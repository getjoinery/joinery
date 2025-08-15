<?php
/**
 * HTTP Response Routing System Integration Test
 * 
 * Tests routing by making actual HTTP requests and checking responses:
 * - Uses cURL to test real server responses
 * - Checks HTTP status codes (200, 404, 301/302, etc.)
 * - Tests admin authentication and access control
 * - Validates static file serving with correct MIME types
 * - Tests database URL redirects
 * - Verifies error handling for missing pages
 * 
 * REQUIREMENTS:
 * - Must run on dev server with HTTP access
 * - Requires the site to be accessible via HTTP/HTTPS
 * - Tests against actual server responses
 * 
 * USAGE:
 * 1. Upload this file to: tests/integration/routing_test.php
 * 2. Run from command line: php tests/integration/routing_test.php
 * 3. Or run from browser: https://yoursite.com/tests/integration/routing_test.php
 * 
 * This integration test verifies:
 * - ACTUAL HTTP responses from real URLs using cURL
 * - Correct HTTP status codes (200, 404, 301/302 redirects, etc.)
 * - Real routing behavior on your actual server
 * - Static file serving with proper MIME types
 * - Admin authentication requirements
 * - Error handling for missing pages
 */

// Browser-only HTTP routing test - designed for web access only

// HTML output functions
function output_pass($text) {
    echo '<li style="color: #16a34a; font-family: monospace; margin: 8px 0; padding: 8px; background: #f0fdf4; border-left: 4px solid #16a34a; border-radius: 4px;">✅ PASS: ' . htmlspecialchars($text) . '</li>';
}

function output_fail($text) {
    echo '<li style="color: #dc2626; font-family: monospace; margin: 8px 0; padding: 8px; background: #fef2f2; border-left: 4px solid #dc2626; border-radius: 4px;">❌ FAIL: ' . htmlspecialchars($text) . '</li>';
}

function output_info($text) {
    echo '<li style="color: #7c3aed; font-family: monospace; margin: 8px 0; padding: 8px; background: #faf5ff; border-left: 4px solid #7c3aed; border-radius: 4px;">📝 ' . htmlspecialchars($text) . '</li>';
}

// Start HTML output
echo '<!DOCTYPE html><html><head>';
echo '<title>HTTP Routing Test Results</title>';
echo '<meta charset="UTF-8">';
echo '<style>';
echo 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 40px; background: #fff; }';
echo 'h1 { color: #1e293b; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px; }';
echo 'h2 { color: #475569; margin-top: 30px; }';
echo 'h3 { color: #2563eb; margin: 20px 0 10px 0; }';
echo '.test-section { background: #f8fafc; padding: 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #3b82f6; }';
echo 'ul { list-style: none; padding: 0; margin: 0; }';
echo 'li { margin: 8px 0; padding: 8px; border-radius: 4px; font-family: monospace; }';
echo 'div { line-height: 1.6; }';
echo '.results-section { background: #fefce8; border: 1px solid #facc15; padding: 15px; margin: 20px 0; border-radius: 6px; }';
echo '.detail-item { margin: 4px 0 4px 20px; padding: 4px 8px; background: #fef2f2; border-left: 2px solid #dc2626; font-size: 12px; }';
echo '</style>';
echo '</head><body>';

echo '<h1>🚀 HTTP Routing System Test</h1>';
echo '<div style="background: #eff6ff; padding: 15px; border-radius: 6px; margin-bottom: 20px;">';
echo '<strong>Environment:</strong> Browser-Only Mode<br>';
echo '<strong>Server:</strong> ' . htmlspecialchars($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'Unknown') . '<br>';
echo '<strong>Timestamp:</strong> ' . date('Y-m-d H:i:s T');
echo '</div>';

// Include system dependencies
require_once(__DIR__ . '/../../includes/PathHelper.php');

