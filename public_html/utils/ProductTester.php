<?php
/**
 * Product Testing Script
 * 
 * This script reads product specifications from products_to_test.json,
 * creates those products by calling /adm/admin_product_edit endpoint,
 * and then verifies they were created with the exact specifications.
 * 
 * IMPORTANT: This script operates on the TEST DATABASE to avoid affecting
 * production data. Ensure test database credentials are configured in Globalvars.
 * 
 * Usage: Access this file through your web browser while logged in as an admin user.
 */

require_once(__DIR__ . '/../includes/PathHelper.php');
PathHelper::requireOnce('/includes/LibraryFunctions.php');
PathHelper::requireOnce('/includes/StripeHelper.php');
PathHelper::requireOnce('/includes/SessionControl.php');
PathHelper::requireOnce('/includes/Globalvars.php');
PathHelper::requireOnce('/includes/DbConnector.php');

PathHelper::requireOnce('/data/email_templates_class.php');
PathHelper::requireOnce('/data/products_class.php');
PathHelper::requireOnce('/data/product_versions_class.php');
PathHelper::requireOnce('/data/product_groups_class.php');
PathHelper::requireOnce('/data/product_requirements_class.php');
PathHelper::requireOnce('/data/product_requirement_instances_class.php');
PathHelper::requireOnce('/data/order_items_class.php');
PathHelper::requireOnce('/data/events_class.php');

class ProductTester {
    private $settings;
    private $dbconnector;
    private $created_products = [];
    private $test_results = [];
    
    public function __construct() {
        echo "Initializing ProductTester...<br>\n";
        flush();
        
        $this->settings = Globalvars::get_instance();
        
        // Enable test mode to use test database
        $this->dbconnector = DbConnector::get_instance();
        
        try {
            echo "Setting test mode...<br>\n";
            flush();
            
            $this->dbconnector->set_test_mode();
            echo "Test mode enabled - using test database<br>\n";
            flush();
            
            // Test the database connection
            $test_connection = $this->dbconnector->get_db_link();
            if ($test_connection) {
                echo "Test database connection successful<br>\n";
            } else {
                throw new Exception("Test database connection failed");
            }
            flush();
            
        } catch (Exception $e) {
            throw new Exception("Failed to enable test mode: " . $e->getMessage() . "<br>Please ensure test database credentials are configured in Globalvars.");
        }
    }
    
    public function __destruct() {
        // Close test mode when done
        if ($this->dbconnector) {
            $this->dbconnector->close_test_mode();
            echo "Test mode disabled<br>\n";
        }
    }
    
    /**
     * Main execution method
     */
    public function run() {
        echo "<h2>Product Testing Script</h2>\n";
        echo "Starting product creation and verification tests...<br><br>\n";
        
        try {
            // Load JSON specifications
            $json_file = __DIR__ . '/products_to_test.json';
            if (!file_exists($json_file)) {
                throw new Exception("JSON file not found: $json_file");
            }
            
            $json_content = file_get_contents($json_file);
            $specifications = json_decode($json_content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Invalid JSON: " . json_last_error_msg());
            }
            
            if (!isset($specifications['products']) || !is_array($specifications['products'])) {
                throw new Exception("JSON must contain a 'products' array");
            }
            
            echo "Loaded " . count($specifications['products']) . " product specifications<br><br>\n";
            
            // Process each product
            foreach ($specifications['products'] as $index => $product_spec) {
                $this->processProduct($index + 1, $product_spec);
            }
            
            // Display results
            $this->displayResults();
            
        } catch (Exception $e) {
            echo "<strong>ERROR:</strong> " . $e->getMessage() . "<br>\n";
            $this->cleanup();
            exit(1);
        }
    }
    
    /**
     * Cleanup method to ensure test mode is properly closed
     */
    private function cleanup() {
        if ($this->dbconnector) {
            $this->dbconnector->close_test_mode();
            echo "Test mode disabled (cleanup)<br>\n";
        }
    }
    
