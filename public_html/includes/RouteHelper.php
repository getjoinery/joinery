<?php
/**
 * RouteHelper - Simplified routing and file serving utilities  
 * Handles route matching, parameter extraction, and file serving for serve.php refactoring
 * 
 * DEPENDENCY REQUIREMENTS:
 * - PathHelper is always available (system requirement)
 * - All other dependencies loaded on-demand within methods to minimize overhead
 */

// Load PathHelper - required for all routing operations
require_once('PathHelper.php');

class RouteHelper {
    
    /**
     * Serve static file with proper HTTP caching headers and MIME type detection
     * 
     * This method handles serving static files (CSS, JS, images, fonts, etc.) with appropriate
     * caching headers for performance. It includes security validation to prevent serving
     * PHP files as static assets. Supports excluding certain file types from long-term caching.
     * 
     * @param string $file_path Path to file to serve
     * @param int $cache_seconds Cache time in seconds (default: 43200 = 12 hours)
     * @param array $exclude_from_cache File extensions to exclude from long caching
     * @return bool True if file served, false if rejected or not found
     */
    public static function serveStaticFile($file_path, $cache_seconds = 43200, $exclude_from_cache = []) {
        if (!file_exists($file_path)) {
            return false;
        }
        
        $file_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        
        // SECURITY: Only serve actual static assets - never execute PHP files
        if ($file_extension === 'php') {
            echo "<!-- RouteHelper: SECURITY - Rejecting PHP file in static route: {$file_path} -->\n";
            return false; // Hard rejection - no execution
        }
        
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
        
        // Load URL redirect system - only if needed
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
                PathHelper::requireOnce('includes/LibraryFunctions.php');
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
            if (self::matchesPattern($pattern, $path)) {
                // Auto-set valid page when route matches (unless explicitly disabled)
                global $is_valid_page;
                $is_valid_page = ($config['valid_page'] ?? true) ? true : false;
                $result = array_merge($config, ['pattern' => $pattern, 'path' => $sanitized_path]);
                return $result;
            }
        }
        return false;
    }
    
    /**
     * Handle static file routes with caching and plugin activation checks
     * 
     * Processes ONLY static asset files (CSS, JS, images, fonts, etc.) with proper
     * caching headers and MIME type detection. This method should NEVER handle PHP
     * files or dynamic content - it only serves actual static assets using readfile().
     * Used for routes like '/theme/*' and '/plugins/ * /assets/*'.
     * 
     * @param array $route Route configuration
     * @param array $params URL parameters  
     * @param string $template_directory Theme directory
     * @return bool True if handled successfully
     */
    public static function handleStaticRoute($route, $params, $template_directory) {
        $pattern = $route['pattern'];
        $path = $route['path'];
        
        // ALWAYS check plugin activation for ANY plugin path - non-overridable security
        if (preg_match('#^/plugins/([^/]+)/#', $path, $matches)) {
            $plugin_name = $matches[1];
            if (!PluginHelper::isPluginActive($plugin_name)) {
                error_log("RouteHelper: BLOCKED inactive plugin access: {$path}");
                return false; // Always block inactive plugins - no exceptions
            }
        }
        
        // Static routes should NEVER handle view files - that's dynamic content
        // View files should be handled by simple or content routes
        
        // Handle wildcard and semantic placeholder static routes
        if (strpos($pattern, '*') !== false || strpos($pattern, '{') !== false) {
            // Handle semantic placeholders like /plugins/{plugin}/assets/* or /theme/{theme}/assets/*
            // Extract parameters and build file path
            $route_params = self::extractRouteParams($pattern, $path);
            
            // Build actual file path from request path
            $file_path = PathHelper::getAbsolutePath($path);
            if (file_exists($file_path)) {
                $cache_seconds = $route['cache'] ?? 43200;
                $exclude_from_cache = $route['exclude_from_cache'] ?? [];
                return self::serveStaticFile($file_path, $cache_seconds, $exclude_from_cache);
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
     * Handle dynamic routes with optional model loading and theme override support
     * 
     * This unified method handles all dynamic content routes:
     * 1. Simple view routes ('/login' => ['view' => 'views/login'])
     * 2. System routes with placeholders ('/admin/*' => ['view' => 'adm/{path}'])
     * 3. Model-based content routes ('/page/{slug}' => ['model' => 'Page', ...])
     * 4. Mixed routes (models + path placeholders + fallbacks)
     * 
     * @param array $route Route configuration
     * @param array $params URL parameters  
     * @param string $template_directory Theme directory
     * @return bool True if handled successfully
     */
    public static function handleDynamicRoute($route, $params, $template_directory) {
        $pattern = $route['pattern'];
        $path = $route['path'];
        
        // Check setting requirement if specified
        if (!empty($route['check_setting'])) {
            $settings = Globalvars::get_instance();
            if (!$settings->get_setting($route['check_setting'])) {
                return false;
            }
        }
        
        // Initialize variables that might be extracted to view scope
        $model_instance = null;
        $route_params = [];
        
        // MODEL LOADING LOGIC (optional)
        if (!empty($route['model'])) {
            $model_name = $route['model'];
            
            // Require model file
            if (empty($route['model_file'])) {
                error_log("RouteHelper: ERROR - 'model_file' is required when 'model' is specified");
                return false;
            }
            
            try {
                PathHelper::requireOnce($route['model_file'] . '.php');
            } catch (Exception $e) {
                error_log("RouteHelper: ERROR - Failed to load model file: " . $route['model_file'] . " - " . $e->getMessage());
                return false;
            }
            
            // Extract parameters from route pattern
            $route_params = self::extractRouteParams($pattern, $path);
            
            // Create model instance based on available parameters
            if (isset($route_params['slug'])) {
                $model_instance = call_user_func([$model_name, 'get_by_link'], $route_params['slug']);
            } elseif (isset($route_params['id'])) {
                $model_instance = new $model_name($route_params['id'], true);
            } else {
                // No specific parameter - might be a collection route or current user
                // This could be extended to support other patterns
            }
        } else {
            // No model - just extract route parameters for view use
            $route_params = self::extractRouteParams($pattern, $path);
        }
        
        // DETERMINE VIEW PATH
        $view_path = null;
        
        if (!empty($route['view'])) {
            // Explicit view path specified
            $view_path = $route['view'];
            
            // Handle dynamic placeholders in view path
            if (strpos($view_path, '{path}') !== false) {
                $pattern_prefix = rtrim(str_replace('*', '', $pattern), '/');
                $remaining_path = substr($path, strlen($pattern_prefix));
                $remaining_path = ltrim($remaining_path, '/');
                $view_path = str_replace('{path}', $remaining_path, $view_path);
            }
            
            if (strpos($view_path, '{file}') !== false) {
                $path_parts = explode('/', ltrim($path, '/'));
                $file = end($path_parts);
                
                // Strip .php extension if present
                if (substr($file, -4) === '.php') {
                    $file = substr($file, 0, -4);
                }
                
                $view_path = str_replace('{file}', $file, $view_path);
            }
            
            // Replace other route parameters in view path
            foreach ($route_params as $key => $value) {
                $view_path = str_replace('{' . $key . '}', $value, $view_path);
            }
            
        } elseif (!empty($route['model'])) {
            // Auto-determine view from model name
            $view_path = 'views/' . strtolower($route['model']);
        } else {
            error_log("RouteHelper: ERROR - Either 'view' or 'model' must be specified for dynamic routes");
            return false;
        }
        
        // HANDLE SPECIAL ROUTE TYPES
        
        // Admin routes - direct inclusion, no theme overrides
        if (strpos($view_path, 'adm/') === 0) {
            $admin_file = PathHelper::getAbsolutePath($view_path . '.php');
            if (file_exists($admin_file)) {
                // Extract model and params to scope if available
                if ($model_instance) {
                    extract([
                        strtolower($route['model']) => $model_instance,
                        'params' => $route_params
                    ], EXTR_SKIP);
                } else {
                    extract(['params' => $route_params], EXTR_SKIP);
                }
                require_once($admin_file);
                return true;
            }
            return false;
        }
        
        // Test/Utils routes - allow plugin overrides, no theme overrides
        if (strpos($view_path, 'tests/') === 0 || strpos($view_path, 'utils/') === 0) {
            $route_type = strpos($view_path, 'tests/') === 0 ? 'tests' : 'utils';
            
            // Check for plugin override
            if (preg_match('#^/' . $route_type . '/(.+)$#', $path, $matches)) {
                $file = $matches[1];
                
                if (!class_exists('PluginHelper')) {
                    PathHelper::requireOnce('includes/PluginHelper.php');
                }
                
                $activePlugins = PluginHelper::getActivePlugins();
                foreach ($activePlugins as $pluginName => $pluginHelper) {
                    if ($pluginHelper->includeFile($route_type . '/' . $file)) {
                        return true;
                    }
                }
            }
            
            // Fall back to base file
            $base_file = PathHelper::getAbsolutePath($view_path . '.php');
            if (file_exists($base_file)) {
                if ($model_instance) {
                    extract([
                        strtolower($route['model']) => $model_instance,
                        'params' => $route_params
                    ], EXTR_SKIP);
                } else {
                    extract(['params' => $route_params], EXTR_SKIP);
                }
                require_once($base_file);
                return true;
            }
            return false;
        }
        
        // Ajax routes - check plugin overrides first
        if (strpos($view_path, 'ajax/') === 0) {
            if (preg_match('#^/ajax/(.+)$#', $path, $matches)) {
                $file = $matches[1];
                
                if (!class_exists('PluginHelper')) {
                    PathHelper::requireOnce('includes/PluginHelper.php');
                }
                
                $activePlugins = PluginHelper::getActivePlugins();
                foreach ($activePlugins as $pluginName => $pluginHelper) {
                    if ($pluginHelper->includeFile('ajax/' . $file)) {
                        return true;
                    }
                }
            }
        }
        
        // STANDARD VIEW LOADING with theme overrides
        
        // Extract model and parameters to view scope
        global $is_valid_page;
        if ($model_instance) {
            $var_name = strtolower($route['model']);
            extract([
                $var_name => $model_instance,
                'params' => $route_params,
                'is_valid_page' => $is_valid_page
            ], EXTR_SKIP);
        } else {
            extract([
                'params' => $route_params,
                'is_valid_page' => $is_valid_page
            ], EXTR_SKIP);
        }
        
        // Try to load the view file with theme override support
        // BUT preserve variable scope by including directly instead of using ThemeHelper::includeThemeFile()
        
        // Get theme name
        $settings = Globalvars::get_instance();
        $theme_name = $settings->get_setting('theme_template', true, true);
        
        // Try theme-specific view first
        if ($theme_name) {
            $theme_file = PathHelper::getIncludePath("theme/{$theme_name}/{$view_path}.php");
            if (file_exists($theme_file)) {
                require_once($theme_file);
                return true;
            }
        }
        
        // Try base view
        $base_file = PathHelper::getIncludePath($view_path . '.php');
        if (file_exists($base_file)) {
            require_once($base_file);
            return true;
        }
        
        // Try default view if specified
        if (!empty($route['default_view'])) {
            // Try theme-specific default view first
            if ($theme_name) {
                $theme_default_file = PathHelper::getIncludePath("theme/{$theme_name}/{$route['default_view']}.php");
                if (file_exists($theme_default_file)) {
                    require_once($theme_default_file);
                    return true;
                }
            }
            
            // Try base default view
            $base_default_file = PathHelper::getIncludePath($route['default_view'] . '.php');
            if (file_exists($base_default_file)) {
                require_once($base_default_file);
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
     * Converts route patterns like '/page/{slug}', '/plugins/{plugin}/assets/*' or '/product/{id}' 
     * into regex patterns and extracts the parameter values from the actual request path. 
     * Handles both semantic placeholders (single segments) and wildcards (multi-segment).
     * For example:
     * - pattern '/page/{slug}' with path '/page/about-us' returns ['slug' => 'about-us']
     * - pattern '/plugins/{plugin}/assets/*' with path '/plugins/controld/assets/css/style.css' 
     *   returns ['plugin' => 'controld', 'path' => 'css/style.css']
     * Essential for content routes and static routes that need URL parameters.
     * 
     * @param string $pattern Route pattern with {param} placeholders and/or wildcards
     * @param string $path Actual request path
     * @return array Extracted parameters
     */
    public static function extractRouteParams($pattern, $path) {
        $params = [];
        
        // Normalize paths - ensure both have leading slash for comparison
        if ($pattern[0] !== '/') $pattern = '/' . $pattern;
        if ($path[0] !== '/') $path = '/' . $path;
        
        // Use preg_match with named capture groups
        $regex_pattern = preg_quote($pattern, '#');
        
        // Replace semantic placeholders with named capture groups
        $regex_pattern = preg_replace('/\\\\{([^}]+)\\\\}/', '(?P<$1>[^/]+)', $regex_pattern);
        
        // Replace wildcards with multi-segment captures  
        $regex_pattern = str_replace('\\\\*', '(.*)', $regex_pattern);
        
        $final_pattern = '#^' . $regex_pattern . '$#';
        
        // Match against the path and extract named groups
        if (preg_match($final_pattern, $path, $matches)) {
            // Extract only named capture groups (not numbered ones)
            foreach ($matches as $key => $value) {
                if (!is_numeric($key)) {
                    $params[$key] = $value;
                }
            }
        }
        
        return $params;
    }
    
    /**
     * Check if route pattern matches the request path
     * 
     * This method handles pattern matching with semantic placeholders:
     * 1. Exact matches: '/admin' matches '/admin' 
     * 2. Semantic placeholders: '/plugins/{plugin}/assets/*' - {plugin} = single segment, * = multi-segment
     * 3. Parameter patterns: '/page/{slug}' matches '/page/about-us'
     * 4. Wildcard patterns: '/admin/*' matches '/admin/settings/users' (multi-segment)
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
        
        // Handle patterns with wildcards or parameters
        if (strpos($pattern, '*') !== false || strpos($pattern, '{') !== false) {
            $regex_pattern = preg_quote($pattern, '#');
            
            // Replace semantic placeholders (single segments)
            $regex_pattern = preg_replace('/\\\\{(plugin|theme|file|slug|id|path)\\\\}/', '([^/]+)', $regex_pattern);
            
            // Replace wildcard with multi-segment match (everything from this point)
            $regex_pattern = str_replace('\\*', '(.*)', $regex_pattern);
            
            $final_pattern = '#^' . $regex_pattern . '$#';
            
            $result = preg_match($final_pattern, $path);
            
            return $result;
        }
        
        return false;
    }
    
    /**
     * Process all routes and handle the request
     * 
     * This is the main routing method that processes routes in the correct order:
     * 0. .php extension handling - Redirects /file.php to /file with monitoring
     * 1. Database URL redirects (if enabled)
     * 2. Static asset routes
     * 3. Custom routes with complex logic
     * 4. Content routes (model-view pattern)
     * 5. Simple routes (direct file serving)
     * 6. View directory fallback (automatic theme-aware view lookup)
     * 7. Plugin routes (backward compatibility)
     * 8. 404 fallback
     * 
     * @param array $routes Route configuration array
     * @param string $request_path The request path from $_REQUEST['path']
     * @return void Exits on successful route match or redirect
     */
    public static function processRoutes($routes, $request_path) {
        // EXTENSIVE DEBUGGING - Log everything that comes in
        error_log("Type of request_path: " . gettype($request_path));
        error_log("Length of request_path: " . strlen($request_path ?? ''));
        error_log("Is request_path empty: " . (empty($request_path) ? 'YES' : 'NO'));
        error_log("request_path === null: " . ($request_path === null ? 'YES' : 'NO'));
        error_log("request_path === '': " . ($request_path === '' ? 'YES' : 'NO'));
        
        // Log $_REQUEST contents
        error_log("Full \$_REQUEST array: " . var_export($_REQUEST, true));
        error_log("Specific \$_REQUEST['path']: " . var_export($_REQUEST['path'] ?? 'NOT_SET', true));
        
        // Log routes structure
        error_log("Routes structure passed in: " . var_export(array_keys($routes), true));
        if (isset($routes['static'])) {
            error_log("Static routes count: " . count($routes['static']));
            error_log("Static routes keys: " . var_export(array_keys($routes['static']), true));
        }
        if (isset($routes['dynamic'])) {
            error_log("Dynamic routes count: " . count($routes['dynamic']));
            error_log("Dynamic routes keys: " . var_export(array_keys($routes['dynamic']), true));
        }
        if (isset($routes['custom'])) {
            error_log("Custom routes count: " . count($routes['custom']));
            error_log("Custom routes keys: " . var_export(array_keys($routes['custom']), true));
        }
        
        // Initialize global variables
        global $is_valid_page;
        if (!isset($is_valid_page)) {
            $is_valid_page = false;
        }
        error_log("Initial \$is_valid_page: " . ($is_valid_page ? 'true' : 'false'));
        
        // Handle .php extension hiding and monitoring
        error_log("Checking for .php extension in: " . var_export($request_path, true));
        if (substr($request_path, -4) === '.php') {
            // Log warning for monitoring missed links
            error_log("PURE PHP ROUTING WARNING: Request for .php URL detected: " . $request_path . " - This link should be updated to clean URL format");
            
            $clean_path = substr($request_path, 0, -4);
            error_log("Redirecting to clean path: " . $clean_path);
            header("Location: /$clean_path", true, 301);
            exit();
        }
        
        // Normalize request path to always have leading slash for consistent pattern matching
        if (empty($request_path)) {
            $request_path = '/';
        } elseif ($request_path[0] !== '/') {
            $request_path = '/' . $request_path;
        }
        
        // Parse request parameters internally
        $params = explode("/", $request_path);
        $full_path = $request_path;
        $static_routes_path = rtrim($request_path, '/');
        $static_routes_path = ltrim($static_routes_path, '/');
        
        error_log("After normalization:");
        error_log("  full_path: " . var_export($full_path, true));
        error_log("  static_routes_path: " . var_export($static_routes_path, true));
        error_log("  params: " . var_export($params, true));
        
        // Load core dependencies - these are almost always needed for routing
        error_log("Loading core dependencies...");
        try {
            PathHelper::requireOnce('includes/Globalvars.php');
            error_log("  ✓ Globalvars loaded");
        } catch (Exception $e) {
            error_log("  ✗ Failed to load Globalvars: " . $e->getMessage());
        }
        
        try {
            PathHelper::requireOnce('includes/SessionControl.php');
            error_log("  ✓ SessionControl loaded");
        } catch (Exception $e) {
            error_log("  ✗ Failed to load SessionControl: " . $e->getMessage());
        }
        
        try {
            PathHelper::requireOnce('includes/ThemeHelper.php');
            error_log("  ✓ ThemeHelper loaded");
        } catch (Exception $e) {
            error_log("  ✗ Failed to load ThemeHelper: " . $e->getMessage());
        }
        
        try {
            PathHelper::requireOnce('includes/PluginHelper.php');
            error_log("  ✓ PluginHelper loaded");
        } catch (Exception $e) {
            error_log("  ✗ Failed to load PluginHelper: " . $e->getMessage());
        }
        
        $settings = Globalvars::get_instance();
        $session = SessionControl::get_instance();
        error_log("Core objects instantiated");
        
        // Load THE theme's serve.php (only one theme active at a time)
        error_log("Loading theme serve.php...");
        ThemeHelper::includeThemeFile('serve.php');
        
        // Get theme directory for theme overrides (themes only, never plugins)
        $theme_template = $settings->get_setting('theme_template');
        error_log("Theme template setting: " . var_export($theme_template, true));
        
        $template_directory = null;
        if (ThemeHelper::themeExists($theme_template)) {
            // $template_directory will be absolute path like /var/www/html/theme/falcon
            $template_directory = PathHelper::getIncludePath('theme/'.$theme_template);
            error_log("Theme directory: " . var_export($template_directory, true));
        } else {
            error_log("Theme does not exist or is invalid");
        }

        // 1. Check for database-stored URL redirects
        if (self::checkUrlRedirects($static_routes_path, $settings)) {
            error_log("URL redirect found and handled - exiting");
            exit(); // Redirect handled
        }
        error_log("No URL redirects found");
        
        // 2. Check static routes (assets only)
        if ($route = self::matchRoute($full_path, $routes['static'] ?? [])) {
            error_log("Static route matched: " . var_export($route, true));
            error_log("Calling handleStaticRoute...");
            if (self::handleStaticRoute($route, $params, $template_directory)) {
                error_log("Static route handled successfully - exiting");
                exit();
            } else {
                error_log("Static route matched but handler failed - showing 404");
                // Route matched but handler failed - 404
                PathHelper::requireOnce('includes/LibraryFunctions.php');
                LibraryFunctions::display_404_page();
                exit();
            }
        }
        error_log("No static routes matched");
        
        // 3. Load and merge plugin routes (pull approach)
        error_log("=== STEP 3: Loading plugin routes ===");
        $plugin_routes = self::loadPluginRoutes();
        error_log("Plugin routes loaded: " . var_export($plugin_routes, true));
        
        if (!empty($plugin_routes)) {
            error_log("Merging plugin routes...");
            foreach ($plugin_routes as $type => $plugin_type_routes) {
                if (!isset($routes[$type])) {
                    $routes[$type] = [];
                }
                error_log("Merging {$type} routes: " . count($plugin_type_routes) . " routes");
                // Plugin routes go FIRST in each category - prepend instead of append
                $routes[$type] = array_merge($plugin_type_routes, $routes[$type]);
            }
        } else {
            error_log("No plugin routes found");
        }
        
        // 4. Check custom routes (complex logic)
        error_log("=== STEP 4: Checking custom routes ===");
        if (!empty($routes['custom'])) {
            error_log("Custom routes available: " . var_export(array_keys($routes['custom']), true));
            foreach ($routes['custom'] as $pattern => $handler) {
                error_log("Testing custom route pattern: " . var_export($pattern, true) . " against path: " . var_export($full_path, true));
                if (self::matchesPattern($pattern, $full_path)) {
                    error_log("Custom route matched - calling handler");
                    if ($handler($params, $settings, $session, $template_directory)) {
                        error_log("Custom route handler succeeded - exiting");
                        exit();
                    } else {
                        error_log("Custom route handler failed - showing 404");
                        // Route matched but handler failed - 404
                        PathHelper::requireOnce('includes/LibraryFunctions.php');
                        LibraryFunctions::display_404_page();
                        exit();
                    }
                }
            }
            error_log("No custom routes matched");
        } else {
            error_log("No custom routes available");
        }
        
        // 5. Check dynamic routes (unified content + simple)
        if ($route = self::matchRoute($full_path, $routes['dynamic'] ?? [])) {
            if (self::handleDynamicRoute($route, $params, $template_directory)) {
                exit();
            } else {
                // Route matched but handler failed - 404
                PathHelper::requireOnce('includes/LibraryFunctions.php');
                LibraryFunctions::display_404_page();
                exit();
            }
        }
        
        // 6. View directory fallback (automatic theme-aware view lookup)
        error_log("=== STEP 6: View directory fallback ===");
        // Try to find view file for any remaining paths
        $view_file = 'views/' . trim($request_path, '/') . '.php';
        error_log("Trying view fallback file: " . var_export($view_file, true));
        if (ThemeHelper::includeThemeFile($view_file)) {
            error_log("View fallback succeeded - exiting");
            $is_valid_page = true;
            exit();
        }
        error_log("View fallback failed");
        
        // 7. Allow plugins to add custom routes (backward compatibility)
        error_log("=== STEP 7: Legacy plugin route handler ===");
        // Check if global plugin route handler exists
        if (function_exists('handlePluginRoutes')) {
            error_log("Legacy handlePluginRoutes function exists - calling it");
            handlePluginRoutes($params);
        } else {
            error_log("No legacy handlePluginRoutes function found");
        }
        
        // 8. Final fallback - 404
        PathHelper::requireOnce('includes/LibraryFunctions.php');
        LibraryFunctions::display_404_page();
    }
    
    /**
     * Load routes from all active plugin serve.php files (pull approach)
     * 
     * This method discovers active plugins, includes their serve.php files,
     * and extracts route definitions without requiring plugins to register
     * themselves. Each plugin serve.php should simply define a $routes variable.
     * 
     * @return array Combined routes from all active plugins
     */
    public static function loadPluginRoutes() {
        $all_plugin_routes = ['static' => [], 'dynamic' => [], 'custom' => []];
        
        // Get active plugins
        if (!class_exists('PluginHelper')) {
            PathHelper::requireOnce('includes/PluginHelper.php');
        }
        
        try {
            $activePlugins = PluginHelper::getActivePlugins();
        } catch (Exception $e) {
            error_log("RouteHelper: Failed to get active plugins: " . $e->getMessage());
            return $all_plugin_routes;
        }
        
        foreach ($activePlugins as $pluginName => $pluginHelper) {
            $serve_file = $pluginHelper->getIncludePath('serve.php');
            
            if (file_exists($serve_file)) {
                try {
                    // Use output buffering to prevent any output from plugin files
                    ob_start();
                    
                    // Initialize routes variable
                    $routes = [];
                    
                    // Include the plugin serve.php file
                    include $serve_file;
                    
                    // Clean up any output
                    ob_end_clean();
                    
                    // Merge plugin routes
                    if (is_array($routes)) {
                        foreach ($routes as $type => $type_routes) {
                            if (isset($all_plugin_routes[$type]) && is_array($type_routes)) {
                                $all_plugin_routes[$type] = array_merge($all_plugin_routes[$type], $type_routes);
                            }
                        }
                    } else {
                        error_log("RouteHelper: Plugin '{$pluginName}' serve.php did not define routes array");
                    }
                    
                } catch (Exception $e) {
                    ob_end_clean();
                    error_log("RouteHelper: Failed to load routes from plugin '{$pluginName}': " . $e->getMessage());
                }
            }
        }
        
        return $all_plugin_routes;
    }
}