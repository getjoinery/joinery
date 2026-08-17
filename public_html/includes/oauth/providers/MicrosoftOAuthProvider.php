<?php
/**
 * MicrosoftOAuthProvider - Microsoft (Azure AD) OAuth2 endpoints.
 *
 * Endpoints are templated on the configured tenant (oauth_microsoft_tenant,
 * default 'common'): common | organizations | consumers | a specific tenant id.
 * Scopes must include offline_access to receive a refresh token.
 *
 * @version 1.2 - configGuide steps link straight to the Entra pages they
 *   happen on instead of narrating the portal menu path
 * @version 1.1 - a guest UPN (#EXT#) is a sign-in name, not a mailbox address;
 *   identity reports none rather than minting an onmicrosoft.com mailbox
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Provider.php'));
require_once(PathHelper::getIncludePath('includes/SecretBox.php'));

class MicrosoftOAuthProvider implements OAuth2Provider {

    use DeclaresOAuthConfigFields;
    use DeclaresNoOAuthIdentity;

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
                [
                    'text'      => 'Open App registrations in the Entra admin center and choose New registration.',
                    'url'       => 'https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade',
                    'url_label' => 'App registrations',
                ],
                'Name it; under Supported account types pick who may sign in (personal Microsoft accounts included, for outlook.com mailboxes).',
                'Under Redirect URI choose Web and paste the callback URL below, then Register.',
                'The Application (client) ID is on the app\'s Overview page — that is the client ID field here.',
                'Open Certificates & secrets, choose New client secret, then copy the Value column immediately — it is shown only once.',
                'Copy the Directory (tenant) ID from Overview if you want to lock this to one tenant; blank means any.',
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

    // --- Identity -----------------------------------------------------------
    // Graph reports `mail` for a mailbox-enabled account and only the sign-in
    // name (userPrincipalName) for one without, so both are read in that order:
    // the mailbox address is what an IMAP feed needs, and the sign-in name is
    // the honest fallback rather than nothing.

    public static function identityScopes(): array {
        return ['User.Read'];
    }

    public static function getIdentityEndpoint(): ?string {
        return 'https://graph.microsoft.com/v1.0/me';
    }

    public static function identityFromProfile(array $profile): ?string {
        foreach (['mail', 'userPrincipalName'] as $field) {
            $value = trim((string)($profile[$field] ?? ''));
            // A guest account's UPN ('jem_gmail.com#EXT#@x.onmicrosoft.com') is a
            // sign-in name, not a mailbox address — reporting it would mint an
            // onmicrosoft.com mailbox nothing can fetch. No identity is the
            // honest answer; the flow then asks the operator.
            if ($value !== '' && strpos($value, '#EXT#') === false) {
                return $value;
            }
        }
        return null;
    }
}