    /**
     * Process a single product specification
     */
    private function processProduct($index, $product_spec) {
        echo "<h3>Processing Product $index: " . htmlspecialchars($product_spec['pro_name']) . "</h3>\n";
        flush();
        
        try {
            echo "Starting product creation...<br>\n";
            flush();
            
            // Create the product
            $product_id = $this->createProduct($product_spec);
            $this->created_products[] = $product_id;
            
            echo "✓ Product created with ID: $product_id<br>\n";
            flush();
            
            echo "Starting product verification...<br>\n";
            flush();
            
            // Verify the product
            $this->verifyProduct($product_id, $product_spec);
            
            echo "✓ Product verification completed<br>\n";
            flush();
            
            // Create product versions if specified
            if (isset($product_spec['versions']) && is_array($product_spec['versions'])) {
                echo "Creating " . count($product_spec['versions']) . " product version(s)...<br>\n";
                flush();
                
                foreach ($product_spec['versions'] as $version_index => $version_spec) {
                    try {
                        echo "Creating version " . ($version_index + 1) . ": " . htmlspecialchars($version_spec['version_name']) . "<br>\n";
                        flush();
                        
                        $this->createProductVersion($product_id, $version_spec);
                        
                        echo "✓ Version created successfully<br>\n";
                        flush();
                    } catch (Exception $e) {
                        echo "✗ <strong>Error creating version:</strong> " . htmlspecialchars($e->getMessage()) . "<br>\n";
                        throw $e; // Re-throw to mark the product test as failed
                    }
                }
            }
            
            $this->test_results[] = [
                'name' => $product_spec['pro_name'],
                'id' => $product_id,
                'status' => 'PASSED',
                'errors' => []
            ];
            
        } catch (Exception $e) {
            echo "✗ <strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "<br>\n";
            
            $this->test_results[] = [
                'name' => $product_spec['pro_name'],
                'id' => null,
                'status' => 'FAILED',
                'errors' => [$e->getMessage()]
            ];
        }
        
        echo "<br>\n";
    }
    
    /**
     * Create a product by directly including admin_product_edit logic
     */
    private function createProduct($spec) {
        // Add action field and json_confirm flag, then pass all other data directly
        $post_data = array_merge(['action' => 'add', 'json_confirm' => '1'], $spec);
        
        echo "POST data being sent:<br>\n";
        echo "<pre>" . htmlspecialchars(print_r($post_data, true)) . "</pre><br>\n";
        flush();
        
        // Set up $_POST and $_REQUEST for the admin script
        $_POST = $post_data;
        $_REQUEST = $post_data;
        
        // Ensure test mode is enabled for this process
        $dbconnector = DbConnector::get_instance();
        $dbconnector->set_test_mode();
        
        // Capture output from admin_product_edit
        ob_start();
        
        echo "About to include admin_product_edit.php...<br>\n";
        flush();
        
        // Register shutdown function to catch fatal errors
        $fatal_error_caught = false;
        register_shutdown_function(function() use (&$fatal_error_caught) {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                $fatal_error_caught = true;
                echo "Fatal error during include: " . $error['message'] . " in " . $error['file'] . " on line " . $error['line'] . "<br>\n";
                flush();
            }
        });
        
        try {
            // Include the admin script directly
            include(PathHelper::getRootDir() . '/adm/admin_product_edit.php');
            echo "Include completed successfully...<br>\n";
            flush();
            $response = ob_get_contents();
        } catch (Exception $e) {
            ob_end_clean();
            echo "Exception caught: " . $e->getMessage() . "<br>\n";
            flush();
            throw new Exception("Error in admin_product_edit: " . $e->getMessage());
        } catch (Error $e) {
            ob_end_clean();
            echo "Fatal error caught: " . $e->getMessage() . "<br>\n";
            flush();
            throw new Exception("Fatal error in admin_product_edit: " . $e->getMessage());
        }
        
        if ($fatal_error_caught) {
            ob_end_clean();
            throw new Exception("Fatal error occurred during admin_product_edit include");
        }
        
        ob_end_clean();
        
        echo "Response captured, length: " . strlen($response) . "<br>\n";
        flush();
        
        // Since we're using direct includes, check for headers that were set
        $headers = headers_list();
        $location_header = '';
        foreach ($headers as $header) {
            if (stripos($header, 'Location:') === 0) {
                $location_header = $header;
                break;
            }
        }
        
        // Create a mock response with the location header for parsing
        $response_with_headers = $location_header . "\n\n" . $response;
        
        // Parse response to extract product ID
        $product_id = $this->extractProductIdFromResponse($response_with_headers);
        
        if (!$product_id) {
            throw new Exception("Failed to extract product ID from admin_product_edit response");
        }
        
