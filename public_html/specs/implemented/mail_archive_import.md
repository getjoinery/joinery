# Mail archive import

**Status:** Implemented. Built and verified 2026-07-28 — safe-tier reader suite
(`mail_archive_readers_test.php`) and db-tier end-to-end suite
(`mail_archive_import_test.php`), plus a live upload-scan-choose-import run on dev
through the admin surface.

The two items in § 14 remain open: both need measuring against a real
hundred-thousand-message archive, which no test fixture can stand in for.

Bring an existing mailbox into Joinery from a file the user already has: a Proton
export, a Gmail Takeout, a folder of saved messages, an mbox from Thunderbird.
The counterpart to the IMAP feed, which pulls from a *live* account; this reads a
*dead* archive. Between them there is a way in from any provider.

The unit of work is one **import run**: pick a source file, say which mailbox it
goes into, choose what to bring, and let it grind. Runs are resumable, reportable,
and reversible.

---

## 1. Decisions

Settled up front so the build does not relitigate them:

| Decision | Choice |
|---|---|
| Transport | Upload an archive, **or** pick a file already in the user's Drive. No server-path option — too advanced for the flow. |
| Formats | Maximum compatibility: `.eml`, `.emlx`, mbox, maildir, loose folders, and any of those inside `.zip` / `.tar` / `.tar.gz`. |
| Outlook `.pst` / `.olm` | Detected and **refused** with a redirect to connecting the account over IMAP. No external binary — that would break zero-config install. |
| Identity | The user declares **which addresses were theirs**. Drives sent-vs-received and the recorded delivery address. |
| Organisation | Source folders and labels become labels; read / starred / archived carry over. **Spam and Trash excluded by default**, shown with counts and tickable. |
| Placement | **Members** import into a mailbox they hold a grant on; **admins** can import into any mailbox. Shared logic, two entry points. |
| Undo | Every imported row is **tagged with its run**, so a whole import can be reversed in one action. |

### Deliberately not doing

- **Inbound filters do not run on imported mail.** Same reasoning as IMAP feeds:
  the archive already reflects whatever filtering the source applied, and running
  years-old mail through live filters would fire forwards and notifications for
  messages nobody just received. Requires a change to `storeMessage()` — §6.
- **No `.tar.bz2`.** The `bz2` extension is not present. `.zip` and `.tar.gz`
  cover every export tool in scope; revisit only if a real archive needs it.
- **No client-side decryption of encrypted Drive files.** Drive encryption is
  per-folder and inherited, and an encrypted file's plaintext exists only in the
  browser. The picker refuses them with that reason — §5.2.

---

## 2. Why the store path is nearly free

The expensive half already exists. Live delivery does:

```php
$parsed = $router->parseEmail($raw);
$router->storeMessage($raw, $parsed, $alias, $domain, $recipient, $auth);
```

`storeMessage()` already handles bodies, attachment extraction into `File` records,
thread keys, spam verdict, raw storage, and dedup. `RelaySpoolConsumer` does exactly
this against raw messages on disk today, so an importer is the same shape pointed at
a different source of bytes.

Two properties of the existing schema carry most of the design:

**Dedup is free and correct.** The unique key is
`(iem_message_id_header, iem_recipient, iem_direction)`. Re-running an import over
the same archive stores nothing new. Resume after a crash, retry a failed batch, and
"did I already import this?" all fall out of that constraint rather than needing a
cursor to be right. A unique violation is already treated as a successful dedup.

**Mailbox filing and delivery address are independent.** Mail is scoped to a mailbox
by `iem_iea_inbound_email_alias_id`; `iem_recipient` is a separate string. So a
message can record the address it was genuinely delivered to *and* be filed in
whichever mailbox the user picked. Honest history without breaking filing.

**Sealing needs no unlock.** Ingest seals content to the owner's vault *public* key
and explicitly runs with no unlock window, so a background import into a Private or
Fortress mailbox encrypts correctly with nobody logged in. No special handling.

---

## 3. Data model

### 3.1 `mir_mail_import_runs` — one row per import

`MailImportRun extends SystemBase`, prefix `mir`.

