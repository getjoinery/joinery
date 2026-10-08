<?php
/**
 * BackupChain — the manifest that makes a set of incremental archives restorable.
 *
 * A chain is one full backup plus the incrementals that depend on it. Its
 * manifest is the unit of listing and the restore contract: it names every
 * artifact in order, carries each one's size and hash, and holds the sealed data
 * keys that open them. Without it a bucket full of `inc-0003.tar.gz.enc` is
 * unusable, so it is rewritten after every successful run and uploaded with the
 * artifacts it describes.
 *
 * Two properties this file exists to guarantee:
 *
 *   * A chain is restored in ORDER, from the full forward. The sequence is
 *     explicit here rather than inferred from filenames, because inferring it
 *     from a bucket listing is how a missing artifact turns into a silently
 *     partial restore.
 *   * A chain is deleted whole or not at all. An incremental without its full
 *     is not a smaller backup, it is no backup, so retention operates on
 *     chains and never on the runs inside them.
 *
 * Layout in the bucket:
 *
 *   {prefix}/{slug}/chain-{YYYYMMDD_HHMMSS}/
 *       manifest.json
 *       files-0000.tar.gz.enc      the full
 *       db-0000.sql.gz.enc
 *       meta-0000.tar.gz.enc
 *       objects-0000.json.gz       the index of offloaded files, plain
 *       files-0001.tar.gz.enc      an incremental
 *       db-0001.sql.gz.enc
 *       ...
 *
 * That is a version-1 chain: one files archive per run, and the run's level is
 * the files archive's. A version-2 chain gives every kind its own level. Its
 * tree is two kinds, data (the site directory less public_html) and code
 * (public_html), and its database is a dump (db) or a physical backup (pgdata)
 * that increments on its own terms, so one kind can start over (level 0) in a
 * run where the others increment. A run's own level is 0 exactly when every
 * kind in it is: "this run restores on its own".
 *
 * @version 1.8 - should_start_new: destination_changed, when the chain's runs went somewhere other than where
 *                this run goes (specs/storage_targets.md WP3)
 * @version 1.7 - should_start_new: a swapped tree no longer starts a chain (the runner re-bases that kind
 *                inside it); layout_split does — a version-1 chain is not extended by a runner that writes
 *                version 2 — and is checked before snar_lost, whose snapshots it would read as lost
 * @version 1.6 - manifest version 2 (specs/backup_database_incrementals.md WP2): every artifact kind
 *                carries its own level, so one kind can start over inside a chain while the others
 *                continue. KINDS gains code, data and pgdata. restore_plan() plans per kind — the
 *                newest level 0 of that kind at or before the run, then every one after it — and
 *                returns the tree kinds and the database as lists; plan_artifacts() is the whole
 *                plan in restore order. decode() reads versions 1 and 2; start() writes the version
 *                it is given, 1 unless a caller asks for 2.
 * @version 1.5 - should_start_new breaks the chain when the code tree was swapped under the snapshot
 *                (tree_changed): an incremental across an upgrade records renames no extraction can
 *                apply. A manifest records why its chain started (`started_because`).
 * @version 1.4 - should_start_new rolls on age with a one-hour grace: the scheduled tick runs a few
 *                seconds earlier in the minute than the run it follows wrote `created`, so an exact
 *                comparison missed by seconds and every chain ran eight days instead of seven
 * @version 1.3 - the `objects` kind: a run's index of the site's offloaded files
 *                (objects-0003.json.gz, plain gzipped JSON like the manifest), named by
 *                artifact_name(), returned by restore_plan(), deleted with the chain by
 *                object_keys() — the objects it names are outside the chain and are not.
 * @version 1.2 - should_start_new breaks the chain when the recovery recipient changed: a chain's
 *                one data key is sealed at chain start, so after a key rotation an extended chain
 *                would stay openable only by the rotated-away key
 * @version 1.1 - manifest writes are atomic (write-beside + rename)
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/BackupEnvelope.php'));
require_once(PathHelper::getIncludePath('includes/BackupProfile.php'));

class BackupChainException extends Exception {}

class BackupChain {

	/** The newest manifest schema this build writes and reads. */
	const VERSION = 2;

	/** Every manifest schema decode() accepts. An older build refuses a newer one by name. */
	const VERSIONS = array(1, 2);

	/** The kinds that make up the site tree, in the order a restore applies them, by version. */
	const TREE_KINDS = array(1 => array('files'), 2 => array('data', 'code'));

	/** The kinds a run's database may be: a dump, whole every run, or a physical backup that increments. */
	const DATABASE_KINDS = array('db', 'pgdata');

	const MANIFEST_NAME = 'manifest.json';

	/** Prefix of a chain directory in the bucket. */
	const DIR_PREFIX = 'chain-';

	/**
	 * Slack on the age rule. A chain's `created` is stamped some seconds into the
	 * run that started it; the next scheduled tick at the same hour can arrive
	 * seconds earlier in the minute. Without slack, "7 days" misses by those
	 * seconds and the chain runs a whole extra day.
	 */
	const AGE_GRACE_SECONDS = 3600;

	/** Every artifact kind a manifest may name — see the class comment. */
	const KINDS = array('files', 'db', 'meta', 'objects', 'code', 'data', 'pgdata');

	// ------------------------------------------------------------------ shape

	/**
	 * A fresh manifest for a chain starting now. $reason is should_start_new()'s
	 * answer — why the previous chain was not extended — kept as
	 * `started_because` so a reader of the chain can see it.
	 */
	public static function start($chain_id, $slug, array $envelope, $reason = '', $version = 1) {
		if (!in_array((int)$version, self::VERSIONS, true)) {
			throw new BackupChainException('There is no chain manifest version ' . (int)$version . '.');
		}
		return array(
			'version'   => (int)$version,
			'chain_id'  => (string)$chain_id,
			'slug'      => (string)$slug,
			'started_because' => (string)$reason,
			'created'   => gmdate('Y-m-d\TH:i:s\Z'),
			'updated'   => gmdate('Y-m-d\TH:i:s\Z'),
			// One envelope for the whole chain: every artifact in it is
			// encrypted with the same data key, so a restore unseals once and
			// can then read the full and every incremental after it.
			'envelope'  => $envelope,
			'runs'      => array(),
		);
	}

	/** Chain id for a chain starting at this UTC time. */
	public static function new_chain_id($utc = null) {
		$utc = $utc ?: gmdate('Y-m-d H:i:s');
		return self::DIR_PREFIX . gmdate('Ymd_His', strtotime($utc . ' UTC'));
	}

	/** Directory name inside the target prefix. */
	public static function dir_for($chain_id) {
		return (string)$chain_id;
	}

	/** Next run sequence number in a chain. */
	public static function next_seq(array $manifest) {
		return count($manifest['runs'] ?? array());
	}

	/**
	 * Artifact filename for a kind and sequence, e.g. files-0003.tar.gz.enc.
	 * The objects index is plain (objects-0003.json.gz) whatever $encrypted
	 * says: the management node prunes by it and cannot open a chain key.
	 */
	public static function artifact_name($kind, $seq, $encrypted = true) {
		if (!in_array($kind, self::KINDS, true)) {
			throw new BackupChainException("Unknown chain artifact kind '{$kind}'.");
		}
		$n = str_pad((string)(int)$seq, 4, '0', STR_PAD_LEFT);
		if ($kind === 'objects') {
			return $kind . '-' . $n . '.json.gz';
		}
		$ext = ($kind === 'db') ? '.sql.gz' : '.tar.gz';   // files, meta, code, data, pgdata
		return $kind . '-' . $n . $ext . ($encrypted ? '.enc' : '');
	}

	/**
	 * Record a completed run. $artifacts is [kind => ['name','bytes','sha256']].
	 *
	 * Version 1: $level is the run's, and the files archive's (0 = the chain's
	 * full). Version 2: each artifact of a kind that increments (the tree kinds
	 * and pgdata) carries its own 'level', and may carry why it started over
	 * ('rebased_because'); pgdata also 'raw_bytes' and 'pg'. The run's level is
	 * derived — 0 exactly when every kind in it is — and $level is not read.
	 */
	public static function add_run(array $manifest, $seq, $level, array $artifacts) {
		$v2 = ((int)($manifest['version'] ?? 1) >= 2);
		$run = array(
			'seq'       => (int)$seq,
			'level'     => (int)$level,
			'time'      => gmdate('Y-m-d\TH:i:s\Z'),
			'artifacts' => array(),
		);
		foreach ($artifacts as $kind => $a) {
			$entry = array(
				'name'   => (string)$a['name'],
				'bytes'  => (int)$a['bytes'],
				'sha256' => (string)$a['sha256'],
			);
			if ($v2 && self::increments($kind)) {
				if (!isset($a['level'])) {
					throw new BackupChainException("A version-2 run's {$kind} artifact must say its level.");
				}
				$entry['level'] = (int)$a['level'];
				if (!empty($a['rebased_because'])) { $entry['rebased_because'] = (string)$a['rebased_because']; }
				if (isset($a['raw_bytes']))        { $entry['raw_bytes'] = (int)$a['raw_bytes']; }
				if (isset($a['pg']) && is_array($a['pg'])) { $entry['pg'] = $a['pg']; }
			}
			$run['artifacts'][$kind] = $entry;
		}
		if ($v2) {
			$run['level'] = 0;
			foreach (array_keys($run['artifacts']) as $kind) {
				if (self::kind_level($manifest, $run, $kind) !== 0) { $run['level'] = 1; }
			}
		}
		$manifest['runs'][] = $run;
		$manifest['updated'] = gmdate('Y-m-d\TH:i:s\Z');
		return $manifest;
	}

	// ------------------------------------------------------------- decisions

	/**
	 * Should this run start a NEW chain rather than extend the current one?
	 *
	 * Reasons, in the order they are checked:
	 *   no_chain          nothing to extend
	 *   destination_changed  the chain's runs went somewhere other than where this
	 *                     run goes (a site switched targets): its full is in the
	 *                     other place, so nothing here could be restored from
	 *                     an incremental on top of it
	 *   layout_split      the chain is in a layout this runner does not write (a
	 *                     version-1 chain, one files archive a run, under a runner
	 *                     that archives code and data apart). Checked before
	 *                     snar_lost: that runner's snapshots live at other paths,
	 *                     so they are always missing on its first run
	 *   snar_lost         a snapshot file is gone, so tar cannot produce a valid
	 *                     incremental — this is the safe degradation, not a failure
	 *   recovery_rotated  the chain's envelope is sealed to a recovery key that is
	 *                     no longer this site's — extending it would keep filing
	 *                     runs only the rotated-away key can open
	 *   age               the chain is older than the configured full interval
	 *   length            too many incrementals depend on one full
	 *
	 * A tree swapped under its snapshot (an upgrade, a restore) is not a reason
	 * to start a chain: the runner re-bases that kind alone inside the chain.
	 *
	 * Returns '' when the current chain should simply continue.
	 *
	 * Pure: every input is passed in, so the rules can be exercised without a
	 * bucket, a clock, or a filesystem.
	 */
	public static function should_start_new(?array $manifest = null, $snar_exists = false,
	                                        $full_interval_days = 7, $max_incrementals = 30,
	                                        $now_utc = null, $current_recovery_fpr = null,
	                                        $writes_version = 1, $chain_destination = null, $run_destination = null) {
		if (!$manifest || empty($manifest['runs'])) {
			return 'no_chain';
		}
		// Where the chain's runs went and where this one goes, as the caller
		// names them ('target:3'); null when the caller does not know.
		if ($chain_destination !== null && $run_destination !== null
				&& (string)$chain_destination !== (string)$run_destination) {
			return 'destination_changed';
		}
		if ((int)($manifest['version'] ?? 1) !== (int)$writes_version) {
			return 'layout_split';
		}
		if (!$snar_exists) {
			return 'snar_lost';
		}

		// A chain has ONE data key, sealed when the chain starts. Rotating the
		// recovery key therefore cannot take effect inside a chain — only a new
		// chain seals to the new key — so a recipient mismatch ends the chain
		// here. The old chain stays openable with the old private key, which is
		// why rotation instructions say to keep it until those chains retire.
		if ($current_recovery_fpr !== null && $current_recovery_fpr !== '') {
			$chain_fpr = '';
			foreach (($manifest['envelope']['recipients'] ?? array()) as $r) {
				if (($r['kind'] ?? '') === 'recovery') {
					$chain_fpr = (string)($r['fingerprint'] ?? '');
				}
			}
			if (!hash_equals((string)$current_recovery_fpr, $chain_fpr)) {
				return 'recovery_rotated';
			}
		}

		$now = strtotime(($now_utc ?: gmdate('Y-m-d H:i:s')) . ' UTC');
		$started = strtotime((string)($manifest['created'] ?? ''));
		if ($started && $full_interval_days > 0
			&& ($now - $started) >= ($full_interval_days * 86400 - self::AGE_GRACE_SECONDS)) {
			return 'age';
		}

		if ($max_incrementals > 0 && count($manifest['runs']) > $max_incrementals) {
			return 'length';
		}

		return '';
	}

	// ---------------------------------------------------------- verification

	/** Whether a kind increments inside a chain: the tree kinds and pgdata. A dump, meta and objects are whole every run. */
	public static function increments($kind) {
		return in_array((string)$kind, array('files', 'code', 'data', 'pgdata'), true);
	}

	/**
	 * The level of one kind in one run: 0 when that artifact restores without
	 * any before it. Version 1 has one level per run, the files archive's;
	 * version 2 records it on each artifact of a kind that increments. A kind
	 * that is whole every run is 0.
	 */
	public static function kind_level(array $manifest, array $run, $kind) {
		if (!self::increments($kind)) {
			return 0;
		}
		if ((int)($manifest['version'] ?? 1) < 2) {
			return ((string)$kind === 'files') ? (int)($run['level'] ?? 1) : 0;
		}
		return (int)($run['artifacts'][$kind]['level'] ?? 1);
	}

	/**
	 * The artifacts needed to restore a chain at a given run, per kind, in the
	 * order each must be applied.
	 *
	 * For a kind that increments: the newest level 0 of that kind at or before
	 * the run, then every one of that kind after it, up to and including the
	 * run. A version-1 chain's files are therefore its full and every
	 * incremental after it; a version-2 chain's code may start over at an
	 * upgrade while its data goes back to the chain's full. A dump is that
	 * run's alone.
	 *
	 * Order is not cosmetic. tar's incremental extraction replays deletions from
	 * each archive's directory listings, so applying them out of order, or
	 * skipping one, produces a tree that never existed.
	 *
	 * @return array ['chain_id', 'seq', 'version',
	 *                'trees'    => [kind => [artifact, ...]] in restore order,
	 *                'database' => ['kind' => 'db'|'pgdata', 'artifacts' => [...]] or null,
	 *                'meta' => artifact|null, 'objects' => artifact|null]
	 */
	public static function restore_plan(array $manifest, $seq = null) {
		$runs = $manifest['runs'] ?? array();
		if (!$runs) {
			throw new BackupChainException('This chain has no runs to restore.');
		}
		$seq = ($seq === null) ? (count($runs) - 1) : (int)$seq;
		if ($seq < 0 || $seq >= count($runs)) {
			throw new BackupChainException('This chain has no run ' . $seq . '.');
		}
		$version = (int)($manifest['version'] ?? 1);
		if (!isset(self::TREE_KINDS[$version])) {
			throw new BackupChainException('Unsupported chain manifest version ' . $version . '.');
		}
		if ($version === 1 && (int)($runs[0]['level'] ?? 1) !== 0) {
			throw new BackupChainException('This chain does not begin with a full backup.');
		}

		$trees = array();
		foreach (self::TREE_KINDS[$version] as $kind) {
			$trees[$kind] = self::kind_chain($manifest, $seq, $kind);
		}

		$database = null;
		$last = $runs[$seq]['artifacts'] ?? array();
		if (!empty($last['pgdata'])) {
			$database = array('kind' => 'pgdata', 'artifacts' => self::kind_chain($manifest, $seq, 'pgdata'));
		} elseif (!empty($last['db'])) {
			$database = array('kind' => 'db', 'artifacts' => array($last['db']));
		}

		return array(
			'chain_id' => (string)($manifest['chain_id'] ?? ''),
			'seq'      => $seq,
			'version'  => $version,
			'trees'    => $trees,
			'database' => $database,
			'meta'     => $last['meta'] ?? null,
			'objects'  => $last['objects'] ?? null,
		);
	}

	/**
	 * One kind's artifacts for a restore at $seq: its newest level 0 at or
	 * before the run, then every one after it. Every run in that span must
	 * carry the kind — an increment is only meaningful on top of everything
	 * before it.
	 */
	private static function kind_chain(array $manifest, $seq, $kind) {
		$runs = $manifest['runs'];
		$start = null;
		for ($i = $seq; $i >= 0; $i--) {
			if (!isset($runs[$i])) {
				throw new BackupChainException(
					'Run ' . $i . ' is missing from this chain, so run ' . $seq . ' cannot be restored: '
					. 'an incremental is only meaningful applied on top of everything before it.');
			}
			if (empty($runs[$i]['artifacts'][$kind])) {
				throw new BackupChainException('Run ' . $i . ' has no ' . $kind . ' artifact.');
			}
			if (self::kind_level($manifest, $runs[$i], $kind) === 0) {
				$start = $i;
				break;
			}
		}
		if ($start === null) {
			throw new BackupChainException('This chain has no full ' . $kind . ' backup at or before run ' . $seq . '.');
		}
		$out = array();
		for ($i = $start; $i <= $seq; $i++) {
			$out[] = $runs[$i]['artifacts'][$kind];
		}
		return $out;
	}

	/**
	 * Every artifact of a plan, in the order a restore applies them: the tree
	 * kinds (each from its full forward), the database, the run's metadata and
	 * its objects index. Each as ['kind' => ..., 'entry' => artifact].
	 */
	public static function plan_artifacts(array $plan) {
		$out = array();
		foreach ($plan['trees'] ?? array() as $kind => $list) {
			foreach ($list as $a) { $out[] = array('kind' => (string)$kind, 'entry' => $a); }
		}
		if (!empty($plan['database'])) {
			foreach ($plan['database']['artifacts'] as $a) {
				$out[] = array('kind' => (string)$plan['database']['kind'], 'entry' => $a);
			}
		}
		foreach (array('meta', 'objects') as $kind) {
			if (!empty($plan[$kind])) { $out[] = array('kind' => $kind, 'entry' => $plan[$kind]); }
		}
		return $out;
	}

	/**
	 * Check a downloaded artifact against what the manifest says it should be.
	 * A truncated download is the failure mode this catches, and it has to be
	 * caught BEFORE a restore starts overwriting a live tree.
	 */
	public static function verify_artifact($path, array $expected) {
		if (!is_file($path)) {
			throw new BackupChainException('Missing backup artifact: ' . basename($path));
		}
		$bytes = (int)filesize($path);
		if (!empty($expected['bytes']) && $bytes !== (int)$expected['bytes']) {
			throw new BackupChainException(
				basename($path) . ' is ' . $bytes . ' bytes but the manifest says '
				. (int)$expected['bytes'] . '. It is incomplete; do not restore from it.');
		}
		if (!empty($expected['sha256'])) {
			$actual = hash_file('sha256', $path);
			if (!hash_equals((string)$expected['sha256'], (string)$actual)) {
				throw new BackupChainException(
					basename($path) . ' does not match its recorded hash. It is damaged or was replaced; '
					. 'do not restore from it.');
			}
		}
		return true;
	}

	// --------------------------------------------------------------- storage

	public static function encode(array $manifest) {
		$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			throw new BackupChainException('Could not encode the chain manifest.');
		}
		return $json . "\n";
	}

	public static function decode($json) {
		$data = json_decode((string)$json, true);
		if (!is_array($data)) {
			throw new BackupChainException('This chain manifest is not readable JSON.');
		}
		if (!in_array((int)($data['version'] ?? 0), self::VERSIONS, true)) {
			throw new BackupChainException(
				'Unsupported chain manifest version ' . (int)($data['version'] ?? 0)
				. '; this build reads versions ' . implode(' and ', self::VERSIONS) . '.');
		}
		if (!isset($data['runs']) || !is_array($data['runs'])) {
			throw new BackupChainException('This chain manifest lists no runs.');
		}
		return $data;
	}

	public static function write(array $manifest, $path) {
		// Written beside, then renamed into place: the manifest is what makes a
		// chain extendable AND restorable, so a crash mid-write must leave the
		// previous manifest, not half of the new one.
		$tmp = $path . '.' . getmypid() . '.tmp';
		if (@file_put_contents($tmp, self::encode($manifest)) === false) {
			throw new BackupChainException('Could not write the chain manifest to ' . $path . '.');
		}
		@chmod($tmp, 0600);
		if (!@rename($tmp, $path)) {
			@unlink($tmp);
			throw new BackupChainException('Could not write the chain manifest to ' . $path . '.');
		}
		return $path;
	}

	public static function read($path) {
		$raw = @file_get_contents($path);
		if ($raw === false) {
			throw new BackupChainException('Could not read the chain manifest at ' . $path . '.');
		}
		return self::decode($raw);
	}

	/** Total bytes a chain occupies, for listing and retention reporting. */
	public static function bytes(array $manifest) {
		$total = 0;
		foreach ($manifest['runs'] ?? array() as $run) {
			foreach ($run['artifacts'] ?? array() as $a) {
				$total += (int)($a['bytes'] ?? 0);
			}
		}
		return $total;
	}

	/**
	 * Every object key a chain owns, for a chain-atomic delete.
	 *
	 * The profile segment is required rather than defaulted: this list is handed
	 * to a delete, and guessing the wrong segment would either delete nothing
	 * (harmless but silent) or address another party's backup storage.
	 */
	public static function object_keys(array $manifest, $prefix, $slug, $profile) {
		$dir = rtrim($prefix, '/') . '/' . $slug . '/' . BackupProfile::path_segment($profile)
		     . '/' . self::dir_for($manifest['chain_id'] ?? '') . '/';
		$keys = array($dir . self::MANIFEST_NAME);
		foreach ($manifest['runs'] ?? array() as $run) {
			foreach ($run['artifacts'] ?? array() as $a) {
				if (!empty($a['name'])) {
					$keys[] = $dir . $a['name'];
				}
			}
		}
		return $keys;
	}
}
