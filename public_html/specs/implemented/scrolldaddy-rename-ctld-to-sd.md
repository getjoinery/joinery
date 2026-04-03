# ScrollDaddy: Rename ctld → sd (Full Schema & Code Rename)

## Goal

Remove all legacy "ctld" (ControlD) naming from the ScrollDaddy plugin. Rename database tables, columns, PHP classes, files, logic functions, URLs, and all internal references to use clean "sd" (ScrollDaddy) prefixes.

## Status: Not Started

---

## Naming Reference

### Tables

| Old | New |
|-----|-----|
| `cdp_ctldprofiles` | `sdp_profiles` |
| `cdd_ctlddevices` | `sdd_devices` |
| `cdf_ctldfilters` | `sdf_filters` |
| `cdr_ctldrules` | `sdr_rules` |
| `cds_ctldservices` | `sds_services` |
| `cdb_ctlddevice_backups` | `sddb_device_backups` |

### Column Prefix Rules

| Old prefix | New prefix | Used in |
|-----------|-----------|--------|
| `cdp_` | `sdp_` | profiles |
| `cdd_` | `sdd_` | devices |
| `cdf_` | `sdf_` | filters |
| `cdr_` | `sdr_` | rules |
| `cds_` | `sds_` | services |
| `cdb_` | `sddb_` | device_backups |

### PHP Classes

| Old | New |
|-----|-----|
| `CtldProfile` | `SdProfile` |
| `MultiCtldProfile` | `MultiSdProfile` |
| `CtldProfileException` | `SdProfileException` |
| `CtldDevice` | `SdDevice` |
| `MultiCtldDevice` | `MultiSdDevice` |
| `CtldDeviceException` | `SdDeviceException` |
| `CtldFilter` | `SdFilter` |
| `MultiCtldFilter` | `MultiSdFilter` |
| `CtldRule` | `SdRule` |
| `MultiCtldRule` | `MultiSdRule` |
| `CtldService` | `SdService` |
| `MultiCtldService` | `MultiSdService` |
| `CtldDeviceBackup` | `SdDeviceBackup` |
| `MultiCtldDeviceBackup` | `MultiSdDeviceBackup` |

### Data Files (`plugins/scrolldaddy/data/`)

| Old | New |
|-----|-----|
| `ctldprofiles_class.php` | `profiles_class.php` |
| `ctlddevices_class.php` | `devices_class.php` |
| `ctldfilters_class.php` | `filters_class.php` |
| `ctldrules_class.php` | `rules_class.php` |
| `ctldservices_class.php` | `services_class.php` |
| `ctlddevice_backups_class.php` | `device_backups_class.php` |

### Logic Files (`plugins/scrolldaddy/logic/`)

| Old | New |
|-----|-----|
| `ctlddevice_edit_logic.php` | `device_edit_logic.php` |
| `ctlddevice_delete_logic.php` | `device_delete_logic.php` |
| `ctlddevice_soft_delete_logic.php` | `device_soft_delete_logic.php` |
| `ctldfilters_edit_logic.php` | `filters_edit_logic.php` |
| `ctldprofile_delete_logic.php` | `profile_delete_logic.php` |
| `ctld_activation_logic.php` | `activation_logic.php` |
| `ctld_mobileconfig_logic.php` | `mobileconfig_logic.php` |

### Logic Function Names

| Old | New |
|-----|-----|
| `ctlddevice_edit_logic()` | `device_edit_logic()` |
| `ctlddevice_delete_logic()` | `device_delete_logic()` |
| `ctlddevice_soft_delete_logic()` | `device_soft_delete_logic()` |
| `ctldfilters_edit_logic()` | `filters_edit_logic()` |
| `ctldprofile_delete_logic()` | `profile_delete_logic()` |
| `ctld_activation_logic()` | `activation_logic()` |
| `ctld_mobileconfig_logic()` | `mobileconfig_logic()` |

### View Files (`plugins/scrolldaddy/views/profile/`)

| Old | New |
|-----|-----|
| `ctlddevice_edit.php` | `device_edit.php` |
| `ctlddevice_delete.php` | `device_delete.php` |
| `ctlddevice_soft_delete.php` | `device_soft_delete.php` |
| `ctldfilters_edit.php` | `filters_edit.php` |
| `ctldprofile_delete.php` | `profile_delete.php` |
| `ctld_activation.php` | `activation.php` |
| `ctld_mobileconfig.php` | `mobileconfig.php` |

### URL Routes

| Old URL | New URL |
|---------|---------|
| `/profile/ctld_activation` | `/profile/activation` |

Note: `/profile/device_edit` and `/profile/filters_edit` are already clean at the route level in `serve.php`. The view files they point to still need renaming (see above).

