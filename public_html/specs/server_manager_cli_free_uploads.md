# Server Manager: CLI-Free Backup Uploads

## Goal

Remove the hard dependency on per-provider CLI tools (`b2`, `aws`) being installed on every managed node. Today, if the CLI isn't present, the upload step fails with exit status 127 — which we've also seen cause data loss because the local-cleanup step previously ran regardless.

Replace CLI-based uploads with direct HTTP calls to provider APIs, using the same `curl` + SigV4 pattern already established by `TargetTester` and `TargetLister`. No new external binaries on nodes.

## Motivation

### The concrete failure

Job #39 (`backup_database` against the `empoweredhealthtn` node):

```
=== [Step 4/6] Upload backup to B2 Backup ===
bash: line 1: b2: command not found
[ERROR (continuing): command exited with error: Process exited with status 127]
```

The DB dump succeeded, the upload failed because the `b2` CLI wasn't installed. (The data-loss bug — cleanup ran anyway — has already been fixed by dropping `continue_on_error` on the upload step. See commit history for `JobCommandBuilder.php` around `append_upload_steps()`.)

### Why the CLI dependency is fragile

1. **Every new node needs provisioning.** The install toolchain does not currently guarantee `b2` / `aws` are present. Any node added to a fleet inherits the gap.
2. **Multiple CLIs to keep in sync.** Today `b2` (B2), `aws` (S3, Linode). Adding a new S3-compatible provider means verifying `aws` works against its endpoint; adding a non-S3 provider means a new CLI altogether.
3. **Python/version skew risk.** The `b2` CLI is a Python package whose behavior has changed across versions (the command even renamed `authorize-account` ↔ `authorize_account` at one point). We don't pin a version.
4. **No parallel for the web server.** `TargetTester` and `TargetLister` already talk to provider APIs directly via `curl` from the web server, because installing CLIs on the web server would be the same fragile problem. The upload path is the only remaining place where we shell out.

### Historical context — separate orchestrator removed

A parallel, pre-Server-Manager backup orchestrator used to live on docker-prod as `/root/backup_all_docker_sites.sh`, with B2 credentials in `/root/.joinery_b2_config`. It was cron-oriented, ran `docker cp` to extract tarballs from each container, then called `b2 upload-file` with retries. That script and its credential files were **deleted** once Server Manager became the canonical path. References to `~/.joinery_b2_config` in `specs/implemented/docker_backup_system.md` refer to that retired system — there is no longer a second upload code path to worry about.

## Approach

Build a `TargetUploader` class alongside `TargetTester` and `TargetLister`. All three share the same curl-based pattern and live in `plugins/server_manager/includes/`. The uploader exposes **two methods only**:

- `TargetUploader::upload(BackupTarget $target, string $local_path, string $remote_key)` — create a file in the bucket
- `TargetUploader::delete(BackupTarget $target, string $remote_key)` — remove a file from the bucket

We intentionally do **not** implement listing or download in the uploader — those are separate concerns:

- **Listing** stays in `TargetLister` (already shipped, web-server-side, direct API). Covered below under "Retiring node-side cloud listing."
- **Restore/download** is out of scope for this spec; if the need arises later, a `TargetDownloader::download()` method can be added following the same SigV4 pattern.

### One code path: AWS SigV4 against S3-compatible endpoints

We use **a single signed-HTTP implementation** for every provider. No native-B2 code. B2 exposes an S3-compatible API layer (endpoint `s3.<region>.backblazeb2.com`) and we route B2 through it alongside everything else.

The same SigV4 signer covers:

- Amazon S3
- Backblaze B2 (via its S3-compatible endpoint)
- Linode Object Storage (path-style with custom endpoint — already working in `TargetTester`)
- DigitalOcean Spaces, Wasabi, Cloudflare R2, MinIO, Scaleway, OVH, Vultr, and any other S3-compatible backend

Adding a new S3-compatible provider is just a new row in the provider dropdown with an endpoint URL — **no new code**.

Tradeoff accepted: we lose B2's server-returned SHA-1 and slightly nicer multipart semantics. Both matter for sync tools, not for upload-and-forget backups. B2's S3-compat layer has been stable since 2020 and Backblaze actively promotes it; the risk of it being deprecated is small but not zero — a tail risk we accept.

### Consequence: B2 credential schema changes

Today the B2 target stores `{key_id, app_key}`. Under a unified SigV4 path, a B2 target must store the same four fields as an S3 target: `{access_key, secret_key, region, endpoint}`.

The translation is mechanical:
- `access_key` ← old `key_id` value
- `secret_key` ← old `app_key` value
- `region` ← **new field** — user picks from B2's regions (`us-west-001`, `us-west-002`, `us-west-004`, `us-east-005`, `eu-central-003`)
- `endpoint` ← auto-derived from region: `https://s3.{region}.backblazeb2.com`

