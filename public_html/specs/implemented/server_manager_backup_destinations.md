# Server Manager: Backup Destination Management

**Created:** 2026-04-11
**Status:** Active

## Problem

Backup storage location logic is scattered across shell scripts. The `backup_all_docker_sites.sh` script has B2 upload code baked in, reading credentials from config files on the remote host. The individual backup scripts (`backup_database.sh`, `backup_project.sh`) have no cloud upload at all — they just write files locally.

This means:
- Adding a new storage provider requires editing shell scripts and deploying to every server
- Credentials live in config files on each remote host instead of centrally
- The server manager admin UI has no visibility into where backups go or how to configure storage
- There's no way to change backup destinations without SSH access

## Solution

Move backup destination management into the server manager plugin, following the existing "smart plugin, dumb agent" architecture. The backup scripts stay unchanged — they produce a local file. The plugin's `JobCommandBuilder` appends upload steps to the job after the backup step, based on centrally-stored destination configuration.

### How It Works

**Before (current):**
```
Step 1: SSH → Run backup_database.sh → file lands in /backups/
Step 2: SSH → List backup files
(done — file sits on remote server forever)
```

**After:**
```
Step 1: SSH → Run backup_database.sh → file lands in /backups/
Step 2: SSH → Upload to configured destination (B2, S3, etc.)
Step 3: SSH → Optionally delete local copy after confirmed upload
Step 4: SSH → List backup files
```

The upload command is just another SSH step — the agent doesn't need to know anything about storage providers. All intelligence stays in PHP.

## Data Model

### BackupDestination

New data class: `plugins/server_manager/data/backup_destination_class.php`

A backup destination is a configured storage target. There can be multiple destinations (e.g., one B2 bucket for production, a different one for staging, plus local-only for dev).

```php
public static $tablename = 'bkd_backup_destinations';
public static $pkey_column = 'bkd_id';
public static $prefix = 'bkd';

public static $field_specifications = array(
    'bkd_id'              => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
    'bkd_name'            => array('type'=>'varchar(100)', 'required'=>true, 'is_nullable'=>false),
    'bkd_provider'        => array('type'=>'varchar(30)', 'required'=>true, 'is_nullable'=>false),
    // Provider values: 'local', 'b2', 's3', 'linode'
    'bkd_bucket'          => array('type'=>'varchar(255)'),
    'bkd_path_prefix'     => array('type'=>'varchar(255)', 'default'=>"'joinery-backups'"),
    'bkd_credentials'     => array('type'=>'jsonb'),
    // JSON structure varies by provider:
    //   b2:    {"key_id": "...", "app_key": "..."}
    //   s3:    {"access_key": "...", "secret_key": "...", "region": "us-east-1"}
    //   linode: {"access_key": "...", "secret_key": "...", "region": "us-east-1", "endpoint": "..."}
    //   local: {} (no credentials needed)
    'bkd_delete_local'    => array('type'=>'bool', 'default'=>'false', 'is_nullable'=>false),
    // Whether to delete the local backup file after successful upload
    'bkd_enabled'         => array('type'=>'bool', 'default'=>'true', 'is_nullable'=>false),
    'bkd_create_time'     => array('type'=>'timestamp(6)', 'default'=>'now()'),
    'bkd_update_time'     => array('type'=>'timestamp(6)'),
    'bkd_delete_time'     => array('type'=>'timestamp(6)'),
);
```

### Node-to-Destination Association

Each node gets a default backup destination. Stored on the managed node record.

Add to `ManagedNode::$field_specifications`:
```php
'mgn_bkd_backup_destination_id' => array('type'=>'int8'),
```

When a backup job is triggered for a node, `JobCommandBuilder` looks up the node's destination. If none is set, backups stay local only (current behavior — no upload step appended).

## Upload Step Generation

### Provider-Specific Commands

All providers use CLI tools that run via SSH on the remote server (or on the control plane, depending on where the backup file is). The plugin generates the appropriate shell command.

**Backblaze B2** (using `b2` CLI):
```bash
b2 authorize-account "{key_id}" "{app_key}" && \
b2 upload-file "{bucket}" "{local_path}" "{prefix}/{node_slug}/{filename}"
```

**S3-compatible** (using `aws` CLI — works for AWS S3, Linode Object Storage, and others):
```bash
AWS_ACCESS_KEY_ID="{access_key}" AWS_SECRET_ACCESS_KEY="{secret_key}" \
aws s3 cp "{local_path}" "s3://{bucket}/{prefix}/{node_slug}/{filename}" \
  --region "{region}" --endpoint-url "{endpoint}"
```
The `--endpoint-url` flag is only included for non-AWS providers (Linode, DigitalOcean, MinIO, etc.). For standard S3, it's omitted.

