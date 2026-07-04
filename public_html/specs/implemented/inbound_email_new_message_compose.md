# New-Message Compose for Mailboxes (Web + iOS)

**Status:** Implemented. `MailboxSender::MODE_NEW` is the one shared
implementation both send endpoints and both clients (web, iOS) use.
Verified live on dev.getjoinery.com: New message → From preselected to the
open mailbox → sent → new conversation appeared in the list instantly with
the exact subject entered (no Re:/Fwd:), no quote block, and working
Reply/Reply All/Forward chips. iOS verified via `xcodebuild test -scheme
JoineryKit-Package` (all existing suites pass; no new unit tests added since
the touched surface is UI/networking glue over already-tested models, same
as the attachments spec).
**Plugin:** inbound_email (server + web reader), JoineryMailKit (iOS)
**Related:** `specs/implemented/inbound_email_compose_attachments.md`
(independent; see "Interplay" at the end)

## Current state

The mailbox can only continue conversations, never start one. Compose is
reachable solely from a thread's Reply / Reply-All / Forward chips, and the
whole send pipeline assumes a source message: `MailboxSender::send()`
rejects any mode other than reply / reply_all / forward, and the source
message is what supplies the two things a send needs beyond recipients —
**which mailbox to send as** (the source's alias is the sending identity)
and **which conversation to file into** (the source's thread key). There is
no compose button on the web reader, the admin reader, or the iOS mailbox
screen.

## Goal

A "New message" action on every mailbox surface: pick which mailbox to send
as (when you have more than one), enter To/Cc/Subject/body, send. The sent
message starts a new conversation in the reader, and when the recipient
replies, their reply files into that same conversation.

## Non-goals

- No contacts/address book, no drafts, no rich-text body editor — the
  compose form stays exactly as lean as the reply form.
- No changes to reply / reply-all / forward behavior.
- No Android work.

## Design

### Server: a fourth mode, `new`

`MailboxSender` gains `MODE_NEW = 'new'`. The engine is reused whole; the
mode differs only in where identity and threading come from:

- **Params:** `alias_id` replaces `source_id`. Authorization is
  `viewer->canAccess(alias_id)` plus alias-exists-and-not-deleted — the
  same grant rule as everywhere else ("a grant means full access: read +
  send as it").
- **Identity:** unchanged from there on — `connectedAccountFor()` and
  `resolveOutboundTransport()` already key on the alias, not the source.
- **Subject:** sent as entered (trimmed), no Re:/Fwd: prefixing. Empty is
  allowed — the reader already renders "(no subject)".
- **Body:** the user's text only (`textToHtml()`), no quote block.
- **Threading:** no In-Reply-To/References headers. The stored row's
  `iem_thread_key` is the new message's own Message-ID — the same
  "singleton thread" rule the inbound ingest uses
  (`InboundEmailRouter::computeThreadKey()` precedence 3). This is what
  makes replies thread back: the recipient's reply carries our Message-ID
  in In-Reply-To, ingest's precedence 2 resolves it to that same key, and
  the reply lands in the conversation.
- **Sent/compose interop (§9 of the reply spec):** unchanged —
  `appendSentCopy()` and the Gmail `pending_sent_ingest` path key on the
  connected account, not the mode. For the Gmail case the new conversation
  appears after the next Sent ingest, exactly like a reply does.
- **Uploads:** `attachUploads()` already runs in every mode; a new message
  can carry attachments with zero extra work.

### Web reader

- A **New message** button in the reader chrome (list header, next to
  Refresh), enabled whenever the viewer can compose. Clicking it opens the
  existing compose panel in `new` mode: empty To/Cc/Subject/body, no
  source context.
- A **From selector** appears in the panel only in `new` mode: a select of
  the viewer's mailboxes, defaulting to the mailbox currently selected in
  the rail (or the only one, when there is just one grant — in which case
  the selector still shows, as a one-option statement of the sending
  address, not a control to hunt for). Reply modes keep their implicit
  identity and never show it.
- The mailbox list is already in the reader's memory (`state.mailboxes`,
  loaded at init for the rail) — the selector is populated from it, no new
  fetch.
- Submit is the same `FormData` POST with `mode=new` and `alias_id` from
  the selector; `source_id` is absent. Errors render inline as today.
- The admin reader shares the mount and JS, so it gets all of this
  automatically; an all-access admin's selector lists every mailbox
  (that's what `mailboxesUrl` already returns for them).

### iOS (JoineryMailKit)

- A compose toolbar button (`square.and.pencil`) on `MailboxScreen`,
  presenting the existing `ComposeSheet` in a new `.new` mode.
- `ComposeSheet` in `.new` mode: empty To/Cc/Subject, a **From picker**
  over the granted mailboxes (`MailboxStore.home.mailboxes`, preselecting
  the store's `selectedAlias`), and no quoted-original footer text.
- `MailAPI.send()` gains the `new` mode and an `aliasID` parameter sent as
  `alias_id`; `source_id` is omitted in this mode.

## Implementation notes

Line numbers are as of this writing — verify before editing.

### `plugins/inbound_email/includes/MailboxSender.php`

- Add `const MODE_NEW = 'new'` and accept it in the mode check
  (`send()`, lines 76-79).
- Branch early in `send()`: in `new` mode, skip `loadSourceInScope()`
  (line 81) and instead load the alias from `intval($params['alias_id'])`,
  checking `$this->viewer->canAccess($alias_id)` and
  alias-exists/not-deleted (throw `MailboxSenderException` with the same
  tone as the existing messages). Everything from transport resolution
  (line 90) through recipient parsing is shared; note the Cc suppression
  at line 100 is forward-only and needs no change.
- `normalizeSubject()` (lines 214-224): in `new` mode return the trimmed
  subject as-is (no prefix, no fallback to a source subject).
- `buildBody()` (lines 226-250): in `new` mode return
  `'<div>' . $this->textToHtml($userText) . '</div>'` — no quote block.
  Cleanest is a guard at the top rather than threading a nullable source
  through the quote logic.
- Skip `applyThreadingHeaders()` (already reply-only, line 119) and
  `attachOriginal()` (already forward-only, line 126); `attachUploads()`
  (line 129) runs unchanged.
- `storeOutboundRow()` (lines 649-680) takes the source for two values
  only: the domain id (line 653) and the thread key (line 650). Make the
  source parameter nullable; when null, the domain id comes from the
  alias (`iea_ied_inbound_email_domain_id`) and the thread key is the new
  message's own Message-ID (`substr($message_id, 0, 255)` — the same
  truncation `computeThreadKey()` applies). `resolveThreadKey()`
  (line 682) is untouched and simply not called in `new` mode.
- The source-message row-locking/backfill in `resolveThreadKey()` and the
  §9 interop block (lines 146-160) need no changes — verify they are
  reached only with a source or are mode-independent, respectively.

### Endpoints

- `ajax/mailbox_send.php`: pass `alias_id` through in the `$params` array
  (line 45-52). Nothing else changes — mode validation lives in
  `MailboxSender`.
- `logic/send_logic.php`: same one-line param addition (lines 34-41);
  update the docblock's params list.

### Web reader

- **Mount** (`includes/mailbox_reader_mount.php`): add the New message
  button to the list header (near `#mbx-refresh`, line ~103) and a
  FormWriter select for From
  (`$compose->select('alias_id', 'From', ...)`) with no options —
  options are filled client-side from the already-loaded mailbox list.
  Wrap it (or give it an id) so JS can show it only in `new` mode.
- **JS** (`assets/mailbox_reader.js`): `openCompose(mode, t, source)`
  (lines 771-813) currently requires a thread + source; give it a
  `new`-mode path that clears all fields, populates and shows the From
  select from `state.mailboxes` (line 29/81; preselect the rail's current
  selection — see the `selectMailbox` state around lines 353/820), and
  hides it in the reply modes. `submitCompose()` (840-872) needs no
  structural change — the select is a form field and rides the
  `FormData`; keep the client-side "recipient required" check (845-846).
  Show the button only when the viewer has mailboxes (the init seed at
  lines 1034-1056 already handles the zero-mailbox message).
- The compose panel title (`#mbx-compose-title`) reads "New message" in
  this mode.

### iOS

- `ThreadDetailView.swift:204` — `ComposeRequest` holds `mode` + `source`;
  make `source` optional (`MailMessage?`) or add a factory for the
  sourceless case. `ComposeSheet.init` (lines 22-39) switches on mode to
  prefill; add the `.new` case (empty To, empty Subject). The footer text
  (lines 79-83) shows nothing in `.new` mode.
- `MailAPI.ComposeMode` (MailAPI.swift:107-111): add `case new = "new"`.
  `send()` (115-131): add `aliasID: Int? = nil`; in `.new` mode send
  `alias_id` and omit `source_id`.
- `MailboxScreen.swift`: toolbar compose button presenting
  `ComposeSheet`; pass the mailbox list and current selection from
  `MailboxStore` (the granted mailboxes live on `home.mailboxes`,
  current selection on `selectedAlias` — MailboxStore.swift:39). In
  `.new` mode the sheet renders a From `Picker` above To.
- On sent, the existing `onSent` refresh path shows the new conversation.

### Build and verify

Same as `specs/inbound_email_compose_attachments.md`: `php -l` +
`validate_php_file.php` on touched PHP, existing inbound_email suites,
live send on dev.getjoinery.com; iOS built and tested on the Mac mini
(`JoineryKit-Package` scheme runs `JoineryMailKitTests`) after rsyncing
`ios/` — edit only the repo copy under `{repo root}/ios/`. Bump docblock
versions and `plugins/inbound_email/plugin.json`.

## Documentation updates (part of implementation)

- `plugins/inbound_email/docs/overview.md` — the compose statement covers
  new-message compose: grant-gated From identity, singleton thread key,
  reply threading behavior.
- `docs/mobile_apps.md` — mail compose section covers the new-message
  entry point and From picker.
- `docs/api.md` — `inbound_email/send` accepts `mode=new` with `alias_id`.

## Acceptance

1. Web: New message → From preselected to the rail's current mailbox →
   send to an external address → the recipient receives it from the alias
   address; a new conversation appears in the reader; the recipient's
   reply files into that same conversation (not a new one).
2. A viewer with one grant sees the From line stating the address; a
   viewer with several can switch it; an admin sees all mailboxes.
3. `mode=new` with an `alias_id` outside the viewer's grants is rejected;
   `mode=new` with no recipients is rejected with the draft kept.
4. iOS: compose from the mailbox screen toolbar with the From picker →
   same round-trip and threading as (1), visible in both the app and the
   web reader.
5. Reply / reply-all / forward behavior is unchanged; existing suites
   pass.

## Interplay with the attachments spec

Independent specs, shared surfaces (`MailboxSender::send()`, the compose
panel, `ComposeSheet`, both send endpoints). They can land in either
order and the second rebases trivially — and because uploads attach in
every mode and new-message compose flows through the same form and
engine, whichever lands second gives new messages attachments for free.
