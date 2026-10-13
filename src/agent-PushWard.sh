#!/bin/bash
# PushWard notification agent for Unraid.
#
# Invoked by the dynamix notification system with the notification in the
# environment: EVENT, SUBJECT, DESCRIPTION, IMPORTANCE, CONTENT, LINK,
# TIMESTAMP. Posts them to the PushWard notification API. No daemon, no Unraid
# API key. Config: /boot/config/plugins/pushward-unraid/pushward-unraid.cfg

# The override exists so CI can drive the agent without /boot; only root can set
# it, and dynamix hands the agent a controlled environment.
CFG="${PUSHWARD_CFG:-/boot/config/plugins/pushward-unraid/pushward-unraid.cfg}"
STATE_FILE="${PUSHWARD_STATE:-/var/run/pushward/state.json}"
[ -f "$CFG" ] || exit 0

# Read the config by parsing, NOT by sourcing it: the values are operator-entered
# free text (server name, URL), and `. "$CFG"` would execute any $(...)/backtick
# in them on every notification. grep+cut never evaluates the value.
pw_cfg() {  # $1 = key -> value with surrounding quotes stripped
  local v
  v="$(grep -E "^[[:space:]]*$1[[:space:]]*=" "$CFG" 2>/dev/null | tail -n1 | cut -d= -f2-)"
  v="${v%$'\r'}"                   # the .cfg is on vfat, so a Windows editor leaves a
                                   # CR that would otherwise ride along into the value
  v="${v#"${v%%[![:space:]]*}"}"   # trim around the value, never inside it, so a
  v="${v%"${v##*[![:space:]]}"}"   # deliberate space in the server name survives
  v="${v%\"}"; v="${v#\"}"
  printf '%s' "$v"
}
PUSHWARD_API_KEY="$(pw_cfg PUSHWARD_API_KEY)"
PUSHWARD_URL="$(pw_cfg PUSHWARD_URL)"
PUSHWARD_SERVER_NAME="$(pw_cfg PUSHWARD_SERVER_NAME)"
PUSHWARD_ALERT_LEVEL="$(pw_cfg PUSHWARD_ALERT_LEVEL)"
PUSHWARD_WEBUI_URL="$(pw_cfg PUSHWARD_WEBUI_URL)"
PUSHWARD_E2E_KEY="$(pw_cfg PUSHWARD_E2E_KEY)"
PUSHWARD_ACK="$(pw_cfg PUSHWARD_ACK)"
PUSHWARD_ACK_REPEAT="$(pw_cfg PUSHWARD_ACK_REPEAT)"
PUSHWARD_ACK_EXPIRE="$(pw_cfg PUSHWARD_ACK_EXPIRE)"

# Nothing to send without an API key; exit cleanly so the notification pipeline
# isn't disrupted.
[ -n "${PUSHWARD_API_KEY:-}" ] || exit 0

URL="${PUSHWARD_URL:-https://api.pushward.app}"
SERVER_NAME="${PUSHWARD_SERVER_NAME:-Unraid}"

# Map Unraid importance to a PushWard interruption level. Unraid only has three
# levels (normal/warning/alert), so which one an alert lands on is an operator
# choice: PUSHWARD_ALERT_LEVEL picks between active, time-sensitive and critical.
#
# The subject rule below only ever raises. It is tempting to demote a
# "Notice [...]" subject, but Unraid's own monitor sends parity-check finished
# WITH errors as exactly that, at importance warning - demoting on the prefix
# would silence a parity check that found problems.
ALERT_LEVEL="${PUSHWARD_ALERT_LEVEL:-time-sensitive}"
case "$ALERT_LEVEL" in
  active | time-sensitive | critical) ;;
  *) ALERT_LEVEL="time-sensitive" ;;
esac

# IS_ALERT marks what arrived as an Unraid alert, the only notifications that may
# repeat until acknowledged. The level alone can not tell: with the alert level
# set to Normal, a warning lands on the same one.
IS_ALERT=0
case "$(printf '%s' "${IMPORTANCE:-}" | tr '[:upper:]' '[:lower:]')" in
  alert) LEVEL="$ALERT_LEVEL"; IS_ALERT=1 ;;
  warning) LEVEL="active" ;;
  *) LEVEL="passive" ;;
