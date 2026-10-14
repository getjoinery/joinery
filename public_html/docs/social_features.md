# Social Features

Core platform features for user-to-user interaction: reactions (likes, favorites, bookmarks), messaging, content reports, and the pieces every community feature shares — spaces (who may read and post), mentions, full-text search and attachments on posts. These are generic systems used by any interactive site -- the dating plugin and others add domain-specific behavior on top.

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
| `forum_post` | Forum posts | like |

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
| `cnv_protection_level` | varchar(20) | `standard` / `private` (see Protection levels) |
| `cnv_sealed_exits_only` | bool | The **Nothing leaves unsealed** add-on on a Private conversation; one-way (default false) |
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

**Add-on: Nothing leaves unsealed** (`cnv_sealed_exits_only`). Extra protection
on a Private conversation, offered as a switch under the Private card: "No
message text appears in notifications or crosses to another server
unencrypted." Its cost: "Notifications don't show the message, and people on
servers without encryption can't be reached." With it on, `Conversation::add_message()` writes notifications that
say only that there is something new, and federation sends with Direct's
`require_sealed` (see Cross-instance messaging). `Conversation::sealed_exits_only()`
answers it; the flag is inert on a Standard row.

```php
$conversation->turn_on_sealed_exits_only($actor_user_id);
```

