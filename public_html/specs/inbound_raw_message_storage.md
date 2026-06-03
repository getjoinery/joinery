# Move inbound raw messages out of the database (private file storage)

## Overview

Inbound mail received over the **push transports** (Postfix pipe, Mailgun webhook)
is stored with its **complete RFC822 message — base64 attachments and all — in the
`iem_raw_message` Postgres `text` column**. That is the classic "blobs in the
database" anti-pattern: attachments are the bulk of an email's size, base64 inflates
them ~33%, and every byte rides along in each backup, restore, and replication
stream while occupying the most expensive, least-scalable storage tier. A handful of
messages with large attachments bloats the database out of proportion to its actual
working set.

This spec moves the raw message bytes **out of the database into private file
storage**, leaving the database holding only what it needs for listing, search, and
display: headers, the `text/plain`/`text/html` bodies, and a small pointer to where
the raw lives. The full message (with attachments) is read back from the file store
on demand for the *Download .eml* action. No behavior changes for the user — the
reader and download work exactly as before; only the bytes move.

This is a **prerequisite refactor** for the IMAP inbound provider
([inbound_imap_provider.md](inbound_imap_provider.md)), which already stores no raw
at all (it is reference-backed to the remote mailbox). Both transports converge on a
single principle and a single read accessor.

## The principle

**The database holds the small, searchable, displayable parts of a message; the
heavy raw lives in the cheapest durable store available for that transport.**

| Transport | Where the raw durably lives | Why |
|-----------|-----------------------------|-----|
| Postfix / Mailgun (push) | **private file store** (this spec) | the source keeps no copy — the platform must persist it somewhere, just not in the DB |
| IMAP (pull) | the **remote mailbox** (no platform copy) | the mailbox is already a durable store; the platform stores only a locator and re-fetches on demand |

The DB row is identical in shape for every transport: headers + text bodies + a
**storage descriptor** saying where the raw is and how to get it. A single accessor
resolves that descriptor regardless of transport.

## Storage descriptor (per-row source of truth)

Mirroring the cloud-storage subsystem's `fil_storage_driver` pattern — the per-row
flag is authoritative, so a misconfigured global setting can never strand a message
— each message records its own raw location:

| Column | Type | Notes |
|--------|------|-------|
| `iem_raw_storage_driver` | varchar(16) | `inline` · `local` · `remote` · (future) `cloud` |
| `iem_raw_storage_key` | varchar(500) | file path/key for `local`; null for `inline`/`remote` |
| `iem_raw_message` | text (existing) | **legacy/`inline` only** — populated for pre-refactor rows; new push writes leave it empty |

Driver meanings:

- **`inline`** — the raw is in `iem_raw_message` (pre-refactor rows). Read path
  supports this forever; no forced migration.
- **`local`** — the raw is a file under the private store, located by
  `iem_raw_storage_key`. **The default for all new push-transport messages.**
- **`remote`** — there is no platform copy; the raw is fetched on demand from the
  source (IMAP locator columns from the IMAP spec). Set by the IMAP ingestor, not
  this spec; listed here so the accessor covers it.
- **`cloud`** *(future)* — a file relocated to a **private** object-storage bucket
  by a cold-offload sync task (see *Forward compatibility*). Not built here.

## The raw message store — `RawMessageStore` (new)

A small helper (`plugins/inbound_email/includes/RawMessageStore.php`) that owns
reading/writing/deleting raw bytes for the file-backed drivers, so callers never
touch paths directly:

```php
RawMessageStore::write(int $message_id, string $raw): string   // returns the storage key
RawMessageStore::read(string $driver, string $key): string     // streams/returns the raw
RawMessageStore::stream(string $driver, string $key): void      // echo to output without buffering whole
RawMessageStore::delete(string $driver, string $key): void      // remove the file (no-op for inline/remote)
```

The local driver is the only one implemented now. The signatures are
driver-parameterized so a future private-bucket driver slots in without changing
callers — the same seam cloud_storage uses.

### Disk layout (local driver)

Files live **outside the web root**, under the site root alongside `logs/`,
`uploads/`, `backups/` (none of which are web-served):

```
{site_root}/storage/inbound_email/{site_template}/{yyyy}/{mm}/{message_id}.eml
```

- `{site_root}` is the directory containing `public_html` (resolve via `PathHelper`,
  never `$_SERVER['DOCUMENT_ROOT']` or `__DIR__` navigation).
- `{site_template}` prefix lets multiple instances share a store without collision
  (same rationale as the bucket prefix in cloud_storage).
