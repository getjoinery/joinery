<?php
/**
 * ReleaseLogWatch - asks Sigstore, every day, whether the next release can
 * still be logged, so the changeover to a new log is never missed
 * (spec release_transparency, O6, WP3).
 *
 * WHY. Sigstore moves Rekor to a new log about once a year and lists the new
 * one months before it takes over. Every node must hold the new log's key
 * before the first release logged there, and only a release logged on the old
 * log can hand it to them. So the key has to be pinned and shipped inside that
 * window; miss it and no release can reach nodes again.
 *
 * WHAT. The same discovery the publisher runs, with the same two key sets:
 * shipping (release_keys/log/ in this tree, what the next release puts on
 * nodes) and held (the keys the last logged release installed). Each run's
 * conclusion is stored in one setting; two incident sources read it, on this
 * management node's own node:
 *
 *   plane:release_log         publishing is, or will be, refused: a future log
 *                             nodes do not hold the key of yet - not published
 *                             by Sigstore, not pinned here, or pinned and not
 *                             yet shipped by a logged release (warning,
 *                             critical in the last 30 days) - or any other
 *                             refusal (critical)
 *   plane:release_log_blind   the watch cannot say: Sigstore unreachable or
 *                             unreadable, no conclusion for 3 days, or the task
 *                             not scheduled (warning, critical after 14 days)
 *
 * SILENCE IS NEVER GREEN. The only all-clear is a run that concluded and saw
 * nothing wrong. A run that could not conclude keeps the last conclusion
 * standing and raises the blind incident beside it, and a watch that stops
 * running goes blind on its own after three days.
 *
 * Runs only on the box that logs releases: the one that mints release
 * versions and signs its own tree. Everywhere else it has nothing to watch.
 *
 * @version 1.0
 */
class ReleaseLogWatch {

	/** The stored conclusion: a declared setting holding JSON. */
	const STATE_SETTING = 'server_manager_release_log_watch';

	/** The scheduled task that runs it. */
	const TASK_CLASS = 'WatchReleaseLog';

	/** A clear conclusion is asked again after this long; anything else every run. */
	const CONCLUDE_EVERY = 72000;

	/** No conclusion for this long is blind, whatever the last attempt said. */
	const BLIND_AFTER = 259200;

	/** Blind this long is critical: the shard window is months, and this eats into it. */
	const BLIND_CRITICAL_AFTER = 1209600;

	/** A future log taking over within this long is critical. */
	const CRITICAL_WITHIN = 2592000;

	/** Whether this box logs releases, and so has a changeover to watch. */
	public static function watches(): bool {
		return DeploymentHelper::mayMintReleaseVersion()
			&& TreeManifestPublisher::signsItsOwnTree(self::siteRoot());
	}

	/**
	 * One run of the scheduled task.
	 *
	 * @param callable|null $transport HTTP for ReleaseLogClient, injected by tests
	 * @param int|null      $now
	 * @return array{status:string, message:string}
	 */
	public static function run($transport = null, ?int $now = null): array {
		$now = $now ?? time();
		if (!self::watches()) {
			return array('status' => 'success', 'message' => 'This site does not log releases, so it has no log changeover to watch.');
		}
		$state = self::state();
		// Its incidents open on this site's own node. Without one the watch
		// would conclude into a setting nothing reads, so it says so here.
		$no_self = ManagedNode::self_node() === null
			? ' This site has no node of its own on its dashboard, so no incident can open: add it.' : '';
		if (($state['attempt'] ?? null) === 'concluded' && ($state['result'] ?? null) === 'clear'
			&& $now - (int)($state['concluded_at'] ?? 0) < self::CONCLUDE_EVERY) {
			return array('status' => $no_self ? 'error' : 'success',
				'message' => 'Concluded clear at ' . gmdate('Y-m-d H:i', (int)$state['concluded_at']) . ' UTC; asked again daily.' . $no_self);
		}
		try {
			$held = ReleaseStatementPublisher::held(ReleaseStatementPublisher::previous())['log'];
		} catch (\Throwable $e) {
			$state = self::conclude($state, array('result' => 'refused', 'message' => $e->getMessage()), $now);
			self::save($state);
			return array('status' => 'error', 'message' => $e->getMessage());
		}
		$state = self::check(new ReleaseLogClient(ReleaseLogClient::repoLogKeys(self::siteRoot()), $held, $transport, $now), $state, $now, $held);
		self::save($state);
		if ($state['attempt'] === 'blind') {
			return array('status' => 'error', 'message' => 'Cannot see Sigstore: ' . $state['blind_reason'] . $no_self);
		}
		return array('status' => $no_self ? 'error' : 'success', 'message' => ($state['result'] === 'clear'
			? 'Concluded clear: the live log is ' . $state['live'] . ($state['ahead'] ? '; ahead, pinned: ' . implode(', ', $state['ahead']) : '')
			: 'Concluded: ' . $state['message']) . $no_self);
	}

