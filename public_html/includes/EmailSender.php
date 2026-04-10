<?php
require_once(PathHelper::getIncludePath('includes/EmailServiceProvider.php'));
require_once(PathHelper::getIncludePath('includes/EmailTemplate.php'));
require_once(PathHelper::getIncludePath('includes/EmailMessage.php'));

require_once(PathHelper::getIncludePath('data/debug_email_logs_class.php'));

class EmailSender {
    private $settings;
    private $defaultFrom;
    private $defaultFromName;
    private $debugMode;

    /** @var array|null Cached provider registry: key => class name */
    private static $providers = null;

    public function __construct() {
        $this->settings = Globalvars::get_instance();
        $this->defaultFrom = $this->settings->get_setting('defaultemail');
        $this->defaultFromName = $this->settings->get_setting('defaultemailname');
        $this->debugMode = $this->settings->get_setting('email_debug_mode') == '1';
    }

    // ── Provider Discovery ──────────────────────────────────────────────

    /**
     * Scan includes/email_providers/ for classes implementing EmailServiceProvider.
     * Results are cached for the lifetime of the request.
     */
    private static function discoverProviders(): array {
        if (self::$providers !== null) {
            return self::$providers;
        }

        self::$providers = [];
        $provider_dir = PathHelper::getIncludePath('includes/email_providers/');

        foreach (glob($provider_dir . '*Provider.php') as $file) {
            require_once($file);
            $class = basename($file, '.php');
            if (class_exists($class) && in_array('EmailServiceProvider', class_implements($class))) {
                $key = $class::getKey();
                self::$providers[$key] = $class;
            }
        }

        return self::$providers;
    }

    /**
     * Get the class name for a provider by key.
     */
    private static function getProviderClass(string $key): ?string {
        $providers = self::discoverProviders();
        return $providers[$key] ?? null;
    }

    /**
     * Instantiate a provider by key.
     */
    private static function getProvider(string $key): ?EmailServiceProvider {
        $class = self::getProviderClass($key);
        if (!$class) {
            return null;
        }
        return new $class();
    }

    /**
     * Return all discovered providers as ['key' => 'Label'] for dropdowns.
     */
    public static function getAvailableServices(): array {
        $services = [];
        foreach (self::discoverProviders() as $key => $class) {
            $services[$key] = $class::getLabel();
        }
        return $services;
    }

    /**
     * Return settings fields for a specific provider.
     */
    public static function getProviderSettings(string $key): array {
        $providers = self::discoverProviders();
        if (!isset($providers[$key])) {
            return [];
        }
        return $providers[$key]::getSettingsFields();
    }

    /**
     * Return the discovered providers registry (for admin page iteration).
     * Returns ['key' => 'ClassName', ...]
     */
    public static function getDiscoveredProviders(): array {
        return self::discoverProviders();
    }

    /**
     * Reset the cached provider list (useful for testing).
     */
    public static function resetProviderCache(): void {
        self::$providers = null;
    }

    // ── Sending ─────────────────────────────────────────────────────────

    /**
     * Main sending method with clean interface
     *
     * @param EmailMessage $message The email to send
     * @param bool $queue_on_failure If true, save to equ_queued_emails on total failure for later retry.
     *        Pass false when sending from the retry task to prevent infinite re-queuing.
     * @return bool True if sent successfully
     */
    public function send(EmailMessage $message, $queue_on_failure = true) {
        // Set defaults if not specified
        if (!$message->getFrom()) {
            $message->from($this->defaultFrom, $this->defaultFromName);
        }

        // Validate
        $errors = $message->validate();
        if (!empty($errors)) {
            throw new Exception('Invalid email message: ' . implode(', ', $errors));
        }

        // Use service selection with fallback
        $settings = Globalvars::get_instance();
        $service = $settings->get_setting('email_service') ?: 'mailgun';
        $fallback = $settings->get_setting('email_fallback_service') ?: 'smtp';

        $result = $this->sendWithService($service, $message);

        if (!$result && $fallback && $fallback !== $service) {
            $this->logEmailDebug("Primary service $service failed, trying fallback $fallback");
            $fallback_result = $this->sendWithService($fallback, $message);

            if ($fallback_result) {
                $this->logEmailDebug("Fallback service $fallback succeeded");
                return true;
            }

            $this->logEmailDebug("Both primary and fallback services failed");
            if ($queue_on_failure) {
                $this->queueForRetry($message);
            }
        }

        return $result;
    }