| Column | Type | Notes |
|---|---|---|
| `mir_mail_import_run_id` | int8 serial | |
| `mir_iea_inbound_email_alias_id` | int8, not null | target mailbox |
| `mir_usr_user_id` | int4, not null | who started it |
| `mir_fil_file_id` | int8, not null | the source archive |
| `mir_source_name` | varchar(500) | original filename, for display |
| `mir_format` | varchar(40) | reader key that claimed it — `mbox`, `eml_dir`, `zip`, … |
| `mir_state` | varchar(20), not null | see state machine below |
| `mir_own_addresses` | text | newline-separated, as declared by the user |
| `mir_selection` | jsonb | which folders/labels the user ticked |
| `mir_total_entries` | int4, default 0 | filled by the scan |
| `mir_processed` | int4, default 0 | entries the import has finished with |
| `mir_stored` | int4, default 0 | |
| `mir_dedup` | int4, default 0 | |
| `mir_failed` | int4, default 0 | |
| `mir_bytes_total` | int8 | for the progress bar |
| `mir_error` | text | why a `failed` run failed |
| `mir_create_time` / `mir_start_time` / `mir_finish_time` | timestamp(6) | |
| `mir_delete_time` | timestamp(6) | |

**State machine.** `queued` → `scanning` → `scanned` (waiting on the user's
selection) → `importing` → `done`. Plus `failed` from any state, and `undone` after
a reversal. Nothing advances except through the task in §7.

### 3.2 `mie_mail_import_entries` — one row per message found

`MailImportEntry extends SystemBase`, prefix `mie`. This table **is** the index the
scan leaves behind, and it is what makes "any size" work: the import never re-parses
the archive to find out what is in it.

| Column | Type | Notes |
|---|---|---|
| `mie_mail_import_entry_id` | int8 serial | |
| `mie_mir_mail_import_run_id` | int8, not null | |
| `mie_locator` | varchar(1000), not null | how to find the bytes again — path inside the container, or `offset:length` for mbox |
| `mie_ordinal` | int4 | position in the archive, for stable ordering |
| `mie_source_folder` | varchar(255) | folder or label the source filed it under |
| `mie_labels` | text | additional labels, newline-separated |
| `mie_direction` | varchar(10) | `inbound` / `outbound`, decided at scan |
| `mie_class` | varchar(20) | `normal` / `spam` / `trash` — drives the default exclusions |
| `mie_is_read` / `mie_is_starred` | bool | |
| `mie_state` | varchar(20), not null | `pending` / `stored` / `dedup` / `skipped` / `failed` |
| `mie_reason` | text | why it failed or was skipped |
| `mie_iem_inbound_email_message_id` | int8 | the row it created |

Indexed on `(mie_mir_mail_import_run_id, mie_state)` — the import's work query.

A 500,000-message archive means 500,000 narrow rows. That is unremarkable for
Postgres and buys exact preview counts, per-entry failure reasons, free
resumability, and per-entry retry.

### 3.3 One new column on `iem_inbound_email_messages`

`iem_mir_mail_import_run_id` (int8, nullable, indexed) — the run that created this
row. Null for everything that arrived normally. This is the whole undo mechanism.

Deduped rows are **not** tagged: they already existed, so an undo must not remove
them. That falls out naturally — the tag is only written on a fresh insert.

---

## 4. Reading any archive

### 4.1 The reader registry

Format support is a registry, not a chain of `if`s, so adding a format later is one
new class and one line:

```php
interface MailArchiveReader {
    public static function key(): string;
    public static function sniff(string $path, string $filename): bool;
    public function scan(string $path, callable $emit): void;  // emit one locator per message
    public function read(string $path, string $locator): string; // raw RFC822
}
```

`MailArchiveReaderRegistry::detect($path, $filename)` asks each reader to sniff, in
priority order, and returns the first that claims it.

### 4.2 The readers

| Reader | Handles | How |
|---|---|---|
| `MboxReader` | Gmail Takeout, Thunderbird, Apple Mail `mbox` | Splits on `From ` at line start with the standard `>From` unescaping. Emits `offset:length` — never loads the file. |
| `EmlDirectoryReader` | Proton export, maildir, Apple Mail `Messages/`, any folder of saved mail | Recursive walk. Takes `.eml`, `.emlx`, `.msg`-less extensionless maildir files. Directory names become the source folder. |
| `EmlFileReader` | a single saved message | Degenerate case of the above. |
| `ZipReader` | `.zip` around any of the above | Reads entries **in place** through `zip://` — a 50GB zip is never extracted, which would double the disk requirement. |
| `TarReader` | `.tar`, `.tar.gz` | `PharData`. Gzipped tar is sequential-access only, so it is expanded once into a working area and the run holds that area until it finishes. |
| `OutlookReader` | `.pst`, `.olm` | Sniffs the magic bytes and **refuses**, with the IMAP redirect. Exists so the refusal is specific rather than "unrecognised file". |

