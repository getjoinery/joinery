# Admin Pages Documentation

This document provides comprehensive guidance for creating admin interface pages in the joinery platform.

## Required Setup

Every admin page must include these basic requirements:

```php
<?php
// PathHelper, Globalvars, SessionControl, DbConnector, ThemeHelper, PluginHelper are guaranteed available

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/Pager.php'));

$session = SessionControl::get_instance();
$session->check_permission(9); // Adjust permission level as needed (5=basic admin, 9=super admin)
$session->set_return();

$page = new AdminPage();
$settings = Globalvars::get_instance();
```

**Critical Notes:**
- **NEVER use `$_SERVER['DOCUMENT_ROOT']`** for includes - always use `PathHelper`
- **Always call `$session->set_return()`** to handle redirects properly
- **Permission levels**: 5 = basic admin access, 9 = super admin access

## Basic Page Structure

### Standard Layout Pattern
```php
$page->admin_header([
    'menu-id' => 'menu-identifier',
    'page_title' => 'Page Title',
    'readable_title' => 'Human Readable Title',
    'breadcrumbs' => NULL,
    'session' => $session,
]);
?>

<div class="row">
    <div class="col-12">
        <h5 class="mb-3">Section Title</h5>
        
        <!-- Page content here -->
        
    </div>
</div>

<?php $page->admin_footer(); ?>
```

### Form Structure with FormWriter

Every admin form must be wrapped in `begin_box()`/`end_box()`. Without the wrapper the form renders with no card-header or card-body, losing the standard padding and visual container.

```php
<?php
$page->begin_box(['title' => 'Edit Item']);

// Get FormWriter V2 instance from the page object
$formwriter = $page->getFormWriter('form_name', 'v2', [
    'model' => $object  // Auto-fills values and applies validation from model
]);

$formwriter->begin_form();

// Field with clean options array
$formwriter->textinput('field_name', 'Label', [
    'placeholder' => 'Enter value',
    'helptext' => 'Help text here',
    'maxlength' => 255
]);

$formwriter->submitbutton('submit', 'Submit Text');
$formwriter->end_form();

$page->end_box();
?>
```

**Note:** Admin pages use `$page->getFormWriter('form_name', 'v2')` which automatically provides FormWriterV2Bootstrap for admin interfaces with automatic CSRF protection, validation, and value filling.

## Table-Based Admin Pages

### Standard Data Table Pattern
For pages that display lists of data (like admin_errors.php), use this pattern:

```php
// Get URL parameters using LibraryFunctions
$numperpage = 30;
$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
$sort = LibraryFunctions::fetch_variable('sort', 'create_time', 0, '');
$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '');

// Load data using MultiModel pattern
$search_criteria = array();
if ($searchterm) {
    $search_criteria['field_name'] = 'LIKE %' . $searchterm . '%';
}

$items = new MultiTableName(
    $search_criteria,
    array($sort => $sdirection),
    $numperpage,
    $offset
);
$numrecords = $items->count_all();
$items->load();

// Set up table
$headers = array("Column 1", "Column 2", "Column 3", "Actions");
$sortoptions = array(
    "Column 1" => "field_name_1",
    "Column 2" => "field_name_2"
);

$pager = new Pager(array('numrecords' => $numrecords, 'numperpage' => $numperpage));
$table_options = array(
    'sortoptions' => $sortoptions,
    'title' => 'Table Title',
    'search_on' => TRUE // or FALSE
);

$page->tableheader($headers, $table_options, $pager);

// Display rows
foreach ($items as $item) {
    $rowvalues = array();
    
    array_push($rowvalues, $item->get('field_1'));
    array_push($rowvalues, $item->get('field_2'));
    array_push($rowvalues, $item->get('field_3'));
    
    // Action links - CRITICAL: Never use .php extension in URLs!
    $actions = '<a href="/admin/admin_item_edit?id=' . $item->get('primary_key') . '" class="btn btn-sm btn-primary">Edit</a>';
    array_push($rowvalues, $actions);
    
    $page->disprow($rowvalues);
}

$page->endtable($pager);
```

### Alternative: File-Based Data Tables
For parsing log files or other non-database sources:

