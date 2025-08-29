# Theme and Plugin Manager Refactoring Specification

## Current Issues

### PluginManager.php Structure Problems
The file contains multiple separate classes doing related tasks:
- `PluginMigrationRunner` - Handles database migrations
- `PluginVersionDetector` - Detects version changes
- `PluginDependencyValidator` - Validates dependencies  
- `PluginSystemRepair` - Repairs plugin system
- `PluginManager` - Handles installation (being added)

This violates single responsibility principle and makes the file difficult to maintain.

### Code Duplication Between Themes and Plugins
Both ThemeManager and PluginManager need nearly identical functionality:

| Functionality | Theme Implementation | Plugin Implementation | Similarity |
|--------------|---------------------|----------------------|------------|
| Install from ZIP | `installTheme()` | `installPlugin()` | 95% same |
| Validate names | `is_valid_theme_name()` | `is_valid_plugin_name()` | 90% same |
| Read manifest | `theme.json` | `plugin.json` | 85% same |
| Set permissions | Same code | Same code | 100% same |
| Sync with DB | `syncThemes()` | Would need similar | 90% same |
| Cleanup temp files | Same code | Same code | 100% same |
| Check dependencies | Not yet implemented | `PluginDependencyValidator` | Would be 80% same |
| Run migrations | Not yet implemented | `PluginMigrationRunner` | Would be 70% same |

## Proposed Solution: ExtensionManager Base Class

### Architecture

```
AbstractExtensionManager (base class)
    ├── ThemeManager (extends base)
    ├── PluginManager (extends base)
    └── Shared Traits/Utilities
        ├── ExtensionInstaller
        ├── ExtensionValidator  
        ├── ExtensionMigrator
        └── ExtensionDependencyChecker
```

### Base Class Implementation

```php
<?php
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
     * Validate extension name
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
     * Install from ZIP file
     */
    public function installFromZip($zip_path) {
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
     * Set proper permissions
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
     * Sync filesystem with database
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
            
            // Check if exists in database
            $model_class = $this->model_class;
            $existing = $model_class::GetByColumn($this->table_prefix . '_name', $dir);
            
            if (!$existing) {
                // New extension, add to database
                $extension = new $model_class(null);
                $extension->set($this->table_prefix . '_name', $dir);
                $extension->set($this->table_prefix . '_status', 'installed');
                $extension->set($this->table_prefix . '_installed_time', 'now()');
                
                // Load metadata from manifest
                $this->loadMetadataIntoModel($extension, $dir);
                $extension->save();
            }
            
            $synced[] = $dir;
        }
        
        return $synced;
    }
    
    /**
     * Get path for extension
     */
    protected function getExtensionPath($name) {
        return PathHelper::getAbsolutePath($this->extension_dir . '/' . $name);
    }
    
    // Abstract methods that subclasses must implement
    abstract protected function getAdditionalReservedNames();
    abstract protected function findAndValidateManifest($temp_dir);
    abstract protected function handleExistingExtension($path);
    abstract protected function postInstall($name, $manifest);
    abstract protected function loadMetadataIntoModel($model, $name);
}
```

### ThemeManager Implementation

```php
<?php
class ThemeManager extends AbstractExtensionManager {
    
    private static $instance = null;
    
    public function __construct() {
        $this->extension_type = 'theme';
        $this->extension_dir = 'theme';
        $this->manifest_filename = 'theme.json';
        $this->table_prefix = 'thm';
        $this->model_class = 'Theme';
        $this->multi_model_class = 'MultiTheme';
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    protected function getAdditionalReservedNames() {
        return array('plugins', 'plugin');
    }
    
    protected function findAndValidateManifest($temp_dir) {
        // Look for theme.json
        $manifest_path = null;
        $theme_name = null;
        $theme_root = null;
        
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
        if (!$manifest) {
            throw new Exception("Invalid theme.json");
        }
        
        // Determine theme name
        if (!$theme_name && isset($manifest['name'])) {
            $theme_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($manifest['name']));
            if (!preg_match('/^[a-zA-Z]/', $theme_name)) {
                $theme_name = 'theme_' . $theme_name;
            }
        }
        
        return array(
            'root' => $theme_root,
            'manifest' => $manifest,
            'name' => $theme_name
        );
    }
    
    protected function handleExistingExtension($path) {
        // Backup existing theme
        $backup_path = $path . '_backup_' . date('YmdHis');
        rename($path, $backup_path);
    }
    
    protected function postInstall($name, $manifest) {
        // Sync themes with database
        $this->sync();
    }
    
    protected function loadMetadataIntoModel($theme, $name) {
        $manifest_path = $this->getExtensionPath($name) . '/theme.json';
        if (!file_exists($manifest_path)) return;
        
        $metadata = json_decode(file_get_contents($manifest_path), true);
        if ($metadata) {
            $theme->set('thm_metadata', $metadata);
            
            if (isset($metadata['version'])) $theme->set('thm_version', $metadata['version']);
            if (isset($metadata['description'])) $theme->set('thm_description', $metadata['description']);
            if (isset($metadata['author'])) $theme->set('thm_author', $metadata['author']);
            if (isset($metadata['is_stock'])) $theme->set('thm_is_stock', $metadata['is_stock']);
        }
    }
    
    // Theme-specific methods
    public function getActiveTheme() {
        $active_themes = new MultiTheme(array('thm_is_active' => true));
        $active_themes->load();
        
        if ($active_themes->count() > 0) {
            return $active_themes->get(0);
        }
        
        return null;
    }
    
    public function setActiveTheme($theme_name) {
        $theme = Theme::get_by_theme_name($theme_name);
        
        if (!$theme) {
            throw new Exception("Theme not found: $theme_name");
        }
        
        return $theme->activate();
    }
}
```

