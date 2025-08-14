# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## System Overview

This is a custom PHP membership and event management platform with a modular MVC-like architecture. The system uses PostgreSQL and follows a front-controller pattern with theme-based UI customization and plugin extensibility.

**Key Entry Point:** `serve.php` - All requests are routed through this front controller using RouteHelper.php

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

### Routing System (serve.php + RouteHelper.php)

The system uses a hybrid routing approach with pattern matching:

**Route Pattern Rules:**
- **Route patterns** in serve.php always start with `/` (e.g., `/tests/*`, `/page/{slug}`)
- **File paths** in route configurations are always relative (e.g., `'view' => 'tests/{path}'`, `'model_file' => 'data/posts_class'`)
- **Request paths** are automatically normalized by RouteHelper (leading slashes added if missing)

**Path Processing Flow:**
1. Incoming request: `tests/integration/routing_test` (from Apache rewrite)
2. RouteHelper normalizes to: `/tests/integration/routing_test` (adds leading slash)
3. Pattern matching: `/tests/*` matches `/tests/integration/routing_test` 
4. Path validation: Returns `tests/integration/routing_test` (strips leading slash for file operations)
5. File inclusion: Uses relative path `tests/integration/routing_test.php`

**Route Types:**
- **Static routes**: Asset files with caching (`/theme/{theme}/assets/*`)
- **Dynamic routes**: Views and models (`/tests/*`, `/page/{slug}`)
- **Custom routes**: PHP closures for complex logic (`'/' => function() {...}`)

**Key Files:**
- `/serve.php`: Route definitions and front controller
- `/includes/RouteHelper.php`: Pattern matching and file serving utilities
- Plugin serve.php files: `/plugins/[name]/serve.php` for plugin-specific routes

### Plugin Architecture
Plugins in `/plugins/[name]/` have full MVC structure and can:
- Add admin interface pages
- Override routing through `serve.php`
- Include database migrations
- Extend themes

## Database & Configuration

**Database:** PostgreSQL with PDO prepared statements
**Configuration:** 
- `Globalvars::get_instance()` - Settings singleton
- Database-stored settings in `stg_settings` table
- File-based config: `config/Globalvars_site.php` (gitignored)

### Important Settings
- **composerAutoLoad**: Path to vendor directory (e.g., `/home/user1/vendor/`)
  - This setting contains the path to the vendor directory, NOT the full path to autoload.php
  - Code should append `autoload.php` to this path when using it
  - Example: `$autoload_path = $settings->get_setting('composerAutoLoad') . 'autoload.php';`

### Data Classes Pattern
```php
class TableName extends SystemBase {
    public static $prefix = 'tbl';
    public static $tablename = 'tbl_table_name';
    public static $pkey_column = 'tbl_id';
    // CRUD methods, soft delete, validation
}
```

### DbConnector Usage and Database Calls

The `DbConnector` class is a singleton that provides access to the PostgreSQL database using PDO.

**CRITICAL RULE:** **NEVER use DbConnector directly if there is a model class available!**
- ✅ Use `User`, `MultiUser`, `Product`, `MultiProduct`, etc. when they exist
- ✅ Use model methods like `load()`, `save()`, `get()`, `set()`, etc.
- ❌ Only use DbConnector for tables that don't have model classes
- ❌ Only use DbConnector for complex queries that models can't handle

#### **When to Use Models vs DbConnector:**

**USE MODELS (Preferred):**
```php
// Getting a user - USE THE MODEL
$user = new User($user_id, TRUE); // Load user by ID
$email = $user->get('usr_email');

// Finding users - USE THE MULTI MODEL
$users = new MultiUser(['usr_active' => 1], ['usr_id' => 'DESC']);
$users->load();

// Creating records - USE THE MODEL
$product = new Product(NULL);
$product->set('pro_name', 'New Product');
$product->save();
```

**USE DbConnector (Only when no model exists):**
```php
// Example: Custom reporting query with no model
$dbconnector = DbConnector::get_instance();
$dblink = $dbconnector->get_db_link();
$sql = "SELECT COUNT(*) FROM some_table WHERE complex_condition = ?";
$q = $dblink->prepare($sql);
$q->execute([$value]);
```

#### **Common Database Patterns:**

