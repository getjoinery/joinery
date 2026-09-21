<?php
/**
 * BackupObjectRestore — bringing offloaded files home from the backup shelf.
 *
 * A backup's archives carry no offloaded file: every blob whose bytes live in
 * the file bucket is on the shelf once, encrypted under an epoch key, and a
 * run's index names each with its encrypted size and hash
 * (BackupObjects). After the archives and the database are back, this puts the
 * files back where the site expects them — but only the ones the file bucket
 * cannot serve (`missing`, the default), or every one of them (`all`, a site
 * leaving its bucket).
 *
 * The bytes come from one of two sources and are treated the same way: a tree
 * an operator downloaded with the site's own credential
 * (restore_chain.sh --objects DIR, the shelf's objects/{epoch}/ layout), or a
 * page of presigned links a management node signed (utils/restore_objects.php
 * as the restore_objects primitive). Either way an object is checked against
 * the index before it is decrypted, decrypted to the placement the storage
 * profile computes, checked against the blob's own row (size, and hash where
 * the row records one), and only then is the row set to local. Variants are
 * left to on-demand resize().
 *
 * What this never does: overwrite a file already at the placement, delete
 * anything in any bucket, or touch a row whose bytes it did not just place. A
 * file already on disk is adopted — the row is set to local — only when it
 * matches its record. That is what makes the restore repeatable: a run that
 * stops half way is finished by running it again.
 *
 * The survey is the read-only half: which names would be brought home, from
 * which epochs. A dry run prints it; a management node's first job asks the
 * node for it and signs a page of links per answer, driving the loop from its
 * side (specs/backup_offloaded_files.md § Restore). The survey's list is
 * capped so it always fits the agent's output; a truncated one says so and
 * the plane surveys again once the pages are done.
 *
 * Nothing here prints a key or a credential. Every function is pure over what
 * it is handed; the two test hooks stand in for the file bucket and the
 * placement so a suite can run against scratch.
 *
 * @version 1.0.1 - a survey's list is capped by bytes as well as by count (a name can be 255 bytes,
 *                  and the agent drops the middle of output past 64 KiB); in missing mode a row the
 *                  file bucket serves is left alone even when a copy is on disk
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/BackupObjects.php'));
require_once(PathHelper::getIncludePath('includes/BackupStaging.php'));
require_once(PathHelper::getIncludePath('includes/BackupChain.php'));
require_once(PathHelper::getIncludePath('includes/BackupEnvelope.php'));
require_once(PathHelper::getIncludePath('includes/BackupFetch.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriverFactory.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/StorageProfileRegistry.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudOffloadEngine.php'));
require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));

class BackupObjectRestoreException extends Exception {

	/** Exit status for a request that could not be understood. */
	const MALFORMED = 2;

	/** Exit status for a transfer, key, integrity or placement failure. */
	const FAILED = 1;

	public function __construct($message, $code = self::FAILED) {
		parent::__construct($message, (int)$code);
	}
}

class BackupObjectRestore {

	/** Bring home only what the file bucket cannot serve. */
	const MODE_MISSING = 'missing';

	/** Bring every offloaded file home. */
	const MODE_ALL = 'all';

	const RESULT_OK   = 'ok';
	const RESULT_FAIL = 'fail';

	/**
	 * The most names one survey reports, and the most bytes the list may
	 * run to. The agent keeps 64 KiB of a script's output and drops the
	 * middle of anything longer, so the list must fit whole by construction:
	 * a name can be 255 bytes, and a thousand of those would not. A survey
	 * with more to say sets `more`, and is asked again once the pages it
	 * named are done.
	 */
	const SURVEY_MAX_NAMES = 1000;
	const SURVEY_MAX_BYTES = 32768;

	/** The most objects one page brings home (BackupStaging::MAX_OBJECT_LINKS). */
	const PAGE_MAX = BackupStaging::MAX_OBJECT_LINKS;

	/** How long to wait on a row the offload tick is holding, per object. */
	const ROW_LOCK_TRIES = 5;

	/**
	 * Test seams. 'store' => fn(string $visibility): ?CloudStorageDriver, the
	 * file bucket a blob is served from; 'placement' => fn(FileBlob): string,
	 * where its original belongs on disk. Production leaves both unset.
	 */
	public static $test_hooks = array();

	// -------------------------------------------------------------- request

	public static function is_mode($mode) {
		return in_array((string)$mode, array(self::MODE_MISSING, self::MODE_ALL), true);
	}

