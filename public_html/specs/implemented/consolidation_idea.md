# Plugin and Theme System Consolidation Proposal

## Overview

After analyzing the plugin system and theme system specifications, several common patterns emerge that could be standardized into a unified base class architecture. This would reduce code duplication, improve maintainability, and create a consistent API for all system components.

## Common Patterns Between Plugins and Themes

### 1. Manifest Structure
Both systems use nearly identical JSON manifest structures:

**Plugins (plugin.json):**
```json
{
  "name": "string",
  "displayName": "string", 
  "version": "string",
  "description": "string",
  "author": "string",
  "requires": {"php": ">=7.4", "joinery": ">=1.0.0"}
}
```

**Themes (theme.json):**
```json
{
  "name": "string",
  "displayName": "string",
  "version": "string", 
  "description": "string",
  "author": "string",
  "requires": {"php": ">=7.4", "joinery": ">=1.0.0"}
}
```

### 2. Directory Structure
Both follow similar MVC patterns:
- `/data/` - Data models
- `/logic/` - Business logic
- `/views/` - Templates
- `/migrations/` - Database changes
- `/includes/` - Helper classes

### 3. Singleton Pattern with Caching
Both ThemeHelper and plugin system would benefit from:
- Instance caching by component name
- Lazy loading of manifests
- Similar getInstance() patterns

### 4. Path Resolution Needs
Both need:
- Finding files with fallback mechanisms
- Asset path resolution
- Include path management

## Proposed Unified Architecture

### ComponentBase - Abstract Base Class

Create `/includes/ComponentBase.php`:

```php
<?php
/**
 * ComponentBase - Abstract base class for plugins and themes
 * Provides common functionality for manifest loading, path resolution, and lifecycle management
 */
abstract class ComponentBase {
    protected $name;
    protected $manifestData = [];
    protected $manifestPath;
    protected $componentType; // 'plugin' or 'theme'
    protected $basePath;
    
    private static $instances = [];
    
    /**
     * Load component manifest from JSON file
     */
    protected function loadManifest() {
        if (file_exists($this->manifestPath)) {
            $content = file_get_contents($this->manifestPath);
            $data = json_decode($content, true);
            
            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                $this->manifestData = $data;
                return true;
            }
        }
        return false;
    }
    
    /**
     * Get manifest field value
     */
    public function get($key, $default = null) {
        return $this->manifestData[$key] ?? $default;
    }
    
    // Common getters for standard manifest fields
    public function getName() { 
        return $this->manifestData['name'] ?? $this->name; 
    }
    
    public function getDisplayName() { 
        return $this->manifestData['displayName'] ?? ''; 
    }
    
    public function getVersion() { 
        return $this->manifestData['version'] ?? ''; 
    }
    
    public function getDescription() { 
        return $this->manifestData['description'] ?? ''; 
    }
    
    public function getAuthor() { 
        return $this->manifestData['author'] ?? ''; 
    }
    
    public function getRequirements() {
        return $this->manifestData['requires'] ?? [];
    }
    
    /**
     * Check if component meets system requirements
     */
    public function checkRequirements() {
        $requirements = $this->getRequirements();
        $errors = [];
        
        // Check PHP version
        if (isset($requirements['php'])) {
            if (!version_compare(PHP_VERSION, $requirements['php'], '>=')) {
                $errors[] = "PHP {$requirements['php']} or higher required";
            }
        }
        
        // Check Joinery version
        if (isset($requirements['joinery'])) {
            // Implementation would check actual Joinery version
        }
        
        return empty($errors) ? true : $errors;
    }
    
    /**
     * Get path to component asset
     */
    public function getAssetPath($path) {
        return '/' . $this->basePath . '/' . $path;
    }
    
    /**
     * Get full include path for component file
     */
    public function getIncludePath($path) {
        return PathHelper::getIncludePath($this->basePath . '/' . $path);
    }
    
    /**
     * Include file from component with optional fallback
     */
    public function includeFile($path, $fallbackPath = null) {
        $fullPath = $this->getIncludePath($path);
        
        if (file_exists($fullPath)) {
            require_once($fullPath);
            return true;
        }
        
        if ($fallbackPath && file_exists($fallbackPath)) {
            require_once($fallbackPath);
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if file exists in component
     */
    public function fileExists($path) {
        return file_exists($this->getIncludePath($path));
    }
    
    /**
     * Get all files matching pattern in component
     */
    public function getFiles($pattern) {
        $basePath = $this->getIncludePath('');
        return glob($basePath . '/' . $pattern);
    }
    
    /**
     * Export manifest data as array
     */
    public function toArray() {
        return $this->manifestData;
    }
    
    // Abstract methods that subclasses must implement
    abstract public function initialize();
    abstract public function isActive();
    abstract public function validate();
}
```

