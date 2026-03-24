# Messaging Enhancements Spec

**Purpose:** Enhance the existing messaging system with conversation threading, read status, user-to-user compose, and a full inbox UI. This is a core platform feature useful for communities, dating, marketplaces, and membership sites.

**Last Updated:** 2026-03-24

**Parent Spec:** [Dating Platform Spec](dating_platform_spec.md) (section 1.7)

---

## Implementation Status

| Feature | Status | Notes |
|---------|--------|-------|
| **Data Models** | | |
| Conversation model | **DONE** | `cnv_conversations` table (2026-03-24) |
| Conversation participants model | **DONE** | `cnp_conversation_participants` table (2026-03-24) |
| Add `msg_cnv_conversation_id` FK to messages | **DONE** | Links existing messages to conversations (2026-03-24) |
| **Public UI** | | |
| Inbox (conversation list) | **DONE** | `/profile/conversations` route (2026-03-24) |
| Single conversation view | **DONE** | `/profile/conversation?id=N` route (2026-03-24) |
| Compose new message | **DONE** | `/profile/conversation?new=1&to=N` route (2026-03-24) |
| Unread message count in header | **DONE** | Envelope icon next to notification bell (2026-03-24) |
| **AJAX Endpoints** | | |
| Conversation AJAX | **DONE** | send_message, mark_read, delete_conversation, mute/unmute (2026-03-24) |
| **Admin** | | |
| Admin conversation list | **DONE** | Browse all conversations (2026-03-24) |
| Admin conversation view | **DONE** | View conversation with moderation actions (2026-03-24) |
| **Logic** | | |
| Conversations logic | **DONE** | Inbox loading, conversation loading, compose (2026-03-24) |
| Conversation helper methods | **DONE** | `create_conversation()`, `get_or_create_conversation()`, `get_unread_count()` (2026-03-24) |

---

## Existing System

The current messaging model (`msg_messages`) is a flat table used primarily for admin-to-user communication:

- **Fields:** `msg_message_id`, `msg_usr_user_id_sender`, `msg_usr_user_id_recipient`, `msg_evt_event_id`, `msg_body`, `msg_sent_time`, `msg_delete_time`
- **Use cases:** Admin sends to individual users, event registrants, or group members via `/admin/admin_users_message`
- **Gaps:** No conversation grouping, no read tracking, no user-to-user compose UI, no inbox

Admin broadcast messages (`msg_evt_event_id` set, `msg_usr_user_id_recipient` NULL) are event-scoped and continue to work independently of conversations. They do not need to be migrated into the conversation model.

---

## Design Decision: Option B — Conversations as First-Class Entities

Conversations are a new model that groups messages. The existing `msg_messages` table gains a FK to conversations. This approach:
- Supports future group messaging (3+ participants)
- Separates conversation metadata (participants, read state) from message content
- Allows per-participant controls (mute, delete for self)
- Keeps the existing admin broadcast flow unchanged

---

## Data Models

### New Model: `Conversation` (`cnv_conversations`)

```php
// data/conversations_class.php
class Conversation extends SystemBase {
    public static $prefix = 'cnv';
    public static $tablename = 'cnv_conversations';
    public static $pkey_column = 'cnv_conversation_id';

    public static $field_specifications = array(
        'cnv_conversation_id'     => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
        'cnv_create_time'         => array('type' => 'timestamp(6)'),
        'cnv_update_time'         => array('type' => 'timestamp(6)'),
        'cnv_delete_time'         => array('type' => 'timestamp(6)'),
    );
}
```

**No denormalized fields.** The inbox query uses `JOIN LATERAL` to fetch the latest message per conversation directly from `msg_messages`. This keeps the write path simple (one save per message, no conversation row to update) and avoids stale data risks. If performance becomes an issue at scale, denormalized fields (`cnv_last_message_time`, `cnv_last_message_preview`) can be added later as an optimization.

**Required index:** `CREATE INDEX idx_msg_conversation_time ON msg_messages (msg_cnv_conversation_id, msg_sent_time DESC)` — ensures the lateral join does a fast index scan per conversation.

### New Model: `ConversationParticipant` (`cnp_conversation_participants`)

