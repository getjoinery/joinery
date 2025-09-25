<?php
/**
 * StaticPageCache - High-performance static HTML caching for public pages
 *
 * This class handles all caching operations for the static page cache system.
 * It works in conjunction with RouteHelper to cache and serve static HTML versions
 * of public pages, dramatically improving performance for anonymous users.
 */
class StaticPageCache {
    private static $index = null;
    private static $index_path = null;
    private static $cache_dir = null;

    /**
     * Initialize cache paths (called once)
     */
    private static function init() {
        if (self::$cache_dir === null) {
            self::$cache_dir = PathHelper::getAbsolutePath('cache/static_pages/');
            self::$index_path = self::$cache_dir . 'index.json';

            // Create cache directory if it doesn't exist
            if (!is_dir(self::$cache_dir)) {
                // Use recursive creation with proper error handling
                // The 'true' parameter creates parent directories as needed
                if (!@mkdir(self::$cache_dir, 0755, true)) {
                    // Check if directory was created by another process (race condition)
                    if (!is_dir(self::$cache_dir)) {
                        error_log("StaticPageCache: Failed to create cache directory: " . self::$cache_dir . " - " . error_get_last()['message']);
                        self::$cache_dir = null; // Disable caching
                        return;
                    }
                }

                // Log successful creation
                error_log("StaticPageCache: Successfully created cache directory: " . self::$cache_dir);
            }

            // Auto-fix permissions if directory is not writable
            if (!is_writable(self::$cache_dir)) {
                // Try to fix permissions - use 755 for directories
                @chmod(self::$cache_dir, 0755);

                // If still not writable, log and continue without caching
                if (!is_writable(self::$cache_dir)) {
                    error_log("StaticPageCache: Cache directory not writable, caching disabled: " . self::$cache_dir);
                    self::$cache_dir = null; // Disable caching
                    return;
                }
            }
        }
    }

    /**
     * Load the cache index from disk
     */
    private static function loadIndex() {
        self::init();

        // If cache directory couldn't be initialized, return disabled config
        if (self::$cache_dir === null) {
            return ['_config' => ['enabled' => false]];
        }

        if (self::$index === null) {
            if (file_exists(self::$index_path)) {
                $content = file_get_contents(self::$index_path);
                self::$index = json_decode($content, true) ?? ['_config' => ['enabled' => true]];
            } else {
                self::$index = ['_config' => ['enabled' => true]];
            }
        }
        return self::$index;
    }

    /**
     * Save the cache index to disk (atomic operation)
     */
    private static function saveIndex() {
        self::init();

        // If cache directory couldn't be initialized, skip saving
        if (self::$cache_dir === null) {
            return;
        }

        if (self::$index !== null) {
            $temp = self::$index_path . '.tmp';
            $json = json_encode(self::$index, JSON_PRETTY_PRINT);
            if ($json === false) {
                throw new Exception("Failed to encode cache index to JSON");
            }

            if (@file_put_contents($temp, $json, LOCK_EX) === false) {
                throw new Exception("Failed to write cache index to temp file: " . $temp);
            }

            if (!@rename($temp, self::$index_path)) {
                @unlink($temp); // Clean up temp file
                throw new Exception("Failed to update cache index file: " . self::$index_path);
            }
        }
    }

