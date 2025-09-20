# Static Page Caching Specification

## Overview
Implement a static HTML page caching system for public (non-authenticated) pages to dramatically improve performance by serving pre-rendered HTML files directly, bypassing PHP processing entirely.

## Goals
- Serve cached public pages with minimal overhead
- Transparent caching that requires no changes to existing code
- Simple cache invalidation mechanism
- Easy management through admin interface

## Technical Design

### Cache Storage Structure
```
/cache/static_pages/
├── index.json                   # Cache index and configuration
├── a5f3c6b7d9e2f4b8.html
├── b8d2e4f9c1d5a2f7.html
└── c2f4d8a1b7e9f5d2.html
```

### Index File

#### Index File Format (`index.json`)
```json
{
  "_config": {
    "enabled": true
  },
  "a5f3c6b7d9e2f4b8": "cached",
  "b8d2e4f9c1d5a2f7": "nostatic",
  "c2f4d8a1b7e9f5d2": "cached"
}
```

The index tracks cache status:
- `"cached"` - File exists and should be served
- `"nostatic"` - This URL should never be cached (e.g., contains user-specific content)
- Missing entry - Not yet evaluated, will be checked on first access

### Cache Key Generation

Uses SHA-256 truncated to 16 hexadecimal characters for cache filenames.

```php
// Generate cache key from URL and parameters
$cache_string = $url;
if (!empty($params)) {
    $cache_string .= '?' . http_build_query($params);
}

// Return first 16 chars of SHA-256 hash
$cache_key = substr(hash('sha256', $cache_string), 0, 16);
// Example output: "a5f3c6b7d9e2f4b8"
```

- **16 hex characters** = 64 bits of entropy (sufficient for millions of URLs)
- **Consistent length** for all cache files
- **No special characters** - only 0-9 and a-f

### Cache File Naming
- Format: `[hash].html`
- Simple, direct naming with no versioning
- Cache invalidation simply deletes the file
- New cache overwrites existing file

### Integration Points

#### RouteHelper Integration - Single Point of Control

RouteHelper handles all caching logic in `processRoutes()`:

```php
// Early in processRoutes(), for non-authenticated GET requests only
if (!SessionControl::get_instance()->is_logged_in() && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $cache_status = StaticPageCache::checkCache($request_path, $_GET);

    if ($cache_status === 'cached') {
        // Serve from cache
        $file = StaticPageCache::getCacheFile($request_path, $_GET);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Cache: HIT');
        readfile($file);
        exit();
    } elseif ($cache_status === 'nostatic') {
        // Continue normal processing, never cache this URL
    } elseif ($cache_status === 'not_cached') {
        // Continue processing, but capture output for potential caching
        ob_start();
        // ... normal route processing ...

        // At the end, check if we should cache
        $content = ob_get_contents();
        if (StaticPageCache::shouldCache($request_path, $_GET, $content)) {
            StaticPageCache::createCache($request_path, $_GET, $content);
        }
        ob_end_flush();
    }
}
```

No cache writing logic needed anywhere else - RouteHelper handles everything on first access.

### Cacheable Page Criteria

StaticPageCache determines cacheability by checking:
1. **Request conditions** (checked before processing):
   - GET request method
   - No active session (not logged in)
   - Not in excluded patterns list

2. **Response conditions** (checked after processing):
   - HTTP status 200
   - No Location header (not a redirect)
   - Content-Type is text/html
   - No user-specific content detected

If a page fails cacheability checks, it's marked as `"nostatic"` in the index to avoid rechecking.

### Cache Management

#### Cache Invalidation (Simple)
When content is edited or theme changes:
1. Delete the cached file (or mark as "expired" in index)
2. Next access will automatically regenerate it via RouteHelper

#### Manual Controls
- "Clear Cache" button - Deletes all .html files (they regenerate on next access)
- "Disable Cache" - Sets enabled=false in index (bypasses all caching)
- Mark URL as "nostatic" - Prevents specific URLs from being cached

### Configuration

All configuration is in the `_config` key of index.json:
- `enabled` - Master on/off switch
- No other configuration needed - everything else is automatic

Benefits:
- Single file read gets both config and cache status
- No database lookups
- No complex settings to manage

### Excluded Patterns
Never cache URLs matching:
- `/admin/*`
- `/login`
- `/logout`
- `/register`
- `/api/*`
- `/ajax/*`
- `/utils/*`
- Any URL with POST data
- Any URL when user is authenticated

