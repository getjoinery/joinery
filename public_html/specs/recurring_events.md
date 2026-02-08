# Recurring Events Specification

## Overview

This specification defines the implementation of recurring events functionality for the Joinery platform. Users will be able to create events that automatically repeat on a schedule (daily, weekly, monthly, yearly) without manually creating each instance.

The system uses a **hybrid virtual/materialized instance** approach: recurring event occurrences are computed on-the-fly from the parent event's recurrence pattern for display, and only written to the database when an admin explicitly materializes them (to open registration, edit details, or cancel). This keeps the database lean while supporting full event functionality for any occurrence that needs it.

## Goals

1. Allow event creators to define recurrence patterns when creating/editing events
2. Compute event instances on-the-fly from recurrence patterns for display
3. Support standard recurrence patterns (daily, weekly, monthly, yearly)
4. Materialize instances into the database via admin action when ready for registration
5. Allow modifications to individual materialized instances without affecting the series
6. Support iCalendar RRULE format for calendar export compatibility
7. Maintain backwards compatibility with existing single events

## Key Concepts

### Parent Event
The original event record with `evt_recurrence_type` set (not null). Holds the recurrence pattern definition and serves as the template for all instances. The parent event does **not** appear in public event listings — only its instances (virtual or materialized) do.

### Virtual Instance
A computed occurrence that exists only in memory. Generated on-the-fly when querying a date range by applying the parent's recurrence pattern. Virtual instances inherit all properties from the parent (name, description, location, etc.) with adjusted start/end times. They have no database row and no `evt_event_id`.

### Materialized Instance
A virtual instance that has been written to the database as a real `evt_events` row. Created by an admin action (materialize, cancel, etc.). Once materialized, it is **completely independent** from the parent — it behaves like any normal event record with its own `evt_event_id`, registrants, sessions, photos, etc. Changes to the parent do not propagate to materialized instances.

### Materialization Triggers
Materialization is an **admin-initiated action**. A virtual instance is materialized (written to the database) when an admin explicitly:
- Clicks "Materialize" on a virtual instance from the parent event's admin view
- Cancels a specific virtual occurrence (materializes then sets status to cancelled)

Public users never trigger materialization. Virtual instances appear on public pages as read-only event listings with registration closed. Once an admin materializes an instance, it becomes a normal event and the admin can open registration, edit details, etc.

## Database Schema

### New Fields in `evt_events` Table

Add to `Event` class `$field_specifications`:

```php
// Recurrence pattern fields (set on parent events)
'evt_recurrence_type' => array('type' => 'varchar(20)', 'is_nullable' => true),
// Values: 'daily', 'weekly', 'monthly', 'yearly'
// If set (not null), this event is a recurring parent. No separate is_recurring flag needed.

'evt_recurrence_interval' => array('type' => 'integer', 'default' => 1),
// Every X days/weeks/months/years

'evt_recurrence_days_of_week' => array('type' => 'varchar(20)', 'is_nullable' => true),
// For weekly: comma-separated days (0=Sun, 1=Mon, etc.) e.g., "1,3,5" for Mon/Wed/Fri

'evt_recurrence_week_of_month' => array('type' => 'integer', 'is_nullable' => true),
// For monthly by-week: 1=first, 2=second, 3=third, 4=fourth, -1=last
// Day of week is inferred from parent's evt_start_time.
// If NULL and recurrence_type is 'monthly', recurs on the same day-of-month as evt_start_time.

'evt_recurrence_end_date' => array('type' => 'date', 'is_nullable' => true),
// End recurrence on this date. NULL = no end date (recurs forever).
// When admin selects "after N occurrences", the UI computes the Nth occurrence
// date and stores it here. Core logic only checks this one field.

// Instance relationship fields (set on materialized instances)
'evt_parent_event_id' => array('type' => 'integer', 'is_nullable' => true),
// References the parent recurring event

'evt_materialized_instance_date' => array('type' => 'date', 'is_nullable' => true),
// The specific date this instance represents (unique per parent)
```

