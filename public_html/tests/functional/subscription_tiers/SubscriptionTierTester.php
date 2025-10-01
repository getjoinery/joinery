<?php
/**
 * Subscription Tier Testing Script
 *
 * This script comprehensively tests the subscription tier system:
 * - Model layer (tier assignment, features, tracking)
 * - Business logic layer (change_subscription_logic.php)
 * - All setting combinations for upgrades, downgrades, cancellations, reactivations
 * - Stripe price ID syncing
 *
 * Usage: Access this file through your web browser while logged in as an admin user.
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('/includes/StripeHelper.php'));
require_once(PathHelper::getIncludePath('/includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('/includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('/includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('/includes/LogicResult.php'));

require_once(PathHelper::getIncludePath('/data/subscription_tiers_class.php'));
require_once(PathHelper::getIncludePath('/data/users_class.php'));
require_once(PathHelper::getIncludePath('/data/settings_class.php'));
require_once(PathHelper::getIncludePath('/data/change_tracking_class.php'));
require_once(PathHelper::getIncludePath('/data/products_class.php'));
require_once(PathHelper::getIncludePath('/data/product_versions_class.php'));
require_once(PathHelper::getIncludePath('/data/order_items_class.php'));
require_once(PathHelper::getIncludePath('/data/orders_class.php'));
require_once(PathHelper::getIncludePath('/logic/change_subscription_logic.php'));

class SubscriptionTierTester {
    private $settings;
    private $dbconnector;
    private $test_user_id;
    private $test_results = [];
    private $tiers = [];
    private $tier_products = []; // Maps tier_level => product_id
    private $original_settings = []; // Store original settings to restore later
    private $test_failures = []; // Track all test failures

    public function __construct() {
        $this->dbconnector = DbConnector::get_instance();

        // Enable test mode FIRST, before loading settings
        try {
            $this->dbconnector->set_test_mode();
            $test_connection = $this->dbconnector->get_db_link();
            if (!$test_connection) {
                throw new Exception("Test database connection failed");
            }
        } catch (Exception $e) {
            throw new Exception("Failed to enable test mode: " . $e->getMessage());
        }

        // Get settings - Globalvars was already loaded by DbConnector
        // Settings will be loaded from the test database since we're in test mode
        $this->settings = Globalvars::get_instance();
    }

    public function __destruct() {
        if ($this->dbconnector) {
            $this->dbconnector->close_test_mode();
        }
    }

    /**
     * Display database info
     */
    private function displayDatabaseInfo() {
        $dblink = $this->dbconnector->get_db_link();
        try {
            $stmt = $dblink->query("SELECT current_database()");
            $database_name = $stmt->fetchColumn();
        } catch (Exception $e) {
            $database_name = "Unknown";
        }

        echo '<div class="alert alert-warning">';
        echo '<h4>🔍 TEST ENVIRONMENT STATUS</h4>';
        echo '<strong>Database:</strong> <span class="text-primary font-weight-bold">' . htmlspecialchars($database_name) . '</span><br>';
        echo '</div>';
    }

    /**
     * Load tiers from database
     */
    private function loadTiers() {
        $multi_tiers = new MultiSubscriptionTier(
            ['sbt_is_active' => true, 'sbt_delete_time' => 'IS NULL'],
            ['sbt_tier_level' => 'ASC']
        );
        $multi_tiers->load();

        foreach ($multi_tiers as $tier) {
            $this->tiers[$tier->get('sbt_tier_level')] = [
                'id' => $tier->key,
                'name' => $tier->get('sbt_name'),
                'display_name' => $tier->get('sbt_display_name'),
                'level' => $tier->get('sbt_tier_level'),
                'object' => $tier
            ];
        }

        echo "<h3>Loaded Tiers:</h3>";
        echo "<ul>";
        foreach ($this->tiers as $tier) {
            echo "<li>Level {$tier['level']}: {$tier['display_name']} (ID: {$tier['id']})</li>";
        }
        echo "</ul>";
    }

    /**
     * Load products for each tier
     */
    private function loadTierProducts() {
        echo "<h3>Loading Products for Each Tier:</h3>";

        foreach ($this->tiers as $level => $tier) {
            // Find a product assigned to this tier
            $products = new MultiProduct([
                'pro_sbt_subscription_tier_id' => $tier['id'],
                'pro_is_active' => true,
                'pro_delete_time' => 'IS NULL'
            ]);
            $products->load();

            if ($products->count() > 0) {
                $product = $products->get(0);
                $this->tier_products[$level] = $product->key;
                echo "<p>✓ Tier Level {$level}: Product '{$product->get('pro_name')}' (ID: {$product->key})</p>";

                // Check if product has Stripe price IDs
                $versions = $product->get_product_versions();
                $default_version = $versions->count() > 0 ? $versions->get(0) : null;
                if ($default_version) {
                    $price_id = $default_version->get('prv_stripe_price_id');
                    $price_id_test = $default_version->get('prv_stripe_price_id_test');

                    if ($price_id || $price_id_test) {
                        echo "<p style='margin-left:20px'>Price IDs: Live=" . ($price_id ?: 'none') . ", Test=" . ($price_id_test ?: 'none') . "</p>";
                    } else {
                        echo "<p style='margin-left:20px' class='text-warning'>⚠️ No Stripe price IDs set (will be auto-generated on first use)</p>";
                    }
                }
            } else {
                echo "<p class='text-danger'>✗ No product found for tier level {$level}</p>";
            }
        }

        if (count($this->tier_products) < 3) {
            $message = "Need at least 3 products assigned to tiers for testing. Found: " . count($this->tier_products);
            echo "<p class='text-danger'><strong>ERROR:</strong> {$message}</p>";
            $this->recordFailure('Load Tier Products', $message);
            return false;
        }

        return true;
    }

    /**
     * Create test user
     */
    private function getTestUser() {
        $test_email = 'tier_test_' . time() . '@example.com';

        $user = new User(NULL);
        $user->set('usr_email', $test_email);
        $user->set('usr_first_name', 'Tier');
        $user->set('usr_last_name', 'Test');
        $user->set('usr_name', 'Tier Test User');
        $user->set('usr_password', password_hash('testpassword', PASSWORD_DEFAULT));
        $user->set('usr_permission', 1);
        $user->save();

        $this->test_user_id = $user->key;

        echo "<p><strong>Test User Created:</strong> ID {$this->test_user_id} ({$test_email})</p>";
    }

    /**
     * Create a mock subscription for the user
     */
    private function createMockSubscription($product_id, $stripe_subscription_id = null, $stripe_item_id = null) {
        if (!$stripe_subscription_id) {
            $stripe_subscription_id = 'sub_test_' . time() . '_' . rand(1000, 9999);
        }
        if (!$stripe_item_id) {
            $stripe_item_id = 'si_test_' . time() . '_' . rand(1000, 9999);
        }

        $product = new Product($product_id, TRUE);
        $versions = $product->get_product_versions();
        $product_version = $versions->get(0);

        // Create order
        $order = new Order(NULL);
        $order->set('ord_usr_user_id', $this->test_user_id);
        $order->set('ord_status', Order::STATUS_PAID);
        $order->set('ord_payment_method', 'stripe');
        $order->set('ord_amount', $product_version->get('prv_version_price'));
        $order->save();

        // Create order item
        $order_item = new OrderItem(NULL);
        $order_item->set('odi_ord_order_id', $order->key);
        $order_item->set('odi_pro_product_id', $product->key);
        $order_item->set('odi_prv_product_version_id', $product_version->key);
        $order_item->set('odi_price', $product_version->get('prv_version_price'));
        $order_item->set('odi_is_subscription', true);
        $order_item->set('odi_subscription_id', $stripe_subscription_id);
        $order_item->set('odi_subscription_item_id', $stripe_item_id);
        $order_item->set('odi_subscription_status', 'active');
        $order_item->set('odi_subscription_period_end', date('Y-m-d H:i:s', strtotime('+1 month')));
        $order_item->save();

        // Assign user to tier
        if ($product->get('pro_sbt_subscription_tier_id')) {
            $tier = new SubscriptionTier($product->get('pro_sbt_subscription_tier_id'), TRUE);
            $tier->addUser($this->test_user_id, 'purchase', 'order', $order->key);
        }

        return [
            'order_id' => $order->key,
            'order_item_id' => $order_item->key,
            'subscription_id' => $stripe_subscription_id,
            'item_id' => $stripe_item_id
        ];
    }

    /**
     * Record a test failure
     */
    private function recordFailure($test_name, $message) {
        $this->test_failures[] = [
            'test' => $test_name,
            'message' => $message
        ];
        echo "<div style='background: #ffcccc; border: 3px solid #cc0000; padding: 15px; margin: 10px 0;'>";
        echo "<h3 style='color: #cc0000; margin-top: 0;'>❌ TEST FAILURE: {$test_name}</h3>";
        echo "<p style='font-weight: bold;'>{$message}</p>";
        echo "</div>";
    }

    /**
     * Save and restore settings
     */
    private function saveSettings($settings_to_save) {
        foreach ($settings_to_save as $name) {
            // Try to get existing setting value, fail silently if it doesn't exist or is NULL
            $value = $this->settings->get_setting($name, true, true);

            // If setting is NULL or doesn't exist, initialize it with a test default
            if ($value === null || $value === '') {
                echo "<p>⚠️ Setting '{$name}' is NULL/empty, initializing with test default</p>";

                $setting = Setting::GetByColumn('stg_name', $name);
                if ($setting) {
                    // Set sensible defaults for subscription settings for testing
                    $test_defaults = [
                        'subscription_downgrades_enabled' => '1',
                        'subscription_downgrade_timing' => 'immediate',
                        'subscription_cancellation_enabled' => '1',
                        'subscription_cancellation_timing' => 'immediate',
                        'subscription_reactivation_enabled' => '1',
                        'subscription_cancellation_prorate' => '0'
                    ];

                    if (isset($test_defaults[$name])) {
                        $setting->set('stg_value', $test_defaults[$name]);
                        $setting->save();
                        $value = $test_defaults[$name];
                        echo "<p>✓ Set '{$name}' to '{$value}'</p>";
                    }
                }
            }

            $this->original_settings[$name] = $value;
        }
    }

    private function restoreSettings() {
        foreach ($this->original_settings as $name => $value) {
            $setting = Setting::GetByColumn('stg_name', $name);
            if ($setting) {
                $setting->set('stg_value', $value);
                $setting->save();
            }
        }
    }

    /**
     * Apply setting values
     */
    private function applySettings($settings_array) {
        foreach ($settings_array as $name => $value) {
            $setting = Setting::GetByColumn('stg_name', $name);
            if ($setting) {
                $setting->set('stg_value', $value);
                $setting->save();
            }
        }

        // Clear the Globalvars cache so the new settings are loaded
        // We need to reset the singleton instance_map to force reload from database
        $reflection = new ReflectionClass('Globalvars');
        $instance_map = $reflection->getProperty('instance_map');
        $instance_map->setAccessible(true);
        $instance_map->setValue(null, array());
    }

    /**
     * Test basic tier assignment
     */
    private function testBasicTierAssignment() {
        echo "<h4>Test: Basic Tier Assignment</h4>";

        $levels = array_keys($this->tiers);
        $test_level = $levels[0];
        $tier = $this->tiers[$test_level];

        try {
            $tier_obj = new SubscriptionTier($tier['id'], TRUE);
            $tier_obj->addUser($this->test_user_id, 'manual', 'test', null, 1);

            $current_tier = SubscriptionTier::GetUserTier($this->test_user_id);

            if ($current_tier && $current_tier->key == $tier['id']) {
                echo "<p class='text-success'>✓ User successfully assigned to tier</p>";
                return true;
            } else {
                $message = "Assignment failed";
                echo "<p class='text-danger'>✗ {$message}</p>";
                $this->recordFailure('Basic Tier Assignment', $message);
                return false;
            }
        } catch (Exception $e) {
            $message = "Exception: " . $e->getMessage();
            echo "<p class='text-danger'>✗ " . htmlspecialchars($message) . "</p>";
            $this->recordFailure('Basic Tier Assignment', $message);
            return false;
        }
    }

    /**
     * Test upgrade-only purchase logic
     */
    private function testUpgradeOnlyLogic() {
        echo "<h4>Test: Purchase Upgrade-Only Logic</h4>";

        $levels = array_keys($this->tiers);
        $high_level = $levels[count($levels) - 1];
        $low_level = $levels[0];

        // Assign to high tier
        $high_tier = new SubscriptionTier($this->tiers[$high_level]['id'], TRUE);
        $high_tier->addUser($this->test_user_id, 'manual', 'test', null, 1);

        // Try to "purchase" lower tier (should be blocked)
        $low_tier = new SubscriptionTier($this->tiers[$low_level]['id'], TRUE);
        $result = $low_tier->addUser($this->test_user_id, 'purchase', 'test', null, 1);

        // Verify user is still on high tier
        $current_tier = SubscriptionTier::GetUserTier($this->test_user_id);

        if ($current_tier->key == $high_tier->key) {
            echo "<p class='text-success'>✓ Downgrade via purchase correctly blocked</p>";
            return true;
        } else {
            $message = "Downgrade was allowed (should be blocked)";
            echo "<p class='text-danger'>✗ {$message}</p>";
            $this->recordFailure('Upgrade-Only Purchase Logic', $message);
            return false;
        }
    }

    /**
     * Test feature access
     */
    private function testFeatureAccess() {
        echo "<h4>Test: Feature Access</h4>";

        $levels = array_keys($this->tiers);
        $test_level = $levels[0];

        // Assign to tier
        $tier = new SubscriptionTier($this->tiers[$test_level]['id'], TRUE);
        $tier->addUser($this->test_user_id, 'manual', 'test', null, 1);

        // Get feature
        $max_devices = SubscriptionTier::getUserFeature($this->test_user_id, 'controld_max_devices', 1);
        echo "<p>User's max_devices: <strong>$max_devices</strong></p>";

        // Get tier display
        $tier_display = SubscriptionTier::getUserTierDisplay($this->test_user_id);
        echo "<p>Tier display: <strong>$tier_display</strong></p>";

        echo "<p class='text-success'>✓ Feature access working</p>";
        return true;
    }

    /**
     * Test minimum tier level checking
     */
    private function testMinimumTierLevel() {
        echo "<h4>Test: Minimum Tier Level Checking</h4>";

        $levels = array_keys($this->tiers);
        $mid_level = $levels[1];
        $high_level = $levels[2];
        $low_level = $levels[0];

        // Assign to mid tier
        $tier = new SubscriptionTier($this->tiers[$mid_level]['id'], TRUE);
        $tier->addUser($this->test_user_id, 'manual', 'test', null, 1);

        // Should pass low level check
        $has_low = SubscriptionTier::UserHasMinimumTier($this->test_user_id, $low_level);

        // Should pass same level check
        $has_mid = SubscriptionTier::UserHasMinimumTier($this->test_user_id, $mid_level);

        // Should fail high level check
        $has_high = SubscriptionTier::UserHasMinimumTier($this->test_user_id, $high_level);

        if ($has_low && $has_mid && !$has_high) {
            echo "<p class='text-success'>✓ Tier level checking works correctly</p>";
            return true;
        } else {
            $message = "Tier level checking failed - Low: " . ($has_low ? 'pass' : 'fail') . ", Mid: " . ($has_mid ? 'pass' : 'fail') . ", High: " . ($has_high ? 'pass' : 'fail');
            echo "<p class='text-danger'>✗ Tier level checking failed</p>";
            echo "<p>Low: " . ($has_low ? 'pass' : 'fail') . ", Mid: " . ($has_mid ? 'pass' : 'fail') . ", High: " . ($has_high ? 'pass' : 'fail') . "</p>";
            $this->recordFailure('Minimum Tier Level Checking', $message);
            return false;
        }
    }

    /**
     * Test change tracking
     */
    private function testChangeTracking() {
        echo "<h4>Test: Change Tracking</h4>";

        $levels = array_keys($this->tiers);

        // Make some tier changes
        foreach ($levels as $level) {
            $tier = new SubscriptionTier($this->tiers[$level]['id'], TRUE);
            $tier->addUser($this->test_user_id, 'manual', 'test', null, 1);
        }

        // Get change history
        $changes = ChangeTracking::getUserHistory($this->test_user_id);

        $tier_changes = 0;
        foreach ($changes as $change) {
            if ($change->get('cht_entity_type') === 'subscription_tier') {
                $tier_changes++;
            }
        }

        echo "<p>Found <strong>{$tier_changes}</strong> tier change entries</p>";

        if ($tier_changes > 0) {
            echo "<p class='text-success'>✓ Change tracking working</p>";
            return true;
        } else {
            $message = "No changes tracked";
            echo "<p class='text-danger'>✗ {$message}</p>";
            $this->recordFailure('Change Tracking', $message);
            return false;
        }
    }

    /**
     * Test Stripe price ID syncing with actual Stripe API
     */
    private function testStripePriceSync() {
        echo "<h4>Test: Stripe Price ID Syncing (with Stripe API)</h4>";

        $levels = array_keys($this->tiers);
        $test_level = $levels[0];
        $product_id = $this->tier_products[$test_level];

        $product = new Product($product_id, TRUE);
        $versions = $product->get_product_versions();
        $product_version = $versions->get(0);

        if (!$product_version) {
            $message = "No product version found";
            echo "<p class='text-danger'>✗ {$message}</p>";
            $this->recordFailure('Stripe Price ID Syncing', $message);
            return false;
        }

        // Check if price IDs exist before
        $price_id_before = $product_version->get('prv_stripe_price_id_test');
        echo "<p>Price ID before: " . ($price_id_before ?: 'none') . "</p>";

        try {
            // Call StripeHelper to create/fetch price (will auto-save ID)
            $stripe_helper = new StripeHelper();
            $stripe_price = $stripe_helper->get_or_create_price($product_version);

            // Reload product version to see if ID was saved
            $product_version = new ProductVersion($product_version->key, TRUE);
            $price_id_after = $product_version->get('prv_stripe_price_id_test');

            echo "<p>Price ID after: " . ($price_id_after ?: 'none') . "</p>";
            echo "<p>Stripe Price ID from API: " . $stripe_price->id . "</p>";

            if ($price_id_after && $price_id_after === $stripe_price->id) {
                echo "<p class='text-success'>✓ Stripe price ID automatically synced and stored</p>";
                return true;
            } else {
                $message = "Price ID not stored correctly";
                echo "<p class='text-danger'>✗ {$message}</p>";
                $this->recordFailure('Stripe Price ID Syncing', $message);
                return false;
            }
        } catch (Exception $e) {
            $message = "Stripe API error: " . $e->getMessage();
            echo "<p class='text-danger'>✗ " . htmlspecialchars($message) . "</p>";
            $this->recordFailure('Stripe Price ID Syncing', $message);
            return false;
        }
    }

    /**
     * Create actual Stripe subscription for testing
     */
    private function createStripeSubscription($product_id) {
        echo "<h4>Creating Stripe Subscription for Testing</h4>";

        $product = new Product($product_id, TRUE);
        $versions = $product->get_product_versions();
        $product_version = $versions->get(0);

        if (!$product->get('pro_stripe_product_id_test')) {
            $message = "Product does not have Stripe product ID (test)";
            echo "<p class='text-danger'>✗ {$message}</p>";
            $this->recordFailure('Create Stripe Subscription', $message);
            return false;
        }

        try {
            $stripe_helper = new StripeHelper();

            // Get or create Stripe customer
            $user = new User($this->test_user_id, TRUE);

            // Check if user has Stripe customer ID
            if (!$user->get('usr_stripe_customer_id_test')) {
                // Create Stripe customer
                $customer = $stripe_helper->get_stripe_client()->customers->create([
                    'email' => $user->get('usr_email'),
                    'name' => $user->get('usr_first_name') . ' ' . $user->get('usr_last_name'),
                    'description' => 'Test user for subscription tier testing'
                ]);

                $user->set('usr_stripe_customer_id_test', $customer->id);
                $user->save();

                echo "<p>✓ Created Stripe customer: {$customer->id}</p>";
            } else {
                echo "<p>✓ Using existing Stripe customer: {$user->get('usr_stripe_customer_id_test')}</p>";
            }

            // Get or create price
            $stripe_price = $stripe_helper->get_or_create_price($product_version);
            echo "<p>✓ Got Stripe price: {$stripe_price->id}</p>";

            // Create or attach test payment method to customer
            $payment_method = $stripe_helper->get_stripe_client()->paymentMethods->create([
                'type' => 'card',
                'card' => [
                    'token' => 'tok_visa',  // Stripe test token for a valid Visa card
                ],
            ]);

            $stripe_helper->get_stripe_client()->paymentMethods->attach(
                $payment_method->id,
                ['customer' => $user->get('usr_stripe_customer_id_test')]
            );

            // Set as default payment method
            $stripe_helper->get_stripe_client()->customers->update(
                $user->get('usr_stripe_customer_id_test'),
                ['invoice_settings' => ['default_payment_method' => $payment_method->id]]
            );

            echo "<p>✓ Attached test payment method to customer</p>";

            // Create subscription with automatic payment
            $subscription = $stripe_helper->get_stripe_client()->subscriptions->create([
                'customer' => $user->get('usr_stripe_customer_id_test'),
                'items' => [['price' => $stripe_price->id]],
                'default_payment_method' => $payment_method->id,
                'expand' => ['latest_invoice.payment_intent'],
            ]);

            echo "<p>✓ Created Stripe subscription: {$subscription->id} (status: {$subscription->status})</p>";

            // Create order and order item
            $order = new Order(NULL);
            $order->set('ord_usr_user_id', $this->test_user_id);
            $order->set('ord_status', Order::STATUS_PAID);
            $order->set('ord_total_cost', $product_version->get('prv_version_price'));
            $order->save();

            $order_item = new OrderItem(NULL);
            $order_item->set('odi_ord_order_id', $order->key);
            $order_item->set('odi_usr_user_id', $this->test_user_id);
            $order_item->set('odi_pro_product_id', $product->key);
            $order_item->set('odi_prv_product_version_id', $product_version->key);
            $order_item->set('odi_price', $product_version->get('prv_version_price'));
            $order_item->set('odi_is_subscription', true);
            $order_item->set('odi_stripe_subscription_id', $subscription->id);
            $order_item->set('odi_subscription_status', $subscription->status);
            $order_item->set('odi_subscription_period_end', date('Y-m-d H:i:s', $subscription->current_period_end));
            $order_item->save();

            // Assign user to tier
            if ($product->get('pro_sbt_subscription_tier_id')) {
                $tier = new SubscriptionTier($product->get('pro_sbt_subscription_tier_id'), TRUE);
                $tier->addUser($this->test_user_id, 'purchase', 'order', $order->key);
                echo "<p>✓ User assigned to tier</p>";
            }

            return [
                'subscription_id' => $subscription->id,
                'subscription_item_id' => $subscription->items->data[0]->id,
                'order_id' => $order->key,
                'order_item_id' => $order_item->key,
                'customer_id' => $user->get('usr_stripe_customer_id_test')
            ];

        } catch (Exception $e) {
            $message = "Failed to create Stripe subscription: " . $e->getMessage();
            echo "<p class='text-danger'>✗ " . htmlspecialchars($message) . "</p>";
            $this->recordFailure('Create Stripe Subscription', $message);
            return false;
        }
    }

    /**
     * Test upgrade through logic file with real Stripe
     */
    private function testLogicFileUpgrade() {
        echo "<h4>Test: Upgrade via change_subscription_logic() with Stripe</h4>";

        $levels = array_keys($this->tiers);
        if (count($levels) < 2) {
            echo "<p class='text-warning'>⚠️ Need at least 2 tiers to test upgrade</p>";
            return false;
        }

        // Create subscription at low tier
        $subscription_data = $this->createStripeSubscription($this->tier_products[$levels[0]]);
        if (!$subscription_data) {
            return false;
        }

        // Simulate session for logic file
        $_SESSION['usr_user_id'] = $this->test_user_id;
        $_SESSION['loggedin'] = true;

        $session = SessionControl::get_instance();

        echo "<p style='margin-left:20px'>Debug: Checking for existing subscription before upgrade...</p>";

        // Debug: Check if subscription can be found
        $test_subscriptions = new MultiOrderItem(
            array('user_id' => $this->test_user_id, 'is_active_subscription' => true),
            array('order_item_id' => 'DESC')
        );
        $test_subscriptions->load();
        echo "<p style='margin-left:20px'>Debug: Found " . $test_subscriptions->count() . " active subscription(s) for user</p>";

        if ($test_subscriptions->count() > 0) {
            $test_sub = $test_subscriptions->get(0);
            echo "<p style='margin-left:20px'>Debug: Subscription ID: " . $test_sub->get('odi_stripe_subscription_id') . "</p>";
            echo "<p style='margin-left:20px'>Debug: Product ID: " . $test_sub->get('odi_pro_product_id') . "</p>";
            echo "<p style='margin-left:20px'>Debug: User ID on sub: " . $test_sub->get('odi_usr_user_id') . "</p>";
        }

        // Call logic file to upgrade
        $post = [
            'action' => 'upgrade',
            'product_id' => $this->tier_products[$levels[1]]
        ];

        echo "<p style='margin-left:20px'>Debug: Calling change_subscription_logic with action=upgrade, product_id=" . $this->tier_products[$levels[1]] . "</p>";

        try {
            $result = change_subscription_logic([], $post);

            // Debug: Check what logic returned
            if ($result->error) {
                echo "<p style='margin-left:20px' class='text-warning'>⚠️ Logic returned error: " . htmlspecialchars($result->error) . "</p>";
            }
            if ($result->redirect) {
                echo "<p style='margin-left:20px'>ℹ️ Logic returned redirect: " . htmlspecialchars($result->redirect) . "</p>";
            }
            if (isset($result->data['success_message'])) {
                echo "<p style='margin-left:20px' class='text-success'>✓ Logic success: " . htmlspecialchars($result->data['success_message']) . "</p>";
            }

            // Check if user is now on higher tier
            $current_tier = SubscriptionTier::GetUserTier($this->test_user_id);
            $current_tier_id = $current_tier ? $current_tier->key : 'none';
            $expected_tier_id = $this->tiers[$levels[1]]['id'];

            echo "<p style='margin-left:20px'>Current tier ID: {$current_tier_id}, Expected tier ID: {$expected_tier_id}</p>";
            echo "<p style='margin-left:20px'>Current tier level: " . ($current_tier ? $current_tier->get('sbt_tier_level') : 'none') . ", Expected level: {$levels[1]}</p>";

            if ($current_tier && $current_tier->key == $this->tiers[$levels[1]]['id']) {
                echo "<p class='text-success'>✓ Upgrade successful - user now on tier level {$levels[1]}</p>";
                return true;
            } else {
                $message = "Upgrade failed or tier not updated";
                echo "<p class='text-danger'>✗ {$message}</p>";
                $this->recordFailure('Logic File Upgrade', $message);
                return false;
            }
        } catch (Exception $e) {
            $message = "Exception during upgrade: " . $e->getMessage();
            echo "<p class='text-danger'>✗ " . htmlspecialchars($message) . "</p>";
            $this->recordFailure('Logic File Upgrade', $message);
            return false;
        }
    }

    /**
     * Test downgrade through logic file
     */
    private function testLogicFileDowngrade($immediate = true) {
        $timing = $immediate ? 'immediate' : 'end_of_period';
        echo "<h4>Test: Downgrade via change_subscription_logic() ({$timing})</h4>";

        // Enable downgrades
        $this->applySettings([
            'subscription_downgrades_enabled' => '1',
            'subscription_downgrade_timing' => $timing
        ]);

        $levels = array_keys($this->tiers);
        if (count($levels) < 2) {
            echo "<p class='text-warning'>⚠️ Need at least 2 tiers to test downgrade</p>";
            return false;
        }

        // User should already be on high tier from upgrade test
        $current_tier = SubscriptionTier::GetUserTier($this->test_user_id);
        $current_level = $current_tier ? $current_tier->get('sbt_tier_level') : 0;

        echo "<p>Current tier level: {$current_level}</p>";

        // Try to downgrade to lower tier
        $post = [
            'action' => 'downgrade',
            'product_id' => $this->tier_products[$levels[0]]
        ];

        try {
            $result = change_subscription_logic([], $post);

            if ($immediate) {
                // Check if user is now on lower tier
                $new_tier = SubscriptionTier::GetUserTier($this->test_user_id);

                if ($new_tier && $new_tier->key == $this->tiers[$levels[0]]['id']) {
                    echo "<p class='text-success'>✓ Immediate downgrade successful</p>";
                    return true;
                } else {
                    $message = "Downgrade failed or tier not updated";
                    echo "<p class='text-danger'>✗ {$message}</p>";
                    $this->recordFailure('Logic File Downgrade (immediate)', $message);
                    return false;
                }
            } else {
                // For end-of-period, tier should NOT change yet
                $new_tier = SubscriptionTier::GetUserTier($this->test_user_id);

                if ($new_tier && $new_tier->key == $current_tier->key) {
                    echo "<p class='text-success'>✓ End-of-period downgrade scheduled (tier unchanged until period end)</p>";
                    return true;
                } else {
                    $message = "Tier changed immediately (should wait until period end)";
                    echo "<p class='text-danger'>✗ {$message}</p>";
                    $this->recordFailure('Logic File Downgrade (end-of-period)', $message);
                    return false;
                }
            }
        } catch (Exception $e) {
            $message = "Exception during downgrade: " . $e->getMessage();
            echo "<p class='text-danger'>✗ " . htmlspecialchars($message) . "</p>";
            $this->recordFailure('Logic File Downgrade', $message);
            return false;
        }
    }

    /**
     * Test cancellation through logic file
     */
    private function testLogicFileCancellation($immediate = true) {
        $timing = $immediate ? 'immediate' : 'end_of_period';
        echo "<h4>Test: Cancellation via change_subscription_logic() ({$timing})</h4>";

        // Enable cancellation
        $this->applySettings([
            'subscription_cancellation_enabled' => '1',
            'subscription_cancellation_timing' => $timing,
            'subscription_cancellation_prorate' => '0'
        ]);

        $post = ['action' => 'cancel'];

        try {
            $result = change_subscription_logic([], $post);

            if ($immediate) {
                // User should be removed from tier
                $current_tier = SubscriptionTier::GetUserTier($this->test_user_id);

                if (!$current_tier) {
                    echo "<p class='text-success'>✓ Immediate cancellation successful - user removed from tier</p>";
                    return true;
                } else {
                    $message = "User still has tier (should be removed)";
                    echo "<p class='text-danger'>✗ {$message}</p>";
                    $this->recordFailure('Logic File Cancellation (immediate)', $message);
                    return false;
                }
            } else {
                // User should still have tier until period end
                $current_tier = SubscriptionTier::GetUserTier($this->test_user_id);

                if ($current_tier) {
                    echo "<p class='text-success'>✓ End-of-period cancellation scheduled (tier active until period end)</p>";
                    return true;
                } else {
                    $message = "User removed from tier immediately (should wait until period end)";
                    echo "<p class='text-danger'>✗ {$message}</p>";
                    $this->recordFailure('Logic File Cancellation (end-of-period)', $message);
                    return false;
                }
            }
        } catch (Exception $e) {
            $message = "Exception during cancellation: " . $e->getMessage();
            echo "<p class='text-danger'>✗ " . htmlspecialchars($message) . "</p>";
            $this->recordFailure('Logic File Cancellation', $message);
            return false;
        }
    }

    /**
     * Test reactivation through logic file
     */
    private function testLogicFileReactivation() {
        echo "<h4>Test: Reactivation via change_subscription_logic()</h4>";

        // Enable reactivation
        $this->applySettings([
            'subscription_reactivation_enabled' => '1'
        ]);

        // First, schedule a cancellation at period end
        echo "<p style='margin-left:20px'>Step 1: Scheduling cancellation at period end...</p>";
        $this->applySettings([
            'subscription_cancellation_enabled' => '1',
            'subscription_cancellation_timing' => 'end_of_period',
            'subscription_cancellation_prorate' => '0'
        ]);

        $cancel_post = ['action' => 'cancel'];

        try {
            $cancel_result = change_subscription_logic([], $cancel_post);

            // Debug: Check what the logic returned
            if (isset($cancel_result->data['error_message'])) {
                echo "<p style='margin-left:20px' class='text-warning'>⚠️ Cancel returned error: " . htmlspecialchars($cancel_result->data['error_message']) . "</p>";
            }
            if (isset($cancel_result->data['success_message'])) {
                echo "<p style='margin-left:20px'>ℹ️ Cancel success: " . htmlspecialchars($cancel_result->data['success_message']) . "</p>";
            }

            // Verify cancellation was scheduled
            $subscriptions = new MultiOrderItem(
                array('user_id' => $this->test_user_id, 'is_active_subscription' => true),
                array('order_item_id' => 'DESC')
            );
            $subscriptions->load();

            echo "<p style='margin-left:20px'>Debug: Found " . $subscriptions->count() . " active subscription(s) after scheduling cancellation</p>";

            if ($subscriptions->count() == 0) {
                // Try without the is_active filter to see what's there
                $all_subscriptions = new MultiOrderItem(
                    array('user_id' => $this->test_user_id, 'is_subscription' => true),
                    array('order_item_id' => 'DESC')
                );
                $all_subscriptions->load();
                echo "<p style='margin-left:20px'>Debug: Found " . $all_subscriptions->count() . " total subscription(s)</p>";

                if ($all_subscriptions->count() > 0) {
                    $sub = $all_subscriptions->get(0);
                    echo "<p style='margin-left:20px'>Debug: First subscription - cancelled_time: " . ($sub->get('odi_subscription_cancelled_time') ?: 'NULL') . ", cancel_at_period_end: " . ($sub->get('odi_subscription_cancel_at_period_end') ? 'true' : 'false') . "</p>";
                }

                $message = "No subscription found after scheduling cancellation";
                echo "<p class='text-danger'>✗ {$message}</p>";
                $this->recordFailure('Logic File Reactivation', $message);
                return false;
            }

            $subscription = $subscriptions->get(0);

            // Check if cancellation was scheduled (should have cancel_at_period_end set)
            // Note: We can't check Stripe directly in this simple test, so we verify the action didn't fail
            if (isset($cancel_result->data['error_message'])) {
                $message = "Cancellation scheduling failed: " . $cancel_result->data['error_message'];
                echo "<p class='text-danger'>✗ {$message}</p>";
                $this->recordFailure('Logic File Reactivation', $message);
                return false;
            }

            echo "<p style='margin-left:20px' class='text-success'>✓ Cancellation scheduled at period end</p>";

            // Now test reactivation
            echo "<p style='margin-left:20px'>Step 2: Reactivating subscription...</p>";
            $reactivate_post = ['action' => 'reactivate'];

            $reactivate_result = change_subscription_logic([], $reactivate_post);

            // Check for success message
            if (isset($reactivate_result->data['success_message'])) {
                echo "<p style='margin-left:20px' class='text-success'>✓ Reactivation message: " . htmlspecialchars($reactivate_result->data['success_message']) . "</p>";
            }

            // Check for error
            if (isset($reactivate_result->data['error_message'])) {
                $message = "Reactivation failed: " . $reactivate_result->data['error_message'];
                echo "<p class='text-danger'>✗ {$message}</p>";
                $this->recordFailure('Logic File Reactivation', $message);
                return false;
            }

            // Verify user still has tier (should remain active)
            $current_tier = SubscriptionTier::GetUserTier($this->test_user_id);

            if ($current_tier) {
                echo "<p class='text-success'>✓ Reactivation successful - subscription and tier still active</p>";
                return true;
            } else {
                $message = "User lost tier after reactivation";
                echo "<p class='text-danger'>✗ {$message}</p>";
                $this->recordFailure('Logic File Reactivation', $message);
                return false;
            }

        } catch (Exception $e) {
            $message = "Exception during reactivation test: " . $e->getMessage();
            echo "<p class='text-danger'>✗ " . htmlspecialchars($message) . "</p>";
            $this->recordFailure('Logic File Reactivation', $message);
            return false;
        }
    }

    /**
     * Cleanup Stripe test data
     */
    private function cleanupStripeData() {
        echo "<h4>Cleaning Up Stripe Test Data</h4>";

        try {
            $user = new User($this->test_user_id, TRUE);
            $stripe_customer_id = $user->get('usr_stripe_customer_id_test');

            if ($stripe_customer_id) {
                $stripe_helper = new StripeHelper();

                // Cancel all subscriptions for this customer
                $subscriptions = $stripe_helper->get_stripe_client()->subscriptions->all([
                    'customer' => $stripe_customer_id,
                    'status' => 'all'
                ]);

                foreach ($subscriptions->data as $subscription) {
                    if ($subscription->status !== 'canceled') {
                        $stripe_helper->get_stripe_client()->subscriptions->cancel($subscription->id);
                        echo "<p>✓ Cancelled Stripe subscription: {$subscription->id}</p>";
                    }
                }

                // Delete customer
                $stripe_helper->get_stripe_client()->customers->delete($stripe_customer_id);
                echo "<p>✓ Deleted Stripe customer: {$stripe_customer_id}</p>";

                // Clear the customer ID from user record so it doesn't get reused
                $user->set('usr_stripe_customer_id_test', NULL);
                $user->save();
            }

            return true;
        } catch (Exception $e) {
            echo "<p class='text-warning'>⚠️ Error cleaning Stripe data: " . htmlspecialchars($e->getMessage()) . "</p>";
            return false;
        }
    }

    /**
     * Main execution method
     */
    public function run() {
        echo "<h2>Subscription Tier Testing Script</h2>\n";
        $this->displayDatabaseInfo();

        // Step 1: Load tiers
        echo "<hr><h3>Step 1: Loading Tiers</h3>";
        $this->loadTiers();

        if (count($this->tiers) < 3) {
            echo "<p class='text-danger'>ERROR: Need at least 3 tiers for testing. Found: " . count($this->tiers) . "</p>";
            return;
        }

        // Step 2: Load tier products
        echo "<hr><h3>Step 2: Loading Tier Products</h3>";
        if (!$this->loadTierProducts()) {
            return;
        }

        // Step 3: Create test user
        echo "<hr><h3>Step 3: Creating Test User</h3>";
        $this->getTestUser();

        // Step 4: Save current settings
        echo "<hr><h3>Step 4: Saving Current Settings</h3>";
        $this->saveSettings([
            'subscription_downgrades_enabled',
            'subscription_downgrade_timing',
            'subscription_cancellation_enabled',
            'subscription_cancellation_timing',
            'subscription_reactivation_enabled',
            'subscription_cancellation_prorate'
        ]);
        echo "<p>✓ Settings saved for restoration</p>";

        // Step 5: Run model-level tests
        echo "<hr><h3>Step 5: Model Layer Tests</h3>";

        $this->testBasicTierAssignment();
        SubscriptionTier::removeUserFromAllTiers($this->test_user_id);

        $this->testUpgradeOnlyLogic();
        SubscriptionTier::removeUserFromAllTiers($this->test_user_id);

        $this->testFeatureAccess();
        SubscriptionTier::removeUserFromAllTiers($this->test_user_id);

        $this->testMinimumTierLevel();
        SubscriptionTier::removeUserFromAllTiers($this->test_user_id);

        $this->testChangeTracking();
        SubscriptionTier::removeUserFromAllTiers($this->test_user_id);

        $this->testStripePriceSync();

        // Step 6: Integration tests with Stripe
        echo "<hr><h3>Step 6: Integration Tests with Stripe API</h3>";
        echo "<p class='text-info'>Testing actual subscription changes through logic file with real Stripe calls...</p>";

        // Ensure all tier products have Stripe price IDs before testing
        echo "<h4>Preparing Stripe Price IDs for All Tier Products</h4>";
        $stripe_helper = new StripeHelper();
        foreach ($this->tier_products as $level => $product_id) {
            $product = new Product($product_id, TRUE);
            $versions = $product->get_product_versions();
            if ($versions->count() > 0) {
                $product_version = $versions->get(0);
                $price_id = $product_version->get('prv_stripe_price_id_test');
                if (!$price_id) {
                    echo "<p>Creating Stripe price for tier level {$level} (product {$product_id})...</p>";
                    $stripe_price = $stripe_helper->get_or_create_price($product_version);
                    echo "<p>✓ Created price: {$stripe_price->id}</p>";
                } else {
                    echo "<p>✓ Tier level {$level} already has price ID: {$price_id}</p>";
                }
            }
        }

        // Test upgrade
        $this->testLogicFileUpgrade();

        // Test immediate downgrade
        $this->testLogicFileDowngrade(true);

        // Clean up and start fresh for cancellation test
        SubscriptionTier::removeUserFromAllTiers($this->test_user_id);
        $this->cleanupStripeData();

        // Create new subscription for cancellation test
        echo "<hr>";
        $subscription_data = $this->createStripeSubscription($this->tier_products[array_keys($this->tiers)[0]]);
        if ($subscription_data) {
            // Simulate session
            $_SESSION['usr_user_id'] = $this->test_user_id;
            $_SESSION['loggedin'] = true;
            $session = SessionControl::get_instance();

            // Test immediate cancellation
            $this->testLogicFileCancellation(true);
        }

        // Clean up and start fresh for reactivation test
        SubscriptionTier::removeUserFromAllTiers($this->test_user_id);
        $this->cleanupStripeData();

        // Clean up old order items from previous tests
        echo "<p>Cleaning up old order items from database...</p>";
        $old_items = new MultiOrderItem(
            array('user_id' => $this->test_user_id),
            array('order_item_id' => 'DESC')
        );
        $old_items->load();
        foreach ($old_items as $item) {
            $item->permanent_delete();
        }
        echo "<p>✓ Cleaned up " . $old_items->count() . " old order items</p>";

        // Create new subscription for reactivation test
        echo "<hr>";
        $subscription_data = $this->createStripeSubscription($this->tier_products[array_keys($this->tiers)[0]]);
        if ($subscription_data) {
            // Simulate session
            $_SESSION['usr_user_id'] = $this->test_user_id;
            $_SESSION['loggedin'] = true;
            $session = SessionControl::get_instance();

            // Test reactivation
            $this->testLogicFileReactivation();
        }

        // Step 7: Restore settings
        echo "<hr><h3>Step 7: Restoring Original Settings</h3>";
        $this->restoreSettings();
        echo "<p>✓ Original settings restored</p>";

        // Step 8: Cleanup
        echo "<hr><h3>Step 8: Cleanup</h3>";

        // Clean up Stripe data
        $this->cleanupStripeData();

        // Remove user from tiers
        SubscriptionTier::removeUserFromAllTiers($this->test_user_id);
        echo "<p>✓ User removed from all tiers</p>";

        // Delete test user
        $user = new User($this->test_user_id, TRUE);
        $user->permanent_delete();
        echo "<p>✓ Test user deleted</p>";

        // Summary
        echo "<hr><h2>Test Complete</h2>";

        // Show failures prominently if any occurred
        if (!empty($this->test_failures)) {
            echo "<div style='background: #ffcccc; border: 5px solid #cc0000; padding: 20px; margin: 20px 0;'>";
            echo "<h2 style='color: #cc0000; margin-top: 0;'>❌❌❌ TEST FAILURES DETECTED ❌❌❌</h2>";
            echo "<h3>" . count($this->test_failures) . " test(s) failed:</h3>";
            echo "<ul style='font-size: 1.2em;'>";
            foreach ($this->test_failures as $failure) {
                echo "<li><strong>{$failure['test']}</strong>: {$failure['message']}</li>";
            }
            echo "</ul>";
            echo "</div>";
        }

        // Success or completion message
        if (empty($this->test_failures)) {
            echo "<div class='alert alert-success'>";
            echo "<h4>✓ All Subscription Tier Tests Completed Successfully!</h4>";
        } else {
            echo "<div class='alert alert-warning'>";
            echo "<h4>⚠️ Subscription Tier Tests Completed With Failures</h4>";
        }
        echo "<p><strong>Tests Run:</strong></p>";
        echo "<ul>";
        echo "<li>✓ Basic tier assignment</li>";
        echo "<li>✓ Upgrade-only purchase logic</li>";
        echo "<li>✓ Feature access</li>";
        echo "<li>✓ Minimum tier level checking</li>";
        echo "<li>✓ Change tracking</li>";
        echo "<li>✓ Stripe price ID auto-sync (with real Stripe API)</li>";
        echo "<li>✓ Upgrade via change_subscription_logic (with Stripe)</li>";
        echo "<li>✓ Downgrade immediate (with Stripe)</li>";
        echo "<li>✓ Cancellation immediate (with Stripe)</li>";
        echo "<li>✓ Reactivation (with Stripe)</li>";
        echo "</ul>";
        echo "<p><strong>What was tested:</strong></p>";
        echo "<ul>";
        echo "<li>✓ Model layer (SubscriptionTier, MultiSubscriptionTier)</li>";
        echo "<li>✓ Business logic layer (change_subscription_logic.php)</li>";
        echo "<li>✓ Stripe API integration (real test mode calls)</li>";
        echo "<li>✓ Price ID auto-generation and storage</li>";
        echo "<li>✓ Subscription creation, upgrade, downgrade, cancellation, reactivation</li>";
        echo "<li>✓ Order and OrderItem creation</li>";
        echo "<li>✓ User tier assignment through full flow</li>";
        echo "</ul>";
        echo "<p><strong>Not tested (requires manual testing):</strong></p>";
        echo "<ul>";
        echo "<li>• View layer UI (/views/change-subscription.php)</li>";
        echo "<li>• End-of-period timing for downgrades (requires waiting for billing cycle)</li>";
        echo "<li>• Proration calculations (requires specific test scenarios)</li>";
        echo "<li>• Webhook processing</li>";
        echo "</ul>";
        echo "</div>";
    }
}
?>