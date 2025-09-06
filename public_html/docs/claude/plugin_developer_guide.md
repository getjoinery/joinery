# Plugin and Theme Developer Guide

## Overview

This guide outlines the current plugin and theme architecture after implementing the hybrid plugin/theme system. The system provides clear separation of concerns between plugins (backend-only) and themes (user-facing routing and presentation), while enabling themes to seamlessly integrate with plugin functionality through a sophisticated view resolution system.

## Current Architecture

### Plugin Architecture (Backend-Only)

**Plugins are now backend-only components** that provide:
- Data models and business logic
- Admin interfaces 
- Database migrations
- API endpoints and webhooks
- Background processing

**Plugins NO LONGER provide:**
- User-facing routes (moved to themes)
- Public views or templates
- Static assets for public pages
- Direct user interaction

### Theme Architecture (Frontend + Routing)

**Themes handle all user-facing functionality:**
- URL routing and route definitions
- Public page templates and views
- Static assets (CSS, JS, images)
- User interface presentation
- Integration with plugin backend services
- Theme-specific class implementations (PublicPage, FormWriter extensions)
- CSS framework-specific customizations

#### Hybrid Plugin/Theme System

The system now supports a hybrid approach where:
- **Plugin views can be accessed by themes** through the view resolution fallback chain
- **Themes can override plugin views** by creating their own versions
- **Multiple fallback paths** ensure views are found even when themes don't provide them
- **Theme-specific includes** allow custom class implementations while maintaining compatibility

### Route Processing Order

Routes are processed in this order:
1. **Static routes** - Direct file serving with caching
2. **Theme routes** - Theme-specific routing (serve.php in theme directory)
3. **Plugin routes** - Merged from theme serve.php that can include plugin routes
4. **Custom routes** - Complex logic routes (in main serve.php)
5. **Dynamic routes** - Standard view and model routes
6. **Fallback** - 404 handling

#### View Resolution Chain

When a view is requested, the system searches in this order:
1. **Theme-specific view** - `/theme/{theme}/views/{view}.php`
2. **Plugin views** (if plugin specified) - `/plugins/{plugin}/views/{view}.php`
3. **Base system views** - `/views/{view}.php`
4. **404 error** if no view is found

This allows themes to override any view while providing automatic fallback to plugin or system defaults.

## Plugin Development

### Required Plugin Structure

```
/plugins/my-plugin/
├── plugin.json                 # Plugin metadata
├── data/                       # Data model classes
│   └── my_data_class.php
├── admin/                      # Admin interface files
│   └── admin_my_plugin.php
├── includes/                   # Helper classes and libraries
│   └── MyPluginHelper.php
├── hooks/                      # Event hooks
│   └── my_hook.php
├── migrations/                 # Database migrations
│   └── migrations.php
└── uninstall.php              # Clean uninstall script
```

### Plugin.json Requirements

**Minimum required plugin.json:**
```json
{
    "name": "My Plugin Name",
    "version": "1.0.0",
    "description": "Plugin description"
}
```

**Complete plugin.json example:**
```json
{
    "name": "My Advanced Plugin",
    "description": "A comprehensive backend plugin",
    "version": "2.1.0",
    "author": "Your Name or Company",
    "license": "MIT",
    "homepage": "https://yoursite.com/plugin-docs",
    "requires": {
        "php": ">=8.0",
        "joinery": ">=1.0",
        "extensions": ["pdo", "json", "curl"]
    },
    "depends": {
        "core-plugin": ">=1.0"
    },
    "provides": ["api-endpoint", "widget-support"],
    "tags": ["utility", "api", "backend"]
}
```

### Data Models

Plugins provide data models using the SystemBase pattern:

```php
// plugins/my-plugin/data/my_data_class.php
class MyData extends SystemBase {
    public static $prefix = 'mdt';
    public static $tablename = 'mdt_my_data';
    public static $pkey_column = 'mdt_id';
    
    public static $field_specifications = [
        'mdt_id' => ['required' => true, 'type' => 'int'],
        'mdt_name' => ['required' => true, 'type' => 'varchar', 'length' => 255],
        'mdt_description' => ['type' => 'text'],
        'mdt_created' => ['type' => 'timestamp', 'default' => 'CURRENT_TIMESTAMP']
    ];
}
```