    /**
     * Generate a cache key from URL and parameters
     * Uses simple, Apache-friendly filenames instead of hashes
     */
    private static function generateCacheKey($url, $params) {
        // Handle root path
        if ($url === '/' || $url === '') {
            $safe_name = 'index';
        } else {
            // Remove leading/trailing slashes and replace path separators
            $safe_name = trim($url, '/');
            // Replace slashes with underscores for filesystem safety
            $safe_name = str_replace('/', '_', $safe_name);
            // Replace other problematic characters
            $safe_name = preg_replace('/[^a-zA-Z0-9_\-]/', '-', $safe_name);
        }

        // Handle query parameters if present
        if (!empty($params)) {
            // Sort for consistency
            ksort($params);
            // Build parameter string
            $param_parts = [];
            foreach ($params as $key => $value) {
                // Sanitize parameter names and values
                $safe_key = preg_replace('/[^a-zA-Z0-9]/', '', $key);
                $safe_value = preg_replace('/[^a-zA-Z0-9]/', '', $value);
                $param_parts[] = $safe_key . '-' . $safe_value;
            }
            $safe_name .= '__' . implode('_', $param_parts);
        }

        // Truncate if too long (filesystem limit is usually 255 chars)
        // Leave room for .html extension
        if (strlen($safe_name) > 200) {
            // For very long URLs, fall back to hash just for those
            $safe_name = substr($safe_name, 0, 150) . '_' . substr(hash('sha256', $url . '?' . http_build_query($params)), 0, 16);
        }

        return $safe_name;
    }

    /**
     * Check if a URL is cached and return the file path if it exists
     * @return string|false Returns file path if cached, 'nostatic' if marked non-cacheable, or false if not cached
     */
    public static function checkCache($url, $params = []) {
        self::init();

        // If cache directory couldn't be initialized, disable caching
        if (self::$cache_dir === null) {
            return 'nostatic';
        }

        $index = self::loadIndex();

        // Check if caching is enabled
        if (!$index['_config']['enabled']) {
            return 'nostatic';
        }

        $hash = self::generateCacheKey($url, $params);

        // Check index status
        if (isset($index[$hash]) && is_array($index[$hash])) {
            $entry = $index[$hash];

            if ($entry['status'] === 'nostatic') {
                return 'nostatic';
            }
            if ($entry['status'] === 'cached') {
                // Determine file extension from index or guess from URL
                $extension = isset($entry['extension']) ? $entry['extension'] : '.html';
                $cache_file = self::$cache_dir . $hash . $extension;

                // Verify file still exists and return path
                if (file_exists($cache_file)) {
                    return $cache_file;
                }
                // File missing, update index
                unset($index[$hash]);
                self::$index = $index;  // Update the static property
                self::saveIndex();
            }
        }

        return false;  // Not cached
    }