	/**
	 * The request a management node sends (the restore_objects primitive):
	 * the run by chain and number, the index by link, the mode, and — on a
	 * page job — the objects to bring home, with the envelopes of the epochs
	 * they are sealed under. No key and no credential; anything unrecognised
	 * is refused.
	 *
	 * @return array ['chain_id','profile','seq','mode','index_url','epoch_envelope_urls','object_urls']
	 * @throws BackupObjectRestoreException code MALFORMED
	 */
	public static function parse_link_request($config) {
		if (!is_array($config)) {
			throw new BackupObjectRestoreException('this run needs its configuration as JSON on stdin', BackupObjectRestoreException::MALFORMED);
		}
		$accepted = array('chain_id', 'profile', 'seq', 'mode', 'index_url', 'epoch_envelope_urls', 'object_urls');
		$unknown = array_diff(array_keys($config), $accepted);
		if ($unknown) {
			sort($unknown);
			throw new BackupObjectRestoreException('configuration carries unrecognised key(s): ' . implode(', ', $unknown),
				BackupObjectRestoreException::MALFORMED);
		}
		$chain_id = trim((string)($config['chain_id'] ?? ''));
		if (!preg_match(BackupStaging::CHAIN_ID_PATTERN, $chain_id)) {
			throw new BackupObjectRestoreException('that is not a chain id', BackupObjectRestoreException::MALFORMED);
		}
		try {
			$profile = BackupProfile::normalize((string)($config['profile'] ?? ''));
		} catch (BackupProfileException $e) {
			throw new BackupObjectRestoreException($e->getMessage(), BackupObjectRestoreException::MALFORMED);
		}
		if (!isset($config['seq']) || !is_numeric($config['seq']) || (int)$config['seq'] < 0 || (int)$config['seq'] > BackupStaging::MAX_SEQ) {
			throw new BackupObjectRestoreException("'seq' must name the run, 0 to " . BackupStaging::MAX_SEQ, BackupObjectRestoreException::MALFORMED);
		}
		$mode = (string)($config['mode'] ?? self::MODE_MISSING);
		if (!self::is_mode($mode)) {
			throw new BackupObjectRestoreException("'mode' must be missing or all", BackupObjectRestoreException::MALFORMED);
		}
		if (!BackupFetch::is_signed_url((string)($config['index_url'] ?? ''))) {
			throw new BackupObjectRestoreException("'index_url' must be an https URL", BackupObjectRestoreException::MALFORMED);
		}
		try {
			$envelopes = BackupStaging::link_map($config['epoch_envelope_urls'] ?? null, 'epoch_envelope_urls',
				BackupStaging::EPOCH_ID_PATTERN, 64);
			$objects   = BackupStaging::link_map($config['object_urls'] ?? null, 'object_urls');
		} catch (BackupStagingException $e) {
			throw new BackupObjectRestoreException($e->getMessage(), $e->getCode());
		}
		return array(
			'chain_id'            => $chain_id,
			'profile'             => $profile,
			'seq'                 => (int)$config['seq'],
			'mode'                => $mode,
			'index_url'           => trim((string)$config['index_url']),
			'epoch_envelope_urls' => $envelopes,
			'object_urls'         => $objects,
		);
	}

