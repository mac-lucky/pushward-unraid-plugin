#!/usr/bin/php
<?php
/**
 * PushWard Unraid monitor daemon.
 *
 * Polls Unraid for long-running operations and drives PushWard Live Activities
 * over the public REST API:
 *   - parity check / rebuild / clear  -> "generic" template (progress + ETA)
 *   - appdata backup (CA appdata.backup) -> "steps" template (one step per container)
 *   - mover (cache -> array)          -> "log" template (files moved + percent),
 *                                        "generic" (percent/bytes) when mover logging is off
 *   - VM backup (vmbackup plugin)     -> "steps" template (one step per VM),
 *                                        "generic" (indeterminate) when the plan is "all"
 *   - UPS on battery (apcupsd)        -> "generic" template (charge + runtime countdown)
 *
 * Single long-running process, supervised by watchdog.sh (flock + pgrep) and a
 * 1-minute cron, started/stopped by the array event hooks. No external deps
 * beyond php-cli + curl, both always present on Unraid.
 *
 * Usage:
 *   php pushward-monitor.php daemon      # run the daemon loop
 *   php pushward-monitor.php once        # one poll cycle, for debugging
 *   php pushward-monitor.php end-all     # end every active activity, clear state
 *   php pushward-monitor.php test-activity  # push a short demo activity
 *
 * Always pass "daemon" explicitly. It is also the default, but an argv without it
 * matches none of the pgrep/pkill patterns the watchdog, the install scripts and
 * the settings page use, so such an instance is invisible to all of them.
 */

const CFG_FILE   = '/boot/config/plugins/pushward-unraid/pushward-unraid.cfg';
const STATE_DIR  = '/var/run/pushward';
const STATE_FILE = '/var/run/pushward/state.json';
const LOCK_FILE  = '/var/run/pushward/monitor.lock';
const LOG_FILE   = '/var/log/pushward-monitor.log';
const LOG_MAX    = 262144; // 256 KiB before truncation
const BACKUP_TMP = '/tmp/appdata.backup';
const VAR_INI    = '/var/local/emhttp/var.ini';
const DISKS_INI  = '/var/local/emhttp/disks.ini';
// Also hardcoded in pushward-settings.page, which shows the resolved web UI
// base next to the setting; keep the two in step.
const NGINX_INI  = '/var/local/emhttp/nginx.ini';
const SHARES_DIR = '/boot/config/shares';
const SYSLOG     = '/var/log/syslog';
const VMBACKUP_CFG = '/boot/config/plugins/vmbackup/user.cfg';

const PROGRESS_EPSILON = 0.01; // re-push when progress moves >= 1%
const HEARTBEAT_SECS   = 30;   // re-push at least this often while active (keeps ETA fresh)
const START_RETRY_SECS = 60;   // back off this long after a failed create
const VMBACKUP_FRESH_SECS  = 600; // a vmbackup log older than this is a previous run, not the current one
const UPS_ONBATT_DEBOUNCE  = 2;   // consecutive on-battery polls before raising the outage activity
const MOVER_MIN_MOVABLE    = 1073741824; // 1 GiB: below this, skip the % and show indeterminate
const MOVER_DU_BUDGET      = 25;  // seconds budget for the one-shot movable-size baseline du
const PARITY_ETA_MAX_SECS  = 2592000; // 30 days: a longer ETA is a bad speed sample, not a forecast
const VIRSH_TIMEOUT_SECS   = 5;   // cap a dumpxml against an unresponsive libvirtd
const MAX_STEP_LABEL_RUNES = 32;  // server cap on a step_labels entry

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------

function load_cfg(): array {
    $cfg = @parse_ini_file(CFG_FILE) ?: [];
    $bool = fn($k, $d) => (($cfg[$k] ?? ($d ? 'true' : 'false')) !== 'false');
    return [
        // false when the file failed to parse / was read mid-write (empty array);
        // a real config always carries several seeded keys.
        'valid'    => !empty($cfg),
        'url'      => rtrim($cfg['PUSHWARD_URL'] ?? 'https://api.pushward.app', '/'),
        'key'      => trim($cfg['PUSHWARD_API_KEY'] ?? ''),
        'server'   => trim($cfg['PUSHWARD_SERVER_NAME'] ?? 'Unraid'),
        'enabled'  => $bool('PUSHWARD_ACTIVITIES_ENABLED', true),
        'parity'   => $bool('PUSHWARD_TRACK_PARITY', true),
        'backup'   => $bool('PUSHWARD_TRACK_BACKUP', true),
        'mover'    => $bool('PUSHWARD_TRACK_MOVER', true),
        'vmbackup' => $bool('PUSHWARD_TRACK_VMBACKUP', true),
        'ups'      => $bool('PUSHWARD_TRACK_UPS', true),
        'interval' => max(5, (int) ($cfg['PUSHWARD_POLL_INTERVAL'] ?? 15)),
        'priority' => max(0, min(10, (int) ($cfg['PUSHWARD_ACTIVITY_PRIORITY'] ?? 5))),
        'webui'    => trim($cfg['PUSHWARD_WEBUI_URL'] ?? ''),
        // Widgets require the `widgets` capability on the key, which the setup
        // instructions have never asked for, so this one defaults OFF: the
        // literal string "true" turns it on, unlike every toggle above.
        'widgets'  => ($cfg['PUSHWARD_WIDGETS_ENABLED'] ?? 'false') === 'true',
        'widget_interval' => max(60, min(3600, (int) ($cfg['PUSHWARD_WIDGET_INTERVAL'] ?? 300))),
    ];
}

function slug_prefix(string $server): string {
    $p = strtolower($server);
    $p = preg_replace('/[^a-z0-9]+/', '-', $p);
    $p = trim((string) $p, '-');
    return $p !== '' ? $p : 'unraid';
}

// ---------------------------------------------------------------------------
// Logging + state
// ---------------------------------------------------------------------------

function mlog(string $msg, string $level = 'info'): void {
    if (@filesize(LOG_FILE) > LOG_MAX) {
        @file_put_contents(LOG_FILE, ''); // simple truncate; this is a tmpfs debug log
    }
    @file_put_contents(LOG_FILE, '[' . date('Y-m-d H:i:s') . "][$level] $msg\n", FILE_APPEND);
}

function load_state(): array {
    $s = @json_decode((string) @file_get_contents(STATE_FILE), true);
    return is_array($s) ? $s : [];
}

function save_state(array $s): void {
    // Encode first and bail on failure: a stray non-UTF-8 byte in a stashed log
    // line would otherwise make json_encode() return false, write a 0-byte file
    // and clobber the good state, losing every tracked slug. SUBSTITUTE keeps
    // bad bytes from failing the encode in the first place; the false-check is
    // the real guard.
    $json = json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        mlog('save_state: json_encode failed (' . json_last_error_msg() . '); keeping previous state', 'error');
        return;
    }
    // Atomic write so a concurrent reader (a one-shot subcommand) never sees a
    // truncated file and forgets the active activities.
    $tmp = STATE_FILE . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $json) !== false) {
        @rename($tmp, STATE_FILE);
    } else {
        @unlink($tmp);
    }
}

// ---------------------------------------------------------------------------
// PushWard REST client
// ---------------------------------------------------------------------------

function pw_request(array $cfg, string $method, string $path, ?array $body, string $contentType = 'application/json'): array {
    $ch = curl_init($cfg['url'] . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $cfg['key'],
            'Content-Type: ' . $contentType,
            'Accept: application/json',
        ],
    ]);
    if ($body !== null) {
        // SUBSTITUTE so a non-UTF-8 byte in a backup log line can't make
        // json_encode() return false and post an empty body (silently dropped).
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => (string) $resp, 'error' => $err];
}

function pw_ok(array $r): bool {
    return $r['code'] >= 200 && $r['code'] < 300;
}

function pw_create(array $cfg, string $slug, string $name, int $priority, int $endedTtl = 300, int $staleTtl = 1800, ?int $dismissalTtl = null): array {
    $body = [
        'slug'      => $slug,
        'name'      => $name,
        'priority'  => $priority,
        'ended_ttl' => $endedTtl,
        'stale_ttl' => $staleTtl,
    ];
    // Only send dismissal_ttl when asked for: 0 is a real setting (clear the
    // Lock Screen at once) and null means "leave the server default", so the
    // key has to be absent rather than zero.
    if ($dismissalTtl !== null) {
        $body['dismissal_ttl'] = $dismissalTtl;
    }
    return pw_request($cfg, 'POST', '/activities', $body);
}

function pw_patch(array $cfg, string $slug, array $patch): array {
    return pw_request($cfg, 'PATCH', '/activities/' . rawurlencode($slug), $patch, 'application/merge-patch+json');
}

// ---------------------------------------------------------------------------
// Formatting helpers
// ---------------------------------------------------------------------------

function human_eta(int $s): string {
    if ($s <= 0) {
        return '';
    }
    $h = intdiv($s, 3600);
    $m = intdiv($s % 3600, 60);
    if ($h > 0) {
        return "{$h}h{$m}m";
    }
    if ($m > 0) {
        return "{$m}m";
    }
    return "{$s}s";
}

function human_speed(float $kbps): string {
    if ($kbps <= 0) {
        return '';
    }
    $mbps = $kbps / 1024.0;
    return $mbps >= 1 ? round($mbps, 1) . ' MB/s' : round($kbps) . ' KB/s';
}

