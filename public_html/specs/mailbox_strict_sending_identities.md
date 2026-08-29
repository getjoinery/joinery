# Strict Sending Identities: Bare Domain for Humans, Subdomains for Machines

## Goal

Every domain whose mail this platform touches should be able to publish
**strict DMARC** (`aspf=s; adkim=s`) and have every legitimate sender still
pass. Relaxed alignment is not a design input: it papers over shared
organizational domains and stops working the moment two systems send as the
same bare domain with different signing identities. The doctrine that makes
strict possible is the one the Fortress security level already mandates:

> **The bare domain belongs to humans. Automated senders live on a subdomain.**

Under this doctrine every sender signs exactly as its own From domain — hosted
mailbox mail as `d=<domain>` (per-domain provider registration + aligned
submission, built by specs/implemented/mailbox_provider_dkim.md), site
transactional mail as `d=<subdomain>` — and strict alignment holds for both.
Raising a domain to Fortress later changes nothing about this layout.

## Current state (the concrete instance: scrolldaddy.app)

Two deployments send as scrolldaddy.app today, and they collide on one
address:

- **ScrollDaddy product site** (node `scrolldaddy`): system/transactional mail
  From `info@scrolldaddy.app` (`defaultemail`), via Mailgun sending domain
  `mg.scrolldaddy.app`. Signature `d=mg.scrolldaddy.app` — passes DMARC only
  under relaxed alignment.
- **jeremytunnell mailbox deployment** (node 176, relay-fronted): hosts the
  `info@scrolldaddy.app` mailbox (store-mode alias, receiving verified
  2026-07-20). Compose will sign `d=scrolldaddy.app` once the domain is
  registered at Mailgun — the Setup tab DKIM row already demands and verifies
  exactly that.

So the same address is simultaneously a human mailbox and a robot sender, and
strict DMARC would break the robot half.

## Plan

### 1. Platform: default Reply-To setting (small, general)

`EmailMessage` supports a per-message Reply-To but there is no system-wide
default; `EmailSender` reads only `defaultemail`/`defaultemailname`. Add a
core setting `defaultreplyto` (settings.json, default `''`): when non-empty
and a message carries no explicit reply-to, `EmailSender` applies it. This is
what lets any deployment move its From to a machine subdomain while replies
still land in a human mailbox. Surface it on the admin settings page next to
`defaultemail`.

### 2. ScrollDaddy site: move transactional From off the bare domain

- **DECIDED (owner, 2026-07-21):** the sending subdomain is
  **`mail.scrolldaddy.app`**, sender address `hello@mail.scrolldaddy.app`.
  Rejected `mg.scrolldaddy.app` (already registered, zero setup) because the
  name is Mailgun's own convention — the visible sender identity would be tied
  to a vendor that can be swapped, and it reads as a machine artifact.
  `mail.` is the most conventional vendor-neutral choice. `news.scrolldaddy.app`
  is reserved for marketing/bulk mail if that traffic ever starts, so
  transactional reputation stays isolated from it.
  - Naming constraint that follows: `mail.scrolldaddy.app` is now a *sending
    identity*, not a host — do not also use it as the mail server's A/PTR
    hostname. The relay-fronted deployment does not need it as a host record.
- Register `mail.scrolldaddy.app` as a Mailgun sending domain and publish its
  DKIM/SPF records.
- Then on the scrolldaddy node: set `defaultemail` to
  `hello@mail.scrolldaddy.app`, `mailgun_domain` to `mail.scrolldaddy.app`
  (keeps the API path = signing identity = From domain, exactly aligned), and
  `defaultreplyto` to `info@scrolldaddy.app` so replies land in the hosted
  mailbox.
- `mg.scrolldaddy.app` stays registered until step 5 verifies the new identity,
  then is retired.

**DNS + provider registration DONE (2026-07-21).** Both sending domains are
registered and `active` at Mailgun, records published to Cloudflare:

