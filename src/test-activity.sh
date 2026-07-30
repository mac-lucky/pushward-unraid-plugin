#!/bin/bash
# Push a short demo Live Activity so the user can verify push-to-start works,
# straight from Settings -> PushWard.
MON="/usr/local/emhttp/plugins/pushward-unraid/pushward-monitor.php"
if [ ! -f "$MON" ]; then
  # Not >&2: update.php shows only stdout (see CLAUDE.md).
  echo "PushWard monitor not installed at $MON. Reinstall the plugin."
  exit 1
fi
php "$MON" test-activity
