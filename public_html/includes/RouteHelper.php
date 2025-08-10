<?php
/**
 * RouteHelper - Simplified routing and file serving utilities  
 * Handles route matching, parameter extraction, and file serving for serve.php refactoring
 */
require_once('PathHelper.php');
require_once('PluginHelper.php');

class RouteHelper {
    
    /**
     * Serve static file with proper HTTP caching headers and MIME type detection
     * 
     * This method handles serving static files (CSS, JS, images, etc.) with appropriate
     * caching headers for performance. It supports excluding certain file types from 
     * long-term caching (useful for files like .upg.zip that should not be cached).
     * Automatically detects MIME type based on file extension and sets proper headers.
     * 
     * @param string $file_path Path to file to serve
     * @param int $cache_seconds Cache time in seconds (default: 43200 = 12 hours)
     * @param array $exclude_from_cache File extensions to exclude from long caching
     * @return bool True if file served, false if not found
     */
    public static function serveStaticFile($file_path, $cache_seconds = 43200, $exclude_from_cache = []) {
        if (!file_exists($file_path)) {
            return false;
        }
        
        $file_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        $actual_cache_seconds = $cache_seconds;
        
        // Check if this file type should be excluded from long caching
        foreach ($exclude_from_cache as $excluded_ext) {
            if (strpos($excluded_ext, '.') === 0) {
                $excluded_ext = substr($excluded_ext, 1); // Remove leading dot
            }
            if ($file_extension === strtolower($excluded_ext)) {
                $actual_cache_seconds = 300; // 5 minutes for excluded files
                break;
            }
        }
        
        // Set caching headers
        $ts = gmdate("D, d M Y H:i:s", time() + $actual_cache_seconds) . " GMT";
        header("Expires: $ts");
        header("Pragma: cache");
        header("Cache-Control: max-age=$actual_cache_seconds");
        
        // Set content type
        $content_type = self::getMimeType($file_path);
        header("Content-type: $content_type");
        
        // Serve file
        readfile($file_path);
        return true;
    }
    
    
    /**
     * Check for database-stored URL redirects and perform redirect if found
     * 
     * This method queries the url_urls table for custom URL redirects configured 
     * through the admin interface. Supports both 301 (permanent) and 302 (temporary)
     * redirects. If a matching URL is found but has no redirect configured, it will
     * display a 404 page. Only runs if the urls_active setting is enabled.
     * 
     * @param string $path Current request path
     * @param object $settings Globalvars settings object
     * @return bool True if redirect was performed, false otherwise
     */
    public static function checkUrlRedirects($path, $settings) {
        // Validate path first
        $sanitized_path = self::validatePath($path);
        if ($sanitized_path === false) {
            return false;
        }
        
        // Only check redirects if URLs feature is active
        if (!$settings->get_setting('urls_active')) {
            return false;
        }
        
        // Load URL redirect system
        try {
            PathHelper::requireOnce('data/urls_class.php');
        } catch (Exception $e) {
            return false;
        }
        
        // Look for matching URL redirect
        $urls = new MultiUrl(
            array('deleted' => false, 'incoming' => mb_convert_encoding($sanitized_path, 'UTF-8', 'UTF-8')),
            NULL,
            1,  // Limit to 1 result
            0,  // No offset
            'AND'
        );
        $urls->load();
        
        if ($urls->count()) {
            $url = $urls->get(0);
            
            // Check if there's a redirect URL configured
            if ($url->get('url_redirect_url')) {
                // Determine redirect type (301 or 302)
                $redirect_type = $url->get('url_type');
                if ($redirect_type == 301) {
                    header("HTTP/1.1 301 Moved Permanently");
                } else {
                    header("HTTP/1.1 302 Found");
                }
                
                header("Location: " . $url->get('url_redirect_url'));
                exit();
            } else {
                // URL found but no redirect configured - show 404
                LibraryFunctions::display_404_page();
                exit();
            }
        }
        
        return false; // No redirect found
    }
    