	/**
	 * Run discovery with $client and fold the answer into $state. Pure apart
	 * from the client's HTTP, so tests drive it with recorded documents.
	 *
	 * A discovery that passes is not yet clear. Publish checks a future log's
	 * key against the tree only, so it passes as soon as the key is committed;
	 * nodes hold it only once a logged release has shipped it. Until then
	 * ($held, what the last logged release installed, lacks it) the window is
	 * still open. And a future log whose key Sigstore has not published yet
	 * does not stop a publish, so it is reported here.
	 *
	 * @param array|null $held origin => DER the last logged release installed; null before genesis
	 */
	public static function check(ReleaseLogClient $client, array $state, int $now, ?array $held = null): array {
		try {
			$shard = $client->discover();
			foreach ($shard['waiting'] as $w) {
				return self::conclude($state, array('result' => 'ahead', 'stage' => 'unpublished', 'origin' => $w['origin'], 'starts_at' => $w['start'],
					'message' => "Sigstore lists {$w['origin']} as its log from " . gmdate('Y-m-d', $w['start']) . ', but its trusted root does not publish the log\'s key yet.',
					'live' => $shard['origin'], 'ahead' => $shard['ahead']), $now);
			}
			foreach ($shard['ahead_detail'] as $a) {
				if ($held !== null && !(isset($held[$a['origin']]) && hash_equals($held[$a['origin']], $a['key']))) {
					return self::conclude($state, array('result' => 'ahead', 'stage' => 'unshipped', 'origin' => $a['origin'], 'starts_at' => $a['start'],
						'message' => "{$a['origin']}'s key is pinned in this repository, but no logged release has carried it to nodes yet.",
						'live' => $shard['origin'], 'ahead' => $shard['ahead']), $now);
				}
			}
			return self::conclude($state, array('result' => 'clear', 'live' => $shard['origin'], 'ahead' => $shard['ahead']), $now);
		} catch (ReleaseLogBlindException $e) {
			$state['watching_since'] = $state['watching_since'] ?? $now;
			$state['attempt'] = 'blind';
			$state['attempted_at'] = $now;
			$state['blind_reason'] = $e->getMessage();
			return $state;
		} catch (ReleaseLogShardAheadException $e) {
			return self::conclude($state, array('result' => 'ahead', 'stage' => 'unpinned', 'message' => $e->getMessage(), 'origin' => $e->origin,
				'starts_at' => $e->starts_at), $now);
		} catch (ReleaseLogException $e) {
			return self::conclude($state, array('result' => 'refused', 'message' => $e->getMessage()), $now);
		}
	}

	private static function conclude(array $state, array $result, int $now): array {
		return array('watching_since' => $state['watching_since'] ?? $now, 'attempt' => 'concluded', 'attempted_at' => $now,
			'concluded_at' => $now) + $result + array('live' => null, 'ahead' => array(), 'message' => null);
	}

	/**
	 * The two conditions, from the stored state, the task row and the time.
	 * Each is null or ['title', 'severity', 'detail'] as IncidentSource wants.
	 *
	 * @param array      $state from state()
	 * @param array|null $task  {active:bool, created:int} for the task row, null when there is none
	 * @return array{refused:?array, blind:?array}
	 */
	public static function conditions(array $state, ?array $task, int $now): array {
		return array('refused' => self::refusedCondition($state, $now), 'blind' => self::blindCondition($state, $task, $now));
	}