- Year/month sharding keeps any one directory from growing unbounded.
- Files are created with restrictive perms; the directory tree is created on demand.
  (Per the dev-server file-permission rules, created dirs `777` / files `666` on this
  dev box; production install sets tighter perms — note this, don't hardcode 666 as a
  security claim.)

The store directory must **never** be mapped into the web root or served by Apache.

## Store path change — `InboundEmailRouter::storeMessage`

`storeMessage` already has the full raw in memory and already calls `extractBodies()`
to populate the text columns. Change only where the raw goes:

1. Build the row as today **except** `iem_raw_message` is left empty.
2. Save the row first (to get the serial id), then `RawMessageStore::write($id, $raw)`
   and set `iem_raw_storage_driver = 'local'`, `iem_raw_storage_key = <key>`; update.
   (Or write to a key derived from a pre-allocated id — choose at build; the
   save-then-write order is simplest and keeps the key tied to the row id.)
3. If the file write fails, the message must not be silently lost: fall back to
   `inline` (write the raw to the column) and record the failure, rather than storing
   a row whose raw is unreachable. This is the one place an inline write still happens
   for new mail — a safety net, not the norm.

`extractBodies()` and all parsing are unchanged — they run on the in-memory raw
before it is handed to the store.

## Read path change — one accessor

Add `InboundEmailMessage::getRawMessage(): ?string` (and a `streamRawMessage()` for
the download) that resolves the descriptor:

- `inline` → return `iem_raw_message`.
- `local` → `RawMessageStore::read/stream(driver, key)`.
- `remote` → delegate to the IMAP on-demand fetch (the IMAP spec owns this); if the
  source can't produce it, surface the same "no longer available" result.

Reroute the **three** existing readers of `iem_raw_message` through the accessor:

| File | Today | Change |
|------|-------|--------|
| `logic/admin_inbound_email_message_logic.php:51` | `echo $message->get('iem_raw_message')` for *Download .eml* | `streamRawMessage()` — stream from disk, don't buffer the whole file in memory |
| `admin/admin_inbound_email_message.php:105` | inlines the entire raw into a `<pre>` on the detail page | read via accessor; **and** stop inlining multi-MB raw into the page — render it lazily (a "View raw" toggle / fetch) or replace the inline dump with the *Download .eml* link. Reading a large file to HTML-escape it into every detail-page load is the same waste in a new place |
| `includes/InboundEmailRouter.php` | writes `iem_raw_message` directly | writes via the store (above) |

No other code reads the column.

## Deletion

The stored file is owned by the message row and must be cleaned up with it:

- **Permanent delete / purge** (`PurgeOldMailboxMessages`, and any hard-delete path)
  → `RawMessageStore::delete()` for the row before/after removing it. Define this in
  the message class's deletion strategy (`$foreign_key_actions` / hard-delete hook)
  so it cannot be forgotten by a caller — see the Deletion System docs.
- **Soft delete** leaves the file in place (the row is recoverable, so its raw must be
  too).
- Orphan sweep: the optional backfill/maintenance task can also detect files with no
  surviving row and remove them (belt-and-suspenders; the delete hook is the primary
  mechanism).

## Migration / backward compatibility

- **No forced migration.** Pre-launch there are no production messages to preserve
  (see the no-production-users principle); even so, `inline` rows read correctly
  forever via the accessor, so this is safe on a populated DB too.
- **Optional one-time backfill task** (`MigrateInlineRawToFiles`): for each `inline`
  row, write the raw to the store, set `driver='local'` + key, and null
  `iem_raw_message`. Idempotent, batched, logs what it moved. Run once to reclaim DB
  space on an existing site; never required for correctness.
- New push writes always go to `local`; `inline` exists only as the legacy-read and
  the write-failure fallback.

## Forward compatibility — private object storage (future, not built here)

