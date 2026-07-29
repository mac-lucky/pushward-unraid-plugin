mkdir -p /var/run/pushward
# Restart the monitor so an upgrade picks up the new code (a long-running PHP
# process keeps the old file in memory). Wait for the old daemon to be fully
# gone (TERM grace then KILL) so it releases its flock before the watchdog
# launches the new one, since a still-alive old instance would keep the lock and the
# fresh daemon would exit immediately.
PAT="pushward-monitor[.]php daemon"
pkill -TERM -f "$PAT" 2>/dev/null || true
for _ in $(seq 1 30); do
  pgrep -f "$PAT" >/dev/null 2>&1 || break
  sleep 0.1
done
pkill -KILL -f "$PAT" 2>/dev/null || true

# Register the watchdog cron. update_cron reads the .cron files only of plugins it
# finds marked installed in /var/log/plugins, and Unraid creates that marker only
# AFTER these install scripts finish - so on a first install ours is invisible to
# it, the watchdog line never reaches /etc/cron.d/root, and nothing ever starts the
# monitor. Stand the marker up for the call, then drop it again so the plugin
# manager can create the real one itself. Its glob matches the directory entry and
# never stats the target, so this works even though the .plg has not been copied to
# /boot yet. Testing both -e and -L because -e alone is false for a dangling
# symlink: only a path with nothing on it is ours to create and take back down.
MARKER="/var/log/plugins/pushward-unraid.plg"
MADE_MARKER=""
if [ ! -e "$MARKER" ] && [ ! -L "$MARKER" ]; then
  mkdir -p /var/log/plugins
  if ln -s /boot/config/plugins/pushward-unraid.plg "$MARKER" 2>/dev/null; then
    MADE_MARKER=1
  fi
fi
if [ -x /usr/local/sbin/update_cron ]; then
  /usr/local/sbin/update_cron >/dev/null 2>&1 || true
fi
if [ -n "$MADE_MARKER" ]; then
  rm -f "$MARKER"
fi

# Record the outcome. Nothing else does: the banner below prints success either
# way, so without this a missing cron entry stays invisible until someone wonders
# why no Live Activity ever appeared.
if grep -q 'pushward-unraid/watchdog\.sh' /etc/cron.d/root 2>/dev/null; then
  logger -t pushward "watchdog cron registered"
else
  logger -t pushward "WARNING: watchdog cron not registered, the Live Activity monitor will not start"
fi

# Starts the monitor on an upgrade. On a first install the key is still empty, so
# the watchdog declines and the Apply button starts it once a key is entered.
/usr/local/emhttp/plugins/pushward-unraid/watchdog.sh >/dev/null 2>&1 || true

cat <<'EOF'

================================================================
  PushWard Unraid plugin installed.

  Find it under:  Settings -> User Utilities -> PushWard
  API key on the Settings tab; Live Activities on the Activities tab.

  Notifications use an integration key with the 'notifications'
  capability. Live Activities (parity, appdata backup, mover) need
  the same key to also have 'activity:manage' scope and an active
  subscription. The Settings page shows the live status.
================================================================

EOF

# Say so in the install window if the cron still did not land. Silence here is what
# let this ship broken in the first place; the Apply button repairs it.
if ! grep -q 'pushward-unraid/watchdog\.sh' /etc/cron.d/root 2>/dev/null; then
  echo "WARNING: the watchdog cron is not registered, so Live Activities will not start."
  echo "Open Settings -> User Utilities -> PushWard and press Apply to fix it."
fi

# Always succeed: Unraid treats a non-zero Run block as a failed install and files
# the .plg under plugins-error, so nothing above may decide the exit status.
exit 0
