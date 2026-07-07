# Personal Calendar

The personal calendar is a core, subject-owned timeline. Every time-bound thing in the platform — events, bookings, native entries, and later external feeds — projects onto one timeline per subject. Availability and booking are *derived from* the calendar, never the other way around.

## Subject identity

A calendar belongs to a **schedulable subject**, not directly to a user. `CalendarSubject` (`includes/calendar/CalendarSubject.php`) is a small value object — `{type, id}` — plus a resolver that turns it into the owner record (display name, timezone, avatar). It is the single place that knows subject types exist; everything else passes a `CalendarSubject` around without branching on type.

`user` is the only implemented type. `resource`, `team`, and `venue` are reserved (the schema and interfaces already key on `(subject_type, subject_id)`).

Owner identity is stored as `subject_type` + `subject_id` columns on the only two owner-bearing tables — `sch_schedules` and `cal_entries` — and resolved live. A polymorphic owner can't be a database foreign key to `usr_users`, so those two tables give up DB-level referential integrity on the owner column; owner cleanup on user deletion is handled by a declarative `$foreign_key_actions` cascade.

## Ownership model — system of record vs. projection

The calendar is a **system of record only for items that originate in the calendar itself.** Everything else is a **read-only projection** it links out to. There is no write-back to external systems and no two-way sync.

- **Native entries** (`type = personal`) — created directly on the calendar (a personal appointment, a block of busy time). Stored in `cal_entries`, owned by the calendar, fully editable. Exposed through the registry via `NativeCalendarItemSource`.
- **Projected items** (events, bookings, later external feeds) — owned by their originating system. The calendar renders them and deep-links (`url`) but never edits or moves them. Rescheduling a booking happens in the booking flow; editing an event happens in the events system.

Consequences: `CalendarItemSource` is a read-only contract (no write methods); there is no sync engine and no conflict resolution (every item has exactly one owner); projected items are not inline-editable.

## The calendar item

`CalendarItem` (`includes/calendar/CalendarItem.php`) is the unit on the timeline — a value object, not necessarily a stored row. Fields: `start_utc`/`end_utc` (UTC instants), `all_day`, `type` (`event`/`booking`/`external`/`personal`), `title` (owner-visible only), `url` (owner-visible only), `blocks_availability`, `visibility` (`details`/`busy`), `source`, `source_key` (stable id `{source}:{record-id}` for redraw/diff, ICS UID, click-to-edit), and — on native entries only — the edit coordinates `entry_id` (the `cal_entries` row; the parent for a recurring occurrence) and `occurrence_date` (set only on virtual occurrences), so a consumer opens the right editor without parsing the `url`. At `busy` visibility the projection boundary strips `title`, `url`, and both edit coordinates.

> **Note:** the stored native-entry model is `CalendarEntry` (`data/calendar_entry_class.php`, table `cal_entries`), which is distinct from the `CalendarItem` value object. `NativeCalendarItemSource` reads `CalendarEntry` rows and emits `CalendarItem` value objects.

### Visibility — enforced at the projection boundary

A personal calendar shows the owner "Dentist, 2pm"; a stranger loading the owner's public booking page must see only *busy*, never the title. Enforcement is in the registry, not in callers: the public availability path requests items at `busy` and the registry strips `title`/`url` before they leave the aggregation. Owner-facing requests get `details`.

## Item sources and the registry

`CalendarItemSource` (`includes/calendar/CalendarItemSource.php`) is the single contract every feature implements to put things on calendars:

```php
interface CalendarItemSource {
    public static function getKey(): string;
    public function getItems(CalendarSubject $subject, string $start_utc, string $end_utc, string $visibility): array;
}
```

`CalendarItemSourceRegistry` auto-discovers sources (EmailSender-style) from core `includes/calendar/item_sources/` and active plugins' `includes/calendar_item_sources/`. Shipped sources:

- **EventItemSource** (core) — events the subject leads plus their active registrations; recurring events expand via `Event::get_instances_for_range()`.
- **NativeCalendarItemSource** (core) — native `cal_entries` entries.
- **BookingItemSource** (bookings plugin) — confirmed bookings and active paid holds where the subject is host.

The registry being the only coupling point is why this lives in core: a new source appears on every calendar and gates every availability calculation with no change to the grid, the slot generator, or any other source.

