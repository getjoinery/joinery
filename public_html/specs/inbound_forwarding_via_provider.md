# Route inbound forwarding through the selected outbound provider (retire the separate SMTP credential)

## Overview

Inbound forwarding currently relays through a **raw SMTP connection**
(`SmtpMailer` → `smtp.mailgun.org`), authenticating with a **separate**
`smtp_username` / `smtp_password` credential — even though normal outbound mail
goes through the selected provider's API (`email_service = mailgun`, using
`mailgun_api_key`). The two credentials drift independently: a working API key
and a stale SMTP password is exactly the state we found in production, where
every forward failed at `535 Authentication failed` while ordinary sending
worked fine.

This spec routes forwarding through the **same provider abstraction** that
outbound sending uses, so forwarding reuses the one credential the operator
already maintains and becomes provider-agnostic instead of silently depending on
a generic SMTP relay. The separate `smtp_*` relay stays only as an explicit
fallback for providers that genuinely can't relay raw MIME.

## Goal

When the active outbound provider can relay a raw MIME message with a chosen
envelope sender, inbound forwarding uses **that** path (reusing the provider's
existing credential). Forwarding works for every provider; no second credential
to configure or let go stale.

## The problem (root-caused this session)

- `InboundEmailRouter::forwardEmail()` builds a `SmtpMailer` via
  `createMailer()`, which — with the `inbound_email_forwarding_smtp_*` settings
  empty — falls back to the base `smtp_*` settings: `smtp.mailgun.org:465`,
  authenticated as `info@mg.dev.getjoinery.com`. That SMTP login is rejected
  (`535`), so forwarding is dead while API sending is healthy.
- **Two credentials for one provider.** Mailgun (and most providers) issue an
  **API key** *and* separate **SMTP credentials**. The provider abstraction uses
  the API key; forwarding uses the SMTP password. Operators reasonably maintain
  only the key, so the SMTP path rots silently.
- **Forwarding is provider-blind.** It always relays over generic SMTP
  regardless of which provider is selected — there is no link between
  `email_service` and how forwarded mail leaves the building.

## Why forwarding used raw SMTP in the first place (and why it doesn't have to)

Forwarding needs two things ordinary transactional sending doesn't:

1. **A chosen envelope sender (Return-Path)** — `forwardEmail()` sets `MAIL FROM`
   to the SRS-rewritten address so forwarded mail's bounces route back through us
   and SPF aligns. `EmailMessage`/`EmailSender` expose **no** envelope-sender /
   Return-Path field (verified) — only from/to/subject/body — so the API path
   couldn't express it.
2. **Byte-faithful raw-MIME relay** — `forwardEmail()` relays the *original*
   message bytes (`$smtp->data($header . "\r\n\r\n" . $body)`), preserving MIME
   structure, attachments and most headers. `MailgunProvider::send()` rebuilds a
   message from `from`/`to`/`subject`/`html|text` (MailgunProvider.php:165) — it
   reconstructs from decoded parts, it does not relay raw MIME.

Raw SMTP was simply the one path that gave both. But it isn't the only one:
Mailgun's API has a raw-MIME endpoint (`messages.mime`), Amazon SES has
`SendRawEmail`, and the generic SMTP provider IS raw SMTP. The fix is to add a
**raw-MIME-relay capability** to the provider abstraction and let forwarding use
it — closing the dual-credential gap at the right layer rather than papering over
it by re-entering an SMTP password that will rot again.

## Design

### A small, optional capability interface

Add `includes/RawMessageRelay.php`:

```php
interface RawMessageRelay {
    /**
     * Relay an already-formed RFC 5322 message to one or more envelope
     * recipients, with an explicit envelope sender (Return-Path / MAIL FROM).
     * Returns ['dest@x' => bool] per recipient, mirroring forwardEmail().
     *
     * @param string $raw_mime         The full message to relay, as-is.
     * @param string $envelope_sender  MAIL FROM (already SRS-rewritten if applicable).
     * @param string[] $destinations   Envelope recipients (RCPT TO).
     */
    public function relayRawMessage(string $raw_mime, string $envelope_sender, array $destinations): array;
}
```

