# Joinery Messenger — WhatsApp-style messaging in the member area

**Status:** Draft 2026-08-11 — awaiting review. Not yet built.

**Plugin name:** `messenger`

**Companion specs:** `messaging_sealed_at_rest.md` (this spec absorbs its design as the
Private tier), `messaging_ai_participant.md` (rides the group enablement built here),
`joinery_direct_mail.md` + `joinery_direct_mail_review.md` (the cross-instance
transport), `protection_levels_platform.md` (the level vocabulary),
`chat_plugin.md` (the deferred Discord-style channels product — different product,
shared upgrade path, see *Relationship to other specs*).

---

## 1. What this is, in plain terms

A messaging app that lives in the member area next to Email and Calendar — open
`/profile/messenger`, see your conversations, talk to another member or a small group
the way you would on WhatsApp: instant-feeling delivery, read receipts, typing
indicators, replies, emoji reactions, photos and files, group names and membership.

Two people whose accounts live on *different* Joinery instances can also message each
other — the message travels over the Joinery Direct channel (the signed
instance-to-instance HTTPS transport from `joinery_direct_mail.md`), not through any
third party. Your Joinery mail address is your cross-instance chat handle, the way a
phone number is your WhatsApp handle.

Conversations carry a protection level from the platform ladder: **Standard**
(server-managed plaintext), **Private** (sealed at rest, readable only while a
participant is present), and **Guarded** (Private with the doors guarded — no message
content in notifications, AI pinned to local models, sealed-only federation).
**Fortress is deliberately not offered** — see §7.5.

## 2. The main question first: yes, this works in PHP only

No Go service, no WebSocket, no new daemon. The whole feature is PHP + Postgres +
short-interval polling, and that is an established pattern here, not an experiment:

- **The AI chat already polls at 600 ms** while a turn runs
  (`plugins/joinery_ai/includes/chat_view_body.php`, `POLL_INTERVAL_MS = 600`,
  chained `setTimeout`, give-up at 3600 s), through a normal `/api/v1` action. The
  mobile apps use the same poll. A messenger polling at 3 s is *lighter* than what
  ships today.
- **SSE / long-poll is rejected**, deliberately: a held `text/event-stream` response
  pins a php-fpm worker per open tab, and the fleet runs mpm_event + php-fpm with
  small worker pools. A handful of members with the messenger open in two tabs each
  would exhaust the pool. Bounded polling costs one indexed query per poll and
  releases the worker immediately. (There is no SSE anywhere in the tree today;
  `text/event-stream` appears only in the LLM providers *reading* upstream streams.)
- **Each poll is cheap by construction:** one query keyed on the existing
  `(msg_cnv_conversation_id, msg_sent_time DESC)` index with a `msg_message_id`
  cursor, plus an APCu read for typing state. No table scans, no fan-out work at
  read time.

**The Go upgrade path is kept open, at zero cost.** `chat_plugin.md` designed its
realtime around a generic `NOTIFY message_events` emitted at the end of
`Conversation::add_message()` — a NOTIFY with no listeners is essentially free, and
nothing chat-flavored lives in core. This spec makes that one-line core change now.
When the Go realtime service is eventually built, it LISTENs on `message_events` and
pushes over WebSocket; the messenger's poll endpoint becomes the fallback transport
instead of the primary one, and nothing in this build is redone.

### Polling design

One action, `messenger_poll`, carries everything; the client adapts cadence to what
the member is looking at:

| Client state | Cadence |
|---|---|
| Thread open, tab visible | 3 s |
| Conversation list visible, no thread | 12 s |
| Tab hidden (`visibilitychange`) | paused; immediate poll on return |
| Just sent a message | immediate poll (pick up receipts/replies fast) |

Request: `{conversation_id?, after_message_id?, list_since?, typing?}` — the poll
piggybacks the caller's own typing state (`typing: true/false`) so typing needs no
separate endpoint. Response: new messages after the cursor, reaction/receipt deltas,
who is typing, and an inbox delta (conversations with activity since `list_since`,
with unread counts). Intervals are plugin settings, not constants.

