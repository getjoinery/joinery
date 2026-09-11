# Master Plan - Complete Implementation Code

This document contains the full implementation code for the unified component architecture described in MASTER_PLAN.md.

## 1. ComponentBase.php

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
            } else {
                // Invalid JSON - throw exception for mandatory manifests
                throw new Exception("Invalid {$this->componentType} manifest at {$this->manifestPath}: " . json_last_error_msg());
            }
        } else {
            // Manifest is mandatory - throw exception
            throw new Exception("Required {$this->componentType} manifest not found at {$this->manifestPath}");
        }
        return false;
    }
    
    /**
     * Get manifest field value
     */
    public function get($key, $default = null) {
        return $this->manifestData[$key] ?? $default;
    }
    
    /**
     * Get component name
     */
    public function getName() { 
        return $this->manifestData['name'] ?? $this->name; 
    }
    
    /**
     * Get display name
     */
    public function getDisplayName() { 
        return $this->manifestData['displayName'] ?? $this->getName(); 
    }
    
    /**
     * Get version
     */
    public function getVersion() { 
        return $this->manifestData['version'] ?? '0.0.0'; 
    }
    
    /**
     * Get description
     */
    public function getDescription() { 
        return $this->manifestData['description'] ?? ''; 
    }
    
    /**
     * Get author
     */
    public function getAuthor() { 
        return $this->manifestData['author'] ?? ''; 
    }
    
    /**
     * Get requirements
     */
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
            // Parse version requirement (e.g., ">=7.4")
            $operator = '>=';
            $version = $requirements['php'];
            
            if (preg_match('/^([><=]+)(.+)$/', $requirements['php'], $matches)) {
                $operator = $matches[1];
                $version = $matches[2];
            }
            
            if (!version_compare(PHP_VERSION, $version, $operator)) {
                $errors[] = "PHP {$requirements['php']} required, currently " . PHP_VERSION;
            }
        }
        
        // Check Joinery version
        if (isset($requirements['joinery'])) {
            // Get current Joinery version from database or config
            $settings = Globalvars::get_instance();
            $joineryVersion = $settings->get_setting('joinery_version', true, true) ?? '1.0.0';
            
            $operator = '>=';
            $version = $requirements['joinery'];
            
            if (preg_match('/^([><=]+)(.+)$/', $requirements['joinery'], $matches)) {
                $operator = $matches[1];
                $version = $matches[2];
            }
            
            if (!version_compare($joineryVersion, $version, $operator)) {
                $errors[] = "Joinery {$requirements['joinery']} required, currently {$joineryVersion}";
            }
        }
        
        return empty($errors) ? true : $errors;
    }
    
    /**
     * Get URL path to component asset
     */
    public function getAssetUrl($path) {
        // Remove leading slash if present
        $path = ltrim($path, '/');
        return '/' . $this->basePath . '/' . $path;
    }
    
    /**
     * Get full filesystem path for component file
     */
    public function getIncludePath($path) {
        // Remove leading slash if present
        $path = ltrim($path, '/');
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
        
        if ($fallbackPath) {
            $fallbackFull = PathHelper::getIncludePath($fallbackPath);
            if (file_exists($fallbackFull)) {
                require_once($fallbackFull);
                return true;
            }
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
    
    /**
     * Get component type
     */
    public function getType() {
        return $this->componentType;
    }
    
    /**
     * Get base path
     */
    public function getBasePath() {
        return $this->basePath;
    }
    
    // Abstract methods that subclasses must implement
    abstract public function initialize();
    abstract public function isActive();
    abstract public function validate();
}
```

## 2. ThemeHelper.php

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
    
    /**
     * Private constructor for singleton pattern
     */
    private function __construct($themeName) {
        $this->name = $themeName;
        $this->basePath = "theme/{$themeName}";
        $this->manifestPath = PathHelper::getIncludePath("{$this->basePath}/theme.json");
        
        // Load manifest (will throw exception if not found/invalid)
        $this->loadManifest();
    }
    
    /**
     * Get ThemeHelper instance for a theme (singleton pattern)
     */
    public static function getInstance($themeName = null) {
        // Use current theme if not specified
        if (!$themeName) {
            $settings = Globalvars::get_instance();
            $themeName = $settings->get_setting('theme_template', true, true);
            
            if (!$themeName) {
                throw new Exception("No theme specified and no default theme configured");
            }
        }
        
        // Return cached instance or create new one
        if (!isset(self::$instances[$themeName])) {
            self::$instances[$themeName] = new self($themeName);
        }
        
        return self::$instances[$themeName];
    }
    
    /**
     * Initialize theme
     */
    public function initialize() {
        // Load theme functions file if it exists
        $functionsFile = $this->getIncludePath('functions.php');
        if (file_exists($functionsFile)) {
            require_once($functionsFile);
        }
        
        // Register theme with system
        $this->registerTheme();
        
        return true;
    }
    
    /**
     * Register theme with the system
     */
    private function registerTheme() {
        // Hook into system if needed
        // This is where theme-specific initialization would happen
        
        // Example: Register theme support features
        if (method_exists('SystemHooks', 'registerThemeSupport')) {
            SystemHooks::registerThemeSupport($this->name, $this->manifestData);
        }
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
        
        // Check for theme directory
        if (!is_dir(PathHelper::getIncludePath($this->basePath))) {
            $errors[] = "Theme directory not found: {$this->basePath}";
        }
        
        // Manifest is mandatory and already validated in loadManifest()
        // If we got here, manifest exists and is valid
        
        // Check for required manifest fields
        if (empty($this->manifestData['name'])) {
            $errors[] = "Theme manifest missing required field: name";
        }
        
        // Check FormWriter base class if specified
        if ($formWriterBase = $this->getFormWriterBase()) {
            $classFile = PathHelper::getIncludePath("includes/{$formWriterBase}.php");
            if (!file_exists($classFile)) {
                $errors[] = "FormWriter base class file not found: {$formWriterBase}.php";
            }
        }
        
        return empty($errors) ? true : $errors;
    }
    
    /**
     * Get CSS framework used by theme
     */
    public function getCssFramework() {
        return $this->manifestData['cssFramework'] ?? null;
    }
    
    /**
     * Get FormWriter base class for theme
     */
    public function getFormWriterBase() {
        return $this->manifestData['formWriterBase'] ?? null;
    }
    
    /**
     * Get PublicPage base class for theme
     */
    public function getPublicPageBase() {
        return $this->manifestData['publicPageBase'] ?? null;
    }
    
    // === STATIC HELPER METHODS ===
    // These provide convenient access without needing the instance
    
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
        
        // Return theme path anyway (might be dynamically generated)
        return "/theme/{$themeName}/{$path}";
    }
    
    /**
     * Include file from theme with fallback to base
     */
    public static function includeFile($path, $themeName = null) {
        try {
            $instance = self::getInstance($themeName);
            return $instance->includeFile($path);
        } catch (Exception $e) {
            // If theme doesn't exist, try base path
            $basePath = PathHelper::getIncludePath($path);
            if (file_exists($basePath)) {
                require_once($basePath);
                return true;
            }
            return false;
        }
    }
    
    /**
     * Get theme configuration value
     */
    public static function config($key, $default = null, $themeName = null) {
        try {
            $instance = self::getInstance($themeName);
            return $instance->get($key, $default);
        } catch (Exception $e) {
            return $default;
        }
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
                    error_log("Theme {$themeName} skipped: " . $e->getMessage());
                    continue;
                }
            }
        }
        
        return $themes;
    }
    
    /**
     * Check if theme exists and has valid manifest
     */
    public static function themeExists($themeName) {
        try {
            self::getInstance($themeName);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
```

## 3. PluginHelper.php

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
    
    /**
     * Private constructor for singleton pattern
     */
    private function __construct($pluginName) {
        $this->name = $pluginName;
        $this->basePath = "plugins/{$pluginName}";
        $this->manifestPath = PathHelper::getIncludePath("{$this->basePath}/plugin.json");
        
        // Load manifest (will throw exception if not found/invalid)
        $this->loadManifest();
    }
    
    /**
     * Get PluginHelper instance for a plugin (singleton pattern)
     */
    public static function getInstance($pluginName) {
        if (!$pluginName) {
            throw new Exception("Plugin name is required");
        }
        
        if (!isset(self::$instances[$pluginName])) {
            self::$instances[$pluginName] = new self($pluginName);
        }
        
        return self::$instances[$pluginName];
    }
    
    /**
     * Initialize plugin
     */
    public function initialize() {
        // Only initialize if plugin is active
        if (!$this->isActive()) {
            return false;
        }
        
        // Load plugin initialization file if it exists
        $initFile = $this->getIncludePath('init.php');
        if (file_exists($initFile)) {
            require_once($initFile);
        }
        
        // Load plugin functions file if it exists
        $functionsFile = $this->getIncludePath('functions.php');
        if (file_exists($functionsFile)) {
            require_once($functionsFile);
        }
        
        // Register plugin routes if custom routing exists
        if ($this->hasCustomRouting()) {
            $this->registerRoutes();
        }
        
        // Register admin menu items
        if ($this->hasAdminInterface()) {
            $this->registerAdminMenu();
        }
        
        // Run plugin migrations if needed
        if ($this->hasMigrations()) {
            $this->checkMigrations();
        }
        
        return true;
    }
    
    /**
     * Register plugin routes with the system
     */
    private function registerRoutes() {
        // This would integrate with serve.php routing system
        // Store plugin routes for processing in main routing logic
        if (class_exists('RouteRegistry')) {
            RouteRegistry::registerPlugin($this->name, $this->getIncludePath('serve.php'));
        }
    }
    
    /**
     * Register admin menu items
     */
    private function registerAdminMenu() {
        $menuItems = $this->getAdminMenuItems();
        
        if (!empty($menuItems) && class_exists('AdminMenuRegistry')) {
            foreach ($menuItems as $item) {
                AdminMenuRegistry::addMenuItem(
                    $item['title'] ?? '',
                    $item['url'] ?? '',
                    $item['permission'] ?? 5,
                    $item['icon'] ?? '',
                    $item['parent'] ?? null
                );
            }
        }
    }
    
    /**
     * Check and run pending migrations
     */
    private function checkMigrations() {
        $migrationsFile = $this->getMigrationsPath();
        if (file_exists($migrationsFile)) {
            // This would integrate with the migration system
            // The actual migration runner would handle this
            if (class_exists('MigrationRunner')) {
                MigrationRunner::registerPluginMigrations($this->name, $migrationsFile);
            }
        }
    }
    
    /**
     * Check if plugin is currently active
     */
    public function isActive() {
        // Check database for plugin activation status
        $settings = Globalvars::get_instance();
        
        // First check individual plugin setting
        $pluginActive = $settings->get_setting("plugin_{$this->name}_active", true, true);
        if ($pluginActive !== null) {
            return (bool)$pluginActive;
        }
        
        // Check active_plugins array
        $activePlugins = $settings->get_setting('active_plugins', true, true);
        if (is_array($activePlugins)) {
            return in_array($this->name, $activePlugins);
        }
        
        // Check if plugin has activation record in database
        $dbconnector = DbConnector::get_instance();
        $dblink = $dbconnector->get_db_link();
        
        $sql = "SELECT COUNT(*) as count FROM plg_plugins WHERE plg_name = ? AND plg_active = 1";
        $q = $dblink->prepare($sql);
        $q->execute([$this->name]);
        $result = $q->fetch(PDO::FETCH_ASSOC);
        
        return ($result['count'] > 0);
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
        
        // Check for plugin directory
        if (!is_dir(PathHelper::getIncludePath($this->basePath))) {
            $errors[] = "Plugin directory not found: {$this->basePath}";
        }
        
        // Manifest is mandatory and already validated in loadManifest()
        
        // Check for required manifest fields
        if (empty($this->manifestData['name'])) {
            $errors[] = "Plugin manifest missing required field: name";
        }
        
        // Validate admin menu items if present
        $menuItems = $this->getAdminMenuItems();
        foreach ($menuItems as $index => $item) {
            if (empty($item['title']) || empty($item['url'])) {
                $errors[] = "Admin menu item {$index} missing required fields (title, url)";
            }
        }
        
        // Validate API endpoints if present
        $endpoints = $this->getApiEndpoints();
        foreach ($endpoints as $index => $endpoint) {
            if (empty($endpoint['path']) || empty($endpoint['method'])) {
                $errors[] = "API endpoint {$index} missing required fields (path, method)";
            }
        }
        
        return empty($errors) ? true : $errors;
    }
    
    /**
     * Activate plugin
     */
    public function activate() {
        if ($this->isActive()) {
            return true; // Already active
        }
        
        // Run activation hook if exists
        $activateFile = $this->getIncludePath('activate.php');
        if (file_exists($activateFile)) {
            require_once($activateFile);
        }
        
        // Update database
        $dbconnector = DbConnector::get_instance();
        $dblink = $dbconnector->get_db_link();
        
        // Check if plugin record exists
        $sql = "SELECT plg_id FROM plg_plugins WHERE plg_name = ?";
        $q = $dblink->prepare($sql);
        $q->execute([$this->name]);
        $existing = $q->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            // Update existing record
            $sql = "UPDATE plg_plugins SET plg_active = 1, plg_activated_date = NOW() WHERE plg_name = ?";
            $q = $dblink->prepare($sql);
            $q->execute([$this->name]);
        } else {
            // Insert new record
            $sql = "INSERT INTO plg_plugins (plg_name, plg_active, plg_activated_date, plg_version) VALUES (?, 1, NOW(), ?)";
            $q = $dblink->prepare($sql);
            $q->execute([$this->name, $this->getVersion()]);
        }
        
        // Clear any cached plugin states
        $settings = Globalvars::get_instance();
        $settings->set_setting("plugin_{$this->name}_active", 1);
        
        return true;
    }
    
    /**
     * Deactivate plugin
     */
    public function deactivate() {
        if (!$this->isActive()) {
            return true; // Already inactive
        }
        
        // Run deactivation hook if exists
        $deactivateFile = $this->getIncludePath('deactivate.php');
        if (file_exists($deactivateFile)) {
            require_once($deactivateFile);
        }
        
        // Update database
        $dbconnector = DbConnector::get_instance();
        $dblink = $dbconnector->get_db_link();
        
        $sql = "UPDATE plg_plugins SET plg_active = 0, plg_deactivated_date = NOW() WHERE plg_name = ?";
        $q = $dblink->prepare($sql);
        $q->execute([$this->name]);
        
        // Clear cached plugin state
        $settings = Globalvars::get_instance();
        $settings->set_setting("plugin_{$this->name}_active", 0);
        
        return true;
    }
    
    // Plugin-specific getters
    
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
    
    /**
     * Get all plugin data models
     */
    public function getDataModels() {
        $models = [];
        $dataDir = $this->getIncludePath('data');
        
        if (is_dir($dataDir)) {
            $files = glob($dataDir . '/*_class.php');
            foreach ($files as $file) {
                $className = str_replace('_class.php', '', basename($file));
                $models[] = $className;
            }
        }
        
        return $models;
    }
    
    /**
     * Check if plugin provides a specific feature
     */
    public function providesFeature($feature) {
        $provides = $this->manifestData['provides'] ?? [];
        return in_array($feature, $provides);
    }
    
    /**
     * Get all available plugins with their helpers
     */
    public static function getAvailablePlugins() {
        $plugins = [];
        $pluginDir = PathHelper::getIncludePath('plugins');
        
        if (is_dir($pluginDir)) {
            $directories = glob($pluginDir . '/*', GLOB_ONLYDIR);
            foreach ($directories as $dir) {
                $pluginName = basename($dir);
                try {
                    $plugins[$pluginName] = self::getInstance($pluginName);
                } catch (Exception $e) {
                    // Skip plugins without valid manifests
                    error_log("Plugin {$pluginName} skipped: " . $e->getMessage());
                    continue;
                }
            }
        }
        
        return $plugins;
    }
}
```

## 4. Enhanced Helper Classes (System-Level Operations)

Rather than creating a separate ComponentManager, we add system-level operations to the helper classes themselves:

### 4a. Additional ThemeHelper Methods

```php
<?php
// Add these methods to the ThemeHelper class from section 2

