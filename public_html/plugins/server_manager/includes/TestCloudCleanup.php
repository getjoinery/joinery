<?php
/**
 * TestCloudCleanup — deletes test servers and volumes past their age on a
 * cloud account kept for testing (specs/test_cloud_account_and_prod_management.md WP3).
 *
 * A run covers every cloud account of ours flagged test (the company name
 * read when the account was added or last checked), each on its own. The
 * safety catch is a property of the account, not of this site: an account is
 * cleaned only when it carries the company name COMPANY, read from the
 * provider again on every run. A live account never carries it, so a live
 * account flagged by mistake deletes nothing; its run stops and the incident
 * names the account it found. There is no "this is a test site" setting to
 * flip in the wrong place.
 *
 * Never deleted: anything tagged KEEP_TAG at the provider, and a server at
 * the address of a live managed node here (it is listed as held, so the
 * operator tags it or removes the node first).
 *
 * A deleted server's address records go with it: every A and AAAA record in
 * the test zones (DNS_ZONES) naming one of its addresses, whichever test
 * account the server was in. The zones live in a Linode test account, whose
 * own token removes them; with no such account held here, the run says the
 * records were left. A record left on a released address names whoever the
 * provider gives that address to next (fleet_security_patching WP10).
 *
 * @version 1.3 - a deleted server's A and AAAA records in the test zones are removed with it
 * @version 1.2 - every test account of ours, each behind its own safety catch; the state is one entry per account
 *                (cloud_accounts_list)
 * @version 1.1 - the company name Linode accepts: Joinery Test disposable (it refuses parentheses)
 * @version 1.0
 */

class TestCloudCleanup {

	/** The account company name that marks a cloud account as disposable. */
	const COMPANY = 'Joinery Test disposable';
	/** A provider-side tag that spares a server or volume. */
	const KEEP_TAG = 'keep';
	const TASK_CLASS = 'DeleteOldTestServers';
	const STATE_SETTING = 'server_manager_test_cloud_cleanup';
	const MAX_AGE_SETTING = 'server_manager_test_cloud_max_age_hours';
	/** The test account's own DNS zones, whose address records follow its servers. */
	const DNS_ZONES = array('joinerytest.com');
	const DEFAULT_MAX_AGE_HOURS = 24;
	/** The token scopes a run needs, as scope => what it is for. */
	const SCOPES = array(
		'linodes:read_write' => 'deleting servers',
		'volumes:read_write' => 'deleting volumes',
		'account:read_only'  => 'reading the account\'s company name, the safety catch',
		'domains:read_write' => 'removing a deleted server\'s address records',
	);

	/** The accounts of ours flagged test that can be used now. */
	public static function accounts(): array {
		$out = array();
		foreach (OperatorCloudAccount::held() as $account) {
			if ($account->get('oca_is_test') && $account->usable()) {
				$out[] = $account;
			}
		}
		return $out;
	}

	/** The age limit in hours (the setting, or the default when it is not a positive number). */
	public static function max_age_hours(): int {
		$hours = (int)trim((string)Globalvars::get_instance()->get_setting(self::MAX_AGE_SETTING));
		return $hours > 0 ? $hours : self::DEFAULT_MAX_AGE_HOURS;
	}

