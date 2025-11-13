# Plugin as Theme Hybrid System Specification

## Overview

This specification describes a system where plugins can provide complete UI implementations that work alongside or replace themes. The architecture maintains theme sovereignty while allowing plugins to serve as complete applications with their own views and assets.

## Core Principles

1. **Themes always have priority** - Any active theme can override any plugin view
2. **Plugins provide fallback views** - When theme doesn't provide a view, plugin views are used
3. **Blank theme enables plugin UI** - A system-provided blank theme allows plugins to act as the primary UI
4. **No special configuration required** - Plugins automatically provide views based on route context

## Architecture Changes

### 1. View Resolution Order

The system will check for views in this order:
1. Active theme views (always has priority)
2. Current plugin views (based on route or explicit specification)
3. Base system views

### 2. Route Priority Order

Routes are processed in this order (first match wins):
1. Theme routes (from theme's serve.php)
2. Plugin routes (from plugin's serve.php if detected)
3. Main routes (from main serve.php)

No duplicate routes allowed - first match wins.

### 3. Plugin Detection

Plugins are detected as "current" based on:
- URL prefix matching (`/plugins/{name}/` or custom prefix)
- Plugin-declared route prefixes in plugin.json
- Optimized to check common patterns first, then scan directories only if needed

### 4. Blank Theme

A minimal theme that provides no views, allowing plugins to become the effective UI. Located in `/theme/blank/`.

## Implementation Details

### ThemeHelper.php Changes

**Before:**
```php
class ThemeHelper extends ComponentBase {
    public static function includeThemeFile($path, $themeName = null, $variables = array()) {
        if ($themeName === null) {
            $themeName = self::getActive();
        }
        
        $theme_path = "theme/{$themeName}/views/{$path}.php";
        $base_path = "views/{$path}.php";
        
        // Check theme-specific file first
        if (file_exists(PathHelper::getIncludePath($theme_path))) {
            extract($variables);
            include PathHelper::getIncludePath($theme_path);
            return true;
        }
        
        // Fall back to base views
        if (file_exists(PathHelper::getIncludePath($base_path))) {
            extract($variables);
            include PathHelper::getIncludePath($base_path);
            return true;
        }
        
        return false;
    }
    
    public static function asset($path, $themeName = null) {
        if ($themeName === null) {
            $themeName = self::getActive();
        }
        
        $version = self::getAssetVersion($themeName, $path);
        $versionString = $version ? "?v={$version}" : '';
        
        return "/theme/{$themeName}/assets/{$path}{$versionString}";
    }
}
```

**After:**
```php
class ThemeHelper extends ComponentBase {
    public static function includeThemeFile($path, $themeName = null, $variables = array(), $plugin_specify = null) {
        if ($themeName === null) {
            $themeName = self::getActive();
        }
        
        // 1. Theme always gets first priority
        $theme_path = "theme/{$themeName}/views/{$path}.php";
        if (file_exists(PathHelper::getIncludePath($theme_path))) {
            extract($variables);
            include PathHelper::getIncludePath($theme_path);
            return true;
        }
        
        // 2. Check plugin (specified or current based on route)
        $plugin = $plugin_specify ?: RouteHelper::getCurrentPlugin();
        if ($plugin) {
            $plugin_path = "plugins/{$plugin}/views/{$path}.php";
            if (file_exists(PathHelper::getIncludePath($plugin_path))) {
                extract($variables);
                include PathHelper::getIncludePath($plugin_path);
                return true;
            }
        }
        
        // 3. Base views fallback
        $base_path = "views/{$path}.php";
        if (file_exists(PathHelper::getIncludePath($base_path))) {
            extract($variables);
            include PathHelper::getIncludePath($base_path);
            return true;
        }
        
        // Not found - log if debug mode
        $settings = Globalvars::get_instance();
        if ($settings->get_setting('debug') == '1') {
            $attempted = self::getViewResolutionOrder($path, $themeName, $plugin);
            error_log("Optional view not found: {$path} (theme: {$themeName}, plugin: {$plugin})");
            echo "<!-- Optional view not found: {$path} -->\n";
        }
        
        return false;
    }
    
    // New method for views that MUST exist (used by routes)
    public static function requireThemeFile($path, $themeName = null, $variables = array(), $plugin_specify = null) {
        $result = self::includeThemeFile($path, $themeName, $variables, $plugin_specify);
        
        if (!$result) {
            $settings = Globalvars::get_instance();
            $debug = $settings->get_setting('debug') == '1';
            
            $plugin = $plugin_specify ?: RouteHelper::getCurrentPlugin();
            $attempted = self::getViewResolutionOrder($path, $themeName, $plugin);
            error_log("Required view not found: {$path}");
            
            if ($debug) {
                error_log("Attempted: " . implode(', ', $attempted));
                echo "<!-- Required view not found: {$path} -->\n";
                echo "<!-- Attempted: " . implode(', ', $attempted) . " -->\n";
            }
            
            // Use existing 404 function
            LibraryFunctions::display_404_page();
            exit;
        }
        
        return $result;
    }
    
    public static function asset($path, $themeName = null) {
        if ($themeName === null) {
            $themeName = self::getActive();
        }
        
        // Check theme first
        $theme_asset = "/theme/{$themeName}/assets/{$path}";
        if (file_exists($_SERVER['DOCUMENT_ROOT'] . $theme_asset)) {
            $version = self::getAssetVersion($themeName, $path);
            $versionString = $version ? "?v={$version}" : '';
            return "{$theme_asset}{$versionString}";
        }
        
        // Check current plugin
        $current_plugin = RouteHelper::getCurrentPlugin();
        if ($current_plugin) {
            $plugin_asset = "/plugins/{$current_plugin}/assets/{$path}";
            if (file_exists($_SERVER['DOCUMENT_ROOT'] . $plugin_asset)) {
                // Plugin asset versioning will be added later
                return $plugin_asset;
            }
        }
        
        // Return theme path even if not found (will 404)
        $version = self::getAssetVersion($themeName, $path);
        $versionString = $version ? "?v={$version}" : '';
        return "{$theme_asset}{$versionString}";
    }
    
    // New helper method for getting view resolution order (useful for debugging)
    public static function getViewResolutionOrder($path, $themeName = null, $plugin = null) {
        if ($themeName === null) {
            $themeName = self::getActive();
        }
        
        $order = [];
        $order[] = "theme/{$themeName}/views/{$path}.php";
        
        if (!$plugin) {
            $plugin = RouteHelper::getCurrentPlugin();
        }
        if ($plugin) {
            $order[] = "plugins/{$plugin}/views/{$path}.php";
        }
        
        $order[] = "views/{$path}.php";
        
        return $order;
    }
}
```

### RouteHelper.php Changes

**Before:**
```php
class RouteHelper {
    public static function processRoutes($routes, $request_path, $settings = null, $session = null, $template_directory = null) {
        // Normalize the request path
        if (empty($request_path) || $request_path === '/') {
            $request_path = '/';
        } else {
            // Ensure path starts with /
            if ($request_path[0] !== '/') {
                $request_path = '/' . $request_path;
            }
            // Remove trailing slash for non-root paths
            if (strlen($request_path) > 1 && substr($request_path, -1) === '/') {
                $request_path = substr($request_path, 0, -1);
            }
        }
        
        // Process static routes
        if (isset($routes['static'])) {
            foreach ($routes['static'] as $pattern => $config) {
                $params = self::extractRouteParams($pattern, $request_path);
                if ($params !== false) {
                    return self::handleStaticRoute($config, $params, $template_directory);
                }
            }
        }
        
        // Process custom routes
        if (isset($routes['custom'])) {
            foreach ($routes['custom'] as $pattern => $handler) {
                $params = self::extractRouteParams($pattern, $request_path);
                if ($params !== false && is_callable($handler)) {
                    return $handler($params, $settings, $session, $template_directory);
                }
            }
        }
        
        // Process dynamic routes
        if (isset($routes['dynamic'])) {
            foreach ($routes['dynamic'] as $pattern => $config) {
                $params = self::extractRouteParams($pattern, $request_path);
                if ($params !== false) {
                    return self::handleDynamicRoute($config, $params, $template_directory, $settings, $session);
                }
            }
        }
        
        return false;
    }
}
```

**After:**
```php
class RouteHelper {
    private static $current_plugin = null;
    
    public static function processRoutes($routes, $request_path, $settings = null, $session = null, $template_directory = null) {
        // Normalize the request path
        if (empty($request_path) || $request_path === '/') {
            $request_path = '/';
        } else {
            // Ensure path starts with /
            if ($request_path[0] !== '/') {
                $request_path = '/' . $request_path;
            }
            // Remove trailing slash for non-root paths
            if (strlen($request_path) > 1 && substr($request_path, -1) === '/') {
                $request_path = substr($request_path, 0, -1);
            }
        }
        
        // Detect current plugin based on route
        self::$current_plugin = self::detectPluginByRoute($request_path);
        
        // Build complete route array with proper priority
        $all_routes = ['static' => [], 'custom' => [], 'dynamic' => []];
        
        // 1. Theme routes have highest priority
        $theme = ThemeHelper::getActive();
        $theme_routes_file = "theme/{$theme}/serve.php";
        if (file_exists(PathHelper::getIncludePath($theme_routes_file))) {
            $routes = [];
            include PathHelper::getIncludePath($theme_routes_file);
            if (!empty($routes)) {
                $all_routes = self::mergeRoutes($all_routes, $routes);
            }
        }
        
        // 2. Plugin routes (if detected) have second priority
        if (self::$current_plugin) {
            $plugin_routes_file = "plugins/" . self::$current_plugin . "/serve.php";
            if (file_exists(PathHelper::getIncludePath($plugin_routes_file))) {
                $routes = [];
                include PathHelper::getIncludePath($plugin_routes_file);
                if (!empty($routes)) {
                    $all_routes = self::mergeRoutes($all_routes, $routes);
                }
            }
        }
        
        // 3. Main routes have lowest priority
        $all_routes = self::mergeRoutes($all_routes, $routes);
        
        // Use merged routes for processing
        $routes = $all_routes;
        
        // Process static routes
        if (isset($routes['static'])) {
            foreach ($routes['static'] as $pattern => $config) {
                $params = self::extractRouteParams($pattern, $request_path);
                if ($params !== false) {
                    return self::handleStaticRoute($config, $params, $template_directory);
                }
            }
        }
        
        // Process custom routes
        if (isset($routes['custom'])) {
            foreach ($routes['custom'] as $pattern => $handler) {
                $params = self::extractRouteParams($pattern, $request_path);
                if ($params !== false && is_callable($handler)) {
                    return $handler($params, $settings, $session, $template_directory);
                }
            }
        }
        
        // Process dynamic routes
        if (isset($routes['dynamic'])) {
            foreach ($routes['dynamic'] as $pattern => $config) {
                $params = self::extractRouteParams($pattern, $request_path);
                if ($params !== false) {
                    return self::handleDynamicRoute($config, $params, $template_directory, $settings, $session);
                }
            }
        }
        
        return false;
    }
    
    /**
     * Get the currently active plugin based on route
     */
    public static function getCurrentPlugin() {
        return self::$current_plugin;
    }
    
    /**
     * Detect which plugin owns the current route (optimized)
     */
    private static function detectPluginByRoute($path) {
        // 1. FASTEST: Check standard /plugins/{name}/ pattern first
        if (preg_match('#^/plugins/([^/]+)/#', $path, $matches)) {
            // Just check directory exists, don't load metadata
            if (is_dir(PathHelper::getIncludePath("plugins/{$matches[1]}"))) {
                return $matches[1];
            }
        }
        
        // 2. FASTER: Only check plugins that declare routes_prefix
        // Scan directory for plugin.json files instead of loading all plugins
        $plugins_dir = PathHelper::getIncludePath('plugins');
        if (!is_dir($plugins_dir)) {
            return null;
        }
        
        $directories = scandir($plugins_dir);
        foreach ($directories as $dir) {
            if ($dir === '.' || $dir === '..' || !is_dir($plugins_dir . '/' . $dir)) {
                continue;
            }
            
            $plugin_json = $plugins_dir . '/' . $dir . '/plugin.json';
            if (file_exists($plugin_json)) {
                $metadata = json_decode(file_get_contents($plugin_json), true);
                if (isset($metadata['routes_prefix']) && strpos($path, $metadata['routes_prefix']) === 0) {
                    return $dir;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Merge routes arrays without duplicates (first wins)
     */
    private static function mergeRoutes($existing, $new) {
        foreach (['static', 'custom', 'dynamic'] as $type) {
            if (isset($new[$type])) {
                foreach ($new[$type] as $pattern => $config) {
                    // Only add if pattern doesn't already exist (first wins)
                    if (!isset($existing[$type][$pattern])) {
                        $existing[$type][$pattern] = $config;
                    }
                }
            }
        }
        return $existing;
    }
    
    /**
     * Clear the current plugin (useful for testing)
     */
    public static function clearCurrentPlugin() {
        self::$current_plugin = null;
    }
}
```

### PluginHelper.php Changes

**Before:**
```php
class PluginHelper extends ComponentBase {
    // Existing methods...
}
```

**After:**
```php
class PluginHelper extends ComponentBase {
    // Existing methods remain unchanged...
    
    /**
     * Get a service instance from a plugin
     * Services provide business logic without UI dependencies
     */
    public static function getService($plugin_name, $service_name = null) {
        $service_name = $service_name ?: 'Service';
        $service_file = "plugins/{$plugin_name}/services/{$service_name}.php";
        
        if (file_exists(PathHelper::getIncludePath($service_file))) {
            PathHelper::requireOnce($service_file);
            $class_name = ucfirst($plugin_name) . $service_name;
            if (class_exists($class_name)) {
                return new $class_name();
            }
        }
        
        return null;
    }
    
    /**
     * Check if a plugin provides a specific service
     */
    public static function hasService($plugin_name, $service_name = null) {
        $service_name = $service_name ?: 'Service';
        $service_file = "plugins/{$plugin_name}/services/{$service_name}.php";
        return file_exists(PathHelper::getIncludePath($service_file));
    }
    
    /**
     * Get all plugins of a specific type
     */
    public static function getByType($type) {
        $plugins = [];
        foreach (self::getAll() as $name => $plugin) {
            $metadata = $plugin->getMetadata();
            if (isset($metadata['type']) && $metadata['type'] === $type) {
                $plugins[$name] = $plugin;
            }
        }
        return $plugins;
    }
}
```

### Blank Theme Creation

Create a new blank theme at `/theme/blank/`:

**theme/blank/theme.json:**
```json
{
    "name": "blank",
    "display_name": "No Theme (Plugin UI)",
    "description": "Minimal theme that allows plugins to provide the complete user interface",
    "author": "System",
    "version": "1.0.0",
    "framework": "none",
    "is_blank": true,
    "supports": {
        "responsive": true,
        "dark_mode": false
    }
}
```

**theme/blank/serve.php:**
```php
<?php
// Blank theme serve.php - no routes defined
// Plugins will provide their own routes when detected
$routes = [
    'static' => [],
    'dynamic' => [],
    'custom' => []
];
```

**theme/blank/views/:** (empty directory)

### Plugin.json Format

Plugins can optionally declare their type and route prefix:

**Minimal plugin.json (extension):**
```json
{
    "name": "events",
    "display_name": "Event Management",
    "version": "1.0.0",
    "type": "extension"
}
```

**Application plugin.json:**
```json
{
    "name": "controld",
    "display_name": "Controld System",
    "version": "2.0.0",
    "type": "application",
    "routes_prefix": "/controld",
    "description": "Complete control panel application"
}
```

**Service plugin.json:**
```json
{
    "name": "payments",
    "display_name": "Payment Processing",
    "version": "1.5.0",
    "type": "service",
    "provides": ["stripe", "paypal", "invoicing"]
}
```

## Usage Scenarios

### Scenario 1: Extension Plugin with Theme

**Setup:**
- Active theme: Falcon (Bootstrap)
- Installed plugin: Events
- URL: `/events`

**Resolution:**
1. `theme/falcon/views/events/list.php` → Not found
2. `plugins/events/views/list.php` → Found, rendered
3. Assets from `theme/falcon/assets/`

**Result:** Events plugin renders with Falcon theme styling

### Scenario 2: Application Plugin with Blank Theme

**Setup:**
- Active theme: Blank
- Installed plugin: Controld
- URL: `/controld/dashboard`

**Resolution:**
1. `theme/blank/views/dashboard.php` → Not found (blank theme)
2. `plugins/controld/views/dashboard.php` → Found, rendered
3. Assets from `plugins/controld/assets/`

**Result:** Controld provides complete UI

### Scenario 3: Theme Override of Plugin View

**Setup:**
- Active theme: Falcon
- Installed plugin: Events
- Falcon has custom events view
- URL: `/events`

**Resolution:**
1. `theme/falcon/views/events/list.php` → Found, rendered
2. Plugin view not checked (theme wins)

**Result:** Falcon's custom events view is used

### Scenario 4: Service Plugin Usage

**Setup:**
- Active theme: Any
- Application plugin: Controld
- Service plugin: Payments

**Code in controld:**
```php
// plugins/controld/controllers/BillingController.php
$payment_service = PluginHelper::getService('payments');
if ($payment_service) {
    $subscriptions = $payment_service->getActiveSubscriptions();
    // Render with controld's UI
    ThemeHelper::includeThemeFile('billing/subscriptions', [
        'subscriptions' => $subscriptions
    ]);
}
```

**Result:** Controld uses payment logic without payment UI

### Scenario 5: Explicit Plugin Specification

**Setup:**
- Active theme: Controld (via blank theme)
- Current route: `/controld/dashboard`
- Need to include scheduler widget from scheduler plugin

**Code:**
```php
// In plugins/controld/views/dashboard.php
<div class="dashboard">
    <h1>Dashboard</h1>
    
    <?php 
    // Explicitly use scheduler plugin's calendar view
    ThemeHelper::includeThemeFile('widgets/calendar', null, [
        'events' => $events
    ], 'scheduler'); // plugin_specify parameter
    ?>
</div>
```

**Resolution for calendar widget:**
1. `theme/blank/views/widgets/calendar.php` → Not found
2. `plugins/scheduler/views/widgets/calendar.php` → Found (explicit plugin)
3. Base fallback not needed

**Result:** Dashboard uses scheduler's calendar widget while maintaining controld context

## Migration Path

### Phase 1: Core Implementation
1. Update ThemeHelper with plugin fallback support
2. Update RouteHelper with plugin detection
3. Create blank theme

### Phase 2: Plugin Updates
1. Move plugin views to `/views` subdirectory if needed
2. Add `routes_prefix` to application plugins
3. Add service classes for shared functionality

### Phase 3: Documentation
1. Update plugin development guide
2. Update theme development guide
3. Create examples for each plugin type

## Benefits

1. **Clean Architecture** - Themes control UI, plugins provide functionality
2. **No Special Cases** - Same resolution order everywhere  
3. **Flexibility** - Plugins can be extensions, applications, or services
4. **Theme Sovereignty** - Themes can always override anything
5. **Simple Mental Model** - Files exist → they work
6. **Gradual Migration** - Existing plugins/themes continue working

## Backward Compatibility

All existing functionality remains intact:
- Current themes work unchanged
- Current plugins work unchanged
- Only new behavior is added (plugin fallback in view resolution)

## Testing Checklist

- [ ] Theme override of plugin view works ✓ *Ready for testing - ThemeHelper implemented*
- [ ] Plugin view fallback works when theme has no view ✓ *Ready for testing - ThemeHelper implemented*  
- [ ] Blank theme allows plugin UI to show ✓ *Ready for testing - Blank theme created*
- [ ] Assets resolve correctly from plugins ✓ *Ready for testing - Asset resolution implemented*
- [ ] Service plugins can be loaded without UI ✓ *Ready for testing - PluginHelper service methods implemented*
- [ ] Route detection identifies correct plugin ✓ *Ready for testing - RouteHelper plugin detection implemented*
- [ ] Multiple plugins can coexist ✓ *Ready for testing - Route merging implemented*
- [ ] Base view fallback still works ✓ *Ready for testing - Fallback chain preserved*

## Implementation Status ✅ COMPLETED (2025-09-06)

1. **✅ High Priority - COMPLETED:**
   - ✅ ThemeHelper plugin fallback - **IMPLEMENTED**
   - ✅ RouteHelper plugin detection - **IMPLEMENTED**  
   - ✅ Blank theme creation - **CREATED**

2. **✅ Medium Priority - COMPLETED:**
   - ✅ PluginHelper service methods - **IMPLEMENTED**
   - Plugin.json standardization - *Documentation provided*

3. **🔄 Low Priority - DEFERRED:**
   - Asset versioning for plugins - *Can be added later*
   - Additional helper methods - *Can be added as needed*