# Notification Center Spec

**Purpose:** Add an in-app notification system to the core platform. Every interactive platform needs this — it's not specific to any single use case (dating, membership, marketplace, etc.).

**Last Updated:** 2026-03-20

**Origin:** Extracted from [Dating Platform Spec](dating_platform_spec.md) §1.2 as a standalone core feature.

---

## Problem

The platform sends emails but has no in-app notification system. Users have no way to see activity (new messages, likes, system alerts, event updates) without checking email.

---

## Data Models

### Notifications

**New Model: `notifications`**
- `ntf_notification_id` (serial, primary key)
- `ntf_usr_user_id` (int4, FK) - Recipient
- `ntf_type` (varchar 50) - Category: 'message', 'like', 'match', 'system', 'event', etc.
- `ntf_title` (varchar 255)
- `ntf_body` (text)
- `ntf_link` (varchar 255) - URL to navigate to on click
- `ntf_is_read` (bool, default false)
- `ntf_read_time` (timestamp)
- `ntf_source_usr_user_id` (int4, nullable) - User who triggered it
- `ntf_create_time` / `ntf_delete_time` (timestamps)

### Notification Preferences

**New Model: `notification_preferences`**
- `ntp_notification_preference_id` (serial, primary key)
- `ntp_usr_user_id` (int4, FK)
- `ntp_type` (varchar 50) - Matches notification type
- `ntp_email_enabled` (bool, default true)
- `ntp_in_app_enabled` (bool, default true)

---

## What Already Exists

### Data Placeholder in `get_menu_data()` (Ready — just needs real data)

**`PublicPageBase::get_menu_data()`** (`includes/PublicPageBase.php:311-318`) — already returns a `notifications` key with an `enabled => false` placeholder:
```php
$menu_data['notifications'] = [
    'enabled' => false,
    'count' => 0,
    'unread_count' => 0,
    'items' => []
];
```
The `get_menu_data()` spec (`specs/implemented/get_menu_data_spec.md`) documents this structure. Implementation replaces this with a session-cached unread count (no object loading on every page) and flips `enabled` to `true`. The `items` array and `count` fields are dropped — only `enabled`, `unread_count`, and `view_all_link` are needed.

### No Notification UI in Active Theme

The active theme (`default`) uses `PublicPage extends PublicPageBase`, which has **no notification rendering code** — no bell icon, no dropdown, no unread badge. Notification UI markup needs to be built in the active `PublicPage.php`.

**Note:** The legacy `PublicPageFalcon.php` and `PublicPageTailwind.php` files contain notification bell/dropdown code that could serve as reference, but these classes are not in the active code path.

### Admin Notification Emails (Unrelated — for reference only)

Three existing settings send email to admin addresses on specific events. These are NOT the user-facing notification system but are worth noting:
- `subscription_notification_emails` — admin email on new subscriptions
- `single_purchase_notification_emails` — admin email on purchases
- `comment_notification_emails` — admin email on new comments

These use `SystemMailer` directly and are independent of the notification center.

### No Existing Data Model

There are no `ntf_*` database tables, no `notifications_class.php`, and no notification-related AJAX endpoints. The data layer is entirely new work.

---

## Delivery

Notifications are always created in-app. Email delivery is controlled per-user via notification preferences (new setting per notification type).

---

## Architecture

### File Map

```
data/
  notifications_class.php              # Notification + MultiNotification (new)
  notification_preferences_class.php   # NotificationPreference + Multi (new, Phase 2)

views/
  notifications.php                    # Full notification list page (new)

logic/
  notifications_logic.php              # List/mark-read logic (new)

ajax/
  notifications_ajax.php               # Mark read, get unread count (new)
```

**Existing files to modify:**
- `includes/PublicPageBase.php` — `get_menu_data()`: replace placeholder with real query; add `render_notification_icon()` method
- `includes/PublicPage.php` — `top_right_menu()`: call `$this->render_notification_icon($menu_data)`
- `includes/PublicPageJoinerySystem.php` — `top_right_menu()`: call `$this->render_notification_icon($menu_data)` (admin interface)

---

### Data Model: `Notification` (SystemBase)

Follows the same pattern as `Message` in `data/messages_class.php`:

```php
class Notification extends SystemBase {
    public static $prefix = 'ntf';
    public static $tablename = 'ntf_notifications';
    public static $pkey_column = 'ntf_notification_id';

    protected static $foreign_key_actions = [
        'ntf_usr_user_id' => ['action' => 'permanent_delete'],           // Delete notifications when user is deleted
        'ntf_source_usr_user_id' => ['action' => 'set_null']             // Null out source if source user is deleted
    ];

    public static $field_specifications = [
        'ntf_notification_id'       => ['type' => 'int8', 'is_nullable' => false, 'serial' => true],
        'ntf_usr_user_id'           => ['type' => 'int4', 'required' => true],      // Recipient
        'ntf_type'                  => ['type' => 'varchar(50)', 'required' => true], // 'message', 'like', 'system', 'event', etc.
        'ntf_title'                 => ['type' => 'varchar(255)', 'required' => true],
        'ntf_body'                  => ['type' => 'text'],
        'ntf_link'                  => ['type' => 'varchar(255)'],                   // URL on click
        'ntf_is_read'               => ['type' => 'bool', 'default' => false],
        'ntf_read_time'             => ['type' => 'timestamp(6)'],
        'ntf_source_usr_user_id'    => ['type' => 'int4'],                           // Who triggered it (nullable)
        'ntf_create_time'           => ['type' => 'timestamp(6)'],
        'ntf_delete_time'           => ['type' => 'timestamp(6)'],
    ];
}
```

### Collection: `MultiNotification` (SystemMultiBase)

Follows the same pattern as `MultiMessage`. Option keys map to column filters:

```php
class MultiNotification extends SystemMultiBase {
    protected static $model_class = 'Notification';

    protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        // Required: always filter by recipient
        if (isset($this->options['user_id'])) {
            $filters['ntf_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
        }

        // Filter by read status
        if (isset($this->options['unread_only']) && $this->options['unread_only']) {
            $filters['ntf_is_read'] = "= false";
        }

        // Filter by type
        if (isset($this->options['type'])) {
            $filters['ntf_type'] = [$this->options['type'], PDO::PARAM_STR];
        }

        // Exclude soft-deleted
        if (isset($this->options['deleted'])) {
            $filters['ntf_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        }

        return $this->_get_resultsv2('ntf_notifications', $filters, $this->order_by, $only_count, $debug);
    }
}
```

**Option keys:**
| Option Key | Purpose | Example |
|------------|---------|---------|
| `user_id` | Recipient user ID | `['user_id' => 42]` |
| `unread_only` | Only unread notifications | `['unread_only' => true]` |
| `type` | Filter by notification type | `['type' => 'message']` |
| `deleted` | Include/exclude soft-deleted | `['deleted' => false]` |

---

### Populating `get_menu_data()` (PublicPageBase)

Replace the placeholder block at `PublicPageBase.php:311-318`. The header only needs the unread count — no object loading. The count is cached in the session to avoid a DB query on every page load.

**Session cache approach:** `create_notification()` and the AJAX `mark_read`/`mark_all_read` actions invalidate the cached count. On the next page load, `get_menu_data()` does a single `SELECT COUNT(*)` and re-caches it. All other page loads use the cached value with zero DB cost.

```php
// 4. Notifications
$menu_data['notifications'] = [
    'enabled' => false,
    'unread_count' => 0,
    'view_all_link' => '/notifications',
];

if ($is_logged_in) {
    try {
        $unread_count = $session->get('notification_unread_count');
        if ($unread_count === null) {
            // Cache miss — single COUNT query, no object loading
            require_once(PathHelper::getIncludePath('data/notifications_class.php'));
            $unread_count = Notification::get_unread_count($session->get_user_id());
            $session->set('notification_unread_count', $unread_count);
        }

        $menu_data['notifications'] = [
            'enabled' => true,
            'unread_count' => (int)$unread_count,
            'view_all_link' => '/notifications',
        ];
    } catch (Exception $e) {
        // Notification system not yet installed or query failed — keep disabled
    }
}
```

**`Notification::get_unread_count()`** — lightweight static method, no model instantiation:

```php
public static function get_unread_count($user_id) {
    $dbconnector = DbConnector::get_instance();
    $dblink = $dbconnector->get_db_link();
    $sql = "SELECT COUNT(*) FROM ntf_notifications
            WHERE ntf_usr_user_id = ? AND ntf_is_read = false AND ntf_delete_time IS NULL";
    $q = $dblink->prepare($sql);
    $q->execute([$user_id]);
    return (int)$q->fetchColumn();
}
```

