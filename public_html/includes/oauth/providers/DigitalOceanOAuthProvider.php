<?php
/**
 * DigitalOceanOAuthProvider - DigitalOcean OAuth2 endpoints.
 *
 * DigitalOcean issues both an access token and a refresh token on the
 * authorization-code grant. The DNS consumer discards both the moment its one
 * publish returns — a refresh token is exactly the standing credential this
 * platform declines to hold.
 *
 * Scopes are coarse: 'read write' covers the whole account API, DNS included.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Provider.php'));
require_once(PathHelper::getIncludePath('includes/SecretBox.php'));

class DigitalOceanOAuthProvider implements OAuth2Provider {

    use DeclaresOAuthConfigFields;
    use DeclaresNoOAuthIdentity;

    public static function getKey(): string { return 'digitalocean'; }
    public static function getLabel(): string { return 'DigitalOcean'; }

    public static function configGuide(): ?array {
        return [
            'title'     => 'Register a DigitalOcean OAuth app',
            'url'       => 'https://cloud.digitalocean.com/account/api/applications',
            'url_label' => 'Open DigitalOcean OAuth Applications',
            'steps'     => [
                'In the DigitalOcean control panel open API, then the OAuth Applications tab.',
                'Choose Register OAuth Application and name it.',
                'Paste the callback URL below as the application callback URL.',
                'Register it — the client ID and client secret are shown on the next page.',
            ],
            'copy'      => [self::callbackCopyRow()],
        ];
    }

    public static function getAuthorizeEndpoint(): string {
        return 'https://cloud.digitalocean.com/v1/oauth/authorize';
    }

    public static function getTokenEndpoint(): string {
        return 'https://cloud.digitalocean.com/v1/oauth/token';
    }

    public static function getClientId(): string {
        $settings = Globalvars::get_instance();
        return trim((string)$settings->get_setting('oauth_digitalocean_client_id', false, true));
    }

    public static function getClientSecret(): string {
        $settings = Globalvars::get_instance();
        $stored = (string)$settings->get_setting('oauth_digitalocean_client_secret', false, true);
        if ($stored === '') {
            return '';
        }
        // open() collapses the legacy-plaintext passthrough: a plaintext value
        // comes back raw, a sealed one decrypted, a dead one as '' (not configured).
        return (string)((new SecretBox())->open($stored)['value'] ?? '');
    }

    public static function isConfigured(): bool {
        return self::getClientId() !== '' && self::getClientSecret() !== '';
    }

    public static function extraAuthorizeParams(array $scopes): array {
        return [];
    }
}
