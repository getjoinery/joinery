# Cloud storage: one private bucket, private files only — hand back

**Built:** 2026-09-21, from `specs/implemented/cloud_storage_private_only.md`. Nothing
committed, nothing staged, no database written. WP1–WP5 done in order; the
`--changed` loop and the `db --changed` gate as pasted below.

Where a file was already modified in the tree by the provider-picker work
(uncommitted), the line says what *this* build changed in it.

## 1. Files changed

| Path | Version | What changed |
|---|---|---|
| `settings.json` | — | `cloud_storage_private_bucket`, `cloud_storage_public_base_url`, `cloud_storage_private_enabled`, `cloud_storage_private_draining` are `managed` declarations whose helptext starts DEAD (no group, no label); `cloud_storage_bucket` help reads "A private bucket used for nothing else. Not the backup bucket. Save refuses a bucket anyone can read."; `cloud_storage_draining` help names the one bucket |
| `storage_profiles.json` | — | `BlobStorageProfile` only; the comment says every profile is private |
| `includes/cloud_storage/BlobStorageProfile.php` | 1.3 | `visibility()` answers `private`; `eligibilityWhere()` is `fbb_is_private = TRUE`; `isEligibleRow()`, `backupObjects()`, `backupObject()` take private blobs only |
| `includes/cloud_storage/StorageProfileRegistry.php` | 1.1 | `all()` refuses a profile whose `visibility()` is not `private` (logged, left out); `forVisibility()` removed |
| `includes/cloud_storage/CloudStorageDriverFactory.php` | 2.0 | one binding: `driver()`, `driverUnlatched()`, `driverWithFallback()`, `binding()`, `fromOptions()`, `reset()`; no visibility argument, no `default()`, no `public_base_url` |
| `includes/cloud_storage/CloudOffloadEngine.php` | 1.3 | resolves the driver with no argument; log lines drop the visibility |
| `includes/cloud_storage/CloudStorageLifecycle.php` | 2.0 | `testConnection($opts)` runs own bucket and key, Reach, Write, Private (the gate), Delete, in that order, storing nothing on a fail; `assertBindingMutable()`, `cloudRowCount()`, `persistSettings()`, `setEnabled()`, `startDrain()`, `stopDrain()`, `health()` lose the visibility argument; `modeForVisibility()` is `mode()`; `_settings_map()` writes provider, endpoint, region, bucket, access key, secret key, enabled; `runOffloadTick()` reads one mode; `_anonymous_status()` removed (callers use `BucketCheck::anonymous_status()`) |
| `includes/cloud_storage/CloudStorageS3Driver.php` | 1.2 | `public_base_url` option, `looksLikeRawBucketHost()`, `inspectPublicUrl()`, `derivePublicBaseUrl()`, `getPublicBaseUrl()`, `getPathPrefix()` removed; `url()` builds on the bucket's own address, derived from endpoint + bucket **with the endpoint's port kept** (the derivation dropped it, so the gate probed the wrong address on any endpoint with a port) |
| `includes/cloud_storage/CloudStorageDriver.php` | 1.2 | `url()` doc: the bucket's own address, read only by the privacy gate |
| `includes/cloud_storage/CloudStoreInventory.php` | 1.1 | one driver per tick, one "did not answer" state; rows and the missing record carry no `visibility`; the `driver` test seam takes no argument; "not checked (no store configured)" |
| `includes/cloud_storage/CloudStoreInventoryPanel.php` | 1.0.2 | "could not be checked (no store is configured)" names no visibility |
| `includes/BucketCheck.php` | 1.2 | `file_store_buckets()` returns the one bucket labelled "the file store"; the collision message says one deletion or one mistaken bucket setting takes both (it said "a file bucket is public", which is false); both directions say "private bucket" |
| `includes/BackupObjects.php` | 1.2.1 | `fetch_from_store()` reads the one store; the message names "the file store" |
| `includes/BackupObjectRestore.php` | 1.0.2 | `placement()` walks `StorageProfileRegistry::all()`; `served()` resolves the one driver; the `store` test seam takes no argument |
| `data/file_blobs_class.php` | 1.2.2 | `_cloud_visibility()` removed; `_cloud_driver()` and `_pull_back_from_cloud()` resolve the one driver; `flipVisibility()` comment states the invariant (confirmed, not rewritten: PHASE 3 commits `driver = local` and `fbb_is_private` in one transaction, after the bytes land) |
| `data/files_class.php` | 1.12.0 | `get_url()` cloud branch removed: every URL is a local `/uploads/*` URL |
| `serve.php` | 1.9.0 | the 302 to the bucket for a public cloud file removed; a cloud file is gate-streamed, resolving `driverWithFallback()` |
| `plugins/mailbox/includes/RawMessageStore.php` | 1.4 | `privateDriver()` resolves the one driver |
| `adm/logic/admin_cloud_storage_logic.php` | 3.0 | one store in `save` (provider, endpoint, region, bucket, access key, secret key); `disable_and_pull_private` removed; `remove` blanks the one binding; `private_status`, `private_enabled`, `private_errors`, `private_test_results`, `public_cloud` gone from the page vars; `cloud_count` added |
| `adm/admin_cloud_storage.php` | 2.0 | the intro gains the § The page sentence; setup and change forms draw provider, endpoint, region, bucket, access key, secret key; the locked form the two key fields; the store box loses Public base URL and Private bucket; the status box loses the private lines and the second pull-back; the private-store results box, the egress banner, the pre-save confirm and `detectRawHost` removed; the script keeps `applyProvider` and the region auto-fill |
| `docs/cloud_storage.md` | — | rewritten as one private store: overview, the rule, the Save check, settings, providers, admin UI, permission flips, URL generation and `/uploads/*` serving, bucket setup per provider (every bucket private; Amazon keeps Block Public Access on), file-by-file; the egress section is gone |
| `docs/drive.md` | — | placement sentence and the profile row name one private store |
| `docs/backups.md` | — | "Not the file store's bucket" (one bucket) |
| `specs/implemented/backup_offloaded_files.md` | — | three sentences that stated the public/private pair as current |
| `specs/DEFERRED_cloud_blob_variant_generation.md` | — | the opening sentence: private uploaded bytes are the only bytes that move |
| `tests/lib/cloud_fixtures.php` | 1.1 | `ScratchTableProfile` answers `private`; the `visibility` option is gone |
| `tests/lib/s3_fixtures.php` | 1.2 | HeadBucket (a HEAD with an empty key) answers 200, so the Save check's Reach step passes over the fixture |
| `tests/integration/cloud_private_store_test.php` | 2.0 | rewritten (see § 3) |
| `tests/integration/cloud_storage_guards_test.php` | 3.0 | one binding, one latch, one drain flag; the cloud row is a private blob |
| `tests/integration/cloud_storage_characterization_test.php` | 3.0 | fixtures are private blobs in the restricted directory; mid-flight ineligibility flips the blob public; new "public blob is never eligible" checks |
| `tests/integration/cloud_file_private_offload_test.php` | 2.0 | section B adds the public-file URL check; new section C, the flip-to-public invariant |
| `tests/integration/cloud_storage_live_b2_test.php` | 2.1 | live tier, **edited only, not run**: `binding()`, `driver()`, `testConnection($opts)` with the new step labels, `BucketCheck::anonymous_status()`, private blob fixture, `persistSettings($opts, null)` / `setEnabled(false, null)`, the temp plugin declares a private profile and a public one that must be refused |
| `tests/cloud_storage/offload_release_test.php` | 1.1 | the blobs are private, in the restricted directory |
| `tests/cloud_storage/store_inventory_test.php` | 1.1 | one driver, rows without visibility; the no-store case counts every row; a checked pass follows it; the panel's no-store line |
| `tests/backups/bucket_check_test.php` | 1.1 | "the file store" label; the collision wording; `file_store_buckets()` from the settings (new section); the cloud storage check's five step labels; `testConnection()` with one argument |
| `tests/backups/backup_bring_back_test.php` | 1.1 | the cloud rows are private blobs; the `store` seam takes no argument; missing records carry no visibility |
| `tests/backups/backup_restore_objects_test.php` | 1.1 | same: private blobs, the `store` seam takes no argument (found by the gate) |
| `tests/backups/backup_objects_run_test.php`, `backup_verify_objects_test.php` | 1.1 | an object's `visibility` reads `private` (found by the gate) |
| `tests/lib/coverage.php` | 1.1 | the run-everything rule narrowed to the four harness files (owner's request after the gate) |
| `tests/unit/changed_selection_test.php` | — | checks for the narrowed rule |
| `docs/testing.md` | — | states the narrowed rule |
| `plugins/mailbox/tests/raw_message_store_test.php` | 1.1 | injects into the factory's `cached` |
| `plugins/mailbox/tests/inbound_raw_storage_test.php` | 2.1 | injects into the factory's `cached` (found by the gate; not in the plan's list) |

