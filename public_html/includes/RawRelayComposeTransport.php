<?php
/**
 * RawRelayComposeTransport - the hidden-origin compose transport for a
 * relay-fronted deployment with the relay smarthost OFF (the default:
 * specs/mailbox_relay_inbound_only.md).
 *
 * The relay defaults to inbound-only, so compose sends leave through the
 * deployment's configured outbound provider instead of the relay smarthost.
 * SMTP submission would stamp the main box's IP into the delivered message's
 * first Received: header and defeat the hidden origin; an HTTP-API raw-message
 * submission does not. This transport therefore builds the FULLY FORMED,
 * app-signed RFC 5322 message and hands it to the active provider's
 * relayRawMessage() (the ApiSubmissionRelay capability), so the message's
 * Received: chain begins inside the provider's infrastructure.
 *
 * It is an EmailServiceProvider so it slots into the ONE EmailSender pipeline as
 * an injected $transport (EmailSender::send($msg, true, $transport)) exactly like
 * SmtpProvider — which is also what marks the send as the session-gated compose
 * path for EmailSender's ambient-send guard, and keys the in-app DKIM signing off
 * the From-domain identically to the direct-submission branch.
 *
 *   - Protected domains: DKIM signed in-app (MailIdentityGuard's registered
 *     signer, unwrapped in-window). A protected From-domain with no open unlock
 *     window makes the signer throw VaultLockedException, which propagates so the
 *     compose path prompts a one-tap unlock rather than sending unsigned.
 *   - Non-protected hosted domains: no in-app signature (the provider's normal
 *     alignment for the domain applies if it is verified there).
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/EmailServiceProvider.php'));
require_once(PathHelper::getIncludePath('includes/SmtpMailer.php'));
require_once(PathHelper::getIncludePath('includes/MailIdentityGuard.php'));

class RawRelayComposeTransport implements EmailServiceProvider {

    /** @var string Envelope sender (MAIL FROM); '' falls back to the message From. */
    private $envelopeSender;

    /** @var EmailServiceProvider|null The resolved API relay; null = resolve the active provider at send. */
    private $provider;

    /**
     * @param string $envelopeSender  MAIL FROM. For a protected domain the caller
     *        passes the forwarding-subdomain envelope so the protected domain's
     *        own v=spf1 -all never touches the envelope. '' = use the From header.
     * @param EmailServiceProvider|null $provider  The active provider to relay
     *        through (already confirmed API-class by the resolver). Null resolves
     *        EmailSender::getActiveProvider() lazily.
     */
    public function __construct(string $envelopeSender = '', ?EmailServiceProvider $provider = null) {
        $this->envelopeSender = $envelopeSender;
        $this->provider = $provider;
    }

    public static function getKey(): string {
        return 'raw-relay';
    }

    public static function getLabel(): string {
        return 'Raw-MIME relay (hidden origin)';
    }

    public static function getSettingsFields(): array {
        return [];
    }

    public static function validateConfiguration(): array {
        // This transport carries no settings of its own — it relays through the
        // active provider, whose configuration is validated in its own right.
        return ['valid' => true, 'errors' => []];
    }

    public function send(EmailMessage $message): bool {
        require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
        $provider = $this->provider ?: EmailSender::getActiveProvider();

        // API-class self-declaration is the whole point: SMTP submission would
        // leak the origin, so a non-ApiSubmissionRelay provider must not carry
        // this send. The resolver already checked, but re-assert defensively.
        if (!($provider instanceof ApiSubmissionRelay)) {
            error_log('[RawRelayComposeTransport] active provider is not an API raw-message relay — '
                . 'cannot send hidden-origin compose mail. Switch providers or enable relay smarthost.');
            return false;
        }

        $mailer = new SmtpMailer();
        $mailer->applyMessage($message);

        // Generated headers (Message-ID host, and any Received the library adds)
        // must derive from the MAIL HOSTNAME — which points at the relay — never
        // gethostname()/the box IP, or a generated header would leak the very
        // origin the relay exists to hide (⟨VERIFY⟩, mailbox_relay_inbound_only).
        $settings = Globalvars::get_instance();
        $mail_host = trim((string)$settings->get_setting('mailbox_mail_hostname'))
            ?: (trim((string)$settings->get_setting('smtp_hostname'))
            ?: trim((string)$settings->get_setting('smtp_helo')));
        if ($mail_host !== '') {
            $mailer->Hostname = $mail_host;
        }

        // In-app DKIM for a protected From-domain (specs/mailbox_outbound_send_protection.md).
        // resolveDkimSigner throws VaultLockedException for a protected domain with
        // no open window — deliberately OUTSIDE the try below so it propagates to the
        // compose path's unlock prompt instead of being swallowed as a send failure.
        $sig = MailIdentityGuard::resolveDkimSigner(MailIdentityGuard::domainOf((string)$message->getFrom()));
        if ($sig !== null) {
            $mailer->DKIM_domain         = $sig['domain'];
            $mailer->DKIM_selector       = $sig['selector'];
            $mailer->DKIM_private_string = $sig['private_string']; // in-memory only, never a file
            $mailer->DKIM_identity       = (string)$message->getFrom();
        }

        try {
            // preSend() assembles the MIME and, when DKIM_* is set, prepends the
            // in-app DKIM-Signature — the same assembly SES's raw path uses.
            $mailer->preSend();
            $raw = $mailer->getSentMIMEMessage();
        } catch (\Exception $e) {
            error_log('[RawRelayComposeTransport] failed to assemble message: ' . $e->getMessage());
            return false;
        } finally {
            if ($sig !== null) {
                // The unwrapped signing key must not outlive the send — php-fpm
                // workers persist across requests (same discipline as SmtpProvider).
                sodium_memzero($sig['private_string']);
                if ($mailer->DKIM_private_string !== '') {
                    sodium_memzero($mailer->DKIM_private_string);
                    $mailer->DKIM_private_string = '';
                }
            }
        }

        $destinations = $this->destinationsFor($message);
        if (empty($destinations)) {
            error_log('[RawRelayComposeTransport] no envelope recipients resolved.');
            return false;
        }

        $envelope = $this->envelopeSender !== '' ? $this->envelopeSender : (string)$message->getFrom();

        $results = $provider->relayRawMessage($raw, $envelope, $destinations);
        foreach ($destinations as $destination) {
            if (empty($results[$destination])) {
                error_log('[RawRelayComposeTransport] provider relay failed for ' . $destination);
                return false;
            }
        }
        return true;
    }

    public function sendBatch(EmailMessage $message, array $recipients): array {
        $failed = [];
        foreach ($recipients as $email) {
            $individual = new EmailMessage();
            $individual->to($email)
                       ->subject($message->getSubject())
                       ->from($message->getFrom(), $message->getFromName());
            if ($message->getHtmlBody()) {
                $individual->html($message->getHtmlBody());
            } else {
                $individual->text($message->getTextBody());
            }
            try {
                if (!$this->send($individual)) {
                    $failed[] = $email;
                }
            } catch (\Exception $e) {
                error_log('[RawRelayComposeTransport] batch send failed for ' . $email . ': ' . $e->getMessage());
                $failed[] = $email;
            }
        }
        return ['success' => empty($failed), 'failed_recipients' => $failed];
    }

    /** All envelope recipients (to + cc + bcc), de-duplicated. */
    private function destinationsFor(EmailMessage $message): array {
        $dests = [];
        foreach ($message->getRecipients() as $r) {
            if (!empty($r['email'])) { $dests[] = $r['email']; }
        }
        foreach ($message->getCc() as $r) {
            if (!empty($r['email'])) { $dests[] = $r['email']; }
        }
        foreach ($message->getBcc() as $r) {
            if (!empty($r['email'])) { $dests[] = $r['email']; }
        }
        return array_values(array_unique($dests));
    }
}
