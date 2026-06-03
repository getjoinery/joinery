# Inbound IMAP Provider (poll a mailbox) + Google/Microsoft OAuth add-ons

## Overview

Add a **generic IMAP inbound provider** so the platform can receive mail by
**polling an existing mailbox** (Gmail, Microsoft 365, Yahoo, iCloud, Fastmail,
any IMAP host) instead of only via self-hosted Postfix (MX → pipe) or a webhook
provider (Mailgun). Combined with the existing **generic SMTP** outbound
provider, this gives a complete **"bring your own mailbox"** path: SMTP out +
IMAP in, both pointed at the same account — no self-hosted MX, no webhook
service. The target user is low-volume: someone who already has a mailbox and
wants the platform to read it.

Fetched messages land in the same store the rest of inbound email uses
(`iem_inbound_email_messages`) and show up in the **Mailbox Reader** with no
changes to the reader — it already reads `iem_`.

Two auth realities drive the scope:
- **Yahoo / iCloud / Fastmail / generic IMAP** work with **basic auth + an app
  password** — covered by the base provider with per-host connection presets.
- **Gmail / Google Workspace** and **Microsoft 365 / Outlook.com** have
  **disabled or deprecated basic auth** and require **OAuth2 (XOAUTH2)**.

The OAuth2 part is **not built here.** The authorization-code flow (consent,
`code`→token exchange, refresh, and a single-use session-backed callback) and the
Google/Microsoft provider definitions are a platform abstraction **implemented in
[OAuth2 Core](implemented/oauth2_core.md)** (now built) — IMAP is its **first
consumer**. This spec builds the IMAP transport and plugs into that core: it
requests the mail scope, stores the returned tokens on the IMAP account, and uses
them for XOAUTH2. The general-purpose [`SecretBox`](secret_box.md) helper for
encrypting credentials at rest (a standalone core helper, not OAuth-specific) is
reused here for IMAP passwords.

## Motivation / the gap

The inbound provider interface (`InboundEmailProvider`) is **push-only**:
`handleInbound($post, $raw_body)` + `isWebhook()`. Both existing transports push
— Mailgun POSTs a webhook, Postfix pipes on delivery. **IMAP is pull**: nobody
notifies you; you connect and poll on a schedule. So IMAP is a *third transport
shape* the current model doesn't express. Everything needed to add it already
exists: a scheduled-task system, the store path
(`InboundEmailRouter::storeMessage`) with dedup via `UNIQUE
(iem_message_id_header, iem_recipient)`, and the `php-imap` extension.

This is also a platform-level abstraction, not a product feature: a generic IMAP
ingestor with pluggable auth, where Gmail and Microsoft are two OAuth strategies
among several hosts — not a "Gmail integration."

## Core model

Three decisions define it:

1. **IMAP accounts are additive, not a system transport switch.** The existing
   `inbound_email_provider` setting selects the *one* system transport
   (postfix/mailgun) that owns the MX/webhook. IMAP accounts are **independent,
   multiple, per-mailbox pollers** that run *alongside* whatever that primary
   transport is. Adding an IMAP account never changes the system transport.

2. **An IMAP account maps to a mailbox (alias).** Each account binds to an
   inbound **alias** — the mailbox it populates. Fetched messages are stored with
   that alias's id, so they appear as a normal mailbox in the reader, honor the
   grant model, and need no reader changes. **No MX/DNS is required** for an
   IMAP-sourced alias — the mail is already in the remote mailbox; that is the
   whole point. (The alias still belongs to a domain record for identity, but
   that domain needs no MX. See "Mailbox mapping" for the one schema nuance.)

3. **Auth is a pluggable strategy.** The connection (host/port/encryption/folder)
   is generic; the *authentication* is either a stored password/app-password or
   an OAuth2 bearer token obtained from the [OAuth2 Core](implemented/oauth2_core.md). Gmail
   and Microsoft use the OAuth strategy; everyone else uses a password.

