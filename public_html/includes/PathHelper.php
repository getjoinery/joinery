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
        return self::getRootDir() . '/';
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
     * Get the full system path to a theme file with complete override chain support
     *
     * Resolution order:
     * 1. Theme override: /theme/{theme}/{path}
     * 2. Plugin context: /plugins/{plugin}/{path}
     * 3. Base fallback: /{path}
     *
     * @param string $filename Filename only with .php extension (e.g., 'profile.php', 'PublicPage.php')
     * @param string $subdirectory Subdirectory path without leading/trailing slashes (e.g., 'includes', 'assets/css')
     * @param string $path_format 'system' for absolute paths, 'web' for URL paths
     * @param string|null $theme_name Theme to use (null = current theme)
     * @param string|null $plugin_name Plugin name (null = auto-detect from RouteHelper)
     * @param bool $debug Enable debug output
     * @return string|false Path to file or false if not found
     * @throws Exception If file not found and required
     */
    public static function getThemeFilePath($filename, $subdirectory='', $path_format='system', $theme_name=NULL, $plugin_name=NULL, $debug = false) {

        // STRICT INPUT VALIDATION - Enforce consistent format across codebase

        // 1. Filename validation
        if (empty($filename)) {
            throw new Exception("Filename cannot be empty");
        }

        // Removed PHP-only restriction to allow CSS, JS, and other asset files

        // Filename cannot contain any directory separators
        if (strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
            throw new Exception("Filename cannot contain slashes. Use subdirectory parameter for path. Given: '$filename'");
        }

        // 2. Subdirectory validation
        // Subdirectory cannot have leading or trailing slashes (none in current usage)
        if (!empty($subdirectory)) {
            if (substr($subdirectory, 0, 1) === '/' || substr($subdirectory, -1) === '/') {
                throw new Exception("Subdirectory cannot have leading or trailing slashes. Given: '$subdirectory'. Use format: 'includes' or 'assets/css'");
            }

            // No double slashes allowed
            if (strpos($subdirectory, '//') !== false) {
                throw new Exception("Subdirectory cannot contain double slashes. Given: '$subdirectory'");
            }

            // No backslashes allowed (use forward slashes for nested paths)
            if (strpos($subdirectory, '\\') !== false) {
                throw new Exception("Subdirectory must use forward slashes. Given: '$subdirectory'");
            }
        }

        // 3. Security validation - prevent path traversal
        if (strpos($filename, '..') !== false || strpos($subdirectory, '..') !== false) {
            throw new Exception("Path traversal attempt detected ('..') in filename or subdirectory");
        }

        // 4. Build clean path with exactly one slash between components
        if ($subdirectory !== '') {
            $relative_path = $subdirectory . '/' . $filename;
        } else {
            $relative_path = $filename;
        }

        // Get theme name if not specified
        if ($theme_name === NULL) {
            $theme_name = self::getActiveThemeDirectory();
        }

        // Auto-detect plugin name if not specified
        if ($plugin_name === null && class_exists('RouteHelper')) {
            $plugin_name = RouteHelper::getCurrentPlugin();
        }


        // Get the base directory - for 'system' we need full path, for 'web' we need URL path
        $base_dir = self::getBasePath(); // Always need full path for file_exists checks

        // 1. Try theme override first
        if ($theme_name) {
            // getActiveThemeDirectory() returns "theme/falcon" or "plugins/pluginname"
            // If it starts with "theme/" or "plugins/", use as-is, otherwise prepend "theme/"
            if (strpos($theme_name, 'theme/') === 0 || strpos($theme_name, 'plugins/') === 0) {
                $theme_check_path = $base_dir . $theme_name . '/' . $relative_path;
                $theme_return_path = ($path_format === 'web') ? '/' . $theme_name . '/' . $relative_path : $theme_check_path;
            } else {
                $theme_check_path = $base_dir . 'theme/' . $theme_name . '/' . $relative_path;
                $theme_return_path = ($path_format === 'web') ? '/theme/' . $theme_name . '/' . $relative_path : $theme_check_path;
            }
            if ($debug) {
                error_log("getThemeFilePath: Checking theme path: $theme_check_path");
            }
            if (file_exists($theme_check_path)) {
                if ($debug) {
                    error_log("getThemeFilePath: Found in theme, returning: $theme_return_path");
                }
                return $theme_return_path;
            }
        }

        // 2. Try plugin path if plugin name exists
        if ($plugin_name) {
            $plugin_check_path = $base_dir . 'plugins/' . $plugin_name . '/' . $relative_path;
            $plugin_return_path = ($path_format === 'web') ? '/plugins/' . $plugin_name . '/' . $relative_path : $plugin_check_path;
            if ($debug) {
                error_log("getThemeFilePath: Checking plugin path: $plugin_check_path");
            }
            if (file_exists($plugin_check_path)) {
                if ($debug) {
                    error_log("getThemeFilePath: Found in plugin, returning: $plugin_return_path");
                }
                return $plugin_return_path;
            }
        }

        // 3. Fall back to base directory
        $base_check_path = $base_dir . $relative_path;
        $base_return_path = ($path_format === 'web') ? '/' . $relative_path : $base_check_path;
        if ($debug) {
            error_log("getThemeFilePath: Checking base path: $base_check_path");
        }
        if (file_exists($base_check_path)) {
            if ($debug) {
                error_log("getThemeFilePath: Found in base, returning: $base_return_path");
            }
            return $base_return_path;
        }

        // File not found - throw exception with helpful error message
        $error_msg = "File not found: $relative_path\n";
        $error_msg .= "Searched locations:\n";
        if ($theme_name) {
            $error_msg .= "  Theme: " . (isset($theme_check_path) ? $theme_check_path : 'none') . "\n";
        }
        if ($plugin_name) {
            $error_msg .= "  Plugin: " . (isset($plugin_check_path) ? $plugin_check_path : 'none') . "\n";
        }
        $error_msg .= "  Base: $base_check_path";

        if ($debug) {
            error_log("getThemeFilePath: " . $error_msg);
        }

        throw new Exception($error_msg);
    }
}
?>