---

## Phase 1: Database Renames

Run this directly in psql before deploying the code changes. No file needed — copy/paste into the psql session or run via `! psql -U postgres -d joinerytest` from the CLI:

```sql
BEGIN;

-- Profiles
ALTER TABLE cdp_ctldprofiles RENAME TO sdp_profiles;
ALTER TABLE sdp_profiles RENAME COLUMN cdp_ctldprofile_id TO sdp_profile_id;
ALTER TABLE sdp_profiles RENAME COLUMN cdp_usr_user_id TO sdp_usr_user_id;
ALTER TABLE sdp_profiles RENAME COLUMN cdp_is_active TO sdp_is_active;
ALTER TABLE sdp_profiles RENAME COLUMN cdp_create_time TO sdp_create_time;
ALTER TABLE sdp_profiles RENAME COLUMN cdp_delete_time TO sdp_delete_time;
ALTER TABLE sdp_profiles RENAME COLUMN cdp_schedule_start TO sdp_schedule_start;
ALTER TABLE sdp_profiles RENAME COLUMN cdp_schedule_end TO sdp_schedule_end;
ALTER TABLE sdp_profiles RENAME COLUMN cdp_schedule_days TO sdp_schedule_days;
ALTER TABLE sdp_profiles RENAME COLUMN cdp_schedule_timezone TO sdp_schedule_timezone;
ALTER TABLE sdp_profiles RENAME COLUMN cdp_safesearch TO sdp_safesearch;
ALTER TABLE sdp_profiles RENAME COLUMN cdp_safeyoutube TO sdp_safeyoutube;

-- Devices
ALTER TABLE cdd_ctlddevices RENAME TO sdd_devices;
ALTER TABLE sdd_devices RENAME COLUMN cdd_ctlddevice_id TO sdd_device_id;
ALTER TABLE sdd_devices RENAME COLUMN cdd_device_name TO sdd_device_name;
ALTER TABLE sdd_devices RENAME COLUMN cdd_device_type TO sdd_device_type;
ALTER TABLE sdd_devices RENAME COLUMN cdd_cdp_ctldprofile_id_primary TO sdd_sdp_profile_id_primary;
ALTER TABLE sdd_devices RENAME COLUMN cdd_cdp_ctldprofile_id_secondary TO sdd_sdp_profile_id_secondary;
ALTER TABLE sdd_devices RENAME COLUMN cdd_usr_user_id TO sdd_usr_user_id;
ALTER TABLE sdd_devices RENAME COLUMN cdd_is_active TO sdd_is_active;
ALTER TABLE sdd_devices RENAME COLUMN cdd_create_time TO sdd_create_time;
ALTER TABLE sdd_devices RENAME COLUMN cdd_delete_time TO sdd_delete_time;
ALTER TABLE sdd_devices RENAME COLUMN cdd_deactivation_pin TO sdd_deactivation_pin;
ALTER TABLE sdd_devices RENAME COLUMN cdd_timezone TO sdd_timezone;
ALTER TABLE sdd_devices RENAME COLUMN cdd_allow_device_edits TO sdd_allow_device_edits;
ALTER TABLE sdd_devices RENAME COLUMN cdd_activate_time TO sdd_activate_time;
ALTER TABLE sdd_devices RENAME COLUMN cdd_resolver_uid TO sdd_resolver_uid;

-- Filters
ALTER TABLE cdf_ctldfilters RENAME TO sdf_filters;
ALTER TABLE sdf_filters RENAME COLUMN cdf_ctldfilter_id TO sdf_filter_id;
ALTER TABLE sdf_filters RENAME COLUMN cdf_cdp_ctldprofile_id TO sdf_sdp_profile_id;
ALTER TABLE sdf_filters RENAME COLUMN cdf_filter_pk TO sdf_filter_key;
ALTER TABLE sdf_filters RENAME COLUMN cdf_is_active TO sdf_is_active;

-- Rules
ALTER TABLE cdr_ctldrules RENAME TO sdr_rules;
ALTER TABLE sdr_rules RENAME COLUMN cdr_ctldrule_id TO sdr_rule_id;
ALTER TABLE sdr_rules RENAME COLUMN cdr_cdp_ctldprofile_id TO sdr_sdp_profile_id;
ALTER TABLE sdr_rules RENAME COLUMN cdr_rule_hostname TO sdr_hostname;
ALTER TABLE sdr_rules RENAME COLUMN cdr_is_active TO sdr_is_active;
ALTER TABLE sdr_rules RENAME COLUMN cdr_rule_action TO sdr_action;
ALTER TABLE sdr_rules RENAME COLUMN cdr_rule_via TO sdr_via;

-- Services
ALTER TABLE cds_ctldservices RENAME TO sds_services;
ALTER TABLE sds_services RENAME COLUMN cds_ctldservice_id TO sds_service_id;
ALTER TABLE sds_services RENAME COLUMN cds_cdp_ctldprofile_id TO sds_sdp_profile_id;
ALTER TABLE sds_services RENAME COLUMN cds_service_pk TO sds_service_key;
ALTER TABLE sds_services RENAME COLUMN cds_is_active TO sds_is_active;

-- Device backups
ALTER TABLE cdb_ctlddevice_backups RENAME TO sddb_device_backups;
ALTER TABLE sddb_device_backups RENAME COLUMN cdb_ctlddevice_backup_id TO sddb_device_backup_id;
ALTER TABLE sddb_device_backups RENAME COLUMN cdb_device_backup_name TO sddb_device_backup_name;
ALTER TABLE sddb_device_backups RENAME COLUMN cdb_usr_user_id TO sddb_usr_user_id;
ALTER TABLE sddb_device_backups RENAME COLUMN cdb_create_time TO sddb_create_time;
ALTER TABLE sddb_device_backups RENAME COLUMN cdb_delete_time TO sddb_delete_time;
ALTER TABLE sddb_device_backups RENAME COLUMN cdb_deactivation_pin TO sddb_deactivation_pin;

COMMIT;
```

