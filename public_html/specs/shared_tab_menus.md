# Shared Tab Menus Spec

## Overview

Profile settings pages (account_edit, password_edit, address_edit, phone_numbers_edit, contact_preferences) each define an identical tab menu array in their logic files. Admin settings pages (general, payments, email) do the same. When a new tab is added (e.g., a "Security" tab for 2FA), every logic file in the group must be updated individually. This is fragile and has already been flagged as a maintenance concern.

This spec consolidates shared tab menus into named, centrally-defined groups that any page can reference and that plugins can extend.

## Current State

### Profile settings tabs (5 duplicated definitions)
Each of these logic files defines the identical array:
- `logic/account_edit_logic.php` (line 98)
- `logic/password_edit_logic.php` (line 50)
- `logic/address_edit_logic.php` (line 77)
- `logic/phone_numbers_edit_logic.php` (line 67)
- `logic/contact_preferences_logic.php` (line 64)

```php
$page_vars['tab_menus'] = array(
    'Edit Account' => '/profile/account_edit',
    'Change Password' => '/profile/password_edit',
    'Edit Address' => '/profile/address_edit',
    'Edit Phone Number' => '/profile/phone_numbers_edit',
    'Change Contact Preferences' => '/profile/contact_preferences',
);
```

### Admin settings tabs (3 duplicated definitions)
- `adm/admin_settings.php` (line 46)
- `adm/admin_settings_payments.php` (line 70)
- `adm/admin_settings_email.php` (line 48)

### Event listing tabs (not duplicated)
Built dynamically from event types in `logic/events_logic.php`. Not a candidate for this spec.

### controld plugin profile tabs
The controld plugin defines its own profile tab arrays in both logic files and view files (inconsistently). A plugin extension mechanism would let controld add or replace tabs on the standard profile group.

## Design Questions

1. **Should this be a registry (register at runtime) or a definition (static config)?**
   - Registry: `TabMenus::register('profile_settings', 'Security', '/profile/security');` — flexible, but requires boot-order awareness
   - Definition: `TabMenus::get('profile_settings')` returns a static array defined in one place — simpler, but plugins can't extend without hooks
   - Hybrid: Define base groups statically, allow plugins to append via a hook

2. **Should plugins be able to modify existing groups?**
   - The controld plugin currently redefines the entire profile tab array. Should it be able to add/remove/reorder tabs on the core `profile_settings` group instead?
   - If yes, a hook or filter pattern is needed (e.g., `TabMenus::get('profile_settings')` fires a hook that plugins can listen to)

3. **Should tab visibility be conditional?**
   - Some tabs might only appear for certain permission levels or when a feature is enabled (e.g., "Security" tab only when TOTP feature is active)
   - This could be handled by the group definition (callback/closure per tab) or by the caller after getting the array

4. **Where do the group definitions live?**
   - A new `includes/TabMenus.php` class
   - Or inline in an existing include (Globalvars, LibraryFunctions)
   - Or as simple include files (`includes/tab_menus/profile_settings.php`)

## Recommended Approach

Keep it simple. A single class with static group definitions and a plugin filter hook:

**`includes/TabMenus.php`:**
```php
class TabMenus {
    private static $groups = [];
    private static $filters = [];

    // Register a named tab group with its base tabs
    public static function define($group_name, $tabs) {
        self::$groups[$group_name] = $tabs;
    }

    // Plugins call this to modify a group before it's returned
    public static function filter($group_name, $callback) {
        self::$filters[$group_name][] = $callback;
    }

    // Get the final tab array for a group (base + plugin modifications)
    public static function get($group_name) {
        $tabs = self::$groups[$group_name] ?? [];
        if (!empty(self::$filters[$group_name])) {
            foreach (self::$filters[$group_name] as $callback) {
                $tabs = $callback($tabs);
            }
        }
        return $tabs;
    }
}
```

**Base group definitions (loaded once at startup):**
```php
TabMenus::define('profile_settings', [
    'Edit Account' => '/profile/account_edit',
    'Change Password' => '/profile/password_edit',
    'Edit Address' => '/profile/address_edit',
    'Edit Phone Number' => '/profile/phone_numbers_edit',
    'Change Contact Preferences' => '/profile/contact_preferences',
]);

TabMenus::define('admin_settings', [
    'General Settings' => '/admin/admin_settings',
    'Payment Settings' => '/admin/admin_settings_payments',
    'Email Settings' => '/admin/admin_settings_email',
]);
```

**Usage in logic files (replaces the duplicated arrays):**
```php
// Before (duplicated in 5 files):
$page_vars['tab_menus'] = array(
    'Edit Account' => '/profile/account_edit',
    // ... same 5 entries ...
);

// After (one line):
$page_vars['tab_menus'] = TabMenus::get('profile_settings');
```

**Plugin extension:**
```php
// In controld plugin init:
TabMenus::filter('profile_settings', function($tabs) {
    // Add a tab
    $tabs['Devices'] = '/profile/devices';
    // Or remove one
    unset($tabs['Edit Phone Number']);
    return $tabs;
});
```

**Adding a new tab (e.g., TOTP Security):**
```php
// One place, not five:
TabMenus::define('profile_settings', [
    'Edit Account' => '/profile/account_edit',
    'Change Password' => '/profile/password_edit',
    'Edit Address' => '/profile/address_edit',
    'Edit Phone Number' => '/profile/phone_numbers_edit',
    'Change Contact Preferences' => '/profile/contact_preferences',
    'Security' => '/profile/security',
]);
```

## Open Questions

- **Where do the `define()` calls live?** Options: in TabMenus.php itself (hardcoded), in a separate config file loaded at boot, or registered by each subsystem. Hardcoding the base groups in TabMenus.php is simplest for a small number of groups.
- **Should `get()` support conditional tabs?** E.g., only show "Security" when `totp_active` setting is true. Could accept an optional context array, or callers can just filter the result.
- **Should the controld plugin migrate to using `filter()`?** Or is that a separate cleanup task?

## Files Summary

### New Files
| File | Purpose |
|------|---------|
| `includes/TabMenus.php` | Tab menu group registry with plugin filter support |

### Modified Files
| File | Changes |
|------|---------|
| `logic/account_edit_logic.php` | Replace inline array with `TabMenus::get('profile_settings')` |
| `logic/password_edit_logic.php` | Replace inline array with `TabMenus::get('profile_settings')` |
| `logic/address_edit_logic.php` | Replace inline array with `TabMenus::get('profile_settings')` |
| `logic/phone_numbers_edit_logic.php` | Replace inline array with `TabMenus::get('profile_settings')` |
| `logic/contact_preferences_logic.php` | Replace inline array with `TabMenus::get('profile_settings')` |
| `adm/admin_settings.php` | Replace inline array with `TabMenus::get('admin_settings')` |
| `adm/admin_settings_payments.php` | Replace inline array with `TabMenus::get('admin_settings')` |
| `adm/admin_settings_email.php` | Replace inline array with `TabMenus::get('admin_settings')` |

## Implementation Order

1. Create `TabMenus.php` with `define()`, `filter()`, `get()` and hardcoded base groups
2. Update the 5 profile logic files
3. Update the 3 admin settings files
4. (Optional) Migrate controld plugin to use `filter()`
