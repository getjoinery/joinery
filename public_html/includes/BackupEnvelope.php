<?php
/**
 * BackupEnvelope — per-backup data keys, sealed to the people who may open them.
 *
 * Every backup run mints its own random data key and encrypts the archive with
 * it. The data key is then sealed (SealedBox's v2 seal: X-Wing, then
 * AES-256-GCM) to two recipients and travels alongside the archive, never in a
 * database:
 *
 *   recovery - the operator's recovery public key (BackupRecoveryKey). The
 *              private half lives in a password manager and opens every backup
 *              from every site given the same public key. Always present; a run
 *              refuses rather than produce an archive only the site can open.
 *   site     - a key the site itself holds (config/backup_site_key), so it
 *              can open its own backups without the operator: pre-restore
 *              rollback snapshots, install-from-backup, routine restores. This
 *              key is disposable — if it is lost, recovery still opens
 *              everything and the next run mints a new one.
 *
 * Nothing on a node is precious as a result. Losing a node, or its whole disk,
 * loses no ability to read any backup it ever made.
 *
 * The sealed keys ride in a JSON sidecar next to a standalone archive
 * ({archive}.keys.json), and inside the chain manifest for incremental chains.
 * Both use the recipient structure built here.
 *
 * Envelope version 2 marks each recipient `"alg": "xwing"`. Version 1, whose
 * recipients carry no `alg` and are libsodium sealed boxes to 32-byte X25519
 * keys, is opened only: an old backup restores with the old recovery key
 * while retention keeps it (specs/post_quantum_sealing.md R2, R7). A secret
 * names neither version, so open() derives both candidate public keys from it
 * and opens the recipient whose recorded fingerprint matches.
 *
 * @version 1.2 - envelope version 2 (X-Wing recipients, `alg`); 32-byte v1 recipients refused
 *                at build; the site key is a 32-byte seed (site_secret()); open() reads both
 *                versions by fingerprint
 * @version 1.1 - no recipient set can be built from a supplied key. Every run on this machine,
 *                whoever triggered it, seals to the recovery key this machine holds and has proven
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/BackupRecoveryKey.php'));

class BackupEnvelopeException extends Exception {}

class BackupEnvelope {

	/** Sidecar/manifest envelope schema version written. */
	const VERSION = 2;

	/** Every version decode() reads: 1 (sodium sealed boxes) is opened only. */
	const READABLE_VERSIONS = [1, 2];

	/** A version-2 recipient's algorithm. */
	const ALG_XWING = 'xwing';

	/** Cipher the archive itself is encrypted with (what the engine scripts run). */
	const CIPHER = 'aes-256-cbc-pbkdf2';

	/** Site key filename, under the site's config/ directory: base64 of a 32-byte X-Wing seed. */
	const SITE_KEY_FILE = 'backup_site_key';

	/** Suffix appended to an archive name to get its sidecar name. */
	const SIDECAR_SUFFIX = '.keys.json';

	// ---------------------------------------------------------------- minting

	/**
	 * Mint a data key for one backup run and seal it to every recipient.
	 *
	 * Returns ['data_key' => <passphrase string>, 'envelope' => <array>]. The
	 * caller hands data_key to the engine (via a 0600 file, never argv) and
	 * writes the envelope beside the finished archive.
	 *
	 * The data key is base64 text rather than raw bytes because openssl consumes
	 * it as a PBKDF2 passphrase on a pipe; 32 random bytes of entropy either way.
	 */
	public static function mint($artifact_name, ?array $recipients = null) {
		$data_key = base64_encode(random_bytes(32));
		return [
			'data_key' => $data_key,
			'envelope' => self::build($data_key, $artifact_name, $recipients ?? self::recipients()),
		];
	}

	/**
	 * Accept a recovery public key as raw bytes or base64 and return raw bytes.
	 *
	 * Validated rather than trusted: sealing to a malformed key fails loudly
	 * here, whereas sealing to a well-formed key nobody holds the private half
	 * of appears to succeed and produces archives that can never be opened.
	 */
	public static function normalize_public_key($pub) {
		$raw = (string)$pub;
		if (strlen($raw) !== XWing::PUBLIC_KEY_BYTES) {
			$decoded = base64_decode(trim($raw), true);
			if ($decoded !== false && strlen($decoded) === SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
				throw new BackupEnvelopeException('The supplied recovery public key is an old X25519 key. '
					. 'Backups seal to post-quantum keys only: generate a new recovery key.');
			}
			if ($decoded === false || strlen($decoded) !== XWing::PUBLIC_KEY_BYTES) {
				throw new BackupEnvelopeException(
					'The supplied recovery public key is not a valid public key '
					. '(expected ' . XWing::PUBLIC_KEY_BYTES . ' raw bytes, or that base64-encoded).');
			}
			$raw = $decoded;
		}
		return $raw;
	}

	/**
	 * Build the envelope structure for an already-chosen data key. Split out so
	 * chain manifests (which carry one envelope for a whole chain) and rotation
	 * (which re-seals an existing data key to a new recipient set) share it.
	 */
	public static function build($data_key, $artifact_name, array $recipients) {
		if (!is_string($data_key) || $data_key === '') {
			throw new BackupEnvelopeException('Cannot build a backup envelope around an empty data key.');
		}
		if (!$recipients) {
			throw new BackupEnvelopeException('A backup envelope needs at least the recovery recipient.');
		}

		$sealed = [];
		foreach ($recipients as $r) {
			if (strlen((string)$r['pub']) === SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
				throw new BackupEnvelopeException('The ' . $r['kind'] . ' key is an old X25519 key, and backups seal to '
					. 'post-quantum keys only. ' . ($r['kind'] === 'recovery'
						? 'Generate a new recovery key on the Backups page; older backups stay openable with the key you have.'
						: 'The host converger replaces it on its next run.'));
			}
			try {
				$box = SealedBox::sealV2($data_key, (string)$r['pub']);
			} catch (RuntimeException $e) {
				throw new BackupEnvelopeException('Could not seal to the ' . $r['kind'] . ' key: ' . $e->getMessage());
			}
			$sealed[] = [
				'kind'        => $r['kind'],
				'alg'         => self::ALG_XWING,
				'fingerprint' => BackupRecoveryKey::fingerprint($r['pub']),
				'sealed'      => base64_encode($box),
			];
		}

		return [
			'version'    => self::VERSION,
			'artifact'   => (string)$artifact_name,
			'cipher'     => self::CIPHER,
			'created'    => gmdate('Y-m-d\TH:i:s\Z'),
			'recipients' => $sealed,
		];
	}

	/**
	 * The recipients every backup made by this site seals to.
	 *
	 * Recovery comes first and is mandatory: public_key() throws when recovery
	 * is unconfigured or unproven, which is what makes an encrypted backup
	 * refuse to run rather than produce an unopenable archive.
	 */
	public static function recipients(): array {
		$out = [['kind' => 'recovery', 'pub' => BackupRecoveryKey::public_key()]];
		$out[] = ['kind' => 'site', 'pub' => self::site_public_key()];
		return $out;
	}

	// ------------------------------------------------------------- site key

	/** Absolute path of this site's disposable backup key. */
	public static function site_key_path() {
		return PathHelper::getSiteRoot() . '/config/' . self::SITE_KEY_FILE;
	}

	/**
	 * This site's secret: a 32-byte X-Wing seed, minting one on first use.
	 *
	 * Minting is no-clobber: two runs racing to create the key must not end with
	 * one of them holding a key the file no longer names, because backups it
	 * sealed would then only open with the recovery key. The loser re-reads the
	 * winner's file.
	 */
	public static function site_secret() {
		$path = self::site_key_path();

		$existing = self::read_site_secret($path);
		if ($existing !== null) {
			return $existing;
		}

		$seed = random_bytes(XWing::SEED_BYTES);
		$tmp = $path . '.' . getmypid() . '.tmp';
		if (@file_put_contents($tmp, base64_encode($seed)) === false) {
			// config/ belongs to the tree owner, so the web user cannot create
			// a file there (specs/implemented/read_only_tree.md). The host converger mints
			// this key at its root moments and normally gets there first; this
			// is the loud fallback for a site whose converger has not run.
			throw new BackupEnvelopeException(
				'The site backup key is not present and could not be created at ' . $path . '. '
				. 'The host converger mints it as root on its next run; backups cannot be sealed until it has.');
		}
		// 0640, not 0600: whichever account mints the key first, the other
		// accounts that run backups here still have to read it. The web user
		// takes the scheduled run and the deploy account runs one from a shell,
		// and both are in the file's group. fix_permissions.sh pins the same mode.
		@chmod($tmp, 0640);

		// link() fails when the destination exists, which is the atomic
		// "only if absent" primitive rename() does not give us.
		if (@link($tmp, $path)) {
			@unlink($tmp);
			return $seed;
		}
		@unlink($tmp);

		$won = self::read_site_secret($path);
		if ($won === null) {
			throw new BackupEnvelopeException('Could not create the site backup key at ' . $path . '.');
		}
		return $won;
	}

	/** Raw public key of the site's seed (1216 bytes). */
	public static function site_public_key() {
		return (new XWing())->publicKey(self::site_secret());
	}

	private static function read_site_secret($path) {
		if (!is_file($path)) {
			return null;
		}
		// A key that exists but cannot be read is NOT a missing key. Confusing
		// the two would send the caller down the minting path, and minting over
		// a live key orphans the site recipient on every backup sealed to the
		// old one. Backups run as more than one account — the web user for
		// scheduled runs, the deploy user over SSH — so this is a real state,
		// not a theoretical one.
		$raw = @file_get_contents($path);
		if ($raw === false) {
			throw new BackupEnvelopeException(
				'The site backup key at ' . $path . ' exists but is not readable by '
				. (function_exists('posix_getpwuid') && function_exists('posix_geteuid')
					? (posix_getpwuid(posix_geteuid())['name'] ?? 'this user')
					: 'this user')
				. '. Run fix_permissions.sh, or pass the recovery key instead.');
		}
		if (trim($raw) === '') {
			return null;
		}
		$seed = base64_decode(trim($raw), true);
		if ($seed !== false && strlen($seed) === SODIUM_CRYPTO_BOX_KEYPAIRBYTES) {
			throw new BackupEnvelopeException(
				'The site backup key at ' . $path . ' is an old X25519 keypair. The host converger replaces it '
				. 'with a post-quantum key on its next run; backups already made stay openable with the recovery key.');
		}
		if ($seed === false || strlen($seed) !== XWing::SEED_BYTES) {
			throw new BackupEnvelopeException(
				'The site backup key at ' . $path . ' is not a valid key. Move it aside to have a new one '
				. 'minted; backups already made stay openable with the recovery key.');
		}
		return $seed;
	}

	// ---------------------------------------------------------------- opening

	/**
	 * Recover the data key from an envelope using a secret key.
	 *
	 * Accepts a 32-byte secret (a v2 seed or a v1 X25519 secret: the bytes do
	 * not say which) or a 64-byte v1 sodium keypair, raw or base64, so the same
	 * call serves the site key file and a recovery private key pasted from a
	 * password manager. The candidate public keys are derived from it and the
	 * recipient whose recorded fingerprint matches is opened.
	 */
	public static function open(array $envelope, $secret) {
		$recipients = $envelope['recipients'] ?? null;
		if (!is_array($recipients) || !$recipients) {
			throw new BackupEnvelopeException('This backup envelope lists no recipients.');
		}

		// Public and secret keys can look alike (a v1 public key and any secret
		// are both 32 bytes), and the two failures need opposite fixes. The
		// envelope carries the fingerprint of every recipient's PUBLIC key, so
		// say plainly when that is what was pasted, instead of reporting the
		// wrong key, which is the last thing anyone needs mid-disaster.
		$raw = self::raw_key_bytes($secret);
		if ($raw !== null && in_array(strlen($raw), [SODIUM_CRYPTO_BOX_PUBLICKEYBYTES, XWing::PUBLIC_KEY_BYTES], true)) {
			$fpr = hash('sha256', $raw);
			foreach ($recipients as $r) {
				if (hash_equals((string)($r['fingerprint'] ?? ''), $fpr)) {
					throw new BackupEnvelopeException(
						'That is the PUBLIC half of the ' . (string)($r['kind'] ?? 'recovery') . ' key — it seals '
						. 'backups but cannot open them. Supply the private key you saved when the keypair was '
						. 'generated.');
				}
			}
		}
		if ($raw === null) {
			throw new BackupEnvelopeException('No key supplied.');
		}
		if (strlen($raw) === XWing::PUBLIC_KEY_BYTES) {
			throw new BackupEnvelopeException('That is a PUBLIC key — public keys seal backups but cannot open them, '
				. 'and this one is not even a recipient of this backup. Supply the private key you saved when the keypair was generated.');
		}
		if (strlen($raw) !== SODIUM_CRYPTO_BOX_KEYPAIRBYTES && strlen($raw) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
			throw new BackupEnvelopeException(
				'That is not a recovery key. Expected the base64 secret key, not a file path.');
		}

		$wants_xwing = false;
		foreach ($recipients as $r) {
			$wants_xwing = $wants_xwing || ($r['alg'] ?? '') === self::ALG_XWING;
		}
		$pq_unavailable = null;
		$candidates = self::candidate_keys($raw, $wants_xwing, $pq_unavailable);

		foreach ($recipients as $r) {
			$blob = base64_decode((string)($r['sealed'] ?? ''), true);
			if ($blob === false || $blob === '') {
				continue;
			}
			$xwing = ($r['alg'] ?? '') === self::ALG_XWING;
			$candidate = $candidates[$xwing ? 2 : 1] ?? null;
			if ($candidate === null) {
				continue;
			}
			$fpr = (string)($r['fingerprint'] ?? '');
			if ($fpr !== '' && !hash_equals($fpr, $candidate['fingerprint'])) {
				continue;
			}
			if ($xwing) {
				try {
					return SealedBox::openV2($blob, $candidate['secret']);
				} catch (RuntimeException $e) {
					continue;
				}
			}
			$opened = @sodium_crypto_box_seal_open($blob, $candidate['secret']);
			if ($opened !== false) {
				return $opened;
			}
		}

		if ($pq_unavailable !== null) {
			throw new BackupEnvelopeException('This backup is sealed with a post-quantum key, which this machine cannot open: '
				. $pq_unavailable);
		}
		throw new BackupEnvelopeException(
			'That key does not open this backup. The envelope is sealed to: '
			. implode(', ', array_map(function ($r) { return (string)($r['kind'] ?? '?'); }, $recipients)) . '.');
	}

	/** Open an envelope with this site's own key. */
	public static function open_as_site(array $envelope) {
		return self::open($envelope, self::site_secret());
	}

	/**
	 * What a secret can open, by seal version: [1 => [secret = sodium keypair,
	 * fingerprint], 2 => [secret = seed, fingerprint]]. A 64-byte keypair is v1
	 * only; 32 bytes may be either, so both are derived. The v2 candidate is
	 * left out when no recipient needs it, so a v1 envelope opens on a machine
	 * without OpenSSL 3.5. When the v2 candidate is wanted but ML-KEM is not
	 * available here, it is left out and $pq_unavailable says why: the caller
	 * reports that only if no v1 recipient opened, since an old key may still
	 * open an old recipient.
	 */
	private static function candidate_keys(string $raw, bool $want_xwing, ?string &$pq_unavailable = null): array {
		$out = [];
		if (strlen($raw) === SODIUM_CRYPTO_BOX_KEYPAIRBYTES) {
			$out[1] = ['secret' => $raw, 'fingerprint' => hash('sha256', sodium_crypto_box_publickey($raw))];
			return $out;
		}
		$v1_pub = sodium_crypto_box_publickey_from_secretkey($raw);
		$out[1] = [
			'secret'      => sodium_crypto_box_keypair_from_secretkey_and_publickey($raw, $v1_pub),
			'fingerprint' => hash('sha256', $v1_pub),
		];
		if ($want_xwing) {
			try {
				$v2_pub = (new XWing())->publicKey($raw);
				$out[2] = ['secret' => $raw, 'fingerprint' => hash('sha256', $v2_pub)];
			} catch (RuntimeException $e) {
				$pq_unavailable = $e->getMessage();
			}
		}
		return $out;
	}

	/**
	 * The supplied key as raw bytes, accepting raw or base64, or null when
	 * nothing usable was given. Length is not interpreted here — callers decide
	 * whether 32 bytes means a secret key or a mistakenly pasted public one.
	 */
	private static function raw_key_bytes($secret) {
		if (!is_string($secret) || trim($secret) === '') {
			return null;
		}
		$known = [SODIUM_CRYPTO_BOX_KEYPAIRBYTES, SODIUM_CRYPTO_BOX_SECRETKEYBYTES, XWing::PUBLIC_KEY_BYTES];
		if (in_array(strlen($secret), $known, true)) {
			return $secret;
		}
		$decoded = base64_decode(trim($secret), true);
		return ($decoded === false || $decoded === '') ? $secret : $decoded;
	}

	// --------------------------------------------------------------- sidecars

	/** Sidecar filename for a standalone archive. */
	public static function sidecar_name($artifact_name) {
		return $artifact_name . self::SIDECAR_SUFFIX;
	}

	/** True when a key looks like an envelope sidecar rather than an archive. */
	public static function is_sidecar_name($name) {
		return substr((string)$name, -strlen(self::SIDECAR_SUFFIX)) === self::SIDECAR_SUFFIX;
	}

	/** Serialize an envelope for storage next to its archive. */
	public static function encode(array $envelope) {
		$json = json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			throw new BackupEnvelopeException('Could not encode the backup envelope.');
		}
		return $json . "\n";
	}

	/** Parse and sanity-check a stored envelope. */
	public static function decode($json) {
		$data = json_decode((string)$json, true);
		if (!is_array($data)) {
			throw new BackupEnvelopeException('This backup envelope is not readable JSON.');
		}
		if (!in_array((int)($data['version'] ?? 0), self::READABLE_VERSIONS, true)) {
			throw new BackupEnvelopeException(
				'Unsupported backup envelope version ' . (int)($data['version'] ?? 0) . '; this build reads versions '
				. implode(' and ', self::READABLE_VERSIONS) . '.');
		}
		if (!is_array($data['recipients'] ?? null) || !$data['recipients']) {
			throw new BackupEnvelopeException('This backup envelope lists no recipients.');
		}
		return $data;
	}

	/** Write an envelope beside its archive, group-readable only. */
	public static function write_sidecar($path, array $envelope) {
		if (@file_put_contents($path, self::encode($envelope)) === false) {
			throw new BackupEnvelopeException('Could not write the backup envelope to ' . $path . '.');
		}
		// 0640 for the same reason as the site key it is opened with: the web
		// user writes this on the scheduled run, and the deploy account has to
		// read it back to restore from a shell. Owner-only would mean an archive
		// whose envelope only one account can open.
		@chmod($path, 0640);
		return $path;
	}

	/** Read an envelope from disk. */
	public static function read_sidecar($path) {
		$raw = @file_get_contents($path);
		if ($raw === false) {
			throw new BackupEnvelopeException('Could not read the backup envelope at ' . $path . '.');
		}
		return self::decode($raw);
	}
}
