# FormWriter Visibility Rules — Checkbox & Radio Triggers

## Overview

FormWriter can show and hide fields automatically based on another field's value (`visibility_rules`), so forms reveal only the inputs that apply — no hand-written JavaScript. Today that only works when the controlling field is a **dropdown**. If a developer wants a checkbox ("Repeats") or a radio group ("Ends: never / on date / after N") to drive what's shown, `visibility_rules` silently does the wrong thing or nothing at all, and they fall back to hand-rolled JS — which the platform's own conventions tell them not to do.

This spec makes checkboxes and radio groups first-class visibility **triggers**, alongside the dropdowns that already work. The fix lives entirely in the FormWriter base class, so every form on the platform gains the capability at once. It is purely additive: selects behave exactly as before.

The immediate motivation is the recurring-calendar authoring UI, whose "Repeats" checkbox and monthly-mode / ends-mode radio groups currently can't use `visibility_rules` and are therefore hand-rolled. But the limitation is general, so the fix belongs in FormWriter, not in the calendar.

---

## Why It Doesn't Work Today

`generateVisibilityScript()` (in `includes/FormWriterV2Base.php`) emits one shape of JavaScript for every trigger:

```js
const selected = document.getElementById(fieldId).value;   // reads .value
const rules = visibilityRules[selected] || visibilityRules["default"] || {};
// ... show/hide ...
selectEl.addEventListener("change", update…Visibility);     // one element
```

There are **zero** `.checked` references in the file. The consequences:

- **Checkbox trigger:** `.value` is the checkbox's constant `value` attribute (e.g. `"1"`) regardless of whether it's ticked, so the rule key never reflects the checked state. `outputCheckboxInput()` *does* emit the script — so it runs, but it can only ever resolve one rule key. Effectively broken.
- **Radio-group trigger:** `getElementById(name)` finds nothing (radios render with per-option ids `{name}_{value}` and share `name="{name}"`), so the script can't read the chosen value. `outputRadioInput()` and `outputCheckboxList()` don't emit the script at all. No support.

**Blast radius of changing this is nil:** an audit of every `visibility_rules` usage in the codebase (core admin, plugins, examples) shows **all triggers are dropdowns**. No form relies on the broken checkbox path. Selects are untouched by this change.

---

## The Rendered DOM (the contract we build on)

All themes render these consistently (the `data['name']` convention), so one shared base-class script covers HTML5, Bootstrap, and Tailwind:

- **Select:** single `<select id="{name}">`; selected value is `el.value`.
- **Checkbox:** single `<input type="checkbox" id="{id}" name="{name}" value="{checked_value}">` inside `#{name}_container`; state is `el.checked`.
- **Radio group:** N `<input type="radio" name="{name}" id="{name}_{value}" value="{value}">` inside `#{name}_container`; chosen value is `querySelector('input[name="{name}"]:checked').value`.

Show/hide **targets** already resolve theme-agnostically via `#{id}_container` with `#{id}` fallback — unchanged.

---

## Design

Make `generateVisibilityScript()` **trigger-type aware**. Its signature gains the trigger's type; each output method passes its own. Only the *read* and the *listener wiring* differ by type — the rule structure, target resolution, fade CSS, `default` fallback, and initial-on-load evaluation are all shared and unchanged.

| Trigger type | Read the current key | Listen on |
|---|---|---|
| `select` (today) | `el.value` | the `<select>` element |
| `checkbox` | `el.checked ? 'checked' : 'unchecked'` | the checkbox element |
| `radio` | `document.querySelector('input[name="{name}"]:checked')?.value ?? ''` | every `input[name="{name}"]` |

### Rule-key conventions (the authoring contract)

- **Select / radio:** keys are option values, exactly as today, e.g. `'weekly'`, `'date'`. Plus the existing `'default'` fallback.
- **Checkbox:** keys are `'checked'` and `'unchecked'` (with `'default'` honored). Example:

```php
$formwriter->checkboxinput('entry_repeats', 'Repeats', [
    'visibility_rules' => [
        'checked'   => ['show' => ['rec_frequency', 'rec_interval', 'rec_ends']],
        'unchecked' => ['hide' => ['rec_frequency', 'rec_interval', 'rec_ends']],
    ],
]);
```

```php
$formwriter->radioinput('rec_ends', 'Ends', [
    'options' => ['never' => 'Never', 'date' => 'On date', 'count' => 'After N occurrences'],
    'visibility_rules' => [
        'never' => ['hide' => ['rec_end_date', 'rec_count']],
        'date'  => ['show' => ['rec_end_date'], 'hide' => ['rec_count']],
        'count' => ['show' => ['rec_count'], 'hide' => ['rec_end_date']],
    ],
]);
```

