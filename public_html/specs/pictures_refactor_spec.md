# Pictures Refactor Spec: Multi-Photo Support & Image Sizing

**Purpose:** Replace the current single-photo-per-entity system with a general-purpose multi-photo gallery that works for any entity type (users, events, locations, products, etc.), and refactor image resizing to be theme-driven.

**Last Updated:** 2026-02-07

---

## 1. Current State

### What Exists

- **`fil_files` table** (`data/files_class.php`): General-purpose file model handling uploads, metadata, and access control. Fields include `fil_file_id`, `fil_name`, `fil_title`, `fil_description`, `fil_type`, `fil_usr_user_id`, `fil_gal_gallery_id`, `fil_min_permission`, `fil_grp_group_id`, `fil_evt_event_id`, timestamps.
- **`UploadHandler`** (`includes/UploadHandler.php`): jQuery File Upload handler supporting image uploads and resizing.
- **`fil_gal_gallery_id`** on `fil_files`: Existing gallery FK field, suggesting a gallery concept was planned but never fully built. No `Gallery` model class exists.

**Current single-image FK pattern:** Each entity that has an image stores a single FK to `fil_files`:

| Entity | Table | FK Column | Notes |
|--------|-------|-----------|-------|
| Users | `usr_users` | `usr_pic_picture_id` | Profile avatar |
| Events | `evt_events` | `evt_fil_file_id` | Event image |
| Locations | `loc_locations` | `loc_fil_file_id` | Location image |
| Mailing Lists | `mlt_mailing_lists` | `mlt_fil_file_id` | List image |
| Product Reqs | `prq_product_requirements` | `prq_fil_file_id` | Requirement file |

**Exception:** Event sessions already have a many-to-many join table (`esf_event_session_files`) linking sessions to multiple files. This is the closest existing pattern to what we're building generically.

### Image Resizing (Current)

`File::resize()` in `data/files_class.php` generates five hardcoded sizes on every upload using Imagick. Each size gets its own subdirectory under the upload directory:

| Name | Dimensions | Method | Used by |
|------|-----------|--------|---------|
| `thumbnail` | 80x80 | Center crop | Admin file list, image dropdowns, `image_list_ajax` |
| `lthumbnail` | 256x256 | Center crop | Event list cards (tailwind, canvas themes) |
| `small` | 500x300 | Aspect fit | Event cards (zoukroom, zoukphilly), location pictures |
| `medium` | 800x600 | Aspect fit | Event detail pages |
| `large` | 1200x1000 | Aspect fit | Event detail hero images, social preview (og:image) |

`File::get_url($size)` maps a size name to a subdirectory path. `File::delete_resized($size)` cleans up resized versions.

The `UploadHandler` (`includes/UploadHandler.php`) also has its own `image_versions` config with a separate `thumbnail` definition (80x80), but only generates that one thumbnail -- the rest of the resizing is done by `File::resize()`.

**Problems:**
1. **All sizes generated for every file** -- wasteful when a site only uses 2-3 sizes. A dating site needs different sizes (profile cards, swipe cards, chat avatars) than an event site.
2. **Hardcoded dimensions** -- can't adjust without editing `files_class.php`. Different themes have different layout needs.
3. **English name coupling** -- code uses strings like `'small'`, `'medium'`, `'large'` which don't describe the actual use case. A `'small'` at 500x300 might be too large or too small depending on the theme.
4. **Two separate resize systems** -- `UploadHandler` and `File::resize()` both generate thumbnails independently with overlapping names.
5. **No on-demand generation** -- if you add a new size later, existing files don't have it. You'd need a bulk re-resize script.

### Dead Code

- **`Picture` class**: Referenced in commented-out `User::picture()` method (`data/users_class.php` ~line 708) and commented-out picture export in `User::export_as_array()` (~line 437). The `Picture` class does not exist -- no `pictures_class.php` or similar file. These references should be removed.

---

## 2. Design

### 2.1 New Model: `entity_photos`

A polymorphic join table linking any entity type to files with gallery metadata (ordering, primary flag, captions). Same pattern as `user_likes` in the dating spec (entity_type + entity_id).

