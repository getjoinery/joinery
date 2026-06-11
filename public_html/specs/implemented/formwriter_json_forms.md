# Server-Driven Forms — FormWriter JSON Output (Core Platform)

Make native apps thin clients for forms: the server returns a form
*definition* (fields, labels, values, validation, visibility rules) as JSON,
and each app has exactly one generic renderer instead of a hand-built screen
per form. FormWriter already owns the canonical definition of every form on
the platform — fields, validation (auto-detected from model
`$field_specifications`), visibility rules, prefill. This spec exposes that
knowledge as data.

The payoff: a form changes once, server-side, and the web page, the iPhone
app, and the Android app all pick it up — no app release, no App Store review
cycle. Prerequisite ordering: after `specs/user_session_api_keys.md`, before
Phase 1 of the app specs (`specs/scrolldaddy_ios_app.md`,
`specs/scrolldaddy_android_app.md`), whose account screens consume it.

## Design decisions

| Decision | Choice | Why |
|---|---|---|
| Renderer | New `FormWriterV2JSON` subclass of `FormWriterV2Base` | The 2.5.0 prepare/render split already isolates all behavioral logic in `prepare*Data()`; theme subclasses are pure markup. The JSON class implements each abstract `render*($data)` by accumulating the already-normalized `$data` into a definition array — it inherits autofill, model validation auto-detection, and visibility rules for free |
| Single source of truth | Per-action **form builder functions** in the action's logic file; the web view calls the builder with a theme FormWriter, the API calls it with the JSON FormWriter | One declaration drives both surfaces. Model form helpers (`Address::renderFormFields()`) already prove FormWriter-implementation-agnostic builders work |
| Fetch | `GET /api/v1/form/{action_name}` | Pairs one-to-one with the existing action endpoints |
| Submit | Unchanged: `POST /api/v1/action/{action_name}` | The action API already returns field-keyed `validation_errors` on 422 — the error round-trip exists today; the renderer just maps it back onto fields |
| CSRF | Disabled in JSON mode (`csrf => false`) | CSRF is a cookie-session attack; API requests authenticate via key headers, which browsers never attach cross-origin. Matches how actions already accept API submissions |
| Unsupported constructs | Fail loudly at definition time | A builder using a non-serializable feature (`custom_script`, unsupported field type) throws when rendered by `FormWriterV2JSON` — caught in development, never silently dropped in production |
| Schema evolution | Integer `schema_version`, additive-only within a version | Old app binaries keep working; renderer falls back per form (see Renderer contract) when it sees a higher version |

## Definition schema (v1)

`GET /api/v1/form/account_edit` returns:

```json
{
  "schema_version": 1,
  "form": {
    "name": "account_edit",
    "submit_to": "/api/v1/action/account_edit",
    "submit_label": "Save Changes"
  },
  "fields": [
    {"type": "hidden", "name": "edit_primary_key_value", "value": "123"},
    {"type": "text", "name": "usr_first_name", "label": "First Name",
     "value": "Jeremy", "required": true,
     "validation": {"required": true, "maxlength": 64}},
    {"type": "drop", "name": "usr_timezone", "label": "Timezone",
     "value": "America/Chicago", "empty_option": "Select...",
     "options": {"America/Chicago": "Central Time", "...": "..."}},
    {"type": "checkbox", "name": "usr_newsletter", "label": "Newsletter",
     "checked_value": "1", "is_checked": true,
     "helptext": "Monthly product updates"}
  ]
}
```

Common field keys: `type`, `name`, `label`, `value`, `required`, `readonly`,
`disabled`, `helptext`, `placeholder`, `validation` (object of
JoineryValidator rule names — the same rules FormWriter auto-detects from
model `$field_specifications`), `visibility_rules` (verbatim — the existing
format is already declarative data). Type-specific keys mirror the
corresponding `prepare*Data()` output (`options`, `empty_option`,
`checked_value`, `min`/`max`/`step`, etc.). Purely cosmetic HTML keys
(`class`, `id`, `autofocus`) are not serialized.

