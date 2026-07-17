# Mailbox Compose Maturity — Fix Pack

**Status:** Ready to execute
**Source:** Multi-agent code review (42 agents, 39 confirmed findings folding into 10 defects) of the
implemented spec `specs/implemented/mailbox_compose_maturity.md` (uncommitted working tree, 2026-07-17).
**Scope:** `plugins/mailbox/`, `plugins/joinery_ai/pipeline_jobs/EmailScheduleJob.php`.

All design decisions below are already made — execute as written. Line numbers are as of the
current working tree; re-locate by symbol if drifted. Every fix lands together (several share
plumbing). No data migration is needed for existing rows (pre-launch, dev data only — see
"no production users" principle); legacy dev drafts without an author simply become invisible.

---

## Verified facts the fixes rely on (do not re-derive)

- `ima_inbound_message_attachments` already has `ima_content_id varchar(255)` and `ima_is_inline`
  (`inbound_message_attachment_class.php:49-62`). Sealing is per-attachment (`ima_is_sealed`),
  independent of the row's `iem_content_sealed` — mixed plaintext/sealed attachments on one
  message are supported by `InboundEmailMessage::openSealedAttachment()` (returns bytes as-is
  when `ima_is_sealed` is false).
- The File decrypt hook in `plugins/mailbox/includes/bootstrap.php:61-63` routes signed-URL file
  serving through `openSealedAttachment`, so `File::mintSignedUrl()` serves sealed inline images
  **decrypted** (in-window). `MailboxService::resolveInlineImages()` (line ~1095) is the existing
  precedent for cid→signed-URL mapping.
- `MailboxIndex` FTS table is a plain FTS5 table (`CREATE VIRTUAL TABLE mailfts USING
  fts5(message_id UNINDEXED, content)`, MailboxIndex.php:117) — **not** contentless, so
  `DELETE FROM mailfts WHERE message_id = ?` works.
- `InboundEmailMailboxGrant::user_ids_for_alias(int $alias_id)` already exists (grant class line 89).
- `InboundEmailMessage::sealAndPersistContent(..., $reuse_dek)`: with `$reuse_dek` it re-seals
  content under the same DEK and leaves `iem_sealed_key`/generation/owner untouched; with null it
  **mints a new DEK and overwrites the wrapping columns** (this is the stranding hazard in Fix 2).
- `InboundEmailMessage::unwrapDekInWindow($owner_id, $sealed_key)` returns null when the owner's
  window is closed (never throws).
- Sealing-owner invariant: a draft's `iem_sealed_owner_user_id` is always the viewer who saved it
  (sealing requires a single-grantee alias, and the viewer holds the grant), so a viewer can never
  hold two sealed mailboxes with *different* owners. The only posture transitions a From-change
  can cause are sealed↔standard and sealed→sealed-same-owner.
- `MailboxHtmlSanitizer` allows `<img src>` only with `cid:` scheme — draft HTML stored via
  `saveDraft` keeps `cid:{localId}` placeholders intact.
- Spec contract (`specs/implemented/mailbox_compose_maturity.md:130-135`): closing the compose
  panel is **save-and-close** ("the panel is always safe to close"); discard is a distinct action
  with no draft trash.
- The client's send/save flows: `buildComposeBody()` (mailbox_reader.js:1690) is shared by
  `autosaveDraft()` (1731) and `submitCompose()` (1885). `pendingFiles` (regular) and
  `inlineImages` (pasted, each with a `localId` used as its `cid:` placeholder) are module-level
  arrays. `state.draftAttachments` renders read-only "saved" chips.

---

## Fix 1 — Draft authorization: drafts belong to their author

**Defect:** `MailboxService::draftScopeSql()` (line 160) scopes drafts only by
`accessibleAliasIds()`. A superadmin (all aliases) sees every user's drafts; co-grantees of a
shared mailbox see each other's drafts. `MailboxDrafts::loadDraftInScope()` and
`MailboxSender::loadDraftInScope()` have the same hole, so foreign drafts can be opened, edited,
sent-as, and deleted.

**Fix — add an author column and scope everything by it:**