	/**
	 * What a run would do now; deletes nothing.
	 *   account     string  the account's company name as found ('' when none is set or it could not be read)
	 *   safe        bool    the safety catch passes
	 *   reason      string  why not, when not safe
	 *   instances   list    servers to delete: id, label, created, age_hours, addresses
	 *   volumes     list    unattached volumes to delete: id, label, created, age_hours
	 *   held        list    servers past the age at a managed node's address: id, label, age_hours, addresses, node_ids
	 *   kept        int     servers and volumes past the age that are tagged keep
	 *   volume_error string why the volumes could not be listed ('' when they were); servers still go
	 *
	 * @param object $driver  CloudComputeProvider + CloudMachineTransfer + CloudAccountCleanup
	 * @param array  $nodes   MachineTransferWatch::node_addresses()
	 */
	public static function plan($driver, array $nodes, int $now, int $max_age_hours): array {
		$plan = array('account' => '', 'safe' => false, 'reason' => '', 'instances' => array(), 'volumes' => array(),
			'held' => array(), 'kept' => 0, 'volume_error' => '');
		if (!$driver instanceof CloudAccountCleanup || !$driver instanceof CloudMachineTransfer
				|| !$driver instanceof CloudComputeProvider) {
			$plan['reason'] = 'This cloud provider\'s driver cannot list and delete an account\'s servers and volumes.';
			return $plan;
		}
		try {
			$plan['account'] = $driver->accountCompany();
		} catch (CloudComputeException $e) {
			$plan['reason'] = 'The account\'s company name could not be read (' . $e->getMessage() . '); the token needs account:read_only.';
			return $plan;
		}
		if ($plan['account'] !== self::COMPANY) {
			$plan['reason'] = 'The account\'s company name is ' . ($plan['account'] === '' ? 'empty' : '"' . $plan['account'] . '"')
				. ', not "' . self::COMPANY . '", so it is not a test account and nothing on it is deleted.';
			return $plan;
		}
		$plan['safe'] = true;

		$limit = $max_age_hours * 3600;
		foreach ($driver->listInstances() as $instance) {
			$age = self::age($instance['created'] ?? '', $now);
			if ($age === null || $age <= $limit) {
				continue;
			}
			if (self::kept($instance)) {
				$plan['kept']++;
				continue;
			}
			$entry = array(
				'id'        => (string)$instance['id'],
				'label'     => (string)($instance['label'] ?? ''),
				'created'   => (string)$instance['created'],
				'age_hours' => (int)floor($age / 3600),
				'addresses' => array_values(array_filter(array_merge((array)($instance['ipv4_public'] ?? array()),
					array((string)($instance['ipv6'] ?? ''))), 'strlen')),
			);
			$on = MachineTransferWatch::nodes_on($instance, $nodes);
			if ($on) {
				$plan['held'][] = $entry + array('node_ids' => array_keys($on));
				continue;
			}
			$plan['instances'][] = $entry;
		}
		try {
			$volumes = $driver->listVolumes();
		} catch (CloudComputeException $e) {
			$plan['volume_error'] = $e->getMessage();
			$volumes = array();
		}
		foreach ($volumes as $volume) {
			$age = self::age($volume['created'] ?? '', $now);
			if ($age === null || $age <= $limit || (string)($volume['attached_to'] ?? '') !== '') {
				continue;
			}
			if (self::kept($volume)) {
				$plan['kept']++;
				continue;
			}
			$plan['volumes'][] = array(
				'id'        => (string)$volume['id'],
				'label'     => (string)($volume['label'] ?? ''),
				'created'   => (string)$volume['created'],
				'age_hours' => (int)floor($age / 3600),
			);
		}
		return $plan;
	}

	/**
	 * One run over every test account (or, given $accounts as
	 * [name => driver], over those): plan, then delete, each account on its
	 * own. Records one outcome per account for the setup page and the
	 * incident. Returns the scheduled task's {status, message}.
	 */
	public static function run(?array $accounts = null, ?callable $resolve = null, ?int $now = null, $dns = null): array {
		$now = $now ?? time();
		if ($accounts === null) {
			$accounts = array();
			foreach (self::accounts() as $account) {
				$accounts[$account->label()] = $account->driver();
			}
			$dns = self::zone_holder(self::accounts());
		}
		if (!$accounts) {
			self::save_state(array());
			return array('status' => 'skipped', 'message' => 'No cloud account of ours here is the test account.');
		}
		$nodes = MachineTransferWatch::node_addresses($resolve ?? array('MachineTransferWatch', 'resolve'));
		$states = array();
		$messages = array();
		$status = 'success';
		foreach ($accounts as $name => $driver) {
			list($state, $result) = self::run_one((string)$name, $driver, $nodes, $now, $dns);
			$states[] = $state;
			$messages[] = $name . ': ' . $result['message'];
			if ($result['status'] === 'error') {
				$status = 'error';
			}
		}
		self::save_state($states);
		return array('status' => $status, 'message' => implode(' ', $messages));
	}