/**
 * Switch active theme (system-level operation)
 */
public static function switchTheme($themeName) {
    // Validate new theme
    try {
        $newTheme = self::getInstance($themeName);
        $validation = $newTheme->validate();
        
        if ($validation !== true) {
            throw new Exception("Theme validation failed: " . implode(', ', $validation));
        }
    } catch (Exception $e) {
        throw new Exception("Cannot switch to theme '{$themeName}': " . $e->getMessage());
    }
    
    // Update database setting
    $settings = Globalvars::get_instance();
    $settings->set_setting('theme_template', $themeName);
    
    // Clear cached current theme instance
    $oldTheme = $settings->get_setting('theme_template', true, true);
    if (isset(self::$instances[$oldTheme])) {
        unset(self::$instances[$oldTheme]);
    }
    
    // Initialize new theme
    $newTheme->initialize();
    
    return true;
}

/**
 * Initialize all active themes (usually just one)
 */
public static function initializeActive() {
    $results = ['success' => [], 'failed' => []];
    
    try {
        $activeTheme = self::getInstance();
        $activeTheme->initialize();
        $results['success'][$activeTheme->getName()] = 'Theme initialized';
    } catch (Exception $e) {
        $results['failed']['theme'] = $e->getMessage();
        error_log("Failed to initialize active theme: " . $e->getMessage());
    }
    
    return $results;
}

