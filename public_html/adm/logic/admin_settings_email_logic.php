<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_settings_email_logic($get, $post) {
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
		$errors = array();

		// Validate email services before saving
		require_once(PathHelper::getIncludePath('includes/EmailSender.php'));

		// Validate primary email service if being set
		if (!empty($post['email_service'])) {
			$primary_validation = EmailSender::validateService($post['email_service']);
			if (!$primary_validation['valid']) {
				$errors[] = 'Primary email service (' . ucfirst($post['email_service']) . ') is not properly configured: ' . implode(', ', $primary_validation['errors']);
			}
		}

		// Validate fallback email service if being set
		if (!empty($post['email_fallback_service'])) {
			$fallback_validation = EmailSender::validateService($post['email_fallback_service']);
			if (!$fallback_validation['valid']) {
				$errors[] = 'Fallback email service (' . ucfirst($post['email_fallback_service']) . ') is not properly configured: ' . implode(', ', $fallback_validation['errors']);
			}
		}

		// If validation errors, return to form with errors
		if (!empty($errors)) {
			return LogicResult::render(array(
				'run_validation' => true,
				'errors' => $errors
			));
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
		return LogicResult::redirect('/admin/admin_settings');
	}

	return LogicResult::render(array(
		'run_validation' => $run_validation
	));
}
?>
