<?php
/**
 * BackupObjects — the offloaded files a backup would otherwise not contain.
 *
 * Once a site's uploaded file is offloaded to the customer's file bucket its
 * bytes exist in exactly one place, and the tar the backup makes does not
 * carry it. This class puts every such file in backup storage once, while
 * it is still on the server's disk, encrypted and named by its immutable
 * stored name, and never moves it again:
 *
 *   {prefix}/{slug}/{profile}/objects/{epoch}/envelope.json   the epoch's sealed data key
 *   {prefix}/{slug}/{profile}/objects/{epoch}/{name}.enc      one object per offloaded blob
 *
 * and every run writes an INDEX — every cloud blob, with whether it is stored —
 * as an artifact of the run (kind `objects`, plain gzipped JSON like the
 * manifest), so restore point N restores the tar chain to N plus the objects
 * index N names, and retention keeps an object while any retained run names it.
 *
 * On the node, beside the profile's chain directories:
 *
 *   {profile output dir}/objects/epoch.json   the current epoch id and its envelope
 *   {profile output dir}/objects/held.json    what this profile's backup storage held as of its last run
 *   {profile output dir}/objects/enabled      manager profile only — a manager run carrying
 *                                             the object store has been here
 *   {profile output dir}/objects/tmp/         one object's ciphertext during an upload
 *
 * Local bytes are released only once every enabled profile's backup storage holds the
 * object (BackupProfile::enabled()); may_release() is that rule, pure.
 *
 * Objects are encrypted here, in PHP, in the same stock format as archives
 * (`Salted__` + 8-byte salt, PBKDF2-SHA256 at openssl's default count,
 * AES-256-CBC, PKCS7), so `openssl enc -d -aes-256-cbc -pbkdf2` opens an object
 * with the epoch key exactly as it opens an archive with a chain key.
 *
 * What is stored is read from backup storage, never from a node-side record alone:
 * the site profile lists its `objects/` prefix; the manager profile is handed
 * the newest index in its backup storage by link. held.json is a cache of that picture
 * plus what the tick stored since, rewritten whole by every run, and losing it
 * costs at most one run's worth of re-stores.
 *
 * Nothing here prints a key or a credential; the index and every result carry
 * names, sizes and hashes of ciphertext only.
 *
 * @version 1.2 - retired-epochs.json: a run writes down the epochs it found sealed to a retired recovery
 *                key that the site key cannot open (reseal_epochs() 'unopenable'), and
 *                retired_summary() turns that plus held.json into "N objects (X GB) open only with a
 *                retired recovery key" for Recovery Readiness — a stored fact, no shelf read on a page
 * @version 1.1.2 - destination() is public: the site's Bring them back (BackupObjectRestoreLauncher) signs
 *                  links under the same credential, bucket and base key the store step writes to
 * @version 1.1.1 - prune_site() deletes nothing when a retained run's index cannot be read
 * @version 1.1 - the manager profile: envelopes_from_links() reads epoch envelopes a request linked
 *                for the re-seal on rotation; mark_enabled()/clear_enabled() keep the marker that
 *                says a manager run carrying the object store has been here
 * @version 1.0.1 - a run's held.json rewrite keeps the entries the tick added while the run was
 *                  going; the epoch is minted after a catch-up fetch succeeds, not before
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/BackupEnvelope.php'));
require_once(PathHelper::getIncludePath('includes/BackupProfile.php'));
require_once(PathHelper::getIncludePath('includes/S3Signer.php'));

class BackupObjectsException extends Exception {}

class BackupObjects {

	/** Shelf prefix segment and node-side directory name. */
	const DIR = 'objects';
	const EPOCH_FILE = 'epoch.json';
	const HELD_FILE = 'held.json';
	const RETIRED_FILE = 'retired-epochs.json';
	const TMP_DIR = 'tmp';
	const ENVELOPE_NAME = 'envelope.json';
	const OBJECT_SUFFIX = '.enc';
	const EPOCH_PREFIX = 'epoch-';
	const INDEX_VERSION = 1;
	const HELD_VERSION = 1;

	/** Stock openssl `enc -salt -pbkdf2` format: header, salt length, iteration count. */
	const SALT_MAGIC = 'Salted__';
	const SALT_BYTES = 8;
	const PBKDF2_ITERATIONS = 10000;
	const CHUNK_BYTES = 1048576;

	/** A temporary older than this belongs to a run that is no longer running. */
	const TMP_MAX_AGE = 3600;

	/** The largest index a run will read back: ten thousand entries is ~150 kB gzipped. */
	const INDEX_MAX_BYTES = 33554432;

	/**
	 * Tests only. Keys:
	 *   site_plan   array   the plan the tick stores with, instead of BackupRunner::plan()
	 *   enumerator  callable(): array   the cloud objects, instead of the storage profiles
	 *   fetch       callable(url, sink): bool   fetches a link, instead of BackupFetch
	 *   catchup     CloudStorageDriver  the file-store driver, instead of the factory
	 */
	public static $test_hooks = array();

	// ------------------------------------------------------------ locations

	/** This profile's objects directory on the node, created on first use. */
	public static function dir(array $plan) {
		$dir = rtrim($plan['output_dir'], '/') . '/' . self::DIR;
		self::ensure_dir($dir);
		return $dir;
	}

	public static function tmp_dir(array $plan) {
		$dir = self::dir($plan) . '/' . self::TMP_DIR;
		self::ensure_dir($dir);
		return $dir;
	}

	/** held.json for a profile named by base directory, for the release rule across profiles. */
	public static function held_path_for($profile, $base_dir) {
		return BackupProfile::output_dir($profile, $base_dir) . '/' . self::DIR . '/' . self::HELD_FILE;
	}

	/**
	 * 2775, group www-data: the tick runs as the web user, a scheduled run as
	 * the web user, a shell run as the deploy account, and all three write here.
	 */
	private static function ensure_dir($dir) {
		if (is_dir($dir)) {
			return;
		}
		$old = umask(0002);
		@mkdir($dir, 02775, true);
		umask($old);
		if (!is_dir($dir)) {
			throw new BackupObjectsException('The objects directory ' . $dir . ' could not be created.');
		}
		@chmod($dir, 02775);
		if (function_exists('posix_getgrnam') && ($grp = @posix_getgrnam('www-data'))) {
			@chgrp($dir, $grp['gid']);
		}
	}

	/** Object name in backup storage, relative to the profile's base key. */
	public static function object_relname($epoch, $name) {
		return self::DIR . '/' . $epoch . '/' . $name . self::OBJECT_SUFFIX;
	}

	public static function envelope_relname($epoch) {
		return self::DIR . '/' . $epoch . '/' . self::ENVELOPE_NAME;
	}

	// --------------------------------------------------------------- cipher

	/**
	 * Encrypt $src to $dst in the stock openssl format, streaming a megabyte at
	 * a time. Returns ['bytes', 'sha256'] of the ciphertext — what the index
	 * records and what a fetched object is checked against.
	 */
	public static function encrypt_file($src, $dst, $data_key) {
		$in = @fopen($src, 'rb');
		if (!$in) {
			throw new BackupObjectsException('Cannot read ' . basename($src) . ' to encrypt it.');
		}
		$old = umask(0077);
		$out = @fopen($dst, 'wb');
		umask($old);
		if (!$out) {
			fclose($in);
			throw new BackupObjectsException('Cannot write the encrypted object to ' . basename($dst) . '.');
		}
		$hash = hash_init('sha256');
		$bytes = 0;
		$emit = function ($chunk) use ($out, $hash, &$bytes) {
			if ($chunk === '') { return; }
			if (fwrite($out, $chunk) !== strlen($chunk)) {
				throw new BackupObjectsException('Short write while encrypting an object.');
			}
			hash_update($hash, $chunk);
			$bytes += strlen($chunk);
		};

		try {
			$salt = random_bytes(self::SALT_BYTES);
			list($key, $iv) = self::derive($data_key, $salt);
			$emit(self::SALT_MAGIC . $salt);

			// Every chunk but the last is a whole number of blocks, encrypted
			// without padding and chained by hand: the next chunk's IV is the
			// last block of this one. The final chunk carries the PKCS7 padding,
			// so the whole stream is exactly what one openssl_encrypt() of the
			// file would have produced — and what `openssl enc` produces.
			$pending = '';
			while (!feof($in)) {
				$read = fread($in, self::CHUNK_BYTES);
				if ($read === false) {
					throw new BackupObjectsException('Read failed while encrypting ' . basename($src) . '.');
				}
				$pending .= $read;
				$whole = strlen($pending) - (strlen($pending) % 16);
				if ($whole > 0 && !feof($in)) {
					$block = substr($pending, 0, $whole);
					$pending = (string)substr($pending, $whole);
					$ct = openssl_encrypt($block, 'aes-256-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
					if ($ct === false) {
						throw new BackupObjectsException('Encryption failed.');
					}
					$iv = substr($ct, -16);
					$emit($ct);
				}
			}
			$ct = openssl_encrypt($pending, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
			if ($ct === false) {
				throw new BackupObjectsException('Encryption failed.');
			}
			$emit($ct);
		} catch (\Throwable $e) {
			fclose($in);
			fclose($out);
			@unlink($dst);
			throw $e;
		}
		fclose($in);
		if (!fclose($out)) {
			@unlink($dst);
			throw new BackupObjectsException('Could not finish writing the encrypted object.');
		}
		return array('bytes' => $bytes, 'sha256' => hash_final($hash));
	}

	/**
	 * Decrypt an object written by encrypt_file() or by `openssl enc -aes-256-cbc
	 * -salt -pbkdf2`. Returns the plaintext byte count. A wrong key or a
	 * damaged object fails on the final block's padding and throws.
	 */
	public static function decrypt_file($src, $dst, $data_key) {
		$in = @fopen($src, 'rb');
		if (!$in) {
			throw new BackupObjectsException('Cannot read ' . basename($src) . ' to decrypt it.');
		}
		$header = fread($in, strlen(self::SALT_MAGIC) + self::SALT_BYTES);
		if (!is_string($header) || strlen($header) !== 16 || substr($header, 0, 8) !== self::SALT_MAGIC) {
			fclose($in);
			throw new BackupObjectsException(basename($src) . ' is not an encrypted object (no Salted__ header).');
		}
		list($key, $iv) = self::derive($data_key, substr($header, 8));

		$old = umask(0077);
		$out = @fopen($dst, 'wb');
		umask($old);
		if (!$out) {
			fclose($in);
			throw new BackupObjectsException('Cannot write the decrypted object to ' . basename($dst) . '.');
		}
		$bytes = 0;
		try {
			// Hold the last block back until EOF: it is the only one whose
			// padding is removed.
			$pending = '';
			while (!feof($in)) {
				$read = fread($in, self::CHUNK_BYTES);
				if ($read === false) {
					throw new BackupObjectsException('Read failed while decrypting ' . basename($src) . '.');
				}
				$pending .= $read;
				if (feof($in)) { break; }
				$whole = strlen($pending) - (strlen($pending) % 16) - 16;
				if ($whole > 0) {
					$block = substr($pending, 0, $whole);
					$pending = (string)substr($pending, $whole);
					$pt = openssl_decrypt($block, 'aes-256-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
					if ($pt === false) {
						throw new BackupObjectsException('Decryption failed.');
					}
					$iv = substr($block, -16);
					fwrite($out, $pt);
					$bytes += strlen($pt);
				}
			}
			if (strlen($pending) < 16 || strlen($pending) % 16 !== 0) {
				throw new BackupObjectsException(basename($src) . ' is truncated: its length is not a whole number of blocks.');
			}
			$pt = openssl_decrypt($pending, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
			if ($pt === false) {
				throw new BackupObjectsException('That key does not open ' . basename($src) . ', or the object is damaged.');
			}
			fwrite($out, $pt);
			$bytes += strlen($pt);
		} catch (\Throwable $e) {
			fclose($in);
			fclose($out);
			@unlink($dst);
			throw $e;
		}
		fclose($in);
		fclose($out);
		return $bytes;
	}

	/** Key and IV the way `openssl enc -pbkdf2` derives them: one PBKDF2 call, 48 bytes, split. */
	private static function derive($data_key, $salt) {
		$material = hash_pbkdf2('sha256', (string)$data_key, $salt, self::PBKDF2_ITERATIONS, 48, true);
		return array(substr($material, 0, 32), substr($material, 32, 16));
	}

	// ---------------------------------------------------------------- epoch

	/** Epoch id for an epoch starting at this UTC time. */
	public static function epoch_id($utc = null) {
		$utc = $utc ?: gmdate('Y-m-d H:i:s');
		return self::EPOCH_PREFIX . gmdate('Ymd_His', strtotime($utc . ' UTC'));
	}

	/**
	 * Why a new epoch starts, or '' to keep the current one. Pure: the stored
	 * epoch record, the current recovery fingerprint, and whether the site key
	 * opens the stored envelope are all passed in.
	 *
	 *   none                 no epoch yet
	 *   recovery_rotated     the epoch's envelope is sealed to a recovery key
	 *                        that is no longer this site's — the same test a
	 *                        chain applies (BackupChain::should_start_new)
	 *   envelope_unopenable  the site key cannot open it (lost or re-minted) —
	 *                        degrade to a new epoch, never fail every run
	 */
	public static function epoch_decision(?array $stored, $current_fpr, $opens) {
		if (!$stored || empty($stored['id']) || empty($stored['envelope']['recipients'])) {
			return 'none';
		}
		if ($current_fpr !== null && $current_fpr !== '') {
			$epoch_fpr = '';
			foreach ($stored['envelope']['recipients'] as $r) {
				if (($r['kind'] ?? '') === 'recovery') {
					$epoch_fpr = (string)($r['fingerprint'] ?? '');
				}
			}
			if (!hash_equals((string)$current_fpr, $epoch_fpr)) {
				return 'recovery_rotated';
			}
		}
		if (!$opens) {
			return 'envelope_unopenable';
		}
		return '';
	}

	/** The node's record of the current epoch, or null. */
	public static function read_epoch(array $plan) {
		$path = self::dir($plan) . '/' . self::EPOCH_FILE;
		if (!is_file($path)) {
			return null;
		}
		$data = json_decode((string)@file_get_contents($path), true);
		return (is_array($data) && !empty($data['id']) && is_array($data['envelope'] ?? null)) ? $data : null;
	}

	/**
	 * The current epoch: its id and its data key, minting a new one when the
	 * rules say so. The envelope goes to backup storage BEFORE the node records the
	 * epoch, so an epoch the node names is always one backup storage can open.
	 *
	 * Returns ['id', 'data_key', 'envelope', 'reason'] — reason is '' when the
	 * epoch was kept.
	 */
	public static function epoch(array $plan) {
		$stored = self::read_epoch($plan);
		$data_key = null;
		$opens = false;
		if ($stored) {
			try {
				$data_key = BackupEnvelope::open_as_site($stored['envelope']);
				$opens = true;
			} catch (\Throwable $e) {
				error_log('BackupObjects: the epoch envelope did not open with the site key (' . $e->getMessage() . '); starting a new epoch.');
			}
		}
		$reason = self::epoch_decision($stored, (string)($plan['recovery_fpr'] ?? ''), $opens);
		if ($reason === '') {
			return array('id' => $stored['id'], 'data_key' => $data_key, 'envelope' => $stored['envelope'], 'reason' => '');
		}
		if ($reason === 'recovery_rotated') {
			error_log('BackupObjects: the recovery key changed since epoch ' . $stored['id'] . ' started; starting a new epoch sealed to the current key.');
		}

		$id   = self::epoch_id();
		if ($stored && $stored['id'] === $id) {
			// Two epochs in one second cannot share a name.
			$id = self::epoch_id(gmdate('Y-m-d H:i:s', time() + 1));
		}
		$mint = BackupEnvelope::mint($id, $plan['recipients']);
		self::upload_envelope($plan, $id, $mint['envelope']);
		self::write_epoch($plan, $id, $mint['envelope']);
		return array('id' => $id, 'data_key' => $mint['data_key'], 'envelope' => $mint['envelope'], 'reason' => $reason);
	}

	private static function write_epoch(array $plan, $id, array $envelope) {
		$path = self::dir($plan) . '/' . self::EPOCH_FILE;
		$json = json_encode(array('id' => $id, 'envelope' => $envelope), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		$tmp = $path . '.' . getmypid() . '.tmp';
		if (@file_put_contents($tmp, $json . "\n") === false || !@rename($tmp, $path)) {
			@unlink($tmp);
			throw new BackupObjectsException('Could not record the epoch at ' . $path . '.');
		}
		@chmod($path, 0664);
	}

	/** Put an epoch envelope in backup storage (a new epoch, or one re-sealed on rotation). */
	private static function upload_envelope(array $plan, $id, array $envelope) {
		$tmp = self::tmp_dir($plan) . '/' . $id . '-' . self::ENVELOPE_NAME . '.' . getmypid();
		if (@file_put_contents($tmp, BackupEnvelope::encode($envelope)) === false) {
			throw new BackupObjectsException('Could not write the epoch envelope for upload.');
		}
		try {
			self::put($plan, self::envelope_relname($id), $tmp, 'application/json');
		} finally {
			@unlink($tmp);
		}
	}

	/**
	 * Site profile, after a recovery-key rotation: every epoch envelope on the
	 * shelf sealed to another recovery key, and openable with the site key, is
	 * re-sealed to the current recipients and uploaded again under the same
	 * name — the same data key, one more recipient. Nothing is re-encrypted.
	 * Epochs the site key cannot open are returned under 'unopenable' for
	 * Recovery Readiness to report; nothing is re-copied automatically.
	 *
	 * @param array $envelopes epoch id => decoded envelope, as read from backup storage
	 */
	public static function reseal_epochs(array $plan, array $envelopes) {
		$current = (string)($plan['recovery_fpr'] ?? '');
		$resealed = array();
		$unopenable = array();
		foreach ($envelopes as $id => $envelope) {
			$fpr = '';
			foreach (($envelope['recipients'] ?? array()) as $r) {
				if (($r['kind'] ?? '') === 'recovery') { $fpr = (string)($r['fingerprint'] ?? ''); }
			}
			if ($current === '' || hash_equals($current, $fpr)) {
				continue;
			}
			try {
				$data_key = BackupEnvelope::open_as_site($envelope);
			} catch (\Throwable $e) {
				$unopenable[] = (string)$id;
				continue;
			}
			try {
				$rebuilt = BackupEnvelope::build($data_key, (string)$id, $plan['recipients']);
				self::upload_envelope($plan, (string)$id, $rebuilt);
				$resealed[] = (string)$id;
				$stored = self::read_epoch($plan);
				if ($stored && $stored['id'] === (string)$id) {
					self::write_epoch($plan, (string)$id, $rebuilt);
				}
			} catch (\Throwable $e) {
				error_log('BackupObjects: could not re-seal epoch ' . $id . ': ' . $e->getMessage());
			}
		}
		return array('resealed' => $resealed, 'unopenable' => $unopenable);
	}

	/** Fetch and decode every epoch envelope the site's backup storage lists. */
	public static function envelopes_site(array $plan, array $shelf) {
		$out = array();
		list($creds, $bucket, $base) = self::destination($plan);
		foreach (array_keys($shelf['envelopes'] ?? array()) as $id) {
			$tmp = self::tmp_dir($plan) . '/env-' . getmypid();
			try {
				$resp = S3Signer::get_to_file($creds, $bucket, '/' . ltrim($base . self::envelope_relname($id), '/'), $tmp);
				if ((int)($resp['status'] ?? 0) === 200) {
					$out[$id] = BackupEnvelope::read_sidecar($tmp);
				}
			} catch (\Throwable $e) {
				error_log('BackupObjects: could not read the envelope of epoch ' . $id . ': ' . $e->getMessage());
			} finally {
				@unlink($tmp);
			}
		}
		return $out;
	}

	/** Fetch and decode epoch envelopes by link — the manager profile's way of reading them. */
	public static function envelopes_from_links(array $plan, array $links) {
		$out = array();
		foreach ($links as $id => $url) {
			$tmp = self::tmp_dir($plan) . '/env-' . getmypid();
			try {
				if (isset(self::$test_hooks['fetch'])) {
					$ok = (bool)call_user_func(self::$test_hooks['fetch'], $url, $tmp);
				} else {
					$got = BackupFetch::fetch($url, $tmp, 65536);
					$ok = !empty($got['ok']);
				}
				if ($ok) {
					$out[(string)$id] = BackupEnvelope::read_sidecar($tmp);
				} else {
					error_log('BackupObjects: the envelope link of epoch ' . $id . ' could not be fetched.');
				}
			} catch (\Throwable $e) {
				error_log('BackupObjects: could not read the envelope of epoch ' . $id . ': ' . $e->getMessage());
			} finally {
				@unlink($tmp);
			}
		}
		return $out;
	}

	/**
	 * The manager profile's enabled marker. Written by the first manager run
	 * whose request carried the object store; until it exists the manager
	 * profile does not hold bytes, so a node whose management node has not
	 * been upgraded behaves as it always did. Leaving the management node
	 * removes it.
	 */
	public static function mark_enabled(array $plan) {
		$path = self::dir($plan) . '/' . basename(BackupProfile::OBJECTS_ENABLED_MARKER);
		if (!is_file($path)) {
			@file_put_contents($path, gmdate('Y-m-d\TH:i:s\Z') . "\n");
			@chmod($path, 0664);
		}
	}

	public static function clear_enabled($base_dir) {
		$path = BackupProfile::output_dir(BackupProfile::MANAGER, $base_dir) . '/' . BackupProfile::OBJECTS_ENABLED_MARKER;
		if (is_file($path)) {
			@unlink($path);
		}
	}

	// ------------------------------------------------------ what is held

	/**
	 * Read the objects/ prefix of the site's backup storage into backup storage picture:
	 * ['objects' => name => ['epoch', 'object_bytes', 'last_modified'],
	 *  'envelopes' => epoch id => true]. One request per thousand objects.
	 */
	public static function listing_site(array $plan) {
		list($creds, $bucket, $base) = self::destination($plan);
		$prefix = $base . self::DIR . '/';
		return self::parse_listing(S3Signer::list($creds, $bucket, ltrim($prefix, '/')), ltrim($prefix, '/'));
	}

	/** Backup storage picture from a raw listing. Pure. Anything not objects/{epoch}/{name} is ignored. */
	public static function parse_listing(array $objects, $prefix) {
		$out = array('objects' => array(), 'envelopes' => array());
		$prefix = ltrim((string)$prefix, '/');
		foreach ($objects as $obj) {
			$key = ltrim((string)($obj['key'] ?? ''), '/');
			if ($prefix !== '' && strpos($key, $prefix) !== 0) { continue; }
			$rel = substr($key, strlen($prefix));
			$parts = explode('/', $rel);
			if (count($parts) !== 2 || strpos($parts[0], self::EPOCH_PREFIX) !== 0 || $parts[1] === '') { continue; }
			list($epoch, $file) = $parts;
			if ($file === self::ENVELOPE_NAME) {
				$out['envelopes'][$epoch] = true;
				continue;
			}
			if (substr($file, -strlen(self::OBJECT_SUFFIX)) !== self::OBJECT_SUFFIX) { continue; }
			$name = substr($file, 0, -strlen(self::OBJECT_SUFFIX));
			$out['objects'][$name] = array(
				'epoch'         => $epoch,
				'object_bytes'  => (int)($obj['size'] ?? 0),
				'last_modified' => (string)($obj['last_modified'] ?? ''),
			);
		}
		return $out;
	}

	/**
	 * The held set an index describes: every entry it marks stored, keyed by
	 * name, with epoch, bytes and hash. Pure.
	 */
	public static function held_from_index(?array $index) {
		$out = array();
		foreach (($index['objects'] ?? array()) as $e) {
			if (empty($e['stored']) || empty($e['name'])) { continue; }
			$out[(string)$e['name']] = array(
				'epoch'         => (string)($e['epoch'] ?? ''),
				'object_bytes'  => (int)($e['object_bytes'] ?? 0),
				'object_sha256' => (string)($e['object_sha256'] ?? ''),
			);
		}
		return $out;
	}

	/**
	 * What backup storage holds, for this run: names with epoch, size and hash.
	 *
	 * Site profile: the listing is the authority on presence and size; hashes
	 * come from the newest index in backup storage (fetched by key) and from
	 * held.json (what the tick stored since). An object the listing shows with
	 * no hash from either source is reported under 'unhashed' — the run
	 * re-stores it if it can, which costs one upload and is the price of a
	 * lost record, never the store.
	 *
	 * Manager profile: the newest manager index, by link, union held.json.
	 * No link means nothing is held.
	 *
	 * @param string $previous_index_key full bucket key of the newest index this
	 *                                   profile committed, or '' when none is known
	 * @return array ['held' => name => entry, 'shelf' => listing picture|null,
	 *                'unhashed' => [names], 'index_read' => bool,
	 *                'file' => name => entry, held.json as it was read]
	 */
	public static function held(array $plan, $previous_index_key = '') {
		$held_file = self::read_held($plan) ?: array();
		$from_index = array();
		$index_read = false;
		$shelf = null;

		if (($plan['objects_source'] ?? 'listing') === 'index') {
			$url = (string)($plan['objects_index_url'] ?? '');
			if ($url !== '') {
				$index = self::fetch_index_url($plan, $url);
				if ($index !== null) {
					$from_index = self::held_from_index($index);
					$index_read = true;
				}
			}
			$held = $from_index + $held_file;
			return array('held' => $held, 'shelf' => null, 'unhashed' => array(), 'index_read' => $index_read, 'file' => $held_file);
		}

		$shelf = self::listing_site($plan);
		if ($previous_index_key !== '') {
			$index = self::fetch_index_key($plan, $previous_index_key);
			if ($index !== null) {
				$from_index = self::held_from_index($index);
				$index_read = true;
			}
		}
		$known = $held_file + $from_index;
		$held = array();
		$unhashed = array();
		foreach ($shelf['objects'] as $name => $seen) {
			$entry = $known[$name] ?? null;
			if ($entry && $entry['object_sha256'] !== '' && (int)$entry['object_bytes'] === (int)$seen['object_bytes']
				&& $entry['epoch'] === $seen['epoch']) {
				$held[$name] = $entry;
				continue;
			}
			// Present, but its hash is unknown or its record disagrees with
			// what is there: hold it by what backup storage says, hashless, and let
			// the run replace it where it can.
			$held[$name] = array('epoch' => $seen['epoch'], 'object_bytes' => (int)$seen['object_bytes'], 'object_sha256' => '');
			$unhashed[] = $name;
		}
		return array('held' => $held, 'shelf' => $shelf, 'unhashed' => $unhashed, 'index_read' => $index_read, 'file' => $held_file);
	}

	/** held.json, or null when the profile has none. */
	public static function read_held(array $plan) {
		return self::read_held_file(self::dir($plan) . '/' . self::HELD_FILE);
	}

	public static function read_held_file($path) {
		if (!is_file($path)) {
			return null;
		}
		$data = json_decode((string)@file_get_contents($path), true);
		if (!is_array($data) || !is_array($data['objects'] ?? null)) {
			return null;
		}
		$out = array();
		foreach ($data['objects'] as $name => $e) {
			$out[(string)$name] = array(
				'epoch'         => (string)($e['epoch'] ?? ''),
				'object_bytes'  => (int)($e['object_bytes'] ?? 0),
				'object_sha256' => (string)($e['object_sha256'] ?? ''),
			);
		}
		return $out;
	}

	/**
	 * Rewrite held.json — what a run does at its end — from what the run held
	 * and stored. The tick keeps storing while a run is going, and each of
	 * its stores adds an entry the run's picture of backup storage predates; those
	 * entries are kept. $seen_names is what the file named when the run read
	 * it: an entry the file has now that was not there then is the tick's,
	 * and goes in; anything the run's set lacks that WAS there then is
	 * dropped, which is what a rewrite is for.
	 */
	public static function write_held(array $plan, array $held, array $seen_names = array()) {
		$path = self::dir($plan) . '/' . self::HELD_FILE;
		$seen = array_fill_keys(array_map('strval', $seen_names), true);
		self::with_held_lock($path, function () use ($path, $held, $seen) {
			foreach ((self::read_held_file($path) ?: array()) as $name => $entry) {
				if (!isset($held[$name]) && !isset($seen[$name])) {
					$held[$name] = $entry;
				}
			}
			self::write_held_file($path, $held);
		});
	}

	/** Add one entry — what the tick does after a store, under the same lock the run writes under. */
	public static function held_add(array $plan, $name, array $entry) {
		$path = self::dir($plan) . '/' . self::HELD_FILE;
		self::with_held_lock($path, function () use ($path, $name, $entry) {
			$held = self::read_held_file($path) ?: array();
			$held[(string)$name] = array(
				'epoch'         => (string)$entry['epoch'],
				'object_bytes'  => (int)$entry['bytes'],
				'object_sha256' => (string)$entry['sha256'],
			);
			self::write_held_file($path, $held);
		});
	}

	private static function write_held_file($path, array $held) {
		ksort($held);
		$json = json_encode(array('version' => self::HELD_VERSION, 'updated' => gmdate('Y-m-d\TH:i:s\Z'), 'objects' => $held),
			JSON_UNESCAPED_SLASHES);
		$tmp = $path . '.' . getmypid() . '.tmp';
		if (@file_put_contents($tmp, $json . "\n") === false || !@rename($tmp, $path)) {
			@unlink($tmp);
			throw new BackupObjectsException('Could not write ' . $path . '.');
		}
		@chmod($path, 0664);
	}

	private static function with_held_lock($path, callable $fn) {
		$lock = @fopen($path . '.lock', 'c');
		if ($lock) { @chmod($path . '.lock', 0664); flock($lock, LOCK_EX); }
		try {
			$fn();
		} finally {
			if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
		}
	}

	// ------------------------------------------------------------- retired

	/**
	 * A run that examined the epoch envelopes writes down the ones it could not
	 * re-seal: sealed to a recovery key that is no longer this site's, and not
	 * openable with the site key either. Those objects open only with the
	 * retired key, and nothing re-copies them; Recovery Readiness says so from
	 * this file rather than by reading backup storage on a page load. An empty list
	 * is written too — the fact that every epoch opens is a fact.
	 */
	public static function write_retired_epochs(array $plan, array $unopenable) {
		$path = self::dir($plan) . '/' . self::RETIRED_FILE;
		$epochs = array_values(array_unique(array_map('strval', $unopenable)));
		sort($epochs);
		$json = json_encode(array('version' => 1, 'noted' => gmdate('Y-m-d\TH:i:s\Z'), 'epochs' => $epochs), JSON_UNESCAPED_SLASHES);
		$tmp = $path . '.' . getmypid() . '.tmp';
		if (@file_put_contents($tmp, $json . "\n") === false || !@rename($tmp, $path)) {
			@unlink($tmp);
			throw new BackupObjectsException('Could not write ' . $path . '.');
		}
		@chmod($path, 0664);
	}

	/** retired-epochs.json of a profile named by base directory. */
	public static function retired_path_for($profile, $base_dir) {
		return BackupProfile::output_dir($profile, $base_dir) . '/' . self::DIR . '/' . self::RETIRED_FILE;
	}

	/** The epoch ids a retired-epochs.json names, or array() when there is none. */
	public static function read_retired_file($path) {
		if (!is_file($path)) {
			return array();
		}
		$data = json_decode((string)@file_get_contents($path), true);
		return (is_array($data) && is_array($data['epochs'] ?? null)) ? array_map('strval', $data['epochs']) : array();
	}

	/**
	 * For Recovery Readiness: across these profiles, the epochs only a retired
	 * recovery key opens and what sits in them — objects and bytes, from each
	 * profile's held.json. Pure over two files per profile.
	 *
	 * @return array ['epochs' => [ids], 'count' => int, 'bytes' => int]
	 */
	public static function retired_summary(array $profiles, $base_dir) {
		$out = array('epochs' => array(), 'count' => 0, 'bytes' => 0);
		foreach ($profiles as $profile) {
			$retired = self::read_retired_file(self::retired_path_for($profile, $base_dir));
			if (!$retired) { continue; }
			$set = array_fill_keys($retired, true);
			foreach ($retired as $id) { $out['epochs'][$id] = true; }
			foreach ((array)self::read_held_file(self::held_path_for($profile, $base_dir)) as $e) {
				if (isset($set[(string)($e['epoch'] ?? '')])) {
					$out['count']++;
					$out['bytes'] += (int)($e['object_bytes'] ?? 0);
				}
			}
		}
		$out['epochs'] = array_keys($out['epochs']);
		sort($out['epochs']);
		return $out;
	}

	// ---------------------------------------------------------------- index

	/** Read an index off a signed link (manager profile). Null when it cannot be read. */
	private static function fetch_index_url(array $plan, $url) {
		$tmp = self::tmp_dir($plan) . '/index-' . getmypid() . '.json.gz';
		try {
			if (isset(self::$test_hooks['fetch'])) {
				$ok = (bool)call_user_func(self::$test_hooks['fetch'], $url, $tmp);
			} else {
				$got = BackupFetch::fetch($url, $tmp, self::INDEX_MAX_BYTES);
				$ok = !empty($got['ok']);
				if (!$ok) { error_log('BackupObjects: the objects index link could not be fetched: ' . ($got['error'] ?? '')); }
			}
			return $ok ? self::read_index_file($tmp) : null;
		} catch (\Throwable $e) {
			error_log('BackupObjects: the objects index could not be read: ' . $e->getMessage());
			return null;
		} finally {
			@unlink($tmp);
		}
	}

	/** Read an index off the site's backup storage by key. Null when it is not there. */
	public static function fetch_index_key(array $plan, $key) {
		list($creds, $bucket) = self::destination($plan);
		$tmp = self::tmp_dir($plan) . '/index-' . getmypid() . '.json.gz';
		try {
			$resp = S3Signer::get_to_file($creds, $bucket, '/' . ltrim($key, '/'), $tmp);
			if ((int)($resp['status'] ?? 0) !== 200) {
				return null;
			}
			return self::read_index_file($tmp);
		} catch (\Throwable $e) {
			error_log('BackupObjects: the previous objects index could not be read: ' . $e->getMessage());
			return null;
		} finally {
			@unlink($tmp);
		}
	}

	/** Decode a gzipped index file. Throws when it is not one. */
	public static function read_index_file($path) {
		$raw = @file_get_contents($path);
		if ($raw === false) {
			throw new BackupObjectsException('Could not read the objects index at ' . basename($path) . '.');
		}
		return self::decode_index($raw, basename($path));
	}

	/** Decode gzipped index bytes. Throws when they are not an index. */
	public static function decode_index($raw, $label = 'the objects index') {
		$json = @gzdecode((string)$raw);
		if ($json === false) {
			throw new BackupObjectsException($label . ' is not a gzipped objects index.');
		}
		$data = json_decode($json, true);
		if (!is_array($data) || (int)($data['version'] ?? 0) !== self::INDEX_VERSION || !is_array($data['objects'] ?? null)) {
			throw new BackupObjectsException($label . ' is not a readable objects index.');
		}
		return $data;
	}

	/**
	 * The index: every cloud blob, with whether it is stored. Pure.
	 *
	 * @param array $objects the enumerated cloud objects
	 * @param array $held    name => entry, what backup storage held before this run
	 * @param array $stored  name => entry, what this run stored
	 */
	public static function build_index(array $plan, $run_label, array $objects, array $held, array $stored) {
		$entries = array();
		$epochs = array();
		foreach ($objects as $obj) {
			$name = (string)$obj['name'];
			$e = $stored[$name] ?? $held[$name] ?? null;
			$entries[] = array(
				'name'          => $name,
				'epoch'         => $e ? (string)$e['epoch'] : '',
				'object_bytes'  => $e ? (int)$e['object_bytes'] : 0,
				'object_sha256' => $e ? (string)$e['object_sha256'] : '',
				'stored'        => (bool)$e,
			);
			if ($e && $e['epoch'] !== '') { $epochs[(string)$e['epoch']] = true; }
		}
		ksort($epochs);
		return array(
			'version' => self::INDEX_VERSION,
			'profile' => (string)$plan['profile'],
			'run'     => (string)$run_label,
			'created' => gmdate('Y-m-d\TH:i:s\Z'),
			'epochs'  => array_keys($epochs),
			'objects' => $entries,
		);
	}

	/** Write an index as a gzipped artifact and describe it for the manifest. */
	public static function write_index(array $index, $path) {
		$json = json_encode($index, JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			throw new BackupObjectsException('Could not encode the objects index.');
		}
		$gz = gzencode($json . "\n", 6);
		$tmp = $path . '.' . getmypid() . '.tmp';
		$old = umask(0077);
		$ok = @file_put_contents($tmp, $gz);
		umask($old);
		if ($ok === false || !@rename($tmp, $path)) {
			@unlink($tmp);
			throw new BackupObjectsException('Could not write the objects index to ' . $path . '.');
		}
		@chmod($path, 0600);
		return array(
			'name'   => basename($path),
			'path'   => $path,
			'bytes'  => (int)filesize($path),
			'sha256' => hash_file('sha256', $path),
			'kind'   => 'objects',
		);
	}

	/** Every stored entry of an index, keyed by name. Pure. */
	public static function index_entries(array $index) {
		return self::held_from_index($index);
	}

	// ------------------------------------------------------------ enumerate

	/**
	 * Every offloaded blob, from every storage profile that can describe its
	 * rows for backup. Each: id, name, original (the local path the original
	 * occupies or would), paths (every local path original and variants use),
	 * remote_key, content_type, visibility.
	 */
	public static function cloud_objects() {
		if (isset(self::$test_hooks['enumerator'])) {
			return (array)call_user_func(self::$test_hooks['enumerator']);
		}
		$out = array();
		foreach (StorageProfileRegistry::all() as $profile) {
			if (!method_exists($profile, 'backupObjects')) { continue; }
			foreach ($profile->backupObjects() as $obj) {
				$out[] = $obj;
			}
		}
		return $out;
	}

	/**
	 * The archive's exclude list: every local path of every cloud blob,
	 * relative to the project directory, one per line. Pure. Paths outside the
	 * project directory cannot be in the archive and are left out.
	 */
	public static function exclude_lines(array $objects, $project_dir) {
		$root = rtrim((string)$project_dir, '/') . '/';
		$lines = array();
		foreach ($objects as $obj) {
			foreach (($obj['paths'] ?? array()) as $path) {
				$path = (string)$path;
				if (strpos($path, $root) !== 0) { continue; }
				$rel = substr($path, strlen($root));
				if ($rel !== '' && strpos($rel, "\n") === false) {
					$lines[$rel] = true;
				}
			}
		}
		return array_keys($lines);
	}

	/** Write the exclude file for the files engine; '' when there is nothing to exclude. */
	public static function write_exclude_file(array $plan, array $objects, $project_dir) {
		$lines = self::exclude_lines($objects, $project_dir);
		if (!$lines) {
			return '';
		}
		$path = self::dir($plan) . '/.exclude-' . getmypid();
		if (@file_put_contents($path, implode("\n", $lines) . "\n") === false) {
			throw new BackupObjectsException('Could not write the archive exclude list at ' . $path . '.');
		}
		@chmod($path, 0640);
		return $path;
	}

	// ---------------------------------------------------------------- store

	/**
	 * Put one object in backup storage: under the offload engine's per-row lock,
	 * encrypt the source with the epoch key to objects/tmp, upload it, unlink
	 * the ciphertext. Returns ['epoch', 'bytes', 'sha256'], or null when the
	 * row's lock is held by someone else (the tick is pushing it right now —
	 * the next run takes it).
	 *
	 * The lock is what keeps two encryptions of one file — salted differently
	 * — from both being uploaded, the later PUT winning in backup storage while the
	 * index recorded the earlier hash. Advisory locks are re-entrant within a
	 * session, so the tick, which already holds its row's lock, takes it again
	 * here without waiting.
	 */
	public static function store_object(array $plan, array $epoch, array $obj, $source) {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare('SELECT pg_try_advisory_lock(:k1, :k2) AS got');
		$q->execute(array(':k1' => CloudOffloadEngine::ADVISORY_LOCK_NAMESPACE, ':k2' => (int)$obj['id']));
		$got = $q->fetch(PDO::FETCH_ASSOC);
		if (empty($got['got'])) {
			return null;
		}
		$tmp = self::tmp_dir($plan) . '/' . $obj['name'] . self::OBJECT_SUFFIX;
		try {
			$enc = self::encrypt_file($source, $tmp, $epoch['data_key']);
			self::put($plan, self::object_relname($epoch['id'], $obj['name']), $tmp);
			return array('epoch' => $epoch['id'], 'bytes' => (int)$enc['bytes'], 'sha256' => $enc['sha256']);
		} finally {
			@unlink($tmp);
			$u = $db->prepare('SELECT pg_advisory_unlock(:k1, :k2)');
			$u->execute(array(':k1' => CloudOffloadEngine::ADVISORY_LOCK_NAMESPACE, ':k2' => (int)$obj['id']));
		}
	}

	/**
	 * The run's store step: every cloud blob not held, one at a time, inside
	 * one budget of bytes and seconds. Local originals first; then blobs with
	 * no local bytes, fetched one at a time from the file store (catch-up for
	 * files offloaded before the store existed). What the budget leaves is
	 * indexed `stored: false` and taken next run.
	 *
	 * One object failing to store is logged and left for the next run. Every
	 * shelf write failing is not one bad object, it is a shelf that cannot be
	 * written, and the run fails so that it is seen. A catch-up fetch failing
	 * — the file store unreachable, or an object it no longer has — is a fact
	 * about the file store, counted and reported, never a reason to fail the
	 * backup of everything else.
	 *
	 * @param callable|null $epoch_fn returns the epoch on first need, so a run
	 *                                with nothing to store mints none
	 * @return array ['stored' => name => entry, 'attempted' => n, 'failed' => n,
	 *                'fetch_failed' => n, 'busy' => n, 'budget_hit' => bool,
	 *                'bytes' => n, 'catchup_left' => n]
	 */
	public static function store_missing(array $plan, callable $epoch_fn, array $objects, array $held,
	                                     $budget_bytes, $budget_seconds, array $replace = array()) {
		$replace = array_fill_keys($replace, true);
		$local = array();
		$remote = array();
		foreach ($objects as $obj) {
			$name = (string)$obj['name'];
			if (isset($held[$name]) && !isset($replace[$name])) { continue; }
			if (!empty($obj['original']) && is_file($obj['original'])) {
				$local[] = $obj;
			} else {
				$remote[] = $obj;
			}
		}

		$result = array('stored' => array(), 'attempted' => 0, 'failed' => 0, 'fetch_failed' => 0, 'busy' => 0,
			'budget_hit' => false, 'bytes' => 0, 'catchup_left' => 0);
		if (!$local && !$remote) {
			return $result;
		}
		$epoch = null;
		$started = time();
		$queue = array_merge(
			array_map(function ($o) { return array($o, true); }, $local),
			array_map(function ($o) { return array($o, false); }, $remote));

		foreach ($queue as $i => $item) {
			list($obj, $is_local) = $item;
			if ($result['bytes'] >= $budget_bytes || (time() - $started) >= $budget_seconds) {
				$result['budget_hit'] = true;
				$result['catchup_left'] += $is_local ? 0 : 1;
				continue;
			}
			$result['attempted']++;
			$plain_tmp = '';
			if (!$is_local) {
				$plain_tmp = self::tmp_dir($plan) . '/' . $obj['name'] . '.plain';
				try {
					self::fetch_from_store($obj, $plain_tmp);
				} catch (\Throwable $e) {
					@unlink($plain_tmp);
					$result['fetch_failed']++;
					$result['catchup_left']++;
					error_log('BackupObjects: could not fetch ' . $obj['name'] . ' from the file store to copy it to backup storage: ' . $e->getMessage());
					continue;
				}
			}
			// The epoch is minted only once there is a plaintext to encrypt:
			// a run whose every catch-up fetch fails leaves no envelope-only
			// epoch in backup storage. A failure here is backup storage refusing the
			// envelope, and it propagates: the run fails, as any shelf write
			// failing before the first object does.
			if ($epoch === null) {
				try {
					$epoch = $epoch_fn();
				} finally {
					if ($epoch === null && $plain_tmp !== '') { @unlink($plain_tmp); }
				}
			}
			try {
				$r = self::store_object($plan, $epoch, $obj, $is_local ? $obj['original'] : $plain_tmp);
				if ($r === null) {
					$result['busy']++;
					continue;
				}
				$result['stored'][(string)$obj['name']] = array(
					'epoch' => $r['epoch'], 'object_bytes' => $r['bytes'], 'object_sha256' => $r['sha256']);
				$result['bytes'] += (int)$r['bytes'];
			} catch (\Throwable $e) {
				$result['failed']++;
				$result['catchup_left'] += $is_local ? 0 : 1;
				error_log('BackupObjects: could not store ' . $obj['name'] . ': ' . $e->getMessage());
			} finally {
				if ($plain_tmp !== '') { @unlink($plain_tmp); }
			}
		}

		if (!$result['stored'] && $result['failed'] > 0) {
			throw new BackupObjectsException(
				'None of the ' . $result['failed'] . ' offloaded files this run tried to copy to backup storage '
				. 'could be stored. Backup storage cannot be written; see the error log for the provider\'s answer.');
		}
		return $result;
	}

	/** Bring one original down from the file store, by the driver its visibility uses. */
	private static function fetch_from_store(array $obj, $sink) {
		if (isset(self::$test_hooks['catchup'])) {
			$driver = self::$test_hooks['catchup'];
		} else {
			$driver = CloudStorageDriverFactory::forVisibilityWithFallback((string)($obj['visibility'] ?? 'public'));
		}
		if (!$driver) {
			throw new BackupObjectsException('no local copy and the ' . ($obj['visibility'] ?? 'public') . ' file store is not configured');
		}
		$driver->get((string)$obj['remote_key'], $sink);
		if (!is_file($sink)) {
			throw new BackupObjectsException('the file store returned nothing for ' . $obj['remote_key']);
		}
		@chmod($sink, 0600);
	}

	// -------------------------------------------------------------- release

	/**
	 * May the local bytes of this object go? Only once every enabled profile's
	 * held set names it. A profile with no held set at all holds everything —
	 * a missing held.json means "no run has told us", not "nothing to wait
	 * for". With no profile enabled the answer is yes, as it always was. Pure.
	 *
	 * @param array $held_by_profile profile => (name => entry) | null when the profile has no held.json
	 */
	public static function may_release(array $held_by_profile, array $enabled, $name) {
		foreach ($enabled as $profile) {
			$set = $held_by_profile[$profile] ?? null;
			if (!is_array($set) || !isset($set[$name])) {
				return false;
			}
		}
		return true;
	}

	/** The held sets of the enabled profiles, read from disk, for may_release(). */
	public static function held_sets(array $enabled, $base_dir) {
		$out = array();
		foreach ($enabled as $profile) {
			$out[$profile] = self::read_held_file(self::held_path_for($profile, $base_dir));
		}
		return $out;
	}

	/**
	 * End of a run: release the local bytes of every cloud blob that every
	 * enabled profile now holds. Returns how many blobs were released.
	 */
	public static function release_waiting(array $objects, array $enabled, $base_dir) {
		if (!$objects) {
			return 0;
		}
		$sets = self::held_sets($enabled, $base_dir);
		$released = 0;
		foreach ($objects as $obj) {
			$present = false;
			foreach (($obj['paths'] ?? array()) as $p) {
				if (is_file($p)) { $present = true; break; }
			}
			if (!$present || !self::may_release($sets, $enabled, (string)$obj['name'])) {
				continue;
			}
			foreach ($obj['paths'] as $p) {
				if (is_file($p)) { @unlink($p); }
			}
			$released++;
		}
		return $released;
	}

	/**
	 * The offload tick, after the flip to `cloud`: store to the site's backup storage when
	 * the site profile is enabled, then say whether the engine may unlink the
	 * local bytes. Never throws — a failed store leaves the row `cloud` with
	 * its bytes, and the next site run's listing shows the object missing and
	 * stores it. Nothing is retried here.
	 */
	public static function after_offload(StorageProfile $profile, $id) {
		$enabled = BackupProfile::enabled();
		if (!$enabled) {
			return true;
		}
		if (!method_exists($profile, 'backupObject')) {
			// A consumer that cannot describe its rows for backup is not backed
			// up by the object store; its bytes go as they always did.
			return true;
		}
		$obj = $profile->backupObject((int)$id);
		if ($obj === null) {
			return true;
		}
		$name = (string)$obj['name'];
		$sets = array();
		if (in_array(BackupProfile::SITE, $enabled, true)) {
			try {
				$plan = self::$test_hooks['site_plan'] ?? BackupRunner::plan(array('profile' => BackupProfile::SITE));
				$r = self::store_object($plan, self::epoch($plan), $obj, $obj['original']);
				if ($r !== null) {
					self::held_add($plan, $name, $r);
				}
			} catch (\Throwable $e) {
				error_log('BackupObjects: the offload tick could not copy ' . $name . ' to backup storage ('
					. $e->getMessage() . '); keeping its local bytes until a run stores it.');
			}
		}
		try {
			$base = isset(self::$test_hooks['site_plan']) ? self::$test_hooks['site_plan']['base_dir'] : BackupRunner::output_dir();
			$sets = self::held_sets($enabled, $base);
		} catch (\Throwable $e) {
			return false;
		}
		return self::may_release($sets, $enabled, $name);
	}

	// ------------------------------------------------------------ retention

	/**
	 * The objects an index names, keyed by shelf location (epoch/name) — the
	 * unit retention deletes. An object re-stored under a later epoch is a
	 * different location from its earlier copy, so the earlier copy is
	 * retired when nothing retained names it there. Pure.
	 */
	public static function index_locations(array $index) {
		$out = array();
		foreach (self::held_from_index($index) as $name => $e) {
			if ($e['epoch'] === '') { continue; }
			$out[$e['epoch'] . '/' . $name] = $e + array('name' => $name);
		}
		return $out;
	}

	/**
	 * Site shelf: delete every object the pruned runs' indexes name that no
	 * retained index names, and the envelope of any epoch left empty. A
	 * retained index that cannot be read ends the pass with nothing deleted.
	 *
	 * @param array $candidates epoch/name => entry (name, epoch, object_bytes, object_sha256), from the pruned indexes
	 * @param array $retained_keys bucket keys of the retained runs' indexes, newest first
	 * @return int objects deleted
	 */
	public static function prune_site(array $plan, array $candidates, array $retained_keys) {
		if (!$candidates) {
			return 0;
		}
		// The newest index of each retained chain first; only a candidate absent
		// from all of those costs a read of an older index.
		foreach ($retained_keys as $key) {
			if (!$candidates) { break; }
			$index = self::fetch_index_key($plan, $key);
			if ($index === null) {
				// A retained run's index that cannot be read may be the only
				// one naming what remains: nothing goes this pass.
				error_log('BackupObjects: the objects index ' . $key . ' could not be read; no offloaded file is pruned this run.');
				return 0;
			}
			foreach (array_keys(self::index_locations($index)) as $loc) {
				unset($candidates[$loc]);
			}
		}
		if (!$candidates) {
			return 0;
		}
		list($creds, $bucket, $base) = self::destination($plan);
		$deleted = 0;
		$epochs = array();
		foreach ($candidates as $loc => $e) {
			$name = (string)($e['name'] ?? substr($loc, strpos($loc, '/') + 1));
			$key = $base . self::object_relname((string)$e['epoch'], $name);
			try {
				$resp = S3Signer::delete($creds, $bucket, '/' . ltrim($key, '/'));
				$status = (int)($resp['status'] ?? 0);
				if (($status < 200 || $status >= 300) && $status !== 404) {
					throw new BackupObjectsException('HTTP ' . $status);
				}
				$deleted++;
				$epochs[(string)$e['epoch']] = true;
			} catch (\Throwable $ex) {
				error_log('BackupObjects: could not delete object ' . $name . ': ' . $ex->getMessage());
			}
		}
		foreach (array_keys($epochs) as $epoch) {
			try {
				$left = S3Signer::list($creds, $bucket, ltrim($base . self::DIR . '/' . $epoch . '/', '/'));
				$only_envelope = true;
				foreach ($left as $o) {
					if (basename((string)$o['key']) !== self::ENVELOPE_NAME) { $only_envelope = false; break; }
				}
				if ($only_envelope) {
					S3Signer::delete($creds, $bucket, '/' . ltrim($base . self::envelope_relname($epoch), '/'));
				}
			} catch (\Throwable $ex) {
				error_log('BackupObjects: could not tidy epoch ' . $epoch . ': ' . $ex->getMessage());
			}
		}
		return $deleted;
	}

	// ---------------------------------------------------------------- sweep

	/** Remove temporaries a budget or an interrupt left behind. */
	public static function sweep_tmp(array $plan) {
		$dir = rtrim($plan['output_dir'], '/') . '/' . self::DIR . '/' . self::TMP_DIR;
		if (!is_dir($dir)) {
			return 0;
		}
		$swept = 0;
		$cutoff = time() - self::TMP_MAX_AGE;
		foreach (glob($dir . '/*') ?: array() as $p) {
			if (is_file($p) && filemtime($p) < $cutoff && @unlink($p)) { $swept++; }
		}
		foreach (glob(rtrim($plan['output_dir'], '/') . '/' . self::DIR . '/.exclude-*') ?: array() as $p) {
			if (is_file($p) && filemtime($p) < $cutoff && @unlink($p)) { $swept++; }
		}
		return $swept;
	}

	// -------------------------------------------------------------- helpers

	/** The run's bucket, credentials and base key — the runner's own resolution. */
	/**
	 * Where this profile's objects live: the target's credentials, its bucket,
	 * and the base key `{prefix}/{slug}/{profile}/` every relative name hangs
	 * off. Public so the site's own Bring them back signs links to exactly
	 * where the store step put things.
	 *
	 * @return array [credentials, bucket, base key]
	 * @throws BackupObjectsException
	 */
	public static function destination(array $plan) {
		$target = $plan['target'];
		$creds  = $target->get_credentials();
		if (empty($creds)) {
			throw new BackupObjectsException('The backup target has no stored credentials.');
		}
		$bucket = trim((string)$target->get('bkt_bucket'));
		if ($bucket === '') {
			throw new BackupObjectsException('The backup target has no bucket configured.');
		}
		$prefix = rtrim(trim((string)$target->get('bkt_path_prefix')) ?: 'joinery-backups', '/');
		$base = $prefix . '/' . $plan['slug'] . '/' . BackupProfile::path_segment($plan['profile']) . '/';
		return array($creds, $bucket, $base);
	}

	/** Upload one file to a relative name under the profile's base key, or throw with the provider's answer. */
	private static function put(array $plan, $relname, $local, $content_type = 'application/octet-stream') {
		list($creds, $bucket, $base) = self::destination($plan);
		$resp = S3Signer::put_file($creds, $bucket, '/' . ltrim($base . $relname, '/'), $local, $content_type);
		$status = (int)($resp['status'] ?? 0);
		if ($status < 200 || $status >= 300) {
			$msg = S3Signer::extract_error($resp['body'] ?? '') ?: ('HTTP ' . $status);
			throw new BackupObjectsException('Upload of ' . basename($relname) . ' failed: ' . $msg);
		}
	}
}