```php
// data/conversation_participants_class.php
class ConversationParticipant extends SystemBase {
    public static $prefix = 'cnp';
    public static $tablename = 'cnp_conversation_participants';
    public static $pkey_column = 'cnp_conversation_participant_id';

    protected static $foreign_key_actions = [
        'cnp_usr_user_id' => ['action' => 'permanent_delete'],
        'cnp_cnv_conversation_id' => ['action' => 'permanent_delete']
    ];

    public static $field_specifications = array(
        'cnp_conversation_participant_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
        'cnp_cnv_conversation_id'         => array('type' => 'int8', 'required' => true),
        'cnp_usr_user_id'                 => array('type' => 'int4', 'required' => true),
        'cnp_last_read_time'              => array('type' => 'timestamp(6)'),
        'cnp_is_muted'                    => array('type' => 'bool', 'default' => false),
        'cnp_create_time'                 => array('type' => 'timestamp(6)'),
        'cnp_delete_time'                 => array('type' => 'timestamp(6)'),
    );
}
```

**Fields explained:**
- `cnp_last_read_time` — Set to `now()` when user opens the conversation. Messages with `msg_sent_time > cnp_last_read_time` are unread.
- `cnp_is_muted` — Suppresses notifications for new messages but conversation still appears in inbox.
- `cnp_delete_time` — User "deletes" conversation for themselves. Conversation reappears if a new message arrives (delete_time is cleared).

**Unique constraint:** `(cnp_cnv_conversation_id, cnp_usr_user_id)` — one row per user per conversation.

### Existing Model: `Message` (`msg_messages`) — Modifications

Add one field to `$field_specifications` in `data/messages_class.php`:

```php
'msg_cnv_conversation_id' => array('type' => 'int8'),
```

This is nullable to preserve backward compatibility with existing admin broadcast messages that don't belong to conversations.

### Multi Classes

**`MultiConversation`** — Filter options:
- `participant_user_id` (int) — Conversations where this user is a participant (JOIN through `cnp_conversation_participants`). This is the primary inbox query. Uses a custom query with `JOIN LATERAL` rather than `_get_resultsv2()` to fetch the latest message per conversation.
- `deleted` (bool) — Filter by `cnv_delete_time`
- Default sort: latest message `msg_sent_time DESC`

**`MultiConversationParticipant`** — Filter options:
- `conversation_id` (int) — All participants in a conversation
- `user_id` (int) — All conversations a user participates in
- `deleted` (bool) — Filter by `cnp_delete_time`

**`MultiMessage`** — Add filter option:
- `conversation_id` (int) — Messages in a conversation

---

## Helper Methods

### On `Conversation` class:

```php
/**
 * Get or create a 1:1 conversation between two users.
 * Returns existing conversation if one exists, creates new one otherwise.
 */
public static function get_or_create_conversation($user_id_1, $user_id_2);

/**
 * Create a conversation with given participant user IDs.
 * Returns the new Conversation object.
 */
public static function create_conversation($participant_user_ids, $subject = null);

/**
 * Add a message to this conversation.
 * Creates a Message record linked to this conversation.
 * Clears cnp_delete_time for all participants (resurfaces deleted conversations).
 * Creates notifications for other participants (unless muted).
 * Returns the new Message object.
 */
public function add_message($sender_user_id, $body);

/**
 * Get unread conversation count for a user — lightweight COUNT query.
 * A conversation is "unread" if it has messages newer than the participant's last_read_time.
 */
public static function get_unread_count($user_id);

/**
 * Get the other participant in a 1:1 conversation.
 * Returns User object or null for group conversations.
 */
public function get_other_participant($current_user_id);

/**
 * Check if a user is a participant in this conversation.
 */
public function has_participant($user_id);
```

### Unread Count Query

```sql
-- Used by get_unread_count() — session-cached like notifications
-- Checks for conversations with messages newer than the participant's last read time
SELECT COUNT(*)
FROM cnp_conversation_participants cnp
JOIN cnv_conversations cnv ON cnv.cnv_conversation_id = cnp.cnp_cnv_conversation_id
WHERE cnp.cnp_usr_user_id = :user_id
  AND cnp.cnp_delete_time IS NULL
  AND cnv.cnv_delete_time IS NULL
  AND EXISTS (
      SELECT 1 FROM msg_messages msg
      WHERE msg.msg_cnv_conversation_id = cnp.cnp_cnv_conversation_id
        AND msg.msg_delete_time IS NULL
        AND (cnp.cnp_last_read_time IS NULL OR msg.msg_sent_time > cnp.cnp_last_read_time)
  )
```

### Inbox Query

