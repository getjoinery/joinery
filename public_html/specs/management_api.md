# Specification: Management API (Read-Only)

## Overview

Add a **read-only** HTTP management API exposed by every Joinery instance and teach the server_manager agent to prefer it over SSH for observability operations. Every node is already a Joinery/PHP/Apache install with an authenticated `/api/v1/` surface — this spec adds a namespaced set of read endpoints to core and a new `api` step type to the server_manager agent.

**The API is read-only by design.** Mutating operations — backups, upgrades, restores, deletions, installs, discoveries — stay on SSH, permanently. SSH is a more deliberate transport for operations that change node state; admins know how to audit it; the control plane already has the infrastructure. The API takes over the things that are currently painful SSH round-trips (stats, version checks, health probes, error logs) plus one streaming read (backup file download).

`JobCommandBuilder` chooses between API and SSH at job-build time: API if the node has a superadmin-owned API key configured and a fresh `/health` probe passes, SSH otherwise. Choice is made once per job, visible in the stored job record, never revisited at runtime.

## Motivation

### 1. Stats are currently SSH round-trips
Every dashboard view of `check_status` spawns an SSH session per metric. The data (disk, memory, Postgres liveness, version, error log lines) is cheap to compute and fits naturally in a JSON response. HTTP makes this faster, parallelizable across the fleet, and trivial to log.

### 2. Managed-services product story
Managing client instances over SSH means all-or-nothing root access. A read-only API lets us offer read-only monitoring keys: *"this key can see your fleet's health but cannot change anything."* Much easier to explain and sell than scoped writes, and the worst-case blast radius of a compromise is dramatically smaller.

### 3. Every node is already a Joinery install
No agent-to-deploy problem. Endpoints live in core; every instance gets them on upgrade.

### 4. Auditability
API calls land in logs by default. SSH commands require ad-hoc tooling to audit meaningfully.

### 5. Small security surface
Read-only means a leaked key cannot mutate state — no triggered backups, no hostile database restores, no unauthorized upgrades. The worst case is an attacker learning what versions you're running and, if they also have decryption keys for your encrypted backups, reading them.

## Non-Goals

- **Not a write API.** Backups, upgrades, restores, deletes — all stay SSH. Permanent design decision, not a phasing compromise.
- **Not killing SSH.** SSH remains the transport for all mutating operations and for emergency recovery.
- **Not a public API.** Management endpoints are not part of the public `/api/v1/` CRUD surface. They are namespaced under `/api/v1/management/` and gated by requiring the API key's owning user to be a superadmin.
- **No separate "management key" type.** Management endpoints use the existing `stg_api_keys` table with its existing permission model. No `apk_scopes` column, no new table, no new model class, no new CLI, no new admin page.
- **No automatic runtime fallback.** A job is decided as API or SSH at build time. It runs the chosen path or fails. Routing around a sick API is handled by the pre-flight health probe.

## Design Principles

1. **Core feature, not a plugin.** Every Joinery instance exposes the management API. The server_manager plugin is the client, not the provider.
2. **Build-time routing, not runtime branching.** `JobCommandBuilder` chooses API or SSH when it builds the job. The agent sees one path and runs it.
3. **Reuse, don't rebuild.** Existing API keys, existing permission model, existing admin UI. Zero new tables, zero new columns on `stg_api_keys`.
4. **Reuse existing job abstraction.** Add `type: api` alongside `ssh`/`scp`/`local` in the agent's step schema. No other structural changes.

## Architecture

### Request flow

```
Control plane (server_manager plugin)
  |
  | 1. Build steps (array of {type, label, ...})
  v
mjb_management_jobs row with steps JSON
  |
  | 2. Go agent picks up the job
  v
Go agent runner.go
  |
  +---> type=ssh   -> existing ssh.go
  +---> type=scp   -> existing scp.go
  +---> type=local -> existing local executor
  +---> type=api   -> NEW: HTTPS call to node's /api/v1/management/*
```

### Node-side request handling

`apiv1.php` is extended to recognize the `management/` path prefix *before* its existing class-matching logic (around the `if (in_array($operation, $classes))` branch) and dispatch to a new `ManagementApiRouter`. The existing auth chain (key lookup, is_active, start/expires, user load, IP restriction, bcrypt) runs first — the management router does **not** re-do auth. It intercepts after auth has passed.

