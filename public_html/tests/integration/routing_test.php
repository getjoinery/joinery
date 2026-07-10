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

/** @joinery-test
 * name: routing
 * tier: safe
 * env: any
 * needs: []
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

// Result helpers funnel into the shared harness. A pass/fail becomes a recorded
// check; an info line opens a new section (its label groups the checks below it).
function output_pass($text) { check(true, $text); }
function output_fail($text) { check(false, $text); }
function output_info($text) { section($text); }

// System dependencies ($settings + $dblink are used throughout). The harness has
// already loaded Globalvars/DbConnector; these require_once calls are no-ops.
try {
    $settings = Globalvars::get_instance();
    $dbconnector = DbConnector::get_instance();
    $dblink = $dbconnector->get_db_link();
} catch (Exception $e) {
    check(false, 'load system settings', $e->getMessage());
    harness_finish();
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
            // Try to get from settings or use fallback. Empty (not just null)
            // must fall back too, so a CLI run with no site_domain still targets
            // a real host instead of degrading to "https://".
            $host = $settings->get_setting('site_domain');
            if (empty($host)) $host = 'dev.getjoinery.com';
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

        // Test Categories
        $this->testPublicPages();
        $this->testStaticFiles();
        $this->testThemeFiles();
        $this->testThemeViews();
        $this->testPluginFiles();
        $this->testPluginViews();
        $this->testAdminAccess();
        $this->testAjaxEndpoints();
        $this->testUtilityPages();
        $this->testContentRoutes();
        $this->testErrorPages();
        $this->testRedirects();
    }
    
    private function testPublicPages() {
        section('1. TESTING PUBLIC PAGES');
        
        $test_cases = [
            // Homepage - always test this
            ['/', 200, 'Homepage'],
        ];
        
        // Check for actual view files that exist
        $view_files_to_check = [
            '/login' => 'Login page',
            '/events' => 'Events page',
            '/register' => 'Register page',
        ];

        foreach ($view_files_to_check as $path => $description) {
            $view_file = PathHelper::getRootDir() . "/views" . $path . ".php";
            if (file_exists($view_file)) {
                $test_cases[] = [$path, 200, "{$description} (exists)"];
            } else {
                $test_cases[] = [$path, 404, "{$description} (not found)"];
            }
        }

        // The products listing is a store-plugin route now — 200 when the store
        // plugin is active, 404 (route gated off) when it isn't.
        $store_active = PluginHelper::isPluginActive('store');
        $test_cases[] = ['/products', $store_active ? 200 : 404,
            'Products page (store ' . ($store_active ? 'active' : 'inactive') . ')'];
        
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
        
    }
    
    private function testStaticFiles() {
        section('2. TESTING STATIC FILES');
        
        $test_cases = [];
        
        // Test files that should exist (either static files or dynamic routes)
        $should_exist_files = [];
        
        // Check if robots.txt exists as static file OR dynamic route (robots.php view)
        if (file_exists(PathHelper::getRootDir() . '/robots.txt')) {
            $should_exist_files['/robots.txt'] = 'Robots.txt (exists)';
        } elseif (file_exists(PathHelper::getRootDir() . '/views/robots.php')) {
            $should_exist_files['/robots.txt'] = 'Robots.txt (exists)';
        }
        
        // Check if sitemap exists as static file OR dynamic route (sitemap.php view) 
        if (file_exists(PathHelper::getRootDir() . '/sitemap.xml')) {
            $should_exist_files['/sitemap.xml'] = 'Sitemap (exists)';
        } elseif (file_exists(PathHelper::getRootDir() . '/views/sitemap.php')) {
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
            $full_path = PathHelper::getRootDir() . $path;
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
        
    }
    
    private function testThemeFiles() {
        section('3. TESTING THEME FILES');
        
        // Get current theme using ThemeHelper
        $current_theme = 'falcon'; // Default fallback
        try {
            require_once(PathHelper::getIncludePath('includes/ThemeHelper.php')); 
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
            $full_path = PathHelper::getRootDir() . $path;
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
        
    }
    
    private function testThemeViews() {
        section('4. TESTING THEME VIEW FILES');
        
        // Get current theme using ThemeHelper
        $current_theme = 'falcon';
        try {
            require_once(PathHelper::getIncludePath('includes/ThemeHelper.php')); 
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
            $theme_view_path = PathHelper::getRootDir() . "/theme/{$current_theme}/views{$route}.php";
            $base_view_path = PathHelper::getRootDir() . "/views{$route}.php";
            
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
            require_once(PathHelper::getIncludePath('data/events_class.php'));
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
        
    }
    
    private function testPluginFiles() {
        section('5. TESTING PLUGIN FILES');
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
        
    }
    
    
    private function testPluginViews() {
        section('6. TESTING PLUGIN VIEW AUTO-DISCOVERY');

        $doc_root = PathHelper::getRootDir();

        // Whitelists: views known to be safe without parameters or session state
        $root_whitelist   = ['index', 'pricing', 'forms_example'];
        $profile_whitelist = ['profile', 'devices', 'test'];

        // Static negative cases — always run regardless of installed plugins
        $static_negative = [
            ['/definitely-fake-plugin-99999/anything',         404, 'Fake plugin name in root namespace'],
            ['/profile/definitely-fake-plugin-99999/anything', 404, 'Fake plugin name in profile namespace'],
        ];
        foreach ($static_negative as [$path, $expected, $description]) {
            $result = HttpTester::testUrl($path, $expected, $description);
            if ($result['success']) {
                $this->pass("{$description}: {$path} -> {$result['actual_status']}");
            } else {
                $this->fail("{$description}: {$path} -> {$result['message']}", $result);
            }
        }

        // Discover active plugins
        $active_plugins = [];
        try {
            $active_plugins = PluginHelper::getActivePlugins();
        } catch (Exception $e) {
            output_info('Could not load active plugins: ' . $e->getMessage());
            return;
        }

        if (empty($active_plugins)) {
            output_info('No active plugins found — skipping plugin view tests');
            return;
        }

        foreach ($active_plugins as $plugin_name => $plugin_info) {
            $views_dir = $doc_root . '/plugins/' . $plugin_name . '/views';
            if (!is_dir($views_dir)) {
                output_info("Plugin '{$plugin_name}' has no views/ directory — skipping");
                continue;
            }

            output_info("Testing plugin: {$plugin_name}");

            // --- Root namespace tests ---

            // Plugin index: /{plugin} resolves to views/index.php
            // Accept 302 for permission-gated indexes (e.g. owner-only dashboards redirect to login)
            $index_file = $views_dir . '/index.php';
            if (file_exists($index_file)) {
                $result = HttpTester::testUrl('/' . $plugin_name, [200, 302], "Plugin {$plugin_name}: index page");
                if ($result['success']) {
                    $this->pass("Plugin {$plugin_name} index: /{$plugin_name} -> {$result['actual_status']}");
                } else {
                    $this->fail("Plugin {$plugin_name} index: /{$plugin_name} -> {$result['message']}", $result);
                }
            }

            // Whitelisted root views: /{plugin}/{view}
            foreach ($root_whitelist as $view_name) {
                if ($view_name === 'index') continue; // Already tested above
                $view_file = $views_dir . '/' . $view_name . '.php';
                if (!file_exists($view_file)) continue;
                $path = '/' . $plugin_name . '/' . $view_name;
                $result = HttpTester::testUrl($path, 200, "Plugin {$plugin_name}: {$view_name}");
                if ($result['success']) {
                    $this->pass("Plugin {$plugin_name} view: {$path} -> {$result['actual_status']}");
                } else {
                    $this->fail("Plugin {$plugin_name} view: {$path} -> {$result['message']}", $result);
                }
            }

            // Non-existent view within real plugin: /{plugin}/definitely-fake-view-99999
            $result = HttpTester::testUrl('/' . $plugin_name . '/definitely-fake-view-99999', 404, "Plugin {$plugin_name}: non-existent root view");
            if ($result['success']) {
                $this->pass("Plugin {$plugin_name} 404: /{$plugin_name}/definitely-fake-view-99999 -> {$result['actual_status']}");
            } else {
                $this->fail("Plugin {$plugin_name} 404: /{$plugin_name}/definitely-fake-view-99999 -> {$result['message']}", $result);
            }

            // --- Profile namespace tests ---
            $profile_dir = $views_dir . '/profile';
            if (is_dir($profile_dir)) {

                // Profile index: /profile/{plugin} resolves to views/profile/index.php
                $profile_index = $profile_dir . '/index.php';
                if (file_exists($profile_index)) {
                    $result = HttpTester::testUrl('/profile/' . $plugin_name, [200, 301, 302], "Plugin {$plugin_name}: profile index");
                    if ($result['success']) {
                        $this->pass("Plugin {$plugin_name} profile index: /profile/{$plugin_name} -> {$result['actual_status']}");
                    } else {
                        $this->fail("Plugin {$plugin_name} profile index: /profile/{$plugin_name} -> {$result['message']}", $result);
                    }
                }

                // Whitelisted profile views: /profile/{plugin}/{view}
                foreach ($profile_whitelist as $view_name) {
                    $view_file = $profile_dir . '/' . $view_name . '.php';
                    if (!file_exists($view_file)) continue;
                    $path = '/profile/' . $plugin_name . '/' . $view_name;
                    // Auth redirect is correct when unauthenticated
                    $result = HttpTester::testUrl($path, [200, 301, 302], "Plugin {$plugin_name}: profile/{$view_name}");
                    if ($result['success']) {
                        $this->pass("Plugin {$plugin_name} profile view: {$path} -> {$result['actual_status']}");
                    } else {
                        $this->fail("Plugin {$plugin_name} profile view: {$path} -> {$result['message']}", $result);
                    }
                }

                // Non-existent profile view: /profile/{plugin}/definitely-fake-view-99999
                $result = HttpTester::testUrl('/profile/' . $plugin_name . '/definitely-fake-view-99999', 404, "Plugin {$plugin_name}: non-existent profile view");
                if ($result['success']) {
                    $this->pass("Plugin {$plugin_name} 404: /profile/{$plugin_name}/definitely-fake-view-99999 -> {$result['actual_status']}");
                } else {
                    $this->fail("Plugin {$plugin_name} 404: /profile/{$plugin_name}/definitely-fake-view-99999 -> {$result['message']}", $result);
                }
            }
        }

    }

    private function testAdminAccess() {
        section('7. TESTING ADMIN ACCESS');
        
            //removed: echo "7. TESTING ADMIN ACCESS\n";
        
        $test_cases = [
            // Existing admin page (should require auth)
            ['/admin/admin_users', [301, 302, 401, 403], 'Existing admin page (should require auth)'],

            // Admin page that doesn't exist
            ['/admin/definitely-fake-admin-page', [302, 404, 401, 403], 'Admin page (does not exist)'],
        ];
        
        foreach ($test_cases as [$path, $expected_status, $description]) {
            $result = HttpTester::testUrl($path, $expected_status, $description);
            
            if ($result['success']) {
                $this->pass("{$description}: {$path} -> {$result['actual_status']}");
            } else {
                $this->fail("{$description}: {$path} -> {$result['message']}", $result);
            }
        }
        
    }
    
    private function testAjaxEndpoints() {
        section('8. TESTING AJAX ENDPOINTS');
        
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
        
    }
    
    private function testUtilityPages() {
        section('9. TESTING UTILITY PAGES');
        
            //removed: echo "9. TESTING UTILITY PAGES\n";
        
        $test_cases = [
            // Existing utility (avoid sync scripts)
            ['/utils/component_preview', [200, 301, 302, 401, 403], 'Existing utility page'],

            // Utility page that doesn't exist
            ['/utils/definitely-fake-utility', [302, 404, 401, 403], 'Utility page (does not exist)'],
        ];
        
        foreach ($test_cases as [$path, $expected_status, $description]) {
            $result = HttpTester::testUrl($path, $expected_status, $description);
            
            if ($result['success']) {
                $this->pass("{$description}: {$path} -> {$result['actual_status']}");
            } else {
                $this->fail("{$description}: {$path} -> {$result['message']}", $result);
            }
        }
        
    }
    
    private function testContentRoutes() {
        section('10. TESTING CONTENT ROUTES');
        
            //removed: echo "10. TESTING CONTENT ROUTES\n";
        
        $test_cases = [];
        
        // Test real event URLs from database
        try {
            require_once(PathHelper::getIncludePath('data/events_class.php'));
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
            require_once(PathHelper::getIncludePath('data/pages_class.php'));
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
        
        // Test real product URLs from database. Product pages are store-plugin
        // routes: 200 when the store is active, 404 (route gated off) otherwise.
        try {
            require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
            $product_status = PluginHelper::isPluginActive('store') ? 200 : 404;
            $products = new MultiProduct(['deleted' => false], ['pro_product_id' => 'DESC'], 2);
            if ($products->count_all() > 0) {
                $products->load();
                $index = 1;
                foreach ($products as $product) {
                    if ($product->get('pro_link')) {
                        $test_cases[] = [$product->get_url(), $product_status, 'Product #' . $index . ' from database'];
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
            output_info("Testing " . count($test_cases) . " content items from database");
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
        
    }
    
    private function testErrorPages() {
        section('11. TESTING ERROR PAGES');
        
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
        
    }
    
    private function testRedirects() {
        section('12. TESTING URL REDIRECTS');
        
            //removed: echo "12. TESTING URL REDIRECTS\n";
        
        $test_cases = [];
        
        // Test a random redirect URL from database
        try {
            require_once(PathHelper::getIncludePath('data/urls_class.php'));
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
                    output_info("Found " . $count . " URLs but none have redirect_url set");
                }
            } else {
                output_info("No URLs found in database");
            }
        } catch (Exception $e) {
            output_info("Could not load URLs: " . $e->getMessage());
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
        
        // Record the failing check, folding the troubleshooting context into
        // the check's detail so it survives into the harness result.
        check(false, $enhanced_message, implode('  ', $troubleshooting_details));
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
    
}

// Run the tests
$runner = new HttpRoutingTestRunner($settings, $dblink);
$runner->runAllTests();

harness_finish();