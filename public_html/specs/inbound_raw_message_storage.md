# Inbound raw-message + attachment storage (reuse the cloud-storage driver seam)

## Overview

Mail received over the **push transports** (Postfix pipe, Mailgun webhook) is stored
with its **complete RFC822 message — base64 attachments and all — in the
`iem_raw_message` Postgres `text` column**. That is the classic "blobs in the
database" anti-pattern: attachments are the bulk of an email's size, base64 inflates
them ~33%, and every byte rides along in each backup, restore, and replication stream
while occupying the most expensive, least-scalable storage tier.

This spec moves the raw bytes **out of the database** and, in doing so, makes inbound
mail storage behave like every other large-object in the platform: it **reuses the
existing cloud-storage driver subsystem** ([cloud_storage.md](implemented/cloud_storage.md))
rather than inventing a parallel one. The database is left holding only what it needs
for listing, search, and display — headers, the `text/plain`/`text/html` bodies, an
attachment manifest, and a small descriptor saying where the raw lives. A single
accessor resolves that descriptor regardless of transport or storage tier.

It also **closes the attachment gap between transports.** Today IMAP mail has a
per-attachment list and per-attachment download (fetched on demand from the mailbox);
push mail has neither, because its attachments are buried inside the inline raw blob.
Once the raw moves to a real object store and a manifest is written at ingest, push
mail gets the same per-attachment experience IMAP already has — one reader UI, one
endpoint, one storage seam, for all mail types.

A free side benefit: nothing in the platform full-text-searches `iem_raw_message` (only
three call sites read it, all enumerated below), so moving the raw off-row **shrinks the
hot table and speeds up list/search** with zero search-behavior impact.

### What changed since the first draft of this spec

The first draft was framed around rerouting a **Download .eml** action and an inline
raw `<pre>` view through a new accessor. Both of those surfaces have since been
**retired for every transport** — the user-facing surface is now the rendered body
plus the clickable per-attachment list, and there is no whole-message raw view or
download. So the remaining readers of a stored raw are narrower (see *Read path*), and
this revision is built around the current code, not the retired surfaces.

## The principle

**The database holds the small, searchable, displayable parts of a message; the heavy
raw lives in the cheapest durable store available for that transport — using the same
storage abstraction the rest of the platform already uses for large objects.**

| Transport | Where the raw durably lives | Why |
|-----------|-----------------------------|-----|
| Postfix / Mailgun (push) | **platform object store** (local disk, optionally cold-offloaded to a **private** bucket) — this spec | the source keeps no copy; the platform must persist it somewhere, just not in the DB |
| IMAP (pull) | the **remote mailbox** (no platform copy) | the mailbox is already a durable store; the platform keeps only a locator and re-fetches on demand |

The DB row is identical in shape for every transport: headers + text bodies +
attachment manifest + a **storage descriptor**. One accessor resolves the descriptor.

## Reusing the cloud-storage subsystem (the core of this revision)

The cloud-storage feature already solved "store a large blob locally, optionally move
it to an S3-compatible bucket, with a per-row flag as the source of truth." We reuse
that machinery wholesale and add exactly one thing it deliberately excluded.

**What we reuse verbatim:**

- **The driver contract** — `CloudStorageDriver` (`put`/`get`/`delete`/`ping`). These
  are byte-agnostic storage primitives; nothing about them is photo-specific.
- **The S3 implementation** — `CloudStorageS3Driver`. Its `put`/`get`/`delete` are
  generic; we instantiate it against a different bucket and never call `url()`.
- **The factory** — `CloudStorageDriverFactory`, extended with a private-bucket builder
  (below).
- **The per-row-flag truth model** — mirrored as `iem_raw_storage_driver` exactly as
  `fil_storage_driver` works for files. A misconfigured global setting can never strand
  a message.
- **The site-template key prefix** convention.
- **The cold-offload task shape** — a `CloudStorageSync`-style task that drains `local`
  rows to the bucket on cron with failure counting, plus a reverse task to pull back.
  Same bounded-batch, same `fil_sync_failed_count`-style stuck-row handling.

**The one thing the cloud-storage spec excluded, that we add here.** Its §13 says, in
bold: *"Storing private/permissioned files in the bucket. Private files stay local.
Period. Adding cloud-backed private files later would require a presigning + redirect
serving path and is an explicit future-work item, not v1."* Inbound mail is private and
attacker-controlled, so:

- It **must not** go in the existing public uploads bucket (objects there are
  world-readable by key).
