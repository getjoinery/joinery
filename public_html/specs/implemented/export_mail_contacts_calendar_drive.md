# Export Mail, Contacts, Calendar and Drive

**Status:** All four work packages built. WP2 (contacts) built 2026-10-22, with core `CsvWriter` and `CsvReader`, released in 0.8.520. WP3 (calendar) built and reviewed 2026-10-22, released in 0.8.521 and checked on getjoinery; the Google Calendar import is in the live verification queue. WP4 (Drive folder zip) built and reviewed 2026-10-22, released in 0.8.524, live walk on dev passed; the checks after release are in the live verification queue (getjoinery has Drive off). WP1 (mailbox) built 2026-10-23 on the owner's go-ahead, with B1 and B30, and reviewed by reviewer2 (READY; R1-R3 taken). Tests pass on dev (db --changed 718/718), and the Standard and Private walks on dev passed. The Fortress browser walk and the checks after release are in the live verification queue. No questions are open.

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

**As built (2026-10-23):**

- **Folders.** Each message goes once into the view it sits in: Inbox, Archive, Sent, Spam, Trash, decided Trash, then Spam, then Sent, then Archive or Inbox. Drafts are left out, because they are unsent work and our import would file them as mail. One label can also be downloaded on its own. `X-Gmail-Labels` carries the view word, the labels and Unread/Starred, and a label name with a comma is quoted the way Takeout writes it. The importer now reads quoted names.
- **The marker.** `X-Joinery-Rebuilt: yes` is on a downloaded or exported rebuild. Mail programs still get FORMAT 1 unmarked, because marking them would change bytes every program has cached.
- **Where the zip is made.** A Standard or Private build writes mbox pieces (sealed ones for Private, using `SealedFileContainer::sealIterable`). The zip is put together as it downloads, so the server holds the mail once and no zip state crosses build passes.
- **The ready email.** It goes from the `RunMailExports` task. A Private build runs in a request that opened sealed mail, and the egress guard refuses the email-queue write there, so the email cannot be sent from the build itself (found in testing).
- **Who may ask.** A Private or Fortress mailbox can be downloaded only by its owner; a superadmin gets Standard mailboxes only. A Fortress run's paging is covered by its start's second factor for 12 hours.
- **What the note names.** A Private run's left-out note names a message by folder, date and id, never by its subject.
- **Fortress parts.** In a Fortress download, one mbox never crosses a part: a folder past 64 MB continues in `Inbox (2).mbox`.

### WP2. Contacts export

**Built and reviewed 2026-10-22.** The `contacts` and `csv` tests cover the round trip, the formula rule, a sealed store's export, and B2-B15.

**What the member gets.** On `/profile/mailbox/contacts`, **Export** as vCard 3.0 (`.vcf`) or Google CSV.

- A contact is a name and an email (`imc_display_name`, `imc_address`), which is all that is stored.
- Contacts are sealed to server custody, so the export runs while the vault is unlocked. The list is small, so it is built in the request.
- The CSV applies the formula rule (P5).

