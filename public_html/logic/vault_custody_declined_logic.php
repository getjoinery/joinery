<?php
/**
 * The person answered no when their browser asked whether a hand-over of
 * stored keys was their own doing (JoinerySealed.changeCustody): a row in the
 * audit log (VaultAudit::custodyDeclined()). The count recorded is this
 * server's own, of the rows that were waiting to move, not one the browser
 * sends. Nothing else changes.
 *
 * @version 1.0
 */
function vault_custody_declined_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user_id = (int)$session->get_user_id();
	$scope = (string)($input['scope'] ?? '');
	$how = (string)($input['how'] ?? '') === VaultAudit::ANSWER_CLOSED ? VaultAudit::ANSWER_CLOSED : VaultAudit::ANSWER_NO;

	try {
		$page = VaultCustodyChange::page($user_id, $scope, '', 0, 1);
	} catch (VaultClientCustodyException $e) {
		return LogicResult::error($e->getMessage());
	}
	VaultAudit::custodyDeclined($user_id, $scope, $how, intval($page['remaining'] ?? 0), session_id() ?: null);
	return LogicResult::render(['logged' => true]);
}

function vault_custody_declined_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('min_user_permission' => 0, 'requires_browser_session' => true),
		'mutates' => true,
		'description' => 'Record that the caller declined to hand stored keys of a client-custody scope to the server (audit log only)',
		'input' => [
			'scope' => ['type' => 'string', 'required' => true, 'label' => 'The client-custody scope the rows would have left'],
			'how'   => ['type' => 'string', 'required' => false, 'max_length' => 16, 'label' => 'no (the No button) or closed (the dialog was closed)'],
		],
	];
}
?>
