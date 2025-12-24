# Delete Button Helper Specification

## Overview

Create a reusable helper function to generate inline delete form HTML, eliminating duplicate code across admin pages.

## Problem Statement

The same delete form pattern is repeated verbatim in multiple admin files:

```php
$delete_form = '<form method="POST" style="display:inline" onsubmit="return confirm(\'Are you sure you want to delete this component?\');">';
$delete_form .= '<input type="hidden" name="action" value="delete">';
$delete_form .= '<input type="hidden" name="pac_page_content_id" value="' . $content->key . '">';
$delete_form .= '<button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>';
$delete_form .= '</form>';
```

### Current Occurrences

| File | Line | Action Value | ID Field |
|------|------|--------------|----------|
| `adm/admin_components.php` | 128-132 | `delete` | `pac_page_content_id` |
| `adm/admin_component_types.php` | 110-114 | `delete` | `com_component_id` |
| `adm/admin_page.php` | 233-237 | `delete_component` | `pac_page_content_id` |

## Proposed Solution

Add a static helper method to `LibraryFunctions` class.

### Method Signature

```php
/**
 * Generate an inline delete button form with confirmation
 *
 * @param string $action      The action value for the hidden input (e.g., 'delete', 'delete_component')
 * @param string $id_field    The name of the ID field (e.g., 'pac_page_content_id', 'com_component_id')
 * @param mixed  $id_value    The ID value to submit
 * @param array  $options     Optional settings:
 *                            - 'confirm_message' => string (default: 'Are you sure you want to delete this item?')
 *                            - 'button_text' => string (default: 'Delete')
 *                            - 'button_class' => string (default: 'btn btn-sm btn-outline-danger')
 * @return string HTML form markup
 */
public static function delete_button($action, $id_field, $id_value, $options = [])
```

### Implementation

```php
public static function delete_button($action, $id_field, $id_value, $options = []) {
    $confirm_message = $options['confirm_message'] ?? 'Are you sure you want to delete this item?';
    $button_text = $options['button_text'] ?? 'Delete';
    $button_class = $options['button_class'] ?? 'btn btn-sm btn-outline-danger';

    $html = '<form method="POST" style="display:inline" onsubmit="return confirm(\'' . htmlspecialchars(addslashes($confirm_message), ENT_QUOTES) . '\');">';
    $html .= '<input type="hidden" name="action" value="' . htmlspecialchars($action) . '">';
    $html .= '<input type="hidden" name="' . htmlspecialchars($id_field) . '" value="' . htmlspecialchars($id_value) . '">';
    $html .= '<button type="submit" class="' . htmlspecialchars($button_class) . '">' . htmlspecialchars($button_text) . '</button>';
    $html .= '</form>';

    return $html;
}
```

### Usage Examples

**Basic usage:**
```php
$delete_form = LibraryFunctions::delete_button('delete', 'pac_page_content_id', $content->key);
```

**With custom confirmation message:**
```php
$delete_form = LibraryFunctions::delete_button(
    'delete',
    'com_component_id',
    $component->key,
    ['confirm_message' => 'Are you sure you want to delete this component type?']
);
```

**With custom button styling:**
```php
$delete_form = LibraryFunctions::delete_button(
    'remove',
    'item_id',
    $item->key,
    [
        'button_text' => 'Remove',
        'button_class' => 'btn btn-danger'
    ]
);
```

## Files to Update

1. **LibraryFunctions.php** - Add the `delete_button()` method

2. **adm/admin_components.php** - Replace lines 128-132:
   ```php
   $delete_form = LibraryFunctions::delete_button('delete', 'pac_page_content_id', $content->key, [
       'confirm_message' => 'Are you sure you want to delete this component?'
   ]);
   ```

3. **adm/admin_component_types.php** - Replace lines 110-114:
   ```php
   $delete_form = LibraryFunctions::delete_button('delete', 'com_component_id', $component->key, [
       'confirm_message' => 'Are you sure you want to delete this component type?'
   ]);
   ```

4. **adm/admin_page.php** - Replace lines 233-237:
   ```php
   $actions .= LibraryFunctions::delete_button('delete_component', 'pac_page_content_id', $component->key, [
       'confirm_message' => 'Are you sure you want to delete this component?'
   ]);
   ```

## Testing

1. Verify delete functionality works on all three admin pages
2. Confirm JavaScript confirmation dialog appears with correct message
3. Ensure proper HTML escaping of all dynamic values
4. Test with IDs containing special characters

## Security Considerations

- All output is escaped with `htmlspecialchars()`
- Confirmation message is escaped and JS-safe via `addslashes()`
- Form uses POST method to prevent CSRF via URL manipulation
- Action handlers should still validate permissions server-side

## Future Enhancements

Consider extending this pattern to other common action buttons:
- Toggle status buttons
- Edit link buttons
- Action button groups
