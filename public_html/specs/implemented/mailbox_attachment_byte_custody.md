# Mailbox attachment byte custody

## The problem in plain terms

Connect a mailbox over IMAP and the platform stores the message but **not** the
attachment bytes — it records what attachments exist and pulls a part from the
server only when you open it. That is the right default: hooking up a ten-year
Gmail archive should not mean downloading ten years of PDFs.

Later, the same mail arrives again in a form that *does* carry the bytes — a
Google Takeout archive, an `.eml` drop, any future importer. The platform
recognises the message, says "already have this", and throws the bytes away. The
message keeps borrowing its attachments from a mailbox the user may one day
disconnect.

Worse, it depends on the order things were done. Import the archive **first** and
the bytes are kept; connect IMAP first and they are not. Same two actions, same
end state expected, different result. A user should not have to know the right
order.

## The rule

> **Local bytes win. A reference is what we have when we don't have the bytes.**
> Any path that turns up the real bytes for a reference-backed attachment takes
> them, whatever order it happens in.

A *reference-backed* attachment is a manifest row with no `ima_fil_file_id` on a
message whose `iem_raw_storage_driver` is `'remote'`. That second condition
matters: a row with no File on an `'inline'`/`'local'`/`'cloud'` message is a
**section pointer into a raw the platform already stores** — those bytes are
already local, and upgrading them would duplicate custody. The upgrade targets
only rows whose bytes genuinely live somewhere else.

## Why it doesn't work today

The read side already follows the rule. `mailbox_retrieve_attachment_bytes()`
(`plugins/mailbox/includes/attachment_retrieval.php`) checks `ima_fil_file_id`
first and only falls back to fetching the part from the source. A
reference-backed row becoming file-backed is a state the schema and the reader
already understand.

The write side has one gap, in one direction:

| Order | Today | Why |
|---|---|---|
| Archive import → IMAP later | **Correct.** Bytes kept, server locator added. | `ImapIngestor::adoptLocatorIfMissing()` writes four locator columns and nothing else; the manifest is left alone (`dedup ⇒ already has one`). |
| IMAP → archive import later | **Loses the bytes.** | `MailArchiveImporter::importEntry()` finds the existing row via `existingMessageId()`, records `dedup`, returns — while holding the full raw (`$raw`) in memory. |

So the fix is at one decision point: **dedup should mean "don't duplicate the
message", not "don't accept anything".**

## The blocker that isn't

Attachment bytes on a protected mailbox are sealed. The obvious objection is that
a background import can't seal onto an existing message, because
`ima_is_sealed` means *"an AEAD blob under the owning message's DEK"* and using
that key means opening it, which needs the owner's unlock window. Imports run in
cron (`RunMailImports`), which never has one.

That objection is wrong, and the reason is the platform's own rule
(`includes/VaultCrypto.php`):

> A consumer never seals content directly to the vault keypair. It generates one
> random per-item DEK, seals the DEK to the vault's public key (**cheap, works
> offline**, no size limit), and seals the actual content under the DEK with AEAD.

Sealing needs only the **public** key. Writing sealed bytes in cron is normal;
only reading them needs an unlock. `DriveSealed::createSealedFile()` does exactly
this today — `requireVault($owner_id)` for the public key, `newItemDek()`, seal,
record the wrapping. `VaultUnlock::secretKey()` appears nowhere on that path
(it lives only in `fileKey()`/`versionKeyFor()`, the read side).

## The actual defect

Mail attachments are the one sealed thing on the platform that cannot be written
independently, because they **borrow** the message's key instead of holding their
own. Every other consumer — Drive files, conversation key grants, Direct
identities — carries a per-item key wrapped to the owner's vault.

`data/files_class.php` names the deviation outright, describing the per-file key
as *"the same shape mail uses where a message DEK seals related Files."* Two
shapes exist for the same job; only one of them works offline, and only one of
them is what the rotation ceremony sweeps generically.

**Fix the grain and the ordering problem stops existing.** This spec is not "let
the importer seal things"; it is "attachment bytes are sealed by the File that
holds them, like every other file on the platform."

## Design

### No new columns

