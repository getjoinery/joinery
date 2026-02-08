# Profile Picture Upload Spec

**Purpose:** Add multi-photo upload and management to the public-facing account edit page, and introduce a reusable `PhotoHelper` class that centralizes all entity photo UI rendering. This eliminates duplicated photo grid code across pages, exercises the EntityPhoto system from the user-facing side, and prepares the foundation for dating profiles.

**Last Updated:** 2026-02-07

**Depends on:** [Pictures Refactor Spec](implemented/pictures_refactor_spec.md) (implemented), [Admin Event Photo Manager](implemented/admin_event_photo_manager_spec.md) (implemented -- provides the UI pattern to extract into PhotoHelper)

---

## 1. Current State

### What Exists

**Account edit page** (`views/profile/account_edit.php` + `logic/account_edit_logic.php`):
- Tab-based profile editing UI with tabs: Edit Account, Change Password, Edit Address, Edit Phone Number, Change Contact Preferences
- Current form fields: first name, last name, nickname (configurable), timezone
- No profile picture field or upload functionality
- Uses FormWriter V2 with model binding to User

**Profile page** (`views/profile/profile.php` + `logic/profile_logic.php`):
- Shows user info in sidebar: name, email, address, mailing lists
- Uses hardcoded placeholder avatar: `../../assets/img/team/1.jpg`
- "Edit Account" button links to `/profile/account_edit`
- Mostly Falcon theme boilerplate/demo content -- not yet customized

**User model photo methods** (from pictures refactor):
- `User::set_primary_photo($photo_id)` -- clears old primary, sets new, syncs `usr_pic_picture_id`
- `User::clear_primary_photo()` -- clears all primaries, nulls FK
- `User::get_photos()` -- returns MultiEntityPhoto for entity_type='user'
- `User::get_primary_photo()` -- returns EntityPhoto or null
- `User::get_picture_link($size_key='avatar')` -- returns URL from `usr_pic_picture_id`, defaults to `/assets/images/blank-avatar.png`

**Entity photos AJAX** (`ajax/entity_photos_ajax.php`):
- upload, delete, reorder, update_caption actions
- Auth: session user must be admin (perm >= 5) OR file owner
- Currently only admin users can upload via this endpoint (the `check_photo_permission` function requires perm >= 5 for upload since no file_id exists yet)

**Admin event photo grid** (`adm/admin_event.php`):
- Working multi-photo grid with upload, delete, set primary, drag-and-drop reorder
- ~230 lines of inline HTML (~60) and JavaScript (~170)
- Uses `entity_photos_ajax.php` for upload/delete/reorder
- Uses form POST for set_primary_photo/clear_primary_photo
- Photo items display at `profile_card` size with overlay icons (star for primary, X to delete)
- Icons use dark semi-transparent backgrounds for visibility on any image

### Problems

1. **No way for users to upload a profile picture** -- the account edit page has no photo field
2. **Profile page shows hardcoded placeholder** -- doesn't use `User::get_picture_link()`
3. **AJAX endpoint auth blocks regular users for upload** -- `check_photo_permission()` requires admin OR file owner, but on upload there's no file_id yet, so non-admins are blocked
4. **Default avatar not wired up** -- `User::get_picture_link()` needs to return a path that the static file handler can serve (use existing `/assets/images/blank-avatar.png`)

---

## 2. Design

### 2.1 Overview

Two deliverables:

1. **PhotoHelper class** (`includes/PhotoHelper.php`) -- a static utility class that renders entity photo management UI. Supports multiple display modes (`grid` for multi-photo, `single` for single-photo) so any page can add photo management with two method calls. Extracted from the working admin_event.php implementation.

2. **Account edit page photo upload** -- add a "My Photos" grid to the account edit page using PhotoHelper, with auth fix so non-admin users can upload their own photos.

### 2.2 PhotoHelper Class

**File:** `includes/PhotoHelper.php`

A static utility class with no state. Two public methods render the HTML card and the JavaScript separately (since JS typically goes at the bottom of the page or in a script block).

#### API

