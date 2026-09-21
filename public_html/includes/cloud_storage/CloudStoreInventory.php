<?php
/**
 * CloudStoreInventory — is every offloaded file still in the file store?
 *
 * Once a file's bytes are offloaded, the file bucket is the only place they
 * are served from, and nothing on this machine notices when the bucket loses
 * one until a visitor gets a 404. This asks the bucket, on a schedule: every
 * `cloud` blob is HEADed once a day (CloudStorageDriver::head — size and
 * ETag, or null), and the ones the bucket cannot serve are written down, by
 * name, so the cloud-storage page and the Backups page can say "N offloaded
 * files are missing from the file store; the backup holds M of them" and
 * offer to bring them back (BackupObjectRestoreLauncher on a site with its
 * own backup target; a management-node job for a site backed up only by its
 * management node).
 *
 * It runs inside the offload tick (CloudStorageLifecycle::runOffloadTick),
 * which stays active while any offloaded file exists — a paused store serves
 * the same files as an active one — and stops when none remains. One pass a day, taken a slice at a time: each tick checks rows for
 * TICK_BUDGET_SECONDS and then leaves a cursor, so a bucket of ten thousand
 * files is walked over a few ticks without holding the scheduler. Every
 * answer is bound by the store's own word: a tick asks the bucket to ping
 * first and checks nothing while it does not answer, and a HEAD that says
 * absent is asked again before the file is called missing — a moment's
 * outage must never read as ten thousand lost files.
 *
 * The record is one declared setting (SETTING), JSON: the pass in progress,
 * the last completed pass, and what the last Bring them back did. No schema.
 * It carries names, sizes and counts; never a key or a credential.
 *
 * @version 1.1 - one file store: one driver per tick, one "did not answer" state; a row carries no visibility
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriverFactory.php'));
require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));
require_once(PathHelper::getIncludePath('data/settings_class.php'));

class CloudStoreInventory {

	/** The declared setting the record lives in (settings.json, managed). */
	const SETTING = 'cloud_storage_inventory';

	const RECORD_VERSION = 1;

	/** A pass begins this long after the last one finished. */
	const EVERY_SECONDS = 86400;

	/** How long one tick spends asking the bucket before it leaves a cursor. */
	const TICK_BUDGET_SECONDS = 45;

	/** Rows loaded per query while walking the cursor. */
	const BATCH = 200;

	/** Why a file is on the missing list. */
	const REASON_ABSENT = 'absent';
	const REASON_SIZE   = 'size';

	/**
	 * Test seams. Keys:
	 *   rows    callable(int $after_id, int $limit): array   the cloud rows past a cursor, each
	 *           ['id','name','remote_key','size'], instead of fbb_file_blobs
	 *   driver  callable(): ?CloudStorageDriver   the file store's driver, instead of the factory
	 *   record  array|null   the record, held here instead of the setting (array_key_exists decides)
	 */
	public static $test_hooks = array();

	/** The record as last read or written in this process (Globalvars caches a non-blank value). */
	private static $record = null;

	// --------------------------------------------------------------- record

	public static function blank_record() {
		return array('version' => self::RECORD_VERSION, 'pass' => null, 'last' => null, 'bring_back' => null);
	}

	/** The record: the setting decoded, or a blank one. */
	public static function read() {
		if (array_key_exists('record', self::$test_hooks)) {
			return (is_array(self::$test_hooks['record']) ? self::$test_hooks['record'] : array()) + self::blank_record();
		}
		if (self::$record !== null) {
			return self::$record;
		}
		$raw = (string)Globalvars::get_instance()->get_setting(self::SETTING, true, true);
		$data = $raw !== '' ? json_decode($raw, true) : null;
		if (!is_array($data) || (int)($data['version'] ?? 0) !== self::RECORD_VERSION) {
			$data = self::blank_record();
		}
		return self::$record = $data + self::blank_record();
	}

	public static function write(array $record) {
		$record['version'] = self::RECORD_VERSION;
		if (array_key_exists('record', self::$test_hooks)) {
			self::$test_hooks['record'] = $record;
			return;
		}
		self::$record = $record;
		Setting::put(self::SETTING, json_encode($record));
	}

	/** Tests only: forget the in-process copy so the next read() goes to the setting. */
	public static function reset_for_tests() {
		self::$record = null;
	}

	// ----------------------------------------------------------------- tick

	/** Is a new pass due? Pure. */
	public static function due(array $record, $now_ts) {
		if (is_array($record['pass'] ?? null)) {
			return true;   // a pass in progress is always continued
		}
		$last = $record['last'] ?? null;
		if (!is_array($last) || empty($last['finished'])) {
			return true;
		}
		$finished = strtotime((string)$last['finished'] . ' UTC');
		return $finished === false || ((int)$now_ts - $finished) >= self::EVERY_SECONDS;
	}

	/**
	 * One tick's worth of the inventory. Starts a pass when one is due,
	 * continues the one in progress, finishes it when the rows run out.
	 *
	 * @param string|null $now        UTC 'Y-m-d H:i:s' (tests); the clock otherwise
	 * @param int         $budget     seconds of HEADs this tick may spend
	 * @param int         $max_rows   rows this tick may check (tests)
	 * @return array ['status' => 'idle'|'running'|'finished'|'waiting', 'message' => string, 'checked' => n]
	 */
	public static function tick($now = null, $budget = self::TICK_BUDGET_SECONDS, $max_rows = PHP_INT_MAX) {
		$now_ts = $now !== null ? strtotime($now . ' UTC') : time();
		$stamp  = gmdate('Y-m-d H:i:s', $now_ts);
		$record = self::read();
		if (!self::due($record, $now_ts)) {
			return array('status' => 'idle', 'message' => '', 'checked' => 0);
		}
		$pass = $record['pass'];
		if (!is_array($pass)) {
			$pass = array('started' => $stamp, 'cursor' => 0, 'checked' => 0, 'missing' => array(), 'unchecked' => 0);
		}
		$pass['missing'] = is_array($pass['missing'] ?? null) ? $pass['missing'] : array();

		$started_at = microtime(true);
		$checked_this_tick = 0;
		$driver = false;   // false: not yet asked; null: no store configured; a driver: answered its ping
		$stalled = false;  // the store did not answer its ping this tick

		$exhausted = false;
		while (!$exhausted && !$stalled) {
			$rows = self::rows((int)$pass['cursor'], self::BATCH);
			if (!$rows) {
				$exhausted = true;
				break;
			}
			foreach ($rows as $row) {
				if ($checked_this_tick >= $max_rows || (microtime(true) - $started_at) >= $budget) {
					break 2;
				}
				if ($driver === false) {
					$driver = self::driver();
					if ($driver !== null) {
						try {
							$ping = $driver->ping();
							if (empty($ping['ok'])) { $stalled = true; }
						} catch (\Throwable $e) {
							$stalled = true;
						}
					}
				}
				if ($stalled) {
					// The store did not answer its ping this tick: nothing is
					// called missing on its word; the cursor stays here.
					break 2;
				}
				$name = (string)$row['name'];
				if ($driver === null) {
					$pass['unchecked']++;
				} else {
					$reason = self::check($driver, $row);
					if ($reason !== null) {
						$pass['missing'][$name] = array(
							'id' => (int)$row['id'], 'size' => (int)($row['size'] ?? 0), 'reason' => $reason,
						);
					} else {
						unset($pass['missing'][$name]);
					}
					$pass['checked']++;
				}
				$pass['cursor'] = (int)$row['id'];
				$checked_this_tick++;
			}
			if (count($rows) < self::BATCH) {
				$exhausted = true;
			}
		}

		if ($exhausted) {
			$record['last'] = array(
				'started'   => $pass['started'],
				'finished'  => $stamp,
				'checked'   => (int)$pass['checked'],
				'unchecked' => (int)$pass['unchecked'],
				'missing'   => $pass['missing'],
			);
			$record['pass'] = null;
			self::write($record);
			$n = count($pass['missing']);
			return array('status' => 'finished', 'checked' => $checked_this_tick,
				'message' => 'file store check finished: ' . number_format((int)$pass['checked']) . ' offloaded file'
					. ((int)$pass['checked'] === 1 ? '' : 's') . ' checked, ' . ($n === 0 ? 'none missing' : number_format($n) . ' missing')
					. ($pass['unchecked'] > 0 ? ', ' . number_format((int)$pass['unchecked']) . ' not checked (no store configured)' : ''));
		}

		$record['pass'] = $pass;
		self::write($record);
		if ($stalled) {
			return array('status' => 'waiting', 'checked' => $checked_this_tick,
				'message' => 'file store check paused: the file store did not answer; it resumes next tick');
		}
		return array('status' => 'running', 'checked' => $checked_this_tick,
			'message' => 'file store check: ' . number_format((int)$pass['checked']) . ' offloaded files checked so far'
				. (count($pass['missing']) ? ', ' . number_format(count($pass['missing'])) . ' missing' : ''));
	}

	/**
	 * One file against the bucket. Null when the bucket serves it at its
	 * recorded size; otherwise why not. An absent answer is asked once more
	 * before it counts — head() also answers null when the bucket did not
	 * answer at all.
	 */
	public static function check(CloudStorageDriver $driver, array $row) {
		$key  = (string)$row['remote_key'];
		$head = $driver->head($key);
		if ($head === null) {
			$head = $driver->head($key);
		}
		if ($head === null) {
			return self::REASON_ABSENT;
		}
		$size = (int)($row['size'] ?? 0);
		if ($size > 0 && (int)($head['size'] ?? -1) !== $size) {
			return self::REASON_SIZE;
		}
		return null;
	}

	/** The cloud rows past a cursor, in id order. */
	private static function rows($after_id, $limit) {
		if (isset(self::$test_hooks['rows'])) {
			return (array)call_user_func(self::$test_hooks['rows'], (int)$after_id, (int)$limit);
		}
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("SELECT fbb_file_blob_id FROM fbb_file_blobs WHERE fbb_storage_driver = 'cloud'"
			. ' AND fbb_file_blob_id > ? ORDER BY fbb_file_blob_id ASC LIMIT ' . (int)$limit);
		$q->execute(array((int)$after_id));
		$out = array();
		foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
			$blob = new FileBlob((int)$id, true);
			if (!$blob->key || (string)$blob->get('fbb_storage_driver') !== 'cloud') {
				continue;
			}
			$out[] = array(
				'id'         => (int)$blob->key,
				'name'       => (string)$blob->get('fbb_stored_name'),
				'remote_key' => $blob->remote_key_for('original'),
				'size'       => (int)$blob->get('fbb_size_bytes'),
			);
		}
		return $out;
	}

	private static function driver() {
		if (isset(self::$test_hooks['driver'])) {
			return call_user_func(self::$test_hooks['driver']);
		}
		return CloudStorageDriverFactory::driverWithFallback();
	}

	// ---------------------------------------------------------- bring back

	/** The launcher's mark: what Bring them back is doing or last did. Merged into the record. */
	public static function note_bring_back(array $fields) {
		$record = self::read();
		$current = is_array($record['bring_back'] ?? null) ? $record['bring_back'] : array();
		$record['bring_back'] = array_merge($current, $fields);
		self::write($record);
	}

	/** Names brought home are no longer missing; the last pass says so without waiting a day. */
	public static function forget_missing(array $names) {
		if (!$names) {
			return;
		}
		$record = self::read();
		foreach (array('last', 'pass') as $slot) {
			if (!is_array($record[$slot] ?? null) || !is_array($record[$slot]['missing'] ?? null)) { continue; }
			foreach ($names as $name) { unset($record[$slot]['missing'][(string)$name]); }
		}
		self::write($record);
	}

	// -------------------------------------------------------------- summary

	/**
	 * What a page says. Pure over the record and the held sets.
	 *
	 * @param array $record   read()
	 * @param array $held     profile => (name => entry) | null — BackupObjects::held_sets() of the enabled profiles
	 * @return array ['checked_at' => string|null, 'checked' => n, 'unchecked' => n, 'missing' => name => entry,
	 *                'missing_count' => n, 'held' => n (of the missing, in backup storage; null when no
	 *                profile has a held set to say), 'running' => bool,
	 *                'running_since' => string|null, 'running_checked' => n, 'bring_back' => array|null]
	 */
	public static function summary(array $record, array $held) {
		$last = is_array($record['last'] ?? null) ? $record['last'] : null;
		$pass = is_array($record['pass'] ?? null) ? $record['pass'] : null;
		$missing = is_array($last['missing'] ?? null) ? $last['missing'] : array();
		// How many of the missing a shelf holds. A profile with no held set has
		// had no run since the object store shipped, so it cannot say; when no
		// profile can say, the count is unknown (null) rather than zero.
		$knows = false;
		foreach ($held as $set) { if (is_array($set)) { $knows = true; } }
		$on_shelf = ($held && !$knows) ? null : 0;
		if ($knows) {
			foreach (array_keys($missing) as $name) {
				foreach ($held as $set) {
					if (is_array($set) && isset($set[$name])) { $on_shelf++; break; }
				}
			}
		}
		return array(
			'checked_at'      => $last ? (string)$last['finished'] : null,
			'checked'         => $last ? (int)$last['checked'] : 0,
			'unchecked'       => $last ? (int)($last['unchecked'] ?? 0) : 0,
			'missing'         => $missing,
			'missing_count'   => count($missing),
			'held'            => $on_shelf,
			'running'         => $pass !== null,
			'running_since'   => $pass ? (string)$pass['started'] : null,
			'running_checked' => $pass ? (int)$pass['checked'] : 0,
			'bring_back'      => is_array($record['bring_back'] ?? null) ? $record['bring_back'] : null,
		);
	}

	/**
	 * The summary for this site: the record, against what the enabled
	 * backup profiles' shelves hold. Never throws — a page must render.
	 */
	public static function current() {
		$held = array();
		try {
			$enabled = BackupProfile::enabled();
			if ($enabled) {
				$held = BackupObjects::held_sets($enabled, BackupRunner::output_dir());
			}
		} catch (\Throwable $e) {
			$held = array();
		}
		return self::summary(self::read(), $held);
	}

	/**
	 * The one sentence both pages show when files are missing. Pure.
	 * "3 offloaded files are missing from the file store; the backup holds 2 of them."
	 */
	public static function sentence(array $summary) {
		$n = (int)$summary['missing_count'];
		if ($n === 0) {
			return '';
		}
		$files = number_format($n) . ' offloaded file' . ($n === 1 ? ' is' : 's are') . ' missing from the file store; ';
		if ($summary['held'] === null) {
			return $files . 'whether the backup holds ' . ($n === 1 ? 'it' : 'them') . ' is known after its next run.';
		}
		$m = (int)$summary['held'];
		if ($m === 0) {
			return $files . 'no backup holds ' . ($n === 1 ? 'it' : 'any of them') . '.';
		}
		if ($m >= $n) {
			return $files . 'the backup holds ' . ($n === 1 ? 'it' : 'all of them') . '.';
		}
		return $files . 'the backup holds ' . number_format($m) . ' of them.';
	}
}
