# ScrollDaddy Schedule Redesign: Scheduled Blocks

**Status:** Draft  
**Date:** 2026-04-03

---

## Problem

The current scheduling system uses a ControlD-inspired "two profile" model that is confusing:

1. **Users must manage two complete filter profiles** — a "primary" (always on) and a "secondary" (scheduled). Each has its own independent set of filters, services, and custom rules. Users must reason about which profile is "active" at any given time.

2. **Midnight-crossing schedules are blocked.** The form rejects `start >= end`, so a user who wants to block gaming from 10 PM to 6 AM cannot configure this. The DNS resolver already handles overnight ranges correctly — the UI just prevents creating them.

3. **Only one schedule per device.** A user cannot say "block social media on weekdays 9 AM–5 PM" AND "block gaming every night 10 PM–7 AM." They'd need to pick one.

4. **The mental model is backwards.** Users think "block X during Y times." The current system forces them to think "configure a whole separate profile and schedule when it takes over."

---

## Design

Replace the two-profile model with **one base profile plus multiple scheduled blocks**.

### Concept

- **Base profile** — always active. The filters, services, and custom rules that apply 24/7.
- **Scheduled blocks** — each one defines a time window and a list of rules. Each rule within a block has its own action:
  - **Block:** "also block this category during this time" — adds a restriction on top of the base.
  - **Allow:** "allow this category during this time" — temporarily lifts a base restriction.
  
A single scheduled block can mix block and allow rules. For example, a "Bedtime" block could block social media AND allow gaming in the same time window.

Multiple blocks can be active simultaneously. Resolution order: start with base filters, union all active block rules, then subtract all active allow rules. **Allow always wins** — if a block rule and an allow rule target the same category, the allow takes precedence.

### User-Facing Language

| Old | New |
|-----|-----|
| Primary blocklist | Always-On Filters |
| Secondary blocklist | *(removed)* |
| *(none)* | Scheduled Block |

### Examples

**Example 1: Parental controls with bedtime**
```
Always-On Filters: adult content, gambling, drugs

Scheduled Blocks:
  "School Hours"   Mon–Fri, 7:00 AM – 3:00 PM
      Block: gaming, social media, YouTube

  "Bedtime"        Every day, 9:00 PM – 7:00 AM  (overnight ✓)
      Block: gaming, social media, YouTube, news, shopping
```

**Example 2: Gaming allowed only on Saturday mornings**
```
Always-On Filters: adult content, gambling, drugs, gaming

Scheduled Blocks:
  "Saturday Gaming"  Sat, 10:00 AM – 12:00 PM
      Allow: gaming
```
Gaming is blocked 24/7 by the base, except Saturday 10 AM–12 PM when the allow rule lifts it.

**Example 3: Mixed block and allow in one schedule**
```
Always-On Filters: adult content, drugs, gaming

Scheduled Blocks:
  "Bedtime"          Every day, 9:00 PM – 7:00 AM
      Block: social media, news, shopping
      Allow: gaming
```
At bedtime, social media/news/shopping are additionally blocked, but the base gaming block is lifted (reward for getting off social media).

---

## Data Model Changes

### New Table: `sdb_scheduled_blocks`

| Column | Type | Description |
|--------|------|-------------|
| `sdb_scheduled_block_id` | serial PK | |
| `sdb_sdd_device_id` | int4, FK → sdd_devices | Which device this block belongs to |
| `sdb_name` | varchar(64) | User-given name ("Bedtime", "School Hours") |
| `sdb_schedule_start` | varchar(5) | HH:MM format, e.g. "21:00" |
| `sdb_schedule_end` | varchar(5) | HH:MM format, e.g. "07:00" |
| `sdb_schedule_days` | varchar(128) | JSON array, e.g. `["mon","tue","wed","thu","fri"]` |
| `sdb_schedule_timezone` | varchar(64) | Timezone string, e.g. "America/New_York" |
| `sdb_is_active` | bool | Soft toggle for enabling/disabling |
| `sdb_create_time` | timestamp | |
| `sdb_delete_time` | timestamp | Soft delete |

### New Table: `sbf_scheduled_block_filters`

