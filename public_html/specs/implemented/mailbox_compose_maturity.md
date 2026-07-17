# Mailbox — Compose Maturity (Individual Track)

## Status: active — design

The individual-webmail compose layer for the mailbox plugin: **saved drafts,
rich-text compose, BCC, per-mailbox signatures, recipient autocomplete backed by a
real contact store (with import), and the member-context panel.** These were carved
out of `specs/mailbox_group_collaboration.md` (deferred team layer) because they are
single-user daily-driver features — the things you hit in the first week of moving a
real email account onto the platform — and are not gated on any team work.

Product bar: a user who moves their real personal/business mail here should not lose
a half-written reply, should not send HTML mail that looks like `nl2br()` output,
should not retype their sign-off on every message, and should not have to remember
correspondents' addresses. That is the whole spec.

**Pre-launch note:** no production users; no data-preservation migrations needed.
Schema lands via `$field_specifications` + plugin sync.

## Current state (what this builds on)

One compose panel (`#mbx-compose` in `includes/mailbox_reader_mount.php`) shared by
the member reader (`/profile/mailbox/mailbox`) and admin reader, driven by
`assets/mailbox_reader.js` (vanilla IIFE). Four modes — `reply` / `reply_all` /
`forward` / `new` — all through `includes/MailboxSender.php` and the API action
`mailbox/send` (`logic/send_logic.php`). Fields today: From (aliases select, new mode
only), To, Cc, Subject, plaintext `<textarea>` body, attachment chips (10 files /
10 MB / 25 MB, `File` + `ima_` manifest rows). Quoting, `Re:`/`Fwd:` prefixing, and
threading headers are server-side. Sent mail is stored as
`iem_inbound_email_messages` rows with `iem_direction='outbound'`, sealed at rest on
Private/Fortress domains under the owner's vault
(`InboundEmailMessage::sealAndPersistContent()`, `$sealed_fields`).

Missing entirely: drafts (any persistence), rich text (`textToHtml()` =
`nl2br(htmlspecialchars())`), BCC (no field, no parsing), signatures (no column, no
UI), contacts/autocomplete (To/Cc are bare text inputs; core has no email contact
store — `Address` is postal), member-context panel (nothing).

## Integration-point inventory (decided up front)

Every touch point, so nothing is discovered mid-build:

| Area | Change |
|------|--------|
| `data/inbound_email_message_class.php` | New `iem_direction` value `'draft'`; new columns `iem_draft_state`, `iem_bcc`; both added to `$sealed_fields` |
| `includes/MailboxService.php` | Every list/count/thread query excludes `direction='draft'` except the new Drafts view; Drafts bucket + unread-style count in `listMailboxes()` payload |
| `includes/MailboxSender.php` | BCC parse/send/store; HTML body path (sanitize + derive plaintext); draft-morph on send; inline (cid) upload support |
| `logic/send_logic.php` | New optional params: `bcc`, `body_html`, `draft_id`, `inline_manifest` |
| New logic files | `draft_save`, `draft_get`, `draft_delete`, `contacts`, `contact_delete`, `contacts_import`, `signature_save`, `sender_context` — each `{name}_logic.php` + `{name}_logic_descriptor()`, namespaced `mailbox/…`, session-gated. Nothing under `/ajax/` |
| `data/inbound_email_mailbox_grant_class.php` | New `ieg_signature` column |
| New data class | `data/mailbox_contacts_class.php` (`imc_mailbox_contacts`) |
| Reader mount + JS + CSS | Editor, Bcc row, autocomplete dropdown, Drafts rail entry, autosave, context panel `<aside>`, signature editor modal |
| Full-text search indexer | Must skip `direction='draft'` (drafts stay out of the index — already stated in `mailbox_encryption_at_rest.md`) |
| Two-way IMAP sync | Dirtiness scan / push must ignore `direction='draft'` rows (no `iia_` locator on drafts anyway — verify filters) |
| AI surfaces (triage, summary, `ModelQueryExecutor`) | Verify inbound-only filters; drafts must never be triaged/summarized |
| Retention/purge tasks | Verify direction filters don't sweep drafts; sent-retention untouched |
| Vault re-seal callback (`bootstrap.php`) | Must cover draft rows (same table — verify the iterator includes them) and `imc_` contact rows |
| Mobile apps | `mailbox/send` stays backward-compatible (all new params optional). Draft/contact adoption on iOS/Android is out of scope here |

