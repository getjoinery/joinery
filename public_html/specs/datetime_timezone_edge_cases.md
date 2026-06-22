# Datetime & Timezone Edge Cases

A reference for every class of timezone/datetime bug that can affect this platform, with findings from the codebase for each.

---

## Edge Cases

### 1. DST Spring-Forward Gap

**What:** When clocks jump forward (e.g., US/Eastern 1:59 AM → 3:00 AM), the entire 2:00–2:59 AM hour does not exist in local time.

**What goes wrong:** Scheduling a slot or converting a UTC time that lands in the gap produces an incorrect or confusing local time. PHP's `DateTime` auto-advances the time to the next valid moment — correct behaviour, but silent.

**Correct handling:** Store and compute in UTC. When materialising a local time from a schedule rule, detect gap slots and apply a documented policy (skip, shift forward, fire early). Never store a local time in the gap as authoritative.

---

### 2. DST Fall-Back Fold

**What:** When clocks fall back (e.g., US/Eastern 2:00 AM → 1:00 AM), the 1:00–1:59 AM window occurs twice — once in the outgoing offset, once in the incoming offset.

**What goes wrong:** A local timestamp of "1:30 AM Eastern" on that date is ambiguous — it could be two different UTC moments. PHP defaults to the first (outgoing) offset, which may be wrong for the second slot. Scheduling a slot in the fold can either be skipped or doubled.

**Correct handling:** Store all specific times as UTC. When displaying, include the timezone abbreviation to disambiguate (e.g., "1:30 AM EDT" vs "1:30 AM EST"). When generating recurring slots through a fall-back night, be explicit about which occurrence is intended.

---

### 3. Recurring Events: Wall-Clock vs Fixed UTC

**What:** A rule like "every Monday at 9:00 AM America/New_York" should always fire at 9:00 AM local time, regardless of DST. If the recurrence is stored as a fixed UTC time ("every Monday at 14:00 UTC"), it drifts by ±1 hour after each DST transition.

**What goes wrong:** Events or availability windows appear at the wrong time for half the year.

**Correct handling:** Store recurrence rules with the originating IANA timezone identifier and a local wall-clock time. Recompute the UTC equivalent fresh for each future occurrence, applying the DST rules active at that moment.

---

### 4. Duration Arithmetic Across DST

**What:** A "24-hour" event starting at midnight on a spring-forward night is only 23 wall-clock hours long; on a fall-back night it is 25 hours. Adding `86400` seconds in UTC is correct; adding "24 hours" in local time arithmetic is not.

**What goes wrong:** Deadlines, SLA timers, booking windows, and expiry calculations are off by one hour on DST transition days.

**Correct handling:** Perform all duration arithmetic in UTC seconds. Convert to local time only for display.

---

### 5. All-Day Events Across Timezones

**What:** An all-day event is a bare calendar date (`2024-06-15`), not a point in time. Storing it as UTC midnight (`2024-06-15T00:00:00Z`) and then converting to local time shifts the displayed date by one day for users west of UTC.

**What goes wrong:** An all-day event appears on the wrong day for some users.

**Correct handling:** Store all-day events as a plain date string (`DATE` column or `varchar`). Never pass them through timezone conversion. Handle them as a separate code path from timed events throughout the stack.

---

### 6. IANA Identifier vs UTC Offset

