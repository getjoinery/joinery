# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## System Overview

This is a custom PHP membership and event management platform with a modular MVC-like architecture. The system uses PostgreSQL and follows a front-controller pattern with theme-based UI customization and plugin extensibility.

**Key Entry Point:** `serve.php` - All requests are routed through this front controller using RouteHelper.php

## CRITICAL: File Include Rules

**NEVER use `$_SERVER['DOCUMENT_ROOT']` for include paths!**

### Include Path Rules:
- **Files in `/includes/`**: Use `PathHelper::requireOnce('includes/filename.php')` (or direct `require_once('filename.php')` only if always called from within `/includes/`)
- **PathHelper itself**: Use `require_once(__DIR__ . '/relative/path/to/PathHelper.php')` - adjust the relative path based on where you're including from
- **All other files**: Use `PathHelper::requireOnce('path/to/file.php')`

```php
// ✅ CORRECT
PathHelper::requireOnce('includes/LibraryFunctions.php');
require_once(__DIR__ . '/../includes/PathHelper.php');  // from /data/ directory
require_once(__DIR__ . '/PathHelper.php');              // from /includes/ directory

// ❌ WRONG - NEVER do this
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/filename.php');
```

### Variable Scope with PathHelper
**CRITICAL:** `PathHelper::requireOnce()` includes files in method scope, isolating variables. When you need global scope access to variables defined in the included file (like `$migrations`), use:

```php
// ✅ For shared variables - maintains global scope
require_once(PathHelper::getIncludePath('migrations/migrations.php'));

// ❌ Variables not accessible - method scope isolation  
PathHelper::requireOnce('migrations/migrations.php');
```

## Custom Slash Commands

**Location:** `/home/user1/.claude/commands/`

When the user types a slash command (e.g., `/implement`, `/refactor`), always check if a custom command exists before proceeding:

1. **Check for custom command:** Look for `/home/user1/.claude/commands/{command}.md`
2. **If found:** Read the command file and follow its instructions exactly
3. **If not found:** Proceed with built-in slash command or ask for clarification

Custom commands override built-in behavior and provide specific workflows tailored to this project.

## Architecture Patterns

### Directory Structure & Responsibilities
- `/data/` - Database model classes using Active Record pattern (`[table]_class.php`)
- `/logic/` - Business logic layer (`[page]_logic.php`) 
- `/views/` - Base presentation templates
- `/adm/` - Complete admin interface (currently uses Falcon theme)
- `/includes/` - Core system classes (Globalvars, DbConnector, FormWriterMaster, etc.)
- `/theme/` - Multi-theme system (falcon=Bootstrap, tailwind=legacy Tailwind CSS option)
- `/plugins/` - Self-contained modules with own MVC structure
- `/ajax/` - AJAX endpoints and webhook handlers
- `/api/` - REST API with key-based authentication
- `/utils/` - Maintenance scripts and development tools
- `/migrations/` - Version-controlled database schema changes

### Theme Override System
The system checks for theme-specific files first, then falls back to base files:
1. `/theme/[theme]/views/page.php` (theme-specific)
2. `/views/page.php` (base fallback)

Theme is selected via `theme_template` setting and can be changed at runtime.

### Theme Routing System
Each theme can define its own routes via `/theme/[name]/serve.php` using RouteHelper format:

```php
<?php
// theme/mytheme/serve.php - RouteHelper format routes for mytheme theme
$routes = [
    'dynamic' => [
        '/special-page' => ['view' => 'views/special-page'],
        '/event/{slug}' => ['model' => 'Event', 'model_file' => 'data/events_class'],
    ],
    'custom' => [
        '/complex-feature' => function($params, $settings, $session, $template_directory) {
            return ThemeHelper::includeThemeFile('views/complex-feature.php');
        },
    ],
];
```

Theme routes are processed by RouteHelper using the same pattern matching as main serve.php routes.

### Routing System (serve.php + RouteHelper.php)

**Route Pattern Rules:**
- **Route patterns** in serve.php always start with `/` (e.g., `/tests/*`, `/page/{slug}`)
- **File paths** in route configurations are always relative (e.g., `'view' => 'tests/{path}'`)
- **Request paths** are automatically normalized by RouteHelper (leading slashes added if missing)

**Route Processing Order:**
1. Static routes (main serve.php)
2. Theme serve.php routes (if exists)
3. Custom routes (main serve.php)
4. Dynamic routes (main serve.php)
5. View directory fallback
6. 404 page

### Plugin Architecture (Backend Only)
Plugins in `/plugins/[name]/` provide backend functionality only:
- Add admin interface pages (served via `/plugins/{plugin}/admin/*` automatic discovery)
- Include database migrations and data models
- Hook into system events
- **NO routing control** - all user-facing routes are controlled by themes

