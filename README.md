# PushWard Unraid Plugin

> Beta: expect rough edges, and please report issues.

A self-contained Unraid plugin that connects your server to [PushWard](https://pushward.app). It does two things.

**Notifications.** Every Unraid notification is forwarded to your iPhone.

**Live Activities.** Long-running jobs (parity check/rebuild, appdata backup, mover, VM backup, UPS on battery) show real-time progress on the Lock Screen and Dynamic Island, with a dashboard page to view and end them.

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
| Live Activities | Master switch for progress activities |
| Track parity / appdata backup / mover / VM backup / UPS | Per-source toggles |
| Poll interval | How often the monitor checks state (default 15s) |
| Activity priority | 0-10, used when PushWard evicts to make room for higher-priority activities |

Click Apply, then use the test buttons to confirm each path. The Settings tab shows live status: whether the key is valid, whether the subscription is active, and whether the monitor is running.

### API key permissions

One `hlk_` key can carry both features:

- Notifications need the `notifications` capability.
- Live Activities need `activity:manage` scope and an active PushWard subscription.

Notifications work without a subscription; creating and updating Live Activities does not.

## What it does

### Notifications

The agent lives at `/boot/config/plugins/dynamix/notifications/agents/PushWard`. Unraid invokes it for every notification whose importance has the Agent method enabled (Warning and Alert by default; enable Info too under Settings > Notifications). `ALERT`/`WARNING` map to active push notifications, everything else to passive. The full message body is forwarded, not just the subject.

### Live Activities

A background monitor, started by an array-event hook and kept alive by a 1-minute watchdog cron, polls Unraid's state and drives PushWard activities:

| Operation | Source | Template | Shows |
|---|---|---|---|
| Parity check / rebuild / clear | `mdcmd status` | generic | percent, speed, ETA, error count |
| Appdata backup | `appdata.backup` log + state file | log | live log lines + container progress |
| Mover | mover flag + syslog move log + cache usage | log | files moved, percent, transfer speed |
| VM backup | `vmbackup` plugin log | log | live log lines + per-VM progress |
| UPS on battery | `apcaccess` (apcupsd) | generic | battery charge + runtime countdown |

Activities appear via push-to-start (no app interaction needed), update as the job runs, and end automatically when it finishes. View and end the current ones on the Activities tab of Settings > PushWard.

The monitor only reads status; it never touches Unraid or the source plugins. It also stays under PushWard's update quota by pushing only on a meaningful change.

A few notes on the newer sources. Mover lists the files as they move, read from the lines Unraid writes to syslog when "Mover logging" is on (Settings > Scheduler > Mover Settings); with that setting off there are no per-file lines and it shows a progress bar with bytes moved and speed instead. The percent is a fraction of the data the run will move: at the start the monitor measures, once, the size of each share set to move cache to array (Use cache = Yes) on its assigned pool, and uses that as the denominator. It reads only the cache pools, so the array stays spun down. Bytes moved come from how much the pools drain. Both are estimates, since mover skips open or in-use files and new writes refill the cache during a run, so the bar can stall short of full; the run finishing is what marks it complete. When nothing movable is found the bar is indeterminate. VM backup tracks the `vmbackup` plugin's own scheduled or manual run, and a backup launched through the User Scripts plugin under a custom name is not detected. The UPS source reads `apcaccess` and only appears while the UPS is on battery; NUT is not supported.

## Uninstall

Plugins > PushWard > Remove. The agent, monitor, settings/dashboard pages and cron are removed, and any active Live Activities are ended. Your config under `/boot/config/plugins/pushward-unraid/` stays put, so reinstalling restores your API key.
