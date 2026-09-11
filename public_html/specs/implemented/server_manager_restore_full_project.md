# Server Manager: Restore Full Project Backup

## Goal

Add a UI path in the Server Manager node detail page to restore a full project backup (`.tar.gz` produced by `backup_project.sh`) onto an existing managed node. Today the admin can:

- Take a full project backup (Backups tab → "Run Project Backup")
- Restore a database-only backup (Database tab → "Restore Database from Backup")
- Clone a full backup onto a *new* node (install_node_form → "Install from backup")

But there is **no way to restore a full project backup onto the same running node**. The `.tar.gz` files are listed in the backup browser but ignored by the restore dropdown, which filters to `.sql.gz` / `.sql.gz.enc` only.

## Motivation

The operator use case: "We deployed a broken release and need to roll back files + DB + Apache config, not just the DB." Or: "The file tree got corrupted but I don't want to reprovision from scratch via install_node." The `restore_project.sh` script already exists on every node and handles this end-to-end — it just isn't wired to the UI.

## Scope

**In scope**

- New job type `restore_project` with a `JobCommandBuilder::build_restore_project()` method
- A "Restore Full Project from Backup" form in the Backups tab that lists `.tar.gz` files (local + cloud)
- Auto-download from cloud to `/backups/` on the node before restore, reusing the existing `node_uploader.php` `download` path
- Auto-backup of DB and project files before overwrite (matching the DB-restore flow's `auto_pre_restore_*.sql.gz` pattern)
- Granular job steps for progress visibility

**Out of scope**

- New backup generation (covered by existing `backup_project` flow)
- Restoring onto a different node (use `install_node` from-backup mode)
- Cross-site restore (wrong project name → refuse)

## UI

### Location

New "Restore Full Project from Backup" box on the **Backups tab** (not the Database tab — the Database tab is DB-centric). Placed below "Backup Files" browser so the operator sees the listing first, then the restore form.

### Form fields

- **Backup file** (required) — dropdown of `.tar.gz` files from `BackupListHelper::get_for_node()`, same data source as the DB restore dropdown but filtered by filename pattern `/\.tar\.gz$/`. Options carry `data-local-path` and `data-cloud-path` attributes.
- **What to restore** — three checkboxes, all checked by default:
  - ☑ Project files
  - ☑ Database (embedded in the archive)
  - ☑ Apache config
  These map directly to `restore_project.sh`'s `--skip-files` / `--skip-database` / `--skip-apache` flags (inverted).
- **Confirm overwrite** — required checkbox ("I understand this will overwrite the current site"). Prevents accidental fat-finger.
- **Submit button**: red "Restore Project" with a JS `confirm()` dialog listing exactly what will be overwritten.

### Post-submit

Redirect to `/admin/server_manager/job_detail?job_id=X`, same as DB restore.

## Backend

### New job type: `restore_project`

Add `'restore_project'` to the job-type filter list in `views/admin/jobs.php` and `views/admin/node_detail.php` (Jobs tab).

### `JobCommandBuilder::build_restore_project($node, $params)`

Parameters:
- `filename` — display name of the archive (e.g. `empoweredhealthtn-2026-04-14-213924.tar.gz`)
- `local_path` — `/backups/foo.tar.gz` on the node, or null
- `cloud_path` — `joinery-backups/slug/foo.tar.gz` in the bucket, or null
- `skip_database` — bool, default false
- `skip_files` — bool, default false
- `skip_apache` — bool, default false

Step sequence (mirrors `build_restore_database`):

1. **Download backup from cloud** (only if `local_path` is null and `cloud_path` is set) — reuses the existing `node_uploader.php` heredoc pattern with `download` op. Target path: `/backups/{basename}`. After download, treat as local.
2. **Auto-backup DB before restore** (unless `skip_database`) — same command as the DB restore flow, written to `/backups/auto_pre_project_restore_{timestamp}.sql.gz`.
3. **Auto-backup project files before restore** (unless `skip_files`) — quick `tar czf /backups/auto_pre_project_restore_{timestamp}.tar.gz` of the current project root. Smaller than a full `backup_project.sh` run (no DB dump, no Apache config) and fast.
4. **Run project restore** — invoke `restore_project.sh` with `--force` and any combination of `--skip-*` flags:
   ```
   bash {scripts}/sysadmin_tools/restore_project.sh {project_name} {local_path} --force {skip_flags}
   ```
   `timeout: 3600`. Note: `--force` is already implemented in the script and skips all interactive prompts.
5. **Verify restore** — a small check: `ls -la {web_root} | head -5` plus a DB table count if DB was restored.

No `continue_on_error: true` anywhere — if any step fails, we want the job to halt and show red in the UI.

### Project name derivation

`restore_project.sh` takes a `PROJECT_NAME` positional argument that must match the directory under `/var/www/html/`. We already derive this the same way `build_backup_project` does:

```php
$project_name = basename(dirname(rtrim($node->get('mgn_web_root'), '/')));
```

### Encryption

Full project backups produce a `.tar.gz` whose *contents* may include a `.sql.gz.enc` (the DB dump is encrypted when the node has a cloud target). The outer tarball is never encrypted. `restore_project.sh` delegates DB restore to `restore_database.sh`, which reads `~/.joinery_backup_key` on the node to decrypt. No new key handling needed — if the key exists on the node (it must, since backups were produced there), restore works.

### Script edits required

`restore_project.sh` is already non-interactive when invoked with `--force` (all four of its own `read -p` prompts are gated on `[ "$FORCE" = false ]`). However, it delegates the DB restore to `restore_database.sh`, which has **no** non-interactive mode today and will hang any queued job.

**`restore_database.sh` — add `--non-interactive` flag.** Three prompts to handle:

- Line 57 — "Press Enter to continue with password prompts..." (fallback when no `PGPASSWORD` and no config found). Under `--non-interactive`, **fail-fast** with a clear error instead — the operator can fix credentials and re-run.
- Line 241 — "Create backup before restore? (Y/n)". Under `--non-interactive`, default to **Yes** (the safer choice; auto-backup is cheap insurance).
- Line 259 — "Terminate active connections and retry? (Y/n)". Under `--non-interactive`, default to **Yes** (otherwise the restore can't proceed).

Mirror the existing `--non-interactive` pattern already in `backup_database.sh` for consistency (flag parsing, `NON_INTERACTIVE=true` variable, conditional `read` calls).

**`restore_project.sh` — pass the flag through.** When the script is invoked with `--force`, pass `--non-interactive` to the inner `restore_database.sh` call at line 329:

```bash
if bash "$RESTORE_DB_SCRIPT" "$PROJECT_NAME" "$db_file" --non-interactive; then
```

Both scripts are checked into the repo under `/var/www/html/joinerytest/maintenance_scripts/sysadmin_tools/` and get shipped to every node via the install/upgrade flow. No special deployment — the next `upgrade.php` run on each node picks them up.

### Docker vs bare-metal

The SSH step for `restore_project.sh` should use **default routing** (no `on_host: true`) so it runs inside the container for Docker nodes and on the host for bare-metal. This matches the `backup_project` step and means the restored files land in the same filesystem they were backed up from.

**Caveat** — on Docker nodes, restoring the in-container Apache config is fine (the container's internal Apache on port 8080 still works), but it will **not** touch the host's reverse-proxy config written by `manage_domain.sh` during install. That's correct behavior — the proxy is node-level infra, not site state.

## View wiring (node_detail.php)

### POST handler (top of file, near line 132)

```php
if ($action === 'restore_project') {
    $filename   = trim($_POST['backup_filename'] ?? '');
    $local_path = trim($_POST['backup_local_path'] ?? '');
    $cloud_path = trim($_POST['backup_cloud_path'] ?? '');

    if ($filename && ($local_path || $cloud_path)) {
        $params = [
            'filename'       => $filename,
            'local_path'     => $local_path ?: null,
            'cloud_path'     => $cloud_path ?: null,
            'skip_database'  => empty($_POST['restore_database']),
            'skip_files'     => empty($_POST['restore_files']),
            'skip_apache'    => empty($_POST['restore_apache']),
        ];
        $steps = JobCommandBuilder::build_restore_project($node, $params);
        $job = ManagementJob::createJob($node->key, 'restore_project', $steps, $params, $session->get_user_id());
        header('Location: /admin/server_manager/job_detail?job_id=' . $job->key);
        exit;
    }
    header('Location: ' . $base_url . '&tab=backups');
    exit;
}
```

### Form markup (Backups tab, after the "Backup Files" box)

Filter the existing `$files` list from `BackupListHelper::get_for_node()` by `/\.tar\.gz$/`. Build the dropdown with the same `data-local-path` / `data-cloud-path` pattern as the DB restore form. Include three checkboxes and the confirm-overwrite checkbox.

If `empty($project_files)`, show a "No project backups found — run a Project Backup above." placeholder.

### Jobs tab filter

Add `'restore_project'` to the job type dropdown in both views (`jobs.php` and `node_detail.php` Jobs tab).

## Edge cases

- **Partial restore with all three skip flags set** — the script will complain that there's nothing to do and exit non-zero. The form should require at least one of the three checkboxes to be checked (client-side JS + server-side sanity check).
- **Cloud-only backup, no key on node** — auto-backup step (#2) will fail because the current DB dump can encrypt but only if the key exists; actually the pre-restore dump uses plaintext `pg_dump | gzip`, so no key needed. Fine.
- **Downloaded backup orphaned** — if the restore fails after the cloud download step, the `/backups/{basename}` file is left on the node. That's fine — the listing will pick it up next scan, and the operator can retry without re-downloading, or delete via the Backups UI.
- **Concurrent restore and backup** — `ManagementJob::createJob` already serializes jobs per node (agent runs one at a time). No special locking needed.
- **Wrong project name in archive** — `restore_project.sh` doesn't validate that the archive's top-level dir matches `PROJECT_NAME`. Low risk in practice (we always restore archives that came from this node), but could add a pre-check step that runs `tar tzf | head -1 | grep -q "^{project_name}"`.

## Testing plan

1. Run "Run Project Backup" on the empoweredhealthtn node. Verify `.tar.gz` appears in the Backups listing as Local + Cloud.
2. Delete the local copy to force cloud-download path. Click "Restore Project" on the cloud-only entry. Verify download step succeeds, DB is auto-backed-up, project is restored, site is reachable.
3. Uncheck "Database", run restore. Verify DB is untouched and files are replaced.
4. Uncheck all three and submit — form rejects.
5. Job detail page shows 5 green check steps on success.
6. Run on a Docker node and a bare-metal node — both should work without code changes since SSH routing is the same.

## Rollout

Single PR, no migrations, no new columns. The `restore_project.sh` script already exists on every node. Docs update: add a line to `docs/server_manager.md` under the Backups section mentioning the new restore capability.

## Open questions

- Should we let the operator rename the project on restore (e.g. restore `site-a.tar.gz` onto a node whose slug is `site-b`)? Current script doesn't support rename-on-restore. Leaving this out — if needed, use `install_node` from-backup instead.
- Should the auto-backup of project files (step #3) go through the full `backup_project.sh` so it also uploads to cloud? Probably no — the point is a quick local safety net, not a keeper. Keep it fast.
