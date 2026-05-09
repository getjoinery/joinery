# JoineryModal Migration Spec

## Overview

The joinery-system admin theme now has a native `<dialog>`-based modal component (`JoineryModal`) defined in `theme/joinery-system/assets/js/script.js`. This spec covers migrating all old-style confirmation dialogs in admin-context files to use it.

**Out of scope:** Public theme views (`views/profile/`, public plugin views) and non-admin themes (zoukroom-html5, scrolldaddy, linka-reference). Those run different themes that do not load `JoineryModal`.

---

## The JoineryModal API

Full API reference: `specs/joinery_modal_api.md`. Summary:

```js
JoineryModal.confirm(message, onConfirm [, options]);  // two buttons, danger style
JoineryModal.alert(message [, onClose [, options]]);   // one button (OK), primary style
JoineryModal.prompt(message, onConfirm [, options]);   // text input, onConfirm(value)
```

Options: `confirmLabel`, `cancelLabel`, `confirmStyle` (`'danger'`|`'primary'`), `placeholder`, `defaultValue`.

The migration targets below only require `confirm()`. `alert()` and `prompt()` are available for future use.

---

## Migration Targets

### Type A — `window.confirm()` / `confirm()` inline in PHP output

These are `onsubmit="return confirm(...)"` or `onclick="return confirm(...)"` patterns. They block the page synchronously. The replacement pattern is an async callback via `JoineryModal.confirm()`.

**Replacement pattern for `onsubmit="return confirm(...)"`:**

```php
// Before
<form onsubmit="return confirm('Are you sure?')">

// After — wire form to a named JS function
<form id="myForm">
...
<button type="button" onclick="confirmAndSubmit('myForm', 'Are you sure?')">Submit</button>
```
```js
function confirmAndSubmit(formId, message, options) {
    JoineryModal.confirm(message, function() {
        document.getElementById(formId).submit();
    }, options);
}
```

Where a form already has an id or the button is unique, use an inline anonymous function instead of the helper.

**Replacement pattern for `onclick="return confirm(...)"`:**

```php
// Before
<a href="..." onclick="return confirm('Delete this?')">Delete</a>

// After
<a href="#" onclick="JoineryModal.confirm('Delete this?', function(){ window.location='...'; })">Delete</a>
```

---

### Type B — Custom CSS overlay (`jy-modal`)

`adm/admin_themes.php` has a hand-rolled modal with custom classes (`.jy-modal-overlay`, `.jy-modal`, etc.), inline CSS, and JS helper functions `showDeleteModal()` / `closeDeleteModal()`. Replace with `JoineryModal.confirm()`.

---

### Type C — Fixed-position overlay (`restoreModal`)

`plugins/server_manager/views/admin/node_detail.php` has a `restoreModal` div with inline styles and a form inside it. This is a more complex multi-field form — it cannot be replaced by `JoineryModal.confirm()` directly. The correct approach is to convert it to a native `<dialog>` element (HTML, no inline styles) and call `.showModal()` / `.close()` manually.

---

## File-by-File Changes

### 1. `adm/admin_cloud_storage.php`
- **Pattern:** `if (!window.confirm(msg)) return;` inside a JS function
- **Change:** Replace the `if (!window.confirm(...))` guard with `JoineryModal.confirm(msg, function() { /* original action */ });`

### 2. `adm/admin_api_key.php`
- **Pattern:** `if(!confirm('Regenerate secret key?...')) return false;` in `onsubmit`
- **Change:** Remove `onsubmit`, add a `type="button"` wrapper that calls `JoineryModal.confirm()` and then submits the form.

### 3. `adm/admin_event.php`
- **Pattern:** `onclick="return confirm('Are you sure you want to delete this session?')"` on a delete link/button
- **Change:** Replace with `onclick="JoineryModal.confirm('Delete this session?', function(){ /* navigate or submit */ })"` 

### 4. `adm/admin_test_database.php`
- **Pattern:** `onsubmit="return confirm('This will DROP the test database...')"` 
- **Change:** Same as #2 — move to button onclick with JoineryModal.

### 5. `adm/admin_users_edit.php`
- **Pattern:** `onsubmit="return confirm('Reset 2FA for this user?')"`
- **Change:** Same pattern.

### 6. `adm/admin_themes.php`
- **Pattern:** Full custom `jy-modal` overlay with inline CSS block (~40 lines), modal HTML (`deleteThemeModal`), and JS helper functions `showDeleteModal()` / `closeDeleteModal()`
- **Change:**
  - Remove the inline `<style>` block for jy-modal
  - Remove the modal HTML div
  - Remove `showDeleteModal()` and `closeDeleteModal()` JS functions
  - Change the delete button's `onclick` to call `JoineryModal.confirm('Delete theme "'+displayName+'"?', function(){ submitDeleteForm(themeName); }, { confirmLabel: 'Delete' })`
  - Keep `submitDeleteForm()` as the actual form-submit callback

### 7. `plugins/server_manager/views/admin/job_detail.php`
- **Pattern:** `onclick="return confirm('Cancel this job?')"`
- **Change:** JoineryModal.confirm() pattern.