```
  Scheduled task: PollImapAccounts (every few minutes)
        │  for each enabled account whose interval elapsed
        ▼
  ImapIngestor(account)
   ├─ auth: PasswordImapAuth | OAuth2ImapAuth
   │        └─ OAuth2ImapAuth asks OAuth2Client::ensureFresh(provider, token) → bearer
   ├─ connect (php-imap / library), select folder, UID search > last_seen_uid
   ├─ fetch raw messages
   ├─ InboundEmailRouter::storeMessage(...)  → iem_ (dedup via UNIQUE)
   └─ advance last_seen_uid / uidvalidity, record status
        │
        ▼
  iem_inbound_email_messages  → Mailbox Reader (unchanged)
```

## The polled-transport extension

Keep `InboundEmailProvider` (push) untouched. Add a sibling interface so the
polling subsystem is discoverable through the same registry:

```php
interface InboundEmailPollingProvider {
    public static function getKey(): string;            // 'imap_generic','imap_gmail','imap_microsoft'
    public static function getLabel(): string;          // 'Generic IMAP','Gmail / Google Workspace',...
    public static function getConnectionPreset(): array; // host/port/encryption defaults ([] for generic)
    public static function getAuthMethod(): string;     // 'password' | 'oauth2'
    public static function getAccountSettingsFields(): array; // config fields for an account of this kind
    public static function authStrategy(InboundImapAccount $acct): ImapAuthStrategy; // password or oauth2
    public static function testConnection(InboundImapAccount $acct): array; // [ok,bool; message]
}
```

`InboundProviderRegistry` is extended to discover polling providers too (same
discovery mechanism, separate list). Push providers (Postfix/Mailgun) are
unaffected.

Concrete providers:
- `GenericImapProvider` — `password` auth, no preset (user supplies host).
- `GmailImapProvider` — preset `imap.gmail.com:993/ssl`, `oauth2` (Google).
- `MicrosoftImapProvider` — preset `outlook.office365.com:993/ssl`, `oauth2`
  (Microsoft).
- Yahoo / iCloud / Fastmail need **no new class** — they are `GenericImapProvider`
  with a connection **preset** (host/port) and an "app password" hint. Ship these
  presets as data so the UI pre-fills the host when the user picks them.

## Data model

### 1. IMAP account — `data/inbound_imap_account_class.php` (new)

`InboundImapAccount` (`SystemBase`) + Multi. Prefix `iia`, table
`iia_inbound_imap_accounts`.

| Field | Type | Notes |
|-------|------|-------|
| `iia_inbound_imap_account_id` | int8 serial PK | |
| `iia_label` | varchar(255) | human label |
| `iia_provider_key` | varchar(40) | `imap_generic`/`imap_gmail`/`imap_microsoft` |
| `iia_iea_inbound_email_alias_id` | int4 | the mailbox this populates; FK cascade |
| `iia_imap_host` | varchar(255) | |
| `iia_imap_port` | int4 | default 993 |
| `iia_imap_encryption` | varchar(10) | `ssl`/`tls`/`none` |
| `iia_imap_folder` | varchar(255) | default `INBOX` |
| `iia_username` | varchar(255) | the mailbox login |
| `iia_auth_method` | varchar(10) | `password`/`oauth2` |
| `iia_password_enc` | text | **encrypted** app/basic password (null for oauth) |
| `iia_oauth_access_token_enc` | text | **encrypted** (null for password) |
| `iia_oauth_refresh_token_enc` | text | **encrypted** |
| `iia_oauth_token_expires` | timestamp(6) | access-token expiry |
| `iia_poll_interval_seconds` | int4 | default 300 |
| `iia_uidvalidity` | int8 | folder UIDVALIDITY guard |
| `iia_last_seen_uid` | int8 | incremental cursor |
| `iia_is_enabled` | bool | default true |
| `iia_last_poll_time` | timestamp(6) | |
| `iia_last_status` | varchar(500) | last poll result/error |
| `iia_create_time`/`iia_update_time`/`iia_delete_time` | timestamp(6) | |

`getMultiResults()` filters: `enabled`, `provider_key`, `due` (last_poll_time +
interval ≤ now), `alias_id`.