**Note on legacy columns:** The DB may also contain orphaned ctld columns (`cdd_device_id`, `cdd_profile_id_primary`, `cdd_profile_id_secondary`, `cdp_profile_id`, `cdp_schedule_id`, `cdd_controld_resolver`) that are already unused. Drop them in the same session if desired, before `COMMIT`.

---

## Phase 2: Data Class Files

For each file: rename the file, then update its contents.

### 2a. `data/ctldprofiles_class.php` → `data/profiles_class.php`

**In `$field_specifications`**, rename every column key:

| Old key | New key |
|---------|---------|
| `cdp_ctldprofile_id` | `sdp_profile_id` |
| `cdp_usr_user_id` | `sdp_usr_user_id` |
| `cdp_is_active` | `sdp_is_active` |
| `cdp_create_time` | `sdp_create_time` |
| `cdp_delete_time` | `sdp_delete_time` |
| `cdp_schedule_start` | `sdp_schedule_start` |
| `cdp_schedule_end` | `sdp_schedule_end` |
| `cdp_schedule_days` | `sdp_schedule_days` |
| `cdp_schedule_timezone` | `sdp_schedule_timezone` |
| `cdp_safesearch` | `sdp_safesearch` |
| `cdp_safeyoutube` | `sdp_safeyoutube` |

**Static properties:**
- `$prefix = 'cdp'` → `'sdp'`
- `$tablename = 'cdp_ctldprofiles'` → `'sdp_profiles'`
- `$pkey_column = 'cdp_ctldprofile_id'` → `'sdp_profile_id'`

**Class/exception names:**
- `CtldProfileException` → `SdProfileException`
- `CtldProfile` → `SdProfile`
- `MultiCtldProfile` → `MultiSdProfile`

**require_once paths** — update all 4:
- `plugins/scrolldaddy/data/ctldfilters_class.php` → `plugins/scrolldaddy/data/filters_class.php`
- `plugins/scrolldaddy/data/ctlddevices_class.php` → `plugins/scrolldaddy/data/devices_class.php`
- `plugins/scrolldaddy/data/ctldservices_class.php` → `plugins/scrolldaddy/data/services_class.php`
- `plugins/scrolldaddy/data/ctldrules_class.php` → `plugins/scrolldaddy/data/rules_class.php`

**Column references in method bodies** — replace every `->get()`, `->set()`, and filter key:

| Old | New |
|-----|-----|
| `'cdp_ctldprofile_id'` | `'sdp_profile_id'` |
| `'cdp_usr_user_id'` | `'sdp_usr_user_id'` |
| `'cdp_is_active'` | `'sdp_is_active'` |
| `'cdp_schedule_start'` | `'sdp_schedule_start'` |
| `'cdp_schedule_end'` | `'sdp_schedule_end'` |
| `'cdp_schedule_days'` | `'sdp_schedule_days'` |
| `'cdp_schedule_timezone'` | `'sdp_schedule_timezone'` |
| `'cdp_safesearch'` | `'sdp_safesearch'` |
| `'cdp_safeyoutube'` | `'sdp_safeyoutube'` |

**Cross-table column references** (in `delete_profile_from_device()`):
- `'cdd_cdp_ctldprofile_id_primary'` → `'sdd_sdp_profile_id_primary'`
- `'cdd_cdp_ctldprofile_id_secondary'` → `'sdd_sdp_profile_id_secondary'`

