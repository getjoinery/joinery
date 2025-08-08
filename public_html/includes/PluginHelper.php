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
     * Initialize plugin
     */
    public function initialize() {
        // Only initialize if plugin is active
        if (!$this->isActive()) {
            return false;
        }
        
        // Load plugin initialization file if it exists
        $initFile = $this->getIncludePath('init.php');
        if (file_exists($initFile)) {
            require_once($initFile);
        }
        
        // Load plugin functions file if it exists
        $functionsFile = $this->getIncludePath('functions.php');
        if (file_exists($functionsFile)) {
            require_once($functionsFile);
        }
        
        // Register plugin routes if custom routing exists
        if ($this->hasCustomRouting()) {
            $this->registerRoutes();
        }
        
        // Register admin menu items
        if ($this->hasAdminInterface()) {
            $this->registerAdminMenu();
        }
        
        // Run plugin migrations if needed
        if ($this->hasMigrations()) {
            $this->checkMigrations();
        }
        
        return true;
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
     * Register admin menu items
     */
    private function registerAdminMenu() {
        $menuItems = $this->getAdminMenuItems();
        
        if (!empty($menuItems) && class_exists('AdminMenuRegistry')) {
            foreach ($menuItems as $item) {
                AdminMenuRegistry::addMenuItem(
                    $item['title'] ?? '',
                    $item['url'] ?? '',
                    $item['permission'] ?? 5,
                    $item['icon'] ?? '',
                    $item['parent'] ?? null
                );
            }
        }
    }
    
    /**
     * Check and run pending migrations
     */
    private function checkMigrations() {
        $migrationsFile = $this->getMigrationsPath();
        if (file_exists($migrationsFile)) {
            // This would integrate with the migration system
            // The actual migration runner would handle this
            if (class_exists('MigrationRunner')) {
                MigrationRunner::registerPluginMigrations($this->name, $migrationsFile);
            }
        }
    }
    
    /**
     * Check if plugin is currently active
     */
    public function isActive() {
        // Check database for plugin activation status
        $settings = Globalvars::get_instance();
        
        // First check individual plugin setting
        $pluginActive = $settings->get_setting("plugin_{$this->name}_active", true, true);
        if ($pluginActive !== null) {
            return (bool)$pluginActive;
        }
        
        // Check active_plugins array
        $activePlugins = $settings->get_setting('active_plugins', true, true);
        if (is_array($activePlugins)) {
            return in_array($this->name, $activePlugins);
        }
        
        // Check if plugin has activation record in database
        $dbconnector = DbConnector::get_instance();
        $dblink = $dbconnector->get_db_link();
        
        $sql = "SELECT COUNT(*) as count FROM plg_plugins WHERE plg_name = ? AND plg_active = 1";
        $q = $dblink->prepare($sql);
        $q->execute([$this->name]);
        $result = $q->fetch(PDO::FETCH_ASSOC);
        
        return ($result['count'] > 0);
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
     * Activate plugin
     */
    public function activate() {
        if ($this->isActive()) {
            return true; // Already active
        }
        
        // Run activation hook if exists
        $activateFile = $this->getIncludePath('activate.php');
        if (file_exists($activateFile)) {
            require_once($activateFile);
        }
        
        // Update database
        $dbconnector = DbConnector::get_instance();
        $dblink = $dbconnector->get_db_link();
        
        // Check if plugin record exists
        $sql = "SELECT plg_id FROM plg_plugins WHERE plg_name = ?";
        $q = $dblink->prepare($sql);
        $q->execute([$this->name]);
        $existing = $q->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            // Update existing record
            $sql = "UPDATE plg_plugins SET plg_active = 1, plg_activated_date = NOW() WHERE plg_name = ?";
            $q = $dblink->prepare($sql);
            $q->execute([$this->name]);
        } else {
            // Insert new record
            $sql = "INSERT INTO plg_plugins (plg_name, plg_active, plg_activated_date, plg_version) VALUES (?, 1, NOW(), ?)";
            $q = $dblink->prepare($sql);
            $q->execute([$this->name, $this->getVersion()]);
        }
        
        // Clear any cached plugin states
        $settings = Globalvars::get_instance();
        $settings->set_setting("plugin_{$this->name}_active", 1);
        
        return true;
    }
    
    /**
     * Deactivate plugin
     */
    public function deactivate() {
        if (!$this->isActive()) {
            return true; // Already inactive
        }
        
        // Run deactivation hook if exists
        $deactivateFile = $this->getIncludePath('deactivate.php');
        if (file_exists($deactivateFile)) {
            require_once($deactivateFile);
        }
        
        // Update database
        $dbconnector = DbConnector::get_instance();
        $dblink = $dbconnector->get_db_link();
        
        $sql = "UPDATE plg_plugins SET plg_active = 0, plg_deactivated_date = NOW() WHERE plg_name = ?";
        $q = $dblink->prepare($sql);
        $q->execute([$this->name]);
        
        // Clear cached plugin state
        $settings = Globalvars::get_instance();
        $settings->set_setting("plugin_{$this->name}_active", 0);
        
        return true;
    }
    
    // Plugin-specific getters
    
    /**
     * Check if plugin has admin interface
     */
    public function hasAdminInterface() {
        return is_dir($this->getIncludePath('adm'));
    }
    
    /**
     * Check if plugin has custom routing
     */
    public function hasCustomRouting() {
        return file_exists($this->getIncludePath('serve.php'));
    }
    
    /**
     * Get plugin admin menu items
     */
    public function getAdminMenuItems() {
        return $this->manifestData['adminMenu'] ?? [];
    }
    
    /**
     * Get plugin API endpoints
     */
    public function getApiEndpoints() {
        return $this->manifestData['apiEndpoints'] ?? [];
    }
    
    /**
     * Check if plugin has migrations
     */
    public function hasMigrations() {
        return file_exists($this->getIncludePath('migrations/migrations.php'));
    }
    
    /**
     * Get plugin migration file path
     */
    public function getMigrationsPath() {
        return $this->getIncludePath('migrations/migrations.php');
    }
    
    /**
     * Get all plugin data models
     */
    public function getDataModels() {
        $models = [];
        $dataDir = $this->getIncludePath('data');
        
        if (is_dir($dataDir)) {
            $files = glob($dataDir . '/*_class.php');
            foreach ($files as $file) {
                $className = str_replace('_class.php', '', basename($file));
                $models[] = $className;
            }
        }
        
        return $models;
    }
    
    /**
     * Check if plugin provides a specific feature
     */
    public function providesFeature($feature) {
        $provides = $this->manifestData['provides'] ?? [];
        return in_array($feature, $provides);
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
     * Initialize all active plugins
     */
    public static function initializeActive() {
        $results = ['success' => [], 'failed' => []];
        $activePlugins = self::getActivePlugins();
        
        foreach ($activePlugins as $name => $plugin) {
            try {
                $plugin->initialize();
                $results['success'][$name] = 'Plugin initialized';
            } catch (Exception $e) {
                $results['failed'][$name] = $e->getMessage();
                error_log("Failed to initialize plugin '{$name}': " . $e->getMessage());
            }
        }
        
        return $results;
    }
    
    /**
     * Validate all available plugins
     */
    public static function validateAll() {
        $results = [];
        $plugins = self::getAvailablePlugins();
        
        foreach ($plugins as $name => $plugin) {
            $validation = $plugin->validate();
            $results[$name] = [
                'valid' => $validation === true,
                'errors' => $validation === true ? [] : $validation
            ];
        }
        
        return $results;
    }
    
    /**
     * Activate a plugin (static convenience method)
     */
    public static function activatePlugin($pluginName) {
        $plugin = self::getInstance($pluginName);
        
        // Validate before activation
        $validation = $plugin->validate();
        if ($validation !== true) {
            throw new Exception("Plugin validation failed: " . implode(', ', $validation));
        }
        
        return $plugin->activate();
    }
    
    /**
     * Deactivate a plugin (static convenience method)
     */
    public static function deactivatePlugin($pluginName) {
        $plugin = self::getInstance($pluginName);
        return $plugin->deactivate();
    }
}