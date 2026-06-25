#!/bin/bash
# Push a short demo Live Activity so the user can verify push-to-start works,
# straight from Settings -> PushWard.
MON="/usr/local/emhttp/plugins/pushward-unraid/pushward-monitor.php"
if [ ! -f "$MON" ]; then
  echo "PushWard monitor not installed at $MON" >&2
  exit 1
fi
php "$MON" test-activity
