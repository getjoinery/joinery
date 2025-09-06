# ControlD Plugin Enhancement Specification

## Overview

This specification outlines the enhancement of the ControlD plugin by copying necessary views and logic from the sassa theme into the controld plugin, while leaving the sassa theme intact. This approach creates a self-contained ControlD plugin that works with the hybrid plugin/theme system without disrupting existing theme functionality.

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

**Goal**: Copy ControlD-specific files into `plugins/controld/` to create a self-contained application plugin while preserving the sassa theme's existing functionality.

## Target Architecture: Enhanced Self-Contained Plugin

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

## Implementation Steps

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
# Create new directories needed for the enhanced plugin
mkdir -p plugins/controld/views/profile
mkdir -p plugins/controld/logic
mkdir -p plugins/controld/assets/css
mkdir -p plugins/controld/assets/js
mkdir -p plugins/controld/assets/images
mkdir -p plugins/controld/services

# Note: Existing directories (data, admin, includes, migrations, hooks) remain unchanged
```

### 2a. File Copying Strategy

**Identification of Files to Copy:**

1. **ControlD Views** (from `theme/sassa/views/`):\n   - `profile/ctld_activation.php`\n   - `profile/ctlddevice_edit.php`\n   - `profile/ctldfilters_edit.php`\n   - `profile/devices.php` (if ControlD-specific)\n   - `profile/rules.php` (if ControlD-specific)\n   - `pricing.php` (if ControlD-related)\n\n2. **ControlD Logic** (from `theme/sassa/logic/`):\n   - `ctld_activation_logic.php`\n   - `ctlddevice_edit_logic.php`\n   - `ctldfilters_edit_logic.php`\n   - Any other `ctld*_logic.php` files\n\n3. **ControlD Assets** (from `theme/sassa/assets/`):\n   - CSS files with ControlD-specific styling\n   - JavaScript files for ControlD functionality\n   - Images specific to ControlD features\n\n**Copy Strategy:**\n- **Preserve originals**: All files remain in sassa theme for backward compatibility\n- **Update paths**: Copied files updated to use plugin-relative paths\n- **Maintain functionality**: No loss of existing features\n- **Enable independence**: Plugin works without theme dependency

### 3. Create plugin routing (plugins/controld/serve.php)
```php
<?php
// Plugin routes - will be merged with theme routes in the hybrid system
$routes = [
    'dynamic' => [
        // Main ControlD dashboard
        '/controld' => [
            'view' => 'views/index',
            'plugin_specify' => 'controld'
        ],
        // Profile management routes
        '/profile/device_edit' => [
            'view' => 'views/profile/ctlddevice_edit',
            'plugin_specify' => 'controld'
        ],
        '/profile/filters_edit' => [
            'view' => 'views/profile/ctldfilters_edit', 
            'plugin_specify' => 'controld'
        ],
        '/profile/devices' => [
            'view' => 'views/profile/devices',
            'plugin_specify' => 'controld'
        ],
        '/profile/rules' => [
            'view' => 'views/profile/rules',
            'plugin_specify' => 'controld'
        ],
        '/profile/ctld_activation' => [
            'view' => 'views/profile/ctld_activation',
            'plugin_specify' => 'controld'
        ],
        // Public pages
        '/pricing' => [
            'view' => 'views/pricing',
            'plugin_specify' => 'controld'
        ],
    ],
    'static' => [
        '/controld/assets/*' => [
            'path' => 'plugins/controld/assets/{path}',
            'cache' => 86400
        ]
    ]
];
```

### 4. Copy views from theme to plugin
```bash
# Copy all ControlD views from theme to plugin (preserve originals)
cp theme/sassa/views/profile/ctld*.php plugins/controld/views/profile/
cp theme/sassa/views/pricing.php plugins/controld/views/
# Note: Original files remain in theme/sassa for backward compatibility
```

### 5. Copy logic files from theme to plugin
```bash
# Copy ControlD logic files (preserve originals)
cp theme/sassa/logic/ctld*_logic.php plugins/controld/logic/
# Note: Original files remain in theme/sassa for existing functionality
```

### 6. Update copied view file paths
In each copied view file, update:
- Change `require_once($_SERVER['DOCUMENT_ROOT'] . '/...')` to `PathHelper::requireOnce(...)`
- Update logic file paths to use `plugins/controld/logic/` for plugin-specific logic
- Update asset references to use plugin assets or ThemeHelper for theme assets
- Add plugin context awareness for proper view resolution
- Ensure compatibility with multiple themes through generic styling classes

### 7. Create main ControlD index view (plugins/controld/views/index.php)
```php
<?php
// Main ControlD dashboard - works with any theme
PathHelper::requireOnce('includes/ThemeHelper.php');
$page_title = 'ControlD Dashboard';