**SELECT Queries (with parameters):**
```php
$sql = "SELECT * FROM users WHERE usr_email = ? AND usr_active = ?";
$q = $dblink->prepare($sql);
$q->execute([$email, 1]);
$results = $q->fetchAll(PDO::FETCH_ASSOC);

// Single row
$result = $q->fetch(PDO::FETCH_ASSOC);
```

**INSERT/UPDATE/DELETE (with parameters):**
```php
$sql = "INSERT INTO stg_settings (stg_name, stg_value, stg_create_time) VALUES (?, ?, NOW())";
$q = $dblink->prepare($sql);
$q->execute([$setting_name, $setting_value]);

// For updates
$sql = "UPDATE users SET usr_last_login = NOW() WHERE usr_id = ?";
$q = $dblink->prepare($sql);
$q->execute([$user_id]);
```

**Transactions:**
```php
$dblink->beginTransaction();
try {
    // Multiple database operations
    $q1 = $dblink->prepare("INSERT INTO...");
    $q1->execute([...]);
    
    $q2 = $dblink->prepare("UPDATE...");
    $q2->execute([...]);
    
    $dblink->commit();
} catch (Exception $e) {
    $dblink->rollback();
    throw $e;
}
```

**Checking Existence:**
```php
$sql = "SELECT COUNT(*) as count FROM stg_settings WHERE stg_name = ?";
$q = $dblink->prepare($sql);
$q->execute([$setting_name]);
$result = $q->fetch(PDO::FETCH_ASSOC);
$exists = ($result['count'] > 0);
```

**CRITICAL RULES:**
1. **Always use prepared statements** - Never concatenate user input directly into SQL
2. **Always use the PDO connection** from `$dbconnector->get_db_link()`
3. **Never call DbConnector methods directly** - It doesn't have query(), exec(), etc.
4. **Use proper PDO fetch modes** - `PDO::FETCH_ASSOC` for arrays, `PDO::FETCH_OBJ` for objects
5. **Handle transactions properly** - Always rollback on exceptions

## Admin Page Structure and Conventions

### Required Includes and Setup

All admin pages should follow this standard structure:

```php
<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

$session = SessionControl::get_instance();
$session->check_permission(5); // Adjust permission level as needed

$page = new AdminPage();
$settings = Globalvars::get_instance();
```

### Admin Header

Use the AdminPage class for consistent admin panel styling:

```php
$page->admin_header([
    'title' => 'Page Title',
    'menu-id' => 'menu-identifier',
    'readable_title' => 'Human Readable Title'
]);
```

### Page Layout Rules

1. **No Nested Panels**: Avoid putting cards/panels inside other cards/panels unless there's a specific reason
2. **Use Headings for Sections**: Use `<h5 class="mb-3">Section Name</h5>` instead of nested panel headers
3. **Bootstrap Grid**: Use Bootstrap's responsive grid system (`row`, `col-md-6`, etc.)
4. **Consistent Spacing**: Use Bootstrap spacing utilities (`mb-4`, `g-3`, etc.)

### CSS Framework Usage

**Admin Sections ALWAYS use Bootstrap CSS classes:**
- Error states: Use `is-invalid` instead of legacy framework classes
- Form controls: Use `form-control` instead of legacy equivalents
- Buttons: Use `btn btn-primary`, `btn btn-secondary` etc.
- **IMPORTANT**: UIKit is being phased out and only left for compatibility with old themes. Do not reference UIKit classes unless explicitly instructed.

### Form Implementation

**ALWAYS use the FormWriter class for all forms and prefer built-in FormWriter validation:**

```php
$formwriter = LibraryFunctions::get_formwriter_object('form_name', 'admin');

// Set validation rules
$validation_rules = array();
$validation_rules['field_name']['required']['value'] = 'true';
echo $formwriter->set_validate($validation_rules);

// Begin form
echo $formwriter->begin_form('form_name', 'POST', $_SERVER['PHP_SELF']);

// Form fields
echo $formwriter->textinput('Label', 'field_name', 'form-control', 100, $default_value, 'placeholder', 255, 'help text');
echo $formwriter->passwordinput('Password Label', 'password_field', 'form-control', 100, '', 'help text');
echo $formwriter->checkinput('Checkbox Label', 'checkbox_field', 'form-check-input', '1', $checked_value, 'help text');

// Submit buttons
echo $formwriter->start_buttons();
echo $formwriter->new_form_button('Submit Text', 'btn btn-primary');
echo $formwriter->end_buttons();

echo $formwriter->end_form();
```

### Standard Page Structure

