<?php
require_once('PathHelper.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');

/**
 * PluginManager - Combined plugin management system
 * 
 * This file contains all the enhanced plugin management classes:
 * - PluginMigrationRunner: Handles plugin-specific database migrations
 * - PluginVersionDetector: Detects plugin version changes and updates
 * - PluginDependencyValidator: Validates plugin dependencies and requirements
 */

/**
 * PluginMigrationRunner - Handles plugin-specific database migrations
 * 
 * This class manages the execution and rollback of plugin migrations,
 * tracking their state in the plm_plugin_migrations table.
 */
class PluginMigrationRunner {
    
    private $dbconnector;
    private $plugin_name;
    private $migrations_path;
    private $applied_migrations = [];
    
    /**
     * Constructor
     * 
     * @param string $plugin_name Name of the plugin
     * @param string $migrations_path Path to the plugin's migrations directory
     */
    public function __construct($plugin_name, $migrations_path = null) {
        $this->dbconnector = DbConnector::get_instance();
        $this->plugin_name = $plugin_name;
        
        if ($migrations_path === null) {
            $this->migrations_path = PathHelper::getIncludePath('plugins/' . $plugin_name . '/migrations/');
        } else {
            $this->migrations_path = $migrations_path;
        }
        
        $this->loadAppliedMigrations();
    }
    
    /**
     * Load list of already applied migrations
     */
    private function loadAppliedMigrations() {
        try {
            $sql = "SELECT plm_migration_id FROM plm_plugin_migrations 
                    WHERE plm_plugin_name = ? AND plm_status = 'applied'
                    ORDER BY plm_applied_time";
            
            $dblink = $this->dbconnector->get_db_link();
            $q = $dblink->prepare($sql);
            $q->execute([$this->plugin_name]);
            $result = $q->fetchAll(PDO::FETCH_ASSOC);
            
            if ($result) {
                foreach ($result as $row) {
                    $this->applied_migrations[] = $row['plm_migration_id'];
                }
            }
        } catch (Exception $e) {
            // Table might not exist yet if this is the first run
            $this->applied_migrations = [];
        }
    }
    
    /**
     * Get available migrations from the plugin's migrations directory
     * 
     * @return array Array of migration definitions
     */
    private function getAvailableMigrations() {
        $migrations_file = $this->migrations_path . 'migrations.php';
        
        if (!file_exists($migrations_file)) {
            return [];
        }
        
        // Include the migrations file and capture its return value
        $migrations = include($migrations_file);
        
        // Ensure we have an array
        if (!is_array($migrations)) {
            return [];
        }
        
        return $migrations;
    }
    
    /**
     * Run all pending migrations for the plugin
     * 
     * @return array Result array with status and messages
     */
    public function migrate() {
        $results = [
            'success' => true,
            'migrations_run' => 0,
            'messages' => [],
            'errors' => []
        ];
        
        try {
            $available_migrations = $this->getAvailableMigrations();
            
            foreach ($available_migrations as $migration) {
                if (!$this->validateMigration($migration)) {
                    $results['errors'][] = "Invalid migration format: " . json_encode($migration);
                    continue;
                }
                
                $migration_id = $migration['id'];
                
                // Skip if already applied
                if (in_array($migration_id, $this->applied_migrations)) {
                    continue;
                }
                
                // Check dependencies
                if (isset($migration['depends_on']) && is_array($migration['depends_on'])) {
                    foreach ($migration['depends_on'] as $dependency) {
                        if (!in_array($dependency, $this->applied_migrations)) {
                            $results['errors'][] = "Migration '{$migration_id}' depends on '{$dependency}' which has not been applied";
                            $results['success'] = false;
                            continue 2;
                        }
                    }
                }
                
                // Execute the migration
                $result = $this->executeMigration($migration, 'up');
                
                if ($result['success']) {
                    $results['migrations_run']++;
                    $results['messages'][] = "Applied migration: {$migration_id}";
                    $this->applied_migrations[] = $migration_id;
                } else {
                    $results['success'] = false;
                    $results['errors'][] = "Failed to apply migration '{$migration_id}': " . $result['error'];
                    break; // Stop on first error
                }
            }
            
        } catch (Exception $e) {
            $results['success'] = false;
            $results['errors'][] = "Migration error: " . $e->getMessage();
        }
        
        return $results;
    }
    
    /**
     * Rollback migrations to a specific point
     * 
     * @param string $target Target migration ID to rollback to (null = rollback all)
     * @return array Result array with status and messages
     */
    public function rollback($target = null) {
        $results = [
            'success' => true,
            'migrations_rolled_back' => 0,
            'messages' => [],
            'errors' => []
        ];
        
        try {
            $available_migrations = $this->getAvailableMigrations();
            $migrations_by_id = [];
            
            // Index migrations by ID
            foreach ($available_migrations as $migration) {
                $migrations_by_id[$migration['id']] = $migration;
            }
            
            // Get applied migrations in reverse order
            $applied_reversed = array_reverse($this->applied_migrations);
            
            foreach ($applied_reversed as $migration_id) {
                // Stop if we've reached the target
                if ($target !== null && $migration_id === $target) {
                    break;
                }
                
                // Skip if migration definition not found
                if (!isset($migrations_by_id[$migration_id])) {
                    $results['errors'][] = "Migration definition not found for: {$migration_id}";
                    continue;
                }
                
                $migration = $migrations_by_id[$migration_id];
                
                // Execute the rollback
                $result = $this->executeMigration($migration, 'down');
                
                if ($result['success']) {
                    $results['migrations_rolled_back']++;
                    $results['messages'][] = "Rolled back migration: {$migration_id}";
                    
                    // Remove from applied list
                    $key = array_search($migration_id, $this->applied_migrations);
                    if ($key !== false) {
                        unset($this->applied_migrations[$key]);
                    }
                } else {
                    $results['success'] = false;
                    $results['errors'][] = "Failed to rollback migration '{$migration_id}': " . $result['error'];
                    break; // Stop on first error
                }
            }
            
        } catch (Exception $e) {
            $results['success'] = false;
            $results['errors'][] = "Rollback error: " . $e->getMessage();
        }
        
        return $results;
    }
    
