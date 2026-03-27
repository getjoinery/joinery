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

> For complete routing documentation (adding pages, route options, common patterns), see **[Routing](routing.md)**.
> This section covers how routing interacts with plugins and themes.

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

### Core File Guarantees

When developing plugins, the following core files are guaranteed to be available without requiring them:

- **PathHelper** - Use for all file operations
- **Globalvars** - Access configuration and settings
- **SessionControl** - Handle session and authentication

#### Example Usage in Plugins

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
require_once(PathHelper::getIncludePath('data/users_class.php'));

// ❌ WRONG - Don't do this
require_once(__DIR__ . '/../../includes/PathHelper.php');
require_once(__DIR__ . '/../../includes/Globalvars.php');
```

#### Why This Matters

1. **Cleaner Code** - No need for complex relative paths
2. **Consistency** - Same pattern everywhere
3. **Performance** - Files only loaded once
4. **Maintainability** - Easier to refactor

### Required Plugin Structure

```
/plugins/my-plugin/
├── plugin.json                 # Plugin metadata
├── data/                       # Data model classes
│   └── my_data_class.php
├── logic/                      # Business logic files using LogicResult pattern
│   └── my_feature_logic.php       # See [Logic Architecture Guide](CLAUDE_logic_architecture.md)
├── views/                      # Plugin view templates (if needed)
│   └── my_view.php
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
        'mdt_created' => ['type' => 'timestamp', 'default' => 'now()']
    ];

    // Define foreign key behavior (optional - defaults to cascade)
    protected static $foreign_key_actions = [
        'mdt_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED]
    ];
}
```

**Deletion Behavior**: For complete documentation on defining foreign key actions, cascading deletes, soft-delete cascading patterns, and undelete strategies, see the [Deletion System Documentation](deletion_system.md).

### Business Logic Files

Plugin logic files follow the same LogicResult pattern as core logic files. For comprehensive documentation on logic file architecture and best practices, see the [Logic Architecture Guide](CLAUDE_logic_architecture.md).

```php
// plugins/my-plugin/logic/my_feature_logic.php
<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

function my_feature_logic($get_vars, $post_vars) {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('plugins/my-plugin/data/my_data_class.php'));

    // Business logic processing
    $data = new MyData($get_vars['id'], TRUE);

    // Use LogicResult for consistent returns
    if ($post_vars['action'] === 'delete') {
        $data->soft_delete();
        return LogicResult::redirect('/plugins/my-plugin/admin/list');
    }

    return LogicResult::render(['data' => $data]);
}
?>
```

Key points for plugin logic files:
- Always use `LogicResult::render()`, `LogicResult::redirect()`, or `LogicResult::error()`
- Follow the naming convention: `[feature]_logic.php` with matching function name
- Include paths are relative to the plugin directory when using `__DIR__`
- Can be called from views, admin pages, or the router

### Admin Interface

Plugin admin pages are accessed via the plugin admin discovery route:
`/plugins/{plugin}/admin/{page}`

```php
// plugins/my-plugin/admin/admin_my_plugin.php
<?php
// Core files are already available - no need to require them
// PathHelper, Globalvars, and SessionControl are pre-loaded

// Use PathHelper for other includes
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

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

### Plugin Installation & Activation Lifecycle

When a plugin is **installed** via the admin Plugins page, the system:
1. Validates plugin structure and dependencies
2. Creates database tables automatically from data class `$field_specifications` (via `DatabaseUpdater::runPluginTablesOnly()`)
3. Runs any `.sql` migration files found in `plugins/{name}/migrations/` (via `PluginManager::runPendingMigrations()`)
4. Records the plugin in `plg_plugins` with status `inactive`

When a plugin is **activated**, it sets `plg_active = 1`. No additional table or migration processing occurs.

**Important:** The core `update_database.php` script explicitly **excludes plugins** (`include_plugins => false`). Plugin tables are only created during the install step above. If you need to add columns to an existing plugin table, you must uninstall and reinstall the plugin, or manually run the database updater for the plugin.

### Table Creation (Automatic)

Plugin tables are created automatically from data class `$field_specifications` — you do NOT write CREATE TABLE statements. Simply define your data model classes in `plugins/{name}/data/` and tables will be created when the plugin is installed.