esac

case "${SUBJECT:-}" in
  "Alert ["*) if [ "$LEVEL" = "passive" ]; then LEVEL="$ALERT_LEVEL"; IS_ALERT=1; fi ;;
  "Warning ["*) [ "$LEVEL" = "passive" ] && LEVEL="active" ;;
esac

# Repeat until acknowledged: opt-in, alerts only. An alert never maps to passive,
# the one level the server refuses it on.
ACK=""
if [ "$PUSHWARD_ACK" = "1" ] && [ "$IS_ALERT" = "1" ]; then ACK=1; fi

# Collapse repeats of the same event into one banner. Unraid's own archive
# dedupe never fires (notify sets the ticket to the timestamp), which is why a
# repeating SMART warning stacks today. The server name is in the key because
# thread_id is the constant "unraid": without it two boxes on one account would
# collapse each other's "Unraid Disk 1 error". The inbox still keeps every
# notification - collapsing only affects the delivered banner.
#
# Keying on EVENT alone and not EVENT+SUBJECT is deliberate: the SMART subject
# embeds the ticking raw attribute value, so a subject-derived key would change
# on every repeat and stack again.
#
# The exception is "Unraid Status", which notify fills in when the caller gives
# no -e (User Scripts and many plugins). Those are unrelated notifications under
# one name, so their subject joins the event: otherwise each would replace the
# last one's banner, take over its repeats, or cancel them.
EVENT_KEY="${EVENT:-}"
GENERIC_EVENT=0
if [ "$(printf '%s' "$EVENT_KEY" | tr '[:upper:]' '[:lower:]')" = "unraid status" ]; then
  EVENT_KEY="$EVENT_KEY|${SUBJECT:-}"
  GENERIC_EVENT=1
fi
COLLAPSE_ID=""
if [ -n "$EVENT_KEY" ]; then
  slugify() { printf '%s' "$1" | tr '[:upper:]' '[:lower:]' | sed -e 's/[^a-z0-9]\{1,\}/-/g' -e 's/^-//' -e 's/-$//'; }
  COLLAPSE_ID="$(slugify "$SERVER_NAME-$EVENT")"
  # 64 is the server cap. Keep a readable prefix and make the tail collision-safe
  # rather than truncating two long events onto the same key. A generic subject
  # always goes into the hash and never the slug: a slug keeps only a-z and 0-9,
  # so subjects in another script, or that differ in accents or punctuation,
  # would all come out the same.
  if [ "$GENERIC_EVENT" = "1" ] || [ "${#COLLAPSE_ID}" -gt 64 ]; then
    COLLAPSE_ID="$(printf '%s' "$COLLAPSE_ID" | cut -c1-31)-$(printf '%s\0%s' "$SERVER_NAME" "$EVENT_KEY" | sha1sum | cut -c1-32)"
  fi
fi

# Deep-link the notification into the Live Activity the monitor daemon is
# driving for the same job, when there is one. The slug prefix comes from the
# daemon's own state rather than being re-derived here: a second copy of
# slug_prefix() with nothing pinning the two together is how they drift.
ACTIVITY_SLUG=""
if [ -n "${EVENT:-}" ] && [ -r "$STATE_FILE" ]; then
  # shellcheck disable=SC2016
  ACTIVITY_SLUG="$(EVENT="$EVENT" STATE_FILE="$STATE_FILE" php -r '
    $state = json_decode((string) @file_get_contents(getenv("STATE_FILE")), true);
    $prefix = $state["_meta"]["prefix"] ?? "";
    if (!is_string($prefix) || $prefix === "") { exit(0); }
    $event = strtolower(trim((string) getenv("EVENT")));
    $suffix = "";
    if (str_starts_with($event, "unraid parity-check")
        || str_starts_with($event, "unraid parity-sync")
        || str_starts_with($event, "unraid data-rebuild")
        || str_starts_with($event, "unraid read-check")
        || str_starts_with($event, "unraid disk-clear")) {
        $suffix = "-array";
    } elseif (str_starts_with($event, "appdata backup")) {
        $suffix = "-backup";
    } elseif (str_starts_with($event, "unraid-vmbackup")) {
        $suffix = "-vmbackup";
    }
    // Only claim the slug when the activity is LIVE. A state key is never
    // removed - the idle branch writes active=false back, and vmbackup seeds its
    // key on a box that has never run one - so a mere isset() attaches a dead
    // slug to every parity/backup/vmbackup notification, 422s, and burns the
    // retry below on the common case instead of the rare race it exists for.
    $key = $prefix . $suffix;
    if ($suffix !== "" && !empty($state[$key]["active"])) { echo $key; }
  ' 2>/dev/null)"
