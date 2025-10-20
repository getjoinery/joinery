# Admin Page Reference Guide

**Version:** 1.0
**Last Updated:** 2025-01-19
**Reference Implementation:** `/adm/admin_user.php` and `/adm/logic/admin_user_logic.php`

## Table of Contents

1. [Overview](#overview)
2. [Quick Start](#quick-start)
3. [File Structure](#file-structure)
4. [Logic File Pattern](#logic-file-pattern)
5. [View File Pattern](#view-file-pattern)
6. [Common Patterns by Page Type](#common-patterns-by-page-type)
7. [Complete Reference Example](#complete-reference-example)
8. [Best Practices](#best-practices)
9. [Troubleshooting](#troubleshooting)

## Overview

This guide provides a complete reference for creating admin pages in the Joinery platform using the modern logic architecture pattern. The admin_user page serves as the canonical example of this pattern, demonstrating all key concepts.

### Core Architecture Principles

1. **Separation of Concerns**: Business logic in `/adm/logic/`, presentation in `/adm/`
2. **LogicResult Pattern**: All logic functions return `LogicResult` objects
3. **process_logic() Helper**: Views use `process_logic()` to handle redirects and errors
4. **Exact Code Preservation**: When converting, move code as-is without refactoring

### Key Files

- **Reference Logic File**: `/adm/logic/admin_user_logic.php` (⭐⭐⭐⭐⭐ Very Complex)
- **Reference View File**: `/adm/admin_user.php`
- **Conversion Guide**: `/specs/admin_conversion_guide.md`
- **Logic Architecture**: `/docs/logic_architecture.md`
- **Admin Pages Guide**: `/docs/admin_pages.md`

## Quick Start

### Creating a New Admin Page

1. **Create the logic file** at `/adm/logic/admin_[page]_logic.php`
2. **Create the view file** at `/adm/admin_[page].php`
3. **Validate syntax**: `php -l filename.php`
4. **Test methods**: `php "/home/user1/joinery/joinery/maintenance scripts/method_existence_test.php" filename.php`

### Naming Conventions

| Page Type | Logic File | View File |
|-----------|-----------|-----------|
| List page | `admin_users_logic.php` | `admin_users.php` |
| Detail page | `admin_user_logic.php` | `admin_user.php` |
| Edit form | `admin_user_edit_logic.php` | `admin_user_edit.php` |
| Delete action | `admin_user_delete_logic.php` | `admin_user_delete.php` |

## File Structure

### Project Organization

```
/adm/
  ├── admin_user.php                    # View file (display only)
  └── logic/
      └── admin_user_logic.php          # Logic file (business logic)
```

### Logic File Location Rules

- **Always** in `/adm/logic/` directory
- **Never** in subdirectories
- **Plugin admin pages**: Same pattern in `/plugins/[plugin]/admin/logic/`

## Logic File Pattern

### Complete Template

```php
<?php
// IMPORTANT: Logic files MUST require PathHelper because they are not accessed
// through serve.php's front controller. They are directly included by view files,
// so they don't get automatic PathHelper loading.
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_page_logic($get_vars, $post_vars) {
    // 1. Required includes (PathHelper is now available from the require above)
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getIncludePath('includes/Pager.php'));

    // 2. Data class includes
    require_once(PathHelper::getIncludePath('data/users_class.php'));
    // Add other required data classes

    // 3. Get singletons (NO require needed - these are always pre-loaded)
    // Globalvars, SessionControl, DbConnector, ThemeHelper, PluginHelper are ALWAYS available
    $settings = Globalvars::get_instance();
    $session = SessionControl::get_instance();

    // 4. Permission check
    $session->check_permission(5); // Or appropriate level (5=admin, 10=superadmin)
    $session->set_return();

    // 5. Initialize page variables
    $page_vars = array();
    $page_vars['settings'] = $settings;
    $page_vars['session'] = $session;

    // 6. Process actions/POST data FIRST (before loading data)
    if (isset($post_vars['action']) || isset($get_vars['action'])) {
        $action = $post_vars['action'] ?? $get_vars['action'] ?? null;

        switch ($action) {
            case 'delete':
                $item = new Item($get_vars['item_id'], TRUE);
                $item->soft_delete();
                return LogicResult::redirect('/admin/admin_items?msg=deleted');

            case 'save':
                $item = new Item($post_vars['item_id'] ?? NULL);
                $item->set('field_name', $post_vars['field_name']);
                $item->prepare();
                $item->save();
                return LogicResult::redirect('/admin/admin_item?item_id=' . $item->key . '&msg=saved');
        }
    }

    // 7. Load data for display
    $items = new MultiItem(
        array('deleted' => false),
        array('item_id' => 'DESC'),
        30,  // limit
        0    // offset
    );
    $numrecords = $items->count_all();
    $items->load();

    // 8. Prepare all data for view
    $page_vars['items'] = $items;
    $page_vars['numrecords'] = $numrecords;

    // 9. Return data for rendering
    return LogicResult::render($page_vars);
}
?>
```

### Logic File Sections Explained

#### Section 1: PathHelper Bootstrap
```php
// CRITICAL: This is the ONLY place where __DIR__ navigation is allowed
require_once(__DIR__ . '/../../includes/PathHelper.php');
```

**Why:** Logic files are included by view files, not served through the front controller, so PathHelper isn't pre-loaded.

#### Section 2-3: Includes
```php
// Required utility classes
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

// Data models
require_once(PathHelper::getIncludePath('data/users_class.php'));

// NEVER require these - they're always available:
// - PathHelper (loaded in Section 1)
// - Globalvars (pre-loaded system-wide)
// - SessionControl (pre-loaded system-wide)
// - DbConnector (pre-loaded system-wide)
// - ThemeHelper (pre-loaded system-wide)
// - PluginHelper (pre-loaded system-wide)
```

#### Section 4: Permissions
```php
$session->check_permission(5);  // Redirects if insufficient permission
$session->set_return();          // Sets return URL for after login
```

**Permission Levels:**
- `5` = Basic admin access
- `7` = Higher admin access
- `9` = Super admin access
- `10` = Full system admin access

#### Section 5: Page Variables
```php
$page_vars = array();
$page_vars['settings'] = $settings;  // Always include
$page_vars['session'] = $session;    // Always include
```

**CRITICAL:** Always include `$settings` and `$session` in `$page_vars`.

#### Section 6: Action Processing

**IMPORTANT:** Process actions BEFORE loading display data to avoid unnecessary queries.

```php
// Handle POST actions (forms)
if ($post_vars) {
    switch ($post_vars['action']) {
        case 'add_to_group':
            $group = new Group($post_vars['grp_group_id'], TRUE);
            $group->add_member($user->key);
            return LogicResult::redirect('/admin/admin_user?usr_user_id=' . $user->key);
    }
}

// Handle GET actions (links)
if (isset($get_vars['action'])) {
    switch ($get_vars['action']) {
        case 'delete':
            $user->soft_delete();
            return LogicResult::redirect('/admin/admin_users');
    }
}
```

#### Section 7-8: Data Loading

```php
// Simple data loading
$users = new MultiUser(
    array('deleted' => false),           // Search criteria
    array('user_id' => 'DESC'),          // Sort
    30,                                   // Limit
    0                                     // Offset
);
$numrecords = $users->count_all();       // Count BEFORE load() for efficiency
$users->load();

// Add to page_vars
$page_vars['users'] = $users;
$page_vars['numrecords'] = $numrecords;
```

**Best Practice:** Call `count_all()` before `load()` to get total count without loading all records.

#### Section 9: Return

```php
return LogicResult::render($page_vars);  // Normal page display
return LogicResult::redirect('/path');    // Redirect after action
return LogicResult::error('Error msg');   // Error condition
```

## View File Pattern

### Complete Template

```php
<?php
// NO need to require PathHelper - admin pages are accessed through serve.php
// PathHelper, Globalvars, SessionControl, DbConnector, ThemeHelper, PluginHelper are ALWAYS available

// 1. Include the logic file
require_once(PathHelper::getIncludePath('adm/logic/admin_page_logic.php'));

// 2. Include required view classes (AdminPage, Pager, etc.)
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/Pager.php'));

// 3. Process the logic and get page variables
$page_vars = process_logic(admin_page_logic($_GET, $_POST));

// 4. Extract commonly used variables for convenience
$session = $page_vars['session'];
$settings = $page_vars['settings'];
$items = $page_vars['items'];
$numrecords = $page_vars['numrecords'];

// 5. AdminPage setup (display only)
$page = new AdminPage();
$page->admin_header(array(
    'menu-id' => 'items-list',
    'page_title' => 'Items',
    'readable_title' => 'Item List',
    'breadcrumbs' => array('All Items' => ''),
    'session' => $session,
));

// 6. Display table
$pager = new Pager(array(
    'numrecords' => $numrecords,
    'numperpage' => 30
));

$headers = array("Name", "Date", "Actions");
$table_options = array(
    'title' => 'Items',
    'search_on' => TRUE
);

$page->tableheader($headers, $table_options, $pager);

foreach ($items as $item) {
    $rowvalues = array();
    array_push($rowvalues, htmlspecialchars($item->get('name')));
    array_push($rowvalues, LibraryFunctions::convert_time($item->get('created'), 'UTC', $session->get_timezone()));
    array_push($rowvalues, '<a href="/admin/admin_item_edit?id=' . $item->key . '">Edit</a>');
    $page->disprow($rowvalues);
}

$page->endtable($pager);

// 7. Footer
$page->admin_footer();
?>
```

### View File Sections Explained

#### Section 1-2: Includes
```php
// Logic file - ALWAYS required
require_once(PathHelper::getIncludePath('adm/logic/admin_page_logic.php'));

// View classes - require as needed
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/Pager.php'));

// Data classes - only if needed for display-specific logic
require_once(PathHelper::getIncludePath('data/events_class.php'));
```

**Note:** Only include data classes in view if you need them for display logic (rare).

#### Section 3: process_logic()
```php
$page_vars = process_logic(admin_page_logic($_GET, $_POST));
```

**What process_logic() does:**
1. Calls the logic function
2. Handles `LogicResult::redirect()` (performs redirect, exits)
3. Handles `LogicResult::error()` (throws exception or displays error)
4. Returns `$page_vars` array from `LogicResult::render()`

#### Section 4: Variable Extraction
```php
// Extract for cleaner code
$session = $page_vars['session'];
$settings = $page_vars['settings'];
$user = $page_vars['user'];
```

**Best Practice:** Extract commonly used variables at the top for cleaner template code.

#### Section 5: AdminPage Setup
```php
$page = new AdminPage();
$page->admin_header(array(
    'menu-id' => 'users-list',              // For menu highlighting
    'page_title' => 'User',                 // Browser title
    'readable_title' => 'John Doe',         // Page heading
    'breadcrumbs' => array(                  // Breadcrumb trail
        'Users' => '/admin/admin_users',
        'John Doe' => '',
    ),
    'session' => $session,                   // Required
    'no_page_card' => true,                  // Optional: no card wrapper
    'header_action' => $dropdown_button,     // Optional: action button/dropdown
));
```

#### Section 6-7: Display Content
Use AdminPage methods for consistent layout:
- `tableheader()` - Start table with headers
- `disprow()` - Display table row
- `endtable()` - End table
- `admin_footer()` - Close page

## Common Patterns by Page Type

### 1. List Page Pattern (admin_users.php)

**Purpose:** Display paginated, searchable, sortable list of records

**Logic File:**
```php
function admin_users_logic($get_vars, $post_vars) {
    // ... setup ...

    // Get pagination/search parameters
    $numperpage = 30;
    $offset = LibraryFunctions::fetch_variable_local($get_vars, 'offset', 0);
    $sort = LibraryFunctions::fetch_variable_local($get_vars, 'sort', 'user_id');
    $sdirection = LibraryFunctions::fetch_variable_local($get_vars, 'sdirection', 'DESC');
    $searchterm = LibraryFunctions::fetch_variable_local($get_vars, 'searchterm', '');

    // Build search criteria
    $search_criteria = array('deleted' => false);
    if ($searchterm) {
        $search_criteria['search'] = $searchterm;
    }

    // Load data
    $users = new MultiUser($search_criteria, array($sort => $sdirection), $numperpage, $offset);
    $numrecords = $users->count_all();
    $users->load();

    // Prepare for view
    $page_vars['users'] = $users;
    $page_vars['numrecords'] = $numrecords;
    $page_vars['numperpage'] = $numperpage;
    $page_vars['headers'] = array("Name", "Email", "Signup Date");
    $page_vars['sortoptions'] = array(
        "User ID" => "user_id",
        "Name" => "last_name",
        "Email" => "email"
    );

    return LogicResult::render($page_vars);
}
```

**View File:**
```php
$pager = new Pager(array('numrecords' => $numrecords, 'numperpage' => $numperpage));
$table_options = array(
    'sortoptions' => $page_vars['sortoptions'],
    'title' => 'Users',
    'search_on' => TRUE
);
$page->tableheader($page_vars['headers'], $table_options, $pager);

foreach ($users as $user) {
    $rowvalues = array();
    array_push($rowvalues, '<a href="/admin/admin_user?usr_user_id=' . $user->key . '">' . $user->display_name() . '</a>');
    array_push($rowvalues, htmlspecialchars($user->get('usr_email')));
    array_push($rowvalues, LibraryFunctions::convert_time($user->get('usr_signup_date'), 'UTC', $session->get_timezone(), 'M j, Y'));
    $page->disprow($rowvalues);
}

$page->endtable($pager);
```

### 2. Detail Page Pattern (admin_user.php)

**Purpose:** Display single record with related data and multiple action forms

**Logic File:**
```php
function admin_user_logic($get_vars, $post_vars) {
    // ... setup ...

    // Get record ID
    $user_id = $get_vars['usr_user_id'] ?? null;
    if (!$user_id) {
        return LogicResult::error('User ID is required');
    }

    // Load main record
    $user = new User($user_id, TRUE);
    if (!$user->get('usr_id')) {
        header("HTTP/1.0 404 Not Found");
        return LogicResult::error('User not found');
    }

    // Handle multiple possible POST actions
    if ($post_vars) {
        switch ($post_vars['action']) {
            case 'add_to_group':
                $group = new Group($post_vars['grp_group_id'], TRUE);
                $group->add_member($user->key);
                return LogicResult::redirect('/admin/admin_user?usr_user_id=' . $user->key);

            case 'remove_from_group':
                $groupmember = new GroupMember($post_vars['grm_group_member_id'], TRUE);
                $groupmember->remove();
                return LogicResult::redirect('/admin/admin_user?usr_user_id=' . $user->key);
        }
    }

    // Load all related data
    $phone_numbers = new MultiPhoneNumber(array('user_id' => $user->key));
    $phone_numbers->load();

    $addresses = new MultiAddress(array('user_id' => $user->key));
    $addresses->load();

    $orders = new MultiOrder(
        array('user_id' => $user->key),
        array('ord_order_id' => 'DESC'),
        10,
        NULL
    );
    $numorders = $orders->count_all();
    $orders->load();

    // Prepare everything for view
    $page_vars['user'] = $user;
    $page_vars['phone_numbers'] = $phone_numbers;
    $page_vars['addresses'] = $addresses;
    $page_vars['orders'] = $orders;
    $page_vars['numorders'] = $numorders;

    return LogicResult::render($page_vars);
}
```

**View File:**
```php
// Display main record info
echo '<h2>' . htmlspecialchars($user->display_name()) . '</h2>';
echo '<p>Email: ' . htmlspecialchars($user->get('usr_email')) . '</p>';

// Display related data
echo '<h3>Phone Numbers</h3>';
foreach ($phone_numbers as $phone) {
    echo '<p>' . htmlspecialchars($phone->get_phone_string()) . '</p>';
}

// Display action forms
echo '<form method="POST" action="/admin/admin_user?usr_user_id=' . $user->key . '">';
echo '<input type="hidden" name="action" value="add_to_group" />';
// ... form fields ...
echo '</form>';
```

### 3. Edit Form Pattern (admin_user_edit.php)

**Purpose:** Create or edit a single record

**Logic File:**
```php
function admin_user_edit_logic($get_vars, $post_vars) {
    // ... setup ...

    // Determine if new or edit
    $user_id = $get_vars['usr_user_id'] ?? null;
    $user = new User($user_id ?? NULL, $user_id ? TRUE : FALSE);

    // Process form submission
    if ($post_vars) {
        try {
            // Set values from form
            $user->set('usr_first_name', $post_vars['usr_first_name']);
            $user->set('usr_last_name', $post_vars['usr_last_name']);
            $user->set('usr_email', $post_vars['usr_email']);

            // Validate and save
            $user->prepare();
            $user->save();

            // Redirect on success
            return LogicResult::redirect('/admin/admin_user?usr_user_id=' . $user->key . '&msg=saved');

        } catch (Exception $e) {
            $page_vars['error_message'] = $e->getMessage();
        }
    }

    // Prepare data for form
    $page_vars['user'] = $user;
    $page_vars['is_new'] = empty($user_id);

    return LogicResult::render($page_vars);
}
```

**View File:**
```php
<?php if (isset($error_message)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error_message) ?></div>
<?php endif; ?>

<?php
$formwriter = $page->getFormWriter('form1');

$validation_rules = array();
$validation_rules['usr_first_name']['required']['value'] = 'true';
$validation_rules['usr_last_name']['required']['value'] = 'true';
$validation_rules['usr_email']['required']['value'] = 'true';
$validation_rules['usr_email']['email']['value'] = 'true';

echo $formwriter->set_validate($validation_rules);
echo $formwriter->begin_form('form1', 'POST', $_SERVER['PHP_SELF'] . '?usr_user_id=' . $user->key);

echo $formwriter->textinput('First Name', 'usr_first_name', 'form-control', 100, $user->get('usr_first_name'));
echo $formwriter->textinput('Last Name', 'usr_last_name', 'form-control', 100, $user->get('usr_last_name'));
echo $formwriter->textinput('Email', 'usr_email', 'form-control', 100, $user->get('usr_email'));

echo $formwriter->start_buttons();
echo $formwriter->new_form_button('Save User', 'btn btn-primary');
echo $formwriter->end_buttons();
echo $formwriter->end_form();
?>
```

### 4. Delete Confirmation Pattern

**Logic File:**
```php
function admin_user_delete_logic($get_vars, $post_vars) {
    // ... setup ...

    $user_id = $get_vars['usr_user_id'] ?? null;
    $user = new User($user_id, TRUE);

    // Handle confirmation
    if ($post_vars && $post_vars['confirm'] === 'yes') {
        $user->soft_delete();
        return LogicResult::redirect('/admin/admin_users?msg=deleted');
    }

    $page_vars['user'] = $user;
    return LogicResult::render($page_vars);
}
```

**View File:**
```php
<div class="alert alert-warning">
    <p>Are you sure you want to delete user: <strong><?= htmlspecialchars($user->display_name()) ?></strong>?</p>
    <form method="POST" action="/admin/admin_user_delete?usr_user_id=<?= $user->key ?>">
        <input type="hidden" name="confirm" value="yes" />
        <button type="submit" class="btn btn-danger">Yes, Delete</button>
        <a href="/admin/admin_user?usr_user_id=<?= $user->key ?>" class="btn btn-secondary">Cancel</a>
    </form>
</div>
```

## Complete Reference Example

See the following files for a complete, production-ready example:

- **Logic File**: `/adm/logic/admin_user_logic.php`
- **View File**: `/adm/admin_user.php`
- **Backup**: `/adm/admin_user.php.bak` (original before conversion)

### What admin_user Demonstrates

✅ Multi-action handling (add to group, remove from group, add to event, remove from event)
✅ Complex data loading (phone numbers, addresses, orders, subscriptions, groups, emails, logins)
✅ Permission checks and authentication
✅ Database queries (both model-based and custom SQL)
✅ Pagination with "show all" toggle
✅ Building altlinks dropdown
✅ Custom HTML output
✅ FormWriter integration
✅ Multiple tables on one page
✅ Conditional display based on user properties

## Best Practices

### 1. Code Organization

✅ **DO:**
- Put ALL business logic in logic file
- Put ALL display code in view file
- Load data in logic, display in view
- Use descriptive variable names

❌ **DON'T:**
- Mix business logic and display
- Load data in view file (except for display-specific lookups)
- Use generic variable names ($data, $result)
- Refactor during conversion

### 2. Variable Naming

```php
// Logic file
$page_vars['user'] = $user;                    // Main entity
$page_vars['phone_numbers'] = $phone_numbers;  // Related collection
$page_vars['numorders'] = $numorders;          // Count
$page_vars['show_all'] = $show_all;            // State/flag

// View file - extract for convenience
$user = $page_vars['user'];
$phone_numbers = $page_vars['phone_numbers'];
```

### 3. Action Handling

```php
// CORRECT - Process actions before loading data
if ($post_vars) {
    // Handle action and redirect
    return LogicResult::redirect('/path');
}

// Load data for display (only if no redirect occurred)
$data = load_data();

// WRONG - Loading data before processing actions
$data = load_data();  // Wasteful if we redirect
if ($post_vars) {
    return LogicResult::redirect('/path');
}
```

### 4. URL Formation

```php
// CORRECT - No .php extension
return LogicResult::redirect('/admin/admin_users');
$link = '<a href="/admin/admin_user?usr_user_id=' . $id . '">View</a>';

// WRONG - Don't use .php extension
return LogicResult::redirect('/admin/admin_users.php');  // ❌ WRONG
$link = '<a href="/admin/admin_user.php?usr_user_id=' . $id . '">View</a>';  // ❌ WRONG
```

### 5. Error Handling

```php
// Logic file
if (!$user_id) {
    return LogicResult::error('User ID is required');
}

$user = new User($user_id, TRUE);
if (!$user->get('usr_id')) {
    header("HTTP/1.0 404 Not Found");
    return LogicResult::error('User not found');
}

// For form errors
try {
    $user->prepare();
    $user->save();
} catch (Exception $e) {
    $page_vars['error_message'] = $e->getMessage();
}
```

### 6. Security

```php
// Always check permissions
$session->check_permission(5);  // Redirects if unauthorized

// Always authenticate write operations
$user->authenticate_write(array(
    'current_user_id' => $session->get_user_id(),
    'current_user_permission' => $session->get_permission()
));

// Always escape output
echo htmlspecialchars($user->get('usr_email'));

// Always use prepared statements (models do this automatically)
$users = new MultiUser(array('search' => $searchterm));  // ✅ Safe
```

### 7. Data Loading Efficiency

```php
// CORRECT - Count before loading
$users = new MultiUser($criteria, $sort, $limit, $offset);
$numrecords = $users->count_all();  // Efficient count query
$users->load();                      // Load only what's needed

// WRONG - Loading everything
$users = new MultiUser($criteria);
$users->load();                      // Loads ALL records
$numrecords = $users->count();       // Counts loaded records (wasteful)
```

### 8. FormWriter Usage

```php
// Get FormWriter from page object
$formwriter = $page->getFormWriter('form1');

// Set validation rules
$validation_rules = array();
$validation_rules['field_name']['required']['value'] = 'true';
echo $formwriter->set_validate($validation_rules);

// Build form
echo $formwriter->begin_form('form1', 'POST', '/admin/admin_page');
echo $formwriter->textinput('Label', 'field_name', 'form-control', 100, $default_value);
echo $formwriter->start_buttons();
echo $formwriter->new_form_button('Submit', 'btn btn-primary');
echo $formwriter->end_buttons();
echo $formwriter->end_form();
```

## Troubleshooting

### Common Issues and Solutions

#### Issue: "Cannot use object of type LogicResult as array"

**Cause:** View is not using `process_logic()` wrapper

**Solution:**
```php
// ❌ WRONG
$page_vars = admin_page_logic($_GET, $_POST);

// ✅ CORRECT
$page_vars = process_logic(admin_page_logic($_GET, $_POST));
```

#### Issue: "Undefined variable in view"

**Cause:** Variable not added to `$page_vars` in logic file

**Solution:**
```php
// Logic file - ensure all variables are in $page_vars
$page_vars['user'] = $user;
$page_vars['items'] = $items;
// etc.

return LogicResult::render($page_vars);
```

#### Issue: "Redirect not working"

**Cause:** Output sent before redirect, or not using LogicResult

**Solution:**
```php
// ❌ WRONG
echo "Debug output";
header("Location: /admin/admin_users");

// ✅ CORRECT
return LogicResult::redirect('/admin/admin_users');
```

#### Issue: "PathHelper not found in logic file"

**Cause:** Missing PathHelper bootstrap at top of logic file

**Solution:**
```php
// MUST be first line in logic file
require_once(__DIR__ . '/../../includes/PathHelper.php');
```

#### Issue: "Permission denied incorrectly"

**Cause:** Permission check in wrong location

**Solution:**
```php
// ✅ CORRECT - Check permission in logic file
function admin_page_logic($get_vars, $post_vars) {
    $session = SessionControl::get_instance();
    $session->check_permission(5);  // Early in logic file
    // ... rest of logic ...
}

// ❌ WRONG - Don't check in view
```

#### Issue: "Form not processing"

**Cause:** Logic file not handling POST action

**Solution:**
```php
// Ensure logic file has this pattern
if ($post_vars) {
    if ($post_vars['action'] == 'your_action') {
        // Process action
        return LogicResult::redirect('/path');
    }
}
```

### Validation Commands

```bash
# Check PHP syntax
php -l /var/www/html/joinerytest/public_html/adm/logic/admin_user_logic.php
php -l /var/www/html/joinerytest/public_html/adm/admin_user.php

# Check method existence
php "/home/user1/joinery/joinery/maintenance scripts/method_existence_test.php" /var/www/html/joinerytest/public_html/adm/logic/admin_user_logic.php
php "/home/user1/joinery/joinery/maintenance scripts/method_existence_test.php" /var/www/html/joinerytest/public_html/adm/admin_user.php
```

### Testing Checklist

After creating or converting an admin page:

- [ ] **Syntax validated** with `php -l`
- [ ] **Methods verified** with method_existence_test.php
- [ ] **Permissions work** - unauthorized users redirected
- [ ] **Data loads** correctly in all sections
- [ ] **Sorting works** (if applicable)
- [ ] **Searching works** (if applicable)
- [ ] **Pagination works** (if applicable)
- [ ] **Forms submit** correctly
- [ ] **Redirects occur** after POST operations
- [ ] **Error handling** works (try invalid IDs)
- [ ] **Success messages** display properly
- [ ] **URLs correct** (no .php extensions)

## Related Documentation

- **Main Architecture Guide**: `/CLAUDE.md`
- **Logic Architecture**: `/docs/logic_architecture.md`
- **Admin Pages Guide**: `/docs/admin_pages.md`
- **Conversion Guide**: `/specs/admin_conversion_guide.md`
- **Plugin Development**: `/docs/plugin_developer_guide.md`

## Version History

- **v1.0** (2025-01-19): Initial version based on admin_user conversion
