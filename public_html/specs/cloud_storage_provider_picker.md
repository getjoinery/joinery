# Cloud storage: a provider picker heads the form

**Status:** Built 2026-09-21 and committed in c4ec9d05 with `specs/cloud_storage_private_only.md`, which supersedes the egress paragraph below. Awaiting the owner's live gate on dev.

## Why

The cloud storage form asked everyone for an endpoint hostname and a region,
and most people do not know either. A person with a Backblaze bucket has a
key; a person with an Amazon bucket knows its region; a person with a
Cloudflare R2 bucket has an account endpoint and no region at all. Asking
for what a provider already decides invites a wrong answer, and a wrong
endpoint or region is a bucket that does not answer.

The owner's rule for the platform: prevent the wrong thing, help with the
right thing.

## What is built

**A provider picker** (`cloud_storage_provider`, a declared select) heads
the setup form and the change-settings form. "Generic S3 compatible bucket"
is the default and fits MinIO and anything self-hosted. The other choices
are Backblaze B2, Amazon S3, Cloudflare R2, Wasabi, DigitalOcean Spaces and
Linode Object Storage.

**Only the fields the provider needs are shown.** The endpoint and region
declarations carry `show_when` naming the providers that ask for them; the
picker's show/hide is FormWriter's own. The page's script fills each shown field's example and help for
the provider chosen.

**The rest is settled at Save**, before the bucket and key check runs
(`StorageProvider::complete()`):

| Provider | Asks for | Settles |
|---|---|---|
| Generic | endpoint, region | as typed; region may be empty |
| Backblaze B2 | nothing beyond bucket and key | endpoint and region from what `b2_authorize_account` names for the key |
| Amazon S3 | region | `s3.<region>.amazonaws.com` |
| Cloudflare R2 | endpoint | region `auto` |
| Wasabi | region | `s3.<region>.wasabisys.com` |
| DigitalOcean Spaces | datacenter | `<region>.digitaloceanspaces.com` |
| Linode Object Storage | cluster | `<region>.linodeobjects.com` |

A missing region or endpoint is refused naming the provider. A Backblaze key
the service refuses is refused with its reason, and one Backblaze names no
S3 endpoint for is refused too.

**What is stored does not change shape.** The endpoint and region are
stored as before; every reader (the driver, the bucket check, the
inventory) is untouched. The provider is stored beside them and shown first
in the read-only summary, locked with the endpoint and bucket while files
are in the bucket. Remove resets it to generic. A store saved before the
picker existed shows as the provider its endpoint belongs to.

**The egress warning** works out the host files would serve from — the
endpoint typed, or the one the provider names from the region — so it fires
for a raw Amazon or Wasabi bucket before the endpoint exists.

## Decisions

- **One catalogue** (`includes/StorageProvider.php`) holds what each
  provider asks for, its endpoint pattern, its fixed region, its help and
  examples, and the host pattern that recognises it. The page's script
  reads the same catalogue as JSON. Nothing about a provider is written
  twice.
- **Backblaze asks for nothing but the key.** The S3 key pair is the
  application key, and `b2_authorize_account` names the S3 endpoint for it;
  the bucket check already makes that call, so `BucketCheck::b2_allowed()`
  carries the endpoint out with the capabilities.
- **`show_when` takes a list.** A field several choices share names them
  all; the renderer inverts the list into one rule per choice. The rules
  are built over the whole group before a page's `only` narrows it, so a
  page that draws a group one field at a time keeps them.
- **The backup target forms are not changed.** They already have a
  provider select of their own (b2, s3, linode) with their own show/hide;
  folding them onto this catalogue is a separate piece of work.
- **The core Settings page no longer draws the cloud storage group.** A
  plain settings save ran neither the bucket check nor the provider
  fill-in. The page links to Cloud Storage and Backups instead, in the
  superadmin section where the group used to be; the backups group was
  already drawn only on the Backups page.

## Not built

- The backup target forms keep their own provider select and fields.

## Tests

- `tests/cloud_storage/storage_provider_test.php` (safe): the catalogue,
  `complete()` for every provider including Backblaze through the
  `b2_allowed` hook, `effective()`, and the rendered picker's rules when a
  page draws the group one field at a time.
- `tests/backups/bucket_check_test.php` (safe) still passes with the
  endpoint carried out of `b2_allowed()`.

## Live gate

1. `update_database` ran on dev 2026-09-21; `cloud_storage_provider` is seeded.
2. Open `/admin/admin_cloud_storage` with nothing configured: the picker
   shows first, generic; switching to Backblaze hides the endpoint and
   region, Amazon shows the region only, R2 the endpoint only.
3. Save a Backblaze bucket with only the bucket and key: the stored
   endpoint reads `s3.<region>.backblazeb2.com` and the check passes.
4. Save an Amazon bucket with a region: the endpoint follows from it.
5. Leave the region empty for Amazon: refused, naming Amazon S3.
