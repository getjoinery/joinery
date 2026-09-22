# Cloud storage: one private bucket, private files only

**Status:** Built by the executor and committed 2026-09-21 (c4ec9d05); reviewed by the spec's author the same day against § The rule, § What is removed and § Tests, full db gate 464 of 465 with only the unrelated agent_bundle_drift red. Hand-back: `specs/cloud_storage_private_only_report.md`. Awaiting the owner's live gate (§ Live gate 2–7) and the CLAUDE.md docs-index line edited at /admin/admin_agent_files.
Supersedes the egress warning and the public store wherever
`specs/cloud_storage_provider_picker.md` and `docs/cloud_storage.md` describe
them.

## Why

Cloud storage exists so a Joinery that is running out of disk can move files
to a bucket. As built it also moves every public image and its thumbnails,
and pages link straight to the bucket, so every image on every page view is
a bucket transfer. That is a CDN, which the feature is not meant to be, and
it is why the page carries an egress warning at all.

On dev, private files are 97 percent of the bytes that could move:

| Set | Files | Size |
|---|---|---|
| Private files (signed-in uploads, Drive, sealed) | 1,497 | 207 MB |
| Inbound mail | 2,019 messages | on disk beside them |
| Public images and uploads | 498 | 6.5 MB |

Mail attachments and member documents pile up and are rarely opened. Public
images are small and are what pages serve. Moving only the private set is
cold storage by construction: a file in the bucket is read only when a
signed-in person opens it, the bytes stream through this server, and
nothing on any page points at the bucket.

## The rule

**One bucket. It must be private. Only private things move to it.
Everything else stays on this server.**

- **What moves:** every file people must be signed in to see (member
  uploads, Drive files, sealed vault files) and inbound mail. In code that
  is every blob with `fbb_is_private = TRUE` and every raw message.
- **What stays:** public uploads, photos, gallery and blog images and all
  their page-size copies, theme and brand assets. Nothing served on a page
  ever lives in the bucket.
- **Enforced, not advised.** A bucket that answers an anonymous read is
  refused at Save; the profile registry refuses a profile that is not
  private; a private file that becomes public comes back to this server
  first. There is no setting that makes a public file move.

## What is removed

The public store and everything that only it needed.

| Piece | Today | After |
|---|---|---|
| `cloud_storage_bucket` | the public bucket | the one private bucket |
| `cloud_storage_private_bucket` | the optional second bucket | removed |
| `cloud_storage_public_base_url` | CDN or custom domain for public reads | removed; nothing is read by URL |
| `cloud_storage_private_enabled`, `cloud_storage_private_draining` | the private store's latch and drain flag | removed; `cloud_storage_enabled` and `cloud_storage_draining` are the only ones |
| `BlobPrivateStorageProfile` | the private subclass | removed; `BlobStorageProfile` is the one blob profile, with `fbb_is_private = TRUE` as its eligibility and the same push list of original plus variants |
| `CloudStorageDriverFactory::default()`, the `public` branch of `forVisibility()`, `bindingFor('public')` | the public store's driver | removed; one binding |
| `File::get_url()` cloud branch, the `/uploads/*` 302 to the bucket in `serve.php` | public files served from the bucket | removed; a public file is always a local file |
| `CloudStorageS3Driver::looksLikeRawBucketHost()`, `inspectPublicUrl()`, `derivePublicBaseUrl()` | the egress warning and the public read test | removed |
| The egress banner, the pre-save confirm, the "Write + read public" test step | on the page | removed |
| "Disable Private Store and Pull Back" | a second pull-back button | folded into "Disable and Pull Files Back to Local" |
| `BucketCheck::file_store_buckets()` | two buckets to collide against | one |

Kept as they are: the provider picker and `StorageProvider`, the bucket and
key check, binding immutability, the offload modes and the single tick, the
daily file-store check and Bring them back, offloaded files riding backup
storage, the stuck-file table and Retry, `RawMessageStore`, and the sealed
vault's per-file handling.

## The visibility contract stays as an invariant

