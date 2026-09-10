# Parser jail: strangers' bytes never parse as the web user

**Status:** IMPLEMENTED 2026-09-10. Shipped in 0.8.384 and upgraded onto
every managed node the same day; dev installed by hand. The fence is proven
from inside (`tests/unit/document_text_test.php`) and outside
(`tests/security/parser_jail_gate.sh`) on dev with launcher 1.0.1, which
fixes the one defect the first jailed run found (see "As built"). Owner
decision: the fallback stays advisory; `DocumentText::JAIL_REQUIRED` is not
scheduled to flip. `docs/document_text.md` is the current-state record. The
Postfix pipe uid (S6) stays in the inventory; it is a permissions question
the read-only tree (S10) answers, not a parser question.

## What this closes

Mail from anyone on the internet arrives as bytes, and some of those bytes
are handed to C libraries: libzip opens the attachment, libxml2 parses the
spreadsheet inside it, the DMARC report, and the HTML of the message. A bug
in one of those libraries, reached by a crafted attachment, runs code as
whoever called the parser. Today that is `www-data` in every case, which is
the user that holds the database password, the config file, every open vault
window, and (until S10) a writable copy of the code tree.

After this spec, every byte that came from a stranger is parsed by a process
that runs as its own user, holds no credentials, has no network, cannot write
the code tree, and lives for one document. A parser bug then corrupts a
process that can read the bytes it was given and nothing else. This is route
2 of the malicious-message analysis (`vault_key_memory_exposure.md` § The
routes, ranked), and it turns route 3 (the same parsers inside php-fpm) into
the same thing.

## The shape today, verified 2026-09-10

**The extractor is already a subprocess.** `DocumentText::run()` spawns
`timeout 20 php -d memory_limit=256M utils/extract_document_text.php <path|-> <mime> <max>`
and reads `category=…`, a blank line, then text from its stdout. Sealed bytes
travel down stdin and never touch disk; container formats are staged to
`/dev/shm/joinery_doctext_*` and unlinked as soon as the archive is open. The
parent sweeps stale staging after every run. This is a memory and time fence
under the same uid: the child is `www-data`, sees the whole filesystem, and
can open sockets.

**The child boots more than it needs.** `utils/extract_document_text.php`
requires `PathHelper`, and the sandbox side calls
`PathHelper::getComposerAutoloadPath()` to load Horde and the PDF parser. That
reads the `composerAutoLoad` setting through `Globalvars`, which loads
`config/Globalvars_site.php` and queries `stg_settings`. So the process that
parses hostile bytes needs the config file and a database connection before
it has read one byte of the document. Under a jail uid neither is available,
and neither should be.

**Attachments are not parsed on arrival.** `DocumentText` is called from the
attachment preview (`attachment_text_logic.php`, php-fpm, on click) and from
the AI reading an attachment (`AiAttachment.php`, php-fpm for chat, cron for
recipes). Arrival uses only `DocumentText::toUtf8()`, the charset ladder,
which is pure PHP. The vault spec's sentence saying attachments run through
the extractor on arrival was wrong and is corrected with this spec.

**Two more C surfaces take stranger bytes, both in-process, one on arrival:**

| Surface | Library | Tier | When |
|---|---|---|---|
| `DocumentText` (attachment text) | libzip, libxml2, PDF parser (PHP) | php-fpm and cron, already a subprocess | preview click, AI attachment read |
| `DeliverabilityReportIngest` (DMARC aggregate, TLS-RPT, ARF) | libzip via `ZipArchive`, zlib via `gzdecode`, libxml2 via `DOMDocument` | Postfix pipe (CLI as www-data), cron, php-fpm (Refresh) | on arrival, in-process, for any message that looks like a report |
| `MailboxHtmlSanitizer` (`toReadableText`, `sanitizeForPrint`) | libxml2 HTML parser via `DOMDocument::loadHTML` | php-fpm | thread-list snippet when a message has no plain part; the print sheet |

The report ingest is the one that runs on arrival with no click and no
subprocess: a stranger's zip is opened by libzip inside the Postfix pipe
handler, and its XML is parsed by libxml2, in-process, before any human has
seen the message.

**Outside bytes that are not a stranger's mail, same libraries, listed so
they are decided rather than forgotten:**

