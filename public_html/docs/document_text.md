# Document Text

`DocumentText` is the platform's one answer to **"what does this file say?"** — the words inside a PDF, a spreadsheet, a contract, a forwarded email, without opening the file as a document.

It exists because reading an attachment otherwise means handing attacker-supplied bytes to a program that will act on them. Anywhere the platform needs a file's text — a mail attachment preview, a chat upload fed to a model, search over stored documents — it asks here, and gets text back or an honest reason why not.

## The two sides, and why the split is the design

**The parent side** runs in a web request. It answers "could this be read?" and starts the reader. It never opens the bytes: not with `finfo`, not with `ZipArchive`, not with `DOMDocument`.

```php
DocumentText::isExtractable($mime);            // is this a type we can read?
DocumentText::categoryForMime($mime);          // 'pdf' | 'docx' | 'text' | …
DocumentText::mimeForExtension('invoice.pdf'); // what a filename claims
DocumentText::bestMimeGuess($declared, $filename);

DocumentText::extractPath($path, $mime, $maxChars);
DocumentText::extractBytes($bytes, $mime, $maxChars);
```

**The sandbox side** is `utils/extract_document_text.php`, a short-lived subprocess launched as `timeout 20 php -d memory_limit=256M`. Every byte of the document is opened there and nowhere else — sniffing, unpacking, and parsing included. A bomb, a hang, or a runaway allocation kills only that child; the parent reads the exit code and reports the file unreadable.

The isolation is not politeness. An in-process memory fatal is uncatchable, and the PDF parser retains per-document state that accumulates across files in a long-lived process — a loop over 54 real PDFs peaked at 512MB in one process versus 18–35MB per file in isolation. The subprocess is both the security boundary and the memory boundary.

Both entry points return the same array and never throw:

```php
['status' => …, 'category' => …, 'text' => …, 'detail' => …]
```

| `status` | Means |
|---|---|
| `ok` | text is present |
| `empty` | parsed cleanly, but there is no text layer (a scanned document) |
| `secured` | encrypted or permission-restricted; the parser refuses |
| `failed` | parser error, timeout, or out of memory |
| `skipped` | not a type anything here can read |
| `too_large` | over the caller's byte ceiling, never parsed |

`empty`, `secured` and `failed` are kept apart because they are different facts a user acts on differently: download it and read the pages, find the password, or give up. Roughly a third of real PDFs land on one of the first two.

## Formats

| Category | Reads |
|---|---|
| `pdf` | PDF, via pure-PHP `smalot/pdfparser` |
| `html` | HTML and XHTML — visible text, with `script`/`style`/`head` removed |
| `text` | plain text, Markdown, CSV, JSON, YAML, TOML, SQL, shell, and any other `text/*` |
| `docx` | Word — body, headers, footers, footnotes, endnotes |
| `xlsx` | Excel — rows tab-separated, sheets labelled, cell text resolved through `sharedStrings` |
| `pptx` | PowerPoint — per-slide text plus speaker notes |
| `odf` | OpenDocument text, spreadsheet, presentation |
| `epub` | EPUB — chapters through the HTML branch |
| `rtf` | RTF, body text only |
| `xml` | XML and SVG — text values, one per line |
| `eml` | a forwarded message: From/To/Cc/Date/Subject plus the text body |
| `ics` | a calendar invite, as a readable event summary |
| `archive` | ZIP — a **manifest** of entry names, sizes and dates. Nothing inside is decompressed |

The office formats need no dependency at all: OOXML, OpenDocument and EPUB are ZIP plus XML, and `zip`, `dom` and `libxml` are already loaded.

**Not read, deliberately:** legacy binary Office (`.doc`, `.xls`, `.ppt`), Outlook `.msg`, iWork, `.7z`/`.rar`, and encrypted databases like `.kdbx`. Each of the first four needs a dependency the platform does not have; the last is encrypted and correctly unreadable. Images are not read either — there is no OCR anywhere on the platform.

## The security properties

1. **Everything that touches the bytes runs in the subprocess.** Including type detection: `libmagic`, `libzip` and `libxml` are C, which is exactly why they run nowhere else.

2. **Detected type, never declared.** The sandbox sniffs the bytes with `finfo_buffer` and parses only what it found. The MIME the caller passes is a hint, consulted when detection is inconclusive. This matters because senders lie: most real PDFs arrive declared `application/octet-stream`.

