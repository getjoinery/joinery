<?php
/**
 * TestCloudCleanup — deletes test servers and volumes past their age on a
 * cloud account kept for testing (specs/test_cloud_account_and_prod_management.md WP3).
 *
 * The safety catch is a property of the account, not of this site: a run
 * deletes only when the operator token's account carries the company name
 * COMPANY, read from the provider on every run. A live account never carries
 * it, so a live token on this site, or the task turned on at a production
 * management node, deletes nothing; the run stops and its incident names the
 * account it found. There is no "this is a test site" setting to flip in the
 * wrong place.
 *
 * Never deleted: anything tagged KEEP_TAG at the provider, and a server at
 * the address of a live managed node here (it is listed as held, so the
 * operator tags it or removes the node first).
 *
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
	const DEFAULT_MAX_AGE_HOURS = 24;
	/** The token scopes a run needs, as scope => what it is for. */
	const SCOPES = array(
		'linodes:read_write' => 'deleting servers',
		'volumes:read_write' => 'deleting volumes',
		'account:read_only'  => 'reading the account\'s company name, the safety catch',
	);

	/** The operator token's driver, or null when no token is set. */
	public static function driver() {
		$token = ProvisionCustomerCloud::operator_compute_token();
		return $token === '' ? null : new LinodeComputeDriver($token);
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
	 * One run: plan, then delete. Records the outcome for the setup page and
	 * the incident. Returns the scheduled task's {status, message}.
	 */
	public static function run($driver = null, ?callable $resolve = null, ?int $now = null): array {
		$now = $now ?? time();
		$driver = $driver ?? self::driver();
		if ($driver === null) {
			return array('status' => 'skipped', 'message' => 'No operator cloud token is set on this site.');
		}
		$nodes = MachineTransferWatch::node_addresses($resolve ?? array('MachineTransferWatch', 'resolve'));
		try {
			$plan = self::plan($driver, $nodes, $now, self::max_age_hours());
		} catch (CloudComputeException $e) {
			self::save_state(array('time' => gmdate('Y-m-d H:i:s', $now), 'outcome' => 'failed',
				'reason' => 'The account\'s servers or volumes could not be listed: ' . $e->getMessage()));
			return array('status' => 'error', 'message' => 'Nothing deleted: the account could not be listed (' . $e->getMessage() . ').');
		}
		if (!$plan['safe']) {
			self::save_state(array('time' => gmdate('Y-m-d H:i:s', $now), 'outcome' => 'refused',
				'account' => $plan['account'], 'reason' => $plan['reason']));
			return array('status' => 'error', 'message' => 'Nothing deleted. ' . $plan['reason']);
		}

		$deleted = array('instances' => array(), 'volumes' => array());
		$failures = array();
		if ($plan['volume_error'] !== '') {
			$failures[] = 'volumes could not be listed: ' . $plan['volume_error'];
		}
		foreach ($plan['instances'] as $i) {
			try {
				$driver->deleteInstance($i['id']);
				$deleted['instances'][] = $i['label'] . ' (' . $i['id'] . ')';
			} catch (CloudComputeException $e) {
				$failures[] = 'server ' . $i['label'] . ': ' . $e->getMessage();
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
		self::save_state(array('time' => gmdate('Y-m-d H:i:s', $now), 'outcome' => 'ran', 'account' => $plan['account'],
			'deleted_instances' => $deleted['instances'], 'deleted_volumes' => $deleted['volumes'],
			'held' => $held, 'kept' => $plan['kept'], 'failures' => $failures));

		$message = 'Deleted ' . count($deleted['instances']) . ' server(s)'
			. ($deleted['instances'] ? ' (' . implode(', ', $deleted['instances']) . ')' : '')
			. ' and ' . count($deleted['volumes']) . ' volume(s)'
			. ($deleted['volumes'] ? ' (' . implode(', ', $deleted['volumes']) . ')' : '') . '.';
		if ($held) {
			$message .= ' Held, at a managed node\'s address: ' . implode('; ', $held) . '. Tag each keep, or remove its node.';
		}
		if ($failures) {
			$message .= ' Failed: ' . implode('; ', $failures) . '.';
		}
		return array('status' => $failures ? 'error' : 'success', 'message' => $message);
	}

	/**
	 * The incident while the task is on: the last run refused (the safety
	 * catch failed) or could not list the account. Null otherwise.
	 */
	public static function condition(array $state, ?array $task): ?array {
		if (!$task || !$task['active'] || !in_array($state['outcome'] ?? '', array('refused', 'failed'), true)) {
			return null;
		}
		if ($state['outcome'] === 'refused') {
			return array(
				'severity' => IncidentRecord::SEVERITY_WARNING,
				'title'    => 'The test-account cleanup stopped: the cloud token here is not for an account marked disposable',
				'detail'   => array(
					'Account found' => (string)($state['account'] ?? '') !== '' ? $state['account'] : '(no company name)',
					'Why'           => (string)($state['reason'] ?? ''),
					'What it means' => 'Nothing was deleted. Either this site holds a live account\'s token, or the test account\'s '
						. 'company name was changed. Turn the Delete Old Test Servers task off here, or set the company name to "'
						. self::COMPANY . '" in the test account\'s settings.',
					'Checked'       => (string)($state['time'] ?? '') . ' UTC',
				),
			);
		}
		return array(
			'severity' => IncidentRecord::SEVERITY_WARNING,
			'title'    => 'The test-account cleanup could not list the account',
			'detail'   => array(
				'Why'           => (string)($state['reason'] ?? ''),
				'What it means' => 'Nothing was deleted, so test servers past their age are still billing.',
				'Checked'       => (string)($state['time'] ?? '') . ' UTC',
			),
		);
	}

	/** The last run's outcome, or an empty state. */
	public static function state(): array {
		$state = json_decode((string)Globalvars::get_instance()->get_setting(self::STATE_SETTING), true);
		return is_array($state) ? $state : array();
	}

	/** The task row as condition() wants it: {active}, or null when there is none. */
	public static function taskRow(): ?array {
		foreach (new MultiScheduledTask(array('sct_task_class' => self::TASK_CLASS, 'deleted' => false)) as $task) {
			return array('active' => (bool)$task->get('sct_is_active'));
		}
		return null;
	}

	/** The scopes a run needs that the operator token's recorded grant lacks, or null when it is not checked. */
	public static function missing_scopes(): ?array {
		$granted = ProvisioningSetup::operatorTokenGrantedScopes();
		if ($granted === null) {
			return null;
		}
		return array_intersect_key(self::SCOPES,
			array_flip(LinodeComputeDriver::missingScopes($granted, array_keys(self::SCOPES))));
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
