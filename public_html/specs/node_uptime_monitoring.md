# Node Uptime Monitoring Spec

A minimal uptime check folded into the existing **Server Manager** plugin. Watches the public URL of each managed node on a schedule and emails an admin when a node goes down or comes back up. Nothing else.

This is the slimmest viable alternative to `specs/uptime_monitor.md`. No history, no charts, no status page, no contacts CRUD. If those become needed later, that's the trigger to graduate to the standalone plugin.

## Scope

**In (v1):**
- Per-node HTTP check, with two interchangeable check types: authenticated API probe (reuses existing `fetch_status_via_api`) and plain HTTP status check
- Per-node on/off toggle and check-type selector
- Email alert when a node goes down (after N consecutive failures) and when it recovers

**Out:**
- Any kind of history, charts, or uptime %
- Per-node tuning of interval/timeout/threshold/cooldown (those are constants for v1)
- Public status page, contacts CRUD, SMS, on-call schedules
- TCP/ping checks, multi-region, SSL expiry, maintenance windows
- Arbitrary (non-node) URL checks

## Check types

The scheduled task dispatches on `mgn_uptime_check_type` (default `'api'`):

| Value | Behavior |
|-------|----------|
| `api` | Reuses `JobCommandBuilder::fetch_status_via_api($node)`. Already writes `mgn_last_status_check` and `mgn_last_status_data`. For uptime interpretation, only `reason='transport'` (DNS/connect/timeout failure) counts as down — any HTTP response from the node, including `auth` (401/403), `status` (non-200), or `body` (unexpected payload), proves the site is reachable. `reason='config'` (missing API keys) is a misconfiguration, not an uptime signal: log a warning and skip the state update so a missing key doesn't fire a false down alert. |
| `http_status` | Plain curl GET to `mgn_site_url` with the configured timeout, follow redirects. Success = HTTP status in 2xx or 3xx. Also writes `mgn_last_status_check` so the dashboard stays consistent. |

**Interaction with `mgn_skip_joinery_checks`:** when that existing field is true, the node isn't a Joinery install and the `api` check is invalid. Handle it in two places:

- **UI:** in the node edit form, hide the `api` option from the check-type select when `mgn_skip_joinery_checks` is true (use FormWriter `visibility_rules`).
- **Runtime safety net:** in `RunNodeUptimeChecks`, if `mgn_skip_joinery_checks` is true, treat the check type as `http_status` regardless of what's stored — guards against the field being toggled on after the check type was already chosen.

Future check types (keyword match, custom header, TCP probe) plug into the same enum: add a method, add a `case`. No schema change required.

## Data model

**Augment `mgn_managed_nodes`** only — no new tables:

| Field | Purpose |
|-------|---------|
| `mgn_uptime_enabled` (bool, default true) | Per-node on/off |
| `mgn_uptime_check_type` (varchar(20), default `'api'`) | Which check method to use (`api` or `http_status`) |
| `mgn_uptime_last_status` (varchar(20)) | Live: `'up'` / `'down'` / `null` (never checked) |
| `mgn_uptime_consecutive_failures` (int4, default 0) | Live: streak counter for threshold logic |
| `mgn_uptime_down_since` (timestamp(6)) | Live: when current outage started (null when up); used for duration in recovery email |

"Last checked at" reuses the existing `mgn_last_status_check` — both code paths update it.

## Plugin settings

None. The alert email is resolved per-tick by walking this fallback chain:

1. `server_manager_provisioning_admin_alert_email` (existing Server Manager setting — most specific to admin alerts in this plugin)
2. `webmaster_email` (existing core site-wide contact, already in `settings.json`)
3. The first permission-10 user's email

If none of those resolve, log a warning to the error log and skip sending; the state machine still updates so we don't re-fire on every tick.

Everything else is hardcoded as class constants on `RunNodeUptimeChecks`:

```php
const CHECK_INTERVAL_SECONDS = 300;  // every 5 minutes
const TIMEOUT_SECONDS        = 10;
const FAILURE_THRESHOLD      = 2;    // consecutive failures before alerting
```

Promoting any of these to a setting later is a 5-minute change if it ever becomes worth it.

## Check runner

New scheduled task `plugins/server_manager/tasks/RunNodeUptimeChecks.php`, runs every 60 seconds via the existing scheduled-task runner.

On each tick:

1. Select nodes where `mgn_enabled = true AND mgn_uptime_enabled = true AND mgn_site_url IS NOT NULL AND (mgn_last_status_check IS NULL OR mgn_last_status_check + CHECK_INTERVAL_SECONDS < now())`.
2. For each due node, dispatch on `mgn_uptime_check_type` to either `fetch_status_via_api($node)` or a local `check_http_status($node)`. Both return `['ok' => bool, 'error' => ?string]`.
3. Update live state:
   - **Success:** set `last_status='up'`, reset `consecutive_failures=0`, clear `down_since`. If previous status was `'down'`, fire **recovered** alert.
   - **Failure:** increment `consecutive_failures`. If `>= FAILURE_THRESHOLD` and `last_status != 'down'`, set `last_status='down'`, `down_since=now()`, fire **down** alert.

Each transition fires exactly one alert — down on entry, recovered on exit. No re-alerting while still down. If the recipient misses the email, the overview tab still shows `Down since X` next time they look.

Checks run serially in v1; for the current fleet size this is fine. If/when concurrency matters, parallelize with `curl_multi_exec` for the `http_status` path (the `api` path stays serial).

## Alerting

Email is sent inline from `RunNodeUptimeChecks` via `EmailSender::quickSend($to, $subject, $body)` (in `/includes/EmailSender.php`). Subject and body are assembled in PHP — no DB templates, no editor UI in v1.

- **Down:** subject `[{node_name}] is down` — body includes URL, HTTP status code or error, time.
- **Recovered:** subject `[{node_name}] recovered after {duration}` — body includes URL and time, where duration is `now() - down_since`.

## Admin UI

No new pages, no new tab. Two small additions to existing UI:

1. **Node edit form** (`node_add` / `nodes_edit`): add the `mgn_uptime_enabled` checkbox and the `mgn_uptime_check_type` select (`api` / `http_status`).
2. **Node detail overview tab** (`node_detail?tab=overview`): show current uptime state (`Up` / `Down since X` / `Monitoring disabled` / `Never checked`). Read-only.

Optional polish: a green/red/grey dot next to each node on the dashboard. Skip if it adds meaningful work.

## Implementation order

1. Add `mgn_uptime_*` fields to `ManagedNode` (schema syncs automatically).
2. Build `RunNodeUptimeChecks`: dispatch on check type, state updates, alert-email fallback resolution, `EmailSender::quickSend` on down/recover.
3. Add the toggle + check-type select to the node edit form and the uptime status line to the overview tab.

## Documentation

During implementation, extend `plugins/server_manager/docs/overview.md` with a short "Uptime monitoring" section: what the augmented `mgn_managed_nodes` fields mean, the two check types and how to add a third, the scheduled-task tick logic, alert rules and cooldown, and where the UI lives. No CLAUDE.md index change needed — Server Manager already has an entry.
