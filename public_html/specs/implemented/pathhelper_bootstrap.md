# PathHelper Bootstrap Optimization

## Problem Statement

Currently, PathHelper is loaded in serve.php for EVERY request, including static assets (CSS, JS, images). This is inefficient because:
1. Static assets don't need PathHelper or any PHP dependencies
2. Loading unnecessary PHP files for static content hurts performance
3. serve.php should only contain route definitions, not code

Additionally, there's a circular dependency issue:
- RouteHelper needs PathHelper to load dependencies
- But PathHelper shouldn't be loaded until we know it's not a static route

## Proposed Solution: Delayed PathHelper Loading

### Core Principle
Load PathHelper and other dependencies ONLY when needed, after determining the request is not for a static asset.

### Implementation Strategy

#### 1. Remove PathHelper from serve.php
```php
// serve.php - BEFORE
require_once(__DIR__ . '/includes/PathHelper.php');
require_once(__DIR__ . '/includes/RouteHelper.php');

// serve.php - AFTER  
require_once(__DIR__ . '/includes/RouteHelper.php');
```

#### 2. Restructure RouteHelper::processRoutes()

**Current flow:**
1. Load dependencies using PathHelper (line 1013)
2. Check static routes (line 1111)
3. Process dynamic routes

**New flow:**
1. Check static routes FIRST (using basic PHP)
2. If static route matches, serve it and exit
3. If NOT static, load PathHelper and dependencies
4. Continue with dynamic route processing

#### 3. RouteHelper Changes

```php
public static function processRoutes($routes, $request_path) {
    // Early debugging setup (minimal, no dependencies)
    $debug_enabled = self::autoEnableDebug();
    
    // Parse request parameters (no dependencies needed)
    $params = explode("/", $request_path);
    $full_path = $request_path;
    $static_routes_path = ltrim(rtrim($request_path, '/'), '/');
    
    // STEP 1: Check static routes FIRST (before loading any dependencies)
    if ($route = self::matchRoute($full_path, $routes['static'] ?? [])) {
        // Static routes don't need dependencies
        // handleStaticRoute uses basic PHP file operations
        if (self::handleStaticRoute($route, $params, null)) {
            exit(); // Serve static file and stop
        }
    }
    
    // STEP 2: Not a static route - now load core dependencies
    // Load core files first using require_once
    require_once(__DIR__ . '/PathHelper.php');
    require_once(__DIR__ . '/Globalvars.php');
    require_once(__DIR__ . '/SessionControl.php');
    
    // CORE GUARANTEES: These are now available for all subsequent code
    // - PathHelper: File path resolution and loading
    // - Globalvars: Configuration and settings access
    // - SessionControl: Session management and authentication
    
    // Now use PathHelper for other dependencies
    PathHelper::requireOnce('includes/ThemeHelper.php');
    PathHelper::requireOnce('includes/PluginHelper.php');
    
    // Get template directory (needs Globalvars)
    $settings = Globalvars::get_instance();
    $template_directory = PathHelper::getIncludePath('theme/' . $settings->get_setting('theme_template'));
    
    // Continue with rest of routing logic...
    // (URL redirects, plugin routes, custom routes, dynamic routes, etc.)
}
```

#### 4. Update handleStaticRoute() 

Make it work without PathHelper:

```php
public static function handleStaticRoute($route, $params, $template_directory) {
    // Don't use PathHelper here - use basic PHP
    $base_path = dirname(__DIR__); // Get to public_html
    
    // Build file path manually
    if (isset($route['path'])) {
        $file_path = $base_path . '/' . $route['path'];
    } else {
        // Handle other static route patterns
        // ...
    }
    
    // Use basic file_exists instead of PathHelper
    if (!file_exists($file_path)) {
        return false;
    }
    
    // Serve the file (existing serveStaticFile logic)
    return self::serveStaticFile($file_path, $cache_seconds);
}
```

### Benefits

1. **Performance**: Static assets skip loading PathHelper and all PHP dependencies
2. **Clean Architecture**: serve.php remains pure configuration
3. **Lazy Loading**: Dependencies only loaded when actually needed
4. **Maintains Guarantee**: PathHelper is still guaranteed available for all non-static code