fi

# Build the JSON with PHP (always present on Unraid) so titles/bodies with
# quotes, newlines or unicode are escaped correctly. The $-vars below are PHP,
# not shell, so single quotes are intentional, and the PHP must never use one.
#
# With an encryption key set, the title, subtitle, body and link go into a pw1
# envelope that only the user's devices can open, and the event name stays out
# of everything the server reads. When the key is unusable (exit 3) or sealing
# fails (exit 4) it still prints a content-free alert, so the phone hears that
# something happened without the details going out in the clear; build org
# prints the one an organization key gets (exit 5) when PushWard has not
# enabled encryption for organizations and refuses its encrypted sends. The
# first line printed is the event tag, the second the payload; display_errors
# goes to stderr because a PHP warning on stdout would corrupt both.
build() {  # [org]
  # shellcheck disable=SC2016
  SERVER_NAME="$SERVER_NAME" LEVEL="$LEVEL" COLLAPSE_ID="$COLLAPSE_ID" ACTIVITY_SLUG="$ACTIVITY_SLUG" \
  EVENT_KEY="$EVENT_KEY" E2E_KEY="$PUSHWARD_E2E_KEY" E2E_REFUSED="${1:-}" WEBUI_URL="$PUSHWARD_WEBUI_URL" \
  ACK="$ACK" ACK_REPEAT="$PUSHWARD_ACK_REPEAT" ACK_EXPIRE="$PUSHWARD_ACK_EXPIRE" \
  php -d display_errors=stderr -r '
  // pw-e2e begin
  // PushWard end-to-end encryption (envelope pw1), inline because this agent
  // runs from the flash drive and must not depend on the plugin directory. CI
  // extracts everything between the two markers and checks it against the
  // published test vectors.
  //   enc = HKDF-SHA256(key, salt empty, info "pushward/e2e/v1/enc", 32 bytes)
  //   kid = hex(HKDF-SHA256(key, salt empty, info "pushward/e2e/v1/kid", 4 bytes))
  //   "pw1." kid "." base64url-nopad(nonce || ciphertext || tag), AAD "pw1." kid
  // The largest plaintext whose envelope still fits in 3072 characters.
  const PW_E2E_MAX_PLAINTEXT = 2266;
  // The bytes json.dumps(ensure_ascii=False) writes in the Python reference:
  // raw UTF-8, raw slashes, raw line separators.
  const PW_E2E_JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR;

  // An hlk_/hla_ API key pasted by mistake is never 64 hex characters, so the
  // same check refuses it.
  function pw_e2e_key(string $text): string {
    $hex = preg_replace("/[ \t\n\r\f\v]+/", "", $text);
    if (!preg_match("/^[0-9a-fA-F]{64}\\z/", $hex)) {
      throw new InvalidArgumentException("not 64 hexadecimal characters");
    }
    return hex2bin($hex);
  }

  function pw_e2e_kid(string $key): string {
    return bin2hex(hash_hkdf("sha256", $key, 4, "pushward/e2e/v1/kid"));
  }

  // $nonce is for the test vectors only; a real send always draws a fresh one.
  function pw_e2e_seal_plaintext(string $key, string $plaintext, ?string $nonce = null): string {
    $kid = pw_e2e_kid($key);
    $nonce ??= random_bytes(12);
    $tag = "";
    $sealed = openssl_encrypt($plaintext, "aes-256-gcm", hash_hkdf("sha256", $key, 32, "pushward/e2e/v1/enc"),
      OPENSSL_RAW_DATA, $nonce, $tag, "pw1.$kid", 16);
    if ($sealed === false || strlen($tag) !== 16) {
      throw new RuntimeException("AES-256-GCM failed");
    }
    return "pw1.$kid." . rtrim(strtr(base64_encode($nonce . $sealed . $tag), "+/", "-_"), "=");
  }

  function pw_e2e_fields(string $title, string $body, string $subtitle, string $url): array {
    $f = ["title" => $title];
    if ($subtitle !== "") { $f["subtitle"] = $subtitle; }
    $f["body"] = $body;
    if ($url !== "") { $f["url"] = $url; }
    return $f;
  }

  // Code points, so no cut ever splits a UTF-8 sequence. $s must be valid UTF-8.
  function pw_e2e_chars(string $s): array {
    return $s === "" ? [] : preg_split("//u", $s, -1, PREG_SPLIT_NO_EMPTY);
  }

  // Drop code points from the end until their JSON bytes cover $excess; one always stays.
  function pw_e2e_trim(array $chars, int $excess): array {
    $end = count($chars);
    while ($excess > 0 && $end > 1) {
      $end--;
      $excess -= strlen(json_encode($chars[$end], PW_E2E_JSON)) - 2;
    }
    return [array_slice($chars, 0, $end), $excess];
  }

  // Title and body cut down, the body first, until the message fits one
  // envelope: a long Unraid message is shortened rather than refused. Same
  // rule as fit() in the other senders.
  function pw_e2e_fit(string $title, string $body, string $subtitle, string $url): array {
    $t = array_slice(pw_e2e_chars($title), 0, 256);
    $b = array_slice(pw_e2e_chars($body), 0, 4096);
    $full = json_encode(pw_e2e_fields(implode($t), implode($b), $subtitle, $url), PW_E2E_JSON);
    $excess = strlen($full) - PW_E2E_MAX_PLAINTEXT;
    if ($excess > 0) {
      [$b, $excess] = pw_e2e_trim($b, $excess);
      [$t] = pw_e2e_trim($t, $excess);
    }
    return [implode($t), implode($b)];
  }

  function pw_e2e_seal(string $key, string $title, string $body, string $subtitle = "", string $url = ""): string {
    [$title, $body] = pw_e2e_fit($title, $body, $subtitle, $url);
    $plaintext = json_encode(pw_e2e_fields($title, $body, $subtitle, $url), PW_E2E_JSON);
    // Spaces up to a multiple of 64 bytes hide the exact length, unless the
    // padding alone would push the envelope over its cap.
    $padded = str_pad($plaintext, intdiv(strlen($plaintext) + 63, 64) * 64);
    if (strlen($padded) <= PW_E2E_MAX_PLAINTEXT) {
      $plaintext = $padded;
    } elseif (strlen($plaintext) > PW_E2E_MAX_PLAINTEXT) {
      throw new LengthException("too long to encrypt");
    }
    return pw_e2e_seal_plaintext($key, $plaintext);
  }
  // pw-e2e end

  // An environment value as valid UTF-8. SUBSTITUTE so a non-UTF-8 byte in a
  // SMART/device string (common in Unraid alerts) can not make json_encode()
  // return false and post an empty body.
  function pw_env(string $name): string {
    $v = getenv($name);
    return ($v === false || $v === "") ? "" : (string) json_decode(json_encode($v, JSON_INVALID_UTF8_SUBSTITUTE));
  }

  // The link as an absolute http(s) URL, or "" to leave it out: the server
  // refuses a bare path, and a sealed link has to open from the phone. A web UI
  // path gets the base the monitor puts on its Open buttons: PUSHWARD_WEBUI_URL,
  // else NGINX_DEFAULTURL.
  function pw_link(): string {
    $abs = "#^https?://[^/\\s[:cntrl:]][^\\s[:cntrl:]]*\\z#i";
    $link = pw_env("LINK");
    if (preg_match($abs, $link)) { return $link; }
    if (!str_starts_with($link, "/") || str_starts_with($link, "//")) { return ""; }
    $base = trim(pw_env("WEBUI_URL"));
    if ($base === "") {
      $ini = @parse_ini_file("/var/local/emhttp/nginx.ini") ?: [];
      $base = trim((string) ($ini["NGINX_DEFAULTURL"] ?? ""));
    }
    $url = rtrim($base, "/") . $link;
    return preg_match($abs, $url) ? $url : "";
  }

  // One tag per event: the notice that an event is back to normal cancels the
  // repeats of its alert by it, and sealed sends use it as their collapse id. With a
  // usable key it is an HMAC under a key derived from it, so nobody can hash a
  // list of likely event names until one matches; without one, a plain sha1.
  // Firing and recovery derive it from the same inputs. "" without an event.
  function pw_event_tag(string $server, string $event, string $key): string {
    if ($event === "") { return ""; }
    try {
      $mac = hash_hmac("sha256", $server . "|" . $event, hash_hkdf("sha256", pw_e2e_key($key), 32, "pushward/unraid/v1/event-tag"));
    } catch (Throwable $e) {
      $mac = sha1($server . "|" . $event);
    }
    return "unraid-" . substr($mac, 0, 16);
  }

  function pw_seconds(string $name, int $default, int $min, int $max): int {
    $v = (string) getenv($name);
    return max($min, min($max, preg_match("/^[0-9]{1,9}\\z/", $v) ? (int) $v : $default));
  }

  $server = pw_env("SERVER_NAME");
  $key = (string) getenv("E2E_KEY");
  $eventTag = pw_event_tag((string) getenv("SERVER_NAME"), (string) getenv("EVENT_KEY"), $key);
  $title = pw_env("SUBJECT");
  if ($title === "") $title = pw_env("EVENT");
  if ($title === "") $title = "Unraid notification";
  $body = pw_env("CONTENT");
  if ($body === "") $body = pw_env("DESCRIPTION");
  if ($body === "") $body = $title;
  $subtitle = "Unraid · " . $server;
  $meta = array_filter([
    "importance" => pw_env("IMPORTANCE"),
    "event"      => pw_env("EVENT"),
    "server"     => $server,
    "timestamp"  => pw_env("TIMESTAMP"),
  ], fn($v) => $v !== "");
  $delivery = [
    "level"               => getenv("LEVEL"),
    "push"                => true,
    "source"              => "unraid",
    "source_display_name" => "Unraid",
    "thread_id"           => "unraid",
  ];
  // Both are omitted when empty rather than sent blank: an empty collapse_id
  // would make every unlabelled notification replace every other one, and an
  // empty activity_slug is simply not a link.
  $nonEmpty = fn(array $a) => array_filter($a, fn($v) => $v !== "");

  // One tag names the server, so one box can be silenced without touching
  // another; the event tag lets the recovery notice stop just this alert.
  // Tags are 1-64 printable ASCII characters.
  $ack = [];
  if (getenv("ACK") === "1") {
    $slug = trim(preg_replace("/[^a-z0-9]+/", "-", strtolower($server)), "-");
    $ack = [
      "acknowledge" => [
        "repeat_seconds" => pw_seconds("ACK_REPEAT", 300, 30, 3600),
        "expire_seconds" => pw_seconds("ACK_EXPIRE", 3600, 60, 10800),
      ],
      "tags" => array_values(array_filter([
        substr("unraid-" . ($slug !== "" ? $slug : substr(sha1($server), 0, 12)), 0, 64),
        $eventTag,
      ], fn($v) => $v !== "")),
    ];
  }

  $status = 0;
  if ($key === "") {
    $msg = ["title" => $title, "subtitle" => $subtitle, "body" => $body] + $delivery + [
      "url"      => pw_link(),
      "metadata" => (object) $meta,
    ] + $nonEmpty([
      "collapse_id"   => (string) getenv("COLLAPSE_ID"),
      "activity_slug" => (string) getenv("ACTIVITY_SLUG"),
    ]);
  } else {
    // The event name is the one detail outside the envelope, so it leaves the
    // metadata and the collapse id becomes the hash in the event tag.
    unset($meta["event"]);
    $collapse = $eventTag;
    try {
      if (getenv("E2E_REFUSED") === "org") {
        throw new DomainException("PushWard has not enabled encryption for organizations yet");
      }
      $sealKey = pw_e2e_key($key);
      $subtitle = implode(array_slice(pw_e2e_chars($subtitle), 0, 256));
      try {
        $sealed = pw_e2e_seal($sealKey, $title, $body, $subtitle, pw_link());
      } catch (LengthException $e) {
        // Only an outsized link can leave no room once title and body are cut.
        $sealed = pw_e2e_seal($sealKey, $title, $body, $subtitle);
      }
      $msg = ["encrypted" => $sealed] + $delivery + ["metadata" => (object) $meta]
        + $nonEmpty(["collapse_id" => $collapse, "activity_slug" => (string) getenv("ACTIVITY_SLUG")]);
    } catch (Throwable $e) {
      $status = match (true) {
        $e instanceof InvalidArgumentException => 3,
        $e instanceof DomainException => 5,
        default => 4,
      };
      $msg = [
        "title" => "Unraid alert on " . $server,
        "body"  => [
          3 => "Encryption key invalid, check the PushWard plugin settings",
          4 => "Encryption unavailable on this server, check the PushWard plugin settings",
          5 => "Encryption not enabled for organizations yet, check the PushWard plugin settings",
        ][$status],
      ] + $delivery + ["metadata" => (object) $meta] + $nonEmpty(["collapse_id" => $collapse]);
    }
  }
  $json = json_encode($msg + $ack, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($json === false) { fwrite(STDERR, json_last_error_msg()); exit(1); }
  echo $eventTag, "\n", $json;
  exit($status);
'
}
split_build() {  # $1 = what build printed -> sets EVENT_TAG and payload; fails when it is malformed
  EVENT_TAG="${1%%$'\n'*}"
  payload="${1#*$'\n'}"
  [[ $EVENT_TAG =~ ^(unraid-[0-9a-f]{16})?$ ]] && [ "$payload" != "$1" ] && [ -n "$payload" ]
}
out="$(build)"
built=$?

# Never POST an empty or garbled body (the server would 4xx and the alert would
# vanish).
if ! split_build "$out"; then
  logger -t pushward "notification payload could not be built; alert NOT sent (subject: ${SUBJECT:-?})"
  exit 0
fi
case "$built" in
  3) logger -t pushward "the encryption key in the PushWard settings is invalid; sent a content-free alert instead" ;;
  4) logger -t pushward "notification encryption failed (no AES-256-GCM in PHP?); sent a content-free alert instead" ;;
