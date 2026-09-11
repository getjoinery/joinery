# Admin Event Photo Manager Spec

**Purpose:** Add a multi-photo management interface to the admin event view page, replacing the current single-image display with a full photo gallery that exercises the new EntityPhoto system, entity_photos AJAX endpoint, and `Event::set_primary_photo()`.

**Last Updated:** 2026-02-07

**Depends on:** [Pictures Refactor Spec](implemented/pictures_refactor_spec.md) (implemented)

---

## 1. Current State

### What Exists

**Admin event view** (`adm/admin_event.php` + `adm/logic/admin_event_logic.php`):
- Displays a single "Event Image" card in the right column (lines 253-282)
- Shows the image from `evt_fil_file_id` via `$event_image->get_url()` (original size)
- Falls back to `evt_picture_link` (external URL) if no file FK
- "Change Image" links to the event edit page
- No photo upload, reorder, delete, or primary selection on this page

**Admin event edit** (`adm/admin_event_edit.php` + `adm/logic/admin_event_edit_logic.php`):
- Uses `$formwriter->imageinput('evt_fil_file_id', ...)` to select from existing images
- Loads ALL images via `MultiFile(['deleted'=>false, 'picture'=>true])` into a dropdown
- Saves `evt_fil_file_id` directly on POST (lines 43-48 of logic)
- No upload capability on this page -- must upload via admin_file_upload first

**EntityPhoto system** (from pictures refactor):
- `data/entity_photos_class.php` -- EntityPhoto + MultiEntityPhoto models
- `ajax/entity_photos_ajax.php` -- upload, delete, reorder, update_caption actions
- `Event::set_primary_photo($photo_id)` -- clears old primary, sets new, syncs `evt_fil_file_id`
- `Event::clear_primary_photo()` -- clears primary, nulls FK
- `Event::get_photos()` -- returns MultiEntityPhoto sorted by sort_order
- `Event::get_picture_link($size_key)` -- returns URL from `evt_fil_file_id`
- Migration populated `eph_entity_photos` with existing event photos (48 rows)

### Problems

1. **No way to add multiple photos to an event** -- the edit page only sets a single FK via dropdown
2. **No direct upload on event pages** -- must navigate to file upload, then come back and select
3. **EntityPhoto system is unused in the UI** -- all the AJAX endpoints and entity methods exist but nothing calls them
4. **No primary photo selection** -- `Event::set_primary_photo()` has no UI trigger
5. **No photo reordering or captions** -- entity_photos supports these but no UI exists

---

## 2. Design

### 2.1 Overview

Replace the single "Event Image" card on the admin event view page with a "Photos" card that shows a grid of all photos associated with the event via `eph_entity_photos`. The card supports:

- Viewing all photos in a thumbnail grid
- Uploading new photos directly (via AJAX)
- Setting any photo as primary (star icon)
- Deleting photos (soft-delete via AJAX)
- Drag-to-reorder (via AJAX)

### 2.2 Admin Event View Page Changes

**Replace** the current "Event Image" card (lines 253-282 of `adm/admin_event.php`) with a new "Event Photos" card.

**Card layout:**
```
+--------------------------------------------------+
| Event Photos                          [Upload +] |
+--------------------------------------------------+
| +--------+  +--------+  +--------+  +--------+  |
| | [img]  |  | [img]  |  | [img]  |  | [img]  |  |
| | *      |  |        |  |        |  |        |  |
| | [x]    |  | [x]    |  | [x]    |  | [x]    |  |
| +--------+  +--------+  +--------+  +--------+  |
|                                                  |
| * = primary photo indicator                      |
+--------------------------------------------------+
```

Each thumbnail:
- Shows the image at `profile_card` size (400x500, fits well in a grid)
- Gold star overlay on the primary photo
- Click star on non-primary to make it primary
- Delete (x) button in corner
- Drag handle for reordering

**No photo** state: Shows a message "No photos yet" with an upload button.

