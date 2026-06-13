# CRUD API: opt-in resources + deny-by-default row scope + field-level floors

**Status:** Implemented (2026-06-12)
**Created:** 2026-06-12

> **Implementation note — public read is a flag, not a no-op override.** During build, the
> "public read = override `authenticate_read` to a no-op" approach (below) was refined to a
> declarative `public static $api_public_read` flag on `SystemBase` (default false). The reason:
> §4.5's collection owner-scope must know whether a model is public to decide whether to apply the
> owner-filter, and a no-op method override is not introspectable for that decision — five public
> Bucket A models (Post, Page, Event, PageContent, ProductDetail) carry a real `{prefix}_usr_user_id`
> column and would have had their public collections wrongly owner-filtered. The flag captures
> "this resource is public" as one queryable fact used by both the row-read gate and the collection
> scope. Where the text below says "override `authenticate_read` to a no-op," read "set
> `$api_public_read = true`." Everything else shipped as written.
**Scope:** The core REST CRUD surface (`/api/v1/{Class}`). Establishes three independent
authorization layers — resource exposure, row scope, and field-level read/write floors — matching
how mainstream API frameworks separate these concerns, and unifying the field-level controls with
the ones the AI model surface already has. Pre-launch: no production data to migrate (see
[[project_no_production_users]]), so behavior changes are acceptable as long as the known internal
clients (the mobile app surface and `tests/functional/api/`) keep working.

---

## 1. The problem: the CRUD surface is default-open over every model

`api/apiv1.php` builds its resource list from `LibraryFunctions::discover_model_classes()`
(`apiv1.php:279`), which returns **every** `SystemBase` subclass in `data/` — 84 core models
today. A class is a CRUD resource simply by existing. The only per-row gate is
`authenticate_read()` / `authenticate_write()`, and the `SystemBase` defaults are **no-ops**
(`SystemBase.php:1530,1532`): `function authenticate_read($data) {}`. So a model with no explicit
override hands out **any row by id** to any active key with the right verb capability, and accepts
writes to **any column** it defines.

This is not hypothetical. The recent per-record audit
(`specs/implemented/documentation_audit_from_plugin_simulation.md`, finding 3.4) added
owner-or-staff hooks to ~25 models one at a time and still missed `User` and `Message` — the two
models it named as the motivating leak examples — because the safe behavior depends on every model
author remembering to write a hook. `GET /api/v1/User/{id}` returned full PII (and the password
hash) until it was patched by hand. That is the signature of a default-open design: every new model
is a hole until someone notices.

### How other frameworks handle this

No mainstream framework makes "a model" mean "an endpoint" with an open default:

- **Rails / Django REST / Laravel** — exposure is **opt-in**: you declare a resource (route +
  controller, or serializer + viewset, or `apiResource`). Authorization is a separate layer
  (Pundit / Laravel Policies, DRF object permissions, or queryset scoping like
  `Order.objects.filter(user=request.user)`). Field exposure is a third layer — serializers /
  `$fillable` (writable allowlist) / `$hidden` (read blocklist). Laravel's `User` ships with
  `$hidden = ['password','remember_token']` *and* a `$fillable` allowlist by default — exactly the
  read and write floors this spec describes.
- **Spring Data REST / PostgREST / Supabase** — the minority auto-expose camp (which is what we
  resemble). They survive it by making enforcement **mandatory and default-deny**:
  `@RepositoryRestResource(exported=false)` opt-outs, or Postgres Row-Level Security policies that
  deny unless a policy allows.

We are in the one combination essentially nobody recommends: **auto-expose with deny-by-default
turned off** — the exposure without the enforcement that makes it safe.

---

## 2. The model: three independent layers

The fix is to separate the three concerns every framework keeps separate, and make each
safe-by-default.