**Table: `eph_entity_photos`**
- `eph_entity_photo_id` (serial, primary key)
- `eph_entity_type` (varchar(50), not null) - 'user', 'event', 'location', 'product', 'mailing_list', etc.
- `eph_entity_id` (int4, not null) - FK to the entity's primary key
- `eph_fil_file_id` (int4, FK to `fil_files`, not null)
- `eph_sort_order` (int2, default 0) - Display ordering
- `eph_is_primary` (bool, default false) - Primary/featured photo
- `eph_caption` (varchar(255), nullable)
- `eph_create_time` (timestamp, default now())
- `eph_delete_time` (timestamp, nullable)

**Constraints:**
- Unique on `(eph_entity_type, eph_entity_id, eph_fil_file_id)` -- same file can't be added to the same entity twice
- Only one `eph_is_primary = TRUE` per entity (enforced in model logic, not DB constraint)
- Index on `(eph_entity_type, eph_entity_id)` for fast lookups

**Usage examples:**
```php
// Get all photos for an event
$photos = new MultiEntityPhoto(['entity_type' => 'event', 'entity_id' => $event_id], ['eph_sort_order' => 'ASC']);
$photos->load();

// Get primary photo for a user
$primary = new MultiEntityPhoto(['entity_type' => 'user', 'entity_id' => $user_id, 'is_primary' => true]);
$primary->load();
```

### 2.2 Relationship to Existing Single-Image FKs

The current single-FK columns (`usr_pic_picture_id`, `evt_fil_file_id`, etc.) stay as denormalized pointers to the primary photo's file ID. This avoids JOINs for the very common "show entity image" query.

**The EntityPhoto model does NOT know about entity tables.** It is a pure data record -- no `set_as_primary()` method, no FK sync, no knowledge of other tables. Setting the primary photo is the **entity's** operation, not the photo's.

**Pattern -- "set primary" lives on the entity:**
```php
// In User model:
function set_primary_photo($photo_id) {
    require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));

    // Clear old primary, set new primary in entity_photos
    $old_primaries = new MultiEntityPhoto(['entity_type' => 'user', 'entity_id' => $this->get_key(), 'is_primary' => true]);
    $old_primaries->load();
    foreach ($old_primaries as $old) {
        $old->set('eph_is_primary', false);
        $old->save();
    }

    $photo = new EntityPhoto($photo_id, TRUE);
    $photo->set('eph_is_primary', true);
    $photo->save();

    // Sync own FK -- entity knows its own column
    $this->set('usr_pic_picture_id', $photo->get('eph_fil_file_id'));
    $this->save();
}

// In Event model:
function set_primary_photo($photo_id) {
    // Same pattern, syncs evt_fil_file_id instead
}
```

The entity handles both the `eph_entity_photos` update and its own FK sync in one atomic method. No half-operations, no implicit contracts for callers to remember. EntityPhoto stays completely generic -- just a data record that any entity type can use with zero setup.

### 2.3 File Lifecycle

A single `fil_files` record can be linked to multiple entities via separate `eph_entity_photos` rows (e.g., a logo used by both an event and a location).

- **Soft-deleting an EntityPhoto** (`eph_delete_time`) removes that one entity/photo association. The underlying `fil_files` record and file on disk are untouched — other entities may still reference it.
- **Permanently deleting a File** (`File::permanent_delete()`) removes the file from disk, the `fil_files` record, and all `eph_entity_photos` rows referencing that file (via `$permanent_delete_actions`). This is the cleanup mechanism — orphaned entity_photos rows pointing to nonexistent files can't happen because the File model handles it.
- **EntityPhoto does not delete files.** It only manages the association. File deletion is always done at the `File` level.
- **Entity-side orphans** (entity_photos rows where the entity no longer exists) are harmless — they point to valid files but never get queried because no one looks up photos for a deleted entity. No special cleanup is needed.

### 2.4 Limits

- **Setting:** `max_entity_photos` (json, default `{"user": 6, "event": 10, "location": 10}`) - per-entity-type photo limits
- Enforced in model logic on save -- reject with a displayable error if limit exceeded
- Admin/superadmin bypass the limit

### 2.5 Image Size System Refactor

Replace the hardcoded size names and dimensions with a configurable, theme-driven system. Sites declare which sizes they need, and only those get generated.

#### 2.5.1 Size Registry

A central registry of image sizes, configured per-site via a combination of theme declaration and admin settings. Each size has:

- **Key** (string) -- a semantic name describing the use case, not the dimensions (e.g., `profile_card`, `avatar`, `hero`, not `small`, `medium`, `large`)
- **Width** (int) -- max width in pixels, 0 = auto from height
- **Height** (int) -- max height in pixels, 0 = auto from width
- **Crop** (bool) -- whether to center-crop to exact dimensions (true) or aspect-fit within bounds (false)
- **Quality** (int, default 85) -- JPEG quality

#### 2.5.2 Theme-Declared Sizes

Themes declare the sizes they need in `theme.json`. This is the primary way sizes are defined -- the theme knows what image dimensions its layouts require:

```json
{
  "name": "falcon",
  "image_sizes": {
    "avatar": { "width": 80, "height": 80, "crop": true },
    "profile_card": { "width": 400, "height": 500, "crop": true },
    "content": { "width": 800, "height": 0, "crop": false },
    "hero": { "width": 1200, "height": 0, "crop": false },
    "og_image": { "width": 1200, "height": 630, "crop": true }
  }
}
```

A dating-focused theme might declare:
```json
{
  "image_sizes": {
    "avatar": { "width": 64, "height": 64, "crop": true },
    "discover_card": { "width": 400, "height": 600, "crop": true },
    "profile_gallery": { "width": 800, "height": 0, "crop": false },
    "chat_thumbnail": { "width": 48, "height": 48, "crop": true },
    "og_image": { "width": 1200, "height": 630, "crop": true }
  }
}
```

#### 2.5.3 Size Resolution

The `ImageSizeRegistry` class (new, in `includes/`) merges sizes from:

1. **Falcon** (hardcoded as admin theme) -- always included, declares sizes needed by admin UI
2. **Active public theme** -- from `theme_template` setting, declares sizes needed by the public site
3. **Plugin-declared sizes** (plugins can register sizes via `PluginHelper`)

If the active public theme and Falcon declare the same key, the public theme wins.

#### 2.5.4 Theme Change Regeneration

When the active theme changes, the new theme likely declares different image sizes. Existing resized files won't match.

**On theme switch:**
1. Detect that `theme_template` setting has changed
2. Compare the old theme's `image_sizes` to the new theme's
3. Generate resized files for any new size keys
4. Delete size subdirectories that are no longer declared by any active theme or Falcon

**Implementation:** Add a hook in the theme switch flow (`ajax/theme_switch_ajax.php` or wherever the setting is updated) that triggers `File::regenerate_all_sizes()`. For small sites this runs inline; for large sites it could be a background script.

**Simpler MVP alternative:** Provide a utility script (`utils/regenerate_image_sizes.php`) that an admin runs after switching themes. The theme switch UI can display a notice: "Image sizes are being regenerated for the new theme."

```php
// Get all registered sizes
$sizes = ImageSizeRegistry::get_sizes();
// Returns: ['avatar' => ['width'=>80, 'height'=>80, 'crop'=>true], ...]

// Get a specific size
$size = ImageSizeRegistry::get_size('profile_card');

// Check if a size exists
ImageSizeRegistry::has_size('discover_card');
```

#### 2.5.5 Resize on Upload

When a file is uploaded, `File::resize()` is refactored to iterate over the registered sizes instead of the hardcoded list:

```php
function resize($size_key = 'all') {
    $sizes = ImageSizeRegistry::get_sizes();

    foreach ($sizes as $key => $config) {
        if ($size_key !== 'all' && $size_key !== $key) continue;

        // Generate resized version in subdirectory named by key
        $this->generate_resized($key, $config['width'], $config['height'], $config['crop'], $config['quality'] ?? 85);
    }
}
```

Subdirectories are created by key name (e.g., `uploads/avatar/`, `uploads/profile_card/`), same pattern as today.

#### 2.5.6 Lazy / On-Demand Generation (Post-MVP)

For MVP, sizes are generated eagerly on upload (same as today, just configurable). Post-MVP enhancement:

- `File::get_url($size_key)` checks if the resized file exists on disk
- If not, generates it on the fly, caches it, and returns the URL
- This handles the "added a new size, existing files don't have it" problem
- Also means theme switches don't require bulk re-processing

#### 2.5.7 Migration from Hardcoded Sizes

Update all existing callers to use the new semantic size keys. The old string names (`'thumbnail'`, `'small'`, etc.) are removed entirely -- no aliases or backward compatibility layer.

