<?php
// tests/email/suites/ServiceTests.php

require_once(__DIR__ . '/../../../includes/SmtpMailer.php');

class ServiceTests {
    private array $config;
    private $runner;
    
    public function __construct(array $config, $runner = null) {
        $this->config = $config;
        $this->runner = $runner;
    }
    
    public function run(): array {
        $results = [];

        $results['smtp_config'] = $this->testSMTPConfiguration();
        $results['smtp_connection'] = $this->testSMTPConnection();
        $results['smtp_sending'] = $this->testSMTPSending();
        $results['mailgun_config'] = $this->testMailgunConfiguration();
        $results['mailgun_sending'] = $this->testMailgunSending();
        $results['service_detection'] = $this->testServiceDetection();

        // Provider abstraction tests
        $results['service_validation'] = $this->testServiceValidation();
        $results['service_fallback'] = $this->testServiceFallback();
        $results['batch_sending'] = $this->testBatchSending();
        $results['batch_fallback'] = $this->testBatchFallback();
        $results['queue_on_failure'] = $this->testQueueOnTotalFailure();

        return $results;
    }
    
    private function testSMTPConfiguration(): array {
        $settings = Globalvars::get_instance();
        
        // Test if SMTP settings are configured
        $host = $settings->get_setting('smtp_host');
        $port = $settings->get_setting('smtp_port');
        
        return [
            'passed' => !empty($host) && !empty($port),
            'message' => empty($host) ? 'SMTP host not configured' : 'SMTP configuration found',
            'details' => [
                'host' => $host ?: 'Not set',
                'port' => $port ?: 'Not set',
                'auth' => $settings->get_setting('smtp_auth') ? 'Enabled' : 'Disabled',
            ]
        ];
    }
    
    private function testSMTPConnection(): array {
        $settings = Globalvars::get_instance();
        $host = $settings->get_setting('smtp_host');
        $port = intval($settings->get_setting('smtp_port') ?: 25);
        
        if (empty($host)) {
            return ['passed' => false, 'message' => 'No SMTP host configured'];
        }
        
        // Test connection using socket
        $connection = @fsockopen($host, $port, $errno, $errstr, 5);
        
        if (!$connection) {
            return [
                'passed' => false,
                'message' => "SMTP connection failed: $errstr ($errno)",
            ];
        }
        
        fclose($connection);
        return [
            'passed' => true,
            'message' => "Successfully connected to $host:$port",
        ];
    }
    
    private function testSMTPSending(): array {
        $settings = Globalvars::get_instance();
        $testRecipient = $this->config['test_email'] ?? $settings->get_setting('email_test_recipient');
        
        if (!$testRecipient) {
            return [
                'passed' => false,
                'message' => 'No test recipient configured',
            ];
        }
        
        try {
            // Create a test email using new EmailMessage + EmailSender system
            $message = EmailMessage::fromTemplate('activation_content', [
                'act_code' => 'SMTP-TEST-' . date('His'),
                'resend' => false,
            ]);
            
            $message->from($settings->get_setting('defaultemail'), $settings->get_setting('defaultemailname'))
                   ->to($testRecipient, 'SMTP Test Recipient')
                   ->subject('SMTP Test Email - ' . date('Y-m-d H:i:s'));
            
            $sender = new EmailSender();
            $sendResult = $sender->send($message);
            
            return [
                'passed' => $sendResult,
                'message' => $sendResult ? "Successfully sent via SMTP to $testRecipient" : 'SMTP sending failed',
                'details' => [
                    'service_type' => 'smtp',
                    'test_recipient' => $testRecipient,
                    'smtp_host' => $settings->get_setting('smtp_host'),
                    'smtp_port' => $settings->get_setting('smtp_port'),
                    'smtp_auth' => $settings->get_setting('smtp_username') ? 'enabled' : 'disabled',
                    'send_result' => $sendResult,
                ]
            ];
        } catch (Exception $e) {
            return [
                'passed' => false,
                'message' => 'SMTP sending failed: ' . $e->getMessage(),
                'details' => [
                    'error' => $e->getMessage(),
                    'smtp_host' => $settings->get_setting('smtp_host'),
                ]
            ];
        }
    }
    