try {
    PathHelper::requireOnce('includes/Globalvars.php');
    PathHelper::requireOnce('includes/DbConnector.php');
    $settings = Globalvars::get_instance();
    $dbconnector = DbConnector::get_instance();
    $dblink = $dbconnector->get_db_link();
} catch (Exception $e) {
    if ($is_browser) {
        echo '<div style="background: #fef2f2; border: 2px solid #dc2626; padding: 20px; border-radius: 8px; color: #991b1b;">';
        echo '<h3>❌ System Error</h3>';
        echo '<p><strong>Could not load system settings.</strong></p>';
        echo '<p><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
        echo '</div></body></html>';
    } else {
        echo "❌ ERROR: Could not load system settings.\n";
        echo "Error: " . $e->getMessage() . "\n";
    }
    exit(1);
}

// HTTP Testing Class
class HttpTester {
    private static $base_url = null;
    public static $test_results = [];
    
    public static function init($settings) {
        // Determine base URL for testing
        if (isset($_SERVER['HTTP_HOST'])) {
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] ? 'https' : 'http';
            self::$base_url = $protocol . '://' . $_SERVER['HTTP_HOST'];
        } else {
            // Try to get from settings or use fallback
            $host = $settings->get_setting('site_domain') ?? 'joinerytest.site';
            self::$base_url = 'https://' . $host;
        }
        
        self::$test_results[] = "Testing against base URL: " . self::$base_url;
    }
    
    public static function testUrl($path, $expected_status = 200, $description = '', $options = []) {
        $url = self::$base_url . $path;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // Don't follow redirects automatically
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'RoutingTest/1.0');
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_NOBODY, false);
        
        // Add any custom headers or options
        if (isset($options['headers'])) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $options['headers']);
        }
        
        if (isset($options['method']) && $options['method'] === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if (isset($options['data'])) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $options['data']);
            }
        }
        
        // Handle SSL for HTTPS
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $redirect_url = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        $result = [
            'url' => $url,
            'path' => $path,
            'expected_status' => $expected_status,
            'actual_status' => $http_code,
            'content_type' => $content_type,
            'redirect_url' => $redirect_url,
            'description' => $description,
            'success' => false,
            'message' => '',
            'curl_error' => $curl_error
        ];
        
        if (!empty($curl_error)) {
            $result['message'] = "cURL Error: {$curl_error}";
            return $result;
        }
        
        // Check if status matches expected
        if (is_array($expected_status)) {
            $result['success'] = in_array($http_code, $expected_status);
            if (!$result['success']) {
                $expected_str = implode(' or ', $expected_status);
                $result['message'] = "Expected status {$expected_str}, got {$http_code}";
            }
        } else {
            $result['success'] = ($http_code == $expected_status);
            if (!$result['success']) {
                $result['message'] = "Expected status {$expected_status}, got {$http_code}";
            }
        }
        
        // Additional checks based on content type for successful responses
        if ($result['success'] && $http_code == 200) {
            if (strpos($path, '.css') !== false && !str_contains($content_type, 'css')) {
                $result['success'] = false;
                $result['message'] = "CSS file returned wrong content-type: {$content_type}";
            } elseif (strpos($path, '.js') !== false && !str_contains($content_type, 'javascript')) {
                $result['success'] = false;
                $result['message'] = "JS file returned wrong content-type: {$content_type}";
            }
        }
        
        // Log redirect information
        if (in_array($http_code, [301, 302, 307, 308]) && $redirect_url) {
            self::$test_results[] = "  REDIRECT: {$path} -> {$redirect_url} ({$http_code})";
        }
        
        return $result;
    }
    
    public static function getBaseUrl() {
        return self::$base_url;
    }
}

class HttpRoutingTestRunner {
    private $passed = 0;
    private $failed = 0;
    private $settings;
    private $dblink;
    
    public function __construct($settings, $dblink) {
        $this->settings = $settings;
        $this->dblink = $dblink;
    }
    
