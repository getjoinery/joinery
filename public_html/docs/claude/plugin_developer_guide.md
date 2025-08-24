# Plugin and Theme Developer Migration Guide

## Overview

This guide outlines the current plugin and theme architecture after implementing the routing refactor and plugin/theme cleanup. The system now has clear separation of concerns between plugins (backend-only) and themes (user-facing routing and presentation).

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

### Route Processing Order

Routes are processed in this order:
1. **Static routes** - Direct file serving with caching
2. **Theme routes** - Theme-specific routing (serve.php in theme directory)
3. **Custom routes** - Complex logic routes (in main serve.php)
4. **Dynamic routes** - Standard view and model routes
5. **Fallback** - 404 handling

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

Only themes that need plugin functionality should include plugin routes. Most themes can be simple:

```
/theme/my-theme/
├── serve.php                   # Theme routing (optional)
├── views/                      # Theme templates
│   ├── index.php
│   └── page.php
├── assets/                     # Theme assets
│   ├── css/
│   ├── js/
│   └── images/
└── includes/                   # Theme helpers
    └── theme-functions.php
```

### Theme Routing (serve.php)

Themes can define their own routes in RouteHelper format:

```php
// theme/my-theme/serve.php
$routes = [
    'dynamic' => [
        // Simple view routes
        '/my-page' => ['view' => 'views/my_page'],
        
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

### Plugin Integration in Themes

Themes integrate with plugin backend services through data models:

```php
// theme/my-theme/views/items.php
<?php
require_once('plugins/items/data/items_class.php');

// Use plugin data models
$items = new MultiItem(['itm_active' => 1], ['itm_name' => 'ASC']);
$items->load();

foreach ($items as $item) {
    echo '<h3>' . $item->get('itm_name') . '</h3>';
    echo '<p>' . $item->get('itm_description') . '</p>';
}
?>
```

### Asset Management

Theme assets are served through the theme asset route:
`/theme/{theme}/assets/*`

```php
// In theme templates
<link rel="stylesheet" href="/theme/<?= $template_directory ?>/assets/css/style.css">
<script src="/theme/<?= $template_directory ?>/assets/js/app.js"></script>
<img src="/theme/<?= $template_directory ?>/assets/images/logo.png" alt="Logo">
```

## Migration from Old Architecture

### For Existing Plugins

1. **Remove user-facing routes** from plugin serve.php files
2. **Keep admin interfaces** and backend functionality
3. **Ensure plugin.json exists** with proper versioning
4. **Convert migrations** to new format if needed
5. **Add uninstall script** for clean removal

### For Themes Using Plugin Features

1. **Move plugin routes to theme serve.php** using RouteHelper format
2. **Update view templates** to use plugin data models directly
3. **Ensure assets are in theme/assets/** not plugin directories
4. **Test plugin admin access** via `/plugins/{plugin}/admin/*`

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

## Key Benefits of Current Architecture

### Clear Separation of Concerns
- **Plugins**: Backend logic, data, admin interfaces
- **Themes**: User interface, routing, presentation

### Better Security
- Plugin code not directly accessible via web URLs
- Admin interfaces protected by plugin admin discovery route
- Clear separation between public and admin functionality

### Improved Maintainability
- Plugin updates don't affect user-facing routes
- Theme changes don't break backend functionality
- Easier testing and debugging

### Performance Benefits
- Static asset caching through RouteHelper
- Reduced routing complexity
- Plugin code only loaded when needed

## Development Workflow

### Creating a New Plugin

1. Create plugin directory and plugin.json
2. Develop data models and business logic
3. Create admin interface if needed
4. Add migrations for database changes
5. Test admin functionality via `/plugins/{plugin}/admin/*`
6. No user-facing routes - these go in themes

### Creating a New Theme

1. Create theme directory structure
2. Add serve.php only if custom routing needed
3. Create view templates using plugin data models
4. Add theme assets (CSS, JS, images)
5. Test integration with existing plugins

### Integrating Plugin and Theme

1. Plugin provides backend services and data models
2. Theme creates user-facing routes that use plugin models
3. Theme templates call plugin helper classes
4. Plugin admin remains separate from theme routing

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
- Ensure plugin data class is properly included
- Verify plugin is installed and tables exist
- Check data model usage syntax

**Assets not loading:**
- Verify asset paths use correct theme directory
- Check file exists in `theme/{theme}/assets/`
- Ensure web server can serve static files

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

### Theme Integration

**Sassa Theme (Plugin-enabled)**
- Includes ControlD routes: `/profile/*`, `/pricing`
- Includes Items routes: `/items`, `/item/{slug}`
- File: `/theme/sassa/serve.php`

**Other Themes (Plugin-free)**
- Falcon, Tailwind, Default, etc.
- No plugin routes - pure theme functionality
- Files: `/theme/{name}/serve.php` (minimal or empty)

This architecture provides clean separation while maintaining full functionality and backward compatibility.