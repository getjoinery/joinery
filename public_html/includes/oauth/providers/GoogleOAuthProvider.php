<?php
/**
 * GoogleOAuthProvider - Google / Google Workspace OAuth2 endpoints.
 *
 * extraAuthorizeParams adds access_type=offline and prompt=consent, which
 * Google requires to reliably return a refresh token (without them, Google
 * issues a refresh token only on the very first consent and omits it
 * thereafter).
 *
 * @version 1.1 - configGuide rewritten against the Google Auth Platform console
 *   (clients no longer live under APIs & Services > Credentials), with a direct
 *   link on every step that has its own page, including the Testing-mode
 *   test-user step that otherwise fails consent with access_denied
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
            'url'       => 'https://console.cloud.google.com/auth/clients',
            'url_label' => 'Open Google Auth Platform — Clients',
            'steps'     => [
                [
                    'text'      => 'Sign in to Google Cloud Console and pick a project from the selector at the top of the page, or create one. Any project works — it just holds the OAuth client.',
                    'url'       => 'https://console.cloud.google.com/projectcreate',
                    'url_label' => 'Create a project',
                ],
                [
                    'text'      => 'If the project has never used OAuth, the Auth Platform overview shows a Get started button — it asks for an app name, a support email, and an audience (choose External for personal Gmail accounts).',
                    'url'       => 'https://console.cloud.google.com/auth/overview',
                    'url_label' => 'Auth Platform overview',
                ],
                [
                    'text'      => 'Open Clients and choose Create client, application type Web application. (Clients live under Google Auth Platform, not under APIs & Services.)',
                    'url'       => 'https://console.cloud.google.com/auth/clients',
                    'url_label' => 'OAuth clients',
                ],
                'Under Authorized redirect URIs, add the callback URL below — it must match exactly.',
                'Create it, then copy the client ID and client secret from the confirmation dialog into the fields on this page. The secret is shown once; download the JSON if you want a spare copy.',
                [
                    'text'      => 'While the app\'s publishing status is Testing, only listed test users can sign in — add each Google account you will connect under Audience, or consent ends in access_denied.',
                    'url'       => 'https://console.cloud.google.com/auth/audience',
                    'url_label' => 'Audience & test users',
                ],
                [
                    'text'      => 'For Cloud DNS connections only, also enable the Cloud DNS API in this project. Collecting Gmail over IMAP needs no API enabled.',
                    'url'       => 'https://console.cloud.google.com/apis/library/dns.googleapis.com',
                    'url_label' => 'Cloud DNS API',
                ],
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
        // open() collapses the legacy-plaintext passthrough: a plaintext value
        // comes back raw, a sealed one decrypted, a dead one as '' (not configured).
        return (string)((new SecretBox())->open($stored)['value'] ?? '');
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
