# Specification: Management API

## Overview

Replace SSH-based node management with an HTTP management API exposed by every Joinery instance. Every node is already a Joinery/PHP/Apache install with an authenticated `/api/v1/` surface — this spec adds a namespaced set of management endpoints to core and a new `api` step type to the server_manager agent so that Server Manager jobs call those endpoints instead of SSHing for routine operations.

**SSH is retained as an escape hatch for operations that cannot be performed over HTTPS** (bootstrap recovery, wedged installs, host-level operations outside the web user's reach), but every operation that *can* be moved to the API should be.

## Motivation

### 1. Stats are currently SSH round-trips
Every dashboard view of `check_status` spawns an SSH session per metric. The data being fetched (disk, memory, Postgres liveness, version, container stats) is cheap to compute and fits naturally in a JSON response. HTTP makes this faster, cacheable, parallelizable across the fleet, and trivial to log.

### 2. Managed-services business model
Managing client instances over SSH means all-or-nothing root access. An HTTP management API with scoped keys lets us say — and *prove* — exactly what we can touch: "this key can run backups and read health, but cannot read user data or change settings." That is a real product differentiator for the managed-instance offering, not just a cleanup.

### 3. Every node is already a Joinery install
There is no agent-to-deploy problem. Management endpoints live in core; every instance gets them on upgrade. No separate binary, no separate auth story, no extra install step.

### 4. Auditability
API calls land in logs by default. SSH commands require ad-hoc tooling to audit meaningfully.

## Non-Goals

- **Not killing SSH.** SSH remains the escape hatch for recovery, bootstrap, and anything the API can't reach.
- **Not backwards compatible.** Pre-launch. The agent will require API support for migrated operations; no dual SSH/API code paths for the same operation.
- **Not a public API.** Management endpoints are not part of the public `/api/v1/` CRUD surface. They are namespaced under `/api/v1/management/` and gated by a dedicated key scope.

## Design Principles

1. **Core feature, not a plugin.** Every Joinery instance exposes the management API. The server_manager plugin is the client, not the provider.
2. **API first, SSH fallback.** If an operation can run over HTTP, it must. SSH is used only for operations that genuinely require it (see "What stays on SSH" below).
3. **Scoped keys, not shared keys.** Each managed node gets its own management key, minted by that node and stored by the control plane. Keys carry a scope that constrains which endpoints they can call.
4. **Reuse existing job abstraction.** The server_manager agent already executes ordered step arrays. Add a new step `type: api` alongside `ssh`/`scp`/`local`. No other structural changes to the job system.
5. **No per-endpoint permission registry.** Scopes are coarse-grained sets (`read`, `backup`, `upgrade`, `admin`); each endpoint declares the scope it requires. Keep this small — 3-5 scopes total.

## Architecture

### Request flow (new)

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

The node's existing `apiv1.php` router is extended to recognize the `management/` path prefix and dispatch to a new `ManagementApiRouter`. Authentication uses the same `public_key`/`secret_key` header pair as the rest of the API, but the key must be of type `management` and scoped to a verb the endpoint requires.

```
POST /api/v1/management/stats
public_key: <node-specific-public-key>
secret_key: <node-specific-secret-key>
```

### Why not extend the existing CRUD API?

The public CRUD API authenticates via keys that belong to a *user*. Management operations are not user-scoped — they are infrastructure. Mixing them would pollute the user-identity model (e.g., "who is the user behind `/api/v1/management/apply_upgrade`?"). A separate key type with its own permission model is cleaner.

## Authentication

### Key type

Add a `apk_key_type` column to `stg_api_keys` (or create a `stg_management_api_keys` table — decide during implementation based on whether management keys share enough with user keys to justify a single table; if in doubt, **separate table** because the identity model differs).

Values: `user` (existing, default) and `management`.

### Scopes

Management keys carry one or more scopes in a `apk_scopes` (or equivalent) text column. Start with three:

| Scope | Purpose | Example endpoints |
|-------|---------|-------------------|
| `read` | Read-only diagnostics and status | `stats`, `health`, `version`, `list_backups` |
| `backup` | Run backups, list backups, fetch backups | `backup_database`, `backup_project`, `fetch_backup` |
| `upgrade` | Apply upgrades, refresh archives | `apply_upgrade`, `refresh_archives` |
| `admin` | Everything above + settings mutation | All management endpoints |

Resist adding more scopes until there is a concrete need. Broad is fine — the network-level isolation (HTTPS + secret key + IP restriction) is the primary defense, scopes are the secondary defense for key compromise.

### Key provisioning

Two flows. Node-side is authoritative in both.

**Flow A — automatic on node install (preferred):**

1. `install.sh` runs a post-install step that calls a CLI utility on the freshly-installed site: `php utils/management_key_mint.php --scope=admin --print`
2. That utility generates a key pair, inserts it as a `management` key on the new node, and prints the JSON to stdout: `{"public_key":"...","secret_key":"...","site_url":"https://..."}`
3. `install_node` Server Manager job captures that output (via existing SSH channel) and stores it on the control plane in `mgn_managed_nodes` (new columns `mgn_api_public_key`, `mgn_api_secret_key`, `mgn_api_scopes`).
4. From that point forward, the agent prefers the API for any migrated operation.

**Flow B — manual for existing nodes:**

1. Admin runs `php utils/management_key_mint.php --scope=admin --print` on the node (via SSH).
2. Pastes the JSON into a field on the node's Overview tab in Server Manager.
3. Control plane stores and uses it.

**Key rotation:** same utility with `--rotate` flag. Old key is revoked; new key is returned. Admin pastes into Server Manager to update the stored credential. Rotation is a manual, admin-initiated action — not automatic.

**Key storage on the control plane:** the secret is stored encrypted at rest using the same encryption pattern used for `bkt_credentials` (see `plugins/server_manager/includes/S3Signer.php`'s credential handling). Do not log or echo the secret in job output.

### IP restriction

Management keys should support IP restriction at the key level (the existing API key system already supports this for user keys — reuse the same mechanism). Default: no IP restriction. Strongly recommended: lock each node's management key to the control plane's egress IP.

## New step type: `api`

Add to the agent's step schema alongside `ssh`, `scp`, `local`.

### Schema

| Field | Required | Description |
|-------|----------|-------------|
| `type` | Yes | `api` |
| `label` | Yes | Human-readable step description |
| `method` | Yes | `GET`, `POST`, `PUT`, `DELETE` |
| `endpoint` | Yes | Path relative to `/api/v1/management/`, e.g. `stats` |
| `body` | No | JSON body for POST/PUT (object or null) |
| `timeout` | No | Request timeout in seconds (default 120; set higher for long operations like `apply_upgrade`) |
| `expect_status` | No | Integer status code that counts as success (default 200) |
| `continue_on_error` | No | Same semantics as ssh step |
| `stream` | No | If `true`, the endpoint is expected to stream NDJSON progress events (see "Streaming" below). Default `false`. |

### Execution (Go agent)

`runner.go` dispatches `type: api` to a new `api.go` that:

1. Looks up the target node's `mgn_api_public_key` / `mgn_api_secret_key` from the node row.
2. Constructs `https://{mgn_site_url}/api/v1/management/{endpoint}`.
3. Issues the HTTP request with the two header auth.
4. Writes the response body (or streamed events) to `mjb_output` progressively.
5. If `expect_status` doesn't match, marks the step failed.
6. If the node returns a structured error JSON, surfaces it verbatim in output.

### Streaming

Long-running operations (apply_upgrade, backup_project) need progress visible in the job detail UI during execution, not just at the end. The streaming contract:

- The node responds with `Content-Type: application/x-ndjson`.
- Each line is a JSON object: `{"event":"progress|log|done|error", "message":"...", "data":{...}}`.
- The agent appends each line to `mjb_output` as it arrives, mirroring the existing SSH step behavior where stdout is streamed.
- The final event must be `done` (success) or `error` (failure). Absence of either by the timeout is treated as failure.

This keeps the existing job detail polling UX (`ajax/job_status.php`) working unchanged — it already polls `mjb_output` by offset.

## Initial endpoint catalog

All under `/api/v1/management/`. All return JSON (or NDJSON for streaming endpoints marked ⟿).

### Read-only (`read` scope)

| Endpoint | Method | Purpose | Replaces SSH step(s) |
|----------|--------|---------|----------------------|
| `stats` | GET | Disk, memory, load, uptime, PHP version, Postgres liveness, Joinery version, recent error count | All 6 steps of `check_status` |
| `health` | GET | Minimal `{ok:true,version:"..."}` — cheap liveness probe | N/A (new capability) |
| `version` | GET | `{system_version, schema_version, plugin_versions:{...}}` | `Check Joinery version` |
| `errors/recent` | GET | Last N parsed error.log entries, grouped | `Recent errors` step |
| `databases` | GET | List of accessible PostgreSQL databases | `List databases` step |
| `backups/list` | GET | Backup files in `/backups/` with size, date | `list_backups` job |

### Backup (`backup` scope)

| Endpoint | Method | Purpose | Replaces |
|----------|--------|---------|----------|
| `backups/database` ⟿ | POST | Run `backup_database.sh`, stream progress, optionally upload to configured target | `backup_database` job |
| `backups/project` ⟿ | POST | Run `backup_project.sh`, stream progress | `backup_project` job |
| `backups/fetch` | GET | Download a backup file as an HTTP stream (binary) | `fetch_backup` (SCP) |
| `backups/delete` | DELETE | Delete a backup file (local and/or cloud) | `delete_backup` job |

### Upgrade (`upgrade` scope)

| Endpoint | Method | Purpose | Replaces |
|----------|--------|---------|----------|
| `upgrade/apply` ⟿ | POST | Run `upgrade.php`, stream progress | `apply_update` job |
| `upgrade/refresh_archives` ⟿ | POST | Run `upgrade.php --refresh-archives` | `refresh_archives` job |
| `upgrade/dry_run` ⟿ | POST | `upgrade.php --dry-run` | `apply_update` w/ dry_run param |

### Admin (`admin` scope)

| Endpoint | Method | Purpose | Replaces |
|----------|--------|---------|----------|
| `database/copy` ⟿ | POST | Receive a streamed SQL dump and restore it | `copy_database` (SSH+SCP path) |
| `database/restore` ⟿ | POST | Restore from a named backup file | `restore_database` |
| `project/restore` ⟿ | POST | Restore from a project `.tar.gz` | `restore_project` |

### What stays on SSH (intentionally)

1. **`discover_nodes`** — There is no API yet because there is no node yet. By definition this is SSH-only.
2. **`install_node`** — Bootstrapping a fresh Joinery site. No API exists until installation completes.
3. **`publish_upgrade`** — Runs on the control plane itself, not a node.
4. **Emergency recovery** — When the PHP stack is wedged (bad deploy, Apache down, DB unreachable), SSH is the only way back in. This is a policy of retention, not a migrated operation.

## Running as the web user

The web server user (typically `www-data`) runs every management API endpoint. We do **not** grant `www-data` any sudo privileges. Instead, the filesystem is arranged so the operations that need to write to specific locations can do so directly as `www-data`.

### Filesystem layout changes (install.sh)

`install.sh` is updated to ensure the following ownership/permissions at install time:

1. **`/backups/`** — created if missing, ownership set to `www-data:www-data`, mode `0755`. Previously this was root-owned with `chmod 777` applied inside the backup job itself. Now it's provisioned correctly once and never touched again.
2. **Web root** (`/var/www/html/SITE/public_html` and siblings under `/var/www/html/SITE/`) — owned by `www-data:www-data` recursively. Most Joinery installs already look like this; the install script makes it explicit and enforced rather than incidental.
3. **`maintenance_scripts/`** — readable by `www-data` (already the case; no change beyond verification).

These changes make `backup_database.sh`, `backup_project.sh`, `restore_database.sh`, `restore_project.sh`, and `upgrade.php` all run cleanly as `www-data` with no privilege escalation anywhere.

### What genuinely still needs root

Nothing currently in the Server Manager job catalog does. `systemctl` restarts, Apache config edits, package installs, and other host-level operations are not in scope for the management API — if we ever need them, they stay on SSH. In particular:

- **`docker stats` on the host** — the only host-level (not container-level) metric in today's `check_status`. The API `stats` endpoint explicitly omits this and it continues to come via the existing `on_host: true` SSH step. If we later want container stats over API, that's a separate host-level agent, which is out of scope for this spec.

### Migrating existing managed nodes

Existing nodes were installed before this filesystem convention. A one-time migration on each node:

```bash
sudo chown -R www-data:www-data /backups
sudo chown -R www-data:www-data /var/www/html/SITE
```

Package this as a CLI utility (`utils/management_api_migrate_permissions.php`) so it can be run either manually via SSH during rollout or as a one-shot Server Manager SSH job. The utility must be idempotent — re-runnable without side effects.

### Security posture

The management API being an HTTP-authenticated surface running as `www-data` — with no sudo anywhere — is a net improvement over the current state, where the control plane holds a root SSH key and uses it for every operation. Key compromise scope shrinks from "full root on every managed node" to "whatever `www-data` can touch on a single node's filesystem and database."

### Docker nodes

Inside a Joinery container, the PHP process commonly runs as root. The filesystem-ownership discussion is moot inside the container — the install-time steps simply no-op when already-root. The only operation that ever touched the host was `docker stats`, which stays SSH-routed as described above.

## Implementation phases

### Phase 1 — Foundation (1-2 days)

1. Add management key support
   - Schema: `stg_management_api_keys` table (or `apk_key_type` on existing `stg_api_keys` — decide in implementation)
   - Model: `ManagementApiKey` + `MultiManagementApiKey`
   - Auth middleware: reject management keys from `/api/v1/{Class}/...` CRUD routes; accept only on `/api/v1/management/...`
2. Add CLI: `utils/management_key_mint.php` (supports `--scope`, `--rotate`, `--print`, `--revoke`)
3. Add `/api/v1/management/` dispatcher — wires up to `ManagementApiRouter.php` which maps paths to handler files under `includes/management_api/`.
4. Implement `health` endpoint. This is the smoke test: if `health` works over the `api` step type end-to-end, the foundation is sound.
5. Add `api` step type to the Go agent (`api.go`, wire into `runner.go`).
6. Add `mgn_api_public_key`, `mgn_api_secret_key`, `mgn_api_scopes` columns to `mgn_managed_nodes`. Add an Overview-tab UI panel to paste/view/rotate the credential.

**Phase 1 exit criterion:** a `check_status` job configured with a single `type: api, endpoint: health` step runs successfully end-to-end, and the output appears in the job detail page.

### Phase 2 — Migrate read-only operations (1-2 days)

1. Implement `stats`, `version`, `errors/recent`, `databases`, `backups/list`.
2. Rewrite `JobCommandBuilder::build_check_status()` to emit an `api` step for `stats` instead of six SSH steps. Rewrite the result processor to parse the JSON response into `mgn_last_status_data`.
3. Rewrite `JobCommandBuilder::build_list_backups()` to emit an `api` step for `backups/list`.
4. Verify dashboard health dots, version display, and backups tab work unchanged.

**Phase 2 exit criterion:** nothing read-only uses SSH. Dashboard feels faster.

### Phase 3 — Migrate backup operations (2-3 days)

1. Implement `backups/database`, `backups/project` (streaming NDJSON).
2. Implement `backups/fetch` (binary stream) — replaces the SCP fetch step.
3. Implement `backups/delete`.
4. Rewrite corresponding `JobCommandBuilder` methods.
5. Preserve existing cloud-upload behavior (encryption, target upload) — those already run node-side via `node_uploader.php`, so they compose naturally with the API endpoint rather than with an SSH shell.

### Phase 4 — Migrate upgrade operations (1-2 days)

1. Implement `upgrade/apply`, `upgrade/refresh_archives`, `upgrade/dry_run` (streaming).
2. Rewrite `build_apply_update`, `build_refresh_archives`.
3. Special handling: `upgrade/apply` may upgrade the API itself. The endpoint must respond with a final `done` event *before* any process restart. If the new code changes the management API contract, the control plane falls back to SSH for the first post-upgrade health check.

### Phase 5 — Migrate destructive/restore operations (2-3 days)

1. Implement `database/copy`, `database/restore`, `project/restore`.
2. `database/copy` is the interesting one — currently it uses SSH + SCP to stream a pg_dump from source to target. Replacement: source node exposes a read endpoint that streams the dump; target node exposes a write endpoint that ingests it; control plane orchestrates the two-sided stream. This eliminates the intermediate SCP hop.

### Phase 6 — Polish (1 day)

1. Remove dead SSH helper methods from `JobCommandBuilder` where the only callers migrated.
2. Update `docs/server_manager.md` and `docs/api.md` with the new architecture and endpoint catalog.
3. Add a short section to `docs/api.md` distinguishing user keys from management keys.
4. Remove any now-unused scripts or wrappers whose only purpose was SSH invocation.

## Error handling

Every endpoint returns either:

**Success (non-streaming):**
```json
{
  "api_version": "1.0",
  "success_message": "...",
  "data": { ... }
}
```

**Failure:**
```json
{
  "api_version": "1.0",
  "error": "Human-readable message",
  "error_code": "MACHINE_READABLE_CODE",
  "data": null
}
```

HTTP status follows:
- `200` — success
- `400` — malformed request, invalid scope, bad parameter
- `401` — authentication failed
- `403` — authenticated but insufficient scope
- `404` — endpoint not found, or resource (backup file, etc.) not found
- `409` — conflict (e.g., another job of the same kind already running on this node)
- `500` — unexpected server-side failure
- `504` — downstream operation timed out

## Testing

### Unit tests
- Auth: management key accepted on `/management/*`, rejected on `/{Class}/*`; user key accepted on CRUD, rejected on `/management/*`.
- Scope enforcement: a `read` key calling `backups/database` returns 403.
- Endpoint handlers: test each one in isolation with mocked node state.

### Integration tests
- `tests/integration/management_api/` — full round-trip from a control plane test harness to a local Joinery install.
- Each endpoint has a happy-path test and a failure test (wrong scope, bad input, underlying operation fails).

### Agent tests
- Go agent `api` step test: mocked HTTP server, verify headers, streaming behavior, timeout.
- Go agent `api` step against a real Joinery instance — end-to-end smoke test runnable from CI.

### Manual
- After each phase, exercise the migrated operations in the Server Manager dashboard against the two docker-prod sites (`empoweredhealthtn` and `scrolldaddy`) and verify parity with pre-migration behavior.

## Open questions (resolve during implementation)

1. **Separate table or column?** `stg_management_api_keys` vs `apk_key_type` on `stg_api_keys`. Current lean: separate table, because identity model differs (no user FK). Defer until Phase 1.
2. **Encryption at rest for control-plane-stored secrets.** What's the encryption-key source? Ideally the same mechanism backing `bkt_credentials`. Audit that and document.
3. **What counts as a "recent error" for `errors/recent`?** Time window? Pattern match? Start with "last 20 lines containing `Fatal|Exception|Error`" — mirrors current SSH behavior — and iterate.
4. **Rate limiting.** The management API runs operations that take real resources (backups, upgrades). Phase 1 should reject requests when another job of the same type is already running (409 Conflict), but a request-rate limit per key is also worth considering in Phase 6.

## Docs to update

Per project convention (memory: "Always add developer docs to the relevant existing /docs/ file when writing specs"):

- **`docs/server_manager.md`** — add a "Management API" section explaining the `api` step type, how keys are provisioned, and which operations are API vs SSH. Update the "How It Works" section to describe `ssh`/`scp`/`local`/`api` as the four step types.
- **`docs/api.md`** — add a section distinguishing `user` keys (public CRUD) from `management` keys (infrastructure), document scopes, and link out to `server_manager.md` for the endpoint catalog (catalog lives there, not in api.md, because it's server-manager-specific).

## Success criteria

After all phases:

1. A fresh `/admin/server_manager` dashboard load with N nodes issues N parallel HTTPS calls to `stats` and renders in under 2 seconds. Today's SSH-based path is ~1 second per node sequentially.
2. Backup, upgrade, and restore operations run with the same reliability as the current SSH path, with live streamed progress visible in the job detail page.
3. `git grep` for `'type' => 'ssh'` in `JobCommandBuilder.php` returns only steps that *genuinely require* SSH (install, discover, host-level Docker metrics) — everything else is `'type' => 'api'`.
4. A managed-services client can be given a `backup` or `read` scoped key and be unable to escalate from it.
