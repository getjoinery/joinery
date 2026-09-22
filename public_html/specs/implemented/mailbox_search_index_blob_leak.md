# The Search Index Lives at One Path Per User

**Status:** IMPLEMENTED 2026-09-22. WP1–WP6 built 2026-09-22 (mailbox plugin 1.119.0);
WP1 ran on dev by hand (903 Files) and on every other node as the mailbox migration
`imi_002_reclaim_search_index_files` (released in 0.8.420). WP7 read 2026-09-22 on
0.8.421 / agent 1.42.0: the only mailbox nodes are dev (verified, zero) and
jeremytunnell.com (176: one unheld pre-move copy, `mailfts_1_ttjre2po.bin`, 0 MiB,
which the nightly mailbox index sweep removes). The confirming re-read of 176 is
tracked outside this spec.

## Brief

The persisted mailbox search index stops being a `File`. It becomes one sealed
file per user at a fixed path, written by temp name and `rename()`. One name
per user means a second copy cannot exist, whatever fails. Cleanup on user
deletion, a standing sweep, and a health count make any leftover visible.

**Rules that bind this work** (CLAUDE.md governs; these are the ones that
bite here): no commit, no `git add`; no schema change (a data migration is fine); docs describe the
current state only; bump `@version` on every PHP file touched; `php -l` and
`validate_php_file.php` on every class file edited; tests use the harness and
carry `@joinery-test`; never run the runner as root.

**Decided, do not reopen:** the index is regenerable (a lost copy costs CPU);
it is not a File and not a file version; the in-place blob swap is rejected;
strays are reclaimed through `File::permanent_delete()`, never raw SQL or `rm`.

## Background (read once)

- `MailboxIndex::persistOrThrow()` (`plugins/mailbox/includes/MailboxIndex.php`
  ~483) writes a new private File per persist and deletes the previous one in
  a silent catch. On node 176 the delete failed for ten weeks: 157 copies,
  13.1 GiB, disk full. Incident:
  `/var/www/html/joinerytest/incidents/2026-09-22-jeremytunnell-full-backup-enospc.md`.
- **Cause (B1), already fixed fleet-wide:** node 176 carried deletion rules
  `fil_files → pro_products` / `prq_product_requirements` for store tables it
  never had; the delete engine's COUNT on them threw and rolled back every
  File delete on the node. `DeletionRule::pruneOrphanedRules()` v1.2 (commit
  7d98f7f5, 0.8.407) prunes such rules at plugin sync; node 176 pruned them in
  job 21913 on 2026-09-17. Nothing here re-fixes B1.
- **B2:** the catch was silent. **B3:** a File per persist needs a second
  operation to succeed, runs the deletion-rules engine on every persist, sits
  in every backup (`uploads/` is backed up; that is what filled the disk), is
  eligible for cloud offload, and is never deleted with its user: the
  `usr_users → fil_files` rule is `set_value 3`, so dev holds 903 test-made
  index files owned by user 3.

## The design

Path: `PathHelper::getSiteRoot() . '/cache/mailfts/' . $user_id . '.bin'`,
written to `{user_id}.bin.tmp` in the same directory, then `rename()`.

- Directory `0770`, files `0660` (php-fpm and CLI both write; same as
  `uploads/`). The host converger does not touch `cache/`. `cache` is in the
  backup script's always-skipped set and is a named volume on Docker hosts.
- Sealing is unchanged: `VaultCrypto::sealFieldFile()` into the `.tmp`, fresh
  item DEK sealed to `uev_public_key`, associated data `blobAd()`.
- Bookkeeping (`imi_inbound_mailbox_search_index`) keeps `imi_sealed_key`,
  `imi_format`, `imi_blob_high_water`, `imi_refold_ids`. `imi_fil_file_id`
  becomes unused and stays in place. "No blob yet" in `fold()` (~199) means
  the path does not exist, not `imi_fil_file_id <= 0`.
