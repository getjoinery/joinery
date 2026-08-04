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
 * The inbound_mail_server and domain DNS checks are provider-aware: they
 * consult InboundProviderRegistry::active() and dispatch accordingly. The
 * spam-scanner check goes one step further and asks MailboxSpamPolicy, which
 * folds provider and topology into a single expected/present question.
 *
 * The relay outbound checks are MODE-aware (specs/mailbox_relay_inbound_only.md):
 * with the relay smarthost off (the default) compose rides the provider's API, so
 * checkOutboundTransportClass + checkOutboundOriginLeak apply and checkRelayTunnel
 * is a no-op; with smarthost on, checkRelayTunnel applies and the two provider
 * checks are no-ops. The check list always matches the chosen path.
 *
 * @version 1.14
 */

require_once(PathHelper::getIncludePath('includes/ProvisioningCheckFailed.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
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
        // Relay-fronted deployment (specs/…hardened_ingest_relay § Phase 8/9): the
        // MTA runs on the relay, not here — the main box's local port 25 is not a
        // health requirement. The relay's own port 25 / milters / tunnel are covered
        // by checkRelayTunnel. Setting-aware inversion
        // (specs/mailbox_listener_decommission.md): once the listener is recorded as
        // decommissioned, an ANSWERING port 25 is the failure — the attack surface
        // the decommission removed has come back.
        if (self::activeRelay() !== null) {
            $recorded = strtolower(trim((string)Globalvars::get_instance()->get_setting('mailbox_local_listener')));
            if ($recorded === 'decommissioned') {
                $sock = @stream_socket_client('tcp://127.0.0.1:25', $errno, $errstr, 2);
                if ($sock) {
                    @fclose($sock);
                    throw new ProvisioningCheckFailed(
                        'The local mail listener is recorded as decommissioned, but port 25 answers on this box — '
                        . 'decommission again (or Restore) from the Setup tab\'s Relay section.'
                    );
                }
            }
            return;
        }

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

    /** The active hardened ingest relay, or null on a colocated deployment. */
    private static function activeRelay() {
        require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));
        try {
            return MailboxRelay::active();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The relay's outbound mode: 'smarthost' (opt-in) or 'provider' (default).
     * Anything but an explicit 'smarthost' is 'provider'.
     */
    private static function relayOutboundMode(): string {
        $mode = strtolower(trim((string)Globalvars::get_instance()->get_setting('mailbox_relay_outbound_mode')));
        return $mode === 'smarthost' ? 'smarthost' : 'provider';
    }

    /** The custom header a compose origin-leak probe stamps so the check can find its round-tripped copy. */
    const ORIGIN_PROBE_HEADER = 'X-Joinery-Origin-Probe';

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
                    'Outgoing mail leaves through ' . $class::getLabel()
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
            throw new ProvisioningCheckFailed('Sending route error: ' . $e->getMessage());
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

    /**
     * Verify this server's own spam scanner
     * (specs/mailbox_spam_filtering_simplification.md D6/D7).
     *
     * The scanner SHIPS with the mail stack — install_email.sh installs it
     * unconditionally and the platform never removes it — so the rule is
     * simply: a box hosting its own mail stack must have a working, wired
     * scanner. There is no "installed" setting; presence is observed.
     *
     *   - No local mail stack (webhook-only, relay-fronted from birth): passes
     *     silently. Nothing of ours ever ran as root there, so nothing can be
     *     required; a hand-installed scanner (an operator opting such a box
     *     into learning) is equally fine.
     *   - Mail stack present, controller not answering: fails with the install
     *     command — the box was provisioned before the scanner shipped with
     *     the stack, or the service is down.
     *   - Direct-receiving (nothing upstream scans) with the scanner running
     *     but absent from Postfix's milter chain: fails — mail would flow
     *     unscored. Re-running the idempotent installer repairs the wiring.
     *
     * A missing scanner is never a delivery problem: ingest keeps whatever
     * verdict arrived with each message and the learn task defers its rows.
     *
     * @throws ProvisioningCheckFailed when the scanner is missing or unwired.
     */
    public static function checkContentSpamScanner() {
        require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxSpamPolicy.php'));

        if (!MailboxSpamPolicy::mailStackPresent()) {
            return;
        }

        if (!MailboxSpamPolicy::controllerReachable()) {
            $url = MailboxSpamPolicy::controllerUrl();
            throw new ProvisioningCheckFailed(
                'The spam scanner that ships with the mail stack is not answering ('
                . $url . '). Mail is unaffected — each message keeps whatever verdict '
                . 'arrived with it — but nothing is scored here and user spam/ham '
                . 'corrections are not being learned. Install or repair it with: '
                . MailboxSpamPolicy::installCommand()
            );
        }

        // Direct-receiving only: the scanner must actually be in Postfix's
        // milter chain. A relay-fronted or webhook box reaches it over HTTP at
        // ingest and needs no wiring — and a box whose scanner went in while
        // Postfix was decommissioned, then restored its own listener, is
        // exactly where this drift shows up.
        if (MailboxSpamPolicy::upstreamScanner() === 'none' && !MailboxSpamPolicy::milterWired()) {
            throw new ProvisioningCheckFailed(
                'The spam scanner is running but Postfix is not handing mail to it ('
                . MailboxSpamPolicy::MILTER_ENTRY . ' is missing from smtpd_milters), so inbound '
                . 'mail is never scored. Re-run the idempotent installer to repair the wiring: '
                . MailboxSpamPolicy::installCommand()
            );
        }
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

    // ---------------------------------------------------- hardened ingest relay

    /**
     * The relay accepts compose submission over the WireGuard tunnel. Port 25
     * listens on the relay in BOTH outbound modes (it is the same smtpd that
     * receives inbound mail), so a bare TCP connect proves nothing about
     * submission — the difference between the modes is whether permit_mynetworks
     * trusts the tunnel subnet to relay. This check therefore runs a real SMTP
     * dialogue: EHLO, MAIL FROM:<>, RCPT TO a reserved .invalid domain (never in
     * relay_domains, never deliverable), then QUIT without DATA. A 250 at RCPT
     * means the submission listener is open; a refusal means the relay was
     * provisioned inbound-only and needs a Rebuild to honor smarthost mode.
     *
     * SMARTHOST MODE ONLY: the tunnel carries compose submission only when the
     * relay smarthost is opted in (specs/mailbox_relay_inbound_only.md). In the
     * default provider mode nothing submits over the tunnel, so this is a no-op —
     * checkOutboundTransportClass covers that path instead. Also a no-op on
     * colocated deployments.
     */
    public static function checkRelayTunnel() {
        $relay = self::activeRelay();
        if ($relay === null || self::relayOutboundMode() !== 'smarthost') {
            return;
        }
        $host = trim((string)$relay->get('mrl_host'));
        if ($host === '') {
            throw new ProvisioningCheckFailed('The relay has no tunnel address configured yet.');
        }
        $sock = @stream_socket_client('tcp://' . $host . ':25', $errno, $errstr, self::RELAY_TIMEOUT);
        if (!$sock) {
            throw new ProvisioningCheckFailed(
                'The relay SMTP port 25 is not reachable over the tunnel (' . $host . '): '
                . ($errstr ?: 'connection refused') . ' — check WireGuard is up.'
            );
        }
        stream_set_timeout($sock, self::RELAY_TIMEOUT);
        try {
            $banner = self::smtpReadReply($sock);
            if (strpos($banner, '220') !== 0) {
                throw new ProvisioningCheckFailed('The relay did not greet as an SMTP server (got: ' . trim($banner) . ').');
            }
            $ehlo_name = trim((string)Globalvars::get_instance()->get_setting('mailbox_mail_hostname')) ?: 'joinery.internal';
            $reply = self::smtpCommand($sock, 'EHLO ' . $ehlo_name);
            if (strpos($reply, '250') !== 0) {
                throw new ProvisioningCheckFailed('The relay refused EHLO over the tunnel (got: ' . trim($reply) . ').');
            }
            $reply = self::smtpCommand($sock, 'MAIL FROM:<>');
            if (strpos($reply, '250') !== 0) {
                throw new ProvisioningCheckFailed('The relay refused MAIL FROM over the tunnel (got: ' . trim($reply) . ').');
            }
            // The probe recipient's domain is reserved (.invalid) so it is never in
            // relay_domains: only permit_mynetworks can accept it, which is exactly
            // the smarthost property under test. QUIT below — no DATA, nothing sent.
            $reply = self::smtpCommand($sock, 'RCPT TO:<smarthost-check@joinery-check.invalid>');
            if (strpos($reply, '250') !== 0) {
                throw new ProvisioningCheckFailed(
                    'The relay is reachable but refuses compose submission over the tunnel (got: ' . trim($reply)
                    . '). The relay was provisioned inbound-only — run Rebuild in the Setup tab\'s Relay section to open the '
                    . 'tunnel submission listener.'
                );
            }
        } finally {
            @fwrite($sock, "QUIT\r\n");
            @fclose($sock);
        }
    }

    /** Send one SMTP command and return the (possibly multiline) reply's final line. */
    private static function smtpCommand($sock, string $command): string {
        fwrite($sock, $command . "\r\n");
        return self::smtpReadReply($sock);
    }

    /**
     * Read one SMTP reply, consuming continuation lines ("250-…") until the final
     * "250 …" line, which is returned. '' on read failure/timeout.
     */
    private static function smtpReadReply($sock): string {
        while (($line = fgets($sock, 1024)) !== false) {
            if (preg_match('/^\d{3}(?: |\r|\n|$)/', $line)) {
                return $line;
            }
            // "NNN-..." continuation — keep reading to the final line.
            if (!preg_match('/^\d{3}-/', $line)) {
                return $line; // not an SMTP reply at all; surface it
            }
        }
        return '';
    }

    /**
     * The relay spool is draining: a pull ran recently. A stalled pull means mail
     * is accumulating (sealed) on the relay and not reaching the inbox. No-op on
     * colocated deployments.
     */
    public static function checkRelaySpoolDraining() {
        $relay = self::activeRelay();
        if ($relay === null) {
            return;
        }
        $last = trim((string)$relay->get('mrl_last_pull_time'));
        if ($last === '') {
            throw new ProvisioningCheckFailed('The relay spool has never been pulled — is the relay reconcile task enabled?');
        }
        // The pull runs every cron pass; the threshold allows several missed
        // 5-minute passes before calling it stalled.
        if (strtotime($last . ' UTC') < time() - 1800) {
            throw new ProvisioningCheckFailed(
                'The relay spool has not been pulled since ' . $last . ' UTC (over 30 minutes) — the pull task may be stalled.'
            );
        }
    }

    /**
     * No recoverable mail is stranded on the relay. The pull HOLDS (does not
     * delete) blobs whose domain is disabled/unconfigured or whose Fortress
     * owner is not yet resolvable, so an operator needs to see when mail is
     * waiting — re-enabling the domain (or restoring the grant/vault) drains it
     * on the next pull; left alone it ages out past the grace window. No-op on
     * colocated deployments (specs/mailbox_data_loss_fixes.md, Fixes 6/7).
     */
    public static function checkRelaySpoolHeld() {
        $relay = self::activeRelay();
        if ($relay === null) {
            return;
        }
        $held = intval($relay->get('mrl_last_pull_held'));
        if ($held > 0) {
            throw new ProvisioningCheckFailed(
                $held . ' message(s) are held on the relay — their domain is disabled/unconfigured '
                . 'or their mailbox owner is not yet resolvable. Re-enable the domain (or restore the '
                . 'grant/vault) and they store on the next pull; otherwise they age out after the grace window.'
            );
        }
    }

    /**
     * The relay's alias map is fresh: the map the relay is running matches what the
     * current domains/aliases would produce. A stale map risks bouncing newly
     * created aliases (reject_unmatched). No-op on colocated deployments.
     */
    public static function checkRelayMapFresh() {
        $relay = self::activeRelay();
        if ($relay === null) {
            return;
        }
        require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayMapSync.php'));
        try {
            $artifacts = (new RelayMapExporter($relay))->build();
        } catch (\Throwable $e) {
            throw new ProvisioningCheckFailed('Could not build the relay alias map to check freshness: ' . $e->getMessage());
        }
        if (RelayMapSync::contentHash($artifacts) !== (string)$relay->get('mrl_map_content_hash')) {
            throw new ProvisioningCheckFailed(
                'The relay alias map is out of date — the relay reconcile task has not pushed the latest domains/aliases yet.'
            );
        }
    }

    /**
     * Deployment-wide origin-hiding check: once a relay exists, the main box's
     * public IP must not appear in ANY hosted domain's mail DNS (MX or the mail
     * hostname A record) — a single leak defeats the hidden origin. Not
     * Fortress-only. No-op on colocated deployments.
     */
    public static function checkOriginHidden() {
        $relay = self::activeRelay();
        if ($relay === null) {
            return;
        }
        // Before the DNS cutover completes, the box's address is expected in
        // mail DNS — the Setup tab rows walk the move. Assert only once the
        // recorded cutover verdict says the relay fronts everything.
        if ((string)Globalvars::get_instance()->get_setting('mailbox_relay_cutover_complete') !== '1') {
            return;
        }
        $settings = Globalvars::get_instance();
        $origin_ip = trim((string)$settings->get_setting('mailbox_public_ip'));
        if ($origin_ip === '') {
            return; // unknown origin IP — nothing to assert against
        }

        $domains = new MultiInboundEmailDomain(array('enabled' => true, 'deleted' => false));
        $domains->load();
        $leaks = array();
        foreach ($domains as $domain) {
            $name = trim((string)$domain->get('ied_domain'));
            if ($name === '') {
                continue;
            }
            try {
                foreach (DnsResolver::getMx($name) as $mx) {
                    $target = (string)($mx['host'] ?? '');
                    if ($target === '') {
                        continue;
                    }
                    if (in_array($origin_ip, DnsResolver::getA($target), true)) {
                        $leaks[] = $name . ' (MX ' . $target . ' → ' . $origin_ip . ')';
                    }
                }
                // SPF TXT can also expose the origin: a v=spf1 record still listing
                // the main box IP (ip4:<origin>) leaks it even with the MX moved
                // (specs/mailbox_relay_fix_pack.md § additional gap).
                foreach (DnsResolver::getTxt($name) as $txt) {
                    if (stripos($txt, 'v=spf1') === false) {
                        continue;
                    }
                    if (self::spfNamesIp($txt, $origin_ip)) {
                        $leaks[] = $name . ' (SPF lists ' . $origin_ip . ')';
                    }
                }
            } catch (\Throwable $e) {
                // A transient resolver failure is not an origin leak; skip this domain.
                continue;
            }
        }
        if (!empty($leaks)) {
            throw new ProvisioningCheckFailed(
                'The main box IP (' . $origin_ip . ') is present in mail DNS for: ' . implode(', ', array_unique($leaks))
                . ' — point every hosted domain\'s MX at the relay and drop the origin from SPF to keep it hidden.'
            );
        }
    }

    /** True if an SPF record names $ip via an ip4:/ip6: mechanism (network part match). */
    private static function spfNamesIp(string $spf, string $ip): bool {
        foreach (preg_split('/\s+/', trim($spf)) as $token) {
            $t = ltrim($token, '+-~?');
            if (stripos($t, 'ip4:') === 0 || stripos($t, 'ip6:') === 0) {
                $addr = explode('/', substr($t, 4), 2)[0];
                if (strcasecmp($addr, $ip) === 0) {
                    return true;
                }
            }
        }
        return false;
    }

    // ------------------------------------------- relay outbound (inbound-only default)

    /**
     * The active outbound provider can carry hidden-origin compose mail: it must
     * submit over an HTTP API (ApiSubmissionRelay), not SMTP. SMTP submission
     * stamps the box IP into the first Received: header and defeats the hidden
     * origin. PROVIDER MODE ONLY (the default): no-op in smarthost mode (the
     * tunnel check covers that) and on colocated deployments.
     * (specs/mailbox_relay_inbound_only.md § Setup checks.)
     */
    public static function checkOutboundTransportClass() {
        $relay = self::activeRelay();
        if ($relay === null || self::relayOutboundMode() !== 'provider') {
            return;
        }
        require_once(PathHelper::getIncludePath('includes/EmailServiceProvider.php'));
        require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
        $provider = EmailSender::getActiveProvider();
        if ($provider === null) {
            throw new ProvisioningCheckFailed(
                'No outbound email provider is configured — hidden-origin compose mail has nothing to leave through.');
        }
        if (!($provider instanceof ApiSubmissionRelay)) {
            $label = method_exists($provider, 'getLabel') ? $provider::getLabel() : get_class($provider);
            throw new ProvisioningCheckFailed(
                'The active outbound provider (' . $label . ') submits over SMTP, which would stamp this server\'s '
                . 'IP into sent mail and defeat the hidden origin. Use an API provider (Mailgun or Amazon SES), '
                . 'or switch sent mail to leave through the relay.');
        }
    }

    /**
     * Out-and-back origin-leak probe. When a compose origin-leak probe has been
     * round-tripped (sent from a hosted alias out through the real outbound path
     * and back in via the relay MX — see sendOriginProbe), scan its delivered
     * headers for this box's public IP or its internal hostname (gethostname()).
     * Pass = absent. This checks the FACT (no leak) rather than the MECHANISM
     * (API vs SMTP), so it also catches a provider that starts stamping the
     * submitter's IP. PROVIDER MODE ONLY; no-op with no probe yet delivered.
     */
    public static function checkOutboundOriginLeak() {
        $relay = self::activeRelay();
        if ($relay === null || self::relayOutboundMode() !== 'provider') {
            return;
        }
        $raw = self::latestOriginProbeRaw();
        if ($raw === '') {
            // No probe delivered yet (or its raw is sealed and unreadable) — nothing
            // to assert. The relay tab offers a "Run origin-leak probe" button.
            return;
        }
        $settings = Globalvars::get_instance();
        $origin_ip = trim((string)$settings->get_setting('mailbox_public_ip'));
        $leaks = self::scanHeadersForOrigin($raw, $origin_ip, (string)gethostname());
        if (!empty($leaks)) {
            throw new ProvisioningCheckFailed(
                'The last outbound origin-leak probe found this server exposed in the delivered headers: '
                . implode('; ', $leaks) . ' — sent mail must not carry the main box IP or hostname.');
        }
    }

    /**
     * The raw MIME of the most recently delivered origin-leak probe (carries the
     * ORIGIN_PROBE_HEADER marker), or '' when none is readable. Side-effect-free.
     */
    private static function latestOriginProbeRaw(): string {
        try {
            $db = DbConnector::get_instance()->get_db_link();
            // The time window keeps the ILIKE scan bounded as the table grows —
            // a probe older than a week is stale evidence anyway.
            $stmt = $db->prepare(
                "SELECT iem_raw_message FROM iem_inbound_email_messages
                 WHERE iem_received_time >= ? AND iem_raw_message ILIKE ?
                 ORDER BY iem_received_time DESC LIMIT 1");
            $stmt->execute(array(
                LibraryFunctions::time_shift(gmdate('Y-m-d H:i:s'), '-7 days', 'Y-m-d H:i:s'),
                '%' . self::ORIGIN_PROBE_HEADER . '%',
            ));
            return (string)($stmt->fetchColumn() ?: '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Scan a raw message's HEADER BLOCK (up to the first blank line) for the
     * origin IP or internal hostname, returning a human-readable leak description
     * per needle found (empty array = clean). Pure and side-effect-free so it is
     * unit-testable in isolation — the security-critical core of the probe. The
     * mail hostname is deliberately NOT a needle: on a relay-fronted deployment it
     * names the relay and is expected in the chain.
     */
    public static function scanHeadersForOrigin(string $raw, string $origin_ip, string $internal_hostname): array {
        $normalized = str_replace("\r\n", "\n", $raw);
        $split = strpos($normalized, "\n\n");
        $block = ($split !== false) ? substr($normalized, 0, $split) : $normalized;

        // Each needle is matched on token boundaries so a needle never matches
        // inside a LONGER token: 203.0.113.7 must not flag 203.0.113.78, and a
        // hostname must not flag a distinct FQDN that merely contains it.
        $needles = array();
        $origin_ip = trim($origin_ip);
        if ($origin_ip !== '') {
            $needles['this server\'s IP (' . $origin_ip . ')'] =
                '/(?<![0-9.])' . preg_quote($origin_ip, '/') . '(?![0-9.])/';
        }
        // A very short hostname would false-positive against unrelated tokens; a
        // real host FQDN is well over this floor.
        $internal_hostname = trim($internal_hostname);
        if (strlen($internal_hostname) >= 4) {
            $needles['this server\'s hostname (' . $internal_hostname . ')'] =
                '/(?<![A-Za-z0-9._-])' . preg_quote($internal_hostname, '/') . '(?![A-Za-z0-9-])/i';
        }

        $leaks = array();
        foreach ($needles as $desc => $needle) {
            foreach (explode("\n", $block) as $line) {
                if (preg_match($needle, $line)) {
                    $colon = strpos($line, ':');
                    $hdr = ($colon !== false && ($line === '' || !($line[0] === ' ' || $line[0] === "\t")))
                        ? trim(substr($line, 0, $colon)) : 'a folded header';
                    $leaks[] = $desc . ' in the ' . $hdr . ' header';
                    break;
                }
            }
        }
        return $leaks;
    }

    /**
     * Send an out-and-back origin-leak probe: a message from a hosted alias to
     * itself, marked with ORIGIN_PROBE_HEADER, through the REAL outbound path
     * (resolveOutboundTransport → the provider's API in the default mode). It
     * leaves via the provider and returns via the relay MX; checkOutboundOriginLeak
     * then scans the delivered copy. Relay-fronted + provider mode only.
     *
     * @return array{ok:bool,message:string}
     */
    public static function sendOriginProbe(): array {
        $relay = self::activeRelay();
        if ($relay === null) {
            return array('ok' => false, 'message' => 'No active relay — the origin-leak probe only applies to a relay-fronted deployment.');
        }
        if (self::relayOutboundMode() !== 'provider') {
            return array('ok' => false, 'message' => 'Sent mail leaves through the relay; the tunnel-SMTP check covers that path.');
        }

        // The probe target must be a REAL enabled store-mode alias: the relay's
        // SMTP-time recipient validation only accepts listed aliases (an invented
        // address would bounce under reject_unmatched and the round trip would
        // silently never complete), and only a stored delivery lands in
        // iem_inbound_email_messages where checkOutboundOriginLeak can find it.
        // Fortress domains are skipped — their delivered raw is sealed to the
        // owner's key, so the server could never scan it.
        $address = self::originProbeTarget();
        if ($address === '') {
            return array('ok' => false, 'message' => 'No enabled store-mode alias on a Standard or Private domain to '
                . 'receive the probe — create one (delivery mode "Store") and run the probe again.');
        }

        require_once(PathHelper::getIncludePath('includes/OutboundTransport.php'));
        require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
        require_once(PathHelper::getIncludePath('includes/EmailMessage.php'));

        $token = bin2hex(random_bytes(8));

        $result = resolveOutboundTransport($address);
        if ($result->transport === null && $result->error) {
            return array('ok' => false, 'message' => $result->error);
        }

        $msg = new EmailMessage();
        $msg->from($address, 'Origin Probe')
            ->to($address)
            ->subject('Joinery origin-leak probe ' . $token)
            ->text('Automated origin-leak probe (' . $token . '). Safe to ignore.')
            ->header(self::ORIGIN_PROBE_HEADER, $token);

        try {
            $ok = (new EmailSender())->send($msg, false, $result->transport);
        } catch (\Throwable $e) {
            return array('ok' => false, 'message' => 'Probe send failed: ' . $e->getMessage());
        }
        if (!$ok) {
            return array('ok' => false, 'message' => 'Probe send failed — the provider rejected the message.');
        }
        return array('ok' => true, 'message' => 'Probe sent from ' . $address
            . ' to itself. It leaves via the provider and returns via the relay MX; re-check in a minute.');
    }

    /**
     * The full address of the alias an origin-leak probe is sent to (and from):
     * the first enabled store-capable alias (store or forward_and_store) on an
     * enabled non-Fortress domain, or '' when none exists. Non-Fortress because
     * the delivered copy must be server-readable for the header scan; a listed
     * alias because the relay's recipient validation rejects anything else.
     */
    private static function originProbeTarget(): string {
        $domains = new MultiInboundEmailDomain(array('enabled' => true, 'deleted' => false), array('ied_domain' => 'ASC'));
        $domains->load();
        require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
        foreach ($domains as $domain) {
            if ($domain->security_level() === InboundEmailDomain::LEVEL_FORTRESS) {
                continue;
            }
            $aliases = new MultiInboundEmailAlias(
                array('domain_id' => intval($domain->key), 'enabled' => true, 'deleted' => false),
                array('iea_alias' => 'ASC'));
            $aliases->load();
            foreach ($aliases as $alias) {
                $mode = (string)$alias->get('iea_delivery_mode');
                if ($mode === InboundEmailAlias::MODE_STORE || $mode === InboundEmailAlias::MODE_FORWARD_AND_STORE) {
                    return strtolower(trim((string)$alias->get('iea_alias'))) . '@'
                        . strtolower(trim((string)$domain->get('ied_domain')));
                }
            }
        }
        return '';
    }
}
