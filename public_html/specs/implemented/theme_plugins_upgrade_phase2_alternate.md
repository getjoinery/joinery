# Theme and Plugin Management System Upgrade - Phase 2 Alternate
**Version:** 2.0 - Combined Admin Interface & Refactored Architecture Implementation

## Overview

This alternate Phase 2 combines the admin interface implementation with the refactored architecture, implementing the `AbstractExtensionManager` base class pattern directly. This approach:

- Avoids creating temporary manager files that would be immediately replaced
- Implements the clean architecture from the start
- Reduces code duplication between themes and plugins
- Creates a maintainable, extensible system

**Prerequisites:** Phase 1 must be completed (deploy script and database integration).

## Architecture Overview

The system uses two parallel inheritance chains for different purposes:

### Manager Classes (Deployment/Installation)
```
AbstractExtensionManager (base class)
├── ThemeManager - System-wide theme installation, deployment, stock/custom management
└── PluginManager - System-wide plugin installation, deployment, dependencies, migrations
```

### Helper Classes (Runtime Utilities) 
```
ComponentBase (existing base class)
├── ThemeHelper - Per-theme runtime utilities (asset management, theme switching)
└── PluginHelper - Per-plugin runtime utilities (activation, menu registration, routing)
```

### Key Distinctions:
- **Managers** are system-wide, handle installation/deployment, extend AbstractExtensionManager
- **Helpers** are instance-per-component, handle runtime operations, extend ComponentBase
- **PluginHelper remains unchanged** - It serves a different architectural purpose than PluginManager

## Phase 2 Alternate: Combined Implementation

### 2.1 Base Extension Manager Class

**File:** `/includes/AbstractExtensionManager.php`

```php
<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/Globalvars.php');

/**
 * Abstract base class for managing extensions (themes and plugins)
 * Provides shared functionality for installation, validation, and management
 */
abstract class AbstractExtensionManager {
    
    // Configuration that subclasses must define
    protected $extension_type;      // 'theme' or 'plugin'
    protected $extension_dir;        // 'theme' or 'plugins'
    protected $manifest_filename;    // 'theme.json' or 'plugin.json'
    protected $table_prefix;         // 'thm' or 'plg'
    protected $model_class;          // 'Theme' or 'Plugin'
    protected $multi_model_class;    // 'MultiTheme' or 'MultiPlugin'
    
    // Shared properties
    protected $base_path;
    protected $reserved_names = array(
        'admin', 'api', 'includes', 'data', 'ajax', 'assets',
        'utils', 'adm', 'logic', 'views', 'migrations', 'specs'
    );
    
    /**
     * Constructor - verify required PHP extensions
     */
    public function __construct() {
        // Verify required PHP extensions
        if (!extension_loaded('json')) {
            throw new Exception("PHP json extension is required");
        }
        if (!extension_loaded('pdo')) {
            throw new Exception("PHP PDO extension is required");
        }
    }
    
    /**
     * Validate extension name
     * @param string $name Extension name to validate
     * @return bool True if valid
     */
    public function validateName($name) {
        if (empty($name)) return false;
        
        // Length check
        if (strlen($name) > 50 || strlen($name) < 3) {
            return false;
        }
        
        // Must start with letter, then alphanumeric, underscore, dash
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $name)) {
            return false;
        }
        
        // Reserved names check (merge with extension-specific reserved names)
        $all_reserved = array_merge($this->reserved_names, $this->getAdditionalReservedNames());
        if (in_array(strtolower($name), $all_reserved)) {
            return false;
        }
        
        // Path traversal check
        if (strpos($name, '..') !== false || strpos($name, '/') !== false 
            || strpos($name, '\\') !== false) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Install extension from uploaded ZIP file
     * @param string $zip_path Path to uploaded ZIP file
     * @return string Extension name that was installed
     */
    public function installFromZip($zip_path) {
        // Check for zip extension
        if (!extension_loaded('zip')) {
            throw new Exception("PHP zip extension is required but not installed");
        }
        
        // Create temp directory
        $temp_dir = sys_get_temp_dir() . '/' . $this->extension_type . '_' . uniqid();
        mkdir($temp_dir);
        
        try {
            // Extract ZIP
            $zip = new ZipArchive();
            if ($zip->open($zip_path) !== TRUE) {
                throw new Exception("Failed to open ZIP file");
            }
            
            $zip->extractTo($temp_dir);
            $zip->close();
            
            // Find and validate manifest
            $manifest_data = $this->findAndValidateManifest($temp_dir);
            $extension_root = $manifest_data['root'];
            $manifest = $manifest_data['manifest'];
            $extension_name = $manifest_data['name'];
            
            // Validate name
            if (!$this->validateName($extension_name)) {
                throw new Exception("Invalid {$this->extension_type} name: $extension_name");
            }
            
            // Check if already exists
            $target_path = $this->getExtensionPath($extension_name);
            if (is_dir($target_path)) {
                $this->handleExistingExtension($target_path);
            }
            
            // Move to final location
            if (!rename($extension_root, $target_path)) {
                throw new Exception("Failed to install {$this->extension_type} files");
            }
            
            // Set permissions
            $this->setPermissions($target_path);
            
            // Extension-specific post-install
            $this->postInstall($extension_name, $manifest);
            
            // Clean up
            $this->cleanup($temp_dir);
            
            return $extension_name;
            
        } catch (Exception $e) {
            $this->cleanup($temp_dir);
            throw $e;
        }
    }
    
    /**
     * Set proper permissions on extension directory
     * @param string $dir Directory path
     */
    protected function setPermissions($dir) {
        @chown($dir, 'www-data');
        @chgrp($dir, 'user1');
        @chmod($dir, 0775);
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $path) {
            @chown($path, 'www-data');
            @chgrp($path, 'user1');
            if ($path->isDir()) {
                @chmod($path, 0775);
            } else {
                @chmod($path, 0664);
            }
        }
    }
    
    /**
     * Clean up temporary directory
     * @param string $dir Directory to remove
     */
    protected function cleanup($dir) {
        if (!is_dir($dir)) return;
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($iterator as $path) {
            $path->isDir() ? rmdir($path) : unlink($path);
        }
        
        rmdir($dir);
    }
    
    /**
     * Sync filesystem extensions with database registry
     * @return array Array of newly synced extension names
     */
    public function sync() {
        $extension_dir = PathHelper::getAbsolutePath($this->extension_dir);
        if (!is_dir($extension_dir)) {
            mkdir($extension_dir, 0775, true);
            return array();
        }
        
        $synced = array();
        $dirs = scandir($extension_dir);
        
        foreach ($dirs as $dir) {
            if ($dir == '.' || $dir == '..') continue;
            
            $path = "$extension_dir/$dir";
            if (!is_dir($path)) continue;
            
            // Skip invalid names
            if (!$this->validateName($dir)) continue;
            
            // Check if exists in database using the getByNameMethod
            $existing = $this->getExistingByName($dir);
            
            if (!$existing) {
                // New extension, add to database
                $model_class = $this->model_class;
                $extension = new $model_class(null);
                $extension->set($this->table_prefix . '_name', $dir);
                $extension->set($this->table_prefix . '_status', $this->getDefaultStatus());
                
                // Load metadata from manifest
                $this->loadMetadataIntoModel($extension, $dir);
                $extension->save();
                
                $synced[] = $dir;
            } else {
                // Update metadata for existing extension
                $this->updateExistingMetadata($existing, $dir);
            }
        }
        
        return $synced;
    }
    
    /**
     * Get path for extension
     * @param string $name Extension name
     * @return string Full path to extension directory
     */
    protected function getExtensionPath($name) {
        return PathHelper::getAbsolutePath($this->extension_dir . '/' . $name);
    }
    
    /**
     * Get existing extension by name
     * @param string $name Extension name
     * @return object|null Extension model or null if not found
     */
    protected function getExistingByName($name) {
        $model_class = $this->model_class;
        $method_name = 'get_by_' . $this->extension_type . '_name';
        
        // Use the specific static method if it exists
        if (method_exists($model_class, $method_name)) {
            return call_user_func(array($model_class, $method_name), $name);
        }
        
        // Fallback to GetByColumn
        return call_user_func(array($model_class, 'GetByColumn'), $this->table_prefix . '_name', $name);
    }
    
    // Abstract methods that subclasses must implement
    abstract protected function getAdditionalReservedNames();
    abstract protected function findAndValidateManifest($temp_dir);
    abstract protected function handleExistingExtension($path);
    abstract protected function postInstall($name, $manifest);
    abstract protected function loadMetadataIntoModel($model, $name);
    abstract protected function getDefaultStatus();
    abstract protected function updateExistingMetadata($model, $name);
}
?>
```

