<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_settings_email_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/settings_class.php'));
	require_once(PathHelper::getIncludePath('includes/SettingsDeclarations.php'));
	require_once(PathHelper::getIncludePath('includes/SettingsWriter.php'));
	require_once(PathHelper::getIncludePath('data/email_templates_class.php'));
	require_once(PathHelper::getIncludePath('data/mailing_lists_class.php'));
	require_once(PathHelper::getIncludePath('data/pages_class.php'));

	$session = SessionControl::get_instance();

	$settings = Globalvars::get_instance();

	// Check if validation should run (performance optimization)
	$run_validation = isset($input['run_validation']) && $input['run_validation'] == '1';

	// Only run the save handler on an actual form POST — see admin_settings_logic
	// for why `if($input)` looped ($input is never empty on a GET).
	// See LibraryFunctions::isFormSubmission().
	if(LibraryFunctions::isFormSubmission()){
		require_once(PathHelper::getIncludePath('includes/EmailSender.php'));

		// The services chosen are checked against what this save leaves stored,
		// not against what was stored before it: choosing a provider and typing
		// its key in one submit is the ordinary first setup, and a blank secret
		// field means "keep the stored one", which only the stored value knows.
		$previous = array(
			'email_service'          => (string)$settings->get_setting('email_service'),
			'email_fallback_service' => (string)$settings->get_setting('email_fallback_service'),
		);

		// One save path for every settings page — scope, validation, credential
		// handling and the vault gate all come from the declarations. See
		// includes/SettingsWriter.php.
		$write = SettingsWriter::write($input, array('page' => 'admin_settings_email'));
		SettingsWriter::reportTo($write, '~/admin/admin_settings_email~');

		if (!empty($write['errors'])) {
			return LogicResult::render(array(
				'run_validation' => true,
				'errors' => array('Nothing was saved — fix the fields flagged above and save again.')
			));
		}
		foreach ($write['written'] as $name) {
			$settings->forget_setting($name);
		}

		// A chosen service that is still not configured goes back to what was
		// chosen before; everything else this save wrote stays.
		$errors = array();
		$labels = array('email_service' => 'Primary email service', 'email_fallback_service' => 'Fallback email service');
		foreach ($labels as $name => $label) {
			$chosen = trim((string)($input[$name] ?? ''));
			if ($chosen === '' || $chosen === $previous[$name]) {
				continue;
			}
			$check = EmailSender::validateService($chosen);
			if (!$check['valid']) {
				Setting::put($name, $previous[$name]);
				$settings->forget_setting($name);
				$errors[] = $label . ' (' . ucfirst($chosen) . ') is not properly configured: ' . implode(', ', $check['errors'])
					. '. The rest of your changes were saved; the ' . strtolower($label) . ' is still '
					. ($previous[$name] !== '' ? ucfirst($previous[$name]) : 'not chosen') . '.';
			}
		}
		if (!empty($errors)) {
			return LogicResult::render(array(
				'run_validation' => true,
				'errors' => $errors
			));
		}

		return LogicResult::redirect('/admin/admin_settings');
	}

	return LogicResult::render(array(
		'run_validation' => $run_validation
	));
}
?>
