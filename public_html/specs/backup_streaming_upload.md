# Backups — The Archive Never Lands on Disk

**Status:** Unbuilt.
**Date:** 2026-09-20

## For the executor — read this first

This section is the working brief. The design sections after it are the
reasons; the work packages at the end are the checklist. Do the packages in
order. Build this spec **before** WP1 of `specs/backup_offloaded_files.md`:
both edit `BackupRunner::execute_chain()`, and that spec's step ordering is
written against the streamed engine this one produces.

**House rules that bind this work** (CLAUDE.md and project memory):

- **Never commit, never `git add`.** The index is shared with other sessions;
  the owner runs git.
- **No schema change and no settings change** in this spec.
- **Docs describe the current state only.** No "now", "previously", "replaces".
- **Bump the `@version` header** of every file you touch, one line saying what.
  The shell scripts carry `# Version:` lines at the top; add one.
- **After every PHP edit:** `php -l <file>`, then
  `php /var/www/html/joinerytest/maintenance_scripts/dev_tools/validate_php_file.php <file>`
  — on class and function files only (`includes/`, `data/`, `logic/`,
  `tasks/`). The validator *includes* the file: never run it on `utils/*.php`
  or a script; those get `php -l` only. Shell scripts: `bash -n`.
- **Tests use the shared harness** (`tests/lib/harness.php`: `harness_boot()`,
  `section()`, `check()`, `harness_finish()`, `harness_defer()`,
  `harness_skip()`, `harness_scratch_dir()`) and carry the `@joinery-test`
  header (`name`, `tier`, `env`, `needs`, optional `timeout`). Shell gates
  are `*_gate.sh` with the same header in comments. Working loop:
  `php tests/run.php --changed`; before handing back: `php tests/run.php db
  --changed`. Never run the runner as root. `harness_scratch_dir()` is shared
  across runs: unlink an output before a child writes it.
- **The key never touches argv.** Every script passes the data key on fd 3
  from a 0600 file; keep it that way in stream mode.
- **Never touch a live node.** The live gate at the end is the owner's.
- **Identifiers**: report questions as Q1…, bugs found on the way as B1…,
  action items as A1….

**Decisions already made — do not reopen:**

- The archive streams; the database dump still lands on disk, compressed.
- The runner completes a multipart upload only after the engine's exit status
  is known; the status arrives after the bytes.
- The manager credential is not changed; multipart abort is already within
  `writeFiles` (§ The manager profile).
- The part size stays at the existing constant.

**Stop points** (hand back to the owner; do not work around):

1. When everything is green, for the release and the live gate. There is no
   database write and no agent change in this spec.

**Dev facts, verified 2026-09-20:**

- `{site}/backups/` and every chain directory are `www-data:www-data` 2770.
  A shell user cannot run a real site-profile run on dev; the runner test
  drives `BackupRunner` against a scratch output directory and the
  local-provider fixture, and the gates drive the scripts against throwaway
  trees with `--project-dir` / `--output-dir`.
- The site backup target is Backblaze, chain mode, project type. Nothing here
  needs it: every upload in the tests goes to the local-provider fixture.

**Facts you will need, verified 2026-09-20** (line numbers are for
orientation; re-grep before editing):

- `S3Signer` (`includes/S3Signer.php`): `put_file()` (163) sends a file handle
  as a streamed single PUT with `UNSIGNED-PAYLOAD` **and a known
  Content-Length** (`request()` sets `CURLOPT_UPLOAD` + `CURLOPT_INFILE`,
  510–591), so a pipe of unknown length cannot go that way — hence multipart,
  with a buffered single PUT only for a stream shorter than one part.
  `put_file_multipart()` (198) is the model: parts are strings signed with
  their real payload hash, so a retry re-sends the string it holds;
  `read_exactly()` (358) is the part reader to generalise to
  read-until-EOF; `plan_parts()` needs the size up front and is **not** used
  by the stream path; `build_complete_xml()` (320), `complete_body_ok()`
  (335), `abort_multipart()` (347), `MAX_ATTEMPTS = 3`, `MULTIPART_PART_BYTES
  = 100 MiB`, `MULTIPART_MAX_PARTS = 10000`. `request()` (384) returns
  `['status','body','headers','attempts','retry_log']`.
