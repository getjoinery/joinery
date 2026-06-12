# API Authorization Gate Unification

**Status:** Active (awaiting implementation)
**Created:** 2026-06-12
**Scope:** Core REST API authorization dispatch. No change to authentication (key lookup, secret verification, lifecycle), no change to which endpoints exist, no change to who may call them.

---

## 1. Motivation

The API authenticates requests in one shared chain (`api/apiv1.php` ~lines 274–405: key lookup, secret verify, expiry/active/IP checks). That part is sound and is **out of scope** here.

The problem is **authorization** — the "may this caller invoke this endpoint?" decision. Today it is implemented three separate times, once per surface, each with its own hardcoded thresholds and its own error wording:

| Surface | Router | Authorization rule (hardcoded) |
|---|---|---|
| Actions (`POST /api/v1/action/...`) | `ApiActionEndpoint::dispatchAuthenticated` | `apk_permission < 2` → 403 |
| Forms (`GET /api/v1/form/...`) | `ApiFormEndpoint::dispatchAuthenticated` | `apk_permission == 2` → 403 |
| Management (`/api/v1/management/...`) | `ManagementApiRouter::dispatch` | `apk_type != machine` → 403; `usr_permission < 10` → 403 |
| CRUD verbs (`/api/v1/{Class}...`) | inline in `apiv1.php` | read `== 2`; write `< 2`; delete `< 4` |

Consequences:

- The authorization rule for an endpoint lives **in whichever router caught it**, not near the endpoint. Answering "who can call `device_edit`?" means knowing it is an action and reading `ApiActionEndpoint`.
- The descriptor an author writes (`{action}_logic_api()`, `{handler}_handler_api()`) **cannot express its own auth** — it only carries `requires_session` and `description`.
- Two distinct permission axes (`apk_permission`, the per-key CRUD axis; `usr_permission`, the user role axis) appear as bare integer literals (`< 2`, `== 2`, `< 10`) with no names, which is the root of the "which permission is this?" confusion.

**Goal:** one place that decides authorization, one descriptor shape that can declare it — while keeping every endpoint and every permission requirement **exactly** as they are today.

---

## 2. The non-obvious fact this design hinges on: the CRUD permission axis is non-monotonic

`apk_permission` is **not** a linear "higher = more" scale. From the CRUD verb gates in `apiv1.php` (the source of truth):

| `apk_permission` | Read (`== 2` blocks) | Write (`< 2` blocks) | Delete (`< 4` blocks) |
|---|---|---|---|
| 1 | ✅ allowed | ❌ blocked | ❌ blocked |
| 2 | ❌ blocked | ✅ allowed | ❌ blocked |
| 3 | ✅ allowed | ✅ allowed | ❌ blocked |
| 4 | ✅ allowed | ✅ allowed | ✅ allowed |

Permission `2` is **write-only**: it can write but cannot read. Therefore the gate **cannot** be modeled as a single `min_permission` integer comparison — `2` is simultaneously "enough to write" and "not enough to read." The gate must model a **capability** (`read` / `write` / `delete`), each mapping to the exact comparison already used:

- `read`  → deny if `apk_permission == 2`
- `write` → deny if `apk_permission < 2`
- `delete`→ deny if `apk_permission < 4`

This is the single most important thing to get right, and the reason a naive "min permission level" refactor would silently change who can read.

---

## 3. Design principle: behavior preservation via router-supplied defaults

The refactor introduces one component, `ApiAuthGate`, that enforces a small authorization contract. **The contract is supplied by the router as a default that equals the constant it hardcodes today.** Because the defaults reproduce the current hardcoded values exactly, **no existing descriptor needs to change** (39 action/form `_logic_api()` functions and 7 management `_handler_api()` functions stay byte-for-byte identical).

The descriptor's optional `auth` block lets a *future* endpoint override a default (e.g. a read-only action, or a management endpoint at a different permission floor). Existing endpoints declare nothing new and inherit the router default — which is, by construction, their current behavior.

