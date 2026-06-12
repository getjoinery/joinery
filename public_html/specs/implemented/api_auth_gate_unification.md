# API Auth Consolidation (Authorization Gate + ApiAuth)

**Status:** Implemented 2026-06-12
**Created:** 2026-06-12
**Scope:** Core REST API authorization **and** authentication. Landed in stages: (1) one authorization gate behind a declared contract; (2) the action and form endpoints merged into one class; (3) the authentication chain and the gate consolidated into a single `ApiAuth` class (Option 2 — see §11). No change to which endpoints exist, no change to who may call them: every step is behavior-preserving, pinned by `tests/functional/api/session_keys_test.php` (62 passing).

> **Naming note.** This file is named for stage 1 (the gate). Stages 2–3 broadened it into a full auth consolidation; the filename is kept stable because code comments reference it.

---

## 1. Motivation

The original problem (stage 1) was **authorization** — the "may this caller invoke this endpoint?" decision. It was implemented three separate times, once per surface, each with its own hardcoded thresholds and its own error wording:

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

The refactor introduces one authorization method — originally `ApiAuthGate::enforce()`, now **`ApiAuth::authorize()`** after the stage-3 consolidation (§11) — that enforces a small authorization contract. **The contract is supplied by the router as a default that equals the constant it hardcodes today.** Because the defaults reproduce the current hardcoded values exactly, **no existing descriptor needs to change** (39 action/form `_logic_api()` functions and 7 management `_handler_api()` functions stay byte-for-byte identical).

The descriptor's optional `auth` block lets a *future* endpoint override a default (e.g. a read-only action, or a management endpoint at a different permission floor). Existing endpoints declare nothing new and inherit the router default — which is, by construction, their current behavior.

This is what makes the change provably equivalent: we are **moving constants into a default and naming them**, not changing the comparisons.

---

## 4. Component: the authorization gate

This logic shipped first as `ApiAuthGate::enforce()` and now lives as **`ApiAuth::authorize()`** (`includes/ApiAuth.php`) with `ApiAuth::CAP_READ/WRITE/DELETE` — see §11 for why the standalone gate class was folded into `ApiAuth`. The body below is unchanged; only the class/method name moved.

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

6. **Collapse the action and form endpoints into one class.** `ApiActionEndpoint`
   and `ApiFormEndpoint` were near-twins: identical two-phase dispatch skeleton
   (`dispatchPreAuth` / `dispatchAuthenticated`), identical `requiresSession`
   resolution, differing only in how a path resolves (POST `_logic` vs GET
   `_logic_form`), the default capability (write vs read), and the terminal step
   (run logic and translate a `LogicResult` vs build a `FormWriterV2JSON`
   definition). They are merged into **`includes/ApiLogicEndpoint.php`** — one
   class for both HTTP faces of an action, sharing the skeleton, `requiresSession`,
   and the gate. The faces are exposed as `dispatchActionPreAuth` /
   `dispatchActionAuthenticated` / `dispatchFormPreAuth` /
   `dispatchFormAuthenticated`; the four `apiv1.php` call sites and the two
   `require_once`s are updated, and the two old files are deleted. Bodies are
   carried over verbatim, so this is behavior-preserving. `ManagementApiRouter`
   and `ApiAuthEndpoint` are NOT merged — the former resolves handlers by file
   path (not function-name convention) and the latter is password-based; folding
   either in would add glue, not remove it.

7. **Run** `php -l` and `validate_php_file.php` on every touched file.

8. **Run** `tests/functional/api/session_keys_test.php` against dev — it is the
   behavior-preservation oracle (§8) — plus a direct smoke test of the form face
   (`GET /api/v1/form/register`), which the suite does not otherwise exercise.

### Files touched (final, all three stages — see §11 for stage 3)
- `includes/ApiAuth.php` (**new** — authenticate() + authorize() + credential decisions; absorbed the stage-1 `ApiAuthGate`)
- `includes/ApiLogicEndpoint.php` (**new** — merged action + form endpoint)
- `includes/ApiActionEndpoint.php` (**deleted** — merged into ApiLogicEndpoint)
- `includes/ApiFormEndpoint.php` (**deleted** — merged into ApiLogicEndpoint)
- `includes/ApiAuthGate.php` (**created then deleted** — folded into ApiAuth in stage 3)
- `includes/ApiAuthEndpoint.php` (now a thin HTTP shell over `ApiAuth::attemptLogin()/revokeSessionKey()`)
- `includes/ManagementApiRouter.php`
- `api/apiv1.php` (inline auth chain replaced by one `ApiAuth::authenticate()` call; CRUD verbs call `ApiAuth::authorize()`)
- `docs/api.md` (§9)
- `tests/functional/api/session_keys_test.php` (§8 new assertions)

