<?php
require_once(__DIR__ . '/ComponentBase.php');

/**
 * ThemeHelper - Manages theme metadata and provides helper functions
 * Extends ComponentBase for common functionality
 */
class ThemeHelper extends ComponentBase {
    protected $componentType = 'theme';
    
    private static $instances = [];
    
    /**
     * Private constructor for singleton pattern
     */
    private function __construct($themeName) {
        $this->name = $themeName;
        $this->basePath = "theme/{$themeName}";
        $this->manifestPath = PathHelper::getIncludePath("{$this->basePath}/theme.json");
        
        // Load manifest (will throw exception if not found/invalid)
        $this->loadManifest();
    }
    
    /**
     * Get ThemeHelper instance for a theme (singleton pattern)
     */
    public static function getInstance($themeName = null) {
        // Use current theme if not specified
        if (!$themeName) {
            $settings = Globalvars::get_instance();
            $themeName = $settings->get_setting('theme_template', true, true);
            
            if (!$themeName) {
                throw new Exception("No theme specified and no default theme configured");
            }
        }
        
        // Return cached instance or create new one
        if (!isset(self::$instances[$themeName])) {
            self::$instances[$themeName] = new self($themeName);
        }
        
        return self::$instances[$themeName];
    }
    
    /**
     * Initialize theme
     */
    public function initialize() {
        // Load theme functions file if it exists
        $functionsFile = $this->getIncludePath('functions.php');
        if (file_exists($functionsFile)) {
            require_once($functionsFile);
        }
        
        // Register theme with system
        $this->registerTheme();
        
        return true;
    }
    
    /**
     * Register theme with the system
     */
    private function registerTheme() {
        // Hook into system if needed
        // This is where theme-specific initialization would happen
        
        // Example: Register theme support features
        if (method_exists('SystemHooks', 'registerThemeSupport')) {
            SystemHooks::registerThemeSupport($this->name, $this->manifestData);
        }
    }
    
    /**
     * Check if theme is currently active
     */
    public function isActive() {
        $settings = Globalvars::get_instance();
        $activeTheme = $settings->get_setting('theme_template', true, true);
        return $this->name === $activeTheme;
    }
    
    /**
     * Validate theme structure and requirements
     */
    public function validate() {
        $errors = [];
        
        // Check requirements
        $reqCheck = $this->checkRequirements();
        if ($reqCheck !== true) {
            $errors = array_merge($errors, $reqCheck);
        }
        
        // Check for theme directory
        if (!is_dir(PathHelper::getIncludePath($this->basePath))) {
            $errors[] = "Theme directory not found: {$this->basePath}";
        }
        
        // Manifest is mandatory and already validated in loadManifest()
        // If we got here, manifest exists and is valid
        
        // Check for required manifest fields
        if (empty($this->manifestData['name'])) {
            $errors[] = "Theme manifest missing required field: name";
        }
        
        // Check FormWriter base class if specified
        if ($formWriterBase = $this->getFormWriterBase()) {
            $classFile = PathHelper::getIncludePath("includes/{$formWriterBase}.php");
            if (!file_exists($classFile)) {
                $errors[] = "FormWriter base class file not found: {$formWriterBase}.php";
            }
        }
        
        return empty($errors) ? true : $errors;
    }
    
    /**
     * Get CSS framework used by theme
     */
    public function getCssFramework() {
        return $this->manifestData['cssFramework'] ?? null;
    }
    
    /**
     * Get FormWriter base class for theme
     */
    public function getFormWriterBase() {
        return $this->manifestData['formWriterBase'] ?? null;
    }
    
    /**
     * Get PublicPage base class for theme
     */
    public function getPublicPageBase() {
        return $this->manifestData['publicPageBase'] ?? null;
    }
    
    // === STATIC HELPER METHODS ===
    // These provide convenient access without needing the instance
    