	/**
	 * The request the shell path makes (restore_chain.sh --objects, or an
	 * operator by hand): an index file, a directory in the shelf's
	 * objects/{epoch}/ layout, the mode, and — where the site key does not
	 * open an epoch — a key file per epoch, recovered with backup_envelope.php.
	 *
	 * @return array ['index','objects_dir','mode','dry_run','epoch_keys' => epoch => path]
	 * @throws BackupObjectRestoreException code MALFORMED
	 */
	public static function parse_tree_request($config) {
		if (!is_array($config)) {
			throw new BackupObjectRestoreException('this run needs its configuration as JSON on stdin or as arguments', BackupObjectRestoreException::MALFORMED);
		}
		$accepted = array('index', 'objects_dir', 'mode', 'dry_run', 'epoch_keys');
		$unknown = array_diff(array_keys($config), $accepted);
		if ($unknown) {
			sort($unknown);
			throw new BackupObjectRestoreException('configuration carries unrecognised key(s): ' . implode(', ', $unknown),
				BackupObjectRestoreException::MALFORMED);
		}
		$index = (string)($config['index'] ?? '');
		if ($index === '' || !is_file($index) || !is_readable($index)) {
			throw new BackupObjectRestoreException("'index' must name a readable offloaded-files index (objects-NNNN.json.gz)",
				BackupObjectRestoreException::MALFORMED);
		}
		$dir = rtrim((string)($config['objects_dir'] ?? ''), '/');
		$dry = !empty($config['dry_run']);
		if (!$dry && ($dir === '' || !is_dir($dir))) {
			throw new BackupObjectRestoreException("'objects_dir' must be a directory holding the shelf's objects/{epoch}/ tree",
				BackupObjectRestoreException::MALFORMED);
		}
		$mode = (string)($config['mode'] ?? self::MODE_MISSING);
		if (!self::is_mode($mode)) {
			throw new BackupObjectRestoreException("'mode' must be missing or all", BackupObjectRestoreException::MALFORMED);
		}
		$keys = array();
		if (isset($config['epoch_keys']) && $config['epoch_keys'] !== null) {
			if (!is_array($config['epoch_keys'])) {
				throw new BackupObjectRestoreException("'epoch_keys' must map an epoch id to a key file", BackupObjectRestoreException::MALFORMED);
			}
			foreach ($config['epoch_keys'] as $epoch => $path) {
				if (!preg_match(BackupStaging::EPOCH_ID_PATTERN, (string)$epoch)) {
					throw new BackupObjectRestoreException("'epoch_keys' names something that is not an epoch id", BackupObjectRestoreException::MALFORMED);
				}
				if (!is_string($path) || $path === '' || !is_file($path) || !is_readable($path)) {
					throw new BackupObjectRestoreException('the key file for ' . $epoch . ' is not readable', BackupObjectRestoreException::MALFORMED);
				}
				$keys[(string)$epoch] = $path;
			}
		}
		return array('index' => $index, 'objects_dir' => $dir, 'mode' => $mode, 'dry_run' => $dry, 'epoch_keys' => $keys);
	}

	// ----------------------------------------------------------------- keys

	/**
	 * The data key of every epoch the index's stored entries name: from a key
	 * file where one was given for the epoch, otherwise by opening the epoch's
	 * envelope with this machine's own site key. Any epoch that yields no key
	 * is a refusal by name — a restore that could open half its files would
	 * leave a person guessing which half.
	 *
	 * @param callable $envelope_path fn(string $epoch): string — where the epoch's envelope.json is
	 * @param array    $key_files     epoch => path of a recovered key
	 * @return array epoch => data key
	 * @throws BackupObjectRestoreException
	 */
	public static function epoch_keys(array $index, callable $envelope_path, array $key_files = array()) {
		$epochs = array();
		foreach (BackupObjects::index_entries($index) as $e) {
			$epochs[(string)$e['epoch']] = ($epochs[(string)$e['epoch']] ?? 0) + 1;
		}
		ksort($epochs);
		$keys = array();
		foreach ($epochs as $epoch => $n) {
			if (isset($key_files[$epoch])) {
				$key = trim((string)@file_get_contents($key_files[$epoch]));
				if ($key === '') {
					throw new BackupObjectRestoreException('the key file for ' . $epoch . ' is empty');
				}
				$keys[$epoch] = $key;
				continue;
			}
			$keys[$epoch] = self::open_envelope($epoch, (string)$envelope_path($epoch), $n);
		}
		return $keys;
	}

	/**
	 * One epoch's data key from its envelope, with this machine's own site
	 * key. The envelope must be the epoch's own (its artifact field says which
	 * epoch it was minted for) and must open here; otherwise the refusal names
	 * the epoch and how many files it holds.
	 *
	 * @throws BackupObjectRestoreException
	 */
	public static function open_envelope($epoch, $path, $holds_n = 0) {
		$holds = $holds_n > 0 ? ' for the ' . $holds_n . ' offloaded file' . ($holds_n === 1 ? '' : 's') . ' it holds' : '';
		if ($path === '' || !is_file($path)) {
			throw new BackupObjectRestoreException('gone: the envelope of ' . $epoch . ' is not there, so no key can be recovered' . $holds
				. ' (recover one with backup_envelope.php open and pass it as --epoch-key ' . $epoch . '=FILE)');
		}
		try {
			$envelope = BackupEnvelope::read_sidecar($path);
		} catch (\Throwable $ex) {
			throw new BackupObjectRestoreException('the envelope of ' . $epoch . ' is not readable: ' . $ex->getMessage());
		}
		$sealed_for = (string)($envelope['artifact'] ?? '');
		if ($sealed_for !== '' && $sealed_for !== (string)$epoch) {
			throw new BackupObjectRestoreException('the envelope at ' . $epoch . ' was minted for ' . $sealed_for
				. ' — it is not that epoch\'s, so its files cannot be opened');
		}
		try {
			return BackupEnvelope::open_as_site($envelope);
		} catch (\Throwable $ex) {
			throw new BackupObjectRestoreException('the envelope of ' . $epoch . ' does not open with this machine\'s own backup key'
				. $holds . ': ' . $ex->getMessage() . ' (recover the key with backup_envelope.php open --private <recovery key>'
				. ' and pass it as --epoch-key ' . $epoch . '=FILE)');
		}
	}

