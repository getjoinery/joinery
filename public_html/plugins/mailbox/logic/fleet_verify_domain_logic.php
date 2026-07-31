<?php
/**
 * fleet_verify_domain - run the DNS TXT check for a pending domain claim.
 *
 * (specs/mailbox_relay_shared_fleet.md § Enrollment step 2). On success the
 * fleet writes the domain into the tenant's shard-side allowlist (a
 * set-domains job dispatched by the relay reconcile task), which the shard's map merge
 * then enforces on EVERY subsequent sync — the claim is checked continuously,
 * not once at enrollment.
 */
function fleet_verify_domain_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/FleetService.php'));

	$session = SessionControl::get_instance();
	$user_id = intval($session->get_user_id());
	if (!$user_id) {
		return LogicResult::error('You must be signed in.');
	}
	$slot = MailboxFleetSlot::activeForUser($user_id);
	if ($slot === null) {
		return LogicResult::error('No fleet slot — enroll first (fleet_enroll).');
	}

	$claim_id = intval($input['claim_id'] ?? 0);
	if ($claim_id <= 0) {
		return LogicResult::error('claim_id is required.');
	}
	try {
		$claim = new MailboxFleetDomainClaim($claim_id, TRUE);
	} catch (\Throwable $e) {
		return LogicResult::error('That claim does not exist.');
	}

	try {
		$result = FleetService::verifyClaim($slot, $claim);
	} catch (FleetServiceException $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render(array(
		'claim_id' => $claim_id,
		'domain'   => (string)$claim->get('mfd_domain'),
		'verified' => (bool)$result['verified'],
		'status'   => (string)$claim->get('mfd_status'),
		'message'  => (string)$result['message'],
	));
}

function fleet_verify_domain_logic_descriptor(): array {
	return array(
		'description'      => 'Check the DNS TXT challenge for a pending domain claim. On success the fleet starts accepting mail for the domain.',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => array(
			'claim_id' => array('type' => 'int', 'required' => true, 'label' => 'Claim id'),
		),
	);
}
?>
