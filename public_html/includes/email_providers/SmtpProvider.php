<?php
/**
 * SmtpProvider - SMTP email service provider
 *
 * Implements EmailServiceProvider using PHPMailer via the SmtpMailer wrapper class.
 * Batch sending loops over individual sends (no native SMTP batch API).
 * Also implements RawMessageRelay: SMTP is natively a raw-MIME relay with full
 * envelope (MAIL FROM) control, so inbound forwarding can relay through it.
 *
 * Configured from an SmtpConfig (default: global smtp_*), so the one class is the
 * SMTP transport whether sending globally, as a connected account, or through the
 * forwarding relay. The EmailMessage→PHPMailer mapping lives once in
 * SmtpMailer::applyMessage().
 *
 * @version 1.4
 */

require_once(PathHelper::getIncludePath('includes/SmtpMailer.php'));
require_once(PathHelper::getIncludePath('includes/SmtpConfig.php'));
require_once(PathHelper::getIncludePath('includes/MailIdentityGuard.php'));

class SmtpProvider implements EmailServiceProvider, RawMessageRelay {

    /**
     * The SMTP transport configuration. Defaults to the global smtp_* settings so
     * `new SmtpProvider()` (the auto-discovered, no-arg construction EmailSender
     * uses) is the system SMTP provider unchanged. Pass an SmtpConfig to make the
     * same class send through a connected account or the forwarding relay.
     *
     * @var SmtpConfig
     */
    private $config;

    public function __construct(?SmtpConfig $config = null) {
        $this->config = $config ?: SmtpConfig::fromSettings();
    }

    public static function getKey(): string {
        return 'smtp';
    }

    public static function getLabel(): string {
        return 'SMTP';
    }

    public static function getSpfMechanism(string $domain): string
    {
        // Mail egresses from the configured SMTP host, so its address records
        // are the authorized senders. Localhost submission is the box itself —
        // covered (colocated only) by the server's own ip4 term, not an a: term.
        $host = strtolower(trim((string)Globalvars::get_instance()->get_setting('smtp_host')));
        if ($host === '' || $host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            return '';
        }
        return 'a:' . $host;
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
        $mailer = new SmtpMailer($this->config);
        $mailer->applyMessage($message);

        // Protected-identity DKIM signing (specs/mailbox_outbound_send_protection.md).
        // Core names no mailbox symbol: it asks MailIdentityGuard, into which the
        // plugin registered a resolver, for a signer keyed on the From-domain. A
        // protected domain returns its in-app signer (unwrapped in-window); a
        // non-protected domain returns null (opendkim signs it, or it is unsigned);
        // a protected domain with no open window throws VaultLockedException, which
        // propagates so the compose path prompts an unlock rather than sending
        // unsigned. The raw-relay path (relayRawMessage) is untouched — it carries
        // the original sender's own signature and is not a mailbox compose.
        $sig = MailIdentityGuard::resolveDkimSigner(MailIdentityGuard::domainOf((string)$message->getFrom()));
        if ($sig !== null) {
            $mailer->DKIM_domain         = $sig['domain'];
            $mailer->DKIM_selector       = $sig['selector'];
            $mailer->DKIM_private_string = $sig['private_string']; // in-memory only, never a file
            $mailer->DKIM_identity       = (string)$message->getFrom();
        }

        $ok = $mailer->send();

        if ($sig !== null) {
            // The unwrapped signing key must not outlive the send — php-fpm
            // workers persist across requests (same discipline as SealedBox).
            sodium_memzero($sig['private_string']);
            sodium_memzero($mailer->DKIM_private_string);
            $mailer->DKIM_private_string = '';
        }

        if (!$ok) {
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
     * Uses this provider's injected SmtpConfig (default: the base smtp_* settings),
     * so the same class relays through the global SMTP credential, the inbound
     * forwarding relay (SmtpConfig::fromForwardingSettings()), or any other
     * configured transport without a second SMTP transaction implementation.
     */
    public function relayRawMessage(string $raw_mime, string $envelope_sender, array $destinations): array {
        $results = [];

        foreach ($destinations as $destination) {
            try {
                $mailer = new SmtpMailer($this->config);
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
