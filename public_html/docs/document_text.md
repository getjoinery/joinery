# Document Text

`DocumentText` is the platform's one answer to **"what does this file say?"** — the words inside a PDF, a spreadsheet, a contract, a forwarded email, without opening the file as a document. It is also the one door through which any outside bytes reach a C parser: a deliverability report, the HTML of a received message, a page the AI fetched, a captured feed.

It exists because reading a stranger's bytes otherwise means handing them to a program that will act on them, as the user that runs the site. Anywhere the platform needs a file's text — a mail attachment preview, a chat upload fed to a model, a report to file, a list snippet — it asks here, and gets text back or an honest reason why not.

## The two sides, and why the split is the design

**The parent side** runs in a web request, a cron task, or the Postfix pipe. It answers "could this be read?" and starts the reader. It never opens the bytes: not with `finfo`, not with `ZipArchive`, not with `DOMDocument`.

```php
DocumentText::isExtractable($mime);            // is this a type we can read?
DocumentText::categoryForMime($mime);          // 'pdf' | 'docx' | 'text' | …
DocumentText::mimeForExtension('invoice.pdf'); // what a filename claims
DocumentText::bestMimeGuess($declared, $filename);

DocumentText::extractPath($path, $mime, $maxChars);
DocumentText::extractBytes($bytes, $mime, $maxChars);

DocumentText::parseWith($class, $bytes, $options);       // a SandboxParserInterface class, one input
DocumentText::parseWithMany($class, $inputs, $options);  // the same class, many inputs, one subprocess
```

**The sandbox side** is `utils/extract_document_text.php`, a short-lived subprocess. Every byte of the document is opened there and nowhere else — sniffing, unpacking, and parsing included. A bomb, a hang, or a runaway allocation kills only that child; the parent reads the exit code and reports the file unreadable.

The isolation is not politeness. An in-process memory fatal is uncatchable, and the PDF parser retains per-document state that accumulates across files in a long-lived process — a loop over 54 real PDFs peaked at 512MB in one process versus 18–35MB per file in isolation. The subprocess is both the security boundary and the memory boundary.

Both extraction entry points return the same array and never throw:

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

## The parser jail

The subprocess runs under **the parser jail** wherever the launcher is installed: `/usr/local/sbin/joinery-jail`, a small setuid-root Go program (`maintenance_scripts/install_tools/joinery_jail/`) that takes one command line and runs it as the `joinery-jail` user inside a fence the command cannot leave. `DocumentText` prefixes its command with `joinery-jail --timeout=20 --rlimit-as=<bytes> --`; the launcher carries no policy about PHP or documents. In order, it:

1. Discards the caller's environment and replaces it (`PATH`, `LANG`, `TMPDIR=/dev/shm`).
2. Enters a fresh, empty network namespace. This needs `CAP_SYS_ADMIN`, which a setuid-root process has on bare metal and lacks inside a default container; step 5 closes the same door either way.
3. Sets kernel limits the command inherits: CPU seconds, file size, open files, no core dumps. The address space (`--rlimit-as`, the backstop for what libzip, libxml2 and the interpreter map outside PHP's own `memory_limit`) is applied by util-linux `prlimit` as the last hop before the command, because a Go process cannot cap its own address space without risking its runtime.
4. Becomes `joinery-jail`: no supplementary groups, verified by reading the ids back, not dumpable.
5. Sets `PR_SET_NO_NEW_PRIVS` and installs a seccomp filter that answers `EPERM` to `socket`/`connect`/`bind`, `fork`/`clone`/`clone3`, `ptrace`, every mount and namespace call, every `setuid` family, `keyctl`, `bpf`, `io_uring_setup`, module loading and the like; a syscall from another architecture's table is killed outright. It is a deny-list on purpose: PHP's syscall set is wide and changes between releases, and an allow-list that breaks on a PHP upgrade is a jail nobody keeps.
6. Sets umask `077` and execs the command.

The launcher is two stages in one binary. The first is a supervisor: it starts the fenced child, drops its own privileges to the jail user, and waits with the deadline, killing the child on expiry. The wall-clock has to be enforced by something that can still signal the child after the uid change — the web user cannot signal a `joinery-jail` process — and the supervisor holds no root while it waits. Exit codes follow `timeout(1)`: the command's own, 124 on the deadline, 128+signal when it died of one, 125 when the launcher refused.

What the jailed subprocess has: the code tree and the vendor directory (read-only, granted by ACL where the tree is not world-readable), `/dev/shm`, the bytes on stdin, and the vendor path on its argv. What it does not have: the config file, a database connection, a key, a network, another process. `utils/extract_document_text.php` calls `ClassAutoloader::restrictToCore()` and `PathHelper::setComposerVendorPath()` before anything else, so nothing on the sandbox side ever asks for a setting — under the jail that ask would be a fatal error, not an exception. The jail protects the pool from the parser, not the parser from the pool: the web user can run `joinery-jail -- anything`, and what it can read as the jail user is a subset of what it reads already.

**Installing it.** `maintenance_scripts/install_tools/install_parser_jail.sh` is a core host installer, run by `_plugin_installers_start.sh` after the agent's at every root moment the platform has — container start, site build, an agent-run upgrade, the Run Plugin Installers action — and by hand on a box those never reached:

```bash
sudo bash /var/www/html/SITE/maintenance_scripts/install_tools/install_parser_jail.sh
```

It creates the user, grants the read ACL where the probe says the user cannot already read the tree (a container's tree is world-readable and needs none), installs the prebuilt `joinery_jail/bin/joinery-jail-<uname -m>` at `4755 root:root`, and runs one command through the fence to check. On the management node itself, whose tree is the source and is never upgraded, a publish queues that same installer run on the node's own agent, so a new launcher lands there without a shell. Nothing is compiled on a node: `ParserJailPublisher` cross-compiles both architectures at publish time, and a stale launcher refuses the release.

**Without it.** A self-hosted box upgraded from the browser gets the launcher from the host converger, the root timer every site install leaves behind (`docs/deploy_and_upgrade.md`), within five minutes of the upgrade. A node that has not had the installer runs the same subprocess as the web user under `timeout`, and says so: once per process in the error log, as an `unmet` check in `VaultHealth`, and to superadmins in the admin header (`ParserJailNotice`) with the command above. `DocumentText::JAIL_REQUIRED` would turn a missing launcher into a refusal to parse; it stays false, because a self-hosted box whose owner never runs the installer keeps the previous posture rather than losing previews, reports and fetched pages. Managed nodes are always jailed: the agent-run upgrade installs it.

## Sandbox parsers

The document formats are `DocumentText`'s own. Anything else that opens outside bytes with a C parser is a class implementing `SandboxParserInterface`:

```php
class DeliverabilityReportParser implements SandboxParserInterface {
    public static function sandboxParse(string $bytes, array $options): string { … }
}

$r = DocumentText::parseWith('DeliverabilityReportParser', $bytes);   // $r['text'] is what sandboxParse() returned
$texts = DocumentText::parseWithMany('MailboxHtmlSanitizer', $htmls, ['op' => 'readable']);  // keyed like $htmls, null per failure
```

The parent side resolves the class to its file and hands the subprocess the path, the class name, and the options as JSON; the subprocess loads that one file (it must be inside the code tree), checks the class implements the interface, and calls `sandboxParse()` with the bytes from stdin. What comes back is a string: text, a fragment, or a JSON document for structured results. `parseWithMany()` sends many inputs as one JSON array and gets one array back — a page of fifty list rows is one spawn, not fifty.

What a `sandboxParse()` may rely on, and nothing more: the bytes, the options, core classes under `includes/` (`DocumentText::xmlDoc()` is the one XML door), and its own file. It must not name a plugin class, read a setting, or reach for a model; it runs with none of them. Throw `DocumentTextException` to refuse with a kind; any other throw is a failed parse.

The parsers in the tree:

| Class | Opens | Returns | For |
|---|---|---|---|
| `DeliverabilityReportParser` (mailbox) | a report attachment: zip or gzip holding DMARC XML, or TLS-RPT JSON | `{kind, extract, error}` — the flat record the ingest files | mail arriving on the Postfix pipe, in cron, and on Refresh |
| `MailboxHtmlSanitizer` (mailbox) | received HTML | readable one-line text (`op: readable`), or the print sheet's sanitized fragment (`op: print`) | the thread list and search index, the print sheet |
| `GmailFilterExportParser` (mailbox) | a Gmail `mailFilters.xml` export | `{entries: [[name, value], …]}` | the filter import |
| `FetchUrlReader` (joinery_ai) | a fetched web page | `{text, note}` — reader-mode Markdown with its fallbacks, or the full flatten | the AI's `fetch_url` tool |
| `ScanUrlPageResources` (dns_filtering) | a fetched web page | the list of resource URLs it refers to | the URL scan |
| `FacebookFeedExtractor` (persona_browser) | a captured feed's markup | `{items, stories}` | the persona browser |

`tests/security/parser_surfaces_test.php` pins the rule: a file that names `ZipArchive`, `DOMDocument`, `loadHTML`, `simplexml_load_string`, `gzdecode` or `PharData` is a sandbox parser, `DocumentText` itself, or on that test's list with the reason its bytes are not a stranger's (a package we signed, the owner's own import, a provider's API answer under our credentials).

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

1. **Everything that touches the bytes runs in the subprocess, as the jail user.** Including type detection: `libmagic`, `libzip` and `libxml` are C, which is exactly why they run nowhere else. A parser bug corrupts a process that can read the bytes it was given and nothing else.

2. **Detected type, never declared.** The sandbox sniffs the bytes with `finfo_buffer` and parses only what it found. The MIME the caller passes is a hint, consulted when detection is inconclusive. This matters because senders lie: most real PDFs arrive declared `application/octet-stream`.

3. **One XML door.** `DocumentText::xmlDoc()` holds the class's single `loadXML()` call; every XML-reading branch, and every sandbox parser, goes through it. It passes `LIBXML_NONET` affirmatively and never passes `LIBXML_NOENT`, `LIBXML_DTDLOAD` or `LIBXML_PARSEHUGE`. That first omission is load-bearing: with `LIBXML_NOENT`, an entity declaring `SYSTEM "file:///etc/passwd"` puts that file's contents straight into the extracted text; without it the same document is inert. A branch or a parser that calls `loadXML()` for itself is a defect, and the tests fail on one at the source level.

4. **Containers are bounded before they are read** — per-member size (12MB), summed size (40MB), compression ratio (200:1 above 1MB), and a cap on parts. A 61KB file expanding to 60MB is refused, not discovered by the memory limit.

5. **Nothing is written to disk.** Every entry point sends the bytes down the subprocess's stdin — `extractPath()` reads the file in the caller and streams it too, so the jail user never needs to open a caller's path — and decrypted content never lands on disk-backed storage. `ZipArchive` can only open a file, so a container is staged on `/dev/shm` (memory-backed tmpfs), `0600` and the jail user's own, and unlinked the moment the parser has it open — the open handle keeps reading, so a timeout or kill mid-parse leaves nothing behind. Each child sweeps, at startup, any staging a killed predecessor left older than a live child could still be holding; it is the child that sweeps because `/dev/shm` is sticky and only the user that staged a file may remove it. A missing or unwritable `/dev/shm` is a refusal, never a fallback to `/tmp`.

6. **Metadata is not content.** RTF `\info` (title, author), OOXML `docProps`, and EPUB OPF metadata are excluded. An RTF stripped with a regex leaks all three, because destination groups nest — the RTF branch is a brace-depth state machine for exactly that reason.

7. **Output is always valid UTF-8.** The text crosses a JSON boundary and `json_encode()` fails outright on a malformed sequence, so charsets are converted with substitution and truncation is multibyte-safe.

## Calling it

The parent must not write all of stdin before reading: up to 15MB can go down that pipe, and a child that rejects the input early and writes to stdout leaves both sides blocked, which the deadline turns into a guaranteed 20-second stall. `extractBytes()` pumps with `stream_select` for this reason. Callers get that for free — the point is not to reimplement the spawn.

**Consumers should apply their own byte ceiling before calling**, because the cheapest parse is the one that never starts. And a caller with many documents should call once: `parseWithMany()` for a parser class, one spawn per page or per chunk of a fold, never one per row.

Measured on dev: a bare `php -r 'exit;'` is 0.12 s and the extractor on a one-line text file 0.18 s; the launcher adds one `execve` and a handful of syscalls.

### joinery_ai

`AiAttachment` owns policy — which types the chat accepts, per-category byte caps, model capability gating, untrusted-input framing — and delegates every read here. Its four stored statuses (`aia_extract_status`) are aliases of the core constants; `secured` and `too_large` collapse to `failed`, because for send-time routing an encrypted PDF is simply one with no readable text, and `blocksForAttachment()` then offers the original to a document-capable model.

The chat's accepted set is `AiAttachment::CATEGORY`, and it stays narrower than what this class can read — an upload there becomes part of a model payload, so what the chat accepts is a decision about what a model ingests, not a consequence of the extractor learning a format. Archives are refused there for that reason, and so is SVG.

Two rules make the container formats safe to accept. `resolveUploadMime()` consults the filename **only** when detection landed on a bare container and the name claims a format built on one — a real need, because Office and OpenDocument files are zips and libmagic tells them apart by convention. It can never reach `image` or `pdf`, the categories that send raw bytes to a model. And whatever gets past that, `categoryForCoreCategory()` compares the category this class reports after actually opening the bytes against the one ingress accepted, so a plain zip renamed `.docx` is refused after the fact.

`fetch_url` downloads a page in the pool with every URL checked, converts the charset, and hands the body to `FetchUrlReader`; the DOM walk, the reader-mode escalation and the full-page flatten all run in the jail.

### mailbox

The reader's Preview button calls `mailbox/attachment_text`, which gates on mailbox-grant scope exactly as the download endpoint does, applies `mailbox_preview_max_bytes`, throttles per IP, and hands the bytes here. A deliverability report is recognised and filed by `DeliverabilityReportIngest`, which never opens the attachment: `DeliverabilityReportParser` does, in the jail, and answers with the record. The thread list's HTML-only previews and the search index's HTML bodies go through `MailboxHtmlSanitizer::toReadableTextMany()`, one subprocess per page or per chunk of a fold. See the [Mailbox plugin overview](../plugins/mailbox/docs/overview.md).

## Tests

`tests/unit/document_text_test.php` (safe tier). Fixtures are generated at run time by `tests/fixtures/documents/generate_fixtures.php`, so the suite carries no binary blobs and each fixture's tricky bit stays visible in source: the `sharedStrings` indirection, the RTF `\info` group, the XML that tries to read `/etc/passwd`, the 61KB zip that expands to 60MB. Its last sections exercise `parseWith()`/`parseWithMany()` through `JailProbeParser`, a fixture class that reports what the subprocess can do, and — where the launcher is installed — prove the fence from inside: the uid, no socket, no fork, no write into the tree, staging `0600`.

`tests/security/parser_jail_gate.sh` (safe tier, `needs: [parser-jail]`) proves the launcher from outside on a box that has it: the setuid install, the scrubbed environment, the refusals, the deadline, the address-space cap, and one extraction through the fence. `tests/security/parser_surfaces_test.php` pins that no file in the pool names a C parser on outside bytes. `tests/vault/vault_health_test.php` covers the health check.

The OOM branch (exit 137) is not covered automatically; exercise it by hand against a crafted file.
