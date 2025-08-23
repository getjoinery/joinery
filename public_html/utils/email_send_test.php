<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
PathHelper::requireOnce('includes/AdminPage.php');
PathHelper::requireOnce('includes/SessionControl.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');

$session = SessionControl::get_instance();
$session->check_permission(5);

// Determine the base path based on how the script is being run
if (!defined('GLOBALVARS_INCLUDED')) {
    // Running standalone - need to include files
    $base_path = dirname(__DIR__);
    require_once($base_path . '/includes/Globalvars.php');
    require_once($base_path . '/includes/EmailTemplate.php');
    
    // Try to load Mailgun dependencies if they exist
    $settings = Globalvars::get_instance();
    $composer_dir = $settings->get_setting('composerAutoLoad');
    if ($composer_dir && file_exists($composer_dir . 'autoload.php')) {
        require_once($composer_dir . 'autoload.php');
    }
}

$page = new AdminPage();
$settings = Globalvars::get_instance();

// Process form submission
$run_test = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $run_test = true;
    // Debug: Log that form was submitted
    error_log('Form submitted with POST data: ' . print_r($_POST, true));
}

// Configuration
$config = [
    'test_email' => $_POST['test_email'] ?? 'joineryemailtests@gmail.com',
    'imap_username' => $_POST['imap_username'] ?? 'joineryemailtests@gmail.com',
    'imap_password' => $_POST['imap_password'] ?? '',
    'imap_host' => '{imap.gmail.com:993/imap/ssl}INBOX',
    'email_subject' => 'Email Authentication Test - ' . date('Y-m-d H:i:s'),
    'wait_time' => 10, // Seconds to wait after sending before checking
];

// Admin header
$page->admin_header([
    'title' => 'Email Authentication Test',
    'menu-id' => 'email-tools',
    'readable_title' => 'Email Authentication Test'
]);

// Debug output
echo '<!-- DEBUG: REQUEST_METHOD = ' . ($_SERVER['REQUEST_METHOD'] ?? 'NOT SET') . ' -->';
echo '<!-- DEBUG: POST data: ' . htmlspecialchars(print_r($_POST, true)) . ' -->';
echo '<!-- DEBUG: REQUEST data: ' . htmlspecialchars(print_r($_REQUEST, true)) . ' -->';
echo '<!-- DEBUG: $run_test = ' . ($run_test ? 'true' : 'false') . ' -->';

