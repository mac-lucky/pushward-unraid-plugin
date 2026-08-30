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
ensure PUSHWARD_TRACK_VMBACKUP '"true"'
ensure PUSHWARD_TRACK_UPS '"true"'
ensure PUSHWARD_POLL_INTERVAL '"15"'
ensure PUSHWARD_ACTIVITY_PRIORITY '"5"'
# Which interruption level an Unraid "alert" importance maps to. Not critical by
# default: that level bypasses Do Not Disturb and the ringer switch, and Unraid's
# alert tier is not curated for 3am - "disk is low on space" and "disk in error
# state" are both alerts.
ensure PUSHWARD_ALERT_LEVEL '"time-sensitive"'
# Blank means auto: NGINX_DEFAULTURL from nginx.ini, the same value Unraid's own
# notify script builds its email links from.
ensure PUSHWARD_WEBUI_URL '""'
# Widgets default OFF. The routes need the `widgets` capability on the key and
# the setup instructions have only ever asked for notifications + activity:manage,
# so defaulting on would 403-loop every installed box on the next auto-update;
# PATCH /widgets also spends the account's widget-update quota, and three
# uninvited entries in someone's iOS widget picker is not ours to add.
ensure PUSHWARD_WIDGETS_ENABLED '"false"'
ensure PUSHWARD_WIDGET_INTERVAL '"300"'
chmod 600 "$CFG_FILE"
echo "PushWard config ready at $CFG_FILE"
