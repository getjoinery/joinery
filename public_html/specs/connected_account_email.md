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

## 4. Design: Transport Injection on the One SMTP Path (no new sender)

**Two needs, one pipeline.** Connected-account outbound serves two needs. Today only the first can
flow through `EmailSender`, because a provider is selected by the global `email_service` string and
built with `new $class()` (no arguments) — there is no slot for "send through *this* account":

1. **Connected account as the *system-wide* provider** ("send all site email through my Gmail").
   Chosen once via the active-provider setting; surfaced by the thin exposure layer (d).
2. **Send *as a specific mailbox*** (reader reply/forward — §7's `resolveOutboundTransport(mailbox)`),
   where the account varies *per call*.

The clean way to serve both with one code path is to **separate transport from transport selection**:
make a *configured transport* a first-class value that `EmailSender::send()` can be handed, instead of
always re-deriving it from global settings. Then the system provider, the connected account, and the
per-mailbox case are three ways to *obtain* a transport — all sent through the **same** `EmailSender`
pipeline (validation, retry-queue, debug logging). **No send path bypasses `EmailSender`.**

**(a) An explicit `SmtpConfig` + a single `SmtpMailer` construction model.** Today `SmtpMailer`
hard-reads global `smtp_*` in its constructor and does basic auth only. Introduce a plain
**`SmtpConfig` value object** `{host, port, encryption, authMode, credential}` with two auth modes:
- `password` — username/password (the existing behavior).
- `xoauth2` — **new**: authenticate with an OAuth access token via PHPMailer's XOAUTH2 token-provider
  interface, the token supplied by OAuth2 Core (`ensureFresh()`).

`SmtpMailer` is configured *from* an `SmtpConfig` — one construction model, not two. Back-compatible:
`SmtpConfig::fromSettings()` reproduces today's global-settings behavior, so the existing no-arg
`new SmtpMailer()` (used by `SmtpProvider::send`, `relayRawMessage`, `validateApiConnection`) keeps
working unchanged. Likewise `SmtpProvider` takes an **optional `SmtpConfig`** (default
`SmtpConfig::fromSettings()`), so the *one* provider class is the SMTP transport whether configured
globally or per-account. XOAUTH2 is the only genuinely new primitive, and it benefits any OAuth SMTP
use — not just connected accounts.

**(b) `SmtpConfig::fromConnectedAccount(InboundImapAccount $a)`.** Reads host/port/encryption from the
account's `PRESETS` SMTP coordinates and the credential from the account (`xoauth2` for `oauth2`
providers; `password` with its stored app password otherwise). No host/port/password is re-typed. This
is the *only* connected-account-specific mechanic; everything downstream is shared.

**(b′) Extract the `EmailMessage`→PHPMailer mapping (the anti-duplication move, do it first).** The
mapping (From, recipients, cc/bcc, reply-to, custom headers, attachments) lives **inline inside
`SmtpProvider::send()`** today. Lift it into `SmtpMailer::applyMessage(EmailMessage $m)`. After this
every SMTP send is *configure a mailer from `SmtpConfig` + `applyMessage` + send* — one mapping, used
by the global path, the connected-account path, and the per-mailbox path alike.

**(c) `EmailSender::send()` accepts an optional transport — the single pipeline.** Change the signature
to `send(EmailMessage $m, $queue_on_failure = true, ?EmailServiceProvider $transport = null)`:
- `$transport === null` (the default) — unchanged: select by the `email_service` setting, with the
  `email_fallback_service` fallback.
- `$transport` provided — send through it directly. **Skip the fallback** (you cannot fall back a
  "send *as* this mailbox" to a *different* identity), but **keep** validation, the try/catch, the
  retry-queue (`queueForRetry`), and debug logging.

This is the crux of the refactor: per-mailbox sends inherit every cross-cutting concern instead of
re-implementing them or silently losing them. `resolveOutboundTransport(mailbox)` (§7) returns a
configured transport, and reply/forward calls `$sender->send($msg, true, $transport)` — *through* the
pipeline, never around it.

