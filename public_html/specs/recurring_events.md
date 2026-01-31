# Recurring Events Specification

## Overview

This specification defines the implementation of recurring events functionality for the Joinery platform. Users will be able to create events that automatically repeat on a schedule (daily, weekly, monthly, yearly) without manually creating each instance.

## Goals

1. Allow event creators to define recurrence patterns when creating/editing events
2. Automatically generate event instances based on recurrence rules
3. Support standard recurrence patterns (daily, weekly, monthly, yearly)
4. Allow modifications to individual instances without affecting the series
5. Support iCalendar RRULE format for calendar export compatibility
6. Maintain backwards compatibility with existing single events

## Database Schema

### New Fields in `evt_events` Table

Add to `Event` class `$field_specifications`:

```php
// Recurrence fields
'evt_is_recurring' => array('type' => 'boolean', 'default' => false),
'evt_recurrence_type' => array('type' => 'varchar(20)', 'is_nullable' => true),
// Values: 'daily', 'weekly', 'monthly', 'yearly', 'custom'

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

'evt_parent_event_id' => array('type' => 'integer', 'is_nullable' => true),
// For instances: references the parent recurring event

'evt_instance_date' => array('type' => 'date', 'is_nullable' => true),
// For instances: the specific date this instance represents

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
```

## Data Model Changes

### Event Class Additions

```php
// In /data/events_class.php

/**
 * Generate recurring event instances
 * Creates individual event records for each occurrence within the date range
 *
 * @param string $start_date Start of range to generate (Y-m-d)
 * @param string $end_date End of range to generate (Y-m-d)
 * @param bool $save_instances Whether to save generated instances to database
 * @return array Array of Event objects (saved or unsaved)
 */
public function generate_instances($start_date, $end_date, $save_instances = true);

/**
 * Get all instances of this recurring event
 *
 * @param string $start_date Optional start filter
 * @param string $end_date Optional end filter
 * @return MultiEvent
 */
public function get_instances($start_date = null, $end_date = null);

/**
 * Update all future instances with changes from parent
 * Used when editing "this and all future events"
 *
 * @param array $fields Fields to update
 * @param string $from_date Update instances from this date forward
 */
public function update_future_instances($fields, $from_date);

/**
 * Delete all instances of this recurring event
 *
 * @param string $from_date Optional: only delete from this date forward
 */
public function delete_instances($from_date = null);

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
 * Check if this event is an instance of a recurring series
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
 * Get the parent event if this is an instance
 *
 * @return Event|null
 */
public function get_parent_event();
```

### MultiEvent Class Additions

```php
// In /data/events_class.php (MultiEvent)

// Add filter options:
'parent_event_id' => $parent_id,  // Get instances of specific series
'exclude_instances' => true,       // Get only parent events, not instances
'include_instances' => true,       // Include generated instances in results
```

## Recurrence Logic

### Instance Generation Algorithm

```php
// Pseudo-code for generate_instances()

function generate_instances($start_date, $end_date, $save = true) {
    if (!$this->get('evt_is_recurring')) {
        return [];
    }

    $instances = [];
    $current_date = max($this->get('evt_start_time'), $start_date);
    $series_end = $this->calculate_series_end_date();
    $end = min($end_date, $series_end);
    $count = 0;
    $max_count = $this->get('evt_recurrence_count') ?: PHP_INT_MAX;

    while ($current_date <= $end && $count < $max_count) {
        if ($this->date_matches_pattern($current_date)) {
            // Check if instance already exists
            $existing = $this->get_instance_for_date($current_date);

            if (!$existing) {
                $instance = $this->create_instance_for_date($current_date);
                if ($save) {
                    $instance->save();
                }
                $instances[] = $instance;
            } else {
                $instances[] = $existing;
            }
            $count++;
        }
        $current_date = $this->get_next_date($current_date);
    }

    return $instances;
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

## Admin Interface

### Event Edit Form Additions

Add a "Recurrence" section to `/adm/admin_event_edit.php`:

```
[ ] This is a recurring event

Repeat: [Daily ▼]  Every [1] day(s)