This is what makes the change provably equivalent: we are **moving constants into a default and naming them**, not changing the comparisons.

---

## 4. Component: `ApiAuthGate`

New file: `includes/ApiAuthGate.php`.

```php
class ApiAuthGate {

    // Named so the two axes are never again bare literals.
    const CAP_READ   = 'read';    // deny if apk_permission == 2
    const CAP_WRITE  = 'write';   // deny if apk_permission < 2
    const CAP_DELETE = 'delete';  // deny if apk_permission < 4

    /**
     * Enforce an authorization contract, or exit via api_error() (403).
     *
     * @param array  $auth    Merged contract. Recognized keys:
     *   'capability'           => 'read'|'write'|'delete'|null  (null = no apk_permission check)
     *   'requires_machine_key' => bool   (default false)
     *   'min_user_permission'  => int    (default 0; usr_permission floor)
     * @param ApiKey $api_entry           The authenticated key.
     * @param int    $user_permission     The owning user's usr_permission.
     * @param string $message_prefix      Surface label for the 403 body (preserves wording).
     */
    public static function enforce(array $auth, $api_entry, $user_permission, $message_prefix = 'Endpoint') {
        if (!empty($auth['requires_machine_key'])
            && $api_entry->get('apk_type') !== ApiKey::TYPE_MACHINE) {
            api_error($message_prefix . ' requires a machine key', 'AuthenticationError', 403);
        }

        $cap = $auth['capability'] ?? null;
        if ($cap !== null) {
            $perm = (int) $api_entry->get('apk_permission');
            $blocked =
                ($cap === self::CAP_READ   && $perm == 2) ||
                ($cap === self::CAP_WRITE  && $perm < 2)  ||
                ($cap === self::CAP_DELETE && $perm < 4);
            if ($blocked) {
                api_error($message_prefix . ': insufficient API key permission', 'AuthenticationError', 403);
            }
        }

        if ((int) $user_permission < (int) ($auth['min_user_permission'] ?? 0)) {
            api_error($message_prefix . ': insufficient user permission', 'AuthenticationError', 403);
        }
    }
}
```

Notes:
- The gate is **authorization only** (the 403 decisions). Session simulation (`set_api_user`) and `requires_session` pre-auth dispatch are unchanged and stay in the action/form endpoints — see §6.
- `$user_permission` is passed as the raw int. `auth_data['current_user_permission']` already exists at `apiv1.php:411`; action/form routers currently receive `$api_user` and can pass `$api_user->get('usr_permission')`.
- `$message_prefix` exists solely to keep 403 bodies close to today's wording. No test asserts on wording (verified), so exact text is not load-bearing — but keeping it close avoids surprising any client.

---

## 5. Equivalence table (the troubleshooting reference)

This is the contract each call site must produce. If a future change breaks a permission boundary, compare against this table first.

| Endpoint | Today's check (file:line) | New contract passed to `ApiAuthGate::enforce` |
|---|---|---|
| `POST /api/v1/action/*` | `apk_permission < 2` (`ApiActionEndpoint.php:81`) | `capability: write` |
| `GET /api/v1/form/*` | `apk_permission == 2` (`ApiFormEndpoint.php:79`) | `capability: read` |
| `/api/v1/management/*` | `apk_type != machine` + `usr_permission < 10` (`ManagementApiRouter.php:52,57`) | `requires_machine_key: true, min_user_permission: 10` (no capability — apk_permission is intentionally **not** checked for management today) |
| `GET /api/v1/{Class}/{id}` | `apk_permission == 2` (`apiv1.php:440`) | `capability: read` |
| `GET /api/v1/{Class}s` | `apk_permission == 2` (`apiv1.php:557`) | `capability: read` |
| `POST /api/v1/{Class}` | `apk_permission < 2` (`apiv1.php:497`) | `capability: write` |
| `PUT /api/v1/{Class}/{id}` | `apk_permission < 2` (`apiv1.php:465`) | `capability: write` |
| `DELETE /api/v1/{Class}/{id}` | `apk_permission < 4` (`apiv1.php:529`) | `capability: delete` |