**7 fields total** (down from 15 in the original design). The following were eliminated:
- `evt_is_recurring` — inferred from `evt_recurrence_type IS NOT NULL`
- `evt_recurrence_end_type` — inferred: `end_date` set = has end, null = never ends
- `evt_recurrence_count` — when admin selects "after N occurrences", UI computes the end date and stores in `evt_recurrence_end_date`
- `evt_recurrence_rrule` — computed on-the-fly for iCal export, not stored
- `evt_recurrence_month_of_year` — inferred from the parent's `evt_start_time` month for yearly patterns
- `evt_recurrence_day_of_month` — inferred from the parent's `evt_start_time` day-of-month for monthly by-date patterns
- `evt_instance_modified` — no functional purpose since materialized instances are fully independent
- `evt_instance_cancelled` — use existing `evt_status = STATUS_CANCELED` instead

### Index Additions

```sql
CREATE INDEX idx_evt_parent_event_id ON evt_events(evt_parent_event_id);
CREATE INDEX idx_evt_materialized_instance_date ON evt_events(evt_materialized_instance_date);
CREATE INDEX idx_evt_recurrence_type ON evt_events(evt_recurrence_type) WHERE evt_recurrence_type IS NOT NULL;
CREATE UNIQUE INDEX idx_evt_parent_instance_date ON evt_events(evt_parent_event_id, evt_materialized_instance_date);
```

The unique index on `(evt_parent_event_id, evt_materialized_instance_date)` prevents duplicate materialized instances for the same date. The partial index on `evt_recurrence_type` efficiently finds recurring parents without indexing every non-recurring event.

## Data Model Changes

### Event Class Additions

```php
// In /data/events_class.php

/**
 * Compute virtual instances for a date range
 * Returns in-memory Event-like objects with adjusted start/end times.
 * Does NOT write anything to the database.
 * Merges with any materialized instances that exist in the range.
 *
 * @param string $start_date Start of range (Y-m-d)
 * @param string $end_date End of range (Y-m-d)
 * @return array Array of Event objects (materialized) and stdClass objects (virtual)
 */
public function get_instances_for_range($start_date, $end_date);

/**
 * Materialize a virtual instance into a real database record
 * Creates an evt_events row copying parent fields with adjusted dates.
 * If an instance for this date already exists, returns the existing one.
 *
 * @param string $instance_date The occurrence date to materialize (Y-m-d)
 * @return Event The materialized Event object (saved, with evt_event_id)
 */
public function materialize_instance($instance_date);

/**
 * Compute the next N occurrence dates from a starting point
 * Pure date calculation — no database interaction.
 *
 * @param string $from_date Starting date (Y-m-d)
 * @param int $count Number of dates to compute
 * @return array Array of date strings (Y-m-d)
 */
public function compute_occurrence_dates($from_date, $count);

/**
 * Get all materialized instances from the database
 * Returns only instances that have been written to the DB.
 *
 * @param string $start_date Optional start filter
 * @param string $end_date Optional end filter
 * @return MultiEvent
 */
public function get_materialized_instances($start_date = null, $end_date = null);

/**
 * End the recurring series from a given date forward
 * Sets evt_recurrence_end_date on the parent so virtual instances
 * stop being computed. Does NOT affect materialized instances
 * (they are independent).
 *
 * @param string $end_date Stop generating instances on/after this date (Y-m-d)
 */
public function end_series($end_date = null);

/**
 * Check if this event is a materialized instance of a recurring series
 * True when evt_parent_event_id is set.
 *
 * @return bool
 */
public function is_instance();

/**
 * Check if this is the parent/template of a recurring series
 * True when evt_recurrence_type is not null.
 *
 * @return bool
 */
public function is_recurring_parent();

/**
 * Get the parent event if this is a materialized instance
 *
 * @return Event|null
 */
public function get_parent_event();

/**
 * Get a human-readable description of the recurrence pattern
 * e.g., "Every Monday, Wednesday, and Friday until Dec 31, 2025"
 *
 * @return string
 */
public function get_recurrence_description();

/**
 * Check if a specific date matches the recurrence pattern
 *
 * @param string $date Date to check (Y-m-d)
 * @return bool
 */
public function date_matches_pattern($date);

/**
 * Create a virtual instance object for a given date
 * Returns a stdClass with all parent fields and adjusted times.
 * Not saved to DB — used for display purposes.
 *
 * @param string $instance_date The occurrence date (Y-m-d)
 * @return stdClass Virtual event object
 */
protected function create_virtual_instance($instance_date);
```

### Virtual Instance Object Structure

Virtual instances are `stdClass` objects (not full `Event` instances) with the following properties:

```php
$virtual->is_virtual = true;              // Flag to distinguish from real Event objects
$virtual->parent_event_id = 123;          // Parent event's ID
$virtual->instance_date = '2025-03-05';   // This occurrence's date
$virtual->evt_name = 'Weekly Dance Class'; // Inherited from parent
$virtual->evt_description = '...';        // Inherited from parent
$virtual->evt_start_time = '2025-03-05 19:00:00'; // Adjusted to this date
$virtual->evt_end_time = '2025-03-05 21:00:00';   // Adjusted to this date
$virtual->evt_location = '...';           // Inherited from parent
// ... all other display fields inherited from parent
$virtual->evt_event_id = null;            // No DB row yet
```

**Inheritance on display:** Virtual instances inherit **everything** from the parent — name, description, location, leader, photos, sessions, visibility. They are a complete read-only projection of the parent with adjusted dates. Registration is always closed for virtual instances.

Views check `is_virtual` to suppress registration UI. Only materialized instances (real Event objects) can have registration open.

### Inheritance on Materialization

When a virtual instance is materialized, **all parent fields are copied** to the new event row:
- Display fields: name, description, short description, location, leader, photos, visibility, event type
- Registration settings: accepting signups, max signups, waiting list, collect extra info, external register link
- Product linkage: inherited from parent (admin can change later)
- Sessions: parent's sessions are **not** copied — the materialized instance starts with no sessions (sessions can be added independently)
- Photos: `evt_fil_file_id` (primary image) is copied; entity photos are not copied
- Bundle group: `evt_grp_group_id` is copied as informational metadata

**Once materialized, the instance is fully independent.** Subsequent changes to the parent (name, time, description, etc.) do **not** propagate to existing materialized instances. The `evt_parent_event_id` field is retained for reference/navigation only.

### MultiEvent Class Additions

```php
// In /data/events_class.php (MultiEvent)

// Add filter options:
'parent_event_id' => $parent_id,        // Get materialized instances of specific series
'exclude_recurring_parents' => true,     // Exclude parent events from results (for public listings)
                                         // Filters: evt_recurrence_type IS NULL
```

## Recurrence Logic

### Date Computation Algorithm

The core of the system is pure date math — given a recurrence pattern and a date range, compute which dates match.

```php
// Pseudo-code for get_instances_for_range()

function get_instances_for_range($start_date, $end_date) {
    if (!$this->is_recurring_parent()) {
        return [];
    }

    // 1. Compute all occurrence dates in the range
    $dates = $this->compute_dates_in_range($start_date, $end_date);

    // 2. Load any materialized instances in this range from DB
    $materialized = $this->get_materialized_instances($start_date, $end_date);
    $materialized_by_date = [];
    foreach ($materialized as $instance) {
        $materialized_by_date[$instance->get('evt_materialized_instance_date')] = $instance;
    }

    // 3. Merge: use materialized instance if it exists, otherwise create virtual
    $instances = [];
    foreach ($dates as $date) {
        if (isset($materialized_by_date[$date])) {
            $instance = $materialized_by_date[$date];
            // Skip cancelled instances
            if ($instance->get('evt_status') != Event::STATUS_CANCELED) {
                $instances[] = $instance;
            }
        } else {
            // Create virtual instance (in-memory only)
            $instances[] = $this->create_virtual_instance($date);
        }
    }

    return $instances;
}
```

### Materialization Flow

```php
// Pseudo-code for materialize_instance()

// Fields to skip when copying parent to instance
const RECURRENCE_FIELDS = [
    'evt_recurrence_type', 'evt_recurrence_interval', 'evt_recurrence_days_of_week',
    'evt_recurrence_week_of_month', 'evt_recurrence_end_date'
];

function materialize_instance($instance_date) {
    // Check if already materialized
    $existing = $this->get_materialized_instance_for_date($instance_date);
    if ($existing) {
        return $existing;
    }

    // Verify date matches pattern
    if (!$this->date_matches_pattern($instance_date)) {
        throw new Exception("Date does not match recurrence pattern");
    }

    // Copy all parent fields except recurrence fields (same pattern as Event::copy())
    $instance = new Event(NULL);
    foreach (self::$field_specifications as $field => $spec) {
        if (!in_array($field, self::RECURRENCE_FIELDS)) {
            $instance->set($field, $this->get($field));
        }
    }

    // Set instance-specific fields
    $instance->set('evt_parent_event_id', $this->key);
    $instance->set('evt_materialized_instance_date', $instance_date);

    // Adjust start/end times to the instance date
    $day_offset = date_diff(date_create($this->get('evt_start_time')), date_create($instance_date));
    $instance->set('evt_start_time', /* parent start + offset */);
    $instance->set('evt_end_time', /* parent end + offset */);

    $instance->save();
    return $instance;
}
```

