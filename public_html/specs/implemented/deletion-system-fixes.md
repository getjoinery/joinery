# Deletion System Fixes

## Background

Investigation prompted by attempting to permanently delete a user (Ro Westin, usr_user_id=597) on the ScrollDaddy production server. The deletion would fail because `del_deletion_rules` references tables that were renamed during the ctld-to-sd migration. Deeper investigation revealed three systemic issues beyond the stale data.

## Issues Found

### Issue 1: Stale deletion rules after table rename (ScrollDaddy-specific)

**Root cause**: When the ctld tables were renamed to sd tables (e.g., `cdd_ctlddevices` -> `sdd_devices`), `update_database` was never run on the ScrollDaddy production server afterward. The auto-registration system (`DeletionRule::registerModelsFromDiscovery()`) was never invoked to pick up the new SD model classes.

**Why the old rules persisted**: The registration system works by deleting rules WHERE `del_target_table = {current model table}`, then re-creating them. Since the old ctld model classes no longer exist, nothing claims those old target tables, so nothing deletes their rules. And since the new SD classes were never registered, no new rules were created.

**7 stale rules** in production `del_deletion_rules`:

| del_id | del_source_table | del_target_table | del_target_column |
|--------|------------------|------------------|-------------------|
| 1704 | ctlddevice_backups | cdb_ctlddevice_backups | cdb_ctlddevice_backup_id |
| 1705 | usr_users | cdb_ctlddevice_backups | cdb_usr_user_id |
| 1706 | usr_users | cdd_ctlddevices | cdd_usr_user_id |
| 1707 | cdp_ctldprofiles | cdf_ctldfilters | cdf_cdp_ctldprofile_id |
| 1708 | usr_users | cdp_ctldprofiles | cdp_usr_user_id |
| 1709 | cdp_ctldprofiles | cdr_ctldrules | cdr_cdp_ctldprofile_id |
| 1710 | cdp_ctldprofiles | cds_ctldservices | cds_cdp_ctldprofile_id |

All reference tables that no longer exist. When `permanent_delete()` tries to count records in these tables, PostgreSQL throws "relation does not exist" and the entire transaction rolls back.

**Fix**: Run `update_database` on ScrollDaddy production (registers new SD rules), then manually delete the 7 stale rules.

### Issue 2: No multi-level cascade in permanent_delete()

**Root cause**: `SystemBase::permanent_delete()` queries `del_deletion_rules WHERE del_source_table = {this table}` and performs flat SQL DELETEs for cascade actions. It does NOT recursively process deletion rules for the cascade-deleted tables.

**Example**: User deletion with the ScrollDaddy data model:

```
usr_users (level 0 - being deleted)
  |-- sdd_devices (level 1) -- cascade DELETE via flat SQL -- OK
  |-- sdp_profiles (level 1) -- cascade DELETE via flat SQL -- OK
  |-- sddb_device_backups (level 1) -- cascade DELETE via flat SQL -- OK
  |
  |   But these are ORPHANED (level 2 - never reached):
  |     sdp_profiles --x--> sdf_filters (rules exist but never triggered)
  |     sdp_profiles --x--> sdr_rules
  |     sdp_profiles --x--> sds_services
```

When `permanent_delete()` runs on a User:
1. It finds `usr_users -> sdp_profiles` (cascade) and does `DELETE FROM sdp_profiles WHERE sdp_usr_user_id = ?`
2. The profiles are deleted from the DB
3. The rules `sdp_profiles -> sdf_filters` exist in `del_deletion_rules` but are NEVER consulted, because the system only looks up rules for `usr_users`, not for `sdp_profiles`
4. `sdf_filters`, `sdr_rules`, `sds_services` records are left orphaned

**This is a general limitation affecting any data model with 3+ levels of depth.** Not ScrollDaddy-specific.

### Issue 3: `permanent_delete` action type not handled

Three model classes declare `'action' => 'permanent_delete'` in `$foreign_key_actions`:

- `ConversationParticipant`: `cnp_usr_user_id`, `cnp_cnv_conversation_id`
- `Reaction`: `rct_usr_user_id`
- `Notification`: `ntf_usr_user_id`

But `SystemBase::permanent_delete()` has no `case 'permanent_delete':` in its switch statement (only `prevent`, `cascade`, `null`, `set_value`). These rules are silently ignored -- the dependent records are left behind when a parent is deleted.

The intent was likely to load each dependent record as a model object and call its `permanent_delete()` method (enabling custom cleanup logic). But this was never implemented.

### Issue 4: Model permanent_delete() overrides are bypassed by cascade

`SdDevice::permanent_delete()` and `SdProfile::permanent_delete()` contain custom logic that properly cascades through the full object tree:

```
SdDevice::permanent_delete()
  -> SdProfile::permanent_delete() for primary and secondary profiles
    -> permanent_delete_all_rules()
    -> permanent_delete_all_filters()
    -> permanent_delete_all_services()
    -> delete_profile_from_device()
  -> creates SdDeviceBackup record
  -> parent::permanent_delete()
```

But when a user is permanently deleted, the system does `DELETE FROM sdd_devices WHERE sdd_usr_user_id = ?` -- a flat SQL DELETE that completely bypasses `SdDevice::permanent_delete()`. The custom cascade logic never runs.

This is actually the same root cause as Issue 2 -- flat SQL cascading cannot invoke model-level behavior. Issue 3 (`permanent_delete` action) was designed to solve this but was never implemented.