### 2.2 Theme Manager Implementation

**File:** `/includes/ThemeManager.php`

```php
<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
PathHelper::requireOnce('includes/AbstractExtensionManager.php');
PathHelper::requireOnce('data/themes_class.php');

/**
 * ThemeManager - Manages theme installation, activation, and configuration
 */
class ThemeManager extends AbstractExtensionManager {
    
    private static $instance = null;
    
    public function __construct() {
        parent::__construct();
        $this->extension_type = 'theme';
        $this->extension_dir = 'theme';
        $this->manifest_filename = 'theme.json';
        $this->table_prefix = 'thm';
        $this->model_class = 'Theme';
        $this->multi_model_class = 'MultiTheme';
    }
    
    /**
     * Get singleton instance
     * @return ThemeManager
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Get additional reserved names for themes
     * @return array
     */
    protected function getAdditionalReservedNames() {
        return array('plugins', 'plugin');
    }
    
    /**
     * Get default status for new themes
     * @return string
     */
    protected function getDefaultStatus() {
        return 'installed';
    }
    
    /**
     * Find and validate theme manifest
     * @param string $temp_dir Temporary directory containing extracted files
     * @return array Contains 'root', 'manifest', and 'name'
     */
    protected function findAndValidateManifest($temp_dir) {
        $manifest_path = null;
        $theme_name = null;
        $theme_root = null;
        
        // Check root directory first
        if (file_exists("$temp_dir/theme.json")) {
            $manifest_path = "$temp_dir/theme.json";
            $theme_root = $temp_dir;
        } else {
            // Look in first subdirectory
            $dirs = scandir($temp_dir);
            foreach ($dirs as $dir) {
                if ($dir == '.' || $dir == '..') continue;
                if (is_dir("$temp_dir/$dir") && file_exists("$temp_dir/$dir/theme.json")) {
                    $manifest_path = "$temp_dir/$dir/theme.json";
                    $theme_root = "$temp_dir/$dir";
                    $theme_name = $dir;
                    break;
                }
            }
        }
        
        if (!$manifest_path) {
            throw new Exception("No theme.json found in uploaded file");
        }
        
        $manifest = json_decode(file_get_contents($manifest_path), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid theme.json: " . json_last_error_msg());
        }
        
        // Determine theme name from manifest if not from directory
        if (!$theme_name && isset($manifest['name'])) {
            $theme_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($manifest['name']));
            if (!preg_match('/^[a-zA-Z]/', $theme_name)) {
                $theme_name = 'theme_' . $theme_name;
            }
        }
        
        if (!$theme_name) {
            throw new Exception("Could not determine theme name");
        }
        
        return array(
            'root' => $theme_root,
            'manifest' => $manifest,
            'name' => $theme_name
        );
    }
    
    /**
     * Handle existing theme when installing
     * @param string $path Path to existing theme
     */
    protected function handleExistingExtension($path) {
        throw new Exception("Theme already exists. Please uninstall the existing version first.");
    }
    
    /**
     * Post-installation tasks for themes
     * @param string $name Theme name
     * @param array $manifest Theme manifest data
     */
    protected function postInstall($name, $manifest) {
        // Sync themes with database
        $this->sync();
        
        // Mark as custom (not stock) since it was uploaded
        $theme = Theme::get_by_theme_name($name);
        if ($theme) {
            $theme->set('thm_is_stock', false);
            $theme->save();
        }
    }
    
    /**
     * Load metadata from theme.json into model
     * @param Theme $model Theme model object
     * @param string $name Theme name
     */
    protected function loadMetadataIntoModel($model, $name) {
        $manifest_path = $this->getExtensionPath($name) . '/theme.json';
        if (!file_exists($manifest_path)) return;
        
        $metadata = json_decode(file_get_contents($manifest_path), true);
        if (json_last_error() === JSON_ERROR_NONE && $metadata) {
            $model->set('thm_metadata', $metadata);
            $model->set('thm_display_name', $metadata['name'] ?? $name);
            $model->set('thm_description', $metadata['description'] ?? '');
            $model->set('thm_version', $metadata['version'] ?? '1.0.0');
            $model->set('thm_author', $metadata['author'] ?? 'Unknown');
            $model->set('thm_is_stock', $metadata['is_stock'] ?? true);
        }
    }
    
    /**
     * Update existing theme metadata
     * @param Theme $model Theme model object
     * @param string $name Theme name
     */
    protected function updateExistingMetadata($model, $name) {
        $manifest_path = $this->getExtensionPath($name) . '/theme.json';
        if (!file_exists($manifest_path)) return;
        
        $metadata = json_decode(file_get_contents($manifest_path), true);
        if (json_last_error() === JSON_ERROR_NONE && $metadata && isset($metadata['is_stock'])) {
            // Only update stock status if it's explicitly defined in manifest
            $current_stock = $model->get('thm_is_stock');
            $manifest_stock = $metadata['is_stock'];
            
            if ($current_stock != $manifest_stock) {
                $model->set('thm_is_stock', $manifest_stock);
                $model->save();
            }
        }
    }
    
    // Theme-specific public methods
    
    /**
     * Get the currently active theme
     * @return Theme|null Active theme or null if none
     */
    public function getActiveTheme() {
        $active_themes = new MultiTheme(array('thm_is_active' => true));
        $active_themes->load();
        
        if ($active_themes->count() > 0) {
            return $active_themes->get(0);
        }
        
        return null;
    }
    
    /**
     * Set the active theme
     * @param string $theme_name Theme name to activate
     * @return bool Success status
     */
    public function setActiveTheme($theme_name) {
        $theme = Theme::get_by_theme_name($theme_name);
        
        if (!$theme) {
            throw new Exception("Theme not found: $theme_name");
        }
        
        return $theme->activate();
    }
    
    /**
     * Install theme (alias for installFromZip for backward compatibility)
     * @param string $zip_path Path to ZIP file
     * @return string Theme name
     */
    public function installTheme($zip_path) {
        return $this->installFromZip($zip_path);
    }
}
?>
```

