# Local Mailbox Delivery for the Inbound Email Plugin

## Overview

Add a third delivery option to the Inbound Email plugin: instead of relaying an
inbound message onward via SMTP, an alias (or a domain catch-all) can **store
the message locally** in a database-backed mailbox. Stored messages are visible
in a new admin viewer and queryable directly, which makes the plugin a
self-hosted replacement for the Mailgun inbound-webhook testing pathway.

Today an alias has exactly one behaviour — forward to one or more real
addresses. This spec adds *local delivery* as a first-class destination type,
not a test-only side door.

> **Assumes the rename is done.** This spec uses the post-rename `inbound_email`
> identifiers throughout (`InboundEmail*` classes, `iea_/ied_/iel_/iem_`
> prefixes, `inbound_email_*` settings, `plugins/inbound_email/`). It must be
> implemented **after** `inbound_email_rename.md`. If the rename has not
> happened, translate the identifiers back (`ie*`→`ef*`,
> `InboundEmail*`→`EmailForwarding*`, `inbound_email_*`→`email_forwarding_*`).

## Motivation

The platform currently captures inbound test email through a separate feature
(`specs/implemented/inbound_email_testing.md`): the app sends mail, Mailgun
receives it on an MX-routed subdomain, Mailgun POSTs it to
`ajax/mailgun_inbound_webhook.php`, and it lands in the `iem_inbound_emails`
table for tests to query.

That pathway and the Inbound Email plugin already receive inbound mail the same
way — MX record → mail server → PHP. Mailgun is just a rented stand-in for
"Postfix that catches mail and hands it to PHP," which the plugin already runs
(`provisioning/install_email.sh` installs Postfix and the `joinery` pipe
transport). The only capability missing is *store the body somewhere queryable*
instead of *relay it onward*.

Adding local mailbox delivery lets the plugin's own Postfix absorb Mailgun's
receiving role, removes an external dependency for hosts that can run their
own MX, and survives the dev domain move to `dev.getjoinery.com` for free
(inbound domains are read live from the DB).

Mailgun-as-transport is **still supported** for deployments where running
Postfix as a public MX isn't an option (e.g. VPS providers that block
inbound port 25). It becomes a second front door into the same
`InboundEmailRouter` via the **Inbound Provider Architecture** below — the
same plug-in model the outbound side uses for sending providers.

## Architecture

```
App sends email → outbound relay delivers → MX routes to this host's Postfix
    → Postfix pipes to utils/inbound_email_handler.php → InboundEmailRouter::processEmail()
        → alias / catch-all delivery mode says "store"
            → message parsed + persisted to iem_inbound_email_messages
            → STORED entry written to iel_inbound_email_logs
    → Admin viewer / test queries read iem_inbound_email_messages
```

No change to how mail is *received*. The branch is entirely inside
`InboundEmailRouter::processEmail()` after alias/domain lookup.

## Data Model Changes

### 1. Alias delivery mode — `data/inbound_email_alias_class.php`

Add one field to `$field_specifications`:

| Field | Type | Notes |
|-------|------|-------|
| `iea_delivery_mode` | `varchar(20)` | `default => 'forward'`, `is_nullable => false` |

Allowed values:

- `forward` — current behaviour; relay to `iea_destinations` (default)
- `store` — persist to the local mailbox; do not relay
- `forward_and_store` — relay **and** persist a copy

Make `iea_destinations` nullable (currently `required => true`,
`is_nullable => false`). Move the "at least one destination" check out of the
column spec and into `prepare()`, where it applies **only** when
`iea_delivery_mode` is `forward` or `forward_and_store`. When the mode is
`store`, an empty `iea_destinations` is valid and the duplicate-alias and
max-destinations checks are skipped for the destination list.

`prepare()` must also validate `iea_delivery_mode` against the three allowed
values and reject anything else.

### 2. Domain catch-all mode — `data/inbound_email_domain_class.php`

Add one field:

| Field | Type | Notes |
|-------|------|-------|
| `ied_catch_all_mode` | `varchar(20)` | `default => 'forward'`, `is_nullable => false` |

- `forward` — current behaviour; unmatched mail goes to `ied_catch_all_address`
- `store` — unmatched mail is persisted to the local mailbox

When `ied_catch_all_mode` is `store`, `ied_catch_all_address` is not required
and is ignored. `prepare()` validates the mode value. A `store` catch-all is
the direct equivalent of the Mailgun wildcard route `.*@inbox.<domain>` — it
captures every recipient on the domain without pre-creating aliases.

**Precedence:** a `store` catch-all supersedes `ied_reject_unmatched`. When the
catch-all mode is `store`, every unmatched recipient is captured, so no message
on that domain is ever rejected or discarded for lack of a matching alias.
`ied_reject_unmatched` applies only when the catch-all mode is `forward` and no
`ied_catch_all_address` is set (the existing behaviour).

### 3. New message store — `data/inbound_email_message_class.php`

New `InboundEmailMessage` (`SystemBase`) + `MultiInboundEmailMessage`
(`SystemMultiBase`). Prefix `iem`, table `iem_inbound_email_messages`.

