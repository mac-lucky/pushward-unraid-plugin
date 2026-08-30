# PushWard Unraid Plugin

> Beta: expect rough edges, and please report issues.

A self-contained Unraid plugin that connects your server to [PushWard](https://pushward.app). It does three things.

**Notifications.** Every Unraid notification is forwarded to your iPhone.

**Live Activities.** Long-running jobs (parity check/rebuild, appdata backup, mover, VM backup, UPS on battery) show real-time progress on the Lock Screen and Dynamic Island, with a dashboard page to view and end them.

**Home Screen widgets.** Optional: array fill, free space per disk and pool, and a status list. Off by default.

It installs a native notification agent plus a small monitor that reads Unraid's own state. There is no downloaded binary and no Unraid API key to manage; everything talks to the PushWard REST API over HTTPS.

## Install

In the Unraid web UI, open Plugins > Install Plugin and paste:

```
https://github.com/mac-lucky/pushward-unraid-plugin/raw/main/pushward-unraid.plg
```

Needs Unraid 6.12 or later.

## Configure

Open Settings > PushWard (in the User Utilities row) and, on the Settings tab, fill in:

| Field | What it is |
|---|---|
| PushWard server URL | Defaults to `https://api.pushward.app` |
| PushWard API key | An `hlk_...` key (permissions below) |
| Server display name | Shown under notifications and used to name activities, e.g. "Tower" |
| Alert notification level | Which PushWard level an Unraid Alert becomes: Normal, Time Sensitive (default) or Critical |
| Live Activities | Master switch for progress activities |
| Track parity / appdata backup / mover / VM backup / UPS | Per-source toggles |
| Poll interval | How often the monitor checks state (default 15s) |
| Activity priority | 0-10, used when PushWard evicts to make room for higher-priority activities |
| Unraid web UI URL | Where the Open button on an activity points. Blank auto-detects the same address Unraid puts in its own alert emails |
| Home Screen widgets | Off by default; the key needs an extra permission (below) |
| Widget refresh interval | How often the widgets are recomputed (default 300s) |

Click Apply, then use the test buttons to confirm each path. The Settings tab shows live status: whether the key is valid, whether the subscription is active, and whether the monitor is running.

### API key permissions

One `hlk_` key can carry all three:

- Notifications need the `notifications` capability.
- Live Activities need `activity:manage` scope and an active PushWard subscription.
- Home Screen widgets need the `widgets` capability. Without it the widget calls come back 403 and the log says so; nothing else is affected.

Notifications work without a subscription; creating and updating Live Activities does not.

## What it does

### Notifications

The agent lives at `/boot/config/plugins/dynamix/notifications/agents/PushWard`. Unraid invokes it for every notification whose importance has the Agent method enabled (Warning and Alert by default; enable Info too under Settings > Notifications). The full message body is forwarded, not just the subject.

Unraid has three importances. An `ALERT` becomes whatever "Alert notification level" is set to, Time Sensitive out of the box; a `WARNING` becomes a Normal push; anything else stays passive. A subject starting `Alert [` or `Warning [` raises the level to match whatever importance it arrived with, and never lowers it - Unraid sends a parity check that finished *with* errors as `Notice [...]` at Warning importance. Critical is the level that breaks through Do Not Disturb and the silent switch, so it is opt-in, and it needs the critical-alert permission granted to the PushWard app.

Repeats of the same event replace the previous banner rather than stacking, keyed on the server name and the event, so a SMART warning that repeats every hour leaves one banner. The app's notification list still holds every one of them. A notification about a job the monitor is already tracking carries a link to that Live Activity.

### Live Activities

A background monitor, started by an array-event hook and kept alive by a 1-minute watchdog cron, polls Unraid's state and drives PushWard activities:

| Operation | Source | Template | Shows |
|---|---|---|---|
| Parity check / rebuild / clear | `mdcmd status` | generic | percent, speed, ETA, error count |
| Appdata backup | `appdata.backup` log + state file | steps | per-container step progress |
| Mover | mover flag + syslog move log + cache usage | log | files moved, percent, transfer speed |
| VM backup | `vmbackup` plugin log | steps | per-VM step progress, sized by vdisk (needs a VM list in vmbackup; "all" shows an indeterminate bar) |
| UPS on battery | `apcaccess` (apcupsd) | generic | battery charge + runtime countdown |

Activities appear via push-to-start (no app interaction needed), update as the job runs, and end automatically when it finishes. Each card carries an Open button that goes to the matching page in the web UI. View and end the current ones on the Activities tab of Settings > PushWard.

The monitor only reads status; it never touches Unraid or the source plugins. It also stays under PushWard's update quota by pushing only on a meaningful change.

A few notes on the newer sources. Mover lists the files as they move, read from the lines Unraid writes to syslog when "Mover logging" is on (Settings > Scheduler > Mover Settings); with that setting off there are no per-file lines and it shows a progress bar with bytes moved and speed instead. The percent is a fraction of the data the run will move: at the start the monitor measures, once, the size of each share set to move cache to array (Use cache = Yes) on its assigned pool, and uses that as the denominator. It reads only the cache pools, so the array stays spun down. Bytes moved come from how much the pools drain. Both are estimates, since mover skips open or in-use files and new writes refill the cache during a run, so the bar can stall short of full; the run finishing is what marks it complete. When nothing movable is found the bar is indeterminate. VM backup tracks the `vmbackup` plugin's own scheduled or manual run, and a backup launched through the User Scripts plugin under a custom name is not detected. The UPS source reads `apcaccess` and only appears while the UPS is on battery; NUT is not supported.

### Home Screen widgets

Off by default, because they need an extra permission on your key (see above) and they occupy space in your iPhone's widget picker whether or not you place them. Switch them on under Settings and the monitor publishes three:

| Widget | Template | Shows |
|---|---|---|
| `<server>-array-fill` | gauge | How full the array is, with used and total underneath |
| `<server>-storage` | battery | A ring per mounted disk and pool, plus the UPS, showing free space rather than used. A disk with a SMART fault or a read error goes red whatever its level. Eight rings is the server's limit, so a box with more devices than that shows the unhealthy ones first, then whichever have least room left |
| `<server>-status` | stat_list | Array state, used and free, the last parity result with how long ago it ran, mover, and how many disks are healthy |

Everything comes from the status files Unraid already keeps up to date, so a refresh never spins a disk up to read it. Widgets refresh on their own interval (default 300s) rather than the activity poll, and re-send unchanged content twice an hour so iOS does not grey them out as stale; the server counts that as a touch, not a push, so it costs nothing against your quota. Switching them back off removes them from your account.

## Uninstall

Plugins > PushWard > Remove. The agent, monitor, settings/dashboard pages and cron are removed, any active Live Activities are ended, and any published widgets are deleted. Your config under `/boot/config/plugins/pushward-unraid/` stays put, so reinstalling restores your API key.
