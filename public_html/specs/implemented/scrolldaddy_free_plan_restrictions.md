# ScrollDaddy Free Plan Restrictions

## Overview

Implement server-side restrictions for free-tier ScrollDaddy accounts to minimize DNS server load.
The free plan offers: 1 device, scheduled blocks, no custom rules, no malware/phishing filters.

## Items to Implement

### 1. Disable Query Logging for Free Tier

**Goal:** Force query logging off for free accounts with no option to enable it.

Query logging causes the DNS server to store every DNS query for the device — significant I/O overhead at scale. Free users do not need this feature.

**Changes required:**

**`plugins/scrolldaddy/views/profile/device_edit.php`**
- Wrap the query logging toggle in a tier check
- If the user does NOT have `scrolldaddy_query_logging` (new feature, see below): hide the toggle entirely and render a locked/upgrade prompt instead
- The hidden input should NOT be included (omitting it means no POST value = off)

**`plugins/scrolldaddy/logic/device_edit_logic.php`**
- When saving a device, check `scrolldaddy_query_logging` tier feature
- If the user does not have it, force `sdd_log_queries` to `false` regardless of POST data
- This prevents bypass via raw POST

**`plugins/scrolldaddy/tier_features.json`**
- Add new feature: `scrolldaddy_query_logging` (boolean, default `false`)
- Set to `true` for paid tiers

**Default state:** Existing free users who have logging enabled should be unaffected until they next edit their device (at which point the logic layer will force it off). Alternatively, a migration can force-disable logging for all users without the feature — decide at implementation time.

---

### 2. Filter Category Gating — Server-Side Enforcement (Bug Fix)

**Status:** UI is already gating malware/phishing/fakenews filters via `scrolldaddy_advanced_filters` tier check in `views/profile/filters_edit.php` (line ~101-118) and `views/profile/scheduled_block_edit.php` (line ~193). However, **there is no server-side validation** — a user can bypass this by POSTing directly.

**This is a security/integrity bug, not a new feature.**

**Changes required:**

**`plugins/scrolldaddy/logic/filters_edit_logic.php`**
- After the malware dropdown expansion (lines 39–53) but **before** the `update_remote_filters()` call, check `scrolldaddy_advanced_filters` tier feature
- If the user does not have it, unset the `block_`-prefixed restricted keys from `$_POST`
- The strip must happen **after** the dropdown expansion because the expansion converts `block_malware = 'ip_malware'` into `block_ip_malware = 1` — stripping before expansion would miss a user who POSTs `block_ip_malware=1` directly, bypassing the dropdown entirely

```php
// After dropdown expansion, before update_remote_filters():
if(!SubscriptionTier::getUserFeature($session->get_user_id(), 'scrolldaddy_advanced_filters', false)){
    foreach(ScrollDaddyHelper::getRestrictedFilters() as $key){
        unset($_POST['block_'.$key]);
    }
}
```

- Restricted filter keys: `malware`, `ip_malware`, `ai_malware`, `typo`, `fakenews`
- These map to POST keys: `block_malware`, `block_ip_malware`, `block_ai_malware`, `block_typo`, `block_fakenews`

**`plugins/scrolldaddy/logic/scheduled_block_edit_logic.php`**
- Same strip logic, placed before the `$block->update_filters($post_vars)` call (line 80)
- No dropdown expansion runs in this logic file, but strip all restricted `block_` keys regardless to guard against direct POST bypass

**`plugins/scrolldaddy/includes/ScrollDaddyHelper.php`**
- Add a static method `getRestrictedFilters()` returning `['malware', 'ip_malware', 'ai_malware', 'typo', 'fakenews']`
- This is the single canonical definition used by both logic files — do not duplicate the list inline

---

### 3. Cap Scheduled Blocks at 1 for Free Tier

**Goal:** Free accounts may create at most 1 scheduled block per device.

Scheduled blocks each create a secondary profile config that the DNS server must track and evaluate per query. Limiting free users to 1 reduces per-device config size on the DNS server.

**Changes required:**

**`plugins/scrolldaddy/tier_features.json`**
- Add new feature: `scrolldaddy_max_scheduled_blocks` (integer, default `1`)
- Set to a higher value (e.g., `10`) for paid tiers, or `null`/`0` for unlimited

**`plugins/scrolldaddy/logic/scheduled_block_edit_logic.php`**
- On the **create** path (when `$_POST['action'] == 'create'` or no existing block ID), count existing non-deleted scheduled blocks for the device
- If count >= `scrolldaddy_max_scheduled_blocks`, return an error: *"Your plan allows 1 scheduled block per device. Upgrade to add more."*
- Skip this check on the **edit** path (don't lock someone out of editing their existing block)

**`plugins/scrolldaddy/views/profile/devices.php`** (line 182 — the "Add" scheduled block button)
- Before rendering the "Add" button for a device, check if `count($device_blocks) >= $max_blocks` where `$max_blocks = $tier ? $tier->getFeature('scrolldaddy_max_scheduled_blocks', 1) : 1`
- If at the limit, replace the button with a locked/upgrade prompt
- `$tier` is already available in `$page_vars` from `devices_logic.php`; `$device_blocks` is `$scheduled_blocks[$device->key]` already loaded in the loop

**Count query (logic layer):** Use `(new MultiSdScheduledBlock(['device_id' => $device->key]))->count_all()` — `MultiSdScheduledBlock` excludes deleted records by default.

---

## Tier Feature Summary

| Feature | Free Default | Paid |
|---|---|---|
| `scrolldaddy_max_devices` | `1` | higher |
| `scrolldaddy_custom_rules` | `false` | `true` |
| `scrolldaddy_advanced_filters` | `false` | `true` |
| `scrolldaddy_query_logging` *(new)* | `false` | `true` |
| `scrolldaddy_max_scheduled_blocks` *(new)* | `1` | `10` (or unlimited) |

---

## Implementation Notes

- All tier checks must be **server-side** (in logic files), not UI-only
- UI checks in views are acceptable as UX aids but must never be the sole enforcement
- Use `SubscriptionTier::getUserFeature($session->get_user_id(), 'feature_name', $default)` for tier checks
- The `tier_features.json` changes take effect immediately once saved — existing tier assignments will inherit the new feature defaults automatically