**`.emlx`** (Apple Mail) is a length line, then RFC822, then a plist trailer. Strip
the first line and everything past the declared byte count.

**Nested containers** are not followed. A zip inside a zip is reported, not
recursed — that way lies a zip bomb.

### 4.3 Provider metadata

Read when present, ignored when not, so a bare folder still imports correctly:

- **Proton** — a `<id>.metadata.json` beside `<id>.eml` carries labels and flags.
- **Gmail** — the `X-Gmail-Labels` header inside the mbox carries labels plus the
  `Inbox` / `Sent` / `Spam` / `Trash` / `Starred` / `Unread` pseudo-labels.
- **Maildir** — the `:2,` filename suffix flags (`S` seen, `F` flagged, `T` trashed).

### 4.4 Messages with no Message-ID

Real archives contain them, and without a Message-ID the dedup key collapses and a
re-run duplicates everything. The importer synthesizes a stable one:

```
<sha256(raw bytes)@import.invalid>
```

Deterministic, so re-importing the same archive still dedups; `.invalid` by RFC 2606
so it can never collide with a real domain.

---

## 5. The import flow

### 5.1 Choose a source

Two ways in, both resolving to a `File` the server can read:

- **Upload an archive** — the normal path, through the existing upload handling.
- **Pick a file already uploaded** — a Drive picker.

### 5.2 Encrypted Drive files are refused

Drive encryption is per-folder and inherited; a file in an encrypted folder is
decryptable only in the browser. The picker shows those files greyed with the reason
rather than hiding them — a user who cannot find their archive is worse off than one
told why it cannot be used. Suggested remedy in the message: move a copy to an
unencrypted folder.

### 5.3 Declare your addresses

Pre-filled with the addresses already on the account. The user edits or adds. Stored
on the run.

Used for:
- **Direction** — `From` matches one of them → outbound. Metadata wins when present.
- **Delivery address** — the first of `Delivered-To`, `X-Original-To`, `Envelope-To`,
  then `To`/`Cc`, that matches a declared address. Falls back to the target mailbox
  address when nothing matches, which is the Bcc case.

For outbound mail the recipient recorded is the first `To`, matching how sent mail is
stored today.

### 5.4 Scan, then choose

The scan walks the archive once and writes one `mie_` row per message. It writes no
mail. On completion the run goes `scanned` and the user sees counts by folder, with
Spam and Trash unticked:

```
Found in archive.mbox

  [x] Inbox                     8,412
  [x] Sent                      2,105
  [x] Archived                 21,660
  [x] Custom labels (14)        3,297
  [ ] Spam                     38,904
  [ ] Trash                     1,240

  Importing 35,474 of 75,618 messages
```

Confirming writes the selection to `mir_selection`, marks unselected entries
`skipped`, and moves the run to `importing`.

### 5.5 Import

Batches of entries, oldest first. Per entry: read the raw at its locator, parse,
resolve identity, `storeMessage()` with filters suppressed, apply labels and state,
record the outcome on the entry.

---

## 6. Required change to `storeMessage()`

It currently runs inbound filters unconditionally. Add a final options argument:

```php
public function storeMessage($raw_email, $parsed, $alias, $domain,
                             $envelope_recipient, $auth = null,
                             $content_spam = null, array $options = array())
```

Honouring `$options['run_filters']` (default `true`, so every existing caller is
unchanged) and `$options['import_run_id']` (stamped onto the row for undo).

The importer passes `run_filters => false`. Documented alongside the existing note
that filters never run for IMAP-polled mail — imported mail is the second exemption
and for the same reason.

---

## 7. Any size: the task, batching, and resume

Both phases run in `RunMailImports`, a scheduled task, because a 50GB scan cannot
happen inside a web request.

Each pass:

1. Claim one run in `scanning` or `importing` with an atomic conditional update, the
   same overlap guard `PollImapAccounts` uses, so two cron passes never race a run.
2. Do **one bounded batch** — `mailbox_import_batch_size` entries, default 200.
3. Update the run's counters and return.

Progress is `mir_processed` against `mir_total_entries`. Resume is implicit: the work
query is `mie_state = 'pending'`, so a crash mid-batch costs at most the batch, and
even that re-runs safely because of the dedup key.

**Concurrency** is capped by `mailbox_import_max_concurrent` (default 2) so one
enthusiastic user cannot starve the mail stack.

