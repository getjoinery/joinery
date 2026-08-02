<?php
/**
 * BackupEnvelope — per-backup data keys, sealed to the people who may open them.
 *
 * Every backup run mints its own random data key and encrypts the archive with
 * it. The data key is then sealed (X25519 sealed box) to two recipients and
 * travels alongside the archive, never in a database:
 *
 *   recovery - the operator's recovery public key (BackupRecoveryKey). The
 *              private half lives in a password manager and opens every backup
 *              from every site given the same public key. Always present; a run
 *              refuses rather than produce an archive only the site can open.
 *   site     - a keypair the site itself holds (config/backup_site_key), so it
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
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/BackupRecoveryKey.php'));

class BackupEnvelopeException extends Exception {}

class BackupEnvelope {

	/** Sidecar/manifest envelope schema version. */
	const VERSION = 1;

	/** Cipher the archive itself is encrypted with (what the engine scripts run). */
	const CIPHER = 'aes-256-cbc-pbkdf2';

	/** Site keypair filename, under the site's config/ directory. */
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
	public static function mint($artifact_name) {
		$data_key = base64_encode(random_bytes(32));
		return [
			'data_key' => $data_key,
			'envelope' => self::build($data_key, $artifact_name, self::recipients()),
		];
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
			$sealed[] = [
				'kind'        => $r['kind'],
				'fingerprint' => BackupRecoveryKey::fingerprint($r['pub']),
				'sealed'      => base64_encode(sodium_crypto_box_seal($data_key, $r['pub'])),
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

	/** Absolute path of this site's disposable backup keypair. */
	public static function site_key_path() {
		return PathHelper::getSiteRoot() . '/config/' . self::SITE_KEY_FILE;
	}

	/**
	 * This site's keypair, minting one on first use.
	 *
	 * Minting is no-clobber: two runs racing to create the key must not end with
	 * one of them holding a keypair the file no longer names, because backups it
	 * sealed would then only open with the recovery key. The loser re-reads the
	 * winner's file.
	 */
	public static function site_keypair() {
		$path = self::site_key_path();

		$existing = self::read_site_keypair($path);
		if ($existing !== null) {
			return $existing;
		}

		$keypair = sodium_crypto_box_keypair();
		$tmp = $path . '.' . getmypid() . '.tmp';
		if (@file_put_contents($tmp, base64_encode($keypair)) === false) {
			throw new BackupEnvelopeException('Could not write the site backup key at ' . $path . '.');
		}
		@chmod($tmp, 0600);

		// link() fails when the destination exists, which is the atomic
		// "only if absent" primitive rename() does not give us.
		if (@link($tmp, $path)) {
			@unlink($tmp);
			return $keypair;
		}
		@unlink($tmp);

		$won = self::read_site_keypair($path);
		if ($won === null) {
			throw new BackupEnvelopeException('Could not create the site backup key at ' . $path . '.');
		}
		return $won;
	}

	/** Raw public half of the site keypair. */
	public static function site_public_key() {
		return sodium_crypto_box_publickey(self::site_keypair());
	}

	private static function read_site_keypair($path) {
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
		$keypair = base64_decode(trim($raw), true);
		if ($keypair === false || strlen($keypair) !== SODIUM_CRYPTO_BOX_KEYPAIRBYTES) {
			throw new BackupEnvelopeException(
				'The site backup key at ' . $path . ' is not a valid keypair. Move it aside to have a new one '
				. 'minted; backups already made stay openable with the recovery key.');
		}
		return $keypair;
	}

	// ---------------------------------------------------------------- opening

	/**
	 * Recover the data key from an envelope using a secret key.
	 *
	 * Accepts a full keypair or a bare secret key (the public half is derived),
	 * so the same call serves the site key file and a recovery private key
	 * pasted from a password manager. Every recipient is tried: an envelope does
	 * not say which recipient a given secret belongs to, and trying the rest
	 * after one fails costs nothing.
	 */
	public static function open(array $envelope, $secret) {
		$recipients = $envelope['recipients'] ?? null;
		if (!is_array($recipients) || !$recipients) {
			throw new BackupEnvelopeException('This backup envelope lists no recipients.');
		}

		// X25519 public and secret keys are both 32 bytes, so nothing about the
		// value itself says which one was pasted — and the two failures need
		// opposite fixes. The envelope does know: it carries the fingerprint of
		// every recipient's PUBLIC key. Say so plainly instead of reporting the
		// wrong key, which is the last thing anyone needs mid-disaster.
		$raw = self::raw_key_bytes($secret);
		if ($raw !== null && strlen($raw) === SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
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

		$keypair = self::normalize_identity($secret);

		foreach ($recipients as $r) {
			$blob = base64_decode((string)($r['sealed'] ?? ''), true);
			if ($blob === false || $blob === '') {
				continue;
			}
			$opened = @sodium_crypto_box_seal_open($blob, $keypair);
			if ($opened !== false) {
				return $opened;
			}
		}

		throw new BackupEnvelopeException(
			'That key does not open this backup. The envelope is sealed to: '
			. implode(', ', array_map(function ($r) { return (string)($r['kind'] ?? '?'); }, $recipients)) . '.');
	}

	/** Open an envelope with this site's own key. */
	public static function open_as_site(array $envelope) {
		return self::open($envelope, self::site_keypair());
	}

	/**
	 * Coerce a secret into a sodium keypair. Takes raw bytes or base64, and a
	 * keypair or a bare secret key.
	 */
	public static function normalize_identity($secret) {
		$raw = self::raw_key_bytes($secret);
		if ($raw === null) {
			throw new BackupEnvelopeException('No key supplied.');
		}
		if (strlen($raw) === SODIUM_CRYPTO_BOX_KEYPAIRBYTES) {
			return $raw;
		}
		if (strlen($raw) === SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
			return sodium_crypto_box_keypair_from_secretkey_and_publickey(
				$raw, sodium_crypto_box_publickey_from_secretkey($raw));
		}
		throw new BackupEnvelopeException(
			'That is not a recovery key. Expected the base64 secret key or keypair, not a file path.');
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
		$known = [SODIUM_CRYPTO_BOX_KEYPAIRBYTES, SODIUM_CRYPTO_BOX_SECRETKEYBYTES];
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
		if ((int)($data['version'] ?? 0) !== self::VERSION) {
			throw new BackupEnvelopeException(
				'Unsupported backup envelope version ' . (int)($data['version'] ?? 0) . '; this build reads version '
				. self::VERSION . '.');
		}
		if (!is_array($data['recipients'] ?? null) || !$data['recipients']) {
			throw new BackupEnvelopeException('This backup envelope lists no recipients.');
		}
		return $data;
	}

	/** Write an envelope beside its archive, owner-readable only. */
	public static function write_sidecar($path, array $envelope) {
		if (@file_put_contents($path, self::encode($envelope)) === false) {
			throw new BackupEnvelopeException('Could not write the backup envelope to ' . $path . '.');
		}
		@chmod($path, 0600);
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
