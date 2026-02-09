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

Use `exclude_recurring_parents => true` in MultiEvent to hide parents, then merge in virtual instances from `get_instances_for_range()`.

## Key model methods

- `$parent->get_instances_for_range($start, $end)` — returns mixed array of Event and stdClass objects
- `$parent->materialize_instance($date)` — creates DB row, returns Event
- `$parent->compute_occurrence_dates($from, $count)` — pure date math
- `$parent->get_recurrence_description()` — human-readable pattern text