The poll loop itself — chained `setTimeout`, `visibilitychange` pause/resume,
immediate-poll trigger — ships as a small shared JS helper in core `assets/js/`
rather than being written fresh; the AI chat's poll adopts it opportunistically
when next touched, so the idiom lives in one place.

**Typing indicators are ephemeral by design** — APCu key
`messenger:typing:{conversation_id}` holding `{user_id: last_typed_at}`, ~8 s TTL,
written by the poll, returned to the other participants' polls, never persisted.
Valueless when stale, exactly like the chat plugin's presence reasoning. APCu is
per-host, which is correct here: a deployment is one box.

## 3. Product scope

### In scope (v1)

- Conversation list + thread view as a member-area app (the `$options['app'] = true`
  full-bleed chrome that mailbox and calendar use), at `/profile/messenger`
- 1:1 and small-group conversations (group size capped by setting, default 32):
  create, name, add/remove members, leave; admin role for groups
- Near-realtime delivery via polling (§2); read receipts; typing indicators
- Replies (quote a message), emoji reactions, delete-for-everyone (own messages,
  tombstone rendering), system messages ("Alice added Bob")
- Photo and file attachments via the existing `UploadHandler` + `File` pipeline
- Protection levels per conversation: Standard / Private / Guarded (§7)
- Cross-instance 1:1 messaging over Joinery Direct (§8)
- Unread badges via the existing header/session-cache mechanism; notifications via
  the existing fan-out already inside `add_message()`

### Out of scope (v1)

- Voice/video calls, voice notes
- Message editing (v1.1 — additive column)
- Cross-instance **groups** (§8.6 — 1:1 only in v1)
- Cross-instance typing indicators and read receipts (§8.5)
- Fortress / client-custody conversations (§7.5 — future spec, per platform doctrine)
- Broadcast lists, stories/status, stickers
- Blocking. There is still no `UserBlock` class platform-wide; the `class_exists`
  seams in `conversations_class.php` remain the integration point, and this spec
  does not build it. Cross-instance, blocking rides the mail block list when
  Direct Mail's F5 resolution builds it (§8.4).

## 4. What already exists (reuse, don't reinvent)

The messenger is a **UI and federation layer over the core messaging system that
already ships** — not a parallel message store:

- **Data layer:** `cnv_conversations`, `cnp_conversation_participants`,
  `msg_messages` (`data/conversations_class.php`, `data/conversation_participants_class.php`,
  `data/messages_class.php`). `create_conversation()` already accepts N participants;
  `add_message()` already clears per-user deletes, fans out notifications, respects
  mutes; `get_unread_count()` and the session-cached header badge already work;
  `MultiConversation` already has the LATERAL-join inbox query.
- **API surface:** the four `/api/v1` actions (`conversation_list`,
  `conversation_thread`, `conversation_send`, `conversation_action`) that the iOS
  member app also calls. **These keep working unchanged** — the messenger extends
  the surface with `messenger_*` actions, never breaks the existing four.
- **Polling idiom:** the AI chat poll (§2). **App chrome:**
  `PublicPage::BeginPage($title, ['app' => true])` + `.jy-app-bar`.
  **Menu:** `profileMenu` in `plugin.json`, synced to `amu_admin_menus`.
- **Sealing stack:** `$sealed_fields` + `SystemBase::sealColumns()` /
  `decryptSealedField()`, `VaultCrypto`, `VaultUnlock` windows, the 25 s
  `vault-presence.js` beacon, `VaultDeferredWork`, rotation via
  `VaultUnlock::onReseal()` (docs/sealed_vault.md §consumer contract).
- **Multi-recipient key wrapping precedent:** Drive's `FileKeyGrant`
  (`data/file_key_grants_class.php`) — one wrapped-key row per (item, user),
  `sync_for_file()` reconciliation, revocation by row delete.
- **Cross-instance transport:** everything in `joinery_direct_mail.md` — capability
  record, preflight + signed manifest (BLAKE2b part hashes per review F2), sender
  sealing to the preflight key, contact gate over `imc_mailbox_contacts`, relay
  termination at Fortress, deferred ingest under a locked vault, and the safe HTTP
  client (`specs/safe_http_client.md`, review F1).