### PluginManager Implementation

```php
<?php
class PluginManager extends AbstractExtensionManager {
    
    public function __construct() {
        $this->extension_type = 'plugin';
        $this->extension_dir = 'plugins';
        $this->manifest_filename = 'plugin.json';
        $this->table_prefix = 'plg';
        $this->model_class = 'Plugin';
        $this->multi_model_class = 'MultiPlugin';
    }
    
    protected function getAdditionalReservedNames() {
        return array('theme', 'themes', 'core', 'system');
    }
    
    protected function findAndValidateManifest($temp_dir) {
        // Similar to theme but looks for plugin.json
        // ... implementation similar to ThemeManager
    }
    
    protected function handleExistingExtension($path) {
        throw new Exception("Plugin already exists. Please uninstall first.");
    }
    
    protected function postInstall($name, $manifest) {
        // Run plugin migrations if any
        $migration_runner = new PluginMigrationRunner($name);
        $migration_runner->runPendingMigrations();
        
        // Check dependencies
        $validator = new PluginDependencyValidator();
        $validator->validatePlugin($name);
        
        // Sync with database
        $this->sync();
    }
    
    protected function loadMetadataIntoModel($plugin, $name) {
        // Similar to theme but for plugin fields
        // ... implementation
    }
}
```

## Benefits of Refactoring

### 1. Code Reduction
- **~40% less code** overall by eliminating duplication
- Single implementation of common functionality
- Easier to add new features (apply once, works for both)

### 2. Consistency
- Same validation rules automatically applied
- Same error handling patterns
- Same security measures

### 3. Maintainability
- Fix bugs in one place
- Add features to base class, both inherit
- Clear separation of concerns

### 4. Testability
- Test base functionality once
- Only test extension-specific code in subclasses
- Mock base class for isolated testing

### 5. Extensibility
- Easy to add new extension types (e.g., "modules", "components")
- Traits can be mixed in for specific capabilities
- Dependency injection becomes cleaner

## Migration Strategy

### Phase 1: Create Base Class
1. Create `AbstractExtensionManager` with shared functionality
2. Create trait classes for specific capabilities:
   - `ExtensionInstaller`
   - `ExtensionValidator`
   - `ExtensionMigrator`

### Phase 2: Refactor ThemeManager
1. Extend from `AbstractExtensionManager`
2. Remove duplicate code
3. Implement abstract methods
4. Test thoroughly

### Phase 3: Refactor PluginManager
1. Split current PluginManager.php into separate files:
   - `PluginManager.php` (main class)
   - `PluginMigrationRunner.php`
   - `PluginVersionDetector.php`
   - `PluginDependencyValidator.php`
2. Make PluginManager extend `AbstractExtensionManager`
3. Test thoroughly

### Phase 4: Optimize
1. Look for additional shared patterns
2. Consider adding interfaces for contracts
3. Add comprehensive unit tests

## Considerations

### Backward Compatibility
- Keep existing public methods with same signatures
- Deprecate old methods gradually
- Maintain database schema

### Performance
- Lazy loading of dependencies
- Cache manifest data in database
- Optimize file operations

### Security
- Centralized validation means one place to secure
- Add checksum verification for installed extensions
- Consider signed packages in future

## Future Enhancements

Once refactored, these become much easier to implement:

1. **Version Management**
   - Track version history
   - Rollback capability
   - Update notifications

2. **Dependency Resolution**
   - Automatic dependency installation
   - Conflict detection
   - Version constraints

3. **Marketplace Integration**
   - Direct installation from repository
   - Update checking
   - License validation

4. **CLI Tools**
   - Install/uninstall via command line
   - Bulk operations
   - Automated testing

5. **Development Mode**
   - Hot reloading
   - Symlink support for development
   - Debug tools

## Conclusion

The refactoring would provide significant benefits with relatively low risk. The shared functionality between themes and plugins makes them ideal candidates for inheritance from a common base class. This would reduce code duplication, improve maintainability, and make the system more robust and extensible.