| Name | Type | Purpose |
|---|---|---|
| `krs._domainkey.mail.scrolldaddy.app` | TXT | DKIM for the site's machine identity |
| `mail.scrolldaddy.app` | TXT | `v=spf1 include:mailgun.org -all` (hardfail, matching the apex — Mailgun's suggested `~all` deliberately not used) |
| `mx._domainkey.scrolldaddy.app` | TXT | DKIM for the hosted mailbox's own identity |

The apex SPF was left untouched — Mailgun validates the bare domain against the
existing `v=spf1 include:mailgun.org -all`. Publishing the second SPF record
Mailgun offers would have broken SPF for every sender on the domain.

**Account consolidation (2026-07-21).** All of it lives in the Mailgun account
the jeremytunnell mailbox deployment (node 176) already uses — the same account
holding `mail.jeremytunnell.com`, `mg.dev.getjoinery.com`, `phillyzouk.org`. So
the mailbox deployment needs no credential change. Two blockers found on the
ScrollDaddy site node while checking:

1. Its `mailgun_api_key` is **disabled** at Mailgun — that site's transactional
   mail is broken at the API level today, independent of this work. Needs the
   consolidated account's key.
2. It runs **0.8.87** against a 0.8.174 management node, so `defaultreplyto` (step
   1's setting) is not seeded there. Upgrade required before step 2 completes.

### 3. Mailbox side: register the bare domain at the provider

In the Mailgun dashboard (same account as mg.scrolldaddy.app), add
`scrolldaddy.app` as a sending domain. The jeremytunnell Setup tab's DKIM row
then serves the exact TXT record to publish and verifies it against live DNS;
the SPF row already prescribes the fronted-provider shape
(`v=spf1 include:mailgun.org -all` — never the box or relay IP). Compose from
`info@scrolldaddy.app` then submits through the domain's own registration and
signs `d=scrolldaddy.app` (aligned submission is already live in
`MailgunProvider::relayRawMessage`).

### 4. Publish strict DMARC on scrolldaddy.app

Once 2 and 3 verify (both senders exactly aligned):

```
_dmarc.scrolldaddy.app  TXT  v=DMARC1; p=quarantine; aspf=s; adkim=s; rua=mailto:postmaster@scrolldaddy.app
```

Raise `p=quarantine` to `p=reject` after the rua reports show nothing
legitimate failing. (`p=none` first is optional monitoring theater here — the
sender inventory is exactly two systems, both verified aligned in step 5.)

**PUBLISHED 2026-07-25.** The record above is live on `_dmarc.scrolldaddy.app`
(Cloudflare, TTL auto), confirmed resolving through 1.1.1.1. Sender inventory
re-checked before publishing: the ScrollDaddy site sends as
`hello@mail.scrolldaddy.app`, the mailbox deployment as `info@scrolldaddy.app`,
and no other node carries a `scrolldaddy.app` sending identity. Aggregate
reports to `postmaster@scrolldaddy.app` are deliverable — the domain's
catch-all is `store` with `reject_unmatched` off, so they land in the hosted
mailbox rather than nowhere.

### 5. Verification

- From the jeremytunnell mailbox, compose to a Gmail account; confirm in the
  received headers: `DKIM d=scrolldaddy.app`, SPF domain =
  scrolldaddy.app-aligned envelope, `dmarc=pass` with strict identifiers.
- Trigger a ScrollDaddy site transactional send (e.g. password reset);
  confirm `DKIM d=mail.scrolldaddy.app`, `dmarc=pass`, and that replying lands
  in the info@ mailbox on jeremytunnell (Reply-To honored).
- Confirm relay-forwarded/received mail is unaffected (DMARC on our domain
  governs mail *from* it, not to it).

### 6. Setup tab: prescribe strict-capable DMARC (platform, general)

The Setup tab's DMARC row currently prescribes
`v=DMARC1; p=none; rua=mailto:postmaster@<domain>`. For mailbox domains under
provider outbound, exact alignment is guaranteed by doctrine, so the
prescription should include `aspf=s; adkim=s` from the start — with row
helptext noting that any *other* systems sending as the bare domain must be
moved to subdomains first (this spec's doctrine). Fortress domains already
get the strict inverted shape from `protectedShapeResults()`; this closes the
gap for Standard/Private.

## Later instances of the same pattern

- **getjoinery.com** on the jeremytunnell deployment: same steps — register
  the bare domain at Mailgun, keep any automated getjoinery senders (e.g. the
  dev site's own system mail) on their own subdomain identity, then strict
  DMARC. Nothing new to build.
- Every future hosted domain follows the doctrine at creation time: the Setup
  rows (DKIM per-domain registration + strict DMARC prescription) walk it.

## Out of scope

- Relay smarthost DKIM signing (recorded in
  specs/implemented/mailbox_provider_dkim.md as deferred).
- Fortress migration of scrolldaddy.app (separate ceremony; this layout is a
  prerequisite it will inherit unchanged).