Profiles keep `visibility()`. `StorageProfileRegistry::all()` refuses any
profile that does not answer `private`, logging it and leaving it out, so a
future consumer cannot opt a public set into the bucket by declaring one.
`forVisibility()` is replaced by `driver()` with no argument; the callers
(`CloudOffloadEngine`, `FileBlob`, `BackupObjects`, `CloudStoreInventory`,
`BackupObjectRestore`, `RawMessageStore`) drop the argument. Backup object
records keep their `visibility` field, which only ever reads `private` from
now on.

A private cloud file that is made public is pulled back to this server by
`FileBlob::flipVisibility()` before its record flips, which is what it does
today. A local public file made private moves on the next tick, as today.

## The page

`/admin/admin_cloud_storage`, the same three shapes, one store.

**The intro note** keeps the owner's sentence and gains one: "Only files
people must be signed in to see move: private uploads, Drive files and
inbound mail. Public images and downloads stay on this server, so nothing
on a page is served from the bucket."

**Setup form:** provider, then endpoint and region as the provider asks,
bucket, access key, secret key. The bucket's help: "A private bucket used
for nothing else. Not the backup bucket. Save refuses a bucket anyone can
read."

**Save runs the check, in this order, and stores nothing on a fail:**

0. Its own bucket, and what the key may do (`BucketCheck`, as today).
1. Reach: the key can list the bucket.
2. Write: a probe object lands.
3. Private: an anonymous read of the probe is refused. This is the gate.
   A 2xx says "This bucket is publicly readable; it cannot hold private
   files. Make it private at the provider and save again."
4. Delete: the probe goes, so permanent delete and retention work.

**The Status box** is one state sentence (not set up, off, active, pulling
files back) with counts and sizes, then coloured boxes only for problems
and warnings, as today; the private-store lines go because there is no
second store.

**The store box** shows provider, endpoint, region, bucket, access key,
whether a secret is stored, and files in the bucket, with Pause or Enable,
Disable and Pull Files Back to Local, and Remove as the state allows. The
locked form carries the access key and secret key only.

## No migration

No file bucket is configured anywhere, so there are no values to carry
across and no files to pull back. The removed settings keep a `managed`
declaration marked dead in `settings.json`, the way `anti_spam_answer_comments`
does, so any row that exists stays declared until it is purged; nothing
reads them.

## Decisions

- **Remove the public store rather than hide it.** A hidden store is code
  nobody runs and tests nobody trusts; the rule is enforced by what does
  not exist.
- **Keep `visibility()` on profiles.** One method on each profile is the
  cheapest place to enforce "private only" for every future consumer, and
  it costs nothing.
- **No presigned links.** Every read of a private file streams through
  this server, as today. If large private files turn out to be opened
  often, a short-lived signed link straight from the bucket is a later,
  separate piece of work; the file signed URL machinery exists for it.
- **No per-set checkboxes.** With the rule fixed, there is nothing to
  choose; the intro note says what moves.

## Tests

Updated: `cloud_storage_characterization`, `cloud_storage_guards`,
`cloud_private_store`, `cloud_file_private_offload`, `cloud_offload_engine`,
`offload_release`, `store_inventory`, `bucket_check`, `backup_objects`,
`backup_bring_back`, `password_field_no_value`, the drive `encryption`
test, the mailbox raw store tests, `tests/lib/cloud_fixtures.php`, and
`cloud_storage_live_b2` (live).

New checks: the registry refuses a profile that is not private; Save
refuses a bucket an anonymous read can see (through the S3 fixture's
`FIXTURE_ANON_READ`); a public blob is never eligible; a private cloud
blob made public is local before its record says public.

## Docs

`docs/cloud_storage.md` rewritten as one private store: overview, the
gate, settings, providers, admin UI, the check, permission
flips, URL generation and `/uploads/*` serving, bucket setup per provider
(every bucket private; Amazon keeps Block Public Access on), file-by-file.
The egress section goes. The docs index line in CLAUDE.md, "S3-compatible
cloud bucket for public uploaded files", is edited through the admin agent
files page to say "private files and inbound mail".

## Live gate

