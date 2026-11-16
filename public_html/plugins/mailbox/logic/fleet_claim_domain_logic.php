<?php
/**
 * fleet_claim_domain - start the domain-ownership TXT challenge.
 *
 * (specs/mailbox_relay_shared_fleet.md § Enrollment step 2). Domain
 * verification is a security boundary: the fleet accepts no mail for a domain
 * before its owner proves control, and a domain can be claimed by exactly one
 * tenant fleet-wide. Returns the TXT record the tenant must publish; verify
 * with fleet_verify_domain.
 * @version 1.2 - the claim is written on the slot rule (rule_checked_write): a member's site key was refused
 * @version 1.1 - the caller's slot is the one its key speaks for: a hosted site's own, or the buyer's tier slot
 */
function fleet_claim_domain_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/FleetService.php'));

	$session = SessionControl::get_instance();
	$user_id = intval($session->get_user_id());
	if (!$user_id) {
		return LogicResult::error('You must be signed in.');
	}
	$slot = FleetService::callerSlot($session);
	if ($slot === null) {
		return LogicResult::error('No fleet slot — enroll first (fleet_enroll).');
	}

	// The caller's own slot (the key's site, or the buyer's tier slot) was
	// found above; the fleet rows are written on that rule, whatever the
	// account's level (a hosting buyer is an ordinary member).
	try {
		$claim = SystemBase::rule_checked_write(function () use ($slot, $input) {
			return FleetService::claimDomain($slot, (string)($input['domain'] ?? ''));
		});
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
		'auth' => array('min_user_permission' => 0),
		'description'      => 'Claim a mail domain for this fleet slot. Returns the DNS TXT challenge to publish; complete it with fleet_verify_domain.',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => array(
			'domain' => array('type' => 'string', 'required' => true, 'label' => 'Domain to claim'),
		),
	);
}
?>
