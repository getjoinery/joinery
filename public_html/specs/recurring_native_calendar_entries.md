# Recurring Native Calendar Entries

## Overview

Extend native `CalendarEntry` records (`cal_items`) so a personal calendar entry can repeat on a schedule. A user blocks out "every Tuesday 9–10am for yoga" once and the calendar and availability engine see every occurrence automatically.

This spec is a direct follow-on to `specs/scheduling_system.md`. The core machinery (virtual instance expansion, `NativeCalendarItemSource`, `SlotGenerator`) is already in place; this spec wires recurrence into the data layer and the authoring UI.

---

## Design Principles

**Virtual-only expansion.** There is no materialization step — a recurring entry stays one database row (the parent). Instances are computed on the fly by `CalendarEntry::get_instances_for_range()`, exactly as the event system does for its recurring parents. Because native entries have no registration, there is no reason to write instance rows to the database.

**Exceptions, not edits.** Skipping or moving one occurrence is done through an exception: skip the original date (stored in `cal_item_exceptions`) and optionally create a standalone replacement entry. There is no per-instance in-place editing.

**Edit scopes mirror common calendar conventions.** When a user edits a recurring entry they choose: this occurrence only (skip + replace), this and future (split the series), or all occurrences (update the parent).

**Reuse the events expansion pattern.** `get_instances_for_range()` on `CalendarEntry` follows the same interface and algorithm as `Event::get_instances_for_range()`. The slot generator and busy projection receive the same `CalendarItem` value objects regardless of whether an entry is recurring or not.

---

## Schema Changes

### Additions to `cal_items`

Add to `CalendarEntry::$field_specifications`:

```php
// Recurrence (null = non-recurring; non-null = this is a recurring parent)
'cal_recurrence_type'         => ['type' => 'varchar(20)',  'is_nullable' => true],
// Values: 'daily' | 'weekly' | 'monthly' | 'yearly'

'cal_recurrence_interval'     => ['type' => 'int4',         'default' => 1],
// Every N days/weeks/months/years

'cal_recurrence_days_of_week' => ['type' => 'varchar(20)',  'is_nullable' => true],
// Weekly only: comma-separated 0=Sun…6=Sat, e.g. "1,3,5"

'cal_recurrence_week_of_month'=> ['type' => 'int4',         'is_nullable' => true],
// Monthly by-week: 1=first, 2=second, 3=third, 4=fourth, -1=last
// NULL + monthly → same day-of-month as cal_start_utc

'cal_recurrence_end_date'     => ['type' => 'date',         'is_nullable' => true],
// NULL = no end; otherwise last date to generate instances on or before

// Exception replacement link (set on standalone entries that replace one skipped occurrence)
'cal_parent_entry_id'         => ['type' => 'int8',         'is_nullable' => true],
// FK to the recurring parent this replaces one occurrence of

'cal_parent_entry_date'       => ['type' => 'date',         'is_nullable' => true],
// The occurrence date this entry replaces (pairs with cal_parent_entry_id)

// External calendar interop (iCalendar import / future sync)
'cal_uid'                     => ['type' => 'varchar(255)',  'is_nullable' => true],
// iCal UID — uniquely identifies this entry across calendars; used for deduplication
// and delta sync on re-import. NULL for locally-created entries.

'cal_rrule_raw'               => ['type' => 'text',          'is_nullable' => true],
// Raw RRULE string from the iCal source (e.g. "FREQ=WEEKLY;BYDAY=MO,WE").
// Stored verbatim so complex patterns that don't map to the decomposed fields
// are preserved faithfully. NULL for locally-created entries.

'cal_source'                  => ['type' => 'varchar(50)',   'is_nullable' => true],
// Origin of this entry: 'google', 'proton', or NULL for locally-created.

'cal_source_event_id'         => ['type' => 'varchar(255)',  'is_nullable' => true],
// The event ID assigned by the external calendar service; paired with cal_source
// for sync matching on re-import.
```

`cal_recurrence_type IS NOT NULL` is the authoritative test for "is a recurring parent." No separate flag needed.

