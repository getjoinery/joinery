# Show Original Coverage: IMAP On-Demand Fetch + Header Retention

**Status:** Implemented
**Area:** mailbox plugin (reader kebab menu, message source/export endpoints, push ingest)
**Motivation session:** 2026-08-25 — owner looked for "view raw email" and found only Print, on every message.

## Problem

The reader's per-message kebab ships "Show original" and "Download .eml", gated on
`has_original` — a stored RFC822 original. On a current deployment that gate is
almost never true:

- **Reference-backed IMAP mail** (`iem_raw_storage_driver = 'remote'`) keeps no
  platform copy by design; parts are fetched from the source mailbox on demand.
- **Push mail** (Postfix/webhook, and archive imports) is stored as a **lean
  record** (specs/implemented/inbound_email_attachment_storage.md): headers parsed
  into columns, decoded bodies, non-text parts as private Files — and on the happy
  path the raw is discarded. The full header block is not retained anywhere.

Verified on production: all recent rows have no stored raw. The menu is honest but
the feature is dead, and the wire headers (Received chain, Content-Type/charset,
DKIM signatures as sent) are unrecoverable for push mail — exactly what you want
when debugging delivery or encoding problems.

## Design

One thread-row flag replaces the boolean: **`original_source`** ∈
`'stored' | 'imap' | 'headers' | 'none'`, resolved server-side per message:

| value | condition | menu |
|---|---|---|
| `stored` | a whole RFC822 original is stored (local/cloud key, or non-empty inline raw) | Show original + Download .eml |
| `imap` | `driver = 'remote'` and the IMAP locator's account still exists (`iem_iia_inbound_imap_account_id` set) | Show original + Download .eml (fetched live) |
| `headers` | no stored raw, but `iem_raw_headers` is non-empty | Show original (labeled reconstruction), no .eml |
| `none` | none of the above | neither item |

`has_original` disappears from the thread payload; the reader JS is its only
consumer and updates in the same change.

### Part A — remote rows: fetch the original from the source mailbox on demand

The true original still exists at the source (Gmail etc.), and the row carries its
locator. `ImapIngestor::fetchFullRaw()` (built for materialize-on-account-delete)
already does the fetch, with peek semantics and a Message-ID fallback for a
changed UIDVALIDITY.

- A shared resolver in `includes/message_export.php` —
  `mailbox_resolve_original(InboundEmailMessage $m, ?ImapIngestor $ingestor = null)` —
  returns the stored raw when there is one; otherwise, for a `'remote'` row with a
  live account, constructs an ingestor from
  `iem_iia_inbound_imap_account_id` (injectable for tests, mirroring
  `materializeRemoteMessage`), calls `fetchFullRaw`, and `close()`s it.
  Result shape: `['ok', 'raw', 'kind' ('stored'|'imap'|'reconstructed'), 'reason', 'locked']`.
- **Pass-through, never persisted** — the same posture as the per-part attachment
  fetch. Materialization stays an account-deletion concern.
- Both consumers use the resolver: `mailbox/message_source` (the modal; its 1 MB
  display cap unchanged) and `/profile/mailbox/original` (`format=eml` streams the
  fetched bytes; `format=print` is untouched — it renders from parsed columns).
- Failure (message expunged at the source, account unreachable) surfaces as the
  existing `{available:false, reason}` shape — the modal already renders reasons.
- A **sealed** remote row needs no vault window for this: the platform's seal
  protects the *local* copy at rest; the bytes served here come live from the
  source mailbox to a viewer who already passed the same MailboxViewer scope
  check that guards the message body. No local persistence, so nothing new to
  seal.

### Part B — push rows: retain the RFC822 header block at ingest

New column **`iem_raw_headers`** (`text`, nullable) on
`iem_inbound_email_messages`:

- **Captured** as the byte range of `$raw_email` up to the first blank line,
  capped at 64 KB (`InboundEmailRouter::rawHeaderBlock()`). Written by both parse
  moments: `storeMessage()` (live delivery + archive imports) and
  `parsePendingMessage()` (deferred Fortress relay ingest).
- **Sealed like content.** Header blocks name correspondents and subjects, so on
  a sealing mailbox they go through the same per-message DEK as the body: member
  of `$sealed_fields` and `$optional_sealed_fields` (legitimately absent on rows
  from before the column existed and on composed rows). Active on every
  direction — not compose-only. Plaintext mailboxes store it in the clear at
  insert; sealing mailboxes pass it through
  `sealMessageContent()` → `sealAndPersistContent()` (new trailing optional
  param, so `MailboxSender`'s existing calls are untouched).
- `sealExistingRow()` (level raise) and `unsealAndPersistContent()` (lowering)
  iterate `$sealed_fields` generically — the new column is covered with no code
  change there.
- **Not captured for outbound composed rows.** They have no wire original — the
  platform authored them, and their content is already fully present in columns.
  (When a connected account files a Sent copy, that copy is a remote row and
  Part A serves its true original from the source.)
- **No backfill.** Rows stored before this change discarded their raw; nothing
  can recover the headers. `original_source` answers `'none'` for them honestly.
- **Read path:** when the resolver finds no raw but the row has headers, it
  answers a **reconstruction**: the stored header block, a blank line, then the
  decoded plain body. `message_source` returns it with `reconstructed: true` and
  the modal labels it ("original headers plus the decoded text body — the raw
  wire bytes were not retained"). `.eml` download refuses a reconstruction — a
  file claiming to be the original must be the original.

### Reader changes (`mailbox_reader.js`)

- Kebab gating switches to `original_source`: Show original for any value but
  `'none'`; Download .eml only for `'stored'`/`'imap'`.
- The source modal renders the `reconstructed` label, and keeps its existing
  locked/unavailable/truncated handling.

## Deliberately not doing

- **Storing the fetched remote original** on view (that is materialize's job, at
  account deletion).
- **Reconstructing a full MIME document** from a lean record for `.eml` export —
  it would carry the name of an original without being one (re-encoded parts,
  new boundaries, broken DKIM).
- **Backfilling headers** for existing rows (raw is gone).
- **Header capture on `storeExtracted`** (IMAP poller): remote rows answer from
  the source via Part A; materialized rows keep whatever shape materialize wrote.

## Touched files

- `plugins/mailbox/data/inbound_email_message_class.php` — column, sealed-field
  membership, version bump
- `plugins/mailbox/includes/InboundEmailRouter.php` — `rawHeaderBlock()`, capture
  in `storeMessage()` / `parsePendingMessage()`, `sealMessageContent()` param
- `plugins/mailbox/includes/message_export.php` — `mailbox_resolve_original()`
- `plugins/mailbox/logic/message_source_logic.php`,
  `plugins/mailbox/logic/profile_original_logic.php` — resolve through the helper
- `plugins/mailbox/includes/MailboxService.php` — `original_source` in the thread
  payload (replacing `has_original`), SQL selects a headers-presence bit
- `plugins/mailbox/assets/mailbox_reader.js` — kebab gating + modal label
- `plugins/mailbox/plugin.json` — version bump
- `plugins/mailbox/docs/overview.md` — current-state description
- Tests: `plugins/mailbox/tests/message_original_test.php` (db tier): header
  capture plaintext + sealed (ciphertext in the column, opens under the row
  DEK, resolver answers locked with no window), the 64 KB cap, resolver
  dispatch across all four sources (stub ingestor for `'imap'`, pass-through
  asserted), reconstruction shape, and `original_source` mapping in the thread
  payload. The `.eml` endpoint's refusal keys on the resolver's
  `'reconstructed'` kind, which the test pins.
