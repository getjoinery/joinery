<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));

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
     * @return array Result with counts: ['added' => array, 'updated' => array, 'total' => int]
     */
    public function sync() {
        $extension_dir = PathHelper::getAbsolutePath($this->extension_dir);
        if (!is_dir($extension_dir)) {
            mkdir($extension_dir, 0775, true);
            return array('added' => array(), 'updated' => array(), 'total' => 0);
        }
        
        $added = array();
        $updated = array();
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
                
                $added[] = $dir;
            } else {
                // Update metadata for existing extension
                $was_updated = $this->updateExistingMetadata($existing, $dir);
                if ($was_updated) {
                    $updated[] = $dir;
                }
            }
        }
        
        return array(
            'added' => $added,
            'updated' => $updated, 
            'total' => count($added) + count($updated)
        );
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