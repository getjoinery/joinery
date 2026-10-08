<?php
/**
 * ReleaseLogTail - reads every new entry of the public logs our nodes trust,
 * looking for entries under our release statement keys that no publish here
 * wrote (spec release_transparency, O5).
 *
 * WHY. A node installs a release only if its statement is in Sigstore's
 * public log, so a build the publisher was made to sign in secret has to be
 * logged to reach any node. That makes it public, but only to someone who
 * looks: Rekor v2 has no search, so finding "an entry under our key that no
 * release accounts for" means reading the whole log as it grows. This does
 * that, on the box that logs releases, and nothing else does it for nodes.
 *
 * WHAT. Each run reads, for each log nodes trust (release_keys/log/ in this
 * tree and the keys the last logged release installed), the entries added
 * since the last run: entry bundles of 256, searched for the base64 of each
 * statement key (release_keys/statement/ and the keys nodes hold). An entry
 * naming one is compared with the release ledger (rle_release_log_entries):
 * the same index with the same leaf bytes is ours, and its ledger row is
 * stamped as seen in the log; anything else is unaccounted for, and
 * IncidentSourceReleaseLogEntry raises it.
 *
 *  - It starts on each log at our first logged entry there, or, on a log we
 *    never logged to, where the log stood when the tail first saw it.
 *  - It reads only up to a checkpoint seen at least SETTLE_SECONDS earlier.
 *    A publish records its ledger row the moment the log answers, so by then
 *    every entry of ours is in the ledger and none is mistaken for a stranger.
 *  - It stops at a time budget and carries on where it stopped next run.
 *
 * SILENCE IS NEVER GREEN. A log not read up to a settled checkpoint for a day,
 * or a task that is missing or off, is the blind incident
 * (IncidentSourceReleaseLogTailBlind); a run that fails says why there.
 *
 * WHAT IT DOES NOT CHECK. It reads the entries the log serves and checks the
 * checkpoint's signature, but does not hash the entries back to the
 * checkpoint's root. A log that showed this reader different entries than it
 * proves to nodes is a split view, which only witnesses catch (D8).
 *
 * KEYS SIGSTORE LISTS. Once a day it also fetches Sigstore's trusted root and
 * checks every log key a logged release installed on nodes against it (D7). A
 * key Sigstore does not list for that log is a key nodes trust that only we
 * vouch for, and is raised with the unaccounted entries.
 *
 * The state, which the public releases page shows: how far each log has been
 * read, when, every unaccounted entry, and the last key check.
 *
 * @version 1.3 - every key pinned or held for a log reads its checkpoint (WP7 review B7)
 * @version 1.2 - a checkpoint smaller than one already seen is refused; the run reports only entries the ledger
 *                still does not account for
 * @version 1.1 - the daily check of installed log keys against Sigstore's trusted root (D7)
 * @version 1.0
 */
class ReleaseLogTail {

	/** The stored state: a declared setting holding JSON. */
	const STATE_SETTING = 'server_manager_release_log_tail';

	/** The scheduled task that runs it. */
	const TASK_CLASS = 'TailReleaseLog';

	/** Only a checkpoint at least this old is read up to. */
	const SETTLE_SECONDS = 600;

	/** A run reads for at most this long. */
	const BUDGET_SECONDS = 240;

	/** Checkpoints remembered per log, oldest dropped first. */
	const HEADS_KEPT = 48;

	/** A log not read up to a settled checkpoint for this long is blind. */
	const BEHIND_AFTER = 86400;

	/** Blind this long is critical. */
	const BLIND_CRITICAL_AFTER = 604800;

	/** Sigstore's trusted root is asked this often. */
	const ROOT_EVERY = 86400;

	/** No completed key check for this long is blind. */
	const ROOT_BLIND_AFTER = 259200;

