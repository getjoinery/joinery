# Profile Picture Upload Spec

**Purpose:** Add profile picture upload and management to the public-facing account edit page, allowing users to upload, view, and change their profile picture. This exercises the EntityPhoto system, entity_photos AJAX endpoint, `User::set_primary_photo()`, and the `File::resize()` pipeline from the user-facing side.

**Last Updated:** 2026-02-07

**Depends on:** [Pictures Refactor Spec](implemented/pictures_refactor_spec.md) (implemented)

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
- `User::get_picture_link($size_key='avatar')` -- returns URL from `usr_pic_picture_id`, defaults to `/img/default_avatar.png`

**Entity photos AJAX** (`ajax/entity_photos_ajax.php`):
- upload, delete, reorder, update_caption actions
- Auth: session user must be admin (perm >= 5) OR file owner
- Currently only admin users can upload via this endpoint (the `check_photo_permission` function requires perm >= 5 for upload since no file_id exists yet)

### Problems

1. **No way for users to upload a profile picture** -- the account edit page has no photo field
2. **Profile page shows hardcoded placeholder** -- doesn't use `User::get_picture_link()`
3. **AJAX endpoint auth blocks regular users for upload** -- `check_photo_permission()` requires admin OR file owner, but on upload there's no file_id yet, so non-admins are blocked
4. **No default avatar image exists** -- `User::get_picture_link()` returns `/img/default_avatar.png` but that file may not exist

---

## 2. Design

### 2.1 Overview

Add a "Profile Picture" section to the account edit page that allows users to:
- View their current profile picture (or a default avatar)
- Upload a new profile picture
- Remove their profile picture

The upload uses the existing `entity_photos_ajax.php` endpoint after fixing its auth to allow self-service uploads.

### 2.2 Account Edit Page Changes

Add a profile picture section above the existing form fields in `views/profile/account_edit.php`.

**Layout:**
```
+--------------------------------------------------+
| Edit Account                                      |
+--------------------------------------------------+
| [Tab: Edit Account] [Tab: Change Password] ...   |
+--------------------------------------------------+
|                                                   |
|    +----------+                                   |
|    |          |   [Change Photo]                  |
|    |  avatar  |   [Remove Photo]                  |
|    |          |                                   |
|    +----------+                                   |
|                                                   |
|    First Name: [___________]                      |
|    Last Name:  [___________]                      |
|    ...existing fields...                          |
|                                                   |
|    [Submit]                                       |
+--------------------------------------------------+
```

**Current photo display:**
- Shows the user's current profile picture at `profile_card` size (400x500) in a rounded container
- If no photo, shows a default avatar placeholder
- "Change Photo" button triggers file upload
- "Remove Photo" button (only shown when a photo exists) clears the primary photo

### 2.3 Auth Fix for Self-Service Upload

**`ajax/entity_photos_ajax.php`** -- The `check_photo_permission()` function needs adjustment for the upload case:

Current logic:
```php
function check_photo_permission($session, $file_id = null) {
    if ($session->get_permission() >= 5) return true;  // Admin
    if ($file_id) { /* check file owner */ }
    return false;  // Blocks non-admins on upload!
}
```

For upload, a non-admin user should be allowed to upload photos for their **own** entity. The fix is to also check entity ownership:

- If `entity_type == 'user'` and `entity_id == session user_id`, allow upload
- This is safe because the user can only attach photos to their own profile

Add a new parameter for entity context:

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

Update the upload case to pass entity context:
```php
case 'upload':
    if (!check_photo_permission($session, null, $entity_type, $entity_id)) { ... }
```

### 2.4 Profile Picture Flow

**Upload:**
1. User clicks "Change Photo"
2. Hidden `<input type="file" accept="image/*">` opens file picker
3. On file select, POST to `entity_photos_ajax.php` with `action=upload`, `entity_type=user`, `entity_id={usr_user_id}`, `file=...`
4. AJAX creates File record, resizes, creates EntityPhoto
5. On success, POST to account_edit with `action=set_primary_photo` and `photo_id` from AJAX response
6. Page reloads showing new photo

**Simplified alternative:** Instead of two-step AJAX+form, the upload AJAX response can trigger a second AJAX call or form submission to set_primary_photo. For MVP, a page reload after upload is acceptable.

**Remove:**
1. User clicks "Remove Photo"
2. `confirm()` dialog
3. POST to account_edit with `action=clear_primary_photo`
4. Page reloads showing default avatar

### 2.5 Single Photo Simplification

For user profile pictures, the multi-photo gallery (reorder, captions) is not needed in the MVP. Users upload one photo at a time -- a new upload replaces the previous one as the primary. The old photo stays in entity_photos (not deleted) but is not primary.

Future: A full photo gallery for user profiles (like dating sites need) is a separate feature.

### 2.6 Profile Page Changes

**`views/profile/profile.php`:**
- Replace the hardcoded placeholder avatar (`../../assets/img/team/1.jpg`) with `$page_vars['user']->get_picture_link('profile_card')`
- Show the user's actual profile picture or the default avatar

**`logic/profile_logic.php`:**
- Add `require_once` for `data/files_class.php` (needed by `get_picture_link()`)

### 2.7 Default Avatar