`File` is already a complete blob-only sealed consumer: `fil_protection_level`,
`fil_content_sealed`, `fil_sealed_key`, `fil_sealed_owner_user_id`,
`fil_key_generation`. Nothing is added to `ima_` and no new crypto is written.

Rotation is free **and this has been verified against the code**: the Drive
reseal callback (`includes/DriveSealed.php`, the `VaultUnlock::onReseal`
registration at the bottom of the file) selects rows by
`fil_content_sealed = true AND fil_key_generation = ? AND
fil_sealed_owner_user_id = ?` — it is **not** scoped to `fil_source = 'drive'`.
An upgraded attachment File is re-wrapped by the existing ceremony with no new
code. Do not add a source filter to that query; its breadth is what makes this
design work.

### The two flags, precisely

Two different columns describe two different sealed shapes, and the executor
must not conflate them:

- `ima_is_sealed` (on the manifest row) — **legacy shape**: the linked File's
  on-disk bytes are an AEAD blob under the *message's* DEK
  (`iem_sealed_key`), opened by
  `InboundEmailMessage::openSealedAttachment()`.
- `fil_content_sealed` (on the File row) — **self-sealed shape**: the File's
  on-disk bytes are a `SealedFileContainer` under the File's *own* DEK
  (`fil_sealed_key`, wrapped to the owner's vault), opened via
  `DriveSealed::fileKey()` + `SealedFileContainer` open.

A File written by this spec has `fil_content_sealed = true` and its manifest row
has `ima_is_sealed = false`. The two flags are never both true on the same
attachment. (`File::is_sealed()` returns "protection level is private", which
happens to align, but dispatch in code must key on `fil_content_sealed` — the
column that literally means "the bytes are a container".)

### When to seal: follow the message, not the mailbox

The upgrade seals the new File **iff the owning message is itself sealed**
(`iem_content_sealed = true`), and seals it to that row's own recorded owner,
`iem_sealed_owner_user_id` — the same owner-from-the-row principle
`openSealedAttachment()` already uses. A plaintext message gets a plaintext
File, even on a mailbox whose current policy would seal new mail.

This is deliberate and load-bearing. The digest
(`plugins/mailbox/includes/EmailAttachmentDigest.php`) and other code rely on
the invariant *"an attachment is sealed only when its message is"* to know that
skipping sealed messages means never meeting sealed bytes. Sealing an
attachment onto a plaintext message would break that invariant for zero real
protection (the message body is plaintext anyway). Do not read the mailbox's
current protection setting anywhere in this feature.

If the sealed owner's vault cannot be loaded (`requireVault()` throws), skip
that row — leave it reference-backed, count it as skipped. Never fall back to
plaintext for a sealed message's attachment.

### Writing the File

Both shapes keep the receive path's visibility columns. The `fil_source` tag is
**`File::SOURCE_EMAIL_ATTACHMENT`**, never `'drive'` — this is a hard
requirement, not a style choice:

- Drive's listings, search index, and size trigger are all scoped to
  `fil_source = 'drive'` (`data/files_class.php`). Tagging attachments `'drive'`
  would leak them into the user's Drive UI and quota.
- The File decrypt/streaming hooks resolve **by `fil_source`**
  (`File::serve_from_path()`), so the source tag decides which decryptor serves
  the bytes. `'email_attachment'` routes to the mailbox's hooks (see Reading).

**Plaintext message** — exactly the receive path's shape
(`InboundEmailRouter::extractAttachmentsToFiles()`):

```php
$file = File::createFromBytes($bytes, $name ?: 'attachment', $type, $owner_id,
    array('fil_private' => true, 'fil_source' => File::SOURCE_EMAIL_ATTACHMENT));
```

**Sealed message** — the standard self-sealed shape via the existing helper:

```php
// createSealedFile() takes a PATH. Spool the decoded bytes to a temp file
// (0600), hand it over, unlink in a finally. It is NOT consumed by the call.
$file = DriveSealed::createSealedFile($tmp_path, $name ?: 'attachment', $type,
    $seal_owner_id,   // iem_sealed_owner_user_id — whose vault must open it
    array('fil_private' => true, 'fil_source' => File::SOURCE_EMAIL_ATTACHMENT));
```

`createSealedFile()`'s own `array_merge` keeps `fil_private`/`fil_source` from
the restrictions and enforces `fil_protection_level = 'private'`,
`fil_plain_size_bytes`, and the sniffed `fil_type` itself. It also renders a
sealed thumbnail variant — new behavior for mail attachments and acceptable;
do not suppress it.

Note: `DriveSealed::fileKey()` marks the request hot for the sealed-egress
guard (`SealedEgressGuard::markHot`) when the File is later *read*. That is the
same guard posture protected mail already lives under; nothing extra to do.

### Updating the manifest row

After the File exists, one targeted prepared UPDATE per row (never
`$att->save()` of a stale object, and never touching the message row):

- `ima_fil_file_id` → the new File's id
- `ima_size_bytes` → `strlen($decoded_bytes)` — for a **file-backed** row this
  column records the plain decoded size (see
  `InboundEmailRouter::storeDirectAttachments()`); the old value was the IMAP
  BODYSTRUCTURE's transfer-encoded size and would now be wrong
- `ima_encoding` → **left alone.** (Corrected during implementation: the draft
  said `'binary'`, reasoning from `storeDirectAttachments()`. But the path this
  is converging with is the MIME split,
  `InboundEmailRouter::extractAttachmentsToFiles()`, which records the *source
  part's* transfer encoding — `'base64'` — on a file-backed row. The IMAP ingest
  already wrote that same value from BODYSTRUCTURE, so not touching the column is
  what makes the two orders converge. Writing `'binary'` would have made them
  differ in exactly the column the convergence test compares. Nothing reads
  `ima_encoding`, so this is about consistency, not behaviour.)
- `ima_is_sealed` stays `false` (the File carries its own sealed state)

Identity columns (`ima_filename`, `ima_content_type`, `ima_mime_part`,
`ima_content_id`, `ima_is_inline`) are never changed. Nothing else on the
message is touched: no duplicate row, no changed `iem_received_time`, no
re-threading, no disturbed read/starred/label state, no import-run tag (a
deduped message is not mail this run created, so UNDO must still not remove
it — see the tagging comment in `MailArchiveImporter`).

Per-row ordering is File first, row second: a crash between the two leaves an
orphaned File (invisible, harmless, cheap) — never a manifest row pointing at
bytes that don't exist. On a row-update failure, `permanent_delete()` the File
just created, mirroring the receive path's rollback.

### Matching archive parts to manifest rows — skip, don't guess

The manifest rows were written from IMAP BODYSTRUCTURE; the archive copy is
parsed by Horde. Their part numbering *usually* agrees (both follow MIME
section numbering) but this must not be assumed. Attaching the **wrong** bytes
to a row is strictly worse than leaving it reference-backed.

Extraction reuses the router's own part enumeration so "attachment" means the
same thing on both paths: make
`InboundEmailRouter::enumerateNonTextParts(string $raw_email)` public (it is
currently private; the importer already holds a router via `$this->router()`).
Each part offers `getContents()`, `getName()`, `getType()`, `getContentId()`,
`getDisposition()`, `getMimeId()`.

Match each reference-backed manifest row against the enumerated parts, first
rule that applies wins, and **every rule requires the match to be unique on
both sides** (exactly one row candidate and exactly one part candidate):

1. **Content-ID.** Both non-empty after trimming angle brackets
   (`ima_content_id` is stored trimmed), equal.
2. **MIME section + type.** `ima_mime_part` equals the part's `getMimeId()`
   **and** `ima_content_type` equals the part's type (case-insensitive).
3. **Filename + type.** Both filenames non-empty and equal, and types equal
   (case-insensitive).

A row that matches nothing, or whose best rule matches ambiguously, is
**skipped and counted** — one `error_log` line per message naming the message
id and how many rows could not be matched. No fuzzy matching, no size-based
matching (`ima_size_bytes` on these rows is an encoded size and cannot equal a
decoded length), no "closest candidate".

### Where it fires

The machinery lives in a shared helper —
`AttachmentByteCustody::adopt(int $message_id, string $raw, InboundEmailRouter $router): int`
(`plugins/mailbox/includes/AttachmentByteCustody.php`) — because more than one
path can find itself holding a message's real bytes while the stored copy is a
reference. Two call sites:

1. **Archive import.** `MailArchiveImporter::importEntry()`, on the
   `existingMessageId()` hit (the early-return `dedup` branch), before
   recording the outcome:

```php
$existing = self::existingMessageId($messageId, intval($alias->key), $direction);
if ($existing !== null) {
    $adopted = AttachmentByteCustody::adopt($existing, $raw, $this->router());
    MailImportEntry::recordOutcome(intval($entry->key), MailImportEntry::STATE_DEDUP,
        $adopted > 0
            ? 'Already in this mailbox — attachment bytes taken.'
            : 'Already in this mailbox.',
        $existing);
    return 'dedup';
}
```

2. **Live delivery.** `InboundEmailRouter::storeMessage()`'s dedup return: a
   Postfix/webhook delivery (or an archive import deduping mailbox-agnostically)
   that collides with an already-stored row looks the duplicate's id up
   (`duplicateMessageId()`) and adopts before reporting `dedup`. This is the
   combined-alias case — one address delivered over SMTP while also fed over
   IMAP — where the delivery holds the raw the IMAP row lacks.

3. **Joinery Direct.** `storeDirectMessage()`'s dedup return adopts through
   `adoptParts()`: Direct never assembles a raw MIME document — it delivers
   each attachment as an already-decoded part — so each part is wrapped
   (`DeliveredAttachmentPart`) to answer the same questions a parsed MIME part
   does. A Direct part carries no MIME section number, so only the Content-ID
   and filename+type matching rules can claim one.

The IMAP path (`storeExtracted`) holds no bytes at all, and the relay pending
store holds only a blob sealed to the owner's vault, so neither has anything
readable to adopt from.

`adopt()` / `adoptParts()` return the number of rows upgraded and:

1. Loads the message. If `iem_delete_time` is set → return 0.
   (`existingMessageId()` counts deleted rows on purpose, so a re-import cannot
   resurrect thrown-away mail; the upgrade must not resurrect one either.)
2. If `iem_raw_storage_driver !== 'remote'` → return 0 (bytes already local —
   see The rule).
3. Loads reference-backed rows:
   `new MultiInboundMessageAttachment(['message_id' => $message_id, 'file_backed' => false])`.
   None → return 0.
4. Enumerates parts from `$raw`, matches per the rules above.
5. Per matched row, inside its own try/catch (one bad part must not cost the
   rest): stream the part's decoded bytes to a private temp file (never a
   whole-attachment PHP string — a large attachment materialized whole can
   breach `memory_limit` and kill the run), create the File per the message's
   sealed state, update the row, count it. A throw logs, cleans up its File if
   the row update failed, and moves on.

