<?php
require_once('ThemeHelper.php');
require_once('PluginHelper.php');
require_once(__DIR__ . '/Globalvars.php');

class PathHelper {
    private static $root_dir = null;
    
    public static function getRootDir() {
        if (self::$root_dir === null) {
            // Calculate root from this file's location
            self::$root_dir = dirname(__DIR__);
        }
        return self::$root_dir;
    }
    
    public static function getIncludePath($relativePath) {
        return self::getRootDir() . '/' . ltrim($relativePath, '/');
    }
    
    public static function getBasePath() {
        return self::getRootDir();
    }
    
    public static function getAbsolutePath($relativePath) {
        return self::getRootDir() . '/' . ltrim($relativePath, '/');
    }
    
    public static function requireOnce($relativePath) {
        $fullPath = self::getIncludePath($relativePath);
        if (file_exists($fullPath)) {
            require_once $fullPath;
            return true;
        }
        throw new Exception("Required file not found: $relativePath (looked for: $fullPath)");
    }
    
    /**
     * Check if file exists at given path
     * 
     * @param string $path Relative path from document root
     * @return bool True if file exists
     */
    public static function fileExists($path) {
        return file_exists(self::getAbsolutePath($path));
    }
    
    /**
     * Get URL path from filesystem path (reverse of getAbsolutePath)
     * 
     * @param string $absolute_path Absolute filesystem path
     * @return string URL path (with leading slash)
     */
    public static function getUrlPath($absolute_path) {
        $root = self::getRootDir();
        if (strpos($absolute_path, $root) === 0) {
            $url_path = substr($absolute_path, strlen($root));
            return '/' . ltrim($url_path, '/');
        }
        return $absolute_path;
    }
    
    /**
     * Get file extension from path
     * 
     * @param string $path File path
     * @return string File extension (without dot)
     */
    public static function getExtension($path) {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }
    
    /**
     * Get the active theme directory path (handles both regular and plugin themes)
     * @return string Theme directory relative path (e.g., 'theme/falcon' or 'plugins/controld')
     * @throws Exception if plugin theme is active but plugin not found
     */
    public static function getActiveThemeDirectory() {
        $settings = Globalvars::get_instance();
        
        // Try to get theme_template, with fallback handling during database updates
        try {
            $theme_template = $settings->get_setting('theme_template', true, true);
        } catch (Exception $e) {
            // During database updates when settings table might not be available
            $theme_template = 'falcon';
        }
        
        if ($theme_template === 'plugin') {
            // Get the active plugin - this should always be set when plugin theme is active
            try {
                $active_plugin = $settings->get_setting('active_theme_plugin', true, true);
            } catch (Exception $e) {
                // If setting doesn't exist, throw error - plugin theme should not be active without a plugin selected
                throw new Exception("Plugin theme is active but active_theme_plugin setting is missing.");
            }
            
            if (!$active_plugin) {
                throw new Exception("Plugin theme is active but no plugin selected. Please contact administrator.");
            }
            
            $plugin_dir = self::getIncludePath("plugins/$active_plugin");
            if (!is_dir($plugin_dir)) {
                throw new Exception("Plugin theme is active but plugin '$active_plugin' not found. Please contact administrator.");
            }
            
            return "plugins/$active_plugin";
        }
        
        // Validate regular theme exists
        $theme_dir = self::getIncludePath("theme/$theme_template");
        if (!is_dir($theme_dir)) {
            throw new Exception("Theme '$theme_template' directory not found. Please contact administrator.");
        }
        
        return "theme/$theme_template";
    }
    
    /**
     * Check if the current theme is a plugin-provided theme
     * @return bool True if plugin theme is active
     */
    public static function isPluginTheme() {
        $settings = Globalvars::get_instance();
        try {
            $theme_template = $settings->get_setting('theme_template', true, true);
            return $theme_template === 'plugin';
        } catch (Exception $e) {
            // During database updates or if setting doesn't exist
            return false;
        }
    }
    
