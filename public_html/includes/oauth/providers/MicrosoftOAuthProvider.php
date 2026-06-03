<?php
/**
 * MicrosoftOAuthProvider - Microsoft (Azure AD) OAuth2 endpoints.
 *
 * Endpoints are templated on the configured tenant (oauth_microsoft_tenant,
 * default 'common'): common | organizations | consumers | a specific tenant id.
 * Scopes must include offline_access to receive a refresh token.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Provider.php'));
require_once(PathHelper::getIncludePath('includes/SecretBox.php'));

class MicrosoftOAuthProvider implements OAuth2Provider {

    public static function getKey(): string { return 'microsoft'; }
    public static function getLabel(): string { return 'Microsoft'; }

    /** Configured tenant segment, defaulting to 'common'. */
    public static function getTenant(): string {
        $settings = Globalvars::get_instance();
        $tenant = trim((string)$settings->get_setting('oauth_microsoft_tenant', false, true));
        return $tenant !== '' ? $tenant : 'common';
    }

    public static function getAuthorizeEndpoint(): string {
        return 'https://login.microsoftonline.com/' . rawurlencode(self::getTenant())
            . '/oauth2/v2.0/authorize';
    }

    public static function getTokenEndpoint(): string {
        return 'https://login.microsoftonline.com/' . rawurlencode(self::getTenant())
            . '/oauth2/v2.0/token';
    }

    public static function getClientId(): string {
        $settings = Globalvars::get_instance();
        return trim((string)$settings->get_setting('oauth_microsoft_client_id', false, true));
    }

    public static function getClientSecret(): string {
        $settings = Globalvars::get_instance();
        $stored = (string)$settings->get_setting('oauth_microsoft_client_secret', false, true);
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
