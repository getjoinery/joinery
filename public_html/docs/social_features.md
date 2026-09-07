# Social Features

Core platform features for user-to-user interaction: reactions (likes, favorites, bookmarks) and messaging. These are generic systems used by any interactive site -- the dating plugin and others add domain-specific behavior on top.

---

## Reaction System

A polymorphic reaction system that works with any entity type. Supports likes, favorites, bookmarks, passes, and any other reaction type. Uses the same `entity_type` + `entity_id` pattern as EntityPhoto and ChangeTracking.

**Spec:** [Reaction System Spec](/specs/implemented/reaction_system_spec.md)

### Data Model

**Table:** `rct_reactions`

| Column | Type | Description |
|--------|------|-------------|
| `rct_reaction_id` | int8, serial, PK | |
| `rct_usr_user_id` | int4, FK | User who reacted |
| `rct_entity_type` | varchar(50) | 'user', 'event', 'post', 'product', etc. |
| `rct_entity_id` | int4 | ID of target entity |
| `rct_reaction_type` | varchar(20) | 'like', 'favorite', 'pass', 'bookmark' (default 'like') |
| `rct_create_time` | timestamp | |
| `rct_delete_time` | timestamp | Soft delete (unreact) |

**Classes:** `Reaction` (single), `MultiReaction` (collection) in `data/reactions_class.php`

### Usage

**Check if a user has reacted:**
```php
require_once(PathHelper::getIncludePath('data/reactions_class.php'));

$is_liked = Reaction::has_reacted($user_id, 'event', $event_id);
```

**Toggle a reaction (react if not reacted, unreact if already reacted):**
```php
$result = Reaction::toggle($user_id, 'event', $event_id);
// $result = ['action' => 'reacted'|'unreacted', 'reaction' => $reaction_obj]

// With a specific reaction type:
$result = Reaction::toggle($user_id, 'post', $post_id, 'bookmark');
```

**Get reaction count for an entity:**
```php
$count = Reaction::get_count('event', $event_id);
```

**Get all entities a user has reacted to:**
```php
// All likes
$reactions = Reaction::get_user_reactions($user_id);

// Only event favorites
$favorites = Reaction::get_user_reactions($user_id, 'event', 'favorite');
```

**Query with MultiReaction:**
```php
$reactions = new MultiReaction(
    ['entity_type' => 'event', 'entity_id' => $event_id, 'deleted' => false],
    ['rct_create_time' => 'DESC']
);
$reactions->load();
```

### Reaction Button (UI Component)

Drop a reaction button into any view:

```php
// Basic like button with count
Reaction::render_button('event', $event_id);

// Customized bookmark button
Reaction::render_button('post', $post_id, [
    'reaction_type' => 'bookmark',
    'show_count' => false,
    'icon_active' => 'fas fa-bookmark',
    'icon_inactive' => 'far fa-bookmark',
    'css_class' => 'btn-sm'
]);
```

The button handles AJAX toggling and state updates automatically. User must be logged in.

### API Actions

The button's JS calls these `POST /api/v1/action/{name}` actions with the
browser-session credential; each returns its payload inside the response
envelope's `data`. Logged-in only.

| Action | Params | `data` payload |
|--------|--------|----------------|
| `reaction_toggle` | `entity_type`, `entity_id`, `reaction_type` (opt) | `{action, count}` |
| `reaction_status` | `entity_type`, `entity_id` | `{reacted, count}` |
| `reaction_count` | `entity_type`, `entity_id` | `{count}` |

### Entity Types

Any entity with a primary key can be reacted to. Common types:

| `entity_type` | Entity | Typical `reaction_type` |
|---------------|--------|-------------------------|
| `user` | Users (dating, follows) | like, pass, super_like |
| `event` | Events | favorite, interested |
| `post` | Blog posts | like |
| `product` | Products | favorite, bookmark |
| `location` | Locations | favorite |

Plugins can introduce new entity types and reaction types without schema changes.

---

## Messaging / Conversations

Conversations are core; the **Messages app** (the `messenger` plugin) is the
member-facing surface over them. A conversation holds two people or a small
group, carries a protection level from the platform ladder, and can reach
someone whose account lives on a different Joinery instance.

