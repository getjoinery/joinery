# Inbound raw-message + attachment storage (a private consumer of the offload layer)

## Overview

Mail received over the **push transports** (Postfix pipe, Mailgun webhook) is stored
with its complete RFC822 message — base64 attachments and all — in the
`iem_raw_message` Postgres `text` column. That is "blobs in the database": attachments
dominate an email's size, base64 inflates them ~33%, and every byte rides along in
each backup, restore, and replication stream on the most expensive storage tier.

This spec moves the raw bytes **out of the database** by making inbound mail a
**consumer of the unified offload layer**
([cloud_offload_unification.md](cloud_offload_unification.md)). That layer already
owns the storage descriptor pattern, the local→cloud offload/reverse engine, the
admin lifecycle, and — crucially — the **private store**: a bucket the platform has
*verified non-public* and reads server-side behind a permission gate. Inbound mail is
private and attacker-controlled, so it declares `visibility = 'private'` and inherits
all of that. This spec adds **only the email-specific pieces**: the `iem_` descriptor,
the mail `StorageProfile`, the ingest write + attachment manifest, the read accessor,
and the read-dispatch at the three call sites. It adds **no** bucket config, no
privacy gate, no offload/reverse logic — those live in the offload layer.

It also **closes the attachment gap between transports.** IMAP mail has a
per-attachment list and per-attachment download (fetched on demand from the mailbox);
push mail has neither, because its attachments are buried in the inline raw blob. Once
the raw moves to a real store and a manifest is written at ingest, push mail gets the
same per-attachment experience — one reader UI, one endpoint, one accessor, for all
mail types.

A free side benefit: nothing full-text-searches `iem_raw_message` (only three call
sites read it, all below), so moving the raw off-row **shrinks the hot table** with
zero search-behavior impact.

## The principle

**The database holds the small, searchable, displayable parts of a message; the heavy
raw lives in the cheapest durable store for that transport. Where "a bucket" is
involved, it is the platform's verified-private store, reached through the shared
offload layer — inbound mail configures none of that itself.**

| Transport | Where the raw durably lives | Why |
|-----------|-----------------------------|-----|
| Postfix / Mailgun (push) | platform local disk, offloaded to the **private store** by the shared engine | the source keeps no copy; the platform must persist it, just not in the DB |
| IMAP (pull) | the **remote mailbox** (no platform copy) | the mailbox is already durable; the platform keeps only a locator and re-fetches on demand |

The DB row is identical in shape for every transport: headers + text bodies +
attachment manifest + a **storage descriptor**. One accessor resolves the descriptor.

## Storage descriptor (per-row source of truth)

The offload layer keys its engine on a per-table descriptor; mail supplies its own
columns (the names the `StorageProfile` returns):