| Layer | Question | Mechanism | Default |
|---|---|---|---|
| **1. Resource exposure** | Is this class a CRUD resource at all? | `$api_readable` / `$api_writable` (new) | **not exposed** |
| **2. Row scope** | May this caller touch *this row*? | `authenticate_read` / `authenticate_write` | **owner-or-staff (deny)** |
| **3. Field floors** | Which fields may be read / written? | **read:** unreadable floor (done); **write:** unwritable floor (new) | secrets unreadable, privileged columns unwritable — **shared with the AI surface** |

Layer 3 is symmetric and shared across both API surfaces. The **read** half already exists
(`SystemBase::is_unreadable_field()` / `export_for_api()`, the unreadable floor the AI surface also
honors). This spec adds Layer 1, flips Layer 2's default, and adds the **write** half of Layer 3 —
mirroring the read floor and unifying with the AI's `$ai_writable_fields`. The per-key capability
axis (`apk_permission` read/write/delete via `ApiAuth::authorize()`) is unchanged and sits above
all three.

Note the symmetry with the rest of the system: **action** endpoints are already opt-in (a logic
function is reachable only if it defines `{action}_logic_api()`), and the AI model surface is
already opt-in (`$ai_readable`) with field-level read/write control (`$ai_excluded_fields` /
`$ai_writable_fields`). CRUD is the one surface missing all of it. This brings it in line.

---

## 3. Layer 1 — resource exposure opt-in

### 3.1 The declaration

Two static booleans on the model, mirroring the AI surface's `$ai_readable`:

```php
class Foo extends SystemBase {
    public static $api_readable = true;   // exposed to GET /{Class}/{id} and GET /{Class}s
    public static $api_writable = true;   // exposed to POST/PUT/DELETE /{Class}
    ...
}
```

`SystemBase` declares both `false`. Read and write are separate so a model can be **read-only**
(`$api_readable = true; $api_writable = false;`).

### 3.2 Where the gate goes — the API layer, NOT discovery

**Critical:** `discover_model_classes()` is shared infrastructure — consumed by
`update_database`/`DatabaseUpdater` (table creation), `PluginManager::sync()`, deletion-rule
resolution, `SystemBase` reference caching, and several `utils/` scripts. It must keep returning
**all** models. The API opt-in filter therefore lives in `apiv1.php`, applied to the discovered
list:

```php
$all_classes = LibraryFunctions::discover_model_classes();
$readable_classes = array_filter($all_classes, fn($c) => self::api_flag($c, 'api_readable'));
$writable_classes = array_filter($all_classes, fn($c) => self::api_flag($c, 'api_writable'));
```

CRUD dispatch then gates per verb:

- `GET /{Class}/{id}` and `GET /{Class}s` → membership in `$readable_classes` (else 404, as today
  for an unknown class — an unexposed model is indistinguishable from a nonexistent one).
- `POST` / `PUT` / `DELETE /{Class}` → membership in `$writable_classes` (else 404).

Plugin models stay excluded (`discover_model_classes()` defaults `include_plugins` to false).

### 3.3 Helper

`api_flag($class, $prop)` reads the static safely (a model predating the property inherits the
`false` default from `SystemBase`). Put it next to the other API helpers in `apiv1.php`, or as a
small static on `SystemBase` if a second consumer appears.

---

## 4. Layer 2 — deny-by-default row scope

### 4.1 Flip the SystemBase default

Today (`SystemBase.php:1530`):

```php
function authenticate_read($data) {}          // no-op = open
function authenticate_write($data) {}          // no-op = open
```

Change to **owner-or-staff**, using the conventional owner column:

```php
function authenticate_read($data) {
    $owner_col = static::$prefix . '_usr_user_id';
    $owner_matches = array_key_exists($owner_col, static::$field_specifications)
        && $this->get($owner_col) == $data['current_user_id'];
    if (!$owner_matches && (int)$data['current_user_permission'] < 5) {
        throw new SystemAuthenticationError(
            'Current user does not have permission to view this entry in '. static::$tablename);
    }
}
// authenticate_write identical (wording: "edit")
```