- Process handling precedent: `BackupVerifier::run_command()` (702) —
  `proc_open('bash -c …')` with stdout as a pipe, stderr to a 0600 temp file,
  an explicit environment from `inherited_env()`. Stream mode reads stdout
  in part-sized chunks instead of `stream_get_contents()`, then
  `proc_close()` for the exit code, then reads the report file.
- `backup_files.sh`: argument loop 84–100 (`--output-dir --project-dir --name
  --snar --key-file --exclude --plaintext`); `ARCHIVE` is derived at 118;
  `TAR_ARGS` 148–153; the tar pipeline 210–217 (`tar … -czf - | openssl enc
  … -pass fd:3 -out "$ARCHIVE" 3< "$KEY_FILE"`, `PIPESTATUS` captured);
  the 64-byte guard and the `LEVEL/ARCHIVE/BYTES/SHA256` lines 250–266;
  `TAR_RC` 1 accepted, ≥2 fails. The snapshot is chmod'd 600 after tar
  (238–241) — unchanged in stream mode.
- `backup_project.sh` (2.6.3): key sources `ARCHIVE_KEY_SOURCE` = `env`
  (`$BACKUP_ENCRYPTION_KEY`) or a file (`--key-file`, 136–160, 209–216);
  staging dir beside the output 303–318; rsync of the site into
  `${TEMP_DIR}/${BACKUP_NAME}/project_files/` 434–447 with the exclude list
  at 436–442; `apache_config/` 477–478; `shape.json` ~504; the tar block
  566–590 (`cd "$TEMP_DIR"; tar -czf - "$BACKUP_NAME" | openssl … -out
  "${BACKUP_DIR}/${FINAL_ARCHIVE}"`). `restore_project.sh` expects: one
  top-level directory (386), the dump at its root (416), `project_files/`
  (431), `apache_config/` (464).
- `backup_database.sh`: the encrypted path dumps to
  `mktemp /tmp/jy_backup_XXXXXXXX.sql` (109–110), then `gzip -9 < tmp |
  openssl … -pass fd:3 -out "$backup_file"` under `pipefail` in a subshell
  (119); the plaintext path already pipes (158). Remove the temp file and its
  sweep (108).
- `BackupRunner`: `execute_chain()` 616 (files engine → db engine → meta →
  `add_run` → `write` manifest → `upload_chain`; the failure branch clears
  the snapshot and calls `discard_failed_run()`); `run_files_engine()` 848
  (builds the command, `exec()`s it, `parse_kv()` on stdout, returns
  `{name, path, bytes, sha256, level, kind}`); `run_db_engine()` 887;
  `build_meta()` 941; `upload_chain()` 1010 and `upload()` 1209 (loops
  artifacts, `S3Signer::put_file()`, `BackupLedger::record()`, reports
  unledgered names); `discard_failed_run()` 812 (unlinks local artifacts by
  `path` and by `BackupChain::artifact_name()`, restores the manifest);
  `full_size_warning()` 774 takes a byte count; `execute_full()` 1091
  (`run_engine()` 1167 → `produced_archive()` 1200 finds the new file →
  names the sidecar → `upload()`); `ENGINE_TIMEOUT = 10800`.
- `BackupLedger::record($profile, $relname, $path, $object_key)` (149)
  hashes the path; `write()` (385) is the atomic replace; add
  `record_hash($profile, $relname, $sha256, $bytes, $object_key)` beside it
  sharing the entry-building and eviction code.
- `BackupChain::add_run()` (105) stores `{name, bytes, sha256}` per kind —
  the streamed artifact supplies the same three; `verify_artifact()` (236)
  checks a downloaded file and is unchanged.