| Column | Type | Notes |
|--------|------|-------|
| `iem_raw_storage_driver` | varchar(16) | `inline` · `local` · `cloud` · `remote` (default `inline`) |
| `iem_raw_storage_key` | varchar(500) | tier-invariant relative key (`inbound_email/{yyyy}/{mm}/{id}.eml`) for `local`/`cloud`; null for `inline`/`remote` |
| `iem_raw_message` | text (existing) | **legacy/`inline` only** — pre-refactor rows + the write-failure fallback; new push writes leave it empty |
| `iem_raw_sync_failed_count` | int4 (default 0) | offload retry counter (the engine's failure cap reads this) |
| `iem_raw_sync_last_attempt` | timestamp(6), nullable | offload breadcrumb |

Driver meanings:

- **`inline`** — raw is in `iem_raw_message` (pre-refactor rows + the write-failure
  fallback). Read forever; no forced migration.
- **`local`** — raw is a file under the private on-disk store, located by
  `iem_raw_storage_key`. **The default for all new push writes.**
- **`cloud`** — raw is an object in the **private store**, located by the same key.
  Reached only via the shared driver's server-side `get()`, never a public URL. Set by
  the shared offload engine; reversible.
- **`remote`** — no platform copy; parts are fetched on demand from IMAP (the IMAP
  locator columns). Routed through the same accessor so callers are transport-blind.

## The mail storage class — `RawMessageStore implements StorageProfile`

One class (`plugins/inbound_email/includes/RawMessageStore.php`) wears two hats:

1. **The offload layer's `StorageProfile`** — so the shared `CloudOffloadEngine` can
   offload/reverse mail rows. It declares:
   - `visibility()` → `'private'`
   - the `iem_raw_*` column names; `table()` = `iem_inbound_email_messages`
   - `itemsForRow($id)` → forward: the single on-disk `.eml` (`[{local_path,
     remote_key, content_type: 'message/rfc822'}]`); null if the file is missing
   - `reverseItemsForRow($id)` → reverse: the same single `.eml`, computed from the key
     scheme **without** needing local bytes present (`[{remote_key, local_path,
     content_type: 'message/rfc822'}]`) — what the engine's pull-back enumerates
   - `eligibilityWhere()` → `''` (any `local` row is eligible; optionally restrict to
     rows older than N days)
   - `forwardTaskClass()`/`reverseTaskClass()` → the two shims below
2. **The consumer's request-time byte I/O** (which the offload layer leaves to each
   consumer):
   ```php
   RawMessageStore::write(int $message_id, string $raw): array   // writes LOCAL; ['driver'=>'local','key'=>…]
   RawMessageStore::read(string $driver, string $key): string    // local file read, or forVisibility('private')->get()
   RawMessageStore::delete(string $driver, string $key): void    // unlink / private get-delete; no-op for inline/remote
   ```

`write()` always targets `local` (ingest never blocks on bucket I/O — the engine
offloads later, same posture as the public-files path). `read()` for `cloud` pulls the
object to a unique temp via the shared private driver, returns its bytes, and unlinks.
There is no separate offload/pull-back code here — `RawMessageStore` provides the
profile surface and the shared engine does the moving.

> This settles the earlier "should `RawMessageStore` be its own class" question: it is
> the mail `StorageProfile`. It exists because request-time I/O is per-consumer and the
> profile is the natural home for the key scheme it already owns.

### One relative key, two tier bases

`iem_raw_storage_key` holds a single tier-invariant relative key:

```
inbound_email/{yyyy}/{mm}/{message_id}.eml
```

(`{yyyy}/{mm}` = received-month shard; `{message_id}` ties the object to the row.)
Each tier supplies its own base, so offload is a flag flip + byte copy with **no key
rewrite**:

| Tier | Base | Full location (example) |
|------|------|--------------------------|
| `local` | `{site_root}/storage/` (via `PathHelper::getSiteRoot()`) | `…/storage/inbound_email/2026/06/12345.eml` |
| `cloud` | `{site_template}/` (auto-applied by the shared S3 driver) | bucket object `joinerytest/inbound_email/2026/06/12345.eml` |

- `{site_root}` resolves via `PathHelper` (never `$_SERVER['DOCUMENT_ROOT']` or
  `__DIR__`); it is the parent of `public_html`, alongside `logs/`, `uploads/`,
  `backups/`.
- The `{site_template}` prefix exists only on the **cloud** tier (shared bucket,
  applied automatically by the driver). The local tier needs none — each instance has
  its own `site_root`.
- Cloud objects are written `message/rfc822`.
- The store directory and the private bucket must **never** be web-served. Dev perms
  per the file-permission rules; production sets tighter. The boundary is the web-root
  exclusion (local) + the verified-private bucket (cloud) + the permission gate.

## Write path — `InboundEmailRouter::storeMessage`

`storeMessage` already holds the full raw and calls `extractBodies()`. Both push
providers deliver true RFC822 here (Postfix on `php://input`; `MailgunProvider` reads
`body-mime` and rejects the request if absent), so MIME-parsing the raw for the
manifest is always valid. Change only where the raw goes, and add the manifest:

1. Build the row as today **except** `iem_raw_message` is left empty.
2. Insert first (to get the serial id). On a unique-violation dedup (SQLSTATE 23505 —
   as today) **no file is written**, so a deduped message never orphans a file; return
   the dedup result before touching the store.
