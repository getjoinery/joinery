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

    use DeclaresOAuthConfigFields;

    public static function getKey(): string { return 'microsoft'; }
    public static function getLabel(): string { return 'Microsoft'; }

    /**
     * The usual pair plus the tenant segment the endpoints are templated on.
     */
    public static function configFields(): array {
        $fields = self::defaultConfigFields();
        $fields['oauth_microsoft_tenant'] = [
            'label'  => 'Microsoft tenant',
            'help'   => 'common, organizations, consumers, or a specific tenant id. Blank means common.',
            'secret' => false,
        ];
        return $fields;
    }

    public static function configGuide(): ?array {
        return [
            'title'     => 'Register a Microsoft Entra app',
            'url'       => 'https://portal.azure.com/#view/Microsoft_AAD_IAM/ActiveDirectoryMenuBlade/~/RegisteredApps',
            'url_label' => 'Open Entra ID app registrations',
            'steps'     => [
                'In the Azure portal open Microsoft Entra ID, then App registrations, then New registration.',
                'Name it, and under Redirect URI choose Web and paste the callback URL below.',
                'Register it — the Application (client) ID is on the Overview page.',
                'Open Certificates & secrets, choose New client secret, then copy the Value column immediately.',
                'Copy the Directory (tenant) ID from Overview if you want to lock this to one tenant.',
                'For Azure DNS, give the account you will consent as the DNS Zone Contributor role on the zone.',
            ],
            'copy'      => [self::callbackCopyRow()],
        ];
    }

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