| Field | Type | Notes |
|-------|------|-------|
| `iem_inbound_email_message_id` | `int8` | serial PK |
| `iem_ied_inbound_email_domain_id` | `int4` | not null — receiving domain |
| `iem_iea_inbound_email_alias_id` | `int4` | nullable — null for catch-all stores |
| `iem_sender` | `varchar(500)` | From header / envelope sender |
| `iem_recipient` | `varchar(500)` | envelope recipient |
| `iem_subject` | `varchar(1000)` | RFC 2047-decoded, UTF-8 (see Body extraction) |
| `iem_body_plain` | `text` | best-effort decoded + UTF-8-converted text/plain part |
| `iem_body_html` | `text` | best-effort decoded + UTF-8-converted text/html part |
| `iem_raw_message` | `text` | full raw message as received |
| `iem_message_id_header` | `varchar(255)` | RFC 5322 `Message-ID` header; declares `'unique_with' => ['iem_recipient']` so the platform creates a UNIQUE constraint (and B-tree index) on `(iem_message_id_header, iem_recipient)` — that constraint **is** the dedup mechanism |
| `iem_dkim_result` | `varchar(10)` | `pass` / `fail` / `none`, recorded only |
| `iem_size_bytes` | `int4` | raw message size |
| `iem_received_time` | `timestamp(6)` | `default => now()` |
| `iem_create_time` | `timestamp(6)` | `default => now()` |
| `iem_delete_time` | `timestamp(6)` | soft delete |

`$foreign_key_actions`: cascade on `iem_ied_inbound_email_domain_id`; set null
on `iem_iea_inbound_email_alias_id` (a stored test message should outlive the
alias that captured it).

`MultiInboundEmailMessage::getMultiResults()` filter options: `domain_id`,
`alias_id`, `recipient` (substring match via `LIKE`), `sender` (substring
match), `message_id_header` (exact, for retry dedup), `received_since`
(timestamp, for the volume cap), `deleted`.

Add a static `InboundEmailMessage::CreateEntry(...)` helper mirroring
`InboundEmailLog::CreateEntry()` so the router persists a message in one call.

### 4. New log statuses — `data/inbound_email_log_class.php`

Add two constants:

- `const STATUS_STORED = 'stored';` — a successful local store. Store
  deliveries write an `iel` log row with this status so the existing Logs
  viewer shows them alongside forwards.
- `const STATUS_STORE_CAPPED = 'store_capped';` — a store delivery dropped
  because `inbound_email_mailbox_max_per_window` was reached. **Do not reuse
  `STATUS_RATE_LIMITED`** — it already means "forward-rate limit hit," and
  conflating the two would make the Logs viewer ambiguous about which limit
  fired.

**Logs viewer `domain_id` filter caveat.** `MultiInboundEmailLog`'s `domain_id`
filter resolves the domain via a subquery on the alias's
`iea_ied_inbound_email_domain_id`. Catch-all `store` deliveries have a null
alias, so they would be invisible to that filter. To keep the domain filter
accurate, add `iel_ied_inbound_email_domain_id` (`int4`, nullable) to the log
table, populate it on every transaction, and switch the `domain_id` filter to
match that column directly. This also makes the per-domain rate-limit query in
`InboundEmailRouter` a plain column match instead of a join.

## Router Logic Changes — `includes/InboundEmailRouter.php`

In `processEmail()`, after the alias is resolved (step 6, "Forward"):

1. Read `iea_delivery_mode`.
2. If mode is `store` or `forward_and_store`, call a new
   `storeMessage($raw_email, $parsed, $alias, $domain, $envelope_recipient)`.
3. If mode is `forward` or `forward_and_store`, run the existing
   `forwardEmail()` path.
4. **Forwarding rate limits** (`checkAliasRateLimit` / `checkDomainRateLimit`)
   and **DKIM rejection** gate the *forward* path only. A pure `store` delivery
   does not consume the per-alias/per-domain forwarding limits.
5. **Store volume cap.** Before a `store` delivery, check
   `inbound_email_mailbox_max_per_window`: count non-deleted `iem` rows for the
   receiving domain whose `iem_received_time` falls inside
   `inbound_email_forwarding_rate_limit_window`. If the count is at or above the
   cap, drop the message, log `STATUS_STORE_CAPPED`, and return `0` (accepted —
   a retry would not help). A cap of `0` disables the check.
6. DKIM verification still runs for stored mail; the result string is recorded
   in `iem_dkim_result` and never causes a rejection.
7. The 25 MB size cap still applies before storing.
8. Log status: `STATUS_STORED` for `store`, `STATUS_FORWARDED` for `forward`.
   For `forward_and_store`, log the forward outcome as today; the store half is
   best-effort (see Failure handling).

`storeMessage()` always persists `iem_raw_message` from the **original**
`$raw_email` as received — never the header-rewritten copy that `forwardEmail()`
builds for relay — so a `forward_and_store` delivery keeps a faithful original.

For the **catch-all** branch (alias not found): if
`ied_catch_all_mode === 'store'`, call `storeMessage()` with a null alias.
Otherwise keep the current `forwardToCatchAll()` / reject / discard behaviour.

### Failure handling and exit codes

The pipe script's exit code controls whether Postfix retries: `0` =
accepted-and-done, `75` = temporary failure → Postfix re-queues and retries.
A store that returns `0` after failing silently loses the message — the exact
flakiness this feature exists to remove. Therefore:

- **Pure `store` mode** — if the DB write fails (transient connection error,
  etc.), `processEmail()` returns `75` so Postfix retries later. The message is
  not lost.
- **`forward_and_store` mode** — the forward has already happened; returning
  `75` would re-run and double-forward. So here a store failure is best-effort:
  logged to `error_log` and `STATUS_ERROR`, but the message returns the
  forward's exit code and is not retried for the store's sake.

