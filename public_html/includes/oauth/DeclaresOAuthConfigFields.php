<?php
/**
 * DeclaresOAuthConfigFields - The default shape of a provider's app registration.
 *
 * Every OAuth2 provider needs the same two values from the deployment: the app's
 * client id and its client secret. The interface already fixes where they live —
 * getKey() is documented as the settings prefix — so a provider does not need to
 * restate them. Using this trait is the whole declaration.
 *
 * This exists because the alternative failed in practice. The admin page used to
 * render a hardcoded field pair per provider, which meant adding a provider took
 * an edit in a second file; DigitalOcean and DNSimple were added without it and
 * could not be configured anywhere in the software. Every surface that collects
 * an app registration now reads configFields(), so a new provider is one file.
 *
 * A provider needing more than the two (Microsoft's tenant) overrides
 * configFields() and calls self::defaultConfigFields() to keep them.
 *
 * @version 1.0
 */

trait DeclaresOAuthConfigFields {

    /**
     * The settings this provider's app registration is made of.
     *
     * [setting_name => [
     *     'label'  => string,
     *     'help'   => string,   // optional
     *     'secret' => bool,     // stored through SecretBox, never echoed back
     * ]]
     */
    public static function configFields(): array {
        return self::defaultConfigFields();
    }

    /**
     * The callback URL as a guide copy row.
     *
     * Every vendor's app-registration form asks for it, and it has to match
     * byte-for-byte, so it is offered as a copy button rather than described.
     * OAuth2Client is required here rather than at file scope because it pulls
     * in the provider registry, which loads these provider classes.
     */
    protected static function callbackCopyRow(): array {
        require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Client.php'));
        return [
            'label' => 'Callback URL',
            'value' => OAuth2Client::redirectUri(),
        ];
    }

    /** The client id / client secret pair every provider shares. */
    protected static function defaultConfigFields(): array {
        $key   = static::getKey();
        $label = static::getLabel();
        return [
            'oauth_' . $key . '_client_id' => [
                'label'  => $label . ' client ID',
                'secret' => false,
            ],
            'oauth_' . $key . '_client_secret' => [
                'label'  => $label . ' client secret',
                'help'   => 'Leave blank to keep the stored secret unchanged.',
                'secret' => true,
            ],
        ];
    }
}
