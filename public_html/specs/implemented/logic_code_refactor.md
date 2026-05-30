# Logic Layer Refactor

> **⚠️ Correction — submission-detection guidance below is superseded.**
> See [`admin_logic_get_submission_guard_fix.md`](admin_logic_get_submission_guard_fix.md).
>
> This spec settled on `if (!empty($_POST))` (and, for mixed files, field-presence
> checks like `isset($input['action'])`) as the action-vs-load gate, and explicitly
> avoided any `$_SERVER` / `REQUEST_METHOD` reference. That idiom proved fragile: a
> later refactor (commit `5b7f2251`) reintroduced the always-truthy `if($input)`
> gate across the **admin** logic files, causing redirect loops on the settings
> pages and silent null-overwrite corruption on edit pages opened with a GET.
>
> **Canonical rule now:** guard every save/mutate/redirect handler with
> `LibraryFunctions::isFormSubmission()` (i.e. `$_SERVER['REQUEST_METHOD'] === 'POST'`),
> never `if($input)` / `if(!empty($input))`; `__route` is stripped from the request
> superglobals in `RouteHelper::processRoutes()`; and `SystemBase` enforces a
> GET-is-read-only invariant at the write boundary. Treat the `if (!empty($_POST))`
> / no-`$_SERVER` convention in this document as historical.

## Problem

The architecture is 80% of the way to "free" API and AI integration, but the action layer is incomplete. Models declare their own shape via `$field_specifications` — so the REST API, auto-discovery, FormWriter, and the database updater all work from one declaration. Logic files have the right encapsulation (business rules, validation, side effects all bundled) and the right output (`LogicResult`), but the input side has no equivalent declaration.

The specific gaps, in order of impact:

1. Logic files don't declare their input shape — callers reverse-engineer the implementation.
2. Logic function signatures are HTTP-coupled (`($get_vars, $post_vars)`) — non-HTTP callers must simulate an HTTP request.
3. Most logic files serve double duty: loading page data on GET and executing an action on POST. There's no clean "action" to describe or expose.
4. Some entity-scoped state transitions live in logic files when they belong on the model.

Until these are resolved, every new integration surface (REST API action exposure, AI tool discovery, form generation) requires per-action boilerplate rather than emerging from the architecture.

## Goal

After this work: write a business action once, give it a descriptor, and it is automatically available to HTTP forms, the REST API, and AI recipes without per-consumer code. The same "write a model, get API access" experience, applied to actions.

## Related specs

- [`fix_legacy_logic_files.md`](fix_legacy_logic_files.md) — prerequisite: removes the three logic files with top-level executable code
- [`joinery_ai_autodiscovery.md`](joinery_ai_autodiscovery.md) — the AI model auto-discovery surface; steps here unlock the action surface
- [`joinery_ai_write_tools.md`](../joinery_ai_write_tools.md) — write tool design for AI; depends on steps 4–5 here

---

## Step 1 — Fix legacy logic files

**Complexity: trivial**

Already specced in [`fix_legacy_logic_files.md`](fix_legacy_logic_files.md). Must land first — everything that scans the logic directory assumes all `*_logic.php` files are safe to `require_once`.

---

## Step 2 — Move entity-scoped state transitions to model methods

**Complexity: low**

**Codebase survey result (2026-05-06): largely inapplicable.** Single-column toggles (activate/deactivate, enable/disable) are already trivially handled by `set()`/`save()` and don't warrant a model method. The only multi-field candidates found (`Question::publish()` sets flag + timestamp; `Email::unqueue()` resets status + deletes recipients) are marginal. All genuinely interesting transitions (booking cancel, subscription cancellation, event withdrawal) are entangled with Stripe or email and don't qualify. Step 2 is effectively done — the right boundary already exists. Apply the pattern if a new transition with a real guard condition and multiple fields emerges.

Some logic files contain operations that are really just changing one entity's own state — no external API calls, no cross-system side effects, no multi-entity orchestration. These belong on the model, not in logic files.

### What qualifies

A state transition belongs on the model when it:
- Only touches the entity itself (and directly-owned child records)
- Has no external API calls (Stripe, Mailgun, etc.)
- Has no email sending or hook firing
- Makes a decision the model is uniquely positioned to enforce (e.g. "can only publish if status is draft")

Examples: `$event->publish()`, `$booking->cancel()`, `$user->deactivate()`, `$product->archive()`.

### What does NOT qualify

Anything that crosses system boundaries — charging a card, sending email, firing purchase hooks, updating a Stripe subscription. Those remain in logic files. A useful test: if the method would need to `require_once` StripeHelper or SystemMailer, it doesn't belong on the model.

### Convention

```php
// In data/events_class.php
public function publish(): void {
    if ($this->get('evt_status') !== 'draft') {
        throw new Exception('Only draft events can be published.');
    }
    $this->set('evt_status', 'published');
    $this->set('evt_published_time', 'now()');
    $this->save();
}
```

- Method names are verb-first snake_case: `publish`, `cancel`, `deactivate`, `archive`.
- Throw `Exception` with a human-readable message on failure. Logic files that call model methods catch and translate to `LogicResult::error()`.
- Return `void` on success. The caller already has the model object if it needs updated state.
- Do NOT return `LogicResult` — keeps the model layer decoupled from application-layer result types.

### Migration

Logic files that currently contain these operations call the model method instead:

```php
// Before (in event_logic.php)
$event->set('evt_status', 'published');
$event->set('evt_published_time', 'now()');
$event->save();

// After
try {
    $event->publish();
} catch (Exception $e) {
    return LogicResult::error($e->getMessage());
}
```

No callers outside logic files change in this step.

---

## Step 3 — Add input descriptors to action-shaped logic files

**Complexity: low — additive, no callers change**

