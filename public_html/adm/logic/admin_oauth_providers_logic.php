<?php
/**
 * admin_oauth_providers_logic - Save/read OAuth app credentials.
 *
 * Every provider in the registry is saved from its own configFields()
 * declaration, so adding a provider needs no edit here. Client ids are plain
 * settings; client secrets are written through SecretBox and never echoed back,
 * and a blank secret field means "leave the stored secret unchanged" so an admin
 * can edit a client id without re-entering the secret.
 *
 * @version 2.1
 * @changelog 2.1 - Honours a same-site return path, so a page that sends an admin here to register an app gets them back afterwards
 * @changelog 2.0 - Registry-driven: providers and their fields come from OAuth2ProviderRegistry / configFields() instead of a hardcoded list, which had silently omitted DigitalOcean and DNSimple entirely
 */

require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_oauth_providers_logic(array $input): LogicResult {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getIncludePath('includes/oauth/OAuth2ProviderRegistry.php'));
    require_once(PathHelper::getIncludePath('includes/oauth/OAuth2ProviderConfig.php'));

    $session = SessionControl::get_instance();
    $session->check_permission(10);

    // Somewhere else sent the admin here to finish one thing — an IMAP feed that
    // cannot connect until its provider is registered, say. Saving returns them
    // to where they were, so the errand ends where it started instead of leaving
    // them on a settings page working out how to get back.
    $return = _oauth_providers_return_url($input['return'] ?? '');

    if (LibraryFunctions::isFormSubmission()) {
        foreach (OAuth2ProviderRegistry::all() as $provider_class) {
            $error = OAuth2ProviderConfig::save($provider_class, $input, '', $session);
            if ($error !== '') {
                return LogicResult::error($error, ['error_message' => $error, 'return_url' => $return]);
            }
        }
        return LogicResult::redirect($return ?: '/admin/admin_oauth_providers');
    }

    return LogicResult::render(['error_message' => null, 'return_url' => $return]);
}

/**
 * A same-site path to return to, or '' when there isn't a usable one. Only a
 * path is accepted — never a scheme or a host — so this cannot be turned into a
 * redirect to somebody else's site.
 */
function _oauth_providers_return_url($raw): string {
    $raw = trim((string)$raw);
    if ($raw === '' || $raw[0] !== '/' || strpos($raw, '//') === 0) {
        return '';
    }
    if (strpos($raw, "\n") !== false || strpos($raw, "\r") !== false) {
        return '';
    }
    return $raw;
}