```sql
-- Used by MultiConversation with participant_user_id filter
-- JOIN LATERAL fetches the latest message per conversation via index scan
SELECT cnv.cnv_conversation_id, cnv.cnv_create_time,
       latest.msg_sent_time, latest.msg_body, latest.msg_usr_user_id_sender,
       cnp.cnp_last_read_time, cnp.cnp_is_muted
FROM cnv_conversations cnv
JOIN cnp_conversation_participants cnp
  ON cnp.cnp_cnv_conversation_id = cnv.cnv_conversation_id
JOIN LATERAL (
    SELECT msg_sent_time, msg_body, msg_usr_user_id_sender
    FROM msg_messages
    WHERE msg_cnv_conversation_id = cnv.cnv_conversation_id
      AND msg_delete_time IS NULL
    ORDER BY msg_sent_time DESC
    LIMIT 1
) latest ON true
WHERE cnp.cnp_usr_user_id = :user_id
  AND cnp.cnp_delete_time IS NULL
  AND cnv.cnv_delete_time IS NULL
ORDER BY latest.msg_sent_time DESC
LIMIT :limit OFFSET :offset
```

---

## Block System Integration

Before sending a message or creating a conversation, check if either user has blocked the other:

```php
// In Conversation::get_or_create_conversation() and add_message():
require_once(PathHelper::getIncludePath('data/user_blocks_class.php'));
if (UserBlock::is_blocked($user_id_1, $user_id_2)) {
    throw new ConversationException('Cannot message this user');
}
```

The block check is bidirectional — if A blocked B or B blocked A, neither can initiate or continue messaging.

---

## Public Views

### Inbox — `views/profile/conversations.php` (route: `/profile/conversations`)

Displays the user's conversation list, most recent first.

**Layout:**
```
┌─────────────────────────────────────────────────────┐
│  Messages                            [New Message]  │
├─────────────────────────────────────────────────────┤
│ ● Jane Smith                           2 min ago    │
│   Hey, are you coming to the event...               │
├─────────────────────────────────────────────────────┤
│   Bob Johnson                          Yesterday    │
│   Thanks for the info about the...                  │
├─────────────────────────────────────────────────────┤
│   Admin                               Mar 20        │
│   Welcome to the platform! Here...                  │
├─────────────────────────────────────────────────────┤
│              Page 1 of 3   [Older >>]               │
└─────────────────────────────────────────────────────┘
```

**Details:**
- `●` indicator (or bold styling) for conversations with unread messages
- Other participant's name (or "Group: ..." for multi-participant)
- Other participant's avatar thumbnail
- `cnv_last_message_preview` truncated to ~80 chars
- Relative time for recent ("2 min ago", "Yesterday"), absolute for older ("Mar 20")
- Click row to open conversation
- "New Message" button opens compose view
- 20 conversations per page with Pager

**View file structure** follows the notification list pattern — loop over conversations, render each as a `div.conversation-item`.

### Single Conversation — `views/profile/conversation.php` (route: `/profile/conversation?id=N`)

Displays messages in a conversation with a compose area at the bottom.

**Layout:**
```
┌─────────────────────────────────────────────────────┐
│  ← Back to Messages          Jane Smith    [⋮ More] │
├─────────────────────────────────────────────────────┤
│                                                     │
│  ┌──────────────────────────────────────┐           │
│  │ Hey, are you going to the meetup     │  10:30 AM │
│  │ next week?                           │           │
│  └──────────────────────────────────────┘           │
│                                                     │
│           ┌──────────────────────────────────────┐  │
│  2:15 PM  │ Yes! I was just about to ask you the │  │
│           │ same thing. Want to go together?      │  │
│           └──────────────────────────────────────┘  │
│                                                     │
│  ┌──────────────────────────────────────┐           │
│  │ Perfect, let's meet at 6pm at the    │  2:20 PM  │
│  │ entrance.                            │           │
│  └──────────────────────────────────────┘           │
│                                                     │
├─────────────────────────────────────────────────────┤
│ [Message input area...                    ] [Send]  │
└─────────────────────────────────────────────────────┘
```

**Details:**
- Messages displayed chronologically (oldest first)
- Current user's messages aligned right, other user's aligned left
- Sender name + avatar shown on other user's messages
- Timestamp on each message
- "More" menu: Mute conversation, Delete conversation, Block user, Report user
- Compose area at bottom with textarea and Send button
- On page load: set `cnp_last_read_time = now()` for current user (marks conversation as read)
- Invalidate session unread count cache
- Messages loaded with Pager — "Load older messages" link at top if more exist
- Send via AJAX (no page reload) — append new message to bottom of list
- 50 messages per page, most recent loaded first, displayed in chronological order

