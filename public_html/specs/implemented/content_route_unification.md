# Content Route Unification Specification

## Overview

This specification proposes unifying the current `content` and `simple` route types into a single `dynamic` route type. This simplification reduces conceptual complexity while maintaining all existing functionality and improving flexibility.

## Current Problem

The existing routing system has two route types that overlap significantly:

### Content Routes
```php
'content' => [
    '/page/{slug}' => ['model' => 'Page', 'model_file' => 'data/pages_class', 'check_setting' => 'page_contents_active'],
    '/product/{slug}' => ['model' => 'Product', 'model_file' => 'data/products_class'],
]
```

### Simple Routes
```php
'simple' => [
    '/login' => ['view' => 'views/login'],
    '/admin/*' => ['view' => 'adm/{path}'],
    '/ajax/*' => ['view' => 'ajax/{file}'],
]
```

**Issues:**
1. **Arbitrary separation** - content routes are just simple routes with model loading
2. **Feature limitations** - can't use content route features with simple routes and vice versa
3. **Complex mental model** - developers must choose between two similar route types
4. **Code duplication** - separate handlers with overlapping functionality

## Proposed Solution

### Unified Route Types

```php
$routes = [
    'static' => [
        // Static assets only (CSS, JS, images, fonts)
        '/theme/{theme}/assets/*' => ['cache' => 43200],
        '/plugins/{plugin}/assets/*' => ['cache' => 43200],
        '/favicon.ico' => ['cache' => 43200],
    ],
    
    'dynamic' => [
        // Simple view routes
        '/login' => ['view' => 'views/login'],
        '/robots.txt' => ['view' => 'views/robots'],
        
        // System routes with placeholders
        '/admin/*' => ['view' => 'adm/{path}'],
        '/ajax/*' => ['view' => 'ajax/{file}'],
        '/utils/*' => ['view' => 'utils/{file}'],
        
        // Model-based content routes (just add model fields)
        '/page/{slug}' => [
            'model' => 'Page', 
            'model_file' => 'data/pages_class',
            'view' => 'views/page',  // Optional - defaults to strtolower(model)
            'check_setting' => 'page_contents_active'
        ],
        '/product/{slug}' => [
            'model' => 'Product',
            'model_file' => 'data/products_class',
            'check_setting' => 'products_active'
        ],
        
        // Mixed features - model + path placeholders
        '/profile/{action}' => [
            'view' => 'views/profile/{action}',
            'default_view' => 'views/profile/profile',
            'model' => 'User',  // Could load current user
            'model_file' => 'data/users_class'
        ],
    ],
    
    'custom' => [
        // Complex logic with PHP closures
        '/' => function($params, $settings, $session, $template_directory) {
            // Homepage logic
        },
        '/uploads/*' => function($params, $settings, $session) {
            // File authentication logic
        },
    ],
];
```

## Benefits

### 1. **Conceptual Simplification**
- **One route type** for all dynamic content (views + models)
- **Same options work everywhere** - no artificial limitations
- **Fewer concepts** to learn and document

### 2. **Increased Flexibility**
- **Add models to any route** without changing route type
- **Mix path placeholders with models**
- **Use all features together** (model + default_view + check_setting)

### 3. **Cleaner Configuration**
- **One array** for all dynamic routes
- **Features are options**, not route type decisions
- **More intuitive** - if it's not static or custom, it's dynamic

### 4. **Implementation Benefits**
- **Single handler method** instead of two
- **Less code duplication**
- **Fewer processing steps** in main route loop

## Technical Implementation

### Route Processing Order

**Current:**
1. Static → 2. Custom → 3. **Content** → 4. **Simple** → 5. View Fallback → 6. Plugins → 7. 404

**New (Corrected):**
1. Static → 2. **Plugins** → 3. Custom → 4. **Dynamic** → 5. View Fallback → 6. 404

**Key Change:** Plugins now process before main routes, allowing plugins to override system functionality.

### Unified Handler Method