// Handle web interface
if (!$run_test) {
    ?>
    
    <div class="row">
        <div class="col-12">
            
            <!-- Information Section -->
            <div class="alert alert-info">
                <h6 class="alert-heading mb-2">📧 Email Authentication Test Tool</h6>
                <p class="mb-2"><strong>What this tool does:</strong></p>
                <ul class="mb-2">
                    <li>Sends a test email using your EmailTemplate system</li>
                    <li>Connects to Gmail via IMAP to retrieve the sent email</li>
                    <li>Analyzes SPF, DKIM, and DMARC authentication headers</li>
                    <li>Provides detailed interpretation of authentication results</li>
                </ul>
            </div>
            
            <!-- Requirements Alert -->
            <div class="alert alert-warning">
                <h6 class="alert-heading mb-2">⚠️ Important Requirements:</h6>
                <ul class="mb-2">
                    <li>You need a <strong>Gmail App Password</strong>, not your regular password</li>
                    <li>IMAP must be enabled in your Gmail account settings</li>
                    <li>Two-factor authentication must be enabled on your Google account</li>
                </ul>
                <p class="mb-0"><a href="https://support.google.com/accounts/answer/185833" target="_blank" class="text-decoration-none">📖 Learn how to create a Gmail App Password</a></p>
            </div>
            
            <!-- Test Form -->
            <h5 class="mb-3">Run Authentication Test</h5>
            
            <?php
            $formwriter = LibraryFunctions::get_formwriter_object('email_test_form', 'admin');
            
            // No validation rules - just plain form
            $form_html = $formwriter->begin_form('email_test_form', 'POST', '/utils/email_send_test');
            echo '<!-- DEBUG FORM HTML: ' . htmlspecialchars($form_html) . ' -->';
            echo $form_html;
            
            echo '<div class="row g-3 mb-4">';
            echo '<div class="col-md-6">';
            echo $formwriter->textinput('Gmail address to send test email to', 'test_email', 'form-control', 100, 'joineryemailtests@gmail.com', 'email@gmail.com', 255, '');
            echo '</div>';
            
            echo '<div class="col-md-6">';
            echo $formwriter->textinput('Gmail username for IMAP access', 'imap_username', 'form-control', 100, 'joineryemailtests@gmail.com', 'email@gmail.com', 255, 'Usually the same as your email address');
            echo '</div>';
            
            echo '<div class="col-12">';
            echo $formwriter->passwordinput('Gmail App Password', 'imap_password', 'form-control', 100, '', '16-character app-specific password from Google Account settings');
            echo '</div>';
            
            echo '<div class="col-12">';
            echo $formwriter->start_buttons();
            echo $formwriter->new_form_button('<i class="fas fa-paper-plane"></i> Run Authentication Test', 'btn btn-primary');
            echo $formwriter->end_buttons();
            echo '</div>';
            echo '</div>';
            
            echo $formwriter->end_form();
            ?>
            
            <script>
            // Form submission with loading state
            document.addEventListener('DOMContentLoaded', function() {
                const form = document.getElementById('email_test_form');
                const submitBtn = form ? form.querySelector('button[type="submit"]') : null;
                
                if (form && submitBtn) {
                    form.addEventListener('submit', function(e) {
                        // Disable the submit button
                        submitBtn.disabled = true;
                        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Running Test...';
                        
                        // Show loading status
                        const loadingHtml = `
                            <div id="loading-status" class="alert alert-primary mt-3">
                                <div class="d-flex align-items-center">
                                    <div class="spinner-border spinner-border-sm me-3" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                    <div>
                                        <strong>Running Email Authentication Test...</strong><br>
                                        <small class="text-muted">This may take 10-15 seconds. Please wait while we send the email and analyze the results.</small>
                                    </div>
                                </div>
                            </div>
                        `;
                        
                        // Insert loading status after the form
                        form.insertAdjacentHTML('afterend', loadingHtml);
                        
                        // Scroll to loading indicator
                        document.getElementById('loading-status').scrollIntoView({ behavior: 'smooth' });
                    });
                }
            });
            </script>
            
            <!-- Setup Instructions -->
            <div class="card">
                <div class="card-header bg-body-tertiary">
                    <h6 class="mb-0">ℹ️ How to Setup Gmail App Password</h6>
                </div>
                <div class="card-body">
                    <ol>
                        <li>Go to your <a href="https://myaccount.google.com/" target="_blank" class="text-decoration-none">Google Account settings</a></li>
                        <li>Click "Security" in the left sidebar</li>
                        <li>Under "Signing in to Google," click "2-Step Verification" (must be enabled)</li>
                        <li>At the bottom, click "App passwords"</li>
                        <li>Select "Mail" and "Other (custom name)" - enter "Email Auth Test"</li>
                        <li>Copy the 16-character password and use it in the form above</li>
                    </ol>
                </div>
            </div>
            
        </div>
    </div>
    
    <?php
    $page->admin_footer();
    exit;
}

// If we get here, $run_test is true - process the form submission
echo '<div class="row"><div class="col-12">';
echo '<h4>Running Email Authentication Test...</h4>';

