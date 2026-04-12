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

			// Uninstall if not already done
			if ($this->get('plg_status') !== 'uninstalled') {
				require_once(PathHelper::getIncludePath('includes/PluginManager.php'));
				$plugin_manager = new PluginManager();
				try {
					$plugin_manager->uninstall($plugin_name);
					$results['messages'][] = "Plugin uninstalled";
				} catch (Exception $uninstall_error) {
					$results['errors'][] = $uninstall_error->getMessage();
					return $results;
				}
			}

			// Drop plugin database tables BEFORE deleting files (needs class definitions)
			require_once(PathHelper::getIncludePath('includes/PluginManager.php'));
			$plugin_manager = new PluginManager();
			if (is_dir($plugin_dir)) {
				$plugin_manager->permanentDeleteTables($plugin_name);
				$results['messages'][] = "Dropped plugin database tables";
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
                'directory_exists' => true,
                'deprecated' => false,
                'superseded_by' => null,
            );

            // Read manifest for deprecation metadata
            $metadata_file = $plugin_path . '/plugin.json';
            $metadata = null;
            if (file_exists($metadata_file)) {
                $metadata = json_decode(file_get_contents($metadata_file), true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $metadata = null;
                }
            }

            if ($metadata) {
                $plugin_data['deprecated'] = !empty($metadata['deprecated']);
                $plugin_data['superseded_by'] = $metadata['superseded_by'] ?? null;
                $plugin_data['requires_joinery'] = $metadata['requires']['joinery'] ?? null;
            } else {
                $plugin_data['requires_joinery'] = null;
            }

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

                if ($metadata) {
                    $plugin_data['display_name'] = $metadata['name'] ?? $plugin_name;
                    $plugin_data['description'] = $metadata['description'] ?? null;
                    $plugin_data['version'] = $metadata['version'] ?? null;
                    $plugin_data['author'] = $metadata['author'] ?? null;
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
                    'deprecated' => false,
                    'superseded_by' => null,
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
        
        // Sort plugins: deprecated last, then alphabetical
        usort($plugins, function($a, $b) {
            $a_dep = !empty($a['deprecated']);
            $b_dep = !empty($b['deprecated']);
            if ($a_dep !== $b_dep) return $a_dep ? 1 : -1;
            return strcasecmp($a['display_name'], $b['display_name']);
        });
        
        return $plugins;
    }
}

?>
