<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

/**
 * Retire one unlocker wrapping from a client-custody vault. Refused by the
 * shared unlocker floor if it would leave the vault with no working unlocker.
 * Gated on a recent step-up.
 */
function vault_client_remove_wrapping_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
	require_once(PathHelper::getIncludePath('includes/VaultClientCustody.php'));
	require_once(PathHelper::getIncludePath('data/user_encryption_wrappings_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user_id = (int)$session->get_user_id();

	$scope = isset($input['scope']) ? (string)$input['scope'] : '';
	$wrapping_id = isset($input['wrapping_id']) ? (int)$input['wrapping_id'] : 0;
	if (!$wrapping_id) {
		return LogicResult::error('Missing the unlocker to remove.');
	}

	try {
		VaultClientCustody::assertClientScope($scope);
		$vault = VaultClientCustody::loadVault($user_id, $scope);
		if (!$vault) {
			return LogicResult::error('Your vault is not set up.');
		}

		if ($session->step_up_outstanding(null, 300)) {
			return LogicResult::error('Confirm with your passkey before changing your vault unlockers.', ['requires_stepup' => true]);
		}

		$wrapping = new UserEncryptionWrapping($wrapping_id, TRUE);
		if (!$wrapping->key || (int)$wrapping->get('uew_uev_user_encryption_vault_id') !== (int)$vault->key) {
			return LogicResult::error('That unlocker does not belong to your vault.');
		}

		$exclude = $wrapping->get('uew_unlocker_type') === UserEncryptionWrapping::TYPE_PASSKEY
			? (int)$wrapping->get('uew_pkc_credential_id') : null;
		try {
			VaultUnlock::assertWrappingDeleteSafe((int)$vault->key, $exclude, (int)$wrapping->key);
		} catch (RuntimeException $e) {
			return LogicResult::error('This would leave your vault with no working unlocker. Add another passkey or keep at least 3 unused recovery keys first.');
		}

		$wrapping->soft_delete();
	} catch (VaultClientCustodyException $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render(['removed' => true]);
}

function vault_client_remove_wrapping_logic_api() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Retire one unlocker wrapping from a client-custody vault (unlocker-floor enforced); requires a recent step-up',
	];
}
?>