## Proposed Fixes

### Fix A: Implement the `permanent_delete` action type in SystemBase (Issues 2, 3, 4)

Add a `permanent_delete` case to the switch in `SystemBase::permanent_delete()` that loads each dependent record as a model object and calls its `permanent_delete()` method:

```php
case 'permanent_delete':
    // Load each dependent record and call its permanent_delete()
    // This enables custom cascade logic and recursive rule processing
    $model_class = self::getModelClassForTable($dep_table);
    if ($model_class) {
        $select_sql = "SELECT {$dep_pkey} FROM {$dep_table} WHERE {$dep_column} = ?";
        $select_stmt = $db->prepare($select_sql);
        $select_stmt->execute([$this->key]);
        while ($row = $select_stmt->fetch(PDO::FETCH_ASSOC)) {
            $obj = new $model_class($row[$dep_pkey], TRUE);
            $obj->permanent_delete($debug);
        }
    } else {
        // Fallback to flat cascade if model class can't be determined
        $del_sql = "DELETE FROM {$dep_table} WHERE {$dep_column} = ?";
        $del_stmt = $db->prepare($del_sql);
        $del_stmt->execute([$this->key]);
    }
    break;
```

This would:
- Fix Issue 3 directly (the action type now works)
- Fix Issue 4 when models opt in (by using `'action' => 'permanent_delete'` instead of `'action' => 'cascade'`)
- Fix Issue 2 for opted-in relationships (recursive rule processing happens naturally when child models call `parent::permanent_delete()`)

**Implementation notes**:
- Needs a `getModelClassForTable()` helper to map table names to PHP class names. Could use `LibraryFunctions::discover_model_classes()` with caching, or add a simple lookup via the `del_deletion_rules` schema.
- Slower than flat `cascade` for large datasets (loads each record individually). The `cascade` action should remain the default for high-volume tables (logs, analytics, etc.). Use `permanent_delete` only where model-level behavior matters.
- Must handle the case where the model class file is from a plugin that may not be loaded. Could require the class file based on the `del_plugin` column.

### Fix B: ScrollDaddy SD models opt in to `permanent_delete` action (Issue 2, 4 for ScrollDaddy)

After Fix A is implemented, update the SD model classes to declare `'action' => 'permanent_delete'` for relationships where model-level cascading matters:

In `plugins/scrolldaddy/data/devices_class.php`, add:
```php
protected static $foreign_key_actions = [
    'sdd_usr_user_id' => ['action' => 'permanent_delete'],
];
```

This tells the system: "When a user is deleted, don't just flat-DELETE my device records -- load each one and call `SdDevice::permanent_delete()`, which properly handles profile cascading and backup creation."

No changes needed to `SdProfile`, `SdFilter`, `SdRule`, `SdService` -- their default `cascade` action is correct since their parent (SdProfile) already has a custom `permanent_delete()` override that cleans them up.

### Fix C: Clean up stale ctld rules on production (Issue 1)

Run on ScrollDaddy production DB:
```sql
DELETE FROM del_deletion_rules WHERE del_id IN (1704, 1705, 1706, 1707, 1708, 1709, 1710);
```

Then run `update_database` on ScrollDaddy production to register the new SD model rules (or use "Sync with Filesystem" from the admin plugins page).

### Fix D: Verify existing `permanent_delete` action declarations (Issue 3)

After Fix A, verify the 3 models currently using `'action' => 'permanent_delete'` work correctly:
- `ConversationParticipant` -- loading and calling permanent_delete on each participant
- `Reaction` -- loading and calling permanent_delete on each reaction
- `Notification` -- loading and calling permanent_delete on each notification

These are currently silently broken (records left behind on user deletion).

## Implementation Order

1. **Fix A** -- Implement `permanent_delete` action in SystemBase (core fix, enables everything else)
2. **Fix B** -- SD models opt in to `permanent_delete` for `sdd_usr_user_id`
3. **Fix C** -- Clean up stale rules and run update_database on production
4. **Fix D** -- Verify existing `permanent_delete` declarations

After all fixes, deleting Ro Westin (user 597) would:
1. Find rule `usr_users -> sdd_devices` with action `permanent_delete`
2. Load each SdDevice and call `$device->permanent_delete()`
3. Which calls `SdProfile::permanent_delete()` for each profile
4. Which calls `permanent_delete_all_filters()`, `permanent_delete_all_rules()`, `permanent_delete_all_services()`
5. Then flat-cascade the remaining usr_users dependencies (orders, emails, etc.)
6. Delete the user record

All 4 of Ro Westin's records (1 device, 2 profiles, 1 filter, 3 services) would be properly cleaned up.

## Open Questions

1. **Performance**: The `permanent_delete` action loads records one-by-one. For tables with thousands of records (e.g., notifications), this could be slow. Should we add a batch-loading optimization or keep `cascade` as default for high-volume tables?

2. **Table-to-class mapping**: How should `getModelClassForTable()` resolve table names to PHP classes? Options: (a) scan all loaded classes, (b) maintain a lookup table, (c) use LibraryFunctions::discover_model_classes() with caching.

3. **Plugin class loading**: If a `permanent_delete` rule references a plugin model class, the class file may not be loaded yet. Should the system auto-load it based on the `del_plugin` column, or require plugins to be fully loaded before deletion?