    /**
     * Validate and sanitize request path for security
     * 
     * Checks for common security issues in request paths including:
     * - Path traversal attempts (../, ..\)
     * - Null byte injection (\0)
     * - Backslash directory separators
     * - Leading/trailing slashes (normalizes to clean relative paths)
     * - Encoded path traversal sequences
     * 
     * @param string $path Request path to validate (relative, no leading slash)
     * @return string|false Sanitized path if valid, false if invalid/dangerous
     */
    public static function validatePath($path) {
        // Basic type check - empty string is valid (root path)
        if (!is_string($path)) {
            return false;
        }
        
        // Handle empty path (root)
        if (empty($path)) {
            return '';
        }
        
        // Remove any leading/trailing slashes (normalize to relative path)
        $path = trim($path, '/');
        
        // Check for null bytes (security risk)
        if (strpos($path, "\0") !== false) {
            return false;
        }
        
        // Check for backslashes (Windows-style paths not allowed)
        if (strpos($path, '\\') !== false) {
            return false;
        }
        
        // Check for path traversal attempts
        if (strpos($path, '../') !== false || strpos($path, '..\\') !== false) {
            return false;
        }
        
        // Check for paths starting with .. (relative parent access)
        if (strpos($path, '..') === 0) {
            return false;
        }
        
        // Check for encoded path traversal sequences
        $decoded_path = urldecode($path);
        if (strpos($decoded_path, '../') !== false || strpos($decoded_path, '..\\') !== false || strpos($decoded_path, '..') === 0) {
            return false;
        }
        
        // Check for double slashes (normalize to single)
        $path = preg_replace('#/+#', '/', $path);
        
        return $path;
    }
    
    /**
     * Match route pattern against request path and return route configuration
     * 
     * This is the core routing method that iterates through route patterns and finds
     * the first match using wildcard (*) and parameter ({slug}) matching. When a route
     * matches, it automatically sets the global $is_valid_page flag (respecting the
     * 'valid_page' option) and returns the route configuration merged with the pattern 
     * and path for further processing.
     * 
     * @param string $path Request path
     * @param array $routes Routes configuration
     * @return array|false Route configuration if matched, false otherwise
     */
    public static function matchRoute($path, $routes) {
        // Validate and sanitize the path first
        $sanitized_path = self::validatePath($path);
        if ($sanitized_path === false) {
            return false;
        }
        
        foreach ($routes as $pattern => $config) {
            if (self::matchesPattern($pattern, $sanitized_path)) {
                // Auto-set valid page when route matches (unless explicitly disabled)
                global $is_valid_page;
                $is_valid_page = ($config['valid_page'] ?? true) ? true : false;
                return array_merge($config, ['pattern' => $pattern, 'path' => $sanitized_path]);
            }
        }
        return false;
    }
    
    /**
     * Handle static file routes with caching and plugin activation checks
     * 
     * Processes static file routes (CSS, JS, images, etc.) with support for explicit
     * view files, wildcard patterns, and plugin activation requirements. Handles
     * caching exclusions for specific file types and serves files with appropriate
     * HTTP headers. Used for routes like 'includes/*' and plugin asset routes.
     * 
     * @param array $route Route configuration
     * @param array $params URL parameters  
     * @param string $template_directory Theme directory
     * @return bool True if handled successfully
     */
    public static function handleStaticRoute($route, $params, $template_directory) {
        $pattern = $route['pattern'];
        $path = $route['path'];
        
        // Check plugin activation requirement
        if (!empty($route['require_plugin_active'])) {
            if (preg_match('#^plugins/([^/]+)/#', $path, $matches)) {
                $plugin_name = $matches[1];
                if (!PluginHelper::isPluginActive($plugin_name)) {
                    return false;
                }
            }
        }
        
        // Handle explicit view file
        if (!empty($route['view'])) {
            // Use ThemeHelper for consistent file loading with theme override support
            return ThemeHelper::includeThemeFile($route['view']);
        }
        
        // Handle wildcard static routes
        if (strpos($pattern, '*') !== false) {
            // includes/* -> includes/file.css becomes full path
            $prefix = str_replace('*', '', $pattern);
            if (strpos($path, $prefix) === 0) {
                $file_path = PathHelper::getAbsolutePath($path);
                if (file_exists($file_path)) {
                    $cache_seconds = $route['cache'] ?? 43200;
                    $exclude_from_cache = $route['exclude_from_cache'] ?? [];
                    return self::serveStaticFile($file_path, $cache_seconds, $exclude_from_cache);
                }
            }
        } else {
            // Handle specific file routes
            $file_path = PathHelper::getAbsolutePath($path);
            if (file_exists($file_path)) {
                $cache_seconds = $route['cache'] ?? 43200;
                return self::serveStaticFile($file_path, $cache_seconds);
            }
        }
        
        return false;
    }
    
