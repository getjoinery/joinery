# Storage bucket and key check

**Status:** Built and reviewed 2026-09-21; tests green (`tests/backups/bucket_check_test.php`). Awaiting the owner's live gate on the two forms.

## Why

The platform keeps two kinds of bucket: backup storage (a backup target) and
the file store (the cloud storage page's public and private buckets). The
design goal is that a form stops a person doing the wrong thing and helps
them do the right one. Before this, the file store's Save tested reach,
write, public or private read, and delete, but never asked whether the
bucket already held backups. A backup target's test was one list call; the
site page saved without running it, the fleet page saved a failing target
with a warning, nothing asked whether the bucket was public, the node's
write-only key was stored unproven, and a key that could reach every bucket
on the account looked the same as one made for this bucket.

## What is built

`includes/BucketCheck.php` holds the shared questions, each answered as a
step `['label', 'status' => pass|warn|fail, 'message']` in words an
operator can act on:

- **Its own bucket.** `same_bucket()` matches by name and endpoint host.
  `file_store_buckets()` and `backup_target_buckets()` read the other side;
  `collision_step()` refuses a match and says what to make instead.
- **Private.** `private_read_step()` refuses a bucket an anonymous GET can
  read (`anonymous_status()`, which the cloud storage privacy gate also uses).
- **What a Backblaze key may do.** `B2Client::authorize()` keeps the
  `allowed` set. `b2_key_steps()` refuses a key pinned to another bucket,
  warns on a key that opens the whole account (naming the other side's
  buckets it also opens), and refuses a missing capability naming what it
  would break.

`TargetTester::test()` (4.0) runs the whole check for a backup target, saved
or not, and returns `success`, `message` and `steps`: own bucket, reach,
write a probe, private, prune the probe, the node key proven write-only
(a probe it can write and cannot delete, cleaned up by the main key), and on
Backblaze each key's reach and capabilities, with the key-minting
capabilities when minting per run is on.

Both target forms run it on Save of an enabled target and refuse the save
when it fails (`adm/logic/admin_backups_logic.php` 1.11,
`plugins/server_manager/views/admin/targets.php` 2.7). A disabled target is
saved untested; enabling it is a save. The **Test** button runs the same
check on a saved target. `utils/install_backup_target.php` is unchanged: it
already removed a target whose test failed.

`CloudStorageLifecycle::testConnection()` (1.6) asks the own-bucket and
Backblaze-key questions first and skips the network steps when they fail.

## The cloud storage page's shapes

Fields that cannot change are not shown as editable. The page
(`adm/admin_cloud_storage.php` 1.6, logic 2.5) shows the store in one of
three shapes: the setup form when nothing is configured; what is stored,
read-only, with the actions the state allows (Pause or Enable; Disable and
Pull Files Back to Local; Remove when nothing is in the bucket); and a form
for what may change. While any file is in either bucket or on its way back
the endpoint, region and bucket are locked and the form carries only the
key, the public URL and the private bucket. With nothing in the bucket the
whole configuration sits under a Change settings fold. A field the form did
not post keeps its stored value, which is how Enable re-proves the stored
settings through the same Save path. There is one store per site; nothing
is added, only changed or removed.

## Decisions

- **Refuse, do not warn**, for the same bucket, a public backup bucket, a
  failed reach or write, a main key that cannot prune, a node key that can
  delete, and a missing capability. Each is a mistake, not a choice.
- **Warn** for a key that opens the whole account. It works; the warning
  says what a leaked key would reach and how to shrink it.
- **Same account** stays the standing line on the Backups page, not a
  refusal. It is a choice.
- **A disabled target is not tested**, so a broken target can still be
  saved off.
- **Identifiers keep the word shelf**; user-facing text says backup storage.

## Not built

- Amazon and Linode keys state nothing about themselves the way Backblaze's
  do; for them the acts prove what the key can do and nothing says what
  else it reaches.
- The file store does not run a write-only key check: it has one key.

## Tests

`tests/backups/bucket_check_test.php` (safe, 43 checks) over the loopback
S3 fixture, which refuses anonymous reads unless `FIXTURE_ANON_READ=1` and
refuses a DELETE by a key id starting `wo-` when `FIXTURE_WRITE_ONLY_KEY=1`.

## Live gate

1. On dev's Backups page, save a target naming the file store's bucket: not
   saved, the message names the file store.
2. Save a target on a public bucket: not saved, "Anyone can read this
   bucket".
3. On the fleet Targets page, enter a node key that can delete: not saved.
4. On the cloud storage page, name the backup bucket: refused at step 0.
5. Save a good target: the success line, plus the account-wide warning if
   the key is not pinned.