    /**
     * Execute a single migration
     * 
     * @param array $migration Migration definition
     * @param string $direction 'up' or 'down'
     * @return array Result array with success status and error message
     */
    private function executeMigration($migration, $direction = 'up') {
        $result = ['success' => false, 'error' => ''];
        
        try {
            $this->dbconnector->beginTransaction();
            
            $migration_id = $migration['id'];
            $version = $migration['version'] ?? '0.0.0';
            
            // Execute the migration function
            if ($direction === 'up' && isset($migration['up']) && is_callable($migration['up'])) {
                $success = $migration['up']($this->dbconnector);
            } elseif ($direction === 'down' && isset($migration['down']) && is_callable($migration['down'])) {
                $success = $migration['down']($this->dbconnector);
            } else {
                throw new Exception("Migration '{$migration_id}' does not have a valid '{$direction}' method");
            }
            
            if ($success !== false) {
                // Record the migration in the tracking table
                if ($direction === 'up') {
                    $sql = "INSERT INTO plm_plugin_migrations 
                            (plm_plugin_name, plm_migration_id, plm_version, plm_status, plm_up_sql, plm_down_sql) 
                            VALUES (?, ?, ?, 'applied', ?, ?)";
                    
                    $up_sql = $this->getFunctionSource($migration['up']);
                    $down_sql = isset($migration['down']) ? $this->getFunctionSource($migration['down']) : null;
                    
                    $dblink = $this->dbconnector->get_db_link();
                    $q = $dblink->prepare($sql);
                    $q->execute([
                        $this->plugin_name,
                        $migration_id,
                        $version,
                        $up_sql,
                        $down_sql
                    ]);
                } else {
                    // Update the migration record for rollback
                    $sql = "UPDATE plm_plugin_migrations 
                            SET plm_status = 'rolled_back', 
                                plm_rollback_time = NOW() 
                            WHERE plm_plugin_name = ? AND plm_migration_id = ?";
                    
                    $dblink = $this->dbconnector->get_db_link();
                    $q = $dblink->prepare($sql);
                    $q->execute([$this->plugin_name, $migration_id]);
                }
                
                $this->dbconnector->commit();
                $result['success'] = true;
            } else {
                throw new Exception("Migration function returned false");
            }
            
        } catch (Exception $e) {
            $this->dbconnector->rollback();
            $result['error'] = $e->getMessage();
            
            // Log the error
            try {
                $sql = "INSERT INTO plm_plugin_migrations 
                        (plm_plugin_name, plm_migration_id, plm_version, plm_status, plm_error_message) 
                        VALUES (?, ?, ?, 'failed', ?)
                        ON CONFLICT (plm_plugin_name, plm_migration_id) 
                        DO UPDATE SET plm_status = 'failed', plm_error_message = ?";
                
                $dblink = $this->dbconnector->get_db_link();
                $q = $dblink->prepare($sql);
                $q->execute([
                    $this->plugin_name,
                    $migration_id,
                    $version,
                    $e->getMessage(),
                    $e->getMessage()
                ]);
            } catch (Exception $logError) {
                // Ignore logging errors
            }
        }
        
        return $result;
    }
    
    /**
     * Validate migration structure
     * 
     * @param mixed $migration Migration to validate
     * @return bool True if valid
     */
    private function validateMigration($migration) {
        if (!is_array($migration)) {
            return false;
        }
        
        // Required fields
        if (!isset($migration['id']) || !isset($migration['up'])) {
            return false;
        }
        
        // ID must be a string
        if (!is_string($migration['id'])) {
            return false;
        }
        
        // up must be callable
        if (!is_callable($migration['up'])) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Get function source code for storage
     * 
     * @param callable $function Function to get source from
     * @return string|null Source code or null
     */
    private function getFunctionSource($function) {
        if (!is_callable($function)) {
            return null;
        }
        
        try {
            $reflection = new ReflectionFunction($function);
            $filename = $reflection->getFileName();
            $start_line = $reflection->getStartLine();
            $end_line = $reflection->getEndLine();
            
            if ($filename && $start_line && $end_line) {
                $source = file($filename);
                $body = implode("", array_slice($source, $start_line - 1, $end_line - $start_line + 1));
                return $body;
            }
        } catch (Exception $e) {
            // Unable to get source
        }
        
        return null;
    }
    
    /**
     * Get migration status for the plugin
     * 
     * @return array Status information
     */
    public function getStatus() {
        $available = $this->getAvailableMigrations();
        $pending = [];
        
        foreach ($available as $migration) {
            if (!in_array($migration['id'], $this->applied_migrations)) {
                $pending[] = $migration['id'];
            }
        }
        
        return [
            'plugin' => $this->plugin_name,
            'applied_count' => count($this->applied_migrations),
            'pending_count' => count($pending),
            'applied' => $this->applied_migrations,
            'pending' => $pending
        ];
    }
}

/**
 * PluginVersionDetector - Detects plugin version changes and updates
 * 
 * This class monitors plugin versions by reading plugin.json files and
 * comparing them against stored versions in the database.
 */
class PluginVersionDetector {
    
    private $dbconnector;
    private $cache_duration = 3600; // 1 hour cache by default
    private $version_cache = [];
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->dbconnector = DbConnector::get_instance();
        // Use default cache duration - no settings needed for organic checking
    }
    
