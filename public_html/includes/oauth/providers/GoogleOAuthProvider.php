<?php
/**
 * GoogleOAuthProvider - Google / Google Workspace OAuth2 endpoints.
 *
 * extraAuthorizeParams adds access_type=offline and prompt=consent, which
 * Google requires to reliably return a refresh token (without them, Google
 * issues a refresh token only on the very first consent and omits it
 * thereafter).
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Provider.php'));
require_once(PathHelper::getIncludePath('includes/SecretBox.php'));

class GoogleOAuthProvider implements OAuth2Provider {

    use DeclaresOAuthConfigFields;
    use DeclaresNoOAuthIdentity;

    public static function getKey(): string { return 'google'; }
    public static function getLabel(): string { return 'Google'; }

    public static function configGuide(): ?array {
        return [
            'title'     => 'Create a Google OAuth client',
            'url'       => 'https://console.cloud.google.com/apis/credentials',
            'url_label' => 'Open Google Cloud credentials',
            'steps'     => [
                'Pick the Google Cloud project that holds what you are connecting, or create one.',
                'Configure the OAuth consent screen once if the project has none.',
                'Under Clients (APIs & Services) choose Create client, type Web application.',
                'Add the callback URL below under Authorized redirect URIs — it must match exactly.',
                'Create it, then copy the client ID and client secret.',
                'For Cloud DNS, also enable the Cloud DNS API in this project.',
            ],
            'copy'      => [self::callbackCopyRow()],
        ];
    }

    public static function getAuthorizeEndpoint(): string {
        return 'https://accounts.google.com/o/oauth2/v2/auth';
    }

    public static function getTokenEndpoint(): string {
        return 'https://oauth2.googleapis.com/token';
    }

    public static function getClientId(): string {
        $settings = Globalvars::get_instance();
        return trim((string)$settings->get_setting('oauth_google_client_id', false, true));
    }

    public static function getClientSecret(): string {
        $settings = Globalvars::get_instance();
        $stored = (string)$settings->get_setting('oauth_google_client_secret', false, true);
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
        return [
            'access_type' => 'offline',
            'prompt'      => 'consent',
        ];
    }

    // --- Identity -----------------------------------------------------------
    // Google will say which account just consented, which is what stops an
    // operator typing one address and signing in as another.

    public static function identityScopes(): array {
        return ['openid', 'email'];
    }

    public static function getIdentityEndpoint(): ?string {
        return 'https://www.googleapis.com/oauth2/v3/userinfo';
    }

    public static function identityFromProfile(array $profile): ?string {
        $email = trim((string)($profile['email'] ?? ''));
        return $email !== '' ? $email : null;
    }
}