function human_bytes(float $b): string {
    if ($b <= 0) {
        return '0 B';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $i = (int) floor(log($b, 1024));
    $i = max(0, min($i, count($units) - 1));
    $v = $b / pow(1024, $i);
    return ($i === 0 || $v >= 100 ? (string) round($v) : (string) round($v, 1)) . ' ' . $units[$i];
}

/**
 * Number of the step currently running, for the steps template. Steps count
 * from 1, with 0 meaning "not started" and $total meaning "all done", so the
 * caller passes how many steps have STARTED, not an array index.
 */
function steps_current(int $started, int $total): int {
    return max(0, min($started, max(1, $total)));
}

function map_level(string $raw): string {
    if (strpos($raw, "\u{274C}") !== false || stripos($raw, 'err') !== false) {
        return 'error';
    }
    if (strpos($raw, "\u{26A0}") !== false || stripos($raw, 'warn') !== false) {
        return 'warn';
    }
    return 'info';
}

// ---------------------------------------------------------------------------
// Detection: parity / array operation (mdcmd status)
// ---------------------------------------------------------------------------

function md_status(): array {
    $out = [];
    @exec('/usr/local/sbin/mdcmd status 2>/dev/null', $lines);
    foreach ($lines as $l) {
        if (strpos($l, '=') !== false) {
            [$k, $v] = explode('=', $l, 2);
            $out[$k] = $v;
        }
    }
    return $out;
}

function detect_parity(array $md): ?array {
    // mdResync is 0 when idle, non-zero while a parity op runs.
    if ((float) ($md['mdResync'] ?? 0) <= 0) {
        return null;
    }
    $pos  = (float) ($md['mdResyncPos'] ?? 0);
    $size = (float) ($md['mdResyncSize'] ?? 0);
    $progress = $size > 0 ? min(1.0, max(0.0, $pos / $size)) : 0.0;
    $action = trim($md['mdResyncAction'] ?? '');
    $corr   = (int) ($md['mdResyncCorr'] ?? 0);

    $label = 'Array operation';
    $icon  = 'externaldrive.fill';
    if (stripos($action, 'check') !== false) {
        $label = 'Parity-Check';
        $icon  = 'externaldrive.fill.badge.checkmark';
    } elseif (stripos($action, 'recon') !== false) {
        $label = 'Rebuilding parity';
        $icon  = 'arrow.triangle.2.circlepath';
    } elseif (stripos($action, 'clear') !== false) {
        $label = 'Clearing disk';
        $icon  = 'eraser.fill';
    }
    return [
        'progress' => $progress,
        'pos'      => $pos,
        'size'     => $size,
        'action'   => $action,
        'label'    => $label,
        'icon'     => $icon,
        'corr'     => $corr,
    ];
}

// ---------------------------------------------------------------------------
// disks.ini
//
// The one file that already carries every disk's size, SMART status and error
// count, kept warm by Unraid itself. Both the mover baseline and all three
// widgets read it through here; nothing may reach for smartctl/hdparm instead
// and spin a sleeping disk up to learn what this file already says.
// ---------------------------------------------------------------------------

/**
 * Per-section disk facts from disks.ini: filesystem sizes (KiB), SMART status
 * and error count.
 *
 * Hand-rolled rather than parse_ini_file(DISKS_INI, true, INI_SCANNER_RAW),
 * which returns the section keys with their quotes still attached ('"disk1"',
 * not 'disk1') and would silently stop every name predicate below matching.
 */
function disks_ini_sections(): array {
    $data = (string) @file_get_contents(DISKS_INI);
    if ($data === '') {
        return [];
    }
    $out     = [];
    $section = '';
    foreach (preg_split('/\r?\n/', $data) as $line) {
        $line = trim($line);
        if (preg_match('/^\["?([^"\]]+)"?\]$/', $line, $m)) {
            $section = $m[1];
            $out[$section] = [];
        } elseif ($section !== '' && preg_match('/^([A-Za-z0-9_]+)="?([^"]*)"?$/', $line, $m)) {
            $out[$section][$m[1]] = $m[2];
        }
    }
    return $out;
}

/** True when a section is an array data disk (diskN), not parity, flash or a pool. */
function is_array_disk(string $name): bool {
    return (bool) preg_match('/^disk\d+$/', $name);
}

/** True when a section is a user-named pool: not diskN, parity or flash. */
function is_pool_disk(string $name): bool {
    return !preg_match('/^(disk\d+|parity\d*|flash)$/', $name);
}

// ---------------------------------------------------------------------------
// Detection: mover (var.ini)
// ---------------------------------------------------------------------------

/**
 * Whole of var.ini as a KEY => value map, in one read.
 *
 * Hand-rolled rather than parse_ini_file() for the same reason disks.ini is:
 * the values are Unraid's own free text (a share comment, a model string) and
 * an unbalanced quote in one of them would fail the whole parse and take every
 * other key with it. This scan cannot fail, only miss a line.
 */
function var_ini_all(): array {
    $data = @file_get_contents(VAR_INI);
    if ($data === false) {
        return [];
    }
    $out = [];
    if (preg_match_all('/^([A-Za-z0-9_]+)="?([^"\n]*)"?/m', $data, $m, PREG_SET_ORDER)) {
        foreach ($m as $kv) {
            $out[$kv[1]] = $kv[2];
        }
    }
    return $out;
}

/** $var lets a caller that already read var.ini this poll skip a second read. */
function detect_mover(?array $var = null): bool {
    $v = $var ?? var_ini_all();
    return ($v['shareMoverActive'] ?? null) === 'yes';
}

/**
 * Cache-pool mount points: every disks.ini section that isn't an array data
 * disk, parity disk or the boot flash. Unraid reserves the diskN/parity/flash
 * names for the array, so anything else is a user-named cache pool.
 */
function cache_pool_mounts(): array {
    $mounts = [];
    foreach (array_keys(disks_ini_sections()) as $name) {
        if (!is_pool_disk($name)) {
            continue;
        }
        $mp = '/mnt/' . $name;
        if (is_dir($mp)) {
            $mounts[] = $mp;
        }
    }
    return $mounts;
}

/** Sum of used bytes (total - free) across the given mount points (statvfs). */
function pool_used_bytes(array $mounts): float {
    $used = 0.0;
    foreach ($mounts as $mp) {
        $total = @disk_total_space($mp);
        $free  = @disk_free_space($mp);
        if ($total !== false && $free !== false) {
            $used += max(0.0, (float) $total - (float) $free);
        }
    }
    return $used;
}

/**
 * Bytes the mover will drain from the cache pools: for each share set to "Use
 * cache = Yes" (the cache -> array direction), its footprint on its PRIMARY pool
 * (shareCachePool) only. The mover reads a yes-share solely from /mnt/<primary>/
 * <share>; data the same share happens to have on another pool is left alone, so
 * counting every pool (as a naive walk would) inflates the denominator and stalls
 * the bar. Shares set to only/no (cache-resident or array-only) and prefer
 * (array -> cache, the opposite direction) never drain the pool and are excluded.
 *
 * Reads only the flash cache pools (du there does not spin the array up) and is
 * called once, at the idle -> running transition. The single du is wrapped in a
 * time budget; on timeout or failure it returns 0 so the caller shows an
 * indeterminate frame rather than a denominator built from a partial walk.
 */
function mover_movable_bytes(): float {
    $dirs = [];
    foreach (@glob(SHARES_DIR . '/*.cfg') ?: [] as $cfgPath) {
        $data = (string) @file_get_contents($cfgPath);
        if (!preg_match('/^shareUseCache="?yes"?/mi', $data)) {
            continue;
        }
        if (!preg_match('/^shareCachePool="?([^"\n]*)"?/m', $data, $m)) {
            continue;
        }
        $pool = trim($m[1]);
        $dir  = '/mnt/' . $pool . '/' . basename($cfgPath, '.cfg');
        if ($pool !== '' && is_dir($dir)) {
            $dirs[] = $dir;
        }
    }
    if (!$dirs) {
        return 0.0;
    }
    $args = implode(' ', array_map('escapeshellarg', $dirs));
    @exec('timeout ' . MOVER_DU_BUDGET . " du -sk $args 2>/dev/null", $out, $rc);
    if ($rc !== 0 || !$out) {
        return 0.0; // timed out (124) or du failed -> indeterminate
    }
    $kb = 0.0;
    foreach ($out as $line) {
        if (preg_match('/^(\d+)\s/', $line, $m)) {
            $kb += (float) $m[1];
        }
    }
    return $kb * 1024.0;
}

/**
 * Per-file move lines for the current run, newest-first, as PushWard LogLines.
 * Unraid writes these only when "Mover logging" is enabled (the scheduled cron
 * then runs `mover start |& logger -t move`); without it syslog carries no move
 * lines and the caller falls back to the generic percent frame. syslog is tmpfs,
 * so this reads RAM. $sincePos is the syslog size captured when the run was first
 * seen, so the previous run's lines are excluded; only Success/error lines are
 * shown (skip/started/finished chatter is dropped - the daemon owns lifecycle).
 */
function mover_log_lines(int $sincePos, int $n): array {
    $size = @filesize(SYSLOG);
    if ($size === false) {
        return [];
    }
    // Start at the run's offset, but read at most the last 256 KiB (a long run
    // logs thousands of lines and only the newest $n are shown). If the log
    // rotated under us (shrank below the offset) fall back to a plain tail read.
    $start = ($sincePos >= 0 && $sincePos <= $size) ? $sincePos : 0;
    $start = max($start, $size - 262144);
    if ($start < 0) {
        $start = 0;
    }
    $data = @file_get_contents(SYSLOG, false, null, $start);
    if ($data === false || $data === '') {
        return [];
    }
    $out = [];
    foreach (preg_split('/\r?\n/', $data) ?: [] as $line) {
        // "Mon DD HH:MM:SS host move: <payload>" - the mover's logger tag is "move".
        if (!preg_match('/^(\w{3}\s+\d+\s+\d{2}:\d{2}:\d{2})\s+\S+\s+move:\s+(.*)$/', $line, $m)) {
            continue;
        }
        $payload = trim($m[2]);
        $level   = 'info';
        if (preg_match('/^move:\s+(.*\S)\s+Success$/i', $payload, $mm)) {
            $text = preg_replace('#^/mnt/[^/]+/#', '', $mm[1]); // drop the /mnt/<pool>/ prefix
        } elseif (preg_match('/(error|fail|cannot|denied)/i', $payload)) {
            $level = 'error';
            $text  = $payload;
        } else {
            continue; // skip:/mover:started/finished - not a moved file
        }
        $text = mb_substr((string) $text, 0, 512);
        if ($text === '') {
            continue;
        }
        $entry = ['text' => $text, 'level' => $level];
        $at = strtotime($m[1]);
        if ($at !== false) {
            $entry['at'] = $at;
        }
        $out[] = $entry;
    }
    return array_reverse(array_slice($out, -$n)); // newest-first, capped at $n
}

// ---------------------------------------------------------------------------
// Detection: appdata backup (running file + ab.log)
// ---------------------------------------------------------------------------

function backup_running(): bool {
    return file_exists(BACKUP_TMP . '/running');
}

/** Parse a single ab.log line into [tsUnix|null, level, component, message]. */
function parse_backup_line(string $line): ?array {
    if (!preg_match('/^\[([^\]]*)\]\[([^\]]*)\]\[([^\]]*)\]\s?(.*)$/u', $line, $m)) {
        return null;
    }
    $ts = DateTime::createFromFormat('d.m.Y H:i:s', trim($m[1]));
    return [
        'at'   => $ts ? $ts->getTimestamp() : null,
        'level' => map_level($m[2]),
        'comp' => trim($m[3]),
        'msg'  => trim($m[4]),
    ];
}

/**
 * Whole-file scan of ab.log: overall progress + current container + flags.
 *
 * Containers are backed up in the plugin's own order, not the alphabetical
 * "Selected containers" order, so progress is the count of DISTINCT containers
 * that have appeared in the log (order-independent and monotonic), and the
 * current container is simply the most recent one with activity.
 */
function backup_progress(string $logPath): array {
    $content  = (string) @file_get_contents($logPath);
    $lines    = preg_split('/\r?\n/', $content) ?: [];
    $total    = 0;
    $seen     = [];
    $current  = '';
    $finished = false;
    $error    = false;
    foreach ($lines as $l) {
        if (preg_match('/\]\[Main\]\s*Selected containers:\s*(.+)$/u', $l, $m)) {
            $total = count(array_map('trim', explode(',', $m[1])));
            continue;
        }
        $p = parse_backup_line($l);
        if ($p === null) {
            continue;
        }
        if ($p['comp'] !== 'Main' && $p['comp'] !== '') {
            $seen[$p['comp']] = true;
            $current = $p['comp'];
        }
        if (strpos($l, 'DONE! Thanks for using') !== false) {
            $finished = true;
        }
        if ($p['level'] === 'error') {
            $error = true;
        }
    }
    $done = count($seen);
    return [
        'total'    => $total ?: $done,
        // Clamp so a stray non-container component can't render "(8/5)".
        'idx'      => $total > 0 ? min($done, $total) : $done,
        'current'  => $current,
        'progress' => $total > 0 ? min(1.0, $done / $total) : 0.0,
        'finished' => $finished,
        'error'    => $error,
    ];
}

// ---------------------------------------------------------------------------
// Detection: VM backup (JTok vmbackup plugin)
// ---------------------------------------------------------------------------

/**
 * Whether a vmbackup run is in progress. Gated on a process match (reads /proc,
 * never the array) so an idle box with the toggle on does NOT stat the backup
 * share every poll and risk spinning a disk up. Matches the plugin's own
 * scheduled/manual invocation; a run launched through the User Scripts plugin
 * under a custom name won't be detected (acceptable - it's an opt-in toggle).
 * The [.] keeps the pattern from matching this exec's own sh -c argv.
 */
function vmbackup_running(): bool {
    @exec("pgrep -f 'vmbackup/user-script[.]sh' 2>/dev/null", $o);
    return !empty($o);
}

/** Log directory from the plugin config (backup_location + log_file_subfolder). */
function vmbackup_logdir(): string {
    $c   = @parse_ini_file(VMBACKUP_CFG) ?: [];
    $loc = rtrim($c['backup_location'] ?? '/mnt/user/backup/', '/');
    $sub = trim($c['log_file_subfolder'] ?? 'logs/', '/');
    return $sub !== '' ? "$loc/$sub" : $loc;
}

/** Newest main vmbackup log (the *_error.log copy is skipped), or '' if none. */
function vmbackup_log(): string {
    $all  = @glob(vmbackup_logdir() . '/*unraid-vmbackup.log') ?: [];
    $main = array_values(array_filter($all, fn($p) => !str_ends_with($p, '_error.log')));
    if (!$main) {
        return '';
    }
    usort($main, fn($a, $b) => (@filemtime($b) ?: 0) <=> (@filemtime($a) ?: 0));
    return $main[0];
}

/**
 * Newest vmbackup log, but only if it was written recently. At the start of a
 * run the new timestamped log file does not exist yet, so the plain newest is
 * the PREVIOUS run's log (ended at ~100% / complete); the freshness guard keeps
 * that stale frame off the phone until the current run's log appears.
 */
function vmbackup_fresh_log(): string {
    $log = vmbackup_log();
    if ($log === '') {
        return '';
    }
    return (time() - (@filemtime($log) ?: 0)) <= VMBACKUP_FRESH_SECS ? $log : '';
}

/**
 * VMs the run plans to back up, in config order; empty when the plan is "all"
 * or unset. The step number and the per-step weights are both derived from this
 * one list so they cannot drift apart mid-run.
 */
function vmbackup_planned_vms(): array {
    $c    = @parse_ini_file(VMBACKUP_CFG) ?: [];
    $list = trim($c['vms_to_backup'] ?? '');
    if ($list === '') {
        return [];
    }
    return array_values(array_filter(array_map('trim', explode(',', $list)), fn($v) => $v !== ''));
}

/** Parse a vmbackup log line: "YYYY-mm-dd HH:ii:ss <level>: <message>". */
function parse_vmbackup_line(string $line): ?array {
    // No /u flag: a non-UTF-8 byte in a VM name or path must not make preg_match
    // return false and silently drop the line.
    if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s+(.*)$/', $line, $m)) {
        return null;
    }
    $ts    = DateTime::createFromFormat('Y-m-d H:i:s', $m[1]);
    $msg   = trim($m[2]);
    $level = 'info';
    if (preg_match('/^(information|info|warning|error|alert|failure)\s*:\s*(.*)$/is', $msg, $mm)) {
        $kw  = strtolower($mm[1]);
        $msg = $mm[2];
        if ($kw === 'warning') {
            $level = 'warn';
        } elseif ($kw === 'error' || $kw === 'alert' || $kw === 'failure') {
            $level = 'error';
        }
    }
    return ['at' => $ts ? $ts->getTimestamp() : null, 'level' => $level, 'msg' => $msg];
}