3. `RawMessageStore::write($id, $raw)`, then `UPDATE` the descriptor
   (`iem_raw_storage_driver='local'`, `iem_raw_storage_key=<key>`).
4. **Write the attachment manifest** (`ima_` rows) by MIME-parsing the raw
   (`Horde_Mime_Part::parseMessage()`), walking the parts, recording filename /
   content-type / size / section / encoding / content-id / inline-flag — the same row
   shape `ImapIngestor::writeManifest()` produces from BODYSTRUCTURE. This is what gives
   push mail a per-attachment list + download.
5. **File-write failure falls back to `inline`** — if `write()` fails (disk full /
   perms), store the raw in `iem_raw_message` instead, log a loud
   `INBOUND_RAW_LOCAL_WRITE_FAILED` marker, and continue. The one place a new `inline`
   write still happens — a safety net, not the norm.

**Outbound / Sent rows stay raw-less.** `storeOutboundRow()` already writes an empty
`iem_raw_message`; they are `inline`+empty (no raw, no manifest). Unchanged.

### IMAP write path — `storeExtracted()` sets `remote`

`storeExtracted()` is reference-backed and holds no raw. It **must set
`iem_raw_storage_driver='remote'`** (it currently sets none → defaults to `inline` →
reads as "empty raw"). `iem_raw_storage_key` stays null: the key is the IMAP locator
tuple already on the row. The driver flag is the single source of truth for *how* to
get the bytes; the locator columns say *which source/message*. The `ima_` manifest is
still written at ingest from BODYSTRUCTURE, unchanged.

## Read path — one accessor

Add to `InboundEmailMessage`:

```php
getRawMessage(): ?string                  // whole raw (forward re-attach)
getRawMimePart(string $section): ?array   // one decoded part: ['content','type','filename']
```

Dispatch on `iem_raw_storage_driver`:

- `inline` → `iem_raw_message`.
- `local` → `RawMessageStore::read` (filesystem).
- `cloud` → `RawMessageStore::read` → shared private driver `get()` → temp → bytes.
- `remote` → `ImapIngestor::fetchPart`; if the source can't produce it, surface "no
  longer available."

`getRawMimePart()` for stored-raw drivers MIME-parses and extracts the one section;
for `remote` it calls `fetchPart()`. **Bound memory + clean temp:** `local` parses
directly from the on-disk file; `cloud` pulls to a unique temp, parses, and unlinks in
a `finally`; only legacy `inline` parses from an in-memory string. (No whole-message
stream accessor — the `.eml` download was retired for every transport; only
`getRawMimePart` streams, and only one part.)

### The actual readers being rerouted

There is no Download .eml and no inline raw view. The real readers of a stored raw:

| File | Today | Change |
|------|-------|--------|
| `includes/InboundEmailRouter.php` | writes `iem_raw_message` directly; `storeExtracted()` sets no driver | push `storeMessage` writes via `RawMessageStore`, leaves the column empty, writes the `ima_` manifest, inline-fallback on failure; `storeExtracted()` sets `driver='remote'` |
| `includes/MailboxSender.php` (forward/reply re-attach) | dispatches on `account_id > 0` → `attachFromImap` else `attachFromRaw` over `iem_raw_message` | dispatch on the **driver flag**: `remote` → `attachFromImap` (`fetchPart`); `inline`/`local`/`cloud` → `attachFromRaw` reading via `getRawMessage()` |
| `logic/admin_inbound_email_attachment_logic.php` (per-attachment download) | dispatches on `account_id`; the non-IMAP branch returns "not yet available" | dispatch on the **driver flag**: `remote` → `fetchPart`; `inline`/`local`/`cloud` → `getRawMimePart()` + stream — lighting up push per-attachment download |

**Dispatch is unified on `iem_raw_storage_driver`** — `remote` → IMAP on-demand fetch,
every other value → the accessor. `account_id` is no longer a dispatch signal (now
purely the `remote` locator), so the accessor is genuinely transport-blind.

