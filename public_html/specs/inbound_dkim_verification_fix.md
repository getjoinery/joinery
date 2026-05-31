# Trustworthy inbound SPF/DKIM/DMARC verification (retire the false-failing verifier)

## Overview

Inbound email authentication results are **wrong today**. The router computes
DKIM with a hand-rolled verifier (`InboundEmailRouter::verifyDKIM()`) that
produces **false `fail` verdicts on legitimate mail**, and it computes no SPF or
DMARC at all. The verdict is stamped on every stored message as
`iem_dkim_result` and shown as a DKIM badge — so the system is actively
mislabeling good mail as failing.

This spec replaces the hand-rolled verifier with the correct architecture: **let
the MTA verify, and have the app trust the verified result.** It does both halves
together: the **safety fix** (stop emitting false `fail`; read verdicts from
`Authentication-Results`) and the **correctness fix** (provision the
opendkim-verify + opendmarc milters so the self-hosted Postfix path actually stamps
`Authentication-Results`). We confirmed this session that the milter is currently
unreachable, so without the provisioning fix the app would only ever read
`unverified` — both halves are needed for real verdicts.

## The bug (from this session's investigation)

Reproduced against a real stored Mailgun message (`iem #23`,
`d=mg.dev.getjoinery.com`, `s=mx`, `c=relaxed/relaxed`):

- **Body hash: MATCH** — the message is intact and the signature genuinely
  corresponds to it.
- **Signature verify: FAIL** — both with the router's logic *and* with a careful
  from-scratch re-implementation, even after correctly handling oversigning.

So the body is fine and the signature is valid (Gmail/real receivers accept it);
the failure is entirely in **header canonicalization / signature
reconstruction** — the genuinely hard part of DKIM. The message's `h=` uses
**oversigning** (`To:To`, `From:From`, `Subject:Subject`, `Sender:Sender`),
header folding, and the DKIM-Signature self-canonicalization — all of which the
router mishandles (`verifyDKIM()` always takes header instance `[0]`, ignores
`l=`, ignores `x=`, takes only signature `[0]` when multiple exist, and rebuilds
the signed string by hand). Modern senders (Gmail, Mailgun, Microsoft) oversign,
so a large fraction of real inbound mail is mismarked.

**Reproduced again live on genuine external mail (this session).** A real email
sent from `jeremy.tunnell@gmail.com` to `test@dev.getjoinery.com` arrived through
our server (`Received: … by devmail.getjoinery.com (Postfix) with ESMTPS`) and was
stored with `iem_dkim_result = 'fail'` — a correctly-signed Gmail message marked
failing. This confirms the bug affects ordinary external inbound mail, not just the
self-test loopback.

**Critical, separately-discovered fact (root-caused this session):** the inbound
Postfix path **stamps no `Authentication-Results` header at all** — because the
opendkim milter is **unreachable**, not because verification is misconfigured:

- Postfix is wired `smtpd_milters = inet:localhost:8891`, but **opendkim listens
  only on the unix socket** `/run/opendkim/opendkim.sock` (confirmed via `ss -lx`);
  **nothing listens on port 8891**.
- With `milter_default_action = accept`, every message fails the milter connection
  and is accepted **unprocessed** — no verify, no sign, no AR. Silent by design.
- The deployed `/etc/opendkim.conf` is essentially **Debian stock**: no `Mode`,
  `AuthservID`, `KeyTable`, or `SigningTable` — even though populated
  `key.table`/`signing.table` and per-domain keys exist on disk. So
  `install_email.sh`'s opendkim config block **never took effect** here (or a
  package upgrade restored the stock conf, whose `Socket local:…` then overrode the
  intended `inet:8891`, while `/etc/default/opendkim`'s `SOCKET="inet:8891@localhost"`
  is ignored under systemd).

So the self-hosted path produces nothing the app can trust, and the provisioning
job is concrete: **make the deployed opendkim config match what Postfix dials and
load the signing/verify tables** (see Provisioning). This is a wiring fix, not a
"Mode sv isn't enough" subtlety.