**Local only** (no upload step appended — current behavior).

### Upload Path Structure

All providers use the same logical path: `{prefix}/{node_slug}/{filename}`

- `prefix`: From destination config, default `joinery-backups`
- `node_slug`: From the node's `mgn_slug` field (e.g., `empoweredhealthtn`)
- `filename`: The backup filename as produced by the script

Example: `joinery-backups/empoweredhealthtn/empoweredhealthtn-04_11_2026.sql.gz.enc`

### JobCommandBuilder Changes

`build_backup_database` and `build_backup_project` gain a destination lookup:

```php
public static function build_backup_database($node, $params = []) {
    // ... existing backup step ...

    // Look up the node's backup destination
    $dest_id = $node->get('mgn_bkd_backup_destination_id');
    if ($dest_id) {
        $dest = new BackupDestination($dest_id, TRUE);
        if ($dest->get('bkd_enabled') && $dest->get('bkd_provider') !== 'local') {
            // Append upload step
            $steps[] = self::build_upload_step($dest, $node);

            // Optionally delete local copy
            if ($dest->get('bkd_delete_local')) {
                $steps[] = ['type' => 'ssh', 'label' => 'Clean up local backup',
                    'cmd' => '...rm command...', 'continue_on_error' => true];
            }
        }
    }

    // ... existing list-files step ...
    return $steps;
}
```

A new private helper generates the upload step:

```php
private static function build_upload_step($destination, $node) {
    $provider = $destination->get('bkd_provider');
    $creds = json_decode($destination->get('bkd_credentials'), true);
    $bucket = $destination->get('bkd_bucket');
    $prefix = $destination->get('bkd_path_prefix') ?: 'joinery-backups';
    $slug = $node->get('mgn_slug');

    // Build provider-specific upload command
    // The backup file path is captured from the previous step's output
    // Use a shell variable or known path pattern

    $upload_cmd = match($provider) {
        'b2' => self::build_b2_upload($creds, $bucket, $prefix, $slug),
        's3', 'linode' => self::build_s3_upload($creds, $bucket, $prefix, $slug, $destination),
    };

    return [
        'type' => 'ssh',
        'label' => 'Upload backup to ' . $destination->get('bkd_name'),
        'cmd' => $upload_cmd,
        'timeout' => 3600,
        'continue_on_error' => true,
    ];
}
```

### Credential Security

Credentials are stored in `bkd_credentials` as JSON in the database. They are injected into SSH commands as environment variables, not written to files on the remote host. The commands use inline `VAR=value command` syntax so credentials exist only for the duration of the upload command and don't persist in shell history (when combined with a leading space or `set +o history`).

For B2, the `b2 authorize-account` command writes a short-lived token to `~/.b2_account_info` — this is standard B2 behavior and is acceptable for the SSH session duration.

### CLI Tool Availability

The upload step requires the appropriate CLI tool to be installed on the machine where the upload runs:

- **B2**: `pip install b2` (or `b2-cli`)
- **S3/Linode**: `aws` CLI (installed via package manager or pip)

If the CLI tool is not installed, the upload step will fail with a clear error (command not found). The backup itself still succeeds — the upload is `continue_on_error: true`.

A future enhancement could add a "test destination" action that verifies CLI tool availability and credential validity before the first real backup.

## Backup Encryption

### Default Behavior

Encryption is **checked by default** on both the Database Backup and Full Project Backup forms. Users can uncheck it for local-only backups if desired.

### B2 Enforcement

When a node's backup destination is Backblaze B2, encryption is **mandatory**. The UI replaces the encryption checkbox with a hidden `encryption=1` field and a message: "Encryption required for Backblaze B2 destinations." Server-side enforcement in `JobCommandBuilder` also forces `params['encryption'] = true` for B2 destinations regardless of what the form submits.

### Auto-Generated Encryption Keys

The existing `backup_database.sh` script reads encryption keys from `~/.joinery_backup_key` on the remote server (with 600 permissions). Rather than requiring manual key setup, the backup job auto-generates a key if one doesn't exist.

When encryption is enabled, `build_backup_database` and `build_backup_project` prepend an "Ensure encryption key" step:

```bash
if [ -f ~/.joinery_backup_key ]; then
    echo "ENCRYPTION_KEY_OK"
else
    openssl rand -base64 32 > ~/.joinery_backup_key
    chmod 600 ~/.joinery_backup_key
    echo "ENCRYPTION_KEY_GENERATED — retrieve it via SSH: cat ~/.joinery_backup_key"
fi
```

This step:
- Uses the existing key if present (no-op)
- Generates a random 32-byte base64 key if missing
- Sets restrictive file permissions (600)
- Tells the admin how to retrieve the key, but **never outputs the key value itself**

### Key Security Model

The encryption key **never touches the control plane**. It exists only on the remote server's filesystem. This means:

- If someone compromises the B2 bucket, they get encrypted blobs they can't decrypt
- If someone compromises the control plane database, they get bucket credentials but no decryption key
- The key and the data never live in the same place

To view or back up the key, the admin must SSH directly to the remote server:
```bash
cat ~/.joinery_backup_key
```

The key is not stored in job output, the plugin database, or any settings table.

## Admin UI

### Destinations Management

Add a **Settings** tab to the node detail page (or a separate destinations page linked from the dashboard). This page lets you:

- Create/edit/delete backup destinations
- Configure provider, credentials, bucket, path prefix
- Set whether to delete local copies after upload
- Test the destination (verify credentials and CLI tool availability)

### Node Destination Assignment

On the node detail **Overview** tab (in the connection settings section), add a dropdown to select the node's default backup destination. Shows all enabled destinations.

### Backups Tab Enhancement

On the node detail **Backups** tab, show the currently configured destination alongside the backup forms. Example:

```
Backup destination: Production B2 (joinery-backups/empoweredhealthtn/)
[Change destination]
```

If no destination is configured, show: `Backup destination: Local only [Configure]`

### Backup Browser

The Backups tab gets a **Backup Browser** section below the backup action forms. This replaces the current "Recent Backup Jobs" table with something more useful — an actual listing of backup files that exist, with the ability to delete them.

#### Two sources to browse

A node can have backups in two places:

1. **Local** — files in `/backups/` on the remote server (always present)
2. **Cloud** — files in the configured destination bucket (only if a destination is set)

The browser shows both in a unified table with a "Location" column indicating where each file lives. If a file exists in both places (uploaded but local not deleted), it appears once with "Local + Cloud" in the location column.

#### How listing works

Listing files is a job, just like everything else. The Backups tab triggers a `list_backups` job when the page loads (or on a "Refresh" button click). This keeps the architecture consistent — no special SSH-from-PHP path.

However, waiting for a job to complete on every page load is a poor UX. Instead:

**Approach: Cache the listing on the node record.**

- A `list_backups` job runs, produces structured output (file list with names, sizes, dates, locations)
- `JobResultProcessor::process_list_backups` parses the output and stores the result on the node: `mgn_last_backup_list` (jsonb) and `mgn_last_backup_list_time` (timestamp)
- The Backups tab reads `mgn_last_backup_list` and renders the table immediately — no waiting
- A "Refresh" button triggers a new `list_backups` job. When it completes, the user refreshes the page (or AJAX updates the table)
- If the list has never been fetched, show an empty state with a "Scan for backups" button

Add to `ManagedNode::$field_specifications`:
```php
'mgn_last_backup_list'      => array('type'=>'jsonb'),
'mgn_last_backup_list_time' => array('type'=>'timestamp(6)'),
```

Add to `ManagedNode::$json_vars`:
```php
public static $json_vars = array('mgn_last_status_data', 'mgn_last_backup_list');
```

#### list_backups job type

`JobCommandBuilder::build_list_backups($node)` generates steps:

**Step 1: List local backups**
```bash
ls -lh /backups/*.sql.gz /backups/*.sql.gz.enc /backups/*.tar.gz 2>/dev/null | \
  awk '{print "LOCAL|"$5"|"$6" "$7" "$8"|"$9}'
```
Output format: `LOCAL|size|date|filepath` per line.

**Step 2: List cloud backups** (only if destination is configured and not local-only)

For B2:
```bash
b2 authorize-account "{key_id}" "{app_key}" && \
b2 ls --long "{bucket}" "{prefix}/{node_slug}/"
```

For S3/Linode:
```bash
AWS_ACCESS_KEY_ID="{access_key}" AWS_SECRET_ACCESS_KEY="{secret_key}" \
aws s3 ls "s3://{bucket}/{prefix}/{node_slug}/" --endpoint-url "{endpoint}"
```

Output is parsed by the result processor into a normalized list.

**Step 3 (optional):** Could also check disk usage of `/backups/` to show total local backup size.

