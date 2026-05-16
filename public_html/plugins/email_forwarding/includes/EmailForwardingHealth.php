<?php
/**
 * EmailForwardingHealth - provisioning check methods for the Email Forwarding
 * plugin's runtime dependencies.
 *
 * Each public static method here is a `code` provisioning check (see the
 * "Declaring host provisioners" section of docs/plugin_developer_guide.md).
 * A check method verifies one of the plugin's real runtime dependencies and
 * rethrows any failure as ProvisioningCheckFailed with a clean message. It
 * must be side-effect-free, time-bounded, and cheap to run.
 *
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('includes/ProvisioningCheckFailed.php'));
require_once(PathHelper::getIncludePath('plugins/email_forwarding/includes/EmailForwarder.php'));
require_once(PathHelper::getIncludePath('plugins/email_forwarding/data/email_forwarding_domain_class.php'));

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

    /**
     * Verify DNS records for every enabled forwarding domain.
     *
     * For each enabled, non-deleted domain it confirms an MX record exists and
     * an SPF (v=spf1) TXT record is published. DKIM is intentionally not checked
     * here — DKIM keys are generated per domain and are optional; the Domains
     * page surfaces DKIM separately.
     *
     * Lookups go through the system resolver, so with many domains this is the
     * slowest of the plugin's checks; it stays a quick check (two lookups per
     * domain, no DKIM selector scanning) and the async Plugins-page UI absorbs
     * the latency.
     *
     * @throws ProvisioningCheckFailed if any enabled domain is missing MX or SPF.
     */
    public static function checkDomainDns() {
        $domains = new MultiEmailForwardingDomain(['enabled' => true, 'deleted' => false]);
        $domains->load();

        if (count($domains) === 0) {
            return; // No forwarding domains configured — nothing to verify.
        }

        $problems = [];
        foreach ($domains as $domain) {
            $name = $domain->get('efd_domain');
            $issues = [];

            $mx = @dns_get_record($name, DNS_MX);
            if (empty($mx)) {
                $issues[] = 'missing MX';
            }

            $spf_found = false;
            $txt = @dns_get_record($name, DNS_TXT);
            if (!empty($txt)) {
                foreach ($txt as $record) {
                    if (stripos($record['txt'] ?? '', 'v=spf1') !== false) {
                        $spf_found = true;
                        break;
                    }
                }
            }
            if (!$spf_found) {
                $issues[] = 'missing SPF';
            }

            if ($issues) {
                $problems[] = $name . ' (' . implode(', ', $issues) . ')';
            }
        }

        if ($problems) {
            throw new ProvisioningCheckFailed(
                'DNS not configured for ' . count($problems) . ' of ' . count($domains)
                . ' forwarding domain(s): ' . implode('; ', $problems)
            );
        }
    }
}