    /**
     * Handle content routes using model-view pattern with theme override support
     * 
     * This method handles routes that load data models and render views, such as
     * 'page/{slug}' or 'product/{id}'. It checks feature flag settings if specified,
     * loads the specified model class, creates an instance using URL parameters (slug or id), 
     * extracts the parameters into the view scope, and renders the view with automatic 
     * theme override support. Essential for content-driven routes.
     * 
     * @param array $route Route configuration
     * @param array $params URL parameters
     * @param string $template_directory Theme directory
     * @return bool True if handled successfully
     */
    public static function handleContentRoute($route, $params, $template_directory) {
        $model_name = $route['model'] ?? null;
        if (!$model_name) {
            return false;
        }
        
        // Check setting requirement if specified
        if (!empty($route['check_setting'])) {
            $settings = Globalvars::get_instance();
            if (!$settings->get_setting($route['check_setting'])) {
                return false;
            }
        }
        
        // Load model class
        $model_file = 'data/' . strtolower($model_name) . 's_class.php';
        try {
            PathHelper::requireOnce($model_file);
        } catch (Exception $e) {
            return false;
        }
        
        // Extract parameters from route pattern
        $route_params = self::extractRouteParams($route['pattern'], $route['path']);
        
        // Create model instance
        $model_instance = null;
        if (isset($route_params['slug'])) {
            $model_instance = call_user_func([$model_name, 'get_by_link'], $route_params['slug']);
        } elseif (isset($route_params['id'])) {
            $model_instance = new $model_name($route_params['id'], true);
        }
        
        // Determine view file
        $view_file = $route['view'] ?? strtolower($model_name) . '.php';
        $view_path = 'views/' . $view_file;
        
        // Load view with theme override and extract data into scope
        if (!empty($view_path)) {
            extract([
                strtolower($model_name) => $model_instance,
                'params' => $route_params
            ], EXTR_SKIP);
            return ThemeHelper::includeThemeFile($view_path);
        }
        return false;
    }
    