**(d) Thin exposure layer — `ConnectedMailboxProvider` (Option A), now pure UX.** Expose "send through
a connected account" as its own auto-discovered provider so it is a **first-class, discoverable choice**
in the existing provider dropdown (matching the §6 onboarding):
- `ConnectedMailboxProvider implements EmailServiceProvider`, `getKey() = 'connected_account'`,
  `getLabel() = 'Connected Email Account'`. Its `send()` reads which account from a setting and sends
  via a `SmtpProvider` configured with `SmtpConfig::fromConnectedAccount($chosen)`, forcing `From` to
  the account address (§5 identity model). With (a)–(c) in place this is a few delegating lines — no
  mechanics of its own.
- `getSettingsFields()` returns a dropdown whose options are the connected accounts, built dynamically
  (the method runs PHP and may query `InboundImapAccount`).
- `validateConfiguration()` checks an account is selected and not in `iia_needs_reauth`.

Chosen over bolting a "credential source" sub-option onto `SmtpProvider` for that dropdown
discoverability; with the mapping single-sourced in (b′) it adds no duplicate mechanics either way.

**Architecture (before → after).**

```
BEFORE
  EmailSender::send($msg)
     │  reads email_service (global string) → new $class()      one config source: globals
     ▼
  EmailServiceProvider   (SmtpProvider | MailgunProvider | …)
     │  SmtpProvider::send(): new SmtpMailer() + INLINE EmailMessage→PHPMailer mapping
     ▼
  SmtpMailer extends PHPMailer    ← constructor reads global smtp_* (password auth only)

  per-mailbox reply/forward ──── no path through EmailSender ────✗  (would bypass it)

AFTER
  EmailSender::send($msg, $queue, ?$transport)        ┐ ONE pipeline:
     │  $transport == null → select by email_service (+ fallback)  │ validate · retry-queue · debug log
     │  $transport given   → use it (no fallback)      ┘
     ▼
  EmailServiceProvider  (the transport)
     ├─ SmtpProvider(?SmtpConfig)         ← injected config, else SmtpConfig::fromSettings()
     │     └─ applyMessage()  (shared EmailMessage→PHPMailer mapping)
     ├─ ConnectedMailboxProvider          ← UX only; delegates to SmtpProvider w/ fromConnectedAccount()
     └─ MailgunProvider | SesProvider | …

  SmtpConfig {host, port, encryption, authMode, credential}
     ├─ fromSettings()             (global smtp_*)
     └─ fromConnectedAccount($a)   (PRESETS + iia_oauth_* / iia_password_enc; password | xoauth2)
     ▼
  SmtpMailer extends PHPMailer    ← ONE constructor, configured from SmtpConfig (password | XOAUTH2)

  resolveOutboundTransport(mailbox) → EmailServiceProvider ─┐
  reader reply/forward: send($msg, true, $transport) ───────┘ through the SAME pipeline
```

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
branching `SmtpConfig::fromConnectedAccount()` by provider — against the minimal-duplication goal. Their
auto-file-Sent win is already covered without them: providers whose SMTP auto-saves Sent
(`smtp_files_sent`, §7/§11) need nothing extra, and where SMTP does not, two-way sync `APPEND`s the
copy (`two_way_imap_sync.md` §9) — so Sent is correct on the one SMTP path regardless. **Deferred:**
introduce Graph `sendMail` as a Microsoft-only transport *only if* M365 demand justifies it — isolated
to that case, leaving Google/password on the one SMTP path.

### 4.2 Two Send Modes, One Plumbing (relay consolidation)

The transport-injection work above cleans up the **structured-send** path (mode 1). The same
`SmtpConfig` primitive then collapses the *raw-relay* paths, so the platform ends with exactly **two
send modes over one set of plumbing** — the natural end state, flagged here as a distinct delivery step
(§13) because it reaches into `InboundEmailRouter`.

Today there are **four** send entry points, two of which duplicate each other:

1. `EmailSender::send(EmailMessage)` — structured/composed send (mode 1 above).
2. `provider->relayRawMessage()` — raw-MIME relay with explicit envelope sender, for inbound forwarding.
3. `InboundEmailRouter::relayViaSmtp()` + `createMailer()` — the SMTP **fallback** for forwarding (and
   the path when a dedicated `inbound_email_forwarding_smtp_*` relay is set). Its SMTP transaction is a
   near-line-for-line **duplicate** of (2); the only real difference is `createMailer()` hand-patching
   Host/Port/auth from the forwarding settings.
4. `Activation.php`'s stray `new systemmailer()` — a direct PHPMailer send bypassing `EmailSender`.

`SmtpConfig` resolves the redundancy:

- **`createMailer()` → `SmtpConfig::fromForwardingSettings()`** — a third `SmtpConfig` factory
  (`inbound_email_forwarding_smtp_*`, falling back to base `smtp_*`). The router's manual override block
  disappears.
- **`relayViaSmtp()` → deleted.** The forwarding SMTP fallback becomes
  `SmtpProvider(SmtpConfig::fromForwardingSettings())->relayRawMessage(...)`. The duplicate transaction
  collapses into the single copy in `SmtpProvider::relayRawMessage()` (which now reads its injected
  `SmtpConfig` instead of `new SmtpMailer()` globals — §4a). `InboundEmailRouter::relay()`'s
  primary→fallback orchestration is unchanged; only the fallback's *implementation* swaps to the
  configured provider.
- **`Activation.php` → `EmailSender`** (`quickSend`/`sendTemplate`), removing the stray direct mailer.

End state — **two modes, shared plumbing:**

```
  STRUCTURED SEND   EmailSender::send(EmailMessage, ?transport)
                      └ provider + applyMessage()            build MIME, stamp our identity
  RAW RELAY         provider->relayRawMessage(raw, envelope, dests)
                      └ preserve envelope (SRS/SPF/DKIM), forward exact bytes

  both modes share:   SmtpConfig { fromSettings | fromConnectedAccount | fromForwardingSettings }
                      one SmtpMailer  (single constructor)
                      the discovered provider set (Smtp | Mailgun | Ses | ConnectedMailbox | …)
```

**The floor is two, not one.** Structured send builds a MIME body and lets the provider set `From` to
the authenticated identity; raw relay ships pre-formed bytes with an explicit `MAIL FROM` so
SPF/DKIM/SRS stay aligned to the *original* sender. Forwarding needs the envelope preserved, so the two
modes cannot merge without losing that. Consolidation stops here deliberately.

**Scope flag.** This step widens the blast radius from the email layer into `plugins/inbound_email`
(`InboundEmailRouter::relayViaSmtp`/`createMailer`, the forwarding-relay tests) and touches
`Activation.php`. It is cleanly separable from the connected-account feature and ships last (§13).

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
**configured transport** — an `EmailServiceProvider` (`SmtpProvider` built from an `SmtpConfig`, or the
platform provider unchanged). The caller sends it through the one pipeline:
`$sender->send($msg, true, $transport)` (§4c) — same path as the system provider, just a different
transport:

| Mailbox type | From identity | Transport (built from) | Sent copy in source (`smtp_files_sent`)? |
|---|---|---|---|
| **Hosted** (`alias@our-domain`) | the alias | the platform provider / SMTP relay (existing), our domain's DKIM + SRS | n/a — no source mailbox |
| **IMAP-source, OAuth** (Gmail / M365) | the feed address | `SmtpProvider(SmtpConfig::fromConnectedAccount(feed))` → XOAUTH2 | **Yes** — provider auto-files |
| **IMAP-source, password** (Yahoo/iCloud/Fastmail) | the feed address | `SmtpProvider(SmtpConfig::fromConnectedAccount(feed))` → password | Yes — provider auto-files |
| **IMAP-source, generic** (self-hosted Postfix+Dovecot) | the feed address | `SmtpProvider(SmtpConfig::fromConnectedAccount(feed))` → password | **No** — SMTP submission does not save Sent |

So the system provider (§4) and the per-mailbox transport are two ways to **obtain a transport** for
the one `EmailSender` pipeline — no second send implementation, no bypass.

**Sent-copy responsibility.** `smtp_files_sent` (a `PRESETS` capability, §11) records whether a
provider's SMTP saves the sent copy itself. When true, nothing more is needed. When false, two-way sync
`APPEND`s the copy to the Sent folder (`two_way_imap_sync.md` §9) — filed exactly once, never both.
`resolveOutboundTransport` surfaces this flag so the sync layer knows whether to APPEND.

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

- **`SmtpConfig` (new value object):** `{host, port, encryption, authMode, credential}` with
  `fromSettings()`, `fromConnectedAccount()`, and `fromForwardingSettings()` factories.
- **`SmtpMailer`:** configured from an `SmtpConfig` (one construction model) with `password` and
  `xoauth2` auth modes; the no-arg path defaults to `SmtpConfig::fromSettings()` (back-compatible).