- **Moderation:** `adm/admin_conversations.php` / `admin_conversation.php` (gain
  group rendering, nothing structural).

## 5. Architecture and placement

**A plugin for the product, core for the data layer.** The `messenger` plugin owns
the views (`plugins/messenger/views/profile/…` → `/profile/messenger/...` by
auto-discovery, no serve.php entry), the `messenger_*` logic/actions, assets, and
admin settings. Schema additions and behavior changes to conversations/messages land
in the **core data classes**, because messaging is core and other consumers (the
AI-participant spec, the iOS app, the future chat plugin) build on the same rows.

- **Profile menu:** `profileMenu` entry — slug `messenger`, title "Messages", url
  `/profile/messenger`, icon `chat`, order 53 (between Email 52 and Calendar 55),
  `visibility: "in"`.
- **The legacy views retire.** When the plugin is active, `/profile/conversations`
  and `/profile/conversation` redirect to `/profile/messenger` (no band-aid parallel
  UI to drift; one messaging surface). The header envelope icon links to the
  messenger. When the plugin is inactive, the old views keep working untouched.
- **Shared chat visual kit — styles, not a component.** The bubble and composer
  styles land as `.jy-chat-*` classes in the shared jy-ui stylesheet, consumed by
  both the messenger and the AI chat so the two read as one family. Sharing stops
  at styles and small utilities (the poll helper in §2, `vault-lock.js`) —
  deliberately no shared thread *component*: the AI chat is turn-based (streaming
  partials, tool activity, thinking visibility), the messenger is multi-party
  (receipts, typing, reactions, replies), and one component serving both would
  grow an option flag per difference. The real deduplication arrives with
  `messaging_ai_participant.md`: the AI joins messenger rooms as a participant,
  in this UI, while the private capability-bearing AI chat remains its own
  product surface.
- **Dependencies:** local messaging (everything in §3 except federation) requires no
  other plugin. Federation (§8) requires the mailbox plugin — it supplies the
  member's address, the contact list, and the Direct endpoint.

## 6. Feature mechanics and data model

All schema changes are `$field_specifications` additions — no migrations.

### Group enablement (core — and a prerequisite others are waiting on)

`messaging_ai_participant.md` §3.2 documents that groups are a latent capability:
the schema and `create_conversation()` accept N participants, but there is no
creation path, no >2 rendering, and no membership actions. **This spec builds that
minimum group surface**, and the AI-participant spec rides it later:

- Create-group flow (pick members, optional name → `cnv_subject`), group rendering
  in list and thread (never assume `get_other_participant()`), member add/remove/
  leave actions, `cnp_is_admin bool` (creator defaults to admin; admins manage
  membership and name).
- **System messages:** membership and rename events are recorded as messages with
  `msg_message_type = 'system'` (default `'text'`), rendered as centered chips.
  They come from the same `add_message()` funnel, so they poll, notify, and order
  like everything else.

### New columns and tables

`cnv_conversations`: `cnv_protection_level varchar(20) default 'standard'`,
`cnv_guid varchar(36)` (stable cross-instance identity, generated for every
conversation). Group photo rides the existing polymorphic `EntityPhoto`
(`entity_type = 'conversation'`).

`cnp_conversation_participants`: `cnp_is_admin bool default false`.

`msg_messages`: `msg_guid varchar(36)`, `msg_reply_to_message_id int8 NULL`,
`msg_message_type varchar(20) default 'text'` (`text` / `system`),
`msg_remote_sender_address varchar(255) NULL` (+ `msg_usr_user_id_sender` becomes
nullable — a remote peer has no local user row, §8.3), and the sealed-content
columns per §7.

New tables:

| Table | Purpose |
|---|---|
| `msr_message_reactions` | `(msr_msg_message_id, msr_usr_user_id NULL, msr_remote_address NULL, msr_emoji, msr_create_time)`, unique per (message, reactor, emoji). The additive design `chat_plugin.md` already anticipated for DM reactions. |
| `msa_message_attachments` | `(msa_msg_message_id, msa_fil_file_id, msa_filename, msa_mime_type, msa_byte_size, msa_is_sealed)` — per-part rows in the shape `imc_inbound_message_attachment` established. |
| `ckg_conversation_key_grants` | `(ckg_cnv_conversation_id, ckg_usr_user_id, ckg_wrapped_key, ckg_key_generation)`, unique per (conversation, user) — §7. |
| `crp_conversation_remote_peers` | `(crp_cnv_conversation_id, crp_address, crp_domain, crp_display_name, crp_create_time, crp_delete_time)` — §8.3. |

### Receipts, replies, reactions, deletes

- **Read receipts** derive from `cnp_last_read_time`, which already exists — the
  thread poll returns each participant's read position; the UI renders "seen by"
  under the last-seen message. Always on in v1; a per-user visibility toggle is a
  clean later addition.
- **Delivery state (local):** a stored message *is* delivered — one state. The
  WhatsApp-style tick progression only becomes meaningful cross-instance (§8.5).
- **Replies** are `msg_reply_to_message_id` + quoted-parent rendering (the
  `chm_reply_to_id` tap-reply UX from the chat plugin spec, applied here).
- **Delete-for-everyone:** sender soft-deletes own message (`msg_delete_time`);
  thread renders a tombstone ("This message was deleted"). Admin moderation via the
  existing admin pages is unchanged.
- **Attachments** upload through `UploadHandler` into the `File` system; images
  render inline with thumbnails, other types as file chips. Size cap is a plugin
  setting.

### Settings (declared in `plugin.json`)

`messenger_active` (bool, default true), `messenger_max_group_size` (default 32),
`messenger_poll_thread_seconds` (default 3), `messenger_poll_list_seconds`
(default 12), `messenger_max_attachment_mb` (default 25),
`messenger_default_protection_level` (per-deployment default for new conversations,
`standard`).

## 7. Protection levels

The ladder is the platform doctrine's, per conversation, chosen with the card picker
at creation and shown as a level chip in the thread header. This section **absorbs
`messaging_sealed_at_rest.md`** — its design is the Private tier here, and its open
questions are resolved below (§7.6). `protection_levels_platform.md` recorded social
messaging as "deferred — candidate for a future spec"; this is that spec.

### 7.1 Standard

Today's behavior: plaintext rows, AI-readable under existing rules, content-ful
notifications. No change.

### 7.2 Private — sealed at rest, multi-participant server custody

The promise: a database dump or stolen disk yields ciphertext; the server reads a
message only while a participant's unlock window is open.

Mechanism (= `messaging_sealed_at_rest.md` §4, unchanged in substance):

- **One DEK per conversation**, wrapped to each participant's server-custody vault
  public key in `ckg_conversation_key_grants` — AI-chat sealing generalized from one
  owner to N readers, using Drive's `FileKeyGrant` shape with the unwrap happening
  server-side in-window instead of in the browser.
- **Sealed columns on `msg_messages`:** `msg_body` ciphertext when the conversation
  is sealed, declared via `$sealed_fields` with `msg_content_sealed` +
  `msg_key_generation`; the model's decrypt hook resolves the DEK through whichever
  present participant's grant `VaultUnlock::secretKey()` can open, then opens the
  field (AD = `msg:{id}:msg_body` splice defense as standard). Attachment bytes are
  encrypted under the same conversation DEK through the sealed-file container +
  `File` decrypt-hook path (`msa_is_sealed`).
- **Send-time sealing works by construction:** the sender just typed the message, so
  their window is open and the DEK is in hand inside `add_message()`.
- **Locked-state-aware read paths** (the real integration surface, per the sealed
  spec §4.3): the REST surface returns locked rather than ciphertext, the AI-read
  path gates on window state, display helpers degrade to the one-tap-unlock
  placeholder — the established `VaultLockedException` / `423 Locked` pattern.
- **Metadata stays plaintext**, stated honestly: who talks to whom, when, and
  message counts are operational metadata (same rule as mail and AI chat). Sealing
  covers bodies and attachment content, not the social graph.