/**
 * Per-VM progress from the log. The denominator is the planned VM count from the
 * plugin config, NOT the count seen so far: VMs are backed up sequentially, so a
 * "seen" count equals "done" the instant a VM completes and before the next one
 * logs, which would make the bar bounce 100% -> down every cycle. A fixed planned
 * total keeps done/total monotonic; when the plan is "all" (unknown) progress is
 * null so the handler renders an indeterminate frame instead of a fake bar.
 */
function vmbackup_progress(string $logPath): array {
    $lines   = preg_split('/\r?\n/', (string) @file_get_contents($logPath)) ?: [];
    $done    = [];
    $skipped = [];
    $failed  = [];
    $current = '';
    $error   = false;
    foreach ($lines as $l) {
        $p = parse_vmbackup_line($l);
        if ($p === null) {
            continue;
        }
        $msg = $p['msg'];
        if (preg_match('/^(.+?) can be found on the system\. attempting backup/i', $msg, $m)) {
            $current = trim($m[1]);
        } elseif (preg_match('/^starting backup of (.+?) configuration/i', $msg, $m)) {
            $current = trim($m[1]);
        }
        // "completed." is the VM-level done line; per-vdisk lines end "complete."
        if (preg_match('/^backup of (.+?) to .* completed\.?$/i', $msg, $m)) {
            $done[trim($m[1])] = true;
        }
        // vmbackup logs this and moves on without ever making the VM current.
        if (preg_match('/^(.+?) can not be found on the system/i', $msg, $m)) {
            $skipped[trim($m[1])] = true;
        }
        if ($p['level'] === 'error') {
            $error = true;
            // Attribute the error to whichever VM is running when it lands. A
            // run-level error before any VM starts has no owner and colours
            // nothing, which is right: the card already turns orange.
            if ($current !== '') {
                $failed[$current] = true;
            }
        }
    }
    // The one config read of the tick: the labels, the weights, the colours and
    // the step number all come off this list, so they cannot describe different
    // plans within a tick the way three separate reads could.
    $vms   = vmbackup_planned_vms();
    $total = count($vms);
    $d     = count($done);
    // Take the step from the running VM's position in the planned list, not
    // from a count of completions: vmbackup logs "can not be found" and moves
    // on without a "completed." line, and after one such skip a count points at
    // the wrong VM - and at the wrong step_weights entry - for the rest of the
    // run. Fall back to the count only while no VM is identifiable.
    $pos  = $current !== '' ? array_search($current, $vms, true) : false;
    $step = $pos !== false ? $pos + 1 : (($current !== '' || $d > 0) ? $d + 1 : 0);
    return [
        'vms'      => $vms,
        'total'    => $total,
        'done'     => $d,
        'step'     => $total > 0 ? min($step, $total) : $step,
        'current'  => $current,
        'progress' => $total > 0 ? min(1.0, $d / $total) : null,
        'error'    => $error,
        // A VM that completed cannot also have failed: an error logged while it
        // was current may well have been about the next one.
        'failed'   => array_diff_key($failed, $done),
        'skipped'  => $skipped,
    ];
}

/**
 * Bytes a disk image actually occupies. Unraid creates raw vdisks sparse, so
 * the apparent size is the provisioned size - a 200 GiB image holding 12 GiB
 * reports 200 GiB - and weighting by that sizes the segment by provisioning
 * instead of by the data the backup copies. Prefer the allocated block count
 * and fall back to the apparent size only where blocks are unavailable.
 */
function vdisk_alloc_bytes(string $path): float {
    $s = @stat($path);
    if ($s === false) {
        return 0.0;
    }
    if (!empty($s['blocks']) && $s['blocks'] > 0) {
        return (float) $s['blocks'] * 512;
    }
    return (float) ($s['size'] ?? 0);
}

/**
 * Total bytes of a VM's disk images from its libvirt domain XML. Only
 * device='disk' sources count (an attached install ISO is device='cdrom' and is
 * not part of the backup). Returns 0.0 when virsh is missing or the domain has
 * no file-backed disk, so the caller can substitute a weight.
 */
function vm_vdisk_bytes(string $vm): float {
    // Timeout: libvirtd goes unresponsive under heavy VM I/O, which is exactly
    // what a backup run produces. An untimed exec would block the poll loop
    // once per VM while the watchdog still sees a live process and leaves it be.
    @exec('timeout ' . VIRSH_TIMEOUT_SECS . ' virsh dumpxml ' . escapeshellarg($vm) . ' 2>/dev/null', $out, $rc);
    if ($rc !== 0 || !$out) {
        return 0.0;
    }
    $xml   = implode("\n", $out);
    $bytes = 0.0;
    if (preg_match_all('/<disk\b[^>]*device=[\'"]disk[\'"][^>]*>(.*?)<\/disk>/is', $xml, $blocks)) {
        foreach ($blocks[1] as $block) {
            if (preg_match('/<source\b[^>]*\bfile=[\'"]([^\'"]+)[\'"]/i', $block, $m)) {
                $bytes += vdisk_alloc_bytes($m[1]);
            }
        }
    }
    return $bytes;
}

/**
 * One positive weight per planned VM, in vmbackup_planned_vms() order, so the
 * steps row can size each VM by how much data it copies instead of equal
 * widths. Statting the images is cheap here: the run already holds the source
 * disks spun up. Returns [] when nothing could be sized, which leaves the row
 * at equal widths rather than inventing a shape.
 */
function vmbackup_step_weights(array $vms): array {
    if (!$vms) {
        return [];
    }
    $weights = [];
    foreach ($vms as $vm) {
        $weights[] = vm_vdisk_bytes($vm);
    }
    $sized = array_filter($weights, fn($w) => $w > 0);
    if (!$sized) {
        return [];
    }
    // A VM we could not size takes the mean of the ones we could. The weights
    // are byte counts, so a literal 1.0 here is a weight of one byte: next to a
    // 500 GB vdisk that segment renders zero pixels wide, the opposite of the
    // fallback's purpose.
    $mean = array_sum($sized) / count($sized);
    return array_map(fn($w) => $w > 0 ? $w : $mean, $weights);
}

/**
 * One label per planned VM, in vmbackup_planned_vms() order, so the segmented
 * bar names each VM instead of showing anonymous blocks. Built from the same
 * list as the weights and the step number, which is what keeps the three from
 * drifting. Truncated to the server's 32-rune step_labels cap.
 */
function vmbackup_step_labels(array $vms): array {
    return array_map(fn($vm) => mb_substr($vm, 0, MAX_STEP_LABEL_RUNES, 'UTF-8'), $vms);
}

/**
 * Per-step colors: red for a VM whose backup failed, orange for one vmbackup
 * could not find, and empty (falls back to accent_color) for the rest. Returns
 * [] when nothing is coloured - an all-empty array is valid but costs a
 * structural push for no visible change.
 */
function vmbackup_step_colors(array $vms, array $failed, array $skipped): array {
    $colors = [];
    $any    = false;
    foreach ($vms as $vm) {
        $c = '';
        if (isset($failed[$vm])) {
            $c = 'red';
        } elseif (isset($skipped[$vm])) {
            $c = 'orange';
        }
        if ($c !== '') {
            $any = true;
        }
        $colors[] = $c;
    }
    return $any ? $colors : [];
}

// ---------------------------------------------------------------------------
// Detection: UPS on battery (apcupsd)
// ---------------------------------------------------------------------------

/** Parse `apcaccess status` into a KEY => value map (empty if apcupsd is down). */
function apc_status(): array {
    @exec('apcaccess status 2>/dev/null', $lines);
    $out = [];
    foreach ($lines as $l) {
        if (preg_match('/^([A-Z][A-Z0-9]*)\s*:\s*(.*)$/', trim($l), $m)) {
            $out[$m[1]] = trim($m[2]);
        }
    }
    return $out;
}

