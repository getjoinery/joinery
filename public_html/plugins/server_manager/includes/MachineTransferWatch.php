<?php
/**
 * MachineTransferWatch — each cloud machine's outbound transfer this month,
 * read from its provider once a day (specs/node_outbound_and_transfer.md WP1).
 *
 * The plane reads every account it holds a credential for: the operator token
 * and each connected customer account. It lists the account's machines,
 * matches them to its nodes by address (a node's host, and its server's
 * where it is a site on a shared one), and reads the transfer of each machine
 * a node runs on. A machine no node runs on is not read: the account pool
 * alert covers the account, and this is about the plane's nodes.
 *
 * What it stores is facts (MachineTransfer). What they mean is
 * IncidentSourceMachineTransfer's, from conditions() here: a day far above
 * the machine's share, a month on pace past its allowance, the allowance
 * passed. One node speaks for each machine, so a server of twelve sites raises
 * one incident.
 *
 * @version 1.1 - review R2-R5: a node is on one machine (host match over a join's report, newest of
 *                equals; only the newest join counts); the link is a one-column update; a read never
 *                parks a connected account; a new month's first figure no lower than last month's is held
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/ProvisionCustomerCloud.php'));

class MachineTransferWatch {

	/** A day's rate above this many times the machine's daily share is a spike. */
	const SPIKE_TIMES = 3;
	/** And only when the day carried at least this much: a tiny machine's noise is not a runaway. */
	const SPIKE_MIN_BYTES = 1000000000;
	/** The rate is measured over at least this long; a read sooner keeps the older start. */
	const MIN_WINDOW_SECONDS = 72000;   // 20 hours
	/** A month projected past this many times the allowance is on pace to overrun. */
	const PACE_TIMES = 1.5;
	/** The projection waits this long into the month: a first day says little. */
	const PACE_AFTER_SECONDS = 259200;  // 3 days
	/** A reading older than this is not current, and no condition is drawn from it. */
	const STALE_SECONDS = 259200;       // 3 days
	/** How long into a month a figure no lower than last month's is taken as not yet started again. */
	const ROLL_GRACE_SECONDS = 172800;  // 2 days

	/** How a node's address matched a machine: its own or its server's host, or only a join's report. */
	const MATCH_HOST = 2;
	const MATCH_JOIN = 1;

	/**
	 * Read every account and record what its machines sent.
	 *
	 * @param array|null $accounts  [account key => CloudMachineTransfer driver], or null for the
	 *                              accounts this plane holds (tests pass their own)
	 * @param callable|null $resolve  host name => list of addresses (tests pass their own)
	 * @return array{status: string, message: string}
	 */
	public static function run(?array $accounts = null, ?callable $resolve = null, ?int $now = null): array {
		$now = $now ?? time();
		$problems = array();
		if ($accounts === null) {
			list($accounts, $problems) = self::accounts();
		}
		if (!$accounts) {
			return array('status' => $problems ? 'error' : 'skipped',
				'message' => $problems ? implode(' ', $problems) : 'No cloud account to read.');
		}

		$nodes = self::node_addresses($resolve ?? array(__CLASS__, 'resolve'));
		$read_ok = array();      // account key => true when its listing succeeded
		$machines = 0;
		$linked = array();       // node id => machine row id

		// Every machine any node could be on: [instance id => ['key', 'driver',
		// 'instance', 'nodes' => [node id => strength]]].
		$candidates = array();
		foreach ($accounts as $key => $driver) {
			if (!$driver instanceof CloudMachineTransfer) {
				continue;
			}
			try {
				$instances = $driver->listInstances();
			} catch (Exception $e) {
				$problems[] = 'Account ' . $key . ': its machines could not be listed (' . $e->getMessage() . ').';
				continue;
			}
			$read_ok[$key] = true;
			foreach ($instances as $instance) {
				$on = self::nodes_on($instance, $nodes);
				if ($on) {
					$candidates[(string)$instance['id']] = array('key' => (string)$key, 'driver' => $driver,
						'instance' => $instance, 'nodes' => $on);
				}
			}
		}

		// A node is on one machine. Where its addresses match more than one
		// (a site that moved keeps the old machine's addresses on its old
		// join), its own host or its server's beats what an agent reported
		// at a join, and of equals the newest machine wins.
		$chosen = array();       // instance id => [node id => is the server's own node]
		foreach (self::choose_machines($candidates) as $node_id => $instance_id) {
			$chosen[$instance_id][$node_id] = $nodes[$node_id]['is_host'];
		}

		foreach ($chosen as $instance_id => $on) {
			$c = $candidates[$instance_id];
			$instance = $c['instance'];
			$row = MachineTransfer::for_instance('linode', (string)$instance_id) ?? new MachineTransfer(NULL);
			$row->set('mtr_provider', 'linode');
			$row->set('mtr_instance_id', (string)$instance_id);
			$row->set('mtr_account', $c['key']);
			$row->set('mtr_label', (string)($instance['label'] ?? ''));
			$row->set('mtr_region', (string)($instance['region'] ?? ''));
			if (($instance['created'] ?? '') !== '') {
				$row->set('mtr_instance_created_time', $instance['created']);
			}
			$row->set('mtr_mgn_managed_node_id', self::speaker($on));
			try {
				self::record($row, $c['driver']->getInstanceTransfer((string)$instance_id), $now);
				$row->set('mtr_error', null);
			} catch (Exception $e) {
				$row->set('mtr_error', $e->getMessage());
				$problems[] = 'Machine ' . $instance['label'] . ': its transfer could not be read (' . $e->getMessage() . ').';
			}
			$row->save();
			$machines++;
			foreach (array_keys($on) as $node_id) {
				$linked[(int)$node_id] = (int)$row->key;
			}
		}

		self::link_nodes($linked, $read_ok);

		$message = $machines . ' machine(s) read on ' . count($read_ok) . ' account(s).';
		if ($problems) {
			$message .= ' ' . count($problems) . ' problem(s): ' . implode(' ', array_slice($problems, 0, 3));
		}
		return array('status' => $problems ? 'error' : 'success', 'message' => $message);
	}

	/**
	 * Put a reading on the row. The read before stays the start of the rate
	 * unless it is old enough to be a day's window; a new month starts the
	 * window at the month's start (or the machine's creation, if later).
	 * Does not save.
	 */
	public static function record(MachineTransfer $row, array $reading, int $now): void {
		$period = gmdate('Y-m', $now);
		$same_period = (string)$row->get('mtr_period') === $period && trim((string)$row->get('mtr_read_time')) !== '';
		$used = max(0, (int)($reading['used_bytes'] ?? 0));
		// The first read of a month on its first two days: a figure no lower
		// than last month's last is last month's, not yet started again at the
		// provider. It is not recorded, so last month's row stays and raises
		// nothing (conditions() reads only the current month); the next read
		// tries again.
		$last_used = (int)$row->get('mtr_used_bytes');
		if (!$same_period && $last_used > 0 && $used >= $last_used
			&& $now - strtotime($period . '-01 00:00:00 UTC') < self::ROLL_GRACE_SECONDS) {
			return;
		}
		if (!$same_period) {
			$row->set('mtr_prev_used_bytes', 0);
			$row->set('mtr_prev_read_time', gmdate('Y-m-d H:i:s', self::counted_from($row, $now)));
		} else {
			$last = strtotime($row->get('mtr_read_time') . ' UTC');
			if ($last !== false && $now - $last >= self::MIN_WINDOW_SECONDS) {
				$row->set('mtr_prev_used_bytes', (int)$row->get('mtr_used_bytes'));
				$row->set('mtr_prev_read_time', $row->get('mtr_read_time'));
			}
		}
		$row->set('mtr_period', $period);
		$row->set('mtr_used_bytes', $used);
		$row->set('mtr_quota_gb', (float)($reading['quota_gb'] ?? 0));
		$row->set('mtr_billable_gb', (float)($reading['billable_gb'] ?? 0));
		$row->set('mtr_read_time', gmdate('Y-m-d H:i:s', $now));
	}

	/**
	 * What the row's reading says now, worst first: each
	 * ['kind' => full|spike|pace, 'severity', 'title', 'detail' => label => text].
	 * Nothing when the reading is from another month, is stale, or has no
	 * allowance to measure against.
	 */
	public static function conditions(MachineTransfer $row, int $now): array {
		$read = strtotime(trim((string)$row->get('mtr_read_time')) . ' UTC');
		$quota = (float)$row->get('mtr_quota_gb');
		if ($read === false || $quota <= 0 || (string)$row->get('mtr_period') !== gmdate('Y-m', $now)
			|| $now - $read > self::STALE_SECONDS) {
			return array();
		}
		$used = (int)$row->get('mtr_used_bytes');
		$used_gb = $used / 1e9;
		$from = self::counted_from($row, $read);
		$period_end = strtotime(gmdate('Y-m-01 00:00:00', $read) . ' UTC +1 month');
		$days_covered = max(1.0, ($period_end - $from) / 86400);
		$daily_gb = $quota / $days_covered;
		$figures = array(
			'This month' => self::gb($used_gb) . ' of ' . self::gb($quota) . ' allowance',
			'Read'       => gmdate('Y-m-d H:i', $read) . ' UTC, from the provider',
		);
		$out = array();

		if ($used_gb > $quota) {
			$out[] = array('kind' => 'full', 'severity' => IncidentRecord::SEVERITY_CRITICAL,
				'title' => 'This server has used its whole month\'s transfer allowance',
				'detail' => $figures + array(
					'Billed past it so far' => self::gb((float)$row->get('mtr_billable_gb')),
					'What it means' => 'Every gigabyte it sends until the month ends is billed past the account\'s pool once the pool runs out.',
				));
		}

		$prev_time = strtotime(trim((string)$row->get('mtr_prev_read_time')) . ' UTC');
		if ($prev_time !== false && $read - $prev_time >= self::MIN_WINDOW_SECONDS) {
			$sent = $used - (int)$row->get('mtr_prev_used_bytes');
			$per_day_gb = ($sent / 1e9) / (($read - $prev_time) / 86400);
			if ($sent >= self::SPIKE_MIN_BYTES && $per_day_gb > self::SPIKE_TIMES * $daily_gb) {
				$out[] = array('kind' => 'spike', 'severity' => IncidentRecord::SEVERITY_WARNING,
					'title' => 'This server sent ' . self::gb($per_day_gb) . ' a day, ' . round($per_day_gb / $daily_gb, 1)
						. ' times its share of the month\'s allowance',
					'detail' => $figures + array(
						'Its share' => self::gb($daily_gb) . ' a day',
						'What it means' => 'Something on it is sending far more than usual: a large download, a backup to an outside bucket, or a site doing something it should not.',
					));
			}
		}

		if ($read - $from >= self::PACE_AFTER_SECONDS) {
			$fraction = ($read - $from) / ($period_end - $from);
			$projected = $used_gb / $fraction;
			if ($projected > self::PACE_TIMES * $quota && $used_gb <= $quota) {
				$out[] = array('kind' => 'pace', 'severity' => IncidentRecord::SEVERITY_WARNING,
					'title' => 'This server is on pace to pass its transfer allowance this month',
					'detail' => $figures + array(
						'At this rate' => 'about ' . self::gb($projected) . ' by the month\'s end',
					));
			}
		}
		return $out;
	}

	/** When the month's counting starts for this machine: the month's start, or its creation if later. */
	private static function counted_from(MachineTransfer $row, int $at): int {
		$start = strtotime(gmdate('Y-m-01 00:00:00', $at) . ' UTC');
		$created = strtotime(trim((string)$row->get('mtr_instance_created_time')) . ' UTC');
		return ($created !== false && $created > $start && $created <= $at) ? $created : $start;
	}

	private static function gb(float $gb): string {
		return ($gb >= 100 ? number_format($gb, 0) : number_format($gb, 1)) . ' GB';
	}

	/**
	 * The accounts this plane holds: [key => driver], and the sentences for
	 * those it could not use. A connected account whose grant has expired is
	 * skipped silently: the accounts page already asks its owner to reconnect.
	 */
	private static function accounts(): array {
		$accounts = array();
		$problems = array();
		$token = ProvisionCustomerCloud::operator_compute_token();
		if ($token !== '') {
			$accounts['operator'] = new LinodeComputeDriver($token);
		}
		foreach (new MultiCustomerCloudAccount(array('status' => 'active', 'deleted' => false)) as $account) {
			if ((string)$account->get('cca_provider') !== 'linode' || CustomerCloudAccount::grant_expired($account)) {
				continue;
			}
			// A read-only watch never parks an account: a failed refresh is a
			// problem in this run's report, and the account stays as it was.
			$resolved = ProvisionCustomerCloud::account_driver($account, false);
			if ($resolved['driver'] === null) {
				$problems[] = 'Connected account ' . $account->key . ': ' . $resolved['reason'];
				continue;
			}
			$accounts['cca:' . $account->key] = $resolved['driver'];
		}
		return array($accounts, $problems);
	}

	/**
	 * Every live node's addresses, packed: [node id => ['host' => [...],
	 * 'join' => [...], 'is_host' => bool]]. 'host' is the node's host
	 * (resolved when it is a name) and its server's where it is a site on a
	 * shared one; 'join' is what the machine reported when its agent last
	 * joined: a host name behind a proxy resolves to the proxy, and only the
	 * machine knows its own addresses. Only the newest approved join counts:
	 * an older one may name a machine the site has since left.
	 */
	private static function node_addresses(callable $resolve): array {
		$hosts = array();
		foreach (new MultiManagedHost(array('deleted' => false)) as $host) {
			$hosts[(int)$host->key] = $host;
		}
		$joined = array();   // node id => [join id, addresses]
		foreach (new MultiAgentJoinRequest(array('status' => AgentJoinRequest::STATUS_APPROVED)) as $join) {
			$node_id = (int)$join->get('ajr_mgn_managed_node_id');
			if ($node_id && !$join->get('ajr_delete_time') && (int)$join->key > ($joined[$node_id][0] ?? 0)) {
				$joined[$node_id] = array((int)$join->key, $join->addresses());
			}
		}
		$pack = function (array $names) use ($resolve) {
			$addrs = array();
			foreach (array_unique(array_filter($names, 'strlen')) as $name) {
				$list = IpAddress::binary($name) !== null ? array($name) : (array)$resolve($name);
				foreach ($list as $a) {
					if (($b = IpAddress::binary((string)$a)) !== null) {
						$addrs[$b] = true;
					}
				}
			}
			return array_keys($addrs);
		};
		$out = array();
		foreach (new MultiManagedNode(array('deleted' => false)) as $node) {
			$names = array(trim((string)$node->get('mgn_host')));
			$host = $hosts[(int)$node->get('mgn_mgh_managed_host_id')] ?? null;
			if ($host) {
				$names[] = trim((string)$host->get('mgh_host'));
			}
			$out[(int)$node->key] = array(
				'host'    => $pack($names),
				'join'    => $pack($joined[(int)$node->key][1] ?? array()),
				'is_host' => $host !== null && (int)$host->get('mgh_mgn_managed_node_id') === (int)$node->key,
			);
		}
		return $out;
	}

	/** The nodes this instance's addresses match: [node id => MATCH_HOST or MATCH_JOIN]. */
	private static function nodes_on(array $instance, array $nodes): array {
		$mine = array();
		foreach (array_merge((array)($instance['ipv4_public'] ?? array()), array((string)($instance['ipv6'] ?? ''))) as $a) {
			if (($b = IpAddress::binary((string)$a)) !== null) {
				$mine[$b] = true;
			}
		}
		$on = array();
		foreach ($nodes as $id => $n) {
			foreach (array(self::MATCH_HOST => $n['host'], self::MATCH_JOIN => $n['join']) as $strength => $addrs) {
				foreach ($addrs as $b) {
					if (isset($mine[$b])) {
						$on[$id] = max($on[$id] ?? 0, $strength);
						break;
					}
				}
			}
		}
		return $on;
	}

	/**
	 * Each node's one machine: [node id => instance id]. A match by the node's
	 * own or its server's host beats one by a join's report; of equals the
	 * machine created last wins (a site copy's new server over the old).
	 */
	private static function choose_machines(array $candidates): array {
		$best = array();   // node id => [strength, created, instance id]
		foreach ($candidates as $instance_id => $c) {
			$created = (string)($c['instance']['created'] ?? '');
			foreach ($c['nodes'] as $node_id => $strength) {
				$mine = array($strength, $created, (string)$instance_id);
				if (!isset($best[$node_id]) || $mine > $best[$node_id]) {
					$best[$node_id] = $mine;
				}
			}
		}
		return array_map(function ($b) { return $b[2]; }, $best);
	}

	/** The node that speaks for the machine: the server's own node, else the lowest-numbered. */
	private static function speaker(array $on): int {
		ksort($on);
		foreach ($on as $id => $is_host) {
			if ($is_host) {
				return (int)$id;
			}
		}
		return (int)array_key_first($on);
	}

	/**
	 * Point each node at its machine's row. A node no longer matched loses its
	 * link only when the account its old row was read through was read this
	 * time: a failed listing never unlinks anything.
	 */
	private static function link_nodes(array $linked, array $read_ok): void {
		foreach (new MultiManagedNode(array('deleted' => false)) as $node) {
			$want = $linked[(int)$node->key] ?? null;
			$have = (int)$node->get('mgn_mtr_machine_transfer_id') ?: null;
			if ($want === $have) {
				continue;
			}
			if ($want === null) {
				$old = new MachineTransfer($have, TRUE);
				if ($old->key && !isset($read_ok[(string)$old->get('mtr_account')])) {
					continue;
				}
			}
			// One column, not the whole row: an agent report may have written
			// the node seconds ago, after this collection loaded it.
			ManagedNode::updateColumns((int)$node->key, array('mgn_mtr_machine_transfer_id' => $want));
		}
	}

	/** A host name's addresses, IPv4 and IPv6. */
	public static function resolve(string $name): array {
		$out = array();
		foreach ((array)@gethostbynamel($name) as $a) {
			$out[] = (string)$a;
		}
		foreach ((array)@dns_get_record($name, DNS_AAAA) as $r) {
			if (!empty($r['ipv6'])) {
				$out[] = (string)$r['ipv6'];
			}
		}
		return $out;
	}
}