    /**
     * Quick send static method for simple cases
     * Uses the default outer template for consistent styling
     */
    public static function quickSend($to, $subject, $body, $from = null) {
        $settings = Globalvars::get_instance();

        // Check if body appears to be plain text or HTML
        $isHtml = strip_tags($body) !== $body;

        if ($isHtml) {
            // Get default template setting with proper error handling
            $defaultTemplate = $settings->get_setting('default_email_template');

            if (!$defaultTemplate) {
                throw new Exception('Default email template not configured. Please set default_email_template setting or use plain text emails.');
            }

            try {
                $message = EmailMessage::fromTemplate($defaultTemplate, [
                    'subject' => $subject,
                    'content' => $body,
                    'inner_template' => $body
                ]);

                // Override subject since we want the one passed in, not from template
                $message->subject($subject);
            } catch (EmailTemplateError $e) {
                throw new Exception('Default email template \'' . $defaultTemplate . '\' is malformed or missing: ' . $e->getMessage());
            }
        } else {
            // For plain text, just send as-is
            $message = EmailMessage::create($to, $subject, $body);
        }

        $message->to($to);

        if ($from) {
            $message->from($from);
        }

        $sender = new self();
        return $sender->send($message);
    }

    /**
     * Send using a template
     */
    public static function sendTemplate($templateName, $to, $values = [], $subject = null) {
        $message = EmailMessage::fromTemplate($templateName, $values);
        $message->to($to);

        if ($subject) {
            $message->subject($subject);
        }

        $sender = new self();
        return $sender->send($message);
    }

    /**
     * Batch send to multiple recipients.
     *
     * Uses the provider's native batch API when available (e.g., Mailgun's
     * recipient-variables). Falls back failed recipients to the fallback provider.
     *
     * @return array ['success' => bool, 'failed_recipients' => string[]]
     */
    public function sendBatch(EmailMessage $message, array $recipients) {
        // Set defaults if not specified
        if (!$message->getFrom()) {
            $message->from($this->defaultFrom, $this->defaultFromName);
        }

        $settings = Globalvars::get_instance();
        $service = $settings->get_setting('email_service') ?: 'mailgun';
        $fallback = $settings->get_setting('email_fallback_service') ?: '';

        $provider = self::getProvider($service);
        if (!$provider) {
            $this->logEmailDebug("Unknown email service for batch: $service");
            return ['success' => false, 'failed_recipients' => $recipients];
        }

        // Try primary provider's native batch
        $result = $provider->sendBatch($message, $recipients);

        if ($result['success']) {
            $this->logEmailDebug("Batch sent successfully via $service (" . count($recipients) . " recipients)");
            return $result;
        }

        // If some recipients failed and there's a fallback, try those
        $failed = $result['failed_recipients'];
        if (!empty($failed) && $fallback && $fallback !== $service) {
            $fallback_provider = self::getProvider($fallback);
            if ($fallback_provider) {
                $this->logEmailDebug("Batch: " . count($failed) . " recipients failed via $service, trying $fallback");
                $fallback_result = $fallback_provider->sendBatch($message, $failed);

                if ($fallback_result['success']) {
                    $this->logEmailDebug("Batch fallback via $fallback succeeded");
                    return ['success' => true, 'failed_recipients' => []];
                }

                // Queue whatever is still left
                $still_failed = $fallback_result['failed_recipients'];
                foreach ($still_failed as $email) {
                    $individual = new EmailMessage();
                    $individual->to($email, '')
                               ->subject($message->getSubject())
                               ->from($message->getFrom(), $message->getFromName());
                    if ($message->getHtmlBody()) {
                        $individual->html($message->getHtmlBody());
                    } else {
                        $individual->text($message->getTextBody());
                    }
                    $this->queueForRetry($individual);
                }
                return ['success' => false, 'failed_recipients' => $still_failed];
            }
        }

        // No fallback — queue all failures
        foreach ($failed as $email) {
            $individual = new EmailMessage();
            $individual->to($email, '')
                       ->subject($message->getSubject())
                       ->from($message->getFrom(), $message->getFromName());
            if ($message->getHtmlBody()) {
                $individual->html($message->getHtmlBody());
            } else {
                $individual->text($message->getTextBody());
            }
            $this->queueForRetry($individual);
        }
        return $result;
    }