	/**
	 * One run of the scheduled task.
	 *
	 * @param callable|null $transport      HTTP GET for LogTileReader, injected by tests
	 * @param callable|null $root_transport HTTP for ReleaseLogClient's trusted-root fetch, injected by tests
	 * @return array{status:string, message:string}
	 */
	public static function run($transport = null, ?int $now = null, int $budget = self::BUDGET_SECONDS, $root_transport = null): array {
		$now = $now ?? time();
		if (!ReleaseLogWatch::watches()) {
			return array('status' => 'success', 'message' => 'This site does not log releases, so it has no log entries to account for.');
		}
		$state = self::state();
		try {
			$held = ReleaseStatementPublisher::held(ReleaseStatementPublisher::previous());
		} catch (\Throwable $e) {
			$state['attempted_at'] = $now;
			$state['last_error'] = $e->getMessage();
			self::save($state);
			return array('status' => 'error', 'message' => $e->getMessage());
		}
		$root = PathHelper::getSiteRoot();
		$statement_keys = array_values(array_unique(array_merge(ReleaseLogClient::repoStatementKeys($root), $held['statement'] ?? array())));
		$log_keys = ReleaseLogClient::keySets(array_merge_recursive(ReleaseLogClient::repoLogKeys($root), $held['log'] ?? array()));
		if (!$statement_keys) {
			return array('status' => 'success', 'message' => 'No release statement key exists yet, so nothing can be logged under one.');
		}

		$ledger = self::ledger();
		$state = self::read(new LogTileReader($transport), $state, $statement_keys, $log_keys, $ledger, $now, microtime(true) + $budget);
		if ($now - (int)($state['root']['checked_at'] ?? 0) >= self::ROOT_EVERY) {
			try {
				$trusted = (new ReleaseLogClient(array(), null, $root_transport, $now))->trustedRoot();
				$state = self::checkRoot($state, $trusted, self::installedLogKeys($ledger), $now);
			} catch (\Throwable $e) {
				$state['root'] = array_merge($state['root'] ?? array(), array('attempted_at' => $now, 'error' => $e->getMessage()));
			}
		}
		foreach ($state['seen'] ?? array() as $id) {
			$row = new ReleaseLogEntry($id, TRUE);
			if ($row->key && !$row->get('rle_seen_in_log_time')) {
				$row->set('rle_seen_in_log_time', gmdate('Y-m-d H:i:s', $now));
				$row->save();
			}
		}
		unset($state['seen']);
		self::save($state);

		$parts = array();
		foreach ($state['logs'] as $origin => $log) {
			$parts[] = "{$origin} read to {$log['next']}" . ($log['target'] > $log['next'] ? ' of ' . $log['target'] : '');
		}
		$message = implode('; ', $parts) ?: 'No log to read.';
		$unaccounted = self::unaccounted($state, $ledger);
		if ($unaccounted) {
			return array('status' => 'error', 'message' => count($unaccounted) . ' entry(ies) under our key no publish here wrote. ' . $message);
		}
		if (!empty($state['root']['unknown'])) {
			return array('status' => 'error', 'message' => count($state['root']['unknown']) . ' installed log key(s) Sigstore does not list. ' . $message);
		}
		if (($state['root']['error'] ?? null) !== null) {
			return array('status' => 'error', 'message' => 'Could not check log keys against Sigstore: ' . $state['root']['error'] . ' ' . $message);
		}
		if ($state['last_error'] !== null) {
			return array('status' => 'error', 'message' => $state['last_error'] . ' ' . $message);
		}
		return array('status' => 'success', 'message' => $message);
	}