## Phase 1 — Rich-text composer + BCC

**Bcc.** Add a Bcc field (hidden behind a small "Bcc" toggle link next to Cc, Gmail
style). `MailboxSender` parses it with the existing `parseAddressList()`, passes it
to `EmailSender` as true envelope BCC (verify `EmailSender` exposes PHPMailer
`addBCC`; add pass-through if not). **Bcc is stored in its own sealed column
`iem_bcc` on the outbound row — never merged into `iem_recipient`** — so reply-all
on your own sent message can never re-leak a bcc'd address. Sent-view rendering
shows a separate "Bcc:" line when present.

**Editor.** Replace `#mbx_body` with a `contenteditable` div — minimal, vanilla,
no dependency, per theme rules. Toolbar: bold, italic, underline, bulleted/numbered
list, link, remove-formatting. Keyboard shortcuts (Ctrl/Cmd+B/I/U) work natively.
Paste is sanitized client-side (strip everything outside the allowlist); the client
sanitizer is advisory only.

**Server is authoritative.** `mailbox/send` gains `body_html`. When present,
`MailboxSender` sanitizes it against a strict allowlist (`p, br, div, b, strong, i,
em, u, a[href http/https/mailto], ul, ol, li, blockquote, img[src cid: only]`; all
other tags/attributes/styles stripped — check for an existing core HTML sanitizer
before writing one) and derives `iem_body_plain` from the sanitized HTML (tags
stripped, links rendered as `text <url>`). The plaintext `body` param remains
supported unchanged (mobile apps, degraded clients) — `textToHtml()` stays for that
path. Quote blocks remain server-side, appended below the user's HTML exactly as
today.

**Inline images.** Paste or drag an image into the editor → it is added to the
existing upload machinery as an inline attachment: client inserts
`<img src="cid:{local-id}">`, sends the file in `attachments[]` plus an
`inline_manifest` mapping local-id → filename. Server persists it through the
existing path with `ima_is_inline=true` + `ima_content_id`, rewrites the cid in the
stored/sent HTML, and attaches it as an embedded image on the wire. Counts against
the existing 10/10 MB/25 MB caps; no new limits. (Reading inline images already
works — this reuses that manifest shape.)

## Phase 2 — Saved drafts

**Storage: drafts are `iem_inbound_email_messages` rows with
`iem_direction='draft'`.** No new table. This is the path
`mailbox_encryption_at_rest.md` §"Sent mail and drafts seal under the same model"
anticipated: the row already carries every sealing column (`iem_sealed_key`,
`iem_content_sealed`, `iem_key_generation`, `iem_sealed_owner_user_id`), the
`$sealed_fields` plumbing, attachment manifests, and the alias FK.

Field mapping on a draft row: `iem_iea_inbound_email_alias_id` = From identity;
`iem_subject` / `iem_body_html` / `iem_body_plain` as composed so far;
`iem_recipient` = To + Cc combined (as on outbound); `iem_bcc`; and one new sealed
text column **`iem_draft_state`** — a JSON string holding what a column can't:
`{mode, source_id, to, cc}` split out so reopening restores the exact fields
(`iem_recipient` alone can't distinguish To from Cc). `iem_message_id_header` stays
NULL (the dedup `unique_with` key tolerates it). `iem_thread_key` = the source
message's thread key for reply/forward drafts (so a draft can show a "draft" chip on
its thread later if we want), NULL for new-message drafts.

