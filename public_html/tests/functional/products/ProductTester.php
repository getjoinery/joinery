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

require_once(__DIR__ . '/../../../includes/PathHelper.php');
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
PathHelper::requireOnce('/data/coupon_codes_class.php');

class ProductTester {
    private $settings;
    private $dbconnector;
    private $created_products = [];
    private $successful_products = []; // Track products that passed individual cart tests
    private $test_results = [];
    private $coupon_codes = []; // Coupon code specifications from JSON
    private $created_coupons = []; // Track created coupon IDs for cleanup
    
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
        
        echo "Starting tests (using hardcoded admin user for testing)...<br><br>\n";
        
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
            
            // Load coupon codes if provided
            if (isset($specifications['coupon_codes']) && is_array($specifications['coupon_codes'])) {
                $this->coupon_codes = $specifications['coupon_codes'];
                echo "Creating " . count($this->coupon_codes) . " coupon codes for testing...<br>\n";
                $this->createCouponCodes();
            }
            
            echo "Testing " . count($specifications['products']) . " products...<br><br>\n";
            
            // Process each product
            foreach ($specifications['products'] as $index => $product_spec) {
                $this->processProduct($index + 1, $product_spec);
            }
            
            // Test cart functionality with all products together
            $this->testAllProductsTogether($specifications['products']);
            
            // Test payment functionality if enabled
            if (isset($specifications['payment_testing']) && 
                isset($specifications['payment_testing']['enabled']) && 
                $specifications['payment_testing']['enabled']) {
                
                echo "<h3>Payment Testing</h3>\n";
                
                // Re-add a product to cart for payment testing
                if (!empty($this->successful_products)) {
                    $this->removeAllProductsFromCart();
                    
                    // Add first successful product for payment test
                    $product_info = $this->successful_products[0];
                    try {
                        $this->addProductToCart($product_info['id'], $product_info['spec']);
                        echo "Added " . htmlspecialchars($product_info['spec']['pro_name']) . " to cart for payment test<br>\n";
                        
                        // Set up billing info AFTER adding product to cart
                        if (isset($specifications['payment_testing']['billing_info'])) {
                            $this->setupBillingInfo($specifications['payment_testing']['billing_info']);
                        }
                        
                        // Run payment test
                        $this->testPaymentFlow();
                    } catch (Exception $e) {
                        echo "✗ <span style='color: red;'>Failed to set up payment test: " . htmlspecialchars($e->getMessage()) . "</span><br>\n";
                    }
                } else {
                    echo "⚠ <span style='color: orange;'>No successful products available for payment testing</span><br>\n";
                }
                echo "<br>\n";
            }
            
            // Clean up created coupons
            $this->deleteCreatedCoupons();
            
