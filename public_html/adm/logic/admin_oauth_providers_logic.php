<?php
/**
 * admin_oauth_providers_logic - Save/read OAuth app credentials.
 *
 * Client ids and the Microsoft tenant are stored as plain settings; client
 * secrets are written through SecretBox (encrypted at rest) and are never echoed
 * back to the browser. A blank secret field means "leave the stored secret
 * unchanged", so an admin can edit the client id without re-entering the secret.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_oauth_providers_logic(array $input): LogicResult {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getIncludePath('data/settings_class.php'));
    require_once(PathHelper::getIncludePath('includes/SecretBox.php'));

    $session = SessionControl::get_instance();
    $session->check_permission(10);

    if (LibraryFunctions::isFormSubmission()) {
        // Plain (non-secret) settings.
        admin_oauth_providers_upsert('oauth_google_client_id', trim((string)($input['oauth_google_client_id'] ?? '')), $session);
        admin_oauth_providers_upsert('oauth_microsoft_client_id', trim((string)($input['oauth_microsoft_client_id'] ?? '')), $session);
        admin_oauth_providers_upsert('oauth_microsoft_tenant', trim((string)($input['oauth_microsoft_tenant'] ?? '')), $session);

        // Secrets: only overwrite when a new value was actually entered.
        $secrets = [
            'oauth_google_client_secret'    => trim((string)($input['oauth_google_client_secret_input'] ?? '')),
            'oauth_microsoft_client_secret' => trim((string)($input['oauth_microsoft_client_secret_input'] ?? '')),
        ];
        $secrets_to_set = array_filter($secrets, function ($v) { return $v !== ''; });

        if (!empty($secrets_to_set)) {
            try {
                $box = new SecretBox();
            } catch (Throwable $e) {
                return LogicResult::error(
                    'Cannot store client secrets: ' . $e->getMessage(),
                    ['error_message' => 'Cannot store client secrets: ' . $e->getMessage()]
                );
            }
            foreach ($secrets_to_set as $name => $plaintext) {
                admin_oauth_providers_upsert($name, $box->encrypt($plaintext), $session);
            }
        }

        return LogicResult::redirect('/admin/admin_oauth_providers');
    }

    return LogicResult::render(['error_message' => null]);
}

/** Create or update a single setting by name. */
function admin_oauth_providers_upsert(string $name, string $value, $session): void {
    $existing = new MultiSetting(['setting_name' => $name], NULL, NULL, NULL, NULL);
    $existing->load();

    $found = false;
    foreach ($existing as $setting) {
        $setting->set('stg_value', $value);
        $setting->set('stg_update_time', 'NOW()');
        $setting->set('stg_usr_user_id', $session->get_user_id());
        $setting->prepare();
        $setting->save();
        $found = true;
    }

    if (!$found) {
        $new = new Setting(NULL);
        $new->set('stg_name', $name);
        $new->set('stg_value', $value);
        $new->set('stg_usr_user_id', $session->get_user_id());
        $new->set('stg_group_name', 'oauth');
        $new->prepare();
        $new->save();
    }
}
