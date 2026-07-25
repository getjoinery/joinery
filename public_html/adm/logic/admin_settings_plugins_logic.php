<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

/**
 * Plugin Settings tab.
 *
 * Each plugin section on the page is an independent form, so a save carries
 * exactly one plugin's fields and names that plugin in `plugin_settings_target`.
 * The write scope is that one plugin's declared settings — nothing else in the
 * POST is written, so a crafted post cannot reach a core or sibling-plugin row.
 *
 * @version 1.0
 */
function admin_settings_plugins_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/settings_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	// Only active plugins contribute a section. A deactivated plugin's stored
	// rows persist untouched but are neither shown nor writable here.
	$plugin_forms = PluginHelper::getSettingsForms();

	// Only run the save handler on an actual form POST — $input is never empty
	// on a GET. See LibraryFunctions::isFormSubmission().
	if (!LibraryFunctions::isFormSubmission()) {
		return LogicResult::render(array('plugin_forms' => $plugin_forms));
	}

	$target = isset($input['plugin_settings_target'])
		? trim((string)$input['plugin_settings_target'])
		: '';
	if ($target === '' || !isset($plugin_forms[$target])) {
		return LogicResult::error(
			'That save named a plugin with no settings form on this site.',
			array('plugin_forms' => $plugin_forms)
		);
	}

	// The allowed set is the submitting plugin's own declarations from its
	// plugin.json `settings` block — the same list that seeds its rows.
	$declared = array();
	foreach (PluginHelper::getInstance($target)->getDeclaredSettings() as $declaration) {
		if (is_array($declaration) && !empty($declaration['name'])) {
			$declared[(string)$declaration['name']] = true;
		}
	}
	if (empty($declared)) {
		return LogicResult::error(
			ucfirst($target) . ' declares no settings in its plugin.json, so there is nothing to save.',
			array('plugin_forms' => $plugin_forms)
		);
	}

	// Vault-gated settings (specs/mailbox_security_levels.md § Vault-Gated
	// Settings): a change to a setting that redirects protected mail's plaintext
	// is refused unless the acting account holds an open unlock window. The
	// gated names are plugin-declared, so they can only ever land on this page.
	require_once(PathHelper::getIncludePath('includes/VaultGatedSettings.php'));
	require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
	require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
	$acting_uid = (int)$session->get_user_id();
	$acting_has_vault = ($acting_uid > 0) && (UserEncryptionVault::loadForUser($acting_uid) !== null);
	$vault_window_open = $acting_has_vault && VaultUnlock::isOpen($acting_uid);
	$vault_blocked_settings = array();

	$user_settings = new MultiSetting(array(), NULL, NULL, NULL, NULL);
	$user_settings->load();

	$existing_names = array();
	foreach ($user_settings as $user_setting) {
		$stg_name = $user_setting->get('stg_name');
		$existing_names[$stg_name] = true;

		if (!isset($declared[$stg_name]) || !isset($input[$stg_name])) {
			continue;
		}
		$value = $input[$stg_name];

		// Only gate a genuine change (unchanged value re-submitted is a no-op).
		if ($acting_has_vault && !$vault_window_open
				&& VaultGatedSettings::isGated($stg_name)
				&& (string)$value !== (string)$user_setting->get('stg_value')) {
			$vault_blocked_settings[] = $stg_name;
			continue;
		}

		// An unchanged field is not a write. The form posts every field in the
		// section, so without this one Save re-stamps stg_update_time on rows
		// that did not change — which makes the column useless for answering
		// "when did this value actually change?".
		if ((string)$value === (string)$user_setting->get('stg_value')) {
			continue;
		}

		$user_setting->set('stg_value', $value);
		$user_setting->set('stg_update_time', 'NOW()');
		$user_setting->set('stg_usr_user_id', $session->get_user_id());
		$user_setting->prepare();
		$user_setting->save();
	}

	// A declared setting whose row is missing (activation seeded before the
	// declaration was added) is created rather than silently dropped.
	foreach ($declared as $stg_name => $unused) {
		if (isset($existing_names[$stg_name]) || !isset($input[$stg_name])) {
			continue;
		}
		if ($acting_has_vault && !$vault_window_open && VaultGatedSettings::isGated($stg_name)) {
			$vault_blocked_settings[] = $stg_name;
			continue;
		}
		$new_setting = new Setting(NULL);
		$new_setting->set('stg_name', $stg_name);
		$new_setting->set('stg_value', $input[$stg_name]);
		$new_setting->set('stg_usr_user_id', $session->get_user_id());
		$new_setting->set('stg_group_name', 'general');
		try {
			$new_setting->prepare();
			$new_setting->save();
		} catch (Exception $e) {
			error_log("admin_settings_plugins: failed to create '{$stg_name}': " . $e->getMessage());
		}
	}

	if (!empty($vault_blocked_settings)) {
		$session->save_message(new DisplayMessage(
			'Unlock your vault to change these protected settings, then save again: '
				. htmlspecialchars(implode(', ', $vault_blocked_settings)) . '. '
				. 'Other settings were saved.',
			'Unlock required',
			'~/admin/admin_settings_plugins~',
			DisplayMessage::MESSAGE_WARNING,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
	}

	return LogicResult::redirect('/admin/admin_settings_plugins');
}
?>