- **Membership changes:** add a participant → wrap the DEK to their key while a
  present participant's window supplies it; remove → delete their grant row (they
  can no longer decrypt server-side; they have, of course, already seen what they
  saw). Vault rotation → per-participant re-wrap via `VaultUnlock::onReseal()`.
- **Notifications** still carry content previews at Private (captured at send time,
  sender present — the same moment Private mail captures its preview).

**Raising a conversation** (Standard → Private) is a batch re-seal of its history in
the mailbox raise-ceremony shape, allowed to any participant, and requires every
*local* participant to hold a server-custody vault — the UI names who still needs
one ("Bob hasn't set up protection yet"). **Levels only tighten** — no lowering in
v1. One-way is the platform's derived-tier rule, and for a shared room it avoids the
consent problem of one member exposing everyone's history; a lowering ceremony can
be specced later if it is ever wanted.

### 7.3 Guarded — Private, with the doors guarded

Same custody as Private (the server still decrypts in-window — which is what keeps
AI and search possible), plus messenger-specific hardening on every edge where
content could leave:

1. **Generic notifications.** No message content in any notification, email, or
   future push — "New message in Ski Trip", never a preview.
2. **AI pinned local.** If the AI participant (`messaging_ai_participant.md`) is in
   a Guarded conversation, its turns run on local models only — the same
   `ChatLevel::isLocalModel()` coercion Guarded AI chats use. (At Private, cloud
   egress follows the platform egress-consent rules, mirroring mail.)
3. **Sealed-only federation.** A Guarded conversation refuses to send a
   cross-instance message unless the preflight returned a recipient key to seal to —
   the plaintext-over-TLS opportunistic downgrade Direct Mail permits is not
   accepted here (§8).

### 7.4 The card picker

The shared protection-level card component — work item 1 on the
`protection_levels_platform.md` gap list — **still does not exist**; mailbox's
three-card `radioinput(..., ['card' => true])` in `admin_mailbox_domains.php` is the
reference implementation and Drive hand-rolled a second, weaker one. This build
extracts the shared component (card copy in one place, consumers declare their
subset of `ProtectionLevel::ORDER`) and the messenger consumes it, showing
Standard / Private / Guarded. That pays down the doctrine's top work item instead of
adding a third hand-rolled picker.

### 7.5 Why no Fortress card

Fortress means client custody — plaintext never exists on the server, strictly (R1).
For a multi-party thread that requires per-participant browser-side key ceremonies,
member-revocation re-sealing, and client-side decrypt of every render — the exact
problem `mailbox_group_collaboration.md` and the doctrine both record as "a
different key-management problem… a new spec." It would also forfeit the AI
participant, content-ful anything, and server-side search on that conversation. Per
doctrine R2, a service that cannot honestly offer client custody shows no Fortress
card. When a client-custody messaging spec exists, it arrives as this picker's
fourth card with the blackout stated on it.

### 7.6 Resolutions to `messaging_sealed_at_rest.md` open questions

- **Vault scope:** reuse the default `user` server-custody scope (as AI chat does).
  One unlock opens mail, chat, and messenger together — that is the product's
  window model, and a separate scope would buy isolation nobody has asked for at
  the cost of a second unlock prompt.
- **Granularity/consent:** per conversation; any participant may raise; never
  lowers (§7.2).
- **Not-yet-enrolled participant:** block the raise until every local participant
  is enrolled, with the UI naming who. (Remote peers are their own instance's
  business — §8.)
- **Forward-secret removal:** out of scope; grant revocation is the v1 semantics.
- **Async AI turn vs. window:** owned by the AI-participant build, unchanged here.

## 8. Cross-instance messaging over Joinery Direct

This is the piggyback. Direct Mail builds a signed, sealed, consent-gated
instance-to-instance HTTPS channel; the messenger is its **second payload kind**,
not a second transport. Everything the review hardened — the safe HTTP client and
port policy (F1), BLAKE2b part hashes in the signed manifest (F2), the honest DNS
trust claim (F3), contact sealing following domain posture (F4), rate limiting and
block list (F5), replay protection (F6) — applies to chat identically, because chat
messages travel through the same preflight, the same signature check, the same
endpoint, the same relay at Fortress. Chat adds **no new endpoint, no new DNS
record, no new key custody, and no new oracle surface**.

