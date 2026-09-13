<?php
/**
 * BackupStaging — bringing one of this machine's own chains back off the shelf.
 *
 * Two scripts need the same thing and must not be allowed to drift: Prepare
 * (utils/stage_chain.php) stages a chain so a restore can be approved against
 * it, and Verify (utils/verify_backup.php) stages a chain so it can be opened
 * and read without touching the site. Everything that decides whether a byte
 * is allowed onto this machine lives here, once, so a verify can never fetch
 * something a Prepare would refuse:
 *
 *   - the request shape (a chain id that is safe as a directory name, https
 *     links only, bare artifact names only, no key and no credential);
 *   - the manifest first, ledger-checked, and its chain id matched;
 *   - the artifact list taken from BackupChain::restore_plan, never from the
 *     caller;
 *   - every artifact fetched through BackupFetch, which checks it against the
 *     node-side upload ledger before and after the transfer;
 *   - the chain data key recovered from this machine's own site key and written
 *     restricted before content.
 *
 * Every function here is pure over an explicit working directory; nothing
 * chooses where to work, and nothing prints. A failure is a
 * BackupStagingException whose code is the exit status the calling script
 * should use — 2 for a malformed request, 1 for a transfer, envelope or
 * integrity failure — and whose message is exactly what the script used to say.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/BackupChain.php'));
require_once(PathHelper::getIncludePath('includes/BackupEnvelope.php'));
require_once(PathHelper::getIncludePath('includes/BackupFetch.php'));
require_once(PathHelper::getIncludePath('includes/BackupProfile.php'));

class BackupStagingException extends Exception {

	/** Exit status for a request that could not be understood. */
	const MALFORMED = 2;

	/** Exit status for a transfer, envelope or integrity failure. */
	const FAILED = 1;

	public function __construct($message, $code = self::FAILED) {
		parent::__construct($message, (int)$code);
	}
}

class BackupStaging {

	/** Name of the recovered chain data key inside a working directory. */
	const KEY_NAME = 'chain.key';

	/** The pattern BackupChain mints; it becomes a directory name. */
	const CHAIN_ID_PATTERN = '/^chain-[0-9_]+$/';

	/** Bounds on a run number a caller may name. */
	const MAX_SEQ = 100000;

	// -------------------------------------------------------------- request

	/**
	 * Understand a staging request.
	 *
	 * $config is the decoded JSON a script read from stdin. The keys every
	 * request must carry are chain_id, profile, manifest_url and artifact_urls;
	 * seq is optional; $extra names further optional keys a particular script
	 * accepts (verify adds 'level'). Anything else is refused, because the one
	 * map a caller is allowed to send is the one place it could smuggle a path,
	 * a key or a credential.
	 *
	 * @return array ['chain_id','profile','manifest_url','artifact_urls','seq', ...extras]
	 * @throws BackupStagingException code MALFORMED
	 */
	public static function parse_request($config, array $extra = array()) {
		if (!is_array($config)) {
			throw new BackupStagingException('this run needs its configuration as JSON on stdin', BackupStagingException::MALFORMED);
		}

		$required = array('chain_id', 'profile', 'manifest_url', 'artifact_urls');
		$accepted = array_merge($required, array('seq'), $extra);
		$unknown = array_diff(array_keys($config), $accepted);
		if ($unknown) {
			sort($unknown);
			throw new BackupStagingException(
				'configuration carries unrecognised key(s): ' . implode(', ', $unknown), BackupStagingException::MALFORMED);
		}

		$chain_id = trim((string)($config['chain_id'] ?? ''));
		// It becomes a DIRECTORY NAME, so no separator and no dot is what keeps
		// the workspace inside the backup base.
		if (!preg_match(self::CHAIN_ID_PATTERN, $chain_id)) {
			throw new BackupStagingException('that is not a chain id', BackupStagingException::MALFORMED);
		}
		if (!BackupFetch::is_signed_url((string)($config['manifest_url'] ?? ''))) {
			throw new BackupStagingException("'manifest_url' must be an https URL", BackupStagingException::MALFORMED);
		}
		if (!is_array($config['artifact_urls'] ?? null) || !$config['artifact_urls']) {
			throw new BackupStagingException(
				"'artifact_urls' must be a map of artifact name to signed URL", BackupStagingException::MALFORMED);
		}

		try {
			$profile = BackupProfile::normalize((string)($config['profile'] ?? ''));
		} catch (BackupProfileException $e) {
			throw new BackupStagingException($e->getMessage(), BackupStagingException::MALFORMED);
		}

		$seq = (isset($config['seq']) && $config['seq'] !== '' && $config['seq'] !== null)
			? (int)$config['seq'] : null;
		if ($seq !== null && ($seq < 0 || $seq > self::MAX_SEQ)) {
			throw new BackupStagingException(
				'a chain run number must be between 0 and ' . self::MAX_SEQ, BackupStagingException::MALFORMED);
		}

		// Bare names only. A key with a separator in it would be the caller
		// naming a path again, through the one map it is allowed to send.
		$artifact_urls = array();
		foreach ($config['artifact_urls'] as $name => $url) {
			$name = (string)$name;
			if ($name !== basename($name) || $name === '' || $name === '.' || $name === '..') {
				throw new BackupStagingException(
					'an artifact link is keyed by a path rather than a name', BackupStagingException::MALFORMED);
			}
			if (!BackupFetch::is_signed_url((string)$url)) {
				throw new BackupStagingException(
					'the link for ' . $name . ' is not an https URL', BackupStagingException::MALFORMED);
			}
			$artifact_urls[$name] = (string)$url;
		}

		$out = array(
			'chain_id'      => $chain_id,
			'profile'       => $profile,
			'manifest_url'  => trim((string)$config['manifest_url']),
			'artifact_urls' => $artifact_urls,
			'seq'           => $seq,
		);
		foreach ($extra as $key) {
			if (array_key_exists($key, $config)) {
				$out[$key] = $config[$key];
			}
		}
		return $out;
	}