**Class references** inside method bodies:
- `new CtldFilter(` → `new SdFilter(`
- `new MultiCtldFilter(` → `new MultiSdFilter(`
- `new MultiCtldService(` → `new MultiSdService(`
- `new MultiCtldRule(` → `new MultiSdRule(`
- `new CtldRule(` → `new SdRule(`
- `CtldDevice::GetByColumn(` → `SdDevice::GetByColumn(`

**`getMultiResults()` filter key:**
- `'cdp_usr_user_id'` → `'sdp_usr_user_id'`
- `'cdp_is_active'` → `'sdp_is_active'`
- `'cdp_delete_time'` → `'sdp_delete_time'`
- Table name string `'cdp_ctldprofiles'` → `'sdp_profiles'`

---

### 2b. `data/ctlddevices_class.php` → `data/devices_class.php`

**In `$field_specifications`**, rename every column key:

| Old key | New key |
|---------|---------|
| `cdd_ctlddevice_id` | `sdd_device_id` |
| `cdd_device_name` | `sdd_device_name` |
| `cdd_device_type` | `sdd_device_type` |
| `cdd_cdp_ctldprofile_id_primary` | `sdd_sdp_profile_id_primary` |
| `cdd_cdp_ctldprofile_id_secondary` | `sdd_sdp_profile_id_secondary` |
| `cdd_usr_user_id` | `sdd_usr_user_id` |
| `cdd_is_active` | `sdd_is_active` |
| `cdd_create_time` | `sdd_create_time` |
| `cdd_delete_time` | `sdd_delete_time` |
| `cdd_deactivation_pin` | `sdd_deactivation_pin` |
| `cdd_timezone` | `sdd_timezone` |
| `cdd_allow_device_edits` | `sdd_allow_device_edits` |
| `cdd_activate_time` | `sdd_activate_time` |
| `cdd_resolver_uid` | `sdd_resolver_uid` |

**Static properties:**
- `$prefix = 'cdd'` → `'sdd'`
- `$tablename = 'cdd_ctlddevices'` → `'sdd_devices'`
- `$pkey_column = 'cdd_ctlddevice_id'` → `'sdd_device_id'`

**Class/exception names:**
- `CtldDeviceException` → `SdDeviceException`
- `CtldDevice` → `SdDevice`
- `MultiCtldDevice` → `MultiSdDevice`

**require_once paths** — update all 5:
- `plugins/scrolldaddy/data/ctldprofiles_class.php` → `plugins/scrolldaddy/data/profiles_class.php`
- `plugins/scrolldaddy/data/ctldfilters_class.php` → `plugins/scrolldaddy/data/filters_class.php`
- `plugins/scrolldaddy/data/ctldservices_class.php` → `plugins/scrolldaddy/data/services_class.php`
- `plugins/scrolldaddy/data/ctldrules_class.php` → `plugins/scrolldaddy/data/rules_class.php`
- `plugins/scrolldaddy/data/ctlddevice_backups_class.php` → `plugins/scrolldaddy/data/device_backups_class.php`

**Column references in method bodies:**

| Old | New |
|-----|-----|
| `'cdd_ctlddevice_id'` | `'sdd_device_id'` |
| `'cdd_device_name'` | `'sdd_device_name'` |
| `'cdd_device_type'` | `'sdd_device_type'` |
| `'cdd_cdp_ctldprofile_id_primary'` | `'sdd_sdp_profile_id_primary'` |
| `'cdd_cdp_ctldprofile_id_secondary'` | `'sdd_sdp_profile_id_secondary'` |
| `'cdd_usr_user_id'` | `'sdd_usr_user_id'` |
| `'cdd_is_active'` | `'sdd_is_active'` |
| `'cdd_create_time'` | `'sdd_create_time'` |
| `'cdd_delete_time'` | `'sdd_delete_time'` |
| `'cdd_deactivation_pin'` | `'sdd_deactivation_pin'` |
| `'cdd_timezone'` | `'sdd_timezone'` |
| `'cdd_allow_device_edits'` | `'sdd_allow_device_edits'` |
| `'cdd_activate_time'` | `'sdd_activate_time'` |
| `'cdd_resolver_uid'` | `'sdd_resolver_uid'` |

**Cross-table column references** (in `get_time_to_active_profile()`, `get_active_profile()`, etc.):
- `'cdp_schedule_start'` → `'sdp_schedule_start'`
- `'cdp_schedule_end'` → `'sdp_schedule_end'`
- `'cdp_schedule_days'` → `'sdp_schedule_days'`
- `'cdp_schedule_timezone'` → `'sdp_schedule_timezone'`

