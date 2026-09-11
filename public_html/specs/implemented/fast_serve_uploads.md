# Fast Serve Uploads Specification

## Overview

Move uploaded files with no permission restrictions to `/static_files/uploads/` to enable faster serving. The system provides three tiers of file serving speed, with the application automatically using the middle tier and sysadmins optionally using the fastest tier for critical assets.

## Problem

All uploaded files are currently served through a custom route in `serve.php` that:
1. Loads the full PHP bootstrap (PathHelper, Globalvars, SessionControl, DbConnector) — ~15ms
2. Queries the database to load the File model — ~1ms
3. Runs `authenticate_read()` — ~0.004ms
4. Calls `readfile()` to serve the file

99.8% of uploaded files have no permission restrictions (`fil_min_permission`, `fil_grp_group_id`, and `fil_evt_event_id` are all NULL). The authentication check returns `true` after spending ~20ms on overhead.

## Three-Tier Serving Model

### Tier 1: Apache Direct (~0.1ms)
- **URL:** `/static_files/uploads/photo.jpg`
- **How:** Apache Alias serves the file directly. PHP never runs.
- **When:** Sysadmin manually uses this URL for high-traffic assets (hero images, logos, etc.)
- **Safety net:** If the file moves back to `/uploads/` (permissions added), a `RewriteRule` in `.htaccess` redirects (302) to the `/uploads/` route, preserving the full subpath. Served via Tier 3. No broken URLs.

### Tier 2: Pre-Bootstrap Fast Path (~1.5ms)
- **URL:** `/uploads/photo.jpg` (standard URL from `get_url()`)
- **How:** RouteHelper checks for the file in `static_files/uploads/` *before* loading the PHP bootstrap. If found, serves it with `readfile()` and exits. No session, no DB, no auth.
- **When:** Automatic for all public files that have been moved to `static_files/uploads/`.
- **Safe:** URL always works. If the file moves back to `/uploads/`, it falls through to Tier 3.

### Tier 3: Full PHP Auth (~20ms)
- **URL:** `/uploads/photo.jpg`
- **How:** Full PHP bootstrap loads, File model queried from DB, `authenticate_read()` runs.
- **When:** Files with permission restrictions (`fil_min_permission`, `fil_grp_group_id`, or `fil_evt_event_id` set), or public files that haven't been moved yet.

**Key design rule:** `get_url()` always returns `/uploads/...` — never `/static_files/uploads/...`. The Tier 1 direct URL is only used by sysadmins manually.

## Directory Structure

Two upload locations:
- **`/static_files/uploads/`** (relative to project root) — Public files (no permissions). Enables Tier 1 and Tier 2 serving.
- **`/uploads/`** (the existing `upload_dir`) — Restricted files (have permissions). Served via Tier 3.

Both directories maintain the same internal structure:
```
uploads/
  photo.jpg              (original)
  thumb/photo.jpg         (resized)
  medium/photo.jpg        (resized)
  profile_card/photo.jpg  (resized)
  avatar/photo.jpg        (resized)
  content/photo.jpg       (resized)
```

### Permission Logic

A file is "public" when ALL of these are true:
- `fil_min_permission` is NULL (or 0)
- `fil_grp_group_id` is NULL
- `fil_evt_event_id` is NULL
- `fil_delete_time` is NULL

Public files live in `/static_files/uploads/`. All others live in `/uploads/`.

### File Lifecycle

1. **Upload**: File is initially uploaded to `/uploads/` (no change to UploadHandler)
2. **Save**: `File::save()` evaluates permissions and moves the file to the correct directory (including resized versions)
3. **Permission change**: On subsequent `save()`, file moves between directories as needed
4. **Soft delete**: File moves to `/uploads/` (restricted) since deleted files should not be publicly accessible
5. **Undelete**: File re-evaluates and moves to appropriate directory
6. **Permanent delete**: File is deleted from whichever directory it currently lives in

### Backward Compatibility

No feature flag or new settings required. The fast-serve directory path is computed from the existing `upload_dir` setting. Code always checks both directories defensively.

