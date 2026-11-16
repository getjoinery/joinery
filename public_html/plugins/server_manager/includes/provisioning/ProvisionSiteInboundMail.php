<?php
/**
 * ProvisionSiteInboundMail — the DNS a hosted site's inbound mail needs.
 *
 * A Starter site has no port 25 of its own: its mail comes in through its own
 * slot on the relay fleet (hosting_launch S5). Once the site has enrolled and
 * filed its ownership claim, its domain needs two records: an MX naming the
 * slot's mail name, and the ownership TXT the fleet checks before it accepts
 * a single message (FleetService::site_inbound_records).
 *
 * On a name registered through us, this plane holds the zone and publishes
 * both: a record we did not set is never removed or overwritten, and one in
 * the way (a registrar's mail-forwarding MX) is reported on every pass until
 * it is cleared; the buyer has no editor for that zone. On the buyer's own
 * name it holds no credential and never will, so the records are left for
 * the buyer, who sees them on their sites page (profile_sites_mail_records).
 * Either way the site asks the fleet to check the claim every few minutes
 * until it is proven (FleetClient::autoEnroll).
 *
 * cvp_inbound_dns_state records which way it went, and a site with a state
 * is done. A publish that fails, a record skipped, a record in the way or a
 * zone that could not be read leaves it unset, reported, and asked again
 * next pass.
 *
 * @version 1.2 - every site whose plan grants a slot and holds a site key, Managed as well as Starter: no landing filter (hosting_paid_launch B11)
 * @version 1.1 - a skipped record is not placed; a record in the way or an unreadable zone is reported every pass;
 *               a drifted record of ours is updated
 * @version 1.0
 */
class ProvisionSiteInboundMail {

	/** @var array Problems for the run summary. */
	private $errors = array();

	public function run(array $config): array {
		$this->errors = array();
		if (!class_exists('FleetService') || !PluginHelper::isPluginActive('mailbox')) {
			return array('status' => 'skipped', 'message' => 'The mailbox plugin is not active.');
		}
		require_once(PathHelper::getIncludePath('includes/dns/DnsRecordPlan.php'));
		require_once(PathHelper::getIncludePath('includes/dns/DnsReconciler.php'));

		$rows = new MultiCustomerCloudProvision(array(
			'statuses'          => array('done'),
			'fleet_seed_states' => array('done'),
			'deleted'           => false,
		));
		// provision_id: advance that one site only (a person retrying one, a test).
		$only = (int)($config['provision_id'] ?? 0);
		$waiting = 0;
		$advanced = 0;
		foreach ($rows as $provision) {
			if (($only > 0 && (int)$provision->key !== $only) || $provision->get('cvp_inbound_dns_state') !== null || !FleetService::site_plan_grants_slot($provision)) {
				continue;
			}
			// A site seeded with its buyer's tier key (before its plan granted
			// a slot) has no slot of its own to place records for.
			if ((int)$provision->get('cvp_fleet_apk_api_key_id') <= 0) {
				continue;
			}
			$waiting++;
			try {
				$advanced += $this->advance($provision);
			} catch (Throwable $e) {
				$this->errors[] = $provision->get('cvp_domain') . ': ' . $e->getMessage();
			}
		}
		if ($waiting === 0) {
			return array('status' => 'skipped', 'message' => 'No site inbound mail records to place.');
		}
		$message = 'Site inbound mail: ' . $advanced . ' of ' . $waiting . ' site(s) placed.';
		if ($this->errors) {
			return array('status' => 'error', 'message' => $message . ' ' . implode('; ', array_slice($this->errors, 0, 3)));
		}
		return array('status' => 'success', 'message' => $message);
	}

	/** One site. Returns 1 when its records were placed (published, or left for the buyer). */
	private function advance($provision): int {
		$records = FleetService::site_inbound_records($provision);
		if (!$records) {
			return 0; // the site has not enrolled or filed its claim yet
		}
		if (!JobResultProcessor::plane_registered_domain($provision)) {
			$provision->set('cvp_inbound_dns_state', 'buyer');
			$provision->save();
			return 1;
		}
		$driver = $this->dns_driver();
		if ($driver === null) {
			$this->errors[] = $provision->get('cvp_domain') . ': no DNS driver for the configured registrar';
			return 0;
		}
		$domain = strtolower(trim((string)$provision->get('cvp_domain')));
		$plan = new DnsRecordPlan($domain, 'server_manager');
		foreach ($records as $record) {
			$plan->addRecord($record['type'], $record['name'], $record['value'], null,
				$record['priority'] ?? null, (string)($record['note'] ?? ''));
		}
		// Read the zone first. The zone is ours, so whatever stands in the way
		// is ours to resolve: a zone that cannot be read, or a record we did
		// not set (a registrar's mail-forwarding MX), is reported on every
		// pass until it is cleared, and never overwritten. A record of ours
		// that has drifted (the slot's mail name changed) is updated.
		$reconciler = new DnsReconciler();
		$in_the_way = array();
		$drifted = array();
		foreach ($reconciler->diffAgainstProvider($driver, $domain, $plan) as $row) {
			if ($row['outcome'] === DnsReconciler::UNKNOWN) {
				$this->errors[] = $domain . ': its zone could not be read; asked again next pass';
				return 0;
			}
			if ($row['outcome'] === DnsReconciler::CONFLICTS) {
				$in_the_way[] = $row['record']->type . ' ' . $row['record']->name;
			} elseif ($row['outcome'] === DnsReconciler::DIFFERS) {
				$drifted[] = $row['key'];
			}
		}
		if ($in_the_way) {
			$this->errors[] = $domain . ': already holds ' . implode(', ', $in_the_way)
				. ' that we did not set; remove it from the zone (it is never overwritten) and the next pass publishes';
			return 0;
		}
		$results = $drifted
			? $reconciler->apply($driver, $domain, $plan, array('cutover' => $drifted), DnsReconciler::APPLY_CONFIRMED)
			: $reconciler->apply($driver, $domain, $plan, array(), DnsReconciler::APPLY_ADDITIVE);
		$failed = array();
		foreach ($results as $result) {
			// A skip is a record not placed, whatever the reason.
			if (empty($result['ok']) || $result['action'] === 'skipped') {
				$failed[] = trim((string)($result['reason'] ?? 'unknown reason'));
			}
		}
		if ($failed) {
			$this->errors[] = $domain . ': ' . implode('; ', $failed);
			return 0;
		}
		$provision->set('cvp_inbound_dns_state', 'published');
		$provision->save();
		return 1;
	}

	/** The registrar's DNS driver, or null. Overridable for tests. */
	protected function dns_driver() {
		return ProvisionHostedMail::registrar_dns_driver();
	}
}