Per-record `authenticate_read()/authenticate_write()` (the row-ownership layer) is **unchanged** and still runs after the verb gate, exactly as today.

**Sessionless dispatch is unchanged.** Actions/forms with `requires_session => false` (e.g. `register`, `password_reset_1/2`) are still dispatched in `dispatchPreAuth()` **before** key authentication and never reach `ApiAuthGate` — there is no key to gate. This preserves first-launch flows for clients with no credentials.

---

## 6. Implementation steps

Each step is a mechanical substitution of an inline check for a gate call with the contract from §5. No control flow changes.

1. **Add** `includes/ApiAuthGate.php` (§4). `chmod 666`.

2. **`ApiActionEndpoint::dispatchAuthenticated`** — replace the inline `if ($api_entry->get('apk_permission') < 2) { api_error(...) }` (line 81–83) with:
   ```php
   $auth = ($meta['auth'] ?? []) + ['capability' => ApiAuthGate::CAP_WRITE];
   ApiAuthGate::enforce($auth, $api_entry, $api_user->get('usr_permission'), 'Action');
   ```
   The subsequent `requires_session` / `set_api_user` logic is untouched. Continue reading `$meta['requires_session']` from the top level (unchanged) so no descriptor edits are needed; additionally honor `$meta['auth']['requires_session']` if present (forward-looking, see §7).

3. **`ApiFormEndpoint::dispatchAuthenticated`** — replace the inline `if ($api_entry->get('apk_permission') == 2) { ... }` (line 79–81) with:
   ```php
   $auth = ($meta['auth'] ?? []) + ['capability' => ApiAuthGate::CAP_READ];
   ApiAuthGate::enforce($auth, $api_entry, $api_user->get('usr_permission'), 'Form');
   ```
   Session simulation below it is untouched.

4. **`ManagementApiRouter::dispatch`** — replace the two inline gates (lines 52–59) with:
   ```php
   $meta_defaults = ['requires_machine_key' => true, 'min_user_permission' => 10];
   // $meta isn't resolved until after path validation today; either resolve the
   // handler's _api() meta first, or apply the router default here and let a
   // handler's ['auth'] block override once meta is available.
   ApiAuthGate::enforce(
       ($endpoint_meta['auth'] ?? []) + $meta_defaults,
       $api_entry,
       $auth_data['current_user_permission'] ?? 0,
       'Management API'
   );
   ```
   **Sequencing caveat:** today both management gates fire *before* the endpoint path is resolved (fail-closed on the whole namespace). To keep a non-existent management path returning 403 (not 404) for a session key, apply the **router default** gate up front (machine-key + superadmin) exactly where it is now, and treat any per-handler `auth` override as an additional, narrower check after resolution. The default path must not regress: an unknown `/api/v1/management/nope` with a session key still 403s before 404 resolution. This is the one place where order matters; preserve it.

5. **CRUD verbs in `apiv1.php`** — replace the five inline comparisons (lines 440, 465, 497, 529, 557) with `ApiAuthGate::enforce` calls carrying the §5 capability. These already have `$auth_data` in scope. This step is **optional / can be a second commit** — it is pure consolidation and carries marginally more risk because it is on the hottest path. Recommended but separable.

6. **Run** `php -l` and `validate_php_file.php` on every touched file.

7. **Run** `tests/functional/api/session_keys_test.php` against dev — it is the behavior-preservation oracle (§8).

### Files touched
- `includes/ApiAuthGate.php` (new)
- `includes/ApiActionEndpoint.php`
- `includes/ApiFormEndpoint.php`
- `includes/ManagementApiRouter.php`
- `api/apiv1.php` (step 5, optional second commit)
- `docs/api.md` (§9)

### Files NOT touched (the proof of low risk)
- 0 of 39 `*_logic_api()` descriptors
- 0 of 7 `*_handler_api()` descriptors
- The authentication chain, `ApiKey` model, lifecycle, rate limiting, per-record `authenticate_read/write`.