    public function runAllTests() {
        // Initialize HTTP tester first
        HttpTester::init($this->settings);
        
        echo '<h2>🌐 HTTP Response Testing</h2>';
        echo '<div style="background: #f1f5f9; padding: 10px; border-radius: 6px; margin: 15px 0;">';
        echo '<strong>Testing Mode:</strong> Browser Display with Enhanced Troubleshooting<br>';
        echo '<strong>Base URL:</strong> ' . htmlspecialchars(HttpTester::getBaseUrl()) . '<br>';
        echo '</div>';
        
        // Test Categories
        $this->testPublicPages();
        $this->testStaticFiles();
        $this->testThemeFiles();
        $this->testThemeViews();
        $this->testPluginFiles();
        $this->testAdminAccess();
        $this->testAjaxEndpoints();
        $this->testUtilityPages();
        $this->testContentRoutes();
        $this->testErrorPages();
        $this->testRedirects();
        
        // Summary
        $this->displaySummary();
    }
    
    private function testPublicPages() {
        echo '<div class="test-section"><h3>1. TESTING PUBLIC PAGES</h3><ul>';
        
        $test_cases = [
            // Homepage - always test this
            ['/', 200, 'Homepage'],
        ];
        
        // Check for actual view files that exist
        $view_files_to_check = [
            '/login' => 'Login page',
            '/events' => 'Events page', 
            '/register' => 'Register page',
            '/products' => 'Products page',
        ];
        
        foreach ($view_files_to_check as $path => $description) {
            $view_file = $_SERVER['DOCUMENT_ROOT'] . "/views" . $path . ".php";
            if (file_exists($view_file)) {
                $test_cases[] = [$path, 200, "{$description} (exists)"];
            } else {
                $test_cases[] = [$path, 404, "{$description} (not found)"];
            }
        }
        
        // Test nonexistent root view
        $test_cases[] = ['/definitely-fake-page-12345', 404, 'Root view (does not exist)'];
        
        foreach ($test_cases as [$path, $expected_status, $description]) {
            $result = HttpTester::testUrl($path, $expected_status, $description);
            
            if ($result['success']) {
                $this->pass("{$description}: {$path} -> {$result['actual_status']}");
            } else {
                $this->fail("{$description}: {$path} -> {$result['message']}", $result);
            }
        }
        
        echo '</ul></div>';
    }
    
    private function testStaticFiles() {
        echo '<div class="test-section"><h3>2. TESTING STATIC FILES</h3><ul>';
        
        $test_cases = [];
        
        // Test files that should exist (either static files or dynamic routes)
        $should_exist_files = [];
        
        // Check if robots.txt exists as static file OR dynamic route (robots.php view)
        if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/robots.txt')) {
            $should_exist_files['/robots.txt'] = 'Robots.txt (exists)';
        } elseif (file_exists($_SERVER['DOCUMENT_ROOT'] . '/views/robots.php')) {
            $should_exist_files['/robots.txt'] = 'Robots.txt (exists)';
        }
        
