<?php
/**
 * InboundEmailHealth - provisioning check methods for the Inbound Email
 * plugin's runtime dependencies.
 *
 * Each public static method here is a `code` provisioning check (see the
 * "Declaring host provisioners" section of docs/plugin_developer_guide.md).
 * A check method verifies one of the plugin's real runtime dependencies and
 * rethrows any failure as ProvisioningCheckFailed with a clean message. It
 * must be side-effect-free, time-bounded, and cheap to run.
 *
 * @version 1.3
 */

require_once(PathHelper::getIncludePath('includes/ProvisioningCheckFailed.php'));
require_once(PathHelper::getIncludePath('includes/DnsResolver.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/InboundEmailRouter.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_domain_class.php'));

class InboundEmailHealth {

    /** Connection timeout, in seconds, applied to the relay check. */
    const RELAY_TIMEOUT = 5;

    /**
     * Verify the outbound SMTP relay used to forward mail can be reached and
     * authenticated. This calls InboundEmailRouter::createMailer() — the same
     * routine the router itself uses to acquire its relay — then connects
     * and immediately closes. It sends nothing: it verifies acquisition, not
     * delivery.
     *
     * @throws ProvisioningCheckFailed if the relay cannot be acquired.
     */
    public static function checkForwardingRelay() {
        $router = new InboundEmailRouter();
        $mailer = $router->createMailer();

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

    /**
     * Verify DNS and host setup for every enabled inbound domain.
     *
     * Delegates to InboundEmailSetupCheck — the single verification engine the
     * Setup tab also uses — and fails the provisioner if any required
     * per-domain check (MX exists / not a CNAME / resolves / points here, SPF
     * exists / authorizes, no mydestination conflict) is failing. Recommended
     * items (DKIM, DMARC) surface on the Setup tab but never fail this check.
     *
     * Scoped to the per-domain layer only, so it does not pay for the host or
     * outbound-relay checks — those are separate provisioners.
     *
     * @throws ProvisioningCheckFailed if any enabled domain has a required failure.
     */
    public static function checkDomainDns() {
        require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/InboundEmailSetupCheck.php'));

        $checker = new InboundEmailSetupCheck();
        $results = $checker->runDomainChecks();

        $problems = [];
        foreach ($results as $r) {
            if ($r['severity'] === InboundEmailSetupCheck::REQUIRED
                && $r['status'] === InboundEmailSetupCheck::FAIL) {
                $problems[] = $r['scope'] . ' — ' . $r['summary'];
            }
        }

        if ($problems) {
            throw new ProvisioningCheckFailed(
                count($problems) . ' inbound-domain setup problem(s): ' . implode('; ', $problems)
            );
        }
    }
}