esac

# The response body says why a send was refused; kept in a scratch file.
RESP="$(mktemp 2>/dev/null)" || RESP=""
trap '[ -z "$RESP" ] || rm -f "$RESP"' EXIT

# Surface delivery failures to the system log instead of swallowing them, so a
# dropped alert (bad key, missing capability, 4xx/5xx, network) is diagnosable.
# Still exit 0 so the dynamix notification pipeline isn't disrupted.
post() {  # $1 = payload -> prints the HTTP status, or 000 when curl itself failed
  local code
  [ -z "$RESP" ] || : > "$RESP"
  code="$(curl -sS -m 15 -o "${RESP:-/dev/null}" -w '%{http_code}' -X POST "$URL/notifications" \
    -H "Authorization: Bearer $PUSHWARD_API_KEY" \
    -H "Content-Type: application/json" \
    -d "$1" 2>/dev/null)" || code="000"
  printf '%s' "${code:-000}"
}

resp_code() {  # -> the problem code of the last response, or nothing
  # shellcheck disable=SC2016
  php -r '$j = json_decode((string) @file_get_contents($argv[1]), true);
    $c = is_array($j) ? ($j["code"] ?? "") : "";
    echo is_string($c) ? preg_replace("/[^a-z0-9._]/", "", $c) : "";' -- "${RESP:-/dev/null}" 2>/dev/null
}

