# Settings Management Improvements Specification

## Problem Statement

### Current Pain Points
1. **Adding settings requires multiple steps:**
   - Create a migration to insert into stg_settings
   - Modify admin_settings.php to add UI fields
   - For plugins: create separate admin settings pages

2. **Plugin settings management is fragmented:**
   - Each plugin needs its own admin page
   - Code duplication across plugins
   - Inconsistent UI/UX
   - Extra development effort for simple settings

3. **Deployment complexity:**
   - New sites need all migrations run in order
   - Missing settings can break functionality
   - Plugin settings scattered across different admin pages

## Proposed Solution

Implement two complementary improvements:
1. **Auto-create settings** when they're referenced but don't exist
2. **Include plugin settings** directly in the main settings page via simple PHP includes

## Part 1: Auto-Creating Settings

### Implementation Approach

Modify admin_settings.php to automatically create settings that are submitted via POST but don't exist in the database. Since plugin settings will be integrated into the main settings page, this single change handles both core and plugin settings.

```php
// Simplified POST handler
foreach($_POST as $setting_name => $setting_value) {
    // Skip non-setting fields (submit buttons, CSRF tokens, etc)
    if(is_non_setting_field($setting_name)) continue;

    // Try to update existing or create new
    $existing = new MultiSetting(['setting_name' => $setting_name]);
    if($existing->count_all() > 0) {
        // Update existing setting
        $existing->load();
        $setting = $existing->get(0);
        $setting->set('stg_value', $setting_value);
    } else {
        // Create new setting
        $setting = new Setting(NULL);
        $setting->set('stg_name', $setting_name);
        $setting->set('stg_value', $setting_value);
        $setting->set('stg_usr_user_id', $session->get_user_id());
    }
    $setting->save();
}
```

### Deployment Safety Strategy

**Simple Empty Defaults:** Eliminate migrations by returning empty string for all missing settings

#### Modified get_setting() Method

Update `Globalvars::get_setting()` to return empty string for missing settings without creating them:

```php
public function get_setting($setting, $calculated_values=true, $fail_silently=false) {
    // ... existing code to check config file and database ...

    if(!$found) {
        // Log this for monitoring/debugging
        error_log("Settings: Returning empty default for missing setting '{$setting}'");

        // Return empty string (no caching)
        return '';
    }

    // ... existing return logic ...
}
```

#### Settings Creation on Save

Settings only get created when the admin saves the form. Add this after the existing foreach loop in admin_settings.php:

```php
// After existing code that updates known settings (lines 44-62)

// Track which settings we've processed
$processed_settings = array();
foreach($user_settings as $user_setting) {
    $processed_settings[] = $user_setting->get('stg_name');
}

// Auto-create any missing settings that were submitted
foreach($_POST as $setting_name => $setting_value) {
    // Skip if already processed (already exists in database)
    if(in_array($setting_name, $processed_settings)) continue;

    // Create new setting - only happens on explicit save
    error_log("Settings: Creating new setting '{$setting_name}' with value '{$setting_value}'");

    $new_setting = new Setting(NULL);
    $new_setting->set('stg_name', $setting_name);
    $new_setting->set('stg_value', $setting_value);
    $new_setting->set('stg_usr_user_id', $session->get_user_id());
    $new_setting->set('stg_group_name', 'general');

    try {
        $new_setting->prepare();
        $new_setting->save();
    } catch(Exception $e) {
        // Setting might already exist (race condition) or validation error
        error_log("Settings: Failed to create '{$setting_name}': " . $e->getMessage());
    }
}
```

#### Pro/Con Analysis

**Pros:**
- **No migrations needed** - Settings work without database entries
- **Dead simple** - No magic, no pattern matching, just empty string
- **No duplication** - No need to maintain defaults anywhere
- **Explicit creation** - Settings only created when admin consciously saves
- **Transparent operation** - Error logs show when missing settings are accessed
- **Predictable behavior** - Always returns empty string, no surprises
- **Minimal database writes** - Only write when admin actually saves
- **Zero maintenance** - No default logic to maintain or update

**Cons:**
- **Everything starts empty** - Boolean fields show as unchecked, dropdowns unselected
- **Log volume** - Could generate many log entries on fresh install
- **May need code updates** - Code that expects specific defaults needs to handle empty strings