```php
<div class="row">
    <div class="col-12">
        
        <!-- Information Section (if needed) -->
        <div class="alert alert-info">
            <h6 class="alert-heading mb-2">Information Title</h6>
            <p class="mb-0">Information content...</p>
        </div>
        
        <!-- Main Form Section -->
        <h5 class="mb-3">Section Title</h5>
        
        <?php
        // FormWriter implementation here
        ?>
        
        <!-- Additional Content -->
        <div class="card">
            <div class="card-header bg-body-tertiary">
                <h6 class="mb-0">Card Title</h6>
            </div>
            <div class="card-body">
                Card content...
            </div>
        </div>
        
    </div>
</div>
```

### Admin Footer

Always close admin pages properly:

```php
$page->admin_footer();
```

### Alert Types

Use Bootstrap alerts for different message types:

- `alert-info`: General information
- `alert-warning`: Warnings and important notes  
- `alert-danger`: Errors and critical issues
- `alert-success`: Success messages

### Form Field Types

Common FormWriter field types:

- `textinput()`: Text input fields
- `passwordinput()`: Password fields
- `checkinput()`: Checkboxes
- `dropinput()`: Select dropdowns
- `hiddeninput()`: Hidden fields
- `new_form_button()`: Submit buttons

### Bootstrap Components

Preferred Bootstrap components for admin pages:

- **Cards**: For grouping related content
- **Alerts**: For messages and notifications
- **Badges**: For status indicators
- **Buttons**: Use `btn btn-primary`, `btn btn-secondary`, etc.
- **Grid**: Use responsive grid classes (`col-md-6`, `col-lg-4`, etc.)

### Permission Levels

Common permission levels:
- 5: Basic admin access
- 8: Advanced admin access  
- 10: System admin access

Always check permissions early in the script using:
```php
$session->check_permission(LEVEL);
```

### Validation

**Always prefer built-in FormWriter validation over custom JavaScript validation.** Use FormWriter validation for all user inputs:

```php
$validation_rules = array();
$validation_rules['field_name']['required']['value'] = 'true';
$validation_rules['email_field']['email']['value'] = 'true';
$validation_rules['url_field']['url']['value'] = 'true';
$validation_rules['file_field']['remote']['value'] = "'/ajax/validation_endpoint'";
echo $formwriter->set_validate($validation_rules);
```

**Built-in validation types:** required, email, url, minlength, maxlength, min, max, remote
**Custom validators:** Can be added to FormWriterMaster.php when needed

## Development Workflow

### Adding New Features
1. Create data class: `/data/[feature]_class.php`
2. Add business logic: `/logic/[feature]_logic.php`
3. Create view template: `/views/[feature].php`
4. Add admin interface: `/adm/admin_[feature].php` and `/adm/admin_[feature]_edit.php`
5. Add route to `serve.php` if needed (e.g., `'/feature/*' => ['view' => 'views/feature/{path}']`)
6. Add database migration in `/migrations/`

**Route Configuration Examples:**
```php
// Simple view route
'/feature' => ['view' => 'views/feature'],

// Model-based route  
'/item/{slug}' => ['model' => 'Item', 'model_file' => 'data/items_class'],

// Wildcard route
'/feature/*' => ['view' => 'views/feature/{path}'],

// Static asset route
'/feature/assets/*' => ['cache' => 43200],
```

### Database Migrations

**CRITICAL:** Database migrations require careful attention to structure and syntax to prevent system failures.

#### Migration File Structure

Migrations are defined in `/migrations/migrations.php` and use a specific array structure:

```php
// SQL-based migration
$migration['database_version'] = '0.XX';
$migration['test'] = "SELECT count(1) as count FROM table WHERE condition";
$migration['migration_sql'] = 'SQL STATEMENT HERE';
$migration['migration_file'] = NULL;
$migrations[] = $migration;

// File-based migration  
$migration = array(); // CRITICAL: Clear previous migration data
$migration['database_version'] = '0.XX';
$migration['test'] = "SELECT count(1) as count FROM table WHERE condition";
$migration['migration_file'] = 'migration_filename.php';
$migration['migration_sql'] = NULL; // CRITICAL: Clear SQL from previous migration
$migrations[] = $migration;
```

#### Critical Migration Rules

1. **ALWAYS clear the `$migration` array between migrations:**
   ```php
   $migration = array(); // Prevents data contamination from previous migrations
   ```

