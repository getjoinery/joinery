<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_settings_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/settings_class.php'));
	require_once(PathHelper::getIncludePath('includes/SettingsDeclarations.php'));
	require_once(PathHelper::getIncludePath('includes/SettingsWriter.php'));
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

		// terms_url and privacy_url must be empty, a relative path or an http(s)
		// URL — never a javascript: or data: scheme. That is a rule about one
		// value, so it lives on the declaration in settings.json and applies to
		// every page that can write it, not just this one.

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

		// A changed preview image needs a new cache-busting index. This is a
		// side effect of a change, not a rule about a value, so it stays here
		// rather than moving to the declaration.
		$preview_image_changed = isset($input['preview_image'])
			&& $settings->get_setting('preview_image') != $input['preview_image'];

		// One save path for every settings page. Scope, validation, credential
		// handling and the vault gate all come from the declarations rather
		// than from this page — see includes/SettingsWriter.php.
		$write = SettingsWriter::write($input, array('page' => 'admin_settings'));
		SettingsWriter::reportTo($write, '~/admin/admin_settings~');

		if (!empty($write['errors'])) {
			return LogicResult::render(array(
				'run_validation' => $run_validation,
				'error_message'  => 'Nothing was saved — fix the fields flagged above and save again.'
			));
		}

		if ($preview_image_changed) {
			$increments = new MultiSetting(
				array('setting_name' => 'preview_image_increment'),
				NULL, NULL, NULL, NULL
			);
			$increments->load();
			foreach($increments as $increment) {
				$increment->set('stg_value', $settings->get_setting('preview_image_increment') + 1);
				$increment->set('stg_update_time', 'NOW()');
				$increment->set('stg_usr_user_id', $session->get_user_id());
				$increment->prepare();
				$increment->save();
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