---

## 7. Forward-looking descriptor schema (additive, optional)

After this lands, a **new** endpoint may declare its full authorization contract in its descriptor, instead of relying on the router default. This is the developer-facing payoff: auth is declared where the endpoint is defined.

```php
// A read-only action (overrides the action router's default 'write'):
function catalog_logic_api() {
    return [
        'description' => 'List blockable categories',
        'auth' => [
            'capability'       => 'read',
            'requires_session' => true,
        ],
    ];
}

// A management endpoint that should also be reachable by permission-7 ops staff
// (overrides only the user floor; still machine-key only):
function health_handler_api() {
    return [
        'method'      => 'GET',
        'description' => 'Liveness probe',
        'auth'        => ['min_user_permission' => 7],
    ];
}
```

Resolution order for each field: `$meta['auth'][field]` (explicit) → router default (the current hardcoded value) → `ApiAuthGate` built-in default. Existing descriptors omit `auth` entirely and therefore land on the router default = today's behavior.

`requires_session` is read from `$meta['auth']['requires_session'] ?? $meta['requires_session'] ?? true`, so existing top-level declarations keep working and new endpoints may consolidate it under `auth`.

---

## 8. Test plan

`tests/functional/api/session_keys_test.php` already asserts the exact boundaries this refactor must preserve; it is the regression oracle. Key assertions that must still pass unchanged:

- Superadmin **session** key gets **403** on `/api/v1/management` and `/api/v1/management/stats` (line ~330).
- Superadmin **machine** key gets **200** on `/api/v1/management` discovery (line ~333).
- Machine key on `/api/v1/auth/logout` gets **403** (line ~290) — note this gate lives in `ApiAuthEndpoint`, which is **not** changed by this refactor; listed only to confirm it stays green.
- Sessioned action (`account_edit`) via session key returns **200** (line ~260).
- Sessionless action (`password_reset_1`) dispatched with no key headers returns its normal result (line ~267).

Add to the same suite (new assertions, still behavior-pinning):
- A **read-only** machine key (`apk_permission = 1`) gets **403 on `POST /api/v1/action/*`** (write capability) and the appropriate result on `GET /api/v1/form/*` (read capability allowed).
- A **write-only** machine key (`apk_permission = 2`) gets **403 on `GET /api/v1/form/*`** (read capability) and **200-path on `POST /api/v1/action/*`** (write capability). This is the non-monotonic case from §2 and is the assertion most likely to catch a regression.

Run before and after; output must be identical (same pass count, same boundaries).

---

## 9. Documentation updates

Update `docs/api.md` (do **not** create a new doc) to reflect the end state:

- In the authorization section, name the two axes explicitly: `apk_permission` = per-key CRUD capability (read/write/delete, non-monotonic — call out that `2` is write-only), `usr_permission` = user role floor.
- Add a short "Declaring endpoint authorization" subsection documenting the descriptor `auth` block (§7) for action/form/management authors, with the resolution order.
- State that management endpoints are machine-key + superadmin by default and that a handler may widen/narrow via `auth`.

Per the docs rule, write it as the current state — no "previously the gate was inline" narrative. The reason for the change lives here in the spec and in git history.

---

## 10. Non-goals / explicitly out of scope

- **CRUD default-deny** (flipping `SystemBase::authenticate_read/write` from no-op to owner-or-admin). That is a real and recommended change but it is a **behavior change**, not behavior-preserving, so it does not belong in this refactor. Track separately.
- Changing `apk_permission` semantics, renaming the column, or making the scale monotonic.
- Touching authentication, hashing, key lifecycle, rate limiting, or webhook (Stripe / inbound-email) auth.
- Session-key permission flexibility (they remain hardcoded to `apk_permission = 4`).

---

## 11. Rollback

The change is additive (one new class) plus in-place gate substitutions. Rollback is reverting the touched files; no schema, no data, no descriptor changes to unwind. If split into two commits (routers, then CRUD verbs per step 5), each is independently revertible.