The provider dropdown label stays "Backblaze B2" (user-friendly), but internally B2 is just a preset for the S3-compatible signer.

### TargetTester / TargetLister retrofit

Because the B2 credential schema changes, the B2 branches in `TargetTester` and `TargetLister` no longer match the stored data. Retrofit both to use the same SigV4 signer as the new uploader — the B2-specific native-API code (`b2_authorize_account` / `b2_list_buckets` / `b2_list_file_names`) gets deleted. Net result: Tester, Lister, and Uploader each have exactly one code path per operation.

### Retiring node-side cloud listing

Today `JobCommandBuilder::build_provider_list_cmd()` emits an SSH step that runs `b2 ls` or `aws s3 ls` on the node to enumerate cloud backups. That step populates the "cloud backups" half of the node's Backups tab browser. Once we drop the node-side cloud CLIs, it has nowhere to run.

**Approach:** delete the cloud-listing SSH step entirely. Use `TargetLister` on the web server instead — it already knows how to enumerate a bucket over the API. The node still runs a local `ls /backups/` (pure-shell, no cloud CLI) to report local files. The backup browser UI merges the two lists in PHP before rendering.

Concretely:
- Remove the cloud-listing step from `JobCommandBuilder::build_list_backups()` (keep the local `ls /backups/` step).
- Where the backup browser UI consumes the job output, augment it by calling `TargetLister::list_files($target)` directly and merging its results with the node's local file list, tagging each row as local or cloud.

