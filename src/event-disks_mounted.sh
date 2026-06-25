#!/bin/bash
# Array/disks came up: make sure the PushWard monitor daemon is running.
/usr/local/emhttp/plugins/pushward-unraid/watchdog.sh >/dev/null 2>&1 &
exit 0