### Admin Interface

Plugin admin pages are accessed via the plugin admin discovery route:
`/plugins/{plugin}/admin/{page}`

```php
// plugins/my-plugin/admin/admin_my_plugin.php
<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');

$session = SessionControl::get_instance();
$session->check_permission(5);

$page = new AdminPage();
$page->admin_header([
    'title' => 'My Plugin',
    'menu-id' => 'my-plugin',
    'readable_title' => 'My Plugin Management'
]);

// Admin interface content here

$page->admin_footer();
?>
```

### Migration System

```php
// plugins/my-plugin/migrations/migrations.php
return [
    [
        'id' => '001_initial_setup',
        'version' => '1.0.0',
        'description' => 'Initial plugin setup',
        'up' => function($dbconnector) {
            // Add plugin settings
            $dbconnector->exec("INSERT INTO stg_settings (stg_name, stg_value, stg_group_name) 
                               VALUES ('my_plugin_enabled', '1', 'general')");
        },
        'down' => function($dbconnector) {
            $dbconnector->exec("DELETE FROM stg_settings WHERE stg_name LIKE 'my_plugin_%'");
        }
    ]
];
```

### Uninstall Script

```php
// plugins/my-plugin/uninstall.php
function my_plugin_uninstall() {
    try {
        $dbconnector = DbConnector::get_instance();
        
        // Drop plugin tables
        $dbconnector->exec("DROP TABLE IF EXISTS mdt_my_data CASCADE");
        
        // Remove settings
        $dbconnector->exec("DELETE FROM stg_settings WHERE stg_name LIKE 'my_plugin_%'");
        
        return true;
    } catch (Exception $e) {
        error_log("My Plugin uninstall failed: " . $e->getMessage());
        return false;
    }
}
```

## Theme Development

### Theme Structure with Plugin Integration

Themes can range from simple presentation layers to complex integrations with multiple plugins:

**Basic Theme Structure:**
```
/theme/my-theme/
├── theme.json                  # Theme metadata and configuration
├── serve.php                   # Theme routing (optional)
├── views/                      # Theme templates and view overrides
│   ├── index.php
│   ├── page.php
│   └── plugin_overrides/       # Plugin view overrides
├── assets/                     # Theme assets
│   ├── css/
│   ├── js/
│   └── images/
└── includes/                   # Theme-specific classes
    ├── PublicPage.php          # Theme-specific PublicPage implementation
    └── FormWriter.php          # Theme-specific FormWriter (optional)
```

**Advanced Theme with Plugin Integration:**
```
/theme/advanced-theme/
├── theme.json
├── serve.php                   # Includes plugin routes
├── views/
│   ├── index.php
│   ├── items/                  # Plugin view overrides
│   │   ├── list.php
│   │   └── detail.php
│   └── profile/                # Plugin view overrides
│       └── dashboard.php
├── assets/
└── includes/
    ├── PublicPage.php          # Bootstrap/UIKit/WordPress-specific implementation
    └── ThemeHelper.php         # Theme-specific utilities
```

### Theme Routing (serve.php)

Themes can define their own routes in RouteHelper format, including integration with plugin functionality:

**Basic Theme Routing:**
```php
// theme/my-theme/serve.php
$routes = [
    'dynamic' => [
        // Simple view routes (uses view resolution chain)
        '/my-page' => ['view' => 'views/my_page'],
        '/about' => ['view' => 'views/about'],
        
        // Model-based routes using plugin data
        '/item/{slug}' => [
            'model' => 'Item',
            'model_file' => 'plugins/items/data/items_class'
        ],
    ],
    
    'custom' => [
        // Complex routing logic
        '/custom-handler' => function($params, $settings, $session, $template_directory) {
            // Custom logic here
            return ThemeHelper::includeThemeFile('views/custom.php');
        },
    ],
];
```

