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
receiving role, removes an external dependency, and survives the dev domain
move to `dev.getjoinery.com` for free (inbound domains are read live from the
DB).

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
| `iem_message_id_header` | `varchar(255)` | RFC 5322 `Message-ID` header; retry-dedup key |
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

### 4. New log status — `data/inbound_email_log_class.php`

Add `const STATUS_STORED = 'stored';`. Store deliveries write an `iel` log row
with this status so the existing Logs viewer shows them alongside forwards.

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
   cap, drop the message, log `STATUS_RATE_LIMITED`, and return `0` (accepted —
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
before Postfix saw success). Before inserting, `storeMessage()` extracts the
`Message-ID` header and, when present, skips the insert if a non-deleted `iem`
row already exists with the same `iem_message_id_header` **and**
`iem_recipient`. Messages with no `Message-ID` header are always inserted (no
dedup key available — acceptable, as near-all real mail carries one).

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

The Incoming admin (`admin/admin_inbound_email.php`) uses Bootstrap
`nav nav-tabs` (admin runs the Joinery System theme, Bootstrap available).

- **New "Mailbox" tab** — `admin/admin_inbound_email_mailbox.php` +
  `logic/admin_inbound_email_mailbox_logic.php`. A paged table of stored
  messages: received time, recipient, sender, subject, size, DKIM result.
  Filter inputs for recipient and sender. Soft-delete action per row and a
  "purge all" action.
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

## Settings

Add to `plugin.json` `settings`:

```json
{ "name": "inbound_email_mailbox_retention_days", "default": "14" },
{ "name": "inbound_email_mailbox_max_per_window", "default": "500" }
```

- `inbound_email_mailbox_retention_days` — age after which stored messages are
  purged. Drives the scheduled task below.
- `inbound_email_mailbox_max_per_window` — max messages stored per domain
  within `inbound_email_forwarding_rate_limit_window`; abuse cap for `store`
  deliveries (see Security). `0` disables the cap.

Both appear under **Admin > Settings > Email** / the plugin `settings_form.php`.

## Scheduled Task

New task `tasks/PurgeOldMailboxMessages.php` implementing
`ScheduledTaskInterface`, modelled on `tasks/PurgeOldInboundEmailLogs.php`. It
hard-deletes `iem_inbound_email_messages` rows older than
`inbound_email_mailbox_retention_days`. Register it the same way the log purge
task is registered (see `docs/scheduled_tasks.md`).

## Provisioning

No new provisioner. A `store`-only deployment needs the existing
`inbound_mail_server` (Postfix) and `domain_dns_records` (MX/SPF) provisioners
but does **not** need `outbound_forwarding_relay`. Note in the docs that the
outbound-relay provisioner may legitimately read "Needs setup" on a host that
only uses local mailbox delivery.

A store-only domain also has no DKIM key (nothing is signed or sent from it).
The domain edit page's DKIM badge is expected to stay neutral/absent for such a
domain; this is not an error and needs no action.

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

## Retiring the Mailgun Inbound Pathway (follow-up, sequenced after cutover)

Once local mailbox delivery is verified end-to-end, the Mailgun inbound testing
feature is superseded. As a **separate follow-up step**:

- Remove `ajax/mailgun_inbound_webhook.php` and `data/inbound_email_class.php`;
  drop the `iem_inbound_emails` table. Existing rows are test artefacts and are
  **not** migrated.
- In the Mailgun dashboard, delete the `inbox.*` receiving domain and its
  inbound route.
- Update the inbound-email testing instructions in `CLAUDE.md` (and `GEMINI.md`)
  to point at `iem_inbound_email_messages`.
- `specs/implemented/inbound_email_testing.md` is a frozen historical record
  and must not be edited; this spec supersedes it. When this spec is
  implemented and moved to `specs/implemented/`, note the supersession in its
  Overview.

Do not delete the Mailgun pathway in the same change that adds local delivery —
keep both live until the new path is proven, then cut over.

