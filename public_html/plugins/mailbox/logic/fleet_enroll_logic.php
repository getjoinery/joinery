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
 * @version 1.3 - a site's key whose site no longer counts is refused, never given the buyer's tier slot
 * @version 1.2 - a key minted for a hosted site enrolls that site's own slot (hosting_launch S5)
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
		// A key minted for a hosted site enrolls that site's own slot, and
		// nothing else once the site no longer counts; any other key enrolls
		// the buyer's tier slot.
		$provision = FleetService::provisionForKey($user_id, $session->get_api_key_id());
		if ($provision === null && FleetService::isSiteKey($session->get_api_key_id())) {
			return LogicResult::error('This site\'s hosting no longer includes a hosted relay slot.');
		}
		$slot = FleetService::enroll($user_id, array(
			'public_key'      => trim((string)($input['public_key'] ?? '')),
			'wg_public_key'   => trim((string)($input['wg_public_key'] ?? '')),
			'pull_public_key' => trim((string)($input['pull_public_key'] ?? '')),
		), $provision);
	} catch (FleetServiceException $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render(FleetService::coordinates($slot));
}

function fleet_enroll_logic_descriptor(): array {
	return array(
		'auth' => array('min_user_permission' => 0),
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
