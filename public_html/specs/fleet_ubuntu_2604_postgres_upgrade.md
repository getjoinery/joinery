# Fleet Upgrade: Ubuntu 26.04 LTS + PostgreSQL 18

**Status:** Draft — awaiting owner decisions (see Open Decisions)
**Date:** 2026-08-01

## Problem

The entire fleet runs Ubuntu 24.04 LTS with the distro-default stack: PostgreSQL 16, PHP 8.3, Apache 2.4.58. PostgreSQL 16 lacks incremental base backups (`pg_basebackup --incremental` + `pg_combinebackup`, added in 17), which blocks the database-incremental phase of `specs/backups_core_and_incremental.md`. Rather than bolting PG 17 from the PGDG repository onto 24.04 — a second package source to maintain on every node forever — moving to Ubuntu 26.04 LTS gets a supported distro-default PostgreSQL ≥ 18 (which includes the incremental backup feature set), a current PHP, and resets the LTS clock in one campaign.

## Goals

1. Every Ubuntu box in the fleet on 26.04 LTS with distro-default PostgreSQL and PHP.
2. Installer and Docker base image target 26.04, so new nodes come up on it with no manual steps (zero-config principle unchanged).
3. No mixed-version PostgreSQL hazards left standing (a PG 16 `pg_dump` cannot dump a PG 18 server — `copy_database` between mixed nodes would fail).
4. The upgrade path itself is the one operators would use for real: prefer rebuild-from-backup over in-place mutation wherever a box is rebuildable, so the campaign doubles as a fleet-wide DR exercise.

## Current Pins (what hardcodes the old stack)

| Pin | Location |
|---|---|
| Server install hard-fails off Ubuntu 24.04 | `maintenance_scripts/install_tools/install.sh:1722` |
| PHP 8.3 package names and paths hardcoded throughout server setup | `install.sh:1800` onward (packages, fpm pool paths, ini paths) |
| Docker base image | `maintenance_scripts/install_tools/Dockerfile.base:17` — `FROM ubuntu:24.04`; version tag `BASE_IMAGE_VERSION` at `install.sh:119` |
| PostgreSQL | unpinned — `apt install postgresql` (`install.sh:1887`), version detected dynamically (`install.sh:1909`) |
| Linode quick-deploy | `install_tools/linode_stackscript_wrapper.sh` + the deploy image selected on the Linode side — verify at build time |
| Go agent | no pin — static binary, unaffected; re-verify heartbeat post-upgrade only |

## Phase 0 — Verify Target Stack and App Compatibility

Exact shipped versions must be confirmed, not assumed (expected: PostgreSQL 18, PHP 8.4 or 8.5, Apache 2.4.x):

1. Bring up a scratch 26.04 container/VM; record `postgresql`, `php`, `apache2` versions from the default archive.
2. Run `install.sh` dev/server setup against it (after the Phase 1 parameterization) and stand up a site from a dev backup.
3. Run the full test estate (`safe` + `db` tiers) under the new PHP. Sweep deprecations — PHP ≥ 8.4's implicitly-nullable-parameter deprecation (`function f(Type $x = null)`) is the likely bulk item in a codebase this size; fix warnings at the source, not by suppressing.
4. Composer dependency audit under the new PHP version (`composerAutoLoad` vendor tree rebuilt on-target).
5. Confirm PostgreSQL 18 restores a PG 16 dump cleanly (it does by design; verify with a real site dump) and that the app's SQL surface raises nothing (the db tier is the gate).

## Phase 1 — Installer and Image Work

1. **Parameterize PHP version** in `install.sh`: detect the distro's PHP (`php -v` / package resolution) once, derive package names, fpm service name, pool and ini paths from it. Remove the 8.3 literals.
2. **OS gate**: accept 24.04 and 26.04 during the transition; hard-fail on anything else. After the fleet is migrated, drop 24.04.
3. **`Dockerfile.base`**: `FROM ubuntu:26.04`, bump `BASE_IMAGE_VERSION`. Existing site containers keep running on the old base until their host's migration slot (Phase 2).
4. **Linode stackscript**: point the deploy at the 26.04 image; run the existing live gates from `specs/` for the quick-deploy app on the new image.
5. Keep `backup_project.sh` / `restore_project.sh` untouched — they are version-agnostic; the PG major bump is handled by dump-before/restore-after, which is already their contract.

