# Personal Calendar

The personal calendar is a core, subject-owned timeline. Every time-bound thing in the platform — events, bookings, native entries, and later external feeds — projects onto one timeline per subject. Availability and booking are *derived from* the calendar, never the other way around.

## Subject identity

A calendar belongs to a **schedulable subject**, not directly to a user. `CalendarSubject` (`includes/calendar/CalendarSubject.php`) is a small value object — `{type, id}` — plus a resolver that turns it into the owner record (display name, timezone, avatar). It is the single place that knows subject types exist; everything else passes a `CalendarSubject` around without branching on type.

`user` is the only implemented type. `resource`, `team`, and `venue` are reserved (the schema and interfaces already key on `(subject_type, subject_id)`).

Owner identity is stored as `subject_type` + `subject_id` columns on the only two owner-bearing tables — `sch_schedules` and `cal_items` — and resolved live. A polymorphic owner can't be a database foreign key to `usr_users`, so those two tables give up DB-level referential integrity on the owner column; owner cleanup on user deletion is handled by a declarative `$foreign_key_actions` cascade.

## Ownership model — system of record vs. projection

The calendar is a **system of record only for items that originate in the calendar itself.** Everything else is a **read-only projection** it links out to. There is no write-back to external systems and no two-way sync.

- **Native entries** (`type = personal`) — created directly on the calendar (a personal appointment, a block of busy time). Stored in `cal_items`, owned by the calendar, fully editable. Exposed through the registry via `NativeCalendarItemSource`.
- **Projected items** (events, bookings, later external feeds) — owned by their originating system. The calendar renders them and deep-links (`url`) but never edits or moves them. Rescheduling a booking happens in the booking flow; editing an event happens in the events system.

Consequences: `CalendarItemSource` is a read-only contract (no write methods); there is no sync engine and no conflict resolution (every item has exactly one owner); projected items are not inline-editable.

## The calendar item

`CalendarItem` (`includes/calendar/CalendarItem.php`) is the unit on the timeline — a value object, not necessarily a stored row. Fields: `start_utc`/`end_utc` (UTC instants), `all_day`, `type` (`event`/`booking`/`external`/`personal`), `title` (owner-visible only), `url` (owner-visible only), `blocks_availability`, `visibility` (`details`/`busy`), `source`, `source_key` (stable id `{source}:{record-id}` for redraw/diff, ICS UID, click-to-edit).

> **Note:** the stored native-entry model is `CalendarEntry` (`data/calendar_entry_class.php`, table `cal_items`), which is distinct from the `CalendarItem` value object. `NativeCalendarItemSource` reads `CalendarEntry` rows and emits `CalendarItem` value objects.

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
- **NativeCalendarItemSource** (core) — native `cal_items` entries.
- **BookingItemSource** (bookings plugin) — confirmed bookings and active paid holds where the subject is host.

The registry being the only coupling point is why this lives in core: a new source appears on every calendar and gates every availability calculation with no change to the grid, the slot generator, or any other source.

## Busy projection

There is exactly one upstream contract — items. "Busy time" is a derived view: aggregate `getItems(...)` at `busy` visibility, keep those with `blocks_availability === true`, reduce to `{start, end}`, and merge overlaps (`CalendarItemSourceRegistry::getBusyBlocks()` / `mergeBlocks()`). This projection is the only thing the availability engine and `SlotGenerator` consume. One registration therefore yields both outcomes — the item shows on the calendar **and** blocks availability.

## Native entries and the personal calendar page

`/profile/calendar` renders the `calendar_grid` component against the owner's aggregated item feed (`/ajax/calendar_feed`, `details` visibility). Native entries are created and edited there: click a day to start a new entry, click a native chip to edit it. A "blocking" entry removes its time from booking availability via the busy projection. Times are entered in the owner's timezone and stored as UTC.

## Deletion

User-as-subject deletion deletes their schedules and native entries via `$foreign_key_actions` (the declarative cascade that substitutes for the missing real FK on the polymorphic owner column). Native entries have no external side effects.