This is **separate** from `EmailServiceProvider` (opt-in), so a provider that
cannot relay raw MIME simply doesn't implement it and there is no interface-wide
breakage. Forwarding detects support with `instanceof RawMessageRelay`.

### Forwarding picks the path once

`InboundEmailRouter` gains a single resolution point (replacing the per-call
`createMailer()` + hand-rolled SMTP in `forwardEmail()`):

1. Resolve the active outbound provider (the same resolution `EmailSender`
   uses — `email_service`).
2. If it implements `RawMessageRelay` **and** no explicit
   `inbound_email_forwarding_smtp_host` override is set → relay via the provider
   (reusing its credential).
3. Otherwise → the existing `SmtpMailer` raw-SMTP relay (forwarding-specific
   `inbound_email_forwarding_smtp_*`, else base `smtp_*`). Unchanged behavior,
   kept for providers without raw-MIME relay and for operators who deliberately
   point forwarding at a dedicated SMTP relay.

All three relay paths route through this resolver, not just the main alias
forward:
- `forwardEmail()` (alias forward / forward_and_store),
- `forwardToCatchAll()` — today this is **lossy** (rebuilds via
  `setFrom`/`Body`); switching it to raw-MIME relay also *fixes* that fidelity
  loss as a side benefit,
- `handleSRSBounce()` (bounce notification send) — a normal transactional send;
  it can stay on `EmailSender` or use the relay, decided in the inventory below.

### Provider inventory — decide once (per the up-front-inventory rule)

The nine outbound providers, and whether each gets `RawMessageRelay` now:

| Provider | Raw-MIME relay available? | Decision |
|---|---|---|
| `MailgunProvider` | Yes — `messages.mime` (SDK `sendMime`) | **Implement** (the live deployment; the whole point) |
| `SmtpProvider` | Native — it *is* raw SMTP with envelope control | **Implement** (thin wrapper over what `forwardEmail` does today) |
| `SesProvider` | Yes — `SendRawEmail` with envelope sender | **Implement** if the SES path is exercised; else defer with a noted TODO |
| `PostmarkProvider` | Limited — no arbitrary-envelope raw MIME | **Do not implement** → SMTP fallback |
| `SendGridProvider` | Limited / no faithful raw-MIME relay | **Do not implement** → SMTP fallback |
| `BrevoProvider` | No | **Do not implement** → SMTP fallback |
| `MailjetProvider` | No | **Do not implement** → SMTP fallback |
| `ResendProvider` | No | **Do not implement** → SMTP fallback |
| `PostfixProvider` | n/a (inbound-only) | not an outbound provider |

A provider without `RawMessageRelay` keeps working via the SMTP fallback — so
forwarding never regresses for anyone. The decision is made here once; new
providers declare support (or not) when added.

### Envelope sender / SRS, decided explicitly

SRS rewrites the envelope so (a) SPF aligns at the destination and (b) bounces
route back to us for `handleSRSBounce()` decoding. When relaying **through a
provider that owns bounce handling** (Mailgun, SES), the provider's own
SPF/DKIM align with its sending domain and it manages bounces — so the SRS
envelope is largely moot on that path. The decision:

- **SMTP-relay fallback path:** unchanged — SRS still rewrites `MAIL FROM` (as
  today), because we are the MTA and own the return-path.
- **Provider-relay path:** pass the SRS-rewritten sender as the envelope where
  the provider honors a custom Return-Path; where it doesn't, let the provider
  own bounces and document that SRS bounce-decoding does not apply to
  provider-relayed forwards. Either way, the From-header rewrite to the site's
  verified address (already done) is what carries deliverability — that does not
  change.

This is called out so the SRS behavior is a deliberate per-path decision, not an
accident of which relay fired.

## Files

