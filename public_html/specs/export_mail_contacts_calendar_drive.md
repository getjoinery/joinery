# Export Mail, Contacts, Calendar and Drive

**Status:** Draft, 2026-10-22. Nothing is built. No questions are open.

Everything else from the first draft (member data download, closing an account, admin list exports, member import, backup download and restore from a file) is in `DEFERRED_data_portability.md`.

## The problem in plain terms

A member can bring their email, contacts and calendar into Joinery, but can't take any of them out. Drive files come out only one at a time.

- **Email:** there is no "download this mailbox". Even the single-message "Download .eml" refuses most messages, because most mail is kept without its original (B1). A Fortress mailbox has no way to get mail out at all: no .eml, and mail programs are refused.
- **Contacts:** a vCard or Google CSV can be uploaded, but nothing downloads.
- **Calendar:** an .ics can be uploaded once, but the member's own calendar can't be downloaded. Only public events have .ics files.
- **Drive:** a folder can't be downloaded in one go.

## What exists today

| Area | In | Out |
|---|---|---|
| Email | Archive upload: mbox, .eml, .emlx, Maildir, zip/tar of those; Gmail Takeout and Proton labels. Live IMAP pull (admin). IMAP APPEND from mail apps. | One message as .eml, only when an original is stored (`message_export.php`). Mail apps over IMAP (experimental; not Fortress). |
| Contacts | vCard or Google CSV, name and email only (`MailboxContacts::import`). | None. |
| Personal calendar | .ics upload, one time (`IcsImporter`). | None. Public events have per-event `.ics` and the `/events/calendar.ics` feed. |
| Drive | Multi-file upload; desktop sync client. | One file at a time (`File::mintSignedUrl`); sync client. |

## Principles

**P1. Standard formats only.**

| Data | Format |
|---|---|
| Mail | mbox (mboxrd), one file per folder |
| Contacts | vCard 3.0 and Google CSV |
| Calendar | iCalendar |
| Bundles and folders | zip |

**P2. Everything round-trips.** Every export reads back through our own import of the same kind. The tests prove it: export, import into an empty place, compare.

**P3. The encryption level decides where an export is built** (`docs/protection_levels.md`):

- **Standard:** the server builds it at any time.
- **Private:** the server builds it only while the owner's unlock window is open.
  - Long work runs as `VaultDeferredWork` slices driven by the browser's heartbeat, never from cron.
  - A finished file is sealed to the owner's vault the way a Private Drive file is, and decrypted as it streams down. No plaintext copy ever sits on disk.
- **Fortress:** the browser builds it. The server sends only ciphertext and never decrypts.

**P4. Big jobs run in the background.** A whole-mailbox export can be gigabytes, so it is a queued job, the same pattern as `RunMailImports`:

- one row per run, claimed atomically
- done in bounded batches
- recovered if a claim goes stale

When the file is ready, the member gets an email that links to the page, never to the file. The file is kept for `export_retention_days` (a new setting, default 7), then `RetentionSweep` deletes it.

**P5. Safety:**

- **Downloading a whole mailbox asks for a fresh second factor** (`SessionControl::step_up_outstanding`), the same as Google Takeout asks you to sign in again. A stolen session shouldn't be able to walk off with years of mail in one click. A Drive folder zip doesn't ask, because every file in it can already be downloaded one by one.
- **A CSV cell that could run as a formula is defused.** Any cell starting with `=`, `+`, `-`, `@`, tab or carriage return is prefixed with `'`, so a spreadsheet never runs it. This matters for contacts, whose names often come from the headers of incoming mail.

### What this spec does not do

- **No live sync.** CardDAV, CalDAV and subscribing to an outside calendar feed are out of scope. The calendar ones are planned in `external_scheduling_integrations.md`.
- **No personal calendar feed URL.** A feed that keeps updating would leak Private entries (`calendar_contacts_private.md`, `protection_levels_platform.md`). The export is a one-time download.
- **No new import formats.** Imports already cover what these exports produce.

## Work packages

### WP1. Mailbox export

