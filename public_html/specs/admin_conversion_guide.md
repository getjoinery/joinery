# Admin Pages Conversion Guide to Logic File Structure

## Overview
This guide provides step-by-step instructions for converting existing admin pages to use the new logic file structure. After conversion, admin pages will have separated business logic (in `/adm/logic/`) and presentation/display code (in the view files).

## Core Principles

1. **Logic files** handle all:
   - Database operations (loading, saving, deleting)
   - Form processing and validation
   - Permission checks
   - Data preparation for views
   - Redirects after operations
   - Session and settings access

2. **View files** handle only:
   - HTML structure and display
   - Calling the logic function via `process_logic()`
   - Rendering data received from logic
   - FormWriter display (but not processing)

## File Structure After Conversion

```
/adm/
  ├── admin_users.php (view - display only)
  └── logic/
      └── admin_users_logic.php (business logic)
```

## Step-by-Step Conversion Process

### IMPORTANT: Code Preservation Principle

**When converting admin pages, the existing code should be moved EXACTLY as-is wherever possible. DO NOT rewrite, refactor, or "improve" the code during conversion.** The goal is separation of logic and display, not code refactoring. This ensures:
- Existing functionality is preserved exactly
- No new bugs are introduced
- The conversion can be tested by verifying identical behavior
- Future refactoring can be done separately with proper testing

Only make minimal changes necessary for the separation:
- Moving code blocks to the appropriate file (logic vs view)
- Changing `$_GET`/`$_POST` to `$get_vars`/`$post_vars` in logic files
- Using `LogicResult::redirect()` instead of direct `header()` calls
- Adding variables to `$page_vars` array for passing to views

### Step 1: Analyze the Existing Admin Page

1. Open the admin page to be converted (e.g., `/adm/admin_users.php`)
2. Identify and categorize the code sections:
   - **Setup code** (requires, session checks, permissions)
   - **GET parameter processing** (offset, sort, search terms)
   - **POST/action processing** (form submissions, deletions, updates)
   - **Data loading** (database queries, Multi class usage)
   - **Display preparation** (building arrays, formatting data)
   - **HTML output** (headers, tables, forms)

### Step 2: Create the Logic File

Create a new file in `/adm/logic/` with naming pattern: `[page_name]_logic.php`

Example: `/adm/logic/admin_users_logic.php`

#### Logic File Template:

```php
<?php
// IMPORTANT: Logic files MUST require PathHelper because they are not accessed
// through serve.php's front controller. They are directly included by view files,
// so they don't get automatic PathHelper loading.
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_users_logic($get_vars, $post_vars) {
    // Required includes (PathHelper is now available from the require above)
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getIncludePath('includes/Pager.php'));

    // Data class includes
    require_once(PathHelper::getIncludePath('data/users_class.php'));
    // Add other required data classes

    // Get singletons (NO require needed - these are always pre-loaded)
    // Globalvars, SessionControl, DbConnector, ThemeHelper, PluginHelper are ALWAYS available
    $settings = Globalvars::get_instance();
    $session = SessionControl::get_instance();

    // Permission check
    $session->check_permission(5); // Or appropriate level
    $session->set_return();

    // Initialize page variables
    $page_vars = array();
    $page_vars['settings'] = $settings;
    $page_vars['session'] = $session;

    // Process actions/POST data FIRST (before loading data)
    // IMPORTANT: Copy the existing action processing code here EXACTLY as-is
    // Just change $_POST to $post_vars, $_GET to $get_vars, and header() to LogicResult::redirect()
    if (isset($post_vars['action']) || isset($get_vars['action'])) {
        $action = $post_vars['action'] ?? $get_vars['action'] ?? null;

        switch ($action) {
            case 'delete':
                // [COPY EXACT DELETE CODE FROM ORIGINAL FILE HERE]
                // Just change: header("Location: /admin/admin_users");
                // To: return LogicResult::redirect('/admin/admin_users?msg=deleted');
                break;

            case 'save':
                // [COPY EXACT SAVE CODE FROM ORIGINAL FILE HERE]
                // Just change: header("Location: /admin/admin_users");
                // To: return LogicResult::redirect('/admin/admin_users?msg=saved');
                break;
        }
    }

    // Process GET parameters for listing/filtering
    $numperpage = 30;
    $offset = LibraryFunctions::fetch_variable_local($get_vars, 'offset', 0);
    $sort = LibraryFunctions::fetch_variable_local($get_vars, 'sort', 'user_id');
    $sdirection = LibraryFunctions::fetch_variable_local($get_vars, 'sdirection', 'DESC');
    $searchterm = LibraryFunctions::fetch_variable_local($get_vars, 'searchterm', '');

    // Build search criteria
    $search_criteria = array();
    // Add search logic here

    // Load data
    $users = new MultiUser(
        $search_criteria,
        array($sort => $sdirection),
        $numperpage,
        $offset
    );
    $numrecords = $users->count_all();
    $users->load();

    // Prepare data for view
    $page_vars['users'] = $users;
    $page_vars['numrecords'] = $numrecords;
    $page_vars['numperpage'] = $numperpage;
    $page_vars['offset'] = $offset;
    $page_vars['sort'] = $sort;
    $page_vars['sdirection'] = $sdirection;
    $page_vars['searchterm'] = $searchterm;

    // Add any additional data needed by view
    $page_vars['headers'] = array("User", "Email", "Signup Date", "Status");
    $page_vars['sortoptions'] = array(
        "User ID" => "user_id",
        "Last Name" => "last_name",
        "First Name" => "first_name"
    );

    // Return data for rendering
    return LogicResult::render($page_vars);
}
?>
```

