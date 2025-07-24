# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## System Overview

This is a custom PHP membership and event management platform with a modular MVC-like architecture. The system uses PostgreSQL and follows a front-controller pattern with theme-based UI customization and plugin extensibility.

**Key Entry Point:** `serve.php` - All requests are routed through this front controller

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

### Data Classes Pattern
```php
class TableName extends SystemBase {
    public static $prefix = 'tbl';
    public static $tablename = 'tbl_table_name';
    public static $pkey_column = 'tbl_id';
    // CRUD methods, soft delete, validation
}
```

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
5. Update routing in `serve.php` if needed
6. Add database migration in `/migrations/`

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

### Email Authentication Testing

The framework includes utilities for testing email authentication:
- `/utils/email_setup_check.php` - Check SPF, DKIM, DMARC records for domains
- `/utils/email_send_test.php` - Send test emails and analyze authentication results

## Development Environment

### PHP Runtime Available
PHP is available locally for syntax checking, validation, and running scripts. This enables:
- Syntax validation of all PHP files before completion
- Running utility scripts and diagnostics
- Testing migrations and database operations
- Validating code changes locally

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
```

### Frontend Assets
**Primary Theme:** Falcon (Bootstrap-based)
**Legacy Themes:** 
- Tailwind CSS configuration in `/theme/tailwind/includes/tailwind.config.js` (being phased out, ~70% complete)
- UIKit 3.6.14 maintained as optional framework

**Static files** served through `serve.php` routing with cache headers

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
**External APIs:** Webhooks in `/ajax/` for Stripe, Calendly
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