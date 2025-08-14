 Is this needed?
 
    private function testPluginRoutes() {
        global $is_browser;
        
        if ($is_browser) {
            echo '<div class="test-section"><h3>6. TESTING PLUGIN ROUTES</h3>';
        } else {
            echo "6. TESTING PLUGIN ROUTES\n";
            echo "-----------------------\n";
        }
        
        $test_cases = [];
        
        // Specify specific plugin routes to test - change these paths to match your actual plugin routes
        $test_cases[] = ['/profile/ctld_activation', [200, 302, 401, 403], 'Plugin route (should exist)'];
        
        // Always test nonexistent plugin route
        $test_cases[] = ['/definitely-fake-plugin-12345', 404, 'Plugin route (does not exist)'];
        
        foreach ($test_cases as [$path, $expected_status, $description]) {
            $result = HttpTester::testUrl($path, $expected_status, $description);
            
            if ($result['success']) {
                $this->pass("{$description}: {$path} -> {$result['actual_status']}");
            } else {
                $this->fail("{$description}: {$path} -> {$result['message']}");
            }
        }
        
        if ($is_browser) {
            echo '</div>';
        } else {
            echo "\n";
        }
    }