/** Leading number from values like "45.0 Minutes" / "87.5 Percent". */
function apc_num(string $v): float {
    return preg_match('/-?\d+(\.\d+)?/', $v, $m) ? (float) $m[0] : 0.0;
}

/**
 * UPS state from apcupsd, or null when apcupsd isn't running (no UPS, or NUT,
 * which is out of scope) or isn't answering yet. Gated on the process with one
 * /proc read so boxes without a UPS never shell out to apcaccess. 'on_battery'
 * is the live outage flag; 'charge' is null when the UPS doesn't report BCHARGE
 * (so a missing reading isn't mistaken for a genuine 0%).
 */
function ups_state(): ?array {
    @exec('pgrep -x apcupsd 2>/dev/null', $pg);
    if (empty($pg)) {
        return null;
    }
    $s      = apc_status();
    $status = strtoupper($s['STATUS'] ?? '');
    if ($status === '') {
        return null;
    }
    return [
        'on_battery' => strpos($status, 'ONBATT') !== false,
        'charge'     => array_key_exists('BCHARGE', $s) ? apc_num($s['BCHARGE']) : null,
        'timeleft'   => (int) round(apc_num($s['TIMELEFT'] ?? '') * 60), // minutes -> seconds
        'load'       => apc_num($s['LOADPCT'] ?? ''),
    ];
}

// ---------------------------------------------------------------------------
// Activity lifecycle helpers
// ---------------------------------------------------------------------------

function should_push(array $st, float $progress, string $stateText, bool $newContent, int $now): bool {
    if (empty($st['last_push_ts'])) {
        return true;
    }
    if ($newContent) {
        return true;
    }
    if ($stateText !== ($st['last_state_text'] ?? '')) {
        return true;
    }
    if (abs($progress - (float) ($st['last_progress'] ?? -1)) >= PROGRESS_EPSILON) {
        return true;
    }
    return ($now - (int) $st['last_push_ts']) >= HEARTBEAT_SECS;
}

/**
 * Drive one activity through its lifecycle.
 *
 * @param array|null $content  desired content frame while active, or null when idle
 * @param array      $st       per-slug persisted state (by ref)
 */
/**
 * Base URL of this server's web UI: PUSHWARD_WEBUI_URL when set, otherwise
 * NGINX_DEFAULTURL from nginx.ini - the same value Unraid's own notify script
 * builds its links from, so the button on a card and the link in an alert email
 * agree. '' when neither is available, which drops the button.
 *
 * Auto-detection deliberately does not try to be clever about WAN or Tailscale
 * names: matching what Unraid itself sends is the predictable choice, and
 * PUSHWARD_WEBUI_URL covers off-LAN access (NGINX_TAILSCALEFQDN is the usual
 * value there).
 */
function unraid_webui_base(array $cfg): string {
    $base = trim((string) ($cfg['webui'] ?? ''));
    if ($base === '') {
        $ini  = @parse_ini_file(NGINX_INI) ?: [];
        $base = trim((string) ($ini['NGINX_DEFAULTURL'] ?? ''));
    }
    // PUSHWARD_WEBUI_URL is unvalidated operator free text, and a schemeless
    // "tower.local" builds "tower.local/Main", which the phone cannot open. No
    // button beats a dead button. The no-whitespace tail matters as much as the
    // scheme: Go's url.Parse rejects a space in the host, so "https://my tower"
    // would 422 every PATCH the button rides on rather than just failing to open.
    if (!preg_match('#^https?://[^\s]+$#i', $base)) {
        return '';
    }
    return rtrim($base, '/');
}

/** Which web UI page each activity's "Open" button goes to, keyed by slug suffix. */
const WEBUI_PATHS = [
    '-array'    => '/Main',
    '-mover'    => '/Main',
    '-backup'   => '/Settings/AB.Main',
    '-vmbackup' => '/Settings/Vmbackup',
    '-ups'      => '/Settings/UPSsettings',
    '-test'     => '/Settings/pushward-activities',
];

/**
 * The "Open" button for a web UI path, or null when the base URL is
 * unresolvable. The widgets, which have no activity slug, use this directly.
 *
 * url_action, not tap_action: tap_action would override the whole-card tap,
 * which opens the PushWard app on the activity detail - what a user expects
 * from the card body. This adds a labelled button beside it.
 */
function unraid_webui_link(array $cfg, string $path): ?array {
    $base = unraid_webui_base($cfg);
    return $base === ''
        ? null
        : ['url' => $base . $path, 'foreground' => true, 'title' => 'Open', 'icon' => 'safari'];
}

/**
 * The "Open" button for one activity slug.
 *
 * An exact lookup on the suffix left after the server's slug prefix, never a
 * str_ends_with() scan over the table: that scan let a bare suffix be passed in
 * place of a slug, and it made "-backup" miss "-vmbackup" only by the accident
 * of one character.
 */
function unraid_url_action(array $cfg, string $slug): ?array {
    $prefix = slug_prefix($cfg['server']);
    if (!str_starts_with($slug, $prefix)) {
        return null;
    }
    $path = WEBUI_PATHS[substr($slug, strlen($prefix))] ?? null;
    return $path !== null ? unraid_webui_link($cfg, $path) : null;
}

function drive_activity(array $cfg, string $slug, string $name, ?array $content, float $progress, string $stateText, bool $newContent, array &$st): void {
    $now = time();

    if ($content !== null) {
        // content is an RFC 7396 merge patch, so a transient nginx.ini read
        // failure costs nothing: omitting the key keeps the button the server
        // already holds. No stash, and an edited PUSHWARD_WEBUI_URL therefore
        // takes effect on the next tick rather than the next run.
        $urlAction = unraid_url_action($cfg, $slug);
        if ($urlAction !== null) {
            $content['url_action'] = $urlAction;
        }
        if (empty($st['active'])) {
            if (!empty($st['start_fail_ts']) && ($now - (int) $st['start_fail_ts']) < START_RETRY_SECS) {
                return; // back off after a failed create
            }
            $c = pw_create($cfg, $slug, $name, $cfg['priority']);
            if (!pw_ok($c)) {
                $st['start_fail_ts'] = $now;
                mlog("create $slug failed: {$c['code']} {$c['body']} {$c['error']}", 'error');
                return;
            }
            // POST creates the row in the ended state; PATCH to ongoing is what
            // actually starts it and fires the server push-to-start broadcast.
            $p = pw_patch($cfg, $slug, ['state' => 'ongoing', 'content' => $content]);
            if (!pw_ok($p)) {
                $st['start_fail_ts'] = $now;
                mlog("seed $slug failed: {$p['code']} {$p['body']}", 'error');
                return;
            }
            // Merge (don't replace) so end_content / log_path / pos bookkeeping
            // the caller stashed on $st survives the start transition.
            $st['active']          = true;
            $st['last_push_ts']    = $now;
            $st['last_progress']   = $progress;
            $st['last_state_text'] = $stateText;
            unset($st['start_fail_ts']);
            mlog("started $slug ($stateText)");
            return;
        }
        if (should_push($st, $progress, $stateText, $newContent, $now)) {
            // Re-assert ongoing so the activity auto-recovers if the cap had
            // preempted it; harmless (no push) when it's already ongoing.
            $p = pw_patch($cfg, $slug, ['state' => 'ongoing', 'content' => $content]);
            if (pw_ok($p)) {
                $st['last_push_ts']    = $now;
                $st['last_progress']   = $progress;
                $st['last_state_text'] = $stateText;
            } else {
                mlog("update $slug failed: {$p['code']} {$p['body']}", 'error');
                // The server has fully dropped the row (stale-ended + TTL-evicted
                // after a long daemon outage). Re-asserting ongoing only works
                // while the row still exists; once it's a 404 we must re-create,
                // so clear active and let the next tick POST a fresh activity
                // instead of 404-looping for the rest of the run.
                if ($p['code'] === 404) {
                    $st['active'] = false;
                }
            }
        }
        return;
    }

    // idle: if we were tracking it, send the final ENDED frame
    if (!empty($st['active'])) {
        $st['active'] = false;
        $end = $st['end_content'] ?? ['template' => 'generic', 'progress' => 1.0, 'state' => 'Complete'];
        $p = pw_patch($cfg, $slug, ['state' => 'ended', 'content' => $end]);
        mlog("ended $slug" . (pw_ok($p) ? '' : " (patch {$p['code']})"));
    }
}

// ---------------------------------------------------------------------------
// Per-tick operation handlers
// ---------------------------------------------------------------------------

function tick_parity(array $cfg, string $prefix, array $md, array &$state): void {
    $slug = "$prefix-array";
    $st   = $state[$slug] ?? [];
    $p    = $cfg['parity'] ? detect_parity($md) : null;

    if ($p !== null) {
        $now = time();
        $eta = 0;
        if (isset($st['last_pos'], $st['last_pos_ts']) && $now > (int) $st['last_pos_ts']) {
            $dpos = $p['pos'] - (float) $st['last_pos'];
            $dt   = $now - (int) $st['last_pos_ts'];
            if ($dpos > 0) {
                $speed = $dpos / $dt; // KB/s
                $eta   = (int) (($p['size'] - $p['pos']) / $speed);
                $st['last_speed'] = $speed;
            }
        }
        $st['last_pos']    = $p['pos'];
        $st['last_pos_ts'] = $now;
        $speed = (float) ($st['last_speed'] ?? 0);

        // One poll's speed sample extrapolates absurdly while the check stalls
        // (bad-sector retries, mover contention). Past the ceiling, treat it as
        // no estimate at all: an end_date beyond five years is rejected, which
        // would fail the whole update instead of just the ETA.
        if ($eta > PARITY_ETA_MAX_SECS) {
            $eta = 0;
        }
        $live = $eta > 0;

        // Under live_progress the phone counts the ETA down in the headline slot
        // and drops the caption timer, so a pushed percent and a frozen ETA
        // would sit there disagreeing with the moving bar between pushes.
        $stateText = $live
            ? $p['label']
            : sprintf('%s · %d%%', $p['label'], round($p['progress'] * 100));
        // No static "ETA 2h15m" caption: it only ever had a value when $live is
        // on, and that is precisely when the phone renders a live countdown.
        $subParts = [];
        if ($speed > 0) {
            $subParts[] = human_speed($speed);
        }
        if (stripos($p['action'], 'check') !== false) {
            $subParts[] = $p['corr'] . ' error' . ($p['corr'] === 1 ? '' : 's');
        }
        $content = [
            'template'     => 'generic',
            'progress'     => round($p['progress'], 4),
            'state'        => $stateText,
            'subtitle'     => implode(' · ', $subParts),
            'icon'         => $p['icon'],
            'accent_color' => $p['corr'] > 0 ? 'orange' : 'blue',
        ];
        if ($live) {
            $content['remaining_time'] = $eta;
            // Hand iOS the finish time so it interpolates the bar and counts the
            // ETA down between pushes (generic-only; end_date must be in future).
            $content['live_progress'] = true;
            $content['end_date']      = $now + $eta;
        } else {
            // content is an RFC 7396 merge patch, so omitting these keeps the
            // last anchor and a stalled check would keep animating toward a
            // finish time that no longer applies. null is the documented clear.
            $content['remaining_time'] = null;
            $content['live_progress']  = false;
            $content['end_date']       = null;
        }
        $st['end_content'] = [
            'template'     => 'generic',
            'progress'     => 1.0,
            'state'        => $p['label'] . ' complete',
            'subtitle'     => $p['corr'] > 0 ? $p['corr'] . ' errors found' : 'No errors',
            'icon'         => $p['corr'] > 0 ? 'exclamationmark.triangle.fill' : 'checkmark.circle.fill',
            'accent_color' => $p['corr'] > 0 ? 'red' : 'green',
            // The final frame is a merge patch too. A check that beats its ETA
            // would otherwise end with the anchor still in the future, and the
            // finished card would count down instead of reading 100%.
            'remaining_time' => null,
            'live_progress'  => false,
            'end_date'       => null,
        ];
        drive_activity($cfg, $slug, 'Unraid · ' . $cfg['server'] . ' array', $content, $p['progress'], $stateText, false, $st);
    } else {
        drive_activity($cfg, $slug, '', null, 1.0, '', false, $st);
        if (empty($st['active'])) {
            unset($st['last_pos'], $st['last_pos_ts'], $st['last_speed']);
        }
    }
    $state[$slug] = $st;
}