	private static function refusedCondition(array $state, int $now): ?array {
		$result = $state['result'] ?? null;
		if (!isset($state['concluded_at']) || $result === 'clear' || $result === null) {
			return null;
		}
		$asof = gmdate('Y-m-d H:i', (int)$state['concluded_at']) . ' UTC';
		if ($result === 'ahead') {
			$starts = (int)$state['starts_at'];
			$date = gmdate('Y-m-d', $starts);
			$days = (int)floor(($starts - $now) / 86400);
			$stage = $state['stage'] ?? 'unpinned';
			$fix = array(
				'unpinned'    => 'Add the key file the message names to the repository, commit it, and publish once before ' . $date
					. '. That release, logged on the current log, hands every node the new key.',
				'unshipped'   => 'Publish once before ' . $date . '. That release, logged on the current log, hands every node the new key; '
					. 'this clears when it has.',
				'unpublished' => 'Nothing to add yet: Sigstore has not published the new log\'s key. Releases still publish meanwhile. '
					. 'When Sigstore publishes it, this incident names the key file to add.',
			)[$stage] ?? '';
			return array(
				'title'    => 'Sigstore\'s next log, ' . $state['origin'] . ', takes over on ' . $date
					. ($days >= 0 ? " ({$days} days)" : '') . ' and nodes cannot trust it yet',
				'severity' => $starts - $now < self::CRITICAL_WITHIN ? IncidentRecord::SEVERITY_CRITICAL : IncidentRecord::SEVERITY_WARNING,
				'detail'   => array(
					'Why'   => (string)$state['message'],
					'Fix'   => $fix,
					'Stakes' => 'A release logged on a log whose key nodes do not hold is refused by every node, and only a release '
						. 'logged before the changeover can hand them the key. Miss the date and no release reaches any node again.',
					'As of' => $asof,
				),
			);
		}
		return array(
			'title'    => 'Publishing a release is refused: Sigstore\'s log does not match what this repository pins',
			'severity' => IncidentRecord::SEVERITY_CRITICAL,
			'detail'   => array(
				'Why'   => (string)$state['message'],
				'Fix'   => 'The message says what to change. A publish refuses for the same reason until it is fixed.',
				'As of' => $asof,
			),
		);
	}

	private static function blindCondition(array $state, ?array $task, int $now): ?array {
		$last = isset($state['concluded_at']) ? gmdate('Y-m-d H:i', (int)$state['concluded_at']) . ' UTC' : 'never';
		if ($task === null || !$task['active']) {
			$why = 'The ' . self::TASK_CLASS . ' scheduled task ' . ($task === null ? 'does not exist' : 'is turned off') . ', so nothing asks Sigstore.';
			$fix = 'Turn the Watch Release Log task on under Admin > System > Scheduled Tasks.';
		} elseif (($state['attempt'] ?? null) === 'blind') {
			$why = (string)$state['blind_reason'];
			$fix = 'If Sigstore is reachable from this machine and still answers this way, its format has changed and ReleaseLogClient needs updating.';
		} else {
			$since = (int)($state['concluded_at'] ?? ($state['watching_since'] ?? $task['created']));
			if ($now - $since <= self::BLIND_AFTER) {
				return null;
			}
			$why = 'The watch has not concluded since ' . gmdate('Y-m-d H:i', $since) . ' UTC.';
			$fix = 'Check the Watch Release Log task\'s last run under Admin > System > Scheduled Tasks.';
		}
		$since = (int)($state['concluded_at'] ?? ($state['watching_since'] ?? ($task['created'] ?? $now)));
		return array(
			'title'    => 'The release-log watch cannot see Sigstore',
			'severity' => $now - $since > self::BLIND_CRITICAL_AFTER ? IncidentRecord::SEVERITY_CRITICAL : IncidentRecord::SEVERITY_WARNING,
			'detail'   => array(
				'Why'             => $why,
				'Fix'             => $fix,
				'Stakes'          => 'While it cannot see, a coming change of Sigstore\'s log would go unnoticed until a publish refuses.',
				'Last conclusion' => $last,
			),
		);
	}

	/** The stored conclusion, or an empty state. */
	public static function state(): array {
		$state = json_decode((string)Globalvars::get_instance()->get_setting(self::STATE_SETTING), true);
		return is_array($state) ? $state : array();
	}

	private static function save(array $state): void {
		Setting::put(self::STATE_SETTING, json_encode($state, JSON_UNESCAPED_SLASHES));
	}

	/** The task row as conditions() wants it: {active, created}, or null when there is none. */
	public static function taskRow(): ?array {
		foreach (new MultiScheduledTask(array('sct_task_class' => self::TASK_CLASS, 'deleted' => false)) as $task) {
			return array('active' => (bool)$task->get('sct_is_active'), 'created' => (int)strtotime((string)$task->get('sct_create_time') . ' UTC'));
		}
		return null;
	}

	/** Whether $node is the node this management node is, where the watch's incidents belong. */
	public static function isSelf(ManagedNode $node): bool {
		static $self_id = false;
		if ($self_id === false) {
			$self = ManagedNode::self_node();
			$self_id = $self ? (int)$self->key : null;
		}
		return $self_id !== null && (int)$node->key === $self_id;
	}

	private static function siteRoot(): string {
		return PathHelper::getSiteRoot();
	}
}