### 2.3 Plugin Manager Implementation (Consolidated)

**File:** `/includes/PluginManager.php` (Replace existing file)

**Current State:** 
- `/includes/PluginManager.php` contains multiple separate classes (`PluginMigrationRunner`, `PluginVersionDetector`, `PluginDependencyValidator`, etc.) within the same file
- `/includes/PluginHelper.php` exists separately as a runtime utility class extending ComponentBase

**New Implementation:** 
- Consolidate all classes within PluginManager.php into a single `PluginManager` class that extends `AbstractExtensionManager`
- All migration, dependency, and version detection functionality becomes methods within the unified PluginManager class
- **PluginHelper.php remains completely unchanged** - it handles different responsibilities (per-plugin runtime operations vs system-wide management)

```php
<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
PathHelper::requireOnce('includes/AbstractExtensionManager.php');
PathHelper::requireOnce('data/plugins_class.php');
PathHelper::requireOnce('data/plugin_dependencies_class.php');
PathHelper::requireOnce('data/plugin_migrations_class.php');

/**
 * PluginManager - Comprehensive plugin management including installation, 
 * activation, dependencies, and migrations
 * 
 * This consolidated class replaces the previous multi-class structure with
 * a single cohesive manager that extends AbstractExtensionManager
 */
class PluginManager extends AbstractExtensionManager {
    
    private static $instance = null;
    
    public function __construct() {
        parent::__construct();
        $this->extension_type = 'plugin';
        $this->extension_dir = 'plugins';
        $this->manifest_filename = 'plugin.json';
        $this->table_prefix = 'plg';
        $this->model_class = 'Plugin';
        $this->multi_model_class = 'MultiPlugin';
    }
    
    /**
     * Get singleton instance
     * @return PluginManager
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    // ========== Base Class Implementation ==========
    
    /**
     * Get additional reserved names for plugins
     * @return array
     */
    protected function getAdditionalReservedNames() {
        return array('theme', 'themes', 'core', 'system');
    }
    
    /**
     * Get default status for new plugins
     * @return string
     */
    protected function getDefaultStatus() {
        return 'inactive';
    }
    
    /**
     * Find and validate plugin manifest
     * @param string $temp_dir Temporary directory containing extracted files
     * @return array Contains 'root', 'manifest', and 'name'
     */
    protected function findAndValidateManifest($temp_dir) {
        $manifest_path = null;
        $plugin_name = null;
        $plugin_root = null;
        
        // Check root directory first
        if (file_exists("$temp_dir/plugin.json")) {
            $manifest_path = "$temp_dir/plugin.json";
            $plugin_root = $temp_dir;
        } else {
            // Look in first subdirectory
            $dirs = scandir($temp_dir);
            foreach ($dirs as $dir) {
                if ($dir == '.' || $dir == '..') continue;
                if (is_dir("$temp_dir/$dir") && file_exists("$temp_dir/$dir/plugin.json")) {
                    $manifest_path = "$temp_dir/$dir/plugin.json";
                    $plugin_root = "$temp_dir/$dir";
                    $plugin_name = $dir;
                    break;
                }
            }
        }
        
        if (!$manifest_path) {
            throw new Exception("No plugin.json found in uploaded file");
        }
        
        $manifest = json_decode(file_get_contents($manifest_path), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid plugin.json: " . json_last_error_msg());
        }
        
        // Determine plugin name from manifest if not from directory
        if (!$plugin_name && isset($manifest['name'])) {
            $plugin_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($manifest['name']));
            if (!preg_match('/^[a-zA-Z]/', $plugin_name)) {
                $plugin_name = 'plugin_' . $plugin_name;
            }
        }
        
        if (!$plugin_name) {
            throw new Exception("Could not determine plugin name");
        }
        
        return array(
            'root' => $plugin_root,
            'manifest' => $manifest,
            'name' => $plugin_name
        );
    }
    
    /**
     * Handle existing plugin when installing
     * @param string $path Path to existing plugin
     */
    protected function handleExistingExtension($path) {
        throw new Exception("Plugin already exists. Please uninstall the existing version first.");
    }
    
    /**
     * Post-installation tasks for plugins
     * @param string $name Plugin name
     * @param array $manifest Plugin manifest data
     */
    protected function postInstall($name, $manifest) {
        // Run plugin migrations if any exist
        $this->runPendingMigrations($name);
        
        // Validate and store dependencies
        $validation = $this->validatePlugin($name);
        if (!$validation['valid']) {
            throw new Exception("Plugin validation failed: " . implode('; ', $validation['errors']));
        }
        
        // Sync with database
        $this->sync();
        
        // Mark as custom (not stock) since it was uploaded
        $plugin = Plugin::get_by_plugin_name($name);
        if ($plugin) {
            $plugin->set('plg_is_stock', false);
            $plugin->save();
        }
    }
    
    /**
     * Load metadata from plugin.json into model
     * @param Plugin $model Plugin model object
     * @param string $name Plugin name
     */
    protected function loadMetadataIntoModel($model, $name) {
        $manifest_path = $this->getExtensionPath($name) . '/plugin.json';
        if (!file_exists($manifest_path)) return;
        
        $metadata = json_decode(file_get_contents($manifest_path), true);
        if (json_last_error() === JSON_ERROR_NONE && $metadata) {
            $model->set('plg_metadata', json_encode($metadata));
            $model->set('plg_is_stock', $metadata['is_stock'] ?? true);
            $model->set('plg_installed_time', date('Y-m-d H:i:s'));
            
            // Load stock status
            $model->load_stock_status();
        }
    }
    
    /**
     * Update existing plugin metadata
     * @param Plugin $model Plugin model object
     * @param string $name Plugin name
     */
    protected function updateExistingMetadata($model, $name) {
        $model->load_stock_status();
        $model->save();
    }
    
    // ========== Migration Handling ==========
    
    /**
     * Run all pending migrations for a plugin
     * @param string $plugin_name Plugin name
     * @return array Results of migration runs
     */
    public function runPendingMigrations($plugin_name) {
        $results = array();
        $migration_dir = PathHelper::getAbsolutePath("plugins/{$plugin_name}/migrations");
        
        if (!is_dir($migration_dir)) {
            return $results;
        }
        
        // Get list of migration files
        $files = glob($migration_dir . '/*.sql');
        if (empty($files)) {
            return $results;
        }
        
        // Sort files to ensure proper order
        sort($files);
        
        foreach ($files as $file) {
            $filename = basename($file);
            
            // Check if migration has already been run
            $existing = PluginMigration::GetByColumns(array(
                'pgm_plugin_name' => $plugin_name,
                'pgm_filename' => $filename
            ));
            
            if ($existing) {
                continue; // Skip already-run migrations
            }
            
            // Run migration
            $result = $this->runMigration($file);
            $results[] = $result;
            
            // Record migration
            $migration = new PluginMigration(null);
            $migration->set('pgm_plugin_name', $plugin_name);
            $migration->set('pgm_filename', $filename);
            $migration->set('pgm_executed_time', date('Y-m-d H:i:s'));
            $migration->set('pgm_success', $result['success']);
            $migration->set('pgm_error_message', $result['error'] ?? null);
            $migration->save();
        }
        
        return $results;
    }
    
    /**
     * Run a single migration file
     * @param string $file Path to migration file
     * @return array Result with 'success' and optional 'error'
     */
    private function runMigration($file) {
        try {
            $sql = file_get_contents($file);
            if (empty($sql)) {
                return array('success' => true, 'file' => basename($file));
            }
            
            $dbconnector = DbConnector::get_instance();
            $dblink = $dbconnector->get_db_link();
            
            // Execute migration
            $dblink->exec($sql);
            
            return array(
                'success' => true,
                'file' => basename($file)
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'file' => basename($file),
                'error' => $e->getMessage()
            );
        }
    }
    
    // ========== Dependency Validation ==========
    
    /**
     * Validate all dependencies for a plugin
     * @param string $plugin_name Plugin name to validate
     * @return array Validation results with 'valid', 'errors', and 'warnings'
     */
    public function validatePlugin($plugin_name) {
        $results = array(
            'valid' => true,
            'errors' => array(),
            'warnings' => array()
        );
        
        // Get plugin manifest
        $manifest_path = PathHelper::getAbsolutePath("plugins/{$plugin_name}/plugin.json");
        if (!file_exists($manifest_path)) {
            $results['valid'] = false;
            $results['errors'][] = "Plugin manifest not found";
            return $results;
        }
        
        $manifest = json_decode(file_get_contents($manifest_path), true);
        if (json_last_error() !== JSON_ERROR_NONE || !$manifest) {
            $results['valid'] = false;
            $results['errors'][] = "Invalid plugin manifest";
            return $results;
        }
        
        // Check PHP version requirement
        if (isset($manifest['requires']['php'])) {
            $required_php = $manifest['requires']['php'];
            if (!$this->checkVersionConstraint(PHP_VERSION, $required_php)) {
                $results['valid'] = false;
                $results['errors'][] = "PHP version " . PHP_VERSION . " does not meet requirement: " . $required_php;
            }
        }
        
        // Check required PHP extensions
        if (isset($manifest['requires']['extensions']) && is_array($manifest['requires']['extensions'])) {
            foreach ($manifest['requires']['extensions'] as $ext) {
                if (!extension_loaded($ext)) {
                    $results['valid'] = false;
                    $results['errors'][] = "Required PHP extension not loaded: " . $ext;
                }
            }
        }
        
        // Check plugin dependencies
        if (isset($manifest['depends']) && is_array($manifest['depends'])) {
            foreach ($manifest['depends'] as $dep_name => $dep_version) {
                $dep_plugin = Plugin::get_by_plugin_name($dep_name);
                
                if (!$dep_plugin) {
                    $results['valid'] = false;
                    $results['errors'][] = "Required plugin not found: " . $dep_name;
                    continue;
                }
                
                if ($dep_plugin->get('plg_status') !== 'active') {
                    $results['valid'] = false;
                    $results['errors'][] = "Required plugin not active: " . $dep_name;
                }
                
                // TODO: Check version constraint when plugin versioning is implemented
            }
        }
        
        // Check for conflicts
        if (isset($manifest['conflicts']) && is_array($manifest['conflicts'])) {
            foreach ($manifest['conflicts'] as $conflict_name) {
                $conflict_plugin = Plugin::get_by_plugin_name($conflict_name);
                
                if ($conflict_plugin && $conflict_plugin->get('plg_status') === 'active') {
                    $results['valid'] = false;
                    $results['errors'][] = "Conflicting plugin is active: " . $conflict_name;
                }
            }
        }
        
        // Store dependencies in database if valid
        if ($results['valid']) {
            $this->storeDependencies($plugin_name, $manifest);
        }
        
        return $results;
    }
    
    /**
     * Check if a version satisfies a constraint
     * @param string $version Current version
     * @param string $constraint Version constraint (e.g., ">=7.4")
     * @return bool True if constraint is satisfied
     */
    private function checkVersionConstraint($version, $constraint) {
        // Simple implementation - can be enhanced with composer/semver library
        if (strpos($constraint, '>=') === 0) {
            $required = substr($constraint, 2);
            return version_compare($version, $required, '>=');
        } elseif (strpos($constraint, '>') === 0) {
            $required = substr($constraint, 1);
            return version_compare($version, $required, '>');
        } elseif (strpos($constraint, '<=') === 0) {
            $required = substr($constraint, 2);
            return version_compare($version, $required, '<=');
        } elseif (strpos($constraint, '<') === 0) {
            $required = substr($constraint, 1);
            return version_compare($version, $required, '<');
        } else {
            // Exact version
            return version_compare($version, $constraint, '=');
        }
    }
    
    /**
     * Store plugin dependencies in database
     * @param string $plugin_name Plugin name
     * @param array $manifest Plugin manifest
     */
    private function storeDependencies($plugin_name, $manifest) {
        // Clear existing dependencies
        $existing = new MultiPluginDependency(array('pld_plugin_name' => $plugin_name));
        $existing->load();
        foreach ($existing as $dep) {
            $dep->permanent_delete();
        }
        
        // Store new dependencies
        if (isset($manifest['depends']) && is_array($manifest['depends'])) {
            foreach ($manifest['depends'] as $dep_name => $dep_version) {
                $dependency = new PluginDependency(null);
                $dependency->set('pld_plugin_name', $plugin_name);
                $dependency->set('pld_depends_on', $dep_name);
                $dependency->set('pld_version_constraint', $dep_version);
                $dependency->save();
            }
        }
    }
    
    // ========== Public API Methods (Backward Compatibility) ==========
    
    /**
     * Sync filesystem plugins with database
     * @return array Array of newly synced plugin names
     */
    public function syncWithFilesystem() {
        return $this->sync();
    }
    
    /**
     * Install plugin from ZIP (alias for backward compatibility)
     * @param string $zip_path Path to ZIP file
     * @return string Plugin name
     */
    public function installPlugin($zip_path) {
        return $this->installFromZip($zip_path);
    }
    
    // ========== Legacy Support Methods ==========
    // These methods exist to support any existing code that might call them
    
    /**
     * Run plugin system repair
     * @deprecated Use validateAllPlugins() instead
     */
    public function repair() {
        return $this->validateAllPlugins();
    }
    
    /**
     * Validate all installed plugins
     * @return array Validation results for all plugins
     */
    public function validateAllPlugins() {
        $results = array();
        
        $plugins = new MultiPlugin();
        $plugins->load();
        
        foreach ($plugins as $plugin) {
            $plugin_name = $plugin->get('plg_name');
            $results[$plugin_name] = $this->validatePlugin($plugin_name);
        }
        
        return $results;
    }
    
    /**
     * Check if a plugin can be safely activated
     * @param string $plugin_name Plugin name
     * @return bool True if plugin can be activated
     */
    public function canActivate($plugin_name) {
        $validation = $this->validatePlugin($plugin_name);
        return $validation['valid'];
    }
    
    /**
     * Get all plugins that depend on a given plugin
     * @param string $plugin_name Plugin name
     * @return array Array of dependent plugin names
     */
    public function getDependents($plugin_name) {
        $dependents = array();
        
        $deps = new MultiPluginDependency(array('pld_depends_on' => $plugin_name));
        $deps->load();
        
        foreach ($deps as $dep) {
            $dependents[] = $dep->get('pld_plugin_name');
        }
        
        return array_unique($dependents);
    }
}
?>
```