**Done (2026-05-06).** `_logic_descriptor()` added to 18 action-shaped files via `utils/add_logic_descriptors.php`. All 18 retain `_logic_api()` for backward compat. Five files that have `_logic_api()` stubs but serve double duty as page handlers (`cart_logic`, `booking_logic`, `survey_logic`, `event_sessions_logic`, `event_sessions_course_logic`) were deferred — their POST actions can receive descriptors independently, file-by-file, whenever that exposure is warranted.

Action-shaped logic files — those that are already single-purpose and return `LogicResult` — get a companion descriptor function. This is purely additive: no existing callers change, no signatures change.

### What qualifies for step 3

Logic files that currently do one cohesive thing and return `LogicResult`. Roughly a third of existing logic files meet this bar today:

```
event_register, event_withdraw, event_waiting_list,
cart_charge, cart_clear, change_tier,
verify_totp, register, login,
orders_recurring_action, password_edit,
password_reset_1, password_reset_2, password_set,
account_edit, address_edit, phone_numbers_edit,
contact_preferences, security
```

Mixed page-handlers (`event_logic`, `cart_logic`, `profile_logic`, etc.) do NOT qualify until step 5.

### Descriptor shape

```php
function event_register_logic_descriptor(): array {
    return [
        'description'      => 'Register the current user for an event.',
        'requires_session' => true,
        'mutates'          => true,
        'input'            => [
            'event_id' => [
                'type'     => 'int',
                'required' => true,
                'label'    => 'Event',
            ],
            'session_id' => [
                'type'     => 'int',
                'required' => false,
                'label'    => 'Session',
            ],
        ],
    ];
}
```

### Input types

| Type | Maps to |
|------|---------|
| `int` | Integer, cast at normalization |
| `string` | String, trimmed |
| `email` | String, validated as email |
| `bool` | Boolean |
| `select` | String, must be one of `options` array |
| `text` | Multi-line string |
| `date` | Date string (Y-m-d) |
| `password` | String, never logged |

### Relationship to `_logic_api()`

`_logic_descriptor()` supersedes `_logic_api()` when both exist. Files that already have `_logic_api()` get the descriptor added; `_logic_api()` is kept for backward compat until step 7. New files only write the descriptor.

### What this enables

- REST API action discovery returns richer metadata (description + typed input schema).
- AI tool schemas are generated from descriptors rather than hand-written.
- Foundation for step 6 (FormWriter reads descriptors).

---

## Step 4 — Normalize logic function signatures

**Complexity: medium — mechanical, broad scope**

**Done (2026-05-06).** `utils/normalize_logic_signatures.php` applied with `--apply` and confirmed clean with `--verify`. 54 logic files transformed, 57 view/plugin call sites updated, `apiv1.php` dynamic invocation updated. 9 extra-param files skipped — see Step 5 analysis.

Change all logic function signatures from `($get_vars, $post_vars)` to `(array $input): LogicResult`. HTTP callers normalize before calling; the logic function receives one flat array.

### Before / after

```php
// Before
function event_register_logic($get_vars, $post_vars) {
    $event_id = $post_vars['event_id'] ?? null;
    ...
}

// After
function event_register_logic(array $input): LogicResult {
    $event_id = $input['event_id'] ?? null;
    ...
}
```

### Caller normalization

In the HTTP layer (`process_logic()` call sites in views and serve.php):

```php
// Before
$page_vars = process_logic(event_register_logic($_GET, $_POST));

// After
$page_vars = process_logic(event_register_logic(array_merge($_GET, $_POST)));
```

POST values win on key conflict (array_merge ordering). File uploads stay in `$_FILES` — logic functions that handle uploads receive them via a second `$files = []` parameter added only where needed.

### Return type enforcement

All logic functions must return `LogicResult`. Functions that currently return nothing or return raw arrays are updated to wrap their output. The `LogicResult` class already supports data payloads, errors, validation errors, and redirects — no changes needed there.

### Scope

54 logic files (core + all plugins) plus their call sites in views/plugin views. 9 files with extra injected parameters were skipped — they take `($get_vars, $post_vars, $model, ...)` and are covered by Step 5. `apiv1.php`'s dynamic `call_user_func` invocation was also updated to pass a single merged array.

---

## Step 5 — Normalize the extra-param page controllers

**Complexity: medium — coordinated change across 9 logic files, 8 route entries, RouteHelper, ~20 views (9 base + 11 theme overrides), test-file and internal callers (notably `event_register_logic.php` and `tests/functional/products/ProductTester.php`), the routing docs, and a final sweep that brings the ~57 view call sites normalized in Step 4 onto the same call-site form *and* fixes a handful of stale Step-4 call sites in tests/utils that are silently broken. Mechanical, no cross-file logical dependencies.**

**Status: Done (2026-05-06).** All five layers landed, smoke-tested live, and verified against `tests/integration/routing_test.php` (53/53 passing). See "Bugs surfaced during implementation" below for two regressions caught and fixed during the work — neither was in scope for Step 5 originally, but both blocked verification.

### Background: what the original Step 5 proposed

The original plan was to split every "mixed" logic file into a `*_load()` data loader and a `*_logic()` action function. A full codebase survey after Step 4 showed this is unnecessary and would create a consistency problem for plugin developers.

### Codebase survey (2026-05-06)

After Step 4, the 54 transformed logic files fall into three behavioural categories:

| Category | Count | Description |
|----------|-------|-------------|
| **Loader** | ~19 | Pure GET — builds `$page_vars`, returns `LogicResult::render()`. No POST action. Examples: `profile_logic`, `blog_logic`, `events_logic`, `booking_logic`, all plugin dashboard loaders. |
| **Action** | ~14 | POST-only — executes a mutation, returns redirect or error. No page data loading. Examples: `event_register_logic`, `cart_clear_logic`, `register_logic`, `verify_totp_logic`. These have descriptors (Step 3). |
| **Mixed** | ~21 | Handles both GET and POST in one function — loads form data on GET, executes mutation on POST, gated by `isset($input['action'])` or similar specific-field checks. Examples: `account_edit_logic`, `login_logic`, `billing_logic`, `cart_logic`, `security_logic`. |