### Step 3: Convert the View File

Update the original admin page to be a view-only file:

#### View File Template:

```php
<?php
// NO need to require PathHelper - admin pages are accessed through serve.php
// PathHelper, Globalvars, SessionControl, DbConnector, ThemeHelper, PluginHelper are ALWAYS available

// Include the logic file
require_once(PathHelper::getIncludePath('adm/logic/admin_users_logic.php'));

// Process the logic and get page variables (process_logic is from LibraryFunctions which is always available)
$page_vars = process_logic(admin_users_logic($_GET, $_POST));

// Extract commonly used variables for convenience
$session = $page_vars['session'];
$settings = $page_vars['settings'];
$users = $page_vars['users'];

// AdminPage setup (display only) - AdminPage is NOT pre-loaded, so we need to require it
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
$page = new AdminPage();

// Display header
$page->admin_header(array(
    'menu-id' => 'users-list',
    'page_title' => 'Users',
    'readable_title' => 'Users',
    'breadcrumbs' => array('All Users' => ''),
    'session' => $session,
));

// Setup pager - Pager class should already be loaded by the logic file
// If not already loaded by logic, require it here:
// require_once(PathHelper::getIncludePath('includes/Pager.php'));
$pager = new Pager(array(
    'numrecords' => $page_vars['numrecords'],
    'numperpage' => $page_vars['numperpage']
));

// Display table
$table_options = array(
    'sortoptions' => $page_vars['sortoptions'],
    'title' => $page_vars['searchterm'] ? 'Users matching "'.$page_vars['searchterm'].'"' : 'User list',
    'search_on' => TRUE
);

$page->tableheader($page_vars['headers'], $table_options, $pager);

// Display rows
// IMPORTANT: Copy the display code EXACTLY from the original file
// The display logic should remain unchanged - just using data from $page_vars
foreach ($users as $user) {
    $rowvalues = array();

    // [COPY EXACT ROW BUILDING CODE FROM ORIGINAL FILE HERE]
    array_push($rowvalues, "<a href='/admin/admin_user?usr_user_id={$user->key}'>".$user->display_name()."</a>");
    array_push($rowvalues, $user->get('usr_email'));
    array_push($rowvalues, LibraryFunctions::convert_time($user->get('usr_signup_date'), "UTC", $session->get_timezone(), 'M j, Y'));
    array_push($rowvalues, $user->get('usr_email_is_verified') ? 'Verified' : 'Unverified');

    $page->disprow($rowvalues);
}

$page->endtable($pager);
$page->admin_footer();
?>
```