## Database & Configuration

**Database:** PostgreSQL with PDO prepared statements
**Configuration:** 
- File-based config for core settings: `Globalvars_site.php` (available at `/var/www/html/joinerytest/config/`)
- Additional, database-stored settings in `stg_settings` table, accessed with settings singleton
- `$settings = Globalvars::get_instance()` - Get settings singleton
- `$settings->get_setting('setting_name')` - Get configuration value

### Important Settings
- **composerAutoLoad**: Path to vendor directory (e.g., `/home/user1/vendor/`)
  - This setting contains the path to the vendor directory, NOT the full path to autoload.php
  - Code should append `autoload.php` to this path when using it

### Data Classes Pattern
```php
class TableName extends SystemBase {
    public static $prefix = 'tbl';
    public static $tablename = 'tbl_table_name';
    public static $pkey_column = 'tbl_id';
    public static $fields = array();
    public static $field_specifications = array();
    public static $timestamp_fields = array();
    public static $required_fields = array();
    public static $field_constraints = array();
    public static $initial_default_values = array();
    public static $zero_variables = array();
    public static $permanent_delete_actions = array();
    public static $json_vars = array('key');
    public static $url_namespace; // For URL generation
    
    // Constructor
    function __construct($key, $and_load = FALSE);
    
    // Static Methods
    static function GetByColumn($column, $value);
    static function get_by_link($link, $search_deleted = false);
    static function CheckForDuplicate($obj_to_check, $fields = NULL, $search_deleted = false);
    static function check_if_exists($key);
    
    // Data Access Methods
    function get($key);
    function set($key, $value, $check_existance = TRUE);
    function export_as_array();
    
    // Loading & Saving Methods
    function load();
    function prepare(); // Validation before save
    function save($debug = false);
    
    // Deletion Methods
    function soft_delete(); // Sets delete_time field
    function undelete(); // Clears delete_time field
    function permanent_delete($debug = false); // Permanently removes from database
    // Note: There is NO delete() method - use soft_delete() or permanent_delete()
    
    // Utility Methods
    function check_for_duplicate($fields = NULL, $search_deleted = false);
    function check_unique_constraints(); // Check field_specifications unique constraints
    
    // URL/Link Methods
    function create_url($input_url); // Create URL-safe slug
    function get_url($format = 'short'); // Get record's URL
    
    // Timezone Methods
    function get_timezone_corrected_time($key, $session, $format = 'Y-m-d h:i A T');
    function get_timezone_corrected_datetime($key, $session, $format = 'Y-m-d h:i A T');
    function get_timezone_agnostic_date($key, $session, $format = 'Y-m-d h:i A T');
    
    // Authentication & Security
    function authenticate_read($data);
    function authenticate_write($data);
    function is_owner($session);
    
    // JSON Export
    function get_json(); // Returns array suitable for json_encode()
	
	//There are more methods available in the SystemBase class that are not listed here
}
```

### DbConnector Usage and Database Calls

**CRITICAL RULE:** **NEVER use DbConnector directly if there is a model class available!**
- ✅ Use `User`, `MultiUser`, `Product`, `MultiProduct`, etc. when they exist
- ✅ Use model methods like `load()`, `save()`, `get()`, `set()`, etc.
- ❌ Only use DbConnector for tables that don't have model classes or complex queries

#### Essential Database Patterns:

**USE MODELS (Preferred):**
```php
// Loading and modifying records
$user = new User($user_id, TRUE); // Load user by ID
$email = $user->get('usr_email');
$user->set('usr_name', 'New Name');
$user->prepare();
$user->save();

// Finding multiple records
$users = new MultiUser(['usr_active' => 1], ['usr_id' => 'DESC']);
$users->load();

// Creating new records
$product = new Product(NULL);
$product->set('pro_name', 'New Product');
$product->save();
```

**USE DbConnector (ONLY when no model exists):**
```php
$dbconnector = DbConnector::get_instance();
$dblink = $dbconnector->get_db_link();
$sql = "SELECT COUNT(*) FROM some_table WHERE complex_condition = ?";
$q = $dblink->prepare($sql);
$q->execute([$value]);
$result = $q->fetch(PDO::FETCH_ASSOC);
```

**CRITICAL RULES:**
1. **Always use prepared statements** - Never concatenate user input directly into SQL
2. **Always use the PDO connection** from `$dbconnector->get_db_link()`
3. **Use proper PDO fetch modes** - `PDO::FETCH_ASSOC` for arrays, `PDO::FETCH_OBJ` for objects

## Model Querying Patterns and Best Practices

### Single Object Access

**CRITICAL**: All SystemBase-derived classes require at least one parameter in their constructor! Never call `new ClassName()` without parameters - this is the most common model usage error.

