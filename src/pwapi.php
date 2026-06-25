<?php
/**
 * Server-side proxy for the PushWard Activities dashboard.
 *
 * Keeps the integration key on the server; the browser never sees it. Exposes:
 *   ?action=list             -> GET  /activities          (read, no CSRF)
 *   ?action=authcheck        -> GET  /auth/me             (read, no CSRF)
 *   action=end   &slug=...   -> PATCH /activities/{slug} {state:ended}  (CSRF)
 *   action=cancel&slug=...   -> DELETE /activities/{slug}               (CSRF)
 *
 * Mutating actions require Unraid's csrf_token (POST), matching the webGUI's
 * convention; the page is already behind the webGUI's authenticated session.
 */

header('Content-Type: application/json');

$cfg = @parse_ini_file('/boot/config/plugins/pushward-unraid/pushward-unraid.cfg') ?: [];
$url = rtrim($cfg['PUSHWARD_URL'] ?? 'https://api.pushward.app', '/');
$key = trim($cfg['PUSHWARD_API_KEY'] ?? '');

if ($key === '') {
    http_response_code(400);
    echo json_encode(['error' => 'PushWard API key not configured']);
    exit;
}

$action = $_REQUEST['action'] ?? 'list';
$slug   = $_REQUEST['slug'] ?? '';

// This dashboard must only ever show / act on THIS server's own activities. The
// same account/key may also drive the relay (Grafana, Sonarr, Proxmox, etc.) or a
// second Unraid box; GET /activities is account-wide, so we filter the list to
// our own slug prefix and refuse mutations on any foreign slug. Mirrors the
// monitor's slug_prefix().
$prefix = pw_slug_prefix(trim($cfg['PUSHWARD_SERVER_NAME'] ?? 'Unraid'));

function pw_slug_prefix(string $server): string {
    $p = strtolower($server);
    $p = preg_replace('/[^a-z0-9]+/', '-', $p);
    $p = trim((string) $p, '-');
    return $p !== '' ? $p : 'unraid';
}

function pw_owns(string $slug, string $prefix): bool {
    return $slug === $prefix || str_starts_with($slug, $prefix . '-');
}

function unraid_csrf(): string {
    $data = (string) @file_get_contents('/var/local/emhttp/var.ini');
    if (preg_match('/^csrf_token="?([^"\n]*)"?/m', $data, $m)) {
        return $m[1];
    }
    return '';
}

function require_csrf(): void {
    $sent = $_POST['csrf_token'] ?? $_REQUEST['csrf_token'] ?? '';
    $real = unraid_csrf();
    if ($real === '' || !hash_equals($real, (string) $sent)) {
        http_response_code(403);
        echo json_encode(['error' => 'invalid csrf token']);
        exit;
    }
}

function pw(array $ctx, string $method, string $path, ?array $body, string $ct = 'application/json'): array {
    $ch = curl_init($ctx['url'] . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $ctx['key'],
            'Content-Type: ' . $ct,
            'Accept: application/json',
        ],
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => (string) $resp];
}

$ctx = ['url' => $url, 'key' => $key];

switch ($action) {
    case 'list':
        $r = pw($ctx, 'GET', '/activities', null);
        http_response_code($r['code'] ?: 502);
        // Filter the account-wide list down to our own activities so foreign
        // ones are never shown (and so their Cancel/delete buttons never exist).
        if ($r['code'] >= 200 && $r['code'] < 300) {
            $data = json_decode($r['body'], true);
            if (is_array($data) && isset($data['items']) && is_array($data['items'])) {
                $data['items'] = array_values(array_filter($data['items'], function ($a) use ($prefix) {
                    return is_array($a) && pw_owns((string) ($a['slug'] ?? ''), $prefix);
                }));
                echo json_encode($data);
                break;
            }
        }
        echo $r['body'] !== '' ? $r['body'] : json_encode(['error' => 'no response']);
        break;

    case 'authcheck':
        $r = pw($ctx, 'GET', '/auth/me', null);
        http_response_code($r['code'] ?: 502);
        echo $r['body'] !== '' ? $r['body'] : json_encode(['error' => 'no response']);
        break;

    case 'end':
        require_csrf();
        if ($slug === '') {
            http_response_code(400);
            echo json_encode(['error' => 'missing slug']);
            break;
        }
        if (!pw_owns($slug, $prefix)) {
            http_response_code(403);
            echo json_encode(['error' => 'slug not owned by this server']);
            break;
        }
        $r = pw($ctx, 'PATCH', '/activities/' . rawurlencode($slug), ['state' => 'ended'], 'application/merge-patch+json');
        http_response_code($r['code'] ?: 502);
        echo $r['body'] !== '' ? $r['body'] : json_encode(['ok' => true]);
        break;

    case 'cancel':
        require_csrf();
        if ($slug === '') {
            http_response_code(400);
            echo json_encode(['error' => 'missing slug']);
            break;
        }
        if (!pw_owns($slug, $prefix)) {
            http_response_code(403);
            echo json_encode(['error' => 'slug not owned by this server']);
            break;
        }
        $r = pw($ctx, 'DELETE', '/activities/' . rawurlencode($slug), null);
        http_response_code($r['code'] ?: 502);
        echo $r['body'] !== '' ? $r['body'] : json_encode(['ok' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'unknown action']);
}
