# Admin Pages

Reference for building admin interface pages on the Joinery platform. Admin pages follow the logic/view split: business logic lives in `/adm/logic/admin_*_logic.php` and returns a `LogicResult`; presentation lives in `/adm/admin_*.php` and renders through `AdminPage`.

The canonical reference implementation is `/adm/admin_user.php` and `/adm/logic/admin_user_logic.php`.

## Table of Contents

1. [Core Principles](#core-principles)
2. [File Structure & Naming](#file-structure--naming)
3. [Logic File Pattern](#logic-file-pattern)
4. [View File Pattern](#view-file-pattern)
5. [Common Patterns by Page Type](#common-patterns-by-page-type)
6. [Advanced Patterns](#advanced-patterns)
7. [Layout & CSS Rules](#layout--css-rules)
8. [Best Practices](#best-practices)
9. [Troubleshooting](#troubleshooting)
10. [Testing Checklist](#testing-checklist)

## Core Principles

1. **Separation of concerns** — business logic in `/adm/logic/`, presentation in `/adm/`.
2. **LogicResult pattern** — logic functions return a `LogicResult` (`render`, `redirect`, or `error`).
3. **`process_logic()` in views** — views wrap the logic call so redirects and errors are handled centrally.
4. **POST → redirect** — admin actions always redirect after POST; messages flow via session, never via query strings.
5. **No `.php` in URLs** — all admin URLs are served through the front controller; the `.php` extension breaks routing.

See [Logic Architecture](logic_architecture.md) for the broader pattern.

## File Structure & Naming

```
/adm/
  ├── admin_user.php                  # View — display only
  └── logic/
      └── admin_user_logic.php        # Business logic
```

Logic files always live in `/adm/logic/` (no subdirectories). Plugin admin pages mirror this in `/plugins/{plugin}/admin/logic/`.

### Naming Conventions

| Page Type      | View                      | Logic                              |
|----------------|---------------------------|-------------------------------------|
| List page      | `admin_users.php`         | `admin_users_logic.php`             |
| Detail page    | `admin_user.php`          | `admin_user_logic.php`              |
| Edit form      | `admin_user_edit.php`     | `admin_user_edit_logic.php`         |
| Delete action  | `admin_user_delete.php`   | `admin_user_delete_logic.php`       |
| Other actions  | `admin_user_{action}.php` | `admin_user_{action}_logic.php`     |

## Logic File Pattern

### Complete Template

```php
<?php
// Logic files require PathHelper directly. Views get it from serve.php's front
// controller, but logic files are included by views — by the time PHP parses
// the require below, PathHelper is already loaded — but keep this for
// defensive consistency with the rest of the codebase.
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_page_logic($get_vars, $post_vars) {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getIncludePath('includes/Pager.php'));
    require_once(PathHelper::getIncludePath('data/users_class.php'));

    // Globalvars, SessionControl, DbConnector, ThemeHelper, PluginHelper are
    // always pre-loaded — never require them.
    $settings = Globalvars::get_instance();
    $session = SessionControl::get_instance();

    $session->check_permission(5);  // 5=admin, 9=super admin, 10=full
    $session->set_return();

    $page_vars = array();
    $page_vars['settings'] = $settings;
    $page_vars['session'] = $session;

    // Process actions BEFORE loading display data.
    if (isset($post_vars['action']) || isset($get_vars['action'])) {
        $action = $post_vars['action'] ?? $get_vars['action'] ?? null;
        switch ($action) {
            case 'delete':
                $item = new Item($get_vars['item_id'], TRUE);
                $item->soft_delete();
                return LogicResult::redirect('/admin/admin_items');

            case 'save':
                $item = new Item($post_vars['item_id'] ?? NULL);
                $item->set('field_name', $post_vars['field_name']);
                $item->prepare();
                $item->save();
                return LogicResult::redirect('/admin/admin_item?item_id=' . $item->key);
        }
    }

    // Load data for display.
    $items = new MultiItem(
        array('deleted' => false),
        array('item_id' => 'DESC'),
        30, 0
    );
    $numrecords = $items->count_all();
    $items->load();

    $page_vars['items'] = $items;
    $page_vars['numrecords'] = $numrecords;

    return LogicResult::render($page_vars);
}
?>
```

### Section Notes

- **Permission levels**: `5` = basic admin, `7` = higher admin, `9` = super admin, `10` = full system admin. `check_permission()` redirects unauthorized users automatically.
- **Always include `$settings` and `$session`** in `$page_vars`.
- **Process actions before loading data** — avoids wasted queries when the path ends in a redirect.
- **Always redirect after POST** — return `LogicResult::redirect(...)` so refresh doesn't re-submit.

### Return Types

```php
return LogicResult::render($page_vars);  // Normal display
return LogicResult::redirect('/path');   // After action
return LogicResult::error('Error msg');  // Failure (404, etc.)
```

## View File Pattern

### Complete Template

```php
<?php
// PathHelper, Globalvars, SessionControl, DbConnector, ThemeHelper,
// PluginHelper are always pre-loaded — never require them.

require_once(PathHelper::getIncludePath('adm/logic/admin_page_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/Pager.php'));

$page_vars = process_logic(admin_page_logic($_GET, $_POST));

$session = $page_vars['session'];
$settings = $page_vars['settings'];
$items = $page_vars['items'];
$numrecords = $page_vars['numrecords'];

$page = new AdminPage();
$page->admin_header(array(
    'menu-id' => 'items-list',
    'page_title' => 'Items',
    'readable_title' => 'Item List',
    'breadcrumbs' => array('All Items' => ''),
    'session' => $session,
));

$pager = new Pager(array('numrecords' => $numrecords, 'numperpage' => 30));
$headers = array('Name', 'Date', 'Actions');
$table_options = array('title' => 'Items', 'search_on' => TRUE);

$page->tableheader($headers, $table_options, $pager);
foreach ($items as $item) {
    $row = array();
    array_push($row, htmlspecialchars($item->get('name')));
    array_push($row, LibraryFunctions::convert_time($item->get('created'), 'UTC', $session->get_timezone()));
    array_push($row, '<a href="/admin/admin_item_edit?id=' . $item->key . '">Edit</a>');
    $page->disprow($row);
}
$page->endtable($pager);

$page->admin_footer();
?>
```

### `process_logic()` Behaviour

1. Calls the logic function.
2. If it returns `LogicResult::redirect(...)`, performs the redirect and exits.
3. If it returns `LogicResult::error(...)`, displays the error or throws.
4. Otherwise returns the `$page_vars` array from `LogicResult::render()`.

### `admin_header()` Options

| Option           | Notes                                              |
|------------------|----------------------------------------------------|
| `menu-id`        | For sidebar highlighting                           |
| `page_title`     | Browser `<title>`                                  |
| `readable_title` | Page H1                                            |
| `breadcrumbs`    | `['Label' => '/url', ...]`; empty string = current |
| `session`        | Required                                           |
| `no_page_card`   | Skip the surrounding card wrapper                  |
| `header_action`  | Action button or dropdown HTML                     |

## Common Patterns by Page Type

### List Page

```php
// Logic
$numperpage = 30;
$offset = LibraryFunctions::fetch_variable_local($get_vars, 'offset', 0);
$sort = LibraryFunctions::fetch_variable_local($get_vars, 'sort', 'user_id');
$sdirection = LibraryFunctions::fetch_variable_local($get_vars, 'sdirection', 'DESC');
$searchterm = LibraryFunctions::fetch_variable_local($get_vars, 'searchterm', '');

$criteria = array('deleted' => false);
if ($searchterm) $criteria['search'] = $searchterm;

$users = new MultiUser($criteria, array($sort => $sdirection), $numperpage, $offset);
$numrecords = $users->count_all();
$users->load();

$page_vars['users'] = $users;
$page_vars['numrecords'] = $numrecords;
$page_vars['numperpage'] = $numperpage;
$page_vars['sortoptions'] = array('Name' => 'last_name', 'Email' => 'email');
```

```php
// View
$pager = new Pager(array('numrecords' => $numrecords, 'numperpage' => $numperpage));
$page->tableheader(
    array('Name', 'Email', 'Signup Date'),
    array('sortoptions' => $page_vars['sortoptions'], 'title' => 'Users', 'search_on' => TRUE),
    $pager
);

foreach ($users as $user) {
    $row = array();
    array_push($row, '<a href="/admin/admin_user?usr_user_id=' . $user->key . '">' . $user->display_name() . '</a>');
    array_push($row, htmlspecialchars($user->get('usr_email')));
    array_push($row, LibraryFunctions::convert_time($user->get('usr_signup_date'), 'UTC', $session->get_timezone(), 'M j, Y'));
    $page->disprow($row);
}
$page->endtable($pager);
```

### Detail Page

Loads one main record and any related collections. Handles multiple POST actions via an `action` field. See `/adm/logic/admin_user_logic.php` for a worked example covering add/remove group, add/remove event, multiple related tables, and a custom altlinks dropdown.

```php
// Logic — sketch
$user_id = $get_vars['usr_user_id'] ?? null;
if (!$user_id) return LogicResult::error('User ID is required');

$user = new User($user_id, TRUE);
if (!$user->get('usr_id')) {
    header('HTTP/1.0 404 Not Found');
    return LogicResult::error('User not found');
}

if ($post_vars) {
    switch ($post_vars['action']) {
        case 'add_to_group':
            $group = new Group($post_vars['grp_group_id'], TRUE);
            $group->add_member($user->key);
            return LogicResult::redirect('/admin/admin_user?usr_user_id=' . $user->key);
    }
}

// Load related data...
$page_vars['user'] = $user;
return LogicResult::render($page_vars);
```

### Edit Form

```php
// Logic
$user_id = $get_vars['usr_user_id'] ?? null;
$user = new User($user_id ?? NULL, $user_id ? TRUE : FALSE);

if ($post_vars) {
    try {
        $user->set('usr_first_name', $post_vars['usr_first_name']);
        $user->set('usr_last_name',  $post_vars['usr_last_name']);
        $user->set('usr_email',      $post_vars['usr_email']);
        $user->prepare();
        $user->save();
        return LogicResult::redirect('/admin/admin_user?usr_user_id=' . $user->key);
    } catch (Exception $e) {
        $page_vars['error_message'] = $e->getMessage();
    }
}
```

When using FormWriterV2's `edit_primary_key_value` hidden field, **check the POST field first**, then fall back to GET:

```php
if (isset($post_vars['edit_primary_key_value'])) {
    $item = new Item($post_vars['edit_primary_key_value'], TRUE);
} elseif (isset($get_vars['itm_item_id'])) {
    $item = new Item($get_vars['itm_item_id'], TRUE);
} else {
    $item = new Item(NULL);
}
```

See [FormWriter — Edit Forms](formwriter.md#edit-forms-with-edit_primary_key_value).

```php
// View — wrap in begin_box / end_box so the form gets the standard card chrome
$page->begin_box(array('title' => 'Edit User'));

$formwriter = $page->getFormWriter('form1', 'v2', array('model' => $user));
$formwriter->begin_form();

// Fields prefixed with the model's column prefix (e.g. usr_) pick up
// validation from the model automatically.
$formwriter->textinput('usr_first_name', 'First Name');
$formwriter->textinput('usr_last_name',  'Last Name');
$formwriter->textinput('usr_email',      'Email');

$formwriter->submitbutton('submit', 'Save User');
$formwriter->end_form();

$page->end_box();
```

### Delete Confirmation

```php
// Logic
$user = new User($get_vars['usr_user_id'], TRUE);
if ($post_vars && ($post_vars['confirm'] ?? '') === 'yes') {
    $user->soft_delete();
    return LogicResult::redirect('/admin/admin_users');
}
$page_vars['user'] = $user;
```

```php
// View
?>
<div class="alert alert-warning">
    <p>Delete <strong><?= htmlspecialchars($user->display_name()) ?></strong>?</p>
    <form method="POST" action="/admin/admin_user_delete?usr_user_id=<?= $user->key ?>">
        <input type="hidden" name="confirm" value="yes" />
        <button type="submit" class="btn btn-danger">Yes, delete</button>
        <a href="/admin/admin_user?usr_user_id=<?= $user->key ?>" class="btn btn-secondary">Cancel</a>
    </form>
</div>
```

### Dashboard / Analytics / Settings / Logs

These don't need their own pattern — they're composed from the basics above:

- **Dashboard**: Stat cards (`.card` / `.card-header` / `.card-body`), Chart.js or similar, date-range selectors.
- **Settings**: Tabs or `<details>` groups; group related fields into card-style sections; show success/error after save.
- **Log/Monitoring**: Filter inputs feed `MultiX` criteria; `<details>` / `<summary>` for expandable rows; CSV export via a `?action=export` branch in the logic.

## Advanced Patterns

### Options Dropdown (Action Menu)

Both content pages and table pages support an `altlinks` dropdown — a small menu of secondary actions.

```php
// Content pages — pass to begin_box()
$altlinks = array(
    'Enable'  => '/admin/admin_page_name?action=enable',
    'Disable' => '/admin/admin_page_name?action=disable',
);
$page->begin_box(array('altlinks' => $altlinks));
// content
$page->end_box();
```

```php
// Table pages — pass via tableheader() options
$altlinks = array(
    'Add New' => '/admin/admin_item_edit',
    'Export'  => '/admin/admin_items?action=export',
);
$page->tableheader($headers, array(
    'altlinks' => $altlinks,
    'title' => 'Items',
    'search_on' => TRUE,
), $pager);
```

Action handling and the resulting flash message belong in the logic file (see the [DisplayMessage pattern](#displaymessage-flash-messages) below). Never handle actions inline in the view and never pass messages through query strings.

### DisplayMessage Flash Messages

```php
// In the logic file — after a successful save/delete
$page_regex = '/\/admin\/admin_items/';

if ($message) {
    $session->save_message(new DisplayMessage(
        $message, 'Success', $page_regex,
        DisplayMessage::MESSAGE_ANNOUNCEMENT,
        DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
    ));
}
return LogicResult::redirect('/admin/admin_items');

// Earlier in the same logic function, on the GET path
$display_messages = $session->get_messages('/admin/admin_items');
$page_vars['display_messages'] = $display_messages;
```

```php
// View — render and clear
if (!empty($display_messages)) {
    foreach ($display_messages as $msg) {
        $alert_class = 'alert-info';
        if ($msg->display_type == DisplayMessage::MESSAGE_ERROR)        $alert_class = 'alert-danger';
        elseif ($msg->display_type == DisplayMessage::MESSAGE_WARNING)  $alert_class = 'alert-warning';
        elseif ($msg->display_type == DisplayMessage::MESSAGE_ANNOUNCEMENT) $alert_class = 'alert-success';
        ?>
        <div class="alert <?= $alert_class ?>">
            <?php if ($msg->message_title): ?><strong><?= htmlspecialchars($msg->message_title) ?>:</strong><?php endif; ?>
            <?= htmlspecialchars($msg->message) ?>
        </div>
        <?php
    }
    $session->clear_clearable_messages();
}
```

### Modal Dialogs (JoineryModal)

The joinery-system admin theme ships `JoineryModal`, a vanilla-JS utility built on the native `<dialog>` element. Use it for confirmations and notifications — never `window.confirm()` or Bootstrap modals.

```js
// Confirmation (two buttons, danger-styled by default)
JoineryModal.confirm('Delete this record?', function() { submitAction(); });

// Alert (one OK button, primary-styled)
JoineryModal.alert('Settings saved successfully.');

// Prompt (text input)
JoineryModal.prompt('Enter the item name to confirm:', function(value) {
    if (value === expected) submitDelete();
});
```

All three accept an options object:

| Option         | Type                       | Default                   | Notes                                |
|----------------|----------------------------|---------------------------|--------------------------------------|
| `confirmLabel` | string                     | `'Confirm'` / `'OK'`      | Label on the action button           |
| `cancelLabel`  | string                     | `'Cancel'`                | `confirm` and `prompt` only          |
| `confirmStyle` | `'danger'` \| `'primary'`  | `'danger'` / `'primary'`  | Button color                         |
| `placeholder`  | string                     | `''`                      | `prompt` only                        |
| `defaultValue` | string                     | `''`                      | `prompt` only                        |

```js
// Constructive action
JoineryModal.confirm('Apply this update?', function() {
    submitForm();
}, { confirmLabel: 'Apply', confirmStyle: 'primary' });
```

**From PHP-rendered action links:**

```php
$plugin_name = htmlspecialchars($plugin['name']);
$warning = 'This will permanently delete all data. This cannot be undone.';
$action_cell .= '<a href="javascript:void(0)" onclick="confirmAndDelete(\'' . $plugin_name . '\', \'' . addslashes($warning) . '\')">Delete</a>';
```

```js
function confirmAndDelete(name, message) {
    JoineryModal.confirm(message, function() {
        submitPluginAction('delete', name);
    }, { confirmLabel: 'Delete' });
}
```

**Form-hosting modals** — when a modal needs real form fields, use a raw `<dialog>` element; the theme styles it automatically:

```html
<dialog id="myModal">
    <form method="post">
        <!-- fields -->
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

Full API: `specs/joinery_modal_api.md`.

### Bulk Actions

```php
// Add a checkbox column to each row
$checkbox = '<input type="checkbox" name="selected_ids[]" value="' . $item->get('primary_key') . '">';
array_push($row, $checkbox);

// Wrap the table in a form with an action selector
?>
<form method="post" action="/admin/admin_bulk_action">
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
    </div>
    <!-- table here -->
</form>
```

## Layout & CSS Rules

The admin theme (joinery-system) is vanilla HTML5 with a small set of utility classes that mirror common Bootstrap names (`form-control`, `btn`, `alert-*`, grid columns) — these are theme-provided, not Bootstrap. Don't pull in Bootstrap.

- Wrap content in `<div class="row">` / `<div class="col-12">` (or appropriate column sizes).
- Don't nest cards inside cards unless there's a real reason.
- Responsive columns: `col-md-4`, `col-md-6`, `col-md-8`, `col-12`.
- Use `form-control` on inputs, `btn btn-primary` / `btn btn-secondary` / `btn btn-danger` on buttons.

## Best Practices

### Code Organization

✅ Logic file owns all business logic, data loading, action handling. View renders only.
❌ Don't load data in the view (except trivial display-specific lookups).
❌ Don't use generic variable names like `$data` or `$result` in `$page_vars`.

### Action Handling Order

Process actions before loading display data:

```php
if ($post_vars) {
    // handle and redirect
    return LogicResult::redirect('/path');
}

// only reached on GET
$data = load_data();
```

### URL Formation

```php
// ✅ No .php extension
return LogicResult::redirect('/admin/admin_users');
$link = '<a href="/admin/admin_user?usr_user_id=' . $id . '">View</a>';

// ❌ Breaks routing — query parameters can be lost
return LogicResult::redirect('/admin/admin_users.php');
```

### Error Handling

```php
if (!$user_id) {
    return LogicResult::error('User ID is required');
}

$user = new User($user_id, TRUE);
if (!$user->get('usr_id')) {
    header('HTTP/1.0 404 Not Found');
    return LogicResult::error('User not found');
}

try {
    $user->prepare();
    $user->save();
} catch (Exception $e) {
    $page_vars['error_message'] = $e->getMessage();
}
```

### Security

- Permissions in the logic file, not the view: `$session->check_permission(5);`
- Authenticate writes on the model: `$user->authenticate_write([...])`.
- Always `htmlspecialchars()` user-supplied output.
- Use model methods or PDO prepared statements — never concatenate into SQL.
- FormWriter handles CSRF tokens automatically.

### Data Loading Efficiency

```php
// ✅ count_all() runs a count query without loading rows
$users = new MultiUser($criteria, $sort, $limit, $offset);
$numrecords = $users->count_all();
$users->load();

// ❌ Loads everything just to count
$users = new MultiUser($criteria);
$users->load();
$numrecords = $users->count();
```

### FormWriter

```php
$formwriter = $page->getFormWriter('form1', 'v2', array('model' => $object));
$formwriter->begin_form();
$formwriter->textinput('field_name', 'Label', array(
    'placeholder' => 'Enter value',
    'helptext'    => 'Help text here',
));
$formwriter->submitbutton('submit', 'Save');
$formwriter->end_form();
```

`$page->getFormWriter()` automatically returns the right FormWriter for the theme (FormWriterV2Bootstrap in admin). Never hand-roll admin forms — see [FormWriter](formwriter.md).

## Troubleshooting

### "Cannot use object of type LogicResult as array"

The view isn't wrapping the logic call with `process_logic()`.

```php
// ❌
$page_vars = admin_page_logic($_GET, $_POST);

// ✅
$page_vars = process_logic(admin_page_logic($_GET, $_POST));
```

### "Undefined variable" in view

The variable wasn't added to `$page_vars` in the logic file. Every value the view reads must be returned through `LogicResult::render($page_vars)`.

### Redirect not working

Either output was sent before the redirect, or the logic used `header()` directly.

```php
// ❌
echo 'Debug';
header('Location: /admin/admin_users');

// ✅
return LogicResult::redirect('/admin/admin_users');
```

### Form not processing

The logic file isn't handling the action:

```php
if ($post_vars && ($post_vars['action'] ?? '') === 'your_action') {
    // ...
    return LogicResult::redirect('/path');
}
```

### Validation Commands

```bash
# Syntax check
php -l /var/www/html/joinerytest/public_html/adm/logic/admin_user_logic.php
php -l /var/www/html/joinerytest/public_html/adm/admin_user.php

# Method existence check
php /var/www/html/joinerytest/maintenance_scripts/dev_tools/validate_php_file.php \
    /var/www/html/joinerytest/public_html/adm/logic/admin_user_logic.php
```

## Testing Checklist

- [ ] `php -l` clean on both files
- [ ] `validate_php_file.php` clean (or false positives understood)
- [ ] Unauthorized users are redirected by `check_permission()`
- [ ] Data loads in every section of the page
- [ ] Sort, search, pagination work (where applicable)
- [ ] POST forms redirect cleanly (no resubmit on refresh)
- [ ] Error paths surface useful messages (invalid IDs, missing required fields)
- [ ] DisplayMessage success/error renders and clears
- [ ] All admin URLs are extension-less
- [ ] Mobile / narrow viewports render correctly

## Related Documentation

- [Logic Architecture](logic_architecture.md) — LogicResult pattern in depth
- [FormWriter](formwriter.md) — Form generation, validation, model binding
- [Validation](validation.md) — Client/server/model validation layers
- [Routing](routing.md) — How admin URLs reach these files
- [Plugin Developer Guide](plugin_developer_guide.md) — Same pattern in plugin admin pages