## Attachments — parity across all mail types

| | Push (Postfix / Mailgun) | IMAP (pull) |
|---|---|---|
| Raw | `local` → `cloud` (private store), or `inline` legacy | `remote` (mailbox) |
| `ima_` manifest | **written at ingest** (MIME parse) | written at ingest (BODYSTRUCTURE) |
| Per-attachment list | **yes** (from manifest) | yes (from manifest) |
| Per-attachment download | **yes** — `getRawMimePart()` over stored raw | yes — `fetchPart()` from mailbox |
| Forward re-attach | `attachFromRaw` over the accessor | `attachFromImap` (`fetchPart`) |

One manifest table, one reader UI, one download endpoint, one accessor — transport-
and tier-blind. The whole-message `.eml` is never reconstructed; attachments are served
per-part.

## Offload — inherited from the engine

Mail does **not** implement offload. It registers with the shared layer and the engine
does the work:

- **Declare `RawMessageStore` in `plugin.json` under `storage_profiles`** (class name
  only — the registry instantiates it and reads `visibility()`; no runtime
  self-registration). The shared `StorageProfileRegistry` then sees the mail profile the
  same way it sees the core profile — including while the plugin is deactivated, since
  the declaration lives on disk. `CloudStorageLifecycle` activates mail's forward task
  when the private store is enabled (gate passed) and its reverse task on
  disable+pull-back — the same wiring that drives the public-files tasks, over every
  `private`-visibility profile.
- **Drain before uninstall.** Because this plugin owns a `private` profile,
  *uninstalling* it (which removes the declaration from disk) **requires the mail store
  drained back to local first**, per the offload layer's drain-before-uninstall rule —
  otherwise cloud-resident raw objects would be invisible to the bucket-immutability
  guard and could be stranded. Deactivation alone is safe (files, and so the
  declaration, remain).
- **Two task shims** (registered in `plugin.json`), each a one-liner:
  - `OffloadInboundRawToCloud` → `CloudOffloadEngine::syncBatch(new RawMessageStore())`
  - `PullInboundRawBackToLocal` → `CloudOffloadEngine::reverseBatch(new RawMessageStore())`

  They exist only so the scheduler tracks mail offload status distinctly; all logic —
  the PUT→reload→flip→delete ordering, the failure cap, batching — is the engine's.

Until a private store is configured and gated (in the offload layer), mail simply
stays `local` on disk forever; the feature degrades cleanly to local-only.

## Deletion

The stored object is owned by the message row:

- **Permanent delete / purge** → the message's hard-delete hook calls
  `RawMessageStore::delete()` (filesystem unlink or private-store `delete()`). Define it
  in the message class's deletion strategy so no caller can forget it. (Note:
  `PurgeOldMailboxMessages` already routes through `permanent_delete()` per row rather
  than a bulk SQL `DELETE`, so the hook fires — done in a prior change.)
- **Soft delete** leaves the object in place (the row is recoverable).
- The `ima_` manifest already cascades via its `$foreign_key_actions`.

No separate orphan-sweep — the hard-delete hook is the single reclaim path.

## Migration / backward compatibility

- **No forced migration.** Pre-launch there are no production messages; `inline` rows
  read correctly forever via the accessor.
- **Backfill existing IMAP rows to `remote` (`iem_006`, runs at upgrade).**
  Reference-backed rows written before the descriptor column sit on the `inline`
  default. A data-only, idempotent migration corrects them — and must land **before**
  the driver-flag dispatch goes live, or existing IMAP attachments/forwards route to
  the empty-raw branch:
  ```sql
  UPDATE iem_inbound_email_messages SET iem_raw_storage_driver = 'remote'
   WHERE iem_iia_inbound_imap_account_id IS NOT NULL AND iem_raw_storage_driver = 'inline';
  ```
  Mirrors the `iia_001`/`iem_003` pattern.
- **No inline→files backfill.** Pre-launch there are no production `inline` rows;
  any on dev/test read correctly via the `inline` branch. New push goes straight to
  `local`.