```php
require_once(PathHelper::getIncludePath('includes/PhotoHelper.php'));

// Render the photo card HTML
PhotoHelper::render_photo_card($mode, $entity_type, $entity_id, $photos, $options);

// Render the associated JavaScript
PhotoHelper::render_photo_scripts($mode, $entity_type, $entity_id, $options);
```

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$mode` | string | Display mode: `'grid'` or `'single'` |
| `$entity_type` | string | Entity type for EntityPhoto system (e.g., `'event'`, `'user'`, `'location'`) |
| `$entity_id` | int | Entity primary key |
| `$photos` | MultiEntityPhoto | Photo collection from `$entity->get_photos()` |
| `$options` | array | Configuration options (see below) |

**Options array:**

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `set_primary_url` | string | *(required)* | URL the set-primary form POSTs to (e.g., `/admin/admin_event?evt_event_id=123`) |
| `card_title` | string | `'Photos'` | Card header text |
| `image_size` | string | `'profile_card'` | ImageSizeRegistry key for display |
| `confirm_delete_msg` | string | `'Remove this photo?'` | Confirm dialog text for delete |
| `editable` | bool | `true` | Show upload/delete/reorder/set-primary controls. When false, display-only. |
| `aspect_ratio` | string | `'4/5'` | CSS aspect-ratio for photo display |
| `empty_message` | string | `'No photos yet'` | Text shown when no photos exist |

#### Display Modes

**`grid` mode** (multi-photo):
- Bootstrap card with photo grid inside
- Upload button in card header
- Photos in `.row.g-2` layout, each in `.col-4.col-md-3`
- Drag-and-drop reorder between photos
- Primary star icon (solid gold = current primary, outline = click to set)
- Delete X icon on each photo
- Empty state with muted icon and message
- All interactions via AJAX (`entity_photos_ajax.php`) except set-primary (form POST)

**`single` mode** (single-photo, for future use):
- Bootstrap card with single centered photo
- Upload/Change button in card header (label changes based on whether photo exists)
- One photo displayed at configurable size
- No reorder (only one photo)
- No primary star (the one photo IS the primary)
- Delete button to remove
- Empty state with placeholder icon
- Upload auto-sets as primary; delete auto-clears primary

#### What Varies Between Callers

Only 5 parameters change between instances -- everything else is identical:

| Parameter | admin_event.php | account_edit.php | Future: admin_location.php |
|-----------|----------------|-----------------|---------------------------|
| `entity_type` | `'event'` | `'user'` | `'location'` |
| `entity_id` | `$event->key` | `$user->key` | `$location->key` |
| `set_primary_url` | `/admin/admin_event?evt_event_id=X` | `/profile/account_edit` | `/admin/admin_location?loc_location_id=X` |
| `card_title` | `'Event Photos'` | `'My Photos'` | `'Location Photo'` |
| `editable` | `!$deleted && $perm > 7` | `true` | `!$deleted && $perm > 7` |

#### Element ID Namespacing

All HTML element IDs are namespaced with `joinery-photo-{entity_type}-{entity_id}` to avoid collisions with other frameworks and to support multiple PhotoHelper instances on the same page.

| Current (admin_event.php) | PhotoHelper ID |
|--------------------------|----------------|
| `photo-grid` | `joinery-photo-grid-event-123` |
| `btn-upload-photo` | `joinery-photo-upload-btn-event-123` |
| `photo-upload-input` | `joinery-photo-upload-input-event-123` |
| `no-photos-msg` | `joinery-photo-empty-event-123` |

CSS classes that are used for event delegation (`.joinery-photo-item`, `.joinery-photo-set-primary-btn`, `.joinery-photo-delete-btn`) are also joinery-namespaced but do not need entity suffixes since the JS scopes queries to the grid container.

PhotoHelper generates the ID prefix internally:
```php
$prefix = 'joinery-photo-' . $entity_type . '-' . $entity_id;
// e.g., "joinery-photo-event-123", "joinery-photo-user-45"
```

#### Internal Requires

PhotoHelper handles its own `require_once` calls for `data/files_class.php` and `data/entity_photos_class.php` so callers don't need to load them separately.

#### Internal Structure

Both methods output HTML directly (like FormWriter methods). The `render_photo_card()` method handles:

1. Card wrapper with header (title + upload button if editable)
2. Hidden file input for upload
3. Photo loop: for each photo, render the image with overlay icons
4. Empty state when no photos
5. Hidden form for set-primary POST

The `render_photo_scripts()` method outputs a `<script>` block with:

1. Upload handler (file input → FormData → fetch to entity_photos_ajax)
2. Set-primary handler (click star → submit hidden form)
3. Delete handler (click X → confirm → fetch to entity_photos_ajax → remove from DOM)
4. Drag-and-drop reorder handler (dragstart/dragover/dragend/drop → fetch to entity_photos_ajax)

For `single` mode, the script omits the reorder handler and the upload handler auto-sets primary after upload.

### 2.3 Account Edit Page Changes

Add a "My Photos" grid above the existing form in `views/profile/account_edit.php` using PhotoHelper:

```php
<?php
require_once(PathHelper::getIncludePath('includes/PhotoHelper.php'));
PhotoHelper::render_photo_card('grid', 'user', $page_vars['user']->key, $page_vars['user_photos'], [
    'set_primary_url' => '/profile/account_edit',
    'card_title' => 'My Photos',
]);
?>

