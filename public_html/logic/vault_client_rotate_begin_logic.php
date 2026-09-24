<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

/**
 * Start rotating a client-custody vault's keypair: store the browser-made new
 * public key and its wrappings as the pending generation (VaultClientRotation).
 * The key in use and every unlocker it has keep working until the commit.
 * Gated on a recent step-up, like any change to a vault's unlockers.
 *
 * `dry_run` checks everything begin would (the step-up, no rotation pending,
 * every consumer able to re-seal) and writes nothing: the browser asks it before
 * collecting passkey taps and the passphrase, so a refusal costs nothing.
 *
 * @version 1.1 - dry_run
 */
function vault_client_rotate_begin_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user_id = (int)$session->get_user_id();

	if ($session->step_up_outstanding(null, 300)) {
		return LogicResult::error('Confirm it is you before rotating your vault key.', ['requires_stepup' => true]);
	}

	$scope = (string)($input['scope'] ?? '');
	$public_key = (string)($input['public_key'] ?? '');
	$wrappings = isset($input['wrappings']) && is_array($input['wrappings']) ? $input['wrappings'] : [];
	try {
		if (!empty($input['dry_run'])) {
			VaultClientRotation::assertCanBegin($user_id, $scope);
			return LogicResult::render(['can_begin' => true]);
		}
		return LogicResult::render(VaultClientRotation::begin($user_id, $scope, $public_key, $wrappings));
	} catch (VaultClientCustodyException $e) {
		return LogicResult::error($e->getMessage());
	}
}

function vault_client_rotate_begin_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'mutates' => true,
		'description' => 'Begin rotating a client-custody vault key: the new public key and its browser-produced wrappings are stored as the pending generation; requires a recent step-up',
		'input' => [
			'scope'      => ['type' => 'string', 'required' => true, 'label' => 'Client-custody scope'],
			'public_key' => ['type' => 'string', 'required' => false, 'label' => 'New vault public key'],
			'wrappings'  => ['type' => 'array', 'required' => false, 'items' => ['type' => 'object'], 'label' => 'Wrappings of the new secret key'],
			'dry_run'    => ['type' => 'bool', 'required' => false, 'label' => 'Only check that a rotation can begin'],
		],
	];
}
?>
