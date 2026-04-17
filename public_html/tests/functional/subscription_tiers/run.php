<?php
/**
 * Subscription Tier Testing Script Runner
 *
 * This script runs the SubscriptionTierTester class to test all combinations
 * of subscription tier changes.
 *
 * Usage: Access this file through your web browser while logged in as an admin user.
 */

echo "Starting subscription tier test script...<br>\n";
flush();

echo "Including SubscriptionTierTester class...<br>\n";
flush();

require_once(__DIR__ . '/SubscriptionTierTester.php');

echo "Creating SubscriptionTierTester instance...<br>\n";
flush();

try {
    // Run the subscription tier tests
    $tester = new SubscriptionTierTester();
    echo "Running tests...<br>\n";
    flush();
    $tester->run();

} catch (Exception $e) {
    echo "<strong>ERROR:</strong> " . htmlspecialchars($e->getMessage()) . "<br>\n";
    exit(1);
}
?>