### Files NOT touched (the proof of low risk)
- 0 of 39 `*_logic_api()` descriptors
- 0 of 7 `*_handler_api()` descriptors
- The `ApiKey` model, key lifecycle, rate limiting, per-record `authenticate_read/write`. (The authentication *chain* moved verbatim into `ApiAuth::authenticate()` in stage 3 — relocated, not changed.)

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

// A destructive management endpoint that TIGHTENS the default — still
// machine-key + superadmin, and additionally requires the delete capability:
function purge_handler_api() {
    return [
        'method'      => 'POST',
        'description' => 'Purge old backups',
        'auth'        => ['capability' => 'delete'],
    ];
}
```

Resolution order for each field: `$meta['auth'][field]` (explicit) → router default (the current hardcoded value) → `ApiAuthGate` built-in default. Existing descriptors omit `auth` entirely and therefore land on the router default = today's behavior.

**Management overrides can only tighten, not loosen.** The machine-key + superadmin default is enforced up front (before the handler is resolved) so unknown paths fail closed; a handler's `auth` block runs *after* resolution and can only add restrictions. To make a management endpoint reachable by a lower user role you would have to relax the router default itself — which is intentionally not supported here. Action and form endpoints, whose defaults carry no user floor, can both tighten and widen via `auth`.

`requires_session` is read from `$meta['auth']['requires_session'] ?? $meta['requires_session'] ?? true`, so existing top-level declarations keep working and new endpoints may consolidate it under `auth`.

---

## 8. Test plan

`tests/functional/api/session_keys_test.php` already asserts the exact boundaries this refactor must preserve; it is the regression oracle. Key assertions that must still pass unchanged:

- Superadmin **session** key gets **403** on `/api/v1/management` and `/api/v1/management/stats` (line ~330).
- Superadmin **machine** key gets **200** on `/api/v1/management` discovery (line ~333).
- Machine key on `/api/v1/auth/logout` gets **403** (line ~290) — note this gate lives in `ApiAuthEndpoint`, which is **not** changed by this refactor; listed only to confirm it stays green.
- Sessioned action (`account_edit`) via session key returns **200** (line ~260).
- Sessionless action (`password_reset_1`) dispatched with no key headers returns its normal result (line ~267).

Added to the same suite (new section "Capability boundaries — non-monotonic apk_permission"), pinning the read and write boundaries with machine keys at `apk_permission` 1 and 2:
- A **read-only** key (`apk_permission = 1`): **200 on `GET /api/v1/User/{own id}`** (read allowed) and **403 on `POST /api/v1/action/account_edit`** (write capability blocks perm 1).
- A **write-only** key (`apk_permission = 2`): **403 on `GET /api/v1/User/{id}`** (read capability blocks perm 2 — the non-monotonic case from §2) and **200 on `POST /api/v1/action/account_edit`** (write capability allows perm 2).

The read/write boundaries are exercised through CRUD GET and an action POST rather than the form endpoint, so the test does not depend on a specific form companion existing. Result after implementation: **62 passed, 0 failed** (was 57; +5 new assertions), with every pre-existing boundary unchanged.

---

## 9. Documentation updates

Update `docs/api.md` (do **not** create a new doc) to reflect the end state:

- In the authorization section, name the two axes explicitly: `apk_permission` = per-key CRUD capability (read/write/delete, non-monotonic — call out that `2` is write-only), `usr_permission` = user role floor.
- Add a short "Declaring endpoint authorization" subsection documenting the descriptor `auth` block (§7) for action/form/management authors, with the resolution order.
- State that management endpoints are machine-key + superadmin by default and that a handler may **tighten** (not loosen) that default via `auth`.

Per the docs rule, write it as the current state — no "previously the gate was inline" narrative. The reason for the change lives here in the spec and in git history.

---

## 10. Non-goals / explicitly out of scope

- **CRUD default-deny** (flipping `SystemBase::authenticate_read/write` from no-op to owner-or-admin). That is a real and recommended change but it is a **behavior change**, not behavior-preserving, so it does not belong in this refactor. Track separately.
- Changing `apk_permission` semantics, renaming the column, or making the scale monotonic.
- Changing **how** authentication works — hashing, key lifecycle, rate limiting, or webhook (Stripe / inbound-email) auth. (Stage 3 *relocated* the authentication chain into `ApiAuth::authenticate()` verbatim; it did not change its behavior.)
- Session-key permission flexibility (they remain hardcoded to `apk_permission = 4`).

---

## 11. Stage 3 — the `ApiAuth` consolidation (Option 2)

Stages 1–2 left the auth domain split across three places: the gate (`ApiAuthGate`), the credential endpoints (`ApiAuthEndpoint`), and — the real smell — **authentication itself, which was ~130 lines of inline procedural code in `apiv1.php`**, not a class at all. Stage 3 unifies the security boundary into one class, `ApiAuth`, under the **Option 2** decision (authN + authZ unified as decision logic; HTTP endpoints stay as thin shells; dispatch classes stay separate). The alternatives considered were Full (also fold the credential *endpoints* into `ApiAuth`) and Stop (leave authentication inline); Option 2 was chosen because it unifies the two same-abstraction-level concerns (authenticate/authorize) without mixing HTTP request/response handling into the auth class.

**`ApiAuth` (`includes/ApiAuth.php`) owns:**
- `authenticate(array $headers, $source_ip): array` — the full chain lifted verbatim from `apiv1.php` (key lookup → status/expiry/IP checks → secret verify → user load), returning `['api_entry','api_user','auth_data']` or exiting 4xx. The ten repeated failure blocks collapse into one private `auth_failure()` helper. Also stamps the key type and records usage on success. The front controller is now just `$principal = ApiAuth::authenticate($headers, $source_ip);`.
- `authorize(...)` — the former `ApiAuthGate::enforce()`, byte-for-byte (with `CAP_READ/WRITE/DELETE`). `ApiAuthGate.php` is deleted; all callers (CRUD verbs in `apiv1.php`, `ApiLogicEndpoint`, `ManagementApiRouter`) call `ApiAuth::authorize()`.
- `attemptLogin($email,$password,$device_label)` and `revokeSessionKey($api_entry)` — the credential *decisions* that `ApiAuthEndpoint` now delegates to.

**`ApiAuthEndpoint` becomes a thin HTTP shell:** it keeps method checks, request parsing, request logging, and response shaping (`user_summary`), but delegates verify-and-mint (login) and revoke (logout) into `ApiAuth`. Layering points cleanly transport → domain.

**What is deliberately NOT in `ApiAuth`:** the dispatch classes (`ApiLogicEndpoint`, `ManagementApiRouter`) and the credential *endpoints'* HTTP plumbing. Folding those in would mix routing/transport with auth decisions — a god-class, which Option 2 explicitly rejects.

**Behavior preservation:** `authenticate()` is verbatim, so every failure response is identical (verified by direct curls: no-headers → `Public/secret keys not present`; bogus key → `Unable to find the api key`). `session_keys_test.php` stays **62 passed, 0 failed** — it exercises authentication (missing/expired/revoked keys), the login/logout delegation, and the capability boundaries. The form face was re-smoke-tested (`GET /api/v1/form/register` → definition; `POST` → 405).

**Net result across all stages:** the auth domain went from "authorization decided in 4 places + authentication inline + gate + endpoint" to **one `ApiAuth` class** for the security boundary, plus separate single-purpose dispatch (`ApiLogicEndpoint`, `ManagementApiRouter`) and a thin `ApiAuthEndpoint` shell.

---

## 12. Rollback

The change is additive (`ApiAuth.php`, `ApiLogicEndpoint.php`) plus in-place substitutions and two deletions. Rollback is reverting the touched files and restoring the deleted ones; no schema, no data, no descriptor changes to unwind. The three stages are independently revertible in reverse order (ApiAuth consolidation → endpoint merge → gate).
