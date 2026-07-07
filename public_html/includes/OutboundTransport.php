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
 * @version 1.1
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