/**
 * Validate all available themes
 */
public static function validateAll() {
    $results = [];
    $themes = self::getAvailableThemes();
    
    foreach ($themes as $name => $theme) {
        $validation = $theme->validate();
        $results[$name] = [
            'valid' => $validation === true,
            'errors' => $validation === true ? [] : $validation
        ];
    }
    
    return $results;
}
```

### 4b. Additional PluginHelper Methods

```php
<?php 
// Add these methods to the PluginHelper class from section 3

/**
 * Get all active plugins
 */
public static function getActivePlugins() {
    $activePlugins = [];
    $allPlugins = self::getAvailablePlugins();
    
    foreach ($allPlugins as $name => $plugin) {
        if ($plugin->isActive()) {
            $activePlugins[$name] = $plugin;
        }
    }
    
    return $activePlugins;
}

/**
 * Initialize all active plugins
 */
public static function initializeActive() {
    $results = ['success' => [], 'failed' => []];
    $activePlugins = self::getActivePlugins();
    
    foreach ($activePlugins as $name => $plugin) {
        try {
            $plugin->initialize();
            $results['success'][$name] = 'Plugin initialized';
        } catch (Exception $e) {
            $results['failed'][$name] = $e->getMessage();
            error_log("Failed to initialize plugin '{$name}': " . $e->getMessage());
        }
    }
    
    return $results;
}