3. **One XML door.** `DocumentText::xmlDoc()` holds the class's single `loadXML()` call; every XML-reading branch goes through it. It passes `LIBXML_NONET` affirmatively and never passes `LIBXML_NOENT`, `LIBXML_DTDLOAD` or `LIBXML_PARSEHUGE`. That first omission is load-bearing: with `LIBXML_NOENT`, an entity declaring `SYSTEM "file:///etc/passwd"` puts that file's contents straight into the extracted text; without it the same document is inert. A branch that calls `loadXML()` for itself is a defect, and `tests/unit/document_text_test.php` fails on one at the source level.

4. **Containers are bounded before they are read** — per-member size (12MB), summed size (40MB), compression ratio (200:1 above 1MB), and a cap on parts. A 61KB file expanding to 60MB is refused, not discovered by the memory limit.

5. **Nothing is written to disk.** `extractBytes()` sends the document down the subprocess's stdin, so decrypted content — a sealed mail attachment, say — never lands on disk-backed storage. `ZipArchive` can only open a file, so a container is staged on `/dev/shm` (memory-backed tmpfs) and unlinked the moment the parser has it open — the open handle keeps reading, so a timeout or OOM kill mid-parse leaves nothing behind. The parent also sweeps stale staging older than the subprocess could still be holding, covering a kill in the instant before that unlink. A missing or unwritable `/dev/shm` is a refusal, never a fallback to `/tmp`.

6. **Metadata is not content.** RTF `\info` (title, author), OOXML `docProps`, and EPUB OPF metadata are excluded. An RTF stripped with a regex leaks all three, because destination groups nest — the RTF branch is a brace-depth state machine for exactly that reason.

7. **Output is always valid UTF-8.** The text crosses a JSON boundary and `json_encode()` fails outright on a malformed sequence, so charsets are converted with substitution and truncation is multibyte-safe.

## Calling it

The parent must not write all of stdin before reading: up to 15MB can go down that pipe, and a child that rejects the input early and writes to stdout leaves both sides blocked, which `timeout` turns into a guaranteed 20-second stall. `extractBytes()` pumps with `stream_select` for this reason. Callers get that for free — the point is not to reimplement the spawn.

**Consumers should apply their own byte ceiling before calling**, because the cheapest parse is the one that never starts.

### joinery_ai

`AiAttachment` owns policy — which types the chat accepts, per-category byte caps, model capability gating, untrusted-input framing — and delegates every read here. Its four stored statuses (`aia_extract_status`) are aliases of the core constants; `secured` and `too_large` collapse to `failed`, because for send-time routing an encrypted PDF is simply one with no readable text, and `blocksForAttachment()` then offers the original to a document-capable model.

The chat's accepted set is `AiAttachment::CATEGORY`, and it stays narrower than what this class can read — an upload there becomes part of a model payload, so what the chat accepts is a decision about what a model ingests, not a consequence of the extractor learning a format. Archives are refused there for that reason, and so is SVG.

Two rules make the container formats safe to accept. `resolveUploadMime()` consults the filename **only** when detection landed on a bare container and the name claims a format built on one — a real need, because Office and OpenDocument files are zips and libmagic tells them apart by convention. It can never reach `image` or `pdf`, the categories that send raw bytes to a model. And whatever gets past that, `categoryForCoreCategory()` compares the category this class reports after actually opening the bytes against the one ingress accepted, so a plain zip renamed `.docx` is refused after the fact.

### mailbox

The reader's Preview button calls `mailbox/attachment_text`, which gates on mailbox-grant scope exactly as the download endpoint does, applies `mailbox_preview_max_bytes`, throttles per IP, and hands the bytes here. See the [Mailbox plugin overview](../plugins/mailbox/docs/overview.md).

## Tests

`tests/unit/document_text_test.php` (safe tier). Fixtures are generated at run time by `tests/fixtures/documents/generate_fixtures.php`, so the suite carries no binary blobs and each fixture's tricky bit stays visible in source: the `sharedStrings` indirection, the RTF `\info` group, the XML that tries to read `/etc/passwd`, the 61KB zip that expands to 60MB.

The timeout (exit 124) and OOM (exit 137) branches are not covered automatically; exercise them by hand against a crafted file.
