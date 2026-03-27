# Profile Dashboard Redesign

**Purpose:** Replace the current placeholder-style `/profile` page with a focused dashboard that surfaces actionable items and gives users a quick pulse on their activity across the platform.

**Last Updated:** 2026-03-27

---

## Problem

The current `/profile` page is a flat list of events, subscriptions, and orders with no hierarchy or prioritization. It doesn't surface the notification or messaging systems (both recently built), buries important action items, and shows historical data (expired events, old orders) alongside current items. Users have no reason to visit this page regularly because it doesn't answer the question "what needs my attention?"

---

## Theme Decision: joinery-system for All Member Pages

The entire `/profile/*` member area uses the **joinery-system** theme, regardless of the active public theme. This mirrors how the admin section works — `AdminPage.php` hardcodes `PublicPageJoinerySystem` so that admin pages look consistent across all site deployments.

**Rationale:**
- **One design to maintain** — dashboard widgets, sub-pages, and cards only need to work with one CSS framework (joinery-system's vanilla CSS)
- **Proven pattern** — admin already does this successfully
- **joinery-system has everything needed** — cards, badges, alerts, a responsive grid, stats grid, avatars, 40+ inline SVG icons, and CSS custom properties for theming. It's Falcon-inspired but weighs only ~35KB CSS + ~4KB JS with zero vendor dependencies.
- **Falcon is deprecated** — building against Falcon would mean building against a theme being phased out
- **No cross-theme testing** — views don't need to handle Bootstrap vs Tailwind vs vanilla differences

### Implementation: MemberPage Class

A new `MemberPage` class (analogous to `AdminPage`) provides the page wrapper for all `/profile/*` pages.

**File:** `includes/MemberPage.php`

```php
<?php
require_once(PathHelper::getIncludePath('includes/PublicPageJoinerySystem.php'));

// Member area always uses joinery-system theme, regardless of the active public theme
if (!class_exists('PublicPage')) {
    class PublicPage extends PublicPageJoinerySystem {}
}

class MemberPage extends PublicPage {

    protected $header_options = array();

    public function getFormWriter($form_id = 'form1', $form_options = []) {
        require_once(PathHelper::getIncludePath('includes/FormWriterV2HTML5.php'));
        return new FormWriterV2HTML5($form_id, $form_options);
    }

    public function member_header($options = array()) {
        $session = SessionControl::get_instance();
        $_GLOBALS['page_header_loaded'] = true;

        $options['hide_horizontal_menu'] = false;
        $options['hide_vertical_menu'] = true;

        $this->header_options = $options;
        $this->public_header($options);

        // Use no-card layout — dashboard manages its own card structure
        echo PublicPageJoinerySystem::BeginPageNoCard($options);

        return true;
    }

    public function member_footer($options = array()) {
        $session = SessionControl::get_instance();
        $session->clear_clearable_messages();

        echo PublicPageJoinerySystem::EndPageNoCard();
        $this->public_footer($options);
    }
}
```

**Key differences from AdminPage:**
- **No admin sidebar** — member pages use the public site's horizontal nav (or a simple member nav), not the admin sidebar menu
- **No admin menu loading** — no `MultiAdminMenu` dependency
- **No-card wrapper by default** — the dashboard and sub-pages manage their own card layouts rather than being wrapped in a single page card
- **Same FormWriter** — uses `FormWriterV2HTML5` like AdminPage

### Migration of Existing Profile Pages (Phase 2)

In Phase 1, only the dashboard (`profile.php`) and new sub-pages (`events.php`, `orders.php`) use MemberPage. All other existing profile views continue using the public theme unchanged.

In Phase 2 (separate spec), all remaining `views/profile/*.php` files will be migrated from:
```php
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
$page = new PublicPage();
$page->public_header($hoptions, NULL);
```
To:
```php
require_once(PathHelper::getIncludePath('includes/MemberPage.php'));
$page = new MemberPage();
$page->member_header($hoptions);
```

---

## Design Principles

1. **Action items first** — anything requiring user attention goes at the top
2. **Show summaries, link to detail** — the dashboard shows 3-5 items per section with "View all" links; full lists live on dedicated sub-pages
3. **Lightweight data loading** — use count queries and small LIMIT values; don't load everything
4. **Settings-aware** — sections only appear when their feature is enabled (events_active, products_active, subscriptions_active, messaging_active)
5. **Progressive** — empty sections show a friendly empty state, not a broken layout

---

## Page Layout

The dashboard uses a two-column layout: main content area (left, wider) and sidebar (right, narrower). On mobile, the sidebar stacks below the main content.

```
┌─────────────────────────────────────────────────────────┐
│  Page Title: "Dashboard"                                │
│  Breadcrumb: Home > Dashboard                           │
├─────────────────────────────────────────────────────────┤
│  [Action Items Banner - only if items exist]            │
│  Pending surveys | Unread messages | Unread notifs      │
├───────────────────────────────────┬─────────────────────┤
│                                   │                     │
│  Quick Stats Row                  │  User Card          │
│  ┌──────┐ ┌──────┐ ┌──────┐      │  (avatar, name,     │
│  │Events│ │ Msgs │ │Notifs│      │   email, edit btn)  │
│  └──────┘ └──────┘ └──────┘      │                     │
│                                   │  Quick Links        │
│  Upcoming Events                  │  - Messages         │
│  (next 3, active only)           │  - Notifications    │
│  [View all events →]              │  - Account Settings │
│                                   │  - Orders           │
│  Recent Notifications             │  - Subscriptions    │
│  (last 5)                        │                     │
│  [View all notifications →]       │  Subscription       │
│                                   │  Summary            │
│  Recent Messages                  │  (if active)        │
│  (last 3 conversations)          │                     │
│  [View all messages →]            │  Mailing Lists      │
│                                   │                     │
│  Recent Orders                    │                     │
│  (last 3)                        │                     │
│  [View all orders →]              │                     │
│                                   │                     │
├───────────────────────────────────┴─────────────────────┤
│  Footer                                                 │
└─────────────────────────────────────────────────────────┘
```

---

## Section Details

### 1. Action Items Banner

**Visibility:** Only renders if at least one action item exists. Omitted entirely otherwise.

**Displayed as:** A horizontal bar with compact items separated by vertical dividers. Uses an alert-style background (light blue/info) to draw the eye.

**Action items (in priority order):**

| Item | Condition | Display | Link |
|------|-----------|---------|------|
| Pending surveys | `count($pending_surveys) > 0` | "N survey(s) awaiting your feedback" | Link to first survey, or anchor to survey section if multiple |
| Unread messages | `$unread_messages > 0` | "N unread message(s)" | `/profile/conversations` |
| Unread notifications | `$unread_notifications > 0` | "N new notification(s)" | `/profile/notifications` |

Each item shows an icon, the count text, and is clickable as a link to the relevant page.

### 2. Quick Stats Row

**Displayed as:** 3-4 small cards in a horizontal flex row. Each card shows a number and a label. Cards are clickable and link to the relevant detail page.

| Stat | Value | Link | Condition |
|------|-------|------|-----------|
| Upcoming Events | Count of active, non-expired event registrations | `/profile/events` | Always shown |
| Unread Messages | Count from `Conversation::get_unread_count()` | `/profile/conversations` | `messaging_active` setting enabled |
| Notifications | Unread count from `Notification::get_unread_count()` | `/profile/notifications` | Always shown |
| Active Subscriptions | Count of non-canceled subscriptions | `/profile/subscriptions` | `products_active` AND `subscriptions_active` |

**Styling:** Each card has a subtle background, large number, small label text below. Use theme CSS variables for colors. Cards should have equal width and wrap on narrow screens.

### 3. Upcoming Events Card

**Header:** "Upcoming Events" with "View All" link on the right side of the header

**Content:** Show up to 3 events, filtered to only **Active** status (not expired, not canceled, not completed). Sorted by next session time ascending (soonest first) so users see what's coming up.

Each event row shows:
- Event name (linked to the event sessions page)
- Next session time or event time (timezone-aware)
- Status badge ("Active", "Expires [date]")

**Empty state:** "No upcoming events." with a link to the events listing page if `events_page_link` setting is available, or a simple text message otherwise.

**"View All" link:** Goes to `/profile/events` (new sub-page, see below).

### 4. Recent Notifications Card

**Header:** "Recent Notifications" with "View All" link

**Content:** Last 5 notifications for the user, loaded via MultiNotification with `user_id` filter, sorted by `ntf_create_time DESC`, limit 5.

Each notification row shows:
- Type icon (small, color-coded by type — see notification view for existing icon mapping)
- Title text (linked to `ntf_link` if set, otherwise plain)
- Relative time ("2h ago", "Yesterday")
- Unread indicator: left border accent color or subtle background highlight

**Empty state:** "No notifications yet."

**"View All" link:** `/profile/notifications`

### 5. Recent Messages Card

**Header:** "Recent Messages" with "View All" link

**Visibility:** Only shown when `messaging_active` setting is enabled.

**Content:** Last 3 conversations, loaded via MultiConversation with `participant_user_id` filter, limit 3. Uses the existing JOIN LATERAL query that provides latest message preview.

Each conversation row shows:
- Other participant's display name
- Latest message preview (truncated to ~80 characters)
- Relative time of latest message
- Unread indicator (highlight if latest_message_time > cnp_last_read_time)

**Empty state:** "No messages yet." with a note about how to start a conversation if applicable.

**"View All" link:** `/profile/conversations`

### 6. Recent Orders Card

**Header:** "Recent Orders" with "View All" link

**Visibility:** Only shown when `products_active` setting is enabled.

**Content:** Last 3 orders, loaded via MultiOrder with `user_id` filter, sorted by `ord_order_id DESC`, limit 3.

Each order row shows:
- "Order #[id]" with total cost
- Order date (timezone-aware)

**Empty state:** "No orders yet."

**"View All" link:** `/profile/orders` (new sub-page, see below).

### 7. Sidebar: User Card

**Keep existing design** with minor refinements:
- Avatar (or placeholder icon)
- Display name
- Email
- Address (if set)
- "Edit Account" button (full width, links to `/profile/account_edit`)

No structural changes needed. This section works well already.

### 8. Sidebar: Quick Links

**New section.** A simple vertical list of navigation links to profile sub-pages. Gives users a consistent way to navigate to any part of their account.

| Link | URL | Condition |
|------|-----|-----------|
| Messages | `/profile/conversations` | `messaging_active` |
| Notifications | `/profile/notifications` | Always |
| Account Settings | `/profile/account_edit` | Always |
| Orders | `/profile/orders` | `products_active` |
| Subscriptions | `/profile/subscriptions` | `products_active` AND `subscriptions_active` |
| Contact Preferences | `/profile/contact_preferences` | Always |

**Styling:** Simple list with small icons or bullet points. Active counts (unread messages, unread notifications) shown as small badges next to the link text.

### 9. Sidebar: Subscription Summary

**Visibility:** Only shown when `products_active` AND `subscriptions_active` are enabled AND user has at least one active (non-canceled) subscription.

**Content:** Compact summary — subscription name/price and status. Link to `/profile/subscriptions` for full management.

### 10. Sidebar: Mailing Lists

**Keep existing design.** Shows subscribed list names. No changes needed.

---

## New Sub-Pages Required

### `/profile/events` — Full Event History

**New file:** `views/profile/events.php` and `logic/events_profile_logic.php`

Shows all event registrations (active, expired, canceled, completed) with filtering and pagination. This is where the full event list moves from the current profile page.

**Content:**
- All user's event registrations
- Status filter tabs or dropdown (All, Active, Completed, Expired, Canceled)
- Pagination (10 per page)
- Each row: event name (linked), date/time, status badge, calendar links for active events

### `/profile/orders` — Full Order History

**New file:** `views/profile/orders.php` and `logic/orders_profile_logic.php`

Shows complete order history with pagination. Moves the full order list off the dashboard.

**Content:**
- All user's orders, sorted by date descending
- Pagination (10 per page)
- Each row: order number, total, date, item summary

---

## Logic Layer Changes

### `profile_logic.php` — Modifications

The logic file needs to shift from "load everything" to "load summaries." Changes:

**Add:**
- `require_once` for `data/notifications_class.php`
- `require_once` for `data/conversations_class.php` (if messaging_active)
- `$page_vars['unread_notifications']` — via `Notification::get_unread_count($user_id)` (lightweight COUNT query)
- `$page_vars['unread_messages']` — via `Conversation::get_unread_count($user_id)` (lightweight COUNT query, only if messaging_active)
- `$page_vars['recent_notifications']` — MultiNotification, user_id filter, limit 5, sorted by create_time DESC
- `$page_vars['recent_conversations']` — MultiConversation, participant_user_id filter, limit 3 (only if messaging_active)
- `$page_vars['active_event_count']` — count of active (non-expired, non-canceled, non-completed) registrations
- `$page_vars['active_subscription_count']` — count of non-canceled subscriptions (only if subscriptions_active)

**Modify:**
- Event registrations: filter to **Active only**, limit to 3, sort by next session ascending (soonest first)
- Orders: reduce limit from 5 to 3
- Remove phone number loading (not displayed on dashboard)
- Remove full address loading beyond what the sidebar user card needs (already loaded)
- Remove old messages loading (MultiMessage) — replaced by conversations

**Keep unchanged:**
- User loading and picture link
- Activation code handling
- Session setup and permission check
- Pending surveys logic
- Mailing list loading
- Subscription loading (but add count)
- Address loading (for sidebar)
- Display messages from session

### `events_profile_logic.php` — New

Standard logic file pattern:
- Requires login (permission 0)
- Loads all MultiEventRegistrant for user with deleted=false
- Supports status filtering via GET parameter
- Pagination: 10 per page
- Builds event_registrations array with full detail (same as current profile_logic event loop)

### `orders_profile_logic.php` — New

Standard logic file pattern:
- Requires login (permission 0)
- Loads MultiOrder for user, deleted=false
- Pagination: 10 per page
- Sorted by ord_order_id DESC

---

## View Changes

### `views/profile/profile.php` — Full Rewrite

Replace the current view with the dashboard layout described above. Uses `MemberPage` for the page wrapper.

Key implementation notes:

- Use joinery-system CSS classes — no Bootstrap, no Falcon, no vendor dependencies
- Use card components (`.card`, `.card-header`, `.card-body`) from joinery-system's style.css
- Action items banner uses `.alert.alert-info` with inline SVG icons from `PublicPageJoinerySystem::getIconSvg()`
- Quick stats use `.stats-grid` (CSS Grid, auto-fill responsive) or `.row` with `.col` cards
- Main content sections are cards with headers containing the section title and "View All" link
- Sidebar uses the same card pattern
- Responsive: use joinery-system's `.row` / `.col-md-8` / `.col-md-4` grid for the two-column layout
- Icons: use the 40+ inline SVGs available in `PublicPageJoinerySystem::getIconSvg()` — bell, envelope, calendar, shopping-cart, star, dashboard, etc.
- Colors: use CSS custom properties (`--primary`, `--success`, `--danger`, `--warning`, `--info`, `--muted`)

### `views/profile/events.php` — New

Full event history page with status filtering and pagination.

### `views/profile/orders.php` — New

Full order history page with pagination.

---

## Page Title Change

Change the page title from "My Profile" to "Dashboard". Update:
- The `<h1>` heading
- The breadcrumb ("Home > Dashboard")
- The `$hoptions['title']` value
- The page `<title>` via hoptions

The route remains `/profile` — no URL change needed.

---

## Relative Time Display

Several sections need relative time formatting ("2h ago", "Yesterday", "Mar 15"). Add a helper or reuse existing patterns:

**Rules:**
- Under 1 minute: "Just now"
- 1-59 minutes: "Nm ago"
- 1-23 hours: "Nh ago"
- Yesterday: "Yesterday"
- 2-6 days: "N days ago"
- Older: "M j" format (e.g., "Mar 15")
- Different year: "M j, Y" format

This logic already exists in `conversations.php` — extract into a reusable function in LibraryFunctions or inline in the view if preferred.

---

## Settings Dependencies

| Setting | Sections Affected |
|---------|-------------------|
| `messaging_active` | Messages stat card, Recent Messages card, Messages quick link |
| `products_active` | Orders stat card (if applicable), Recent Orders card, Orders quick link |
| `subscriptions_active` (+ products_active) | Subscriptions stat card, Subscription summary sidebar, Subscriptions quick link |
| `events_active` (if exists) | Events stat card, Upcoming Events card |

Sections gated by disabled settings are omitted entirely from the HTML — not hidden with CSS.

---

## Phasing

This work is split into two phases so the dashboard can ship independently of the full migration.

### Phase 1: Dashboard + MemberPage (this spec)

Create MemberPage, build the new dashboard, and create the new sub-pages. Only `profile.php` and the new files use MemberPage. All other existing profile views continue using the public theme unchanged.

**Files:**

| File | Action | Description |
|------|--------|-------------|
| `includes/MemberPage.php` | **New** | Member area page wrapper (like AdminPage, uses joinery-system) |
| `views/profile/profile.php` | **Rewrite** | New dashboard layout using MemberPage |
| `logic/profile_logic.php` | **Modify** | Shift to summary data loading, add notification/conversation counts |
| `views/profile/events.php` | **New** | Full event history sub-page using MemberPage |
| `logic/events_profile_logic.php` | **New** | Event history logic with filtering and pagination |
| `views/profile/orders.php` | **New** | Full order history sub-page using MemberPage |
| `logic/orders_profile_logic.php` | **New** | Order history logic with pagination |

**Implementation order:**
1. **Create `MemberPage.php`** — new page wrapper class in `includes/`
2. **Create new logic files** — `events_profile_logic.php` and `orders_profile_logic.php`
3. **Create new sub-page views** — `events.php` and `orders.php` under `views/profile/`, using MemberPage
4. **Modify `profile_logic.php`** — add notification/conversation data, adjust limits and filters
5. **Rewrite `views/profile/profile.php`** — new dashboard layout using MemberPage
6. **Test** — verify all dashboard sections render correctly with and without data, confirm settings gating works, check mobile responsiveness

### Phase 2: Migrate Remaining Profile Views (separate spec)

Switch all remaining `views/profile/*.php` files from the public theme to MemberPage. This is a mechanical migration — each file changes its require/instantiation but content and logic stay the same.

**Files to migrate:**
- `views/profile/account_edit.php`
- `views/profile/address_edit.php`
- `views/profile/billing.php`
- `views/profile/change-tier.php`
- `views/profile/contact_preferences.php`
- `views/profile/conversation.php`
- `views/profile/conversations.php`
- `views/profile/event_sessions.php`
- `views/profile/event_sessions_course.php`
- `views/profile/event_withdraw.php`
- `views/profile/orders_recurring_action.php`
- `views/profile/password_edit.php`
- `views/profile/phone_numbers_edit.php`
- `views/profile/subscriptions.php`
- `views/notifications.php` (if moved under /profile)

**Note:** Until Phase 2 is complete, navigating from the dashboard (joinery-system) to an existing sub-page (public theme) will involve a visual transition between themes. This is acceptable as an interim state — the dashboard is the most-visited page, and the sub-pages still function correctly.

---

## What This Spec Does NOT Cover

- **Phase 2 migration** — migrating existing profile views to MemberPage (separate spec)
- **Notification preferences on dashboard** — managed on existing contact_preferences page
- **Social feed widget** — the social_feed.md spec covers embedding Instagram/Facebook on public pages, which is a separate feature
- **Profile editing** — account_edit.php is unchanged
- **New notification types** — this spec consumes existing notifications, does not add new trigger points
- **Conversation compose from dashboard** — users go to /profile/conversations to start new conversations
