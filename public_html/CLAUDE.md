# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## System Overview

This is a custom PHP membership and event management platform with a modular MVC-like architecture. The system uses PostgreSQL and follows a front-controller pattern with theme-based UI customization and plugin extensibility.

**Key Entry Point:** `serve.php` - All requests are routed through this front controller using RouteHelper.php

## Database Access Rules

**CRITICAL:** While database access is auto-approved, you MUST follow these rules:

1. **READ operations** (SELECT, SHOW, DESCRIBE, \d, \dt, etc.) - Execute without asking
2. **WRITE operations** (INSERT, UPDATE, DELETE, DROP, ALTER, CREATE, TRUNCATE) - ALWAYS ask for explicit user confirmation before executing

## Version Control Rules

**CRITICAL:** NEVER commit to git unless explicitly directed to by the user. File changes are allowed, but git commits require explicit user permission.

## CRITICAL: File Include Rules

**⚠️ NEVER REQUIRE PathHelper, Globalvars, or SessionControl - THEY ARE ALWAYS AVAILABLE! ⚠️**

**NEVER use `$_SERVER['DOCUMENT_ROOT']` for include paths!**

### Core File Guarantees
**These files are ALWAYS pre-loaded - NEVER use require/require_once for them:**
- **PathHelper** - Always available in ALL PHP files (loaded by serve.php)
- **Globalvars** - Always available in ALL PHP files (loaded by PathHelper)
- **SessionControl** - Always available in ALL PHP files
- **ThemeHelper** - Always available in ALL PHP files (loaded by PathHelper)
- **PluginHelper** - Always available in ALL PHP files (loaded by PathHelper)
- **DbConnector** - Always available in ALL PHP files (loaded by Globalvars)

```php
// ❌ WRONG - NEVER DO THIS
require_once('PathHelper.php');
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(__DIR__ . '/../includes/Globalvars.php');
require_once(__DIR__ . '/../includes/ThemeHelper.php');
require_once(__DIR__ . '/../includes/PluginHelper.php');
require_once(__DIR__ . '/../includes/DbConnector.php');
require_once(PathHelper::getIncludePath('includes/PathHelper.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));

// ✅ CORRECT - Just use them directly
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
$settings = Globalvars::get_instance();
$session = SessionControl::get_instance();
```

### Include Path Rules:
- **Core files (PathHelper, Globalvars, SessionControl, ThemeHelper, PluginHelper, DbConnector)**: Already loaded - NEVER require them
- **All other files**: Use `require_once(PathHelper::getIncludePath('path/to/file.php'))`
- **Theme-overridable files**: Use `require_once(PathHelper::getThemeFilePath('filename.php', 'subdirectory'))`
- **EXCEPTION**: In bootstrap tests or deployment scripts running outside the normal application flow, you may need to manually require core files using `require_once(PathHelper::getIncludePath('includes/Globalvars.php'))` after loading PathHelper

```php
// ✅ CORRECT - Standard file inclusion
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('plugins/bookings/data/bookings_class.php'));

// ✅ CORRECT - Theme-overridable files
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('profile_logic.php', 'logic'));

// ❌ WRONG - NEVER do this
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/filename.php');
```

### Variable Scope Note
When including files that define variables you need to access (like `$migrations`), the standard `require_once(PathHelper::getIncludePath())` pattern works perfectly as it maintains proper scope:

```php
// ✅ Standard pattern - variables are accessible
require_once(PathHelper::getIncludePath('migrations/migrations.php'));
// Now $migrations array is available
```

## Architecture Patterns

### Directory Structure & Responsibilities
- `/data/` - Database model classes using Active Record pattern (`[table]_class.php`)
- `/logic/` - Business logic layer using LogicResult pattern (`[page]_logic.php`) [📖 See Logic Architecture Guide](docs/logic_architecture.md)
- `/views/` - Base presentation templates
- `/adm/` - Complete admin interface (currently uses Falcon theme)
- `/includes/` - Core system classes (Globalvars, DbConnector, FormWriterHTML5, LogicResult, etc.)
- `/theme/` - Multi-theme system (falcon=Bootstrap, tailwind=legacy Tailwind CSS option) [symlinked]
- `/plugins/` - Self-contained modules with own MVC structure [symlinked]
- `/ajax/` - AJAX endpoints and webhook handlers
- `/api/` - REST API with key-based authentication
- `/utils/` - Maintenance scripts and development tools
- `/migrations/` - Version-controlled database schema changes
- `/specs/` - Feature specifications (active and implemented)
- `/docs/` - Documentation and Claude-specific guidance
- `/tests/` - Test suites (email, functional, integration, models)
- `/home/user1/joinery/joinery/maintenance_scripts/` - Development and deployment scripts [separate git repo]

### Routing & Theme System

