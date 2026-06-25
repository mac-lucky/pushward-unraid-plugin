#!/bin/bash
# Assemble pushward-unraid.plg from the verified src/ scripts so the embedded
# copies are byte-identical to what was tested on homeserver.
set -euo pipefail
SRC="$(cd "$(dirname "$0")/src" && pwd)"
OUT="${1:-$(dirname "$0")/pushward-unraid.plg}"
VERSION="2026.06.25d"

# Guard: a literal ]]> in any embedded TEXT file would break its CDATA section.
# Skip *.png: the icon is base64-encoded (icon_file), never embedded raw, so a
# stray ]]> byte in the binary is harmless and must not fail the build.
if grep -rlF ']]>' "$SRC" --exclude='*.png' >/dev/null 2>&1; then
  echo "ERROR: a src file contains ]]> which breaks CDATA" >&2
  grep -rlF ']]>' "$SRC" --exclude='*.png' >&2
  exit 1
fi

run_file() {  # comment, srcfile  -> a <FILE Run="/bin/bash"> block
  printf '<!-- %s -->\n<FILE Run="/bin/bash">\n<INLINE>\n<![CDATA[\n' "$1"
  cat "$SRC/$2"
  printf ']]>\n</INLINE>\n</FILE>\n\n'
}

# md5 of trim(content)+"\n": Unraid writes a <FILE Name> INLINE payload as
# trim($INLINE).PHP_EOL and stores it on disk, then on a later install REPLACES the
# file only if the block carries an <MD5>/<SHA256> that differs from what's there.
# Without a hash it skips any file that already exists, so upgrades never update it.
# PHP trim() strips [ \t\n\r\0\x0B]; replicate that, append one \n, then md5.
md5_inline() {  # srcfile (absolute) -> 32-char lowercase md5 hex
  perl -0777 -pe 's/\A[ \t\n\r\x00\x0B]+//; s/[ \t\n\r\x00\x0B]+\z//' "$1" \
    | { cat; printf '\n'; } | openssl dgst -md5 | sed 's/^.*= //'
}

named_file() {  # comment, dest, mode, srcfile -> a <FILE Name=...> block
  # <MD5> sits AFTER </INLINE> on purpose: Unraid reads it position-independently,
  # but the CI extractor regex expects <INLINE> right after the open tag, so the
  # hash must stay outside the INLINE/CDATA it scans.
  printf '<!-- %s -->\n<FILE Name="%s" Mode="%s">\n<INLINE>\n<![CDATA[' "$1" "$2" "$3"
  cat "$SRC/$4"
  printf ']]>\n</INLINE>\n<MD5>%s</MD5>\n</FILE>\n\n' "$(md5_inline "$SRC/$4")"
}

icon_file() {  # comment, dest, srcpng -> a <FILE Run> that base64-decodes a binary
  # Binaries can't live in CDATA (PNG bytes aren't valid XML text), so ship the
  # icon as base64 and decode it on install. Strip newlines so the output is
  # identical on macOS (build host) and Linux (CI), which differ in base64 line
  # wrapping; the CI drift check rebuilds from src/ and compares byte-for-byte.
  printf '<!-- %s -->\n<FILE Run="/bin/bash">\n<INLINE>\n<![CDATA[\n' "$1"
  printf 'mkdir -p "%s"\n' "$(dirname "$2")"
  printf 'base64 -d > "%s" <<'\''PWICONB64'\''\n' "$2"
  base64 < "$SRC/$3" | tr -d '\n'; printf '\n'
  printf 'PWICONB64\nchmod 0644 "%s"\n]]>\n</INLINE>\n</FILE>\n\n' "$2"
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
- Mover Live Activity now lists the files as they move (when Mover logging is enabled) with a progress percent sized from the cache-to-array shares' footprint on their assigned pool, plus bytes moved and transfer speed; shows a percent/bytes bar without the file list when Mover logging is off
- Add VM Backup Live Activity (vmbackup plugin) with streaming log lines
- Add UPS on-battery Live Activity: battery charge and runtime countdown (apcupsd)
- Settings: new toggles for VM backup and UPS tracking
###2026.06.25c
- Move PushWard to the User Utilities row in Settings
- Merge the Settings and Activities pages into one entry with two tabs
- Use the PushWard icon instead of the generic bell
- Replace changed plugin files on upgrade (previously only new files were added)
###2026.06.25b
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
operations (parity check/rebuild, appdata backup, mover, VM backup, UPS on
battery) via a small background monitor. Find it under Settings -> User Utilities
-> PushWard: configure your API
key on the Settings tab and view Live Activities on the Activities tab.

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
named_file "10. Tab container (Settings -> User Utilities -> PushWard)." "/usr/local/emhttp/plugins/pushward-unraid/pushward-unraid.page" "0644" pushward-unraid.page
named_file "10a. Settings tab page." "/usr/local/emhttp/plugins/pushward-unraid/pushward-settings.page" "0644" pushward-settings.page
named_file "11. Activities tab page (live dashboard)." "/usr/local/emhttp/plugins/pushward-unraid/pushward-activities.page" "0644" pushward-activities.page
icon_file  "11a. Plugin icon (PushWard mark), base64-decoded on install." "/usr/local/emhttp/plugins/pushward-unraid/pushward.png" pushward.png
named_file "12. Watchdog cron." "/boot/config/plugins/pushward-unraid/pushward-unraid.cron" "0644" pushward-unraid.cron
run_file   "13. Post-install: register cron, start monitor, print summary." post-install.sh

printf '<!-- 14. Removal: stop monitor, end activities, drop agent/pages/cron; keep config. -->\n'
printf '<FILE Run="/bin/bash" Method="remove">\n<INLINE>\n<![CDATA[\n'
cat "$SRC/remove.sh"
printf ']]>\n</INLINE>\n</FILE>\n\n'

printf '</PLUGIN>\n'
} > "$OUT"

echo "Wrote $OUT"