1. `update_database` on dev seeds the declarations.
2. `/admin/admin_cloud_storage` shows one store. Save a bucket that is
   public at the provider: refused at the Private step, nothing stored.
3. Make it private and save: passes, Active.
4. Upload a private file and a public image; after the tick the private
   file's blob reads `cloud`, the image's reads `local`, and the image's
   URL is a local `/uploads/` URL.
5. Open the private file signed in: it streams. Fetch its bucket key
   anonymously: refused.
6. Flip the private file to public: its bytes are back on this server and
   its blob reads `local`.
7. Disable and Pull Files Back to Local: the count reaches zero; Remove
   forgets the bucket.

## Build plan

Five work packages, in this order; each leaves `php tests/run.php --changed`
green before the next starts. Every listed file is a place the public store
is referenced; the count is how many references it holds today.

**WP1, one store in code.**
- `settings.json`: the four removed settings (`cloud_storage_private_bucket`,
  `cloud_storage_public_base_url`, `cloud_storage_private_enabled`,
  `cloud_storage_private_draining`) become `managed` declarations whose
  helptext starts with DEAD, as `anti_spam_answer_comments` does; no group,
  no label. `cloud_storage_bucket`'s help reads "A private bucket used for
  nothing else. Not the backup bucket. Save refuses a bucket anyone can
  read."
- `storage_profiles.json`: `BlobStorageProfile` only.
- `includes/cloud_storage/BlobStorageProfile.php`: `visibility()` answers
  `private`; `eligibilityWhere()` is `fbb_is_private = TRUE`. Delete
  `BlobPrivateStorageProfile.php` (3 references).
- `includes/cloud_storage/StorageProfileRegistry.php`: `all()` refuses a
  profile whose `visibility()` is not `private`, logs it, leaves it out;
  `forVisibility()` goes.
- `includes/cloud_storage/CloudStorageDriverFactory.php` (15): `driver()`,
  `driverUnlatched()`, `driverWithFallback()`, `binding()`; no visibility
  argument, no `default()`, no `public_base_url` in the binding.
- Callers drop the argument: `includes/cloud_storage/CloudOffloadEngine.php`
  (5), `data/file_blobs_class.php` (2, and `_cloud_visibility()` goes),
  `includes/BackupObjects.php` (1), `includes/BackupObjectRestore.php` (2),
  `includes/cloud_storage/CloudStoreInventory.php` (1),
  `plugins/mailbox/includes/RawMessageStore.php` (1).
- `includes/BucketCheck.php`: `file_store_buckets()` returns the one bucket,
  labelled "the file store".

**WP2, the lifecycle, the logic and the page.**
- `includes/cloud_storage/CloudStorageLifecycle.php` (9): `testConnection()`
  takes only `$opts` and runs own bucket and key, reach, write, private
  gate, delete; `privacyVerdict()` stays; `assertBindingMutable()`,
  `cloudRowCount()`, `persistSettings()`, `setEnabled()`, `startDrain()`,
  `stopDrain()`, `modeForVisibility()` and `health()` lose the visibility
  argument; `_settings_map()` writes provider, endpoint, region, bucket,
  access key, secret key, enabled.
- `adm/logic/admin_cloud_storage_logic.php` (19): one store in `save`;
  `disable_and_pull_private` goes; `remove` blanks the one binding;
  `private_status` and `private_enabled` go from the page vars.
- `adm/admin_cloud_storage.php` (8): the intro gains the sentence in § The
  page; the setup and change forms draw provider, endpoint, region, bucket,
  access key, secret key; the locked form the two key fields; the store
  box loses Public base URL and Private bucket; the status box loses the
  private lines and the second pull-back button; the egress banner, the
  pre-save confirm and `detectRawHost` go from the script, which keeps
  `applyProvider` and the generic region auto-fill.
- `includes/cloud_storage/CloudStorageS3Driver.php` (6):
  `looksLikeRawBucketHost()`, `inspectPublicUrl()`, `derivePublicBaseUrl()`
  and the `public_base_url` option go; `url()` stays, the privacy gate
  fetches it anonymously.