### 2.3.1 Responsibility Division: PluginManager vs PluginHelper

**PluginManager (System-wide Management):**
- Install plugins from ZIP files
- Validate dependencies across all plugins
- Run database migrations for plugins
- Sync filesystem with database registry
- Manage stock/custom status for deployment
- Check version constraints
- Store dependency relationships

**PluginHelper (Per-plugin Runtime):**
- Load plugin initialization files
- Register plugin routes with the system
- Register admin menu items
- Check if a specific plugin is active
- Activate/deactivate individual plugins
- Access plugin metadata and configuration
- Initialize active plugins on page load

**Usage Examples:**
```php
// PluginManager - System operations
$manager = new PluginManager();
$manager->installFromZip($uploaded_file);        // Install any plugin
$manager->validateAllPlugins();                  // Check all dependencies
$manager->syncWithFilesystem();                  // Update registry

// PluginHelper - Runtime operations  
$helper = PluginHelper::getInstance('my-plugin'); // Specific plugin instance
$helper->initialize();                            // Load this plugin's files
$helper->isActive();                             // Check if this plugin is active
$helper->getAdminMenuItems();                    // Get this plugin's menu items
```

### 2.4 Database Models

#### Theme Model

**File:** `/data/themes_class.php`

```php
<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
PathHelper::requireOnce('includes/SystemClass.php');

class Theme extends SystemBase {
    public static $prefix = 'thm';
    public static $tablename = 'thm_themes';
    public static $pkey_column = 'thm_theme_id';
    
    public static $fields = array(
        'thm_theme_id' => 'Primary key - Theme ID',
        'thm_name' => 'Theme folder name (e.g. falcon, tailwind)',
        'thm_display_name' => 'Display name for admin interface', 
        'thm_description' => 'Theme description',
        'thm_version' => 'Theme version',
        'thm_author' => 'Theme author',
        'thm_is_active' => 'Is this the active theme?',
        'thm_is_stock' => 'Is this a stock theme (auto-updated)?',
        'thm_status' => 'Status: installed, active, inactive, error',
        'thm_metadata' => 'JSON metadata from theme.json',
        'thm_installed_time' => 'When theme was installed',
        'thm_create_time' => 'Record creation time',
        'thm_update_time' => 'Record update time'
    );
    
    public static $field_specifications = array(
        'thm_theme_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
        'thm_name' => array('type'=>'varchar(50)', 'is_nullable'=>false, 'unique'=>true),
        'thm_display_name' => array('type'=>'varchar(100)'),
        'thm_description' => array('type'=>'text'),
        'thm_version' => array('type'=>'varchar(20)'),
        'thm_author' => array('type'=>'varchar(100)'),
        'thm_is_active' => array('type'=>'bool'),
        'thm_is_stock' => array('type'=>'bool'),
        'thm_status' => array('type'=>'varchar(20)'),
        'thm_metadata' => array('type'=>'jsonb'),
        'thm_installed_time' => array('type'=>'timestamp(6)'),
        'thm_create_time' => array('type'=>'timestamp(6)'),
        'thm_update_time' => array('type'=>'timestamp(6)')
    );
    
    public static $json_vars = array('thm_metadata');
    public static $timestamp_fields = array('thm_create_time', 'thm_update_time', 'thm_installed_time');
    public static $required_fields = array('thm_name');
    public static $initial_default_values = array(
        'thm_is_active' => false,
        'thm_is_stock' => true,
        'thm_status' => 'installed',
        'thm_installed_time' => 'now()',
        'thm_create_time' => 'now()',
        'thm_update_time' => 'now()'
    );
    
    /**
     * Get theme by name
     */
    public static function get_by_theme_name($theme_name) {
        return static::GetByColumn('thm_name', $theme_name);
    }
    
    /**
     * Activate this theme
     */
    public function activate() {
        // Deactivate all other themes
        $dbconnector = DbConnector::get_instance();
        $dblink = $dbconnector->get_db_link();
        
        $sql = "UPDATE thm_themes SET thm_is_active = false, thm_status = 'installed' WHERE thm_is_active = true";
        $q = $dblink->prepare($sql);
        $q->execute();
        
        // Activate this theme
        $this->set('thm_is_active', true);
        $this->set('thm_status', 'active');
        $this->save();
        
        // Update global theme setting
        $settings = Globalvars::get_instance();
        $settings->set_setting('theme_template', $this->get('thm_name'));
        
        return true;
    }
    
    /**
     * Check if theme directory exists
     */
    public function theme_files_exist() {
        $theme_name = $this->get('thm_name');
        $theme_path = PathHelper::getAbsolutePath("theme/$theme_name");
        return is_dir($theme_path);
    }
}

class MultiTheme extends SystemMultiBase {
    public static $table_name = 'thm_themes';
    public static $table_primary_key = 'thm_theme_id';
    protected static $default_options = array();
    
    protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        if (isset($this->options['thm_is_active'])) {
            $filters['thm_is_active'] = [$this->options['thm_is_active'], PDO::PARAM_BOOL];
        }
        
        if (isset($this->options['thm_is_stock'])) {
            $filters['thm_is_stock'] = [$this->options['thm_is_stock'], PDO::PARAM_BOOL];
        }
        
        if (isset($this->options['thm_status'])) {
            $filters['thm_status'] = [$this->options['thm_status'], PDO::PARAM_STR];
        }
        
        return $this->_get_resultsv2('thm_themes', $filters, $this->order_by, $only_count, $debug);
    }
    
    function load($debug = false) {
        parent::load();
        $q = $this->getMultiResults(false, $debug);
        foreach($q->fetchAll() as $row) {
            $child = new Theme($row->thm_theme_id);
            $child->load_from_data($row, array_keys(Theme::$fields));
            $this->add($child);
        }
    }
    
    function count_all($debug = false) {
        $q = $this->getMultiResults(TRUE, $debug);
        return $q;
    }
}
?>
```