The upgrade must never throw out of `adopt()` — a total failure still records
the dedup outcome exactly as it would have been. Adoption is a bonus on top of
dedup, not a condition of it.

The upgrade is **idempotent and additive**: a manifest row that already has a
File is excluded by the query, so re-running an import over the same archive is
a no-op and a crash mid-batch costs at most a batch.

## Reading — one open path, five call sites

This is where the original design was dangerously underspecified. The byte-open
logic is **not** one function today; the pattern
`$file->read_bytes('original')` + `openSealedAttachment()` appears in five
places, and `openSealedAttachment()` keys on `ima_is_sealed` — which is `false`
for the new shape. Left alone, every one of these would return **sealed
container bytes as a success**: a forwarded mail carrying ciphertext, a 200
response full of garbage. No error, no 423.

### The fix: extend the single opener that already exists

`InboundEmailMessage::openSealedAttachment()` documents itself as "the one
implementation … all call". Keep that true by teaching **it** the new shape,
so no caller can be missed:

```php
public static function openSealedAttachment(InboundEmailMessage $msg,
        InboundMessageAttachment $att, string $bytes, ?File $file = null): string {

    // Self-sealed File (this spec's shape): the bytes are a SealedFileContainer
    // under the File's own DEK. Resolve the File from the row when the caller
    // didn't pass it, so an un-updated caller still gets plaintext, never a
    // container.
    $fil_id = intval($att->get('ima_fil_file_id'));
    if ($fil_id > 0) {
        if ($file === null || intval($file->key) !== $fil_id) {
            $file = new File($fil_id, TRUE);
        }
        if ($file->key && $file->get('fil_content_sealed')) {
            $fk = DriveSealed::fileKey($file);            // throws VaultLockedException when closed
            return SealedFileContainer::openBytes($bytes, $fk);
        }
    }

    if (!$att->get('ima_is_sealed')) {
        return $bytes; // stored plaintext — nothing to open
    }
    // ... existing message-DEK body, unchanged ...
}
```

