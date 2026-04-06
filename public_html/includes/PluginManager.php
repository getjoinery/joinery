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
     * Load metadata from plugin.json into model.
     * Calls parent for manifest validation, then sets plugin-specific fields.
     *
     * @param Plugin $model Plugin model object
     * @param string $name Plugin name
     */
    protected function loadMetadataIntoModel($model, $name) {
        $metadata = parent::loadMetadataIntoModel($model, $name);
        if ($metadata === false) return; // Error already set by parent

        $model->set('plg_metadata', json_encode($metadata));
        $model->set('plg_is_stock', $metadata['is_stock'] ?? true);
        $model->set('plg_installed_time', date('Y-m-d H:i:s'));
    }

    /**
     * Returns the Multi class filter options for active plugins (used by sync() ghost detection).
     */
    protected function getActiveFilterOptions() {
        return array('plg_active' => 1);
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
    protected function runPendingMigrations($plugin_name) {
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
    protected function validatePlugin($plugin_name) {
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

                // Check version constraint if specified
                if ($dep_version && $dep_version !== '*') {
                    $metadata = json_decode($dep_plugin->get('plg_metadata'), true);
                    $installed_version = $metadata['version'] ?? '0.0.0';
                    // Parse constraint like ">=1.0.0"
                    if (preg_match('/^([<>=!]+)(.+)$/', $dep_version, $m)) {
                        if (!version_compare($installed_version, trim($m[2]), $m[1])) {
                            $results['valid'] = false;
                            $results['errors'][] = "Plugin '$dep_name' version $installed_version does not satisfy constraint $dep_version";
                        }
                    }
                }
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
    
    // ========== Lifecycle: onActivate / onDeactivate / install / uninstall ==========

    /**
     * Called inside activate() transaction. Validates dependencies, runs plugin table updates,
     * runs activate.php hook, registers deletion rules, sets plg_active and timestamps,
     * and resumes suspended scheduled tasks.
     *
     * @param string $name Plugin name
     * @param Plugin $model Plugin model
     * @param PDO $dblink Database connection
     * @throws Exception to roll back the transaction
     */
    protected function onActivate($name, $model, $dblink) {
        // Reject plugin names that conflict with system URL segments.
        // Plugin names appear directly in URLs (/{name}/*, /profile/{name}/*),
        // so they must not collide with existing system paths.
        $reserved_names = ['profile', 'admin', 'login', 'ajax', 'api', 'assets', 'theme',
                           'plugins', 'views', 'uploads', 'utils', 'tests', 'docs', 'specs',
                           'migrations', 'data', 'includes', 'logic', 'adm'];
        if (in_array($name, $reserved_names)) {
            throw new Exception("Plugin name '$name' is reserved and conflicts with system URLs. Choose a distinctive plugin name.");
        }
        // Also reject names that clash with existing base view filenames
        // (e.g. if views/profile/billing.php exists, plugin 'billing' is blocked).
        $base_views      = PathHelper::getAbsolutePath('views');
        $profile_views   = PathHelper::getAbsolutePath('views/profile');
        foreach ([$base_views, $profile_views] as $dir) {
            if (file_exists($dir . '/' . $name . '.php')) {
                throw new Exception("Plugin name '$name' conflicts with a system view. Choose a distinctive plugin name.");
            }
        }

        // Re-validate dependencies
        $validation = $this->validatePlugin($name);
        if (!$validation['valid']) {
            throw new Exception("Cannot activate plugin '$name': " . implode('; ', $validation['errors']));
        }

        // Run plugin table updates — picks up schema changes since install
        require_once(PathHelper::getIncludePath('includes/DatabaseUpdater.php'));
        $database_updater = new DatabaseUpdater();
        $table_result = $database_updater->runPluginTablesOnly($name);
        if (!$table_result['success']) {
            throw new Exception("Plugin '$name' table update failed: " . implode('; ', $table_result['errors']));
        }

        // Run activate.php hook if it exists
        $activate_file = PathHelper::getAbsolutePath("plugins/{$name}/activate.php");
        if (file_exists($activate_file)) {
            require_once($activate_file);
            $activate_fn = $name . '_activate';
            if (function_exists($activate_fn)) {
                $activate_fn();
            }
        }

        // Register deletion rules (non-fatal)
        require_once(PathHelper::getIncludePath('data/deletion_rule_class.php'));
        try {
            DeletionRule::registerModelsFromDiscovery([
                'include_plugins' => true,
                'plugin_filter' => $name
            ]);
        } catch (Exception $e) {
            error_log("Failed to register deletion rules for plugin '$name': " . $e->getMessage());
        }

        // Set active flag and timestamps
        $now = gmdate('Y-m-d H:i:s');
        $model->set('plg_active', 1);
        $model->set('plg_activated_time', $now);
        $model->set('plg_last_activated_time', $now);
        $model->set('plg_install_error', null);

        // Resume scheduled tasks that belong to this plugin
        require_once(PathHelper::getIncludePath('data/scheduled_tasks_class.php'));
        $suspended = new MultiScheduledTask(array('plugin_name' => $name, 'active' => false, 'deleted' => false));
        $suspended->load();
        foreach ($suspended as $task) {
            $task->set('sct_is_active', true);
            $task->save();
        }
    }

    /**
     * Called inside deactivate() transaction. Checks theme provider and dependents,
     * runs deactivate.php hook, removes deletion rules, sets plg_active=0 and timestamps,
     * and suspends scheduled tasks.
     *
     * @param string $name Plugin name
     * @param Plugin $model Plugin model
     * @param PDO $dblink Database connection
     * @throws Exception to roll back the transaction
     */
    protected function onDeactivate($name, $model, $dblink) {
        // Block if this plugin is the active theme provider
        require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));
        try {
            $plugin_helper = PluginHelper::getInstance($name);
            if ($plugin_helper->isActiveThemeProvider()) {
                throw new Exception("Cannot deactivate plugin '$name': it is the active theme provider. Switch to a different theme first.");
            }
        } catch (Exception $e) {
            // If the exception is about theme provider, re-throw it
            if (strpos($e->getMessage(), 'theme provider') !== false) {
                throw $e;
            }
            // Otherwise plugin helper unavailable — proceed
        }

        // Block if the active theme requires this plugin
        try {
            $settings_obj = Globalvars::get_instance();
            $active_theme_name = $settings_obj->get_setting('theme_template', true, true);
            if ($active_theme_name && $active_theme_name !== 'plugin') {
                $theme_helper = ThemeHelper::getInstance($active_theme_name);
                $required_plugins = $theme_helper->get('requires_plugins', []);
                if (is_array($required_plugins) && in_array($name, $required_plugins)) {
                    throw new Exception("Cannot deactivate plugin '{$name}': the active theme '{$active_theme_name}' requires it. Switch to a different theme first.");
                }
            }
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'requires it') !== false) {
                throw $e;
            }
            // Theme helper unavailable or no theme configured — proceed
        }

        // Block if other active plugins depend on this one
        $dependents = $this->getDependents($name);
        if (!empty($dependents)) {
            throw new Exception("Cannot deactivate plugin '$name': other plugins depend on it: " . implode(', ', $dependents));
        }

        // Run deactivate.php hook if it exists
        $deactivate_file = PathHelper::getAbsolutePath("plugins/{$name}/deactivate.php");
        if (file_exists($deactivate_file)) {
            require_once($deactivate_file);
            $deactivate_fn = $name . '_deactivate';
            if (function_exists($deactivate_fn)) {
                $deactivate_fn();
            }
        }

        // Remove deletion rules
        try {
            $plugin_helper_instance = PluginHelper::getInstance($name);
            $plugin_helper_instance->removePluginDeletionRules();
        } catch (Exception $e) {
            error_log("Failed to remove deletion rules for plugin '$name': " . $e->getMessage());
        }

        // Set inactive flag and timestamps
        $model->set('plg_active', 0);
        $model->set('plg_activated_time', null);
        $model->set('plg_last_deactivated_time', gmdate('Y-m-d H:i:s'));

        // Suspend scheduled tasks that belong to this plugin
        require_once(PathHelper::getIncludePath('data/scheduled_tasks_class.php'));
        $active_tasks = new MultiScheduledTask(array('plugin_name' => $name, 'active' => true, 'deleted' => false));
        $active_tasks->load();
        foreach ($active_tasks as $task) {
            $task->set('sct_is_active', false);
            $task->save();
        }
    }

    /**
     * Install a plugin — creates tables, runs migrations, sets status=inactive.
     * Transaction-wrapped. Validates first; rolls back on failure.
     *
     * @param string $name Plugin name
     * @throws Exception on failure
     */
    public function install($name) {
        if (!$this->validateName($name)) {
            throw new Exception("Invalid plugin name: $name");
        }

        $plugin_path = $this->getExtensionPath($name);
        if (!is_dir($plugin_path)) {
            throw new Exception("Plugin directory not found: $name");
        }

        // Get or create plugin record
        $plugin = Plugin::get_by_plugin_name($name);
        if (!$plugin) {
            $plugin = new Plugin(null);
            $plugin->set('plg_name', $name);
        }

        $dbconnector = DbConnector::get_instance();
        $dblink = $dbconnector->get_db_link();

        $this_transaction = false;
        if (!$dblink->inTransaction()) {
            $dblink->beginTransaction();
            $this_transaction = true;
        }

        try {
            // Validate dependencies
            $validation = $this->validatePlugin($name);
            if (!$validation['valid']) {
                throw new Exception("Plugin validation failed: " . implode('; ', $validation['errors']));
            }

            // Create plugin tables
            require_once(PathHelper::getIncludePath('includes/DatabaseUpdater.php'));
            $database_updater = new DatabaseUpdater();
            $table_result = $database_updater->runPluginTablesOnly($name);
            if (!$table_result['success']) {
                throw new Exception("Plugin table creation failed: " . implode('; ', $table_result['errors']));
            }

            // Run migrations
            $migration_results = $this->runPendingMigrations($name);
            $migration_errors = array();
            foreach ($migration_results as $result) {
                if (!empty($result['error'])) {
                    $migration_errors[] = $result['error'];
                }
            }
            if (!empty($migration_errors)) {
                throw new Exception("Plugin migration failed: " . implode('; ', $migration_errors));
            }

            // Load metadata
            $this->loadMetadataIntoModel($plugin, $name);

            // Update status
            $plugin->set('plg_installed_time', gmdate('Y-m-d H:i:s'));
            $plugin->set('plg_status', 'inactive');
            $plugin->set('plg_install_error', null);
            $plugin->save();

            if ($this_transaction) {
                $dblink->commit();
            }
        } catch (Exception $e) {
            if ($this_transaction && $dblink->inTransaction()) {
                $dblink->rollBack();
            }
            // Record error on plugin
            try {
                $plugin->set('plg_status', 'error');
                $plugin->set('plg_install_error', $e->getMessage());
                $plugin->save();
            } catch (Exception $save_error) {
                // Ignore save errors during error reporting
            }
            throw $e;
        }
    }

    /**
     * Uninstall a plugin — runs uninstall hook, removes deletion rules, deletes task records,
     * dependency records, version records. Tables and files are preserved.
     *
     * Plugin must be inactive before calling.
     *
     * @param string $name Plugin name
     * @throws Exception on failure
     */
    public function uninstall($name) {
        $plugin = Plugin::get_by_plugin_name($name);
        if (!$plugin) {
            throw new Exception("Plugin '$name' not found in database.");
        }

        if ($plugin->is_active()) {
            throw new Exception("Cannot uninstall active plugin '$name'. Deactivate it first.");
        }

        // Check dependents
        $dependents = $this->getDependents($name);
        if (!empty($dependents)) {
            throw new Exception("Cannot uninstall plugin '$name': other plugins depend on it: " . implode(', ', $dependents));
        }

        // Run uninstall hook
        $uninstall_file = PathHelper::getAbsolutePath("plugins/{$name}/uninstall.php");
        if (file_exists($uninstall_file)) {
            require_once($uninstall_file);
            $uninstall_fn = $name . '_uninstall';
            if (function_exists($uninstall_fn)) {
                $result = call_user_func($uninstall_fn);
                if ($result === false) {
                    throw new Exception("Plugin '$name' uninstall script returned failure.");
                }
            }
        }

        // Remove deletion rules
        require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));
        try {
            $plugin_helper = PluginHelper::getInstance($name);
            $plugin_helper->removePluginDeletionRules();
        } catch (Exception $e) {
            error_log("Failed to remove deletion rules for plugin '$name' during uninstall: " . $e->getMessage());
        }

        // Delete scheduled task records by plugin_name
        require_once(PathHelper::getIncludePath('data/scheduled_tasks_class.php'));
        $tasks = new MultiScheduledTask(array('plugin_name' => $name, 'deleted' => false));
        $tasks->load();
        foreach ($tasks as $task) {
            $task->permanent_delete();
        }

        // Delete version tracking records
        require_once(PathHelper::getIncludePath('data/plugin_versions_class.php'));
        $versions = new MultiPluginVersion(array('plv_plugin_name' => $name));
        $versions->load();
        foreach ($versions as $version) {
            $version->permanent_delete();
        }

        // Delete dependency records
        require_once(PathHelper::getIncludePath('data/plugin_dependencies_class.php'));
        $deps = new MultiPluginDependency(array('pld_plugin_name' => $name));
        $deps->load();
        foreach ($deps as $dep) {
            $dep->permanent_delete();
        }

        // Update plugin record
        $plugin->set('plg_status', 'uninstalled');
        $plugin->set('plg_active', 0);
        $plugin->set('plg_activated_time', null);
        $plugin->set('plg_uninstalled_time', gmdate('Y-m-d H:i:s'));
        $plugin->save();
    }

    /**
     * Drop all plugin database tables. Uses regex to extract table names from
     * *_class.php files (avoids loading classes with potentially missing deps).
     * Also cleans up plm_plugin_migrations records.
     *
     * Call BEFORE deleting plugin files.
     *
     * @param string $name Plugin name
     */
    public function permanentDeleteTables($name) {
        $dblink = DbConnector::get_instance()->get_db_link();
        $data_dir = PathHelper::getAbsolutePath("plugins/{$name}/data");

        if (!is_dir($data_dir)) {
            return;
        }

        $class_files = glob($data_dir . '/*_class.php');
        if (empty($class_files)) {
            return;
        }

        foreach ($class_files as $class_file) {
            $content = file_get_contents($class_file);
            if (preg_match('/\$tablename\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
                $tablename = $matches[1];
                // Validate table name to prevent injection (should only be alphanumeric + underscore)
                if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $tablename)) {
                    $dblink->exec("DROP TABLE IF EXISTS " . $tablename . " CASCADE");
                }
            }
        }

        // Clean up migration records
        require_once(PathHelper::getIncludePath('data/plugin_migrations_class.php'));
        $migrations = new MultiPluginMigration(array('plm_plugin_name' => $name));
        $migrations->load();
        foreach ($migrations as $migration) {
            $migration->permanent_delete();
        }
    }

    // ========== Public API Methods (Backward Compatibility) ==========

    /**
     * Sync filesystem plugins with database
     * @return array Array of newly synced plugin names
     */
    public function syncWithFilesystem() {
        $result = parent::sync();

        // Update database tables for all active plugins
        // This ensures new data classes in existing plugins get their tables created
        require_once(PathHelper::getIncludePath('includes/DatabaseUpdater.php'));
        require_once(PathHelper::getIncludePath('data/plugins_class.php'));
        $database_updater = new DatabaseUpdater();
        $active_plugins = new MultiPlugin(['plg_active' => 1]);
        $active_plugins->load();
        $table_messages = [];
        foreach ($active_plugins as $plugin) {
            $plugin_name = $plugin->get('plg_name');
            $table_result = $database_updater->runPluginTablesOnly($plugin_name);
            if (!empty($table_result['messages'])) {
                $table_messages = array_merge($table_messages, $table_result['messages']);
            }
        }
        $result['table_messages'] = $table_messages;

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
    
    /**
     * Get all plugins that depend on a given plugin
     * @param string $plugin_name Plugin name
     * @return array Array of dependent plugin names
     */
    protected function getDependents($plugin_name) {
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