**Mitigation strategies:**
- Log volume: Use a dedicated log file or log level for settings defaults
- Code handling: Check for empty string and treat as appropriate default in code
- Admin experience: Forms show empty state clearly, admin sets values on first save

This approach eliminates migrations while keeping everything simple and predictable.

## Part 2: Plugin Settings Integration

### Simple Include-Based Approach

Instead of complex declarations, plugins simply provide PHP code snippets that get included directly in the main settings page.

### Plugin Settings Files

Plugins provide settings UI snippets at `/plugins/{plugin_name}/settings_form.php` that use the existing FormWriter:

```php
// Location: /plugins/{plugin_name}/settings_form.php
<?php
// This file is included within admin_settings.php context
// $formwriter, $settings, and $session are already available

// IMPORTANT: All settings MUST be prefixed with your plugin name
// to avoid conflicts with other plugins and core settings.
// Pattern: {plugin_name}_{setting_name}

// Example for ControlD plugin (/plugins/controld/settings_form.php):
echo '<h3>ControlD Settings</h3>';
echo $formwriter->textinput("ControlD API Key", 'controld_key', '', 20,
    $settings->get_setting('controld_key'), "Your ControlD API key", 255, "");
?>
```

### Namespace Convention

**All plugin settings MUST be prefixed with the plugin name followed by an underscore.**

- Pattern: `{plugin_name}_{setting_name}`
- Examples: `controld_key`, `bookings_default_duration`, `events_timezone`
- This prevents conflicts between plugins and with core settings
- This is enforced by convention and documentation, not code

### Modified admin_settings.php

The main settings page replaces the plugin settings link generation (lines 89-99) with direct inclusion:

```php
// REMOVE lines 89-99 (the code that creates altlinks for plugin settings pages):
// //GET ALL OF THE PLUGIN SETTINGS PAGES
// $plugins = LibraryFunctions::list_plugins();
// foreach($plugins as $plugin){
//     ...generates links to separate settings pages...
// }

// ADD after core settings sections (around line 920):
echo '<hr><h2>Plugin Settings</h2>';

// Scan and include plugin settings forms directly in this page
$plugins = LibraryFunctions::list_plugins();
foreach($plugins as $plugin) {
    $settings_form = PathHelper::getIncludePath("plugins/$plugin/settings_form.php");
    if(file_exists($settings_form)) {
        echo "<div class='plugin-settings-section'>";
        echo "<h4>" . ucfirst($plugin) . " Plugin</h4>";
        include($settings_form);
        echo "</div>";
    }
}
```

### Benefits of This Approach

1. **No new APIs to learn** - Plugins use the same FormWriter they already know
2. **Full flexibility** - Plugins can add JavaScript, conditional logic, complex layouts
3. **Consistent UI** - Using the same FormWriter ensures consistency
4. **Simple implementation** - Just include() the files
5. **Access to everything** - Plugins have access to $formwriter, $settings, $session, etc.

## Implementation Phases

### Phase 1: Simple Defaults Infrastructure
1. Modify Globalvars::get_setting() to return empty string for missing settings with logging
2. Update admin_settings.php to auto-create settings from POST
3. Test with new settings (no migrations needed)
4. Monitor logs to see which settings are being accessed before creation
5. Document new pattern for developers

### Phase 2: Plugin Settings Integration
1. Modify admin_settings.php to include plugin settings_form.php files
2. Convert controld plugin to use new pattern:
   - Create `/plugins/controld/settings_form.php`:
     ```php
     <?php
     // ControlD plugin settings
     // Included in main admin_settings.php - $formwriter and $settings available

     echo '<p>Configure your ControlD integration settings below.</p>';
     echo $formwriter->textinput("ControlD API Key", 'controld_key', '', 20,
         $settings->get_setting('controld_key'),
         "Get your API key from ControlD dashboard", 255, "");
     ?>
     ```
   - Remove `/plugins/controld/admin/admin_settings_controld.php`
   - Remove the automatic plugin settings link generation code from admin_settings.php (lines 89-99) that creates "controld settings" links in altlinks
   - Update controld uninstall.php to use `DELETE ... WHERE stg_name LIKE 'controld_%'`
3. Test auto-creation with plugin settings
4. Verify controld_key setting appears in main settings page

### Phase 3: Migration of Existing Settings
1. Add defaults to all existing settings in admin_settings.php
2. Convert remaining plugins to use new system
3. Mark old migrations as deprecated (but keep for historical installs)
4. Test full deployment on fresh database

