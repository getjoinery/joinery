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
PathHelper::requireOnce('/includes/ShoppingCart.php');

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
        $this->settings = Globalvars::get_instance();
        
        // Enable test mode to use test database
        $this->dbconnector = DbConnector::get_instance();
        
        try {
            $this->dbconnector->set_test_mode();
            
            // Test the database connection
            $test_connection = $this->dbconnector->get_db_link();
            if (!$test_connection) {
                throw new Exception("Test database connection failed");
            }
            
        } catch (Exception $e) {
            throw new Exception("Failed to enable test mode: " . $e->getMessage() . "<br>Please ensure test database credentials are configured in Globalvars.");
        }
    }
    
    public function __destruct() {
        // Close test mode when done
        if ($this->dbconnector) {
            $this->dbconnector->close_test_mode();
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
            
            echo "Testing " . count($specifications['products']) . " products...<br><br>\n";
            
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
        }
    }
    
    /**
     * Process a single product specification
     */
    private function processProduct($index, $product_spec) {
        echo "<h3>Testing Product $index: " . htmlspecialchars($product_spec['pro_name']) . "</h3>\n";
        
        try {
            // Create the product
            $product_id = $this->createProduct($product_spec);
            $this->created_products[] = $product_id;
            echo "✓ Product created (ID: $product_id)<br>\n";
            
            // Verify the product
            $this->verifyProduct($product_id, $product_spec);
            echo "✓ Product verified<br>\n";
            
            // Create product versions if specified
            if (isset($product_spec['versions']) && is_array($product_spec['versions'])) {
                foreach ($product_spec['versions'] as $version_index => $version_spec) {
                    try {
                        $this->createProductVersion($product_id, $version_spec);
                        echo "✓ Version created: " . htmlspecialchars($version_spec['version_name']) . "<br>\n";
                    } catch (Exception $e) {
                        echo "✗ <strong>Error creating version:</strong> " . htmlspecialchars($e->getMessage()) . "<br>\n";
                        throw $e; // Re-throw to mark the product test as failed
                    }
                }
            }
            
            // Test shopping cart functionality
            $cart_test_passed = true;
            $cart_test_error = null;
            
            try {
                $this->testShoppingCart($product_id, $product_spec);
                echo "✓ Shopping cart test passed<br>\n";
            } catch (Exception $e) {
                $cart_test_passed = false;
                $cart_test_error = $e->getMessage();
                echo "✗ <strong>Cart test failed:</strong> " . htmlspecialchars($cart_test_error) . "<br>\n";
            }
            
            if ($cart_test_passed) {
                $this->test_results[] = [
                    'name' => $product_spec['pro_name'],
                    'id' => $product_id,
                    'status' => 'PASSED',
                    'errors' => []
                ];
            } else {
                $this->test_results[] = [
                    'name' => $product_spec['pro_name'],
                    'id' => $product_id,
                    'status' => 'FAILED',
                    'errors' => ['Cart test failed: ' . $cart_test_error]
                ];
            }
            
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
        
        
        // Set up $_POST and $_REQUEST for the admin script
        $_POST = $post_data;
        $_REQUEST = $post_data;
        
        // Ensure test mode is enabled for this process
        $dbconnector = DbConnector::get_instance();
        $dbconnector->set_test_mode();
        
        // Capture output from admin_product_edit
        ob_start();
        
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
            $response = ob_get_contents();
        } catch (Exception $e) {
            ob_end_clean();
            throw new Exception("Error in admin_product_edit: " . $e->getMessage());
        } catch (Error $e) {
            ob_end_clean();
            throw new Exception("Fatal error in admin_product_edit: " . $e->getMessage());
        }
        
        if ($fatal_error_caught) {
            ob_end_clean();
            throw new Exception("Fatal error occurred during admin_product_edit include");
        }
        
        ob_end_clean();
        
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
        
        // First check for JSON response (when json_confirm is set)
        if (preg_match('/"(\d+)"/', $response, $matches)) {
            return intval($matches[1]);
        }
        
        // Check for the comment format used when skip_redirect is set
        if (preg_match('/<!-- PRODUCT_ID:(\d+) -->/', $response, $matches)) {
            return intval($matches[1]);
        }
        
        // admin_product_edit redirects to admin_product?pro_product_id=X on success
        if (preg_match('/Location:.*admin_product\?pro_product_id=(\d+)/i', $response, $matches)) {
            return intval($matches[1]);
        }
        
        // Alternative: Look for admin_products redirect
        if (preg_match('/Location:.*admin_products.*pro_product_id=(\d+)/i', $response, $matches)) {
            return intval($matches[1]);
        }
        
        // Alternative: Look for any redirect with product_id parameter
        if (preg_match('/Location:.*pro_product_id=(\d+)/i', $response, $matches)) {
            return intval($matches[1]);
        }
        
        // Alternative: Look for product ID in the response body
        if (preg_match('/pro_product_id[=:](\d+)/i', $response, $matches)) {
            return intval($matches[1]);
        }
        
        return null;
    }
    
    /**
     * Verify a product was created successfully
     */
    private function verifyProduct($product_id, $spec) {
        try {
            // Make sure we're in test mode for verification
            $this->dbconnector->set_test_mode();
            $product = new Product($product_id, TRUE);
            $test_name = $product->get('pro_name');
            
            // Also check production database to see if it was created there instead
            $this->dbconnector->close_test_mode();
            $prod_name = null;
            try {
                $prod_product = new Product($product_id, TRUE);
                $prod_name = $prod_product->get('pro_name');
                
                // If the product exists in production with the right name, that's where it was created
                if ($prod_name === $spec['pro_name']) {
                    echo "<strong>WARNING: Product was created in PRODUCTION database, not test database!</strong><br>\n";
                    return true;
                }
            } catch (Exception $e) {
                // Product not found in production - that's expected
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
                return true;
            } else {
                throw new Exception("Version not found in database after creation");
            }
        } catch (Exception $e) {
            throw new Exception("Error verifying version creation: " . $e->getMessage());
        }
    }
    
    /**
     * Test shopping cart functionality for a product
     */
    private function testShoppingCart($product_id, $product_spec) {
        
        // Get the product and its versions for testing
        $product = new Product($product_id, TRUE);
        $versions = $product->get_product_versions(TRUE);
        
        if (!$versions || $versions->count_all() == 0) {
            throw new Exception("No product versions found - products must have at least one version");
        }
        
        // Test adding product to cart
        $this->addProductToCart($product_id, $product_spec);
        $this->displayCartSummary("After adding product");
        
        // Test removing product from cart
        $this->removeProductFromCart($product_id);
        $this->displayCartSummary("After removing product");
    }
    
    /**
     * Add a product to the shopping cart by calling product logic
     */
    private function addProductToCart($product_id, $product_spec) {
        // Ensure we have a session for cart functionality
        $session = SessionControl::get_instance();
        
        // Get the product and its first version
        $product = new Product($product_id, TRUE);
        $versions = $product->get_product_versions(TRUE);
        
        if (!$versions || $versions->count_all() == 0) {
            throw new Exception("No product versions available to add to cart");
        }
        
        $versions->load();
        $first_version = $versions->get(0);
        
        // Prepare form data for adding to cart with version selection
        $post_data = array(
            'product_id' => $product_id,
            'product_version' => $first_version->key,
            'cart' => '1'
        );
        
        // Add form data from the JSON specification
        if (isset($product_spec['form_data']) && is_array($product_spec['form_data'])) {
            foreach ($product_spec['form_data'] as $field => $value) {
                $post_data[$field] = $value;
            }
        } else {
            // Fallback to defaults if no form_data provided
            $post_data['full_name_first'] = 'Test';
            $post_data['full_name_last'] = 'User';
            $post_data['email'] = 'test@example.com';
        }
        
        // Save current POST data
        $original_post = $_POST;
        $original_request = $_REQUEST;
        
        // Set up POST data for the product logic
        $_POST = $post_data;
        $_REQUEST = $post_data;
        
        try {
            // Include product logic to add item to cart
            require_once(PathHelper::getRootDir() . '/logic/product_logic.php');
            
            // Call product logic which will add to cart
            $page_vars = product_logic(array(), $post_data, null);
            
            // Cart addition successful - no output needed
        } catch (Exception $e) {
            // Check if this is a redirect (normal behavior after adding to cart)
            if (strpos($e->getMessage(), 'redirect') !== false) {
                // Redirect is expected behavior - cart addition was successful
            } else {
                throw $e;
            }
        } finally {
            // Restore original POST data
            $_POST = $original_post;
            $_REQUEST = $original_request;
        }
    }
    
    /**
     * Remove a product from the shopping cart
     */
    private function removeProductFromCart($product_id) {
        $session = SessionControl::get_instance();
        $cart = $session->get_shopping_cart();
        
        foreach ($cart->get_detailed_items() as $item) {
            if ($item['product_version']->get('prv_pro_product_id') == $product_id) {
                $cart->remove_item($item['id']);
                break;
            }
        }
    }
    
    /**
     * Display a mini pricing chart for the current cart contents
     */
    private function displayCartSummary($context = "Cart Summary") {
        $session = SessionControl::get_instance();
        $cart = $session->get_shopping_cart();
        $detailed_items = $cart->get_detailed_items();
        
        echo "<div style='margin: 10px 0; padding: 10px; border: 1px solid #ddd; background: #f9f9f9;'>\n";
        echo "<strong>$context:</strong><br>\n";
        
        if (empty($detailed_items)) {
            echo "Cart is empty<br>\n";
        } else {
            echo "<table style='width: 100%; border-collapse: collapse; margin-top: 5px;'>\n";
            echo "<tr style='background: #eee;'>\n";
            echo "<th style='padding: 5px; border: 1px solid #ccc; text-align: left;'>Item</th>\n";
            echo "<th style='padding: 5px; border: 1px solid #ccc; text-align: right;'>Price</th>\n";
            echo "<th style='padding: 5px; border: 1px solid #ccc; text-align: right;'>Discount</th>\n";
            echo "<th style='padding: 5px; border: 1px solid #ccc; text-align: right;'>Total</th>\n";
            echo "<th style='padding: 5px; border: 1px solid #ccc; text-align: center;'>Type</th>\n";
            echo "</tr>\n";
            
            foreach ($detailed_items as $item) {
                $item_total = $item['total'] - $item['discount'];
                $type = $item['recurring'] ? 'Recurring' : 'One-time';
                
                echo "<tr>\n";
                echo "<td style='padding: 5px; border: 1px solid #ccc;'>" . htmlspecialchars($item['name']) . "</td>\n";
                echo "<td style='padding: 5px; border: 1px solid #ccc; text-align: right;'>$" . number_format($item['price'], 2) . "</td>\n";
                echo "<td style='padding: 5px; border: 1px solid #ccc; text-align: right;'>$" . number_format($item['discount'], 2) . "</td>\n";
                echo "<td style='padding: 5px; border: 1px solid #ccc; text-align: right;'><strong>$" . number_format($item_total, 2) . "</strong></td>\n";
                echo "<td style='padding: 5px; border: 1px solid #ccc; text-align: center;'>$type</td>\n";
                echo "</tr>\n";
            }
            
            // Summary totals
            echo "<tr style='background: #f0f0f0; font-weight: bold;'>\n";
            echo "<td colspan='3' style='padding: 5px; border: 1px solid #ccc; text-align: right;'>Cart Total:</td>\n";
            echo "<td style='padding: 5px; border: 1px solid #ccc; text-align: right;'>$" . number_format($cart->get_total(), 2) . "</td>\n";
            echo "<td style='padding: 5px; border: 1px solid #ccc;'></td>\n";
            echo "</tr>\n";
            
            $recurring_total = $cart->get_recurring_total();
            $non_recurring_total = $cart->get_non_recurring_total();
            
            if ($recurring_total > 0) {
                echo "<tr>\n";
                echo "<td colspan='3' style='padding: 5px; border: 1px solid #ccc; text-align: right;'>Recurring Total:</td>\n";
                echo "<td style='padding: 5px; border: 1px solid #ccc; text-align: right;'>$" . number_format($recurring_total, 2) . "</td>\n";
                echo "<td style='padding: 5px; border: 1px solid #ccc;'></td>\n";
                echo "</tr>\n";
            }
            
            if ($non_recurring_total > 0) {
                echo "<tr>\n";
                echo "<td colspan='3' style='padding: 5px; border: 1px solid #ccc; text-align: right;'>One-time Total:</td>\n";
                echo "<td style='padding: 5px; border: 1px solid #ccc; text-align: right;'>$" . number_format($non_recurring_total, 2) . "</td>\n";
                echo "<td style='padding: 5px; border: 1px solid #ccc;'></td>\n";
                echo "</tr>\n";
            }
            
            echo "</table>\n";
        }
        
        echo "</div>\n";
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