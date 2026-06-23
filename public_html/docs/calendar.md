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

`CalendarItem` (`includes/calendar/CalendarItem.php`) is the unit on the timeline — a value object, not necessarily a stored row. Fields: `start_utc`/`end_utc` (UTC instants), `all_day`, `type` (`event`/`booking`/`external`/`personal`), `title` (owner-visible only), `url` (owner-visible only), `blocks_availability`, `visibility` (`details`/`busy`), `source`, `source_key` (stable id `{source}:{record-id}` for redraw/diff, ICS UID, click-to-edit).

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

## Native entries and the personal calendar page

`/profile/calendar` renders the `calendar_grid` component against the owner's aggregated item feed (`/ajax/calendar_feed`, `details` visibility). Native entries are created and edited there: click a day to start a new entry, click a native chip to edit it. A "blocking" entry removes its time from booking availability via the busy projection. Times are entered in the owner's timezone and stored as UTC.

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

## Deletion

The owner column is polymorphic (`subject_type` + `subject_id`), so it can't be a real FK and the generic delete-cascade can't express it — a blind delete by id would also hit other subject types sharing the number. Owner cleanup is therefore subject-aware: `CalendarSubject::purge()` permanently deletes a subject's schedules and native entries (the latter cascading to their `cal_entry_exceptions`), and the owner's deletion path calls it — `User::permanent_delete()` purges the user subject. Soft-deleting a user changes nothing here (the owner still exists); only a permanent delete purges. Native entries have no external side effects.
