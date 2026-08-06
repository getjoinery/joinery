<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

/**
 * Enroll another unlocker (a second passkey, or the optional passphrase) on an
 * existing client-custody vault. The browser unlocked, re-wrapped the same
 * secret key under the new unlocker's KEK, and posts the opaque blob. Gated on
 * a recent step-up - adding an unlocker is a credential change.
 */
function vault_client_add_wrapping_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/VaultClientCustody.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user_id = (int)$session->get_user_id();

	$scope = isset($input['scope']) ? (string)$input['scope'] : '';
	$wrapping = isset($input['wrapping']) && is_array($input['wrapping']) ? $input['wrapping'] : null;
	if (!$wrapping) {
		return LogicResult::error('Missing the new wrapping.');
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

		VaultClientCustody::persistWrappings($user_id, $vault, [$wrapping]);
	} catch (VaultClientCustodyException $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render(['added' => true]);
}

function vault_client_add_wrapping_logic_api() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Add an unlocker wrapping (browser-produced opaque blob) to an existing client-custody vault; requires a recent step-up',
	];
}
?>