Weekly options (shown when weekly selected):
  Repeat on: [ ] Sun [x] Mon [ ] Tue [x] Wed [ ] Thu [x] Fri [ ] Sat

Monthly options (shown when monthly selected):
  ( ) On day [15] of the month
  (x) On the [2nd ▼] [Tuesday ▼] of the month

Ends:
  ( ) Never
  (x) On [2025-12-31]
  ( ) After [10] occurrences
```

### Instance Edit Behavior

When editing an instance of a recurring event, prompt:

```
This event is part of a recurring series.
What would you like to edit?

[This event only] [This and future events] [All events in series] [Cancel]
```

### Instance Delete Behavior

When deleting an instance:

```
This event is part of a recurring series.
What would you like to delete?

[This event only] [This and future events] [All events in series] [Cancel]
```

## Calendar Integration

### iCalendar Export

Modify the iCal export to include RRULE for recurring events:

```
BEGIN:VEVENT
UID:event-123@joinerytest.site
DTSTART:20250115T190000Z
DTEND:20250115T210000Z
SUMMARY:Weekly Dance Class
RRULE:FREQ=WEEKLY;BYDAY=MO,WE,FR;UNTIL=20251231T235959Z
END:VEVENT
```

### Google Calendar Sync

When syncing with Google Calendar:
- Export parent event with RRULE (Google handles instance generation)
- Sync modified instances as EXDATE exceptions
- Sync cancelled instances as EXDATE

## API Considerations

### Events API Endpoints

```
GET /api/events
  - Add parameter: include_instances=true|false
  - Add parameter: expand_recurring=true (generates instances on-the-fly)

GET /api/events/{id}
  - Returns event with recurrence info
  - If instance, includes parent_event_id

POST /api/events
  - Accept recurrence fields
  - Optionally generate instances immediately

PUT /api/events/{id}
  - Add parameter: update_scope=single|future|all

DELETE /api/events/{id}
  - Add parameter: delete_scope=single|future|all
```

## Migration Plan

### Phase 1: Database Schema
1. Add new fields to Event class `$field_specifications`
2. Run database update to create columns
3. Add indexes

### Phase 2: Core Logic
1. Implement `generate_instances()` method
2. Implement pattern matching logic
3. Implement RRULE generation/parsing
4. Add instance management methods

### Phase 3: Admin UI
1. Add recurrence form fields to event edit page
2. Implement JavaScript for dynamic form behavior
3. Add instance edit/delete confirmation dialogs

### Phase 4: Display Integration
1. Update MultiEvent queries to handle instances
2. Update event listings to show recurring indicators
3. Update calendar views

### Phase 5: Calendar Export
1. Update iCal export with RRULE support
2. Test with Google Calendar, Outlook, Apple Calendar

## Edge Cases

1. **Timezone handling**: Store recurrence pattern in event's timezone, convert when displaying
2. **DST transitions**: Handle days that don't exist (e.g., 2am during spring forward)
3. **Month length**: Handle "31st of month" for months with fewer days (use last day)
4. **Leap years**: Handle Feb 29 for yearly recurrence
5. **Registration limits**: Each instance has its own registration count
6. **Past instances**: Don't generate instances before event creation date
7. **Orphaned instances**: Clean up instances if parent is deleted

## UI/UX Considerations

1. Show recurring indicator icon on event listings
2. Display "Part of recurring series" on instance detail pages
3. Allow viewing all instances in series from parent event
4. Provide "View series" link from any instance
5. Show recurrence pattern in human-readable format: "Every Monday, Wednesday, and Friday until Dec 31, 2025"

## Testing Requirements

1. Unit tests for pattern matching (all recurrence types)
2. Unit tests for RRULE generation and parsing
3. Integration tests for instance generation
4. Integration tests for edit/delete scope handling
5. UI tests for recurrence form
6. Calendar export validation

## Future Enhancements

1. Complex RRULE patterns (BYSETPOS, BYMONTHDAY combinations)
2. Exception dates (EXDATE) - specific dates to skip
3. Recurrence rule builder UI with preview
4. Bulk operations on series
5. Series templates (save recurrence patterns for reuse)
