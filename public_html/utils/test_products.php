<?php
/**
 * Product Testing Script Runner
 * 
 * This script runs the ProductTester class to test product creation
 * via the admin_product_edit endpoint.
 * 
 * Usage: Access this file through your web browser while logged in as an admin user.
 */

echo "Starting test script...<br>\n";
flush();

echo "Including ProductTester class...<br>\n";
flush();

require_once(__DIR__ . '/ProductTester.php');

echo "Creating ProductTester instance...<br>\n";
flush();

try {
    // Run the product tests
    $tester = new ProductTester();
    echo "Running tests...<br>\n";
    flush();
    $tester->run();
} catch (Exception $e) {
    echo "<strong>ERROR:</strong> " . htmlspecialchars($e->getMessage()) . "<br>\n";
}
?>