# Calendar Email Reminders & Summaries

## Overview

Members can ask the calendar to email them: a **daily or weekly summary** of what's coming up, and a **reminder shortly before an event starts** (1 hour / 30 / 15 / 5 minutes, chosen as a default in calendar settings). Each individual calendar entry inherits the member's default but can override it — including turning the reminder off for just that entry.

One core scheduled task drives both. It is **activated on install** on every deployment, but the factory state sends nothing: both preferences default to off, so zero email leaves until a member opts in on the new calendar settings page.

This build is also the designated **live test of the `mail.<domain>` machine-sender path** on the jeremytunnell.com deployment (see "Send path" below): reminder mail originates from cron, which can never hold a vault unlock window, so it *must* travel the ambient platform send path — exactly the path the `mail.jeremytunnell.com` sending identity exists to serve on a Fortress mail domain.

---

## Design Principles

**Copy the bookings reminder pattern.** `plugins/bookings/tasks/BookingEmailsTask.php` is the platform's only existing reminder implementation: an `every_run` task, offsets computed against start times, idempotency via a send-log table. This spec is that pattern applied to the core calendar, plus per-user preferences.

**Preferences follow the notification-preferences template.** One row per user in a dedicated table; **absence of a row means "defaults apply" (everything off)**. Owner-or-staff read/write, API-exposed, `permanent_delete` on the user FK — the `ntp_notification_preferences` shape.

**Summaries show what the calendar page shows.** The summary email is built from `CalendarItemSourceRegistry` aggregation — native entries, event registrations, bookings — not from `cal_entries` alone. If it's on your calendar, it's in your summary.

**Per-entry reminders are native-entry scope in v1.** The per-entry override lives on `cal_entries`, so timed reminders cover personal entries (including AI-created and ICS-imported ones). Projected items keep their own channels: bookings already has reminder emails; event session reminders would be an event_manager feature with per-registration state, out of scope here.

**Cron never reads sealed content.** The task sends ambient (platform sender) only — never the session-gated compose transport, which requires a vault window a CLI process structurally cannot have. Template design is forward-compatible with the protection-levels doctrine (generic reminders for future Private entries; see "Protection levels" below).

---

## User Experience

### Calendar settings page — `/profile/calendar_settings`

New member settings section **Calendar** in the settings rail (core `settingsMenu` block in `admin_menus.json`), between Notifications and Security. FormWriter page, two controls, self-documenting labels — no explainer prose:

```
Summary emails      [No summary ▾ | Daily | Weekly — Mondays]
Send summary at     [7:00 AM ▾]     (hour dropdown, member's timezone; visible only when a summary is selected — visibility_rules)
Event reminders     [No reminder ▾ | 1 hour before | 30 minutes before | 15 minutes before | 5 minutes before]
                    (applies to new and existing entries unless an entry overrides it)
```

Defaults: `No summary`, send hour `7:00 AM`, `No reminder`. The send hour is per-member, interpreted in `usr_timezone`.

### Entry form — per-entry override

The native entry form on `/profile/calendar` gains one dropdown below the time fields:

```
Reminder:  [Use my default (30 minutes before) ▾ | No reminder | 1 hour before | 30 minutes before | 15 minutes before | 5 minutes before]
```

- "Use my default" renders the member's current default inline so the choice is legible without leaving the form. When the default is "No reminder", the option reads "Use my default (no reminder)".
- This satisfies "on/off per event" and stays coherent in the one ambiguous case: default off + entry on needs a lead time, and a bare toggle can't express one.
- On a recurring parent the setting applies to every occurrence. A "this occurrence only" edit already materializes a standalone replacement row, which carries its own override for free.

### The emails

