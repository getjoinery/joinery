# Backup multipart upload and failed-run cleanup

**Status:** BUILT 2026-08-24 (S3Signer 1.4, BackupRunner 1.4, two new safe-tier
tests, docs updated; safe tier 128/128). OPEN: live acceptance 1–3 (a >5 GB
object to real B2, node 176's nightly via multipart, fault injection), the B2
lifecycle rule, and the one-time node 176 chain-dir cleanup.
**Date:** 2026-08-24
**Trigger:** the 2026-08-23 backup failure on jeremytunnell.com (node 176).

## The incident

The nightly manager-profile backup of jeremytunnell.com failed at 04:18 UTC:

    Upload of files-0002.tar.gz.enc failed: File size too big: 6246548736

The Takeout import (97k messages, ~3.7 GB of per-file-sealed attachments in
`uploads/`, plus import working data present mid-run) pushed the incremental
archive to 5.9 GB. `S3Signer::put_file()` is a single PUT by design, and the
S3-compatible single-PUT ceiling on every supported provider is 5 GB. B2
rejected the upload.

Two consequences, one fix each:

1. **The upload cap is a correctness bug, not a tuning problem.** The node's
   tree is now ~4.1 GB and grows with every message. The next site to cross
   5 GB loses backups *permanently*: the failure path deliberately abandons the
   chain and starts a new full — which is also over the cap. Incrementals only
   postpone the ceiling; a chain still starts with a level-0 full.
2. **A failed run strands its artifacts on disk.** Local artifact deletion runs
   only after success, so the failed run left 7 GB (5.9 GB files + 1.2 GB db
   dump) in `backups/manager/chain-20260821_040009/` on a 49 GB disk. Nothing
   reclaims it until that chain ages out of cloud retention weeks later.

This was already on the books: `specs/backups_remaining_gaps.md` item 3 called
multipart "the first thing that will force itself back in". It has.

## Fix 1 — multipart upload in S3Signer

### Shape

Extend `S3Signer`, not adopt the AWS SDK. The backup path is deliberately
dependency-free hand-rolled SigV4 (`CloudStorageS3Driver` and its SDK
`MultipartUploader` serve the file stores, which have different custody rules),
and all three provider drivers — `b2`, `s3`, `linode` — speak the same SigV4
S3 API, so one implementation covers the fleet.

`put_file()` chooses the path itself: at or below the threshold it does the
single PUT it does today; above it, multipart. **No caller changes, no
setting.** The better path is taken automatically when the object needs it,
which is the rule everywhere else on the platform.

New internals (S3 multipart API, all SigV4-signed like every existing request):

- `CreateMultipartUpload` (POST `?uploads`) → uploadId
- `UploadPart` (PUT `?partNumber=N&uploadId=`) per part → ETag
- `CompleteMultipartUpload` (POST `?uploadId=`) with the part/ETag manifest
- `AbortMultipartUpload` (DELETE `?uploadId=`) on any failure, best-effort,
  in a `finally`

### Decisions

- **Threshold 1 GB, part size 100 MB** (constants, not settings). The
  threshold is set low enough that node 176's nightly ~1.2 GB database dump
  crosses it: the multipart path then runs every night on a real node instead
  of only in the emergency it exists for. A path exercised only in emergencies
  is broken when the emergency comes. 100 MB parts × the API's 10,000-part cap
  gives ~1 TB per object — far beyond any plausible artifact.
- **Each part is read into memory, hashed, and signed** (`x-amz-content-sha256`
  per part, exactly like every request S3Signer makes today). The source is a
  file on disk, so a part can be re-read and retried; no UNSIGNED-PAYLOAD
  shortcut is needed. Peak memory is one part (100 MB), which the smallest
  fleet VPS can afford.
- **Per-part retry** reuses the existing `attempt()`/`is_retryable()` budget.
  A part that exhausts its budget fails the run; the abort in `finally` means
  no half-object survives to be mistaken for a backup.
- **`CompleteMultipartUpload` can return HTTP 200 with an error document in
  the body.** The implementation must parse the completion body and treat an
  `<Error>` payload as failure regardless of status. This is the classic S3
  multipart trap and gets its own unit test.
- **Orphaned-part backstop:** the abort is best-effort (the process can die).
  Each provider's bucket should carry a cancel-unfinished-multipart lifecycle
  rule (B2: "cancel unfinished large files after 7 days"). That is bucket
  configuration, not code; it folds into the already-open B2 lifecycle item in
  the fleet backups work and gets a line in `docs/backups.md`.

### What does not change

- `sha256` and `bytes` are computed from the local file exactly as today; a
  restore verifies against them and cannot tell how the object was uploaded.
- Downloads (`get_to_file`), listing, delete, retention, chain manifests,
  envelope sealing order: untouched. Multipart changes how bytes reach the
  bucket, not what is in it.
- `TargetTester` and its small probe object: untouched.

## Fix 2 — a failed run cleans up after itself

In `execute_chain()`, the failure path today unlinks the tar snapshot (so the
next run starts a fresh chain — correct, keep) and rethrows, leaving the failed
run's artifacts and an advanced local manifest in the chain directory.

Add to the same catch block:

- **Delete the failed run's artifacts** — every path collected in
  `$artifacts` so far, plus any file matching this run's sequence-numbered
  artifact names in the chain dir (covers an engine that wrote a file before
  throwing).
- **Restore the pre-run manifest.** Hold the manifest as it was before
  `add_run()`, and write it back on failure, so the local manifest never
  describes a run the bucket does not hold. (The bucket's manifest copy is
  already consistent — it only uploads after the artifacts.)

The chain directory itself stays; chain retention already `rmtree`s it when
the chain leaves retention.

## Operational follow-up (node 176)

One-time, after Fix 1 ships in a release: delete the stranded artifacts at
`/var/www/html/jeremytunnell/backups/manager/chain-20260821_040009/` (7 GB).
Retention would get there eventually; the disk is 62% full and should not wait.

## Acceptance

1. An object larger than 5 GB uploads to B2 and downloads back byte-identical
   (sha256 verified) — the case the single PUT cannot do at all.
2. Node 176's nightly backup succeeds with the database dump travelling via
   multipart, proven by the artifact landing and restoring — the routine
   exercise that keeps the path honest.
3. A part failure mid-upload fails the run, aborts the multipart upload (no
   claimable partial object), deletes the run's local artifacts, restores the
   local manifest, and the next run starts a fresh chain — verified by fault
   injection.
4. Unit tests (safe tier): part planning (threshold, part count, last-part
   size), Complete XML assembly, and the 200-with-`<Error>`-body completion
   trap.
5. `docs/backups.md` describes the multipart path, the one-part memory bound,
   and the lifecycle-rule backstop, written as current state.

## Related specs

- `specs/backups_remaining_gaps.md` item 3 — this spec closes it.
- `specs/streamed_backups.md` — the larger streaming redesign. It needs a
  multipart primitive and lists its absence as a hard part; this spec supplies
  that primitive for file-on-disk artifacts. Streaming (pipe-fed parts,
  UNSIGNED-PAYLOAD, one-part-in-memory retry) remains its own decision and is
  out of scope here. Nothing here blocks or presupposes it.

## Out of scope

- Streaming / not landing archives on disk (`specs/streamed_backups.md`).
- Changing what goes into a backup, chain cadence, or retention counts.
- Restore-side changes of any kind.
