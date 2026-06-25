#!/bin/bash
# Array is stopping: stop the monitor and end any active Live Activities so the
# user isn't left with a frozen "Parity 50%" card after the array goes down.

PAT="pushward-monitor.php daemon"

# Stop the daemon and WAIT for it to actually be gone before ending activities.
# Otherwise its in-flight tick can re-PATCH an activity back to 'ongoing' after
# end-all sets it 'ended', and its later state.json write (active=true) clobbers
# end-all's, leaving the exact frozen card this hook exists to clear. A short
# TERM grace then SIGKILL guarantees no competing writer when end-all runs.
pkill -TERM -f "$PAT" >/dev/null 2>&1
for _ in $(seq 1 30); do
  pgrep -f "$PAT" >/dev/null 2>&1 || break
  sleep 0.1
done
pkill -KILL -f "$PAT" >/dev/null 2>&1

php /usr/local/emhttp/plugins/pushward-unraid/pushward-monitor.php end-all >/dev/null 2>&1 &
exit 0