1. `inbound_email_message_class.php` `$field_specifications`, next to `iem_draft_state`:
   ```php
   // Draft author (compose maturity fix pack): a draft is PERSONAL compose state,
   // owned by the user who is writing it — never shared with co-grantees of the
   // alias and never visible to an all-access superadmin. Set only while
   // iem_direction='draft'; cleared on morph to outbound.
   'iem_draft_author_user_id' => array('type'=>'int8', 'is_nullable'=>true),
   ```
2. `MailboxDrafts::saveDraft()` insert path: `$row->set('iem_draft_author_user_id',
   $this->viewer->getUserId());`
3. Both `loadDraftInScope()` implementations (MailboxDrafts.php:236, MailboxSender.php:594):
   additionally require `intval($row->get('iem_draft_author_user_id')) ===
   $this->viewer->getUserId()` — a null/legacy author fails closed.
4. `MailboxService::draftScopeSql()` (160): append
   `" AND iem_draft_author_user_id = " . intval($this->viewer->getUserId())`.
5. The Drafts rail count in `MailboxService::listMailboxes()` (line ~286-292): add the same
   author predicate to the COUNT query.
6. Morph clears it — see Fix 5's `$cols` addition (`iem_draft_author_user_id => null`).

**Accept:** user B (co-grantee of A's shared mailbox) and a superadmin each see a drafts count of
0 and cannot `draft_get`/`draft_save`/`draft_delete`/send-morph user A's draft id.

---

## Fix 2 — Sealed send with a lapsed window must fail loudly, before SMTP

**Defect:** two silent-loss paths on a sealed draft-morph send with the window closed:
`attachStoredAttachments()` (MailboxSender.php:636) catches `VaultLockedException` and `continue`s
(mail delivered without its attachments, no error), and `storeOutboundRow()`'s morph branch
(1085) gets `$reuse_dek = null` from `unwrapDekInWindow`, so `sealAndPersistContent` mints a NEW
DEK and overwrites `iem_sealed_key` — permanently stranding the attachment ciphertext.

**Fix — unwrap the DEK once, up front, and thread it through:**

1. New private `MailboxSender::assertDraftSendable(InboundEmailMessage $draft): ?string` —
   called in `send()` right after `loadDraftInScope` (line ~216), **before** any attach/SMTP work:
   - If `$draft->get('iem_sealed_key')` is empty → return null (nothing sealed anywhere on it).
   - Resolve owner: `intval($draft->get('iem_sealed_owner_user_id'))`, falling back to
     `InboundEmailMessage::singleOwnerUserId(alias)`.
   - `unwrapDekInWindow(owner, sealed_key)` — null → `throw new MailboxLockedException('Your
     vault is locked — unlock it to send this draft.')`. (Nothing has gone to the wire yet, so
     the client's existing locked→unlock→resubmit flow is safe.)
   - Return the raw DEK.
2. `attachStoredAttachments($email, $draft, ?string $dek)`: for a sealed attachment row, decrypt
   with the **passed** DEK directly (`VaultCrypto->openField($bytes, $dek,
   InboundEmailMessage::attachmentAd($id, $att->get('ima_mime_part')))`) instead of
   `openSealedAttachment` — eliminates the TOCTOU window entirely. A sealed attachment with a
   null `$dek` throws `MailboxLockedException` (defensive; unreachable after the preflight).
   **Delete the `catch (VaultLockedException) { continue; }`.** Plaintext attachments
   (`ima_is_sealed` false) pass through unchanged.
3. `storeOutboundRow(...)` gains a `?string $morph_dek = null` parameter (pass the preflight DEK
   from `send()`). Morph branch: `$reuse_dek = $morph_dek`. If `$sealing && $morph sealed &&
   $reuse_dek === null` → **throw** (defensive) rather than let `sealAndPersistContent` mint a
   fresh DEK over a sealed draft's wrapping.

**Accept:** with a sealed draft carrying attachments and the window forcibly closed, `send`
returns `locked:true` and delivers nothing; after unlock, resubmit delivers with attachments and
the Sent copy's attachments open correctly.

---

## Fix 3 — Autosave must not re-upload attachments; send must not double-attach

**Defect:** every autosave re-sends `pendingFiles`/`inlineImages` bytes and
`persistDraftUploads()` appends new manifest rows each time (duplicates accumulate until the
count cap breaks autosave); send with a `draft_id` attaches both the stored copies AND the same
request files.

**Fix — server confirms what is persisted; client stops resending it:**

Server:
1. `MailboxDrafts::saveDraft()` returns `array('draft_id' => $id, 'attachments' =>
   $this->draftAttachments($id))` (the regular, non-inline list — existing helper).
2. `draft_save_logic.php` passes `attachments` through in the render payload (and
   `inline_manifest` — see Fix 7).
3. New `MailboxDrafts::deleteDraftAttachment(int $draft_id, int $attachment_id): bool` —
   loadDraftInScope (author-checked per Fix 1), verify the `ima_` row belongs to the draft and
   `ima_is_inline` is false, permanent-delete the backing File then the manifest row.
4. New logic endpoint `plugins/mailbox/logic/draft_attachment_delete_logic.php`
   (`mailbox/draft_attachment_delete`), modeled exactly on `draft_delete_logic.php`
   (session + `canCompose` + `_logic_descriptor` opt-in). Params: `draft_id`, `attachment_id`.
5. `mailbox_reader_mount.php` config: add
   `'draftAttachmentDeleteUrl' => '/api/v1/action/mailbox/draft_attachment_delete'`.

Client (`mailbox_reader.js`):
6. `buildComposeBody(includeFiles)` returns `{ body, sentFiles, sentInline }` — snapshots of
   exactly which `pendingFiles` entries and unsaved `inlineImages` entries were appended.
   (`submitCompose` keeps using `.body`.) Inline entries gain a `saved` flag; a saved inline
   image is **never** re-appended and never re-listed in `inline_manifest`.
7. `autosaveDraft` success handler (same-generation only, see Fix 4): remove exactly the
   `sentFiles` entries from `pendingFiles` (files added mid-flight survive), set `saved = true`
   on the `sentInline` entries, replace `state.draftAttachments` with the response `attachments`,
   `renderAttachStrip()`.
8. Saved chips in `renderAttachStrip()` get a remove (×) button posting to
   `CFG.draftAttachmentDeleteUrl`; on success drop the entry from `state.draftAttachments`.
9. `submitCompose`: if `state.draftSaving`, wait for the in-flight save to settle before building
   the send body (bounded retry, e.g. every 250 ms up to ~5 s) — prevents the same file arriving
   via both the request and a concurrent draft-save persist.

**Accept:** attach one file, wait for two autosaves, send → the received message and Sent copy
carry the attachment exactly once and the draft manifest never grew past one row. A saved chip's
× removes it from the draft (verified by reopening).

---

## Fix 4 — Stale autosave callback must not resurrect a closed draft's id

**Defect:** `autosaveDraft`'s resolve handler (mailbox_reader.js:1742) writes `state.draftId`
unconditionally; `closeCompose`'s save-and-close starts the save then synchronously
`resetDraftState()`, so the resolved id lands in whatever compose is open next — the next
autosave then overwrites the old draft with the new message's content.

**Fix:** module-level `var composeGen = 0;` incremented in `resetDraftState()`. `autosaveDraft`
captures `var gen = composeGen;` at entry; the resolve/catch handlers always clear
`state.draftSaving`, but apply **nothing else** (no `draftId`, no `draftDirty`, no Fix-3 state
moves) when `gen !== composeGen`.

**Accept:** write draft A → close (save-and-close) → immediately open New Message and type →
draft A's content is intact in the Drafts list and message B saves under its own new draft id.

---

## Fix 5 — A From-alias change must persist to the draft and the morphed Sent row

**Defect:** neither `MailboxDrafts::saveDraft()`'s update path (writes `$content` only) nor
`storeOutboundRow()`'s morph UPDATE (`$cols`, line 1052-1091) writes
`iem_iea_inbound_email_alias_id`/`iem_ied_inbound_email_domain_id` — a draft keeps the identity
it was first autosaved under, so the Sent copy files into the wrong mailbox (and `submitCompose`'s
`clearTimeout` means the last alias change often never reached the draft at all).

**Fix:**

1. `saveDraft()` update path — add to `$content`:
   ```php
   'iem_iea_inbound_email_alias_id'  => $alias_id,
   'iem_ied_inbound_email_domain_id' => intval($alias->get('iea_ied_inbound_email_domain_id')),
   ```
2. Posture transitions on update (the alias change can flip sealing):
   - **sealed → sealed** (same owner, always — see invariant above): existing reuse-DEK logic
     stands, but unwrap with the **row's** recorded owner
     (`iem_sealed_owner_user_id`, fallback to alias-derived) rather than the new alias's.
   - **standard → sealed:** already works — `$reuse_dek` stays null, a fresh DEK is minted for
     content; existing plaintext attachments stay plaintext (`ima_is_sealed` per-file, readable).
   - **sealed → standard:** add `'iem_content_sealed' => false` to `$content` for this case and
     **keep** `iem_sealed_key`/`iem_sealed_owner_user_id` untouched — the already-sealed
     attachments stay decryptable through them (per-attachment `ima_is_sealed` governs reads).
3. `storeOutboundRow()` morph branch — add to `$cols`:
   ```php
   $cols['iem_iea_inbound_email_alias_id']  = intval($alias->key);
   $cols['iem_ied_inbound_email_domain_id'] = intval($alias->get('iea_ied_inbound_email_domain_id'));
   $cols['iem_draft_author_user_id']        = null;   // no longer a draft (Fix 1)
   if (!$sealing && $morph->get('iem_content_sealed')) {
       $cols['iem_content_sealed'] = false;           // sealed draft sent from a standard alias
   }
   ```
   (With Fix 2, the sealed-morph DEK comes in as `$morph_dek`; the `iem_sealed_key` wrapping is
   never overwritten on a reuse.)

**Accept:** create a draft under mailbox A, reopen, switch From to mailbox B, send → mail
delivered from B, Sent copy listed under B (not A), replies thread into B. Repeat across a
sealed→standard pair: Sent copy readable, draft's attachments intact on it.

---

## Fix 6 — Sent-from-draft rows must enter the full-text index

**Defect:** `MailboxIndex::foldSince()` (line 247) skips draft rows but advances
`imi_fts_high_water` past their ids via later folded rows; when the draft later morphs **in
place** into the Sent row (same id ≤ watermark), the `id > since_id` query never revisits it —
the sent message is permanently unsearchable.

**Fix — an explicit refold queue on the per-user index bookkeeping:**

1. `inbound_mailbox_search_index_class.php` `$field_specifications`: add
   ```php
   // Row ids at-or-below the high-water mark that changed after folding (a draft
   // morphed into its Sent row keeps its id) — folded on the next cycle, then cleared.
   'imi_refold_ids' => array('type'=>'text', 'is_nullable'=>true),   // JSON int array
   ```
2. `MailboxSender::send()` — after a successful `storeOutboundRow` **when `$draft !== null`**
   (the morph case), best-effort (try/catch, log, never fail the send): for each
   `InboundEmailMailboxGrant::user_ids_for_alias(intval($alias->key))`, load that user's
   **existing** `imi_` row only (do NOT `loadOrCreateForUser` — a user with no index row
   rebuilds from 0 on first search and needs no queue), append the row id to the decoded
   `imi_refold_ids` array (dedup), save.
3. `MailboxIndex::foldSince()` restructure:
   - Load `$bookkeeping` once at the top; decode `$refold` from `imi_refold_ids`.
   - If both `$refold` and the `> since_id` result set are empty → return (keep the early-out).
   - Open the shm DB once. **Refold pass:** for each refold id — `DELETE FROM mailfts WHERE
     message_id = :id`, then re-insert content iff the row still exists, is non-deleted,
     non-draft, and its alias is in the user's scope set (reuse the same content-build as the
     main loop — factor the sender/subject/body/attachment-names concatenation into a private
     `rowContent(int $id): ?string`).
   - **Main pass:** unchanged, but skip ids present in the refold set (already handled).
   - Single bookkeeping save at the end: watermark as today, `imi_refold_ids` cleared (null).

**Accept:** autosave a draft, receive any newer message, search (folds; watermark passes the
draft), send the draft → a new search finds the sent message's body text; `imi_refold_ids` is
null afterward and the FTS table has exactly one row for that message id.

---

## Fix 7 — Inline (pasted) images must survive the draft round-trip

**Defect:** `saveDraft` ignores `inline_manifest`, so pasted images persist as regular
(`ima_is_inline=false`) attachments with no Content-ID while the stored HTML keeps unresolvable
`cid:{localId}` srcs — a reopened-in-a-new-session draft sends broken image placeholders plus
loose attachments.

**Fix — the draft stores inline parts under their localId as Content-ID:**

1. Make `MailboxSender::parseInlineManifest()` and `matchInlineLocalId()` `public static`
   (neither uses `$this`; update the internal call sites).
2. `draft_save_logic.php`: pass `'inline_manifest' => $input['inline_manifest'] ?? ''` through.
3. `MailboxDrafts::saveDraft()`/`persistDraftUploads()`: parse the manifest; an upload matched by
   `matchInlineLocalId` persists with `ima_is_inline => true`, `ima_content_id => {localId}`,
   part id `'draftinl:' . $message_id . ':' . $index . ':' . bin2hex(random_bytes(3))`. Caps
   apply to inline and regular alike (as in `attachUploads`).
4. **Prune stale inline parts:** after a save where `body_html_param !== ''`, delete (File + row)
   any inline attachment of the draft whose `ima_content_id` does not appear as
   `cid:{content_id}` in the sanitized HTML — the user deleted the image from the editor.
5. `getDraft()`: also return `'inline' => [{content_id, url, filename}]` for the draft's inline
   rows — `url` is `File::mintSignedUrl('original', <ttl as resolveInlineImages uses>, 'full')`
   (the bootstrap decrypt hook serves sealed bytes decrypted; getDraft already gates on an open
   window for sealed drafts).
6. Client `populateComposerFromDraft()`: before `setComposerHtml`, rewrite each
   `cid:{content_id}` img src to its `url` and set `data-mbx-cid="{content_id}"` — so
   `composerHtml()` naturally re-emits `cid:{content_id}` on later saves/sends and the editor
   displays the image.
7. Send-from-draft: new `MailboxSender::attachStoredInline(EmailMessage $email,
   InboundEmailMessage $draft, string $userHtml, ?string $dek): int` (called next to
   `attachStoredAttachments`, sharing the Fix-2 DEK and the running-total cap): for each stored
   inline row whose `cid:{content_id}` appears in `$userHtml`, embed via
   `$email->attachInlineData($bytes, $content_id, $name, $type)` — the outgoing HTML already
   carries `cid:{content_id}`, so no rewrite is needed. Unreferenced stored inline parts are
   skipped (already pruned at step 4 in the normal flow).

**Accept:** paste an image, let it autosave, close the tab, reopen the draft in a fresh session
(image visible in the editor), send → recipient's HTML renders the image inline; Sent copy
renders it via the thread view; no loose attachment chip.

---

## Fix 8 — The beforeunload save must fit the keepalive budget

**Defect:** the `beforeunload` path (mailbox_reader.js:2016) posts the full multipart body with
`keepalive:true`; browsers cap keepalive bodies at ~64 KB, so any draft with attachment/pasted
image bytes pending is rejected outright and lost.

**Fix:** `buildComposeBody(includeFiles)` — the `sync` path calls it with `includeFiles=false`:
no `attachments[]` appends, no `inline_manifest`. Fields-only stays under the cap; file bytes
were already persisted by the debounced autosaves (Fix 3), so the last-ditch save now loses at
most <3 s of typing, never the attachments.

**Accept:** attach a 5 MB file, wait one autosave, edit the subject, close the tab within 3 s →
reopened draft has the file AND the final subject.

---

## Fix 9 — EmailScheduleJob must exclude drafts

**Defect:** the drafts-are-never-AI-read invariant (`$ai_read_filter`) was added to
`EmailTriageJob` (line 80) and `EmailSecurityScanJob` (line 83) but not the third sibling —
`EmailScheduleJob.php`'s `nextItem()` query has no direction predicate, so an unsealed
half-written draft gets sent to the LLM and its recipe-log dedup mark suppresses the real scan
of the later sent version.

**Fix:** add `AND iem_direction IS DISTINCT FROM 'draft'` to the WHERE clause, matching the
siblings verbatim. Bump the file's `@version`.

**Accept:** a draft row on a schedule-recipe mailbox is never returned by `nextItem()`; the same
content sent (morphed, same id) IS returned.

---

## Fix 10 — Compose × is save-and-close; discard is explicit

**Defect:** the panel's only visible close control (`#mbx-compose-close`, wired at
mailbox_reader.js:1995 to `closeCompose(true)`) hard-deletes the saved draft with no
confirmation — violating the spec's "always safe to close" contract.

