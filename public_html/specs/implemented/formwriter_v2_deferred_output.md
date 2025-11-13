# FormWriter V2 Deferred Output Feature Specification

## Problem

FormWriter V2 immediately echoes field HTML, making it impossible to build form strings in loops (e.g., inline action forms in `/adm/admin_comments.php`).

## Current Behavior

```php
// Field methods echo immediately
public function textinput($name, $label = '', $options = []) {
    $this->registerField($name, 'text', $label, $options);
    $this->outputTextInput($name, $label, $options);  // ECHOES HTML
}
```

## Solution

Add optional deferred output mode:

```php
// Constructor option
$formwriter = new FormWriterV2Bootstrap('form1', ['deferred_output' => true]);

// Fields no longer echo immediately
$formwriter->textinput('name', 'Name');
$formwriter->submitbutton('submit', 'Save');

// Get HTML string
$html = $formwriter->getFieldsHTML();
```

### Implementation

1. Add to constructor: `$this->use_deferred_output = $options['deferred_output'] ?? false;`
2. Add property: `protected $deferred_output = [];`
3. Modify output methods to check flag and either echo or store HTML
4. Add method: `getFieldsHTML()` to return collected HTML

### Usage in admin_comments.php

```php
foreach ($comments as $comment) {
    $form = $page->getFormWriter('form_' . $comment->key, 'v2', [
        'deferred_output' => true,
        'action' => '/admin/admin_comment?cmt_comment_id=' . $comment->key
    ]);

    $form->hiddeninput('action', ['value' => 'delete']);
    $form->submitbutton('btn_delete', 'Delete');

    array_push($rowvalues, $form->getFieldsHTML());
}
```

## Files to Modify

1. **FormWriterV2Base.php**: Add deferred mode properties and `getFieldsHTML()` method
2. **FormWriterV2Bootstrap.php**: Refactor ~15 output methods to build strings before echoing
3. **FormWriterV2Tailwind.php**: Same refactoring as Bootstrap

## Backward Compatibility

✅ Fully backward compatible - deferred mode is opt-in only via constructor option
✅ No changes needed to existing forms

## Success Criteria

- Deferred mode works for inline forms
- admin_comments.php can be migrated successfully
- All existing forms continue to work unchanged
- Validation and error display work in deferred mode
