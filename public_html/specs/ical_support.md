# iCal Support for Events

## Overview

Add iCalendar (RFC 5545) support with two features:

1. **Single event download** — `/event/{slug}.ics` serves a downloadable `.ics` file for any public event
2. **Public calendar feed** — `/events/calendar.ics` serves a subscribable iCal feed of all upcoming public events

## Problem

The existing Spatie calendar-links library generates "Add to Calendar" links (Google, Yahoo, Outlook) but:
- Its ICS output is a base64 data URI, not a downloadable file
- It generates single events only — no multi-event feed support
- It's missing the required `DTSTAMP` field (RFC 5545 violation)
- It uses the event's profile URL as the location instead of the actual venue address

A custom `IcsHelper` class is needed to generate RFC 5545-compliant output.

## Scope

- Public events only (`evt_visibility = 1`)
- Calendar feed includes upcoming non-recurring events + expanded recurring instances (next 6 months)
- No authentication required (public endpoints)
- UTC times with `Z` suffix (no VTIMEZONE complexity — events already store UTC in the database)

---

## Implementation

### 1. New File: `includes/IcsHelper.php`

Static utility class for RFC 5545 iCalendar generation.

#### Public Methods

| Method | Signature | Purpose |
|--------|-----------|---------|
| `generateVevent` | `($event, $instance_date = null)` | Build a VEVENT block from an Event object or virtual stdClass |
| `wrapInVcalendar` | `($vevents_string)` | Wrap VEVENT(s) in VCALENDAR envelope |
| `outputIcs` | `($ics_string, $filename, $inline = false)` | Set headers, echo output, exit |

#### Private/Internal Methods

| Method | Purpose |
|--------|---------|
| `escapeText($text)` | Escape per RFC 5545 (backslashes, semicolons, commas, newlines) |
| `formatUtcDateTime($datetime_string)` | Convert DB timestamp to `YYYYMMDDTHHMMSSZ` |
| `generateUid($event_id, $instance_date)` | Deterministic UID: `event-{id}@{domain}` or `event-{id}-{date}@{domain}` |
| `getLocationString($event)` | Build location from `evt_location` + Location entity address |
| `getField($obj, $field)` | Unified accessor for Event objects (`->get()`) and virtual stdClass (`->$field`) |
| `getEventUrl($event)` | Build absolute URL to the event page |
| `foldLines($ics_string)` | Fold lines >75 octets per RFC 5545 §3.1 |

#### VEVENT Fields

Each VEVENT block includes:

```
BEGIN:VEVENT
UID:event-{id}@{domain}
DTSTAMP:{now in UTC}
DTSTART:{evt_start_time as UTC}
DTEND:{evt_end_time as UTC}
SUMMARY:{evt_name}
DESCRIPTION:{evt_short_description, plain text, max 500 chars}
LOCATION:{evt_location text + Location address if evt_loc_location_id set}
URL:{absolute URL to event page}
STATUS:{CONFIRMED|CANCELLED}
END:VEVENT
```

#### Key Design Decisions

- **UTC with `Z` suffix**: Events store UTC in the database (`evt_start_time`, `evt_end_time`). Output as `DTSTART:20260315T180000Z`. No VTIMEZONE blocks needed.
- **Deterministic UIDs**: `event-{id}@{domain}` for non-recurring, `event-{id}-{YYYYMMDD}@{domain}` for recurring instances. This allows calendar apps to properly update events on re-import.
- **`getField()` helper**: Virtual instances from `Event::create_virtual_instance()` return stdClass objects (not Event). `getField()` calls `->get($field)` on Event objects and accesses `->$field` on stdClass, making both work transparently.
- **STATUS mapping**: `evt_status` 1 (active) → `CONFIRMED`, 2 (completed) → `CONFIRMED`, 3 (canceled) → `CANCELLED`
- **VCALENDAR envelope**: Includes `VERSION:2.0`, `PRODID:-//Joinery//Events//EN`, `CALSCALE:GREGORIAN`, and `X-WR-CALNAME:{site_name}` (feed only).
- **Line folding**: Per RFC 5545 §3.1, lines longer than 75 octets are folded with `CRLF` + single space.

