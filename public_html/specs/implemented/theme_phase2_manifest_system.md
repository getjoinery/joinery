# Theme System Phase 2: Manifest System Technical Specification

## Overview

Phase 2 builds on the PathHelper foundation from Phase 1 to add theme metadata, enhanced FormWriter selection, and improved developer experience. This specification provides a complete implementation plan for the theme manifest system.

**Key Goals:**
- Add structured theme metadata via `theme.json` files
- Improve FormWriter base class selection using theme metadata
- Provide enhanced helper functions for common theme tasks
- Maintain 100% backward compatibility with existing themes

## Prerequisites

Phase 2 requires Phase 1 completion:
- ✅ All themes use `PathHelper::getThemeFilePath()` consistently
- ✅ Theme path logic consolidated in PathHelper
- ✅ Clean architectural separation achieved

## 1. Theme Manifest System (theme.json)

### 1.1 Manifest File Structure

Each theme can optionally include a `theme.json` file in its root directory (structured to align with plugin.json format for future harmonization):

```json
{
  "name": "falcon",
  "displayName": "Falcon Theme",
  "version": "1.0.0",
  "description": "Bootstrap-based responsive theme with modern UI components",
  "author": "Joinery Team",
  "requires": {
    "php": ">=7.4",
    "joinery": ">=1.0.0"
  },
  "cssFramework": "bootstrap",
  "formWriterBase": "FormWriterMasterFalcon",
  "publicPageBase": "PublicPageFalcon"
}
```

### 1.2 Manifest Field Definitions

**Core Fields (aligned with plugin manifest structure):**
- `name` (string, required) - Internal theme identifier (matches directory name)
- `displayName` (string, optional) - Human-readable name for admin interface
- `version` (string, optional) - Theme version for tracking updates
- `description` (string, optional) - Brief description of theme
- `author` (string, optional) - Theme author/organization
- `requires` (object, optional) - System requirements (php version, joinery version)

**Framework Integration:**
- `cssFramework` (string, optional) - CSS framework used ("bootstrap", "tailwind", "uikit", "custom")
- `formWriterBase` (string, optional) - FormWriter base class to use
- `publicPageBase` (string, optional) - PublicPage base class to use


### 1.3 Manifest Validation Schema

The system will validate manifests against this structure but won't enforce it (graceful degradation for missing files).

## 2. ThemeHelper Class Implementation

### 2.1 Class Structure

Create `/includes/ThemeHelper.php`:

```php
<?php
/**
 * ThemeHelper - Manages theme metadata and provides helper functions
 * 
 * Note: This class structure is designed to potentially extend a future
 * ComponentHelper base class for harmonized plugin/theme infrastructure
 */
class ThemeHelper {
    private $themeName;
    private $data = [];
    private $manifestPath;
    
    private static $instances = [];
    
    private function __construct($themeName) {
        $this->themeName = $themeName;
        $this->manifestPath = PathHelper::getIncludePath("theme/{$themeName}/theme.json");
        $this->loadManifest();
    }
    
    /**
     * Get ThemeHelper instance for a theme (singleton pattern)
     */
    public static function getInstance($themeName = null) {
        if (!$themeName) {
            $settings = Globalvars::get_instance();
            $themeName = $settings->get_setting('theme_template', true, true);
        }
        
        if (!isset(self::$instances[$themeName])) {
            self::$instances[$themeName] = new self($themeName);
        }
        
        return self::$instances[$themeName];
    }
    
    /**
     * Load theme manifest or create defaults
     */
    private function loadManifest() {
        // Try to load manifest file
        if (file_exists($this->manifestPath)) {
            $content = file_get_contents($this->manifestPath);
            $data = json_decode($content, true);
            
            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                $this->data = array_merge($this->getDefaultData(), $data);
                return;
            }
        }
        
        $this->data = $data;
    }
    
    
    
    
    
    
    // Public getters
    public function get($key, $default = null) {
        return $this->data[$key] ?? $default;
    }
    
    public function getName() {
        return $this->data['name'];
    }
    
    public function getDisplayName() {
        return $this->data['displayName'];
    }
    
    public function getVersion() {
        return $this->data['version'];
    }
    
    public function getDescription() {
        return $this->data['description'];
    }
    
    public function getCssFramework() {
        return $this->data['cssFramework'];
    }
    
    public function getFormWriterBase() {
        return $this->data['formWriterBase'];
    }
    
    public function getPublicPageBase() {
        return $this->data['publicPageBase'];
    }
    
    public function toArray() {
        return $this->data;
    }
    
    // === STATIC HELPER METHODS ===
    
    /**
     * Get URL to theme asset
     */
    public static function asset($path, $themeName = null) {
        if (!$themeName) {
            $settings = Globalvars::get_instance();
            $themeName = $settings->get_setting('theme_template', true, true);
        }
        
        // Check if asset exists in theme
        $themeAssetPath = PathHelper::getIncludePath("theme/{$themeName}/{$path}");
        if (file_exists($themeAssetPath)) {
            return "/theme/{$themeName}/{$path}";
        }
        
        // Fallback to base path
        $basePath = PathHelper::getIncludePath($path);
        if (file_exists($basePath)) {
            return "/{$path}";
        }
        
        // Return requested path anyway (might be external or dynamically generated)
        return "/theme/{$themeName}/{$path}";
    }
    
    /**
     * Include file from theme with fallback to base
     */
    public static function includeFile($path, $themeName = null) {
        $fullPath = PathHelper::getThemeFilePath(
            basename($path), 
            dirname($path), 
            'system', 
            $themeName
        );
        
        if ($fullPath && file_exists($fullPath)) {
            require_once($fullPath);
            return true;
        }
        
        return false;
    }
    
    /**
     * Get theme configuration value
     */
    public static function config($key, $default = null, $themeName = null) {
        $helper = self::getInstance($themeName);
        return $helper->get($key, $default);
    }
    
    /**
     * Get all available themes with their helpers
     */
    public static function getAvailableThemes() {
        $themes = [];
        $themeDir = PathHelper::getIncludePath('theme');
        
        if (is_dir($themeDir)) {
            $directories = glob($themeDir . '/*', GLOB_ONLYDIR);
            foreach ($directories as $dir) {
                $themeName = basename($dir);
                try {
                    $themes[$themeName] = self::getInstance($themeName);
                } catch (Exception $e) {
                    // Skip themes without valid manifests
                    continue;
                }
            }
        }
        
        return $themes;
    }
}
```

## 3. Enhanced LibraryFunctions Integration

### 3.1 Update get_formwriter_object()

Modify the existing method in `/includes/LibraryFunctions.php`:

```php
static function get_formwriter_object($form_id = 'form1', $override_name=NULL, $override_path=NULL){
    // Keep existing override logic
    if($override_path){
        require_once($override_path);
        $formwriter = new FormWriter($form_id);
        return $formwriter;
    }
    
    if($override_name == 'admin'){
        PathHelper::requireOnce('includes/FormWriterMasterBootstrap.php');
        return new FormWriterMasterBootstrap($form_id);
    }
    else if($override_name == 'tailwind'){
        PathHelper::requireOnce('includes/FormWriterMasterTailwind.php');
        return new FormWriterMasterTailwind($form_id);
    }
    
    // NEW: Use theme manifest for enhanced selection
    try {
        $settings = Globalvars::get_instance();
        $themeName = $settings->get_setting('theme_template', true, true);
        
        if ($themeName) {
            PathHelper::requireOnce('includes/ThemeHelper.php');
            $themeHelper = ThemeHelper::getInstance($themeName);
            
            // Check if theme has custom FormWriter
            $formWriterPath = PathHelper::getThemeFilePath('FormWriter.php', 'includes');
            if ($formWriterPath && file_exists($formWriterPath)) {
                require_once($formWriterPath);
                return new FormWriter($form_id);
            }
            
            // Use base class from theme manifest
            $baseClass = $themeHelper->getFormWriterBase();
            if ($baseClass && $baseClass !== 'FormWriter') {
                $baseClassPath = PathHelper::getIncludePath("includes/{$baseClass}.php");
                if (file_exists($baseClassPath)) {
                    require_once($baseClassPath);
                    return new $baseClass($form_id);
                }
            }
        }
    } catch (Exception $e) {
        // Fall through to legacy method
        error_log("ThemeHelper error: " . $e->getMessage());
    }
    
    // LEGACY: Existing fallback code remains unchanged
    $theme_form = PathHelper::getThemeFilePath('FormWriter.php', 'includes', 'system');
    
    if($theme_form){
        require_once($theme_form);
        return new FormWriter($form_id);
    }
    
    // Final default
    PathHelper::requireOnce('includes/FormWriterMasterBootstrap.php');
    return new FormWriterMasterBootstrap($form_id);
}
```

## 4. Sample Theme Manifests

### 4.1 Falcon Theme Manifest

Create `/theme/falcon/theme.json`:

```json
{
  "name": "falcon",
  "displayName": "Falcon Theme",
  "version": "1.0.0",
  "description": "Bootstrap-based responsive theme with modern UI components",
  "author": "Joinery Team",
  "requires": {
    "php": ">=7.4",
    "joinery": ">=1.0.0"
  },
  "cssFramework": "bootstrap",
  "formWriterBase": "FormWriterMasterFalcon",
  "publicPageBase": "PublicPageFalcon",
  "assets": {
    "css": [
      "includes/vendors/bootstrap/css/bootstrap.min.css",
      "includes/css/theme.css"
    ],
    "js": [
      "includes/vendors/bootstrap/js/bootstrap.bundle.min.js",
      "includes/js/theme.js"
    ],
    "images": "includes/images/"
  },
  "features": {
    "responsive": true,
    "darkMode": false,
    "customColors": true
  }
}
```

### 4.2 Tailwind Theme Manifest

Create `/theme/tailwind/theme.json`:

```json
{
  "name": "tailwind",
  "displayName": "Tailwind Theme",
  "version": "1.0.0",
  "description": "Tailwind CSS-based theme (legacy)",
  "author": "Joinery Team",
  "requires": {
    "php": ">=7.4",
    "joinery": ">=1.0.0"
  },
  "cssFramework": "tailwind",
  "formWriterBase": "FormWriterMasterTailwind",
  "publicPageBase": "PublicPageFalcon",
  "assets": {
    "css": ["includes/css/tailwind.css"],
    "js": [],
    "images": "includes/images/"
  },
  "features": {
    "responsive": true,
    "darkMode": false,
    "customColors": true
  }
}
```

## 5. Implementation Steps

### 5.1 Phase 2.1: Core Infrastructure (Low Risk)
1. **Add ThemeHelper class** - Create `/includes/ThemeHelper.php`
2. **Create theme manifests** - Add theme.json files for all existing themes

### 5.2 Phase 2.2: FormWriter Enhancement (Medium Risk)
1. **Update LibraryFunctions** - Modify `get_formwriter_object()` to use ThemeHelper
2. **Test form generation** - Ensure all themes still work

### 5.3 Phase 2.3: Manifest Creation (Low Risk)
1. **Create manifests** - Add theme.json for active themes
2. **Test manifest loading** - Verify ThemeInfo reads correctly
3. **Test enhanced features** - Use helper functions in theme files

### 5.4 Phase 2.4: Integration (Medium Risk)
1. **Update theme files** - Optionally use new helper functions
2. **Add asset rendering** - Use `theme_css_links()` and `theme_js_scripts()`
3. **Test thoroughly** - All themes, all form types, all pages

