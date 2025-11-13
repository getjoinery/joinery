# min_permission Routing System - Complete Specification

## Overview

This specification implements a `min_permission` parameter for routes in serve.php that allows centralized access control at the routing layer. Routes can specify minimum permission levels required for access, with automatic enforcement before route processing.

## Core Requirements

1. **Route-level permission enforcement** - Check permissions before processing any route
2. **Numeric permission levels** - Simple integer-based permission system (0=guest, 1=user, 8=admin, 10=superadmin)
3. **Automatic session handling** - Initialize sessions only when permission checks are needed
4. **Error handling** - Graceful handling of permission failures with appropriate responses
5. **Performance optimization** - Minimal overhead for public routes and static assets
6. **Backward compatibility** - Existing routes without permission specs continue working unchanged

## Core Code Changes

### 1. RouteHelper.php Modifications

```php
<?php
/**
 * Enhanced RouteHelper with permission checking
 */
class RouteHelper {
    
    /**
     * Process routes with permission checking
     */
    public static function processRoutes($routes, $request_path, $template_directory = null) {
        // Normalize request path
        $normalized_path = self::normalizePath($request_path);
        
        // Process route types in order: static, theme, custom, dynamic
        foreach (['static', 'custom', 'dynamic'] as $route_type) {
            if (!isset($routes[$route_type])) continue;
            
            foreach ($routes[$route_type] as $pattern => $route_config) {
                $params = self::extractRouteParams($pattern, $normalized_path);
                if ($params !== false) {
                    // Check permissions before processing route
                    if (is_array($route_config) && isset($route_config['min_permission'])) {
                        $session = SessionControl::get_instance();
                        $session->check_permission($route_config['min_permission']);
                    }
                    
                    // Process the route based on type
                    switch ($route_type) {
                        case 'static':
                            return self::handleStaticRoute($route_config, $params, $template_directory);
                        case 'custom':
                            return self::handleCustomRoute($route_config, $params, $template_directory);
                        case 'dynamic':
                            return self::handleDynamicRoute($route_config, $params, $template_directory);
                    }
                }
            }
        }
        
        // No matching route found - check for view file fallback
        $view_file = $template_directory . '/views' . $normalized_path . '.php';
        if (file_exists($view_file)) {
            include $view_file;
            return;
        }
        
        // Show 404 page
        self::show404();
    }
    
    
    
    // ... existing methods remain unchanged ...
}
```

### 2. Protect All Test Directories

To secure all test directories with superadmin access, modify the existing test route in serve.php:

```php
'/tests/*' => ['view' => 'tests/{path}', 'min_permission' => 10],  // Test routes probably shouldn't be in production
```

This will require permission level 10 (superadmin) to access any URL starting with `/tests/`.

### 3. Implementation Summary

**Required Changes:**
1. **RouteHelper.php** - Add 3 lines of permission checking code
2. **serve.php routes** - Optionally add `min_permission` to routes that need protection

**Leverages Existing Systems:**
- Uses existing `SessionControl::check_permission()` for validation
- Uses existing error handling and redirect system
- Uses existing 403/login pages without modification

## Permission Level Standards

Establish consistent permission levels across the system:

```php
const PERMISSION_LEVELS = [
    0  => 'Guest (not logged in)',
    1  => 'Registered User',
    5  => 'Member', 
    8  => 'Administrator',
    10 => 'Super Administrator'
];
```

## Testing and Validation

### Testing

The implementation can be tested by:

1. **Adding `min_permission` to a test route** in serve.php
2. **Accessing the route without login** - should redirect to login page
3. **Logging in with insufficient permission** - should show existing 403 error page
4. **Logging in with sufficient permission** - should access the route normally

No additional test infrastructure needed - uses existing error handling.

## Benefits

1. **Centralized Security** - Route permissions defined in one place
2. **Minimal Code Changes** - Only 3 lines added to RouteHelper
3. **Backward Compatible** - Existing routes work unchanged
4. **Leverages Existing Systems** - Uses current SessionControl and error handling
5. **Simple Implementation** - No new infrastructure or error pages needed

This specification provides a minimal implementation of permission-based routing that integrates seamlessly with the existing Joinery CMS architecture.