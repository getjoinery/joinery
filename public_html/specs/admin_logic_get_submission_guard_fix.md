# Fix: admin logic files run save/redirect handlers on GET (`if($input)` regression)

## Overview

Commit **5b7f2251 "Fix bug with inconsistent logic file calls"** refactored ~40
admin pages to a logic-function pattern where the page calls
`thing_logic(array_merge($_GET, $_POST))` and the logic guards its
mutate-and-redirect handler with **`if($input){ … ->save(); return
LogicResult::redirect(...); }`**.

That guard is wrong. `$input` is **never empty on a GET**, so these handlers run
when a page is merely *opened*, not submitted. Consequences range from infinite
redirect loops (the Settings pages) to **silent data corruption** (edit pages
that write `$input[$field]` with no `isset` guard null out the record on GET).
This spec defines the fix, the full file inventory, and a guard against
recurrence.

## How it breaks

1. Apache rewrites every request to `serve.php?__route=<path>` (vhost
   `RewriteRule ^(.*)$ serve.php?__route=$1`), so **`$_GET['__route']` is set on
   every request**.
2. `serve.php` dispatches with `$_REQUEST['__route']` but **never unsets**
   `$_GET['__route']` (`RouteHelper` only strips it from a *cache-key copy*, not
   the live superglobal — `RouteHelper.php` ~line 975).
3. Admin pages call their logic with `array_merge($_GET, $_POST)`, so `$input`
   **always contains at least `__route`** (and, for edit pages, the record id
   from the link). `$input` is therefore truthy on every GET.
4. The logic does `if($input){ …save…; redirect; }`. On a GET this runs the save
   path and the redirect, every time.

The intended meaning of that guard was "the form was submitted." The correct
signal for that is the **HTTP method (POST)**, not "is `$input` non-empty."

## Impact & severity tiers

All 26 affected logic files run their handler on GET; the *effect* depends on
what the handler does:

- **Tier 1 — redirect loop (page unusable).** Handler redirects to the same URL.
  → `admin_settings`, `admin_settings_email` returned `ERR_TOO_MANY_REDIRECTS`.
  No data loss (they only write settings whose keys are present in `$input`,
  which on a GET is none).
- **Tier 2 — data corruption / forced error (worst).** Handler sets model fields
  from `$input[$field]` **without an `isset` guard**, then `save()`s. On a GET
  those keys are undefined → fields set to `null` → the record is overwritten
  with nulls, or `prepare()/save()` throws a validation error. Opening the edit
  form mutates or errors. Example confirmed: `admin_contact_type_edit_logic.php`
  (`foreach($editable_fields as $field){ $contact_type->set($field,
  $input[$field]); } … ->save();`).
- **Tier 3 — bounce to view (annoying, no data loss).** Handler guards each
  field write with `isset`, so a GET writes nothing, but still `save()`s the
  unchanged record and **redirects to the view page** — so the edit form can
  never be reached.

This has likely gone unnoticed only because 5b7f2251 is recent and the edit
pages haven't been exercised since. As shipped, large parts of the admin
edit/save surface are broken on GET, some destructively.

## Root causes (two layers — fix both)

1. **Wrong submission guard (the real bug).** `if($input)` is used to mean "form
   submitted." It must be an explicit POST check. *This is the correctness fix
   and must be applied per file.*
2. **`__route` pollutes page input (the footgun).** `$_GET['__route']` silently
   rides along into every `$input`. Even after the per-file fix, this will keep
   making `if($input)`-style code misfire. *Strip it at the source.*

Fixing only (2) is insufficient: edit pages also carry the record id in `$input`,
so they stay broken without (1). Fixing only (1) leaves the footgun for future
code. Do both.

## The fix

### Layer 1 (correctness, per file): gate mutation on a real submission

Replace the `if($input)` submission guard with an explicit POST check on the
**form-save handler**:

```php
// before
if($input){ … ->save(); return LogicResult::redirect(...); }

// after
if($_SERVER['REQUEST_METHOD'] === 'POST'){ … ->save(); return LogicResult::redirect(...); }
```

Provide and use a **shared, greppable idiom** so this is consistent and lintable
— add once to `LibraryFunctions`:

```php
/** True only on an actual form POST. Use this — never `if($input)` — to guard
 *  a logic function's save/mutate/redirect handler. */
public static function isFormSubmission(): bool {
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}
```

…and write handlers as `if (LibraryFunctions::isFormSubmission()) { … }`.

**This is not a blind sed.** Each file must be eyeballed because the handler
shape varies:

- **Pure edit/save handlers** (the majority, `*_edit_logic.php`): wrap the whole
  block in the POST check. On GET, fall through to the existing
  `LogicResult::render(...)` that draws the form. Confirm such a render path
  exists (it does in every file reviewed).
- **Mixed handlers** (`admin_user_logic`, `admin_survey_logic`,
  `admin_question_logic`, `admin_users_message_logic`): these also perform
  **GET-link actions** (delete/toggle via `$input['action']`/an id). Those
  explicit, param-keyed actions are handled in their own branches **before** the
  `if($input)` block and must be preserved as-is; only the form-save block moves
  behind the POST check. Do **not** convert intentional GET actions to POST in
  this spec (that is a separate CSRF-hardening effort — see Non-goals).
- **Files with two `if($input)` blocks** (`admin_address_edit_logic`,
  `admin_phone_edit_logic`): gate each save block; verify the load/prefill block
  still runs on GET.

After each edit: load the page on GET (form renders, nothing saved, no redirect)
and submit it (still saves), and run `php -l` + `validate_php_file.php`.

### Layer 2 (hardening, one place): stop `__route` leaking into page input

