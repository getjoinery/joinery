# Sassa Theme to ControlD Plugin Migration

## Overview

This document outlines the migration of the sassa theme into the controld plugin, consolidating all ControlD-related functionality into a single, self-contained plugin.

## Migration Rationale

The sassa theme is essentially a view layer for the controld plugin:
- Contains 10 ControlD-specific logic files
- Has 14 ControlD-specific profile views
- Custom routing for ControlD functionality
- The controld plugin already has the data models but lacks views

Merging them creates a more maintainable, logical architecture where all ControlD functionality is in one place.

## File Migration Map

### 1. Logic Files
**From:** `theme/sassa/logic/`  
**To:** `plugins/controld/logic/`

Files to move:
- `ctld_activation_logic.php`
- `ctlddevice_delete_logic.php`
- `ctlddevice_edit_logic.php`
- `ctlddevice_soft_delete_logic.php`
- `ctldfilters_edit_logic.php`
- `ctldprofile_delete_logic.php`
- `devices_logic.php`
- `rules_logic.php`
- `profile_logic.php`
- `subscription_cancel_logic.php`

### 2. View Files
**From:** `theme/sassa/views/`  
**To:** `plugins/controld/views/`

Files to move:
```
views/
├── cart.php
├── cart_confirm.php
├── forms_example.php
├── index.php
├── login.php
├── logout.php
├── pricing.php
├── product.php
└── profile/
    ├── account_edit.php
    ├── contact_preferences.php
    ├── ctld_activation.php
    ├── ctlddevice_delete.php
    ├── ctlddevice_edit.php
    ├── ctlddevice_soft_delete.php
    ├── ctldfilters_edit.php
    ├── ctldprofile_delete.php
    ├── devices.php
    ├── password_edit.php
    ├── profile.php
    ├── rules.php
    ├── subscription_cancel.php
    └── subscription_edit.php
```

### 3. Assets
**From:** `theme/sassa/assets/`  
**To:** `plugins/controld/assets/`

Move entire directory structure:
```
assets/
├── css/
├── fonts/
├── img/
├── js/
└── sass/
```

### 4. Include Files
**From:** `theme/sassa/includes/`  
**To:** `plugins/controld/includes/`

Files to move:
- `FormWriter.php`
- `PublicPage.php`

## Exhaustive Asset Reference List

All files requiring asset path updates during the migration:

### Asset References in `theme/sassa/includes/PublicPage.php` (29 references):
- **Line 102**: Background image - `/theme/sassa/assets/img/bg/breadcumb-bg.jpg`
- **Line 237**: Apple touch icon 57x57 - `/theme/sassa/assets/img/favicons/apple-icon-57x57.png`
- **Line 238**: Apple touch icon 60x60 - `/theme/sassa/assets/img/favicons/apple-icon-60x60.png`
- **Line 239**: Apple touch icon 72x72 - `/theme/sassa/assets/img/favicons/apple-icon-72x72.png`
- **Line 240**: Apple touch icon 76x76 - `/theme/sassa/assets/img/favicons/apple-icon-76x76.png`
- **Line 241**: Apple touch icon 114x114 - `/theme/sassa/assets/img/favicons/apple-icon-114x114.png`
- **Line 242**: Apple touch icon 120x120 - `/theme/sassa/assets/img/favicons/apple-icon-120x120.png`
- **Line 243**: Apple touch icon 144x144 - `/theme/sassa/assets/img/favicons/apple-icon-144x144.png`
- **Line 244**: Apple touch icon 152x152 - `/theme/sassa/assets/img/favicons/apple-icon-152x152.png`
- **Line 245**: Apple touch icon 180x180 - `/theme/sassa/assets/img/favicons/apple-icon-180x180.png`
- **Line 246**: Android icon 192x192 - `/theme/sassa/assets/img/favicons/android-icon-192x192.png`
- **Line 247**: Favicon 32x32 - `/theme/sassa/assets/img/favicons/favicon-32x32.png`
- **Line 248**: Favicon 96x96 - `/theme/sassa/assets/img/favicons/favicon-96x96.png`
- **Line 249**: Favicon 16x16 - `/theme/sassa/assets/img/favicons/favicon-16x16.png`
- **Line 250**: Web app manifest - `/theme/sassa/assets/img/favicons/manifest.json`
- **Line 252**: MS tile image - `/theme/sassa/assets/img/favicons/ms-icon-144x144.png`
- **Line 275**: Font Awesome CSS - `/theme/sassa/assets/css/fontawesome.min.css`
- **Line 281**: Swiper CSS - `/theme/sassa/assets/css/swiper-bundle.min.css`
- **Line 283**: Main stylesheet - `/theme/sassa/assets/css/style.css`
- **Line 323**: Preloader SVG (2 references) - `/theme/sassa/assets/img/preloader.svg`
- **Line 357**: Blog image 1 - `/theme/sassa/assets/img/blog/recent-post-1-1.jpg`
- **Line 368**: Blog image 2 - `/theme/sassa/assets/img/blog/recent-post-1-2.jpg`
- **Line 379**: Blog image 3 - `/theme/sassa/assets/img/blog/recent-post-1-3.jpg`
- **Line 395**: Location icon - `/theme/sassa/assets/img/icon/location.svg`
- **Line 401**: Mail icon - `/theme/sassa/assets/img/icon/mail.svg`
- **Line 410**: Call icon - `/theme/sassa/assets/img/icon/call.svg`
- **Line 430**: Logo SVG (commented) - `/theme/sassa/assets/img/logo.svg`
- **Line 630**: Logo SVG (commented, 2 references) - `/theme/sassa/assets/img/logo.svg`
- **Line 723**: Swiper JS - `/theme/sassa/assets/js/swiper-bundle.min.js`
- **Line 749**: Main JS - `/theme/sassa/assets/js/main.js`

### Asset References in `theme/sassa/views/index.php` (7 references):
- **Line 56**: Client avatar 1 - `/theme/sassa/assets/img/shape/client-1-1.png`
- **Line 57**: Client avatar 2 - `/theme/sassa/assets/img/shape/client-1-2.png`
- **Line 58**: Client avatar 3 - `/theme/sassa/assets/img/shape/client-1-3.png`
- **Line 120**: Feature icon - `/theme/sassa/assets/img/icon/feature_1_1.svg`
- **Line 130**: Feature icon - `/theme/sassa/assets/img/icon/feature_1_1.svg`
- **Line 140**: Feature icon - `/theme/sassa/assets/img/icon/feature_1_1.svg`
- **Line 150**: Feature icon - `/theme/sassa/assets/img/icon/feature_1_1.svg`

### Routing References in `theme/sassa/serve.php` (2 references):
- **Line 11**: View path - `/theme/sassa/views/profile/ctlddevice_edit.php`
- **Line 20**: View path - `/theme/sassa/views/profile/ctldfilters_edit.php`

### **Total Asset References: 38 files need path updates**

**Asset Path Changes Required:**
- **FROM**: `/theme/sassa/assets/` 
- **TO**: `/plugins/controld/assets/`

**View Path Changes Required:**
- **FROM**: `/theme/sassa/views/`
- **TO**: `/plugins/controld/views/`

**Include Path Changes Required:**
- **FROM**: `/theme/sassa/includes/`
- **TO**: `/plugins/controld/includes/`

**Logic Path Changes Required:**
- **FROM**: `/theme/sassa/logic/`
- **TO**: `/plugins/controld/logic/`

## Files Requiring Updates

### 1. Add Plugin Asset Serving to `serve.php`

The main serve.php needs plugin asset serving capability added:

```php
// Add after line 114 in serve.php
//PLUGIN ASSET FILES
if($params[0] == 'plugins' && $params[2] == 'assets'){
    $base_file = PathHelper::getRootDir().$_SERVER['REQUEST_URI'];
    if(file_exists($base_file)){
        // Check if plugin is active
        $plugin_name = $params[1];
        PathHelper::requireOnce('data/plugins_class.php');
        
        if(Plugin::is_plugin_active($plugin_name)){
            $seconds_to_cache = 43200;
            $ts = gmdate("D, d M Y H:i:s", time() + $seconds_to_cache) . " GMT";
            header("Expires: $ts");
            header("Pragma: cache");
            header("Cache-Control: max-age=$seconds_to_cache");
            $the_content_type = 'Content-type: '.mime_type($base_file);
            header($the_content_type);
            readfile($base_file);
            exit();
        }
        else{
            LibraryFunctions::display_404_page();
        }
    }
    else{
        LibraryFunctions::display_404_page();
    }
}
```

### 2. Update `plugins/controld/serve.php`

Merge routing from `theme/sassa/serve.php` into `plugins/controld/serve.php`:

```php
<?php

// PROFILE CTLD DEVICE ROUTES
if($params[0] == 'profile' && $params[1] == 'device_edit'){	
    $base_file = PathHelper::getIncludePath('plugins/controld/views/profile/ctlddevice_edit.php');
    if(file_exists($base_file)){
        $is_valid_page = true;
        require_once($base_file); 
        exit();		
    }
}

if($params[0] == 'profile' && $params[1] == 'filters_edit'){	
    $base_file = PathHelper::getIncludePath('plugins/controld/views/profile/ctldfilters_edit.php');
    if(file_exists($base_file)){
        $is_valid_page = true;
        require_once($base_file); 
        exit();		
    }
}

// Add other profile routes for ControlD
if($params[0] == 'profile' && $params[1] == 'devices'){	
    $base_file = PathHelper::getIncludePath('plugins/controld/views/profile/devices.php');
    if(file_exists($base_file)){
        $is_valid_page = true;
        require_once($base_file); 
        exit();		
    }
}

if($params[0] == 'profile' && $params[1] == 'rules'){	
    $base_file = PathHelper::getIncludePath('plugins/controld/views/profile/rules.php');
    if(file_exists($base_file)){
        $is_valid_page = true;
        require_once($base_file); 
        exit();		
    }
}

if($params[0] == 'profile' && $params[1] == 'ctld_activation'){	
    $base_file = PathHelper::getIncludePath('plugins/controld/views/profile/ctld_activation.php');
    if(file_exists($base_file)){
        $is_valid_page = true;
        require_once($base_file); 
        exit();		
    }
}

// EXISTING CREATE ACCOUNT ROUTE
if($params[0] == 'create_account'){
    $base_file = PathHelper::getIncludePath('plugins/controld/views/create_account.php');
    require_once($base_file); 
    exit();	
}

// ROOT VIEWS (if needed for ControlD-specific pages)
if($params[0] == 'pricing' && Plugin::is_plugin_active('controld')){	
    $base_file = PathHelper::getIncludePath('plugins/controld/views/pricing.php');
    if(file_exists($base_file)){
        $is_valid_page = true;
        require_once($base_file); 
        exit();		
    }
}

// ADMIN ROUTES (keep existing)
if($params[0] == 'plugins' && $params[1] == 'controld' && $params[2] == 'admin'){	
    $base_file = ensure_extension(PathHelper::getIncludePath('plugins/controld/admin/'.$params[3]),'php');
    if(file_exists($base_file)){
        $is_valid_page = true;
        require_once($base_file); 
        exit();		
    }
}

?>
```

### 2. Update Asset References in View Files

All view files need their asset paths updated:

**CSS/JS Asset References:**
```php
// OLD (in sassa theme views):
<link rel="stylesheet" href="/theme/sassa/assets/css/bootstrap.min.css">
<script src="/theme/sassa/assets/js/main.js"></script>

// NEW (in controld plugin views):
<link rel="stylesheet" href="/plugins/controld/assets/css/bootstrap.min.css">
<script src="/plugins/controld/assets/js/main.js"></script>
```

**Image References:**
```php
// OLD:
<img src="/theme/sassa/assets/img/logo.svg" alt="Logo">

// NEW:
<img src="/plugins/controld/assets/img/logo.svg" alt="Logo">
```