The data layer is core because several consumers share it: the Messages app,
the iOS member surface, and any plugin that wants to start a conversation (the
dating plugin opens one on a mutual like). The plugin owns the app — the views,
the `messenger_*` actions, the polling, the assets.

**Specs:** [Joinery Messenger](/specs/implemented/joinery_messenger.md)

### Data model

**Table:** `cnv_conversations`

| Column | Type | Description |
|--------|------|-------------|
| `cnv_conversation_id` | int8, serial, PK | |
| `cnv_subject` | varchar(255) | The group's name; null for an unnamed thread |
| `cnv_guid` | varchar(36) | Stable identity across instances — both sides of a federated conversation carry the same one |
| `cnv_protection_level` | varchar(20) | `standard` / `private` / `guarded` (see Protection levels) |
| `cnv_create_time` | timestamp | |
| `cnv_update_time` | timestamp | Moves with every message; the inbox delta reads it |
| `cnv_delete_time` | timestamp | Soft delete |

**Table:** `cnp_conversation_participants`

| Column | Type | Description |
|--------|------|-------------|
| `cnp_conversation_participant_id` | int8, serial, PK | |
| `cnp_cnv_conversation_id` | int8, FK | |
| `cnp_usr_user_id` | int4, FK | |
| `cnp_last_read_time` | timestamp | Messages after this time are unread; read receipts derive from it |
| `cnp_is_muted` | bool | Suppresses notifications (default false) |
| `cnp_is_admin` | bool | May manage group membership and the name |
| `cnp_create_time` | timestamp | |
| `cnp_delete_time` | timestamp | The member cleared the conversation out of their own inbox; a new message brings it back |

Leaving a group **removes** the row. `cnp_delete_time` means "not in my inbox
right now", which a new message undoes — that is not what leaving means.

**Table:** `msg_messages`

| Column | Type | Description |
|--------|------|-------------|
| `msg_cnv_conversation_id` | int8, FK | The conversation |
| `msg_usr_user_id_sender` | int4, nullable | Null for a system message and for one that arrived from another instance |
| `msg_remote_sender_address` | varchar(255) | The attributed sender when there is no local user row |
| `msg_guid` | varchar(36) | Stable identity across instances; the receiver dedups on it |
| `msg_message_type` | varchar(20) | `text` or `system` |
| `msg_reply_to_message_id` | int8, nullable | The quoted parent, always inside the same conversation |
| `msg_body` | text | The words — ciphertext on a sealed conversation |
| `msg_content_sealed` / `msg_sealed_key` / `msg_sealed_owner_user_id` / `msg_key_generation` | — | Sealed Vault columns (see Protection levels) |
| `msg_delivery_state` | varchar(16) | `local` / `queued` / `delivered` / `failed` — only meaningful cross-instance |
| `msg_delivery_attempts` / `msg_delivery_next_try` | — | The outbound retry queue |

**Table:** `msr_message_reactions` — one row per (message, reactor, emoji).
`msr_usr_user_id` for a local member, `msr_remote_address` for one on another
instance.

**Table:** `msa_message_attachments` — the manifest of what a message carries:
`msa_fil_file_id`, filename, MIME type, byte size, and `msa_is_sealed`.

**Table:** `ckg_conversation_key_grants` — the conversation key wrapped to one
member's vault public key (see Protection levels).

**Table:** `crp_conversation_remote_peers` — the far party of a cross-instance
conversation: address, domain, display name. Never a user row.

**Classes:** `Conversation` / `MultiConversation`, `ConversationParticipant` /
`MultiConversationParticipant`, `Message` / `MultiMessage`, `MessageReaction`,
`MessageAttachment`, `ConversationKeyGrant`, `ConversationRemotePeer` — all in
`data/`. `ConversationSealing` (`includes/`) owns sealed attachment bytes.

**Index:** `(msg_cnv_conversation_id, msg_sent_time DESC)` on `msg_messages`.

### Usage

