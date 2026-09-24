<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

/**
 * Store DEKs the browser re-sealed to a client-custody scope's new key during a
 * rotation. Rewrites the key column and the generation and nothing else; the
 * caller must own each row, and each blob's scope must be the row's.
 * Takes one row ({model, id, sealed_dek}) or several ({rows: [...]}).
 *
 * @version 1.0
 */
function vault_row_reseal_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user_id = (int)$session->get_user_id();

	$rows = isset($input['rows']) && is_array($input['rows'])
		? $input['rows']
		: [['model' => $input['model'] ?? '', 'id' => $input['id'] ?? 0, 'sealed_dek' => $input['sealed_dek'] ?? '']];
	if (count($rows) > VaultClientRotation::PAGE_MAX) {
		return LogicResult::error('Too many rows in one request.');
	}
	try {
		return LogicResult::render(['written' => VaultClientRotation::resealRows($user_id, $rows)]);
	} catch (VaultClientCustodyException $e) {
		return LogicResult::error($e->getMessage());
	}
}

function vault_row_reseal_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'mutates' => true,
		'description' => 'Store DEKs re-sealed to a client-custody scope\'s new key during a rotation (key column and generation only)',
		'input' => [
			'model'      => ['type' => 'string', 'required' => false, 'max_length' => 128, 'label' => 'Model'],
			'id'         => ['type' => 'int', 'required' => false, 'label' => 'Row id'],
			'sealed_dek' => ['type' => 'string', 'required' => false, 'max_length' => 4096, 'label' => 'Re-sealed DEK'],
			'rows'       => ['type' => 'array', 'required' => false, 'items' => ['type' => 'object'], 'label' => 'Several rows: [{model, id, sealed_dek}]'],
		],
	];
}
?>
