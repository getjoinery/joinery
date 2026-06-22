# Event Time Storage Fix

## Background

Events store both a UTC timestamp (`evt_start_time`, `evt_end_time`) and a local timestamp (`evt_start_time_local`, `evt_end_time_local`). The intent of dual storage is correct: for future events, UTC alone is not sufficient because government timezone rule changes can cause UTC→local re-conversion to disagree with what the user originally entered. The local time preserves original intent; UTC is derived data that can be recomputed when tzdata changes.

The current implementation has this direction inverted, and has a secondary bug in which timezone is used for conversion.

References:
- [Jon Skeet — Storing UTC is not a silver bullet](https://codeblog.jonskeet.uk/2019/03/27/storing-utc-is-not-a-silver-bullet/) — canonical engineering post; recommends storing local + IANA + derived UTC
- [RFC 9557 — IETF ratified standard (2024) for timezone annotations on timestamps](https://www.rfc-editor.org/rfc/rfc9557) — standardises `2026-11-01T09:00:00-07:00[America/Vancouver]` wire format
- [RFC 5545 — iCalendar Date-Time: local time + TZID is preferred over UTC](https://datatracker.ietf.org/doc/html/rfc5545)
- [TC39 Temporal Cookbook — future appointments must not be stored as UTC instants](https://tc39.es/proposal-temporal/docs/cookbook.html)
- [IANA tz database — theory.html: "predictions will be incorrect after governments change the rules"](https://data.iana.org/time-zones/theory.html)
- [Matt Johnson-Pint — On the Timing of Timezone Changes (documents short-notice government changes)](https://codeofmatt.com/on-the-timing-of-time-zone-changes/)
- [Matt Johnson-Pint — Egypt 2016: 4 days notice, iPhones wrong, Emirates flight departed early](https://codeofmatt.com/time-zone-chaos-inevitable-in-egypt/)
- [Crunchy Data — British Columbia 2026 timezone change and Postgres remediation](https://www.crunchydata.com/blog/british-columbia-and-time-zone-changes)
- [Shay Rojansky — Storing timezones in the DB (UTC everywhere isn't enough)](https://www.roji.org/storing-timezones-in-the-db)
- [Q42 Engineering — Why "always use UTC" is bad advice](https://engineering.q42.nl/why-always-use-utc-is-bad-advice/)
- [CalConnect developer guide — iCalendar date/time handling](https://devguide.calconnect.org/iCalendar-Topics/Handling-Dates-and-Times/)
- [CodeOpinion — Just store UTC? Not so fast!](https://codeopinion.com/just-store-utc-not-so-fast-handling-time-zones-is-complicated/)
- [Simon Willison — Storing times for human events](https://simonwillison.net/2024/Nov/27/storing-times-for-human-events/)
- [Noah Sussman — Falsehoods Programmers Believe About Time](https://infiniteundo.com/post/25326999628/falsehoods-programmers-believe-about-time)

---

## Bug 1: Direction of Derivation Is Inverted

### Current (wrong)
`process_datetimeinput()` immediately converts the user's local input to UTC at form submission time. UTC is stored as primary. `prepare()` re-derives local from UTC before every save.

```
user enters local time
  → convert to UTC (using session timezone)
  → store UTC as primary
  → prepare() derives local from UTC
```

If timezone rules change after the event is saved, `prepare()` will overwrite the local field with the newly-converted value, destroying the original intent. The local field offers no protection.

### Correct
Local time is the source of truth. UTC is derived from local + the event's IANA timezone. `prepare()` should compute UTC from local, not the other way around.

```
user enters local time
  → store local time as primary
  → prepare() derives UTC from local + evt_timezone
```

If timezone rules change, recompute UTC from the still-intact local field.

---

## Bug 2: Wrong Timezone Used at Form Submission

### Current (wrong)
`process_datetimeinput()` with `$to_utc = true` converts the user's input using `$session->get_timezone()` — the editing admin's own timezone.

The form label tells the user to enter times in the *event's* timezone (`evt_timezone`). An admin in UTC who types "9:00 AM" for a New York event intends 9 AM Eastern. The system converts it as UTC, storing the wrong moment.

### Correct
Conversion must use `evt_timezone`, not the session timezone. The form already communicates to the user which timezone to enter times in — the backend must honour that same timezone.

---

## What Needs to Change

### `adm/admin_event_session_edit.php` and any other event/session time save paths

Stop converting to UTC at form submission time. Instead:
1. Call `process_datetimeinput()` with `$to_utc = false` to get the raw local datetime string.
2. Store that directly into `evs_start_time_local` / `evs_end_time_local`.
3. Let `prepare()` compute and store UTC.

The "copy last session + N days" logic (lines 107-117) already shifts both UTC and local independently — that pattern is correct and should be preserved.

### `data/events_class.php` and `data/event_sessions_class.php` — `prepare()`

Reverse the derivation direction:

```php
// CORRECT direction: derive UTC from local + timezone
if ($this->get('evt_start_time_local') && $this->get('evt_timezone')) {
    $utc = LibraryFunctions::convert_time(
        $this->get('evt_start_time_local'),
        $this->get('evt_timezone'),
        'UTC',
        'Y-m-d H:i:s'
    );
    $this->set('evt_start_time', $utc);
}
```

Same for end time. The event session class must pull `evt_timezone` from the parent event — sessions do not have their own timezone field.

### `includes/FormWriterV2Base.php` — `process_datetimeinput()`

The `$to_utc` parameter and the session-timezone conversion path are the source of bug 2. Options:

- Remove the `$to_utc` path entirely and always return local time, forcing callers to handle conversion with the correct timezone.
- Keep the parameter but deprecate it — it can only be correct when the session timezone happens to match the intended timezone, which is an unsafe assumption.

Callers that currently pass `$to_utc = true` outside the events system should be audited to confirm they are actually storing times that should use the session's timezone (e.g., user-created calendar entries for themselves, where session TZ == intended TZ).

---

## Display (No Change Required)

Display already converts from UTC using the event's timezone — this is correct and should not change. UTC remains the value used for sorting, filtering, and all query-time comparisons.

---

## Storing the tzdata Version

The recommended pattern for all tables storing future user-facing appointment times is:

| Column | Role |
|--------|------|
| `*_local` | Source of truth — the user's original wall-clock intent |
| `*_timezone` | IANA identifier — required for conversion |
| `*_utc` / primary timestamp | Derived cache — recomputed when tzdata updates |
| `*_tzdata_version` | tzdata version active at write time (e.g. `2026a`) |

The `*_tzdata_version` field enables targeted recomputation: after a tzdata update, find all future appointments in the affected timezone where `tzdata_version != current_version`, recompute UTC from local + timezone using the new rules, and write the updated UTC back. No local fields change — they are the permanent record of intent.

The tzdata version string can be read at runtime from the system's installed tzdata package:

```bash
# Linux
cat /usr/share/zoneinfo/tzdata.zi | head -1
# or: dpkg -l tzdata | grep ^ii
```

Or stored as a platform setting updated whenever tzdata is upgraded.

---

## Tables That Need This Pattern

### Tier 1 — Immediate priority (future appointments, displayed to users in their timezone)

**`evt_events` / `data/events_class.php`**
- Has: `evt_start_time` (UTC), `evt_start_time_local` (local, currently derived from UTC — bug), `evt_timezone`
- Missing: `evt_tzdata_version`
- Fix: reverse derivation direction in `prepare()` + add tzdata version field

**`evs_event_sessions` / `data/event_sessions_class.php`**
- Has: `evs_start_time` (UTC), `evs_start_time_local` (local, currently derived from UTC — bug), timezone from parent `evt_timezone`
- Missing: `evs_tzdata_version`
- Fix: reverse derivation direction in `prepare()` + add tzdata version field
- Note: display methods (`get_start_time()`, `get_end_time()`, `get_time_string()`) already read from `evs_start_time_local` rather than UTC — the intent was right, only the derivation direction is wrong

**`bkn_bookings` / `plugins/bookings/data/bookings_class.php`**
- Has: `bkn_start_time` (UTC), `bkn_end_time` (UTC), `bkn_invitee_timezone`
- Missing: `bkn_start_time_local`, `bkn_end_time_local`, `bkn_tzdata_version`
- Fix: add local fields, store them as source of truth at booking creation, derive UTC from them, add tzdata version field
- Note: bookings originate from a slot picker that knows the local time; local is available at creation time

**`cal_calendar_entries` / `data/calendar_entry_class.php`**
- Has: `cal_start_utc`, `cal_end_utc`, `cal_all_day` (bool)
- Missing: timezone field, local time fields, `cal_tzdata_version`
- Fix: add `cal_timezone` (IANA identifier, populated from the creating user's timezone), `cal_start_local`, `cal_end_local`, `cal_tzdata_version`
- Note: `logic/calendar_logic.php` converts local input to UTC at save time using `$session->get_timezone()` — same derivation-direction bug as events; local is available from form input

### Tier 2 — Not required (no user-facing timezone context)

**`ccd_coupon_codes`** — `ccd_start_time`, `ccd_end_time` are validity windows evaluated server-side in UTC. No user timezone field; coupons are not appointments.

**`odi_order_items`** — `odi_subscription_period_end` is processed server-side in UTC. Not displayed as a user appointment.

**`sch_schedules` / schedule windows** — Windows store bare `TIME` (wall-clock, no date), which is already the source of truth. The slot generator recomputes UTC per occurrence at query time, so no tzdata version is needed on the template rows.

---

## Handling Timezone Rule Changes

With the pattern in place, the recovery path if tzdata changes is:

1. Identify future appointments in the affected timezone where `tzdata_version != current_version`.
2. For each, recompute UTC from `*_local` + `*_timezone` using the updated tzdata.
3. Write the new UTC value back and update `tzdata_version`.

This can be done as a maintenance script triggered whenever a tzdata package update is applied. No local time fields change — they remain the permanent record of original intent.

---

## Scope

**Bugs to fix (derivation direction inverted):**
- `adm/admin_event_session_edit.php` — stop converting to UTC at form submission; store local time, let `prepare()` derive UTC
- `data/events_class.php` `prepare()` — reverse: derive UTC from local+timezone, not local from UTC; write `evt_tzdata_version`
- `data/event_sessions_class.php` `prepare()` — same reversal; write `evs_tzdata_version`
- `logic/calendar_logic.php` — store local input before UTC conversion; add `cal_timezone`, `cal_start_local`, `cal_end_local`, `cal_tzdata_version`

**New fields to add (local + tzdata version missing):**
- `data/events_class.php` — add `evt_tzdata_version varchar(10)`
- `data/event_sessions_class.php` — add `evs_tzdata_version varchar(10)`
- `plugins/bookings/data/bookings_class.php` — add `bkn_start_time_local`, `bkn_end_time_local`, `bkn_tzdata_version varchar(10)`
- `data/calendar_entry_class.php` — add `cal_timezone varchar(64)`, `cal_start_local timestamp(6)`, `cal_end_local timestamp(6)`, `cal_tzdata_version varchar(10)`

**Audit:**
- All other `process_datetimeinput(..., true)` call sites — confirm they are safe (session TZ == intended TZ) or apply the same fix
- `includes/FormWriterV2Base.php` — deprecate or remove the `$to_utc` parameter to prevent recurrence

---

## What Is Not Changing

- Existing UTC and local column names — no renames, only new columns added
- Display logic — UTC → timezone conversion at render time remains correct; display does not change
- Schedule windows — already stored as wall-clock `TIME`; no changes needed
