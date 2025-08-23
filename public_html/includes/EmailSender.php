<?php
require_once('PathHelper.php');
require_once('Globalvars.php');
require_once('SmtpMailer.php');
require_once('EmailTemplate.php');
require_once('EmailMessage.php');

// Composer autoload is already loaded by SmtpMailer.php
use Mailgun\Mailgun;

PathHelper::requireOnce('data/debug_email_logs_class.php');

class EmailSender {
    private $settings;
    private $defaultFrom;
    private $defaultFromName;
    private $debugMode;
    private $ccRecipients = [];
    private $bccRecipients = [];
    private $attachments = [];
    private $headers = [];
    private $replyTo;
    
    public function __construct() {
        $this->settings = Globalvars::get_instance();
        $this->defaultFrom = $this->settings->get_setting('defaultemail');
        $this->defaultFromName = $this->settings->get_setting('defaultemailname');
        $this->debugMode = $this->settings->get_setting('email_debug_mode') == '1';
    }
    
    /**
     * Main sending method with clean interface
     */
    public function send(EmailMessage $message) {
        // Set defaults if not specified
        if (!$message->getFrom()) {
            $message->from($this->defaultFrom, $this->defaultFromName);
        }
        
        // Validate
        $errors = $message->validate();
        if (!empty($errors)) {
            throw new Exception('Invalid email message: ' . implode(', ', $errors));
        }
        
        // Use service selection with fallback (moved logic from EmailTemplate)
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
            
            $this->logEmailDebug("Both primary and fallback services failed, email queued for retry");
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
                    'content' => $body,  // Assuming template uses *content* for the main body
                    'inner_template' => $body  // Or *inner_template* depending on your template
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
     * Batch send to multiple recipients
     */
    public function sendBatch(EmailMessage $message, array $recipients) {
        $results = [];
        
        foreach ($recipients as $recipient) {
            $individualMessage = clone $message;
            $individualMessage->to($recipient);
            
            try {
                $results[$recipient] = $this->send($individualMessage);
            } catch (Exception $e) {
                $results[$recipient] = false;
                error_log("Failed to send to $recipient: " . $e->getMessage());
            }
        }
        
        return $results;
    }
    
    
    /**
     * Send with service selection and fallback (moved from EmailTemplate)
     */
    private function sendWithService($service, EmailMessage $message) {
        switch ($service) {
            case 'smtp':
                return $this->sendViaSMTP($message);
                
            case 'mailgun':
                return $this->sendViaMailgun($message);
                
            default:
                $this->logEmailDebug("Unknown email service: $service");
                return false;
        }
    }
    
    /**
     * Main SMTP sending implementation (moved from EmailTemplate)
     */
    public function sendViaSMTP(EmailMessage $message) {
        $mailer = new SmtpMailer();
        
        // Configure SMTP settings
        $settings = Globalvars::get_instance();
        $mailer->isSMTP();
        $mailer->Host = $settings->get_setting('smtp_host');
        $port = intval($settings->get_setting('smtp_port') ?: 25);
        $mailer->Port = $port;
        $mailer->SMTPAuth = ($settings->get_setting('smtp_username') ? true : false);
        
        if ($mailer->SMTPAuth) {
            $mailer->Username = $settings->get_setting('smtp_username');
            $mailer->Password = $settings->get_setting('smtp_password');
        }
        
        $encryption = $settings->get_setting('smtp_encryption');
        if ($encryption) {
            $mailer->SMTPSecure = $encryption;
        }
        
        // Set email content
        $mailer->isHTML(true);
        $mailer->setFrom($message->getFrom(), $message->getFromName());
        $mailer->Subject = $message->getSubject();
        $mailer->Body = $message->getHtmlBody();
        $mailer->AltBody = $message->getTextBody();
        
        // Add recipients
        foreach ($message->getRecipients() as $recipient) {
            $mailer->addAddress($recipient['email'], $recipient['name']);
        }
        
        // Add CC recipients
        foreach ($message->getCc() as $cc) {
            $mailer->addCC($cc['email'], $cc['name']);
        }
        
        // Add BCC recipients  
        foreach ($message->getBcc() as $bcc) {
            $mailer->addBCC($bcc['email'], $bcc['name']);
        }
        
        // Add reply-to
        if ($replyTo = $message->getReplyTo()) {
            $mailer->addReplyTo($replyTo);
        }
        
        // Add custom headers
        foreach ($message->getHeaders() as $name => $value) {
            $mailer->addCustomHeader($name, $value);
        }
        
        // Add attachments
        foreach ($message->getAttachments() as $attachment) {
            $mailer->addAttachment($attachment['path'], $attachment['name']);
        }
        
        if (!$mailer->send()) {
            $this->logEmailDebug("SMTP send failed: " . $mailer->ErrorInfo, 'smtp');
            $this->queueFailedEmail($message, $mailer->ErrorInfo);
            return false;
        }
        
        $this->logEmailDebug("Email sent successfully via SMTP", 'smtp');
        return true;
    }
    
    /**
     * Main Mailgun sending implementation (moved from EmailTemplate)
     */
    public function sendViaMailgun(EmailMessage $message) {
        $settings = Globalvars::get_instance();
        
        // Initialize Mailgun client (preserve existing logic)
        if ($settings->get_setting('mailgun_version') == 1) {
            if ($settings->get_setting('mailgun_eu_api_link')) {
                $mg = new Mailgun($settings->get_setting('mailgun_api_key'), $settings->get_setting('mailgun_eu_api_link'));
            } else {
                $mg = new Mailgun($settings->get_setting('mailgun_api_key'));
            }
        } else {
            if ($settings->get_setting('mailgun_eu_api_link')) {
                $mg = Mailgun::create($settings->get_setting('mailgun_api_key'), $settings->get_setting('mailgun_eu_api_link'));
            } else {
                $mg = Mailgun::create($settings->get_setting('mailgun_api_key'));
            }
        }
        
        $domain = $settings->get_setting('mailgun_domain');
        
        // Prepare email data
        $email_to_send = array(
            'from' => $message->getFromName() . '<' . $message->getFrom() . '>',
            'subject' => $message->getSubject(),
        );
        
        if ($message->getHtmlBody()) {
            $email_to_send['html'] = $message->getHtmlBody();
        } else {
            $email_to_send['text'] = $message->getTextBody();
        }
        
        // PRESERVE CRITICAL FEATURE: Send in batches of 500 recipients
        $recipients = $message->getRecipients();
        $sending_groups = array_chunk($recipients, 500, true);
        $all_sent = true;
        
        foreach ($sending_groups as $sending_group) {
            $mailgun_recipients = array();
            $recipient_variables = array();
            
            foreach ($sending_group as $recipient) {
                $mailgun_recipients[] = $recipient['name'] . '<' . $recipient['email'] . '>';
                $recipient_variables[$recipient['email']] = array('name' => $recipient['name']);
            }
            
            $email_to_send['to'] = implode(',', $mailgun_recipients);
            $email_to_send['recipient-variables'] = json_encode($recipient_variables);
            
            try {
                if ($settings->get_setting('mailgun_version') == 1) {
                    $result = $mg->sendMessage($domain, $email_to_send);
                } else {
                    $result = $mg->messages()->send($domain, $email_to_send);
                }
                $this->logEmailDebug("Email batch sent successfully via Mailgun", 'mailgun');
            } catch (Exception $e) {
                $this->logEmailDebug("Mailgun send failed: " . $e->getMessage(), 'mailgun');
                $this->queueFailedEmail($message, $e->getMessage());
                $all_sent = false;
            }
        }
        
        return $all_sent;
    }
    
    /**
     * Internal method to queue failed emails for retry (replaces save_email_as_queued calls)
     */
    private function queueFailedEmail(EmailMessage $message, $error = null) {
        try {
            PathHelper::requireOnce('data/queued_email_class.php');
            
            // Set defaults if not specified
            if (!$message->getFrom()) {
                $message->from($this->defaultFrom, $this->defaultFromName);
            }
            
            $queued_email = new QueuedEmail(null);
            $queued_email->set('equ_from_name', $message->getFromName());
            $queued_email->set('equ_from', $message->getFrom());
            $queued_email->set('equ_subject', $message->getSubject());
            $queued_email->set('equ_body', $message->getHtmlBody());
            $queued_email->set('equ_status', QueuedEmail::NORMAL_MAILER_ERROR);
            
            // Handle recipients (queue supports single recipient per entry)
            $recipients = $message->getRecipients();
            if (!empty($recipients)) {
                $first_recipient = $recipients[0];
                $queued_email->set('equ_to', $first_recipient['email']);
                $queued_email->set('equ_to_name', $first_recipient['name']);
            }
            
            $queued_email->save();
            
            if ($this->debugMode) {
                error_log("Email queued for retry due to send failure: " . ($error ?: 'Unknown error'));
            }
            
            return $queued_email->key;
        } catch (Exception $e) {
            error_log("Failed to queue email for retry: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Validate email service configuration
     * Moved from EmailTemplate - this belongs with sending logic
     */
    public function validateServiceConfiguration($service = null) {
        $service = $service ?: $this->settings->get_setting('email_service') ?: 'mailgun';
        
        $errors = [];
        
        switch($service) {
            case 'mailgun':
                if (empty($this->settings->get_setting('mailgun_domain'))) {
                    $errors[] = 'Mailgun domain not configured';
                }
                if (empty($this->settings->get_setting('mailgun_api_key'))) {
                    $errors[] = 'Mailgun API key not configured';
                }
                break;
                
            case 'smtp':
                if (empty($this->settings->get_setting('smtp_host'))) {
                    $errors[] = 'SMTP host not configured';
                }
                if (empty($this->settings->get_setting('smtp_username'))) {
                    $errors[] = 'SMTP username not configured';
                }
                if (empty($this->settings->get_setting('smtp_password'))) {
                    $errors[] = 'SMTP password not configured';
                }
                break;
                
            default:
                $errors[] = "Unknown email service: $service";
        }
        
        return [
            'valid' => empty($errors),
            'service' => $service,
            'errors' => $errors
        ];
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
     * Moved from EmailTemplate - belongs with sending logic
     */
    public function getServiceType() {
        // Use the same logic as sendWithService but read-only
        $service = $this->settings->get_setting('email_service') ?: 'mailgun';
        
        switch($service) {
            case 'smtp':
                if ($this->settings->get_setting('smtp_host')) {
                    return 'smtp';
                }
                break;
            case 'mailgun':
                if ($this->settings->get_setting('mailgun_api_key') && $this->settings->get_setting('mailgun_domain')) {
                    return 'mailgun';
                }
                break;
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
     * Debug logging (moved from EmailTemplate)
     * Made public to support deprecated EmailTemplate methods
     */
    public function logEmailDebug($message, $service = null) {
        if (!$this->debugMode) {
            return;
        }
        
        try {
            PathHelper::requireOnce('data/debug_email_logs_class.php');
            
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