### 2.3 Logic Changes

**`adm/logic/admin_event_logic.php`:**
- Add `require_once` for `data/entity_photos_class.php`
- Load event photos: `$event_photos = $event->get_photos()`
- Add `event_photos` to page_vars
- Handle new POST action `set_primary_photo` -- calls `$event->set_primary_photo($post_vars['photo_id'])`
- Handle new POST action `clear_primary_photo` -- calls `$event->clear_primary_photo()`

### 2.4 AJAX Integration

The photo card uses the existing `ajax/entity_photos_ajax.php` endpoint for:
- **Upload**: `POST action=upload, entity_type=event, entity_id={evt_event_id}` + file
- **Delete**: `POST action=delete, entity_type=event, entity_id={evt_event_id}, photo_id={eph_entity_photo_id}`
- **Reorder**: `POST action=reorder, entity_type=event, entity_id={evt_event_id}, photo_ids[]={ordered ids}`

**Set primary** is NOT handled via the entity_photos AJAX (by design -- see pictures refactor spec section 2.2). Instead, it's a form POST to the event page itself, which calls `$event->set_primary_photo()`.

### 2.5 JavaScript

Inline JavaScript in the admin event view page (no separate JS file needed for MVP). Uses vanilla JS (no jQuery -- per codebase convention).

**Upload handling:**
- Hidden `<input type="file" accept="image/*">` triggered by Upload button click
- On file select, POST to `entity_photos_ajax.php` via `fetch()` with FormData
- On success, append new thumbnail to the grid
- Show error alert on failure (e.g., photo limit exceeded)

**Set primary:**
- Click star icon → submit hidden form with `action=set_primary_photo` and `photo_id`
- Page reloads with updated primary

**Delete:**
- Click X icon → `confirm()` dialog → POST to entity_photos_ajax.php
- On success, remove thumbnail from DOM

**Reorder (stretch goal for MVP):**
- HTML5 drag-and-drop on thumbnails
- On drop, POST new order to entity_photos_ajax.php reorder action
- Update DOM positions

### 2.6 Admin Event Edit Page

**No changes needed.** The existing `imageinput` dropdown for `evt_fil_file_id` stays as a quick way to select from existing images. The new photo manager on the view page is the primary way to manage event photos going forward.

The `evt_fil_file_id` FK stays in sync because `Event::set_primary_photo()` updates it automatically.

---

## 3. Implementation

### 3.1 Files to Modify

| File | Changes |
|------|---------|
| `adm/logic/admin_event_logic.php` | Add entity_photos require, load photos, handle set_primary_photo/clear_primary_photo POST actions |
| `adm/admin_event.php` | Replace "Event Image" card with "Event Photos" card, add inline JS for upload/delete/reorder |

### 3.2 Logic Changes Detail

**`adm/logic/admin_event_logic.php`** additions:

```php
// At top with other requires:
require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));

// In POST handling section (after existing actions):
if ($post_vars['action'] == 'set_primary_photo') {
    $event->set_primary_photo((int)$post_vars['photo_id']);
    $returnurl = $session->get_return();
    return LogicResult::redirect($returnurl);
}

if ($post_vars['action'] == 'clear_primary_photo') {
    $event->clear_primary_photo();
    $returnurl = $session->get_return();
    return LogicResult::redirect($returnurl);
}

// In page_vars assembly:
$event_photos = $event->get_photos();
// Add to page_vars array:
'event_photos' => $event_photos,
```

### 3.3 View Changes Detail

**`adm/admin_event.php`** -- Replace the "Event Image" card (lines 253-282) with:

```php
<!-- Event Photos Card -->
<div class="card">
    <div class="card-header bg-body-tertiary d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><span class="fas fa-images me-2"></span>Event Photos</h6>
        <?php if(!$event->get('evt_delete_time') && $_SESSION['permission'] > 7): ?>
        <button type="button" class="btn btn-falcon-primary btn-sm" id="btn-upload-photo">
            <span class="fas fa-plus me-1"></span>Upload
        </button>
        <input type="file" id="photo-upload-input" accept="image/*" style="display:none;">
        <?php endif; ?>
    </div>
    <div class="card-body">
        <div id="photo-grid" class="row g-2">
            <?php if(count($event_photos) == 0): ?>
                <div id="no-photos-msg" class="col-12 text-center text-muted py-4">
                    <span class="fas fa-image fa-3x mb-2 d-block"></span>
                    No photos yet
                </div>
            <?php endif; ?>
            <?php foreach($event_photos as $photo): ?>
                <?php $photo_file = new File($photo->get('eph_fil_file_id'), TRUE); ?>
                <div class="col-4 col-md-3" data-photo-id="<?php echo $photo->key; ?>">
                    <div class="position-relative">
                        <img src="<?php echo htmlspecialchars($photo_file->get_url('profile_card')); ?>"
                             class="img-fluid rounded" alt="">
                        <?php if($photo->get('eph_is_primary')): ?>
                            <span class="position-absolute top-0 start-0 m-1 text-warning" title="Primary photo">
                                <span class="fas fa-star"></span>
                            </span>
                        <?php else: ?>
                            <a href="#" class="position-absolute top-0 start-0 m-1 text-400 set-primary-btn"
                               data-photo-id="<?php echo $photo->key; ?>" title="Set as primary">
                                <span class="far fa-star"></span>
                            </a>
                        <?php endif; ?>
                        <?php if(!$event->get('evt_delete_time') && $_SESSION['permission'] > 7): ?>
                        <a href="#" class="position-absolute top-0 end-0 m-1 text-danger delete-photo-btn"
                           data-photo-id="<?php echo $photo->key; ?>" title="Remove photo">
                            <span class="fas fa-times-circle"></span>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
```

### 3.4 JavaScript Detail

Inline `<script>` block at end of admin_event.php (before `admin_footer()`):

- **Upload**: `btn-upload-photo` click triggers `photo-upload-input` click. On `change`, create FormData with `action=upload`, `entity_type=event`, `entity_id=<?php echo $event->key; ?>`, `file=...`. POST to `/ajax/entity_photos_ajax`. On success JSON, create new thumbnail div and append to `#photo-grid`. Remove `#no-photos-msg` if present.

- **Set primary**: Event delegation on `.set-primary-btn` click. Create and submit a hidden form with `action=set_primary_photo` and `photo_id`.

- **Delete**: Event delegation on `.delete-photo-btn` click. `confirm('Remove this photo?')`. POST to `/ajax/entity_photos_ajax` with `action=delete`. On success, remove the thumbnail div. If no photos remain, show `#no-photos-msg`.

---

## 4. Pictures Refactor Coverage

This feature exercises the following untested parts of the pictures refactor:

| Component | How It's Tested |
|-----------|----------------|
| `entity_photos_ajax.php` upload action | Photo upload from event page |
| `entity_photos_ajax.php` delete action | Photo delete from event page |
| `entity_photos_ajax.php` reorder action | Drag-to-reorder (stretch) |
| `Event::set_primary_photo()` | Star icon click |
| `Event::clear_primary_photo()` | (If last photo deleted) |
| `Event::get_photos()` | Loading photo grid |
| `EntityPhoto::save()` limit enforcement | Upload when at max_entity_photos limit |
| `File::resize()` via AJAX upload | New photos get all registered sizes |
| `eph_entity_photos` unique constraint | Prevents duplicate file association |

---

## 5. Not In Scope

- **Public-facing event photo gallery** -- this spec is admin-only
- **Caption editing** -- the AJAX endpoint supports it but no UI for MVP
- **Bulk upload** -- single file at a time for MVP
- **Lightbox/full-size preview** -- thumbnails link to nothing for MVP
- **Photo gallery component** -- reusable JS component is a separate spec
