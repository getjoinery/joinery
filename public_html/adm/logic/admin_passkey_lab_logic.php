<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_passkey_lab_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/passkeys_class.php'));
	require_once(PathHelper::getIncludePath('data/request_logs_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$settings = Globalvars::get_instance();
	$passkeys_enabled = (bool)$settings->get_setting('passkeys_enabled');

	// The lab runs ceremonies against the signed-in superadmin's own
	// credentials - enroll the authenticators under test on this account.
	$credentials = [];
	$creds = new MultiPasskey(['user_id' => $session->get_user_id()], ['pkc_created_time' => 'ASC']);
	$creds->load();
	foreach ($creds as $passkey) {
		$transports = json_decode($passkey->get('pkc_transports') ?: '[]', true) ?: [];
		$credentials[] = [
			'credential_id' => $passkey->get('pkc_credential_id'),
			'label' => $passkey->get('pkc_label'),
			'transports' => $transports,
			'is_platform' => in_array('internal', $transports, true),
			'prf_capable' => (bool)$passkey->get('pkc_prf_capable'),
			// The verdict and the signals it was built from, side by side — the
			// lab is where someone goes when they do not believe the badge on
			// /profile/security, so it has to show its work.
			'vault_capability' => $passkey->vault_capability(),
			'discoverable' => $passkey->get('pkc_discoverable'),
			'attachment' => $passkey->get('pkc_attachment'),
			'uv_never_performed' => $passkey->uv_never_performed(),
			'created_time' => $passkey->get('pkc_created_time'),
			'last_used_time' => $passkey->get('pkc_last_used_time'),
		];
	}

	$recent = [];
	$logs = new MultiRequestLog(
		['feature' => 'passkey_lab'],
		['rql_create_time' => 'DESC'],
		30
	);
	$logs->load();
	foreach ($logs as $log) {
		$recent[] = [
			'time' => $log->get('rql_create_time'),
			'action' => $log->get('rql_action'),
			'success' => (bool)$log->get('rql_was_success'),
			'note' => $log->get('rql_note'),
			'response_ms' => $log->get('rql_response_ms'),
		];
	}

	return LogicResult::render([
		'session' => $session,
		'passkeys_enabled' => $passkeys_enabled,
		'credentials' => $credentials,
		'recent' => $recent,
	]);
}
?>