**Plugin-Integrated Theme Routing:**
```php
// theme/sassa/serve.php - Example of plugin integration
$routes = [
    'dynamic' => [
        // ControlD plugin routes served by theme
        '/profile/device_edit' => [
            'view' => 'views/profile/ctlddevice_edit',
            'plugin_specify' => 'controld'
        ],
        '/create_account' => [
            'view' => 'views/create_account', 
            'plugin_specify' => 'controld'
        ],
        
        // Items plugin routes
        '/items' => ['view' => 'views/items/list', 'plugin_specify' => 'items'],
        '/item/{slug}' => [
            'model' => 'Item',
            'model_file' => 'plugins/items/data/items_class',
            'view' => 'views/items/detail'
        ],
    ],
];
```

### Plugin Integration in Themes

Themes integrate with plugin backend services through data models and the view resolution system:

**Using Plugin Data Models:**
```php
// theme/my-theme/views/items.php
<?php
PathHelper::requireOnce('plugins/items/data/items_class.php');

// Use plugin data models
$items = new MultiItem(['itm_active' => 1], ['itm_name' => 'ASC']);
$items->load();

foreach ($items as $item) {
    echo '<h3>' . $item->get('itm_name') . '</h3>';
    echo '<p>' . $item->get('itm_description') . '</p>';
}
?>
```

**View Override Pattern:**
```php
// theme/my-theme/views/items/list.php - Overrides plugin view
<?php
// This theme view will be used instead of plugins/items/views/items/list.php
// But can still access plugin data models and helpers
PathHelper::requireOnce('plugins/items/data/items_class.php');
PathHelper::requireOnce('plugins/items/includes/ItemsHelper.php');

$items = ItemsHelper::getActiveItems();
foreach ($items as $item) {
    // Theme-specific presentation
    include 'item_card_template.php';
}
?>
```

**Theme-Specific Class Integration:**
```php
// theme/bootstrap-theme/includes/PublicPage.php
class PublicPage extends PublicPageBase {
    protected function getTableClasses() {
        return [
            'wrapper' => 'table-responsive',
            'table' => 'table table-striped table-hover',
            'header' => 'thead-dark'
        ];
    }
    
    // Bootstrap-specific implementations
    public function renderAlert($message, $type = 'info') {
        return "<div class='alert alert-{$type}' role='alert'>{$message}</div>";
    }
}
```

### Asset Management

Theme assets are served through the theme asset route with automatic caching:
`/theme/{theme}/assets/*`

**Basic Asset Usage:**
```php
// In theme templates
<link rel="stylesheet" href="/theme/<?= $template_directory ?>/assets/css/style.css">
<script src="/theme/<?= $template_directory ?>/assets/js/app.js"></script>
<img src="/theme/<?= $template_directory ?>/assets/images/logo.png" alt="Logo">
```

**Using ThemeHelper for Assets:**
```php
// Enhanced asset management
<?php
$theme = ThemeHelper::getInstance();
?>
<link rel="stylesheet" href="<?= $theme->asset('css/bootstrap.min.css') ?>">
<link rel="stylesheet" href="<?= $theme->asset('css/theme.css') ?>">
<script src="<?= $theme->asset('js/theme.js') ?>"></script>
```

**Theme Configuration:**
```php
// Using theme.json configuration in templates
<?php
$theme_config = ThemeHelper::config('cssFramework', 'bootstrap');
if ($theme_config === 'bootstrap') {
    echo '<div class="container">';
} elseif ($theme_config === 'uikit') {
    echo '<div class="uk-container">';
}
?>
```

### Theme Metadata (theme.json)

All themes should include a `theme.json` file for proper system integration:

**Basic theme.json:**
```json
{
  "name": "my-theme",
  "displayName": "My Custom Theme",
  "version": "1.0.0",
  "description": "A custom theme for my site",
  "author": "Your Name",
  "is_stock": false,
  "requires": {
    "php": ">=7.4",
    "joinery": ">=1.0.0"
  },
  "cssFramework": "bootstrap",
  "formWriterBase": "FormWriterMasterBootstrap",
  "publicPageBase": "PublicPageBase"
}
```