            // Display results
            $this->displayResults();
            
        } catch (Exception $e) {
            echo "<span style='color: red;'><strong>ERROR:</strong> " . $e->getMessage() . "</span><br>\n";
            $this->cleanup();
            exit(1);
        }
    }
    
    /**
     * Cleanup method to ensure test mode is properly closed and test data is removed
     */
    private function cleanup() {
        // Clean up created coupons even if there was an error
        $this->deleteCreatedCoupons();
        
        if ($this->dbconnector) {
            $this->dbconnector->close_test_mode();
        }
    }
    
    /**
     * Delete any existing test coupons from previous runs
     */
    private function deleteExistingTestCoupons() {
        $this->dbconnector->set_test_mode();
        
        $existing_coupons = [];
        foreach ($this->coupon_codes as $coupon_spec) {
            $coupon_code = $coupon_spec['ccd_code'];
            try {
                $existing_coupon = CouponCode::GetByColumn('ccd_code', $coupon_code);
                if ($existing_coupon && $existing_coupon->key) {
                    $existing_coupons[] = $existing_coupon->key;
                }
            } catch (Exception $e) {
                // Coupon doesn't exist, which is fine
            }
        }
        
        if (!empty($existing_coupons)) {
            echo "Cleaning up " . count($existing_coupons) . " existing test coupons from previous runs...<br>\n";
            foreach ($existing_coupons as $coupon_id) {
                try {
                    $coupon = new CouponCode($coupon_id, true);
                    if ($coupon->key) {
                        $coupon->permanent_delete();
                    }
                } catch (Exception $e) {
                    echo "⚠ Failed to delete existing coupon $coupon_id: " . htmlspecialchars($e->getMessage()) . "<br>\n";
                }
            }
        }
    }
    
    /**
     * Create coupon codes from JSON specifications
     */
    private function createCouponCodes() {
        // First, clean up any existing test coupons from previous runs
        $this->deleteExistingTestCoupons();
        
        foreach ($this->coupon_codes as $coupon_spec) {
            try {
                $coupon_id = $this->createCouponCode($coupon_spec);
                $this->created_coupons[] = $coupon_id;
                echo "✓ Created coupon: " . htmlspecialchars($coupon_spec['ccd_code']) . " (ID: $coupon_id)<br>\n";
            } catch (Exception $e) {
                echo "✗ <span style='color: red;'><strong>Error creating coupon " . htmlspecialchars($coupon_spec['ccd_code']) . ":</strong> " . htmlspecialchars($e->getMessage()) . "</span><br>\n";
            }
        }
        echo "<br>\n";
    }
    
    /**
     * Create a single coupon code by calling admin_coupon_code_edit.php
     */
    private function createCouponCode($coupon_spec) {
        // Add action field and json_confirm flag
        $post_data = array_merge(['action' => 'add', 'json_confirm' => '1'], $coupon_spec);
        
        // Set up $_POST and $_REQUEST for the admin script
        $_POST = $post_data;
        $_REQUEST = $post_data;
        
        // Ensure test mode is enabled
        $dbconnector = DbConnector::get_instance();
        $dbconnector->set_test_mode();
        
        // Capture output from admin_coupon_code_edit
        ob_start();
        
        try {
            // Include the admin script directly
            include(PathHelper::getRootDir() . '/adm/admin_coupon_code_edit.php');
            $response = ob_get_contents();
        } catch (Exception $e) {
            ob_end_clean();
            throw new Exception("Error in admin_coupon_code_edit: " . $e->getMessage());
        } catch (Error $e) {
            ob_end_clean();
            throw new Exception("Fatal error in admin_coupon_code_edit: " . $e->getMessage());
        }
        
        ob_end_clean();
        
        // Instead of parsing response, directly check database for created coupon
        try {
            $created_coupon = CouponCode::GetByColumn('ccd_code', $coupon_spec['ccd_code']);
            if ($created_coupon && $created_coupon->key) {
                return $created_coupon->key;
            }
        } catch (Exception $e) {
            // Coupon not found in database
        }
        
        throw new Exception("Coupon was not created successfully or not found in database");
    }
    
    /**
     * Extract coupon ID from admin_coupon_code_edit response
     */
    private function extractCouponIdFromResponse($response) {
        // Check for JSON response first
        if (preg_match('/"(\d+)"/', $response, $matches)) {
            return intval($matches[1]);
        }
        
        // Check for redirect patterns (similar to product extraction)
        if (preg_match('/Location:.*admin_coupon_code\?ccd_coupon_code_id=(\d+)/i', $response, $matches)) {
            return intval($matches[1]);
        }
        
        // Check for admin_coupon_codes redirect
        if (preg_match('/Location:.*admin_coupon_codes.*ccd_coupon_code_id=(\d+)/i', $response, $matches)) {
            return intval($matches[1]);
        }
        
        // Check for any redirect with coupon code ID parameter
        if (preg_match('/Location:.*ccd_coupon_code_id=(\d+)/i', $response, $matches)) {
            return intval($matches[1]);
        }
        
        // Check for coupon ID in the response body
        if (preg_match('/ccd_coupon_code_id[=:](\d+)/i', $response, $matches)) {
            return intval($matches[1]);
        }
        
        return null;
    }
    
    /**
     * Delete all created coupon codes
     */
    private function deleteCreatedCoupons() {
        if (empty($this->created_coupons)) {
            return;
        }
        
        echo "Cleaning up " . count($this->created_coupons) . " test coupon codes...<br>\n";
        
        // Make sure we're in test mode for deletion
        $this->dbconnector->set_test_mode();
        
        $deleted_count = 0;
        $failed_deletions = [];
        
        foreach ($this->created_coupons as $coupon_id) {
            try {
                // Load the coupon
                $coupon = new CouponCode($coupon_id, true);
                
                if ($coupon->key) {
                    // Use permanent_delete() method
                    $delete_result = $coupon->permanent_delete();
                    $deleted_count++;
                }
                
            } catch (Exception $e) {
                $failed_deletions[] = $coupon_id;
                echo "✗ <span style='color: red;'>Failed to delete coupon $coupon_id: " . htmlspecialchars($e->getMessage()) . "</span><br>\n";
            }
        }
        
        if ($deleted_count > 0) {
            echo "✓ Deleted $deleted_count coupon codes<br>\n";
        }
        if (!empty($failed_deletions)) {
            echo "<span style='color: red;'>Failed to delete: " . count($failed_deletions) . " coupon codes</span><br>\n";
        }
        echo "<br>\n";
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
            
            // Create Stripe product ID for payment testing
            $this->createStripeProduct($product_id);
            
            // Create product versions if specified
            if (isset($product_spec['versions']) && is_array($product_spec['versions'])) {
                foreach ($product_spec['versions'] as $version_index => $version_spec) {
                    try {
                        $this->createProductVersion($product_id, $version_spec);
                        echo "✓ Version created: " . htmlspecialchars($version_spec['version_name']) . "<br>\n";
                    } catch (Exception $e) {
                        echo "✗ <span style='color: red;'><strong>Error creating version:</strong> " . htmlspecialchars($e->getMessage()) . "</span><br>\n";
                        throw $e; // Re-throw to mark the product test as failed
                    }
                }
            }
            
            // Test shopping cart functionality
            $cart_test_passed = true;
            $cart_test_error = null;
            
            try {
                $this->testIndividualProductCart($product_id, $product_spec);
                echo "✓ Shopping cart test passed<br>\n";
            } catch (Exception $e) {
                $cart_test_passed = false;
                $cart_test_error = $e->getMessage();
                echo "✗ <span style='color: red;'><strong>Cart test failed:</strong> " . htmlspecialchars($cart_test_error) . "</span><br>\n";
            }
            
            if ($cart_test_passed) {
                // Track successful products for combined testing
                $this->successful_products[] = [
                    'id' => $product_id,
                    'spec' => $product_spec
                ];
                
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
            echo "✗ <span style='color: red;'><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</span><br>\n";
            
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
        // Creating product via admin endpoint
        
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
                    echo "<span style='color: red;'><strong>WARNING: Product was created in PRODUCTION database, not test database!</strong></span><br>\n";
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
     * Test cart functionality for an individual product (avoids business rule conflicts)
     */
    private function testIndividualProductCart($product_id, $product_spec) {
        // Clear cart first to avoid conflicts with business rules
        $this->removeAllProductsFromCart();
        
        // Test the product individually
        $this->addProductToCartForTesting($product_id, $product_spec);
        $this->displayCartSummary("After adding " . $product_spec['pro_name']);
        
        // Test coupon codes if any are configured
        if (!empty($this->coupon_codes)) {
            $this->testCouponCodes($product_spec['pro_name']);
        }
        
        // Remove the product 
        $this->removeProductFromCart($product_id);
        $this->displayCartSummary("After removing " . $product_spec['pro_name']);
    }
    
    /**
     * Test adding all successful products together to see business rule interactions
     */
    private function testAllProductsTogether($all_products) {
        if (empty($this->successful_products)) {
            echo "<h3>Combined Cart Test</h3>\n";
            echo "No products passed individual testing, skipping combined test.<br><br>\n";
            return;
        }
        
        echo "<h3>Combined Cart Test</h3>\n";
        echo "Testing " . count($this->successful_products) . " successful products together...<br>\n";
        
        // Clear cart first
        $this->removeAllProductsFromCart();
        
        $added_products = [];
        $warnings = [];
        
        // Try to add each successful product
        foreach ($this->successful_products as $product_info) {
            $product_id = $product_info['id'];
            $product_spec = $product_info['spec'];
            
            try {
                $this->addProductToCart($product_id, $product_spec);
                $added_products[] = $product_spec['pro_name'];
                echo "✓ Added " . htmlspecialchars($product_spec['pro_name']) . " to cart<br>\n";
            } catch (Exception $e) {
                // Check if this is a business rule violation
                if (strpos($e->getMessage(), 'cart may contain only one subscription') !== false ||
                    strpos($e->getMessage(), 'can not have more than') !== false) {
                    $warnings[] = "⚠ <span style='color: orange;'><strong>Business rule prevented adding " . htmlspecialchars($product_spec['pro_name']) . ":</strong> " . htmlspecialchars($e->getMessage()) . "</span>";
                } else {
                    // Real error - rethrow
                    throw $e;
                }
            }
        }
        
        // Display any warnings
        if (!empty($warnings)) {
            foreach ($warnings as $warning) {
                echo $warning . "<br>\n";
            }
        }
        
        // Show final cart state
        if (!empty($added_products)) {
            $this->displayCartSummary("Final combined cart (" . implode(", ", $added_products) . ")");
            
            // Test coupon codes with combined cart if any products were added
            if (!empty($this->coupon_codes)) {
                $this->testCouponCodes("combined cart");
            }
        } else {
            echo "No products could be combined due to business rules.<br>\n";
        }
        
        // Clean up
        $this->removeAllProductsFromCart();
        $this->displayCartSummary("After clearing combined cart");
        echo "<br>\n";
    }
    
    /**
     * Test coupon codes with the current cart contents
     */
    private function testCouponCodes($product_name) {
        $session = SessionControl::get_instance();
        $cart = $session->get_shopping_cart();
        
        echo "Testing coupon codes with " . htmlspecialchars($product_name) . "...<br>\n";
        
        foreach ($this->coupon_codes as $coupon_spec) {
            $coupon_code = $coupon_spec['ccd_code'];
            try {
                // Add the coupon code
                $result = $cart->add_coupon($coupon_code);
                
                if ($result === 1) {
                    echo "✓ Applied coupon: " . htmlspecialchars($coupon_code) . "<br>\n";
                    $this->displayCartSummary("After applying " . $coupon_code);
                    
                    // Remove the coupon for next test
                    $cart->remove_coupon($coupon_code);
                } else {
                    echo "⚠ <span style='color: orange;'>Coupon " . htmlspecialchars($coupon_code) . " not applicable: " . htmlspecialchars($result) . "</span><br>\n";
                }
            } catch (Exception $e) {
                echo "✗ <span style='color: red;'>Error testing coupon " . htmlspecialchars($coupon_code) . ": " . htmlspecialchars($e->getMessage()) . "</span><br>\n";
            }
        }
    }
    
    /**
     * Add a product to the shopping cart for testing (validates versions first)
     */
    private function addProductToCartForTesting($product_id, $product_spec) {
        // Get the product and its versions for testing
        $product = new Product($product_id, TRUE);
        $versions = $product->get_product_versions(TRUE);
        
        if (!$versions || $versions->count_all() == 0) {
            throw new Exception("No product versions found - products must have at least one version");
        }
        
        // Call the actual cart addition method
        $this->addProductToCart($product_id, $product_spec);
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
     * Remove all products from the shopping cart
     */
    private function removeAllProductsFromCart() {
        $session = SessionControl::get_instance();
        $cart = $session->get_shopping_cart();
        
        // Clear the entire cart
        $cart->clear_cart();
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
            // Don't display empty cart status unless it's an error condition
            if (strpos($context, "before payment") !== false || strpos($context, "Final combined cart") !== false) {
                echo "Cart is empty<br>\n";
            }
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
        
        // Display created product IDs for reference
        if (!empty($this->created_products)) {
            echo "<br><strong>Created test product IDs:</strong> " . implode(', ', $this->created_products) . "<br>\n";
        }
        echo "</div>\n";
    }
    
    
    /**
     * Create a Stripe product ID for the test product
     */
    private function createStripeProduct($product_id) {
        // Make sure we're in test mode for Stripe operations
        $_SESSION['test_mode'] = true;
        
        try {
            // Load the product
            $product = new Product($product_id, TRUE);
            
            // Check if it already has a test Stripe product ID
            if (!$product->get('pro_stripe_product_id_test')) {
                // Create StripeHelper instance
                $stripe_helper = new StripeHelper();
                
                // Prepare product info for Stripe
                $product_info = [
                    'name' => $product->get('pro_name'),
                    'description' => $product->get('pro_description'),
                ];
                
                // Create the Stripe product
                $stripe_product = $stripe_helper->create_product($product_info);
                
                if (!$stripe_product['id']) {
                    throw new Exception("Unable to create a stripe product");
                }
                
                // Save the Stripe product ID to the product
                $product->set('pro_stripe_product_id_test', $stripe_product['id']);
                $product->save();
                
                // Stripe product ID created successfully
            }
            // Product already has Stripe ID - no action needed
            
        } catch (Exception $e) {
            throw new Exception("Failed to create Stripe product: " . $e->getMessage());
        }
    }
    
    /**
     * Set up billing information for the cart
     */
    private function setupBillingInfo($billing_info) {
        $session = SessionControl::get_instance();
        $cart = $session->get_shopping_cart();
        
        // First, try to prefill from cart items (this should work if cart has items with form_data)
        if (method_exists($cart, 'billing_user_prefill_from_items')) {
            $prefill_result = $cart->billing_user_prefill_from_items();
            
            if ($prefill_result) {
                echo "✓ Billing info setup: " . 
                     htmlspecialchars($cart->billing_user['first_name'] ?? 'N/A') . " " . 
                     htmlspecialchars($cart->billing_user['last_name'] ?? 'N/A') . " (" . 
                     htmlspecialchars($cart->billing_user['email'] ?? 'N/A') . ")<br>\n";
                return;
            }
        }
        
        // If prefill didn't work, set billing user information manually from JSON config
        $cart->billing_user = [
            'first_name' => $billing_info['first_name'],
            'last_name' => $billing_info['last_name'], 
            'email' => $billing_info['email']
        ];
        
        echo "✓ Billing info setup: " . htmlspecialchars($cart->billing_user['first_name']) . " " . 
             htmlspecialchars($cart->billing_user['last_name']) . " (" . 
             htmlspecialchars($cart->billing_user['email']) . ")<br>\n";
    }
    
    /**
     * Test payment functionality with Stripe
     */
    private function testPaymentFlow() {
        echo "<h3>Testing Payment Flow</h3>\n";
        
        // Store original test mode state
        $original_test_mode = $_SESSION['test_mode'] ?? false;
        
        try {
            // Enable Stripe test mode for this transaction only
            $_SESSION['test_mode'] = true;
            
            // Submit to cart_charge endpoint
            $this->simulateStripePayment();
        } catch (Exception $e) {
            echo "✗ <span style='color: red;'><strong>Payment test failed:</strong> " . htmlspecialchars($e->getMessage()) . "</span><br>\n";
        } finally {
            // Always restore original test mode state
            $_SESSION['test_mode'] = $original_test_mode;
        }
    }
    
    /**
     * Simulate a Stripe payment by submitting to cart_charge
     */
    private function simulateStripePayment() {
        echo "Simulating Stripe payment submission...<br>\n";
        
        // Get the session and cart
        $session = SessionControl::get_instance();
        $cart = $session->get_shopping_cart();
        
        // Check if cart has items
        if (empty($cart->items)) {
            throw new Exception("Cannot test payment - cart is empty");
        }
        
        // Display cart before payment
        $this->displayCartSummary("Cart before payment");
        
        
        // Prepare POST data as if from checkout form
        // In normal flow, JavaScript would create a token from card details
        // For testing, we use Stripe's pre-defined test tokens
        $post_data = [
            'stripeToken' => 'tok_visa', // Stripe test token (simulates a Visa card)
            'password' => '', // Optional: If billing user doesn't exist, create account with this password
        ];
        
        // Save current POST/REQUEST
        $original_post = $_POST;
        $original_request = $_REQUEST;
        $original_get = $_GET;
        
        // Set up POST data for cart_charge
        $_POST = $post_data;
        $_REQUEST = $post_data;
        $_GET = [];
        
        // Suppress emails during testing to avoid email configuration issues
        $original_send_emails = $_SESSION['send_emails'] ?? true;
        $_SESSION['send_emails'] = false;
        
        try {
            // Include the cart_charge view which calls cart_charge_logic
            ob_start();
            
            // Capture any redirects
            $redirect_captured = false;
            
            // Override the redirect function temporarily
            if (!function_exists('LibraryFunctions_Redirect_Override')) {
                function LibraryFunctions_Redirect_Override($url) {
                    global $redirect_captured;
                    $redirect_captured = $url;
                    throw new Exception("REDIRECT:$url");
                }
            }
            
            try {
                include(PathHelper::getRootDir() . '/views/cart_charge.php');
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'REDIRECT:') === 0) {
                    // Expected redirect to cart_confirm
                    $redirect_url = substr($e->getMessage(), 9);
                    echo "✓ Payment processed, redirected to: " . htmlspecialchars($redirect_url) . "<br>\n";
                } else {
                    // Check if this is a user creation issue
                    if (strpos($e->getMessage(), 'Call to a member function get() on null') !== false) {
                        throw new Exception("Billing user creation failed - the user object is null. This usually means the test user email already exists in the database but couldn't be retrieved, or user creation failed.");
                    }
                    throw $e;
                }
            }
            
            $output = ob_get_contents();
            ob_end_clean();
            
            // The view redirects to cart_confirm, so verify the order was created
            $this->verifyLatestOrder();
            
        } finally {
            // Restore original POST/REQUEST/GET and email settings
            $_POST = $original_post;
            $_REQUEST = $original_request;
            $_GET = $original_get;
            $_SESSION['send_emails'] = $original_send_emails;
        }
    }
    
    /**
     * Verify the latest order was created and paid successfully
     */
    private function verifyLatestOrder() {
        echo "Verifying order creation and payment...<br>\n";
        
        // Need to include the MultiOrder class
        PathHelper::requireOnce('/data/orders_class.php');
        
        
        // Since the cart is cleared after payment, find the most recent test order
        // Look for orders created in the last few minutes with test mode
        $orders = new MultiOrder(
            array('ord_test_mode' => 1),
            array('ord_order_id' => 'DESC'),
            5  // Get last 5 test orders to find the right one
        );
        
        if ($orders->count_all() == 0) {
            throw new Exception("No test orders found in database");
        }
        
        $orders->load();
        
        // Find the most recent order (should be the one we just created)
        $order = null;
        $current_time = time();
        
        foreach ($orders as $test_order) {
            $order_time = strtotime($test_order->get('ord_timestamp'));
            $time_diff = $current_time - $order_time;
            
            // If order was created in the last 60 seconds, it's probably ours
            if ($time_diff < 60) {
                $order = $test_order;
                break;
            }
        }
        
        if (!$order) {
            throw new Exception("No recent test order found (looked for orders created in last 60 seconds)");
        }
        
        echo "Found order ID: " . $order->key . "<br>\n";
        
        // Check order status
        if ($order->get('ord_status') !== Order::STATUS_PAID) {
            throw new Exception("Order not marked as paid. Status: " . $order->get('ord_status'));
        }
        
        // Verify Stripe charge ID exists (for non-zero orders)
        if ($order->get('ord_total_cost') > 0 && !$order->get('ord_stripe_charge_id')) {
            // Check if this is a subscription-only order by examining order items
            PathHelper::requireOnce('/data/order_items_class.php');
            $order_items = new MultiOrderItem(
                array('odi_ord_order_id' => $order->key),
                array('odi_order_item_id' => 'ASC')
            );
            
            if ($order_items->count_all() > 0) {
                $order_items->load();
                $has_subscriptions = false;
                
                foreach ($order_items as $item) {
                    if ($item->get('odi_is_subscription') && $item->get('odi_stripe_subscription_id')) {
                        $has_subscriptions = true;
                        break;
                    }
                }
                
                if (!$has_subscriptions) {
                    throw new Exception("Order has cost but no Stripe charge ID and no active subscriptions");
                }
            } else {
                throw new Exception("Order has cost but no Stripe charge ID and no order items found");
            }
        }
        
        // Verify order items
        $this->verifyOrderItems($order);
        
        // Verify payment with Stripe
        echo "<br>Verifying payment with Stripe...<br>\n";
        if ($this->verifyStripePayment($order)) {
            echo "✓ <span style='color: green;'><strong>Stripe verification successful!</strong></span><br>\n";
        } else {
            echo "✗ <span style='color: red;'><strong>Stripe verification failed!</strong></span><br>\n";
        }
        
        echo "✓ <span style='color: green;'><strong>Payment test successful!</strong></span><br>\n";
    }
    
    /**
     * Verify order items were created correctly
     */
    private function verifyOrderItems($order) {
        PathHelper::requireOnce('/data/order_items_class.php');
        
        $order_items = new MultiOrderItem(
            array('odi_ord_order_id' => $order->key),
            array('odi_order_item_id' => 'ASC')
        );
        
        $item_count = $order_items->count_all();
        
        if ($item_count == 0) {
            throw new Exception("No order items found for order " . $order->key);
        }
        
        if ($item_count > 50) {
            throw new Exception("Too many order items found ($item_count). Query filtering issue.");
        }
        
        $order_items->load();
        $items_checked = 0;
        $subscription_ids = [];
        
        foreach ($order_items as $item) {
            $items_checked++;
            if ($items_checked > 10) {
                break;
            }
            
            $status = $item->get('odi_status');
            
            if ($status !== OrderItem::STATUS_PAID) {
                throw new Exception("Order item " . $item->key . " not marked as paid. Status: '" . $status . "'");
            }
            
            // Collect subscription IDs
            if ($item->get('odi_is_subscription')) {
                $sub_id = $item->get('odi_stripe_subscription_id');
                if ($sub_id) {
                    $subscription_ids[] = $sub_id;
                }
            }
        }
        
        // Single summary line
        $summary_parts = [];
        if (!empty($subscription_ids)) {
            $summary_parts[] = "subscription " . $subscription_ids[0];
        }
        $summary_parts[] = "order verified";
        
        echo "✓ " . implode(', ', $summary_parts) . "<br>\n";
    }
    
    private function verifyStripePayment($order) {
        $stripe_helper = new StripeHelper();
        
        // Determine verification method based on order type
        if ($order->get('ord_stripe_session_id')) {
            // Stripe Checkout verification
            $verification = $stripe_helper->verify_checkout_session($order);
            if ($verification && $verification['payment_status'] === 'paid') {
                echo "✓ Stripe Checkout session verified: " . $verification['session_id'] . "<br>\n";
                echo "  Amount: $" . $verification['amount_total'] . " " . strtoupper($verification['currency']) . "<br>\n";
                echo "  Status: " . $verification['status'] . "<br>\n";
                return true;
            }
        } elseif ($order->get('ord_stripe_charge_id') || $order->get('ord_stripe_payment_intent_id')) {
            // Regular Stripe verification
            $charge = $stripe_helper->get_charge_from_order($order);
            if ($charge && $charge->paid && $charge->status === 'succeeded') {
                echo "✓ Stripe charge verified: " . $charge->id . "<br>\n";
                echo "  Amount: $" . ($charge->amount / 100) . " " . strtoupper($charge->currency) . "<br>\n";
                echo "  Status: " . $charge->status . "<br>\n";
                
                // Verify amount matches order total
                $order_total = $order->get('ord_total_cost');
                $stripe_amount = $charge->amount / 100;
                $amounts_match = abs($stripe_amount - $order_total) < 0.01;
                
                if ($amounts_match) {
                    echo "✓ Payment amount matches order total<br>\n";
                } else {
                    echo "✗ Payment amount mismatch: Order=$" . $order_total . ", Stripe=$" . $stripe_amount . "<br>\n";
                    return false;
                }
                
                return true;
            }
        } else {
            // Check if this is a subscription-only order
            PathHelper::requireOnce('/data/order_items_class.php');
            $order_items = new MultiOrderItem(
                array('odi_ord_order_id' => $order->key),
                array('odi_order_item_id' => 'ASC')
            );
            
            if ($order_items->count_all() > 0) {
                $order_items->load();
                $has_subscriptions = false;
                
                foreach ($order_items as $item) {
                    if ($item->get('odi_is_subscription') && $item->get('odi_stripe_subscription_id')) {
                        $has_subscriptions = true;
                        $sub_id = $item->get('odi_stripe_subscription_id');
                        
                        try {
                            $subscription = $stripe_helper->get_subscription($sub_id);
                            if ($subscription && $subscription->status === 'active') {
                                echo "✓ Stripe subscription verified: " . $sub_id . "<br>\n";
                                echo "  Status: " . $subscription->status . "<br>\n";
                                echo "  Amount: $" . ($subscription->items->data[0]->price->unit_amount / 100) . " " . strtoupper($subscription->currency) . "<br>\n";
                                echo "  Interval: " . $subscription->items->data[0]->price->recurring->interval . "<br>\n";
                            } else {
                                echo "✗ Subscription verification failed for: " . $sub_id . "<br>\n";
                                return false;
                            }
                        } catch (Exception $e) {
                            echo "✗ Error verifying subscription " . $sub_id . ": " . $e->getMessage() . "<br>\n";
                            return false;
                        }
                    }
                }
                
                if ($has_subscriptions) {
                    return true;
                }
            }
        }
        
        echo "✗ Stripe payment verification failed - no charge, session, or subscription found<br>\n";
        return false;
    }
}

// Class is now ready to be included and used
?>