	// ------------------------------------------------------------ decisions

	/** The blob row an index entry names, or null when the restored database has none. */
	public static function blob_for($name) {
		$blobs = new MultiFileBlob(array('fbb_stored_name' => (string)$name), null, 1);
		foreach ($blobs as $blob) { return $blob; }
		return null;
	}

	/** Where a blob's original belongs on disk: the storage profile's placement, or the test seam. */
	public static function placement(FileBlob $blob) {
		if (isset(self::$test_hooks['placement'])) {
			return (string)call_user_func(self::$test_hooks['placement'], $blob);
		}
		$visibility = $blob->is_private_bool() ? 'private' : 'public';
		foreach (StorageProfileRegistry::forVisibility($visibility) as $profile) {
			if ($profile->table() !== FileBlob::$tablename) { continue; }
			$items = $profile->reverseItemsForRow((int)$blob->key);
			if (!empty($items[0]['local_path'])) {
				return (string)$items[0]['local_path'];
			}
		}
		throw new BackupObjectRestoreException('no storage profile places ' . $visibility . ' files on this site, so '
			. $blob->get('fbb_stored_name') . ' has nowhere to go');
	}

	/** Can the file bucket serve this blob's original, at its recorded size? */
	public static function served(FileBlob $blob) {
		$visibility = $blob->is_private_bool() ? 'private' : 'public';
		$driver = isset(self::$test_hooks['store'])
			? call_user_func(self::$test_hooks['store'], $visibility)
			: CloudStorageDriverFactory::forVisibilityWithFallback($visibility);
		if (!$driver) {
			return false;
		}
		$head = $driver->head($blob->remote_key_for('original'));
		if ($head === null) {
			return false;
		}
		$size = (int)$blob->get('fbb_size_bytes');
		return $size <= 0 || (int)($head['size'] ?? -1) === $size;
	}

	/**
	 * What to do about one stored entry. 'want' — bring it home; 'kept' — its
	 * file is already on disk, adopt it; 'served' — the file bucket has it
	 * (missing mode only); 'local' — the row is not offloaded; 'no_row' — the
	 * restored database has no row for it, so there is nothing to attach the
	 * bytes to.
	 *
	 * In missing mode a row the file bucket serves is left as it is, whatever
	 * is on disk: a local copy beside a served cloud row is the waiting state
	 * the offload tick already handles, and flipping it local would have the
	 * tick upload the file again.
	 */
	public static function decide($mode, ?FileBlob $blob) {
		if ($blob === null) {
			return 'no_row';
		}
		if ((string)$blob->get('fbb_storage_driver') !== 'cloud') {
			return 'local';
		}
		if ($mode === self::MODE_MISSING && self::served($blob)) {
			return 'served';
		}
		if (is_file(self::placement($blob))) {
			return 'kept';
		}
		return 'want';
	}

	/**
	 * The read-only half: which names would be brought home, and from which
	 * epochs. The list is capped at $max names and $max_bytes (the names and
	 * the commas between them, as the contract prints it); `more` says it
	 * was. The counts are of everything, capped or not.
	 *
	 * @return array ['want' => [names], 'more' => bool, 'wanted' => int, 'indexed' => int,
	 *                'epochs' => epoch => n wanted, 'not_stored' => int, 'served' => int,
	 *                'local' => int, 'no_row' => int]
	 */
	public static function survey(array $index, $mode, $max = self::SURVEY_MAX_NAMES, $max_bytes = self::SURVEY_MAX_BYTES) {
		$out = array('want' => array(), 'more' => false, 'wanted' => 0, 'indexed' => 0, 'epochs' => array(),
			'not_stored' => 0, 'served' => 0, 'local' => 0, 'no_row' => 0);
		$bytes = 0;
		foreach (($index['objects'] ?? array()) as $e) {
			$name = (string)($e['name'] ?? '');
			if ($name === '') { continue; }
			if (empty($e['stored'])) { $out['not_stored']++; continue; }
			$out['indexed']++;
			$decision = self::decide($mode, self::blob_for($name));
			if ($decision === 'want' || $decision === 'kept') {
				$out['wanted']++;
				$epoch = (string)($e['epoch'] ?? '');
				$out['epochs'][$epoch] = ($out['epochs'][$epoch] ?? 0) + 1;
				$grown = $bytes + strlen($name) + ($out['want'] ? 1 : 0);
				if (count($out['want']) < (int)$max && $grown <= (int)$max_bytes) {
					$out['want'][] = $name;
					$bytes = $grown;
				} else {
					$out['more'] = true;
				}
				continue;
			}
			$out[$decision]++;
		}
		ksort($out['epochs']);
		return $out;
	}