2. **File-based migrations MUST define a function:**
   ```php
   // File: protocol_mode_migration.php
   function protocol_mode_migration() {
       // Migration logic here
       return true; // MUST return true on success, false on failure
   }
   ```

3. **Function name MUST match filename** (without .php extension):
   - File: `protocol_mode_migration.php` → Function: `protocol_mode_migration()`

4. **Set unused fields to NULL explicitly:**
   ```php
   $migration['migration_sql'] = NULL; // For file-based migrations
   $migration['migration_file'] = NULL; // For SQL-based migrations
   ```

#### Migration Types

**SQL Migrations:**
- Hash generated from: `md5($migration['migration_sql'])`
- Use for simple SQL statements
- Executed directly by migration system

**File Migrations:**
- Hash generated from: `md5_file($migration['migration_file'])`  
- Use for complex logic, multi-step operations
- Must define function with same name as file

#### Test Conditions

Test conditions determine if migration should run:
- Return `count = 0` → Migration runs
- Return `count > 0` → Migration skipped

```php
// Good: Check if setting doesn't exist
$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'new_setting'";

// Complex: Check multiple conditions
$migration['test'] = "SELECT count(1) as count FROM stg_settings WHERE stg_name = 'new_setting' AND NOT EXISTS (SELECT 1 FROM stg_settings WHERE stg_name = 'old_setting')";
```

#### Common Migration Errors

**❌ Array Contamination (causes hash collisions):**
```php
// Previous migration
$migration['migration_sql'] = 'INSERT INTO...';
$migration['migration_file'] = NULL;
$migrations[] = $migration;

// Next migration - WRONG (inherits previous SQL)
$migration['migration_file'] = 'new_migration.php';
$migrations[] = $migration;
```

**✅ Correct Pattern:**
```php
// Previous migration
$migration['migration_sql'] = 'INSERT INTO...';
$migration['migration_file'] = NULL;
$migrations[] = $migration;

// Next migration - CORRECT (clean slate)
$migration = array();
$migration['migration_file'] = 'new_migration.php'; 
$migration['migration_sql'] = NULL;
$migrations[] = $migration;
```

**❌ Missing Function in Migration File:**
```php
// File: my_migration.php - WRONG (no function defined)
$dblink = DbConnector::get_instance();
$dblink->exec("UPDATE table SET field = 'value'");
```

**✅ Correct Function Pattern:**
```php
// File: my_migration.php - CORRECT
function my_migration() {
    $dblink = DbConnector::get_instance();
    try {
        $dblink->exec("UPDATE table SET field = 'value'");
        return true;
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage();
        return false;
    }
}
```

#### Migration Debugging

Enhanced error handling now provides detailed messages:
- Missing function errors show expected function name
- Failed migrations log detailed error information
- Migration tracking prevents duplicate execution

To troubleshoot migration issues:
1. Check `/utils/update_database.php` output for detailed errors
2. Verify function name matches filename exactly
3. Ensure migration array is properly cleared between definitions
4. Check for hash collisions in `mig_migrations` table

#### Migration Best Practices

1. **Test migrations thoroughly** in development environment
2. **Use transactions** for multi-step operations in migration functions
3. **Include rollback logic** where possible
4. **Log migration progress** with descriptive echo statements
5. **Handle errors gracefully** and return appropriate boolean values
6. **Follow existing patterns** in `/migrations/migrations.php`
7. **Plugins have separate migration files** in `/plugins/[name]/migrations/`

### Form Generation
Use `FormWriterMaster` classes for consistent form rendering:
- Base: `FormWriterMaster` (includes UIKit classes for themes that use UIKit)
- Falcon theme: `FormWriterMasterFalcon` (primary theme)
- Tailwind theme: `FormWriterMasterTailwind` (legacy, being phased out ~70% complete)

## Email System

### EmailTemplate Class

For sending emails, use the EmailTemplate class:

```php
require_once($base_path . '/includes/EmailTemplate.php');

$emailTemplate = new EmailTemplate('template_name');
$emailTemplate->clear_recipients();
$emailTemplate->add_recipient($email, 'Name');
$emailTemplate->email_subject = 'Subject';
$emailTemplate->email_html = $html_content;
$emailTemplate->email_text = $text_content;
$send_result = $emailTemplate->send(false);
```


## Development Environment

### Local Development Setup
**Available Locally:**
- **PHP Runtime** - Available for syntax checking, validation, and running scripts
- **File system access** - Can read, write, and modify files
- **Command line tools** - Basic bash commands available