Callers to update:
- `data/files_class.php` -- `get_image_dropdown_array()` uses `'thumbnail'`
- `ajax/image_list_ajax.php` -- uses `'standard'` and `'thumbnail'`
- `views/event.php`, `views/events.php` -- uses `'medium'`, `'large'`, `'lthumbnail'`
- `theme/*/views/event*.php` -- various sizes per theme
- `logic/event_logic.php`, `logic/event_sessions_*_logic.php` -- uses `'small'`
- `adm/admin_file.php` -- displays all sizes

Each caller switches to the semantic key that matches its use case (e.g., `'lthumbnail'` on an event card becomes `'avatar'` or whatever the theme declares for that context).

#### 2.5.8 `File::get_url()` Refactor

Replace the hardcoded if/else chain with a registry lookup:

```php
function get_url($size_key = 'original', $format = 'short') {
    $settings = Globalvars::get_instance();
    $upload_web_dir = $settings->get_setting('upload_web_dir');

    if ($size_key === 'original') {
        $file_path = $upload_web_dir . '/' . $this->get('fil_name');
    } else {
        $file_path = $upload_web_dir . '/' . $size_key . '/' . $this->get('fil_name');
    }

    // ... rest of URL formatting
}
```

#### 2.5.9 UploadHandler Cleanup

Remove the `thumbnail` definition from `UploadHandler`'s `image_versions` config. All resizing goes through `File::resize()` using the registry. The `UploadHandler` handles the raw upload only; resizing is the `File` model's responsibility.

---

## 3. Implementation

### 3.1 Data Model

**New file:** `data/entity_photos_class.php`

```php
class EntityPhoto extends SystemBase {
    public static $prefix = 'eph';
    public static $tablename = 'eph_entity_photos';
    public static $pkey_column = 'eph_entity_photo_id';

    public static $field_specifications = array(
        'eph_entity_photo_id' => array('type'=>'int4', 'is_nullable'=>false, 'serial'=>true),
        'eph_entity_type' => array('type'=>'varchar(50)', 'is_nullable'=>false),
        'eph_entity_id' => array('type'=>'int4', 'is_nullable'=>false),
        'eph_fil_file_id' => array('type'=>'int4', 'is_nullable'=>false),
        'eph_sort_order' => array('type'=>'int2', 'default'=>0),
        'eph_is_primary' => array('type'=>'bool', 'default'=>false),
        'eph_caption' => array('type'=>'varchar(255)'),
        'eph_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'eph_delete_time' => array('type'=>'timestamp(6)'),
    );
    // ...
}

class MultiEntityPhoto extends SystemMultiBase {
    // Options: entity_type, entity_id, is_primary, deleted
    // Default sort: eph_sort_order ASC
}
```

**Key model methods:**
- `get_primary($entity_type, $entity_id)` -- static method, returns the EntityPhoto marked as primary for a given entity, or NULL if none
- `save()` override -- enforces per-entity-type photo limit, validates file exists

**Note:** EntityPhoto is a pure data record. There is no `set_as_primary()` and no auto-primary logic. Setting the primary photo is the entity's responsibility (see section 2.2) because the entity owns the FK sync.

### 3.2 AJAX Endpoints

**New file:** `ajax/entity_photos_ajax.php`

All actions take `entity_type` and `entity_id` parameters to identify the target entity.

- `POST /ajax/entity_photos_ajax` with `action` parameter:
  - `upload` -- Handle file upload + create `EntityPhoto` record. Returns the new EntityPhoto data.
  - `delete` -- Soft-delete an EntityPhoto row. Returns success.
  - `reorder` -- Accept array of photo IDs in new order, update `eph_sort_order`
  - `update_caption` -- Update caption text

**No `set_primary` action.** Setting the primary photo is an entity operation that includes FK sync (see section 2.2). Each entity's own page/endpoint calls `$entity->set_primary_photo()` directly. The gallery component JS accepts a callback or endpoint URL for this action from the embedding page.

**No entity loading.** The endpoint only manages `eph_entity_photos` rows. It does not load or know about entity models. This means no switch/case, no entity-type mapping, and plugins work without modification.

**Authentication:** Session user must be an admin or must match the file's `fil_usr_user_id` (the uploader). The endpoint does not need to load the entity for auth.

### 3.3 Gallery Component

The reusable photo gallery UI (grid display, drag-to-reorder, upload widget, primary selection) is specified separately in **`specs/photo_gallery_component_spec.md`**. This spec covers only the data model, AJAX endpoints, and image sizing system.