	/** The index narrowed to some of its names — what a page needs keys and envelopes for. Pure. */
	public static function subset(array $index, array $names) {
		$want = array_flip(array_map('strval', $names));
		$out = $index;
		$out['objects'] = array();
		foreach (($index['objects'] ?? array()) as $e) {
			if (isset($want[(string)($e['name'] ?? '')])) { $out['objects'][] = $e; }
		}
		return $out;
	}

	// ---------------------------------------------------------- bring home

	/**
	 * Bring the named objects home. $source($name, $entry) hands back
	 * ['path' => ciphertext, 'temporary' => bool]; a temporary ciphertext is
	 * removed once the object is placed or refused, so a page holds at most
	 * one object's ciphertext and one plaintext at any point.
	 *
	 * Every name must be one the index marks stored (a page from a management
	 * node that names anything else does not match the run). A name whose
	 * decision is not 'want' is skipped, not fetched — a page composed from
	 * an earlier survey may find the file bucket has recovered since.
	 *
	 * @param array    $keys     epoch => data key (epoch_keys())
	 * @param callable $progress fn(string $what, string $name, string $detail)
	 * @return array ['restored' => n, 'bytes' => n, 'kept' => n, 'skipped' => n]
	 * @throws BackupObjectRestoreException
	 */
	public static function restore(array $index, array $names, $mode, array $keys, callable $source, ?callable $progress = null) {
		$entries = BackupObjects::index_entries($index);
		foreach ($names as $name) {
			if (!isset($entries[(string)$name])) {
				throw new BackupObjectRestoreException('the request names the offloaded file ' . $name
					. ', which this run\'s index does not mark stored', BackupObjectRestoreException::MALFORMED);
			}
		}
		$out = array('restored' => 0, 'bytes' => 0, 'kept' => 0, 'skipped' => 0);
		foreach ($names as $name) {
			$name  = (string)$name;
			$entry = $entries[$name];
			$blob  = self::blob_for($name);
			$decision = self::decide($mode, $blob);
			if ($decision === 'kept') {
				self::adopt($name, $blob);
				$out['kept']++;
				if ($progress) { $progress('kept', $name, 'already on disk; its record is set to local'); }
				continue;
			}
			if ($decision !== 'want') {
				$out['skipped']++;
				if ($progress) { $progress('skipped', $name, self::decision_words($decision)); }
				continue;
			}
			$epoch = (string)$entry['epoch'];
			if (!isset($keys[$epoch])) {
				throw new BackupObjectRestoreException('no key for ' . $epoch . ', which ' . $name . ' is sealed under');
			}
			$got = $source($name, $entry);
			try {
				$bytes = self::bring($name, $entry, (string)$got['path'], $keys[$epoch], $blob);
			} finally {
				if (!empty($got['temporary'])) { @unlink((string)$got['path']); }
			}
			$out['restored']++;
			$out['bytes'] += (int)$bytes;
			if ($progress) { $progress('restored', $name, BackupFetch::human($bytes)); }
		}
		return $out;
	}

	/** Why a name was skipped, in words. */
	public static function decision_words($decision) {
		switch ((string)$decision) {
			case 'served': return 'the file store still serves it';
			case 'local':  return 'its record says the file is local';
			case 'no_row': return 'the restored database has no record of it';
			default:       return (string)$decision;
		}
	}

