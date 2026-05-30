# Trustworthy inbound SPF/DKIM/DMARC verification (retire the false-failing verifier)

## Overview

Inbound email authentication results are **wrong today**. The router computes
DKIM with a hand-rolled verifier (`InboundEmailRouter::verifyDKIM()`) that
produces **false `fail` verdicts on legitimate mail**, and it computes no SPF or
DMARC at all. The verdict is stamped on every stored message as
`iem_dkim_result` and shown as a DKIM badge — so the system is actively
mislabeling good mail as failing.

This spec replaces the hand-rolled verifier with the correct architecture: **let
the MTA / provider verify, and have the app trust the verified result.** It is
phased so the **safety fix (stop emitting false `fail`)** lands first and
independently, with the **correctness fix (actually verify on the self-hosted
path)** following.

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

**Consumers of the bad verdict today:**
- `InboundEmailRouter::storeMessage()` → `iem_dkim_result`.
- `admin/admin_inbound_email_message.php` → "DKIM" badge.
- `MailboxService::getThread()` → `dkim_result` to the reader.
- (`utils/email_send_test.php` loopback — already changed this session to show
  "signature present" instead of the false verdict; this spec makes its
  verdict rows real.)

## Why not "just fix the verifier," and why not a PHP library

- **Hand-rolling DKIM correctly is a rabbit hole.** A careful re-implementation
  written during the investigation still failed on a valid signature. Oversigning,
  folding, `l=`, multiple signatures, ed25519, and byte-exact relaxed/simple
  canonicalization are exactly where hand-rolled verifiers break, and they need
  ongoing maintenance as senders evolve.
- **Mature pure-PHP DKIM *verifiers* don't really exist.** PHP libraries do DKIM
  *signing* (PHPMailer, Symfony Mailer); robust verification is an ecosystem gap.
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
| **Self-hosted Postfix** | `Authentication-Results` header stamped on receipt by `opendkim` (verify mode) + `opendmarc` milters (Phase 2 provisioning) |
| **Mailgun webhook** | Mailgun verifies upstream — read its results from the stored MIME's `Authentication-Results` and/or Mailgun's webhook fields |
| **Neither present** | `unverified` — **never** a hand-rolled `fail` |

The hand-rolled `verifyDKIM()` is removed from the verdict path. (Optionally kept
only behind an off-by-default flag as a clearly-labeled best-effort fallback that
can report `pass`/`unverified` but **never `fail`** — recommended: just remove
it.)

## Data model — `data/inbound_email_message_class.php`

Replace the single `iem_dkim_result` semantics with explicit, sourced verdicts:

| Field | Type | Notes |
|-------|------|-------|
| `iem_dkim_result` | `varchar(16)` | `pass`/`fail`/`none`/`unverified` — now from `Authentication-Results`, not hand-rolled |
| `iem_spf_result` | `varchar(16)` | new; `pass`/`fail`/`softfail`/`neutral`/`none`/`unverified` |
| `iem_dmarc_result` | `varchar(16)` | new; `pass`/`fail`/`none`/`unverified` |
| `iem_auth_source` | `varchar(20)` | new; where the verdict came from: `milter` / `mailgun` / `none` (drives the "verified vs unverified" UI) |

`getMultiResults()` gains `spf_result`/`dmarc_result`/`auth_source` filters for
the reader's future "authentication" facet. Bump `@version`.

## Shared parser — `includes/AuthenticationResults.php` (new, plugin)

A small, tested parser turning an `Authentication-Results` header into structured
verdicts: `{ spf, dkim, dmarc, dkim_domain, spf_domain }`. Handles multiple
`Authentication-Results` lines (pick the one stamped by *our* authserv-id),
multiple `dkim=` entries (a message can carry several — take the aligned/pass
one), and the `header.d` / `smtp.mailfrom` properties. This replaces the ad-hoc
regex currently inlined in the email self-test page (`est_parse_auth`), which
should be retired in favor of this class.

## Router — `includes/InboundEmailRouter.php`