/**
 * Validate all available plugins
 */
public static function validateAll() {
    $results = [];
    $plugins = self::getAvailablePlugins();
    
    foreach ($plugins as $name => $plugin) {
        $validation = $plugin->validate();
        $results[$name] = [
            'valid' => $validation === true,
            'errors' => $validation === true ? [] : $validation
        ];
    }
    
    return $results;
}

/**
 * Activate a plugin (static convenience method)
 */
public static function activatePlugin($pluginName) {
    $plugin = self::getInstance($pluginName);
    
    // Validate before activation
    $validation = $plugin->validate();
    if ($validation !== true) {
        throw new Exception("Plugin validation failed: " . implode(', ', $validation));
    }
    
    return $plugin->activate();
}

/**
 * Deactivate a plugin (static convenience method)
 */
public static function deactivatePlugin($pluginName) {
    $plugin = self::getInstance($pluginName);
    return $plugin->deactivate();
}
```

## 5. Updated LibraryFunctions.php (get_formwriter_object method)

```php
<?php
// This shows only the updated get_formwriter_object method
// The rest of LibraryFunctions.php remains unchanged

class LibraryFunctions {
    // ... other methods ...
    
    /**
     * Get FormWriter object with theme-aware selection
     * Updated to use ThemeHelper directly (no ComponentManager)
     */
    static function get_formwriter_object($form_id = 'form1', $override_name=NULL, $override_path=NULL){
        // Handle explicit path override
        if($override_path){
            require_once($override_path);
            $formwriter = new FormWriter($form_id);
            return $formwriter;
        }
        
        // Handle explicit name override
        if($override_name == 'admin'){
            PathHelper::requireOnce('includes/FormWriterMasterBootstrap.php');
            return new FormWriterMasterBootstrap($form_id);
        }
        else if($override_name == 'tailwind'){
            PathHelper::requireOnce('includes/FormWriterMasterTailwind.php');
            return new FormWriterMasterTailwind($form_id);
        }
        
        // Use ThemeHelper for theme-based selection
        try {
            PathHelper::requireOnce('includes/ThemeHelper.php');
            $theme = ThemeHelper::getInstance(); // Gets current theme
            
            // First check if theme has custom FormWriter
            $formWriterPath = $theme->getIncludePath('includes/FormWriter.php');
            if (file_exists($formWriterPath)) {
                require_once($formWriterPath);
                return new FormWriter($form_id);
            }
            
            // Use base class from theme manifest
            $baseClass = $theme->getFormWriterBase();
            if ($baseClass && $baseClass !== 'FormWriter') {
                $baseClassPath = PathHelper::getIncludePath("includes/{$baseClass}.php");
                if (file_exists($baseClassPath)) {
                    require_once($baseClassPath);
                    return new $baseClass($form_id);
                }
            }
            
            // If theme doesn't specify, determine from CSS framework
            $cssFramework = $theme->getCssFramework();
            switch($cssFramework) {
                case 'bootstrap':
                    PathHelper::requireOnce('includes/FormWriterMasterBootstrap.php');
                    return new FormWriterMasterBootstrap($form_id);
                    
                case 'tailwind':
                    PathHelper::requireOnce('includes/FormWriterMasterTailwind.php');
                    return new FormWriterMasterTailwind($form_id);
                    
                case 'uikit':
                    PathHelper::requireOnce('includes/FormWriterMaster.php');
                    return new FormWriterMaster($form_id);
            }
            
        } catch (Exception $e) {
            // Log error but don't break - fall through to legacy method
            error_log("ThemeHelper error in get_formwriter_object: " . $e->getMessage());
        }
        
        // LEGACY FALLBACK: Original method for backward compatibility
        $settings = Globalvars::get_instance();
        $theme_template = $settings->get_setting('theme_template', true, true);
        
        // Try theme-specific FormWriter
        $theme_form = PathHelper::getThemeFilePath('FormWriter.php', 'includes', 'system', $theme_template);
        if($theme_form){
            require_once($theme_form);
            return new FormWriter($form_id);
        }
        
        // Final default - Bootstrap
        PathHelper::requireOnce('includes/FormWriterMasterBootstrap.php');
        return new FormWriterMasterBootstrap($form_id);
    }
    
