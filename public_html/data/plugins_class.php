<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class PluginException extends SystemBaseException {}
class PluginNotSentException extends PluginException {};

class Plugin extends SystemBase {	public static $prefix = 'plg';
	public static $tablename = 'plg_plugins';
	public static $pkey_column = 'plg_plugin_id';

		/**
	 * Field specifications define database column properties and validation rules
	 * 
	 * Database schema properties (used by update_database):
	 *   'type' => 'varchar(255)' | 'int4' | 'int8' | 'text' | 'timestamp' | 'bool' | etc.
	 *   'is_nullable' => true/false - Whether NULL values are allowed
	 *   'serial' => true/false - Auto-incrementing field
	 * 
	 * Validation and behavior properties (used by SystemBase):
	 *   'required' => true/false - Field must have non-empty value on save
	 *   'default' => mixed - Default value for new records (applied on INSERT only)
	 *   'zero_on_create' => true/false - Set to 0 when creating if NULL (INSERT only)
	 * 
	 * Note: Timestamp fields are auto-detected based on type for smart_get() and export_as_array()
	 */
	public static $field_specifications = array(
	    'plg_plugin_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'plg_name' => array('type'=>'varchar(128)', 'required'=>true, 'unique'=>true),
	    'plg_activated_time' => array('type'=>'timestamp(6)'),
	    'plg_active' => array('type'=>'int4', 'is_nullable'=>true),
	    'plg_installed_time' => array('type'=>'timestamp(6)'),
	    'plg_last_activated_time' => array('type'=>'timestamp(6)'),
	    'plg_last_deactivated_time' => array('type'=>'timestamp(6)'),
	    'plg_uninstalled_time' => array('type'=>'timestamp(6)'),
	    'plg_status' => array('type'=>'varchar(20)'),
	    'plg_install_error' => array('type'=>'text'),
	    'plg_metadata' => array('type'=>'text'),
	    'plg_is_stock' => array('type'=>'bool', 'default'=>true),
	    'plg_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'plg_update_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);

function authenticate_write($data) {
			if ($data['current_user_permission'] < 10) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this entry in '. static::$tablename);
			}
	}

	/**
	 * Check if plugin is currently active
	 * @return bool Active status
	 */
	public function is_active() {
		// Use new status field if available, fall back to activated_time
		$status = $this->get('plg_status');
		if ($status) {
			return $status === 'active';
		}
		// Legacy support
		return !is_null($this->get('plg_activated_time'));
	}
	
	/**
	 * Get plugin by plugin name
	 * @param string $plugin_name Plugin directory name
	 * @return Plugin|null
	 */
	public static function get_by_plugin_name($plugin_name) {
		$plugins = new MultiPlugin(
			array('plg_name' => $plugin_name)
		);
		$plugins->load();
		return $plugins->count() > 0 ? $plugins->get(0) : null;
	}
	
	/**
	 * Get formatted activation status for display
	 * @return string HTML badge showing status
	 */
	public function get_status_badge() {
		$status = $this->get('plg_status');
		$install_error = $this->get('plg_install_error');
		
		// If there's an install error, show that regardless of status
		if ($install_error) {
			if ($status === 'uninstalled') {
				return '<span class="badge bg-warning">Install Failed</span>';
			} else {
				return '<span class="badge bg-danger">Error</span>';
			}
		}
		
		switch ($status) {
			case 'active':
				return '<span class="badge bg-success">Active</span>';
			case 'inactive':
				return '<span class="badge bg-secondary">Inactive</span>';
			case 'installed':
				return '<span class="badge bg-info">Installed</span>';
			case 'error':
				return '<span class="badge bg-danger">Error</span>';
			case 'uninstalled':
				return '<span class="badge bg-light text-dark border border-secondary"><i class="fas fa-times-circle"></i> Uninstalled</span>';
			default:
				// Legacy support
				if ($this->is_active()) {
					return '<span class="badge bg-success">Active</span>';
				} else {
					return '<span class="badge bg-secondary">Inactive</span>';
				}
		}
	}
	
	/**
	 * Validate plugin name to prevent directory traversal
	 * @param string $plugin_name Plugin name to validate
	 * @return bool True if valid
	 */
	public static function is_valid_plugin_name($plugin_name) {
		// Only allow alphanumeric, underscore, and hyphen
		return preg_match('/^[a-zA-Z0-9_-]+$/', $plugin_name);
	}
	
	/**
	 * Check if plugin directory exists
	 * @return bool True if plugin directory exists
	 */
	public function plugin_directory_exists() {
		$plugin_name = $this->get('plg_name');
		if (!$plugin_name) {
			return false;
		}
		
		$plugin_dir = PathHelper::getIncludePath('plugins/' . $plugin_name);
		return is_dir($plugin_dir);
	}
	
	/**
	 * Get plugin metadata from plugin.json if it exists
	 * @return array|null Metadata array or null if not found
	 */
	public function get_plugin_metadata() {
		$plugin_name = $this->get('plg_name');
		if (!$plugin_name) {
			return null;
		}
		
		$metadata_file = PathHelper::getIncludePath('plugins/' . $plugin_name . '/plugin.json');
		if (!file_exists($metadata_file)) {
			return null;
		}
		
		$json_data = file_get_contents($metadata_file);
		$metadata = json_decode($json_data, true);
		
		// Return null if JSON is invalid
		if (json_last_error() !== JSON_ERROR_NONE) {
			return null;
		}
		
		return $metadata;
	}
	
	/**
	 * Get display name for plugin (from metadata or directory name)
	 * @return string Display name
	 */
	public function get_display_name() {
		$metadata = $this->get_plugin_metadata();
		if ($metadata && isset($metadata['name'])) {
			return $metadata['name'];
		}
		
		// Fallback to directory name
		return $this->get('plg_name');
	}
	
	/**
	 * Get description for plugin (from metadata)
	 * @return string|null Description or null if not available
	 */
	public function get_description() {
		$metadata = $this->get_plugin_metadata();
		if ($metadata && isset($metadata['description'])) {
			return $metadata['description'];
		}
		
		return null;
	}
	
	/**
	 * Get version for plugin (from metadata)
	 * @return string|null Version or null if not available
	 */
	public function get_version() {
		$metadata = $this->get_plugin_metadata();
		if ($metadata && isset($metadata['version'])) {
			return $metadata['version'];
		}
		
		return null;
	}
	
	/**
	 * Get author for plugin (from metadata)
	 * @return string|null Author or null if not available
	 */
	public function get_author() {
		$metadata = $this->get_plugin_metadata();
		if ($metadata && isset($metadata['author'])) {
			return $metadata['author'];
		}
		
		return null;
	}
	
	/**
	 * Override prepare to add plugin name validation
	 */
	public function prepare() {
		$plugin_name = $this->get('plg_name');
		if ($plugin_name && !self::is_valid_plugin_name($plugin_name)) {
			throw new PluginException('Invalid plugin name: ' . $plugin_name);
		}
		
		return parent::prepare();
	}

	// Static cache for plugin activation status
	private static $activation_cache = array();
	
	/**
	 * Check if plugin is active with caching
	 * @param string $plugin_name Plugin directory name
	 * @return bool True if plugin is active
	 */
	public static function is_plugin_active($plugin_name) {
		if (!isset(self::$activation_cache[$plugin_name])) {
			$plugin = self::get_by_plugin_name($plugin_name);
			self::$activation_cache[$plugin_name] = $plugin ? $plugin->is_active() : false;
		}
		return self::$activation_cache[$plugin_name];
	}
	
	/**
	 * Clear activation cache for a specific plugin
	 * @param string $plugin_name Plugin directory name
	 */
	public static function clear_activation_cache($plugin_name = null) {
		if ($plugin_name) {
			unset(self::$activation_cache[$plugin_name]);
		} else {
			self::$activation_cache = array();
		}
	}

	/**
	 * Install plugin - runs migrations and sets up initial state
	 * @return array Result with success status and messages
	 */
	public function install() {
		$results = [
			'success' => false,
			'messages' => [],
			'errors' => []
		];
		
		try {
			$plugin_name = $this->get('plg_name');
			if (!$plugin_name) {
				throw new PluginException('Plugin name not set');
			}
			
			// Validate plugin name
			if (!self::is_valid_plugin_name($plugin_name)) {
				throw new PluginException('Invalid plugin name');
			}
			
			// Check if plugin directory exists
			if (!$this->plugin_directory_exists()) {
				throw new PluginException('Plugin directory not found');
			}
			
			// Check dependencies
			require_once(PathHelper::getIncludePath('includes/PluginManager.php'));
			$plugin_manager = new PluginManager();
			$dependency_result = $plugin_manager->validatePlugin($plugin_name);
			
			if (!$dependency_result['valid']) {
				$results['errors'] = $dependency_result['errors'];
				return $results;
			}
			
			// Create plugin tables first
			require_once(PathHelper::getIncludePath('includes/DatabaseUpdater.php'));
			$database_updater = new DatabaseUpdater();
			$table_result = $database_updater->runPluginTablesOnly($plugin_name);
			
			if (!$table_result['success']) {
				$results['errors'] = array_merge($results['errors'], $table_result['errors']);
				$this->set('plg_install_error', implode('; ', $table_result['errors']));
				$this->set('plg_status', 'error');
				$this->save();
				return $results;
			}
			
			// Add table creation messages to results
			$results['messages'] = array_merge($results['messages'], $table_result['messages']);
			
			// Run migrations
			require_once(PathHelper::getIncludePath('includes/PluginManager.php'));
			$plugin_manager = new PluginManager();
			$migration_results = $plugin_manager->runPendingMigrations($plugin_name);

			// Check migration results - runPendingMigrations returns array of individual results
			$migration_errors = array();
			foreach ($migration_results as $migration_result) {
				if (!empty($migration_result['error'])) {
					$migration_errors[] = $migration_result['error'];
				}
			}

			if (!empty($migration_errors)) {
				$results['errors'] = $migration_errors;
				$this->set('plg_install_error', implode('; ', $migration_errors));
				$this->set('plg_status', 'error');
				$this->save();
				return $results;
			}
			
			// Update plugin record
			$this->set('plg_installed_time', date('Y-m-d H:i:s'));
			$this->set('plg_status', 'inactive');
			$this->set('plg_install_error', null);
			
			// Store metadata
			$metadata = $this->get_plugin_metadata();
			if ($metadata) {
				$this->set('plg_metadata', json_encode($metadata));
			}
			
			$this->save();

			$results['success'] = true;
			$results['messages'][] = "Plugin '{$plugin_name}' installed successfully";
			if (!empty($migration_results)) {
				$results['messages'][] = count($migration_results) . " migration(s) completed";
			}
			
		} catch (Exception $e) {
			$results['errors'][] = $e->getMessage();
			$this->set('plg_install_error', $e->getMessage());
			$this->set('plg_status', 'error');
			$this->save();
		}
		
		return $results;
	}
	
	/**
	 * Uninstall plugin - runs uninstall script and rollback migrations
	 * @return array Result with success status and messages
	 */
	public function uninstall() {
		$results = [
			'success' => false,
			'messages' => [],
			'errors' => []
		];
		
		try {
			$plugin_name = $this->get('plg_name');
			if (!$plugin_name) {
				throw new PluginException('Plugin name not set');
			}
			
			// Check if plugin is active
			if ($this->is_active()) {
				$results['errors'][] = 'Cannot uninstall active plugin. Deactivate it first.';
				return $results;
			}
			
			// Check dependencies
			require_once(PathHelper::getIncludePath('includes/PluginManager.php'));
			$plugin_manager = new PluginManager();
			$dependents = $plugin_manager->getDependents($plugin_name);
			$deactivation_check = array(
				'can_deactivate' => empty($dependents),
				'dependent_plugins' => $dependents
			);
			
			if (!$deactivation_check['can_deactivate']) {
				$results['errors'][] = 'Cannot uninstall plugin. Other plugins depend on it: ' . 
					implode(', ', $deactivation_check['dependent_plugins']);
				return $results;
			}
			
			// Run uninstall script if exists
			$uninstall_file = PathHelper::getIncludePath('plugins/' . $plugin_name . '/uninstall.php');
			if (file_exists($uninstall_file)) {
				include_once($uninstall_file);
				
				$uninstall_function = $plugin_name . '_uninstall';
				if (function_exists($uninstall_function)) {
					$uninstall_result = call_user_func($uninstall_function);
					if ($uninstall_result === false) {
						$results['errors'][] = 'Uninstall script failed';
						return $results;
					}
					$results['messages'][] = 'Ran uninstall script';
				}
			}
			
			// Rollback migrations
			require_once(PathHelper::getIncludePath('includes/PluginManager.php'));
			$plugin_manager = new PluginManager();
			// Note: Rollback not implemented in consolidated PluginManager
			// For now, just skip rollback
			$rollback_result = array('success' => true, 'errors' => [], 'messages' => []);
			
			if (!$rollback_result['success']) {
				$results['errors'] = array_merge($results['errors'], $rollback_result['errors']);
				$results['warnings'][] = 'Some migrations could not be rolled back';
			} else {
				$results['messages'] = array_merge($results['messages'], $rollback_result['messages']);
			}
			
			// Update plugin record - keep record with 'uninstalled' status
			// so sync won't re-register it automatically
			$this->set('plg_uninstalled_time', date('Y-m-d H:i:s'));
			$this->set('plg_status', 'uninstalled');
			$this->set('plg_activated_time', null);
			$this->set('plg_active', 0);
			$this->save();

			// Clear version tracking using model class
			require_once(PathHelper::getIncludePath('data/plugin_versions_class.php'));
			$plugin_versions = new MultiPluginVersion(['plv_plugin_name' => $plugin_name]);
			$plugin_versions->load();

			foreach ($plugin_versions as $version) {
				$version->permanent_delete();
			}

			// Remove deletion rules for this plugin's models
			$plugin_helper = PluginHelper::getInstance($plugin_name);
			$plugin_helper->removePluginDeletionRules();

			// Remove scheduled tasks that belong to this plugin
			$plugin_tasks_dir = PathHelper::getIncludePath('plugins/' . $plugin_name . '/tasks');
			if (is_dir($plugin_tasks_dir)) {
				$task_jsons = glob($plugin_tasks_dir . '/*.json');
				if (!empty($task_jsons)) {
					require_once(PathHelper::getIncludePath('data/scheduled_tasks_class.php'));
					foreach ($task_jsons as $json_file) {
						$task_class_name = basename($json_file, '.json');
						$existing_tasks = new MultiScheduledTask(array('task_class' => $task_class_name));
						$existing_tasks->load();
						foreach ($existing_tasks as $sct) {
							$sct->permanent_delete();
						}
					}
					$results['messages'][] = 'Removed scheduled tasks';
				}
			}

			$results['success'] = true;
			$results['messages'][] = "Plugin '{$plugin_name}' uninstalled successfully";
			
		} catch (Exception $e) {
			$results['errors'][] = $e->getMessage();
		}
		
		return $results;
	}
	
	/**
	 * Update plugin activation method to use new fields
	 * @return bool Success status
	 */
	public function activate() {
		// Check dependencies before activation
		require_once(PathHelper::getIncludePath('includes/PluginManager.php'));
		$plugin_manager = new PluginManager();
		$dependency_result = $plugin_manager->validatePlugin($this->get('plg_name'));
		
		if (!$dependency_result['valid']) {
			throw new PluginException('Cannot activate plugin: ' . implode('; ', $dependency_result['errors']));
		}
		
		$this->set('plg_activated_time', date('Y-m-d H:i:s'));
		$this->set('plg_last_activated_time', date('Y-m-d H:i:s'));
		$this->set('plg_active', 1);
		$this->set('plg_status', 'active');
		$result = $this->save();
		
		// Clear cache after activation
		self::clear_activation_cache($this->get('plg_name'));
		return $result;
	}
	
	/**
	 * Update plugin deactivation method to use new fields
	 * @return bool Success status
	 */
	public function deactivate() {
		// Check if other plugins depend on this one
		require_once(PathHelper::getIncludePath('includes/PluginManager.php'));
		$plugin_manager = new PluginManager();
		$plugin_name = $this->get('plg_name');
		$dependents = $plugin_manager->getDependents($plugin_name);
		$deactivation_check = array(
			'can_deactivate' => empty($dependents),
			'dependent_plugins' => $dependents
		);
		
		if (!$deactivation_check['can_deactivate']) {
			throw new PluginException('Cannot deactivate plugin. Other plugins depend on it: ' . 
				implode(', ', $deactivation_check['dependent_plugins']));
		}
		
		$this->set('plg_activated_time', null);
		$this->set('plg_last_deactivated_time', date('Y-m-d H:i:s'));
		$this->set('plg_active', 0);
		$this->set('plg_status', 'inactive');
		$result = $this->save();
		
		// Clear cache after deactivation
		self::clear_activation_cache($this->get('plg_name'));
		return $result;
	}
	
	/**
	 * Check if plugin is stock (auto-updated)
	 * @return bool True if stock plugin
	 */
	public function is_stock() {
		return (bool)$this->get('plg_is_stock');
	}

	/**
	 * Permanently delete plugin files from filesystem
	 * Runs uninstall first if not already uninstalled
	 * @return array Results with success, errors, messages
	 */
	public function permanent_delete_with_files() {
		$results = array(
			'success' => false,
			'errors' => array(),
			'messages' => array(),
			'warnings' => array()
		);

		try {
			$plugin_name = $this->get('plg_name');
			$plugin_dir = PathHelper::getAbsolutePath('plugins/' . $plugin_name);

			// Check if this plugin is the active theme provider
			try {
				$plugin_helper = PluginHelper::getInstance($plugin_name);
				if ($plugin_helper->isActiveThemeProvider()) {
					$results['errors'][] = "Cannot delete plugin '$plugin_name' - it is the active theme provider. Switch to a different theme first.";
					return $results;
				}
			} catch (Exception $e) {
				// Plugin helper not available - proceed
			}

			// Pre-flight check: verify we can delete files before making any changes
			if (is_dir($plugin_dir)) {
				$perm_check = LibraryFunctions::check_directory_deletable($plugin_dir);
				if (!$perm_check['can_delete']) {
					$results['errors'][] = "Permission denied. Cannot delete: " . implode(', ', array_slice($perm_check['errors'], 0, 3));
					if (count($perm_check['errors']) > 3) {
						$results['errors'][0] .= ' (and ' . (count($perm_check['errors']) - 3) . ' more)';
					}
					return $results;
				}
			}

			// Run uninstall if not already uninstalled
			if ($this->get('plg_status') !== 'uninstalled') {
				$uninstall_result = $this->uninstall();
				if (!$uninstall_result['success']) {
					$results['errors'] = $uninstall_result['errors'];
					return $results;
				}
				$results['messages'] = array_merge($results['messages'], $uninstall_result['messages']);
			}

			// Delete files
			if (is_dir($plugin_dir)) {
				if (LibraryFunctions::delete_directory($plugin_dir)) {
					$results['messages'][] = "Deleted plugin directory";
				} else {
					$results['errors'][] = "Failed to delete plugin directory";
					return $results;
				}
			} else {
				$results['messages'][] = "Plugin directory already removed";
			}

			// Delete the database record (permanent delete removes the record entirely)
			$this->permanent_delete();
			$results['messages'][] = "Removed plugin from database";
			$results['success'] = true;

		} catch (Exception $e) {
			$results['errors'][] = $e->getMessage();
		}

		return $results;
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
}

