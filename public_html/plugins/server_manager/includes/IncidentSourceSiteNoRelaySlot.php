<?php
/**
 * IncidentSourceSiteNoRelaySlot - plane:site_no_relay_slot: a built site whose
 * plan brings a relay slot has none.
 *
 * A hosted site with no mail server of its own receives only through the
 * relay fleet. It asks for its slot itself, minutes after it is built, and the
 * fleet refuses when every shard is full ("enrollment is temporarily closed").
 * The site keeps asking and nobody is told: its buyer has a site that cannot
 * receive mail, and the one DNS list they are owed waits on records the slot
 * would have brought (specs/hosting_purchase_walkthrough_test.md, re-run B2:
 * two paid sites sat without a slot, one for a day).
 *
 * Open while the site's provision is done, was handed the fleet's settings
 * (cvp_fleet_apk_api_key_id), has no active slot, and its install ended more
 * than WAIT_MINUTES ago. Recorded facts only.
 *
 * @version 1.0
 */
class IncidentSourceSiteNoRelaySlot implements IncidentSource {

	const NAME = 'plane:site_no_relay_slot';

	/** A site enrolls within a few minutes of its build; past this it is not going to on its own. */
	const WAIT_MINUTES = 20;

	public function name(): string {
		return self::NAME;
	}

	public function evaluate(ManagedNode $node): ?array {
		if (!class_exists('FleetService') || !class_exists('MailboxFleetSlot')) {
			return null;
		}
		$provision = CustomerCloudProvision::latest_for_node((int)$node->key);
		if (!$provision || (string)$provision->get('cvp_status') !== 'done'
				|| (int)$provision->get('cvp_fleet_apk_api_key_id') <= 0
				|| !FleetService::site_plan_grants_slot($provision)
				|| MailboxFleetSlot::activeForProvision((int)$provision->key) !== null) {
			return null;
		}
		$install = ManagementJob::latestForNode((int)$node->key, 'install_node');
		$ended = $install ? trim((string)$install->get('mjb_completed_time')) : '';
		if ($ended === '' || (time() - strtotime($ended . ' UTC')) < self::WAIT_MINUTES * 60) {
			return null;
		}
		$full = FleetService::assignShard() === null;
		return array(
			'title'    => 'This site has no mail relay slot, so it cannot receive mail',
			'severity' => IncidentRecord::SEVERITY_WARNING,
			'detail'   => array(
				'Site'          => (string)$provision->get('cvp_domain'),
				'Built'         => $ended . ' UTC',
				'The fleet'     => $full
					? 'No relay shard has a free slot: every site that asks is refused.'
					: 'A shard has room, so the site is not asking or is being refused for another reason.',
				'What it means' => 'Mail to this site\'s domain is not arriving, and its buyer\'s DNS list is shown without the receiving records.',
				'What to do'    => $full
					? 'Raise a shard\'s capacity or add a shard (Mailbox, Relay fleet). The site asks again by itself every five minutes.'
					: 'Ask the node for its Mail relay status (Diagnostics) and read this node\'s request log for mailbox/fleet_enroll.',
			),
		);
	}

	public function cleared_text(ManagedNode $node): string {
		return 'The site has its relay slot.';
	}
}
?>