**Secrets:** `*_enc` columns are encrypted at rest (see Security). The model
exposes `getPassword()`/`setPassword()` / token accessors that
encrypt/decrypt; raw `*_enc` is never read directly by callers and never
logged or echoed.

### 2. OAuth app config — owned by the OAuth2 Core

Google and Microsoft each need an OAuth **application** (client id/secret +
redirect URI), shared across *all* OAuth consumers — not just IMAP. These live in
the [OAuth2 Core](implemented/oauth2_core.md) as the core settings `oauth_google_client_id`/
`oauth_google_client_secret`, `oauth_microsoft_client_id`/`_secret`/`_tenant`,
entered once at `/admin/admin_oauth_providers`. **This spec declares no OAuth app
settings** — it reuses whatever the OAuth core has configured. One app per
provider, many accounts (and other consumers) consent into it; one shared
redirect URI (`/oauth_callback`).

### Mailbox mapping nuance

An alias requires `iea_ied_inbound_email_domain_id`. An IMAP-sourced mailbox
needs an alias but **not** an MX-backed domain. Resolution (decide at build):
register the alias under any existing domain record (MX optional — the inbound
Setup checks already treat store-only domains without requiring a relay), or add
an `ied_is_imap_source` marker so Setup checks skip MX/DNS for it. Recommended:
the latter — a domain flag that says "this domain's mail arrives by IMAP poll,
not MX," so the Setup tab doesn't red-flag a missing MX it doesn't need.

## The poller — `tasks/PollImapAccounts.{json,php}` (new)

A scheduled task (mirrors `PurgeOldMailboxMessages`): `.json` (name, description,
`default_frequency`, `config_fields` for a global on/off + max-per-run) + a
`.php` implementing `ScheduledTaskInterface::run($config)`.

Per run:
1. Load enabled accounts that are **due** (`last_poll_time + interval ≤ now`).
   (Self-throttle per account so the task can run frequently without hammering
   every mailbox — the task frequency is the *floor*, the account interval is the
   *actual* cadence.)