**Dependency:** this phase builds only after Direct Mail (including its relay
version and fleet upgrade) exists. Phases 1–2 of the messenger do not wait for it.

### 8.1 Identity and consent

Your Joinery mail address is your chat handle. The consent model is Direct Mail's,
verbatim: **a chat message is accepted only if the sender's address is in the
recipient's `imc_mailbox_contacts`**, matched with a sending domain bound to the
verified instance signature (no spoofed-From borrowing). A stranger cannot open a
chat with you — chat spam is structurally impossible for the same reason direct-mail
spam is. The bootstrap for strangers is the one that already exists: email first
(which self-routes to SMTP), get added to contacts, then chat works.

### 8.2 The chat kind on the wire

The Direct envelope's `kind` field (defined in `joinery_direct_mail.md`
§Vocabulary) gains its second value, `chat`. A chat envelope carries sender address, recipient address, `cnv_guid`,
`msg_guid`, sent time, and reply-to guid; the manifest declares the body part and
any attachment parts (sealed to the preflight key, hashes signed, exactly as mail
parts are). Small control payloads ride the same kind: `chat.reaction`
(add/remove, referencing a `msg_guid`) and `chat.delete` (sender tombstones their
own message).

Preflight and the two-answer discipline are unchanged: `accept` (with the
recipient's current key) or `declined`. For chat, `declined` means **"this person
doesn't accept direct messages from you"** — there is no SMTP fallback for a chat
bubble. Refusal, absence of a capability record, and a receiver
too old to understand `kind: chat` (which rejects the preflight) all converge on
the same sender UX: the compose surface shows the address as not chat-reachable and
offers "send as email instead" as an explicit affordance — never a silent
transmutation of a chat message into an email. This is the compose-time honesty
rule from Direct Mail's social-signal section, applied to chat: the member sees the
path *before* sending. Version skew during rollout is therefore safe by the same
logic as Direct Mail's: an instance that can't serve chat simply reads as
not-reachable, and nothing breaks.

### 8.3 Receiving and the local model

On an accepted chat message, the receiver routes `kind: chat` to the messenger's
ingest (registered with the Direct receiver the way inbound webhook handlers are
registered today): find-or-create the local conversation by (`cnv_guid`, peer
address) for the recipient, store the message, fan out notifications through the
normal funnel. The remote peer is a `crp_conversation_remote_peers` row — **no
shadow user rows**; `msg_usr_user_id_sender` is null for remote messages and
`msg_remote_sender_address` carries the attributed sender, resolved for display
through the peer table.

Each side's at-rest posture is its own: the recipient's copy stores at the level of
the recipient's conversation (their deployment, their vault), the sender's copy at
the sender's. The wire is sealed to the preflight key regardless (opportunistic at
Standard/Private conversations, mandatory at Guarded — §7.3).