function tick_backup(array $cfg, string $prefix, array &$state): void {
    $slug = "$prefix-backup";
    $st   = $state[$slug] ?? [];
    $log  = BACKUP_TMP . '/ab.log';

    $bp = ($cfg['backup'] && backup_running()) ? backup_progress($log) : null;
    // Wait for the container count (the "Selected containers" line, which the CA
    // plugin logs before any container runs) before opening the activity, so
    // total_steps is the real total from the first frame rather than resizing
    // from a 1-step placeholder.
    if ($bp !== null && $bp['total'] > 0) {
        $total = (int) $bp['total'];
        // idx already counts the container being backed up right now, so it is
        // the 1-based step number the API and the phone expect.
        $step = steps_current((int) $bp['idx'], $total);

        $stateText = $bp['current'] !== ''
            ? sprintf('Backing up %s (%d/%d)', $bp['current'], $bp['idx'], $total)
            : 'Starting appdata backup...';
        $content = [
            'template'     => 'steps',
            'progress'     => round($bp['progress'], 4),
            'current_step' => $step,
            'total_steps'  => $total,
            'state'        => $stateText,
            'icon'         => 'externaldrive.badge.timemachine',
            'accent_color' => $bp['error'] ? 'orange' : 'blue',
        ];
        $st['end_content'] = [
            'template'     => 'steps',
            'progress'     => 1.0,
            'current_step' => $total,
            'total_steps'  => $total,
            'state'        => $bp['error'] ? 'Backup finished with errors' : 'Backup complete',
            'icon'         => $bp['error'] ? 'exclamationmark.triangle.fill' : 'checkmark.circle.fill',
            'accent_color' => $bp['error'] ? 'red' : 'green',
        ];
        // Progress moves ~1/total per container and the state text changes each
        // time, so drive_activity's progress/state throttle pushes once per
        // container; no per-log-line change flag needed.
        drive_activity($cfg, $slug, 'Unraid · ' . $cfg['server'] . ' appdata backup', $content, $bp['progress'], $stateText, false, $st);
    } else {
        drive_activity($cfg, $slug, '', null, 1.0, '', false, $st);
    }
    $state[$slug] = $st;
}

function tick_mover(array $cfg, string $prefix, array &$state): void {
    $slug = "$prefix-mover";
    $st   = $state[$slug] ?? [];

    if ($cfg['mover'] && detect_mover()) {
        // Cache drains toward the array, so (baseline - current) over the cache
        // pools is the bytes moved so far. The percent denominator is the on-pool
        // footprint of the "Use cache = Yes" shares, measured once at the start of
        // the run (mover_movable) - that is the data this run will actually move.
        // When that is unknown or too small (no du, all cache-only shares) the bar
        // is indeterminate and only bytes/speed are shown. Both numbers are
        // estimates: mover skips open/in-use files and concurrent writes refill
        // the cache, so moved is kept monotonic and the run finishing (the flag
        // clearing) is the authoritative "done", snapped to 100% on the end frame.
        $now     = time();
        $mounts  = cache_pool_mounts();
        $current = pool_used_bytes($mounts);
        if (!isset($st['mover_baseline'])) {
            $st['mover_baseline']   = $current;
            $st['mover_movable']    = mover_movable_bytes();            // one-shot du, primary pool
            $st['mover_syslog_pos'] = (int) (@filesize(SYSLOG) ?: 0);   // read move lines from here on
        }
        $baseline = (float) $st['mover_baseline'];
        $movable  = (float) ($st['mover_movable'] ?? 0);
        $prev     = (float) ($st['mover_moved'] ?? 0);
        $prevTs   = (int) ($st['mover_moved_ts'] ?? 0);
        $moved    = max(0.0, $baseline - $current, $prev);

        // Speed from the bytes moved since the last sample; only while actually
        // moving (zero during mover's long scan/skip tail, so it's omitted then).
        $speedKbps = 0.0;
        if ($prevTs > 0 && $now > $prevTs && $moved > $prev) {
            $speedKbps = (($moved - $prev) / ($now - $prevTs)) / 1024.0;
        }
        $st['mover_moved']    = $moved;
        $st['mover_moved_ts'] = $now;

        // Real percent only when the movable baseline is meaningful; kept monotonic
        // and capped below 1.0 so finish (the flag clearing) is what shows 100%.
        $hasPct   = $movable >= MOVER_MIN_MOVABLE;
        $progress = 0.0;
        if ($hasPct) {
            $progress = max(min(0.99, $moved / $movable), (float) ($st['mover_progress'] ?? 0));
            $st['mover_progress'] = $progress;
        }

        $movedTxt = $moved > 0 ? human_bytes($moved) . ' moved' : '';
        $speedTxt = human_speed($speedKbps);

        // Prefer the log template so the phone shows the files as they move. The
        // lines come from syslog and only exist when Unraid "Mover logging" is on;
        // with no lines this run, fall back to the generic percent/bytes frame.
        $lines = mover_log_lines((int) ($st['mover_syslog_pos'] ?? 0), 10);

        if ($lines) {
            $newContent = ($lines[0]['text'] ?? '') !== ($st['mover_last_line'] ?? '');
            $st['mover_last_line'] = $lines[0]['text'] ?? '';

            $statusParts = array_filter([$movedTxt, $speedTxt]);
            $stateText   = $statusParts ? 'Mover · ' . implode(' · ', $statusParts) : 'Mover running';
            $content = [
                'template'     => 'log',
                'state'        => $stateText,
                'icon'         => 'arrow.down.to.line',
                'accent_color' => 'blue',
                'lines'        => $lines,
            ];
            if ($hasPct) {
                $content['progress'] = $progress;
            }
            $st['end_content'] = [
                'template'     => 'log',
                'progress'     => 1.0,
                'state'        => $moved > 0 ? human_bytes($moved) . ' moved to the array' : 'Mover finished',
                'icon'         => 'checkmark.circle.fill',
                'accent_color' => 'green',
                'lines'        => $lines,
            ];
            drive_activity($cfg, $slug, 'Unraid · ' . $cfg['server'] . ' mover', $content, $progress, $stateText, $newContent, $st);
        } else {
            $subtitle = trim(implode(' · ', array_filter([
                $hasPct && $moved > 0 ? human_bytes($moved) . ' of ' . human_bytes($movable) : $movedTxt,
                $speedTxt,
            ]))) ?: 'Moving cache → array';
            $content = [
                'template'     => 'generic',
                'state'        => 'Mover running',
                'subtitle'     => $subtitle,
                'icon'         => 'arrow.down.to.line',
                'accent_color' => 'blue',
            ];
            if ($hasPct) {
                $content['progress'] = $progress;
            }
            $st['end_content'] = [
                'template'     => 'generic',
                'state'        => 'Mover finished',
                'subtitle'     => $moved > 0 ? human_bytes($moved) . ' moved to the array' : '',
                'icon'         => 'checkmark.circle.fill',
                'accent_color' => 'green',
                'progress'     => 1.0,
            ];
            // With a percent, throttle on progress (1% steps + heartbeat) like
            // parity; when indeterminate, pass the subtitle so each new bytes/speed
            // reading still pushes (the displayed state is otherwise constant).
            drive_activity(
                $cfg, $slug, 'Unraid · ' . $cfg['server'] . ' mover', $content,
                $progress, $hasPct ? 'Mover running' : $subtitle, false, $st
            );
        }
    } else {
        drive_activity($cfg, $slug, '', null, 1.0, '', false, $st);
        if (empty($st['active'])) {
            unset(
                $st['mover_baseline'], $st['mover_movable'], $st['mover_moved'],
                $st['mover_moved_ts'], $st['mover_progress'], $st['mover_syslog_pos'], $st['mover_last_line']
            );
        }
    }
    $state[$slug] = $st;
}

