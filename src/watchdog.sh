#!/bin/bash
# PushWard monitor watchdog. Starts the daemon if it isn't already running.
# Invoked every minute by cron and by the array event hooks. The daemon itself
# holds an flock so a double-launch is harmless; pgrep just avoids the churn.

CFG="/boot/config/plugins/pushward-unraid/pushward-unraid.cfg"
MON="/usr/local/emhttp/plugins/pushward-unraid/pushward-monitor.php"

[ -f "$CFG" ] || exit 0

# Parse the config rather than sourcing it: the values are operator-entered free
# text and `. "$CFG"` would execute any $(...)/backticks in them every minute via
# cron. grep+cut never evaluates the value.
pw_cfg() {  # $1 = key -> value with surrounding quotes stripped
  local v
  v="$(grep -E "^$1=" "$CFG" 2>/dev/null | tail -n1 | cut -d= -f2-)"
  v="${v%\"}"; v="${v#\"}"
  printf '%s' "$v"
}
PUSHWARD_API_KEY="$(pw_cfg PUSHWARD_API_KEY)"
PUSHWARD_ACTIVITIES_ENABLED="$(pw_cfg PUSHWARD_ACTIVITIES_ENABLED)"

[ -n "${PUSHWARD_API_KEY:-}" ] || exit 0
[ "${PUSHWARD_ACTIVITIES_ENABLED:-true}" = "false" ] && exit 0
[ -f "$MON" ] || exit 0

if pgrep -f "pushward-monitor.php daemon" >/dev/null 2>&1; then
  exit 0
fi

mkdir -p /var/run/pushward
setsid php "$MON" daemon >/dev/null 2>&1 &
exit 0
