# Recurring Events Architecture

The system supports recurring events using a **hybrid virtual/materialized instance** pattern:

- **Recurring Parent**: An event with `evt_recurrence_type` set (not null). Holds the recurrence pattern. Hidden from public listings.
- **Virtual Instance**: Computed in-memory (stdClass) from the parent's pattern. No database row. Registration closed.
- **Materialized Instance**: A real `evt_events` row created by admin action. Fully independent after creation.

## Key checks

```php
$event->is_recurring_parent();  // evt_recurrence_type IS NOT NULL
$event->is_instance();          // evt_parent_event_id IS NOT NULL
```

## Materialization

Materialization is admin-initiated only (via admin event detail page). Virtual instances become materialized instances when admin clicks "Materialize" or "Cancel".

## URL routing

`/event/{slug}/{date}` for recurring event instances (e.g., `/event/weekly-class/2025-03-05`). The slug identifies the parent, the date selects the occurrence.

## Public listings

Use `MultiEvent::getWithRepeatingEvents()` to get a merged, deduplicated, sorted array of standalone events plus expanded recurring instances:

```php
// Get upcoming events with recurring series expanded (default 6-month range)
$events = MultiEvent::getWithRepeatingEvents(
    ['upcoming' => true, 'deleted' => false, 'visibility' => 1],
    null,  // range_end (default: +6 months)
    20     // limit (optional)
);
```

Returns a mixed array of Event objects and virtual stdClass instances. Handles all deduplication between materialized instances and virtual instances automatically.

**Do NOT manually query with `exclude_recurring_parents` and merge `get_instances_for_range()` — use `getWithRepeatingEvents()` instead** to avoid duplicate materialized instance bugs.

## Key model methods

- `$parent->get_instances_for_range($start, $end)` — returns mixed array of Event and stdClass objects
- `$parent->materialize_instance($date)` — creates DB row, returns Event
- `$parent->compute_occurrence_dates($from, $count)` — pure date math
- `$parent->get_recurrence_description()` — human-readable pattern text
