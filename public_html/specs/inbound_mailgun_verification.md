# Inbound webhook-provider authentication verdicts (companion to the DKIM verification fix)

## Context

This spec is split out from `specs/implemented/inbound_dkim_verification_fix.md`. That
spec retires the false-failing hand-rolled DKIM verifier and reads verdicts from the
message's `Authentication-Results` header (RFC 8601) instead — for the **self-hosted
Postfix** path, where the receiving MTA's milters (opendkim verify + opendmarc) stamp a
trusted line. It explicitly **defers** the third-party webhook providers to here.

Read the parent spec first. The data model (`iem_spf_result` / `iem_dkim_result` /
`iem_dmarc_result` / `iem_auth_source`), the `AuthenticationResults` parser, the
router's `readAuthResults()` / `unverified` semantics, and the capability warning in
`InboundEmailSetupCheck` all come from there and are assumed in place. This spec adds
**verdict sources for the webhook providers**.

## Goal

Make inbound mail that arrives via a third-party **webhook provider** (Mailgun,
SendGrid, SES, …) carry real SPF/DKIM/DMARC verdicts — sourced from that provider's
own upstream verification — instead of `unverified`.

## Key correction to the prior draft

The earlier version of this spec assumed Mailgun reports verdicts inside the
`body-mime` the router already parses, and that the fix was therefore a small fallback
*inside `AuthenticationResults`*. **That is wrong for the real webhook providers:**

- **Mailgun** posts its verdicts as the headers `X-Mailgun-Spf` /
  `X-Mailgun-Dkim-Check-Result` and (per the route-URL-posts release) in the POST
  payload — separate from the SMTP `Authentication-Results` chain.
- **SendGrid Inbound Parse** posts verdicts as **top-level form fields** `SPF` and
  `dkim` — never in a MIME header at all (the raw MIME is an optional separate field).
- **SES** delivers verdicts as a JSON `receipt` object over SNS — not in the MIME.

So the verdict does **not** reliably live in the MIME, and the correct seam is **each
provider's `handleInbound()`** — which already owns the authenticated POST/SNS payload
— not a MIME parser. `AuthenticationResults` stays single-purpose (standard
`Authentication-Results` only); the provider classes do their own mapping.

