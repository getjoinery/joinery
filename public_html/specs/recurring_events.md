# Recurring Events Specification

## Overview

This specification defines the implementation of recurring events functionality for the Joinery platform. Users will be able to create events that automatically repeat on a schedule (daily, weekly, monthly, yearly) without manually creating each instance.

The system uses a **hybrid virtual/materialized instance** approach: recurring event occurrences are computed on-the-fly from the parent event's recurrence pattern and only written to the database when something attaches to them (registration, admin edit, cancellation, etc.). This keeps the database lean while supporting full event functionality for any occurrence that needs it.

## Goals

1. Allow event creators to define recurrence patterns when creating/editing events
2. Compute event instances on-the-fly from recurrence patterns for display
3. Support standard recurrence patterns (daily, weekly, monthly, yearly)
4. Materialize instances into the database only when needed (registration, edit, cancellation)
5. Allow modifications to individual materialized instances without affecting the series
6. Support iCalendar RRULE format for calendar export compatibility
7. Maintain backwards compatibility with existing single events

## Key Concepts

### Parent Event
The original event record with `evt_is_recurring = true`. Holds the recurrence pattern definition and serves as the template for all instances. The parent event does **not** appear in public event listings — only its instances (virtual or materialized) do.

### Virtual Instance
A computed occurrence that exists only in memory. Generated on-the-fly when querying a date range by applying the parent's recurrence pattern. Virtual instances inherit all properties from the parent (name, description, location, etc.) with adjusted start/end times. They have no database row and no `evt_event_id`.

### Materialized Instance
A virtual instance that has been written to the database as a real `evt_events` row. Created automatically when something needs to attach to the instance. Once materialized, it is **completely independent** from the parent — it behaves like any normal event record with its own `evt_event_id`, registrants, sessions, photos, etc. Changes to the parent do not propagate to materialized instances.

### Materialization Triggers
A virtual instance is materialized (written to the database) when any of the following occurs:
- A user registers for the occurrence
- An admin edits the specific occurrence (time change, description, etc.)
- An admin cancels the specific occurrence
- A session, photo, or other entity is attached to the occurrence

## Database Schema

### New Fields in `evt_events` Table

Add to `Event` class `$field_specifications`:

```php
// Recurrence pattern fields (set on parent events)
'evt_is_recurring' => array('type' => 'boolean', 'default' => false),
'evt_recurrence_type' => array('type' => 'varchar(20)', 'is_nullable' => true),
// Values: 'daily', 'weekly', 'monthly', 'yearly'

'evt_recurrence_interval' => array('type' => 'integer', 'default' => 1),
// Every X days/weeks/months/years

'evt_recurrence_days_of_week' => array('type' => 'varchar(20)', 'is_nullable' => true),
// For weekly: comma-separated days (0=Sun, 1=Mon, etc.) e.g., "1,3,5" for Mon/Wed/Fri

'evt_recurrence_day_of_month' => array('type' => 'integer', 'is_nullable' => true),
// For monthly: specific day (1-31)

'evt_recurrence_week_of_month' => array('type' => 'integer', 'is_nullable' => true),
// For monthly: 1=first, 2=second, 3=third, 4=fourth, -1=last

'evt_recurrence_month_of_year' => array('type' => 'integer', 'is_nullable' => true),
// For yearly: specific month (1-12)

'evt_recurrence_end_type' => array('type' => 'varchar(10)', 'is_nullable' => true),
// Values: 'never', 'date', 'count'

'evt_recurrence_end_date' => array('type' => 'date', 'is_nullable' => true),
// End recurrence on this date

'evt_recurrence_count' => array('type' => 'integer', 'is_nullable' => true),
// End after X occurrences

'evt_recurrence_rrule' => array('type' => 'text', 'is_nullable' => true),
// Full iCalendar RRULE string for complex patterns and export

// Instance relationship fields (set on materialized instances)
'evt_parent_event_id' => array('type' => 'integer', 'is_nullable' => true),
// References the parent recurring event

'evt_instance_date' => array('type' => 'date', 'is_nullable' => true),
// The specific date this instance represents (unique per parent)

'evt_instance_modified' => array('type' => 'boolean', 'default' => false),
// True if this instance has been modified from the series defaults

'evt_instance_cancelled' => array('type' => 'boolean', 'default' => false),
// True if this specific instance is cancelled (exception)
```

### Index Additions

```sql
CREATE INDEX idx_evt_parent_event_id ON evt_events(evt_parent_event_id);
CREATE INDEX idx_evt_instance_date ON evt_events(evt_instance_date);
CREATE INDEX idx_evt_is_recurring ON evt_events(evt_is_recurring);
CREATE UNIQUE INDEX idx_evt_parent_instance_date ON evt_events(evt_parent_event_id, evt_instance_date);
```