2. For each account:
   a. Resolve `authStrategy`. If OAuth and the access token is expired/near
      expiry → **refresh** via the provider's token endpoint (store the new
      token). If refresh fails → record status, skip (don't crash the run).
   b. Connect (host/port/encryption), select folder. Compare server
      **UIDVALIDITY** to stored; if changed, reset `last_seen_uid` to 0 (folder
      was reset) and log it.
   c. `UID SEARCH` for messages with UID > `last_seen_uid` (cap to max-per-run).
   d. For each: fetch the **raw RFC822** message, hand it to the store path
      (`InboundEmailRouter::storeMessage` / a store-only entry) with the
      account's bound alias + recipient. Dedup is the existing UNIQUE constraint,
      so re-fetching a message is harmless.
   e. Advance `last_seen_uid`; set `last_poll_time`, `last_status`.
3. Return a summary (accounts polled, messages stored, errors).

Failures are **per-account and non-fatal** — one unreachable mailbox or expired
token must not stop the others (same posture as the router's per-recipient
handling).

## Auth strategy

`ImapAuthStrategy` interface: `applyTo($imapConnection)` / returns the
credential the IMAP connection needs.

- `PasswordImapAuth` — basic `LOGIN` with username + stored app/basic password
  (decrypted via the core `SecretBox`).
- `OAuth2ImapAuth` — `AUTHENTICATE XOAUTH2` with a valid bearer access token. It
  does **not** implement OAuth: it reads the account's stored `OAuth2Token`, calls
  `OAuth2Client::ensureFresh($provider, $token)` from the [OAuth2 Core](implemented/oauth2_core.md)
  (which refreshes and hands back a valid access token), persists the token if it
  changed, and formats the XOAUTH2 SASL string. The XOAUTH2 *formatting* and IMAP
  *use* are the only OAuth-adjacent code this plugin owns.

### Provider matrix (what each major host needs)

| Provider | IMAP host | Auth | Add-on needed |
|----------|-----------|------|---------------|
| Generic IMAP | user-supplied | password | base provider |
| **Gmail / Google Workspace** | `imap.gmail.com:993` | **OAuth2** (App Passwords being retired) | **Google OAuth** |
| **Microsoft 365 / Outlook.com** | `outlook.office365.com:993` | **OAuth2** (basic auth disabled) | **Microsoft OAuth** |
| Yahoo / AOL | `imap.mail.yahoo.com:993` | app password | preset only |
| iCloud | `imap.mail.me.com:993` | app-specific password | preset only |
| Fastmail | `imap.fastmail.com:993` | app password | preset only |

So the only **code** add-ons are **Google** and **Microsoft** (OAuth2). Yahoo,
iCloud, and Fastmail are connection **presets** on the generic provider plus an
"use an app password" hint — no new classes.

### OAuth handling — consume the OAuth2 Core

This plugin owns **no** authorization-endpoint, token-exchange, refresh, or
callback code. The provider definitions (`GoogleOAuthProvider`,
`MicrosoftOAuthProvider`), the grant engine (`OAuth2Client`), the session-backed
single-use callback, and `SecretBox` all live in the
[OAuth2 Core](implemented/oauth2_core.md). IMAP plugs in as a **consumer**:

- **Consumer registration** — `InboundImapOAuthConsumer implements OAuth2Consumer`
  (in the plugin) with `getPurpose() === 'inbound_imap'`. Its
  `onTokenGranted(OAuth2Token $token, array $payload)` loads the
  `InboundImapAccount` from `$payload['account_id']`, stores the encrypted
  access+refresh tokens and expiry on the account, and returns the IMAP-accounts
  admin URL.
- **Scopes (requested at initiation):** Google `https://mail.google.com/`;
  Microsoft `https://outlook.office365.com/IMAP.AccessAsUser.All offline_access`
  (`offline_access` required for a refresh token).
- **Consent flow:** admin adds an account with provider=gmail/microsoft → clicks
  **"Connect"** → the page calls
  `OAuth2Client::beginConsent($providerKey, $scopes, 'inbound_imap', ['account_id' => $id], $returnUrl)`
  and redirects to the returned consent URL. The provider returns to the **core**
  `/oauth_callback`, which validates state, exchanges the code, and dispatches to
  `InboundImapOAuthConsumer`. No plugin callback route.
- **Refresh:** handled by `OAuth2Client::ensureFresh` inside `OAuth2ImapAuth` on
  each poll; refresh failure raises `OAuth2Exception`, which the ingestor records
  as the account's `last_status` and skips — never crashes the run.
- **Redirect URI** is the single shared `/oauth_callback` registered once per
  provider in the OAuth core setup — IMAP adds nothing to the cloud app.

## Config UI / admin

New admin pages under the plugin's tab strip (reuse `inbound_email_admin_tabs()`,
add an **"IMAP Accounts"** tab):
- `admin/admin_inbound_email_imap.php` — list accounts (label, provider, bound
  mailbox, enabled, last poll, last status), add/edit/delete, **"Poll now"**, and
  **"Connect"** (for OAuth accounts). FormWriter only.
- `admin/admin_inbound_email_imap_edit.php` (+ logic) — account form: pick
  provider (preset fills host/port/encryption), username, auth fields (password
  field for basic; "Connect with Google/Microsoft" button for oauth), bound
  alias, folder, interval, enabled. Use `visibility_rules` to show the password
  field only for password auth.
- OAuth app credentials (`*_client_id/secret`) live on the Email settings area or
  a dedicated section, entered once per provider.

## Setup checks

Reuse the per-provider check pattern (`getSetupChecks`-style) for an account:
- **Connectivity:** connect + select folder (the "Test connection" / "Poll now"
  button), report success or the IMAP error.
- **OAuth:** token present and refreshable; consent not revoked.
- **Binding:** the account's alias exists and is store-capable.

## Dependencies (build decision)

- **IMAP access:** `php-imap` extension is installed and handles basic auth
  cleanly. Its XOAUTH2 support is workable but fiddly. **Recommended:** evaluate
  the maintained userland library **`webklex/php-imap`** (folders, UID, and
  OAuth bearer auth in one API) vs. the raw extension; lean toward the library
  for the OAuth paths, keep the extension as a fallback.