Semantics:

- Model **with** a `{prefix}_usr_user_id` column → owner-or-staff (the `orders` pattern, now the
  default).
- Model **without** an owner column → staff-only (no owner to match → falls to the `< 5` check).
  Safe for tables with no per-user ownership.
- A model that should be **publicly readable** (posts, pages) overrides `authenticate_read` to a
  no-op — you override to *open*, never to close.

### 4.2 Why flipping the default is safe for existing callers

Three kinds of caller; none regress:

- **The ~76 models that already override** — unaffected; they don't use the default.
- **Admin pages** (`adm/*`, `adm/logic/*`) that call `authenticate_write` — run behind
  `check_permission(5+)` and pass `current_user_permission ≥ 5`, so the owner-or-staff default
  passes for staff. (Spot-checked: `admin_message.php` is `check_permission(8)`.)
- **The API** — exactly where we *want* the new default to bite: an exposed model with no explicit
  scope now denies non-owners instead of leaking.

### 4.3 Safety net — close the fail-open path

`apiv1.php` relies solely on `authenticate_read()` *throwing*; it ignores the return value. After
the files/videos rename (§6) no model returns a bool from these methods, but to prevent recurrence
the CRUD read/write sites should treat a **falsy return as denial** as well as catching the throw.
Document the contract as **throw-to-deny**.

### 4.4 Ownership integrity — the owner column itself

The row-scope check is only sound if the *owner column* cannot be forged or reassigned. Today's
`apiv1.php` order of operations defeats it: on PUT it loads the row, applies every input field
(including `{prefix}_usr_user_id`), and only *then* calls `authenticate_write()` — which now sees
the **mutated** owner. So `PUT /api/v1/Order/{someone-else's id}?ord_usr_user_id={my id}` passes the
owner-or-staff check (the row "belongs to me" after the set) and saves: a row takeover. The
deny-by-default does not fix this on its own. Three coordinated rules close it:

1. **PUT authorizes the *loaded* row, before applying input.** Move the `authenticate_write($auth_data)`
   call to *before* the field-application loop, so it checks the row as it exists in the database.
   You may only update a row you already own.
2. **Owner columns (`{prefix}_usr_user_id`) are unwritable via CRUD.** They join the write floor
   (§5) — dropped from input like any unwritable field — so ownership can never be reassigned through
   a field write. Staff reassignment, where needed, happens through admin, not raw CRUD.
3. **POST stamps the owner server-side.** On create of a model that has an owner column, set
   `{prefix}_usr_user_id = current_user_id` from the session rather than trusting input. Without this,
   a create that omits the owner fails under deny-by-default (null ≠ you), and a create that *supplies*
   an owner is exactly the spoof we're blocking. This is the `current_user.orders.create(...)` pattern.

**The POST `CreateNew()` fast-path must not escape the floor.** `apiv1.php`'s POST branch first tries
`$class_name::CreateNew($_POST)` and only falls through to the field-application loop if that returns
falsy. A model's `CreateNew()` is a business-logic factory (`User::CreateNew()` is the only one today:
it dedups by email, hashes the password, sends activation). It bypasses the apply loop entirely, so
rules 2–3 and the §5 write floor — all written as if every POST flows through that loop — silently
do **not** apply to a `CreateNew` model. This is harmless *today* (`User::CreateNew()` is itself a
curated allowlist that never reads `usr_permission`, and `User` is not owner-scoped), but it is a
latent hole of exactly the kind this spec exists to close: the first owner-scoped model to grow a
`CreateNew()` would escape both the owner-stamp and the unwritable drop unnoticed.

The fix is to make the floor a property of the **API boundary**, not of one code path: the unwritable
drop (§5.3) and the owner-stamp are applied to the input map **before dispatch**, so they wrap
`CreateNew()` and the raw loop alike. Concretely — strip unwritable keys (and the owner column) from
the working input *before* it is handed to either `CreateNew()` or the set-loop, then stamp
`{prefix}_usr_user_id = current_user_id` for owner-column models on the created row. A model author
can never reintroduce a privileged-field write by adding a `CreateNew()` factory, because the boundary
already sanitized the input that factory receives.