	/**
	 * Read each log forward from where $state left it. Pure apart from the
	 * reader's HTTP, so tests drive it with a log of their own.
	 *
	 * @param string[] $statement_keys DER of every statement key to look for
	 * @param array    $log_keys       origin => Ed25519 DER, or a list of them, of every log to read
	 * @param array    $ledger         "origin#index" => {id, leaf} for every entry this site logged
	 * @return array the new state; 'seen' lists the ledger ids found in the log this run
	 */
	public static function read(LogTileReader $reader, array $state, array $statement_keys, array $log_keys, array $ledger, int $now, float $deadline): array {
		$state += array('watching_since' => $now, 'logs' => array(), 'unaccounted' => array());
		$state['attempted_at'] = $now;
		$state['last_error'] = null;
		$state['seen'] = array();
		// The base64 of each key, without its padding: a leaf carries the key
		// as base64 of its DER, so the bundle's bytes can be searched before
		// they are split.
		$needles = array();
		foreach ($statement_keys as $der) {
			$needles[rtrim(base64_encode($der), '=')] = $der;
		}
		ksort($log_keys);
		foreach ($log_keys as $origin => $log_der) {
			try {
				$checkpoint = $reader->checkpoint($origin, array($origin => $log_der));
			} catch (TransparencyProofException $e) {
				$state['last_error'] = "Could not read {$origin}'s checkpoint: " . $e->getMessage();
				continue;
			}
			$size = (int)$checkpoint['tree_size'];
			$log = $state['logs'][$origin] ?? null;
			if ($log === null) {
				$from = self::firstLedgerIndex($ledger, $origin) ?? $size;
				$log = array('from' => $from, 'next' => $from, 'heads' => array(), 'target' => $from, 'caught_up_at' => null, 'read_at' => null);
			}
			$last_head = end($log['heads']);
			if ($last_head !== false && $size < $last_head[0]) {
				// A log only grows. A smaller tree under the log's own key is a
				// rollback or a second view of the log: nothing is read and the
				// log is not called caught up, so the tail goes blind on it.
				$state['last_error'] = "{$origin} signed a checkpoint of {$size} entries after one of {$last_head[0]}; a log never shrinks.";
				continue;
			}
			if ($last_head === false || $size > $last_head[0]) {
				$log['heads'][] = array($size, $now);
				$log['heads'] = array_slice($log['heads'], -self::HEADS_KEPT);
			}
			$settled = null;
			foreach ($log['heads'] as $head) {
				if ($head[1] <= $now - self::SETTLE_SECONDS) {
					$settled = max((int)$settled, $head[0]);
				}
			}
			$target = max($log['target'], (int)$settled);
			$log['target'] = $target;
			try {
				while ($log['next'] < $target && microtime(true) < $deadline) {
					$tile = intdiv($log['next'], LogTileReader::ENTRIES_PER_TILE);
					$base = $tile * LogTileReader::ENTRIES_PER_TILE;
					$raw = $reader->bundleRaw($origin, $tile, $target);
					$end = min(LogTileReader::ENTRIES_PER_TILE, $target - $base);
					foreach ($needles as $needle => $der) {
						if (strpos($raw['bytes'], $needle) === false) {
							continue;
						}
						for ($i = $log['next'] - $base; $i < $end; $i++) {
							$leaf = $raw['entries'][$i] ?? '';
							if (strpos($leaf, $needle) !== false && self::leafNames($leaf, $statement_keys)) {
								$state = self::found($state, $ledger, $origin, $base + $i, $leaf, $now);
							}
						}
					}
					$log['next'] = $base + $end;
					$log['read_at'] = $now;
				}
			} catch (TransparencyProofException $e) {
				$state['last_error'] = "Could not read {$origin} at entry {$log['next']}: " . $e->getMessage();
			}
			if ($settled !== null && $log['next'] >= $target) {
				$log['caught_up_at'] = $now;
			}
			$state['logs'][$origin] = $log;
		}
		$state['seen'] = array_values(array_unique($state['seen']));
		return $state;
	}

	/** Whether $leaf is an entry whose verifier is one of $keys, read as a log entry. */
	private static function leafNames(string $leaf, array $keys): bool {
		$doc = json_decode($leaf, true);
		$raw = $doc['spec']['hashedRekordV002']['signature']['verifier']['publicKey']['rawBytes'] ?? null;
		return is_string($raw) && in_array(base64_decode($raw, true), $keys, true);
	}

	/** Account for one entry under our key: ours if the ledger holds it, byte for byte. */
	private static function found(array $state, array $ledger, string $origin, int $index, string $leaf, int $now): array {
		$mine = $ledger[$origin . '#' . $index] ?? null;
		if ($mine !== null && hash_equals($mine['leaf'], $leaf)) {
			$state['seen'][] = $mine['id'];
			return $state;
		}
		foreach ($state['unaccounted'] as $u) {
			if ($u['origin'] === $origin && $u['index'] === $index) {
				return $state;
			}
		}
		$state['unaccounted'][] = array(
			'origin'      => $origin,
			'index'       => $index,
			'why'         => $mine === null ? 'no publish here recorded it' : 'it differs from what the publish here recorded at that index',
			'leaf_sha256' => hash('sha256', $leaf),
			'found_at'    => $now,
		);
		return $state;
	}

