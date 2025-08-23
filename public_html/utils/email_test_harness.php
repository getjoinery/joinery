<?php
/**
 * TEMPORARY TEST HARNESS - NOT FOR PRODUCTION USE
 * 
 * This is temporary scaffolding for testing the email system during refactoring.
 * This file will be DEPRECATED and REMOVED once proper tests are in place.
 * DO NOT use this in production code or depend on it for any functionality.
 * 
 * This file is only meant to provide a safe entry point for testing email
 * functionality during the refactoring process. Once the email testing suite
 * in /tests/ is complete, this file should be deleted.
 * 
 * @deprecated Will be removed after email testing suite is complete
 * @temporary This is temporary scaffolding only
 */

require_once(__DIR__ . '/../includes/PathHelper.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/EmailTemplate.php');

// DO NOT IMPORT OR REFERENCE THIS CLASS IN ANY PRODUCTION CODE
class EmailTestHarness {
    
    /**
     * Test template processing without sending
     */
    public static function testTemplateProcessing($template_id, $values = []) {
        $email = EmailTemplate::CreateLegacyTemplate($template_id, null);
        $email->fill_template($values);
        
        return [
            'success' => true,
            'has_content' => $email->hasContent(),
            'subject' => $email->getEmailSubject(),
            'html_length' => strlen($email->getEmailHtml()),
            'text_length' => strlen($email->getEmailText()),
            'from' => $email->email_from,
            'service_type' => EmailSender::detectServiceType()
        ];
    }
    
    /**
     * Test SMTP configuration
     */
    public static function testSmtpConfig() {
        $settings = Globalvars::get_instance();
        
        return [
            'host' => $settings->get_setting('smtp_host'),
            'port' => $settings->get_setting('smtp_port'),
            'auth' => $settings->get_setting('smtp_auth'),
            'has_credentials' => !empty($settings->get_setting('smtp_username'))
        ];
    }
    
    /**
     * Check what service would be used
     */
    public static function checkServiceSelection() {
        $settings = Globalvars::get_instance();
        $email = EmailTemplate::CreateLegacyTemplate('default_outer_template', null);
        
        return [
            'mailgun_configured' => (
                $settings->get_setting('mailgun_api_key') && 
                $settings->get_setting('mailgun_domain')
            ),
            'smtp_configured' => !empty($settings->get_setting('smtp_host')),
            'would_use' => EmailSender::detectServiceType()
        ];
    }
}

// TEMPORARY CLI USAGE - This will be removed when proper tests are created
// Example: php utils/email_test_harness.php test_template 1
// DO NOT create any scripts or automation that depends on this CLI interface
if (php_sapi_name() === 'cli' && isset($argv[1])) {
    $command = $argv[1];
    
    switch($command) {
        case 'test_template':
            $template_id = $argv[2] ?? null;
            $result = EmailTestHarness::testTemplateProcessing($template_id);
            echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
            break;
            
        case 'test_smtp':
            $result = EmailTestHarness::testSmtpConfig();
            echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
            break;
            
        case 'check_service':
            $result = EmailTestHarness::checkServiceSelection();
            echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
            break;
            
        default:
            echo "Unknown command: $command\n";
            echo "Available commands: test_template, test_smtp, check_service\n";
    }
}