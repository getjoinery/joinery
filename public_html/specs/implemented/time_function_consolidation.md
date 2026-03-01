# Spec: Time Function Consolidation

## Goal

Consolidate all time/date formatting functions in LibraryFunctions and SystemBase down to 2 essential methods, removing 13 methods total. Most removed methods were trivial one-liner wrappers around PHP's DateTime or around `convert_time()`.

## Methods Kept (2)

| Method | Location | Calls | Reason |
|--------|----------|-------|--------|
| `convert_time($starttime, $fromtz, $totz, $format)` | LibraryFunctions | 147+ | Core workhorse — timezone conversion with DateTime/string handling |
| `time_shift($starttime, $days, $format)` | LibraryFunctions | 4 | Date arithmetic — adds days to a time |

## Methods Removed (13)

### Phase 1: Deleted with zero code changes (0 callers)

| # | Method | Location |
|---|--------|----------|
| 1 | `get_timezone_corrected_datetime()` | SystemBase.php |
| 2 | `get_timezone_agnostic_date()` | SystemBase.php |
| 3 | `get_time_abbr()` | LibraryFunctions.php |
| 4 | `reformat_time()` | LibraryFunctions.php |
| 5 | `format_date_and_time()` | LibraryFunctions.php |
| 6 | `datetoISO8601()` | LibraryFunctions.php |
| 7 | `getTimezoneFromPoint()` | LibraryFunctions.php |
| 8 | `diff_mins()` (commented out) | LibraryFunctions.php |

### Phase 2: Replaced callers then deleted

#### 9. `get_timezone_corrected_time()` — SystemBase.php (3 callers)

Replaced with `LibraryFunctions::convert_time($obj->get($key), 'UTC', $session->get_timezone(), $format)`

| File | Before | After |
|------|--------|-------|
| `adm/admin_event.php` | `$event->get_timezone_corrected_time('evt_start_time', ...)` | `LibraryFunctions::convert_time($event->get('evt_start_time'), 'UTC', $session->get_timezone(), ...)` |
| `adm/admin_event.php` | `$event->get_timezone_corrected_time('evt_end_time', ...)` | `LibraryFunctions::convert_time($event->get('evt_end_time'), 'UTC', $session->get_timezone(), ...)` |
| `adm/admin_scheduled_tasks.php` | `$task->get_timezone_corrected_time('sct_last_run_time', ...)` | `LibraryFunctions::convert_time($task->get('sct_last_run_time'), 'UTC', $session->get_timezone(), ...)` |

#### 10. `get_current_time()` — LibraryFunctions.php (1 caller)

Inlined as `(new DateTime('now', new DateTimeZone($tz)))->format($format)`

| File | After |
|------|-------|
| `data/coupon_codes_class.php` | `$current_time = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d, H:i:s');` |

#### 11. `get_current_time_obj()` — LibraryFunctions.php (6 callers)

Replaced with `new DateTime('now', new DateTimeZone($tz))`

| File |
|------|
| `views/events.php` |
| `theme/tailwind/views/events.php` |
| `theme/phillyzouk/views/events.php` |
| `theme/zoukphilly/index.php` |
| `adm/admin_api_keys.php` |
| `adm/logic/admin_api_key_logic.php` |

#### 12. `get_time_obj()` — LibraryFunctions.php (7 callers)

Replaced with `new DateTime($time, new DateTimeZone($tz))`

| File |
|------|
| `data/events_class.php` (2 calls) |
| `data/event_sessions_class.php` (2 calls) |
| `theme/tailwind/views/events.php` |
| `views/events.php` |
| `theme/zoukphilly/index.php` |

#### 13. `DatetimeIntoDaysAgo()` — LibraryFunctions.php (2 callers)

Inlined as `intval(time() / 86400) - intval($dt->format('U') / 86400)`

| File |
|------|
| `data/users_class.php` (2 calls) |