    /**
     * Get URL to theme asset with plugin fallback
     */
    public static function asset($path, $themeName = null) {
        // If specific theme requested, load from that theme directory
        if ($themeName !== null) {
            $theme_asset = "/theme/{$themeName}/assets/{$path}";
            $theme_asset_path = PathHelper::getIncludePath("theme/{$themeName}/assets/{$path}");
            if (file_exists($theme_asset_path)) {
                return $theme_asset;
            }
        } else {
            // No specific theme - use the currently active theme (regular or plugin)
            try {
                // Get the active theme directory using centralized method
                $theme_dir = PathHelper::getActiveThemeDirectory();
                $asset_path = "/{$theme_dir}/assets/{$path}";
                $asset_full_path = PathHelper::getIncludePath("{$theme_dir}/assets/{$path}");
                
                if (file_exists($asset_full_path)) {
                    return $asset_path;
                }
            } catch (Exception $e) {
                // Log error but don't throw - fall through to existing fallback logic
                error_log("Asset loading error: " . $e->getMessage());
            }
        }
        
        // Check current plugin (existing fallback behavior)
        $current_plugin = RouteHelper::getCurrentPlugin();
        if ($current_plugin) {
            $plugin_asset = "/plugins/{$current_plugin}/assets/{$path}";
            $plugin_asset_path = PathHelper::getIncludePath("plugins/{$current_plugin}/assets/{$path}");
            if (file_exists($plugin_asset_path)) {
                return $plugin_asset;
            }
        }
        
        // Return default theme path even if not found (will 404)
        $default_theme = self::getActive();
        return "/theme/{$default_theme}/assets/{$path}";
    }
    
    /**
     * Include file from theme with fallback to plugin and base (static helper)
     * 
     * @param string $path Path to file to include
     * @param string|null $themeName Optional theme name override
     * @param array $variables Variables to make available in the included file
     * @param string|null $plugin_specify Optional plugin name to use for fallback
     * @return bool Success
     */
    public static function includeThemeFile($path, $themeName = null, array $variables = [], $plugin_specify = null) {
        // STRICT VALIDATION in debug mode: Path must include .php extension
        $settings = Globalvars::get_instance();
        if ($settings->get_setting('debug') == '1') {
            if (substr($path, -4) !== '.php') {
                throw new Exception(
                    "ThemeHelper::includeThemeFile() validation error:\n" .
                    "Path must include .php extension for file inclusion\n" .
                    "Given: '{$path}'\n" .
                    "Expected: '{$path}.php'\n" .
                    "Reason: includeThemeFile() operates on FILES, which have extensions"
                );
            }
            
            // Also validate no double .php
            if (substr($path, -8) === '.php.php') {
                throw new Exception(
                    "ThemeHelper::includeThemeFile() validation error:\n" .
                    "Path contains double .php extension\n" .
                    "Given: '{$path}'\n" .
                    "This usually indicates .php being added twice"
                );
            }
        }
        
        if ($themeName === null) {
            $themeName = self::getActive();
        }
        
        // Determine if path starts with 'includes/' (theme-specific includes)
        $is_includes_path = strpos($path, 'includes/') === 0;
        
        if ($is_includes_path) {
            // Use PathHelper to get the correct theme file path (handles plugin themes)
            try {
                // PathHelper::getThemeFilePath handles both regular themes and plugin themes
                // Path should already have .php extension at this point (validated above in debug mode)
                $filename = basename($path);
                $subdirectory = '/' . dirname($path);
                $full_path = PathHelper::getThemeFilePath($filename, $subdirectory, 'system', $themeName);
                if (file_exists($full_path)) {
                    extract($variables);
                    self::outputDebugComments($full_path, $themeName, $plugin_specify);
                    include $full_path;
                    return true;
                }
            } catch (Exception $e) {
                // File not found in theme, try base path fallback
            }
            
            // Fallback to base path for includes
            // Path should already have .php extension at this point
            if (file_exists(PathHelper::getIncludePath($path))) {
                extract($variables);
                self::outputDebugComments($path, $themeName, $plugin_specify);
                include PathHelper::getIncludePath($path);
                return true;
            }
        } else {
            // View files: use the plugin/theme view resolution system
            
            // 1. Theme views get first priority
            // Path already includes .php extension (validated above)
            $theme_path = "theme/{$themeName}/views/{$path}";
            if (file_exists(PathHelper::getIncludePath($theme_path))) {
                extract($variables);
                self::outputDebugComments($theme_path, $themeName, $plugin_specify);
                include PathHelper::getIncludePath($theme_path);
                return true;
            }
            
            // 2. Check plugin (specified or current based on route)
            $plugin = $plugin_specify ?: RouteHelper::getCurrentPlugin();
            if ($plugin) {
                $plugin_path = "plugins/{$plugin}/views/{$path}";
                if (file_exists(PathHelper::getIncludePath($plugin_path))) {
                    extract($variables);
                    self::outputDebugComments($plugin_path, $themeName, $plugin);
                    include PathHelper::getIncludePath($plugin_path);
                    return true;
                }
            }
            
            // 3. Base views fallback
            $base_path = "views/{$path}";
            if (file_exists(PathHelper::getIncludePath($base_path))) {
                extract($variables);
                self::outputDebugComments($base_path, $themeName, $plugin_specify);
                include PathHelper::getIncludePath($base_path);
                return true;
            }
        }
        
        // Not found - log if debug mode
        $settings = Globalvars::get_instance();
        if ($settings->get_setting('debug') == '1') {
            error_log("File not found: {$path} (theme: {$themeName}, plugin: " . ($plugin_specify ?: RouteHelper::getCurrentPlugin()) . ")");
            echo "<!-- File not found: {$path} -->\n";
        }
        
        return false;
    }
    
