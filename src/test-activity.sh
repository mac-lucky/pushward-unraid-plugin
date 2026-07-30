#!/bin/bash
# Push a short demo Live Activity so the user can verify push-to-start works,
# straight from Settings -> PushWard.
MON="/usr/local/emhttp/plugins/pushward-unraid/pushward-monitor.php"
if [ ! -f "$MON" ]; then
  # Not >&2: Unraid runs this through popen($cmd,'r') and echoes only stdout into
  # the progress window, so a message on stderr would be lost.
  echo "PushWard monitor not installed at $MON. Reinstall the plugin."
  exit 1
fi
php "$MON" test-activity