### Pattern Matching

| Recurrence Type | Pattern Logic |
|-----------------|---------------|
| Daily | Every N days from start date |
| Weekly | On specified days of week, every N weeks |
| Monthly (by date) | On day N of month, every N months |
| Monthly (by week) | On Nth weekday of month (e.g., 2nd Tuesday) |
| Yearly | On specific month/day, every N years |

### Registration Flow

Registration requires a materialized instance — virtual instances cannot accept registrations. Materialization is admin-initiated.

1. Admin views the parent event's admin page and sees upcoming occurrences
2. Admin clicks "Materialize" on a virtual instance (e.g., March 5)
3. System calls `$parent->materialize_instance('2025-03-05')` to create the DB row
4. The materialized instance inherits the parent's product linkage and registration settings
5. Admin can now open registration, adjust details, etc. — it's a normal event
6. Users see the materialized instance with registration open and can register normally
7. `EventRegistrant` attaches to the materialized instance's `evt_event_id`

### URL Routing

Virtual instances have no `evt_event_id` or slug of their own. They are addressed via the parent event's slug plus the occurrence date:

```
/event/{parent-slug}/{date}
```

**Examples:**
- `/event/weekly-dance-class/2025-03-05` — specific occurrence (virtual or materialized)
- `/event/weekly-dance-class` — for recurring parents, redirects to next upcoming instance

**Route added to `serve.php`:**
```php
'/event/{slug}/{date}' => [
    'view' => 'views/event',
    'check_setting' => 'events_active'
]
```

Resolution logic is detailed in the **Public-Facing UI** section below.

## Admin Interface

### Event Edit Form — Recurrence Section

**Location in form:** New card section placed immediately after the Start Time / End Time fields in `/adm/admin_event_edit.php`. The recurrence section is scheduling-related, so it belongs right after the "when" fields.

**Form fields using FormWriter V2 model-based binding:**

```
Recurrence
─────────────────────────────────────────────
Repeat:  [None v]          Every [1] [day(s) v]
         (None/Daily/Weekly/Monthly/Yearly)

[Shown when Weekly selected:]
Repeat on:  [ ] Sun  [x] Mon  [ ] Tue  [x] Wed  [ ] Thu  [x] Fri  [ ] Sat

[Shown when Monthly selected:]
( ) On the same day each month (e.g., the 15th)
(x) On the [2nd v] [Tuesday v] of the month

Ends:  ( ) Never
       (x) On date  [2025-12-31]
       ( ) After [10] occurrences
```

**Field mapping:**
- "Repeat" dropdown → `evt_recurrence_type` (null for "None", otherwise 'daily'/'weekly'/'monthly'/'yearly')
- "Every N" input → `evt_recurrence_interval`
- Day-of-week checkboxes → `evt_recurrence_days_of_week` (comma-separated: "1,3,5")
- Monthly week-of-month dropdown → `evt_recurrence_week_of_month` (null for by-date, 1-4 or -1 for by-week)
- End date → `evt_recurrence_end_date`
- "After N occurrences" → JavaScript computes the Nth date using the pattern and stores in `evt_recurrence_end_date`

**Visibility rules (JavaScript):**
- All recurrence fields hidden when type is "None"
- Day-of-week checkboxes shown only for "Weekly"
- Monthly options shown only for "Monthly"
- End date input shown when "On date" radio selected
- Occurrence count input shown when "After N" radio selected; on change, JS computes end date

**When editing a materialized instance:** The recurrence section is **not shown** — it's a normal event. Only parent events show the recurrence form.

### Admin Event Listing (`/adm/admin_events.php`)

**Changes to existing listing:**
- Recurring parent events show with a small repeat icon (e.g., `fa-repeat` or `fa-sync`) next to the event name
- Materialized instances appear as normal events in the listing (they are normal events)
- Add a tab filter alongside the existing "Future Events" / "All Events" tabs:
  - "Future Events" (default) — regular events + materialized instances, excludes parents (`exclude_recurring_parents`)
  - "All Events" — everything including parents
  - "Series" — only recurring parents (`evt_recurrence_type IS NOT NULL`)

