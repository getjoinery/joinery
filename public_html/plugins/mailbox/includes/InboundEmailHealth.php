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
 * The inbound_mail_server, domain DNS, and content-spam-scanner checks are
 * provider-aware: they consult InboundProviderRegistry::active() and dispatch
 * accordingly.
 *
 * @version 1.6
 */

require_once(PathHelper::getIncludePath('includes/ProvisioningCheckFailed.php'));
require_once(PathHelper::getIncludePath('includes/DnsResolver.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundProviderRegistry.php'));

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
     * Verify the relay used to forward mail. The router resolves one of two
     * paths (resolveRelayProvider):
     *
     *   - Provider raw-MIME relay (Mailgun/SMTP/SES) — verify the provider's
     *     own configuration is complete. A healthy provider credential passes
     *     even when the legacy smtp_* settings are empty.
     *   - SMTP fallback — acquire the relay via createMailer(), connect, and
     *     immediately close. It sends nothing: it verifies acquisition, not
     *     delivery.
     *
     * @throws ProvisioningCheckFailed if the resolved relay cannot be verified.
     */
    public static function checkForwardingRelay() {
        $router = new InboundEmailRouter();

        // Provider raw-MIME relay active: verify the provider credential.
        $relay = $router->resolveRelayProvider();
        if ($relay !== null) {
            $class = get_class($relay);
            $validation = $class::validateConfiguration();
            if (empty($validation['valid'])) {
                throw new ProvisioningCheckFailed(
                    'Forwarding relays through ' . $class::getLabel()
                    . ', but its configuration is incomplete: '
                    . implode('; ', $validation['errors'] ?? array()));
            }
            return;
        }

        // SMTP fallback relay.
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
        require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailSetupCheck.php'));

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

    /** Port the rspamd milter (proxy worker) listens on locally. */
    const RSPAMD_MILTER_PORT = 11332;

    /**
     * Verify the content spam scanner (specs/inbound_email_content_spam_filtering.md).
     *
     * Optional feature, provider-aware:
     *   - Disabled (mailbox_content_spam_filtering_enabled off) → nothing to
     *     verify; passes silently.
     *   - Webhook providers (Mailgun/SendGrid/SES) get the content-spam signal from the
     *     provider's own upstream scanning — there is no local milter — so this passes.
     *   - Postfix path: the rspamd milter must be listening locally (same shape as
     *     checkInboundMailServer's port-25 probe).
     *
     * @throws ProvisioningCheckFailed if the local rspamd milter is unreachable.
     */
    public static function checkContentSpamScanner() {
        $settings = Globalvars::get_instance();
        if (!$settings->get_setting('mailbox_content_spam_filtering_enabled')) {
            return;
        }

        $provider = InboundProviderRegistry::active();
        if ($provider::isWebhook()) {
            return;
        }

        $sock = @stream_socket_client('tcp://127.0.0.1:' . self::RSPAMD_MILTER_PORT, $errno, $errstr, 2);
        if (!$sock) {
            throw new ProvisioningCheckFailed(
                'The rspamd content-spam milter (port ' . self::RSPAMD_MILTER_PORT
                . ') is not accepting connections: ' . ($errstr ?: 'connection refused')
            );
        }
        @fclose($sock);
    }

    /**
     * Verify ext-sqlite3 is loaded WITH FTS5 compiled in — MailboxIndex's
     * sealed search index (specs/implemented/inbound_email_encryption_at_rest.md
     * § 6) has no fallback: without it, search on a sealed mailbox is simply
     * unavailable (the reader surfaces this, not a 500). The APCu/swap host
     * hardening this feature also depends on is the vault's own VaultHealth
     * check (includes/VaultHealth.php), not repeated here.
     *
     * @throws ProvisioningCheckFailed if ext-sqlite3 is missing, or FTS5 is
     *         not compiled into the linked sqlite3 library.
     */
    public static function checkSearchIndexEngine() {
        if (!class_exists('SQLite3')) {
            throw new ProvisioningCheckFailed(
                'ext-sqlite3 is not loaded — the sealed mailbox search index (MailboxIndex) requires it.'
            );
        }
        try {
            $db = new SQLite3(':memory:');
            $db->exec('CREATE VIRTUAL TABLE t USING fts5(x)');
            $db->close();
        } catch (\Throwable $e) {
            throw new ProvisioningCheckFailed(
                'ext-sqlite3 is loaded but FTS5 is not compiled in: ' . $e->getMessage()
            );
        }
    }
}