	/**
	 * One object: checked against the index, decrypted beside its placement,
	 * checked against its own row, moved into place, and only then is the row
	 * set to local. Nothing is overwritten: a file already at the placement
	 * is a refusal by name (decide() adopts one that matches instead).
	 *
	 * @return int plaintext bytes placed
	 * @throws BackupObjectRestoreException
	 */
	public static function bring($name, array $entry, $ciphertext, $data_key, FileBlob $blob) {
		try {
			BackupChain::verify_artifact($ciphertext, array('bytes' => (int)$entry['object_bytes'], 'sha256' => (string)$entry['object_sha256']));
		} catch (Exception $ex) {
			throw new BackupObjectRestoreException('offloaded file ' . $name . ': ' . str_replace('the manifest', 'its index', $ex->getMessage()));
		}
		$dest = self::placement($blob);
		if (file_exists($dest)) {
			throw new BackupObjectRestoreException('offloaded file ' . $name . ': a file is already at ' . $dest . '; nothing is overwritten');
		}
		$parent = dirname($dest);
		if (!is_dir($parent) && !@mkdir($parent, 0777, true)) {
			throw new BackupObjectRestoreException('offloaded file ' . $name . ': could not make ' . $parent);
		}
		$part = $parent . '/.restore-' . getmypid() . '-' . basename($dest) . '.part';
		try {
			$bytes = BackupObjects::decrypt_file($ciphertext, $part, $data_key);
		} catch (BackupObjectsException $ex) {
			@unlink($part);
			throw new BackupObjectRestoreException('offloaded file ' . $name . ': ' . $ex->getMessage());
		}
		try {
			self::check_against_row($name, $part, $blob);
		} catch (BackupObjectRestoreException $ex) {
			@unlink($part);
			throw $ex;
		}
		if (!@rename($part, $dest)) {
			@unlink($part);
			throw new BackupObjectRestoreException('offloaded file ' . $name . ': could not put it in place at ' . $dest);
		}
		@chmod($dest, 0666);
		self::set_local($blob);
		return (int)$bytes;
	}

	/**
	 * A file already at the placement becomes the blob's local copy when it
	 * matches the row; one that does not is left alone and refused by name.
	 */
	public static function adopt($name, FileBlob $blob) {
		self::check_against_row($name, self::placement($blob), $blob, true);
		self::set_local($blob);
	}

	/**
	 * The plaintext against the blob's own record: size always, hash where the
	 * row holds one. $is_placed words the refusal for a file found on disk
	 * rather than one just decrypted.
	 */
	private static function check_against_row($name, $path, FileBlob $blob, $is_placed = false) {
		$size = (int)@filesize($path);
		$want = (int)$blob->get('fbb_size_bytes');
		if ($want > 0 && $size !== $want) {
			throw new BackupObjectRestoreException('offloaded file ' . $name . ($is_placed ? ': the file already at ' . $path . ' is ' : ' decrypts to ')
				. $size . ' bytes where its record says ' . $want . ($is_placed ? '; nothing is overwritten' : ''));
		}
		$sha = (string)$blob->get('fbb_sha256');
		if ($sha !== '' && !hash_equals($sha, (string)hash_file('sha256', $path))) {
			throw new BackupObjectRestoreException('offloaded file ' . $name . ($is_placed ? ': the file already at ' . $path . ' is not this file'
				: ' decrypts to bytes whose hash is not the one its record holds') . ($is_placed ? '; nothing is overwritten' : ' — the shelf holds a different file'));
		}
	}

	/**
	 * The row to local, under the offload engine's per-row lock so the tick
	 * and this never write one row at once. Only a row still marked cloud is
	 * changed; the failure counters are cleared with it, as the drain does.
	 */
	private static function set_local(FileBlob $blob) {
		$db = DbConnector::get_instance()->get_db_link();
		$id = (int)$blob->key;
		$got = false;
		for ($i = 0; $i < self::ROW_LOCK_TRIES; $i++) {
			$q = $db->prepare('SELECT pg_try_advisory_lock(:k1, :k2) AS got');
			$q->execute(array(':k1' => CloudOffloadEngine::ADVISORY_LOCK_NAMESPACE, ':k2' => $id));
			$row = $q->fetch(PDO::FETCH_ASSOC);
			$q->closeCursor();
			if (!empty($row['got'])) { $got = true; break; }
			sleep(1);
		}
		if (!$got) {
			throw new BackupObjectRestoreException('offloaded file ' . $blob->get('fbb_stored_name')
				. ' is being handled by the offload tick; run this again in a moment');
		}
		try {
			$u = $db->prepare("UPDATE fbb_file_blobs SET fbb_storage_driver = 'local', fbb_sync_failed_count = 0,"
				. " fbb_sync_last_attempt = now() WHERE fbb_file_blob_id = ? AND fbb_storage_driver = 'cloud'");
			$u->execute(array($id));
			$u->closeCursor();
		} finally {
			$un = $db->prepare('SELECT pg_advisory_unlock(:k1, :k2)');
			$un->execute(array(':k1' => CloudOffloadEngine::ADVISORY_LOCK_NAMESPACE, ':k2' => $id));
			$un->closeCursor();
		}
		$blob->set('fbb_storage_driver', 'local', false);
	}

