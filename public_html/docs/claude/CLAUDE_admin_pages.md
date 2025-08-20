# Admin Pages Documentation

This document provides comprehensive guidance for creating admin interface pages in the joinery platform.

## Required Setup

Every admin page must include these basic requirements:

```php
<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/AdminPage.php');
PathHelper::requireOnce('includes/SessionControl.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/Pager.php');

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
```php
<?php
$formwriter = LibraryFunctions::get_formwriter_object('form_name', 'admin');

$validation_rules = array();
$validation_rules['field_name']['required']['value'] = 'true';
echo $formwriter->set_validate($validation_rules);

echo $formwriter->begin_form('form_name', 'POST', $_SERVER['PHP_SELF']);
echo $formwriter->textinput('Label', 'field_name', 'form-control', 100, $default_value, 'placeholder', 255, 'help text');
echo $formwriter->start_buttons();
echo $formwriter->new_form_button('Submit Text', 'btn btn-primary');
echo $formwriter->end_buttons();
echo $formwriter->end_form();
?>
```

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
    
    // Action links
    $actions = '<a href="admin_item_edit.php?id=' . $item->get('primary_key') . '" class="btn btn-sm btn-primary">Edit</a>';
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

### Data Processing Pattern
```php
// Process form submission
if ($_POST) {
    try {
        // Create or load model
        $item = new ItemClass($id ?? NULL, $id ? TRUE : FALSE);
        
        // Set values from form
        $item->set('field_name', $_POST['field_name']);
        $item->set('other_field', $_POST['other_field']);
        
        // Validate and save
        $item->prepare();
        $item->save();
        
        // Redirect on success
        header('Location: admin_items.php?message=saved');
        exit;
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}
```

### Error Display
```php
<?php if (isset($error_message)): ?>
    <div class="alert alert-danger">
        <?= htmlspecialchars($error_message) ?>
    </div>
<?php endif; ?>

<?php if (isset($_GET['message']) && $_GET['message'] === 'saved'): ?>
    <div class="alert alert-success">
        Item saved successfully!
    </div>
<?php endif; ?>
```

## Advanced Patterns

### Modal/AJAX Integration
```php
// For pages that need JavaScript interaction
echo '<button type="button" class="btn btn-primary" data-toggle="modal" data-target="#editModal">
    Edit Item
</button>';

// Modal structure
echo '<div class="modal fade" id="editModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Item</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <!-- Form content -->
            </div>
        </div>
    </div>
</div>';
```

### Bulk Actions
```php
// Add checkboxes to table rows
$checkbox = '<input type="checkbox" name="selected_ids[]" value="' . $item->get('primary_key') . '">';
array_push($rowvalues, $checkbox);

// Add bulk action form
echo '<form method="post" action="admin_bulk_action.php">
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

### Bootstrap Classes
1. **Use standard Bootstrap classes**: `form-control`, `btn btn-primary`, `alert-info`, etc.
2. **Container structure**: Always wrap content in `<div class="row">` and `<div class="col-12">` or appropriate column classes
3. **No nested panels**: Avoid cards inside cards unless there's a specific UI reason
4. **Responsive design**: Use Bootstrap's responsive utilities (`d-md-block`, `col-md-6`, etc.)

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
- **Statistics cards**: Bootstrap card components with key metrics  
- **Charts**: Include Chart.js or similar for data visualization
- **Time period selectors**: Date range pickers for filtering data

### Settings Pages
- **Tab interface**: Multiple settings categories in Bootstrap tabs
- **Section groups**: Related settings grouped in card components
- **Immediate feedback**: Success/error messages for setting changes

### Log/Monitoring Pages
- **Real-time updates**: JavaScript refresh or WebSocket updates
- **Filtering**: Multiple filter options (date, severity, user, etc.)
- **Export options**: CSV/PDF export functionality
- **Expandable details**: Use `<details>/<summary>` tags or Bootstrap collapse

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
Your admin pages automatically inherit the current admin theme (Falcon/Bootstrap). Follow the existing visual patterns from other admin pages.

### Database Integration
Use the existing model classes and follow the SystemBase patterns. Create new model classes in `/data/` if needed following the established naming conventions.

This documentation should be used alongside the main CLAUDE.md file for complete development guidance.