# ControlD to Theme Migration Specification

## Overview

This specification outlines the migration of ControlD functionality from the current split architecture (theme/sassa + plugins/controld) into a unified, self-contained plugin using the new hybrid plugin/theme system.

## Current Architecture Analysis

**theme/sassa/** (UI and Logic):
- **Views**: 13 ControlD-specific views in `views/profile/ctld*`
- **Logic**: 6 ControlD business logic files in `logic/ctld*_logic.php`
- **Assets**: Bootstrap-based theme assets (CSS, JS, images)
- **Includes**: Custom FormWriter and PublicPage classes
- **Routing**: ControlD routes defined in `serve.php`

**plugins/controld/** (Data and Admin):
- **Data Models**: 7 database model classes (`ctld*_class.php`)
- **Admin Interface**: 3 admin pages for ControlD management
- **Helper Class**: `ControlDHelper.php` with API integration
- **Database**: Migrations and schema definitions
- **Hooks**: Product purchase integration

**Goal**: Consolidate everything into `plugins/controld/` as a self-contained application plugin.

## Target Architecture: Self-Contained Plugin

### New Structure: plugins/controld/

```
plugins/controld/
├── plugin.json                    # Updated metadata with UI capability
├── serve.php                      # All ControlD routes
├── assets/                        # ControlD-specific assets
│   ├── css/
│   ├── js/
│   └── images/
├── views/                         # All ControlD views (migrated from theme)
│   ├── profile/
│   │   ├── ctld_activation.php
│   │   ├── ctlddevice_edit.php
│   │   ├── ctldfilters_edit.php
│   │   ├── devices.php
│   │   ├── rules.php
│   │   └── ...
│   ├── pricing.php
│   └── index.php                  # Main ControlD dashboard
├── logic/                         # Business logic (migrated from theme)
│   ├── ctld_activation_logic.php
│   ├── ctlddevice_edit_logic.php
│   ├── ctldfilters_edit_logic.php
│   └── ...
├── includes/                      # Helper classes
│   ├── ControlDHelper.php
│   ├── ControlDFormWriter.php     # Plugin-specific form handling
│   └── ControlDPageHelper.php     # Plugin-specific page handling
├── services/                      # Service classes for other plugins
│   └── ControlDService.php        # API for other plugins to use ControlD
├── data/                          # Database models (existing)
├── admin/                         # Admin interface (existing)
├── migrations/                    # Database migrations (existing)
└── hooks/                         # System hooks (existing)
```

## Migration Steps

### 1. Update plugin.json
```json
{
    "name": "controld",
    "display_name": "ControlD DNS Filtering",
    "version": "2.0.0",
    "description": "Complete ControlD DNS filtering service with integrated UI",
    "author": "System Developer",
    "type": "application",
    "routes_prefix": "/controld",
    "provides": ["dns_filtering", "device_management", "content_filtering"],
    "is_stock": true,
    "requires": {
        "php": ">=8.0",
        "extensions": ["curl"]
    }
}
```

### 2. Create directory structure
```bash
mkdir -p plugins/controld/views/profile
mkdir -p plugins/controld/logic
mkdir -p plugins/controld/assets/css
mkdir -p plugins/controld/assets/js
mkdir -p plugins/controld/assets/images
mkdir -p plugins/controld/services
```

### 3. Create plugin routing (plugins/controld/serve.php)
```php
<?php
$routes = [
    'dynamic' => [
        '/controld' => ['view' => 'views/index'],
        '/profile/device_edit' => ['view' => 'views/profile/ctlddevice_edit'],
        '/profile/filters_edit' => ['view' => 'views/profile/ctldfilters_edit'],
        '/profile/devices' => ['view' => 'views/profile/devices'],
        '/profile/rules' => ['view' => 'views/profile/rules'],
        '/profile/ctld_activation' => ['view' => 'views/profile/ctld_activation'],
        '/pricing' => ['view' => 'views/pricing'],
    ],
    'static' => [
        '/controld/assets/*' => [
            'path' => 'plugins/controld/assets/{path}',
            'cache' => 86400
        ]
    ]
];
```

### 4. Move views from theme to plugin
```bash
# Copy all ControlD views from theme to plugin
cp theme/sassa/views/profile/ctld*.php plugins/controld/views/profile/
cp theme/sassa/views/pricing.php plugins/controld/views/
```

### 5. Move logic files from theme to plugin
```bash
cp theme/sassa/logic/ctld*_logic.php plugins/controld/logic/
```

### 6. Update view file paths
In each moved view file, update:
- Change `require_once($_SERVER['DOCUMENT_ROOT'] . '/...')` to `PathHelper::requireOnce(...)`
- Update logic file paths to use `plugins/controld/logic/` instead of theme logic
- Update any theme-specific asset references

### 7. Create main ControlD index view (plugins/controld/views/index.php)
```php
<?php
PathHelper::requireOnce('includes/ThemeHelper.php');
$page = new PublicPage();
$page->public_header(['title' => 'ControlD Dashboard']);
echo PublicPage::BeginPage('ControlD Dashboard');
// Add main dashboard content
echo PublicPage::EndPage();
```

### 8. Create service layer (plugins/controld/services/ControlDService.php)
```php
<?php
class ControlDService {
    private $helper;
    
    public function __construct() {
        PathHelper::requireOnce('plugins/controld/includes/ControlDHelper.php');
        $this->helper = new ControlDHelper();
    }
    
    public function getDevicesForUser($user_id) {
        return $this->helper->getUserDevices($user_id);
    }
    
    public function createProfile($user_id, $profile_data) {
        return $this->helper->createProfile($user_id, $profile_data);
    }
}
```

### 9. Copy necessary assets
```bash
# Copy ControlD-specific CSS/JS from theme to plugin assets
cp theme/sassa/assets/css/controld*.css plugins/controld/assets/css/
cp theme/sassa/assets/js/controld*.js plugins/controld/assets/js/
```

### 10. Clean up theme/sassa
- Remove ControlD routes from `theme/sassa/serve.php`
- Remove ControlD views: `rm theme/sassa/views/profile/ctld*`
- Remove ControlD logic: `rm theme/sassa/logic/ctld*`
- Update `theme/sassa/theme.json` to remove ControlD references

## Result

After migration, ControlD will be a self-contained plugin that:

- **Works with any theme**: Falcon, blank, or custom themes
- **Contains all functionality**: Views, logic, data models, admin interface
- **Provides service layer**: Other plugins can integrate ControlD features
- **Maintains all features**: Device management, filtering, profiles, etc.
- **Has clean theme separation**: theme/sassa becomes generic, ControlD-free

The plugin will use the new hybrid plugin/theme system to automatically provide its views when the current route matches ControlD functionality, while falling back to theme styling and components.