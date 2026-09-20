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

- The archive streams, and so does the database dump in chain mode and in
  database-only mode. Only the small metadata artifact lands on disk.
- The runner completes a multipart upload only after the engine's exit status
  is known; the status arrives after the bytes.
- The manager credential is not changed by this spec; multipart abort is
  already within `writeFiles` (§ The manager profile). **But see the
  brokered-shelf block below:** put the signing of each multipart call
  behind one seam so that credential can later be no credential at all.
- The part size stays at the existing constant.

**Brokered shelf — deltas from `services_phase2_platform.md` D3 (owner,
2026-09-20).** The shelf a management node keeps for its nodes is becoming
*brokered*: no node ever holds a storage credential; for every object it
asks the plane for a presigned URL (PUT, GET, and multipart create / part /
complete / abort), signed inside the node's own prefix, never a delete. That
lands as phase 2 items 2a (the broker, presigning beside `presign_get`)
and 2b (an object-store seam in the engine: a direct implementation that is
today's signer with a credential, and a brokered one that asks the plane),
**after this spec, never across it** — phase 2 §10 says so. What 2b needs
from this build, so it is a swap and not a rewrite, **this build already
provides** (executor, 2026-09-20): `put_stream()`, `complete_stream()` and
`abort_stream()` issue every multipart call — create, part, complete, abort
— through the signer's one private `request()` → `attempt()` seam; nothing
signs inline. Today that seam signs with `$creds`; the brokered store makes
it return URLs the plane signed. Part payload hashing, retries and the
complete-only-after-status rule are the loop's and do not move. The runner
reaches the signer only through `destination()` (creds, bucket, base key)
and `stream_engine()`; 2b turns that triple into a store object.
- `discard_failed_run()`'s remote delete of an orphaned files object is a
  site-profile act. On a brokered shelf it becomes *abort the unfinished
  multipart through the broker*; a completed orphan is the plane's ledger's
  to prune. Write the delete behind the same seam.
- The per-run `writeFiles` key in the manager profile stays until 2a
  retires it; nothing here should make it harder to remove (no new reads of
  `mint_run_credentials()` outside `plan_manager()`'s existing slot).

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
- `backup_database.sh` (3.4): the encrypted path dumps to
  `mktemp /tmp/jy_backup_XXXXXXXX.sql` (109–110), then `gzip -9 < tmp |
  openssl … -pass fd:3 -out "$backup_file"` under `pipefail` in a subshell
  (119); the plaintext path already pipes (158); the output name is
  `${db_name}-${now}.sql.gz.enc` (99). Remove the temp file and its sweep
  (108); add `--archive -` / `--report FILE` beside `--non-interactive` and
  `--key-file` (argument loop from 418).
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
bucket through the signer's multipart path, and streams the database dump
the same way, so **no archive and no dump is ever on the node**. What a chain
run holds on disk shrinks to the metadata artifact, a few kilobytes.

It is independent of `backup_offloaded_files.md` (files in the cloud store).
That spec shrinks the tar by keeping offloaded files out of it; this one keeps
whatever the engines produce off the disk. With both, a run's peak disk is
the live site tree plus one object's ciphertext, and nothing else. On a
mail-heavy site the database *is* the site — dev's is 925 MB, dominated by
mail-adjacent tables — which is why the dump streams too.

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
| Chain run | site + archive + dump (plain + compressed) | site |
| Standalone full run | site + **copy of site** + archive + dump (plain + compressed) | site + dump (compressed, inside the staging directory for the length of the tar) |
| Database-only run | dump (plain + compressed) | nothing |
| Between runs | up to seven days of archives | metadata, manifest and snapshot only |

The standalone archive carries the dump **inside** the tar, so that mode alone
must have the compressed dump on disk while tar reads it. It is deleted with
the staging directory the moment the stream closes.

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

The files engine streams, then the database engine streams, each under the
contract above; both artifacts' `bytes` and `sha256` come from the upload.
The metadata artifact is made on disk as today (kilobytes) and uploaded by
`upload_chain()` with the manifest, which now also carries the two
already-uploaded artifacts. Artifact entries carry their bucket `key`; a
streamed artifact has no `path`. `run_db_engine()` no longer renames a
produced file: the artifact name `db-{seq}.sql.gz.enc` is the object key from
the start.

A failure between the files upload and the manifest upload (the database
stream fails, say) leaves an uploaded files object the manifest does not name.
`discard_failed_run()` deletes it where the credential can delete (the site
profile with its own target). On the manager shelf the credential is
write-only, so the object stays until its chain is pruned whole — a bounded,
harmless orphan, and the same one a failed `upload_chain()` can leave today.
Under the brokered shelf the same orphan is a ledger row the plane's
reconcile drops (phase 2 §3), and an *unfinished* multipart is aborted by
asking the broker.

`full_size_warning()` reads the streamed byte count. `delete_local` has no
files or database artifact to delete; it still removes the metadata artifact.

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

`pg_dump | gzip | openssl` in one pipeline under `pipefail`. The plaintext
temp file and its stale-leftover sweep go in every mode. The script gains the
same stream contract as the archive engines — `--archive -` writes the
encrypted dump to stdout, `--report FILE` records `DUMP_RC` and `ENC_RC`
after the stream closes — and the runner completes the upload only when
`pg_dump` exited 0. A dump that failed part-way is never on the shelf.

Chain mode streams the dump as `db-{seq}.sql.gz.enc`. Database-only mode
(`backup_type = database`, `execute_full()` → `run_engine()`) streams it as
the standalone artifact and uploads the envelope sidecar beside it. The
standalone **project** archive is the one place the dump is written to disk:
it is a member of that tar, so it is dumped into the staging directory
compressed, archived, and deleted with the directory.

A database-only restore reads a local file, as it does today, and the file
arrives the way every artifact does: `download_backup` fetches it from the
shelf by presigned link, ledger-checked. Nothing on that path changes.

### The ledger

`BackupLedger::record_hash($profile, $relname, $sha256, $bytes, $object_key)`
records an artifact the machine hashed as it streamed. The ledger's claim —
this machine made these bytes, hashed at the moment they went up — holds:
the hash is taken by the process that pushed them, from the same bytes.
`record()` remains for artifacts that exist on disk.

### What stays on disk between runs

The metadata artifact, the chain manifest and the snapshot. Local retention
and delete-after-upload govern the first; the manifest and snapshot are never
swept, as today. A restore or a verify fetches from the
bucket, as it does now — `BackupStaging` already re-uses only what is in its
own work directory, never the backup directory.

### The manager profile

Same code, same credential, for now. The per-run key is a Backblaze
application key minted with the single capability `writeFiles`, restricted to
the node's name prefix (`AgentChannelEndpoint.php:1116-1126`). Every multipart
call the stream makes — create, upload part, complete, and abort
(`b2_cancel_large_file` on the S3 surface) — is a `writeFiles` operation, and
`put_file()` already takes the multipart path under this key for every dump
over 1 GiB. Nothing new is asked of the credential.

The credential itself is going: under the brokered shelf (phase 2 D3, the
block at the top) the job carries a per-run token in the slot the key
occupies today, and the node's run asks the broker for each URL the stream
needs. The B2-only key mint is what made the manager profile provider-bound;
with it gone the shelf is any S3-compatible store. This spec's loop is the
one the brokered store drives, which is why the signing sits behind a seam.

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
  says it governs the metadata artifact and a standalone archive's staging.
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
- `tests/backups/backup_database_stream_gate.sh` (db): a streamed dump
  decrypts and loads into a throwaway database identical to a written one; no
  plaintext temp file exists at any point (the `jy_backup_*` glob is empty
  throughout); a `pg_dump` failure is reported after the stream and the
  runner-side rule refuses it; a database-only run leaves nothing in the
  output directory but the envelope sidecar's upload record.
- `tests/backups/restore_database_envelope_test.php` proves a streamed dump
  opens unchanged.

## Docs

`docs/backups.md` — *What a backup is*, *Uploads*, *Retention → Local*, *Disk
and egress*, the settings table. `backup_database.sh` and both archive
scripts carry the contract in their headers. Current state only.

## Out of scope

- Streaming a restore or a verify.
- Changing the part size or the multipart threshold.

## Work packages

- **WP1** `S3Signer::put_stream()` and its test.
- **WP2** `backup_files.sh` stream mode, `execute_chain()` on it,
  `BackupLedger::record_hash()`, `discard_failed_run()` remote delete, gate
  and runner test.
- **WP3** `backup_project.sh` stream mode without the staging copy,
  `execute_full()` on it, gate.
- **WP4** `backup_database.sh` pipeline and stream mode, `run_db_engine()`
  and the database-only `execute_full()` path on it, gate.
- **WP5** Docs, Backups page wording, settings help text.