- **On deploy**: All files in `/uploads/`. `get_url()` returns `/uploads/...`. Everything works as before.
- **As files are re-saved**: Public files move to `/static_files/uploads/`. `get_url()` still returns `/uploads/...`. Tier 2 fast path kicks in automatically.
- **New uploads**: `save()` creates the directory and moves them automatically.

---

## Implementation Details

### 1. File Model Changes

**File:** `data/files_class.php`

No new settings or Globalvars changes. The fast-serve directory is derived from the existing `upload_dir` setting.

#### 1a. New static method: `get_fast_serve_dir()`

Computes the fast-serve directory path from the existing `upload_dir` setting. The `upload_dir` points to the project's `uploads/` directory (e.g., `/var/www/html/joinerytest/uploads`). The fast-serve directory is always `static_files/uploads` at the same level.

```php
private static function get_fast_serve_dir() {
    $settings = Globalvars::get_instance();
    return dirname($settings->get_setting('upload_dir')) . '/static_files/uploads';
}
```

#### 1b. New method: `is_public()`

Determines whether the file should be in the public (fast-serve) directory.

```php
function is_public() {
    if ($this->get('fil_delete_time')) return false;
    if ($this->get('fil_min_permission')) return false;
    if ($this->get('fil_grp_group_id')) return false;
    if ($this->get('fil_evt_event_id')) return false;
    return true;
}
```

#### 1c. New method: `get_filesystem_path($size_key = 'original')`

Returns the actual filesystem path, checking both directories. This is the primary method all code should use to locate a file on disk. Checks the fast-serve directory first since most files will be public.

```php
function get_filesystem_path($size_key = 'original') {
    $settings = Globalvars::get_instance();
    $filename = $this->get('fil_name');

    $dirs = [
        self::get_fast_serve_dir(),
        $settings->get_setting('upload_dir')
    ];

    foreach ($dirs as $dir) {
        if ($size_key === 'original') {
            $path = $dir . '/' . $filename;
        } else {
            $path = $dir . '/' . $size_key . '/' . $filename;
        }
        if (file_exists($path)) {
            return $path;
        }
    }

    // Fallback: return expected path in normal upload_dir
    $fallback_dir = $settings->get_setting('upload_dir');
    if ($size_key === 'original') {
        return $fallback_dir . '/' . $filename;
    }
    return $fallback_dir . '/' . $size_key . '/' . $filename;
}
```

#### 1d. New method: `move_to_correct_directory()`

Moves the file (and all resized versions) to the correct directory based on current permissions. Called by `save()`.

```php
function move_to_correct_directory() {
    $settings = Globalvars::get_instance();
    $filename = $this->get('fil_name');

    $fast_dir = self::get_fast_serve_dir();
    $normal_dir = $settings->get_setting('upload_dir');

    $in_fast = file_exists($fast_dir . '/' . $filename);
    $in_normal = file_exists($normal_dir . '/' . $filename);

    // Safety check: if file exists in BOTH directories, there are duplicate
    // filenames across different records. Do not move — this would cause data loss.
    if ($in_fast && $in_normal) {
        throw new FileException("Cannot move file '$filename': duplicate filename exists in both upload directories.");
    }

    // Determine target based on permissions
    $target_dir = $this->is_public() ? $fast_dir : $normal_dir;

    // Determine source directory (where file actually is)
    $source_dir = null;
    if ($in_fast) {
        $source_dir = $fast_dir;
    } elseif ($in_normal) {
        $source_dir = $normal_dir;
    }

    if (!$source_dir || $source_dir === $target_dir) {
        return; // Already in correct location or file not found
    }

    // Ensure .htaccess exists in fast-serve directory for Tier 1 fallback
    if ($target_dir === $fast_dir) {
        $htaccess_path = $fast_dir . '/.htaccess';
        if (!file_exists($htaccess_path)) {
            if (!is_dir($fast_dir)) {
                mkdir($fast_dir, 0777, true);
            }
            file_put_contents($htaccess_path, "RewriteEngine On\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteRule ^(.*)$ /uploads/\$1 [R=302,L]\n");
        }
    }

    // Move original file
    if (!$this->move_single_file($source_dir, $target_dir, $filename)) {
        return; // Original failed to move, don't move resized versions
    }

    // Move all resized versions
    if ($this->is_image()) {
        require_once(PathHelper::getIncludePath('includes/ImageSizeRegistry.php'));
        $sizes = ImageSizeRegistry::get_sizes();
        foreach ($sizes as $key => $config) {
            $this->move_single_file(
                $source_dir . '/' . $key,
                $target_dir . '/' . $key,
                $filename
            );
        }
    }
}

private function move_single_file($source_dir, $target_dir, $filename) {
    $source = $source_dir . '/' . $filename;
    $target = $target_dir . '/' . $filename;

    if (!file_exists($source)) return true; // Nothing to move, not an error

    // Don't overwrite an existing file at the target
    if (file_exists($target)) {
        throw new FileException("Cannot move file '$filename': file already exists at target '$target'.");
    }

    // Ensure target directory exists
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0777, true);
    }

    if (!rename($source, $target)) {
        throw new FileException("Failed to move file '$filename' from '$source' to '$target'.");
    }

    return true;
}
```