The router then:

1. Checks the key's user permission: `$auth_data['current_user_permission'] >= 10` (superadmin). Anything lower returns 403. This uses the existing user permission model (admin=5, superadmin=10 per CLAUDE.md) rather than inventing new `apk_permission` levels.
2. Resolves the URL path to a handler file under `includes/management_api/`.
3. Verifies the request method matches what the handler declares.
4. Invokes the handler.

No scope check — the superadmin-level gate *is* the authorization. A later spec can add scope granularity if read-subcategories become necessary; YAGNI today.

### Authorization model

Management endpoints do NOT go through SystemBase's user-aware authorization hooks (`authenticate_read($session)`, `is_owner($session)`). The superadmin-user gate at the router level is the entire authorization layer.

- apiv1.php's existing auth chain validates the key and loads the user.
- Management router checks `current_user_permission >= 10` and resolves the endpoint.
- On success, control passes to the handler. Handlers use model classes as data-access helpers; they do not call `->authenticate_read($session)`.
- A session object exists (auto-loaded) but handlers should not consult it for authorization.

This parallels how admin pages in `/adm/` work: `$session->check_permission(5)` at the top of the page, then the rest of the code runs at the gated trust level.

**Smell to avoid:** reaching into user-flow logic functions (`register_logic()`, `cart_charge_logic()`, etc.) from a management handler. Those assume a user session. Management operations are system-level; if one ever needs user-flow behavior, extract it into a system-level helper first.

## Authentication

Management endpoints use the existing `stg_api_keys` table unchanged. No schema additions.

### Creating a management key (per node)

A key for the control plane to use on a given node is just a regular API key whose owning user is a superadmin. The admin creates it through the existing flow:

1. Admin logs into the managed node's admin UI as a superadmin user (`usr_permission = 10`).
2. Goes to Admin > API Keys, creates a new key owned by that superadmin account.
3. Sets `apk_permission` to 1 (read-only). Note: this does not make the key "management-only" — it just blocks writes through the CRUD API. The key can still read user data via `GET /api/v1/{Class}/{id}` because `apk_permission=1` allows reads. A truly management-only key would require a separate type indicator we deliberately chose not to add; see the exposure note below.
4. Sets IP restriction to the control plane's egress IP (strongly recommended).
5. Optionally sets a description like *"Server Manager — control plane"* for clarity.
6. Copies the public/secret pair.
7. Pastes into Server Manager's node detail page (Overview tab, "API Credential" panel).

No separate CLI utility, no separate admin page. The existing flow handles it. The key is usable for management endpoints because it belongs to a superadmin user.

**Why this works without a scope column:** management endpoint auth looks at `current_user_permission`, not `apk_permission`. A non-superadmin user's key can't hit management endpoints even if `apk_permission` is 4. Conversely, a superadmin's key with `apk_permission=1` *can* hit management endpoints (because the gate is user-level, not key-level) but cannot write to CRUD endpoints (because `apk_permission=1` is read-only for CRUD). The two permission axes are orthogonal, as they already are in the existing model.

**Exposure to be aware of:** a key minted as a management credential for the control plane can also read the CRUD API (since `apk_permission=1` allows CRUD reads). This is equivalent to the owning superadmin user's normal read access — not a privilege escalation over what the user already has — but it does mean a compromised management credential exposes more than just management endpoints. Mitigations:
- **IP restriction** (strongly recommended) limits the key's usability to the control plane's IP, whether the attacker is trying CRUD or management.
- **Distinct keys per purpose:** if a superadmin needs a key for CRUD integration work, they should mint a separate key; don't reuse the management credential.
- **Future work:** if we later add an `apk_scopes` column or equivalent, a value like `'management'` could block CRUD access from this key specifically. Deferred until the need arises.

### IP restriction

Strongly recommended on every management key — lock to the control plane's egress IP. The existing API key system already supports this; no new work.

### TLS policy

**HTTPS is enforced via the existing `api_require_https` setting** (on by default, can be turned off per-node for dev). The management endpoints inherit this — they don't add a parallel rule. Recommendation: leave the setting on in production.

Cert *verification* is a separate concern from protocol.