try {
    // Step 1: Send test email
    echo '<div class="alert alert-info"><strong>Step 1:</strong> Sending test email...</div>';
    
    $test_timestamp = date('Y-m-d H:i:s');
    $test_id = uniqid('test_', true);
    
    // Create email content
    $email_html = '
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
        <div style="background-color: #2c5aa0; color: white; padding: 20px; text-align: center;">
            <h1>Email Authentication Test</h1>
            <p>Test ID: ' . $test_id . '</p>
        </div>
        <div style="padding: 20px; background-color: #f8f9fa;">
            <h2>Test Results</h2>
            <p><strong>Timestamp:</strong> ' . $test_timestamp . '</p>
            <p><strong>From:</strong> ' . $settings->get_setting('defaultemail') . '</p>
            <p><strong>To:</strong> ' . htmlspecialchars($config['test_email']) . '</p>
            <p><strong>Subject:</strong> ' . htmlspecialchars($config['email_subject']) . '</p>
            
            <div style="margin: 20px 0; padding: 15px; background-color: white; border-left: 4px solid #007bff;">
                <h3>What to expect:</h3>
                <ul>
                    <li>This email tests your server\'s email authentication setup</li>
                    <li>The system will analyze SPF, DKIM, and DMARC headers</li>
                    <li>Results will be displayed in the admin panel</li>
                </ul>
            </div>
        </div>
        <div style="background-color: #6c757d; color: white; padding: 10px; text-align: center; font-size: 12px;">
            Generated by Email Authentication Test Tool
        </div>
    </div>';

    // Try to use EmailTemplate system
    $emailTemplate = EmailTemplate::CreateLegacyTemplate('default_outer_template', null);
    $emailTemplate->clear_recipients();
    $emailTemplate->add_recipient($config['test_email'], 'Test Recipient');
    
    // Set email properties directly
    $emailTemplate->email_subject = $config['email_subject'];
    $emailTemplate->email_html = $email_html;
    $emailTemplate->email_has_content = true;
    
    // Send the email
    $send_result = $emailTemplate->send(false); // false = don\'t check session
    
    if ($send_result) {
        echo '<div class="alert alert-success"><strong>✓ Email sent successfully!</strong></div>';
    } else {
        echo '<div class="alert alert-warning"><strong>⚠ Email sending completed</strong> (check logs for details)</div>';
    }
    
    // Step 2: Wait before checking
    echo '<div class="alert alert-info"><strong>Step 2:</strong> Waiting ' . $config['wait_time'] . ' seconds for email delivery...</div>';
    echo '<script>
        let countdown = ' . $config['wait_time'] . ';
        const timer = setInterval(function() {
            document.getElementById("countdown").textContent = countdown;
            countdown--;
            if (countdown < 0) {
                clearInterval(timer);
                document.getElementById("countdown-container").innerHTML = "<strong>✓ Wait complete!</strong>";
            }
        }, 1000);
    </script>';
    echo '<div id="countdown-container" class="alert alert-primary">Waiting <span id="countdown">' . $config['wait_time'] . '</span> seconds...</div>';
    
    // Force output to browser immediately
    if (ob_get_level()) {
        ob_flush();
    }
    flush();
    
    // Actually wait
    sleep($config['wait_time']);
    
    // Step 3: Connect to IMAP and retrieve email
    echo '<div class="alert alert-info"><strong>Step 3:</strong> Connecting to Gmail IMAP...</div>';
    
    if (!function_exists('imap_open')) {
        throw new Exception('IMAP extension is not installed. Please install php-imap extension.');
    }
    
    // Connect to Gmail IMAP
    $imap = imap_open($config['imap_host'], $config['imap_username'], $config['imap_password']);
    
    if (!$imap) {
        throw new Exception('Failed to connect to Gmail IMAP: ' . imap_last_error());
    }
    
    echo '<div class="alert alert-success"><strong>✓ Connected to Gmail IMAP successfully!</strong></div>';
    
    // Search for our test email
    $search_criteria = 'SUBJECT "' . $config['email_subject'] . '"';
    $emails = imap_search($imap, $search_criteria);
    
    if (!$emails) {
        echo '<div class="alert alert-warning"><strong>⚠ Test email not found</strong> in inbox. It may be in spam, or delivery may be delayed.</div>';
        echo '<div class="alert alert-info">Try checking your spam folder, or run the test again in a few minutes.</div>';
    } else {
        echo '<div class="alert alert-success"><strong>✓ Found test email!</strong> Analyzing headers...</div>';
        
        // Get the most recent matching email
        $latest_email = end($emails);
        $header = imap_fetchheader($imap, $latest_email);
        
        // Parse authentication headers
        $results = parseEmailAuthHeaders($header);
        
        // Display results
        displayAuthResults($results, $header, $settings);
    }
    
    imap_close($imap);
    
} catch (Exception $e) {
    echo '<div class="alert alert-danger"><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</div>';
    echo '<div class="alert alert-info">
        <strong>Troubleshooting tips:</strong>
        <ul>
            <li>Verify your Gmail App Password is correct (16 characters, no spaces)</li>
            <li>Ensure IMAP is enabled in your Gmail settings</li>
            <li>Check that 2-factor authentication is enabled on your Google account</li>
            <li>Try generating a new App Password if the current one doesn\'t work</li>
        </ul>
    </div>';
}

echo '</div></div>';
$page->admin_footer();

