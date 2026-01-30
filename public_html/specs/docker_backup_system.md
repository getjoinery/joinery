# Docker Backup System Specification

## Overview

A system for automated, encrypted backups of all Joinery Docker sites on a server, with upload to Backblaze B2 cloud storage.

## Goals

1. Back up all Joinery Docker containers on a server with a single command
2. Always use encryption for automated backups (existing `--plaintext` option retained for manual use)
3. Support fully automated (non-interactive) operation for cron jobs
4. Clean up temporary files automatically to prevent orphaned large files
5. Reuse existing backup scripts (`backup_database.sh`, `backup_project.sh`)
6. Upload backups to Backblaze B2 for offsite storage

## Components

### 1. New Script: `backup_all_docker_sites.sh`

**Location:** `/maintenance_scripts/sysadmin_tools/backup_all_docker_sites.sh`

**Responsibilities:**
- Discover all Joinery Docker containers on the server
- Orchestrate backup of each container
- Handle temporary file cleanup
- Upload to Backblaze B2
- Send notification on success/failure (optional)

**Usage:**
```bash
# Interactive mode (prompts for encryption password per site)
./backup_all_docker_sites.sh

# Non-interactive mode (uses encryption key from file or environment)
./backup_all_docker_sites.sh --non-interactive

# Backup specific site only
./backup_all_docker_sites.sh --site sitename

# Dry run (show what would be backed up)
./backup_all_docker_sites.sh --dry-run
```

**Options:**
| Option | Description |
|--------|-------------|
| `--non-interactive` | Use encryption key from `$BACKUP_ENCRYPTION_KEY` env var or `~/.joinery_backup_key` file |
| `--site SITENAME` | Backup only the specified site |
| `--dry-run` | List sites that would be backed up without actually backing up |
| `--skip-upload` | Create backups but don't upload to B2 (for testing) |
| `--keep-local` | Don't delete local backup files after upload |
| `--help` | Show help message |

### 2. Modifications to Existing Scripts

#### `backup_database.sh` Changes

Add `--non-interactive` mode:
- Accept encryption password from `$BACKUP_ENCRYPTION_KEY` environment variable
- Fall back to `~/.joinery_backup_key` file if env var not set
- Exit with error if non-interactive and no key available
- Keep existing `--plaintext` option for manual use cases

**New usage:**
```bash
# Interactive (existing behavior - prompts for password)
./backup_database.sh sitename

# Non-interactive with env var
BACKUP_ENCRYPTION_KEY="secretkey" ./backup_database.sh --non-interactive sitename

# Non-interactive with default key file (~/.joinery_backup_key)
./backup_database.sh --non-interactive sitename
```

#### `backup_project.sh` Changes

- Pass through `--non-interactive` to `backup_database.sh`
- Keep existing `--plaintext` option for manual use cases
- Add `--output-dir` option to specify where backup archive is created

### 3. B2 Configuration

**Config file:** `~/.joinery_b2_config` or `/etc/joinery/b2_config`

```bash
B2_APPLICATION_KEY_ID="your_key_id"
B2_APPLICATION_KEY="your_application_key"
B2_BUCKET_NAME="your-backup-bucket"
B2_PATH_PREFIX="joinery-backups"  # Optional prefix within bucket
```

**Security:**
- Config file must have `600` permissions
- Keys should be application keys with write-only access to specific bucket

### 4. Encryption Key Management

**Key sources (in order of precedence):**
1. Environment variable: `$BACKUP_ENCRYPTION_KEY`
2. Key file: `~/.joinery_backup_key` (must have `600` permissions)
3. Interactive prompt (only if not using `--non-interactive`)

**Key file format:**
```
# Single line, the encryption password
MySecureEncryptionPassword123!
```

## Workflow

### Backup Process

