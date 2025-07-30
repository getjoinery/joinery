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
		'plg_name' => array('type'=>'varchar(128)'),
		'plg_activated_time' => array('type'=>'timestamp(6)'),
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
		if ($this->is_active()) {
			return '<span class="badge bg-success">Active</span>';
		} else {
			return '<span class="badge bg-secondary">Inactive</span>';
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
	 * Activate plugin (override to clear cache)
	 * @return bool Success status
	 */
	public function activate() {
		$this->set('plg_activated_time', date('Y-m-d H:i:s'));
		$result = $this->save();
		// Clear cache after activation
		self::clear_activation_cache($this->get('plg_name'));
		return $result;
	}
	
	/**
	 * Deactivate plugin (override to clear cache)
	 * @return bool Success status
	 */
	public function deactivate() {
		$this->set('plg_activated_time', null);
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