	/**
	 * The DNS of the test account that holds the test zones: a Linode test
	 * account's own token (Linode's Domains), the same account behind the same
	 * safety catch. False when no held account holds them.
	 */
	public static function zone_holder(array $accounts) {
		foreach ($accounts as $account) {
			if ($account->provider() !== 'linode') { continue; }
			try {
				$dns = new LinodeDnsDriver(array('access_token' => $account->token()));
				if ($dns->zoneFor(self::DNS_ZONES[0]) !== null) { return $dns; }
			} catch (Exception $e) {
				continue;
			}
		}
		return false;
	}

	/**
	 * One account's run: [its state, its {status, message}]. $dns is the zone
	 * holder's DNS (zone_holder), false when none is held, or null to leave
	 * records alone (a test of the rest).
	 */
	private static function run_one(string $name, $driver, array $nodes, int $now, $dns = null): array {
		$base = array('name' => $name, 'time' => gmdate('Y-m-d H:i:s', $now));
		try {
			$plan = self::plan($driver, $nodes, $now, self::max_age_hours());
		} catch (CloudComputeException $e) {
			return array($base + array('outcome' => 'failed',
				'reason' => 'The account\'s servers or volumes could not be listed: ' . $e->getMessage()),
				array('status' => 'error', 'message' => 'Nothing deleted: the account could not be listed (' . $e->getMessage() . ').'));
		}
		if (!$plan['safe']) {
			return array($base + array('outcome' => 'refused', 'account' => $plan['account'], 'reason' => $plan['reason']),
				array('status' => 'error', 'message' => 'Nothing deleted. ' . $plan['reason']));
		}

		$deleted = array('instances' => array(), 'volumes' => array());
		$failures = array();
		if ($plan['volume_error'] !== '') {
			$failures[] = 'volumes could not be listed: ' . $plan['volume_error'];
		}
		$released = array();
		foreach ($plan['instances'] as $i) {
			try {
				$driver->deleteInstance($i['id']);
				$deleted['instances'][] = $i['label'] . ' (' . $i['id'] . ')';
				foreach ($i['addresses'] as $a) {
					if (($b = IpAddress::binary($a)) !== null) { $released[$b] = true; }
				}
			} catch (CloudComputeException $e) {
				$failures[] = 'server ' . $i['label'] . ': ' . $e->getMessage();
			}
		}
		$deleted['records'] = array();
		if ($released && $dns === false) {
			$failures[] = 'their ' . implode(', ', self::DNS_ZONES) . ' records were left: no Linode test account holding that zone is held here';
		} elseif ($released && $dns !== null) {
			foreach (self::DNS_ZONES as $domain) {
				try {
					$zone = $dns->zoneFor($domain);
					$records = $zone === null ? array() : $dns->listRecords($zone);
				} catch (Exception $e) {
					$failures[] = 'the ' . $domain . ' records: ' . $e->getMessage();
					continue;
				}
				foreach ($records as $record) {
					if (!in_array($record->type, array('A', 'AAAA'), true)) { continue; }
					$b = IpAddress::binary((string)$record->value);
					if ($b === null || !isset($released[$b])) { continue; }
					try {
						$dns->deleteRecord($zone, $record);
						$deleted['records'][] = $record->type . ' ' . $record->name;
					} catch (Exception $e) {
						$failures[] = 'record ' . $record->type . ' ' . $record->name . ': ' . $e->getMessage();
					}
				}
			}
		}
		foreach ($plan['volumes'] as $v) {
			try {
				$driver->deleteVolume($v['id']);
				$deleted['volumes'][] = $v['label'] . ' (' . $v['id'] . ')';
			} catch (CloudComputeException $e) {
				$failures[] = 'volume ' . $v['label'] . ': ' . $e->getMessage();
			}
		}
		$held = array_map(function ($h) {
			return $h['label'] . ' (' . implode(', ', $h['addresses']) . '; node #' . implode(', #', $h['node_ids']) . ')';
		}, $plan['held']);
		$state = $base + array('outcome' => 'ran', 'account' => $plan['account'],
			'deleted_instances' => $deleted['instances'], 'deleted_volumes' => $deleted['volumes'], 'deleted_records' => $deleted['records'],
			'held' => $held, 'kept' => $plan['kept'], 'failures' => $failures);

		$message = 'Deleted ' . count($deleted['instances']) . ' server(s)'
			. ($deleted['instances'] ? ' (' . implode(', ', $deleted['instances']) . ')' : '')
			. ' and ' . count($deleted['volumes']) . ' volume(s)'
			. ($deleted['volumes'] ? ' (' . implode(', ', $deleted['volumes']) . ')' : '')
			. ($deleted['records'] ? ', and their address records (' . implode(', ', $deleted['records']) . ')' : '') . '.';
		if ($held) {
			$message .= ' Held, at a managed node\'s address: ' . implode('; ', $held) . '. Tag each keep, or remove its node.';
		}
		if ($failures) {
			$message .= ' Failed: ' . implode('; ', $failures) . '.';
		}
		return array($state, array('status' => $failures ? 'error' : 'success', 'message' => $message));
	}