**Cert verification is strict by default, opt-out per node.** A new column `mgn_tls_insecure` (boolean, default `false`) on `mgn_managed_nodes` controls whether the control plane verifies the node's TLS certificate. When `true`, the Go agent's `http.Client` sets `InsecureSkipVerify` for that node only, and the ajax probe endpoint honors the same flag.

**When to set it:** dev/local instances without a cert from a trusted CA. **When not to:** anything reachable from the public internet; anything holding real user data.

**UI:** the Add Node / connection form exposes this as a checkbox tucked under "Advanced." Node detail page shows a small warning badge when set, so the insecure state is visible at a glance.

**Audit:** `SELECT mgn_slug FROM mgn_managed_nodes WHERE mgn_tls_insecure = true` lists every node bypassing verification.

## Endpoint discovery and method declaration

Matches the existing action-endpoint convention (see `specs/implemented/api_business_logic_endpoints_spec.md`). Each management endpoint is a single file; the file declares its own metadata via a companion function. No registry file.

**Layout:** filenames mirror the existing action-endpoint convention — the `_handler` suffix appears in both the filename and the function names, parallel to how `_logic` appears in both in `logic/{name}_logic.php`.

```
includes/management_api/
    health_handler.php
    stats_handler.php
    version_handler.php
    databases_handler.php
    errors/
        recent_handler.php
    backups/
        list_handler.php
        fetch_handler.php
```

**File contract** — each file defines two functions:

```php
// includes/management_api/stats_handler.php

function stats_handler($request) {
    // Does the work. Returns an associative array — the router wraps it
    // with api_success(). For endpoints that stream (backups/fetch),
    // the handler writes directly to the output buffer and returns null;
    // the router sees null and emits no trailing envelope.
    return ['disk' => ..., 'memory' => ..., /* ... */];
}

function stats_handler_api() {
    return [
        'method'      => 'GET',
        'description' => 'Disk, memory, load, uptime, Postgres liveness, Joinery version.',
    ];
}
```

**`$request` shape** — a simple associative array the router constructs from the incoming HTTP request:

```php
[
    'method'  => 'GET',          // uppercased HTTP method
    'path'    => 'stats',        // the URL segments after /api/v1/management/
    'query'   => $_GET,          // parsed query string
    'body'    => $decoded_json,  // decoded JSON body for non-GET, null otherwise
    'headers' => getallheaders(),
]
```

Handlers should not touch `$_GET`/`$_POST`/`$_SERVER` directly — always go through `$request`. Keeps handlers mockable in unit tests.

**Handler return → HTTP response:** the router calls `api_success($handler_result)` for a non-null array return, `api_error(...)` for a thrown exception. Status code defaults to 200 for success; a handler that needs a non-200 success (rare) can throw a specifically-typed exception the router translates. For streaming endpoints, the handler writes headers and bytes itself and returns `null`.

**URL-to-file mapping:** `/api/v1/management/stats` → `includes/management_api/stats_handler.php` (append `_handler.php`). `/api/v1/management/backups/list` → `includes/management_api/backups/list_handler.php`. Function name matches the file's basename: `backups/list_handler.php` → `backups_list_handler()`.

**Dispatch flow:** router resolves path → requires file → checks meta function exists (missing = 404, opt-in enforcement) → verifies method → invokes handler.

**Discovery endpoint:** `GET /api/v1/management` lists every management endpoint with its metadata. Analogous to the existing `GET /api/v1/actions`. Useful for admins, tooling, and generated docs.

**Properties:**
- Adding a new endpoint is one file. No other files change.
- Nested paths are trivial: subdirectories mirror URL segments.
- The meta function is mandatory — an endpoint without one is unreachable.

## Endpoint catalog

All under `/api/v1/management/`. All return JSON except `backups/fetch` which streams a binary file.

| Endpoint | Method | Purpose | Replaces SSH step(s) |
|----------|--------|---------|----------------------|
| `health` | GET | `{ok:true,version:"..."}` — liveness probe used by `has_api()` | N/A (new) |
| `stats` | GET | Disk, memory, load, uptime, PHP version, Postgres liveness, Joinery version, recent error count | All 6 steps of `check_status` |
| `version` | GET | `{system_version, schema_version, plugin_versions:{...}}` | `Check Joinery version` |
| `errors/recent` | GET | Last N parsed error.log entries | `Recent errors` step |
| `databases` | GET | List of accessible PostgreSQL databases | `List databases` step |
| `backups/list` | GET | Backup files in `/backups/` with size, date | `list_backups` job |
| `backups/fetch` | GET | Download a backup file as an HTTP stream (binary) | `fetch_backup` (SCP) |