        // Check if sitemap exists as static file OR dynamic route (sitemap.php view) 
        if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/sitemap.xml')) {
            $should_exist_files['/sitemap.xml'] = 'Sitemap (exists)';
        } elseif (file_exists($_SERVER['DOCUMENT_ROOT'] . '/views/sitemap.php')) {
            $should_exist_files['/sitemap.xml'] = 'Sitemap (exists)';
        }
        
        // Add all files that should exist and expect 200
        foreach ($should_exist_files as $path => $description) {
            $test_cases[] = [$path, 200, $description];
        }
        
        // Test files that might exist (check filesystem)
        $might_exist_files = [
            '/favicon.ico' => 'Favicon'
        ];
        
        foreach ($might_exist_files as $path => $description) {
            $full_path = $_SERVER['DOCUMENT_ROOT'] . $path;
            if (file_exists($full_path)) {
                $test_cases[] = [$path, 200, "{$description} (exists)"];
            } else {
                $test_cases[] = [$path, 404, "{$description} (does not exist)"];
            }
        }
        
        // Test nonexistent static file  
        $test_cases[] = ['/definitely-fake-static-file.css', 404, 'Static file (does not exist)'];
        
        foreach ($test_cases as [$path, $expected_status, $description]) {
            $result = HttpTester::testUrl($path, $expected_status, $description);
            
            if ($result['success']) {
                $this->pass("{$description}: {$path} -> {$result['actual_status']}");
            } else {
                $this->fail("{$description}: {$path} -> {$result['message']}", $result);
            }
        }
        
        echo '</ul></div>';
    }
    
    private function testThemeFiles() {
        echo '<div class="test-section"><h3>3. TESTING THEME FILES</h3><ul>';
        
        // Get current theme using ThemeHelper
        $current_theme = 'falcon'; // Default fallback
        try {
            PathHelper::requireOnce('includes/ThemeHelper.php'); 
            $themeHelper = ThemeHelper::getInstance(); // Gets current theme
            $current_theme = $themeHelper->getName();
        } catch (Exception $e) {
            // ThemeHelper might not be available, use settings fallback
            $current_theme = $this->settings->get_setting('theme_template') ?? 'falcon';
        }
        
        $test_cases = [];
        
        // Check for actual theme files that exist - based on real filesystem structure
        $theme_files_to_check = [
            // Falcon theme (Bootstrap-based) - has actual files
            "/theme/falcon/includes/css/theme.css" => "Falcon theme CSS",
            "/theme/falcon/includes/js/theme.js" => "Falcon theme JS",
            "/theme/falcon/includes/vendors/bootstrap/bootstrap.min.js" => "Falcon Bootstrap JS",
            
            // Tailwind theme - has actual files  
            "/theme/tailwind/includes/output.css" => "Tailwind CSS",
            "/theme/tailwind/includes/jquery-3.4.1.min.js" => "Tailwind jQuery",
            
            // Zoukroom theme - has actual files
            "/theme/zoukroom/includes/css/main.css" => "Zoukroom CSS",
            "/theme/zoukroom/includes/js/uikit.js" => "Zoukroom UIKit JS",
            
            // Default theme - has actual files
            "/theme/default/includes/output.css" => "Default theme CSS",
            "/theme/default/includes/jquery-3.4.1.min.js" => "Default jQuery",
 
            // Default theme - has actual files
            "/theme/sassa/assets/img/logo.svg" => "Sassa theme image",
			
            // Current theme specific files (if different from above)
        ];
        
        
        foreach ($theme_files_to_check as $path => $description) {
            $full_path = $_SERVER['DOCUMENT_ROOT'] . $path;
            if (file_exists($full_path)) {
                $test_cases[] = [$path, 200, "{$description} (exists)"];
            } else {
                $test_cases[] = [$path, 404, "{$description} (does not exist)"];
            }
        }
        
        // Test nonexistent theme files
        $test_cases[] = ["/theme/definitely-fake-theme-12345/style.css", 404, "Theme CSS (does not exist)"];
        $test_cases[] = ["/theme/falcon/definitely-fake-file.css", 404, "File in real theme (does not exist)"];
        
        foreach ($test_cases as [$path, $expected_status, $description]) {
            $result = HttpTester::testUrl($path, $expected_status, $description);
            
            if ($result['success']) {
                $this->pass("{$description}: {$path} -> {$result['actual_status']}");
            } else {
                $this->fail("{$description}: {$path} -> {$result['message']}", $result);
            }
        }
        
        echo '</ul></div>';
    }
    
    private function testThemeViews() {
        echo '<div class="test-section"><h3>4. TESTING THEME VIEW FILES</h3><ul>';
        
        // Get current theme using ThemeHelper
        $current_theme = 'falcon';
        try {
            PathHelper::requireOnce('includes/ThemeHelper.php'); 
            $themeHelper = ThemeHelper::getInstance();
            $current_theme = $themeHelper->getName();
        } catch (Exception $e) {
            $current_theme = $this->settings->get_setting('theme_template') ?? 'falcon';
        }
        
        $test_cases = [];
        
        // Check for theme view files that override base views
        $theme_views_to_check = [
            '/index' => "Theme homepage view ({$current_theme})",
            '/login' => "Theme login view ({$current_theme})", 
            '/events' => "Theme events view ({$current_theme})",
            '/event' => "Theme event detail view ({$current_theme})",
        ];
        
        foreach ($theme_views_to_check as $route => $description) {
            // Check if theme has this view file
            $theme_view_path = $_SERVER['DOCUMENT_ROOT'] . "/theme/{$current_theme}/views{$route}.php";
            $base_view_path = $_SERVER['DOCUMENT_ROOT'] . "/views{$route}.php";
            
            if (file_exists($theme_view_path)) {
                $test_cases[] = [$route, 200, "{$description} (exists)"];
            } elseif (file_exists($base_view_path)) {
                // Special case: /event without slug should return 404 (no event specified)
                if ($route === '/event') {
                    $test_cases[] = [$route, 404, "Base view{$route}.php (exists, theme override does not exist, no event specified)"];
                } else {
                    $test_cases[] = [$route, 200, "Base view{$route}.php (exists, theme override does not exist)"];
                }
            } else {
                $test_cases[] = [$route, 404, "View{$route}.php (does not exist)"];
            }
        }
        
        foreach ($test_cases as [$path, $expected_status, $description]) {
            $result = HttpTester::testUrl($path, $expected_status, $description);
            
            if ($result['success']) {
                $this->pass("{$description}: {$path} -> {$result['actual_status']}");
            } else {
                $this->fail("{$description}: {$path} -> {$result['message']}", $result);
            }
        }
        
        // Test actual event page with real slug from database
        try {
            PathHelper::requireOnce('data/events_class.php');
            $events = new MultiEvent(['deleted' => false], ['evt_event_id' => 'DESC'], 1);
            if ($events->count_all() > 0) {
                $events->load();
                $event = $events->get(0);
                if ($event && $event->get('evt_link')) {
                    $event_url = $event->get_url();
                    $result = HttpTester::testUrl($event_url, 200, 'Actual event from database');
                    
                    if ($result['success']) {
                        $this->pass("Actual event from database: {$event_url} -> {$result['actual_status']}");
                    } else {
                        $this->fail("Actual event from database: {$event_url} -> {$result['message']}", $result);
                    }
                }
            }
        } catch (Exception $e) {
            // Silently skip if events can't be loaded
        }
        
        echo '</ul></div>';
    }
    
    private function testPluginFiles() {
        echo '<div class="test-section"><h3>5. TESTING PLUGIN FILES</h3><ul>';
		//Currently, there are no plugins that have assets
        
            //removed: echo "5. TESTING PLUGIN FILES\n";
        
        $test_cases = [];
        
        // Specify specific plugin files to test - change these paths to match your actual plugin files
        //$test_cases[] = ['/plugins/controld/includes/ControlDHelper', 200, 'Include file (should exist)'];
        
        // Always test nonexistent plugin files
        //$test_cases[] = ['/plugins/definitely-fake-plugin-12345/assets/fake.js', 404, 'Plugin JS (does not exist)'];
        
        foreach ($test_cases as [$path, $expected_status, $description]) {
            $result = HttpTester::testUrl($path, $expected_status, $description);
            
            if ($result['success']) {
                $this->pass("{$description}: {$path} -> {$result['actual_status']}");
            } else {
                $this->fail("{$description}: {$path} -> {$result['message']}", $result);
            }
        }
        
        echo '</ul></div>';
    }
    
    
    private function testAdminAccess() {
        echo '<div class="test-section"><h3>7. TESTING ADMIN ACCESS</h3><ul>';
        
            //removed: echo "7. TESTING ADMIN ACCESS\n";
        
        $test_cases = [
            // Existing admin page (should require auth)
            ['/admin/admin_users', [301, 302, 401, 403], 'Existing admin page (should require auth)'],
			['/plugins/controld/admin/admin_ctld_account', [301, 302, 401, 403], 'Existing plugin admin page (should require auth)'],
            
            // Admin page that doesn't exist
            ['/admin/definitely-fake-admin-page', [404, 401, 403], 'Admin page (does not exist)'],
        ];
        
        foreach ($test_cases as [$path, $expected_status, $description]) {
            $result = HttpTester::testUrl($path, $expected_status, $description);
            
            if ($result['success']) {
                $this->pass("{$description}: {$path} -> {$result['actual_status']}");
            } else {
                $this->fail("{$description}: {$path} -> {$result['message']}", $result);
            }
        }
        
        echo '</ul></div>';
    }
    
    private function testAjaxEndpoints() {
        echo '<div class="test-section"><h3>8. TESTING AJAX ENDPOINTS</h3><ul>';
        
            //removed: echo "8. TESTING AJAX ENDPOINTS\n";
        
        $test_cases = [
            // Existing AJAX endpoint
            ['/ajax/theme_switch_ajax', [200, 400, 401, 403, 405], 'Existing AJAX endpoint'],
            
            // AJAX endpoint that doesn't exist
            ['/ajax/definitely-fake-endpoint', [404, 401, 403], 'AJAX endpoint (does not exist)'],
        ];
        
        foreach ($test_cases as [$path, $expected_status, $description]) {
            $result = HttpTester::testUrl($path, $expected_status, $description);
            
            if ($result['success']) {
                $this->pass("{$description}: {$path} -> {$result['actual_status']}");
            } else {
                $this->fail("{$description}: {$path} -> {$result['message']}", $result);
            }
        }
        
        echo '</ul></div>';
    }
    
    private function testUtilityPages() {
        echo '<div class="test-section"><h3>9. TESTING UTILITY PAGES</h3><ul>';
        
            //removed: echo "9. TESTING UTILITY PAGES\n";
        
        $test_cases = [
            // Existing utility (avoid sync scripts)
            ['/utils/forms_example_bootstrap', [200, 401, 403], 'Existing utility page'],
            
            // Utility page that doesn't exist
            ['/utils/definitely-fake-utility', [404, 401, 403], 'Utility page (does not exist)'],
        ];
        
        foreach ($test_cases as [$path, $expected_status, $description]) {
            $result = HttpTester::testUrl($path, $expected_status, $description);
            
            if ($result['success']) {
                $this->pass("{$description}: {$path} -> {$result['actual_status']}");
            } else {
                $this->fail("{$description}: {$path} -> {$result['message']}", $result);
            }
        }
        
        echo '</ul></div>';
    }
    
    private function testContentRoutes() {
        echo '<div class="test-section"><h3>10. TESTING CONTENT ROUTES</h3><ul>';
        
            //removed: echo "10. TESTING CONTENT ROUTES\n";
        
        $test_cases = [];
        
        // Test real event URLs from database
        try {
            PathHelper::requireOnce('data/events_class.php');
            $events = new MultiEvent(['deleted' => false], ['evt_event_id' => 'DESC'], 2);
            if ($events->count_all() > 0) {
                $events->load();
                $index = 1;
                foreach ($events as $event) {
                    if ($event->get('evt_link')) {
                        $test_cases[] = [$event->get_url(), 200, 'Event #' . $index . ' from database'];
                        $index++;
                    }
                }
            }
        } catch (Exception $e) {
            // Events model might not exist
        }
        
        // Test real page URLs from database
        try {
            PathHelper::requireOnce('data/pages_class.php');
            $pages = new MultiPage(['deleted' => false], ['pag_page_id' => 'DESC'], 2);
            if ($pages->count_all() > 0) {
                $pages->load();
                $index = 1;
                foreach ($pages as $page) {
                    if ($page->get('pag_link')) {
                        $test_cases[] = [$page->get_url(), 200, 'Page #' . $index . ' from database'];
                        $index++;
                    }
                }
            }
        } catch (Exception $e) {
            // Pages model might not exist
        }
        
        // Test real product URLs from database
        try {
            PathHelper::requireOnce('data/products_class.php');
            $products = new MultiProduct(['deleted' => false], ['pro_product_id' => 'DESC'], 2);
            if ($products->count_all() > 0) {
                $products->load();
                $index = 1;
                foreach ($products as $product) {
                    if ($product->get('pro_link')) {
                        $test_cases[] = [$product->get_url(), 200, 'Product #' . $index . ' from database'];
                        $index++;
                    }
                }
            }
        } catch (Exception $e) {
            // Products model might not exist
        }
        
        // If no real content found in database, note it
        if (empty($test_cases)) {
            output_info("No content found in database to test");
        } else {
            // Close the list before adding info, then reopen
            echo '</ul>';
            output_info("Testing " . count($test_cases) . " content items from database");
            echo '<ul>';
        }
        
        // Always test nonexistent content
        $test_cases[] = ['/page/definitely-fake-page-slug-12345', 404, 'Page content (does not exist)'];
        
        foreach ($test_cases as [$path, $expected_status, $description]) {
            $result = HttpTester::testUrl($path, $expected_status, $description);
            
            if ($result['success']) {
                $this->pass("{$description}: {$path} -> {$result['actual_status']}");
            } else {
                $this->fail("{$description}: {$path} -> {$result['message']}", $result);
            }
        }
        
        echo '</ul></div>';
    }
    
    private function testErrorPages() {
        echo '<div class="test-section"><h3>11. TESTING ERROR PAGES</h3><ul>';
        
            //removed: echo "11. TESTING ERROR PAGES\n";
        
        $test_cases = [
            // URL that doesn't exist
            ['/absolutely-fake-url-that-definitely-does-not-exist-12345', 404, 'URL (does not exist)'],
        ];
        
        foreach ($test_cases as [$path, $expected_status, $description]) {
            $result = HttpTester::testUrl($path, $expected_status, $description);
            
            if ($result['success']) {
                $this->pass("{$description}: {$path} -> {$result['actual_status']}");
            } else {
                $this->fail("{$description}: {$path} -> {$result['message']}", $result);
            }
        }
        
        echo '</ul></div>';
    }
    
    private function testRedirects() {
        echo '<div class="test-section"><h3>12. TESTING URL REDIRECTS</h3><ul>';
        
            //removed: echo "12. TESTING URL REDIRECTS\n";
        
        $test_cases = [];
        
        // Test a random redirect URL from database
        try {
            PathHelper::requireOnce('data/urls_class.php');
            // Get any URL record - we'll check for redirect_url after loading
            $urls = new MultiUrl([], ['url_url_id' => 'DESC'], 10);
            $count = $urls->count_all();
            
            if ($count > 0) {
                $urls->load();
                // Look for a URL that has redirect_url set
                $found_redirect = false;
                foreach ($urls as $url) {
                    $incoming = $url->get('url_incoming');
                    $redirect_url = $url->get('url_redirect_url');
                    
                    if ($incoming && $redirect_url) {
                        $expected_status = intval($url->get('url_type') ?? 301);
                        if (!in_array($expected_status, [301, 302, 307, 308])) {
                            $expected_status = 301;
                        }
                        $test_cases[] = [
                            '/' . ltrim($incoming, '/'), 
                            $expected_status, 
                            'Redirect from database (→ ' . $redirect_url . ')'
                        ];
                        $found_redirect = true;
                        break; // Just test one redirect
                    }
                }
                
                if (!$found_redirect) {
                    echo '</ul>';
                    output_info("Found " . $count . " URLs but none have redirect_url set");
                    echo '<ul>';
                }
            } else {
                echo '</ul>';
                output_info("No URLs found in database");
                echo '<ul>';
            }
        } catch (Exception $e) {
            echo '</ul>';
            output_info("Could not load URLs: " . $e->getMessage());
            echo '<ul>';
        }
        
        // Always test a URL that should not redirect
        $test_cases[] = ['/definitely-fake-redirect-url-12345', 404, 'Redirect URL (does not exist)'];
        
        foreach ($test_cases as [$path, $expected_status, $description]) {
            $result = HttpTester::testUrl($path, $expected_status, $description);
            
            if ($result['success']) {
                $this->pass("{$description}: {$path} -> {$result['actual_status']}");
            } else {
                $this->fail("{$description}: {$path} -> {$result['message']}", $result);
            }
        }
        
        echo '</ul></div>';
    }
    
    private function pass($message) {
        output_pass($message);
        $this->passed++;
    }
    
    private function fail($message, $result = null) {
        
        // Enhanced failure output with additional details
        $enhanced_message = $message;
        $troubleshooting_details = [];
        
        if ($result && is_array($result)) {
            // Add redirect information if present
            if (isset($result['redirect_url']) && !empty($result['redirect_url'])) {
                $troubleshooting_details[] = "→ REDIRECTS TO: " . $result['redirect_url'];
            }
            
            // Add HTTP status code analysis
            if (isset($result['actual_status'])) {
                $status_explanation = $this->explainHttpStatus($result['actual_status']);
                if ($status_explanation) {
                    $troubleshooting_details[] = "→ STATUS: " . $result['actual_status'] . " (" . $status_explanation . ")";
                }
            }
            
            // Add content type information
            if (isset($result['content_type']) && !empty($result['content_type'])) {
                $troubleshooting_details[] = "→ CONTENT-TYPE: " . $result['content_type'];
            }
            
            // Add curl error if present
            if (isset($result['curl_error']) && !empty($result['curl_error'])) {
                $troubleshooting_details[] = "→ CURL ERROR: " . $result['curl_error'];
            }
            
            // Add request URL for context
            if (isset($result['url'])) {
                $troubleshooting_details[] = "→ FULL URL: " . $result['url'];
            }
        }
        
        // Output the main failure message
        output_fail($enhanced_message);
        
        // Output troubleshooting details on separate lines for better readability
        if (!empty($troubleshooting_details)) {
            foreach ($troubleshooting_details as $detail) {
                echo '<li class="detail-item">' . htmlspecialchars($detail) . '</li>';
            }
        }
        
        $this->failed++;
    }
    
    private function explainHttpStatus($status) {
        $explanations = [
            301 => "Permanent Redirect - URL moved permanently",
            302 => "Temporary Redirect - URL temporarily moved", 
            401 => "Unauthorized - Authentication required",
            403 => "Forbidden - Access denied",
            404 => "Not Found - Resource does not exist",
            500 => "Internal Server Error - Server-side problem",
            502 => "Bad Gateway - Upstream server error",
            503 => "Service Unavailable - Server temporarily unavailable"
        ];
        
        return $explanations[$status] ?? null;
    }
    
    private function displaySummary() {
        $total = $this->passed + $this->failed;
        $color = ($this->failed == 0) ? '#16a34a' : '#dc2626';
        $icon = ($this->failed == 0) ? '✅' : '❌';
        
        echo '<div style="background: #f8fafc; border: 2px solid ' . $color . '; padding: 20px; margin: 20px 0; border-radius: 8px;">';
        echo '<h3 style="color: ' . $color . '; margin: 0 0 10px 0;">' . $icon . ' TEST SUMMARY</h3>';
        echo '<div style="font-family: monospace; font-size: 14px;">';
        echo "<div>PASSED: <strong style='color: #16a34a;'>{$this->passed}</strong></div>";
        echo "<div>FAILED: <strong style='color: #dc2626;'>{$this->failed}</strong></div>";
        echo "<div>TOTAL: <strong>{$total}</strong></div>";
        echo '</div>';
        
        if ($this->failed == 0) {
            echo '<p style="margin: 10px 0 0 0; color: ' . $color . ';">🎉 All HTTP responses are working correctly!</p>';
        } else {
            echo '<p style="margin: 10px 0 0 0; color: ' . $color . ';">⚠️ Some URLs returned unexpected responses. Review failures above.</p>';
        }
        echo '</div>';
        
        // Display HTTP test results
        if (!empty(HttpTester::$test_results)) {
            echo '<div class="results-section">';
            echo '<h3>📋 HTTP TEST DETAILS</h3>';
            foreach (HttpTester::$test_results as $result) {
                echo '<div style="font-family: monospace; font-size: 14px; margin: 5px 0;">' . htmlspecialchars($result) . '</div>';
            }
            echo '</div>';
        }
    }
}

// Run the tests
$runner = new HttpRoutingTestRunner($settings, $dblink);
$runner->runAllTests();

// Close HTML output
echo '<div style="margin-top: 40px; padding: 20px; background: #f8fafc; border-radius: 8px; color: #64748b; text-align: center;">';
echo 'HTTP test completed at ' . date('Y-m-d H:i:s T') . ' on ' . htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'Unknown');
echo '</div>';
echo '</body></html>';
?>