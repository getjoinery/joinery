# Mailbox — Relay Default: Inbound-Only (Outbound Rides the Provider)

**Status:** Implemented (2026-07-11)
**Version:** 1.1 — post-review fix pack: probe targets a real store-mode alias;
tunnel check is a live submission handshake; mode switch surfaces the Rebuild
requirement; origin scanner matches on token boundaries.
**Builds on:** `specs/implemented/mailbox_hardened_ingest_relay.md` (the relay),
`specs/implemented/mailbox_outbound_send_protection.md` (protected identity
domains, app-held DKIM, closure 3's provider-transport rule).
**Amends:** the *Outbound* section (§ Architecture item 6) of the hardened
ingest relay spec and Phase 7 of its executor: compose sends no longer route
through the relay by default. The relay smarthost becomes an explicit opt-in.

## Goal

The relay was designed to carry mail in both directions: inbound (accept,
verify, seal, spool) and outbound (compose sends as smarthost, so the sent
`Received:` chain shows the relay instead of the Joinery box). The inbound
half is the security product. The outbound half quietly hands the operator
the one email problem this platform has deliberately outsourced since day
one: **sending reputation**. A relay that originates compose mail from its
own IP owns warmup, blocklist monitoring, PTR hygiene, and the opaque
filtering decisions of Google and Microsoft — the entire reason relay-class
providers (Mailgun, SES, …) exist. No relay operator signed up for that, and
the planned shared fleet cannot take it on at all.

The fix: **the relay defaults to inbound-only.** Compose sends leave through
the deployment's configured outbound provider — the same path every
colocated deployment already uses — with one added constraint that preserves
the hidden origin (below). The smarthost stays in the codebase as an opt-in
for operators who specifically want no third party touching outbound
plaintext and accept owning sender reputation in exchange.

## Why Routing Outbound Through the Provider Is Safe

**The origin leak is a property of SMTP submission, not of providers.**
The smarthost's justification was: "otherwise every sent message's
`Received:` chain would leak Joinery's IP." That is true when the box
submits over SMTP — the receiving MTA stamps the connecting client's IP into
the first `Received:` header. It is not true of HTTP API submission: the
message's `Received:` chain begins inside the provider's infrastructure, and
the submitting client's IP appears nowhere in the delivered message. So the
requirement is a **constraint, not a component**: on a relay-fronted
deployment with the smarthost off, outbound mail must leave via an API-class
raw-MIME path — never SMTP submission.

The platform already has that path: `RawMessageRelay` (declared in
`includes/EmailServiceProvider.php`), implemented by Mailgun
(`messages.mime`) and SES (SESv2 `Content.Raw`). `SmtpProvider` also
implements it but is SMTP submission and therefore excluded by the
constraint on relay-fronted deployments.

**The privacy cost is marginal, not categorical.** Every message sent to an
external address is delivered in plaintext to the recipient's provider —
Gmail reads your sent mail no matter what transport carried it. Outbound
confidentiality is bounded by the recipient's side; a provider seeing the
message in transit adds a second reader to a set that already has one.
Mail whose transit privacy genuinely matters is the encrypted-interop path
(`specs/mailbox_encrypted_interop.md`), which is ciphertext before it leaves
the box and stays ciphertext through any transport. Inbound is where the
asymmetry lives — inbound lands in the operator's own archive under the
user's keys — and inbound keeps the relay. This accounting goes in the
public security model doc, stated plainly.

**The outbound send protection spec anticipated exactly this.** Its closure
3 rule: provider-API transports "must either carry the app-produced
signature in the raw message or are simply not used for protected-domain
mail." The raw-MIME path carries the app-produced signature byte-for-byte.
The protected domain's other invariants are untouched: it is never a
verified sending domain at the provider, no provider include appears in its
SPF, and DMARC passes on the strict-aligned in-app DKIM signature alone
(sealed key, unwrapped in-window). The provider's own SPF/DKIM apply to the
envelope/submission domain (the forwarding subdomain, where providers are
already fully usable per that spec) and are unaligned by design.

## Design

### Transport resolution (`OutboundTransport::forHostedAlias()`)

Current behavior: an active relay short-circuits every hosted-domain send to
`SmtpConfig::fromRelaySmarthost()`. New behavior:

1. **Relay active + smarthost opt-in enabled** — unchanged: relay smarthost
   over the tunnel, in-app DKIM, relay only transports.
2. **Relay active + smarthost off (the new default)** — resolve the active
   provider; it must implement `RawMessageRelay` and be API-class. Send via
   `relayRawMessage()` with the fully formed, app-signed message:
   - **Protected domains:** DKIM signed in-app per the outbound spec (the
     session-gated compose path and ambient-send guard semantics are keyed
     off the transport exactly as they are for the direct-submission branch
     today). Envelope sender (MAIL FROM) on the forwarding subdomain.
   - **Non-protected hosted domains:** same raw path; the provider's normal
     alignment for the domain applies if it is verified there.
3. **No relay (colocated)** — untouched. Direct submission for protected
   domains (`fromForwardingSettings()`), platform active provider otherwise.
   Colocated deployments never had a hidden origin to protect.

### The smarthost opt-in

A mailbox plugin setting (declared in `plugin.json` `settings`, factory
default **off**): relay outbound mode = provider (default) | smarthost.
Surfaced on the mailbox Setup tab's relay section as a single FormWriter
select — "Sent mail leaves through:" with options "Your email provider
(recommended)" and "The relay (advanced)". Each option carries one line of
helptext stating its consequence (provider: deliverability is the
provider's job, and it carries the message in transit; smarthost: no third
party carries sent mail, and the deployment owns the relay IP's sending
reputation — warmup, blocklists, PTR), shown one-at-a-time for the current
selection via `visibility_rules`. The selection also drives which setup
checks appear: the provider-class check in provider mode, the tunnel-SMTP
check in smarthost mode — the check list always matches the chosen path,
never shows N/A rows. No confirmation step beyond the select itself. No
migration concerns (pre-launch, no production users).

### Setup checks (`InboundEmailSetupCheck` / `InboundEmailHealth`)

- **Outbound transport class** — relay-fronted + smarthost off: the active
  outbound provider implements `RawMessageRelay` and is API submission
  (provider self-declares; `SmtpProvider` fails the check). Colocated or
  smarthost on: no-op.
- **Outbound origin leak probe** — relay-fronted deployments: send a probe
  message from a real enabled store-mode alias to itself (it travels out via
  the real outbound path and back in via the relay MX), then scan the
  delivered headers — full `Received:` chain, `Message-ID`,
  `X-Mailer`/originating headers — for the Joinery box's public IP or
  internal hostname, matched on token boundaries (the IP never matches inside
  a longer IP; the hostname never matches inside a distinct token). Pass =
  absent. This checks the *fact* (no leak) rather than the *mechanism* (API
  vs SMTP), so it also catches a provider that starts stamping submitter IPs.
  ✅ CONFIRMED at build: in-app header generation (`Message-ID`, etc.) derives
  from the mail hostname, never `gethostname()`/server IP —
  `RawRelayComposeTransport` pins `SmtpMailer->Hostname` to
  `mailbox_mail_hostname` (see Open Items).
- **Tunnel accepts compose submission** — relay-fronted + smarthost on: a
  live SMTP dialogue over the tunnel (EHLO, `MAIL FROM:<>`, RCPT to a
  reserved `.invalid` recipient, QUIT — nothing is ever sent). Port 25
  answers in both modes (the same smtpd receives inbound mail), so a bare TCP
  connect proves nothing; only the relay *accepting* an external recipient
  proves `permit_mynetworks` trusts the tunnel subnet. A relay provisioned
  inbound-only fails this check until rebuilt in smarthost mode — the failure
  message names the fix. Provider mode or colocated: no-op.

### What does not change

- **Forwarding stays relay-side.** Forward-mode aliases execute at the relay
  (SRS + relay onward) — that is inbound mail being redistributed at the
  plaintext-arrival moment, and it must work while the user is logged out
  (Fortress mail is sealed to the user's key; Joinery cannot open it to
  forward). The relay therefore still originates *forwarding* SMTP from its
  IP even in inbound-only mode: a bounded sending surface (destinations are
  the operator's own user-chosen mailboxes, low volume, high engagement),
  not reputation ownership in the ESP sense. The open item on relay-side
  per-alias/per-domain forward rate limits carries over from the relay spec.
- **Inbound path** — accept, verify milters, RBL, recipient map, seal,
  spool, pull: untouched.
- **DKIM signing location** — in-app always; the relay never held a sending
  identity key and still doesn't.

### Threat model update

Relay compromise yields **inbound transit mail only** by default (from
compromise until rebuild). The "both directions" clause in the relay spec's
threat model applies only when the smarthost opt-in is enabled. The relay's
sending capability in default mode is limited to forwarding already-received
inbound mail onward.

## Integration Points That Change

- **`OutboundTransport::forHostedAlias()`** — branch order per Design; the
  relay branch consults the opt-in setting; new raw-MIME provider branch.
- **`EmailSender`** — the compose path can hand a fully formed signed
  message to `relayRawMessage()`; the ambient-send guard's recognition of
  the session-gated compose path extends to this transport.
- **`plugins/mailbox/plugin.json`** — the new outbound-mode setting.
- **`InboundEmailSetupCheck` / `InboundEmailHealth`** — the three checks
  above; the relay-tunnel check is conditional on smarthost mode and performs
  the live submission handshake rather than a TCP connect.
- **`provision_relay.sh`** — smarthost/submission listener provisioned only
  when opted in (default provisioning opens nothing for it). The listener
  state is baked at provision time, so a mode switch takes effect on the
  relay itself only at the next Rebuild; the mode select's save message says
  so in both directions (to smarthost: sends refused until Rebuild opens the
  listener; back to provider: the listener stays open until Rebuild closes
  it), and the handshake check fails honestly in the interim.

## Documentation to Update

Current-state only, per docs rules:

- `plugins/mailbox/docs/overview.md` — relay architecture section: relay is
  inbound (accept/verify/seal/spool) + forwarding; compose sends leave via
  the provider's raw-MIME API on relay-fronted deployments; smarthost
  described as the opt-in with its tradeoff.
- `specs/mailbox_security_model_public.md` (and pentest brief) — the
  outbound privacy accounting (recipient-provider bound; provider transit;
  encrypted interop unaffected) and the default relay compromise window
  (inbound-only).
- `docs/email_system.md` — `RawMessageRelay` compose-path usage if the
  provider-capability table is documented there.

## Open Items — Resolved During Implementation

### ⟨VERIFY⟩ Mailgun raw-MIME round-trip (dev, 2026-07-11) — CONFIRMED

Sent a raw MIME through `MailgunProvider::relayRawMessage()` (the exact
`messages.mime` path `RawRelayComposeTransport` uses) `From:
phase3.sender@dev.getjoinery.com` — a domain **not** verified at the provider
(the Mailgun-verified sending domain is `mg.dev.getjoinery.com`) — to a store
alias on the same deployment (`security-eval@dev.getjoinery.com`).

- **Delivery:** accepted by Mailgun (`relayRawMessage` returned
  `['security-eval@dev.getjoinery.com' => true]`) and delivered to the MX; the
  inbound log recorded `stored` ~2s later (message id 1939).
- **DMARC alignment:** the receiving milters stamped **SPF=pass, DKIM=pass,
  DMARC=pass** (`iem_auth_source = milter`). So a `From:` on a
  not-provider-verified domain delivers DMARC-aligned with no provider-side
  rejection. On dev the alignment came from Mailgun's `d=mg.dev.getjoinery.com`
  signature under relaxed DMARC (org domain `getjoinery.com`); the strict
  protected-domain path (in-app `d=<bare domain>`, `aspf=s; adkim=s`, box
  excluded from SPF) was **not** exercised live — it needs the sealed-key
  protect ceremony + published strict records + a relay, none present on dev.
- **Origin-leak (⟨VERIFY⟩ header generation):** the message the box hands to
  Mailgun carries **no box public IP** (`69.164.209.253`) and **no
  `gethostname()`** (`localhost`) in any generated header; its `Message-ID`
  derives from the **mail hostname** (`<…@devmail.getjoinery.com>`), confirmed
  both in the generated headers and in the delivered stored `Message-ID`.
  `RawRelayComposeTransport` pins `SmtpMailer->Hostname` to the mail hostname so
  this holds regardless of the `smtp_hostname` setting.

### ⟨VERIFY⟩ Provider does not stamp the API caller's IP — CONFIRMED (by construction + partial live)

`ApiSubmissionRelay` is a self-declared property: an HTTP-API submission's
`Received:` chain begins inside the provider, and the submitting client's IP
appears nowhere. `SmtpProvider` (SMTP submission) is excluded from the compose
path by not implementing the interface. The generated-header inspection above
found no origin leak. The **delivered** Mailgun `Received:` chain could not be
inspected on dev because this deployment's lean-record ingest discards the raw
message after parse (`iem_raw_message` empty, driver `inline`), and the mail
logs are not readable by the web user — so "no submitter IP in Mailgun's added
headers" rests on the API-submission property, not a live delivered-header read.
The live delivered-header scan is what `checkOutboundOriginLeak` performs on a
relay-fronted deployment that retains the sealed raw.

### Probe-alias mechanics — DECIDED

Reuse the existing send machinery and an existing alias rather than a
dedicated hidden one: `InboundEmailHealth::sendOriginProbe()` sends from the
first enabled store-capable alias (store or forward_and_store) on an enabled
non-Fortress domain to itself, marked with an `X-Joinery-Origin-Probe` header,
through the real outbound path. The target must be a **real listed alias**
(the relay's SMTP-time recipient validation rejects anything else under
`reject_unmatched` — an invented address would bounce and the round trip
would silently never complete), **store-mode** (so the delivered copy lands
in `iem_inbound_email_messages` where the check can find it), and
**non-Fortress** (a Fortress recipient's delivered raw is sealed to the
owner's key, unreadable to the server-side scan). With no qualifying alias
the probe refuses with an actionable message instead of sending.
`checkOutboundOriginLeak()` finds the round-tripped copy by the marker
(bounded to a 7-day window) and scans its delivered headers with the pure,
unit-tested `scanHeadersForOrigin()`. The probe is offered as a "Run
origin-leak probe" button on the Relay tab (provider mode only).

## Not Exercised Live (stated plainly)

- **Full relay round-trip** (compose out via provider, back in via the relay MX,
  then the delivered-header scan): dev has no active relay (`mrl_is_enabled =
  false`; colocated). Verified through the test estate instead — the pure
  `scanHeadersForOrigin` detector (including its token-boundary matching) and
  the `RawRelayComposeTransport` behavior against a stub `ApiSubmissionRelay`
  (22 checks, `plugins/mailbox/tests/relay_outbound_inbound_only_test.php`).
  The probe target selection and the SMTP handshake helpers were additionally
  exercised live against dev data and the local Postfix.
- **Strict protected-domain alignment** over the raw-MIME path (see the DMARC
  note above).
- **`provision_relay.sh` smarthost-gate** on a real VPS: verified by `bash -n`
  and the conditional `postconf` logic; not run against a live relay VPS.