#### JobResultProcessor::process_list_backups

Parses the combined output into a structured JSON array:

```json
{
  "files": [
    {
      "filename": "empoweredhealthtn-04_11_2026.sql.gz.enc",
      "size": "2.5G",
      "date": "2026-04-11",
      "local_path": "/backups/empoweredhealthtn-04_11_2026.sql.gz.enc",
      "cloud_path": "joinery-backups/empoweredhealthtn/empoweredhealthtn-04_11_2026.sql.gz.enc",
      "location": "both"
    },
    {
      "filename": "older-backup.sql.gz",
      "size": "1.8G",
      "date": "2026-04-01",
      "local_path": "/backups/older-backup.sql.gz",
      "cloud_path": null,
      "location": "local"
    }
  ],
  "local_total_size": "8.2G",
  "cloud_file_count": 5
}
```

Stored on the node record via `mgn_last_backup_list` and `mgn_last_backup_list_time`.

#### Backups tab UI

**Backup Browser section** (below the backup action forms):

```
Backup Files                                    [Refresh] 
Last scanned: Apr 11, 12:34 PM

| Filename                                  | Size  | Date       | Location      | Actions       |
|-------------------------------------------|-------|------------|---------------|---------------|
| empoweredhealthtn-04_11_2026.sql.gz.enc   | 2.5G  | Apr 11     | Local + Cloud | [Delete ▼]    |
| empoweredhealthtn-04_01_2026.sql.gz.enc   | 1.8G  | Apr 1      | Local only    | [Delete ▼]    |
| auto_pre_overwrite_20260410.sql.gz         | 1.7G  | Apr 10     | Local only    | [Delete ▼]    |
```

The **Delete** button is a dropdown with options depending on where the file exists:
- "Delete local copy" — runs `rm` on the remote server via SSH
- "Delete cloud copy" — runs the provider's delete command
- "Delete everywhere" — both

Each delete action creates a `delete_backup` job with the appropriate steps.

#### delete_backup job type

`JobCommandBuilder::build_delete_backup($node, $params)` generates steps based on `$params['target']` (local, cloud, or both):

**Delete local:**
```bash
rm -f "{local_path}"
```

**Delete cloud (B2):**
```bash
b2 authorize-account "{key_id}" "{app_key}" && \
b2 delete-file-version "{bucket}" "{cloud_path}"
```

**Delete cloud (S3/Linode):**
```bash
AWS_ACCESS_KEY_ID="{access_key}" AWS_SECRET_ACCESS_KEY="{secret_key}" \
aws s3 rm "s3://{bucket}/{cloud_path}" --endpoint-url "{endpoint}"
```

All delete steps use `continue_on_error: true`. After deletion, a `list_backups` job is automatically queued to refresh the cached file list.

## Implementation Plan

1. Create `BackupDestination` + `MultiBackupDestination` data class
2. Add `mgn_bkd_backup_destination_id`, `mgn_last_backup_list`, `mgn_last_backup_list_time` fields to `ManagedNode`
3. Add `build_upload_step`, `build_b2_upload`, `build_s3_upload` helpers to `JobCommandBuilder`
4. Add `build_list_backups`, `build_delete_backup` to `JobCommandBuilder`
5. Update `build_backup_database` and `build_backup_project` to append upload steps when a destination is configured
6. Add `process_list_backups` to `JobResultProcessor`
7. Add destination management UI (CRUD form — can be a section on the dashboard or a settings tab)
8. Add destination dropdown to node detail Overview tab
9. Update Backups tab: show current destination, add backup browser with file table and delete actions
10. Update `JobResultProcessor::process_backup_database` regex to also match `.sql.gz.enc`

## Files

| File | Action |
|------|--------|
| `data/backup_destination_class.php` | CREATE — BackupDestination + MultiBackupDestination |
| `data/managed_node_class.php` | MODIFY — add destination FK, backup list cache fields |
| `includes/JobCommandBuilder.php` | MODIFY — add upload, list, delete step generation, update backup builders |
| `includes/JobResultProcessor.php` | MODIFY — add process_list_backups, fix backup regex |
| `views/admin/node_detail.php` | MODIFY — destination dropdown in overview, backup browser in backups tab |
| `views/admin/index.php` | MODIFY — add destination management section or link |
| `ajax/backup_actions.php` | CREATE — AJAX endpoint for delete and refresh actions from the backup browser |

## Developer Documentation

After implementation, update `/docs/server_manager.md` with backup destination configuration, supported providers, backup browser usage, and credential setup instructions.
