<?php
/**
 * SmtpProvider - SMTP email service provider
 *
 * Implements EmailServiceProvider using PHPMailer via the SmtpMailer wrapper class.
 * Batch sending loops over individual sends (no native SMTP batch API).
 * Also implements RawMessageRelay: SMTP is natively a raw-MIME relay with full
 * envelope (MAIL FROM) control, so inbound forwarding can relay through it.
 *
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('includes/SmtpMailer.php'));

class SmtpProvider implements EmailServiceProvider, RawMessageRelay {

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

    // ── RawMessageRelay ─────────────────────────────────────────────────

    /**
     * Relay raw MIME over SMTP with an explicit envelope sender. SMTP is the
     * one path with true MAIL FROM control, so the SRS-rewritten envelope is
     * honored as-is. Each destination is a separate SMTP transaction so a
     * single failed recipient does not fail the others — matching the
     * per-destination result shape forwardEmail() expects.
     *
     * Uses the base smtp_* settings (via SmtpMailer), the same credential the
     * outbound SMTP send() path uses. The router's forwarding-specific
     * inbound_email_forwarding_smtp_* override is handled separately by its
     * own SMTP fallback path (createMailer()), which this method does not touch.
     */
    public function relayRawMessage(string $raw_mime, string $envelope_sender, array $destinations): array {
        $results = [];

        foreach ($destinations as $destination) {
            try {
                $mailer = new SmtpMailer();
                $mailer->Sender = $envelope_sender;
                $mailer->addAddress($destination);

                if (!$mailer->smtpConnect()) {
                    throw new \Exception('SMTP connect failed: ' . $mailer->ErrorInfo);
                }

                $smtp = $mailer->getSMTPInstance();

                if (!$smtp->mail($envelope_sender)) {
                    throw new \Exception('SMTP MAIL FROM failed');
                }
                if (!$smtp->recipient($destination)) {
                    throw new \Exception('SMTP RCPT TO failed');
                }
                if (!$smtp->data($raw_mime)) {
                    throw new \Exception('SMTP DATA failed');
                }

                $smtp->quit();
                $smtp->close();

                $results[$destination] = true;
            } catch (\Exception $e) {
                error_log('[SmtpProvider] relayRawMessage failed to ' . $destination . ': ' . $e->getMessage());
                $results[$destination] = false;
            }
        }

        return $results;
    }
}
