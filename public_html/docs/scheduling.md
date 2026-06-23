# Availability & Scheduling

The availability engine turns a subject's working hours (minus the time they're already busy) into open booking slots. It is pure, core, and reusable by any feature that needs "who is free when" — bookings is one consumer.

## Schedules

A subject has **one** schedule (`Schedule` / `data/schedule_class.php`, table `sch_schedules`) — it *is* the subject's availability and the timezone anchor for their windows.

- `sch_subject_type` / `sch_subject_id` — the owning `CalendarSubject`, **unique together** (one schedule per subject).
- `sch_timezone` — the IANA timezone the windows are defined in.

`Schedule::for_subject(CalendarSubject)` and `Schedule::get_or_create_for_subject(CalendarSubject)` load (or seed) the row.

**Weekly windows** (`ScheduleWindow`, `scw_schedule_windows`): `scw_day_of_week` (0=Sunday…6=Saturday), `scw_start_time`/`scw_end_time` (PostgreSQL `time`). End must be after start; windows do not cross midnight (enter an overnight span as two windows on adjacent days).

**Date overrides** (`ScheduleOverride`, `sco_schedule_overrides`): `sco_date` plus nullable `sco_start_time`/`sco_end_time`. If any override rows exist for a date, they **replace** the weekly windows for that date — a single row with null start/end means fully unavailable; rows with times are that date's windows.

### The wall-clock-time exception

Unlike every other time in the database (UTC), `scw_*_time` / `sco_*_time` are **wall-clock** times in the schedule's timezone, not UTC instants — "9am Monday" must survive DST transitions. The slot generator converts wall-clock windows to UTC instants per concrete date.

## SlotGenerator

`includes/scheduling/SlotGenerator.php` — pure computation, no HTTP/DB, unit-testable.

- `SlotGenerator::generate(array $params)` → list of `['start'=>UTC, 'end'=>UTC]`. Params: timezone, windows, overrides, range (UTC), `duration_minutes`, `increment_minutes`, `buffer_before_minutes`, `buffer_after_minutes`, `min_notice_minutes`, `busy` (the busy projection), optional `now_utc`.
- `SlotGenerator::availableIntervals(array $params)` → merged free intervals (windows minus busy), for previews.
- `SlotGenerator::forSchedule($schedule, $start, $end, $opts, $busy)` → loads a Schedule's windows/overrides and generates slots; the caller supplies the busy projection.

**Algorithm:** expand weekly windows over the concrete dates (applying overrides, converting wall-clock → UTC per date), pad busy blocks by the buffers, then anchor the increment grid to each availability window's start and emit a duration-long slot at each grid point that stays inside the window, clears the padded busy regions, and passes the min-notice and range filters. Window-anchored (not busy-anchored) start times keep slots on a predictable grid.

Per-period caps (max bookings/day/week) are **not** the generator's job — the bookings layer counts its own bookings and suppresses slots on capped days, bounded in the schedule's timezone.

## Availability editor

`/profile/bookings/availability` (a bookings-plugin view over the core models) edits the subject's single schedule: weekly windows, date overrides, and timezone. It previews the resulting open availability (green) against existing commitments using the `calendar_grid` component, fed by `/ajax/availability_preview`.

## Calendar UI components

Two universal HTML5 + vanilla-JS component types in `views/components/`, rendered viewer-local from UTC data:

- **`calendar_grid`** — month/week views of timed items. `ComponentRenderer::render(null, 'calendar_grid', ['items'=>[...], 'view'=>'month', 'feed_url'=>'...', 'initial_date'=>'Y-m-d'])`. With `feed_url` it fetches `{items:[...]}` per range and pages client-side. Emits a `calendardayclick` event (date) for click-to-create.
- **`slot_picker`** — booking time picker: mini-month + the selected day's times + a viewer-timezone selector (auto-detected, overridable). Loads UTC slots from `slots_url` (`{slots:[{start,end}]}`) and writes the chosen UTC slot start into a hidden field; emits a `slotchosen` event.

## Data layer

`sch_schedules`, `scw_schedule_windows`, `sco_schedule_overrides`, and `cal_entries` are core data-model classes. `Schedule` and `CalendarEntry` carry a polymorphic owner — `(subject_type, subject_id)` rather than a `usr_users` FK — so each defines a hand-written owner-or-staff `authenticate_read/write()` keyed on `subject_id`.
