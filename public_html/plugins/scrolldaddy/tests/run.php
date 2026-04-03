<?php
/**
 * ControlD Plugin Test Runner
 *
 * Usage:
 *   Mock mode (default): php run.php
 *   Live API mode: php run.php --live
 *   Web mock mode: /plugins/controld/tests/run
 *   Web live mode: /plugins/controld/tests/run?live=1
 *
 * Version: 1.04
 */

// Determine if live API mode is requested
$live_api_mode = false;
$is_web = php_sapi_name() !== 'cli';

// Check command line arguments
if (!$is_web) {
    $live_api_mode = in_array('--live', $argv ?? []);
} else {
    // Check GET parameter for web access
    $live_api_mode = isset($_GET['live']) && $_GET['live'] == '1';
}

// Show mode selection buttons for web interface
if ($is_web) {
    echo "<div style='margin-bottom: 20px; padding: 15px; background: #f5f5f5; border-radius: 5px;'>";
    echo "<strong>Test Mode:</strong> ";

    if ($live_api_mode) {
        echo "<a href='/plugins/controld/tests/run' class='btn btn-secondary' style='margin-right: 10px;'>Run Mock Tests</a>";
        echo "<button class='btn btn-danger' disabled>Live API Tests (Running)</button>";
    } else {
        echo "<button class='btn btn-primary' disabled style='margin-right: 10px;'>Mock Tests (Running)</button>";
        echo "<a href='/plugins/controld/tests/run?live=1' class='btn btn-danger' onclick=\"return confirm('This will make REAL API calls to ControlD and create a temporary device. Continue?');\">Run Live API Tests</a>";
    }
    echo "</div>";
}

echo "Starting ControlD plugin tests...\n";
if ($live_api_mode) {
    echo "<strong style='color: red;'>LIVE API MODE - Real API calls will be made!</strong>\n";
} else {
    echo "Mock mode (no real API calls)\n";
}
echo "Including ControlDTester class...\n";

require_once(__DIR__ . '/ControlDTester.php');

echo "Creating ControlDTester instance...\n";

try {
    $tester = new ControlDTester($live_api_mode);
    echo "Running tests...\n";
    $tester->run();
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>";
    echo "<h4>Fatal Error</h4>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}
