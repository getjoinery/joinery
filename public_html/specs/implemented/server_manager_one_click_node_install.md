# Server Manager One-Click Node Install

**Status:** Implemented (Fresh mode validated end-to-end; From-Backup scaffolded but untested).

## Overview

A one-click provisioning flow in the Server Manager that takes a bare Ubuntu 24.04 host with SSH root access and turns it into a running Joinery node. Two modes:

1. **Fresh install** — empty Joinery site with default schema; admin picks the target domain.
2. **Install from backup** — fresh site provisioned with the source's domain, then a backup of the source is restored on top. Result is a functional clone; admin cuts DNS over.

Admin fills a form on `/admin/server_manager/install_node_form`, the system creates a `ManagedNode` record with `mgn_install_state='installing'`, queues an `install_node` job, and streams output to the job-detail page. On success `mgn_install_state` clears; on failure it becomes `install_failed` and a Retry button appears on the node detail page.

## Architecture note

The joinery-agent runs on the control plane, not on managed nodes. It polls `mjb_management_jobs` and SSHes out to targets. A new node therefore requires no agent install — only site provisioning plus a `ManagedNode` row. The agent automatically picks up jobs for the new node on its next poll.

## How it works (actual build)

### Artifact delivery — curl from the control plane

The target fetches the full Joinery release tarball from `https://<control_plane>/utils/latest_release` (a 302 redirect to the most recent `joinery-core-X.Y.Z.tar.gz` in `/static_files/`, produced by `publish_upgrade.php`). This is the same URL used by the documented one-liner install. Nothing is packaged on the control plane at install time — no local tarball, no SCP of install_tools. The control plane's URL is derived from the `webDir` setting in `Globalvars_site.php`.

If no release has been published (empty `upg_upgrades` table), the pre-flight step fails with the HTTP status returned by `/utils/latest_release`.

### install.sh subcommands actually used

`install.sh` has four subcommands. A bare-server install chains two of them:

- **`install.sh -y -q docker`** — installs Docker CE. Idempotent: short-circuits if Docker is already installed.
- **`install.sh -y -q server`** — installs Apache + PHP 8.3 + PostgreSQL + Composer + Certbot + fail2ban + UFW + SSH hardening. Requires `POSTGRES_PASSWORD` env var. **Not idempotent**: `apt upgrade -y`, overwrites `pg_hba.conf`, and resets the `postgres` role password. Running it again breaks any existing site. Must be skipped when prereqs are already present.
- **`install.sh -y -q site [--docker|--bare-metal] SITENAME [PASSWORD|-|--password-file=PATH] [DOMAIN] [PORT] [--no-ssl]`** — creates a site (DB, site directory, Apache vhost, cron). Requires prereqs. Without `--no-ssl`, it does early DNS validation and **fails hard** if the domain doesn't resolve to the target's IP — so the one-click flow always passes `--no-ssl`. Admin runs `sudo certbot --apache -d DOMAIN` after DNS cutover.
- **`install.sh -y -q list`** — not used.

### User1 switch — working around SSH hardening mid-job

`install.sh server` disables `PermitRootLogin` and restarts sshd, which would lock the agent out. Before running server setup we pre-stage `user1`:

1. Create `user1` if missing, copy `/root/.ssh/authorized_keys` into `/home/user1/.ssh/authorized_keys`, and write `user1 ALL=(ALL:ALL) NOPASSWD: ALL` to `/etc/sudoers.d/user1`. Refuses to proceed if `/root/.ssh/authorized_keys` is empty — otherwise we'd lock out root with no fallback.
2. Run a `local` SQL step that updates the `ManagedNode`'s `mgn_ssh_user` to `user1`.
3. sshd restart kills the pooled root connection; the agent's next SSH reconnect comes in as `user1` via the updated `NodeConnInfo`.
4. All subsequent SSH steps prefix `sudo` (no-op as root, required as user1 with NOPASSWD).

Docker mode skips all of this — `install.sh docker` doesn't harden SSH, so the root connection stays intact.

### Postgres password pinning