### Wiring changes

- `outputRadioInput()` — emit the visibility script (type `radio`) when `visibility_rules` are present. (Currently emits nothing.)
- `outputCheckboxInput()` — keep emitting, but pass type `checkbox` so it uses the `.checked` read. (Currently uses the broken `.value` read.)
- `outputDropInput()` — pass type `select`. No behavior change.
- `outputCheckboxList()` with `type='radio'` — a single-select radio list is a valid trigger; wire it like `radio`.

### Validation

Extend `validateVisibilityRules()` to be type-aware:

- Checkbox triggers: keys must be a subset of `{checked, unchecked, default}` — anything else is almost certainly a mistake (e.g. keying on a value), so fail fast at generation with a clear message.
- A **checkbox-list with `type='checkbox'`** (a multi-select group, like the weekly day picker) has no single value and cannot be a trigger — throw a `DisplayableUserException` at generation time directing the author to use it as a *target* instead. (It works fine as a show/hide target; only as a trigger is it invalid.)
- Keep the existing show/hide-conflict and non-string-id checks for all types.

### JSON / native-app parity

`FormWriterV2JSON` already serializes `visibility_rules` verbatim and includes each field's `type`. So the JSON definition needs **no schema change** — but the generic native renderer must apply the same per-type read semantics (checkbox → checked/unchecked, radio → checked option value). This contract is documented in `docs/formwriter.md` and `docs/api.md` so web and native stay in lockstep. A JSON unit-test asserts a checkbox/radio field serializes its `type` + rules so the contract is testable.

---

## Out of Scope

- Multi-trigger conditions (AND/OR across several fields), numeric/range comparisons, or "show when any of N" — the model stays single-trigger, keyed by one field's current value. A genuine need for compound logic gets its own spec.
- `checkboxList` with `type='checkbox'` as a trigger (multi-value, ambiguous) — explicitly rejected, not silently degraded.
- Any change to how show/hide **targets** are resolved or animated.

---

## Phases

### Phase 1 — Engine + wiring

1. Add the `$type` parameter to `generateVisibilityScript()`; implement the `select` (unchanged), `checkbox`, and `radio` read/listener branches.
2. Wire `outputRadioInput()`, `outputCheckboxInput()` (fix to checked-based), `outputDropInput()` (pass `select`), and `outputCheckboxList()` (radio type).
3. Extend `validateVisibilityRules()` with the per-type checks and the checkbox-list-trigger rejection.
4. Unit tests: generated JS reads `.checked` for a checkbox trigger and `:checked` for a radio group; selects are byte-for-byte unchanged; JSON definition carries type + rules for checkbox/radio.

*Checkpoint:* a checkbox and a radio group each drive show/hide on load and on change, in an HTML5 form, with no hand-written JS.

### Phase 2 — Docs + examples

1. Expand `docs/formwriter.md` §6: the three trigger types, the per-type rule-key conventions, and the checkbox-list-as-target-only rule.
2. Note the native-renderer contract in the JSON section of `docs/formwriter.md` and in `docs/api.md`'s form-definition endpoint.
3. Add a checkbox-driven and a radio-driven example to `utils/forms_example_html5v2.php` and `utils/forms_example_bootstrapv2.php`.

*Checkpoint:* the docs example for a checkbox/radio trigger works when pasted into a form.

---

## Files

**Modify:** `includes/FormWriterV2Base.php` — `generateVisibilityScript()` type-awareness; `outputRadioInput` / `outputCheckboxInput` / `outputDropInput` / `outputCheckboxList` wiring; `validateVisibilityRules()` per-type checks.

**Verify (likely no change):** `includes/FormWriterV2JSON.php` — confirm checkbox/radio definitions already include `type` + `visibility_rules`.

**Modify (docs):** `docs/formwriter.md` (§6 + JSON contract), `docs/api.md` (form-definition endpoint note).

**Modify (examples/tests):** `utils/forms_example_html5v2.php`, `utils/forms_example_bootstrapv2.php`, `tests/unit/formwriter_json_test.php`, plus a new test for web-script generation.

---

## Follow-on

With this in place, the recurring-calendar authoring UI can replace its hand-rolled show/hide JavaScript with declarative `visibility_rules` on real FormWriter checkbox/radio/select inputs — the original goal that surfaced this limitation. That conversion is a separate spec; it depends on this one.
