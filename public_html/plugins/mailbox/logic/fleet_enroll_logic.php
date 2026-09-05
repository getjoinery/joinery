<?php
/**
 * fleet_enroll - request a slot on the operator's shared relay fleet.
 *
 * (specs/mailbox_relay_shared_fleet.md § Enrollment step 1). Called by a
 * TENANT deployment against the OPERATOR's /api/v1 with the customer
 * account's API key; runs as that customer. Validates entitlement (the
 * mailbox_fleet_slot tier feature), assigns a shard, allocates the tunnel
 * address, and returns the connection coordinates. The shard-side provisioning
 * job is dispatched by the relay reconcile task; the slot reports
 * status=provisioning until it lands (poll fleet_status).
 *
 * Idempotent: re-enrolling returns the existing live slot (re-registering it
 * only if the submitted keys changed).
 *
 * @version 1.1 - the tenant sends its relay client public key (specs/relay_without_a_shell.md)
 */
function fleet_enroll_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/FleetService.php'));

	$session = SessionControl::get_instance();
	$user_id = intval($session->get_user_id());
	if (!$user_id) {
		return LogicResult::error('You must be signed in to enroll.');
	}

	try {
		$slot = FleetService::enroll($user_id, array(
			'public_key'      => trim((string)($input['public_key'] ?? '')),
			'wg_public_key'   => trim((string)($input['wg_public_key'] ?? '')),
			'pull_public_key' => trim((string)($input['pull_public_key'] ?? '')),
		));
	} catch (FleetServiceException $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render(FleetService::coordinates($slot));
}

function fleet_enroll_logic_descriptor(): array {
	return array(
		'description'      => 'Enroll this deployment for a hosted relay slot on the shared fleet. Returns the slot coordinates (MX hostname, the shard\'s identity pin and address; on a tunnel shard its WireGuard endpoint, tunnel address and pull account).',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => array(
			'public_key'      => array('type' => 'string', 'required' => true, 'label' => 'Relay client public key (Ed25519, base64)'),
			'wg_public_key'   => array('type' => 'string', 'required' => false, 'label' => 'Main-box WireGuard public key (tunnel shard only)'),
			'pull_public_key' => array('type' => 'string', 'required' => false, 'label' => 'Relay pull SSH public key (tunnel shard only)'),
		),
	);
}
?>