**CRITICAL: NEVER use .php extension in URLs or links! All requests go through the routing system.**
- ❌ WRONG: `<a href="/admin/admin_user_edit.php?id=1">` - Query parameters will be lost!
- ✅ CORRECT: `<a href="/admin/admin_user_edit?id=1">` - Routes properly with parameters

**Main Entry:** `serve.php` processes all requests via RouteHelper
**Theme Override:** Checks `/theme/[theme]/views/` first, then `/views/` fallback
**Plugin Routes:** Admin pages auto-discovered at `/plugins/{plugin}/admin/*`

For complete details on themes, plugins, and routing: **📖 [Plugin Developer Guide](/docs/plugin_developer_guide.md)**

## Database & Configuration

**Database:** PostgreSQL with PDO prepared statements
**Configuration:** 
- File-based config for core settings: `Globalvars_site.php` (available at `/var/www/html/joinerytest/config/`)
- Additional, database-stored settings in `stg_settings` table, accessed with settings singleton
- `$settings = Globalvars::get_instance()` - Get settings singleton
- `$settings->get_setting('setting_name')` - Get configuration value
- **Note:** There is no `set_setting()` method - see `/adm/admin_settings.php` for how to change settings

### Important Settings
- **composerAutoLoad**: Path to vendor directory (e.g., `/home/user1/vendor/`)
  - This setting contains the path to the vendor directory, NOT the full path to autoload.php
  - Code should append `autoload.php` to this path when using it

### Data Model Classes (Single and Multi Patterns)

#### Single Object Pattern (extends SystemBase)
```php
class TableName extends SystemBase {
    public static $prefix = 'tbl';
    public static $tablename = 'tbl_table_name';
    public static $pkey_column = 'tbl_id';
    public static $fields = array();
    public static $field_specifications = array();
    public static $timestamp_fields = array();
    public static $required_fields = array();
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

#### Multi-Object Collection Pattern (extends SystemMultiBase)
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
	// More methods are available in the SystemMultiBase class
}
```

**⚠️ CRITICAL: Multi-Object Filter Keys**

Multi classes use **custom option keys** that may differ from database column names. Always check the `getMultiResults()` method in the specific Multi class to see which option keys it accepts.

**Common mistake:**
```php
// ❌ WRONG - Using database column names directly
$groups = new MultiGroup(['grp_name' => 'Basic Plan', 'grp_category' => 'subscription_tier']);

// ✅ CORRECT - Using the option keys defined in MultiGroup::getMultiResults()
$groups = new MultiGroup(['group_name' => 'Basic Plan', 'category' => 'subscription_tier']);
```

**Always verify option keys** in `/data/[table]_class.php` before using Multi classes!

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

### Multi-Object Filter Patterns

**Filter Types in `getMultiResults()`:**
```php
// 1. Parameterized (safe from SQL injection)
$filters['evr_evt_event_id'] = [$event_id, PDO::PARAM_INT];  // Becomes: evr_evt_event_id = ?

// 2. String conditions
$filters['evr_delete_time'] = "IS NULL";                      // Becomes: evr_delete_time IS NULL
$filters['evt_start_time'] = "> now()";                       // Becomes: evt_start_time > now()

// 3. Complex OR conditions (CRITICAL: split parentheses for precedence)
$filters['(evr_expires_time'] = ">= now() OR evr_expires_time IS NULL)";
// Becomes: (evr_expires_time >= now() OR evr_expires_time IS NULL)

// Usage example
$registrants = new MultiEventRegistrant(
    array('event_id' => 123, 'deleted' => false, 'expired' => false),
    array('evr_created_time' => 'DESC')
);
if ($registrants->count_all() > 0) {
    $registrants->load();
}
```

**Method Verification Best Practice:** Always check the actual class definition in `/data/[class]_class.php` to confirm method names, signatures, and parameters before using any method.

## Admin Page Development

For complete guidance on creating admin interface pages, including required setup, table patterns, form handling, and best practices, see:

**📖 [Admin Pages Documentation](/docs/admin_pages.md)**


## Common Tasks & Quick Reference

### File Loading Methods

**Two primary methods for including files:**

1. **`PathHelper::getIncludePath()`** - Direct file loading, no overrides
   ```php
   // Standard pattern for all non-overridable files
   require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));  // System files
   require_once(PathHelper::getIncludePath('data/users_class.php'));          // Data models
   require_once(PathHelper::getIncludePath('plugins/bookings/data/bookings_class.php')); // Plugin files
   ```

2. **`PathHelper::getThemeFilePath()`** - Theme-aware file resolution with override chain
   ```php
   // Files that can be overridden by themes
   require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
   require_once(PathHelper::getThemeFilePath('profile_logic.php', 'logic'));
   require_once(PathHelper::getThemeFilePath('profile.php', 'views'));

   // With explicit plugin context (5th parameter)
   require_once(PathHelper::getThemeFilePath('devices_logic.php', 'logic', 'system', null, 'controld'));

   // Parameters: filename, subdirectory, path_format, theme_name, plugin_name
   ```

   **Override chain:** theme/{theme}/path → plugins/{plugin}/path → /path
   **Format:** Always use two parameters - filename and subdirectory separately