- Its reads **must not** use the driver's `url()` method (a public link to private
  mail). Reads stay behind the existing `check_permission(5)` gate: PHP does a
  server-side `get()` to a temp path and streams the bytes — the opposite of the
  public-file path where PHP is bypassed.

So the mail `cloud` tier is a **separate, private bucket**, read **server-side**. The
driver code is shared verbatim; only the bucket and the read path differ. We do **not**
add presigned URLs in this spec — server-side streaming through the existing
permission-gated endpoint is sufficient for the low volume of attachment/raw reads, and
keeps the `CloudStorageDriver` interface unchanged. (Presigning is a future optimization
if egress on mail reads ever matters — it won't for a long time.)

### Private-bucket configuration (one field on the existing cloud-storage page)

The mail bucket is **not a separate provider or account** — that is explicitly out of
scope for now. It is one more bucket on the customer's *existing* cloud-storage
configuration: a single S3 access key already spans multiple buckets on AWS, R2, and B2,
so the private mail bucket reuses the customer's existing `cloud_storage_endpoint` /
`region` / `access_key` / `secret_key`. The only new input is a bucket name, added as one
field on the existing `/admin/admin_cloud_storage` page:

```
cloud_storage_private_bucket   = ''     bucket name on the SAME provider/account as the public cloud storage;
                                        reuses its endpoint/region/keys. Set on the cloud-storage admin page.
cloud_storage_private_enabled  = false  internal latch; set true ONLY after a Save whose privacy check passed.
```

**One decision, one Save — no standalone toggle.** Mail offload is enabled as part of the
same cloud-storage setup the customer already does, so a customer who turns on cloud
storage doesn't accidentally leave gigabytes of mail on local disk for want of a second
switch. The Save flow validates each configured bucket independently: the public bucket
enables `CloudStorageSync` as today; if the private-bucket field is filled and passes its
privacy check, `cloud_storage_private_enabled` is latched true and `OffloadInboundRawToCloud`
is activated. A failure on one bucket shows its own per-field diagnostic and does **not**
block the other — public uploads aren't held hostage to a mail-bucket misconfig, and vice
versa.

- Empty `cloud_storage_private_bucket` ⇒ no cloud tier; inbound raw stays `local` on disk
  forever. The feature degrades cleanly to local-only with zero extra config.

**Privacy is a hard gate, verified before any mail is stored.** Mail must never land in a
bucket we haven't *proven* is private. Unlike the public-bucket test (which verifies
public read *works*), the private-bucket test verifies public read is *denied*:

1. PUT a scratch probe with the platform's credentials; confirm an authenticated HEAD
   sees it (write works).
2. Fetch the same key **anonymously** (no credentials) via the bucket's direct URL.
3. **If the anonymous fetch returns 200, the bucket is public → the test FAILS and
   `cloud_storage_private_enabled` is NOT set.** The admin sees: "This bucket is publicly
   readable; inbound mail cannot be stored in a public bucket. Make the bucket private and
   re-test." Any non-200 (403/401/404/connection refused) means anonymous read is denied →
   the privacy check passes.
4. DELETE the scratch probe.

Until that check passes, offload never activates and mail stays `local` — there is no path
by which mail bytes reach an unverified bucket.

`CloudStorageDriverFactory` gains a builder that returns a driver bound to the private
bucket (via the existing `fromOptions()` path, passing the shared creds + private bucket
name), and `null` when no private bucket is configured or the privacy check has not passed:

```php
CloudStorageDriverFactory::privateDefault(): ?CloudStorageDriver
```

Everything downstream (the store, the offload task, the accessor) consumes a
`CloudStorageDriver` and is therefore identical whether the bytes are headed for the
private mail bucket or, in future, anywhere else a private driver points.

## Storage descriptor (per-row source of truth)

Mirroring `fil_storage_driver`, each message records its own raw location:

| Column | Type | Notes |
|--------|------|-------|
| `iem_raw_storage_driver` | varchar(16) | `inline` · `local` · `cloud` · `remote` (default `inline`) |
| `iem_raw_storage_key` | varchar(500) | tier-invariant relative key (`inbound_email/{yyyy}/{mm}/{id}.eml`) for `local` and `cloud`; null for `inline`/`remote` |
| `iem_raw_message` | text (existing) | **legacy/`inline` only** — populated for pre-refactor rows; new push writes leave it empty |
| `iem_raw_sync_failed_count` | int4 (default 0) | cold-offload retry counter (mirrors `fil_sync_failed_count`) |
| `iem_raw_sync_last_attempt` | timestamp(6), nullable | debugging breadcrumb for the offload task |

Driver meanings:

- **`inline`** — raw is in `iem_raw_message` (pre-refactor rows + the write-failure
  fallback). Read path supports this forever; no forced migration.
- **`local`** — raw is a file under the private on-disk store, located by
  `iem_raw_storage_key`. **The default for all new push-transport writes.**
- **`cloud`** — raw is an object in the **private** mail bucket, located by
  `iem_raw_storage_key`. Reached only via the driver's server-side `get()`, never a
  public URL. Set by the cold-offload task; reversible.
- **`remote`** — no platform copy; the raw/parts are fetched on demand from the IMAP
  source (the IMAP locator columns). Routed through the same accessor so callers are
  transport-blind.

## The raw store — `RawMessageStore` (new)

A thin facade (`plugins/inbound_email/includes/RawMessageStore.php`) that owns
reading/writing/deleting raw bytes so callers never touch paths or drivers directly. It
dispatches on the driver: `local` hits the filesystem; `cloud` delegates to the private
`CloudStorageDriver`.

```php
RawMessageStore::write(int $message_id, string $raw): array
        // writes to the LOCAL tier; returns ['driver' => 'local', 'key' => <key>]
RawMessageStore::read(string $driver, string $key): string         // returns the raw bytes
RawMessageStore::delete(string $driver, string $key): void          // remove the stored object (no-op for inline/remote)
RawMessageStore::offloadToCloud(InboundEmailMessage $m): bool       // local file -> private bucket; flip driver/key; used by the task
RawMessageStore::pullBackToLocal(InboundEmailMessage $m): bool      // private bucket -> local file; used by the reverse task
```

- `write()` always targets `local` (ingest never blocks on bucket I/O — same posture as
  the cloud-storage upload path, which always lands locally first).
- `read()` for `cloud` pulls the object to a temp path via `driver->get()`, returns its
  bytes, and unlinks the temp.
- `offloadToCloud()`/`pullBackToLocal()` are the per-row engine the cold-offload and
  reverse tasks call; they encapsulate the PUT/flip/delete (and the reverse) so the
  tasks stay thin, mirroring `File`'s cloud methods.

### One relative key, two tier bases

`iem_raw_storage_key` holds a single **tier-invariant relative key**:

```
inbound_email/{yyyy}/{mm}/{message_id}.eml
```

(`{yyyy}/{mm}` is the received-month shard; `{message_id}` ties the object to the row.)
Each tier supplies its own base; the relative key never changes, so **offload is a flag
flip + byte copy with no key rewrite**:

| Tier | Base prepended | Full location (example) |
|------|----------------|--------------------------|
| `local` | `{site_root}/storage/` (via `PathHelper::getSiteRoot()`) | `/var/www/html/joinerytest/storage/inbound_email/2026/06/12345.eml` |
| `cloud` | `{site_template}/` (auto-applied by `CloudStorageS3Driver::pathPrefix()`) | bucket object `joinerytest/inbound_email/2026/06/12345.eml` |

- `{site_root}` resolves via `PathHelper` (never `$_SERVER['DOCUMENT_ROOT']` or
  `__DIR__` navigation). It is the parent of `public_html`, alongside the existing
  non-web-served `logs/`, `uploads/`, `backups/`, `config/`.
- The local tier needs no `{site_template}` segment — each instance already has its own
  `site_root`, so cross-instance collision is impossible. The `{site_template}` prefix
  exists only on the **cloud** tier, where instances can share one bucket, and is applied
  automatically by the existing driver (same convention as public file uploads).
- Year/month sharding bounds directory / key-listing size.
- Cloud objects are written with content-type `message/rfc822`.
- Dev box: created dirs `777` / files `666` per the file-permission rules; production
  install sets tighter perms. The security boundary is the web-root exclusion (local) +
  the non-public bucket (cloud) + the permission gate — not the file mode.

The store directory and the private bucket must **never** be web-served.

## Write path — `InboundEmailRouter::storeMessage`

`storeMessage` already holds the full raw and already calls `extractBodies()`. Both push
providers deliver **true RFC822** to this point — Postfix pipes it on `php://input`, and
`MailgunProvider` reads `body-mime` (the raw-MIME "store & notify" route) and *rejects*
the request outright if `body-mime` is absent — so MIME-parsing the raw for the manifest
is always valid here. Change only where the raw goes, and add the manifest write:

1. Build the row as today **except** `iem_raw_message` is left empty.
2. Insert the row first (to get the serial id). On a unique-violation dedup (SQLSTATE
   23505 — same as today) **no file is written**, so a deduped message never orphans a
   file; return the dedup result before touching the store.