The unique index on `(evt_parent_event_id, evt_instance_date)` prevents duplicate materialized instances for the same date.

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
 * Convert recurrence settings to iCalendar RRULE string
 *
 * @return string RRULE string
 */
public function get_rrule();

/**
 * Parse iCalendar RRULE string and set recurrence fields
 *
 * @param string $rrule RRULE string to parse
 */
public function set_from_rrule($rrule);

/**
 * Check if this event is a materialized instance of a recurring series
 *
 * @return bool
 */
public function is_instance();

/**
 * Check if this is the parent/template of a recurring series
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

**Inheritance on display:** Virtual instances inherit **everything** from the parent — name, description, location, leader, photos, sessions, visibility, registration settings. They are a complete read-only projection of the parent with adjusted dates.

Views should check `is_virtual` to determine whether the instance can accept registrations directly (materialized) or needs materialization first. The registration/edit flows handle this transparently.

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
'parent_event_id' => $parent_id,  // Get materialized instances of specific series
'exclude_recurring_parents' => true,  // Exclude parent events from results (for public listings)
'exclude_instances' => true,       // Exclude materialized instances from results
'only_recurring_parents' => true,  // Get only parent events (for admin series management)
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
        $materialized_by_date[$instance->get('evt_instance_date')] = $instance;
    }

    // 3. Merge: use materialized instance if it exists, otherwise create virtual
    $instances = [];
    foreach ($dates as $date) {
        if (isset($materialized_by_date[$date])) {
            $instance = $materialized_by_date[$date];
            // Skip cancelled instances
            if (!$instance->get('evt_instance_cancelled')) {
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

    // Create new Event row copying parent fields
    $instance = new Event(NULL);
    $instance->set('evt_parent_event_id', $this->key);
    $instance->set('evt_instance_date', $instance_date);
    $instance->set('evt_name', $this->get('evt_name'));
    $instance->set('evt_description', $this->get('evt_description'));
    $instance->set('evt_location', $this->get('evt_location'));
    // ... copy all relevant display/config fields ...

    // Adjust start/end times to the instance date
    $time_offset = $this->calculate_time_offset($instance_date);
    $instance->set('evt_start_time', $adjusted_start);
    $instance->set('evt_end_time', $adjusted_end);
    $instance->set('evt_timezone', $this->get('evt_timezone'));

    // Inherit registration settings
    $instance->set('evt_is_accepting_signups', $this->get('evt_is_accepting_signups'));
    $instance->set('evt_max_signups', $this->get('evt_max_signups'));
    $instance->set('evt_allow_waiting_list', $this->get('evt_allow_waiting_list'));
    // ... etc ...

    // Do NOT copy recurrence fields — instances are not themselves recurring
    // evt_is_recurring stays false (default)

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

Products can be universal (shared across events) or per-event. The event points to its product. Registration requires a materialized instance — virtual instances cannot accept registrations directly.

1. User sees "Weekly Dance Class — March 5" in the event listing (virtual instance)
2. User clicks register
3. System calls `$parent->materialize_instance('2025-03-05')` to create the DB row
4. The materialized instance inherits the parent's product linkage (same product reference)
5. Registration proceeds normally — `EventRegistrant` attaches to the materialized instance's `evt_event_id`
6. From this point forward, the March 5 occurrence is a real event record with its own registrants, capacity tracking, etc.

An admin can later change a materialized instance's product linkage if needed (e.g., different pricing for a special occurrence).

### URL Routing

Virtual instances have no `evt_event_id` or slug of their own. They are addressed via the parent event's slug plus the occurrence date:

```
/event/{parent-slug}/{date}
```

**Examples:**
- `/event/weekly-dance-class/2025-03-05` — specific occurrence (virtual or materialized)
- `/event/weekly-dance-class` — the parent event (admin/series view, or redirects to next upcoming instance for public)

**Route added to `serve.php`:**
```php
'/event/{slug}/{date}' => [
    'view' => 'views/event',
    'check_setting' => 'events_active'
]
```

**Resolution logic in `event_logic.php`:**
1. Look up parent event by slug
2. If `{date}` parameter is present:
   a. Check if a materialized instance exists for that date (`evt_parent_event_id` + `evt_instance_date`)
   b. If materialized: display that instance (full Event object)
   c. If not materialized: verify date matches pattern, display virtual instance
   d. If date doesn't match pattern: 404
3. If no `{date}` parameter: existing behavior for non-recurring events; for recurring parents, redirect to next upcoming instance or show series overview

**Materialized instances** can also be accessed directly by their own `evt_event_id` in admin contexts, but the canonical public URL is always `/event/{parent-slug}/{date}`.

## Admin Interface

### Event Edit Form Additions

Add a "Recurrence" section to `/adm/admin_event_edit.php`:

```
[ ] This is a recurring event

Repeat: [Daily v]  Every [1] day(s)

Weekly options (shown when weekly selected):
  Repeat on: [ ] Sun [x] Mon [ ] Tue [x] Wed [ ] Thu [x] Fri [ ] Sat

Monthly options (shown when monthly selected):
  ( ) On day [15] of the month
  (x) On the [2nd v] [Tuesday v] of the month

Ends:
  ( ) Never
  (x) On [2025-12-31]
  ( ) After [10] occurrences
```

### Admin Event Listing

- Recurring parent events show with a recurring indicator icon
- Admin can click into the parent to manage the series
- Admin can also see/manage individual materialized instances
- Admin event list has a filter to show "Series only" or "All events including instances"

### Parent Event Admin View

When viewing a recurring parent event in admin:
- Show the recurrence pattern description (e.g., "Every Monday and Wednesday, starting Jan 6, 2025")
- Show a list/calendar of upcoming occurrences
- Indicate which occurrences are materialized vs. virtual
- Provide actions: edit series, end series, cancel specific occurrence

### Editing Instances vs. Parent

**Materialized instances are fully independent.** Editing one is exactly like editing any normal event — no scope dialog, no propagation. The `evt_instance_modified` flag is set for reference but has no functional effect.

**Editing the parent** changes the template that future virtual instances are computed from. This is done from the parent's admin series view. Changes to the parent do **not** propagate to existing materialized instances.

**To change the recurrence pattern itself** (e.g., Mon/Wed/Fri to Tue/Thu), end the current series by setting `evt_recurrence_end_date` and create a new parent event with the new pattern.

### Instance Cancel/Delete Behavior

**Cancelling a single occurrence (virtual or materialized):**
- If virtual: materialize it, then set `evt_instance_cancelled = true`. The date is skipped in future computations.
- If materialized: set `evt_instance_cancelled = true` (or use normal event cancellation).

**Ending the series from a date forward:**
- Set parent's `evt_recurrence_end_date` to stop before the chosen date
- Future virtual instances simply stop being computed
- Existing materialized instances are **not affected** (they are independent events and must be cancelled individually if desired)

**Ending the entire series:**
- Soft-delete the parent or set `evt_is_recurring = false`
- All virtual instances stop being computed
- Existing materialized instances remain as standalone events (independent)

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
5. Implement RRULE generation/parsing (`get_rrule()`, `set_from_rrule()`)
6. Implement `end_series()`

### Phase 3: Admin UI
1. Add recurrence form fields to event edit page
2. Implement JavaScript for dynamic form behavior (show/hide pattern-specific fields)
3. Add series management view to admin event detail page
4. Add instance edit/delete confirmation dialogs with scope selection

### Phase 4: Display Integration
1. Update MultiEvent queries with new filter options
2. Update public event listings to merge virtual instances with real events
3. Show recurring indicator icons and "Part of series" labels
4. Ensure registration flow triggers materialization transparently

### Phase 5: Calendar Export
1. Update iCal export with RRULE support for parent events
2. Export modified instances with RECURRENCE-ID
3. Export cancelled instances as EXDATE
4. Test with Google Calendar, Outlook, Apple Calendar

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

## UI/UX Considerations

1. Show recurring indicator icon on event listings (both public and admin)
2. Display "Part of recurring series" on materialized instance detail pages
3. Provide "View series" link from any instance back to the parent
4. Show recurrence pattern in human-readable format: "Every Monday, Wednesday, and Friday until Dec 31, 2025"
5. Public event listings show individual occurrences (virtual + materialized), not the parent
6. Admin event listings provide filters to toggle between series view and instance view
7. Registration buttons on virtual instances work seamlessly — materialization happens behind the scenes

## Testing Requirements

1. Unit tests for date computation (all recurrence types, edge cases)
2. Unit tests for pattern matching (`date_matches_pattern()`)
3. Unit tests for RRULE generation and parsing
4. Integration tests for materialization (concurrent access, duplicate prevention)
5. Integration tests for registration flow on virtual instances
6. Integration tests for edit/delete scope handling
7. UI tests for recurrence form
8. Calendar export validation
9. Performance tests for large date ranges with "never ends" series

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