	// ------------------------------------------------------------ workspace

	/**
	 * Make the working directory, private. Idempotent: a resumed staging
	 * reuses what it has.
	 *
	 * @throws BackupStagingException
	 */
	public static function prepare_workspace($work) {
		if (!is_dir($work) && !@mkdir($work, 0700, true)) {
			// Exit 2, as stage_chain.php has always answered this: the request
			// named a workspace this machine cannot provide.
			throw new BackupStagingException(
				'could not make the restore workspace at ' . $work, BackupStagingException::MALFORMED);
		}
		@chmod($work, 0700);
		return $work;
	}

	// ------------------------------------------------------------- manifest

	/**
	 * Fetch the chain manifest into $work and read it.
	 *
	 * First, because it names every artifact with the size and hash each must
	 * match, and carries the sealed data keys. A directory full of
	 * files-0003.tar.gz.enc without it is not a backup.
	 *
	 * Ledger-checked like everything else, and this one carries the most weight:
	 * the manifest's own hashes are only as trustworthy as the manifest, so a
	 * manifest served by a management node that had been compromised could
	 * bless whatever artifacts it liked. The ledger entry was written by this
	 * machine at upload time, which the management node has never been able to
	 * reach.
	 *
	 * @return array the decoded manifest, whose chain_id matches $chain_id
	 * @throws BackupStagingException
	 */
	public static function fetch_manifest($profile, $work, $chain_id, $manifest_url) {
		$got = BackupFetch::fetch_artifact(
			$profile, $work, $chain_id . '/' . BackupChain::MANIFEST_NAME, BackupChain::MANIFEST_NAME, $manifest_url);
		if (!$got['ok']) {
			throw new BackupStagingException('could not bring back the chain manifest: ' . $got['error']);
		}
		return self::read_manifest($work, $chain_id);
	}

	/**
	 * Read the manifest already in $work and check it is the chain asked for.
	 *
	 * @throws BackupStagingException
	 */
	public static function read_manifest($work, $chain_id) {
		try {
			$manifest = BackupChain::read(rtrim($work, '/') . '/' . BackupChain::MANIFEST_NAME);
		} catch (Exception $e) {
			throw new BackupStagingException($e->getMessage());
		}
		if ((string)($manifest['chain_id'] ?? '') !== (string)$chain_id) {
			throw new BackupStagingException('the manifest at ' . $work . ' is for chain '
				. (string)($manifest['chain_id'] ?? '(unnamed)') . ', not ' . $chain_id);
		}
		return $manifest;
	}