**When to use each:**
- `PathHelper::getIncludePath()`: All standard files - system includes, data models, plugin files (no override capability)
- `PathHelper::getThemeFilePath()`: Files that themes/plugins should be able to override (views, logic, customizable includes)

**Examples:**
```php
// Standard files - use getIncludePath
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('plugins/bookings/data/bookings_class.php'));

// Theme-overridable files - use getThemeFilePath
require_once(PathHelper::getThemeFilePath('profile.php', 'views'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
```

### Handling Logic Function Results

```php
// ✅ CORRECT - Always wrap logic calls with process_logic():
$page_vars = process_logic(profile_logic($_GET, $_POST));

// Automatically handles redirects, errors, and data extraction
```

### Getting FormWriter Instances

```php
// In views with PublicPage available:
$formwriter = $page->getFormWriter('form1');

// In admin pages and utilities (where AdminPage is available):
$formwriter = $page->getFormWriter('form1');

// In logic files or other contexts without a page object:
// Directly require the appropriate FormWriter class for your context
require_once(PathHelper::getIncludePath('includes/FormWriterBootstrap.php'));
$formwriter = new FormWriterBootstrap('form1');

// The page->getFormWriter() method automatically detects the correct FormWriter for the theme
```

### Session Check (Admin Pages)
```php
$session = SessionControl::get_instance();
$session->check_permission(5); // Requires permission level 5 (admin minimum)
// Permission levels: 5 = admin, 10 = superadmin
// check_permission() automatically redirects to login if not authorized
```

### Tests
- `/tests/email/` - Email sending and authentication patterns
- `/tests/functional/products/` - Product-related functionality  
- `/tests/integration/` - External services (Mailgun, PHPMailer, routing)
- `/tests/models/` - Data model CRUD operations and validation

### Deployment Scripts
Located in `/home/user1/joinery/joinery/maintenance_scripts/`

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

**🚨 CRITICAL RULE: NEVER MODIFY FILES IN `/specs/implemented/`**

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

**CRITICAL REQUIREMENTS for PHP Development:**

1. **Syntax Validation**: Always check PHP files for syntax errors using `php -l filename.php` before declaring any PHP development task complete.

2. **Method Existence Validation**: Run the method existence test on all PHP files created or modified:
   ```bash
   php "/home/user1/joinery/joinery/maintenance scripts/method_existence_test.php" /path/to/modified/file.php
   ```
   - Investigate any missing methods flagged by the script
   - Only report task completion after all flags are investigated and resolved
   - The script will identify calls to non-existent functions and methods
   - Whitelisted common methods (SystemBase, PDO, etc.) are automatically skipped

### Common Development Commands
```bash
# PHP Syntax Validation
php -l filename.php

# Method Existence Validation
php "/home/user1/joinery/joinery/maintenance scripts/method_existence_test.php" /path/to/file.php

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
3. Run method existence validation on modified files
4. Test changes locally (web server is available)
5. Check error logs for any issues

**Quick Log Check:** `tail /var/www/html/joinerytest/logs/error.log` - Shows recent Apache error.log entries

## Plugin Development

Plugins provide backend functionality with admin interfaces at `/plugins/{plugin}/admin/*`.
See **📖 [Plugin Developer Guide](/docs/plugin_developer_guide.md)** for complete details.

## Best Practices

1. **Custom Commands**: Check `/home/user1/.claude/commands/` for project-specific slash commands before proceeding
2. **Syntax Validation**: ALWAYS run `php -l filename.php` on all PHP files before completing any task
3. **Method Existence Validation**: ALWAYS run method_existence_test.php on created/modified PHP files and investigate any flagged issues before completion
4. **Method Verification**: NEVER assume available functions - always check class definitions first
5. **Security**: Always validate and sanitize user input
6. **FormWriter**: Always use FormWriter class for forms
7. **Follow Existing Patterns**: Look at similar files in the codebase before creating new ones
8. **Version Numbers**: ALWAYS look for version numbers in files when making changes and increment them appropriately

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
- `asset($path, $themeName)` - Generate theme asset URLs with cache busting
- `config($key, $default, $themeName)` - Get theme configuration values
- `switchTheme($themeName)` - Change active theme system-wide

**PluginHelper** - Manages plugin metadata and provides plugin helper functions

**PathHelper** - Provides standardized path resolution and file operations across the system

**ComponentBase** - Base class providing common functionality for PluginHelper and ThemeHelper

## Workflow Notes

### Viewing Files After Edits

After making edits to any file, always provide a `batcat` command that the user can copy/paste to verify the changes. Do NOT run the batcat command yourself:

```bash
batcat /path/to/file.php
```

This allows the user to quickly review what was changed without needing to ask for a file view.