// Helper functions
function parseEmailAuthHeaders($header) {
    $results = [
        'spf' => ['status' => 'not found', 'result' => '', 'details' => '', 'domain' => ''],
        'dkim' => ['status' => 'not found', 'result' => '', 'details' => '', 'domain' => '', 'selector' => ''],
        'dmarc' => ['status' => 'not found', 'result' => '', 'details' => '', 'domain' => '']
    ];
    
    // Parse Authentication-Results header for SPF
    if (preg_match('/Authentication-Results:.*?spf=([^;\\s]+)([^\\r\\n]*)/i', $header, $matches)) {
        $results['spf']['status'] = 'found';
        $results['spf']['result'] = trim($matches[1]);
        $results['spf']['details'] = trim($matches[2]);
        
        // Extract SPF domain
        if (preg_match('/smtp\\.mailfrom=([^\\s;]+)/i', $matches[2], $domain_matches)) {
            $results['spf']['domain'] = trim($domain_matches[1]);
        }
    }
    
    // Parse Authentication-Results header for DKIM with more detail
    if (preg_match('/Authentication-Results:.*?dkim=([^;\\s]+)([^\\r\\n]*)/i', $header, $matches)) {
        $results['dkim']['status'] = 'found';
        $results['dkim']['result'] = trim($matches[1]);
        $results['dkim']['details'] = trim($matches[2]);
        
        // Extract DKIM domain (header.d)
        if (preg_match('/header\\.d=([^\\s;]+)/i', $matches[2], $domain_matches)) {
            $results['dkim']['domain'] = trim($domain_matches[1]);
        }
        
        // Extract DKIM selector (header.s)
        if (preg_match('/header\\.s=([^\\s;]+)/i', $matches[2], $selector_matches)) {
            $results['dkim']['selector'] = trim($selector_matches[1]);
        }
    }
    
    // Parse Authentication-Results header for DMARC
    if (preg_match('/Authentication-Results:.*?dmarc=([^;\\s]+)([^\\r\\n]*)/i', $header, $matches)) {
        $results['dmarc']['status'] = 'found';
        $results['dmarc']['result'] = trim($matches[1]);
        $results['dmarc']['details'] = trim($matches[2]);
        
        // Extract DMARC domain
        if (preg_match('/header\\.from=([^\\s;]+)/i', $matches[2], $domain_matches)) {
            $results['dmarc']['domain'] = trim($domain_matches[1]);
        }
    }
    
    return $results;
}