In `serve.php`, immediately after the route is extracted for dispatch, unset it
from the request superglobals so it never reaches page logic:

```php
$__route = $_REQUEST['__route'] ?? '';
unset($_GET['__route'], $_POST['__route'], $_REQUEST['__route']);
RouteHelper::processRoutes($routes, $__route);
```

First confirm nothing downstream reads `$_GET['__route']`/`$_REQUEST['__route']`
after dispatch (grep; `RouteHelper` already works off the passed-in value and its
cache copy). This removes the silent footgun and makes `if($input)` behave on
no-parameter pages — defense-in-depth behind the real per-file fix.

## Inventory — 26 affected `adm/logic` files

To fix (verify tier & handler shape per file):

| File | Notes |
|------|-------|
| `admin_contact_type_edit_logic.php` | **Tier 2** (no isset guard — confirmed corrupting) |
| `admin_address_edit_logic.php` | two `if($input)` blocks |
| `admin_phone_edit_logic.php` | two `if($input)` blocks |
| `admin_api_key_edit_logic.php` | edit/save |
| `admin_comment_edit_logic.php` | edit/save |
| `admin_coupon_code_edit_logic.php` | edit/save (+ child rows) |
| `admin_email_edit_logic.php` | edit/save |
| `admin_event_edit_logic.php` | large handler |
| `admin_event_type_edit_logic.php` | edit/save |
| `admin_mailing_list_edit_logic.php` | edit/save |
| `admin_order_edit_logic.php` | edit/save |
| `admin_product_edit_logic.php` | edit/save (+ early redirects) |
| `admin_product_group_edit_logic.php` | edit/save |
| `admin_product_version_edit_logic.php` | edit/save |
| `admin_seo_page_edit_logic.php` | edit/save (+ delete branch) |
| `admin_shadow_session_edit_logic.php` | edit/save |
| `admin_subscription_tier_edit_logic.php` | edit/save |
| `admin_survey_edit_logic.php` | edit/save |
| `admin_survey_logic.php` | **mixed** (GET actions + save) |
| `admin_question_logic.php` | **mixed** (GET actions + save) |
| `admin_user_add_logic.php` | create/save |
| `admin_user_logic.php` | **mixed** (GET actions + save) |
| `admin_users_edit_logic.php` | edit/save |
| `admin_users_message_logic.php` | **mixed** (send + save) |

Already fixed this session (use as the reference pattern; verify they match the
final idiom):
- `admin_settings_logic.php`, `admin_settings_email_logic.php` — gated on
  `REQUEST_METHOD === 'POST'`.
- `admin_agent_file_edit_logic.php` — gated on submit-button presence (predates
  this spec; re-align to `isFormSubmission()` for consistency).

Run a final `grep -rlE 'if ?\( ?\$input ?\)' adm/logic plugins/*/logic` after the
sweep — it should return nothing.

## Prevent recurrence

- **Lint rule.** Extend `maintenance_scripts/dev_tools/validate_php_file.php` to
  flag `if ($input)` / `if($input)` used as a submission guard in `*_logic.php`
  files, with the message "use `LibraryFunctions::isFormSubmission()` — `$input`
  is non-empty on GET (carries `__route`)." This turns the class of bug into a
  caught error on the validation step every change already runs.
- **Convention doc.** Document the rule (below) so the next refactor doesn't
  reintroduce it.

## Testing

- **Per page (manual checklist, all 26):** GET renders the form and performs **no
  write and no redirect**; POST saves and redirects as before; for mixed pages,
  the GET-link actions still work.
- **Regression test** (`tests/`): a small harness that, for each affected logic
  function, calls it with a GET-shaped `$input` (only `__route` + an id) under
  `$_SERVER['REQUEST_METHOD']='GET'` and asserts the result is a `render` (not a
  `redirect`) and that no `save()` occurred (spy/fixture record unchanged). This
  locks the fix in.
- **Root test:** after the `serve.php` change, assert `$_GET['__route']` is unset
  by the time a view runs, and that routing still resolves.
- `php -l` + `validate_php_file.php` on every changed file; the new lint rule
  must pass clean.

## Documentation

- Add a **"Detecting form submission in logic files"** section to
  `docs/logic_architecture.md`: never guard a save handler with `if($input)`;
  use `LibraryFunctions::isFormSubmission()`; why (`$input` always carries
  `__route`, and edit pages carry the record id). Cross-reference from the admin
  pages guide (`docs/admin_pages.md`).
- Note the `serve.php` `__route` invariant in `docs/routing.md` (the param is
  stripped from request superglobals after dispatch).

## Versioning

- Bump `@version` on `serve.php`, `LibraryFunctions.php`, the validator, and each
  modified logic file.
- No schema or settings changes; no migration.

## Rollout / ordering

1. Land Layer 2 (`serve.php` `__route` strip) + the `isFormSubmission()` helper
   first — immediately stops the Tier-1 loops and removes the footgun.
2. Fix the **Tier-2 (data-corruption)** files next, highest priority
   (`admin_contact_type_edit` and any other no-`isset` setters found during the
   sweep).
3. Sweep the remaining edit/save and mixed files.
4. Add the lint rule last so the now-clean tree passes, preventing reintroduction.

## Non-goals

- **Converting intentional GET mutations (delete/toggle links) to POST.** Those
  are a real CSRF-hardening concern but a separate effort; this spec only stops
  *form-save* handlers from firing on GET and preserves existing explicit
  GET-action branches.
- **Re-architecting the page→logic calling convention** (e.g., passing a typed
  request object instead of `array_merge($_GET,$_POST)`). The targeted fix +
  helper + lint is sufficient; a broader convention change is optional future
  work.
- **Reverting 5b7f2251.** It made other intended fixes; this is a fix-forward.