	/**
	 * The incident while the task is on: the last run refused an account
	 * (its safety catch failed) or could not list it. Null otherwise. The
	 * first such account speaks; the setup page lists them all.
	 */
	public static function condition(array $states, ?array $task): ?array {
		if (!$task || !$task['active']) {
			return null;
		}
		$state = null;
		foreach ($states as $one) {
			if (is_array($one) && in_array($one['outcome'] ?? '', array('refused', 'failed'), true)) {
				$state = $one;
				break;
			}
		}
		if ($state === null) {
			return null;
		}
		if ($state['outcome'] === 'refused') {
			return array(
				'severity' => IncidentRecord::SEVERITY_WARNING,
				'title'    => 'The test-account cleanup stopped: an account flagged test here is not marked disposable',
				'detail'   => array(
					'Account'       => (string)($state['name'] ?? ''),
					'Company found' => (string)($state['account'] ?? '') !== '' ? $state['account'] : '(no company name)',
					'Why'           => (string)($state['reason'] ?? ''),
					'What it means' => 'Nothing was deleted on it. Its company name changed since it was last checked. Check it again '
						. 'on the Hosting tab of Provisioning Setup (a live account loses its test flag), or set the company name to "'
						. self::COMPANY . '" in the test account\'s settings.',
					'Checked'       => (string)($state['time'] ?? '') . ' UTC',
				),
			);
		}
		return array(
			'severity' => IncidentRecord::SEVERITY_WARNING,
			'title'    => 'The test-account cleanup could not list the account',
			'detail'   => array(
				'Account'       => (string)($state['name'] ?? ''),
				'Why'           => (string)($state['reason'] ?? ''),
				'What it means' => 'Nothing was deleted, so test servers past their age are still billing.',
				'Checked'       => (string)($state['time'] ?? '') . ' UTC',
			),
		);
	}

	/** The last run's outcome, one entry per account ({name, time, outcome, ...}), or none. */
	public static function state(): array {
		$state = json_decode((string)Globalvars::get_instance()->get_setting(self::STATE_SETTING), true);
		if (!is_array($state)) {
			return array();
		}
		// A run recorded before there were several accounts is one entry.
		return isset($state['outcome']) ? array($state) : array_values($state);
	}

	/** The task row as condition() wants it: {active}, or null when there is none. */
	public static function taskRow(): ?array {
		foreach (new MultiScheduledTask(array('sct_task_class' => self::TASK_CLASS, 'deleted' => false)) as $task) {
			return array('active' => (bool)$task->get('sct_is_active'));
		}
		return null;
	}

	/** The scopes a run needs that an account's recorded grant lacks, or null when it is not checked. */
	public static function missing_scopes(OperatorCloudAccount $account): ?array {
		return $account->missing_scopes(self::SCOPES);
	}

	private static function save_state(array $state): void {
		Setting::put(self::STATE_SETTING, json_encode($state, JSON_UNESCAPED_SLASHES));
	}

	/** Seconds since $created ('Y-m-d H:i:s' UTC), or null when it cannot be read. */
	private static function age(string $created, int $now): ?int {
		$stamp = trim($created) === '' ? false : strtotime($created . ' UTC');
		return $stamp === false ? null : $now - $stamp;
	}

	private static function kept(array $item): bool {
		foreach ((array)($item['tags'] ?? array()) as $tag) {
			if (strtolower(trim((string)$tag)) === self::KEEP_TAG) {
				return true;
			}
		}
		return false;
	}
}
