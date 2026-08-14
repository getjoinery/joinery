# Spec: Sealed-at-rest human conversations (server-custody encryption for messaging)

**Status:** BUILT 2026-08-14, as the **Private** rung of
`specs/implemented/joinery_messenger.md` § Protection levels, which absorbed this
design and resolved its open questions (§7.6 there). This file stays as the
design record; the current-state developer documentation is
`docs/social_features.md` § Protection levels.
**Area:** core messaging (`data/conversations_class.php`, `data/messages_class.php`) + Sealed Vault server-custody stack
**Companion to:** `messaging_ai_participant.md` (the in-room AI — this spec is designed to *coexist* with it, §5)
**Contrast with:** `DEFERRED_client_custody_mail.md` (the zero-knowledge / client-custody posture — a different, larger, mutually-exclusive-with-AI build; §6)

---

## 1. What this is, in plain terms

Encrypt the messages two people (or a small group) send each other **at rest**, so a database dump or a stolen disk yields ciphertext, not conversations — while still letting the server read a message **briefly, only when one of the participants is actively present** (their vault unlock window is open). This is the same posture the private (Private/Fortress) AI chats already use, extended from "one owner" to "the people in a conversation."

It is deliberately **not** zero-knowledge. The server *can* read message content during an unlock window, and that is the point: it's what lets the in-room AI participant (`messaging_ai_participant.md`), content-ful notifications, and server-side search keep working. The "even we can never read it" version is the separate, deferred client-custody path (§6).

The plain trade: **encrypted at rest against theft/dump; readable by the server only in a live, time-boxed window tied to a present participant.** Everything hard about it is already built for single-owner AI chat; the new work is making it multi-participant.

---

## 2. The principle: custody follows whether the server needs to read

Custody mode follows one question — *does the server need to read the content to do its job?*

- **Drive, passwords → client-custody** (browser-only; server never reads). The server runs no automation over them, so going browser-only costs nothing. This is `DEFERRED_client_custody_mail.md`'s model.
- **Human conversations → server-custody (this spec).** We *want* the server to read, transiently: to let an @-mentioned AI answer in the room, to put a message preview in a notification, to search. Sealing at rest protects the archive; the unlock window preserves the features. Giving the server no readable form (client-custody) would forfeit all three (§6).

So sealed-at-rest is the right default for messaging for the same reason server-custody is the default for mail: the value is in what the server does with the content while you're there.

---

## 3. What already exists (the cheap part — it's the AI-chat pattern)

Server-custody sealing is **already shipped** for private AI chats, and this is that pattern, not a new one:

- **Sealing helper + columns.** `plugins/joinery_ai/includes/ChatSeal.php` seals message/conversation content to a vault; the column shape is `aic_sealed_key` / `aic_content_sealed` on the conversation (`ai_conversations_class.php:63-65`) and `aim_sealed_key` / `aim_content_sealed` / `aim_sealed_owner_user_id` on each message (`ai_conversation_messages_class.php:63-66`), with `$sealed_fields` declaring which fields are ciphertext (`:99`).
- **Unlock window.** `includes/VaultUnlock.php` holds the server-custody secret key in a bounded in-memory window (APCu); `VaultUnlock::secretKey()` is how a server-side read gets the key while the window is open.
- **The consumer contract.** `docs/sealed_vault.md:329-353` (server-custody): declare `$sealed_fields`, implement the `decryptSealedField()` / `decryptSealedFieldStatic()` model hook, register a decrypt hook, and provide a rotation re-seal callback (`VaultUnlock::onReseal()`).
- **Per-user vault identity.** `UserEncryptionVault` / `uev_user_encryption_vaults` already exists in server-custody mode with a `uev_public_key` (`user_encryption_vaults_class.php:53`), looked up via `loadForUser($user_id, $scope)` (`:68`).

**The one structural gap vs. AI chat:** AI chat seals to a *single owner* — one `aim_sealed_key`, one `aim_sealed_owner_user_id`. A conversation has **two or more participants**, and a message must be readable when **any** of them is present. So the per-message content key must be wrapped **per participant**, not sealed to one owner. That multi-recipient wrapping is itself a solved precedent — it's exactly what Drive's `FileKeyGrant` (`fkg`, one wrapped-key row per recipient) does — but here the unwrap happens **server-side in the unlock window** rather than in the browser. This spec is essentially *AI-chat server-custody sealing + Drive's per-recipient key-grant fan-out*.

---

## 4. Design

### 4.1 Per-conversation content key, wrapped per participant