**Sealing.** At Standard: plaintext, `iem_content_sealed=false`. At
Private/Fortress: `iem_draft_state` and `iem_bcc` join the sealed set alongside
subject/body/recipient, per-draft DEK sealed to the owner's `uev_public_key`, AD
convention `"mail:{id}:{field}"` unchanged. **Autosave never blocks on the unlock
window**: sealing needs only the public key, so a save succeeds even if the window
lapsed mid-compose. Only *reopening* a draft needs the window — locked reopen
returns the standard `locked:true` shape and the client shows the unlock affordance,
same as reading sealed mail.

**Actions.**
- `mailbox/draft_save` — create or update (`draft_id` optional; multipart, same
  attachment handling as send; new files persist immediately as `File` + `ima_` rows
  on the draft). Returns `draft_id`. Auth: `MailboxViewer::canAccess(alias_id)`.
- `mailbox/draft_get` — returns the decrypted compose state + attachment list
  (in-window on protected domains).
- `mailbox/draft_delete` — hard delete: row + its `ima_` rows + backing `File`s.
  A discarded draft should not linger in a trash tier; there is no draft trash.

**Autosave.** Debounced (~3 s after last keystroke) + fired on compose close/park
and on `beforeunload` via `sendBeacon`. First autosave creates the row; subsequent
ones update it. The existing `parkCompose()` (hide panel, keep state) is replaced by
save-and-close — the panel is always safe to close.

**Send.** `mailbox/send` gains optional `draft_id`. On success the draft row
**morphs into the outbound row** (`storeOutboundRow()` writes into it: direction →
`'outbound'`, message-id/thread key set, `iem_draft_state` cleared) so its
already-persisted attachments are reused in place — no re-upload, no copying. In the
Gmail `pending_sent_ingest` case (connected account files Sent itself; no local row
is stored) the draft is deleted instead.

**Reader surface.** A **Drafts** entry in the left rail (a special entry like
"All mail", with count), listing via the existing `thread_list` action with a
`view=drafts` branch (drafts are singletons; grouping is harmless). Clicking a draft
opens the composer populated from `draft_get` instead of the thread pane. Every
other view/query in `MailboxService` explicitly excludes `direction='draft'`.

**Out of the FTS index**, per the encryption spec: drafts are few, in flux, and
opened directly. No IMAP mirroring of drafts (local-only; the platform exposes no
IMAP server, and pushing drafts to a synced account's Drafts folder is not worth the
two-way edge cases).

## Phase 3 — Per-mailbox signatures

