#!/usr/bin/php
<?php
/**
 * PushWard Unraid monitor daemon.
 *
 * Polls Unraid for long-running operations and drives PushWard Live Activities
 * over the public REST API:
 *   - parity check / rebuild / clear  -> "generic" template (progress + ETA)
 *   - appdata backup (CA appdata.backup) -> "log" template (streaming log lines)
 *   - mover (cache -> array)          -> "generic" template (indeterminate)
 *
 * Single long-running process, supervised by watchdog.sh (flock + pgrep) and a
 * 1-minute cron, started/stopped by the array event hooks. No external deps
 * beyond php-cli + curl, both always present on Unraid.
 *
 * Usage:
 *   php pushward-monitor.php             # run the daemon loop
 *   php pushward-monitor.php end-all     # end every active activity, clear state
 *   php pushward-monitor.php test-activity  # push a short demo activity
 */

const CFG_FILE   = '/boot/config/plugins/pushward-unraid/pushward-unraid.cfg';
const STATE_DIR  = '/var/run/pushward';
const STATE_FILE = '/var/run/pushward/state.json';
const LOCK_FILE  = '/var/run/pushward/monitor.lock';
const LOG_FILE   = '/var/log/pushward-monitor.log';
const LOG_MAX    = 262144; // 256 KiB before truncation
const BACKUP_TMP = '/tmp/appdata.backup';
const VAR_INI    = '/var/local/emhttp/var.ini';

const PROGRESS_EPSILON = 0.01; // re-push when progress moves >= 1%
const HEARTBEAT_SECS   = 30;   // re-push at least this often while active (keeps ETA fresh)
const START_RETRY_SECS = 60;   // back off this long after a failed create

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

/** The newest $n log lines as PushWard LogLine objects, newest-first. */
function backup_log_lines(string $logPath, int $n): array {
    $content = (string) @file_get_contents($logPath);
    $raw = array_values(array_filter(
        preg_split('/\r?\n/', $content) ?: [],
        fn($l) => trim($l) !== ''
    ));
    $tail = array_slice($raw, -$n);
    $out = [];
    foreach ($tail as $l) {
        $p = parse_backup_line($l);
        if ($p === null) {
            continue;
        }
        $text = ($p['comp'] !== '' && $p['comp'] !== 'Main') ? $p['comp'] . ': ' . $p['msg'] : $p['msg'];
        $text = mb_substr($text, 0, 512);
        if ($text === '') {
            continue;
        }
        $line = ['text' => $text, 'level' => $p['level']];
        if ($p['at'] !== null) {
            $line['at'] = $p['at'];
        }
        $out[] = $line;
    }
    return array_reverse($out); // newest-first
}

/** Size of ab.log, to detect new content cheaply for the throttle. */
function backup_log_size(string $logPath): int {
    $s = @filesize($logPath);
    return $s === false ? 0 : (int) $s;
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
            // Merge (don't replace) so end_content / log_size / pos bookkeeping
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
    $p    = detect_parity($md);

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

        $pct       = round($p['progress'] * 100);
        $stateText = sprintf('%s · %d%%', $p['label'], $pct);
        $subParts  = [];
        if ($eta > 0) {
            $subParts[] = 'ETA ' . human_eta($eta);
        }
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
        if ($eta > 0) {
            $content['remaining_time'] = $eta;
        }
        $st['end_content'] = [
            'template'     => 'generic',
            'progress'     => 1.0,
            'state'        => $p['label'] . ' complete',
            'subtitle'     => $p['corr'] > 0 ? $p['corr'] . ' errors found' : 'No errors',
            'icon'         => $p['corr'] > 0 ? 'exclamationmark.triangle.fill' : 'checkmark.circle.fill',
            'accent_color' => $p['corr'] > 0 ? 'red' : 'green',
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

    if (backup_running()) {
        $bp   = backup_progress($log);
        $size = backup_log_size($log);
        $newContent = $size !== (int) ($st['log_size'] ?? -1);
        $st['log_size'] = $size;

        $stateText = $bp['current'] !== ''
            ? sprintf('Backing up %s (%d/%d)', $bp['current'], $bp['idx'], $bp['total'])
            : 'Starting appdata backup…';
        $content = [
            'template'     => 'log',
            'progress'     => round($bp['progress'], 4),
            'state'        => $stateText,
            'icon'         => 'externaldrive.badge.timemachine',
            'accent_color' => $bp['error'] ? 'orange' : 'blue',
            'lines'        => backup_log_lines($log, 10),
        ];
        $st['end_content'] = [
            'template'     => 'log',
            'progress'     => 1.0,
            'state'        => $bp['error'] ? 'Backup finished with errors' : 'Backup complete',
            'icon'         => $bp['error'] ? 'exclamationmark.triangle.fill' : 'checkmark.circle.fill',
            'accent_color' => $bp['error'] ? 'red' : 'green',
            'lines'        => backup_log_lines($log, 10),
        ];
        drive_activity($cfg, $slug, 'Unraid · ' . $cfg['server'] . ' appdata backup', $content, $bp['progress'], $stateText, $newContent, $st);
    } else {
        if (!empty($st['active']) && !empty($st['end_content'])) {
            // refresh the final frame with the last lines of the completed run
            $st['end_content']['lines'] = backup_log_lines($log, 10);
        }
        drive_activity($cfg, $slug, '', null, 1.0, '', false, $st);
        if (empty($st['active'])) {
            unset($st['log_size']);
        }
    }
    $state[$slug] = $st;
}

function tick_mover(array $cfg, string $prefix, array &$state): void {
    $slug = "$prefix-mover";
    $st   = $state[$slug] ?? [];

    if (detect_mover()) {
        $stateText = 'Mover running';
        $content = [
            'template'     => 'generic',
            'state'        => $stateText,
            'subtitle'     => 'Moving cache → array',
            'icon'         => 'arrow.down.to.line',
            'accent_color' => 'blue',
        ];
        $st['end_content'] = [
            'template'     => 'generic',
            'state'        => 'Mover finished',
            'icon'         => 'checkmark.circle.fill',
            'accent_color' => 'green',
        ];
        drive_activity($cfg, $slug, 'Unraid · ' . $cfg['server'] . ' mover', $content, 0.0, $stateText, false, $st);
    } else {
        drive_activity($cfg, $slug, '', null, 0.0, '', false, $st);
    }
    $state[$slug] = $st;
}

function tick(array $cfg): void {
    $prefix = slug_prefix($cfg['server']);
    $state  = load_state();
    $md     = ($cfg['parity']) ? md_status() : [];
    if ($cfg['parity']) {
        tick_parity($cfg, $prefix, $md, $state);
    }
    if ($cfg['backup']) {
        tick_backup($cfg, $prefix, $state);
    }
    if ($cfg['mover']) {
        tick_mover($cfg, $prefix, $state);
    }
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
    $lock = fopen(LOCK_FILE, 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        exit(0); // another instance already owns the loop
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