### Compose — `views/profile/conversation.php` with `new=1&to=N` params (route: `/profile/conversation?new=1&to=N`)

Not a separate view — the conversation view handles compose mode:
- If `new=1` and `to=N` params: show compose header with recipient name, empty message list, compose area
- On first message send: calls `Conversation::get_or_create_conversation()`, then `add_message()`
- Redirects to the conversation view after first message
- If conversation already exists between these two users, redirect to existing conversation

### "Send Message" Button on User Profiles

Add a "Send Message" link to user profile pages that links to `/profile/conversation?new=1&to={user_id}`. Only shown when:
- Viewer is logged in
- Viewer is not looking at their own profile
- Target user has not blocked the viewer (check via block system if implemented)

---

## Header Icon — Unread Message Count

Add an envelope icon to `PublicPageBase::top_right_menu()` between the cart and notification bell, following the exact same pattern:

### `PublicPageBase::get_menu_data()` — Add messages section:

```php
// 4b. Messages (after notifications block)
$menu_data['messages'] = [
    'enabled' => false,
    'unread_count' => 0,
    'view_all_link' => '/profile/conversations',
];

if ($is_logged_in) {
    try {
        $unread_count = isset($_SESSION['message_unread_count']) ? $_SESSION['message_unread_count'] : null;
        if ($unread_count === null) {
            require_once(PathHelper::getIncludePath('data/conversations_class.php'));
            $unread_count = Conversation::get_unread_count($session->get_user_id());
            $_SESSION['message_unread_count'] = $unread_count;
        }
        $menu_data['messages'] = [
            'enabled' => true,
            'unread_count' => (int)$unread_count,
            'view_all_link' => '/profile/conversations',
        ];
    } catch (Exception $e) {
        // Conversation system not yet installed — keep disabled
    }
}
```

### `PublicPageBase` — Add `render_message_icon()` method:

```php
public function render_message_icon($menu_data = null) {
    if ($menu_data === null) {
        $menu_data = $this->get_menu_data();
    }
    $messages = $menu_data['messages'];
    if (!$messages['enabled']) {
        return;
    }
    $unread = (int)$messages['unread_count'];
    echo '<a href="' . htmlspecialchars($messages['view_all_link'], ENT_QUOTES, 'UTF-8') . '" class="header-messages-link" title="Messages">';
    // Envelope SVG icon (same 20x20 style as notification bell)
    echo '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 4l-10 8L2 4"/></svg>';
    if ($unread > 0) {
        echo '<span class="messages-count">' . $unread . '</span>';
    }
    echo '</a>';
}
```

### `PublicPage::top_right_menu()` — Insert call:

```php
// Cart
// ...existing cart code...

// Messages (NEW)
$this->render_message_icon($menu_data);

// Notifications
$this->render_notification_icon($menu_data);

// Admin link
// ...existing code...
```

### CSS for message count badge:

```css
.messages-count {
    position: absolute;
    top: -6px;
    right: -8px;
    background: var(--color-primary);
    color: #fff;
    font-size: 0.625rem;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
}
```

Same positioning/styling as `.cart-count` and `.notifications-count`.

---

## AJAX Endpoint — `ajax/conversations_ajax.php`

Handles all conversation AJAX operations. Follows the `notifications_ajax.php` pattern.

**Actions:**