**A 1:1 conversation, and a message in it:**
```php
$conversation = Conversation::get_or_create_conversation($sender_user_id, $recipient_user_id);
$message = $conversation->add_message($sender_user_id, 'Are you coming on Saturday?');
```

`get_or_create_conversation()` matches only the unnamed 1:1 between the two
people. A **named** two-person conversation is a group that happens to hold two
members — other people can be added to it — so it is never reused as the DM.

`add_message()` stores the message, seals it when the conversation is protected,
clears `cnp_delete_time` for every participant, moves `cnv_update_time`, fans out
notifications (respecting mutes), and emits `NOTIFY message_events`.

Its third argument carries everything optional:

```php
$conversation->add_message($user_id, $body, array(
    'reply_to_message_id' => $parent_id,   // dropped unless the parent is in this conversation
    'attachments'         => array($file), // File objects; sealed with the message when protected
    'message_type'        => Conversation::TYPE_SYSTEM,
    'guid'                => $guid,        // an arriving cross-instance message keeps its identity
    'remote_sender_address' => 'alice@othersite.com',
    'dek'                 => $key,         // a key already in hand, for a change that revokes its own grant
));
```

**A group:**
```php
$conversation = Conversation::create_conversation(
    array($alice, $bob, $carol), 'Ski Trip', array('admin_user_id' => $alice));

$conversation->add_participant($dave, $alice);      // admins only
$conversation->remove_participant($carol, $alice);  // admins only
$conversation->leave($bob);                         // anyone, for themselves
$conversation->rename('Ski Trip 2027', $alice);     // admins only
$conversation->set_admin($dave, true, $alice);
```

Every one of those writes a `system` message through the ordinary message funnel,
so the change polls, notifies and orders like anything else. A group that loses
its last admin promotes its longest-standing member.

`create_conversation()` refuses a protected level: protection is a ceremony
(mint a key, hand it to everyone, seal the history), so a conversation is created
Standard and raised.

**Reading:**
```php
$conversation->is_group();                  // >2 people, or it has a name
$conversation->title_for($user_id);         // the group name, or who else is in it
$conversation->participant_user_ids();
$conversation->is_admin($user_id);
$conversation->remote_peers();              // address => display name
Conversation::get_unread_count($user_id);   // session-cached in $_SESSION['message_unread_count']
```

`Conversation::url_for($id)` is where a member is sent to read a conversation —
the messenger when its plugin is active and its `messenger_active` switch is on,
the older thread view otherwise. The `serve.php` handoff from the older routes
honours the same pair of conditions, so switching the app off (plugin still
installed) hands messaging back to the older pages instead of redirecting into
an app that refuses. Notification links and the header badge both read it, so
the surfaces never disagree.

### Protection levels

A conversation sits on one rung of the platform ladder (`ProtectionLevel`):

| Level | What it means |
|---|---|
| `standard` | The server manages the conversation. Plaintext rows, content-ful notifications. |
| `private` | Message bodies and attachment bytes are ciphertext at rest. The server reads them only while a participant is present with an open unlock window. |
| `guarded` | Private, plus no message content in any notification, the AI participant pinned to local models, and no unsealed federation. |

**Fortress is deliberately not offered.** Client custody for a multi-party thread
is a different key-management problem — per-participant browser ceremonies,
re-sealing on every membership change, client-side decrypt of every render — and
a service that cannot honestly offer it shows no card for it.

**One key per conversation**, wrapped separately to each participant in
`ckg_conversation_key_grants`. That is the one thing the messenger does
differently from every other sealed model, which seals to a single owner:

```php
$conversation->raise(ProtectionLevel::PRIVATE_, $actor_user_id);
```

The raise mints the key, grants it to every participant, and re-seals the whole
history in one pass — bodies through `Message::sealBody()`, attachment bytes
through `ConversationSealing::sealAttachment()`. It refuses while any local
participant has no vault, naming who: `$conversation->members_without_vault()`.
It needs no unlock window, because sealing needs only public keys.

**Levels only tighten.** Lowering is refused **at the column itself** —
`Conversation::set()` throws on any write that would move
`cnv_protection_level` down a rung, so no surface (the generic REST PUT
included) can lower a conversation below what it reached. For a shared room
that also settles a consent problem: one member must not be able to expose
everyone's history.