Reuses the same filter key vocabulary as `sdf_filters`.

| Column | Type | Description |
|--------|------|-------------|
| `sbf_scheduled_block_filter_id` | serial PK | |
| `sbf_sdb_scheduled_block_id` | int4, FK → sdb_scheduled_blocks | |
| `sbf_filter_key` | varchar(32) | Same keys as sdf: "ads", "porn", "games", etc. |
| `sbf_action` | int2 | 0 = block (add restriction), 1 = allow (lift restriction). Matches `sdr_rules` convention. |

### New Table: `sbs_scheduled_block_services`

Reuses the same service key vocabulary as `sds_services`.

| Column | Type | Description |
|--------|------|-------------|
| `sbs_scheduled_block_service_id` | serial PK | |
| `sbs_sdb_scheduled_block_id` | int4, FK → sdb_scheduled_blocks | |
| `sbs_service_key` | varchar(32) | Same keys as sds: "facebook", "youtube", etc. |
| `sbs_action` | int2 | 0 = block, 1 = allow. Matches `sdr_rules` convention. |

### Removed from `sdd_devices`

- `sdd_sdp_profile_id_secondary` — no longer needed. The base profile stays as `sdd_sdp_profile_id_primary`.

### Removed from `sdp_profiles`

- `sdp_schedule_start` — schedule data moves to `sdb_scheduled_blocks`
- `sdp_schedule_end`
- `sdp_schedule_days`
- `sdp_schedule_timezone`

These columns can be dropped after migration (see Migration section).

### New Table: `sbr_scheduled_block_rules`

Custom domain allow/block rules per scheduled block.

| Column | Type | Description |
|--------|------|-------------|
| `sbr_scheduled_block_rule_id` | serial PK | |
| `sbr_sdb_scheduled_block_id` | int4, FK → sdb_scheduled_blocks | |
| `sbr_hostname` | varchar(128) | Domain to block or allow |
| `sbr_is_active` | int2 | 0 or 1 |
| `sbr_action` | int2 | 0 = block, 1 = allow. Matches `sdr_rules` convention. |

### Unchanged

- `sdd_sdp_profile_id_primary` on devices — still links to the base profile
- `sdf_filters` / `sds_services` / `sdr_rules` — still linked to the base profile for always-on config
- SafeSearch and SafeYouTube remain on the base profile only.

---

## Midnight-Crossing Schedules

A scheduled block where `start > end` (e.g., 21:00 → 07:00) means the block spans midnight.

**Evaluation logic** (same as current DNS resolver logic, now applied per-block):

```
if end < start:
    active = (current_time >= start) OR (current_time < end)
else:
    active = (current_time >= start) AND (current_time < end)
```

There is **no validation restriction** on start vs. end. Any combination is valid.

### Day Semantics for Overnight Blocks

When a block spans midnight, the **start day** determines when it activates. Example:

- Block: Mon–Fri, 9 PM – 7 AM
- Monday 10 PM → active (Monday is a scheduled day, and 10 PM >= 9 PM)
- Tuesday 3 AM → this is the continuation of Monday's block. Active because Monday's block hasn't ended yet (3 AM < 7 AM).
- Saturday 10 PM → NOT active (Saturday is not a scheduled day)
- Sunday 3 AM → NOT active (the previous day, Saturday, was not scheduled)

**Important:** The "continuation into the next day" check must look at whether the *previous* day was a scheduled day:

```
if end < start:  // overnight block
    if today in scheduled_days and current_time >= start:
        active = true
    else if yesterday in scheduled_days and current_time < end:
        active = true
    else:
        active = false
```

This is a change from the current resolver logic, which only checks today's day. The current approach would incorrectly activate Tuesday's block during the early-morning continuation of Monday's block even if Tuesday itself isn't scheduled. Document this edge case for the Go implementation.

---

## Edit-Day Restrictions

The existing `are_filters_editable()` logic (Sunday-only edits, first-24-hours grace period, admin override) applies to scheduled blocks the same way it applies to the base profile's social media / gaming / etc. filters.

Ads and malware rules within scheduled blocks remain **always editable**, consistent with current behavior on the base profile.