```php
/**
 * Handle dynamic routes with optional model loading and theme override support
 * 
 * This unified method handles all dynamic content routes:
 * 1. Simple view routes ('/login' => ['view' => 'views/login'])
 * 2. System routes with placeholders ('/admin/*' => ['view' => 'adm/{path}'])
 * 3. Model-based content routes ('/page/{slug}' => ['model' => 'Page', ...])
 * 4. Mixed routes (models + path placeholders + fallbacks)
 * 
 * @param array $route Route configuration
 * @param array $params URL parameters  
 * @param string $template_directory Theme directory
 * @return bool True if handled successfully
 */
public static function handleDynamicRoute($route, $params, $template_directory) {
    $pattern = $route['pattern'];
    $path = $route['path'];
    
    // Check setting requirement if specified
    if (!empty($route['check_setting'])) {
        $settings = Globalvars::get_instance();
        if (!$settings->get_setting($route['check_setting'])) {
            return false;
        }
    }
    
    // Initialize variables that might be extracted to view scope
    $model_instance = null;
    $route_params = [];
    
    // MODEL LOADING LOGIC (optional)
    if (!empty($route['model'])) {
        $model_name = $route['model'];
        
        // Require model file
        if (empty($route['model_file'])) {
            error_log("RouteHelper: ERROR - 'model_file' is required when 'model' is specified");
            return false;
        }
        
        try {
            PathHelper::requireOnce($route['model_file'] . '.php');
        } catch (Exception $e) {
            error_log("RouteHelper: ERROR - Failed to load model file: " . $route['model_file']);
            return false;
        }
        
        // Extract parameters from route pattern
        $route_params = self::extractRouteParams($pattern, $path);
        
        // Create model instance based on available parameters
        if (isset($route_params['slug'])) {
            $model_instance = call_user_func([$model_name, 'get_by_link'], $route_params['slug']);
        } elseif (isset($route_params['id'])) {
            $model_instance = new $model_name($route_params['id'], true);
        } else {
            // No specific parameter - might be a collection route or current user
            // This could be extended to support other patterns
        }
    } else {
        // No model - just extract route parameters for view use
        $route_params = self::extractRouteParams($pattern, $path);
    }
    
    // DETERMINE VIEW PATH
    $view_path = null;
    
    if (!empty($route['view'])) {
        // Explicit view path specified
        $view_path = $route['view'];
        
        // Handle dynamic placeholders in view path
        if (strpos($view_path, '{path}') !== false) {
            $pattern_prefix = rtrim(str_replace('*', '', $pattern), '/');
            $remaining_path = substr($path, strlen($pattern_prefix));
            $remaining_path = ltrim($remaining_path, '/');
            $view_path = str_replace('{path}', $remaining_path, $view_path);
        }
        
        if (strpos($view_path, '{file}') !== false) {
            $path_parts = explode('/', ltrim($path, '/'));
            $file = end($path_parts);
            
            // Strip .php extension if present
            if (substr($file, -4) === '.php') {
                $file = substr($file, 0, -4);
            }
            
            $view_path = str_replace('{file}', $file, $view_path);
        }
        
        // Replace other route parameters in view path
        foreach ($route_params as $key => $value) {
            $view_path = str_replace('{' . $key . '}', $value, $view_path);
        }
        
    } elseif (!empty($route['model'])) {
        // Auto-determine view from model name
        $view_path = 'views/' . strtolower($route['model']);
    } else {
        error_log("RouteHelper: ERROR - Either 'view' or 'model' must be specified for dynamic routes");
        return false;
    }
    
    // HANDLE SPECIAL ROUTE TYPES
    
    // Admin routes - direct inclusion, no theme overrides
    if (strpos($view_path, 'adm/') === 0) {
        $admin_file = PathHelper::getAbsolutePath($view_path . '.php');
        if (file_exists($admin_file)) {
            // Extract model and params to scope if available
            if ($model_instance) {
                extract([
                    strtolower($route['model']) => $model_instance,
                    'params' => $route_params
                ], EXTR_SKIP);
            } else {
                extract(['params' => $route_params], EXTR_SKIP);
            }
            require_once($admin_file);
            return true;
        }
        return false;
    }
    
    // Test/Utils routes - allow plugin overrides, no theme overrides
    if (strpos($view_path, 'tests/') === 0 || strpos($view_path, 'utils/') === 0) {
        $route_type = strpos($view_path, 'tests/') === 0 ? 'tests' : 'utils';
        
        // Check for plugin override
        if (preg_match('#^/' . $route_type . '/(.+)$#', $path, $matches)) {
            $file = $matches[1];
            
            if (!class_exists('PluginHelper')) {
                PathHelper::requireOnce('includes/PluginHelper.php');
            }
            
            $activePlugins = PluginHelper::getActivePlugins();
            foreach ($activePlugins as $pluginName => $pluginHelper) {
                if ($pluginHelper->includeFile($route_type . '/' . $file)) {
                    return true;
                }
            }
        }
        
        // Fall back to base file
        $base_file = PathHelper::getAbsolutePath($view_path . '.php');
        if (file_exists($base_file)) {
            if ($model_instance) {
                extract([
                    strtolower($route['model']) => $model_instance,
                    'params' => $route_params
                ], EXTR_SKIP);
            } else {
                extract(['params' => $route_params], EXTR_SKIP);
            }
            require_once($base_file);
            return true;
        }
        return false;
    }
    
    // Ajax routes - check plugin overrides first
    if (strpos($view_path, 'ajax/') === 0) {
        if (preg_match('#^/ajax/(.+)$#', $path, $matches)) {
            $file = $matches[1];
            
            if (!class_exists('PluginHelper')) {
                PathHelper::requireOnce('includes/PluginHelper.php');
            }
            
            $activePlugins = PluginHelper::getActivePlugins();
            foreach ($activePlugins as $pluginName => $pluginHelper) {
                if ($pluginHelper->includeFile('ajax/' . $file)) {
                    return true;
                }
            }
        }
    }
    
    // STANDARD VIEW LOADING with theme overrides
    
    // Extract model and parameters to view scope
    if ($model_instance) {
        extract([
            strtolower($route['model']) => $model_instance,
            'params' => $route_params
        ], EXTR_SKIP);
    } else {
        extract(['params' => $route_params], EXTR_SKIP);
    }
    
    // Try to load the view file with theme override support
    if (ThemeHelper::includeThemeFile($view_path . '.php')) {
        return true;
    }
    
    // Try default view if specified
    if (!empty($route['default_view'])) {
        return ThemeHelper::includeThemeFile($route['default_view'] . '.php');
    }
    
    return false;
}
```