Notes for the executor:

- `SealedFileContainer::openBytes($cipher, $fk)` opens a whole container from
  bytes already in memory — no filesystem path needed, so it works whatever
  storage driver holds the File. Do not use `openString()` (path-based) here.
- `DriveSealed::fileKey()` throws `VaultLockedException` when the owner's
  window is closed — the exact exception every existing caller already
  handles. No caller's error handling changes.
- The container branch runs **before** the `ima_is_sealed` early-return so the
  dispatch order is: self-sealed File → legacy message-DEK → plaintext.

Then update the callers to pass the `File` they already hold (saving the
re-load), verifying each of the five sites:

1. `plugins/mailbox/includes/attachment_retrieval.php` —
   `mailbox_retrieve_attachment_bytes()`, file-backed branch.
2. `plugins/mailbox/includes/MailboxSender.php` — the forward/re-attach read
   (`readOriginalPartBytes`).
3. `plugins/mailbox/includes/InboundEmailRouter.php` — the message-synthesis
   attachment loop (~line 1984).
4. `plugins/mailbox/includes/EmailAttachmentDigest.php` — the defensive open.
   With seal-follows-message (above) the digest's sealed-message exclusion
   still means it never meets sealed bytes; this stays a defensive catch.
5. `plugins/mailbox/includes/bootstrap.php` — the whole-bytes decrypt hook for
   `File::SOURCE_EMAIL_ATTACHMENT` (it receives the `File`; pass it through).