**Reminder** — subject `Reminder: {title} at {time}`; body: title, local start–end time (entry's `cal_timezone`), location/description if present, a "(tentative)" badge when `cal_status = 'tentative'`, link to `/profile/calendar`. Footer links to calendar settings ("Change or turn off reminders").

**Summary** — subject `Your calendar today` / `Your week ahead`; body: a per-day list of items (time, title, source badge for events/bookings), covering the member's local **today** (daily) or **next 7 days** (weekly, sent Monday). Built from the aggregated feed. **No email is sent for an empty period.**

---

## Schema

### `cpr_calendar_preferences` — new table, class `CalendarPreference` / `MultiCalendarPreference`

Scaffold with `surfaces: ["data"]`. (Prefix `cpr` verified unused; re-verify at build.)

```php
'cpr_usr_user_id'              => ['type' => 'int4', 'required' => true, 'unique' => true,
                                   'foreign_key' => 'usr_users'],   // deletion action: permanent_delete
'cpr_summary_frequency'        => ['type' => 'varchar(10)', 'default' => 'none'],  // none | daily | weekly
'cpr_summary_hour'             => ['type' => 'int4', 'default' => 7],              // 0–23, local hour to send summaries
'cpr_reminder_default_minutes' => ['type' => 'int4', 'default' => 0],              // 0 = off; else 60|30|15|5
'cpr_create_time' / 'cpr_update_time'
```

Static helper `CalendarPreference::get_for(int $user_id): CalendarPreference` returning an unsaved defaults object when no row exists (the `NotificationPreference::get_for` idiom). Owner-or-staff `authenticate_read`/`authenticate_write`; `api_readable`/`api_writable` on both preference fields.

### `cal_entries` — one new field

```php
'cal_reminder_minutes' => ['type' => 'int4', 'is_nullable' => true],
// NULL = inherit the owner's default; 0 = no reminder for this entry; else minutes before start (60|30|15|5)
```

### `cme_calendar_emails` — new send-log table, class `CalendarEmail` / `MultiCalendarEmail`

Idempotency ledger for everything this task sends (the `bke_booking_emails` role). Scaffold with `surfaces: ["data"]`.

```php
'cme_usr_user_id'          => ['type' => 'int4', 'required' => true,
                               'foreign_key' => 'usr_users'],       // deletion action: permanent_delete
'cme_kind'                 => ['type' => 'varchar(20)', 'required' => true],  // reminder | summary_daily | summary_weekly
'cme_cal_entry_id'         => ['type' => 'int8', 'is_nullable' => true,
                               'foreign_key' => 'cal_entries'],     // deletion action: cascade; NULL for summaries
'cme_occurrence_start_utc' => ['type' => 'timestamp(6)', 'is_nullable' => true], // reminders only
'cme_period_key'           => ['type' => 'varchar(10)',  'is_nullable' => true], // summaries: local Y-m-d of period start
'cme_dedup_key'            => ['type' => 'varchar(160)', 'required' => true, 'unique' => true],
'cme_send_time'            => ['type' => 'timestamp(6)', 'default' => 'now()'],
```

`cme_dedup_key` is deterministic and does the real work (one unique column instead of two partial composites, which the auto-schema system doesn't model):

- reminder: `reminder:{entry_id}:{occurrence_start_utc}`
- summary: `{kind}:{user_id}:{period_key}`

The descriptive columns exist for reporting and admin inspection. Retention: `$retention_policy` on the class, delete rows older than 90 days (picked up by `RetentionSweep` automatically).

This table is a dedup ledger, not an audit trail — the unique key is the at-most-once guarantee, which is why no shared log table can play its role (a generic log can't carry a unique constraint, an FK cascade to `cal_entries`, or its own retention). Run-level audit ("sent 12 reminders, 3 summaries") goes to the generic `EventLog` (`evl_event = 'calendar_emails_run'`), the way ICS import records its runs — only when the pass sent something, so the every-minute tick doesn't flood the log.

---

## The Scheduled Task

### `tasks/CalendarEmails.php` + `tasks/CalendarEmails.json` (core — the calendar is core)

```json
{
    "name": "Calendar Email Reminders & Summaries",
    "description": "Sends per-entry reminder emails and daily/weekly calendar summary emails to members who opted in on their calendar settings page.",
    "activate_on_install": true,
    "default_frequency": "every_run"
}
```

`every_run` because 5-minute reminder leads need minute resolution; the task self-limits (both scans are cheap indexed lookups and exit early when nobody has opted in). The runner's advisory lock already prevents overlap.

The task class is a thin wrapper. All behavior lives in **`includes/calendar/CalendarEmailEngine.php`**, which takes `$now` (UTC string) as a parameter so tests drive the clock. Implement `ScheduledTaskDryRunnable`: dry run reports what would send right now (count + first few recipients/titles) without sending or logging.

### Reminder pass — per tick

1. Effective lead for an entry = `cal_reminder_minutes` if not NULL, else the owner's `cpr_reminder_default_minutes` (0 without a row). Lead 0 → no reminder.
2. Candidate window: entries starting in `[now, now + 65 minutes]` (65 = max lead + one tick of slack), `cal_subject_type = 'user'`, not soft-deleted, `cal_status != 'cancelled'`, not all-day. Two selections, both bounded by the window:
   - entries with an explicit `cal_reminder_minutes > 0`;
   - entries with `cal_reminder_minutes IS NULL` whose owner has a preference row with `cpr_reminder_default_minutes > 0`.
3. Recurring parents matching the same owner filters are expanded via `get_instances_for_range(now, now + 65 min)`; each instance is a candidate with its own occurrence start.
4. An entry/occurrence is **due** when `occurrence_start - lead <= now < occurrence_start`. Sending late-but-before-start is deliberate: an entry created 10 minutes before start with a 60-minute lead still gets its reminder immediately. **Never send at or after start** — a reminder for something already underway is noise, so a missed window (cron outage) is dropped, not queued.
5. Dedup: insert the `cme_` row first (unique key), send on success. A moved entry gets a fresh occurrence start and therefore a fresh reminder for the new time — correct, not a bug.

### Summary pass — per tick

1. Load preference rows with `cpr_summary_frequency != 'none'`.
2. For each user, compute local time from `usr_timezone`. Due when local time ≥ `cpr_summary_hour`:00, and (weekly only) local day is Monday, and no `cme_` row exists for this period's dedup key. (The ≥ comparison means a raised hour later the same day never double-sends — the dedup key is per period, not per hour.)
3. Build the item list through `CalendarItemSourceRegistry` for the user subject over the local period (today / next 7 days), exactly as the calendar feed does. Empty period → log the `cme_` row anyway (so the check stays cheap) but send nothing.
4. Send, honoring `email_test_mode` etc. through the normal `EmailSender` path.

---

## Email Templates

Two inner templates (type 2), seeded by a core migration (`migrations.php` entry + `migration_calendar_email_templates.php`, the `subscription_created` pattern), editable afterward at `/admin/admin_email_templates`:

- **`calendar_reminder`** — vars: `title`, `start_display`, `end_display`, `location`, `description`, `calendar_url`, `settings_url`, `recipient`. Title/location/description all render inside `{var}...{end}` conditionals.
- **`calendar_summary`** — vars: `period_label`, `days` (loop of day → items loop), `calendar_url`, `settings_url`, `recipient`.

Sending is one call per email: `EmailSender::sendTemplate('calendar_reminder', $email, $vars)` — ambient transport, platform default sender, wrapped by the deployment's outer template. **No custom transport, no `resolveOutboundTransport()`:** cron holds no vault window, and `EmailSender` will (correctly) throw if a protected identity domain is used outside the compose path.

---

## Send Path: the `mail.jeremytunnell.com` Live Test

jeremytunnell.com is a **Fortress mail domain with strict DMARC** (`aspf=s`/`adkim=s`): bare-domain machine senders fail DMARC today, and `EmailSender` refuses protected-identity From addresses outside the session-gated compose path. Per the email-consolidation doctrine, `mail.<domain>` is the machine-sender identity everywhere. Cron-origin calendar mail is the ideal first real traffic through it: it exercises the exact path (ambient provider send, machine subdomain From, DKIM aligned to the subdomain) that every transactional email on a Fortress domain must use.

**Prerequisites on the jeremytunnell deployment (node 176)** — follow the recipe in `specs/mailbox_strict_sending_identities.md` (the `mail.scrolldaddy.app` worked example):

1. Register `mail.jeremytunnell.com` as a sending identity (it is **not yet registered** — the bare domain isn't either). Mind the recorded gotchas: use node 176's admin Mailgun key; `force_dkim_authority=true` at create (never fix authority after the fact); 2048-bit, selector `pic`; SPF `-all`; DKIM TXT pre-quoted and split at 255 chars for Cloudflare. (If the box's own Postfix provider is preferred over Mailgun, the identity/DNS work is the same; the provider choice is a deployment setting, decided at flip time.)
2. Site settings: `defaultemail` → `notifications@mail.jeremytunnell.com`, provider send domain to match, `defaultreplyto` → the owner's real mailbox on the bare domain.

**Acceptance (live):**

- A calendar reminder and a daily summary, triggered by real cron, arrive at an external mailbox (e.g. Gmail) with **SPF pass, DKIM `d=mail.jeremytunnell.com`, DMARC pass**.
- Site error log shows no `Refusing to send from a protected identity domain` and no `VaultLockedException` — proving the task never strays off the ambient path.
- Append the result to the live-verification queue when built.

---

## Protection Levels (forward compatibility only)

`specs/protection_levels_platform.md` gives the calendar a Standard/Private dial and states: **Private entries send generic reminders** ("You have an appointment at 2pm") because cron holds no window, and Private entries are excluded from feeds. Nothing of that dial is built here — every entry today is plaintext Standard. This spec stays forward-compatible by construction:

- The reminder template renders title/location/description conditionally, so a sealed entry can flow through the same template with only `start_display` set.
- The engine centralizes "what may this email say about this entry" in one method (`CalendarEmailEngine::reminderVars($item)`), which is where the level check lands later.

---

## Phases

### Phase 1 — Data layer

1. `cal_reminder_minutes` on `CalendarEntry::$field_specifications`.
2. Scaffold `cpr_calendar_preferences` and `cme_calendar_emails` (data classes + Multi, `surfaces: ["data"]`, deletion actions as specified). `CalendarPreference::get_for()`. Retention policy on `CalendarEmail`.
3. Run `update_database`. Model CRUD tests.

### Phase 2 — Settings page + entry form

1. `views/profile/calendar_settings.php` + `logic/calendar_settings_logic.php` (FormWriter, `_logic_descriptor` API opt-in — page JS not required here, but the surface is the platform contract).
2. `settingsMenu` entry in `admin_menus.json` (slug `core-calendar-settings`, url `/profile/calendar_settings`); run `update_database` to reseed menus.
3. Reminder dropdown on the native entry form; field handling in `calendar_entry_save_logic.php` (validate against the allowed set: NULL, 0, 60, 30, 15, 5).

### Phase 3 — Engine, task, templates

1. `includes/calendar/CalendarEmailEngine.php` (clock-injected; reminder pass, summary pass, dedup-first sends, `reminderVars()`).
2. `tasks/CalendarEmails.php` + `.json` (thin wrapper + dry run).
3. Template migration (`calendar_reminder`, `calendar_summary`).
4. `tests/calendar/calendar_emails_test.php` (db tier): default-off sends nothing; opt-in reminder due/not-due boundaries (before window, in window, after start); per-entry override in both directions (on despite default off, off despite default on); recurring expansion sends per occurrence; idempotency on repeated runs; moved entry re-reminds at the new time; daily summary due at the configured hour across two timezones (including a non-default hour); weekly only on Monday; empty period logs but doesn't send; cancelled and soft-deleted entries excluded.

### Phase 4 — Docs + live verification

1. `docs/calendar.md` gains an "Email reminders and summaries" section (current-state, developer-facing: preference model, engine, dedup keys, template names).
2. The jeremytunnell send-path test above; record the outcome in the live-verification queue.

---

## Files

**Create:** `data/calendar_preference_class.php`, `data/calendar_email_class.php`, `includes/calendar/CalendarEmailEngine.php`, `tasks/CalendarEmails.php`, `tasks/CalendarEmails.json`, `views/profile/calendar_settings.php`, `logic/calendar_settings_logic.php`, `migrations/migration_calendar_email_templates.php`, `tests/calendar/calendar_emails_test.php`.

**Modify:** `data/calendar_entry_class.php`, `logic/calendar_entry_save_logic.php`, `views/profile/calendar.php` (entry form dropdown), `admin_menus.json`, `migrations/migrations.php`, `docs/calendar.md`.

---

## Decisions — all resolved with owner 2026-08-07

1. ~~All-day entries~~ **DECIDED 2026-08-07: excluded from timed reminders**; they appear in summaries (the 7 AM daily summary is effectively their reminder).
2. ~~Summary send time~~ **DECIDED 2026-08-07: member-configurable hour** (`cpr_summary_hour`, hour dropdown on the settings page, default 7 AM, member's timezone).
3. ~~Per-entry control~~ **DECIDED 2026-08-07: dropdown as specced** (Use my default / No reminder / explicit leads).
4. ~~Tentative entries~~ **DECIDED 2026-08-07: included** in both reminders and summaries, badged "(tentative)" in the email.
5. ~~Sender local part~~ **DECIDED 2026-08-07: `notifications@mail.jeremytunnell.com`**.