3. `RawMessageStore::write($id, $raw)`, then `UPDATE` the descriptor
   (`iem_raw_storage_driver = 'local'`, `iem_raw_storage_key = <key>`).
4. **Write the attachment manifest** (`ima_` rows) by MIME-parsing the raw
   (`Horde_Mime_Part::parseMessage()`), walking the parts, and recording filename /
   content-type / size / section / encoding / content-id / inline-flag — the same row
   shape `ImapIngestor` already writes from BODYSTRUCTURE. This is what gives push mail a
   per-attachment list and download. (The manifest is written regardless of which tier
   the raw landed in, including the `inline` fallback below.)
5. **File-write failure falls back to `inline`** — if `RawMessageStore::write()` fails
   (disk full / perms), write the raw to the `iem_raw_message` column instead, log a loud
   `INBOUND_RAW_LOCAL_WRITE_FAILED` marker so ops sees the disk problem, and continue.
   This is the one place a new `inline` write still happens — a safety net, not the norm.
   (No rollback choreography for the descriptor `UPDATE`: a DB `UPDATE` failing in the
   same request immediately after its own `INSERT` succeeded is effectively impossible,
   and the row stays re-fetchable, so handling it explicitly would be papering over a
   non-case.)

**Outbound / Sent rows are intentionally raw-less.** `storeOutboundRow()` already writes
an empty `iem_raw_message`; under the new column they are `inline`+empty (no raw, no
manifest, nothing to fetch). That is correct and unchanged — do not "fix" them into the
store.

### IMAP write path — `storeExtracted()` sets `remote`

The IMAP ingest path (`InboundEmailRouter::storeExtracted()`) is reference-backed — it
holds no raw to store. It **must set `iem_raw_storage_driver = 'remote'`** on the row (it
currently sets none, which would default to `inline` and read as "empty raw"). For
`remote`, `iem_raw_storage_key` stays null: the *key* is the IMAP locator tuple already on
the row (`iem_iia_inbound_imap_account_id` / `iem_imap_uid` / `iem_imap_uidvalidity` /
`iem_imap_folder`), exactly as `local`/`cloud` use `iem_raw_storage_key`. The driver flag
is therefore the single source of truth for *how* to get the bytes; the locator columns
say *which source and which message*. The `ima_` manifest is still written at ingest from
BODYSTRUCTURE, unchanged.

## Read path — one accessor

Add to `InboundEmailMessage`:

```php
getRawMessage(): ?string        // resolves the descriptor and returns the whole raw (forward re-attach)
getRawMimePart(string $section): ?array   // returns one decoded MIME part: ['content','type','filename']
```

(There is no whole-message *stream* accessor — the `.eml` download was retired for every
transport, so nothing streams the whole raw; only `getRawMimePart` streams, and it streams
a single part.)

Dispatch:

- `inline` → `iem_raw_message`.
- `local` → `RawMessageStore::read`.
- `cloud` → `RawMessageStore::read` → private driver `get()` → temp → bytes.
- `remote` → delegate to the IMAP on-demand fetch (`ImapIngestor::fetchPart`); if the
  source can't produce it, surface the "no longer available" result.

`getRawMimePart()` for the stored-raw drivers (`inline`/`local`/`cloud`) MIME-parses the
raw and extracts the one requested section. For `remote` it calls `fetchPart()`. This is
the single method the attachment endpoint uses.

**Bound the memory + clean the temp.** Stored raws are capped at 25 MB (the existing
`InboundEmailRouter` size limit), but we still avoid holding a large message twice:
`local` parses **directly from the on-disk file** (Horde parses from a stream/path), and
`cloud` pulls the object to a unique temp path, parses from it, and unlinks it in a
`finally` so a parse error or fatal never leaks temp files. Only `inline` (legacy) parses
from an in-memory string, which is unavoidable for column-stored raw.

### The actual readers being rerouted

There is **no** Download .eml action and **no** inline raw view anymore. The real
readers of a stored raw, post-retirement, are:

| File | Today | Change |
|------|-------|--------|
| `includes/InboundEmailRouter.php` | writes `iem_raw_message` directly; `storeExtracted()` sets no driver | push `storeMessage` writes via `RawMessageStore`, leaves the column empty, inline-fallback on failure, writes the `ima_` manifest; `storeExtracted()` sets `driver='remote'` |
| `includes/MailboxSender.php` (forward/reply re-attach) | dispatches on `account_id > 0` → `attachFromImap` else `attachFromRaw` (`Horde_Mime` over `iem_raw_message`) | dispatch on the **driver flag**: `remote` → `attachFromImap` (`fetchPart`); `inline`/`local`/`cloud` → `attachFromRaw` reading via `getRawMessage()` |
| `logic/admin_inbound_email_attachment_logic.php` (per-attachment download) | dispatches on `account_id`; the non-IMAP branch returns "not yet available for this message" | dispatch on the **driver flag**: `remote` → `fetchPart`; `inline`/`local`/`cloud` → `getRawMimePart()` and stream the part — lighting up push per-attachment download |