**The source file is retained** for the life of the run. An uploaded archive is
deleted when the run reaches `done` and the user dismisses it; a Drive-picked file is
never touched, since it is the user's own file.

---

## 8. Failure handling

Per-entry failures are recorded on the entry with a reason and never abort the run —
one unreadable message must not cost the other 35,000.

Reuse the run-record pattern built for IMAP ingest:

- A row in `evl_event_logs` under event **`mail_archive_import`** per batch that did
  something, with counts and failure reasons rolled up by reason.
- The same summary to the error log, per-entry detail bounded.
- Reasons rolled up for display, so 400 messages failing identically read as one
  line.

The reconciliation tripwire applies here too: `stored + dedup + failed + skipped`
must equal the entries processed. A shortfall marks the run unsuccessful and names
itself, because a message that vanishes without a reason is the failure a counter
alone hides.

---

## 9. Undo

Available on any `done` run. Permanently deletes every `iem_` row carrying that run
id, through the existing deletion cascade so attachment `File` rows, label
memberships, and search index entries go with them.

Undo also removes labels the import created that are now empty. Labels that existed
beforehand, or that hold mail from elsewhere, are left alone.

The run goes to `undone` and keeps its entries, so the report of what happened
survives the reversal.

Mail that deduped against something already present was never tagged, so it is
untouched — as is anything that arrived after the import.

---

## 10. Surfaces

**Member** — mailbox settings gains an *Import old mail* section: the button, and a
list of past runs with live progress.

**Admin** — the same tool in the Accounts tree beside IMAP feeds, with a mailbox
picker covering every mailbox rather than just the user's own.

Both call the same logic. Per the API rule, the logic exposes its actions through
`_logic_descriptor()` and the page JS calls `/api/v1` with the browser-session
credential — progress polling included. No new `/ajax/` endpoints.

**Permissions.** A member must hold a live grant on the target alias; permission ≥ 5
may target any mailbox. Checked in logic, not in the view.

---

## 11. Settings

Declared in `plugin.json`, seeded automatically:

| Setting | Default | Purpose |
|---|---|---|
| `mailbox_import_enabled` | `true` | Master switch for the member-facing surface. |
| `mailbox_import_batch_size` | `200` | Entries per task pass. |
| `mailbox_import_max_concurrent` | `2` | Runs importing at once, deployment-wide. |

---

## 12. Testing

**Fixtures** under `plugins/mailbox/tests/fixtures/import/` — small but real: an
mbox with `From ` escaping and `X-Gmail-Labels`; a Proton-shaped folder with
`.metadata.json` sidecars; a maildir with flag suffixes; an `.emlx`; a zip
containing `.eml` files; a truncated archive; a message with no Message-ID; a fake
`.pst` header.

**Tier `safe`** — the readers and identity resolution are pure. Sniffing picks the
right reader; mbox splitting handles escaping and the final message; `.emlx` framing
is stripped; direction and delivery address resolve correctly from declared
addresses; a missing Message-ID synthesizes stably (same bytes twice → same id).

**Tier `db`** — a full run end to end on a fixture archive: scan writes the expected
entry counts and classes, selection skips Spam and Trash, import stores and files
into the right mailbox with labels and read state, a second run of the same archive
dedups to zero new rows, undo removes exactly what the run created and nothing else,
and a deliberately corrupt entry fails without stopping the batch.

Explicitly asserted: **filters do not run** on imported mail.

---

## 13. Documentation

`plugins/mailbox/docs/overview.md` gains an **Importing an existing archive**
section after *Receiving by IMAP poll*, covering the supported formats, the two
sources, the scan-then-choose flow, what the identity step is for, the Spam/Trash
default, undo, and the filter exemption. Written as the current state, with no
reference to this having been added.

The filter exemption also gets a line where the existing "filters never run for
IMAP-polled mail" note lives, so both exemptions are stated in one place.

---

## 14. Open items

- **Search indexing at scale.** The mailbox search index is incremental and
  user-scoped. A 500,000-message import will make it do a great deal of work; whether
  it keeps up inside the same batches or needs its own throttled catch-up pass should
  be measured on a real archive before deciding.
- **Attachment storage growth.** Imported mail is stored whole, not
  reference-backed like IMAP, so attachments land on platform storage. A large Gmail
  archive is a real disk commitment, and the preview should probably show an
  estimated size alongside the message counts. Needs a number from a real import
  before the UI promises one.