### Performance Considerations

#### Memory Usage
- Stream file directly to output (no full file load)
- Use `readfile()` for efficient serving

## Complete Implementation Code

### StaticPageCache Class (/includes/StaticPageCache.php)

```php
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
            $base = PathHelper::getAbsolutePath('');
            self::$cache_dir = $base . '/cache/static_pages/';
            self::$index_path = self::$cache_dir . 'index.json';

            // Create cache directory if it doesn't exist
            if (!is_dir(self::$cache_dir)) {
                if (!@mkdir(self::$cache_dir, 0755, true)) {
                    throw new Exception("Failed to create cache directory: " . self::$cache_dir .
                                      " - Please create manually and ensure web server can write to it.");
                }
            }

            // Verify directory is writable
            if (!is_writable(self::$cache_dir)) {
                throw new Exception("Cache directory is not writable: " . self::$cache_dir .
                                  " - Please run: chmod 755 " . self::$cache_dir);
            }
        }
    }

    /**
     * Load the cache index from disk
     */
    private static function loadIndex() {
        self::init();
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
        $index = self::loadIndex();

        // Check if caching is enabled
        if (!$index['_config']['enabled']) {
            return 'nostatic';
        }

        $hash = self::generateCacheKey($url, $params);
        $file = self::$cache_dir . $hash . '.html';

        // Check index status
        if (isset($index[$hash])) {
            if ($index[$hash] === 'nostatic') {
                return 'nostatic';
            }
            if ($index[$hash] === 'cached') {
                // Verify file still exists and return path
                if (file_exists($file)) {
                    return $file;  // Return the actual file path
                }
                // File missing, update index
                unset($index[$hash]);
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
        $hash = self::generateCacheKey($url, $params);
        $file = self::$cache_dir . $hash . '.html';

        // Add URL comment for debugging
        $url_with_params = $url;
        if (!empty($params)) {
            $url_with_params .= '?' . http_build_query($params);
        }
        $content_with_comment = "<!-- Cached: {$url_with_params} -->\n" . $content;

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
            $index[$hash] = 'cached';
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
        if (http_response_code() !== 200) return false;

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
            if (strpos($request_path, $pattern) === 0) return false;
        }

        // 3. Check headers for disqualifiers
        $headers = headers_list();
        $is_html = false;

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

            // Verify it's HTML content
            if (stripos($header, 'Content-Type: text/html') === 0) {
                $is_html = true;
            }

            // Don't cache JSON/XML API responses
            if (stripos($header, 'Content-Type: application/json') === 0) return false;
            if (stripos($header, 'Content-Type: application/xml') === 0) return false;
        }

        // 4. Must be HTML
        if (!$is_html) return false;

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
        $hash = self::generateCacheKey($url, $params);
        $index = self::loadIndex();
        $index[$hash] = 'nostatic';
        self::saveIndex();
        return true;
    }

    /**
     * Invalidate (delete) a cached URL
     */
    public static function invalidateUrl($url, $params = []) {
        self::init();
        $hash = self::generateCacheKey($url, $params);
        $file = self::$cache_dir . $hash . '.html';

        if (file_exists($file)) {
            unlink($file);
        }

        // Update index
        $index = self::loadIndex();
        unset($index[$hash]);
        self::saveIndex();

        return true;
    }

    /**
     * Clear all cached files
     */
    public static function clearAll() {
        self::init();
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
        $index = self::loadIndex();
        $index['_config']['enabled'] = (bool)$enabled;
        self::saveIndex();
        return true;
    }

    /**
     * Get cache statistics
     */
    public static function getCacheStats() {
        self::init();
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
     * Get list of cached URLs (for admin interface)
     */
    public static function getCachedUrls($limit = 100) {
        self::init();
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
}
```

### RouteHelper Modifications

Add to RouteHelper::processRoutes() method at appropriate locations:

```php
// Add at the beginning of processRoutes(), after line ~1050 (after core dependencies loaded)
// STATIC PAGE CACHE CHECK - For non-authenticated users only
$cache_status = null;
$cache_buffer_started = false;

if (class_exists('StaticPageCache')) {
    PathHelper::requireOnce('includes/StaticPageCache.php');
}

if (!SessionControl::get_instance()->is_logged_in() && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $cache_result = StaticPageCache::checkCache($request_path, $_GET);

    if ($cache_result === 'nostatic') {
        // This URL should never be cached, continue normal processing
    } elseif ($cache_result !== false) {
        // Random invalidation to keep all pages fresh
        // 1% chance = ~100 page views average before refresh
        // Adjust the 100 to change freshness (50 = more fresh, 200 = less fresh)
        if (rand(1, 100) === 1) {
            // Invalidate this cache and regenerate
            StaticPageCache::invalidateUrl($request_path, $_GET);
            // Continue with normal processing to regenerate
            ob_start();
            $cache_buffer_started = true;
        } else {
            // Serve the cached version
            header('Content-Type: text/html; charset=utf-8');
            header('Content-Length: ' . filesize($cache_result));
            header('X-Cache: HIT');
            readfile($cache_result);
            exit();
        }
    } else {
        // Not cached yet - start buffering for potential caching
        // Note: This works fine with FormWriter's ob_start/ob_get_clean pairs
        // since those are self-contained and return strings. Nested output
        // buffering is handled correctly by PHP.
        ob_start();
        $cache_buffer_started = true;
    }
}

// ... existing route processing code continues ...

// Add at the very end of processRoutes(), before the final 404 handler (around line ~1270)
// STATIC PAGE CACHE CREATION - Save cache if appropriate
if ($cache_buffer_started && $cache_result === false) {
    // Check if we should cache this response
    $content = ob_get_contents();
    if (StaticPageCache::shouldCache($request_path, $_GET, $content)) {
        StaticPageCache::createCache($request_path, $_GET, $content);
    } else {
        // Mark as non-cacheable to avoid rechecking
        StaticPageCache::markAsNostatic($request_path, $_GET);
    }
    ob_end_flush();
}
```

### Admin Interface (/adm/admin_static_cache.php)

