#!/bin/bash
# Fire a sample notification straight through the PushWard agent so the user can
# verify their config without waiting for a real Unraid event.
AGENT="/boot/config/plugins/dynamix/notifications/agents/PushWard"
if [ ! -f "$AGENT" ]; then
  # Not >&2: Unraid runs this through popen($cmd,'r') and echoes only stdout into
  # the progress window, so a message on stderr would be lost.
  echo "PushWard agent not installed at $AGENT. Reinstall the plugin."
  exit 1
fi
EVENT="Test" \
SUBJECT="PushWard test notification" \
DESCRIPTION="Test from Settings -> PushWard" \
CONTENT="If this reached your device, the PushWard agent is working." \
IMPORTANCE="alert" \
LINK="https://pushward.app" \
TIMESTAMP="$(date +%s)" \
bash "$AGENT"
echo "Test notification sent to PushWard. Check your device."