- **`SmtpProvider`:** optional `SmtpConfig` constructor arg (default `fromSettings()`) + the extracted
  `applyMessage()`; both `send()` and `relayRawMessage()` use the injected config. Same class serves
  global, per-account, and forwarding SMTP transport.
- **`EmailSender::send()`:** optional `?EmailServiceProvider $transport` param — when supplied, send
  through it (no fallback) while keeping validation, retry-queue, and debug logging.
- **Relay consolidation (§4.2):** `InboundEmailRouter::relayViaSmtp()` deleted; `createMailer()` reduced
  to `SmtpConfig::fromForwardingSettings()`; the forwarding SMTP fallback routes through
  `SmtpProvider::relayRawMessage()`. `Activation.php`'s direct `systemmailer` send moves to
  `EmailSender`.
- **`PRESETS`:** add `smtp_host` / `smtp_port` / `smtp_encryption` per entry (Gmail
  `smtp.gmail.com:587`, M365 `smtp.office365.com:587`, Yahoo `:465`, iCloud `smtp.mail.me.com:587`,
  Fastmail `:465`, generic = user-entered). Add `smtp_files_sent` (bool) per entry — whether the
  provider's SMTP auto-saves the sent copy to Sent (`true` for Gmail/M365/Yahoo/iCloud/Fastmail;
  `false` for generic self-hosted). Consumed by two-way sync (`two_way_imap_sync.md` §9) to decide
  whether to `APPEND` the copy.
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

- **Exposure layer:** *(resolved — Option A, see §4(d))* a thin `ConnectedMailboxProvider` delegating
  to a `SmtpProvider` configured via `SmtpConfig::fromConnectedAccount()`, chosen for first-class
  discoverability in the provider dropdown over a "credential source" option on `SmtpProvider`. The
  deciding factor was UX, not mechanics, because the §4(b′) mapping extraction single-sources the send.
- **Transport injection vs. provider bypass:** *(resolved — see §4(c))* per-mailbox sends flow through
  `EmailSender::send()` via an optional injected transport, **not** by calling the SMTP layer directly.
  Chosen so per-mailbox sends inherit validation, retry-queue, and debug logging; the only behavior
  intentionally skipped for an injected transport is provider fallback (wrong for send-as-identity).
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
2. **`SmtpConfig` value object + single `SmtpMailer` construction model** (`fromSettings()` back-compat)
   **+ XOAUTH2 auth mode** (the core primitives; back-compatible).
3. **`SmtpConfig::fromConnectedAccount()` + `PRESETS` SMTP coordinates + `SmtpProvider` optional
   `SmtpConfig` param** (so the one provider class is the SMTP transport either way).
4. **`EmailSender::send()` optional transport parameter** (§4 c) — the single pipeline for injected
   transports; regression: existing global-selection + fallback unchanged.
5. **`ConnectedMailboxProvider`** (§4 d) + the "Connect an email account" onboarding (§6).
6. **`resolveOutboundTransport`** (§7) — returns an injected transport; unblocks `outbound_reply_forward.md`.
7. **Limit detection + migration nudge + bulk warning** (§9).
8. **Relay consolidation to the two-mode end state** (§4.2) — `SmtpConfig::fromForwardingSettings()`;
   route the forwarding SMTP fallback through `SmtpProvider::relayRawMessage()`; delete
   `InboundEmailRouter::relayViaSmtp()`; move `Activation.php` to `EmailSender`. **Wider blast radius
   (`plugins/inbound_email` + forwarding-relay tests); separable, ships last.** Regression: inbound
   forwarding via provider relay *and* via the SMTP fallback both still deliver.

## 14. Docs to Update at Implementation

`docs/email_system.md` — document the **two send modes** (structured send vs. raw relay) and their
shared plumbing: the SMTP path's XOAUTH2 auth and connected-account credential sourcing, `SmtpConfig`
and its three factories (`fromSettings`/`fromConnectedAccount`/`fromForwardingSettings`),
`EmailSender::send()`'s optional injected transport (one pipeline, fallback skipped for injected
transports), the connected-account provider option, the per-mailbox transport resolver, forced `From`,
and the limits/migration guidance. Note that inbound forwarding's SMTP fallback now routes through
`SmtpProvider::relayRawMessage()` (no separate `relayViaSmtp` path). Cross-reference from `plugins/inbound_email/docs/overview.md` that
one account connection serves both inbound (IMAP feed) and outbound (the same `SmtpMailer`). Written
as the current state, per the docs rule.