```php
// ✅ CORRECT patterns
$product = new Product($id, TRUE);    // Creates and loads data
$product = new Product(NULL);         // Creates new object for insertion

// ❌ WRONG - will cause "Too few arguments" error
$product = new Product();
```

### Multi-Object Collections (SystemMultiBase)

**Constructor Pattern:**
```php
new MultiClassName($search_criteria, $order_by, $limit, $offset, $operation, $write_lock)
```

**Selected Method Reference:**
```php
class MultiTableName extends SystemMultiBase {
    public static $table_name = 'tbl_table_name';
    public static $table_primary_key = 'tbl_id';
    protected static $default_options = array();
    
    // Constructor
    function __construct($options = array(), $order_by = array(), 
                        $limit = NULL, $offset = NULL, $operation = 'AND', $write_lock = FALSE);
    
    // Loading Methods
    function load($debug = false); // Load all matching objects
    function count_all(); // Get total count without loading (implemented in concrete classes)
    
    // Collection Access Methods
    function get($index); // Get object at specific index (0-based)
    function get_by_key($key); // Get object by primary key value
    function is_valid($location); // Check if index exists
    function contains($item); // Check if collection contains object
    function contains_key($key); // Check if collection contains key
    
    // Collection Manipulation Methods
    function add($value); // Add object to collection
    function remove($location); // Remove object at index

    
    // Iterator Implementation (IteratorAggregate, Countable)
    function count(); // Get number of loaded objects
    function getIterator(); // Returns ArrayIterator for foreach loops
    function incremental_iterator($incremental_limit = 200); // For large datasets
    
    
    // Authentication
    function authenticate_read($data); // Calls authenticate_read on all children
    
    // Internal Methods (used by concrete implementations)
    protected function _get_resultsv2($table, $filters = [], $sorts = [], 
                                     $only_count = false, $debug = false);
	// More methods are available in the SystemBase class
}
```

**Examples:**
```php
// Search criteria patterns
$criteria = array('pro_is_active' => 1);
$criteria = array('prv_status' => '> 0');
$criteria = array('pro_evt_event_id' => null);

// Order by patterns
$order_by = array('pro_name' => 'ASC');
$order_by = array('pro_name' => 'ASC', 'pro_created' => 'DESC');

// Complete example
$products = new MultiProduct(
    array('pro_is_active' => 1),
    array('pro_name' => 'ASC'),
    10, 0
);
if ($products->count_all() > 0) {
    $products->load();
    foreach ($products as $product) {
        echo $product->get('pro_name');
    }
}
```

**Method Verification Best Practice:** Always check the actual class definition in `/data/[class]_class.php` to confirm method names, signatures, and parameters before using any method.

## Admin Page Development

For complete guidance on creating admin interface pages, including required setup, table patterns, form handling, and best practices, see:

**📖 [Admin Pages Documentation](/docs/claude/CLAUDE_admin_pages.md)**


## Development Workflow

### Adding New Features
1. Create data class: `/data/[feature]_class.php` (defines database schema automatically)
2. Add business logic: `/logic/[feature]_logic.php`
3. Create view template: `/views/[feature].php`
4. Add admin interface: `/adm/admin_[feature].php`
5. Add route to `serve.php` if needed
6. Add data migrations in `/migrations/` if needed (for settings, data updates only)

**Helper Class Integration:** Use RouteHelper for custom routing, ThemeHelper for asset management, and PathHelper for file operations instead of manual path handling.

### Specifications Management

**Directory Structure:**
- **`/specs/`** - Active specifications awaiting implementation
- **`/specs/implemented/`** - Completed specifications

**Workflow:** Place new specs in `/specs/`, follow during development, move to `/specs/implemented/` when complete. This structure must be used unless explicitly specified otherwise.

### Database Schema Management

**IMPORTANT:** Database tables, columns, and constraints are managed automatically by the `update_database` system based on data class specifications. **DO NOT add columns or table structure changes via migrations.**

#### Automatic Database Updates
The system automatically:
- Creates tables based on data class `$tablename` and `$field_specifications`
- Adds missing columns from `$field_specifications` 
- Updates column types and constraints
- Creates indexes and foreign keys as needed

Simply define fields in your data class and the database will be updated automatically:

```php
// In data/example_class.php
public static $field_specifications = array(
    'new_column' => array('type'=>'varchar(255)', 'is_nullable'=>false),
);
```

#### When to Use Migrations
Migrations in `/migrations/migrations.php` are ONLY for:
- **Data migrations** (updating existing data)
- **Settings insertions** (adding configuration values)  
- **Non-structural changes** (stored procedures, triggers, etc.)

