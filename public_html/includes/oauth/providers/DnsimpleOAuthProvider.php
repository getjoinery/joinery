<?php
/**
 * DnsimpleOAuthProvider - DNSimple OAuth2 endpoints.
 *
 * DNSimple's access tokens do not expire and no refresh token is issued; the
 * DNS consumer still discards the grant when its one publish returns, so
 * nothing DNS-write-capable is left at rest.
 *
 * DNSimple scopes every API call to an account id, which the driver reads from
 * /v2/whoami rather than asking anyone to record it.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Provider.php'));
require_once(PathHelper::getIncludePath('includes/SecretBox.php'));

class DnsimpleOAuthProvider implements OAuth2Provider {

    public static function getKey(): string { return 'dnsimple'; }
    public static function getLabel(): string { return 'DNSimple'; }

    public static function getAuthorizeEndpoint(): string {
        return 'https://dnsimple.com/oauth/authorize';
    }

    public static function getTokenEndpoint(): string {
        return 'https://api.dnsimple.com/v2/oauth/access_token';
    }

    public static function getClientId(): string {
        $settings = Globalvars::get_instance();
        return trim((string)$settings->get_setting('oauth_dnsimple_client_id', false, true));
    }

    public static function getClientSecret(): string {
        $settings = Globalvars::get_instance();
        $stored = (string)$settings->get_setting('oauth_dnsimple_client_secret', false, true);
        if ($stored === '') {
            return '';
        }
        if (SecretBox::looksEncrypted($stored)) {
            return (new SecretBox())->decrypt($stored);
        }
        return $stored;
    }

    public static function isConfigured(): bool {
        return self::getClientId() !== '' && self::getClientSecret() !== '';
    }

    public static function extraAuthorizeParams(array $scopes): array {
        return [];
    }
}
