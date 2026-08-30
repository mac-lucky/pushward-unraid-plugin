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

case "$(printf '%s' "${IMPORTANCE:-}" | tr '[:upper:]' '[:lower:]')" in
  alert) LEVEL="$ALERT_LEVEL" ;;
  warning) LEVEL="active" ;;
  *) LEVEL="passive" ;;
esac

case "${SUBJECT:-}" in
  "Alert ["*) [ "$LEVEL" = "passive" ] && LEVEL="$ALERT_LEVEL" ;;
  "Warning ["*) [ "$LEVEL" = "passive" ] && LEVEL="active" ;;
esac

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
COLLAPSE_ID=""
if [ -n "${EVENT:-}" ]; then
  slugify() { printf '%s' "$1" | tr '[:upper:]' '[:lower:]' | sed -e 's/[^a-z0-9]\{1,\}/-/g' -e 's/^-//' -e 's/-$//'; }
  COLLAPSE_ID="$(slugify "$SERVER_NAME-$EVENT")"
  # 64 is the server cap. Keep a readable prefix and make the tail collision-safe
  # rather than truncating two long events onto the same key.
  if [ "${#COLLAPSE_ID}" -gt 64 ]; then
    COLLAPSE_ID="$(printf '%s' "$COLLAPSE_ID" | cut -c1-31)-$(printf '%s\0%s' "$SERVER_NAME" "$EVENT" | sha1sum | cut -c1-32)"
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
# not shell, so single quotes are intentional.
# shellcheck disable=SC2016
payload="$(SERVER_NAME="$SERVER_NAME" LEVEL="$LEVEL" COLLAPSE_ID="$COLLAPSE_ID" ACTIVITY_SLUG="$ACTIVITY_SLUG" php -r '
  $title = getenv("SUBJECT");
  if ($title === false || $title === "") $title = getenv("EVENT");
  if ($title === false || $title === "") $title = "Unraid notification";
  $body = getenv("CONTENT");
  if ($body === false || $body === "") $body = getenv("DESCRIPTION");
  if ($body === false || $body === "") $body = $title;
  $meta = array_filter([
    "importance" => getenv("IMPORTANCE") ?: "",
    "event"      => getenv("EVENT") ?: "",
    "server"     => getenv("SERVER_NAME") ?: "",
    "timestamp"  => getenv("TIMESTAMP") ?: "",
  ], fn($v) => $v !== "");
  // SUBSTITUTE so a non-UTF-8 byte in a SMART/device string (common in Unraid
  // alerts) can not make json_encode() return false and post an empty body.
  $json = json_encode([
    "title"               => $title,
    "subtitle"            => "Unraid · " . getenv("SERVER_NAME"),
    "body"                => $body,
    "level"               => getenv("LEVEL"),
    "push"                => true,
    "source"              => "unraid",
    "source_display_name" => "Unraid",
    "thread_id"           => "unraid",
    "url"                 => getenv("LINK") ?: "",
    "metadata"            => (object) $meta,
  ] + array_filter([
    // Both are omitted when empty rather than sent blank: an empty collapse_id
    // would make every unlabelled notification replace every other one, and an
    // empty activity_slug is simply not a link.
    "collapse_id"   => getenv("COLLAPSE_ID") ?: "",
    "activity_slug" => getenv("ACTIVITY_SLUG") ?: "",
  ], fn($v) => $v !== ""), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($json === false) { fwrite(STDERR, json_last_error_msg()); exit(1); }
  echo $json;
')"

# Never POST an empty body (the server would 4xx and the alert would vanish).
if [ -z "$payload" ]; then
  logger -t pushward "notification payload could not be built; alert NOT sent (subject: ${SUBJECT:-?})"
  exit 0
fi

# Surface delivery failures to the system log instead of swallowing them, so a
# dropped alert (bad key, missing capability, 4xx/5xx, network) is diagnosable.
# Still exit 0 so the dynamix notification pipeline isn't disrupted.
post() {  # $1 = payload -> prints the HTTP status, or 000 when curl itself failed
  curl -sS -m 15 -o /dev/null -w '%{http_code}' -X POST "$URL/notifications" \
    -H "Authorization: Bearer $PUSHWARD_API_KEY" \
    -H "Content-Type: application/json" \
    -d "$1" 2>/dev/null || printf '000'
}

code="$(post "$payload")"

# A 422 with a slug attached means the activity was swept between the monitor's
# last write and this notification. Retry once WITHOUT the slug rather than let
# a raced link swallow a disk-failure alert - the server creates nothing on that
# rejection. Only 422, and only when a slug was actually sent: a 429 or 5xx must
# not be retried into a duplicate.
if [ "$code" = "422" ] && [ -n "$ACTIVITY_SLUG" ]; then
  logger -t pushward "notification rejected with a stale activity_slug ($ACTIVITY_SLUG); retrying without it"
  # shellcheck disable=SC2016
  retry="$(printf '%s' "$payload" | php -r '
    $j = json_decode((string) stream_get_contents(STDIN), true);
    if (!is_array($j)) { exit(1); }
    unset($j["activity_slug"]);
    echo json_encode($j, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  ')"
  if [ -n "$retry" ]; then
    code="$(post "$retry")"
  fi
fi

case "$code" in
  2*) ;;
  *) logger -t pushward "notification delivery to PushWard failed (HTTP $code; subject: ${SUBJECT:-?})" ;;
esac

exit 0
