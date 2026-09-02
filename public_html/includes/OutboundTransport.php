<?php
/**
 * OutboundTransport - resolve "how do I send AS this mailbox?" into a configured
 * transport for the one EmailSender pipeline (§7).
 *
 * The system provider (§4) and the per-mailbox send (reader reply/forward) are
 * two ways to OBTAIN a transport, not two send implementations. This class is the
 * per-mailbox resolver: given a mailbox it returns a configured
 * EmailServiceProvider plus the identity to send as and whether the provider's
 * SMTP auto-files the Sent copy. The caller hands the transport to
 * EmailSender::send($msg, true, $transport) — the same path as the system
 * provider, never around it.
 *
 *   - IMAP-source mailbox (a connected account): SmtpProvider configured with
 *     SmtpConfig::fromConnectedAccount() — XOAUTH2 for Gmail/M365, app password
 *     for Yahoo/iCloud/Fastmail. From is the feed address.
 *   - Hosted alias (alias@our-domain): no source mailbox — `transport` is null,
 *     meaning "use the platform's active provider" (our domain's DKIM + SRS). A
 *     connected account cannot send AS a hosted alias (its SMTP forces its own
 *     From), so a relay-class provider is required; that is the global default.
 *
 * `filesSent` is the PRESETS smtp_files_sent capability: true when the provider's
 * SMTP saves the sent copy itself; false when two-way sync must APPEND it.
 *
 * @version 1.5 - forHostedAlias() refuses an address on an IMAP-source
 *   domain: mail from a connected account leaves only through that account's
 *   own SMTP, never platform egress (specs/imap_source_domain_boundaries.md §5)
 * @version 1.4
 */

require_once(PathHelper::getIncludePath('includes/EmailServiceProvider.php'));
require_once(PathHelper::getIncludePath('includes/SmtpConfig.php'));
require_once(PathHelper::getIncludePath('includes/email_providers/SmtpProvider.php'));
require_once(PathHelper::getIncludePath('includes/MailIdentityGuard.php'));

class OutboundTransport {

    /** @var EmailServiceProvider|null Configured transport; null = use the platform's active provider. */
    public $transport = null;

    /** @var string|null The address to send AS (forced From), or null to leave the caller's From. */
    public $fromAddress = null;

    /** @var bool Whether the provider's SMTP auto-files the sent copy (else two-way sync APPENDs it). */
    public $filesSent = false;

    /** @var string|null Why a usable transport could not be resolved (caller surfaces it). */
    public $error = null;

    /**
     * Resolve a transport for an IMAP-source mailbox (a connected account). The
     * From identity is the feed address; the transport authenticates as it.
     * Returns an OutboundTransport with `error` set (and `transport` null) when
     * the account cannot send (generic with no SMTP host, or not authorized).
     */
    public static function forConnectedAccount(InboundImapAccount $account): self {
        $t = new self();
        $t->fromAddress = $account->get('iia_username');
        $t->filesSent = $account->smtpFilesSent();

        if (!$account->isSendAuthorized()) {
            $t->error = $account->canSendViaSmtp()
                ? 'The connected account is not authorized to send — reconnect to allow sending.'
                : 'The connected account (generic IMAP) has no SMTP host — use a relay-class provider.';
            return $t;
        }

        try {
            $t->transport = new SmtpProvider(SmtpConfig::fromConnectedAccount($account));
        } catch (SmtpConfigException $e) {
            $t->error = $e->getMessage();
        }
        return $t;
    }

