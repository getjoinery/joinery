# Specification: FormWriter V2 Auto Primary Key Hidden Field

**Status:** Approved
**Priority:** High
**Date Created:** 2025-10-28
**Related Specifications:**
- `/specs/migrate_admin_forms_to_formwriter_v2.md` - Form migration spec
- `/docs/formwriter.md` - FormWriter documentation

---

## 1. Overview

### Problem Statement

Currently, every add/edit form requires manual addition of primary key hidden fields:

```php
// Current manual approach - error prone
if($product->key){
    $formwriter->hiddeninput('pro_product_id', ['value' => $product->key]);
}
```

This leads to:
- Repetitive boilerplate code in every edit form
- Inconsistent implementation patterns
- Complex logic for detecting add vs edit operations
- Manual tracking of primary key field names

### Proposed Solution

FormWriter V2 will automatically generate a standardized hidden field `edit_primary_key_value` when editing an existing record. Logic files can simply check for this field's presence to determine if it's an add or edit operation.

---

## 2. Implementation Design

### Selected Approach

**FormWriter V2 will generate a hidden field named `edit_primary_key_value` when explicitly provided with the key value.**

#### View File Pattern
```php
// Explicitly pass the edit key value
$formwriter = $page->getFormWriter('form1', 'v2', [
    'model' => $product,
    'edit_primary_key_value' => $product->key  // Explicit and clear
]);

// FormWriter internally generates:
// <input type="hidden" name="edit_primary_key_value" value="123"> (if value provided)
// Nothing added if edit_primary_key_value is null or omitted (new record)
```

#### Logic File Pattern
```php
// Simple two-way check
if (isset($post_vars['edit_primary_key_value'])) {
    // It's an edit - the key is in the hidden field
    $product = new Product($post_vars['edit_primary_key_value'], TRUE);
} else {
    // It's an add - no hidden field present
    $product = new Product(NULL);
}

// For cleaner conditionals later:
$is_edit = isset($post_vars['edit_primary_key_value']);
```

### Why Explicit Over Automatic?

- **No magic** - Developer explicitly controls when the field is added
- **Clear intent** - Can see in the code that we're passing an edit key
- **Flexible** - Works with or without models
- **Debuggable** - Easy to see what's being passed

### 2.3 FormWriter Internal Implementation

```php
class FormWriterV2Bootstrap extends FormWriterV2Base {

    protected function __construct($form_id, $options = []) {
        parent::__construct($form_id, $options);

        // Handle edit_primary_key_value if explicitly provided
        if (isset($options['edit_primary_key_value']) && $options['edit_primary_key_value'] !== null) {
            $this->addHiddenField('edit_primary_key_value', $options['edit_primary_key_value']);
        }
    }

    // Helper to add hidden fields before form renders
    private function addHiddenField($name, $value) {
        // Store for rendering after begin_form()
        $this->autoHiddenFields[$name] = $value;
    }

    public function begin_form($options = []) {
        $output = parent::begin_form($options);

        // Render auto hidden fields immediately after form open tag
        foreach ($this->autoHiddenFields as $name => $value) {
            $output .= $this->hiddeninput($name, ['value' => $value]);
        }

        return $output;
    }
}
```

### 2.4 Logic File Processing

The standardized hidden field `edit_primary_key_value` makes add/edit detection trivial:

#### Current Pattern (Complex)
```php
// Must check GET for initial page load
if (isset($get_vars['pro_product_id'])) {
    $product = new Product($get_vars['pro_product_id'], TRUE);
} else {
    $product = new Product(NULL);
}
```

#### New Pattern (Simple)
```php
// Check for the standardized hidden field
if (isset($post_vars['edit_primary_key_value'])) {
    $product = new Product($post_vars['edit_primary_key_value'], TRUE);
} else {
    $product = new Product(NULL);
}

// For cleaner conditionals:
$is_edit = isset($post_vars['edit_primary_key_value']);

if($post_vars){
    if (!$is_edit) {
        // Adding new record
        $product->set('pro_created_by', $session->get_user_id());
    }

    // Common processing...
    $product->save();
}

```

---

## 3. Migration Path

### 3.1 Backward Compatibility

The enhancement is 100% backward compatible:
- Existing manual `hiddeninput()` calls continue working
- No changes required to existing forms
- The new field `edit_primary_key_value` won't conflict with existing field names

### 3.2 Migration Pattern

```php
// OLD Pattern
$formwriter = $page->getFormWriter('form1', 'v2', [
    'model' => $product
]);
if($product->key){
    $formwriter->hiddeninput('pro_product_id', ['value' => $product->key]);
}

// NEW Pattern - Explicit and standardized
$formwriter = $page->getFormWriter('form1', 'v2', [
    'model' => $product,
    'edit_primary_key_value' => $product->key  // Pass the key explicitly
]);
```

---

## 4. Benefits

- ✅ **Simpler logic** - Just check for `edit_primary_key_value` field
- ✅ **No field name tracking** - Always the same field name
- ✅ **Clean conditionals** - `if (!$is_edit)` is crystal clear
- ✅ **Explicit control** - Developer decides when to add the field
- ✅ **Self-documenting** - Can see the edit key in the FormWriter options

---

## 5. Example: Complete Before/After

### Before (Manual Approach)
```php
// admin_product_edit.php
$formwriter = $page->getFormWriter('form1', 'v2', [
    'model' => $product
]);
if($product->key){
    $formwriter->hiddeninput('pro_product_id', ['value' => $product->key]);
}

// admin_product_edit_logic.php
if (isset($get_vars['pro_product_id'])) {
    $product = new Product($get_vars['pro_product_id'], TRUE);
} else {
    $product = new Product(NULL);
}
```

### After (Explicit Hidden Field)
```php
// admin_product_edit.php
$formwriter = $page->getFormWriter('form1', 'v2', [
    'model' => $product,
    'edit_primary_key_value' => $product->key  // Explicit and clear
]);

// admin_product_edit_logic.php
if (isset($post_vars['edit_primary_key_value'])) {
    $product = new Product($post_vars['edit_primary_key_value'], TRUE);
} else {
    $product = new Product(NULL);
}

$is_edit = isset($post_vars['edit_primary_key_value']);

if($post_vars){
    if (!$is_edit) {
        // Adding new product
        $product->set('pro_created_by', $session->get_user_id());
    }

    // Common processing...
    $product->save();
}
```

---

## 6. Implementation Checklist

- [ ] Add `edit_primary_key_value` option handling to FormWriterV2Base
- [ ] Modify `begin_form()` to render auto hidden fields
- [ ] Test with existing forms for backward compatibility
- [ ] Update documentation

---

## 7. Decision Log

| Date | Decision | Rationale |
|------|----------|-----------|
| 2025-10-28 | Use standardized field name `edit_primary_key_value` | Simple, clear, no methods needed. Just check if the field exists. |
| 2025-10-28 | Explicit parameter approach | Developer explicitly passes `edit_primary_key_value` option. No magic, clear intent, easier to debug. |

---

## 8. References

- Current FormWriter V2: `/var/www/html/joinerytest/public_html/includes/FormWriterV2Bootstrap.php`
- Migration spec: `/var/www/html/joinerytest/public_html/specs/migrate_admin_forms_to_formwriter_v2.md`
- Example forms: `/var/www/html/joinerytest/public_html/adm/admin_*_edit.php`