<!-- ...existing form... -->

<?php
PhotoHelper::render_photo_scripts('grid', 'user', $page_vars['user']->key, [
    'confirm_delete_msg' => 'Remove this photo?',
]);
?>
```

**Layout:**
```
+--------------------------------------------------+
| Edit Account                                      |
+--------------------------------------------------+
| [Tab: Edit Account] [Tab: Change Password] ...   |
+--------------------------------------------------+
|                                                   |
| My Photos                          [+ Upload]    |
| +--------+ +--------+ +--------+                 |
| | *    x | | *    x | | *    x |                 |
| | photo1 | | photo2 | | photo3 |                 |
| |        | |        | |        |                 |
| +--------+ +--------+ +--------+                 |
|                                                   |
|    First Name: [___________]                      |
|    Last Name:  [___________]                      |
|    ...existing fields...                          |
|                                                   |
|    [Submit]                                       |
+--------------------------------------------------+
```

### 2.4 Admin Event Page Refactor

Replace the inline photo grid HTML and JS in `adm/admin_event.php` with PhotoHelper calls. This is the extraction source -- after refactoring, the behavior should be identical.

**Before** (~230 lines inline):
```php
<!-- Event Photos Card -->
<div class="card mt-3">
    <!-- ...60 lines of HTML... -->
</div>
<!-- ...170 lines of JavaScript... -->
```

**After** (~10 lines):
```php
<?php
require_once(PathHelper::getIncludePath('includes/PhotoHelper.php'));
$photo_editable = !$event->get('evt_delete_time') && $_SESSION['permission'] > 7;
PhotoHelper::render_photo_card('grid', 'event', $event->key, $event_photos, [
    'set_primary_url' => '/admin/admin_event?evt_event_id=' . $event->key,
    'card_title' => 'Event Photos',
    'editable' => $photo_editable,
]);
?>

<!-- later, in the script section: -->
<?php if($photo_editable): ?>
<?php PhotoHelper::render_photo_scripts('grid', 'event', $event->key, [
    'confirm_delete_msg' => 'Remove this photo from the event?',
]); ?>
<?php endif; ?>
```

The legacy external image fallback (`evt_picture_link`) stays inline in admin_event.php since it's event-specific and will be removed eventually.

### 2.5 Auth Fix for Self-Service Upload

**`ajax/entity_photos_ajax.php`** -- The `check_photo_permission()` function needs adjustment for self-service use:

Current logic:
```php
function check_photo_permission($session, $file_id = null) {
    if ($session->get_permission() >= 5) return true;  // Admin
    if ($file_id) { /* check file owner */ }
    return false;  // Blocks non-admins on upload!
}
```

Add entity ownership check:

```php
function check_photo_permission($session, $file_id = null, $entity_type = null, $entity_id = null) {
    if ($session->get_permission() >= 5) return true;
    if ($file_id) {
        $file = new File($file_id, TRUE);
        if ($file->get('fil_usr_user_id') == $session->get_user_id()) return true;
    }
    // Self-service: user managing their own entity
    if ($entity_type === 'user' && $entity_id == $session->get_user_id()) return true;
    return false;
}
```

Update all action cases to pass entity context where needed:
```php
case 'upload':
    if (!check_photo_permission($session, null, $entity_type, $entity_id)) { ... }
case 'reorder':
    if (!check_photo_permission($session, null, $entity_type, $entity_id)) { ... }