**Retry idempotency.** Because `store` mode can now be retried, the same
message can arrive twice (e.g. the row committed but the connection dropped
before Postfix saw success). The dedup is enforced at the DB layer by the
UNIQUE constraint on `(iem_message_id_header, iem_recipient)` declared on
`iem_message_id_header` via `unique_with` — see Data Model. `storeMessage()`
extracts the `Message-ID` header, sets it on the row, and attempts the
insert; if Postgres raises a unique-violation (SQLSTATE 23505),
`storeMessage()` treats that as "already stored, retry succeeded" and
returns success without re-inserting. Messages with no `Message-ID` header
are always inserted because Postgres treats NULLs as distinct in UNIQUE
constraints by default — multiple `(NULL, recipient)` rows do not conflict
(acceptable, as near-all real mail carries a `Message-ID`).

This replaces the prior soft SELECT-before-INSERT dedup: no extra read per
store, no race window between the check and the insert, and the index
required for the dedup is created for free by the constraint.

### Body extraction

`parseEmail()` currently returns a single undivided `body`. `storeMessage()`
needs separated, readable plain/html parts. Add an `extractBodies($raw_email,
$parsed)` helper that:

- handles `multipart/alternative` and `multipart/mixed`, decoding
  `quoted-printable` and `base64` parts;
- falls back to treating the whole body as plain or html based on the
  top-level `Content-Type`;
- **converts each part to UTF-8** from its declared `charset` (via `mb_convert_encoding`,
  defaulting to UTF-8 when the charset is absent or unrecognised), since the DB
  stores UTF-8;
- **decodes RFC 2047 encoded-words** (e.g. `=?UTF-8?B?...?=`) in the `Subject`
  (and in display names) via `mb_decode_mimeheader`, so `iem_subject` holds
  readable text rather than raw encoded tokens;
- always stores the untouched `iem_raw_message` so nothing is lost even when
  decoding is imperfect.

**Attachment scope (deliberate boundary).** Attachments are *not* extracted or
stored as separate downloadable entities — they remain inside `iem_raw_message`
only. The admin viewer offers a raw-message (`.eml`) download for cases that
need the attachment. Per-attachment extraction is a possible future extension
and is out of scope here; the test-capture use case does not need it.

## Admin UI

The Incoming admin uses Bootstrap `nav nav-tabs` (admin runs the Joinery
System theme, Bootstrap available). The current tab order is
**Setup → Forwarding Aliases → Domains → Logs**; the `adminMenu` URL in
`plugin.json` lands on the Setup tab. Every admin page in
`plugins/inbound_email/admin/` renders its own copy of the tab strip — they
all need the new entry added in the same position.

- **New "Mailbox" tab** — `admin/admin_inbound_email_mailbox.php` +
  `logic/admin_inbound_email_mailbox_logic.php`. Inserted **after Logs** so
  Setup remains the first tab (the recommended landing page). A paged table
  of stored messages: received time, recipient, sender, subject, size, DKIM
  result. Filter inputs for recipient and sender. Soft-delete action per row
  and a "purge all" action.
- **New message detail view** — `admin/admin_inbound_email_message.php`,
  reached as `/plugins/inbound_email/admin/admin_inbound_email_message?iem_inbound_email_message_id=N`
  (linked from the Mailbox table rows; no menu entry, resolved by plugin view
  auto-discovery). Shows headers, plain body, a "view raw" toggle, and a
  **download `.eml`** action that streams `iem_raw_message` with a
  `message/rfc822` content type. **The HTML body must not be rendered as live
  markup in the admin page** — render it inside a sandboxed `<iframe sandbox>`
  (no `allow-scripts`) or display the escaped source. Stored messages are fully
  attacker-controlled (see Security).
- **Alias edit form** (`admin/admin_inbound_email_alias.php` /
  `logic/admin_inbound_email_alias_logic.php`) — add a "Delivery mode" select
  (Forward / Store locally / Forward and store) via FormWriter. The
  destinations field is required and shown for the two forward modes and
  optional/hidden for `store`; server-side validation in `prepare()` is the
  source of truth regardless of the JS toggle.
- **Domain edit form** (`admin/admin_inbound_email_domains.php`) — add a
  "Catch-all mode" select (Forward to address / Store locally). The catch-all
  address field applies only to forward mode.

All new admin pages call `$session->check_permission(5)`.

### Setup tab — delivery-mode awareness — `includes/InboundEmailSetupCheck.php`

The guided setup engine (`InboundEmailSetupCheck`) and the add-an-address
wizard predate this feature and only know about forwarding targets. Two
adjustments are needed so a store-only deployment doesn't read as
"incomplete" (separate from the larger provider-architecture refactor in
"Setup tab — provider-aware" below):

- **`plugin.alias_or_catchall`** currently passes when the target address has
  an alias *or* the domain has a `ied_catch_all_address`. Extend it to also
  pass when (a) the matching alias's `iea_delivery_mode` is `store` or
  `forward_and_store`, or (b) the domain's `ied_catch_all_mode` is `store`.
  Without this change, a store-only domain reports a red required-item every
  time the Setup tab runs.
- **Add-an-address wizard.** The plugin-config step that offers to create the
  alias gains a delivery-mode select (Forward / Store locally / Forward and
  store), mirroring the alias edit form. When the admin picks `store`, the
  destinations input is hidden and `iea_destinations` is saved empty —
  consistent with the `prepare()` rules in §1.

## Settings

Add to `plugin.json` `settings`:

```json
{ "name": "inbound_email_mailbox_retention_days", "default": "14" },
{ "name": "inbound_email_mailbox_max_per_window", "default": "500" },
{ "name": "inbound_email_provider",               "default": "postfix" }
```

- `inbound_email_mailbox_retention_days` — age after which stored messages
  are purged. Drives the scheduled task below.
- `inbound_email_mailbox_max_per_window` — max messages stored per domain
  within `inbound_email_forwarding_rate_limit_window`; abuse cap for
  `store` deliveries (see Security). `0` disables the cap.
- `inbound_email_provider` — selects the active inbound provider. Default
  `postfix`. Allowed values are provider keys discovered by
  `InboundProviderRegistry`. See "Inbound Provider Architecture" above.

**Provider-supplied settings are not duplicated here.** A provider's
`getSettingsFields()` declares what it needs; the Mailgun provider returns
references to the platform's existing `mailgun_webhook_signing_key`,
`mailgun_api_key`, and `mailgun_eu_api_link` settings (already wired up
for outbound Mailgun). The plugin `settings_form.php` renders the active
provider's fields inline so the admin sees the relevant ones in context.

## Scheduled Task

New task `tasks/PurgeOldMailboxMessages.php` implementing
`ScheduledTaskInterface`, modelled on `tasks/PurgeOldInboundEmailLogs.php`. It
hard-deletes `iem_inbound_email_messages` rows older than
`inbound_email_mailbox_retention_days`. Register it the same way the log purge
task is registered (see `docs/scheduled_tasks.md`).

## Provisioning

No new provisioner key. The existing three (`inbound_mail_server`,
`outbound_forwarding_relay`, `domain_dns_records`) become **provider-aware**
— see "Provisioners — provider-aware" under the Inbound Provider
Architecture section above.

A `store`-only deployment doesn't need the outbound relay and may
legitimately see "Needs setup" on `outbound_forwarding_relay` — that is
fine. The provisioner remains provider-independent because forwarding
behavior is.

## Security Considerations

- **Stored bodies are fully untrusted.** A public MX means anyone on the
  internet can send mail to a `store` alias or catch-all domain. The admin
  message viewer must never execute or live-render stored HTML — sandboxed
  iframe or escaped source only. Apply the platform's untrusted-input handling
  (see `specs/implemented/joinery_ai_untrusted_input_markers.md`) to any path
  that surfaces stored content to an AI agent.
- **Disk-exhaustion abuse.** A `store` catch-all on a public domain lets
  strangers write rows. Mitigations: the 25 MB per-message cap, the
  `inbound_email_mailbox_max_per_window` per-domain cap (a `store` delivery
  that exceeds it is logged `STATUS_RATE_LIMITED` and dropped), and the
  retention purge task. Recommend keeping the store domain a dedicated
  subdomain with short retention.
- No signature check is needed on the local path — Postfix receiving over the
  MX is the trust boundary, replacing Mailgun's HMAC webhook signature.
- **Stored mail can contain live secrets.** A captured password-reset or
  email-verification message holds a working token. The mailbox is therefore
  permission-5 admin-only, and the short default retention
  (`inbound_email_mailbox_retention_days = 14`) limits the exposure window.
  Do not surface stored bodies on any non-admin page.

## Test Workflow After This Feature

1. In the plugin, add an inbound domain `inbox.dev.getjoinery.com`, enabled,
   **catch-all mode = store**.
2. Publish its MX record pointing at this host (not Mailgun) and the SPF
   record; confirm the DNS badges go green.
3. A test sends application mail to `testuser@inbox.dev.getjoinery.com`.
4. The test queries the store:
   `SELECT * FROM iem_inbound_email_messages WHERE iem_recipient LIKE '%testuser%' ORDER BY iem_received_time DESC LIMIT 1;`
5. Extract links / verify content from `iem_body_html` / `iem_body_plain`.
6. The purge task handles cleanup; no manual `DELETE` needed.

**Loopback note.** If the app's own outbound mail is relayed through the *same*
host's Postfix, and that host also lists the inbox domain as a virtual domain,
Postfix delivers the message straight into the pipe without it leaving the
host. That still produces a stored row, so tests pass — but `iem_dkim_result`
will read `none` because no external signing occurred. Tests must not assert on
`iem_dkim_result`; it is recorded for reference only.

## Inbound Provider Architecture

Inbound email is **provider-based**, and the platform already has an
outbound provider model (`EmailServiceProvider` in
`includes/email_providers/`). This spec adds a **second role interface**
(`InboundEmailProvider`) and lets provider classes implement either or
both — one class per service, however many roles it plays.

```
includes/email_providers/MailgunProvider.php
    implements EmailServiceProvider, InboundEmailProvider   <- one class, both roles, one settings declaration

includes/email_providers/SmtpProvider.php
    implements EmailServiceProvider                          <- outbound only

includes/email_providers/PostfixProvider.php  (new)
    implements InboundEmailProvider                          <- inbound only
```

One inbound provider is active at a time, feeding the same
`InboundEmailRouter`. One router, one store, many ways in.

```
                              +-> PostfixProvider ----------+
External MX -> inbound mail --|   (utils/inbound_email_handler.php)
                              |                             +-> InboundEmailRouter::processEmail()
                              +-> MailgunProvider ----------+      |
                                  (ajax/inbound_email_webhook ?provider=mailgun)
                                                                    +-> storeMessage()  -> iem_inbound_email_messages
                                                                    +-> forwardEmail()  -> alias destinations
```

