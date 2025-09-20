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
                // Create with 755 permissions (owner can write, others can read/execute)
                // When created by www-data, this gives Apache full access
                if (!@mkdir(self::$cache_dir, 0755, true)) {
                    error_log("StaticPageCache: Failed to create cache directory: " . self::$cache_dir);
                    self::$cache_dir = null; // Disable caching
                    return;
                }
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
     */
    private static function generateCacheKey($url, $params) {
        // Sort parameters for consistent hashing
        ksort($params);

        // Build cache key from full URL including parameters
        $cache_string = $url;
        if (!empty($params)) {
            $cache_string .= '?' . http_build_query($params);
        }

        // Return first 16 chars of SHA-256 hash
        return substr(hash('sha256', $cache_string), 0, 16);
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
        $file = self::$cache_dir . $hash . '.html';

        // Check index status
        if (isset($index[$hash]) && is_array($index[$hash])) {
            $entry = $index[$hash];

            if ($entry['status'] === 'nostatic') {
                return 'nostatic';
            }
            if ($entry['status'] === 'cached') {
                // Verify file still exists and return path
                if (file_exists($file)) {
                    return $file;  // Return the actual file path
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
        $file = self::$cache_dir . $hash . '.html';

        // Add URL comment after DOCTYPE for debugging
        $url_with_params = $url;
        if (!empty($params)) {
            $url_with_params .= '?' . http_build_query($params);
        }

        // Insert comment after DOCTYPE if present, otherwise at the beginning
        $comment = "<!-- Cached: {$url_with_params} -->";
        if (preg_match('/^(<!DOCTYPE[^>]*>)/i', $content, $matches)) {
            // Insert comment after DOCTYPE
            $content_with_comment = $matches[1] . "\n" . $comment . "\n" . substr($content, strlen($matches[1]));
        } else {
            // No DOCTYPE found, just prepend the comment
            $content_with_comment = $comment . "\n" . $content;
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

            // Store both status and URL info
            $index[$hash] = [
                'status' => 'cached',
                'url' => $url_with_params,
                'time' => time()
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
     * Check if current response should be cached
     */
    public static function shouldCache($request_path = '', $params = [], $content = '') {
        // 1. Check HTTP status - only cache successful responses
        $response_code = http_response_code();
        // If no response code has been set, assume 200 (PHP's default)
        if ($response_code === false) {
            $response_code = 200;
        }
        if ($response_code !== 200) {
            return false;
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

        foreach ($excluded_patterns as $pattern) {
            if (strpos($request_path, $pattern) === 0) {
                return false;
            }
        }

        // 3. Check headers for disqualifiers
        $headers = headers_list();

        foreach ($headers as $header) {
            // Don't cache redirects
            if (stripos($header, 'Location:') === 0) return false;

            // Check if setting NEW cookies (not just passing existing session)
            if (stripos($header, 'Set-Cookie:') === 0) {
                // Check if it's just refreshing existing session vs creating new data
                if (strpos($header, 'PHPSESSID') === false) {
                    // Non-session cookie being set - don't cache
                    return false;
                }
            }

            // Don't cache JSON/XML API responses
            if (stripos($header, 'Content-Type: application/json') === 0) return false;
            if (stripos($header, 'Content-Type: application/xml') === 0) return false;
        }

        // 4. Check if content looks like HTML (headers may not be set yet)
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

        if (!$is_html) {
            return false;
        }

        // 5. Check for CSRF tokens in forms (don't cache protected forms)
        if (!empty($content) && strpos($content, '<form') !== false) {
            // Check for common CSRF token patterns
            if (strpos($content, 'csrf_token') !== false ||
                strpos($content, '_token') !== false ||
                strpos($content, 'authenticity_token') !== false ||
                strpos($content, 'form_token') !== false) {
                return false; // Don't cache pages with CSRF-protected forms
            }
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
        $file = self::$cache_dir . $hash . '.html';

        if (file_exists($file)) {
            unlink($file);
        }

        // Update index
        $index = self::loadIndex();
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

        $files = glob(self::$cache_dir . '*.html');
        $count = 0;

        foreach ($files as $file) {
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

        if (isset($index[$hash])) {
            if ($index[$hash] === 'cached') {
                $result['status'] = 'already_cached';
                $result['cache_file'] = self::$cache_dir . $hash . '.html';
                $result['reasons'][] = '✅ URL is already cached';
                $result['is_cacheable'] = true;
                return $result;
            } elseif ($index[$hash] === 'nostatic') {
                $result['status'] = 'marked_nostatic';
                $result['reasons'][] = '❌ URL is marked as non-cacheable (nostatic)';
                return $result;
            }
        }

        // Check excluded patterns
        $excluded_patterns = [
            '/login', '/logout', '/register', '/reset-password',
            '/ajax/', '/api/', '/admin/', '/utils/', '/test/',
            '/account/', '/profile/', '/checkout/', '/cart/'
        ];

        foreach ($excluded_patterns as $pattern) {
            if (strpos($path, $pattern) === 0) {
                $result['reasons'][] = "❌ Path matches excluded pattern: $pattern";
                return $result;
            }
        }
        $result['reasons'][] = '✅ Path does not match any excluded patterns';

        // If requested, fetch the content and do full analysis
        if ($fetch_content) {
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

            // Check HTTP status
            if ($http_code !== 200) {
                $result['reasons'][] = "❌ HTTP status code is $http_code (must be 200)";
                return $result;
            }
            $result['reasons'][] = '✅ HTTP status code is 200';

            // Check Content-Type
            $is_html = false;
            if (preg_match('/Content-Type:\s*text\/html/i', $headers)) {
                $is_html = true;
                $result['reasons'][] = '✅ Content-Type is text/html';
            } elseif (!empty($content)) {
                $trimmed = trim($content);
                if (stripos($trimmed, '<!DOCTYPE') === 0 ||
                    stripos($trimmed, '<html') === 0) {
                    $is_html = true;
                    $result['reasons'][] = '✅ Content appears to be HTML based on structure';
                }
            }

            if (!$is_html) {
                $result['reasons'][] = '❌ Content is not HTML';
                return $result;
            }

            // Check for redirects
            if (preg_match('/Location:/i', $headers)) {
                $result['reasons'][] = '❌ Response contains a redirect';
                return $result;
            }

            // Check for cookies (other than session)
            if (preg_match_all('/Set-Cookie:\s*([^;]+)/i', $headers, $matches)) {
                $non_session_cookies = [];
                foreach ($matches[1] as $cookie) {
                    if (strpos($cookie, 'PHPSESSID') === false) {
                        $non_session_cookies[] = $cookie;
                    }
                }
                if (!empty($non_session_cookies)) {
                    $result['reasons'][] = '❌ Response sets non-session cookies: ' . implode(', ', $non_session_cookies);
                    return $result;
                }
            }

            // Check for CSRF tokens
            if (strpos($content, '<form') !== false) {
                if (strpos($content, 'csrf_token') !== false ||
                    strpos($content, '_token') !== false ||
                    strpos($content, 'authenticity_token') !== false ||
                    strpos($content, 'form_token') !== false) {
                    $result['reasons'][] = '❌ Page contains forms with CSRF tokens';
                    return $result;
                }
                $result['reasons'][] = '⚠️ Page contains forms but no CSRF tokens detected';
            }

            $result['reasons'][] = '✅ All caching criteria met';
            $result['is_cacheable'] = true;
            $result['status'] = 'cacheable';
        } else {
            $result['reasons'][] = 'ℹ️ Full content analysis not performed (fetch_content = false)';
            $result['status'] = 'partial_check';
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