### `send_message`
- **Params:** `body` (string, required), plus one of: `conversation_id` (int) OR `recipient_user_id` (int)
- **Auth:** Must be logged in. If `conversation_id`: must be a participant. If `recipient_user_id`: cannot message self, block check.
- **Logic:** If `recipient_user_id` is provided, call `Conversation::get_or_create_conversation()` to find or create the conversation, then call `add_message()`. If `conversation_id` is provided, load the conversation and call `add_message()` directly.
- **Response:** `{ success: true, conversation_id: N, message_html: "...", message_id: N, sent_time: "..." }`
- **Side effects:** Invalidates `$_SESSION['message_unread_count']` for all other participants (they'll recompute on next page load). Creates notification for other participants (unless muted).

### `mark_read`
- **Params:** `conversation_id` (int)
- **Auth:** Must be participant
- **Logic:** Set `cnp_last_read_time = now()` for this user in this conversation. Invalidate session cache.
- **Response:** `{ success: true }`

### `delete_conversation`
- **Params:** `conversation_id` (int)
- **Auth:** Must be participant
- **Logic:** Set `cnp_delete_time = now()` for this user's participant row (soft delete for this user only). Other participants unaffected.
- **Response:** `{ success: true }`

### `mute_conversation` / `unmute_conversation`
- **Params:** `conversation_id` (int)
- **Auth:** Must be participant
- **Logic:** Toggle `cnp_is_muted`
- **Response:** `{ success: true, is_muted: bool }`

---

## Logic Layer — `logic/conversations_logic.php`

```php
function conversations_logic($get_vars, $post_vars) {
    // Login required (redirect to /login if not)
    // Load conversations for current user via MultiConversation
    // For each conversation, load the other participant's User object for display name/avatar
    // Pager: 20 per page
    // Return: conversations collection, pager, title
}
```

### `logic/conversation_logic.php` (single conversation)

```php
function conversation_logic($get_vars, $post_vars) {
    // Login required
    // If new=1&to=N: compose mode — validate recipient exists, not blocked, return empty conversation shell
    // If id=N: load conversation, verify current user is participant
    // Load messages via MultiMessage with conversation_id filter, ordered ASC
    // Pager: 50 per page (load most recent page by default, "Load older" at top)
    // Mark conversation as read: set cnp_last_read_time = now()
    // Invalidate $_SESSION['message_unread_count']
    // Load other participant(s) User objects
    // Return: conversation, messages collection, participants, pager, is_compose_mode
}
```

---

## Notification Integration

When a new message is sent via `add_message()`, create a notification for each other participant (unless muted):

```php
// In Conversation::add_message()
require_once(PathHelper::getIncludePath('data/notifications_class.php'));
$sender = new User($sender_user_id, TRUE);

foreach ($other_participants as $participant) {
    if ($participant->get('cnp_is_muted')) continue;

    Notification::create_notification(
        $participant->get('cnp_usr_user_id'),
        'message',
        'New message from ' . $sender->display_name(),
        substr(strip_tags($body), 0, 100),
        '/profile/conversation?id=' . $this->key,
        $sender_user_id
    );
}
```

---

## Admin Interface

### Admin Conversation List — `adm/admin_conversations.php`

Browse all conversations on the platform. Permission level 8 required.

**Table columns:**
| ID | Participants | Messages | Last Message | Last Activity | Status | Actions |
|----|-------------|----------|--------------|---------------|--------|---------|
| 42 | Jane Smith, Bob Johnson | 15 | "Hey, are you coming..." | Mar 23, 2026 2:15 PM | Active | View |
| 41 | Admin, Jane Smith | 3 | "Welcome to the..." | Mar 20, 2026 10:00 AM | Active | View |

**Filters:**
- Search by participant name or message content
- Filter by date range
- Filter by status (active / deleted)

**Uses:** Standard AdminPage pattern with `tableheader()` / `disprow()`.

### Admin Conversation View — `adm/admin_conversation.php`

View any conversation with full message history. Permission level 8 required.

**Displays:**
- Conversation metadata (ID, created time, participant list, message count)
- Full message history with sender names and timestamps
- Participant details (join time, last read time, mute status)

**Admin actions:**
- Delete individual messages (soft delete)
- Delete entire conversation (soft delete, affects all participants)

### Admin Message List — Update existing `adm/admin_message.php`

Add `msg_cnv_conversation_id` display with link to admin conversation view when present. No other changes needed — existing admin message views continue to work for broadcast messages.

---

## Migration Plan for Existing Messages

Existing messages in `msg_messages` that have both a sender and recipient (not event-broadcast-only) can optionally be migrated into conversations. This is **not required for launch** — old messages without a `msg_cnv_conversation_id` simply won't appear in the new inbox. They remain accessible through the existing admin interface.

**Optional post-launch migration:**
```sql
-- Group existing 1:1 messages into conversations
-- For each unique (sender, recipient) pair, create a conversation and link messages
-- This would be a file-based migration in /migrations/
```

---

## Session Cache Strategy

Following the notification pattern:

- **Session key:** `$_SESSION['message_unread_count']`
- **Set:** On first page load after login (via `get_menu_data()`)
- **Invalidate (set to null):**
  - When user sends a message (other participants' caches aren't invalidated server-side — they recompute on next page load)
  - When user opens a conversation (marks as read)
  - When user deletes a conversation
  - When a new notification of type `message` is created for the current user
- **Recompute:** On next page load when cache is null

---

## File Structure

```
data/
  conversations_class.php              # Conversation + MultiConversation
  conversation_participants_class.php  # ConversationParticipant + MultiConversationParticipant
  messages_class.php                   # MODIFY: add msg_cnv_conversation_id field

views/profile/
  conversations.php                    # Inbox — conversation list (route: /profile/conversations)
  conversation.php                     # Single conversation + compose mode (route: /profile/conversation)

logic/
  conversations_logic.php              # Inbox logic
  conversation_logic.php               # Single conversation logic

ajax/
  conversations_ajax.php               # send_message, start_conversation, mark_read, etc.

adm/
  admin_conversations.php              # Admin conversation list
  admin_conversation.php               # Admin conversation view + moderation

includes/
  PublicPageBase.php                    # MODIFY: add messages to get_menu_data(), add render_message_icon()
  PublicPage.php                        # MODIFY: call render_message_icon() in top_right_menu()
```

---

## Settings

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `messaging_active` | bool | true | Feature toggle — disables all user-to-user messaging when false |

Login is always required (enforced in logic). Max message length is a class constant on `Conversation` (`MAX_MESSAGE_LENGTH = 5000`).

---

## Security Considerations

1. **Authorization:** Every AJAX action verifies the user is a participant in the conversation
2. **Block enforcement:** Block checks on conversation creation and message sending
3. **XSS:** All message bodies are `htmlspecialchars()` escaped on output. Stored as plain text (no raw HTML in user messages, unlike admin broadcast)
4. **Rate limiting:** Consider adding rate limiting on message sends to prevent spam (post-MVP)
5. **Input validation:** Message body required, max length enforced, stripped of HTML tags on save
6. **Privacy:** Users can only see conversations they participate in. Admin (permission 8+) can view any conversation.

---

## CSS Classes

Follow the notification system's naming convention:

```css
/* Inbox */
.conversations-page { }
.conversations-list { display: flex; flex-direction: column; }
.conversation-item { display: flex; padding: 1rem; border-bottom: 1px solid #eee; cursor: pointer; }
.conversation-item.conversation-unread { background: #f0f7ff; }
.conversation-avatar { width: 40px; height: 40px; border-radius: 50%; margin-right: 1rem; }
.conversation-content { flex: 1; }
.conversation-name { font-weight: 600; }
.conversation-preview { color: #555; font-size: 0.9rem; }
.conversation-time { font-size: 0.8rem; color: #999; }
.conversation-muted { opacity: 0.6; }

/* Single conversation */
.conversation-messages { display: flex; flex-direction: column; gap: 0.75rem; padding: 1rem; }
.message-bubble { max-width: 70%; padding: 0.75rem 1rem; border-radius: 1rem; }
.message-bubble.message-mine { align-self: flex-end; background: var(--color-primary); color: #fff; }
.message-bubble.message-theirs { align-self: flex-start; background: #f0f0f0; }
.message-sender { font-size: 0.8rem; font-weight: 600; margin-bottom: 0.25rem; }
.message-time { font-size: 0.75rem; opacity: 0.7; margin-top: 0.25rem; }
.conversation-compose { display: flex; gap: 0.5rem; padding: 1rem; border-top: 1px solid #eee; }
.conversation-compose textarea { flex: 1; resize: none; min-height: 44px; max-height: 120px; }
```

---

## Open Questions

1. **Rich text in user messages?** The spec assumes plain text for user-to-user messages (simpler, safer). Admin broadcasts already support HTML. Should user messages support basic formatting (bold, italic, links)?

2. **File/image attachments?** Not in this spec. Could be added later using the existing `EntityPhoto` system to attach files to messages.

3. **Real-time updates?** This spec uses standard request/response. Real-time message delivery (WebSocket, SSE, or polling) is a post-MVP enhancement that would add "new message" DOM updates without page reload.

4. **Group conversations?** The data model supports it (multiple participants per conversation), but the UI spec here is designed for 1:1. Group UI would need a separate compose flow (select multiple recipients) and different display rules (show sender name on every message).

5. **Message editing/deletion?** Not in this spec. Users cannot edit or delete sent messages. This could be added later with a `msg_edit_time` field and a short editing window.

6. **Emoji reactions on messages?** Not in this spec. Could use the existing reaction system with `entity_type = 'message'` in a future enhancement.