**Advanced theme.json with plugin support:**
```json
{
  "name": "advanced-theme",
  "displayName": "Advanced Plugin-Integrated Theme",
  "version": "2.1.0",
  "description": "Theme with full plugin integration",
  "author": "Developer Team",
  "is_stock": false,
  "requires": {
    "php": ">=8.0",
    "joinery": ">=1.0.0"
  },
  "supports_plugins": ["controld", "items"],
  "cssFramework": "uikit",
  "formWriterBase": "FormWriterMasterDefault",
  "publicPageBase": "PublicPageBase",
  "features": {
    "responsive": true,
    "dark_mode": true,
    "plugin_integration": true
  }
}
```

## ThemeHelper Enhanced Capabilities

### includeThemeFile() Method

The enhanced `ThemeHelper::includeThemeFile()` method now supports both view resolution and theme-specific includes:

**For View Files (uses resolution chain):**
```php
// Searches: theme/{theme}/views/page.php → plugins/{plugin}/views/page.php → views/page.php
$result = ThemeHelper::includeThemeFile('views/page.php', null, $variables, 'plugin_name');
```

**For Theme Includes (direct theme access):**
```php
// Loads: theme/{theme}/includes/PublicPage.php directly
$result = ThemeHelper::includeThemeFile('includes/PublicPage.php');
```

**Method Signature:**
```php
public static function includeThemeFile(
    $path,                    // File path to include
    $themeName = null,        // Theme name (defaults to active)
    array $variables = [],    // Variables to inject
    $plugin_specify = null    // Plugin for view resolution
)
```

### Theme Management Methods

**Get Active Theme:**
```php
$current_theme = ThemeHelper::getActive();
```

**Switch Themes:**
```php
ThemeHelper::switchTheme('new-theme');
```

**Get Theme Configuration:**
```php
$css_framework = ThemeHelper::config('cssFramework', 'bootstrap', 'theme-name');
$supports_plugins = ThemeHelper::config('supports_plugins', [], 'theme-name');
```

## Migration from Old Architecture

### For Existing Plugins

1. **Remove user-facing routes** from plugin serve.php files
2. **Keep admin interfaces** and backend functionality  
3. **Ensure plugin.json exists** with proper versioning
4. **Convert migrations** to new format if needed
5. **Add uninstall script** for clean removal
6. **Update view paths** to work with the new resolution system

### For Themes Using Plugin Features

