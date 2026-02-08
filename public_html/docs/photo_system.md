# Photo System

The photo system provides multi-photo management for any entity (users, events, locations, mailing lists). It consists of three layers: a polymorphic data model, theme-driven image sizing, and a reusable UI helper.

---

## Architecture Overview

```
┌──────────────────────────────────────────────────────────────┐
│  Views (admin or public)                                     │
│  ┌─────────────────────────────────────────────────────────┐ │
│  │ PhotoHelper::render_photo_card('grid', 'event', $id...) │ │
│  │ PhotoHelper::render_photo_scripts('grid', 'event', $id) │ │
│  └─────────────────────────────────────────────────────────┘ │
│                          │                                    │
│                          ▼                                    │
│  ┌─────────────────────────────────────────────────────────┐ │
│  │ AJAX: /ajax/entity_photos_ajax                          │ │
│  │   upload, delete, reorder, update_caption               │ │
│  └─────────────────────────────────────────────────────────┘ │
│                          │                                    │
│                          ▼                                    │
│  ┌────────────────────┐  ┌──────────────────┐                │
│  │ EntityPhoto model   │  │ File model       │                │
│  │ (eph_entity_photos) │  │ (fil_files)      │                │
│  └────────────────────┘  └──────────────────┘                │
│                          │                                    │
│                          ▼                                    │
│  ┌─────────────────────────────────────────────────────────┐ │
│  │ ImageSizeRegistry                                       │ │
│  │   Reads image_sizes from theme.json → drives File resize │ │
│  └─────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────┘
```

### Key Files

| File | Purpose |
|------|---------|
| `includes/PhotoHelper.php` | Reusable UI rendering (HTML + JS) |
| `includes/ImageSizeRegistry.php` | Theme-driven image size definitions |
| `data/entity_photos_class.php` | Polymorphic photo-entity association model |
| `data/files_class.php` | File storage, resizing, URL generation |
| `ajax/entity_photos_ajax.php` | AJAX endpoint for upload/delete/reorder/caption |

---

## EntityPhoto Data Model

**Table:** `eph_entity_photos`

Polymorphic association linking any entity type to files. Uses `eph_entity_type` (string) + `eph_entity_id` (int) to identify the owning entity.

```php
// Load all photos for an entity
$photos = new MultiEntityPhoto([
    'entity_type' => 'event',
    'entity_id' => $event->key,
    'deleted' => false
]);
$photos->load();

// Get the primary photo for an entity
$primary = EntityPhoto::get_primary('user', $user_id);

// Create a new association
$photo = new EntityPhoto(NULL);
$photo->set('eph_entity_type', 'event');
$photo->set('eph_entity_id', $event_id);
$photo->set('eph_fil_file_id', $file_id);
$photo->set('eph_is_primary', true);
$photo->save();
```

The `save()` method enforces the `max_entity_photos` setting (JSON object with per-entity-type limits, e.g. `{"user": 6, "event": 10}`).

### Entity Photo Methods

All four entity types have the same photo methods added to their model classes:

| Method | Description |
|--------|-------------|
| `set_primary_photo($photo_id)` | Clears old primary, sets new one, syncs the legacy FK column |
| `clear_primary_photo()` | Clears all primaries, nulls the legacy FK column |
| `get_photos()` | Returns `MultiEntityPhoto` for this entity (non-deleted, ordered by sort_order) |
| `get_primary_photo()` | Returns the primary `EntityPhoto` or null |
| `get_picture_link($size_key)` | Returns the URL for the primary photo at the given size, or a default fallback |

**Entity-specific defaults for `get_picture_link()`:**

| Entity | Default Size | Fallback Image |
|--------|-------------|----------------|
| User | `'avatar'` | `/assets/images/blank-avatar.png` |
| Event | `'original'` | `null` |
| Location | `'content'` | `null` |
| MailingList | `'content'` | `null` |

**Legacy FK sync:** When `set_primary_photo()` is called, it also updates the entity's legacy FK column (`usr_pic_picture_id`, `evt_fil_file_id`, `loc_fil_file_id`, `mlt_fil_file_id`) so that existing code using those columns continues to work.

---

## ImageSizeRegistry

Reads `image_sizes` from the active theme's `theme.json` and provides them to `File::resize()`.