**Reading is locked, never ciphertext.** `Message::get('msg_body')` resolves the
key through whichever present participant's grant opens
(`ConversationKeyGrant::openConversationKey()`, cached per request once opened)
and raises `VaultLockedException` when nobody is. At the edges that becomes a
`423 Locked` from the single-object REST read, a masked row with
`content_locked: true` in a REST collection, `is_locked: true` in the thread
payload, and a one-tap unlock in place of the words. Inbox previews are resolved
in the model layer too: `MultiConversation` routes a sealed latest body through
the decrypt — the words while a window is open, a locked stand-in otherwise — so
no consumer of the inbox query can render raw sealed bytes as a preview.

**Writing goes through the sealing path only.** `Conversation::add_message()` is
the one writer that holds the conversation key, so the generic REST write on
Message refuses a protected conversation for everyone, staff included — and on
any conversation it requires the caller to be a participant.

**Membership:** adding a member wraps the key to them first — a member who cannot
be given the key is not added at all, rather than added to a conversation they
cannot read. Removing deletes their grant. A vault key rotation re-wraps the
grants (`ConversationKeyGrant::resealForUser()`, registered as the plugin's
`onReseal`) and rewrites no message and no attachment.

**Metadata stays plaintext**, stated plainly: who talks to whom, when, group
names, group photos and message counts are operational metadata. Sealing covers
bodies and attachment content, not the social graph.

The picker a member chooses with is the shared `ProtectionLevelPicker`, which
owns the card copy for every service on the platform.

### Near-realtime delivery

The Messages app keeps itself current by polling, not by holding a connection: a
held stream pins a php-fpm worker per open tab, and the worker pools on a
deployment are small. One action carries everything.

`POST /api/v1/action/messenger/messenger_poll`

| Param | Meaning |
|---|---|
| `conversation_id` | The open conversation, if any |
| `after_message_id` | Cursor; everything above this id is new to the caller. `0` means from the start |
| `list_since` | UTC timestamp; conversations touched since then |
| `typing` | The caller's own typing state, piggybacked so typing needs no endpoint |
| `mark_read` | Mark the open conversation read |

The response carries new messages, `updates` (reaction and tombstone state for
the most recent stretch of the thread — a reaction changes a bubble the cursor
has already passed), each participant's read position, who is typing, and the
inbox delta.

Cadence is the browser's: 3 s with a conversation open, 12 s on the list alone,
paused while the tab is hidden, and poked immediately after the member does
something. Both intervals are plugin settings. The loop itself is the shared
helper `assets/js/joinery-poll.js`.

**Typing indicators never touch the database.** They live in APCu under
`messenger:typing:{conversation_id}` with an ~8 s TTL (`MessengerTyping`), which
is the honest storage for a fact that is worthless the moment it is stale. With
APCu unavailable the indicator simply never appears.

**`NOTIFY message_events`** is emitted at the end of `add_message()`, carrying
`{conversation_id, message_id, sent_time}`. A NOTIFY with nobody listening costs
essentially nothing, and it is the one seam a realtime service needs: it LISTENs
and pushes over its own transport while the poll endpoint stays as the fallback.

### Attachments

A photo or file is uploaded first (so the composer can show it) and claimed onto
a message when the member presses send:

1. `messenger/messenger_upload` — multipart, one `file` field. Stores a private
   `File` tagged `File::SOURCE_MESSENGER_ATTACHMENT`, owned by the uploader alone.
2. `messenger/messenger_send` with `attachment_ids` — `MessengerUploads::claim()`
   re-points each file at the conversation and hangs an `msa` row off the message.

An attachment is exactly as private as its conversation: the `File` carries the
platform's content access gate (`fil_access_provider = messenger_conversation`,
`fil_access_ref` = the conversation id) and `MessengerAttachmentGate` answers
"is this viewer in that room" on every serve. That buys the ordinary `/uploads/`
URL, thumbnails and range requests with no second serving path.

On a protected conversation the bytes are rewritten as a `SealedFileContainer`
under the conversation key and the file is marked Private, so every read goes
through the streaming decrypt hook the plugin registers.