**What the member gets.** On a mailbox they hold a grant on, **Download this mailbox**, or one folder. It produces a zip with one mbox file per folder, containing what that member can read. Admins get the same button for any mailbox on the admin mailbox page.

**Where each message's bytes come from,** in order:

1. The stored original.
2. A live IMAP fetch, for a reference-backed row.
3. A full rebuild from the stored bodies and attachments, for a lean record. This is the common case.

The rebuild is `MailAppRebuild`, which already produces a complete multipart message for mail apps. It moves into the mailbox plugin as `MessageRebuild`, and mail apps calls it from there, so mailbox export doesn't depend on the mail apps plugin. A rebuilt message carries `X-Joinery-Rebuilt: yes`, so nobody mistakes it for the original bytes.

**The single-message "Download .eml"** uses the same three steps, so most messages get a download where today they get a refusal (fixes B1).

**Labels** are written as an `X-Gmail-Labels` header. Our own importer already reads that header, so an export imports back with its labels (P2).

**By level:**

- **Standard:** queued job (P4).
- **Private:** `VaultDeferredWork` slices while unlocked; the output is sealed (P3).
- **Fortress:** built in the browser (Q2). The server sends only ciphertext.
  - **Building each message.** A new browser message builder (today there is only a parser, `mailbox_mime.js`) assembles each message from the decrypted header block, bodies and attachments. It produces the same structure as the server's `MessageRebuild`, so one test can compare the two. Every Fortress message is marked `X-Joinery-Rebuilt: yes`, since no original is kept.
  - **Packing.** The browser zip writer from WP4 packs the messages.
  - **Size.** A tab can't hold a large mailbox in memory; Fortress Drive downloads today are held whole as one blob. The export comes in parts of about 500 MB (`{mailbox}-part-01.zip`, …). Where the browser can write straight to a file (`showSaveFilePicker`, Chrome and Edge), it streams one file to disk instead.

### WP2. Contacts export

**What the member gets.** On `/profile/mailbox/contacts`, **Export** as vCard 3.0 (`.vcf`) or Google CSV.

- A contact is a name and an email (`imc_display_name`, `imc_address`), which is all that is stored.
- Contacts are sealed to server custody, so the export runs while the vault is unlocked. The list is small, so it is built in the request.
- The CSV applies the formula rule (P5).