case 'delete':
    if (!check_photo_permission($session, $photo->get('eph_fil_file_id'), $entity_type, $entity_id)) { ... }
```

### 2.6 Profile Page Changes

**`views/profile/profile.php`:**
- Replace the hardcoded placeholder avatar (`../../assets/img/team/1.jpg`) with `$page_vars['user']->get_picture_link('profile_card')`
- Show the user's actual primary photo or the default avatar

**`logic/profile_logic.php`:**
- Add `require_once` for `data/files_class.php` (needed by `get_picture_link()`)

### 2.7 Default Avatar

Use the existing default avatar at `/assets/images/blank-avatar.png` (already in the codebase). `User::get_picture_link()` returns this path when `usr_pic_picture_id` is NULL. The `/assets/` route is served by the static file handler, so the image is accessible at the URL `/assets/images/blank-avatar.png`.

---

## 3. Implementation

### 3.1 Files to Modify

| File | Changes |
|------|---------|
| `ajax/entity_photos_ajax.php` | Fix `check_photo_permission()` to allow self-service upload/reorder for own entity |
| `adm/admin_event.php` | Replace inline photo grid HTML+JS (~230 lines) with PhotoHelper calls (~10 lines) |
| `logic/account_edit_logic.php` | Add `set_primary_photo` / `clear_primary_photo` POST handlers, load entity_photos and files classes, pass `user_photos` to page_vars |
| `views/profile/account_edit.php` | Add PhotoHelper photo grid card above existing form |
| `logic/profile_logic.php` | Add files_class require |
| `views/profile/profile.php` | Replace hardcoded avatar with `get_picture_link()` |

### 3.2 New Files

| File | Purpose |
|------|---------|
| `includes/PhotoHelper.php` | Static utility class for rendering entity photo management UI |

### 3.3 PhotoHelper Implementation Detail

**`includes/PhotoHelper.php`:**

```php
<?php
/**
 * PhotoHelper - Renders entity photo management UI components
 *
 * Static utility class that outputs HTML and JavaScript for managing
 * entity photos (upload, delete, reorder, set primary). Supports
 * multiple display modes for different use cases.
 *
 * Usage:
 *   require_once(PathHelper::getIncludePath('includes/PhotoHelper.php'));
 *   PhotoHelper::render_photo_card('grid', 'event', $id, $photos, $options);
 *   PhotoHelper::render_photo_scripts('grid', 'event', $id, $options);
 *
 * @version 1.0.0
 */
class PhotoHelper {

    public static function render_photo_card($mode, $entity_type, $entity_id, $photos, $options = []) {
        // Merge defaults
        // Switch on $mode to call private render method
    }

    public static function render_photo_scripts($mode, $entity_type, $entity_id, $options = []) {
        // Merge defaults
        // Switch on $mode to call private render method
    }

    // --- Private renderers ---

    private static function render_grid_card($entity_type, $entity_id, $photos, $options) {
        // Card wrapper, upload button, photo loop, empty state
        // Extracted from admin_event.php lines 290-348
    }

    private static function render_grid_scripts($entity_type, $entity_id, $options) {
        // Upload, set-primary, delete, drag-and-drop JS
        // Extracted from admin_event.php lines 688-905
    }

    private static function render_single_card($entity_type, $entity_id, $photos, $options) {
        // Single photo display with upload/change/delete
    }

    private static function render_single_scripts($entity_type, $entity_id, $options) {
        // Upload (auto-set-primary), delete (auto-clear-primary) JS
    }
}
```

### 3.4 Logic Changes Detail

**`logic/account_edit_logic.php`** additions:

```php
// At top with other requires:
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));

// In POST handling, before existing post handling:
if (isset($post_vars['action']) && $post_vars['action'] == 'set_primary_photo') {
    $user = new User($session->get_user_id(), TRUE);
    $user->set_primary_photo((int)$post_vars['photo_id']);

    $msgtxt = 'Your profile picture has been updated.';
    $message = new DisplayMessage($msgtxt, 'Photo updated', '/\/profile\/account_edit.*/',
        DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'userbox', TRUE);
    $session->save_message($message);
    return LogicResult::redirect('/profile/account_edit');
}

