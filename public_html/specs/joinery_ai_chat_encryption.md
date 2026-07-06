# AI Chat — Encryption at Rest (Vault Consumer, Per-Conversation Levels)

**Status:** Ready for implementation
**Version:** 1.0
**Design authority:** `specs/sealed_vault_core.md` (v1.0) — the vault provides the keypair,
unlockers, unlock window, `VaultCrypto`, and the two hooks. This spec is chat's **consumer**
side: what seals, the per-conversation levels, and the unlock-first locked-state contract.
The levels doctrine and locked-state contract mirror
`specs/inbound_email_security_levels.md`; the crypto mirrors
`specs/inbound_email_encryption_at_rest.md`. Chat reuses both — it does not re-derive them.
**Depends on (build first):** `sealed_vault_core.md` (and its retarget of the mail package)
+ `passkeys_core_executor.md` (context `vault-kek`).
**Plugin:** `joinery_ai` (not affected by the inbound_email→mailbox rename).

## The three levels, for chat

Level attaches to the **conversation** (`aic_conversations` is the top unit — no
workspace/agent grouping above it). Same three stacked questions as mail, but Fortress means
something chat-specific:

| | **Standard** | **Private** | **Fortress** |
|---|---|---|---|
| One-line meaning | The server manages this chat for you | Only you can read this stored chat | Your chat content never leaves your hardware |
| Stored turns/title/tool-traces/attachments sealed at rest | — | ✓ | ✓ |
| Unlock required to read/continue | — | ✓ (in the vault window) | ✓ |
| Inference provider | any (cloud or local) | any (operator's choice) | **local model only** — pinned |
| Best for | quick questions, low-stakes | chats worth keeping private | the chat that must never touch a third party |

Chat's off-box-plaintext leak is the **LLM provider** (there is no relay, no sending
identity), so Fortress = Private-at-rest **+ inference pinned to a local model**. Honest
limit to carry in copy: Fortress does **not** beat the active-session residual (a server
compromised while you're mid-turn reads your live prompt either way); what it removes is the
*provider's* copy. True claim: "never leaves your hardware — not to us at rest, not to any
AI provider in flight," not "unreadable even when hacked."

**Chat is unlock-first.** You read responses as they stream, so continuing a Private/Fortress
chat means unlocking first (one tap in the shared vault window), then chatting freely; turns
seal as they finalize and decrypt for display in-window. There is **no passive
receive-while-locked** path to design (unlike mail) — you're never passively handed a chat.

## What seals vs. what stays cleartext (anchored to real columns)

No counterparty axis (it's you and the model), so the rule is simply: **seal the content,
keep the skeleton the list needs.**

**Sealed at rest (content), when the conversation is Private/Fortress:**
| Column | Table | Note |
|---|---|---|
| `aim_content` | `aim_conversation_messages` | user prompt AND assistant reply (role decides) |
| `aim_tool_calls` | `aim` | tool names + args + results (jsonb → see schema note) |
| `aim_pending_action` | `aim` | proposed mutating tool-call args (jsonb → schema note) |
| `aim_error` | `aim` | may echo provider/content detail |
| `aic_title` | `aic_conversations` | auto-derived from the first message = content in miniature |
| `aic_instructions` | `aic` | per-chat system-prompt override (user-authored) |
| `aia_extracted_text` | `aia_message_attachments` | text extracted from an uploaded file |
| attachment **bytes** | core `fil_files` (`fil_source=ai_chat_upload`) | via the vault File decrypt hook |

**Cleartext (operational metadata):** `aim_role`, `aim_status`, token counts, `aim_activity`
(stage label — but see note), `aic_owner_user_id`, `aic_model`, `aic_pinned`, capability
toggles, sampling params, times, ordering, `aic_security_level`. These let the list render
and sort while locked. (`aim_activity` can name a tool mid-turn; it's transient and cleared
at finalize, so keep it cleartext but ensure it never lands a *content* fragment — stage
labels only.)

**Schema notes (mirror mail's widen-for-ciphertext):**
- Widen `aic_title` `varchar(255)` → `text` (ciphertext + base64 overhead exceeds 255).
- `aim_tool_calls` / `aim_pending_action` are `jsonb` with ORM `$json_vars` auto-decode —
  ciphertext is not valid JSON, so for sealed conversations they must hold a **text** blob.
  Change both to `text`, remove them from `$json_vars`, and let the seal/unseal path do
  `json_encode`/`json_decode` around the ciphertext (Standard convos store plain JSON text;
  Private/Fortress store ciphertext). `aim_content`, `aic_instructions`, `aim_error`,
  `aia_extracted_text` are already `text`.
- Add the per-item sealed DEK column to `aim` and `aic` (e.g. `aim_sealed_key`,
  `aic_sealed_key`, text) + `aim_key_generation`/`aic_key_generation` (int, for rotation),
  per the vault consumer contract. Attachments seal under the owning message's DEK (no
  per-attachment key), resolved via the vault File decrypt hook.

## Phase 0 — Preflight

Branch `ai-chat-encryption`. Confirm the vault core exists (`SealedBox`, `VaultUnlock`,
`VaultCrypto`, `uev`/`uew`) and passkeys' `vault-kek` context. AD convention for chat items:
`chat:{aim_message_id}:{field}` for message columns, `chat:conv:{aic_conversation_id}:title`
/ `:instructions` for conversation columns, `chat:{aim_message_id}:att:{aia_attachment_id}`
for attachment bytes.

## Phase 1 — The level field + provider default

- Add `aic_security_level` (`varchar(20)`, default `'standard'`) to
  `AiConversation::$field_specifications` (`data/ai_conversations_class.php:23`). Operational
  metadata (cleartext).
- Add a plugin-wide default setting `joinery_ai_default_chat_level` (default `'standard'`) in
  `settings_form.php` so a user can make **all new chats** Private by default; the create
  path stamps it. A per-conversation override lives in the existing chat controls
  (`logic/chat_controls_logic.php`) — a small level selector, outcome-language only.
- New conversations are created in `chat_send_logic.php` (the `$is_new` branch, ~65–70) and
  its web mirror `views/admin/chat_send.php:75` — stamp `aic_security_level` there from the
  default (or the composer's choice).

## Phase 2 — Seal-on-write

Seal only when the conversation's level is `private` or `fortress` (Standard = plaintext,
unchanged). Sealing needs only the vault **public key** (available at rest), but chat is
unlock-first anyway, so a window is open during any turn. Insertion points:
- **User turn** (`chat_send_logic.php:100–105`): seal `aim_content` before insert.
- **Assistant finalize** (`ChatTurn::runAndFinalize()` `includes/ChatTurn.php:22–55`, sets
  `aim_content` 43, `aim_tool_calls` 44, `aim_pending_action` 46; and `resumeAndFinalize()`
  62–102): seal these before `save()`. This is the authoritative seal-on-write boundary.
- **Title** (`ChatTurn::deriveTitle()` written at `chat_send_logic.php:70` /
  `views/admin/chat_send.php:75`): seal `aic_title` before set. Rename path
  (`chat_thread_action_logic.php:43`) seals the new title too.
- **Instructions** (`aic_instructions`): seal on the controls-save path; it's read at send
  time (Phase 3) to build the system prompt.
- **Attachment extracted text** (`aia_extracted_text`) + **bytes**: seal the extracted text
  under the message DEK; seal the `File` bytes via the vault File decrypt hook
  (`fil_source=ai_chat_upload`) — the same generic hook mail uses.

### Streaming — the one awkward case

The assistant reply streams token-by-token into `aim_content` via `ChatAsync::streamSink()`
(`includes/ChatAsync.php:66–95`, `save()` repeatedly), and `chat_poll` reads the partial for
live display. Sealing every incremental save is wasteful and defeats the partial read.
**Resolution (mirrors the vault's tmpfs discipline):** stream the growing buffer into a
RAM/tmpfs scratch keyed to `aim_message_id` (or APCu), **not** into the DB column; `chat_poll`
reads the scratch; at finalize, `runAndFinalize()` seals the complete buffer into
`aim_content` and clears the scratch. This keeps `aim_content` sealed-only at rest.
*Simpler fallback:* keep the partial plaintext in `aim_content` during the active turn and
seal at finalize — bounded to the in-window active turn, but a brief plaintext-at-rest
window; state it honestly if chosen. Recommend the tmpfs scratch.

## Phase 3 — Decrypt-on-read (all in-window)

Every read calls `VaultUnlock::secretKey($user_id)`; null → locked (Phase 4). Points:
- **Model history** (`ChatRunner::buildHistoryMessages()` `includes/ChatRunner.php:197–`,
  reads `aim_content` at 235, attachment text at 266): decrypt each turn's content +
  attachment extracted-text in-window before building the provider payload. `aic_instructions`
  decrypts here too (system prompt).
- **The single structured-read funnel** `ChatSerializer::message()`
  (`includes/ChatSerializer.php:90–118`: `aim_content` 104, `aim_error` 106, `aim_tool_calls`
  110, `aim_pending_action` 94, attachments 109): decrypt here so native (`chat_thread`),
  poll, and thread all inherit it. Also `ChatSerializer::conversationSummary()` (17) for the
  title.
- **Live poll** (`chat_poll_logic.php:27–58`, partial at 58): reads the tmpfs scratch during
  streaming (Phase 2), and the sealed-then-decrypted `aim_content` once finalized.
- **HTML web transcript** (`includes/ChatRender.php`, `chat_view_body.php`): decrypt in-window
  before rendering bubbles.
- **Attachment helpers** (`AiMessageAttachment::displayListForMessage()` 101,
  `conversationRefs()` 134): decrypt `aia_extracted_text` in-window.

## Phase 4 — Locked-state contract (unlock-first)

Mirror mail's contract; simpler because there's no receive-while-locked. Rule: **list shows
metadata; every content action becomes a one-tap unlock prompt, then resumes.**
- **Conversation list** (`chat_list` / `ChatSerializer::conversationSummary()`
  `ChatSerializer.php:17`, returns id/title/pinned): when the owner has protected chats and
  the vault is locked, return times/pinned/model/counts normally, **replace `title` with a
  sealed placeholder**, add `locked: true`. `chat_view_body.php` (list title 46/56) and
  `views/admin/chat_list.php:43` render the placeholder.
- **Open a conversation** (`chat_thread` `logic/chat_thread_logic.php`, owner check 23–27):
  a protected conversation while locked returns `locked` + metadata, no turns; the client
  prompts unlock (the vault `vault-kek` passkey ceremony), then re-loads and decrypts.
- **Send / continue** (`chat_send`): a protected conversation requires an open window (to
  stream + seal); locked → prompt unlock first.
- **Search.** `MultiAiConversation::getSearchResults()` (`ai_conversations_class.php:97–138`)
  does `aic_title ILIKE` + `aim_content ILIKE` — this **breaks** on sealed columns. Replace:
  search over protected chats runs **in-window**, decrypting and filtering in PHP (chat
  volume is far lower than a mail archive, so no FTS5 index is needed — flag FTS5 reuse of
  the mail `MailboxIndex` pattern only if volume ever warrants it). Locked search prompts
  unlock. Standard chats keep the SQL ILIKE path.
- **Native `/api/v1`**: the `locked` flag on `chat_list`, `chat_thread`, `chat_poll`;
  metadata-returning, content-withholding, triggering the native unlock ceremony — identical
  discipline to the mail actions.

## Phase 5 — Fortress = local-inference enforcement

`LlmProviderFactory::forModel()` (`includes/llm/LlmProviderFactory.php:25–33`) is the single
choke point every turn passes through. Add a conversation-aware check (a `forConversation(
AiConversation $c)` or a param): when `aic_security_level === 'fortress'`, the resolved
provider **must** be `local()` (the `OpenAiCompatibleProvider` at `joinery_ai_local_base_url`,
default Ollama `localhost:11434`) — reject any `claude-*`/Fireworks/cloud model with a clear
error. Also:
- Gate the model `<select>` in `includes/chat_view_body.php:92` to local models only for a
  Fortress conversation.
- Validate in `logic/chat_controls_logic.php` so a Fortress chat can't be switched to a cloud
  model, and switching a chat *to* Fortress requires its model already be (or become) local.
- There is **no provider allowlist today** — it's net-new. A Fortress chat with no local
  model configured is an invalid state the setup surfaces (like mail Fortress requiring the
  relay).

## Phase 6 — Vault integration (rotation + backfill + one unlock)

- **One unlock, shared window.** Chat reads the same `VaultUnlock` window mail opens — a
  single passkey tap covers both. No chat-specific key or window.
- **Rotation callback.** Register chat's re-seal callback with the vault key-rotation
  ceremony: re-seal each protected conversation's + message's DEK to the new keypair (bump
  `aim_key_generation`/`aic_key_generation`); content blobs untouched.
- **Backfill (pre-launch).** Raising a conversation Standard→Private converges its rows to
  sealed form (seal content columns + attachment extracted-text + bytes), idempotent
  (`aic_key_generation`/a sealed marker). No production data to preserve.

## Phase 7 — Settings & docs

- Settings (`settings_form.php`): `joinery_ai_default_chat_level` (default `standard`); the
  Fortress local-provider requirement reuses the existing `joinery_ai_local_*` settings.
- One disclosure line on protected-chat AI settings (levels doctrine): *chats send message
  text to your configured provider; Fortress pins a local model so nothing leaves the box.*
- Docs: `plugins/joinery_ai/docs/` (or the plugin overview) — a "Chat encryption" section:
  the three levels, per-conversation unit, the seal/cleartext split, unlock-first behavior,
  and Fortress local-only (current-state voice); cross-reference `docs/sealed_vault.md`.

## Phase 8 — Verification (acceptance gate)

8.1 `php -l` + `validate_php_file.php` on every edited PHP file.

8.2 On `dev.getjoinery.com` (local model via the Mac-mini Ollama already configured):
- **Level + default:** new chats inherit `joinery_ai_default_chat_level`; a per-chat override
  works; Fortress is unavailable with no local model configured.
- **Seal at rest:** a Private chat's `aim_content`/`aic_title`/`aim_tool_calls` are ciphertext
  in psql; `aim_sealed_key` populated.
- **Unlock-first:** continuing a Private chat while locked prompts one-tap unlock, then
  streams; the streaming partial reads from the scratch, finalizes sealed.
- **Locked list/open:** conversation list shows times/counts with a sealed title placeholder
  + `locked`; opening prompts unlock; search prompts unlock then filters in-window.
- **Fortress local-only:** a Fortress chat refuses a `claude-*` model at
  `LlmProviderFactory::forModel()`; the model picker offers only local models.
- **One unlock, both consumers:** unlocking for mail also opens chat (shared vault window),
  and vice versa.
- **Rotation:** vault key rotation re-seals chat DEKs; old key no longer opens new turns.

8.3 `batcat` for each edited file (do not run them).

## Open Items to Confirm During Implementation

- The tmpfs/APCu streaming-scratch mechanism vs. the transient-plaintext-column fallback
  (Phase 2) — pick against how `chat_poll` and `ChatAsync::streamSink` actually interleave.
- Whether the level override belongs in `chat_controls` or a composer-time picker (Phase 1).
- Confirm the ORM `$json_vars` removal for `aim_tool_calls`/`aim_pending_action` doesn't
  break Standard-chat readers that expect auto-decoded arrays (adjust those call sites).
- Whether chat search ever needs the FTS5 index (volume) or in-window decrypt-filter suffices
  (Phase 4) — default to decrypt-filter.
- The vault re-seal-callback registration mechanism (shared with the vault spec's open item).
