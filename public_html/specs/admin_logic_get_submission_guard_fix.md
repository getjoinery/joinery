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

This is a targeted correctness fix for one repeated mistake — not a refactor of
the page→logic calling convention. The handler shape stays as-is; only the
broken submission test changes, in one shared place, plus a one-time strip of the
`__route` footgun that made it misfire.

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

…and write handlers as `if (LibraryFunctions::isFormSubmission()) { … }`. The
*meaning* of "submitted" then lives in exactly one place; each file just calls
it. The line is still per-file, but it is correct and named — the fragility of
`if($input)` was that it was silently wrong, not that it appeared in each file.

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

Strip `__route` in the routing component that already owns it — **not** in
`serve.php` (keep the front controller free of this logic). `RouteHelper::processRoutes($routes, $request_path)`
already receives the route value as `$request_path` and already removes `__route`
from its cache-key copy (`RouteHelper.php:975`), so it has no further need of the
superglobal. Unset it once at the **top of `processRoutes()`**, before the
cache/debug/dispatch logic:

```php
public static function processRoutes($routes, $request_path) {
    // __route is routing metadata injected by the Apache rewrite; it must not
    // reach page logic ($input). The route value is already in $request_path.
    unset($_GET['__route'], $_POST['__route'], $_REQUEST['__route']);
    …
}
```

`serve.php:443` still passes `$_REQUEST['__route'] ?? ''` in (read *before* the
strip runs, so unaffected). The existing cache-key strip at `:975` becomes a
harmless no-op (the key is already gone) — leave it as defensive, or drop the now-
redundant `unset($cache_params['__route'])`.

**Downstream-read audit (done — safe to strip).** A full grep for
`$_(GET|POST|REQUEST)['__route']` reads found only one genuine post-dispatch
consumer: `theme/tailwind/views/404.php` reads `$_REQUEST['__route']` as the
*middle* of a fallback chain (`$debug_info['requested_path'] ?? $_REQUEST['__route']
?? $_SERVER['REQUEST_URI'] ?? 'unknown'`) — stripping it degrades to `REQUEST_URI`,
an equivalent source (cosmetic, 404 debug display). `includes/PublicPageBase.php`
already lists `__route` among params it strips for canonical URLs (our global
strip makes that a no-op); `RouteHelper` works off the passed value + a cache-key
copy; `utils/route_debug.php` writes its own value under CLI. Nothing breaks. This
removes the silent footgun and makes `if($input)` behave on no-parameter pages —
defense-in-depth behind the real per-file fix.

### Layer 3 (recurrence guard, one place): enforce GET-is-read-only at the write boundary

Layers 1–2 correct the known cases. Layer 3 makes the *class* of bug impossible to
reintroduce silently — not by linting `*_logic.php` text, but by enforcing the
actual invariant ("a GET request must not persist data") at the single chokepoint
every mutation passes through: `SystemBase`'s write methods. This catches anything
Layer 1 misses or any future code reintroduces — including plugin code, dynamic
calls, and non-`if($input)` forms a text lint can't see — and turns the dangerous
property of this bug (silent corruption that goes unnoticed until a page is
exercised) into a loud, located failure on first hit.

Add a static opt-out and an assertion to `includes/SystemBase.php`, and call it at
the top of `save()` (~line 1045), `soft_delete()` (~line 724), and
`permanent_delete()` (~line 865):

```php
/** Set true only around an intentional GET-action mutation (e.g. a delete link). */
public static $allow_get_mutation = false;

private static function assert_not_get_mutation(string $op): void {
    // Exempt CLI / cron / scheduled tasks (no HTTP method) and explicit opt-outs.
    if (PHP_SAPI === 'cli' || !isset($_SERVER['REQUEST_METHOD']))  return;
    if (self::$allow_get_mutation)                                 return;
    if ($_SERVER['REQUEST_METHOD'] !== 'GET')                      return;

    $msg = "GET-request mutation ({$op} on " . static::class . ') at '
         . ($_SERVER['REQUEST_URI'] ?? '?') . ' — a GET must not persist data. '
         . 'Guard the save with LibraryFunctions::isFormSubmission(), or, for an '
         . 'intentional GET action, set SystemBase::$allow_get_mutation = true.';
    error_log($msg . "\n" . (new Exception())->getTraceAsString());

    // Loud in dev (the `debug` setting), log-only in prod so a slip is observable
    // without fataling a user-facing page.
    if (Globalvars::get_instance()->get_setting('debug')) {
        throw new SystemBaseException($msg);
    }
}
```