### ThemeHelper - Theme-Specific Implementation

Update `/includes/ThemeHelper.php`:

```php
<?php
require_once(__DIR__ . '/ComponentBase.php');

/**
 * ThemeHelper - Manages theme metadata and provides helper functions
 * Extends ComponentBase for common functionality
 */
class ThemeHelper extends ComponentBase {
    protected $componentType = 'theme';
    
    private static $instances = [];
    
    private function __construct($themeName) {
        $this->name = $themeName;
        $this->basePath = "theme/{$themeName}";
        $this->manifestPath = PathHelper::getIncludePath("{$this->basePath}/theme.json");
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
     * Initialize theme
     */
    public function initialize() {
        // Load theme functions.php if exists
        $functionsFile = $this->getIncludePath('functions.php');
        if (file_exists($functionsFile)) {
            require_once($functionsFile);
        }
        
        return true;
    }
    
    /**
     * Check if theme is currently active
     */
    public function isActive() {
        $settings = Globalvars::get_instance();
        $activeTheme = $settings->get_setting('theme_template', true, true);
        return $this->name === $activeTheme;
    }
    
    /**
     * Validate theme structure and requirements
     */
    public function validate() {
        $errors = [];
        
        // Check requirements
        $reqCheck = $this->checkRequirements();
        if ($reqCheck !== true) {
            $errors = array_merge($errors, $reqCheck);
        }
        
        // Check for required directories
        if (!is_dir($this->getIncludePath(''))) {
            $errors[] = "Theme directory not found";
        }
        
        // Check for manifest
        if (empty($this->manifestData)) {
            $errors[] = "Theme manifest (theme.json) is required";
        }
        
        return empty($errors) ? true : $errors;
    }
    
    // Theme-specific methods
    public function getFormWriterBase() { 
        return $this->manifestData['formWriterBase'] ?? null; 
    }
    
    public function getCssFramework() {
        return $this->manifestData['cssFramework'] ?? null;
    }
    
    public function getPublicPageBase() {
        return $this->manifestData['publicPageBase'] ?? null;
    }
    
    // Static helper methods (unchanged from original)
    public static function asset($path, $themeName = null) {
        $instance = self::getInstance($themeName);
        return $instance->getAssetPath($path);
    }
    
    public static function includeFile($path, $themeName = null) {
        $instance = self::getInstance($themeName);
        return $instance->includeFile($path);
    }
    
    public static function config($key, $default = null, $themeName = null) {
        $instance = self::getInstance($themeName);
        return $instance->get($key, $default);
    }
}
```

### PluginHelper - Plugin-Specific Implementation

Create `/includes/PluginHelper.php`:

```php
<?php
require_once(__DIR__ . '/ComponentBase.php');

/**
 * PluginHelper - Manages plugin metadata and provides helper functions
 * Extends ComponentBase for common functionality
 */
class PluginHelper extends ComponentBase {
    protected $componentType = 'plugin';
    
    private static $instances = [];
    
    private function __construct($pluginName) {
        $this->name = $pluginName;
        $this->basePath = "plugins/{$pluginName}";
        $this->manifestPath = PathHelper::getIncludePath("{$this->basePath}/plugin.json");
        $this->loadManifest();
    }
    
    /**
     * Get PluginHelper instance for a plugin (singleton pattern)
     */
    public static function getInstance($pluginName) {
        if (!isset(self::$instances[$pluginName])) {
            self::$instances[$pluginName] = new self($pluginName);
        }
        
        return self::$instances[$pluginName];
    }
    
    /**
     * Initialize plugin
     */
    public function initialize() {
        // Load plugin initialization file if exists
        $initFile = $this->getIncludePath('init.php');
        if (file_exists($initFile)) {
            require_once($initFile);
        }
        
        // Register plugin routes if serve.php exists
        if ($this->hasCustomRouting()) {
            // Registration would happen here
        }
        
        return true;
    }
    
    /**
     * Check if plugin is currently active
     */
    public function isActive() {
        // Check database or configuration for plugin activation status
        // This would integrate with existing plugin activation system
        $settings = Globalvars::get_instance();
        $activePlugins = $settings->get_setting('active_plugins', true, true);
        
        if (is_array($activePlugins)) {
            return in_array($this->name, $activePlugins);
        }
        
        return false;
    }
    
    /**
     * Validate plugin structure and requirements
     */
    public function validate() {
        $errors = [];
        
        // Check requirements
        $reqCheck = $this->checkRequirements();
        if ($reqCheck !== true) {
            $errors = array_merge($errors, $reqCheck);
        }
        
        // Check for required directories
        if (!is_dir($this->getIncludePath(''))) {
            $errors[] = "Plugin directory not found";
        }
        
        // Check for manifest
        if (empty($this->manifestData)) {
            $errors[] = "Plugin manifest (plugin.json) not found or invalid";
        }
        
        return empty($errors) ? true : $errors;
    }
    
    // Plugin-specific methods
    
    /**
     * Check if plugin has admin interface
     */
    public function hasAdminInterface() {
        return is_dir($this->getIncludePath('adm'));
    }
    
    /**
     * Check if plugin has custom routing
     */
    public function hasCustomRouting() {
        return file_exists($this->getIncludePath('serve.php'));
    }
    
    /**
     * Get plugin admin menu items
     */
    public function getAdminMenuItems() {
        return $this->manifestData['adminMenu'] ?? [];
    }
    
    /**
     * Get plugin API endpoints
     */
    public function getApiEndpoints() {
        return $this->manifestData['apiEndpoints'] ?? [];
    }
    
    /**
     * Check if plugin has migrations
     */
    public function hasMigrations() {
        return file_exists($this->getIncludePath('migrations/migrations.php'));
    }
    
    /**
     * Get plugin migration file path
     */
    public function getMigrationsPath() {
        return $this->getIncludePath('migrations/migrations.php');
    }
}
```

### ComponentManager - Unified Registry

Create `/includes/ComponentManager.php`:

```php
<?php
/**
 * ComponentManager - Registry and manager for all system components (themes and plugins)
 * Provides unified access to component functionality
 */
class ComponentManager {
    private static $instance;
    private $themes = [];
    private $plugins = [];
    private $componentsLoaded = false;
    
    private function __construct() {
        // Private constructor for singleton
    }
    
    /**
     * Get ComponentManager instance (singleton)
     */
    public static function getInstance() {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Get theme helper instance
     */
    public function getTheme($name = null) {
        if (!$name) {
            $settings = Globalvars::get_instance();
            $name = $settings->get_setting('theme_template', true, true);
        }
        
        if (!isset($this->themes[$name])) {
            PathHelper::requireOnce('includes/ThemeHelper.php');
            $this->themes[$name] = ThemeHelper::getInstance($name);
        }
        
        return $this->themes[$name];
    }
    
    /**
     * Get plugin helper instance
     */
    public function getPlugin($name) {
        if (!isset($this->plugins[$name])) {
            PathHelper::requireOnce('includes/PluginHelper.php');
            $this->plugins[$name] = PluginHelper::getInstance($name);
        }
        
        return $this->plugins[$name];
    }
    
    /**
     * Discover all available components
     */
    private function discoverComponents() {
        if ($this->componentsLoaded) {
            return;
        }
        
        // Discover themes
        $themeDir = PathHelper::getIncludePath('theme');
        if (is_dir($themeDir)) {
            $directories = glob($themeDir . '/*', GLOB_ONLYDIR);
            foreach ($directories as $dir) {
                $themeName = basename($dir);
                try {
                    $this->themes[$themeName] = $this->getTheme($themeName);
                } catch (Exception $e) {
                    error_log("Failed to load theme {$themeName}: " . $e->getMessage());
                }
            }
        }
        
        // Discover plugins
        $pluginDir = PathHelper::getIncludePath('plugins');
        if (is_dir($pluginDir)) {
            $directories = glob($pluginDir . '/*', GLOB_ONLYDIR);
            foreach ($directories as $dir) {
                $pluginName = basename($dir);
                try {
                    $this->plugins[$pluginName] = $this->getPlugin($pluginName);
                } catch (Exception $e) {
                    error_log("Failed to load plugin {$pluginName}: " . $e->getMessage());
                }
            }
        }
        
        $this->componentsLoaded = true;
    }
    
    /**
     * Get all components of a specific type
     */
    public function getAllComponents($type = null) {
        $this->discoverComponents();
        
        switch($type) {
            case 'theme':
                return $this->themes;
            case 'plugin':
                return $this->plugins;
            default:
                return array_merge($this->themes, $this->plugins);
        }
    }
    
    /**
     * Get all active components
     */
    public function getActiveComponents($type = null) {
        $components = $this->getAllComponents($type);
        $active = [];
        
        foreach ($components as $name => $component) {
            if ($component->isActive()) {
                $active[$name] = $component;
            }
        }
        
        return $active;
    }
    
    /**
     * Validate all components
     */
    public function validateAll() {
        $this->discoverComponents();
        $results = [
            'themes' => [],
            'plugins' => []
        ];
        
        foreach ($this->themes as $name => $theme) {
            $validation = $theme->validate();
            $results['themes'][$name] = $validation === true ? 'valid' : $validation;
        }
        
        foreach ($this->plugins as $name => $plugin) {
            $validation = $plugin->validate();
            $results['plugins'][$name] = $validation === true ? 'valid' : $validation;
        }
        
        return $results;
    }
    
    /**
     * Initialize all active components
     */
    public function initializeActive() {
        $active = $this->getActiveComponents();
        $results = [];
        
        foreach ($active as $name => $component) {
            try {
                $component->initialize();
                $results[$name] = 'initialized';
            } catch (Exception $e) {
                $results[$name] = 'error: ' . $e->getMessage();
            }
        }
        
        return $results;
    }
    
    /**
     * Get component by type and name
     */
    public function getComponent($type, $name) {
        switch($type) {
            case 'theme':
                return $this->getTheme($name);
            case 'plugin':
                return $this->getPlugin($name);
            default:
                throw new Exception("Unknown component type: {$type}");
        }
    }
}
```

