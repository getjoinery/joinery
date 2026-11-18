<?php
/**
 * ProvisionBuyerDns — every DNS record a buyer's own domain needs, in one list.
 *
 * A site on its buyer's own domain needs records this plane cannot write: the
 * zone is the buyer's. They come from several places that settle at different
 * times: the server's addresses, the sending provider's records
 * (ProvisionHostedMail), the relay slot's inbound records
 * (ProvisionSiteInboundMail), and what only the built site knows, above all
 * its Joinery Direct endpoint and signing key. Shown as each arrived, the
 * buyer made up to four trips to their registrar, and never saw the Direct
 * records at all (walkthrough Q16).
 *
 * So the list is held until it is whole. This leg waits for the other legs to
 * settle, asks the site what its domain needs (the managed_domain_prepare
 * primitive, the question ProvisionManagedDomains asks for a name we hold),
 * keeps the answer on the row, and stamps cvp_site_dns_time. The buyer's
 * sites page lists nothing before the stamp and everything after it.
 *
 * Never for ever: a site that has not answered SmHosting::DNS_WAIT_SECONDS
 * after its install ended is stamped with what is known, reported, and asked
 * again until it answers.
 *
 * @version 1.0
 */
class ProvisionBuyerDns {

	const JOB_TYPE = 'managed_domain_prepare';

	/** Minutes between two questions to a site that did not give a usable answer. */
	const RETRY_GAP_MINUTES = 5;

	/** @var array Problems for the run summary. */
	private $errors = array();