### Parent Event Admin Detail View (`/adm/admin_event.php`)

When viewing a recurring parent event, the existing event detail page is extended with a **"Series Occurrences" card** section. This section appears after the event details card.

**Series info header:**
- Recurrence pattern in human-readable text (e.g., "Every Monday and Wednesday, starting Jan 6, 2025")
- "Edit Series" link to edit the parent event
- "End Series" button (sets `evt_recurrence_end_date` to today)

**Occurrences table** showing next 20 computed dates:

```
Upcoming Occurrences
───────────────────────────────────────────────────────────
Date              Status          Registrants    Actions
───────────────────────────────────────────────────────────
Mar 5, 2025       Materialized    12/30         View | Edit
Mar 12, 2025      Virtual         —             Materialize | Cancel
Mar 19, 2025      Virtual         —             Materialize | Cancel
Mar 26, 2025      Cancelled       —             —
Apr 2, 2025       Virtual         —             Materialize | Cancel
───────────────────────────────────────────────────────────
```

- **Status column**: "Virtual" (no DB row), "Materialized" (linked to the event), or "Cancelled"
- **Registrants column**: Shows count for materialized instances, "—" for virtual
- **Actions**:
  - "Materialize" — creates the DB row, redirects to the new event's edit page
  - "Cancel" — materializes (if virtual) and sets `evt_status = STATUS_CANCELED`
  - "View" / "Edit" — links to the materialized instance's admin pages

### Editing Instances vs. Parent

**Materialized instances are fully independent.** Editing one is exactly like editing any normal event — no scope dialog, no propagation. The recurrence form section is not shown for instances.

**Editing the parent** changes the template that future virtual instances are computed from. This is done via the parent's edit page. Changes to the parent do **not** propagate to existing materialized instances.

**To change the recurrence pattern itself** (e.g., Mon/Wed/Fri to Tue/Thu), end the current series by setting `evt_recurrence_end_date` and create a new parent event with the new pattern.

### Instance Cancel/Delete Behavior