**Adding inbound support to an existing outbound provider is one diff to
one class** — add `, InboundEmailProvider` to its `implements` clause and
add the interface's methods. **Adding a brand-new service is one file** in
`includes/email_providers/`. No router changes, no Setup-tab changes, no
webhook routing changes either way.

A single setting **`inbound_email_provider`** (default `postfix`) selects
the active provider. Values are provider keys discovered from the
`InboundProviderRegistry` (see below). The Setup tab, the provisioners,
and the add-an-address wizard all read this setting and ask the active
provider for the work that matters.

### The interface — `InboundEmailProvider`

Lives in core (`includes/InboundEmailProvider.php`) so any provider class
under `includes/email_providers/` can implement it, regardless of whether
the inbound plugin is installed. (The interface declaration is zero-cost
when unused.) `getKey()` and `getLabel()` overlap with
`EmailServiceProvider`'s identity methods — a class implementing both
interfaces declares each once and satisfies both.

```php
interface InboundEmailProvider {
    public static function getKey(): string;                  // 'postfix', 'mailgun', ...
    public static function getLabel(): string;                // 'Postfix (self-hosted)', 'Mailgun', ...

    // Inbound-only settings this provider needs. Combined providers
    // (Mailgun, etc.) already declare their full setting set in
    // EmailServiceProvider::getSettingsFields() — this method returns the
    // subset relevant to inbound (typically the webhook signing key), or
    // an empty array if everything inbound needs is already in the
    // outbound declaration. The Setup tab uses this to render
    // inbound-specific fields in context.
    public static function getInboundSettingsFields(): array;

    // Setup tab check catalogue for this provider, scoped to an optional
    // domain. Each entry uses the engine's existing result shape
    // (id, layer, label, severity, status, summary, detail, fix, recheckable).
    public static function getSetupChecks(?string $domain = null): array;

    // Copy-ready DNS records for a domain — MX, SPF, DKIM, DMARC. The
    // Setup tab renders these as cards in the add-an-address wizard.
    public static function getDnsRecords(string $domain): array;

    // Whether handleInbound() is invoked from an HTTP webhook (true) or a
    // local pipe / process (false). The generic webhook dispatcher only
    // accepts providers where this returns true.
    public static function isWebhook(): bool;

    // Transport-specific entry point. For webhook providers $post is the
    // form fields and $raw_body is the request body; for pipe providers
    // $post is empty and $raw_body is what was read from stdin.
    // Verify the request, extract raw MIME and envelope recipient, return
    // them. Return null on rejection (signature failure, malformed input).
    public function handleInbound(array $post, string $raw_body): ?array;
        // ?array{ 'raw_mime' => string, 'recipient' => string }
}
```

### Discovery — `InboundProviderRegistry`

`InboundProviderRegistry` discovers providers **by interface**, not by
directory: it walks `get_declared_classes()` and selects classes that
implement `InboundEmailProvider`. Classes are loaded by the same
mechanism `EmailSender` already uses to load
`includes/email_providers/*.php`, plus the plugin's optional
`includes/inbound_providers/` directory for inbound-only providers that
don't belong in core (none in the initial ship, but the hook is there
for future plugin-owned providers).

It exposes:

- `InboundProviderRegistry::all(): array` — discovered providers
- `InboundProviderRegistry::get(string $key): ?string` — class name by key
- `InboundProviderRegistry::active(): string` — class for the
  `inbound_email_provider` setting; falls back to `PostfixProvider` if
  the setting names something unknown

Because discovery is interface-based, the same `MailgunProvider` class
that `EmailSender::getProvider('mailgun')` returns for outbound is the
same class `InboundProviderRegistry::get('mailgun')` returns for inbound.
One class, one identity, two roles.

### Shipping providers

#### `PostfixProvider` — new, inbound-only

- **File:** `includes/email_providers/PostfixProvider.php`.
- **Implements:** `InboundEmailProvider` only. Postfix is not an
  outbound-API service from the app's perspective (the platform doesn't
  "send via Postfix" through an interface — it relays through configured
  SMTP).
- **Entry point:** `utils/inbound_email_handler.php` — the existing
  Postfix pipe script. It reads stdin and `$argv[1]` and delegates to
  `PostfixProvider::create()->handleInbound(['recipient' => $argv[1]], $stdin)`,
  then hands the returned raw MIME + recipient to the router.
- **`isWebhook()`** returns `false`.
- **Settings:** none provider-specific. Postfix is local.
- **Setup checks:** the Host / Mail-host / per-domain-this-host-DNS layers
  from `inbound_email_guided_setup.md` (Postfix running, transport in
  `master.cf`, `myhostname` set, mail-host A record, PTR / FCrDNS, MX
  points here, SPF authorizes this IP, local DKIM key matches published
  TXT).
- **DNS records:** MX → `<inbound_email_mail_hostname>`, SPF →
  `v=spf1 ip4:<public IP> -all`, DKIM → from local opendkim key, DMARC →
  recommended.

#### `MailgunProvider` — existing class, gains inbound

- **File:** `includes/email_providers/MailgunProvider.php` (already
  exists; this spec adds the inbound interface implementation to it).
- **Implements:** `EmailServiceProvider, InboundEmailProvider`. One class,
  both roles.
- **Entry point (inbound):** `ajax/inbound_email_webhook.php?provider=mailgun`.
- **`isWebhook()`** returns `true`.
- **`handleInbound()`** verifies Mailgun's HMAC signature (reads the
  existing `mailgun_webhook_signing_key` setting with `mailgun_api_key`
  fallback for legacy accounts — same settings the outbound side already
  uses), pulls the `body-mime` field as raw MIME and the `recipient`
  field as envelope recipient. Returns null on signature failure or
  missing fields.