### 8. `plugins/server_manager/views/admin/marketplace.php`
- **Patterns:** Two `onsubmit="return confirm('Install...?')"` forms
- **Change:** Button onclick pattern (Type A).

### 9. `plugins/server_manager/views/admin/node_detail.php`
- **Patterns (confirm):**
  - Line 489: `onsubmit="return confirm('Before retrying...')"` 
  - Line 546: `onclick="return confirm('Delete this site?')"`
  - Line 1341: `return confirm('Restore...from...?')`
  - Line 1345: `return confirm('Restore database from...?')`
  - Line 1354: `if (!confirm('Delete...?'))`
  - Lines 1413, 1445: `'onclick' => 'return confirm(...)'` (PHP array form attributes)
  - Line 1528: `onclick="return confirm('Apply update...')"`
  - Line 1534: `onsubmit="return confirm('Queue an upgrade job...')"`
  - Line 1689: `onclick="return confirm('Clear API credentials?')"`
- **Pattern (restoreModal):** Fixed-position overlay div with inline styles and a form inside
- **Change for confirm():** JoineryModal.confirm() throughout.
- **Change for restoreModal:** Convert to `<dialog id="restoreModal">` (no inline styles; use theme CSS for dialog sizing). Keep existing `closeRestoreModal()` call but change it to `document.getElementById('restoreModal').close()`. Open with `document.getElementById('restoreModal').showModal()`.

### 10. `plugins/server_manager/views/admin/targets.php`
- **Pattern:** `onclick="return confirm('Delete this target?')"`
- **Change:** JoineryModal.confirm() pattern.

### 11. `plugins/server_manager/views/admin/publish_upgrade.php`
- **Pattern:** `onsubmit="return confirm('Delete upgrade...?')"`
- **Change:** Button onclick pattern.

### 12. `plugins/server_manager/includes/publish_upgrade.php`
- **Pattern:** `onclick="return confirm('Are you sure you want to delete version...')"` 
- **Note:** This is an includes file that outputs HTML. Same button onclick pattern.

### 13. `plugins/dns_filtering/views/profile/devices.php`
- **Note:** This is a *public* profile view — it runs under whatever the active public theme is, not joinery-system. JoineryModal is not available here.
- **Change:** Defer. When/if a modal component is added to public themes, migrate this file then.

---

## Theme CSS — No Changes Required

The `<dialog>` styling already exists in `theme/joinery-system/assets/css/style.css`. The `restoreModal` `<dialog>` conversion will inherit those styles. If the restore form is wider than 480px (current `max-width`), add a modifier class and extend the CSS rule:

```css
dialog.dialog-wide { max-width: 640px; }
```

---

## Implementation Order

1. Simple `confirm()` replacements in `adm/` files (items 1–5) — low risk, no HTML changes
2. `admin_themes.php` jy-modal removal (item 6) — removes ~80 lines of inline CSS/HTML/JS
3. `server_manager` confirm() replacements (items 7–12) — many instances, same pattern
4. `server_manager` `restoreModal` dialog conversion (item 9) — most complex, do last

---

## Alert Dismiss Cleanup (Broken Close Buttons)

A separate but related bug: all admin and plugin pages that show dismissible flash alerts use `data-bs-dismiss="alert"` + `class="btn-close"`. The joinery-system JS has no handler for `data-bs-dismiss` — the close button renders but clicking it does nothing.

**Fix pattern:**

```php
// Before
echo '<div class="alert ' . $alert_class . ' alert-dismissible fade show" role="alert">';
echo $message;
echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
echo '</div>';

// After
echo '<div class="alert ' . $alert_class . '" role="alert">';
echo $message;
echo '<button type="button" class="alert-close" aria-label="Close">&times;</button>';
echo '</div>';
```

The joinery-system JS already handles `.alert-close` — no JS changes required, only the HTML.

**Files to fix:**

| File | Lines |
|---|---|
| `adm/admin_cloud_storage.php` | 39, 42 |
| `adm/admin_plugins.php` | 69, 71 |
| `adm/admin_scheduled_tasks.php` | 101, 106 |
| `adm/admin_static_cache.php` | 390, 396 |
| `adm/admin_test_database.php` | 238, 240 |
| `plugins/server_manager/includes/publish_upgrade.php` | 491, 496 |
| `plugins/server_manager/views/admin/job_detail.php` | 95, 97 |
| `plugins/server_manager/views/admin/node_detail.php` | 435, 440 |
| `plugins/server_manager/views/admin/publish_upgrade.php` | 113, 116 |
| `plugins/server_manager/views/admin/targets.php` | 173, 175 |

---

## Testing Checklist

For each migrated file:
- [ ] Destructive action button/link triggers JoineryModal (not browser native dialog)
- [ ] Cancel button dismisses the modal without performing the action
- [ ] Confirm button performs the action (form submits / navigation occurs)
- [ ] Dialog is visually styled (white background, border, centered)
- [ ] No inline `<style>` blocks remain for modal styling
- [ ] No `window.confirm()` or `confirm()` calls remain in admin-context files
- [ ] Alert close buttons use `class="alert-close"` and dismiss correctly
