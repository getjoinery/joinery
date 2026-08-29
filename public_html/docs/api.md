# REST API Documentation

## Overview

The Joinery platform provides a REST API for programmatic access to data and operations.

- **Base URL:** `https://{site-domain}/api/v1/`
- **Format:** JSON (all requests and responses)
- **Methods:** GET (read), POST (create), PUT (update), DELETE (soft delete)
- **HTTPS Required:** All requests must use HTTPS

## Architecture

Every API request flows through the front controller (`api/apiv1.php`), which runs transport preconditions (HTTPS, CORS, rate limits, client-version handshake), establishes the caller's identity once, then routes to a handler:

```
api/apiv1.php
  ├─ transport preconditions (HTTPS, CORS, rate limits, version handshake)
  ├─ $principal = ApiAuth::authenticate($headers, $source_ip)   → key + user, or 4xx
  └─ route by URL:
       /{Class}, /{Class}s   → CRUD      → ApiAuth::authorize([capability]) → model authenticate_read/write
       /action/*, /form/*    → ApiLogicEndpoint → ApiAuth::authorize([capability]) → run logic / build form
       /management/*         → ManagementApiRouter → ApiAuth::authorize([machine + superadmin])
       /auth/*               → ApiAuthEndpoint (thin shell) → ApiAuth::attemptLogin / revokeSessionKey
       /app/*                → ApiAppEndpoint → app navigation routing table (session keys only)
```

The whole security boundary lives in **one class, `ApiAuth`** (`includes/ApiAuth.php`):

| Method | Responsibility |
|--------|----------------|
| `authenticate($headers, $source_ip)` | Resolve + validate the API key, load its user, return the principal (or exit 4xx). The single authentication path for every request. |
| `authorize($contract, $api_entry, $user_permission, $label)` | The one authorization decision point. `$contract` is a small array — `capability` (`read`/`write`/`delete`), optional `requires_machine_key`, `min_user_permission`. Called by the CRUD verbs, the logic endpoint, and the management router. |
| `attemptLogin()` / `revokeSessionKey()` | Credential-lifecycle decisions that the thin `ApiAuthEndpoint` (the `/auth/*` HTTP shell) delegates to. |