### Step 4: Handle Complex Form Processing

For pages with forms (like admin_user.php with multiple actions):

#### Logic File with Form Processing:

```php
function admin_user_logic($get_vars, $post_vars) {
    // ... setup code ...

    // Get user ID from request
    $user_id = $get_vars['usr_user_id'] ?? null;
    if (!$user_id) {
        return LogicResult::error('User ID is required');
    }

    $user = new User($user_id, TRUE);
    if (!$user->get('usr_id')) {
        header("HTTP/1.0 404 Not Found");
        return LogicResult::error('User not found');
    }

    // Handle different POST actions
    if ($post_vars) {
        $action = $post_vars['action'] ?? null;

        switch ($action) {
            case 'add_to_group':
                $group = new Group($post_vars['grp_group_id'], TRUE);
                $group->add_member($user->key);
                return LogicResult::redirect('/admin/admin_user?usr_user_id=' . $user->key);

            case 'remove_from_group':
                $groupmember = new GroupMember($post_vars['grm_group_member_id'], TRUE);
                $groupmember->remove();
                return LogicResult::redirect('/admin/admin_user?usr_user_id=' . $user->key);

            // Add other cases
        }
    }

    // Handle GET actions (like delete)
    $action = $get_vars['action'] ?? null;
    if ($action) {
        switch ($action) {
            case 'delete':
                $user->soft_delete();
                return LogicResult::redirect('/admin/admin_users');

            case 'undelete':
                $user->undelete();
                return LogicResult::redirect('/admin/admin_user?usr_user_id=' . $user->key);
        }
    }

    // Load all related data
    $page_vars['user'] = $user;
    $page_vars['phone_numbers'] = load_phone_numbers($user->key);
    $page_vars['addresses'] = load_addresses($user->key);
    // ... load other related data ...

    return LogicResult::render($page_vars);
}
```

### Step 5: Handle Special Response Types (Rare in Admin Pages)

Most admin pages don't handle AJAX, but if you find code that returns JSON or other special responses:

```php
// Example: If the original code has something like this (from admin_product_edit.php):
if($_POST['json_confirm']){
    echo json_encode($product->key);
    exit();
}

// In the logic file, preserve it exactly:
function admin_product_edit_logic($get_vars, $post_vars) {
    // ... process the form ...

    if($post_vars['json_confirm']){
        echo json_encode($product->key);
        exit();
    }

    // Normal redirect for non-JSON requests
    return LogicResult::redirect('/admin/admin_product?pro_product_id=' . $product->key);
}
```

**Note:** True AJAX requests in this system typically go to dedicated handlers in `/ajax/` directory, not admin pages. If you need to create new AJAX functionality, consider creating a separate AJAX handler instead of mixing it into admin pages.

### Step 6: Testing Checklist

After conversion, test each page for:

1. **Permission checks** - Verify users without proper permissions are redirected
2. **Data loading** - All data displays correctly
3. **Sorting** - Table sorting works
4. **Searching** - Search functionality works
5. **Pagination** - Paging through results works
6. **Form submissions** - All POST operations work correctly
7. **Redirects** - After operations, redirects go to correct pages
8. **Error handling** - Invalid IDs, missing data handled gracefully
9. **Messages** - Success/error messages display properly

### Step 7: Common Patterns Reference

#### Pattern 1: List Pages (admin_users.php, admin_groups.php)
- Process search/filter parameters
- Load data with Multi classes
- Return data for table display

#### Pattern 2: Detail Pages (admin_user.php, admin_event.php)
- Load single record by ID
- Load related data
- Handle multiple sub-actions
- Display detailed information

#### Pattern 3: Edit Forms (admin_user_edit.php, admin_group_edit.php)
- Load existing record or create new
- Process POST data for save
- Validate and save
- Redirect after save

#### Pattern 4: Settings Pages (admin_settings.php)
- Load configuration
- Process multiple form fields
- Save to settings table
- Show success message

## Complete Admin File Conversion Ranking

### Difficulty Levels Explained

