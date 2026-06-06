# Spec: Connected-Account Email — Outbound for Any Provider

**Status:** Proposed (awaiting implementation)
**Scope:** Extend the existing SMTP send path (`SmtpMailer` / `SmtpProvider`) to authenticate as,
and reuse the credential of, an already-connected account — for **every provider the platform can
connect** — plus the per-mailbox **send transport** the reader reply/forward feature relies on.
**Related:** `specs/outbound_reply_forward.md`, `specs/two_way_imap_sync.md`, `docs/email_system.md`

This spec is about **reusing what we already have**, not adding a parallel sender. The platform
already sends via SMTP (`SmtpMailer` through PHPMailer) and the inbound side already connects
Gmail/Microsoft (OAuth) and Yahoo/iCloud/Fastmail/generic (app password) through one `PRESETS`
catalog. The job here is to let the **same SMTP sender** authenticate with those connected
credentials so one connection powers inbound **and** outbound.

---

## 1. Problem & Goal

A small operator already has *some* mailbox — Gmail, Outlook/M365, Yahoo, iCloud, Fastmail, or a
plain IMAP/SMTP host — and wants the whole site's email through it with no separate provider to set
up. We already *can* relay through such a mailbox via the SMTP provider (set `smtp_host`, port,
username/password by hand). Two things are missing, and both are narrow extensions of the existing
SMTP path — **not** new sending code:

1. **XOAUTH2 auth.** `SmtpMailer` only does basic `SMTPAuth` (username/password). Gmail and
   Microsoft increasingly require OAuth (Gmail app passwords need 2FA and are curtailed; M365 basic
   auth is largely disabled). We already hold those OAuth tokens from the IMAP feed — the SMTP layer
   just can't use them yet.
2. **Credential/config reuse.** `SmtpMailer` reads one global `smtp_*` config. The value of "connect
   once" is sourcing host/port from `PRESETS` and the credential from the **already-connected
   account** (`iia_oauth_*` / `iia_password_enc`), instead of re-typing settings and being limited to
   one account.

**Goal:** add those two capabilities to the existing SMTP path so any connected account is usable
for system/transactional outbound, and define the per-mailbox transport the reply/forward spec
consumes — with **minimal new code and a single send mechanism**.

Provider-agnostic by construction: per-provider differences (host, port, encryption, auth style) are
**data in `PRESETS`**, not branching code. Per-provider send limits are the user's signal to move to
an ESP (Mailgun/SES, already supported); the spec makes that transition graceful.

## 2. Non-Goals

- **No parallel sender.** All sending stays in `SmtpMailer`; this spec extends it, never duplicates it.
- **Not solving any provider's send limits** (surface + easy migration only, §9).
- **Not bulk-optimized** — list volume stays an ESP job (§9).
- **No custom domain requirement** — a plain `@gmail.com` / `@yahoo.com` account is first-class.
- **No reader UI / threading here** — that's `outbound_reply_forward.md`.

## 3. Building Blocks (reuse)

- **`SmtpMailer` / `SmtpProvider`** — the existing SMTP sender (PHPMailer) and `EmailServiceProvider`,
  already also a `RawMessageRelay`. This is what we extend.
- **`PRESETS`** (`InboundImapAccount::PRESETS`) — per-provider IMAP host/port/encryption + auth style
  (`oauth2`/`password`) + `oauth_provider`. Extend with SMTP coordinates so one row drives both
  directions.
- **OAuth2 Core** (`GoogleOAuthProvider`, `MicrosoftOAuthProvider`) — issues/refreshes tokens;
  `ensureFresh()` yields a live access token. Already used by inbound.
- **PHPMailer** — supports SMTP **XOAUTH2** via a token-provider object (and basic password auth).
- IMAP account credentials (`InboundImapAccount`: `iia_oauth_*`, `iia_password_enc`,
  `iia_provider_key`) — outbound reuses these unchanged.
- `EmailMessage` / `EmailSender` — the structured outbound entry point.

## 4. Design: Extend the SMTP Path (no new sender)

**Two needs, one mechanism.** Connected-account outbound serves two distinct needs, and only one of
them fits the existing provider model:

1. **Connected account as the *system-wide* provider** ("send all site email through my Gmail"). This
   fits the current model exactly: `EmailSender` selects a provider by the single `email_service`
   setting, instantiates it with no arguments, and the provider reads its config from settings. One
   account, chosen once, site-wide. **This is what the exposure layer (c) is about.**
2. **Send *as a specific mailbox*** (reader reply/forward — §7's `resolveOutboundTransport(mailbox)`).
   Here the account varies *per call*, which **does not fit** the provider model: providers are picked
   by a global string setting and built with no arguments, so there is no slot for "send this one
   through account 7." This need is served by calling the §4(b) helper
   (`SmtpMailer::forConnectedAccount($account)`) **directly** from `resolveOutboundTransport`,
   bypassing `EmailSender`'s provider selection. No provider-layer design changes this.

Both needs converge on the **same primitives** below — a configurable `SmtpMailer`, a
`forConnectedAccount()` builder, and a shared `EmailMessage`→mailer mapping — so there is exactly one
send mechanism. Two focused changes, a shared extraction, then a thin exposure layer.

**(a) `SmtpMailer` gains an auth abstraction.** Today it hard-reads global `smtp_*` and does basic
auth. Generalize it to be configured from a **credential source**, with two auth modes:
- `password` — existing behavior (global settings *or* a supplied username/password).
- `xoauth2` — **new**: authenticate with an OAuth access token, wired through PHPMailer's XOAUTH2
  token-provider interface, the token supplied by the OAuth2 Core (`ensureFresh()`).

So `SmtpMailer` can be built either the current way (global settings) or from an explicit config
`{host, port, encryption, authMode, credential}`. This is the only real new primitive, and it
benefits any OAuth SMTP use — not just connected accounts.

**(b) Build that config from a connected account.** A small helper —
`SmtpMailer::forConnectedAccount(InboundImapAccount $a)` — reads host/port/encryption from the
account's `PRESETS` row and the credential from the account (`xoauth2` with its OAuth token for
`oauth2` providers; `password` with its stored app password otherwise). No host/port/password is
re-typed.

**(b′) Extract the `EmailMessage`→mailer mapping (the key anti-duplication move).** Today the mapping
of an `EmailMessage` onto a PHPMailer instance (From, recipients, cc/bcc, reply-to, custom headers,
attachments) lives **inline inside `SmtpProvider::send()`**. Lift it into a shared method —
`SmtpMailer::applyMessage(EmailMessage $m)` (or an equivalent static helper). After this, every send
is *build a mailer + `applyMessage` + send*:
- `SmtpProvider::send()` — build from global settings, `applyMessage`, send.
- `ConnectedMailboxProvider::send()` — `forConnectedAccount()`, `applyMessage`, send.
- `resolveOutboundTransport()` (§7, Need 2) — `forConnectedAccount()`, return the configured mailer;
  the reply/forward caller does `applyMessage` + send.

This extraction is what makes "one send mechanism" literally true and reduces the exposure layer to a
UX choice. Do it first (§13).

**(c) Thin exposure layer — *resolved: a `ConnectedMailboxProvider` class* (Option A).** Expose "send
through a connected account" as its own auto-discovered provider:
- A small `ConnectedMailboxProvider implements EmailServiceProvider`, `getKey() = 'connected_account'`,
  `getLabel() = 'Connected Email Account'`. Its `send()` reads which account from a setting, builds
  `SmtpMailer::forConnectedAccount($chosen)`, runs `applyMessage`, sends — forcing `From` to the
  account address (per the §5 identity model). It delegates; it does not duplicate.
- `getSettingsFields()` returns a dropdown whose options are the connected accounts — built
  dynamically (the method runs PHP and may query `InboundImapAccount`; `SmtpProvider` already returns
  option lists for static fields, so dynamic options are a small extension).
- `validateConfiguration()` checks an account is selected and not in `iia_needs_reauth`.

Chosen over bolting a "credential source" option onto `SmtpProvider` because it surfaces "Connected
Email Account" as a **first-class, discoverable choice** in the existing provider dropdown — matching
the §6 "Connect an email account" onboarding — whereas the alternative hides the feature behind a
sub-option and gives `SmtpProvider` two personalities (hand-typed host vs. account picker). Once (b′)
is done, the class is a few delegating lines, so the discoverability win is nearly free.

The connected-account provider's *only* distinct responsibilities are UX (pick a connected account)
and forcing `From` to that account. Mechanics are single-sourced in `SmtpMailer` via (a), (b), (b′).