if (isset($post_vars['action']) && $post_vars['action'] == 'clear_primary_photo') {
    $user = new User($session->get_user_id(), TRUE);
    $user->clear_primary_photo();

    $msgtxt = 'Your profile picture has been removed.';
    $message = new DisplayMessage($msgtxt, 'Photo removed', '/\/profile\/account_edit.*/',
        DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'userbox', TRUE);
    $session->save_message($message);
    return LogicResult::redirect('/profile/account_edit');
}

// In page_vars setup (after loading user):
$page_vars['user_photos'] = $user->get_photos();
```

### 3.5 AJAX Auth Fix Detail

**`ajax/entity_photos_ajax.php`** -- Update `check_photo_permission()` signature and add entity ownership check:

```php
function check_photo_permission($session, $file_id = null, $entity_type = null, $entity_id = null) {
    // Admin always allowed
    if ($session->get_permission() >= 5) {
        return true;
    }
    // File owner check (for delete, update_caption)
    if ($file_id) {
        $file = new File($file_id, TRUE);
        if ($file->get('fil_usr_user_id') == $session->get_user_id()) {
            return true;
        }
    }
    // Self-service: user can manage photos on their own entity
    if ($entity_type === 'user' && $entity_id == $session->get_user_id()) {
        return true;
    }
    return false;
}
```

---

## 4. Implementation Order

1. **Create `includes/PhotoHelper.php`** with `grid` mode -- extract HTML and JS from admin_event.php into the static methods
2. **Refactor `adm/admin_event.php`** to use PhotoHelper -- replace inline code with method calls, verify identical behavior
3. **Fix `ajax/entity_photos_ajax.php`** auth -- add entity ownership check to `check_photo_permission()`
4. **Update `logic/account_edit_logic.php`** -- add requires, POST handlers, user_photos in page_vars
5. **Update `views/profile/account_edit.php`** -- add PhotoHelper grid card
6. **Update profile page** -- replace hardcoded avatar, add files_class require
7. **Wire up default avatar** -- point `User::get_picture_link()` fallback to existing `/assets/images/blank-avatar.png`
8. **Implement `single` mode** (optional, can defer) -- add private render methods for single-photo display

Steps 1-2 are a safe refactor with no behavior change. Steps 3-7 add the new feature. Step 8 is independent and can be done later when a location or mailing list admin page needs it.

---

## 5. Pictures Refactor Coverage

This feature exercises the following parts of the pictures refactor from the user-facing side:

| Component | How It's Tested |
|-----------|----------------|
| `entity_photos_ajax.php` upload (non-admin) | User uploads their own profile photos |
| `entity_photos_ajax.php` delete (non-admin) | User deletes their own photos |
| `entity_photos_ajax.php` reorder (non-admin) | User reorders their photos via drag-and-drop |
| `User::set_primary_photo()` | Setting a photo as primary via star icon |
| `User::clear_primary_photo()` | Removing primary (when last photo deleted) |
| `User::get_photos()` | Loading photo grid on page load |
| `User::get_picture_link()` | Displaying current avatar on profile page |
| `File::resize()` via AJAX upload | New profile photos get all registered sizes |
| `EntityPhoto::save()` limit enforcement | Upload when at max_entity_photos user limit (6) |
| `max_entity_photos` setting | User photo count checked against limit |

---

## 6. Future Uses of PhotoHelper

Pages that will use PhotoHelper once the `single` and `grid` modes are available:

| Page | Mode | Entity Type | Notes |
|------|------|-------------|-------|
| `adm/admin_event.php` | `grid` | event | **This spec** -- refactored from inline code |
| `views/profile/account_edit.php` | `grid` | user | **This spec** -- new feature |
| `adm/admin_location.php` | `single` | location | Future -- replace read-only image display |
| `adm/admin_mailing_list.php` | `single` | mailing_list | Future -- replace file dropdown with visual upload |
| Dating profile edit | `grid` | user | Future -- dating spec section 1.1 |

---

## 7. Not In Scope

- **Photo cropping UI** -- server-side crop via ImageSizeRegistry is sufficient
- **Admin user page photo management** -- separate feature, admin can use file management tools
- **Profile picture display in navigation/header** -- separate UI feature
- **Social features** (likes, comments on photos) -- dating plugin scope
- **Captions on user photos** -- the AJAX endpoint supports it, but no UI needed yet
- **`single` mode implementation** -- defined in design, but can be deferred until a page needs it