#### 1e. Modify `save()`

Add `move_to_correct_directory()` call after parent save:

```php
function save($debug = false) {
    $result = parent::save($debug);
    $this->move_to_correct_directory();
    return $result;
}
```

**Current save location:** Inherited from SystemBase. Override in File class.

#### 1f. Modify `get_url()` (lines 106-127)

`get_url()` always returns `/uploads/...` — never a `static_files` URL. This ensures URLs are stable and always work via the PHP route regardless of which directory the file is in.

```php
function get_url($size_key='original', $format='short') {
    $settings = Globalvars::get_instance();
    $upload_web_dir = $settings->get_setting('upload_web_dir');

    if ($size_key === 'original') {
        $file_path = $upload_web_dir . '/' . $this->get('fil_name');
    } else {
        $file_path = $upload_web_dir . '/' . $size_key . '/' . $this->get('fil_name');
    }

    // Ensure leading slash
    if ($file_path[0] !== '/') {
        $file_path = '/' . $file_path;
    }

    if ($format == 'full') {
        return LibraryFunctions::get_absolute_url($file_path);
    } else {
        return $file_path;
    }
}
```

**Note:** This is essentially unchanged from the current implementation. The `get_url()` method does not need to know about the fast-serve directory.

#### 1g. Modify `permanent_delete()` (lines 129-155)

Use `get_filesystem_path()` instead of hardcoded `upload_dir`:

```php
function permanent_delete($debug=false) {
    $file_path = $this->get_filesystem_path('original');
    if (file_exists($file_path)) {
        @unlink($file_path);
    }

    $this->delete_resized();

    // Clean up all entity_photos rows referencing this file
    // ... existing entity_photos cleanup unchanged ...

    parent::permanent_delete($debug);
    return true;
}
```

#### 1h. Modify `delete_resized()` (lines 162-182)

Use `get_filesystem_path($key)` for each size:

```php
function delete_resized($size_key = 'all') {
    if (!$this->is_image()) {
        return false;
    }

    require_once(PathHelper::getIncludePath('includes/ImageSizeRegistry.php'));
    $sizes = ImageSizeRegistry::get_sizes();

    foreach ($sizes as $key => $config) {
        if ($size_key !== 'all' && $size_key !== $key) continue;
        $file_path = $this->get_filesystem_path($key);
        if (file_exists($file_path)) {
            @unlink($file_path);
        }
    }
}
```

#### 1i. Modify `resize()` (lines ~189-223)

**Critical**: The original and its resized versions must always live in the same directory tree. Use `get_filesystem_path()` to find the original, then derive the output directory from its actual location (not from permissions — the file may not have moved yet during initial upload).

```php
function resize($size_key = 'all') {
    if (!$this->is_image()) {
        return false;
    }

    $old_path = $this->get_filesystem_path('original');
    if (!file_exists($old_path)) {
        return false;
    }

    // Derive the base directory from where the original actually lives
    $upload_dir = dirname($old_path);

    require_once(PathHelper::getIncludePath('includes/ImageSizeRegistry.php'));
    $sizes = ImageSizeRegistry::get_sizes();

    // Ensure all resize subdirectories exist
    foreach ($sizes as $key => $config) {
        if ($size_key !== 'all' && $size_key !== $key) continue;
        $dir_path = $upload_dir . '/' . $key;
        if (!is_dir($dir_path)) {
            if (mkdir($dir_path, 0777, true)) {
                chmod($dir_path, 0777);
            }
        }
    }

    foreach ($sizes as $key => $config) {
        if ($size_key !== 'all' && $size_key !== $key) continue;
        $new_path = $upload_dir . '/' . $key . '/' . $this->get('fil_name');
        $this->generate_resized($old_path, $new_path, $config['width'], $config['height'], $config['crop'], $config['quality']);
    }
}
```