```php
// Data migration example - ONLY for data changes, not structure
$migration['database_version'] = '0.XX';
$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'setting_name'";
$migration['migration_sql'] = 'INSERT INTO stg_settings (stg_name, stg_value) VALUES (\'setting_name\', \'value\');';
$migration['migration_file'] = NULL;
$migrations[] = $migration;

// File-based migration for complex data updates
$migration = array(); // CRITICAL: Clear previous migration data
$migration['database_version'] = '0.XX';
$migration['test'] = "SELECT count(1) as count FROM table WHERE condition";
$migration['migration_file'] = 'migration_filename.php';
$migration['migration_sql'] = NULL;
$migrations[] = $migration;
```

**Critical Rules:**
1. **NEVER add table/column/constraint changes to migrations** - use data class specifications
2. **ALWAYS clear the `$migration` array between migrations**
3. **File-based migrations MUST define a function matching the filename**
4. **Set unused fields to NULL explicitly**
5. **Migrations are ONLY for data changes, not schema changes**

## Development Environment

### Local Development Setup
**Available Locally:**
- PHP Runtime for syntax checking (`php -l filename.php`)
- File system access and basic bash commands
- PostgreSQL database access via psql
- Web server (Apache) and configuration access

**CRITICAL REQUIREMENT:** Always check PHP files for syntax errors using `php -l filename.php` before declaring any PHP development task complete.

### Common Development Commands
```bash
# PHP Syntax Validation
php -l filename.php

# Check error logs
tail /var/www/html/joinerytest/logs/error.log

# Database access (get credentials from /var/www/html/joinerytest/config/Globalvars_site.php)
psql -U postgres -d joinerytest

# Apache service management
sudo systemctl status apache2
sudo systemctl restart apache2
```

### Test Server Monitoring
**Usage Pattern:**
1. Make code changes
2. Run syntax validation (`php -l filename.php`)
3. Test changes locally (web server is available)
4. Check error logs for any issues

**Quick Log Check:** `tail /var/www/html/joinerytest/logs/error.log` - Shows recent Apache error.log entries

## Plugin Development

### Plugin Directory Structure (Backend Only)
```
/plugins/[name]/
├── admin/        # Admin interface files ✅
├── data/         # Data model classes ✅
├── includes/     # Helper classes ✅
├── migrations/   # Database changes ✅
├── plugin.json   # Plugin metadata ✅
└── ❌ NO: serve.php, views/, assets/
```

### Plugin Admin Access
- **URL Pattern:** `/plugins/{plugin}/admin/{page}`
- **Automatic discovery** - no need to register pages
- **Frontend control:** Only themes control user-facing routes

## Development Restrictions

**IMPORTANT: Do not make changes to files in the following directories without explicit instructions:**

- `/includes/` - Core system classes and libraries
- `/migrations/` - Database schema changes and version control
- `/api/` - REST API endpoints and authentication
- `/data/` - Existing data model classes (new classes are allowed)

## Best Practices

1. **Syntax Validation**: ALWAYS run `php -l filename.php` on all PHP files before completing any task
2. **Method Verification**: NEVER assume available functions - always check class definitions first
3. **Security**: Always validate and sanitize user input
4. **FormWriter**: Always use FormWriter class for forms
5. **Follow Existing Patterns**: Look at similar files in the codebase before creating new ones
6. **Respect Restrictions**: Only modify restricted directories with explicit user permission

## Security Notes

- All database queries use PDO prepared statements
- Session-based authentication with role management
- Input validation on all forms (client + server side)
- Check permissions early in admin scripts

## Key Integration Points

**Payment Processing:** StripeHelper, PaypalHelper classes
**Email:** SystemMailer with template support  
**External APIs:** Webhooks in `/ajax/` for Stripe
**File Management:** Secure upload handling in `/includes/UploadHandler.php`

### Helper Classes

**RouteHelper** - Manages URL routing and file serving for the front controller system
- `processRoutes($routes, $request_path)` - Main route processing with pattern matching
- `handleStaticRoute($route, $params, $template_directory)` - Serve static assets with caching
- `handleDynamicRoute($route, $params, $template_directory)` - Handle view and model-based routes
- `extractRouteParams($pattern, $path)` - Extract parameters from URL patterns
- `serveStaticFile($file_path, $cache_seconds)` - Serve files with HTTP caching headers

**ThemeHelper** - Manages theme metadata and provides theme-specific functionality
- `getInstance($themeName)` - Get singleton instance for theme operations
- `includeThemeFile($path, $themeName, $variables)` - Include theme files with variable injection
- `asset($path, $themeName)` - Generate theme asset URLs with cache busting
- `config($key, $default, $themeName)` - Get theme configuration values
- `switchTheme($themeName)` - Change active theme system-wide

**PluginHelper** - Manages plugin metadata and provides plugin helper functions

**PathHelper** - Provides standardized path resolution and file operations across the system

**ComponentBase** - Base class providing common functionality for PluginHelper and ThemeHelper