### 3.4 Entity Integration

Entities that want photo support don't need to register or configure anything. They just use EntityPhoto/MultiEntityPhoto with their entity_type string and primary key.

**Each entity that wants photos implements these methods:**

```php
// In User model (or any entity model):

function set_primary_photo($photo_id) {
    require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));

    // Clear old primary
    $old = new MultiEntityPhoto(['entity_type' => 'user', 'entity_id' => $this->get_key(), 'is_primary' => true]);
    $old->load();
    foreach ($old as $p) {
        $p->set('eph_is_primary', false);
        $p->save();
    }

    // Set new primary
    $photo = new EntityPhoto($photo_id, TRUE);
    $photo->set('eph_is_primary', true);
    $photo->save();

    // Sync own FK
    $this->set('usr_pic_picture_id', $photo->get('eph_fil_file_id'));
    $this->save();
}

function clear_primary_photo() {
    require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));

    $old = new MultiEntityPhoto(['entity_type' => 'user', 'entity_id' => $this->get_key(), 'is_primary' => true]);
    $old->load();
    foreach ($old as $p) {
        $p->set('eph_is_primary', false);
        $p->save();
    }

    $this->set('usr_pic_picture_id', NULL);
    $this->save();
}

function get_photos() {
    require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));
    $photos = new MultiEntityPhoto(
        ['entity_type' => 'user', 'entity_id' => $this->get_key()],
        ['eph_sort_order' => 'ASC']
    );
    $photos->load();
    return $photos;
}

function get_primary_photo() {
    require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));
    return EntityPhoto::get_primary('user', $this->get_key());
}

function get_picture_link($size_key = 'avatar') {
    $file_id = $this->get('usr_pic_picture_id');
    if (!$file_id) return '/img/default_avatar.png';
    $file = new File($file_id, TRUE);
    return $file->get_url($size_key);
}
```

These are simple methods each entity writes for itself. The only entity-specific part is the FK column name (`usr_pic_picture_id`, `evt_fil_file_id`, etc.) and the entity_type string. No registration, no interfaces, no base class traits. A new entity type (e.g., `Article`) just starts using `entity_type => 'article'` and implements the same pattern.

**No entity-side cleanup needed.** When an entity is permanently deleted, any remaining entity_photos rows are harmless orphans — they point to valid files but will never be queried. The real cleanup happens on `File::permanent_delete()`, which removes all entity_photos rows referencing that file (see section 2.3).

### 3.5 JSON / API Export

Entities include their photos in `get_json()` by calling `get_photos()` and mapping each to a simple array:

```php
// In User::get_json() or similar:
function get_json() {
    $json = parent::get_json();

    $photos = $this->get_photos();
    $json['photos'] = [];
    foreach ($photos as $photo) {
        $file = new File($photo->get('eph_fil_file_id'), TRUE);
        $json['photos'][] = [
            'photo_id' => $photo->get('eph_entity_photo_id'),
            'file_id' => $photo->get('eph_fil_file_id'),
            'is_primary' => (bool) $photo->get('eph_is_primary'),
            'sort_order' => (int) $photo->get('eph_sort_order'),
            'caption' => $photo->get('eph_caption'),
            'urls' => [
                'avatar' => $file->get_url('avatar'),
                'profile_card' => $file->get_url('profile_card'),
                'original' => $file->get_url('original'),
            ],
        ];
    }

    return $json;
}
```

Each entity chooses which size keys to include in `urls` based on what's relevant to its consumers. The dating API discovery endpoint would include `discover_card` and `profile_gallery`; an event API would include `content` and `hero`.

### 3.6 Dead Code Cleanup

- **`data/users_class.php`**: Remove commented-out `picture()` method (~line 708) and commented-out picture export lines in `export_as_array()` (~line 437)
- **`Picture` class references**: Search codebase for any other `Picture::` or `new Picture` references and remove

---

## 4. Directory Structure

```
includes/
  ImageSizeRegistry.php          # Size registry: merges Falcon + active theme + plugin sizes

data/
  entity_photos_class.php        # EntityPhoto + MultiEntityPhoto models

ajax/
  entity_photos_ajax.php         # AJAX endpoints for photo management (all entity types)
```

---

## 5. Settings