**Timing note**: In the upload flow, `save()` is called *before* `resize()`. So `save()` moves the original to `static_files/uploads/`, then `resize()` finds it there via `get_filesystem_path()` and creates resized versions alongside it. This is correct — resize always operates on the file's current location.

#### 1j. Modify `soft_delete()`

Override to call `move_to_correct_directory()` after parent, since `is_public()` returns false for deleted files:

```php
function soft_delete() {
    $result = parent::soft_delete();
    $this->move_to_correct_directory();
    return $result;
}
```

#### 1k. Modify `undelete()`

Override to call `move_to_correct_directory()` after parent:

```php
function undelete() {
    $result = parent::undelete();
    $this->move_to_correct_directory();
    return $result;
}
```

### 2. Route Changes

#### 2a. Pre-Bootstrap Fast Path (Tier 2)

**File:** `includes/RouteHelper.php` — in `processRoutes()`, after the static route check (line ~1077) but before loading core dependencies (line ~1080)

Add an uploads-specific check that serves public files without loading the bootstrap:

```php
// STEP 1.5: Fast-serve check for uploads
// If the file exists in static_files/uploads/, serve it without loading dependencies.
// The file's presence there means it has no permission restrictions.
if (strpos($full_path, '/uploads/') === 0) {
    $base_path = dirname(dirname(__DIR__));  // project root (parent of public_html)
    $fast_dir = $base_path . '/static_files/uploads';
    $fast_path = $fast_dir . substr($full_path, 8);  // strip '/uploads' prefix

    // Security: verify resolved path is within the fast-serve directory
    $real_path = realpath($fast_path);
    $real_dir = realpath($fast_dir);
    if ($real_path && $real_dir && strpos($real_path, $real_dir . '/') === 0) {
        self::serveStaticFile($real_path, 43200);
        exit();
    }
}
```

This maps `/uploads/photo.jpg` → checks `static_files/uploads/photo.jpg`. If found, serves it at ~1.5ms. If not found, falls through to the full bootstrap and Tier 3 handling.

Also handles resized versions: `/uploads/thumb/photo.jpg` → `static_files/uploads/thumb/photo.jpg`.

#### 2b. Full Auth Route (Tier 3)

**File:** `serve.php` (lines 237-261)

The existing `/uploads/*` custom route handles files that didn't match the fast path (Tier 2). These are restricted files in `/uploads/`. No changes needed to this route — it already works correctly for files in `/uploads/`.