`_site_init.sh` uses the site's `$PASSWORD` as `PGPASSWORD` when running `createdb -U postgres`, which means the site's DB password must match the `postgres` role password set by `install.sh server`. Passing `-` to `install.sh site` auto-generates a fresh password that doesn't match, causing `createdb` to silently auth-fail and the schema load to skip. Fix: pass `--password-file=/root/.joinery_postgres_password` so both use the same value.

When prereqs are already installed but `/root/.joinery_postgres_password` doesn't exist (host was set up manually), the prereq step harvests the password from an existing site's `Globalvars_site.php` — grep `dbpassword` from the first file found under `/var/www/html/*/config/`.

### Step order (Fresh mode)

1. **Pre-flight (local)** — HEAD-check `/utils/latest_release` returns 200 or 302.
2. **Ensure curl (SSH)** — `command -v curl || sudo apt-get install -y curl`.
3. **Download and extract release (SSH)** — `sudo rm -rf /tmp/joinery_install && sudo mkdir -p /tmp/joinery_install && curl -sL URL | sudo tar xz -C /tmp/joinery_install && sudo chmod +x .../install_tools/*.sh`.
4. **Pre-stage user1 (SSH, bare-metal only)** — see above.
5. **Switch SSH user to user1 (local, bare-metal only)** — direct SQL update via a local `psql` step that reads control-plane DB creds from `config/Globalvars_site.php`.
6. **Install prereqs (SSH)** — Docker mode: always run `./install.sh -y -q docker` (idempotent). Bare-metal mode: check `command -v apache2 && psql && php`; if all present, skip server step and harvest the postgres password from an existing site config. Otherwise generate a postgres password with `openssl rand`, save to `/root/.joinery_postgres_password`, run `sudo -E ./install.sh -y -q server`.
7. **Create the site (SSH)** — `cd install_tools && sudo ./install.sh -y -q site --bare-metal|--docker SITENAME --password-file=/root/.joinery_postgres_password DOMAIN [PORT] --no-ssl`. Docker mode uses `-` instead of `--password-file` (Docker Postgres is fresh, no shared role password).
8. **Cleanup (SSH)** — `sudo rm -rf /tmp/joinery_install`, continue-on-error.
9. **Verify (SSH)** — `echo INSTALL_SUCCESS && hostname && sudo test -f /var/www/html/SITENAME/config/Globalvars_site.php && echo CONFIG_OK`.

`JobResultProcessor::process_install_node` runs on both `completed` and `failed` states; clears `mgn_install_state` on success (with `INSTALL_SUCCESS` sentinel in output) or sets it to `install_failed` on failure.

### From-Backup mode

Scaffolded in `JobCommandBuilder::build_install_node()` but not tested end-to-end in this iteration. Adds these steps around the Fresh spine:

- Before release download: dump source DB + archive source project files on the source node (unless admin chose an existing backup), then SCP-download both to the control plane.
- After site creation: SCP-upload backups to target, drop+restore the site DB, extract project files with `--exclude=config/Globalvars_site.php`, run `fix_permissions.sh`.
- Target always inherits the source's domain; no URL rewriting. Admin cuts DNS over after install.

Validating From-Backup end-to-end is tracked as a follow-up.

## Schema

One nullable column added to `mgn_managed_nodes` via `$field_specifications` in `managed_node_class.php`:

- `mgn_install_state` — `varchar(20)` nullable. Values: `installing`, `install_failed`, `NULL` (normal). Picked up by the next `update_database` run.

## Files touched

**New:**
- `plugins/server_manager/views/admin/install_node_form.php` — install form (SSH creds, site name, domain, Docker/bare-metal radio + port, fresh/from-backup toggle).
- `public_html/utils/publish_theme.php` — legacy shim that `require_once`s the plugin's version, so release tarballs calling `/utils/publish_theme?list=themes` stop returning 404.

