# Inbound Mailgun authentication verdicts (deferred companion to the DKIM verification fix)

## Context

This spec is split out from `inbound_dkim_verification_fix.md`. That spec retires
the false-failing hand-rolled DKIM verifier and reads verdicts from the message's
`Authentication-Results` header instead — for the **self-hosted Postfix** path. It
explicitly **defers** the Mailgun webhook path to here so the safety fix can land
without waiting on Mailgun-specific work.

Read the parent spec first. The data model (`iem_spf_result` / `iem_dmarc_result` /
`iem_auth_source`), the `AuthenticationResults` parser, the router's
read-from-MIME behavior, and the UI/`unverified` semantics all come from there and
are assumed in place. This spec only adds the **Mailgun verdict source**.

## Goal

Make inbound mail that arrives via the **Mailgun webhook** carry real
SPF/DKIM/DMARC verdicts (source `mailgun`) instead of `unverified`, by reading
Mailgun's own upstream verification from the message.

## Why this is deferred, not abandoned

- **No live Mailgun inbound path to verify against.** The deployment runs
  `inbound_email_provider = postfix`. Mailgun inbound isn't exercised today, so
  nothing regresses by deferring, and there's no real sample to build against.
- **The field names are unconfirmed.** The only stored "Mailgun" message is
  Joinery's own *outbound* self-test looped back (it carries `X-Mailgun-Sending-Ip`
  and **no** verdict headers) — it never traversed Mailgun's *inbound*
  verification, so it tells us nothing about what genuine inbound Mailgun mail
  carries. Per Mailgun's docs, inbound-received MIME carries `X-Mailgun-Spf`,
  `X-Mailgun-Dkim-Check-Result`, and a `Received-SPF` header — but this is
  **documentation-derived, not observed**.

## Prerequisite (do this first)

**Capture a genuine third-party→Mailgun inbound message and confirm the fields.**
Either on a deployment running Mailgun inbound, or by temporarily switching
`inbound_email_provider` to `mailgun` with a configured Mailgun inbound route to
the webhook, send a real external email (e.g. from Gmail) to an inbound address and
inspect the `body-mime` Mailgun delivers. Record the exact header names/values for
SPF, DKIM, and DMARC. Do not write the mapping code against assumed names.

## Approach (no interface or provider change)

Everything Mailgun reports lives **in the MIME** the router already parses
(`MailgunProvider::handleInbound()` reads the full message via
`$post['body-mime']`). So this is a small **fallback inside
`AuthenticationResults`**, not a new provider hook:

1. Parser first looks for a standard `Authentication-Results` line stamped by our
   authserv-id (if Mailgun includes one, we're done — source `milter`/standard).
2. If absent, the parser reads Mailgun's own headers
   (`X-Mailgun-Spf` / `X-Mailgun-Dkim-Check-Result` / `Received-SPF`) from the same
   MIME and maps them to the verdict structure with `iem_auth_source = 'mailgun'`.
3. If neither is present, `unverified` (unchanged).

Confirmed during the parent-spec inventory: `includes/InboundEmailProvider.php`,
both `handleInbound()` implementations, and `processEmail()`'s signature stay
**unchanged**. The single thing that would force an interface change — Mailgun
posting verdicts *solely* as separate form fields, never in `body-mime` — is
contradicted by how the provider already works; only revisit if a real sample
proves otherwise.

## Mapping notes (fill in after the prerequisite)

- `Received-SPF: pass/fail/softfail/neutral/none …` → `spf_result`.
- `X-Mailgun-Dkim-Check-Result: Pass/Fail` → `dkim_result` (normalize case).
- `X-Mailgun-Spf:` (Mailgun's own SPF verdict) — reconcile with `Received-SPF`;
  prefer whichever the captured sample shows Mailgun populating reliably.
- DMARC: confirm whether Mailgun exposes a DMARC verdict header at all; if not,
  `dmarc_result` stays `unverified` for the Mailgun path (don't synthesize it).
- `source = 'mailgun'`; record the sending domain into `dkim_domain`/`spf_domain`
  where available.

## Files

### To modify
| File | Change |
|------|--------|
| `plugins/inbound_email/includes/AuthenticationResults.php` | add the `X-Mailgun-*` / `Received-SPF` fallback (used only when no standard AR line is present); `source='mailgun'`; `@version` |
| `plugins/inbound_email/tests/authentication_results_test.php` | add fixtures from a **real captured** Mailgun inbound message; assert the mapped verdicts + `source='mailgun'` |
| `includes/InboundEmailSetupCheck.php` | the verification-capability check (from the parent spec) should treat the Mailgun provider as a **supported, verifiable** inbound provider once this lands (it currently warns that Mailgun inbound is unverified) |
| `plugins/inbound_email/docs/overview.md` | document the Mailgun verdict source and that `mailgun` is now a real `iem_auth_source` value |

No interface, router-signature, or provider-`handleInbound()` changes.

## Testing

- **Parser (Mailgun fallback)** — fixtures from a real captured Mailgun inbound
  MIME: assert `spf`/`dkim`(/`dmarc` if present) map correctly with
  `source='mailgun'`; a message with a standard `Authentication-Results` line still
  prefers that line (Mailgun fallback only fires when AR is absent).
- **Regression** — a Mailgun message with neither AR nor `X-Mailgun-*` still yields
  `unverified` (never a hand-rolled verdict).
- `php -l` + `validate_php_file.php` on changed PHP files.

## Security

- Same authserv-id discipline as the parent spec: a standard
  `Authentication-Results` line is trusted only when stamped by our authserv-id.
  Mailgun's `X-Mailgun-*` headers are trusted only on the Mailgun **webhook** path
  (HMAC-verified by `MailgunProvider::handleInbound()` before the MIME is accepted),
  not on arbitrary inbound mail — a forged `X-Mailgun-Spf` on a Postfix-delivered
  message must not be honored.

## Out of scope

- Everything the parent spec already covers (verifier removal, columns, parser
  core, Postfix milters, UI, capability warning).
- Acting on DMARC policy; outbound signing; ARC.
