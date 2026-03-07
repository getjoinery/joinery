# FormWriter V2 API Migration

## Overview

All FormWriter classes now extend `FormWriterV2Base` (via `FormWriterV2Bootstrap` or `FormWriterV2HTML5`). The old positional argument API no longer exists in any FormWriter class. This spec covers eradicating all remaining old API call sites across the codebase.

## Background

### Old API (no longer exists)
```php
// Old positional signatures — these methods DO NOT EXIST in FormWriterV2
$fw->textinput($title, $name, $class, $tabindex, $fill_value, $placeholder, $maxlength, $required)
$fw->passwordinput($title, $name, $class, $tabindex, $fill_value, $placeholder, $maxlength, $required)
$fw->checkboxinput($title, $name, $class, $align, $fillval, $value, $required)
$fw->set_validate($validation_rules_array)
$fw->new_button($label, $url, $class)
$fw->begin_form($form_id, $method, $action, $use_nonce)  // with arguments
$fw->end_form($bool)                                      // with arguments
```

### New V2 API
```php
// New named + options-array signatures
$fw->textinput($name, $label, $options = [])
$fw->passwordinput($name, $label, $options = [])
$fw->checkboxinput($name, $label, $options = [])
$fw->textbox($name, $label, $options = [])       // textarea
$fw->dropinput($name, $label, $options = [])
$fw->submitbutton($name, $label, $options = [])
$fw->hiddeninput($name, $value)
$fw->begin_form()                                 // no arguments; action/method set in constructor
$fw->end_form()                                   // no arguments
// set_validate() and new_button() DO NOT EXIST — see replacements below
```

### Common options for textinput / passwordinput / textbox
```php
[
    'class'       => 'form-control',   // default; override if needed
    'value'       => $prefill_value,   // pre-fill
    'placeholder' => 'hint text',
    'maxlength'   => 255,
    'required'    => true,
    'type'        => 'email',          // textinput only: email, tel, url, search, etc.
]
```

### Replacing set_validate()
`set_validate()` passed a legacy jQuery Validate rules array. It does not exist in V2. The V2 FormWriter derives validation from the model class field specifications automatically. Remove all `set_validate()` calls and their associated `$validation_rules` array setup entirely.

### Replacing new_button()
`new_button()` rendered a link styled as a button. Replace with a plain HTML anchor:
```php
// Old
echo $formwriter->new_button('Change', '/cart?newbilling=1', 'btn btn-outline-secondary btn-sm');

// New — just use an <a> tag; no FormWriter needed
?>
<a href="/cart?newbilling=1" class="btn btn-outline-secondary btn-sm">Change</a>
<?php
```

### Replacing old begin_form() with arguments
The old `begin_form($form_id, $method, $action, $use_nonce)` is replaced by passing action/method to the FormWriter constructor:
```php
// Old
$fw = LibraryFunctions::get_formwriter_object('form1');
echo $fw->begin_form('form1', 'post', '/my-action', true);

// New
$fw = $page->getFormWriter('form1', ['action' => '/my-action']);
$fw->begin_form();
```

---

## Files to Fix

### Priority 1 — Public-facing (currently broken for active themes)

#### `theme/canvas-html5/views/cart.php`
- Remove `set_validate()` call and its `$validation_rules` array setup
- Replace `new_button(...)` with `<a>` tag
- Fix `textinput('', 'billing_first_name', 'form-control', 30, $value, '', 255, '')` → `textinput('billing_first_name', 'First Name', ['value' => $value, 'maxlength' => 255, 'required' => true])`
- Fix `textinput('', 'billing_last_name', ...)` similarly
- Fix `textinput('', 'billing_email', ...)` with `'type' => 'email'`
- Fix `passwordinput('', 'password', 'form-control', 20, ...)` → `passwordinput('password', 'Create Password', ['required' => true])`
- Fix `checkboxinput('I consent...', 'privacy', 'form-check-input', 'left', null, 1, '')` → `checkboxinput('privacy', 'I consent to the terms of use and privacy policy.', ['required' => true])`
- Fix coupon `textinput('', 'coupon_code', 'form-control', 64, null, 'Enter coupon code', 255, '')` → `textinput('coupon_code', '', ['placeholder' => 'Enter coupon code', 'maxlength' => 255])`
- Since FormWriter renders label + wrapper div, remove the surrounding `<div class="form-group"><label>...</label>` wrappers from each converted field