// Use theme's PublicPage class through ThemeHelper
$result = ThemeHelper::includeThemeFile('includes/PublicPage.php');
if ($result === false) {
    PathHelper::requireOnce('includes/PublicPageBase.php');
    $page = new PublicPageBase();
} else {
    $page = new PublicPage();
}

$page->public_header(['title' => $page_title]);
echo $page->BeginPage($page_title);

// Add main dashboard content using plugin data
PathHelper::requireOnce('plugins/controld/data/ctldaccount_class.php');
PathHelper::requireOnce('plugins/controld/includes/ControlDHelper.php');

// Dashboard content here...

echo $page->EndPage();
```

### 8. Create service layer (plugins/controld/services/ControlDService.php)
```php
<?php
/**
 * ControlD Service Layer
 * Provides API for other plugins and themes to interact with ControlD functionality
 */
class ControlDService {
    private $helper;
    private static $instance;
    
    private function __construct() {
        PathHelper::requireOnce('plugins/controld/includes/ControlDHelper.php');
        $this->helper = new ControlDHelper();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getDevicesForUser($user_id) {
        return $this->helper->getUserDevices($user_id);
    }
    
    public function createProfile($user_id, $profile_data) {
        return $this->helper->createProfile($user_id, $profile_data);
    }
    
    public function getFilteringStats($user_id) {
        return $this->helper->getFilteringStats($user_id);
    }
    
    public function isUserActive($user_id) {
        return $this->helper->isUserActive($user_id);
    }
}
```

### 9. Copy necessary assets
```bash
# Copy ControlD-specific CSS/JS from theme to plugin assets (if they exist)
cp theme/sassa/assets/css/controld*.css plugins/controld/assets/css/ 2>/dev/null || true
cp theme/sassa/assets/js/controld*.js plugins/controld/assets/js/ 2>/dev/null || true
# Note: Some assets may be embedded in general theme files
```

### 10. Preserve theme/sassa (no cleanup needed)
- **Keep all files**: Leave ControlD routes in `theme/sassa/serve.php` for backward compatibility
- **Keep all views**: Leave ControlD views in `theme/sassa/views/profile/ctld*` intact
- **Keep all logic**: Leave ControlD logic files in `theme/sassa/logic/ctld*` intact
- **Preserve theme.json**: No changes needed to maintain existing functionality
- **Note**: The hybrid system will use plugin views when the plugin is active

## Result

After implementation, ControlD will be a self-contained plugin that:

- **Works with any theme**: Falcon, blank, sassa, or custom themes
- **Contains all functionality**: Views, logic, data models, admin interface
- **Provides service layer**: Other plugins can integrate ControlD features
- **Maintains all features**: Device management, filtering, profiles, etc.
- **Preserves backward compatibility**: Sassa theme retains all existing functionality
- **Enables flexibility**: Users can choose plugin-provided or theme-customized views

### Benefits of This Approach

**For Users:**
- **No disruption**: Existing sassa theme users experience no changes
- **Enhanced flexibility**: Can switch themes while keeping ControlD functionality
- **Backward compatibility**: All existing URLs and functionality preserved

**For Developers:**
- **Plugin autonomy**: ControlD plugin is fully self-contained
- **Theme independence**: Plugin works with any theme through view resolution
- **Easy customization**: Themes can override plugin views as needed
- **Clean architecture**: Follows hybrid plugin/theme system patterns

**View Resolution Priority:**
1. **Plugin views** (plugins/controld/views/*) - Used when plugin is active
2. **Theme overrides** (theme/current/views/*) - Theme-specific customizations
3. **System fallback** (views/*) - Base system views

The plugin will leverage the hybrid plugin/theme system's view resolution chain to provide its own views while allowing themes to override them when needed, creating maximum flexibility without breaking existing functionality.