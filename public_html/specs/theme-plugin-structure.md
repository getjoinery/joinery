# Theme and Plugin Structure Specification

## Overview

This document defines the standard directory structure and organization for themes and plugins in the Joinery system. The structure is designed to work with the new routing system defined in serve_refactor.md, which requires clear separation between static assets and executable PHP files.

## Core Principles

1. **Clear Separation**: Static assets (CSS, JS, images, fonts) must be clearly separated from executable PHP files
2. **Consistent Naming**: Use predictable directory names across all themes and plugins
3. **Theme Independence**: Themes provide presentation only - no business logic or data handling
4. **Plugin Modularity**: Plugins provide functionality and can include admin interfaces, data models, and API endpoints
5. **Asset Routing**: Static assets are served directly via readfile(), while PHP files are executed/included

## Theme Structure

Themes reside in the `/theme/[theme-name]/` directory and provide presentation layer functionality only.

### Required Theme Structure

```
/theme/[theme-name]/
├── theme.json                     # REQUIRED: Theme manifest
├── assets/                        # Static assets served via readfile()
│   ├── css/                       # Stylesheets
│   │   ├── theme.css             # Main theme styles
│   │   └── *.css                 # Additional stylesheets
│   ├── js/                        # JavaScript files
│   │   ├── theme.js              # Main theme script
│   │   └── *.js                  # Additional scripts
│   ├── images/                    # Theme images
│   │   ├── favicon.ico           # Site favicon
│   │   └── *.{jpg,png,svg,webp}  # Other image files
│   ├── fonts/                     # Theme fonts
│   │   └── *.{woff,woff2,ttf}    # Font files
│   └── vendors/                   # Third-party assets
│       └── [vendor-name]/         # Vendor subdirectories (flexible structure)
├── includes/                      # PHP includes (executed, not served)
│   ├── FormWriter.php            # Theme-specific FormWriter
│   ├── PublicPage.php            # Theme-specific PublicPage
│   └── functions.php             # Theme functions (auto-loaded)
├── views/                         # View templates (PHP files)
│   ├── index.php                 # Homepage template
│   ├── page.php                  # Page template
│   ├── post.php                  # Post template
│   ├── product.php               # Product template
│   ├── profile/                  # User profile templates
│   │   ├── profile.php           # Main profile page
│   │   └── *.php                 # Profile sub-pages
│   └── *.php                     # Additional templates
├── logic/                         # Theme-specific logic (if needed)
│   └── *_logic.php               # Logic files
├── docs/                          # Documentation (optional)
│   ├── README.md                 # Theme documentation
│   ├── CHANGELOG.md              # Version history
│   └── *.md                      # Additional documentation
└── serve.php                      # Theme routing overrides (optional)
```

### Theme Manifest (theme.json)

```json
{
  "name": "theme-directory-name",
  "displayName": "Theme Display Name",
  "version": "1.0.0",
  "description": "Theme description",
  "author": "Author Name",
  "requires": {
    "php": ">=7.4",
    "joinery": ">=1.0.0"
  },
  "cssFramework": "bootstrap|tailwind|uikit|custom",
  "formWriterBase": "FormWriterMasterBootstrap",
  "publicPageBase": "PublicPageFalcon"
}
```

### Theme Routing

Static assets are accessed via:
- `/theme/[theme-name]/assets/*` - Served directly with caching headers
- Theme views are included via ThemeHelper, not served directly

### Vendor Asset Organization

The `/assets/vendors/` directory is for third-party libraries and frameworks. Vendor subdirectories have **flexible internal structure** but must contain **only static assets**.

**Suggested structure:**
```
/assets/vendors/bootstrap/
├── css/
│   └── bootstrap.min.css
├── js/
│   └── bootstrap.min.js
└── fonts/
    └── bootstrap-icons.woff
```

**Alternative structures are allowed:**
```
/assets/vendors/jquery/
└── jquery.min.js

/assets/vendors/fontawesome/
├── all.min.css
├── webfonts/
│   ├── fa-solid-900.woff2
│   └── fa-regular-400.woff2
└── LICENSE.txt
```

**Not allowed in vendor directories:**
- PHP files (`.php`)
- Server-side scripts  
- Files requiring special routing

