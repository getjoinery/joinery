<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_settings_logic($get, $post) {
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
	$run_validation = isset($get['run_validation']) && $get['run_validation'] == '1';

	if($post){

		// Validate: plugin theme requires a plugin to be selected
		if (isset($post['theme_template']) && $post['theme_template'] === 'plugin' && empty($post['active_theme_plugin'])) {
			return LogicResult::render(array(
				'run_validation' => $run_validation,
				'error_message' => 'You must select an Active Theme Plugin when using "Plugin Provided Theme".'
			));
		}

		if($settings->get_setting('preview_image') != $post['preview_image']){
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

		foreach($user_settings as $user_setting) {
			if(isset($post[$user_setting->get('stg_name')])){
				$user_setting->set('stg_value', $post[$user_setting->get('stg_name')]);
				$user_setting->set('stg_update_time', 'NOW()');
				$user_setting->set('stg_usr_user_id', $session->get_user_id());
				$user_setting->prepare();
				$user_setting->save();
			}
		}

		// Track which settings we've processed
		$processed_settings = array();
		foreach($user_settings as $user_setting) {
			$processed_settings[] = $user_setting->get('stg_name');
		}

		// Auto-create any missing settings that were submitted
		foreach($post as $setting_name => $setting_value) {
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

		return LogicResult::redirect('/admin/admin_settings');
	}

	return LogicResult::render(array(
		'run_validation' => $run_validation,
		'error_message' => null
	));
}
?>
