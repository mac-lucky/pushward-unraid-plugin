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

# Register the watchdog cron. update_cron reads a plugin's .cron only once Unraid
# has marked it installed in /var/log/plugins, and that happens AFTER these scripts
# run - so on a first install ours is invisible and the monitor never starts. Its
# glob matches the directory entry without stating the target, so a marker we stand
# up here works even before the .plg is copied to /boot. -e alone is false for a
# dangling symlink, hence -e and -L: only an empty path is ours to take back down.
# The cleanup must stay in a trap - a marker left behind by a signal dangles, and
# Unraid then refuses to install or update the plugin until the box reboots.
MARKER="/var/log/plugins/pushward-unraid.plg"
if [ ! -e "$MARKER" ] && [ ! -L "$MARKER" ]; then
  mkdir -p /var/log/plugins
  if ln -s /boot/config/plugins/pushward-unraid.plg "$MARKER" 2>/dev/null; then
    trap 'rm -f "$MARKER"' EXIT INT TERM HUP
  fi
fi
if [ -x /usr/local/sbin/update_cron ]; then
  /usr/local/sbin/update_cron >/dev/null 2>&1 || true
fi

# The banner below prints success either way, so record the real outcome. One
# predicate, checked once: the two call sites are far apart and must not disagree.
cron_ok() { grep -qF 'plugins/pushward-unraid/watchdog.sh' /etc/cron.d/root 2>/dev/null; }
if cron_ok; then
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

if ! cron_ok; then
  echo "WARNING: the watchdog cron is not registered, so Live Activities will not start."
  echo "Open Settings -> User Utilities -> PushWard and press Apply to fix it."
fi

# Always succeed: Unraid treats a non-zero Run block as a failed install and files
# the .plg under plugins-error, so nothing above may decide the exit status.
exit 0