### 3. Update Include Path References

In all migrated PHP files, update include/require statements:

```php
// OLD (in view files):
require_once($_SERVER['DOCUMENT_ROOT'].'/theme/sassa/includes/PublicPage.php');
require_once($_SERVER['DOCUMENT_ROOT'].'/theme/sassa/logic/devices_logic.php');

// NEW:
PathHelper::requireOnce('plugins/controld/includes/PublicPage.php');
PathHelper::requireOnce('plugins/controld/logic/devices_logic.php');
```

### 4. Update Logic File References

The logic files that call other logic files need path updates:

```php
// In migrated logic files, update any references:
// OLD:
require_once($_SERVER['DOCUMENT_ROOT'].'/theme/sassa/logic/profile_logic.php');

// NEW:
PathHelper::requireOnce('plugins/controld/logic/profile_logic.php');
```

### 5. Update FormWriter References

If the sassa FormWriter extends base classes:

```php
// In plugins/controld/includes/FormWriter.php
// Update parent class references if needed:
require_once(PathHelper::getIncludePath('includes/FormWriterMasterBootstrap.php'));
class FormWriter extends FormWriterMasterBootstrap {
    // ...
}
```

### 6. Update PublicPage References

The PublicPage class may need updates:

```php
// In plugins/controld/includes/PublicPage.php
// Update any theme-specific references to plugin paths
class PublicPage extends PublicPageFalcon {
    // Update any asset paths or includes
}
```

## Main serve.php Updates

The main `serve.php` file already handles plugin routing, but ensure:

1. **Plugin include files are served** (lines 86-114) - Already working
2. **Plugin serve.php is included** (lines 631-647) - Already working
3. **Plugin assets can be served** - May need to add:

```php
// Add to main serve.php if not present
// PLUGIN ASSET FILES
if($params[0] == 'plugins' && $params[2] == 'assets'){
    $base_file = PathHelper::getRootDir().$_SERVER['REQUEST_URI'];
    if(file_exists($base_file)){
        // Check if plugin is active
        $plugin_name = $params[1];
        PathHelper::requireOnce('data/plugins_class.php');
        
        if(Plugin::is_plugin_active($plugin_name)){
            $seconds_to_cache = 43200;
            $ts = gmdate("D, d M Y H:i:s", time() + $seconds_to_cache) . " GMT";
            header("Expires: $ts");
            header("Pragma: cache");
            header("Cache-Control: max-age=$seconds_to_cache");
            $the_content_type = 'Content-type: '.mime_type($base_file);
            header($the_content_type);
            readfile($base_file);
            exit();
        }
        else{
            LibraryFunctions::display_404_page();
        }
    }
    else{
        LibraryFunctions::display_404_page();
    }
}
```

## Migration Steps

1. **Create backup** of both sassa theme and controld plugin
2. **Create directory structure** in controld plugin:
   ```bash
   mkdir -p plugins/controld/views/profile
   mkdir -p plugins/controld/logic
   mkdir -p plugins/controld/assets
   ```
3. **Copy files** according to the migration map
4. **Update all file references** as outlined above
5. **Test each route** to ensure functionality
6. **Update plugin.json** version to indicate major update
7. **Remove or archive** the sassa theme

## Testing Checklist

After migration, test:

- [ ] All profile routes work (`/profile/devices`, `/profile/rules`, etc.)
- [ ] CSS and JS assets load correctly
- [ ] Images display properly
- [ ] Form submissions work
- [ ] Logic files execute without errors
- [ ] Admin pages still function
- [ ] Plugin activation/deactivation works
- [ ] No 404 errors for assets
- [ ] All ControlD-specific functionality intact

## Post-Migration Benefits

- **Single codebase** for all ControlD functionality
- **Easier maintenance** - all code in one location
- **Plugin portability** - can be moved between sites easily
- **Clear separation** - ControlD functionality separate from theme
- **Better organization** - follows plugin architecture patterns
- **Simplified deployment** - one plugin instead of theme + plugin