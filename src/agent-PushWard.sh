#!/bin/bash
# PushWard notification agent for Unraid.
#
# Invoked by the dynamix notification system with the notification in the
# environment: EVENT, SUBJECT, DESCRIPTION, IMPORTANCE, CONTENT, LINK,
# TIMESTAMP. Posts them to the PushWard notification API. No daemon, no Unraid
# API key. Config: /boot/config/plugins/pushward-unraid/pushward-unraid.cfg

CFG="/boot/config/plugins/pushward-unraid/pushward-unraid.cfg"
[ -f "$CFG" ] || exit 0

# Read the config by parsing, NOT by sourcing it: the values are operator-entered
# free text (server name, URL), and `. "$CFG"` would execute any $(...)/backtick
# in them on every notification. grep+cut never evaluates the value.
pw_cfg() {  # $1 = key -> value with surrounding quotes stripped
  local v
  v="$(grep -E "^$1=" "$CFG" 2>/dev/null | tail -n1 | cut -d= -f2-)"
  v="${v%\"}"; v="${v#\"}"
  printf '%s' "$v"
}
PUSHWARD_API_KEY="$(pw_cfg PUSHWARD_API_KEY)"
PUSHWARD_URL="$(pw_cfg PUSHWARD_URL)"
PUSHWARD_SERVER_NAME="$(pw_cfg PUSHWARD_SERVER_NAME)"

# Nothing to send without an API key; exit cleanly so the notification pipeline
# isn't disrupted.
[ -n "${PUSHWARD_API_KEY:-}" ] || exit 0

URL="${PUSHWARD_URL:-https://api.pushward.app}"
SERVER_NAME="${PUSHWARD_SERVER_NAME:-Unraid}"

# Map Unraid importance (alert/warning/normal) to a PushWard interruption level.
case "$(printf '%s' "${IMPORTANCE:-}" | tr '[:upper:]' '[:lower:]')" in
  alert | warning) LEVEL="active" ;;
  *) LEVEL="passive" ;;
esac

# Build the JSON with PHP (always present on Unraid) so titles/bodies with
# quotes, newlines or unicode are escaped correctly. The $-vars below are PHP,
# not shell, so single quotes are intentional.
# shellcheck disable=SC2016
payload="$(SERVER_NAME="$SERVER_NAME" LEVEL="$LEVEL" php -r '
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
  ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
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
curl -fsS -m 15 -X POST "$URL/notifications" \
  -H "Authorization: Bearer $PUSHWARD_API_KEY" \
  -H "Content-Type: application/json" \
  -d "$payload" >/dev/null 2>&1
rc=$?
if [ "$rc" -ne 0 ]; then
  logger -t pushward "notification delivery to PushWard failed (curl exit $rc; subject: ${SUBJECT:-?})"
fi

exit 0
