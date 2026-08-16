# Streamed backups

**Status:** proposed
**Owner concern:** a site cannot be required to hold a second and third copy of
itself on local disk in order to be backed up.

## Why

A backup today needs far more free disk than the thing it is backing up, and the
requirement grows with the site. That is the wrong shape: the disk a site runs on
is sized for the site, not for a transient multiple of it, and the first thing a
growing site loses is its ability to protect itself.

Worked example, the node this was found on. `jeremytunnell` is a 25GB disk
carrying a 529MB site. Importing one Gmail archive takes it to roughly 12GB.
Backing that up then wants a staging copy of the tree plus the compressed
encrypted archive beside it — so the site becomes unbackupable on its own disk
long before the disk is full of site.

The fix is to stop landing bytes that only exist to be uploaded.

## What it costs today

Three copies exist at peak, not one:

1. **The staging tree.** `backup_project.sh` makes
   `.staging_XXXXXX/{BACKUP_NAME}/` and copies the project files into it,
   alongside the database dump and the captured Apache config, then tars *that*.
   Cost ≈ the size of the site.
2. **The database dump**, written into staging as its own file before it is
   tarred. Cost ≈ the dump.
3. **The finished archive.** The tar is already a pipeline —
   `tar -czf - | openssl enc -aes-256-cbc -salt -pbkdf2 -pass fd:3 -out FILE` —
   but its last stage lands a file, which is then uploaded and (if
   `backup_delete_local_after_upload` is set) deleted. Cost ≈ the compressed
   archive.

Only (3) is addressed by "stream the upload". A spec that stops there leaves the
larger cost in place.

## What changes

Three changes, independently useful, in the order they are worth doing.

### S1 — the archive is never written to disk

Replace the pipeline's `-out FILE` with a writer that uploads as it is fed. The
tar and encryption stages are unchanged; the archive exists only as bytes in
flight.

### S2 — nothing is staged that can be read in place

Staging exists to assemble one coherent tree with a stable top-level name. That
does not require copying the project files: `tar` can take several sources with
`-C` and rewrite their paths with `--transform`, and the database dump can be fed
in from a fifo rather than a file. Staging shrinks to the small assembled
artifacts (`shape.json`, `backup_info.txt`, the Apache config) and the project
tree and dump are streamed straight into the archive.

### S3 — integrity is computed in flight

`sha256` and `bytes` are read off the finished file today (`hash_file()`,
`filesize()`). A restore verifies against both, so neither may simply be dropped:
they are computed by teeing the stream through a hashing filter as it uploads,
and recorded exactly as they are now.

## The hard parts

These are the reasons this is not a small change, and each needs a decision.

**A pipe cannot be rewound.** S3 multipart wants parts of at least 5MB and
retries a failed part by re-reading it. A tar pipeline cannot seek backwards, so
the uploader must hold the current part in memory to retry it, and a failure that
outlives the buffered part aborts the run rather than resuming it. **Decision:
one part in memory, retried within its own budget; anything worse fails the run.**
A failed run already leaves the previous backup untouched, which is what makes
that acceptable.

**The backup path has no multipart implementation.** Backups do not use the AWS
SDK. They use `S3Signer`, hand-rolled SigV4 with a single `put_file()` and no
`CreateMultipartUpload` / `UploadPart` / `CompleteMultipartUpload`. All three
supported providers (`b2`, `s3`, `linode`) are S3-compatible over SigV4, so one
implementation covers them — but it has to be written. The mailbox and file
stores use the AWS SDK through `CloudStorageS3Driver`, which already ships
`MultipartUploader`; deciding whether the backup path adopts that driver instead
of extending `S3Signer` is the first architectural call in this spec.

**SigV4 needs to know the payload hash before it signs.** That is impossible for
a body being generated as it is sent. The two ways out are `UNSIGNED-PAYLOAD`
(acceptable only because every target is HTTPS) or streaming chunked signing.
**Decision: `UNSIGNED-PAYLOAD` per part, with S3's own per-part MD5/ETag check
and our S3 hash over the whole stream as the integrity story.** Note that the
current single-PUT path also caps an object at 5GB, which a 12GB site would
already exceed — so multipart is not only a streaming concern, it is a
correctness bug waiting for the first large site.

**Tarring a live tree can race a writer.** Staging incidentally froze the files.
Reading them in place means `tar` may see a file change under it. **Decision:
treat `tar`'s "file changed as we read it" as a warning, not a failure, for
paths under `uploads/` and `storage/` — content-addressed and append-mostly — and
as a failure anywhere else.** The database is consistent regardless, because the
dump is a single transaction.

## What must not change

These are load-bearing and a streaming implementation must preserve them:

- **The envelope is minted before the archive exists and sealed to it after.**
  A run must never be able to upload someone else's archive under its own
  envelope.
- **Retention runs last, and only after a confirmed upload.** Retention deletes
  backups; a run that failed to upload must not be the run that decides what to
  delete.
- **`sha256` and `bytes` are recorded for every artifact** and mean the same
  thing they mean today.
- **Chain manifests and per-artifact naming** (`BackupChain::artifact_name`) are
  untouched. Streaming changes how bytes reach the bucket, not what is in it.
- **A restore is unaffected.** The objects it downloads are byte-identical to
  what the current path produces.

## Compatibility

Streaming is the path when the target supports multipart and the engine can
write to stdout. Anything else — a driver without multipart, a format that must
be assembled before it can be read — falls back to the current
write-then-upload, unchanged. No deployment is required to adopt this to keep
working, and no setting is required to turn it on: it is the better path taken
automatically when it is available, which is the rule everywhere else on the
platform.

## Acceptance

1. A full backup of a site larger than the free space on its disk completes and
   restores. This is the acceptance test; nothing else demonstrates the point.
2. Peak additional local disk during a backup is bounded by the part buffer plus
   the small staged artifacts — not by any multiple of the site.
3. `sha256` and `bytes` recorded by a streamed run match those of a
   write-then-upload run over the same content, byte for byte.
4. A part failure mid-upload aborts the run, leaves no partial object claimable
   as a backup, and leaves the previous backup and its retention untouched.
5. An object larger than 5GB uploads and restores — the case the single PUT
   cannot do at all today.
6. A file modified under `uploads/` during a run produces a warning and a valid
   archive; a file modified under `public_html/` fails the run.

## Documentation

When this lands, `docs/backups.md` gains the developer-facing account: how the
streamed path is selected, what the part buffer costs in memory, the
`UNSIGNED-PAYLOAD` decision and why HTTPS makes it acceptable, the live-tree
warning rule, and the failure semantics of an aborted multipart. Written as the
current state, with no reference to the write-then-upload path having come
first — except where fallback behaviour genuinely still exists, which is
described as a capability, not as history.

## Out of scope

- **Restore streaming.** A restore already pulls to disk and needs the space it
  needs; that is a separate question with a different answer.
- **Changing what goes into a backup.** This spec moves the same bytes.
- **`backup_delete_local_after_upload`.** It stays, and stays meaningful, for the
  fallback path.
- **The unencrypted-tarball concern** recorded separately: a backup tarball
  contains `secret_box_key`. Streaming neither helps nor worsens it and must not
  be conflated with it.