	/**
	 * The restore plan for a run, or the refusal BackupChain gives.
	 *
	 * @throws BackupStagingException
	 */
	public static function plan(array $manifest, $seq = null) {
		try {
			return BackupChain::restore_plan($manifest, $seq);
		} catch (Exception $e) {
			throw new BackupStagingException($e->getMessage());
		}
	}

	/**
	 * The artifact names a restore of this plan needs, in the order they are
	 * applied: the full and every incremental up to the chosen run, then that
	 * run's database dump and metadata. From the plan, so the caller has no say
	 * in it.
	 */
	public static function wanted(array $plan) {
		$wanted = array();
		foreach ($plan['files'] as $a) {
			$wanted[] = (string)$a['name'];
		}
		foreach (array('db', 'meta') as $kind) {
			if (!empty($plan[$kind]['name'])) {
				$wanted[] = (string)$plan[$kind]['name'];
			}
		}
		return $wanted;
	}

	// ------------------------------------------------------------------ key

	/**
	 * Recover the chain data key from this machine's own site key and write it
	 * beside the artifacts.
	 *
	 * Every chain seals to the node as well as to the management node's recovery
	 * key precisely so that a node can open its own backups without anybody's
	 * private key travelling. A chain that does not open here belongs to a
	 * different machine, and that is a refusal, not a prompt for a better key.
	 *
	 * Written the way BackupRunner writes a run key: restricted before content,
	 * so there is no window in which a usable decryption key is readable by
	 * anything else on the machine.
	 *
	 * @return string path of the key file
	 * @throws BackupStagingException
	 */
	public static function write_chain_key(array $manifest, $work) {
		$key_path = rtrim($work, '/') . '/' . self::KEY_NAME;
		try {
			if (empty($manifest['envelope']) || !is_array($manifest['envelope'])) {
				throw new BackupEnvelopeException('This chain manifest carries no envelope, so there is no data key to recover.');
			}
			$data_key = BackupEnvelope::open_as_site($manifest['envelope']);
		} catch (Exception $e) {
			throw new BackupStagingException(
				'the chain envelope did not open with this machine\'s own backup key — '
				. 'this chain was taken by a different machine. ' . $e->getMessage() . "\n"
				. 'Restore it from a shell with the recovery key: backup_envelope.php open '
				. '--sidecar manifest.json --private <recovery key>');
		}

		$old_umask = umask(0077);
		$wrote = @file_put_contents($key_path, $data_key);
		umask($old_umask);
		sodium_memzero($data_key);
		if ($wrote === false) {
			throw new BackupStagingException('could not write the recovered chain key');
		}
		@chmod($key_path, 0600);
		return $key_path;
	}

	// ------------------------------------------------------------ artifacts

	/**
	 * Fetch every wanted artifact into $work, ledger-checked.
	 *
	 * An artifact already present and already matching the ledger is kept
	 * (a resumed staging should not re-pull gigabytes it has); one present but
	 * not matching is replaced. $progress, when given, is called with
	 * ('already staged'|'fetching', $name) before each artifact is handled.
	 *
	 * @return array ['fetched' => int, 'bytes' => int]
	 * @throws BackupStagingException
	 */
	public static function fetch_artifacts($profile, $work, $chain_id, array $wanted, array $artifact_urls,
	                                       $seq = null, ?callable $progress = null) {
		$work = rtrim($work, '/');
		$fetched = 0;
		$bytes   = 0;
		foreach ($wanted as $name) {
			if (!isset($artifact_urls[$name])) {
				throw new BackupStagingException('no download link was supplied for ' . $name
					. ', which this chain\'s manifest says run ' . (int)$seq . ' needs');
			}
			$local = $work . '/' . $name;
			if (is_file($local)) {
				$check = BackupLedger::verify($profile, $chain_id . '/' . $name, $local);
				if ($check['ok']) {
					if ($progress) { $progress('already staged', $name); }
					$fetched++;
					$bytes += (int)@filesize($local);
					continue;
				}
				@unlink($local);
			}

			if ($progress) { $progress('fetching', $name); }
			$got = BackupFetch::fetch_artifact($profile, $work, $chain_id . '/' . $name, $name, $artifact_urls[$name]);
			if (!$got['ok']) {
				throw new BackupStagingException($got['error']);
			}
			$fetched++;
			$bytes += (int)$got['bytes'];
		}
		return array('fetched' => $fetched, 'bytes' => $bytes);
	}
}