```php
// plugins/my-plugin/data/my_data_class.php
class MyData extends SystemBase {
    public static $prefix = 'mdt';
    public static $tablename = 'mdt_my_data';
    public static $pkey_column = 'mdt_my_data_id';

    public static $field_specifications = array(
        'mdt_my_data_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
        'mdt_name' => array('type'=>'varchar(255)', 'required'=>true),
        'mdt_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'mdt_delete_time' => array('type'=>'timestamp(6)'),
    );
}
```

### Migration System

**Current status:** The plugin migration runner (`PluginManager::runPendingMigrations()`) only processes `.sql` files in `plugins/{name}/migrations/`. The PHP `return []` format with `up`/`down` closures shown below is the intended future format but is **not yet executed automatically** during plugin installation.

**For settings, menu entries, and initial data**, use `.sql` migration files:

```sql
-- plugins/my-plugin/migrations/001_initial_settings.sql
INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name)
SELECT 'my_plugin_enabled', '1', 1, NOW(), NOW(), 'general'
WHERE NOT EXISTS (SELECT 1 FROM stg_settings WHERE stg_name = 'my_plugin_enabled');
```

SQL migration files are:
- Located in `plugins/{name}/migrations/`
- Named with a numeric prefix for ordering (e.g., `001_initial_settings.sql`, `002_add_menu.sql`)
- Executed in filename order during plugin installation
- Tracked in `plm_plugin_migrations` to prevent re-execution
- Run only once — if a migration has already been recorded, it is skipped

**PHP migration format (not yet auto-executed):**

The `return []` format below is used by some existing plugins but requires manual execution or running `update_database` from the admin utilities page. It is the intended future format:

```php
// plugins/my-plugin/migrations/migrations.php
return [
    [
        'id' => '001_initial_setup',
        'version' => '1.0.0',
        'description' => 'Initial plugin setup',
        'up' => function($dbconnector) {
            $dblink = $dbconnector->get_db_link();
            $sql = "INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name)
                    VALUES ('my_plugin_enabled', '1', 1, NOW(), NOW(), 'general')";
            $q = $dblink->prepare($sql);
            $q->execute();
            return true;
        },
        'down' => function($dbconnector) {
            $dblink = $dbconnector->get_db_link();
            $dblink->exec("DELETE FROM stg_settings WHERE stg_name LIKE 'my_plugin_%'");
            return true;
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
            require_once(PathHelper::getThemeFilePath('custom.php', 'views'));
            return true;
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
require_once(PathHelper::getIncludePath('plugins/items/data/items_class.php'));

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
require_once(PathHelper::getIncludePath('plugins/items/data/items_class.php'));
require_once(PathHelper::getIncludePath('plugins/items/includes/ItemsHelper.php'));

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

**Base Assets:**

`PublicPageBase` loads fallback CSS/JS (`base.css`, `assets/css/style.css`, `base.js`) via the `render_base_assets()` method, called from `global_includes_top()`. Themes that provide their own complete CSS (like `PublicPageJoinerySystem`) override `render_base_assets()` with an empty body to prevent style conflicts. See [Theme Integration Instructions](theme_integration_instructions.md) for details.

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
  "formWriterBase": "FormWriterV2Bootstrap",
  "publicPageBase": "PublicPageBase"
}
```

**Tailwind theme.json:**
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
  "cssFramework": "tailwind",
  "formWriterBase": "FormWriterV2Tailwind",
  "publicPageBase": "PublicPageBase",
  "features": {
    "responsive": true,
    "dark_mode": true,
    "plugin_integration": true
  }
}
```

**HTML5 framework-agnostic theme.json:**
```json
{
  "name": "custom-theme",
  "displayName": "Custom HTML5 Theme",
  "version": "1.0.0",
  "description": "Framework-agnostic theme with custom styling",
  "author": "Developer",
  "is_stock": false,
  "requires": {
    "php": ">=7.4",
    "joinery": ">=1.0.0"
  },
  "cssFramework": "html5",
  "formWriterBase": "FormWriterV2HTML5",
  "publicPageBase": "PublicPageBase"
}
```

## ThemeHelper Enhanced Capabilities

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
7. **Test view resolution chain** to ensure fallbacks work correctly

### Working with Forms in Views

#### Getting FormWriter Instances

In views with PublicPage available (most frontend views):
```php
// Preferred method in views - uses PublicPage wrapper
$formwriter = $page->getFormWriter('form1');
```

In different contexts:
```php
// Admin pages - use the page object
$formwriter = $page->getFormWriter('form1'); // $page is AdminPage instance