### What stays on SSH — permanently

All of these. No exceptions, no "for now":

1. **All mutating operations** — `backup_database`, `backup_project`, `backup_delete`, `apply_update`, `refresh_archives`, `copy_database`, `restore_database`, `restore_project`, `install_node`, `discover_nodes`.
2. **`publish_upgrade`** — runs on the control plane, no node transport applies.
3. **Emergency recovery** — PHP stack wedged, Apache down, DB unreachable. SSH is the escape hatch.
4. **Host-level container metrics** (`docker stats`) — the API runs inside the container; host-level state is SSH-only.

## Choosing API or SSH

The choice is made once, in PHP, at job-build time. The agent never decides — it just runs whatever steps it finds in the job record.

### The rule

`JobCommandBuilder::has_api($node, $operation)` returns `true` when all three are true:

1. The node has `mgn_api_public_key` and `mgn_api_secret_key` populated.
2. The operation being built has an API implementation (`build_<op>_api` exists).
3. A fresh `GET /api/v1/management/health` probe against the node succeeds.

If `has_api()` is true, the builder emits `api` steps. Otherwise it emits the SSH steps exactly as today.

**Forcing SSH without deleting credentials.** Not supported. If an admin needs to force SSH on a specific node for some reason, they clear the stored credentials and paste them back when they're ready to return to API. The probe handles the "API is broken" case automatically.

### Pre-flight health check

Every call to `has_api()` synchronously probes the node with `GET /api/v1/management/health` (1-second timeout). **No state is persisted.** The probe happens fresh each time the routing decision is made; the result is consumed in-memory and discarded.

This works because:
- Jobs aren't created rapidly. One probe per click, 50-200ms per healthy probe.
- The dashboard per-node API indicator is handled by a separate async ajax probe (see below), independent of job-building.
- Nothing to invalidate on recovery.

**Probe result classification:**
- HTTP 200 with expected body → healthy
- Any other HTTP status, transport failure, timeout, or malformed response → unhealthy
- HTTP 401/403 → unhealthy for routing purposes; ajax probe surfaces this as an auth-specific reason so the UI can distinguish "key is wrong" from "endpoint is down"

### Dashboard API indicator

Per-node status cards show an "API" indicator alongside the online/offline dot. Populated by an ajax endpoint on the control plane, called async from the dashboard JS after page render:

```
GET /ajax/server_manager/probe_api?node_id=N
→ {"ok": true, "elapsed_ms": 87, "message": null}
  or
→ {"ok": false, "elapsed_ms": 1003, "message": "connection refused", "reason": "transport"}
```

Stores nothing. Async — slow or failing probes don't block page render. Users refresh the page to re-check.

### The dispatcher pattern

For every migrated operation, the existing builder is renamed with an `_ssh` suffix; the original name becomes a dispatcher:

```php
public static function build_check_status($node) {
    if (self::has_api($node, 'check_status')) {
        return self::build_check_status_api($node);
    }
    if (self::has_ssh($node)) {
        return self::build_check_status_ssh($node);
    }
    throw new SystemException(
        "Node '{$node->get('mgn_slug')}' cannot run check_status: " .
        "no API credentials (or health probe failed) and no SSH credentials configured."
    );
}
```

Both implementations coexist. Neither calls the other. If neither transport is available, the dispatcher refuses to build the job — an explicit, admin-visible error.

### Node transports and operation capability

