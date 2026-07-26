<?php
/**
 * OAuth2Provider - A provider's static identity.
 *
 * Implementations live in includes/oauth/providers/ and are discovered by
 * interface (see OAuth2ProviderRegistry). Endpoints are constant per provider;
 * app credentials are read from settings so they are entered once in admin and
 * never hardcoded. The grant mechanics in OAuth2Client are identical across
 * providers — only the values these methods return differ.
 *
 * @version 1.1
 * @changelog 1.1 - Added configFields() and configGuide(): a provider declares the settings its app registration is made of, so every surface that collects one renders from one declaration
 */

// The default configFields() implementation every provider uses. Required here
// so implementing this interface is enough to pick it up.
require_once(PathHelper::getIncludePath('includes/oauth/DeclaresOAuthConfigFields.php'));

interface OAuth2Provider {
    /** Stable key, also the settings prefix, e.g. 'google' | 'microsoft'. */
    public static function getKey(): string;

    /** Human-readable label for admin UI. */
    public static function getLabel(): string;

    /** Authorization (consent) endpoint URL. */
    public static function getAuthorizeEndpoint(): string;

    /** Token endpoint URL (code exchange + refresh). */
    public static function getTokenEndpoint(): string;

    /** OAuth app client id, read from settings. */
    public static function getClientId(): string;

    /** OAuth app client secret, read from settings and decrypted via SecretBox. */
    public static function getClientSecret(): string;

    /** True when both client id and secret are present. */
    public static function isConfigured(): bool;

    /**
     * The settings this provider's app registration is made of, so every surface
     * that collects one renders from a single declaration instead of a hardcoded
     * list. `DeclaresOAuthConfigFields` supplies the usual client id / secret
     * pair; a provider overrides only to add something unusual.
     *
     * [setting_name => ['label' => string, 'help' => string, 'secret' => bool]]
     */
    public static function configFields(): array;

    /**
     * How the deployment registers this app at the vendor — the clicks that
     * produce the client id and secret. NULL when there is no guide.
     *
     * Same shape as DnsProvider::credentialGuide() and FormWriter's help_modal:
     * title, steps, optional url/url_label, optional copy rows. The callback URL
     * belongs in `copy`, because the vendor's own form asks for it.
     */
    public static function configGuide(): ?array;

    /**
     * Provider quirks merged into the authorize query (e.g. Google
     * access_type=offline&prompt=consent to reliably receive a refresh token).
     */
    public static function extraAuthorizeParams(array $scopes): array;
}