```php
<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/AdminPage.php');
PathHelper::requireOnce('includes/SessionControl.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/StaticPageCache.php');

$session = SessionControl::get_instance();
$session->check_permission(9); // Super admin only for cache management
$session->set_return();

$page = new AdminPage();
$settings = Globalvars::get_instance();

// Handle form actions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'enable':
                StaticPageCache::setEnabled(true);
                $message = 'Static caching has been enabled.';
                break;

            case 'disable':
                StaticPageCache::setEnabled(false);
                $message = 'Static caching has been disabled.';
                break;

            case 'clear_all':
                $count = StaticPageCache::clearAll();
                $message = "Cleared {$count} cached files.";
                break;

            case 'invalidate_url':
                $url = LibraryFunctions::fetch_variable('url', '', 1, '');
                if ($url) {
                    $url_parts = parse_url($url);
                    $path = $url_parts['path'] ?? '/';
                    parse_str($url_parts['query'] ?? '', $params);
                    StaticPageCache::invalidateUrl($path, $params);
                    $message = "Invalidated cache for: {$url}";
                } else {
                    $error = "Please provide a URL to invalidate.";
                }
                break;

            case 'mark_nostatic':
                $url = LibraryFunctions::fetch_variable('url', '', 1, '');
                if ($url) {
                    $url_parts = parse_url($url);
                    $path = $url_parts['path'] ?? '/';
                    parse_str($url_parts['query'] ?? '', $params);
                    StaticPageCache::markAsNostatic($path, $params);
                    $message = "Marked as non-cacheable: {$url}";
                } else {
                    $error = "Please provide a URL to mark as non-cacheable.";
                }
                break;
        }
    }
}

// Get cache statistics
$stats = StaticPageCache::getCacheStats();
$cached_urls = StaticPageCache::getCachedUrls(50);

// Display admin page
$page->admin_header(array(
    'menu-id' => 'system-cache',
    'page_title' => 'Static Cache Management',
    'readable_title' => 'Static Cache Management',
    'breadcrumbs' => array(
        'System' => '/admin/admin_system',
        'Cache Management' => '',
    ),
    'session' => $session,
));

// Display messages
if ($message) {
    echo '<div class="alert alert-success alert-dismissible fade show" role="alert">';
    echo htmlspecialchars($message);
    echo '<button type="button" class="close" data-dismiss="alert">&times;</button>';
    echo '</div>';
}
if ($error) {
    echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">';
    echo htmlspecialchars($error);
    echo '<button type="button" class="close" data-dismiss="alert">&times;</button>';
    echo '</div>';
}
<!-- Cache Statistics -->
<div class="row">
    <div class="col-12">
        <h5 class="mb-3">Cache Statistics</h5>
        <div class="row">
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body text-center">
                        <h6>Status</h6>
                        <p class="h4">
                            <?php if ($stats['enabled']): ?>
                                <span class="badge badge-success">Enabled</span>
                            <?php else: ?>
                                <span class="badge badge-danger">Disabled</span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body text-center">
                        <h6>Cached Files</h6>
                        <p class="h4"><?php echo number_format($stats['file_count']); ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body text-center">
                        <h6>Cache Size</h6>
                        <p class="h4"><?php echo $stats['total_size_mb']; ?> MB</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body text-center">
                        <h6>Non-Cacheable URLs</h6>
                        <p class="h4"><?php echo number_format($stats['nostatic_urls']); ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Cache Controls -->
<div class="row mt-4">
    <div class="col-12">
        <h5 class="mb-3">Cache Controls</h5>
        <div class="card">
            <div class="card-body">
                <?php
                $formwriter = LibraryFunctions::get_formwriter_object('cache_controls', 'admin');
                echo $formwriter->begin_form('cache_controls', 'POST', $_SERVER['PHP_SELF']);
                ?>
                <div class="btn-group" role="group">
                    <?php if ($stats['enabled']): ?>
                        <button type="submit" name="action" value="disable" class="btn btn-warning">
                            Disable Cache
                        </button>
                    <?php else: ?>
                        <button type="submit" name="action" value="enable" class="btn btn-success">
                            Enable Cache
                        </button>
                    <?php endif; ?>
                    <button type="submit" name="action" value="clear_all" class="btn btn-danger"
                            onclick="return confirm('Clear all cached files? They will regenerate on next access.')">
                        Clear All Cache
                    </button>
                </div>
                <?php echo $formwriter->end_form(); ?>
            </div>
        </div>
    </div>
</div>

<!-- URL Management -->
<div class="row mt-4">
    <div class="col-md-6">
        <h5 class="mb-3">Invalidate Specific URL</h5>
        <div class="card">
            <div class="card-body">
                <?php
                $formwriter = LibraryFunctions::get_formwriter_object('invalidate_form', 'admin');

                $validation_rules = array();
                $validation_rules['url']['required']['value'] = 'true';
                echo $formwriter->set_validate($validation_rules);

                echo $formwriter->begin_form('invalidate_form', 'POST', $_SERVER['PHP_SELF']);
                echo $formwriter->textinput('URL to invalidate', 'url', 'form-control', 100, '',
                                          '/page/about?param=value', 255, 'Enter the URL path to remove from cache');
                echo $formwriter->hiddeninput('action', 'invalidate_url');
                echo $formwriter->start_buttons();
                echo $formwriter->new_form_button('Invalidate Cache', 'btn btn-primary');
                echo $formwriter->end_buttons();
                echo $formwriter->end_form();
                ?>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <h5 class="mb-3">Mark URL as Non-Cacheable</h5>
        <div class="card">
            <div class="card-body">
                <?php
                $formwriter = LibraryFunctions::get_formwriter_object('nostatic_form', 'admin');

                $validation_rules = array();
                $validation_rules['url']['required']['value'] = 'true';
                echo $formwriter->set_validate($validation_rules);

                echo $formwriter->begin_form('nostatic_form', 'POST', $_SERVER['PHP_SELF']);
                echo $formwriter->textinput('URL to exclude', 'url', 'form-control', 100, '',
                                          '/page/dynamic', 255, 'Enter the URL path to exclude from caching');
                echo $formwriter->hiddeninput('action', 'mark_nostatic');
                echo $formwriter->start_buttons();
                echo $formwriter->new_form_button('Mark as Non-Cacheable', 'btn btn-info');
                echo $formwriter->end_buttons();
                echo $formwriter->end_form();
                ?>
            </div>
        </div>
    </div>
</div>

<!-- Cached URLs List -->
<div class="row mt-4">
    <div class="col-12">
        <h5 class="mb-3">Recently Cached URLs</h5>
        <?php
        // Set up table
        $headers = array("URL", "Size", "Cached", "Actions");

        $pager = new Pager(array('numrecords' => count($cached_urls), 'numperpage' => 50));
        $table_options = array(
            'title' => 'Cached URLs (showing first 50)',
            'search_on' => FALSE
        );

        $page->tableheader($headers, $table_options, $pager);

        // Display rows
        foreach ($cached_urls as $item) {
            $rowvalues = array();

            // URL column with link
            $url_html = '<a href="' . htmlspecialchars($item['url']) . '" target="_blank">' .
                       htmlspecialchars($item['url']) . '</a>';
            array_push($rowvalues, $url_html);

            // Size column
            array_push($rowvalues, round($item['size'] / 1024, 1) . ' KB');

            // Cached time column
            array_push($rowvalues, date('Y-m-d H:i', $item['modified']));

            // Actions column
            $action_form = '<form method="post" style="display: inline;">
                           <input type="hidden" name="url" value="' . htmlspecialchars($item['url']) . '">
                           <button type="submit" name="action" value="invalidate_url"
                                   class="btn btn-sm btn-warning">Invalidate</button>
                           </form>';
            array_push($rowvalues, $action_form);

            $page->disprow($rowvalues);
        }

        $page->endtable($pager);
        ?>
    </div>
</div>

<?php $page->admin_footer(); ?>
```

