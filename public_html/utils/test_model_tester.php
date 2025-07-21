<?php
/**
 * Test script to verify ModelTester implementation
 * This tests the Phase 1 automated testing functionality
 */

require_once(__DIR__ . '/../includes/PathHelper.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('data/activation_codes_class.php');

echo "<h2>Testing ModelTester Implementation</h2>\n";
echo "<p>This will test the automated testing system using the ActivationCode model.</p>\n";

try {
    // Create an ActivationCode instance and run its automated test
    echo "<h3>Testing ActivationCode Model</h3>\n";
    
    $activation_code = new ActivationCode();
    $result = $activation_code->test(true); // true for debug output
    
    if ($result) {
        echo "<div style='color: green; font-weight: bold;'>✓ ModelTester implementation successful!</div>\n";
    } else {
        echo "<div style='color: red; font-weight: bold;'>✗ ModelTester test failed</div>\n";
    }
    
} catch (Exception $e) {
    echo "<div style='color: red;'>";
    echo "<h4>Error during testing:</h4>\n";
    echo "<p><strong>Message:</strong> " . htmlspecialchars($e->getMessage()) . "</p>\n";
    echo "<p><strong>File:</strong> " . htmlspecialchars($e->getFile()) . " (line " . $e->getLine() . ")</p>\n";
    echo "<h5>Stack Trace:</h5>\n";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>\n";
    echo "</div>\n";
}

// Display test statistics
echo "<h3>Test Statistics</h3>\n";
$stats = ModelTester::get_test_stats();
echo "<p><strong>Passed:</strong> " . $stats['passed'] . "</p>\n";
echo "<p><strong>Failed:</strong> " . $stats['failed'] . "</p>\n";

echo "<p><em>Test completed - see output above for details</em></p>\n";
?>