**Stored per grant, not per alias:** new `ieg_signature` (text, sanitized HTML) on
`ieg_inbound_email_mailbox_grants`. Rationale: a signature is *personal* ("Jeremy —
Founder"), a grant is exactly (user, mailbox), and this stays correct if shared
mailboxes ever arrive — two grantees of `info@` sign differently. For the individual
product (one user, N mailboxes) it degenerates to per-mailbox, which is the Gmail
send-as model users expect.

**Not sealed.** A signature is a template broadcast in cleartext on every outgoing
message; sealing it protects nothing and adds a decrypt dependency to compose-open.

**Editing:** a signature editor in the reader — small modal opened from a gear on
each mailbox entry in the rail, using the same Phase 1 mini rich editor (image tags
excluded). Saved via `mailbox/signature_save` (auth: own grant only). No admin-page
surface; signatures are personal, not mailbox administration.

**Insertion is client-side and visible:** the `mailbox/mailboxes` payload gains each
mailbox's signature; on compose open the client inserts it into the editor —
below the caret position, above the quote placeholder — where the user can see and
edit it before sending. The server does no injection (what's in the editor is what
sends, sanitized). Applied in all four modes; a per-save empty signature simply
inserts nothing.

## Phase 4 — Contacts + recipient autocomplete + import

**A real per-user contact store**, plugin-local:
`data/mailbox_contacts_class.php` → `MailboxContact` / `MultiMailboxContact`,
table `imc_mailbox_contacts`, prefix `imc`:

- `imc_usr_user_id` (FK, cascade delete)
- `imc_address` (text — sealed when applicable)
- `imc_display_name` (text — sealed when applicable)
- `imc_address_hash` (varchar 64) — deterministic digest of the normalized
  (lowercased, trimmed) address, `unique_with` `imc_usr_user_id`, so upsert/dedup
  works even when `imc_address` is ciphertext. Reuse the blind-index key-derivation
  convention from the sealed search side-index (`MailboxIndex`) for vault holders;
  plain SHA-256 for users with no vault (their address column is plaintext anyway).
- `imc_last_used_time`, `imc_use_count`, `imc_source` (varchar 10:
  `sent` / `received` / `import` / `manual`)
- sealing columns: `imc_content_sealed`, `imc_sealed_key`, `imc_key_generation`,
  `imc_sealed_owner_user_id`; `$sealed_fields = [imc_address, imc_display_name]`,
  AD `"contact:{id}:{field}"`.

**Sealing rule:** rows are sealed iff the owning user holds a vault
(`UserEncryptionVault` exists) — the same identity that seals their mail. Harvest
always happens in-window or at send time, so the plaintext is available when the row
is written; sealing uses only the public key.

**Harvest points** (upsert: bump `imc_use_count`, `imc_last_used_time`, fill
display name if empty):
1. Every successful send — all To/Cc/Bcc addresses (`source='sent'`).
2. Opening a thread — the decrypted counterparty sender (`source='received'`,
   opportunistic, in-window by construction). No bulk backfill scan of sealed
   history; the store warms up through use.

**Autocomplete UX:** on compose open, one `mailbox/contacts` fetch returns the
user's full decrypted list (contact lists are small; this is what keeps sealed
storage compatible with autocomplete — **filtering is client-side**, no server
prefix-search over ciphertext). To/Cc/Bcc inputs get a vanilla dropdown filtering
on address + name, ranked by `use_count` desc then recency; Enter/comma/Tab commits
a chip-free plain address (inputs stay plain text lists — no chip rework in this
spec). When the vault is locked on a protected setup, autocomplete is silently
absent (fetch returns `locked:true`; typing addresses by hand still works).

**Import — the real-account onboarding gap.** `mailbox/contacts_import` accepts an
uploaded vCard (.vcf) or Google-contacts CSV export, parses name + email(s)
(everything else discarded), and upserts through the same path
(`source='import'`). Surface: an "Import contacts" affordance inside the contacts
management view. Parser is minimal and forgiving; a row with no valid email is
skipped, and the response reports imported/skipped counts.

**Future seam (build nothing now):** this table is a disposable autocomplete cache
and must stay one. The identity/relationship layer is a separate future core system
(`specs/FUTURE_verified_connections.md`); when it exists, a contact row gains one
nullable FK to a verified connection. Identity is never retrofitted into this table.

**Management:** a lightweight contacts list (rendered from the same
`mailbox/contacts` payload) with delete (`mailbox/contact_delete`) and manual add
(reuse `contacts_import`'s upsert via a one-row form or a `manual` save param —
decide the smaller surface at implementation). No groups, no sync, no vCard export
in this spec.

## Phase 5 — Member-context panel

Beside an open thread, show who the correspondent is *on this platform* — the
integration differentiator (mail tied to member records).