Not changed, checked: `tests/backups/backup_objects_test.php`,
`tests/functional/drive/encryption_test.php`,
`tests/integration/password_field_no_value_test.php`,
`tests/integration/cloud_offload_engine_test.php` reference nothing removed
and pass as they are. `migrations/migrate_offload_single_task.php` is a past
migration and is left alone.

## 2. Files deleted

- `includes/cloud_storage/BlobPrivateStorageProfile.php`

## 3. Test results

**New checks** (all passing):

- `cloud_private_store`: driver() null until configured AND enabled (the
  unlatched and with-fallback resolvers, `binding()`'s five keys); `mode()`
  from the latch and the drain flag; **the registry refuses a profile that is
  not private** and keeps one that is (two throwaway classes registered
  through `_load_and_register`); **Save refuses a bucket an anonymous read
  can see** over the loopback fixture with `FIXTURE_ANON_READ=1` — the five
  steps in order, Private fails saying "publicly readable", Reach and Write
  passed before it, the probe still deleted; Save passes a private bucket,
  with exactly one anonymous read tried and refused.
- `cloud_storage_characterization`: **a public blob is never eligible** —
  `isEligibleRow()` false, `_sync_row` returns skipped, nothing pushed, the
  row stays local with no failure recorded, the local bytes untouched.
- `cloud_file_private_offload`: a public file's `get_url()` is a local
  `/uploads` URL with a store configured and enabled; **a private cloud blob
  made public is local before its record says public** — after
  `flipVisibility(false)` through an injected in-memory driver the record
  reads local and public, the bytes are in the fast-serve dir, the bucket no
  longer holds the object.
- `bucket_check`: `file_store_buckets()` is the one bucket from the settings,
  labelled "the file store"; the cloud storage check's steps are own bucket,
  Reach, Write, Private, Delete.
- `cloud_store_inventory`: with no store configured all seven rows are
  unchecked and the panel says "7 could not be checked (no store is
  configured)".