Ensure a default avatar image exists at `/img/default_avatar.png` (or `/assets/img/default_avatar.png`). This is what `User::get_picture_link()` returns when `usr_pic_picture_id` is NULL.

---

## 3. Implementation

### 3.1 Files to Modify

| File | Changes |
|------|---------|
| `ajax/entity_photos_ajax.php` | Fix `check_photo_permission()` to allow self-service upload for own entity |
| `logic/account_edit_logic.php` | Add set_primary_photo/clear_primary_photo POST handlers, load files_class |
| `views/profile/account_edit.php` | Add profile picture section with upload/remove UI, inline JS |
| `logic/profile_logic.php` | Add files_class require |
| `views/profile/profile.php` | Replace hardcoded avatar with `get_picture_link()` |

### 3.2 New Files

| File | Purpose |
|------|---------|
| `img/default_avatar.png` | Default avatar placeholder (simple silhouette, ~200x200) |

### 3.3 Logic Changes Detail

**`logic/account_edit_logic.php`** additions:

```php
// At top with other requires:
require_once(PathHelper::getIncludePath('data/files_class.php'));

// In POST handling, before existing post handling:
if ($post_vars['action'] == 'set_primary_photo') {
    $user = new User($session->get_user_id(), TRUE);
    $user->set_primary_photo((int)$post_vars['photo_id']);

    $msgtxt = 'Your profile picture has been updated.';
    $message = new DisplayMessage($msgtxt, 'Photo updated', '/\/profile\/account_edit.*/', DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'userbox', TRUE);
    $session->save_message($message);
    return LogicResult::redirect('/profile/account_edit');
}

if ($post_vars['action'] == 'clear_primary_photo') {
    $user = new User($session->get_user_id(), TRUE);
    $user->clear_primary_photo();

    $msgtxt = 'Your profile picture has been removed.';
    $message = new DisplayMessage($msgtxt, 'Photo removed', '/\/profile\/account_edit.*/', DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'userbox', TRUE);
    $session->save_message($message);
    return LogicResult::redirect('/profile/account_edit');
}
```

### 3.4 View Changes Detail

**`views/profile/account_edit.php`** -- Add before the existing form fields:

```php
<!-- Profile Picture Section -->
<div class="mb-4 text-center">
    <div class="mb-3">
        <img src="<?php echo htmlspecialchars($page_vars['user']->get_picture_link('profile_card')); ?>"
             alt="Profile Picture"
             class="rounded-circle" style="width: 150px; height: 150px; object-fit: cover;"
             id="current-avatar">
    </div>
    <button type="button" class="btn btn-falcon-default btn-sm me-2" id="btn-change-photo">
        Change Photo
    </button>
    <?php if($page_vars['user']->get('usr_pic_picture_id')): ?>
    <button type="button" class="btn btn-falcon-default btn-sm text-danger" id="btn-remove-photo">
        Remove Photo
    </button>
    <?php endif; ?>
    <input type="file" id="photo-upload-input" accept="image/*" style="display:none;">
</div>
```

### 3.5 JavaScript Detail

Inline `<script>` in account_edit.php:

**Change Photo:**
1. `btn-change-photo` click → trigger `photo-upload-input` click
2. On file select, create FormData: `action=upload`, `entity_type=user`, `entity_id=<?php echo $page_vars['user']->key; ?>`, `file=...`
3. POST to `/ajax/entity_photos_ajax`
4. On success, create and submit a hidden form: `action=set_primary_photo`, `photo_id={response.photo.photo_id}` → POST to `/profile/account_edit`
5. Page reloads with new photo and success message

**Remove Photo:**
1. `btn-remove-photo` click → `confirm('Remove your profile picture?')`
2. Create and submit hidden form: `action=clear_primary_photo` → POST to `/profile/account_edit`
3. Page reloads with default avatar and success message

### 3.6 AJAX Auth Fix Detail

**`ajax/entity_photos_ajax.php`** -- Update `check_photo_permission()`:

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

Update the upload case call:
```php
case 'upload':
    // ...
    if (!check_photo_permission($session, null, $entity_type, $entity_id)) {
```

---

## 4. Pictures Refactor Coverage

This feature exercises the following untested parts of the pictures refactor:

| Component | How It's Tested |
|-----------|----------------|
| `entity_photos_ajax.php` upload (non-admin) | User uploads their own profile picture |
| `User::set_primary_photo()` | Setting uploaded photo as primary |
| `User::clear_primary_photo()` | Removing profile picture |
| `User::get_picture_link()` | Displaying current avatar on profile and edit pages |
| `User::get_photos()` | (Implicitly, via set_primary_photo clearing old primaries) |
| `File::resize()` via AJAX upload | New profile photos get all registered sizes |
| `EntityPhoto::save()` limit enforcement | Upload when at max_entity_photos user limit (6) |
| `max_entity_photos` setting | User photo count checked against limit |

---

## 5. Not In Scope

- **Multi-photo gallery for users** -- MVP is single profile picture only; multi-photo is for dating plugin
- **Photo cropping UI** -- server-side crop via ImageSizeRegistry is sufficient
- **Admin user page photo management** -- separate feature, admin can use file management tools
- **Profile picture display in navigation/header** -- separate UI feature
- **Social features** (likes, comments on photos) -- dating plugin scope