    /**
     * Handle simple routes with plugin override support and theme fallbacks
     * 
     * This method handles direct file serving routes like 'admin/settings' or 'ajax/endpoint'.
     * It checks feature flag settings if specified, then checks if any active plugins provide 
     * an override for ajax/utils requests, derives the appropriate view path from the route 
     * pattern, and finally attempts to load the file with theme override support and 
     * default view fallbacks.
     * 
     * @param array $route Route configuration
     * @param array $params URL parameters
     * @param string $template_directory Theme directory
     * @param bool $is_plugin_theme Whether current theme is a plugin
     * @return bool True if handled successfully
     */
    public static function handleSimpleRoute($route, $params, $template_directory, $is_plugin_theme = false) {
        $pattern = $route['pattern'];
        $path = $route['path'];
        
        // Check setting requirement if specified
        if (!empty($route['check_setting'])) {
            $settings = Globalvars::get_instance();
            if (!$settings->get_setting($route['check_setting'])) {
                return false;
            }
        }
        
        // Check for plugin files first (ajax/utils override)
        if (preg_match('#^(ajax|utils)/(.+)$#', $path, $matches)) {
            $type = $matches[1];
            $file = $matches[2];
            
            $activePlugins = PluginHelper::getActivePlugins();
            foreach ($activePlugins as $pluginName => $pluginHelper) {
                // Use ComponentBase method with built-in file checking and inclusion
                if ($pluginHelper->includeFile($type . '/' . $file)) {
                    return true;
                }
            }
        }
        
        // Handle admin routes specially
        if (strpos($path, 'admin/') === 0) {
            $admin_file = str_replace('admin/', 'adm/', $path) . '.php';
            if (file_exists(PathHelper::getAbsolutePath($admin_file))) {
                global $is_valid_page;
                $is_valid_page = true;
                require_once(PathHelper::getAbsolutePath($admin_file));
                return true;
            }
        }
        
        // Handle api/v1 routes specially
        if (strpos($path, 'api/v1') === 0) {
            $api_file = 'api/apiv1.php';
            if (file_exists(PathHelper::getAbsolutePath($api_file))) {
                global $is_valid_page;
                $is_valid_page = true;
                require_once(PathHelper::getAbsolutePath($api_file));
                return true;
            }
        }
        
        // Handle tests routes
        if (strpos($path, 'tests/') === 0) {
            // Check if path already has .php extension
            if (substr($path, -4) === '.php') {
                $test_file = $path;
            } else {
                $test_file = $path . '.php';
            }
            $full_test_path = PathHelper::getAbsolutePath($test_file);
            if (file_exists($full_test_path)) {
                global $is_valid_page;
                $is_valid_page = true;
                require_once($full_test_path);
                return true;
            }
        }
        
        // Derive view path from pattern
        $view_path = 'views/' . $path . '.php';
        if (strpos($pattern, '{') !== false) {
            // Remove parameter placeholders: /page/{slug} -> /page
            $clean_pattern = preg_replace('/\/{[^}]+}/', '', $pattern);
            $view_path = 'views' . $clean_pattern . '.php';
        }
        
        // Check theme override - use appropriate helper based on theme type
        if ($is_plugin_theme) {
            // For plugin themes, try plugin file first, then base file
            $plugin_name = basename($template_directory);
            $plugin_instance = PluginHelper::getInstance($plugin_name);
            
            if ($plugin_instance && $plugin_instance->includeFile($view_path)) {
                global $is_valid_page;
                $is_valid_page = true;
                return true;
            } else {
                // Fall back to base file
                $base_file = PathHelper::getIncludePath($view_path);
                if (file_exists($base_file)) {
                    global $is_valid_page;
                    $is_valid_page = true;
                    require_once($base_file);
                    return true;
                }
            }
        } else {
            // For directory themes, use ThemeHelper
            if (ThemeHelper::includeThemeFile($view_path)) {
                global $is_valid_page;
                $is_valid_page = true;
                return true;
            }
        }
        
        // Check default view if specified
        if (!empty($route['default_view'])) {
            if (ThemeHelper::includeThemeFile($route['default_view'])) {
                global $is_valid_page;
                $is_valid_page = true;
                return true;
            }
        }
        
        return false;
    }
    
    
    
    /**
     * Get MIME type for file using hybrid approach
     * 
     * Uses fast extension-based detection for common web assets (CSS, JS, images, fonts)
     * and falls back to content-based detection for unknown file types. This provides
     * optimal performance for static file serving while maintaining accuracy for uploads
     * and unknown file types. Falls back to 'application/octet-stream' for undetectable types.
     * 
     * @param string $filename File name or path
     * @return string MIME type
     */
    public static function getMimeType($filename) {
        // For performance, try extension-based first for common web assets
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        $web_mime_types = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'xml' => 'text/xml',
            'html' => 'text/html',
            'htm' => 'text/html',
            'txt' => 'text/plain',
            'pdf' => 'application/pdf',
            'zip' => 'application/zip',
            'gif' => 'image/gif',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mp3' => 'audio/mpeg',
            'ogg' => 'audio/ogg'
        ];
        
        if (isset($web_mime_types[$extension])) {
            return $web_mime_types[$extension];
        }
        
        // For unknown extensions, check the actual file content if it exists
        if (file_exists($filename) && function_exists('mime_content_type')) {
            $detected = mime_content_type($filename);
            if ($detected) {
                return $detected;
            }
        }
        
