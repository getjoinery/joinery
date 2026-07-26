<?php
/**
 * LinodeOAuthProvider - Linode (Akamai Cloud) OAuth2 endpoints.
 *
 * Access tokens expire in two hours and Linode issues NO refresh token on the
 * authorization-code grant (the token response carries refresh_token: null —
 * verified empirically 2026-07-18). A stored grant is therefore a two-hour
 * credential: consumers must treat an expired token as "re-consent needed"
 * (OAuth2Client::ensureFresh throws OAuth2Exception in that state). No extra
 * authorize params are required.
 *
 * Scopes of interest to consumers: 'linodes:read_write' (create/manage
 * instances on the granting user's account). Request the minimum scope the
 * consumer needs - instances created with the token are billed by Linode to
 * the token owner's account.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Provider.php'));
require_once(PathHelper::getIncludePath('includes/SecretBox.php'));

class LinodeOAuthProvider implements OAuth2Provider {

    use DeclaresOAuthConfigFields;

    public static function getKey(): string { return 'linode'; }
    public static function getLabel(): string { return 'Linode'; }

    public static function configGuide(): ?array {
        return [
            'title'     => 'Register a Linode OAuth app',
            'url'       => 'https://cloud.linode.com/profile/clients',
            'url_label' => 'Open Linode OAuth Apps',
            'steps'     => [
                'In Cloud Manager, click your username and choose My Profile, then the OAuth Apps tab.',
                'Choose Add an OAuth App, give it a label, and paste the callback URL below.',
                'Leave it private, then create it.',
                'Copy the client secret from the window that appears — it cannot be retrieved later.',
                'The client ID is listed with the app afterwards.',
            ],
            'copy'      => [self::callbackCopyRow()],
        ];
    }

    public static function getAuthorizeEndpoint(): string {
        return 'https://login.linode.com/oauth/authorize';
    }

    public static function getTokenEndpoint(): string {
        return 'https://login.linode.com/oauth/token';
    }

    public static function getClientId(): string {
        $settings = Globalvars::get_instance();
        return trim((string)$settings->get_setting('oauth_linode_client_id', false, true));
    }

    public static function getClientSecret(): string {
        $settings = Globalvars::get_instance();
        $stored = (string)$settings->get_setting('oauth_linode_client_secret', false, true);
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
