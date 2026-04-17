# Migration Specification: Move /adm/includes to /assets

## Overview
Migrate all vendor and system assets from `/adm/includes/` to a new `/assets/` directory structure following the same pattern as plugin and theme assets. This will resolve the current 500 errors for Trumbowyg and other admin assets by establishing a proper static file serving route.

## Current Problem
- Admin assets in `/adm/includes/` are not being served correctly
- The routing system treats these paths as dynamic views instead of static files
- This causes 500 errors for JavaScript, CSS, and image files

## Proposed Solution

### New Directory Structure
```
/var/www/html/joinerytest/public_html/
├── assets/                    # NEW: Global system assets (matching plugin structure)
│   ├── js/                    # JavaScript files
│   │   └── jquery.validate-1.9.1.js
│   ├── css/                   # CSS files (if any global CSS needed)
│   ├── images/                # System images
│   │   ├── image_placeholder_thumbnail.png
│   │   ├── pdf_icon_80px.png
│   │   ├── microsoft_word_icon_80px.png
│   │   └── excel_icon_80px.png
│   └── vendor/                # Third-party libraries
│       ├── Trumbowyg-2-26/
│       ├── jquery-timepicker-1.3.5/
│       └── uikit-3.6.14/
├── plugins/
│   └── {plugin}/
│       └── assets/           # Plugin-specific assets (existing)
│           ├── js/
│           ├── css/
│           ├── images/
│           └── fonts/
└── theme/
    └── {theme}/
        └── assets/           # Theme-specific assets (existing)
```

### Routing Configuration

#### Add to serve.php (line ~102):
```php
'static' => [
    '/assets/*' => ['cache' => 43200],  // NEW: Global assets route
    '/plugins/{plugin}/assets/*' => ['cache' => 43200],
    '/theme/{theme}/assets/*' => ['cache' => 43200],
    '/static_files/*' => ['cache' => 43200, 'exclude_from_cache' => ['.upg.zip']],
    '/favicon.ico' => ['cache' => 43200],
],
```

## Migration Tasks

### 1. File System Changes

#### Move vendor libraries:
- `/adm/includes/Trumbowyg-2-26/` → `/assets/vendor/Trumbowyg-2-26/`
- `/adm/includes/jquery-timepicker-1.3.5/` → `/assets/vendor/jquery-timepicker-1.3.5/`
- `/adm/includes/uikit-3.6.14/` → `/assets/vendor/uikit-3.6.14/`

#### Move system JavaScript:
- `/adm/includes/scripts/` → `/assets/js/`

#### Move system images:
- `/adm/includes/images/` → `/assets/images/`

### 2. Code Updates Required

#### FormWriterHTML5.php (3 locations):
- **Lines 333-337:** Update Trumbowyg paths
  ```php
  // OLD:
  <script src="/adm/includes/Trumbowyg-2-26/dist/trumbowyg.min.js"></script>
  // NEW:
  <script src="/assets/vendor/Trumbowyg-2-26/dist/trumbowyg.min.js"></script>
  ```

- **Lines 647-648:** Update timepicker paths
  ```php
  // OLD:
  <link rel="stylesheet" href="/adm/includes/jquery-timepicker-1.3.5/jquery.timepicker.min.css"/>
  // NEW:
  <link rel="stylesheet" href="/assets/vendor/jquery-timepicker-1.3.5/jquery.timepicker.min.css"/>
  ```

- **Lines 915, 918:** Update placeholder image paths
  ```php
  // OLD:
  src="/adm/includes/images/image_placeholder_thumbnail.png"
  // NEW:
  src="/assets/images/image_placeholder_thumbnail.png"
  ```

#### FormWriterBootstrap.php (3 locations):
- **Lines 337-341:** Update Trumbowyg paths
- **Lines 651-652:** Update timepicker paths
- **Lines 919, 922:** Update placeholder image paths

#### FormWriterUIKit.php (3 locations):
- **Lines 189-193:** Update Trumbowyg paths
- **Lines 421-422:** Update timepicker paths
- **Lines 610, 613:** Update placeholder image paths