```php
public static function has_api_creds($node) {
    return !empty($node->get('mgn_api_public_key'))
        && !empty($node->get('mgn_api_secret_key'));
}

public static function has_ssh($node) {
    return !empty($node->get('mgn_host'))
        && !empty($node->get('mgn_ssh_user'))
        && !empty($node->get('mgn_ssh_key_path'));
}

public static function transports_for($operation) {
    $transports = [];
    if (method_exists(static::class, "build_{$operation}_api")) {
        $transports[] = 'api';
    }
    if (method_exists(static::class, "build_{$operation}_ssh")) {
        $transports[] = 'ssh';
    }
    return $transports;
}

public static function can_run($node, $operation) {
    $op_transports = self::transports_for($operation);
    if (in_array('api', $op_transports) && self::has_api_creds($node)) return true;
    if (in_array('ssh', $op_transports) && self::has_ssh($node)) return true;
    return false;
}

public static function why_cannot_run($node, $operation) {
    $op_transports = self::transports_for($operation);
    if (empty($op_transports)) {
        return "Operation '{$operation}' has no implementation.";
    }
    $parts = [];
    if (in_array('api', $op_transports) && !self::has_api_creds($node)) {
        $parts[] = 'no API credentials are configured';
    }
    if (in_array('ssh', $op_transports) && !self::has_ssh($node)) {
        $parts[] = 'SSH is not configured';
    }
    if (!in_array('api', $op_transports)) {
        $parts[] = 'no API implementation exists';
    }
    if (!in_array('ssh', $op_transports)) {
        $parts[] = 'no SSH implementation exists';
    }
    return "Cannot run '{$operation}' on this node: " . implode('; ', $parts) . '.';
}
```

`can_run()` uses `has_api_creds()` (config check, optimistic) — not `has_api()` (which probes). Gray-out means "structurally impossible," not "might flake for a second."

### UI affordance: graying out non-runnable operations

Node detail pages render operation buttons through a helper:

```php
if (JobCommandBuilder::can_run($node, 'backup_database')) {
    echo '<button ...>Run Backup</button>';
} else {
    echo '<button disabled title="'. htmlspecialchars(
        JobCommandBuilder::why_cannot_run($node, 'backup_database')
    ) .'">Run Backup</button>';
}
```

`why_cannot_run()` returns a short reason: *"This operation has no API implementation, and SSH is not configured for this node."*

### What happens when an API job fails

It fails. The job record shows `failed`, the output explains why. In most cases the admin doesn't need to do anything:

- **Transient failure** (network blip, brief 5xx, node restarting) — admin clicks retry; next job-build probes fresh and routes correctly.
- **Persistent API-only failure** — probe keeps returning unhealthy; subsequent jobs route to SSH automatically until the endpoint is fixed.
- **Persistent whole-node failure** — node is down; neither transport helps.
- **No transport configured** — dispatcher refuses to build the job with a clear message. UI gray-out prevents this reaching the dispatcher in practice; the builder-level check is the backstop.

The admin never has to touch configuration for normal recovery. The probe is the whole mechanism.

## New step type: `api`

Add to the agent's step schema alongside `ssh`, `scp`, `local`.

### Schema

| Field | Required | Description |
|-------|----------|-------------|
| `type` | Yes | `api` |
| `label` | Yes | Human-readable step description |
| `method` | Yes | `GET`, `POST`, `PUT`, `DELETE` (in practice, always `GET` for this spec — all endpoints are read-only) |
| `endpoint` | Yes | Path relative to `/api/v1/management/`, e.g. `stats` |
| `timeout` | No | Request timeout in seconds (default 30; `backups/fetch` overrides to something longer) |
| `expect_status` | No | Integer status code that counts as success (default 200) |
| `continue_on_error` | No | Same semantics as ssh step |

### Execution (Go agent)

`runner.go` dispatches `type: api` to a new `api.go` that:

1. Looks up the target node's `mgn_api_public_key` / `mgn_api_secret_key` / `mgn_tls_insecure` / site URL from the node row. (The site URL column name — `mgn_site_url` or equivalent — should be verified against the current `mgn_managed_nodes` schema during Phase 1. Node Add form today exposes "Site URL"; wire up to whatever column backs it.)
2. Constructs `https://{site_url}/api/v1/management/{endpoint}`.
3. Issues the HTTP request with the two header auth. TLS cert verification enabled by default; skipped only when `mgn_tls_insecure=true`.
4. Reads the response body incrementally, appending bytes to `mjb_output` as they arrive.
5. If the HTTP response doesn't match `expect_status`, or the transport fails, marks the step failed and writes the reason to output.

`backups/fetch` is the only endpoint that streams a meaningful volume of data; the agent streams chunks to disk (not `mjb_output`) using the existing fetch-backup output-path convention.

