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
 * @version 1.0
 */
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
     * Provider quirks merged into the authorize query (e.g. Google
     * access_type=offline&prompt=consent to reliably receive a refresh token).
     */
    public static function extraAuthorizeParams(array $scopes): array;
}