- The manager credential: `AgentChannelEndpoint::mint_run_credentials()`
  (1094–1128) mints a Backblaze key with `['writeFiles']` on the node's
  prefix; multipart create/part/complete/abort are all `writeFiles`.
- Tests to copy from: `tests/backups/s3signer_multipart_test.php`
  (`s3mp_start_fixture()`, `s3mp_stop_fixture()`, `s3mp_fixture_assembled()`;
  keep parts under 1 KB so curl sends no `Expect: 100-continue`),
  `tests/backups/backup_chain_gate.sh` (real tar/openssl against a throwaway
  tree, replays deletions), `tests/backups/backup_files_sudo_gate.sh`,
  `tests/backups/backup_runner_test.php`, `tests/backups/backup_ledger_test.php`,
  `tests/backups/restore_database_envelope_test.php`.
- Docs to touch in `docs/backups.md`: *What a backup is* (117), *Uploads*
  (474), *Retention → Local* (559), *Disk and egress* (856), the settings
  table (981).

---

## What this is

A backup run writes the site's archive to local disk, then uploads it. On the
smallest nodes (25 GB) that means a site can only be backed up while its own
size is free beside it, and a site that grows past half the disk stops being
backed up at all. This spec streams the archive from tar straight into the
bucket through the signer's multipart path, so **no archive is ever on the
node**. What a run holds on disk shrinks to the compressed database dump.

It is independent of `backup_offloaded_files.md` (files in the cloud store).
That spec shrinks the tar by keeping offloaded files out of it; this one keeps
whatever tar produces off the disk. With both, a run's peak disk is the site
tree plus the compressed dump plus one object's ciphertext, and nothing else.

## The gap, precisely

Three places a run uses disk it does not need:

- **Chain mode.** `backup_files.sh` pipes tar into openssl and writes the
  encrypted archive to the chain directory
  (`maintenance_scripts/sysadmin_tools/backup_files.sh:210-217`); the runner
  then uploads that file (`BackupRunner::upload()`, `S3Signer::put_file()`).
  Peak: the site plus its compressed archive.
- **Standalone full mode.** `backup_project.sh` **rsyncs a whole copy of the
  site** into a staging directory beside the output, dumps the database into
  it, tars the copy to a second file, then deletes the copy
  (`backup_project.sh:303-311`, the rsync near line 434, the tar at 575).
  Peak: the site, plus a copy of the site, plus its archive — three times the
  site, which is why the staging directory had to be moved off `/tmp`.
- **The database dump.** `backup_database.sh` writes the **plaintext** dump
  to a temp file, then compresses and encrypts it to a second file
  (`backup_database.sh:110-119`). Peak: the uncompressed database plus the
  compressed one.

Local copies then linger: the default local retention keeps seven days of
artifacts, and the delete-after-upload setting defaulted to off until
`backup_offloaded_files.md` WP0 flipped it.

## Node disk

| | Today | After |
|---|---|---|
| Chain run | site + archive + dump (plain + compressed) | site + dump (compressed) |
| Standalone full run | site + **copy of site** + archive + dump (plain + compressed) | site + dump (compressed) |
| Database-only run | dump (plain + compressed) | dump (compressed) |
| Between runs | up to seven days of archives | dumps and metadata only, same window |

Memory: one upload part, 100 MiB, exactly what the multipart path costs today
for any artifact over 1 GiB. Nothing here raises it.

## How it works

### The signer streams

`S3Signer::put_stream($creds, $bucket, $path, $fh, $content_type)` uploads a
non-seekable stream of unknown length:

- It reads the stream one part at a time (`MULTIPART_PART_BYTES`, the
  existing constant), hashing and counting every byte as it goes.
- A stream that ends inside the first part is uploaded as **one signed PUT**
  of the buffered bytes, length known, exactly as a small file is today.
- Otherwise `CreateMultipartUpload` is issued when the first full part is in
  hand, each part goes up as today (in memory, signed with its real payload
  hash, retried on the ordinary request budget — a part in memory needs no
  seekable source), and `CompleteMultipartUpload` is issued after the stream
  closes, with the same 200-with-`<Error>` guard.