## Benefits of Unification

### 1. Code Reusability
- Single codebase for manifest loading and validation
- Shared path resolution logic
- Common requirement checking

### 2. Consistent API
- Same methods across themes and plugins
- Predictable behavior for developers
- Easier to learn and use

### 3. Improved Maintainability
- Fix bugs in one place
- Add features to all components at once
- Reduce testing surface area

### 4. Future Extensibility
- Easy to add new component types (e.g., "modules", "widgets")
- Shared infrastructure for new features
- Consistent patterns for expansion

### 5. Better Developer Experience
- One set of documentation
- Familiar patterns across components
- Unified tooling possibilities

## Migration Strategy

### Phase 1: Create Base Infrastructure
1. Implement ComponentBase class
2. Create ComponentManager registry
3. Add validation and testing utilities

### Phase 2: Adapt Existing Code
1. Update ThemeHelper to extend ComponentBase
2. Create PluginHelper extending ComponentBase
3. Update LibraryFunctions to use ComponentManager

### Phase 3: Gradual Migration
1. Update existing code to use new helpers
2. Maintain backward compatibility during transition
3. Deprecate old methods gracefully

### Phase 4: Enhanced Features
1. Add dependency resolution
2. Implement lifecycle hooks
3. Create unified CLI tools

## Additional Standardizations

### 1. Unified Migration System
Both plugins and themes could share:
- Migration runner
- Version tracking
- Rollback capabilities

### 2. Asset Management
Centralized system for:
- CSS/JS bundling
- Asset versioning
- CDN integration

### 3. Dependency Resolution
Common system for:
- Checking PHP version
- Verifying component dependencies
- Handling conflicts

### 4. Lifecycle Hooks
Standard events for:
- activation/deactivation
- installation/uninstallation
- updates/upgrades

### 5. Error Handling
Consistent approach to:
- Missing components
- Failed validations
- Runtime errors

## Testing Strategy

### Unit Tests
```php
class ComponentBaseTest extends PHPUnit\Framework\TestCase {
    public function testManifestLoading() {
        // Test manifest parsing
    }
    
    public function testRequirementChecking() {
        // Test version comparisons
    }
    
    public function testPathResolution() {
        // Test file finding
    }
}
```

### Integration Tests
- Test theme switching with new system
- Test plugin activation/deactivation
- Test component discovery
- Test fallback mechanisms

## Conclusion

This unified architecture would significantly reduce code duplication between the plugin and theme systems while maintaining all existing functionality. The abstraction through ComponentBase provides a clean, extensible foundation for future enhancements while the ComponentManager offers centralized access to all system components.

The migration can be done incrementally without breaking existing code, and the benefits include better maintainability, consistent APIs, and a more robust foundation for future development.