```
1. Validate prerequisites
   - Check B2 CLI installed (or skip upload)
   - Check encryption key available (if non-interactive)
   - Check Docker is running

2. Discover Joinery containers
   - List all containers
   - Filter to Joinery sites (by volume naming pattern or label)

3. For each container:
   a. Create temp directory: /tmp/joinery_backup_XXXXXX/

   b. Run backup inside container:
      docker exec -e BACKUP_ENCRYPTION_KEY="$KEY" sitename \
        /var/www/html/sitename/maintenance_scripts/sysadmin_tools/backup_project.sh \
        sitename --non-interactive --output-dir /tmp

   c. Copy backup out of container:
      docker cp sitename:/tmp/sitename-TIMESTAMP.tar.gz /tmp/joinery_backup_XXXXXX/

   d. Clean up inside container:
      docker exec sitename rm /tmp/sitename-TIMESTAMP.tar.gz

   e. Upload to B2:
      b2 upload-file bucket backups/HOSTNAME/sitename/sitename-TIMESTAMP.tar.gz

   f. Clean up local temp file (unless --keep-local)

4. Clean up temp directory

5. Report summary:
   - Sites backed up successfully
   - Sites that failed
   - Total backup size
   - B2 upload status
```

### Temp File Strategy

**Location:** `/tmp/joinery_backup_XXXXXX/` (mktemp -d)

**Cleanup triggers:**
1. Automatic cleanup after each site backup completes (success or failure)
2. Trap on script exit to clean temp directory
3. Clean up any backups older than 1 hour in container's `/tmp/` on start

**Inside container cleanup:**
```bash
# At start of backup, clean up any orphaned backups
docker exec sitename find /tmp -name "sitename-*.tar.gz" -mmin +60 -delete
```

## File Structure

```
/maintenance_scripts/sysadmin_tools/
├── backup_all_docker_sites.sh    # NEW: Docker orchestrator
├── backup_database.sh            # MODIFIED: Add --non-interactive
├── backup_project.sh             # MODIFIED: Add --non-interactive, --output-dir
├── restore_database.sh           # Existing
├── restore_project.sh            # Existing
└── b2_config.template            # NEW: Template for B2 configuration
```

## B2 Bucket Structure

```
bucket-name/
└── joinery-backups/
    └── HOSTNAME/
        ├── sitename1/
        │   ├── sitename1-2026-01-30-143022.tar.gz
        │   ├── sitename1-2026-01-29-143015.tar.gz
        │   └── ...
        └── sitename2/
            ├── sitename2-2026-01-30-143045.tar.gz
            └── ...
```

**Retention:** Configure B2 lifecycle rules to automatically delete backups older than N days.

## Cron Setup

```bash
# Daily backup at 3 AM
0 3 * * * /root/joinery-source/maintenance_scripts/sysadmin_tools/backup_all_docker_sites.sh --non-interactive >> /var/log/joinery_backup.log 2>&1
```

## Error Handling

1. **Container not running:** Skip with warning, continue to next site
2. **Backup script fails:** Log error, clean up temp files, continue to next site
3. **B2 upload fails:** Retry up to 3 times with exponential backoff, then log error
4. **Disk space low:** Check before each backup, skip if < 5GB free in /tmp
5. **Encryption key missing:** Exit with clear error message

## Security Considerations

1. Encryption key file must be `600` permissions, owned by root
2. B2 config file must be `600` permissions, owned by root
3. Use B2 application keys (not master key) with minimal permissions
4. Backups are encrypted with AES-256-CBC before leaving the server
5. B2 bucket should have:
   - Server-side encryption enabled
   - Object Lock for ransomware protection (optional)
   - IP allowlist if possible

## Dependencies

- `docker` - Container management
- `b2` - Backblaze B2 CLI (install via `pip install b2`)
- `openssl` - Encryption (pre-installed)
- `tar`, `gzip` - Archive creation (pre-installed)

## Success Criteria

1. Single command backs up all sites on a Docker server
2. Works in cron without any interactive prompts
3. No orphaned temp files after completion (success or failure)
4. Backups are encrypted and uploaded to B2
5. Clear logging of success/failure per site
6. Graceful handling of individual site failures (don't abort entire backup)

## Future Enhancements

1. Email/Slack notification on backup completion or failure
2. Backup verification (download and test decrypt)
3. Automatic restore testing
4. Bandwidth throttling for B2 uploads
5. Parallel backups for faster completion
6. Bare-metal server support (same script, different discovery method)