Upside: the backup browser works the same whether or not any cloud CLI is installed anywhere. Downside: the cloud listing now hits the provider API every time the Backups tab is opened (rather than being cached in the node's last-status job). Acceptable — `TargetLister` already paginates at 500 and has a 15s timeout; we can add caching later if it becomes an issue.

### Invocation from the node

The uploader needs to run on the node where the backup file lives (`/backups/*.sql.gz.enc`), not on the web server — the file is too large to stream across SSH. We'll invoke PHP on the node:

1. The web server generates a small uploader script on the fly (reads from a template + substitutes target credentials/bucket/key).
2. The SSH step on the node executes it via `php - <<'EOF' ... EOF` (heredoc piped in) — the script never lands on disk.
3. The script reads the newest backup file, signs the request, streams the body via curl, returns exit 0 on 2xx.

PHP is already present on every Joinery node (it's how the node runs Joinery itself). We're using it, not adding it.

## Files involved

**New code:**
- `plugins/server_manager/includes/TargetUploader.php` — two methods: `upload()` and `delete()`. Both return `['success' => bool, 'error' => ?string]` (plus `bytes` / `remote_url` on upload). Mirrors `TargetTester` / `TargetLister` shape.
- `plugins/server_manager/includes/UploaderScript.php` (or a constant/string in `TargetUploader`) — the PHP script that's heredoc'd onto the node. Self-contained (no `require` of the rest of the codebase); reads credentials from stdin to avoid env-var leak.
- Shared SigV4 signing helper — extract the signer used in `TargetTester` / `TargetLister` into a single reusable function/class so all four callers (Tester, Lister, Uploader's create, Uploader's delete) use the exact same signing code. No duplication.

**Modifications:**
- `plugins/server_manager/includes/JobCommandBuilder.php`:
  - `build_provider_upload_cmd()` — rewrite to emit a `php <<'EOF' ... EOF` invocation of the uploader script with credentials piped in on stdin.
  - `build_provider_delete_cmd()` — same treatment (for the cleanup/retention path).
  - `build_provider_list_cmd()` — **deleted.** Cloud listing moves to the web server via `TargetLister`. The node-side SSH step in `build_list_backups()` that invoked it is also removed.
  - `build_list_backups()` — keep the local-file `ls /backups/` step; drop the cloud-list step.
  - `build_provider_download_cmd()` — **not modified in this spec.** Restore is out of scope.
- Backup browser UI (wherever `build_list_backups()` output is consumed for the node_detail Backups tab) — augment the local file list by calling `TargetLister::list_files($target)` and merging results, tagging each row as local vs cloud.
- `plugins/server_manager/includes/TargetTester.php` — drop the native-B2 branch; route B2 through the SigV4 path using `region` + `endpoint` from the updated credential schema.
- `plugins/server_manager/includes/TargetLister.php` — same: drop native-B2 branch, route through SigV4.
- `plugins/server_manager/views/admin/targets.php` — B2 form section: replace `key_id` / `app_key` labels with `access_key` / `secret_key`, add a Region dropdown (hardcoded list of B2's regions), hide the Endpoint field for B2 (auto-computed from region).
- `plugins/server_manager/data/backup_target_class.php` — optional cleanup: add instance methods `upload_file()` / `delete_file()` that delegate to `TargetUploader`, so callers don't reach into `TargetUploader::` directly.

**Data migration:**
- Exactly one existing B2 row (id 1, bucket `joinery-backups-354`). Required action: confirm the bucket's region with the user, then update `bkt_credentials` JSON in place: rename `key_id` → `access_key`, `app_key` → `secret_key`, add `region` + `endpoint`. A one-off SQL update — no migration file needed since this is pre-release.

**Install tooling:**
- `/var/www/html/joinerytest/maintenance_scripts/install_tools/install.sh` — remove any `b2` / `aws` install steps (if present). We no longer depend on them.

**Not affected:**
- `maintenance_scripts/sysadmin_tools/backup_database.sh` and `backup_project.sh` — pure local-backup scripts. They run `pg_dump` / `tar` / openssl encryption and drop the resulting file in `/backups/`. They do not touch B2/S3/Linode at all. The upload is appended as a separate SSH step by `JobCommandBuilder::append_upload_steps()`.

## Design notes

### Credential handling on the node

The node needs the target's credentials long enough to make the HTTP call. Recommended:
- **Pipe credentials in via stdin** when invoking the uploader script — never written to disk, gone when the shell exits.

Avoid:
- Env vars — visible to other processes on the box via `/proc/<pid>/environ`.
- Files on the node — persists credentials at rest, which matches the pattern of the *retired* orchestrator we just deleted. Don't regress.

This matches the existing pattern: credentials currently come inline in the shell command, never persisted.

### Large files

Current backups are small (tens of MB — e.g. the 63KB file in job #39) but project-backup tarballs can easily exceed 5 GB. A single signed PUT works up to 5 GB. Beyond that, the S3 multipart flow (`CreateMultipartUpload` → `UploadPart` × N → `CompleteMultipartUpload`) is required. Since every provider uses the same multipart API under SigV4, this is one implementation — no per-provider branching.

**v1 scope:** ship the single-PUT path and document a 5 GB per-file limit. Add multipart in a follow-up once a real large-file case shows up. The current B2 CLI command uses single upload too, so we're not regressing.

### Streaming vs. buffering

Stream the file through curl (`CURLOPT_READFUNCTION` / `CURLOPT_INFILE`) rather than buffering it in memory. PHP's memory limit on a node is typically 128–256 MB — a 3 GB tarball would OOM a naive buffered upload.

### Retry

curl's native retry is limited. Wrap the upload call in an outer retry loop with exponential backoff on 5xx / network errors. Three attempts is a reasonable default. Network flakes are the common cause of upload failures; tolerate them without marking the whole job failed.

### Verification

After a successful upload, verify the remote file has the expected size. The PUT response's `ETag` is the MD5 of the content for single-PUT uploads on every S3-compatible provider (including B2-via-S3-compat); we can compare against the local file's MD5 directly. For multipart, ETag semantics are different — defer that verification strategy to the multipart v2.

## Migration / rollout

1. **Data migration.** Confirm the region of the existing `joinery-backups-354` bucket, then update target row 1's `bkt_credentials` JSON: rename `key_id`/`app_key` → `access_key`/`secret_key`, add `region` + `endpoint`. Update the B2 section of the target form so future B2 targets use the new schema.
2. **Unified signer + uploader.** Extract SigV4 signing into a shared helper. Retrofit `TargetTester` and `TargetLister` to route B2 through it (delete native-B2 code). Add `TargetUploader` with `upload()` and `delete()`.
3. **Wire it into jobs.** Swap `build_provider_upload_cmd()` and `build_provider_delete_cmd()` to invoke the new uploader via `php <<EOF ... EOF` with credentials on stdin. Delete `build_provider_list_cmd()` and the cloud-listing SSH step in `build_list_backups()`; update the Backups tab UI to merge local-file output with a direct `TargetLister::list_files()` call.
4. **Verify.** Run a real backup job against a test node — confirm the file lands in B2, the job is marked `completed`, and the Backups tab shows it. Then run a job with deliberately bad credentials — confirm the job is marked `failed` with a clear error and the local backup file is preserved.

## Related fixes shipped ahead of this spec

- **Local-cleanup data loss** — fixed by removing `continue_on_error` from the upload step in `JobCommandBuilder::append_upload_steps()`. Upload failure now halts the job, so the local cleanup step never runs, and the local backup survives.
- **Silent failure in job status** — same fix as above, because upload failure now propagates to the overall job status instead of being swallowed.

Both were preconditions for this spec: until they landed, the uploader rewrite would have been layered on top of a misleading status signal.

## Out of scope

- **Restore / download paths.** `build_provider_download_cmd()` still emits CLI-based downloads. Same fragility applies, but restore is low-frequency and doesn't block day-to-day backup operation. When it's needed, add a `TargetDownloader::download()` method following the same SigV4 pattern.
- **Credential encryption at rest** (in `bkt_credentials`). That's a separate hardening story; this spec assumes the existing plaintext-in-DB + TLS-in-flight posture.
- **Multipart upload for files over 5 GB.** v2 once there's a real case.
