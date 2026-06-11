# REST API Documentation

## Overview

The Joinery platform provides a REST API for programmatic access to data and operations.

- **Base URL:** `https://{site-domain}/api/v1/`
- **Format:** JSON (all requests and responses)
- **Methods:** GET (read), POST (create), PUT (update), DELETE (soft delete)
- **HTTPS Required:** All requests must use HTTPS

## Authentication

All authenticated API requests use the same two custom headers:

```
public_key: {your_public_key}
secret_key: {your_secret_key}
```

There is one auth story with **two key types**, distinguished by `apk_type` in the shared `apk_api_keys` table. The request path is identical for both — only provisioning and secret hashing differ.

| | Machine keys (`machine`) | Session keys (`session`) |
|---|---|---|
| Provisioned by | An administrator via **Admin > API Keys** | The user, via `POST /api/v1/auth/login` |
| Identity | The user account the admin attached | The user who logged in |
| Secret hashing | Slow hash (phpass) — appropriate for admin-chosen secrets | SHA-256 — the secret is a random 256-bit value, so a slow KDF buys nothing and would cost on every request |
| Permission | Chosen by the admin (1–4) | Always 4; object-level authorization is the effective gate |
| Expiry | Optional, set by the admin | Always set, from the `api_session_key_lifetime_days` setting (default 365) |
| Revocation | Admin API Keys page | `auth/logout`, the profile **App Sessions** page, the admin API Keys page (type filter), and automatically on password change |
| Management API | Allowed (with superadmin owner) | **Never** |

A password change revokes **all** of the user's session keys (the lost-phone path); machine keys owned by the same user are untouched. Session keys are not IP-restricted — devices roam networks by design; the App Sessions view's device label and last-used time are the compensating visibility.

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

### `GET /api/v1/auth/session` — key-authenticated (either type)

Returns the user summary: user id, name, email, permission, subscription tier, and tier feature flags. The "who am I / what may I do" call on app launch.

### `POST /api/v1/auth/logout` — session-key-authenticated

Revokes the presented key (soft delete) and nothing else. Machine keys get 403 here — they are revoked from the admin page, not by themselves.

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

## CRUD Endpoints

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
| `numperpage` | 3 | Items per page |
| `sort` | (none) | Database column to sort by |
| `sdirection` | ASC | Sort direction: `ASC` or `DESC` |

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

### Create Object

```
POST /api/v1/{ClassName}
Content-Type: application/x-www-form-urlencoded

field1=value1&field2=value2
```

If the model has a `CreateNew()` static method, it is called first. Otherwise, a new object is created and fields are set from the POST body.

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

Fields to update are passed as query string parameters.

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

Any SystemBase model class is available via the API. Class names are case-sensitive and use PascalCase.

Common models include: `User`, `Product`, `Event`, `EventRegistrant`, `EventSession`, `Order`, `OrderItem`, `Group`, `GroupMember`, `Post`, `Page`, `Email`, `Message`, `File`, `CouponCode`, `SubscriptionTier`, `Location`, `Video`, `Comment`, `Survey`, `SurveyAnswer`, `Question`, `QuestionOption`, `MailingList`, `MailingListRegistrant`.

## Error Handling

### Error Response Format

```json
{
    "api_version": "1.0",
    "errortype": "AuthenticationError",
    "error": "Error: description of what went wrong",
    "data": ""
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

## Security Headers

All API responses include:

```
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
X-XSS-Protection: 1; mode=block
Referrer-Policy: no-referrer
```

## Action Endpoints

Actions execute multi-step business logic (registration, event signup, payments, etc.) rather than raw CRUD operations. All logic functions that have been opted in via a companion `_api()` function are available.

### Making a Logic Function Available via API

Add a companion function to your logic file:

```php
// In logic/your_action_logic.php

function your_action_logic_api() {
    return [
        'requires_session' => true,   // default: true
        'description' => 'What this action does',
    ];
}
```

That's it — no registry file or mapping needed.

### Action Request Format

```
POST /api/v1/action/{action_name}
Content-Type: application/json
public_key: {key}
secret_key: {key}

{ "field": "value", ... }
```

Sessioned actions require API key write permission (level 2+) and run under session simulation as the key's user.

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

### Action Discovery Endpoint

```
GET /api/v1/actions
```

Returns a list of all available actions with descriptions. Useful for API consumers to programmatically determine what actions are available.

**Response:**
```json
{
    "api_version": "1.0",
    "success_message": "Available actions",
    "data": {
        "register": {
            "description": "Register a new user account",
            "requires_session": false,
            "has_form": true
        },
        "event_register": {
            "description": "Register for an event",
            "requires_session": true,
            "has_form": false
        }
    }
}
```

`has_form` indicates whether the action exposes a server-driven form definition (below).

## Form Definition Endpoint

```
GET /api/v1/form/{action_name}
```

Returns the action's form as a JSON **definition** — fields, labels, prefilled values, validation rules, visibility rules — built by the action's form builder function and rendered through `FormWriterV2JSON`. Native apps render the definition with a generic form renderer and submit through the normal action endpoint; the schema reference, builder convention, and supported field types are documented in [docs/formwriter.md](formwriter.md#11-json-output-mode-server-driven-forms).

A form is served iff the action's logic file defines **both** `{action_name}_logic_api()` and `{action_name}_logic_form()` (reflected in the discovery endpoint's `has_form` flag).

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

The `/api/v1/management/*` namespace is a separate **read-only** surface used by the server_manager control plane to observe managed nodes (stats, version, backup files, error log). It is **not part of the public CRUD API**: endpoints don't map to SystemBase models and have their own convention.

### Authorization

Management endpoints reuse the existing `apk_api_keys` table unchanged. Two gates, both checked before the endpoint is resolved:

- **Machine keys only.** The key's `apk_type` must be `machine`. Session keys minted by `auth/login` get 403 here regardless of who owns them — a superadmin logging into a phone app must not hold a control-plane credential. This boundary is pinned by a dedicated test in `tests/functional/api/session_keys_test.php`; treat that test as load-bearing.
- **Superadmin owner.** The key's owning user must have `usr_permission >= 10`.
- `apk_permission` (1–4 CRUD gradient) is NOT a gate here — it is **orthogonal** to the management check. A superadmin's machine key with `apk_permission = 1` (read-only CRUD) can call management endpoints; a permission-5 admin's key cannot, regardless of `apk_permission`.
- All other existing auth checks (active, not deleted, not expired, IP restriction, secret verification) apply unchanged — management dispatch only happens after `apiv1.php`'s full auth chain has passed.

### Endpoints

All under `/api/v1/management/`, all `GET`, all return the standard success envelope except `backups/fetch` which streams a binary file.

| Endpoint | Description |
|----------|-------------|
| `health` | Liveness probe: `{ok: true, version: "…"}` — used by `JobCommandBuilder::has_api()` |
| `stats` | Disk, memory, load, uptime, PostgreSQL liveness, Joinery version, DB list |
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
| 422 | ActionError | Business logic error (e.g., feature disabled, invalid state) |
| 422 | ValidationError | Input validation failed — check `validation_errors` for field-level detail |

## Request Logging

All API requests are logged for audit purposes. Logs include: feature, action, IP address, user ID, success/failure, HTTP status code, response time, and — once authentication has passed — the API key type (`rql_api_key_type`), so audit queries can separate machine from session traffic. Secret keys, passwords, and request bodies are never logged.

Logs are retained for a configurable period (default: 90 days) and automatically cleaned up by a scheduled task.
