<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/AbstractExtensionManager.php'));
require_once(PathHelper::getIncludePath('data/plugins_class.php'));
require_once(PathHelper::getIncludePath('data/plugin_dependencies_class.php'));
require_once(PathHelper::getIncludePath('data/plugin_migrations_class.php'));
require_once(PathHelper::getIncludePath('data/settings_class.php'));

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
        
        // Mark as preserved-on-deploy since it was uploaded
        $plugin = Plugin::get_by_plugin_name($name);
        if ($plugin) {
            $plugin->set('plg_receives_upgrades', false);
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
        $model->set('plg_receives_upgrades', $metadata['receives_upgrades'] ?? true);
        $model->set('plg_is_system', $metadata['is_system'] ?? false);
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
        $model->load_receives_upgrades();
        $metadata = $model->get_plugin_metadata();
        if ($metadata) {
            $model->set('plg_is_system', $metadata['is_system'] ?? false);
        }
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

        // Check Joinery version requirement — read from the canonical VERSION file.
        // Fail-closed: if we can't determine Joinery's version, reject.
        if (isset($manifest['requires']['joinery'])) {
            require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
            $required_joinery = $manifest['requires']['joinery'];
            $joinery_version = LibraryFunctions::get_joinery_version();
            if ($joinery_version === '') {
                $results['valid'] = false;
                $results['errors'][] = "Joinery $required_joinery required, but installed Joinery version could not be determined";
            } elseif (!$this->checkVersionConstraint($joinery_version, $required_joinery)) {
                $results['valid'] = false;
                $results['errors'][] = "Joinery version $joinery_version does not meet requirement: $required_joinery";
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
        
        // Scan all existing data classes (core + installed plugins, excluding self)
        $existing_classes = $existing_tables = $existing_prefixes = [];
        $scan_dirs = [PathHelper::getAbsolutePath('data')];
        $plugins_dir = PathHelper::getAbsolutePath('plugins');
        foreach (glob($plugins_dir . '/*/data') ?: [] as $dir) {
            if (strpos($dir, "plugins/{$plugin_name}/") === false) {
                $scan_dirs[] = $dir;
            }
        }
        foreach ($scan_dirs as $dir) {
            foreach (glob($dir . '/*.php') ?: [] as $file) {
                $content = file_get_contents($file);
                preg_match_all('/^\s*class\s+([A-Za-z_]\w*)/m', $content, $cm);
                preg_match_all('/\$tablename\s*=\s*[\'"]([a-z0-9_]+)[\'"]/', $content, $tm);
                preg_match_all('/\$prefix\s*=\s*[\'"]([a-z0-9_]+)[\'"]/', $content, $pm);
                $existing_classes = array_merge($existing_classes, $cm[1]);
                foreach ($tm[1] as $t) $existing_tables[$t] = basename(dirname($dir));
                foreach ($pm[1] as $p) $existing_prefixes[$p] = basename(dirname($dir));
            }
        }

        // Scan the incoming plugin
        $incoming_classes = $incoming_tables = $incoming_prefixes = [];
        $data_dir = PathHelper::getAbsolutePath("plugins/{$plugin_name}/data");
        foreach (glob($data_dir . '/*.php') ?: [] as $file) {
            $content = file_get_contents($file);
            preg_match_all('/^\s*class\s+([A-Za-z_]\w*)/m', $content, $cm);
            preg_match_all('/\$tablename\s*=\s*[\'"]([a-z0-9_]+)[\'"]/', $content, $tm);
            preg_match_all('/\$prefix\s*=\s*[\'"]([a-z0-9_]+)[\'"]/', $content, $pm);
            $incoming_classes = array_merge($incoming_classes, $cm[1]);
            $incoming_tables = array_merge($incoming_tables, $tm[1]);
            $incoming_prefixes = array_merge($incoming_prefixes, $pm[1]);
        }

        // Check collisions
        foreach ($incoming_classes as $cls) {
            if (in_array($cls, $existing_classes)) {
                $results['valid'] = false;
                $results['errors'][] = "Class name collision: '{$cls}' is already defined";
            }
        }
        foreach ($incoming_tables as $tbl) {
            if (isset($existing_tables[$tbl])) {
                $results['valid'] = false;
                $results['errors'][] = "Table name collision: '{$tbl}' is already used by {$existing_tables[$tbl]}";
            }
        }
        foreach ($incoming_prefixes as $pfx) {
            if (isset($existing_prefixes[$pfx]) && empty(array_intersect($incoming_tables, array_keys($existing_tables)))) {
                $results['warnings'][] = "Table prefix '{$pfx}' is also used by {$existing_prefixes[$pfx]} — consider a more distinctive prefix";
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

        // Seed declared default settings from plugin.json
        $this->syncSettings($name);

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

        // Sync declarative menus (admin sidebar + user dropdown) from plugin.json
        $this->syncMenus($name);
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

        // Remove declarative menus (both admin sidebar + user dropdown)
        $this->syncMenus($name, ['admin' => [], 'profile' => []]);

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
     * Fetch a fresh plugin archive from the upgrade endpoint and extract it over
     * plugins/{name}/. The upgrade endpoint is the authoritative source of truth
     * for published plugin files; this runs at install time so stale on-disk code
     * (e.g. after an uninstall/reinstall cycle) gets replaced with upstream.
     *
     * Does not read the on-disk plugin.json to decide — the endpoint's response
     * is what determines whether this plugin is upstream-published. A 404 means
     * the plugin is not in the publisher's catalog (included_in_publish=false);
     * any other failure means the endpoint is unreachable or the transfer failed.
     *
     * Failure modes are non-fatal: install falls through to on-disk files.
     *
     * @param string $name Plugin name
     */
    public function refreshFromUpstream($name) {
        $settings = Globalvars::get_instance();
        $upgrade_source = $settings->get_setting('upgrade_source');

        if (empty($upgrade_source)) {
            error_log("refreshFromUpstream: upgrade_source not configured; skipping refresh for '$name'");
            return;
        }

        $url = rtrim($upgrade_source, '/') . '/admin/server_manager/publish_theme'
             . '?download=' . urlencode($name) . '&type=plugin';

        // Append .tar.gz so PharData recognizes the archive format on extract.
        $temp_base = tempnam(sys_get_temp_dir(), 'joinery_plugin_');
        $temp_file = $temp_base . '.tar.gz';
        @unlink($temp_base);
        $fp = fopen($temp_file, 'w');
        if (!$fp) {
            @unlink($temp_file);
            error_log("refreshFromUpstream: could not open temp file for '$name'");
            return;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_FILE => $fp,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $ok = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if ($http_code === 404) {
            // Custom plugin — upstream doesn't know about it. On-disk files are source of truth.
            @unlink($temp_file);
            return;
        }

        if (!$ok || $http_code !== 200 || filesize($temp_file) < 100) {
            @unlink($temp_file);
            error_log("refreshFromUpstream: fetch failed for '$name' (HTTP $http_code" .
                      ($curl_error ? ", curl: $curl_error" : '') . "); falling back to on-disk files");
            return;
        }

        $plugins_root = PathHelper::getAbsolutePath('plugins');
        if (!is_dir($plugins_root)) {
            @unlink($temp_file);
            error_log("refreshFromUpstream: plugins root directory not found; skipping refresh for '$name'");
            return;
        }

        // Archive root contains the plugin directory (e.g. bookings/data/...),
        // so extracting into plugins/ overwrites plugins/{name}/ contents in-place.
        // PharData (PHP-native) avoids the chmod-on-existing-directory failures
        // the tar binary hits in mixed-ownership dev environments.
        try {
            $phar = new PharData($temp_file);
            $phar->extractTo($plugins_root, null, true);
        } catch (Exception $e) {
            error_log("refreshFromUpstream: extract failed for '$name': " . $e->getMessage());
        }
        @unlink($temp_file);
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

        // Before any DB work, attempt to refresh the plugin's files from the
        // upgrade endpoint. Plugins with included_in_publish=true on the upgrade
        // server get fresh code on every install; plugins not in the catalog 404
        // silently and install proceeds with on-disk files.
        $this->refreshFromUpstream($name);

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
     * Uninstall a plugin — destructive. Removes scaffolding (settings, menus,
     * deletion rules, scheduled tasks, version/dependency/migration records),
     * runs the plugin's optional uninstall.php hook, drops plugin tables, and
     * deletes the plg_plugins row. Files on disk are preserved.
     *
     * Plugin must be inactive before calling.
     *
     * Hook failure is fatal: if the hook throws or returns false, the table
     * drop and row deletion are skipped. Scaffolding cleanup (steps 1-5) is
     * idempotent, so the operator can fix the hook and re-run uninstall.
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

        $dependents = $this->getDependents($name);
        if (!empty($dependents)) {
            throw new Exception("Cannot uninstall plugin '$name': other plugins depend on it: " . implode(', ', $dependents));
        }

        require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));
        $plugin_helper = PluginHelper::getInstance($name);

        // Step 1: Delete declared settings. Settings previously declared and
        // since removed from the manifest are left as orphans by design.
        try {
            $declared_settings = $plugin_helper->getDeclaredSettings();
            Setting::unseed_declared($declared_settings);
        } catch (Exception $e) {
            error_log("Failed to remove declared settings for plugin '$name' during uninstall: " . $e->getMessage());
        }

        // Step 2: Delete menus (admin sidebar + user dropdown)
        $this->syncMenus($name, ['admin' => [], 'profile' => []]);

        // Step 3: Remove deletion rules
        try {
            $plugin_helper->removePluginDeletionRules();
        } catch (Exception $e) {
            error_log("Failed to remove deletion rules for plugin '$name' during uninstall: " . $e->getMessage());
        }

        // Step 4: Delete scheduled task records
        require_once(PathHelper::getIncludePath('data/scheduled_tasks_class.php'));
        $tasks = new MultiScheduledTask(array('plugin_name' => $name, 'deleted' => false));
        $tasks->load();
        foreach ($tasks as $task) {
            $task->permanent_delete();
        }

        // Step 5: Delete version, dependency, and migration records
        require_once(PathHelper::getIncludePath('data/plugin_versions_class.php'));
        $versions = new MultiPluginVersion(array('plv_plugin_name' => $name));
        $versions->load();
        foreach ($versions as $version) {
            $version->permanent_delete();
        }

        require_once(PathHelper::getIncludePath('data/plugin_dependencies_class.php'));
        $deps = new MultiPluginDependency(array('pld_plugin_name' => $name));
        $deps->load();
        foreach ($deps as $dep) {
            $dep->permanent_delete();
        }

        require_once(PathHelper::getIncludePath('data/plugin_migrations_class.php'));
        $migrations = new MultiPluginMigration(array('plm_plugin_name' => $name));
        $migrations->load();
        foreach ($migrations as $migration) {
            $migration->permanent_delete();
        }

        // Step 6: Run uninstall hook. Runs after scaffolding cleanup but before
        // tables are dropped, so the hook can still query the plugin's own
        // tables for external teardown (e.g. revoking cached API keys).
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

        // Step 7: Drop plugin tables. Regex-extracts table names from *_class.php
        // files rather than loading the classes (avoids missing-dep issues when
        // the plugin's code path is partially torn down). Also drops orphan
        // sequences — Joinery's sequences aren't column-owned in pg_depend, so
        // DROP TABLE CASCADE doesn't sweep them automatically.
        $dblink = DbConnector::get_instance()->get_db_link();
        $data_dir = PathHelper::getAbsolutePath("plugins/{$name}/data");
        if (is_dir($data_dir)) {
            foreach (glob($data_dir . '/*_class.php') as $class_file) {
                $content = file_get_contents($class_file);
                if (preg_match('/\$tablename\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
                    $tablename = $matches[1];
                    if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $tablename)) {
                        $dblink->exec("DROP TABLE IF EXISTS " . $tablename . " CASCADE");

                        $seq_stmt = $dblink->prepare(
                            "SELECT relname FROM pg_class WHERE relkind = 'S' AND relname LIKE ?"
                        );
                        $seq_stmt->execute([$tablename . '\_%']);
                        foreach ($seq_stmt->fetchAll(PDO::FETCH_COLUMN) as $seqname) {
                            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $seqname)) {
                                $dblink->exec("DROP SEQUENCE IF EXISTS " . $seqname . " CASCADE");
                            }
                        }
                    }
                }
            }
        }

        // Step 8: Delete the plg_plugins row
        $plugin->permanent_delete();
    }

    // ========== Public API Methods (Backward Compatibility) ==========

    /**
     * Comprehensive plugin sync: filesystem scan, table updates, migrations, deletion rules.
     *
     * Overrides the base sync() so that every caller (upgrade.php, postInstall,
     * marketplace, admin UI) automatically gets the full behaviour.
     *
     * @return array Sync result with keys: added, updated, total, table_messages, migration_messages
     */
    public function sync(array $options = array()) {
        $result = parent::sync($options);

        // Update database tables and run migrations for all active plugins
        require_once(PathHelper::getIncludePath('includes/DatabaseUpdater.php'));
        require_once(PathHelper::getIncludePath('data/plugins_class.php'));
        $database_updater = new DatabaseUpdater();
        $active_plugins = new MultiPlugin(['plg_active' => 1]);
        $active_plugins->load();
        $table_messages = [];
        $migration_messages = [];
        foreach ($active_plugins as $plugin) {
            $plugin_name = $plugin->get('plg_name');

            // Update tables (adds missing columns, creates new tables)
            $table_result = $database_updater->runPluginTablesOnly($plugin_name);
            if (!empty($table_result['messages'])) {
                $table_messages = array_merge($table_messages, $table_result['messages']);
            }

            // Run pending migrations
            try {
                $migration_results = $this->runPendingMigrations($plugin_name);
                foreach ($migration_results as $m) {
                    if (!empty($m['error'])) {
                        $migration_messages[] = "$plugin_name: " . $m['error'];
                    } elseif (!empty($m['id'])) {
                        $migration_messages[] = "$plugin_name: applied " . $m['id'];
                    }
                }
            } catch (Exception $e) {
                $migration_messages[] = "$plugin_name: migration error - " . $e->getMessage();
            }
        }
        $result['table_messages'] = $table_messages;
        $result['migration_messages'] = $migration_messages;

        // Register deletion rules for ALL active plugins
        require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));
        PluginHelper::registerAllActiveDeletionRules();

        // Sync declarative menus (admin sidebar + user dropdown) for all active plugins
        foreach ($active_plugins as $plugin) {
            $this->syncMenus($plugin->get('plg_name'));
        }

        // Sync declarative settings for all active plugins — seeds any that
        // were added in a newer manifest version
        $settings_messages = [];
        foreach ($active_plugins as $plugin) {
            $plugin_name = $plugin->get('plg_name');
            try {
                $this->syncSettings($plugin_name);
            } catch (Exception $e) {
                $settings_messages[] = "$plugin_name: " . $e->getMessage();
                error_log("syncSettings skipped for '$plugin_name': " . $e->getMessage());
            }
        }
        if (!empty($settings_messages)) {
            $result['settings_messages'] = $settings_messages;
        }

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

    // ========== Declarative Admin Menus ==========

    /**
     * Seed a plugin's declared default settings into stg_settings.
     *
     * Reads the 'settings' array from plugin.json, validates it, then inserts
     * any rows that don't already exist (seed-only — existing values are
     * preserved).
     *
     * @param string $plugin_name Plugin directory name
     * @throws Exception if validation fails
     */
    public function syncSettings($plugin_name) {
        try {
            $helper = PluginHelper::getInstance($plugin_name);
        } catch (Exception $e) {
            error_log("syncSettings: could not read plugin.json for '$plugin_name': " . $e->getMessage());
            return;
        }

        $declared = $helper->getDeclaredSettings();
        if (empty($declared)) return;

        $this->validateDeclaredSettings($plugin_name, $declared);
        Setting::seed_declared($declared);
    }

    /**
     * Validate a plugin's declared settings against the two design rules:
     *   1. Every declared 'name' must start with the plugin's directory name.
     *   2. No declared 'name' may collide with a core setting in settings.json.
     *
     * Also rejects non-string defaults (e.g. JSON booleans or numbers) — values
     * in stg_settings are always strings, so the manifest must match.
     *
     * @param string $plugin_name
     * @param array $declared
     * @throws Exception on any rule violation
     */
    protected function validateDeclaredSettings($plugin_name, array $declared) {
        $prefix = $plugin_name . '_';

        // Load core setting names as a lookup map for collision checks.
        $core_names = [];
        $core_path = PathHelper::getIncludePath('settings.json');
        if (file_exists($core_path)) {
            $core_data = json_decode(file_get_contents($core_path), true);
            foreach ($core_data['settings'] ?? [] as $entry) {
                if (!empty($entry['name'])) $core_names[$entry['name']] = true;
            }
        }

        foreach ($declared as $i => $entry) {
            if (!is_array($entry) || empty($entry['name'])) {
                throw new Exception("Plugin '$plugin_name' settings entry #$i is missing a 'name'.");
            }
            $name = $entry['name'];

            if (strpos($name, $prefix) !== 0) {
                throw new Exception("Plugin '$plugin_name' declares setting '$name' — must start with the plugin's directory name ('$prefix').");
            }

            if (isset($core_names[$name])) {
                throw new Exception("Plugin '$plugin_name' declares setting '$name' — collides with a core setting in settings.json.");
            }

            if (array_key_exists('default', $entry) && !is_string($entry['default'])) {
                throw new Exception("Plugin '$plugin_name' setting '$name' has non-string default — use \"0\"/\"1\" for booleans and \"42\" for numbers.");
            }
        }
    }

    /**
     * Sync menus (admin sidebar + user dropdown) from a declared source —
     * either a plugin's plugin.json or the core admin_menus.json.
     *
     * Handles creation, update, and removal. Pass ['admin' => [], 'profile' => []]
     * to remove all menus owned by the source (plugin deactivate/uninstall).
     *
     * @param string $source_name Plugin directory name, or 'core' for core menus.
     * @param array|null $declared When null, reads from plugin.json (plugins only;
     *                             throws for source_name='core'). When an array,
     *                             must use shape ['admin' => [...], 'profile' => [...]].
     *                             Missing keys are treated as empty arrays.
     * @param array $options ['overwrite' => bool, 'prune' => bool]. Defaults:
     *                       overwrite=true, prune=true (preserves plugin behavior).
     *                       Core caller passes overwrite=false, prune=false to
     *                       preserve admin customizations to existing rows.
     */
    public function syncMenus(string $source_name, ?array $declared = null, array $options = []): void {
        require_once(PathHelper::getIncludePath('data/admin_menus_class.php'));

        $overwrite = $options['overwrite'] ?? true;
        $prune     = $options['prune']     ?? true;

        // Read declared menus from plugin.json if not explicitly provided.
        // Core has no plugin.json — caller must always pass $declared.
        if ($declared === null) {
            if ($source_name === 'core') {
                throw new InvalidArgumentException(
                    "syncMenus('core'): \$declared is required for source_name='core' (no plugin.json to read from)."
                );
            }
            try {
                $helper = PluginHelper::getInstance($source_name);
                $declared = [
                    'admin'   => $helper->getAdminMenuItems(),
                    'profile' => $helper->getProfileMenuItems(),
                ];
            } catch (Exception $e) {
                error_log("syncMenus: could not read plugin.json for '$source_name': " . $e->getMessage());
                $declared = ['admin' => [], 'profile' => []];
            }
        }

        $admin_items   = $declared['admin']   ?? [];
        $profile_items = $declared['profile'] ?? [];

        // === Strict per-entry validation up front (before any DB work). ===
        // Required fields per entry: slug, title, order, permission.
        // Nested children may inherit 'permission' from their parent.
        $validate = function (array $entry, string $kind, string $idx_path, bool $allow_permission_missing) use ($source_name) {
            foreach (['slug', 'title', 'order'] as $field) {
                if (!array_key_exists($field, $entry)) {
                    throw new InvalidArgumentException(
                        "syncMenus('$source_name'): {$kind}[$idx_path] missing required field '$field'"
                    );
                }
            }
            if (!$allow_permission_missing && !array_key_exists('permission', $entry)) {
                throw new InvalidArgumentException(
                    "syncMenus('$source_name'): {$kind}[$idx_path] missing required field 'permission'"
                );
            }
            if (!is_string($entry['slug']) || $entry['slug'] === '') {
                throw new InvalidArgumentException(
                    "syncMenus('$source_name'): {$kind}[$idx_path] field 'slug' must be a non-empty string"
                );
            }
            if (!is_string($entry['title']) || $entry['title'] === '') {
                throw new InvalidArgumentException(
                    "syncMenus('$source_name'): {$kind}[$idx_path] (slug='{$entry['slug']}') field 'title' must be a non-empty string"
                );
            }
            if (!is_int($entry['order'])) {
                throw new InvalidArgumentException(
                    "syncMenus('$source_name'): {$kind}[$idx_path] (slug='{$entry['slug']}') field 'order' must be an integer"
                );
            }
            if (array_key_exists('permission', $entry) && !is_int($entry['permission'])) {
                throw new InvalidArgumentException(
                    "syncMenus('$source_name'): {$kind}[$idx_path] (slug='{$entry['slug']}') field 'permission' must be an integer"
                );
            }
        };

        // Reserved-slug gate: only 'core' may declare core-* slugs.
        $reserved_check = function (array $entry, string $kind, string $idx_path) use ($source_name) {
            if ($source_name !== 'core' && strpos($entry['slug'], 'core-') === 0) {
                throw new InvalidArgumentException(
                    "syncMenus('$source_name'): {$kind}[$idx_path] (slug='{$entry['slug']}') reserved slug 'core-*' is for core only"
                );
            }
        };

        foreach ($admin_items as $idx => $item) {
            $validate($item, 'adminMenu', (string)$idx, false);
            $reserved_check($item, 'adminMenu', (string)$idx);
            if (!empty($item['items']) && is_array($item['items'])) {
                foreach ($item['items'] as $cidx => $child) {
                    $validate($child, 'adminMenu', "$idx.items.$cidx", true);
                    $reserved_check($child, 'adminMenu', "$idx.items.$cidx");
                }
            }
        }
        foreach ($profile_items as $idx => $item) {
            $validate($item, 'profileMenu', (string)$idx, false);
            $reserved_check($item, 'profileMenu', (string)$idx);
        }

        // === Build flat entry list ===
        $admin_parents  = [];
        $admin_children = [];
        foreach ($admin_items as $item) {
            $entry = [
                'slug' => $item['slug'],
                'title' => $item['title'],
                'url' => $item['url'] ?? '',
                'order' => $item['order'],
                'permission' => $item['permission'],
                'icon' => $item['icon'] ?? null,
                'settingActivate' => $item['settingActivate'] ?? null,
                'disabled' => $item['disabled'] ?? false,
                'parent_slug' => $item['parent'] ?? null,
                'location' => 'admin_sidebar',
                'visibility' => 'in',
            ];

            if (!empty($item['items']) && is_array($item['items'])) {
                $admin_parents[] = $entry;
                foreach ($item['items'] as $child) {
                    $admin_children[] = [
                        'slug' => $child['slug'],
                        'title' => $child['title'],
                        'url' => $child['url'] ?? '',
                        'order' => $child['order'],
                        'permission' => $child['permission'] ?? $item['permission'],
                        'icon' => $child['icon'] ?? null,
                        'settingActivate' => $child['settingActivate'] ?? null,
                        'disabled' => $child['disabled'] ?? false,
                        'parent_slug' => $item['slug'],
                        'location' => 'admin_sidebar',
                        'visibility' => 'in',
                    ];
                }
            } elseif (!empty($item['parent'])) {
                $admin_children[] = $entry;
            } else {
                $admin_parents[] = $entry;
            }
        }

        $profile_entries = [];
        foreach ($profile_items as $item) {
            $profile_entries[] = [
                'slug' => $item['slug'],
                'title' => $item['title'],
                'url' => $item['url'] ?? '',
                'order' => $item['order'],
                'permission' => $item['permission'],
                'icon' => $item['icon'] ?? null,
                'settingActivate' => $item['settingActivate'] ?? null,
                'disabled' => $item['disabled'] ?? false,
                'parent_slug' => null,
                'location' => 'user_dropdown',
                'visibility' => $item['visibility'] ?? 'in',
            ];
        }

        $flat_entries = array_merge($admin_parents, $admin_children, $profile_entries);
        $declared_slugs = array_column($flat_entries, 'slug');

        $dblink = DbConnector::get_instance()->get_db_link();

        // Previous slugs only meaningful for plugins (core has no plg_plugins row).
        $previous_slugs = ($source_name === 'core') ? [] : $this->getMenuSlugsFromMetadata($source_name);

        // === Upsert ===
        foreach ($flat_entries as $entry) {
            $parent_menu_id = null;

            // Resolve parent slug to ID (admin items only). Throw on missing parent.
            if (!empty($entry['parent_slug'])) {
                $q = $dblink->prepare("SELECT amu_admin_menu_id FROM amu_admin_menus WHERE amu_slug = ?");
                $q->execute([$entry['parent_slug']]);
                $parent_menu_id = $q->fetchColumn();

                if ($parent_menu_id === false) {
                    throw new InvalidArgumentException(
                        "syncMenus('$source_name'): entry (slug='{$entry['slug']}') references parent slug '{$entry['parent_slug']}' which does not exist"
                    );
                }
            }

            // Check if row already exists
            $q = $dblink->prepare("SELECT amu_admin_menu_id FROM amu_admin_menus WHERE amu_slug = ?");
            $q->execute([$entry['slug']]);
            $existing_id = $q->fetchColumn();

            $url = $entry['url'] ?? '';
            $permission = $entry['permission'];
            $icon = $entry['icon'];
            $setting_activate = $entry['settingActivate'];
            $disabled = (!empty($entry['disabled'])) ? 1 : 0;

            if ($existing_id !== false) {
                if (!$overwrite) continue;
                $q = $dblink->prepare(
                    "UPDATE amu_admin_menus SET
                        amu_menudisplay = ?,
                        amu_defaultpage = ?,
                        amu_parent_menu_id = ?,
                        amu_order = ?,
                        amu_min_permission = ?,
                        amu_icon = ?,
                        amu_setting_activate = ?,
                        amu_disable = ?,
                        amu_location = ?,
                        amu_visibility = ?
                    WHERE amu_admin_menu_id = ?"
                );
                $q->execute([
                    $entry['title'],
                    $url,
                    $parent_menu_id,
                    $entry['order'],
                    $permission,
                    $icon,
                    $setting_activate,
                    $disabled,
                    $entry['location'],
                    $entry['visibility'],
                    $existing_id
                ]);
            } else {
                $q = $dblink->prepare(
                    "INSERT INTO amu_admin_menus
                        (amu_menudisplay, amu_slug, amu_defaultpage, amu_parent_menu_id, amu_order, amu_min_permission, amu_icon, amu_setting_activate, amu_disable, amu_location, amu_visibility)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $q->execute([
                    $entry['title'],
                    $entry['slug'],
                    $url,
                    $parent_menu_id,
                    $entry['order'],
                    $permission,
                    $icon,
                    $setting_activate,
                    $disabled,
                    $entry['location'],
                    $entry['visibility']
                ]);
            }
        }

        // === Prune (gated) ===
        if ($prune) {
            $slugs_to_remove = array_diff($previous_slugs, $declared_slugs);
            if (!empty($slugs_to_remove)) {
                $placeholders = implode(',', array_fill(0, count($slugs_to_remove), '?'));
                $q = $dblink->prepare(
                    "DELETE FROM amu_admin_menus WHERE amu_slug IN ({$placeholders})
                     AND amu_parent_menu_id IS NOT NULL"
                );
                $q->execute(array_values($slugs_to_remove));

                $q = $dblink->prepare(
                    "DELETE FROM amu_admin_menus WHERE amu_slug IN ({$placeholders})"
                );
                $q->execute(array_values($slugs_to_remove));
            }
        }

        // Plugins track their declared slugs in plg_metadata; core has no row to write.
        if ($source_name !== 'core') {
            $this->saveMenuSlugsToMetadata($source_name, $declared_slugs);
        }
    }

    /**
     * Backward-compatible alias for the renamed syncMenus(). Reads adminMenu only
     * when the legacy single-array signature is used. New code should call
     * syncMenus() directly.
     *
     * @param string $plugin_name
     * @param array|null $declared_menus Legacy admin-only items, or null to read both keys from plugin.json
     * @deprecated Use syncMenus() instead.
     */
    public function syncAdminMenus($plugin_name, $declared_menus = null) {
        if ($declared_menus === null) {
            $this->syncMenus($plugin_name, null);
            return;
        }
        // Legacy callers pass admin-only items. To deactivate (empty array), wipe
        // both menu types so plugins migrating to syncMenus don't strand profile rows.
        $this->syncMenus($plugin_name, ['admin' => $declared_menus, 'profile' => []]);
    }

    /**
     * Get the previously synced menu slugs from the plugin's plg_metadata.
     *
     * @param string $plugin_name Plugin name
     * @return array List of slug strings
     */
    private function getMenuSlugsFromMetadata($plugin_name) {
        $plugin = Plugin::get_by_plugin_name($plugin_name);
        if (!$plugin) {
            return [];
        }

        $metadata_raw = $plugin->get('plg_metadata');
        if (empty($metadata_raw)) {
            return [];
        }

        $metadata = json_decode($metadata_raw, true);
        if (!is_array($metadata)) {
            return [];
        }

        return $metadata['_menu_slugs'] ?? [];
    }

    /**
     * Save the current declared menu slugs into the plugin's plg_metadata.
     *
     * @param string $plugin_name Plugin name
     * @param array $slugs List of slug strings
     */
    private function saveMenuSlugsToMetadata($plugin_name, $slugs) {
        $plugin = Plugin::get_by_plugin_name($plugin_name);
        if (!$plugin) {
            return;
        }

        $metadata_raw = $plugin->get('plg_metadata');
        $metadata = [];
        if (!empty($metadata_raw)) {
            $decoded = json_decode($metadata_raw, true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }

        if (empty($slugs)) {
            unset($metadata['_menu_slugs']);
        } else {
            $metadata['_menu_slugs'] = array_values($slugs);
        }

        $plugin->set('plg_metadata', json_encode($metadata));
        $plugin->save();
    }
}
?>