function tick_vmbackup(array $cfg, string $prefix, array &$state): void {
    $slug = "$prefix-vmbackup";
    $st   = $state[$slug] ?? [];

    // Toggle gating lives here (not in tick()) so turning the toggle off mid-run
    // falls through to the idle branch and tears the activity down.
    $log = '';
    if ($cfg['vmbackup'] && vmbackup_running()) {
        $log = (!empty($st['log_path']) && is_file($st['log_path']))
            ? $st['log_path']        // stay on the log we latched for this run
            : vmbackup_fresh_log();  // starting: ignore a stale previous-run log
    }
    $bp = $log !== '' ? vmbackup_progress($log) : null;

    // Open the activity once the run has produced identifiable output (a VM is
    // being processed, one completed, or it errored) so the first frame reflects
    // this run, not the previous one. vmbackup_progress already parsed the whole
    // log, so derive the gate from it instead of reading the log a second time.
    if ($bp !== null && ($bp['current'] !== '' || $bp['done'] > 0 || $bp['error'])) {
        $st['log_path'] = $log;

        if ($bp['current'] === '') {
            $stateText = 'Backing up VMs';
        } elseif ($bp['total'] > 0) {
            $stateText = sprintf('Backing up %s (%d/%d)', $bp['current'], $bp['step'], $bp['total']);
        } else {
            $stateText = sprintf('Backing up %s', $bp['current']);
        }

        $endState = $bp['error'] ? 'VM backup finished with errors' : 'VM backup complete';
        $endIcon  = $bp['error'] ? 'exclamationmark.triangle.fill' : 'checkmark.circle.fill';
        $endColor = $bp['error'] ? 'red' : 'green';
        if ($bp['total'] > 0) {
            // Known plan: one step per VM, each sized by its vdisk bytes so the
            // segmented row is proportional to the work rather than equal-width.
            $total = (int) $bp['total'];
            $step  = steps_current((int) $bp['step'], $total);
            // empty(), not isset(): a config read that loses a race with the
            // vmbackup settings page returns [], and isset would cache that
            // failure for the whole run with no retry.
            if (empty($st['step_weights'])) {
                $st['step_weights'] = vmbackup_step_weights($bp['vms']); // one-shot per run
                $st['step_labels']  = vmbackup_step_labels($bp['vms']);
            }
            // Only attach when the count matches (a mid-run config edit could
            // desync it); a length mismatch is a 422 on the steps template.
            $weights = count($st['step_weights']) === $total ? $st['step_weights'] : null;
            $labels  = count($st['step_labels'] ?? []) === $total ? $st['step_labels'] : null;
            // Colours are recomputed every tick, not cached: a VM fails partway
            // through a run and the bar has to show it from that tick onward.
            $cols   = vmbackup_step_colors($bp['vms'], $bp['failed'], $bp['skipped']);
            $colors = count($cols) === $total ? $cols : null;
            $content = [
                'template'     => 'steps',
                'progress'     => round((float) $bp['progress'], 4),
                'current_step' => $step,
                'total_steps'  => $total,
                'state'        => $stateText,
                'icon'         => 'desktopcomputer',
                'accent_color' => $bp['error'] ? 'orange' : 'blue',
            ];
            $st['end_content'] = [
                'template'     => 'steps',
                'progress'     => 1.0,
                'current_step' => $total,
                'total_steps'  => $total,
                'state'        => $endState,
                'icon'         => $endIcon,
                'accent_color' => $endColor,
            ];
            // Explicit null, not omission: content is an RFC 7396 merge patch,
            // so leaving the key out keeps a previously sent array, which then
            // fails the length check against the new total and 422s every
            // remaining update of the run - the exact desync this guards.
            $content['step_weights']           = $weights;
            $st['end_content']['step_weights'] = $weights;
            $content['step_labels']            = $labels;
            $st['end_content']['step_labels']  = $labels;
            $content['step_colors']            = $colors;
            $st['end_content']['step_colors']  = $colors;
        } else {
            // Plan is "all"/unknown: steps needs a fixed total, so fall back to a
            // generic indeterminate frame rather than a bouncing fraction.
            $content = [
                'template'     => 'generic',
                'state'        => $stateText,
                'icon'         => 'desktopcomputer',
                'accent_color' => $bp['error'] ? 'orange' : 'blue',
            ];
            $st['end_content'] = [
                'template'     => 'generic',
                'progress'     => 1.0,
                'state'        => $endState,
                'icon'         => $endIcon,
                'accent_color' => $endColor,
            ];
        }
        drive_activity($cfg, $slug, 'Unraid · ' . $cfg['server'] . ' VM backup', $content, $bp['progress'] ?? 0.0, $stateText, false, $st);
    } else {
        drive_activity($cfg, $slug, '', null, 1.0, '', false, $st);
        if (empty($st['active'])) {
            unset($st['log_path'], $st['step_weights'], $st['step_labels']);
        }
    }
    $state[$slug] = $st;
}

function tick_ups(array $cfg, string $prefix, array &$state): void {
    $slug = "$prefix-ups";
    $st   = $state[$slug] ?? [];
    $u    = $cfg['ups'] ? ups_state() : null;
    $onBattery = $u !== null && $u['on_battery'];

    // Debounce: a brief on-battery blip (UPS self-test, a momentary transfer)
    // must not fire a push-to-start outage alert, so require a couple of
    // consecutive on-battery polls before raising the activity.
    $st['ups_onbatt'] = $onBattery ? (int) ($st['ups_onbatt'] ?? 0) + 1 : 0;

    if ($onBattery && $st['ups_onbatt'] >= UPS_ONBATT_DEBOUNCE) {
        $charge = $u['charge'];
        $known  = $charge !== null;
        $charge = $known ? max(0.0, min(100.0, $charge)) : 0.0;
        $crit   = ($known && $charge <= 25) || ($u['timeleft'] > 0 && $u['timeleft'] <= 300);

        $stateText = $known ? sprintf('On battery · %d%%', round($charge)) : 'On battery';
        $subParts  = [];
        if ($u['timeleft'] > 0) {
            $subParts[] = '~' . human_eta($u['timeleft']) . ' runtime';
        }
        if ($u['load'] > 0) {
            $subParts[] = 'load ' . round($u['load']) . '%';
        }
        $content = [
            'template'     => 'generic',
            'state'        => $stateText,
            'subtitle'     => implode(' · ', $subParts),
            'icon'         => 'bolt.batteryblock.fill',
            'accent_color' => $crit ? 'red' : 'orange',
        ];
        if ($known) {
            $content['progress'] = round($charge / 100, 4);
        }
        if ($u['timeleft'] > 0) {
            $content['remaining_time'] = $u['timeleft'];
        } else {
            // Merge patch: without the null, an apcupsd that stops reporting
            // TIMELEFT mid-outage leaves the last runtime frozen on the card,
            // which is the one number someone acts on during a power cut.
            $content['remaining_time'] = null;
        }
        // Default end frame is NEUTRAL. If the activity ends for any reason other
        // than a confirmed return to line power - the server shutting down on a
        // dying battery, end-all on array stop, the toggle being turned off - it
        // must NOT claim "Power restored". That green frame is set only below,
        // when we actually observe the UPS back on line power.
        $st['end_content'] = [
            'template'     => 'generic',
            'state'        => 'On battery',
            'subtitle'     => 'Monitoring ended',
            'icon'         => 'bolt.batteryblock.fill',
            'accent_color' => 'orange',
        ];
        drive_activity($cfg, $slug, 'Unraid · ' . $cfg['server'] . ' UPS', $content, $known ? $charge / 100 : 0.0, $stateText, false, $st);
    } else {
        if (!empty($st['active']) && $u !== null && !$u['on_battery']) {
            // Observed back on line power: a genuine restore.
            $st['end_content'] = [
                'template'     => 'generic',
                'state'        => 'Power restored',
                'subtitle'     => 'UPS back on line power',
                'icon'         => 'powerplug.fill',
                'accent_color' => 'green',
            ];
        }
        drive_activity($cfg, $slug, '', null, 0.0, '', false, $st);
        // Keep the debounce counter while still accumulating on-battery polls;
        // only drop it once we're genuinely back on line power and idle.
        if (empty($st['active']) && !$onBattery) {
            unset($st['ups_onbatt']);
        }
    }
    $state[$slug] = $st;
}

function tick(array $cfg): void {
    $prefix = slug_prefix($cfg['server']);
    $state  = load_state();
    // The notification agent reads this to attach activity_slug without owning a
    // second copy of slug_prefix(). Keys starting with an underscore are
    // metadata, never activities - end_active() skips them.
    $state['_meta'] = ['prefix' => $prefix];
    if ($cfg['enabled']) {
        // Every handler runs and gates on its own per-source toggle internally,
        // so turning one source off while its activity is live still reaches
        // that handler's idle branch and ends the card rather than freezing it
        // until stale_ttl.
        $md = $cfg['parity'] ? md_status() : [];
        tick_parity($cfg, $prefix, $md, $state);
        tick_backup($cfg, $prefix, $state);
        tick_mover($cfg, $prefix, $state);
        tick_vmbackup($cfg, $prefix, $state);
        tick_ups($cfg, $prefix, $state);
    } else {
        // Activities off but widgets on keeps the daemon alive with no handler
        // running, so nothing would ever reach those idle branches: an in-flight
        // card would sit at its last frame until stale_ttl. End it here instead.
        end_active($cfg, $state);
    }
    tick_widgets($cfg, $prefix, $state);
    save_state($state);
}

// ---------------------------------------------------------------------------
// Home Screen widgets
//
// A second publishing surface alongside the Live Activities: three widgets that
// stay on the phone's Home Screen instead of appearing for the duration of a
// job. Off by default (see load_cfg) because the widget routes need the
// `widgets` capability on the key, which the setup instructions never asked for.
//
// Everything below reads only files Unraid already keeps warm - disks.ini and
// var.ini - plus the UPS reading the activity path already takes. A widget poll
// must NEVER shell out to smartctl, hdparm or virsh: disks.ini already carries
// status, temp and error counts, and a five-minute poll that spins the array up
// to re-read them is real heat and wear for no new information.
// ---------------------------------------------------------------------------

const WIDGET_STALE_AFTER   = 3600; // floor for the dim-as-stale window; see widget_stale_after()
const WIDGET_MAX_DEVICES   = 8;    // server cap on battery-template rings
const WIDGET_MAX_STAT_ROWS = 6;    // server cap on stat_list rows; a seventh 422s every poll
const MAX_DEVICE_NAME_RUNES = 32;  // server cap on a battery device name
const WIDGET_SYNC_EPOCH_MIN = 946684800; // 2000-01-01: the server rejects a date before this

function pw_widget_create(array $cfg, array $spec): array {
    return pw_request($cfg, 'POST', '/widgets', $spec);
}

function pw_widget_patch(array $cfg, string $slug, array $patch): array {
    return pw_request($cfg, 'PATCH', '/widgets/' . rawurlencode($slug), $patch, 'application/merge-patch+json');
}

function pw_widget_delete(array $cfg, string $slug): array {
    return pw_request($cfg, 'DELETE', '/widgets/' . rawurlencode($slug), null);
}

/** Every widget slug this plugin owns for a given prefix. The one source of truth. */
function widget_owned_slugs(string $prefix): array {
    return [$prefix . '-array-fill', $prefix . '-storage', $prefix . '-status'];
}

/** A disk is unhealthy when SMART is not OK or Unraid has counted errors on it. */
function disk_unhealthy(array $d): bool {
    $status = strtoupper((string) ($d['status'] ?? ''));
    return ($status !== '' && $status !== 'DISK_OK') || ((int) ($d['numErrors'] ?? 0)) > 0;
}

/** Array fill from the mounted data disks: [used, size] in bytes, or null. */
function array_fill_bytes(array $sections): ?array {
    $size = 0.0;
    $used = 0.0;
    foreach ($sections as $name => $d) {
        if (!is_array_disk($name) || ($d['fsStatus'] ?? '') !== 'Mounted') {
            continue;
        }
        // disks.ini reports filesystem figures in KiB.
        $size += ((float) ($d['fsSize'] ?? 0)) * 1024;
        $used += ((float) ($d['fsUsed'] ?? 0)) * 1024;
    }
    return $size > 0 ? [$used, $size] : null;
}

/** Content for the array-fill gauge, or null when the array is not mounted. */
function widget_content_array_fill(array $sections, ?array $link): ?array {
    $fill = array_fill_bytes($sections);
    if ($fill === null) {
        return null;
    }
    [$used, $size] = $fill;
    $pct   = round($used / $size * 100, 1);
    $color = $pct >= 95 ? 'red' : ($pct >= 90 ? 'orange' : 'blue');
    return array_filter([
        'template'     => 'gauge',
        'value'        => $pct,
        'min_value'    => 0,
        'max_value'    => 100,
        'unit'         => '%',
        'label'        => 'Array',
        'subtitle'     => human_bytes($used) . ' of ' . human_bytes($size),
        'icon'         => 'externaldrive.fill',
        'accent_color' => $color,
        'url_action'   => $link,
    ], fn($v) => $v !== null);
}

/**
 * Content for the storage battery widget: one ring per mounted disk and pool,
 * plus the UPS when apcupsd is running.
 *
 * The level is FREE space, not used: the Batteries idiom reads low as alarming,
 * and low headroom is the alarming state for a disk. An unhealthy disk goes red
 * whatever its level.
 */