- `storeMessage()`: instead of `verifyDKIM()`, parse the message's
  `Authentication-Results` (via `AuthenticationResults`) and populate
  `iem_dkim_result` / `iem_spf_result` / `iem_dmarc_result` / `iem_auth_source`.
  If no usable header is present, set all to `unverified` / source `none`.
- **Remove** `verifyDKIM()`, `parseDKIMSignature()`, `canonicalizeBody*()` and
  the related helpers (or quarantine behind an off-by-default flag, never `fail`).
- The DKIM-fail `error_log` branch at the top of `processEmail()` goes away (it
  was acting on the bogus verdict).
- Bump `@version`.

## Provider path — `includes/email_providers/MailgunProvider.php`

`handleInbound()` already extracts `body-mime` + recipient. Mailgun-relayed MIME
normally carries an `Authentication-Results` line (Mailgun verifies upstream);
the shared parser handles it for free once the router reads it. If a Mailgun
deployment strips it, extract SPF/DKIM/DMARC from Mailgun's webhook fields and
pass them through the same verdict structure. (No change if the MIME already
carries `Authentication-Results`.)

## Provisioning (Phase 2, self-hosted correctness) — `provisioning/`

Make the Postfix path actually verify, so it stamps `Authentication-Results`:

- Extend `install_email.sh` to install/configure **`opendkim` in verify mode for
  inbound** (it currently signs outbound only) and **`opendmarc`**, wired as
  Postfix `smtpd_milters` so they run on receipt **before** the
  `InboundEmailRouter` pipe.
- Ensure the milters set an authserv-id of the mail host
  (`inbound_email_mail_hostname`, e.g. `devmail.getjoinery.com`) so the parser can
  trust the right `Authentication-Results` line and ignore forged upstream ones.
- opendmarc needs SPF input; configure `opendmarc` with `SPFSelfValidate` or pair
  with a policyd-spf milter (decide at build — `opendmarc`'s built-in SPF is
  simplest).
- Idempotent + safe to re-run, matching the existing provisioning style.

## Setup checks — `includes/InboundEmailHealth.php` / Setup tab

Add a provisioner/check: **"Inbound messages are authentication-verified"** —
passes when recently-received stored mail carries a milter-stamped
`Authentication-Results` (i.e., `iem_auth_source = 'milter'` appearing), warns
otherwise with the fix ("install the opendkim-verify/opendmarc milters"). This
makes the gap visible instead of silently showing `unverified`.

## UI

- `admin/admin_inbound_email_message.php`: show SPF / DKIM / DMARC verdicts with a
  **source** indicator — a real `pass`/`fail` only when `iem_auth_source` is
  `milter`/`mailgun`; otherwise an explicit **"unverified (no verifying milter)"**,
  never a bare red `fail`.
- Mailbox reader: surface the same verdicts/“unverified” in the message view
  (small addition to `getThread()` payload + render).
- Email self-test loopback (`utils/email_send_test.php`): the SPF/DKIM/DMARC
  verdict rows become **real** on the Postfix path once Phase 2 lands (today they
  read "—"); keep the "signature present" fact and the external-check pointer for
  deployments without milters.

## Phasing

**Phase 1 — Safety (code only, no infra).** Stop emitting false `fail`.
- Add the verdict columns + `AuthenticationResults` parser.
- Router reads `Authentication-Results` (works immediately for Mailgun-relayed
  mail that carries it); everything else → `unverified`.
- Remove the hand-rolled verifier from the verdict path.
- Fix the message-detail + reader UIs to show `unverified` rather than `fail`.
- **Backfill:** existing `iem_dkim_result = 'fail'` values are untrustworthy —
  migrate them to `unverified` (data migration; they were never reliable).

**Phase 2 — Correctness (self-hosted).** Provision opendkim-verify + opendmarc
milters so the Postfix path stamps `Authentication-Results` and the verdicts
become real; add the Setup check.

Phase 1 fully resolves "we shouldn't show a failed test unless it failed." Phase 2
restores actual verification on the self-hosted path.

## Files

