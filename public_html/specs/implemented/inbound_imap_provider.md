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
(`iem_inbound_email_messages`) and show up in the **Mailbox Reader** like any other
mailbox — it already reads `iem_`. The reader gains one shared, transport-agnostic
addition: a per-message **attachment list** with clickable download (§4), which all
inbound mail can use.

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
(iem_message_id_header, iem_recipient)`, and a pure-PHP IMAP client
(`horde/imap_client`) for the connect/fetch.

This is also a platform-level abstraction, not a product feature: a generic IMAP
ingestor with pluggable auth, where Gmail and Microsoft are two OAuth strategies
among several hosts — not a "Gmail integration."

## Core model

Four decisions define it:

1. **IMAP accounts are additive, not a system transport switch.** The existing
   `inbound_email_provider` setting selects the *one* system transport
   (postfix/mailgun) that owns the MX/webhook. IMAP accounts are **independent,
   multiple, per-mailbox pollers** that run *alongside* whatever that primary
   transport is. Adding an IMAP account never changes the system transport.

2. **An IMAP account maps to a mailbox (alias).** Each account binds to an
   inbound **alias** — the mailbox it populates. Fetched messages are stored with
   that alias's id, so they appear as a normal mailbox in the reader and honor the
   grant model (the new attachment list inherits that same grant). **No MX/DNS is
   required** for an
   IMAP-sourced alias — the mail is already in the remote mailbox; that is the
   whole point. (The alias still belongs to a domain record for identity, but
   that domain needs no MX. See "Mailbox mapping" for the one schema nuance.)

3. **Auth is a pluggable strategy.** The connection (host/port/encryption/folder)
   is generic; the *authentication* is either a stored password/app-password or
   an OAuth2 bearer token obtained from the [OAuth2 Core](implemented/oauth2_core.md). Gmail
   and Microsoft use the OAuth strategy; everyone else uses a password.

4. **IMAP-sourced messages are reference-backed, not copied whole.** Pushed mail
   (Postfix/Mailgun) *must* store the full raw because the source keeps no copy —
   it is delivered once and gone. An IMAP mailbox is the opposite: a **durable
   remote store** that stays put. So the poller stores only what the reader shows
   — headers + the `text/plain`/`text/html` bodies + an **attachment manifest** (the
   list of parts) — plus a **locator** back to the message, and leaves
   `iem_raw_message` empty. Individual attachments are fetched **on demand** when the
   user clicks one.
   Attachment bytes never land on platform disk or in the database, so a 50 GB Gmail
   costs the platform kilobytes per message. See §3–§4 under **Data model**.

```
  Scheduled task: PollImapAccounts (every few minutes)
        │  for each enabled account whose interval elapsed
        ▼
  ImapIngestor(account)
   ├─ auth branch on iia_auth_method: password LOGIN | XOAUTH2
   │        └─ oauth2 → OAuth2Client::ensureFresh(provider, token) → bearer
   ├─ connect (Horde_Imap_Client), select folder, UID search > last_seen_uid
   ├─ fetch raw messages
   ├─ InboundEmailRouter::storeMessage(...)  → iem_ (dedup via UNIQUE)
   └─ advance last_seen_uid / uidvalidity, record status
        │
        ▼
  iem_inbound_email_messages (+ ima_ attachment manifest)  → Mailbox Reader (+ attachment list)