Net: you may update only rows you already own, you cannot reassign them, created rows are owned by
the caller by construction, and the write floor holds on **every** create path — `CreateNew` or raw.

### 4.5 Collection scoping — filter the query, don't leak the count

The collection endpoint loads a page and skips unauthorized rows in PHP, but returns
`num_results` from an **unfiltered** `count_all()` — so a caller sees only their own rows yet learns
the total row count. For **owner-scoped models read by a non-staff caller**, inject the owner filter
into the `Multi` query (`{prefix}_usr_user_id = current_user_id`); staff get the unfiltered query.
Then `count_all()` is correct, nothing over-fetches, and there is no count disclosure. The per-record
`authenticate_read` stays as a backstop. (Non-ownership scoping remains fetch-then-filter — see §11.)

---

## 5. Layer 3 (write) — the unwritable-field floor

The read half of Layer 3 already shipped: a shared **unreadable floor** (`CREDENTIAL_FIELD_PATTERN` +
per-model `$api_unreadable_fields`, honored by both REST `export_for_api()` and the AI surface). This
section adds the **write** half — the exact mirror — so "which fields may be written over an API"
has one definition, shared by REST and AI, instead of living only on the AI side as
`$ai_writable_fields`.

### 5.1 Why a write floor (and why it's the real fix for `User`)

Raw CRUD writes (`POST`/`PUT`) call `$object->set($col, $val)` for each input column, then `save()`
— there is **no field-level guard**, so a write can set *any* column the model defines, including
privileged ones. `PUT /api/v1/User/{own id}?usr_permission=10` is privilege escalation: the
owner-or-staff row scope (Layer 2) passes (you own the row), and nothing stops the `usr_permission`
column from being set. Frameworks close this at the field layer (Rails strong params, Laravel
`$fillable`, DRF serializer fields). The AI surface already does (`$ai_writable_fields`). REST must
too — and once it does, `User` is safely writable because the *dangerous column* is blocked, not
the whole model.

### 5.2 The shared core mechanism

Mirror the read unreadable floor exactly. In `SystemBase`:

```php
// Credentials (caught by the existing read regex) are never writable either.
// Plus an explicit per-model list of privileged, non-credential columns.
public static $api_unwritable_fields = array();

public static function is_unwritable_field($field) {
    if (in_array($field, static::$api_unwritable_fields, true)) return true;
    return (bool) preg_match(self::CREDENTIAL_FIELD_PATTERN, $field);   // reuse the read floor's regex
}
```

- `CREDENTIAL_FIELD_PATTERN` (`/_(password|secret|key|token|hash)$/i`) covers credentials for both
  directions — a credential is neither readable nor writable.
