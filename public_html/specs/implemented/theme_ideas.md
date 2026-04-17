# Theme System Future Ideas: Plugin-Theme Harmonization

## Overview

This document captures future enhancement ideas for harmonizing the theme and plugin systems. These ideas build on the existing plugin infrastructure to create a unified component management system while maintaining the distinction between themes (presentation) and plugins (functionality).

## 1. Unified Component Infrastructure

### 1.1 Shared Base Classes

Create abstract base classes that both plugins and themes can extend:

```php
// Base component management
abstract class ComponentInfo {
    protected $componentName;
    protected $componentType; // 'plugin' or 'theme'
    protected $data = [];
    protected $manifestPath;
    
    abstract protected function getDefaultData();
    abstract protected function detectCapabilities();
    
    public function loadManifest() {
        // Shared manifest loading logic
    }
    
    public function get($key, $default = null) {
        return $this->data[$key] ?? $default;
    }
}

// Plugin and Theme specific implementations
class PluginInfo extends ComponentInfo {
    protected $componentType = 'plugin';
    // Plugin-specific implementation
}

class ThemeInfo extends ComponentInfo {
    protected $componentType = 'theme';
    // Theme-specific implementation (already specified in phase 2)
}
```

### 1.2 Component Version Management

Extend the existing `PluginVersionDetector` to handle both plugins and themes:

```php
abstract class ComponentVersionDetector {
    protected $componentType;
    protected $componentName;
    
    public function detectVersion() {
        // Shared version detection logic
    }
    
    public function checkForUpdates() {
        // Shared update checking
    }
}

class PluginVersionDetector extends ComponentVersionDetector {
    protected $componentType = 'plugin';
}

class ThemeVersionDetector extends ComponentVersionDetector {
    protected $componentType = 'theme';
}
```

### 1.3 Component Dependency Validation

Leverage the sophisticated `PluginDependencyValidator` for themes:

```php
abstract class ComponentDependencyValidator {
    protected $componentType;
    
    public function validateDependencies($manifest) {
        // Shared dependency validation
    }
    
    public function checkCircularDependencies($components) {
        // Shared circular dependency detection
    }
    
    public function resolveDependencyTree($component) {
        // Shared dependency tree resolution
    }
}
```

## 2. Cross-Component Dependencies

### 2.1 Theme-Plugin Dependencies

Allow themes to declare dependencies on plugins:

```json
{
  "name": "ecommerce-theme",
  "depends": {
    "plugins": ["shopping-cart", "payment-gateway"],
    "themes": ["parent-theme"]
  }
}
```

### 2.2 Plugin-Theme Compatibility

Allow plugins to declare theme compatibility:

```json
{
  "name": "advanced-forms",
  "compatible": {
    "themes": ["falcon", "tailwind"],
    "cssFrameworks": ["bootstrap", "tailwind"]
  }
}
```

### 2.3 Unified Dependency Resolution

```php
class ComponentDependencyResolver {
    public function resolveAllDependencies() {
        // Resolve dependencies across both plugins and themes
        // Handle conflicts between components
        // Generate load order
    }
}
```

## 3. Unified Asset Management

### 3.1 ComponentAssetManager

Create a unified asset management system for both plugins and themes:

```php
class ComponentAssetManager {
    private $loadedAssets = [];
    
    public function registerAsset($componentName, $componentType, $assetType, $path, $dependencies = []) {
        // Register an asset with dependency tracking
    }
    
    public function loadAssets($componentName, $componentType, $assetType = null) {
        // Load assets in dependency order
    }
    
    public function getAssetUrl($componentName, $componentType, $assetPath) {
        // Generate proper URL for asset
    }
    
    public function renderAssetTags($componentName, $componentType, $assetType) {
        // Render HTML tags for assets
    }
    
    public function optimizeAssets($environment = 'production') {
        // Minify, concatenate, cache assets
    }
}
```

### 3.2 Asset Declaration in Manifests

Standardized asset declaration for both plugins and themes:

```json
{
  "assets": {
    "css": [
      {
        "path": "assets/css/main.css",
        "dependencies": ["bootstrap"],
        "media": "screen",
        "priority": 100
      }
    ],
    "js": [
      {
        "path": "assets/js/app.js",
        "dependencies": ["jquery"],
        "defer": true,
        "priority": 50
      }
    ]
  }
}
```