### Visitor Tracking Method (add to /data/visitor_events_class.php)

```php
// Add this static method to the VisitorEvent class
public static function recordPageVisit($page) {
    try {
        // Don't track admin pages or Ajax requests
        if (strpos($page, '/admin/') === 0 || strpos($page, '/ajax/') === 0) {
            return;
        }

        $visitor_event = new VisitorEvent(NULL);

        // Set visitor ID from cookie or generate new one
        $visitor_id = $_COOKIE['visitor_id'] ?? null;
        if (!$visitor_id) {
            $visitor_id = substr(md5(uniqid(mt_rand(), true)), 0, 20);
            setcookie('visitor_id', $visitor_id, time() + (365 * 24 * 60 * 60), '/');
        }

        $visitor_event->set('vse_visitor_id', $visitor_id);

        // Set user ID if logged in
        $session = SessionControl::get_instance();
        if ($session->is_logged_in()) {
            $visitor_event->set('vse_usr_user_id', $_SESSION['user_id']);
        }

        // Set tracking data
        $visitor_event->set('vse_type', 1); // 1 = page view
        $visitor_event->set('vse_ip', $_SERVER['REMOTE_ADDR'] ?? '');
        $visitor_event->set('vse_page', $page);
        $visitor_event->set('vse_referrer', $_SERVER['HTTP_REFERER'] ?? '');

        // Parse UTM parameters if present
        if (!empty($_GET['utm_source'])) {
            $visitor_event->set('vse_source', $_GET['utm_source']);
        }
        if (!empty($_GET['utm_campaign'])) {
            $visitor_event->set('vse_campaign', $_GET['utm_campaign']);
        }
        if (!empty($_GET['utm_medium'])) {
            $visitor_event->set('vse_medium', $_GET['utm_medium']);
        }
        if (!empty($_GET['utm_content'])) {
            $visitor_event->set('vse_content', $_GET['utm_content']);
        }

        // Check if 404
        if (http_response_code() === 404) {
            $visitor_event->set('vse_is_404', true);
        }

        $visitor_event->save();

    } catch (Exception $e) {
        // Silently fail - don't break page for tracking errors
        error_log("Visitor tracking error: " . $e->getMessage());
    }
}
```

### Visitor Tracking Endpoint (/ajax/vs.php)

```php
<?php
/**
 * Visitor tracking endpoint
 * Named 'vs.php' to avoid ad blockers
 */

PathHelper::requireOnce('data/visitor_events_class.php');

// Security: Verify request is from our domain
$allowed_origins = [
    'https://' . $_SERVER['HTTP_HOST'],
    'http://' . $_SERVER['HTTP_HOST']
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';

if (!$origin || !in_array(rtrim($origin, '/'), $allowed_origins)) {
    // Also check referer as fallback for older browsers
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $referer_host = parse_url($referer, PHP_URL_HOST);

    if ($referer_host !== $_SERVER['HTTP_HOST']) {
        http_response_code(403);
        exit();
    }
}

// Get the page being tracked
$page = $_POST['p'] ?? $_GET['p'] ?? '/';

// Validate page parameter (prevent injection)
if (!preg_match('/^\/[a-zA-Z0-9\/_\-\?&=]*$/', $page)) {
    http_response_code(400);
    exit();
}

// Record the visit using VisitorEvent class method
VisitorEvent::recordPageVisit($page);

// Return 204 No Content (optimal for sendBeacon)
http_response_code(204);
exit();
```