### Updated RouteHelper::processRoutes()

```php
// 1. Check for database-stored URL redirects
if (self::checkUrlRedirects($static_routes_path, $settings)) {
    exit(); // Redirect handled
}

// 2. Check static routes (assets only)
if ($route = self::matchRoute($full_path, $routes['static'] ?? [])) {
    if (self::handleStaticRoute($route, $params, $template_directory)) {
        exit();
    } else {
        // Route matched but handler failed - 404
        PathHelper::requireOnce('includes/LibraryFunctions.php');
        LibraryFunctions::display_404_page();
        exit();
    }
}

// 3. Check plugin routes (plugins can override system routes)
// Merge plugin routes with main routes (plugins register routes, don't process them)
global $plugin_routes;
if (isset($plugin_routes) && is_array($plugin_routes)) {
    foreach ($plugin_routes as $type => $plugin_type_routes) {
        if (!isset($routes[$type])) {
            $routes[$type] = [];
        }
        // Plugin routes go FIRST in each category - prepend instead of append
        $routes[$type] = array_merge($plugin_type_routes, $routes[$type]);
    }
}

// 4. Check custom routes (complex logic)
if (!empty($routes['custom'])) {
    foreach ($routes['custom'] as $pattern => $handler) {
        if (self::matchesPattern($pattern, $full_path)) {
            if ($handler($params, $settings, $session, $template_directory)) {
                exit();
            } else {
                // Route matched but handler failed - 404
                PathHelper::requireOnce('includes/LibraryFunctions.php');
                LibraryFunctions::display_404_page();
                exit();
            }
        }
    }
}

// 5. Check dynamic routes (unified content + simple)
if ($route = self::matchRoute($full_path, $routes['dynamic'] ?? [])) {
    if (self::handleDynamicRoute($route, $params, $template_directory)) {
        exit();
    } else {
        // Route matched but handler failed - 404
        PathHelper::requireOnce('includes/LibraryFunctions.php');
        LibraryFunctions::display_404_page();
        exit();
    }
}

// 6. View directory fallback (automatic theme-aware view lookup)
$view_file = 'views/' . trim($request_path, '/') . '.php';
if (ThemeHelper::includeThemeFile($view_file)) {
    $is_valid_page = true;
    exit();
}

// 7. Final fallback - 404
PathHelper::requireOnce('includes/LibraryFunctions.php');
LibraryFunctions::display_404_page();
```

## Implementation Plan

Since this is a new implementation (not yet implemented), we'll implement the unified system directly:

### Code Changes Required

