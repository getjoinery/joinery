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
 * (cvp_fleet_apk_api_key_id), has no slot, and its install ended more than
 * WAIT_MINUTES ago. Open too, and critical, while it has a slot its shard
 * never took: assigned more than WAIT_MINUTES ago and still at provisioning
 * (re-run finding 113: a shard whose applier had stopped answered every add
 * with a timeout, and two sites sat enrolled in name only). Recorded facts only.
 *
 * @version 1.1 - a slot assigned and never applied on its shard
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
				|| !FleetService::site_plan_grants_slot($provision)) {
			return null;
		}
		$slot = MailboxFleetSlot::activeForProvision((int)$provision->key);
		if ($slot !== null) {
			return self::unapplied($provision, $slot);
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

	/**
	 * A slot the fleet assigned and its shard never took: it stays at
	 * provisioning while the shard refuses or does not answer the add. The
	 * site looks enrolled and its buyer was given the records, but the relay
	 * turns its mail away.
	 */
	private static function unapplied($provision, MailboxFleetSlot $slot): ?array {
		if ((string)$slot->get('mft_status') !== MailboxFleetSlot::STATUS_PROVISIONING) {
			return null;
		}
		$assigned = trim((string)$slot->get('mft_create_time'));
		if ($assigned === '' || (time() - strtotime(substr($assigned, 0, 19) . ' UTC')) < self::WAIT_MINUTES * 60) {
			return null;
		}
		return array(
			'title'    => 'This site\'s mail relay slot is not live on its relay, so it cannot receive mail',
			'severity' => IncidentRecord::SEVERITY_CRITICAL,
			'detail'   => array(
				'Site'          => (string)$provision->get('cvp_domain'),
				'Slot'          => (string)$slot->get('mft_slug') . ', assigned ' . substr($assigned, 0, 19) . ' UTC',
				'What it means' => 'The fleet gave the site a slot, but the relay shard has not added it. Its buyer was shown the receiving records; mail sent to the domain is refused at the relay.',
				'What to do'    => 'Read the scheduled tasks log for FleetService lines naming this slot: they carry the shard\'s answer. '
					. 'An answer of timeout on every request means the shard is not applying requests and needs renewing (Mailbox, Relay fleet).',
			),
		);
	}

	public function cleared_text(ManagedNode $node): string {
		return 'The site has its relay slot, live on its relay.';
	}
}
?>
