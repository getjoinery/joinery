<?php
/**
 * EmailServiceProvider Interface
 *
 * All email providers must implement this interface. Provider classes live in
 * includes/email_providers/ and are auto-discovered by EmailSender.
 *
 * To add a new provider, create a single file in includes/email_providers/
 * implementing this interface. No other files need modification.
 *
 * This file also declares the optional RawMessageRelay, ApiSubmissionRelay,
 * DkimRecordSource, and SendingDomainRegistrar capabilities (below).
 *
 * @version 1.5
 */
interface EmailServiceProvider {
    /**
     * Return the provider's unique key (e.g., 'mailgun', 'smtp', 'sendgrid').
     * This is the value stored in the email_service / email_fallback_service settings.
     */
    public static function getKey(): string;

    /**
     * Return a human-readable label for admin UI (e.g., 'Mailgun', 'SMTP').
     */
    public static function getLabel(): string;

    /**
     * Return the SPF mechanism a sending domain must carry in its SPF record
     * for mail sent through this provider to pass SPF — a complete term ready
     * to paste into a v=spf1 record. Three shapes:
     *
     *   - 'include:mailgun.org' — providers with a fixed shared range.
     *   - 'a:smtp.example.com'  — derived from configured settings (custom SMTP).
     *   - fetched from the provider's own API for providers that publish
     *     per-account DNS records (Resend-class); may be several
     *     space-separated mechanisms.
     *
     * Return '' when no mechanism applies or can be determined: local sends
     * covered by the server's own IP, or a per-account provider whose API is
     * unreachable. Callers treat '' as "nothing to prescribe", never as an
     * error. $domain is the sending domain being asked about — most providers
     * ignore it; per-account providers use it to select the account record.
     */
    public static function getSpfMechanism(string $domain): string;

    /**
     * Validate that this provider's required settings are configured.
     * Returns ['valid' => bool, 'errors' => string[]]
     */
    public static function validateConfiguration(): array;

    /**
     * Send an EmailMessage. Returns true on success, false on failure.
     * Should log errors via error_log() and optionally via the debug logger.
     * Must NOT queue failed emails - the caller (EmailSender) handles that.
     */
    public function send(EmailMessage $message): bool;

    /**
     * Send to multiple recipients efficiently (batch).
     * Default implementation can loop over send(), but providers like Mailgun
     * can override to use native batch APIs.
     *
     * Returns an array:
     *   'success' => bool (true only if ALL recipients succeeded)
     *   'failed_recipients' => string[] (email addresses that failed)
     *
     * The failed_recipients list is used by EmailSender for fallback: only
     * unsent recipients are passed to the fallback provider, avoiding double-sends.
     */
    public function sendBatch(EmailMessage $message, array $recipients): array;
}

/**
 * RawMessageRelay - optional, opt-in capability for outbound providers.
 *
 * An EmailServiceProvider MAY also implement this interface when it can relay
 * an already-formed RFC 5322 message byte-for-byte to chosen envelope
 * recipients with an explicit envelope sender (Return-Path / MAIL FROM). This
 * is what inbound-email forwarding needs (faithful MIME + a chosen envelope)
 * and what the normal send() path cannot express.
 *
 * It is declared here, alongside EmailServiceProvider, rather than in its own
 * file: this file is already loaded wherever outbound providers are resolved
 * (EmailSender, InboundProviderRegistry), so the interface is in scope with no
 * extra includes. A provider opts in simply by adding it to its `implements`
 * list — mirroring how InboundEmailProvider is opted into. Forwarding detects
 * support with `instanceof RawMessageRelay`; a provider that does not implement
 * it falls back to the SMTP relay, so forwarding never regresses.
 *
 * Providers that implement it: Mailgun (messages.mime), SMTP (native raw SMTP),
 * SES (SESv2 sendEmail with Content.Raw). The remaining providers expose only
 * structured-message APIs and deliberately do not.
 *
 * @version 1.0
 */