**NOT Available Locally:**
- **Database connections** - Cannot connect to PostgreSQL from local environment
- **Web server** - Cannot run the application locally
- **External services** - No network access to APIs, mail servers, etc.

### What This Enables:
- ✅ Syntax validation of all PHP files before completion (`php -l filename.php`)
- ✅ Running utility scripts and diagnostics (non-database)
- ✅ File manipulation and code generation
- ✅ Testing file operations and parsing logic
- ❌ Database operations (testing must be done on server)
- ❌ Full application testing (requires server environment)

**CRITICAL REQUIREMENT:** Always check PHP files for syntax errors using `php -l filename.php` before declaring any PHP development task complete. This prevents syntax errors from being introduced into the codebase.

## Common Development Commands

### PHP Syntax Validation
```bash
# Check single file syntax
php -l filename.php

# Check multiple files syntax
php -l file1.php && php -l file2.php

# Check all PHP files in current directory
find . -name "*.php" -exec php -l {} \;
```

### Testing & Diagnostics
```bash
# System diagnostics (requires password 'setupinfo')
curl "http://site.local/utils/diagnostics.php?password=setupinfo"

# Database migrations
php utils/update_database.php

# Test email configuration
php utils/phpmailer_test.php

# Check test server error logs (joinerytest site)
"/mnt/c/Users/jerem/Proton Drive/jeremy.tunnell/My files/joinery/joinery/maintenance scripts/log_fetcher.sh"
```

### Test Server Monitoring

**Log Fetcher Tool:** `/mnt/c/Users/jerem/Proton Drive/jeremy.tunnell/My files/joinery/joinery/maintenance scripts/log_fetcher.sh`

This script provides access to the Apache error.log from the joinerytest site. Use it to:
- **Monitor runtime errors**: Check for errors after running changed code on the test server
- **Debug issues**: View recent PHP warnings, errors, and stack traces
- **Validate functionality**: Ensure new code works correctly when executed

**Usage Pattern:**
1. Make code changes locally with Claude Code
2. Run syntax validation (`php -l filename.php`)
3. Wait ~6 seconds for automatic file sync to test server
4. **Execute the changed files** (visit URLs like `https://joinerytest.site/path/to/file`)
5. Run log fetcher script to check for new errors
6. Fix any issues found in the logs

### Routing Troubleshooting

**Debug Mode**: To troubleshoot routing issues, RouteHelper and serve.php can echo debug output to HTML comments (visible in View Source):

```php
// Enable debugging in RouteHelper.php and serve.php by changing:
error_log("Debug message");
// to:
echo "<!-- Debug message -->\n";
```

**Common Routing Issues:**
1. **Pattern not matching**: Check that route patterns start with `/` and paths are normalized
2. **File not found**: Verify view files exist at the relative path specified in route config
3. **Path normalization**: RouteHelper strips leading slashes from paths for file operations
4. **Route order**: Routes are processed in order - more specific patterns should come before general ones

**Important Notes:** 
- **File Upload**: Claude Code does not upload files to the server. An automated script handles file synchronization to the test server (allow ~6 seconds for sync).
- **Runtime Errors Only**: The error logs only show runtime errors that occur when files are actually executed on the server. Simply having files uploaded will not generate log entries - you must visit the pages, run the scripts, or trigger the functionality to see any runtime errors in the logs.
- **Error Identification**: New errors appear with recent timestamps at the end of the log output, making them easy to distinguish from existing warnings.

**Common Error Types in Logs:**
- **Undefined array keys**: Missing array key checks (use `isset()` or `??` operator)
- **Undefined variables**: Variable initialization issues
- **PHP warnings**: Non-fatal issues that should be addressed for code quality
- **Fatal errors**: Critical issues that break functionality

The log shows timestamps, client IPs, and full error details including file paths and line numbers for efficient debugging.

### Frontend Assets
**Primary Theme:** Falcon (Bootstrap-based)
**Legacy Themes:** 
- Tailwind CSS configuration in `/theme/tailwind/includes/tailwind.config.js` (being phased out, ~70% complete)
- UIKit 3.6.14 maintained as optional framework


## Security Notes

- All database queries use PDO prepared statements
- Session-based authentication with role management
- File uploads have validation and safe storage
- Input validation on all forms (client + server side)
- Always validate and sanitize user input
- Check permissions early in admin scripts

