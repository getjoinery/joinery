<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_settings_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/settings_class.php'));
	require_once(PathHelper::getIncludePath('data/email_templates_class.php'));
	require_once(PathHelper::getIncludePath('data/mailing_lists_class.php'));
	require_once(PathHelper::getIncludePath('data/pages_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	$settings = Globalvars::get_instance();

	// Check if validation should run (performance optimization)
	$run_validation = isset($input['run_validation']) && $input['run_validation'] == '1';

	// Only run the save handler on an actual form POST. Guarding on `if($input)`
	// fired the save+redirect on every GET (producing an infinite redirect to
	// /admin/admin_settings), because `$input` is never empty on a GET — edit
	// pages carry a record id, and historically the `__route` rewrite param too.
	// See LibraryFunctions::isFormSubmission().
	if(LibraryFunctions::isFormSubmission()){

		// Validate: plugin theme requires a plugin to be selected
		if (isset($input['theme_template']) && $input['theme_template'] === 'plugin' && empty($input['active_theme_plugin'])) {
			return LogicResult::render(array(
				'run_validation' => $run_validation,
				'error_message' => 'You must select an Active Theme Plugin when using "Plugin Provided Theme".'
			));
		}

		// Validate: terms_url and privacy_url must be empty, a relative path,
		// or an http(s) URL — never a javascript: or data: scheme.
		foreach (['terms_url', 'privacy_url'] as $url_field) {
			if (!isset($input[$url_field])) continue;
			$candidate = trim($input[$url_field]);
			if ($candidate === '') continue;
			if (!preg_match('#^(/|https?://)#i', $candidate)) {
				return LogicResult::render(array(
					'run_validation' => $run_validation,
					'error_message' => "Invalid {$url_field}: must start with '/', 'http://', or 'https://'."
				));
			}
		}

		// Validate: new theme's required plugins must all be active (only when theme is changing)
		if (isset($input['theme_template']) && $input['theme_template'] !== 'plugin'
			&& $input['theme_template'] !== $settings->get_setting('theme_template')) {
			$new_theme_name = $input['theme_template'];
			try {
				$new_theme = ThemeHelper::getInstance($new_theme_name);
				$required_plugins = $new_theme->get('requires_plugins', []);
				if (!empty($required_plugins)) {
					$inactive_plugins = [];
					foreach ($required_plugins as $plugin_name) {
						if (!PluginHelper::isPluginActive($plugin_name)) {
							$inactive_plugins[] = $plugin_name;
						}
					}
					if (!empty($inactive_plugins)) {
						return LogicResult::render(array(
							'run_validation' => $run_validation,
							'error_message' => "Cannot activate theme '{$new_theme_name}': required plugin(s) not active: " . implode(', ', $inactive_plugins) . ". Activate the plugin(s) first."
						));
					}
				}
			} catch (Exception $e) {
				// Theme not loadable — other validation will surface the error
			}
		}

		if($settings->get_setting('preview_image') != $input['preview_image']){
			//AUTO INCREMENT THE PREVIEW IMAGE INDEX IF IT HAS CHANGED
			$search_criteria = array();
			$search_criteria['setting_name'] = 'preview_image_increment';
			$user_settings = new MultiSetting(
				$search_criteria,
				NULL,
				NULL,
				NULL,
				NULL
			);
			$user_settings->load();
			foreach($user_settings as $user_setting) {
				if($user_setting->get('stg_name') == 'preview_image_increment'){
					$user_setting->set('stg_value', $settings->get_setting('preview_image_increment') + 1);
					$user_setting->set('stg_update_time', 'NOW()');
					$user_setting->set('stg_usr_user_id', $session->get_user_id());
					$user_setting->prepare();
					$user_setting->save();
				}
			}
		}

		$search_criteria = array();
		//$search_criteria['setting_like'] = $searchterm;
		$user_settings = new MultiSetting(
			$search_criteria,
			NULL,
			NULL,
			NULL,
			NULL);
		$user_settings->load();

		// Vault-gated settings (specs/mailbox_security_levels.md § Vault-Gated
		// Settings): a change to a setting that redirects protected mail's
		// plaintext is refused unless the acting account holds an open unlock
		// window. Resolve the acting user's vault state once.
		require_once(PathHelper::getIncludePath('includes/VaultGatedSettings.php'));
		require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
		require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
		$acting_uid = (int)$session->get_user_id();
		$acting_has_vault = ($acting_uid > 0) && (UserEncryptionVault::loadForUser($acting_uid) !== null);
		$vault_window_open = $acting_has_vault && VaultUnlock::isOpen($acting_uid);
		$vault_blocked_settings = array();

		foreach($user_settings as $user_setting) {
			if(isset($input[$user_setting->get('stg_name')])){
				$stg_name = $user_setting->get('stg_name');
				$value = $input[$stg_name];
				// Only gate a genuine change (unchanged value re-submitted is a no-op).
				if ($acting_has_vault && !$vault_window_open
						&& VaultGatedSettings::isGated($stg_name)
						&& (string)$value !== (string)$user_setting->get('stg_value')) {
					$vault_blocked_settings[] = $stg_name;
					continue;
				}
				if ($stg_name === 'webDir') {
					$value = rtrim(preg_replace('#^https?://#i', '', $value), '/');
				}
				// An unchanged field is not a write. The settings form posts every
				// setting on the page, so without this a single Save re-stamped
				// stg_update_time on ~160 rows — which makes the column useless for
				// answering "when did this value actually change?" and destroys the
				// audit trail for the ones that did.
				if ((string)$value === (string)$user_setting->get('stg_value')) {
					continue;
				}
				$user_setting->set('stg_value', $value);
				$user_setting->set('stg_update_time', 'NOW()');
				$user_setting->set('stg_usr_user_id', $session->get_user_id());
				$user_setting->prepare();
				$user_setting->save();
			}
		}

		if (!empty($vault_blocked_settings)) {
			require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
			$session->save_message(new DisplayMessage(
				'Unlock your vault to change these protected settings, then save again: '
					. htmlspecialchars(implode(', ', $vault_blocked_settings)) . '. '
					. 'Other settings were saved.',
				'Unlock required',
				'~/admin/admin_settings~',
				DisplayMessage::MESSAGE_WARNING,
				DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
		}

		// Track which settings we've processed
		$processed_settings = array();
		foreach($user_settings as $user_setting) {
			$processed_settings[] = $user_setting->get('stg_name');
		}

		// Auto-create any missing settings that were submitted
		foreach($input as $setting_name => $setting_value) {
			// Skip if already processed (already exists in database)
			if(in_array($setting_name, $processed_settings)) continue;

			// Create new setting - only happens on explicit save
			error_log("Settings: Creating new setting '{$setting_name}' with value '{$setting_value}'");

			$new_setting = new Setting(NULL);
			$new_setting->set('stg_name', $setting_name);
			$new_setting->set('stg_value', $setting_value);
			$new_setting->set('stg_usr_user_id', $session->get_user_id());
			$new_setting->set('stg_group_name', 'general');

			try {
				$new_setting->prepare();
				$new_setting->save();
			} catch(Exception $e) {
				// Setting might already exist (race condition) or validation error
				error_log("Settings: Failed to create '{$setting_name}': " . $e->getMessage());
			}
		}

		// Invalidate homepage cache if homepage-routing settings changed
		if (isset($input['alternate_homepage']) || isset($input['alternate_loggedin_homepage'])) {
			require_once(PathHelper::getIncludePath('includes/StaticPageCache.php'));
			StaticPageCache::invalidateUrl('/');
		}

		// Flush entire cache if active theme changed
		if (isset($input['active_theme'])) {
			require_once(PathHelper::getIncludePath('includes/StaticPageCache.php'));
			StaticPageCache::clearAll();
		}

		return LogicResult::redirect('/admin/admin_settings');
	}

	return LogicResult::render(array(
		'run_validation' => $run_validation,
		'error_message' => null
	));
}
?>