- It returns the usual response shape plus `bytes` and `sha256` of everything
  it sent. Any failure aborts the multipart upload, as today; the same
  cancel-unfinished-multipart lifecycle rule on the bucket is the backstop.
- The caller can ask it **not** to complete: `put_stream()` returns the
  handle before `CompleteMultipartUpload`, and the caller completes or aborts.
  This is what lets the runner refuse an archive whose producer failed after
  the stream closed (below). `put_file()` is unchanged.

The part cap arithmetic is what it is today: 100 MiB × 10 000 parts, about
1 TB per archive. No node is near it.

### The engine contract

Both archive engines gain a stream mode with one contract:

- **`--archive -`**: the encrypted archive is written to **stdout** and
  nothing else is. Every human line goes to stderr.
- **`--report FILE`**: after the stream is closed, the `LEVEL`, `TAR_RC`,
  `ENC_RC` lines the script prints today are written to this file, then the
  script exits. `ARCHIVE`, `BYTES` and `SHA256` are gone from the report — the
  runner has them from the upload.

The status arrives **after** the bytes. tar reports a file that changed while
it was being read as exit 1, which is accepted today, and a real failure as
2 or more, which today deletes the archive. So the runner:

1. Starts the engine with `proc_open`, hands its stdout to `put_stream()`.
2. When the stream closes, waits for the process, reads the report.
3. Completes the multipart upload only when the process exited 0, `TAR_RC`
   is 0 or 1, `ENC_RC` is 0, and at least 64 bytes went up (the existing
   empty-archive guard, now checked on the count rather than the file).
   Otherwise it aborts. **Nothing partial or empty is ever on the shelf**, and
   the chain's failure rule (clear the snapshot, discard the run, restore the
   manifest) applies as it does now.

### Chain mode (`backup_files.sh`, `execute_chain()`)

The files engine streams; the archive's `bytes` and `sha256` come from the
upload. The database dump and metadata are made on disk as today (both small,
both bounded) and uploaded by `upload_chain()` with the manifest, which now
also carries the already-uploaded files artifact. Artifact entries carry their
bucket `key`; a files artifact has no `path`.

A failure between the files upload and the manifest upload (the database
engine fails, say) leaves an uploaded files object the manifest does not name.
`discard_failed_run()` deletes it where the credential can delete (the site
profile). On the manager shelf the credential is write-only, so the object
stays until its chain is pruned whole — a bounded, harmless orphan, and the
same one a failed `upload_chain()` can leave today.

`full_size_warning()` reads the streamed byte count. `delete_local` has no
files artifact to delete; it still removes the dump and metadata.

### Standalone full mode (`backup_project.sh`, `execute_full()`)

Stream mode drops the rsync copy. The staging directory keeps only what is
not in the live tree: the compressed dump, `apache_config/` and `shape.json`,
all small. tar then archives the staged directory and the **live tree**
together in one invocation, piped through openssl to stdout under the
contract above.

The member layout is what `restore_project.sh` reads and does not change:
one top-level directory (`find -maxdepth 1 -type d`, line 386), the dump at
its root (line 416), `project_files/` under it (line 431), `apache_config/`
under it (line 464). The live tree therefore enters as
`{BACKUP_NAME}/project_files/…` — a second `-C` into the site root with a
`--transform` prefixing the members, and the same exclude list rsync applies
today. Symbolic links inside the tree stay links, as rsync `-a` kept them; the
transform applies to member names, not link targets. `execute_full()` streams
the result, names the envelope sidecar for the uploaded object's name, uploads
the sidecar, and records both.

### The database dump (`backup_database.sh`)

`pg_dump | gzip | openssl` in one pipeline under `pipefail`, to the output
file. The plaintext temp file and its stale-leftover sweep go. The dump still
lands on disk compressed and encrypted, and is uploaded as today: it is the
one artifact that stays local, and it is the smallest.

### The ledger