**Fixes B2.** `MailboxContacts::parseCsv()` reads whole records, so a quoted newline (common in Google's "Notes" column) no longer breaks a row. Without this the CSV round trip (P2) fails.

### WP3. Personal calendar export

**What the member gets.** On `/profile/calendar`, under Actions, **Download my calendar (.ics)**: a one-time file, not a feed.

**What changes in the code:**

- `IcsHelper::buildVevent()` gains RRULE, EXDATE, TZID and all-day `DATE` values. Today it writes only start, end, summary, description, location, url and status, so recurrence would be lost.
- An entry imported with `cal_rrule_raw` writes its original rule back out.
- Any other entry writes the inverse of `IcsImporter::translateRecurrence()`, which is new.
- Exceptions come from `CalEntryException`.
- Private entries (`cal_title`, `cal_location`, `cal_link`, `cal_notes`) are decrypted in the request while unlocked (`CalendarSealed`). The calendar has no Fortress level.

**Scope.** The member's own entries only. Events and bookings already have their own `.ics`.

### WP4. Download a Drive folder as a zip

**What the member gets.** On a Drive folder, **Download as zip**.

**How it's built:**

- **Standard and Private:** the server streams the zip as it goes. Private files are decrypted one at a time through `DriveSealedStream`, so nothing is held at rest.
- **Fortress:** the browser builds the zip from files it decrypts itself (`drive-crypto.js`). It uses the same parts or straight-to-disk rule as WP1.

**The zip writer (Q3).** WP1 and WP4 share one small zip writer that writes the zip as it goes, one in PHP (`ZipStreamWriter`) and one in JS (`zip-writer.js`).

- **Why not PHP's zip extension.** The extension is on every install (`ZipArchive`, required by `install.sh` :3563, already used by `publish_upgrade.php`), but it only writes a finished file to disk. That fails two needs here:
  - Private content would sit decrypted on disk while the zip is built (P3).
  - A large Drive folder would need that much free disk, and the member would wait for the whole build before the download started.
- **It lays out the zip format only.** Compression comes from what is already built in: PHP's zlib (`deflate_init` / `deflate_add`, streaming raw deflate) on the server, and the browser's `CompressionStream('deflate-raw')`, which the mailbox already uses for gzip (`mailbox_fortress.js`).
- **Already-compressed files are stored as they are:** photos, video, audio, PDFs, Office files and archives.
- **CRC32** comes from `hash('crc32b')` on the server and a small table in JS.
- **ZIP64** handles sets over 4 GB.

**The sync client** remains the answer for whole copies of very large Drives.

## Bugs found while scoping

- **B1.** "Download .eml" refuses most messages. A lean record has no original, and the header-plus-plain-body reconstruction must not be served as .eml (`message_export.php`, `mailbox_resolve_original`). **Fixed by WP1:** a full rebuild.
- **B2.** `MailboxContacts::parseCsv()` splits lines before parsing, so a quoted newline in a Google CSV breaks the row. **Fixed by WP2.**

## Decided

- **Q1 (owner, 2026-10-21 and 2026-10-22).** This spec covers email, contacts, calendar and Drive. Everything else from the first draft is in `DEFERRED_data_portability.md`. Posts and pages, and exports for boards and mail filters, are not in either spec.
- **Q2 (owner, 2026-10-21).** Fortress mail export is built with WP1, in the browser. Fortress is the tier sold on "only you hold your data", so it can't be the one tier you can't leave with your mail.
- **Q3 (2026-10-22, owner can overrule).** A small zip writer of our own, using the compression built into PHP (zlib) and the browser (`CompressionStream`). PHP's `ZipArchive` is on every install but only writes a finished file to disk, which would leave Private content decrypted at rest and make large folders wait on free disk. A library would add two outside dependencies to save about two hundred lines.

## Open questions

None.

## Files

**New:**

- includes/ZipStreamWriter.php, assets/js/zip-writer.js
- plugins/mailbox/includes/MessageRebuild.php (moved from mail_apps)
- plugins/mailbox/tasks/RunMailExports.php
- plugins/mailbox/assets/mailbox_mime_build.js (the browser message builder for Fortress export)

**Changed:**

- plugins/mailbox/includes/message_export.php
- the mailbox reader menu and the admin mailbox page (**Download this mailbox**)
- plugins/mailbox/includes/MailboxContacts.php (export; whole-record CSV parsing)
- plugins/mailbox/views/profile/contacts.php
- plugins/mail_apps (calls `MessageRebuild`)
- includes/IcsHelper.php, includes/calendar/IcsImporter.php (inverse rule)
- views/profile/calendar.php, logic/calendar_logic.php
- views/drive.php, assets/js/drive.js
- settings.json (`export_retention_days`)

## Docs

Each part is documented in the existing doc it belongs to:

- `plugins/mailbox/docs/overview.md`: mailbox and contacts export.
- `docs/calendar.md`: calendar export.
- `docs/drive.md`: folder download.
- `docs/sealed_vault.md`: how exports follow each encryption level.

## Tests

- **Round trip (P2):**
  - mbox: export, import into an empty mailbox, then compare messages, folders and labels.
  - vCard and Google CSV, including a quoted newline.
  - .ics, including recurrence and exceptions.
  - Drive zip: a folder tree comes back with the same names and bytes.
- **Encryption levels:**
  - A Private export file is sealed at rest, with no plaintext under `storage/`.
  - A Private export won't run with the window closed.
  - The server never decrypts a Fortress message or file for export.
  - For the same message, the browser builder's output matches the server's `MessageRebuild` output.
  - A Fortress mailbox larger than one part comes out as several parts, and together they import back complete.
- **Safety:**
  - A whole-mailbox export asks for a fresh second factor.
  - A contacts CSV cell beginning with `=` is defused.
  - A member exports only what their grant can read.