### New table: `cal_item_exceptions`

Stores dates to skip when expanding a recurring parent. One row per skipped occurrence (whether or not the user created a replacement entry).

```
cex_calendar_entry_exception_id  int8 PK serial
cex_cal_entry_id                 int8 NOT NULL FK → cal_items (recurring parent)
cex_exception_date               date NOT NULL
cex_create_time                  timestamp(6) default now()
```

Unique constraint on `(cex_cal_entry_id, cex_exception_date)`.

On hard-delete of the parent `cal_items` row, cascade-delete its exception rows.

---

## Data Model Additions

### `CalendarEntry` new methods

```php
/** True when this entry is a recurring parent. */
public function is_recurring_parent(): bool

/** Compute virtual instances in a UTC window.
 *  Returns CalendarItem[] — same type NativeCalendarItemSource already emits.
 *  @param string $start_utc  Y-m-d H:i:s UTC
 *  @param string $end_utc    Y-m-d H:i:s UTC
 *  @param string $visibility 'details' | 'busy'
 */
public function get_instances_for_range(
    string $start_utc,
    string $end_utc,
    string $visibility
): array

/** Pure date computation: which dates in [start_date, end_date] match this pattern?
 *  @param string $start_date Y-m-d
 *  @param string $end_date   Y-m-d
 *  @return string[]          Y-m-d dates
 *
 *  Edge-case rule: if the pattern day does not exist in a given month (e.g.
 *  "every 31st" in November, or "Feb 29" in a non-leap year), that month is
 *  skipped entirely — no clamping to the nearest valid date.
 */
public function compute_dates_in_range(string $start_date, string $end_date): array

/** Check whether a specific date matches the pattern. */
public function date_matches_pattern(string $date): bool

/** Human-readable recurrence description.
 *  e.g. "Every Monday and Wednesday" or "Every month on the 15th"
 */
public function get_recurrence_description(): string

```

### `get_instances_for_range()` algorithm

```
1. Compute occurrence dates in the window via compute_dates_in_range()
2. Load exception rows for this parent from cal_item_exceptions
3. For each date:
   a. Skip if it is in the exception set
   b. Build a CalendarItem value object (start/end adjusted to this date,
      all other fields from the parent row)
   c. Set source_key = 'native:cal-{parent_id}-{date}'
4. Return the CalendarItem array
```

`CalendarEntry` already stores both `cal_start_local` / `cal_end_local` (wall-clock) and `cal_start_utc` / `cal_end_utc` (derived cache), plus `cal_timezone` (IANA identifier). For each instance date, extract the wall-clock H:i:s from `cal_start_local` / `cal_end_local`, combine with the instance date, and convert to UTC using `cal_timezone`. This mirrors `Event::create_virtual_instance()` and is DST-safe: "yoga at 9am every Tuesday" stays at 9am local time across DST transitions because UTC is recomputed fresh per occurrence.

---

## NativeCalendarItemSource Update

The existing source iterates non-recurring entries for the subject. Extend it to also expand recurring parents:

```
1. Load non-recurring entries in the window (cal_recurrence_type IS NULL)
   → emit a CalendarItem per row (existing behavior)

2. Load recurring parents for the subject (cal_recurrence_type IS NOT NULL,
   deleted IS NULL, start before window end, end_date IS NULL or ≥ window start)
   → call get_instances_for_range() on each
   → emit the resulting CalendarItem array

All emitted items flow into the calendar feed and the busy projection unchanged.
```

The busy projection (and therefore `SlotGenerator`) sees recurring blocks automatically — "block every Tuesday 9–10am" eliminates those slots from availability with no extra wiring.

---

## Edit Scopes

When the user edits a recurring entry the UI presents three choices:

| Scope | Behaviour |
|---|---|
| **This occurrence only** | Add the occurrence date to `cal_item_exceptions`; create a standalone `cal_items` row (non-recurring) with the new values, `cal_parent_entry_id` / `cal_parent_entry_date` set for grouping. |
| **This and future occurrences** | Set `cal_recurrence_end_date` on the parent to one day before the chosen occurrence; create a new recurring parent starting from that date with the edited values. Copy any `cal_item_exceptions` rows from the original parent whose `cex_exception_date` falls on or after the split date to the new parent. |
| **All occurrences** | Update the parent row in place. No exceptions are created; existing exceptions are preserved. |