### To create
| File | Purpose |
|------|---------|
| `includes/RawMessageRelay.php` | the opt-in capability interface |
| `tests/integration/inbound_forwarding_relay_test.php` | resolver picks provider-relay vs SMTP fallback correctly; a `RawMessageRelay` provider is used when active; a non-supporting provider falls back |

### To modify
| File | Change |
|------|--------|
| `includes/email_providers/MailgunProvider.php` | implement `RawMessageRelay::relayRawMessage()` via the SDK MIME endpoint, reusing `mailgun_api_key`; `@version` |
| `includes/email_providers/SmtpProvider.php` | implement `RawMessageRelay` as a thin wrapper over the raw-SMTP relay logic currently inlined in `forwardEmail()` |
| `includes/email_providers/SesProvider.php` | implement `RawMessageRelay` via `SendRawEmail` (or defer with a TODO if the SES path is unexercised) |
| `plugins/inbound_email/includes/InboundEmailRouter.php` | add the relay resolver; route `forwardEmail()`, `forwardToCatchAll()`, and (per decision) `handleSRSBounce()` through it; keep `createMailer()` SMTP path as the documented fallback; `@version` |
| `plugins/inbound_email/includes/InboundEmailSetupCheck.php` | the "Outbound forwarding relay" plugin check should verify the **resolved** relay (provider credential when provider-relay is active), not assume SMTP — so a healthy API key reads PASS even with empty `smtp_*` |
| `plugins/inbound_email/plugin.json` | minor version bump |

## Testing

- **Resolver unit test** — active provider implements `RawMessageRelay` and no
  forwarding-SMTP override → provider path chosen; non-supporting provider →
  SMTP fallback; explicit `inbound_email_forwarding_smtp_host` set → SMTP path
  even when the provider supports relay.
- **Mailgun relay** — `relayRawMessage()` sends the raw MIME via the MIME
  endpoint with the right envelope and destinations; failure returns
  per-destination `false` exactly like `forwardEmail()` expects.
- **Regression** — `forward_and_store` still stores its copy regardless of relay
  outcome (the store path is independent of forwarding); a relay failure still
  logs `STATUS_ERROR` without changing the exit code.
- **Fallback parity** — with a non-relay provider, behavior is byte-identical to
  today's SMTP forward.
- `php -l` + `validate_php_file.php` on every changed PHP file.

## Documentation

Per the docs-in-existing-files convention:

- **`plugins/inbound_email/docs/overview.md`** — the forwarding section: explain
  that forwarding relays through the **selected outbound provider** when it
  supports raw-MIME relay (reusing its credential), and falls back to the
  `inbound_email_forwarding_smtp_*` / `smtp_*` SMTP relay otherwise. Note the SRS
  per-path behavior. Update the "Outbound forwarding relay" Setup-tab check
  description.
- **`docs/email_system.md`** — document the new `RawMessageRelay` optional
  capability alongside `EmailServiceProvider`: what it's for (forwarding /
  raw-MIME relay with an envelope sender), which providers implement it, and that
  providers without it fall back to SMTP.

## Versioning

- `plugin.json` minor bump; `@version` on each changed file.
- No schema changes, no migration.

## Out of scope / non-goals

- **Changing the main transactional send path** — `EmailSender` /
  `EmailMessage` for normal outbound is untouched; this only adds an *optional*
  relay capability used by forwarding.
- **Implementing `RawMessageRelay` for every provider** — only the providers
  that genuinely support faithful raw-MIME relay with an envelope sender
  (Mailgun, SMTP, SES); the rest deliberately use the SMTP fallback.
- **Removing the SMTP relay** — it remains the correct fallback and the path for
  operators who point forwarding at a dedicated relay.
- **Reworking SRS** — its envelope rewriting is unchanged on the SMTP path; the
  provider-relay path's bounce ownership is documented, not redesigned.
- **The immediate credential fix** — supplying a valid Mailgun SMTP credential
  restores forwarding today and is independent of this spec.
