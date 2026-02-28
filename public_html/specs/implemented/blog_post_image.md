# Blog Post Images

## Overview

Add image support to blog posts:
1. A main image field (`pst_fil_file_id`) following the same pattern as events
2. EntityPhoto support for multiple photos per post
3. PhotoHelper integration on the admin post view for uploading/managing photos
4. Public display via the `image_gallery` component (see [component_render_overrides.md](component_render_overrides.md))

## Reference Implementation

Events use this exact pattern:
- **Data class:** `data/events_class.php` — `evt_fil_file_id` field + `get_picture_link()` + `get_photos()` + `set_primary_photo()`
- **Admin edit:** `adm/admin_event_edit.php` lines 41-44 — `imageinput()` FormWriter call
- **Admin view:** `adm/admin_event.php` — PhotoHelper card for upload/reorder/delete
- **AJAX:** `ajax/entity_photos_ajax.php` — entity_class_map for auto-primary

## Changes

### 1. Data Model: `data/posts_class.php`

Add field to `$field_specifications`:
```php
'pst_fil_file_id' => array('type'=>'int4'),
```

Add to `$foreign_key_actions`:
```php
'pst_fil_file_id' => ['action' => 'null'],
```

Add methods (matching Event pattern):
- `get_picture_link($size_key)` — returns image URL via File class
- `get_photos()` — returns MultiEntityPhoto for entity_type 'post'
- `set_primary_photo($photo_id)` — sets pst_fil_file_id from EntityPhoto
- `clear_primary_photo()` — sets pst_fil_file_id to NULL

Update `permanent_delete()` to clean up entity photos.

### 2. Entity Photos AJAX: `ajax/entity_photos_ajax.php`

Add `'post'` to `$entity_class_map`:
```php
'post' => ['class' => 'Post', 'file' => 'data/posts_class.php'],
```

### 3. Admin Edit: `adm/admin_post_edit.php`

- Load `MultiFile(['deleted'=>false, 'picture'=>true])` for image dropdown
- Handle POST for `pst_fil_file_id` (set/clear)
- Add `$formwriter->imageinput('pst_fil_file_id', 'Main image', ...)` after title input

### 4. Admin View: `adm/admin_post.php`

- Add `set_primary_photo` and `clear_primary_photo` action handlers
- Add PhotoHelper `render_photo_card()` for photo management
- Add PhotoHelper `render_photo_scripts()` for upload/delete/reorder JS

### 5. Public Display: `image_gallery` Component

Use the new programmatic component rendering (see [component_render_overrides.md](component_render_overrides.md)) to display post images on the public post view:

```php
echo ComponentRenderer::render(null, 'image_gallery', [
    'photos' => $post->get_photos(),
    'primary_file_id' => $post->get('pst_fil_file_id'),
]);
```

## Files Modified

1. `data/posts_class.php` — new field, foreign key action, photo methods
2. `ajax/entity_photos_ajax.php` — add 'post' to entity_class_map
3. `adm/admin_post_edit.php` — load files, handle POST, add imageinput widget
4. `adm/admin_post.php` — PhotoHelper card, set/clear primary actions

## Notes

- No migration needed; the field is added via `$field_specifications` (automatic schema update)
- The `imageinput` FormWriter widget handles the image selection UI including preview
- The `image_gallery` component template is created as part of the component_render_overrides spec