**Key finding: all three categories use identical calling conventions.** From the view, from the API, and from the plugin system's perspective, these are indistinguishable — they all share the signature `function foo_logic(array $input): LogicResult` and are called identically. The category label describes internal behaviour, not interface.

### Why the split is unnecessary

The mixed files work correctly for API and AI consumers today. Their POST guards check for specific input fields (`isset($input['action'])`, `!empty($input['email'])`), not for `REQUEST_METHOD`. When the API sends POST data, the action gate opens; when no action fields are present, the GET loading path runs instead. The GET data-loading code is never wasted on API calls.

The `_logic_descriptor()` function on a logic file is the only signal that matters for API/AI exposure — it declares the file as an action surface. Files without a descriptor are page helpers; files with one are actions. No split is needed to enforce this distinction.

**For plugin developers, there is one type of logic file.** Every plugin logic file already follows the same pattern. The `dns_filtering` plugin has loaders, actions, and mixed files — all `(array $input): LogicResult`, all called identically. A plugin developer reads any logic file in the codebase as a valid reference.

### The real problem: 9 extra-param page controllers

The 9 files skipped from Step 4 are a different matter:

```
event_logic($get_vars, $post_vars, $event, $instance_date = null)
event_waiting_list_logic($get_vars, $post_vars, $event_id = null)
list_logic($get_vars, $post_vars, $mailing_list, $params)
lists_logic($get_vars, $post_vars, $params)
location_logic($get_vars, $post_vars, $location, $params)
page_logic($get_vars, $post_vars, $page, $params)
post_logic($get_vars, $post_vars, $post)
product_logic($get_vars, $post_vars, $product)
video_logic($get_vars, $post_vars, $video, $params)
```

These follow a different pattern: serve.php loads a model object from the URL slug (for 404 validation and SEO metadata), and the view passes that already-loaded object into the logic function alongside the request vars. The signature inconsistency is real — these files did not get Step 4 treatment and still use the old two-var convention.

These are core-only files. No plugin uses this pattern. But they are visible to anyone reading the codebase, and they create a second calling convention that undermines the "one type" principle.

### Decision: drop model injection (Option B)

Change the 9 logic files to `(array $input): LogicResult`. Each one loads its own model from the slug or id present in `$input`. The route config in serve.php drops the `model`/`model_file`/`var_name` declarations for these routes. The corresponding pre-load mechanism in RouteHelper becomes dead code and is removed.

```php
// Before — view
$page_vars = process_logic(event_logic($_GET, $_POST, $event, $instance_date));

// After — view
$page_vars = process_logic(event_logic(array_merge($_GET, $_POST, $params ?? [])));

// After — logic
function event_logic(array $input): LogicResult {
    $event = Event::get_by_link($input['slug'] ?? '');
    if (!$event) { require_once(LibraryFunctions::display_404_page()); }
    // ...
}
```

The `$params ?? []` form matters: `$params` is in scope only for routes that go through `RouteHelper::handleDynamicRoute()`. Auto-discovered views (`event_waiting_list`, `lists`) reach the view directly with no `$params` injection, so the unguarded form would emit "undefined variable" warnings. Use `$params ?? []` everywhere for safety.

Precedence in `array_merge($_GET, $_POST, $params ?? [])` is `$params` > `$_POST` > `$_GET`, which is the right ordering: a URL placeholder like `/event/abc` must win over a query string like `?slug=xyz`. Don't reorder.

### Why this is bigger than just a signature change

Re-reading `RouteHelper::handleDynamicRoute()` and the affected views shows the model pre-load is providing less than it appears to:

- **It does not 404 on missing records.** When `Model::get_by_link($slug)` returns null, RouteHelper just sets `$model_instance = null`. The view receives a null and either crashes or relies on the logic file to 404. The 404 responsibility is already in the logic file today.
- **The views do not read the scoped model before the logic call.** `views/event.php`, `views/product.php`, and `views/post.php` all reassign `$event = $page_vars['event']` (etc.) immediately after `process_logic()` and only use that. The SEO header (`$page->public_header(...)`) runs after the logic call, off the logic-returned model. The auto-extracted scope variable is read nowhere in these views.
- **The logic files already have a self-load fallback path.** `product_logic.php` for example accepts the injected `$product` *or* loads from `$get_vars['product_id']` / `$post_vars['product_id']`.

The pre-load's only real purpose today is to stash the model so the view can pass it as the third argument to the logic function. Once the logic loads its own copy, the pre-load is pure waste — and the route-config keys, RouteHelper code paths, and view-scope extraction that exist to support it can all go.

### What changes — five layers

#### Layer 1 — Logic files (9 files, two variants)

The 9 files split into two migration variants.

**Variant A — Model-injection (7 files):** `event_logic`, `list_logic`, `location_logic`, `page_logic`, `post_logic`, `product_logic`, `video_logic`. These take a pre-loaded model object as their third arg and are reached via slug-based serve.php routes (Layer 2). Per-file steps:

1. Change signature to `function foo_logic(array $input): LogicResult`.
2. Replace the injected parameter with a local self-load: `$model = Model::get_by_link($input['slug']);` (or `new Model($input['id'], TRUE)`), 404 if null.
3. Drop the now-redundant fallback paths (the `else if (!empty($get_vars['product_id']))` branches inside the logic — they collapse into the single self-load).
4. The view's call site becomes `array_merge($_GET, $_POST, $params ?? [])`. `$params` is in view scope from RouteHelper and contains the slug + any other URL placeholders (e.g. `$params['date']` for `/event/{slug}/{date}`).
5. **All other callers of this function** get updated in the same commit (lockstep). Layer 4 enumerates them: base views, theme view overrides, test files, and any internal logic-from-logic invocations. Notable instance: `logic/event_register_logic.php` calls `event_logic()` directly — that line gets rewritten in the same commit as event_logic's signature change.
6. `php -l` + validate_php_file.php on the changed files.