**Individual suites** (run directly while editing): cloud_private_store 31/31,
cloud_storage_guards 13/13, cloud_storage_characterization 25/25,
cloud_file_private_offload 17/17, cloud_store_inventory 57/57,
offload_release 37/37, bucket_check 47/47, raw_message_store 18/18,
inbound_raw_storage 26/26 (1 skipped).

**`php tests/run.php --changed`** (safe tier, 203 suites):

```
Tests: 202 passed, 1 failed of 203   |   Checks: 6955 passed, 1 failed, 141 skipped
Failed:
  - agent_bundle_drift  (plugins/server_manager/tests/agent_bundle_drift_test.php)
```

`agent_bundle_drift` is pre-existing and unrelated: the bundled agent is
1.37.0 while the source is 1.38.0 (the backup-offloaded-files work's unbuilt
agent). Nothing in this build touches it.

**`php tests/run.php db --changed`** — run twice. The runner ran everything
(465 suites) both times, because `tests/lib/cloud_fixtures.php` changed and
the selection treated any file under `tests/lib/` as the harness (fixed
after, see below). The first run found two more test files still injecting
into the old factory cache (`inbound_raw_storage_test.php`,
`backup_restore_objects_test.php`, plus the seams in
`backup_objects_run_test.php` and `backup_verify_objects_test.php`); fixed,
then the second run:

```
Tests: 463 passed, 2 failed of 465   |   Checks: 16788 passed, 6 failed, 153 skipped
Failed:
  - agent_bundle_drift  (plugins/server_manager/tests/agent_bundle_drift_test.php)
  - fetch_url_reader    (plugins/joinery_ai/tests/fetch_url_reader_test.php)
```

Neither is this build's: `agent_bundle_drift` is the stale 1.37.0 agent
bundle (as in the `--changed` loop); `fetch_url_reader` failed with
"joinery-jail: seccomp: permission denied" launching its parser sandbox,
passed in the first full run and passes standalone (18/18).

**The runner, after the gate.** `tests/lib/coverage.php` (1.1) runs
everything only for `tests/run.php`, `tests/lib/harness.php`,
`tests/lib/coverage.php` and `tests/lib/discovery.php`; any other file under
`tests/lib/` selects by recorded reach. `tests/unit/changed_selection_test.php`
covers both sides; `docs/testing.md` states the rule. Note that this build
still reaches ~440 suites by honest reach: a bare `harness_boot()` loads
`data/file_blobs_class.php` through the data classes' require chain.

## 4. Not done, and why

- **The CLAUDE.md docs index line.** § Docs says the line "S3-compatible
  cloud bucket for public uploaded files" is edited through the admin agent
  files page. That is a database write (`agf_agent_files`), which the
  executor rules forbid without the owner's word. The owner edits the
  "Internal CLAUDE.md" record at `/admin/admin_agent_files`, line
  `- [Cloud Storage](docs/cloud_storage.md) - ...`, to read:
  `S3-compatible cloud bucket for private files and inbound mail`.
- **WP1 and WP2 were not separately green.** WP1 removes
  `StorageProfileRegistry::forVisibility()`, which `CloudStorageLifecycle`
  (WP2) still called, so the loop could not be green between them; WP2 was
  built straight after WP1 and the loop run once for both. WP3, WP4 and WP5
  each left it green.
- **The live gate** (§ Live gate 1–7) is the owner's, as the spec says.
  `cloud_storage_live_b2_test.php` was edited to the new API and not run.

## 5. Questions