## Phase 2 — Fleet Migration

Two procedures; per-node choice recorded in Open Decisions:

**A. Rebuild-from-backup (default for rebuildable nodes).** Fresh 26.04 node → `install.sh` → install-from-backup provisioning (the existing server_manager path) → verify → cut DNS/traffic → decommission old node. The PG 16→18 data move is just the dump in the backup. This is the DR drill run for real, node by node.

**B. In-place (`do-release-upgrade`) — for boxes whose identity is expensive** (IP reputation, DNS glue):
- Take a full backup first (non-negotiable gate; provider snapshot too where available).
- `do-release-upgrade` 24.04 → 26.04.
- PostgreSQL: `pg_upgradecluster 16 main` (Debian wrapper migrates config and swaps ports), then drop the 16 cluster and packages.
- PHP: the fpm pool/vhost references move to the new version's paths — same edits Phase 1 automated for the installer, applied once by hand or by a small migration script.

**Docker hosts:** host OS via A or B; then rebuild `joinery-base` on 26.04 and recreate each site container. Container PG data volumes hold a PG 16 data directory that PG 18 cannot open in place — recreate containers via install-from-backup (dump/restore), not by remounting the old volume.

**Ordering within the fleet:**
1. Scratch box (Phase 0).
2. Dev box / control plane (in-place; it is the one box that is not rebuild-from-backup friendly today).
3. One expendable managed node via procedure A — this validates the whole rebuild path.
4. Remaining web nodes and Docker hosts, one at a time; each must pass its gates before the next starts (serial, per the one-at-a-time working rule).
5. Special boxes last, each with its own smoke test:
   - **jeremytunnell mail box:** procedure B (IP reputation must survive). Announce/accept a mail-delivery pause; verify inbound store, DKIM signing, and relay flow afterward.
   - **relay1:** procedure B; verify relay hold/forward for scrolldaddy.app.
   - **ScrollDaddy DNS servers:** procedure B; verify resolver + DoH end-to-end (the cert-outage runbook's verification steps).

**Mixed-version window:** while 16 and 18 coexist, do not run `copy_database` between mixed-version nodes (newer-server/older-client dumps fail). Node-local backups stay safe throughout since each node dumps with its own client.

## Verification Gates (every node, both procedures)

- `php tests/run.php deploy` on the node (never the safe tier on a node).
- Agent heartbeat green; a `check_status` job round-trips.
- One backup job + one restore round-trip on the upgraded stack.
- Site smoke: front page, login, admin dashboard over HTTPS.
- Role-specific smoke for mail/relay/DNS boxes as above.

## Rollback

- Procedure A nodes: the old node stays up untouched until gates pass; rollback = don't cut over.
- Procedure B nodes: provider snapshot + the pre-upgrade full backup; rollback = restore snapshot (or rebuild on 24.04 from backup — the installer keeps 24.04 support through the transition for exactly this).

## Out of Scope

- Mac build boxes (not Ubuntu; separate lifecycle).
- PGDG-pinned PostgreSQL on 24.04 (rejected: permanent extra package source for a stack we're leaving).
- App-level PostgreSQL 18 feature adoption (incremental backups land via `specs/backups_core_and_incremental.md`, not here).

## Documentation (update at build time, current-state only)

- `docs/deploy_and_upgrade.md`: supported OS/stack statement.
- Server Manager overview: node OS expectations, mixed-version `copy_database` rule if any window remains.
- Install docs / README wherever 24.04 is named.

## Open Decisions

1. **Per-node procedure map** — proposed: rebuild (A) for all standard web/docker nodes; in-place (B) for dev box, mail box, relay1, DNS servers. Confirm, and confirm the expendable node for the first A run.
2. **Timing vs. the backups spec** — recommended: backups Phases 1–2 (envelope keys + core self-backup with retention) land first, so the rebuild-from-backup campaign runs on the improved backup engine; this OS campaign then unblocks backups Phase 4 (DB incrementals).
3. **Mail pause window** for the jeremytunnell box upgrade.
4. **Drop-24.04 date** for the installer gate once migration completes.