`BackupLedger::record_hash($profile, $relname, $sha256, $bytes, $object_key)`
records an artifact the machine hashed as it streamed. The ledger's claim —
this machine made these bytes, hashed at the moment they went up — holds:
the hash is taken by the process that pushed them, from the same bytes.
`record()` remains for artifacts that exist on disk.

### What stays on disk between runs

The dump, the metadata artifact, the chain manifest and the snapshot. Local
retention and delete-after-upload govern the first two; the manifest and
snapshot are never swept, as today. A restore or a verify fetches from the
bucket, as it does now — `BackupStaging` already re-uses only what is in its
own work directory, never the backup directory.

### The manager profile

Same code, same credential. The per-run key is a Backblaze application key
minted with the single capability `writeFiles`, restricted to the node's name
prefix (`AgentChannelEndpoint.php:1116-1126`). Every multipart call the
stream makes — create, upload part, complete, and abort (`b2_cancel_large_file`
on the S3 surface) — is a `writeFiles` operation, and `put_file()` already
takes the multipart path under this key for every dump over 1 GiB. Nothing new
is asked of the credential.

## What this does not change

- **Verify and restore** still download the set to disk and check free space
  first (`BackupVerifier::disk_check()`). The double-space need during a
  backup goes; the one during a restore does not.
- The part size, the multipart threshold for `put_file()`, the retry budget.
- Artifact names, manifest shape, envelope format, the restore scripts' inputs.
  A restore cannot tell a streamed archive from a written one, exactly as it
  cannot tell a multipart upload from a single PUT today.

## Admin surfaces

- **Backups page:** the run message no longer says the local copy was removed
  (there is none); the help text for *Delete the local copy once uploaded*
  says it governs the database dump and metadata.
- **Backups tab (node):** unchanged.

No new settings.

## Tests

- `tests/backups/s3signer_stream_test.php` (safe, local-provider fixture from
  `s3signer_multipart_test.php`): a stream shorter than a part goes as one
  PUT; a longer one reassembles to the exact bytes; `bytes` and `sha256`
  match; a failed part aborts and nothing is claimable; a caller-declined
  completion aborts.
- `tests/backups/backup_files_stream_gate.sh` (db, real tar and openssl):
  the stream decrypts to the same tree the file mode produces; the report is
  written only after stdout closes; a tar failure of 2 or more is reported
  and the runner-side rule refuses it; incremental snapshots advance
  identically in both modes.
- `tests/backups/backup_project_stream_gate.sh` (db): a streamed standalone
  archive restores through `restore_project.sh` to the same tree and
  database as a written one; no staging copy of the tree is made (the
  staging directory holds only the dump).
- `tests/backups/backup_runner_stream_test.php` (db, local-provider fixture):
  a chain run leaves no files archive in the chain directory; the manifest's
  `bytes` and `sha256` equal the assembled object's; an engine that fails
  after streaming leaves nothing on the fixture and the run fails under the
  existing discard rule; `full_size_warning()` sees the streamed count.
- `tests/backups/backup_ledger_test.php` gains `record_hash()`.
- `tests/backups/restore_database_envelope_test.php` (or the database gate)
  proves the pipelined dump opens unchanged.

## Docs

`docs/backups.md` — *What a backup is*, *Uploads*, *Retention → Local*, *Disk
and egress*, the settings table. `backup_database.sh` and both archive
scripts carry the contract in their headers. Current state only.

## Out of scope

- Streaming the database dump; it is the smallest artifact and the one a
  database-only restore reads from disk.
- Streaming a restore or a verify.
- Changing the part size or the multipart threshold.

## Work packages

- **WP1** `S3Signer::put_stream()` and its test.
- **WP2** `backup_files.sh` stream mode, `execute_chain()` on it,
  `BackupLedger::record_hash()`, `discard_failed_run()` remote delete, gate
  and runner test.
- **WP3** `backup_project.sh` stream mode without the staging copy,
  `execute_full()` on it, gate.
- **WP4** `backup_database.sh` pipeline.
- **WP5** Docs, Backups page wording, settings help text.