## Plugin Structure

Plugins reside in the `/plugins/[plugin-name]/` directory and provide functionality extensions.

### Required Plugin Structure

```
/plugins/[plugin-name]/
├── plugin.json                    # REQUIRED: Plugin manifest
├── assets/                        # Static assets served via readfile()
│   ├── css/                       # Plugin stylesheets
│   │   └── plugin.css            # Main plugin styles
│   ├── js/                        # Plugin JavaScript
│   │   └── plugin.js             # Main plugin script
│   ├── images/                    # Plugin images
│   │   └── *.{jpg,png,svg}       # Image files
│   └── vendors/                   # Third-party assets
│       └── [vendor-name]/         # Vendor subdirectories (flexible structure)
├── admin/                         # Admin interface (executed)
│   ├── admin_[entity].php        # List pages
│   ├── admin_[entity]_edit.php   # Edit forms
│   └── includes/                  # Admin includes
│       └── *.php                  # Helper files
├── data/                          # Data models
│   └── [entity]_class.php        # Model classes
├── hooks/                         # Event-driven hooks (optional)
│   └── product_purchase.php      # Product purchase scripts
├── ajax/                          # AJAX endpoints
│   └── *.php                     # AJAX handlers
├── api/                           # API endpoints
│   └── *.php                     # API handlers
├── migrations/                    # Database migrations
│   └── migrations.php            # Migration definitions
├── includes/                      # Plugin includes
│   └── *.php                     # Helper classes
├── utils/                         # Utility scripts (optional)
│   └── *.php                     # Command-line tools, maintenance scripts
├── docs/                          # Documentation (optional)
│   ├── README.md                 # Plugin documentation
│   ├── CHANGELOG.md              # Version history
│   ├── LICENSE                   # License file
│   └── *.md                      # Additional documentation
├── serve.php                      # Plugin routing (optional)
├── uninstall.php                  # Uninstall script (recommended)
├── activate.php                   # Activation hook (optional)
└── deactivate.php                 # Deactivation hook (optional)
```

### Plugin Manifest (plugin.json)

```json
{
  "name": "Plugin Name",
  "version": "1.0.0",
  "description": "Plugin description",
  "author": "Author Name",
  "requires": {
    "php": ">=8.0",
    "joinery": ">=1.0.0",
    "extensions": ["pdo", "json"]
  }
}
```

**Note:** While PluginHelper.php has code that references `provides`, `adminMenu`, and `apiEndpoints` fields, these are not actively used by the system:
- `adminMenu` references non-existent `AdminMenuRegistry` class
- `apiEndpoints` is retrieved but never processed
- `provides` is only used to prevent plugins from claiming to be themes

These fields should not be added to plugin manifests at this time.

### Plugin Routing

Plugin assets and files are accessed via:
- `/plugins/[plugin-name]/assets/*` - Static assets (served directly)
- `/plugins/[plugin-name]/admin/*` - Admin pages (executed)
- Plugin views are typically included via logic files, not served directly

## Routing Rules

Based on the serve_refactor.md specification:

### Static Asset Routes (served via readfile())
These directories contain ONLY static files that are served directly:
- `/theme/*/assets/*` - Theme static assets
- `/plugins/*/assets/*` - Plugin static assets

### Dynamic Routes (PHP executed/included)
These directories contain PHP files that are executed:
- `/theme/*/views/*` - Theme view templates (included via ThemeHelper)
- `/theme/*/includes/*` - Theme PHP includes
- `/plugins/*/admin/*` - Plugin admin interfaces
- `/plugins/*/ajax/*` - Plugin AJAX endpoints
- `/plugins/*/api/*` - Plugin API endpoints

### Never Directly Accessible
These directories should never be directly accessible via URL:
- `/theme/*/logic/*` - Theme logic files
- `/theme/*/docs/*` - Theme documentation
- `/plugins/*/data/*` - Plugin data models
- `/plugins/*/includes/*` - Plugin include files
- `/plugins/*/hooks/*` - Plugin hook handlers
- `/plugins/*/migrations/*` - Plugin migrations
- `/plugins/*/utils/*` - Plugin utility scripts
- `/plugins/*/docs/*` - Plugin documentation

## Migration Requirements

### Current Structure Issues