…with `self::assert_not_get_mutation('save')` (etc.) as the first statement in each
write method.

**Intentional GET mutations must opt in.** The existing delete/toggle **GET-action
links** (e.g. `admin_question_logic`'s `action=delete` branch) legitimately mutate
on GET. Each such branch wraps its write:

```php
SystemBase::$allow_get_mutation = true;
try { $question->soft_delete(); }
finally { SystemBase::$allow_get_mutation = false; }
return LogicResult::redirect('/admin/admin_questions');
```

This opt-in is not overhead — satisfying the tripwire forces every intentional
GET mutation to be **explicitly marked**, which inventories exactly the GET-action
surface the separate CSRF-hardening effort will need. The work the tripwire
requires *is* that documentation.

Tradeoffs (accepted): you must find and opt-in every intentional GET mutation or
it false-positives — that discovery is ≈ the mixed-page audit Layer 1 needs
anyway; CLI/cron contexts are exempted (no `REQUEST_METHOD`); prod logs rather
than throws.

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

**Progress / done marker.** A file is unmigrated exactly while it still contains
the `if($input){` guard. Track with (requires the trailing `{`, so it matches the
guard but not the explanatory comments the fix adds — the loose form without `{`
false-flags fixed files whose comments mention `if($input)`):

```bash
grep -rlnE 'if[[:space:]]*\([[:space:]]*\$input[[:space:]]*\)[[:space:]]*\{' adm/logic plugins/*/logic
```

Baseline at spec time: **24 files** (the inventory below minus the two
`admin_settings*` files already patched). Each drops off as its guard becomes
`if (LibraryFunctions::isFormSubmission()) {`. A final run after the sweep should
return nothing.

## Non-admin GET-mutation surface (Layer-3 opt-in audit)

The Layer-3 tripwire is global, so it also surfaces GET mutations outside `adm/`.
220 files call a write method; 138 are non-admin, but that number collapses fast —
most can't trip the wire:

| Bucket | Files | Tripwire impact |
|---|---|---|
| CLI / cron / `tasks` / `migrations` / `tests` | ~22 | **Auto-exempt** — no `REQUEST_METHOD`; never fires. Zero work. |
| `data/` model internals (`$this->save()` inside methods) | ~39 | Not decision sites — fire in the caller's context, no own opt-in. |
| Web entrypoints that mutate (core `logic`/`ajax`/`api` ≈ 25; plugin `logic`/`ajax`/`views` ≈ 22; plugin `admin` ≈ 13) | ~60 | Only those that mutate **on GET** trip it; POST handlers (forms, webhooks, most ajax) stay silent and need nothing. |

The realistic opt-in surface is single-to-low-double digits, not 138. A heuristic
sweep ("a GET/`action` param drives a mutation") surfaces **9 concrete core
candidates** — the same endpoints the `FormWriterV2Base` CSRF TODO already names
(conversations, reactions, notifications, entity_photos, checkout):

```
logic/billing_logic.php          ajax/notifications_ajax.php
logic/account_edit_logic.php     ajax/entity_photos_ajax.php
logic/change_tier_logic.php      ajax/conversations_ajax.php
logic/cart_charge_logic.php      ajax/checkout_ajax.php
api/apiv1.php
```

…plus an unaudited subset of the ~22 plugin-web files. Each confirmed GET mutation
gets either corrected (guard with `isFormSubmission()`) or, if intentional, opted
in with `$allow_get_mutation` — exactly as for the admin GET-action links.

**The exact list is not statically knowable** — whether a given call site is
reached by GET or POST is a runtime fact — which is why the rollout ships the
tripwire **log-only everywhere first** and lets the logs produce the definitive
list (see Rollout).

## Prevent recurrence

- **Primary: the Layer-3 write-boundary tripwire** (above). This is the real
  recurrence guard — a runtime invariant enforced in one place that catches the
  entire bug class (including plugin/dynamic/non-`if($input)` code), not a
  pattern-match on one filename glob. Throws in dev, logs in prod.
- **Optional, cheap secondary: lint rule.** Extend
  `maintenance_scripts/dev_tools/validate_php_file.php` to flag `if ($input)` /
  `if($input)` used as a submission guard in `*_logic.php` files, message: "use
  `LibraryFunctions::isFormSubmission()` — `$input` is non-empty on GET (carries
  `__route`)." Catches the specific idiom at edit-time, before the page is even
  run. Nice-to-have on top of the tripwire, not load-bearing.
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
- **Root test:** after the `RouteHelper` change, assert `$_GET['__route']` is unset
  by the time a view runs, and that routing still resolves.
- **Tripwire (Layer 3):** with `$_SERVER['REQUEST_METHOD']='GET'` and the `debug`
  setting on, assert `save()`/`soft_delete()`/`permanent_delete()` throw
  `SystemBaseException`; with `$allow_get_mutation = true` they do not; under
  `PHP_SAPI==='cli'` / unset `REQUEST_METHOD` they do not; on POST they do not.
  Confirm prod (`debug` off) logs without throwing.
- `php -l` + `validate_php_file.php` on every changed file; the lint rule (if
  added) must pass clean.

## Documentation

- Add a **"Detecting form submission in logic files"** section to
  `docs/logic_architecture.md`: never guard a save handler with `if($input)`;
  use `LibraryFunctions::isFormSubmission()`; why (`$input` always carries
  `__route`, and edit pages carry the record id). Cross-reference from the admin
  pages guide (`docs/admin_pages.md`).
- Note the `__route` invariant in `docs/routing.md` — `RouteHelper::processRoutes`
  strips it from the request superglobals at dispatch entry, so page logic never
  sees it (the route value lives in `$request_path`).
- Document the **GET-is-read-only invariant** and the `SystemBase::$allow_get_mutation`
  opt-out (with the `try/finally` reset pattern) wherever model writes are covered
  — e.g. `docs/logic_architecture.md` next to the submission rule, and a one-liner
  in the deletion-system / model docs — so intentional GET actions know how to opt
  in and nobody mistakes the tripwire for a bug.

## Versioning

- Bump `@version` on `includes/RouteHelper.php`, `LibraryFunctions.php`,
  `includes/SystemBase.php`, the validator (if the lint rule is added), and each
  modified logic file.
- No schema or settings changes; no migration.

## Rollout / ordering

1. Land Layer 2 (`RouteHelper::processRoutes` `__route` strip) + the
   `isFormSubmission()` helper first — immediately stops the Tier-1 loops and
   removes the footgun.
2. Land the **Layer-3 tripwire in log-only mode EVERYWHERE first** — no throw,
   not even in dev (temporarily skip the `debug` throw branch). It silently logs
   every GET mutation across admin *and* non-admin, producing the definitive
   worklist (broken handlers + intentional GET-action links) without breaking any
   page mid-migration.
3. Fix the **Tier-2 (data-corruption)** files next, highest priority
   (`admin_contact_type_edit` and any other no-`isset` setters found during the
   sweep).
4. Sweep the remaining admin edit/save and mixed files, then the non-admin
   surface the logs revealed (see *Non-admin GET-mutation surface*); opt-in each
   intentional GET-action mutation (`$allow_get_mutation`) as it is flagged.
5. **Flip dev to throw** only once the log-only burndown is clean (no unaccounted
   GET-mutation log lines) — restoring the `debug`-gated `throw` in
   `assert_not_get_mutation()`. Prod stays log-only permanently.
6. (Optional) add the lint rule once the tree is clean so it passes immediately.

## Non-goals

- **Converting intentional GET mutations (delete/toggle links) to POST.** Those
  are a real CSRF-hardening concern but a separate effort; this spec only stops
  *form-save* handlers from firing on GET and preserves existing explicit
  GET-action branches.
- **Re-architecting the page→logic calling convention** (e.g., a render/submit
  dispatcher, a typed request object, or a declarative resource layer). The
  targeted fix + helper + lint is sufficient; this is one wrong boolean test, not
  a framework gap. A broader convention change is explicitly out of scope.
- **De-duplicating edit-page boilerplate** (the repeated load-model / set-fields
  ritual). Real, but orthogonal to this correctness fix; handle separately if
  ever.
- **Reverting 5b7f2251.** It made other intended fixes; this is a fix-forward.