- Each conversation gets one symmetric **content key (DEK)**. Every message body is encrypted with it (AEAD, via the existing sealed helpers). Encrypting the body once and wrapping only the small key per participant is the same efficiency Drive relies on (no per-recipient content re-encryption).
- The DEK is **wrapped to each participant's server-custody vault key** and stored in a new grant table — call it `ConversationKeyGrant`, mirroring `fkg` almost exactly: `(cnv_conversation_id, usr_user_id, wrapped_conversation_key)`, unique per (conversation, user). This is the N-participant generalization of AI chat's single `aim_sealed_key`.
- **Reading a message:** the server opens whichever present participant's wrapped DEK its unlock window permits (`VaultUnlock::secretKey()` for that user), decrypts the DEK, then the body. Alice present → open Alice's grant; Bob present → open Bob's. Message content is never at rest in the clear.

### 4.2 Sealed columns on `msg_messages`

`msg_messages` today stores a **plaintext** body (`msg_body`, `messages_class.php:57`) and has **no** sealed columns. Add the ciphertext form and a flag (mirroring `aim_content_sealed`): the body column becomes ciphertext when the conversation is sealed, with `$sealed_fields` declaring it and the `decryptSealedField()` hook opening it in-window. `Conversation::add_message()` (`conversations_class.php:121`) encrypts before the row is saved (`:163`) — the sender's window is open at send time (they just typed it), so the DEK is available.

### 4.3 The read paths that must become locked-state-aware

This is the real integration surface, because `msg_body` is read by the server in several places that must now handle "sealed & no window open":

- `msg_body` is `api_readable` / `api_writable` (`messages_class.php:18-19`) — the REST surface must return locked (or omit) when sealed and no window is open, not raw ciphertext.
- It is `ai_readable = true` with `msg_body` in `$ai_untrusted_fields` (`messages_class.php:25-29`) — the AI-read path must gate on window state.
- `display_title()` / `strip_tags` helpers (`messages_class.php:62-68`) run over the body server-side — these must degrade gracefully when the body is sealed-and-locked.

The pattern for all three already exists in the AI-chat sealed model (a sealed field returns a locked sentinel when no window is open); this is applying it to a second model.

### 4.4 Key rotation & participant changes

- **Rotation:** on a participant's vault key rotation, re-wrap that participant's conversation-DEK grants — the `VaultUnlock::onReseal()` callback contract, per-participant.
- **Add a participant:** wrap the existing conversation DEK to the new participant's vault key (one new grant row) when a present participant's window is open to supply the DEK — the server-side analog of Drive's `sync_for_file()` re-wrap-on-add.
- **Remove a participant:** revoke their grant; note honestly that they may have already read past messages (true forward-secret removal would require rotating the DEK for future messages — a possible refinement, not required for at-rest protection).
- **Precondition:** a participant must have a server-custody vault set up to receive a grant. A conversation with a not-yet-enrolled participant either stays unsealed or blocks sealing until enrollment (a UX decision, §7).

---

## 5. How this coexists with the AI participant (the reason server-custody was chosen)

This is the crux, and it's why sealed-at-rest is compatible where zero-knowledge is not.

**The AI can read a sealed room because the server can — in-window.** The in-room AI runs as a server-side async worker (`messaging_ai_participant.md` §3.4). Under client-custody the worker would see only ciphertext and be dead on arrival. Under **server-custody**, the worker decrypts the thread with a present participant's unlock window and runs the turn normally. And an AI turn is always triggered by a **human @-mention** — i.e. a participant just acted, so a window is (or can be) open at exactly the moment the turn needs it. The two features fit.

Two real interactions to design, though:

1. **The window must still be open when the async turn runs.** The turn fires moments after the human's send, but the worker is asynchronous. Either the trigger captures the in-window capability at enqueue time, or the turn runs while the triggering participant's window is still open. Bound the enqueue-to-run latency to the window, or pass the unlock capability through the enqueue. (Design point, not a blocker.)

2. **A sealed room likely forces the AI turn onto a *local* model.** Sealing at rest is undermined if the AI ships the decrypted thread to a *remote* provider. AI chat already solves this: protected (Private/Fortress) conversations are coerced to a local model (`ChatSend.php:46-47`, `ChatLevel::isLocalModel()` at `includes/ChatLevel.php:22`). A sealed human conversation with an AI participant should inherit the same rule — the in-room turn runs on a local provider, not the remote default the unsealed spec allows. **This is the one place `messaging_ai_participant.md` would need a follow-up:** its §3.4 "platform-default (possibly remote) provider" becomes "local provider when the conversation is sealed." Noted here so the AI spec isn't edited speculatively; it's a clean addition if both ship.

The AI **sandbox is unchanged** — sealing is about the room's *own* content at rest, orthogonal to the §2 rule in the AI spec that the AI reads no *other* participant's private data.

---

## 6. What this is NOT — the zero-knowledge fork

