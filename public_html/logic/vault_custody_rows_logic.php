<?php
/**
 * One page of the caller's rows under a client-custody scope whose hook now
 * names another vault (a mailbox moved off end-to-end): the browser opens each
 * sealed DEK and re-seals it to target_public_key (vault_row_custody). Rows
 * already moved are not listed.
 *
 * @version 1.0
 */
function vault_custody_rows_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user_id = (int)$session->get_user_id();

	try {
		return LogicResult::render(VaultCustodyChange::page($user_id, (string)($input['scope'] ?? ''),
			(string)($input['model'] ?? ''), (int)($input['after_id'] ?? 0), (int)($input['limit'] ?? VaultCustodyChange::PAGE_MAX)));
	} catch (VaultClientCustodyException $e) {
		return LogicResult::error($e->getMessage());
	}
}

function vault_custody_rows_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'mutates' => false,
		'description' => 'Page the caller\'s rows leaving a client-custody scope: {rows:[{model,id,sealed_dek,target_scope,target_public_key}], next:{model,after_id}|null, remaining|null}',
		'input' => [
			'scope'    => ['type' => 'string', 'required' => true, 'label' => 'Client-custody scope'],
			'model'    => ['type' => 'string', 'required' => false, 'max_length' => 128, 'label' => 'Cursor: model'],
			'after_id' => ['type' => 'int', 'required' => false, 'label' => 'Cursor: last row id'],
			'limit'    => ['type' => 'int', 'required' => false, 'label' => 'Rows per page'],
		],
	];
}
?>