#### Location Building

`getLocationString()` logic:
1. Start with `evt_location` text field (if set)
2. If `evt_loc_location_id` is set, load the Location and append `loc_address` (if different from evt_location)
3. Combine with comma separator

#### Event URL Building

`getEventUrl()` logic:
- For Event objects: use `$event->get_url()` to get the slug-based path
- For virtual stdClass: build from `evt_link` property + `instance_date` if present
- Prefix with `LibraryFunctions::get_absolute_url()` for full URL

### 2. Route Changes: `serve.php`

Add two custom routes in the `'custom'` array, **before** the existing `/uploads/*` route (since custom routes are processed in order):

#### Route A: `/event/*.ics` — Single Event Download

```php
'/event/*.ics' => function($params, $settings, $session) {
    // ...
}
```

Logic:
1. Extract the path after `/event/` and before `.ics` from `$params`
2. Parse as `{slug}` or `{slug}/{date}` (for recurring instances)
3. Load event via `Event::get_by_link($slug)`
4. Return 404 if not found or not public (`evt_visibility != 1`)
5. For recurring parent:
   - With date: resolve to materialized instance (`_get_materialized_instance_for_date`) or virtual (`create_virtual_instance`)
   - Without date: use `compute_occurrence_dates(date('Y-m-d'), 1)` to get next upcoming instance
6. Generate VEVENT via `IcsHelper::generateVevent()`
7. Wrap in VCALENDAR via `IcsHelper::wrapInVcalendar()`
8. Output via `IcsHelper::outputIcs()` with `Content-Disposition: attachment`

Requires: `data/events_class.php`, `data/locations_class.php`, `includes/IcsHelper.php`

#### Route B: `/events/calendar.ics` — Public Calendar Feed

```php
'/events/calendar.ics' => function($params, $settings, $session) {
    // ...
}
```

Logic:
1. Check `events_active` setting
2. Load upcoming non-recurring public events:
   ```php
   $events = new MultiEvent([
       'visibility' => Event::VISIBILITY_PUBLIC,
       'deleted' => false,
       'upcoming' => true,
       'exclude_recurring_parents' => true
   ], ['evt_start_time' => 'ASC']);
   $events->load();
   ```
3. Load recurring parents:
   ```php
   $parents = new MultiEvent([
       'visibility' => Event::VISIBILITY_PUBLIC,
       'deleted' => false,
       'only_recurring_parents' => true
   ]);
   $parents->load();
   ```
4. Expand each recurring parent to instances for the next 6 months via `get_instances_for_range(date('Y-m-d'), date('Y-m-d', strtotime('+6 months')))`
5. Generate VEVENT for each event/instance
6. Wrap all VEVENTs in a single VCALENDAR
7. Output via `IcsHelper::outputIcs()` with `Content-Disposition: inline` and `Cache-Control: max-age=300` (5-minute cache)

Requires: `data/events_class.php`, `data/locations_class.php`, `includes/IcsHelper.php`

### Route Pattern Matching

The RouteHelper `buildRouteRegex()` converts:
- `*` → `(.*)`
- `{slug}` → `([^/]+)`

So `/event/*.ics` becomes `#^/event/(.*)\.ics$#` which matches:
- `/event/my-event.ics` (single event)
- `/event/my-event/2026-03-15.ics` (recurring instance by date)

And `/events/calendar.ics` is a literal match (no wildcards).

The `.ics` routes must appear **before** the existing dynamic routes `/event/{slug}/{date}` and `/event/{slug}` in serve.php's custom routes section, so they match first and don't fall through to the dynamic event view.

---

### 3. Fix Spatie Calendar Link Location: `data/events_class.php`

The existing `get_add_to_calendar_links()` method passes the event's profile URL as the `->address()` (location), which is wrong. Fix to use the same physical-location-first logic as IcsHelper:

1. If `evt_location` is set, use that
2. Else if `evt_loc_location_id` is set, use the Location's `loc_address`
3. Fallback to the event URL

Also swap the `$calendar_links['ics']` value from the Spatie base64 data URI to a link to `/event/{slug}.ics` (the new downloadable endpoint).