### Cross-instance messaging

Two members on different Joinery deployments can hold a 1:1 conversation. The
message travels over Joinery Direct — chat is that channel's second payload kind
and adds no endpoint, no DNS record, no key custody and no new wire surface.
See [Joinery Direct](joinery_direct.md).

**Your Joinery mail address is your chat handle.** Consent is Direct Mail's,
verbatim: a chat message is accepted only if the sender's address is in the
recipient's `imc_mailbox_contacts`, matched with a sending domain bound to the
verified instance signature. Chat spam is structurally impossible for the same
reason direct-mail spam is; the bootstrap for strangers is email first, get added
to contacts, then chat works.

`ChatDirectHandler` is the whole receiving surface — it declares
`"gate": "contacts"` so the framework runs the canned gate, and implements only
`ingest`. Everything chat-specific rides in a header part
(`application/vnd.joinery.chat+json`) carrying `type`
(`message` / `reaction` / `delete`), `cnv_guid`, `msg_guid`, `sent_time` and
`reply_to_guid`; the words ride as the body part and attachments as their own.

A conversation is keyed on **(guid, peer address)**: a guid comes from a peer and
is theirs to choose, so binding it to the sender is what stops one peer landing a
message in a conversation with somebody else. The peer is a
`crp_conversation_remote_peers` row — no shadow user accounts.

A declined delivery is **discarded locally**, never refused on the wire: the
sender was already answered `accept`, and any other answer would make the
endpoint a way to probe whose contacts you are in.

**Sending** goes through `MessengerFederation`. Chat maps no result to email — a
message typed into a chat bubble stays one. A failure that might change is queued
with a doubling backoff and drained by the `DrainChatOutbox` scheduled task; a
refusal is final and never retried. Delivery state drives the sender's ticks:
`queued` (clock) → `delivered` (check). Read receipts and typing do not cross
instances.

**Before the member types**, the compose surface asks
`messenger_action` with `action: reachability` and says what will happen: an
address that cannot be reached by chat says so and offers to send an email
instead. A refusal, a missing capability record and an instance too old to
understand the chat kind are indistinguishable by design — all of them read as
not-chat-reachable.

**Guarded refuses to send unsealed.** Direct permits an opportunistic
plaintext-over-TLS delivery when the far side published no key; at Guarded that
trade is not on offer. The send carries Direct's `require_sealed` option, so the
refusal happens **between preflight and transfer** — no content byte crosses the
wire — and it is final: a keyless instance is a posture, not a blip, and the
member resends once the far side has a vault.

**Arriving into a raised conversation waits for a key.** A message landing in a
conversation the local member raised to Private or Guarded can only be stored
sealed, and the key opens only while a participant has an open window. With
nobody present the handler throws `DirectDeferIngest` before any attachment byte
touches the disk; the framework holds the delivery and re-ingests it at the
recipient's next unlock. An outbound message with a sealed attachment defers the
same way on the sender's side — it is never sent as a header-only envelope
because its files could not be opened.

Cross-instance **groups** are not offered: a federated group needs membership
propagation, fan-out from every member's home instance, and ordering
reconciliation across N instances. The local model does not fight it later
(`cnv_guid` is global, `crp` holds N peers), but it is its own design. Groups are
same-instance.

### API surface

The Messages app's actions, all under the plugin namespace and all requiring a
session and re-checking participation on every call:

| Action | Params | Returns |
|--------|--------|---------|
| `messenger/messenger_poll` | see Near-realtime delivery | New messages, reaction/tombstone updates, read positions, typists, inbox delta, unread total |
| `messenger/messenger_thread` | `conversation_id`, `before_message_id`, `mark_read` | A page of messages; opening also returns the thread header |
| `messenger/messenger_send` | `conversation_id` OR `to`; `body`, `reply_to_message_id`, `attachment_ids` | The stored message, in the same shape the poll uses |
| `messenger/messenger_action` | `action`: `open` / `open_remote` / `reachability` / `mute` / `unmute` / `delete` / `mark_read` / `react` / `delete_message` / `protection` | Per action |
| `messenger/messenger_group` | `action`: `create` / `rename` / `add_member` / `remove_member` / `leave` / `set_admin` / `set_photo` / `remove_photo` | The updated conversation |
| `messenger/messenger_upload` | multipart, one `file` field | An attachment id for `messenger_send` to claim |
| `messenger/messenger_people` | `q`, `exclude_conversation_id` | Members matching by name — names and pictures only, never addresses |