#### 14. `toDBTime()` — LibraryFunctions.php (3 active callers + 1 commented-out)

Each caller replaced differently based on context:

| File | Strategy |
|------|----------|
| `includes/FormWriterV2Base.php` | Eliminated string round-trip — convert hour/minute/ampm parts directly to 24h with inline math |
| `adm/admin_orders.php` (2 calls) | Replaced hardcoded `toDBTime('12:01:00 am')` / `toDBTime('12:59:59 pm')` with literal `'00:01:00'` / `'12:59:59'` |
| `plugins/bookings/admin/admin_booking_edit.php` | Replaced with `(new DateTime($time_string))->format('H:i:s')` |

### Phase 3: Eliminate raw DateTime for comparisons

Replaced DateTime object comparisons with UTC string comparisons using `gmdate('Y-m-d H:i:s')`. DB timestamps are ISO-formatted UTC strings, so string comparison works correctly.

| File | Change |
|------|--------|
| `views/events.php` | `$now` → `$now_utc = gmdate(...)`, removed `$event_time` DateTime, compare `$evt_start_time > $now_utc` directly |
| `theme/tailwind/views/events.php` | Same — `$now`/`$event_time` → `$now_utc` string comparison |
| `theme/phillyzouk/views/events.php` | Removed unused `$now` DateTime |
| `theme/zoukphilly/index.php` | Removed unused `$now` and `$event_time` DateTimes |
| `adm/admin_api_keys.php` | `$now` → `$now_utc = gmdate(...)`, string comparison |
| `adm/admin_api_key.php` | `$now` → `$now_utc` string comparison |
| `adm/logic/admin_api_key_logic.php` | `$now` → `$now_utc`, passed as string in page_vars |
| `data/coupon_codes_class.php` | `(new DateTime(...))->format('Y-m-d, H:i:s')` → `gmdate('Y-m-d H:i:s')` (also fixed comma in format) |

**Exception:** `data/events_class.php` and `data/event_sessions_class.php` still use `new DateTime()` for the Spatie calendar-links library, which requires DateTime objects. These are the only legitimate raw DateTime uses.

## Summary

| Metric | Count |
|--------|-------|
| Methods removed | 13 (10 from LibraryFunctions, 3 from SystemBase) |
| Methods kept | 2 (`convert_time`, `time_shift` in LibraryFunctions) |
| Files modified | 19 |

### All Files Modified

| File | Changes |
|------|---------|
| `includes/LibraryFunctions.php` | Delete 10 methods |
| `includes/SystemBase.php` | Delete 3 methods |
| `includes/FormWriterV2Base.php` | Inline 12h→24h conversion replacing toDBTime |
| `adm/admin_event.php` | 2 replacements — convert_time |
| `adm/admin_scheduled_tasks.php` | 1 replacement — convert_time |
| `adm/admin_api_keys.php` | DateTime → gmdate string comparison |
| `adm/admin_api_key.php` | DateTime → string comparison |
| `adm/admin_orders.php` | 2 replacements — hardcoded time literals |
| `adm/logic/admin_api_key_logic.php` | DateTime → gmdate, pass string in page_vars |
| `data/coupon_codes_class.php` | DateTime → gmdate |
| `data/users_class.php` | 2 inlined DatetimeIntoDaysAgo |
| `data/events_class.php` | 2 replacements — direct DateTime for calendar lib |
| `data/event_sessions_class.php` | 2 replacements — direct DateTime for calendar lib |
| `views/events.php` | DateTime → gmdate string comparison |
| `theme/tailwind/views/events.php` | DateTime → gmdate string comparison |
| `theme/phillyzouk/views/events.php` | Removed unused DateTime |
| `theme/zoukphilly/index.php` | Removed unused DateTimes |
| `plugins/bookings/admin/admin_booking_edit.php` | DateTime::format replacing toDBTime |
| `CLAUDE.md` | Removed deleted SystemBase method references |