function displayAuthResults($results, $header, $settings) {
    // Extract domain from default email settings
    $defaultEmail = $settings->get_setting('defaultemail');
    $domain = '';
    if ($defaultEmail && strpos($defaultEmail, '@') !== false) {
        $domain = substr($defaultEmail, strpos($defaultEmail, '@') + 1);
    }
    if (empty($domain)) {
        $domain = 'yourdomain.com'; // fallback
    }
    echo '<div class="card mt-4">';
    echo '<div class="card-header bg-primary text-white">';
    echo '<h5 class="mb-0">📊 Authentication Results for <code>' . htmlspecialchars($domain) . '</code></h5>';
    echo '<small class="text-light">Testing email authentication from ' . htmlspecialchars($defaultEmail) . '</small>';
    echo '</div>';
    echo '<div class="card-body">';
    
    // SPF Results
    $spf = $results['spf'];
    echo '<div class="row mb-4">';
    echo '<div class="col-md-6">';
    echo '<h6>SPF (Sender Policy Framework)</h6>';
    
    if ($spf['status'] === 'found') {
        $badge_class = getSPFBadgeClass($spf['result']);
        echo '<span class="badge ' . $badge_class . ' mb-2">' . strtoupper($spf['result']) . '</span><br>';
        if ($spf['details']) {
            echo '<small class="text-muted">' . htmlspecialchars($spf['details']) . '</small><br>';
        }
        echo '<div class="mt-2">' . getSPFExplanation($spf['result']) . '</div>';
    } else {
        echo '<span class="badge bg-danger mb-2">NOT FOUND</span><br>';
        echo '<div class="alert alert-warning mt-2 p-2">';
        echo '<strong>⚠️ Missing SPF Record for ' . htmlspecialchars($domain) . '</strong><br>';
        echo 'Gmail could not verify that your server is authorized to send email for <strong>' . htmlspecialchars($domain) . '</strong>.';
        echo '</div>';
    }
    
    echo '</div>';
    echo '<div class="col-md-6">';
    echo '<div class="bg-light p-3 rounded">';
    echo '<h6 class="text-primary">What SPF Should Show:</h6>';
    echo '<ul class="mb-2" style="font-size: 0.9em;">';
    echo '<li><strong>PASS:</strong> Server is authorized to send</li>';
    echo '<li><strong>FAIL:</strong> Server is NOT authorized</li>';
    echo '<li><strong>SOFTFAIL:</strong> Server probably not authorized</li>';
    echo '<li><strong>NEUTRAL:</strong> No policy or inconclusive</li>';
    echo '</ul>';
    echo '<strong>Expected:</strong> <code>spf=pass</code><br>';
    echo '<strong>Fix:</strong> Add SPF record to <strong>' . htmlspecialchars($domain) . '</strong> DNS:<br>';
    echo '<code style="font-size: 0.8em;">v=spf1 ip4:YOUR_SERVER_IP ~all</code>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    
    // DKIM Results
    $dkim = $results['dkim'];
    echo '<div class="row mb-4">';
    echo '<div class="col-md-6">';
    echo '<h6>DKIM (DomainKeys Identified Mail)</h6>';
    
    if ($dkim['status'] === 'found') {
        $badge_class = getDKIMBadgeClass($dkim['result']);
        echo '<span class="badge ' . $badge_class . ' mb-2">' . strtoupper($dkim['result']) . '</span><br>';
        
        // Show DKIM domain and alignment
        if ($dkim['domain']) {
            $is_aligned = (strtolower($dkim['domain']) === strtolower($domain));
            echo '<div class="mt-2 mb-2">';
            echo '<strong>DKIM Signing Domain:</strong> <code>' . htmlspecialchars($dkim['domain']) . '</code> ';
            if ($is_aligned) {
                echo '<span class="badge bg-success">ALIGNED</span>';
            } else {
                echo '<span class="badge bg-warning">NOT ALIGNED</span>';
                echo '<br><small class="text-warning">⚠️ DKIM signed by <strong>' . htmlspecialchars($dkim['domain']) . '</strong> but email claims to be from <strong>' . htmlspecialchars($domain) . '</strong></small>';
            }
            echo '</div>';
            
            if ($dkim['selector']) {
                echo '<small class="text-muted">Selector: ' . htmlspecialchars($dkim['selector']) . '</small><br>';
            }
        }
        
        if ($dkim['details']) {
            echo '<small class="text-muted">' . htmlspecialchars($dkim['details']) . '</small><br>';
        }
        
        // Enhanced explanation based on alignment
        if ($dkim['domain'] && strtolower($dkim['domain']) !== strtolower($domain)) {
            echo '<div class="alert alert-warning mt-2 p-2">';
            echo '<strong>🔍 Domain Alignment Issue Detected</strong><br>';
            echo 'DKIM signature is valid, but it\'s from <strong>' . htmlspecialchars($dkim['domain']) . '</strong>, not your domain <strong>' . htmlspecialchars($domain) . '</strong>. ';
            echo 'This means you\'re sending through a third-party service (like Gmail) without proper domain authentication setup.';
            echo '</div>';
        } else {
            echo '<div class="mt-2">' . getDKIMExplanation($dkim['result']) . '</div>';
        }
    } else {
        echo '<span class="badge bg-danger mb-2">NOT FOUND</span><br>';
        echo '<div class="alert alert-warning mt-2 p-2">';
        echo '<strong>⚠️ Missing DKIM Signature</strong><br>';
        echo 'Your email from <strong>' . htmlspecialchars($domain) . '</strong> was not digitally signed, reducing trust and deliverability.';
        echo '</div>';
    }
    
    echo '</div>';
    echo '<div class="col-md-6">';
    echo '<div class="bg-light p-3 rounded">';
    echo '<h6 class="text-primary">What DKIM Should Show:</h6>';
    echo '<ul class="mb-2" style="font-size: 0.9em;">';
    echo '<li><strong>PASS:</strong> Signature valid, email unmodified</li>';
    echo '<li><strong>FAIL:</strong> Signature invalid or email modified</li>';
    echo '<li><strong>NEUTRAL:</strong> No signature found</li>';
    echo '<li><strong>TEMPERROR:</strong> Temporary DNS issue</li>';
    echo '<li><strong>PERMERROR:</strong> Permanent DNS/config issue</li>';
    echo '</ul>';
    echo '<strong>Expected:</strong> <code>dkim=pass</code><br>';
    echo '<strong>Fix:</strong> Configure DKIM signing on your mail server and publish public key in <strong>' . htmlspecialchars($domain) . '</strong> DNS';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    
    // DMARC Results
    $dmarc = $results['dmarc'];
    echo '<div class="row mb-4">';
    echo '<div class="col-md-6">';
    echo '<h6>DMARC (Domain-based Message Authentication)</h6>';
    
    if ($dmarc['status'] === 'found') {
        $badge_class = getDMARCBadgeClass($dmarc['result']);
        echo '<span class="badge ' . $badge_class . ' mb-2">' . strtoupper($dmarc['result']) . '</span><br>';
        if ($dmarc['details']) {
            echo '<small class="text-muted">' . htmlspecialchars($dmarc['details']) . '</small><br>';
        }
        echo '<div class="mt-2">' . getDMARCExplanation($dmarc['result']) . '</div>';
    } else {
        echo '<span class="badge bg-danger mb-2">NOT FOUND</span><br>';
        echo '<div class="alert alert-warning mt-2 p-2">';
        echo '<strong>⚠️ Missing DMARC Policy</strong><br>';
        echo 'No DMARC policy found for <strong>' . htmlspecialchars($domain) . '</strong>. Email receivers cannot determine how to handle authentication failures.';
        echo '</div>';
    }
    
    echo '</div>';
    echo '<div class="col-md-6">';
    echo '<div class="bg-light p-3 rounded">';
    echo '<h6 class="text-primary">What DMARC Should Show:</h6>';
    echo '<ul class="mb-2" style="font-size: 0.9em;">';
    echo '<li><strong>PASS:</strong> SPF or DKIM passed with alignment</li>';
    echo '<li><strong>FAIL:</strong> Both SPF and DKIM failed alignment</li>';
    echo '<li><strong>TEMPERROR:</strong> Temporary DNS issue</li>';
    echo '<li><strong>PERMERROR:</strong> Invalid DMARC record</li>';
    echo '</ul>';
    echo '<strong>Expected:</strong> <code>dmarc=pass</code><br>';
    echo '<strong>Fix:</strong> Add DMARC record to <code>_dmarc.' . htmlspecialchars($domain) . '</code>:<br>';
    echo '<code style="font-size: 0.8em;">v=DMARC1; p=none; rua=mailto:dmarc@' . htmlspecialchars($domain) . '</code>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    
    // Domain Alignment Analysis
    $alignment_issues = [];
    if ($dkim['status'] === 'found' && $dkim['domain'] && strtolower($dkim['domain']) !== strtolower($domain)) {
        $alignment_issues[] = 'DKIM signed by ' . $dkim['domain'] . ' instead of ' . $domain;
    }
    if ($spf['status'] === 'found' && $spf['domain'] && strtolower($spf['domain']) !== strtolower($domain)) {
        $alignment_issues[] = 'SPF checked for ' . $spf['domain'] . ' instead of ' . $domain;
    }
    
    if (!empty($alignment_issues)) {
        echo '<div class="alert alert-danger">';
        echo '<h6 class="alert-heading">🚨 Critical Domain Alignment Issues Detected</h6>';
        echo '<p><strong>Your email authentication reveals a security problem:</strong></p>';
        echo '<ul>';
        foreach ($alignment_issues as $issue) {
            echo '<li>' . htmlspecialchars($issue) . '</li>';
        }
        echo '</ul>';
        echo '<p class="mb-0"><strong>What this means:</strong> You\'re sending emails claiming to be from <strong>' . htmlspecialchars($domain) . '</strong> but using a third-party service (like Gmail) for actual delivery. Recipients can detect this mismatch, which may cause your emails to be marked as suspicious or spam.</p>';
        echo '</div>';
    }
    
    // Overall Summary
    echo '<div class="alert alert-info">';
    echo '<h6 class="alert-heading">📋 Summary & Next Steps for ' . htmlspecialchars($domain) . '</h6>';
    $passed = 0;
    $aligned_passed = 0;
    $total = 3;
    
    if ($spf['status'] === 'found' && strtolower($spf['result']) === 'pass') {
        $passed++;
        if (!$spf['domain'] || strtolower($spf['domain']) === strtolower($domain)) {
            $aligned_passed++;
        }
    }
    if ($dkim['status'] === 'found' && strtolower($dkim['result']) === 'pass') {
        $passed++;
        if (!$dkim['domain'] || strtolower($dkim['domain']) === strtolower($domain)) {
            $aligned_passed++;
        }
    }
    if ($dmarc['status'] === 'found' && strtolower($dmarc['result']) === 'pass') {
        $passed++;
        $aligned_passed++; // DMARC passing means alignment is OK
    }
    
    echo '<p><strong>Authentication Score:</strong> ' . $passed . '/' . $total . ' protocols passing for <strong>' . htmlspecialchars($domain) . '</strong></p>';
    echo '<p><strong>Domain Alignment Score:</strong> ' . $aligned_passed . '/' . $passed . ' passing protocols properly aligned</p>';
    
    if ($passed === 3) {
        echo '<p class="text-success mb-0"><strong>✅ Excellent!</strong> All email authentication protocols for <strong>' . htmlspecialchars($domain) . '</strong> are working correctly.</p>';
    } elseif ($passed >= 1) {
        echo '<p class="text-warning mb-2"><strong>⚠️ Partially Protected:</strong> <strong>' . htmlspecialchars($domain) . '</strong> has some authentication working, but improvements needed.</p>';
        echo '<p class="mb-0"><strong>Priority for ' . htmlspecialchars($domain) . ':</strong> ';
        if ($spf['status'] !== 'found' || strtolower($spf['result']) !== 'pass') echo 'Fix SPF DNS record first (easiest), ';
        if ($dmarc['status'] !== 'found' || strtolower($dmarc['result']) !== 'pass') echo 'then add DMARC DNS policy, ';
        if ($dkim['status'] !== 'found' || strtolower($dkim['result']) !== 'pass') echo 'finally configure DKIM signing on mail server';
        echo '</p>';
    } else {
        echo '<p class="text-danger mb-0"><strong>❌ Vulnerable:</strong> No email authentication found for <strong>' . htmlspecialchars($domain) . '</strong>. Emails from this domain may be rejected or marked as spam.</p>';
    }
    
    // Add specific DNS guidance
    echo '<hr>';
    echo '<h6 class="text-primary">🔧 Specific DNS Records Needed for ' . htmlspecialchars($domain) . ':</h6>';
    echo '<div class="row">';
    
    if ($spf['status'] !== 'found' || strtolower($spf['result']) !== 'pass') {
        echo '<div class="col-md-4 mb-2">';
        echo '<strong>SPF Record:</strong><br>';
        echo '<code style="font-size: 0.8em;">v=spf1 ip4:YOUR_SERVER_IP ~all</code><br>';
        echo '<small class="text-muted">Add as TXT record for ' . htmlspecialchars($domain) . '</small>';
        echo '</div>';
    }
    
    if ($dmarc['status'] !== 'found' || strtolower($dmarc['result']) !== 'pass') {
        echo '<div class="col-md-4 mb-2">';
        echo '<strong>DMARC Record:</strong><br>';
        echo '<code style="font-size: 0.8em;">v=DMARC1; p=none; rua=mailto:dmarc@' . htmlspecialchars($domain) . '</code><br>';
        echo '<small class="text-muted">Add as TXT record for _dmarc.' . htmlspecialchars($domain) . '</small>';
        echo '</div>';
    }
    
    if ($dkim['status'] !== 'found' || strtolower($dkim['result']) !== 'pass') {
        echo '<div class="col-md-4 mb-2">';
        echo '<strong>DKIM Setup:</strong><br>';
        echo '<small>1. Configure mail server to sign emails<br>';
        echo '2. Publish public key as TXT record:<br>';
        echo '<code style="font-size: 0.75em;">selector._domainkey.' . htmlspecialchars($domain) . '</code></small>';
        echo '</div>';
    }
    
    echo '</div>';
    echo '</div>';
    
    echo '</div></div>';
    
    // Show raw headers for debugging
    echo '<div class="card mt-3">';
    echo '<div class="card-header">';
    echo '<h6 class="mb-0">🔍 Raw Authentication Headers</h6>';
    echo '<small class="text-muted">Look for "Authentication-Results" lines in the headers below</small>';
    echo '</div>';
    echo '<div class="card-body">';
    
    // Extract and highlight authentication-related headers
    $auth_headers = extractAuthHeaders($header);
    if ($auth_headers) {
        echo '<div class="alert alert-secondary p-2 mb-3">';
        echo '<strong>Found Authentication Headers:</strong><br>';
        echo '<pre style="font-size: 0.9em; margin: 0;">' . htmlspecialchars($auth_headers) . '</pre>';
        echo '</div>';
    }
    
    echo '<details>';
    echo '<summary class="btn btn-sm btn-outline-secondary">Show All Headers</summary>';
    echo '<pre style="font-size: 11px; max-height: 300px; overflow-y: auto; margin-top: 10px;">' . htmlspecialchars($header) . '</pre>';
    echo '</details>';
    echo '</div></div>';
}