## 4. Migration System Extension

### 4.1 Theme Migrations

Extend the plugin migration system to themes:

```php
class ThemeMigrationRunner extends ComponentMigrationRunner {
    protected $componentType = 'theme';
    
    public function runMigrations($themeName) {
        // Run theme-specific migrations
        // Handle asset optimization
        // Update configuration
    }
}
```

### 4.2 Cross-Component Migrations

Handle migrations that affect both plugins and themes:

```php
class CrossComponentMigration {
    public function migrate() {
        // Update plugin data structures
        // Update theme configurations
        // Ensure compatibility
    }
}
```

## 5. Unified Helper Functions

### 5.1 Generic Component Helpers

Create base helper functions that work for both plugins and themes:

```php
// Get component information
function component_info($name, $type = 'auto') {
    if ($type === 'auto') {
        $type = detect_component_type($name);
    }
    
    $className = ucfirst($type) . 'Info';
    return new $className($name);
}

// Get component asset URL
function component_asset($name, $type, $path) {
    $assetManager = ComponentAssetManager::getInstance();
    return $assetManager->getAssetUrl($name, $type, $path);
}

// Include file from component
function component_include($name, $type, $path) {
    $componentPath = get_component_path($name, $type);
    $fullPath = $componentPath . '/' . $path;
    
    if (file_exists($fullPath)) {
        require_once($fullPath);
        return true;
    }
    return false;
}

// Check if component is active
function is_component_active($name, $type) {
    $manager = ComponentManager::getInstance();
    return $manager->isActive($name, $type);
}

// Get all components of a type
function get_components($type = null) {
    $manager = ComponentManager::getInstance();
    return $manager->getComponents($type);
}
```

### 5.2 Type-Specific Wrappers

Maintain backwards compatibility with type-specific functions:

```php
// Plugin-specific wrappers
function plugin_info($name) {
    return component_info($name, 'plugin');
}

function plugin_asset($name, $path) {
    return component_asset($name, 'plugin', $path);
}

// Theme-specific wrappers (already defined in phase 2)
function theme_info($name) {
    return component_info($name, 'theme');
}

function theme_asset($path, $name = null) {
    if (!$name) {
        $name = get_current_theme();
    }
    return component_asset($name, 'theme', $path);
}
```

## 6. Unified Component Manager

### 6.1 ComponentManager Class

Create a central manager for all components:

```php
class ComponentManager {
    private static $instance;
    private $plugins = [];
    private $themes = [];
    private $loadOrder = [];
    
    public function discoverComponents() {
        // Scan for all plugins and themes
        // Load manifests
        // Validate dependencies
        // Determine load order
    }
    
    public function loadComponent($name, $type) {
        // Load a specific component
        // Check dependencies
        // Initialize component
    }
    
    public function getComponent($name, $type) {
        // Get component instance
    }
    
    public function isActive($name, $type) {
        // Check if component is active
    }
    
    public function enableComponent($name, $type) {
        // Enable a component
        // Run migrations
        // Update configuration
    }
    
    public function disableComponent($name, $type) {
        // Disable a component
        // Check for dependent components
        // Clean up resources
    }
}
```

### 6.2 Component Lifecycle Hooks

Standardized lifecycle hooks for both plugins and themes:

```php
interface ComponentLifecycle {
    public function onActivate();      // Called when component is activated
    public function onDeactivate();    // Called when component is deactivated
    public function onInstall();       // Called on first installation
    public function onUninstall();     // Called when being removed
    public function onUpdate($oldVersion, $newVersion); // Called on updates
    public function onInit();          // Called on every load
}
```

## 7. Component Marketplace Infrastructure

### 7.1 Package Format

Standardized package format for distribution:

```
component-package.zip
├── manifest.json          # Component manifest
├── README.md             # Documentation
├── LICENSE               # License file
├── src/                  # Source code
├── assets/               # Static assets
├── migrations/           # Database migrations
└── tests/                # Test suite
```

### 7.2 Component Repository

API for component discovery and installation:

```php
class ComponentRepository {
    public function search($query, $type = null) {
        // Search for components
    }
    
    public function getDetails($name, $type) {
        // Get component details
    }
    
    public function download($name, $version, $type) {
        // Download component package
    }
    
    public function install($packagePath) {
        // Install from package
    }
}
```

## 8. Development Tools

### 8.1 Component CLI

Unified CLI for component management:

```bash
# Component creation
php utils/component.php create plugin my-plugin
php utils/component.php create theme my-theme --parent=falcon

# Component management
php utils/component.php list [--type=plugin|theme]
php utils/component.php enable my-plugin
php utils/component.php disable my-theme

# Component validation
php utils/component.php validate my-component
php utils/component.php check-dependencies

# Component packaging
php utils/component.php package my-component
php utils/component.php publish my-component
```

### 8.2 Component Scaffolding

Templates for quick component creation:

```php
class ComponentScaffold {
    public function createPlugin($name, $options = []) {
        // Generate plugin structure
        // Create manifest
        // Add boilerplate code
    }
    
    public function createTheme($name, $parent = null, $options = []) {
        // Generate theme structure
        // Create manifest
        // Inherit from parent if specified
    }
}
```

## 9. Testing Infrastructure

### 9.1 Component Testing Framework

Unified testing for both plugins and themes:

```php
abstract class ComponentTestCase extends PHPUnit\Framework\TestCase {
    protected $componentType;
    protected $componentName;
    
    public function setUp() {
        // Load component in test environment
    }
    
    public function assertComponentActive($name, $type) {
        // Assert component is active
    }
    
    public function assertAssetLoaded($asset) {
        // Assert asset is loaded
    }
    
    public function assertDependenciesMet($component) {
        // Assert all dependencies are satisfied
    }
}
```

## 10. Performance Optimization

### 10.1 Component Caching

Cache component metadata and assets:

```php
class ComponentCache {
    public function cacheManifests() {
        // Cache all component manifests
    }
    
    public function cacheDependencyTree() {
        // Cache resolved dependency tree
    }
    
    public function cacheAssets() {
        // Cache optimized assets
    }
    
    public function invalidate($componentName = null) {
        // Invalidate cache for component or all
    }
}
```

### 10.2 Lazy Loading

Load components only when needed:

```php
class ComponentLazyLoader {
    private $components = [];
    
    public function register($name, $type, $callback) {
        // Register component for lazy loading
    }
    
    public function load($name, $type) {
        // Load component on demand
    }
}
```

## Implementation Roadmap

### Phase 1: Foundation (Months 1-2)
- Extract shared interfaces from existing plugin system
- Create ComponentInfo base class
- Implement basic cross-component dependency checking

### Phase 2: Asset Management (Months 3-4)
- Build ComponentAssetManager
- Migrate existing asset handling
- Add asset optimization

### Phase 3: Migration System (Months 5-6)
- Extend migration system to themes
- Add cross-component migration support
- Create migration rollback for themes

### Phase 4: Developer Tools (Months 7-8)
- Create unified CLI
- Build scaffolding system
- Add validation tools

### Phase 5: Advanced Features (Months 9-12)
- Implement marketplace infrastructure
- Add performance optimizations
- Create comprehensive testing framework

## Benefits

1. **Code Reuse**: Eliminate duplication between plugin and theme systems
2. **Consistency**: Unified patterns for all components
3. **Flexibility**: Themes gain plugin-like capabilities when needed
4. **Maintainability**: Single codebase for component management
5. **Extensibility**: Easy to add new component types in the future
6. **Developer Experience**: Consistent APIs and tools
7. **System Integrity**: Better dependency management and conflict resolution

## Considerations

1. **Backward Compatibility**: All changes must maintain compatibility with existing plugins and themes
2. **Performance**: Unified system should not add overhead
3. **Complexity**: Balance feature richness with simplicity
4. **Migration Path**: Provide clear upgrade path for existing components
5. **Documentation**: Comprehensive docs for new unified system

## Conclusion

This harmonization plan leverages the mature plugin infrastructure to benefit the theme system while creating a unified component management architecture. The approach is incremental and can be implemented in phases without disrupting existing functionality.