- **Settings:** declared once in `MailgunProvider::getSettingsFields()`
  (already covers `mailgun_api_key`, `mailgun_domain`,
  `mailgun_eu_api_link`); adds `mailgun_webhook_signing_key` if not
  already in the declaration. `getInboundSettingsFields()` returns the
  inbound-relevant subset (`mailgun_webhook_signing_key`) for in-context
  rendering on the Setup tab.
- **Setup checks:** `mailgun.signing_key_set` and a per-domain DNS layer
  pointing at Mailgun's MX/SPF/DKIM values (region-aware via
  `mailgun_eu_api_link`).
- **DNS records:** MX → `mxa.mailgun.org` / `mxb.mailgun.org` (or EU
  equivalents), SPF → `v=spf1 include:mailgun.org -all`, DKIM → at
  Mailgun's selector for the domain, DMARC → recommended.
- **Route configuration** lives in the Mailgun dashboard and is proven by
  the e2e test only — no API-based route check.

### Generic webhook dispatcher

`ajax/inbound_email_webhook.php` is a thin router:

1. Read `?provider=<key>` from the query string.
2. Look up the class via `InboundProviderRegistry::get($key)`. Return 404
   if unknown.
3. Reject (403) if the key does not match `inbound_email_provider` —
   prevents an inactive provider's webhook from being abused while the
   site is configured for a different one.
4. Reject (404) if `$provider::isWebhook()` is false.
5. Call `$provider->handleInbound($_POST, file_get_contents('php://input'))`.
   If null, return 406.
6. Otherwise call
   `(new InboundEmailRouter())->processEmail($raw_mime, $recipient)` and
   map the return code to HTTP (`0` → 200, `75` → 503, `67` → 406).

The pipe entry point doesn't use this endpoint — it instantiates the
Postfix provider directly. But every webhook-style provider does, so
**adding a new HTTP-based provider requires no new endpoints**.

### Setup tab — provider-aware

The Setup tab asks the active provider for its check catalogue
(`getSetupChecks($domain)`) and renders the results in the existing
layered layout. The Plugin and E2E layers remain in core
`InboundEmailSetupCheck` because they apply to every provider; everything
else is provider-supplied.

The first step is **"Inbound provider"** — a select listing every
discovered provider's `getLabel()`. Switching is a single setting flip.

The add-an-address wizard's per-domain DNS step shows the active
provider's `getDnsRecords($domain)` as copy-ready cards, and (when
`isWebhook()` is true) appends the webhook URL and any copy-ready route
expression the provider supplies.

The e2e check (`e2e.test_message`) is provider-independent — it polls
`iel_inbound_email_logs` for the test message. For HTTP-based providers
this doubles as the route-configured proof.

### Provisioners — provider-aware

`InboundEmailHealth` consults the active provider via the registry:

| Provisioner key | Behavior |
|-----------------|----------|
| `inbound_mail_server` | Calls a provider hook. `PostfixProvider` returns the Postfix-on-127.0.0.1:25 check; HTTP-based providers return informational "not applicable — `<label>` selected" |
| `outbound_forwarding_relay` | Unchanged. Provider-independent (forwarding always relays via the configured SMTP relay regardless of inbound provider) |
| `domain_dns_records` | Calls the active provider's `getDnsRecords()` for expected values; compares against actual DNS via `DnsResolver` |

A store-only domain on the Postfix provider has no DKIM key (nothing is
signed or sent from it); its DKIM badge stays neutral/absent, which is
not an error. On the Mailgun provider DKIM is signed by Mailgun and the
badge reflects whether Mailgun's TXT record is published.

`install_email.sh` is only relevant to the Postfix provider; it is never
surfaced as a fix command when another provider is active.

### Cutover

This is one change, not a separate follow-up:

- **Add** `includes/InboundEmailProvider.php` interface (in core, so any
  provider class can implement it).
- **Add** `plugins/inbound_email/includes/InboundProviderRegistry.php`
  (lives in the plugin — the registry only makes sense when the plugin
  is active).
- **Add** `includes/email_providers/PostfixProvider.php` — implements
  `InboundEmailProvider` only.
- **Modify** `includes/email_providers/MailgunProvider.php` — add
  `, InboundEmailProvider` to its `implements` clause and add the
  interface's methods. Leave the existing outbound `getSettingsFields()`
  unchanged; declare `mailgun_webhook_signing_key` in the new
  `getInboundSettingsFields()` only so the field surfaces in the Setup
  tab but not in the outbound settings UI on installs that never enable
  inbound.
- **Add** `inbound_email_provider` setting (default `postfix`). No new
  Mailgun secrets — `MailgunProvider` already owns its setting
  declaration.
- **Refactor** `utils/inbound_email_handler.php` to delegate to
  `PostfixProvider::create()->handleInbound(...)`, then call the router.
- **Add** `ajax/inbound_email_webhook.php` as the generic dispatcher.
- **Delete** `ajax/mailgun_inbound_webhook.php`. Its logic moves into
  `MailgunProvider::handleInbound()`; its URL is replaced by the generic
  dispatcher.
- **Reconfigure** the test site's Mailgun route to point at
  `ajax/inbound_email_webhook?provider=mailgun` and to deliver raw MIME
  (`body-mime`) — the current `forward()` route delivers parsed fields
  and must change before the new code ships.
- **Delete** `data/inbound_email_class.php` and drop the
  `iem_inbound_emails` table. Existing rows are test artefacts and are
  **not** migrated.