**Delete scopes** follow the same logic: this occurrence (exception only), this and future (end series), all (soft-delete the parent — also deletes its exception rows via cascade).

The edit-scope prompt is shown only when editing an occurrence of a recurring entry. Non-recurring entries have no scope prompt.

---

## Authoring UI

The native entry form on `/profile/calendar` gains a recurrence section. It appears below the time fields, collapsed behind a "Does not repeat" toggle.

```
Does not repeat  [toggle → "Repeats"]

[When repeats is on:]

Repeat:   [Daily ▾]     every [1] day(s)

[Weekly only:]
Repeat on:  [ ] Sun  [✓] Mon  [ ] Tue  [✓] Wed  [ ] Thu  [ ] Fri  [ ] Sat

[Monthly only:]
( ) On the same day each month
(✓) On the [2nd ▾] [Monday ▾] of the month

Ends:  (✓) Never
       ( ) On date  [____-__-__]
       ( ) After [__] occurrences
```

"After N occurrences" converts to an end date in JavaScript by reusing the same walk-forward logic already implemented in the events admin — extract and adapt that function rather than reimplementing it.

**Visibility rules** use FormWriter `visibility_rules` — no hand-rolled JS toggles.

When the user clicks a virtual occurrence on the calendar, the calendar view links to `/profile/calendar/entry/{parent_id}/occurrence/{date}` (date as `Y-m-d`). The logic layer detects the `occurrence/{date}` segment, loads the parent entry, verifies the date matches the recurrence pattern (or is a known replacement), and shows the scope-choice modal before the entry form loads. Standalone (non-recurring) entries continue to use `/profile/calendar/entry/{id}` with no occurrence segment and no modal.

---

## Phases

### Phase 1 — Data layer

1. Add recurrence fields to `CalendarEntry::$field_specifications`. Run `update_database`.
2. Create `cal_item_exceptions` table (data class + Multi, `surfaces:["data"]`). Run `update_database`.
3. Implement `is_recurring_parent()`, `date_matches_pattern()`, `compute_dates_in_range()`, `get_instances_for_range()`, `get_recurrence_description()`, `end_series()` on `CalendarEntry`.
4. Add `MultiCalendarEntry` filter: `recurring_only` (`cal_recurrence_type IS NOT NULL`), `non_recurring_only` (`IS NULL`).
5. Model CRUD tests: create recurring parent, verify instance expansion, verify exception skipping.

*Checkpoint:* a seeded recurring entry expands correctly over a date range in tests; exceptions suppress the right dates.

### Phase 2 — Source + busy projection

1. Update `NativeCalendarItemSource` to expand recurring parents alongside non-recurring entries.
2. Verify in tests: a recurring blocking entry removes the correct time slots from `SlotGenerator` output.

*Checkpoint:* "every Tuesday blocked" → Tuesday slots absent from availability.

### Phase 3 — Authoring UI

1. Add recurrence section to the native entry form (FormWriter, `visibility_rules` throughout).
2. Implement edit-scope modal (shown when editing a recurring occurrence).
3. Implement delete-scope for recurring entries.
4. Wire "after N occurrences" → end-date JS computation.

*Checkpoint:* user creates a recurring block in the browser; it appears on the calendar across multiple weeks; editing one occurrence and choosing "this only" shows the change on the right date only.

---

## Files

**Modify:** `data/calendar_entry_class.php` — recurrence fields + new methods.

**Create:** `data/calendar_entry_exception_class.php` (scaffold from manifest, `surfaces:["data"]`).

**Modify:** `includes/calendar/item_sources/NativeCalendarItemSource.php` — recurring expansion.

**Modify:** `views/profile/calendar.php` (or the native-entry component) — recurrence form section + edit-scope modal.
