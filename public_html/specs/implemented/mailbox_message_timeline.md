# Mailbox Message Timeline — "Show logs" on the ⋮ menu

**Status: IMPLEMENTED 2026-09-16** — built, tested (message_timeline 46/46, send_report 24/24), live-proven on dev through Mailgun. Deferred items are listed under Open items.

## Goal

A person reading a message in the mailbox can open the ⋮ menu, choose **Show logs**, and see one timeline of everything the platform knows happened to that message: where it came from, when each hop happened, what the authentication and spam checks said, how it was routed, and — for mail they sent — whether the carrier accepted it and, where the carrier can tell us, whether it was delivered or bounced.

The panel never guesses. Every line is backed by a stored fact or a live answer from the carrier, and the panel says plainly when a question has no answer ("Gmail handled delivery — no status is available"). The failure this spec is designed against is the confident-sounding line nobody can stand behind.

Two defects found while inventorying the sources are fixed here because the timeline depends on them (B1, B2 below).

## What the user sees

The ⋮ menu on a message gains **Show logs** under **Show original**. It opens the same modal shell "Show original" uses, with a vertical timeline. Times are the viewer's local time (the reader's existing timestamp ladder), newest at the bottom.

An inbound message on a hosted alias:

```
Message timeline

14:02:09  Left the sender's server
          mail-sor-f41.google.com, on behalf of alice@example.com
14:02:11  Arrived at mx.dev.getjoinery.com
          over SMTP (Postfix), 48 KB
14:02:11  Authentication: SPF pass · DKIM pass (example.com) · DMARC pass
          verified by this server
14:02:11  Spam check: not spam (score 0.4)
14:02:11  Routed to jeremy@dev.getjoinery.com — stored
14:02:12  Sealed into your vault
14:05:40  Opened
16:20:03  Labelled "Receipts" by filter "Amazon receipts"
```

A reply the user sent through Mailgun:

```
15:10:02  Sent as jeremy@dev.getjoinery.com
          to bob@gmail.com · via Mailgun · Message-ID <…@dev.getjoinery.com>
15:10:02  Mailgun accepted the message
15:10:04  Delivered to gmail.com
          "250 2.0.0 OK" (from Mailgun, checked 3 minutes ago)   [Check again]
```

A reply the user sent through their connected Gmail account:

```
15:10:02  Sent as jeremy@gmail.com via Gmail (your connected account)
          Gmail handled delivery — no delivery status is available here.
15:10:03  Copy filed in Sent
```

A reply that failed:

```
15:09:41  Send attempt failed
          via Mailgun — "Domain dev.getjoinery.com is not allowed to send: not verified"
15:10:02  Sent as jeremy@dev.getjoinery.com …
```

A message sent over Joinery Direct:

```
15:10:02  Delivered to bob@otherinstance.example over Joinery Direct
          the receiving instance confirmed receipt
```

Lines whose fact is missing are omitted, not shown blank. A message with only one knowable fact shows one line.

## Event inventory

Every event the timeline can show, where the fact lives today, and what this spec has to add. "Row" means the message's own `iem_inbound_email_messages` row.

### Inbound

| Event | Source | Today |
|---|---|---|
| Left the sender's server | First `Received:` hop in `iem_raw_headers` (sealed on a protected mailbox; needs an open window, same as Show original) | ✅ |
| Each intermediate hop | Remaining `Received:` hops, in order | ✅ |
| Arrived here | `iem_received_time`; transport from the last `Received:` hop + `iem_transport` (`joinery_direct` when Direct) | ✅ |
| Fetched from a connected account | `iem_iia_inbound_imap_account_id`, `iem_imap_folder`, `iem_create_time` (fetch time vs `iem_received_time` = how long it sat upstream) | ✅ |
| Authentication results | `iem_spf_result`, `iem_dkim_result`, `iem_dmarc_result`, `iem_auth_source` (who verified: this server, the upstream provider, or nobody) | ✅ |
| Spam check | `iem_spam_verdict`, `iem_spam_score`; `iem_learned_verdict` when the user overrode it | ✅ |
| AI safety scan | `iem_ai_danger_score`, `iem_ai_scan_time` | ✅ |
| Routed to alias — outcome | `iel_inbound_email_logs` row: status (`stored`, `forwarded`, `spam_held`, `rejected`, `store_capped`, `filtered`, `report_filed`, `error`…), destinations, error text | ⚠️ the log row does not reference the message (A1) |
| Forwarded onward | Same `iel_` row, status `forwarded`/`bounce_forwarded`, `iel_destinations` | ⚠️ A1 |
| Sealed into the vault | `iem_content_sealed`; no timestamp — shown as a state line without a time | ✅ |
| Opened | `iem_read_time` | ✅ |
| Labelled / archived / starred / trashed by filter or by hand | `ilm_inbound_label_members` present-local flags, `iem_is_archived`, `iem_is_starred`, `iem_delete_time`; the reader records `iem_local_state_modified` for the most recent change only | ⚠️ only the latest change carries a time; filter attribution is not stored (A5, deferred) |

