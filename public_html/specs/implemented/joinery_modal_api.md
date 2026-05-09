# JoineryModal API Design

## Overview

`JoineryModal` is a vanilla-JS modal utility defined in `theme/joinery-system/assets/js/script.js`. It wraps the native HTML `<dialog>` element with a clean, consistent API for the three confirmation/notification patterns needed across the admin interface.

A single `<dialog>` DOM node is created on first use and reused for every subsequent call. No stacked/nested modals — if a second call arrives while one is open it replaces the first. This is the correct constraint for an admin UI.

---

## API

### `JoineryModal.confirm(message, onConfirm [, options])`

Two-button dialog for irreversible or significant actions. Default confirm button is danger-styled.

```js
JoineryModal.confirm('Delete this record?', function() {
    submitAction();
});

JoineryModal.confirm('Install this plugin?', function() {
    submitAction();
}, { confirmLabel: 'Install', confirmStyle: 'primary' });
```

### `JoineryModal.alert(message [, onClose [, options]])`

One-button dialog (no Cancel) for information or error acknowledgment. Defaults to primary styling and "OK" label.

```js
JoineryModal.alert('API key regenerated successfully.');

JoineryModal.alert('Could not connect to server.', null, { confirmLabel: 'Dismiss' });
```

### `JoineryModal.prompt(message, onConfirm [, options])`

Two-button dialog with a text input. `onConfirm` receives the typed string as its argument. Defaults to primary styling.

```js
JoineryModal.prompt('Enter the plugin name to confirm deletion:', function(value) {
    if (value === expectedName) submitDelete();
    else JoineryModal.alert('Name did not match.');
}, { placeholder: 'plugin-name', confirmLabel: 'Delete', confirmStyle: 'danger' });
```

---

## Options

All three methods accept an `options` object. Applicable options per method:

| Option | Type | Default | confirm | alert | prompt |
|---|---|---|---|---|---|
| `confirmLabel` | string | `'Confirm'` / `'OK'` | ✅ | ✅ | ✅ |
| `cancelLabel` | string | `'Cancel'` | ✅ | — | ✅ |
| `confirmStyle` | `'danger'` \| `'primary'` | `'danger'` / `'primary'` | ✅ | ✅ | ✅ |
| `placeholder` | string | `''` | — | — | ✅ |
| `defaultValue` | string | `''` | — | — | ✅ |

---

## CSS Classes

The dialog HTML structure:

```html
<dialog>
  <p class="dialog-message"></p>
  <input class="dialog-input" type="text">        <!-- hidden unless prompt -->
  <div class="dialog-actions">
    <button class="dialog-btn-cancel">Cancel</button>   <!-- hidden for alert -->
    <button class="dialog-btn-confirm dialog-btn-danger">Confirm</button>
  </div>
</dialog>
```

Confirm button color is controlled by a second class alongside `dialog-btn-confirm`:
- `.dialog-btn-danger` — red (`--danger`)
- `.dialog-btn-primary` — blue (`--primary`)

These are set by JS on each call; no inline styles.

---

## What Is Out of Scope

**Form-hosting modals** (multiple inputs, selects, checkboxes inside the overlay) are not a JoineryModal use case. Each such modal is a standalone `<dialog>` element written directly in the page, calling `.showModal()` / `.close()` manually. The theme's `dialog` CSS rule styles these automatically with no extra work.

---

## Files

| File | Role |
|---|---|
| `theme/joinery-system/assets/js/script.js` | JS implementation |
| `theme/joinery-system/assets/css/style.css` | Dialog and button CSS |
| `specs/joinery_modal_migration.md` | Migration plan for all old-style modals |