**Variant B — Extra-scalar (2 files):** `event_waiting_list_logic` (takes `$event_id`) and `lists_logic` (takes `$params`). These are reached via auto-discovered routes (`/event_waiting_list?event_id=N`, `/lists`), not via serve.php route config — Layer 2 does not apply to them. `$params` is NOT in view scope for these. Per-file steps:

1. Change signature to `function foo_logic(array $input): LogicResult`.
2. Read the previously-injected scalar from `$input` directly: `$event_id = $input['event_id'] ?? null;`. No model self-load needed for these — `event_waiting_list_logic` already loads its own Event from the id; `lists_logic` doesn't load any model.
3. The view's call site becomes `array_merge($_GET, $_POST)` (no `$params` to merge). The view's existing `fetch_variable('event_id', ...)` line in `views/event_waiting_list.php` becomes redundant — the logic file does its own fetch from `$input`.
4. `php -l` + validate_php_file.php on both files.

#### Layer 2 — serve.php route config (8 entries)

Drop `model`, `model_file`, and `var_name` from these route entries (serve.php lines 112–119). They become plain view routes:

```php
// Before
'/post/{slug}'         => ['model' => 'Post', 'model_file' => 'data/posts_class', 'check_setting' => 'blog_active'],
'/page/{slug}'         => ['model' => 'Page', 'model_file' => 'data/pages_class', 'check_setting' => 'page_contents_active'],
'/event/{slug}/{date}' => ['model' => 'Event', 'model_file' => 'data/events_class', 'check_setting' => 'events_active', 'view' => 'views/event'],
'/event/{slug}'        => ['model' => 'Event', 'model_file' => 'data/events_class', 'check_setting' => 'events_active'],
'/location/{slug}'     => ['model' => 'Location', 'model_file' => 'data/locations_class', 'check_setting' => 'events_active'],
'/product/{slug}'      => ['model' => 'Product', 'model_file' => 'data/products_class', 'check_setting' => 'products_active'],
'/list/{slug}'         => ['model' => 'MailingList', 'model_file' => 'data/mailing_lists_class', 'view' => 'views/list', 'var_name' => 'mailing_list'],
'/video/{slug}'        => ['model' => 'Video', 'model_file' => 'data/videos_class', 'check_setting' => 'videos_active'],

// After
'/post/{slug}'         => ['view' => 'views/post', 'check_setting' => 'blog_active'],
'/page/{slug}'         => ['view' => 'views/page', 'check_setting' => 'page_contents_active'],
'/event/{slug}/{date}' => ['view' => 'views/event', 'check_setting' => 'events_active'],
'/event/{slug}'        => ['view' => 'views/event', 'check_setting' => 'events_active'],
'/location/{slug}'     => ['view' => 'views/location', 'check_setting' => 'events_active'],
'/product/{slug}'      => ['view' => 'views/product', 'check_setting' => 'products_active'],
'/list/{slug}'         => ['view' => 'views/list'],
'/video/{slug}'        => ['view' => 'views/video', 'check_setting' => 'videos_active'],
```

The auto-determine-view-from-model fallback (`'views/' . strtolower($route['model'])`) goes away with the model declarations, so every route declares an explicit view path.

Inline function-defined routes that load their own model (the `.ics` and calendar-feed handlers around serve.php:240–320) are not affected — they don't use the route-config `model` mechanism.

#### Layer 3 — RouteHelper cleanup (dead-code removal)

After Layer 2 is in place, no route declares `model`. The model-loading branch of `handleDynamicRoute()` is unreachable. Before deletion, run a survey grep for `'model' =>` across `serve.php` and all `plugins/*/serve_routes.php` (or however plugins register routes) to confirm. Then delete:

- `RouteHelper.php:415–441` — the `if (!empty($route['model']))` block that requires the model file and calls `get_by_link` / instantiates by id.
- `RouteHelper.php:478–480` — the auto-determine-view-from-model fallback.
- The `extract([strtolower($route['model']) => $model_instance, ...])` calls in the admin branch (~line 491), the test/utils/ajax branch (~line 537), and the standard-view branch (~line 553).
- The `var_name` config-option handling (~line 555, ~line 575) — its only consumer was the `MailingList` → `$mailing_list` aliasing.
- The `$model_instance` variable threading and the model-related entries in `$viewVariables`.

What's left in `handleDynamicRoute()` after the cleanup: route-param extraction, view-path determination, theme-override resolution, and view inclusion. About a third of the function's body goes away.

#### Layer 4 — Views and other callers (~20 views + tests + internal)

Every caller of any of the 9 logic functions gets rewritten in lockstep with that function's Layer 1 signature change. Four caller categories:

**4a — Base views (9 files).** Each drops references to the auto-extracted scoped model and uses the logic-returned model from `$page_vars` (which they already do post-call). Concretely, in `views/event.php` (and the other base files), the existing line `$page_vars = process_logic(event_logic($_GET, $_POST, $event, $instance_date));` becomes `$page_vars = process_logic(event_logic(array_merge($_GET, $_POST, $params ?? [])));` — the scoped `$event` reference disappears, and the `$event = $page_vars['event'];` reassignment that follows is unchanged.

For Variant B views (`views/event_waiting_list.php`, `views/lists.php`): same uniform 3-arg form `array_merge($_GET, $_POST, $params ?? [])`. `$params ?? []` resolves to `[]` for these auto-discovered routes, keeping every view's `process_logic` call line identical. The `views/event_waiting_list.php` line that pre-fetches `$event_id` via `fetch_variable` becomes redundant once the logic does its own read.

