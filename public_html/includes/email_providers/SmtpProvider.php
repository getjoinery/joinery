<?php
/**
 * SmtpProvider - SMTP email service provider
 *
 * Implements EmailServiceProvider using PHPMailer via the SmtpMailer wrapper class.
 * Batch sending loops over individual sends (no native SMTP batch API).
 */

require_once(PathHelper::getIncludePath('includes/SmtpMailer.php'));

class SmtpProvider implements EmailServiceProvider {

    public static function getKey(): string {
        return 'smtp';
    }

    public static function getLabel(): string {
        return 'SMTP';
    }

    public static function getSettingsFields(): array {
        return [
            [
                'key' => 'smtp_host',
                'label' => 'SMTP Host',
                'type' => 'text',
            ],
            [
                'key' => 'smtp_port',
                'label' => 'SMTP Port (25, 465, 587, 2525)',
                'type' => 'text',
            ],
            [
                'key' => 'smtp_helo',
                'label' => 'SMTP HELO/EHLO Hostname',
                'type' => 'text',
            ],
            [
                'key' => 'smtp_hostname',
                'label' => 'SMTP Hostname (for headers)',
                'type' => 'text',
            ],
            [
                'key' => 'smtp_sender',
                'label' => 'SMTP Bounce Address',
                'type' => 'text',
            ],
            [
                'key' => 'smtp_auth',
                'label' => 'SMTP Authentication Required',
                'type' => 'dropdown',
                'options' => [0 => 'No', 1 => 'Yes'],
            ],
            [
                'key' => 'smtp_username',
                'label' => 'SMTP Username',
                'type' => 'text',
                'show_when' => ['smtp_auth' => '1'],
            ],
            [
                'key' => 'smtp_password',
                'label' => 'SMTP Password',
                'type' => 'password',
                'show_when' => ['smtp_auth' => '1'],
            ],
        ];
    }

    public static function validateConfiguration(): array {
        $settings = Globalvars::get_instance();
        $errors = [];

        if (empty($settings->get_setting('smtp_host'))) {
            $errors[] = 'SMTP host not configured';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Optional: Live SMTP connection test for admin settings panel.
     */
    public static function validateApiConnection(): array {
        $settings = Globalvars::get_instance();
        $host = $settings->get_setting('smtp_host');
        $port = $settings->get_setting('smtp_port');

        if (empty($host)) {
            return [
                'success' => false,
                'label' => 'Not Configured',
                'details' => [],
                'error' => 'Enter SMTP host to validate connection',
            ];
        }

        $details = [
            'Host' => $host,
            'Port' => $port ?: '25',
            'Authentication' => $settings->get_setting('smtp_auth') ? 'Yes' : 'No',
        ];

        // Determine encryption from port
        $port_int = intval($port ?: 25);
        switch ($port_int) {
            case 465:
                $details['Encryption'] = 'SSL/TLS';
                break;
            case 587:
            case 2525:
                $details['Encryption'] = 'STARTTLS';
                break;
            default:
                $details['Encryption'] = 'None';
                break;
        }

        try {
            $mailer = new SmtpMailer();
            $connect_result = $mailer->smtpConnect();

            if ($connect_result) {
                $mailer->smtpClose();
                $label = 'Connection Successful';
                if ($settings->get_setting('smtp_auth')) {
                    $label .= ' (authenticated)';
                }
                return [
                    'success' => true,
                    'label' => $label,
                    'details' => $details,
                    'error' => null,
                ];
            } else {
                $error_info = $mailer->ErrorInfo ?: 'Connection or authentication failed';
                return [
                    'success' => false,
                    'label' => 'Connection Failed',
                    'details' => $details,
                    'error' => $error_info,
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'label' => 'Connection Failed',
                'details' => $details,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function send(EmailMessage $message): bool {
        $mailer = new SmtpMailer();

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
            error_log("[SmtpProvider] Send failed: " . $mailer->ErrorInfo);
            return false;
        }

        return true;
    }

    public function sendBatch(EmailMessage $message, array $recipients): array {
        $failed_recipients = [];

        foreach ($recipients as $email) {
            $individual = clone $message;
            // Clear existing recipients and set just this one
            $individual = new EmailMessage();
            $individual->to($email)
                       ->subject($message->getSubject())
                       ->from($message->getFrom(), $message->getFromName());

            if ($message->getHtmlBody()) {
                $individual->html($message->getHtmlBody());
            } else {
                $individual->text($message->getTextBody());
            }

            // Copy CC, BCC, headers, attachments
            foreach ($message->getCc() as $cc) {
                $individual->cc($cc['email'], $cc['name']);
            }
            foreach ($message->getBcc() as $bcc) {
                $individual->bcc($bcc['email'], $bcc['name']);
            }
            foreach ($message->getHeaders() as $name => $value) {
                $individual->header($name, $value);
            }
            if ($message->getReplyTo()) {
                $individual->replyTo($message->getReplyTo());
            }

            try {
                if (!$this->send($individual)) {
                    $failed_recipients[] = $email;
                }
            } catch (\Exception $e) {
                error_log("[SmtpProvider] Batch send failed for $email: " . $e->getMessage());
                $failed_recipients[] = $email;
            }
        }

        return [
            'success' => empty($failed_recipients),
            'failed_recipients' => $failed_recipients,
        ];
    }
}
