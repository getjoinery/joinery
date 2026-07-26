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
 * @version 2.0
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

    if (LibraryFunctions::isFormSubmission()) {
        foreach (OAuth2ProviderRegistry::all() as $provider_class) {
            $error = OAuth2ProviderConfig::save($provider_class, $input, '', $session);
            if ($error !== '') {
                return LogicResult::error($error, ['error_message' => $error]);
            }
        }
        return LogicResult::redirect('/admin/admin_oauth_providers');
    }

    return LogicResult::render(['error_message' => null]);
}
