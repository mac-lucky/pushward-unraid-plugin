set -e
CFG_DIR="/boot/config/plugins/pushward-unraid"
CFG_FILE="$CFG_DIR/pushward-unraid.cfg"
mkdir -p "$CFG_DIR"
mkdir -p /usr/local/emhttp/plugins/pushward-unraid/event
touch "$CFG_FILE"

# Idempotently ensure every config key exists; existing values are preserved so
# upgrades pick up the new Live Activity keys without clobbering the API key.
ensure() { grep -q "^$1=" "$CFG_FILE" || printf '%s=%s\n' "$1" "$2" >> "$CFG_FILE"; }
ensure PUSHWARD_URL '"https://api.pushward.app"'
ensure PUSHWARD_API_KEY '""'
ensure PUSHWARD_SERVER_NAME '"Unraid"'
ensure PUSHWARD_ACTIVITIES_ENABLED '"true"'
ensure PUSHWARD_TRACK_PARITY '"true"'
ensure PUSHWARD_TRACK_BACKUP '"true"'
ensure PUSHWARD_TRACK_MOVER '"true"'
ensure PUSHWARD_POLL_INTERVAL '"15"'
ensure PUSHWARD_ACTIVITY_PRIORITY '"5"'
chmod 600 "$CFG_FILE"
echo "PushWard config ready at $CFG_FILE"