**Class references in method bodies:**
- `new CtldProfile(` → `new SdProfile(`
- `CtldProfile::createProfile(` → `SdProfile::createProfile(`
- `CtldDevice::createDevice(` → `SdDevice::createDevice(`

**`getMultiResults()` filter keys:**
- `'cdd_usr_user_id'` → `'sdd_usr_user_id'`
- `'cdd_is_active'` → `'sdd_is_active'`
- `'cdd_delete_time'` → `'sdd_delete_time'`
- Table name string `'cdd_ctlddevices'` → `'sdd_devices'`

---

### 2c. `data/ctldfilters_class.php` → `data/filters_class.php`

**In `$field_specifications`**, rename every column key:

| Old key | New key |
|---------|---------|
| `cdf_ctldfilter_id` | `sdf_filter_id` |
| `cdf_cdp_ctldprofile_id` | `sdf_sdp_profile_id` |
| `cdf_filter_pk` | `sdf_filter_key` |
| `cdf_is_active` | `sdf_is_active` |

**Static properties:**
- `$prefix = 'cdf'` → `'sdf'`
- `$tablename = 'cdf_ctldfilters'` → `'sdf_filters'`
- `$pkey_column = 'cdf_ctldfilter_id'` → `'sdf_filter_id'`

**Class names:**
- `CtldFilter` → `SdFilter`
- `MultiCtldFilter` → `MultiSdFilter`

**`getMultiResults()` filter keys:**
- `'cdf_cdp_ctldprofile_id'` → `'sdf_sdp_profile_id'`
- Table name string `'cdf_ctldfilters'` → `'sdf_filters'`

---

### 2d. `data/ctldrules_class.php` → `data/rules_class.php`

**In `$field_specifications`**, rename every column key:

| Old key | New key |
|---------|---------|
| `cdr_ctldrule_id` | `sdr_rule_id` |
| `cdr_cdp_ctldprofile_id` | `sdr_sdp_profile_id` |
| `cdr_rule_hostname` | `sdr_hostname` |
| `cdr_is_active` | `sdr_is_active` |
| `cdr_rule_action` | `sdr_action` |
| `cdr_rule_via` | `sdr_via` |

**Static properties:**
- `$prefix = 'cdr'` → `'sdr'`
- `$tablename = 'cdr_ctldrules'` → `'sdr_rules'`
- `$pkey_column = 'cdr_ctldrule_id'` → `'sdr_rule_id'`

**Class names:**
- `CtldRule` → `SdRule`
- `MultiCtldRule` → `MultiSdRule`

**`getMultiResults()` filter keys:**
- `'cdr_cdp_ctldprofile_id'` → `'sdr_sdp_profile_id'`
- `'cdr_rule_action'` → `'sdr_action'`
- Table name string `'cdr_ctldrules'` → `'sdr_rules'`

---

### 2e. `data/ctldservices_class.php` → `data/services_class.php`

**In `$field_specifications`**, rename every column key:

| Old key | New key |
|---------|---------|
| `cds_ctldservice_id` | `sds_service_id` |
| `cds_cdp_ctldprofile_id` | `sds_sdp_profile_id` |
| `cds_service_pk` | `sds_service_key` |
| `cds_is_active` | `sds_is_active` |

**Static properties:**
- `$prefix = 'cds'` → `'sds'`
- `$tablename = 'cds_ctldservices'` → `'sds_services'`
- `$pkey_column = 'cds_ctldservice_id'` → `'sds_service_id'`

**Class names:**
- `CtldService` → `SdService`
- `MultiCtldService` → `MultiSdService`

**`getMultiResults()` filter keys:**
- `'cds_cdp_ctldprofile_id'` → `'sds_sdp_profile_id'`
- Table name string `'cds_ctldservices'` → `'sds_services'`

---

### 2f. `data/ctlddevice_backups_class.php` → `data/device_backups_class.php`

**In `$field_specifications`**, rename every column key:

| Old key | New key |
|---------|---------|
| `cdb_ctlddevice_backup_id` | `sddb_device_backup_id` |
| `cdb_device_backup_name` | `sddb_device_backup_name` |
| `cdb_usr_user_id` | `sddb_usr_user_id` |
| `cdb_create_time` | `sddb_create_time` |
| `cdb_delete_time` | `sddb_delete_time` |
| `cdb_deactivation_pin` | `sddb_deactivation_pin` |

**Static properties:**
- `$prefix = 'cdb'` → `'sddb'`
- `$tablename = 'cdb_ctlddevice_backups'` → `'sddb_device_backups'`
- `$pkey_column = 'cdb_ctlddevice_backup_id'` → `'sddb_device_backup_id'`

**Class names:**
- `CtldDeviceBackup` → `SdDeviceBackup`
- `MultiCtldDeviceBackup` → `MultiSdDeviceBackup`