## 6. Testing Strategy

### 6.1 Automated Tests

Create `/utils/test_theme_manifest_system.php`:

```php
<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
PathHelper::requireOnce('includes/ThemeHelper.php');

echo "Testing Theme Manifest System\n";
echo "=============================\n\n";

// Test 1: ThemeHelper loading
$themes = ['falcon', 'tailwind', 'sassa'];
foreach ($themes as $themeName) {
    try {
        $helper = ThemeHelper::getInstance($themeName);
        echo "Theme: {$themeName}\n";
        echo "  Display Name: " . $helper->getDisplayName() . "\n";
        echo "  CSS Framework: " . $helper->getCssFramework() . "\n";
        echo "  FormWriter Base: " . $helper->getFormWriterBase() . "\n";
        echo "\n";
    } catch (Exception $e) {
        echo "Theme: {$themeName} - ERROR: " . $e->getMessage() . "\n\n";
    }
}

// Test 2: Helper methods
echo "Testing Helper Methods:\n";
echo "Current theme info: " . ThemeHelper::config('displayName') . "\n";
echo "Theme asset URL: " . ThemeHelper::asset('images/logo.png') . "\n";

echo "\n✓ All tests completed!\n";
```

### 6.2 Manual Testing Checklist

**Theme Switching:**
- [ ] Switch between themes with manifests
- [ ] Switch to themes without manifests
- [ ] Verify forms render correctly in each theme
- [ ] Check asset loading (CSS/JS)

**FormWriter Selection:**
- [ ] Admin forms use correct FormWriter
- [ ] Public forms use theme-specific FormWriter
- [ ] Fallback works for missing base classes

**Helper Methods:**
- [ ] `ThemeHelper::getInstance()` returns correct data
- [ ] `ThemeHelper::asset()` finds assets correctly
- [ ] `ThemeHelper::includeFile()` works with fallback

## 7. Migration Guide

### 7.1 For Existing Themes

**Optional Upgrade Path:**
1. Create `theme.json` manifest (optional but recommended)
2. Use helper functions in templates (optional)
3. Update asset loading to use `theme_css_links()`/`theme_js_scripts()` (optional)

**Breaking Change:**
- All themes must have a theme.json manifest file
- Themes without manifests will throw an exception
- Existing themes need to add manifest files

### 7.2 For New Themes

**Recommended Structure (aligned with plugin conventions):**
```
/theme/my-theme/
├── theme.json          # Theme manifest (similar to plugin.json)
├── includes/
│   ├── FormWriter.php  # Optional: Custom FormWriter
│   └── PublicPage.php  # Optional: Custom PublicPage
├── assets/             # Standardized asset location
│   ├── css/
│   ├── js/
│   └── images/
├── views/              # Optional: Override views (matches plugin structure)
├── logic/              # Optional: Override logic (matches plugin structure)
├── admin/              # Optional: Admin overrides (aligns with plugin 'adm')
└── migrations/         # Optional: Theme migrations (matches plugin structure)
```


## 8. Future Enhancements

Phase 2 provides foundation for:
- **Theme inheritance** - Parent/child theme relationships
- **Asset optimization** - Minification, concatenation
- **Cross-component dependencies** - Themes depending on plugins or other themes
- **Unified component management** - Shared infrastructure with plugin system
- **Theme marketplace** - Package, share, install themes (aligned with plugin marketplace)
- **Advanced validation** - Compatibility checking, dependency resolution
- **CLI tools** - `theme create`, `theme validate`, `theme package` (potentially unified with plugin CLI)

## Conclusion

Phase 2 adds significant value to the theme system while maintaining 100% backward compatibility. The manifest system provides structured theme metadata, enhanced FormWriter selection improves reliability, and helper functions simplify theme development.

The implementation is designed to be incremental and low-risk, building naturally on the PathHelper foundation from Phase 1.