---

## UI Changes

### Devices List (`/profile/devices`)

For each device, show:

```
[Device Name]                                    [Last seen: 5m ago]

Always-On Filters (12 blocked)                        [Edit]

Scheduled Blocks:
  School Hours  — Mon–Fri, 7:00 AM – 3:00 PM (4 rules)     [Edit] [Delete]
  Bedtime       — Every day, 9:00 PM – 7:00 AM (5 rules)    [Edit] [Delete]
                                                      [+ Add Scheduled Block]
```

Show "Active now" badge next to any scheduled block that is currently in effect.

If no scheduled blocks exist, show:

```
Scheduled Blocks: None                            [+ Add Scheduled Block]
```

### Scheduled Block Edit Page (`/profile/scheduled_block_edit`)

**URL parameters:** `device_id` (required), `block_id` (optional — omit for new block)

**Form sections:**

1. **Name** — text input, e.g. "Bedtime", "School Hours", "Weekend Fun"

2. **Schedule** — time pickers and day checkboxes:
   - Start time (dropdown, hourly: 12:00 AM through 11:00 PM)
   - End time (dropdown, same options)
   - If end is earlier than start, show helper text: "This schedule spans overnight (crosses midnight)"
   - Day checkboxes: Mon through Sun
   - Shortcut links: "Weekdays" (selects Mon–Fri), "Every day" (selects all)

3. **Rules** — for each category/service, a three-state selector:
   - **—** (default): no change from base profile during this time
   - **Block**: additionally block this category during this time
   - **Allow**: lift the base restriction for this category during this time
   
   Implementation: a dropdown per category with options `[— / Block / Allow]`, or three radio buttons per row. Group by section (Social Media, Messaging, Gaming, etc.) same as the current filters_edit page.
   
   All categories from the current filters_edit.php are available, including ads/malware (gated behind `scrolldaddy_advanced_filters` tier feature, same as on the base profile).

4. Submit button

**Example form state for "Bedtime" block:**
```
Social Media:  [Block ▾]
Gaming:        [Allow ▾]
YouTube:       [Block ▾]
News:          [Block ▾]
Shopping:      [Block ▾]
Messaging:     [ — ▾]      ← no change from base during bedtime
```

### Always-On Filters Edit Page (`/profile/filters_edit`)

This page simplifies. Remove:
- The secondary profile section (schedule time pickers, day checkboxes)
- The `profile_choice` parameter
- The "Create Scheduled Blocklist" flow

Keep:
- All filter/service checkboxes (these are the always-on base filters)
- Ads and malware dropdowns (still gated behind `scrolldaddy_advanced_filters`)
- Phishing and clickbait checkboxes
- Custom rules link
- SafeSearch / SafeYouTube toggles (if present)

### Profile Delete Page

Remove `/profile/profile_delete` (was for deleting the secondary profile). Scheduled blocks are deleted directly from the devices list via the Delete button, with a simple confirmation prompt.

---

## DNS Server Communication

The Go DNS server and the PHP application never communicate directly. They share the PostgreSQL database:

```
PHP app (writes)  ──→  PostgreSQL  ←──  Go DNS server (reads)
```

- **PHP** writes to `sdb_scheduled_blocks`, `sbf_scheduled_block_filters`, and `sbs_scheduled_block_services` when users create or edit scheduled blocks.
- **Go DNS server** reads these tables on its lightweight reload cycle (every 60 seconds by default). Schedule changes take effect within one reload interval — no push notification or API call needed.
- The Go server caches everything in memory. Each reload builds new data structures and swaps them in atomically.

This means: after a user saves a scheduled block, it will be active in DNS resolution within ~60 seconds.

### Schema Validation

The Go server validates expected tables and columns at startup (see `internal/db/db.go`). The three new tables and their columns must be added to the `expectedSchema` map. If validation fails, the server refuses to start — this catches PHP-side schema changes immediately.

---

## DNS Resolver Changes

### New Resolution Logic

Replace step 2 ("Determine Active Profile") in the current resolver spec:

```
2. DETERMINE ACTIVE FILTERS
   a. Load base profile (device.PrimaryProfileID) → get ProfileInfo
      - Base profile's EnabledCategories, CustomBlocked, CustomAllowed
   b. Start with effective_filters = base EnabledCategories (copy)
   c. For each scheduled block on this device:
      - Check if currently active (day + time check, with overnight support)
      - If active, iterate its rules:
        - action=0 (block): add the filter/service key to effective_filters
        - action=1 (allow): add the filter/service key to allow_set
   d. Subtract allow_set from effective_filters
   e. Result: effective_filters is the final set used for blocking

   Allow always wins: if any active rule allows a category, it is removed
   from the effective set — regardless of base or block rules.

   Custom domain rules: base profile rules are always active. Each active
   scheduled block's custom rules (sbr_scheduled_block_rules) are also
   applied — block rules are added, allow rules override.
   SafeSearch/SafeYouTube come from the base profile only.
```

### Cache Structure Changes

```go
type DeviceInfo struct {
    DeviceID         int64
    ResolverUID      string
    PrimaryProfileID int64
    IsActive         bool
    Timezone         *time.Location
    ScheduledBlocks  []ScheduledBlock  // NEW — replaces secondary profile fields
}

type ScheduledBlock struct {
    BlockID          int64
    Name             string
    ScheduleStart    string            // "HH:MM"
    ScheduleEnd      string            // "HH:MM"
    ScheduleDays     []string          // ["mon", "tue", ...]
    ScheduleTimezone *time.Location
    BlockKeys        []string          // action=0: categories to add    ["games", "facebook"]
    AllowKeys        []string          // action=1: categories to lift   ["youtube"]
}
```

Remove `SecondaryProfileID`, `ScheduleStart`, `ScheduleEnd`, `ScheduleDays`, `ScheduleTimezone` from `DeviceInfo`.

### Updated DB Queries

**Query 1 (devices):** Remove the LEFT JOIN on secondary profile. Only join primary profile.

**New Query: Load scheduled blocks with their filters**

```sql
-- Query A: Load scheduled block metadata
SELECT
    sdb_scheduled_block_id,
    sdb_sdd_device_id,
    sdb_name,
    sdb_schedule_start,
    sdb_schedule_end,
    sdb_schedule_days,
    sdb_schedule_timezone
FROM sdb_scheduled_blocks
WHERE sdb_delete_time IS NULL
  AND sdb_is_active = TRUE;

-- Query B: Load all scheduled block filter rules
SELECT
    sbf_sdb_scheduled_block_id AS block_id,
    sbf_filter_key AS key,
    sbf_action AS action
FROM sbf_scheduled_block_filters;

-- Query C: Load all scheduled block service rules
SELECT
    sbs_sdb_scheduled_block_id AS block_id,
    sbs_service_key AS key,
    sbs_action AS action
FROM sbs_scheduled_block_services;
```

When building the cache: group blocks by device_id, then for each block, partition its filter/service rules by action (0 → BlockKeys, 1 → AllowKeys) and attach the `[]ScheduledBlock` slice to the `DeviceInfo`.

### /test Endpoint Changes

Update the diagnostic response to show which scheduled blocks are active and what action triggered the result:

```json
{
  "uid": "a1b2c3d4...",
  "domain": "youtube.com",
  "result": "BLOCKED",
  "reason": "scheduled_block_rule",
  "block_name": "Bedtime",
  "block_id": 7,
  "action": "block",
  "category": "youtube",
  "active_scheduled_blocks": ["Bedtime", "School Hours"]
}
```

For an allowed-by-schedule domain:
```json
{
  "uid": "a1b2c3d4...",
  "domain": "store.steampowered.com",
  "result": "FORWARDED",
  "reason": "scheduled_allow_rule",
  "block_name": "Saturday Gaming",
  "block_id": 12,
  "action": "allow",
  "category": "games",
  "note": "Would be blocked by base profile, but allowed by active schedule rule"
}
```

---

## PHP Model Classes

### New: `SdScheduledBlock` (extends SystemBase)

File: `/plugins/scrolldaddy/data/scheduled_blocks_class.php`