**Locked-vault receive rides deferred ingest.** For a sealed conversation whose
local participants are all locked (or a sealed-posture mailbox domain, per F4's
resolution: contacts sealed → the gate can't run live), the receiver
accepts-and-spools the sender-sealed parts pending-parse style and absorbs them into
the conversation at the next unlock — authentication runs locked, authorization
defers, no lock-state oracle, exactly the mail rules. A non-contact's chat message
deferred this way is discarded at unlock (the sender saw "delivered"; structurally
identical to mail filed to spam, minus a spam folder — recorded honestly).

### 8.4 Abuse

Remove the contact → their next chat attempt gets `declined` (reads as
not-reachable, indistinguishable from a downgrade). Block (once Direct Mail's F5
block list exists) → same wire answer, so block status stays unobservable, per the
mail design. Rate limiting is the Direct endpoint's, shared.

### 8.5 Delivery ticks

Cross-instance messages get WhatsApp-style state the local path doesn't need:
**queued** (clock — in the sender's outbound retry queue; Direct has no SMTP
fallback for chat, so the sender keeps a small retry-with-backoff queue and the
relay spools for Fortress destinations as it does for mail) → **delivered**
(check — the receiving instance accepted the transfer). Read receipts and typing do
**not** cross instances in v1 — read-position chatter would multiply preflight
traffic for marginal value; revisit on demand.

### 8.6 Cross-instance groups: deferred, on record

v1 federation is 1:1 only. A federated group needs membership propagation,
message fan-out from every member's home instance, and ordering reconciliation
across N instances — a real protocol design, not an envelope field. The local data
model doesn't fight it later (`cnv_guid` is global, `crp` holds N peers), but it is
its own spec. Groups in v1 are same-instance.

## 9. Integration points (up-front inventory)

| System | How the messenger uses it | Modification needed? |
|---|---|---|
| `Conversation` / `Message` / `ConversationParticipant` (core) | The data layer | Yes — new columns (§6), group actions, `NOTIFY message_events` emission in `add_message()`, sealed-field declarations (§7) |
| `conversation_*` API actions | Kept for iOS compatibility | No breaking changes; messenger adds `messenger_*` actions |
| Legacy `/profile/conversation(s)` views | Superseded | Redirect to `/profile/messenger` when plugin active |
| AI chat UI (`plugins/joinery_ai`) | Shares `.jy-chat-*` styles + the poll-loop helper | Opportunistic adoption when next touched; no shared thread component (§5) |
| `PublicPage` app chrome (`app => true`, `.jy-app-bar`) | Messenger layout | No |
| `profileMenu` (`plugin.json` → `amu_admin_menus`) | "Messages" entry, order 53 | Declared in plugin.json |
| Header envelope icon + `$_SESSION['message_unread_count']` | Unread badge | Link target changes to the messenger |
| Notifications / signal fan-out in `add_message()` | New-message alerts | Guarded suppresses content previews (§7.3) |
| `UploadHandler` + `File` (+ decrypt hooks) | Attachments, sealed at Private/Guarded | New consumer, existing seams |
| `EntityPhoto` | Group photos (`entity_type='conversation'`) | No |
| Sealed Vault stack (`VaultCrypto`, `VaultUnlock`, `$sealed_fields`, `onReseal`, `VaultDeferredWork`) | Private/Guarded custody | New consumer per the documented contract |
| `ProtectionLevel` + shared card picker | Level chip + picker | **Builds** the shared picker (doctrine work item 1) and consumes it |
| APCu | Typing state | No (pattern exists: `VaultUnlock`) |
| Direct Mail endpoint, preflight, manifest, relay, safe HTTP client | Cross-instance transport | `kind` field + chat ingest handler; sender retry queue (§8) |
| `imc_mailbox_contacts` | Federation consent gate | No — the gate is Direct Mail's, shared |
| Admin conversations pages | Moderation | Group rendering |
| `update_database` / plugin sync | Tables + columns | Standard |

## 10. Build phases

Each phase ships on its own.

1. **The messenger app (local, Standard).** Plugin skeleton, app-chrome list +
   thread views, `messenger_poll` + adaptive polling, group enablement (core),
   replies, reactions, receipts, typing, delete-for-everyone, system messages,
   attachments, menu entry, legacy-view redirect, admin group rendering, the
   `NOTIFY message_events` core emission. This alone is the usable WhatsApp-style
   product for one instance — and it discharges the group prerequisite the
   AI-participant spec is waiting on.
2. **Protection levels.** `ckg` grants, sealed columns + locked-aware read paths,
   raise ceremony, Guarded rules (generic notifications; the federation and AI
   rules activate with their phases), the shared card picker extraction, level chip.
3. **Federation (after Direct Mail ships).** `kind: chat` envelope + ingest
   handler, remote-peer model, sender retry queue + ticks, compose-time
   reachability, deferred-ingest absorption, Guarded sealed-only send.

Phase 1 is conventional feature work with no load-bearing risk. Phase 2's risk is
the locked-state read-path retrofit (bounded; the pattern ships twice already).
Phase 3's risk rides Direct Mail's — chat deliberately adds no new wire surface.

## 11. Documentation (at build time, current-state only)

- `docs/social_features.md` — the messaging section becomes the messenger's
  developer guide: data model additions, group API, `messenger_*` actions, polling
  contract, protection levels, federation behavior.
- `plugins/messenger/docs/overview.md` — plugin overview in the mailbox-overview
  shape.
- `docs/sealed_vault.md` — add the messenger to the consumer list (first
  multi-participant server-custody consumer).
- `specs/protection_levels_platform.md` matrix — social messaging row moves from
  "deferred" to built, and the shared-picker work item closes.
- `specs/messaging_sealed_at_rest.md` → `specs/implemented/` when phase 2 lands
  (its design record is realized here).

## 12. Acceptance criteria

**Local (phase 1)**
1. Two members hold a 1:1 conversation at `/profile/messenger`; a sent message
   appears on the other member's open thread within one thread-poll interval
   without a reload.
2. A group of 3+ supports name, photo, add/remove/leave with system-message chips;
   non-admins cannot manage membership.
3. Read receipts, typing indicators, replies, reactions, and delete-for-everyone
   tombstones all render and survive reload; typing state never hits the database.
4. Polling pauses when the tab is hidden and resumes on visibility; cadence matches
   the settings.
5. An image attachment sends, thumbnails inline, and downloads intact.
6. The existing `conversation_list` / `conversation_thread` / `conversation_send` /
   `conversation_action` actions behave unchanged (iOS regression gate).
7. With the plugin inactive, `/profile/conversations` works exactly as today; with
   it active, the legacy URLs redirect.

**Protection levels (phase 2)**
8. A Private conversation's `msg_body` and attachment bytes are ciphertext in the
   database; with all participants' windows closed, REST returns locked (never
   ciphertext) and the UI shows the one-tap unlock.