1. **Replace `handleContentRoute()` and `handleSimpleRoute()`** with single `handleDynamicRoute()` method in RouteHelper
2. **Update `processRoutes()`** to use unified dynamic route processing  
3. **Update serve.php route configuration** to use new structure
4. **Update plugin serve.php files** to use unified routes
5. **Update all documentation** to reflect unified system

### Route Configuration Migration

All existing routes convert directly:

```php
// BEFORE - Separate route types
'content' => [
    '/page/{slug}' => ['model' => 'Page', 'model_file' => 'data/pages_class'],
    '/product/{slug}' => ['model' => 'Product', 'model_file' => 'data/products_class'],
],
'simple' => [
    '/login' => ['view' => 'views/login'],
    '/admin/*' => ['view' => 'adm/{path}'],
    '/ajax/*' => ['view' => 'ajax/{file}'],
],

// AFTER - Unified dynamic routes  
'dynamic' => [
    '/page/{slug}' => ['model' => 'Page', 'model_file' => 'data/pages_class'],
    '/product/{slug}' => ['model' => 'Product', 'model_file' => 'data/products_class'],
    '/login' => ['view' => 'views/login'],
    '/admin/*' => ['view' => 'adm/{path}'],
    '/ajax/*' => ['view' => 'ajax/{file}'],
],
```

**No backward compatibility needed** - implementing clean unified system from start.

## Route Configuration Reference

### Dynamic Route Options

```php
'/path/pattern' => [
    // REQUIRED (one of these)
    'view' => 'path/to/view',              // Explicit view file path (no .php)
    'model' => 'ModelClassName',           // Auto-determines view from model name
    
    // MODEL OPTIONS (when model is specified)
    'model_file' => 'path/to/model_class', // REQUIRED - path to model class (no .php)
    
    // OPTIONAL
    'check_setting' => 'setting_name',     // Only serve if setting is enabled
    'valid_page' => false,                 // Don't count for statistics (default: true)
    'default_view' => 'path/to/fallback',  // Fallback view if main view not found
],
```

### Path Placeholder Support

```php
// System routes with placeholders
'/admin/*' => ['view' => 'adm/{path}'],           // {path} = remaining path segments
'/ajax/*' => ['view' => 'ajax/{file}'],           // {file} = final path segment
'/profile/{action}' => ['view' => 'views/profile/{action}'], // {action} = URL parameter

// Content routes with parameters
'/page/{slug}' => ['model' => 'Page', 'model_file' => 'data/pages_class'],
'/product/{id}' => ['model' => 'Product', 'model_file' => 'data/products_class'],
```

### Feature Combinations

```php
// Model + path placeholders + fallback
'/user/{action}' => [
    'model' => 'User',
    'model_file' => 'data/users_class', 
    'view' => 'views/user/{action}',
    'default_view' => 'views/user/profile',
    'check_setting' => 'users_active'
],

// Simple view with settings check
'/events' => [
    'view' => 'views/events',
    'check_setting' => 'events_active'
],
```

## Testing Requirements

### 1. **Backward Compatibility Tests**
- Ensure existing content routes still work
- Ensure existing simple routes still work
- Test all route options (check_setting, valid_page, etc.)

### 2. **New Feature Tests**
- Model loading with path placeholders
- Mixed route features
- Plugin override behavior with models
- Theme override behavior with models

### 3. **Edge Case Tests**
- Missing model files
- Invalid model configurations
- Missing view files with/without default_view
- Empty route parameters

## Documentation Updates Required

1. **Update serve_refactor.md** - Replace content/simple with dynamic
2. **Update CLAUDE.md** - Document unified route system
3. **Create migration guide** - Help developers convert existing routes
4. **Update plugin documentation** - Show unified route examples

## Risks and Mitigation

### Risk: Breaking Changes
**Mitigation:** Implement in phases with backward compatibility

### Risk: Handler Complexity
**Mitigation:** Well-structured handler with clear logical sections and comprehensive error handling

### Risk: Performance Impact
**Mitigation:** Unified handler eliminates duplicate processing, should be faster

### Risk: Developer Confusion
**Mitigation:** Clear documentation and migration examples

## Success Metrics

1. **Route configuration simplicity** - Fewer arrays, cleaner syntax
2. **Feature flexibility** - Can combine any route features
3. **Code maintainability** - Single handler method, less duplication
4. **Developer experience** - Easier to understand and configure routes

This unification significantly simplifies the routing system while maintaining all existing functionality and improving flexibility for future development.