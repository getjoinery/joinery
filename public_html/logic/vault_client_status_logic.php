<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

/**
 * Client-custody vault status: the keyring view the browser needs to unlock a
 * scope (public key, KDF salt/params, and every wrapping's opaque blob). No
 * secret material - the blobs are useless without a KEK the server never holds.
 */
function vault_client_status_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/VaultClientCustody.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user_id = (int)$session->get_user_id();

	$scope = isset($input['scope']) ? (string)$input['scope'] : '';
	try {
		VaultClientCustody::assertClientScope($scope);
		return LogicResult::render(VaultClientCustody::statusPayload($user_id, $scope));
	} catch (VaultClientCustodyException $e) {
		return LogicResult::error($e->getMessage());
	}
}

function vault_client_status_logic_api() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Client-custody vault keyring status for a scope (public key, KDF params, opaque wrapping blobs) - no secret material',
	];
}
?>
