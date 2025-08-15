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
     * Get URL to theme asset
     */
    public static function asset($path, $themeName = null) {
        if (!$themeName) {
            $settings = Globalvars::get_instance();
            $themeName = $settings->get_setting('theme_template', true, true);
        }
        
        // Check if asset exists in theme
        $themeAssetPath = PathHelper::getIncludePath("theme/{$themeName}/{$path}");
        if (file_exists($themeAssetPath)) {
            return "/theme/{$themeName}/{$path}";
        }
        
        // Fallback to base path
        $basePath = PathHelper::getIncludePath($path);
        if (file_exists($basePath)) {
            return "/{$path}";
        }
        
        // Return theme path anyway (might be dynamically generated)
        return "/theme/{$themeName}/{$path}";
    }
    
    /**
     * Include file from theme with fallback to base (static helper)
     * 
     * @param string $path Path to file to include
     * @param string|null $themeName Optional theme name override
     * @param array $variables Variables to make available in the included file
     * @return bool Success
     */
    public static function includeThemeFile($path, $themeName = null, array $variables = []) {
        // Extract variables into local scope
        extract($variables, EXTR_SKIP);
        
        try {
            $instance = self::getInstance($themeName);
            
            // Try theme-specific file first
            $themeFile = $instance->getIncludePath($path);
            if (file_exists($themeFile)) {
                require_once($themeFile);
                return true;
            }
            
            // Try base path fallback
            $basePath = PathHelper::getIncludePath($path);
            if (file_exists($basePath)) {
                require_once($basePath);
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            // If theme doesn't exist, try base path
            $basePath = PathHelper::getIncludePath($path);
            if (file_exists($basePath)) {
                require_once($basePath);
                return true;
            }
            return false;
        }
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
}