This is the one interface change the parent spec flagged as the only thing that would
force a signature change ("provider posting verdicts solely as separate form fields,
never in body-mime") — and it is in fact how Mailgun and SendGrid both work.

## Why this is a real (no-setup) build, not speculation

The inbound formats for the three providers below are **documented with fixed field
names and value enums**, so the mapping can be written from docs without provisioning
an account or capturing a live sample first. The fail-safe makes this low-risk: a
field we read wrong (or a value we don't recognize) falls through to `unverified` — it
**never** fabricates a `pass`. Confirm each provider's mapping against one real message
before fully trusting it, but shipping need not block on a live capture.

The exception is providers whose inbound auth fields are **not documented** (Brevo,
Mailjet, Resend) — those genuinely require a captured sample first and are left for a
later pass (see the inventory table).

## Approach — verdict source moves into the provider

### Interface (documented shape change only — no PHP signature change)

`InboundEmailProvider::handleInbound(array $post, string $raw_body): ?array` today
returns `array{raw_mime, recipient}`. Extend the **documented** return to:

```
array{
  raw_mime:  string,
  recipient: string,
  auth?:     array{                // optional; present only when the provider
    spf:         ?string,          //   verified the message upstream
    dkim:        ?string,
    dmarc:       ?string,
    spf_domain?:  string,
    dkim_domain?: string,
    source:      string,           // the provider key: 'mailgun'|'sendgrid'|'ses'
  }
}
```

Verdict tokens are normalized to the same lowercase set `AuthenticationResults`
produces: `pass | fail | softfail | neutral | none | temperror | permerror`. A method
the provider doesn't assert is `null` (→ recorded `none`, matching the milter path —
the provider *did* verify, it just didn't report that method). A provider that does no
verification omits `auth` entirely (→ `unverified`, unchanged).

### Router precedence

`InboundEmailRouter::processEmail($raw_email, $envelope_recipient)` gains an optional
third argument `$provider_auth = null`, threaded from the dispatcher
(`handleInbound()`'s `auth` key). `readAuthResults()` resolves in this order:

1. **Provider auth present** → use it; `iem_auth_source` = the provider key. (Trusted
   because it came from the provider's authenticated payload — see Security.)
2. **Standard `Authentication-Results`** stamped by our authserv-id (Postfix milter
   path) → `AuthenticationResults::fromMessage()`; source `milter`. (Unchanged.)
3. **Neither** → `unverified`. (Unchanged.)

No change to the `AuthenticationResults` class. No change to the webhook dispatcher
beyond passing `$result['auth'] ?? null` into `processEmail()`.

## Provider inventory — decide once, up front

Every existing provider class, with its inbound-verdict source and disposition:

| Provider class      | Inbound role | Verdict source | Status in this spec |
|---------------------|--------------|----------------|---------------------|
| `PostfixProvider`   | pipe         | standard `Authentication-Results` (milter) | **Done** (parent spec) |
| `MailgunProvider`   | webhook      | `X-Mailgun-Spf`, `X-Mailgun-Dkim-Check-Result` (POST + MIME headers) | **Build now** (docs) |
| `SendGridProvider`  | webhook      | `SPF`, `dkim` form fields (Inbound Parse) | **Build now** (docs) — also add `InboundEmailProvider` |
| `SesProvider`       | webhook/SNS  | `receipt.{spf,dkim,dmarc}Verdict.status` | **Build now** (docs) — also add `InboundEmailProvider` + SNS transport |
| `PostmarkProvider`  | webhook      | no dedicated fields — `Received-SPF` / `Authentication-Results` in `Headers[]` / `RawEmail` | **Second wave** — header-scrape, not field-mapping |
| `BrevoProvider`     | webhook      | inbound parse exists; **auth fields undocumented** | **Needs captured sample** |
| `MailjetProvider`   | webhook      | parse API exists; **auth fields undocumented** | **Needs captured sample** |
| `ResendProvider`    | webhook      | receiving exists; **auth fields undocumented** | **Needs captured sample** |
| `SmtpProvider`      | — (outbound) | n/a | N/A — not an inbound transport |

### Mapping — Mailgun (`source = 'mailgun'`)

Read from the `X-Mailgun-Spf` / `X-Mailgun-Dkim-Check-Result` headers (present in the
stored MIME *and* posted as fields — prefer reading them out of `body-mime`, which
`handleInbound()` already has, so we don't depend on the exact POST field name):

- `X-Mailgun-Spf`: `Pass→pass`, `Neutral→neutral`, `Fail→fail`, `SoftFail→softfail`.
- `X-Mailgun-Dkim-Check-Result`: `Pass→pass`, `Fail→fail`.
- **DMARC**: Mailgun exposes no DMARC verdict → `dmarc = null` (recorded `none`).
- Domain: take `spf_domain` from `Received-SPF` / envelope sender, `dkim_domain` from
  the `DKIM-Signature` `d=` if readily available; otherwise omit.

### Mapping — SendGrid Inbound Parse (`source = 'sendgrid'`)

Read from the multipart POST fields (no MIME header equivalent):

- `SPF` field: already lowercase `pass | fail | softfail | neutral | none | temperror
  | permerror` → map straight through.
- `dkim` field: a string like `{@example.com : pass}` → parse the result token into
  `dkim` and the domain into `dkim_domain`.
- **DMARC**: not provided → `dmarc = null` (recorded `none`).

### Mapping — AWS SES (`source = 'ses'`)

SES delivers inbound via **SNS** (notification JSON; message body in the SNS payload or
S3), a different transport from a multipart webhook. The provider's `handleInbound()`
parses the SNS envelope (and verifies the SNS signature — see Security) and reads
`receipt`:

- `receipt.spfVerdict.status`, `receipt.dkimVerdict.status`,
  `receipt.dmarcVerdict.status`: `PASS→pass`, `FAIL→fail`, `GRAY→none` (no
  policy / insufficient info), `PROCESSING_FAILED→null` (recorded `none`).
- SES is the **only** webhook provider that supplies a real DMARC verdict.

## Files

### To modify
| File | Change |
|------|--------|
| `includes/InboundEmailProvider.php` | document the optional `auth` key in `handleInbound()`'s return shape; `@version` |
| `includes/email_providers/MailgunProvider.php` | populate `auth` (`source='mailgun'`) from `X-Mailgun-*` headers in `handleInbound()`; `@version` |
| `includes/email_providers/SendGridProvider.php` | add `implements InboundEmailProvider` + inbound methods; populate `auth` (`source='sendgrid'`) from `SPF` / `dkim` POST fields |
| `includes/email_providers/SesProvider.php` | add `implements InboundEmailProvider` + SNS inbound handling; populate `auth` (`source='ses'`) from `receipt.*Verdict.status` |
| `plugins/inbound_email/includes/InboundEmailRouter.php` | add optional `$provider_auth` arg to `processEmail()`; `readAuthResults()` prefers provider auth over standard AR; `@version` |
| `plugins/inbound_email/ajax/inbound_email_webhook.php` (and `utils/inbound_email_handler.php`) | pass `$result['auth'] ?? null` from `handleInbound()` into `processEmail()` |
| `plugins/inbound_email/includes/InboundEmailSetupCheck.php` | treat Mailgun/SendGrid/SES as **verifiable** inbound providers (drop the "unverified" warning for them); keep it for providers still in "needs captured sample" |
| `plugins/inbound_email/tests/authentication_results_test.php` (or a new `provider_auth_test.php`) | fixtures per provider: assert mapped verdicts + correct `source`; assert a forged field on the Postfix path is ignored |
| `plugins/inbound_email/docs/overview.md` | document `mailgun` / `sendgrid` / `ses` as real `iem_auth_source` values and the provider-verdict precedence |

### Not changed
- `AuthenticationResults.php` — stays standard-`Authentication-Results`-only.
- SES SNS-transport plumbing beyond what `handleInbound()` needs (full SES inbound
  receipt-rule setup is its own concern; this spec only maps the verdict once a message
  arrives).

## Testing

- **Per provider** — feed a representative payload (Mailgun headers, SendGrid `SPF` +
  `dkim` fields, SES `receipt` JSON); assert `spf`/`dkim`(/`dmarc` for SES) map to the
  normalized tokens and `iem_auth_source` is the provider key.
- **Unknown value → fail-safe** — an unrecognized verdict token yields `unverified` (or
  `none`), never a synthesized `pass`.
- **Precedence** — a Postfix message with a standard `Authentication-Results` line
  still resolves via the milter path (`source='milter'`); a webhook message with
  provider auth uses the provider path.
- **Anti-spoofing** — a forged `X-Mailgun-Spf` / fake `SPF` field on a message that did
  **not** arrive through that provider's authenticated webhook is never honored.
- `php -l` + `validate_php_file.php` on changed PHP files.

## Security

- Provider verdicts are trusted **only** because they ride that provider's
  authenticated inbound path: Mailgun's HMAC signature
  (`MailgunProvider::handleInbound()` already verifies it), SES's SNS message-signature
  verification, and a shared-secret / restricted URL for SendGrid Inbound Parse (which
  does not HMAC-sign by default — the SendGrid inbound provider must gate on a secret
  before honoring its `SPF`/`dkim` fields).
- A forged `X-Mailgun-Spf`, `SPF`, or `receipt` blob on mail delivered by a *different*
  transport (e.g. Postfix) is never honored — the `auth` key only exists when the
  matching provider object handled the request; the standard-AR path ignores these
  headers entirely.
- Same authserv-id discipline as the parent spec for the milter path: a standard
  `Authentication-Results` line is trusted only when stamped by our authserv-id.

## Out of scope

- Everything the parent spec already covers (verifier removal, columns, parser core,
  Postfix milters, UI, capability warning).
- **Brevo / Mailjet / Resend** inbound verdicts — undocumented auth fields; require a
  captured sample before mapping. Listed in the inventory so the decision is on record,
  not forgotten.
- **Postmark** — its inbound webhook has no dedicated verdict fields; a `Received-SPF` /
  `Headers[]` scrape is a later, separate pass (header-scrape, not field-mapping).
- Acting on DMARC policy; outbound signing; ARC.