function getSPFBadgeClass($result) {
    switch (strtolower($result)) {
        case 'pass': return 'bg-success';
        case 'fail': return 'bg-danger';
        case 'softfail': case 'neutral': return 'bg-warning';
        default: return 'bg-secondary';
    }
}

function getDKIMBadgeClass($result) {
    switch (strtolower($result)) {
        case 'pass': return 'bg-success';
        case 'fail': return 'bg-danger';
        case 'neutral': case 'temperror': return 'bg-warning';
        case 'permerror': return 'bg-danger';
        default: return 'bg-secondary';
    }
}

function getDMARCBadgeClass($result) {
    switch (strtolower($result)) {
        case 'pass': return 'bg-success';
        case 'fail': return 'bg-danger';
        case 'temperror': return 'bg-warning';
        case 'permerror': return 'bg-danger';
        default: return 'bg-secondary';
    }
}

function getSPFExplanation($result) {
    switch (strtolower($result)) {
        case 'pass':
            return '<div class="alert alert-success p-2">✅ Your server is authorized to send email for this domain.</div>';
        case 'fail':
            return '<div class="alert alert-danger p-2">❌ Your server is NOT authorized. Recipients may reject your emails.</div>';
        case 'softfail':
            return '<div class="alert alert-warning p-2">⚠️ Your server is probably not authorized. Emails may be marked suspicious.</div>';
        case 'neutral':
            return '<div class="alert alert-info p-2">ℹ️ SPF record exists but doesn\'t specify a policy for your server.</div>';
        default:
            return '<div class="alert alert-secondary p-2">Unknown SPF result: ' . htmlspecialchars($result) . '</div>';
    }
}