### Serving over HTTP: register the streaming hook

`File::serve_from_path()` resolves decryptors **by `fil_source`**. Today
`'email_attachment'` has only the whole-bytes hook. Register a streaming hook
beside it in `plugins/mailbox/includes/bootstrap.php`, mirroring the messenger
plugin's registration (`plugins/messenger/includes/bootstrap.php`) exactly:

```php
File::registerStreamingDecryptHook(File::SOURCE_EMAIL_ATTACHMENT,
    function (File $file, $size_key = null) {
        // Self-sealed container (attachment byte custody): stream it. Anything
        // else — legacy message-DEK file or plaintext — returns null and falls
        // through to the whole-bytes hook below.
        return $file->get('fil_content_sealed') ? new DriveSealedStream($file, $size_key) : null;
    });
```

`serve_from_path()` already implements the fallthrough: a streaming opener
returning `null` drops to the whole-bytes hook, so legacy sealed files and
plaintext files serve exactly as today. `DriveSealedStream` is core
(`includes/DriveSealed.php`) and source-agnostic; its `prepare()` turns a
closed window into a 423 before headers, never ciphertext with a 200.

### Locked reads

`VaultLockedException` must keep surfacing as it does now — `locked => true`
from `mailbox_retrieve_attachment_bytes()` so the reader can run the one-tap
unlock and retry; 423 from the serve path; a clean forward/digest skip. Never a
raw error and never ciphertext. Both sealed shapes open in the same unlock
window against the same vault, so there is no user-visible difference and no
second ceremony.

## Edge cases

- **Message with no `Message-ID`.** The importer synthesises a stable id from
  the bytes; the IMAP path stores an empty one. They cannot match, so such a
  message can still land twice. Out of scope here — noted so it is not mistaken
  for a regression of this work. Rare (verified: 1 of 2,892 rows on dev).
- **Deleted messages.** `existingMessageId()` deliberately counts deleted rows
  so a re-import cannot resurrect thrown-away mail. An upgrade must not
  resurrect one either: a soft-deleted message is skipped, not upgraded (step 1
  of `AttachmentByteCustody::adopt()`).
- **Section-pointer messages.** A message whose raw is stored locally already
  has its bytes; the driver gate (step 2) excludes it. Only `'remote'` messages
  upgrade.
- **Partial manifests.** A message may have some attachments file-backed and
  some not. The upgrade is per manifest row, not per message; inline
  (`ima_is_inline`) rows upgrade like any other.