**Compound submit contracts are part of the schema.** Date/time/datetime
fields submit as the same multi-part POST keys the web forms produce, so
`FormWriterV2Base::process_datetimeinput()` and existing logic files work
unchanged; the field definition lists its `submit_parts` (e.g.
`name_date`, `name_hour`, `name_minute`, `name_ampm`) and the renderer emits
them. Submissions are the action API's normal JSON body — keys and value
shapes identical to what the web form would POST (including
`checkboxList` array submission).

## Field type inventory

Every FormWriter field type, decided once:

| FormWriter method | v1 | JSON `type` / native widget | Notes |
|---|---|---|---|
| `textinput` | ✅ | `text` / text field | incl. `prepend` as a display-hint key |
| `passwordinput` | ✅ | `password` / secure field | `strength_meter` serialized as a flag |
| `numberinput` | ✅ | `number` / numeric field | `min`/`max`/`step` |
| `textarea` | ✅ | `textarea` / multiline field | |
| `dropinput` | ✅ | `drop` / picker | `ajaxendpoint` serializes as `search_endpoint`; renderer does the same debounced fetch the web JS does |
| `checkboxinput` | ✅ | `checkbox` / toggle | |
| `radioinput` | ✅ | `radio` / segmented or radio group | |
| `checkboxList` | ✅ | `checkbox_list` / multi-select list | array submission |
| `dateinput` | ✅ | `date` / native date picker | |
| `timeinput` | ✅ | `time` / native time picker | compound submit parts |
| `datetimeinput` | ✅ | `datetime` / date + time pickers | compound submit parts; values arrive in the user's timezone exactly as on the web |
| `hiddeninput` | ✅ | `hidden` / not rendered | round-trips values incl. `edit_primary_key_value` |
| `submitbutton` | ✅ | form-level `submit_label` | one submit per form in v1 |
| `fileinput` | ❌ v1 | — | needs multipart + upload pipeline over the API; future schema version |
| `imageinput` | ❌ v1 | — | same as `fileinput` |
| `textbox` (rich text) | ❌ v1 | — | native rich-text editing is its own project |
| `repeater` | ❌ v1 | — | component-system use case, not account forms |
| `visibility_rules` | ✅ | serialized verbatim | renderer implements show/hide natively |
| `custom_script`, `onchange` | ❌ ever | — | JavaScript is not data; `FormWriterV2JSON` **throws** if a field carries one |

A builder that uses a ❌ type throws in JSON mode (loud-failure rule). The
initial form set below uses none of them.

## Core changes

### `includes/FormWriterV2JSON.php` (new)

Extends `FormWriterV2Base`. Implements each abstract `render*($data)` by
appending the prepared data (filtered to schema keys) to an internal
definition; `begin_form()`/`end_form()` produce no output; constructor forces
`csrf => false`; `getDefinition()` returns the schema array. Version the file
and bump `FormWriterV2Base` per the version-number rule.

### `FormWriterV2Base` additions

- `set_values(array $values)` and `set_model($model)` — values currently bind
  only at construction; builders own prefill, so the API endpoint can
  construct the FormWriter generically and let the builder bind data.
  Available to all subclasses (useful on the web side too).

### Form builder companions

Convention mirroring `_api()`: in the action's logic file,

```php
function account_edit_logic_form($formwriter, $user = null) {
    $formwriter->set_model($user);
    $formwriter->textinput('usr_first_name', 'First Name', ['required' => true]);
    // ... fields ...
    $formwriter->submitbutton('btn_submit', 'Save Changes');
}
```

Exposure rule: the form endpoint serves `{action_name}` iff both
`{action}_logic_api()` and `{action}_logic_form()` exist. The corresponding
web view is updated to call the same builder with its theme FormWriter —
that refactor is what makes the definition single-source, and it ships per
form in the initial set (not as a separate cleanup).

