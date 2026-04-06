<?php
require_once(__DIR__ . '/ComponentBase.php');

/**
 * PluginHelper - Manages plugin metadata and provides helper functions
 * Extends ComponentBase for common functionality
 */
class PluginHelper extends ComponentBase {
    protected $componentType = 'plugin';
    
    private static $instances = [];
    
    /**
     * Private constructor for singleton pattern
     */
    private function __construct($pluginName) {
        $this->name = $pluginName;
        $this->basePath = "plugins/{$pluginName}";
        $this->manifestPath = PathHelper::getIncludePath("{$this->basePath}/plugin.json");
        
        // Load manifest (will throw exception if not found/invalid)
        $this->loadManifest();
    }
    
    /**
     * Get PluginHelper instance for a plugin (singleton pattern)
     */
    public static function getInstance($pluginName) {
        if (!$pluginName) {
            throw new Exception("Plugin name is required");
        }
        
        if (!isset(self::$instances[$pluginName])) {
            self::$instances[$pluginName] = new self($pluginName);
        }
        
        return self::$instances[$pluginName];
    }
    
    /**
     * Register plugin routes with the system
     */
    private function registerRoutes() {
        // This would integrate with serve.php routing system
        // Store plugin routes for processing in main routing logic
        if (class_exists('RouteRegistry')) {
            RouteRegistry::registerPlugin($this->name, $this->getIncludePath('serve.php'));
        }
    }
    
    /**
     * Check if plugin is currently active
     */
    protected function isActive() {
        $settings = Globalvars::get_instance();

        // If this plugin is the active theme provider, it's always active
        $theme_template = $settings->get_setting('theme_template');
        $active_plugin = $settings->get_setting('active_theme_plugin');
        if ($theme_template === 'plugin' && $active_plugin === $this->name) {
            return true;
        }

        // Check plg_plugins table for activation status
        // SAFETY: Handle case where plg_plugins table doesn't exist yet (during initial setup/migrations)
        try {
            $dbconnector = DbConnector::get_instance();
            $dblink = $dbconnector->get_db_link();

            $sql = "SELECT COUNT(*) as count FROM plg_plugins WHERE plg_name = ? AND plg_active = 1";
            $q = $dblink->prepare($sql);
            $q->execute([$this->name]);
            $result = $q->fetch(PDO::FETCH_ASSOC);

            return ($result['count'] > 0);
        } catch (PDOException $e) {
            // Table doesn't exist yet (likely during initial database setup)
            // Return false to indicate plugin is not active during migration phase
            return false;
        }
    }
    
    /**
     * Validate plugin structure and requirements
     */
    public function validate() {
        $errors = [];
        
        // Check requirements
        $reqCheck = $this->checkRequirements();
        if ($reqCheck !== true) {
            $errors = array_merge($errors, $reqCheck);
        }
        
        // Check for plugin directory
        if (!is_dir(PathHelper::getIncludePath($this->basePath))) {
            $errors[] = "Plugin directory not found: {$this->basePath}";
        }
        
        // Manifest is mandatory and already validated in loadManifest()
        
        // Check for required manifest fields
        if (empty($this->manifestData['name'])) {
            $errors[] = "Plugin manifest missing required field: name";
        }
        
        // Check that plugin doesn't claim to provide theme functionality
        if (isset($this->manifestData['provides']) && in_array('theme', $this->manifestData['provides'])) {
            $errors[] = "Plugins cannot provide theme functionality. Use a separate theme in /theme/ directory.";
        }
        
        // Validate admin menu items if present
        $menuItems = $this->getAdminMenuItems();
        foreach ($menuItems as $index => $item) {
            if (empty($item['title']) || empty($item['url'])) {
                $errors[] = "Admin menu item {$index} missing required fields (title, url)";
            }
        }
        
        // Validate API endpoints if present
        $endpoints = $this->getApiEndpoints();
        foreach ($endpoints as $index => $endpoint) {
            if (empty($endpoint['path']) || empty($endpoint['method'])) {
                $errors[] = "API endpoint {$index} missing required fields (path, method)";
            }
        }
        
        return empty($errors) ? true : $errors;
    }
    