#### `theme/canvas-html5/views/post.php`
- Remove `$validation_rules` array setup and `antispam_question_validate()` assignment (antispam validate call stays, just don't pass old rules)
- Fix `textinput('', 'name', 'form-control', 20, null, '', 255, '')` → `textinput('name', 'Name', ['maxlength' => 255, 'required' => true])`
- Fix `textbox('', 'cmt', 'form-control', 4, 80, null, '', '')` → `textbox('cmt', 'Comment', ['rows' => 4, 'required' => true])`
- Fix `submitbutton('submit', 'Post Comment', ['class' => 'btn btn-primary'])` — already correct V2 syntax, no change needed
- For reply form: replace `LibraryFunctions::get_formwriter_object('form' . $comment->key)` with `$page->getFormWriter('form' . $comment->key, ['action' => $_SERVER['REQUEST_URI']])`
- Remove old `begin_form($form_id, 'post', $action, true)` and replace with `begin_form()`
- Fix reply `textinput` and `textbox` same as above
- Change `end_form(true)` to `end_form()`
- Remove surrounding label/form-group wrappers as above

#### `theme/canvas/views/cart.php`
Same fixes as canvas-html5 cart.php above (identical old API usage).

#### `theme/canvas/views/post.php`
Same fixes as canvas-html5 post.php above (identical old API usage).

### Priority 2 — Other themes (broken if those themes are activated)

#### `theme/tailwind/views/cart.php`
- Remove `set_validate()` and its rules array
- Replace `new_button(...)` with `<a>` tag
- Fix all old positional `textinput`, `passwordinput`, `checkboxinput` calls

#### `theme/tailwind/views/event.php`
- Replace `new_button(...)` with `<a>` tag (used for event registration link)

#### `plugins/controld/views/cart.php`
- Replace `new_button(...)` with `<a>` tag
- Fix any old positional `textinput`/`passwordinput`/`checkboxinput` calls

### Priority 3 — Internal tools (admin/utils/tests — not public-facing)

#### `utils/email_setup_check.php`
- Remove `set_validate()` call
- Fix `textinput('Domain to Check', 'domain', 'form-control', 100, $domain, 'example.com', 255, '...')` → `textinput('domain', 'Domain to Check', ['value' => $domain, 'placeholder' => 'example.com', 'maxlength' => 255])`
- Fix `checkboxinput('Comprehensive DKIM scan...', 'complete', 'form-check-input', 'left', '1', $val, '...')` → `checkboxinput('complete', 'Comprehensive DKIM scan (slower, checks 400+ selectors)', ['value' => '1', 'checked' => $is_comprehensive])`

#### `utils/email_send_test.php`
- Fix 2× `textinput` and 1× `passwordinput` old calls

#### `tests/email/auth_analysis.php`
- Fix 2× `textinput` and 1× `passwordinput` old calls (same as email_send_test)

#### `tests/email/legacy/email_send_test.php`
- Fix 2× `textinput` and 1× `passwordinput` old calls (legacy file — consider deleting entirely)

#### `adm/admin_static_cache_bak.php`
- This is a backup file (`_bak`). Consider deleting it. If kept, fix 3× old `textinput` and 3× `set_validate` calls.

---

## Implementation Notes

1. **Labels**: When converting, pass the field label to the FormWriter method (2nd argument). The V2 FormWriter renders the label + input wrapped in `<div class="form-group">`. Remove any surrounding manual `<label>` and `<div class="form-group">` from the view template for converted fields to avoid duplication.

2. **Pre-fill values**: Old API passed fill value as 5th positional argument. New API uses `'value'` key in options array.

3. **Placeholder**: Old API 6th arg → `'placeholder'` key in options.

4. **Maxlength**: Old API 7th arg → `'maxlength'` key in options.

5. **Required**: Old API 8th arg was a string message; in V2 use `'required' => true`.

6. **Checkboxes**: Old `checkboxinput($label, $name, $class, $align, $fillval, $value, $required)`. In V2: `checkboxinput($name, $label, $options)` where options may include `'value'`, `'checked'`, `'required'`.

7. **No FormWriter instance needed for new_button replacement**: Just use a plain `<a>` tag. Remove the `getFormWriter()` call if the only purpose was `new_button`.

8. **antispam_question_validate()**: This method still exists in V2. Keep those calls. Just remove the old `$validation_rules` array that was being built and passed to `set_validate()` — those rules are no longer needed.

9. **Syntax check all modified files** with `php -l` and run `validate_php_file.php` after each file is fixed.

---

## Verification

After all fixes:
- Visit `/cart` on canvas, canvas-html5, and tailwind themes — billing form should render without errors
- Visit a post with comments enabled — comment form should render without errors
- Run the email utils to confirm they still function
- No `set_validate`, `new_button`, or old positional `textinput`/`passwordinput`/`checkboxinput` calls should remain anywhere in the codebase (verify with grep)