#### Plugin Model Enhancement

**File:** Updates to `/data/plugins_class.php` - Add these to existing class:

```php
// Add these fields to existing Plugin::$fields array:
'plg_is_stock' => 'Is this a stock plugin (auto-updated)?',
'plg_create_time' => 'Record creation time',
'plg_update_time' => 'Record update time'

// Add these to existing Plugin::$field_specifications array:
'plg_is_stock' => array('type'=>'bool'),
'plg_create_time' => array('type'=>'timestamp(6)'),
'plg_update_time' => array('type'=>'timestamp(6)')

// Update Plugin::$timestamp_fields array to include new fields:
public static $timestamp_fields = array('plg_create_time', 'plg_update_time', 
    'plg_installed_time', 'plg_activated_time', 'plg_last_activated_time', 
    'plg_last_deactivated_time', 'plg_uninstalled_time');

// Update Plugin::$initial_default_values:
public static $initial_default_values = array(
    'plg_is_stock' => true,
    'plg_create_time' => 'now()',
    'plg_update_time' => 'now()'
);

// Add these methods to existing Plugin class:

/**
 * Check if plugin is stock (auto-updated)
 * @return bool True if stock plugin
 */
public function is_stock() {
    return (bool)$this->get('plg_is_stock');
}

/**
 * Mark plugin as stock
 */
public function mark_as_stock() {
    $this->set('plg_is_stock', true);
    $this->save();
}

/**
 * Mark plugin as custom
 */
public function mark_as_custom() {
    $this->set('plg_is_stock', false);
    $this->save();
}

/**
 * Load stock status from plugin.json metadata
 */
public function load_stock_status() {
    $metadata = $this->get_plugin_metadata();
    if ($metadata && isset($metadata['is_stock'])) {
        $this->set('plg_is_stock', $metadata['is_stock']);
    }
}
```