```php
class SdScheduledBlock extends SystemBase {
    public static $prefix = 'sdb';
    public static $tablename = 'sdb_scheduled_blocks';
    public static $pkey_column = 'sdb_scheduled_block_id';
    // Standard SystemBase fields...
}
```

Key methods:
- `update_filters($post_vars)` — sync filter checkboxes (same pattern as `SdProfile::update_remote_filters`)
- `update_services($post_vars)` — sync service checkboxes
- `is_active_now()` — evaluate schedule against current time (for display purposes)
- `get_schedule_display()` — human-readable schedule string, e.g. "Mon–Fri, 9:00 PM – 7:00 AM"
- `authenticate_write($data)` — verify ownership via device → user chain

### New: `MultiSdScheduledBlock` (extends SystemMultiBase)

Option keys: `device_id`, `is_active`, `deleted` (false = sdb_delete_time IS NULL)

### New: `SdScheduledBlockFilter` and `SdScheduledBlockService`

Minimal model classes mirroring `SdFilter` and `SdService` but keyed off `sdb_scheduled_block_id` instead of `sdp_profile_id`.

### Changes to `SdDevice`

- Remove `get_active_profile()` — replace with `get_active_blocks()` returning array of active `SdScheduledBlock` objects
- Remove `get_time_to_active_profile()` — replace with `get_next_block_change()` showing when the next block starts or ends
- Remove `get_schedule_string()` — each block has its own `get_schedule_display()`
- Keep `permanent_delete()` — update cascade to also delete scheduled blocks for the device

### Changes to `SdProfile`

- Remove `add_or_edit_schedule()` — schedule data moves to scheduled blocks
- Remove `permanent_delete_schedule()`
- Keep `update_remote_filters()`, `update_remote_services()`, `add_rule()`, `delete_rule()` for the base profile

---

## Tier Feature Gating

No new tier features needed. Scheduled blocks are available to all users.

Consider adding a tier feature in the future for max number of scheduled blocks per device (e.g., free = 2, paid = unlimited), but do not implement gating now.

---

## Migration

Since there are no active users, migration is straightforward:

1. Create the three new tables (`sdb_scheduled_blocks`, `sbf_scheduled_block_filters`, `sbs_scheduled_block_services`) via data class auto-creation.

2. For any device with a `sdd_sdp_profile_id_secondary`:
   - Create a `sdb_scheduled_block` using the secondary profile's schedule fields
   - Copy the secondary profile's active filters to `sbf_scheduled_block_filters`
   - Copy the secondary profile's active services to `sbs_scheduled_block_services`
   - Delete the secondary profile
   - NULL out `sdd_sdp_profile_id_secondary`

3. Drop the schedule columns from `sdp_profiles` (or leave them — they'll simply be unused).

4. Update the Go DNS server schema validation and queries.

Since there are no users, step 2 can be skipped if no test data needs preserving. Just drop the secondary profile links and old schedule columns.

---

## Implementation Order

1. **Data model** — Create `SdScheduledBlock`, `SdScheduledBlockFilter`, `SdScheduledBlockService` classes and multi classes.
2. **Logic** — Create `scheduled_block_edit_logic.php`. Modify `filters_edit_logic.php` to remove secondary profile handling. Modify `devices_logic.php` to load scheduled blocks per device.
3. **Views** — Create `scheduled_block_edit.php`. Update `devices.php` to show blocks list. Simplify `filters_edit.php`.
4. **Routing** — Add route for `/profile/scheduled_block_edit`. Remove `/profile/profile_delete` if no longer needed.
5. **Device model** — Update `SdDevice` methods (remove old schedule methods, add block-related methods, update cascade delete).
6. **Profile model** — Remove schedule methods from `SdProfile`.
7. **DNS server** — Update Go cache structures, DB queries, and resolver logic.
8. **Cleanup** — Remove unused secondary profile code, old schedule fields.

---

## Out of Scope

- **Per-block SafeSearch/SafeYouTube.** These stay on the base profile.
- **Minute-level time granularity.** Keep hourly intervals for now.
- **Max blocks per device tier gating.** Noted above as future consideration.