```

## The polled transport — a preset catalog, not a class hierarchy

Keep `InboundEmailProvider` (push) untouched: Postfix and Mailgun earn their
classes because parsing a piped delivery and parsing a webhook are genuinely
different code. The pull side is the opposite — connecting, selecting a folder, and
fetching over IMAP is **identical** for every host. The only things that vary by
host are three values: the connection preset, the auth method, and (for OAuth) which
OAuth provider key. That is **data, not behavior**, so there is no polling-provider
interface and no per-host class hierarchy. One **preset catalog** describes every
supported host:

```php
// a const map on InboundImapAccount, read by the editor (fill host/port) and ingestor
'imap_gmail'     => ['label'=>'Gmail / Google Workspace', 'host'=>'imap.gmail.com',        'port'=>993, 'encryption'=>'ssl', 'auth'=>'oauth2',   'oauth_provider'=>'google'],
'imap_microsoft' => ['label'=>'Microsoft 365 / Outlook',  'host'=>'outlook.office365.com', 'port'=>993, 'encryption'=>'ssl', 'auth'=>'oauth2',   'oauth_provider'=>'microsoft'],
'imap_yahoo'     => ['label'=>'Yahoo / AOL',              'host'=>'imap.mail.yahoo.com',   'port'=>993, 'encryption'=>'ssl', 'auth'=>'password'],
'imap_icloud'    => ['label'=>'iCloud',                   'host'=>'imap.mail.me.com',      'port'=>993, 'encryption'=>'ssl', 'auth'=>'password'],
'imap_fastmail'  => ['label'=>'Fastmail',                 'host'=>'imap.fastmail.com',     'port'=>993, 'encryption'=>'ssl', 'auth'=>'password'],
'imap_generic'   => ['label'=>'Generic IMAP',             'host'=>null,                    'port'=>993, 'encryption'=>'ssl', 'auth'=>'password'],
```

The account editor reads this catalog to populate the provider dropdown and pre-fill
host/port/encryption when one is picked; `imap_generic` leaves the host blank for the
user to supply. **Gmail and Microsoft are not special** — they are simply the rows
whose `auth` is `oauth2`. Adding a host later (or letting a generic host use OAuth) is
a **one-line edit here** — the whole integration-point inventory in one place, not a
new class per host. Push providers (Postfix/Mailgun) are unaffected.

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

### 3. Inbound message — reference-backed (modify `inbound_email_message_class.php`)

The store has one row per message in `iem_inbound_email_messages` with three content
columns: `iem_body_plain`, `iem_body_html`, and `iem_raw_message` (the complete RFC822
message as a `text` column). Today attachments are never broken out — they sit inside
the raw, and the reader's only attachment affordance is a whole-message *.eml* download
(open it in a mail client to dig the files out). **This spec replaces that with
clickable per-attachment download** (§4) so a user clicks a PDF and gets the PDF. IMAP
mail gets it now; the machinery is built so Postfix/Mailgun mail adopts it later
unchanged.

**IMAP-sourced messages are stored without the raw.** The poller writes the body
columns and headers as usual but leaves `iem_raw_message` **empty**, and records a
locator so individual parts can be re-fetched on demand. Add these nullable columns
(populated only for IMAP-sourced rows; a non-null `iem_iia_inbound_imap_account_id`
marks a message reference-backed):

| Field | Type | Notes |
|-------|------|-------|
| `iem_iia_inbound_imap_account_id` | int8 | source account; non-null ⇒ reference-backed, parts fetched on demand |
| `iem_imap_uid` | int8 | message UID within the folder |
| `iem_imap_uidvalidity` | int8 | folder UIDVALIDITY the UID is valid under |
| `iem_imap_folder` | varchar(255) | folder the UID lives in |

`iem_message_id_header` (already stored) is the fallback locator when a UID goes
stale. Dedup is unchanged — `UNIQUE (iem_message_id_header, iem_recipient)` still
applies, so re-fetching a message stores nothing new.

**No raw/.eml in the UI, any transport.** The user-facing surface is the body plus the
clickable attachment list (§4) — nothing else. The platform's existing *.eml* download
link and raw-source `<pre>` view are **removed** for every message, IMAP and
Postfix/Mailgun alike; nobody uses them and per-attachment download covers the real
need. (Push transports still *store* the raw bytes for now — what becomes of that is
the storage refactor's concern; this change only retires the user-facing button and
view, not the column.)

### 4. Attachment manifest + per-attachment download (new)

So a user can **click a PDF and get the PDF**, store an attachment **manifest** per
message and serve each part on demand. No attachment bytes are ever stored on the
platform — only the metadata needed to list the parts and fetch one.

New child table `data/inbound_message_attachment_class.php`
(`InboundMessageAttachment` + Multi). Prefix `ima`, table
`ima_inbound_message_attachments`:

| Field | Type | Notes |
|-------|------|-------|
| `ima_inbound_message_attachment_id` | int8 serial PK | |
| `ima_iem_inbound_email_message_id` | int8 | parent message; FK cascade |
| `ima_filename` | varchar(500) | display/download name (sanitized on stream) |
| `ima_content_type` | varchar(255) | e.g. `application/pdf` |
| `ima_size_bytes` | int8 | for the listing |
| `ima_mime_part` | varchar(40) | MIME section the bytes live in (e.g. `2`, `2.1`) |
| `ima_encoding` | varchar(40) | transfer-encoding to decode on fetch (`base64`/`quoted-printable`/…) |
| `ima_content_id` | varchar(255) | `cid:` for inline parts, else null |
| `ima_is_inline` | bool | inline (cid) vs. a real attachment |
| `ima_create_time` | timestamp(6) | |

The manifest is **transport-agnostic**: it describes *which* parts exist and *where*
in the MIME tree, not how to read them. Turning a part into bytes is a per-message
detail (retrieval, below). Postfix/Mailgun adopt per-attachment download later by
simply populating this same table (from a MIME parser over their stored raw) and
reusing the same endpoint + reader UI — no new schema, no UI change.

**At ingest (IMAP).** The poller already reads `BODYSTRUCTURE` to locate the text
parts; that same structure enumerates every attachment part (filename, content-type,
size, section number, encoding, content-id). Write one `ima_` row per non-text part —
metadata only, kilobytes. No part bytes are fetched at poll time.

**Per-attachment download endpoint** (new admin logic + auto-discovered view, e.g.
`admin/admin_inbound_email_attachment`). Given an attachment id: load the row + its
message, enforce the **same permission + mailbox-grant check the reader uses** (an
attachment is exactly as private as its message), then retrieve the part and stream it
with `Content-Type: <ima_content_type>` and `Content-Disposition: attachment;
filename="<sanitized ima_filename>"`. Retrieval dispatches on the message's source:

- **IMAP-backed** (`iem_iia_inbound_imap_account_id` set): connect via the account's
  auth, `FETCH BODY[<ima_mime_part>]` (Message-ID fallback if UIDVALIDITY changed),
  decode per `ima_encoding`, stream. Pass-through — the part never lands on disk.
- **Stored-raw** (push, *future*): parse the stored raw to `ima_mime_part`, decode,
  stream. Not built here; the endpoint carries the seam.

If the part can't be retrieved (message deleted/moved/account disabled), return an
honest "no longer available in the source mailbox," not an error.

**Reader UI.** The message view lists attachments from the manifest (filename, size,
type); each is a link to the download endpoint. Inline parts (`ima_is_inline`) are
excluded — they belong to the HTML body. How the body renders is unchanged.

**Size — no cap.** A per-attachment fetch pulls exactly one part on click; its size is
bounded by the provider's send-time message maximum (Gmail ~25 MB, Microsoft ≤ ~150
MB), and the endpoint is permission-10-admin only — no unbounded or abusable vector. A
low limit would defeat the point (getting your attachment). Stream the part to the
response rather than buffering extra copies; let PHP `memory_limit` be the backstop.

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

**First connect — start from now.** When an account's cursor is unset (just
created / just authorized), seed `last_seen_uid` to the folder's **current high
UID** (and store its `uidvalidity`) so the first poll ingests only mail arriving
*after* hookup — never the mailbox's back-catalogue. This is what makes mailbox
size a non-issue: a 50 GB archive and an empty mailbox behave identically once
connected. A bounded historical backfill (last N days) is an explicit opt-in, off
by default.

Per run:
1. Load enabled accounts that are **due** (`last_poll_time + interval ≤ now`).
   (Self-throttle per account so the task can run frequently without hammering
   every mailbox — the task frequency is the *floor*, the account interval is the
   *actual* cadence.) Guard against overlap: an account already mid-poll (or a
   still-running prior task instance) must not be polled again concurrently —
   take a per-account claim (e.g. stamp `last_poll_time` on pickup) so two runs
   can't race on `last_seen_uid`.
2. For each account:
   a. Authenticate per `iia_auth_method`. If `oauth2` and the access token is
      expired/near expiry → **refresh** via `OAuth2Client::ensureFresh` (store the
      new token). If refresh fails → record status, skip (don't crash the run).
   b. Connect (host/port/encryption), select folder. Compare server
      **UIDVALIDITY** to stored; if it changed (folder was recreated), **re-seed
      `last_seen_uid` to the current high UID — not 0** — so a reset doesn't
      trigger a full back-catalogue ingest, and log it.
   c. `UID SEARCH` for messages with UID > `last_seen_uid` (cap to max-per-run).
   d. For each: read `RFC822.SIZE` + `BODYSTRUCTURE`, then **fetch only the text
      parts** (`text/plain`/`text/html`) — *not* attachment parts, *not* the whole
      raw. Hand the decoded bodies + headers + size to the store path
      (`InboundEmailRouter::storeMessage` / a store-only entry) with the account's
      bound alias + recipient, writing the **locator** columns and leaving
      `iem_raw_message` empty (see *Reference-backed*). From the same `BODYSTRUCTURE`,
      write one `ima_` **attachment-manifest** row per non-text part (filename, type,
      size, section number, encoding) — metadata only; no attachment bytes fetched
      (see §4). Attachment bytes stay on the server until clicked. Dedup is the
      existing UNIQUE constraint, so re-fetching is harmless (re-write the manifest
      idempotently or skip when the row already exists). Record `RFC822.SIZE` into `iem_size_bytes` for display, but **do
      not** gate ingest on it — a 25 MB message with a one-line body must still be
      ingested (its attachments are never fetched). The only ingest guard is on the
      **text-body** size actually pulled: if the combined `text/plain`+`text/html`
      parts exceed a (generous) configured ceiling, store a truncated body marked as
      such rather than skipping the message — the message still appears in the reader,
      and the full body part can be fetched on demand the same way an attachment is
      (it is just another MIME part). The ceiling is generous enough that this is rare.
   e. Advance `last_seen_uid`; set `last_poll_time`, `last_status`.
3. Return a summary (accounts polled, messages stored, errors).

Failures are **per-account and non-fatal** — one unreachable mailbox or expired
token must not stop the others (same posture as the router's per-recipient
handling).

## Authenticating the connection

Two auth modes, selected by the account's `iia_auth_method` (which the preset
catalog sets) — a single **branch in `ImapIngestor`**, not a strategy class
hierarchy. The set is closed: `LOGIN` and `XOAUTH2` are the only IMAP auth that
matters, so a two-element interface would be pure ceremony.

- **`password`** — basic `LOGIN` with the username + stored app/basic password,
  decrypted via the core [`SecretBox`](secret_box.md) on use.
- **`oauth2`** — `AUTHENTICATE XOAUTH2` with a valid bearer token. The ingestor
  reads the account's stored `OAuth2Token`, calls
  `OAuth2Client::ensureFresh($provider, $token)` from the [OAuth2 Core](implemented/oauth2_core.md)
  (which refreshes and hands back a valid access token), persists the token if it
  changed, and formats the XOAUTH2 SASL string. That formatting plus the IMAP use
  are the only OAuth-adjacent code this plugin owns — it implements no grant,
  refresh, or callback logic itself.

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
- **Refresh:** handled by `OAuth2Client::ensureFresh` in the ingestor's `oauth2`
  auth branch on each poll; refresh failure raises `OAuth2Exception`, which the
  ingestor records as the account's `last_status` and skips — never crashes the run.
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

- **IMAP access:** pinned to **`horde/imap_client`** (installed as the maintained,
  composer-clean fork **`bytestream/horde-imap-client`**). It is a pure-PHP IMAP
  client — no reliance on the `php-imap` C extension — with **native XOAUTH2** and
  first-class `BODYSTRUCTURE` enumeration + single-part `FETCH`, which is exactly
  what the reference-backed design needs (list parts cheaply, fetch one part on
  demand). It pulls ~16 self-contained `bytestream/horde-*` packages and **no
  framework substrate** (no Laravel/Carbon). It is wrapped entirely behind
  `ImapIngestor`, so the library choice is contained to that one class.
  - *Rejected alternatives, for the record:* the raw `php-imap` C extension
    (XOAUTH2 unreliable on the installed `libc-client 2007f` build — would defeat
    the Gmail/Microsoft headline); `webklex/php-imap` (capable, but drags the
    Laravel `illuminate/*` + Carbon substrate, ~20 packages); `ddeboer/imap`
    (tiny, but wraps the same c-client extension and inherits its XOAUTH2 gap);
    `laminas/laminas-mail` (light, but its IMAP layer leans on whole-message fetch
    and undercuts the reference-backed, kilobytes-per-message goal).
- **OAuth2 consent/refresh:** **not a dependency of this spec** — it is provided
  by the [OAuth2 Core](implemented/oauth2_core.md), whose default is a direct Guzzle
  implementation (Guzzle is already vendored) with no new package.
- **Raw-message storage refactor:** **not a dependency.** IMAP is reference-backed
  (it stores no raw; it fetches parts on demand), so it neither needs nor blocks on the
  push-transport storage refactor ([inbound_raw_message_storage.md](inbound_raw_message_storage.md)).
  The two are independent.

Declare via Composer (`composerAutoLoad` is already wired):
`composer require bytestream/horde-imap-client`. Note the new `require` in
`composer.json`.

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
- **Attachment download:** the per-attachment endpoint enforces the **same
  mailbox-grant + permission check as the reader** — an attachment is exactly as
  private as its message. Sanitize `ima_filename` in the `Content-Disposition` header
  (strip CR/LF and path separators) to prevent header injection, and serve with
  `Content-Disposition: attachment` + `X-Content-Type-Options: nosniff` so
  attacker-controlled attachment bytes are downloaded, never rendered inline in the
  admin origin. Stream pass-through; never persist the part.

## Files

### To create
| File | Purpose |
|------|---------|
| `plugins/inbound_email/data/inbound_imap_account_class.php` | `InboundImapAccount` + Multi; encrypted secret accessors; **the preset catalog** (const map of host/port/encryption/auth/oauth_provider per provider_key) the editor and ingestor read |
| `plugins/inbound_email/includes/ImapIngestor.php` | connect/auth/fetch/store one account — **branches on `iia_auth_method`** (password `LOGIN` vs `XOAUTH2` via `OAuth2Client::ensureFresh`); fetches text parts + `BODYSTRUCTURE` (reference-backed), writes the attachment manifest, never fetches attachment bytes at poll time; also implements the on-demand single-part `FETCH BODY[...]` the attachment endpoint calls, and the `testConnection()` the Setup checks use |
| `plugins/inbound_email/data/inbound_message_attachment_class.php` | `InboundMessageAttachment` + Multi — the per-message attachment manifest (transport-agnostic) |
| `plugins/inbound_email/admin/admin_inbound_email_attachment.php` (+ logic) | per-attachment download endpoint: grant-checked, dispatches retrieval by message source, streams the part with its content-type + filename |
| `plugins/inbound_email/includes/oauth_consumers/InboundImapOAuthConsumer.php` | `OAuth2Consumer` (purpose `inbound_imap`): store granted tokens on the account |
| `plugins/inbound_email/tasks/PollImapAccounts.json` + `.php` | the poller scheduled task |
| `plugins/inbound_email/admin/admin_inbound_email_imap.php` (+ logic) | accounts list / add / poll-now / connect |
| `plugins/inbound_email/admin/admin_inbound_email_imap_edit.php` (+ logic) | account editor (Connect button → `OAuth2Client::beginConsent`) |
| `plugins/inbound_email/tests/inbound_imap_account_test.php` | model + encryption + UID cursor |
| `plugins/inbound_email/tests/imap_poller_test.php` | poll → store → dedup → cursor advance (mock IMAP) |
| `plugins/inbound_email/tests/inbound_attachment_test.php` | manifest written from `BODYSTRUCTURE`; per-attachment fetch returns the right part decoded; inline parts excluded from the list; missing part → graceful |

### To modify
| File | Change |
|------|--------|
| `plugins/inbound_email/includes/admin_tabs.php` | add "IMAP Accounts" tab |
| `plugins/inbound_email/includes/InboundEmailRouter.php` | expose a store-only entry the ingestor reuses that accepts pre-extracted bodies + locator and writes a row with an empty `iem_raw_message` (the ingestor already has the bodies; no raw to parse); bump `@version` |
| `plugins/inbound_email/data/inbound_email_message_class.php` | add the nullable IMAP locator columns (`iem_iia_inbound_imap_account_id`, `iem_imap_uid`, `iem_imap_uidvalidity`, `iem_imap_folder`); bump `@version` |
| `plugins/inbound_email/logic/admin_inbound_email_message_logic.php` | **remove the `download_eml` action** (no user-facing raw/.eml download, any transport); bump `@version` |
| `plugins/inbound_email/admin/admin_inbound_email_message.php` (+ the message view) | list attachments from the manifest (filename, size, type), each linking to the download endpoint; inline parts excluded; **remove the *.eml* download link and the raw `<pre>` view** (all transports); bump `@version` |
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
- **Initial cursor (start from now)** — first connect seeds `last_seen_uid` to the
  folder's current high UID; the back-catalogue is **not** ingested; a UIDVALIDITY
  change re-seeds to the new high UID (not 0), so a folder reset doesn't trigger a
  full backfill.
- **UID cursor** — fetch advances `last_seen_uid`; re-poll stores nothing new
  (dedup); an account already mid-poll isn't picked up concurrently.
- **Reference-backed storage** — ingest writes `iem_body_plain`/`iem_body_html` +
  locator columns and leaves `iem_raw_message` empty; attachment parts are never
  fetched; a large attachment with a tiny body is still ingested (not skipped on
  `RFC822.SIZE`); a body over the text-body ceiling is truncated-and-marked, not
  dropped.
- **Ingestor (mock IMAP)** — given fixture messages, stores them under the bound
  alias, dedups re-fetch, records status; a connection error is captured
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
- **Per-attachment download** — the manifest is written from a fixture
  `BODYSTRUCTURE` (filename/type/size/part/encoding); clicking an attachment fetches
  exactly that part (mock `FETCH BODY[...]`), decodes per `ima_encoding`, and streams
  with the right `Content-Type` + filename; inline (`cid:`) parts are excluded from
  the list; a part that can't be fetched yields the graceful "no longer available"
  result; the endpoint enforces the same mailbox-grant check as the reader (a user
  without grant is refused).
- **Poller task** — `run()` polls only due/enabled accounts, respects
  max-per-run, returns a summary; one failing account doesn't stop the rest.

Run `php -l` + `validate_php_file.php` on every created/modified PHP file. Live
end-to-end (real Gmail/Microsoft consent) is a manual checklist in the docs.

## Documentation

- New section **"Receiving by IMAP poll"** in
  `plugins/inbound_email/docs/overview.md`: the account model, the per-host
  matrix (who needs OAuth vs app password), the poller cadence, the
  bring-your-own-mailbox story (SMTP out + IMAP in), and that the reader/grants
  are reused — plus the new clickable **attachment list** (manifest + on-demand
  per-attachment download), noting attachment bytes are never stored and that
  Postfix/Mailgun mail adopts the same list later.
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
- **Per-attachment download for Postfix/Mailgun mail.** Per-attachment download is
  built here for IMAP (§4), on a transport-agnostic manifest + endpoint + reader list.
  Bringing the *already-received* Postfix/Mailgun mail into it — which needs a real
  MIME parser to enumerate/extract parts from their stored raw — is a later, additive
  step: it populates the same `ima_` table and reuses the same endpoint and UI, no new
  schema and no UI change. Not built in this spec.
- **Attachment search / indexing / virus scanning.** The manifest lists parts for
  display and download; full-text search of attachment contents and AV scanning are
  separate future concerns.
- **Historical backfill by default.** First connect starts from now; an optional
  bounded backfill (last N days) is a future add-on, not the default.
- **Auto-provisioning OAuth apps.** Admin registers the Google/Azure app once and
  pastes credentials; the platform does not create cloud apps.
- **A dedicated Gmail *sending* provider** — explicitly rejected; redundant with
  generic SMTP and breaks domain alignment (see prior analysis).

## Implementation notes — as built (deviations from spec)

The transport, OAuth flow, reference-backed storage, and attachment manifest
landed as specified. These points differ from or extend the spec above:

### Admin IA: one "Accounts" tree, not an "IMAP Accounts" tab
The spec described a standalone **IMAP Accounts** tab (list + add/edit + "Poll
now"). Instead the inbound-email admin was consolidated into four tabs —
**Mailboxes** (the reader, now the default landing tab), **Accounts**, **Logs**,
**Setup** — and the separate Domains / Forwarding Aliases / IMAP Accounts list
pages were retired (they redirect to Accounts). The **Accounts** page
(`admin_inbound_email_accounts.php`) is a single domain → mailbox → feed tree;
the old per-object editors remain and are reached contextually from it.

### Gmail-is-a-domain model
A polled mailbox is modeled as a normal `alias@domain`: `me@gmail.com` is alias
`me` under domain `gmail.com`, with `ied_is_imap_source` set (no MX/DNS). The
domain editor leads with a **Type** dropdown (`Custom` / `IMAP — Gmail` /
`Microsoft` / `Yahoo` / `iCloud` / `Fastmail` / `Other host`); known providers
imply the domain via `InboundImapAccount::PROVIDER_EMAIL_DOMAINS` (a new shared
map). Under an IMAP-source domain, **+ Mailbox** and **Edit** open one *combined*
editor that creates/edits the mailbox (alias name + access grants) **and** its
feed together — creating the feed if missing — so there is no separate "+ IMAP
feed" step there. Hosted (MX) domains keep the distinct alias + "+ IMAP feed".

### First-connect import is a per-mailbox choice (changeable anytime)
The spec seeds the cursor to "now" and treats historical backfill as a future
opt-in. As built, the feed editor has an **Existing mail** choice — **Import only
future emails** (default, = seed-to-now) or **Import full email history** —
editable at any time. Switching to full history resets the cursor and backfills
**oldest-first in bounded UID windows** (`max_per_account` per fetch), so a large
mailbox imports incrementally across polls. Stored as new column
`iia_import_history`. (A date-bounded "last N days" backfill is *not* offered —
it needs IMAP `SEARCH SINCE`, which Gmail's ESEARCH rejects; see below.)

### No UID SEARCH — Gmail rejects ESEARCH
The spec's poll step uses `UID SEARCH > last_seen_uid`. Gmail advertises the
`ESEARCH` capability but returns `BAD Could not parse command` for the
`UID SEARCH RETURN (...)` form Horde emits. So search was removed entirely: the
poller does a numeric `UID FETCH (cursor+1):(cursor+max)` window and derives the
new UIDs from the result keys. This also bounds each fetch (enabling the windowed
backfill above) and is server-agnostic.

### XOAUTH2 needs a non-empty `password` param
This Horde version rejects an empty `password` *before* selecting an auth
mechanism, so XOAUTH2 alone fails with "No password provided." `ImapIngestor`
sets `password` to the bearer token in addition to `xoauth2_token`; XOAUTH2 is
still the mechanism used.

### Connection health: `needs_reauth` + Connect/Reconnect/none
Rather than always offering "Reconnect", the UI shows **Connect** only when never
connected, **Reconnect** only when the stored token is known-broken, and nothing
when healthy. A new column `iia_needs_reauth` is set when a token refresh/auth
fails (e.g. Google's 7-day Testing refresh-token expiry) and cleared whenever a
token is (re)stored.

### Auto-fetch health warning
The Accounts page shows an amber **⚠ Auto-fetch** warning badge on a feed when the
`PollImapAccounts` scheduled task is not scheduled, paused, config-disabled, or
running less often than hourly — linking to the Scheduled Tasks page.

### Naming
The user-facing "Poll now" / "Poll interval" / task name were renamed to
**Fetch now** / **Fetch interval** / **Fetch inbound IMAP mail**. Internal
identifiers (`poll_now` action, `iia_poll_interval_seconds`, the `PollImapAccounts`
task class) are unchanged.

### Schema additions beyond the spec
`iia_needs_reauth` (bool) and `iia_import_history` (bool) on
`iia_inbound_imap_accounts`, in addition to the columns listed in §Schema.
