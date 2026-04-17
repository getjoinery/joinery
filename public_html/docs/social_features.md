# Social Features

Core platform features for user-to-user interaction: reactions (likes, favorites, bookmarks), blocking, reporting, and messaging. These are generic systems used by any interactive site -- the dating plugin and others add domain-specific behavior on top.

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

### AJAX Endpoint

**File:** `ajax/reaction_ajax.php`

| Action | Method | Params | Response |
|--------|--------|--------|----------|
| `toggle` | POST | `entity_type`, `entity_id`, `reaction_type` (opt) | `{success, action, count}` |
| `status` | GET | `entity_type`, `entity_id` | `{reacted, count}` |
| `count` | GET | `entity_type`, `entity_id` | `{count}` |

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

Threaded user-to-user messaging with conversation grouping, read status, and unread counts. The messaging system is a core API that any plugin can use -- for example, the dating plugin creates conversations automatically when users match.

**Spec:** [Messaging Enhancements Spec](/specs/implemented/messaging_enhancements_spec.md)

### Data Model

**Table:** `cnv_conversations`

| Column | Type | Description |
|--------|------|-------------|
| `cnv_conversation_id` | int8, serial, PK | |
| `cnv_subject` | varchar(255) | Optional subject line (nullable) |
| `cnv_create_time` | timestamp | |
| `cnv_update_time` | timestamp | |
| `cnv_delete_time` | timestamp | Soft delete |

**Table:** `cnp_conversation_participants`

| Column | Type | Description |
|--------|------|-------------|
| `cnp_conversation_participant_id` | int8, serial, PK | |
| `cnp_cnv_conversation_id` | int8, FK | |
| `cnp_usr_user_id` | int4, FK | |
| `cnp_last_read_time` | timestamp | Messages after this time are unread |
| `cnp_is_muted` | bool | Suppresses notifications (default false) |
| `cnp_create_time` | timestamp | |
| `cnp_delete_time` | timestamp | User "deleted" conversation for themselves |

Unique constraint on `(cnp_cnv_conversation_id, cnp_usr_user_id)`.

**Table:** `msg_messages` (existing, modified)

Added column:

| Column | Type | Description |
|--------|------|-------------|
| `msg_cnv_conversation_id` | int8, FK | Links message to a conversation (nullable for legacy broadcast messages) |

**Classes:** `Conversation`, `MultiConversation` in `data/conversations_class.php`; `ConversationParticipant`, `MultiConversationParticipant` in `data/conversation_participants_class.php`

**Required index:** `(msg_cnv_conversation_id, msg_sent_time DESC)` on `msg_messages` for efficient inbox and conversation queries.

### Usage

**Get or create a 1:1 conversation and send a message:**
```php
require_once(PathHelper::getIncludePath('data/conversations_class.php'));

$conversation = Conversation::get_or_create_conversation($sender_user_id, $recipient_user_id);
$message = $conversation->add_message($sender_user_id, 'Hey, are you coming to the event?');
```

`get_or_create_conversation()` returns the existing conversation if one already exists between the two users. `add_message()` creates the message, clears `cnp_delete_time` for all participants (resurfaces deleted conversations), and creates notifications for other participants (unless muted).

**Create a conversation with explicit participant list:**
```php
$conversation = Conversation::create_conversation([$user_id_1, $user_id_2], 'Optional subject');
```

**Check unread conversation count for a user:**
```php
$unread = Conversation::get_unread_count($user_id);
```

This is session-cached in `$_SESSION['message_unread_count']` — the header icon reads from cache and only queries the database on cache miss.

**Get the other participant in a 1:1 conversation:**
```php
$other_user = $conversation->get_other_participant($current_user_id);
// Returns User object
```

**Check if a user is in a conversation:**
```php
if ($conversation->has_participant($user_id)) {
    // user can view/send messages
}
```

**Load messages in a conversation:**
```php
require_once(PathHelper::getIncludePath('data/messages_class.php'));

$messages = new MultiMessage(
    ['conversation_id' => $conversation->key, 'deleted' => false],
    ['msg_sent_time' => 'ASC'],
    50  // limit
);
$messages->load();
```

**Mark a conversation as read:**
```php
// Load the participant row for this user
$participants = new MultiConversationParticipant(
    ['conversation_id' => $conversation->key, 'user_id' => $current_user_id]
);
$participants->load();
$participant = $participants->get(0);
$participant->set('cnp_last_read_time', gmdate('Y-m-d H:i:s'));
$participant->save();

// Invalidate session cache
$_SESSION['message_unread_count'] = null;
```

### Block System Integration

`Conversation::get_or_create_conversation()` and `add_message()` check for blocks before proceeding. If either user has blocked the other, a `ConversationException` is thrown. Plugins don't need to check blocks separately -- the messaging API handles it.

### Plugin Usage

Plugins use the Conversation API directly from their own logic. For example, the dating plugin:

```php
// In plugins/dating/logic/match_logic.php — on mutual like
require_once(PathHelper::getIncludePath('data/conversations_class.php'));

$conversation = Conversation::get_or_create_conversation($user_id_1, $user_id_2);
// Conversation is now ready for the matched users to message in
```

Plugins that want to restrict who can message whom (e.g., dating match-only messaging) handle that in their own routes and logic before calling the messaging API. The messaging system itself has no gating -- it's just an API for creating conversations and sending messages.

### AJAX Endpoint

**File:** `ajax/conversations_ajax.php`

| Action | Method | Params | Response |
|--------|--------|--------|----------|
| `send_message` | POST | `body` + (`conversation_id` OR `recipient_user_id`) | `{success, conversation_id, message_html, message_id, sent_time}` |
| `mark_read` | POST | `conversation_id` | `{success}` |
| `delete_conversation` | POST | `conversation_id` | `{success}` |
| `mute_conversation` | POST | `conversation_id` | `{success, is_muted}` |
| `unmute_conversation` | POST | `conversation_id` | `{success, is_muted}` |

All actions require login. `send_message` with `recipient_user_id` calls `get_or_create_conversation()` automatically. Actions that take `conversation_id` verify the user is a participant.

### Header Icon

An envelope icon with unread count badge appears in the header between the cart and notification bell icons. The count is session-cached (`$_SESSION['message_unread_count']`) and recomputed on cache miss via `Conversation::get_unread_count()`. Cache is invalidated when sending, reading, or deleting conversations.

### Public Routes

| Route | View | Description |
|-------|------|-------------|
| `/profile/conversations` | `views/profile/conversations.php` | Inbox — conversation list with unread indicators |
| `/profile/conversation?id=N` | `views/profile/conversation.php` | Single conversation — message history + compose |
| `/profile/conversation?new=1&to=N` | `views/profile/conversation.php` | Compose mode — new conversation with a user |

### Admin

| Route | File | Description |
|-------|------|-------------|
| `/admin/admin_conversations` | `adm/admin_conversations.php` | Browse all conversations (permission 8) |
| `/admin/admin_conversation?id=N` | `adm/admin_conversation.php` | View conversation + moderate (permission 8) |

Admin actions: soft-delete individual messages, soft-delete entire conversation.

### Settings

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `messaging_active` | bool | true | Feature toggle — disables all user-to-user messaging when false |

Max message length is a class constant: `Conversation::MAX_MESSAGE_LENGTH = 5000`.
