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

require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

// SAFETY: Set hard time limit for test execution (15 seconds)
set_time_limit(15);

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

// Show which database is being used
try {
	$dbconnector = DbConnector::get_instance();
	$dblink = $dbconnector->get_db_link();
	$sql = "SELECT current_database() as db_name";
	$q = $dblink->prepare($sql);
	$q->execute();
	$db_info = $q->fetch(PDO::FETCH_ASSOC);
	
	$test_mode = false;
	if (method_exists($dbconnector, 'is_test_mode')) {
		$test_mode = $dbconnector->is_test_mode();
	}
	
	echo '<div style="background: #f0f8ff; border: 1px solid #007bff; padding: 10px; margin: 10px 0; border-radius: 5px;">';
	echo '<strong>🗄️ Database:</strong> ' . htmlspecialchars($db_info['db_name']);
	if ($test_mode) {
		echo ' <span style="color: #007bff; font-weight: bold;">(TEST MODE)</span>';
	} else {
		echo ' <span style="color: #28a745; font-weight: bold;">(LIVE MODE)</span>';
	}
	echo '</div>';
	
} catch (Exception $e) {
	echo '<div style="background: #fff3cd; border: 1px solid #ffc107; padding: 10px; margin: 10px 0; border-radius: 5px;">';
	echo '<strong>⚠️ Warning:</strong> Could not determine database: ' . htmlspecialchars($e->getMessage());
	echo '</div>';
}

echo '<div style="background: #d1ecf1; padding: 10px; border: 1px solid #bee5eb; margin: 10px 0; border-radius: 4px;">';
echo '<strong>ℹ️ INFO:</strong> Testing with <strong>dynamically calculated records per model</strong> (capped at 20) for faster execution<br>';
echo '<small>Record count is calculated based on field complexity to ensure adequate test coverage.</small>';
echo '</div>';

// Display time limit safety notice
echo '<div style="background: #fff3cd; border: 1px solid #ffc107; padding: 5px 10px; margin: 5px 0; border-radius: 3px; font-size: 0.9em;">';
echo '⏱️ <strong>Safety Time Limit:</strong> Test execution will automatically stop after 15 seconds';
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
    echo "<b style='color: #333;'>{$class} (with {$multi_class})</b><br>\n";
    
    try {
        $multi_tester = new MultiModelTester($class);
        $result = $multi_tester->test(null, $verbose);
        
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
echo "<li><strong>Regular tests + Multi tests:</strong> <a href='run_all?test_multi=1'>run_all?test_multi=1</a></li>\n";
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