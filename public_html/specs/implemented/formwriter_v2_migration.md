# FormWriter V2 API Migration — IMPLEMENTED

## Status: Complete

All FormWriter call sites across the codebase have been migrated to the V2 API. No old API patterns remain in any active PHP file.

## What Was Done

### Dead Code Removal
- Removed all `$validation_rules = array()` setup blocks and their associated rule-building code
- Removed all `set_validate($rules)` calls
- These were vestigial remnants of the old jQuery Validate integration; V2 derives validation from model field specifications automatically

### `begin_form()` / `end_form()` Fixes
- All `begin_form($id, $method, $action, $bool)` calls replaced with `begin_form()` (no args)
- Action, method, and other form attributes moved into the `getFormWriter('id', ['action' => ..., 'method' => ...])` constructor call
- All `end_form(true)` calls replaced with `end_form()` (no args)
- Fixed array-style `begin_form(['action' => ..., 'method' => ...])` calls similarly

### Button Wrapper Removal
- All `start_buttons()` / `end_buttons()` wrappers removed
- All `new_form_button($label, $class)` calls replaced with `submitbutton('btn_submit', $label, ['class' => ...])`
- All `new_button($label, $url, $class)` calls replaced with plain `<a href="...">` tags

### Positional API → V2 Named API
Converted all old positional signatures to V2 options-array signatures:
```php
// Old (label-first positional)
$fw->textinput($title, $name, $class, $tabindex, $value, $placeholder, $maxlength, $required)

// New (name-first options array)
$fw->textinput($name, $label, ['value' => $value, 'maxlength' => 255, 'required' => true])
```
Applied to: `textinput`, `passwordinput`, `checkboxinput`, `textbox`, `dropinput`, `dateinput`, `timeinput`, `datetimeinput`, `fileinput`, `radioinput`

### `submitbutton('submit', ...)` → `submitbutton('btn_submit', ...)`
Using `name="submit"` on a form button shadows JavaScript's native `form.submit()` method, preventing programmatic form submission. Fixed globally:
- Changed `FormWriterV2Base::submitbutton()` default from `'submit'` to `'btn_submit'`
- Renamed all 100+ call sites from `submitbutton('submit', ...)` to `submitbutton('btn_submit', ...)`

### `hiddeninput()` API Fix
Fixed calls passing `['value' => ...]` array as second arg: `hiddeninput($name, $value)` expects a scalar, not an array.

### `LibraryFunctions::get_formwriter_object()` Removal
Replaced remaining calls with `$page->getFormWriter()` so the correct FormWriter class is selected automatically for the active theme.

### `antispam_question_validate()` Fix
Old call: `$validation_rules = $formwriter->antispam_question_validate($validation_rules, 'blog')`
New call: `$formwriter->antispam_question_validate([], 'blog')` (return value unused; method still exists in V2)

## Files Modified

Over 100 files across:
- `views/` — all base view templates
- `theme/canvas/views/`, `theme/canvas-html5/views/`, `theme/tailwind/views/`, `theme/empoweredhealth/views/`, `theme/phillyzouk/views/`
- `adm/` — all admin pages using old form patterns
- `utils/` — utility and example pages
- `plugins/bookings/admin/`, `plugins/items/admin/`
- `data/products_class.php`
- `includes/StripeHelper.php`, `includes/PublicPageFalcon.php`, `includes/AdminPage-uikit3.php`
- `includes/FormWriterV2Base.php` — updated default button name

## V2 API Reference

```php
// Constructor — action/method/id all go here
$fw = $page->getFormWriter('form_id', ['action' => '/path', 'method' => 'POST']);

// Form lifecycle
$fw->begin_form();   // no args
$fw->end_form();     // no args

// Inputs — name first, then label, then options
$fw->textinput('field_name', 'Field Label', ['value' => $val, 'maxlength' => 255, 'required' => true])
$fw->passwordinput('password', 'Password', ['required' => true])
$fw->checkboxinput('agree', 'I agree to the terms', ['required' => true])
$fw->textbox('body', 'Message', ['rows' => 5, 'required' => true])
$fw->dropinput('status', 'Status', ['options' => $opts, 'selected' => $selected])
$fw->hiddeninput('field_name', $scalar_value)  // value is scalar, not array
$fw->submitbutton('btn_submit', 'Submit', ['class' => 'btn btn-primary'])

// No FormWriter needed for link-buttons
echo '<a href="/path" class="btn btn-secondary">Link</a>';
```

## Verification

Grep confirms zero remaining old API patterns in active code:
- `->set_validate(` — 0 matches
- `->new_button(` — 0 matches (excluding commented code)
- `->start_buttons()` / `->end_buttons()` — 0 matches (excluding commented code)
- `->new_form_button(` — 0 matches
- `->end_form(true)` — 0 matches
- `submitbutton('submit',` — 0 matches
- `$validation_rules = array()` outside FormWriter core — 0 matches
- `begin_form(` with arguments — 0 matches (excluding class definitions)