    // ── Internal ────────────────────────────────────────────────────────

    /**
     * Send with a specific provider by key.
     */
    private function sendWithService($service, EmailMessage $message) {
        $provider = self::getProvider($service);
        if (!$provider) {
            $this->logEmailDebug("Unknown email service: $service");
            return false;
        }

        try {
            $result = $provider->send($message);
            if ($result) {
                $this->logEmailDebug("Email sent successfully via $service", $service);
            } else {
                $this->logEmailDebug("Email send failed via $service", $service);
            }
            return $result;
        } catch (\Exception $e) {
            $this->logEmailDebug("Email send exception via $service: " . $e->getMessage(), $service);
            return false;
        }
    }

    /**
     * Validate email service configuration
     */
    public function validateServiceConfiguration($service = null) {
        $service = $service ?: $this->settings->get_setting('email_service') ?: 'mailgun';

        $provider_class = self::getProviderClass($service);
        if (!$provider_class) {
            return [
                'valid' => false,
                'service' => $service,
                'errors' => ["Unknown email service: $service"],
            ];
        }

        $result = $provider_class::validateConfiguration();
        $result['service'] = $service;
        return $result;
    }

    /**
     * Static convenience method for service validation
     */
    public static function validateService($service = null) {
        $sender = new self();
        return $sender->validateServiceConfiguration($service);
    }

    /**
     * Get which service would be used to send (for testing)
     */
    public function getServiceType() {
        $service = $this->settings->get_setting('email_service') ?: 'mailgun';
        $provider_class = self::getProviderClass($service);

        if ($provider_class) {
            $validation = $provider_class::validateConfiguration();
            if ($validation['valid']) {
                return $service;
            }
        }

        return 'none';
    }

    /**
     * Static convenience method for service type detection
     */
    public static function detectServiceType() {
        $sender = new self();
        return $sender->getServiceType();
    }

    /**
     * Queue a failed email for later retry via the SendQueuedEmails scheduled task.
     * Saves one row per recipient into equ_queued_emails with ERROR_SENDING status.
     */
    private function queueForRetry(EmailMessage $message) {
        try {
            require_once(PathHelper::getIncludePath('data/queued_email_class.php'));

            $fromName = $message->getFromName() ?: '';

            foreach ($message->getRecipients() as $recipient) {
                $queued = new QueuedEmail(NULL);
                $queued->set('equ_from', $message->getFrom());
                $queued->set('equ_from_name', $fromName);
                $queued->set('equ_to', $recipient['email']);
                $queued->set('equ_to_name', $recipient['name'] ?? '');
                $queued->set('equ_subject', $message->getSubject());
                $queued->set('equ_body', $message->getHtmlBody() ?: $message->getTextBody());
                $queued->set('equ_status', QueuedEmail::ERROR_SENDING);
                $queued->set('equ_retry_count', 0);
                $queued->save();
            }

            $count = count($message->getRecipients());
            $this->logEmailDebug("Queued $count recipient(s) for retry");
        } catch (Exception $e) {
            error_log("[EmailSender] Failed to queue email for retry: " . $e->getMessage());
        }
    }

    /**
     * Debug logging
     * Made public to support deprecated EmailTemplate methods
     */
    public function logEmailDebug($message, $service = null) {
        if (!$this->debugMode) {
            return;
        }

        try {
            require_once(PathHelper::getIncludePath('data/debug_email_logs_class.php'));

            $log = new DebugEmailLog(null);
            $log->set('del_timestamp', date('Y-m-d H:i:s'));
            $log->set('del_message', $message);
            $log->set('del_service', $service ?: 'unknown');
            $log->set('del_status', 'debug');
            $log->save();
        } catch (Exception $e) {
            error_log("Debug email log failed: " . $e->getMessage());
        }
    }
}
