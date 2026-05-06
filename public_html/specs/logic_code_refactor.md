# Logic Layer Refactor

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
- [`joinery_ai_write_tools.md`](joinery_ai_write_tools.md) — write tool design for AI; depends on steps 4–5 here

---

## Step 1 — Fix legacy logic files

**Complexity: trivial**

Already specced in [`fix_legacy_logic_files.md`](fix_legacy_logic_files.md). Must land first — everything that scans the logic directory assumes all `*_logic.php` files are safe to `require_once`.

---

## Step 2 — Move entity-scoped state transitions to model methods

**Complexity: low**

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

46 logic files + their call sites in views and serve.php. Mechanical but broad. A find-replace pass handles most of it; logic files that read from `$get_vars` and `$post_vars` in complex ways need manual review.

---

## Step 5 — Split mixed page-handler logic files

**Complexity: high — largest single body of work**

Most logic files handle both GET (load page data) and POST (execute an action) in the same function. These need splitting into two separate functions:

- **`{name}_load(array $input): array`** — returns view data for GET requests. Not an action; not exposable to API or AI. Returns a plain array of page variables, not a `LogicResult`.
- **`{name}_logic(array $input): LogicResult`** — handles the POST action. Gets a descriptor (step 3), gets exposed to API and AI.

### Convention

```php
// Before: one function doing both
function events_logic($get_vars, $post_vars) {
    // loads events list for display
    // also handles POST to create event
}

// After: two functions
function events_load(array $input): array {
    // returns ['events' => ..., 'pager' => ..., etc.]
    // called by the view on GET
}

function event_create_logic(array $input): LogicResult {
    // handles POST to create event
    // has a descriptor, is exposable
}
```

### What happens to GET-only logic files

Some files are purely data loaders (no POST action). These become `_load` functions with no corresponding `_logic`. They're view helpers, not actions, and don't get descriptors.

### View changes

Views currently call `process_logic(foo_logic($_GET, $_POST))` on every request. After the split:

```php
// In the view (GET path)
$page_vars = foo_load(array_merge($_GET, $_POST));

// In serve.php or the view (POST path, when form submitted)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = process_logic(foo_logic(array_merge($_GET, $_POST)));
}
```

The exact wiring depends on how each view currently handles the GET/POST distinction. Some already check `$_SERVER['REQUEST_METHOD']`; others rely on the logic function to detect it internally.

### Scope estimate

Approximately 30–35 of the 46 logic files are mixed page-handlers. Each split also touches the view(s) that consume it. This is the most labor-intensive step, but it can be done file-by-file with no cross-file dependencies — each split is self-contained.

Priority order: start with files that have the most value as exposed actions (event management, booking, product purchase, membership management). Leave low-traffic or admin-only pages for later.

---

## Step 6 — FormWriter reads from descriptors

**Complexity: medium — requires FormWriter changes + descriptor completeness**

Once descriptors exist (step 3) and are complete for action-shaped files, FormWriter can generate forms from them rather than requiring developers to declare each field manually.

### How it works

```php
// Current: developer declares each field
$fw = $page->getFormWriter('register');
$fw->addText('usr_email', 'Email');
$fw->addPassword('usr_password', 'Password');

// With descriptor-driven forms:
$fw = $page->getFormWriter('register');
$fw->fromDescriptor(register_logic_descriptor());
// generates all fields from the descriptor's 'input' array
```

### Type mapping

Each descriptor `type` maps to a FormWriter field type. The mapping lives in `FormWriterV2HTML5` and `FormWriterBootstrap`:

| Descriptor type | FormWriter field |
|----------------|-----------------|
| `string` | `addText` |
| `email` | `addEmail` |
| `password` | `addPassword` |
| `int` | `addNumber` |
| `bool` | `addCheckbox` |
| `select` | `addSelect` (with `options` from descriptor) |
| `text` | `addTextarea` |
| `date` | `addDate` |

### Field-level extras

Descriptor fields can carry additional FormWriter hints:

```php
'email' => [
    'type'        => 'email',
    'required'    => true,
    'label'       => 'Email address',
    'placeholder' => 'you@example.com',
    'help'        => 'We will send a confirmation to this address.',
],
```

Fields not representable by a descriptor type (file uploads, rich text, custom widgets) are added manually after `fromDescriptor()` — the method adds only what it knows about, remaining fields are hand-added as before.

### What this enables

One declaration drives: form HTML, client-side validation attributes, server-side type coercion, API documentation, AI tool schemas. A developer adds a field to the descriptor and it appears everywhere.

---

## Step 7 — REST API and AI consume descriptors natively

**Complexity: medium — requires steps 3–5 to be substantially complete**

The REST API's action surface and the AI's tool discovery both read from `_logic_descriptor()`. The `_logic_api()` opt-in is retired.

### REST API changes

`apiv1.php` action discovery (`GET /api/v1/actions`) reads `_logic_descriptor()` instead of `_logic_api()`. The `description` and `requires_session` fields are the same; the new `input` field drives parameter documentation. The invocation path (`POST /api/v1/action/{name}`) validates incoming parameters against the descriptor's input schema before calling `_logic()`.

Migration: files with `_logic_api()` but no descriptor get the descriptor added; `_logic_api()` is then deleted. Files with descriptors only work automatically.

### AI tool discovery changes

Per [`joinery_ai_autodiscovery.md`](joinery_ai_autodiscovery.md), the AI already has the model auto-discovery surface. With descriptors in place on action-shaped logic files, a second surface (`describe_actions` / `invoke_action`) can be added without per-action `RecipeToolInterface` classes. The descriptor's `mutates` flag gates read vs. write exposure. See [`joinery_ai_write_tools.md`](joinery_ai_write_tools.md) for the write side.

### Input validation at the boundary

Both the REST API and AI invocation paths validate and normalize inputs against the descriptor schema before calling `_logic()`. This is the equivalent of what `$field_specifications` does for model inputs — a boundary check that catches type errors before they propagate into business logic. The logic file's own validation still runs; the descriptor check is a fast first-pass, not a replacement.

### The payoff

At this point: write a business action, add a descriptor, and it is available to HTTP forms (step 6), REST API (step 7), and AI tools (step 7) with no per-consumer boilerplate. The same experience as adding `$ai_read_safe = true` to a model.

---

## Summary

| Step | Change | Complexity | Enables |
|------|--------|------------|---------|
| 1 | Fix legacy logic files | Trivial | Safe directory scanning |
| 2 | Entity state transitions → model methods | Low | Clean model/logic boundary |
| 3 | Add descriptors to action-shaped files | Low | Rich API metadata, AI schemas |
| 4 | Normalize logic function signatures | Medium | Uniform invocation from any caller |
| 5 | Split mixed page-handler logic files | High | Full surface area exposable |
| 6 | FormWriter reads from descriptors | Medium | One declaration drives forms |
| 7 | REST API + AI consume descriptors natively | Medium | Free integration payoff |

Steps 1–3 are independent and low-risk. Step 4 is a broad mechanical refactor. Step 5 is the largest body of work but can be done file-by-file. Steps 6–7 are the payoff that steps 3–5 make possible.

Steps 1–3 deliver immediate value (richer API metadata, AI schema generation) without touching any callers. The full "free integration" payoff requires steps 4 and 5. Steps 6–7 can start as soon as a critical mass of descriptors exist — they don't require 100% coverage to be useful.