Many existing themes and plugins don't follow this structure. Common issues:

1. **Mixed static/dynamic files**: Static assets mixed with PHP files in same directories
2. **Direct includes access**: JavaScript/CSS in `/includes/` directories
3. **Missing manifests**: No theme.json or plugin.json files
4. **Inconsistent paths**: Various naming conventions for directories
5. **Plugin logic files**: Plugins have `/logic/` directories that should not exist
6. **Plugin view files**: Some plugins may have `/views/` directories that are not allowed
7. **WordPress legacy content**: `/wp-content/` directories contain assets that need redistribution
8. **Profile directory placement**: Some themes have `/profile/` at root instead of `/views/profile/`
9. **Legacy asset directories**: Multiple asset directories (`/images/`, `/scripts/`, `/styles/`) need consolidation

### Migration Steps

#### For Themes

1. **Create assets directory**: Move all static files to `/assets/` subdirectories
2. **Organize by type**: Separate CSS, JS, images, fonts into subdirectories
3. **Create theme.json**: Add manifest with required fields
4. **Update references**: Update all asset URLs to use new `/assets/` paths
5. **Move vendors**: Consolidate third-party assets under `/assets/vendors/`
6. **Move miscellaneous assets**: Move directories like `emailtemplates/`, `scripts/`, `styles/`, and root files like `favicon.ico` to `/assets/`
   - GDPR/legal scripts follow the same rules: JS files → `/assets/js/`, CSS files → `/assets/css/`
7. **Migrate WordPress legacy content**: Break down `/wp-content/` directories and redistribute assets to proper locations:
   - CSS files → `/assets/css/vendors/[plugin-name]/`
   - JS files → `/assets/js/vendors/[plugin-name]/`  
   - Images/fonts → `/assets/images/vendors/[plugin-name]/` or `/assets/fonts/vendors/[plugin-name]/`
   - Update all view file references to use new asset paths
8. **Move profile directories**: Themes with `/profile/` at root level must move to `/views/profile/`
9. **Consolidate legacy asset directories**: Migrate all asset directories to standard structure:
   - `/images/` → `/assets/images/`
   - `/scripts/`, `/js/` → `/assets/js/`
   - `/styles/`, `/css/` → `/assets/css/`
   - `/fonts/` → `/assets/fonts/`
   - Mixed CSS/JS in `/includes/` → Move to appropriate `/assets/` subdirectory
   - Update all file references in view templates and PHP files
10. **Organize documentation files**: Move scattered documentation to `/docs/` directory:
    - `README.md`, `TODO*.md`, `CHANGELOG.md` → `/docs/`
    - `LICENSE` files → `/docs/`

#### For Plugins

1. **Create assets directory**: Move all static files to `/assets/` subdirectories
2. **Organize admin files**: Ensure admin interfaces are in `/admin/` directory
3. **Create plugin.json**: Add manifest with required fields
4. **Update references**: Update all asset URLs to use new `/assets/` paths
5. **Add uninstall.php**: Create uninstall script for clean removal
6. **Migrate logic files**: 
   - Product scripts → `/hooks/product_purchase.php`
   - Other logic → Move to theme logic or convert to data model methods
7. **Remove view files**: Plugin views must be moved to theme `/views/` directory

### Asset URL Updates

#### Before (problematic):
```php
<link href="/theme/falcon/includes/css/theme.css" rel="stylesheet">
<script src="/plugins/controld/includes/js/script.js"></script>
<img src="/theme/falcon/includes/img/logo.png">
```

#### After (correct):
```php
<link href="/theme/falcon/assets/css/theme.css" rel="stylesheet">
<script src="/plugins/controld/assets/js/script.js"></script>
<img src="/theme/falcon/assets/images/logo.png">
```

## Plugin Hooks System

Plugins can respond to system events through the `/hooks/` directory. This replaces the old `/logic/` directory pattern.

### Currently Supported Hooks

#### Product Purchase Hook
Located at `/plugins/[plugin-name]/hooks/product_purchase.php`

This file should contain functions that are called when products are purchased. Functions must:
- End with `_product_script` suffix
- Accept two parameters: `($user, $order_item)`
- Return boolean