The existing cloud-storage bucket is **public-readable by design**
(`docs/cloud_storage.md`: "the bucket must be publicly readable"; "permissioned/
private files always stay on local disk"). Inbound mail is sensitive and **must not**
go there. A future `cloud` driver would be a **separate private bucket** with
authenticated reads (presigned URLs), and a cold-offload sync task mirroring
`CloudStorageSync` would relocate `local` files older than N days to it — same
driver-flag-is-truth pattern, same undo-on-failure posture. The descriptor and
`RawMessageStore` seam are designed so adding that driver touches neither ingestion
nor the reader. This spec stops at `local`.

## Files

### To create
| File | Purpose |
|------|---------|
| `plugins/inbound_email/includes/RawMessageStore.php` | read/write/stream/delete raw bytes for file-backed drivers (local now; pluggable) |
| `plugins/inbound_email/tasks/MigrateInlineRawToFiles.{json,php}` | optional one-time backfill: inline rows → files + null the column; orphan sweep |
| `plugins/inbound_email/tests/raw_message_store_test.php` | write/read/stream/delete round-trip; path layout; missing-file handling |
| `plugins/inbound_email/tests/inbound_raw_storage_test.php` | ingest writes file + empty column; accessor resolves each driver; legacy inline reads; delete removes file |

### To modify
| File | Change |
|------|--------|
| `plugins/inbound_email/data/inbound_email_message_class.php` | add `iem_raw_storage_driver` (+default `inline` for existing-row safety, new writes set `local`) and `iem_raw_storage_key`; add `getRawMessage()` / `streamRawMessage()`; hard-delete removes the file; bump `@version` |
| `plugins/inbound_email/includes/InboundEmailRouter.php` | `storeMessage` writes raw via `RawMessageStore`, leaves `iem_raw_message` empty, inline-fallback on write failure; bump `@version` |
| `plugins/inbound_email/logic/admin_inbound_email_message_logic.php` | *Download .eml* streams via `streamRawMessage()`; bump `@version` |
| `plugins/inbound_email/admin/admin_inbound_email_message.php` | raw view through the accessor; stop inlining full raw into the page (lazy/toggle or link to download); bump `@version` |
| `plugins/inbound_email/tasks/PurgeOldMailboxMessages.php` | delete the stored file when purging a message; bump `@version` |
| `plugins/inbound_email/plugin.json` | register the backfill task; bump `version` |

### Schema
Applied by **Sync with Filesystem** / `update_database` from the data class — no
migration (the two new columns are additive and nullable; `inline` default keeps
existing rows readable).

## Testing

- **Store round-trip** — `write` then `read`/`stream` returns identical bytes; key
  layout is `{site_template}/{yyyy}/{mm}/{id}.eml`; `read` of a missing file fails
  cleanly (logged, not fatal); `delete` is a no-op for `inline`/`remote`.
- **Ingest** — a pushed message writes the raw to a file, sets `driver='local'` + key,
  and leaves `iem_raw_message` empty; `extractBodies` still populates the text columns;
  a simulated file-write failure falls back to `inline` and records it.
- **Read** — `getRawMessage()` returns the right bytes for `inline` (column) and
  `local` (file); *Download .eml* streams the file without buffering it whole; a
  `remote` row delegates to the IMAP fetch path.
- **Backward compatibility** — a pre-existing `inline` row still downloads correctly
  with no file present.
- **Deletion** — permanent delete / purge removes the file; soft delete keeps it; the
  orphan sweep removes a file whose row is gone.
- **Backfill task** — moves `inline` rows to files, nulls the column, is idempotent on
  re-run, and reports counts.
- Run `php -l` + `validate_php_file.php` on every created/modified PHP file.

## Security

- The store directory is **outside the web root** and never served by Apache; raw
  messages are reachable only through the permission-gated reader
  (`check_permission(5)`, unchanged).
- Never log or echo raw message contents or any embedded credentials; the store deals
  in opaque bytes.
- File/dir permissions follow the deployment's posture (liberal on this dev box per
  the file-permission rules; tighter in production) — the security boundary is the
  web-root exclusion and the permission gate, not the file mode.
- Untrusted-content markers on stored mail are unchanged (the bytes are
  attacker-controlled, same as today).

## Versioning

- `plugin.json` minor bump (new internal storage mechanism; backward compatible —
  `inline` rows keep working, no user-visible change).
- Bump `@version` on each modified file.

## Out of scope / future

- **Per-attachment extraction / listing / individual download.** The whole-message
  model is unchanged; the raw is stored and served as one `.eml`. An attachments table
  + per-part UI is a separate cross-cutting feature.
- **Private object-storage (`cloud`) driver and cold-offload sync.** Designed for, not
  built here; `local` is the durable store for now.
- **Reusing the existing public cloud bucket.** Excluded by design — it is
  public-readable; inbound mail is private.
- **IMAP raw storage.** IMAP is reference-backed (no platform copy); this spec only
  routes its `remote` driver through the shared accessor.
- **Changing the oversized-message rejection cap.** The router's existing size limit
  is unchanged.