**Cache invalidation:** `create_notification()` and the AJAX mark-read actions clear the cached count:

```php
// In create_notification(), after save:
$session = SessionControl::get_instance();
if ($session->get_user_id() == $recipient_user_id) {
    // Recipient is the current user (unlikely but possible for system notifications)
    $session->set('notification_unread_count', null);
}

// In notifications_ajax.php, after mark_read / mark_all_read:
$session->set('notification_unread_count', null);
```

**Note:** When another user triggers a notification for someone else (the common case), the recipient's session cache is stale until their next page load after cache expiry or their next explicit count refresh via AJAX. This is acceptable — the bell updates on next navigation, not in real-time. Real-time push is a post-MVP enhancement.

The `try/catch` is important: if the `ntf_notifications` table doesn't exist yet (e.g., during rollout), the header silently degrades to no bell icon rather than crashing.

---

### Header UI: `render_notification_icon()` on PublicPageBase

`PublicPageBase` already has `render_` methods (`render_admin_bar()`, `render_admin_bar_css()`). Add `render_notification_icon()` to the base class so all themes get notification support automatically. Themes that need different markup can override it.

```php
// In PublicPageBase
public function render_notification_icon($menu_data = null) {
    if ($menu_data === null) {
        $menu_data = $this->get_menu_data();
    }
    $notifications = $menu_data['notifications'];
    if (!$notifications['enabled']) {
        return;
    }
    $unread = (int)$notifications['unread_count'];
    echo '<a href="' . htmlspecialchars($notifications['view_all_link'], ENT_QUOTES, 'UTF-8') . '" class="header-notifications-link" title="Notifications">';
    echo '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>';
    if ($unread > 0) {
        echo '<span class="notifications-count">' . $unread . '</span>';
    }
    echo '</a>';
}
```

Themes call it from their `top_right_menu()`, passing the `$menu_data` they already have:

```php
// In any theme's top_right_menu()
$menu_data = $this->get_menu_data();
// ... cart icon ...
$this->render_notification_icon($menu_data);
// ... admin link, user menu ...
```

For Phase 1, clicking the bell goes to the full `/notifications` page. A dropdown could be added later.

---

### AJAX Endpoint: `notifications_ajax.php`

Follows the pattern from `theme_switch_ajax.php` — JSON response, session validation, try/catch:

```php
header('Content-Type: application/json');

// Session required
$session = SessionControl::get_instance();
if (!$session->get_user_id()) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'mark_read':
        // Mark a single notification as read
        $ntf_id = (int)($_POST['notification_id'] ?? 0);
        $ntf = new Notification($ntf_id, TRUE);
        // Verify ownership
        if ($ntf->get('ntf_usr_user_id') != $session->get_user_id()) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit;
        }
        $ntf->set('ntf_is_read', true);
        $ntf->set('ntf_read_time', gmdate('Y-m-d H:i:s'));
        $ntf->save();
        echo json_encode(['success' => true]);
        break;

    case 'mark_all_read':
        // Bulk update via DbConnector (no model method for bulk updates)
        $dbconnector = DbConnector::get_instance();
        $dblink = $dbconnector->get_db_link();
        $sql = "UPDATE ntf_notifications SET ntf_is_read = true, ntf_read_time = NOW()
                WHERE ntf_usr_user_id = ? AND ntf_is_read = false";
        $q = $dblink->prepare($sql);
        $q->execute([$session->get_user_id()]);
        echo json_encode(['success' => true, 'updated' => $q->rowCount()]);
        break;

    case 'get_count':
        // Return unread count (for polling or page refresh)
        $unread = new MultiNotification(
            ['user_id' => $session->get_user_id(), 'unread_only' => true, 'deleted' => false]
        );
        echo json_encode(['success' => true, 'unread_count' => $unread->count_all()]);
        break;
}
```

---

### Logic / View Layer: `notifications_logic.php`

Follows the pattern from `items_logic.php` — Multi query, Pager, LogicResult:

```php
function notifications_logic($get_vars, $post_vars) {
    $page_vars = [];
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('includes/Pager.php'));
    require_once(PathHelper::getIncludePath('data/notifications_class.php'));

    $session = SessionControl::get_instance();
    if (!$session->is_logged_in()) {
        return LogicResult::redirect('/login');
    }

    $numperpage = 20;
    $criteria = ['user_id' => $session->get_user_id(), 'deleted' => false];
    $notifications = new MultiNotification($criteria, ['ntf_create_time' => 'DESC'], $numperpage, $offset);
    $numrecords = $notifications->count_all();
    $notifications->load();

    $page_vars['notifications'] = $notifications;
    $page_vars['title'] = 'Notifications';
    $page_vars['pager'] = new Pager(['numrecords' => $numrecords, 'numperpage' => $numperpage]);

    return LogicResult::render($page_vars);
}
```

The view (`notifications.php`) iterates the collection and renders each notification as a list item with title, body, time, and a link.

---

### Creating Notifications (API for Other Features)

Any feature that needs to send a notification does so by creating a `Notification` object. A static helper method keeps this clean:

```php
// Static factory method on Notification class
public static function create_notification($recipient_user_id, $type, $title, $body, $link = null, $source_user_id = null) {
    $ntf = new Notification(NULL);
    $ntf->set('ntf_usr_user_id', $recipient_user_id);
    $ntf->set('ntf_type', $type);
    $ntf->set('ntf_title', $title);
    $ntf->set('ntf_body', $body);
    $ntf->set('ntf_link', $link);
    $ntf->set('ntf_source_usr_user_id', $source_user_id);
    $ntf->save();
    return $ntf;
}
```

**Callers:**
```php
// From messaging code
Notification::create_notification(
    $recipient_id, 'message', 'New message from ' . $sender_name,
    substr($message_body, 0, 100),
    '/conversations/' . $conversation_id,
    $sender_id
);

// From a plugin (e.g., dating match)
Notification::create_notification(
    $user_id, 'match', 'New match!',
    'You matched with ' . $other_user_name,
    '/matches',
    $other_user_id
);

// System notification (no source user)
Notification::create_notification(
    $user_id, 'system', 'Welcome!',
    'Complete your profile to get started.',
    '/profile'
);
```

### Suggested Notification Points

These are the places in the codebase where user-facing events happen that should trigger notifications. Organized by `ntf_type` value.

#### Type: `message`

| Event | Where | Recipient | Suggested Title |
|-------|-------|-----------|-----------------|
| Admin sends message to user | `adm/logic/admin_users_message_logic.php:299-307` | Message recipient | "New message from [Admin Name]" |
| Admin sends message to event registrants | `adm/logic/admin_users_message_logic.php:119-166` | Each registrant | "New message about [Event Name]" |
| Admin sends message to group members | `adm/logic/admin_users_message_logic.php:237-245` | Each group member | "New message in [Group Name]" |

#### Type: `event`

| Event | Where | Recipient | Suggested Title |
|-------|-------|-----------|-----------------|
| User registers for event (via purchase) | `logic/cart_charge_logic.php:458-468` | Registering user | "You're registered for [Event Name]" |
| User added to waiting list | `logic/event_waiting_list_logic.php:94-103` | User | "You're on the waiting list for [Event Name]" |
| User withdrawn from event | `logic/event_withdraw_logic.php:40-42` | User | "You've withdrawn from [Event Name]" |
| Admin removes user from event | `adm/logic/admin_event_logic.php:60-67` | Removed user | "You've been removed from [Event Name]" |
| Event cancelled by admin | `adm/logic/admin_event_logic.php:32-43` | All registrants | "[Event Name] has been cancelled" |
| Recurring event instance cancelled | `adm/logic/admin_event_logic.php:46-52` | Instance registrants | "[Event Name] on [Date] has been cancelled" |

#### Type: `order`

| Event | Where | Recipient | Suggested Title |
|-------|-------|-----------|-----------------|
| Purchase confirmed | `logic/cart_charge_logic.php:367-368` (subscription) / `:415-416` (one-time) | Purchasing user | "Your purchase is confirmed: [Product Name]" |
| Event receipt email sent | `logic/cart_charge_logic.php:464-468` | Purchasing user | "Receipt for [Event Name]" |
| Payment failed | `logic/cart_charge_logic.php:253-256` | Purchasing user | "Payment failed — please try again" |

#### Type: `subscription`