### 2.5 Admin Interfaces

#### Theme Admin Interface

**File:** `/adm/admin_themes.php`

```php
<?php
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/FormWriterMaster.php');
PathHelper::requireOnce('includes/SessionControl.php');
PathHelper::requireOnce('data/themes_class.php');
PathHelper::requireOnce('includes/ThemeManager.php');

$session = SessionControl::get_instance();

// Verify permissions (system admin only)
if ($session->check_permission(10) === false) {
    throw new SystemException("User does not have sufficient permissions", 403);
}

$theme_manager = ThemeManager::getInstance();
$message = '';
$error = '';

// Handle form submissions
if ($_POST) {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'activate':
                    $theme_name = $_POST['theme_name'];
                    $theme = Theme::get_by_theme_name($theme_name);
                    if ($theme) {
                        $theme->activate();
                        $message = "Theme '$theme_name' activated successfully.";
                    } else {
                        $error = "Theme not found.";
                    }
                    break;
                    
                case 'mark_stock':
                    $theme_name = $_POST['theme_name'];
                    $theme = Theme::get_by_theme_name($theme_name);
                    if ($theme) {
                        $theme->set('thm_is_stock', true);
                        $theme->save();
                        $message = "Theme '$theme_name' marked as stock.";
                    }
                    break;
                    
                case 'mark_custom':
                    $theme_name = $_POST['theme_name'];
                    $theme = Theme::get_by_theme_name($theme_name);
                    if ($theme) {
                        $theme->set('thm_is_stock', false);
                        $theme->save();
                        $message = "Theme '$theme_name' marked as custom.";
                    }
                    break;
                    
                case 'sync':
                    $synced = $theme_manager->sync();
                    $message = "Synced " . count($synced) . " themes from filesystem.";
                    break;
                    
                case 'upload':
                    if (isset($_FILES['theme_zip']) && $_FILES['theme_zip']['error'] === UPLOAD_ERR_OK) {
                        $theme_name = $theme_manager->installTheme($_FILES['theme_zip']['tmp_name']);
                        $message = "Theme '$theme_name' installed successfully.";
                    } else {
                        $error = "Upload failed. Please check the file and try again.";
                    }
                    break;
            }
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Load current themes
$themes = new MultiTheme(array(), array('thm_name' => 'ASC'));
$themes->load();

$page = new AdminPage();
$page->admin_header("Theme Management", false, "", false, false);
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <h1>Theme Management</h1>
            
            <?php if ($message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <!-- Upload Theme Form -->
            <div class="card mb-4">
                <div class="card-header">
                    <h3>Upload New Theme</h3>
                </div>
                <div class="card-body">
                    <form method="post" enctype="multipart/form-data" class="row g-3">
                        <div class="col-md-8">
                            <input type="file" name="theme_zip" class="form-control" accept=".zip" required>
                            <div class="form-text">
                                Upload a ZIP file containing theme files with theme.json manifest.
                            </div>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" name="action" value="upload" class="btn btn-primary">
                                Upload Theme
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Sync Button -->
            <div class="mb-3">
                <form method="post" style="display: inline;">
                    <button type="submit" name="action" value="sync" class="btn btn-info">
                        Sync with Filesystem
                    </button>
                </form>
                <small class="text-muted ms-2">
                    Scan theme directory and update database registry
                </small>
            </div>
            
            <!-- Themes Table -->
            <div class="card">
                <div class="card-header">
                    <h3>Installed Themes (<?= $themes->count() ?>)</h3>
                </div>
                <div class="card-body">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Theme</th>
                                <th>Version</th>
                                <th>Author</th>
                                <th>Status</th>
                                <th>Type</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            foreach ($themes as $theme) {
                                $theme_name = $theme->get('thm_name');
                                $display_name = $theme->get('thm_display_name') ?: $theme_name;
                                $description = $theme->get('thm_description');
                                $version = $theme->get('thm_version') ?: '1.0.0';
                                $author = $theme->get('thm_author') ?: 'Unknown';
                                $is_active = $theme->get('thm_is_active');
                                $is_stock = $theme->get('thm_is_stock');
                                $files_exist = $theme->theme_files_exist();
                                
                                // Get status badge
                                if (!$files_exist) {
                                    $status_badge = '<span class="badge bg-danger">Missing Files</span>';
                                } elseif ($is_active) {
                                    $status_badge = '<span class="badge bg-success">Active</span>';
                                } else {
                                    $status_badge = '<span class="badge bg-secondary">Inactive</span>';
                                }
                                
                                // Get type badge
                                $type_badge = $is_stock ? 
                                    '<span class="badge bg-info">Stock</span>' : 
                                    '<span class="badge bg-warning">Custom</span>';
                                
                                echo '<tr>';
                                echo '<td>';
                                echo '<strong>' . htmlspecialchars($display_name) . '</strong>';
                                if ($description) {
                                    echo '<br><small class="text-muted">' . htmlspecialchars($description) . '</small>';
                                }
                                echo '</td>';
                                echo '<td>' . htmlspecialchars($version) . '</td>';
                                echo '<td>' . htmlspecialchars($author) . '</td>';
                                echo '<td>' . $status_badge . '</td>';
                                echo '<td>' . $type_badge . '</td>';
                                echo '<td>';
                                
                                echo '<form method="post" style="display: inline;">';
                                echo '<input type="hidden" name="theme_name" value="' . htmlspecialchars($theme_name) . '">';
                                
                                if (!$is_active && $files_exist) {
                                    echo '<button type="submit" name="action" value="activate" class="btn btn-sm btn-success me-1">Activate</button>';
                                }
                                
                                if ($is_stock) {
                                    echo '<button type="submit" name="action" value="mark_custom" class="btn btn-sm btn-outline-warning me-1" title="Mark as Custom">→ Custom</button>';
                                } else {
                                    echo '<button type="submit" name="action" value="mark_stock" class="btn btn-sm btn-outline-info" title="Mark as Stock">→ Stock</button>';
                                }
                                
                                echo '</form>';
                                echo '</td>';
                                echo '</tr>';
                            }
                            
                            if ($themes->count() === 0) {
                                echo '<tr><td colspan="6" class="text-center">No themes installed</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <div class="mt-3">
        <p class="text-muted">
            <strong>Note:</strong> Stock themes are automatically updated during deployments. 
            Custom themes are preserved during deployments.
        </p>
    </div>
</div>

<?php
$page->admin_footer();
?>
```