    // ... other methods ...
}
```

## 6. Example Theme Manifest (theme.json)

```json
{
  "name": "falcon",
  "displayName": "Falcon Theme",
  "version": "2.0.0",
  "description": "Bootstrap 5 based responsive theme with modern UI components",
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

## 7. Example Plugin Manifest (plugin.json)

```json
{
  "name": "stripe_payments",
  "displayName": "Stripe Payment Gateway",
  "version": "1.5.0",
  "description": "Integrate Stripe payment processing for products and subscriptions",
  "author": "Joinery Team",
  "requires": {
    "php": ">=7.4",
    "joinery": ">=1.0.0"
  },
  "adminMenu": [
    {
      "title": "Stripe Settings",
      "url": "/adm/admin_stripe_settings.php",
      "permission": 8,
      "icon": "payment"
    },
    {
      "title": "Payment History",
      "url": "/adm/admin_stripe_history.php",
      "permission": 5,
      "icon": "history"
    }
  ],
  "apiEndpoints": [
    {
      "path": "/api/stripe/webhook",
      "method": "POST",
      "description": "Stripe webhook endpoint"
    },
    {
      "path": "/api/stripe/create-session",
      "method": "POST",
      "description": "Create checkout session"
    }
  ],
  "provides": ["payment_gateway", "subscription_management"]
}
```

## 8. Integration Example (serve.php)

```php
<?php
// Example of how ThemeHelper and PluginHelper would be integrated into serve.php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/PathHelper.php');

// Initialize theme and plugins early in request lifecycle
PathHelper::requireOnce('includes/ThemeHelper.php');
PathHelper::requireOnce('includes/PluginHelper.php');

// Initialize active theme
$themeResults = ThemeHelper::initializeActive();
if (!empty($themeResults['failed'])) {
    foreach ($themeResults['failed'] as $name => $error) {
        error_log("Theme initialization failed - {$name}: {$error}");
    }
}

// Initialize active plugins  
$pluginResults = PluginHelper::initializeActive();
if (!empty($pluginResults['failed'])) {
    foreach ($pluginResults['failed'] as $name => $error) {
        error_log("Plugin initialization failed - {$name}: {$error}");
    }
}

// Get active theme for request processing
try {
    $activeTheme = ThemeHelper::getInstance();
    
    // Theme is now available for the request
    // Can access theme configuration, paths, etc.
    
} catch (Exception $e) {
    // Handle missing theme gracefully
    error_log("No active theme: " . $e->getMessage());
    // Could fall back to a default theme or show error page
}

// Continue with normal request processing...
// Routes, logic, views, etc.
```

## 9. Testing Utility (test_components.php)

```php
<?php
/**
 * Test utility for component system
 * Run: php utils/test_components.php
 */

require_once(__DIR__ . '/../includes/PathHelper.php');
PathHelper::requireOnce('includes/ThemeHelper.php');
PathHelper::requireOnce('includes/PluginHelper.php');

echo "Testing Component System\n";
echo "========================\n\n";

// Test 1: Discover all themes
echo "1. Discovering all themes...\n";
$allThemes = ThemeHelper::getAvailableThemes();
echo "   Found " . count($allThemes) . " themes\n";

foreach ($allThemes as $name => $theme) {
    echo "   - {$name}: {$theme->getDisplayName()}\n";
}

// Test 2: Discover all plugins
echo "\n2. Discovering all plugins...\n";
$allPlugins = PluginHelper::getAvailablePlugins();
echo "   Found " . count($allPlugins) . " plugins\n";

foreach ($allPlugins as $name => $plugin) {
    echo "   - {$name}: {$plugin->getDisplayName()}\n";
}

// Test 3: Validate all components
echo "\n3. Validating all components...\n";

$themeValidation = ThemeHelper::validateAll();
echo "   Themes:\n";
foreach ($themeValidation as $name => $result) {
    if ($result['valid']) {
        echo "   ✓ {$name}: Valid\n";
    } else {
        echo "   ✗ {$name}: " . implode(', ', $result['errors']) . "\n";
    }
}

$pluginValidation = PluginHelper::validateAll();
echo "   Plugins:\n";
foreach ($pluginValidation as $name => $result) {
    if ($result['valid']) {
        echo "   ✓ {$name}: Valid\n";
    } else {
        echo "   ✗ {$name}: " . implode(', ', $result['errors']) . "\n";
    }
}

// Test 4: Check active components
echo "\n4. Checking active components...\n";

try {
    $activeTheme = ThemeHelper::getInstance();
    echo "   Active theme: {$activeTheme->getName()}\n";
    echo "   Display name: {$activeTheme->getDisplayName()}\n";
    echo "   CSS Framework: " . ($activeTheme->getCssFramework() ?? 'not specified') . "\n";
    echo "   FormWriter Base: " . ($activeTheme->getFormWriterBase() ?? 'not specified') . "\n";
} catch (Exception $e) {
    echo "   Theme error: " . $e->getMessage() . "\n";
}

$activePlugins = PluginHelper::getActivePlugins();
echo "   Active plugins: " . count($activePlugins) . "\n";
foreach ($activePlugins as $name => $plugin) {
    echo "   - {$name}\n";
}

// Test 5: Test theme functionality
echo "\n5. Testing theme asset methods...\n";
try {
    $assetUrl = ThemeHelper::asset('css/theme.css');
    echo "   Theme asset URL: {$assetUrl}\n";
    
    $configValue = ThemeHelper::config('cssFramework', 'unknown');
    echo "   Theme CSS framework: {$configValue}\n";
} catch (Exception $e) {
    echo "   Asset test error: " . $e->getMessage() . "\n";
}

// Test 6: Statistics
echo "\n6. Component Statistics:\n";
$totalThemes = count($allThemes);
$totalPlugins = count($allPlugins);
$totalActive = 1 + count($activePlugins); // 1 theme + active plugins

echo "   Total themes: {$totalThemes}\n";
echo "   Total plugins: {$totalPlugins}\n";
echo "   Total components: " . ($totalThemes + $totalPlugins) . "\n";
echo "   Active components: {$totalActive}\n";

echo "\n✓ All tests completed!\n";
```

## Notes on Simplified Implementation

### Key Design Decisions:

1. **Mandatory Manifests**: Both themes and plugins require valid JSON manifests. This ensures consistency and prevents silent failures.

2. **Singleton Pattern**: Used for ThemeHelper and PluginHelper to ensure single instances and efficient caching.

3. **Exception Handling**: Components throw exceptions for missing/invalid manifests, making issues visible during development.

4. **Backward Compatibility**: LibraryFunctions falls back to legacy methods if ThemeHelper fails, ensuring existing code continues to work.

5. **Lazy Loading**: Components are loaded only when requested, not all at once.

6. **Database Integration**: Plugin activation state integrates with existing database tables (plg_plugins) and settings system.

7. **No ComponentManager**: System operations are handled as static methods on the helper classes themselves, reducing complexity.

### Integration Points:

1. **PathHelper**: Used throughout for consistent path resolution
2. **Globalvars**: Used for settings and configuration
3. **DbConnector**: Used for plugin activation state
4. **FormWriter Classes**: Enhanced selection based on theme configuration
5. **Migration System**: Plugins can register migrations
6. **Admin Menu**: Plugins can add menu items
7. **Routing**: Plugins can register custom routes

### Simplified Architecture Benefits:

- **Fewer classes** - Just ComponentBase + ThemeHelper + PluginHelper
- **Direct access** - `ThemeHelper::getInstance()` and `PluginHelper::getActivePlugins()`
- **Clear ownership** - Theme operations on ThemeHelper, plugin operations on PluginHelper
- **Still extensible** - ComponentBase provides shared foundation
- **Easier to understand** - No additional abstraction layer

### Error Handling:

- Missing manifests throw exceptions (fail fast principle)
- Invalid JSON throws exceptions with details
- Validation returns specific error messages
- All errors are logged for debugging
- Graceful fallbacks where appropriate

### Usage Examples:

```php
// Simple theme operations
$theme = ThemeHelper::getInstance();
$theme->getCssFramework();

// Simple plugin operations
$plugins = PluginHelper::getActivePlugins();
PluginHelper::activatePlugin('my_plugin');

// System operations
ThemeHelper::switchTheme('new_theme');
$results = ThemeHelper::validateAll();
```

This simplified implementation provides a robust, extensible foundation for managing both themes and plugins with shared infrastructure while maintaining full backward compatibility - all without the overhead of ComponentManager.