	private static function firstLedgerIndex(array $ledger, string $origin): ?int {
		$first = null;
		foreach (array_keys($ledger) as $key) {
			list($o, $i) = explode('#', $key, 2);
			if ($o === $origin && ($first === null || (int)$i < $first)) {
				$first = (int)$i;
			}
		}
		return $first;
	}

	/** Every entry this site logged: "origin#index" => {id, leaf bytes, log_keys: [[origin, DER]] it installs}. */
	public static function ledger(): array {
		$ledger = array();
		foreach (new MultiReleaseLogEntry(array()) as $row) {
			$doc = json_decode((string)$row->get('rle_statement'), true);
			$log_keys = array();
			try {
				foreach (ReleaseStatementPublisher::payloadOf(is_array($doc) ? $doc : array())['keys_installed']['log_keys'] ?? array() as $pair) {
					$log_keys[] = array((string)($pair['origin'] ?? ''), (string)base64_decode((string)($pair['key'] ?? ''), true));
				}
			} catch (\Throwable $e) {
				// A payload this site cannot read installs no key it can check; the entry still counts as ours.
			}
			$ledger[$row->get('rle_log_origin') . '#' . (int)$row->get('rle_log_index')] = array(
				'id'       => (int)$row->key,
				'leaf'     => (string)base64_decode((string)($doc['entry']['leaf'] ?? ''), true),
				'log_keys' => $log_keys,
			);
		}
		return $ledger;
	}

	/** Every log key any logged release installed: unique [origin, DER] pairs. */
	public static function installedLogKeys(array $ledger): array {
		$keys = array();
		foreach ($ledger as $entry) {
			foreach ($entry['log_keys'] ?? array() as $pair) {
				$keys[$pair[0] . '#' . hash('sha256', $pair[1])] = $pair;
			}
		}
		ksort($keys);
		return array_values($keys);
	}

	/** A key's short fingerprint, as the page and the incident show it. */
	public static function fingerprint(string $der): string {
		return substr(hash('sha256', $der), 0, 16);
	}

	/**
	 * Check installed log keys against Sigstore's trusted root. Pure: the
	 * root is fetched by the caller.
	 *
	 * @param array $keys [[origin, DER], ...]
	 */
	public static function checkRoot(array $state, array $trusted_root, array $keys, int $now): array {
		$unknown = array();
		foreach ($keys as $pair) {
			if (!ReleaseLogClient::rootListsKey($trusted_root, $pair[0], $pair[1])) {
				$unknown[] = array('origin' => $pair[0], 'fingerprint' => self::fingerprint($pair[1]));
			}
		}
		$state['root'] = array('checked_at' => $now, 'attempted_at' => $now, 'error' => null, 'checked' => count($keys), 'unknown' => $unknown);
		return $state;
	}

	/**
	 * Entries under our key that the ledger does not account for now. An
	 * entry the tail recorded and the ledger has since come to hold, leaf for
	 * leaf, is no longer one.
	 */
	public static function unaccounted(array $state, array $ledger): array {
		return array_values(array_filter($state['unaccounted'] ?? array(), function ($u) use ($ledger) {
			$mine = $ledger[$u['origin'] . '#' . $u['index']] ?? null;
			return $mine === null || !hash_equals(hash('sha256', $mine['leaf']), (string)$u['leaf_sha256']);
		}));
	}