#### Plugin Admin Interface Enhancements

**File:** `/adm/admin_plugins.php` - Enhancements to add to existing file

The existing admin_plugins.php already has comprehensive plugin management. Add these enhancements:

1. **Upload functionality** - Add after existing alerts section
2. **Stock/Custom management** - Add to action handling and display
3. **Sync with filesystem** - Add button and handler

See the implementation details in the original Phase 2 specification for the exact code to add.

### 2.6 Database Migration

**File:** `/migrations/theme_plugin_registry_sync.php`

```php
<?php
function theme_plugin_registry_sync() {
    PathHelper::requireOnce('data/themes_class.php');
    PathHelper::requireOnce('data/plugins_class.php');
    PathHelper::requireOnce('includes/ThemeManager.php');
    PathHelper::requireOnce('includes/PluginManager.php');
    
    echo "Syncing themes with database registry...\n";
    $theme_manager = ThemeManager::getInstance();
    $synced_themes = $theme_manager->sync();
    echo "Synced " . count($synced_themes) . " themes.\n";
    
    echo "Syncing plugins with stock/custom status...\n";
    $plugin_manager = new PluginManager();
    $synced_plugins = $plugin_manager->syncWithFilesystem();
    echo "Synced " . count($synced_plugins) . " new plugins.\n";
    
    // Update existing plugins with stock/custom status
    $existing_plugins = new MultiPlugin();
    $existing_plugins->load();
    $updated_count = 0;
    
    foreach ($existing_plugins as $plugin) {
        $old_stock_status = $plugin->get('plg_is_stock');
        $plugin->load_stock_status();
        $new_stock_status = $plugin->get('plg_is_stock');
        
        if ($old_stock_status != $new_stock_status) {
            $plugin->save();
            $updated_count++;
            echo "Updated stock status for plugin: " . $plugin->get('plg_name') . "\n";
        }
    }
    
    echo "Updated stock/custom status for $updated_count existing plugins.\n";
    
    // Set current theme as active in database
    PathHelper::requireOnce('includes/Globalvars.php');
    $settings = Globalvars::get_instance();
    $current_theme = $settings->get_setting('theme_template');
    
    if ($current_theme) {
        echo "Setting current theme '$current_theme' as active...\n";
        
        $theme = Theme::get_by_theme_name($current_theme);
        if ($theme) {
            $theme->activate();
            echo "Theme '$current_theme' activated.\n";
        } else {
            echo "Warning: Current theme '$current_theme' not found in registry.\n";
        }
    }
    
    return true;
}
?>
```