drop_keys() {  # $1 = payload, then keys -> the keys it dropped on one line, then the payload
  # shellcheck disable=SC2016
  printf '%s' "$1" | php -d display_errors=stderr -r '
    $j = json_decode((string) stream_get_contents(STDIN));
    if (!is_object($j)) { exit(1); }
    $gone = [];
    foreach (array_slice($argv, 1) as $k) {
      if (property_exists($j, $k)) { unset($j->$k); $gone[] = $k; }
    }
    if (!$gone) { exit(1); }
    echo implode(" ", $gone), "\n";
    echo json_encode($j, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  ' -- "${@:2}"
}

# A notice about an event that is not an alert (Unraid's "returned to normal")
# first stops that event's repeating alert: the repeats share its collapse id,
# so one landing after the notice would put the alert back on the Lock Screen.
# Best effort and 5 seconds at most each; the notice goes out whatever happens
# here. Never for a generic "Unraid Status" notice: it has no alert of its own.
#
# 2026.10.08 tagged alerts with a plain sha1 even with an encryption key, so an
# alert repeating since before the upgrade carries that tag; it is canceled too.
# Repeats expire within 3 hours, so a later release can drop the second call.
if [ "$PUSHWARD_ACK" = "1" ] && [ "$IS_ALERT" = "0" ] && [ "$GENERIC_EVENT" = "0" ] && [ -n "$EVENT_TAG" ]; then
  tags=("$EVENT_TAG")
  legacy="unraid-$(printf '%s|%s' "$SERVER_NAME" "$EVENT_KEY" | sha1sum | cut -c1-16)"
  [ "$legacy" = "$EVENT_TAG" ] || tags+=("$legacy")
  for tag in "${tags[@]}"; do
    canceled="$(curl -sS -m 5 -o /dev/null -w '%{http_code}' -X POST "$URL/notifications/receipts/cancel" \
      -H "Authorization: Bearer $PUSHWARD_API_KEY" \
      -H "Content-Type: application/json" \
      -d "{\"tag\":\"$tag\"}" 2>/dev/null)" || canceled="000"
    case "$canceled" in
      2*) ;;
      *) logger -t pushward "could not stop the repeats tagged $tag (HTTP ${canceled:-000}); sending the notice anyway" ;;
    esac
  done