9. Any single present participant's open window suffices to read the thread
   (grants, not ownership).
10. Raising re-seals history; the raise is blocked, with the member named, while
    any local participant lacks a vault; no lowering path exists.
11. Adding a member to a sealed conversation grants them the DEK; removing them
    deletes the grant and their server-side reads fail thereafter.
12. Vault rotation re-wraps that member's grants via the reseal hook; old-generation
    messages still open.
13. A Guarded conversation's notifications carry no message content anywhere.

**Federation (phase 3)**
14. Two members on different instances, each in the other's contacts, chat 1:1
    with delivered-ticks; the wire carries sealed parts with signed hashes; a
    tampered part rejects the message.
15. A sender not in the recipient's contacts gets the same `declined` as mail;
    their compose surface shows not-chat-reachable and offers email; nothing is
    stored on the receiving side (Standard posture).
16. Under a locked sealed-posture recipient, a contact's chat message is accepted,
    spooled sealed, and absorbed at unlock; a non-contact's is discarded at unlock;
    the sender observes identical behavior in both cases and in both lock states.
17. A Guarded conversation refuses to send when the preflight returns no key.
18. Reactions and delete-for-everyone propagate across instances; typing and read
    receipts do not cross.
19. An instance that predates the chat kind causes the sender to render the peer
    as not-chat-reachable; nothing errors user-visibly.

### Test plan

Harness tests (`plugins/messenger/tests/` + `tests/messaging/`, `@joinery-test`
headers): group CRUD + membership authorization; poll cursor correctness (no
missed/duplicated messages across concurrent sends); reaction/receipt deltas;
sealed round-trip through grants including add/remove/rotate; locked-read behavior
(REST 423, AI gate, display placeholder); raise-ceremony batch; legacy-action
regression suite; federation ingest with a stubbed peer (kind routing, guid
find-or-create, dedup on `msg_guid` replay per Direct's F6 cache); tick state
machine on the sender queue. Browser verification on dev per the standard workflow.

## 13. Open decisions

None blocking phases 1–2. Design questions inherited from companion specs stay
owned there: Direct Mail's pending review findings (F5 block-list shape, F6 replay
window, F1 port policy) gate phase 3, not this spec; the AI participant and
cross-instance groups are their own specs. One product-level default worth an owner
glance at build time: whether `messenger_default_protection_level` should ship as
`standard` (proposed) or `private` for privacy-first deployments — a setting either
way, so nothing hinges on it.
