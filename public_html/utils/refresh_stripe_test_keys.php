#!/usr/bin/env php
<?php
/**
 * Utility script to refresh all Stripe test mode keys
 * This script:
 * - Deletes existing Stripe test IDs from database
 * - Recreates or refreshes them with current Stripe test account
 * - Only runs in test mode
 * - Requires permission level 10 (superadmin)
 */

error_reporting(E_ERROR | E_PARSE);

// Use direct requires for utility scripts running from command line
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/StripeHelper.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/products_class.php'));
require_once(PathHelper::getIncludePath('data/product_versions_class.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

$settings = Globalvars::get_instance();
$session = SessionControl::get_instance();

// Check permission level 10 if running from web
if (php_sapi_name() !== 'cli') {
    if ($session->get_permission() < 10) {
        echo "ERROR: This script requires permission level 10 (superadmin).\n";
        exit(1);
    }
}

// Ensure we're in test mode
if (!StripeHelper::isTestMode()) {
    echo "ERROR: This script can only be run in test mode.\n";
    echo "Current mode: LIVE - Aborting to prevent data loss.\n";
    exit(1);
}

echo "===========================================\n";
echo "Stripe Test Keys Refresh Utility\n";
echo "===========================================\n";
echo "Mode: TEST\n";

// Display user info if available
if (php_sapi_name() !== 'cli' && $session->get_user_id()) {
    $user = new User($session->get_user_id(), TRUE);
    echo "User: " . $user->get('usr_email') . " (Permission: " . $session->get_permission() . ")\n\n";
} else {
    echo "Running from: " . (php_sapi_name() === 'cli' ? 'Command Line' : 'Web') . "\n\n";
}

// Initialize Stripe helper
$stripe_helper = new StripeHelper();

// Check if Stripe keys are configured
$test_public_key = $settings->get_setting(StripeHelper::getStripeSettingKey('stripe_api_key'));
$test_secret_key = $settings->get_setting(StripeHelper::getStripeSettingKey('stripe_api_pkey'));

if (!$test_public_key || !$test_secret_key) {
    echo "ERROR: Stripe test API keys are not configured.\n";
    echo "Please configure stripe_api_key_test and stripe_api_pkey_test in settings.\n";
    exit(1);
}

echo "Using Stripe test keys:\n";
echo "- Public Key: " . substr($test_public_key, 0, 20) . "...\n";
echo "- Secret Key: " . substr($test_secret_key, 0, 20) . "...\n\n";

// Ask for confirmation
if (php_sapi_name() === 'cli') {
    echo "WARNING: This will delete and recreate all Stripe test IDs.\n";
    echo "Continue? (yes/no): ";
    $confirmation = trim(fgets(STDIN));
    if (strtolower($confirmation) !== 'yes') {
        echo "Aborted.\n";
        exit(0);
    }
}

$dbconnector = DbConnector::get_instance();
$dblink = $dbconnector->get_db_link();

// Track statistics
$stats = [
    'users_processed' => 0,
    'users_created' => 0,
    'users_errors' => 0,
    'products_processed' => 0,
    'products_created' => 0,
    'products_errors' => 0,
    'prices_processed' => 0,
    'prices_created' => 0,
    'prices_errors' => 0
];

echo "\n=== PHASE 1: Collect and clear existing test IDs ===\n";

// Get users that have Stripe test customer IDs before clearing
$sql = "SELECT usr_user_id FROM usr_users WHERE usr_stripe_customer_id_test IS NOT NULL";
$q = $dblink->prepare($sql);
$q->execute();
$users_with_stripe_ids = $q->fetchAll(PDO::FETCH_COLUMN);
$users_to_refresh = count($users_with_stripe_ids);

// Clear user Stripe customer IDs
$sql = "UPDATE usr_users SET usr_stripe_customer_id_test = NULL WHERE usr_stripe_customer_id_test IS NOT NULL";
$q = $dblink->prepare($sql);
$q->execute();
$cleared_users = $q->rowCount();
echo "Found $users_to_refresh users with Stripe test customer IDs\n";
echo "Cleared $cleared_users user Stripe test customer IDs\n";

// Clear product Stripe product IDs
$sql = "UPDATE pro_products SET pro_stripe_product_id_test = NULL WHERE pro_stripe_product_id_test IS NOT NULL";
$q = $dblink->prepare($sql);
$q->execute();
$cleared_products = $q->rowCount();
echo "Cleared $cleared_products product Stripe test product IDs\n";

// Clear product version Stripe price IDs
$sql = "UPDATE prv_product_versions SET prv_stripe_price_id_test = NULL WHERE prv_stripe_price_id_test IS NOT NULL";
$q = $dblink->prepare($sql);
$q->execute();
$cleared_prices = $q->rowCount();
echo "Cleared $cleared_prices product version Stripe test price IDs\n";

echo "\n=== PHASE 2: Recreate Stripe test IDs ===\n";

// Process Products first (needed for prices)
echo "\n--- Processing Products ---\n";
$products = new MultiProduct(['is_active' => true]);
if ($products->count_all() > 0) {
    $products->load();

    foreach ($products as $product) {
        $stats['products_processed']++;
        $product_name = $product->get('pro_name');

        try {
            // Build product parameters
            $product_params = [
                'name' => $product_name,
                'metadata' => [
                    'product_id' => $product->get('pro_product_id'),
                    'environment' => 'test'
                ]
            ];

            // Only add description if it's not empty
            $description = $product->get('pro_description');
            if (!empty($description)) {
                $product_params['description'] = $description;
            } else {
                // Use product name as description if empty
                $product_params['description'] = $product_name;
            }

            // Create new product in Stripe
            $stripe_product = $stripe_helper->create_product($product_params);

            // Save the Stripe product ID
            $product->set('pro_stripe_product_id_test', $stripe_product->id);
            $product->save();

            $stats['products_created']++;
            echo "  ✓ Created product: $product_name (ID: {$stripe_product->id})\n";

        } catch (Exception $e) {
            $stats['products_errors']++;
            echo "  ✗ Error creating product '$product_name': " . $e->getMessage() . "\n";
        }
    }
} else {
    echo "  No active products found.\n";
}

// Process Product Versions (Prices)
echo "\n--- Processing Product Versions (Prices) ---\n";
$products = new MultiProduct(['is_active' => true]);
if ($products->count_all() > 0) {
    $products->load();

    foreach ($products as $product) {
        // Skip products without Stripe product ID (those that failed to create)
        if (!$product->get('pro_stripe_product_id_test')) {
            echo "  ⊘ Skipping prices for product '{$product->get('pro_name')}' - no Stripe product ID\n";
            continue;
        }

        $versions = $product->get_product_versions();

        foreach ($versions as $version) {
            $stats['prices_processed']++;
            $version_name = $version->get('prv_name');
            $price = $version->get('prv_version_price');

            try {
                // Get or create the price in Stripe
                $stripe_helper->get_or_create_price($version, $price);

                $stats['prices_created']++;
                echo "  ✓ Created/updated price for: {$product->get('pro_name')} - $version_name (\$" . number_format($price, 2) . ")\n";

            } catch (Exception $e) {
                $stats['prices_errors']++;
                echo "  ✗ Error creating price for '$version_name': " . $e->getMessage() . "\n";
            }
        }
    }
} else {
    echo "  No product versions found.\n";
}

// Process Users (Customers) - Only users that had Stripe test customer IDs
echo "\n--- Processing Users (Customers) ---\n";

if (count($users_with_stripe_ids) > 0) {
    echo "  Recreating Stripe customers for $users_to_refresh users that had test customer IDs...\n";

    foreach ($users_with_stripe_ids as $user_id) {
        $stats['users_processed']++;

        try {
            // Load the user
            $user = new User($user_id, TRUE);
            $user_email = $user->get('usr_email');

            // Skip users without email addresses or deleted/disabled users
            if (empty($user_email) || $user->get('usr_delete_time') || $user->get('usr_is_disabled')) {
                echo "  ⊘ Skipping user ID $user_id (no email or inactive)\n";
                continue;
            }

            // Create customer in Stripe
            $stripe_customer_id = $stripe_helper->create_customer_at_stripe($user, 'id');

            $stats['users_created']++;
            echo "  ✓ Created customer: $user_email (ID: $stripe_customer_id)\n";

        } catch (Exception $e) {
            $stats['users_errors']++;

            // Try to get email for error reporting
            $error_email = isset($user_email) ? $user_email : "User ID $user_id";
            echo "  ✗ Error creating customer '$error_email': " . $e->getMessage() . "\n";
        }
    }
} else {
    echo "  No users with existing test customer IDs found.\n";
}

// Display summary
echo "\n===========================================\n";
echo "Summary\n";
echo "===========================================\n";
echo "Users:\n";
echo "  - Processed: {$stats['users_processed']}\n";
echo "  - Created: {$stats['users_created']}\n";
echo "  - Errors: {$stats['users_errors']}\n";
echo "\nProducts:\n";
echo "  - Processed: {$stats['products_processed']}\n";
echo "  - Created: {$stats['products_created']}\n";
echo "  - Errors: {$stats['products_errors']}\n";
echo "\nPrices:\n";
echo "  - Processed: {$stats['prices_processed']}\n";
echo "  - Created: {$stats['prices_created']}\n";
echo "  - Errors: {$stats['prices_errors']}\n";

$total_errors = $stats['users_errors'] + $stats['products_errors'] + $stats['prices_errors'];
if ($total_errors > 0) {
    echo "\n⚠ Completed with $total_errors errors. Review the output above for details.\n";
    exit(1);
} else {
    echo "\n✅ All Stripe test keys refreshed successfully!\n";
    exit(0);
}