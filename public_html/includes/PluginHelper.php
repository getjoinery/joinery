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
        // SAFETY: Handle case where plg_plugins table doesn't exist yet (during initial setup)
        try {
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
        } catch (PDOException $e) {
            // Table doesn't exist yet (likely during initial database setup)
            // Skip database update during migration phase - plugin will be activated later
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
        // SAFETY: Handle case where plg_plugins table doesn't exist yet (during initial setup)
        try {
            $dbconnector = DbConnector::get_instance();
            $dblink = $dbconnector->get_db_link();
            
            $sql = "UPDATE plg_plugins SET plg_active = 0, plg_deactivated_date = NOW() WHERE plg_name = ?";
            $q = $dblink->prepare($sql);
            $q->execute([$this->name]);
        } catch (PDOException $e) {
            // Table doesn't exist yet (likely during initial database setup)
            // Skip database update during migration phase
        }
        
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
        // Explicitly prevent plugins from providing theme functionality
        if ($feature === 'theme') {
            return false;
        }
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
     * Get plugin display name for UI
     */
    public function getPluginName() {
        // Use plugin's display name from manifest, or plugin directory name
        return $this->get('name', $this->name);
    }
    
    /**
     * Get plugin description for UI
     */
    public function getPluginDescription() {
        // Use plugin description if available
        return $this->get('description', '');
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
     * Get a service instance from a plugin
     * Services provide business logic without UI dependencies
     */
    public static function getService($plugin_name, $service_name = null) {
        $service_name = $service_name ?: 'Service';
        $service_file = "plugins/{$plugin_name}/services/{$service_name}.php";
        
        if (file_exists(PathHelper::getIncludePath($service_file))) {
            PathHelper::requireOnce($service_file);
            $class_name = ucfirst($plugin_name) . $service_name;
            if (class_exists($class_name)) {
                return new $class_name();
            }
        }
        
        return null;
    }
    
    /**
     * Check if a plugin provides a specific service
     */
    public static function hasService($plugin_name, $service_name = null) {
        $service_name = $service_name ?: 'Service';
        $service_file = "plugins/{$plugin_name}/services/{$service_name}.php";
        return file_exists(PathHelper::getIncludePath($service_file));
    }
    
    /**
     * Get all plugins of a specific type
     */
    public static function getByType($type) {
        $plugins = [];
        foreach (self::getAvailablePlugins() as $name => $plugin) {
            $metadata = $plugin->getMetadata();
            if (isset($metadata['type']) && $metadata['type'] === $type) {
                $plugins[$name] = $plugin;
            }
        }
        return $plugins;
    }
    
    /**
     * Check if plugin provides theme functionality
     * @return bool
     */
    public function providesTheme() {
        $metadata = $this->getMetadata();
        return isset($metadata['provides_theme']) && $metadata['provides_theme'] === true;
    }
    
    /**
     * Validate if plugin can serve as a theme provider
     * @return array ['valid' => bool, 'errors' => array, 'warnings' => array]
     */
    public function validateAsThemeProvider() {
        $plugin_dir = PathHelper::getIncludePath("plugins/{$this->name}");
        $errors = [];
        $warnings = [];
        
        // Check for mandatory plugin.json
        if (!$this->metadataExists()) {
            $errors[] = "Missing required file: plugin.json - Plugin metadata is mandatory for theme providers";
        } else {
            // Check for provides_theme flag
            $metadata = $this->getMetadata();
            if (!isset($metadata['provides_theme']) || $metadata['provides_theme'] !== true) {
                $errors[] = "plugin.json must have 'provides_theme': true to serve as theme provider";
            }
        }
        
        // Check required files
        $required_files = [
            'includes/PublicPage.php' => 'PublicPage class is required for theme functionality',
            'includes/FormWriter.php' => 'FormWriter class is required for form generation'
        ];
        
        foreach ($required_files as $file => $error_message) {
            $file_path = $plugin_dir . '/' . $file;
            if (!file_exists($file_path)) {
                $errors[] = "Missing required file: {$file} - {$error_message}";
            }
        }
        
        // Check for main route in serve.php
        $serve_file = $plugin_dir . '/serve.php';
        if (file_exists($serve_file)) {
            $serve_content = file_get_contents($serve_file);
            
            // Check for main plugin route
            if (!preg_match("/['\"]\/?" . preg_quote($this->name, '/') . "['\"]\\s*=>/", $serve_content)) {
                $warnings[] = "No main route '/{$this->name}' found in serve.php";
            }
            
            // Check for restricted routes
            $restricted_routes = ['/login', '/logout', '/register'];
            foreach ($restricted_routes as $route) {
                if (preg_match("/['\"]" . preg_quote($route, '/') . "['\"]\\s*=>/", $serve_content)) {
                    $errors[] = "Plugin cannot define system route: {$route}";
                }
            }
        } else {
            $warnings[] = "No serve.php file found - plugin may not define any routes";
        }
        
        // Check for recommended files
        $recommended_files = [
            'views/index.php' => 'Homepage view recommended',
            'assets/css/style.css' => 'Theme styles recommended'
        ];
        
        foreach ($recommended_files as $file => $message) {
            $file_path = $plugin_dir . '/' . $file;
            if (!file_exists($file_path)) {
                $warnings[] = "Missing recommended file: {$file} - {$message}";
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
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
    
    /**
     * Get all plugins that can provide theme functionality
     * @return array Array of PluginHelper instances that are valid theme providers
     */
    public static function getValidThemeProviders() {
        $all_plugins = self::getAvailablePlugins();
        $theme_providers = [];
        
        foreach ($all_plugins as $plugin_name => $plugin_helper) {
            $validation = $plugin_helper->validateAsThemeProvider();
            if ($validation['valid']) {
                $theme_providers[$plugin_name] = $plugin_helper;
            }
        }
        
        return $theme_providers;
    }
}