    /**
     * Resolve a transport for a hosted alias (alias@our-domain). There is no
     * source mailbox, so the platform's active provider sends it (transport null),
     * with the alias as the From identity and our domain's DKIM/SRS alignment.
     */
    public static function forHostedAlias(string $aliasAddress): self {
        $t = new self();
        $t->fromAddress = $aliasAddress;
        $t->filesSent = false; // no source mailbox to file a copy into

        // A connected account's address (user@gmail.com) is never a hosted
        // identity: the platform has no standing to put that From on the wire
        // through its own provider, the relay, or a DKIM signer. The send
        // reaches here only when the account's feed is disabled or paused —
        // the state every feed is born in — so the error names the fix rather
        // than falling through to a spoofed or nonsensical platform send.
        if (class_exists('InboundEmailDomain')) {
            $imap_row = InboundEmailDomain::GetByDomain(MailIdentityGuard::domainOf($aliasAddress));
            if ($imap_row !== false && $imap_row->is_imap_source()) {
                $t->error = 'This mailbox sends through its connected account, which is currently '
                    . 'disabled. Re-enable it under Mailbox → Accounts to send from this address.';
                return $t;
            }
        }

        // Relay-fronted deployment. The relay defaults to INBOUND-ONLY
        // (specs/mailbox_relay_inbound_only.md): compose sends leave through the
        // deployment's configured provider over an API raw-message path, so the
        // sent message's Received: chain begins inside the provider and the main
        // box IP appears nowhere. The relay smarthost is the opt-in for operators
        // who want no third party touching outbound plaintext and accept owning
        // the relay IP's sending reputation.
        // Doctrine enforcement keys off the RECORDED cutover state, not the
        // relay row's mere existence: relays are born enabled and run through
        // the DNS move, during which sends must keep working the legacy way
        // (the origin is still public until the MX flips, so nothing leaks).
        // The smarthost opt-in is an explicit admin choice and applies as soon
        // as it is chosen.
        $relay = self::activeRelay();
        $cutover_complete = ((string)Globalvars::get_instance()
            ->get_setting('mailbox_relay_cutover_complete') === '1');
        if ($relay !== null && (self::relayOutboundMode() === 'smarthost' || $cutover_complete)) {
            if (self::relayOutboundMode() === 'smarthost') {
                // Opt-in: relay smarthost over the tunnel — the sent Received: chain
                // shows the relay. SmtpProvider still runs the in-app DKIM signer;
                // the relay only transports.
                $t->transport = new SmtpProvider(SmtpConfig::fromRelaySmarthost($relay));
                return $t;
            }

            // Default: hand a fully formed, app-signed message to the active
            // provider's API raw-message relay. It must be API-class — SMTP
            // submission would stamp the box IP into the first Received: header
            // and defeat the hidden origin.
            require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
            $provider = EmailSender::getActiveProvider();
            if (!($provider instanceof ApiSubmissionRelay)) {
                $t->error = 'Sent mail cannot leave through the current email provider without exposing this '
                    . 'server\'s address. Choose a provider that submits over an API (Mailgun or Amazon SES), '
                    . 'or switch the relay to smarthost mode on the mailbox Settings tab.';
                return $t;
            }
            require_once(PathHelper::getIncludePath('includes/RawRelayComposeTransport.php'));
            $t->transport = new RawRelayComposeTransport(self::hostedEnvelopeSender($aliasAddress), $provider);
            return $t;
        }

        if (MailIdentityGuard::isProtectedDomain(MailIdentityGuard::domainOf($aliasAddress))) {
            // A protected identity never rides the ambient provider: the box
            // submits it itself through SmtpProvider, whose send() runs the
            // in-app DKIM signer (sealed key, unwrapped in-window). DMARC passes
            // on the strict-aligned DKIM signature alone — the domain's SPF is
            // v=spf1 -all by design. This injected transport is also what marks
            // the send as the session-gated compose path for EmailSender's
            // ambient-send guard.
            $t->transport = new SmtpProvider(SmtpConfig::fromForwardingSettings());
            return $t;
        }

        $t->transport = null;  // platform active provider via the default EmailSender path
        return $t;
    }

    /**
     * The relay's outbound mode: 'provider' (default — compose rides the
     * configured provider's API, hiding the origin) or 'smarthost' (opt-in —
     * compose leaves through the relay smarthost over the tunnel). Reads the
     * mailbox_relay_outbound_mode setting; anything but an explicit 'smarthost'
     * is treated as 'provider'.
     */
    private static function relayOutboundMode(): string {
        $mode = strtolower(trim((string)Globalvars::get_instance()->get_setting('mailbox_relay_outbound_mode')));
        return $mode === 'smarthost' ? 'smarthost' : 'provider';
    }

    /**
     * The envelope sender (MAIL FROM) for a hosted-alias raw-relay send. For a
     * protected identity the envelope routes through the forwarding subdomain so
     * the protected domain's own v=spf1 -all never touches the envelope (its
     * aspf=s means the subdomain's SPF can never align the identity anyway). For a
     * non-protected domain the envelope is just the From address. Defensive: any
     * lookup failure falls back to the From address.
     */
    private static function hostedEnvelopeSender(string $aliasAddress): string {
        $domain = MailIdentityGuard::domainOf($aliasAddress);
        if ($domain === '' || !MailIdentityGuard::isProtectedDomain($domain)) {
            return $aliasAddress;
        }
        $sub = self::forwardingSubdomainOf($domain);
        $at = strpos($aliasAddress, '@');
        if ($sub === '' || strcasecmp($sub, $domain) === 0 || $at === false) {
            return $aliasAddress;
        }
        return substr($aliasAddress, 0, $at) . '@' . $sub;
    }

    /**
     * The forwarding subdomain configured for a hosted domain, or '' when the
     * mailbox plugin/domain is unavailable. Loaded defensively — a core send path
     * must not fatal if the plugin class or table is absent.
     */
    private static function forwardingSubdomainOf(string $domain): string {
        $path = PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php');
        if (!is_file($path)) {
            return '';
        }
        require_once($path);
        try {
            $model = InboundEmailDomain::GetByDomain($domain);
            return $model ? (string)$model->forwarding_subdomain() : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * The active hardened ingest relay, or null on a colocated deployment. Loaded
     * defensively — the mailbox plugin owns the class, so a core send path must not
     * fatal if it is absent or the table does not exist yet.
     */
    private static function activeRelay() {
        $path = PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php');
        if (!is_file($path)) {
            return null;
        }
        require_once($path);
        try {
            return MailboxRelay::active();
        } catch (\Throwable $e) {
            return null;
        }
    }
}

/**
 * Resolve the outbound transport for a mailbox (§7). The mailbox is either a
 * connected InboundImapAccount (IMAP-source) or a hosted alias address string.
 * Reply/forward calls this, then sends via EmailSender::send($msg, true,
 * $result->transport) — through the single pipeline.
 *
 * @param InboundImapAccount|string $mailbox
 */
function resolveOutboundTransport($mailbox): OutboundTransport {
    if ($mailbox instanceof InboundImapAccount) {
        return OutboundTransport::forConnectedAccount($mailbox);
    }
    return OutboundTransport::forHostedAlias((string)$mailbox);
}
