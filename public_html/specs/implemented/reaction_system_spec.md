# Reaction System Spec

**Purpose:** A generic, polymorphic reaction system that works with any entity type in the platform -- users, events, blog posts, products, etc. Supports likes, favorites, bookmarks, passes, and any other reaction type. Core platform feature, not dating-specific.

**Last Updated:** 2026-03-22

**Status:** DONE (2026-03-22)

**Documentation:** [Social Features](../docs/social_features.md)

---

## Design

### Principle

This is a **generic** system. Any entity that has a primary key can be reacted to. The dating plugin (and others) add semantics on top -- e.g., mutual user likes = match, pass = don't show again.

### Pattern

Follows the same polymorphic `entity_type` + `entity_id` pattern used by:
- `EntityPhoto` (`eph_entity_photos`) -- photos attached to any entity
- `ChangeTracking` (`cht_change_tracking`) -- audit log for any entity
- `Notification` (`ntf_notifications`) -- notifications with typed context

---

## Data Model

### `reactions` (table: `rct_reactions`)

| Column | Type | Notes |
|--------|------|-------|
| `rct_reaction_id` | int8, serial, PK | |
| `rct_usr_user_id` | int4, required, FK | User reacting |
| `rct_entity_type` | varchar(50), required | 'user', 'event', 'post', 'product', 'location', etc. |
| `rct_entity_id` | int4, required | ID of target entity |
| `rct_reaction_type` | varchar(20), default 'like' | 'like', 'favorite', 'pass', 'bookmark' -- extensible by plugins |
| `rct_create_time` | timestamp(6), default now() | When the reaction was created |
| `rct_delete_time` | timestamp(6) | Soft delete |

**Constraints (enforced via `field_specifications`):**
- Unique on (`rct_usr_user_id`, `rct_entity_type`, `rct_entity_id`) via `unique_with` -- one reaction per user per entity
- Foreign key on `rct_usr_user_id` -> `usr_users` with permanent_delete action
- No additional database indexes -- add later only if query performance requires it

### Multi Class Option Keys

| Option Key | Column | Description |
|------------|--------|-------------|
| `user_id` | `rct_usr_user_id` | Filter by who reacted |
| `entity_type` | `rct_entity_type` | Filter by what type of thing was reacted to |
| `entity_id` | `rct_entity_id` | Filter by specific entity |
| `reaction_type` | `rct_reaction_type` | Filter by reaction type (like, favorite, pass) |
| `deleted` | `rct_delete_time` | true/false for soft-delete filtering |

---

## Static Helper Methods

Following the patterns in `Notification::create_notification()` and `ChangeTracking::logChange()`:

### `Reaction::toggle($user_id, $entity_type, $entity_id, $reaction_type = 'like')`

Toggle a reaction on/off. If the user already has an active reaction on the entity, soft-delete it. If not, create one (or undelete an existing soft-deleted row).

Returns: `['action' => 'reacted'|'unreacted', 'reaction' => $reaction_obj]`

### `Reaction::has_reacted($user_id, $entity_type, $entity_id)`

Check if a user has an active reaction on an entity. Lightweight -- uses direct SQL, no model instantiation.

Returns: `bool`

### `Reaction::get_count($entity_type, $entity_id)`

Get total active reaction count for an entity. Direct SQL for performance.

Returns: `int`

### `Reaction::get_user_reactions($user_id, $entity_type = null, $reaction_type = 'like')`

Get all entities a user has reacted to, optionally filtered by type. Returns loaded MultiReaction.

---

## AJAX Endpoint

**File:** `ajax/reaction_ajax.php`

**Actions:**

| Action | Method | Params | Response |
|--------|--------|--------|----------|
| `toggle` | POST | `entity_type`, `entity_id`, `reaction_type` (optional) | `{success: true, action: 'reacted'|'unreacted', count: N}` |
| `status` | GET | `entity_type`, `entity_id` | `{reacted: bool, count: N}` |
| `count` | GET | `entity_type`, `entity_id` | `{count: N}` |

**Authentication:** User must be logged in. Uses session user_id (never accept user_id from client).

---

## UI Component

A reusable reaction button that can be dropped into any view:

```php
// Usage in any view
Reaction::render_button($entity_type, $entity_id, $options);

// Options:
// 'show_count' => true/false (default true)
// 'reaction_type' => 'like'|'favorite'|'bookmark' (default 'like')
// 'icon_active' => CSS class for active state icon
// 'icon_inactive' => CSS class for inactive state icon
// 'css_class' => additional CSS classes
```

The button handles its own AJAX call and updates state without page reload.

---

## Integration Points

### How Plugins Extend This

The `rct_reaction_type` field and `rct_entity_type` field make this extensible without core changes:

**Dating plugin:**
- Uses `entity_type='user'`, `reaction_type='like'` and `reaction_type='pass'`
- Hooks into `toggle` to trigger match detection when `entity_type='user'` and `reaction_type='like'`
- Can add `reaction_type='super_like'` for premium features

**Events plugin:**
- Uses `entity_type='event'`, `reaction_type='favorite'`
- Shows "interested" count on event pages

**Any future entity:**
- Just pass the entity type string and ID -- no schema changes needed

### Block System Integration

When the block system (spec 1.5) is implemented, reaction operations should check blocks:
- Cannot react to a user who has blocked you
- Cannot react to a user you have blocked
- Block check is only relevant for `entity_type='user'`

### Notification Integration

Reactions can optionally trigger notifications. This is **not** built into the core reaction system -- plugins or calling code decide whether to notify. For example:
- Dating plugin: notifies on user likes (blurred for free tier)
- Event system: no notification on event favorites
- Blog: notifies post author on likes

---

## Implementation

### Files Created

- `data/reactions_class.php` -- `Reaction` + `MultiReaction` classes
- `ajax/reaction_ajax.php` -- AJAX endpoint for toggle/status/count

No other files were modified. Views that want reaction buttons add them individually.

### Input Validation

The AJAX endpoint validates:
- `entity_type` -- must match `/^[a-z][a-z0-9_]{0,49}$/`
- `reaction_type` -- must match `/^[a-z][a-z0-9_]{0,19}$/`
- `toggle` action requires POST method
- User ID always comes from session, never from client

### Not Implemented (by design, handled by plugins/other specs)

- Match detection (dating plugin)
- Rate limiting reactions per day (dating plugin, uses subscription tier checks)
- "Who liked you" views (dating plugin)
- Reaction analytics/trending (post-MVP)
