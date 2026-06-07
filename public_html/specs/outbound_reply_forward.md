# Spec: Reply / Forward from the Mailbox Reader

**Status:** Proposed (awaiting implementation)
**Scope:** `inbound_email` plugin — compose reply/forward in the reader, threaded into stored conversations
**Depends on:** `specs/connected_account_email.md` (§7 Per-Mailbox Send Transport — *how* a
message is actually sent as a mailbox)
**Related:** `specs/two_way_imap_sync.md`, `plugins/inbound_email/docs/overview.md`

> This spec is the **reader feature**: the compose UI, threading, storing the sent message, and
> forwarding. The mechanics of *sending as a mailbox* (identity + transport: hosted relay vs. the
> feed's own Gmail/M365/password SMTP) are defined once in the Outbound Email spec and consumed
> here via `resolveOutboundTransport(mailbox)`.

---

## 1. Problem & Goal

The mailbox reader is read-only: view, mark read/unread, star, soft-delete. It cannot reply.
Replying today happens outside Joinery — in whatever inbox a message was forwarded to, or in the
source Gmail for an IMAP feed.

**Goal:** let a user with access to a mailbox **Reply, Reply-All, and Forward** from the reader,
sent as that mailbox, threaded correctly, with the sent copy stored so the reader shows the full
back-and-forth conversation.

## 2. Non-Goals

- **Not a full webmail client.** This spec adds no folder management, scheduled send, or
  signatures/templates in v1 (the platform's email-template system is separate). Folder/label
  navigation and Sent ingestion are owned by `two_way_imap_sync.md`, not built here.
- **No compose-from-scratch.** v1 is reply / reply-all / forward *from an existing thread*; a blank
  "New message" composer is later.
- **No transport/provider design here.** Identity and transport are defined in the Outbound Email
  spec; this spec assumes `resolveOutboundTransport(mailbox)` exists and delivers as the mailbox.
- **No change to automatic forwarding.** Alias forward/forward+store behavior is untouched.

## 3. Building Blocks (reuse)

- `EmailMessage` — supports `from`, `fromName`, `replyTo`, recipients, `cc`/`bcc`, subject,
  html/text body, **custom headers** (for `In-Reply-To`/`References`), and attachments.
- **Send transport** — `resolveOutboundTransport(mailbox)` from the Outbound Email spec returns the
  authorized transport + identity for the mailbox (hosted → platform provider + DKIM/SRS;
  IMAP-source → the feed's own SMTP). This spec calls it and does not re-decide transport.
- Reference-backed retrieval in `ImapIngestor` (on-demand body/attachment fetch) — to re-attach
  original content when **forwarding** an IMAP-sourced message.
- `MailboxService` scope (`MailboxViewer`) — gates who may send as a mailbox.
- Reader CSRF: the persistent per-session reader token used by `mailbox_action.php`.

## 4. Actions & Compose UI

In the reader's thread detail view, add **Reply**, **Reply All**, **Forward** (alongside Mark
unread / Star / Delete). Selecting one opens an in-reader compose panel:

- **Reply:** To = original sender; Subject = `Re: …`; body quotes the original.
- **Reply All:** To = sender; Cc = original To/Cc minus our own mailbox address.
- **Forward:** To = empty; Subject = `Fwd: …`; original message + attachments embedded (§7).
- Fields: To, Cc (collapsible), Subject, body (plain; optional rich via the existing editor),
  attachment upload (platform `UploadHandler`).

**UI convention — resolved: FormWriter, embedded in the reader.** The reader's existing single-button
actions (mark read / star / delete) are hidden-input action forms and legitimately keep the JS +
reader-token path — that is the CLAUDE.md exception. Compose is *not* that exception: it has real
user-entered fields (To, Cc, Subject, body, attachments), so it uses **FormWriter** like every other
multi-field form in the platform.

The all-JS reader is no obstacle: render the compose form with FormWriter server-side once (hidden),
and have the reader's JS **show and populate** it — setting To/Cc/Subject/quoted body and the hidden
context fields (mode, `alias_id`, `thread_key`, replied-to id) on the clicked message. Populating an
existing FormWriter form's fields from JS is not hand-rolling a form. The form may submit via a
`fetch` intercept (reading FormWriter's own CSRF hidden field) so the reader keeps its no-reload feel.
Consequences: CSRF for this endpoint is FormWriter's standard token (the reader token remains for the
single-button actions); the collapsible **Cc** and the plain/rich **body toggle** are FormWriter
`visibility_rules`, not hand-rolled JS.

## 5. Threading & Subject

- `In-Reply-To` = the replied-to message's `iem_message_id_header`.
- `References` = original `References` chain + the replied-to Message-ID (built from stored headers;
  the inbound side already parses these — `InboundEmailRouter::computeThreadKey`).
- Generate/capture the sent message's `Message-ID` for storage + dedup (§6).
- Reuse the conversation's `iem_thread_key` so the sent row groups into the same thread.
- Subject: add `Re:`/`Fwd:` only when not already present.

## 6. Storing the Sent Message

Persist each successfully sent message as an `iem_` row in the same thread so the reader shows the
conversation, not just inbound halves.

- **New column `iem_direction`** `varchar(10)` default `'inbound'` not null — `inbound|outbound`.
  Outbound rows: `iem_sender` = mailbox address, `iem_recipient` = the full recipient list
  (comma-joined To/Cc, for display), store subject/body, `iem_thread_key` = conversation key,
  `iem_message_id_header` = sent Message-ID, `iem_is_read = true`, `iem_is_starred = false`,
  `iem_received_time` = send time. (The inbound dedup constraint on `iem_recipient` is unaffected —
  outbound rows carry distinct Message-IDs.)
- **Reader rendering:** `mailbox_thread.php` returns `direction`; the JS styles outbound messages
  distinctly (labeled "Sent" / aligned) so a thread reads as a dialog.
- **The local outbound row is created immediately on send** and is the reader's record from that
  moment — the conversation renders from it without waiting for a poll.
- **Reconciliation with the ingested Sent copy.** When two-way sync (`two_way_imap_sync.md`) tracks the
  Sent folder, the same message is later observed there — filed by the provider (Gmail/M365 auto-save)
  or `APPEND`-ed by Joinery on a non-auto-filing provider. Ingestion **does not create a second row:**
  it matches the sent copy to the existing outbound row by `iem_gm_msgid` (Gmail, server-stable) or
  `iem_message_id_header` (elsewhere), backfills the IMAP locator, and adds an `imf_` membership in the
  Sent folder so the outbound row becomes reference-backed and shows under Sent in folder navigation.
- **Dedup is reliable, not best-effort.** `iem_gm_msgid` is server-assigned and stable, so the case
  that was previously unsolvable — Gmail rewriting `Message-ID` server-side on send — now reconciles
  cleanly. The APPEND path is reliable by construction (Joinery sets the Message-ID it later matches).
  The own-send identity is the `(account, gm_msgid|message_id)` match; no separate heuristic is needed.

## 7. Forward Specifics

**Formatting — inline (the conventional default).** Replies quote the original inline (a styled
blockquote under an attribution line — "On <date>, <sender> wrote:"). Forwards embed the original
inline Gmail-style: a "Forwarded message" block with the original From/Date/Subject/To headers
followed by the body, with the original attachments re-attached. The original-as-`message/rfc822`
attachment style is **not** v1; it can be a later option. This is a UX choice with no architectural
weight — both styles fetch the same content below.

- **Hosted messages** retain raw MIME in `iem_raw_message` → original headers/body/attachments read
  from the stored message.
- **IMAP-source messages** are reference-backed (no stored bytes) → fetch the original
  parts/attachments on demand via the existing `ImapIngestor` fetch path (the reference-backed
  caveat applies — the source must still hold the message).
- User-added attachments (compose uploads) are added alongside.

## 8. Data Model Changes

All via `$field_specifications` + `update_database`.

**`iem_inbound_email_message`:**
- `iem_direction` `varchar(10)` default `'inbound'` not null.
- `iem_send_status` `varchar(20)` nullable — `sent|failed|queued` for outbound rows (observability +
  retry); null for inbound.
- (Optional) `iem_in_reply_to` / `iem_references` `text` — store threading headers for fidelity;
  `iem_thread_key` already suffices for grouping, so these are nice-to-have.

(The feed-SMTP `PRESETS` coordinates needed to *send* live in the Outbound Email spec, not here.)

## 9. Endpoint & Permissions

- **New `ajax/mailbox_send.php`:** params — mode (`reply|reply_all|forward`), `alias_id`, source
  `thread_key` + replied-to message id, To/Cc, subject, body, attachment refs. Validates the
  **FormWriter CSRF token** (§4), enforces `MailboxService` scope (sender must have a grant to the
  mailbox; superadmins all-access), builds the `EmailMessage`, sends via
  `resolveOutboundTransport(mailbox)`, stores the outbound row (§6), returns status.
- Sending as a mailbox is gated by the **same scope** that governs reading/mutating it — no new
  permission concept.

## 10. Failure Handling

- Send failure → do **not** present the message as sent; store with `iem_send_status='failed'`
  (or don't store) and surface the error inline for retry.
- OAuth expiry on a feed-SMTP send → reuse `iia_needs_reauth` / `markNeedsReauth()` and the Accounts
  "Reconnect" affordance (shared with inbound).
- **Forward of a reference-backed original no longer on the source** (deleted/expunged upstream, so the
  on-demand fetch fails): fail the forward with a clear message ("the original message is no longer
  available in the source mailbox") and do not send a contentless forward. The user can still compose
  a plain message manually. (Hosted messages retain `iem_raw_message`, so this only affects
  IMAP-source forwards.)
- Attachment/MIME size caps enforced server-side.

## 11. Relationship to Two-Way Sync

Two-way sync (`two_way_imap_sync.md`) tracks the **Sent folder**, so a Joinery-composed message lands
in the source's Sent and flows back into the reader. The two specs interlock as follows:

- **Getting the copy into Sent.** For an IMAP-source mailbox the reader sends through the feed's own
  SMTP (`resolveOutboundTransport`, §9). Gmail/M365 SMTP auto-file the Sent copy, so nothing more is
  needed. On a provider that does **not** auto-file (self-hosted Postfix+Dovecot; `PRESETS`
  `smtp_files_sent = false`), the sync layer `APPEND`s the sent copy to Sent — gated so the copy is
  filed **exactly once** (never both auto-filed and APPEND-ed). For hosted mailboxes there is no source
  mailbox to file into; the local outbound row stands alone.
- **No duplicate on the way back.** When sync ingests the Sent folder, it reconciles the copy to the
  outbound row created at send (§6) by `iem_gm_msgid` / `iem_message_id_header` rather than creating a
  new row.
- **Shared health.** Both specs reuse the OAuth-token grant and the `iia_needs_reauth` model.

The specs can still ship independently: until Sent is tracked, the local outbound row is the sole
record and nothing is ingested back; once it is, reconciliation activates with no change to this spec's
send path.

## 12. Testing

- Threading headers: `In-Reply-To`/`References` correctly built from a stored thread; `Re:`/`Fwd:`
  subject normalization.
- Outbound storage: row stored with `iem_direction='outbound'`, grouped by thread_key; reader renders
  it as the sent half of the dialog from the local row.
- Sent reconciliation: a sent copy observed in the Sent folder (provider-filed or APPEND-ed)
  reconciles to the outbound row by `iem_gm_msgid` / `iem_message_id_header` — one row, not two — and
  gains an `imf_` Sent membership. Gmail's server-side `Message-ID` rewrite still reconciles via
  `iem_gm_msgid`.
- Scope: a user without a grant to the mailbox cannot send as it.
- Forward: reference-backed original attachments fetched and attached.
- Failure: send error → not marked sent; OAuth expiry → needs_reauth.

(Transport-resolution tests — which transport per mailbox type — live with the Outbound Email spec.)

## 13. Delivery Order (not a timeline)

1. Reply / Reply-All for **hosted** mailboxes (platform provider, our DKIM).
2. Outbound storage + reader dialog rendering (`iem_direction`).
3. IMAP-source send (depends on the Outbound Email spec's per-mailbox transport). Sent-copy
   reconciliation (§6) activates when `two_way_imap_sync.md` tracks the Sent folder; until then the
   local outbound row stands alone.
4. Forward (inline original + reference-backed attachment re-attach).
5. Compose attachments (uploads).

## 14. Open Decisions

- *(resolved — see §4)* **Compose UI:** FormWriter form embedded in the reader, JS-populated and
  fetch-submitted; CSRF via FormWriter; Cc/body toggles via `visibility_rules`.
- *(resolved — see §7)* **Quote/forward formatting:** inline by default (blockquote reply, Gmail-style
  forward block, attachments re-attached); `message/rfc822` attachment style deferred.

(Transport and provider-scope decisions — Gmail vs. API, Microsoft `SMTP.Send` — live in the
Outbound Email spec.)

## 15. Docs to Update at Implementation

Add a "Reply / Forward" section to `plugins/inbound_email/docs/overview.md` describing the compose
actions, threading, the stored outbound row + reader dialog rendering, and that sending uses the
per-mailbox transport from the Outbound Email spec. Written as the current state, per the docs rule.