| Surface | Library | Whose bytes |
|---|---|---|
| `FetchUrlTool::loadDom()` (AI `fetch_url`) | libxml2 HTML | any web page the model asks for |
| `inbound_email_filter_class.php:649` (feed filter) | libxml2 via `simplexml_load_string` | a feed at a URL the owner configured |
| `dns_filtering/logic/scan_url_logic.php`, `persona_browser/FacebookFeedExtractor.php` | libxml2 HTML | web pages |
| `mailbox/includes/import/{ZipReader,TarReader}.php` | libzip, `PharData` | an archive the owner uploaded to import |
| `PluginManager`, `upgrade.php`, `AbstractExtensionManager` | `PharData`, `ZipArchive` | a release or package we signed (S9) |

**What the host offers.** Checked on dev (Ubuntu 24.04) and in the container
image:

- `systemd-run` exists on bare metal but is useless from `www-data`: a system
  unit needs root or a polkit grant, and `--user` runs under the same uid.
- Unprivileged user namespaces are refused
  (`kernel.apparmor_restrict_unprivileged_userns = 1`; `unshare -Ur` fails
  with `Operation not permitted`), so bubblewrap without a root-installed
  AppArmor profile does not run. Docker's default seccomp profile refuses
  `unshare` inside a container as well.
- The container has no systemd and no `sudo`. Postfix and php-fpm are started
  by the image's start script.

So nothing available to `www-data` can change uid or drop capabilities. A
root-installed helper is required whichever shape is chosen.

## The design

### One root-installed launcher

A small setuid-root binary, `joinery-jail`, installed by root at
`/usr/local/sbin/joinery-jail` (mode `4755 root:root`, outside the tree), and a
system user `joinery-jail` with no shell, no home, no group memberships. The
launcher takes a command line and runs it as that user inside the fence. It
carries no policy about PHP or documents: `DocumentText` keeps deciding what
to run, with what memory limit, and for how long. The launcher decides only
what the child cannot reach.

What it does, in order, and nothing else:

1. Refuses to run unless invoked with a fixed shape (`joinery-jail -- <argv>`)
   and scrubs the environment to `PATH`, `LANG` and `TMPDIR`. A setuid
   binary that honours the caller's environment is a privilege bug waiting
   for a library that reads one.
2. `unshare(CLONE_NEWNET)`: a fresh, empty network namespace. Needs
   `CAP_SYS_ADMIN`, which a setuid-root process has on bare metal and does not
   have inside a Docker container (the default capability set drops it). Best
   effort: on refusal it proceeds, because step 4 closes the same door.
3. Sets resource limits the kernel enforces regardless of what PHP is told:
   address space (`RLIMIT_AS`, from an argument, default 512 MB), CPU seconds,
   process count (`RLIMIT_NPROC` 1, so the child cannot fork), file size
   (`RLIMIT_FSIZE`, enough for staging one document), open files.
4. `PR_SET_NO_NEW_PRIVS`, then a seccomp filter that returns `EPERM` for
   `socket`, `socketpair`, `connect`, `bind`, `ptrace`, `mount`, `umount2`,
   `pivot_root`, `chroot`, `setuid`/`setgid` families, `keyctl`, `bpf`, and
   `io_uring_setup`. No allow-list: PHP's syscall set is wide and changes
   between releases, and an allow-list that breaks on a PHP upgrade is a jail
   nobody keeps. The filter is installed before the uid drop so it applies to
   the exec and everything after; it survives `execve` by design.
5. Drops to `joinery-jail:joinery-jail` (`setgroups([])`, `setgid`, `setuid`,
   verified by reading them back), sets umask `077`, and `execve`s the
   command.

Written in Go, like the agent and the relay sealer, so it rides the same
build and release path and there is no C beneath it. Go's `syscall.Setuid`
applies to every thread since 1.16. The binary reads no file, parses no
input beyond its own argv, and makes no network call, so its attack surface
is the Go runtime's startup. About two hundred lines.

**Why not the alternatives.** A sudoers rule needs `sudo` in the container
image and gives no resource limits, no network drop and no seccomp; sudo is
a general tool with root reach where a fixed-purpose binary has none. A
socket-activated service under systemd is the right hardening on bare metal
and does not exist in the container, so it would be two implementations and
a wire protocol for a job the subprocess contract already does.

### The extractor changes

`DocumentText::run()` prefixes the command it builds today with
`joinery-jail --rlimit-as=<bytes> --`. Everything else on the parent side is
unchanged: same argv, same stdout contract, same exit codes, same `timeout`
for wall-clock.

Sandbox side:

- `utils/extract_document_text.php` takes the vendor directory as a fourth
  argument and never loads `Globalvars`. The parent resolves
  `PathHelper::getComposerVendorPath()` (it has the setting and the database)
  and passes the path. `DocumentText`'s two `require_once` of the composer
  autoload become one static that the script sets. The child then needs
  exactly: the code tree read-only, the vendor tree read-only, `/dev/shm`,
  and the bytes on stdin or at a path it can read.
