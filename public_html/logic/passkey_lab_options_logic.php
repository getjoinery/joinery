<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

/**
 * Superadmin passkey lab: mint a diagnostic assertion ceremony with a
 * caller-chosen shape (uv / prf / credential subset). See
 * adm/admin_passkey_lab.php.
 */
function passkey_lab_options_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/PasskeyService.php'));
	require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('passkeys_enabled')) {
		return LogicResult::error('Passkeys are not enabled.');
	}

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	if ((int)$session->get_permission() < 10) {
		return LogicResult::error('Not authorized.');
	}
	$user = new User($session->get_user_id(), TRUE);

	$variant_key = substr(trim((string)($input['variant'] ?? 'unnamed')), 0, 40);
	$variant = [
		'uv' => (string)($input['uv'] ?? 'required'),
		'prf' => !empty($input['prf']),
		'credential_ids' => (array)($input['credential_ids'] ?? []),
	];

	try {
		$service = new PasskeyService();
		$options = $service->getDiagnosticOptions($user, $variant);
	} catch (Exception $e) {
		return LogicResult::error($e->getMessage());
	}

	RequestLogger::log('passkey_lab', 'options:' . $variant_key, true, [
		'user_id' => $user->key,
		'note' => 'uv=' . $variant['uv'] . ' prf=' . ($variant['prf'] ? '1' : '0')
			. ' creds=' . (count($variant['credential_ids']) ?: 'all'),
	]);

	return LogicResult::render(['options' => $options]);
}

function passkey_lab_options_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Passkey lab (superadmin): begin a diagnostic assertion ceremony with a chosen options shape',
	];
}
?>
