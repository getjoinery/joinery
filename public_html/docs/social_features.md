# Social Features

Core platform features for user-to-user interaction: reactions (likes, favorites, bookmarks), blocking, and reporting. These are generic systems used by any interactive site -- the dating plugin and others add domain-specific behavior on top.

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