function widget_content_storage(array $sections, ?array $link): ?array {
    $devices = [];
    foreach ($sections as $name => $d) {
        if (($d['fsStatus'] ?? '') !== 'Mounted') {
            continue;
        }
        $size = (float) ($d['fsSize'] ?? 0);
        $free = (float) ($d['fsFree'] ?? 0);
        if ($size <= 0) {
            continue;
        }
        $isArray = is_array_disk($name);
        if (!$isArray && !is_pool_disk($name)) {
            continue; // parity carries no filesystem; flash is not worth a ring
        }
        // The server caps a device name at 32 runes and rejects a longer one,
        // which would wedge the whole widget for a pool with a long name.
        $label   = $isArray ? 'Disk ' . substr($name, 4) : ucfirst($name);
        $healthy = !disk_unhealthy($d);
        $devices[] = [
            'name'    => mb_substr($label, 0, MAX_DEVICE_NAME_RUNES, 'UTF-8'),
            'level'   => (int) round($free / $size * 100),
            'icon'    => $isArray ? 'internaldrive' : 'externaldrive',
            'color'   => $healthy ? null : 'red',
            'healthy' => $healthy,
        ];
    }

    $ups = ups_state();
    if ($ups !== null && $ups['charge'] !== null) {
        $devices[] = [
            'name'     => 'UPS',
            'level'    => (int) round($ups['charge']),
            'icon'     => 'bolt.batteryblock.fill',
            'color'    => $ups['on_battery'] ? 'orange' : null,
            'charging' => !$ups['on_battery'],
            'healthy'  => !$ups['on_battery'],
        ];
    }
    if (!$devices) {
        return null;
    }

    // The 8-device cap is a validation error, not a truncation, so trim here:
    // unhealthy first (false <=> true is -1), then whichever has least headroom.
    usort($devices, fn($a, $b) => [$a['healthy'], $a['level']] <=> [$b['healthy'], $b['level']]);
    $devices = array_slice($devices, 0, WIDGET_MAX_DEVICES);
    // 'healthy' is ours, not the API's - the device schema rejects unknown keys -
    // so it comes off, along with any colour that resolved to nothing.
    $devices = array_map(
        fn($dev) => array_filter(array_diff_key($dev, ['healthy' => null]), fn($v) => $v !== null),
        $devices
    );

    return array_filter([
        'template'   => 'battery',
        'label'      => 'Free space',
        'icon'       => 'internaldrive',
        // No device_sort: it is a WRITE-path sort, so asking the server to
        // re-order by level would undo the trim priority above and bury an
        // unhealthy-but-roomy disk past the 2 rings a small widget renders.
        // Absent, the server keeps the order sent, which is the order chosen here.
        'devices'    => $devices,
        'url_action' => $link,
    ], fn($v) => $v !== null);
}

/** Content for the six-row status stat_list, or null when var.ini is unreadable. */
function widget_content_status(array $cfg, array $sections, array $var, ?array $link): ?array {
    $mdState = $var['mdState'] ?? null;
    if ($mdState === null) {
        return null;
    }
    // mdColor is Unraid's own array health light: "green-on" when everything is
    // fine, "green-blink" mid-rebuild, an amber/red value when it is not.
    // mdNumInvalid is NOT a health signal - an unassigned parity2 slot counts as
    // invalid, so on a single-parity box it reads 1 forever and would paint a
    // perfectly healthy array red for the life of the install.
    $mdColor  = (string) ($var['mdColor'] ?? '');
    $degraded = $mdColor !== '' && !str_starts_with($mdColor, 'green');
    $syncErrs = (int) ($var['sbSyncErrs'] ?? 0);
    // sbSynced is when the check STARTED; sbSynced2 is when it finished. The row
    // is a "last parity check" timer, so prefer the completion stamp and fall
    // back to the start only for an array that has never finished one.
    $synced   = (int) ($var['sbSynced2'] ?? 0) ?: (int) ($var['sbSynced'] ?? 0);

    $arrayState = $mdState === 'STARTED' ? ($degraded ? 'Degraded' : 'Started') : 'Stopped';
    $fill       = array_fill_bytes($sections);

    $bad = 0;
    $all = 0;
    foreach ($sections as $name => $d) {
        // Parity counts: a failing parity disk is the most alarming state an
        // Unraid box has, and leaving it out reported "N OK" through it. The
        // boot flash and never-assigned slots still do not. Exact match, not a
        // prefix: DISK_NP_DSBL and DISK_NP_MISSING are a disabled and a missing
        // disk, which are exactly the faults this count exists to surface.
        if ($name === 'flash' || ($d['status'] ?? '') === 'DISK_NP') {
            continue;
        }
        $all++;
        if (disk_unhealthy($d)) {
            $bad++;
        }
    }

    $rows = [
        ['label' => 'Array', 'value' => $arrayState],
        ['label' => 'Used', 'value' => $fill ? human_bytes($fill[0]) : '-'],
        ['label' => 'Free', 'value' => $fill ? human_bytes($fill[1] - $fill[0]) : '-'],
    ];

    $parity = ['label' => 'Parity', 'value' => $syncErrs > 0 ? $syncErrs . ' errors' : 'OK'];
    // A relative timer re-renders on the device with no pushes at all, so the
    // "3 days ago" reading stays honest between polls. Guarded on the epoch
    // floor: the server rejects a date before 2000, and sbSynced is 0 on a
    // never-synced array.
    if ($synced > WIDGET_SYNC_EPOCH_MIN) {
        $parity['timer'] = ['date' => gmdate('c', $synced), 'style' => 'relative'];
    }
    $rows[] = $parity;

    $rows[] = ['label' => 'Mover', 'value' => detect_mover($var) ? 'Running' : 'Idle'];
    $rows[] = ['label' => 'Disks', 'value' => $bad > 0 ? $bad . ' need attention' : $all . ' OK'];

    $usedPct  = $fill ? $fill[0] / $fill[1] * 100 : 0;
    $severity = ($bad > 0 || $degraded) ? 'critical' : (($syncErrs > 0 || $usedPct >= 95) ? 'warning' : 'info');
    $accent   = ['critical' => 'red', 'warning' => 'orange', 'info' => 'blue'][$severity];

    return array_filter([
        'template'     => 'stat_list',
        'label'        => $cfg['server'],
        'icon'         => 'server.rack',
        'severity'     => $severity,
        'accent_color' => $accent,
        // The row list above sits exactly on the cap, so a seventh row added
        // here would 422 the widget on every poll rather than just not render.
        'stat_rows'    => array_slice($rows, 0, WIDGET_MAX_STAT_ROWS),
        'url_action'   => $link,
    ], fn($v) => $v !== null);
}

/**
 * The three widget specs for this run. Each carries its rendered content, or
 * null when the data for it is unavailable this poll.
 */
function widget_specs(array $cfg, string $prefix): array {
    $sections = disks_ini_sections();
    $var      = var_ini_all();
    // All three buttons open the same page, so the base URL is resolved once
    // here instead of once per widget.
    $link     = unraid_webui_link($cfg, WEBUI_PATHS['-array']);
    return [
        ['slug' => $prefix . '-array-fill', 'name' => $cfg['server'] . ' array', 'content' => widget_content_array_fill($sections, $link)],
        ['slug' => $prefix . '-storage', 'name' => $cfg['server'] . ' storage', 'content' => widget_content_storage($sections, $link)],
        ['slug' => $prefix . '-status', 'name' => $cfg['server'] . ' status', 'content' => widget_content_status($cfg, $sections, $var, $link)],
    ];
}

/**
 * How long the phone waits before dimming a widget as stale. WIDGET_STALE_AFTER
 * is a floor, not the answer: PUSHWARD_WIDGET_INTERVAL goes up to 3600, where
 * the poll period would equal the window exactly and any jitter dims a card
 * that is perfectly current.
 */
function widget_stale_after(array $cfg): int {
    return max(WIDGET_STALE_AFTER, $cfg['widget_interval'] * 3);
}

/** Heartbeat: re-send the stored payload this often even when nothing changed. */
function widget_heartbeat_secs(array $cfg): int {
    return intdiv(widget_stale_after($cfg), 2);
}

/**
 * Create-once, PATCH-on-change, heartbeat-otherwise - the same shape the
 * pushward-integrations widget manager uses.
 */
function widget_drive(array $cfg, array $spec, array &$st): void {
    $now  = time();
    $slug = $spec['slug'];

    // No data this poll (array stopped, apcupsd restarting). Skip: publishing a
    // stale value would be a lie and deleting the widget would take it off the
    // user's Home Screen for a transient. Letting stale_after dim it is the
    // truthful outcome, and the status widget separately says "Stopped".
    if ($spec['content'] === null) {
        return;
    }

    $tuning = ['push_throttle' => max(60, $cfg['widget_interval']), 'stale_after' => widget_stale_after($cfg)];

    // POST is an idempotent upsert that also refreshes the tuning, costs no
    // quota and fires no push, so it doubles as the "the operator changed the
    // interval" path.
    if (empty($st['created']) || ($st['tuning'] ?? null) != $tuning) {
        // A floor, not an active brake: the cadence gate already keeps polls at
        // least widget_interval (>= 60s) apart, so this only bites the
        // back-to-back `widgets-once` path and any future shorter interval.
        if (!empty($st['fail_ts']) && ($now - (int) $st['fail_ts']) < START_RETRY_SECS) {
            return;
        }
        $r = pw_widget_create($cfg, ['slug' => $slug, 'name' => $spec['name'], 'content' => $spec['content']] + $tuning);
        if (!pw_ok($r)) {
            $st['fail_ts'] = $now;
            mlog("widget create $slug failed: {$r['code']} {$r['body']}", 'error');
            return;
        }
        unset($st['fail_ts']);
        $st['created'] = true;
        $st['tuning']  = $tuning;
        $st['content'] = $spec['content'];
        $st['sent_ts'] = $now;
        mlog("widget created $slug");
        return;
    }

    // Loose ==, deliberately: the stored copy has round-tripped through JSON, so
    // 100 vs 100.0 must not read as a change and cost a push.
    $changed = ($st['content'] ?? null) != $spec['content'];
    if (!$changed && ($now - (int) ($st['sent_ts'] ?? 0)) < widget_heartbeat_secs($cfg)) {
        return;
    }

    // The heartbeat re-sends the STORED payload verbatim, never a re-render. The
    // server treats byte-identical merged content as a touch: it re-stamps
    // updated_at with no push and refunds the quota slot. Re-rendering would
    // break that equality and turn every heartbeat into a real push.
    $payload = $changed ? $spec['content'] : $st['content'];
    $r = pw_widget_patch($cfg, $slug, ['content' => $payload]);
    if ($r['code'] === 404) {
        // Deleted in the app. Re-create on the next poll rather than 404-looping.
        $st['created'] = false;
        return;
    }
    if (!pw_ok($r)) {
        mlog("widget update $slug failed: {$r['code']} {$r['body']}", 'error');
        return;
    }
    $st['content'] = $payload;
    $st['sent_ts'] = $now;
}

/**
 * Remove every widget this plugin owns and forget the local state.
 *
 * The owned set is seeded unconditionally from the CURRENT server name's prefix,
 * not only from the recorded slugs, and that is what covers a reboot: /var/run
 * is tmpfs, so the state file is gone and there is nothing recorded left to
 * delete. Only ever the exact owned-slug set, never a bare prefix - the same key
 * may drive the relay, a second box or a hand-made widget.
 *
 * On a failed delete the state is KEPT, with a retry stamp, so an API outage at
 * toggle-off does not orphan the widgets on the account forever.
 */