- Restore (`restoreFromBlob()` ~405): path exists, `SealedBox::isStreamFile()`,
  format stamp matches, then `openFieldFile()` into `/dev/shm` as now.
  Anything else returns false and the caller rebuilds.
- Persist runs under the fold lock, so persists never overlap. `purgePersisted()`
  (~293) unlinks `.bin` and `.tmp`.
- **Transition** for a bookkeeping row that names a File: write the path, set
  `imi_fil_file_id` null, save, then `permanent_delete()` the old File in a
  catch that logs the file id and message and never fails the persist.
  Bookkeeping first, because the `fil_files → imi` cascade rule is still
  registered until the class change syncs.

## Work packages

| WP | Do | Done when |
|----|----|-----------|
| WP1 | Mailbox migration `imi_002_reclaim_search_index_files`: every `fil_files` row with `fil_source = 'mailbox_search_index'` that no live bookkeeping row names is deleted through `permanent_delete()`; a shared blob or a failure is logged and skipped, never stops the upgrade. | Node 176 logs 157 deleted at its next upgrade; the health check reads zero |
| WP2 | Every catch around a File delete or unlink in `MailboxIndex` (persist ~527, `purgePersisted` ~300) logs the id or path and the exception. | A failed cleanup appears in the error log |
| WP3 | `MailboxIndex`: persist to and restore from the path as above; the transition step; `purgePersisted()` unlinks. Class docblock updated. | 100 persists leave one `.bin`, no `.tmp`, no File |
| WP4 | `plugins/mailbox/data/inbound_mailbox_search_index_class.php`: `permanent_delete()` override unlinks `.bin` and `.tmp` then calls parent; `imi_usr_user_id` action `cascade` → `permanent_delete`; remove the `imi_fil_file_id` action; docblock updated. Run plugin sync so the rules re-register. | Deleting a user removes their index file |
| WP5 | `sweepWorkingCopies()` (same class, ~103) also removes any `cache/mailfts/{uid}.bin` whose uid has no bookkeeping row and any `.tmp` older than an hour; returns counts. `InboundEmailHealth::checkSearchIndexStorage()` beside `checkSearchIndexEngine()` (~382): fails naming the count when any `mailbox_search_index` File exists or the sweep would remove anything; reports directory size. Register it wherever `checkSearchIndexEngine()` is listed. | Health page names a stray; the next sweep removes it |
| WP6 | Docs: `plugins/mailbox/docs/overview.md` index paragraph (~1584–1620); the two docblocks above; one line in the incident report pointing here. | — |
| WP7 | After release: every mailbox node checked through WP5's count; the migration's log line names what each reclaimed. | Every mailbox node reports zero |

## Tests (tier db)

| Test | Pins |
|---|---|
| `plugins/mailbox/tests/mailbox_index_persist_test.php` (new) | N persists leave exactly `{uid}.bin`, no `.tmp`, no File for the user, `imi_fil_file_id` null; a pre-existing `.tmp` is overwritten; a File-backed row is transitioned on first persist; when the old File delete throws, the persist succeeds and one log line names the id; `User::permanent_delete()` removes the file; `purgePersisted()` removes `.bin` and `.tmp` |
| `mailbox_index_stream_persist_test.php` (rewrite File assertions to path assertions) | Restore from the path without a rebuild; wrong stamp or legacy format is refused and rebuilt |
| sweep + health (new or in the above) | Sweep removes an orphan `.bin` and a stale `.tmp`, leaves a live user's file; health passes at zero and fails naming the count otherwise |

Fixture: copy the vault/domain/alias/grant setup from
`mailbox_index_stream_persist_test.php` lines 46–83. The four suites that fold
without registering the File (`mailbox_index_incremental_fold`, `drafts_fts`,
`index_readable_text`, `mailbox_trash`) need no change once WP4 lands.

## Out of scope

Retiring `File::SOURCE_MAILBOX_SEARCH_INDEX` and the `imi_fil_file_id` column
(after WP7 reports zero everywhere); the `/dev/shm` working copy's file mode;
the backup engine; whether the index belongs on disk at all.