- `$api_unwritable_fields` is the **write-specific** list for privileged columns that are *not* secrets
  (so they're readable but must never be set via API), e.g. `usr_permission`. This is why the write
  floor is a distinct list from `$api_unreadable_fields`, not the same one.

### 5.3 Applying it at the REST write boundary

Apply the floor at the **API boundary** — sanitize the input map *once, before it reaches any create
or update path* — so it holds regardless of whether the write flows through the field-application
loop or the POST `CreateNew()` fast-path (§4.4). **Silently drop** any unwritable field (strong-params
style — preserves round-trip read-modify-write, where a client PUTs back a full object including
read-only fields):

```php
// Boundary sanitize: runs before CreateNew() and before the set-loop alike.
$input_fields = array_filter(
    $input_fields,
    fn($col) => !$class_name::is_unwritable_field($col),
    ARRAY_FILTER_USE_KEY
);
foreach ($input_fields as $col => $val) {
    $object->set($col, $val);   // unwritable cols already gone; keep DB/default value
}
```

Dropping (not rejecting) matches framework convention and avoids breaking legitimate full-object
writes; the unwritable column simply retains its stored value (PUT) or model default (POST). Because
the sanitize happens before dispatch, a `CreateNew()` factory receives an input array already stripped
of unwritable keys — the floor cannot be bypassed by a model defining its own create path.

### 5.4 Unifying the AI surface onto the same floor

The AI write surface (`ModelRegistry`) already strips fields matching its auto-block regex (now
`SystemBase::CREDENTIAL_FIELD_PATTERN` after the secret-floor work) from `$ai_writable_fields`. Extend
it to also strip `is_unwritable_field()` matches, so the core `$api_unwritable_fields` list governs the
AI write surface too — the write-side analogue of how `ModelSchemaBuilder::excludedFor()` folded the
read unreadable floor under `$ai_excluded_fields`. Result: one core write floor, with the AI's
allowlist (`$ai_writable_fields`) layering further restriction on top; REST's blocklist (drop
unwritable) layering permissive access underneath. Same compose-over-a-shared-floor shape as the
read side.

| Direction | Shared core floor (both surfaces) | Per-surface layer |
|---|---|---|
| **Read** | unreadable floor: regex + `$api_unreadable_fields` (never exported) | AI adds `$ai_excluded_fields` |
| **Write** | unwritable floor: regex + `$api_unwritable_fields` (never written) | AI narrows to `$ai_writable_fields` (allowlist) |

### 5.5 Nested embeds must honor the read floor

`export_for_api()` strips the unreadable floor from a model's own columns, but an override that
**embeds a child model** can re-leak through the back door: `User::export_as_array()` embeds
`phone`/`address` via the child's `export_as_array()` (the full row), not `export_for_api()`. Harmless
today (neither child has unreadable columns), but latent — a future credential column on
`PhoneNumber`/`Address` would surface inside a `User` API response. Two rules close it:

- **Rule (documented):** an override that embeds a child model exports it via `export_for_api()`, not
  `export_as_array()`.
- **Fix the existing case:** switch the `phone`/`address` embeds in `User::export_as_array()` to
  `export_for_api()` — a no-op today, correct going forward.

No recursive floor-walking: `export_for_api()` can't reliably tell which nested arrays are model
exports, so the rule plus fixing the real embeds is the whole fix.

---

## 6. The files/videos rename (resolves the long-standing overload)

`files` and `videos` are the only two models whose `authenticate_read` is not API row-ownership —
it is **content-visibility gating** (`fil_min_permission`, group membership, event registration,
subscription tier; returns `false`, takes `$data['session']`). It is called only by the file/video
*serving* paths, and the two callers don't even agree on the argument shape:

- `serve.php:408` — `$file_obj->authenticate_read(array('session'=>$session))`
- `logic/video_logic.php:32` — `$video->authenticate_read($session)` (bare object — likely already
  broken against the `$data['session']` body)

A different question wearing the API method's name. Rename it:

1. On `files`/`videos`, rename the visibility predicate to **`is_viewable($session): bool`** (takes
   the session object, returns bool — its natural shape). Body unchanged.
2. Update `serve.php` and `video_logic.php` to call `is_viewable($session)`, fixing the
   argument-shape inconsistency.
3. Under Layer 1, `File`/`Video` do **not** opt in — they are served as bytes through `serve.php`,
   never read as JSON rows — so they need no API `authenticate_read` at all.

After this, `authenticate_read`/`authenticate_write` mean exactly one thing everywhere (API
row-ownership, throw-to-deny), and content visibility is an honestly-named `serve.php` concern.

---

## 7. Up-front exposure inventory (decide once)

Per the project preference to inventory all integration points up front
([[feedback_upfront_inventory]]), the exposure decision is made here for the whole model set,
grounded in a client-usage reconciliation.

### 7.1 Reconciliation — who actually consumes the CRUD class surface

