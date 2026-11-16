<?php
/**
 * fleet_release - relinquish the tenant's fleet slot (the exit ramp).
 *
 * (specs/mailbox_relay_shared_fleet.md § Goal — the exit ramp keeps the trust
 * cheap). Marks the slot released; the relay reconcile task dispatches the
 * remove-tenant job, which refuses while the tenant's spool still holds
 * undrained sealed mail — so release, keep pulling until the spool drains,
 * and the slot evicts cleanly. Mail queues at senders during the tenant's
 * MX change; nothing is lost.
 * @version 1.2 - the release is written on the slot rule (rule_checked_write): a member's key was refused
 * @version 1.1 - the caller's slot is the one its key speaks for: a hosted site's own, or the buyer's tier slot
 */
function fleet_release_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/FleetService.php'));

	$session = SessionControl::get_instance();
	$user_id = intval($session->get_user_id());
	if (!$user_id) {
		return LogicResult::error('You must be signed in.');
	}
	$slot = FleetService::callerSlot($session);
	if ($slot === null) {
		return LogicResult::error('No fleet slot to release.');
	}

	// The caller's own slot (the key's site, or the buyer's tier slot) was
	// found above; the fleet rows are written on that rule, whatever the
	// account's level (a hosting buyer is an ordinary member).
	// Release + immediate claim revocation (the domains' next home must be
	// able to claim them before this slot finishes evicting).
	SystemBase::rule_checked_write(function () use ($slot) {
		FleetService::releaseSlot($slot);
	});

	return LogicResult::render(array(
		'released' => true,
		'message'  => 'Slot released. Keep your relay pull running until the spool drains; '
			. 'the slot is removed from the shard once it is empty. Point your MX at its new home first.',
	));
}

function fleet_release_logic_descriptor(): array {
	return array(
		'auth' => array('min_user_permission' => 0),
		'description'      => 'Release this deployment\'s hosted relay slot (exit ramp — point your MX elsewhere first).',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => array(),
	);
}
?>
