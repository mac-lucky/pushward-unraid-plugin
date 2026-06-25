echo "Uninstalling pushward-unraid plugin..."
# Stop the monitor and end any active Live Activities before removing its code.
# Wait for the daemon to be fully gone (TERM grace then KILL) so it can't re-PATCH
# an activity back to 'ongoing' after end-all ends it.
PAT="pushward-monitor.php daemon"
pkill -TERM -f "$PAT" 2>/dev/null || true
for _ in $(seq 1 30); do
  pgrep -f "$PAT" >/dev/null 2>&1 || break
  sleep 0.1
done
pkill -KILL -f "$PAT" 2>/dev/null || true
php /usr/local/emhttp/plugins/pushward-unraid/pushward-monitor.php end-all >/dev/null 2>&1 || true
rm -f /boot/config/plugins/dynamix/notifications/agents/PushWard
rm -f /boot/config/plugins/dynamix/notifications/agents-disabled/PushWard
rm -f /boot/config/plugins/pushward-unraid/pushward-unraid.cron
/usr/local/sbin/update_cron >/dev/null 2>&1 || true
rm -rf /usr/local/emhttp/plugins/pushward-unraid
rm -rf /var/run/pushward
echo "Removed the agent, monitor, settings/dashboard pages and cron."
echo "Persistent config under /boot/config/plugins/pushward-unraid/ left intact."
