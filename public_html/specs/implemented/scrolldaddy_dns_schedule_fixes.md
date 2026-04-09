# ScrollDaddy DNS Schedule Bug Fixes

## Overview

Three related bugs in the ScrollDaddy DNS schedule system prevent secondary profile schedules from working correctly. The schedule feature allows parents to define time-of-day + day-of-week filtering rules (e.g., "block games Mon-Fri 8am-3pm"). These bugs affect both the DNS server enforcement and the Joinery-side display.

## Architecture Context

Schedule times are stored as local timezone `HH:MM` strings paired with an IANA timezone name (e.g., `America/New_York`). This is the correct design for recurring day-of-week schedules because converting to UTC would shift the day-of-week (Monday 10pm Eastern = Tuesday 3am UTC) and drift with DST changes.

The DNS server evaluates schedules at query time by converting `time.Now()` to the device's timezone and comparing against the stored local HH:MM and day-of-week values.

**Two schedule systems exist:**
- **Secondary profile schedule** (legacy): stored in `sdp_profiles` (`sdp_schedule_start`, `sdp_schedule_end`, `sdp_schedule_days`, `sdp_schedule_timezone`)
- **Scheduled blocks** (newer): stored in `sdb_scheduled_blocks` (`sdb_schedule_start`, `sdb_schedule_end`, `sdb_schedule_days`, `sdb_schedule_timezone`) — supports multiple blocks per device

## Bug 1: PHP-Serialized schedule_days Breaks DNS Server Parsing

### Problem

Two rows in the production `sdp_profiles` table store `sdp_schedule_days` as PHP-serialized strings instead of JSON:

```
profile 4: a:7:{i:0;s:3:"mon";i:1;s:3:"tue";i:2;s:3:"wed";i:3;s:3:"thu";i:4;s:3:"fri";i:5;s:3:"sat";i:6;s:3:"sun";}
profile 6: a:7:{i:0;s:3:"mon";i:1;s:3:"tue";i:2;s:3:"wed";i:3;s:3:"thu";i:4;s:3:"fri";i:5;s:3:"sat";i:6;s:3:"sun";}
```

The DNS server binary (v1.2.0) uses `json.Unmarshal()` to parse this field. JSON parsing of PHP-serialized data fails silently, returning nil. This causes `ScheduleDays` to be empty, so `isScheduleActive()` always returns false. **The secondary profile schedule never activates on the DNS server.**

The Joinery PHP side already handles this via a fallback in `SdDevice::decodeDays()` (`devices_class.php:101-109`) which tries `json_decode()` first, then falls back to `@unserialize()`. So the PHP-side display works, but DNS enforcement does not.

### Fix

**Option A (preferred): Migrate the two production rows from PHP-serialized to JSON.**

Run on the production ScrollDaddy database:
```sql
-- Profile 4: all days
UPDATE sdp_profiles
SET sdp_schedule_days = '["mon","tue","wed","thu","fri","sat","sun"]'
WHERE sdp_profile_id = 4;

-- Profile 6: all days
UPDATE sdp_profiles
SET sdp_schedule_days = '["mon","tue","wed","thu","fri","sat","sun"]'
WHERE sdp_profile_id = 6;
```

After migration, the `decodeDays()` fallback in `devices_class.php` can be simplified to JSON-only, or left as-is for safety.

**Option B (defense in depth): Also add PHP-serialized fallback parsing to the DNS server.**

This is more complex (Go has no native PHP unserialize) and shouldn't be needed if Option A is applied and future writes always use JSON (which they do -- `profiles_class.php:219` uses `json_encode()`).

### Files

| File | Lines | Role |
|------|-------|------|
| `plugins/scrolldaddy/data/devices_class.php` | 101-109 | `decodeDays()` with PHP-serialized fallback |
| `plugins/scrolldaddy/data/profiles_class.php` | 219 | Saves days as `json_encode()` (correct) |
| DNS server binary (v1.2.0) | — | `ParseScheduleDays()` uses JSON-only parsing |

### Verification

After migration, check the DNS server logs. The lightweight reload messages should continue showing devices/profiles. To verify schedule activation, use the `/test` endpoint during an active schedule window to confirm the secondary profile's filters are applied.

---

## Bug 2: `get_active_profile()` Uses Server Timezone Instead of Device Timezone

### Problem

`SdDevice::get_active_profile()` (`devices_class.php:231-232`) uses PHP's `date()` function, which returns the **web server's timezone**, not the device's schedule timezone:

```php
$current_time = date("H:i");              // server timezone
$current_day = strtolower(date("D"));     // server timezone
```

Other methods in the same class handle this correctly. For comparison, `get_time_to_active_profile()` (`devices_class.php:128-130`):

```php
$tz = new DateTimeZone($profile->get('sdp_schedule_timezone'));
$now = new DateTime('now', $tz);
$currentDay = strtolower($now->format('D'));
```

And `SdScheduledBlock::is_active_now()` (`scheduled_blocks_class.php:180-187`) also does it correctly:

```php
$tz = new DateTimeZone($tz_string);
$now = new DateTime('now', $tz);
$today = strtolower($now->format('D'));
$current_time = $now->format('H:i');
```