    /**
     * Create a cache file for a URL
     */
    public static function createCache($url, $params, $content) {
        self::init();

        // If cache directory couldn't be initialized, skip caching
        if (self::$cache_dir === null) {
            return false;
        }

        $hash = self::generateCacheKey($url, $params);
        $url_with_params = $url;
        if (!empty($params)) {
            $url_with_params .= '?' . http_build_query($params);
        }

        // Determine appropriate file extension based on URL
        $extension = '.html'; // Default to HTML
        if (preg_match('/\.([a-z0-9]+)$/i', $url, $matches)) {
            $url_extension = strtolower($matches[1]);
            // Only use specific extensions we want to cache
            if (in_array($url_extension, ['txt', 'xml', 'json'])) {
                $extension = '.' . $url_extension;
            }
        }

        $file = self::$cache_dir . $hash . $extension;

        // Determine if this is HTML content that should get debug comments
        $is_html = ($extension === '.html' &&
                   (stripos(trim($content), '<!DOCTYPE') === 0 ||
                    stripos(trim($content), '<html') === 0 ||
                    (stripos(trim($content), '<!--') === 0 && stripos($content, '<html') !== false)));

        if ($is_html) {
            // HTML content - add debug comment
            // Trim any leading whitespace/newlines before the DOCTYPE
            $content = ltrim($content);

            // Insert comment after <head> tag for best compatibility
            $comment = "\n    <!-- Cached: {$url_with_params} -->";

            // Try to insert after <head> tag
            if (preg_match('/(<head[^>]*>)/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
                // Insert comment right after <head> tag
                $head_pos = $matches[0][1] + strlen($matches[0][0]);
                $content_with_comment = substr($content, 0, $head_pos) . $comment . substr($content, $head_pos);
            } else {
                // Fallback: if no <head> found, don't add comment at all to avoid breaking HTML
                $content_with_comment = $content;
            }
        } else {
            // Non-HTML content - preserve exactly as-is
            $content_with_comment = $content;
        }

        // Atomic write
        $temp = $file . '.tmp';
        if (@file_put_contents($temp, $content_with_comment, LOCK_EX) === false) {
            // Non-fatal - just log and continue without caching
            error_log("StaticPageCache: Failed to write cache file: " . $temp);
            return false;
        }

        if (!@rename($temp, $file)) {
            @unlink($temp); // Clean up temp file
            error_log("StaticPageCache: Failed to rename cache file: " . $temp . " to " . $file);
            return false;
        }

        // Update index
        try {
            $index = self::loadIndex();

            // Store with hash as key
            $index[$hash] = [
                'status' => 'cached',
                'url' => $url_with_params,
                'time' => time(),
                'extension' => $extension
            ];

            self::$index = $index;  // Update the static property
            self::saveIndex();
        } catch (Exception $e) {
            // Index update failed, but cache file exists - non-fatal
            error_log("StaticPageCache: Index update failed: " . $e->getMessage());
        }

        return true;
    }

    /**
     * Check if URL should be completely ignored (spam/malicious)
     * Returns true for URLs that should not be cached OR tracked
     */
    public static function shouldIgnore($request_path = '', $params = []) {
        // Check User-Agent for obvious spam/bots
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (empty($user_agent)) {
            return true; // No User-Agent = likely automated spam
        }

        $blocked_user_agents = [
            'phpstorm', 'xdebug', 'postman', 'insomnia',
            'nmap', 'nikto', 'sqlmap', 'dirb', 'gobuster', 'wfuzz',
            'bot', 'crawler', 'spider', 'scraper',
            'curl', 'wget', 'python-requests', 'libwww-perl'
        ];

        $user_agent_lower = strtolower($user_agent);
        foreach ($blocked_user_agents as $blocked) {
            if (strpos($user_agent_lower, $blocked) !== false) {
                return true;
            }
        }

        // Check for sensitive keywords in path
        $sensitive_keywords = ['phpinfo', 'phpmyadmin', 'adminer', 'wp-admin', 'wp-includes'];
        foreach ($sensitive_keywords as $keyword) {
            if (strpos(strtolower($request_path), $keyword) !== false) {
                return true;
            }
        }

        // Check for spam/malicious query parameters
        if (!empty($params)) {
            $spam_params = [
                'xdebug_session_start', 'xdebug_session_stop', 'xdebug_profile',
                'phpinfo', 'phpmyadmin', 'adminer', 'wp-admin', 'wp-includes',
                'union', 'select', 'insert', 'delete', 'drop', 'exec',
                'test', 'debug', 'admin', 'login', 'pass', 'passwd', 'password',
                'bot', 'crawler', 'spider', 'scan',
                '%3c', '%3e', '%22', '%27', 'script', 'alert', 'onload'
            ];

            foreach ($params as $key => $value) {
                $key_lower = strtolower($key);
                $value_lower = strtolower($value);

                foreach ($spam_params as $spam) {
                    if (strpos($key_lower, $spam) !== false || strpos($value_lower, $spam) !== false) {
                        return true;
                    }
                }

                // Check for suspicious values
                if (strlen($value) > 100 ||
                    preg_match('/[<>"\']/', $value) ||
                    preg_match('/%[0-9a-f]{2}/i', $value)) {
                    return true;
                }
            }
        }

        return false; // Not spam - process normally
    }

    /**
     * Check if current response should be cached
     * @param bool $detailed If true, returns array with detailed results instead of boolean
     */
    public static function shouldCache($request_path = '', $params = [], $content = '', $detailed = false) {
        $reasons = [];
        $passed = true;
        // 1. Check HTTP status - only cache successful responses
        $response_code = http_response_code();
        // If no response code has been set, assume 200 (PHP's default)
        if ($response_code === false) {
            $response_code = 200;
        }
        if ($response_code !== 200) {
            if ($detailed) {
                $reasons[] = "❌ HTTP status code is $response_code (must be 200)";
                $passed = false;
            } else {
                return false;
            }
        } else if ($detailed) {
            $reasons[] = '✅ HTTP status code is 200';
        }

        // 2. Check excluded URL patterns (truly dynamic/personalized pages)
        $excluded_patterns = [
            '/login',
            '/logout',
            '/register',
            '/reset-password',
            '/ajax/',
            '/api/',
            '/admin/',
            '/utils/',
            '/test/',
            '/account/',
            '/profile/',
            '/checkout/',
            '/cart/'
        ];

        $path_excluded = false;
        foreach ($excluded_patterns as $pattern) {
            if (strpos($request_path, $pattern) === 0) {
                if ($detailed) {
                    $reasons[] = "❌ Path matches excluded pattern: $pattern";
                    $passed = false;
                    $path_excluded = true;
                    break;
                } else {
                    return false;
                }
            }
        }
        if ($detailed && !$path_excluded) {
            $reasons[] = '✅ Path does not match any excluded patterns';
        }

        // 3. Check for sensitive keywords anywhere in the URL path
        $sensitive_keywords = ['phpinfo', 'phpmyadmin', 'adminer', 'wp-admin', 'wp-includes'];
        $keyword_found = false;
        foreach ($sensitive_keywords as $keyword) {
            if (strpos(strtolower($request_path), $keyword) !== false) {
                if ($detailed) {
                    $reasons[] = "❌ Path contains sensitive keyword: $keyword";
                    $passed = false;
                    $keyword_found = true;
                    break;
                } else {
                    return false;
                }
            }
        }
        if ($detailed && !$keyword_found) {
            $reasons[] = '✅ Path does not contain sensitive keywords';
        }

        // 4. Check User-Agent for bots and development tools
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Don't cache requests from suspicious/development User-Agents
        $blocked_user_agents = [
            // Development tools
            'phpstorm', 'xdebug', 'postman', 'insomnia',
            // Security scanners
            'nmap', 'nikto', 'sqlmap', 'dirb', 'gobuster', 'wfuzz',
            // Generic bots (legitimate crawlers usually identify themselves properly)
            'bot', 'crawler', 'spider', 'scraper',
            // Suspicious patterns
            'curl', 'wget', 'python-requests', 'libwww-perl'
        ];

        $user_agent_lower = strtolower($user_agent);
        $user_agent_blocked = false;
        foreach ($blocked_user_agents as $blocked) {
            if (strpos($user_agent_lower, $blocked) !== false) {
                if ($detailed) {
                    $reasons[] = "❌ User-Agent contains blocked pattern: $blocked";
                    $passed = false;
                    $user_agent_blocked = true;
                    break;
                } else {
                    return false;
                }
            }
        }

        // Don't cache requests with no User-Agent (often automated)
        if (empty($user_agent)) {
            if ($detailed) {
                $reasons[] = '❌ User-Agent is empty (often automated requests)';
                $passed = false;
                $user_agent_blocked = true;
            } else {
                return false;
            }
        }

        if ($detailed && !$user_agent_blocked) {
            $reasons[] = '✅ User-Agent is acceptable';
        }

        // 5. Check for known spam/malicious query parameters
        if (!empty($params)) {
            $spam_params = [
                // XDebug and development
                'xdebug_session_start', 'xdebug_session_stop', 'xdebug_profile',
                // Sensitive system info
                'phpinfo', 'phpmyadmin', 'adminer',
                // WordPress admin areas
                'wp-admin', 'wp-includes',
                // Common injection attempts
                'union', 'select', 'insert', 'delete', 'drop', 'exec',
                // Security scanning
                'test', 'debug', 'admin', 'login', 'pass', 'passwd', 'password',
                // Bot fingerprinting
                'bot', 'crawler', 'spider', 'scan',
                // Suspicious encoded content
                '%3c', '%3e', '%22', '%27', 'script', 'alert', 'onload'
            ];

            $spam_detected = false;
            foreach ($params as $key => $value) {
                $key_lower = strtolower($key);
                $value_lower = strtolower($value);

                // Check parameter names
                foreach ($spam_params as $spam) {
                    if (strpos($key_lower, $spam) !== false || strpos($value_lower, $spam) !== false) {
                        if ($detailed) {
                            $reasons[] = "❌ Query parameter contains spam/malicious pattern: $spam (in $key=$value)";
                            $passed = false;
                            $spam_detected = true;
                            break 2;
                        } else {
                            return false;
                        }
                    }
                }

                // Check for obviously encoded/suspicious values
                if (strlen($value) > 100) {
                    if ($detailed) {
                        $reasons[] = "❌ Query parameter value too long: $key (length: " . strlen($value) . ")";
                        $passed = false;
                        $spam_detected = true;
                        break;
                    } else {
                        return false;
                    }
                }

                if (preg_match('/[<>"\']/', $value)) {
                    if ($detailed) {
                        $reasons[] = "❌ Query parameter contains suspicious characters: $key=$value";
                        $passed = false;
                        $spam_detected = true;
                        break;
                    } else {
                        return false;
                    }
                }

                if (preg_match('/%[0-9a-f]{2}/i', $value)) {
                    if ($detailed) {
                        $reasons[] = "❌ Query parameter contains URL encoding: $key=$value";
                        $passed = false;
                        $spam_detected = true;
                        break;
                    } else {
                        return false;
                    }
                }
            }

            if ($detailed && !$spam_detected) {
                $reasons[] = '✅ Query parameters are acceptable';
            }
        } else if ($detailed) {
            $reasons[] = '✅ No query parameters to check';
        }

        // 6. Check headers for disqualifiers
        $headers = headers_list();

        foreach ($headers as $header) {
            // Don't cache redirects
            if (stripos($header, 'Location:') === 0) {
                if ($detailed) {
                    $reasons[] = '❌ Response contains a redirect';
                    $passed = false;
                } else {
                    return false;
                }
            }

            // Check if setting NEW cookies (not just passing existing session)
            if (stripos($header, 'Set-Cookie:') === 0) {
                // Check if it's just refreshing existing session vs creating new data
                if (strpos($header, 'PHPSESSID') === false) {
                    // Non-session cookie being set - don't cache
                    if ($detailed) {
                        $cookie_name = trim(substr($header, 11)); // Remove 'Set-Cookie: '
                        $reasons[] = "❌ Response sets non-session cookie: $cookie_name";
                        $passed = false;
                    } else {
                        return false;
                    }
                }
            }

            // Don't cache JSON/XML API responses
            if (stripos($header, 'Content-Type: application/json') === 0) {
                if ($detailed) {
                    $reasons[] = '❌ Content-Type is application/json';
                    $passed = false;
                } else {
                    return false;
                }
            }
            if (stripos($header, 'Content-Type: application/xml') === 0) {
                if ($detailed) {
                    $reasons[] = '❌ Content-Type is application/xml';
                    $passed = false;
                } else {
                    return false;
                }
            }
        }

        // 7. Check if content looks like HTML (headers may not be set yet)
        $is_html = false;

        // Check headers first
        foreach ($headers as $header) {
            if (stripos($header, 'Content-Type: text/html') === 0) {
                $is_html = true;
                break;
            }
        }

        // If no Content-Type header yet, check if content looks like HTML
        if (!$is_html && !empty($content)) {
            $trimmed = trim($content);
            if (stripos($trimmed, '<!DOCTYPE') === 0 ||
                stripos($trimmed, '<html') === 0 ||
                stripos($trimmed, '<!--') === 0 && stripos($trimmed, '<html') !== false) {
                $is_html = true;
            }
        }

        // Check if this is a cacheable file type based on URL extension
        $is_cacheable_file = $is_html; // HTML is always cacheable

        if (!$is_html) {
            // Allow specific file extensions to be cached
            $cacheable_extensions = ['txt', 'xml', 'json'];
            if (preg_match('/\.([a-z0-9]+)$/i', $request_path, $matches)) {
                $url_extension = strtolower($matches[1]);
                if (in_array($url_extension, $cacheable_extensions)) {
                    $is_cacheable_file = true;
                }
            }
        }

        if (!$is_cacheable_file) {
            if ($detailed) {
                $reasons[] = '❌ Content is not HTML and not a cacheable file type (.txt, .xml, .json)';
                $passed = false;
            } else {
                return false;
            }
        } else if ($detailed) {
            if ($is_html) {
                $reasons[] = '✅ Content is HTML';
            } else {
                $reasons[] = '✅ Non-HTML content but cacheable file type';
            }
        }

        // 8. Check for CSRF tokens in forms (don't cache protected forms)
        if (!empty($content) && strpos($content, '<form') !== false) {
            // Check for common CSRF token patterns
            if (strpos($content, 'csrf_token') !== false ||
                strpos($content, '_token') !== false ||
                strpos($content, 'authenticity_token') !== false ||
                strpos($content, 'form_token') !== false) {
                if ($detailed) {
                    $reasons[] = '❌ Page contains forms with CSRF tokens';
                    $passed = false;
                } else {
                    return false; // Don't cache pages with CSRF-protected forms
                }
            } else if ($detailed) {
                $reasons[] = '✅ Page contains forms but no CSRF tokens detected';
            }
        } else if ($detailed) {
            $reasons[] = '✅ No forms detected on page';
        }

        // Return results
        if ($detailed) {
            if ($passed) {
                $reasons[] = '✅ All caching criteria met';
            }
            return [
                'cacheable' => $passed,
                'reasons' => $reasons
            ];
        }

        // All checks passed - this is cacheable
        return true;
    }

    /**
     * Mark a URL as non-cacheable
     */
    public static function markAsNostatic($url, $params = []) {
        self::init();

        // If cache directory couldn't be initialized, skip operation
        if (self::$cache_dir === null) {
            return false;
        }

        $hash = self::generateCacheKey($url, $params);
        $index = self::loadIndex();

        // Store both status and URL info
        $url_with_params = $url;
        if (!empty($params)) {
            $url_with_params .= '?' . http_build_query($params);
        }

        $index[$hash] = [
            'status' => 'nostatic',
            'url' => $url_with_params,
            'time' => time()
        ];

        self::$index = $index;  // Update the static property
        self::saveIndex();
        return true;
    }

    /**
     * Invalidate (delete) a cached URL
     */
    public static function invalidateUrl($url, $params = []) {
        self::init();

        // If cache directory couldn't be initialized, skip operation
        if (self::$cache_dir === null) {
            return false;
        }

        $hash = self::generateCacheKey($url, $params);
        $index = self::loadIndex();

        // Get the file extension from index if available
        $extension = '.html'; // Default
        if (isset($index[$hash]['extension'])) {
            $extension = $index[$hash]['extension'];
        }

        $cache_file = self::$cache_dir . $hash . $extension;

        // Delete cache file if it exists
        if (file_exists($cache_file)) {
            unlink($cache_file);
        }

        // Remove from index
        unset($index[$hash]);
        self::$index = $index;  // Update the static property
        self::saveIndex();

        return true;
    }

    /**
     * Clear all cached files
     */
    public static function clearAll() {
        self::init();

        // If cache directory couldn't be initialized, return 0
        if (self::$cache_dir === null) {
            return 0;
        }

        // Get all cached files (any extension)
        $cache_files = glob(self::$cache_dir . '*.*');
        // Filter out temp files and index
        $cache_files = array_filter($cache_files, function($file) {
            $basename = basename($file);
            return $basename !== 'index.json' && !str_ends_with($basename, '.tmp');
        });

        $count = 0;
        foreach ($cache_files as $file) {
            if (unlink($file)) {
                $count++;
            }
        }

        // Clear index entries except config
        $index = self::loadIndex();
        $config = $index['_config'] ?? ['enabled' => true];
        self::$index = ['_config' => $config];
        self::saveIndex();

        return $count;
    }

    /**
     * Enable or disable caching
     */
    public static function setEnabled($enabled) {
        self::init();

        // If cache directory couldn't be initialized, skip operation
        if (self::$cache_dir === null) {
            return false;
        }

        $index = self::loadIndex();
        $index['_config']['enabled'] = (bool)$enabled;
        self::$index = $index;  // Update the static property
        self::saveIndex();
        return true;
    }

    /**
     * Get cache statistics
     */
    public static function getCacheStats() {
        self::init();

        // If cache directory couldn't be initialized, return disabled stats
        if (self::$cache_dir === null) {
            return [
                'enabled' => false,
                'file_count' => 0,
                'total_size' => 0,
                'total_size_mb' => 0,
                'cached_urls' => 0,
                'nostatic_urls' => 0
            ];
        }

        $files = glob(self::$cache_dir . '*.html');
        $total_size = 0;

        foreach ($files as $file) {
            $total_size += filesize($file);
        }

        $index = self::loadIndex();
        $nostatic_count = 0;
        $cached_count = 0;

        foreach ($index as $key => $value) {
            if ($key === '_config') continue;
            if ($value === 'nostatic') $nostatic_count++;
            if ($value === 'cached') $cached_count++;
        }

        return [
            'enabled' => $index['_config']['enabled'] ?? false,
            'file_count' => count($files),
            'total_size' => $total_size,
            'total_size_mb' => round($total_size / 1048576, 2),
            'cached_urls' => $cached_count,
            'nostatic_urls' => $nostatic_count
        ];
    }

    /**
     * Diagnose why a URL would or wouldn't be cached
     */
    public static function diagnoseCacheability($url, $fetch_content = false) {
        $result = [
            'url' => $url,
            'is_cacheable' => false,
            'reasons' => [],
            'status' => 'not_checked'
        ];

        // Parse URL
        $url_parts = parse_url($url);
        $path = $url_parts['path'] ?? '/';
        parse_str($url_parts['query'] ?? '', $params);

        // Check if already cached or marked nostatic
        $hash = self::generateCacheKey($path, $params);
        $index = self::loadIndex();

        if (isset($index[$hash]) && is_array($index[$hash])) {
            $entry = $index[$hash];
            if ($entry['status'] === 'cached') {
                $result['status'] = 'already_cached';
                $result['cache_file'] = self::$cache_dir . $hash . '.html';
                $result['reasons'][] = '✅ URL is already cached';
                $result['is_cacheable'] = true;
                return $result;
            } elseif ($entry['status'] === 'nostatic') {
                $result['status'] = 'marked_nostatic';
                $result['reasons'][] = '❌ URL is marked as non-cacheable (nostatic)';
                return $result;
            }
        }

        // If requested, fetch the content and do full analysis
        if ($fetch_content) {
            // Temporarily store original User-Agent
            $original_user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_USERAGENT, 'StaticPageCache/1.0 Diagnostic');

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);

            if ($response === false) {
                $result['reasons'][] = '❌ Failed to fetch URL';
                return $result;
            }

            $headers = substr($response, 0, $header_size);
            $content = substr($response, $header_size);

            // Set up environment to simulate the actual request
            $_SERVER['HTTP_USER_AGENT'] = 'StaticPageCache/1.0 Diagnostic';

            // Set response code for shouldCache() to read
            http_response_code($http_code);

            // Now test using the actual shouldCache() logic with detailed results
            $detailed_result = self::shouldCache($path, $params, $content, true);

            // Restore original User-Agent
            $_SERVER['HTTP_USER_AGENT'] = $original_user_agent;

            $result['is_cacheable'] = $detailed_result['cacheable'];
            $result['reasons'] = $detailed_result['reasons'];
            $result['status'] = $detailed_result['cacheable'] ? 'cacheable' : 'not_cacheable';
        } else {
            // For partial check without fetching content, test what we can with detailed mode
            // We can't test headers/content, but we can test path and parameters
            $partial_result = self::shouldCache($path, $params, '', true);

            $result['is_cacheable'] = $partial_result['cacheable'];
            $result['reasons'] = $partial_result['reasons'];
            $result['reasons'][] = 'ℹ️ Partial check only - some criteria require fetching content';
            $result['status'] = $partial_result['cacheable'] ? 'partial_check' : 'not_cacheable';
        }

