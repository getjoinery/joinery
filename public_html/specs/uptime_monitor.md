# Uptime Monitor Plugin Spec

A standalone plugin that replaces UptimeRobot for an owner managing a handful of services. Ships as a Joinery proof-of-concept — "install Joinery, enable Uptime Monitor, add your URLs, done."

No hard dependency on Server Manager.

## Scope

**In (v1):**
- HTTP/HTTPS endpoint checks (status code, response time, keyword match)
- Configurable interval (1/5/15 min)
- Email/SMS alerts on failure and recovery
- Public status page with historical uptime %
- Per-check and per-period uptime stats
- Multi-contact alert routing

**Out (deferrable to Phase 2):** TCP/ping checks, heartbeat/cron monitors, multi-region checks, SSL/domain expiry tracking, maintenance windows, public write API.

## Data models

| Table | Purpose |
|-------|---------|
| `mon_checks` | One row per URL to watch |
| `mon_check_results` | Append-only log; trimmed after 90 days |
| `mon_incidents` | Start/end of each down period |
| `mon_alert_contacts` | Notification destinations (email, sms) |
| `mon_check_contacts` | M:N check ↔ contact |
| `mon_status_pages` | Public status page configs |

**`mon_checks` key fields:** `mon_url`, `mon_method` (default `HEAD`), `mon_expected_status` (e.g. `200-299` or `200,301,302`), `mon_body_contains`, `mon_request_headers` (JSON), `mon_timeout_seconds` (10), `mon_interval_seconds` (300), `mon_failure_threshold` (2 consecutive failures before firing), `mon_alert_cooldown_seconds` (1800), `mon_enabled`, and live state fields `mon_last_checked_at`, `mon_last_status`, `mon_current_incident_id`.

**`mon_check_results` fields:** `mcr_mon_check_id`, `mcr_checked_at`, `mcr_status_code`, `mcr_response_time_ms`, `mcr_success`, `mcr_error_message`. Indexed on `(mcr_mon_check_id, mcr_checked_at DESC)` for fast history queries.

## Check runner

A scheduled task `tasks/run_uptime_checks.php` runs every 60 seconds via the existing scheduled-task runner. On each tick:

1. Select checks where `enabled = true AND (last_checked_at IS NULL OR last_checked_at + interval < now())`.
2. Execute due checks in parallel with `curl_multi_exec`, bounded concurrency (e.g. 20).
3. Write one `mon_check_results` row per check.
4. Apply failure-threshold logic:
   - Success while an incident is open → resolve it, fire "recovered" alert.
   - Failure while consecutive-failure count ≥ threshold and no open incident → open one, fire "down" alert.

`curl_multi` keeps a 100-check fleet comfortably inside a 60-second tick.

## Alerting

- Email via existing `SystemMailer`.
- SMS via `SmsHelper` **if** the SMS integration is installed (soft-detect with `class_exists`).
- Per-check contact lists; each alert respects the per-check cooldown window.
- Alert templates editable under plugin settings. Placeholders: `{check_name}`, `{url}`, `{status_code}`, `{error}`, `{duration}`.

## Public status page

URL: `/status` (per-page configurable slug, so multiple status pages can coexist if needed).

- Each check: green/yellow/red dot + name + current response time.
- Uptime % over 24h / 7d / 30d, computed on the fly from `mon_check_results`.
- Open-incidents panel listing active down periods.
- Rendered through the active public theme; no auth required.

## Admin UI

Under `/admin/uptime_monitor/...`, mirroring Server Manager's layout:

| Page | Purpose |
|------|---------|
| `/admin/uptime_monitor` | Dashboard: all checks with dots, current uptime %, recent incidents |
| `/admin/uptime_monitor/check_edit` | Add/edit a check |
| `/admin/uptime_monitor/check_detail?id=N` | Single check: response-time chart, result history, incidents |
| `/admin/uptime_monitor/incidents` | Global incident history |
| `/admin/uptime_monitor/contacts` | Alert contact CRUD |
| `/admin/uptime_monitor/status_pages` | Public status page configuration |

## Optional cross-plugin integration

If Server Manager is installed, node-detail pages show a "Uptime Monitors for this node" section listing any `mon_checks` whose URL is under the node's `mgn_site_url`, plus a one-click "Add monitor for this site" button. One-way dependency only — Uptime Monitor does not require Server Manager.

## Implementation order

1. Data models + migrations (tables, seed alert templates).
2. Admin CRUD (checks, contacts).
3. Check runner scheduled task + `mon_check_results` writing.
4. Incident detection + alert firing.
5. Check detail page with response-time chart and history.
6. Public `/status` page.
7. Cross-plugin integration with Server Manager (optional, last).

## Documentation

Per existing convention, developer reference material lives in `/docs/`, not in this spec. During implementation, create `docs/uptime_monitor.md` covering:
- Data model reference
- Scheduled task internals
- Alert template syntax
- Public status page rendering through the theme system
- Cross-plugin integration shape

Add an entry to the CLAUDE.md doc index.