function getDKIMExplanation($result) {
    switch (strtolower($result)) {
        case 'pass':
            return '<div class="alert alert-success p-2">✅ Email signature is valid and email content is unmodified.</div>';
        case 'fail':
            return '<div class="alert alert-danger p-2">❌ Email signature is invalid or email content was modified in transit.</div>';
        case 'neutral':
            return '<div class="alert alert-info p-2">ℹ️ No DKIM signature found on this email.</div>';
        case 'temperror':
            return '<div class="alert alert-warning p-2">⚠️ Temporary DNS error prevented DKIM verification.</div>';
        case 'permerror':
            return '<div class="alert alert-danger p-2">❌ Permanent DKIM configuration error detected.</div>';
        default:
            return '<div class="alert alert-secondary p-2">Unknown DKIM result: ' . htmlspecialchars($result) . '</div>';
    }
}

function getDMARCExplanation($result) {
    switch (strtolower($result)) {
        case 'pass':
            return '<div class="alert alert-success p-2">✅ Email passed DMARC alignment checks (SPF or DKIM aligned with From domain).</div>';
        case 'fail':
            return '<div class="alert alert-danger p-2">❌ Email failed DMARC alignment. Action depends on domain policy (none/quarantine/reject).</div>';
        case 'temperror':
            return '<div class="alert alert-warning p-2">⚠️ Temporary DNS error prevented DMARC policy lookup.</div>';
        case 'permerror':
            return '<div class="alert alert-danger p-2">❌ DMARC record is malformed or invalid.</div>';
        default:
            return '<div class="alert alert-secondary p-2">Unknown DMARC result: ' . htmlspecialchars($result) . '</div>';
    }
}

function extractAuthHeaders($header) {
    $lines = explode("\n", $header);
    $auth_lines = [];
    
    foreach ($lines as $line) {
        if (stripos($line, 'Authentication-Results:') !== false) {
            $auth_lines[] = trim($line);
            // Get continuation lines
            $i = array_search($line, $lines);
            for ($j = $i + 1; $j < count($lines); $j++) {
                if (preg_match('/^\s+/', $lines[$j]) && !empty(trim($lines[$j]))) {
                    $auth_lines[] = trim($lines[$j]);
                } else {
                    break;
                }
            }
        }
    }
    
    return empty($auth_lines) ? null : implode("\n", $auth_lines);
}
