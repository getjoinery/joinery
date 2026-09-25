<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function vault_unlock_recovery_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));
	require_once(PathHelper::getIncludePath('includes/VaultCeremonies.php'));
	require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('passkeys_enabled')) {
		return LogicResult::error('Passkeys are not enabled.');
	}

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user = new User($session->get_user_id(), TRUE);

	if (!RequestLogger::check_rate_limit('vault_unlock_recovery', 10, 900, false)) {
		return LogicResult::error('Too many attempts. Please wait a few minutes and try again.');
	}

	$vault = UserEncryptionVault::loadForUser($user->key);
	if (!$vault) {
		return LogicResult::error('Your vault is not set up yet.');
	}

	// The code opens the vault on its own: it is what someone who lost their
	// passkey holds, and the account's sign-in second factor (an authenticator
	// code) never takes part in opening a vault. What guards a used code is
	// below: it ends every other open window and alerts the account by email.
	// The browser posts only the account half of the code's KEK; the code
	// itself never reaches here (specs/one_vault_experience.md § R6).
	try {
		$kek = VaultCeremonies::decodeKek($input['code_kek'] ?? '');
		$ceremonies = new VaultCeremonies();
		$result = $ceremonies->unlockWithRecoveryKek($user, $vault, $kek);
	} catch (VaultCeremonyException $e) {
		RequestLogger::log('vault_unlock_recovery', 'verify', false, ['user_id' => $user->key]);
		return LogicResult::error($e->getMessage());
	}

	RequestLogger::log('vault_unlock_recovery', 'verify', true, ['user_id' => $user->key]);

	// Notify the account immediately — the reach-every-device channel that does
	// exist is the account email (a richer multi-device push rides the native
	// package). Best-effort: a failed alert never blocks the unlock.
	try {
		$to = (string)$user->get('usr_email');
		if ($to !== '') {
			$site = (string)$settings->get_setting('site_name');
			EmailSender::quickSend(
				$to,
				trim($site . ' security alert'),
				"A vault recovery code was just used on your account. If this was you, no action is needed — "
				. "all other unlocked sessions were signed out of your vault as a precaution, and linked computers "
				. "and phones need linking again. If this was NOT you, "
				. "change your password immediately from a device you trust."
			);
		}
	} catch (\Throwable $e) {
		error_log('vault_unlock_recovery: alert email failed for user ' . $user->key . ': ' . $e->getMessage());
	}

	return LogicResult::render([
		'unlocked' => true,
		'regenerate_recommended' => $result['regenerate_recommended'],
		// The same code's twin on the root vault, spent with it: the browser
		// opens the root with its own half of the code's KEK.
		'root_wrapping' => $result['root_wrapping'],
	]);
}

function vault_unlock_recovery_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Unlock the vault with a one-time recovery code: the account half of its KEK, derived in the browser (the code itself is never sent). Spends the code\'s root-vault twin with it and returns that twin as root_wrapping',
		'input' => [
			'code_kek' => ['type' => 'password', 'required' => true, 'label' => 'Account half of the recovery code KEK (base64url, 32 bytes)'],
		],
	];
}
?>