	// -------------------------------------------------------------- sources

	/** A source over a downloaded tree in the shelf's objects/{epoch}/ layout. Nothing is temporary. */
	public static function tree_source($dir) {
		$dir = rtrim((string)$dir, '/');
		return function ($name, array $entry) use ($dir) {
			$path = $dir . '/' . $entry['epoch'] . '/' . $name . BackupObjects::OBJECT_SUFFIX;
			if (!is_file($path)) {
				throw new BackupObjectRestoreException('gone: offloaded file ' . $name . ' is not in the downloaded tree at '
					. BackupObjects::object_relname($entry['epoch'], $name));
			}
			return array('path' => $path, 'temporary' => false);
		};
	}

	/**
	 * A source over a page of links, one object at a time through
	 * BackupStaging. Every fetch is temporary, and each is preceded by a room
	 * check: the ciphertext and its plaintext both land on this disk.
	 */
	public static function link_source($work, array $object_urls, ?callable $progress = null) {
		return function ($name, array $entry) use ($work, $object_urls, $progress) {
			if (!isset($object_urls[$name])) {
				throw new BackupObjectRestoreException('no download link was supplied for the offloaded file ' . $name);
			}
			$needed = 2 * (int)$entry['object_bytes'] + BackupFetch::MIN_HEADROOM_BYTES;
			$free = @disk_free_space($work);
			if ($free !== false && $free < $needed) {
				throw new BackupObjectRestoreException('not enough disk: offloaded file ' . $name . ' needs about '
					. BackupFetch::human($needed) . ' free in ' . $work . ', and there is ' . BackupFetch::human((int)$free));
			}
			try {
				$got = BackupStaging::fetch_object($work, $name, $entry, $object_urls[$name], $progress);
			} catch (BackupStagingException $e) {
				throw new BackupObjectRestoreException($e->getMessage(), $e->getCode());
			}
			return array('path' => $got['path'], 'temporary' => true);
		};
	}

	// ------------------------------------------------------------- contract

	/**
	 * The result lines a script prints and a management node reads back.
	 * A survey carries WANTED, WANT (the capped list) and EPOCHS; a page
	 * carries RESTORED, BYTES and KEPT; both carry the counts of what was
	 * skipped and what was never on the shelf.
	 */
	public static function format_contract(array $r) {
		$lines = array();
		$lines[] = 'RESTORE_OBJECTS_RESULT=' . ($r['result'] ?? self::RESULT_FAIL);
		$lines[] = 'RESTORE_OBJECTS_MODE=' . (string)($r['mode'] ?? self::MODE_MISSING);
		$lines[] = 'RESTORE_OBJECTS_RUN=' . (string)($r['run'] ?? '');
		$lines[] = 'RESTORE_OBJECTS_INDEXED=' . (int)($r['indexed'] ?? 0);
		$lines[] = 'RESTORE_OBJECTS_NOT_ON_SHELF=' . (int)($r['not_stored'] ?? 0);
		if (isset($r['wanted'])) {
			$lines[] = 'RESTORE_OBJECTS_WANTED=' . (int)$r['wanted'];
			$epochs = array();
			foreach ((array)($r['epochs'] ?? array()) as $epoch => $n) { $epochs[] = $epoch . ':' . (int)$n; }
			$lines[] = 'RESTORE_OBJECTS_EPOCHS=' . implode(',', $epochs);
			$lines[] = 'RESTORE_OBJECTS_WANT=' . implode(',', (array)($r['want'] ?? array()));
			if (!empty($r['more'])) { $lines[] = 'RESTORE_OBJECTS_MORE=1'; }
		}
		if (isset($r['restored'])) {
			$lines[] = 'RESTORE_OBJECTS_RESTORED=' . (int)$r['restored'];
			$lines[] = 'RESTORE_OBJECTS_BYTES=' . (int)($r['bytes'] ?? 0);
			$lines[] = 'RESTORE_OBJECTS_KEPT=' . (int)($r['kept'] ?? 0);
		}
		$lines[] = 'RESTORE_OBJECTS_SKIPPED=' . (int)($r['skipped'] ?? (($r['served'] ?? 0) + ($r['local'] ?? 0) + ($r['no_row'] ?? 0)));
		$lines[] = 'RESTORE_OBJECTS_DURATION=' . (int)($r['duration'] ?? 0);
		if (($r['result'] ?? '') !== self::RESULT_OK) {
			$lines[] = 'RESTORE_OBJECTS_REASON=' . str_replace(array("\r", "\n"), ' ', (string)($r['reason'] ?? ''));
		}
		return implode("\n", $lines) . "\n";
	}

