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

        // Maturity status is honest labeling only and gates nothing, but an
        // unknown value is a manifest error, not a silently ignored string.
        if (isset($this->manifestData['status'])
            && !in_array($this->manifestData['status'], array('experimental', 'beta', 'stable', 'deprecated'), true)) {
            $errors[] = "Unknown status '" . $this->manifestData['status'] . "' — must be one of: experimental, beta, stable, deprecated";
        }
        
        // An audience is a list of site domains. A string or object here would
        // silently hide the theme from every catalog, so it fails loudly.
        if (isset($this->manifestData['audience'])) {
            $audience = $this->manifestData['audience'];
            $audience_valid = is_array($audience) && $audience === array_values($audience);
            if ($audience_valid) {
                foreach ($audience as $audience_entry) {
                    if (!is_string($audience_entry) || trim($audience_entry) === '') {
                        $audience_valid = false;
                        break;
                    }
                }
            }
            if (!$audience_valid) {
                $errors[] = "Invalid audience — must be a list of site domains, e.g. [\"example.com\"]";
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
    
    // === STATIC HELPER METHODS ===
    // These provide convenient access without needing the instance
    
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
     * Get active theme name
     */
    public static function getActive() {
        $settings = Globalvars::get_instance();
        return $settings->get_setting('theme_template', true, true);
    }
    
}