**Dispatch is unified on `iem_raw_storage_driver`** — `remote` routes to the IMAP
on-demand fetch, every other value routes to the accessor. `account_id` is no longer a
dispatch signal (it is now purely the `remote` locator). This makes the descriptor the
single source of truth, so the accessor is genuinely transport-blind as claimed.

## Attachments — parity across all mail types

Attachments are described by the bytes-free `ima_inbound_message_attachments` manifest
and fetched on demand; the manifest never holds bytes. After this spec:

| | Push (Postfix / Mailgun) | IMAP (pull) |
|---|---|---|
| Raw | `local` → `cloud` (private bucket), or `inline` legacy | `remote` (mailbox) |
| `ima_` manifest | **written at ingest** (MIME parse of raw) | written at ingest (BODYSTRUCTURE) |
| Per-attachment list | **yes** (from manifest) | yes (from manifest) |
| Per-attachment download | **yes** — `getRawMimePart()` over stored raw | yes — `fetchPart()` from mailbox |
| Forward re-attach | `attachFromRaw` over the accessor | `attachFromImap` (`fetchPart`) |

One manifest table, one reader UI, one download endpoint, one accessor — transport- and
tier-blind. The whole-message `.eml` is never reconstructed for the user; attachments
are served per-part.

## Cold offload + reverse (reuse the sync-task shape)

Two scheduled tasks modeled directly on `CloudStorageSync` / `CloudStorageReverseSync`:

- **`OffloadInboundRawToCloud`** — when `cloud_storage_private_enabled = true`, walks
  `iem_` rows where `iem_raw_storage_driver = 'local'` and
  `iem_raw_sync_failed_count < 5`, oldest first (optionally only rows older than N days).
  On failure: increment `iem_raw_sync_failed_count`, stamp `iem_raw_sync_last_attempt`,
  log, move on. After 5 failures the row is excluded and surfaces as stuck (same pattern
  as files). This task is also the one-time forward migration of `local` rows once a
  private bucket is first configured.
- **`PullInboundRawBackToLocal`** — activated only when the admin disables the private
  mail bucket; pulls `cloud` rows back to `local`, and self-deactivates when no `cloud`
  rows remain.