	public function run(array $config): array {
		$this->errors = array();
		$rows = new MultiCustomerCloudProvision(array('statuses' => array('done'), 'deleted' => false));
		// provision_id: advance that one site only (a person retrying one, a test).
		$only = (int)($config['provision_id'] ?? 0);
		$waiting = 0;
		$advanced = 0;
		foreach ($rows as $provision) {
			if (($only > 0 && (int)$provision->key !== $only) || !self::applies($provision)
					|| self::site_records($provision) !== null) {
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
			return array('status' => 'skipped', 'message' => 'No buyer DNS list to complete.');
		}
		$message = 'Buyer DNS: ' . $advanced . ' of ' . $waiting . ' list(s) completed.';
		if ($this->errors) {
			return array('status' => 'error', 'message' => $message . ' ' . implode('; ', array_slice($this->errors, 0, 3)));
		}
		return array('status' => 'success', 'message' => $message);
	}

	/**
	 * Whether this site's DNS is its buyer's to add: one we built (hosted by
	 * us, or on the buyer's own Linode) on a name we did not register.
	 */
	public static function applies($provision): bool {
		if ($provision->is_pre_payment() || trim((string)$provision->get('cvp_domain')) === '') {
			return false;
		}
		if (!$provision->is_operator_hosted() && ManagedSiteDraft::plan_of($provision) !== 'own_linode') {
			return false;
		}
		return !JobResultProcessor::plane_registered_domain($provision);
	}

	/** Whether the buyer's list is whole and may be shown. True where there is no list to hold. */
	public static function listed($provision): bool {
		return !self::applies($provision) || trim((string)$provision->get('cvp_site_dns_time')) !== '';
	}

	/** What the site said its domain needs, as stored; null while it has not answered. */
	public static function site_records($provision): ?array {
		$raw = $provision->get('cvp_site_dns_records');
		if (is_string($raw)) { $raw = json_decode($raw, true); }
		return is_array($raw) ? $raw : null;
	}

	/**
	 * The site's records the buyer is asked to add beside the plane's own
	 * ($plane: the sending and inbound records the plane lists), each
	 * array(type, name, value, priority, note). The plane lists the addresses
	 * itself, so the site's are dropped. Where the plane set up sending, the
	 * provider signs and the forgery policy is the plane's optional box, so the
	 * site's own signing key, SPF and DMARC are dropped, and a name and type
	 * the plane already lists is the plane's.
	 */
	public static function site_records_for_buyer($provision, array $plane): array {
		$has_sending = count(ProvisionHostedMail::records_of($provision)) > 0;
		$taken = array();
		foreach ($plane as $record) {
			$taken[strtoupper((string)($record['type'] ?? '')) . ' ' . strtolower(rtrim((string)($record['name'] ?? ''), '.'))] = true;
		}
		$out = array();
		foreach ((array)self::site_records($provision) as $record) {
			$type = strtoupper(trim((string)($record['type'] ?? '')));
			$name = strtolower(rtrim(trim((string)($record['name'] ?? '')), '.'));
			$value = rtrim(trim((string)($record['value'] ?? '')), '.');
			if ($type === '' || $name === '' || $value === '' || in_array($type, array('A', 'AAAA'), true)
					|| isset($taken[$type . ' ' . $name])) {
				continue;
			}
			if ($has_sending && (strpos($name, '._domainkey.') !== false || strpos($name, '_dmarc.') === 0
					|| ($type === 'TXT' && stripos($value, 'v=spf1') === 0))) {
				continue;
			}
			$out[] = array('type' => $type, 'name' => $name, 'value' => $value,
				'priority' => isset($record['priority']) && $record['priority'] !== null ? (int)$record['priority'] : null,
				'note' => (string)($record['note'] ?? ''));
		}
		return $out;
	}

	/** One site. Returns 1 when its list was completed. */
	private function advance($provision): int {
		$node_id = (int)$provision->get('cvp_mgn_managed_node_id');
		if ($node_id <= 0) {
			return 0;
		}
		$node = new ManagedNode($node_id, TRUE);
		$domain = strtolower(trim((string)$provision->get('cvp_domain')));
		$overdue = self::overdue($node_id);
		if (!$overdue && !self::others_settled($provision)) {
			return 0;   // the sending or inbound records are still on their way
		}

		$job = $this->latest_job($node_id, $domain);
		if ($job && in_array((string)$job['mjb_status'], array('queued', 'pending', 'running'), true)) {
			return $this->stamp_if_overdue($provision, $overdue, 'its site has not answered yet');
		}
		if ($job && (string)$job['mjb_status'] === 'completed') {
			$payload = $this->payload($job);
			if ($payload !== null && !empty($payload['answered']) && !empty($payload['ok'])) {
				$provision->set('cvp_site_dns_records', json_encode(array_values((array)($payload['records'] ?? array()))));
				if (trim((string)$provision->get('cvp_site_dns_time')) === '') {
					$provision->set('cvp_site_dns_time', gmdate('Y-m-d H:i:s'));
				}
				$provision->save();
				return 1;
			}
		}
		if ($job) {
			$since = strtotime((string)($job['mjb_completed_time'] ?: $job['mjb_create_time']) . ' UTC');
			if ($since && (time() - $since) < self::RETRY_GAP_MINUTES * 60) {
				return $this->stamp_if_overdue($provision, $overdue, 'its site did not say what its domain needs');
			}
		}
		try {
			$built = JobCommandBuilder::build_managed_domain_prepare($node, array('domain' => $domain));
			ManagementJob::createFromBuild($node->key, self::JOB_TYPE, $built, array('domain' => $domain), null);
		} catch (Throwable $e) {
			return $this->stamp_if_overdue($provision, $overdue, $e->getMessage());
		}
		return 0;   // asked; the answer lands on a later pass
	}

	/**
	 * A list overdue is shown with what is known, and the gap is reported on
	 * every pass until the site answers (its row stays in the run).
	 */
	private function stamp_if_overdue($provision, bool $overdue, string $why): int {
		if (!$overdue) {
			return 0;
		}
		$this->errors[] = $provision->get('cvp_domain') . ': its buyer\'s DNS list is shown without the site\'s own '
			. 'records (Joinery Direct among them): ' . $why;
		if (trim((string)$provision->get('cvp_site_dns_time')) === '') {
			$provision->set('cvp_site_dns_time', gmdate('Y-m-d H:i:s'));
			$provision->save();
		}
		return 0;
	}

	/**
	 * Whether the other legs have said their part: the sending records are
	 * known (or that leg ended, or was never this row's), and a site whose plan
	 * brings a relay slot has its inbound records placed.
	 */
	public static function others_settled($provision): bool {
		$mail = (string)$provision->get('cvp_mail_state');
		if (in_array($mail, ProvisionHostedMail::WORKING_STATES, true) && !ProvisionHostedMail::records_of($provision)) {
			return false;
		}
		if (class_exists('FleetService') && FleetService::site_plan_grants_slot($provision)
				&& (int)$provision->get('cvp_fleet_apk_api_key_id') > 0
				&& $provision->get('cvp_inbound_dns_state') === null) {
			return false;
		}
		return true;
	}

	/** Whether the site's install ended longer ago than a buyer is made to wait for the list. */
	public static function overdue(int $node_id): bool {
		$install = ManagementJob::latestForNode($node_id, 'install_node');
		$ended = $install ? trim((string)$install->get('mjb_completed_time')) : '';
		return $ended !== '' && (time() - strtotime($ended . ' UTC')) > SmHosting::DNS_WAIT_SECONDS;
	}

	/** The most recent question put to this node about this domain. Overridable for tests. */
	protected function latest_job(int $node_id, string $domain): ?array {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			"SELECT mjb_management_job_id, mjb_status, mjb_create_time, mjb_completed_time, mjb_result
			 FROM mjb_management_jobs
			 WHERE mjb_mgn_managed_node_id = ? AND mjb_job_type = ? AND mjb_delete_time IS NULL
			   AND mjb_parameters->>'domain' = ?
			 ORDER BY mjb_create_time DESC, mjb_management_job_id DESC
			 LIMIT 1");
		$q->execute(array($node_id, self::JOB_TYPE, $domain));
		$row = $q->fetch(PDO::FETCH_ASSOC);
		return $row ?: null;
	}

	/** One completed job's answer, processed here when nobody has processed it yet. */
	protected function payload(array $job): ?array {
		$result = $job['mjb_result'] ?? null;
		if (!$result) {
			$done = new ManagementJob((int)$job['mjb_management_job_id'], TRUE);
			if (!$done->key) {
				return null;
			}
			JobResultProcessor::process($done);
			$result = $done->get('mjb_result');
		}
		if (is_string($result)) {
			$result = json_decode($result, true);
		}
		return is_array($result) ? $result : null;
	}
}