        return 'application/octet-stream';
    }
    
    /**
     * Extract parameters from route pattern and actual request path
     * 
     * Converts route patterns like '/page/{slug}' or '/product/{id}' into regex patterns
     * and extracts the parameter values from the actual request path. For example,
     * pattern '/page/{slug}' with path '/page/about-us' returns ['slug' => 'about-us'].
     * Essential for content routes that need URL parameters.
     * 
     * @param string $pattern Route pattern with {param} placeholders
     * @param string $path Actual request path
     * @return array Extracted parameters
     */
    public static function extractRouteParams($pattern, $path) {
        $params = [];
        
        // Convert pattern to regex
        $regex_pattern = preg_quote($pattern, '#');
        $regex_pattern = str_replace('\\*', '([^/]+)', $regex_pattern);
        $regex_pattern = preg_replace('/\\\\{([^}]+)\\\\}/', '([^/]+)', $regex_pattern);
        $regex_pattern = '#^' . $regex_pattern . '$#';
        
        // Extract parameter names
        preg_match_all('/\{([^}]+)\}/', $pattern, $param_names);
        
        // Match against path
        if (preg_match($regex_pattern, $path, $matches)) {
            array_shift($matches); // Remove full match
            
            // Map parameter names to values
            foreach ($param_names[1] as $index => $param_name) {
                if (isset($matches[$index])) {
                    $params[$param_name] = $matches[$index];
                }
            }
        }
        
        return $params;
    }
    
    /**
     * Handle fallback routes for unmatched requests
     * 
     * This method provides fallback handling for routes that don't match any explicit patterns.
     * It tries common patterns in order: theme view override, base view file, then direct file.
     * This maintains backward compatibility with the old serve.php behavior where any existing
     * view file could be accessed directly.
     * 
     * @param string $path Request path
     * @param string $template_directory Theme directory
     * @return bool True if fallback handled the request, false otherwise
     */
    public static function handleFallback($path, $template_directory) {
        // Validate path first
        $sanitized_path = self::validatePath($path);
        if ($sanitized_path === false) {
            return false;
        }
        
        // Try theme view file first, then base view file, then direct file
        $template_file = $template_directory ? $template_directory . '/views/' . $sanitized_path . '.php' : null;
        $base_view_file = PathHelper::getIncludePath('views/' . $sanitized_path . '.php');
        $direct_file = PathHelper::getAbsolutePath($sanitized_path);
        
        if ($template_file && file_exists($template_file)) {
            global $is_valid_page;
            $is_valid_page = true;
            require_once($template_file);
            return true;
        } else if (file_exists($base_view_file)) {
            global $is_valid_page;
            $is_valid_page = true;
            require_once($base_view_file);
            return true;
        } else if (file_exists($direct_file) && !is_dir($direct_file)) {
            // Serve direct file (like sitemap.xml, etc.) with appropriate MIME type
            return self::serveStaticFile($direct_file, 43200);
        }
        
        return false;
    }
    
    /**
     * Check if route pattern matches the request path
     * 
     * This method handles three types of pattern matching:
     * 1. Exact matches: '/admin' matches '/admin' 
     * 2. Wildcard patterns: '/includes/*' matches '/includes/style.css'
     * 3. Parameter patterns: '/page/{slug}' matches '/page/about-us'
     * Core pattern matching logic used by matchRoute().
     * 
     * @param string $pattern Route pattern
     * @param string $path Request path
     * @return bool True if pattern matches
     */
    public static function matchesPattern($pattern, $path) {
        // Handle exact matches
        if ($pattern === $path) {
            return true;
        }
        
        // Handle wildcard patterns
        if (strpos($pattern, '*') !== false) {
            $regex_pattern = preg_quote($pattern, '#');
            $regex_pattern = str_replace('\\*', '.*', $regex_pattern);
            return preg_match('#^' . $regex_pattern . '$#', $path);
        }
        
        // Handle parameter patterns
        if (strpos($pattern, '{') !== false) {
            $regex_pattern = preg_quote($pattern, '#');
            $regex_pattern = preg_replace('/\\\\{[^}]+\\\\}/', '[^/]+', $regex_pattern);
            return preg_match('#^' . $regex_pattern . '$#', $path);
        }
        
        return false;
    }
}