    /**
     * Get theme configuration value
     */
    public static function config($key, $default = null, $themeName = null) {
        try {
            $instance = self::getInstance($themeName);
            return $instance->get($key, $default);
        } catch (Exception $e) {
            return $default;
        }
    }
    
    /**
     * Get all available themes with their helpers
     */
    public static function getAvailableThemes() {
        $themes = [];
        $themeDir = PathHelper::getIncludePath('theme');
        
        if (is_dir($themeDir)) {
            $directories = glob($themeDir . '/*', GLOB_ONLYDIR);
            foreach ($directories as $dir) {
                $themeName = basename($dir);
                try {
                    $themes[$themeName] = self::getInstance($themeName);
                } catch (Exception $e) {
                    // Skip themes without valid manifests
                    error_log("Theme {$themeName} skipped: " . $e->getMessage());
                    continue;
                }
            }
        }
        
        return $themes;
    }
    
    /**
     * Check if theme exists and has valid manifest
     */
    public static function themeExists($themeName) {
        try {
            self::getInstance($themeName);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Switch active theme (system-level operation)
     */
    public static function switchTheme($themeName) {
        // Validate new theme
        try {
            $newTheme = self::getInstance($themeName);
            $validation = $newTheme->validate();
            
            if ($validation !== true) {
                throw new Exception("Theme validation failed: " . implode(', ', $validation));
            }
        } catch (Exception $e) {
            throw new Exception("Cannot switch to theme '{$themeName}': " . $e->getMessage());
        }
        
        // Update database setting
        $settings = Globalvars::get_instance();
        $settings->set_setting('theme_template', $themeName);
        
        // Clear cached current theme instance
        $oldTheme = $settings->get_setting('theme_template', true, true);
        if (isset(self::$instances[$oldTheme])) {
            unset(self::$instances[$oldTheme]);
        }
        
        // Initialize new theme
        $newTheme->initialize();
        
        return true;
    }
    
    /**
     * Initialize all active themes (usually just one)
     */
    public static function initializeActive() {
        $results = ['success' => [], 'failed' => []];
        
        try {
            $activeTheme = self::getInstance();
            $activeTheme->initialize();
            $results['success'][$activeTheme->getName()] = 'Theme initialized';
        } catch (Exception $e) {
            $results['failed']['theme'] = $e->getMessage();
            error_log("Failed to initialize active theme: " . $e->getMessage());
        }
        
        return $results;
    }
    
    /**
     * Validate all available themes
     */
    public static function validateAll() {
        $results = [];
        $themes = self::getAvailableThemes();
        
        foreach ($themes as $name => $theme) {
            $validation = $theme->validate();
            $results[$name] = [
                'valid' => $validation === true,
                'errors' => $validation === true ? [] : $validation
            ];
        }
        
        return $results;
    }
    
    /**
     * New method for views that MUST exist (used by routes)
     */
    public static function requireThemeFile($path, $themeName = null, $variables = [], $plugin_specify = null) {
        $result = self::includeThemeFile($path, $themeName, $variables, $plugin_specify);
        
        if (!$result) {
            $settings = Globalvars::get_instance();
            $debug = $settings->get_setting('debug') == '1';
            
            $plugin = $plugin_specify ?: RouteHelper::getCurrentPlugin();
            $attempted = self::getViewResolutionOrder($path, $themeName, $plugin);
            error_log("Required view not found: {$path}");
            
            if ($debug) {
                error_log("Attempted: " . implode(', ', $attempted));
                echo "<!-- Required view not found: {$path} -->\n";
                echo "<!-- Attempted: " . implode(', ', $attempted) . " -->\n";
            }
            
            // Use existing 404 function
            LibraryFunctions::display_404_page();
            exit;
        }
        
        return $result;
    }
    
    /**
     * Get active theme name
     */
    public static function getActive() {
        $settings = Globalvars::get_instance();
        return $settings->get_setting('theme_template', true, true);
    }
    
    /**
     * Helper method for getting view resolution order (useful for debugging)
     */
    public static function getViewResolutionOrder($path, $themeName = null, $plugin = null) {
        if ($themeName === null) {
            $themeName = self::getActive();
        }
        
        $order = [];
        $order[] = "theme/{$themeName}/views/{$path}.php";
        
        if (!$plugin) {
            $plugin = RouteHelper::getCurrentPlugin();
        }
        if ($plugin) {
            $order[] = "plugins/{$plugin}/views/{$path}.php";
        }
        
        $order[] = "views/{$path}.php";
        
        return $order;
    }
    
    /**
     * Get asset version for cache busting
     */
    private static function getAssetVersion($themeName, $path) {
        // This method is referenced in the asset() method but didn't exist
        // For now, return null - can be implemented later for cache busting
        return null;
    }
    
    /**
     * Output helpful debug comments for theme/plugin file loading
     */
    private static function outputDebugComments($file_path, $theme_name, $plugin = null) {
        // Only show debug comments when debug setting is enabled
        $settings = Globalvars::get_instance();
        if ($settings->get_setting('debug') != '1') {
            return;
        }
        
        echo "<!-- System Info\n";
        echo "Theme: " . htmlspecialchars($theme_name) . "\n";
        if ($theme_name === 'plugin') {
            $active_theme_plugin = $settings->get_setting('active_theme_plugin');
            echo "Active Theme Plugin: " . htmlspecialchars($active_theme_plugin ?: '(none set)') . "\n";
        }
        if ($plugin) {
            echo "Plugin: " . htmlspecialchars($plugin) . "\n";
        }
        echo "File: " . htmlspecialchars($file_path) . "\n";
        echo "Route: " . htmlspecialchars($_SERVER['REQUEST_URI'] ?? 'unknown') . "\n";
        
        // Check if session exists and is safe to access
        if (class_exists('Session')) {
            try {
                $session = new Session($settings);
                echo "Session: " . ($session->is_logged_in() ? 'logged_in' : 'guest') . "\n";
            } catch (Exception $e) {
                echo "Session: unknown\n";
            }
        } else {
            echo "Session: unknown\n";
        }
        
        echo "Timestamp: " . date('Y-m-d H:i:s') . "\n";
        echo "-->\n";
    }
}