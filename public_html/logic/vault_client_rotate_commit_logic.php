<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

/**
 * Finish rotating a client-custody vault key: the old generation's wrappings
 * retire, the pending key becomes the key, and linked devices holding the
 * scope lose it (they must re-link). Refused while any registered row is still
 * on the old key.
 *
 * @version 1.0
 */
function vault_client_rotate_commit_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user_id = (int)$session->get_user_id();

	try {
		return LogicResult::render(VaultClientRotation::commit($user_id, (string)($input['scope'] ?? '')));
	} catch (VaultClientCustodyException $e) {
		return LogicResult::error($e->getMessage());
	}
}

function vault_client_rotate_commit_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'mutates' => true,
		'description' => 'Commit a pending client-custody key rotation: retire the old wrappings, make the pending key current, clear the scope from linked devices',
		'input' => [
			'scope' => ['type' => 'string', 'required' => true, 'label' => 'Client-custody scope'],
		],
	];
}
?>