## Key Integration Points

**Payment Processing:** StripeHelper, PaypalHelper classes
**Email:** SystemMailer with template support
**External APIs:** Webhooks in `/ajax/` for Stripe
**File Management:** Secure upload handling in `/includes/UploadHandler.php`

## Plugin Development
Create new plugin directory structure:
```
/plugins/[name]/
├── adm/          # Admin interface
├── data/         # Data models
├── logic/        # Business logic  
├── views/        # Templates
├── serve.php     # Custom routing
└── migrations/   # Database changes
```

## Admin Interface Patterns
- List pages: `admin_[entity].php`
- Edit forms: `admin_[entity]_edit.php`
- Currently uses Falcon theme (Bootstrap-based) via `AdminPage.php`
- Legacy UIKit support available via `AdminPage-uikit3.php` for themes that prefer UIKit
- UIKit 3.6.14 maintained in `/adm/includes/uikit-3.6.14/` as optional framework
- Table export functionality built-in
- Analytics and reporting modules available

## Development Restrictions

**IMPORTANT: Do not make changes to files in the following directories without explicit instructions:**

- `/includes/` - Core system classes and libraries
- `/migrations/` - Database schema changes and version control
- `/api/` - REST API endpoints and authentication
- `/data/` - Existing data model classes (new classes are allowed)

These directories contain critical system infrastructure. Changes should only be made with explicit user approval to prevent breaking core functionality.

## Best Practices

1. **Syntax Validation**: ALWAYS run `php -l filename.php` on all PHP files before completing any task
2. **Method Verification**: NEVER assume available functions or infer argument structure without examining the actual function definition first. Always check the class file to verify method names, signatures, and proper usage patterns before calling any method.
3. **Security**: Always validate and sanitize user input
4. **Consistency**: Follow the established patterns in existing admin files
5. **FormWriter**: Always use FormWriter class for forms (`LibraryFunctions::get_formwriter_object()`)
6. **No Nested Panels**: Avoid cards inside cards unless there's a specific reason
7. **Accessibility**: Use proper labels and form structure
8. **Responsive**: Ensure pages work on mobile devices
9. **Performance**: Include only necessary CSS/JS files
10. **Error Handling**: Provide clear error messages to users
11. **Follow Existing Patterns**: Look at similar files in the codebase before creating new ones
12. **Documentation**: Update this file when discovering new patterns or conventions
13. **Respect Restrictions**: Only modify restricted directories with explicit user permission

## SystemBase Methods Reference

### Core CRUD Methods

**Creation & Loading:**
- `new SystemBase($key, $and_load = FALSE)` - Constructor, optionally loads data
- `CreateNew($data)` - Static method to create new record from array
- `load()` - Load data from database using current key
- `safe_load_and_set($key, $value, $and_prepare = FALSE)` - Atomically load, set value, and save

**Data Access:**
- `get($key)` - Get field value
- `set($key, $value, $check_existance = TRUE)` - Set field value
- `get_array($key)` - Get JSON decoded array from field
- `set_array($key, $value)` - Set array value (will be JSON encoded)
- `getString($key, $max_len = NULL, $ellipsis = TRUE)` - Get truncated string
- `export_as_array()` - Export all fields as array

**Saving & Deletion:**
- `prepare()` - Prepare record for saving (validation, etc)
- `save($debug = false)` - Save record to database
- `soft_delete()` - Mark record as deleted (sets deleted field)
- `undelete()` - Restore soft-deleted record
- `permanent_delete($debug = false)` - Permanently remove from database (requires $permanent_delete_actions configuration)
- **Note: There is NO `delete()` method - use soft_delete() or permanent_delete()**

### Utility Methods

**Field Manipulation:**
- `set_all_to_null()` - Set all fields to null
- `add_flag($field, $flag)` - Add bitwise flag to field
- `check_flag($field, $flag)` - Check if flag is set
- `remove_flag($field, $flag)` - Remove bitwise flag

**Validation & Constraints:**
- `check_for_duplicate($fields = NULL, $search_deleted = false)` - Check if duplicate exists
- `CheckForDuplicate($obj_to_check, $fields = NULL, $search_deleted = false)` - Static duplicate check
- `check_unique_constraints()` - Validate unique field constraints

**Special Lookups:**
- `get_by_link($link, $search_deleted = false)` - Get record by URL slug
- `GetByColumn($column, $value)` - Get single record by column value
- `check_if_exists($key)` - Check if record exists

