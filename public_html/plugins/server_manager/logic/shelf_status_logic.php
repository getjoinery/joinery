<?php
/**
 * shelf_status - backup storage tenant's standing: the C2 fields for backup storage,
 * plus the sentence saying why it cannot write now, if any.
 *
 * (specs/services_phase2_platform.md §3). What the site's Test button asks.
 *
 * @version 1.1 - answers readable beside writable
 */
function shelf_status_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	$session = SessionControl::get_instance();
	$user_id = intval($session->get_user_id());
	if (!$user_id) {
		return LogicResult::error('You must be signed in.');
	}
	try {
		$row = ShelfBroker::tenantFor($user_id, intval($session->get_api_key_id()));
	} catch (ShelfBrokerException $e) {
		return LogicResult::error($e->getMessage());
	}
	ShelfBroker::refreshFigure($row);
	return LogicResult::render(JoineryServices::statusOf($row) + array(
		'writable' => ShelfBroker::refusal($row) === '',
		'refusal'  => ShelfBroker::refusal($row),
		'readable' => ShelfBroker::readRefusal($row) === '',
	));
}

function shelf_status_logic_descriptor(): array {
	return array(
		'description'      => 'This site\'s standing on the operator\'s backup storage: figure, allowance, paid-through date, state, notice, whether a run would be accepted now, and whether its copies can still be read.',
		'requires_session' => true,
		'mutates'          => false,
		'input'            => array(),
	);
}
?>