- Staging stays in `/dev/shm`, created `0600` by the jail user under umask
  `077`, which is an improvement: today the pool can read a staged decrypted
  document; after this only the jail can. The stale-staging sweep moves into
  the sandbox side, run at startup, because the sticky bit on `/dev/shm`
  stops `www-data` unlinking another user's files. The property kept is the
  one that matters: a killed child's staging is removed by the next
  extraction, exactly as today.
- Reading `$path` (the non-stdin form, used by `extractPath` for files the
  caller owns) requires the file to be readable by the jail user. Callers
  that hold private files stream them on stdin; `extractPath` is kept for
  test fixtures and world-readable inputs, and refuses a path the jail cannot
  read with a clear message rather than a parse failure.

### Every surface goes through the same door

The extractor grows modes rather than the platform growing jails. Each
surface below becomes one `category` the sandbox side answers, with the
parent side reduced to "spawn, read the result":

| Surface | Mode | In | Out | In the 1.0 bar |
|---|---|---|---|---|
| Attachment text | as today | bytes, mime hint | text | yes (it is the existing contract) |
| Deliverability reports | `report` | the candidate part's bytes | one JSON document: the report type and the flat record `parseDmarcAggregate` / `parseTlsRpt` / `parseArf` file today | yes: it runs on arrival, unattended |
| Received HTML | `html-text` and `html-print` | HTML | readable text; sanitized print HTML | yes: `toReadableText` runs on the thread list |
| AI `fetch_url` | `html-read` | fetched HTML | the readable extraction the tool returns | yes: the AI reading strangers is the guardian's own surface |
| Feed filter, dns_filtering scan, persona_browser | `html-read` and `xml` | fetched HTML or XML | as their callers need | yes (owner 2026-09-10): same release, so the claim "no C parser on outside bytes" holds without exceptions |
| Import readers (owner's own archive) | — | | | no: the owner chose the bytes, and the import runs once under their eye |
| Release and package archives | — | | | no: S9 makes them signed; a signed archive is ours |

For `report` the DOM never exists in the pool: the jail parses the XML and
returns the fields, so `DeliverabilityReportIngest` keeps its filing code and
loses its `ZipArchive`, `gzdecode` and `DOMDocument` calls. Decompression caps
(`MAX_DECOMPRESSED_BYTES`) move with the parse. For the HTML modes the jail
returns strings; the sanitizer's rules do not change, only where they run.
Each mode is one section in `docs/document_text.md` with its contract.

### When the launcher is missing

A node that has not had the installer step (a self-hosted box upgraded by
`upgrade.php` as www-data, dev before the sudo step) has no launcher. The
end state is that every node has one, because the installer and the
agent-run upgrade put it there; the transition needs a rule:

- `DocumentText` falls back to the unjailed subprocess of today and logs
  once per process.
- `VaultHealth` (advisory, never a gate, as decided 2026-09-07) and
  `AdminNotices` carry one finding: "attachments and reports are parsed as
  the web user on this node; run `<command>` as root to install the jail."
- A later release, once the fleet has it, removes the fallback and makes a
  missing launcher a refusal to parse. That switch is one constant.

### Overhead

Measured on dev: a bare `php -r 'exit;'` is 0.12 s; the extractor on a
one-line text file is 0.18 s. The launcher adds one `execve` and a handful of
syscalls, well under a millisecond. Nothing on the Refresh path spawns it
except a message that is a deliverability report; the preview spawns on
click as it does today.

## What stays out, and why

**The MIME parse stays in-process** (owner confirmed 2026-09-10: no
security gain to pay the cost with). `MimeParse::parseMessage()` is Horde,
pure PHP. Its failure class is resource exhaustion and logic error, not
memory corruption, and the one hang it had (a body quoting its own boundary)
is caught by the pre-scan. Moving it means serialising a part tree with
decoded bodies across the boundary for every arriving message, on the
Postfix pipe, in cron, and under the Refresh button's 20-second budget, to
protect against a class of bug PHP does not have. The inventory's
separation 1 text says the jail takes the MIME parse; this spec narrows it
to the C parsers and records why. The `eml` category of the extractor
already parses MIME inside the subprocess for a forwarded message as an
attachment, so the door exists if the decision is ever reversed. The cost
of moving it, recorded so it is not re-derived: a process spawn per arriving
message (five Postfix slots, the Refresh budget), a wire format for the part
tree that three thousand lines of router walk as an object today, three
copies of every message in the caller's memory, and a versioned contract
between the jailed and the fallback split. The line is not "PHP versus C":
the splitter's base64 and charset helpers are C too, but they decode flat
byte streams with no structure to get wrong. The line is C libraries with a
memory-bug history parsing complicated structured formats, which is libzip,
libxml2 and the PDF parser.

**The Postfix pipe uid (S6)** is about what the pipe handler as a whole may
write, which is the read-only tree's question. With this spec the handler's
only C-parser reach (the report ingest) is jailed; what remains in the
handler is PHP under www-data, and S10 decides what www-data may write.

**The owner's own imports** parse in-process. A Takeout archive is bytes the
owner chose, under their eye, once. If that ever changes (an import fed by
mail), it is one more mode.

## Interfaces to the other items

- **S10, the read-only tree.** The jail user needs to read the code tree and
  vendor, which on a node today are `www-data:user1` mode 770. Until S10,
  the installer grants read with an ACL (`setfacl -R -m u:joinery-jail:rX`
  plus a default ACL on the directories so upgraded files inherit it). When
  S10 lands and the tree is root-owned and world-readable, the ACL is
  redundant and the installer stops setting it. Nothing in this spec
  writes the tree, so the two never conflict.
- **Uploads (`{site root}/uploads`, mode 777 from `fix_permissions.sh`).**
  The jail can read them, as any local uid can. Not this spec's door, but a
  jail that can read private uploads is not the end state: recorded as
  **B12** for S10, where uploads become `www-data`-only.
- **S6.** No dependency in either direction.
- **S13, the unseal daemon.** None. The jail never sees a key: sealed bytes
  are opened in the pool and travel down stdin, exactly as today.
- **S9 and the release channel.** The launcher is a root-installed binary,
  so it ships the way the relay sealer does: source in the tree, prebuilt
  `bin/joinery-jail-{x86_64,aarch64}` published with the release, installed
  and updated by a core host installer at the root moments the platform
  already has (container start, `install.sh`, an agent-run upgrade). On a self-hosted box upgraded by www-data
  the launcher is not updated, so it must stay stable: policy lives in PHP,
  the launcher is the fence and nothing else. Its checksum is in the release
  manifest like every other shipped file.
- **Containers.** The Dockerfile adds the user and the binary; step 2 of the
  launcher fails there and step 4 covers it. A container that grants
  `CAP_SYS_ADMIN` gets the namespace for free.
- **Dev.** Same as a node: one sudo run of the installer step. The fallback
  covers the interval and the notice names the command.
- **Wasm at the parsers** (the inventory's longer direction) replaces what
  runs *inside* the jail, not the jail: libzip and libxml2 compiled to wasm
  under a runtime would sit behind the same `joinery-jail --` prefix and the
  same stdout contract. This spec does not preclude it and does not need it.

## Decisions this spec takes

- **Q5 (mechanism):** a setuid-root launcher in Go, one implementation for
  bare metal and container, degrading only the network namespace inside a
  container. Not systemd-run, not bubblewrap, not sudo, not a service.
- **Q6 (what goes in):** every place outside bytes reach libzip, zlib or
  libxml2. Mail attachments, deliverability reports, received HTML and the
  AI's fetched pages are in the 1.0 bar. The MIME parse stays out.
- **The jail protects the pool from the parser, not the parser from the
  pool.** `www-data` can run `joinery-jail -- anything` and read what the
  jail user can read. That is the same set `www-data` reads today, minus
  nothing, and the escalation direction that matters is closed.

## Open questions

None. Settled 2026-09-10 with the owner: every outside-bytes caller moves in
the same release (the table above); the fallback for a missing launcher stays
advisory until the fleet carries it; the launcher runs any command. Both
memory limits stay: PHP's gives a clean "document too large" result, the
kernel's is the backstop for what the C library allocates, which PHP's limit
never sees.

## As built (2026-09-10)

Where the build differs from the design above, and why:

- **Two stages, not one.** The launcher forks itself: a supervisor and the
  fenced child. The design's `timeout` outside the launcher cannot work — once
  the child is `joinery-jail`, the web user cannot signal it — so the
  launcher takes `--timeout` and the supervisor, itself dropped to the jail
  user, kills the child on the deadline. Exit codes stay `timeout(1)`'s.
- **The uid drop precedes the seccomp filter.** The deny-list includes every
  `setuid` family, so step 4 and step 5 swap: drop, then
  `PR_SET_NO_NEW_PRIVS`, then the filter (which an unprivileged process may
  install under no-new-privs).
- **No `RLIMIT_NPROC`.** It counts processes per uid across the machine, so a
  limit of 1 would refuse the second concurrent extraction (five Postfix
  slots, php-fpm previews). Forking is refused by seccomp instead (`fork`,
  `vfork`, `clone`, `clone3`), which is the exact property wanted.
- **`extractPath()` streams.** The caller reads the file and sends the bytes
  down stdin like every other entry point; the jail user never opens a
  caller's path, so an uploaded temp file (0600, web user) and a private
  upload need no grant. The "refuse a path the jail cannot read" rule has no
  case left.
- **Modes are classes.** Rather than `report`/`html-text`/… strings inside
  the extractor, a caller hands `DocumentText::parseWith()` a class
  implementing `SandboxParserInterface`; the subprocess loads that one file
  (inside the tree) and runs it. The extractor stays free of plugin knowledge
  and a plugin owns its parser. `parseWithMany()` runs a batch in one spawn,
  which is what keeps the thread list (one page = one subprocess) and the
  search index fold (one chunk = one subprocess) at their previous cost.
- **The Gmail filter export went in too.** It is the owner's own upload, but
  the mechanical test's claim is cleaner with it: the exception list is the
  import readers, the package installers, our own templates and manifests,
  and provider API answers under our credentials.
- **A finding the mechanical test made on its first run:** the persona
  browser's `FacebookFeedExtractor` loaded HTML without `LIBXML_NONET`.
  Fixed with the move.
- **Read access is granted by the installer only where a probe says the jail
  user cannot already read the tree** (`runuser -u joinery-jail -- test -r`);
  a container's 755/644 tree needs no ACL, a node's 770 tree gets one, and
  the grant is re-checked at every root moment because an upgrade swaps a
  fresh `public_html` into place. Where the grant cannot be made the launcher
  is not installed and the advisory fallback stands, rather than a jail whose
  every extraction fails.
- **The address-space cap is applied by util-linux `prlimit`**, exec'd by
  the launcher as the last hop before the command. Launcher 1.0.0 set
  `RLIMIT_AS` on itself and died intermittently with "runtime: cannot
  allocate memory": a Go runtime maps memory lazily and a cap on its own
  address space can land before the exec. Found by the unit suite on the
  first jailed run on dev (2026-09-10), fixed in 1.0.1; the installer
  refuses a box without `prlimit`.
- **The address-space ceiling is 640 MB** (`JAIL_ADDRESS_SPACE_BYTES`):
  PHP's 256 MB plus what the interpreter (~140 MB before reading a byte) and
  the C libraries map outside it. Every fixture in the suite parses under it
  (`ulimit -v 655360`); the two refusals (bomb, XXE) are the same refusals.

## Work packages

1. **WP1 — the launcher.** Go source under
   `maintenance_scripts/install_tools/joinery_jail/`, prebuilt binaries in
   the tree, and a core host installer (`install_tools/install_parser_jail.sh`,
   run by `_plugin_installers_start.sh` after the agent's, at the root
   moments that already exist: container start, site build, node upgrade)
   that creates the user, installs the binary `4755 root:root`, and sets the
   ACL. Idempotent, exit 0 when not applicable, like every host installer. A shell gate that runs `joinery-jail -- id`, tries to
   write the tree, tries to open a socket, tries to fork, and expects each
   refusal.
2. **WP2 — the extractor under the jail.** `DocumentText` v1.3.0: the prefix,
   the vendor argument, the sandbox-side sweep, the missing-launcher
   fallback and its health finding. `extract_document_text.php` v1.1.0.
   `tests/unit/document_text_test.php` extended: staging is `0600` owned by
   the jail user; the child's uid is not www-data; a document that opens a
   socket fails.
3. **WP3 — reports through the jail.** The `report` mode; `DeliverabilityReportIngest`
   keeps filing and loses parsing. A mechanical test that no file outside
   `DocumentText.php` and the sandbox script names `ZipArchive`,
   `DOMDocument`, `simplexml_load_string`, `gzdecode` or `PharData` on a
   path outside the listed exceptions (import readers, package installers).
4. **WP4 — HTML and XML through the jail.** `html-text`, `html-print`,
   `html-read`, `xml`; `MailboxHtmlSanitizer`, `FetchUrlTool`, the feed
   filter, `scan_url_logic` and `FacebookFeedExtractor` call the extractor.
   The thread-list snippet path is measured before and after, since it runs
   per message without a plain part. After WP3 and WP4 the mechanical test's
   exception list is the import readers and the package installers only.
5. **WP5 — the record.** `docs/document_text.md` describes the launcher and
   the modes as the current state; the inventory's S5 row points here; the
   vault spec's arrival sentence corrected. The fallback-removal constant
   stays false (owner, 2026-09-10): self-hosted boxes without the agent are
   advised, never refused.
