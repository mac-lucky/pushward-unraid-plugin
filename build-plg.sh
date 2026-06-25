#!/bin/bash
# Assemble pushward-unraid.plg from the verified src/ scripts so the embedded
# copies are byte-identical to what was tested on homeserver.
set -euo pipefail
SRC="$(cd "$(dirname "$0")/src" && pwd)"
OUT="${1:-$(dirname "$0")/pushward-unraid.plg}"
VERSION="2026.06.25b"

# Guard: a literal ]]> in any embedded file would break its CDATA section.
if grep -rlF ']]>' "$SRC" >/dev/null 2>&1; then
  echo "ERROR: a src file contains ]]> which breaks CDATA" >&2
  grep -rlF ']]>' "$SRC" >&2
  exit 1
fi

run_file() {  # comment, srcfile  -> a <FILE Run="/bin/bash"> block
  printf '<!-- %s -->\n<FILE Run="/bin/bash">\n<INLINE>\n<![CDATA[\n' "$1"
  cat "$SRC/$2"
  printf ']]>\n</INLINE>\n</FILE>\n\n'
}

named_file() {  # comment, dest, mode, srcfile -> a <FILE Name=...> block
  printf '<!-- %s -->\n<FILE Name="%s" Mode="%s">\n<INLINE>\n<![CDATA[' "$1" "$2" "$3"
  cat "$SRC/$4"
  printf ']]>\n</INLINE>\n</FILE>\n\n'
}

{
cat <<XMLHEAD
<?xml version='1.0' standalone='yes'?>

<!DOCTYPE PLUGIN [
<!ENTITY name      "pushward-unraid">
<!ENTITY author    "mac-lucky">
<!ENTITY version   "$VERSION">
<!ENTITY gitURL    "https://github.com/mac-lucky/pushward-unraid-plugin">
<!ENTITY pluginURL "&gitURL;/raw/main/pushward-unraid.plg">
<!ENTITY supportURL "&gitURL;/issues">
]>

<PLUGIN name="&name;"
        author="&author;"
        version="&version;"
        pluginURL="&pluginURL;"
        support="&supportURL;"
        icon="bell"
        min="6.12">

<CHANGES>
###$VERSION
- Add Live Activities: a background monitor pushes parity check / rebuild /
  clear (generic), appdata backup (log) and mover progress to your phone
- New "PushWard Activities" page to view current Live Activities and end them
- Settings: per-source toggles, poll interval, priority and live key/sub status
###2026.06.25
- Standalone rewrite as a native Unraid notification agent
- No daemon, no downloaded binary, no Unraid API key required
- Forwards every Unraid notification (including the full -m message body) to PushWard
- Settings -&gt; PushWard: configure server URL, API key and display name, send a test
</CHANGES>

<!--
PushWard Unraid Plugin

Forwards Unraid notifications to PushWard (https://pushward.app) as a native
dynamix notification agent, and drives PushWard Live Activities for long-running
operations (parity check/rebuild, appdata backup, mover) via a small background
monitor. Configure your API key at Settings -> PushWard; view Live Activities at
Settings -> PushWard Activities.

Plugin: https://github.com/mac-lucky/pushward-unraid-plugin
-->

XMLHEAD

run_file   "1. Seed/migrate persistent config (preserves existing values on upgrade)." install-config.sh
named_file "2. PushWard notification agent (dynamix invokes per notification)." "/boot/config/plugins/dynamix/notifications/agents/PushWard" "0755" agent-PushWard.sh
named_file "3. Test-notification helper (Settings page button)." "/usr/local/emhttp/plugins/pushward-unraid/test.sh" "0755" test.sh
named_file "4. Live Activity monitor daemon." "/usr/local/emhttp/plugins/pushward-unraid/pushward-monitor.php" "0755" pushward-monitor.php
named_file "5. Monitor watchdog (cron + array events start it)." "/usr/local/emhttp/plugins/pushward-unraid/watchdog.sh" "0755" watchdog.sh
named_file "6. Dashboard server-side proxy (keeps the key off the browser)." "/usr/local/emhttp/plugins/pushward-unraid/pwapi.php" "0644" pwapi.php
named_file "7. Test Live Activity helper (Settings page button)." "/usr/local/emhttp/plugins/pushward-unraid/test-activity.sh" "0755" test-activity.sh
named_file "8. Array event: start the monitor when disks mount." "/usr/local/emhttp/plugins/pushward-unraid/event/disks_mounted" "0755" event-disks_mounted.sh
named_file "9. Array event: stop monitor and end activities when unmounting." "/usr/local/emhttp/plugins/pushward-unraid/event/unmounting_disks" "0755" event-unmounting_disks.sh
named_file "10. Settings page (Settings -> PushWard)." "/usr/local/emhttp/plugins/pushward-unraid/pushward-unraid.page" "0644" pushward-unraid.page
named_file "11. Activities dashboard page (Settings -> PushWard Activities)." "/usr/local/emhttp/plugins/pushward-unraid/pushward-activities.page" "0644" pushward-activities.page
named_file "12. Watchdog cron." "/boot/config/plugins/pushward-unraid/pushward-unraid.cron" "0644" pushward-unraid.cron
run_file   "13. Post-install: register cron, start monitor, print summary." post-install.sh

printf '<!-- 14. Removal: stop monitor, end activities, drop agent/pages/cron; keep config. -->\n'
printf '<FILE Run="/bin/bash" Method="remove">\n<INLINE>\n<![CDATA[\n'
cat "$SRC/remove.sh"
printf ']]>\n</INLINE>\n</FILE>\n\n'

printf '</PLUGIN>\n'
} > "$OUT"

echo "Wrote $OUT"