### To create
| File | Purpose |
|------|---------|
| `plugins/inbound_email/includes/AuthenticationResults.php` | parse `Authentication-Results` → structured SPF/DKIM/DMARC verdicts |
| `plugins/inbound_email/tests/authentication_results_test.php` | parser unit tests (oversigning, multi-dkim, multi-line, authserv-id) |
| `plugins/inbound_email/migrations/` entry | backfill `iem_dkim_result='fail'` → `unverified` |

### To modify
| File | Change |
|------|--------|
| `data/inbound_email_message_class.php` | add `iem_spf_result`, `iem_dmarc_result`, `iem_auth_source`; widen `iem_dkim_result`; filters; `@version` |
| `includes/InboundEmailRouter.php` | populate verdicts from `Authentication-Results`; remove `verifyDKIM()` + canon helpers; `@version` |
| `includes/email_providers/MailgunProvider.php` | ensure Mailgun verdicts flow through (extract from MIME/webhook if needed) |
| `includes/MailboxService.php` | return spf/dkim/dmarc + source in `getThread()` |
| `admin/admin_inbound_email_message.php` | show sourced verdicts / "unverified", never bare `fail` |
| `provisioning/install_email.sh` (+ docs) | install/wire opendkim-verify + opendmarc inbound milters (Phase 2) |
| `includes/InboundEmailHealth.php` + Setup tab | "authentication-verified" check (Phase 2) |
| `utils/email_send_test.php` | retire `est_parse_auth` in favor of `AuthenticationResults`; verdict rows real once Phase 2 lands |

### Schema
Columns added declaratively via `$field_specifications` (sync, no migration). The
**only** migration is the data backfill of stale `fail` verdicts.

## Testing

- **Parser** — fixtures of real `Authentication-Results` lines (Gmail, Mailgun,
  Outlook, opendkim/opendmarc), oversigned and multi-`dkim=`; assert correct
  spf/dkim/dmarc + domains; pick the line matching our authserv-id and ignore a
  forged upstream one.
- **Router** — a stored message with an `Authentication-Results` line yields the
  right verdicts + `auth_source`; one without yields `unverified`/`none` and
  performs **no** hand-rolled computation; the dedup/threading paths still pass.
- **Regression** — the `iem #23`-style Mailgun message that the old verifier
  false-failed now reports `dkim=pass` when a milter/Mailgun AR is present, and
  `unverified` (not `fail`) when none is.
- **Backfill migration** — flips `fail` → `unverified`, leaves `pass`/`none`.
- **Phase 2 (manual/integration)** — after provisioning, a real inbound message
  carries a milter `Authentication-Results`; the Setup check passes.
- `php -l` + `validate_php_file.php` on every changed PHP file.

## Security

- **Trust only our own authserv-id.** A message can carry attacker-supplied
  `Authentication-Results` lines from upstream hops; the parser must select the
  line stamped by our mail host and ignore others, or verdicts are spoofable.
- Verdicts are advisory metadata, not an access gate; surfacing `unverified`
  honestly is safer than a confident-but-wrong `fail`/`pass`.
- No secrets involved.

## Documentation

- `plugins/inbound_email/docs/overview.md`: how inbound authentication works now
  (MTA/provider verifies → `Authentication-Results` → stored verdicts), the
  `unverified` state, and the milter requirement for the self-hosted path.
- `docs/email_system.md`: cross-reference; note the app no longer computes DKIM
  itself.
- Provisioning doc for the opendkim-verify/opendmarc milter setup.

## Versioning

- `plugin.json` minor bump; `@version` on each changed file.
- One data-backfill migration; columns are declarative (no schema migration).

## Out of scope / non-goals

- **Acting on DMARC policy** (quarantine/reject inbound on failure) — this stores
  and displays verdicts; enforcement is a separate policy decision.
- **A pure-PHP DKIM verifier** — explicitly rejected (ecosystem gap; wrong layer).
- **Outbound DKIM signing** — unchanged (opendkim already signs outbound).
- **ARC** (Authenticated Received Chain) for forwarded mail — future.
