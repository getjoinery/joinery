# Download a backup

**Status:** implemented 2026-10-21. Verified: the download unit test (36
checks), the engine gate's root-only section, and on dev the real button, the
second-factor ceremony, the background build and its failure message, driven
in the browser. A successful download, a restore dry-run from it, and a node
build are in the live verification queue (dev's two unreadable test tokens in
`config/` block the dev run until they are fixed).

## Why

A site whose agent is dead, or which has no backup storage, needs a way to put
a copy of itself in a person's hands. The site's own scheduled backups need a
bucket; a management node's need the agent. A download needs neither.

## What

- **Download a backup** in the Backups page's Status box, for superadmins
  (level 10), on every site with a proven recovery key, managed or not.
- The archive is the standalone full backup (`backup_project.sh` stream mode),
  sealed to the recovery key and the site key. Never plaintext: it carries
  `config/`.
- Built on the server in the background, then downloaded, so a dropped
  connection retries the download and not the backup. The disk is checked
  first (the newest full backup's size, the pre-flight rule).
- One file: `{project}-{stamp}.joinery-backup.tar` holding `README.txt`, the
  envelope and the archive. Written as it is sent; exact Content-Length.
- Kept 24 hours, then deleted by any read of its state and by the hourly
  `BackupDownloadSweep` task. **Delete it now** removes it sooner. One at a time.
- Building and downloading each ask for a fresh second factor.
- Not a backup run: no history row, nothing counted offsite.
- The confirmation says the expected size, free disk, and how many offloaded
  files are only in the cloud file store and so not in it.

## Found while building

- `backup_project.sh` had no root-only list: a web-user standalone archive
  failed on `config/admin_credentials.txt` (600 root on every node) and a
  publishing box's signing keys. The list moved to
  `_backup_root_only_files.sh`, read by both engines.

## Pieces

- `includes/BackupDownload.php`, `utils/build_backup_download.php`
- `logic/backup_download_start_logic.php`, `logic/backup_download_delete_logic.php`
- `tasks/BackupDownloadSweep.php` + `.json`
- `adm/admin_backups.php` 1.25, `adm/logic/admin_backups_logic.php` 1.25
- `maintenance_scripts/sysadmin_tools/_backup_root_only_files.sh` (new),
  `backup_project.sh` 2.10.0, `backup_files.sh` 1.6.2
- Tests: `tests/backups/backup_download_test.php`, a section in
  `tests/backups/backup_project_stream_gate.sh`
- Docs: `docs/backups.md` § Downloading a backup, `docs/scheduled_tasks.md`