### 4.1 OAuth Transport — SMTP + XOAUTH2 (one path)

Outbound for OAuth accounts uses **SMTP with XOAUTH2** (PHPMailer's built-in `setOAuth()` token
provider, token from OAuth2 Core's `ensureFresh()`). This keeps the single send mechanism: the access
token is just another credential the §4(a) auth abstraction supplies, and `forConnectedAccount()`
remains a pure SMTP config helper with **no per-provider transport branch**.

Per provider:
- **Google** — the existing `https://mail.google.com/` IMAP scope already authorizes SMTP send; **no
  re-consent**. Gmail's SMTP auto-files the Sent copy.
- **Password providers** (Yahoo/iCloud/Fastmail/generic) — the same app password sends; only host/port
  differ (`PRESETS`).
- **Microsoft** — needs `SMTP.Send` added to scopes (**re-consent**). M365 tenants may also disable
  SMTP AUTH org-wide; attempt SMTP and, on a tenant-blocked failure, surface a **clear warning**
  ("your Microsoft tenant blocks SMTP sending — use Mailgun/SES, or have your admin enable SMTP
  AUTH"). Max freedom up to the limit, then a guard — consistent with §5/§10.

  **Upgrade trigger (already-connected accounts).** An account connected for IMAP holds only the
  inbound scope. Detect the missing `SMTP.Send` **proactively at the point outbound is enabled** for
  that account (selecting it as the system provider, or first use as a per-mailbox transport) — compare
  granted scopes against what send requires and, if short, present "Reconnect to allow sending" before
  any send is attempted, rather than letting outbound silently fail. The post-send path is the
  fail-safe only: a send rejected for missing scope/auth flags `iia_needs_reauth` via the shared
  Reconnect affordance (§11). Proactive prompt first, fail-and-flag as backstop.

**Why not provider REST APIs** (Gmail `messages.send`, Graph `sendMail`): they auto-file Sent and
Graph dodges SMTP-AUTH-disabled tenants, but they require HTTP clients and a **second send path**,
branching `forConnectedAccount()` by provider — against the minimal-duplication goal. Their main win
(auto-filed Sent) is moot for the reader anyway: the ingestor polls INBOX, Sent copies land in
`[Gmail]/Sent` and never return via poll, so `outbound_reply_forward.md` stores the sent row locally
regardless. **Deferred:** introduce Graph `sendMail` as a Microsoft-only transport *only if* M365
demand justifies it — isolated to that case, leaving Google/password on the one SMTP path.

## 5. One Connection for Both Directions

The connected account (`InboundImapAccount`) already holds the credential for inbound; outbound reads
the same token/password via the §4 helper. For OAuth accounts a single grant is the source of truth;
refresh and the `iia_needs_reauth` health flag are shared — one "Reconnect" fixes inbound and
outbound. Choosing the connected-account option + an account as the active provider sends the whole
site through it.

**Identity model — maximum freedom, explicit limits.** A connected account *is* allowed as the
**system-wide active provider** (the solo-operator case). Because consumer/provider SMTP rewrites the
envelope sender and `From` to the authenticated address, this means every outbound message ships as
that one connected address. We permit this rather than restrict it, and make the limits clear at the
point of choice instead of blocking:

- **At selection**, warn that *all* site email (transactional, notifications, replies, forwarding)
  goes out as the connected address.
- **Hosted aliases** (`alias@our-domain`) cannot send as themselves through a connected account — its
  SMTP forces its own `From`. Sending *as a hosted alias* requires a relay-class provider (SMTP host /
  Mailgun / SES) that permits an arbitrary `From`. `resolveOutboundTransport` (§7) reflects this: the
  hosted row needs a relay-class provider; a connected account can only send as itself.
- **Forwarding** is a *send-as* transport here, not a transparent relay — see §10.
- **No silent restriction.** Anything the credential can technically do is allowed; the UI states the
  trade-off and warns rather than disabling controls.

**Refresh concurrency (shared grant).** Inbound polling and outbound send now share one OAuth grant
and both call `OAuth2Client::ensureFresh()`, which today is a bare check-then-refresh with no locking.
Concurrent callers can both refresh the same grant; for providers that **rotate the refresh token on
use** (Microsoft does; Google generally does not) the second refresh then redeems an invalidated
refresh token and fails, flagging the feed `needs_reauth` spuriously. Serialize refresh with the
standard **double-checked-locking** pattern, scoped per grant: take a per-grant lock (a Postgres
`pg_advisory_xact_lock` keyed on the account/grant id fits — the platform is Postgres), **re-read the
persisted token** inside the lock, and refresh only if it is still expired; otherwise use the token
the other caller just persisted. This belongs in `ensureFresh()` (or a thin wrapper) so both
directions inherit it; it is not connected-account-specific.

## 6. Unified Onboarding

A "**Connect an email account**" action (Setup tab and/or email Settings) runs the existing
provider-type picker (Gmail/Microsoft → OAuth consent; Yahoo/iCloud/Fastmail/generic → app password)
and optionally sets that account as the active outbound provider with `From` pointed at it. One flow,
any provider, both directions. Switching to Mailgun/SES later is one setting.

## 7. Per-Mailbox Send Transport (consumed by reply/forward)

When sending **as a specific mailbox** (a reader reply or forward), identity must be that mailbox's
address and the transport must be authorized for it. `resolveOutboundTransport(mailbox)` returns a
**configured `SmtpMailer` (or the platform provider)** — the same send path as §4, just configured
per mailbox:

| Mailbox type | From identity | Transport (built from) | Sent copy lands in source? |
|---|---|---|---|
| **Hosted** (`alias@our-domain`) | the alias | the platform provider / SMTP relay (existing), our domain's DKIM + SRS | n/a |
| **IMAP-source, OAuth** (Gmail / M365) | the feed address | `SmtpMailer::forConnectedAccount(feed)` → XOAUTH2 | **Yes** — provider auto-files Sent |
| **IMAP-source, password** (Yahoo/iCloud/Fastmail/generic) | the feed address | `SmtpMailer::forConnectedAccount(feed)` → password | Usually yes |

So the system provider (§4) and the per-mailbox transport are two entry points into the **one**
`SmtpMailer` configuration helper — no second send implementation.

**Provider auth notes (data, per `PRESETS`):**
- **Google:** `https://mail.google.com/` already authorizes SMTP send via XOAUTH2 — no re-consent.
- **Microsoft:** `IMAP.AccessAsUser.All` lacks send — outbound needs `SMTP.Send` added to scopes
  (re-consent for M365). Also: M365 tenants may disable SMTP AUTH org-wide; surface as a connect-time
  check.
- **Password providers:** the same app password covers SMTP; only host/port differ (`PRESETS`).
- **Generic:** the user supplies SMTP host/port/encryption (as for IMAP).

## 8. Deliverability Notes (informational)

Sends through the provider's own SMTP are signed/aligned by that provider (SPF/DKIM/DMARC) with no
DNS work. Mail goes out as the connected address — the accepted trade-off.

## 9. Limits & Graceful Migration

- **Surface the ceiling.** On a provider rate-limit/quota response, record it on the send (reuse the
  existing queue/retry path) and raise a visible status: "<provider> is rate-limiting send — consider
  a dedicated provider." Surface the symptom; don't hard-code per-provider numbers.
- **One-step migration.** Outbound is provider-pluggable, so moving to Mailgun/SES is connecting it
  and changing the active-provider setting — no message-path changes.
- **Bulk stays an ESP job.** The active provider is system-wide; v1 allows bulk through a connected
  account (accepted trade-off) but **warns**. Stream separation is a later refinement.

## 10. Raw-Relay Caveat

Consumer/provider SMTPs rewrite the envelope sender and `From` to the authenticated account, so a
connected-account transport is **not** a transparent `RawMessageRelay` (unlike a plain relay host).
Composed/transactional mail and reader replies (`EmailMessage` send) work normally; automatic inbound
*forwarding* through a connected account sends "as" that account rather than relaying the original
sender intact.

**Guard (max freedom, clear limit).** A connected account does not implement a transparent
`RawMessageRelay`, and the rewrite is a provider limitation we cannot fix at our layer. So:

- Forwarding through a connected account is **allowed**, not blocked. The forwarded message goes out
  as the connected address, with the original sender preserved in `Reply-To` (and an
  `X-Original-From` header) so replies still reach the real sender.
- At the point forwarding is configured while the active/transport provider is a connected account,
  **warn** that the original envelope sender is not preserved and that transparent relay (SPF/DKIM
  aligned to the original sender, SRS) requires a relay-class provider (SMTP host / Mailgun / SES).
- If a relay-class provider is also configured, forwarding **prefers** it automatically; the
  connected-account send-as path is the fallback.

## 11. Data Model / Settings, Failure Handling, Testing

- **`SmtpMailer`:** add the auth abstraction (config object + `xoauth2` mode) and
  `forConnectedAccount()`; existing global-settings construction unchanged (back-compatible).
- **`PRESETS`:** add `smtp_host` / `smtp_port` / `smtp_encryption` per entry (Gmail
  `smtp.gmail.com:587`, M365 `smtp.office365.com:587`, Yahoo `:465`, iCloud `smtp.mail.me.com:587`,
  Fastmail `:465`, generic = user-entered).
- **Settings:** active-provider gains the connected-account option + which account; config stores the
  chosen `InboundImapAccount` reference (credentials already on the account). No schema beyond that.
- **Failure handling:** OAuth refresh via `ensureFresh()`; auth failure → `iia_needs_reauth` +
  "Reconnect" (shared); quota failure → existing queue/retry + the §9 status.
- **Testing:** `SmtpMailer` XOAUTH2 mode (mock token) and password mode both send; `forConnectedAccount`
  builds correct config per `PRESETS`/auth style; `From` forced to the connected identity;
  `resolveOutboundTransport` returns the right configured mailer per mailbox type; reconnect clears
  `iia_needs_reauth` for both directions; switching active provider to Mailgun routes cleanly;
  existing global-settings SMTP send still works (regression).

## 12. Open Decisions

- **Exposure layer:** *(resolved — Option A, see §4(c))* a thin `ConnectedMailboxProvider` delegating
  to `SmtpMailer`, chosen for first-class discoverability in the provider dropdown over a "credential
  source" option on `SmtpProvider`. Both reuse `SmtpMailer`; the deciding factor was UX, not
  mechanics, because the §4(b′) mapping extraction single-sources the send either way.
- **OAuth transport:** *(resolved — SMTP + XOAUTH2, see §4.1)* one transport via PHPMailer's built-in
  XOAUTH2; `forConnectedAccount()` stays a pure SMTP config helper with no per-provider branch.
  Provider REST APIs (Gmail `messages.send`, Graph `sendMail`) are a **deferred, Microsoft-only
  fallback** (§4.1) — not built in v1.
- **Microsoft:** *(resolved)* add `SMTP.Send` (re-consent), attempt SMTP, and detect SMTP-AUTH-disabled
  tenants with a clear warning (§4.1 / §7). Graph `sendMail` is the deferred escape hatch only if
  Microsoft demand justifies the second path.
- **Account scope:** *(resolved — max freedom)* any connected account is selectable as the system
  provider. Choosing the provider is itself an admin-gated action, so the limit is the existing admin
  permission, not an account-ownership restriction. The selection UI carries the single-identity and
  forwarding warnings (§5, §10).

## 13. Delivery Order (not a timeline)

1. **Extract `SmtpMailer::applyMessage()`** (§4 b′) from `SmtpProvider::send()` — the anti-duplication
   primitive everything else reuses; pure refactor, no behavior change (regression: existing SMTP send).
2. **`SmtpMailer` XOAUTH2 auth mode** + config-object construction (the core primitive; back-compatible).
3. **`SmtpMailer::forConnectedAccount()`** + `PRESETS` SMTP coordinates.
4. **`ConnectedMailboxProvider`** (§4 c) + the "Connect an email account" onboarding (§6).
5. **`resolveOutboundTransport`** (§7) — unblocks `outbound_reply_forward.md`.
6. **Limit detection + migration nudge + bulk warning** (§9).

## 14. Docs to Update at Implementation

`docs/email_system.md` — document the SMTP path's XOAUTH2 auth and connected-account credential
sourcing, the connected-account provider option, the per-mailbox transport resolver, forced `From`,
and the limits/migration guidance. Cross-reference from `plugins/inbound_email/docs/overview.md` that
one account connection serves both inbound (IMAP feed) and outbound (the same `SmtpMailer`). Written
as the current state, per the docs rule.
