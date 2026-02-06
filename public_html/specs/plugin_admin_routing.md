# Investigation: Plugin Admin Routes Return 404

## Problem

All plugin admin pages return 404, including for the active plugin (ControlD). Routes tested:
- `/plugins/controld/admin/admin_ctld_account` → 404
- `/plugins/bookings/admin/admin_bookings` → 404
- `/plugins/items/admin/admin_items` → 404

## Current Implementation

**File:** `serve.php` lines 150-165

```php
'/plugins/{plugin}/admin/*' => function($params, $settings, $session, $template_directory) {
    $plugin = $params[2] ?? '';
    $admin_page = $params[4] ?? 'index';
    $admin_file = "plugins/{$plugin}/admin/{$admin_page}.php";

    error_log("Plugin admin route: plugin={$plugin}, admin_page={$admin_page}, file={$admin_file}, exists=" . (file_exists($admin_file) ? 'yes' : 'no'));

    if (file_exists($admin_file)) {
        $is_valid_page = true;
        require_once($admin_file);
        return true;
    }
    return false;
},
```

## Suspected Root Causes

### 1. Relative Path Resolution
The route handler constructs a relative path (`plugins/{plugin}/admin/{admin_page}.php`) and checks with `file_exists()`. This will fail if the working directory at the time of the check isn't the web root (`/var/www/html/joinerytest/public_html/`).

**Diagnosis step:** Check the error log output from the `error_log()` call on line 157. It logs whether the file exists.

### 2. Route Pattern Matching
The wildcard route `/plugins/{plugin}/admin/*` may not be matching correctly. The `$params` array extraction (`$params[2]` for plugin, `$params[4]` for page) assumes a specific URL segmentation that may not match what RouteHelper provides.

**Diagnosis step:** Verify how RouteHelper splits the URL and what indexes the segments map to.

### 3. Plugin File Location
Verify the actual admin files exist at the expected paths:
- `plugins/controld/admin/admin_ctld_account.php`
- `plugins/bookings/admin/` (list files)
- `plugins/items/admin/` (list files)

## Investigation Steps

1. **Check error log** for the debug output from the route handler:
   ```bash
   grep "Plugin admin route" /var/www/html/joinerytest/logs/error.log
   ```

2. **Verify file existence** from the web root:
   ```bash
   ls -la /var/www/html/joinerytest/public_html/plugins/controld/admin/
   ls -la /var/www/html/joinerytest/public_html/plugins/bookings/admin/
   ls -la /var/www/html/joinerytest/public_html/plugins/items/admin/
   ```

3. **Check RouteHelper pattern matching** — read `includes/RouteHelper.php` to understand how wildcard routes extract params and what indexes they use.

4. **Test path resolution** — verify what `getcwd()` returns during request processing:
   ```php
   error_log("CWD: " . getcwd());
   ```

## Potential Fixes

### If relative path issue:
```php
// Replace relative path with absolute
$admin_file = PathHelper::getIncludePath("plugins/{$plugin}/admin/{$admin_page}.php");
```

### If params index issue:
Adjust the `$params` array indexes based on how RouteHelper actually splits the URL.

### If route pattern not matching:
The wildcard route may need to be registered differently. Check if other wildcard routes in serve.php work, and compare the pattern.

## Priority
HIGH — Plugin admin functionality is completely inaccessible.