Every in-repo reference to `/api/v1/{Class}` was inventoried (PHP, JS, tests, mobile-app specs):

- The **only runtime consumer** is `tests/functional/api/session_keys_test.php`
  (`GET /api/v1/User/{id}`).
- The **ScrollDaddy mobile apps use the action/form surface exclusively** — zero CRUD-class
  references; the only `/api/v1/...` path in the iOS spec is `/api/v1/form/{action}`.
- The `RequestLogger.php` mention is a docblock example string, not a call.
- Every other `/api/v1/User`, `/Post`, … reference is in docs/specs as an example.

Caveat: this proves nothing *outside* the repo, but the mobile client is action-based and the
platform is pre-launch ([[project_no_production_users]]), so closing the CRUD surface breaks no
known consumer, and opt-in is the safe default.

### 7.2 The decision — a curated, permissive domain surface

This is a platform product: the CRUD API is a feature customers build against, so the exposed set
is the **domain**, decided up front, leaning permissive. **Default = not exposed.** Reads are broad;
writes are enabled too (per the "lean permissive" decision) — with the strong caveat in §7.3, now
backed by the §5 write floor.

**Bucket A — Public / catalog content** (`$api_readable = true`, `$api_writable = true`; read is
public via an open `authenticate_read` override; write inherits the deny-by-default —
owner-or-staff for owned content, staff-only for ownerless catalog):

> `Event`, `EventSession`, `EventType`, `Product`, `ProductDetail`, `ProductGroup`, `Post`, `Page`,
> `PageContent`, `Location`, `Group`, `MailingList`, `SubscriptionTier`, `Survey`, `Question`,
> `QuestionOption`

