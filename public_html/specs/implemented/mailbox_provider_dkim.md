# Provider-Aware DKIM: Aligned Signing and a Truthful Setup Row

## Problem

When a deployment's outbound mail rides an API provider (Mailgun, SES), the
provider is the DKIM signer — yet the Setup tab's `domain.dkim` row still
checks for a locally generated opendkim key at `/etc/opendkim/keys/` and tells
the operator to generate one. On a relay-fronted deployment that row is wrong
twice over: composed mail never touches local Postfix/opendkim, and the local
mail stack is a decommission candidate. Following the row's instructions
publishes a key that signs nothing.

Worse, the record alone wouldn't help: `MailgunProvider::relayRawMessage()`
submits every message through the single configured `mailgun_domain` setting,
so Mailgun signs `d=<mailgun_domain>` regardless of the From domain. A hosted
alias at `user@customerdomain.example` gets a DKIM signature that does not
align with its From domain, and DMARC alignment fails even after the operator
publishes Mailgun's DKIM record for that domain.

(SES has no equivalent bug: SESv2 `sendEmail` selects the verified identity —
and its Easy DKIM keys — from the message's From domain automatically.)

## Design

Two halves, both required: submit through the right provider identity, and
have the Setup tab prescribe the provider's actual records.

### 1. `DkimRecordSource` capability (core)

A new opt-in interface in `includes/EmailServiceProvider.php`, following the
established capability pattern (`RawMessageRelay`, `ApiSubmissionRelay`):

```php
interface DkimRecordSource {
    /**
     * @return array{status: 'ok'|'not_registered'|'unreachable',
     *               records: array<int, array{type:string,name:string,value:string}>}
     */
    public static function getDkimStatus(string $domain): array;
}
```

- `ok` — the domain is registered with the provider; `records` lists the DNS
  records the provider requires for DKIM signing of that domain (possibly
  empty when the provider reports signing configured with nothing left to
  publish, e.g. SES BYODKIM).
- `not_registered` — the provider's API answered and the domain is not a
  sending domain there. The fix is at the provider dashboard, not in DNS.
- `unreachable` — the API did not answer; the caller renders UNKNOWN, never a
  fabricated verdict.

Implementations in this spec:

- **Mailgun** — `domains()->show($domain)`; DKIM records are the sending DNS
  records whose name contains `_domainkey` (TXT). A 404 from the API maps to
  `not_registered`; any other failure to `unreachable`.
- **SES** — SESv2 `GetEmailIdentity`; Easy DKIM tokens map to the three
  `<token>._domainkey.<domain>` → `<token>.dkim.amazonses.com` CNAMEs.
  `NotFoundException` maps to `not_registered`.

Other providers (Resend, SendGrid, Brevo, Mailjet, Postmark) can opt in later
by adding the interface; until then the Setup row falls back to generic
guidance naming the provider (see below). Postfix/SMTP never implement it —
local submission is what opendkim signs.

### 2. Aligned Mailgun submission

`MailgunProvider::relayRawMessage()` picks its API path domain per message:
when the envelope sender's domain is an **active** sending domain in the
Mailgun account, submit through it (Mailgun then signs `d=` that domain,
giving DMARC alignment); otherwise fall back to the configured
`mailgun_domain` exactly as today. The account lookup is cached per request
per domain, and any lookup failure falls back — a send never breaks because
the domains API hiccuped.

This covers every relayRawMessage caller correctly:

- Hosted-alias compose (`RawRelayComposeTransport`): envelope = the alias
  address → aligned signing once the operator registers the domain at Mailgun.
- Protected-domain compose: envelope = the forwarding subdomain (not in the
  account → fallback). Alignment rides the in-app signature by design; the
  provider's own signature remains a harmless extra.
- Inbound forwarding: envelope = the SRS address at the site's domain; if that
  domain is registered at Mailgun it gains alignment, else unchanged behavior.

`send()`/`sendBatch()` (platform system mail from the site's own address) are
unchanged — the configured `mailgun_domain` is the right identity there.

### 3. Setup tab `domain.dkim` rows by outbound path

A `dkimPlan()` beside `spfPlan()` decides which signing paths carry this
domain's mail; `checkDomain()` renders rows accordingly (protected/Fortress
domains are untouched — `protectedShapeResults()` owns their DKIM shape):

- **Fronted, provider outbound** — provider-driven rows replace the local-key
  check entirely (nothing rides local Postfix):
  - Provider implements `DkimRecordSource`: one row per required record, each
    verified against live DNS (TXT compared by the `p=` key body, CNAME by
    target). Missing/mismatched → WARN with the copy-ready `dns_record` fix;
    published → PASS. `not_registered` → WARN explaining that mail from the
    domain fails DMARC alignment until the domain is added at the provider,
    with a dashboard fix. `unreachable` → UNKNOWN.
  - Provider without the capability: a single WARN telling the operator to
    publish the DKIM record the provider issues for the domain (generic, but
    never prescribes opendkim).
- **Fronted, smarthost outbound** — a WARN stating sent mail leaves through
  the relay smarthost without DKIM signing (relay-side signing is deferred;
  see Out of scope).
- **Colocated** — the existing opendkim row is unchanged (it signs what local
  Postfix sends, e.g. forwards). When the active provider also implements
  `DkimRecordSource`, additional `domain.dkim_provider*` rows verify the
  provider's records too — composed mail rides the provider even colocated.

Severity stays RECOMMENDED throughout: receiving works without DKIM; sending
deliverability is what's at stake.

## Out of scope

- **Relay smarthost DKIM signing** (opendkim on the relay, or in-app signing
  for non-protected domains): deferred. The Setup row states the gap honestly.
- **DkimRecordSource for the remaining providers** — opt-in later; the
  generic row keeps them truthful meanwhile.
- **SPF row changes** — already provider-aware; untouched.

## Tests

- `plugins/mailbox/tests/setup_topology_test.php` — new sections: `dkimPlan`
  branching per topology/outbound mode, and provider-driven row rendering via
  a fake `DkimRecordSource` provider against `FakeDnsBackend` (published /
  missing / mismatched / not_registered / unreachable; colocated keeps the
  local row and adds provider rows).
- `tests/email/provider_dkim_test.php` — pure-logic checks for the Mailgun
  submission-domain pick (active → From domain; unknown/inactive/lookup-fail →
  configured fallback; envelope without a domain → fallback) and record
  filtering.

## Documentation

- `docs/email_system.md` — the `DkimRecordSource` capability alongside the
  other provider capabilities; Mailgun's per-domain submission behavior.
- `plugins/mailbox/docs/overview.md` — the Setup tab DKIM row description.