- **OAuth2 consent/refresh:** **not a dependency of this spec** — it is provided
  by the [OAuth2 Core](implemented/oauth2_core.md), whose default is a direct Guzzle
  implementation (Guzzle is already vendored) with no new package.

Pin the IMAP-library choice when building; declare via Composer
(`composerAutoLoad` is already wired). Note any new `require` in `composer.json`.

## Security

- **Secrets at rest:** IMAP passwords and OAuth refresh tokens are long-lived
  credentials to a user's *entire* mailbox. Store **encrypted** via the
  general-purpose core [`SecretBox`](secret_box.md) helper — never plaintext, never
  log, never echo (per the secret-handling rule); accessors decrypt on use only.
  (OAuth *client* secrets are owned and encrypted by the OAuth core, not stored
  here.)
- **OAuth callback:** the single-use, session-bound `state` and the callback live
  in the OAuth core; `state` is an opaque nonce that keys a server-side flow entry
  carrying `{account_id}`, CSRF-binding the flow to the initiating admin session.
  The IMAP account page that starts the flow is permission-10.
- **Scope minimization:** request only mail-read scope; document that the token
  grants full mailbox read.
- **Least exposure:** redact tokens/passwords in `last_status` and any debug
  output; the "Test connection" result must not leak the credential.
- Apply the untrusted-input markers to IMAP-ingested bodies exactly as the
  webhook/pipe paths do (stored mail is attacker-controlled).

## Files

### To create
| File | Purpose |
|------|---------|
| `plugins/inbound_email/data/inbound_imap_account_class.php` | `InboundImapAccount` + Multi; encrypted secret accessors |
| `plugins/inbound_email/includes/InboundEmailPollingProvider.php` | the polled-transport interface |
| `plugins/inbound_email/includes/ImapIngestor.php` | connect/fetch/store one account |
| `plugins/inbound_email/includes/imap_providers/GenericImapProvider.php` | base (password) + Yahoo/iCloud/Fastmail presets |
| `plugins/inbound_email/includes/imap_providers/GmailImapProvider.php` | Google preset + OAuth |
| `plugins/inbound_email/includes/imap_providers/MicrosoftImapProvider.php` | Microsoft preset + OAuth |
| `plugins/inbound_email/includes/imap_auth/PasswordImapAuth.php`, `OAuth2ImapAuth.php` | auth strategies (OAuth2ImapAuth = XOAUTH2 formatting + `OAuth2Client::ensureFresh`) |
| `plugins/inbound_email/includes/oauth_consumers/InboundImapOAuthConsumer.php` | `OAuth2Consumer` (purpose `inbound_imap`): store granted tokens on the account |
| `plugins/inbound_email/tasks/PollImapAccounts.json` + `.php` | the poller scheduled task |
| `plugins/inbound_email/admin/admin_inbound_email_imap.php` (+ logic) | accounts list / add / poll-now / connect |
| `plugins/inbound_email/admin/admin_inbound_email_imap_edit.php` (+ logic) | account editor (Connect button → `OAuth2Client::beginConsent`) |
| `plugins/inbound_email/tests/inbound_imap_account_test.php` | model + encryption + UID cursor |
| `plugins/inbound_email/tests/imap_poller_test.php` | poll → store → dedup → cursor advance (mock IMAP) |

### To modify
| File | Change |
|------|--------|
| `plugins/inbound_email/includes/InboundProviderRegistry.php` | discover polling providers (separate list) |
| `plugins/inbound_email/includes/admin_tabs.php` | add "IMAP Accounts" tab |
| `plugins/inbound_email/includes/InboundEmailRouter.php` | expose a store-only entry the ingestor reuses (if `storeMessage` needs a non-Postfix caller path); bump `@version` |
| `plugins/inbound_email/plugin.json` | register the poller task and the IMAP-source domain flag; serve `/admin/...imap*`; bump `version`. **No OAuth settings or callback route** (owned by the OAuth core). Declares a dependency on the OAuth core. |
| `plugins/inbound_email/data/inbound_email_domain_class.php` | optional `ied_is_imap_source` flag so Setup skips MX for IMAP-sourced mailboxes |
| `serve.php` | **no change** — IMAP admin routes auto-discover; the OAuth core's `/oauth_callback` view auto-discovers too (no route) |

