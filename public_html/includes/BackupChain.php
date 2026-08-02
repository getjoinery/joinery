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
 *       files-0001.tar.gz.enc      an incremental
 *       db-0001.sql.gz.enc
 *       ...
 *
 * @version 1.1 - manifest writes are atomic (write-beside + rename)
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/BackupEnvelope.php'));

class BackupChainException extends Exception {}

class BackupChain {

	/** Manifest schema version. */
	const VERSION = 1;

	const MANIFEST_NAME = 'manifest.json';

	/** Prefix of a chain directory in the bucket. */
	const DIR_PREFIX = 'chain-';

	/** The database is dumped in full every run — see the class comment. */
	const KINDS = array('files', 'db', 'meta');

	// ------------------------------------------------------------------ shape

	/** A fresh manifest for a chain starting now. */
	public static function start($chain_id, $slug, array $envelope) {
		return array(
			'version'   => self::VERSION,
			'chain_id'  => (string)$chain_id,
			'slug'      => (string)$slug,
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

	/** Artifact filename for a kind and sequence, e.g. files-0003.tar.gz.enc. */
	public static function artifact_name($kind, $seq, $encrypted = true) {
		if (!in_array($kind, self::KINDS, true)) {
			throw new BackupChainException("Unknown chain artifact kind '{$kind}'.");
		}
		$ext = ($kind === 'db') ? '.sql.gz' : '.tar.gz';
		return $kind . '-' . str_pad((string)(int)$seq, 4, '0', STR_PAD_LEFT) . $ext . ($encrypted ? '.enc' : '');
	}

	/**
	 * Record a completed run. $artifacts is [kind => ['name','bytes','sha256']].
	 * Level 0 means this run is the chain's full.
	 */
	public static function add_run(array $manifest, $seq, $level, array $artifacts) {
		$run = array(
			'seq'       => (int)$seq,
			'level'     => (int)$level,
			'time'      => gmdate('Y-m-d\TH:i:s\Z'),
			'artifacts' => array(),
		);
		foreach ($artifacts as $kind => $a) {
			$run['artifacts'][$kind] = array(
				'name'   => (string)$a['name'],
				'bytes'  => (int)$a['bytes'],
				'sha256' => (string)$a['sha256'],
			);
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
	 *   no_chain     nothing to extend
	 *   snar_lost    the snapshot file is gone, so tar cannot produce a valid
	 *                incremental — this is the safe degradation, not a failure
	 *   age          the chain is older than the configured full interval
	 *   length       too many incrementals depend on one full
	 *
	 * Returns '' when the current chain should simply continue.
	 *
	 * Pure: every input is passed in, so the rules can be exercised without a
	 * bucket, a clock, or a filesystem.
	 */
	public static function should_start_new(?array $manifest = null, $snar_exists = false,
	                                        $full_interval_days = 7, $max_incrementals = 30,
	                                        $now_utc = null) {
		if (!$manifest || empty($manifest['runs'])) {
			return 'no_chain';
		}
		if (!$snar_exists) {
			return 'snar_lost';
		}

		$now = strtotime(($now_utc ?: gmdate('Y-m-d H:i:s')) . ' UTC');
		$started = strtotime((string)($manifest['created'] ?? ''));
		if ($started && $full_interval_days > 0
			&& ($now - $started) >= ($full_interval_days * 86400)) {
			return 'age';
		}

		if ($max_incrementals > 0 && count($manifest['runs']) > $max_incrementals) {
			return 'length';
		}

		return '';
	}

	// ---------------------------------------------------------- verification

	/**
	 * The artifacts needed to restore a chain at a given run, in the order they
	 * must be applied: the full first, then every incremental up to and
	 * including $seq, plus that run's database and metadata.
	 *
	 * Order is not cosmetic. tar's incremental extraction replays deletions from
	 * each archive's directory listings, so applying them out of order, or
	 * skipping one, produces a tree that never existed.
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
		if ((int)($runs[0]['level'] ?? 1) !== 0) {
			throw new BackupChainException('This chain does not begin with a full backup.');
		}

		$files = array();
		for ($i = 0; $i <= $seq; $i++) {
			if (!isset($runs[$i])) {
				throw new BackupChainException(
					'Run ' . $i . ' is missing from this chain, so run ' . $seq . ' cannot be restored: '
					. 'an incremental is only meaningful applied on top of everything before it.');
			}
			if (empty($runs[$i]['artifacts']['files'])) {
				throw new BackupChainException('Run ' . $i . ' has no files artifact.');
			}
			$files[] = $runs[$i]['artifacts']['files'];
		}

		return array(
			'chain_id' => (string)($manifest['chain_id'] ?? ''),
			'seq'      => $seq,
			'files'    => $files,
			'db'       => $runs[$seq]['artifacts']['db'] ?? null,
			'meta'     => $runs[$seq]['artifacts']['meta'] ?? null,
		);
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
		if ((int)($data['version'] ?? 0) !== self::VERSION) {
			throw new BackupChainException(
				'Unsupported chain manifest version ' . (int)($data['version'] ?? 0)
				. '; this build reads version ' . self::VERSION . '.');
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

	/** Every object key a chain owns, for a chain-atomic delete. */
	public static function object_keys(array $manifest, $prefix, $slug) {
		$dir = rtrim($prefix, '/') . '/' . $slug . '/' . self::dir_for($manifest['chain_id'] ?? '') . '/';
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
