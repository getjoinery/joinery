# Agent Files Management

## Problem

Agent instruction files (`CLAUDE.md`, `GEMINI.md`, eventual `AGENTS.md`) are currently maintained as files in git. Three issues:

1. **Duplication** — files that should be identical require parallel edits.
2. **Internal content leakage** — our internal agent files reference admin credentials, internal infrastructure, and content that should not ship to customers, but they sit alongside the shipped codebase.
3. **No customization path for customers** — a customer deploying the platform has no clean way to maintain their own agent rules.

## Solution

Manage agent files as admin-managed content in the database, not as in-git artifacts. The file on disk becomes an output written from a db row on demand. Internal agent files live only in our test site's database and never enter git, eliminating leakage by construction.

## Goals

- One source of truth per agent file (the db row).
- Internal variants never enter the git tree.
- Customers create, edit, and write their own agent files entirely through admin UI.
- Multiple target filenames per row, so one row can drive both `CLAUDE.md` and `GEMINI.md` from the same content.

## Non-goals

- Install-time seeding via `update_database` or migrations. Schema is created automatically from the class spec; rows are seeded only via the bootstrap SQL (see Phase 2) or admin UI. Dev-environment seeding lives in `dev_setup_install_script.md`.
- Conflict-detection or merge logic for files edited directly on disk. The db row is authoritative; disk edits are overwritten on the next regenerate.
- Per-plugin contributions to agent file content.
- Fragment composition. Deferred until a third variant or audience emerges to justify the assembly machinery.
- Customer `*.local.md` overlay convention. Customers edit their row directly.

## Design

### Storage

A new `stg_agent_files` table, data class `AgentFile` extending `SystemBase`. Suggested fields:

- `agf_id` (pkey)
- `agf_name` — short identifier (e.g., "Internal CLAUDE.md", "Customer baseline")
- `agf_target_filenames` — JSON array of target filenames written to project root (e.g., `["CLAUDE.md", "GEMINI.md"]`)
- `agf_content` — longtext, the file body
- `agf_last_written_time` — timestamp of last successful write-to-disk
- `agf_last_written_hash` — sha256 of content at last write (used for disk-sync indicator)
- Standard `create_time`, `update_time`, `delete_time`

The JSON-array target field handles the common "CLAUDE.md and GEMINI.md identical" case without content duplication.

### Target filename uniqueness

Target filenames must be unique across all non-deleted rows: a given filename appears in at most one active row's `agf_target_filenames` array. Enforced by application-level validation at save time — any save that would result in two active rows sharing a target filename (create, edit, undelete) is rejected with an error message naming the conflicting row. SQL unique constraint isn't feasible because targets live inside a JSON array; the validation runs as one small query per save against the (small) `stg_agent_files` table.

### Target filename format

Each filename in `agf_target_filenames` must be:

- Non-empty.
- A bare filename — no `/`, no `\`, no `..` segment, no NUL bytes.
- May start with `.` so dotfile conventions (`.cursorrules`, `.aider.conf.yml`) work.

Agent files live at the project root. Subdirectories are not supported in this iteration; revisit if a future agent expects its file in a subdirectory. Validated at save time alongside the uniqueness check.

### Admin UI

New admin page `/admin/admin_agent_files`:

- List view: name, target filenames, last-written time, disk-sync indicator (matches / differs / never written).
- FormWriter edit/create form: name, target filenames (multi-value), content (large textarea).
- Per-row "Write to disk" action: writes content to every target in project root, updates `agf_last_written_time` and `agf_last_written_hash`.
- Per-row soft delete (standard pattern).

Permission gate: superadmin only (level 10). Agent files affect agent behavior across the installation; not a lower-tier admin concern.

### Source of truth

The db row is authoritative. The on-disk file is output, like a generated theme asset. Direct edits to the file on disk are overwritten on the next write-to-disk; the disk-sync indicator surfaces divergence but the system takes no automatic action. This is the intended model and is stated in the admin page help text.

### Lifecycle

The on-disk file is an active mirror of the db row, not a write-only output:

- **Soft-deleting a row** removes its on-disk target files immediately. The row stays in the db with `delete_time` set (standard pattern); the files do not linger.
- **Editing a row's `agf_target_filenames`** to drop an entry removes that file from disk on save. Remaining targets stay managed normally.
- **Permanent delete** of a row whose files were already removed at soft-delete time is a no-op for files; it just removes the row.
- **Undeleting a row** does not automatically rewrite files. The admin must click "Write to disk" to restore them — undelete restores the row's content, not its on-disk artifacts.
- **Missing target file at removal time** is a no-op, not an error.

The upgrade regenerate step iterates non-deleted rows only, so soft-deleted files stay gone across upgrades.

### Filesystem permissions

Write-to-disk needs Apache write access to project root. The dev permissions model (666/777) already provides this; confirm before implementation for any deployment target.

### Upgrade interaction

`upgrade.php` participates in agent file management in two ways:

**Publish-side exclusion.** `publish_upgrade.php` already excludes `CLAUDE.md` from the upgrade archive via rsync `--exclude` (around line 264). Broaden this exclusion to cover all agent file target names (`CLAUDE.md`, `GEMINI.md`, `AGENTS.md`, and any future additions). Without this, the publishing source's on-disk agent files — written there by the same admin feature — ride into customer archives and briefly land on customer disk during extraction, even if the regenerate step (below) overwrites them seconds later.

**Regeneration after swap.** After `upgrade.php` completes the live→backup→archive swap and database migrations, iterate `stg_agent_files` rows where `agf_last_written_time IS NOT NULL` and write each row's content to its target filenames. This:

- Treats the db row as authoritative; disk is freshly written from source after every upgrade.
- Naturally skips rows the customer created but never wrote to disk (the previously-written flag is the consent signal).
- Does nothing on deployments without agent file rows.
- Requires no setting toggle, no preserve-list, and no hardcoded allow-list coupling between `upgrade.php` and the admin UI.

Natural location: the post-deploy sync step inside `update_database.php` (already invoked by `upgrade.php` in a subprocess for theme/plugin sync). Agent-file sync becomes a sibling to those existing reconciliation steps and inherits the same fresh-class-definitions guarantee.

## Migration

To migrate the existing in-git `CLAUDE.md` and `GEMINI.md`:

1. Implement schema and admin UI.
2. On the test site: create a row "Internal CLAUDE.md" with target filenames `["CLAUDE.md", "GEMINI.md"]`, paste current `CLAUDE.md` content.
3. Click "Write to disk" — confirm both files appear correctly.
4. `git rm CLAUDE.md GEMINI.md`; commit.

Afterward, internal agent files exist only in our test site's database. Other dev environments seed via the dev install script.

## Dependencies

- **Versions capability** — integrated with the platform's `ContentVersion` class (`cnv_content_versions` table). New constant `TYPE_AGENT_FILE = 9`. `AgentFile::save()` snapshots content on every change (skips no-op saves where content is unchanged), linked-list versions per row, auto-pruned at `MAX_VERSIONS_PER_ITEM` (100). Rollback path: load a `ContentVersion` row's content into the agent file row and save.

## Open questions

- Starter-template button for new rows? Likely obviated by Phase 2's bootstrap-seeded baseline row, which gives customers a real starting point on day one.
- Hashing strategy for disk-sync indicator: store last-written hash on the row (cheap; schema reflects this) vs. compute on every list-page load. Revisit if the stored-hash approach proves insufficient.
- Single-target rows vs. multi-target rows in the UI: should the form default to one target with an "add another" affordance, or always show the multi-value control?

## Phase 2: Bootstrap-seeded customer baseline row

### Goal

Customers installing the platform receive a starter agent file row in their database automatically, with curated customer-facing content. They can edit, write to disk, or replace it through admin — no instructions to paste content from a doc page.

### Mechanism

Add a single `INSERT INTO agf_agent_files (...)` statement to the bootstrap SQL dump at `maintenance_scripts/install_tools/joinery-install.sql.gz`. The bootstrap SQL is restored by `_site_init.sh` during fresh install and contains the platform's baseline data; an agent file row is appropriate there because it ships *with* the platform like the baseline settings and menu structure already do.

Important: bootstrap SQL runs only on fresh install. `update_database` and `upgrade.php` do not re-apply it. The seeded row is therefore the customer's row from day one — once installed, they own it.

### The seeded row

- `agf_name`: `'Customer baseline'`.
- `agf_target_filenames`: `'["CLAUDE.md"]'` (JSON). Just `CLAUDE.md`; customers add `GEMINI.md` themselves if they use that agent.
- `agf_content`: curated customer-facing content (see Content authoring below).
- `agf_last_written_time`: `NULL`. Load-bearing — the upgrade regenerate step iterates rows where `agf_last_written_time IS NOT NULL`, so a never-written seed row stays dormant on customer upgrades. Customers opt in by explicitly clicking "Write to disk."
- `agf_last_written_hash`: `NULL`.
- `agf_create_time`: `now()`.
- `agf_delete_time`: `NULL`.

After install the row is the customer's like any other — standard soft-delete, versioning, edit semantics apply.

### Customer experience

1. Fresh install completes; agent file row is in the db but not on disk.
2. Customer visits `/admin/admin_agent_files`, sees the "Customer baseline" row with disk-sync status "never written."
3. Customer either edits the content first, or clicks "Write to disk" to start using it as-is.
4. From that point on, the row behaves like any other — versioning, regenerate-on-upgrade, etc.

### Content authoring

The customer-facing content lives at `maintenance_scripts/install_tools/default_agents_template.md` — alongside the rest of the install-time templates. Updates to it ride through the existing bootstrap-SQL regeneration process (the same one that refreshes the rest of `joinery-install.sql.gz`); no new tooling. Audit on every revision for leaks: no admin credentials, no internal hostnames, no memory-file pointers, no references to our specific infrastructure.