| Setting | Default | Description |
|---------|---------|-------------|
| `max_entity_photos` | `{"user": 6, "event": 10, "location": 10}` | Per-entity-type photo limits (JSON) |

---

## 6. Data Migration

Existing single-photo FK columns need to be migrated into `eph_entity_photos` so the new system has a complete picture. The table structure is created automatically by the data class, but the data migration is a file-based migration in `/migrations/`.

**Migration file:** `migrations/migrate_entity_photos.php`

```php
function migrate_entity_photos() {
    $dbconnector = DbConnector::get_instance();
    $dblink = $dbconnector->get_db_link();

    $entity_maps = [
        ['type' => 'user',         'table' => 'usr_users',          'pkey' => 'usr_user_id',          'fk' => 'usr_pic_picture_id',  'delete' => 'usr_delete_time'],
        ['type' => 'event',        'table' => 'evt_events',         'pkey' => 'evt_event_id',         'fk' => 'evt_fil_file_id',     'delete' => 'evt_delete_time'],
        ['type' => 'location',     'table' => 'loc_locations',      'pkey' => 'loc_location_id',      'fk' => 'loc_fil_file_id',     'delete' => 'loc_delete_time'],
        ['type' => 'mailing_list', 'table' => 'mlt_mailing_lists',  'pkey' => 'mlt_mailing_list_id',  'fk' => 'mlt_fil_file_id',     'delete' => 'mlt_delete_time'],
    ];

    foreach ($entity_maps as $map) {
        $sql = "INSERT INTO eph_entity_photos (eph_entity_type, eph_entity_id, eph_fil_file_id, eph_is_primary, eph_sort_order)
                SELECT :type, {$map['pkey']}, {$map['fk']}, true, 0
                FROM {$map['table']}
                WHERE {$map['fk']} IS NOT NULL AND {$map['delete']} IS NULL
                ON CONFLICT (eph_entity_type, eph_entity_id, eph_fil_file_id) DO NOTHING";
        $q = $dblink->prepare($sql);
        $q->execute(['type' => $map['type']]);
    }
}
```

**Migration entry in `migrations/migrations.php`:**

```php
$migration = array();
$migration['database_version'] = '0.XX';
$migration['test'] = "SELECT count(1) as count FROM eph_entity_photos";
$migration['migration_file'] = 'migrate_entity_photos.php';
$migration['migration_sql'] = NULL;
$migrations[] = $migration;
```

The test query returns count 0 when no rows exist yet (migration needs to run). After migration, count > 0 so it won't re-run. The existing FK columns are **not removed** — they stay as the denormalized primary photo pointer and continue to be read by existing code.

---

## 7. Documentation Updates

When this feature is implemented, update these existing docs and create one new one:

**Update:**

| Document | Changes |
|----------|---------|
| `CLAUDE.md` | Add EntityPhoto/MultiEntityPhoto to model examples. Update File class method descriptions (`resize()` now theme-driven, `get_url()` uses size keys). Mention ImageSizeRegistry. Add entity photo integration pattern to "Adding New Features" section. |
| `docs/plugin_developer_guide.md` | Add how plugins use entity_photos for their own entity types (just pass entity_type string, no setup needed). Document `image_sizes` declaration in `theme.json`. |
| `docs/deletion_system.md` | Add `File::permanent_delete()` → automatic cleanup of all `eph_entity_photos` rows referencing the file. |

**Create:**

| Document | Contents |
|----------|----------|
| `docs/entity_photos_system.md` | Developer guide for adding photo support to any entity. Covers: EntityPhoto/MultiEntityPhoto model, entity integration pattern (set_primary_photo, get_photos, get_picture_link convenience methods), AJAX endpoint usage, ImageSizeRegistry and theme.json image_sizes, file lifecycle and cleanup. |

---

## 8. Open Questions

1. **Photo moderation:** Deferred to post-MVP. Will add `eph_is_approved` flag with admin approval queue later.

2. ~~**Gallery FK cleanup:**~~ **Decided:** Remove the unused `fil_gal_gallery_id` column from `fil_files`. The entity_photos system replaces whatever gallery concept it was meant for.

3. ~~**Event session files migration:**~~ **Decided:** Leave `esf_event_session_files` as-is. It serves a different purpose (attaching downloadable course materials like PDFs and documents to event sessions) and benefits from real FK constraints that the polymorphic entity_photos table can't provide.
