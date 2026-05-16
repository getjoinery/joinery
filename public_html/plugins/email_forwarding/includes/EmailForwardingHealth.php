<?php
/**
 * EmailForwardingHealth - provisioning check methods for the Email Forwarding
 * plugin's runtime dependencies.
 *
 * Each public static method here is a `code` provisioning check (see the
 * "Declaring host provisioners" section of docs/plugin_developer_guide.md).
 * A check method performs the plugin's REAL resource-acquisition step — so it
 * exercises the exact code path the feature uses — and rethrows any failure
 * as ProvisioningCheckFailed with a clean message. It must be side-effect-free,
 * time-bounded, and cheap to run.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ProvisioningCheckFailed.php'));
require_once(PathHelper::getIncludePath('plugins/email_forwarding/includes/EmailForwarder.php'));

class EmailForwardingHealth {

    /** Connection timeout, in seconds, applied to the relay check. */
    const RELAY_TIMEOUT = 5;

    /**
     * Verify the outbound SMTP relay used to forward mail can be reached and
     * authenticated. This calls EmailForwarder::createMailer() — the same
     * routine the forwarder itself uses to acquire its relay — then connects
     * and immediately closes. It sends nothing: it verifies acquisition, not
     * delivery.
     *
     * @throws ProvisioningCheckFailed if the relay cannot be acquired.
     */
    public static function checkForwardingRelay() {
        $forwarder = new EmailForwarder();
        $mailer = $forwarder->createMailer();

        // Bound the connection so a dead relay cannot hang the check — the
        // provisioning system cannot forcibly interrupt blocked PHP I/O.
        $mailer->Timeout = self::RELAY_TIMEOUT;

        try {
            $connected = $mailer->smtpConnect();
        } catch (\Throwable $e) {
            // An unexpected error from the SMTP library: rethrow it as the
            // expected dependency-failure signal so it reports as `unmet`.
            throw new ProvisioningCheckFailed('Forwarding relay error: ' . $e->getMessage());
        }

        if (!$connected) {
            $detail = $mailer->ErrorInfo !== '' ? $mailer->ErrorInfo : 'connection failed';
            $mailer->smtpClose();
            throw new ProvisioningCheckFailed('Could not connect to the forwarding SMTP relay: ' . $detail);
        }

        $mailer->smtpClose();
    }
}