**Bucket B — User-owned** (`$api_readable = true`, `$api_writable = true`, owner-or-staff via the
deny-by-default or the model's existing override):

> `User`, `Order`, `OrderItem`, `Address`, `PhoneNumber`, `Notification`, `NotificationPreference`,
> `EventRegistrant`, `WaitingList`, `SurveyAnswer`, `MailingListRegistrant`, `Message`,
> `Conversation`, `ConversationParticipant`, `Comment`, `Reaction`, `StripeInvoice`

`User` is now **writable** as well — the §5 write floor puts `usr_permission` (and any other
privileged columns) in `$api_unwritable_fields`, and credentials are caught by the regex, so a raw write
cannot escalate or set a password. Business-shaped account changes still *should* go through the
`account_edit` action (§7.3), but the security hole that previously forced read-only is closed at
the field layer.

**Bucket C — Closed (no flag, never a resource):** everything else — credentials/config (`ApiKey`,
`ActivationCode`, `Setting`), audit/log tables (`RequestLog`, `EventLog`, `GeneralError`,
`FormError`, `ChangeTracking`, `SessionAnalytic`, `VisitorEvent`, `DebugEmailLog`, `WebhookLog`),
system internals (`Migration`, `Plugin*`, `Upgrade`, `ContentVersion`, `AdminMenu`, `PublicMenu`,
`Component`, `AgentFile`, `Theme`, `DeletionRule`, `ScheduledTask`, `Url`, `SeoPageMetadata`,
`AbTest*`), email plumbing (`QueuedEmail`, `EmailTemplate`, `Email`, `EmailRecipient`),
join/internal tables (`GroupMember`, `CouponCode*`, `ProductRequirement*`, `OrderItemRequirement`,
`EntityPhoto`), and serve-only `File`/`Video` (§6).

### 7.3 Writes are permitted but NOT recommended (and now field-guarded)

Two hazards ride along with raw CRUD writes; both are documented loudly (§10):

1. **Raw writes bypass business logic.** `POST`/`PUT`/`DELETE` do a direct field insert/update — no
   validation workflow, no side effects. `POST /api/v1/Order` creates an order with no
   payment/cart/receipt; `POST /api/v1/EventRegistrant` skips capacity and waitlist. The
   *recommended* write path for anything with a workflow is the corresponding **action** (checkout,
   event signup, registration, account edit). CRUD write is the raw escape hatch, not the front
   door. This is a recommendation, not a security boundary — it is the author's call which models
   to expose for write.
2. **Privileged fields are blocked by the write floor (§5), not by hope.** Raw writes can no longer
   set credential or `$api_unwritable_fields` columns — those are dropped. So the *escalation* class of
   risk (e.g. `usr_permission`) is closed system-wide, independent of which models opt into write.
   A model author adding a new privileged column adds it to `$api_unwritable_fields` (or names it with a
   credential suffix and the regex catches it), exactly as they would add a read secret to
   `$api_unreadable_fields`.

---

## 8. Implementation steps

1. **`SystemBase`** — add `$api_readable` / `$api_writable` (default false) and `$api_unwritable_fields`
   (default empty) + `is_unwritable_field()` (§5.2). Flip the `authenticate_read`/`authenticate_write`
   defaults to owner-or-staff (§4.1). `php -l`, `validate_php_file.php`.
2. **`apiv1.php`** — build `$readable_classes` / `$writable_classes` from the discovered list via the
   opt-in flag (§3.2); gate each CRUD branch on the right list; **sanitize the input map at the boundary
   before dispatch** — drop unwritable fields **and the owner column** — so the floor wraps both the
   POST `CreateNew()` fast-path and the raw set-loops (§5.3, §4.4); on PUT, authorize the **loaded** row
   before applying input (§4.4); on POST, stamp `{prefix}_usr_user_id = current_user_id` for owner-column
   models on the created row, on both the `CreateNew` and raw paths (§4.4); owner-scope the collection
   query for non-staff (§4.5); treat a falsy return from the per-record hooks as denial (§4.3).
3. **AI surface** — extend `ModelRegistry`'s writable computation to also strip
   `is_unwritable_field()` matches, folding the core write floor under `$ai_writable_fields` (§5.4).
4. **Opt-in the inventoried models** (§7.2) — Bucket A and B get both flags (A also overrides
   `authenticate_read` to open for public read); Bucket C gets nothing. Declare `$api_unwritable_fields`
   where needed, starting with `User` (`usr_permission`, account-disable flags).
5. **Rename** `authenticate_read` → `is_viewable($session)` on `files`/`videos`; update `serve.php`
   and `logic/video_logic.php` (§6). Switch the `phone`/`address` embeds in `User::export_as_array()`
   to `export_for_api()` (§5.5).
6. **Run** `tests/functional/api/session_keys_test.php` (must stay green; `User` read is the canary)
   plus targeted curls (§9). `php -l` + `validate_php_file.php` on every touched file.

---

## 9. Test plan

- **Exposure:** `GET /api/v1/Setting/1` → 404 (unexposed); `GET /api/v1/User/{own id}` → 200;
  `PUT /api/v1/Post/{id}` → 404 if `Post` were read-only (it is writable here, so 200/40x by scope).
- **Row scope (default):** users A and B, an owner-column model exposed but not overridden →
  A reading B's row → 40x; A reading own row → 200.
- **Public override:** `GET /api/v1/Post/{id}` of another user's post → 200.
- **Write floor:** `PUT /api/v1/User/{own id}` with `usr_permission=10` → the request may succeed
  (200) but the user's permission is **unchanged** (field dropped); `usr_password` likewise never
  set. A non-unwritable field (`usr_first_name`) on the same request **is** applied.
- **CreateNew fast-path (§4.4):** `POST /api/v1/User` with `usr_permission=10` in the body → user is
  created (or deduped) but `usr_permission` is **not** elevated — the boundary sanitize strips it
  before `CreateNew()` runs, so the floor holds even though POST never enters the raw set-loop.
- **Ownership integrity (§4.4):** A `PUT /api/v1/Order/{B's id}?ord_usr_user_id={A}` by A → 40x (PUT
  authorizes the loaded row, owned by B) and the owner column is unchanged; `POST /api/v1/Address`
  by A → created row is owned by A even if the body names another user; an `Order` create with no
  owner field still succeeds (owner stamped).
- **Collection scoping (§4.5):** A lists an owner-scoped collection → sees only A's rows **and**
  `num_results` equals A's count, not the global total; staff sees the unfiltered set.
- **Nested embed (§5.5):** a `GET /api/v1/User/{id}` response's embedded `phone`/`address` carries no
  unreadable-floor field (verified by adding a temp `$api_unreadable_fields` entry to a child in a
  throwaway check, or by inspection).
- **AI parity:** a model's `$api_unwritable_fields` column does not appear in its AI writable surface.
- **Regression:** full `session_keys_test.php` stays green (62 passing), incl. capability and
  management-plane assertions.
- **Serve paths:** a min-permission / group-gated file still 404s for an unauthorized viewer through
  `serve.php` after the `is_viewable` rename; a public file still serves.

---

## 10. Documentation updates

Update `docs/api.md` to the end state (current state only, no migration narrative; written at
implementation time since docs track current state):

- New **Resource exposure** subsection: `$api_readable` / `$api_writable`, default-closed,
  read/write separable; an unexposed class 404s.
- Rewrite **Per-record authorization**: the `SystemBase` default is now **owner-or-staff (deny)**,
  not a no-op; override to *open* public content; the contract is **throw-to-deny**.
- New **Field floors** subsection documenting the symmetric model: the read unreadable floor (already
  documented) and the write unwritable floor (`$api_unwritable_fields` + the credential regex), both
  shared with the AI surface; privileged/credential columns are dropped from writes.
- **A prominent admonition on CRUD writes** (exact text to ship, as a callout near POST/PUT/DELETE):

  > **⚠️ Raw CRUD writes are not recommended.** `POST`/`PUT`/`DELETE` write a model's columns
  > **directly** — they bypass all business-logic validation, side effects, and workflow. Creating
  > an `Order` this way produces an order with no payment, cart, or receipt; registering for an
  > `Event` skips capacity and waitlist checks. **Use the corresponding action endpoint (checkout,
  > event signup, registration, account edit) for anything with a workflow** — it is the supported
  > write path. CRUD write is a raw escape hatch for simple records, not the front door.
  >
  > Credential and privileged columns (anything matching the credential pattern, or listed in a
  > model's `$api_unwritable_fields` — e.g. `usr_permission`) are **silently dropped** from CRUD writes
  > and can only be changed through the action that owns them. Do not rely on CRUD write to set
  > them.

---

## 11. Non-goals

- Changing the `apk_permission` capability axis or `ApiAuth::authorize()` (the layer above;
  unchanged).
- Exposing plugin models via CRUD (still out; action endpoints remain the plugin path).
- Queryset-level scoping for **non-ownership** collection access (arbitrary visibility rules). Those
  keep "load then skip unauthorized rows." **Ownership** scoping *is* in scope and is done as a real
  query filter (§4.5) — it is both the count-leak fix and the perf fix and is cheap for the
  owner-column case.
- Touching `discover_model_classes()` semantics (it must keep returning all models for schema and
  deletion subsystems).

---

## 12. Rollback

Additive (`$api_readable`/`$api_writable`/`$api_unwritable_fields` properties, the apiv1 filter, the
write-drop loop, the owner-column handling and PUT pre-check (§4.4), the collection owner-filter
(§4.5), the AI strip, the `export_for_api()` embed switch (§5.5)) plus one default-body change and a
rename. Reverting: restore the no-op `SystemBase` defaults, drop the apiv1 filter / write-drop /
owner-handling / collection filter, revert the AI strip and embed switch, and restore
`authenticate_read` on `files`/`videos`. No schema, no data. The new flags/lists are inert once the
filter and drop are removed.