    private function testMailgunConfiguration(): array {
        $settings = Globalvars::get_instance();
        $apiKey = $settings->get_setting('mailgun_api_key');
        $domain = $settings->get_setting('mailgun_domain');
        
        return [
            'passed' => !empty($apiKey) && !empty($domain),
            'message' => (!empty($apiKey) && !empty($domain)) ? 'Mailgun configured' : 'Mailgun not configured',
            'details' => [
                'has_api_key' => !empty($apiKey),
                'has_domain' => !empty($domain),
                'domain' => $domain ?: 'Not set',
            ]
        ];
    }
    
    private function testMailgunSending(): array {
        $settings = Globalvars::get_instance();
        $testRecipient = $settings->get_setting('email_test_recipient');
        
        if (empty($testRecipient)) {
            return [
                'passed' => false,
                'message' => 'No test recipient configured',
            ];
        }
        
        // Check if Mailgun is configured
        $apiKey = $settings->get_setting('mailgun_api_key');
        $domain = $settings->get_setting('mailgun_domain');
        
        if (empty($apiKey) || empty($domain)) {
            return [
                'passed' => false,
                'message' => 'Mailgun not configured - cannot test sending',
                'details' => [
                    'has_api_key' => !empty($apiKey),
                    'has_domain' => !empty($domain),
                ]
            ];
        }
        
        try {
            // Create a test email using new EmailMessage + EmailSender system  
            $message = EmailMessage::fromTemplate('activation_content', [
                'act_code' => 'MAILGUN-TEST-' . date('His'),
                'resend' => false,
            ]);
            
            $message->from($settings->get_setting('defaultemail'), $settings->get_setting('defaultemailname'))
                   ->to($testRecipient, 'Mailgun Test Recipient')
                   ->subject('Mailgun Test Email - ' . date('Y-m-d H:i:s'));
            
            $sender = new EmailSender();
            $sendResult = $sender->send($message);
            
            return [
                'passed' => $sendResult,
                'message' => $sendResult ? "Successfully sent via Mailgun to $testRecipient" : 'Mailgun sending failed',
                'details' => [
                    'service_type' => 'mailgun',
                    'test_recipient' => $testRecipient,
                    'mailgun_domain' => $domain,
                    'send_result' => $sendResult,
                ]
            ];
        } catch (Exception $e) {
            return [
                'passed' => false,
                'message' => 'Mailgun sending failed: ' . $e->getMessage(),
                'details' => [
                    'error' => $e->getMessage(),
                    'test_recipient' => $testRecipient,
                ]
            ];
        }
    }
    
    // ── Helpers for temporarily overriding settings ──────────────────────