    /**
     * Remove all deletion rules for this plugin's models
     * Public so it can be called during plugin uninstall
     */
    public function removePluginDeletionRules() {
        require_once(PathHelper::getIncludePath('data/deletion_rule_class.php'));

        try {
            // Get all model files for this plugin
            $plugin_data_dir = $this->getIncludePath('data');
            if (!is_dir($plugin_data_dir)) {
                return; // No data models to clean up
            }

            $db = DbConnector::get_instance()->get_db_link();

            // Find all *_class.php files in plugin's data directory
            $files = glob($plugin_data_dir . '/*_class.php');
            foreach ($files as $file) {
                require_once($file);

                // Extract class name from filename
                $basename = basename($file, '.php');
                $class_name = str_replace('_class', '', $basename);
                $class_name = implode('', array_map('ucfirst', explode('_', $class_name)));

                // Check if class exists and has tablename
                if (class_exists($class_name)) {
                    $reflection = new ReflectionClass($class_name);
                    if ($reflection->hasProperty('tablename')) {
                        $table = $reflection->getStaticPropertyValue('tablename');

                        // Delete all rules where this table is the target
                        $stmt = $db->prepare("DELETE FROM del_deletion_rules WHERE del_target_table = ?");
                        $stmt->execute([$table]);
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Failed to remove deletion rules for plugin {$this->name}: " . $e->getMessage());
        }
    }

    /**
     * Register deletion rules for all active plugins
     * This is useful when rebuilding the deletion rules system
     */
    public static function registerAllActiveDeletionRules() {
        require_once(PathHelper::getIncludePath('data/deletion_rule_class.php'));
        require_once(PathHelper::getIncludePath('data/plugins_class.php'));

        // Get all active plugins
        $plugins = new MultiPlugin(['plg_active' => 1]);
        $plugins->load();

        foreach ($plugins as $plugin) {
            $plugin_name = $plugin->get('plg_name');
            try {
                DeletionRule::registerModelsFromDiscovery([
                    'include_plugins' => true,
                    'plugin_filter' => $plugin_name
                ]);
            } catch (Exception $e) {
                error_log("Failed to register deletion rules for plugin {$plugin_name}: " . $e->getMessage());
            }
        }
    }

    // Plugin-specific getters

    /**
     * Get plugin admin menu items
     */
    protected function getAdminMenuItems() {
        return $this->manifestData['adminMenu'] ?? [];
    }
    
    /**
     * Get plugin API endpoints
     */
    protected function getApiEndpoints() {
        return $this->manifestData['apiEndpoints'] ?? [];
    }
    
    /**
     * Get all available plugins with their helpers
     */
    public static function getAvailablePlugins() {
        $plugins = [];
        $pluginDir = PathHelper::getIncludePath('plugins');
        
        if (is_dir($pluginDir)) {
            $directories = glob($pluginDir . '/*', GLOB_ONLYDIR);
            foreach ($directories as $dir) {
                $pluginName = basename($dir);
                try {
                    $plugins[$pluginName] = self::getInstance($pluginName);
                } catch (Exception $e) {
                    // Skip plugins without valid manifests
                    error_log("Plugin {$pluginName} skipped: " . $e->getMessage());
                    continue;
                }
            }
        }
        
        return $plugins;
    }
    
    /**
     * Get all active plugins
     */
    public static function getActivePlugins() {
        $activePlugins = [];
        $allPlugins = self::getAvailablePlugins();
        
        foreach ($allPlugins as $name => $plugin) {
            if ($plugin->isActive()) {
                $activePlugins[$name] = $plugin;
            }
        }
        
        return $activePlugins;
    }
    
    /**
     * Get plugin display name for UI
     */
    public function getPluginName() {
        // Use plugin's display name from manifest, or plugin directory name
        return $this->get('name', $this->name);
    }
    
    /**
     * Check if a plugin is active (static convenience method)
     */
    public static function isPluginActive($pluginName) {
        try {
            $plugin = self::getInstance($pluginName);
            return $plugin->isActive();
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Check if this plugin is currently the active theme provider
     * @return bool
     */
    public function isActiveThemeProvider() {
        $settings = Globalvars::get_instance();
        $theme_template = $settings->get_setting('theme_template');
        $active_plugin = $settings->get_setting('active_theme_plugin');
        
        return $theme_template === 'plugin' && $active_plugin === $this->name;
    }
    
}