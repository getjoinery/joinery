# Server Manager One-Click Node Install

## Overview

Add a one-click node provisioning flow to the Server Manager that installs a fresh Joinery site on a remote SSH-accessible server. The admin chooses between two modes:

1. **Fresh install** — empty Joinery site, default schema, new admin user, admin picks the target domain
2. **Install from backup** — fresh site provisioned with the **source's** domain, then a backup of the source node is restored on top (DB + uploads + static files). Result is a functionally identical clone; admin swaps DNS to cut over.

Both modes end with the new node registered and the control plane's existing joinery-agent successfully completing a `test_connection` job against it, at which point the node appears online in the Server Manager dashboard.

### Architecture note

The joinery-agent runs on the control plane, not on managed nodes. It polls the control plane's local `mjb_management_jobs` table and SSHes out to targets to execute job steps. A new node therefore requires no agent install — only site provisioning + registration as a `ManagedNode` row. The control plane's existing agent automatically picks up jobs for the new node on its next poll.

## Motivation

Today, bringing up a new managed node is multi-step and manual: SCP `install.sh` to the target, run it, then register the node in the UI. Every step works non-interactively already — the scripts support it — but nothing ties them together. This spec threads the existing pieces into a single job.

## What Already Exists (Reuse, Don't Rebuild)

- **`install.sh -y -q site`** (`maintenance_scripts/install_tools/install.sh`) — non-interactive provisioning of Apache/PHP/Postgres, site directories, database, Apache vhost, cron entries. Supports Docker and bare-metal auto-detection. Used for fresh install in both modes.
- **Backup/restore job primitives** — `backup_database`, `backup_project`, `fetch_backup`, and `restore_database` already exist in `JobCommandBuilder.php`. From-Backup mode composes these around `install.sh`.
- **Control-plane joinery-agent** — already polls the local DB and SSHes out to managed nodes; will automatically service the new node once its `ManagedNode` row exists.
- **`JobCommandBuilder.php`** — step-based SSH/SCP/local job framework with 13+ existing job types including `test_connection`.
- **Per-node job locking + job output streaming** — already handles concurrency and surfacing remote stdout/stderr to the UI.

**Explicitly not used:** `utils/clone_export.php` and `install.sh --clone-from/--clone-key`. The clone feature remains in the codebase for manual/external cloning scenarios but plays no role in this flow.

Nothing above needs changing. The work is a new job type and a UI form.

## User Flow

1. Admin clicks **"Install New Node"** on `/admin/server_manager`.
2. Form prompts for:
   - SSH host / user / key path / port
   - Site name (becomes DB name + `/var/www/html/$SITENAME/`)
   - Install mode: **Fresh** or **From Backup**
   - If Fresh: primary domain
   - If From Backup:
     - Source node (dropdown of existing managed nodes) — target inherits source's domain automatically; no domain input
     - Backup selection: **"Take fresh backup now"** (adds a backup job as the first step) or **"Use existing backup"** (dropdown populated from `mgn_last_backup_list` on the source node)
   - Docker vs bare-metal (auto-detect by default; override available)
3. Admin clicks **Install**. System creates a `ManagedNode` record (status: `installing`) and queues an `install_node` job.
4. Job runs; output streams to the node detail page.
5. On job success, the system auto-queues a `test_connection` job against the new node. When that succeeds, the node is marked `online`. On any failure, the node is marked `install_failed` and output is preserved for debugging.

## Schema Change

Add one nullable column to `ManagedNode`:

- `mgn_install_state` — `varchar(20)`, nullable. Values: `installing`, `install_failed`, `NULL` (normal/post-install). Added via `$field_specifications` in `managed_node_class.php`; picked up by the next `update_database` run.

During install, the column is `installing`. On failure it becomes `install_failed`. On success (after `test_connection` passes) it is cleared to `NULL` and the node participates in normal heartbeat/status-check flows. This field is purely for the install lifecycle — runtime liveness continues to use `mgn_last_status_check` / `mgn_last_status_data`.

## New Job Type: `install_node`