**What:** Storing a timezone as a fixed offset string (`"+05:30"`) instead of an IANA identifier (`"Asia/Kolkata"`) fails when governments change DST rules or UTC offsets (Venezuela, North Korea, Russia, Morocco have all done this with as little as 8 days' notice).

**What goes wrong:** Stored offsets silently point to the wrong time after a rule change. DST-aware conversion is also impossible from a fixed offset alone.

**Correct handling:** Always store timezone as an IANA identifier string (e.g., `"America/New_York"`). Never store a numeric or string UTC offset as the primary timezone reference.

---

### 7. PostgreSQL POSIX Sign Reversal

**What:** PostgreSQL's `AT TIME ZONE` accepts both IANA names and POSIX-style offset strings. POSIX sign convention is the opposite of ISO: `AT TIME ZONE 'UTC+5'` in PostgreSQL means UTC−5, not UTC+5.

**What goes wrong:** Raw offset strings in `AT TIME ZONE` expressions silently return the wrong time — 10 hours off for IST, etc. No error is raised.

**Correct handling:** Always use IANA timezone names in PostgreSQL `AT TIME ZONE` expressions (e.g., `AT TIME ZONE 'Asia/Kolkata'`). Never use bare `+HH:mm` or `UTC+N` strings.

---

### 8. Sub-Hour UTC Offsets

**What:** Several regions have UTC offsets that are not whole hours: India (+5:30), Nepal (+5:45), Iran (+3:30), Afghanistan (+4:30), Chatham Islands (+12:45). Code that treats UTC offsets as integer hours silently breaks for hundreds of millions of users.

**What goes wrong:** Time display is off by 30 or 45 minutes; offset formatting produces invalid strings; integer arithmetic on offsets produces wrong results.

**Correct handling:** Store offsets as total minutes (not hours), or use IANA identifiers and delegate offset lookup to a timezone library. Never assume `offset * 3600` with an integer.

---

### 9. Midnight as Start vs End of Day

**What:** The time `00:00:00` is the start of a day. Some systems (notably Google Calendar's API) represent the end of an all-day event as the next day at `00:00:00` (exclusive-end convention). Other systems treat the end date as inclusive.

**What goes wrong:** Integrations that disagree on inclusive vs exclusive end display an extra day, or lose the last day. A booking window "August 1–15" can mean 14 or 15 days depending on convention.

**Correct handling:** Document explicitly whether date/time ranges use inclusive or exclusive ends. Validate that every integration partner uses the same convention. Prefer exclusive-end for timestamp ranges (consistent with PostgreSQL `BETWEEN` semantics and iCalendar).

---

### 10. Server Timezone Drift

**What:** PHP's `date()` and `strtotime()` (without explicit timezone parameters) use the server's `date.timezone` INI setting. If the server timezone is not UTC, or drifts between deployments, local-time calculations become environment-dependent.

**What goes wrong:** Dates and times computed without explicit timezone parameters behave differently on dev vs production, or after a server migration.

**Correct handling:** Set `date.timezone = UTC` in `php.ini`. Use `LibraryFunctions::convert_time()` with explicit source/target timezones for all conversions. Never rely on `date()` or `strtotime()` implicitly.

---

## Codebase Findings

### 1. DST Spring-Forward Gap
**Status: Low risk — relies on PHP's silent auto-advance, not explicit handling.**

`includes/scheduling/SlotGenerator.php:116-117` — `availabilityRanges()` converts `date + local_time` to UTC via `convert_time()`. When a window crosses the spring-forward gap (e.g., 01:00–05:00 on the transition night), PHP's `DateTime` constructor silently advances the non-existent time to the next valid moment. The behaviour is correct but undocumented.

`tests/calendar/slot_generator_test.php:92-106` — There is an explicit spring-forward test that verifies slots are UTC-contiguous and strictly increasing. Passes.

No explicit gap detection or policy (skip / shift / fire early) is documented in the code.

---

### 2. DST Fall-Back Fold
**Status: Medium risk — ambiguous hour not explicitly disambiguated; test only checks "no crash".**

`includes/LibraryFunctions.php:750-770` (`convert_time`) — PHP's `DateTime` defaults to the first (outgoing) UTC offset when a local time is ambiguous during fall-back. This is deterministic but silently picks one occurrence.

`tests/calendar/slot_generator_test.php:108-113` — The fall-back test only asserts `count($slots) >= 3`. It does not verify that slots in the ambiguous hour are not duplicated, or that the correct UTC occurrence is chosen.

No deduplication or disambiguation logic exists for the 1:00–2:00 AM window on fall-back nights. For a booking platform this is acceptable (those are unusual booking hours), but it is a gap worth documenting.

---

### 3. Recurring Events: Wall-Clock vs Fixed UTC
**Status: Correct.**

`data/schedule_window_class.php:19-20` — `scw_start_time` and `scw_end_time` are `TIME` columns (wall-clock, no date, no timezone).

`includes/scheduling/SlotGenerator.php:114-122` (`availabilityRanges`) — For each concrete local date in the range, the generator concatenates `date + local_time` and converts to UTC using the schedule's IANA timezone. UTC is recomputed per occurrence, so the same "09:00" wall-clock time produces different UTC values before and after a DST transition — which is the correct behaviour.

---

### 4. Duration Arithmetic Across DST
**Status: Correct — all arithmetic is in UTC seconds.**

`plugins/bookings/includes/scheduling_providers/NativeSchedulingProvider.php:113-114` — Booking end time is computed as `strtotime($slot_start_utc) + $duration * 60`. Adding fixed seconds to a UTC epoch is DST-safe.

`plugins/bookings/includes/scheduling_providers/NativeSchedulingProvider.php:52-54` — Rolling booking window: `gmdate('Y-m-d H:i:s', strtotime($now . ' +' . $rolling . ' days'))`. Uses `gmdate` (UTC), so server timezone does not affect the result.

`includes/LibraryFunctions.php` (`time_shift`) — Wraps `DateTime::modify()` with explicit timezone parameters; safe.

---

### 5. All-Day Events Across Timezones
**Status: Correct.**

`data/calendar_entry_class.php:24-26` — All-day events are stored as UTC `timestamp(6)` pairs plus a `cal_all_day bool` flag. They are not stored as bare `DATE` columns.

`logic/calendar_logic.php:59-62` — On save, an all-day event is converted to `00:00:00` of the user's local date → UTC for start, and `00:00:00` of the next local date → UTC for end (exclusive-end convention). This correctly places the full local calendar day as a UTC interval, which will display on the right date for any viewer in that timezone.

---

### 6. IANA Identifier vs UTC Offset
**Status: Correct — offset strings are rejected at validation.**

`data/users_class.php:392-398` — Timezone is validated by constructing `new DateTimeZone($value)`. PHP throws if the string is not a valid IANA identifier, so `"+05:30"` or `"UTC+5"` would be rejected.

`plugins/bookings/logic/availability_logic.php:41` — Schedule timezone validated with `in_array($timezone, DateTimeZone::listIdentifiers(), true)`.

`plugins/bookings/views/profile/availability.php:23` — Timezone picker is populated from `DateTimeZone::listIdentifiers()`, so only valid IANA names appear in the UI.

---

### 7. PostgreSQL POSIX Sign Reversal
**Status: Not affected — only `'UTC'` (IANA) appears in SQL timezone expressions.**

All `AT TIME ZONE` usages found in the codebase (e.g., `plugins/joinery_ai/tasks/RecipeDispatcher.php:50,52`) pass the literal string `'UTC'`, which is an IANA identifier, not a POSIX offset. No bare `'+05:30'` or `'UTC+5'` strings appear in SQL.

---

### 8. Sub-Hour UTC Offsets
**Status: Correct — IANA identifiers handle sub-hour offsets transparently.**

No integer offset arithmetic exists in the codebase. All timezone operations go through PHP's `DateTime`/`DateTimeZone` which correctly handles +5:30, +5:45, etc. The timezone picker (`DateTimeZone::listIdentifiers()`) includes all sub-hour zones.

---

### 9. Midnight as Start vs End of Day
**Status: Mostly correct, with a minor inconsistency in booking window dates.**

`includes/scheduling/SlotGenerator.php:68-69` — Slot generation uses exclusive-end: `$start + $duration <= $end`. Half-open interval `[start, end)`.

`logic/calendar_logic.php:60-62` — All-day events use exclusive-end: end is `00:00:00` of the next day.

`plugins/bookings/includes/scheduling_providers/NativeSchedulingProvider.php:50` — Booking type window end date is stored as a bare `DATE` (`bkt_window_end`) and then appended with `' 23:59:59'` at query time — inclusive-end-of-day, not exclusive midnight. This is inconsistent with the rest of the system's exclusive-end convention, but not a bug within the booking window logic itself since it's consistently applied.

---

### 10. Server Timezone Drift
**Status: Correct — no bare `date()` or `strtotime()` calls in critical paths.**

`includes/scheduling/SlotGenerator.php:45,304` — Uses `gmdate()` (always UTC regardless of server TZ) and accepts `now_utc` as an injectable parameter for testing.

`includes/LibraryFunctions.php:764` — `convert_time()` always takes explicit source and target timezone parameters; never reads server TZ implicitly.

`logic/calendar_logic.php:60,90` and `plugins/bookings/includes/scheduling_providers/NativeSchedulingProvider.php:43,53` — All timestamp generation uses `gmdate()` or `convert_time()` with explicit timezone.

No bare `date()`, `strtotime()`, or `mktime()` calls without explicit timezone context found in critical datetime paths.