**WP3, nothing public is served from a bucket.**
- `data/files_class.php` (1): the cloud branch of `get_url()` goes; a public
  file is a local file.
- `serve.php` (2): the 302 to the bucket for a public cloud file goes; the
  gated stream for a private cloud file stays.
- `data/file_blobs_class.php`: `flipVisibility()` on a cloud blob still
  pulls the bytes back before the record flips; confirm, do not rewrite.

**WP4, tests and fixtures.** `tests/lib/cloud_fixtures.php` loses its
public-offload shape. Updated: `tests/integration/cloud_private_store_test.php`
(14), `cloud_storage_guards_test.php` (6), `cloud_storage_characterization_test.php`
(3), `cloud_file_private_offload_test.php` (1), `cloud_offload_engine_test.php`,
`cloud_storage_live_b2_test.php` (9, live tier, edit only),
`tests/cloud_storage/offload_release_test.php` (3), `store_inventory_test.php`,
`tests/backups/bucket_check_test.php`, `backup_objects_test.php`,
`backup_bring_back_test.php`, `tests/functional/drive/encryption_test.php` (2),
`plugins/mailbox/tests/raw_message_store_test.php` (1),
`tests/integration/password_field_no_value_test.php`. New checks as § Tests
lists. Gate: `php tests/run.php db --changed`, then the full
`php tests/run.php db`.

**WP5, docs.** `docs/cloud_storage.md` (19) as § Docs says; `docs/drive.md`
(1). `specs/backup_offloaded_files.md` (2) and
`specs/DEFERRED_cloud_blob_variant_generation.md` (2) are specs, not docs:
change a sentence only where it states the public store as current.
`migrations/migrate_offload_single_task.php` is a past migration; leave it.
Files under `specs/implemented/` are never edited.

## Executor rules

These are the house rules that bind this build. `CLAUDE.md` at the tree root
is the full set; the ones that bite here:

- **Never run `git commit`, `git add` or `git mv`.** The owner stages and
  commits. Leave the tree modified and report.
- **No database writes without the owner's word in the session**, including
  `update_database`. None is needed for this build; declarations are read
  from `settings.json` at request time.
- **Tests never run as root.** `php tests/run.php --changed` is the loop,
  `php tests/run.php db --changed` the gate before handing back. Do not
  name the `live` tier.
- **Validation:** `php -l` on every edited PHP file; the validator
  (`php /var/www/html/joinerytest/maintenance_scripts/dev_tools/validate_php_file.php <file>`)
  only on class files in `includes/`, `data/` and plugin `includes/`, never
  on a page, a logic file or a test, because it executes what it loads.
- **Do not chmod anything.** A host timer holds the tree at 644/755.
- **Docs describe the current state only.** No "previously", "no longer",
  "replaces", "used to". A doc reads as though the end state always existed.
- **User-facing text says "backup storage", never "shelf".** Identifiers keep
  `shelf`.
- **Version numbers:** every edited file with a `@version` block gets a new
  entry saying what changed, one or two lines.
- **Delete, do not hide.** No feature flag, no dead branch kept "in case", no
  `// removed` comments. A removed method is gone.
- **Do not widen the scope.** The backup target forms, the provider picker,
  the bucket and key check and backup storage are not this build's.
- **Secrets never reach the transcript.** Do not print a key or a bucket
  URL with a signature; describe where it came from.
- **Stuck on a real ambiguity:** stop and write it as Q1, Q2 in the hand-back
  rather than guessing. Do not ask mid-build; finish everything that does
  not depend on the answer.

## Hand back

When the gate is green, write the report to
`specs/cloud_storage_private_only_report.md` and stop. The report holds, in
this order:

1. The files changed, one line each: path, new version, what changed.
2. The files deleted.
3. Test results: the `--changed` loop and the `db --changed` gate, pasted
   summaries; every new check named.
4. Anything in § Build plan not done, and why.
5. Questions, as Q1, Q2.
6. The one-line `batcat` command for each edited file.

The spec's author checks the work against § The rule, § What is removed and
§ Tests, runs the gate again, and reports to the owner. The live gate is the
owner's.