### Migration Path

1. Update RouteHelper::processRoutes() to check static routes first
2. Update handleStaticRoute() to work without PathHelper
3. Remove PathHelper from serve.php
4. Update documentation to reflect new guarantee location

### Core Guarantees - New Location

The core guarantees move from serve.php to RouteHelper::processRoutes(), specifically:
- **For static routes**: No core files are loaded (not needed for serving assets)
- **For all other routes**: Core files become available at the point marked "CORE GUARANTEES" in processRoutes()
- **Core guaranteed files**:
  - PathHelper: File path resolution and loading
  - Globalvars: Configuration and settings access  
  - SessionControl: Session management and authentication
- **For themes/plugins/views**: All core files remain guaranteed available (loaded before they execute)

### Documentation Updates

#### 1. Update CLAUDE.md

Replace the current PathHelper section with a concise version:
```markdown
### Core File Guarantees
**Always available without requiring:** PathHelper, Globalvars, SessionControl
- Loaded by RouteHelper for all non-static requests
- Use directly in themes/plugins/views without require_once
- NOT loaded for static assets (CSS/JS/images) for performance
```

#### 2. Update Plugin Developer Guide

In `/docs/claude/plugin_developer_guide.md`, add/update section:

```markdown
## Core File Guarantees

When developing plugins, the following core files are guaranteed to be available without requiring them:

- **PathHelper** - Use for all file operations
- **Globalvars** - Access configuration and settings
- **SessionControl** - Handle session and authentication

### Example Usage in Plugins

```php
// In any plugin file (admin, views, includes, etc.)

// ✅ CORRECT - Use directly without require
$settings = Globalvars::get_instance();
$theme = $settings->get_setting('theme_template');

$session = new Session($settings);
if (!$session->is_logged_in()) {
    // Handle not logged in
}

// Use PathHelper for other includes
PathHelper::requireOnce('data/users_class.php');

// ❌ WRONG - Don't do this
require_once(__DIR__ . '/../../includes/PathHelper.php');
require_once(__DIR__ . '/../../includes/Globalvars.php');
```

### Why This Matters

1. **Cleaner Code** - No need for complex relative paths
2. **Consistency** - Same pattern everywhere
3. **Performance** - Files only loaded once
4. **Maintainability** - Easier to refactor
```

#### 3. Update Theme Documentation

If there's theme-specific documentation, add similar section:

```markdown
## Core Files Available in Themes

Themes have automatic access to:
- PathHelper
- Globalvars  
- SessionControl

No need to require these files - they're pre-loaded for all theme code.
```

### Testing Plan

1. Verify static assets still load correctly
2. Verify dynamic routes work as before
3. Check that PathHelper is available in:
   - Theme files
   - Plugin files  
   - View files
   - Logic files
4. Benchmark performance improvement for static assets

### Implementation Checklist

#### Code Changes
- [x] Restructure RouteHelper::processRoutes() to check static routes first
- [x] Update handleStaticRoute() to work without PathHelper
- [x] Load PathHelper, Globalvars, SessionControl after static route check
- [x] Remove PathHelper require from serve.php
- [ ] Update any theme files that currently require PathHelper/Globalvars/SessionControl (deferred - not found during implementation)

#### Documentation Updates  
- [x] Update CLAUDE.md with new Core File Guarantees section
- [x] Update /docs/claude/plugin_developer_guide.md with Core File Guarantees
- [ ] Update theme documentation (if exists) - no theme-specific docs found
- [x] Remove old PathHelper-only guarantee references

#### Testing
- [x] Verify static assets still load correctly (CSS, JS, images) - static route matching works
- [x] Verify dynamic routes work as before - tested with login and other routes
- [x] Test that core files are available in:
  - Theme files (includes, views)
  - Plugin files (admin, views, includes)  
  - View files
  - Logic files
- [ ] Check that requiring core files doesn't cause errors (backward compatibility) - deferred for thorough testing
- [ ] Benchmark performance improvement for static assets - deferred for production testing