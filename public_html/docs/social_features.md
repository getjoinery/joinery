# Social Features

Core platform features for user-to-user interaction: likes/favorites, blocking, and reporting. These are generic systems used by any interactive site -- the dating plugin and others add domain-specific behavior on top.

---

## Like / Favorite System

A polymorphic like system that works with any entity type. Uses the same `entity_type` + `entity_id` pattern as EntityPhoto and ChangeTracking.

**Spec:** [Like System Spec](/specs/like_system_spec.md)

### Data Model

**Table:** `ulk_user_likes`

| Column | Type | Description |
|--------|------|-------------|
| `ulk_user_like_id` | int8, serial, PK | |
| `ulk_usr_user_id` | int4, FK | User who liked |
| `ulk_entity_type` | varchar(50) | 'user', 'event', 'post', 'product', etc. |
| `ulk_entity_id` | int4 | ID of liked entity |
| `ulk_like_type` | varchar(20) | 'like', 'favorite', 'pass', 'bookmark' (default 'like') |
| `ulk_create_time` | timestamp | |
| `ulk_delete_time` | timestamp | Soft delete (unlike) |

**Classes:** `UserLike` (single), `MultiUserLike` (collection) in `data/user_likes_class.php`

### Usage

**Check if a user liked something:**
```php
require_once(PathHelper::getIncludePath('data/user_likes_class.php'));

$is_liked = UserLike::has_liked($user_id, 'event', $event_id);
```

**Toggle a like (like if not liked, unlike if already liked):**
```php
$result = UserLike::toggle_like($user_id, 'event', $event_id);
// $result = ['action' => 'liked'|'unliked', 'like' => $like_obj]

// With a specific like type:
$result = UserLike::toggle_like($user_id, 'post', $post_id, 'bookmark');
```

**Get like count for an entity:**
```php
$count = UserLike::get_like_count('event', $event_id);
```

**Get all entities a user has liked:**
```php
// All likes
$likes = UserLike::get_user_likes($user_id);

// Only event favorites
$favorites = UserLike::get_user_likes($user_id, 'event', 'favorite');
```

**Query with MultiUserLike:**
```php
$likes = new MultiUserLike(
    ['entity_type' => 'event', 'entity_id' => $event_id, 'deleted' => false],
    ['ulk_create_time' => 'DESC']
);
$likes->load();
```

### Like Button (UI Component)

Drop a like button into any view:

```php
// Basic like button with count
UserLike::render_like_button('event', $event_id);

// Customized
UserLike::render_like_button('post', $post_id, [
    'like_type' => 'bookmark',
    'show_count' => false,
    'icon_liked' => 'fas fa-bookmark',
    'icon_unliked' => 'far fa-bookmark',
    'css_class' => 'btn-sm'
]);
```

The button handles AJAX toggling and state updates automatically. User must be logged in.

### AJAX Endpoint

**File:** `ajax/like_ajax.php`

| Action | Method | Params | Response |
|--------|--------|--------|----------|
| `toggle` | POST | `entity_type`, `entity_id`, `like_type` (opt) | `{success, action, count}` |
| `status` | GET | `entity_type`, `entity_id` | `{liked, count}` |
| `count` | GET | `entity_type`, `entity_id` | `{count}` |

### Entity Types

Any entity with a primary key can be liked. Common types:

| `entity_type` | Entity | Typical `like_type` |
|---------------|--------|---------------------|
| `user` | Users (dating, follows) | like, pass, super_like |
| `event` | Events | favorite, interested |
| `post` | Blog posts | like |
| `product` | Products | favorite, bookmark |
| `location` | Locations | favorite |

Plugins can introduce new entity types and like types without schema changes.