It takes the same authority as a raise — any participant, and only on a Private
conversation — writes a sealed system message ("Alice turned on Nothing leaves
unsealed"), and is one-way: `Conversation::set()` refuses turning the flag off,
for the same consent reason levels only tighten. Wherever the level is shown as
a chip — the thread header, the admin conversation list — it reads "Private+"
when the add-on is on, and hovering names it (`ProtectionLevelPicker::summary()`
and `summaryTitle()`).

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
owns the card copy — and the add-on copy — for every service on the platform. A
consumer passes `addons => [key => [checked, disabled]]`; each renders as a
FormWriter switch under an **Extra protection** heading, submitted as
`{field}_{key}` and shown only while Private is selected (FormWriter
`visibility_rules`), and the selected card lists the add-ons that are on. What
only one service needs to say about an add-on goes in its `note` (a sentence
after the catalog's two) and `link` (`[url, label]`, shown under the switch);
`addons_note` puts one sentence under the heading, and a consumer's own
`visibility_rules` are merged into the picker's so fields outside it can follow
the level.

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
   `File` tagged `MessengerUploads::FILE_SOURCE`, owned by the uploader alone.
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

**Nothing leaves unsealed refuses to send unsealed.** Direct permits an
opportunistic plaintext-over-TLS delivery when the far side published no key;
with the add-on on that trade is not on offer. The send carries Direct's `require_sealed` option, so the
refusal happens **between preflight and transfer** — no content byte crosses the
wire — and it is final: a keyless instance is a posture, not a blip, and the
member resends once the far side has a vault.

**Arriving into a raised conversation waits for a key.** A message landing in a
conversation the local member raised to Private can only be stored
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
| `messenger/messenger_action` | `action`: `open` / `open_remote` / `reachability` / `mute` / `unmute` / `delete` / `mark_read` / `react` / `delete_message` / `protection` (with `protection_level` to raise and/or `sealed_exits_only` to turn on Nothing leaves unsealed; neither reads the current state) | Per action |
| `messenger/messenger_group` | `action`: `create` / `rename` / `add_member` / `remove_member` / `leave` / `set_admin` / `set_photo` / `remove_photo`; `create` takes `protection_level` and `sealed_exits_only` | The updated conversation |
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
| `messenger_default_protection_level` | select | `standard` | What a new conversation starts at (`standard` / `private`) |
| `messenger_default_sealed_exits_only` | bool | false | Whether a new Private conversation starts with Nothing leaves unsealed on |
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

---

## Content Reports

Members report other people's content — a forum post, a chat message — for an
admin to look at, and admins settle the reports in one queue at
`/admin/admin_content_reports` (menu: Users → Content Reports, permission 5; the
entry shows how many reports are open). The queue knows nothing about any one
feature: each feature that lets members report something registers a
**handler** for its content, and the queue asks the handler everything about
that content.

### Data model

`ContentReport` / `MultiContentReport` (`data/content_reports_class.php`),
table `rpt_content_reports`:

| Column | Meaning |
|--------|---------|
| `rpt_usr_user_id` | The reporter |
| `rpt_entity_type`, `rpt_entity_id` | The content: a handler's type and the content's own id (no foreign key, so one table serves every feature) |
| `rpt_reason` | `spam` / `abuse` / `off_topic` / `other` (`ContentReport::reasons()` gives the words) |
| `rpt_note` | Optional, at most `ContentReport::NOTE_MAX` characters |
| `rpt_status` | `open` / `actioned` / `dismissed` |
| `rpt_usr_user_id_handled_by`, `rpt_handled_time`, `rpt_outcome` | Who settled it, when, and what they did ("Hid the post") |

A person has at most one open report on a piece of content (a partial unique
index), so reporting twice is one row and one alert. An admin's decision settles
**every** open report on that content at once: acting on it marks them all
`actioned`, dismissing marks them all `dismissed`. A reporter may read their own
reports; only admins change them. Deleting a user sets either user column to the
deleted-user value, so the queue keeps its history.

### Writing a handler

Implement `ContentReportHandler` (`includes/ContentReportHandler.php`) for each
kind of content, and register it from the plugin's bootstrap:

```php
// plugins/forum/includes/bootstrap.php
ContentReportRegistry::register(new ForumReportHandler());
```

```php
class ForumReportHandler implements ContentReportHandler {
    public function entityType(): string { return 'forum_post'; }
    public function label(): string { return 'Forum post'; }

    // The reporter can see it and it is live.
    public function mayReport(int $entity_id, int $user_id): bool { ... }

    // What the queue shows; null when the content is gone.
    public function preview(int $entity_id): ?array {
        return array('title' => $topic_title, 'excerpt' => $plain_text,
                     'author_user_id' => $author_id, 'url' => '/forum/t/12/slug?post=345');
    }

    // Buttons the queue offers; Dismiss is the queue's own and never listed.
    public function actions(int $entity_id): array {
        return array('delete' => 'Remove post', 'lock' => 'Lock topic');
    }

    // Do it; return a short past-tense message. Throw with a readable message to
    // refuse — any exception: the queue shows its message and leaves the report open.
    public function act(int $entity_id, string $action, int $admin_user_id): string { ... }
}
```

`ContentReportRegistry::get($type)` and `all()` pull plugin bootstraps in
first, so callers never load them. `preview()`'s `url` must be a same-site path;
the queue links nothing else. A type whose plugin is inactive has no handler: its
reports stay listed and can only be dismissed.

### Filing a report

Page JavaScript calls the `content_report` API action with the browser session:

```js
joineryApi.post('content_report', {
    entity_type: 'forum_post', entity_id: 345,
    reason: 'spam', note: 'Same link posted in five topics'
});
```

The action refuses an unknown type, content the handler's `mayReport()` turns
down, or an unknown reason, each with a message to show. It is rate-limited per
member in the `content_report` bucket (`content_report_rate_limit_requests` /
`_window`, default 10 an hour). A new report dispatches `content.reported`,
whose notify block has the `admins` audience: every admin is told in-app and by
email unless they mute it on their notification preferences. A repeat report by
the same person answers success with the existing report and dispatches nothing.

Server code files a report with `ContentReport::file($type, $id, $user_id,
$reason, $note)`, which returns `[report, created]` and throws
`ContentReportException` on a refusal; `$report->act($action, $admin_id)` and
`$report->dismiss($admin_id)` settle one.

---

## Spaces: who may read and who may post

A **space** is any row that holds posts other people read: a forum category, a chat channel. `SpaceAccess` (`includes/SpaceAccess.php`) is the one decision every surface asks — a page view, a list, a search, a sitemap, the mention picker, an alert, a file gate — so none of them can disagree.

A space's model declares four columns under its own prefix:

| Column | Meaning |
|---|---|
| `{p}_read_rule` | `public` (signed-out visitors too) / `members` / `tier` / `group` / `invite` / `staff` |
| `{p}_post_rule` | the same, without `public` |
| `{p}_tier_min_level` | the tier level the `tier` rule asks for |
| `{p}_grp_group_id` | the user group the `group` rule asks for |

and two static properties: `$post_model`, the class of the posts it holds, and optionally `$space_members`, a `SpaceMembers` class that answers the `invite` rule from the consumer's own member table.

```php
SpaceAccess::mayRead($category, $user_id);      // 0 = signed out
SpaceAccess::mayPost($category, $user_id);      // needs read too; never signed out
SpaceAccess::mayModerate($category, $user_id);
// The same read decision as SQL, for lists and search:
$where = SpaceAccess::readableSql('ForumCategory', 'fcg', $user_id);
```

An unknown rule is closed, and so is a `tier` rule with no tier level set. A user id that is not a live, enabled account (deleted, disabled, no such row) reads nothing — public spaces included — so a watch or a mention never reaches, or names, an account that is gone. For a list of people (the recipients of an alert), `SpaceAccess::readableUserIds($space, $ids)` answers the same rule in one query, using each person's stored permission. A **moderator** is a user whose permission reaches the post model's `$write_permission`; moderators read, post and moderate every space, and `staff` means moderators only. The level is declared once, on the post model, and read from there. The signed-in user's permission is the session's own; anyone else's is read from their row. A user's tier level and groups are read once per request — call `SpaceAccess::forget()` after changing a membership mid-request.

A consumer names its space types from its plugin bootstrap, so a shared surface can take a reference like `forum_category:12`:

```php
SpaceAccess::registerType('forum_category', 'ForumCategory');
SpaceAccess::resolve('forum_category:12');      // the live row, or null
```

A space's models override `authenticate_read()` with `mayRead()`, and its post models write the rule "author, or a moderator, and on create `mayPost()`" in `authenticate_write()`, so the REST and AI surfaces meet the same rule as the pages.

## Mentions

Users have no unique username, so a typed `@sam` names nobody. A mention is a token the editor's picker writes into the markdown source:

```
@[Sam Lee](user:42)
```

`MarkdownRenderer` renders it as a chip (`<span class="jy-mention" data-user-id="42">@Sam Lee</span>`); inside code it stays text. `MentionParser` (`includes/MentionParser.php`) is the server side:

```php
// Before saving: keep a mention of someone who can be mentioned in the space,
// with their real name; write anyone else's as plain text. At most
// MentionParser::MAX_PER_POST (20) mentions are kept.
$body  = MentionParser::normalizeFor($body, $space);
$alert = MentionParser::userIds($body);         // who to tell, outside code only
```

Who can be mentioned is one rule, `MentionParser::mentionableName($user_id, $space)`: a live, enabled, activated account that may read the space. The picker offers exactly these and a save keeps exactly these, so typing ids by hand reveals no names the picker would not. What counts as code is `MarkdownRenderer`'s own patterns (`FENCE_PATTERN`, `DOUBLE_TICK_PATTERN`, `SINGLE_TICK_PATTERN`), so the parser and the renderer never disagree about whether a token is a mention.

The picker's data comes from the core `mention_search` action (`space`, `q`): people whose first name, last name, "first last" or nickname starts with at least two typed characters — never a match on email — limited to eight, to people who may read that space, and only for a caller who may post in it. It is rate-limited per member.

## Full-text search

`FullTextSearch` (`includes/FullTextSearch.php`) keeps the index and the query on one expression, which is what makes Postgres use the index. A data class declares the index literally (a property default cannot call a method) and its tests assert it equals `FullTextSearch::indexSpec()`:

```php
public static $index_specifications = array(
    array('columns' => array("to_tsvector('english', fpo_body)"), 'method' => 'gin'),
);
```

```php
list($match, $params) = FullTextSearch::match('fpo.fpo_body', $q);   // websearch syntax: "phrases", -exclude
list($rank, $rank_params) = FullTextSearch::rank('fpo.fpo_body', $q);
FullTextSearch::snippet($plain_text, $q);       // escaped excerpt with <mark> around matches
```

The language is the constant `FullTextSearch::CONFIG` (`english`), not a setting: changing it changes every index expression. A search over posts other people wrote always ANDs `match()` with `SpaceAccess::readableSql()` in the same query.

## Attachments on posts

`EntityAttachment` (`eat_entity_attachments`) lists the files on any row a member writes, by entity type and id, with name, type, size and order. The bytes are an ordinary `File`, which the consumer gates on the entity: `fil_access_provider` names its `AccessGateProvider`, `fil_access_ref` the entity, so the file is exactly as visible as the post through core's file serving. Each consumer stamps its own origin tag (`fil_source`), declared under `fileSources` in its `plugin.json`.

```php
EntityAttachment::forEntities('forum_post', $post_ids);   // one query for a page
EntityAttachment::removeAllFor('forum_post', $post_id);   // rows and Files, for good
```

`AccessGateRegistry::userMayAccess()` asks a provider about a signed-out visitor as user 0, so a gate whose content can be public (a post in a public forum category) can allow it; a member-only gate refuses 0.

## Markdown written by members

Text members write for each other renders through `MarkdownRenderer::render($text, true, true)` — the restricted profile: links only to http, https, mailto or relative targets, marked `nofollow ugc` and opening in a new tab; no images; headings pushed down two levels and given no id; mention chips; block quotes. A feature that stores the rendered HTML stores `MarkdownRenderer::VERSION` beside it and renders again when the version changes.

The editor for that text is FormWriter's markdown editor with `markdown_restricted` (no heading, image or table buttons, preview through the restricted profile) and, for mentions, `mention_space` (see [FormWriter](formwriter.md)).
