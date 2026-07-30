#!/bin/bash
# Backs both the Apply and the Start monitor buttons on Settings -> PushWard.
# update.php writes #file before it runs #command, so on Apply the values the user
# just saved are already on disk here.
#
# Two idempotent jobs, which is why one script serves both buttons:
#   1. Register the watchdog cron. A fresh install can end up with no watchdog line
#      at all (see post-install.sh); by the time a button runs, the plugin is marked
#      installed in /var/log/plugins, so update_cron always merges it - which also
#      repairs a box installed before this was fixed. Unconditional on purpose: a
#      grep for the line proves only that some line mentions the path, so it would
#      miss a schedule change shipped in an upgrade.
#   2. Start the monitor if it is not already running.
#
# It never kills a running daemon. The daemon re-reads the config on every poll
# and adopts the new values itself, and when Live Activities are switched off it
# ends its activities and exits on its own. Killing it would interrupt whatever it
# is tracking, and killing it on Disabled would leave the cards frozen on the
# phone until they go stale.
#
# Everything goes to stdout: Unraid runs this through popen($cmd,'r') and echoes
# only stdout into the progress window, so a message on stderr would be lost.
CFG="/boot/config/plugins/pushward-unraid/pushward-unraid.cfg"
WATCHDOG="/usr/local/emhttp/plugins/pushward-unraid/watchdog.sh"
PAT="pushward-monitor[.]php daemon"

# Parse the config rather than sourcing it: the values are operator-entered free
# text and `. "$CFG"` would execute any $(...) in them as root. Same pw_cfg as
# watchdog.sh and agent-PushWard.sh carry, for the same reason.
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

# Deliberately pgrep and not a non-blocking flock on the monitor's lock file, even
# though that would be cheaper and a truer signal: the daemon exits silently when it
# cannot take that lock, so a probe landing between its exec and its flock() would
# acquire the lock and kill the daemon it was checking on.
monitor_pid() { pgrep -f "$PAT" | head -n1; }

if [ -x /usr/local/sbin/update_cron ]; then
  /usr/local/sbin/update_cron >/dev/null 2>&1 || true
fi
if grep -qF 'plugins/pushward-unraid/watchdog.sh' /etc/cron.d/root 2>/dev/null; then
  echo "Watchdog cron registered; it rechecks the monitor every minute."
else
  echo "Warning: the watchdog cron did not register, so the monitor will not restart by itself."
fi

if [ -z "$(pw_cfg PUSHWARD_API_KEY)" ]; then
  echo "No API key set yet, so the monitor has nothing to authenticate with."
  echo "Enter a key on the Settings tab and press Apply."
  exit 0
fi

if [ "$(pw_cfg PUSHWARD_ACTIVITIES_ENABLED)" = "false" ]; then
  if [ -n "$(monitor_pid)" ]; then
    echo "Live Activities are Disabled; the monitor ends its activities and exits on its next poll."
  else
    echo "Live Activities are Disabled; the monitor stays stopped."
  fi
  exit 0
fi

PID="$(monitor_pid)"
if [ -n "$PID" ]; then
  echo "Live Activity monitor already running (pid $PID)."
  exit 0
fi

if [ ! -x "$WATCHDOG" ]; then
  echo "PushWard watchdog is missing from $WATCHDOG. Reinstall the plugin."
  exit 0
fi

# Launch through the watchdog rather than php directly: it owns the setsid and the
# /var/run/pushward setup, so one place knows how to start the daemon.
"$WATCHDOG" >/dev/null 2>&1 || true
# Wait briefly so what is printed matches what the page shows on reload. The daemon
# is normally visible on the first probe, since pgrep matches the argv set at exec.
for _ in $(seq 1 10); do
  PID="$(monitor_pid)"
  if [ -n "$PID" ]; then
    break
  fi
  sleep 0.2
done

if [ -n "$PID" ]; then
  echo "Live Activity monitor started (pid $PID)."
else
  echo "Live Activity monitor did not start. Check /var/log/pushward-monitor.log"
fi
exit 0