### Outbound (sent from the reader)

| Event | Source | Today |
|---|---|---|
| Send attempted / failed | Nothing. A failed send throws and leaves no record (B2) | ❌ A2 |
| Sent — handed to the carrier | The outbound row exists only if the carrier accepted the message (`MailboxSender` stores it after `EmailSender::send()` returned true) | ✅ existence; ⚠️ which carrier is not stored (A2) |
| Carrier's acceptance receipt (queue id / provider message id) | Not captured. Mailgun returns an id; SMTP `250 … queued as XYZ` is in PHPMailer's last reply | ❌ A3 |
| Delivered / bounced / deferred at the recipient's server | Not held anywhere. Mailgun's Events API answers by Message-ID; we set our own `Message-Id` header on every send, so the lookup key exists | ❌ A4 |
| Delivered over Joinery Direct | `EmailSender` knows which recipients Direct delivered; `MailboxSender` does not record it on the row | ⚠️ A2 |
| Copy filed in Sent | `appendSentCopy()` outcome is logged to error_log only; later reconciled by the Sent ingest onto the same row (`iem_imap_uid` set) | ⚠️ the APPEND result is recorded by A2; the later reconcile is visible as `iem_imap_uid` present |

### Not available, and said so

- **Self-hosted Postfix delivery status.** Postfix writes it to `/var/log/mail.log`, which is `syslog:adm 0640` — the web server cannot read it and must not be given a way to. The timeline shows "Accepted by this server's Postfix (queue id XYZ)" from the SMTP receipt (A3) and states that delivery status is not available. Reading the queue's fate through the node agent is a follow-on, not this spec.
- **Connected-account (Gmail / M365 / Yahoo…) delivery status.** The user's own provider delivered it; there is no API into that. Said plainly.
- **Opened by the recipient.** Never. No tracking pixels.
- **Core email debug log** (`del_debug_email_logs`). Fixed by B1 but not a timeline source: it is off by default, keyed to no message, and describes the transactional pipeline rather than a mailbox message.
- **DMARC / TLS-RPT / ARF deliverability reports.** Per-domain-per-day aggregates, never per message. Not shown.

## Design

### A1 — the routing log points at its message

`InboundEmailLog` gains `iel_iem_inbound_email_message_id` (int8, nullable, index). `InboundEmailRouter::logTransaction()` takes the stored message id where one exists (statuses `stored`, `spam_held`, `store_capped`, `filtered`, `forwarded` when the message was also stored) and writes it. Rejections and discards have no message and stay NULL.

`logTransaction()` also passes the decoded subject instead of `''` — the log has had an always-empty subject column since it was written.

Existing rows stay unlinked and are not backfilled (the log carries no key that would make the match certain). A message with no linked routing row simply shows no routing line.

### A2 — every reader send writes an attempt record

New model `MailboxSendAttempt`, table `mst_mailbox_send_attempts`:

| Column | Meaning |
|---|---|
| `mst_mailbox_send_attempt_id` | serial |
| `mst_kind` | `compose` (sent from the reader), `forward` (the router relayed an inbound message to the alias's destinations, or a filter forwarded it onward) |
| `mst_iea_inbound_email_alias_id` | the alias sent as |
| `mst_usr_user_id` | who pressed send; NULL for a `forward` the router did on its own |
| `mst_iem_inbound_email_message_id` | the outbound row on success; the draft row when the send came from a saved draft (success or failure); NULL for an ad-hoc compose that failed and for every `forward` (a forward stores no new row) |
| `mst_source_iem_inbound_email_message_id` | the message being replied to / forwarded, when any |
| `mst_message_id_header` | the `Message-Id` on the wire — ours for a compose, the original sender's for a forward (the raw message relays unchanged). The key every later lookup uses |
| `mst_from_address`, `mst_recipients` | From, and the To/Cc/Bcc list as JSON (`[{email,name,kind}]`) |
| `mst_transport` | `mailgun`, `smtp`, `postfix`, `ses`…, `connected_account`, `joinery_direct` |
| `mst_transport_label` | human label, e.g. "Gmail (your connected account)", "Mailgun" |
| `mst_outcome` | `sent`, `failed`, `partial` (Direct delivered some recipients, the carrier took the rest) |
| `mst_error` | the transport's error text on failure |
| `mst_receipt` | JSON: what the carrier said on acceptance (A3) |
| `mst_direct_delivered` | JSON list of recipients Direct delivered |
| `mst_sent_copy_filed` | `provider` (SMTP filed Sent itself), `appended`, `append_failed`, `not_applicable` |
| `mst_delivery_status` | `unknown`, `accepted`, `delivered`, `failed`, `deferred`, `unavailable` (A4) |
| `mst_delivery_detail` | JSON: the carrier's events, verbatim but trimmed (A4) |
| `mst_delivery_checked_time` | when A4 last asked |
| `mst_create_time` | the attempt time |

Two writers, one model:

- `MailboxSender::send()` writes one `compose` row per attempt: before calling `EmailSender::send()` nothing is written (a row for an attempt that never reached a transport is noise); on return or exception, one row with the outcome. This is what closes B2: a failed send now leaves a record on the draft it came from and on the message it was replying to, which is exactly where the person will look.
- `InboundEmailRouter` writes one `forward` row after each relay of a stored message (the alias forward in the main flow, and a filter's "Forward to"), from the per-destination results `relay()` returns: outcome `sent`, `failed`, or `partial` (some destinations failed), the failed destinations named in `mst_error`, the transport that carried it in `mst_transport` (`relay()` remembers which of the provider relay or the SMTP fallback did). Forwarding does not pass through `EmailSender::send()`, so it does not use `lastSendReport()` (A3); the facts it needs are already in hand where it runs. A forward of a message that has no stored row (a pure-forward alias, a catch-all forward, an SRS bounce) writes nothing: no timeline could show it.

**The record never costs a message.** Both writers run after the send decision is made, inside their own try/catch: a failure to write the attempt row is `error_log`ged and swallowed. The worst case of this feature is a missing timeline line — never a lost, duplicated, or delayed email.

A forwarded message's timeline therefore shows "Forwarded to bob@gmail.com via Mailgun — accepted" and, because the forwarded copy carries the original Message-ID, the A4 delivery lookup answers for it the same as for a compose.

On a **protected mailbox** the recipients list and error text can name correspondents. `mst_recipients` and `mst_error` join the row's sealed fields when the alias's seal target says the outbound row is sealed (`MailboxSender::sealTargetFor()`), under the outbound row's DEK when there is one and the alias's vault otherwise. A locked window shows the attempt line with time, transport and outcome, and "recipients hidden — unlock to show". Message-ID and transport are not sealed; they carry no content.

### A3 — `EmailSender` reports what happened on the send

`EmailSender::send()` today returns a bool and tells the caller nothing else. It gains `lastSendReport(): array`, reset at the top of each `send()` and filled as the send proceeds:

```php
[
  'direct_delivered' => ['bob@x.example'],     // recipients Joinery Direct took
  'transport'        => 'mailgun',              // provider key that took the rest, or null
  'receipt'          => ['id' => '<…>', 'response' => '250 2.0.0 Ok: queued as 4c…'],
  'error'            => null,                    // transport error text on failure
]
```

Providers that can say what the carrier answered implement a small optional interface in `EmailServiceProvider.php`:

```php
interface SendReceiptSource {
    /** What the carrier said when it accepted the last send(); null when nothing was captured. */
    public function lastSendReceipt(): ?array;   // ['id' => ?string, 'response' => ?string]
}
```

- `MailgunProvider`: the `id` and `message` fields of the send response.
- `SmtpProvider` (covers connected accounts and self-hosted Postfix via localhost): the server's reply to `DATA` (`250 2.0.0 Ok: queued as 4cXYZ` for Postfix; Gmail's `250 2.0.0 OK … - gsmtp`). PHPMailer keeps only the *last* reply, which after a send is QUIT's `221 Bye`, so `SmtpMailer` installs its own session class (`SmtpReceiptSession`) that keeps the `DATA` reply at the one moment PHPMailer reads it; the queue id is the transaction id PHPMailer already recognises for the common servers.
- Others: not implemented in this spec; `lastSendReport()['receipt']` is null and the timeline shows "accepted" with no receipt detail.

`MailboxSender` reads the report after the send and fills the attempt row. `EmailSender` itself is not changed in what it sends or how it falls back.

### A4 — asking the carrier whether it was delivered

A second optional provider interface:

```php
interface DeliveryEventSource {
    /**
     * Delivery events the carrier holds for one message we sent through it.
     * Returns null when the carrier cannot answer (no such API, credentials
     * missing, message too old for the carrier's retention).
     * @return ?array{status: string, events: array<array{time:string, event:string, recipient:string, detail:string}>}
     */
    public function deliveryEvents(string $message_id_header, string $from_domain): ?array;
}
```

`MailgunProvider` implements it with the Events API (`GET /v3/{domain}/events?message-id=<id without angle brackets>`, via the SDK's `events()`). A compose `send()` submits through the configured `mailgun_domain` while a raw relay submits through the sender's own active domain, so both are asked — configured first — and the first with events answers; a domain the key may not read (Mailgun scopes keys per domain, and dev's key reads `mg.dev.getjoinery.com` but not `dev.getjoinery.com`) is skipped rather than failing the lookup. It maps Mailgun's `accepted` → `accepted`, `delivered` → `delivered`, `failed` with `severity=permanent` → `failed`, `failed` with `severity=temporary` → `deferred`, `rejected` → `failed`. The `detail` for a failed event is Mailgun's `delivery-status.message` — the receiving server's own words ("550 5.1.1 The email account that you tried to reach does not exist"). Mailgun retains events for a few days on most plans; past that the lookup returns an empty event list and the stored status stands.

No other provider implements it in this spec. `SmtpProvider` returns null. The panel's wording per case:

- provider implements it and answered → one line per carrier event with the carrier's detail; the carrier's own `accepted` event is shown only while nothing later has happened (otherwise it repeats the receipt line); "checked N minutes ago" on the last line
- provider implements it and answered nothing → "Mailgun has no events for this message (it keeps them for a limited time)"
- provider does not implement it → "Delivery status is not available from {label}."
- `joinery_direct` → "the receiving instance confirmed receipt" (delivery *is* the send)

**Caching and refresh.** The attempt row caches the answer (`mst_delivery_status`, `mst_delivery_detail`, `mst_delivery_checked_time`). Opening the panel refreshes when the stored status is non-terminal (`unknown`, `accepted`, `deferred`) and the last check is more than 2 minutes old or never happened; `delivered` and `failed` are terminal and never re-asked unless the user presses **Check again**. There is no background poller: the question is asked when someone looks. A refresh that fails (carrier unreachable) leaves the cached values and says "could not reach Mailgun just now".

The lookup runs from the timeline API action, in the viewer's request, with the provider configured as the system provider (hosted-alias sends) — the same provider that took the message. It never runs during send.

### API action

`plugins/mailbox/logic/message_timeline_logic.php` → `POST /api/v1/action/mailbox/message_timeline`, param `message_id`, optional `refresh_delivery=1` (the Check again button). Authorization is exactly `message_source_logic`'s: mailbox-grant scope on the message's alias; NULL-alias messages superadmin-only.

Response:

```json
{
  "events": [
    {"time": "2026-09-16T14:02:09Z", "kind": "hop", "title": "Left the sender's server",
     "detail": "mail-sor-f41.google.com, on behalf of alice@example.com"},
    {"time": null, "kind": "sealed", "title": "Sealed into your vault", "detail": null},
    {"time": "…", "kind": "delivery", "title": "Delivered to gmail.com", "detail": "250 2.0.0 OK",
     "meta": {"checked_time": "…", "refreshable": true, "attempt_id": 12}}
  ],
  "locked": false,
  "notes": ["Gmail handled delivery — no delivery status is available here."]
}
```

`time` is UTC ISO-8601; the reader renders local time with its existing formatter. `kind` is one of `hop`, `arrived`, `fetched`, `auth`, `spam`, `ai_scan`, `routed`, `forwarded`, `sealed`, `opened`, `label`, `attempt_failed`, `sent`, `receipt`, `delivery`, `sent_copy`. Events are sorted by time; timeless state lines (`sealed`) sort after the arrival event.

The action assembles events from the row, the linked `iel_` row(s), the attempt rows where `mst_iem_inbound_email_message_id = id OR mst_source_iem_inbound_email_message_id = id`, and the `Received:` chain parsed from `iem_raw_headers` when readable. On a protected mailbox with a closed window the header-derived hops and sealed attempt fields are omitted and `locked: true` is returned alongside the events that are readable, so the panel shows what it can and offers the unlock — it does not go blank.

`Received:` parsing reuses the header walk `AuthenticationResults` already performs; a hop is `from <host> by <host> … ; <date>`. Hops whose date does not parse are shown without a time, in header order. The parser is tolerant, never fatal: a header block it cannot walk yields zero hop lines and everything else still renders.

### Reader UI

`mailbox_reader.js` kebab menu: a **Show logs** item after Show original, for every message (inbound, outbound, draft). It opens the shared modal with a `mbx-timeline` list: time column, title, detail line, per-event `kind` class for the icon/colour. A `delivery` event with `refreshable: true` renders a **Check again** button that re-calls the action with `refresh_delivery=1` and re-renders. `locked: true` renders the reader's existing one-tap unlock prompt at the top of the list. The empty case ("no events could be assembled") is a single sentence, not an empty modal.

`mailbox_reader.css`: the timeline layout, phone-width first (time above title on narrow screens).

## Bugs fixed in this spec

### B1 — the email debug log cannot write

`EmailSender::logEmailDebug()` (`includes/EmailSender.php`) sets `del_timestamp`, `del_message`, `del_service`, `del_status`. `DebugEmailLog` (`data/debug_email_logs_class.php`) declares `del_subject`, `del_recipient_email`, `del_body`, `del_create_time`. Every write with `email_debug_mode=1` logs four "Attempting to set the non-defined field" exceptions and saves an empty row; the table has never held a useful record. An operator turning debug mode on to diagnose a send gets nothing.

**Fix.** The sender's fields are the useful ones; the class matches them:

```php
'del_debug_email_log_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
'del_message'            => array('type'=>'text'),
'del_service'            => array('type'=>'varchar(50)'),
'del_status'             => array('type'=>'varchar(20)'),
'del_create_time'        => array('type'=>'timestamp(6)', 'default'=>'now()'),
```

`logEmailDebug()` drops its `del_timestamp` set (`del_create_time` defaults). `update_database` adds the three columns; the three dead ones (`del_subject`, `del_recipient_email`, `del_body`) stay as empty columns — the schema pipeline adds and alters, it does not drop, and nothing reads them. The debug log is not a timeline source (see "Not available").

The admin page that showed the log (`adm/admin_debug_email_logs.php`) was built for the never-written trio too — it linked each row by `del_subject` to a detail page whose iframe rendered `del_body`. It becomes a plain table of time / service / status / message, and the detail and preview pages (`admin_debug_email_log.php`, `admin_debug_email_log_preview.php`, `logic/admin_debug_email_log_logic.php`) are removed.

### B3 — the debug log's "Delete All" button did nothing

The list page's altlink posted `action=delete_all` to itself and nothing read it. The page handles the action (`DebugEmailLog::deleteAll()`) before rendering, with a confirm on the button.

### B2 — a failed reader send leaves no trace

`MailboxSender::send()` stores the outbound row only after `EmailSender::send()` returns true; a failed send throws `MailboxSenderException` and nothing is written. The person saw one error toast and there is no record they tried, which transport refused, or why — and nothing for the timeline to show on the message they were answering.

**Fix.** A2: the attempt record is written on every outcome, linked to the draft and to the replied-to message. The failure line on the timeline carries the transport's own error text. No behaviour of the send itself changes — it still fails loudly to the user and still never queues for retry.

## Authorization and sealing

- Reading the timeline requires exactly the grant required to read the message. No new permission.
- Nothing in the timeline reveals body content. Header hops name servers and the envelope sender; recipients and error text on a protected mailbox are sealed with the outbound row (A2). The `Received:` chain comes from `iem_raw_headers`, already a sealed field, so it is readable only in-window.
- The delivery lookup sends only the Message-ID and the sending domain to the carrier — both things the carrier already holds.
- The API action is read-only except for the delivery cache columns on the attempt row, written with a targeted UPDATE (`MailboxSendAttempt::cacheDelivery()`) on a POST — it is not a page view and needs no `server_initiated_write()`. The routing-log link is written at ingest.

## Tests

- `plugins/mailbox/tests/message_timeline_test.php` (tier `test-db`, 46 checks): pushes real messages through a probe router (relay answers as told). A1 — the stored transaction's `iel_` row names the row and carries the subject. A2 forward — one `forward` attempt per relayed stored copy: `sent` when every destination took it, `partial` with the failed destination named, keyed by the original Message-ID; a pure-forward alias writes none. A2 compose — `MailboxSender::recordAttempt()` from a fabricated report: a failure leaves a row with the transport's words and no outbound row (B2); a success keeps the receipt and Direct-delivered recipients and reads `partial` when Direct took some. The timeline — hops from the `Received:` chain oldest first with their own times, arrival/auth/routing present, the failed reply on the message it answered, time order, the sent message's receipt and Direct line, a partial forward's caveat, and an unreachable carrier said rather than guessed. Sealing — a sealing mailbox with no vault records the attempt with recipients and error NULL.
- `tests/email/send_report_test.php` (tier `safe`, 24 checks): `lastSendReport()` through a stub `SendReceiptSource` transport that accepts / refuses / throws; Direct delivering some (listed, transport takes the rest) and all (no transport named); a dry run reporting nothing; `MailgunProvider::worstStatus()` (latest event per recipient, worst across recipients, unknown event → `unknown`); B1 — every `del_` column `EmailSender` writes is declared on `DebugEmailLog`.
- Live proof on dev 2026-09-16: a compose from `test@dev.getjoinery.com` through Mailgun showed *Sent → Mailgun accepted (Queued. Thank you.) → Delivered to dev.getjoinery.com (250 2.0.0 Ok: queued as 0851762410)*; the stored copy at `chat@` showed two hops, arrival, SPF/DKIM/DMARC pass, spam check, and *Routed to chat@dev.getjoinery.com — stored*.

## Documentation to update

- `plugins/mailbox/docs/overview.md`: a "Message timeline" section — what the ⋮ → Show logs panel shows, its sources, and the per-transport delivery-status availability table (Mailgun: yes; connected account: no; self-hosted Postfix: acceptance only; Direct: confirmed on send).
- `docs/email_system.md`: `lastSendReport()`, the `SendReceiptSource` and `DeliveryEventSource` provider interfaces, and the corrected debug log fields.
- `docs/api.md`: the `mailbox/message_timeline` action.

## Version bumps

`MailboxSender` 1.19, `EmailSender` 1.1 (first header), `EmailServiceProvider.php` 1.8, `MailgunProvider` 1.9, `SmtpProvider` 1.5, `SmtpMailer` 2.4, `InboundEmailRouter` 1.37, `InboundEmailLog` 1.7, `AuthenticationResults` 1.1, `DebugEmailLog` 2.0, `admin_debug_email_logs.php` 2.0, `mailbox_reader.js` 2.65, `mailbox_reader.css` 2.39, `mailbox_reader_mount.php` 1.22.0, mailbox `plugin.json` 1.116.0. New: `plugins/mailbox/data/mailbox_send_attempt_class.php`, `plugins/mailbox/includes/MailboxMessageTimeline.php`, `plugins/mailbox/logic/message_timeline_logic.php`.

## Open items

- **A5 — label/archive/star history.** The reader keeps only the latest state-change time and does not record which filter acted. A per-message action log would make "Labelled Receipts by filter X at 16:20" true instead of "currently labelled Receipts". Deferred: it is a change to how filters and thread actions write, not to this panel, and the panel's other lines do not depend on it. The panel shows current labels as a timeless state line meanwhile.
- **Postfix delivery status.** Queue id is captured (A3); the queue's fate lives in `mail.log`, root-readable. If wanted later, a node-agent recipe can answer "what happened to queue id X" and the panel gains one more `DeliveryEventSource`. Not this spec.
- **Other carriers' event APIs.** Postmark (Messages API by our Message-ID via `metadata` or by their id from the receipt), SES (event publishing only, push not pull — would need a webhook endpoint), SendGrid (Email Activity, paid add-on), Brevo, Mailjet, Resend, SMTP2GO each have some form. Each is one `DeliveryEventSource` implementation once the shape is proven on Mailgun.