// Utilities and logic files - direct instantiation
require_once(PathHelper::getThemeFilePath('FormWriter.php', 'includes'));
$formwriter = new FormWriter('form1');
```

The `$page->getFormWriter()` method automatically:
- Detects the correct FormWriter class for the theme's CSS framework
- Loads theme-specific FormWriter implementations if available
- Falls back to system defaults appropriately
- Handles all the complexity internally

#### FormWriter Framework Mapping
- **Bootstrap themes**: Uses `FormWriterV2Bootstrap`
- **Tailwind themes**: Uses `FormWriterV2Tailwind`
- **HTML5 themes**: Uses `FormWriterV2HTML5` (framework-agnostic)
- **Custom themes**: Can extend `FormWriterV2Base` for custom implementations

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
- Business logic: `ControlDHelper` class and logic files

## Hybrid Architecture

### Separation of Concerns
- **Plugins**: Backend logic, data, admin interfaces
- **Themes**: User interface, routing, presentation
- **Hybrid Integration**: Themes can access plugin functionality without coupling

### View Resolution
- **View Resolution Chain**: Automatic fallback from theme → plugin → system views
- **Framework Support**: Multiple CSS frameworks with proper implementations
- **Plugin Integration**: Themes can include plugin routes without breaking separation
- **Override Capability**: Themes can override any plugin view while maintaining fallbacks

### Security Model
- Plugin code not directly accessible via web URLs
- Admin interfaces protected by plugin admin discovery route
- Clear separation between public and admin functionality
- Theme-specific includes isolated from system includes

### Performance
- Static asset caching through RouteHelper
- Reduced routing complexity with priority-based processing
- Plugin code only loaded when needed
- View resolution caching prevents repeated file system checks
- Framework-specific optimizations in theme implementations

## File Loading in Plugins and Themes

**Two methods for including files:**

1. **`PathHelper::getIncludePath()`** - Direct loading, no overrides
   ```php
   require_once(PathHelper::getIncludePath('data/user_class.php'));  // Data models
   require_once(PathHelper::getIncludePath('includes/MyHelper.php')); // System files
   ```

2. **`PathHelper::getThemeFilePath()`** - Theme-aware file resolution with override chain
   ```php
   // Files that can be overridden by themes
   require_once(PathHelper::getThemeFilePath('profile_logic.php', 'logic'));
   require_once(PathHelper::getThemeFilePath('devices.php', 'views/profile'));

   // With explicit plugin context (5th parameter)
   require_once(PathHelper::getThemeFilePath('devices.php', 'views/profile', 'system', null, 'controld'));

   // Parameters: filename, subdirectory, path_format, theme_name, plugin_name
   ```
   **Override chain:** theme → plugin → base

**When to use:**
- `PathHelper::getIncludePath()`: Direct file access for system files, data models, plugin files
- `PathHelper::getIncludePath()`: Direct file access, no theme overrides needed (plugins, data files)
- `PathHelper::getThemeFilePath()`: Files that themes/plugins can override (views, logic, includes)

### File Override System

**Important:** The file override system uses `PathHelper::getThemeFilePath()` which checks:
1. Theme override: `/theme/{theme}/{subdirectory}/{filename}`
2. Plugin version: `/plugins/{plugin}/{subdirectory}/{filename}`
3. Base fallback: `/{subdirectory}/{filename}`

Always use the two-parameter format:
- First parameter: filename only (e.g., 'profile.php')
- Second parameter: subdirectory path (e.g., 'views', 'logic', 'views/profile')

## Development Workflow

### Creating a New Plugin

1. Create plugin directory under `/plugins/{name}/` with `plugin.json`
2. Create data model classes in `plugins/{name}/data/` with `$field_specifications` (tables created automatically on install)
3. Create `.sql` migration files in `plugins/{name}/migrations/` for settings, menu entries, and initial data
4. Create admin interface in `plugins/{name}/admin/` if needed
5. Create `uninstall.php` for clean removal of settings, menu entries, and other non-table data
6. **Install** the plugin via Admin > System > Plugins (creates tables, runs SQL migrations)
7. **Activate** the plugin to make it live
8. Test admin functionality via `/plugins/{plugin}/admin/*`
9. No user-facing routes - these go in themes

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
- Test view resolution chain: theme → plugin → system
- Ensure plugin_specify parameter matches actual plugin directory name

**CSS framework conflicts:**
- Verify theme.json cssFramework matches actual implementation
- Check PublicPage class extends proper base and implements getTableClasses()
- Ensure FormWriter implementation (V2Bootstrap, V2Tailwind, or V2HTML5) matches CSS framework
- Validate CSS classes match framework documentation

**Assets not loading:**
- Verify asset paths use correct theme directory
- Check file exists in `theme/{theme}/assets/`
- Ensure web server can serve static files
- Test ThemeHelper::asset() method for enhanced asset management

**Class not found errors:**
- Distinguish between theme includes (direct) vs views (resolution chain)
- Use proper require_once(PathHelper::getIncludePath()) for includes
- Check abstract method implementation in theme-specific classes
- Verify class file naming conventions match theme requirements

## Cookie Consent Integration

If your plugin adds analytics or marketing scripts to public pages, you should wrap them for GDPR/CCPA consent compliance.

**Using ConsentHelper to wrap scripts:**
```php
require_once(PathHelper::getIncludePath('includes/ConsentHelper.php'));
$consent = ConsentHelper::get_instance();
echo $consent->wrapTrackingCode('<script>...your tracking code...</script>', 'analytics');
```

**Or manually add the consent attribute to script tags:**
```html
<script type="text/plain" data-joinery-consent="analytics">
  // This script only runs after user consents to analytics
</script>
```

**Consent categories:**
- `analytics` - For analytics and tracking scripts (e.g., Google Analytics)
- `marketing` - For advertising and remarketing scripts (e.g., Facebook Pixel)

When cookie consent is enabled, scripts marked with `data-joinery-consent` remain inactive until the user grants consent for that category.

## CSS Framework Integration

### Supported CSS Frameworks

The system supports multiple CSS frameworks through theme-specific implementations:

**Bootstrap Themes:**
- CSS Framework: `bootstrap`
- FormWriter Base: `FormWriterV2Bootstrap`
- Table Classes: `table`, `table-striped`, `table-hover`
- Container Classes: `container`, `container-fluid`

**Tailwind CSS Themes:**
- CSS Framework: `tailwind`
- FormWriter Base: `FormWriterV2Tailwind`
- Utility-first approach with custom classes
- Table Classes: Custom Tailwind utility classes
- Container Classes: `container`, `mx-auto`

**HTML5 Themes (Framework-Agnostic):**
- CSS Framework: `html5` or `custom`
- FormWriter Base: `FormWriterV2HTML5`
- Pure semantic HTML5 markup
- No framework-specific classes
- Themes can apply any CSS styling

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
7. **Base class render methods** - Call `$this->render_notification_icon($menu_data)` in `top_right_menu()` for notifications; override only if theme needs different markup

### For System Integration
1. **Clear separation** - Plugins (backend) vs Themes (frontend)
2. **Flexible routing** - Theme serve.php can include plugin routes
3. **View fallbacks** - Automatic resolution chain prevents 404s
4. **Framework support** - Multiple CSS frameworks supported cleanly
5. **Maintainability** - Updates to plugins don't break theme functionality

This hybrid architecture provides maximum flexibility while maintaining clean separation of concerns and ensuring backward compatibility across all existing themes and plugins.

## Plugin Theme System

### Overview

The plugin theme system allows plugins to act as complete theme providers, replacing the entire user interface while maintaining all plugin functionality. This enables white-label solutions, complete UI replacements, and branded experiences.

### How the System Works

1. **PathHelper** intercepts theme file requests and redirects to plugin directory for PHP classes
2. **RouteHelper** sets template directory to plugin path for view loading
3. **ThemeHelper** serves assets from plugin directory instead of theme directory
4. **Admin Settings** provides UI for selecting which plugin provides the theme

### Three Types of Plugins

#### 1. Feature Plugins (Standard)
**Purpose**: Add specific functionality without affecting the UI
**Examples**: Bookings, Items, OAuth providers, Payment processors
**Characteristics**:
- Work within existing theme framework
- Add new routes under `/[plugin-name]/*`
- Can provide admin interfaces
- Cannot override system views or routes

**Directory Structure**:
```
/plugins/bookings/
├── plugin.json
├── serve.php
├── admin/
│   └── manage_bookings.php
├── views/
│   └── booking_list.php
└── assets/
    └── js/bookings.js
```

#### 2. Theme Provider Plugins
**Purpose**: Complete UI replacement when selected as active theme
**Examples**: ControlD, White-label solutions, Custom branded interfaces

**Required Files**:
```
/plugins/controld/
├── plugin.json (with "provides_theme": true)
├── serve.php
├── includes/
│   ├── PublicPage.php (required - base page class)
│   └── FormWriter.php (required - form generation)
├── views/
│   ├── index.php (homepage view)
│   ├── profile.php (user profile)
│   └── [other system view overrides]
└── assets/
    ├── css/style.css
    ├── js/main.js
    └── img/logo.png
```

**How Theme Provider Mode Works**:
1. Admin selects "plugin" as the theme
2. Admin selects specific plugin (e.g., "controld") as the theme provider
3. System modifications activate:
   - PathHelper loads PHP classes from `/plugins/controld/includes/`
   - RouteHelper loads views from `/plugins/controld/views/`
   - ThemeHelper loads assets from `/plugins/controld/assets/`
4. Plugin provides complete UI while system handles core functionality

#### 3. Hybrid Plugins
**Purpose**: Dual-mode plugins that can work as features OR complete themes
**Examples**: Complex applications with optional standalone mode

**Behavior Modes**:
- **Feature Mode**: When regular theme active, provides features within that theme
- **Theme Mode**: When selected as theme provider, replaces entire UI
- Same codebase, different activation modes

## System Configuration Documentation

### New Database Settings

**`active_theme_plugin`**
- **Type**: String (plugin directory name)
- **Default**: Empty string
- **Purpose**: Specifies which plugin provides the complete UI when plugin theme is active
- **Valid Values**: Must match an installed plugin directory name
- **Dependencies**: Only used when `theme_template = 'plugin'`
- **Example**: `'controld'` to use ControlD plugin as theme

### Modified Settings

**`theme_template`**
- **New Option**: `'plugin'` - Delegates all theme functionality to a plugin
- **Existing Options**: `'falcon'`, `'sassa'`, `'tailwind'`, etc.

## Admin Interface Documentation

### Settings Page Updates (`/adm/admin_settings.php`)

**Theme Selection Enhancement**:
When "Plugin-Provided Theme" is selected from the theme dropdown:
1. A new dropdown appears labeled "Active Theme Plugin"
2. Dropdown populates with all installed plugins
3. Plugins with `"provides_theme": true` are prioritized
4. Help text explains the plugin must provide theme infrastructure

**JavaScript Behavior**:
- Plugin selector is hidden when regular themes are selected
- Plugin selector shows immediately when "plugin" theme is selected
- Settings save normally through existing form processing

## Technical Implementation Notes

### File Resolution Order

When plugin theme is active, the system checks for files in this order:

**For PHP Classes** (via PathHelper):
1. `/plugins/{active_plugin}/includes/{file}`
2. `/theme/plugin/includes/{file}` (fallback)
3. `/includes/{file}` (system fallback)

**For Views** (via RouteHelper/ThemeHelper):
1. `/plugins/{active_plugin}/views/{file}`
2. `/views/{file}` (system fallback)

**For Assets** (via ThemeHelper):
1. `/plugins/{active_plugin}/assets/{file}`
2. `/theme/plugin/assets/{file}` (shouldn't exist)
3. Current route's plugin assets (existing behavior)

### Performance Considerations

- **Additional Database Queries**: One extra query to get `active_theme_plugin` setting
- **File Existence Checks**: Additional `is_dir()` and `file_exists()` checks
- **Caching Opportunity**: Could cache plugin theme selection in session
- **Impact**: Minimal - only adds conditional checks when plugin theme active

### Security Considerations

- **Plugin Validation**: System should verify plugin exists before activation
- **Fallback Strategy**: Falls back to safe defaults if plugin missing
- **No New Attack Vectors**: Uses existing file inclusion mechanisms
- **Admin Only**: Theme selection requires admin permissions