**Prefix coexistence.** The Mailgun table `iem_inbound_emails` and this
feature's new `iem_inbound_email_messages` are **distinct tables** and coexist
without conflict during the overlap window — the shared three-letter model
prefix is cosmetic, not a database-level collision. Retirement therefore stays
a clean follow-up of *this* feature; it is not coupled to the rename
(consistent with `inbound_email_rename.md` §15).

## Testing

In addition to the end-to-end Test Workflow above, the feature itself needs
coverage before it can be trusted as test infrastructure:

- **Model tests** (`tests/models/inbound_email_message_test.php`) — CRUD on
  `InboundEmailMessage`, the `MultiInboundEmailMessage` filters, and the
  soft-delete path, following the patterns in `tests/models/`.
- **Dedup** — storing two messages with the same `Message-ID` + recipient
  yields exactly one row; differing recipients yield two; a missing
  `Message-ID` always inserts.
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
  next `store` delivery is dropped with `STATUS_RATE_LIMITED`.

Run `php -l` and `validate_php_file.php` on every created/modified PHP file.

## Files

### To create
| File | Purpose |
|------|---------|
| `plugins/inbound_email/data/inbound_email_message_class.php` | `InboundEmailMessage` + `MultiInboundEmailMessage` |
| `plugins/inbound_email/admin/admin_inbound_email_mailbox.php` | Mailbox list page |
| `plugins/inbound_email/logic/admin_inbound_email_mailbox_logic.php` | Mailbox list logic |
| `plugins/inbound_email/admin/admin_inbound_email_message.php` | Single-message detail view + `.eml` download |
| `plugins/inbound_email/logic/admin_inbound_email_message_logic.php` | Detail-view load, delete, and `.eml` download actions |
| `plugins/inbound_email/tasks/PurgeOldMailboxMessages.php` | Retention purge scheduled task |
| `tests/models/inbound_email_message_test.php` | Model CRUD + dedup test (see Testing) |

### To modify
| File | Change |
|------|--------|
| `plugins/inbound_email/data/inbound_email_alias_class.php` | `iea_delivery_mode` field; destinations now nullable; mode-aware `prepare()` validation; bump `@version` |
| `plugins/inbound_email/data/inbound_email_domain_class.php` | `ied_catch_all_mode` field; mode-aware `prepare()`; bump `@version` |
| `plugins/inbound_email/data/inbound_email_log_class.php` | `STATUS_STORED` constant; `iel_ied_inbound_email_domain_id` column; `domain_id` filter and `CreateEntry()` updated to populate/use it; bump `@version` |
| `plugins/inbound_email/includes/InboundEmailRouter.php` | `storeMessage()`, `extractBodies()`, delivery-mode + catch-all-mode branching; bump `@version` |
| `plugins/inbound_email/admin/admin_inbound_email.php` | "Mailbox" nav tab |
| `plugins/inbound_email/admin/admin_inbound_email_alias.php` + logic | Delivery-mode select |
| `plugins/inbound_email/admin/admin_inbound_email_domains.php` + logic | Catch-all-mode select |
| `plugins/inbound_email/plugin.json` | New settings; register scheduled task; bump `version` |
| `plugins/inbound_email/settings_form.php` | Surface the two new settings |

### Schema
Schema changes are applied by **"Sync with Filesystem"** on the admin Plugins
page (or `update_database`) once the data classes are updated — no migration.

## Documentation

Update `plugins/inbound_email/docs/overview.md` (the existing plugin doc):

- Add a "Delivery modes" section explaining Forward / Store locally / Forward
  and store, and the domain catch-all `store` mode.
- Add a "Local mailbox" section: the admin Mailbox tab, retention setting, the
  store-volume abuse cap, and the test workflow.
- Note that store-only hosts do not need the outbound relay provisioner.

## Versioning

- `plugin.json`: bump the **minor** version (new feature, backward compatible —
  existing aliases default to `forward`). The rename migration
  (`inbound_email_rename.md`) establishes the post-rename version baseline;
  this feature is the next minor release on top of it.
- Bump `@version` in each modified data/include file.
