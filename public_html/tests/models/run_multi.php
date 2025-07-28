<?php
/**
 * Multi Class Test Runner
 * 
 * This script runs Multi class tests on all discovered model classes.
 * It demonstrates the Multi class testing functionality implemented in MultiModelTester.
 */

// Clear any output buffering and force immediate output
while (ob_get_level()) {
    ob_end_clean();
}

// Force immediate output
ini_set('output_buffering', '0');
ini_set('implicit_flush', '1');
ob_implicit_flush(true);

require_once(__DIR__ . '/../../includes/PathHelper.php');

PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/SessionControl.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');

// Enable Multi testing and disable single tests
define('TEST_MULTI', true);
define('MULTI_TESTS_ONLY', true);

// Load MultiModelTester class
require_once(__DIR__ . '/MultiModelTester.php');

// Discover all model classes using centralized method
$classes = LibraryFunctions::discover_model_classes();

// Load all classes to ensure Multi classes are available for class_exists() checks
foreach($classes as $class) {
    // This will trigger the autoloading/require_once for each class
    if (class_exists($class)) {
        // Class is now loaded
    }
}

echo '<h2>Multi Class Testing</h2>';
echo '<div style="background: #d1ecf1; padding: 10px; border: 1px solid #bee5eb; margin: 10px 0; border-radius: 4px;">';
echo '<strong>ℹ️ INFO:</strong> Testing with <strong>3 records per model</strong> for faster execution<br>';
echo '<small>This setting can be increased in MultiModelTester.php once testing is stable.</small>';
echo '</div>';
echo 'Found ' . count($classes) . ' model classes<br>';
echo '<p><em>Multi class testing validates collection classes (MultiUser, MultiProduct, etc.) by testing their query generation, filtering, ordering, and pagination against direct SQL queries.</em></p>';

// Debug: Verify constants are set
if (defined('MULTI_TESTS_ONLY') && MULTI_TESTS_ONLY) {
    echo '<p style="color: green;">✓ MULTI_TESTS_ONLY is enabled</p>';
} else {
    echo '<p style="color: red;">✗ MULTI_TESTS_ONLY is NOT enabled</p>';
}
echo '<br>';

$verbose = false;
if(isset($_GET['verbose']) && $_GET['verbose']){
    $verbose = true;
}

// Run Multi tests on each class
$successful_classes = 0;
$failed_classes = 0;
$skipped_classes = 0;
$multi_tested_classes = 0;
$debug_stop_after_one = false; // Now test all models

foreach($classes as $class){
    // Check if Multi class exists first
    $multi_class = 'Multi' . $class;
    $has_multi_class = class_exists($multi_class);
    
    if (!$has_multi_class) {
        echo "<span style='color: #999;'>[SKIP] {$class} - No Multi class ({$multi_class})</span><br>\n";
        $skipped_classes++;
        continue;
    }
    
    $multi_tested_classes++;
    echo "<b style='color: #333;'>TESTING: {$class} (with {$multi_class})</b><br>\n";
    echo "Step 1: Creating MultiModelTester instance...";
    flush(); // Force output
    
    try {
        // Directly instantiate and run MultiModelTester to ensure we only run Multi tests
        $multi_tester = new MultiModelTester($class);
        echo " created...";
        flush();
        
        // Debug: Make sure we're using the right tester
        if ($verbose) {
            echo "<br>\n  Using MultiModelTester for {$class}<br>\n";
            flush();
        }
        
        echo "Step 2: Calling test method...";
        flush();
        
        $result = $multi_tester->test(null, $verbose);
        
        echo " test-complete(result:" . var_export($result, true) . ")...";
        flush();
        
        if ($result === 'SKIPPED') {
            $skipped_classes++;
        } elseif ($result) {
            $successful_classes++;
            // Success message already printed by MultiModelTester in prominent green box
            
            // Continue testing all models (debug restriction removed)
        } else {
            // Clear failure message
            echo "<div style='background: #f8d7da; border: 2px solid #dc3545; padding: 15px; margin: 10px 0; border-radius: 8px;'>";
            echo "<h4 style='color: #721c24; margin: 0 0 8px 0;'>✗ TEST RUNNER FAILURE: {$class}</h4>";
            echo "<p style='color: #721c24; margin: 0;'>Multi test execution failed - see details above</p>";
            echo "</div>\n";
            $failed_classes++;
        }
    } catch (Exception $e) {
        // Clear exception message
        echo "<div style='background: #f8d7da; border: 2px solid #dc3545; padding: 15px; margin: 10px 0; border-radius: 8px;'>";
        echo "<h4 style='color: #721c24; margin: 0 0 8px 0;'>✗ EXCEPTION: {$class}</h4>";
        echo "<p style='color: #721c24; margin: 0;'><strong>Unexpected error:</strong> " . $e->getMessage() . "</p>";
        echo "</div>\n";
        $failed_classes++; 
    }
    
    echo "<br>\n"; // Add spacing between tests
    
    // Continue testing all models even after errors
}

// Display summary
echo "<hr><br>\n";
echo "<h3>Multi Test Summary</h3>\n";
echo "<strong>Total classes discovered:</strong> " . count($classes) . "<br>\n";
echo "<strong>Classes with Multi support:</strong> " . $multi_tested_classes . "<br>\n";
echo "<strong>Successful Multi tests:</strong> " . $successful_classes . "<br>\n";
echo "<strong>Failed Multi tests:</strong> " . $failed_classes . "<br>\n";
echo "<strong>Skipped classes:</strong> " . $skipped_classes . " (no corresponding Multi class)<br>\n";

// Get detailed test statistics from ModelTester
if (class_exists('ModelTester')) {
    $stats = ModelTester::get_test_stats();
    echo "<br><strong>Detailed Test Results:</strong><br>\n";
    echo "Passed tests: " . $stats['passed'] . "<br>\n";
    echo "Failed tests: " . $stats['failed'] . "<br>\n";
    echo "Warning tests: " . $stats['warned'] . "<br>\n";
    echo "Total individual tests: " . ($stats['passed'] + $stats['failed'] + $stats['warned']) . "<br>\n";
}

echo "<hr><br>\n";
echo "<h4>Usage Examples:</h4>\n";
echo "<ul>\n";
echo "<li><strong>Run Multi tests:</strong> <a href='?'>run_multi.php</a></li>\n";
echo "<li><strong>Verbose output:</strong> <a href='?verbose=1'>run_multi.php?verbose=1</a></li>\n";
echo "<li><strong>Regular tests + Multi tests:</strong> <a href='run_all.php?test_multi=1'>run_all.php?test_multi=1</a></li>\n";
echo "</ul>\n";

echo "<h4>How Multi Testing Works:</h4>\n";
echo "<p>Multi class testing validates that collection classes (like MultiUser, MultiProduct) correctly query and return data by:</p>\n";
echo "<ul>\n";
echo "<li>Creating strategic test records with varied data patterns</li>\n";
echo "<li>Testing basic loading, filtering, ordering, and pagination</li>\n";
echo "<li>Comparing Multi class results against direct SQL queries</li>\n";
echo "<li>Ensuring Multi classes generate correct SQL and handle edge cases</li>\n";
echo "</ul>\n";

exit;
?>