**URL Generation:**
- `create_url($input_url)` - Create URL-safe slug
- `get_url($format = 'short')` - Get record's URL

### SystemMultiBase Methods Reference

**Construction & Loading:**
- `new SystemMultiBase($options = [], $order_by = [], $limit = NULL, $offset = NULL, $operation = 'AND')` - Constructor
- `load($debug = false)` - Execute query and load results
- `count_all()` - Get count without loading objects

**Collection Access:**
- `get($index)` - Get object at index (0-based)
- `get_by_key($key)` - Get object by primary key
- `contains($item)` - Check if collection contains object
- `contains_key($key)` - Check if collection contains key

**Collection Manipulation:**
- `add($value)` - Add object to collection
- `remove($location)` - Remove object at index
- `remove_by_key($key)` - Remove object by key
- `clear()` - Empty the collection
- `usort($callback)` - Sort collection with callback

**Iteration:**
- Implements `IteratorAggregate` - use in foreach loops
- `count()` - Get number of loaded objects
- `incremental_iterator($limit = 200)` - Get iterator for large datasets

# Model Querying Patterns and Best Practices

This section provides guidance on how to properly query and interact with data models in the system to avoid common mistakes and follow established patterns.

## Single Object Access

### Object Instantiation Patterns

**CRITICAL**: All SystemBase-derived classes require at least one parameter in their constructor!

```php
// Creates object but doesn't load data from database
$product = new Product($id);

// Creates object AND immediately loads data from database  
$product = new Product($id, TRUE);

// Creates new object for insertion - MUST pass NULL, not empty constructor!
$product = new Product(NULL);  // ✅ CORRECT
$product = new Product();      // ❌ WRONG - will cause "Too few arguments" error

// Manual loading approach
$product = new Product($id);
$product->load();
```

**Common Mistake to Avoid:**
```php
// ❌ WRONG - SystemBase requires a key parameter
$user = new User();
$migration = new Migration();

// ✅ CORRECT - Pass NULL for new records
$user = new User(NULL);
$migration = new Migration(NULL);
```

### Key Single Object Methods
- `get($field)` - Retrieve field value
- `set($field, $value)` - Set field value  
- `save()` - Save object to database
- `load()` - Load data from database
- `prepare()` - Prepare object for saving (validation, etc.)
- `key` - Property containing the primary key value

### Common Usage Example
```php
$product = new Product($_REQUEST['p'], TRUE);
$product->set('pro_name', $_POST['pro_name']);
$product->prepare();
$product->save();
```

## Multi-Object Collections

### Constructor Pattern
```php
new MultiClassName($search_criteria, $order_by, $limit, $offset)
```

### Essential Multi-Object Methods
- `count_all()` - Get total count without loading objects
- `load()` - Load all matching objects into collection
- `get($index)` - Get object at specific index (0-based)
- `get_by_key($key)` - Get object by primary key value
- `add($object)` - Add object to collection
- Implements `IteratorAggregate` - can use `foreach` loops

### Search Criteria Examples
```php
// Simple field matching
$criteria = array('pro_is_active' => 1);

// Multiple criteria (AND by default)
$criteria = array(
    'pro_is_active' => 1,
    'pro_expires' => 0
);

// Special operators
$criteria = array('prv_status' => '> 0');

// Complex criteria with null handling
$criteria = array('pro_evt_event_id' => null);
```

### Order By Examples
```php
// Single field ascending
$order_by = array('pro_name' => 'ASC');

// Single field descending  
$order_by = array('prv_product_version_id' => 'DESC');

// Multiple fields
$order_by = array('pro_name' => 'ASC', 'pro_created' => 'DESC');
```

### Complete Multi-Object Example
```php
$products = new MultiProduct(
    array('pro_is_active' => 1),           // Search criteria
    array('pro_name' => 'ASC'),            // Order by
    10,                                    // Limit
    0                                      // Offset
);

if ($products->count_all() > 0) {
    $products->load();
    
    // Access first product
    $first_product = $products->get(0);
    
    // Iterate through all products
    foreach ($products as $product) {
        echo $product->get('pro_name');
    }
}
```

## Model Hierarchy and Relationships

### Class Relationships
- **Single Classes** (e.g., `Product`) extend `SystemBase`
- **Multi Classes** (e.g., `MultiProduct`) extend `SystemMultiBase`
- Multi classes contain collections of their corresponding single class objects
- Multi classes are NOT subclasses of their single counterparts