Sealed-at-rest is server-custody. The maximum posture — the server *never* holds a readable form — is client-custody, captured separately in `DEFERRED_client_custody_mail.md` and applied there to mail. For conversations it would mean: browser-only decrypt (a `messages-crypto.js` sibling of `drive-crypto.js`), a dedicated `messages` vault scope + PRF context, per-participant grants unwrapped in the browser, and **the loss of the in-room AI, content-ful notifications, and server-side search** (a client-side encrypted search index instead). That is a much larger build and is **mutually exclusive with the AI-participant feature on the same conversation**. It's a legitimate future option for a "this conversation must never be server-readable" toggle — but it is not this spec.

---

## 7. What survives, what it costs (server-custody)

**Survives (because the server can read in-window):**
- The in-room AI participant (§5), on a local model when sealed.
- Content-ful notifications — generated at **send time**, when the sender's window is open and the plaintext is in hand (the same moment Private mail captures its preview).
- Server-side search — either in-window `ILIKE`, or the mailbox sealed-FTS-in-tmpfs pattern (`plugins/mailbox/includes/MailboxIndex.php`) for at-rest-but-searchable.
- Threading, listing, sorting — unaffected; they run on cleartext operational metadata (sender, times, conversation id), which stays unsealed.

**Costs:**
- **Content is readable only when some participant's window is open.** Background/away processing over bodies is limited to send-time (sender present) or a recipient's next unlock. Truly-autonomous overnight processing of sealed bodies isn't available (it needs a present participant's key).
- **The read-path retrofit (§4.3)** — every server-side reader of `msg_body` becomes locked-state-aware. Bounded and pattern-established, but it touches the REST surface, the AI-read path, and the display helpers.
- **Metadata is not sealed** — who talked to whom and when remains in the clear (as with the AI chat and mail sealed models). Sealing bodies, not the social graph.

---

## 8. Build inventory (what it would take)

1. **`ConversationKeyGrant` table + model** — `(cnv_conversation_id, usr_user_id, wrapped_conversation_key)`, unique per pair; the N-participant generalization of AI chat's single `aim_sealed_key`, structurally a copy of `fkg` (§4.1).
2. **Sealed columns on `msg_messages`** — ciphertext body + `content_sealed` flag + `$sealed_fields` + the `decryptSealedField()` hook, mirroring `aim_` (§4.2).
3. **Seal-on-send / open-on-read wiring** in `Conversation::add_message()` and the message read paths, using `ChatSeal`-style helpers and `VaultUnlock::secretKey()` for the present participant (§4.1–4.2).
4. **Locked-state-aware read paths** — REST (`api_readable`), AI-read (`ai_readable`), and `display_title()`/`strip_tags` all handle sealed-and-locked (§4.3).
5. **Rotation re-seal + add/remove-participant re-wrap** — per-participant `onReseal()`; add-participant re-wrap in an open window (§4.4).
6. **Vault scope decision** — likely **reuse the user's existing server-custody vault scope** (as AI chat does), so *no* new scope/PRF-context is needed (unlike the client-custody fork, which requires a dedicated `messages` scope). Confirm the single-scope reuse is acceptable, or isolate a `messages` scope for blast-radius separation (§7 open question).
7. **AI-spec follow-up (only if both ship):** the in-room turn runs on a **local** model when the conversation is sealed (§5.2) — a one-line rule added to `messaging_ai_participant.md` §3.4, not an edit made now.

No new migration for schema (columns come from `$field_specifications` via `update_database`); the grant table is a normal data class.

---

## 9. Open questions

- **Which vault scope** — reuse the default user server-custody vault (simplest; §8.6) vs. a dedicated `messages` scope (isolation). Server-custody probably reuses; decide before build.
- **Async AI turn vs. window lifetime** — capture the unlock capability at enqueue, or bound enqueue-to-run within the window (§5.1).
- **Sealing granularity** — is sealing a per-conversation setting (a "sealed conversation" like a Private chat), a per-user preference, or forced by one participant? (A conversation is shared, so one participant wanting it sealed affects both — a consent/negotiation question the AI-participant transparency model (§2 of that spec) is a precedent for.)
- **Not-yet-enrolled participant** — block sealing, or seal only to enrolled participants and re-wrap on enrollment (§4.4).
- **Forward-secret removal** — is DEK rotation on participant-removal in scope, or is at-rest protection (past messages stay readable to a removed member) sufficient?

---

## 10. Bottom line

Sealed-at-rest conversations are **AI-chat server-custody sealing, made multi-participant** — the sealing helpers, the unlock window, the consumer contract, and the per-recipient key-wrapping (from Drive) all already exist. The genuinely new work is a per-conversation key-grant table, sealed columns on `msg_messages`, and making the server's existing `msg_body` readers window-aware. Crucially, because the server can still read in-window, this version **keeps** the in-room AI participant (on a local model when sealed) — which the zero-knowledge fork (`DEFERRED_client_custody_mail.md`, applied to chat) would forfeit.
