<?php
/**
 * fleet_claim_domain - start the domain-ownership TXT challenge.
 *
 * (specs/mailbox_relay_shared_fleet.md § Enrollment step 2). Domain
 * verification is a security boundary: the fleet accepts no mail for a domain
 * before its owner proves control, and a domain can be claimed by exactly one
 * tenant fleet-wide. Returns the TXT record the tenant must publish; verify
 * with fleet_verify_domain.
 */
function fleet_claim_domain_logic(array $input): LogicResult {
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

	try {
		$claim = FleetService::claimDomain($slot, (string)($input['domain'] ?? ''));
	} catch (FleetServiceException $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render(array(
		'claim_id'  => intval($claim->key),
		'domain'    => (string)$claim->get('mfd_domain'),
		'status'    => (string)$claim->get('mfd_status'),
		'txt_host'  => $claim->challengeHost(),
		'txt_value' => (string)$claim->get('mfd_txt_token'),
	));
}

function fleet_claim_domain_logic_descriptor(): array {
	return array(
		'description'      => 'Claim a mail domain for this fleet slot. Returns the DNS TXT challenge to publish; complete it with fleet_verify_domain.',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => array(
			'domain' => array('type' => 'string', 'required' => true, 'label' => 'Domain to claim'),
		),
	);
}
?>
