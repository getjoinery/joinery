<?php
/**
 * Test Runner Index
 * 
 * Menu page to choose which type of tests to run
 */

require_once(__DIR__ . '/../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
?>
<!DOCTYPE html>
<html>
<head>
    <title>Model Test Runner</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 40px auto;
            padding: 20px;
        }
        .test-option {
            background: #f5f5f5;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .test-option h3 {
            margin-top: 0;
            color: #333;
        }
        .test-option p {
            color: #666;
            margin: 10px 0;
        }
        .test-option a {
            display: inline-block;
            background: #007bff;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 4px;
            margin-right: 10px;
        }
        .test-option a:hover {
            background: #0056b3;
        }
        .test-option a.secondary {
            background: #6c757d;
        }
        .test-option a.secondary:hover {
            background: #545b62;
        }
        .combined-option {
            background: #e8f4f8;
            border-color: #bee5eb;
        }
        code {
            background: #f8f9fa;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
        }
    </style>
</head>
<body>
    <h1>Model Test Runner</h1>
    
    <div class="test-option">
        <h3>Single Model Tests</h3>
        <p>Run CRUD operations, field validation, and constraint tests on individual model classes.</p>
        <p><strong>Tests:</strong> Create, Read, Update, Delete, Required fields, Type constraints, Unique constraints</p>
        <p>
            <a href="run_all">Run All Single Tests</a>
            <a href="run_all?verbose=1" class="secondary">Run with Verbose Output</a>
        </p>
    </div>
    
    <div class="test-option">
        <h3>Multi Class Tests</h3>
        <p>Test collection classes (Multi*) that handle querying multiple records.</p>
        <p><strong>Tests:</strong> Basic loading, Filtering, Ordering, Pagination, Combined queries</p>
        <p>
            <a href="run_multi">Run All Multi Tests</a>
            <a href="run_multi?verbose=1" class="secondary">Run with Verbose Output</a>
        </p>
    </div>
    
    <div class="test-option combined-option">
        <h3>Combined Testing (Advanced)</h3>
        <p>Run both single and multi tests in one session. Use these options with caution as they may take longer.</p>
        <p>
            <a href="run_all?test_multi=1">Single + Multi Tests</a>
        </p>
        <p><small><strong>Note:</strong> This runs single tests with Multi tests enabled via GET parameter. The recommended approach is to run tests separately using the options above.</small></p>
    </div>
    
    <hr>
    
    <h3>Test Specific Models</h3>
    <p>To test a specific model, add it to the URL:</p>
    <ul>
        <li><code>/tests/models/run_all.php?class=User</code> - Test only the User model</li>
        <li><code>/tests/models/run_multi.php?class=User</code> - Test only MultiUser</li>
    </ul>
    
    <h3>Environment Variables</h3>
    <p>You can also control testing via environment variables:</p>
    <ul>
        <li><code>TEST_MULTI=1 php run_all.php</code> - Enable Multi tests in CLI mode</li>
    </ul>
</body>
</html>