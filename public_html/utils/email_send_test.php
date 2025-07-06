<?php
/**
 * Email Authentication Test Script
 * 
 * This script sends a test email using the EmailTemplate class and then
 * connects via IMAP to analyze the SPF, DKIM, and DMARC authentication results.
 * 
 * Usage: php email_send_test.php
 */

// Determine the base path based on how the script is being run
if (defined('GLOBALVARS_INCLUDED')) {
    // Running through serve.php - includes already loaded
} else {
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

// Check if running from CLI or web
$is_cli = (php_sapi_name() === 'cli');

// If running from web, check login requirement
if (!$is_cli) {
    require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');

    $session = SessionControl::get_instance();
    $session->check_permission(5);
    $session->set_return();
}

// Configuration
$config = [
    'test_email' => '',  // Gmail address to send test email to
    'imap_username' => '', // Gmail username (usually same as email)
    'imap_password' => '', // Gmail app password (not regular password)
    'imap_host' => '{imap.gmail.com:993/imap/ssl}INBOX',
    'email_subject' => 'Email Authentication Test - ' . date('Y-m-d H:i:s'),
    'wait_time' => 10, // Seconds to wait after sending before checking
];

// Handle web interface
if (!$is_cli) {
    // Start output buffering to capture all output
    ob_start();
    
    // Check if form was submitted
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $config['test_email'] = $_POST['test_email'] ?? '';
        $config['imap_username'] = $_POST['imap_username'] ?? '';
        $config['imap_password'] = $_POST['imap_password'] ?? '';
        
        if (empty($config['test_email']) || empty($config['imap_username']) || empty($config['imap_password'])) {
            die("Error: All fields are required.");
        }
    } else {
        // Show HTML form
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Authentication Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background-color: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { text-align: center; margin-bottom: 30px; }
        .domain { color: #2c5aa0; font-size: 24px; font-weight: bold; }
        .section { margin-bottom: 30px; border: 1px solid #ddd; border-radius: 5px; overflow: hidden; }
        .section-header { background: #2c5aa0; color: white; padding: 15px; font-size: 18px; font-weight: bold; }
        .section-content { padding: 20px; }
        .status-good { background: #d4edda; color: #155724; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .status-warning { background: #fff3cd; color: #856404; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .status-error { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .status-info { background: #d1ecf1; color: #0c5460; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .form-section { background: #e3f2fd; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
        .form-section input[type="text"], .form-section input[type="password"] { padding: 10px; font-size: 16px; border: 1px solid #ddd; border-radius: 4px; width: 100%; margin: 5px 0 15px 0; box-sizing: border-box; }
        .form-section label { font-size: 14px; color: #333; font-weight: bold; display: block; margin-bottom: 5px; }
        .form-section button { padding: 12px 24px; font-size: 16px; background: #2c5aa0; color: white; border: none; border-radius: 4px; cursor: pointer; margin-top: 10px; }
        .form-section button:hover { background: #1e3f73; }
        .requirements { margin: 15px 0; }
        .requirements ul { margin: 10px 0; padding-left: 20px; }
        .requirements li { margin: 5px 0; color: #666; }
        .help-link { color: #2c5aa0; text-decoration: none; }
        .help-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Email Authentication Test Tool</h1>
            <div class="domain">Send Test Email & Analyze Authentication Results</div>
        </div>
        
        <div class="section">
            <div class="section-header">📧 Test Email & Authentication Analysis</div>
            <div class="section-content">
                <div class="status-info">
                    <strong>What this tool does:</strong><br>
                    • Sends a test email using your EmailTemplate system<br>
                    • Connects to Gmail via IMAP to retrieve the sent email<br>
                    • Analyzes SPF, DKIM, and DMARC authentication headers<br>
                    • Provides detailed interpretation of authentication results
                </div>
                
                <div class="status-warning">
                    <strong>⚠️ Important Requirements:</strong>
                    <div class="requirements">
                        <ul>
                            <li>You need a <strong>Gmail App Password</strong>, not your regular password</li>
                            <li>IMAP must be enabled in your Gmail account settings</li>
                            <li>Two-factor authentication must be enabled on your Google account</li>
                        </ul>
                        <p><a href="https://support.google.com/accounts/answer/185833" target="_blank" class="help-link">📖 Learn how to create a Gmail App Password</a></p>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="form-section">
            <form method="POST">
                <label for="test_email">Gmail address to send test email to:</label>
                <input type="text" id="test_email" name="test_email" value="joineryemailtests@gmail.com" required>
                
                <label for="imap_username">Gmail username for IMAP access:</label>
                <input type="text" id="imap_username" name="imap_username" value="joineryemailtests@gmail.com" required>
                <small style="color: #666;">Usually the same as your email address</small>
                
                <label for="imap_password">Gmail App Password:</label>
                <input type="password" id="imap_password" name="imap_password" required>
                <small style="color: #666;">16-character app-specific password from Google Account settings</small>
                
                <button type="submit">🚀 Run Authentication Test</button>
            </form>
        </div>
        
        <div class="section">
            <div class="section-header">ℹ️ How to Setup Gmail App Password</div>
            <div class="section-content">
                <ol style="color: #666; line-height: 1.6;">
                    <li>Go to your <a href="https://myaccount.google.com/" target="_blank" class="help-link">Google Account settings</a></li>
                    <li>Click "Security" in the left sidebar</li>
                    <li>Under "Signing in to Google," click "2-Step Verification" (must be enabled)</li>
                    <li>At the bottom, click "App passwords"</li>
                    <li>Select "Mail" and "Other (custom name)" - enter "Email Auth Test"</li>
                    <li>Copy the 16-character password and use it in the form above</li>
                </ol>
            </div>
        </div>
    </div>
</body>
</html>
        <?php
        exit;
    }
}

// CLI prompts
if ($is_cli) {
    if (empty($config['test_email'])) {
        echo "Gmail address to send test email to: ";
        $config['test_email'] = trim(fgets(STDIN));
    }

    if (empty($config['imap_username'])) {
        echo "Gmail username for IMAP access (usually same as email): ";
        $config['imap_username'] = trim(fgets(STDIN));
    }

    if (empty($config['imap_password'])) {
        echo "Gmail app password (NOT regular password - see https://support.google.com/accounts/answer/185833): ";
        $config['imap_password'] = trim(fgets(STDIN));
    }
}

// Step 1: Send test email
echo "\n=== STEP 1: Sending Test Email ===\n";

try {
    // Create test content values
    $test_timestamp = date('Y-m-d H:i:s');
    $test_id = uniqid('test_', true);
    $server_info = php_uname();
    $php_version = phpversion();
    
    // First, let's try to use the default_outer_template which should exist
    $emailTemplate = new EmailTemplate('default_outer_template');
    
    // Clear any default recipients and add our test recipient
    $emailTemplate->clear_recipients();
    $emailTemplate->add_recipient($config['test_email'], 'Test Recipient');
    
    // Create the email content directly using public properties
    $email_html = '<div class="header" style="background-color: #2c5aa0; color: white; padding: 20px; text-align: center;">
        <h1>Email Authentication Test</h1>
    </div>
    <div class="content" style="padding: 20px; background-color: #f5f5f5;">
        <p>This is an automated test email to verify SPF, DKIM, and DMARC authentication settings.</p>
        
        <table class="info-table" style="width: 100%; border-collapse: collapse; margin: 20px 0;">
            <tr>
                <td style="padding: 10px; border-bottom: 1px solid #ddd; font-weight: bold; width: 150px;">Test Timestamp:</td>
                <td style="padding: 10px; border-bottom: 1px solid #ddd;">' . $test_timestamp . '</td>
            </tr>
            <tr>
                <td style="padding: 10px; border-bottom: 1px solid #ddd; font-weight: bold;">Test ID:</td>
                <td style="padding: 10px; border-bottom: 1px solid #ddd;">' . $test_id . '</td>
            </tr>
            <tr>
                <td style="padding: 10px; border-bottom: 1px solid #ddd; font-weight: bold;">Server Info:</td>
                <td style="padding: 10px; border-bottom: 1px solid #ddd;">' . htmlspecialchars($server_info) . '</td>
            </tr>
            <tr>
                <td style="padding: 10px; border-bottom: 1px solid #ddd; font-weight: bold;">PHP Version:</td>
                <td style="padding: 10px; border-bottom: 1px solid #ddd;">' . $php_version . '</td>
            </tr>
        </table>
        
        <p>This email will be analyzed via IMAP to check the authentication results headers added by the receiving mail server.</p>
        
        <p style="color: #666; font-size: 12px; margin-top: 30px;">
            This is an automated test message. Please do not reply.
        </p>
    </div>';
    
    $email_text = "Email Authentication Test\n\n";
    $email_text .= "This is an automated test email to verify SPF, DKIM, and DMARC authentication settings.\n\n";
    $email_text .= "Test Timestamp: $test_timestamp\n";
    $email_text .= "Test ID: $test_id\n";
    $email_text .= "Server Info: $server_info\n";
    $email_text .= "PHP Version: $php_version\n\n";
    $email_text .= "This email will be analyzed via IMAP to check the authentication results headers added by the receiving mail server.\n\n";
    $email_text .= "This is an automated test message. Please do not reply.";
    
    // Set the email content directly using public properties
    $emailTemplate->email_subject = $config['email_subject'];
    $emailTemplate->email_html = $email_html;
    $emailTemplate->email_text = $email_text;
    $emailTemplate->email_has_content = true;
    
    // Send the email
    $send_result = $emailTemplate->send(false);
    
    if ($send_result) {
        echo "✓ Email sent successfully to: " . $config['test_email'] . "\n";
        echo "  Subject: " . $config['email_subject'] . "\n";
        echo "  From: " . $emailTemplate->email_from . " (" . $emailTemplate->email_from_name . ")\n";
        echo "  Test ID: " . $test_id . "\n";
    } else {
        throw new Exception("Failed to send email");
    }
    
} catch (Exception $e) {
    $error_message = $e->getMessage();
    
    // Check for Mailgun version compatibility issues
    if (strpos($error_message, 'Mailgun\HttpClient\HttpClientConfigurator') !== false || 
        strpos($error_message, 'Argument #1 ($configurator) must be of type') !== false) {
        
        echo "\n❌ MAILGUN VERSION COMPATIBILITY ERROR DETECTED\n";
        echo "==============================================\n\n";
        echo "The error indicates a mismatch between your Mailgun PHP library version\n";
        echo "and the configuration in your EmailTemplate system.\n\n";
        
        // Get current settings
        $settings = Globalvars::get_instance();
        $current_version = $settings->get_setting('mailgun_version', true, true);
        $api_key_set = $settings->get_setting('mailgun_api_key', true, true) ? 'SET' : 'NOT SET';
        $domain = $settings->get_setting('mailgun_domain', true, true);
        
        echo "Current Mailgun Configuration:\n";
        echo "- mailgun_version setting: " . ($current_version ? $current_version : 'NOT SET (defaults to newer API)') . "\n";
        echo "- mailgun_api_key: $api_key_set\n";
        echo "- mailgun_domain: " . ($domain ? $domain : 'NOT SET') . "\n\n";
        
        echo "To fix this issue, choose ONE of the following solutions:\n\n";
        
        echo "SOLUTION 1 (Recommended): Update Mailgun version setting\n";
        echo "---------------------------------------------------------\n";
        echo "Set mailgun_version to 3 in your system settings:\n";
        echo "• In database: UPDATE stg_settings SET stg_value='3' WHERE stg_name='mailgun_version';\n";
        echo "• Or in your admin panel: Set 'mailgun_version' setting to '3'\n\n";
        
        echo "SOLUTION 2: Check installed Mailgun library version\n";
        echo "----------------------------------------------------\n";
        echo "Run: composer show mailgun/mailgun-php\n";
        echo "If version is 3.0+, use Solution 1 above.\n";
        echo "If you need the old API, downgrade: composer require mailgun/mailgun-php:^2.0\n\n";
        
        echo "SOLUTION 3: Alternative - Use SMTP instead\n";
        echo "-------------------------------------------\n";
        echo "Temporarily disable Mailgun by removing/renaming mailgun_api_key setting\n";
        echo "This will fall back to SMTP sending.\n\n";
        
        echo "For more details about Mailgun PHP library versions:\n";
        echo "https://github.com/mailgun/mailgun-php\n\n";
        
        echo "Original error: " . $error_message . "\n";
        
    } else {
        echo "ERROR sending email: " . $error_message . "\n";
        
        // Check for other common email sending issues
        if (strpos($error_message, 'Could not find the template') !== false) {
            echo "\nTIP: This error means the email template doesn't exist in the database.\n";
            echo "The script is trying to use 'default_outer_template' which should exist in your system.\n";
        } elseif (strpos($error_message, 'SMTP') !== false) {
            echo "\nTIP: This appears to be an SMTP configuration issue.\n";
            echo "Check your SMTP settings in the system configuration.\n";
        } elseif (strpos($error_message, 'authentication') !== false) {
            echo "\nTIP: This appears to be an authentication issue.\n";
            echo "Check your email service credentials (SMTP, Mailgun, etc.).\n";
        }
    }
    
    exit(1);
}

// Step 2: Wait for email to be delivered
echo "\n=== STEP 2: Waiting for Email Delivery ===\n";
echo "Waiting " . $config['wait_time'] . " seconds for email to be delivered...\n";
sleep($config['wait_time']);

// Step 3: Connect to Gmail via IMAP
echo "\n=== STEP 3: Connecting to Gmail via IMAP ===\n";

$imap = @imap_open($config['imap_host'], $config['imap_username'], $config['imap_password']);

if (!$imap) {
    die("ERROR: Cannot connect to Gmail IMAP: " . imap_last_error() . "\n");
}

echo "✓ Connected to Gmail IMAP successfully\n";

// Step 4: Search for our test email
echo "\n=== STEP 4: Searching for Test Email ===\n";

// Search for emails with our unique subject
$search_criteria = 'SUBJECT "' . $config['email_subject'] . '"';
$emails = imap_search($imap, $search_criteria);

if (!$emails) {
    imap_close($imap);
    die("ERROR: Could not find test email. It may not have been delivered yet.\n");
}

// Get the most recent email (last in array)
$email_number = end($emails);
echo "✓ Found test email (Message #$email_number)\n";

// Step 5: Analyze email headers
echo "\n=== STEP 5: Analyzing Email Authentication Headers ===\n";

// Get full headers
$headers = imap_fetchheader($imap, $email_number);

// Parse headers into array
$header_lines = explode("\n", $headers);
$parsed_headers = [];
$current_header = '';

foreach ($header_lines as $line) {
    if (preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/', $line, $matches)) {
        if ($current_header) {
            $parsed_headers[] = $current_header;
        }
        $current_header = ['name' => $matches[1], 'value' => $matches[2]];
    } elseif (preg_match('/^\s+(.*)$/', $line, $matches) && $current_header) {
        $current_header['value'] .= ' ' . $matches[1];
    }
}
if ($current_header) {
    $parsed_headers[] = $current_header;
}

// Authentication results
$auth_results = [
    'spf' => ['status' => 'not_found', 'details' => ''],
    'dkim' => ['status' => 'not_found', 'details' => ''],
    'dmarc' => ['status' => 'not_found', 'details' => ''],
    'arc' => ['status' => 'not_found', 'details' => ''],
];

// Look for authentication results headers
foreach ($parsed_headers as $header) {
    $name = strtolower($header['name']);
    $value = $header['value'];
    
    // Authentication-Results header (primary source)
    if ($name === 'authentication-results') {
        echo "\nAuthentication-Results header found:\n";
        echo "  " . $value . "\n";
        
        // Parse SPF
        if (preg_match('/spf=(\w+)(?:\s+\(([^)]+)\))?/i', $value, $matches)) {
            $auth_results['spf']['status'] = $matches[1];
            $auth_results['spf']['details'] = isset($matches[2]) ? $matches[2] : '';
        }
        
        // Parse DKIM
        if (preg_match('/dkim=(\w+)(?:\s+\(([^)]+)\))?/i', $value, $matches)) {
            $auth_results['dkim']['status'] = $matches[1];
            $auth_results['dkim']['details'] = isset($matches[2]) ? $matches[2] : '';
        }
        
        // Parse DMARC
        if (preg_match('/dmarc=(\w+)(?:\s+\(([^)]+)\))?/i', $value, $matches)) {
            $auth_results['dmarc']['status'] = $matches[1];
            $auth_results['dmarc']['details'] = isset($matches[2]) ? $matches[2] : '';
        }
    }
    
    // ARC-Authentication-Results
    if ($name === 'arc-authentication-results') {
        echo "\nARC-Authentication-Results header found:\n";
        echo "  " . $value . "\n";
        $auth_results['arc']['status'] = 'present';
        $auth_results['arc']['details'] = $value;
    }
    
    // Received-SPF header
    if ($name === 'received-spf') {
        if (preg_match('/^(\w+)/i', $value, $matches)) {
            if ($auth_results['spf']['status'] === 'not_found') {
                $auth_results['spf']['status'] = $matches[1];
                $auth_results['spf']['details'] = $value;
            }
        }
    }
    
    // DKIM-Signature header
    if ($name === 'dkim-signature') {
        if ($auth_results['dkim']['status'] === 'not_found') {
            $auth_results['dkim']['status'] = 'signature_present';
            $auth_results['dkim']['details'] = 'DKIM signature found in headers';
        }
    }
}

// Step 6: Display Results
echo "\n=== AUTHENTICATION ANALYSIS RESULTS ===\n\n";

// SPF Results
echo "SPF (Sender Policy Framework):\n";
echo "  Status: " . $auth_results['spf']['status'] . "\n";
if ($auth_results['spf']['details']) {
    echo "  Details: " . $auth_results['spf']['details'] . "\n";
}
echo "  Interpretation: ";
switch (strtolower($auth_results['spf']['status'])) {
    case 'pass':
        echo "✓ SPF check passed - sending IP is authorized\n";
        break;
    case 'fail':
        echo "✗ SPF check failed - sending IP is NOT authorized\n";
        break;
    case 'softfail':
        echo "⚠ SPF soft fail - sending IP is questionable\n";
        break;
    case 'neutral':
        echo "◯ SPF neutral - no policy statement\n";
        break;
    case 'temperror':
        echo "⚠ SPF temporary error - DNS issue\n";
        break;
    case 'permerror':
        echo "✗ SPF permanent error - invalid SPF record\n";
        break;
    case 'not_found':
        echo "? No SPF results found in headers\n";
        break;
    default:
        echo "? Unknown SPF status\n";
}

echo "\nDKIM (DomainKeys Identified Mail):\n";
echo "  Status: " . $auth_results['dkim']['status'] . "\n";
if ($auth_results['dkim']['details']) {
    echo "  Details: " . $auth_results['dkim']['details'] . "\n";
}
echo "  Interpretation: ";
switch (strtolower($auth_results['dkim']['status'])) {
    case 'pass':
        echo "✓ DKIM signature verified successfully\n";
        break;
    case 'fail':
        echo "✗ DKIM signature verification failed\n";
        break;
    case 'temperror':
        echo "⚠ DKIM temporary error\n";
        break;
    case 'permerror':
        echo "✗ DKIM permanent error\n";
        break;
    case 'signature_present':
        echo "◯ DKIM signature present but verification result not found\n";
        break;
    case 'not_found':
        echo "? No DKIM results found in headers\n";
        break;
    default:
        echo "? Unknown DKIM status\n";
}

echo "\nDMARC (Domain-based Message Authentication):\n";
echo "  Status: " . $auth_results['dmarc']['status'] . "\n";
if ($auth_results['dmarc']['details']) {
    echo "  Details: " . $auth_results['dmarc']['details'] . "\n";
}
echo "  Interpretation: ";
switch (strtolower($auth_results['dmarc']['status'])) {
    case 'pass':
        echo "✓ DMARC check passed - aligned and authenticated\n";
        break;
    case 'fail':
        echo "✗ DMARC check failed\n";
        break;
    case 'temperror':
        echo "⚠ DMARC temporary error\n";
        break;
    case 'permerror':
        echo "✗ DMARC permanent error\n";
        break;
    case 'not_found':
        echo "? No DMARC results found in headers\n";
        break;
    default:
        echo "? Unknown DMARC status\n";
}

echo "\nARC (Authenticated Received Chain):\n";
echo "  Status: " . $auth_results['arc']['status'] . "\n";
if ($auth_results['arc']['status'] === 'present') {
    echo "  ✓ ARC headers present - email went through forwarding/mailing list\n";
} else {
    echo "  ◯ No ARC headers found\n";
}

// Step 7: Additional Header Analysis
echo "\n=== ADDITIONAL HEADER INFORMATION ===\n";

// Look for specific headers
$headers_to_check = [
    'return-path' => 'Return Path',
    'from' => 'From',
    'sender' => 'Sender',
    'reply-to' => 'Reply-To',
    'x-originating-ip' => 'Originating IP',
    'x-mailer' => 'Mail Client',
    'message-id' => 'Message ID',
];

foreach ($parsed_headers as $header) {
    $name = strtolower($header['name']);
    if (isset($headers_to_check[$name])) {
        echo $headers_to_check[$name] . ": " . $header['value'] . "\n";
    }
}

// Close IMAP connection
imap_close($imap);

echo "\n=== TEST COMPLETE ===\n";
echo "\nSummary:\n";
echo "- Email sent from: " . $emailTemplate->email_from . "\n";
echo "- Email sent to: " . $config['test_email'] . "\n";
echo "- SPF: " . $auth_results['spf']['status'] . "\n";
echo "- DKIM: " . $auth_results['dkim']['status'] . "\n";
echo "- DMARC: " . $auth_results['dmarc']['status'] . "\n";

// Provide recommendations
echo "\nRecommendations:\n";

if ($auth_results['spf']['status'] !== 'pass') {
    echo "⚠ SPF is not passing. Check your SPF record includes the sending IP/server.\n";
}

if ($auth_results['dkim']['status'] !== 'pass') {
    echo "⚠ DKIM is not passing. Ensure DKIM signing is enabled and keys are published.\n";
}

if ($auth_results['dmarc']['status'] !== 'pass') {
    echo "⚠ DMARC is not passing. This usually means SPF or DKIM failed, or alignment issues.\n";
}

if ($auth_results['spf']['status'] === 'pass' && 
    $auth_results['dkim']['status'] === 'pass' && 
    $auth_results['dmarc']['status'] === 'pass') {
    echo "✓ All authentication checks are passing! Your email configuration is properly set up.\n";
}

// Format output for web display
if (!$is_cli) {
    $output = ob_get_clean();
    
    // Parse the output to create a more structured web display
    $lines = explode("\n", $output);
    $sections = [];
    $current_section = null;
    $current_content = [];
    
    foreach ($lines as $line) {
        if (strpos($line, '=== STEP') !== false) {
            if ($current_section) {
                $sections[] = ['title' => $current_section, 'content' => implode("\n", $current_content)];
            }
            $current_section = trim(str_replace(['===', 'STEP'], ['', ''], $line));
            $current_content = [];
        } elseif (strpos($line, '=== ') !== false) {
            if ($current_section) {
                $sections[] = ['title' => $current_section, 'content' => implode("\n", $current_content)];
            }
            $current_section = trim(str_replace('===', '', $line));
            $current_content = [];
        } else {
            $current_content[] = $line;
        }
    }
    if ($current_section) {
        $sections[] = ['title' => $current_section, 'content' => implode("\n", $current_content)];
    }
    
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Authentication Test Results</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background-color: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { text-align: center; margin-bottom: 30px; }
        .domain { color: #2c5aa0; font-size: 24px; font-weight: bold; }
        .section { margin-bottom: 20px; border: 1px solid #ddd; border-radius: 5px; overflow: hidden; }
        .section-header { background: #2c5aa0; color: white; padding: 15px; font-size: 16px; font-weight: bold; }
        .section-content { padding: 20px; }
        .status-good { background: #d4edda; color: #155724; padding: 8px; border-radius: 4px; margin: 5px 0; display: inline-block; }
        .status-warning { background: #fff3cd; color: #856404; padding: 8px; border-radius: 4px; margin: 5px 0; display: inline-block; }
        .status-error { background: #f8d7da; color: #721c24; padding: 8px; border-radius: 4px; margin: 5px 0; display: inline-block; }
        .status-info { background: #d1ecf1; color: #0c5460; padding: 8px; border-radius: 4px; margin: 5px 0; display: inline-block; }
        .output-block { background: #f8f9fa; padding: 15px; border-radius: 4px; font-family: monospace; white-space: pre-wrap; margin: 10px 0; border-left: 4px solid #007bff; }
        .back-link { display: inline-block; margin: 20px 0; padding: 10px 20px; background: #2c5aa0; color: white; text-decoration: none; border-radius: 4px; }
        .back-link:hover { background: #1e3f73; }
        .success-icon { color: #28a745; }
        .warning-icon { color: #ffc107; }
        .error-icon { color: #dc3545; }
        .info-icon { color: #17a2b8; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Email Authentication Test Results</h1>
            <div class="domain">📧 Test Complete - Analysis Report</div>
        </div>
        
        <?php foreach ($sections as $section): ?>
        <div class="section">
            <div class="section-header">
                <?php 
                $icon = '📝';
                if (strpos($section['title'], 'Sending') !== false) $icon = '📤';
                elseif (strpos($section['title'], 'Waiting') !== false) $icon = '⏱️';
                elseif (strpos($section['title'], 'Connecting') !== false) $icon = '🔗';
                elseif (strpos($section['title'], 'Searching') !== false) $icon = '🔍';
                elseif (strpos($section['title'], 'Analyzing') !== false) $icon = '🔬';
                elseif (strpos($section['title'], 'RESULTS') !== false) $icon = '📊';
                elseif (strpos($section['title'], 'ADDITIONAL') !== false) $icon = 'ℹ️';
                elseif (strpos($section['title'], 'TEST COMPLETE') !== false) $icon = '✅';
                echo $icon . ' ' . htmlspecialchars($section['title']);
                ?>
            </div>
            <div class="section-content">
                <?php 
                $content = $section['content'];
                
                // Apply color coding to specific patterns
                $content = preg_replace('/✓([^\n]+)/', '<span class="status-good">✓$1</span>', $content);
                $content = preg_replace('/⚠([^\n]+)/', '<span class="status-warning">⚠$1</span>', $content);
                $content = preg_replace('/✗([^\n]+)/', '<span class="status-error">✗$1</span>', $content);
                $content = preg_replace('/◯([^\n]+)/', '<span class="status-info">◯$1</span>', $content);
                
                // Handle ERROR messages
                if (strpos($content, 'ERROR') !== false || strpos($content, 'MAILGUN VERSION COMPATIBILITY') !== false) {
                    echo '<div class="status-error">' . nl2br(htmlspecialchars($content)) . '</div>';
                } else {
                    echo '<div class="output-block">' . $content . '</div>';
                }
                ?>
            </div>
        </div>
        <?php endforeach; ?>
        
        <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="back-link">← Run Another Test</a>
    </div>
</body>
</html>
    <?php
}