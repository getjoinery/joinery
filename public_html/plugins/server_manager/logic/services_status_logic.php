<?php
/**
 * services_status - where this site stands with each service it holds.
 *
 * (specs/services_phase2_platform.md §4, umbrella contract C2). Polled by the
 * site daily and on its wizard step's load, over the connected key. Per
 * service: figure, allowance, paid_until, state, notice, and the first door
 * (action_label, action_url). The site writes these into its banner settings
 * and never interprets them further. The host label on each row is refreshed
 * from the call; a mail domain not yet verified is asked about once.
 *
 * @version 1.0
 */
function services_status_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	$session = SessionControl::get_instance();
	$user_id = intval($session->get_user_id());
	if (!$user_id) {
		return LogicResult::error('You must be signed in.');
	}
	$key_id = intval($session->get_api_key_id());
	if ($key_id <= 0) {
		return LogicResult::error('A site is identified by its connected key; this request carried none.');
	}
	// A POST, so the host label and a verification probe fold into the
	// tenant rows as any action's writes do.
	return LogicResult::render(JoineryServices::status($user_id, $key_id, trim((string)($input['host'] ?? ''))));
}

function services_status_logic_descriptor(): array {
	return array(
		'description'      => 'This site\'s standing with each of the operator\'s services it holds: figure, allowance, paid-through date, state, notice, and the link to its own account with the provider.',
		'requires_session' => true,
		'mutates'          => false,
		'input'            => array(
			'host' => array('type' => 'string', 'required' => false, 'label' => 'This site\'s hostname'),
		),
	);
}
?>