        return $result;
    }

    /**
     * Get list of cached URLs (for admin interface)
     */
    public static function getCachedUrls($limit = 100) {
        self::init();

        // If cache directory couldn't be initialized, return empty array
        if (self::$cache_dir === null) {
            return [];
        }

        $urls = [];
        $files = glob(self::$cache_dir . '*.html');

        foreach (array_slice($files, 0, $limit) as $file) {
            // Read first line to get URL from comment
            $handle = fopen($file, 'r');
            $first_line = fgets($handle);
            fclose($handle);

            if (preg_match('/<!-- Cached: (.+?) -->/', $first_line, $matches)) {
                $urls[] = [
                    'url' => $matches[1],
                    'file' => basename($file),
                    'size' => filesize($file),
                    'modified' => filemtime($file)
                ];
            }
        }

        return $urls;
    }

    /**
     * Get all recent URLs (both cached and non-cacheable)
     */
    public static function getRecentUrls($limit = 50) {
        self::init();

        // If cache directory couldn't be initialized, return empty array
        if (self::$cache_dir === null) {
            return [];
        }

        $urls = [];
        $index = self::getIndex();

        // Get all cached files with their URLs
        $files = glob(self::$cache_dir . '*.html');
        $file_times = [];

        // Sort files by modification time
        foreach ($files as $file) {
            $file_times[$file] = filemtime($file);
        }
        arsort($file_times);

        // Process cached files
        foreach (array_slice($file_times, 0, $limit, true) as $file => $mtime) {
            $hash = basename($file, '.html');

            // Read first line to get URL from comment
            $handle = fopen($file, 'r');
            $first_line = fgets($handle);
            fclose($handle);

            if (preg_match('/<!-- Cached: (.+?) -->/', $first_line, $matches)) {
                $status = isset($index[$hash]) ? $index[$hash] : 'cached';
                $urls[] = [
                    'url' => $matches[1],
                    'status' => $status,
                    'size' => filesize($file),
                    'modified' => $mtime,
                    'hash' => substr($hash, 0, 8)
                ];
            }
        }

        // Note: Non-cached URLs (nostatic) don't have files, so we can't show them
        // unless we implement a separate access log

        return $urls;
    }

    /**
     * Log URL access (for tracking all URLs, not just cached ones)
     */
    public static function logUrlAccess($path, $query, $status) {
        self::init();

        if (self::$cache_dir === null) {
            return;
        }

        $log_file = self::$cache_dir . 'access_log.json';
        $log = [];

        // Read existing log
        if (file_exists($log_file)) {
            $content = file_get_contents($log_file);
            if ($content) {
                $log = json_decode($content, true) ?: [];
            }
        }

        // Build full URL
        $url = $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        // Add new entry at the beginning
        array_unshift($log, [
            'url' => $url,
            'status' => $status,
            'time' => time()
        ]);

        // Keep only last 100 entries
        $log = array_slice($log, 0, 100);

        // Save log
        file_put_contents($log_file, json_encode($log, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * Get recent URL access log
     */
    public static function getAccessLog($limit = 50) {
        self::init();

        if (self::$cache_dir === null) {
            return [];
        }

        $log_file = self::$cache_dir . 'access_log.json';

        if (!file_exists($log_file)) {
            return [];
        }

        $content = file_get_contents($log_file);
        if (!$content) {
            return [];
        }

        $log = json_decode($content, true) ?: [];

        return array_slice($log, 0, $limit);
    }
}