Added to `JobCommandBuilder.php`. Both modes share the fresh-install spine; From-Backup adds a backup-fetch-restore wrapper around it.

### Fresh mode steps

1. **Pre-flight** (local): verify installer artifacts exist — `install.sh`, `_site_init.sh`, `default_Globalvars_site.php`, `default_virtualhost.conf`, `joinery-install.sql.gz`. Fail fast if any are missing.
2. **SCP install_tools** to `/tmp/joinery_install/` on target.
3. **SSH: run install.sh**: `install.sh -y -q site $SITENAME --domain=$DOMAIN`
4. **Cleanup**: remove `/tmp/joinery_install/`.
5. **Post-install verification**: auto-queue a `test_connection` job against the new node. On success, mark node `online`. On failure, mark `install_failed`.

### From-Backup mode steps

1. **Pre-flight** (local): same as Fresh, plus verify the chosen backup exists on the source node (or validate "take fresh backup now" option). Read `$SOURCE_DOMAIN` from the source node's `ManagedNode` record.
2. **(Optional) Take fresh backup on source**: run existing `backup_database` + `backup_project` jobs against the source node. Skip if admin chose an existing backup.
3. **Fetch backups to control plane**: use existing `fetch_backup` job to pull the DB dump + project tarball from the source node to the control plane's local filesystem.
4. **SCP install_tools to target** and **run install.sh** with `--domain=$SOURCE_DOMAIN` (the source's domain, not a new one — the goal is a drop-in replica). Cleanup install_tools.
5. **SCP backup files to target**: transfer DB dump + uploads/static_files tarballs to `/tmp/joinery_restore/`.
6. **SSH: restore DB**: run the same logic as existing `restore_database` job. Auto-backup the empty fresh DB first (standard behavior), then restore the source backup on top.
7. **SSH: extract uploads/static_files**: `tar -xzf` tarballs into the appropriate target directories. Fix permissions via `fix_permissions.sh`.
8. **Cleanup**: remove `/tmp/joinery_restore/`.
9. **Post-install verification**: same as Fresh — queue a `test_connection`.

Failures at any step mark the node `install_failed` and leave artifacts on the target for debugging.

## Domain Handling (From-Backup Mode)

The target is provisioned with the **source's** domain. `install.sh` is invoked with `--domain=$SOURCE_DOMAIN`, which sets up the Apache vhost for the source domain. The restored DB already contains the source domain in its settings, so everything stays internally consistent. No rewriting is needed or performed.

**Implication for the admin:** the new node initially serves at the source's domain but DNS still points at the original server. Admin performs DNS cutover when ready. `install.sh` skips certbot if DNS doesn't yet resolve to the new server; after cutover, admin runs `sudo certbot --apache -d $SOURCE_DOMAIN` on the target.

**Out of scope for v1:** cloning a site to a **different** domain (e.g., `prod.example.com` → `staging.example.com`). This would require both Apache vhost reconfiguration and DB-wide URL rewriting, neither of which are trivial to automate safely. Admins needing a same-backup-different-domain workflow should do a Fresh install and restore content manually.

## Credentials

### Fresh install
- **Web admin**: log in as `admin@example.com` / `changeme123`. `usr_force_password_change` is `true` for this user (set in `create_install_sql.php:277`), so the admin is redirected to the forced-change page on first login and must set a new password before proceeding. All existing behavior — no new Server Manager code.
- **Postgres DB password**: auto-generated by `install.sh`, written to `Globalvars_site.php` on the target. The admin retrieves it via SSH if/when they need direct DB access. The Server Manager does not capture or display it.

### From-Backup install
- **Web admin**: use the source site's existing admin credentials. The restored DB replaces whatever default admin `install.sh` created.
- **Postgres DB password**: same as Fresh — auto-generated and lives in `Globalvars_site.php` on the target.

### Success screen
Short, mode-aware message. For Fresh: "Log in at https://$DOMAIN with `admin@example.com` / `changeme123` — you'll be asked to set a new password." For From-Backup: "Log in with your existing source-site admin credentials." No passwords are displayed or persisted by Server Manager in either mode.

### Secrets note
Joinery stores API keys, OAuth tokens, webhook secrets, and SMTP passwords as plaintext in `stg_settings` (verified during spec review). Backups restore onto any node cleanly with no re-entry required.

## Artifact Staging

The job reads installer artifacts from the control plane at the sibling-of-public_html path:

```
dirname($public_html_root) . '/maintenance_scripts/install_tools/'
```

This layout is guaranteed by `install.sh` and `deploy.sh` on every Joinery deployment, so no configuration is needed. The control plane knows its own `public_html` path from `PathHelper`. If this ever needs to be overridable (e.g., shared `install_tools/` across multiple sites on one host), add an `install_tools_path` setting as a fallback — v1 does not need it.

## UI Changes

- **`views/admin/index.php`**: replace the "Manual install" instructions block with an **"Install New Node"** button that opens the form modal.
- **New view**: `views/admin/install_node_form.php` — form described in User Flow step 2.
- **Node detail page**: show `install_failed` state prominently. **"Retry install"** re-queues the same job with the existing node record, but requires the target to be clean (same rules as a fresh install — `install.sh` will refuse if the site directory already exists). If the previous attempt got far enough to create the site, admin must SSH to the target and clean up manually before retrying. UI makes this explicit with a confirmation dialog listing what to clean up.

## Security Considerations

- SSH key stays on the control plane; never transmitted to the target.
- Backup files transit control plane → target via SCP over SSH (encrypted in transit). Backup files on the control plane are temporary; remove after successful restore.
- The install job runs as root on the target (required for apt install, systemctl, Apache config). Document this as a prerequisite — the SSH user must have passwordless sudo or be root.

## Failure Modes

| Failure | Handling |
|---|---|
| SSH unreachable | Fail in step 2, mark `install_failed`, preserve error |
| `install.sh` fails mid-run | Leave target in partial state; show install.sh output; admin fixes manually or destroys VM |
| Backup file missing or corrupt on source | `fetch_backup` fails early; nothing touched on target |
| `test_connection` fails after install | Mark node `install_failed`; keep artifacts; surface SSH error in job output |
| Pre-existing site at `/var/www/html/$SITENAME` | `install.sh` refuses; surface its error (don't try to force) |
| DNS for domain doesn't resolve | `install.sh` proceeds HTTP-only; success message instructs admin to run certbot when DNS is ready |

## Out of Scope

- Provisioning the bare VM itself (creating the cloud instance). Admin brings an SSH-reachable server.
- Live cloning from a running source (the `utils/clone_export.php` flow). Stays in the codebase for manual use but is not wired into Server Manager.
- From-Backup to a **different** domain than the source. Target always inherits the source's domain in v1.
- DNS configuration. Admin points the domain at the new server manually before or after install.
- TLS/Let's Encrypt. `install.sh` already handles certbot if domain resolves; otherwise admin runs it post-install.

## Testing Plan

1. **Fresh install on a throwaway VM** — verify site loads, `test_connection` passes, admin can log in with `changeme123` and is forced to change password.
2. **From-Backup install** — restore from a recent backup of a small existing managed node. Verify DB row counts match the source, uploads present, `stg_settings.site_url` still points at the source domain (unchanged), admin can log in using source credentials via IP or local hosts-file override.
3. **From-Backup with "take fresh backup now"** — same as #2 but adds the backup step at the front; verify the backup appears on the source.
4. **Failure injection** — bad SSH key, missing backup file, pre-existing site dir on target.
5. **Re-run after failure** — "Retry install" button on an `install_failed` node (with target cleaned up).
6. **Docker target** — run against the `docker-prod` box; verify `--docker-mode` path in `_site_init.sh` triggers.

## Estimated Effort

~1-2 days. Most of the work is:

- `JobCommandBuilder::build_install_node()` — Fresh + From-Backup variants (~120 lines)
- Install form view + controller (~120 lines)
- Node detail state handling for `installing` / `install_failed` (~30 lines)
- `mgn_install_state` schema addition + admin list rendering (~10 lines)
- Docs update in `docs/server_manager.md`

No new agent code. No new install script.