```php
require_once(PathHelper::getIncludePath('includes/ImageSizeRegistry.php'));

// Get all registered sizes
$sizes = ImageSizeRegistry::get_sizes();

// Get a specific size
$avatar = ImageSizeRegistry::get_size('avatar');
// Returns: ['width' => 80, 'height' => 80, 'crop' => true, 'quality' => 90]

// Check if a size exists
if (ImageSizeRegistry::has_size('hero')) { ... }
```

### Default Sizes (Falcon theme)

Defined in `theme/falcon/theme.json` under `image_sizes`:

| Key | Width | Height | Crop | Use Case |
|-----|-------|--------|------|----------|
| `avatar` | 80 | 80 | Yes | User avatars, small thumbnails |
| `profile_card` | 400 | 500 | Yes | Photo grid items, profile cards |
| `content` | 800 | 0 | No | In-content images (auto height) |
| `hero` | 1200 | 0 | No | Hero/banner images (auto height) |
| `og_image` | 1200 | 630 | Yes | Social sharing / Open Graph |

Themes can override or add sizes in their own `theme.json`. The active theme's sizes are merged on top of Falcon's (which always loads as the base).

---

## PhotoHelper

`includes/PhotoHelper.php` — A static utility class that renders photo management UI. Handles its own `require_once` for `files_class.php` and `entity_photos_class.php`.

### Usage

Two method calls per page — one for the HTML card, one for the JavaScript:

```php
require_once(PathHelper::getIncludePath('includes/PhotoHelper.php'));

// In the page body: render the photo card
PhotoHelper::render_photo_card('grid', 'event', $event->key, $event_photos, [
    'set_primary_url' => '/admin/admin_event?evt_event_id=' . $event->key,
    'card_title' => 'Event Photos',
    'editable' => $can_edit,
]);

// Before </body> or in script section: render the JavaScript
PhotoHelper::render_photo_scripts('grid', 'event', $event->key, [
    'set_primary_url' => '/admin/admin_event?evt_event_id=' . $event->key,
    'confirm_delete_msg' => 'Remove this photo from the event?',
]);
```

### Parameters

```php
PhotoHelper::render_photo_card($mode, $entity_type, $entity_id, $photos, $options);
PhotoHelper::render_photo_scripts($mode, $entity_type, $entity_id, $options);
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$mode` | string | `'grid'` (multi-photo) or `'single'` (future) |
| `$entity_type` | string | Entity type: `'event'`, `'user'`, `'location'`, `'mailing_list'` |
| `$entity_id` | int | Entity primary key |
| `$photos` | MultiEntityPhoto | From `$entity->get_photos()` |
| `$options` | array | See options table below |

### Options

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `set_primary_url` | string | `''` | URL the set-primary form POSTs to (required) |
| `card_title` | string | `'Photos'` | Card header text |
| `image_size` | string | `'profile_card'` | ImageSizeRegistry key for display |
| `confirm_delete_msg` | string | `'Remove this photo?'` | Confirm dialog text |
| `editable` | bool | `true` | Show upload/delete/reorder/set-primary controls |
| `aspect_ratio` | string | `'4/5'` | CSS aspect-ratio for photo thumbnails |
| `empty_message` | string | `'No photos yet'` | Empty state text |

### Display Modes

**`grid` mode** (implemented):
- Bootstrap card with photo grid (`.row.g-2`, `.col-4.col-md-3`)
- Upload button in card header
- Drag-and-drop reorder
- Star icon overlay: solid gold = primary, outline = click to set
- X icon overlay: click to delete (with confirm dialog)
- Empty state with muted icon and message

**`single` mode** (stub, not yet implemented):
- For entities that only need one photo (locations, mailing lists)
- Will be implemented when an admin page needs it

### Element ID Namespacing

All element IDs are namespaced with `joinery-photo-{entity_type}-{entity_id}` to avoid collisions with other frameworks and to support multiple PhotoHelper instances on the same page.

| Element | ID Pattern |
|---------|------------|
| Grid container | `joinery-photo-grid-{type}-{id}` |
| Upload button | `joinery-photo-upload-btn-{type}-{id}` |
| File input | `joinery-photo-upload-input-{type}-{id}` |
| Empty message | `joinery-photo-empty-{type}-{id}` |

