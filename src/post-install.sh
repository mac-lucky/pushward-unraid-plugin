mkdir -p /var/run/pushward
# Restart the monitor so an upgrade picks up the new code (a long-running PHP
# process keeps the old file in memory). Wait for the old daemon to be fully
# gone (TERM grace then KILL) so it releases its flock before the watchdog
# launches the new one, since a still-alive old instance would keep the lock and the
# fresh daemon would exit immediately. Then register the watchdog cron.
PAT="pushward-monitor.php daemon"
pkill -TERM -f "$PAT" 2>/dev/null || true
for _ in $(seq 1 30); do
  pgrep -f "$PAT" >/dev/null 2>&1 || break
  sleep 0.1
done
pkill -KILL -f "$PAT" 2>/dev/null || true
/usr/local/sbin/update_cron >/dev/null 2>&1 || true
/usr/local/emhttp/plugins/pushward-unraid/watchdog.sh >/dev/null 2>&1 || true

cat <<EOF

================================================================
  PushWard Unraid plugin installed.

  Configure your API key at:  Settings -> PushWard
  Live Activities dashboard:  Settings -> PushWard Activities

  Notifications use an integration key with the 'notifications'
  capability. Live Activities (parity, appdata backup, mover) need
  the same key to also have 'activity:manage' scope and an active
  subscription. The Settings page shows the live status.
================================================================

EOF