**4b — Theme view overrides (~11 files).** Same call-site rewrite as 4a. Confirmed overrides (2026-05-06):

```
theme/tailwind/views/{event,event_waiting_list,list,lists,location,page,post,product,video}.php  (9)
theme/scrolldaddy/views/{product,page}.php
theme/empoweredhealth-html5/views/{list,lists,page,post}.php
theme/zoukroom-html5/views/event.php
theme/jeremytunnell-html5/views/post.php
theme/phillyzouk-html5/views/post.php
theme/galactictribune-html5/views/post.php
theme/linka-reference-html5/views/post.php
```

No theme overrides any of the 9 logic files themselves (verified) — Layer 1 stays at 9 base files.

**4c — Internal logic-from-logic invocations.** One known instance:

- `logic/event_register_logic.php:19` — currently `return event_logic($input, $input, $event, $instance_date);`. event_register_logic loads the Event by `evt_event_id` (it's the API/AI entry point with its own descriptor), then calls event_logic. After Layer 1 rewrites event_logic, this call becomes:
  ```php
  return event_logic(array_merge($input, [
      'slug' => $event->get('evt_link'),
      'date' => $input['instance_date'] ?? null,
  ]));
  ```
  The file's header docblock describing itself as "an adapter for event_logic() (which requires pre-loaded $event and $instance_date)" loses its premise — event_logic no longer requires anything pre-loaded — so that comment is removed. The file remains as the API entry point (it has its own descriptor) but is no longer framed as an awkward-signature adapter. It's just a load-by-id facade.

Verify via grep that no other logic file invokes any of the 9 functions before Layer 1 lands:
```bash
grep -rn 'event_logic\|list_logic\|lists_logic\|location_logic\|page_logic(\|post_logic\|product_logic\|video_logic\|event_waiting_list_logic' \
    /var/www/html/joinerytest/public_html/logic 2>/dev/null \
| grep -v 'function \|//\|^.*:[[:space:]]*\*'
```

**4d — Test files.** Test code that invokes any of the 9 functions with the old signature:

- `tests/functional/products/ProductTester.php:986` — `$result = product_logic(array(), $post_data, null);` — rewrites to `$result = product_logic($post_data);` (or `product_logic(array_merge($post_data, ['product_id' => ...]))` if the test is exercising a slug/id path).

Run a broader grep at Layer 1 time to catch any other test-file callers of the 9 functions:
```bash
grep -rn 'event_logic(\|list_logic(\|lists_logic(\|location_logic(\|page_logic(\|post_logic(\|product_logic(\|video_logic(\|event_waiting_list_logic(' \
    /var/www/html/joinerytest/public_html/tests 2>/dev/null \
| grep -v 'function \|//\|^.*:[[:space:]]*\*'
```

#### Layer 5 — Call-site cleanup (cosmetic uniformity + stale Step-4 sites)

Two passes, run as one Layer 5 commit.

**5a — Cosmetic uniformity sweep (~57 view call sites from Step 4).** Step 4 normalized 57 view call sites to `array_merge($_GET, $_POST)` (2-arg form). Layer 4 introduces `array_merge($_GET, $_POST, $params ?? [])` (3-arg form). Without a sweep, the codebase ends up with two cosmetically-different forms of the same line — both correct, but a reader can't tell at a glance whether a given call site is "params-aware" or not.

Sweep the 57 Step-4 call sites onto the 3-arg form so every `process_logic` invocation in the codebase reads identically. The transformation is a literal string replacement:

```bash
# Replace 2-arg form with 3-arg form across all view-bearing directories
find /var/www/html/joinerytest/public_html/views \
     /var/www/html/joinerytest/public_html/theme \
     /var/www/html/joinerytest/public_html/plugins \
     -name "*.php" -type f -print0 \
| xargs -0 grep -l 'array_merge($_GET, $_POST)' \
| grep -v 'utils/normalize_logic_signatures.php' \
| xargs sed -i 's/array_merge(\$_GET, \$_POST)/array_merge($_GET, $_POST, $params ?? [])/g'
```

The 3-arg form is functionally identical for the Step-4 routes: those routes have no URL placeholders, so `$params` is either `[]` or unset, and `$params ?? []` resolves to `[]` either way.

**Sweep exclusions:**
- `utils/normalize_logic_signatures.php` — Step 4's own one-shot transformation script. It contains `'array_merge($_GET, $_POST)'` as a literal string inside its replacement code; the sed pass would corrupt it. The script is a historical artifact and isn't re-runnable post-Step-5 anyway.
- `apiv1.php`'s dynamic `call_user_func` invocation — already updated by Step 4 to a single merged array; no `$params` concept on the API side.
- Any non-logic-call uses of `array_merge($_GET, $_POST)` — none observed, but verify via the `grep -l` listing before running `sed`.

**5b — Stale Step-4 call-site fixes.** Step 4 changed 54 logic-function signatures to `(array $input)` but missed several call sites in `tests/` and `utils/`. These call sites still pass two arguments — the second is silently ignored, leaving tests with empty `$input` (no real coverage) and the products util running with no input at all. Known instances (audit before fixing for completeness):

- `tests/functional/subscription_tiers/SubscriptionTierTester.php` — six calls to `change_tier_logic([], $post)`. Rewrite to `change_tier_logic($post)`.
- `tests/functional/products/ProductTester.php:1710` — `cart_charge_logic($get_vars, $post_vars);`. Rewrite to `cart_charge_logic(array_merge($get_vars, $post_vars))`.
- `utils/products_list.php:12` — `products_logic($_GET, $_POST);`. Rewrite to `products_logic(array_merge($_GET, $_POST, $params ?? []))` (uniform form).

Audit grep — run before rewrites to catch anything missed:

```bash
# Find any remaining 2-arg calls to logic functions outside views (which pass through Layer 4) and outside the logic dir itself (function definitions)
grep -rn '_logic([^)]*,[^)]*)' /var/www/html/joinerytest/public_html \
    --include="*.php" 2>/dev/null \
| grep -v '/views/\|/theme/.*/views/\|/plugins/.*/views/\|/logic/\|/plugins/.*/logic/' \
| grep -v 'function \|process_logic\|_logic_descriptor\|_logic_api\|//\|\.md:'
```

This is a pre-existing breakage that Step 4 introduced; folding the fix into Layer 5 keeps the call-site cleanup unified rather than leaving a separate "Step 4 follow-up" task open. Test files are NOT auto-runnable in this codebase, so the silent breakage hasn't been caught by CI; expect that exercising these tests after the fix may surface latent assertion failures unrelated to Step 5.

**End state.** Every logic call in the codebase reads:

```php
process_logic(foo_logic(array_merge($_GET, $_POST, $params ?? [])))
```

— for view contexts. Test/util contexts use `foo_logic($single_array)` with whatever array is most natural for the test setup. One mental model: a logic function takes one array.

### Pre-verification step

Before any deletion, grep every view (base + themes + plugins) for direct reads of the auto-extracted scope variables *before* the `process_logic()` call:

```bash
grep -rn '\$event\b\|\$product\b\|\$post\b\|\$page\b\|\$mailing_list\b\|\$location\b\|\$video\b' \
    /var/www/html/joinerytest/public_html/views \
    /var/www/html/joinerytest/public_html/theme \
    /var/www/html/joinerytest/public_html/plugins/*/views \
    /var/www/html/joinerytest/public_html/plugins/*/theme 2>/dev/null
```

Any view that reads the scoped model before its `process_logic()` call must either move the read after the call (using `$page_vars['event']` etc.) or pull from `$params['slug']`. The three views I sampled (`event.php`, `product.php`, `post.php`) are clean — they only read the scoped model as the third arg to the logic call, which is exactly the line being removed.

`$page` is a special case: it is also the conventional name for the `PublicPage` instance (`$page = new PublicPage();`). The view-scope extraction for the `Page` model uses the same name, so a grep will hit both. Distinguish by context — only the pre-`process_logic()` reads matter, and the `PublicPage` instance is constructed *after* the logic call in every view today.

### Documentation updates

- **`docs/routing.md`** — remove the "Model-based routes" section. Drop `model`, `model_file`, and `var_name` from the route-config keys table. Update the "When you do need a serve.php route" list: the remaining reasons are feature flags (`check_setting`), permission gates (`min_permission`), wildcards (`/admin/*`), and custom function-defined routes. Replace any model-route example with a plain-view example.
- **`serve.php` top-comment block (lines ~26–90)** — the route-system docblock at the top of serve.php documents the `model`, `model_file`, and `var_name` route options with examples. Strip those entries and update the option list to match `docs/routing.md`.
- **`CLAUDE.md`** (the "Three things to know about routing" block near the top) — drop "model-based routes (`/post/{slug}`)" from the list of reasons to add a serve.php entry. The remaining reasons match the routing.md update.
- **`docs/logic_architecture.md`** — drop any mention of the `($get_vars, $post_vars, $model)` variant and any "extra-param page controller" exception. Single signature documented: `(array $input): LogicResult`.
- **`docs/plugin_developer_guide.md`** — the "one logic-file convention" claim becomes literally true everywhere. Remove any caveat noting that core has additional patterns.

### Implementation order

1. **Pre-verification grep** (Layer 4 readiness check, no code change).
2. **Layer 1 + Layer 4 in lockstep, file-by-file:** for each of the 9 logic files, change the signature and add the self-load, and update the matching view's call site (base + theme overrides) in the same commit. Pages stay green throughout.
3. **Layer 2:** drop `model`/`model_file`/`var_name` from the 8 route entries in serve.php. After this commit the route-config `model` mechanism has no remaining consumers.
4. **Layer 3:** survey grep for any plugin-registered route using `'model' =>`, then delete the now-unreachable RouteHelper code paths.
5. **Layer 5:** run the call-site uniformity sweep across the ~57 Step-4 view files. Standalone commit, scriptable.
6. **Documentation pass** (the five files above — `docs/routing.md`, serve.php top-comment block, `CLAUDE.md`, `docs/logic_architecture.md`, `docs/plugin_developer_guide.md`), in a single commit at the end.

Layer 1 must precede Layer 2 — once a route drops `model`, the corresponding logic file must already be self-loading or the page breaks. Layer 5 has no ordering dependency on Layers 1–4 (the form change is harmless before or after) but conventionally runs last so the diff stays small and review-friendly.

### Smoke-test checklist

Pick a real slug from the test database for each route (find via `/admin/admin_events`, `/admin/admin_products`, etc.) and substitute below. Run `tail -f /var/www/html/joinerytest/logs/error.log` in another terminal throughout testing.

**After each Layer 1 + Layer 4 file commit (per-file):**

| Logic file | URL to visit | What to verify |
|------------|--------------|----------------|
| `event_logic` | `/event/{slug}` | Page renders, SEO `<title>` and OG tags populated, calendar links work |
| `event_logic` | `/event/{slug}/{date}` | Recurring instance renders, virtual or materialized, registration widget correct |
| `event_logic` | `/event/nonexistent-slug` | Clean 404 (not a PHP error/blank page) |
| `product_logic` | `/product/{slug}` | Renders, add-to-cart form works, prefill on `?edit_item=N` works |
| `product_logic` | `/product/nonexistent` | Clean 404 |
| `post_logic` | `/post/{slug}` | Renders, comments form works, tier-gate prompt for tier-restricted posts |
| `page_logic` | `/page/{slug}` | Renders, no `$page` variable collision (Page model vs PublicPage) |
| `list_logic` | `/list/{slug}` | Renders, signup form works |
| `location_logic` | `/location/{slug}` | Renders |
| `video_logic` | `/video/{slug}` | Renders |
| `event_waiting_list_logic` | `/event_waiting_list?event_id={id}` | Renders, missing `event_id` 400s cleanly |
| `lists_logic` | `/lists` | Renders, list of mailing lists |

For each URL, take a `mcp__browser__browser_snapshot` and compare to a baseline taken before Layer 1 work began. Differences in the page body are unexpected; differences in the `<head>` should be cosmetic only.

**After Layer 2 (route-config simplification):** re-visit one URL from each of the 8 affected routes — confirms RouteHelper still resolves them correctly without the `model` declaration.

**After Layer 3 (RouteHelper deletion):** smoke-test broader dynamic routing, since `handleDynamicRoute()` serves more than just the 8 model routes:
- An admin page: `/admin/admin_users`
- A test/utils page: `/utils/products_list` (already in scope for Layer 5b)
- An ajax endpoint: any `/ajax/*` URL
- A wildcard route: any `/admin/*` page
- One pure-view auto-discovered page: `/about` or similar

**After Layer 5a (sed sweep):** spot-check three random Step-4 views (e.g. `/login`, `/register`, `/profile`) — confirms the sed pass didn't mangle any call sites. `php -l` every changed file is also worth running as a one-liner: `find views theme plugins -name "*.php" -newer /tmp/sweep-marker -exec php -l {} \;`.

**After Layer 5b (stale call-site fixes):** run the affected test files manually:
```bash
php /var/www/html/joinerytest/public_html/tests/functional/subscription_tiers/SubscriptionTierTester.php
php /var/www/html/joinerytest/public_html/tests/functional/products/ProductTester.php
php /var/www/html/joinerytest/public_html/utils/products_list.php
```
Expect that fixed tests may surface latent assertion failures unrelated to Step 5 (they were silently passing empty input before). Triage and fix or note as out-of-scope.

**Final pass — error.log review:**
```bash
grep -iE 'fatal|warning|undefined|error' /var/www/html/joinerytest/logs/error.log | tail -50
```
Any new `Undefined variable` warnings (especially `$params`, `$event`, `$product` etc.) are the most likely failure mode and warrant immediate attention.

### Bugs surfaced during implementation (2026-05-06)

Two pre-existing regressions blocked verification and were fixed as part of Step 5. Recording here for posterity since they explain why this commit is bigger than the spec described, and because the second one is systemic and may need follow-up if any of the unfixed callers are ever exercised.

**1. RouteHelper `extract` skipping `$params` (latent since the original handleDynamicRoute was written; surfaced when Step 5 made views depend on it).**

`RouteHelper::handleDynamicRoute($route, $params, $template_directory)` took a `$params` function argument (the URL-segment array `[0=>'',1=>'product',2=>'one-time-donation']`) and *also* tried to push the named placeholder map (`['slug'=>'one-time-donation']`) into view scope via `extract([..., 'params'=>$route_params], EXTR_SKIP)`. Because `$params` was already bound as a function argument, `EXTR_SKIP` silently kept the wrong value, so views received the segment array instead of the named map.

This was invisible before Step 5 because the model-injection path bypassed `$params['slug']` entirely — RouteHelper loaded the model itself and pushed it into scope as `$event` / `$product` / etc. After Step 5 made logic files self-load from `$input['slug']`, slugs flowed through `$params`, and every slug route 404'd because `$input['slug']` was empty.

**Fix:** rebind `$params = $route_params` directly in `handleDynamicRoute`, before any `extract()` call, so the function-arg `$params` is overwritten with the named map. (`includes/RouteHelper.php` around line 412.)

**2. Step-4 `if ($input)` always-truthy gate (12 logic files).**

Step 4's mechanical `($get_vars, $post_vars)` → `(array $input)` rename replaced gates like `if ($post_vars)` with `if ($input)`. But `$input` is `array_merge($_GET, $_POST, $params ?? [])`, and the routing layer always injects `__route` into `$_GET`, so `$input` is truthy on every request — including fresh GETs.

Affected files entered their submission/action branch on every page load, attempted to validate empty form data, and either threw a `SystemDisplayableError` (surfacing as 500) or returned a redirect like `/login?retry=1`. Surface area: 12 files, but only `/login` and `/register` were exercised by the routing test, so the others are silent regressions:

```
register_logic.php             login_logic.php
password_reset_2_logic.php     password_edit_logic.php
survey_logic.php               contact_preferences_logic.php
verify_totp_logic.php          address_edit_logic.php
phone_numbers_edit_logic.php   event_withdraw_logic.php
password_set_logic.php         change_password_required_logic.php
```

**Fix:** all 12 gates rewritten as `if (!empty($_POST))`. To keep API entry points working (apiv1.php parses JSON request bodies into a separate `$post_params` and PHP doesn't auto-populate `$_POST` for `application/json`), `apiv1.php` now copies `$post_params` into `$_POST` before invoking the logic function — same gate works for browser POST and JSON API submissions. (`api/apiv1.php` around line 543.)

This is the post-Step-5 convention for action-vs-load gates in mixed logic files: `if (!empty($_POST))`, mirroring `cart_logic`, `post_logic`, `lists_logic`, and `event_waiting_list_logic`. No `$_SERVER` references.

> **Superseded:** this `if (!empty($_POST))` / no-`$_SERVER` convention is replaced by `LibraryFunctions::isFormSubmission()` (`$_SERVER['REQUEST_METHOD'] === 'POST'`), which also handles JSON API POSTs without the `apiv1.php` `$_POST` copy described above. See [`admin_logic_get_submission_guard_fix.md`](admin_logic_get_submission_guard_fix.md).

**3. Test-file fixes folded in.**

`tests/integration/routing_test.php` had three CLI-incompatibility issues that prevented the test from being a real verification path: it used `$_SERVER['DOCUMENT_ROOT']` (empty in CLI), asserted plugin-index URLs return 200 unconditionally (joinery_ai is owner-only and 302s to login), and referenced a renamed util (`forms_example_bootstrap` → `forms_example_bootstrapv2`). Test now runs cleanly from CLI and passes 53/53.

### Cost

Two costs, both small:

- **One extra primary-key/slug lookup per page load** for these 9 routes. Indexed query, ~0.2–0.5ms on a warm connection; invisible at page-render scale.
- **No backwards compatibility** for code that read the auto-extracted scope variables before the logic call. The pre-verification grep + per-file lockstep migration handle this; no shipped code path that needed the old behaviour goes through this path today.

### Benefit

- **One logic-file convention everywhere.** A plugin developer reads any file in `/logic/` (core or plugin) as a valid reference. No core-internal exception to learn.
- **One logic-call form everywhere.** Every `process_logic(foo_logic(array_merge($_GET, $_POST, $params ?? [])))` line in the codebase reads identically — view scanners no longer need to know which routes have URL placeholders.
- **Smaller `RouteHelper`.** A third of `handleDynamicRoute()` deletes — the model-loading branch, the `var_name` aliasing, and the auto-view fallback.
- **Smaller route-config surface.** Three route-config keys (`model`, `model_file`, `var_name`) and a documentation section retire.
- **No more "two model loads per request"** mental confusion: there is one load, in the logic file, where the rest of the per-request work lives.
- **Pre-existing Step-4 breakage gets cleaned up.** A handful of test/util call sites that have been silently passing empty `$input` since Step 4 get fixed as part of Layer 5b, restoring real coverage to the affected tests.

### Why not other approaches

- **Leave the 9 files as-is and document the exception.** Cheapest change, but a permanent two-convention codebase. A plugin developer reading `event_logic.php` as a reference learns the wrong shape. Defeats the point of the refactor.
- **Pass the model through `$input` (e.g. `$input['event']` = pre-loaded object).** Avoids the reload but pollutes `$input` — it stops being "user-supplied data" and becomes a mixed bag of inputs and pre-resolved objects. Breaks the mental model the descriptor work is building on.
- **Request-scoped identity map** that makes `Model::get_by_link()` idempotent within a request. Genuinely useful — the codebase reloads the same User/Product/etc. across many call sites — but it's a separate, larger initiative with cache-invalidation concerns on save. Not a blocker for Step 5; revisit independently if the duplicate-load pattern proves to grate.

### Capability tradeoff

After Layer 3, route-level model loading is no longer a routing-system feature. Today nothing relies on it for permission gates or owner checks at the route level (those happen inside views/logic), so no current feature breaks. A future requirement to "load model and reject the request before the view runs" must implement that check inside the logic file rather than declaratively in the route config. The logic file is the right place for it anyway — it has the session, the model, and the result-building primitives — but the option is being deliberately retired.

### What this step does NOT include

- Renaming loader files to `*_load()` — not needed; the calling convention is already uniform.
- Splitting mixed files into separate loader and action functions — not needed; mixed files work correctly for web, API, and AI consumers as-is.
- Adding descriptors to mixed files — that can be done file-by-file whenever a specific file's POST action warrants API/AI exposure. It is independent of this step.

---

## Step 6 — FormWriter reads from descriptors

**Broken out into [`FUTURE_formwriter_descriptors.md`](../FUTURE_formwriter_descriptors.md).**

The standalone spec covers the FormWriter `fromDescriptor()` method, the descriptor-type → field-type mapping, the migration approach for existing forms, and the dependencies. Independent of Step 7 — either can ship first.

---

## Step 7 — REST API and AI consume descriptors natively

**Broken out into [`FUTURE_descriptor_consumers.md`](../FUTURE_descriptor_consumers.md).**

The standalone spec covers the four sub-pieces (REST API descriptor switch, boundary input validator, AI describe_actions/invoke_action surface, and `_logic_api()` retirement migration), the dependencies on Steps 3–5, and the effort estimate (medium-large, 3–5 days).

---

## Summary

| Step | Change | Complexity | Status | Enables |
|------|--------|------------|--------|---------|
| 1 | Fix legacy logic files | Trivial | Done | Safe directory scanning |
| 2 | Entity state transitions → model methods | Low | Done (largely N/A) | Clean model/logic boundary |
| 3 | Add descriptors to action-shaped files | Low | Done | Rich API metadata, AI schemas |
| 4 | Normalize logic function signatures | Medium | Done | Uniform invocation from any caller |
| 5 | Normalize extra-param page controllers | Medium | Done (2026-05-06) | Single calling convention everywhere |
| 6 | FormWriter reads from descriptors — see [`FUTURE_formwriter_descriptors.md`](../FUTURE_formwriter_descriptors.md) | Medium | Not started | One declaration drives forms |
| 7 | REST API + AI consume descriptors natively — see [`FUTURE_descriptor_consumers.md`](../FUTURE_descriptor_consumers.md) | Medium-large | Not started | Free integration payoff |

Steps 1–5 are done. Steps 6–7 are the payoff and can begin as soon as a critical mass of descriptors exist — they do not require Step 5 to be complete.

### The one-type principle

Every logic file a plugin developer writes follows a single convention:

```php
function foo_logic(array $input): LogicResult {
    // ...
}
```

- **No descriptor** → page handler. Called by its view; not API/AI exposed.
- **With `foo_logic_descriptor()`** → action. Also callable via REST API and AI tools.

Whether the function is a pure loader, a pure action, or handles both GET and POST internally is an implementation detail — not a different type. The calling convention is identical in all cases.