- **Legacy `inline` push rows carry no manifest** (written only for new push mail), so
  they show no per-attachment list. Acceptable with no production data.
- Descriptor columns are additive (default `inline` keeps existing rows readable),
  applied by **Sync with Filesystem** / `update_database`; a migration finalizes any
  deferred NOT-NULL/DEFAULT per the `iem_002`/`iif_001` pattern.

## Install & runtime provisioning

`{site_root}/storage/` holds durable mail bytes — **runtime data on par with
`uploads/` and `backups/`**, not scratch like `logs/`. It must be provisioned the same
way and, critically, **backed by a persistent Docker volume**, or a container rebuild
destroys stored mail. The `inbound_email/...` subtree is created by `RawMessageStore`
at first write; the install layer creates and persists the base `storage/` dir. No new
setting — the path derives from `PathHelper::getSiteRoot()`.

| File | Existing `uploads`/`backups` treatment | Add for `storage/` |
|------|----------------------------------------|--------------------|
| `_site_init.sh` (dir block) | `mkdir -p $SITE_ROOT/{uploads,logs,backups}` | `mkdir -p $SITE_ROOT/storage` |
| `_site_init.sh` (perms fallback) | `chmod -R 775 $SITE_ROOT/uploads` | `chmod -R 775 $SITE_ROOT/storage` |
| `install.sh` (both `docker run` blocks) | `-v ${SITENAME}_uploads:.../uploads` | **`-v ${SITENAME}_storage:/var/www/html/${SITENAME}/storage`** — the durability-critical change |
| `install.sh` (generated `.dockerignore`) | `*/backups/*` | `*/storage/*` |
| `install.sh` (test-clone rsync) | `--exclude='uploads/*'` | `--exclude='storage/*'` |
| `build_dev_from_source.sh` (test-deploy mkdir) | `mkdir -p $deploy_directory/uploads` | `mkdir -p $deploy_directory/storage` |
| `fix_permissions.sh` | uploads chmod block | storage chmod block |
| `INSTALL_README.md` (volume table) | `{site}_uploads` row | `{site}_storage` row |

`utils/upgrade.php` syncs code only and never touches runtime data dirs, so `storage/`
survives upgrades once the volume exists — as `uploads/`/`backups/` already do.

## Files

### To create
| File | Purpose |
|------|---------|
| `plugins/inbound_email/includes/RawMessageStore.php` | the mail `StorageProfile` (`visibility=private`) + request-time write/read/delete |
| `plugins/inbound_email/tasks/OffloadInboundRawToCloud.{json,php}` | shim → `CloudOffloadEngine::syncBatch(new RawMessageStore())` |
| `plugins/inbound_email/tasks/PullInboundRawBackToLocal.{json,php}` | shim → `CloudOffloadEngine::reverseBatch(new RawMessageStore())` |
| `plugins/inbound_email/tests/raw_message_store_test.php` | local + cloud round-trip (mock private driver); key layout; missing-object handling; delete no-op for inline/remote |
| `plugins/inbound_email/tests/inbound_raw_storage_test.php` | ingest writes file + manifest + empty column; accessor resolves each driver; push per-attachment download; legacy inline reads; delete removes object |

### To modify
| File | Change |
|------|--------|
| `data/inbound_email_message_class.php` | add the `iem_raw_*` descriptor columns; add `getRawMessage()`/`getRawMimePart()`; hard-delete removes the stored object; bump `@version` |
| `includes/InboundEmailRouter.php` | `storeMessage` writes via `RawMessageStore`, leaves the column empty, writes the `ima_` manifest, inline-fallback; `storeExtracted()` sets `driver='remote'`; bump `@version` |
| `includes/MailboxSender.php` | forward re-attach dispatches on the driver flag (`remote`→`attachFromImap`, else `attachFromRaw` via `getRawMessage()`); bump `@version` |
| `logic/admin_inbound_email_attachment_logic.php` | dispatch on the driver flag (`remote`→`fetchPart`, else `getRawMimePart()` + stream); bump `@version` |
| `plugin.json` | register the two task shims + declare `RawMessageStore` under the `storage_profiles` key (class name only); bump `version` |
| `migrations/migrations.php` | add `iem_006_backfill_imap_remote_driver` (before dispatch goes live) |
| install scripts (`_site_init.sh`, `install.sh`, `build_dev_from_source.sh`, `fix_permissions.sh`, `INSTALL_README.md`) | provision + persist `storage/` per the table above |
| `plugins/inbound_email/docs/overview.md` | document the raw-message storage descriptor (`inline`/`local`/`cloud`/`remote`), the one accessor, per-attachment manifest + download parity across transports, the `{site_root}/storage/` location + Docker-volume durability, and that the cloud tier is the platform's verified-private store reached via the shared offload layer — as the current design |