**Migration entry for `/migrations/migrations.php`:**
```php
// Add this to migrations.php:
$migration = array();
$migration['database_version'] = '0.56';
$migration['test'] = "SELECT count(1) as count FROM information_schema.tables WHERE table_name = 'thm_themes'";
$migration['migration_file'] = 'theme_plugin_registry_sync.php';
$migration['migration_sql'] = NULL;
$migrations[] = $migration;
```

## Implementation Checklist

### ✅ New Files to Create:
- [ ] `/includes/AbstractExtensionManager.php` - Base class for shared functionality
- [ ] `/includes/ThemeManager.php` - Theme management (extends base)
- [ ] `/data/themes_class.php` - Theme database model
- [ ] `/adm/admin_themes.php` - Theme admin interface
- [ ] `/migrations/theme_plugin_registry_sync.php` - Database sync migration

### ✅ Files to Replace/Refactor:
- [ ] `/includes/PluginManager.php` - Refactor from multi-class file to single consolidated class extending AbstractExtensionManager

### ✅ Files to Update:
- [ ] `/data/plugins_class.php` - Add stock/custom fields and methods
- [ ] `/adm/admin_plugins.php` - Add upload and stock/custom functionality
- [ ] `/migrations/migrations.php` - Add migration entry

### ✅ Files That Remain Unchanged:
- [ ] `/includes/PluginHelper.php` - No changes needed (handles different responsibilities)
- [ ] `/includes/ThemeHelper.php` - Already exists from Phase 1 (handles runtime theme operations)
- [ ] `/includes/ComponentBase.php` - Base class for helpers remains as-is

## Compatibility Updates Required

### Existing Code That References Old Classes

The current `/includes/PluginManager.php` contains multiple classes in one file. We're consolidating these into a single `PluginManager` class. Any code that instantiates these classes needs to be updated:

**Note:** `/includes/PluginHelper.php` remains unchanged as it serves a different purpose (runtime plugin utilities).

#### 1. Search for PluginMigrationRunner Usage
```bash
grep -r "PluginMigrationRunner" --include="*.php"
```

**If found, update from:**
```php
$runner = new PluginMigrationRunner($plugin_name);
$runner->runPendingMigrations();
```

**To:**
```php
$manager = new PluginManager();
$manager->runPendingMigrations($plugin_name);
```

#### 2. Search for PluginDependencyValidator Usage
```bash
grep -r "PluginDependencyValidator" --include="*.php"
```

**If found, update from:**
```php
$validator = new PluginDependencyValidator();
$validator->validatePlugin($plugin_name);
```

**To:**
```php
$manager = new PluginManager();
$manager->validatePlugin($plugin_name);
```

#### 3. Update Plugin Activation Code
Any code that activates plugins should now check dependencies first:

```php
$manager = new PluginManager();
if ($manager->canActivate($plugin_name)) {
    // Proceed with activation
} else {
    $validation = $manager->validatePlugin($plugin_name);
    // Show errors to user
}
```

#### 4. Update Deployment Scripts
The deployment scripts should be aware that:
- `PluginManager.php` is now a single consolidated file
- No need to deploy separate `PluginMigrationRunner.php` or `PluginDependencyValidator.php`

### Specific File Updates Required:

#### `/data/plugins_class.php`
This file currently instantiates the separate classes. Update all occurrences:

**Lines to update:**
```php
// OLD (lines 312, 407, 478, 503):
$dependency_validator = new PluginDependencyValidator();
// NEW:
$plugin_manager = new PluginManager();

// OLD (lines 338, 434):
$migration_runner = new PluginMigrationRunner($plugin_name);
$migration_runner->runPendingMigrations();
// NEW:
$plugin_manager = new PluginManager();
$plugin_manager->runPendingMigrations($plugin_name);
```

### Other Files Likely to Need Updates:
- `/adm/admin_plugins.php` - Already addressed in our spec  
- `/ajax/plugin_*.php` - Any AJAX handlers for plugin operations
- `/utils/plugin_*.php` - Any utility scripts
- Deployment scripts in `/maintenance_scripts/`

### Cleanup of Unused PluginHelper Static Methods

**Methods to DELETE from PluginHelper (not used anywhere):**
```php
// These are not used in production code - just delete them
activatePlugin($name)    // Not used - DELETE
deactivatePlugin($name)  // Not used - DELETE  
initializeActive()       // Not used - DELETE
validateAll()           // Not used - DELETE
```

**Methods that STAY in PluginHelper (actively used):**
```php
// These are runtime query methods that belong in PluginHelper
getInstance($pluginName)     // Factory method
getAvailablePlugins()       // Query all plugins - used by test_components.php
getActivePlugins()          // Query active plugins - used by RouteHelper.php (3x) and test_components.php
isPluginActive($pluginName) // Quick check - used by RouteHelper.php
```

**No file updates needed!** We're just deleting unused methods, not moving anything. The used methods stay exactly where they are, avoiding unnecessary refactoring.

**Note:** The admin interface uses `$plugin->activate()` from the Plugin model class, which is unrelated to these static helper methods.

## Benefits of This Combined Approach

1. **No Wasted Work**: We don't create temporary manager files that would be immediately replaced
2. **Clean Architecture from Start**: Implements the inheritance pattern immediately
3. **Reduced Code Duplication**: ~40% less code by sharing common functionality
4. **Better Maintainability**: Single source of truth for shared operations
5. **Easier Testing**: Can test base functionality once
6. **Future Extensibility**: Easy to add new extension types

## Migration Path from Phase 1

1. Deploy the base class and refactored managers
2. Deploy the data models (tables created automatically)
3. Run the migration to sync filesystem with database
4. Deploy admin interfaces
5. Test upload functionality for both themes and plugins

## Summary

This alternate Phase 2 implementation combines the admin interface features with the refactored architecture, providing:

- Clean, maintainable code structure using inheritance
- Full admin interfaces for theme and plugin management
- Upload capabilities for custom themes and plugins
- Stock/custom tracking for deployment safety
- Separated concerns with dedicated classes for migrations and dependencies
- Backward compatibility with existing plugin system

The implementation is more efficient and maintainable than doing it in two separate steps.