### Schema
Applied by **Sync with Filesystem** / `update_database` from the data classes —
no migration (consistent with the rest of the plugin; the one index, if any, via
the plugin migrations file).

## Testing

- **Account model** — CRUD; encrypted secret round-trips (set/get password +
  tokens, `*_enc` never plaintext); `due`/`enabled` filters.
- **UID cursor** — fetch advances `last_seen_uid`; a UIDVALIDITY change resets to
  0; re-poll stores nothing new (dedup).
- **Ingestor (mock IMAP)** — given fixture raw messages, stores them under the
  bound alias, dedups re-fetch, records status; a connection error is captured
  per-account without throwing.
- **OAuth consumer seam** — `InboundImapOAuthConsumer::onTokenGranted` stores the
  granted tokens (encrypted) on the right account and returns the accounts URL; an
  expired-and-unrefreshable token (mock `OAuth2Client::ensureFresh` throwing
  `OAuth2Exception`) surfaces as the account's `last_status`, not a crash. (The
  grant/refresh/`state` mechanics themselves are covered by the
  [OAuth2 Core](implemented/oauth2_core.md) tests, not duplicated here.)
- **Provider presets** — picking Gmail/Microsoft/Yahoo/iCloud/Fastmail yields the
  correct host/port/encryption and auth method.
- **Reader integration** — an IMAP-ingested message appears in the Mailbox Reader
  under its bound mailbox and honors grant scope (reuses the reader tests).
- **Poller task** — `run()` polls only due/enabled accounts, respects
  max-per-run, returns a summary; one failing account doesn't stop the rest.

Run `php -l` + `validate_php_file.php` on every created/modified PHP file. Live
end-to-end (real Gmail/Microsoft consent) is a manual checklist in the docs.

## Documentation

- New section **"Receiving by IMAP poll"** in
  `plugins/inbound_email/docs/overview.md`: the account model, the per-host
  matrix (who needs OAuth vs app password), the poller cadence, the
  bring-your-own-mailbox story (SMTP out + IMAP in), and that the reader/grants
  are reused unchanged.
- OAuth app registration (Google Cloud / Azure, scopes, the shared redirect URI,
  where to paste client id/secret) is documented **once** in the OAuth core's
  `docs/oauth2.md` — the IMAP overview links to it rather than duplicating it, and
  adds only the IMAP-specific scopes and the per-account "Connect" step.
- `docs/scheduled_tasks.md`: list the `PollImapAccounts` task and its cadence
  model (task floor vs per-account interval).
- `docs/email_system.md`: note IMAP as an inbound transport alongside
  Postfix/Mailgun, and the SMTP-out + IMAP-in pairing for low-volume users.

## Versioning

- `plugin.json` minor bump (new feature, backward compatible — no IMAP accounts
  exist until created).
- Bump `@version` on each modified file. Note any new Composer requires.

## Out of scope / future

- **OAuth for outbound (XOAUTH2 SMTP send as Gmail/365).** This spec is inbound
  only. Outbound via these hosts already works through the generic SMTP provider
  with an app password; OAuth *sending* is a separate, later add-on that could
  reuse the same `GoogleOAuthProvider`/`MicrosoftOAuthProvider`.
- **Push/IDLE.** IMAP IDLE (near-real-time) instead of interval polling — a later
  optimization; polling is correct and simple for low volume.
- **Gmail API / Microsoft Graph** transports (non-IMAP). Generic IMAP covers all
  hosts uniformly; native APIs are a future per-provider optimization.
- **POP3.** IMAP only (UID-based incremental sync; POP3's download-and-delete
  model is a poor fit).
- **Auto-provisioning OAuth apps.** Admin registers the Google/Azure app once and
  pastes credentials; the platform does not create cloud apps.
- **A dedicated Gmail *sending* provider** — explicitly rejected; redundant with
  generic SMTP and breaks domain alignment (see prior analysis).
