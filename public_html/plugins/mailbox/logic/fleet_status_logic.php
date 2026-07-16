<?php
/**
 * fleet_status - the tenant's slot state, coordinates, and domain claims.
 *
 * Polled by the tenant deployment after fleet_enroll (the shard-side
 * provisioning job runs asynchronously) and by its setup checks. Reconciles
 * the last lifecycle job into the slot status lazily, so a finished
 * provisioning shows active here without waiting for the operator's cron.
 */
function fleet_status_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/FleetService.php'));

	$session = SessionControl::get_instance();
	$user_id = intval($session->get_user_id());
	if (!$user_id) {
		return LogicResult::error('You must be signed in.');
	}
	$slot = MailboxFleetSlot::activeForUser($user_id);
	if ($slot === null) {
		return LogicResult::render(array('enrolled' => false));
	}

	FleetService::reconcile($slot);

	$claims = new MultiMailboxFleetDomainClaim(array(
		'slot_id' => intval($slot->key), 'live' => true, 'deleted' => false,
	));
	$claims->load();
	$claim_list = array();
	foreach ($claims as $claim) {
		$claim_list[] = array(
			'claim_id'  => intval($claim->key),
			'domain'    => (string)$claim->get('mfd_domain'),
			'status'    => (string)$claim->get('mfd_status'),
			'txt_host'  => $claim->challengeHost(),
			'txt_value' => (string)$claim->get('mfd_txt_token'),
		);
	}

	return LogicResult::render(array(
		'enrolled'    => true,
		'coordinates' => FleetService::coordinates($slot),
		'claims'      => $claim_list,
	));
}

function fleet_status_logic_descriptor(): array {
	return array(
		'description'      => 'This deployment\'s hosted relay slot: status, connection coordinates, and domain claims.',
		'requires_session' => true,
		'mutates'          => false,
		'input'            => array(),
	);
}
?>