| Event | Where | Recipient | Suggested Title |
|-------|-------|-----------|-----------------|
| Subscription activated | `logic/cart_charge_logic.php:357-411` | Subscribing user | "Your [Product Name] subscription is active" |
| Tier assigned after purchase | `data/subscription_tiers_class.php:216-240` `handleProductPurchase()` | User | "You now have [Tier Name] access" |
| Subscription expired / tier removed | `data/subscription_tiers_class.php:183-201` `handleSubscriptionExpired()` | User | "Your [Tier Name] subscription has expired" |

#### Type: `comment`

| Event | Where | Recipient | Suggested Title |
|-------|-------|-----------|-----------------|
| New comment on post | `logic/post_logic.php:46-77` | Post author | "New comment on '[Post Title]' by [Author]" |
| Comment approved by admin | `adm/logic/admin_comment_edit_logic.php:25` | Comment author | "Your comment on '[Post Title]' was approved" |

#### Type: `group`

| Event | Where | Recipient | Suggested Title |
|-------|-------|-----------|-----------------|
| User added to group by admin | `adm/logic/admin_group_members_logic.php:17` | Added user | "You've been added to [Group Name]" |
| User removed from group by admin | `adm/logic/admin_group_members_logic.php:22` | Removed user | "You've been removed from [Group Name]" |

#### Type: `account`

| Event | Where | Recipient | Suggested Title |
|-------|-------|-----------|-----------------|
| Welcome / account created | `data/users_class.php:340-346` `CreateNew()` | New user | "Welcome to [Site Name]!" |
| Email verified | `includes/Activation.php:26-47` `ActivateUser()` | Verified user | "Your email has been verified" |

#### Notes

- All notification points listed above currently send **emails only** (or nothing). Adding `Notification::create_notification()` alongside existing email sends is the integration pattern — in-app notifications supplement emails, they don't replace them.
- The admin-to-admin notification emails (`subscription_notification_emails`, `single_purchase_notification_emails`, `comment_notification_emails`) are internal admin alerts and should NOT generate in-app notifications.
- Event registration via direct registration (non-purchase) at `data/events_class.php:321` `add_registrant()` should also trigger a notification if not already covered by the purchase flow.

---

## Cleanup: `NotificationCleanup` Scheduled Task

Read notifications older than 30 days are permanently deleted by a scheduled task. Follows the existing `ScheduledTaskInterface` pattern.

**`tasks/NotificationCleanup.json`:**
```json
{
    "name": "Notification Cleanup",
    "description": "Permanently deletes read notifications older than 30 days",
    "default_frequency": "daily",
    "default_time": "03:00:00",
    "config_fields": {
        "retention_days": {"type": "number", "label": "Days to keep read notifications", "required": true}
    }
}
```

**`tasks/NotificationCleanup.php`:**
```php
class NotificationCleanup implements ScheduledTaskInterface {
    public function run(array $config) {
        $retention_days = isset($config['retention_days']) ? (int)$config['retention_days'] : 30;

        $dbconnector = DbConnector::get_instance();
        $dblink = $dbconnector->get_db_link();
        $sql = "DELETE FROM ntf_notifications
                WHERE ntf_is_read = true
                AND ntf_read_time < NOW() - INTERVAL '{$retention_days} days'";
        $q = $dblink->prepare($sql);
        $q->execute();
        $deleted = $q->rowCount();

        return ['status' => 'success', 'message' => "Deleted $deleted read notifications older than $retention_days days"];
    }
}
```

Activate via **Admin > System > Scheduled Tasks** after deployment.

---

## MVP Scope

**Phase 1:**
- Notification data model, Multi class, AJAX endpoint
- Bell icon with unread count in header (public + admin)
- Full notification list page at `/notifications`
- Session-cached unread count (no DB hit per page load)
- `create_notification()` wired into the highest-value points:
  - New messages (admin → user)
  - Event registration confirmed
  - Purchase/subscription confirmed
  - Subscription expired
- `NotificationCleanup` scheduled task
- All notification types enabled (no per-type preferences yet)

**Phase 2:**
- Wire remaining notification points (comments, group changes, waiting list, etc.)
- Notification preferences per type (email + in-app toggles)
- Batch/digest email delivery
- Notification grouping (e.g., "3 people liked your profile")