**Fixes B2.** `MailboxContacts::parseCsv()` reads whole records, so a quoted newline (common in Google's "Notes" column) no longer breaks a row. Without this the CSV round trip (P2) fails.

**How it was built:**

- **Core CSV helpers.** `CsvWriter` applies the formula rule (P5) and `CsvReader` parses whole records and takes the defusing apostrophe off again. Both are the ones `DEFERRED_data_portability.md` WP3 and WP4 name, so those packages reuse them.
- **The file reaches the browser in the API response.** `mailbox/contacts_export` returns `{filename, content_type, content}` and the page saves it as a download. The list is small, so nothing is queued or written on the server.
- **The CSV uses Google's current columns:** First Name, Last Name, E-mail 1 - Value. The stored name is cut at its last space, and the importer joins the two back with one space, so the CSV round trip is exact up to spacing (a run of spaces comes back as one). The vCard round trip is exact.
- **P5 covers the address cell too.** An address starting with `-`, `+` or `=` (legal, and rare) is written with an apostrophe. This importer takes it off; Google Contacts may keep it. Leaving addresses undefused would let a crafted address run as a formula in a spreadsheet.
- **vCard** writes FN (the name exactly), N, and EMAIL. A contact with no name carries its address as FN, which vCard requires; the importer reads that back as no name.

### WP3. Personal calendar export

**Built and reviewed 2026-10-22.** The `ics_export`, `ics_import` and `ics_vevent` tests cover the round trip through an empty calendar and back to the same file, re-import, a Private calendar, the inverse rule, the time zone parts, and B16-B20.

**What the member gets.** On `/profile/calendar`, under Actions, **Download my calendar (.ics)**: a one-time file, not a feed.

**What changes in the code:**

- `IcsHelper::buildVevent()` gains RRULE, EXDATE, TZID and all-day `DATE` values. Today it writes only start, end, summary, description, location, url and status, so recurrence would be lost.
- An entry imported with `cal_rrule_raw` writes its original rule back out.
- Any other entry writes the inverse of `IcsImporter::translateRecurrence()`, which is new.
- Exceptions come from `CalEntryException`.
- Private entries (`cal_title`, `cal_location`, `cal_link`, `cal_notes`) are decrypted in the request while unlocked (`CalendarSealed`). The calendar has no Fortress level.

**Scope.** The member's own entries only. Events and bookings already have their own `.ics`.

**How it was built:**

- **`IcsExporter`** (`includes/calendar/IcsExporter.php`) is the write-side mirror of `IcsImporter`. The `calendar_export` action returns `{filename, content_type, content, count}` and the page saves it as a download, like WP2. A calendar is small enough to build in the request.
- **Times.** A timed entry is written in its own zone (`TZID`), so a repeating entry keeps its local time across a clock change, and the file carries a `VTIMEZONE` for each zone (`IcsHelper::vtimezone()`), which RFC 5545 requires and Outlook reads. It is the zone's current yearly rule, as Google, Apple and Outlook write theirs, taken only when the rule has held for 28 years running. Older changes, and every change of a zone with no weekday rule (Israel, Chile, Morocco), are listed one by one, at least 30 years ahead. The test checks the offsets against PHP's for every change of eleven zones over 30 years; a check of every zone on the box found the only misses past 30 years in those no-rule zones.
- **The rule.** A native series writes `IcsImporter::nativeToRrule()`. An entry imported with a rule the calendar cannot express writes `cal_rrule_raw` back. `cal_rrule_raw` means the repeat as it came in, untouched: a save that changes the repeat drops it (B19), and a native series always writes its native fields.
- **An occurrence edited on its own** is written as an override (the series' UID and `RECURRENCE-ID`), and its date is left out of `EXDATE`.
- **UIDs.** An imported entry keeps its UID, so the calendar it came from still knows it. Any other entry is `cal-{id}@{site}`.
- **Status** is written, and the import now reads it (B18), so tentative and cancelled survive the round trip.
- **Not carried:** the per-entry reminder choice. It is how this calendar emails the member, not part of the entry. Skipped dates of a rule the calendar cannot express were never stored, so they cannot come back out.

### WP4. Download a Drive folder as a zip

**Built and reviewed 2026-10-22.** The `drive_folder_zip` test covers the round trip, names, missing bytes, the route's statuses for owner, grantee, stranger, signed out, Fortress and locked Private, and a Private folder in the window; `zip_stream_writer` and `zip_writer_js` put both writers through every reader at hand in the classic and ZIP64 forms. A one-off run wrote two 4.4 GB files and one past the 4 GiB mark, and unzip, Python and PHP read them back. The dev walk downloaded a Standard tree, a Private folder through the unlock, and a Fortress folder both through the save dialog and held in the tab; every file matched byte for byte, and macOS's own unzip extracted all three.

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

**How it was built:**

- **The server side** is `DriveZip` (`includes/DriveZip.php`) behind a route, `/drive/zip/{folder_id}` (`includes/drive_zip_route.php`), not an API action: the answer is the file itself, streamed as it is built. `plan()` resolves read access, the owner's window and every sealed file's key before the first byte, so a refusal is a status (`404`, `409` Fortress, `423` locked) and the page can `HEAD` first, unlock on `423`, then download. The request releases the session before it streams, so the vault heartbeat and the member's other pages do not wait on a long download.
- **Fortress** is zipped in the tab: the tree is listed from `drive_list` pages and named first, then each file is fetched by its signed link and decrypted with the member's own key. A link that has run out on a long zip is replaced by listing its folder again. `showSaveFilePicker` writes one file to disk where the browser has it (Chrome, Edge). Elsewhere the tab holds one part of about 500 MB at a time, and each finished part waits for a **Save** press, since Safari and Firefox save a file for a page only from a click. Listing first is what lets a folder bigger than one part be named in parts from the start without holding part 1 back.
- **Offloaded files** are fetched to the server's temp space one at a time before they go into the zip, as a single download is; the cloud driver has no streaming read. A streaming read would let large offloaded folders skip that copy; it is not needed for this WP.
- **Names.** Characters that would move a file out of its folder are replaced, and siblings that differ only in case are numbered, since a Mac or Windows disk would overwrite one with the other. Windows-only reserved characters (`:` `?` and the like) are kept: replacing them would change names on every system for the sake of one.
- **A file whose bytes are gone** is left out and named in `Files not included.txt`, rather than failing the whole zip or vanishing silently.
- **Not in the zip:** versions other than the current one, trashed items, and the share settings.

## Bugs found while scoping

- **B1.** "Download .eml" refuses most messages. A lean record has no original, and the header-plus-plain-body reconstruction must not be served as .eml (`message_export.php`, `mailbox_resolve_original`). **Fixed by WP1:** a full rebuild.
- **B2.** `MailboxContacts::parseCsv()` splits lines before parsing, so a quoted newline in a Google CSV breaks the row. **Fixed by WP2.**

Found while building WP2, all fixed with it:

- **B3.** `IcsHelper::foldLines()` could fold in the middle of a UTF-8 character, leaving lines that are not valid UTF-8 on their own. Event `.ics` files with accented or non-Latin text were affected too.
- **B4.** An imported name holding a quote or an angle bracket was dropped with its contact. The parsers glued the name and address into `Name <address>` and parsed that again. They now hand the two over separately.
- **B5.** A Google Contacts CSV exported since 2023 imported with no names. It has First Name and Last Name columns and no Name column.
- **B6.** The vCard reader did not unfold long lines or unescape `\,` and `\;`, and missed Apple's grouped `item1.EMAIL` addresses.

Found in review of WP2, all fixed with it:

- **B7.** A vCard address whose parameters held a quoted colon (`EMAIL;X-LABEL="home:main":...`) was dropped as skipped.
- **B8.** A nameless vCard with two addresses (FN is the first address) gave the second contact the first address as its name. A vCard 2.1 card with N but no FN imported with no name.
- **B9.** An Outlook CSV imported its E-mail Type and E-mail Display Name columns as addresses: one contact came in with two junk rows skipped. Its E-mail Address columns are now read and nothing beside them.
- **B10.** A name starting with an apostrophe and a formula character (`'=x`) lost the apostrophe on a CSV round trip. `CsvWriter` now adds one more apostrophe, and `CsvReader` takes exactly one off.
- **B11.** A vCard file starting with a byte order mark reported one skipped card that was not there.
- **B12.** vCard 2.1 names written as quoted-printable (old phones and Outlook, for every non-ASCII name) imported garbled, as `Ren=C3=A9`. They are now decoded, soft line breaks and `CHARSET` included.
- **B13.** A Yahoo CSV (First, Middle, Last, Email) imported with no names.
- **B14.** A vCard or CSV in raw Windows-1252, which is what desktop Outlook saves, could not be stored: the database refuses bytes that are not UTF-8. The same happened with a vCard `CHARSET` PHP does not know, or a value that claims UTF-8 and is not. Text that is still not UTF-8 is now read as Windows-1252, which maps every byte. A UTF-16 CSV, which imported nothing, is read by its byte order mark.
- **B15.** An import's counts were what it meant to store, not what it stored. One row whose write failed dropped every row after it while the import still reported them as imported. An import into a sealed store with the vault window shut reported every row imported and stored none. Each row now succeeds or fails on its own, the counts are what was stored, and the locked import is refused like the locked export.

Found while building WP3, all fixed with it:

- **B16.** Uploading a downloaded calendar back to the same calendar would have added a second copy of every entry made here. The import only recognised UIDs it had stored itself. It now also recognises `cal-{id}@{site}` for every entry on the calendar.
- **B17.** The import read a UTC `UNTIL`, `EXDATE` or `RECURRENCE-ID` as a UTC date. A Monday 8 PM class in New York is a Tuesday in UTC, so its skipped and replaced dates landed on the Tuesday and the Monday still showed. A Google-style `UNTIL` at the end of the last day, in UTC, added one occurrence past the end. All three are now read in the series' zone, and an `UNTIL` earlier in the day than the series starts ends it the day before.
- **B18.** The import ignored `STATUS`: a tentative or cancelled entry from another calendar came in as confirmed.

Found in review of WP3, both fixed with it:

- **B19.** A repeat the member switched off could come back in the download. An Outlook monthly rule (`BYMONTHDAY=15`) imports as a native repeat with the rule kept. The member turned the repeat off and moved the entry to the 16th; the rule no longer matched the start, so the export took it for one the calendar cannot express and wrote it. Nothing in the form cleared the kept rule. `_calendar_set_recurrence()` now drops it whenever the repeat changes, and the export writes a kept rule only on an entry that does not repeat.
- **B20.** A timed row with a zone and no stored wall clock would have been written with its UTC clock labelled as the zone's. Its UTC instant is now read in the zone. No such rows exist on dev.

Found while checking WP3 on dev, fixed:

- **B21.** The dev walk script (`maintenance_scripts/dev_tools/walk_vault.js`) still switched users with a link after 0.8.521 made the admin's log in as user a button. In a browser already signed in as the admin the switch did nothing, and the script went on to the admin's own security page and tried to add a passkey there (refused for the wrong password; nothing changed). It now switches with a POST and stops unless it is signed in as the walk user.

Found while building WP4, fixed with it:

- **B22.** A Fortress file showed its encrypted size in the Drive listing (a 12-byte file read 44 B). The plaintext size travels in the file's encrypted metadata, and the page now shows it once it decrypts the name.
- **B23.** Drive's messages sat at the bottom centre of the window, under the cookie banner on a narrow window (every phone), so a member who had not answered the banner never saw them. They now sit at the top.

Found in review of WP4, all fixed with it:

- **B24.** A deflate step that failed was skipped, leaving a short entry and a broken archive with no error. It now throws.
- **B25.** A Fortress zip held in the tab saved its parts with clicks the page made itself, long after the member's; Safari blocks those and Firefox may ask for each. Each part now waits for the member's own Save press, and the tree is listed first so only one part is held at a time.
- **B26.** A member's own file named `Files not included.txt` in the top folder would have shared its name with the note. The note now takes the next free name.

Found while checking WP4 after its release, fixed:

- **B27.** On a site with Drive off, `/drive` answered a 500 error page with an error reference ('Drive is not available.'), because the page had no route of its own and its logic's refusal was rendered as a failure. Every other Drive URL was gated on `drive_active`; `/drive` now is too (serve.php 1.16.0), so it is a 404.
- **B28.** A WP4 walk left a `log_logins` row (a logout) and a vault-closed audit row naming its walk user after the user was deleted, failing `referential_integrity`. The browser still held that user's session; its next request found the account gone and signed it out, and `logout()` wrote both rows against the missing id. A session whose account no longer exists now ends without writing anything in its name (`SessionControl::logout(true)`, `VaultUnlock::close(..., $audit = false)`); the window's keys are still wiped.

Found while building WP1, fixed with it:

- **B30.** A header written in plain UTF-8 lost its non-ASCII text. RFC 6532 allows this, and modern mail sends it. Arrival decoded Subject and sender names with `mb_decode_mimeheader()`, which turns every raw non-ASCII byte into "?" ("H??llo"). The sending and mail-apps paths used `iconv_mime_decode()`, which drops those bytes instead. All of them now use `MailHeaderText::decode()`. It decodes encoded words, keeps raw UTF-8, and reads other 8-bit text as Windows-1252 (B14's rule). Messages already stored keep their "?" marks.

## Decided

- **Q1 (owner, 2026-10-21 and 2026-10-22).** This spec covers email, contacts, calendar and Drive. Everything else from the first draft is in `DEFERRED_data_portability.md`. Posts and pages, and exports for boards and mail filters, are not in either spec.
- **Q2 (owner, 2026-10-21).** Fortress mail export is built with WP1, in the browser. Fortress is the tier sold on "only you hold your data", so it can't be the one tier you can't leave with your mail.
- **Q3 (2026-10-22, owner can overrule).** A small zip writer of our own, using the compression built into PHP (zlib) and the browser (`CompressionStream`). PHP's `ZipArchive` is on every install but only writes a finished file to disk, which would leave Private content decrypted at rest and make large folders wait on free disk. A library would add two outside dependencies to save about two hundred lines.

## Open questions

None.

## Files

**New:**

- includes/ZipStreamWriter.php, assets/js/zip-writer.js, includes/DriveZip.php, includes/drive_zip_route.php (built with WP4)
- includes/CsvWriter.php, includes/CsvReader.php (built with WP2), plugins/mailbox/logic/contacts_export_logic.php (built)
- includes/calendar/IcsExporter.php, logic/calendar_export_logic.php (built with WP3)
- plugins/mailbox/includes/MessageRebuild.php (moved from mail_apps), MailExport.php, MailHeaderText.php (B30) (built with WP1)
- plugins/mailbox/data/mail_export_runs_class.php (MailExportRun, mxr_mail_export_runs)
- plugins/mailbox/tasks/RunMailExports.php (+ .json)
- plugins/mailbox/logic/mail_export_start / mail_export_list / mail_export_delete / mail_export_fortress_page _logic.php, mailbox_export_page_logic.php, profile_export_download_logic.php
- plugins/mailbox/views/profile/export.php, export_download.php
- plugins/mailbox/assets/mailbox_mime_build.js (the browser message builder for Fortress export), mailbox_export.js, mailbox_export.css

**Changed:**

- plugins/mailbox/includes/message_export.php, logic/profile_original_logic.php (B1; built)
- the mailbox reader's Settings menu (views/profile/mailbox.php), the reader's kebab (assets/mailbox_reader.js), and the admin mailbox list (admin/admin_mailbox.php) (built)
- plugins/mailbox/includes/MailboxService.php (fortressExportMessages), import/MailArchiveReader.php (quoted label names), bootstrap.php (the `mailbox_export` deferred-work consumer), plugin.json (`mail_export.ready`) (built)
- includes/SealedFileContainer.php (sealIterable) (built)
- InboundEmailRouter, MailAddressList, MailboxSender, mail_apps MailAppMutations and mail_submit_logic (B30) (built)
- plugins/mailbox/includes/MailboxContacts.php (export; whole-record CSV parsing)
- plugins/mailbox/views/profile/contacts.php
- plugins/mailbox/logic/contacts_import_logic.php (B15: true counts, a locked store refused; built)
- plugins/mail_apps (calls `MessageRebuild`) (built)
- includes/IcsHelper.php, includes/calendar/IcsImporter.php (inverse rule; B16-B18) (built)
- views/profile/calendar.php, logic/calendar_logic.php (B19) (built)
- views/drive.php, assets/js/drive.js, serve.php (the `/drive/zip/{folder_id}` route) (built)
- tests/lib/http.php (a HEAD request waits for no body; built with WP4)
- maintenance_scripts/dev_tools/walk_vault.js, specs/dev_walk_fixtures.md (B21)
- settings.json (`export_retention_days`) (built)

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
