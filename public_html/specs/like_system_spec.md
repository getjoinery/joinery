# Like / Favorite System Spec

**Purpose:** A generic, polymorphic like/favorite system that works with any entity type in the platform -- users, events, blog posts, products, etc. Core platform feature, not dating-specific.

**Last Updated:** 2026-03-22

**Status:** Not started

**Documentation:** [Social Features](../docs/social_features.md)

---

## Design

### Principle

This is a **generic** system. Any entity that has a primary key can be liked. The dating plugin (and others) add semantics on top -- e.g., mutual user likes = match, pass = don't show again.

### Pattern

Follows the same polymorphic `entity_type` + `entity_id` pattern used by:
- `EntityPhoto` (`eph_entity_photos`) -- photos attached to any entity
- `ChangeTracking` (`cht_change_tracking`) -- audit log for any entity
- `Notification` (`ntf_notifications`) -- notifications with typed context

---

## Data Model

### `user_likes` (new table: `ulk_user_likes`)

| Column | Type | Notes |
|--------|------|-------|
| `ulk_user_like_id` | int8, serial, PK | |
| `ulk_usr_user_id` | int4, required, FK | User doing the liking |
| `ulk_entity_type` | varchar(50), required | 'user', 'event', 'post', 'product', 'location', etc. |
| `ulk_entity_id` | int4, required | ID of liked entity |
| `ulk_like_type` | varchar(20), default 'like' | 'like', 'favorite', 'pass' -- extensible by plugins |
| `ulk_create_time` | timestamp(6), default now() | When the like was created |
| `ulk_delete_time` | timestamp(6) | Soft delete |

**Constraints (enforced via `field_specifications`):**
- Unique on (`ulk_usr_user_id`, `ulk_entity_type`, `ulk_entity_id`) via `unique_with` -- one like per user per entity
- Foreign key on `ulk_usr_user_id` -> `usr_users` with permanent_delete action
- No additional database indexes -- add later only if query performance requires it

### Multi Class Option Keys

| Option Key | Column | Description |
|------------|--------|-------------|
| `user_id` | `ulk_usr_user_id` | Filter by who liked |
| `entity_type` | `ulk_entity_type` | Filter by what type of thing was liked |
| `entity_id` | `ulk_entity_id` | Filter by specific entity |
| `like_type` | `ulk_like_type` | Filter by like type (like, favorite, pass) |
| `deleted` | `ulk_delete_time` | true/false for soft-delete filtering |

---

## Static Helper Methods

Following the patterns in `Notification::create_notification()` and `ChangeTracking::logChange()`:

### `UserLike::toggle_like($user_id, $entity_type, $entity_id, $like_type = 'like')`

Toggle a like on/off. If the user already has an active like of that type on the entity, soft-delete it. If not, create one (or undelete an existing soft-deleted row).

Returns: `['action' => 'liked'|'unliked', 'like' => $like_obj]`

### `UserLike::has_liked($user_id, $entity_type, $entity_id)`

Check if a user has an active like on an entity. Lightweight -- uses direct SQL, no model instantiation.

Returns: `bool`

### `UserLike::get_like_count($entity_type, $entity_id)`

Get total active like count for an entity. Direct SQL for performance.

Returns: `int`

### `UserLike::get_user_likes($user_id, $entity_type = null, $like_type = 'like')`

Get all entities a user has liked, optionally filtered by type. Returns loaded MultiUserLike.

---

## AJAX Endpoint

**File:** `ajax/like_ajax.php`

**Actions:**

| Action | Method | Params | Response |
|--------|--------|--------|----------|
| `toggle` | POST | `entity_type`, `entity_id`, `like_type` (optional) | `{success: true, action: 'liked'|'unliked', count: N}` |
| `status` | GET | `entity_type`, `entity_id` | `{liked: bool, count: N}` |
| `count` | GET | `entity_type`, `entity_id` | `{count: N}` |

**Authentication:** User must be logged in. Uses session user_id (never accept user_id from client).

---

## UI Component

A reusable like button that can be dropped into any view:

```php
// Usage in any view
UserLike::render_like_button($entity_type, $entity_id, $options);

// Options:
// 'show_count' => true/false (default true)
// 'like_type' => 'like'|'favorite' (default 'like')
// 'icon_liked' => CSS class for liked state icon
// 'icon_unliked' => CSS class for unliked state icon
// 'css_class' => additional CSS classes
```

The button handles its own AJAX call and updates state without page reload.

---

## Integration Points

### How Plugins Extend This

The `ulk_like_type` field and `ulk_entity_type` field make this extensible without core changes:

**Dating plugin:**
- Uses `entity_type='user'`, `like_type='like'` and `like_type='pass'`
- Hooks into `toggle_like` to trigger match detection when `entity_type='user'` and `like_type='like'`
- Can add `like_type='super_like'` for premium features

**Events plugin:**
- Uses `entity_type='event'`, `like_type='favorite'`
- Shows "interested" count on event pages

**Any future entity:**
- Just pass the entity type string and ID -- no schema changes needed

### Block System Integration

When the block system (spec 1.5) is implemented, like operations should check blocks:
- Cannot like a user who has blocked you
- Cannot like a user you have blocked
- Block check is only relevant for `entity_type='user'`

### Notification Integration

Likes can optionally trigger notifications. This is **not** built into the core like system -- plugins or calling code decide whether to notify. For example:
- Dating plugin: notifies on user likes (blurred for free tier)
- Event system: no notification on event favorites
- Blog: notifies post author on likes

---

## Implementation Plan

### Files to Create

```
data/user_likes_class.php          # UserLike + MultiUserLike classes
ajax/like_ajax.php                 # AJAX endpoint for toggle/status/count
```

### Files to Modify

None required for core functionality. Views that want like buttons add them individually.

---

## Scope Notes

**In scope:**
- Generic like/unlike/pass on any entity
- Like counts per entity
- Toggle behavior (like/unlike)
- AJAX endpoint
- Reusable UI component (static render method)

**Out of scope (handled by plugins/other specs):**
- Match detection (dating plugin)
- Rate limiting likes per day (dating plugin, uses subscription tier checks)
- "Who liked you" views (dating plugin)
- Like analytics/trending (post-MVP)
