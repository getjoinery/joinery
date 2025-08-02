<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SystemClass.php');
	

class PluginException extends SystemClassException {}
class PluginNotSentException extends PluginException {};

class Plugin extends SystemBase {
	public static $prefix = 'plg';
	public static $tablename = 'plg_plugins';
	public static $pkey_column = 'plg_plugin_id';
	public static $permanent_delete_actions = array(
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value	

	public static $fields = array(
		'plg_plugin_id' => 'Primary key - Plugin ID',
		'plg_name' => 'Name of the plugin',
		'plg_activated_time' => 'Activation time',
		'plg_active' => 'Active status (1/0)',
		'plg_installed_time' => 'Installation time',
		'plg_last_activated_time' => 'Last activation time',
		'plg_last_deactivated_time' => 'Last deactivation time',
		'plg_uninstalled_time' => 'Uninstall time',
		'plg_status' => 'Plugin status (installed/active/inactive/error)',
		'plg_install_error' => 'Installation error message',
		'plg_metadata' => 'Plugin metadata JSON',
	);

	/**
	 * Field specifications define database column properties and schema constraints
	 * Available options:
	 *   'type' => 'varchar(255)' | 'int4' | 'int8' | 'text' | 'timestamp(6)' | 'numeric(10,2)' | 'bool' | etc.
	 *   'serial' => true/false - Auto-incrementing field
	 *   'is_nullable' => true/false - Whether NULL values are allowed
	 *   'unique' => true - Field must be unique (single field constraint)
	 *   'unique_with' => array('field1', 'field2') - Composite unique constraint with other fields
	 */
	public static $field_specifications = array(
		'plg_plugin_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'plg_name' => array('type'=>'varchar(128)', 'unique'=>true),
		'plg_activated_time' => array('type'=>'timestamp(6)'),
		'plg_active' => array('type'=>'int4', 'is_nullable'=>true),
		'plg_installed_time' => array('type'=>'timestamp(6)'),
		'plg_last_activated_time' => array('type'=>'timestamp(6)'),
		'plg_last_deactivated_time' => array('type'=>'timestamp(6)'),
		'plg_uninstalled_time' => array('type'=>'timestamp(6)'),
		'plg_status' => array('type'=>'varchar(20)'),
		'plg_install_error' => array('type'=>'text'),
		'plg_metadata' => array('type'=>'text'),
	);

	public static $required_fields = array('plg_name');

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $initial_default_values = array();	

	
	
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
				return '<span class="badge bg-secondary">Not Installed</span>';
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
		
		$plugin_dir = $_SERVER['DOCUMENT_ROOT'] . '/plugins/' . $plugin_name;
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
		
		$metadata_file = $_SERVER['DOCUMENT_ROOT'] . '/plugins/' . $plugin_name . '/plugin.json';
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
			PathHelper::requireOnce('includes/PluginManager.php');
			$dependency_validator = new PluginDependencyValidator();
			$dependency_result = $dependency_validator->validate($plugin_name);
			
			if (!$dependency_result['valid']) {
				$results['errors'] = $dependency_result['errors'];
				return $results;
			}
			
			// Create plugin tables first
			PathHelper::requireOnce('includes/DatabaseUpdater.php');
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
			PathHelper::requireOnce('includes/PluginManager.php');
			$migration_runner = new PluginMigrationRunner($plugin_name);
			$migration_result = $migration_runner->migrate();
			
			if (!$migration_result['success']) {
				$results['errors'] = $migration_result['errors'];
				$this->set('plg_install_error', implode('; ', $migration_result['errors']));
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
			
			// Mark version as installed
			PathHelper::requireOnce('includes/PluginManager.php');
			$version_detector = new PluginVersionDetector();
			$version = $metadata['version'] ?? '0.0.0';
			$version_detector->markAsUpdated($plugin_name, $version);
			
			$results['success'] = true;
			$results['messages'][] = "Plugin '{$plugin_name}' installed successfully";
			$results['messages'] = array_merge($results['messages'], $migration_result['messages']);
			
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
			PathHelper::requireOnce('includes/PluginManager.php');
			$dependency_validator = new PluginDependencyValidator();
			$deactivation_check = $dependency_validator->checkDeactivation($plugin_name);
			
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
			PathHelper::requireOnce('includes/PluginManager.php');
			$migration_runner = new PluginMigrationRunner($plugin_name);
			$rollback_result = $migration_runner->rollback();
			
			if (!$rollback_result['success']) {
				$results['errors'] = array_merge($results['errors'], $rollback_result['errors']);
				$results['warnings'][] = 'Some migrations could not be rolled back';
			} else {
				$results['messages'] = array_merge($results['messages'], $rollback_result['messages']);
			}
			
			// Update plugin record
			$this->set('plg_uninstalled_time', date('Y-m-d H:i:s'));
			$this->set('plg_status', 'uninstalled');
			$this->save();
			
			// Clear version tracking
			$sql = "DELETE FROM plv_plugin_versions WHERE plv_plugin_name = ?";
			$dbconnector = DbConnector::get_instance();
			$dblink = $dbconnector->get_db_link();
			$q = $dblink->prepare($sql);
			$q->execute([$plugin_name]);
			
			// Delete plugin record
			$this->permanent_delete();
			
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
		PathHelper::requireOnce('includes/PluginManager.php');
		$dependency_validator = new PluginDependencyValidator();
		$dependency_result = $dependency_validator->validate($this->get('plg_name'));
		
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
		PathHelper::requireOnce('includes/PluginManager.php');
		$dependency_validator = new PluginDependencyValidator();
		$deactivation_check = $dependency_validator->checkDeactivation($this->get('plg_name'));
		
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
}

class MultiPlugin extends SystemMultiBase {

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        

        
        return $this->_get_resultsv2('plg_plugins', $filters, $this->order_by, $only_count, $debug);
    }
    
    function load($debug = false) {
        parent::load();
        $q = $this->getMultiResults(false, $debug);
        foreach($q->fetchAll() as $row) {
            $child = new Plugin($row->plg_plugin_id);
            $child->load_from_data($row, array_keys(Plugin::$fields));
            $this->add($child);
        }
    }
    
    function count_all($debug = false) {
        $q = $this->getMultiResults(TRUE, $debug);
        return $q;
    }

    /**
     * Get all available plugins from filesystem with their activation status
     * @return array Array of plugin data with activation info
     */
    public static function get_all_plugins_with_status() {
        $plugins_dir = $_SERVER['DOCUMENT_ROOT'] . '/plugins';
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