**Fix:**

1. `mailbox_reader_mount.php` compose head (line ~109-112): `×` title becomes "Save & close";
   add a discard control beside it, e.g.
   `<button type="button" class="mbx-iconbtn" id="mbx-compose-discard" title="Discard draft">🗑</button>`
   (match existing `.mbx-iconbtn` styling; add a small CSS rule if the glyph needs sizing).
   Bump the mount file version.
2. `mailbox_reader.js` wiring: `#mbx-compose-close` → `closeCompose()` (save-and-close);
   `#mbx-compose-discard` → `if (state.draftId || hasComposeContent()) { if (!confirm('Discard
   this draft?')) return; } closeCompose(true);`. Update the now-wrong comment block at 1992.

**Accept:** × on a composed message then reopening from Drafts shows the content; 🗑 prompts and,
on confirm, the draft row + manifest + Files are gone.

---

## Cross-cutting execution notes

- **Schema sync:** two new columns (`iem_draft_author_user_id`, `imi_refold_ids`). After the data
  class edits, run plugin sync ("Sync with Filesystem" on the admin Plugins page, or
  `update_database` from admin utilities — its final step syncs plugins). This is a DB write —
  per CLAUDE.md, get explicit user confirmation before running it.
- **Ordering:** land the data-class fields + sync first (Fixes 1/6 depend on the columns), then
  server (MailboxDrafts, MailboxSender, MailboxService, MailboxIndex, logic endpoints,
  EmailScheduleJob), then mount + JS.