### Phase 4: Cleanup and Documentation
1. Remove redundant plugin settings pages
2. Remove admin_setting_edit.php (no longer needed with auto-creation)
3. Remove "New Setting" link from admin_settings.php (line 79)
4. Create `/docs/settings.md` with:
   - How the new settings system works
   - How to add new settings (just add to form)
   - Plugin settings guide (create settings_form.php)
   - Naming conventions (plugin_name_setting)
   - Troubleshooting (check error logs for missing settings)
5. Update existing documentation
6. Consider removing old migrations after grace period
7. Add admin tool to view all settings and their defaults

## Benefits

### For Developers
- No migrations needed for simple settings
- Plugins just declare settings structure
- Automatic form generation
- Less boilerplate code

### For Users
- Consistent settings interface
- All plugin settings in one place
- Better discoverability
- Cleaner admin menu

### For Maintenance
- Centralized settings logic
- Easier to update UI/UX globally
- Less code to maintain
- Audit trail maintained (timestamps, user_id)

## Security Considerations

1. **Permission Control:** Only admin pages (permission level 8+) can auto-create settings
2. **Field Whitelisting:** Settings are only created from explicitly defined form fields
3. **Validation:** Settings still validated before saving
4. **Namespacing:** Plugin settings must follow `{plugin_name}_{setting}` convention to prevent collisions

## Backwards Compatibility

1. **Existing migrations continue to work** for initial deployments
2. **Existing plugin settings pages remain functional** during transition
3. **Gradual migration path** - plugins can opt-in to new system
4. **Escape hatch** for complex settings that need custom UI

## Risk Mitigation

| Risk | Mitigation |
|------|------------|
| Typos creating unwanted settings | Settings only created from form fields developers explicitly add |
| Missing settings on new deployment | Settings auto-create with defaults on first access via get_setting() |
| Settings proliferation | Regular cleanup script to identify unused settings |
| Plugin setting conflicts | Enforced convention: all plugin settings must use `{plugin_name}_` prefix |
| Race condition on auto-create | Use try/catch, ignore duplicate key errors |
| Performance hit on first access | One-time cost, settings cached after creation |

## Implementation Checklist

- [x] Modify Globalvars::get_setting() to return empty string for missing settings with logging
- [x] Modify admin_settings.php to auto-create settings from POST
- [x] Modify admin_settings.php to include plugin settings_form.php files from `/plugins/*/`
- [x] Remove "New Setting" link from admin_settings.php (line 79)
- [x] Create `/plugins/controld/settings_form.php` with controld_key field
- [x] Remove `/plugins/controld/admin/admin_settings_controld.php`
- [x] Update controld uninstall.php to clean up all controld_* settings
- [x] Remove `/adm/admin_setting_edit.php` (replaced by auto-creation)
- [x] Create `/docs/settings.md` documentation file explaining the new settings system
- [ ] Test auto-creation with both core and plugin settings
- [ ] Test fresh deployment (settings return empty, create on save)
- [ ] Monitor error logs to track missing settings access
- [ ] Create migration guide for existing plugins

## Questions Resolved

1. **How to handle deployments?** → Empty string returned for missing settings, created only on save
2. **How to prevent settings explosion?** → Settings only created from explicit form fields
3. **Plugin settings format?** → PHP array in settings.php for flexibility
4. **UI approach?** → Single page with sections (simpler than tabs)

## Plugin Uninstall Handling

Plugins already have an uninstall system (`/plugins/{name}/uninstall.php`). When using the new settings pattern, plugins should update their uninstall scripts to remove all their settings:

```php
// In plugin's uninstall.php
function {plugin}_uninstall() {
    // ... existing cleanup ...

    // Remove all plugin settings using the naming convention
    $sql = "DELETE FROM stg_settings WHERE stg_name LIKE '{plugin}_%'";
    $q = $dblink->prepare($sql);
    $q->execute();

    // ... rest of cleanup ...
}
```

This leverages the existing uninstall infrastructure and the naming convention to ensure clean removal.

## Open Questions

1. Should certain core settings be protected from auto-creation?
2. Should we auto-detect setting type from form input type?
3. Should complex plugins be able to extend the settings UI with custom components?
4. Should uninstall.php be required to clean up settings, or should it be automatic?