- **⭐ EASIEST**: Simple display pages, no forms, minimal logic
- **⭐⭐ EASY**: Basic list pages with simple filtering/sorting
- **⭐⭐⭐ MODERATE**: Simple forms, basic CRUD operations
- **⭐⭐⭐⭐ COMPLEX**: Multiple actions, complex forms, related data
- **⭐⭐⭐⭐⭐ VERY COMPLEX**: Multiple forms, file handling, external APIs, special processing

### Complete File Ranking

#### ⭐ EASIEST - Start Here! (Display Only)
These pages primarily display information with no or minimal user interaction:

1. **admin_utilities.php** - Static utility links page
2. **admin_help.php** - Help/documentation page
3. **admin_activate.php** - Simple activation action
4. **admin_user_login_as.php** - Simple login switch
5. **admin_softdelete.php** - Simple delete action
6. **admin_email_view.php** - Display single email
7. **admin_form_error.php** - Display error details
8. **admin_debug_email_log.php** - Display log entry

#### ⭐⭐ EASY - Simple Lists
Basic list pages with standard table display, search, and sorting:

9. **admin_contact_types.php** - Simple list with search
10. **admin_event_types.php** - Simple list
11. **admin_product_groups.php** - Simple list
12. **admin_locations.php** - Simple list
13. **admin_questions.php** - Basic Q&A list
14. **admin_api_keys.php** - API keys list
15. **admin_mailing_lists.php** - Mailing lists
16. **admin_coupon_codes.php** - Coupon list
17. **admin_subscription_tiers.php** - Tier list
18. **admin_comments.php** - Comments list
19. **admin_groups.php** - Groups list
20. **admin_surveys.php** - Surveys list

#### ⭐⭐⭐ MODERATE - Simple Edit Forms
Single-purpose edit pages with straightforward forms:

21. **admin_contact_type_edit.php** - Single field form
22. **admin_group_edit.php** - Basic group form
23. **admin_api_key_edit.php** - API key form
24. **admin_event_type_edit.php** - Event type form
25. **admin_location_edit.php** - Location form
26. **admin_mailing_list_edit.php** - Mailing list form
27. **admin_coupon_code_edit.php** - Coupon form
28. **admin_question_edit.php** - Question form
29. **admin_comment_edit.php** - Comment form
30. **admin_users_password_edit.php** - Password change form
31. **admin_phone_edit.php** - Phone number form
32. **admin_address_edit.php** - Address form
33. **admin_subscription_tier_edit.php** - Tier editing
34. **admin_url_edit.php** - URL redirect form
35. **admin_survey_edit.php** - Survey form
36. **admin_video_edit.php** - Video form
37. **admin_public_menu_edit.php** - Menu item form
38. **admin_admin_menu_edit.php** - Admin menu form

#### ⭐⭐⭐ MODERATE - Detail Pages
Pages showing single record with related data:

39. **admin_api_key.php** - API key details
40. **admin_contact_type.php** - Contact type details
41. **admin_location.php** - Location details
42. **admin_comment.php** - Comment details
43. **admin_group_members.php** - Group member list
44. **admin_mailing_list.php** - List details
45. **admin_coupon_code.php** - Coupon details
46. **admin_question.php** - Question details
47. **admin_survey.php** - Survey details
48. **admin_survey_answers.php** - Survey responses
49. **admin_video.php** - Video details
50. **admin_url.php** - URL redirect details
51. **admin_product_requirement_edit.php** - Requirement form
52. **admin_page.php** - Page details
53. **admin_post.php** - Post details

#### ⭐⭐⭐⭐ COMPLEX - Multi-Table Lists
List pages with complex filtering, multiple data sources:

54. **admin_users.php** - Complex user search/filter
55. **admin_orders.php** - Orders with items
56. **admin_events.php** - Events with multiple filters
57. **admin_products.php** - Products with versions
58. **admin_emails.php** - Email history
59. **admin_pages.php** - CMS pages
60. **admin_posts.php** - Blog posts
61. **admin_files.php** - File manager list
62. **admin_errors.php** - Error log parsing
63. **admin_apache_errors.php** - Apache log parsing
64. **admin_debug_email_logs.php** - Debug logs
65. **admin_shadow_sessions.php** - Session management
66. **admin_event_sessions.php** - Event sessions
67. **admin_survey_users.php** - Survey participants
68. **admin_videos.php** - Video library
69. **admin_urls.php** - URL management
70. **admin_page_contents.php** - Content blocks
71. **admin_email_templates.php** - Template management