	/**
	 * The contract read back. 'result' is fail when the text carries no
	 * RESTORE_OBJECTS_RESULT line; 'want' is a list; 'epochs' a map.
	 */
	public static function parse_contract($text) {
		$out = array();
		if (!preg_match_all('/^RESTORE_OBJECTS_([A-Z_]+)=(.*)$/m', (string)$text, $all, PREG_SET_ORDER)) {
			return array('result' => self::RESULT_FAIL, 'reason' => 'the node reported no result');
		}
		foreach ($all as $m) {
			$key = strtolower($m[1]);
			$val = trim($m[2]);
			switch ($key) {
				case 'indexed': case 'not_on_shelf': case 'wanted': case 'restored': case 'bytes':
				case 'kept': case 'skipped': case 'duration': case 'more':
					$out[$key === 'not_on_shelf' ? 'not_stored' : $key] = (int)$val;
					break;
				case 'want':
					$names = array();
					foreach (array_filter(explode(',', $val)) as $name) {
						if (preg_match(BackupStaging::LINK_NAME_PATTERN, $name) && strlen($name) <= 255) { $names[] = $name; }
					}
					$out['want'] = $names;
					break;
				case 'epochs':
					$epochs = array();
					foreach (array_filter(explode(',', $val)) as $pair) {
						$bits = explode(':', $pair, 2);
						if (count($bits) === 2 && preg_match(BackupStaging::EPOCH_ID_PATTERN, $bits[0])) { $epochs[$bits[0]] = (int)$bits[1]; }
					}
					$out['epochs'] = $epochs;
					break;
				default:
					$out[$key] = $val;
			}
		}
		if (!in_array($out['result'] ?? '', array(self::RESULT_OK, self::RESULT_FAIL), true)) {
			$out['result'] = self::RESULT_FAIL;
			if (empty($out['reason'])) { $out['reason'] = 'the node reported no result'; }
		}
		$out['more'] = !empty($out['more']);
		return $out;
	}

	/**
	 * The result in plain words. A survey: "1,204 offloaded files to bring
	 * home (8,811 need nothing: served by the file store, or already here)".
	 * A page: "Brought 36 offloaded files home (412 MB) (2 already on disk)".
	 */
	public static function describe(array $r) {
		if (($r['result'] ?? '') !== self::RESULT_OK) {
			return 'Could not bring the offloaded files home: ' . (string)($r['reason'] ?? 'no reason given');
		}
		$n = function ($v) { return number_format((int)$v); };
		$files = function ($v) use ($n) { return $n($v) . ' offloaded file' . ((int)$v === 1 ? '' : 's'); };
		$notes = array();
		if (!empty($r['not_stored'])) { $notes[] = $files($r['not_stored']) . ' never reached the shelf'; }
		if (isset($r['restored'])) {
			$text = 'Brought ' . $files($r['restored']) . ' home (' . BackupFetch::human((int)($r['bytes'] ?? 0)) . ')';
			if (!empty($r['kept'])) { $notes[] = $n($r['kept']) . ' already on disk'; }
			if (!empty($r['skipped'])) { $notes[] = $n($r['skipped']) . ' skipped'; }
		} else {
			$text = $files($r['wanted'] ?? 0) . ' to bring home';
			$settled = isset($r['skipped']) ? (int)$r['skipped'] : ((int)($r['served'] ?? 0) + (int)($r['local'] ?? 0) + (int)($r['no_row'] ?? 0));
			if ($settled > 0) { $notes[] = $n($settled) . ' need nothing: served by the file store, or already here'; }
			if (!empty($r['more'])) { $notes[] = 'the first ' . $n(count($r['want'] ?? array())) . ' named'; }
		}
		return $text . ($notes ? ' (' . implode('; ', $notes) . ')' : '');
	}
}