	/**
	 * Why the tail cannot vouch for the logs, or null when it can: the task
	 * missing or off, or a log not read up to a settled checkpoint for a day.
	 * Null or ['title', 'severity', 'detail'] as IncidentSource wants.
	 *
	 * @param array|null $task {active, created} for the task row, null when there is none
	 */
	public static function blindCondition(array $state, ?array $task, array $log_origins, int $now): ?array {
		$watching = (int)($state['watching_since'] ?? ($task['created'] ?? $now));
		$since = $watching;
		if ($task === null || !$task['active']) {
			$why = 'The ' . self::TASK_CLASS . ' scheduled task ' . ($task === null ? 'does not exist' : 'is turned off') . ', so nothing reads the log.';
			$fix = 'Turn the Tail Release Log task on under Admin > System > Scheduled Tasks.';
		} else {
			$behind = array();
			foreach ($log_origins as $origin) {
				$last = $state['logs'][$origin]['caught_up_at'] ?? null;
				if ($now - ($last ?? $watching) > self::BEHIND_AFTER) {
					$behind[$origin] = $last;
				}
			}
			$root_at = isset($state['root']['checked_at']) ? (int)$state['root']['checked_at'] : null;
			$root_stale = $now - ($root_at ?? $watching) > self::ROOT_BLIND_AFTER;
			if (!$behind && !$root_stale) {
				return null;
			}
			$since = min(array_merge(array_map(function ($last) use ($watching) { return $last ?? $watching; }, $behind),
				$root_stale ? array($root_at ?? $watching) : array()));
			$names = array();
			foreach ($behind as $origin => $last) {
				$log = $state['logs'][$origin] ?? null;
				$names[] = $origin . ($log ? " (read to entry {$log['next']} of {$log['target']})" : ' (never read)')
					. ', last caught up ' . ($last ? gmdate('Y-m-d H:i', $last) . ' UTC' : 'never');
			}
			$why = ($names ? 'Not read up to date for a day: ' . implode('; ', $names) . '.' : '')
				. (($state['last_error'] ?? null) !== null ? ' The last run said: ' . $state['last_error'] : '')
				. ($root_stale ? ' The log keys releases install have not been checked against Sigstore\'s trusted root since '
					. ($root_at ? gmdate('Y-m-d H:i', $root_at) . ' UTC' : 'the reader started')
					. (($state['root']['error'] ?? null) !== null ? ': ' . $state['root']['error'] : '') . '.' : '');
			$why = trim($why);
			$fix = 'Check the Tail Release Log task\'s last run under Admin > System > Scheduled Tasks. If the log is reachable and still '
				. 'answers this way, its format has changed and LogTileReader needs updating.';
		}
		return array(
			'title'    => 'The release-log reader is not keeping up with the public log',
			'severity' => $now - $since > self::BLIND_CRITICAL_AFTER ? IncidentRecord::SEVERITY_CRITICAL : IncidentRecord::SEVERITY_WARNING,
			'detail'   => array(
				'Why'    => $why,
				'Fix'    => $fix,
				'Stakes' => 'While it is behind, an entry signed with our release key that no publish here wrote, or a log key '
					. 'Sigstore does not list, would go unnoticed.',
			),
		);
	}

	/** The logs the tail must read: those nodes trust, as run() reads them. */
	public static function logOrigins(): array {
		$origins = array_keys(ReleaseLogClient::repoLogKeys(PathHelper::getSiteRoot()));
		try {
			$origins = array_merge($origins, array_keys(ReleaseStatementPublisher::held(ReleaseStatementPublisher::previous())['log'] ?? array()));
		} catch (\Throwable $e) {
			// The run reports an unreadable release record; the repository's logs still count here.
		}
		$origins = array_values(array_unique($origins));
		sort($origins);
		return $origins;
	}

	/** The stored state, or an empty one. */
	public static function state(): array {
		$state = json_decode((string)Globalvars::get_instance()->get_setting(self::STATE_SETTING), true);
		return is_array($state) ? $state : array();
	}

	private static function save(array $state): void {
		Setting::put(self::STATE_SETTING, json_encode($state, JSON_UNESCAPED_SLASHES));
	}

	/** The task row: {active, created}, or null when there is none. */
	public static function taskRow(): ?array {
		foreach (new MultiScheduledTask(array('sct_task_class' => self::TASK_CLASS, 'deleted' => false)) as $task) {
			return array('active' => (bool)$task->get('sct_is_active'), 'created' => (int)strtotime((string)$task->get('sct_create_time') . ' UTC'));
		}
		return null;
	}
}