- **Refactor** `InboundEmailHealth` and `InboundEmailSetupCheck` to
  dispatch per-provider via the registry. Provider-specific check logic
  moves out of these classes and into the provider implementations.
- **Update** the inbound-email testing instructions in `CLAUDE.md` to
  query `iem_inbound_email_messages` regardless of which provider
  delivered the message.
- `specs/implemented/inbound_email_testing.md` is a frozen historical
  record and must not be edited; this spec supersedes it. When this spec
  is implemented and moved to `specs/implemented/`, note the supersession
  in its Overview.

All providers must ship together so that switching `inbound_email_provider`
is a single setting flip with no UI lying about its state.

## Testing

In addition to the end-to-end Test Workflow above, the feature itself needs
coverage before it can be trusted as test infrastructure:

- **Model tests** (`tests/models/inbound_email_message_test.php`) — CRUD on
  `InboundEmailMessage`, the `MultiInboundEmailMessage` filters, and the
  soft-delete path, following the patterns in `tests/models/`.
- **Dedup** — storing two messages with the same `Message-ID` + recipient
  yields exactly one row (the second insert raises SQLSTATE 23505, which
  `storeMessage()` swallows as "already stored"); differing recipients yield
  two; a missing `Message-ID` always inserts (multiple `(NULL, recipient)`
  rows coexist because Postgres treats NULLs as distinct in UNIQUE
  constraints).
- **`extractBodies()`** — unit coverage for: `multipart/alternative` (plain +
  html both captured), `quoted-printable` and `base64` decoding, a non-UTF-8
  charset converted correctly, and an RFC 2047 encoded-word `Subject` decoded
  to readable text.
- **Delivery-mode branching** — feed `InboundEmailRouter::processEmail()` a raw
  message for each of `forward`, `store`, `forward_and_store`, and a `store`
  catch-all, asserting the right combination of `iem` row, `iel` status, and
  (for forward modes) outbound relay.
- **Failure handling** — simulate a DB write failure in pure `store` mode and
  assert exit code `75`; in `forward_and_store` assert the forward's exit code
  is preserved and a store failure does not trigger a retry.
- **Volume cap** — once `inbound_email_mailbox_max_per_window` is reached, the
  next `store` delivery is dropped with `STATUS_STORE_CAPPED`.
- **Provider registry** — `InboundProviderRegistry::all()` returns both
  shipping providers (discovered by interface, not by file path);
  `::get('mailgun')` and `::get('postfix')` resolve to the correct
  classes; an unknown key returns null; `::active()` falls back to
  `PostfixProvider` when `inbound_email_provider` names something unknown.
- **Combined-role class** — `MailgunProvider` satisfies both
  `EmailServiceProvider` and `InboundEmailProvider`:
  `EmailSender::getProvider('mailgun')` and
  `InboundProviderRegistry::get('mailgun')` return the same class. Calling
  `send()` and `handleInbound()` on it both work.
- **Outbound regression** — the existing `MailgunProvider` outbound tests
  continue to pass after the inbound interface and methods are added.
  `getSettingsFields()` returns the same three keys it returns today
  (`mailgun_api_key`, `mailgun_domain`, `mailgun_eu_api_link`); the
  signing key appears only via `getInboundSettingsFields()`.
- **Mailgun inbound** — `MailgunProvider::handleInbound()` against a
  fixture POST: valid signature + `body-mime` + recipient returns the raw
  MIME and recipient; missing/invalid signature returns null; missing
  `body-mime` returns null; downstream the router writes the same `iem`
  row, `iel` status, and triggers the same delivery-mode logic as the
  equivalent Postfix-pipe invocation.
- **Postfix inbound** — `PostfixProvider::handleInbound()` returns raw
  MIME and recipient from a stdin payload + `recipient` key.
- **Generic dispatcher** — `ajax/inbound_email_webhook.php` returns 404 for
  an unknown provider, 403 when the provider does not match
  `inbound_email_provider`, and 406 when `handleInbound()` returns null.
- **Provider-aware setup** — with `inbound_email_provider = mailgun`,
  `InboundEmailSetupCheck::run()` includes the `mailgun.*` catalogue from
  `MailgunProvider::getSetupChecks()` and omits Postfix's Host / Mail-host
  layers; with `postfix`, the inverse. The Plugin and E2E layers appear
  under both providers.

Run `php -l` and `validate_php_file.php` on every created/modified PHP file.

## Files

### To create
| File | Purpose |
|------|---------|
| `plugins/inbound_email/data/inbound_email_message_class.php` | `InboundEmailMessage` + `MultiInboundEmailMessage` |
| `includes/InboundEmailProvider.php` | Inbound role interface (core, so providers in `includes/email_providers/` can implement it) |
| `plugins/inbound_email/includes/InboundProviderRegistry.php` | Interface-based discovery + lookup |
| `includes/email_providers/PostfixProvider.php` | Inbound-only provider: pipe entry, this-host setup checks, this-host DNS records |
| `ajax/inbound_email_webhook.php` | Generic webhook dispatcher (`?provider=<key>`) |
| `plugins/inbound_email/admin/admin_inbound_email_mailbox.php` | Mailbox list page |
| `plugins/inbound_email/logic/admin_inbound_email_mailbox_logic.php` | Mailbox list logic |
| `plugins/inbound_email/admin/admin_inbound_email_message.php` | Single-message detail view + `.eml` download |
| `plugins/inbound_email/logic/admin_inbound_email_message_logic.php` | Detail-view load, delete, and `.eml` download actions |
| `plugins/inbound_email/tasks/PurgeOldMailboxMessages.php` | Retention purge scheduled task |
| `tests/models/inbound_email_message_test.php` | Model CRUD + dedup test (see Testing) |
| `tests/integration/inbound_provider_test.php` | Registry interface-based discovery; both providers' `handleInbound()` against fixture payloads |

