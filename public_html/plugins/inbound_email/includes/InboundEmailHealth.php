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
 * The inbound_mail_server and domain DNS checks are provider-aware:
 * they consult InboundProviderRegistry::active() and dispatch accordingly.
 *
 * @version 1.4
 */

require_once(PathHelper::getIncludePath('includes/ProvisioningCheckFailed.php'));
require_once(PathHelper::getIncludePath('includes/DnsResolver.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/InboundEmailRouter.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/InboundProviderRegistry.php'));

class InboundEmailHealth {

    /**
     * Verify the inbound mail server / receiving infrastructure.
     *
     * For Postfix: same as before — port 25 must be listening locally.
     * For webhook providers (Mailgun): not applicable; passes silently
     * because the provider's own infrastructure handles MX.
     */
    public static function checkInboundMailServer() {
        $provider = InboundProviderRegistry::active();
        if ($provider::isWebhook()) {
            // Webhook-based providers don't run a local mail server. The provider's
            // own DNS / signing key are checked by checkDomainDns + the Setup tab.
            return;
        }

        // Postfix-style: local SMTP port 25 must be accepting connections.
        $sock = @stream_socket_client('tcp://127.0.0.1:25', $errno, $errstr, 2);
        if (!$sock) {
            throw new ProvisioningCheckFailed(
                'Local SMTP (port 25) is not accepting connections: ' . ($errstr ?: 'connection refused')
            );
        }
        @fclose($sock);
    }

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

        $provider = InboundProviderRegistry::active();

        $multi = new MultiInboundEmailDomain(array('deleted' => false, 'enabled' => true), array('ied_domain' => 'ASC'));
        $multi->load();

        $problems = [];
        foreach ($multi as $d) {
            foreach ($provider::getSetupChecks($d->get('ied_domain')) as $r) {
                // Only per-domain layers count toward this provisioner; the
                // host / mailhost layers belong to checkInboundMailServer().
                if ($r['layer'] !== 'domain') {
                    continue;
                }
                if ($r['severity'] === InboundEmailSetupCheck::REQUIRED
                    && $r['status'] === InboundEmailSetupCheck::FAIL) {
                    $problems[] = $r['scope'] . ' — ' . $r['summary'];
                }
            }
        }

        if ($problems) {
            throw new ProvisioningCheckFailed(
                count($problems) . ' inbound-domain setup problem(s): ' . implode('; ', $problems)
            );
        }
    }
}
