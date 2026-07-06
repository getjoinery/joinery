<?php
/**
 * InboundEmailProvider Interface
 *
 * The inbound counterpart to EmailServiceProvider. Provider classes that
 * implement this interface can be discovered as inbound transports by
 * InboundProviderRegistry. A single provider class may implement both
 * interfaces (e.g. MailgunProvider), in which case getKey() and getLabel()
 * are shared.
 *
 * Inbound providers live in includes/email_providers/ alongside outbound
 * providers, so the same physical class can satisfy both roles.
 *
 * @version 1.1
 */
interface InboundEmailProvider {
    /**
     * Unique provider key (e.g. 'postfix', 'mailgun'). Also serves as the
     * value stored in the mailbox_provider setting.
     */
    public static function getKey(): string;

    /**
     * Human-readable label for admin UI.
     */
    public static function getLabel(): string;

    /**
     * Inbound-only settings this provider needs.
     *
     * Combined providers (Mailgun, etc.) already declare their full setting
     * set in EmailServiceProvider::getSettingsFields() — this method returns
     * the subset relevant to inbound (typically a webhook signing key), or
     * an empty array if everything inbound needs is already in the outbound
     * declaration. The Setup tab uses this to render inbound-specific
     * fields in context.
     *
     * Each entry follows the same shape as EmailServiceProvider settings:
     *   ['key' => 'setting_name', 'label' => '...', 'type' => 'text|password', 'helptext' => '...']
     */
    public static function getInboundSettingsFields(): array;

    /**
     * Setup tab check catalogue for this provider, scoped to an optional
     * domain. Each entry uses the same result shape as
     * InboundEmailSetupCheck::r() (id, scope, layer, label, severity,
     * status, summary, detail, fix, recheckable).
     */
    public static function getSetupChecks(?string $domain = null): array;

    /**
     * Copy-ready DNS records for a domain — typically MX, SPF, DKIM, DMARC.
     * The Setup tab renders these as cards in the add-an-address wizard.
     *
     * Each record: ['type' => 'MX', 'name' => '...', 'value' => '...', 'note' => '...']
     */
    public static function getDnsRecords(string $domain): array;

    /**
     * True if handleInbound() is invoked from an HTTP webhook, false if
     * from a local pipe/process. The generic webhook dispatcher only
     * accepts providers where this returns true.
     */
    public static function isWebhook(): bool;

    /**
     * Transport-specific entry point.
     *
     * Webhook providers receive form fields in $post and the request body
     * in $raw_body. Pipe providers receive an empty $post (or just the
     * envelope recipient) and the stdin payload in $raw_body. The provider
     * verifies the request, extracts raw MIME + envelope recipient, and
     * returns them. Returning null signals rejection (signature failure,
     * malformed input).
     *
     * A provider that performed its own SPF/DKIM/DMARC verification upstream
     * (and delivered the message over an authenticated path — see each
     * provider's handleInbound) MAY include an optional 'auth' key carrying
     * those verdicts. The router prefers it over the message's
     * Authentication-Results header (see InboundEmailRouter::readAuthResults).
     * Verdict tokens are normalized to the same lowercase set
     * AuthenticationResults produces: pass | fail | softfail | neutral | none
     * | temperror | permerror. A method the provider did not assert is null
     * (recorded 'none'). A provider that does no verification omits 'auth'
     * entirely (the message is then recorded 'unverified').
     *
     * A provider that performed its own CONTENT-spam scanning upstream MAY also
     * include an optional 'spam' key carrying that signal
     * (specs/inbound_email_content_spam_filtering.md). It is a content signal, not an
     * auth verdict, so it is a sibling of 'auth', never folded into it. 'result' is
     * the provider's binary decision (spam | ham | none); 'score' is its numeric score
     * recorded for transparency only (never acted on in PHP). The router OR's a 'spam'
     * result into the verdict (see InboundEmailRouter::resolveContentSpam). A provider
     * that does no content scanning omits 'spam' entirely.
     *
     * @return array{
     *   raw_mime: string,
     *   recipient: string,
     *   auth?: array{
     *     spf: ?string,
     *     dkim: ?string,
     *     dmarc: ?string,
     *     spf_domain?: string,
     *     dkim_domain?: string,
     *     source: string
     *   },
     *   spam?: array{
     *     result: string,
     *     score?: float,
     *     source: string
     *   }
     * }|null
     */
    public function handleInbound(array $post, string $raw_body): ?array;
}