### `GET /api/v1/form/{action_name}` (new, in `api/apiv1.php`)

- Auth mirrors the action's `requires_session` declaration: sessioned forms
  require key auth and prefill from `$api_user`; sessionless forms
  (`register`, `password_reset_1`, `password_reset_2`) are served without key
  headers, riding the same pre-auth dispatch as `auth/*` — the unauthenticated
  exemption for *submitting* those actions is specified in
  `specs/user_session_api_keys.md`.
- HTTPS enforcement and rate limiters apply unchanged.
- `GET /api/v1/actions` discovery output gains `"has_form": true|false`.

### Initial form set (extract builder + update web view + expose)

The forms the app account module needs: `register`, `account_edit`,
`password_edit`, `contact_preferences`, `password_reset_1`,
`password_reset_2`. Login is **not** server-driven — it is the fixed
two-field bootstrap contract of `auth/login` (Keychain/biometric
integration is inherently native).

Anything beyond this set (e.g. `address_edit`, `phone_numbers_edit`, plugin
forms) opts in later by adding its `_form()` companion — no further core
work.

## Renderer contract (consumed by the app specs)

Each of JoineryKit (SwiftUI) and `joinery-android` (Compose) ships **one**
generic form renderer:

- Renders every ✅ type; implements `visibility_rules` natively.
- Applies the serialized `validation` rules locally for immediate UX
  (required, email, min/maxlength, equalTo, pattern); unknown rule names are
  ignored — the server remains authoritative and the renderer maps the 422
  `validation_errors` response onto fields by name.
- Submits the action API JSON body with the schema's submit contract
  (compound parts, array fields).
- **Fallback, per form:** on a `schema_version` above what it supports or a
  field type it doesn't recognize, the renderer shows a "please update the
  app or use the website" panel for that form instead of guessing. Account
  screens degrade individually; the rest of the app is unaffected.

## Tests

`/tests/` additions:

- `FormWriterV2JSON` unit: each supported field method produces the documented
  schema shape; `custom_script` throws; CSRF is off; hidden values round-trip.
- Single-source test: building `account_edit` with `FormWriterV2HTML5` and
  `FormWriterV2JSON` yields the same field names in the same order.
- Endpoint: sessioned form requires auth and prefills the acting user's
  values; sessionless `register` form fetches with no key; unknown action and
  action-without-`_form()` give 404.
- Round-trip: fetch `account_edit` definition → submit a valid body → 200 and
  persisted; submit an invalid body → 422 whose `validation_errors` keys all
  appear as field names in the fetched definition.
- Datetime contract: a definition's `submit_parts` posted as documented is
  accepted by `process_datetimeinput()` unchanged.

## Acceptance checklist

1. `GET /api/v1/form/account_edit` with a session key returns the documented
   schema, prefilled with the acting user's data.
2. Submitting that form through the existing action endpoint persists, and an
   invalid submission's `validation_errors` map onto definition field names.
3. The web profile/account page renders through the same builder function
   (single source), with no visual or behavioral regression.
4. `register` form definition is fetchable with no credentials; the other
   five initial forms are exposed and submit correctly.
5. Adding a field to a builder makes it appear in the web form and the JSON
   definition simultaneously with no other change.
6. A builder using `custom_script` or an unsupported type throws in JSON mode.
7. Full validator pass (`php -l` + `validate_php_file.php`) on all touched
   files.

## Documentation deliverables (on implementation)

- `docs/formwriter.md` — new section: JSON output mode, the builder-companion
  convention, the schema reference, supported/unsupported field types, and
  `set_values()`/`set_model()`.
- `docs/api.md` — the `/api/v1/form/{action}` endpoint, its auth mirror rule,
  and the `has_form` discovery flag.
- `docs/mobile_apps.md` (created by the app specs) — the renderer contract
  section points here rather than restating the schema.