## Busy projection

There is exactly one upstream contract — items. "Busy time" is a derived view: aggregate `getItems(...)` at `busy` visibility, keep those with `blocks_availability === true`, reduce to `{start, end}`, and merge overlaps (`CalendarItemSourceRegistry::getBusyBlocks()` / `mergeBlocks()`). This projection is the only thing the availability engine and `SlotGenerator` consume. One registration therefore yields both outcomes — the item shows on the calendar **and** blocks availability.

`getBusyBlocks()` takes an optional `?callable $include` policy, checked after the `blocks_availability` filter and before items are reduced to `{start, end}` (the only point with per-item metadata left to filter on — the merged block shape that comes out has none). Default `null` keeps every caller's existing behavior — every busy item counts. A consumer that wants to treat firmness (below) as a factor in availability passes a callback reading `$item->status`; nothing does today, so bookings' policy on tentative entries is undecided (see `plugins/bookings/docs/overview.md`).

### Firmness (`cal_status`) vs. busy/free (`cal_blocks_availability`)

These are two independent axes, not one. `cal_blocks_availability` says whether an item occupies time at all (the iCal transparency axis) — untouched by firmness. `cal_status` (`tentative` | `confirmed` | `cancelled`) says how sure the calendar is that the entry is real: every human-authored path (the calendar form, `.ics` import) writes `confirmed`; an AI-extracted entry (`specs/joinery_ai_calendar_ai_surface.md`, `CalendarEntryImporter`) writes `tentative` until the owner acts on it. An AI-extracted meeting is still busy — the calendar records the truth about occupied time; risk tolerance for an *unconfirmed* commitment lives with each consumer via the `getBusyBlocks()` seam above, not by silently marking it free. `CalendarItem::$status` (default `confirmed`) carries this through the projection: `CalendarEntry`'s own projections (`get_instances_for_range()`, `NativeCalendarItemSource`) populate it from `cal_status`; every other source leaves the default, since a projected event or booking is always real.

## Native entries and the personal calendar page

`/profile/calendar` renders the `calendar_grid` component against the owner's aggregated item feed (`/ajax/calendar_feed`, `details` visibility). Native entries are created and edited there: click a day to start a new entry, click a native chip to edit it. A "blocking" entry removes its time from booking availability via the busy projection. Times are entered in the owner's timezone and stored as UTC.

## API surface (native apps and page JS)

Four core actions expose the personal calendar over `/api/v1` (session
credential; ownership enforced server-side — a foreign or missing entry is
always "Entry not found", no existence oracle). They share the exact write
path with the web form: the `_calendar_set_fields` / `_calendar_set_recurrence`
/ scope-split helpers in `logic/calendar_logic.php`.

| Action | Purpose |
|---|---|
| `calendar_feed` | Aggregated items over a UTC range (`start`, `end`; defaults −7d…+45d) at `details` visibility: `{items: [CalendarItem::toArray()...], timezone}`. |
| `calendar_entry` | One native entry shaped for an editor (`entry_id`): wall-clock `date`/`start_time`/`end_time` + `timezone`, flags, `is_recurring_parent`, `recurrence_description`, and the stored `recurrence` fields. |
| `calendar_entry_save` | Create/update: `date`, `title`, `all_day`, `blocks`, `start_time`/`end_time` (`HH:MM[:SS]`), optional `timezone` (IANA; defaults to the profile zone), optional `recurrence` object (`type`, `interval`, `days_of_week`, `week_of_month`, `ends: never\|date\|count` with `end_date`/`count`). With `entry_id` + `occurrence_date` it is a scope-aware recurring edit (`scope: this\|future\|all`, defaulting to the safe `this`). |
| `calendar_entry_delete` | Delete: standalone entries soft-delete; recurring parents take `scope` (`all` default; `this`/`future` require `occurrence_date`). |

The wall-clock → UTC conversion happens server-side in the declared
`timezone`, so clients never do timezone math; "ends after N occurrences" is
converted to a stored end date by the same `nth_occurrence_date()` engine as
the web form. `/ajax/calendar_feed` and `/ajax/calendar_entry_quick_save`
remain the web grid's endpoints.

