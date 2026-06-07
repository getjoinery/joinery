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
existing FormWriter form's fields from JS is not hand-rolling a form. The form submits via a `fetch`
intercept so the reader keeps its no-reload feel.

Consequences: **CSRF is not a concern for this admin-only (permission ≥5) endpoint** — render the
FormWriter form with `csrf => false`. FormWriter's own token is single-use and time-boxed (it `unset`s
the token on a valid check and expires it after 2h, `FormWriterV2Base.php:269-279`), which would break a
second compose in a long-lived reader; disabling it avoids that trap entirely. The send endpoint reuses
the reader's existing persistent `mailbox_reader_csrf` token (the same one the single-button actions
validate-but-don't-consume) or omits the check. The collapsible **Cc** and the plain/rich **body
toggle** are FormWriter `visibility_rules`, not hand-rolled JS.

## 5. Threading & Subject

- `In-Reply-To` = the replied-to message's `iem_message_id_header`.
- `References` = original `References` chain + the replied-to Message-ID (built from stored headers;
  the inbound side already parses these — `InboundEmailRouter::computeThreadKey`).
- Generate/capture the sent message's `Message-ID` for storage + dedup (§6).
- Reuse the conversation's `iem_thread_key` so the sent row groups into the same thread.
- Subject: add `Re:`/`Fwd:` only when not already present.

The threading headers are set on the outgoing `EmailMessage` via `->header()` (`EmailMessage.php:149`) —
wire-only. None are persisted as columns: grouping is `iem_thread_key` (copied from the replied-to row)
and nothing reads `In-Reply-To`/`References` back from storage (see §8).

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

- **Attachment plumbing (shared sink).** Re-attaching fetched/parsed originals needs in-memory bytes,
  but `EmailMessage` only attaches by file path today (`attach($filePath)`, `EmailMessage.php:136`). Add
  `EmailMessage::attachData($bytes, $filename, $contentType)` and map it in `SmtpMailer::applyMessage`
  via PHPMailer `addStringAttachment()`. Both forward sources below converge on this one sink — it is a
  general capability (any feature sending generated/in-memory content benefits), not forward-specific.
- **IMAP-source messages** are reference-backed (no stored bytes), but the attachment set is **already
  enumerated**: the IMAP poll writes an `ima_` manifest per message (`ImapIngestor::writeManifest`) with
  `ima_mime_part`, `ima_filename`, `ima_content_type`, `ima_encoding`, `ima_is_inline`. Forward = load
  the `ima_` rows, `fetchPart(ima_mime_part, …)` each (the IMAP locator columns are on the `iem_` row),
  `attachData`. **No MIME-tree walk at forward time.** The reference-backed caveat applies — the source
  must still hold the message (§10).
- **Hosted messages** retain raw MIME in `iem_raw_message` but have **no** manifest (it is written only
  on the IMAP path). Forward = parse `iem_raw_message` with `Horde_Mime_Part::parseMessage` (Horde_Mime
  is already a dependency, used in `ImapIngestor`), iterate parts, `attachData`. This parse helper is the
  only genuinely new extraction code in the feature.
- User-added attachments (compose uploads) are added alongside.

## 8. Data Model Changes

All via `$field_specifications` + `update_database`. The whole feature adds **one column**:

**`iem_inbound_email_messages`:**
- `iem_direction` `varchar(10)` default `'inbound'` not null — `inbound|outbound`.

`MailboxService::getThread()` must add `iem_direction` to its SELECT (it is not selected today) so the
reader can style the outbound half as "Sent."

Nothing else is stored:
- `iem_in_reply_to` / `iem_references` — **not stored.** The outgoing `In-Reply-To`/`References` headers
  are built transiently at send time (§5) and have no reader that reads them back; grouping is
  `iem_thread_key`, which the sent row copies from the replied-to row.
- `iem_send_status` — **not stored.** Failed sends are not persisted (§10), so every outbound row that
  exists is sent; the column would carry a single value.

(The feed-SMTP `PRESETS` coordinates needed to *send* live in the Outbound Email spec, not here.)

## 9. Endpoint & Permissions

- **New `ajax/mailbox_send.php`:** params — mode (`reply|reply_all|forward`), `alias_id`, source
  `thread_key` + replied-to message id, To/Cc, subject, body, attachment refs. Enforces `MailboxService`
  scope (sender must have a grant to the mailbox; superadmins all-access), builds the `EmailMessage`,
  resolves `$t = resolveOutboundTransport(mailbox)`, and **applies the identity itself** —
  `$message->from($t->fromAddress, $fromName)`. (Required: the hosted-alias path returns
  `transport = null` to mean "use the platform's active provider," and nothing else forces From to the
  alias; the resolver stays a pure data lookup.) Then sends `EmailSender::send($message, false,
  $t->transport)` — `queue_on_failure = false` so success/failure is synchronous and shown inline
  (§10) — and stores the outbound row **only on success** (§6), returns status. CSRF: admin-only (§4).
- Sending as a mailbox is gated by the **same scope** that governs reading/mutating it — no new
  permission concept.

## 10. Failure Handling

- Send failure → do **not** present the message as sent and do **not** store a row. Surface the error
  inline; the compose panel retains the user's draft so retry is "fix and Send again." No stored failed
  rows and no draft/retry state machine — both are §2 non-goals. Failure observability comes from the
  `error_log` lines in `EmailSender`/`SmtpProvider` plus the inline reader error.
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
  fetch-submitted; no CSRF concern (admin-only) so FormWriter is rendered with `csrf => false`; Cc/body
  toggles via `visibility_rules`.
- *(resolved — see §7)* **Quote/forward formatting:** inline by default (blockquote reply, Gmail-style
  forward block, attachments re-attached); `message/rfc822` attachment style deferred.

(Transport and provider-scope decisions — Gmail vs. API, Microsoft `SMTP.Send` — live in the
Outbound Email spec.)

## 15. Docs to Update at Implementation

Add a "Reply / Forward" section to `plugins/inbound_email/docs/overview.md` describing the compose
actions, threading, the stored outbound row + reader dialog rendering, and that sending uses the
per-mailbox transport from the Outbound Email spec. Written as the current state, per the docs rule.