### To modify
| File | Change |
|------|--------|
| `plugins/inbound_email/data/inbound_email_alias_class.php` | `iea_delivery_mode` field; destinations now nullable; mode-aware `prepare()` validation; bump `@version` |
| `plugins/inbound_email/data/inbound_email_domain_class.php` | `ied_catch_all_mode` field; mode-aware `prepare()`; bump `@version` |
| `plugins/inbound_email/data/inbound_email_log_class.php` | `STATUS_STORED` + `STATUS_STORE_CAPPED` constants; `iel_ied_inbound_email_domain_id` column; `domain_id` filter and `CreateEntry()` updated to populate/use it; bump `@version` |
| `plugins/inbound_email/includes/InboundEmailRouter.php` | `storeMessage()`, `extractBodies()`, delivery-mode + catch-all-mode branching; bump `@version` |
| `plugins/inbound_email/admin/admin_inbound_email.php`, `admin_inbound_email_setup.php`, `admin_inbound_email_domains.php`, `admin_inbound_email_logs.php`, and any new mailbox/message pages | Add "Mailbox" entry to the nav-tabs strip (each page renders its own copy) |
| `plugins/inbound_email/admin/admin_inbound_email_alias.php` + logic | Delivery-mode select |
| `plugins/inbound_email/admin/admin_inbound_email_domains.php` + logic | Catch-all-mode select |
| `plugins/inbound_email/admin/admin_inbound_email_setup.php` + logic | Add-an-address wizard delivery-mode select; provider-picker first step; renders the active provider's `getDnsRecords()` and (for webhook providers) webhook URL + route expression; pass mode through to alias creation |
| `plugins/inbound_email/includes/InboundEmailSetupCheck.php` | `plugin.alias_or_catchall` recognises `store` / `forward_and_store` aliases and `store` catch-all domains; **refactored** to delegate Host / Mail-host / per-domain DNS layers to the active provider's `getSetupChecks()` via the registry; Plugin and E2E layers stay in core; bump `@version` |
| `plugins/inbound_email/includes/InboundEmailHealth.php` | All provisioner check methods consult the active provider via `InboundProviderRegistry::active()`; Postfix-specific logic moves into `PostfixProvider`; bump `@version` |
| `utils/inbound_email_handler.php` | Delegate to `PostfixProvider::create()->handleInbound(...)` instead of doing all parsing inline; bump `@version` |
| `includes/email_providers/MailgunProvider.php` | Add `, InboundEmailProvider` to its `implements` clause; add `handleInbound()`, `getSetupChecks()`, `getDnsRecords()`, `isWebhook()`, `getInboundSettingsFields()`. Outbound `getSettingsFields()` is left untouched — the inbound-only signing key lives in `getInboundSettingsFields()` |
| `plugins/inbound_email/plugin.json` | New settings; register scheduled task; bump `version` |
| `plugins/inbound_email/settings_form.php` | Surface the new local-mailbox settings; render the active provider's `getInboundSettingsFields()` inline |

### To delete
| File / table | Reason |
|---|---|
| `data/inbound_email_class.php` | Mailgun's old parallel storage path — superseded by `InboundEmailRouter` + `iem_inbound_email_messages` |
| `ajax/mailgun_inbound_webhook.php` | Replaced by the generic `ajax/inbound_email_webhook.php` dispatcher; Mailgun-specific logic moves into `MailgunProvider::handleInbound()` |
| Database table `iem_inbound_emails` | Existing rows are test artefacts; not migrated. Drop requires explicit user confirmation per repo DB rules |

### Schema
Schema changes are applied by **"Sync with Filesystem"** on the admin Plugins
page (or `update_database`) once the data classes are updated — no migration.
The drop of `iem_inbound_emails` is a manual `DROP TABLE` after cutover.

## Documentation

Update `plugins/inbound_email/docs/overview.md` (the existing plugin doc):

- Add a "Delivery modes" section explaining Forward / Store locally / Forward
  and store, and the domain catch-all `store` mode.
- Add a "Local mailbox" section: the admin Mailbox tab, retention setting, the
  store-volume abuse cap, and the test workflow.
- Note that store-only hosts do not need the outbound relay provisioner.
- Add an **"Inbound providers"** section: the provider model and how it
  composes with outbound's `EmailServiceProvider` (combined providers like
  Mailgun implement both interfaces on one class); the
  `inbound_email_provider` setting; the two shipping providers
  (`PostfixProvider`, `MailgunProvider`) with when to pick each; the
  Mailgun account setup (signing key, route expression, webhook URL);
  how to add a new combined provider (extend an existing
  `includes/email_providers/` class) or a new inbound-only one (drop a
  file in `includes/email_providers/`).
- Update `docs/email_system.md` to cross-reference the dual-role provider
  model alongside the existing outbound notes.

## Versioning

- `plugin.json`: bump the **minor** version (new feature, backward compatible —
  existing aliases default to `forward`). The rename migration
  (`inbound_email_rename.md`) establishes the post-rename version baseline;
  this feature is the next minor release on top of it.
- Bump `@version` in each modified data/include file.
