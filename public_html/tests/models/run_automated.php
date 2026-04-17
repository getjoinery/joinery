<?php
/**
 * Automated Test Runner for Phase 1 - Model Testing
 * 
 * This script automatically discovers and tests all models in the system
 * using the new ModelTester automated testing infrastructure.
 */

require_once(__DIR__ . '/../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

// Security check - require admin permissions  
$session = SessionControl::get_instance();
$session->check_permission(5);

class AutomatedTestRunner {
    
    private $passed = 0;
    private $failed = 0;
    private $skipped = 0;
    private $test_results = [];
    private $start_time;
    
    public function __construct() {
        $this->start_time = microtime(true);
    }
    
    /**
     * Discover all model classes in the system
     */
    public function discover_models() {
        // Use centralized discovery method
        return LibraryFunctions::discover_model_classes();
    }
    
    /**
     * Run tests on all discovered models
     */
    public function run_all_tests($debug = false) {
        echo "<h2>🚀 Automated Model Testing - Phase 1</h2>\n";
        echo "<p>Discovering and testing all models using automated inference...</p>\n";
        
        $models = $this->discover_models();
        
        echo "<h3>📋 Discovered Models</h3>\n";
        echo "<p>Found " . count($models) . " model classes:</p>\n";
        echo "<ul>\n";
        foreach ($models as $model) {
            echo "<li>$model</li>\n";
        }
        echo "</ul>\n";
        
        echo "<h3>🔬 Running Automated Tests</h3>\n";
        echo "<div style='font-family: monospace; background: #f5f5f5; padding: 10px; border: 1px solid #ddd;'>\n";
        
        foreach ($models as $model_class) {
            $this->run_model_tests($model_class, $debug);
        }
        
        echo "</div>\n";
        
        $this->generate_report();
    }
    
    /**
     * Run automated tests for a single model
     */
    private function run_model_tests($model_class, $debug = false) {
        echo "<div style='margin: 10px 0; padding: 5px; border-left: 3px solid #007cba;'>\n";
        echo "<strong style='color: #333;'>Testing: $model_class</strong><br>\n";
        
        try {
            // Use the static test method which delegates to ModelTester
            $result = $model_class::test($debug);
            
            if ($result === 'SKIPPED') {
                $this->skipped++;
                $this->test_results[$model_class] = 'SKIPPED: Configuration required';
                // Skip message already printed by ModelTester
            } else if ($result) {
                $this->passed++;
                $this->test_results[$model_class] = 'PASSED';
                echo "<span style='color: green;'>✓ PASSED</span><br>\n";
            } else {
                $this->failed++;
                $this->test_results[$model_class] = 'FAILED: Unknown error';
                echo "<span style='color: red;'>✗ FAILED: Unknown error</span><br>\n";
            }
            
        } catch (Exception $e) {
            $this->failed++;
            $this->test_results[$model_class] = 'FAILED: ' . $e->getMessage();
            echo "<span style='color: red;'>✗ FAILED: " . htmlspecialchars($e->getMessage()) . "</span><br>\n";
            
            if ($debug) {
                echo "<details style='color: red;'><summary>Stack Trace</summary><pre style='color: red;'>" . 
                     htmlspecialchars($e->getTraceAsString()) . "</pre></details>\n";
            }
        }
        
        echo "</div>\n";
        flush(); // Output results immediately
    }
    
    /**
     * Generate comprehensive test report
     */
    private function generate_report() {
        $end_time = microtime(true);
        $duration = round($end_time - $this->start_time, 2);
        
        echo "<h3>📊 Test Results Summary</h3>\n";
        
        $total = $this->passed + $this->failed + $this->skipped;
        $pass_rate = ($this->passed + $this->failed) > 0 ? round(($this->passed / ($this->passed + $this->failed)) * 100, 1) : 0;
        
        echo "<div style='background: #f9f9f9; padding: 15px; border: 1px solid #ddd; margin: 20px 0;'>\n";
        echo "<table style='width: 100%; border-collapse: collapse;'>\n";
        echo "<tr><td style='padding: 5px; font-weight: bold;'>Total Models:</td><td style='padding: 5px;'>$total</td></tr>\n";
        echo "<tr style='color: green;'><td style='padding: 5px; font-weight: bold;'>Passed:</td><td style='padding: 5px;'>{$this->passed}</td></tr>\n";
        echo "<tr style='color: red;'><td style='padding: 5px; font-weight: bold;'>Failed:</td><td style='padding: 5px;'>{$this->failed}</td></tr>\n";
        echo "<tr style='color: #ff9800;'><td style='padding: 5px; font-weight: bold;'>Skipped:</td><td style='padding: 5px;'>{$this->skipped}</td></tr>\n";
        echo "<tr><td style='padding: 5px; font-weight: bold;'>Pass Rate:</td><td style='padding: 5px;'>{$pass_rate}% (of testable models)</td></tr>\n";
        echo "<tr><td style='padding: 5px; font-weight: bold;'>Duration:</td><td style='padding: 5px;'>{$duration} seconds</td></tr>\n";
        echo "</table>\n";
        echo "</div>\n";
        
        if ($this->failed > 0) {
            echo "<h4>❌ Failed Tests</h4>\n";
            echo "<ul>\n";
            foreach ($this->test_results as $class => $result) {
                if (strpos($result, 'FAILED') === 0) {
                    echo "<li><strong>$class:</strong> " . htmlspecialchars($result) . "</li>\n";
                }
            }
            echo "</ul>\n";
        }
        
        // Success message
        if ($this->failed === 0) {
            echo "<div style='background: #d4edda; color: #155724; padding: 15px; border: 1px solid #c3e6cb; border-radius: 5px; margin: 20px 0;'>\n";
            echo "<h4>🎉 All Tests Passed!</h4>\n";
            echo "<p>Congratulations! All $total models passed their automated tests.</p>\n";
            echo "</div>\n";
        } else {
            echo "<div style='background: #f8d7da; color: #721c24; padding: 15px; border: 1px solid #f5c6cb; border-radius: 5px; margin: 20px 0;'>\n";
            echo "<h4>⚠️ Some Tests Failed</h4>\n";
            echo "<p>{$this->failed} out of $total tests failed. Please review the failures above.</p>\n";
            echo "</div>\n";
        }
        
        echo "<h4>🔍 What Was Tested</h4>\n";
        echo "<p>The automated testing system performed the following tests on each model:</p>\n";
        echo "<ul>\n";
        echo "<li><strong>CRUD Operations:</strong> Create, Read, Update, Delete with auto-generated data</li>\n";
        echo "<li><strong>Field Validation:</strong> Required fields, data types, constraints</li>\n";
        echo "<li><strong>Database Constraints:</strong> Unique fields, nullable fields</li>\n";
        echo "<li><strong>Edge Cases:</strong> Boundary conditions, null handling, max lengths</li>\n";
        echo "</ul>\n";
        
        echo "<p><em>Tests completed in {$duration} seconds</em></p>\n";
    }
}

// Handle web interface
$debug = isset($_GET['debug']) && $_GET['debug'] === '1';
$verbose = $_GET['verbose'] ?? false;

// Set verbose mode in ModelTester
if ($verbose) {
    require_once(__DIR__ . '/ModelTester.php');
    ModelTester::set_verbose($verbose);
}

echo "<!DOCTYPE html>\n";
echo "<html><head><title>Automated Model Testing</title></head><body>\n";

try {
    $runner = new AutomatedTestRunner();
    $runner->run_all_tests($debug);
} catch (Exception $e) {
    echo "<div style='color: red; background: #f8d7da; padding: 15px; border: 1px solid #f5c6cb; margin: 20px 0;'>\n";
    echo "<h4>💥 Fatal Error</h4>\n";
    echo "<p><strong>Message:</strong> " . htmlspecialchars($e->getMessage()) . "</p>\n";
    echo "<p><strong>File:</strong> " . htmlspecialchars($e->getFile()) . " (line " . $e->getLine() . ")</p>\n";
    echo "<details><summary>Stack Trace</summary><pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre></details>\n";
    echo "</div>\n";
}

echo "<hr>\n";
echo "<p>";
echo "<a href='?'>🔄 Run again</a> | ";
echo "<a href='?verbose=1'>📢 Verbose output (all)</a> | ";
echo "<a href='?debug=1'>🐛 Debug mode</a>";
echo "</p>\n";
echo "<form method='get' style='margin: 10px 0;'>\n";
echo "Verbose for specific class: <input type='text' name='verbose' placeholder='e.g., User' />\n";
echo "<button type='submit'>Run</button>\n";
echo "</form>\n";
echo "</body></html>\n";
?>