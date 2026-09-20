<?php
/**
 * services_release - the site leaves one of this operator's services.
 *
 * (specs/services_phase2_platform.md §4, §9 the switch-over). The customer's
 * own act, once their own provider is proven: mail's subaccount is closed and
 * the records this plane published are listed for replacement; the shelf is
 * marked released so the broker refuses it, and its copies are kept
 * RETENTION_DAYS from today before pruning. Idempotent.
 *
 * @version 1.0
 */
function services_release_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	$session = SessionControl::get_instance();
	$user_id = intval($session->get_user_id());
	if (!$user_id) {
		return LogicResult::error('You must be signed in.');
	}
	$key_id = intval($session->get_api_key_id());
	$service = trim((string)($input['service'] ?? ''));
	try {
		$data = JoineryServices::release($user_id, $key_id, $service);
	} catch (JoineryServicesException $e) {
		return LogicResult::error($e->getMessage());
	} catch (Smtp2GoException $e) {
		return LogicResult::error('The mail provider refused to close the subaccount: ' . $e->getMessage());
	}
	return LogicResult::render($data);
}

function services_release_logic_descriptor(): array {
	return array(
		'description'      => 'Stop using one of the operator\'s services (mail or shelf). Mail\'s subaccount is closed; the shelf is kept 90 days and then pruned.',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => array(
			'service' => array('type' => 'string', 'required' => true, 'label' => 'Service: mail or shelf'),
		),
	);
}
?>