**Action:** `mailbox/sender_context` — input: thread's counterparty address
(server re-derives it from the source message, in-window; the client sends
`message_id`, not an address, so the endpoint can't be used as an oracle).
**Auth: thread access via `MailboxViewer` AND admin permission (level 5+).** Member
records (orders, registrations) are operator data; a non-admin mailbox grantee does
not get other members' purchase history. On the member reader, non-admins simply
never see the panel.

**Resolution:** `User::GetByEmail()` (case-insensitive). On match, return:
- member card: name, email-verified flag, member-since, link to
  `/admin/admin_user_edit?usr_user_id=N`
- recent event registrations (`MultiEventRegistrant`, `user_id` option, latest 5) —
  only if the event_manager plugin is active
- recent orders (`MultiOrder`, `user_id` option, latest 5, status + total) — only if
  the store plugin is active
- recent core conversations count/link — only if messaging is in use

No match → a single "Not a member" line (no panel chrome beyond that). Plugins
inactive → their section is absent, not empty.

**Surface:** a right `<aside class="mbx-context">` inside `#mbx-reader`, rendered
when a thread opens, collapsible, hidden below a width breakpoint. Fetch is lazy
(after thread render) and cached per address for the session. No configuration,
no explainer text — the panel is its own explanation.

## Settings

None. No new plugin settings, no tier gating: features gate on mailbox grants (and
vault unlock where sealing applies), consistent with the rest of the reader. The
editor, drafts, and autocomplete are unconditional parts of compose.

## Tests

New files under `plugins/mailbox/tests/`, harness + `@joinery-test` headers, run via
`php tests/run.php db --filter=mailbox`:

- `compose_richtext_test.php` — sanitizer allowlist (script/style/event-handler
  stripping, cid-only img), plaintext derivation, bcc parse/envelope/`iem_bcc`
  storage + reply-all never includes bcc, inline manifest → `ima_` rows + cid
  rewrite, plain-`body` path unchanged.
- `drafts_test.php` — save/update/get/delete lifecycle; autosave-creates-once;
  sealed draft round-trip (locked reopen → `locked:true`; save-while-locked
  succeeds); draft-morph on send (attachments reused, direction flip,
  `pending_sent_ingest` deletes); drafts excluded from every non-draft view, FTS
  index, IMAP dirtiness scan, and AI triage; reseal covers draft rows.
- `signatures_test.php` — grant-scoped save auth (can't write another user's
  grant), sanitization, payload exposure in `mailboxes`.
- `contacts_test.php` — harvest on send + thread-open, hash dedup upsert (sealed and
  plaintext), sealed round-trip + reseal, vCard/CSV import (counts, junk-row skip),
  delete, locked fetch shape.
- `sender_context_test.php` — permission gate (level 5 required), resolution
  hit/miss, plugin-inactive sections absent, no-oracle (message_id input only).

Live verification on dev with a real connected account per phase (Playwright),
`logs/error.log` grep after each.

## Docs

When each phase ships, fold it into `plugins/mailbox/docs/overview.md` — extend the
**Mailbox Reader** section (compose subsection: editor, drafts, signatures, bcc;
new short subsections for Contacts and the Member-context panel). Current-state
voice only. No new doc file.

## Out of scope (deliberate)

- **Undo send / scheduled send** — wants a delayed-send queue; real feature, own
  spec if wanted.
- **Canned responses / templates** — team-layer triage (stays in
  `mailbox_group_collaboration.md`).
- **Contact groups, vCard export, contact sync** — store stays minimal.
- **Drafts mirrored to external IMAP Drafts folders** — local-only.
- **Mobile app adoption** of drafts/contacts/context — server contract stays
  compatible; native UI work is its own effort.
- **A heavy editor dependency** — the toolbar above is the ceiling; tables,
  font-pickers, colors are non-goals.

## Decisions (made here, flag disagreement before build)

1. **Drafts reuse the `iem_` table** (`direction='draft'`) rather than a new table —
   the sealing plumbing, attachment manifests, and alias FK come free; the cost is
   direction-filter discipline, paid once in `MailboxService`.
2. **Draft state that doesn't fit existing columns rides one sealed JSON text column**
   (`iem_draft_state`) instead of new to/cc columns.
3. **Bcc gets its own sealed column**, never merged into `iem_recipient` — reply-all
   leak prevention is structural, not behavioral.
4. **Signatures live on the grant** (per user × mailbox), are **not sealed**, and are
   inserted client-side so the user sees exactly what sends.
5. **Autocomplete = fetch-whole-list + client filter**, which is what lets contact
   rows be sealed at rest with zero UX loss. No server-side prefix search ever.
6. **Contact dedup via keyed address hash** (blind-index convention from the sealed
   search index) so upsert works over ciphertext.
7. **Member-context requires admin permission**, and the endpoint takes a message id,
   not an address, so it cannot be used to probe membership by arbitrary email.
8. **Rich text is additive**: the plaintext `body` path survives untouched for mobile
   and any degraded client.