    /**
     * Check if a plugin has an available update
     * 
     * @param string $plugin_name Plugin directory name
     * @param bool $force_check Force check even if cached
     * @return array Version information
     */
    public function checkForUpdate($plugin_name, $force_check = false) {
        // Check memory cache first
        if (!$force_check && isset($this->version_cache[$plugin_name])) {
            return $this->version_cache[$plugin_name];
        }
        
        try {
            // Get stored version info
            $stored = $this->getStoredVersion($plugin_name);
            
            // Check if we need to recheck based on cache
            if (!$force_check && $stored && $this->isCacheValid($stored['plv_last_check_time'])) {
                $result = [
                    'installed_version' => $stored['plv_installed_version'],
                    'available_version' => $stored['plv_available_version'],
                    'update_available' => (bool)$stored['plv_update_available'],
                    'last_check' => $stored['plv_last_check_time']
                ];
                $this->version_cache[$plugin_name] = $result;
                return $result;
            }
            
            // Read current version from plugin.json
            $current_version = $this->readPluginVersion($plugin_name);
            
            if ($current_version === null) {
                // No plugin.json found
                return [
                    'installed_version' => $stored ? $stored['plv_installed_version'] : '0.0.0',
                    'available_version' => null,
                    'update_available' => false,
                    'error' => 'No plugin.json found'
                ];
            }
            
            // Determine update availability
            if ($stored) {
                // We have a stored version record - compare against it
                $installed_version = $stored['plv_installed_version'];
                $update_available = version_compare($current_version, $installed_version, '>');
            } else {
                // No stored version record - this means plugin.json was just created
                // Treat the current version as both installed and available (no update needed)
                $installed_version = $current_version;
                $update_available = false;
            }
            
            // Update database
            $this->updateStoredVersion($plugin_name, $installed_version, $current_version, $update_available);
            
            $result = [
                'installed_version' => $installed_version,
                'available_version' => $current_version,
                'update_available' => $update_available,
                'last_check' => date('Y-m-d H:i:s')
            ];
            
            $this->version_cache[$plugin_name] = $result;
            return $result;
            
        } catch (Exception $e) {
            return [
                'installed_version' => '0.0.0',
                'available_version' => null,
                'update_available' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Check all active plugins for updates
     * 
     * @return array Array of plugins with updates available
     */
    public function checkAllPlugins() {
        $updates_available = [];
        
        try {
            // Get all active plugins
            PathHelper::requireOnce('data/plugins_class.php');
            $plugins = new MultiPlugin(['plg_status' => 'active']);
            $plugins->load();
            
            foreach ($plugins as $plugin) {
                $plugin_name = $plugin->get('plg_name');
                $version_info = $this->checkForUpdate($plugin_name);
                
                if ($version_info['update_available']) {
                    $updates_available[$plugin_name] = $version_info;
                }
            }
            
        } catch (Exception $e) {
            error_log("Error checking all plugins for updates: " . $e->getMessage());
        }
        
        return $updates_available;
    }
    
    /**
     * Read plugin version from plugin.json file
     * 
     * @param string $plugin_name Plugin directory name
     * @return string|null Version string or null if not found
     */
    private function readPluginVersion($plugin_name) {
        $plugin_json_path = PathHelper::getIncludePath('plugins/' . $plugin_name . '/plugin.json');
        
        if (!file_exists($plugin_json_path)) {
            return null;
        }
        
        try {
            $json_content = file_get_contents($plugin_json_path);
            $plugin_info = json_decode($json_content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Invalid JSON in plugin.json: " . json_last_error_msg());
            }
            
            if (!isset($plugin_info['version'])) {
                throw new Exception("No version field in plugin.json");
            }
            
            // Validate version format
            if (!preg_match('/^\d+\.\d+\.\d+(-[a-zA-Z0-9]+)?$/', $plugin_info['version'])) {
                throw new Exception("Invalid version format: " . $plugin_info['version']);
            }
            
            return $plugin_info['version'];
            
        } catch (Exception $e) {
            error_log("Error reading plugin version for {$plugin_name}: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get stored version information from database
     * 
     * @param string $plugin_name Plugin name
     * @return array|null Version data or null if not found
     */
    private function getStoredVersion($plugin_name) {
        try {
            $sql = "SELECT * FROM plv_plugin_versions WHERE plv_plugin_name = ?";
            $dblink = $this->dbconnector->get_db_link();
            $q = $dblink->prepare($sql);
            $q->execute([$plugin_name]);
            $result = $q->fetchAll(PDO::FETCH_ASSOC);
            
            if ($result && count($result) > 0) {
                return $result[0];
            }
        } catch (Exception $e) {
            // Table might not exist yet
        }
        
        return null;
    }
    
    /**
     * Update stored version information in database
     * 
     * @param string $plugin_name Plugin name
     * @param string $installed_version Currently installed version
     * @param string $available_version Available version from plugin.json
     * @param bool $update_available Whether an update is available
     */
    private function updateStoredVersion($plugin_name, $installed_version, $available_version, $update_available) {
        try {
            $sql = "INSERT INTO plv_plugin_versions 
                    (plv_plugin_name, plv_installed_version, plv_available_version, plv_update_available, plv_last_check_time) 
                    VALUES (?, ?, ?, ?, NOW())
                    ON CONFLICT (plv_plugin_name) 
                    DO UPDATE SET 
                        plv_available_version = EXCLUDED.plv_available_version,
                        plv_update_available = EXCLUDED.plv_update_available,
                        plv_last_check_time = NOW()";
            
            $dblink = $this->dbconnector->get_db_link();
            $q = $dblink->prepare($sql);
            $q->execute([
                $plugin_name,
                $installed_version,
                $available_version,
                $update_available ? 1 : 0
            ]);
            
        } catch (Exception $e) {
            error_log("Error updating stored version for {$plugin_name}: " . $e->getMessage());
        }
    }
    
    /**
     * Mark a plugin as updated after successful update
     * 
     * @param string $plugin_name Plugin name
     * @param string $new_version New installed version
     */
    public function markAsUpdated($plugin_name, $new_version) {
        try {
            $sql = "UPDATE plv_plugin_versions 
                    SET plv_installed_version = ?, 
                        plv_available_version = ?,
                        plv_update_available = FALSE,
                        plv_last_check_time = NOW()
                    WHERE plv_plugin_name = ?";
            
            $dblink = $this->dbconnector->get_db_link();
            $q = $dblink->prepare($sql);
            $q->execute([$new_version, $new_version, $plugin_name]);
            
            // Clear cache
            unset($this->version_cache[$plugin_name]);
            
        } catch (Exception $e) {
            error_log("Error marking plugin as updated: " . $e->getMessage());
        }
    }
    
    /**
     * Check if cache is still valid
     * 
     * @param string $last_check_time Last check timestamp
     * @return bool True if cache is valid
     */
    private function isCacheValid($last_check_time) {
        if (!$last_check_time) {
            return false;
        }
        
        $last_check = strtotime($last_check_time);
        $now = time();
        
        return ($now - $last_check) < $this->cache_duration;
    }
    
    /**
     * Clear all caches
     */
    public function clearCache() {
        $this->version_cache = [];
        
        try {
            // Force all plugins to be rechecked on next access
            $sql = "UPDATE plv_plugin_versions SET plv_last_check_time = NOW() - INTERVAL '1 year'";
            $dblink = $this->dbconnector->get_db_link();
            $q = $dblink->prepare($sql);
            $q->execute();
        } catch (Exception $e) {
            error_log("Error clearing version cache: " . $e->getMessage());
        }
    }
    
    /**
     * Get plugin metadata from plugin.json
     * 
     * @param string $plugin_name Plugin name
     * @return array|null Full plugin.json data or null
     */
    public function getPluginMetadata($plugin_name) {
        $plugin_json_path = PathHelper::getIncludePath('plugins/' . $plugin_name . '/plugin.json');
        
        if (!file_exists($plugin_json_path)) {
            return null;
        }
        
        try {
            $json_content = file_get_contents($plugin_json_path);
            $plugin_info = json_decode($json_content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }
            
            return $plugin_info;
            
        } catch (Exception $e) {
            return null;
        }
    }
}

/**
 * PluginDependencyValidator - Validates plugin dependencies and requirements
 * 
 * This class checks plugin dependencies, PHP version requirements,
 * PHP extensions, and conflicts between plugins.
 */
class PluginDependencyValidator {
    
    private $dbconnector;
    private $active_plugins = [];
    private $version_detector;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->dbconnector = DbConnector::get_instance();
        $this->loadActivePlugins();
        
        $this->version_detector = new PluginVersionDetector();
    }
    
    /**
     * Load list of active plugins
     */
    private function loadActivePlugins() {
        try {
            PathHelper::requireOnce('data/plugins_class.php');
            $plugins = new MultiPlugin(['plg_status' => 'active']);
            $plugins->load();
            
            foreach ($plugins as $plugin) {
                $this->active_plugins[$plugin->get('plg_name')] = $plugin;
            }
        } catch (Exception $e) {
            $this->active_plugins = [];
        }
    }
    
    /**
     * Validate all dependencies for a plugin
     * 
     * @param string $plugin_name Plugin to validate
     * @return array Validation results
     */
    public function validate($plugin_name) {
        $results = [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
            'dependencies' => []
        ];
        
        // Get plugin metadata
        $metadata = $this->version_detector->getPluginMetadata($plugin_name);
        
        if (!$metadata) {
            $results['valid'] = false;
            $results['errors'][] = "No plugin.json found for plugin '{$plugin_name}'";
            return $results;
        }
        
        // Check PHP version requirement
        if (isset($metadata['requires']['php'])) {
            $php_result = $this->checkPhpVersion($metadata['requires']['php']);
            if (!$php_result['valid']) {
                $results['valid'] = false;
                $results['errors'][] = $php_result['error'];
            }
        }
        
        // Check PHP extensions
        if (isset($metadata['requires']['extensions']) && is_array($metadata['requires']['extensions'])) {
            foreach ($metadata['requires']['extensions'] as $extension) {
                if (!extension_loaded($extension)) {
                    $results['valid'] = false;
                    $results['errors'][] = "Required PHP extension '{$extension}' is not loaded";
                }
            }
        }
        
        // Check Joinery version requirement
        if (isset($metadata['requires']['joinery'])) {
            $joinery_result = $this->checkJoineryVersion($metadata['requires']['joinery']);
            if (!$joinery_result['valid']) {
                $results['valid'] = false;
                $results['errors'][] = $joinery_result['error'];
            }
        }
        
        // Check plugin dependencies
        if (isset($metadata['depends']) && is_array($metadata['depends'])) {
            foreach ($metadata['depends'] as $dependency => $version_constraint) {
                $dep_result = $this->checkPluginDependency($dependency, $version_constraint);
                $results['dependencies'][$dependency] = $dep_result;
                
                if (!$dep_result['satisfied']) {
                    $results['valid'] = false;
                    $results['errors'][] = $dep_result['error'];
                }
            }
        }
        
        // Check for conflicts
        if (isset($metadata['conflicts']) && is_array($metadata['conflicts'])) {
            foreach ($metadata['conflicts'] as $conflict) {
                if (isset($this->active_plugins[$conflict])) {
                    $results['valid'] = false;
                    $results['errors'][] = "Plugin conflicts with active plugin '{$conflict}'";
                }
            }
        }
        
        // Store dependencies in database
        if ($results['valid']) {
            $this->storeDependencies($plugin_name, $metadata);
        }
        
        return $results;
    }
    
    /**
     * Check if plugin can be safely deactivated
     * 
     * @param string $plugin_name Plugin to check
     * @return array Results with dependent plugins
     */
    public function checkDeactivation($plugin_name) {
        $results = [
            'can_deactivate' => true,
            'dependent_plugins' => [],
            'warnings' => []
        ];
        
        try {
            // Find all plugins that depend on this one
            $sql = "SELECT DISTINCT pld_plugin_name 
                    FROM pld_plugin_dependencies 
                    WHERE pld_depends_on = ? 
                    AND pld_plugin_name IN (
                        SELECT plg_name FROM plg_plugins WHERE plg_status = 'active'
                    )";
            
            $dblink = $this->dbconnector->get_db_link();
            $q = $dblink->prepare($sql);
            $q->execute([$plugin_name]);
            $dependents = $q->fetchAll(PDO::FETCH_ASSOC);
            
            if ($dependents && count($dependents) > 0) {
                $results['can_deactivate'] = false;
                foreach ($dependents as $dep) {
                    $results['dependent_plugins'][] = $dep['pld_plugin_name'];
                }
            }
            
        } catch (Exception $e) {
            $results['warnings'][] = "Could not check dependencies: " . $e->getMessage();
        }
        
        return $results;
    }
    
    /**
     * Get dependency tree for a plugin
     * 
     * @param string $plugin_name Plugin name
     * @param int $max_depth Maximum recursion depth
     * @return array Dependency tree
     */
    public function getDependencyTree($plugin_name, $max_depth = 5) {
        return $this->buildDependencyTree($plugin_name, 0, $max_depth, []);
    }
    
    /**
     * Recursively build dependency tree
     * 
     * @param string $plugin_name Current plugin
     * @param int $depth Current depth
     * @param int $max_depth Maximum depth
     * @param array $visited Already visited plugins (prevent cycles)
     * @return array Tree structure
     */
    private function buildDependencyTree($plugin_name, $depth, $max_depth, &$visited) {
        if ($depth >= $max_depth || in_array($plugin_name, $visited)) {
            return [
                'name' => $plugin_name,
                'circular' => in_array($plugin_name, $visited),
                'max_depth_reached' => $depth >= $max_depth,
                'dependencies' => []
            ];
        }
        
        $visited[] = $plugin_name;
        
        $tree = [
            'name' => $plugin_name,
            'active' => isset($this->active_plugins[$plugin_name]),
            'dependencies' => []
        ];
        
        // Get plugin's dependencies
        $metadata = $this->version_detector->getPluginMetadata($plugin_name);
        
        if ($metadata && isset($metadata['depends']) && is_array($metadata['depends'])) {
            foreach ($metadata['depends'] as $dep_name => $version_constraint) {
                $tree['dependencies'][] = $this->buildDependencyTree($dep_name, $depth + 1, $max_depth, $visited);
            }
        }
        
        return $tree;
    }
    
    /**
     * Check PHP version requirement
     * 
     * @param string $constraint Version constraint (e.g., ">=8.0")
     * @return array Validation result
     */
    private function checkPhpVersion($constraint) {
        $current_version = PHP_VERSION;
        
        // Parse constraint
        if (preg_match('/^([><=]+)(.+)$/', $constraint, $matches)) {
            $operator = $matches[1];
            $required_version = $matches[2];
            
            if (version_compare($current_version, $required_version, $operator)) {
                return ['valid' => true];
            } else {
                return [
                    'valid' => false,
                    'error' => "PHP version {$current_version} does not satisfy requirement {$constraint}"
                ];
            }
        }
        
        return [
            'valid' => false,
            'error' => "Invalid PHP version constraint: {$constraint}"
        ];
    }
    
    /**
     * Check Joinery version requirement
     * 
     * @param string $constraint Version constraint
     * @return array Validation result
     */
    private function checkJoineryVersion($constraint) {
        // Get current Joinery version from settings
        $settings = Globalvars::get_instance();
        $current_version = $settings->get_setting('system_version');
        
        if (!$current_version) {
            $current_version = '1.0.0'; // Default if not set
        }
        
        // Parse constraint
        if (preg_match('/^([><=]+)(.+)$/', $constraint, $matches)) {
            $operator = $matches[1];
            $required_version = $matches[2];
            
            if (version_compare($current_version, $required_version, $operator)) {
                return ['valid' => true];
            } else {
                return [
                    'valid' => false,
                    'error' => "Joinery version {$current_version} does not satisfy requirement {$constraint}"
                ];
            }
        }
        
        // Handle wildcard
        if ($constraint === '*') {
            return ['valid' => true];
        }
        
        return [
            'valid' => false,
            'error' => "Invalid Joinery version constraint: {$constraint}"
        ];
    }
    
    /**
     * Check plugin dependency
     * 
     * @param string $plugin_name Required plugin
     * @param string $version_constraint Version constraint
     * @return array Dependency check result
     */
    private function checkPluginDependency($plugin_name, $version_constraint) {
        // Check if plugin is active
        if (!isset($this->active_plugins[$plugin_name])) {
            return [
                'satisfied' => false,
                'error' => "Required plugin '{$plugin_name}' is not active"
            ];
        }
        
        // Get plugin version
        $version_info = $this->version_detector->checkForUpdate($plugin_name);
        $installed_version = $version_info['installed_version'] ?? '0.0.0';
        
        // Check version constraint
        if ($version_constraint === '*') {
            return ['satisfied' => true, 'version' => $installed_version];
        }
        
        // Parse constraint
        if (preg_match('/^([><=]+)(.+)$/', $version_constraint, $matches)) {
            $operator = $matches[1];
            $required_version = $matches[2];
            
            if (version_compare($installed_version, $required_version, $operator)) {
                return ['satisfied' => true, 'version' => $installed_version];
            } else {
                return [
                    'satisfied' => false,
                    'error' => "Plugin '{$plugin_name}' version {$installed_version} does not satisfy requirement {$version_constraint}"
                ];
            }
        }
        
        return [
            'satisfied' => false,
            'error' => "Invalid version constraint for plugin '{$plugin_name}': {$version_constraint}"
        ];
    }
    
    /**
     * Store plugin dependencies in database
     * 
     * @param string $plugin_name Plugin name
     * @param array $metadata Plugin metadata
     */
    private function storeDependencies($plugin_name, $metadata) {
        try {
            $dblink = $this->dbconnector->get_db_link();
            
            // Clear existing dependencies
            $sql = "DELETE FROM pld_plugin_dependencies WHERE pld_plugin_name = ?";
            $q = $dblink->prepare($sql);
            $q->execute([$plugin_name]);
            
            // Store new dependencies
            if (isset($metadata['depends']) && is_array($metadata['depends'])) {
                $sql = "INSERT INTO pld_plugin_dependencies 
                        (pld_plugin_name, pld_depends_on, pld_version_constraint, pld_dependency_type) 
                        VALUES (?, ?, ?, 'requires')";
                
                $q = $dblink->prepare($sql);
                foreach ($metadata['depends'] as $dep_name => $version_constraint) {
                    $q->execute([$plugin_name, $dep_name, $version_constraint]);
                }
            }
            
            // Store conflicts as negative dependencies
            if (isset($metadata['conflicts']) && is_array($metadata['conflicts'])) {
                $sql = "INSERT INTO pld_plugin_dependencies 
                        (pld_plugin_name, pld_depends_on, pld_version_constraint, pld_dependency_type) 
                        VALUES (?, ?, '*', 'conflicts')";
                
                $q = $dblink->prepare($sql);
                foreach ($metadata['conflicts'] as $conflict) {
                    $q->execute([$plugin_name, $conflict]);
                }
            }
            
        } catch (Exception $e) {
            error_log("Error storing dependencies for {$plugin_name}: " . $e->getMessage());
        }
    }
    
    /**
     * Get all plugins that provide a specific feature
     * 
     * @param string $feature Feature name
     * @return array List of plugins providing the feature
     */
    public function getProvidersOf($feature) {
        $providers = [];
        
        foreach ($this->active_plugins as $plugin_name => $plugin) {
            $metadata = $this->version_detector->getPluginMetadata($plugin_name);
            
            if ($metadata && isset($metadata['provides']) && is_array($metadata['provides'])) {
                if (in_array($feature, $metadata['provides'])) {
                    $providers[] = $plugin_name;
                }
            }
        }
        
        return $providers;
    }
}

/**
 * PluginSystemRepair - Detects and repairs plugin system issues
 * 
 * This class provides automated repair functionality for common plugin system problems:
 * - Missing database tables
 * - Missing or corrupt plugin settings
 * - Orphaned plugin records
 * - Plugin system integrity issues
 */
class PluginSystemRepair {
    
    private $dbconnector;
    private $results;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->dbconnector = DbConnector::get_instance();
        $this->results = [
            'success' => false,
            'issues_found' => [],
            'repairs_made' => [],
            'errors' => [],
            'warnings' => []
        ];
    }
    
    /**
     * Main repair function - detects and fixes plugin system issues
     * 
     * @param bool $dry_run If true, only detect issues without fixing
     * @return array Detailed results of the repair operation
     */
    public function repair($dry_run = false) {
        try {
            $dblink = $this->dbconnector->get_db_link();
            
            // Check and repair missing tables
            $this->checkPluginTables($dblink, $dry_run);
            
            // Check and repair plugin columns
            $this->checkPluginColumns($dblink, $dry_run);
            
            // Check and repair plugin statuses
            $this->checkPluginStatuses($dblink, $dry_run);
            
            // Check and repair orphaned records
            $this->checkOrphanedRecords($dblink, $dry_run);
            
            // Check and repair indexes
            $this->checkPluginIndexes($dblink, $dry_run);
            
            // Check plugin directories vs database records
            $this->checkPluginDirectories($dblink, $dry_run);
            
            $this->results['success'] = true;
            $this->results['summary'] = $this->generateSummary();
            
        } catch (Exception $e) {
            $this->results['errors'][] = "Repair failed: " . $e->getMessage();
            $this->results['success'] = false;
        }
        
        return $this->results;
    }
    
    /**
     * Check and create missing plugin system tables using DatabaseUpdater
     */
    private function checkPluginTables($dblink, $dry_run) {
        try {
            // Load plugin system model classes
            PathHelper::requireOnce('data/plugins_class.php');
            PathHelper::requireOnce('data/plugin_migrations_class.php');
            PathHelper::requireOnce('data/plugin_versions_class.php');
            PathHelper::requireOnce('data/plugin_dependencies_class.php');
            
            // Get plugin system classes only
            $plugin_classes = [
                'PluginMigration',
                'PluginVersion', 
                'PluginDependency'
            ];
            
            if (!$dry_run) {
                // Use DatabaseUpdater to create missing plugin system tables
                PathHelper::requireOnce('includes/DatabaseUpdater.php');
                $database_updater = new DatabaseUpdater(false, false, false); // No verbose, upgrade, or cleanup
                
                // Create tables for the plugin system classes that are missing
                $any_tables_created = false;
                foreach ($plugin_classes as $class) {
                    $table_name = $class::$tablename;
                    
                    // Check if table exists
                    $check_sql = "SELECT to_regclass('public.{$table_name}') IS NOT NULL as exists";
                    $q = $dblink->prepare($check_sql);
                    $q->execute();
                    $result = $q->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$result['exists']) {
                        $this->results['issues_found'][] = "Missing table: {$table_name}";
                        $any_tables_created = true;
                    }
                }
                
                // If any tables are missing, run core table creation which includes plugin system tables
                if ($any_tables_created) {
                    $table_result = $database_updater->runCoreTablesOnly();
                    
                    if (!empty($table_result['tables_created'])) {
                        // Only report plugin system tables that were created
                        foreach ($table_result['tables_created'] as $table) {
                            if (in_array($table, ['plm_plugin_migrations', 'plv_plugin_versions', 'pld_plugin_dependencies'])) {
                                $this->results['repairs_made'][] = "Created table: {$table}";
                            }
                        }
                    }
                    if (!empty($table_result['errors'])) {
                        $this->results['errors'] = array_merge($this->results['errors'], $table_result['errors']);
                    }
                }
            } else {
                // Dry run - just check for missing tables
                foreach ($plugin_classes as $class) {
                    $table_name = $class::$tablename;
                    
                    $check_sql = "SELECT to_regclass('public.{$table_name}') IS NOT NULL as exists";
                    $q = $dblink->prepare($check_sql);
                    $q->execute();
                    $result = $q->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$result['exists']) {
                        $this->results['issues_found'][] = "Missing table: {$table_name}";
                    }
                }
            }
            
        } catch (Exception $e) {
            $this->results['errors'][] = "Error checking plugin system tables: " . $e->getMessage();
        }
    }
    
    /**
     * Check and add missing columns to plg_plugins table using DatabaseUpdater
     */
    private function checkPluginColumns($dblink, $dry_run) {
        try {
            // Load Plugin class to get field specifications
            PathHelper::requireOnce('data/plugins_class.php');
            
            if (!$dry_run) {
                // Check for missing columns first
                $missing_columns = [];
                $field_specifications = Plugin::$field_specifications;
                
                // Get columns for plg_plugins table once (much more efficient than N queries)
                $tables_and_columns = LibraryFunctions::get_tables_and_columns('plg_plugins');
                $plugin_columns = isset($tables_and_columns['plg_plugins']) ? $tables_and_columns['plg_plugins'] : array();

                foreach ($field_specifications as $field_name => $field_specs) {
                    if (!isset($plugin_columns[$field_name])) {
                        $missing_columns[] = $field_name;
                        $this->results['issues_found'][] = "Missing column: plg_plugins.{$field_name}";
                    }
                }
                
                // If there are missing columns, use DatabaseUpdater to fix them
                if (!empty($missing_columns)) {
                    PathHelper::requireOnce('includes/DatabaseUpdater.php');
                    $database_updater = new DatabaseUpdater(false, false, false);
                    $table_result = $database_updater->runCoreTablesOnly();
                    
                    if (!empty($table_result['columns_added'])) {
                        foreach ($table_result['columns_added'] as $column) {
                            if (strpos($column, 'plg_plugins.') === 0) {
                                $this->results['repairs_made'][] = "Added column: {$column}";
                            }
                        }
                    }
                    if (!empty($table_result['errors'])) {
                        $this->results['errors'] = array_merge($this->results['errors'], $table_result['errors']);
                    }
                }
            } else {
                // Dry run - manually check columns
                $field_specifications = Plugin::$field_specifications;
                
                // Get columns for plg_plugins table once (much more efficient than N queries)
                $tables_and_columns = LibraryFunctions::get_tables_and_columns('plg_plugins');
                $plugin_columns = isset($tables_and_columns['plg_plugins']) ? $tables_and_columns['plg_plugins'] : array();

                foreach ($field_specifications as $field_name => $field_specs) {
                    if (!isset($plugin_columns[$field_name])) {
                        $this->results['issues_found'][] = "Missing column: plg_plugins.{$field_name}";
                    }
                }
            }
            
        } catch (Exception $e) {
            $this->results['errors'][] = "Error checking plugin columns: " . $e->getMessage();
        }
    }
    
    /**
     * Check and repair plugin statuses
     */
    private function checkPluginStatuses($dblink, $dry_run) {
        try {
            // Find plugins with null or invalid status
            $check_sql = "SELECT plg_plugin_id, plg_name, plg_active, plg_status 
                         FROM plg_plugins 
                         WHERE plg_status IS NULL OR plg_status NOT IN ('active', 'inactive', 'installed', 'error', 'uninstalled')";
            $q = $dblink->prepare($check_sql);
            $q->execute();
            $results = $q->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($results as $plugin) {
                $this->results['issues_found'][] = "Plugin '{$plugin['plg_name']}' has invalid status: " . ($plugin['plg_status'] ?: 'NULL');
                
                if (!$dry_run) {
                    // Set status based on plg_active field
                    $new_status = ($plugin['plg_active'] == 1) ? 'active' : 'inactive';
                    $update_sql = "UPDATE plg_plugins SET plg_status = ? WHERE plg_plugin_id = ?";
                    $update_q = $dblink->prepare($update_sql);
                    $update_q->execute([$new_status, $plugin['plg_plugin_id']]);
                    
                    $this->results['repairs_made'][] = "Fixed status for plugin '{$plugin['plg_name']}': {$new_status}";
                }
            }
            
        } catch (Exception $e) {
            $this->results['errors'][] = "Error checking plugin statuses: " . $e->getMessage();
        }
    }
    
    /**
     * Check for and clean up orphaned records
     */
    private function checkOrphanedRecords($dblink, $dry_run) {
        try {
            // Check for orphaned version records
            $check_sql = "SELECT plv_plugin_name FROM plv_plugin_versions v 
                         WHERE NOT EXISTS (SELECT 1 FROM plg_plugins p WHERE p.plg_name = v.plv_plugin_name)";
            $q = $dblink->prepare($check_sql);
            $q->execute();
            $orphaned_versions = $q->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($orphaned_versions as $version) {
                $this->results['issues_found'][] = "Orphaned version record for plugin: {$version['plv_plugin_name']}";
                
                if (!$dry_run) {
                    $delete_sql = "DELETE FROM plv_plugin_versions WHERE plv_plugin_name = ?";
                    $delete_q = $dblink->prepare($delete_sql);
                    $delete_q->execute([$version['plv_plugin_name']]);
                    
                    $this->results['repairs_made'][] = "Removed orphaned version record: {$version['plv_plugin_name']}";
                }
            }
            
            // Check for orphaned migration records
            $check_sql = "SELECT DISTINCT plm_plugin_name FROM plm_plugin_migrations m 
                         WHERE NOT EXISTS (SELECT 1 FROM plg_plugins p WHERE p.plg_name = m.plm_plugin_name)";
            $q = $dblink->prepare($check_sql);
            $q->execute();
            $orphaned_migrations = $q->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($orphaned_migrations as $migration) {
                $this->results['issues_found'][] = "Orphaned migration records for plugin: {$migration['plm_plugin_name']}";
                
                if (!$dry_run) {
                    $delete_sql = "DELETE FROM plm_plugin_migrations WHERE plm_plugin_name = ?";
                    $delete_q = $dblink->prepare($delete_sql);
                    $delete_q->execute([$migration['plm_plugin_name']]);
                    
                    $this->results['repairs_made'][] = "Removed orphaned migration records: {$migration['plm_plugin_name']}";
                }
            }
            
        } catch (Exception $e) {
            $this->results['warnings'][] = "Could not check orphaned records (tables may not exist yet): " . $e->getMessage();
        }
    }
    
    /**
     * Check and create missing indexes
     */
    private function checkPluginIndexes($dblink, $dry_run) {
        $required_indexes = [
            'idx_plm_plugin_status' => "CREATE INDEX IF NOT EXISTS idx_plm_plugin_status ON plm_plugin_migrations(plm_plugin_name, plm_status)",
            'idx_plv_update_check' => "CREATE INDEX IF NOT EXISTS idx_plv_update_check ON plv_plugin_versions(plv_update_available, plv_last_check_time)",
            'idx_pld_plugin' => "CREATE INDEX IF NOT EXISTS idx_pld_plugin ON pld_plugin_dependencies(pld_plugin_name)",
            'idx_plg_status' => "CREATE INDEX IF NOT EXISTS idx_plg_status ON plg_plugins(plg_status)"
        ];
        
        foreach ($required_indexes as $index_name => $create_sql) {
            try {
                // Check if index exists
                $check_sql = "SELECT indexname FROM pg_indexes WHERE indexname = ?";
                $q = $dblink->prepare($check_sql);
                $q->execute([$index_name]);
                $result = $q->fetch(PDO::FETCH_ASSOC);
                
                if (!$result) {
                    $this->results['issues_found'][] = "Missing index: {$index_name}";
                    
                    if (!$dry_run) {
                        $dblink->exec($create_sql);
                        $this->results['repairs_made'][] = "Created index: {$index_name}";
                    }
                }
                
            } catch (Exception $e) {
                $this->results['warnings'][] = "Could not check/create index {$index_name}: " . $e->getMessage();
            }
        }
    }
    
    /**
     * Check plugin directories vs database records
     */
    private function checkPluginDirectories($dblink, $dry_run) {
        try {
            $plugins_dir = $_SERVER['DOCUMENT_ROOT'] . '/plugins';
            
            if (!is_dir($plugins_dir)) {
                $this->results['warnings'][] = "Plugins directory does not exist: {$plugins_dir}";
                return;
            }
            
            // Get all plugin directories
            $plugin_dirs = array_diff(scandir($plugins_dir), array('.', '..'));
            $plugin_dirs = array_filter($plugin_dirs, function($dir) use ($plugins_dir) {
                return is_dir($plugins_dir . '/' . $dir) && Plugin::is_valid_plugin_name($dir);
            });
            
            // Get all plugin records
            $sql = "SELECT plg_name FROM plg_plugins WHERE plg_status != 'uninstalled'";
            $q = $dblink->prepare($sql);
            $q->execute();
            $db_plugins = array_column($q->fetchAll(PDO::FETCH_ASSOC), 'plg_name');
            
            // Find plugins with directories but no database records
            $missing_db_records = array_diff($plugin_dirs, $db_plugins);
            foreach ($missing_db_records as $plugin_name) {
                $this->results['issues_found'][] = "Plugin directory exists but no database record: {$plugin_name}";
                // Note: We don't auto-create plugin records as this should be done through install process
            }
            
            // Find plugins with database records but no directories
            $missing_directories = array_diff($db_plugins, $plugin_dirs);
            foreach ($missing_directories as $plugin_name) {
                $this->results['issues_found'][] = "Plugin has database record but directory missing: {$plugin_name}";
                // Note: We don't auto-delete records as they may be temporarily missing
            }
            
        } catch (Exception $e) {
            $this->results['warnings'][] = "Could not check plugin directories: " . $e->getMessage();
        }
    }
    
    /**
     * Generate a summary of the repair operation
     */
    private function generateSummary() {
        $issues_count = count($this->results['issues_found']);
        $repairs_count = count($this->results['repairs_made']);
        $errors_count = count($this->results['errors']);
        $warnings_count = count($this->results['warnings']);
        
        $summary = "Plugin system repair completed. ";
        $summary .= "Found {$issues_count} issue(s), ";
        $summary .= "made {$repairs_count} repair(s), ";
        $summary .= "encountered {$errors_count} error(s), ";
        $summary .= "and {$warnings_count} warning(s).";
        
        return $summary;
    }
    
    /**
     * Get a quick health check of the plugin system
     * 
     * @return array Basic health status
     */
    public function healthCheck() {
        $health = [
            'overall_status' => 'healthy',
            'issues' => [],
            'recommendations' => []
        ];
        
        try {
            $dblink = $this->dbconnector->get_db_link();
            
            // Check if basic tables exist
            $tables = ['plg_plugins', 'plm_plugin_migrations', 'plv_plugin_versions', 'pld_plugin_dependencies'];
            foreach ($tables as $table) {
                $check_sql = "SELECT to_regclass('public.{$table}') IS NOT NULL as exists";
                $q = $dblink->prepare($check_sql);
                $q->execute();
                $result = $q->fetch(PDO::FETCH_ASSOC);
                
                if (!$result['exists']) {
                    $health['overall_status'] = 'needs_repair';
                    $health['issues'][] = "Missing table: {$table}";
                }
            }
            
            if ($health['overall_status'] === 'needs_repair') {
                $missing_plugin_tables = array_intersect(['plm_plugin_migrations', 'plv_plugin_versions', 'pld_plugin_dependencies'], 
                    array_map(function($issue) { 
                        return str_replace('Missing table: ', '', $issue); 
                    }, $health['issues']));
                
                if (!empty($missing_plugin_tables)) {
                    $health['recommendations'][] = "Run update_database.php to create the plugin system tables.";
                    $health['recommendations'][] = "Navigate to your site's /utils/update_database.php or run it via command line.";
                } else {
                    $health['recommendations'][] = "Run the plugin system repair to fix missing tables and columns.";
                }
            }
            
        } catch (Exception $e) {
            $health['overall_status'] = 'error';
            $health['issues'][] = "Database connectivity issue: " . $e->getMessage();
        }
        
        return $health;
    }
}

?>