The four core actions the iOS member surface calls
(`ios/joinery-kit/Sources/JoineryMemberKit`) are unchanged and stay that way:

| Action | Params | Returns |
|--------|--------|---------|
| `conversation_list` | `offset` (20/page) | Paginated inbox |
| `conversation_thread` | `conversation_id` OR `to`; `before`/`after` ISO UTC cursors | One conversation's messages; marks it read |
| `conversation_send` | `body` + (`conversation_id` OR `to`) | The created message |
| `conversation_action` | `conversation_id`, `action` (`mute` / `unmute` / `delete`) | `{conversation_id, action}` |

### Blocking

**The platform has no user-blocking system.** There is no block model, no block
table, and no way for one member to block another.

`Conversation::get_or_create_conversation()` and `add_message()` each contain a
`class_exists('UserBlock')` branch that would reject a blocked pair. No such
class exists, so the branch never runs and the messaging API performs no block
checks at all. The branch is the integration point if blocking is ever built.

**A caller that needs to restrict who may message whom must enforce it itself,
before calling the messaging API.** The messaging system will not do it.

Cross-instance, the block model is Direct Mail's: remove the contact, plus a
sender-matched filter rule. There is no separate block store, so a blocked sender
is a non-contact on the wire and gets the same answer as any stranger — block
status stays unobservable.

### Plugin usage

Plugins use the Conversation API directly:

```php
// On a mutual like
$conversation = Conversation::get_or_create_conversation($user_id_1, $user_id_2);
```

Plugins that want to restrict who can message whom handle that in their own
routes and logic before calling the messaging API. The messaging system itself
has no gating — it is an API for creating conversations and sending messages.

### Routes

| Route | Description |
|-------|-------------|
| `/profile/messenger` | The Messages app |
| `/profile/messenger?c=N` | With conversation N open |
| `/profile/conversations`, `/profile/conversation?id=N` | Redirect to the app while the messenger plugin is active; serve the older thread views when it is not |

The header envelope icon points at whichever surface this deployment runs.

### Admin

| Route | File | Description |
|-------|------|-------------|
| `/admin/admin_conversations` | `adm/admin_conversations.php` | Browse all conversations (permission 8) — kind, name and protection level per row |
| `/admin/admin_conversation?cnv_conversation_id=N` | `adm/admin_conversation.php` | One conversation + moderation (permission 8) — membership with roles, system messages, attachments, remote senders |

Admin actions: soft-delete individual messages, soft-delete an entire
conversation. A protected conversation's bodies read as locked here like
anywhere else — moderation sees that a message exists, not what it says, unless
a participant is present.

### Settings

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `messaging_active` | bool | true | The platform switch for member messaging |
| `messenger_active` | bool | true | The Messages app itself |
| `messenger_default_protection_level` | select | `standard` | What a new conversation starts at |
| `messenger_max_group_size` | number | 32 | People per group |
| `messenger_max_attachment_mb` | number | 25 | Per file |
| `messenger_poll_thread_seconds` | number | 3 | Refresh rate with a conversation open |
| `messenger_poll_list_seconds` | number | 12 | Refresh rate on the list alone |

Max message length is a class constant: `Conversation::MAX_MESSAGE_LENGTH = 5000`.

### Shared chat styles

The bubbles, composer, reactions, typing dots and system chips are `.jy-chat-*`
classes in the shared kit stylesheet, consumed by both the Messages app and the
AI chat so the two read as one family. Sharing stops at styles and small
utilities: there is deliberately no shared thread *component*, because the AI
chat is turn-based (streaming partials, tool activity) and the messenger is
multi-party (receipts, typing, reactions, replies), and one component serving
both would grow an option flag per difference.