The handler classes around it are **dispatch, not auth**: `ApiLogicEndpoint` runs an action's two faces (POST execute, GET form definition), and `ManagementApiRouter` resolves management-node handler files. They *consume* the principal and *call* `authorize()` — they don't make auth decisions themselves. See [Two authorization axes](#two-authorization-axes) for the `apk_permission`/`usr_permission` distinction and the declarative `auth` block.

## Contract

This section pins what clients — including store-shipped app binaries — may rely on. Everything here is frozen for `/api/v1`; a change to any of it goes through the client-version handshake (426 `UpgradeRequired`), never silently.

### Response envelope

Every response is a JSON object carrying `api_version: "1.0"` (the only exception is `management/backups/fetch`, which streams binary). Success and error envelopes are distinguished by their keys:

**Success:**

| Key | Type | Present |
|-----|------|---------|
| `api_version` | string `"1.0"` | always |
| `success_message` | string (may be `""`) | always |
| `data` | object, or array for collection reads | always |
| `redirect` | string path | actions only, when the web flow would have redirected |
| `num_results`, `page`, `numperpage` | integer | collection reads only |

**Error (HTTP status ≥ 400):**

| Key | Type | Present |
|-----|------|---------|
| `api_version` | string `"1.0"` | always |
| `errortype` | string from the vocabulary below | always |
| `error` | string — the human-readable message itself, no prefix or decoration | always |
| `data` | object (usually empty; may carry action-specific detail) | always |
| `validation_errors` | object: field name → message | `ValidationError` only |

The `errortype` vocabulary is closed: `AuthenticationError`, `TransactionError`, `ActionError`, `ValidationError`, `SecurityError`, `UpgradeRequired`, `RateLimitError`, `NotFound`. Clients branch on `errortype` plus HTTP status (tables under [Error Handling](#error-handling)); the `error` string is for display and logs, never for matching.

### Pagination

Collection reads accept `page` (0-based), `numperpage`, `sort` (column name), `sdirection` (`ASC`/`DESC`) as query parameters and respond with integer `num_results` (total rows matching the query, after owner-scoping), `page`, and `numperpage` alongside the `data` array. There is no cursor form; `num_results` with `page * numperpage` is the whole model.

**`numperpage` defaults to 3.** A page that lists a whole small collection must pass it explicitly — relying on the default silently truncates the list at three rows, and the caller cannot tell a short page from a complete one without reading `num_results`.

`sort` must name a declared column of the model being read; anything else is a `400`. A sort column is an identifier, which SQL cannot bind as a parameter, so it is validated against `$field_specifications` rather than escaped. The bare column name works too — the table prefix is inferred (`sort=user_id` and `sort=usr_user_id` are equivalent on `Users`). Sorting by an expression is not supported.

### Timestamps

Timestamps are strings in **UTC**, formatted `YYYY-MM-DD HH:MM:SS` — space separator, seconds precision, no fractional seconds, no timezone suffix. Clients parse them as UTC. An unset timestamp is `null`, never `""` or a zero date. Producing code uses `LibraryFunctions::api_timestamp()` (payloads assembled by hand) or inherits the format from `export_for_api()` (CRUD reads).

### Naming and types

- All payload keys are `snake_case`.
- CRUD `data` keys are the model's column names (prefixed, e.g. `usr_first_name`) plus the model's declared `$api_derived_fields`; action `data` keys are friendly names documented per action.
- Booleans are JSON `true`/`false` and counts are JSON numbers — not `"1"`/`"0"` strings. Payload-building code casts at the boundary.
- Strings are plain text, not HTML. (A few legacy page-oriented action payloads still carry HTML fragments — see the caveat below — but no new payload may.)

### Idempotent writes

A mutating action request may carry an `Idempotency-Key` header (hyphen form, like the client headers) — any string unique per logical operation; a UUIDv4 is the obvious choice. The server guarantees the action **executes at most once per key**:

- The first request with a key executes normally; its response (status + body) is stored for 24 hours.
- A retry with the same key and same body receives the stored response verbatim, without re-executing — safe to fire blindly after a timeout or lost response.
- The same key with a different body or different action is a client bug and gets **409** `ActionError`, with `data.reason` set to `idempotency_key_reused`; so does a retry that arrives while the original is still executing. The key check answers before boundary validation, so the 409 wins even when the mismatched body would also fail validation. Read the marker rather than the message: this refusal cannot improve on a retry, so a client that keeps trying will keep failing, and the only ways out are to send the body the key was spent on or to re-issue the operation under a fresh key.
- No header → no idempotency behavior; the request is processed exactly as always.

Keys are scoped per credential (API key, or user for browser sessions), so key strings never collide across callers, and both credential types behave identically. Sessionless actions (`register`, password resets) have no credential to scope to and ignore the header. The replayed body is a snapshot of the original outcome — a retried `cart` add returns the cart as it looked at execution time. Client convention: attach the header in the networking layer for every mutating action call, generating a fresh key per logical operation and reusing it only for retries of that operation. A request body has to be reproducible byte for byte to be retried under its key, which is worth checking when any part of it is encrypted: sealing draws a fresh nonce each time, so a body built afresh per attempt is a different body. Build it once and keep it with the operation.

Stored outcomes live in `aik_api_idempotency_keys` (the raw key is never stored, only its SHA-256) and are purged past the 24-hour window by the **Purge Idempotency Keys** scheduled task.

**Responses built from protected content are stored encrypted.** When the request that produced the response opened Sealed Vault content — reading mail on a protected domain, a protected chat turn — the cached body is sealed to that owner (`docs/sealed_vault.md` § The hot-turn rule) rather than cached in the clear. Three consequences for a client:

- A retry made while the owner's vault is unlocked replays the body exactly as always. Nothing changes.
- A retry made while it is locked, or after a request that involved more than one owner's protected content, gets **409** `ActionError` with *the original response for this Idempotency-Key is not retained*. The action was **not** repeated — duplicate suppression is intact. Retry while the owner's vault is unlocked, re-issue the operation under a fresh key, or query the resource for the outcome.
- Retention is unchanged: sealed rows expire on the same `idempotency_key_retention_hours` window as any other.

### Locked state (sealed mailboxes)

Mailbox actions over a protected (Private/Fortress) domain follow the
locked-state contract (`plugins/mailbox/docs/overview.md` § Security levels):
they return **cleartext metadata plus a `locked` flag**, never an error, so a
client renders sealed placeholders and triggers the native unlock ceremony
rather than a failure state.

- `mailbox/thread_list` and `mailbox/thread` include `locked: true` when any row
  in the payload is sealed-and-unreadable (a locked window or a Fortress
  pending-parse row). Threading, unread, labels, folders, times, and sizes stay
  populated; sender/subject/body render a neutral placeholder.
- `mailbox/mailboxes` carries each mailbox's `security_level` and current
  `locked` state for the switcher.
- `mailbox/send` returns `locked: true` instead of sending when a Fortress
  compose has no open window — run the unlock ceremony, then resend.
- `mailbox/thread_action` (mark/star/delete) operates on cleartext metadata and
  keeps working while locked.

The CRUD surface follows the same contract. A single-object GET whose sealed
content nobody present can open answers **423 `VaultLocked`** — the record
exists and the caller may read it, so "unlock and retry" is actionable. A
**collection GET** includes such a row rather than omitting it: its sealed
fields are null, its plain columns populated, and it carries
`content_locked: true` so the client renders its locked placeholder.

The unlock ceremony is the platform passkey `vault-kek` derivation over
`/api/v1` (`vault_unlock_options` → `vault_unlock_passkey`), opening the same
server-side window. **`vault_heartbeat`** keeps that window alive: the site-wide
presence beacon (`assets/js/vault-presence.js`, included on every page for
signed-in users) posts it on a ~25s interval while a window is open, so the
window survives navigation anywhere on Joinery; it returns `{"alive": false}`
once no window is open (the client then stops). The window also ends on explicit
`vault_lock`, session end, network-identity change, and the per-level
idle/absolute caps.

### What is *not* contract

An action's `data` is contract only where its keys are documented (in this file or the owning plugin's docs). Several actions serve web pages first and return their page variables — live PHP objects that JSON-serialize as `{"key": N}` husks. Those husks, and any undocumented key, carry no compatibility promise; each action's payload becomes contract when it is documented as an API surface. The management namespace (`/api/v1/management/*`) is an internal management node consumed only by server_manager and is versioned with it, not with app clients.

## Authentication

Key-authenticated requests use the same two custom headers:

```
public_key: {your_public_key}
secret_key: {your_secret_key}
```

There is one auth story with **three credentials**: two key types, distinguished by `apk_type` in the shared `apk_api_keys` table, plus the browser session for page JavaScript. Key headers always take precedence — the browser-session path only runs when a request carries no key headers.

| | Machine keys (`machine`) | Session keys (`session`) | Browser session |
|---|---|---|---|
| Sent as | Key headers | Key headers | Web session cookie + `X-Joinery-Csrf` header |
| Provisioned by | An administrator via **Admin > API Keys** | The user, via `POST /api/v1/auth/login` | Logging in on the website; the page emits the CSRF token as a `<meta name="joinery-api-csrf">` tag |
| Identity | The user account the admin attached | The user who logged in | The web session's user, exactly as pages see it (login-as included) |
| Secret hashing | Slow hash (phpass) — appropriate for admin-chosen secrets | SHA-256 — the secret is a random 256-bit value, so a slow KDF buys nothing and would cost on every request | n/a — the token is compared constant-time against the session's stored value |
| Permission | Chosen by the admin (1–4) | Always 4; per-record authorization (owner-or-staff by default — see [Per-record authorization](#per-record-authorization)) is the effective gate | Same as a session key: full capability (4); the user's role and per-record authorization are the effective gates |
| Expiry | Optional, set by the admin | Always set, from the `api_session_key_lifetime_days` setting (default 365) | The web session's lifetime |
| Revocation | Admin API Keys page | `auth/logout`, the profile **App Sessions** page, the admin API Keys page (type filter), and automatically on password change | Website `/logout` (the API `auth/logout` refuses browser sessions) |
| Management API | Allowed (with superadmin owner) | **Never** | **Never** |

There is a fourth credential that is not a key at all and does not appear in that table: the **agent channel**. `/api/v1/agent/*` is how a managed node's Go agent takes work from its management node, and it authenticates with an Ed25519 signature over a canonical message rather than a shared secret — the plane stores only the node's public key, so it holds nothing that could act as the node. It is dispatched before key authentication, has its own rate-limit bucket (`api_agent_rate_limit_requests`), and exists only where the `server_manager` plugin is active. See [Server Manager § The agent channel](../plugins/server_manager/docs/overview.md#the-agent-channel).

A password change revokes **all** of the user's session keys (the lost-phone path); machine keys owned by the same user are untouched. Session keys are not IP-restricted — devices roam networks by design; the App Sessions view's device label and last-used time are the compensating visibility.

### Browser sessions (page JavaScript)

Page JavaScript calls `/api/v1` with the web session it already has — no key provisioning. The session's CSRF token reaches the page two ways: a `<meta name="joinery-api-csrf">` tag on signed-in pages, and the `joinery_api_csrf` mirror cookie on every response (signed-in or not). The cookie exists because anonymous pages can be served from the static page cache — shared HTML that must never embed a per-visitor token — while cookies are per-visitor regardless of how the HTML was produced. Validation always compares the header against the raw session value; the cookie is distribution, never the trust anchor.

Page JS never hand-rolls this transport. `assets/js/joinery-api.js` is loaded on every page (`PublicPageBase::global_includes_top()`) and exposes the single implementation:

```js
// Resolves with the success envelope's `data`; rejects with an Error carrying
// .status and .errorType on an error envelope or network failure. Logic-level
// soft failures the action returns inside `data` (e.g. {ok: false}) resolve.
const data = await joineryApi.post('contact_preferences', { ... });

// Accepts '{plugin}/{action}' names and full endpoint URLs alike:
await joineryApi.post('store/checkout_apply_coupon', { code: code });

// joineryApi.csrf() exposes the token read (cookie first — it tracks the
// CURRENT session, resynced on every response; the render-frozen meta tag is
// the fallback) for the rare non-JSON call, e.g. a multipart upload:
fetch(url, { method: 'POST', headers: { 'X-Joinery-Csrf': joineryApi.csrf() }, body: formData });
```

The session cookie proves who the visitor is; the header proves the call came from our own page (an attacker's page can read neither the meta tag nor the cookie). Missing or wrong token → 403 even with a valid cookie. The token is session-wide and distinct from FormWriter's per-form CSRF tokens.

**Stale-token recovery.** A page left open past server-side session expiry holds a token the server no longer knows, so the first call from it draws the 403. That failing request itself starts a fresh session — re-syncing the mirror cookie and restoring sign-in from the remember-me cookie — so on a 403 `AuthenticationError` the transport re-reads the cookie and, if the value changed, retries the request once with the new token. The idle-then-resume case heals invisibly; a genuine denial leaves the cookie unchanged and is surfaced without a retry. A caller that still receives 401 after this (session truly gone, no remember-me) should send the user to `/login`.

**Guests (the anonymous principal).** A session with a valid CSRF proof but no signed-in user authenticates as the *anonymous principal*: same-origin page JS, no identity. It is denied 401 everywhere except actions whose descriptor declares `allow_guest` (see [Metadata contract](#metadata-contract)) — every other route family (CRUD, forms, auth, app, management) refuses it outright. This is how session-state pages like guest checkout call the API: the logic runs without session simulation and sees the visitor's natural session, where state like the cart lives. Guest requests have no idempotency scope, so an `Idempotency-Key` header is ignored for them.

Mechanics worth knowing: the API *reads* the session and releases the session lock as soon as identity is resolved, so parallel page JS calls do not serialize; an action that must persist `$_SESSION` writes (the cart) declares `session_write` in its `auth` block, which re-opens the session for that action only. Browser-session requests carry no `client_app` headers, so client version minimums never apply to them; requests without any credential fail exactly as key-less requests always have.

**Forward rule:** new features expose their logic as API actions (`_logic_descriptor()` opt-in) and page JavaScript calls `/api/v1` with this credential. `/ajax/` is legacy — no new endpoints there; existing ones migrate opportunistically when touched.

**Provider webhooks are the one standing exception to the forward rule.** The rule exists because `/api/v1` authenticates with a session or API-key credential — and payment-provider webhooks can't carry one: they are machine-to-machine calls authenticated by payload signature (Stripe signature header, PayPal verification API, Apple signed JWS, Google OIDC bearer), with no session or CSRF. Provider webhooks therefore live in `plugins/store/ajax/` — `stripe_webhook.php`, `paypal_subscription_webhook.php`, `app_store_webhook.php`, `play_rtdn_webhook.php` — each verifying its signature before touching any state and using `WebhookLog` for idempotency.

### Key Properties

| Property | Description |
|----------|-------------|
| `public_key` | Public identifier sent in requests |
| `secret_key` | Secret, verified against a stored hash (scheme per key type, above) |
| `type` | `machine` or `session` |
| `is_active` | Key must be active to authenticate |
| `start_time` | If set, key is rejected before this time (UTC) |
| `expires_time` | If set, key is rejected after this time (UTC) |
| `last_used_time` | Updated by the auth path at most once per hour |
| `ip_restriction` | Comma-separated list of allowed IPs (optional, machine keys) |
| `permission` | Access level (see Permission Levels below) |

## Auth Endpoints

Session-key provisioning and lifecycle live under `/api/v1/auth/*`. HTTPS enforcement and both rate limiters apply to all of them.

### `POST /api/v1/auth/login` — unauthenticated

The one place passwords transit the API. Body (JSON or form):

| Field | Required | Description |
|-------|----------|-------------|
| `email` | Yes | Account email |
| `password` | Yes | Account password |
| `device_label` | No | Stored as the key name, shown in session lists (e.g. "Jeremy's iPhone") |

Success returns the key pair — **the only time the secret plaintext is ever returned** — plus expiry and the same user/tier summary `auth/session` returns:

```json
{
    "api_version": "1.0",
    "success_message": "Login successful",
    "data": {
        "public_key": "sess_…",
        "secret_key": "…64 hex chars…",
        "expires_time": "2027-06-11 17:00:00",
        "user": { "user_id": 5, "display_name": "…", "email": "…",
                  "permission": 0, "tier": { "name": "…", "tier_level": 2, "features": {} } }
    }
}
```

Store the two strings and send them as the standard key headers on every subsequent request. Failed logins return 401 `AuthenticationError` (identical response for unknown email, deleted user, and wrong password) and count toward the failed-auth rate limit.

### `GET /api/v1/auth/session` — any credential

Returns the user summary: user id, name, email, permission, subscription tier, and tier feature flags. The "who am I / what may I do" call on app launch.

### `POST /api/v1/auth/logout` — session-key-authenticated

Revokes the presented key (soft delete) and nothing else. Machine keys get 403 here — they are revoked from the admin page, not by themselves. Browser sessions also get 403 — they sign out on the website (`/logout`), which ends the web session and with it the API access.

### `POST /api/v1/auth/web_session` — session-key-authenticated

The web-session bridge for native app webviews: derives a web session from the presented session key so the app never shows a web login page. Body: `{"target": "/profile/calendar"}` — a same-origin relative path (default `/`). Returns a single-use bridge URL with a 60-second TTL:

```json
{ "data": { "bridge_url": "/app_bridge?token=…", "expires_in": 60 } }
```

The webview loads `bridge_url`; the server validates the token, starts an **app-context** web session for the key's user, and 302s to the target. Bridged sessions render without site chrome and live only as long as the originating key — revoking the key (logout, App Sessions page, password change) ends them too. Machine keys and browser sessions get 403. Full flow: `docs/mobile_apps.md`.

### `POST /api/v1/auth/device_link` — unauthenticated

Opens a device-link ceremony: how a desktop sync client acquires a credential without ever handling the user's password. Body: `{"device_name": "Studio PC", "platform": "windows", "device_pubkey": "<base64 X25519, optional>"}` — platform is one of `macos`, `windows`, `linux`.

```json
{ "data": { "link_code": "K4RT-9WZP", "poll_token": "…", "verify_url": "https://…/profile/devices/link?code=K4RT-9WZP", "expires_time": "…", "poll_after": 3 } }
```

The client shows `link_code` and opens `verify_url`. The user approves in a browser, where they are already signed in and where a step-up can be demanded. `device_pubkey` is the target for the encrypted-folder key handoff — omit it and the device simply never receives one. Ceremonies last 10 minutes.

### `GET /api/v1/auth/device_link/{poll_token}` — unauthenticated

Collects the outcome. `{"status": "pending", "poll_after": 3}` until the user acts; `{"status": "denied"}` if refused. On approval the **first** successful poll — and only the first — returns the credential:

```json
{ "data": { "status": "approved", "public_key": "sess_…", "secret_key": "…", "device_id": 12, "expires_time": "…", "sealed_vault_key": "…" } }
```

`sealed_vault_key` is present only when the user chose to share their encrypted folders: it is the drive vault secret key sealed to `device_pubkey` in the approving browser, opaque to the server. The row is scrubbed immediately after; a second poll gets 409. An unknown token and an expired ceremony both get 404, so neither confirms the other exists.

Both halves use their own rate bucket (`api_device_link_rate_limit_requests` / `_window`, default 600 per hour per IP) — polling every three seconds for ten minutes cannot share the deliberately small failed-sign-in allowance.

## App Endpoints

`GET /api/v1/app/navigation` — session-key-authenticated. The user's profile menu as a routing table for a native app's tab bar and More list: filtered entries (permission, visibility, setting gates; shell-owned auth entries excluded), each with a version-safe `destination` (`{type: "web", url}` today; `{type: "native", screen, fallback_url}` once a surface goes native), plus the `tabs` slug list pinned for the requesting `client_app` (from the `app_navigation` setting). Machine keys and browser sessions get 403. Response shape and semantics: `docs/mobile_apps.md`.

## Client Versioning

Apps send two headers on every request:

```
client_app: scrolldaddy-ios
client_version: 1.4.2
```

The `api_min_client_versions` setting holds a JSON map of `client_app` → minimum version (semver). If the request names an app with a configured minimum and its `client_version` compares below it (or is missing), every endpoint — including `auth/login` — responds **HTTP 426** with errortype `UpgradeRequired`; the client renders this as a blocking upgrade screen with a store link. Requests without client headers (machine integrations, curl) are wholly unaffected.

## Permission Levels

| Level | Read | Create/Update | Delete | Description |
|-------|------|--------------|--------|-------------|
| 1 | Yes | No | No | Read-only |
| 2 | No | Yes | No | Write-only |
| 3 | Yes | Yes | No | Read + Write |
| 4+ | Yes | Yes | Yes | Full access |

**Note:** Permission level 2 grants write access but blocks read operations (GET requests).

**This axis is non-monotonic** — level 2 is write-only, so it is *not* a simple "higher = more" scale. Authorization is therefore expressed as a **capability** (read / write / delete), not a minimum level:

- `read` → allowed unless `apk_permission == 2`
- `write` → allowed when `apk_permission >= 2`
- `delete` → allowed when `apk_permission >= 4`

## Two authorization axes

API authorization decisions involve two distinct axes — keep them separate when reasoning about access:

| Axis | Field | Meaning |
|------|-------|---------|
| **Key capability** | `apk_permission` | What a *key* may do on the CRUD axis (read / write / delete, non-monotonic — see above). |
| **User role** | `usr_permission` | The owning *user's* role floor (e.g. `5` = staff, `10` = superadmin). This is the value passed to per-record `authenticate_read/write` as `current_user_permission`, and the floor the management plane gates on. |

Both axes live in one class, `ApiAuth` (`includes/ApiAuth.php`), which owns the whole security boundary: `ApiAuth::authenticate()` resolves the principal from request headers, and `ApiAuth::authorize()` enforces every endpoint's authorization against a small contract — a `capability`, an optional `requires_machine_key` or its inverse `requires_browser_session`, and a `min_user_permission` floor.

### Declaring endpoint authorization

Action, form, and management endpoints may declare their authorization contract in their descriptor's optional `auth` block. Each field falls back to the router's default (which equals that surface's standard requirement) when omitted, so most endpoints declare nothing:

```php
function catalog_logic_descriptor(): array {
    return [
        'description' => 'List blockable categories',
        'auth' => [
            'capability'               => 'read',  // 'read' | 'write' | 'delete' | null (no apk_permission check)
            'requires_session'         => true,    // run under session simulation as the key's user
            'requires_machine_key'     => false,   // require apk_type = machine
            'requires_browser_session' => false,   // inverse of machine_key: refuse ALL API keys, browser-session credential only
            'allow_guest'              => false,   // accept the anonymous browser principal (valid CSRF, no signed-in user)
            'session_write'            => false,   // re-open the session so the action's $_SESSION writes persist (browser credential only)
            'min_user_permission'      => 0,       // usr_permission floor
        ],
    ];
}
```

Resolution order for each field: explicit `auth` value → router default → `ApiAuth::authorize` built-in default. Surface defaults:

| Surface | Default contract |
|---------|------------------|
| Action (`POST /api/v1/action/*`) | `capability: write` |
| Form (`GET /api/v1/form/*`) | `capability: read` |
| CRUD verbs (`/api/v1/{Class}…`) | `read` for GET, `write` for POST/PUT, `delete` for DELETE |
| Management (`/api/v1/management/*`) | `requires_machine_key: true, min_user_permission: 10` (no `apk_permission` check) |

`requires_machine_key` and `requires_browser_session` are mutually exclusive opposites: the first admits only machine keys, the second refuses every API key so the action is reachable only through the browser-session credential (session cookie + CSRF; native apps ride the same bridge). Session-bound operations whose state is keyed to the session id — Sealed Vault and passkey management — set `requires_browser_session` so the boundary is declared, not left to incidental session-plumbing behavior.

`allow_guest` admits the anonymous browser principal (see [Browser sessions](#browser-sessions-page-javascript)); without it, `ApiAuth::authorize()` denies anonymous callers 401 before any other check, so contracts that never think about guests stay guest-free. Guest-reachable actions whose state lives in the web session (the cart) pair it with `requires_browser_session` — an API key has no session to act on — and with `session_write` when they mutate that state, since the browser credential otherwise releases the session lock after reading identity and later `$_SESSION` writes would not persist.

A management handler's `auth` block may **tighten** the default (e.g. raise the user floor or add a capability) but cannot loosen it — the machine-key + superadmin default is enforced before the handler resolves so unknown paths still fail closed.

## CRUD Endpoints

The CRUD surface has three independent authorization layers, each safe by default:
**resource exposure** (is this class an endpoint at all?), **row scope** (may this caller
touch this row?), and **field floors** (which columns may be read / written?). The
`apk_permission` gradient (1–4) sits above all three and decides which **HTTP verbs** a key
may use.

### Exposing a model: checklist

A new model is a CRUD resource only when you opt it in. Both API surfaces (REST and the AI
model surface) read the **same** declarations below, so you configure each fact once.

1. **Resource?** Set `$api_readable` / `$api_writable` (both default `false` → 404). Leave both
   off for credential, config, audit/log, and join tables.
2. **Row scope?** The `SystemBase` default is **owner-or-staff (deny)** and needs no code:
   - Standard `{prefix}_usr_user_id` owner column → nothing to write.
   - No owner column → automatically staff-only; nothing to write.
   - Public catalog content → set `$api_public_read = true` (you override **to open**, never to
     close).
   - Non-standard ownership (e.g. sender/recipient) → override `authenticate_read` /
     `authenticate_write` and **throw to deny**.
3. **Secret / privileged columns?** Add genuine secrets to `$api_unreadable_fields` and
   privileged-but-readable columns to `$api_unwritable_fields`. Skip anything matching
   `/_(password|secret|key|token|hash)$/i` — the regex floor catches those automatically. Both
   lists are honored by REST **and** AI.
4. **Custom `export_as_array()` injecting derived keys?** `export_for_api()` is fail-closed: it
   emits declared columns (minus the floor) plus only the keys you list in `$api_derived_fields`.
   Any computed/embed key not declared there is dropped. Embed a child model via its
   `export_for_api()` so the floor holds through the nesting.
5. **AI surface:** keep `$ai_excluded_fields` to relevance/noise trims only — never re-list
   secrets (the shared floor merges them in). `$ai_writable_fields` narrows writes under the
   unwritable floor.
6. **Doing content-visibility gating** (min-permission / group / tier on a served file)? That is
   `is_viewable($session)`, **not** `authenticate_read` — the latter now means API row ownership
   only.

The rest of this section is the reference detail behind each step. For a complete annotated
reference model that declares every property above (and the deletion/validation conventions), see
**[`docs/example_class.php`](example_class.php)** — the canonical data-model template.

### Resource exposure (opt-in)

A model is a CRUD resource only if it opts in, with two static booleans:

```php
class Foo extends SystemBase {
    public static $api_readable = true;   // exposed to GET /{Class}/{id} and GET /{Class}s
    public static $api_writable = true;   // exposed to POST / PUT / DELETE /{Class}
}
```

Both default `false` on `SystemBase`. Read and write are separate, so a model can be
read-only (`$api_readable = true; $api_writable = false;`). A class that is not exposed for a
given verb is indistinguishable from one that does not exist — the request gets a **404**.

> **Plugin models participate on the same terms as core models.** Discovery enumerates
> core `data/*_class.php` plus every **active** plugin's `plugins/{plugin}/data/*_class.php`;
> a plugin model that sets the opt-in flags is a CRUD resource like any other. Deactivating
> the plugin removes its models from the surface — requests to them 404. Behavioural
> endpoints (anything beyond CRUD) remain [Action Endpoints](#action-endpoints), namespaced
> `{plugin}/{action}`.

### Per-record authorization

Exposure decides which **classes** are endpoints; row scope decides **which rows** a caller may
touch. Before returning or mutating a row, the API calls `authenticate_read($data)` (on GET) or
`authenticate_write($data)` (on POST/PUT/DELETE), passing the acting user's identity:

```php
$data = ['current_user_id' => <acting user id>, 'current_user_permission' => <their level>];
```

**The `SystemBase` default is owner-or-staff (deny).** A caller may touch a row only if they own
it — the conventional `{prefix}_usr_user_id` column equals `current_user_id` — or they are staff
(`current_user_permission >= 5`). A model with no owner column falls to staff-only. The contract
is **throw-to-deny**: the method throws `SystemAuthenticationError` to refuse, and returns nothing
to allow. (An explicit `false` return is also treated as denial.) It composes with both read
shapes: a **single** `GET /{Class}/{id}` of an unauthorized row returns an error, while a
**collection** `GET /{Class}s` simply **skips** rows the caller isn't authorized to see.

You override `authenticate_read` in two directions. To make a resource **public** there is a
declarative flag for the common case:

```php
public static $api_public_read = true;   // catalog content: world-readable over the API
```

When `$api_public_read` is true, the read surface skips the per-record scope **and** the
collection owner-filter — the rows are the same for everyone (Events, Products, Posts, Pages).
When false (the default), reads are owner-or-staff. Audit/log/credential tables simply stay
unexposed (`$api_readable = false`).

The rare opposite is tightening to **owner-only**: a model whose rows are personal credential
material overrides `authenticate_read` to throw for everyone but the owner, staff included
(`Passkey` does this — admin surfaces manage users, not credentials). On the collection path
unauthorized rows are skipped, so any caller at any permission receives only their own rows.

**Ownership integrity.** Three rules keep the owner column itself trustworthy on writes:

- **PUT authorizes the loaded row before applying input** — you may only update a row you already
  own (the check runs against the row as stored, not as mutated by the request).
- **The owner column is unwritable** — `{prefix}_usr_user_id` is dropped from CRUD input, so
  ownership can never be reassigned through a field write.
- **POST stamps the owner server-side** — created rows are owned by the caller by construction;
  a supplied owner in the body is ignored. For non-staff callers, the **collection** query is
  owner-filtered in SQL, so `num_results` reflects only the caller's rows (no count disclosure).

### Field floors (read and write)

Row scope decides **which rows**; the field floors decide **which columns** of a row may leave the
server (read) or be set over the API (write). Both floors are single definitions **shared with the
AI model surface**, so "secret" and "privileged" mean the same thing everywhere.

**Read — the unreadable floor.** A column is never exported if **either** its name matches
`SystemBase::CREDENTIAL_FIELD_PATTERN` — `/_(password|secret|key|token|hash)$/i`, so a new
`*_password` / `*_secret` / `*_key` / `*_token` / `*_hash` column is protected the moment it is
added — **or** it is listed in the model's `$api_unreadable_fields` (the explicit list for genuine
secrets whose names don't match the pattern):

```php
// data/users_class.php
public static $api_unreadable_fields = array(
    'usr_authhash', 'usr_remember_tokens', 'usr_totp_backup_codes',
);
```

CRUD reads return `export_for_api()`, which is **fail-closed (an allowlist)** — the same paradigm as
the AI read surface, which selects only `$field_specifications` columns. A key is emitted **only** if
it is either a **declared column** that survives the unreadable floor, **or** a key on the model's
`$api_derived_fields` allowlist. Anything else an `export_as_array()` override injects — a computed /
derived key — is **dropped by construction**, so a derived secret cannot leak under a name the
credential pattern did not anticipate:

```php
// data/users_class.php — computed keys export_as_array() injects that may leave over the API
public static $api_derived_fields = array(
    'key', 'display_name', 'usr_day_since_register', 'usr_days_since_last_email',
    'contact_preferences', 'phone', 'address',
);
```

A derived key is exposed only by deliberate opt-in (default: none) — the same shape as resource
exposure. The allowlist is still subject to the unreadable floor, so a credential-named derived key
cannot be allowlisted back into the open. (Internal/admin/webhook code that needs the full row keeps
calling `export_as_array()`, which is unchanged.) An override that **embeds a child model** exports
it via the child's `export_for_api()`, so the floor holds through nested embeds, and the parent emits
the embed key only when it is on `$api_derived_fields`.

**Write — the unwritable floor.** The exact mirror. A column is never written over the API if
**either** its name matches the same credential pattern, **or** it is listed in the model's
`$api_unwritable_fields` — the explicit list for privileged, non-credential columns:

```php
// data/users_class.php — usr_permission is readable but must never be set via the API
public static $api_unwritable_fields = array(
    'usr_permission', 'usr_is_disabled', 'usr_disabled_time',
    'usr_email_is_verified', 'usr_password_recovery_disabled',
);
```

Unwritable fields are **silently dropped** from POST/PUT input (strong-params style) — the column
keeps its stored value (PUT) or model default (POST), and a full-object round-trip still works.
This is what makes a model like `User` safely writable: the dangerous column (`usr_permission`) is
blocked at the field layer, not the whole model.

The AI surface composes over the same floors: `$ai_excluded_fields` trims **reads** for
relevance/noise on top of the unreadable floor, and `$ai_writable_fields` is an allowlist that
narrows **writes** under the unwritable floor. Neither can re-expose a floored field.

### Read Single Object

```
GET /api/v1/{ClassName}/{id}
```

**Example:** `GET /api/v1/User/123`

**Response:**
```json
{
    "api_version": "1.0",
    "success_message": "User found.",
    "data": {
        "usr_user_id": 123,
        "usr_first_name": "Jane",
        "usr_last_name": "Doe",
        "usr_email": "jane@example.com"
    }
}
```

### List Objects (Collection)

```
GET /api/v1/{ClassName}s?page=0&numperpage=10&sort=field&sdirection=ASC
```

Add a trailing **s** to the class name for collections.

**Pagination Parameters:**

| Parameter | Default | Description |
|-----------|---------|-------------|
| `page` | 0 | Page number (0-based) |
| `numperpage` | 3 | Items per page — pass it explicitly to list a whole collection |
| `sort` | (none) | Declared column of the model; the prefix may be omitted. Anything else is a 400 |
| `sdirection` | ASC | Sort direction: `ASC` or `DESC` (any other value is read as `ASC`) |

Any additional query parameters are passed as filter options to the Multi class. Check the specific Multi class to see which filter keys it accepts.

**Example:** `GET /api/v1/Users?page=0&numperpage=20&sort=usr_id&sdirection=DESC`

**Response:**
```json
{
    "api_version": "1.0",
    "success_message": "",
    "num_results": 100,
    "page": 0,
    "numperpage": 20,
    "data": [ ... ]
}
```

> **⚠️ Raw CRUD writes are not recommended.** `POST`/`PUT`/`DELETE` write a model's columns
> **directly** — they bypass all business-logic validation, side effects, and workflow. Creating
> an `Order` this way produces an order with no payment, cart, or receipt; registering for an
> `Event` skips capacity and waitlist checks. **Use the corresponding action endpoint (checkout,
> event signup, registration, account edit) for anything with a workflow** — it is the supported
> write path. CRUD write is a raw escape hatch for simple records, not the front door.
>
> Credential and privileged columns (anything matching the credential pattern, or listed in a
> model's `$api_unwritable_fields` — e.g. `usr_permission`) are **silently dropped** from CRUD
> writes and can only be changed through the action that owns them. Do not rely on CRUD write to
> set them.

### Create Object

```
POST /api/v1/{ClassName}
Content-Type: application/x-www-form-urlencoded

field1=value1&field2=value2
```

Input is sanitized at the API boundary before anything is created — unwritable fields (the write
floor) and the owner column are dropped, and the owner is stamped from the session. If the model
defines a `CreateNew()` static factory it is called with that sanitized input; otherwise a new
object is created and the sanitized fields are set from the POST body.

**Response:**
```json
{
    "api_version": "1.0",
    "success_message": "New User successful.",
    "data": { ... }
}
```

### Update Object

```
PUT /api/v1/{ClassName}/{id}?field1=value1&field2=value2
```

Fields to update are passed as query string parameters. The row is authorized **as stored**
before any input is applied (you may only update a row you already own), and unwritable fields
plus the owner column are dropped from the update.

**Response:**
```json
{
    "api_version": "1.0",
    "success_message": "User update successful.",
    "data": { ... }
}
```

### Soft Delete Object

```
DELETE /api/v1/{ClassName}/{id}
```

Sets the delete timestamp on the object. Does not permanently remove data.

**Response:**
```json
{
    "api_version": "1.0",
    "success_message": "Deletion successful.",
    "data": { ... }
}
```

## Available Models

Any **core** SystemBase model class is available via the API (plugin models are not — see
[Per-record authorization](#per-record-authorization)). Class names are case-sensitive and use
PascalCase. Which **rows** a key may read or change within a model is governed by that model's
`authenticate_read`/`authenticate_write` — see [Per-record authorization](#per-record-authorization).

Common models include: `User`, `Product`, `Event`, `EventRegistrant`, `EventSession`, `Order`, `OrderItem`, `Group`, `GroupMember`, `Post`, `Page`, `Email`, `Message`, `File`, `CouponCode`, `SubscriptionTier`, `Location`, `Video`, `Comment`, `Survey`, `SurveyAnswer`, `Question`, `QuestionOption`, `MailingList`, `MailingListRegistrant`, `Passkey`.

`Passkey` (`GET /api/v1/Passkeys`) is read-only, owner-scoped: an enrolled WebAuthn
credential's label, created/last-used times, and PRF capability. Rename and
revoke are actions, not CRUD writes — see [Passkeys](passkeys.md).

## Error Handling

### Error Response Format

```json
{
    "api_version": "1.0",
    "errortype": "AuthenticationError",
    "error": "Description of what went wrong",
    "data": {}
}
```

### Error Types and HTTP Status Codes

| Status | Error Type | Meaning |
|--------|-----------|---------|
| 400 | AuthenticationError | Missing headers, invalid key, deleted user, missing login fields |
| 400 | TransactionError | Object not found, validation failure, save error, invalid object name |
| 401 | AuthenticationError | Wrong secret, bad login credentials, IP restricted, inactive/expired/revoked key |
| 403 | AuthenticationError | Insufficient permission; machine key on `auth/logout`; session key on `management/*` |
| 426 | SecurityError | HTTPS required |
| 426 | UpgradeRequired | `client_version` below the configured minimum for this `client_app` |
| 429 | RateLimitError | Rate limit exceeded |

## Rate Limiting

The API enforces two rate limits per IP address:

| Limit | Threshold | Window |
|-------|-----------|--------|
| General requests | 1,000 | Per hour |
| Failed auth attempts | 10 | Per 15 minutes |

When exceeded, the API returns HTTP 429 with a `RateLimitError`. Wait for the time window to pass before retrying.

## HTTPS Requirement

All API requests must use HTTPS. Requests over plain HTTP are rejected with HTTP 426 (Upgrade Required).

This can be disabled for development by setting `api_require_https` to `false` in the site settings.

## CORS

CORS is disabled by default. To enable it, set `api_allowed_origins` in site settings to a comma-separated list of allowed origins:

```
https://example.com,https://app.example.com
```

Preflight `OPTIONS` requests are handled automatically when CORS is configured.

The `X-Joinery-Csrf` header is deliberately absent from the CORS allow-list: the browser-session credential is same-origin by design (the token comes from a meta tag or the `joinery_api_csrf` cookie, both readable only by same-origin pages). Cross-origin callers use API keys.

## Security Headers

All API responses include:

```
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
X-XSS-Protection: 1; mode=block
Referrer-Policy: no-referrer
```

## Action Endpoints

Actions execute multi-step business logic (registration, event signup, payments, etc.) rather than raw CRUD operations. All logic functions that have been opted in via a metadata companion function are available.

### Making a Logic Function Available via API

Add a descriptor companion function to your logic file:

```php
// In logic/your_action_logic.php

function your_action_logic_descriptor(): array {
    return [
        'description'      => 'What this action does',
        'requires_session' => true,   // default: true
        'requires_setting' => 'drive_active',  // optional — feature gate
        'mutates'          => true,
        'input'            => [
            'email'   => ['type' => 'email', 'required' => true],
            'message' => ['type' => 'text',  'required' => false],
        ],
    ];
}
```

That's it — no registry file or mapping needed. The one declaration drives the
discovery endpoint (including the typed `input` schema), boundary validation,
and the AI action surface (`describe_actions` / `invoke_action`).

**Boundary validation.** When the descriptor declares an `input` schema, the
request body is coerced and validated against it (`DescriptorValidator`)
before the logic runs: a hard failure — missing required field, uncoercible
type, out-of-bounds value — returns `422` with errortype `ValidationError`
and the logic never executes, without claiming an `Idempotency-Key` (though a
key that already conflicts or replays resolves first, before validation). Coerced
values (typed, defaults applied) overlay the raw input; fields the schema
doesn't declare pass through untouched. The logic file's own validation
remains the backstop. See `includes/DescriptorValidator.php` for the type
vocabulary (`string`, `int`, `float`, `bool`, `email`, `text`, `password`,
`date`, `datetime`, `array`) and per-field options (`enum`, `min`/`max`,
`max_length`, `items`).

**Feature gating.** An action belonging to a feature the operator can switch
off declares that setting as `requires_setting`. The name is a single setting;
the action exists only while it is truthy. Enforcement happens at dispatch —
before authentication and before the logic runs — and answers `403` with
errortype `ActionError` and a message naming the setting. The discovery
endpoint omits the action entirely, the same way it omits actions belonging to
inactive plugins.

This is the API face's counterpart to `check_setting` on a `serve.php` route,
which gates the feature's pages. An action of a gated feature declares
`requires_setting`; the page route declares `check_setting`. Every `drive_*`
action declares `'requires_setting' => 'drive_active'`.

**No descriptor, no endpoint.** An action with a `{action}_logic()` function
but no `{action}_logic_descriptor()` is not exposed, and asking for it answers
with an error naming that as the fix rather than a bare `Unknown action` — the
action exists on this deployment, it just is not published.

### Action Request Format

```
POST /api/v1/action/{action_name}
Content-Type: application/json
public_key: {key}
secret_key: {key}
Idempotency-Key: {uuid}          (optional — see Contract § Idempotent writes)

{ "field": "value", ... }
```

Sessioned actions require API key write permission (level 2+) and run under session simulation as the key's user. With an `Idempotency-Key`, the action executes at most once per key — retries replay the stored response ([Contract § Idempotent writes](#idempotent-writes)).

**File uploads.** An action that needs to accept files (rather than JSON-only
fields) is posted as `multipart/form-data` instead — no `ApiLogicEndpoint`
change is required: a multipart POST leaves the raw body empty, so the
dispatcher falls back to `$_POST` for the text fields and PHP fills `$_FILES`
natively for the file parts. Two shipped actions take this transport:
`joinery_ai/chat_send` (field `attachments[]`, chat file uploads) and
`mailbox/send` (field `attachments[]`, compose attachments).

### Plugin Actions

Plugin actions are addressed as `{plugin}/{action}`, where `{plugin}` is the plugin directory name:

```
POST /api/v1/action/dns_filtering/device_edit
GET  /api/v1/form/dns_filtering/device_edit
```

The name resolves directly to `plugins/{plugin}/logic/{action}_logic.php` (no theme chain — themes do not override plugin logic) and follows the same metadata-companion opt-in contract as core actions. Only **active** plugins resolve; an inactive or unknown plugin returns the same `Unknown action` 404 as a missing action, so responses do not reveal which plugins are installed. The namespace makes collisions structurally impossible — a plugin action can never shadow a core action or another plugin's.

Request logs and error messages use the full namespaced name (e.g. `action dns_filtering/device_edit`). See [Plugin Developer Guide](plugin_developer_guide.md) for the plugin-side conventions.

**Sessionless actions** (`requires_session => false`: `register`, `password_reset_1`, `password_reset_2`, …) are dispatched **without** key headers — a first-launch client has no credentials yet. HTTPS enforcement and both rate limiters apply unchanged, and failures log like other auth-adjacent traffic. The matching rule for fetching those actions' form definitions is in the Form Definition Endpoint section.

### Action Response Formats

**Success (HTTP 200):**
```json
{
    "api_version": "1.0",
    "success_message": "Action 'register' completed successfully.",
    "redirect": "/page/register-thanks",
    "data": { ... }
}
```

- `redirect` is included when the action would have redirected in the web UI (informational — the API consumer decides what to do with it)
- `data` contains any output data from the logic function

**Validation error (HTTP 422):**
```json
{
    "api_version": "1.0",
    "errortype": "ValidationError",
    "error": "Please correct the errors below",
    "validation_errors": {
        "field_name": "Error message for this field"
    },
    "data": {}
}
```

**Action error (HTTP 422):**
```json
{
    "api_version": "1.0",
    "errortype": "ActionError",
    "error": "This feature is turned off",
    "data": {}
}
```

### Available Actions

| Action | Description | Session |
|--------|------------|---------|
| `register` | Register a new user account | No |
| `password_reset_1` | Request password reset email | No |
| `password_reset_2` | Set new password via reset code | No |
| `password_set` | Set password on first login | No |
| `password_edit` | Change password (logged in) | Yes |
| `change_password_required` | Forced password change | Yes |
| `contact_preferences` | Update contact preferences | Yes |
| `account_edit` | Update profile fields | Yes |
| `address_edit` | Update address | Yes |
| `phone_numbers_edit` | Update phone numbers | Yes |
| `change_tier` | Change subscription tier | Yes |
| `survey` | Submit survey response | Yes |
| `booking` | Book an appointment | Yes |
| `cart` | Add item to cart | Yes |
| `cart_clear` | Clear cart | Yes |
| `event_register` | Register for an event | Yes |
| `event_withdraw` | Withdraw from event | Yes |
| `event_waiting_list` | Join event waiting list | Yes |
| `event_sessions` | Select event sessions | Yes |
| `event_sessions_course` | Select course sessions | Yes |
| `orders_recurring_action` | Recurring order action | Yes |
| `passkey_login_options` | Begin passwordless passkey sign-in (WebAuthn request options) | No |
| `passkey_login_verify` | Complete passkey sign-in and establish the browser session | No |
| `passkey_register_options` | Begin passkey enrollment (WebAuthn creation options); requires a recent step-up once ≥1 passkey exists | Yes |
| `passkey_register_verify` | Complete passkey enrollment and persist the new credential | Yes |
| `passkey_stepup_options` | Begin passkey step-up confirmation (WebAuthn request options scoped to the current user) | Yes |
| `passkey_stepup_verify` | Complete passkey step-up confirmation, marking the session recently re-verified | Yes |
| `passkey_rename` | Rename an enrolled passkey | Yes |
| `passkey_revoke` | Revoke an enrolled passkey (a consumer's unlocker floor may veto) | Yes |
| `vault_setup_options` | Begin Sealed Vault setup (WebAuthn PRF request options); requires an existing account password | Yes |
| `vault_setup_verify` | Complete vault setup: keypair, recovery codes, optional passphrase, open the unlock window | Yes |
| `vault_status` | Report the current user's vault setup/unlock status and enrolled unlockers | Yes |
| `vault_unlock_options` | Begin unlocking the vault with a passkey (userVerification required) | Yes |
| `vault_unlock_passkey` | Complete unlocking the vault with a passkey | Yes |
| `vault_unlock_recovery` | Unlock the vault with a one-time recovery code | Yes |
| `vault_unlock_passphrase` | Unlock the vault with the enrolled passphrase | Yes |
| `vault_lock` | Explicitly lock the vault for the current session | Yes |
| `vault_add_passkey_options` | Begin adding a vault wrapping for another PRF-capable passkey; vault must be unlocked | Yes |
| `vault_add_passkey_verify` | Complete adding a vault wrapping for another PRF-capable passkey | Yes |
| `vault_regenerate_codes` | Invalidate all recovery codes and issue a fresh set; requires step-up and an unlocked vault | Yes |
| `vault_passphrase_enroll` | Enroll (or replace) the optional vault passphrase; requires step-up and an unlocked vault | Yes |
| `vault_passphrase_remove` | Remove the vault passphrase; requires a recent step-up | Yes |
| `vault_rotate_options` | Begin vault key rotation (WebAuthn PRF request options) | Yes |
| `vault_rotate_verify` | Complete vault key rotation: fresh keypair, consumer re-seal, recovery codes replaced | Yes |

See [Passkeys](passkeys.md) for the WebAuthn ceremonies behind the `passkey_*` actions, and
[Sealed Vault](sealed_vault.md) for the `vault_*` ones.

### Drive actions and the chunk-upload endpoint

The member Drive exposes its verbs as sessioned `drive_*` actions —
`drive_folder_create`, `drive_rename`, `drive_move`, `drive_trash`,
`drive_restore`, `drive_delete_forever`, `drive_list`, `drive_shares`,
`drive_share_sync`, `drive_link_create`, `drive_link_revoke`, `drive_versions`,
`drive_version_restore`, `drive_changes`, and the two upload actions below.
Uploads use a resumable protocol rather than a single multipart POST:

- **`drive_upload_init`** opens an upload (or dedup-completes immediately when the
  client's `sha256` matches a blob the caller already possesses through their own
  files/versions), returning `{upload_token, chunk_bytes}`.
- **`PUT /api/v1/drive_upload/{token}`** — a raw-body binary endpoint (not an
  action; a pre-CRUD branch in `apiv1.php`). Chunks are **sequential**: the request
  carries `Content-Range: bytes <start>-<end>/<total>` and `<start>` must equal the
  server's `received_bytes`, else **409** with `{received_bytes}` to resume. `GET`
  returns `{received_bytes, expected_bytes}`. These requests use a dedicated
  **`api_upload`** rate-limit bucket (`api_upload_rate_limit_requests` /
  `api_upload_rate_limit_window`) so a multi-GB upload never exhausts the general
  1,000/hr budget; the transport logs each request's actual outcome into that
  bucket (success on 2xx, failure with status code otherwise).
- **`drive_upload_complete`** verifies the bytes, enforces the storage quota at
  the boundary, and ingests (retry-safe via `Idempotency-Key`).

#### Uploading something that is not a Drive file

The transport above is the only way a file larger than one web request reaches the
server, so it is not reserved for Drive. `drive_upload_init` takes an optional
**`purpose`**, defaulting to `drive`; any other value names an entry in
`UploadPurposeRegistry`, which supplies the policy — who may upload, what origin
tag the resulting `File` carries, and what happens once it exists.

The endpoint names are historical. They serve every purpose, and are not renamed
because three shipped clients address them.

A non-Drive purpose takes the same three steps and skips everything Drive-specific:
no quota, no folder, no encryption, no dedup short-circuit. The purpose is recorded
on the upload at init (`fup_purpose`), so an upload cannot be opened under one
purpose and completed as another to borrow its policy. `drive_upload_complete`
returns `{file: {id, name, size_bytes, mime_type, source}}` for these.

Shipped purposes: **`mail_import_archive`** (a mail archive being imported into a
mailbox — see the [Mailbox plugin](../plugins/mailbox/docs/overview.md)). Adding one
is a single `UploadPurposeRegistry::register()` call from the owning subsystem's
bootstrap; see `specs/implemented/chunked_upload_purposes.md`.

`drive_share_sync`'s `grants` body field is a JSON **object** (grantee → role), so
it is validated in the logic and passes through the descriptor boundary untouched.
See [Drive](drive.md) for the full protocol and access model.

Plugin action surfaces are documented with their plugin (e.g. the DNS filtering surface in [plugins/dns_filtering/docs/overview.md](../plugins/dns_filtering/docs/overview.md)) and appear in the discovery endpoint below.

### Action Discovery Endpoint

```
GET /api/v1/actions
```

Returns a list of all available actions with descriptions. Useful for API consumers to programmatically determine what actions are available. Actions from active plugins are listed under their namespaced name (`{plugin}/{action}`) with the same fields.

**Response:**
```json
{
    "api_version": "1.0",
    "success_message": "Available actions",
    "data": {
        "register": {
            "description": "Register a new user account",
            "requires_session": false,
            "input": null,
            "has_form": true
        },
        "event_withdraw": {
            "description": "Withdraw the current user from an event registration.",
            "requires_session": true,
            "input": {
                "evr_event_registrant_id": {"type": "int", "required": true, "label": "Event registrant ID"},
                "confirm": {"type": "bool", "required": true, "label": "Confirmation flag"}
            },
            "has_form": false
        }
    }
}
```

`input` is the action's typed input schema from its descriptor — the same
schema the action endpoint validates against; `null` when the descriptor
declares none. `has_form` indicates whether the action
exposes a server-driven form definition (below).

## Form Definition Endpoint

```
GET /api/v1/form/{action_name}
```

Returns the action's form as a JSON **definition** — fields, labels, prefilled values, validation rules, visibility rules — built by the action's form builder function and rendered through `FormWriterV2JSON`. Native apps render the definition with a generic form renderer and submit through the normal action endpoint; the schema reference, builder convention, and supported field types are documented in [docs/formwriter.md](formwriter.md#11-json-output-mode-server-driven-forms).

`visibility_rules` may appear on `drop`, `checkbox`, `radio`, and radio `checkbox_list` fields. The native renderer reads the current rule key by the trigger's type — a `drop`/`radio` keys on the selected option value, a `checkbox` keys on `checked`/`unchecked` — matching web behavior exactly (see [FormWriter §6](formwriter.md#6-field-visibility--custom-scripts)).

A form is served iff the action's logic file defines **both** `{action_name}_logic_descriptor()` and `{action_name}_logic_form()` (reflected in the discovery endpoint's `has_form` flag).

**Authentication mirrors the action's `requires_session` declaration:**

- Sessioned forms require the standard key headers; the definition is prefilled with the acting user's data. Like other reads, write-only keys (permission 2) get 403.
- Sessionless forms (`register`, `password_reset_1`, `password_reset_2`) are served **without** key headers — a first-launch client has no credentials yet. HTTPS enforcement and both rate limiters apply unchanged.

Query parameters are passed to the builder as request context (e.g. `GET /api/v1/form/password_reset_2?act_code=...` round-trips the reset code into the form's hidden field).

**Response:**
```json
{
    "api_version": "1.0",
    "success_message": "Form definition for 'account_edit'",
    "data": {
        "schema_version": 1,
        "form": {
            "name": "account_edit",
            "submit_to": "/api/v1/action/account_edit",
            "submit_label": "Submit"
        },
        "fields": [
            {"type": "text", "name": "usr_first_name", "label": "First Name",
             "value": "Jeremy", "maxlength": 255},
            {"type": "drop", "name": "usr_timezone", "label": "Your Time Zone",
             "value": "America/Chicago", "options": {"America/Chicago": "America/Chicago"}}
        ]
    }
}
```

Submissions go to `POST /api/v1/action/{action_name}` with a JSON body whose keys match the web form's POST exactly; validation failures return the standard 422 response with the field-keyed `validation_errors` map.

**Errors:** unknown action, action without a form builder → 404; non-GET method → 405; missing/invalid key on a sessioned form → standard authentication errors; a builder using a non-serializable construct → 500 `ActionError`.

## Management API (Read-Only)

The `/api/v1/management/*` namespace is a separate **read-only** surface used by the server_manager management node to observe managed nodes (stats, version, backup files, error log). It is **not part of the public CRUD API**: endpoints don't map to SystemBase models and have their own convention.

### Authorization

Management endpoints reuse the existing `apk_api_keys` table unchanged. Two gates, both checked before the endpoint is resolved:

- **Machine keys only.** The key's `apk_type` must be `machine`. Session keys minted by `auth/login` get 403 here regardless of who owns them — a superadmin logging into a phone app must not hold a management-node credential. This boundary is pinned by a dedicated test in `tests/functional/api/session_keys_test.php`; treat that test as load-bearing.
- **Superadmin owner.** The key's owning user must have `usr_permission >= 10`.
- `apk_permission` (1–4 CRUD gradient) is NOT a gate here — it is **orthogonal** to the management check. A superadmin's machine key with `apk_permission = 1` (read-only CRUD) can call management endpoints; a permission-5 admin's key cannot, regardless of `apk_permission`.
- All other existing auth checks (active, not deleted, not expired, IP restriction, secret verification) apply unchanged — management dispatch only happens after `apiv1.php`'s full auth chain has passed.

### Endpoints

All under `/api/v1/management/`, all `GET`, all return the standard success envelope except `backups/fetch` which streams a binary file.

| Endpoint | Description |
|----------|-------------|
| `health` | Liveness probe: `{ok: true, version: "…"}` — used by `JobCommandBuilder::has_api()` |
| `stats` | Disk, memory, load, uptime, PostgreSQL liveness, Joinery version, DB list, cron health, and a `backups` block reporting each backup profile (`site`, `manager`) — whether it is scheduled, its last run and outcome, whether that run reached the bucket, and the fingerprint of the recovery key it sealed to |
| `version` | System version, schema version, per-plugin versions |
| `databases` | List of PostgreSQL databases accessible to the site |
| `errors/recent` | Last N error.log lines matching Fatal/Exception/Error (default 20, cap 200) |
| `backups/list` | Files in `/backups/` with size and date |
| `backups/fetch?path=…` | Streams a backup file as `application/octet-stream` (path must be under `/backups/`) |

Discovery: `GET /api/v1/management` lists every endpoint with its method and description. Parallels `/api/v1/actions`.

### Adding a management endpoint

Convention-based, mirrors the action-endpoints layout. A single file defines two functions:

```php
// includes/management_api/my_thing_handler.php

function my_thing_handler($request) {
    return ['value' => 42];   // non-null array → router wraps with api_success()
}

function my_thing_handler_api() {
    return [
        'method' => 'GET',
        'description' => 'What this endpoint does',
    ];
}
```

`$request` is an associative array: `method`, `path`, `query` (`$_GET`), `body` (decoded JSON for non-GET), `headers`. Handlers should use `$request` rather than touching `$_GET`/`$_POST` directly. For streaming endpoints (`backups/fetch`), write bytes yourself and return `null` — the router will not append an envelope.

Nested paths mirror subdirectories: `includes/management_api/backups/list_handler.php` → `GET /api/v1/management/backups/list` → function `backups_list_handler()`.

**Key naming.** Handlers currently differ on names for shared concepts (`version` vs `system_version` vs `joinery_version`; `db_list` vs `databases`). Because the dashboard reads nodes that upgrade on their own schedules, a key rename requires the server_manager reader to accept both names until every node is current — so renames are never worth a standalone change. When you modify a handler for other reasons, align its keys with its siblings and update the server_manager readers in the same change.

### Writes? Not here.

The management API is permanently read-only. Mutating operations (backups, restores, upgrades, installs, deletions) stay on SSH — SSH is the more deliberate transport for state changes, and a compromised read-only key cannot do damage. If you find yourself wanting to add a write endpoint, extend SSH instead.

## Error Types

### CRUD Error Types

| Status | Error Type | Meaning |
|--------|-----------|---------|
| 400 | AuthenticationError | Missing headers, invalid key, deleted user, missing login fields |
| 400 | TransactionError | Object not found, validation failure, save error, invalid object name |
| 401 | AuthenticationError | Wrong secret, bad login credentials, IP restricted, inactive/expired/revoked key |
| 403 | AuthenticationError | Insufficient permission; machine key on `auth/logout`; session key on `management/*` |
| 426 | SecurityError | HTTPS required |
| 426 | UpgradeRequired | `client_version` below the configured minimum for this `client_app` |
| 429 | RateLimitError | Rate limit exceeded |

### Action Error Types

| Status | Error Type | Meaning |
|--------|-----------|---------|
| 404 | ActionError | Unknown action name or action not available via API |
| 405 | ActionError | Wrong HTTP method (actions require POST) |
| 409 | ActionError | `Idempotency-Key` reused with a different body/action, or its original request is still in progress |
| 422 | ActionError | Business logic error (e.g., feature disabled, invalid state) |
| 422 | ValidationError | Input validation failed — check `validation_errors` for field-level detail |

## Request Logging

All API requests are logged for audit purposes. Logs include: feature, action, IP address, user ID, success/failure, HTTP status code, response time, and — once authentication has passed — the API key type (`rql_api_key_type`), so audit queries can separate machine from session traffic. Secret keys, passwords, and request bodies are never logged.

Logs are retained for a configurable period (default: 90 days) and automatically cleaned up by a scheduled task.