Example:
```php
// /plugins/controld/hooks/product_purchase.php
function controld_subscription_product_script($user, $order_item) {
    // Handle ControlD subscription activation
    $ctld_account = CtldAccount::GetByColumn('cda_usr_user_id', $user->key);
    // ... activation logic ...
    return true;
}
```

Products reference these functions in their `pro_product_scripts` field.

### Migration Path for Existing Plugin Logic Files

There are only two plugin logic files in the current system:

1. **`/plugins/controld/logic/product_scripts_logic.php`**
   - **Migrate to:** `/plugins/controld/hooks/product_purchase.php`
   - **Reason:** Event-driven hook for product purchases
   - **No code changes needed:** Just move the file to the new location
   - **System update needed:** Update `Product->run_product_scripts()` to look in `/hooks/` instead of `/logic/`

2. **`/plugins/items/logic/items_logic.php`**
   - **Migrate to:** `/theme/[active-theme]/logic/items_logic.php`  
   - **Reason:** This is presentation logic that prepares data for a view
   - **Also migrate:** `/plugins/items/views/items.php` → `/theme/[active-theme]/views/items.php`
   - **Update serve.php:** The items plugin's serve.php should look for the view in the theme directory

## Benefits of This Structure

1. **Security**: Clear separation prevents execution of static files
2. **Performance**: Static assets can be cached aggressively
3. **Maintainability**: Predictable structure makes maintenance easier
4. **Compatibility**: Works seamlessly with new routing system
5. **Clarity**: Developers know exactly where to find each type of file
6. **Flexibility**: Plugins and themes can evolve independently

## Implementation Priority

### High Priority (Breaking Issues)
1. Move JavaScript/CSS out of `/includes/` directories
2. Create `/assets/` directories for all themes/plugins
3. Update asset references in PHP files
4. Add manifests to all themes/plugins

### Medium Priority (Improvements)
1. Organize vendor assets into `/assets/vendors/`
2. Standardize directory naming conventions
3. Add uninstall scripts for plugins
4. Document asset dependencies in manifests

## Examples

### Properly Structured Theme: Falcon
```
/theme/falcon/
├── theme.json
├── assets/
│   ├── css/
│   │   ├── theme.css
│   │   └── user_exceptions.css
│   ├── js/
│   │   ├── theme.js
│   │   └── theme.min.js
│   ├── images/
│   │   ├── blank-avatar.png
│   │   └── logo.png
│   └── vendors/
│       ├── bootstrap/
│       └── fontawesome/
├── includes/
│   ├── FormWriter.php
│   └── PublicPage.php
└── views/
    ├── index.php
    ├── page.php
    └── profile/
        └── profile.php
```

### Properly Structured Plugin: ControlD
```
/plugins/controld/
├── plugin.json
├── assets/
│   ├── css/
│   │   └── controld.css
│   ├── js/
│   │   └── controld.js
│   └── images/
│       └── logo.png
├── admin/
│   ├── admin_ctld_accounts.php
│   └── admin_settings_controld.php
├── data/
│   ├── ctldaccounts_class.php
│   └── ctlddevices_class.php
├── includes/
│   └── ControlDHelper.php
├── migrations/
│   └── migrations.php
├── serve.php
└── uninstall.php
```

## Validation Checklist

### Theme Validation
- [ ] Has valid theme.json manifest
- [ ] All static assets in /assets/ subdirectories
- [ ] CSS files in /assets/css/
- [ ] JavaScript in /assets/js/
- [ ] Images in /assets/images/
- [ ] Fonts in /assets/fonts/
- [ ] No static files in /includes/
- [ ] FormWriter class specified in manifest
- [ ] Version follows semantic versioning

### Plugin Validation
- [ ] Has valid plugin.json manifest
- [ ] All static assets in /assets/ subdirectories
- [ ] Admin interfaces in /admin/
- [ ] Data models in /data/
- [ ] Has uninstall.php script
- [ ] Version follows semantic versioning
- [ ] No static files in /includes/
- [ ] Migrations use new format

## Conclusion

This structure provides a clean, secure, and maintainable organization for themes and plugins. By clearly separating static assets from executable code and following consistent naming conventions, the system becomes more secure, performant, and easier to maintain. The migration path allows for gradual adoption while maintaining backward compatibility during the transition period.