    /**
     * Read a setting's current DB value (bypassing cache).
     */
    private function readSettingFromDb(string $name): ?string {
        $db = DbConnector::get_instance()->get_db_link();
        $q = $db->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = :n');
        $q->execute([':n' => $name]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['stg_value'] : null;
    }

    /**
     * Write a setting to the DB and flush it from the Globalvars in-memory cache
     * so the next get_setting() call picks up the new value.
     */
    private function writeSetting(string $name, ?string $value): void {
        $db = DbConnector::get_instance()->get_db_link();
        $q = $db->prepare('UPDATE stg_settings SET stg_value = :v WHERE stg_name = :n');
        $q->execute([':v' => $value, ':n' => $name]);

        // Flush from Globalvars cache via reflection
        $settings = Globalvars::get_instance();
        $ref = new ReflectionClass($settings);
        $prop = $ref->getProperty('settings');
        $prop->setAccessible(true);
        $cache = $prop->getValue($settings);
        unset($cache[$name]);
        $prop->setValue($settings, $cache);
    }

    /**
     * Save current values for a list of settings so they can be restored later.
     */
    private function snapshotSettings(array $names): array {
        $snap = [];
        foreach ($names as $name) {
            $snap[$name] = $this->readSettingFromDb($name);
        }
        return $snap;
    }

    /**
     * Restore settings from a snapshot.
     */
    private function restoreSettings(array $snapshot): void {
        foreach ($snapshot as $name => $value) {
            $this->writeSetting($name, $value);
        }
    }

    // ── Provider abstraction tests ────────────────────────────────────

    private function testServiceValidation(): array {
        require_once(PathHelper::getIncludePath('includes/EmailSender.php'));

        $checks = [];
        $all_passed = true;

        // Validate mailgun — should return structured result
        $mg = EmailSender::validateService('mailgun');
        $mg_ok = isset($mg['valid']) && isset($mg['errors']) && is_array($mg['errors']);
        $checks['mailgun_structure'] = $mg_ok;
        if (!$mg_ok) $all_passed = false;

        // Validate smtp — should return structured result
        $smtp = EmailSender::validateService('smtp');
        $smtp_ok = isset($smtp['valid']) && isset($smtp['errors']) && is_array($smtp['errors']);
        $checks['smtp_structure'] = $smtp_ok;
        if (!$smtp_ok) $all_passed = false;

        // Validate nonexistent service — should return invalid
        $bad = EmailSender::validateService('nonexistent_provider');
        $bad_ok = isset($bad['valid']) && $bad['valid'] === false;
        $checks['unknown_rejected'] = $bad_ok;
        if (!$bad_ok) $all_passed = false;

        return [
            'passed' => $all_passed,
            'message' => $all_passed
                ? 'Service validation returns correct structure for all cases'
                : 'Service validation structure issues',
            'details' => [
                'checks' => $checks,
                'mailgun_valid' => $mg['valid'] ?? null,
                'smtp_valid' => $smtp['valid'] ?? null,
                'unknown_valid' => $bad['valid'] ?? null,
                'unknown_errors' => $bad['errors'] ?? [],
            ]
        ];
    }

    private function testServiceFallback(): array {
        require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
        $settings = Globalvars::get_instance();
        $testRecipient = $this->config['test_email'] ?? $settings->get_setting('email_test_recipient');

        if (!$testRecipient) {
            return ['passed' => false, 'message' => 'No test recipient configured'];
        }

        // Snapshot settings we will modify
        $keys = ['email_service', 'email_fallback_service', 'mailgun_api_key'];
        $snapshot = $this->snapshotSettings($keys);

        try {
            // Force mailgun as primary with a bogus API key, SMTP as fallback
            $this->writeSetting('email_service', 'mailgun');
            $this->writeSetting('email_fallback_service', 'smtp');
            $this->writeSetting('mailgun_api_key', 'key-bogus-intentionally-invalid-for-test');

            $message = EmailMessage::create(
                $testRecipient,
                'Fallback Test - ' . date('Y-m-d H:i:s'),
                '<p>This email should arrive via SMTP fallback because the Mailgun API key is invalid.</p>'
            );

            $sender = new EmailSender();
            $result = $sender->send($message);

            $this->restoreSettings($snapshot);

            return [
                'passed' => $result === true,
                'message' => $result
                    ? "Fallback succeeded — email sent via SMTP after Mailgun failure"
                    : "Fallback failed — email was not sent by either service",
                'details' => [
                    'primary' => 'mailgun (bogus key)',
                    'fallback' => 'smtp',
                    'send_result' => $result,
                    'test_recipient' => $testRecipient,
                ]
            ];
        } catch (Exception $e) {
            $this->restoreSettings($snapshot);
            return [
                'passed' => false,
                'message' => 'Fallback test threw exception: ' . $e->getMessage(),
                'details' => ['error' => $e->getMessage()]
            ];
        }
    }

    private function testBatchSending(): array {
        require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
        $settings = Globalvars::get_instance();
        $testRecipient = $this->config['test_email'] ?? $settings->get_setting('email_test_recipient');

        if (!$testRecipient) {
            return ['passed' => false, 'message' => 'No test recipient configured'];
        }

        try {
            $message = EmailMessage::create(
                'placeholder@example.com',  // overridden by sendBatch recipients
                'Batch Test - ' . date('Y-m-d H:i:s'),
                '<p>This is a batch sending test email.</p>'
            );

            $sender = new EmailSender();
            $results = $sender->sendBatch($message, [$testRecipient]);

            // Current API returns [$email => bool]
            $is_array = is_array($results);
            $has_recipient = $is_array && array_key_exists($testRecipient, $results);
            $recipient_ok = $has_recipient && $results[$testRecipient] === true;

            $passed = $is_array && $recipient_ok;

            return [
                'passed' => $passed,
                'message' => $passed
                    ? "Batch send succeeded for $testRecipient"
                    : "Batch send failed",
                'details' => [
                    'is_array' => $is_array,
                    'has_recipient_key' => $has_recipient,
                    'recipient_result' => $results[$testRecipient] ?? null,
                    'result_keys' => $is_array ? array_keys($results) : 'not array',
                ]
            ];
        } catch (Exception $e) {
            return [
                'passed' => false,
                'message' => 'Batch test threw exception: ' . $e->getMessage(),
                'details' => ['error' => $e->getMessage()]
            ];
        }
    }

    private function testBatchFallback(): array {
        require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
        $settings = Globalvars::get_instance();
        $testRecipient = $this->config['test_email'] ?? $settings->get_setting('email_test_recipient');

        if (!$testRecipient) {
            return ['passed' => false, 'message' => 'No test recipient configured'];
        }

        // Snapshot settings we will modify
        $keys = ['email_service', 'email_fallback_service', 'mailgun_api_key'];
        $snapshot = $this->snapshotSettings($keys);

        try {
            // Force mailgun as primary with bogus key, SMTP as fallback
            $this->writeSetting('email_service', 'mailgun');
            $this->writeSetting('email_fallback_service', 'smtp');
            $this->writeSetting('mailgun_api_key', 'key-bogus-intentionally-invalid-for-test');

            $message = EmailMessage::create(
                'placeholder@example.com',
                'Batch Fallback Test - ' . date('Y-m-d H:i:s'),
                '<p>This batch email should arrive via SMTP fallback.</p>'
            );

            $sender = new EmailSender();
            $results = $sender->sendBatch($message, [$testRecipient]);

            $this->restoreSettings($snapshot);

            // Current sendBatch loops calling send() which has fallback built in.
            // So the recipient should still succeed via SMTP.
            $is_array = is_array($results);
            $recipient_ok = $is_array && isset($results[$testRecipient]) && $results[$testRecipient] === true;

            return [
                'passed' => $recipient_ok,
                'message' => $recipient_ok
                    ? "Batch fallback succeeded — sent via SMTP after Mailgun failure"
                    : "Batch fallback failed",
                'details' => [
                    'primary' => 'mailgun (bogus key)',
                    'fallback' => 'smtp',
                    'recipient_result' => $results[$testRecipient] ?? null,
                ]
            ];
        } catch (Exception $e) {
            $this->restoreSettings($snapshot);
            return [
                'passed' => false,
                'message' => 'Batch fallback test threw exception: ' . $e->getMessage(),
                'details' => ['error' => $e->getMessage()]
            ];
        }
    }

    private function testQueueOnTotalFailure(): array {
        require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
        require_once(PathHelper::getIncludePath('data/queued_email_class.php'));

        $settings = Globalvars::get_instance();
        $testRecipient = $this->config['test_email'] ?? $settings->get_setting('email_test_recipient');

        if (!$testRecipient) {
            return ['passed' => false, 'message' => 'No test recipient configured'];
        }

        // Snapshot all settings we will break
        $keys = ['email_service', 'email_fallback_service', 'mailgun_api_key', 'smtp_host', 'smtp_username', 'smtp_password'];
        $snapshot = $this->snapshotSettings($keys);

        try {
            // Break both providers
            $this->writeSetting('email_service', 'mailgun');
            $this->writeSetting('email_fallback_service', 'smtp');
            $this->writeSetting('mailgun_api_key', 'key-bogus-intentionally-invalid-for-test');
            $this->writeSetting('smtp_host', 'invalid.host.example.com');
            $this->writeSetting('smtp_username', '');
            $this->writeSetting('smtp_password', '');

            // Record current max queued email ID
            $db = DbConnector::get_instance()->get_db_link();
            $q = $db->query('SELECT COALESCE(MAX(equ_queued_email_id), 0) AS max_id FROM equ_queued_emails');
            $max_id = (int)$q->fetch(PDO::FETCH_ASSOC)['max_id'];

            $test_subject = 'Queue Test - ' . uniqid();
            // Build manually so recipient has a name — QueuedEmail requires equ_to_name.
            // Without a name, queueForRetry() silently fails (pre-existing bug).
            $message = new EmailMessage();
            $message->to($testRecipient, 'Queue Test Recipient')
                    ->subject($test_subject)
                    ->html('<p>This email should fail to send and be queued for retry.</p>');

            $sender = new EmailSender();
            $result = $sender->send($message);

            // Check for new queued rows
            $q2 = $db->prepare(
                'SELECT COUNT(*) AS cnt FROM equ_queued_emails WHERE equ_queued_email_id > :max_id AND equ_subject = :subj'
            );
            $q2->execute([':max_id' => $max_id, ':subj' => $test_subject]);
            $queued_count = (int)$q2->fetch(PDO::FETCH_ASSOC)['cnt'];

            // Clean up test rows
            $q3 = $db->prepare(
                'DELETE FROM equ_queued_emails WHERE equ_queued_email_id > :max_id AND equ_subject = :subj'
            );
            $q3->execute([':max_id' => $max_id, ':subj' => $test_subject]);

            $this->restoreSettings($snapshot);

            $passed = ($result === false) && ($queued_count > 0);

            return [
                'passed' => $passed,
                'message' => $passed
                    ? "Both services failed, email queued for retry ($queued_count row(s))"
                    : "Queue test issue: send returned " . var_export($result, true) . ", queued $queued_count rows",
                'details' => [
                    'send_returned_false' => ($result === false),
                    'queued_count' => $queued_count,
                    'test_subject' => $test_subject,
                ]
            ];
        } catch (Exception $e) {
            $this->restoreSettings($snapshot);
            return [
                'passed' => false,
                'message' => 'Queue test threw exception: ' . $e->getMessage(),
                'details' => ['error' => $e->getMessage()]
            ];
        }
    }

    private function testServiceDetection(): array {
        $settings = Globalvars::get_instance();
        
        // Test the new service selection system
        $currentService = $settings->get_setting('email_service') ?: 'mailgun';
        $fallbackService = $settings->get_setting('email_fallback_service') ?: 'smtp';
        
        // Test service validation using EmailSender (moved from EmailTemplate)
        $primaryValidation = EmailSender::validateService($currentService);
        $fallbackValidation = EmailSender::validateService($fallbackService);
        
        $passed = in_array($currentService, ['smtp', 'mailgun']) && 
                  in_array($fallbackService, ['smtp', 'mailgun']);
        
        return [
            'passed' => $passed,
            'message' => "Primary: $currentService, Fallback: $fallbackService",
            'details' => [
                'primary_service' => $currentService,
                'fallback_service' => $fallbackService,
                'primary_valid' => $primaryValidation['valid'],
                'fallback_valid' => $fallbackValidation['valid'],
                'primary_errors' => $primaryValidation['errors'],
                'fallback_errors' => $fallbackValidation['errors'],
            ]
        ];
    }
}