**`offloadToCloud()` ordering invariant** (the lesson cloud_storage's §6/§11 paid for —
never leave the flag pointing at bytes that aren't there):

1. `PUT` to the private bucket; verify success.
2. **Re-load the row.** If it was hard-deleted meanwhile, best-effort `delete()` the
   just-PUT object and stop — so no cloud orphan is created in the first place.
3. Commit the flag flip (`driver='cloud'`, key unchanged — it's tier-invariant) in one
   `UPDATE`.
4. **Only then** delete the local file. A crash between 3 and 4 leaves only a harmless
   orphaned local file (the row already reads from cloud) — rare, disk waste rather than a
   correctness problem, and not auto-reclaimed in v1.

`pullBackToLocal()` runs the inverse with the opposite ordering — **commit
`driver='local'` first, then best-effort bucket `delete()`** (logging `INBOUND_RAW_ORPHAN`
on delete failure), because while the flag says `cloud` the bucket is authoritative. A
local file already written but not yet flag-committed is overwritten idempotently on
retry. Both tasks re-check the row still exists before committing, so a concurrent
hard-delete can't strand bytes.

Both piggyback on the existing 15-minute cron (`process_scheduled_tasks.php`) and its
per-task advisory locking — no new cron entry.

## Deletion

The stored object is owned by the message row and cleaned up with it:

- **Permanent delete / purge** (`PurgeOldMailboxMessages` and any hard-delete path) →
  `RawMessageStore::delete()` (which dispatches to filesystem unlink or private-bucket
  `delete()`). Define this in the message class's deletion strategy
  (`$foreign_key_actions` / hard-delete hook) so a caller can't forget it.
- **Soft delete** leaves the object in place (the row is recoverable, so its raw must
  be).
- The `ima_` manifest already cascades on the message via its `$foreign_key_actions`.

The hard-delete hook is the single mechanism for reclaiming stored bytes. No separate
orphan-sweep pass — if stranded objects ever turn out to occur in practice, a sweep is
trivial to add then; building one now would be belt-and-suspenders over a hook that
already covers the case.

## Migration / backward compatibility

- **No forced migration.** Pre-launch there are no production messages
  ([no-production-users principle](../docs)); even so, `inline` rows read correctly
  forever via the accessor.
- **Backfill existing IMAP rows to `remote` (required for Option A, runs at upgrade).**
  Pre-existing reference-backed rows were written before the descriptor column existed, so
  they sit on the `inline` default. A migration sets them straight:
  `UPDATE iem_inbound_email_messages SET iem_raw_storage_driver = 'remote' WHERE
  iem_iia_inbound_imap_account_id IS NOT NULL AND iem_raw_storage_driver = 'inline'`.
  This is a data-only migration (not schema), idempotent, and must land **before** the
  driver-flag dispatch goes live, or existing IMAP attachments/forwards would route to the
  empty-raw accessor branch. Mirrors the existing `iia_001`/`iem_003` migration pattern.
- **No inline→files backfill task.** Pre-launch there are no production `inline` rows to
  migrate ([no-production-users principle](../docs)); any that exist on dev/test are
  disposable and read correctly forever via the `inline` accessor branch. New push mail
  goes straight to `local`. If a populated DB ever needs reclaiming, a one-off
  inline→`local` backfill (write file, build manifest, null the column) is trivial to add
  then — it isn't built now.
- **Legacy `inline` push rows carry no manifest**, so they show no per-attachment list
  (the manifest is written only for *new* push mail). Acceptable given there's no
  production data; not worth a lazy per-row manifest build.
- **Reverse migration needs local headroom.** Disabling the private bucket pulls every
  `cloud` row back to disk. The disable action surfaces the same headroom warning
  cloud_storage shows — the `cloud` row count and `disk_free_space()` — before
  activating `PullInboundRawBackToLocal`. If disk fills mid-run, per-row placement fails
  gracefully, the row stays `cloud`, and the failure counter surfaces it as stuck.
- New push writes always go to `local`; `inline` exists only as legacy-read + the
  write-failure fallback.

## Per-transport / per-tier end state

| Driver | Transport | Raw bytes | Attachment bytes | Read path |
|--------|-----------|-----------|------------------|-----------|
| `inline` | legacy push | `iem_raw_message` column | MIME-parsed from column | accessor (column) |
| `local` | push (new default) | private on-disk store | MIME-parsed from file | accessor (filesystem) |
| `cloud` | push (offloaded) | private S3 bucket | MIME-parsed from `get()`'d temp | accessor (server-side `get()`, gated) |
| `remote` | IMAP | none (mailbox) | `fetchPart()` from mailbox | accessor → `ImapIngestor` |

## Install & runtime provisioning

`{site_root}/storage/` holds durable mail bytes, so it is **runtime data on par with
`uploads/` and `backups/`**, not a scratch dir like `logs/`. The install scripts must
provision it the same way — and, critically, **back it with a persistent Docker volume**,
or a container rebuild silently destroys stored mail. The on-demand `inbound_email/...`
subtree is created by `RawMessageStore` at first write; the install layer only creates
and persists the base `storage/` directory. No new setting is introduced — the path
derives from `PathHelper::getSiteRoot()` exactly as cloud_storage derives its prefix.

Every site that provisions `uploads/`/`backups/` must add `storage/` alongside:

| File | Existing `uploads`/`backups` treatment | Add for `storage/` |
|------|----------------------------------------|--------------------|
| `_site_init.sh` (dir block) | `mkdir -p $SITE_ROOT/{uploads,logs,backups}` | `mkdir -p $SITE_ROOT/storage` |
| `_site_init.sh` (perms fallback) | `chmod -R 775 $SITE_ROOT/uploads` | `chmod -R 775 $SITE_ROOT/storage` |
| `install.sh` (both `docker run` blocks) | `-v ${SITENAME}_uploads:.../uploads`, `-v ${SITENAME}_backups:.../backups` | **`-v ${SITENAME}_storage:/var/www/html/${SITENAME}/storage`** — the durability-critical change |
| `install.sh` (generated `.dockerignore`) | `*/backups/*` | `*/storage/*` (runtime data stays out of the image) |
| `install.sh` (test-clone rsync) | `--exclude='uploads/*'` | `--exclude='storage/*'` (don't copy mail into test clones) |
| `build_dev_from_source.sh` (test-deploy mkdir) | `mkdir -p $deploy_directory/uploads` | `mkdir -p $deploy_directory/storage` |
| `fix_permissions.sh` | uploads chmod block | storage chmod block |
| `INSTALL_README.md` (volume table) | `{site}_uploads` row | `{site}_storage` row |

The code-deploy/upgrade path (`utils/upgrade.php`) syncs code only and never touches the
runtime data dirs, so `storage/` survives upgrades for free once the volume exists — the
same property `uploads/` and `backups/` already rely on.

## Files

### To create
| File | Purpose |
|------|---------|
| `plugins/inbound_email/includes/RawMessageStore.php` | local + cloud(private-bucket) read/write/stream/delete + offload/pull-back, over the `CloudStorageDriver` seam |
| `plugins/inbound_email/tasks/OffloadInboundRawToCloud.{json,php}` | cold offload `local` → private bucket; also the one-time forward migration when a bucket is first configured |
| `plugins/inbound_email/tasks/PullInboundRawBackToLocal.{json,php}` | reverse: private bucket → local on disable; self-deactivates |
| `plugins/inbound_email/tests/raw_message_store_test.php` | local + cloud round-trip (mock driver); key layout; missing-object handling; delete no-op for inline/remote |
| `plugins/inbound_email/tests/inbound_raw_storage_test.php` | ingest writes file + manifest + empty column; accessor resolves each driver; push per-attachment download; legacy inline reads; delete removes object |

### To modify
| File | Change |
|------|--------|
| `data/inbound_email_message_class.php` | add `iem_raw_storage_driver` (default `inline`), `iem_raw_storage_key`, `iem_raw_sync_failed_count`, `iem_raw_sync_last_attempt`; add `getRawMessage()`/`getRawMimePart()`; hard-delete removes the stored object; bump `@version` |
| `includes/InboundEmailRouter.php` | `storeMessage` writes raw via `RawMessageStore`, leaves column empty, writes `ima_` manifest from the MIME parse, inline-fallback on failure; **`storeExtracted()` sets `driver='remote'`**; bump `@version` |
| `includes/MailboxSender.php` | forward re-attach **dispatches on the driver flag** (`remote` → `attachFromImap`, else `attachFromRaw` reading via `getRawMessage()`); bump `@version` |
| `logic/admin_inbound_email_attachment_logic.php` | **dispatch on the driver flag** (`remote` → `fetchPart`, else `getRawMimePart()` + stream); bump `@version` |
| `includes/cloud_storage/CloudStorageDriverFactory.php` | add `privateDefault()` (shared creds + `cloud_storage_private_bucket`); bump `@version` |
| `tasks/PurgeOldMailboxMessages.php` | delete the stored object when purging; bump `@version` |
| `plugins/inbound_email/migrations/migrations.php` | add `iem_006_backfill_imap_remote_driver` (set existing IMAP rows to `driver='remote'`, before dispatch goes live) |
| `settings.json` | declare `cloud_storage_private_bucket`, `cloud_storage_private_enabled` |
| `adm/admin_cloud_storage.php` + `adm/logic/admin_cloud_storage_logic.php` | add the `cloud_storage_private_bucket` field (same page, same creds); same Save validates it independently with the **privacy hard-gate** (probe + anonymous-fetch-must-be-denied); on pass, latch `cloud_storage_private_enabled` and activate `OffloadInboundRawToCloud`; on disable, activate `PullInboundRawBackToLocal` |
| `plugin.json` | register the two tasks (offload + reverse); bump `version` |
| `maintenance_scripts/install_tools/_site_init.sh` | `mkdir -p $SITE_ROOT/storage` + `chmod 775` in the perms fallback |
| `maintenance_scripts/install_tools/install.sh` | add `${SITENAME}_storage` volume mount to both `docker run` blocks; add `*/storage/*` to `.dockerignore`; add `--exclude='storage/*'` to the test-clone rsync |
| `maintenance_scripts/install_tools/build_dev_from_source.sh` | `mkdir -p $deploy_directory/storage` in the test-deploy block |
| `maintenance_scripts/install_tools/fix_permissions.sh` | add a `storage/` chmod block alongside `uploads/` |
| `maintenance_scripts/install_tools/INSTALL_README.md` | add the `{site}_storage` row to the Docker volume table |

### Schema
Applied by **Sync with Filesystem** / `update_database` from the data class — the new
columns are additive (descriptor + counters); `inline` default keeps existing rows
readable. A migration finalizes any new NOT-NULL/DEFAULT the plugin-table sync defers,
per the existing `iem_002`/`iif_001` pattern.

## Testing

- **Store round-trip** — `write` then `read` returns identical bytes for `local`; cloud
  round-trip via a mock `CloudStorageDriver`; key layout is
  `{site_template}/{yyyy}/{mm}/{id}.eml`; missing object fails cleanly (logged, not
  fatal); `delete` is a no-op for `inline`/`remote`.
- **Ingest** — a pushed message writes the raw to a `local` file, writes the `ima_`
  manifest, sets `driver='local'` + key, leaves `iem_raw_message` empty; `extractBodies`
  still populates text columns; a simulated write failure falls back to `inline`.
- **Read** — `getRawMessage()` returns the right bytes for `inline`/`local`/`cloud`;
  `getRawMimePart()` extracts the correct part for each stored-raw driver; a `remote`
  row delegates to `fetchPart`.
- **Attachment parity** — a push message exposes the per-attachment list and downloads a
  single part via the same endpoint IMAP uses.
- **Unified dispatch + IMAP backfill** — a `remote` row routes the attachment endpoint and
  forward re-attach to `fetchPart` (not the empty-raw accessor branch); the
  `iem_006_backfill_imap_remote_driver` migration flips pre-existing IMAP rows
  (`account_id IS NOT NULL`) from `inline` to `remote` and is idempotent on re-run.
- **Privacy hard-gate** — a Save with a *public* private-bucket (anonymous fetch returns
  200) fails the check, leaves `cloud_storage_private_enabled` false, and does not activate
  offload; a genuinely private bucket (anonymous fetch denied) passes and latches enabled;
  a private-bucket failure does not block the public bucket's Save.
- **Offload / reverse** — `local` → private bucket flips the flag and removes the local
  file; failure increments the counter and surfaces as stuck after 5; reverse pulls back
  and self-deactivates.
- **Backward compatibility** — a pre-existing `inline` row reads correctly with no file
  present (it serves from the column; it carries no manifest).
- **Deletion** — permanent delete / purge removes the object (file or bucket); soft
  delete keeps it.
- Run `php -l` + `validate_php_file.php` on every created/modified PHP file.

## Security

- The local store is **outside the web root**; the cloud tier is a **private,
  non-public-readable** bucket. Mail is reachable only through the permission-gated
  reader/endpoint (`check_permission(5)`), which streams bytes server-side — never a
  public bucket URL, never a presigned link in this spec.
- Private-bucket privacy is a **hard gate, not a warning**: the Save flow PUTs a probe,
  proves an anonymous fetch is denied (a 200 fails the check), and only then latches
  `cloud_storage_private_enabled`. Mail never reaches a bucket whose privacy is unproven.
- Never log or echo raw message contents or embedded credentials; the store deals in
  opaque bytes.
- File/dir/object permissions follow the deployment's posture; the security boundary is
  the web-root/public-bucket exclusion + the permission gate, not the file mode.
- Untrusted-content markers on stored mail are unchanged.
- **Graceful when the cloud tier is unreachable.** If the private-bucket credentials are
  revoked or the bucket is down, `cloud`-stored mail can't be fetched (the same shape as
  cloud_storage public files 404ing). A failed `get()` surfaces a clean "temporarily
  unavailable" through the gated endpoint — never a fatal — and the storage admin
  health/ping reflects the red state. Reads recover automatically once creds are fixed;
  no data is lost (the bucket still holds the bytes).

## Versioning

- `plugin.json` minor bump (new internal storage mechanism; backward compatible —
  `inline` rows keep working, no user-visible change except push gaining per-attachment
  download).
- Bump `@version` on each modified file.

## Out of scope / future

- **Presigned-URL reads for the cloud tier.** Server-side streaming is sufficient at
  mail-read volume; presigning is a future egress optimization and would be the only
  change needed to push the read path off PHP. The `CloudStorageDriver` interface is left
  unchanged here so adding a `presignedGet()` later is additive.
- **Reusing the existing public uploads bucket.** Excluded by design — it is
  public-readable; inbound mail is private.
- **A separate provider / account / credentials for the mail bucket.** Out of scope now:
  the private mail bucket is one more bucket on the *same* provider+account as the public
  cloud storage (bucket name only, shared endpoint/region/keys). Supporting a distinct
  provider or instance for mail would mean a second credential set and is deferred until a
  customer actually needs it.
- **Per-attachment storage as first-class rows with their own bytes.** Attachments
  remain part of the raw and are extracted on demand; the manifest stays bytes-free.
- **IMAP raw storage.** IMAP is reference-backed; this spec only routes its `remote`
  driver through the shared accessor.
- **Changing the oversized-message rejection cap.** Unchanged.
