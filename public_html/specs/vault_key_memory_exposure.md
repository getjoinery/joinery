# Vault key and plaintext exposure in node memory

**Status:** Open. Findings from a 2026-09-07 review of what a sealed mailbox
actually exposes on a running node while an unlock window is live, plus a
second-reader pass the same day that corrected two facts and added two
findings. The contained fixes (index file mode, health-check accuracy,
encrypted swap, apport) moved to `vault_exposure_quick_fixes.md`; this spec
keeps the structural questions.

A 2026-09-09 pass added two admin-only code paths that need no plugin (B5,
B6), a ranked account of how a stranger's email reaches the pool, and
the ranked inventory now kept in `security_inventory.md`.

**Declined, kept for reference (owner, 2026-09-07):** nothing in this spec or
its child blocks raising a mailbox to Private or Fortress (the level then called
Fortress; now Private's mail add-ons). Health checks and
the "unsigned plugins present" finding are surfaced as information with a
remediation, never as a gate on the protection ceremony.

## What this is about

A Private mailbox (with or without its add-ons) promises the stored mail cannot be read without
the owner. That promise is about mail *at rest*. While the owner is signed in
and their vault window is open, some of it is necessarily not at rest — and
what that "some" turns out to be is wider than the sentence suggests.

## What is unencrypted during an open window

Three tiers, with very different blast radius.

**The vault secret key.** The owner's unwrapped X25519 secret key sits in the
php-fpm master's APCu shared memory under `vault:{session_id}:{user_id}:{scope}`
for the life of the window (`vault_unlock_idle_minutes`, default 30, idle-
extended). Anonymous shared memory, never a file. Whoever reads it decrypts
everything sealed to that user — every mailbox, every sealed Drive file, every
protected chat, past and future, and can do so **off the box, forever**, against
any copy of the database.

Two facts about that segment the first draft missed:

- **It is shared by every pool and every site the php-fpm master serves.** Code
  running for one site can iterate another site's open windows. Managed nodes
  run one site per container; dev serves three sites through one pool; a
  self-hoster serving several sites from one php-fpm has no separation.
- **Closing a window does not erase the key.** `apcu_delete` and TTL expiry
  mark the slot free; the bytes remain until the allocator reuses them.
  `sodium_memzero` reaches the PHP copy only. The anonymous-memory, no-core,
  encrypted-swap trio guards that residue as much as the live window.

**Plaintext of what the owner touched.** One request's worth: the thread page's
subjects and snippets, the opened body. Freed at request end but not zeroed —
`sodium_memzero` is used for keys, not for content — so it lingers in that
worker's heap until reused. A search fold widens this considerably: it decrypts
every not-yet-indexed message in batches of 200 to tokenize it, and the in-window
deferred-work drain (`mailbox_fts_fold`) runs folds on the vault heartbeat, not
only when the owner searches.

**The search index — every message, not just the inbox.**
`/dev/shm/mailfts_{user_id}.sqlite`, restored from its sealed blob the moment the
vault opens, covering every stored message in every mailbox the owner holds,
trash included (drafts excepted). Since c021389d (2026-09-01) the FTS5 table is
contentless with `detail=none`, so it holds the complete vocabulary of the
owner's mail plus which message ids contain each term — no verbatim text, no word
order. **A node running any release older than that holds the full message text
in that file.** The file is created 0644 in a 1777 tmpfs; the fix is
`vault_exposure_quick_fixes.md` Q1 (a chmod — the drain runs in the web tier,
so there is no cross-user file to share).

## Host hardening is advisory, and the checks were wrong in both directions

`VaultHealth` checks the three facts that keep an unwrapped key off disk during a
window: APCu on anonymous shared memory, core dumps disabled for the worker, and
swap off or encrypted. It is advisory only, surfaced from `vault_setup_verify`
and `check_vault_health.php`, and stays advisory (declined gate, above).

What the second pass found, all moved to the quick-fixes spec:

- The core-dump check passes on Ubuntu while cores are still kept: the kernel
  ignores the rlimit for a pipe `core_pattern`, and apport keeps the core in
  its `/var/crash` report. (Q2)
- The unencrypted swap is fleet-wide, not a dev quirk: the install's
  housekeeping step creates a plain 2 GB swapfile on every node, and Linode's
  image ships a plain 512 MB swap disk for boxes installed before it existed.
  Encrypting with a per-boot random key is the fix. The 2 GB was never
  derived; the quick-fixes spec makes it a flat 1 GB and adds swap to node
  telemetry. Swap stays on: customer nodes are 1 GB nanodes. (Q5)
- `zend.exception_ignore_args` keeps key bytes out of exception traces and is
  asserted nowhere. (Q3)

## The real exposure: code in the pool is the key

Neither the file mode nor the host checks are the main event. The main event is
that **anything able to execute PHP under the php-fpm master reads the vault key
with one `apcu_fetch`**, for every user with a window open at that moment — not
only its own, and not only its own site.

The shortest path to that today needs no shell and no SSH: a permission-10 admin
uploads a plugin ZIP at `/admin/admin_plugins` (or installs one from the
marketplace) and it runs as www-data on the next request.
`adm/logic/admin_plugins_logic.php` gates at permission 10, which is the intended
gate — the problem is what is on the other side of it.

**Web-tier code is the one code path on a node that nothing signs.** The
discipline exists everywhere else: the agent refuses a binary the publisher did
not sign, script primitives verify against `RELEASE_MANIFEST` and its signature,
and the agent's trust root is a key held root-only on the management node. A
plugin ZIP is checked for none of this — `PluginManager` and `MarketplaceClient`
contain no signature verification, and no PHP-side manifest verifier exists in
the tree at all (only the Go agent and `AgentDistPublisher` call sign-verify).
Root-side code has a trust root; the code with the vault key in its address
space does not.

**B3 — activation fetches more code, unsigned.** Switching a plugin on runs
`ComposerValidator::reconcilePluginPackages()` (`PluginManager.php:740`), which
shells out to `composer require` against packagist as www-data
(`ComposerValidator.php:492`). A signed archive would verify what the admin
uploaded and still pull unverified libraries at activation. Any signing scheme
has to cover this: either vendor ships inside the signed archive, or composer
never runs on a node.

**B4 — the webroot is writable by the web user.** `fix_permissions.sh` leaves
the tree `www-data:user1` mode 770, and `upgrade.php`, plugin and theme installs
write through the web user. So a transient break-in (a bug exploited per
request) can persist itself as a file, and install-time signing has nothing
underneath it. See Mitigation C.

### The plugin ZIP is not the only door (2026-09-09)

Assume no plugin is ever uploaded. Two admin pages still turn a browser session
into PHP in the pool today, and neither asks for a step-up or a vault window:

**B5 — the agent-files editor writes any root-level file.** `/admin/admin_agent_files`
(permission 10) renders the "Internal CLAUDE.md" record to disk.
`AgentFile::validate_target_filename()` (data/agent_files_class.php:74) refuses
only path separators, `..` and NUL, and the destination is
`PathHelper::getRootDir() . '/' . $filename` — `public_html`. So `serve.php`,
`index.php`, `settings.json` and `admin_menus.json` are all valid targets, and
overwriting the front controller runs the record's content on the next request.
Fix: an allowlist of documentation names (`*.md` only), never an executable or
manifest extension.

**B6 — the settings page is a code path at permission 8.**
`adm/logic/admin_settings_logic.php:16` gates at `check_permission(8)`, below
the level that installs plugins. Two free-text settings there compose into
execution: `allowed_upload_extensions` (settings.json:912) and
`composerAutoLoad` (settings.json:1143). Neither is vault-gated
(`VaultGatedSettings` lists none of core's names) and `SettingsWriter` applies no
per-setting permission. The chain: add `php` to the extensions list; upload a file
named `autoload.php` through `admin_file_upload_process` (permission 5); set
`composerAutoLoad` to the upload directory. `PathHelper::getComposerAutoloadPath()`
(includes/PathHelper.php:54) is `getRootDir() . setting . 'autoload.php'`, with
no containment check, and `data/users_class.php` requires it on nearly every
request. Fix: refuse `php`, `phtml`, `phar` (and any extension the FPM handler
matches) in the extensions list at write time; refuse a composer path that does
not resolve inside the site root's `vendor/`; and treat both settings as
credential events per Mitigation B (fresh step-up, audit row,
`VaultUnlock::lockAll()`).

**What this changes about Mitigation B.** Package signing closes one instance of
a class. The class is *any admin page that writes a file the pool will execute,
or a setting the pool will `require`*. B needs an inventory of those pages, not
just a verifier for archives; B5 and B6 are the two found so far, and the
composer setting is the pattern to grep for: any setting whose value becomes a
path handed to `require`, `include`, `exec` or `ZipArchive::open`.

### Ranked: how an attacker most likely reaches the pool

With no plugin upload in the picture, in order of likelihood:

1. **A stolen admin credential, then B5 or B6.** Phishing, a reused password, or
   malware on the admin's machine. No exploit needed; permission 8 suffices for
   B6. Every window open on the pool at that moment is read, and on dev that is
   three sites' windows.
2. **A parser bug reached by a stranger's email.** Inbound mail is parsed on
   arrival (MIME in pure PHP; a deliverability report's zip and XML through
   libzip and libxml2, in-process), and an attachment is run through
   `DocumentText` when the owner previews it or the AI reads it. Those run in
   the Postfix pipe, the cron tier and php-fpm alike, all as www-data — and B4
   makes the webroot writable, so a break-in in any of them drops a file that
   runs in the pool on the next request. See § A malicious message, below,
   and `parser_jail.md` for the close.
3. **A disk image, no exploit.** Key residue survives in plain swap after a
   window closes (dev's 496 MB swap is 99.9 % used today) and apport keeps
   worker cores. A snapshot, a host backup or a retired disk is carved offline.
   Provider-side access needed; undetectable from the node. Only Mitigation A
   closes this.
4. **Root through the plane.** By design, out of scope.

### What is worth protecting, and what is not protectable

An attacker resident in the pool *during a window* reads that window's content.
That is accepted and documented (docs/sealed_vault.md § One unlock opens
everything) and no amount of key custody changes it — the plaintext is on its way
to the browser through that process.

What is not acceptable, and is fixable, is the upgrade from *that* to **holding
the long-term key**: reading everything the user has ever sealed, after the
window closes, off the box, against a stolen database, forever. Those are two
very different losses and the platform currently makes no distinction between
them.

### Mitigation A — the key never enters the pool (an unseal daemon)

The key is used for exactly three operations, all of them small-blob asymmetric
opens: `VaultCrypto::openItemDek()`, `openBulkDelivery()` and
`openHeldDeliveryBlob()` (includes/VaultCrypto.php:69, :89, :161). **Every byte
of actual content is decrypted symmetrically under a per-item DEK** — `openField`
and `openFieldFile` never see the secret key. So the surface to move is narrow: a
small local daemon under its own uid holds the secret keys, accepts a sealed
32-byte DEK over a unix socket, and returns the unwrapped DEK. Megabytes of mail
and Drive files never cross the socket.

What this buys:

- The pool cannot exfiltrate a long-term key, because it never holds one. A
  compromise becomes an oracle bounded by the window instead of a permanent,
  portable capability.
- The daemon can `mlock` its pages and mark them `MADV_DONTDUMP`, which closes
  the host-hardening class structurally instead of by advisory check, and is the
  only thing that also closes the APCu residue-after-close fact.
- Every unwrap is countable. A mass decrypt is N calls in a burst and can be rate
  limited and logged, alongside the window open/close records `VaultAudit`
  already keeps.

What it does not buy, stated plainly: an attacker resident during a window still
reads what the user reads, and can still ask the oracle to unwrap any DEK it can
name for as long as the window lasts. And the unlock request itself necessarily
carries key material through the pool — the browser sends the PRF output there.
The honest gain is that the exposure of the long-term key shrinks from "sitting
in shared memory for thirty-plus minutes, grabbable at any instant" to "transits
one request at unlock time", which also means the unwrap of the *wrapping* has to
move into the daemon, not just the unwrap of item DEKs.

Design constraints to work through before committing:

- **Rotation and enrollment need the key**, or a resealing operation from the
  daemon: `VaultCeremonies::rotate()` / `drainAndRetire()` take the old secret key
  to re-wrap under the new one. Either the daemon grows a reseal op or these
  user-initiated, step-up-gated ceremonies stay in the pool as a named exception.
- **A full fold is one unwrap per message.** A 100k-message rebuild becomes 100k
  socket round trips; at roughly 100µs each that is tens of seconds added to a
  rebuild that is already batched and checkpointed, but any rate limit has to be
  calibrated against it rather than against a human reading mail.
- **58 call sites across 32 files** reach `VaultUnlock::secretKey()` today. The
  crypto chokepoint is only the three `VaultCrypto` methods, so most call sites
  become "ask the daemon" rather than "hold the key", but the ones that want the
  key itself need enumerating first.
- **The daemon is not the agent.** The agent runs as root and takes orders from
  the management node. Making it the oracle turns "root can scrape memory"
  (forensic effort, out of scope below) into a documented unseal RPC the plane
  can call. Own uid, own unit, no channel to the plane.
- One more daemon per node, in a fleet that has been deliberately reducing the
  number of things running on a node. The relay's `joinery-sealer` unit
  (specs/relay_without_a_shell.md) is the closest existing precedent.

**Cost, stated for the decision:** a new process with its own account, unit,
installer step, monitoring and upgrade path on every node; every decrypt becomes
a cross-process round trip; and it prevents the "copy the key out" loss, not the
"read what the user reads" loss, which the docs must say so nobody thinks they
paid for more than they got.

### Mitigation B — give web-tier code the trust root node code already has

Cheaper than A, closes the specific permission-10 path, and is worth doing whether
or not A happens:

- **Refuse unsigned plugin and theme packages.** The signature already exists:
  `publish_upgrade.php` writes a `RELEASE_MANIFEST` and `.sig` into every theme
  (:916) and plugin (:1021) archive through `TreeManifestPublisher::
  publish_artifact()`. `MarketplaceClient::install()` downloads the archive over
  TLS and never looks at it, and an uploaded ZIP is never asked for one. So this
  is verifying what is already on the wire, not introducing a new scheme — but
  the PHP-side verifier has to be written; none exists.

  **This does not gate plugin authoring.** The act being gated is "an archive
  arriving through the browser becomes executing code", not "code runs". Files
  placed in `plugins/` directly — a git checkout, a dev box, scp — are untouched,
  and anyone who can place them already holds www-data or root, against whom a
  signature buys nothing anyway. What signing separates is **having the admin
  password** from **being able to run code on the box**, which today are the same
  thing: a phished permission-10 session is arbitrary PHP in the pool that holds
  every open vault key.

  Who genuinely loses something is a third party distributing a plugin outside
  our marketplace to a *managed* customer, who has no shell, and any self-hoster
  who uploads their own plugin through the browser. A **site-local trust anchor**
  — a signing key held `600 root:root` beside `config/agent_signing_key`, so a
  self-hoster signs their own builds from a root shell and the web user cannot
  reach the key — keeps that open. Countersigning third-party packages into the
  marketplace is the other half. Key loss or rotation needs a designed recovery
  before enforcement turns on: a site that cannot verify cannot install
  anything.

  **Honest limit:** install-time verification does not stop a www-data attacker
  writing into `plugins/` by hand (B4). That attacker is already in the pool and
  already has the key, so signing was never the control there; verifying on
  every load, rather than at install, would cost a tree hash per request and
  still not survive the same compromise.
- **Cover composer (B3).** Vendor ships inside the signed archive, resolved on
  the publisher; a node never runs `composer require`. Cost: larger archives,
  duplicated libraries across plugins, and a version conflict between two
  plugins becomes a publishing error instead of an install-time one.
- **Treat install and activate as credential events.** Require a fresh step-up
  (`SessionControl::step_up_outstanding()`), record it, and call
  `VaultUnlock::lockAll()` afterwards so freshly landed code cannot ride a window
  that was already open. Visible cost: installing a plugin closes every open
  vault on the site at that moment.
- **Refuse to install code while any vault window is open** —
  `VaultUnlock::hasAnyOpenWindow()` answers this today from the `/dev/shm`
  marker. Hygiene rather than a boundary (an attacker waits), but it makes the
  dangerous moment visible instead of silent. Needs an override: on a busy site
  the admin may never find the moment.
- **Log it where it will be read**: a code-install event belongs in the same
  audit trail as window open/close, so "code landed while three vaults were open"
  is a durable fact rather than an inference from timestamps.

### Mitigation C — the web user cannot write code

The structural companion to B: if only the agent (root, signed) writes to the
code tree, a break-in through the web server cannot leave anything behind, and
install-time signing has a floor under it. Everything the web user writes today
moves to `uploads/`, and `upgrade.php`, plugin and theme installs become
agent-run. That is the agent-on-node migration's territory
(`project_agent_on_node_migration`), still in progress with custody open, so
doing this first means doing it twice. Dev, where files are edited in place,
needs its own exception. **Direction, not a task for this round.**

### Considered: the browser holds the key

The browser already heartbeats while a window is open; it could carry the key
material on each request instead of the server caching it. No daemon, no
resident key, no residue. Not recommended while CSP is off
(`project_csp_phase1`, built, OFF): a script injected into the page steals the
key outright, native apps need the same change, and the hardened-domain idle
cap is designed around a server-side window. Revisit after CSP is on.

### Rejected

**Obfuscating the APCu entry** — storing the key XORed against something else the
pool can reach. It moves nothing: the attacker is inside the same trust domain
and can read whatever the legitimate reader reads. A variant that binds the entry
to the session cookie's value (never stored server-side) does raise the bar for
an *offline* APCu/swap/core-dump read, since the attacker must be in the request
path rather than merely reading memory — but it is worthless against the case
that matters here, code running in the pool, and it should not be mistaken for a
fix for it.

**Gating Private or its mail add-ons on any of this** — declined by the owner 2026-09-07.
Kept above for reference only.

**Out of scope, and worth saying once:** none of this survives root on the node
or the hypervisor beneath it. Linode's console, the Docker host's root for a
containerised site, and the management node's agent signing key all reach node
memory by design. The signing key on the plane remains the fleet's real trust
root.

## A malicious message: how a stranger's email reaches the pool (2026-09-09)

The owner's real worry is not an admin doing something careless but a stranger
sending something. This section traces every route from "bytes arrive from the
internet" to "code runs where the key is", verified against the tree and the
running dev box on 2026-09-09.

### Where stranger bytes enter, and what runs them

| Entry | Tier and user | Gate |
|---|---|---|
| Postfix pipe (`plugins/mailbox/utils/inbound_email_handler.php`) | PHP CLI spawned by Postfix as **www-data** (`/etc/postfix/master.cf`: `user=www-data`, 5 concurrent) | none beyond SMTP |
| Provider webhooks (`plugins/mailbox/ajax/inbound_email_webhook.php`) | **php-fpm** | per-provider HMAC (`MailgunProvider.php:619`) |
| IMAP poll (`ImapIngestor` via `ImapFetch::run`) | cron **and php-fpm**: the reader's Refresh button runs the full fetch and relay pull inside a web request for up to `INTERACTIVE_BUDGET_SECONDS` (20 s) — `plugins/mailbox/logic/check_mail_logic.php` | signed-in owner; the mail itself is from anyone |
| Joinery Direct (`ajax/joinery_direct.php`) | **php-fpm** | Ed25519 signature by *any* instance (`DirectReceiver.php:115`), spool caps |
| Relay pull (`RelaySpoolConsumer`) | cron and php-fpm (same Refresh) | signed pinned client; content re-verified box-side |

### What parses them

- **MIME**: `Horde_Mime_Part::parseMessage()` (vendor, pure PHP) behind
  `MimeParse.php:54`, with a pre-scan for the hanging-boundary shape that once
  looped it. Pure PHP: the realistic failure class is resource exhaustion and
  logic error, not memory corruption.
- **Attachment text**: `DocumentText::run()` (`includes/DocumentText.php:270`)
  spawns `timeout 20 php -d memory_limit=256M utils/extract_document_text.php`.
  **The subprocess is the same uid, same filesystem, same network.** It is a
  memory and time fence, not a privilege fence. Inside it: `ZipArchive`
  (libzip, C) at `:539` and `:1080`, libxml2 (C) at `:1140` with `LIBXML_NONET`
  and never `NOENT`/`DTDLOAD`, and `Smalot\PdfParser` (PHP) at `:595`.
  Containers stage to `/dev/shm`. This is where the C code that touches
  stranger bytes lives, and it runs as www-data with a writable webroot (B4).
- **Not present**: no image decode or resize of attachments (served as
  original bytes), no `unserialize`, `eval` or `include` of anything derived
  from mail, no vCard, no external tools (`pdftotext`, `libreoffice`). ICS
  invites go through the regex-based `IcsImporter` in the digest job, capped at
  5000 events.

### What the browser does with them

This layer is in good shape and is worth stating so nobody re-litigates it:

- The reader renders `body_html` in an `<iframe sandbox="allow-popups
  allow-popups-to-escape-sandbox" srcdoc=…>` (`mailbox_reader.js:1964`): no
  scripts, no same-origin, so script in a message cannot reach the session,
  the CSRF meta tag or the DOM. The admin single-message page uses an empty
  sandbox. The print sheet is the only surface that puts received HTML in our
  own document, and it is sanitized (`MailboxHtmlSanitizer::sanitizeForPrint`)
  under a per-response CSP with `default-src 'none'`.
- Attachments download with `Content-Disposition: attachment`, `nosniff`, and
  `text/html` downgraded to `application/octet-stream`
  (`attachment_retrieval.php:134-141`). Text previews go into the DOM as
  `textContent`. Image preview is the one thing decoded in the app origin, with
  the type forced client-side.
- Direct mail bodies (`MailDirectHandler.php:127`) are stored verbatim and
  inherit the same sandbox. Chat renders text nodes only.
- Remote images in mail **load unproxied and unblocked**: a tracking pixel
  fires on open, confirming the address is live and leaking the reader's IP
  and time.
- Site CSP is off by default and carries `script-src 'unsafe-inline'` when on
  (`PublicPageBase.php:983`), so it is not XSS-protective yet
  (`project_csp_phase1`).

### The routes, ranked

1. **The message is the lure, not the exploit.** A link opens a full-capability
   tab (that is what `allow-popups-to-escape-sandbox` means, and it is the
   right call; the alternative is links that do not work). The page it opens
   phishes the admin's password. `totp_require_admins` ships **off**
   (settings.json:741), so a password alone signs in; the login throttle
   (`login_logic.php:80`) slows guessing but not a phished credential. From
   there B5 or B6 is arbitrary PHP in the pool. This is the most likely
   real-world path by a wide margin, and every link in it is already in this
   spec except the 2FA default.
2. **A memory-safety bug in libzip or libxml2 reached through an attachment.**
   Runs as www-data in the extractor subprocess. Both libraries have a CVE
   history and both come from the OS (nodes run `unattended-upgrades`,
   `install.sh:2767`, so the window is the distro's patch lag). A win lands as
   www-data, which cannot read the pool's APCu (CLI APCu is separate) but can
   write the webroot (B4) and drop a file the pool runs on the next request. It
   can also read `/dev/shm/mailfts_*.sqlite` (Q1) and the sealed attachment
   store. Needs a real 0-day or an unpatched node; low probability, high
   consequence.
3. **The same parsers, but inside php-fpm.** The Refresh button runs Horde MIME
   parsing, the relay pull and IMAP ingest in the pool for up to 20 s. A
   crash-class bug there is already in the process that holds every key; no
   file drop needed. Pure-PHP parsers make corruption unlikely, but this is the
   only place hostile bytes are parsed in the tier that matters, and it exists
   for the sake of a responsive button.
4. **Resource exhaustion.** The hanging-boundary loop happened once. The Postfix
   pipe has 5 slots; five such messages stop inbound mail. Denial, not
   compromise, but it is the cheapest attack and the one most likely to be
   tried.

**What is not a route:** script or markup in the message body. The sandbox
closes it. A `.html` attachment is downloaded, never rendered. Reply and
forward do not quote the original into the compose editor
(`openCompose()` clears the editor and inserts only the signature), so
attacker HTML never enters the app origin that way. Outbound headers built
from a stored message (In-Reply-To, References) pass through PHPMailer's
`addCustomHeader`, which strips CR/LF. There is no path by which the sender
chooses a filename that lands somewhere meaningful, no deserialization, and
no shell-out with sender-influenced arguments. The DMARC report parser uses
`LIBXML_NONET` without `NOENT`. The recipe `fetch_url` tool refuses private
hosts and pins the resolved address across redirects.

## Inventory

The ranked list of security items to handle soon, this spec's included, lives
in `security_inventory.md`. Findings that are about what a message can make
the platform *do* rather than about the key (B7–B11) live there too. B and S
identifiers are shared across the two specs.

## Open decisions

1. Mitigation A: build the unseal daemon, or accept that pool compromise means
   permanent key loss and say so in docs/sealed_vault.md. Recommendation: do B
   first and document the loss until A exists; A is still worth building for the
   residue and rate-limit gains, after the agent migration.
2. Mitigation B: package signing for plugins and themes — the upstream publisher
   key alone, or also a site-local root-held key so a self-hoster can install
   their own builds without our marketplace? Recommendation: both. Decide the
   ecosystem trade-off explicitly: third-party authors go through the
   marketplace or teach their customers to sign.
3. Does B enforce everywhere, or only once a site actually holds a vault?
   Recommendation: everywhere. The protection ceremony surfaces "N installed
   plugins are unsigned and can read this mailbox's key" as information with an
   in-place fix, never as a gate (declined).
4. Mitigation C: when in the agent-on-node migration does the read-only webroot
   land?

Moved out: the index file mode, the three health-check corrections, encrypted
swap and apport in the install's housekeeping step, and the two doc sentences are
`vault_exposure_quick_fixes.md` Q1–Q6.