The native iOS surface consuming these actions is **JoineryCalendarKit** —
see [Mobile Apps](mobile_apps.md#joinerycalendarkit-native-calendar-module).

## Recurring native entries

A native entry can repeat on a schedule ("every Tuesday 9–10am"). A recurring entry stays **one row** — the parent `cal_entries` record — and its occurrences are computed on the fly; there is no materialization step. `cal_recurrence_type IS NOT NULL` is the authoritative test for "this is a recurring parent." This mirrors the events expansion pattern (see [Recurring Events](recurring_events.md)).

**Pattern fields** (on `cal_entries`): `cal_recurrence_type` (`daily`/`weekly`/`monthly`/`yearly`), `cal_recurrence_interval` (every N), `cal_recurrence_days_of_week` (weekly: comma list `0=Sun…6=Sat`; monthly-by-weekday: a single weekday digit), `cal_recurrence_week_of_month` (monthly: `1`–`4`, or `-1` for last), and `cal_recurrence_end_date` (NULL = open-ended). The pattern anchors on the entry's wall-clock start (`cal_start_local`), so it follows the owner's local time.

**Expansion.** `CalendarEntry::get_instances_for_range($start_utc, $end_utc, $visibility)` returns `CalendarItem`s for a window: `compute_dates_in_range()` walks the matching dates (`date_matches_pattern()` is the per-date test), and each occurrence's wall-clock time is recombined with its date and converted to UTC per instance — so an entry stays at the same local time across DST transitions. A date the pattern can't land on — the 31st in a 30-day month, Feb 29 in a non-leap year — is **skipped, not clamped**. `NativeCalendarItemSource` expands every recurring parent alongside standalone entries (loading all parents' exceptions in a single query), so virtual occurrences flow through the busy projection like any other item: a blocking recurring entry gates availability on every occurrence with no extra wiring.

**Exceptions.** A single occurrence is skipped by a row in `cal_entry_exceptions` (`cex_cal_entry_id` + `cex_exception_date`, unique together); skipped dates are dropped during expansion. These rows are removed when the parent entry is permanently deleted (the registered `cal_entries → cal_entry_exceptions` cascade).

**Editing or deleting an occurrence** offers three scopes, reached via `/profile/calendar/entry/{parent_id}/occurrence/{date}`:

| Scope | Effect |
|---|---|
| This occurrence | Add an exception for the date; on edit, also create a standalone replacement entry (`cal_parent_entry_id` / `cal_parent_entry_date` link it to the parent). |
| This and future | Set the parent's `cal_recurrence_end_date` to the day before the occurrence; on edit, start a new recurring parent from that date, carrying forward exceptions on or after it. |
| All occurrences | Update the parent in place (delete = soft-delete the parent); existing exceptions are preserved. |

"Ends after N occurrences" is converted to an end date at save time by `CalendarEntry::nth_occurrence_date()` — it walks the pattern to the Nth match using the same engine as expansion, so the count→date conversion has a single source of truth. A count-based series is therefore stored as a `cal_recurrence_end_date`; reopening it for edit shows "Ends → on date" with that date (the behavior major calendars use).

**The authoring form is declarative.** The recurrence editor on `/profile/calendar` is a plain FormWriter form: the "Repeats" checkbox, the frequency dropdown, the monthly-pattern radios, and the "Ends" radios are real FormWriter inputs whose show/hide is driven entirely by [`visibility_rules`](formwriter.md#6-field-visibility--custom-scripts) — there is no hand-rolled toggle JavaScript and no hidden-field marshalling. The fields submit their own values; the logic reads them directly (`entry_repeats`, `rec_frequency`, `rec_interval`, `rec_days[]`, `rec_monthly_mode`, `rec_week`, `rec_dow`, `rec_ends`, `rec_end_date`, `rec_count`) and maps them onto the `cal_recurrence_*` columns. The all-day checkbox hides the time fields the same declarative way. The edit-scope and delete-scope choices remain small modal flows (they set a `scope` field), which are not field show/hide and so stay as JavaScript.

## Importing .ics files

A user can populate their calendar by uploading an iCalendar (`.ics`) file exported from another calendar (Google, Apple, Outlook/Microsoft 365, Fastmail, …). The control lives on `/profile/calendar` ("Import from another calendar"); each `VEVENT` in the file becomes a native `cal_entries` row owned by the uploader's `CalendarSubject`. Import is **one-directional and manual** — a one-time read of an uploaded file. There is no feed subscription, no periodic re-fetch, and no API/CalDAV sync.

`IcsImporter` (`includes/calendar/IcsImporter.php`) is the reader — the mirror of the `IcsHelper` writer. It has three pure-ish stages exposed as static methods: `parse()` (text → structured events), `translateRecurrence()` (an `RRULE` → native recurrence fields, or `null`), and `import()` (events → saved rows + a summary). The upload is handled by the `import_entries` branch in `calendar_logic()`; the file is parsed in memory and discarded (never stored on disk).

**Field mapping** (`VEVENT` → `cal_entries`):

| `VEVENT` | `cal_entries` |
|---|---|
| `SUMMARY` | `cal_title` (truncated to 255; empty → `(no title)`) |
| `DTSTART` / `DTEND` / `DURATION` | `cal_start_utc` + `cal_end_utc` + `cal_start_local` + `cal_end_local` + `cal_timezone` |
| `DTSTART;VALUE=DATE` | `cal_all_day = true` |
| `TRANSP` | `cal_blocks_availability` (`TRANSPARENT` → free; `OPAQUE`/absent → busy) |
| `UID` | `cal_uid` + `cal_source_event_id` |
| `RRULE` | `cal_recurrence_*` when expressible + `cal_rrule_raw` (always) |
| `EXDATE` | `cal_entry_exceptions` rows (when the event maps to a recurring parent) |
| `RECURRENCE-ID` | exception on the parent + a standalone replacement entry (`cal_parent_entry_id` / `cal_parent_entry_date`) |

Imported entries are `cal_type = personal`, `cal_visibility = details`, and `cal_source = ical_import`. `DESCRIPTION`, `LOCATION`, and `CLASS` are dropped — the native entry model is title + time + busy/recurrence and has no column for them.

**Timezones.** A `DTSTART` carrying a `TZID` is stored with that zone as `cal_timezone` and the value as the local wall-clock; a UTC (`…Z`) value is stored as the UTC instant with the local derived in the uploader's timezone; a date-only value is an all-day entry; a floating value is interpreted in the uploader's timezone. A `TZID` that is not a recognized IANA zone (e.g. an Outlook Windows name) falls back to the uploader's timezone and is reported as a warning.

**Recurrence subset.** The native recurrence model is a subset of `RRULE`. Common rules map to the `cal_recurrence_*` columns and then display, expand, and edit like any native recurring entry: `FREQ` daily/weekly/monthly/yearly, `INTERVAL`, weekly `BYDAY` lists, a single monthly ordinal weekday (`BYDAY=2TU`, `-1FR` → `cal_recurrence_week_of_month`), monthly/yearly by the start date's day, and `UNTIL`/`COUNT` (the latter converted to an end date via `CalendarEntry::nth_occurrence_date()`). Anything outside that subset — multiple ordinal `BYDAY`, `BYSETPOS`, `BYWEEKNO`, `BYMONTH`, sub-day granularity — is **not** expanded: the event is imported as a single entry at its `DTSTART` with the original rule preserved verbatim in `cal_rrule_raw`. The import summary reports how many events were handled this way (there is no generic `RRULE` expansion engine, so a preserved raw rule is retained but inert).

**Re-import.** Duplicates are matched within the subject by `cal_source = ical_import` and `cal_source_event_id` (the `UID`). An event whose `UID` was already imported is skipped, never updated or duplicated — so re-uploading a file does not overwrite edits made after a prior import. New `UID`s are inserted.

**Summary.** `import()` returns counts surfaced as a banner on the calendar page: created, already-imported (skipped), imported-as-single (advanced recurrence), warnings, failed (per-event, best-effort — one bad `VEVENT` never aborts the rest), and capped (events beyond the per-file limit, reported rather than silently dropped).

## Deletion

The owner column is polymorphic (`subject_type` + `subject_id`), so it can't be a real FK and the generic delete-cascade can't express it — a blind delete by id would also hit other subject types sharing the number. Owner cleanup is therefore subject-aware: `CalendarSubject::purge()` permanently deletes a subject's schedules and native entries (the latter cascading to their `cal_entry_exceptions`), and the owner's deletion path calls it — `User::permanent_delete()` purges the user subject. Soft-deleting a user changes nothing here (the owner still exists); only a permanent delete purges. Native entries have no external side effects.