## Implementation phases

### Phase 1 — Foundation (1-2 days)

1. Add `/api/v1/management/` dispatcher — `ManagementApiRouter.php` resolves URL paths to handler files under `includes/management_api/` using the convention above. Intercepts in `apiv1.php` *before* the class-matching branch. Gates on `current_user_permission >= 10` (superadmin). Include the discovery endpoint `GET /api/v1/management`.
2. Implement `health` endpoint. First handler; smoke test for the whole pipeline.
3. Add `api` step type to Go agent (`api.go`, wire into `runner.go`, honor `mgn_tls_insecure`).
4. Add columns to `mgn_managed_nodes`: `mgn_api_public_key`, `mgn_api_secret_key`, `mgn_tls_insecure` (boolean, default `false`).
5. Implement `JobCommandBuilder` transport/capability helpers: `has_api($node, $operation)`, `has_api_creds($node)`, `has_ssh($node)`, `transports_for($operation)`, `can_run($node, $operation)`, `why_cannot_run($node, $operation)`.
6. Add `GET /ajax/server_manager/probe_api?node_id=N` on the control plane. Reuses the probe helper. Stores nothing.
7. Add Overview-tab UI panel to paste/view/clear the stored API credential. Dashboard and node-detail pages call the ajax probe after render; "API" indicator resolves asynchronously.

**Phase 1 exit criterion:** a `check_status` job configured with a single `type: api, endpoint: health` step runs end-to-end; the output appears in the job detail page; the dashboard's per-node API indicator resolves async after page load against a real probe.

**General rule for each migrated operation:** the existing `build_<op>()` is renamed `build_<op>_ssh()`; a new `build_<op>_api()` is added; the original name becomes the dispatcher. No SSH implementation is deleted, ever.

### Phase 2 — Read-only operations (2-3 days)

1. Implement `stats`, `version`, `errors/recent`, `databases`, `backups/list`, `backups/fetch`.
2. Split `build_check_status` into `_ssh` + `_api`. API version emits one `api` step for `stats`. Result processor handles both branches: parses JSON on API path, stdout on SSH path, both populating `mgn_last_status_data` in the same shape.
3. Split `build_list_backups` into `_ssh` + `_api`.
4. Split `build_fetch_backup` into `_ssh` + `_api`.
5. Verify dashboard health dots, version display, and backups tab work unchanged on both API-provisioned and SSH-only nodes.
6. Verify routing-around: break `health` on one node, confirm next job routes to SSH; restore, confirm next job routes back to API.
7. Verify credential-clear path: clear stored creds on a node, confirm subsequent jobs build SSH steps; paste back, confirm API resumes.

**Phase 2 exit criterion:** on an API-provisioned and healthy node, read-only job steps in `mjb_commands` are `api`-typed; breaking the endpoint OR clearing stored credentials both produce identical-shape SSH-routed results on the next job, with no admin intervention.

**Total timeline: 3-5 days.**

## Error handling

Reuses the existing `api_success()` / `api_error()` helpers in `apiv1.php`. No new response shape.

**Success** (from `api_success()`):
```json
{
  "api_version": "1.0",
  "success_message": "...",
  "data": { ... }
}
```

**Failure** (from `api_error()`):
```json
{
  "api_version": "1.0",
  "errortype": "TransactionError",
  "error": "Human-readable message",
  "data": ""
}
```

`errortype` values match the existing convention: `AuthenticationError`, `TransactionError`, `ActionError`, `ValidationError`, `RateLimitError`, `SecurityError`. Management handlers throw or call `api_error()` with one of these; don't invent new types unless genuinely new.

HTTP status follows the existing api_error convention:
- `200` — success
- `400` — malformed request, bad parameter
- `401` — authentication failed
- `403` — authenticated but below superadmin permission threshold, or IP-restricted
- `404` — endpoint not found, or resource (backup file, etc.) not found
- `429` — rate-limited (inherited from existing `api_rate_limit_*` settings)
- `500` — unexpected server-side failure
- `504` — downstream operation timed out

## Testing

### Unit tests
- Auth: superadmin-user key accepted on `/management/*`, non-superadmin user key rejected with 403, inactive/expired key rejected.
- Endpoint handlers: each tested in isolation with mocked node state.
- Capability helpers: `can_run()` returns correct value for each combination of credentials and operation implementations; `why_cannot_run()` returns meaningful messages.