**Side finding (outbound, out of scope but confirmed):** the same broken milter
connection means **local outbound DKIM signing is also a no-op** — confirmed this
session: a message submitted locally `From signtest@dev.getjoinery.com` (a domain
in opendkim's `signing.table`) was stored with **no `DKIM-Signature` whatsoever**.
Production outbound is signed only because it routes through *Mailgun*
(`d=mg.dev…`), which masks the breakage; "opendkim already signs outbound"
(asserted in current docs) is **false on this box**. The spec keeps outbound
signing out of scope, but note the socket fix in this spec restores *both*
directions at once — and the docs that claim local outbound signing works should be
corrected.

**Consumers of the bad verdict today:**
- `InboundEmailRouter::storeMessage()` → `iem_dkim_result`.
- `admin/admin_inbound_email_message.php` → "DKIM" badge.
- `MailboxService::getThread()` → `dkim_result` to the reader.
- (`utils/email_send_test.php` loopback — already changed this session to show
  "signature present" instead of the false verdict; this spec makes its
  verdict rows real.)

## Why not "just fix the verifier," and why not a PHP library

- **SPF and DMARC literally cannot be computed at this layer.** SPF is a function
  of the *connecting client IP*, evaluated against the sender domain's published
  record. The inbound Postfix pipe (`utils/inbound_email_handler.php`) receives
  only the raw MIME on stdin and the envelope recipient as `$argv[1]` — **the
  connecting IP is never passed to PHP** (it's known only to `smtpd`, before the
  pipe). Any SPF verdict computed in PHP would have to guess the IP from forgeable
  `Received:` headers, so it would be untrustworthy by construction. DMARC depends
  on SPF + DKIM + alignment, so it's unavailable for the same reason. This — not
  "DKIM is hard" — is the decisive argument: two of the three checks are
  *structurally impossible* in the app, and only the MTA (which has the IP at SMTP
  time) can produce them.
- **We already have an SPF *record* check, and it is a different thing.**
  `includes/DnsAuthChecker.php::checkSPF()` does a DNS TXT lookup and inspects
  whether a domain *publishes* a sane `v=spf1` record (`-all`/`~all`/etc.). That's
  an outbound-config/health check on domains **we control** — not verification of
  an inbound message's connecting IP against a record. It cannot be repurposed for
  inbound verdicts; don't confuse the two.
- **Hand-rolling DKIM correctly is a rabbit hole.** A careful re-implementation
  written during the investigation still failed on a valid signature. Oversigning,
  folding, `l=`, multiple signatures, ed25519, and byte-exact relaxed/simple
  canonicalization are exactly where hand-rolled verifiers break, and they need
  ongoing maintenance as senders evolve.
- **Mature pure-PHP DKIM *verifiers* don't really exist.** PHP libraries do DKIM
  *signing* (PHPMailer, Symfony Mailer); robust verification is an ecosystem gap.
- **We are adding zero PHP/Composer dependencies.** To be explicit: this spec does
  **not** pull in a verification library. The only new code on the PHP side is a
  string parser for the `Authentication-Results` header. The verification
  "dependency" is OS-level (`opendkim`/`opendmarc` system packages), installed by
  the plugin's provisioning script (see Provisioning, below) — and `opendkim` is
  **already installed** on the self-hosted stack (it signs outbound today).
- **The right layer is the MTA.** `libopendkim`/`opendmarc` are the reference
  implementations and already run on this stack (opendkim signs outbound). The
  correct design is: the receiving MTA verifies SPF/DKIM/DMARC and stamps an
  `Authentication-Results` header; the application **reads** it. This also yields
  SPF and DMARC verdicts the app can't compute itself.

## Approach

**Stop computing auth verdicts in PHP. Read verified results instead.** Source of
truth, per inbound path:

| Inbound path | Verdict source |
|---|---|
| **Self-hosted Postfix** | `Authentication-Results` header stamped on receipt by the `opendkim` (verify mode) + `opendmarc` milters (provisioned by this spec) |
| **Mailgun webhook** | **Separate spec** (`inbound_mailgun_verification.md`) — `unverified` here until it lands |
| **Neither present** | `unverified` — **never** a hand-rolled `fail` |

The hand-rolled `verifyDKIM()` is removed from the verdict path — deleted outright,
not flagged off (a known-broken verifier kept alive is dead weight).

## Data model — `data/inbound_email_message_class.php`

Replace the single `iem_dkim_result` semantics with explicit, sourced verdicts:

| Field | Type | Notes |
|-------|------|-------|
| `iem_dkim_result` | `varchar(16)` | `pass`/`fail`/`none`/`unverified` — now from `Authentication-Results`, not hand-rolled |
| `iem_spf_result` | `varchar(16)` | new; default `'unverified'`; `pass`/`fail`/`softfail`/`neutral`/`none`/`unverified` |
| `iem_dmarc_result` | `varchar(16)` | new; default `'unverified'`; `pass`/`fail`/`none`/`unverified` |
| `iem_auth_source` | `varchar(20)` | new; default `'none'`; where the verdict came from: `milter` / `none` for now (`mailgun` reserved for the deferred work). Drives the "verified vs unverified" UI |

**Defaults matter for existing rows.** Give the three new columns the defaults above
in `$field_specifications` — Postgres `ADD COLUMN … DEFAULT` backfills existing rows
on sync, so old messages render as `unverified`/`none` (consistent with what they
are) without a separate migration touching them. The only data migration is the
`iem_dkim_result` flip (that column already holds untrustworthy `fail` data); the
three new columns need no migration.

Bump `@version`. (Don't add `spf_result`/`dmarc_result`/`auth_source` filter keys
to `getMultiResults()` yet — there's no consumer. Add them when a reader
"authentication" facet is actually built.)

## Shared parser — `includes/AuthenticationResults.php` (new, plugin)

A small, tested parser turning a message's `Authentication-Results` header into
structured verdicts: `{ spf, dkim, dmarc, dkim_domain, spf_domain }`. Handles
multiple `Authentication-Results` lines (pick the one stamped by *our*
authserv-id), multiple `dkim=` entries (take the aligned/pass one), and the
`header.d` / `smtp.mailfrom` properties. When no line stamped by our authserv-id
is present, returns nothing → the router records `unverified`. This replaces the
ad-hoc regex currently inlined in the email self-test page (`est_parse_auth`),
which should be retired in favor of this class. (A Mailgun `X-Mailgun-*` fallback
is handled in `specs/inbound_mailgun_verification.md`; the parser stays
single-purpose here.)

## Router — `includes/InboundEmailRouter.php`

The verdict is currently computed in `processEmail()` (line ~90) and
`handleStoreOnly()` (line ~166) and threaded into `storeMessage()` as the
`$dkim_result` parameter. The change lands at those two call sites, not inside
`storeMessage()`.

- Instead of `verifyDKIM()`, parse the message's `Authentication-Results` via
  `AuthenticationResults` and populate `iem_dkim_result` / `iem_spf_result` /
  `iem_dmarc_result` / `iem_auth_source`. If a line stamped by our authserv-id is
  present → those verdicts, source `milter`; otherwise `unverified` / source
  `none`. No provider plumbing — `processEmail()`'s signature and both call sites
  are unchanged (see the inventory section).
- **Remove** `verifyDKIM()` (line ~657), `parseDKIMSignature()` (line ~792),
  `canonicalizeBodyRelaxed()` (line ~812) and `canonicalizeBodySimple()` (line
  ~829). This is a **removal, not a rewrite** — the parser reads a verdict rather
  than computing one, so there is nothing to salvage, and re-fixing its
  canonicalization is exactly the rabbit hole this spec avoids. Delete outright; do
  not keep behind a flag.
- **Call-site audit (done):** a repo-wide grep confirms these four methods are
  referenced **only inside `InboundEmailRouter.php`**. `verifyDKIM()` is `public`
  but has no external callers; the three helpers are `private` and called only by
  `verifyDKIM()`. Despite the "DKIM badge" being a user-facing feature, the
  *computation* is not a shared/core API — removal is fully self-contained.
- The DKIM-fail `error_log` branch at the top of `processEmail()` (line ~91) goes
  away (it was acting on the bogus verdict).
- Bump `@version`.

## Inbound-provider inventory (decided once: no interface change)

**Inventory (verified by grep).** Exactly two classes implement
`InboundEmailProvider`:

| Provider | `isWebhook()` | Inbound path | Verdict source |
|---|---|---|---|
| `MailgunProvider` | `true` | HTTP webhook (`ajax/inbound_email_webhook.php`) | **separate spec** → `unverified` here |
| `PostfixProvider` | `false` | local pipe (`utils/inbound_email_handler.php`) | MIME `Authentication-Results` stamped by the provisioned milters |

The other seven provider classes (`SendGrid`, `Postmark`, `Ses`, `Brevo`,
`Mailjet`, `Resend`, `Smtp`) implement **only** `EmailServiceProvider` (outbound).
They have **no inbound path**, so there is no per-provider verdict work for them.

**Conclusion: no interface change, no provider change.** The up-front inventory's
decision is to *not* build an abstraction. Verdicts come from the MIME's
`Authentication-Results`, which the router parses directly — there is nothing to
thread through `handleInbound()`'s return. So `includes/InboundEmailProvider.php`,
both `handleInbound()` implementations, and `processEmail()`'s signature + call
sites are **all unchanged**. Mailgun-relayed mail simply records `unverified` until
`specs/inbound_mailgun_verification.md` lands.

## Provisioning (self-hosted correctness) — `provisioning/install_email.sh`

Make the Postfix SMTP path actually verify, so it stamps `Authentication-Results`
on receipt. All changes go in the existing `install_email.sh`, matching its
current idempotent, marker-guarded style (write-only-if-absent, back up to
`*.pre-joinery`, safe to re-run).

**Current live state (root-caused this session — supersedes "Mode sv is present"):**
the deployed `/etc/opendkim.conf` is **Debian stock** — no `Mode`, `AuthservID`,
`KeyTable`, or `SigningTable` — and opendkim listens on the **unix socket**
`/run/opendkim/opendkim.sock`, while Postfix dials `inet:localhost:8891` (dead).
With `milter_default_action = accept`, the milter is a silent no-op: opendkim
processes **no** Postfix mail in either direction. Populated `key.table`/
`signing.table` and per-domain keys exist on disk but aren't referenced by the
running conf. So the failure is **broken wiring**, and the fix has three parts:
realign the socket, deploy the managed conf (with the tables + `Mode`/`AuthservID`),
and add opendmarc.

The provisioner is **not done until a re-sent test message shows an
`Authentication-Results: devmail.getjoinery.com; dkim=…` line** (and, with
opendmarc, `spf=…; dmarc=…`). This is the acceptance test — config edits alone
don't prove it, given the conf/socket drift we just found.

Concrete changes:

0. **Fix the socket drift (the actual current bug).** Ensure the deployed
   `/etc/opendkim.conf` `Socket` matches what Postfix dials and that the managed
   conf is genuinely in place — not the stock one. The existing marker-guard keys
   on `inet:8891@localhost`; since the live conf has `Socket local:…`, the guard
   *should* rewrite it, so verify the script actually ran and wasn't clobbered by a
   later opendkim package upgrade. Reconcile the systemd-vs-`/etc/default/opendkim`
   socket precedence (under systemd, `opendkim.conf`'s `Socket` wins, so it must be
   the source of truth). After this, confirm `ss -lntp` shows opendkim on 8891.
1. **Packages.** Add `opendmarc` to the `PACKAGES=(postfix postfix-pgsql opendkim
   opendkim-tools)` array → `(… opendmarc)`. (`opendkim`/`opendkim-tools` already
   present; the existing `dpkg -s` loop handles "already installed".)
2. **opendkim conf must be the managed one, with `Mode sv` + `KeyTable`/
   `SigningTable` + `AuthservID`.** Re-assert the full managed `opendkim.conf`
   (not stock): `Socket inet:8891@localhost`, `Mode sv`, the `KeyTable`/
   `SigningTable`/`ExternalIgnoreList`/`InternalHosts` lines, **and** add
   `AuthservID <mail host>` (`inbound_email_mail_hostname`, e.g.
   `devmail.getjoinery.com`) so the stamped AR line is attributable to us and the
   parser can ignore forged upstream ones. Re-deploying this conf also restores the
   long-broken local outbound signing as a side effect.
3. **opendmarc config + socket (new block, mirroring the opendkim block).**
   Install `/etc/opendmarc.conf` only if our marker is absent (back up any
   existing to `/etc/opendmarc.conf.pre-joinery`):
   - `Socket inet:8893@localhost` (distinct port from opendkim's 8891),
   - `AuthservID <mail host>` (same host as opendkim),
   - `SPFSelfValidate true` — opendmarc computes SPF itself from the envelope +
     connecting IP it sees at the milter stage, so **no separate policyd-spf
     milter is needed** (simplest correct option; this is where the IP the PHP
     pipe lacks actually exists),
   - `RejectFailures false` / `SoftwareHeader true` — opendmarc only *stamps*
     results; it must not reject (enforcement is explicitly out of scope), so a
     DMARC failure still delivers and is recorded as a verdict.
   - Keep `/etc/default/opendmarc` `SOCKET=` in step with the conf, mirroring the
     existing opendkim `/etc/default/opendkim` handling.
4. **Milter ordering (critical).** Update `smtpd_milters` to run **opendkim first,
   then opendmarc** — opendmarc consumes opendkim's DKIM result plus its own SPF to
   reach a DMARC verdict, so order matters:
   `postconf -e "smtpd_milters = inet:localhost:8891, inet:localhost:8893"`.
   Leave `milter_default_action = accept` (a down/keyless milter must never block
   or defer mail). `non_smtpd_milters` need not include opendmarc (it applies to
   locally-submitted mail, not inbound).
5. **Service lifecycle.** `systemctl enable opendmarc` + restart, with the same
   `systemctl`→`service`→manual-warning fallback ladder already used for opendkim.
6. **Webhook caveat (documented, not code).** These milters only affect the
   **Postfix SMTP** inbound path. Mail arriving via the **Mailgun webhook** never
   touches Postfix/opendkim/opendmarc — its verdicts are handled separately in
   `specs/inbound_mailgun_verification.md`. The capability check makes this
   distinction visible (it warns when the selected provider isn't verified here).

## Verification-capability warning — `includes/InboundEmailSetupCheck.php`

This is the visible warning the operator sees in the **Setup tab validation
fields** whenever inbound mail isn't actually being authentication-verified — so a
broken or absent verifier surfaces as an explained warning, not a silent
`unverified`. It stays useful after this spec ships: if the milters later break
again (package upgrade, socket drift), the check catches it.

Add a new **host-layer** check (distinct from the existing `host.opendkim` check,
which is about outbound *signing*) — e.g. `host.inbound_verification`, using the
same `exec()`/`postconf`/`pgrep` probing and `r()` result shape already in this
class. The status depends on **two dimensions**: (a) does the *selected inbound
provider* have a verification path we support, and (b) for a supported provider, is
verification actually happening. **Package/config presence alone is not
sufficient** for (b) — we proved the milter can be wired-but-unreachable — so the
"is it happening" answer leans on a behavioral signal:

- **Provider-support probe:** read `inbound_email_provider`. **Postfix** is
  supported (milters can verify it). **Mailgun** has no verification here until
  `inbound_mailgun_verification.md` lands. A provider with **no** inbound
  verification path at all is the "unsupported" case.
- **Config/package probe** (Postfix only): `opendmarc` installed + running
  (`which opendmarc` / `pgrep -x opendmarc`); opendkim reachable on the socket
  Postfix dials (`ss -lnt` on the milter port — this is the drift we found);
  `Mode` includes `v`; `AuthservID` set; `smtpd_milters` lists both sockets.
- **Behavioral probe (authoritative):** any `iem_auth_source = 'milter'` in a
  recent window — catches "wired but not emitting". This probe is a DB query, so
  it's always available to the web user even when host config isn't readable.

The behavioral probe is the source of truth; the config/package probe only enriches
the *reason* shown on a WARN. If the config probe can't read host files (the web
user may lack access to `/etc/opendkim.conf`), that is **not** a failure — fall back
to behavioral: milter mail seen → PASS; none seen → NEUTRAL ("can't confirm"), never
FAIL on unreadability alone.

Status (per your rule: WARN when we *can't verify a provider we should*; NEUTRAL
when we *legitimately can't tell yet*):

- **WARN (REQUIRED)** — the selected provider is one we **don't verify**
  (e.g. Mailgun, pending its spec; or an unsupported inbound provider):
  *"Inbound authentication isn't being verified for the selected mail provider
  (`<provider>`); messages are recorded as `unverified`."*
- **WARN (REQUIRED)** — provider **is** Postfix but verification is **broken**
  (milter unreachable / opendmarc missing / config drift): *"Inbound mail is not
  being authentication-verified — SPF/DKIM/DMARC are recorded as `unverified`."*
  `fix` = `provisioning/install_email.sh` (then a re-send test to confirm AR
  appears).
- **NEUTRAL/INFO** — provider is Postfix, config/packages look correct, but **no
  recent milter-stamped mail has been seen yet** to confirm (e.g. a fresh install
  before any mail arrives). We legitimately don't know — don't alarm; say so:
  *"Verification is configured; no recently-received mail yet to confirm."*
- **PASS** — behavioral probe shows `milter` verdicts arriving.
- The check is `recheckable` so the operator can re-run it after provisioning or
  after sending a test message.

**Note — needs a neutral status:** `InboundEmailSetupCheck` currently defines only
`PASS`/`WARN`/`FAIL` statuses. The NEUTRAL/INFO state above requires adding an
`INFO` (neutral) status constant **and** a small rendering branch in the Setup-tab
result display (neutral styling, not green/yellow/red). Keep it low-effort — one
constant + one CSS/branch — but it is a real (small) addition, not free.

## UI

- `admin/admin_inbound_email_message.php`: show SPF / DKIM / DMARC verdicts with a
  **source** indicator — a real `pass`/`fail` only when `iem_auth_source` is
  `milter`; otherwise an explicit **"unverified — no verifying milter installed"**
  (the same cause the Setup-tab capability check reports), never a bare red `fail`.
- Mailbox reader: surface the same verdicts/“unverified” in the message view
  (small addition to `getThread()` payload + render).
- Email self-test loopback (`utils/email_send_test.php`): with the milters fixed,
  the SPF/DKIM/DMARC verdict rows become **real** on the Postfix path; keep the
  "signature present" fact and the external-check pointer for deployments without
  milters.

## Work (done together — no phasing)

All of the following lands as one change. The code half (read verdicts, stop
emitting `fail`) and the provisioning half (fix the milter so verdicts exist) are
done together, because we confirmed the milter is currently unreachable — code
alone would only ever read `unverified`.

- **Data + parser** — add the verdict columns (with defaults); add the
  `AuthenticationResults` parser.
- **Router** — read `Authentication-Results` from the MIME, populate the four
  verdicts, remove `verifyDKIM()` + canon helpers + the `error_log` branch.
- **Provisioning** — fix the opendkim socket drift, re-assert the managed conf
  (`Mode sv` + tables + `AuthservID`), add opendmarc, wire both milters in order.
  **Acceptance: a re-sent test message shows `Authentication-Results:
  devmail.getjoinery.com; dkim=… spf=… dmarc=…`.** This also restores local
  outbound signing.
- **Capability warning** — the `host.inbound_verification` check (+ `INFO`/neutral
  status) so a future regression (the milter breaking again) surfaces instead of
  silently reverting everything to `unverified`.
- **UI** — message detail + mailbox reader show sourced verdicts / honest
  `unverified`.
- **Backfill** — set every pre-existing `iem_dkim_result` to `unverified` (all were
  produced by the broken verifier; the table currently holds 2 rows, both `fail`).
- **Mailgun** — out of this spec entirely (`inbound_mailgun_verification.md`);
  webhook-delivered mail records `unverified` here.

**Expected end state on this deployment:** real `pass`/`fail` verdicts on
Postfix-delivered mail (the Gmail-style messages that the old verifier
false-failed), the capability check at PASS, and outbound signing restored.

## Files

### To create
| File | Purpose |
|------|---------|
| `plugins/inbound_email/includes/AuthenticationResults.php` | parse a MIME's `Authentication-Results` → structured SPF/DKIM/DMARC verdicts (single-purpose; no Mailgun fallback — deferred) |
| `plugins/inbound_email/tests/authentication_results_test.php` | parser unit tests (oversigning, multi-dkim, multi-line, authserv-id) |
| `plugins/inbound_email/migrations/` entry | backfill: set all pre-existing `iem_dkim_result` → `unverified` |

### To modify
| File | Change |
|------|--------|
| `data/inbound_email_message_class.php` | add `iem_spf_result`, `iem_dmarc_result`, `iem_auth_source`; widen `iem_dkim_result`; `@version` (no new filter keys yet) |
| `includes/InboundEmailRouter.php` | parse verdicts from the MIME's `Authentication-Results`; remove `verifyDKIM()` + canon helpers; `@version` |
| `includes/InboundEmailSetupCheck.php` | new `host.inbound_verification` check: WARN if the selected provider is unverified (Mailgun/unsupported) or Postfix verification is broken; NEUTRAL if configured-but-not-yet-confirmed; PASS when `iem_auth_source='milter'` mail is seen. **Add an `INFO`/neutral status constant + a Setup-tab render branch** (only pass/warn/fail exist today). fix = `install_email.sh` |
| `includes/MailboxService.php` | return spf/dkim/dmarc + source in `getThread()` |
| `admin/admin_inbound_email_message.php` | show sourced verdicts / "unverified — no verifying milter installed", never bare `fail` |
| `provisioning/install_email.sh` (+ docs) | fix opendkim socket drift (conf `Socket` must match Postfix's `inet:8891`; re-assert managed conf with `Mode sv` + `KeyTable`/`SigningTable` + new `AuthservID`); add `opendmarc` package + opendmarc.conf (`SPFSelfValidate`, `RejectFailures false`, socket :8893); set `smtpd_milters = …:8891, …:8893` (opendkim before opendmarc); enable/restart both; **acceptance = AR appears on a re-sent test** |
| `utils/email_send_test.php` | retire `est_parse_auth` in favor of `AuthenticationResults`; verdict rows real once the milters are fixed |
| `plugins/inbound_email/docs/overview.md` | reword "inbound DKIM verification" feature line; add "Inbound authentication" subsection (`unverified` state, sources); document the verification-capability warning; document opendkim-verify/opendmarc milters in "Server Setup" |
| `docs/email_system.md` | note app no longer computes inbound SPF/DKIM/DMARC; disambiguate `DnsAuthChecker` (record check ≠ message verification) at lines ~554–564 |

### Schema
Columns added declaratively via `$field_specifications` (sync, no migration). The
**only** migration is the data backfill (all pre-existing `iem_dkim_result` →
`unverified`).

## Testing

- **Parser** — fixtures of real `Authentication-Results` lines (Gmail, opendkim/
  opendmarc), oversigned and multi-`dkim=`; assert correct spf/dkim/dmarc +
  domains; pick the line matching our authserv-id and ignore a forged upstream one.
- **Router** — a stored message with an `Authentication-Results` line yields the
  right verdicts + `auth_source='milter'`; one without yields `unverified`/`none`
  and performs **no** hand-rolled computation; the dedup/threading paths still pass.
- **Regression** — two real stored messages the old verifier false-failed must now
  report `unverified` (**not** `fail`), because neither carries an
  `Authentication-Results`: (a) the Gmail→`test@dev.getjoinery.com` message
  (genuine external mail, Postfix path) and (b) the Mailgun-relayed self-test. Use
  the Gmail message as the primary external-mail fixture. The `dkim=pass` outcome is
  asserted **separately** against a fixture that *does* carry a milter AR.
- **Verification-capability check** — with no milter mail present, the
  `host.inbound_verification` check returns WARN with the fix command; simulate a
  recent `iem_auth_source='milter'` row and it returns PASS.
- **Backfill migration** — sets all pre-existing rows to `unverified`.
- **Provisioning (manual/integration)** — after `install_email.sh` runs, a re-sent
  inbound message carries a milter `Authentication-Results`, the verdict is a real
  `pass`/`fail`, and the capability check is PASS; a locally-submitted message is
  now opendkim-signed (outbound regression we found is fixed).
- `php -l` + `validate_php_file.php` on every changed PHP file.

## Security

- **Trust only our own authserv-id.** A message can carry attacker-supplied
  `Authentication-Results` lines from upstream hops; the parser must select the
  line stamped by our mail host and ignore others, or verdicts are spoofable.
- Verdicts are advisory metadata, not an access gate; surfacing `unverified`
  honestly is safer than a confident-but-wrong `fail`/`pass`.
- No secrets involved.

## Documentation

Docs are added to the **existing** files that already cover these subsystems (no
new standalone doc file — the provisioning material folds into the overview's
"Server Setup" section).

**`plugins/inbound_email/docs/overview.md`** (verified contents to fix):
- **Features line (line ~13)** currently lists "**inbound DKIM verification**" as a
  feature. This is now inaccurate — the app no longer *computes* DKIM. Reword to
  "**inbound authentication results (SPF/DKIM/DMARC) read from the verifying MTA /
  provider**" so the capability is described honestly.
- **New "Inbound authentication" subsection** explaining the model: the receiving
  MTA (opendkim/opendmarc milters) verifies and stamps `Authentication-Results`;
  the app *reads* it into `iem_spf_result` / `iem_dkim_result` / `iem_dmarc_result`
  / `iem_auth_source`. Document the **`unverified`** state (no verifying milter/AR
  present — not a failure) and the source (`milter` / `none`; `mailgun` is
  deferred), and state plainly that **the app never computes these verdicts itself**
  and a hand-rolled `fail` is never emitted.
- **"Server Setup" section (the `install_email.sh` walkthrough, line ~75+)**: this
  is where the **opendkim verify-mode + opendmarc milter** provisioning is
  documented (folded in here per the "docs in existing files" convention, not a
  separate provisioning doc). Cover: `opendmarc` package, opendkim `AuthservID`,
  opendmarc `SPFSelfValidate`/`RejectFailures false`, milter ordering
  (`smtpd_milters = …:8891, …:8893`), and that the milters only cover the **Postfix
  SMTP** path (webhook-delivered Mailgun mail is verified by Mailgun, not the
  milters).
- **Setup tab**: document the new **verification-capability warning**
  (`host.inbound_verification`) — what WARN vs PASS means, that `unverified`
  verdicts are expected until the milters are installed, and the fix command.

**`docs/email_system.md`** (verified contents to fix):
- Add a cross-reference note that the app **no longer computes inbound
  SPF/DKIM/DMARC** — it reads `Authentication-Results`.
- **Disambiguate `DnsAuthChecker` (lines ~554–564).** That section calls
  `DnsAuthChecker` "the one place to check a domain's SPF, DKIM, and DMARC records."
  Add a sentence clarifying that this checks whether a domain **publishes** records
  (outbound/setup config) and is **not** inbound *message* verification — inbound
  per-message verdicts come from `Authentication-Results` (milter/provider), never
  from `DnsAuthChecker`. This is exactly the conflation that caused confusion;
  spell it out.

## Versioning

- `plugin.json` minor bump; `@version` on each changed file.
- One data-backfill migration; columns are declarative (no schema migration).

## Deferred to a separate spec

- **Mailgun webhook verdicts** are handled entirely in
  **`specs/inbound_mailgun_verification.md`** (reading Mailgun's own
  `X-Mailgun-*` / `Received-SPF` verdicts via a small `AuthenticationResults`
  fallback — no interface/provider change). Deferred because the deployment runs
  `inbound_email_provider = postfix` (no Mailgun inbound path to verify against) and
  the field names are still unconfirmed. Until that spec lands, Mailgun-relayed
  mail records `unverified` (honest, never `fail`).

## Out of scope / non-goals

- **Acting on DMARC policy** (quarantine/reject inbound on failure) — this stores
  and displays verdicts; enforcement is a separate policy decision.
- **A pure-PHP DKIM verifier** — explicitly rejected (ecosystem gap; wrong layer).
- **Outbound DKIM signing** — no dedicated work, but the socket fix here restores
  it as a side effect (it's currently broken on this box; see the bug section). We
  don't redesign outbound signing, just stop it being a casualty of the dead milter.
- **ARC** (Authenticated Received Chain) for forwarded mail — future.