#### AdminPage-uikit3.php (4 locations):
- **Lines 70-71:** Update UIKit CSS paths
  ```php
  // OLD:
  href="/adm/includes/uikit-3.6.14/css/uikit.min.css"
  // NEW:
  href="/assets/vendor/uikit-3.6.14/css/uikit.min.css"
  ```

- **Line 79:** Update jQuery validate path
  ```php
  // OLD:
  src="/adm/includes/scripts/jquery.validate-1.9.1.js"
  // NEW:
  src="/assets/js/jquery.validate-1.9.1.js"
  ```

- **Lines 91-92:** Update UIKit CSS paths (duplicate section)
- **Line 100:** Update jQuery validate path (duplicate section)
- **Lines 486-487:** Update UIKit JS paths
  ```php
  // OLD:
  src="/adm/includes/uikit-3.6.14/js/uikit.min.js"
  // NEW:
  src="/assets/vendor/uikit-3.6.14/js/uikit.min.js"
  ```

#### admin_files.php (3 locations):
- **Line 86:** Update PDF icon path
  ```php
  // OLD:
  src="/adm/includes/images/pdf_icon_80px.png"
  // NEW:
  src="/assets/images/pdf_icon_80px.png"
  ```

- **Line 89:** Update Word icon path
- **Line 92:** Update Excel icon path

### 3. Update serve.php
- Add `/assets/*` static route at line ~102 (see Routing Configuration above)

## Implementation Steps

1. **Create new directory structure:**
   ```bash
   mkdir -p /var/www/html/joinerytest/public_html/assets/vendor
   mkdir -p /var/www/html/joinerytest/public_html/assets/js
   mkdir -p /var/www/html/joinerytest/public_html/assets/css
   mkdir -p /var/www/html/joinerytest/public_html/assets/images
   ```

2. **Move files to new locations:**
   ```bash
   mv /var/www/html/joinerytest/public_html/adm/includes/Trumbowyg-2-26 /var/www/html/joinerytest/public_html/assets/vendor/
   mv /var/www/html/joinerytest/public_html/adm/includes/jquery-timepicker-1.3.5 /var/www/html/joinerytest/public_html/assets/vendor/
   mv /var/www/html/joinerytest/public_html/adm/includes/uikit-3.6.14 /var/www/html/joinerytest/public_html/assets/vendor/
   mv /var/www/html/joinerytest/public_html/adm/includes/scripts/* /var/www/html/joinerytest/public_html/assets/js/
   mv /var/www/html/joinerytest/public_html/adm/includes/images/* /var/www/html/joinerytest/public_html/assets/images/
   ```

3. **Update all code references** (see Code Updates Required section)

4. **Update serve.php** to add `/assets/*` static route

5. **Test all affected pages:**
   - Admin page editor (tests Trumbowyg)
   - Admin pages with date/time pickers (tests timepicker)
   - Admin file manager (tests image icons)
   - General admin pages (tests UIKit and jQuery validate)

6. **Clean up:**
   ```bash
   rmdir /var/www/html/joinerytest/public_html/adm/includes/scripts
   rmdir /var/www/html/joinerytest/public_html/adm/includes/images
   rmdir /var/www/html/joinerytest/public_html/adm/includes
   ```

## Benefits
1. **Consistent asset organization:** Follows the same pattern as plugins and themes
2. **Proper static file serving:** Assets served with correct headers and caching
3. **Clear separation:** Vendor assets vs system assets vs plugin/theme assets
4. **Future-proof:** Easy to add new global assets without routing changes

## Testing Checklist
- [ ] Trumbowyg editor loads on admin page editor
- [ ] Date/time pickers work in forms
- [ ] File manager shows correct icons for different file types
- [ ] UIKit styles load correctly in admin interface
- [ ] jQuery validation works in admin forms
- [ ] Browser console shows no 404/500 errors for assets
- [ ] Check that caching headers are properly set for assets

## Rollback Plan
If issues occur:
1. Move files back to `/adm/includes/`
2. Revert code changes in FormWriter and AdminPage files
3. Remove `/assets/*` route from serve.php

## Notes
- The `/adm/includes/` directory was intentionally removed from static routes as noted in serve.php line 105
- This migration aligns with the comment "Admin should use proper asset organization"
- All paths are hardcoded in PHP files, no database updates required