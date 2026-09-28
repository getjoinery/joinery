<?php
/**
 * Store DEKs the browser re-sealed for rows leaving a client-custody scope,
 * each to the vault its hook now names. Rewrites the key column, generation
 * and owner and nothing else; the caller must own each row.
 *
 * @version 1.0
 */
function vault_row_custody_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user_id = (int)$session->get_user_id();

	$rows = isset($input['rows']) && is_array($input['rows']) ? $input['rows'] : [];
	try {
		return LogicResult::render(['written' => VaultCustodyChange::accept($user_id, (string)($input['scope'] ?? ''), $rows)]);
	} catch (VaultClientCustodyException $e) {
		return LogicResult::error($e->getMessage());
	}
}

function vault_row_custody_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'mutates' => true,
		'description' => 'Store DEKs re-sealed for rows leaving a client-custody scope (key column, generation and owner only)',
		'input' => [
			'scope' => ['type' => 'string', 'required' => true, 'label' => 'The client-custody scope the rows leave'],
			'rows'  => ['type' => 'array', 'required' => true, 'items' => ['type' => 'object'], 'label' => 'Rows: [{model, id, sealed_dek}]'],
		],
	];
}
?>