function widgets_teardown(array $cfg, array &$state): void {
    $prefixes = array_unique(array_filter([
        slug_prefix($cfg['server']),
        (string) ($state['_widgets']['prefix'] ?? ''),
    ]));
    $owned = [];
    foreach ($prefixes as $p) {
        foreach (widget_owned_slugs($p) as $slug) {
            $owned[$slug] = true;
        }
    }

    foreach (array_keys($state['_widgets']['slugs'] ?? []) as $slug) {
        $owned[$slug] = true;
    }

    $failed = false;
    foreach (array_keys($owned) as $slug) {
        $r = pw_widget_delete($cfg, $slug);
        if (pw_ok($r) || $r['code'] === 404) {
            mlog("widget removed $slug");
        } else {
            $failed = true;
            mlog("widget delete $slug failed: {$r['code']}", 'warn');
        }
    }
    if ($failed) {
        // Keep the owned set so the next poll retries it, behind a backoff of
        // its own - teardown_ts, not the poll cadence's next_ts, so an operator
        // toggling widgets off still gets an immediate first attempt.
        $state['_widgets'] = [
            'prefix'      => (string) ($state['_widgets']['prefix'] ?? slug_prefix($cfg['server'])),
            'teardown_ts' => time() + max(START_RETRY_SECS, $cfg['widget_interval']),
            'slugs'       => array_fill_keys(array_keys($owned), []),
        ];
        return;
    }
    unset($state['_widgets']);
}

/**
 * One widget poll. Gates on the toggle internally, the same way the activity
 * handlers do, so turning widgets off mid-run reaches the teardown branch
 * instead of freezing the widgets in place.
 */
function tick_widgets(array $cfg, string $prefix, array &$state): void {
    $w       = $state['_widgets'] ?? null;
    $now     = time();
    $retryTs = (int) ($w['teardown_ts'] ?? 0);

    if (!$cfg['widgets']) {
        if ($w !== null && $now >= $retryTs) {
            widgets_teardown($cfg, $state);
        }
        return;
    }
    // A renamed server changes every slug, so the old set has to go first.
    if ($w !== null && ($w['prefix'] ?? '') !== $prefix) {
        if ($now < $retryTs) {
            return; // a previous teardown failed; wait the backoff out
        }
        widgets_teardown($cfg, $state);
        if (isset($state['_widgets'])) {
            return; // deletes failed - retry before publishing under the new name
        }
        $w = null;
    }

    $w = $w ?? ['prefix' => $prefix, 'next_ts' => 0, 'slugs' => []];
    unset($w['teardown_ts']); // widgets are on again; the retry stamp is spent
    if ($now < (int) ($w['next_ts'] ?? 0)) {
        $state['_widgets'] = $w;
        return;
    }
    // Widgets move in GB/hour, so they run on their own cadence rather than the
    // 15s activity poll.
    $w['next_ts'] = $now + $cfg['widget_interval'];

    foreach (widget_specs($cfg, $prefix) as $spec) {
        $st = $w['slugs'][$spec['slug']] ?? [];
        widget_drive($cfg, $spec, $st);
        $w['slugs'][$spec['slug']] = $st;
    }
    $state['_widgets'] = $w;
}

// ---------------------------------------------------------------------------
// One-shot subcommands
// ---------------------------------------------------------------------------

/**
 * End every activity still marked active, in the caller's own state array.
 *
 * dismissal_ttl 0 because every caller is a forced teardown (array stop,
 * uninstall, a toggle going off, a server rename). A natural completion keeps
 * its ended_ttl linger - that card is worth a last look. The TTL and the state
 * change go in one PATCH: the server persists both before it builds the end
 * push, so the dismissal date rides that push.
 *
 * Takes the state by reference so a caller that has more to do to it - the
 * daemon's shutdown branch, which also tears the widgets down - spends one
 * load/save cycle instead of two.
 */
function end_active(array $cfg, array &$state): void {
    foreach ($state as $slug => $st) {
        // Underscore-prefixed keys are metadata (_meta, _widgets), not
        // activities. is_string first: a numeric JSON key decodes to an int and
        // $slug[0] would warn on it.
        if (!is_array($st) || !is_string($slug) || $slug === '' || $slug[0] === '_') {
            continue;
        }
        if (empty($st['active'])) {
            continue;
        }
        $end = $st['end_content'] ?? ['template' => 'generic', 'state' => 'Ended'];
        pw_patch($cfg, $slug, ['state' => 'ended', 'dismissal_ttl' => 0, 'content' => $end]);
        $state[$slug]['active'] = false;
        mlog("teardown: ended $slug");
    }
}

function cmd_end_all(array $cfg): void {
    $state = load_state();
    end_active($cfg, $state);
    save_state($state);
}

function cmd_widgets_clear(array $cfg): void {
    $state = load_state();
    widgets_teardown($cfg, $state);
    save_state($state);
}

function cmd_test_activity(array $cfg): void {
    $slug = slug_prefix($cfg['server']) . '-test';
    // Short TTLs so a forgotten test card self-cleans on the server within
    // minutes rather than lingering ~30 min.
    $c = pw_create($cfg, $slug, 'Unraid · ' . $cfg['server'] . ' test', $cfg['priority'], 120, 600, 60);
    if (!pw_ok($c)) {
        fwrite(STDERR, "create failed: {$c['code']} {$c['body']}\n");
        exit(1);
    }
    $content = [
        'template'     => 'generic',
        'progress'     => 0.42,
        'state'        => 'PushWard test activity',
        'subtitle'     => 'If you see this on your phone, Live Activities work',
        'icon'         => 'bell.badge.fill',
        'accent_color' => 'indigo',
    ];
    // The test card is the first one most users ever see, so it gets the same
    // Open button every real card carries (WEBUI_PATHS['-test']).
    $urlAction = unraid_url_action($cfg, $slug);
    if ($urlAction !== null) {
        $content['url_action'] = $urlAction;
    }
    $p = pw_patch($cfg, $slug, ['state' => 'ongoing', 'content' => $content]);
    if (!pw_ok($p)) {
        fwrite(STDERR, "seed failed: {$p['code']} {$p['body']}\n");
        exit(1);
    }
    // Record it in state so end-all (array stop / uninstall) ends it; otherwise
    // it would survive both and only disappear on the server's stale_ttl.
    $state = load_state();
    $state[$slug] = [
        'active'       => true,
        'last_push_ts' => time(),
        'end_content'  => [
            'template'     => 'generic',
            'progress'     => 1.0,
            'state'        => 'Test ended',
            'icon'         => 'checkmark.circle.fill',
            'accent_color' => 'green',
        ],
    ];
    save_state($state);
    echo "Test activity '$slug' pushed. Check your device and the dashboard.\n";
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

function run_daemon(array $cfg): void {
    @mkdir(STATE_DIR, 0755, true);
    $lock = @fopen(LOCK_FILE, 'c');
    if (!$lock) {
        // Losing the lock file is not the normal case below, and this exit used to
        // happen before the first mlog() - so the log stayed empty and the settings
        // page just said "Not running" with nothing to go on.
        mlog('cannot open ' . LOCK_FILE . '; is ' . STATE_DIR . ' writable?', 'error');
        exit(0);
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        // Another instance already owns the loop. Expected every time the watchdog
        // double-launches, so stay quiet rather than logging once a minute.
        exit(0);
    }

    $running = true;
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        $stop = function () use (&$running) {
            $running = false;
        };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
    }

    mlog('monitor started (interval ' . $cfg['interval'] . 's)');
    while ($running) {
        try {
            tick($cfg);
        } catch (\Throwable $e) {
            mlog('tick error: ' . $e->getMessage(), 'error');
        }
        $fresh = load_cfg();
        // Ignore a transient failed read (settings page mid-write); only adopt a
        // config that actually parsed.
        if ($fresh['valid']) {
            // A renamed server changes the slug prefix; end the old-prefix
            // activities (still in state, still reachable with the current key)
            // before adopting the new name, so they don't freeze on the phone
            // while duplicates appear under the new prefix.
            if (slug_prefix($fresh['server']) !== slug_prefix($cfg['server']) && $cfg['key'] !== '') {
                mlog('server name changed; ending activities under the old prefix');
                cmd_end_all($cfg);
            }
            $cfg = $fresh;
        }
        // The daemon now serves two surfaces, so it stays up while either is on.
        if ((!$cfg['enabled'] && !$cfg['widgets']) || $cfg['key'] === '') {
            // End in-flight activities on disable so the user isn't left with a
            // frozen card until stale_ttl. A cleared key can't authenticate, so
            // only the API is out of reach then, and nothing we can do but exit.
            if ($cfg['key'] !== '') {
                mlog('both surfaces disabled; ending activities, removing widgets and exiting');
                $state = load_state();
                end_active($cfg, $state);
                widgets_teardown($cfg, $state);
                save_state($state);
            } else {
                mlog('API key cleared; exiting (cannot end activities without auth)');
            }
            break;
        }
        for ($i = 0; $i < $cfg['interval'] && $running; $i++) {
            sleep(1);
        }
    }
    mlog('monitor stopped');
}

// ab.log (and Unraid logs) are written in the box's local time, but PHP defaults
// to UTC, so match the box so parsed LogLine `at` values are correct instants.
function pushward_local_tz(): string {
    $link = @readlink('/etc/localtime');
    if ($link !== false && ($i = strpos($link, 'zoneinfo/')) !== false) {
        $tz = substr($link, $i + strlen('zoneinfo/'));
        if (@timezone_open($tz) !== false) {
            return $tz;
        }
    }
    return @date_default_timezone_get() ?: 'UTC';
}
date_default_timezone_set(pushward_local_tz());

// Allow unit tests to require this file for its functions without running main.
if (defined('PUSHWARD_NO_MAIN')) {
    return;
}

$cfg = load_cfg();
$mode = $argv[1] ?? 'daemon';

if ($cfg['key'] === '') {
    fwrite(STDERR, "PushWard API key not configured\n");
    exit(0);
}

switch ($mode) {
    case 'end-all':
        cmd_end_all($cfg);
        break;
    case 'test-activity':
        cmd_test_activity($cfg);
        break;
    case 'once':
        // Run a single poll cycle and exit (testing / cron-fallback).
        if ($cfg['enabled'] || $cfg['widgets']) {
            tick($cfg);
        }
        break;
    case 'widgets-clear':
        // Reachable regardless of the toggles, like end-all: it is what turns
        // widgets off, and it also has to work after a reboot has dropped the
        // tmpfs state file (the unconditional prefix seeding covers that).
        cmd_widgets_clear($cfg);
        break;
    case 'widgets-once':
        // One widget poll, for debugging. Bypasses the next_ts cadence gate so
        // the effect is immediate. Gated on the toggle because tick_widgets
        // tears down when it is off, which would make this a silent alias for
        // widgets-clear.
        if (!$cfg['widgets']) {
            fwrite(STDERR, "widgets are disabled (PUSHWARD_WIDGETS_ENABLED); use widgets-clear to remove them\n");
            break;
        }
        $state = load_state();
        unset($state['_widgets']['next_ts']);
        tick_widgets($cfg, slug_prefix($cfg['server']), $state);
        save_state($state);
        break;
    case 'daemon':
    default:
        if (!$cfg['enabled'] && !$cfg['widgets']) {
            exit(0);
        }
        run_daemon($cfg);
        break;
}