However, as a defensive measure, also check `static_files/uploads/` in case a file is there but somehow missed the fast path (e.g., the fast path code wasn't deployed yet on an older RouteHelper):

```php
'/uploads/*' => function($params, $settings, $session) {
    if(!$settings->get_setting('files_active')) return false;

    $upload_dir = $settings->get_setting('upload_dir');
    $fast_dir = dirname($upload_dir) . '/static_files/uploads';
    $subpath_parts = array_slice($params, 2);
    $subpath = implode('/', $subpath_parts);

    // Check both directories for the file
    $file = null;
    if (file_exists($upload_dir . '/' . $subpath)) {
        $file = $upload_dir . '/' . $subpath;
    } elseif (file_exists($fast_dir . '/' . $subpath)) {
        $file = $fast_dir . '/' . $subpath;
    }

    if ($file) {
        require_once(PathHelper::getIncludePath('data/files_class.php'));
        $file_obj = File::get_by_name(basename($file));

        if ($file_obj && $file_obj->authenticate_read(array('session'=>$session))) {
            RouteHelper::serveStaticFile($file, 43200);
            return true;
        } else {
            require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
            LibraryFunctions::display_404_page();
            return true;
        }
    }

    return false;
},
```

### 3. Admin File Hardcoded Paths

Several admin files use hardcoded `/uploads/` in URLs. These should use `$file->get_url()` instead for consistency (though with `get_url()` always returning `/uploads/...`, these would still work — this is a code quality improvement).

**File:** `adm/admin_file.php`
- Line 37: `<img src="/uploads/content/..."` — Change to `$file->get_url('content')`
- Line 112: `<a href="/uploads/..."` — Change to `$file->get_url('original')`
- Line 113: `<code>/uploads/...</code>` — Change to `$file->get_url('original')`

**File:** `adm/admin_files.php`
- Line 81: `<img ... src="/uploads/avatar/..."` — Change to `$file->get_url('avatar')`

**File:** `adm/admin_file_delete.php`
- Line 31: `<img src="/uploads/profile_card/..."` — Change to `$file->get_url('profile_card')`

**File:** `adm/admin_location.php`
- Line 150: `<img src="...get_absolute_url('/uploads/content/...')"` — Change to `$file->get_url('content', 'full')`

### 4. Upload Processing

**File:** `adm/logic/admin_file_upload_process_logic.php`
- Lines 187-197: File renaming uses `$upload_dir` directly. After rename, the subsequent `$file->save()` call will handle moving to the correct directory. No changes needed here since initial upload always goes to `/uploads/` and `save()` moves it.

**File:** `ajax/entity_photos_ajax.php`
- Lines 77-83: Direct `move_uploaded_file()` to `$upload_dir`. This is the initial upload — file goes to `/uploads/` first, then `$file->save()` moves it. No changes needed.

**File:** `includes/UploadHandler.php`
- Uses `$settings->get_setting('upload_dir')` for initial upload destination. No changes needed — initial upload always goes to the standard upload directory, and `File::save()` moves it afterward.

### 5. Utility Scripts

**File:** `utils/regenerate_image_sizes.php`
- Lines 58-68: Creates size subdirectories using hardcoded `upload_dir`
- Line 104: Builds source path using hardcoded `upload_dir`
- **Needs update**:
  - Remove hardcoded directory creation (let `File::resize()` handle subdirectory creation since it now derives the base directory from the file's actual location)
  - Replace `$source_path = $upload_dir . '/' . $file_name` (line 104) with `$file->get_filesystem_path('original')`
  - The `$file->resize('all')` call (line 115) already works correctly after the resize() changes above

### 6. Apache Config and .htaccess

#### 6a. Apache config change

**File:** `maintenance_scripts/install_tools/default_virtualhost.conf` (line 36)

Change the `static_files` directory block to allow `.htaccess` overrides:

```apache
Alias /static_files /var/www/html/joinerytest/static_files
<Directory "/var/www/html/joinerytest/static_files">
    Options -Indexes
    AllowOverride FileInfo
    Require all granted
</Directory>
```

Changed `AllowOverride None` → `AllowOverride FileInfo` to allow `FallbackResource` in `.htaccess`.

#### 6b. Redirect .htaccess

**File:** `/static_files/uploads/.htaccess` (new, created by `move_single_file()` when it first creates the directory, or by install script)

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^(.*)$ /uploads/$1 [R=302,L]
```

When a file is not found in `static_files/uploads/`, Apache redirects to the equivalent `/uploads/` URL (preserving the full subpath). This hits serve.php and the normal Tier 3 PHP route. This makes Tier 1 URLs safe — if a file moves back to `/uploads/` (permissions added), the URL still works via redirect.

The redirect is a 302 (temporary) since the file may move back to `static_files/uploads/` later. The extra round trip only occurs in the fallback case (file moved away), not during normal Tier 1 serving.

### 7. Directory Creation

No migration or manual setup required. The `move_single_file()` method creates subdirectories on demand via `mkdir($target_dir, 0777, true)`. The `move_to_correct_directory()` method creates the top-level `static_files/uploads/` directory and writes the `.htaccess` file when first needed.

Existing files move to the fast-serve directory naturally as they are re-saved. Until then, they remain in `/uploads/` and are served via Tier 3 (full auth). As files move, they automatically upgrade to Tier 2 (pre-bootstrap fast path).

---

## Performance Impact

| Tier | URL Pattern | TTFB | When Used |
|------|------------|------|-----------|
| 1. Apache Direct | `/static_files/uploads/...` | ~0.1ms | Sysadmin manual URL for critical assets |
| 2. Pre-Bootstrap Fast | `/uploads/...` (file in static_files) | ~1.5ms | Automatic for public files after `save()` |
| 3. Full PHP Auth | `/uploads/...` (file in uploads) | ~20ms | Restricted files, or public files not yet re-saved |

| Metric | Before | After |
|--------|--------|-------|
| Public file TTFB | ~20ms | ~1.5ms (Tier 2, automatic) |
| Restricted file TTFB | ~20ms | ~20ms (unchanged) |
| Speedup for public files | — | **~13x** |
| Page with 10 public images | ~200ms server time | ~15ms server time |

## Files Changed Summary

| File | Change Type |
|------|------------|
| `data/files_class.php` | Major — new methods, modified existing methods |
| `includes/RouteHelper.php` | Minor — add pre-bootstrap uploads fast path |
| `serve.php` | Minor — check both directories in uploads route |
| `adm/admin_file.php` | Minor — replace hardcoded paths with `get_url()` |
| `adm/admin_files.php` | Minor — replace hardcoded path with `get_url()` |
| `adm/admin_file_delete.php` | Minor — replace hardcoded path with `get_url()` |
| `adm/admin_location.php` | Minor — replace hardcoded path with `get_url()` |
| `utils/regenerate_image_sizes.php` | Moderate — use `get_filesystem_path()` |
| `maintenance_scripts/install_tools/default_virtualhost.conf` | Minor — `AllowOverride FileInfo` on static_files directory |

**New files:**
| File | Description |
|------|------------|
| `static_files/uploads/.htaccess` | RewriteRule to redirect missing files to `/uploads/` — created automatically by code |

**No changes needed:**
- `includes/Globalvars.php` — no new settings
- `includes/UploadHandler.php` — initial uploads always go to `/uploads/`
- `adm/logic/admin_file_upload_process_logic.php` — `save()` handles move
- `ajax/entity_photos_ajax.php` — `save()` handles move
- `includes/PhotoHelper.php` — already uses `get_url()`
- `data/events_class.php` — already uses `get_url()`
- `includes/ImageSizeRegistry.php` — no path logic

## Edge Cases

1. **File renamed after upload**: `admin_file_upload_process_logic.php` renames files in `/uploads/`, then calls `save()` which moves to the correct directory. Works correctly.
2. **Resized images don't exist yet**: `move_to_correct_directory()` only moves files that exist. Lazy-generated resized images will be created in the correct directory because `resize()` derives the base directory from the original's actual location.
3. **Race condition**: If a request hits the Tier 2 fast path while `save()` is moving the file, `rename()` is atomic on the same filesystem. Both directories are on the same filesystem, so this is safe.
4. **File not found in either directory**: `get_filesystem_path()` falls back to the expected path in normal `upload_dir`, which matches existing behavior.
5. **`/uploads/` URL for public file in fast-serve dir**: Tier 2 pre-bootstrap check catches it at ~1.5ms. If Tier 2 code isn't deployed yet, Tier 3 serve.php route catches it defensively.
6. **`static_files/uploads/` directory doesn't exist yet**: `move_single_file()` creates it via `mkdir($target_dir, 0777, true)`. No manual setup required.
7. **Existing files not yet re-saved**: Remain in `/uploads/` and served via Tier 3. Move to fast-serve (Tier 2) on next `save()`.
8. **Sysadmin Tier 1 URL after file moves back**: If a sysadmin used `/static_files/uploads/photo.jpg` and the file moved back to `/uploads/` (permissions added), the `.htaccess` `RewriteRule` redirects (302) to `/uploads/photo.jpg`, which serves it via Tier 3 with full auth. One extra round trip, but no broken URLs.
9. **Duplicate filenames**: If two File records share the same `fil_name`, `move_to_correct_directory()` detects the duplicate (file exists in both directories) and refuses to move, logging an error. `move_single_file()` also refuses to overwrite an existing file at the target. No data loss.