    /**
     * Get the active theme plugin name (if plugin theme is active)
     * @return string|null Plugin name or null if not using plugin theme
     */
    public static function getActiveThemePlugin() {
        if (!self::isPluginTheme()) {
            return null;
        }
        $settings = Globalvars::get_instance();
        try {
            return $settings->get_setting('active_theme_plugin', true, true);
        } catch (Exception $e) {
            // Setting doesn't exist yet
            return null;
        }
    }
    
    /**
     * Get theme file path with fallback to base
     * Moved from LibraryFunctions for proper architectural separation
     */
    public static function getThemeFilePath($filename, $subdirectory='', $path_format='system', $theme_name=NULL, $debug = false){
        $siteDir = PathHelper::getBasePath();
        
        // SUBDIRECTORY WORKS WITH OR WITHOUT SLASH
        if (substr($subdirectory, 0, 1) !== '/') {
            $subdirectory = '/' . $subdirectory; // Add a forward slash if it doesn't exist
        }
        
        // IMPORTANT: Core system files must always load from system directories
        // to prevent circular dependencies during bootstrap
        $core_system_files = array('Globalvars.php', 'Globalvars_site.php', 'DbConnector.php', 'PathHelper.php');
        $is_core_file = in_array($filename, $core_system_files);
        
        // Handle when specific theme is requested
        if ($theme_name) {
            // Special handling for 'plugin' theme - use getActiveThemeDirectory
            if ($theme_name === 'plugin') {
                try {
                    $theme_dir = self::getActiveThemeDirectory();
                } catch (Exception $e) {
                    // If we can't get the plugin theme directory, throw the error
                    throw $e;
                }
            } else {
                $theme_dir = "theme/$theme_name";
            }
            $theme_file = $siteDir . '/' . $theme_dir . $subdirectory . '/' . $filename;
            
            if (file_exists($theme_file)) {
                if ($path_format == 'system') {
                    return $theme_file;  // Full system path
                } else {
                    return '/' . $theme_dir . $subdirectory . '/' . $filename;  // Web path
                }
            }
            // Fall through to check base directory
        }
        // Don't use plugin theme for core files
        else if (!$is_core_file) {
            try {
                // Use centralized method to get active theme directory
                $theme_dir = self::getActiveThemeDirectory();
                $theme_file = $siteDir . '/' . $theme_dir . $subdirectory . '/' . $filename;
                
                if (file_exists($theme_file)) {
                    if ($path_format == 'system') {
                        return $theme_file;  // Full system path
                    } else {
                        return '/' . $theme_dir . $subdirectory . '/' . $filename;  // Web path
                    }
                }
            } catch (Exception $e) {
                // Log error and re-throw - don't silently fall back
                error_log("Theme error: " . $e->getMessage());
                throw $e;
            }
        }
        
        // Check base/default directory
        $default_file = $siteDir . $subdirectory . '/' . $filename;
        
        if ($debug) {
            echo 'Theme directory: ' . (isset($theme_dir) ? $theme_dir : 'none') . '<br>';
            echo 'Theme file: ' . (isset($theme_file) ? $theme_file : 'none') . '<br>';
            echo 'Default file: ' . $default_file . '<br>';
        }
        
        if (file_exists($default_file)) {
            if ($path_format == 'system') {
                return $default_file;
            } else {
                return $subdirectory . '/' . $filename;
            }
        }
        
        // Build helpful error message
        $searched_paths = [];
        if (isset($theme_file)) {
            $searched_paths[] = $theme_file;
        }
        $searched_paths[] = $default_file;
        
        $error_msg = "Theme file not found: $filename";
        if (isset($theme_dir)) {
            $error_msg .= " for theme directory '$theme_dir'";
        }
        $error_msg .= "\nSearched paths:\n - " . implode("\n - ", $searched_paths);
        
        // Add helpful suggestion
        $error_msg .= "\nSuggestion: Create $filename in your theme's $subdirectory directory or ensure the file exists in the base $subdirectory directory";
        
        throw new Exception($error_msg);
    }
}
?>