#### ⭐⭐⭐⭐ COMPLEX - Advanced Forms
Forms with multiple sections, conditional logic, or complex validation:

72. **admin_product_edit.php** - Complex product form with Stripe
73. **admin_event_edit.php** - Complex event form
74. **admin_users_edit.php** - User profile editing
75. **admin_order_edit.php** - Order modification
76. **admin_order_item_edit.php** - Order item editing
77. **admin_email_edit.php** - Email composer
78. **admin_email_template_edit.php** - Template editor
79. **admin_page_content_edit.php** - Content editor
80. **admin_page_edit.php** - Page editor
81. **admin_post_edit.php** - Post editor
82. **admin_file_edit.php** - File metadata
83. **admin_shadow_session_edit.php** - Session editing
84. **admin_event_session_edit.php** - Event session form
85. **admin_tier_edit.php** - Tier management
86. **admin_product_version_edit.php** - Version editing
87. **admin_product_group_edit.php** - Product grouping
88. **admin_event_bundle_edit.php** - Bundle configuration

#### ⭐⭐⭐⭐⭐ VERY COMPLEX - Multi-Action Pages
Pages with multiple forms, actions, and complex business logic:

89. **admin_user.php** - User details with 10+ sub-sections
90. **admin_order.php** - Order with items, payments, refunds
91. **admin_event.php** - Event with sessions, registrants, emails
92. **admin_product.php** - Product with versions, purchases
93. **admin_email.php** - Email with recipients, tracking
94. **admin_file.php** - File with operations
95. **admin_group_permanent_delete.php** - Cascading deletes
96. **admin_users_permanent_delete.php** - User cleanup
97. **admin_order_delete.php** - Order cancellation logic
98. **admin_email_template_permanent_delete.php** - Template cleanup
99. **admin_page_content_permanent_delete.php** - Content cleanup
100. **admin_post_permanent_delete.php** - Post cleanup

#### ⭐⭐⭐⭐⭐ SPECIAL HANDLING REQUIRED
These require special consideration due to unique features:

101. **admin_settings.php** - Multiple setting categories
102. **admin_settings_email.php** - Email configuration
103. **admin_settings_payments.php** - Payment gateway setup
104. **admin_users_message.php** - Email sending interface
105. **admin_emails_send.php** - Bulk email sender
106. **admin_emails_queue.php** - Email queue management
107. **admin_email_recipients_modify.php** - Recipient management
108. **admin_event_emails.php** - Event email automation
109. **admin_file_upload.php** - File upload handling
110. **admin_file_upload_process.php** - Upload processing
111. **admin_file_delete.php** - File deletion
112. **admin_order_refund.php** - Stripe refund processing
113. **admin_stripe_orders.php** - Stripe integration
114. **admin_stripe_invoices.php** - Stripe invoices
115. **admin_user_payment_methods.php** - Payment methods
116. **admin_user_add.php** - Complex user creation
117. **admin_user_add_bulk.php** - Bulk import
118. **admin_users_undelete.php** - Undelete logic
119. **admin_errors_delete.php** - Log cleanup
120. **admin_email_verify.php** - Verification sending
121. **admin_phone_verify.php** - Phone verification
122. **admin_static_cache.php** - Cache management
123. **admin_plugins.php** - Plugin management
124. **admin_themes.php** - Theme management
125. **admin_admin_menu.php** - Menu builder
126. **admin_public_menu.php** - Public menu builder
127. **admin_message.php** - Messaging system

#### ⭐⭐⭐⭐⭐ ANALYTICS & REPORTS (Unique Processing)
These have special data processing and visualization:

128. **admin_analytics_stats.php** - Statistics with charts
129. **admin_analytics_users.php** - User analytics
130. **admin_analytics_funnels.php** - Conversion funnels
131. **admin_analytics_email_stats.php** - Email metrics
132. **admin_analytics_activitybydate.php** - Activity timeline
133. **admin_yearly_report_donations.php** - Annual reports
134. **admin_survey_user_answers.php** - Survey analysis
135. **admin_event_bundle.php** - Bundle analytics
136. **admin_event_bundles.php** - Bundle management
137. **admin_product_requirements.php** - Requirements analysis
138. **admin_log_event.php** - Event logging

### Recommended Conversion Strategy

1. **Week 1**: Complete all ⭐ EASIEST files (1-8) to get familiar with the process
2. **Week 2-3**: Work through ⭐⭐ EASY list pages (9-20)
3. **Week 4-6**: Handle ⭐⭐⭐ MODERATE forms and details (21-53)
4. **Week 7-9**: Tackle ⭐⭐⭐⭐ COMPLEX pages in groups by feature area
5. **Week 10-12**: Address ⭐⭐⭐⭐⭐ VERY COMPLEX pages with careful testing
6. **Final Phase**: Handle special cases and analytics pages

### Tips for Each Difficulty Level

**For EASIEST**: These are perfect for learning the pattern. Focus on getting the structure right.

**For EASY Lists**: Extract the search/sort logic to the logic file, keep table display in view.

**For MODERATE Forms**: Move all POST processing to logic, keep FormWriter display in view.

**For COMPLEX Pages**: Break down into smaller functions within the logic file if needed.

**For VERY COMPLEX**: Consider creating helper functions in the logic file for different sections. May need multiple test passes.

**For SPECIAL/ANALYTICS**: May need to preserve unique patterns like chart data preparation or file handling.

## Important Notes

1. **Preserve existing code exactly** - Move code as-is, don't refactor during conversion
2. **Never use .php extension in URLs** - All links must use routing system
3. **Always use PathHelper** for includes, never $_SERVER['DOCUMENT_ROOT']
4. **Use LibraryFunctions::fetch_variable_local()** in logic files, not fetch_variable()
5. **Return LogicResult objects** - Never use direct header() redirects in logic
6. **Keep display logic in views** - Date formatting, HTML building stays in view
7. **Test permission levels** - Ensure proper access control after conversion
8. **Handle missing data gracefully** - Check for null/empty before operations
9. **Minimal changes only** - Only change what's necessary for separation (e.g., $_GET to $get_vars)

## Validation After Conversion

Run these checks on each converted file:

1. **PHP Syntax Check:**
   ```bash
   php -l /path/to/admin_page.php
   php -l /path/to/logic/admin_page_logic.php
   ```

2. **Method Existence Check:**
   ```bash
   php /home/user1/joinery/joinery/maintenance_scripts/method_existence_test.php /path/to/file.php
   ```

3. **Manual Testing:**
   - Load page in browser
   - Test all actions and forms
   - Verify data displays correctly
   - Check error handling

## Benefits After Conversion

1. **Separation of Concerns** - Clear distinction between logic and presentation
2. **Testability** - Logic functions can be unit tested
3. **Reusability** - Logic can be called from multiple places (API, CLI, etc.)
4. **Maintainability** - Easier to modify logic without breaking display
5. **Consistency** - All pages follow same pattern
6. **Security** - Centralized permission and validation handling

## Troubleshooting Common Issues

### Issue: "Cannot use object of type LogicResult as array"
**Solution:** Ensure view uses `process_logic()` wrapper

### Issue: Redirect not working
**Solution:** Check for output before LogicResult::redirect(), ensure no echo/print

### Issue: Variable undefined in view
**Solution:** Ensure logic file adds variable to $page_vars array

### Issue: Form not processing
**Solution:** Check logic file handles the specific POST action

### Issue: Permission denied incorrectly
**Solution:** Verify session->check_permission() is in logic, not view

## Final Verification

Each converted admin page should:
- Have all database/business logic in `/adm/logic/[page]_logic.php`
- Have only display code in `/adm/[page].php`
- Use `process_logic()` to call logic function
- Return `LogicResult` objects from logic
- Handle all redirects via `LogicResult::redirect()`
- Process all forms in logic, not view
- Pass all data via `$page_vars` array