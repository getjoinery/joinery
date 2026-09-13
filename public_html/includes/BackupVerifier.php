<?php
/**
 * BackupVerifier — proving a staged backup can be recovered without restoring it.
 *
 * A backup that has never been opened is a hope. This is the engine behind the
 * word "verified" on every page that shows a backup: given a chain already on
 * disk (its manifest, its artifacts and the recovered chain key), it opens the
 * backup and reads it to the end, and — when a person asks for it — replays it
 * into a scratch directory and a throwaway database, counts what came back,
 * and deletes both. Nothing on the live site is touched at any level.
 *
 * Two levels live here:
 *
 *   Level 2, "opened and read" (read_all): every artifact a restore of the run
 *   would apply is checked against the manifest's size and hash, then decrypted
 *   to a pipe with the chain key and read to the end — `tar -tz` for the files
 *   and meta archives, a full gunzip plus a header check for the database dump.
 *   Proves the set is decryptable and structurally sound with the key this
 *   machine holds.
 *
 *   Level 3, "rehearsed" (rehearse): level 2, then restore_chain.sh into a
 *   scratch tree under the working directory and restore_database.sh into a
 *   database this engine creates and drops. Proves the set is recoverable.
 *
 * Level 1, "checked on the shelf", is the management node's and lives in the
 * fleet backup pass; it needs no artifact on disk.
 *
 * No network. Fetching is BackupStaging's job, so this can be driven against a
 * chain an operator downloaded by hand (maintenance_scripts/sysadmin_tools/
 * verify_backup.sh) as well as against one the node staged for itself
 * (utils/verify_backup.php). Every function returns the result as an array in
 * the shape format_contract() prints; nothing here prints, and nothing here
 * accepts a key other than the file it is pointed at.
 *
 * @version 1.1 - stamp_history()/note_history() are the history stamp, here so the test can drive it:
 *                a skip or a refusal stamps the run's message only, so a verify a person started
 *                leaves a trace on the page whatever became of it; is_attempt_message() names one.
 *                run_command() collects a child's stderr in a file instead of a second pipe, so a
 *                child that writes more than a pipe buffer to stderr (restore_database.sh tags every
 *                command) no longer deadlocks a rehearsal until the agent kills it
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/BackupChain.php'));
require_once(PathHelper::getIncludePath('includes/BackupFetch.php'));
require_once(PathHelper::getIncludePath('data/backup_history_class.php'));

class BackupVerifierException extends Exception {}

class BackupVerifier {

	const LEVEL_SHELF    = 1;
	const LEVEL_READ     = 2;
	const LEVEL_REHEARSE = 3;

	/** How each level is named where a person reads. */
	const LEVEL_NAMES = array(
		self::LEVEL_SHELF    => 'checked on the shelf',
		self::LEVEL_READ     => 'opened and read',
		self::LEVEL_REHEARSE => 'rehearsed',
	);

	const RESULT_PASS    = 'pass';
	const RESULT_FAIL    = 'fail';
	const RESULT_SKIPPED = 'skipped';

	/** How much of the decompressed dump is inspected for its header. */
	const DUMP_HEAD_BYTES = 65536;

	/** Prefix of a verify's working directory under a profile's backup directory. */
	const WORK_PREFIX = 'verify-';

	/** Prefix of the throwaway database a rehearsal loads. */
	const DB_PREFIX = 'verify_';

	/** The tables a rehearsal counts rows in, before the largest ones. */
	const NAMED_TABLES = array('usr_users');

	/** How many of the largest tables a rehearsal counts rows in. */
	const LARGEST_TABLES = 3;

	/** Ceiling on one decrypt-and-read of one artifact. */
	const READ_TIMEOUT = 3600;

	// ---------------------------------------------------------------- words

	/** The level's name for a person, or '' for a level that has none. */
	public static function level_name($level) {
		return self::LEVEL_NAMES[(int)$level] ?? '';
	}

	/** Is this a level this engine runs? Level 1 is the shelf check, elsewhere. */
	public static function is_runnable_level($level) {
		return in_array((int)$level, array(self::LEVEL_READ, self::LEVEL_REHEARSE), true);
	}

	// ----------------------------------------------------------------- disk

	/**
	 * Bytes a verify of this run needs free under the backup working area
	 * before anything is downloaded.
	 *
	 * Level 2 holds the set a restore of the run depends on, and nothing else.
	 * Level 3 holds that set, plus the replayed tree (taken as twice the
	 * recorded size of the full's files archive) and the loaded database (taken
	 * as three times the dump). The multipliers are deliberately generous:
	 * the number this defends is the disk of a machine that may already be
	 * tight, and the cost of being wrong in the other direction is a verify
	 * that fills it.
	 */
	public static function disk_needed(array $manifest, $seq, $level) {
		$plan = BackupChain::restore_plan($manifest, $seq);
		$set = 0;
		foreach ($plan['files'] as $a) { $set += (int)($a['bytes'] ?? 0); }
		foreach (array('db', 'meta') as $kind) {
			if (!empty($plan[$kind])) { $set += (int)($plan[$kind]['bytes'] ?? 0); }
		}
		if ((int)$level < self::LEVEL_REHEARSE) {
			return $set;
		}
		$full_files = (int)($plan['files'][0]['bytes'] ?? 0);
		$dump       = !empty($plan['db']) ? (int)($plan['db']['bytes'] ?? 0) : 0;
		return $set + ($full_files * 2) + ($dump * 3);
	}

	/**
	 * The refusal for a verify this machine cannot hold, or null when it can.
	 *
	 * Returned as a finished 'skipped' result so a caller prints it and stops:
	 * a skip is neither a pass nor a failure, and it says both numbers so the
	 * card can say "needs N free, has M".
	 */
	public static function disk_check(array $manifest, $seq, $level, $dir, $free = null) {
		$needed = self::disk_needed($manifest, $seq, $level);
		if ($free === null) {
			$free = @disk_free_space($dir);
		}
		if ($free === false || $free === null) {
			return null;   // unknowable here; BackupFetch checks again per artifact
		}
		if ((float)$free >= (float)$needed) {
			return null;
		}
		$result = self::blank($manifest, $seq, $level);
		$result['result']      = self::RESULT_SKIPPED;
		$result['reason']      = 'disk';
		$result['needs_bytes'] = (int)$needed;
		$result['free_bytes']  = (int)$free;
		return $result;
	}

	// -------------------------------------------------------------- level 2

	/**
	 * Open and read every artifact a restore of the run would apply.
	 *
	 * Each artifact is first checked against the manifest's recorded size and
	 * hash, then decrypted to a pipe with the chain key and read to its end.
	 * Any non-zero status, or a read that ends early, is a fail naming the
	 * artifact. Counts come back: artifacts read, bytes read, and the entries
	 * the files archives list (directories excluded, so the number reads like a
	 * file count).
	 *
	 * @param string $work     Directory holding manifest.json and the artifacts
	 * @param array  $manifest The decoded manifest
	 * @param int|null $seq    The run; null for the newest
	 * @param string $key_file The recovered chain data key
	 */
	public static function read_all($work, array $manifest, $seq, $key_file) {
		$started = microtime(true);
		$work = rtrim($work, '/');
		$level = self::LEVEL_READ;

		try {
			$plan = BackupChain::restore_plan($manifest, $seq);
		} catch (BackupChainException $e) {
			$result = self::blank($manifest, null, $level);
			return self::failed($result, $e->getMessage(), $started);
		}
		$result = self::blank($manifest, $plan['seq'], $level);

		if (!is_file($key_file) || !is_readable($key_file)) {
			return self::failed($result, 'the chain key at ' . basename((string)$key_file) . ' is not readable', $started);
		}

		// In restore order: the full, every incremental, then the run's dump
		// and its metadata. Same list a restore would apply, from the same code.
		$ordered = array();
		foreach ($plan['files'] as $a) { $ordered[] = array('kind' => 'files', 'entry' => $a); }
		if (!empty($plan['db']))   { $ordered[] = array('kind' => 'db',   'entry' => $plan['db']); }
		if (!empty($plan['meta'])) { $ordered[] = array('kind' => 'meta', 'entry' => $plan['meta']); }

		$root = '';
		foreach ($ordered as $item) {
			$entry = $item['entry'];
			$name  = (string)($entry['name'] ?? '');
			$path  = $work . '/' . $name;

			// Size and hash against the manifest, before a byte is decrypted. A
			// truncated or substituted artifact is caught here by name.
			try {
				BackupChain::verify_artifact($path, $entry);
			} catch (BackupChainException $e) {
				return self::failed($result, $e->getMessage(), $started);
			}

			if ($item['kind'] === 'db') {
				$read = self::read_dump($path, $key_file);
			} else {
				$read = self::list_archive($path, $key_file);
			}
			if (!$read['ok']) {
				return self::failed($result, $name . ': ' . $read['error'], $started);
			}

			$result['artifacts']++;
			$result['bytes'] += (int)@filesize($path);
			if ($item['kind'] === 'files') {
				$result['files'] += (int)$read['entries'];
				if ($root === '' && $read['root'] !== '') {
					$root = $read['root'];
				}
			}
		}

		$result['result']       = self::RESULT_PASS;
		$result['archive_root'] = $root;
		$result['duration']     = self::elapsed($started);
		return $result;
	}

	// -------------------------------------------------------------- level 3

	/**
	 * Level 2, then a rehearsal: replay the files into a scratch tree under
	 * $work, load the dump into a throwaway database, count what came back,
	 * and remove both. The scratch tree is removed here on every path; the
	 * database is dropped here on every path this function returns through,
	 * and drop_database() is public so a shutdown handler can finish the job
	 * after a fatal.
	 *
	 * $db names how to reach PostgreSQL: ['user', 'password', 'connect_db',
	 * 'host', 'port']. connect_db is an existing database the user may
	 * connect to in order to run CREATE DATABASE (the site's own is fine).
	 * The password crosses to the restore engine in its environment, never in
	 * argv.
	 *
	 * $project, when given, names the directory the tree is replayed as; it must
	 * be the name the archive carries (restore_chain.sh refuses any other), and
	 * left blank it is read from the archive.
	 *
	 * @return array the result; 'db_name' carries the throwaway database's name
	 */
	public static function rehearse($work, array $manifest, $seq, $key_file, array $db, $project = '') {
		$started = microtime(true);
		$work = rtrim($work, '/');

		$result = self::read_all($work, $manifest, $seq, $key_file);
		$result['level'] = self::LEVEL_REHEARSE;
		if ($result['result'] !== self::RESULT_PASS) {
			$result['duration'] = self::elapsed($started);
			return $result;
		}
		$seq  = (int)$result['seq'];
		$plan = BackupChain::restore_plan($manifest, $seq);

		$root = trim((string)$project) !== '' ? basename(trim((string)$project)) : (string)($result['archive_root'] ?? '');
		if ($root === '') {
			return self::failed($result, 'the files archive lists no top-level directory to replay into', $started);
		}

		// ── The tree ────────────────────────────────────────────────────
		$scratch = $work . '/scratch';
		$target  = $scratch . '/' . $root;
		self::remove_tree($scratch);
		if (!@mkdir($scratch, 0700, true)) {
			return self::failed($result, 'could not make the scratch directory at ' . $scratch, $started);
		}

		$tools = rtrim(PathHelper::getSiteRoot(), '/') . '/maintenance_scripts/sysadmin_tools';
		$cmd = 'bash ' . escapeshellarg($tools . '/restore_chain.sh')
			. ' ' . escapeshellarg($root)
			. ' --artifacts ' . escapeshellarg($work)
			. ' --key-file ' . escapeshellarg($key_file)
			. ' --seq ' . (int)$seq
			. ' --target-dir ' . escapeshellarg($target)
			. ' --skip-database --skip-reconcile --force';
		$run = self::run_command($cmd, array());
		if ($run['rc'] !== 0 || strpos($run['output'], 'RESTORE_OK') === false) {
			self::remove_tree($scratch);
			return self::failed($result, 'replaying the files into scratch failed: ' . self::tail($run['output']), $started);
		}

		$counted = self::count_tree($target);
		$result['files'] = $counted['files'];
		$result['restored_bytes'] = $counted['bytes'];
		self::remove_tree($scratch);

		// ── The database ────────────────────────────────────────────────
		if (empty($plan['db']['name'])) {
			// A files-only run has no dump to load; the rehearsal is the tree.
			$result['tables'] = 0;
			$result['rows']   = array();
			$result['result'] = self::RESULT_PASS;
			$result['duration'] = self::elapsed($started);
			return $result;
		}

		$db_name = self::throwaway_db_name((string)($manifest['slug'] ?? 'site'));
		$result['db_name'] = $db_name;
		try {
			$admin = self::connect($db, (string)($db['connect_db'] ?? 'postgres'));
			$admin->exec('DROP DATABASE IF EXISTS ' . self::quote_ident($db_name));
			$admin->exec('CREATE DATABASE ' . self::quote_ident($db_name) . ' TEMPLATE template0');
			$admin = null;
		} catch (\Throwable $e) {
			$result['result']   = self::RESULT_SKIPPED;
			$result['reason']   = 'createdb';
			$result['duration'] = self::elapsed($started);
			return $result;
		}

		try {
			$cmd = 'bash ' . escapeshellarg($tools . '/restore_database.sh')
				. ' ' . escapeshellarg($db_name)
				. ' ' . escapeshellarg($work . '/' . $plan['db']['name'])
				. ' --non-interactive'
				. ' --key-file ' . escapeshellarg($key_file)
				. ' --db-user ' . escapeshellarg((string)($db['user'] ?? 'postgres'));
			$env = array('PGPASSWORD' => (string)($db['password'] ?? ''));
			if (!empty($db['host'])) { $env['PGHOST'] = (string)$db['host']; }
			if (!empty($db['port'])) { $env['PGPORT'] = (string)$db['port']; }
			$run = self::run_command($cmd, $env);
			if ($run['rc'] !== 0 || !preg_match('/^RESTORE_OK$/m', $run['output'])) {
				$marker = preg_match('/^([A-Z_]+)$/m', $run['output'], $m) ? $m[1] : 'no marker';
				return self::failed($result, 'loading the database dump failed (' . $marker . '): '
					. self::tail($run['output']), $started);
			}

			$counts = self::count_database($db, $db_name);
			$result['tables'] = $counts['tables'];
			$result['rows']   = $counts['rows'];
		} catch (\Throwable $e) {
			return self::failed($result, 'counting the rehearsed database failed: ' . $e->getMessage(), $started);
		} finally {
			self::drop_database($db, $db_name);
		}

		$result['result']   = self::RESULT_PASS;
		$result['duration'] = self::elapsed($started);
		return $result;
	}

	/**
	 * The throwaway database's name: verify_<slug>_<pid>, made safe as an
	 * identifier and kept inside PostgreSQL's 63-byte limit.
	 */
	public static function throwaway_db_name($slug, $pid = null) {
		$pid  = ($pid === null) ? getmypid() : (int)$pid;
		$slug = strtolower(preg_replace('/[^A-Za-z0-9_]+/', '_', (string)$slug));
		$slug = trim($slug, '_');
		if ($slug === '') { $slug = 'site'; }
		$suffix = '_' . $pid;
		$room = 63 - strlen(self::DB_PREFIX) - strlen($suffix);
		return self::DB_PREFIX . substr($slug, 0, max(1, $room)) . $suffix;
	}

	/**
	 * Drop the throwaway database, ending anything still connected to it.
	 * Best effort and idempotent: called on the way out of every rehearsal and
	 * again from a shutdown handler.
	 */
	public static function drop_database(array $db, $db_name) {
		if ((string)$db_name === '' || strpos((string)$db_name, self::DB_PREFIX) !== 0) {
			return false;   // never drop anything a rehearsal did not name
		}
		try {
			$admin = self::connect($db, (string)($db['connect_db'] ?? 'postgres'));
			$q = $admin->prepare('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()');
			$q->execute(array($db_name));
			$admin->exec('DROP DATABASE IF EXISTS ' . self::quote_ident($db_name));
			return true;
		} catch (\Throwable $e) {
			error_log('BackupVerifier: could not drop ' . $db_name . ': ' . $e->getMessage());
			return false;
		}
	}

	// ------------------------------------------------------------- contract

	/**
	 * The result as the script prints it: one key per line, VERIFY_ prefixed.
	 * Keys that do not apply are left out, as the contract says.
	 */
	public static function format_contract(array $r) {
		$lines = array();
		$lines[] = 'VERIFY_RESULT=' . ($r['result'] ?? self::RESULT_FAIL);
		$lines[] = 'VERIFY_LEVEL=' . (int)($r['level'] ?? 0);
		$lines[] = 'VERIFY_RUN=' . (string)($r['run'] ?? '');
		$lines[] = 'VERIFY_RUN_TIME=' . (string)($r['run_time'] ?? '');
		$lines[] = 'VERIFY_ARTIFACTS=' . (int)($r['artifacts'] ?? 0);
		$lines[] = 'VERIFY_BYTES=' . (int)($r['bytes'] ?? 0);
		$lines[] = 'VERIFY_FILES=' . (int)($r['files'] ?? 0);
		if ((int)($r['level'] ?? 0) === self::LEVEL_REHEARSE && isset($r['tables'])) {
			$lines[] = 'VERIFY_TABLES=' . (int)$r['tables'];
			$rows = array();
			foreach ((array)($r['rows'] ?? array()) as $table => $n) {
				$rows[] = $table . ':' . (int)$n;
			}
			$lines[] = 'VERIFY_ROWS=' . implode(',', $rows);
		}
		$lines[] = 'VERIFY_DURATION=' . (int)($r['duration'] ?? 0);
		if (($r['result'] ?? '') !== self::RESULT_PASS) {
			$lines[] = 'VERIFY_REASON=' . str_replace(array("\r", "\n"), ' ', (string)($r['reason'] ?? ''));
		}
		if (($r['result'] ?? '') === self::RESULT_SKIPPED && ($r['reason'] ?? '') === 'disk') {
			$lines[] = 'VERIFY_NEEDS_BYTES=' . (int)($r['needs_bytes'] ?? 0);
			$lines[] = 'VERIFY_FREE_BYTES=' . (int)($r['free_bytes'] ?? 0);
		}
		return implode("\n", $lines) . "\n";
	}

	/**
	 * The contract read back from a script's stdout (or a job transcript).
	 * Returns the same array shape read_all()/rehearse() produce; keys absent
	 * from the text are absent from the array, except 'result', which is
	 * 'fail' when the text carries no VERIFY_RESULT line at all.
	 */
	public static function parse_contract($text) {
		$out = array();
		if (!preg_match_all('/^VERIFY_([A-Z_]+)=(.*)$/m', (string)$text, $all, PREG_SET_ORDER)) {
			return array('result' => self::RESULT_FAIL, 'reason' => 'the verify reported no result');
		}
		foreach ($all as $m) {
			$key = strtolower($m[1]);
			$val = trim($m[2]);
			switch ($key) {
				case 'level': case 'artifacts': case 'bytes': case 'files': case 'tables':
				case 'duration': case 'needs_bytes': case 'free_bytes':
					$out[$key] = (int)$val;
					break;
				case 'rows':
					$rows = array();
					foreach (array_filter(explode(',', $val)) as $pair) {
						$bits = explode(':', $pair, 2);
						if (count($bits) === 2 && $bits[0] !== '') { $rows[$bits[0]] = (int)$bits[1]; }
					}
					$out['rows'] = $rows;
					break;
				default:
					$out[$key] = $val;
			}
		}
		if (!in_array($out['result'] ?? '', array(self::RESULT_PASS, self::RESULT_FAIL, self::RESULT_SKIPPED), true)) {
			$out['result'] = self::RESULT_FAIL;
			if (empty($out['reason'])) { $out['reason'] = 'the verify reported no result'; }
		}
		return $out;
	}

	/**
	 * The result in plain words, for a job record, a task message or a card:
	 * "Opened and read the backup of 2026-09-13 04:45 UTC: 3 archives, 717 MB,
	 * 1,842 files".
	 */
	public static function describe(array $r) {
		$level  = (int)($r['level'] ?? 0);
		$when   = self::when_words((string)($r['run_time'] ?? ''));
		$result = (string)($r['result'] ?? self::RESULT_FAIL);
		$verb   = ($level === self::LEVEL_REHEARSE) ? 'Rehearsed a restore of' : 'Opened and read';

		if ($result === self::RESULT_SKIPPED) {
			$reason = (string)($r['reason'] ?? '');
			if ($reason === 'disk') {
				return 'Could not verify the backup of ' . $when . ': needs '
					. BackupFetch::human((int)($r['needs_bytes'] ?? 0)) . ' free, has '
					. BackupFetch::human((int)($r['free_bytes'] ?? 0)) . '.';
			}
			if ($reason === 'createdb') {
				return 'Could not rehearse the backup of ' . $when
					. ': the files replayed, but a throwaway database could not be created on this machine.';
			}
			return 'Could not verify the backup of ' . $when . ': ' . $reason;
		}
		if ($result !== self::RESULT_PASS) {
			return 'Verification of the backup of ' . $when . ' failed: ' . (string)($r['reason'] ?? 'no reason given');
		}

		$parts = array();
		$n = (int)($r['artifacts'] ?? 0);
		$parts[] = $n . ' archive' . ($n === 1 ? '' : 's');
		$parts[] = BackupFetch::human((int)($r['bytes'] ?? 0));
		$parts[] = number_format((int)($r['files'] ?? 0)) . ' files';
		if ($level === self::LEVEL_REHEARSE) {
			$parts[] = number_format((int)($r['tables'] ?? 0)) . ' tables';
			$rows = (array)($r['rows'] ?? array());
			if (isset($rows['usr_users'])) {
				$parts[] = number_format((int)$rows['usr_users']) . ' users';
			}
		}
		return $verb . ' the backup of ' . $when . ': ' . implode(', ', $parts) . '.';
	}

	/**
	 * Whether a verify message records an attempt that proved nothing either
	 * way — a skip, or a request refused before anything was read — as
	 * describe() and note_history() word one. The plane and the site both
	 * keep such a message beside the last real result, which stands.
	 */
	public static function is_attempt_message($message) {
		return strpos((string)$message, 'Could not ') === 0;
	}

	/**
	 * Stamp a result on this machine's own history row for the run, if it
	 * has one. The node's history is the authority for "verified".
	 *
	 * A pass or a failure stamps the time, the level, the outcome and the
	 * message. A skip stamps the MESSAGE ONLY: nothing was proven either way,
	 * so the last real result stands, and the skip's reason rides beside it
	 * where the page shows the run — a verify a person started must leave a
	 * trace whatever became of it.
	 *
	 * @return int rows stamped (0 when this machine has no row for the run)
	 */
	public static function stamp_history(array $result, $chain_id, $seq, $profile = null) {
		$outcome = (string)($result['result'] ?? '');
		if (!in_array($outcome, array(self::RESULT_PASS, self::RESULT_FAIL, self::RESULT_SKIPPED), true)) {
			return 0;
		}
		$stamped = 0;
		foreach (self::history_rows($chain_id, $seq, $profile) as $row) {
			if (empty($result['run_time'])) {
				$result['run_time'] = (string)$row->get('bkh_start_time');
			}
			if ($outcome === self::RESULT_SKIPPED) {
				$row->set('bkh_verify_message', substr(self::describe($result), 0, 4000));
			} else {
				$row->set('bkh_verify_time', gmdate('Y-m-d H:i:s'));
				$row->set('bkh_verify_level', (int)($result['level'] ?? 0));
				$row->set('bkh_verify_outcome', $outcome);
				$row->set('bkh_verify_message', substr(self::describe($result), 0, 4000));
			}
			$row->save();
			$stamped++;
		}
		return $stamped;
	}

	/**
	 * Record on the run's row that a verify could not start — the request was
	 * refused before anything was read. Message only, like a skip.
	 *
	 * @return int rows stamped
	 */
	public static function note_history($chain_id, $seq, $profile, $message) {
		$stamped = 0;
		foreach (self::history_rows($chain_id, $seq, $profile) as $row) {
			$row->set('bkh_verify_message', substr('Could not verify the backup of '
				. self::when_words((string)$row->get('bkh_start_time')) . ': ' . trim((string)$message), 0, 4000));
			$row->save();
			$stamped++;
		}
		return $stamped;
	}

	/** This machine's history row for one run of a chain: at most one. */
	private static function history_rows($chain_id, $seq, $profile = null) {
		$options = array('chain_id' => (string)$chain_id, 'bkh_chain_seq' => (int)$seq, 'deleted' => false);
		if ($profile !== null) { $options['profile'] = $profile; }
		$rows = new MultiBackupHistory($options, array('bkh_start_time' => 'DESC'), 1, 0);
		$out = array();
		foreach ($rows as $row) { $out[] = $row; }
		return $out;
	}

	/** "2026-09-13 04:45 UTC" from a stored UTC time, or "an unknown time". */
	public static function when_words($utc) {
		$t = strtotime((string)$utc . ' UTC');
		return $t ? gmdate('Y-m-d H:i', $t) . ' UTC' : 'an unknown time';
	}

	// ------------------------------------------------------------ internals

	/**
	 * When the run was taken, as the manifest records it, in the platform's
	 * stored-time form (UTC, Y-m-d H:i:s). '' when the manifest does not say.
	 */
	public static function run_time(array $manifest, $seq) {
		$runs = $manifest['runs'] ?? array();
		if ($seq === null && $runs) { $seq = count($runs) - 1; }
		$time = ($seq !== null && isset($runs[$seq]['time'])) ? (string)$runs[$seq]['time'] : '';
		$t = $time !== '' ? strtotime($time) : false;
		return $t ? gmdate('Y-m-d H:i:s', $t) : '';
	}

	/** A result with every counter at zero, before anything has been read. */
	private static function blank(array $manifest, $seq, $level) {
		$runs = $manifest['runs'] ?? array();
		if ($seq === null && $runs) { $seq = count($runs) - 1; }
		return array(
			'result'    => self::RESULT_FAIL,
			'level'     => (int)$level,
			'seq'       => ($seq === null) ? null : (int)$seq,
			'run'       => (string)($manifest['chain_id'] ?? '') . '/' . ($seq === null ? '' : (int)$seq),
			'run_time'  => self::run_time($manifest, $seq),
			'artifacts' => 0,
			'bytes'     => 0,
			'files'     => 0,
			'duration'  => 0,
			'reason'    => '',
		);
	}

	private static function failed(array $result, $reason, $started) {
		$result['result']   = self::RESULT_FAIL;
		$result['reason']   = (string)$reason;
		$result['duration'] = self::elapsed($started);
		return $result;
	}

	private static function elapsed($started) {
		return (int)round(microtime(true) - (float)$started);
	}

	/**
	 * Decrypt a tar archive to a pipe and list it to the end. Returns
	 * ['ok', 'error', 'entries' (directories excluded), 'root' (first
	 * entry's top segment)].
	 */
	private static function list_archive($path, $key_file) {
		$pipeline = '( set -o pipefail; '
			. 'openssl enc -aes-256-cbc -d -pbkdf2 -pass fd:3 -in ' . escapeshellarg($path)
			. ' | tar -tz'
			. ' | awk \'NR==1{r=$0} !/\\/$/{n++} END{print n+0; print r}\''
			. ' ) 3< ' . escapeshellarg($key_file);
		$run = self::run_command($pipeline, array());
		if ($run['rc'] !== 0) {
			return array('ok' => false, 'error' => 'could not be decrypted and read to the end with the chain key ('
				. self::tail($run['output']) . ')', 'entries' => 0, 'root' => '');
		}
		$lines = explode("\n", trim($run['output']));
		$entries = (int)($lines[0] ?? 0);
		$first = trim((string)($lines[1] ?? ''));
		$root = $first !== '' ? explode('/', ltrim($first, './'))[0] : '';
		return array('ok' => true, 'error' => '', 'entries' => $entries, 'root' => $root);
	}

	/**
	 * Decrypt the database dump to a pipe, decompress it to the end (gunzip
	 * checks the CRC, which is what `gzip -t` checks) and look at its head:
	 * a plain dump opens with "PostgreSQL database dump"; a custom-format one
	 * with PGDMP, in which case pg_restore --list must be able to read it.
	 */
	private static function read_dump($path, $key_file) {
		$head = tempnam(sys_get_temp_dir(), 'jy_verify_head_');
		@chmod($head, 0600);
		$pipeline = '( set -o pipefail; '
			. 'openssl enc -aes-256-cbc -d -pbkdf2 -pass fd:3 -in ' . escapeshellarg($path)
			. ' | gunzip -c'
			. ' | { head -c ' . (int)self::DUMP_HEAD_BYTES . ' > ' . escapeshellarg($head) . '; cat > /dev/null; }'
			. ' ) 3< ' . escapeshellarg($key_file);
		$run = self::run_command($pipeline, array());
		$sample = (string)@file_get_contents($head);
		@unlink($head);
		if ($run['rc'] !== 0) {
			return array('ok' => false, 'error' => 'could not be decrypted and decompressed to the end with the chain key ('
				. self::tail($run['output']) . ')');
		}
		$custom = (strpos($sample, 'PGDMP') === 0);
		if (!$custom && strpos($sample, 'PostgreSQL database dump') === false) {
			return array('ok' => false, 'error' => 'decrypts and decompresses, but does not look like a PostgreSQL dump');
		}
		if ($custom) {
			$pipeline = '( set -o pipefail; '
				. 'openssl enc -aes-256-cbc -d -pbkdf2 -pass fd:3 -in ' . escapeshellarg($path)
				. ' | gunzip -c | pg_restore --list > /dev/null'
				. ' ) 3< ' . escapeshellarg($key_file);
			$run = self::run_command($pipeline, array());
			if ($run['rc'] !== 0) {
				return array('ok' => false, 'error' => 'is a custom-format dump pg_restore cannot list ('
					. self::tail($run['output']) . ')');
			}
		}
		return array('ok' => true, 'error' => '');
	}

	/**
	 * Run one bash command with an environment of its own, output combined
	 * (stdout, then stderr). The environment is how a password reaches a
	 * child: it is not in argv, so it is not in `ps`.
	 *
	 * stderr goes to a file of its own rather than a second pipe. Two pipes
	 * read one after the other deadlock as soon as the child fills the one
	 * not being read: restore_database.sh writes every command tag to stderr,
	 * so a rehearsal with a chatty dump would hang against a full 64 KB pipe
	 * until the agent's timeout killed it — and a kill skips the shutdown
	 * handler that drops the throwaway database.
	 *
	 * Public so the test can prove that against a deliberately chatty child.
	 */
	public static function run_command($cmd, array $env) {
		$err_file = tempnam(sys_get_temp_dir(), 'jy_verify_err_');
		@chmod($err_file, 0600);
		$descriptors = array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('file', $err_file, 'w'));
		$full_env = array_merge(self::inherited_env(), $env);
		$proc = @proc_open('bash -c ' . escapeshellarg($cmd), $descriptors, $pipes, null, $full_env);
		if (!is_resource($proc)) {
			@unlink($err_file);
			return array('rc' => 127, 'output' => 'could not start bash');
		}
		$out = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		$rc = proc_close($proc);
		$err = (string)@file_get_contents($err_file);
		@unlink($err_file);
		return array('rc' => (int)$rc, 'output' => rtrim((string)$out . "\n" . $err));
	}

	/** The environment a child inherits: PATH and the locale, nothing secret. */
	private static function inherited_env() {
		$keep = array();
		foreach (array('PATH', 'LANG', 'LC_ALL', 'HOME', 'TMPDIR') as $k) {
			$v = getenv($k);
			if ($v !== false) { $keep[$k] = $v; }
		}
		if (!isset($keep['PATH'])) {
			$keep['PATH'] = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
		}
		return $keep;
	}

	private static function connect(array $db, $dbname) {
		$dsn = 'pgsql:host=' . (string)($db['host'] ?? 'localhost')
			. ' port=' . (int)($db['port'] ?? 5432)
			. ' dbname=' . $dbname;
		$pdo = new PDO($dsn, (string)($db['user'] ?? 'postgres'), (string)($db['password'] ?? ''));
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		return $pdo;
	}

	private static function quote_ident($name) {
		return '"' . str_replace('"', '""', (string)$name) . '"';
	}

	/** Table and row counts from the rehearsed database. */
	private static function count_database(array $db, $db_name) {
		$pdo = self::connect($db, $db_name);
		$tables = (int)$pdo->query(
			"SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public'")->fetchColumn();

		$names = array();
		$largest = $pdo->query(
			"SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace"
			. " WHERE n.nspname = 'public' AND c.relkind = 'r'"
			. " ORDER BY pg_total_relation_size(c.oid) DESC LIMIT " . (int)self::LARGEST_TABLES)
			->fetchAll(PDO::FETCH_COLUMN);
		$exists = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ?");
		foreach (self::NAMED_TABLES as $t) {
			$exists->execute(array($t));
			if ($exists->fetchColumn()) { $names[] = $t; }
		}
		foreach ($largest as $t) {
			if (!in_array($t, $names, true)) { $names[] = $t; }
		}

		$rows = array();
		foreach ($names as $t) {
			$rows[$t] = (int)$pdo->query('SELECT count(*) FROM ' . self::quote_ident($t))->fetchColumn();
		}
		$pdo = null;
		return array('tables' => $tables, 'rows' => $rows);
	}

	/** Files and bytes under a directory. */
	private static function count_tree($dir) {
		$files = 0; $bytes = 0;
		if (!is_dir($dir)) {
			return array('files' => 0, 'bytes' => 0);
		}
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST);
		foreach ($it as $entry) {
			if ($entry->isFile() && !$entry->isLink()) {
				$files++;
				$bytes += (int)$entry->getSize();
			}
		}
		return array('files' => $files, 'bytes' => $bytes);
	}

	/**
	 * Remove a directory and everything under it, whatever modes the replayed
	 * tree carried. Best effort, then `rm -rf` for anything left.
	 */
	public static function remove_tree($dir) {
		if ((string)$dir === '' || !is_dir($dir) || is_link($dir)) { return; }
		try {
			// Two passes. A replayed tree can carry directories without write
			// permission, and a file cannot be unlinked from one of those — so
			// every directory is made writable on the way down before anything
			// is removed on the way up.
			@chmod($dir, 0700);
			$down = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
				RecursiveIteratorIterator::SELF_FIRST);
			foreach ($down as $entry) {
				if ($entry->isDir() && !$entry->isLink()) { @chmod($entry->getPathname(), 0700); }
			}
			$up = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
				RecursiveIteratorIterator::CHILD_FIRST);
			foreach ($up as $entry) {
				$p = $entry->getPathname();
				if ($entry->isDir() && !$entry->isLink()) { @rmdir($p); } else { @unlink($p); }
			}
		} catch (\Throwable $e) {
			// fall through to rm -rf
		}
		@rmdir($dir);
		if (is_dir($dir)) {
			@exec('rm -rf ' . escapeshellarg($dir) . ' 2>/dev/null');
		}
	}

	private static function tail($output, $lines = 6) {
		$parts = array_slice(explode("\n", trim((string)$output)), -$lines);
		return trim(implode(' | ', array_map('trim', $parts)));
	}

}