### Static Methods
```php
// Get object by URL slug/link
$product = Product::get_by_link($link_slug);

// Access static properties
$table_name = Product::$tablename;
$fields = Product::$fields;
```

## Common Anti-Patterns to Avoid

### ❌ Don't Do These
```php
// DON'T use direct SQL queries
$sql = "SELECT * FROM products WHERE pro_name = ?";
$result = $dbconnector->query($sql, $params);

// DON'T assume methods exist without checking
$product = $products->current(); // current() doesn't exist

// DON'T use made-up method names
$versions = $product->getVersions(); // Probably doesn't exist

// DON'T forget to load data when needed
$product = new Product($id); // Data not loaded yet!
$name = $product->get('pro_name'); // Will be null
```

### ✅ Do These Instead
```php
// USE model collections for queries
$products = new MultiProduct(array('pro_name' => $search_name));
$products->load();

// USE proper collection access methods
$product = $products->get(0); // Gets first item

// USE existing relationship methods or create new queries
$versions = new MultiProductVersion(array('prv_pro_product_id' => $product->key));

// USE immediate loading or explicit load calls
$product = new Product($id, TRUE); // Data loaded immediately
$name = $product->get('pro_name'); // Has data
```

## Method Verification Best Practice

**ALWAYS verify method existence before using:** Check the actual class definition in `/data/[class]_class.php` to confirm:
- Method names and signatures
- Required vs optional parameters  
- Return types and values
- Available static methods and properties

Never assume a method exists based on naming conventions or other frameworks. When in doubt, examine the source code of the class and its parent classes (`SystemBase` or `SystemMultiBase`).

## Special Cases and Gotchas

### Permanent Delete Actions
Models define how related data should be handled when an object is permanently deleted via the `$permanent_delete_actions` array:

```php
// Example from a model class
public static $permanent_delete_actions = array(
    'related_table_field' => 'delete',    // Delete related records
    'another_field' => 'null',            // Set field to NULL in related records  
    'status_field' => 'skip',             // Skip this field entirely
    'archive_field' => 'prevent',         // Prevent deletion if related records exist
    'default_field' => 'some_value'       // Set field to specific value
);
```

**Available Actions:**
- `'delete'` - Delete all related records
- `'null'` - Set the foreign key field to NULL in related records
- `'skip'` - Ignore this relationship during deletion
- `'prevent'` - Prevent deletion if related records exist
- Any other value - Set the field to that specific value

This system ensures referential integrity and prevents orphaned data when objects are permanently removed from the system.

### Test Mode Compatibility
Models automatically respect test mode when `DbConnector` is in test mode:
```php
$dbconnector = DbConnector::get_instance();
$dbconnector->set_test_mode();

// All model operations now use test database
$product = new Product($id, TRUE);
```

### Soft Delete Patterns
Many models support soft deletion:
```php
// Include deleted items in search
$products = new MultiProduct(array('deleted' => false));

// Some models have specific deleted field handling
$criteria = array('pro_status' => '> 0'); // Active items only
```

This guidance should prevent common model querying mistakes and ensure consistent usage patterns throughout the codebase.

## Example Admin Page Template

```php
<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

$session = SessionControl::get_instance();
$session->check_permission(5);

$page = new AdminPage();

// Process form submission
if ($_POST) {
    // Handle form data
    // Redirect after processing
}

// Admin header
$page->admin_header([
    'title' => 'Page Title',
    'menu-id' => 'menu-id',
    'readable_title' => 'Page Title'
]);
?>

<div class="row">
    <div class="col-12">
        
        <h5 class="mb-3">Form Section</h5>
        
        <?php
        $formwriter = LibraryFunctions::get_formwriter_object('form1', 'admin');
        
        $validation_rules = array();
        $validation_rules['field']['required']['value'] = 'true';
        echo $formwriter->set_validate($validation_rules);
        
        echo $formwriter->begin_form('form1', 'POST', $_SERVER['PHP_SELF']);
        echo $formwriter->textinput('Field Label', 'field', 'form-control', 100, '', 'placeholder', 255, '');
        echo $formwriter->start_buttons();
        echo $formwriter->new_form_button('Submit', 'btn btn-primary');
        echo $formwriter->end_buttons();
        echo $formwriter->end_form();
        ?>
        
    </div>
</div>

<?php
$page->admin_footer();
?>
```