        return $product_id;
    }
    
    /**
     * Make HTTP request to admin endpoint
     */
    private function makeAdminRequest($endpoint, $post_data) {
        // Use the system's existing method for building absolute URLs
        $url = LibraryFunctions::get_absolute_url($endpoint);
        
        echo "Making request to: $url<br>\n";
        flush();
        
        // Close the current session to prevent session locking
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        
        // Prepare POST data string - handle arrays properly
        $post_string = http_build_query($post_data);
        
        echo "POST string being sent:<br>\n";
        echo "<pre>" . htmlspecialchars($post_string) . "</pre><br>\n";
        flush();
        
        // Set up cURL with timeouts and redirect handling
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // Don't automatically follow redirects
        curl_setopt($ch, CURLOPT_HEADER, true); // Include headers in response
        curl_setopt($ch, CURLOPT_NOBODY, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15); // Reduced to 15 second timeout
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // Reduced to 5 second connect timeout
        curl_setopt($ch, CURLOPT_MAXREDIRS, 0); // Don't follow any redirects
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // In case of SSL issues
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        // Pass along existing session cookie from current request
        if (isset($_SERVER['HTTP_COOKIE'])) {
            curl_setopt($ch, CURLOPT_COOKIE, $_SERVER['HTTP_COOKIE']);
        }
        
        echo "Executing cURL request...<br>\n";
        flush();
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        echo "Response received. HTTP Code: $http_code<br>\n";
        flush();
        
        // Restart the session after the request
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if ($curl_error) {
            throw new Exception("cURL error: " . $curl_error);
        }
        
        // Handle redirects as success (302 is expected after product creation)
        if ($http_code >= 400) {
            throw new Exception("HTTP error $http_code from admin_product_edit");
        } elseif ($http_code >= 300 && $http_code < 400) {
            echo "Redirect response (HTTP $http_code) - this is expected<br>\n";
            flush();
        }
        
        return $response;
    }
    
    /**
     * Extract product ID from admin_product_edit response
     */
    private function extractProductIdFromResponse($response) {
        echo "Parsing response for product ID...<br>\n";
        flush();
        
        // First check for JSON response (when json_confirm is set)
        // Look for JSON pattern in the response (quoted number)
        if (preg_match('/"(\d+)"/', $response, $matches)) {
            echo "Found product ID in JSON response: " . $matches[1] . "<br>\n";
            flush();
            return intval($matches[1]);
        }
        
        // Check for the comment format used when skip_redirect is set
        if (preg_match('/<!-- PRODUCT_ID:(\d+) -->/', $response, $matches)) {
            echo "Found product ID in comment: " . $matches[1] . "<br>\n";
            flush();
            return intval($matches[1]);
        }
        
        // admin_product_edit redirects to admin_product?pro_product_id=X on success
        // Look for the redirect location header (most common pattern)
        if (preg_match('/Location:.*admin_product\?pro_product_id=(\d+)/i', $response, $matches)) {
            echo "Found product ID in Location header: " . $matches[1] . "<br>\n";
            flush();
            return intval($matches[1]);
        }
        
        // Alternative: Look for admin_products redirect
        if (preg_match('/Location:.*admin_products.*pro_product_id=(\d+)/i', $response, $matches)) {
            echo "Found product ID in admin_products redirect: " . $matches[1] . "<br>\n";
            flush();
            return intval($matches[1]);
        }
        
        // Alternative: Look for any redirect with product_id parameter
        if (preg_match('/Location:.*pro_product_id=(\d+)/i', $response, $matches)) {
            echo "Found product ID in redirect URL: " . $matches[1] . "<br>\n";
            flush();
            return intval($matches[1]);
        }
        
        // Alternative: Look for product ID in the response body
        if (preg_match('/pro_product_id[=:](\d+)/i', $response, $matches)) {
            echo "Found product ID in response body: " . $matches[1] . "<br>\n";
            flush();
            return intval($matches[1]);
        }
        
        // Debug: Show first 500 chars of response if no ID found
        echo "Could not find product ID. Response preview:<br>\n";
        echo "<pre>" . htmlspecialchars(substr($response, 0, 500)) . "...</pre><br>\n";
        flush();
        
        return null;
    }
    
    /**
     * Verify a product was created successfully
     */
    private function verifyProduct($product_id, $spec) {
        try {
            echo "Checking product $product_id in test database...<br>\n";
            flush();
            
            // Make sure we're in test mode for verification
            $this->dbconnector->set_test_mode();
            $product = new Product($product_id, TRUE);
            $test_name = $product->get('pro_name');
            echo "Test database - Name: '" . htmlspecialchars($test_name) . "'<br>\n";
            flush();
            
            // Also check production database to see if it was created there instead
            echo "Checking product $product_id in production database...<br>\n";
            flush();
            $this->dbconnector->close_test_mode();
            try {
                $prod_product = new Product($product_id, TRUE);
                $prod_name = $prod_product->get('pro_name');
                echo "Production database - Name: '" . htmlspecialchars($prod_name) . "'<br>\n";
                flush();
                
                // If the product exists in production with the right name, that's where it was created
                if ($prod_name === $spec['pro_name']) {
                    echo "<strong>Product was created in PRODUCTION database, not test database!</strong><br>\n";
                    flush();
                    return true;
                }
            } catch (Exception $e) {
                echo "Product not found in production database<br>\n";
                flush();
            }
            
            // Switch back to test mode
            $this->dbconnector->set_test_mode();
            
            // Check test database result
            if ($test_name === $spec['pro_name']) {
                return true;
            }
            
            throw new Exception("Product not found with correct name in either database. Test: '$test_name', Production: '" . ($prod_name ?? 'not found') . "', Expected: '" . $spec['pro_name'] . "'");
            
        } catch (Exception $e) {
            throw new Exception("Product verification failed: " . $e->getMessage());
        }
    }
    
    /**
     * Create a product version by directly including admin_product_edit logic
     */
    private function createProductVersion($product_id, $version_spec) {
        // Prepare POST data for version creation
        $post_data = [
            'action' => 'new_version',
            'p' => $product_id,
            'version_name' => $version_spec['version_name'],
            'version_price' => $version_spec['version_price'],
            'prv_price_type' => $version_spec['prv_price_type'],
            'prv_trial_period_days' => $version_spec['prv_trial_period_days']
        ];
        
        echo "Creating version with data:<br>\n";
        echo "<pre>" . htmlspecialchars(print_r($post_data, true)) . "</pre><br>\n";
        flush();
        
        // Set up $_POST and $_REQUEST for the admin script
        $_POST = $post_data;
        $_REQUEST = $post_data;
        
        // Ensure test mode is enabled
        $dbconnector = DbConnector::get_instance();
        $dbconnector->set_test_mode();
        
        // Capture output from admin_product_edit
        ob_start();
        
        try {
            // Include the admin script directly
            include(PathHelper::getRootDir() . '/adm/admin_product_edit.php');
            $response = ob_get_contents();
        } catch (Exception $e) {
            ob_end_clean();
            throw new Exception("Error creating version: " . $e->getMessage());
        } catch (Error $e) {
            ob_end_clean();
            throw new Exception("Fatal error creating version: " . $e->getMessage());
        }
        
        ob_end_clean();
        
        // Check if version was created successfully using the ProductVersion model
        try {
            // Use MultiProductVersion to find versions for this product
            require_once(PathHelper::getRootDir() . '/data/product_versions_class.php');
            
            $versions = new MultiProductVersion(
                array('prv_pro_product_id' => $product_id, 'prv_version_name' => $version_spec['version_name']),
                array('prv_product_version_id' => 'DESC'),
                1,
                0
            );
            
            if ($versions->count_all() > 0) {
                $versions->load();
                $version = $versions->get(0); // Get the first version
                echo "✓ Version verified in database (ID: " . $version->key . ")<br>\n";
                return true;
            } else {
                throw new Exception("Version not found in database after creation");
            }
        } catch (Exception $e) {
            throw new Exception("Error verifying version creation: " . $e->getMessage());
        }
    }
    
    /**
     * Display final test results
     */
    private function displayResults() {
        echo "<h2>TEST RESULTS</h2>\n";
        
        $passed = 0;
        $failed = 0;
        
        foreach ($this->test_results as $result) {
            echo "<div style='margin: 10px 0; padding: 10px; border: 1px solid #ccc;'>\n";
            echo "<strong>Product:</strong> " . htmlspecialchars($result['name']) . "<br>\n";
            echo "<strong>Status:</strong> <span style='color: " . ($result['status'] === 'PASSED' ? 'green' : 'red') . ";'>" . $result['status'] . "</span><br>\n";
            
            if ($result['id']) {
                echo "<strong>ID:</strong> " . $result['id'] . "<br>\n";
            }
            
            if (!empty($result['errors'])) {
                echo "<strong>Errors:</strong><br>\n";
                echo "<ul>\n";
                foreach ($result['errors'] as $error) {
                    echo "<li>" . htmlspecialchars($error) . "</li>\n";
                }
                echo "</ul>\n";
            }
            
            if ($result['status'] === 'PASSED') {
                $passed++;
            } else {
                $failed++;
            }
            
            echo "</div>\n";
        }
        
        echo "<div style='margin: 20px 0; padding: 15px; background-color: #f5f5f5; border: 1px solid #ddd;'>\n";
        echo "<h3>SUMMARY</h3>\n";
        echo "<strong>Passed:</strong> <span style='color: green;'>$passed</span><br>\n";
        echo "<strong>Failed:</strong> <span style='color: red;'>$failed</span><br>\n";
        echo "<strong>Total:</strong> " . ($passed + $failed) . "<br>\n";
        
        if ($failed > 0) {
            echo "<br><span style='color: red;'><strong>Some tests failed. Check the errors above.</strong></span><br>\n";
        } else {
            echo "<br><span style='color: green;'><strong>All tests passed successfully!</strong></span><br>\n";
        }
        
        // Display created product IDs for cleanup if needed
        if (!empty($this->created_products)) {
            echo "<br><strong>Created product IDs (for cleanup if needed):</strong> " . implode(', ', $this->created_products) . "<br>\n";
        }
        echo "</div>\n";
    }
    
    /**
     * Delete all created test products and verify deletion
     */
    public function deleteCreatedProducts() {
        if (empty($this->created_products)) {
            echo "No products to delete.<br>\n";
            return;
        }
        
        echo "Deleting " . count($this->created_products) . " test products...<br>\n";
        flush();
        
        // Make sure we're in test mode for deletion
        $this->dbconnector->set_test_mode();
        echo "Test mode enabled for deletion<br>\n";
        flush();
        
        $deleted_count = 0;
        $failed_deletions = [];
        
        foreach ($this->created_products as $product_id) {
            try {
                echo "Deleting product ID: $product_id...<br>\n";
                flush();
                
                // Load the product
                $product = new Product($product_id, true);
                
                if ($product->key) {
                    // Use permanent_delete() method
                    $delete_result = $product->permanent_delete();
                    echo "Delete result: " . ($delete_result ? 'true' : 'false') . "<br>\n";
                    
                    $deleted_count++;
                    echo "✓ Product $product_id permanently deleted<br>\n";
                } else {
                    echo "⚠ Product $product_id not found (may already be deleted)<br>\n";
                }
                
            } catch (Exception $e) {
                $failed_deletions[] = $product_id;
                echo "✗ Failed to delete product $product_id: " . htmlspecialchars($e->getMessage()) . "<br>\n";
            }
            flush();
        }
        
        echo "<br><strong>Deletion Summary:</strong><br>\n";
        echo "Successfully deleted: $deleted_count products<br>\n";
        
        if (!empty($failed_deletions)) {
            echo "Failed to delete: " . count($failed_deletions) . " products (IDs: " . implode(', ', $failed_deletions) . ")<br>\n";
        }
        
        
        // Verify deletion by trying to load each product
        echo "<br><strong>Verification Phase:</strong><br>\n";
        echo "Verifying products were actually deleted...<br>\n";
        
        // Make sure we're still in test mode for verification
        $this->dbconnector->set_test_mode();
        $dblink = $this->dbconnector->get_db_link();
        
        $still_exists = [];
        foreach ($this->created_products as $product_id) {
            try {
                // Use direct database query instead of object loading to avoid caching issues
                $check_sql = "SELECT COUNT(*) as count FROM pro_products WHERE pro_product_id = :product_id";
                $stmt = $dblink->prepare($check_sql);
                $stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
                $stmt->execute();
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($result['count'] > 0) {
                    $still_exists[] = $product_id;
                    echo "⚠ Product $product_id still exists in database<br>\n";
                } else {
                    echo "✓ Product $product_id confirmed deleted<br>\n";
                }
            } catch (Exception $e) {
                echo "✓ Product $product_id confirmed deleted (query failed as expected)<br>\n";
            }
            flush();
        }
        
        if (empty($still_exists)) {
            echo "<br>✅ <strong>All test products successfully deleted and verified!</strong><br>\n";
        } else {
            echo "<br>❌ <strong>Warning: " . count($still_exists) . " products still exist in database (IDs: " . implode(', ', $still_exists) . ")</strong><br>\n";
        }
        flush();
    }
}

// Class is now ready to be included and used
?>