```php
// Parse data from external source (like error logs)
$parser = new DataParser();
$all_data = $parser->getData();

// Paginate manually
$numrecords = count($all_data);
$data = array_slice($all_data, $offset, $numperpage);

// Continue with same table pattern as above
$page->tableheader($headers, $table_options, $pager);

foreach ($data as $item) {
    $rowvalues = array();
    // Build row data...
    $page->disprow($rowvalues);
}

$page->endtable($pager);
```

## Form Handling Best Practices

### Edit Forms with FormWriterV2

When using FormWriterV2 with `edit_primary_key_value`, your logic file must check for this POST field first:

```php
// CRITICAL: Check edit_primary_key_value (form submission) first, fallback to GET
if (isset($post_vars['edit_primary_key_value'])) {
    $item = new Item($post_vars['edit_primary_key_value'], TRUE);
} elseif (isset($get_vars['itm_item_id'])) {
    $item = new Item($get_vars['itm_item_id'], TRUE);
} else {
    $item = new Item(NULL);
}
```

**See [FormWriter Documentation - Edit Forms](formwriter.md#edit-forms-with-edit_primary_key_value)** for complete details on this pattern and why it's required.

### Data Processing Pattern (Logic File)

Admin pages that handle POST actions should use the logic file pattern with session-based messages. **Never pass messages via URL query parameters.**

```php
// In adm/logic/admin_items_logic.php
function admin_items_logic($get_vars, $post_vars) {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

    $session = SessionControl::get_instance();
    $session->check_permission(5);

    // Regex matching the admin page URL (used to target messages)
    $page_regex = '/\/admin\/admin_items/';

    if ($post_vars && isset($post_vars['action'])) {
        $message = null;
        $error = null;

        try {
            $item = new ItemClass($post_vars['item_id'] ?? NULL,
                                  isset($post_vars['item_id']) ? TRUE : FALSE);
            $item->set('field_name', $post_vars['field_name']);
            $item->prepare();
            $item->save();
            $message = 'Item saved successfully.';
        } catch (Exception $e) {
            $error = $e->getMessage();
        }

        // Store messages in session (displayed after redirect)
        if ($message) {
            $session->save_message(new DisplayMessage(
                $message, 'Success', $page_regex,
                DisplayMessage::MESSAGE_ANNOUNCEMENT,
                DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
            ));
        }
        if ($error) {
            $session->save_message(new DisplayMessage(
                $error, 'Error', $page_regex,
                DisplayMessage::MESSAGE_ERROR,
                DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
            ));
        }

        // Always redirect after POST to prevent form resubmission
        return LogicResult::redirect('/admin/admin_items');
    }

    // Retrieve session messages for display
    $display_messages = $session->get_messages('/admin/admin_items');

    return LogicResult::render(array(
        'session' => $session,
        'display_messages' => $display_messages,
    ));
}
```

### Displaying Session Messages (View File)

```php
<?php if (!empty($display_messages)): ?>
    <?php foreach ($display_messages as $msg): ?>
        <?php
        $alert_class = 'alert-info';
        if ($msg->display_type == DisplayMessage::MESSAGE_ERROR) {
            $alert_class = 'alert-danger';
        } elseif ($msg->display_type == DisplayMessage::MESSAGE_WARNING) {
            $alert_class = 'alert-warning';
        } elseif ($msg->display_type == DisplayMessage::MESSAGE_ANNOUNCEMENT) {
            $alert_class = 'alert-success';
        }
        ?>
        <div class="alert <?= $alert_class ?>" role="alert">
            <?php if ($msg->message_title): ?>
                <strong><?= htmlspecialchars($msg->message_title) ?>:</strong>
            <?php endif; ?>
            <?= htmlspecialchars($msg->message) ?>
            <button type="button" class="alert-close" aria-label="Close">&times;</button>
        </div>
    <?php endforeach; ?>
    <?php $session->clear_clearable_messages(); ?>
<?php endif; ?>
```

**Important:** Never pass confirmation or error messages via URL query parameters (e.g., `?message=saved`). Always use the `DisplayMessage` + `$session->save_message()` pattern shown above. This prevents messages from persisting on page refresh and follows the established session message architecture.

## Advanced Patterns

### Options Dropdown (Action Menu)

Admin pages use a built-in options dropdown system. Two patterns:

#### Content Pages - Use `begin_box()` / `end_box()`
```php
// Setup dropdown links for actions
$altlinks = array();
$altlinks['Enable'] = '/admin/admin_page_name?action=enable';
$altlinks['Disable'] = '/admin/admin_page_name?action=disable';

$page->begin_box(array('altlinks' => $altlinks));
// Your content here
$page->end_box();
```

**Note:** Action handling and confirmation messages should be in the logic file using the `DisplayMessage` session pattern (see "Data Processing Pattern" above). Do not handle actions inline or pass messages via URL.

#### Table Pages - Use `tableheader()` with `altlinks`
```php
$altlinks = array();
$altlinks['Add New'] = '/admin/admin_item_edit';
$altlinks['Export'] = '/admin/admin_items?action=export';

$table_options = array(
    'altlinks' => $altlinks,
    'title' => 'Items',
    'search_on' => TRUE
);
$page->tableheader($headers, $table_options, $pager);
```

#### URL Format - CRITICAL
```php
// ✅ CORRECT - No .php extension
$altlinks['Action'] = '/admin/admin_page_name?action=value';

// ❌ WRONG - Breaks routing
$altlinks['Action'] = '/admin/admin_page_name?action=value';
```

### Modal Dialogs

The joinery-system theme provides `JoineryModal`, a vanilla-JS utility built on the native `<dialog>` element. Use it for all confirmation and notification dialogs — never use `window.confirm()` or Bootstrap modals.

#### Three methods

```js
// Confirmation — two buttons, danger-styled by default
JoineryModal.confirm('Delete this record?', function() {
    submitAction();
});

// Alert — one button (OK), primary-styled, no cancel
JoineryModal.alert('Settings saved successfully.');

// Prompt — text input, returns typed value to callback
JoineryModal.prompt('Enter the item name to confirm:', function(value) {
    if (value === expected) submitDelete();
});
```

#### Options

All three methods accept an optional third argument:

| Option | Type | Default | Notes |
|---|---|---|---|
| `confirmLabel` | string | `'Confirm'` / `'OK'` | Label on the action button |
| `cancelLabel` | string | `'Cancel'` | Label on the cancel button (`confirm` and `prompt` only) |
| `confirmStyle` | `'danger'` \| `'primary'` | `'danger'` / `'primary'` | Button color |
| `placeholder` | string | `''` | Input placeholder (`prompt` only) |
| `defaultValue` | string | `''` | Pre-filled input value (`prompt` only) |

```js
// Constructive action — override style and label
JoineryModal.confirm('Apply this update?', function() {
    submitForm();
}, { confirmLabel: 'Apply', confirmStyle: 'primary' });

// Prompt with placeholder
JoineryModal.prompt('Type the plugin name to confirm removal:', function(value) {
    if (value === pluginName) submitUninstall(value);
}, { placeholder: 'plugin-name', confirmLabel: 'Uninstall', confirmStyle: 'danger' });
```

#### Triggering from PHP-generated action links

The standard pattern for PHP-rendered action buttons that need confirmation:

```php
// In a table row or action cell
$plugin_name = htmlspecialchars($plugin['name']);
$warning = "This will permanently delete all data. This cannot be undone.";
$action_cell .= '<a href="javascript:void(0)" onclick="confirmAndDelete(\'' . $plugin_name . '\', \'' . addslashes($warning) . '\')">'
              . 'Delete</a>';
```

```js
function confirmAndDelete(name, message) {
    JoineryModal.confirm(message, function() {
        submitPluginAction('delete', name);
    }, { confirmLabel: 'Delete' });
}
```

#### Form-hosting modals (complex overlays)

When a modal needs to contain real form fields (multiple inputs, selects, checkboxes), use a raw `<dialog>` element directly in the page. The theme's `dialog` CSS rule styles it automatically.

```html
<dialog id="myModal">
    <form method="post">
        <!-- form fields -->
        <div class="dialog-actions">
            <button type="button" onclick="document.getElementById('myModal').close()">Cancel</button>
            <button type="submit" class="dialog-btn-confirm dialog-btn-primary">Save</button>
        </div>
    </form>
</dialog>
```

```js
document.getElementById('myModal').showModal();
document.getElementById('myModal').close();
```

Full API reference: `specs/joinery_modal_api.md`

### Bulk Actions
```php
// Add checkboxes to table rows
$checkbox = '<input type="checkbox" name="selected_ids[]" value="' . $item->get('primary_key') . '">';
array_push($rowvalues, $checkbox);

// Add bulk action form
echo '<form method="post" action="/admin/admin_bulk_action">
    <div class="form-row align-items-center mb-3">
        <div class="col-auto">
            <select name="bulk_action" class="form-control">
                <option value="">Select Action...</option>
                <option value="delete">Delete Selected</option>
                <option value="activate">Activate Selected</option>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-secondary">Apply</button>
        </div>
    </div>';
```

## Layout Rules and Best Practices

### CSS Classes
1. **Use theme CSS classes**: `form-control`, `btn btn-primary`, `alert-info`, etc. — joinery-system provides its own implementations of these common utility classes
2. **Container structure**: Always wrap content in `<div class="row">` and `<div class="col-12">` or appropriate column classes
3. **No nested panels**: Avoid cards inside cards unless there's a specific UI reason
4. **Responsive design**: Use column classes (`col-md-4`, `col-md-6`, `col-md-8`, `col-12`) — joinery-system implements a standard subset of the grid

### Form Best Practices
1. **Always use FormWriter**: Never create forms manually - always use the FormWriter system
2. **Prefer FormWriter validation**: Use built-in FormWriter validation over custom JavaScript
3. **Consistent styling**: Use `form-control` class for all inputs
4. **Help text**: Include helpful placeholder text and validation messages

### Security Considerations
1. **Permission checks**: Always validate user permissions at the top of the page
2. **CSRF protection**: FormWriter automatically includes CSRF tokens
3. **Input sanitization**: Use `htmlspecialchars()` for output, FormWriter handles input sanitization
4. **SQL injection prevention**: Always use model methods or prepared statements

### Performance Tips
1. **Pagination**: Always paginate large datasets using the Pager class
2. **Efficient queries**: Use count_all() for pagination instead of loading all records
3. **Caching**: Consider caching expensive operations (file parsing, API calls)
4. **Lazy loading**: Load related data only when needed

## Common Patterns by Page Type

### CRUD Pages
- **List page**: Uses tableheader/disprow/endtable pattern with search and sorting
- **Edit page**: Uses FormWriter with model load/save pattern
- **Delete page**: Simple confirmation form with model deletion

### Dashboard/Analytics Pages
- **Statistics cards**: Card layout with key metrics using `.card` / `.card-header` / `.card-body`
- **Charts**: Include Chart.js or similar for data visualization
- **Time period selectors**: Date range pickers for filtering data

### Settings Pages
- **Tab interface**: Multiple settings categories using a custom CSS tab structure or `<details>` groups
- **Section groups**: Related settings grouped in card components
- **Immediate feedback**: Success/error messages for setting changes

### Log/Monitoring Pages
- **Real-time updates**: JavaScript refresh or WebSocket updates
- **Filtering**: Multiple filter options (date, severity, user, etc.)
- **Export options**: CSV/PDF export functionality
- **Expandable details**: Use `<details>/<summary>` tags

## File Naming Conventions

- **List pages**: `admin_[items].php` (e.g., `admin_users.php`)
- **Single item pages**: `admin_[item].php` (e.g., `admin_user.php`)
- **Edit pages**: `admin_[item]_edit.php` (e.g., `admin_user_edit.php`)
- **Action pages**: `admin_[item]_[action].php` (e.g., `admin_user_delete.php`)
- **Special functions**: `admin_[descriptive_name].php` (e.g., `admin_errors_file.php`)

## Testing Your Admin Pages

1. **Permission levels**: Test with different user permission levels
2. **Form validation**: Test both client-side and server-side validation
3. **Error handling**: Test with invalid data and server errors
4. **Pagination**: Test with large datasets and edge cases
5. **Mobile responsiveness**: Test on different screen sizes
6. **Performance**: Test with realistic data volumes

## Integration with Existing System

### Menu Integration
Add your page to the admin menu by updating the appropriate menu configuration. Check existing admin pages for the correct `menu-id` to use.

### Consistent Styling
Your admin pages automatically inherit the joinery-system admin theme. Follow the existing visual patterns from other admin pages.

### Database Integration
Use the existing model classes and follow the SystemBase patterns. Create new model classes in `/data/` if needed following the established naming conventions.

This documentation should be used alongside the main CLAUDE.md file for complete development guidance.