- **Client/server compatibility:** the client changes (Fix 3/4/7/8/10) assume the server
  changes are live; there is no staged rollout to worry about (single dev deploy).
- **Version bumps** in every touched file header that carries one (MailboxDrafts 1.0 → 1.1,
  draft_save_logic 1.0.0 → 1.1.0, send_logic 1.3.0 → 1.4.0, mount, reader JS, etc.).
- **Validation:** `php -l` every touched PHP file, then
  `php maintenance_scripts/dev_tools/validate_php_file.php <file>` (safe for these class/logic
  files — none has a run-on-include body).
- **Tests** (harness + `@joinery-test` header, per docs/testing.md), extend the existing suites:
  - `plugins/mailbox/tests/drafts_test.php`: author-scope authz (co-grantee + superadmin denied:
    list count, get, save, delete, send-morph); alias-change persistence (draft row + morphed
    Sent row alias/domain); autosave-twice attachment count stays 1; deleteDraftAttachment;
    inline manifest persistence + prune; sealed→standard posture flip keeps attachments readable.
  - New checks (same file or a small `drafts_fts_test.php`): refold queue — fold, morph, fold
    again → FTS hit, queue cleared, no duplicate FTS rows.
  - Sealed-window test (db tier, mirroring the suite's existing VaultUnlock usage): closed-window
    sealed morph send → locked error, no wire send, `iem_sealed_key` unchanged.
  - `plugins/joinery_ai/`: extend the triage/scan draft-exclusion coverage to EmailScheduleJob.
  - Browser verification of the client flows (autosave dedup, ×/discard, reopen with inline
    image) on `https://dev.getjoinery.com` per the review checklist.
- **Docs:** update `plugins/mailbox/docs/overview.md` (drafts section) to the end state —
  author-owned drafts, save-and-close vs discard, inline-image round-trip, refold queue — in
  current-state voice (no "previously"/"now" narration).
- **On completion:** move this file to `specs/implemented/`.
