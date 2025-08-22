<?php
// Simple test runner to verify email test fixes
require_once(__DIR__ . '/../../includes/PathHelper.php');
PathHelper::requireOnce('includes/Globalvars.php');
require_once(__DIR__ . '/EmailTestRunner.php');

echo "=== Email Template Test Fixes Verification ===\n\n";

try {
    // Create test runner
    $runner = new EmailTestRunner();
    
    // Run template tests only (to focus on our changes)
    echo "Running Template Tests...\n";
    $results = $runner->runAllTests();
    
    if (isset($results['template'])) {
        foreach ($results['template'] as $testName => $result) {
            $status = $result['passed'] ? '✓ PASS' : '✗ FAIL';
            echo "$status - $testName: {$result['message']}\n";
            
            if (!$result['passed'] && isset($result['details'])) {
                echo "  Details: " . json_encode($result['details'], JSON_PRETTY_PRINT) . "\n";
            }
        }
    }
    
    echo "\n=== Summary ===\n";
    
    // Count passes and fails
    $totalTests = 0;
    $passedTests = 0;
    
    if (isset($results['template'])) {
        foreach ($results['template'] as $result) {
            $totalTests++;
            if ($result['passed']) $passedTests++;
        }
    }
    
    echo "Template Tests: $passedTests/$totalTests passed\n";
    
    if ($passedTests === $totalTests) {
        echo "✓ All template tests are passing!\n";
    } else {
        echo "✗ Some template tests are failing - check details above.\n";
    }
    
} catch (Exception $e) {
    echo "Error running tests: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
?>