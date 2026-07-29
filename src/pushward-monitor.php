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

function pw_create(array $cfg, string $slug, string $name, int $priority, int $endedTtl = 300, int $staleTtl = 1800): array {
    return pw_request($cfg, 'POST', '/activities', [
        'slug'      => $slug,
        'name'      => $name,
        'priority'  => $priority,
        'ended_ttl' => $endedTtl,
        'stale_ttl' => $staleTtl,
    ]);
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
// Detection: mover (var.ini)
// ---------------------------------------------------------------------------

function var_ini_val(string $key): ?string {
    $data = @file_get_contents(VAR_INI);
    if ($data === false) {
        return null;
    }
    if (preg_match('/^' . preg_quote($key, '/') . '="?([^"\n]*)"?/m', $data, $m)) {
        return $m[1];
    }
    return null;
}

function detect_mover(): bool {
    return var_ini_val('shareMoverActive') === 'yes';
}

/**
 * Cache-pool mount points: every disks.ini section that isn't an array data
 * disk, parity disk or the boot flash. Unraid reserves the diskN/parity/flash
 * names for the array, so anything else is a user-named cache pool.
 */
function cache_pool_mounts(): array {
    $data = (string) @file_get_contents(DISKS_INI);
    $mounts = [];
    if (preg_match_all('/^\["?([^"\]]+)"?\]/m', $data, $m)) {
        foreach ($m[1] as $name) {
            if (preg_match('/^(disk\d+|parity\d*|flash)$/', $name)) {
                continue;
            }
            $mp = '/mnt/' . $name;
            if (is_dir($mp)) {
                $mounts[$mp] = true;
            }
        }
    }
    return array_keys($mounts);
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
        if ($p['level'] === 'error') {
            $error = true;
        }
    }
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
        'total'    => $total,
        'done'     => $d,
        'step'     => $total > 0 ? min($step, $total) : $step,
        'current'  => $current,
        'progress' => $total > 0 ? min(1.0, $d / $total) : null,
        'error'    => $error,
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
function vmbackup_step_weights(): array {
    $vms = vmbackup_planned_vms();
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
function drive_activity(array $cfg, string $slug, string $name, ?array $content, float $progress, string $stateText, bool $newContent, array &$st): void {
    $now = time();

    if ($content !== null) {
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
                $st['step_weights'] = vmbackup_step_weights(); // one-shot per run
            }
            // Only attach when the count matches (a mid-run config edit could
            // desync it); a length mismatch is a 422 on the steps template.
            $weights = count($st['step_weights']) === $total ? $st['step_weights'] : null;
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
            unset($st['log_path'], $st['step_weights']);
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
    // Every handler runs each tick and gates on its own toggle internally, so
    // turning a source off while its activity is live still reaches the handler's
    // idle branch and ends the activity (rather than freezing it until TTL).
    $md = $cfg['parity'] ? md_status() : [];
    tick_parity($cfg, $prefix, $md, $state);
    tick_backup($cfg, $prefix, $state);
    tick_mover($cfg, $prefix, $state);
    tick_vmbackup($cfg, $prefix, $state);
    tick_ups($cfg, $prefix, $state);
    save_state($state);
}

// ---------------------------------------------------------------------------
// One-shot subcommands
// ---------------------------------------------------------------------------

function cmd_end_all(array $cfg): void {
    $state = load_state();
    foreach ($state as $slug => $st) {
        if (!empty($st['active'])) {
            $end = $st['end_content'] ?? ['template' => 'generic', 'state' => 'Ended'];
            pw_patch($cfg, $slug, ['state' => 'ended', 'content' => $end]);
            $state[$slug]['active'] = false;
            mlog("end-all: ended $slug");
        }
    }
    save_state($state);
}

function cmd_test_activity(array $cfg): void {
    $slug = slug_prefix($cfg['server']) . '-test';
    // Short TTLs so a forgotten test card self-cleans on the server within
    // minutes rather than lingering ~30 min.
    $c = pw_create($cfg, $slug, 'Unraid · ' . $cfg['server'] . ' test', $cfg['priority'], 120, 600);
    if (!pw_ok($c)) {
        fwrite(STDERR, "create failed: {$c['code']} {$c['body']}\n");
        exit(1);
    }
    $p = pw_patch($cfg, $slug, ['state' => 'ongoing', 'content' => [
        'template'     => 'generic',
        'progress'     => 0.42,
        'state'        => 'PushWard test activity',
        'subtitle'     => 'If you see this on your phone, Live Activities work',
        'icon'         => 'bell.badge.fill',
        'accent_color' => 'indigo',
    ]]);
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
        if (!$cfg['enabled'] || $cfg['key'] === '') {
            // End in-flight activities on disable so the user isn't left with a
            // frozen card until stale_ttl. A cleared key can't authenticate, so
            // only the API is out of reach then, and nothing we can do but exit.
            if ($cfg['key'] !== '') {
                mlog('Live Activities disabled; ending active activities and exiting');
                cmd_end_all($cfg);
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
        if ($cfg['enabled']) {
            tick($cfg);
        }
        break;
    case 'daemon':
    default:
        if (!$cfg['enabled']) {
            exit(0);
        }
        run_daemon($cfg);
        break;
}