**Cancelling a single occurrence (from the parent's occurrences table):**
- If virtual: materialize it, then set `evt_status = STATUS_CANCELED`. The cancelled instance is skipped in `get_instances_for_range()`.
- If materialized: set `evt_status = STATUS_CANCELED` (standard event cancellation).

**Ending the series from a date forward:**
- Set parent's `evt_recurrence_end_date` to stop before the chosen date
- Future virtual instances simply stop being computed
- Existing materialized instances are **not affected** (they are independent and must be cancelled individually)

**Ending the entire series:**
- Soft-delete the parent or clear `evt_recurrence_type` to null
- All virtual instances stop being computed
- Existing materialized instances remain as standalone events

## Calendar Integration

### iCalendar Export

Export the parent event with RRULE — the receiving calendar app handles instance generation:

```
BEGIN:VEVENT
UID:event-123@joinerytest.site
DTSTART:20250115T190000Z
DTEND:20250115T210000Z
SUMMARY:Weekly Dance Class
RRULE:FREQ=WEEKLY;BYDAY=MO,WE,FR;UNTIL=20251231T235959Z
END:VEVENT
```

Modified materialized instances export as separate VEVENT entries with `RECURRENCE-ID`.
Cancelled instances export as `EXDATE` on the parent VEVENT.

### Google Calendar Sync

When syncing with Google Calendar:
- Export parent event with RRULE (Google handles instance generation)
- Sync modified instances with RECURRENCE-ID
- Sync cancelled instances as EXDATE

## API Considerations

### Events API Endpoints

```
GET /api/events
  - Default: returns non-recurring events + virtual/materialized instances (not parents)
  - Add parameter: show_parents=true to include parent events
  - Add parameter: expand_range_start / expand_range_end to control virtual instance range

GET /api/events/{id}
  - Returns event with recurrence info
  - If instance, includes parent_event_id
  - If parent, includes recurrence pattern and next N upcoming dates

POST /api/events
  - Accept recurrence fields to create a recurring parent event
  - Returns the parent event (instances are computed on demand, not pre-created)

PUT /api/events/{id}
  - Add parameter: update_scope=single|future|all
  - 'single' on a virtual instance materializes it first, then applies the edit

DELETE /api/events/{id}
  - Add parameter: delete_scope=single|future|all
  - 'single' on a virtual instance materializes it and marks cancelled
```

## Migration Plan

### Phase 1: Database Schema
1. Add new fields to Event class `$field_specifications`
2. Run database update to create columns
3. Add indexes (including unique constraint on parent_id + instance_date)

### Phase 2: Core Logic
1. Implement date computation methods (`compute_occurrence_dates()`, `date_matches_pattern()`)
2. Implement `create_virtual_instance()` and `get_instances_for_range()`
3. Implement `materialize_instance()`
4. Implement `get_recurrence_description()`
5. Implement `end_series()`

### Phase 3: Admin UI
1. Add recurrence card section to event edit form (after start/end time fields)
2. Implement JavaScript for dynamic show/hide of pattern-specific fields
3. Implement JavaScript for "After N occurrences" → end date computation
4. Add "Series Occurrences" card to admin event detail page for recurring parents
5. Add Materialize/Cancel actions for virtual instances in occurrences table
6. Add repeat icon indicator to recurring parents in admin event listing
7. Add "Series" tab filter to admin event listing
8. Hide recurrence form section when editing materialized instances

### Phase 4: Public Display Integration
1. Update `events_logic.php` to merge virtual instances with regular events and sort by date
2. Update `event_logic.php` to handle `/event/{slug}/{date}` route with virtual instance resolution
3. Suppress registration UI for virtual instances on event detail page
4. Ensure virtual instances render identically to regular events in listings (no visual distinction)

### Phase 5: Calendar Export
1. Implement `get_rrule()` and `set_from_rrule()` on Event class
2. Update iCal export with RRULE support for parent events
3. Export modified instances with RECURRENCE-ID
4. Export cancelled instances as EXDATE
5. Test with Google Calendar, Outlook, Apple Calendar

### Phase 6: Documentation
1. Add recurring events section to `CLAUDE.md` under "Architecture Patterns" covering:
   - How to check if an event is a recurring parent (`evt_recurrence_type IS NOT NULL`)
   - How to check if an event is a materialized instance (`evt_parent_event_id IS NOT NULL`)
   - The `materialize_instance()` pattern and when it's triggered
   - MultiEvent filter: `exclude_recurring_parents` for public listings
2. Add recurring events route (`/event/{slug}/{date}`) to the routing section of `CLAUDE.md`
3. No changes needed to existing docs (photo_system.md, logic_architecture.md, formwriter.md — all still valid)

## Edge Cases

1. **Timezone handling**: Store recurrence pattern in event's timezone, convert when displaying
2. **DST transitions**: Handle days that don't exist (e.g., 2am during spring forward)
3. **Month length**: Handle "31st of month" for months with fewer days (use last day)
4. **Leap years**: Handle Feb 29 for yearly recurrence
5. **Registration capacity**: Each materialized instance tracks its own registration count independently
6. **Past instances**: Don't compute virtual instances before event creation date
7. **Orphaned instances**: If parent is deleted, materialized instances become standalone events (clear `evt_parent_event_id`)
8. **Concurrent materialization**: Use the unique index on `(parent_event_id, instance_date)` to prevent race conditions where two requests try to materialize the same instance simultaneously — second insert fails gracefully and returns the existing row
9. **Pattern changes**: To change a recurrence pattern, end the current series (set `evt_recurrence_end_date`) and create a new parent event with the new pattern. This avoids orphaned materialized instances and keeps the history clean. Existing materialized instances from the old pattern remain as standalone events.
10. **"Never ends" series**: Virtual instances are computed only for the requested date range, so infinite series cost nothing in storage. A sensible display limit (e.g., show next 6 months) prevents unbounded computation.

## Public-Facing UI

### Public Event Listing (`/views/events.php` + `/logic/events_logic.php`)

**Merge-and-sort flow in logic layer:**

The current logic queries `MultiEvent(['upcoming' => true])`. With recurring events:

```php
// 1. Get regular events + materialized instances (exclude parent templates)
$events = new MultiEvent([
    'upcoming' => true,
    'status' => Event::STATUS_ACTIVE,
    'exclude_recurring_parents' => true,
    'deleted' => false
], ['evt_start_time' => 'ASC']);
$events->load();
$all_events = iterator_to_array($events);

// 2. Get recurring parents to compute virtual instances
$parents = new MultiEvent([
    'status' => Event::STATUS_ACTIVE,
    'deleted' => false
], []);
// Filter to only recurring parents (evt_recurrence_type IS NOT NULL)
// Use a date range for virtual instance computation
$range_end = date('Y-m-d', strtotime('+6 months'));

foreach ($parents as $parent) {
    if ($parent->is_recurring_parent()) {
        $virtual_instances = $parent->get_instances_for_range(date('Y-m-d'), $range_end);
        foreach ($virtual_instances as $instance) {
            $all_events[] = $instance;
        }
    }
}

// 3. Sort merged array by start time
usort($all_events, function($a, $b) {
    $a_time = is_object($a) && isset($a->is_virtual) ? $a->evt_start_time : $a->get('evt_start_time');
    $b_time = is_object($b) && isset($b->is_virtual) ? $b->evt_start_time : $b->get('evt_start_time');
    return strcmp($a_time, $b_time);
});

// 4. Paginate the merged result
```

**Display:** Virtual instances render as normal event cards — same card layout, same styling. The card links to `/event/{parent-slug}/{date}`. No visual distinction from regular events.

### Public Event Detail (`/views/event.php` + `/logic/event_logic.php`)

**For virtual instances** (accessed via `/event/{slug}/{date}`):
- Displays identically to any other event — name, time, location, description, leader, photos
- All data inherited from parent with adjusted dates
- **No registration section** — registration UI is suppressed (no register button, no waiting list)
- No "recurring series" labels or badges — the user sees a normal event page

**For materialized instances** (accessed via same URL or direct link):
- Displays as a completely normal event
- Registration section shown if `evt_is_accepting_signups` is true (admin controls this)
- No visual indication that it's part of a series — it's just an event

**Resolution logic in `event_logic.php`:**
1. Look up event by slug
2. If `{date}` parameter is present:
   a. The slug identifies the parent event
   b. Check if a materialized instance exists for that date
   c. If materialized: display that instance (full Event object with normal registration behavior)
   d. If not materialized: verify date matches pattern, display virtual instance (no registration)
   e. If date doesn't match pattern: 404
3. If no `{date}` parameter: existing behavior for non-recurring events; for recurring parents, redirect to next upcoming instance

## Testing Requirements

1. Unit tests for date computation (all recurrence types, edge cases)
2. Unit tests for pattern matching (`date_matches_pattern()`)
3. Integration tests for materialization (concurrent access, duplicate prevention)
4. Integration tests for admin materialize/cancel actions
5. Integration tests for public listing merge-and-sort with virtual instances
6. Integration tests for `/event/{slug}/{date}` route resolution
7. UI tests for recurrence form (show/hide logic, "After N" computation)
8. Performance tests for large date ranges with "never ends" series

## Explicitly Deferred Features

The following features are intentionally excluded from this implementation to manage complexity. They may be revisited in future iterations.

### Event Bundles + Recurring Events
Recurring parent events **cannot** be added to event bundles (`grm_group_members`). The bundle purchase flow (`cart_charge_logic.php`) iterates bundle members and calls `add_registrant()` on each — this would require detecting recurring parents, deciding which instances to register for, and materializing them, which adds significant complexity to a payment-critical code path. `evt_grp_group_id` is copied to materialized instances as informational metadata only.

**Workaround:** To include recurring event occurrences in a bundle, manually materialize the specific instances and add those individual events to the bundle.

### Event Copy for Recurring Parents
The existing `Event::copy()` method is not supported for recurring parent events. Copying a parent with all its recurrence configuration and potentially many materialized instances is complex and unlikely to be needed. Individual materialized instances can be copied normally.

### Notifications/Messages Across Series
Materialized instances are fully independent. There is no mechanism to send messages or notifications to registrants across all instances of a series. Each materialized instance manages its own registrant communications independently, just like any standalone event.

### Custom Recurrence Type
The `'custom'` recurrence type (direct RRULE entry) is deferred. Only standard patterns are supported: daily, weekly, monthly, yearly.

## Future Enhancements

1. Complex RRULE patterns (BYSETPOS, BYMONTHDAY combinations)
2. Exception dates (EXDATE) - specific dates to skip without materializing
3. Recurrence rule builder UI with visual preview of upcoming dates
4. Bulk operations on series
5. Series templates (save recurrence patterns for reuse)
6. Bundle purchase support for recurring events (auto-materialize and register for next N instances)
7. Cross-series notifications (message all registrants across a recurring series)