CSS classes used for event delegation (`.joinery-photo-item`, `.joinery-photo-set-primary-btn`, `.joinery-photo-delete-btn`) use the `joinery-photo-` prefix without entity suffixes since JS scopes queries to the grid container.

### CSS Requirements

PhotoHelper uses standard Bootstrap 5 classes (`btn-primary`, `card`, `row`, `col-*`, `position-absolute`, etc.) and Font Awesome icons (`fas fa-star`, `far fa-star`, `fas fa-times-circle`, `fas fa-plus`, `fas fa-images`, `fas fa-image`). It does **not** use Falcon-specific CSS classes, so it works on both admin and public pages.

---

## AJAX Endpoint

**File:** `ajax/entity_photos_ajax.php`

All photo operations (except set-primary) go through this endpoint via `fetch()` POST.

### Actions

| Action | Parameters | Description |
|--------|-----------|-------------|
| `upload` | `entity_type`, `entity_id`, `file` | Upload image, create File + EntityPhoto records |
| `delete` | `entity_type`, `entity_id`, `photo_id` | Soft-delete the EntityPhoto and its File |
| `reorder` | `entity_type`, `entity_id`, `photo_ids[]` | Update `eph_sort_order` for all photos |
| `update_caption` | `entity_type`, `entity_id`, `photo_id`, `caption` | Update `eph_caption` field |

### Authorization

The `check_photo_permission()` function grants access when any of these conditions are met:
1. User has admin permission (>= 5)
2. User owns the file being operated on (`fil_usr_user_id` matches session)
3. User is managing their own entity (`entity_type === 'user'` and `entity_id` matches session user)

### Set Primary

Set-primary uses a form POST (not AJAX) because it needs to update the entity model and redirect with a flash message. The logic file for the page handles the `set_primary_photo` and `clear_primary_photo` POST actions.

---

## Adding Photos to a New Entity Type

To add photo support to a new entity type:

### 1. Add photo methods to the entity model

Add these five methods to the entity class (copy from `User` or `Event` as a template):

```php
function set_primary_photo($photo_id) { ... }
function clear_primary_photo() { ... }
function get_photos() { ... }
function get_primary_photo() { ... }
function get_picture_link($size_key = 'content') { ... }
```

### 2. Add set_primary POST handler to the page's logic file

```php
if (isset($post_vars['action']) && $post_vars['action'] == 'set_primary_photo') {
    $entity = new MyEntity($entity_id, TRUE);
    $entity->set_primary_photo((int)$post_vars['photo_id']);
    // DisplayMessage + redirect
}

if (isset($post_vars['action']) && $post_vars['action'] == 'clear_primary_photo') {
    $entity = new MyEntity($entity_id, TRUE);
    $entity->clear_primary_photo();
    // DisplayMessage + redirect
}
```

### 3. Load photos in the logic file

```php
$page_vars['entity_photos'] = $entity->get_photos();
```

### 4. Add PhotoHelper to the view

```php
require_once(PathHelper::getIncludePath('includes/PhotoHelper.php'));

PhotoHelper::render_photo_card('grid', 'my_entity', $entity->key, $page_vars['entity_photos'], [
    'set_primary_url' => '/admin/admin_my_entity?id=' . $entity->key,
    'card_title' => 'Photos',
]);

// ... rest of page ...

PhotoHelper::render_photo_scripts('grid', 'my_entity', $entity->key, [
    'set_primary_url' => '/admin/admin_my_entity?id=' . $entity->key,
]);
```

### 5. Update max_entity_photos setting (if needed)

The `max_entity_photos` setting is a JSON object. Add a key for the new entity type:

```json
{"user": 6, "event": 10, "location": 10, "my_entity": 10}
```

### 6. Run data migration (if the entity has existing photos in a legacy FK column)

Create a migration that copies existing FK references into `eph_entity_photos` rows.

---

## Current Usage

| Page | Mode | Entity Type | File |
|------|------|-------------|------|
| Admin Event | `grid` | `event` | `adm/admin_event.php` |
| Account Edit (public) | `grid` | `user` | `views/profile/account_edit.php` |

Future candidates: `admin_location.php` (single), `admin_mailing_list.php` (single), dating profile edit (grid).