If the server runs UTC and the device is in `America/New_York` (UTC-5), `get_active_profile()` will evaluate the schedule 5 hours ahead of the user's actual local time. This affects what the UI shows as the "active profile" for a device.

### Fix

Replace lines 231-232 in `devices_class.php` `get_active_profile()` with timezone-aware time:

```php
$tz = new DateTimeZone($profile->get('sdp_schedule_timezone'));
$now = new DateTime('now', $tz);
$current_time = $now->format('H:i');
$current_day = strtolower($now->format('D'));
```

Also fix the time comparison logic on lines 240-248. Currently it compares `strtotime($current_time)` (a Unix timestamp) against raw `HH:MM` strings (e.g., `"09:00"`). PHP coerces `"09:00"` to integer `9`, so a Unix timestamp like `1745000000` is always greater than `9`. The comparison is broken.

Replace lines 240-248 with consistent string comparison (HH:MM strings sort lexicographically for time comparison):

```php
$start = $profile->get('sdp_schedule_start');
$end = $profile->get('sdp_schedule_end');

if ($end < $start) {
    // Overnight: active if current >= start OR current < end
    $is_in_schedule = ($current_time >= $start || $current_time < $end);
} else {
    $is_in_schedule = ($current_time >= $start && $current_time < $end);
}
```

This matches how the DNS server's `isScheduleActive()` handles the comparison (converting to minutes-since-midnight integers).

### Files

| File | Lines | Change |
|------|-------|--------|
| `plugins/scrolldaddy/data/devices_class.php` | 231-248 | Use device timezone; fix time comparison |

---

## Bug 3: DNS Server Source Code Uses Wrong Table Names

### Problem

The DNS server source code at `/tmp/scrolldaddy-dns/` on the DNS server (`45.56.103.84`) references tables that do not exist in the production ScrollDaddy database:

| Source code references | Production DB has |
|----------------------|-------------------|
| `cdd_ctlddevices` | `sdd_devices` |
| `cdp_ctldprofiles` | `sdp_profiles` |
| `cdf_ctldfilters` | `sdf_filters` |
| `cdr_ctldrules` | `sdr_rules` |
| (none) | `sdb_scheduled_blocks` |

The running binary (v1.2.0) uses the correct `sd*` table names -- confirmed via strings extraction and the fact that it successfully loads 3 devices from the production DB. This means the source code in `/tmp/` was modified **after** v1.2.0 was compiled and does not represent the running code.

The source also lacks scheduled blocks support (`sdb_scheduled_blocks`), which the running binary has (log output shows "scheduled blocks" counts).

### Fix

The source code in `/tmp/scrolldaddy-dns/` needs to be updated to match the running binary:

1. **`internal/db/db.go`**: Replace all `cdd_ctlddevices` references with `sdd_devices`, `cdp_ctldprofiles` with `sdp_profiles`, `cdf_ctldfilters` with `sdf_filters`, `cdr_ctldrules` with `sdr_rules`. Update column name prefixes accordingly (e.g., `cdd_resolver_uid` to `sdd_resolver_uid`).

2. **`internal/db/db.go`**: Add `LoadScheduledBlocks()` function to query `sdb_scheduled_blocks` with its filter/service/rule child tables.

3. **`internal/db/db.go` `ValidateSchema()`**: Update expected table/column names to match `sd*` schema.

4. **`internal/db/db.go` `ParseScheduleDays()`**: Already correct (JSON parsing), but should match what the running binary does for scheduled blocks day parsing.

5. **`internal/cache/cache.go`**: Add `ScheduledBlockInfo` struct and scheduled block storage/lookup in the cache.

6. **`internal/resolver/resolver.go`**: Add scheduled block evaluation alongside the existing secondary profile schedule logic.

**Note:** This is effectively a reverse-engineering task -- the source needs to be brought in sync with the running binary. The authoritative reference is the v1.2.0 binary behavior. If the original source that produced v1.2.0 is available elsewhere (e.g., a git repository), it should be used instead of manually updating `/tmp/`.

### Files

| File | Location | Change |
|------|----------|--------|
| `internal/db/db.go` | DNS server `/tmp/scrolldaddy-dns/` | Table names, scheduled blocks queries |
| `internal/cache/cache.go` | DNS server `/tmp/scrolldaddy-dns/` | Scheduled block data structures |
| `internal/resolver/resolver.go` | DNS server `/tmp/scrolldaddy-dns/` | Scheduled block evaluation logic |

---

## Testing Plan

1. **Bug 1**: After migrating the two rows, trigger a DNS server reload (`POST /reload`). Verify via `/stats` that devices/profiles load correctly. During a schedule window, use `GET /test?uid={uid}&domain={blocked_domain}` to confirm the secondary profile's filters are applied.

2. **Bug 2**: Log in as a user with a device that has a secondary profile schedule. Change the device's timezone to something far from the server's timezone (e.g., `Pacific/Auckland`). Verify the UI shows the correct active profile for the current time in that timezone.

3. **Bug 3**: After updating the source, compile and deploy. Verify `scrolldaddy-dns --version` shows the new version. Check logs for successful schema validation and reload. Run the full test suite (`go test ./...`).

## Priority

Bug 1 is the highest priority -- it completely prevents secondary profile schedules from working on the DNS server. Bug 2 is a display-only issue. Bug 3 blocks future DNS server development but does not affect the running system.