**`getMultiResults()` filter keys:**
- `'cdb_usr_user_id'` → `'sddb_usr_user_id'`
- `'cdb_delete_time'` → `'sddb_delete_time'`
- Table name string `'cdb_ctlddevice_backups'` → `'sddb_device_backups'`

---

## Phase 3: Logic Files

For each file: rename the file, update the function name inside it, update all require_once paths, update all class names and column references.

### 3a. All Logic Files — require_once Path Updates

Every logic file that includes a data class needs its path updated:

| Old require_once path | New require_once path |
|----------------------|----------------------|
| `plugins/scrolldaddy/data/ctldprofiles_class.php` | `plugins/scrolldaddy/data/profiles_class.php` |
| `plugins/scrolldaddy/data/ctlddevices_class.php` | `plugins/scrolldaddy/data/devices_class.php` |
| `plugins/scrolldaddy/data/ctldfilters_class.php` | `plugins/scrolldaddy/data/filters_class.php` |
| `plugins/scrolldaddy/data/ctldrules_class.php` | `plugins/scrolldaddy/data/rules_class.php` |
| `plugins/scrolldaddy/data/ctldservices_class.php` | `plugins/scrolldaddy/data/services_class.php` |
| `plugins/scrolldaddy/data/ctlddevice_backups_class.php` | `plugins/scrolldaddy/data/device_backups_class.php` |

Apply to all logic files that have these paths: `ctlddevice_edit_logic.php`, `ctlddevice_delete_logic.php`, `ctlddevice_soft_delete_logic.php`, `ctldfilters_edit_logic.php`, `ctldprofile_delete_logic.php`, `ctld_activation_logic.php`, `ctld_mobileconfig_logic.php`, `devices_logic.php`, `rules_logic.php`.

### 3b. All Logic Files — Class Name Updates

Every class instantiation and static call:

| Old | New |
|-----|-----|
| `new CtldDevice(` | `new SdDevice(` |
| `new MultiCtldDevice(` | `new MultiSdDevice(` |
| `new CtldProfile(` | `new SdProfile(` |
| `new MultiCtldProfile(` | `new MultiSdProfile(` |
| `new CtldFilter(` | `new SdFilter(` |
| `new MultiCtldFilter(` | `new MultiSdFilter(` |
| `new CtldRule(` | `new SdRule(` |
| `new MultiCtldRule(` | `new MultiSdRule(` |
| `new CtldService(` | `new SdService(` |
| `new MultiCtldService(` | `new MultiSdService(` |
| `new CtldDeviceBackup(` | `new SdDeviceBackup(` |
| `new MultiCtldDeviceBackup(` | `new MultiSdDeviceBackup(` |
| `CtldProfile::createProfile(` | `SdProfile::createProfile(` |
| `CtldDevice::createDevice(` | `SdDevice::createDevice(` |

### 3c. All Logic Files — Column Name Updates

Every `->get()`, `->set()`, and POST variable key:

| Old | New |
|-----|-----|
| `'cdd_timezone'` | `'sdd_timezone'` |
| `'cdd_device_name'` | `'sdd_device_name'` |
| `'cdd_device_type'` | `'sdd_device_type'` |
| `'cdd_allow_device_edits'` | `'sdd_allow_device_edits'` |
| `'cdd_is_active'` | `'sdd_is_active'` |
| `'cdd_delete_time'` | `'sdd_delete_time'` |
| `'cdd_cdp_ctldprofile_id_primary'` | `'sdd_sdp_profile_id_primary'` |
| `'cdd_cdp_ctldprofile_id_secondary'` | `'sdd_sdp_profile_id_secondary'` |
| `'cdd_resolver_uid'` | `'sdd_resolver_uid'` |
| `'cdd_usr_user_id'` | `'sdd_usr_user_id'` |
| `'cdp_usr_user_id'` | `'sdp_usr_user_id'` |
| `'cdp_schedule_start'` | `'sdp_schedule_start'` |
| `'cdf_filter_pk'` | `'sdf_filter_key'` |
| `'cdf_is_active'` | `'sdf_is_active'` |
| `'cds_service_pk'` | `'sds_service_key'` |
| `'cds_is_active'` | `'sds_is_active'` |

**POST/GET variable keys** (used in `rules_logic.php`):
- `$post_vars['cdr_rule_hostname']` → `$post_vars['sdr_hostname']`
- `$post_vars['cdr_rule_action']` → `$post_vars['sdr_action']`

### 3d. Per-File Function Renames

Each logic file defines a function with the same name as the file. Rename the function definition AND all call sites in view files:

| File | Old function | New function |
|------|-------------|-------------|
| `ctlddevice_edit_logic.php` | `ctlddevice_edit_logic()` | `device_edit_logic()` |
| `ctlddevice_delete_logic.php` | `ctlddevice_delete_logic()` | `device_delete_logic()` |
| `ctlddevice_soft_delete_logic.php` | `ctlddevice_soft_delete_logic()` | `device_soft_delete_logic()` |
| `ctldfilters_edit_logic.php` | `ctldfilters_edit_logic()` | `filters_edit_logic()` |
| `ctldprofile_delete_logic.php` | `ctldprofile_delete_logic()` | `profile_delete_logic()` |
| `ctld_activation_logic.php` | `ctld_activation_logic()` | `activation_logic()` |
| `ctld_mobileconfig_logic.php` | `ctld_mobileconfig_logic()` | `mobileconfig_logic()` |

---

## Phase 4: View Files

### 4a. Rename Files

Rename in `plugins/scrolldaddy/views/profile/`:

- `ctlddevice_edit.php` → `device_edit.php`
- `ctlddevice_delete.php` → `device_delete.php`
- `ctlddevice_soft_delete.php` → `device_soft_delete.php`
- `ctldfilters_edit.php` → `filters_edit.php`
- `ctldprofile_delete.php` → `profile_delete.php`
- `ctld_activation.php` → `activation.php`
- `ctld_mobileconfig.php` → `mobileconfig.php`

### 4b. Update Logic Includes in Each View

Each view file loads its logic file via `getThemeFilePath`. Update both the filename and the function call:

| View file | Old logic include | New logic include |
|-----------|-------------------|-------------------|
| `device_edit.php` | `ctlddevice_edit_logic.php` | `device_edit_logic.php` |
| `device_delete.php` | `ctlddevice_delete_logic.php` | `device_delete_logic.php` |
| `device_soft_delete.php` | `ctlddevice_soft_delete_logic.php` | `device_soft_delete_logic.php` |
| `filters_edit.php` | `ctldfilters_edit_logic.php` | `filters_edit_logic.php` |
| `profile_delete.php` | `ctldprofile_delete_logic.php` | `profile_delete_logic.php` |
| `activation.php` | `ctld_activation_logic.php` | `activation_logic.php` |
| `mobileconfig.php` | `ctld_mobileconfig_logic.php` | `mobileconfig_logic.php` |

Also update the logic function call in each view:

| View file | Old call | New call |
|-----------|----------|----------|
| `device_edit.php` | `ctlddevice_edit_logic(...)` | `device_edit_logic(...)` |
| `device_delete.php` | `ctlddevice_delete_logic(...)` | `device_delete_logic(...)` |
| `device_soft_delete.php` | `ctlddevice_soft_delete_logic(...)` | `device_soft_delete_logic(...)` |
| `filters_edit.php` | `ctldfilters_edit_logic(...)` | `filters_edit_logic(...)` |
| `profile_delete.php` | `ctldprofile_delete_logic(...)` | `profile_delete_logic(...)` |
| `activation.php` | `ctld_activation_logic(...)` | `activation_logic(...)` |
| `mobileconfig.php` | `ctld_mobileconfig_logic(...)` | `mobileconfig_logic(...)` |

### 4c. Update Column References in View Bodies

Views directly reference column names in places like `->get('cdd_device_name')`, form field names, and HTML output. Apply the same column rename table from Phase 3c to all view files. Key occurrences to check:

- `rules.php`: `->get('cdr_rule_hostname')`, `->get('cdr_rule_action')`, form fields `cdr_rule_hostname`, `cdr_rule_action`, `rule_id` (this is the PK reference, becomes `sdr_rule_id` as the column but the form field name `rule_id` may stay as-is if it's just a POST parameter name)
- `devices.php`: `->get('cdd_resolver_uid')`, `->get('cdd_cdp_ctldprofile_id_primary')`, `->get('cdd_cdp_ctldprofile_id_secondary')`, `->get('cdp_schedule_start')`
- `ctldfilters_edit.php` / `filters_edit.php`: `->get('cdf_filter_pk')`, `->get('cdf_is_active')`, `->get('cds_service_pk')`, `->get('cds_is_active')`
- `ctlddevice_edit.php` / `device_edit.php`: `->get('cdd_timezone')`, `->get('cdd_allow_device_edits')`, `->get('cdd_device_name')`, `->get('cdd_device_type')`

### 4d. Update Form Action URLs in View Bodies

Search all view files for hardcoded URLs referencing old view names:

| Old URL (in form action or href) | New URL |
|----------------------------------|---------|
| `/profile/ctlddevice_edit` | `/profile/device_edit` |
| `/profile/ctlddevice_delete` | `/profile/device_delete` |
| `/profile/ctlddevice_soft_delete` | `/profile/device_soft_delete` |
| `/profile/ctldfilters_edit` | `/profile/filters_edit` |
| `/profile/ctldprofile_delete` | `/profile/profile_delete` |
| `/profile/ctld_activation` | `/profile/activation` |
| `/profile/ctld_mobileconfig` | `/profile/mobileconfig` |

Check these files for URL references: `devices.php`, `device_edit.php`, `device_delete.php`, `device_soft_delete.php`, `filters_edit.php`, `profile_delete.php`, `activation.php`, `rules.php`, `profile.php`.

---

## Phase 5: Other Files

### 5a. `serve.php` (plugin routes)

Update route views to point to renamed view files:

```php
// Old:
'/profile/device_edit' => ['view' => 'views/profile/ctlddevice_edit', ...]
'/profile/filters_edit' => ['view' => 'views/profile/ctldfilters_edit', ...]
'/profile/ctld_activation' => ['view' => 'views/profile/ctld_activation', ...]

// New:
'/profile/device_edit' => ['view' => 'views/profile/device_edit', ...]
'/profile/filters_edit' => ['view' => 'views/profile/filters_edit', ...]
'/profile/activation' => ['view' => 'views/profile/activation', ...]
```

The routes for `devices`, `rules`, and `pricing` don't have ctld in their view path — no change needed.

Also add missing routes for delete/soft-delete/profile-delete views if they are not currently auto-discovered (check whether they work via the profile/* wildcard or need explicit entries).

### 5b. `ajax/test_domain.php`

- Update require_once: `plugins/scrolldaddy/data/ctlddevices_class.php` → `plugins/scrolldaddy/data/devices_class.php`
- Update class reference: `new CtldDevice(` → `new SdDevice(`
- Update column references: `->get('cdd_resolver_uid')` → `->get('sdd_resolver_uid')`, `->get('cdd_usr_user_id')` → `->get('sdd_usr_user_id')`

### 5c. `data/ctldprofiles_class.php` — `add_rule()` / `delete_rule()`

These call `$rule->set('cdr_ctldrule_id', ...)` and similar. Apply column renames from Phase 3c. Also the `permanent_delete` methods call `$rule->permanent_delete()` — no column name change needed there, but the class instantiation (`new CtldRule`) must be updated.

### 5d. Check `tasks/DownloadBlocklists.php`

This file does not reference ctld classes directly, but verify it references no column names that need renaming. Expected: no changes needed.

---

## Phase 6: Verification Checklist

After implementation, run these checks before marking complete:

- [ ] `php -l` on every modified PHP file — zero syntax errors
- [ ] Run `validate_php_file.php` on every modified PHP file — no unresolved method calls
- [ ] Run the Phase 1 SQL block in psql and verify it commits cleanly
- [ ] Run `psql -U postgres -d joinerytest -c "\d sdp_profiles"` — verify all column renames applied
- [ ] Browser test: `/profile/devices` loads correctly
- [ ] Browser test: `/profile/device_edit?device_id=X` loads and submits correctly
- [ ] Browser test: `/profile/filters_edit` loads and saves filters correctly
- [ ] Browser test: `/profile/rules` loads, adds, and deletes a rule correctly
- [ ] Browser test: `/profile/activation` loads the activation page correctly
- [ ] Browser test: Domain test AJAX still works (`/ajax/test_domain`)
- [ ] `grep -r 'ctld' plugins/scrolldaddy --include='*.php'` — zero results (only allowed in comments)
- [ ] `grep -r 'cdp_\|cdd_\|cdf_\|cdr_\|cds_\|cdb_' plugins/scrolldaddy --include='*.php'` — zero results
- [ ] Run `DownloadBlocklists` scheduled task — verify it still completes successfully

---

## Implementation Notes

- **DB renames must run first.** Run the Phase 1 SQL block in psql, then deploy the code. If code goes live before the DB renames run, the site breaks.
- **The `bld_blocklist_domains` table is unaffected.** It uses `bld_` prefix and category keys like `'ads'`, `'malware'` — no changes needed.
- **The `stg_settings` table is unaffected.** Settings like `scrolldaddy_dns_internal_url` and `scrolldaddy_dns_api_key` keep their names.
- **No admin files exist** in `plugins/scrolldaddy/admin/` — nothing to update there.
- **The `DownloadBlocklists` task** references `bld_` tables only — not affected.
- **Legacy DB columns** (`cdd_device_id`, `cdd_profile_id_primary/secondary`, `cdp_profile_id`, `cdp_schedule_id`, `cdd_controld_resolver`) still exist in the DB from the original controld era. These are orphaned — they can be dropped in the same migration if desired, or left in place.