fi

code="$(post "$payload")"

# Some refusals leave nothing created on the server, and resending without the
# part that was refused still gets the alert through:
#  - a 422 with a slug attached means the activity was swept between the
#    monitor's last write and this notification; a raced link must not
#    swallow a disk-failure alert. Only a 422 that names the slug or nothing.
#  - an acknowledge the server will not take (receipt limit reached, receipts
#    off, no answer URL, too large with the answer button, or rejected as
#    invalid) goes out once more as an ordinary notification.
#  - an organization key gets 422 encryption_unavailable while PushWard has
#    not enabled encryption for organizations; it gets the content-free alert
#    instead, minus whatever was dropped before.
# Each happens at most once. A 401/403/429/5xx or network failure is never
# resent: that would duplicate the alert or fail the same way again.
refused=0
for _ in 1 2 3; do
  case "$code" in 400 | 409 | 422) ;; *) break ;; esac
  rcode="$(resp_code)"
  if [ "$code:$rcode" = "422:notification.encryption_unavailable" ] && [ "$refused" = "0" ]; then
    refused=1
    logger -t pushward "PushWard has not enabled encrypted notifications for organization API keys yet; resending as a content-free alert (remove the encryption key or use a personal API key until it does)"
    out="$(build org)"
    split_build "$out" || break
    code="$(post "$payload")"
    continue
  fi
  drop=()
  if [ "$code" = "422" ] && { [ -z "$rcode" ] || [ "$rcode" = "notification.activity_not_found" ]; }; then
    drop+=(activity_slug)
  fi
  case "$code:$rcode" in
    409:notification_receipt.limit_exceeded | 422:notification_receipt.disabled | \
      422:notification.answer_url_unavailable | 422:notification.encrypted_too_large | \
      400:notification.invalid | 422:)
      drop+=(acknowledge tags) ;;
  esac
  [ "${#drop[@]}" -gt 0 ] || break
  out="$(drop_keys "$payload" "${drop[@]}")" || break
  case " ${out%%$'\n'*} " in
    *" activity_slug "*) logger -t pushward "notification rejected with a stale activity_slug ($ACTIVITY_SLUG); retrying without it" ;;
  esac
  case " ${out%%$'\n'*} " in
    *" acknowledge "*)
      ACK=""   # and a later rebuild leaves it out too
      logger -t pushward "acknowledge refused (HTTP $code${rcode:+ $rcode}); resending as an ordinary notification" ;;
  esac
  payload="${out#*$'\n'}"
  code="$(post "$payload")"
done

case "$code" in
  2*) ;;
  *)
    rcode="$(resp_code)"
    logger -t pushward "notification delivery to PushWard failed (HTTP $code${rcode:+ $rcode}; subject: ${SUBJECT:-?})" ;;
esac

exit 0