### 4. Add Calendar Links to Public Event Page: `views/event.php`

The `evt_show_add_to_calendar_link` setting exists in the admin but the public event view never displays calendar links. Add an "Add to Calendar" widget in the sidebar, after the Registration widget and before the Sessions widget.

**Layout:** A small widget card matching the existing sidebar style (`bg-white rounded-4 shadow-sm p-4 mb-4`):

```
┌─────────────────────────┐
│ Add to Calendar          │
│                          │
│ 📅 Google  📅 Outlook   │
│ 📅 Yahoo   📅 Download  │
└─────────────────────────┘
```

- Only shown when `evt_show_add_to_calendar_link` is true and event has a start time
- Uses `$event->get_add_to_calendar_links()` for Google/Yahoo/Outlook links (from Spatie)
- The "Download" link points to `/event/{slug}.ics` (the new endpoint)
- Works for both real Event objects and virtual recurring instances (virtual instances link to `/event/{slug}/{date}.ics`)
- Uses Bootstrap Icons (`bi-google`, `bi-calendar-plus`, etc.) consistent with the rest of the event page

### 5. Add "Subscribe to Calendar" Link to Events Listing: `views/events.php`

Add a small "Subscribe to Calendar" link on the events listing page (`/events`), positioned after the filter tabs and before the event grid.

**Layout:** A subtle right-aligned link below the filter tabs:

```
[Future] [Past] [Event Types...]
                    📅 Subscribe to Calendar
```

- Links to `/events/calendar.ics`
- Only shown when `events_active` setting is true
- Simple text link with calendar icon, right-aligned, muted styling

## Files Changed

| File | Change |
|------|--------|
| `includes/IcsHelper.php` | **New** — Static utility class for iCal generation |
| `serve.php` | **Modified** — Add two custom routes for `.ics` endpoints |
| `data/events_class.php` | **Modified** — Fix location in `get_add_to_calendar_links()`, swap ICS link to new endpoint |
| `views/event.php` | **Modified** — Add "Add to Calendar" sidebar widget |
| `views/events.php` | **Modified** — Add "Subscribe to Calendar" link |

## Dependencies

Uses existing code only:
- `Event::get_by_link()`, `Event::is_recurring_parent()`, `Event::get_instances_for_range()`, `Event::create_virtual_instance()`, `Event::compute_occurrence_dates()`, `Event::_get_materialized_instance_for_date()`
- `MultiEvent` with filters: `visibility`, `deleted`, `upcoming`, `exclude_recurring_parents`, `only_recurring_parents`
- `Location` model for address lookup
- `LibraryFunctions::get_absolute_url()` for absolute URLs

## Edge Cases

1. **Event with no end time**: Omit `DTEND` — RFC 5545 allows this (event is treated as instantaneous)
2. **Event with no start time**: Skip the VEVENT entirely
3. **Cancelled recurring instance**: `get_instances_for_range()` already filters out cancelled materialized instances
4. **Private/unlisted events**: Return 404 for single download; excluded from feed by `visibility => 1` filter
5. **Empty feed**: Return valid VCALENDAR with no VEVENTs (this is valid per RFC 5545)
6. **Special characters in text fields**: `escapeText()` handles backslashes, semicolons, commas, and newlines
7. **Very long descriptions**: Truncated to 500 characters to keep `.ics` files manageable

## Verification

1. Navigate to `/event/{any-public-event-slug}.ics` — should download a `.ics` file
2. Navigate to `/events/calendar.ics` — should return iCal feed content
3. Import downloaded `.ics` into Google Calendar — verify event name, time, location, description render correctly
4. Subscribe to `/events/calendar.ics` in a calendar app — verify upcoming events appear
5. Test with a recurring event slug — should download the next upcoming instance
6. Test with a private event slug — should return 404
7. Test with a non-existent slug — should return 404
8. Visit a public event page with `evt_show_add_to_calendar_link` enabled — should see "Add to Calendar" widget in sidebar with Google/Yahoo/Outlook/Download links
9. Visit `/events` — should see "Subscribe to Calendar" link near the filter tabs
10. Verify the Google/Yahoo/Outlook calendar links show the physical location (not the event URL) when a location is set