1. **Move plugin routes to theme serve.php** using RouteHelper format
2. **Update view templates** to use plugin data models directly
3. **Ensure assets are in theme/assets/** not plugin directories  
4. **Test plugin admin access** via `/plugins/{plugin}/admin/*`
5. **Create theme.json** with proper metadata and plugin support
6. **Implement theme-specific classes** (PublicPage, FormWriter) if needed
7. **Use ThemeHelper::includeThemeFile()** for view includes
8. **Test view resolution chain** to ensure fallbacks work correctly

### Example: ControlD Plugin Migration

**Before (Plugin served routes):**
```php
// plugins/controld/serve.php (REMOVED)
$routes = [
    '/profile/device_edit' => ['view' => 'views/profile/ctlddevice_edit'],
    '/create_account' => ['view' => 'views/create_account'],
];
```

**After (Theme serves routes):**
```php
// theme/sassa/serve.php (CURRENT)
$routes = [
    'dynamic' => [
        '/profile/device_edit' => ['view' => 'views/profile/ctlddevice_edit'],
        '/pricing' => ['view' => 'views/pricing'],
    ],
];
```

**Plugin now only provides:**
- Admin interface: `/plugins/controld/admin/*`
- Data models: `CtldAccount`, `CtldDevice`, etc.
- Business logic: `ControlDHelper` class

## Key Benefits of Hybrid Architecture

### Clear Separation of Concerns
- **Plugins**: Backend logic, data, admin interfaces
- **Themes**: User interface, routing, presentation
- **Hybrid Integration**: Themes can access plugin functionality without coupling

### Enhanced Flexibility
- **View Resolution Chain**: Automatic fallback from theme → plugin → system views
- **Framework Support**: Multiple CSS frameworks with proper implementations
- **Plugin Integration**: Themes can include plugin routes without breaking separation
- **Override Capability**: Themes can override any plugin view while maintaining fallbacks

### Better Security
- Plugin code not directly accessible via web URLs
- Admin interfaces protected by plugin admin discovery route
- Clear separation between public and admin functionality
- Theme-specific includes isolated from system includes

### Improved Maintainability
- Plugin updates don't affect user-facing routes
- Theme changes don't break backend functionality
- Easier testing and debugging with clear responsibilities
- Framework-specific implementations prevent CSS conflicts
- Accurate documentation through theme.json manifests

### Performance Benefits
- Static asset caching through RouteHelper
- Reduced routing complexity with priority-based processing
- Plugin code only loaded when needed
- View resolution caching prevents repeated file system checks
- Framework-specific optimizations in theme implementations

## Development Workflow

### Creating a New Plugin

1. Create plugin directory and plugin.json
2. Develop data models and business logic
3. Create admin interface if needed
4. Add migrations for database changes
5. Test admin functionality via `/plugins/{plugin}/admin/*`
6. No user-facing routes - these go in themes

### Creating a New Theme

1. **Create theme directory structure** with theme.json manifest
2. **Choose CSS framework** and implement corresponding PublicPage class
3. **Add serve.php** only if custom routing or plugin integration needed
4. **Create view templates** using plugin data models and ThemeHelper methods
5. **Add theme assets** (CSS, JS, images) in proper directory structure
6. **Test view resolution chain** to ensure plugin view fallbacks work
7. **Validate theme.json accuracy** against actual implementations
8. **Test integration** with existing plugins using the hybrid system

### Integrating Plugin and Theme

1. **Plugin provides backend services** and data models through SystemBase classes
2. **Theme creates user-facing routes** that use plugin models via serve.php
3. **Theme templates use plugin data** through proper model loading and ThemeHelper
4. **View resolution chain** allows themes to override plugin views while maintaining fallbacks  
5. **Plugin admin remains separate** from theme routing via `/plugins/{plugin}/admin/*`
6. **Theme.json documents integration** with supported plugins and framework choices
7. **CSS framework consistency** maintained between plugin data and theme presentation

## Debugging and Troubleshooting

### Route Debugging

Enable route debugging with URL parameter:
```
http://example.com/any-page?debug_routes=1
```

This shows detailed routing information in HTML comments.

### Common Issues

**404 on plugin admin pages:**
- Check plugin directory name matches URL
- Verify admin file exists in `plugins/{plugin}/admin/`
- Check file permissions

**Theme not finding plugin data:**
- Ensure plugin data class is properly included using PathHelper
- Verify plugin is installed and tables exist
- Check data model usage syntax and constructor parameters

**Views not resolving correctly:**
- Check view path format in routes (should not start with `/`)
- Verify ThemeHelper::includeThemeFile() usage for theme includes vs views
- Test view resolution chain: theme → plugin → system
- Ensure plugin_specify parameter matches actual plugin directory name

**CSS framework conflicts:**
- Verify theme.json cssFramework matches actual implementation
- Check PublicPage class extends proper base and implements getTableClasses()
- Ensure FormWriterBase matches CSS framework requirements
- Validate CSS classes match framework documentation

**Assets not loading:**
- Verify asset paths use correct theme directory
- Check file exists in `theme/{theme}/assets/`
- Ensure web server can serve static files
- Test ThemeHelper::asset() method for enhanced asset management

**Class not found errors:**
- Distinguish between theme includes (direct) vs views (resolution chain)
- Use proper PathHelper::requireOnce() for includes
- Check abstract method implementation in theme-specific classes
- Verify class file naming conventions match theme requirements

## CSS Framework Integration

### Supported CSS Frameworks

The system supports multiple CSS frameworks through theme-specific implementations:

**Bootstrap Themes:**
- CSS Framework: `bootstrap`  
- FormWriter Base: `FormWriterMasterBootstrap`
- Table Classes: `table`, `table-striped`, `table-hover`
- Container Classes: `container`, `container-fluid`

**UIKit Themes:**
- CSS Framework: `uikit`
- FormWriter Base: `FormWriterMasterDefault` 
- Table Classes: `uk-table`, `uk-table-striped`
- Container Classes: `uk-container`

**WordPress CSS Themes:**
- CSS Framework: `wordpress`
- FormWriter Base: `FormWriterMasterDefault`
- Table Classes: `wp-list-table`, `widefat`, `fixed`, `striped`
- Container Classes: `wrap`

**Tailwind CSS Themes:**
- CSS Framework: `tailwind`
- FormWriter Base: `FormWriterMasterDefault`
- Utility-first approach with custom classes

### Framework-Specific Implementations

**PublicPage Class Implementations:**

```php
// Bootstrap theme
protected function getTableClasses() {
    return [
        'wrapper' => 'table-responsive',
        'table' => 'table table-striped table-hover',
        'header' => 'thead-dark'
    ];
}

// UIKit theme  
protected function getTableClasses() {
    return [
        'wrapper' => 'uk-overflow-auto',
        'table' => 'uk-table uk-table-striped', 
        'header' => 'uk-table-header'
    ];
}

// WordPress theme
protected function getTableClasses() {
    return [
        'wrapper' => 'table-wrapper',
        'table' => 'wp-list-table widefat fixed striped',
        'header' => 'thead'
    ];
}
```

## Current Plugin Status

### Active Plugins

**ControlD (Backend-only)**
- Location: `/plugins/controld/`
- Admin: `/plugins/controld/admin/*`
- Data models: Account, Device, Filter, etc.
- User routes: Moved to sassa theme

**Items (Backend-only)**  
- Location: `/plugins/items/`
- Admin: `/plugins/items/admin/*`
- Data models: Item, ItemRelation, etc.
- User routes: Moved to sassa theme

### Theme Integration Examples

**Sassa Theme (Plugin-enabled, Bootstrap)**
- CSS Framework: `bootstrap`
- Includes ControlD routes: `/profile/*`, `/pricing`
- Includes Items routes: `/items`, `/item/{slug}`
- File: `/theme/sassa/serve.php`
- Custom PublicPage with Bootstrap table classes

**Jeremy Tunnell Theme (WordPress CSS)**
- CSS Framework: `wordpress`
- PublicPage with WordPress-specific table classes
- FormWriter using default base
- Theme.json accurately reflects implementation

**Zouk Room Theme (UIKit)**
- CSS Framework: `uikit` 
- PublicPage with UIKit table classes
- Theme.json specifies UIKit framework
- Custom styling for UIKit components

**Other Themes (Various Frameworks)**
- Falcon (Bootstrap), Tailwind (Tailwind CSS), Default (minimal)
- Each with framework-appropriate implementations
- Clean separation of concerns maintained

## Best Practices Summary

### For Plugin Developers
1. **Backend-only focus** - No user-facing routes or views
2. **Proper data models** using SystemBase patterns
3. **Admin interfaces** accessible via `/plugins/{name}/admin/*`
4. **Clean uninstall** scripts for data cleanup
5. **Version management** through plugin.json

### For Theme Developers  
1. **Framework consistency** - Match CSS framework to implementations
2. **Accurate manifests** - theme.json should reflect actual code
3. **View resolution** - Leverage the fallback chain effectively
4. **Plugin integration** - Use data models, not direct plugin coupling
5. **Asset management** - Proper theme asset organization
6. **Abstract methods** - Implement required PublicPageBase methods

### For System Integration
1. **Clear separation** - Plugins (backend) vs Themes (frontend)
2. **Flexible routing** - Theme serve.php can include plugin routes
3. **View fallbacks** - Automatic resolution chain prevents 404s
4. **Framework support** - Multiple CSS frameworks supported cleanly
5. **Maintainability** - Updates to plugins don't break theme functionality

This hybrid architecture provides maximum flexibility while maintaining clean separation of concerns and ensuring backward compatibility across all existing themes and plugins.