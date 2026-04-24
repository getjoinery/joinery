# Settings System Documentation

## Overview

The Joinery CMS settings system provides a flexible, auto-creating configuration management system that eliminates the need for migrations when adding new settings. Settings are stored in the `stg_settings` database table and can be managed through the admin interface.

For plugin-owned settings that need to exist on fresh install without admin intervention, declare them in `plugin.json` under the `settings` key — see the [Plugin Developer Guide](plugin_developer_guide.md#plugin-settings-declarative). For core settings with factory defaults, declare them in `settings.json` at the `public_html/` root. For settings that only need to exist once an admin fills them in (the historical pattern), the auto-create-on-save mechanism described below still applies.

## How the Settings System Works

### Core Components

1. **Globalvars::get_setting()** - Retrieves settings from config file or database
2. **admin_settings.php** - Main settings management interface
3. **Setting class** - Single setting CRUD operations
4. **MultiSetting class** - Multiple settings queries

### Auto-Creation Feature

Settings are automatically created when you:
1. Add a form field to admin_settings.php (or plugin settings_form.php)
2. An admin saves the form
3. The system detects the setting doesn't exist in the database
4. The setting is created with the submitted value

**Important:** Settings are NOT created when accessed via `get_setting()`. They return an empty string if missing, with a log entry for debugging.

### Missing Settings Behavior

When `Globalvars::get_setting('setting_name')` is called for a non-existent setting:

- Returns empty string (`''`)
- Logs the access: `"Settings: Returning empty default for missing setting 'setting_name'"`
- Does NOT cache the empty value
- Does NOT throw an exception

This allows your code to work on fresh deployments without requiring migrations.

## System-Managed Settings

Some settings are managed by the system and should not be edited by hand via migrations or direct SQL — use the admin UI instead.

| Setting name | What it controls | Where to change |
|---|---|---|
| `theme_template` | Active visual theme (e.g., `phillyzouk-html5`) | Admin > Settings |
| `active_theme_plugin` | Plugin that provides the theme (if any) | Admin > Settings |

**Do not confuse `theme_template` with `site_template` in `config/Globalvars_site.php`.** The latter is the site installation directory identifier and is almost never changed after setup.

## Adding New Settings

### For Core Settings

Two paths, choose based on whether the setting needs a factory default:

**Path A — Setting with a factory default** (recommended when the setting has a sensible value from day one):

1. Add an entry to `settings.json` at the `public_html/` root:

```json
{ "name": "my_new_setting", "default": "1" }
```

2. Add the form field to `/adm/admin_settings.php` so admins can edit it.
3. The setting is seeded on every `update_database` run — it exists immediately on fresh installs.

**Path B — Setting created on first admin save** (the historical pattern, still works):

1. Open `/adm/admin_settings.php`
2. Add your form field using FormWriter where appropriate:

```php
echo $formwriter->textinput("My New Setting", 'my_new_setting', '', 20,
    $settings->get_setting('my_new_setting'),
    "Help text for the setting", 255, "");
```

3. Save the file
4. Navigate to Settings page in admin
5. Fill in the value and click Submit
6. The setting is automatically created in the database

Use Path A when the setting needs a sensible default from day one (feature gates, rate limits). Use Path B when the setting has no meaningful default and only exists once an admin configures it.

### For Plugin Settings

Plugins can integrate their settings directly into the main settings page using a simple include-based approach.

#### Step 0: Declare defaults in plugin.json

For any setting that should exist on fresh install with a default value, add it to the `settings` array in your plugin manifest. See the [Plugin Developer Guide](plugin_developer_guide.md#plugin-settings-declarative) for the full shape. This replaces writing `INSERT INTO stg_settings` statements in migrations.

```json
{
  "settings": [
    { "name": "myplugin_api_key", "default": "" },
    { "name": "myplugin_feature_enabled", "default": "1" }
  ]
}
```

#### Step 1: Create settings_form.php

Create `/plugins/{your_plugin}/settings_form.php`:

```php
<?php
// This file is included within admin_settings.php context
// $formwriter, $settings, and $session are already available

// IMPORTANT: All settings MUST be prefixed with your plugin name
// to avoid conflicts with other plugins and core settings.
// Pattern: {plugin_name}_{setting_name}

echo '<p>Configure your plugin settings below.</p>';

$formwriter->textinput('myplugin_api_key', 'API Key', [
    'value' => $settings->get_setting('myplugin_api_key'),
    'helptext' => 'Your API key'
]);

$formwriter->dropinput('myplugin_feature_enabled', 'Enable Feature', [
    'options' => [1 => 'Yes', 0 => 'No'],
    'value' => $settings->get_setting('myplugin_feature_enabled')
]);
?>
```

#### Step 2: Follow the Naming Convention

**All plugin settings MUST be prefixed with the plugin name:**

- Pattern: `{plugin_name}_{setting_name}`
- Examples:
  - `scrolldaddy_dns_host`
  - `bookings_default_duration`
  - `events_timezone`
  - `myplugin_api_key`

This prevents conflicts between plugins and with core settings.

#### Step 3: That's It!

Your plugin settings will automatically appear in the main Settings page under "Plugin Settings" section. When an admin saves the form, any new settings are automatically created.

### Available Form Field Types

The FormWriter provides various input types:

```php
// Text input
$formwriter->textinput('setting_name', 'Label', [
    'value' => $settings->get_setting('setting_name'),
    'helptext' => 'Help text'
]);

// Dropdown
$formwriter->dropinput('setting_name', 'Label', [
    'options' => [1 => 'Option 1', 2 => 'Option 2'],
    'value' => $settings->get_setting('setting_name'),
    'helptext' => 'Help text'
]);

// Textarea
$formwriter->textbox('setting_name', 'Label', [
    'value' => $settings->get_setting('setting_name'),
    'rows' => 10,
    'cols' => 80
]);

// Boolean toggle
$formwriter->dropinput('setting_name', 'Enable Feature', [
    'options' => [1 => 'Yes', 0 => 'No'],
    'value' => $settings->get_setting('setting_name')
]);
```

## Using Settings in Code

### Basic Usage

```php
$settings = Globalvars::get_instance();
$value = $settings->get_setting('setting_name');

// Handle empty default
if(empty($value)) {
    $value = 'default_value';
}
```

### Boolean Settings

```php
if ($settings->get_setting('feature_enabled')) {
    // Feature is enabled
}
```

### Numeric Settings

```php
$max_items = $settings->get_setting('max_items');
if(empty($max_items)) {
    $max_items = 10; // Default
}
$max_items = intval($max_items);
```

## Plugin Uninstall

Settings declared in a plugin's `plugin.json` are **automatically removed** on uninstall — PluginManager reads the current manifest and deletes each declared name. You do not need to repeat this work in `uninstall.php`.

One caveat: settings that were declared in an earlier version of the plugin but dropped from the current manifest are left in place as orphan rows. The orphan has no runtime cost (nothing reads it), but if you need it gone, delete it with an SQL migration before removing the declaration, or clean it up by hand.

An uninstall hook is still useful for cleaning up things the declarative systems don't cover — tables not created from a data class, external resources, scheduled tasks created outside the normal pattern, etc.

## Troubleshooting

### Setting Not Appearing

1. Check error logs: `tail /var/www/html/joinerytest/logs/error.log`
2. Look for: `"Settings: Returning empty default for missing setting 'setting_name'"`
3. Verify form field name matches exactly
4. Ensure you clicked Submit to save the form

### Setting Not Saving

1. Check error logs for: `"Settings: Failed to create 'setting_name'"`
2. Verify the setting name follows naming conventions
3. Check database permissions
4. Verify Setting class validation rules

### Plugin Settings Not Showing

1. Verify file exists: `/plugins/{plugin}/settings_form.php`
2. Check file permissions (must be readable)
3. Verify plugin is in the plugins directory
4. Check for PHP syntax errors: `php -l /plugins/{plugin}/settings_form.php`

### Empty Values After Fresh Install

This is expected behavior! Settings return empty strings until an admin:
1. Navigates to Settings page
2. Fills in the values
3. Clicks Submit

Your code should handle empty values gracefully with appropriate defaults.

## Best Practices

### 1. Always Use Prefixes for Plugin Settings

```php
// ✅ Good
'myplugin_api_key'
'myplugin_enabled'

// ❌ Bad - will conflict!
'api_key'
'enabled'
```

### 2. Handle Empty Defaults in Code

```php
// ✅ Good
$timeout = $settings->get_setting('api_timeout');
if(empty($timeout)) {
    $timeout = 30; // Default timeout
}

// ❌ Bad - assumes value exists
$timeout = $settings->get_setting('api_timeout');
$result = api_call($timeout); // Might fail with empty string
```

### 3. Provide Help Text

```php
// ✅ Good - clear help text
echo $formwriter->textinput("API Timeout (seconds)", 'api_timeout', '', 20,
    $settings->get_setting('api_timeout'),
    "Timeout in seconds for API calls (default: 30)", 255, "");

// ❌ Bad - no context
echo $formwriter->textinput("Timeout", 'timeout', '', 20,
    $settings->get_setting('timeout'), "", 255, "");
```

### 4. Use Meaningful Setting Names

```php
// ✅ Good
'email_notification_enabled'
'max_upload_size_mb'
'default_user_timezone'

// ❌ Bad
'email_on'
'max_size'
'tz'
```

### 5. Group Related Settings

In your settings_form.php, use headings to organize:

```php
echo '<h4>API Configuration</h4>';
echo $formwriter->textinput("API Key", 'myplugin_api_key', ...);
echo $formwriter->textinput("API Secret", 'myplugin_api_secret', ...);

echo '<h4>Email Settings</h4>';
echo $formwriter->textinput("From Email", 'myplugin_from_email', ...);
```

## Migration Guide: Converting SQL Migrations to Declarative Settings

Older plugins seeded defaults with `INSERT INTO stg_settings` inside `migrations/migrations.php`. The declarative path (plugin.json) is now preferred. Here's how to convert.

### Before

`plugins/bookings/migrations/migrations.php`:

```php
'up' => function($dbconnector) {
    $dblink = $dbconnector->get_db_link();
    $sql = "INSERT INTO stg_settings (stg_name, stg_value, ...)
            VALUES ('bookings_enabled', '1', ...)";
    $dblink->prepare($sql)->execute();
}
```

### After

`plugins/bookings/plugin.json`:

```json
{
  "name": "Bookings Management",
  "version": "1.0.0",
  "settings": [
    { "name": "bookings_enabled", "default": "1" }
  ]
}
```

And remove the `INSERT INTO stg_settings` from the migration. If the migration is now empty, replace its body with `return true;` or delete the migration file entirely.

### What happens to existing installs

Nothing changes on existing sites. The old migration's tracking row in `plm_plugin_migrations` stays put, which prevents the (now-removed) INSERT from ever running again. The seed-only declarative path doesn't overwrite the existing value.

### What happens on fresh installs

The plugin.json `settings` array is seeded on activate. Existing sites pick it up on the next `sync()`.

### Benefits

- No SQL boilerplate for simple default-value seeding.
- Declared settings are automatically removed on plugin uninstall.
- One source of truth for "what settings does this plugin own" — the manifest.
- Consistent with how admin menus already work.

## Advanced Topics

### Conditional Field Display

You can use JavaScript to show/hide fields based on other settings:

```php
echo $formwriter->dropinput("Enable Feature", 'myplugin_feature_enabled', '',
    array("Yes"=>1, 'No'=>0),
    $settings->get_setting('myplugin_feature_enabled'), '', FALSE);

echo '<div id="myplugin_feature_options" style="display:none;">';
echo $formwriter->textinput("Feature Option", 'myplugin_feature_option', '', 20,
    $settings->get_setting('myplugin_feature_option'), '', 255, "");
echo '</div>';

echo '<script>
$(document).ready(function() {
    function toggleFeatureOptions() {
        if($("#myplugin_feature_enabled").val() == "1") {
            $("#myplugin_feature_options").show();
        } else {
            $("#myplugin_feature_options").hide();
        }
    }
    toggleFeatureOptions();
    $("#myplugin_feature_enabled").change(toggleFeatureOptions);
});
</script>';
```

### Settings Validation

The Setting class includes validation through the `prepare()` method. Settings validation happens:

1. When admin saves the form
2. Before the setting is written to database
3. Validation errors are caught and logged

### Complex Settings

For complex configuration that doesn't fit the simple key-value model:

- Store JSON in the setting value
- Parse in your code

```php
// Saving complex data
$complex_config = array(
    'servers' => ['server1', 'server2'],
    'options' => ['opt1' => true, 'opt2' => false]
);
$setting->set('stg_value', json_encode($complex_config));

// Retrieving complex data
$json = $settings->get_setting('myplugin_config');
$config = json_decode($json, true);
```

## Security Considerations

1. **Permission Control:** Only admins (permission level 8+) can access settings
2. **Input Validation:** FormWriter provides client-side validation
3. **SQL Injection:** All database queries use prepared statements
4. **XSS Prevention:** Values are escaped when displayed

## Summary

The new settings system provides:

- ✅ No migrations needed for new settings
- ✅ Automatic setting creation on form save
- ✅ Simple plugin integration via includes
- ✅ Consistent UI across core and plugins
- ✅ Empty string defaults for missing settings
- ✅ Clear debugging via error logs
- ✅ Namespace convention prevents conflicts

For questions or issues, check error logs first, then consult this documentation.