### Depends on (from the offload layer, not modified here)
The private store, the privacy gate, `CloudStorageDriverFactory::forVisibility()`, the
`CloudOffloadEngine`, `CloudStorageLifecycle`, `StorageProfile`/`StorageProfileRegistry`
— all delivered by [cloud_offload_unification.md](cloud_offload_unification.md), which
**must land first.**

## Testing

- **Store round-trip** — `write` then `read` returns identical bytes for `local`;
  cloud round-trip via a mock private driver; key layout
  `inbound_email/{yyyy}/{mm}/{id}.eml`; missing object fails cleanly (logged, not
  fatal); `delete` is a no-op for `inline`/`remote`.
- **Ingest** — a pushed message writes a `local` file, writes the `ima_` manifest, sets
  `driver='local'`+key, leaves `iem_raw_message` empty; `extractBodies` still populates
  text; a simulated write failure falls back to `inline`.
- **Read** — `getRawMessage()` returns the right bytes for `inline`/`local`/`cloud`;
  `getRawMimePart()` extracts the correct part for each stored-raw driver; a `remote`
  row delegates to `fetchPart`.
- **Attachment parity** — a push message exposes the per-attachment list and downloads a
  single part via the same endpoint IMAP uses.
- **Unified dispatch + IMAP backfill** — a `remote` row routes the attachment endpoint
  and forward re-attach to `fetchPart` (not the empty-raw branch); `iem_006` flips
  pre-existing IMAP rows from `inline` to `remote` and is idempotent.
- **Offload via the shared engine** — with a gated private store, mail's
  `OffloadInboundRawToCloud` shim flips a `local` row to `cloud` and removes the local
  file (the engine's behavior, exercised through the mail profile); reverse pulls back.
- **Deletion** — permanent delete / purge removes the object; soft delete keeps it.
- `php -l` + `validate_php_file.php` on every created/modified PHP file.

## Security

- The local store is **outside the web root**; the cloud tier is the platform's
  **verified-private** store, read server-side behind `check_permission(5)` — never a
  public URL or presigned link. Mail inherits the privacy guarantee proven once by the
  offload layer.
- Never log or echo raw message contents or embedded credentials; the store deals in
  opaque bytes.
- Untrusted-content markers on stored mail are unchanged.
- **Graceful when the cloud tier is unreachable** — a failed `get()` surfaces a clean
  "temporarily unavailable" through the gated endpoint, never a fatal; reads recover
  once creds/bucket are fixed (the bucket still holds the bytes).

## Versioning

- `plugin.json` minor bump (new internal storage mechanism; backward compatible —
  `inline` rows keep working; push gains per-attachment download).
- Bump `@version` on each modified file; `@version 1.0` on new files.

## Out of scope / future

- **The offload engine, lifecycle, private store, and privacy gate** — owned by
  [cloud_offload_unification.md](cloud_offload_unification.md).
- **Presigned-URL reads for the cloud tier** — a future egress optimization in the
  driver; server-side streaming suffices at mail-read volume.
- **Per-attachment storage as first-class rows with their own bytes** — attachments stay
  part of the raw, extracted on demand; the manifest stays bytes-free.
- **IMAP raw storage** — IMAP is reference-backed; only its `remote` driver routes
  through the accessor.
- **Changing the oversized-message rejection cap** — unchanged.