interface RawMessageRelay {
    /**
     * Relay an already-formed RFC 5322 message to one or more envelope
     * recipients, with an explicit envelope sender (Return-Path / MAIL FROM).
     * Returns ['dest@x' => bool] per recipient, mirroring forwardEmail().
     *
     * @param string $raw_mime         The full message to relay, as-is.
     * @param string $envelope_sender  MAIL FROM (already SRS-rewritten if applicable).
     * @param string[] $destinations   Envelope recipients (RCPT TO).
     * @return array<string,bool>      Per-destination success keyed by address.
     */
    public function relayRawMessage(string $raw_mime, string $envelope_sender, array $destinations): array;
}

/**
 * ApiSubmissionRelay - a raw-message relay that submits over an HTTP API rather
 * than SMTP, so the delivered message's Received: chain begins inside the
 * provider's infrastructure and the submitting client's IP appears nowhere.
 *
 * This is the property a relay-fronted deployment relies on to keep its origin
 * hidden when the relay smarthost is off and compose sends leave through the
 * provider (specs/mailbox_relay_inbound_only.md): SMTP submission stamps the
 * connecting client's IP into the first Received: header, an API submission does
 * not. It is a self-declaration, not something core can infer — a provider
 * asserts it by adding this interface to its `implements` list.
 *
 * Providers that implement it: Mailgun (messages.mime), SES (SESv2 Content.Raw).
 * SmtpProvider implements RawMessageRelay but NOT this — it is SMTP submission,
 * so it is excluded from the hidden-origin compose path by design.
 *
 * @version 1.0
 */
interface ApiSubmissionRelay extends RawMessageRelay {
}

/**
 * DkimRecordSource - optional capability for providers that DKIM-sign outbound
 * mail themselves and can report, from their own API, the DNS records a sending
 * domain must publish for that signing to verify and align.
 *
 * The mailbox Setup tab uses this to drive the domain DKIM row: when the
 * outbound path for a domain's mail is an API provider, the correct DKIM record
 * is the one the PROVIDER issues for that domain — a locally generated opendkim
 * key signs nothing on that path. A provider opts in by adding this interface
 * to its `implements` list; providers without it get generic naming-the-provider
 * guidance instead. Local-submission providers (Postfix, SMTP) never implement
 * it — opendkim owns their signing.
 *
 * Providers that implement it: Mailgun (sending DNS records from the domains
 * API), SES (Easy DKIM CNAME tokens from GetEmailIdentity).
 *
 * @version 1.0
 */
interface DkimRecordSource {
    /**
     * The DKIM DNS records the provider requires for $domain, from the
     * provider's API. Never throws.
     *
     * @return array{status:string, records:array<int,array{type:string,name:string,value:string}>}
     *   status:
     *     'ok'             — $domain is registered with the provider; records
     *                        lists what must be published (may be empty when the
     *                        provider reports signing configured with nothing
     *                        left to publish).
     *     'not_registered' — the API answered and $domain is not a sending
     *                        domain there; the fix is at the provider dashboard.
     *     'unreachable'    — the API did not answer; callers must render an
     *                        unknown verdict, never a fabricated one.
     */
    public static function getDkimStatus(string $domain): array;
}

/**
 * SendingDomainRegistrar - optional capability for providers whose API can
 * register a new sending domain, so guided setup (the mailbox Setup tab's
 * machine sender ceremony) can offer registration as a button instead of a
 * dashboard errand. A provider opts in by adding this interface to its
 * `implements` list; providers without it get manual instructions naming
 * their dashboard, and the ceremony continues from what getDkimStatus()
 * reports once the domain is registered there by hand.
 *
 * Providers that implement it: Mailgun (domains API, with DKIM authority
 * forced to the created domain itself so keys are issued for the subdomain
 * rather than inherited from its parent — inherited authority breaks strict
 * DMARC alignment).
 *
 * @version 1.0
 */
interface SendingDomainRegistrar {
    /**
     * Create $domain as a sending domain at the provider. Idempotent: an
     * already-registered domain returns 'ok'. Never throws.
     *
     * @return array{status:'ok'|'error'|'unreachable', error?:string}
     */
    public static function createSendingDomain(string $domain): array;
}
