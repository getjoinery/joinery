<?php
// tests/email/index.php
require_once(__DIR__ . '/../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(__DIR__ . '/EmailTestRunner.php');
require_once(__DIR__ . '/suites/ServiceTests.php');
require_once(__DIR__ . '/suites/TemplateTests.php');
require_once(__DIR__ . '/suites/DeliveryTests.php');
require_once(__DIR__ . '/suites/AuthenticationTests.php');
require_once(__DIR__ . '/email_pattern_test.php');

$action = $_POST['action'] ?? '';
$results = null;

if ($action === 'run_tests') {
    $runner = new EmailTestRunner();
    $results = $runner->runAllTests();
} elseif ($action === 'test_mailgun_only') {
    $runner = new EmailTestRunner();
    $results = $runner->runMailgunTests();
} elseif ($action === 'test_smtp_only') {
    $runner = new EmailTestRunner();
    $results = $runner->runSmtpTests();
} elseif ($action === 'test_domain_only') {
    $runner = new EmailTestRunner();
    $results = $runner->runDomainTests();
} elseif ($action === 'run_pattern_tests') {
    $settings = Globalvars::get_instance();
    $test_email = $settings->get_setting('email_test_recipient') ?: 'test@example.com';
    
    $config = ['test_email' => $test_email];
    $tester = new EmailPatternTest($config, true); // Silent mode for web interface
    $patternResults = $tester->run();
    $summary = $tester->getSummary();
    
    // Format results for display
    $results = [
        'pattern_tests' => []
    ];
    
    foreach ($patternResults as $name => $result) {
        $results['pattern_tests'][$name] = [
            'passed' => $result['success'],
            'message' => $result['success'] ? 
                "Pattern '{$result['template']}' sent successfully via {$result['method']}" : 
                "Pattern failed: {$result['error']}",
            'details' => array_merge($result, [
                'summary' => $summary
            ])
        ];
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Email System Testing</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-5">
    <h1>Email System Testing</h1>
    <p class="text-muted">Test the email system configuration and functionality</p>
    
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5>Test Results</h5>
                </div>
                <div class="card-body">
                    <?php if ($results): ?>
                        <?php foreach ($results as $suite => $tests): ?>
                            <?php if ($suite === 'pattern_tests'): ?>
                                <h6>Email Pattern Tests</h6>
                                <p class="text-muted small">Testing all email code patterns found in the codebase</p>
                                
                                <?php if (isset($tests) && is_array($tests)): ?>
                                    <?php 
                                    $summary = null;
                                    foreach ($tests as $result) {
                                        if (isset($result['details']['summary'])) {
                                            $summary = $result['details']['summary'];
                                            break;
                                        }
                                    }
                                    ?>
                                    <?php if ($summary): ?>
                                        <div class="alert alert-info alert-sm mb-3">
                                            <strong>Summary:</strong> 
                                            <?= $summary['successful'] ?>/<?= $summary['total_patterns'] ?> patterns successful 
                                            (<?= $summary['success_rate'] ?>% success rate)
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php foreach ($tests as $test => $result): ?>
                                    <div class="alert alert-<?= 
                                        isset($result['warning']) && $result['warning'] ? 'warning' : 
                                        ($result['passed'] ? 'success' : 'danger') 
                                    ?> alert-sm">
                                        <strong><?= ucfirst(str_replace('_', ' ', $test)) ?>:</strong>
                                        <?= htmlspecialchars($result['message']) ?>
                                        <?php if (isset($result['details']['source'])): ?>
                                            <br><small class="text-muted">Source: <?= htmlspecialchars($result['details']['source']) ?></small>
                                        <?php endif; ?>
                                        <?php if (isset($result['details'])): ?>
                                            <details class="mt-2">
                                                <summary>Technical Details</summary>
                                                <pre><?= json_encode(array_diff_key($result['details'], ['summary' => '']), JSON_PRETTY_PRINT) ?></pre>
                                            </details>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <h6><?= ucfirst(str_replace('_', ' ', $suite)) ?> Tests</h6>
                                <?php foreach ($tests as $test => $result): ?>
                                    <div class="alert alert-<?= 
                                        isset($result['warning']) && $result['warning'] ? 'warning' : 
                                        ($result['passed'] ? 'success' : 'danger') 
                                    ?> alert-sm">
                                        <strong><?= ucfirst(str_replace('_', ' ', $test)) ?>:</strong>
                                        <?= htmlspecialchars($result['message']) ?>
                                        <?php if (isset($result['details'])): ?>
                                            <details class="mt-2">
                                                <summary>Details</summary>
                                                <pre><?= json_encode($result['details'], JSON_PRETTY_PRINT) ?></pre>
                                            </details>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <hr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted">Click "Run Tests" or "Run Pattern Tests" to begin testing the email system.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <h5>Actions</h5>
                </div>
                <div class="card-body">
                    <form method="post">
                        <button type="submit" name="action" value="run_tests" class="btn btn-primary w-100 mb-3">
                            Run All Tests
                        </button>
                        <button type="submit" name="action" value="run_pattern_tests" class="btn btn-success w-100 mb-3" title="Test all email code patterns found in the codebase">
                            Run Pattern Tests
                        </button>
                        <button type="submit" name="action" value="test_mailgun_only" class="btn btn-outline-primary w-100 mb-2">
                            Test Mailgun Only
                        </button>
                        <button type="submit" name="action" value="test_smtp_only" class="btn btn-outline-primary w-100 mb-2">
                            Test SMTP Only
                        </button>
                        <button type="submit" name="action" value="test_domain_only" class="btn btn-outline-secondary w-100 mb-3">
                            Test DNS/Domain
                        </button>
                    </form>
                    
                    <div class="d-grid gap-2">
                        <a href="/admin/admin_settings#email-settings" class="btn btn-outline-secondary btn-sm">
                            Email Settings
                        </a>
                        <a href="/admin/admin_debug_email_logs" class="btn btn-outline-info btn-sm">
                            View Debug Logs
                        </a>
                        <a href="/tests/email/legacy/email_test_harness.php" class="btn btn-outline-warning btn-sm">
                            Test Harness (CLI)
                        </a>
                    </div>
                    
                    <hr class="my-3">
                    
                    <div class="d-grid gap-2">
                        <h6 class="text-muted">Related Tools</h6>
                        <a href="/utils/email_setup_check" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-dns"></i> Domain Authentication Checker
                        </a>
                        <a href="/tests/email/auth_analysis.php" class="btn btn-outline-info btn-sm">
                            <i class="fas fa-microscope"></i> Advanced Auth Analysis
                        </a>
                        <small class="text-muted">
                            <strong>Pattern Tests</strong> send one email for each code pattern found in the codebase (11 patterns total).<br>
                            <strong>Domain checker</strong> tests DNS records. <strong>Advanced analysis</strong> tests real-world authentication results.
                        </small>
                    </div>
                </div>
            </div>
            
            <div class="card mt-3">
                <div class="card-header">
                    <h6>Current Settings</h6>
                </div>
                <div class="card-body">
                    <?php
                    $settings = Globalvars::get_instance();
                    $settingsToShow = [
                        'email_test_mode' => 'Test Mode',
                        'email_test_recipient' => 'Test Recipient',
                        'email_debug_mode' => 'Debug Mode',
                        'smtp_host' => 'SMTP Host',
                        'mailgun_domain' => 'Mailgun Domain',
                    ];
                    ?>
                    <small>
                        <?php foreach ($settingsToShow as $key => $label): ?>
                            <div><strong><?= $label ?>:</strong> 
                            <?= htmlspecialchars($settings->get_setting($key) ?: 'Not set') ?></div>
                        <?php endforeach; ?>
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>