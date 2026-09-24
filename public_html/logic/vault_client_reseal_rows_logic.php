<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

/**
 * One page of the caller's rows sealed to a client-custody scope's rotating
 * key, across the models registered with VaultUnlock::clientReseal(): the
 * browser opens each sealed DEK with the old key and re-seals it to the new
 * one (vault_row_reseal). Rows already moved are not listed.
 *
 * @version 1.0
 */
function vault_client_reseal_rows_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user_id = (int)$session->get_user_id();

	try {
		return LogicResult::render(VaultClientRotation::resealPage($user_id, (string)($input['scope'] ?? ''),
			(string)($input['model'] ?? ''), (int)($input['after_id'] ?? 0), (int)($input['limit'] ?? VaultClientRotation::PAGE_MAX)));
	} catch (VaultClientCustodyException $e) {
		return LogicResult::error($e->getMessage());
	}
}

function vault_client_reseal_rows_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'mutates' => false,
		'description' => 'Page the caller\'s rows whose DEK is sealed to a client-custody scope\'s rotating key: {rows:[{model,id,sealed_dek}], next:{model,after_id}|null, remaining}',
		'input' => [
			'scope'    => ['type' => 'string', 'required' => true, 'label' => 'Client-custody scope'],
			'model'    => ['type' => 'string', 'required' => false, 'max_length' => 128, 'label' => 'Cursor: model'],
			'after_id' => ['type' => 'int', 'required' => false, 'label' => 'Cursor: last row id'],
			'limit'    => ['type' => 'int', 'required' => false, 'label' => 'Rows per page'],
		],
	];
}
?>
