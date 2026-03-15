<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/AbstractExtensionManager.php'));
require_once(PathHelper::getIncludePath('data/plugins_class.php'));
require_once(PathHelper::getIncludePath('data/plugin_dependencies_class.php'));
require_once(PathHelper::getIncludePath('data/plugin_migrations_class.php'));

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

        // Register deletion rules for this plugin's models
        require_once(PathHelper::getIncludePath('data/deletion_rule_class.php'));
        try {
            DeletionRule::registerModelsFromDiscovery([
                'include_plugins' => true,
                'plugin_filter' => $name
            ]);
        } catch (Exception $e) {
            error_log("Failed to register deletion rules for plugin {$name}: " . $e->getMessage());
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
     * Run all pending migrations for a plugin.
     * Supports both .sql files and PHP migrations.php (return [] with up/down closures).
     *
     * @param string $plugin_name Plugin name
     * @return array Results of migration runs
     */
    public function runPendingMigrations($plugin_name) {
        require_once(PathHelper::getIncludePath('data/plugin_migrations_class.php'));

        $results = array();
        $migration_dir = PathHelper::getAbsolutePath("plugins/{$plugin_name}/migrations");

        if (!is_dir($migration_dir)) {
            return $results;
        }

        // Run PHP migrations (migrations.php with return [] format)
        $php_migration_file = $migration_dir . '/migrations.php';
        if (file_exists($php_migration_file)) {
            $php_results = $this->runPhpMigrations($plugin_name, $php_migration_file);
            $results = array_merge($results, $php_results);
        }

        // Run SQL migration files
        $sql_files = glob($migration_dir . '/*.sql');
        if (!empty($sql_files)) {
            sort($sql_files);
            foreach ($sql_files as $file) {
                $sql_result = $this->runSqlMigration($plugin_name, $file);
                if ($sql_result !== null) {
                    $results[] = $sql_result;
                }
            }
        }

        return $results;
    }

    /**
     * Run PHP migrations from a migrations.php file.
     * Format: return [ ['id' => '...', 'version' => '...', 'up' => function($dbconnector) {...}], ... ]
     *
     * @param string $plugin_name Plugin name
     * @param string $file Path to migrations.php
     * @return array Results of migration runs
     */
    private function runPhpMigrations($plugin_name, $file) {
        $results = array();

        try {
            $migrations = require($file);
        } catch (Exception $e) {
            $results[] = array('success' => false, 'id' => 'load_error', 'error' => 'Failed to load migrations.php: ' . $e->getMessage());
            return $results;
        }

        if (!is_array($migrations)) {
            return $results;
        }

        $dbconnector = DbConnector::get_instance();

        foreach ($migrations as $migration) {
            $migration_id = $migration['id'] ?? null;
            $version = $migration['version'] ?? '0.0.0';

            if (!$migration_id) {
                continue;
            }

            // Check if already applied
            if ($this->isMigrationApplied($plugin_name, $migration_id)) {
                continue;
            }

            // Run the up function
            $result = array('success' => false, 'id' => $migration_id);

            try {
                $up = $migration['up'] ?? null;
                if (is_callable($up)) {
                    $up_result = $up($dbconnector);
                    $result['success'] = ($up_result !== false);
                } else {
                    $result['success'] = true; // No up function — nothing to do
                }
            } catch (Exception $e) {
                $result['success'] = false;
                $result['error'] = $e->getMessage();
            }

            // Record the migration
            $record = new PluginMigration(NULL);
            $record->set('plm_plugin_name', $plugin_name);
            $record->set('plm_migration_id', $migration_id);
            $record->set('plm_version', $version);
            $record->set('plm_applied_time', gmdate('Y-m-d H:i:s'));
            $record->set('plm_status', $result['success'] ? 'applied' : 'error');
            if (!empty($result['error'])) {
                $record->set('plm_error_message', $result['error']);
            }
            $record->save();

            $results[] = $result;
        }

        return $results;
    }

    /**
     * Run a single .sql migration file if not already applied.
     *
     * @param string $plugin_name Plugin name
     * @param string $file Path to .sql file
     * @return array|null Result, or null if already applied
     */
    private function runSqlMigration($plugin_name, $file) {
        $filename = basename($file);
        $migration_id = 'sql_' . $filename;

        // Check if already applied
        if ($this->isMigrationApplied($plugin_name, $migration_id)) {
            return null;
        }

        $result = array('success' => false, 'id' => $migration_id);

        try {
            $sql = file_get_contents($file);
            if (empty(trim($sql))) {
                $result['success'] = true;
            } else {
                $dblink = DbConnector::get_instance()->get_db_link();
                $dblink->exec($sql);
                $result['success'] = true;
            }
        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
        }

        // Record the migration
        $record = new PluginMigration(NULL);
        $record->set('plm_plugin_name', $plugin_name);
        $record->set('plm_migration_id', $migration_id);
        $record->set('plm_version', '0.0.0');
        $record->set('plm_applied_time', gmdate('Y-m-d H:i:s'));
        $record->set('plm_status', $result['success'] ? 'applied' : 'error');
        if (!empty($result['error'])) {
            $record->set('plm_error_message', $result['error']);
        }
        $record->save();

        return $result;
    }

    /**
     * Check if a migration has already been applied.
     *
     * @param string $plugin_name Plugin name
     * @param string $migration_id Migration identifier
     * @return bool
     */
    private function isMigrationApplied($plugin_name, $migration_id) {
        $db = DbConnector::get_instance()->get_db_link();
        $sql = "SELECT COUNT(*) as cnt FROM plm_plugin_migrations
                WHERE plm_plugin_name = ? AND plm_migration_id = ? AND plm_status = 'applied'";
        $stmt = $db->prepare($sql);
        $stmt->execute([$plugin_name, $migration_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ($row['cnt'] > 0);
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
        $result = parent::sync();

        // Register deletion rules for ALL active plugins
        // This ensures deletion rules are up-to-date even if plugin code changed
        require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));
        PluginHelper::registerAllActiveDeletionRules();

        return $result;
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