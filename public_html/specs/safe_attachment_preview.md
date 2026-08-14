# Safe Attachment Preview — Spec

**Status:** UNBUILT. Drafted 2026-08-14. Format research done against real files on dev — see Evidence.

## What this gives the user

Reading an emailed PDF today means downloading it and opening it in something. That is the one moment a mail reader hands attacker-supplied bytes to a program that will act on them.

This adds a **Preview** button beside eligible attachments in the mailbox reader. It opens a modal containing the document's **words, as plain text** — nothing else. No PDF is rendered, no page is drawn, no markup becomes a document, no font is loaded, no URL is fetched. You read the invoice, decide whether it is worth opening for real, and close it.

The promise, in one line: *you read what it says without running what it is.*

## The question this answers first

The text extractor already exists — it is what strips a PDF when you upload one to the AI chat — and it lives entirely in the AI plugin:

- `plugins/joinery_ai/includes/AiAttachment.php` — policy, classification, subprocess spawn, model-block building
- `plugins/joinery_ai/cli/extract_text.php` — the parser, run as an isolated subprocess

There is no core equivalent. `data/files_class.php` detects MIME types and stops there. `specs/drive_content_search.md` proposes a core `DriveTextExtractor` that was never built and would have been a second copy.

**Decision: promote the extraction half to core, leave the AI policy half in the plugin.** Mailbox must not require the AI plugin to let someone read a PDF, and Drive search must not grow a third parser.

---

## Evidence

Everything below was measured on dev, not assumed.

### What the platform already has

| Capability | State |
|---|---|
| `zip`, `dom`, `libxml`, `simplexml`, `xmlreader`, `mbstring`, `fileinfo`, `iconv`, `zlib` | all loaded (PHP 8.3.32) |
| `smalot/pdfparser` v2.12.5 | production dependency, pure PHP |
| `bytestream/horde-mime` v2.14.1 | production dependency, already used by mailbox |
| `pdftotext`, `libreoffice`, `antiword`, `tesseract`, `pandoc` | **none installed** |

That `zip` is loaded is the decisive fact: OOXML (docx/xlsx/pptx), OpenDocument (odt/ods/odp) and EPUB are all ZIP + XML, so they need **no new dependency at all**.

### Real mailbox attachments on dev

208 non-inline attachments, by extension and the MIME the sender declared:

| Ext | Declared type | Count |
|---|---|---|
| pdf | `application/octet-stream` | **147** |
| pdf | `application/pdf` | 61 |
| kdbx | `application/octet-stream` | 19 |
| zip | `application/zip` / `x-zip-compressed` | 19 |
| docx | `…wordprocessingml.document` | 3 |
| png / jpg | image/* | 4 |
| ics | `text/calendar` / `application/ics` | 3 |
| txt | `application/octet-stream` / `text/plain` | 3 |
| html | `text/html` | 2 |
| (other) | pgp-signature, delivery-status, rfc822-headers | 6 |

**71% of PDFs arrive declared as `application/octet-stream`.** This kills the eligibility design in the first draft of this spec — see Eligibility below.

### PDF extraction, measured across every readable PDF in the mailbox

54 attempted:

| Outcome | Count | Share |
|---|---|---|
| Text extracted | 37 | 69% |
| Parsed cleanly, no text layer (scanned) | 9 | 17% |
| Secured / encrypted, parser refuses | 7 | 13% |
| Parser error | 1 | 2% |

Timing: **median 21ms, p90 445ms, max 2.2s** — comfortably inside a 20s ceiling.

Memory, measured per file in an isolated 256M process: **18–35MB**, even for a 5.9MB PDF. A single long-lived process looping over all 54 peaked at 512MB, because the parser retains per-document state. The one-shot subprocess is therefore not only a security boundary but the thing that stops that retention accumulating.

Two of the three failure modes are common enough to deserve their own honest wording rather than a shared "couldn't read it": scanned documents (17%) and encrypted documents (13%).

### New formats, prototyped and run

| Format | Fixture | Result |
|---|---|---|
| **docx** | real 34.6KB signed contract from `uploads/` | 17,537 chars, paragraph breaks intact |
| **xlsx** | constructed, incl. `sharedStrings` indirection | rows recovered as tab-separated text |
| **pptx** | constructed, 2 slides | per-slide text, slides labelled |
| **odt** | constructed ODF | headings + paragraphs |
| **epub** | constructed, 2 XHTML chapters | both chapters; `<script>` and `<style>` bodies dropped |
| **rtf** | constructed with font/colour tables, `\info`, hex + unicode escapes | see defect 1 |

Extraction of all five ZIP formats took **under 1ms** each and peaked at 2MB.

### Two defects the prototype exposed, and their fixes (both validated)

**1. RTF regex-stripping leaks metadata.** A regex pass over control words left the font table, the colour table, and the `\info` group — meaning the document's **title and author leaked into the preview** even though they are not body text. RTF destination groups nest; a regex cannot track that. The fix is a brace-depth state machine that skips `\fonttbl`, `\colortbl`, `\info`, `\pict`, `\*` destinations and friends by the depth at which they opened. Validated: font table, title, author and generator all absent; invoice number, the `\'a3` hex escape (£), the `\u8212` unicode escape (an em dash), coloured text and tabs all preserved.

**2. HTML `textContent` runs blocks together.** `Chapter OneIt was a dark and stormy night.` — no boundary between a heading and the paragraph after it. **The existing core HTML branch has this same flaw today.** The fix inserts newlines around block-level elements and tabs after table cells before taking text. Validated on the EPUB fixture.

### Abuse cases, tested

| Attack | Result |
|---|---|
| **XXE** — XML declaring `<!ENTITY xxe SYSTEM "file:///etc/passwd">` | Refused. |
| Same file parsed **with `LIBXML_NOENT`** | `/etc/passwd` contents leak into the extracted text immediately. |
| **Zip bomb** — 61KB docx expanding to 60MB in one member | Refused by the per-member size guard. |

The middle row is the important one. **`LIBXML_NOENT` is the entire difference between safe and catastrophic**, and this spec adds five new XML-parsing branches. That is why every branch must go through one shared helper rather than calling `loadXML()` itself.

---

## Part 1 — Core: `DocumentText`

### New files

**`includes/DocumentText.php`** — the platform's one answer to "what does this file say?"

```php
class DocumentText {
    const TIMEOUT_SECONDS   = 20;
    const MEMORY_LIMIT      = '256M';
    const DEFAULT_MAX_CHARS = 50000;

    // Values unchanged from AiAttachment::EXTRACT_* — joinery_ai persists them
    // in aia_extract_status.
    const OK        = 'ok';         // text present
    const EMPTY     = 'empty';      // parsed cleanly, no text layer (scanned)
                                    // (reserved words are legal constant names since PHP 7)
    const FAILED    = 'failed';     // parser error, timeout, or OOM
    const SKIPPED   = 'skipped';    // not an extractable type
    // New, because the real corpus makes them worth telling apart:
    const SECURED   = 'secured';    // encrypted / permission-restricted document
    const TOO_LARGE = 'too_large';  // over the byte ceiling, never parsed

    public static function normalizeMime($mime): string;
    public static function categoryForMime($mime): ?string;
    public static function isExtractable($mime): bool;
    /** Extension -> canonical MIME, for when the sender declared octet-stream. */
    public static function mimeForExtension(string $ext): ?string;
    /**
     * Resolve a zip container to its real format by inspecting members.
     * Runs INSIDE the extraction subprocess only — the parent never opens
     * attacker bytes with ZipArchive (see "Detection happens inside the
     * sandbox").
     */
    public static function refineContainerMime(string $bytes, string $mime): string;

    /**
     * @return array{status:string, category:?string, text:string, detail:?string} — never throws.
     * text is ALWAYS valid UTF-8 and truncation is multibyte-safe (see Output contract).
     */
    public static function extractPath(string $path, string $mime, int $maxChars = self::DEFAULT_MAX_CHARS): array;

    /** Same, but bytes go to the subprocess over stdin — nothing touches disk. */
    public static function extractBytes(string $bytes, string $mime, int $maxChars = self::DEFAULT_MAX_CHARS): array;

    /**
     * The one XML door. Every branch uses this. Passes LIBXML_NONET
     * affirmatively; never passes LIBXML_NOENT, LIBXML_DTDLOAD, or
     * LIBXML_PARSEHUGE (PARSEHUGE disables libxml's built-in
     * entity-expansion limits — the billion-laughs guard).
     */
    private static function xmlText(string $xml, array $breakAfter = []): string;
    private static function htmlText(string $html): string;
}
```

**`utils/extract_document_text.php`** — `plugins/joinery_ai/cli/extract_text.php` moved, extended with the new branches, and taught that a path of `-` means *read the document from stdin*. First line of the script: refuse any non-CLI SAPI (`php_sapi_name() !== 'cli'` → exit) so it is inert if ever reached over the web.

Exit codes preserved: `0` success (possibly empty), `2` bad usage, `3` parse error, `4` unsupported type; parent reads `124` as timeout and `137` as OOM. Add exit `5` = secured document. Stdout gains one machine header line before the text — `category=<cat>` followed by a blank line — because the parent no longer detects the type itself (next section) and the modal needs the category to pick monospace vs proportional. `DocumentText` owns both sides of this contract; nothing else parses it.

### Detection happens inside the sandbox

The parent — the web request — never opens attacker bytes with **any** parser: not `ZipArchive`, not `DOMDocument`, and not `finfo` (libmagic is C code with its own CVE history; running it in the request process would be the one place attacker bytes met native code outside the sandbox). The subprocess trusts nothing about the type it was told: it runs `finfo_buffer()` on the bytes it received, calls `refineContainerMime()` for zip containers, and parses only what it detected. The MIME argument the parent passes is demoted to an advisory hint, consulted only when `finfo` is inconclusive (e.g. labelling `text/plain` vs `csv` — cosmetic either way, both render as text).

One mechanical consequence: `ZipArchive` can only open a *file*, and the container bytes arrive on stdin. The subprocess stages container bytes to a file created on `/dev/shm` (memory-backed tmpfs), opens it, and unlinks it immediately — before parsing begins. tmpfs contents live in RAM and can reach persistent storage only if the host swaps, which is exactly the exposure the plaintext already has sitting in the PHP process's own memory; the no-disk promise holds to the same degree in both places. `smalot/pdfparser` needs no file at all (`Parser::parseContent()` takes a string), so PDFs never stage anywhere.

### The format table

**Already supported (moving to core unchanged):**

| Category | Types |
|---|---|
| `pdf` | `application/pdf` |
| `html` | `text/html` |
| `text` | `text/plain`, `text/markdown`, `text/x-markdown`, `text/csv`, `application/csv`, `application/json` |

**New — ZIP + XML, zero new dependencies:**

| Category | Types | Parts read |
|---|---|---|
| `docx` | `…wordprocessingml.document` | `word/document.xml`, headers, footers, footnotes, endnotes |
| `xlsx` | `…spreadsheetml.sheet` | `xl/worksheets/sheet*.xml`, resolved through `xl/sharedStrings.xml`; rows tab-separated, sheets labelled |
| `pptx` | `…presentationml.presentation` | `ppt/slides/slide*.xml` and `ppt/notesSlides/*` (speaker notes), slides labelled |
| `odf` | `…opendocument.text` / `.spreadsheet` / `.presentation` | `content.xml` |
| `epub` | `application/epub+zip` | XHTML chapters in spine order, through the HTML branch |

**New — small dedicated parsers:**

| Category | Types | Notes |
|---|---|---|
| `rtf` | `text/rtf`, `application/rtf` | brace-depth state machine (see defect 1) |
| `xml` | `text/xml`, `application/xml`, `image/svg+xml` | visible text only. **SVG text is extracted, never rendered** — that is precisely why it is safe here when it is not safe in a body frame |
| `eml` | `message/rfc822` | forwarded mail: From/To/Subject/Date plus the text body, via the Horde MIME parser mailbox already uses |
| `ics` | `text/calendar`, `application/ics` | rendered as a readable event summary, reusing `IcsImporter::parse()` |
| `text` (widened) | `text/*` generally, plus `application/x-yaml`, `application/yaml`, `application/toml`, `application/x-sh`, `application/sql`, `application/javascript` | a `.log`, `.yml`, `.ini`, `.sql`, `.srt`, `.vtt` or `.vcf` is exactly as safe to show as a `.txt`, because it is shown as text |

**New — archive manifest, a different kind of preview:**

| Category | Types | Behaviour |
|---|---|---|
| `archive` | `application/zip`, `application/x-zip-compressed` | Lists entry **names, sizes and dates only**. Nothing inside is decompressed or parsed. 19 real zips in the mailbox; "what is in this archive" is a genuine question answerable without opening it. Labelled *Contents*, not *Text*, in the modal. |

**Deliberately not supported, and why:**

| Format | Reason |
|---|---|
| `.doc`, `.xls`, `.ppt` (legacy binary OLE) | No pure-PHP parser; `antiword` not installed. Would need a new dependency. |
| `.msg` (Outlook) | CFB compound binary, no library available. |
| `.pages`, `.numbers`, `.key` | ZIP containers wrapping proprietary IWA binary. |
| `.7z`, `.rar`, `.tar.bz2` | No matching extension loaded (`bz2` is absent; only `zip`). |
| `.kdbx` | Encrypted database — 19 in the real corpus, correctly never previewable. |
| Images | No OCR anywhere on the platform, and none proposed. |
| Encrypted / scanned PDFs | Detected and named honestly rather than "supported". |

### Container disambiguation

docx, xlsx, pptx, odt and epub can all sniff as `application/zip` depending on the `libmagic` build. (On dev, `finfo` happens to identify them correctly — but that is a property of this box, not a guarantee.) `refineContainerMime()` — inside the subprocess, on the staged member list — resolves it: `word/document.xml` → docx, `xl/workbook.xml` → xlsx, `ppt/…` → pptx, else the `mimetype` member for ODF/EPUB, else plain archive.

### Output contract: always valid UTF-8

Extracted text crosses a JSON boundary, and `json_encode()` **fails outright** on malformed UTF-8 — one Latin-1 `.txt` would otherwise turn the whole preview payload into an error. So the contract is: whatever `extractPath()` / `extractBytes()` return in `text` is valid UTF-8, unconditionally.

- `text/*` bytes in an unknown charset are converted with substitution (`mb_convert_encoding` with invalid sequences replaced), never passed through raw
- RTF hex escapes (`\'a3`) decode per the document's declared code page (cp1252 default), then convert
- EML bodies honor each part's declared charset via the Horde MIME parser mailbox already uses
- truncation at `maxChars` is multibyte-safe — never splits a UTF-8 sequence

### Zip safety

Every container opens through one guarded helper (`ZipArchive::CHECKCONS`) enforcing, before reading anything:

- per-member uncompressed ceiling (12MB)
- summed uncompressed ceiling (40MB)
- per-member compression-ratio ceiling (200:1 above 1MB) — what caught the test bomb
- a cap on parts read (400 slides/chapters, 50 sheets)

These sit *inside* the subprocess, so they are a second line behind `timeout` and `memory_limit`, not a replacement. The `/dev/shm` staging file (previous section) is unlinked before any member is read, so even a kill -9 mid-parse leaves nothing behind.

### Why `extractBytes()` uses stdin

`AiAttachment::extract()` today stages cloud-stored bytes into `tempnam(sys_get_temp_dir(), …)`. `specs/implemented/sealed_content_egress.md` logs that as an **accepted risk**: decrypted attachment bytes touch disk-backed `/tmp` during extraction.

A mailbox attachment on a protected mailbox is sealed under the message's DEK. Previewing means decrypting it, and writing that plaintext to `/tmp` — even at 0600, even unlinked after — puts sealed content on the disk it was sealed to stay off. Piping to the subprocess over `proc_open` stdin keeps it in memory on both sides; `timeout` and `memory_limit` apply exactly as before. `AiAttachment::extract()`'s cloud branch should switch to it in the same pass, retiring the logged risk rather than extending it.

**The parent must pump, not write-then-read.** Up to 15MB goes down stdin; if the child errors early, stops reading, and writes to stdout/stderr, a naive write-all-then-read parent blocks on a full pipe buffer opposite a child blocked the same way — a deadlock that `timeout` converts into a guaranteed 20-second stall per bad file. `extractBytes()` uses a `stream_select` loop over stdin/stdout/stderr: write when stdin is writable, drain output whenever it is readable, close stdin when the bytes are gone.

### Changes to `AiAttachment`

The plugin keeps everything about *feeding a model*: `CATEGORY` (which includes images), per-category byte caps and their settings, `validateForIngress()` / `validateRaw()`, `readOriginalBytes()`, `blocksForAttachment()`, untrusted-input framing.

It loses only the subprocess:

- `runExtract()` deleted; `extract()` / `extractPath()` delegate to `DocumentText`
- `EXTRACT_TIMEOUT_SECONDS`, `EXTRACT_MEMORY_LIMIT` deleted — core constants govern
- `EXTRACT_OK` / `EXTRACT_EMPTY` / `EXTRACT_FAILED` / `EXTRACT_SKIPPED` become aliases of the `DocumentText` constants, so `aia_extract_status` values and every `AiAttachment::EXTRACT_*` comparison in `ChatAttachmentIngest`, `ChatRunner` and `ai_message_attachments_class.php` keep working untouched
- `plugins/joinery_ai/cli/extract_text.php` deleted

`AiAttachment` must keep returning `SKIPPED` for images; `DocumentText` returns `SKIPPED` for any non-extractable MIME, which covers it.

**The AI chat gains the new formats for free** — a docx or xlsx uploaded to chat becomes readable where today it is rejected at ingress. That is a behaviour change in the plugin's accepted set and should be a deliberate, separate follow-up decision, not a side effect: `AiAttachment::CATEGORY` stays as it is in this pass.

---

## Part 2 — Mailbox: the preview

### Eligibility (the octet-stream problem)

71% of real PDFs declare `application/octet-stream`. Deciding the button from `ima_content_type` alone would hide Preview on **147 of 208** attachments.

`MailboxService::attachmentsForMessages()` therefore adds:

```php
'previewable' => DocumentText::isExtractable($r['ima_content_type'])
    || DocumentText::isExtractable(DocumentText::mimeForExtension($ext)),
```

This is a **UI hint only** — it decides whether a button is drawn, never what the parser does. The extraction subprocess re-sniffs the bytes with `finfo_buffer` and that detected type is the only one the parser ever sees. A `.kdbx` declared octet-stream maps to no known extension and stays correctly hidden.

### The chip

`attachmentsBlock()` currently builds each attachment as one `<a>`. A button cannot legally nest inside a link, so the chip becomes a container:

```
.mbx-attachment                    (div — the bordered chip)
  a.mbx-attachment-open            (icon + name + size; download, unchanged)
  button.mbx-attachment-preview    (eye glyph; only when previewable)
```

`title="Preview as text"` plus an `aria-label` naming the file. Non-previewable attachments render exactly as today. The existing sealed-download behaviour on the `<a>` (prompt one-tap unlock when `state.threadLocked`, then open) is preserved, and the preview button does the same before opening the modal.

### New API action: `mailbox/attachment_text`

`plugins/mailbox/logic/attachment_text_logic.php`, POST `{attachment_id}`, exposed via the standard `_logic_descriptor()` opt-in with `requires_session => true`, no `ai_agent` key.

1. Session, then load `InboundMessageAttachment` → its `InboundEmailMessage` → delete check.
2. **Grant check identical to the download endpoint** (`profile_attachment_logic.php`): `MailboxViewer::fromSession()`, `$alias_id > 0 ? canAccess($alias_id) : isAllAccess()`. A preview is as private as the attachment, which is as private as its message; a NULL-alias message stays superadmin-only.
3. **Rate limit:** `RequestLogger::check_rate_limit('mailbox_preview', 30, 300)` — the same per-IP helper already fronting `/api/v1`. Each preview is a subprocess worth up to 256MB × 20s; the global API limit (1000/hr) bounds volume but not burst, so this endpoint gets its own 30-per-5-minutes check. Constants in the logic file, not settings — no operator needs to tune this, and zero-config says don't make them.
4. `mailbox_retrieve_attachment_bytes($att, $message)` — the one helper already handling all four backings (private File, IMAP on-demand part, section pointer into a stored raw, sealed File via `openSealedAttachment()`).
5. Byte ceiling **before** the subprocess spawns: over `mailbox_preview_max_bytes` (new declared setting, default 15MB) → `TOO_LARGE`.
6. `DocumentText::extractBytes($bytes, $declared_hint, $maxChars)` — MIME detection, container refinement, and parsing all happen inside the subprocess (see Detection happens inside the sandbox). The endpoint itself never sniffs or parses the bytes.

| Case | Payload |
|---|---|
| Text found | `{previewable:true, status:'ok', category, text, truncated, filename, size_bytes}` |
| No text layer | `{previewable:true, status:'empty', …}` |
| Encrypted document | `{previewable:true, status:'secured', …}` |
| Parser failed / timed out / OOM | `{previewable:true, status:'failed', …}` |
| Over the byte ceiling | `{previewable:true, status:'too_large', …}` — the button was already drawn; the user gets the honest sentence, not a silent refusal |
| Wrong type (detected non-extractable) | `{previewable:false, reason:…}` |
| Rate limited | `{previewable:false, reason:…}`, HTTP 429 |
| Vault locked | `{locked:true}` |

### One change to the shared retrieval helper

`mailbox_retrieve_attachment_bytes()` flattens `VaultLockedException` into the string `'Unlock your vault to download this attachment.'` — at **two** catch sites inside the function, one in the file-backed branch (`openSealedAttachment()`) and one in the stored-raw branch (`getRawMimePart()` on a sealed raw). The reader's contract everywhere else is `{locked:true}` plus a one-tap unlock ceremony, not an error string. Add an optional `'locked' => true` key to the failure array at **both** sites; the two existing download endpoints keep rendering `error` and are unaffected.

### The modal

Reuses the existing `.mbx-modal-overlay` / `.mbx-modal` machinery, mirroring `openMessageSource()` — Esc to close, same shell, same focus handling.

- **Title:** filename. **Subtitle:** size, and the guarantee stated plainly — *Text only. Nothing in this file is opened or run.*
- **Body:** a `<pre>` written with `textContent`. Never `innerHTML`, for the same reason the RFC822 source modal does not use it.
  - monospace for `text`, `xlsx`, `archive`, `xml` — columns and manifests need alignment
  - proportional with `white-space: pre-wrap` for `pdf`, `docx`, `odf`, `epub`, `rtf`, `html`, `eml` — extracted prose is unreadable in a monospace column
- **Per-status wording**, because a third of real PDFs land somewhere other than "ok":
  - `empty` → *This looks like a scanned document — there is no text layer to read. Download it to view the pages.*
  - `secured` → *This document is password-protected or restricted, so its text cannot be read.*
  - `failed` → *This file could not be read as text.*
  - `too_large` → *This file is too large to preview. Download it to read it.*
  - truncated → a footer noting the cut and that the download has the rest
- **Buttons:** Copy, Download (the same URL the chip uses), Close.

### Deliberately not cached

Extracted text is not persisted. On a protected mailbox that text is exactly as sensitive as the sealed body; storing it in the clear would quietly defeat the seal, and sealing a preview cache under the message DEK is more machinery than a 20-second, user-initiated, bounded subprocess needs. Re-previewing re-extracts. At a median of 21ms, that is not a cost worth paying complexity for.

---

## Security

1. **Parsing is isolated — detection included.** Every parse runs in a short-lived `timeout 20 php -d memory_limit=256M` subprocess, and so does everything that reads the bytes at all: `finfo` sniffing, container refinement, member listing. The web request process hands bytes down a pipe and reads a result; it never opens them. A bomb, a hang or a runaway allocation kills only that child; the parent reads exit 124 / 137 and reports the file unreadable. Measured worst case on real mail: 2.2s and 35MB.
2. **Native code only inside the sandbox.** `smalot/pdfparser` is pure PHP; the ZIP formats are `ZipArchive` + `DOMDocument`; the honest caveat is that libmagic (`finfo`), libzip and libxml are C — which is exactly why they run only in the subprocess. Nothing shells out to poppler, LibreOffice or antiword — none of which are installed, and none of which this adds.
3. **One XML door, hardened on every axis.** `DocumentText::xmlText()` passes `LIBXML_NONET` affirmatively and never passes `LIBXML_NOENT` (proven above to be the difference between an inert file and `/etc/passwd` in the output), `LIBXML_DTDLOAD` (external DTD fetch), or `LIBXML_PARSEHUGE` (which disables libxml's built-in entity-expansion limits — the billion-laughs guard). All five new XML branches go through it; a branch calling `loadXML()` itself is a defect the tests must catch.
4. **Zip containers are bounded before they are read** — per-member size, total size, compression ratio, part count.
5. **Nothing from the file is interpreted by the browser.** The response is text, written with `textContent` into a `<pre>`. No HTML parse, no scripts, no fonts, no `cid:` resolution, no images, no network fetch the document could trigger.
6. **Detected MIME, never declared — and detected inside the sandbox.** The declared type only decides whether a button is drawn.
7. **The gate is the download gate** — same `MailboxViewer` grant scope, same NULL-alias superadmin rule.
8. **Sealed bytes never touch disk** — decrypted in memory, streamed to the subprocess over stdin; container staging uses only memory-backed `/dev/shm`, unlinked before parsing.
9. **Nothing is retained** — no cache row, no temp file, no log of content.
10. **Metadata is not content.** RTF `\info` (title, author), OOXML `docProps`, and EPUB OPF metadata are excluded from output. The first prototype leaked RTF title and author; the fix is validated.
11. **Cost is bounded per requester.** Each preview can cost a 256MB × 20s subprocess, so the endpoint carries its own per-IP rate limit (30 per 5 minutes via `RequestLogger::check_rate_limit`) on top of the global API limit, and the byte ceiling is enforced before the subprocess spawns.
12. **Output is always valid UTF-8** — charset-converted with substitution, multibyte-safe truncation — so the JSON layer cannot fail on attacker-chosen bytes.

## Not in scope

- **Rendering the file.** No PDF.js, no page images, no thumbnails. Drawing the document is the exact thing this exists to avoid.
- **OCR.** A scanned PDF says so and offers the download.
- **Legacy binary Office, `.msg`, iWork.** Each needs a dependency the platform does not have.
- **Image attachments.** See open questions.
- **Widening the AI chat's accepted set.** `DocumentText` makes it possible; doing it is a separate decision.
- **Preview on other surfaces.** The admin Files page, Drive, and `EmailAttachmentDigest` (which today reads only `text/plain` and `.ics` parts and explicitly calls binary extraction out of scope) all stand to gain. None are built here; the core class is what makes them cheap later.

## Files

**New**
- `includes/DocumentText.php`
- `utils/extract_document_text.php` (moved from `plugins/joinery_ai/cli/extract_text.php`, extended)
- `plugins/mailbox/logic/attachment_text_logic.php`
- `tests/unit/document_text_test.php`
- `tests/fixtures/documents/generate_fixtures.php` — the fixture generator (the research script, moved into the tree)
- `tests/fixtures/documents/` — the generated fixture set (build products of the generator, not checked-in blobs)
- `docs/document_text.md`

**Modified**
- `plugins/joinery_ai/includes/AiAttachment.php` — delegate extraction, alias status constants, drop timeout/memory constants
- `plugins/joinery_ai/docs/overview.md` — extraction section points at the core class
- `plugins/mailbox/includes/MailboxService.php` — `previewable` in the attachment payload
- `plugins/mailbox/includes/attachment_retrieval.php` — optional `locked` key
- `plugins/mailbox/assets/mailbox_reader.js` — chip restructure, preview button, preview modal
- `plugins/mailbox/assets/mailbox_reader.css` — chip container, preview button, modal styles
- `plugins/mailbox/includes/mailbox_reader_mount.php` — `attachmentTextUrl` config key
- `plugins/mailbox/plugin.json` — `mailbox_preview_max_bytes` setting
- `plugins/mailbox/docs/overview.md` — preview section under attachments

**Deleted**
- `plugins/joinery_ai/cli/extract_text.php`

## Tests

**`tests/unit/document_text_test.php`** (safe tier). Fixtures are generated at test time by `tests/fixtures/documents/generate_fixtures.php` (the research script, moved into the tree — it is a deliverable of this spec, not a leftover), so the suite carries no binary blobs.

Per format — docx, xlsx, pptx, odt, epub, rtf, html, xml, eml, ics, csv, json, markdown, plain, zip manifest:
- known text is recovered
- block boundaries are present (the defect-2 regression: a heading must not abut the paragraph after it)

Security regressions, each a named check:
- **XXE**: the entity fixture must not yield `/etc/passwd` content — the single most important assertion in the file
- **libxml flags, source-level**: `utils/extract_document_text.php` and `includes/DocumentText.php` contain no `LIBXML_NOENT`, `LIBXML_DTDLOAD`, or `LIBXML_PARSEHUGE` token anywhere, and `xmlText()`'s parse call does contain `LIBXML_NONET`
- **CLI guard**: `utils/extract_document_text.php` refuses a non-CLI SAPI (source-level check for the guard)
- **zip bomb**: the 61KB→60MB fixture is refused, not parsed
- **RTF metadata**: `\info` title/author and the font table are absent from output while body text survives
- **HTML**: `<script>` and `<style>` bodies absent from output
- **SVG**: text extracted, no markup in output

Contract:
- unsupported MIME → `SKIPPED`; unreadable path → `FAILED`
- oversized input truncates and carries the marker; truncation never splits a UTF-8 sequence
- a Latin-1 `.txt` fixture yields valid UTF-8 (`mb_check_encoding`) and survives `json_encode`
- `extractBytes()` and `extractPath()` agree on the same document
- `extractBytes()` leaves no file behind in the temp dir **or** `/dev/shm`
- `extractBytes()` returns promptly (not the full 20s) when the child rejects a large input immediately — the no-deadlock regression for the stream pump
- `refineContainerMime()` resolves a docx that sniffs as `application/zip`

**`plugins/mailbox/tests/attachment_preview_test.php`** (db tier)
- an octet-stream-declared `.pdf` is flagged `previewable` (the 71% case)
- a `.kdbx` declared octet-stream is not
- the endpoint refuses an attachment on an alias the viewer has no grant for
- a NULL-alias message refuses a non-superadmin
- the 31st request inside the window gets the 429, the 30th does not

**Regression:** the joinery_ai attachment tests must pass unchanged. That is the acceptance criterion for the move.

Timeout (124) and OOM (137) branches are not automatically tested — exercise them by hand against a crafted PDF and record the result here.

## Open questions

1. **Image attachments.** A different mechanism — an `<img>` at the existing signed URL, not extraction. Arguably no new exposure (email images already render in the sandboxed body frame), but a second promise to keep. Left out of v1.
2. **Widen the AI chat's accepted set** to the new formats? Free once this lands, but it changes what the model ingests, so it wants its own decision.
3. **Legacy `.doc`/`.xls`.** Absent from the real corpus here, but common in the wild. Supporting them means a new dependency. Worth it, or leave them to the download?