### Content Edit Integration (Simplest Approach)

Add cache invalidation directly to SystemBase::save() - this catches ALL model saves automatically:

```php
// In SystemBase::save() method (around line ~800)
public function save($debug = false) {
    // ... existing save logic ...
    $result = // existing save result

    // AUTO CACHE INVALIDATION - Simple approach
    // Only invalidate the model's own URL if it has one
    if ($result && class_exists('StaticPageCache')) {
        if (method_exists($this, 'get_url')) {
            $url = $this->get_url();
            if ($url) {
                PathHelper::requireOnce('includes/StaticPageCache.php');
                StaticPageCache::invalidateUrl($url);
            }
        }
    }

    return $result;
}
```

That's it! Models with `get_url()` methods will have their detail pages invalidated on save. All other cache invalidation happens randomly during page serves to keep content fresh.

```php
// Theme changes (in admin_theme.php or wherever theme is switched)
if ($new_theme !== $current_theme) {
    PathHelper::requireOnce('includes/StaticPageCache.php');
    StaticPageCache::clearAll();
}
```

## Limitations and Edge Cases

### Footer Template Modification

Replace PHP visitor tracking with JavaScript in your footer template:

```php
<!-- In footer template (e.g., /views/footer.php or /theme/falcon/views/footer.php) -->
<?php
// REMOVE old PHP tracking code like:
// if ($should_track_page) {
//     VisitorTracking::recordVisit($request_path);
// }

// REPLACE with JavaScript tracking:
$settings = Globalvars::get_instance();
$should_track = true; // Your existing logic for determining if page should be tracked

// Exclude certain paths from tracking
$no_track_patterns = ['/admin/', '/ajax/', '/api/', '/utils/'];
foreach ($no_track_patterns as $pattern) {
    if (strpos($_SERVER['REQUEST_URI'], $pattern) === 0) {
        $should_track = false;
        break;
    }
}

if ($should_track):
?>
<script>
// Visitor tracking using sendBeacon for reliability
(function() {
    const trackUrl = '/ajax/vs.php?p=' + encodeURIComponent(window.location.pathname);
    if (navigator.sendBeacon) {
        navigator.sendBeacon(trackUrl);
    }
    // No fallback - only ~1% of users on ancient browsers (IE, Safari <11) won't be tracked
})();
</script>
<?php endif; ?>
```

#### Benefits of This Approach

1. **Simplicity**: One tracking mechanism for all pages
2. **Cache-friendly**: HTML already contains correct tracking behavior
3. **Reliable**: sendBeacon() ensures tracking even when navigating away
4. **Minimal overhead**: Fire-and-forget, no response processing needed

#### Tracking Endpoint

Create `/ajax/vs.php` (named to avoid ad blockers):
```php
<?php
// Simple endpoint - just record the visit
$page = $_POST['p'] ?? $_GET['p'] ?? '/';

// Record to vse_visitor_sessions table
VisitorTracking::recordVisit($page);

// No response needed for sendBeacon
http_response_code(204); // No Content
```

#### Trade-offs

**Won't track:**
- Search engine bots (no JavaScript execution)
- Users with JavaScript disabled (~1-2% of traffic)
- Users with aggressive ad blockers (~15-30% if "track" in URL)

**Will track:**
- All regular users with JavaScript
- Works identically for cached and non-cached pages
- Survives page navigation (sendBeacon)

This approach prioritizes simplicity and maintainability over complete tracking coverage.

### How This Handles Common Scenarios:

1. **Pages with optional login (most common case)**
   - Example: Product pages showing "Login" for anonymous, "Welcome John" for logged-in
   - Solution: Cache serves the anonymous version; logged-in users always get fresh render
   - Result: Anonymous users (majority of traffic) get cached pages