- **Unmatchable parts.** Skipped and counted, never guessed (see Matching).
  A skipped row keeps working exactly as today via the IMAP fallback.
- **Sealed message, missing vault.** `requireVault()` refusal skips the row;
  never store a sealed message's attachment plaintext.
- **Vault locked during import.** Irrelevant by construction — sealing needs
  only the public key. This must be asserted by a test, not assumed.
- **No vault / Standard tier / plaintext message.** Plaintext bytes,
  `ima_is_sealed = false`, `fil_content_sealed = false` — the receive path's
  exact shape.
- **Size accounting.** The File's blob is charged at ciphertext size and
  `fil_plain_size_bytes` records the plain size (`createSealedFile()` handles
  both); `ima_size_bytes` becomes the decoded size, which is what "size" means
  on every file-backed row.

## Tests

All in `plugins/mailbox/tests/`, harness + `@joinery-test` header per
`docs/testing.md`.

1. **Both orders converge.** IMAP-ingested reference-backed message, then
   import the same message from an archive → one message row, attachment now
   file-backed. Archive first, then IMAP → one message row, bytes still local,
   locator adopted. The two end states must be equivalent.
2. **Sealed upgrade with the vault locked** — a sealed mailbox, no unlock
   window, import runs, bytes land as a `fil_content_sealed` File wrapped to
   `iem_sealed_owner_user_id`'s vault, `ima_is_sealed` stays false. This is the
   load-bearing check: it is the claim the whole design rests on.
3. **Round trip** — the upgraded attachment opens inside an unlock window and
   returns the original decoded bytes, through
   `mailbox_retrieve_attachment_bytes()`.
4. **Every reader understands the new shape** — open the upgraded attachment
   through `openSealedAttachment()` directly (with and without passing the
   `File`), and through the forward path
   (`MailboxSender`) in-window: original bytes, never container bytes. This is
   the test for the silent-ciphertext bug class.
5. **Legacy shape still opens** — an attachment sealed under a message DEK
   (`ima_is_sealed = true`) continues to open unchanged, and still serves over
   HTTP via the whole-bytes hook (streaming opener returns null for it).
6. **Locked read** — a sealed upgraded attachment read with the vault closed
   answers `locked => true` from the retrieval helper, never ciphertext.
7. **Rotation** — rotating the vault re-wraps the upgraded File's key (the
   existing Drive sweep, no source filter), and it still opens afterwards.
8. **Idempotence** — re-importing the same archive upgrades nothing a second
   time and tags nothing, so UNDO still cannot remove mail the run did not
   create.
9. **Soft-deleted message is not upgraded** (and not resurrected).
10. **Section-pointer message is not upgraded** (driver gate).
11. **Plaintext message on a mailbox with a seal owner** → plaintext File; the
    sealed-attachment-implies-sealed-message invariant holds.
12. **Ambiguous match is skipped** — two same-name/same-type parts with no
    Content-IDs: no upgrade, rows stay reference-backed, dedup outcome still
    recorded.
13. **Unsealed mailbox** — plaintext bytes stored and readable.

## Documentation to update when this lands

Docs describe current state only, so these change at implementation, not now:

- `plugins/mailbox/docs/overview.md` — attachment storage: the manifest is
  reference-backed until real bytes arrive, any path holding them takes them,
  and the two sealed File shapes with their dispatch.
- `docs/sealed_vault.md` — mail attachment adoption seals per file, like every
  other consumer.
- `specs/implemented/inbound_email_attachment_storage.md` is historical and
  must not be edited.

## Out of scope

- Changing how attachments are sealed on the **normal** receive path. New mail
  may adopt the per-file shape later, retiring the borrowing entirely; this
  spec only requires that the reader understands both.
- Backfilling bytes for mail already stored as references. Once the mechanism
  exists a sweep is possible, but it means fetching from the source mailbox and
  is its own decision.
- Deduplicating messages that have no `Message-ID`.
- Upgrading the message **body/raw** custody on dedup (adopting the archive's
  raw for a `'remote'` message). Sealing an adopted raw would need the message
  DEK — the exact cron blocker this spec routes around — so it is not a free
  addition; it would need its own design.