- **Q1.** `BucketCheck::collision_step()`'s message said "a file bucket is
  public, and one deletion or one public-read setting would take files and
  backups together". With the file bucket private that is false, so I
  changed both directions to "one deletion or one mistaken bucket setting
  would take files and backups together" and "Make a private bucket for
  files/backups". The executor rules say the bucket and key check is not
  this build's; I took a one-sentence truth fix in a file the plan already
  names as within scope. Revert if you would rather the wording stay.
- **Q2.** The Save check's step labels are now `Its own bucket`, `Reach`,
  `Write`, `Private`, `Delete` (constants `CloudStorageLifecycle::STEP_*`),
  matching the backup target tester's `Reach` / `Write` / `Private` /
  `Prune`. The spec names the steps but not their labels; the old ones were
  "Reach + authenticate" / "Write + read public" / "Verify NOT publicly
  readable" / "Delete". Say if you want them otherwise.
- **Q3.** `CloudStoreInventory` records no longer carry a per-row
  `visibility` (there is one store); `BackupObjects` records keep theirs,
  reading `private`, as § The visibility contract says. A
  `cloud_storage_inventory` row written before this build still decodes
  (the field is simply ignored). No migration.

## 6. Verify

```
batcat /var/www/html/joinerytest/public_html/settings.json
batcat /var/www/html/joinerytest/public_html/storage_profiles.json
batcat /var/www/html/joinerytest/public_html/includes/cloud_storage/BlobStorageProfile.php
batcat /var/www/html/joinerytest/public_html/includes/cloud_storage/StorageProfileRegistry.php
batcat /var/www/html/joinerytest/public_html/includes/cloud_storage/CloudStorageDriverFactory.php
batcat /var/www/html/joinerytest/public_html/includes/cloud_storage/CloudOffloadEngine.php
batcat /var/www/html/joinerytest/public_html/includes/cloud_storage/CloudStorageLifecycle.php
batcat /var/www/html/joinerytest/public_html/includes/cloud_storage/CloudStorageS3Driver.php
batcat /var/www/html/joinerytest/public_html/includes/cloud_storage/CloudStorageDriver.php
batcat /var/www/html/joinerytest/public_html/includes/cloud_storage/CloudStoreInventory.php
batcat /var/www/html/joinerytest/public_html/includes/cloud_storage/CloudStoreInventoryPanel.php
batcat /var/www/html/joinerytest/public_html/includes/BucketCheck.php
batcat /var/www/html/joinerytest/public_html/includes/BackupObjects.php
batcat /var/www/html/joinerytest/public_html/includes/BackupObjectRestore.php
batcat /var/www/html/joinerytest/public_html/data/file_blobs_class.php
batcat /var/www/html/joinerytest/public_html/data/files_class.php
batcat /var/www/html/joinerytest/public_html/serve.php
batcat /var/www/html/joinerytest/public_html/plugins/mailbox/includes/RawMessageStore.php
batcat /var/www/html/joinerytest/public_html/adm/logic/admin_cloud_storage_logic.php
batcat /var/www/html/joinerytest/public_html/adm/admin_cloud_storage.php
batcat /var/www/html/joinerytest/public_html/docs/cloud_storage.md
batcat /var/www/html/joinerytest/public_html/docs/drive.md
batcat /var/www/html/joinerytest/public_html/docs/backups.md
batcat /var/www/html/joinerytest/public_html/specs/implemented/backup_offloaded_files.md
batcat /var/www/html/joinerytest/public_html/specs/DEFERRED_cloud_blob_variant_generation.md
batcat /var/www/html/joinerytest/public_html/tests/lib/cloud_fixtures.php
batcat /var/www/html/joinerytest/public_html/tests/lib/s3_fixtures.php
batcat /var/www/html/joinerytest/public_html/tests/integration/cloud_private_store_test.php
batcat /var/www/html/joinerytest/public_html/tests/integration/cloud_storage_guards_test.php
batcat /var/www/html/joinerytest/public_html/tests/integration/cloud_storage_characterization_test.php
batcat /var/www/html/joinerytest/public_html/tests/integration/cloud_file_private_offload_test.php
batcat /var/www/html/joinerytest/public_html/tests/integration/cloud_storage_live_b2_test.php
batcat /var/www/html/joinerytest/public_html/tests/cloud_storage/offload_release_test.php
batcat /var/www/html/joinerytest/public_html/tests/cloud_storage/store_inventory_test.php
batcat /var/www/html/joinerytest/public_html/tests/backups/bucket_check_test.php
batcat /var/www/html/joinerytest/public_html/tests/backups/backup_bring_back_test.php
batcat /var/www/html/joinerytest/public_html/plugins/mailbox/tests/raw_message_store_test.php
batcat /var/www/html/joinerytest/public_html/plugins/mailbox/tests/inbound_raw_storage_test.php
```