class MultiPlugin extends SystemMultiBase {
	protected static $model_class = 'Plugin';

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        // Apply search criteria filters
        foreach ($this->options as $field => $value) {
            if ($value !== null) {
                $filters[$field] = [$value, PDO::PARAM_STR];
            }
        }
        
        return $this->_get_resultsv2('plg_plugins', $filters, $this->order_by, $only_count, $debug);
    }

    /**
     * Get all available plugins from filesystem with their activation status
     * @return array Array of plugin data with activation info
     */
    public static function get_all_plugins_with_status() {
        $plugins_dir = PathHelper::getIncludePath('plugins');
        $plugins = array();
        
        if (!is_dir($plugins_dir)) {
            return $plugins;
        }
        
        // Get all plugin directories
        $plugin_dirs = array_diff(scandir($plugins_dir), array('.', '..'));
        
        // Load all plugin records at once for efficiency
        $all_plugins = new MultiPlugin();
        $all_plugins->load();
        
        // Create lookup array for plugins
        $plugins_lookup = array();
        foreach ($all_plugins as $plugin) {
            $plugins_lookup[$plugin->get('plg_name')] = $plugin;
        }
        
        // Process each plugin directory
        foreach ($plugin_dirs as $plugin_name) {
            $plugin_path = $plugins_dir . '/' . $plugin_name;
            
            if (!is_dir($plugin_path)) {
                continue;
            }
            
            // Skip invalid plugin names
            if (!Plugin::is_valid_plugin_name($plugin_name)) {
                continue;
            }
            
            $plugin_data = array(
                'name' => $plugin_name,
                'directory_exists' => true
            );
            
            // Get plugin record if it exists
            if (isset($plugins_lookup[$plugin_name])) {
                $plugin = $plugins_lookup[$plugin_name];
                $plugin_data['plugin'] = $plugin;
                $plugin_data['is_active'] = $plugin->is_active();
                $plugin_data['status_badge'] = $plugin->get_status_badge();
                $plugin_data['display_name'] = $plugin->get_display_name();
                $plugin_data['description'] = $plugin->get_description();
                $plugin_data['version'] = $plugin->get_version();
                $plugin_data['author'] = $plugin->get_author();
            } else {
                // No plugin record - plugin is inactive
                $plugin_data['plugin'] = null;
                $plugin_data['is_active'] = false;
                $plugin_data['status_badge'] = '<span class="badge bg-secondary">Inactive</span>';
                
                // Try to get metadata directly
                $metadata_file = $plugin_path . '/plugin.json';
                if (file_exists($metadata_file)) {
                    $json_data = file_get_contents($metadata_file);
                    $metadata = json_decode($json_data, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $plugin_data['display_name'] = isset($metadata['name']) ? $metadata['name'] : $plugin_name;
                        $plugin_data['description'] = isset($metadata['description']) ? $metadata['description'] : null;
                        $plugin_data['version'] = isset($metadata['version']) ? $metadata['version'] : null;
                        $plugin_data['author'] = isset($metadata['author']) ? $metadata['author'] : null;
                    } else {
                        $plugin_data['display_name'] = $plugin_name;
                        $plugin_data['description'] = null;
                        $plugin_data['version'] = null;
                        $plugin_data['author'] = null;
                    }
                } else {
                    $plugin_data['display_name'] = $plugin_name;
                    $plugin_data['description'] = null;
                    $plugin_data['version'] = null;
                    $plugin_data['author'] = null;
                }
            }
            
            $plugins[] = $plugin_data;
        }
        
        // Check for orphaned database records (plugins in DB but not on filesystem)
        foreach ($plugins_lookup as $plugin_name => $plugin) {
            $plugin_path = $plugins_dir . '/' . $plugin_name;
            if (!is_dir($plugin_path)) {
                $plugin_data = array(
                    'name' => $plugin_name,
                    'directory_exists' => false,
                    'plugin' => $plugin,
                    'is_active' => $plugin->is_active(),
                    'status_badge' => '<span class="badge bg-warning">Missing</span>',
                    'display_name' => $plugin->get_display_name(),
                    'description' => 'Plugin directory not found',
                    'version' => null,
                    'author' => null
                );
                $plugins[] = $plugin_data;
            }
        }
        
        // Sort plugins by display name
        usort($plugins, function($a, $b) {
            return strcasecmp($a['display_name'], $b['display_name']);
        });
        
        return $plugins;
    }
}

?>