2. **Pages that set non-session cookies**
   - Example: A/B testing cookies, analytics cookies
   - Solution: These pages marked as "nostatic" (can't cache if setting new data)

3. **Public forms**
   - Example: Contact forms, newsletter signups
   - Solution: If form starts a session (CSRF token), it won't cache
   - Note: May need to mark some forms as "nostatic" manually

4. **Time-based content**
   - Example: "Today's special offer" banners
   - Solution: Clear cache when offers change

5. **Randomized content**
   - Example: Random testimonial widgets
   - Solution: Either accept that anonymous users see same random item, or mark as "nostatic"

### When to Manually Mark as "nostatic":

- Public pages with user-specific widgets
- Pages with time-sensitive content
- Pages with randomized elements
- Public pages that check for optional login status

### When Cache Automatically Regenerates:

- Content edit (manual invalidation call)
- Theme change (clear all cache)
- Manual clear via admin
- File deleted (regenerates on next access)

## Testing Requirements

### Functional Tests
1. Cache generation for public pages
2. Cache serving verification
3. Authentication check (no caching when logged in)
4. Query parameter handling
5. Cache regeneration after content save
6. Theme switch triggers regeneration
7. Manual regeneration via admin

### Performance Tests
1. Benchmark cached vs uncached page load times
2. File system stress test with many cached pages
3. Memory usage during cache serving
4. Concurrent request handling

### Edge Cases
1. File system permissions on cache directory
2. Disk space limitations
3. Corrupted index.json file (handle gracefully, regenerate if needed)
4. Race conditions during concurrent cache writes (handled by atomic file operations)

## Implementation Checklist

### Phase 1: Core Implementation
- [x] Create `/cache/static_pages/` directory with proper permissions ✓ (completed 2025-09-20)
- [x] Add `StaticPageCache.php` to `/includes/` ✓ (completed 2025-09-20)
- [x] Modify `RouteHelper.php` with cache check and creation logic ✓ (completed 2025-09-20)
- [x] Test basic caching functionality with a simple page ✓ (completed 2025-09-20)

### Phase 2: Visitor Tracking Migration
- [x] Create `/ajax/vs.php` endpoint ✓ (completed 2025-09-20)
- [ ] Update footer template to use JavaScript tracking
- [ ] Test tracking works for both cached and non-cached pages
- [ ] Verify sendBeacon functionality

### Phase 3: Admin Interface
- [x] Create `/adm/admin_static_cache.php` ✓ (completed 2025-09-20)
- [ ] Add link to admin menu
- [ ] Test enable/disable functionality
- [ ] Test cache clearing and URL invalidation

### Phase 4: Content Integration
- [x] Add cache invalidation to page save logic ✓ (completed 2025-09-20)
- [x] Add cache invalidation to product save logic ✓ (completed 2025-09-20)
- [ ] Add cache clearing on theme switch
- [x] Add cache invalidation to other content types as needed ✓ (completed 2025-09-20)

### Phase 5: Testing & Optimization
- [ ] Load test cached vs non-cached pages
- [ ] Monitor cache hit rates
- [ ] Identify and mark "nostatic" URLs
- [ ] Fine-tune excluded patterns

## Testing Script

```bash
#!/bin/bash
# Simple test script for static cache functionality

echo "Testing Static Page Cache..."

# Test 1: Check if cache directory exists
if [ -d "/var/www/html/joinerytest/public_html/cache/static_pages" ]; then
    echo "✓ Cache directory exists"
else
    echo "✗ Cache directory missing"
fi

# Test 2: Test a public page
echo "Testing public page caching..."
curl -s -o /dev/null -w "%{http_code}" http://localhost/page/about
sleep 1
if [ -f "/var/www/html/joinerytest/public_html/cache/static_pages/*.html" ]; then
    echo "✓ Cache file created"
else
    echo "✗ No cache file created"
fi

# Test 3: Check cache header
HEADERS=$(curl -I -s http://localhost/page/about)
if echo "$HEADERS" | grep -q "X-Cache: HIT"; then
    echo "✓ Cache HIT header present"
else
    echo "✗ Cache HIT header missing"
fi

echo "Cache testing complete"
```

## Security Considerations

- Validate cache file paths (prevent directory traversal)
- Ensure cached content doesn't include sensitive data
- Set proper file permissions on cache directory
- Rate limit cache generation to prevent DoS
- Validate that served files are actually cache files

## Monitoring & Metrics

Track and report:
- Cache hit rate
- Cache miss reasons
- Average cache age
- Disk usage
- Performance improvement metrics
- Error rates

## Future Enhancements

- Compressed cache files (.html.gz)
- Redis/Memcached backend option
- Selective component caching
- Cache prewarming on content changes
- A/B testing support with cache variants