### Integration tests
- `tests/integration/management_api/` — full round-trip from a control plane test harness to a local Joinery install. Each endpoint has happy-path and failure-path tests.

### Agent tests
- Go agent `api` step: mocked HTTP server; verify headers, TLS verification toggle, timeout behavior, response streaming to `mjb_output`.
- End-to-end smoke: agent `api` step against a real Joinery instance.

### Manual
- Exercise each read operation in the Server Manager dashboard against the two docker-prod sites (`empoweredhealthtn`, `scrolldaddy`) and `joinerytest.site` after Phase 2. Verify parity with pre-migration SSH behavior.

## Rollout to existing nodes

Three existing managed instances need credentials provisioned:

1. **`joinerytest.site`** (control plane itself, but also a node) — as a superadmin user, create an API key in its own Admin > API Keys; paste into the Server Manager entry for this node. Lock IP restriction to 127.0.0.1 if Server Manager runs on the same host.
2. **`empoweredhealthtn` (docker-prod)** — create a key via its admin UI; paste into Server Manager; IP-restrict to control plane's IP.
3. **`scrolldaddy` (docker-prod)** — same process.

Nodes without provisioned credentials continue using SSH exactly as today. Nothing breaks.

## Open questions

1. **Should the permission threshold be admin (5) or superadmin (10)?** Spec currently requires superadmin (10). Admin (5) would parallel `/adm/` pages, but those are user-session-authenticated and already carefully authored; API keys are long-lived credentials whose compromise has broader blast radius, so tightening to superadmin is appropriate. Revisit only if a use case emerges for non-superadmin management access.
2. **What counts as a "recent error" for `errors/recent`?** Time window? Pattern match? Start with "last 20 lines containing `Fatal|Exception|Error`" — mirrors current SSH behavior — iterate from there.
3. **Filesystem readability for the web user.** Reading `error.log` requires the log to be readable by `www-data`. Existing installs may or may not have this. If not, the `errors/recent` endpoint will silently return empty results — a functional regression vs. the current SSH path that reads as root. Verify on all three target sites (`joinerytest.site`, `empoweredhealthtn`, `scrolldaddy`) *before* implementing the endpoint in Phase 2. If a permission tweak is needed, ship it as part of `install.sh` and include an idempotent fix-up in the rollout steps. This is not a "minor" open question — it determines whether one of the seven endpoints actually works.

## Docs to update

Per project convention (memory: "Always add developer docs to the relevant existing /docs/ file when writing specs"):

- **`docs/server_manager.md`** — add a "Management API (Read-Only)" section explaining the `api` step type, how credentials are provisioned (existing admin API key pasted into node detail), which operations are API vs SSH. Update the "How It Works" section to describe `ssh`/`scp`/`local`/`api` as the four step types.
- **`docs/api.md`** — add a section describing the `/api/v1/management/*` namespace: superadmin-user gate, read-only, link to server_manager.md for the endpoint catalog. Note the orthogonality of `apk_permission` (CRUD gradient) and `usr_permission` (role gate for management).

## Success criteria

1. A fresh `/admin/server_manager` dashboard load with N API-provisioned nodes issues N parallel HTTPS `stats` calls and renders in under 2 seconds. Today's SSH-based path is ~1 second per node sequentially.
2. Read-only operations on API-provisioned nodes run with the same reliability as the current SSH path.
3. Every migrated operation has both SSH and API implementations. Clearing a node's stored credentials (or breaking its `/health` endpoint) moves the next job to that node onto the SSH path with no code changes required.
4. The API-vs-SSH choice is observable per job: the `mjb_commands` JSON shows the step types directly. Admins can tell from inspecting any recent job record whether API or SSH was used.
5. On a node with API creds but no SSH creds (or vice versa), operation buttons render correctly enabled/disabled. Clicking a disabled button is impossible; if somehow a job is built for an impossible (op, node) pair, the dispatcher throws a clear error naming the missing transport.
6. A leaked management API credential cannot mutate node state — there are no write endpoints to call. Worst case: an attacker knows what Joinery versions are deployed and what backup files exist. An IP-restricted key raises the bar further.