**Modified:**
- `plugins/server_manager/data/managed_node_class.php` — added `mgn_install_state` field spec.
- `plugins/server_manager/includes/JobCommandBuilder.php` — added `build_install_node()` and `_update_node_ssh_user_cmd()` helpers.
- `plugins/server_manager/includes/JobResultProcessor.php` — added `process_install_node()`.
- `plugins/server_manager/ajax/job_status.php` — processor now runs on both `completed` and `failed` terminal states (was completed-only).
- `plugins/server_manager/views/admin/index.php` — replaced manual install instructions with **Install New Node** button; install-state badges on node cards; extended unprocessed-job catch-up to include `install_node` + `failed` status.
- `plugins/server_manager/views/admin/node_detail.php` — install-state banner + Retry Install button with cleanup-required confirmation dialog.
- `maintenance_scripts/install_tools/_site_init.sh` — fixed ordering bug: create `${SITENAME}_test/` directory **before** enabling + reloading Apache vhost (the template references that dir, so reload was failing on fresh installs).
- `public_html/theme/falcon/theme.json` — `"deprecated": true` (drops it from `publish_theme` catalog and from `publish_upgrade` bundling).
- `public_html/theme/default/theme.json` — `"is_system": true` so `install.sh`'s system-theme download loop always includes it.
- `public_html/utils/create_install_sql.php` — default `theme_template` changed from `'falcon'` to `'default'`.
- `docs/server_manager.md` — documented the new `install_node` job type.

## Credentials

**Fresh install — web admin:** `admin@example.com` / `changeme123` with `usr_force_password_change=true` (unchanged existing behavior from `create_install_sql.php:277`). First login forces a password change.

**From-Backup install — web admin:** source site's existing admin credentials (restored DB replaces whatever default admin `install.sh` created).

**Postgres DB password:** auto-generated by `install.sh`, written to `Globalvars_site.php` on the target, mirrored to `/root/.joinery_postgres_password` for multi-site reuse. Server Manager does not capture or display it.

## Known gaps / follow-ups

- From-Backup mode not end-to-end tested.
- No automated post-install theme/plugin sync. Fresh installs work because `install.sh`'s theme-download loop pulls the system themes (`default`, `joinery-system`), which is enough to render. A `php utils/upgrade.php` on the target post-install would pull the full catalog but isn't wired in.
- `install.sh`'s `is_system` theme filter has a latent bug — the catalog JSON is single-line, so `grep -A5` doesn't isolate per-theme metadata and the filter effectively downloads all themes. Harmless but wasteful; fixing it is a separate cleanup.
- Version/requirements enforcement across themes and plugins is broken in multiple ways. Being addressed in a separate spec: `versioning_rationalization.md`.
- Retrying an install on a host where `install.sh server` has already run needs the node's `mgn_ssh_user` pre-set to `user1` (root SSH is disabled). The first install sets this automatically; a *fresh* install queued against a previously-hardened host via a *new* `ManagedNode` row will fail at step 2 until the admin manually switches the user. Making this detect-and-recover is a small follow-up.

## Bugs found and fixed during implementation

1. **`install.sh site` doesn't install prereqs** — original spec assumed it did. Added separate `install.sh docker` / `install.sh server` step.
2. **SCPing only `install_tools/` was incomplete** — `install.sh site` needs `public_html/` and `config/` at the archive root too. Switched to curl-on-target.
3. **`install.sh server` disables root SSH mid-job** — pre-stage `user1` and switch `mgn_ssh_user` before the hardening runs.
4. **`install.sh` early DNS validation fails hard when DNS doesn't resolve** — original spec said it degraded gracefully, but it doesn't. Always pass `--no-ssl`.
5. **Auto-generated site password didn't match postgres role password** — pass `--password-file=/root/.joinery_postgres_password`.
6. **`_site_init.sh` ordering bug** — vhost enabled before `_test/` dir existed, reload failed. Fixed.
7. **`utils/publish_theme.php` deleted in prior refactor** — restored as legacy shim; release-tarball `install.sh` depends on it.
8. **Default theme was `falcon` but falcon wasn't downloaded on install** — marked `falcon` deprecated, switched default to `default` (a vanilla HTML5 theme now marked `is_system` so `install.sh` always pulls it).
