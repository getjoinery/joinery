<?php
/**
 * BackupRecoveryKey — the operator's recovery keypair, and the ceremony that
 * makes it trustworthy.
 *
 * One public key is configured per site. Every backup this site produces seals
 * a copy of its data key to that public key (see BackupEnvelope), so the single
 * private half — kept in the operator's password manager, never on a server —
 * opens every backup from every site that was given the same public key.
 *
 * The possession ceremony is load-bearing, not paperwork. Sealed-box output is
 * unverifiable without the private key, so a mistyped public key would "work":
 * every backup would report itself encrypted and recoverable while every one of
 * them was permanently unopenable, discovered only at disaster time. The
 * operator must open a challenge once before this site will seal anything to
 * the value, and encrypted backups refuse to run until they have.
 *
 * The key is a 1216-byte X-Wing public key and a 32-byte seed
 * (specs/post_quantum_sealing.md R3). A stored 32-byte X25519 key is an old
 * one: nothing is sealed to it, and every parse says the operator owes a new
 * key.
 *
 * @version 1.4 - X-Wing keys: parsing takes 1216 bytes and names a 32-byte key as one to rotate;
 *                both challenges are v2 seals, each under its own scope (BROWSER_INFO, CLI_INFO)
 * @version 1.3 - prove_for(): setup's proof is the Recovery Readiness ceremony's own check, so it is
 *                recorded in that ledger too; a new owner was told the key was verified and never proven (B177)
 * @version 1.2 - the key is established on this site alone. Nothing writes it from outside and no
 *                proof established elsewhere is accepted here; key_report() reports, and that is
 *                the whole of what a management node may do with this setting
 * @version 1.1 - key_report(), so a management node can see what a site holds
 * @version 1.0 - extracted to core from the server_manager custody class; covers the recovery
 *                key only, because per-node key escrow no longer exists
 */

class BackupRecoveryKeyException extends Exception {}

class BackupRecoveryKey {

	/** stg_settings name holding the base64 recovery PUBLIC key. */
	const PUBLIC_KEY_SETTING = 'backup_recovery_public_key';

	/** stg_settings name holding the sha256 of the PROVEN recovery public key. */
	const PROOF_SETTING = 'backup_recovery_public_key_proven_fpr';

	/** Settings group the upsert path writes under. */
	const SETTING_GROUP = 'backups';

	/** Where recovery-key setup happens. Every surface links here rather than
	 *  rendering its own copy of the panel. */
	const SETUP_URL = '/admin/admin_backups#recovery-key';

	/**
	 * One line describing what is outstanding, so every surface that mentions it
	 * — the Backups page, a node's Backups tab, the fleet dashboard — says the
	 * same thing rather than three near-misses.
	 */
	public static function outstanding_summary(array $state): string {
		switch ($state['state']) {
			case 'unconfigured':
				return 'Backup key recovery has not been set up yet.';
			case 'invalid':
				return 'The recovery key that is configured cannot be read.';
			case 'unproven':
				return 'The recovery key is set but has not been verified yet.';
			default:
				return 'Backup key recovery is set up.';
		}
	}

	/**
	 * The configured recovery public key (raw binary), or throw. Callers that
	 * seal anything must fail loudly when recovery is not set up rather than
	 * silently producing a backup nobody can open.
	 */
	public static function public_key() {
		$raw = self::parse_public_key();
		if (self::read_proof_setting() !== hash('sha256', $raw)) {
			throw new BackupRecoveryKeyException(
				'The recovery key has not been verified. Open the challenge with your recovery key on the '
				. 'Backups settings page before encrypted backups can run.');
		}
		return $raw;
	}

	/** Parse the configured public key without the possession check, or throw. */
	public static function parse_public_key() {
		$b64 = trim((string)self::read_public_key_setting());
		if ($b64 === '') {
			throw new BackupRecoveryKeyException(
				'Backup recovery is not configured (' . self::PUBLIC_KEY_SETTING . ' is empty). '
				. 'Encrypted backups refuse to run until a recovery public key is set.');
		}
		$raw = base64_decode($b64, true);
		$why = self::key_shape_error($raw);
		if ($why !== null) {
			throw new BackupRecoveryKeyException($why);
		}
		return $raw;
	}

	/** What makes a decoded stored key unusable, or null when it is a 1216-byte key. */
	private static function key_shape_error($raw): ?string {
		if ($raw !== false && strlen($raw) === SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
			return self::OLD_KEY_MESSAGE;
		}
		if ($raw === false || strlen($raw) !== XWing::PUBLIC_KEY_BYTES) {
			return self::PUBLIC_KEY_SETTING . ' is not a valid base64 recovery public key.';
		}
		return null;
	}

	/** What every surface says about a stored X25519 (v1) recovery key. */
	const OLD_KEY_MESSAGE = 'The recovery key is an old X25519 key, and backups seal to post-quantum keys only. '
		. 'Rotate it from the Backups page (Recovery key, Actions, Rotate key); backups already made stay openable '
		. 'with the key you have.';

	/** True when a public key is set but not yet proven (the verify card should show). */
	public static function needs_possession_proof() {
		try {
			$raw = self::parse_public_key();
		} catch (BackupRecoveryKeyException $e) {
			return false; // nothing configured (or unparseable) — nothing to prove yet
		}
		return self::read_proof_setting() !== hash('sha256', $raw);
	}

	/** Is recovery fully set up (key configured AND proven)? */
	public static function is_ready() {
		try { self::public_key(); return true; }
		catch (BackupRecoveryKeyException $e) { return false; }
	}

	/**
	 * The exact string the operator must recover from the challenge blob.
	 *
	 * A plain sentence, because the operator reads it: recovering something that
	 * says what happened is self-evidently a success, where a hash-shaped token
	 * just looks like more ciphertext to shuttle around. It ends with the key's
	 * full fingerprint, which is what binds it — a proof recovered for one key
	 * can never satisfy a different one. ASCII only and free of any timestamp or
	 * randomness: it has to survive a copy-paste through a terminal and compare
	 * byte for byte.
	 */
	public static function expected_proof_string() {
		return 'Your recovery key opened this message. Backup recovery is proven for key fingerprint '
			. hash('sha256', self::parse_public_key()) . '.';
	}

	/** The command-line proof's own scope: escrow_keypair.php unseal --scope takes it. */
	const CLI_INFO = 'joinery-backup-recovery-possession-cli:';

	/**
	 * A fresh challenge: the expected proof string sealed (a v2 seal under
	 * CLI_INFO) to the configured public key. Only the holder of the matching
	 * private key can open it. Its own scope, not `dek`: a box in the dek scope
	 * is what every backup's data key is, so a challenge opened there would let
	 * a page that swapped one in have the operator paste back a backup's key.
	 * (Seals are randomized, so the blob differs per call — the content is what
	 * matters.)
	 */
	public static function possession_challenge() {
		return base64_encode(SealedBox::sealV2(self::expected_proof_string(), self::parse_public_key(), self::CLI_INFO));
	}

	/**
	 * The command that opens possession_challenge() with a private key file.
	 * A path of plain characters is printed as given, so `~/recovery.key`
	 * still expands in the operator's shell; anything else is quoted.
	 */
	public static function cli_unseal_command(string $private_path): string {
		$path = preg_match('#^[A-Za-z0-9_./~-]+$#', $private_path) ? $private_path : escapeshellarg($private_path);
		return "echo '" . self::possession_challenge() . "' | php " . escapeshellarg(PathHelper::getSiteRoot()
			. '/maintenance_scripts/sysadmin_tools/escrow_keypair.php') . ' unseal --scope ' . self::CLI_INFO
			. ' --private ' . $path;
	}

	/** HKDF context for the browser challenge — never reused by another blob. */
	const BROWSER_INFO = 'joinery-backup-recovery-possession:';

	/**
	 * The same challenge sealed under the browser proof's own scope, so the
	 * operator can prove possession by pasting the key straight out of their
	 * password manager — which is the copy that has to work in a disaster, not
	 * a file left on a server. Opening it needs exactly the same seed as the CLI
	 * challenge; the scope keeps one from ever opening as the other.
	 *
	 * Layout: base64 of SealedBox::sealV2()'s raw form.
	 */
	public static function browser_challenge(): string {
		try {
			return base64_encode(SealedBox::sealV2(self::expected_proof_string(), self::parse_public_key(), self::BROWSER_INFO));
		} catch (RuntimeException $e) {
			throw new BackupRecoveryKeyException('Could not build the verification challenge: ' . $e->getMessage());
		}
	}

	/**
	 * Record proof of possession: the pasted value must be the unsealed
	 * challenge content. On match, the public key's fingerprint is persisted
	 * and public_key() starts honoring the key. Throws on mismatch.
	 */
	public static function record_possession_proof($pasted) {
		$expected = self::expected_proof_string();
		if (!is_string($pasted) || !hash_equals($expected, trim($pasted))) {
			throw new BackupRecoveryKeyException(
				'That is not what the challenge on this page opens to. Paste your recovery key into the box '
				. 'above (or unseal the challenge with escrow_keypair.php) and use the exact sentence it '
				. 'produces.');
		}
		self::write_proof_setting(hash('sha256', self::parse_public_key()));
	}

	/**
	 * A person's proof at setup, recorded where the Recovery Readiness page and
	 * the Backups page read "last proven". It is the same check the readiness
	 * ceremony makes (BackupReadinessItems::verify_recovery_key), so it counts
	 * as one: proving the key at setup and being told it was never proven were
	 * one fact said two ways. Throws on mismatch, recording nothing.
	 */
	public static function prove_for($pasted, int $user_id): void {
		self::record_possession_proof($pasted);
		RecoveryVerification::record('backup_recovery_key', RecoveryVerification::METHOD_CEREMONY, $user_id, true);
	}

	/**
	 * Whether backup recovery is set up, in one shape every surface reads, so
	 * the settings panel, the readiness page, and the fleet dashboard cannot
	 * disagree about it.
	 *
	 * state:
	 *   unconfigured - no recovery public key set
	 *   invalid      - a value is set but is not a box public key
	 *   unproven     - key set, possession not yet demonstrated
	 *   ready        - proven; backups may be sealed to it
	 */
	public static function setup_state(): array {
		$key = self::classify_key(self::read_public_key_setting(), self::read_proof_setting());
		return [
			'state'       => ($key['state'] === 'proven') ? 'ready' : $key['state'],
			'error'       => $key['error'],
			'fingerprint' => $key['fingerprint'],
			'is_ready'    => ($key['state'] === 'proven'),
		];
	}

	/**
	 * Classify a stored key value + proof marker: unconfigured | invalid |
	 * unproven | proven, with the short fingerprint and the reason when there is
	 * one. Pure — no settings, no database — so the state rules can be exercised
	 * without a test ever making a throwaway key look live to a backup run.
	 */
	public static function classify_key($b64, $proof_marker): array {
		$out = ['state' => 'unconfigured', 'fingerprint' => '', 'error' => ''];
		$b64 = trim((string)$b64);
		if ($b64 === '') {
			$out['error'] = 'Backup recovery is not configured (' . self::PUBLIC_KEY_SETTING . ' is empty). '
				. 'Encrypted backups refuse to run until a recovery public key is set.';
			return $out;
		}
		$raw = base64_decode($b64, true);
		$why = self::key_shape_error($raw);
		if ($why !== null) {
			$out['state'] = 'invalid';
			$out['error'] = $why;
			return $out;
		}
		$out['fingerprint'] = substr(hash('sha256', $raw), 0, 16);
		$out['state']       = ((string)$proof_marker === hash('sha256', $raw)) ? 'proven' : 'unproven';
		return $out;
	}

	/**
	 * Set the recovery public key. Parses before writing (a value that cannot
	 * seal is never stored), and clears the possession proof so the new value
	 * must be proven before anything is sealed to it.
	 *
	 * Replacing a PROVEN key is a rotation, not an edit: backups already made
	 * carry data keys sealed to the old public key and stay openable only with
	 * the old private key. Rotation is cheap — re-sealing a data key needs only
	 * the site recipient, never a re-upload — but it is a deliberate procedure,
	 * so pasting over a proven value is refused.
	 */
	public static function set_public_key($b64, $allow_rotation = false) {
		$b64 = trim((string)$b64);
		if ($b64 === '') {
			throw new BackupRecoveryKeyException(
				'Paste the recovery public key printed by "escrow_keypair.php generate", or generate one here.');
		}
		$raw = base64_decode($b64, true);
		if ($raw !== false && strlen($raw) === SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
			throw new BackupRecoveryKeyException(
				'That is an old X25519 recovery key. Backups seal to post-quantum keys only: generate a new key here, '
				. 'or with "escrow_keypair.php generate" on a machine with OpenSSL 3.5 or later.');
		}
		if ($raw === false || strlen($raw) !== XWing::PUBLIC_KEY_BYTES) {
			throw new BackupRecoveryKeyException(
				'That is not a recovery public key. Paste the single base64 line that "escrow_keypair.php '
				. 'generate" prints — not the private key, and not the path to a file.');
		}

		$current = null;
		try { $current = self::parse_public_key(); } catch (BackupRecoveryKeyException $e) { /* none yet */ }

		if ($current !== null && hash_equals($current, $raw)) {
			return; // same key re-pasted: leave any existing proof intact
		}
		if (!$allow_rotation && self::key_in_use($current, self::read_proof_setting())) {
			throw new BackupRecoveryKeyException(
				'A verified recovery key is already in use and backups are sealed to it. Replacing it is a key '
				. 'rotation — backups already made stay openable only with the current private key — so it '
				. 'cannot be done by pasting a new value here. Rotate it from the Backups page: '
				. 'Recovery key, Actions, Rotate key.');
		}

		self::write_setting(self::PUBLIC_KEY_SETTING, $b64);
		self::write_proof_setting(''); // possession must be proven for the new value
	}

	/**
	 * Discard an unproven recovery public key (the "use a different key" path).
	 * Refused once a key is proven — that is rotation, and clearing the setting
	 * would strand the recovery path for backups already sealed to it.
	 */
	public static function clear_public_key() {
		try {
			$current = self::parse_public_key();
			if (self::key_in_use($current, self::read_proof_setting())) {
				throw new BackupRecoveryKeyException(
					'Backups are already sealed to this recovery key; it cannot be cleared here.');
			}
		} catch (BackupRecoveryKeyException $e) {
			if (strpos($e->getMessage(), 'already sealed') !== false) { throw $e; }
			// unparseable/empty value: clearing is exactly the fix
		}
		self::write_setting(self::PUBLIC_KEY_SETTING, '');
		self::write_proof_setting('');
	}

	/**
	 * What this site is holding, in the form something outside it can compare.
	 *
	 * A management node deciding whether a site needs a recovery key must be able
	 * to ask without ever handling a private key, and must be able to tell "this
	 * is the key I manage" from "this is somebody else's key" — so the answer is
	 * the full fingerprint rather than the abbreviated one the UI shows.
	 *
	 * @return array{state:string, fingerprint:string}
	 *         state: unconfigured | invalid | unproven | proven
	 *         fingerprint: full sha256 of the public key, or '' when there isn't one
	 */
	public static function key_report(): array {
		$stored = self::read_public_key_setting();
		$key    = self::classify_key($stored, self::read_proof_setting());
		$fpr    = '';
		if ($key['state'] === 'proven' || $key['state'] === 'unproven') {
			$fpr = hash('sha256', (string)base64_decode(trim((string)$stored), true));
		}
		return array('state' => $key['state'], 'fingerprint' => $fpr);
	}

	/**
	 * There is deliberately no way to write this key from anywhere but this site.
	 *
	 * A recovery key that arrives from outside cannot be verified by the site
	 * receiving it. Sealing to a public key always appears to succeed, so a
	 * substituted one produces archives that report themselves encrypted while
	 * only their substituter can open them — and nothing on this machine, or on
	 * the machine that sent it, looks wrong until the day someone needs to
	 * restore. Possession is proven here, by whoever administers this site,
	 * against a challenge this site issued, or it is not proven at all.
	 *
	 * That is why there is no push path and no fingerprint-accepting shortcut:
	 * set_public_key() plus record_possession_proof(), driven from this site's
	 * own Backups page, is the whole vocabulary. key_report() answers what a
	 * management node may ask — which key this site holds — and asking is all it
	 * may do.
	 */

	/**
	 * Pure: is this key one that backups may already be sealed to? A key is
	 * sealed to from the moment it is proven, so proof alone is the test. An
	 * unproven value — however long it has sat in the setting — is always free
	 * to replace or discard.
	 */
	public static function key_in_use($current_raw, $proof_marker): bool {
		return is_string($current_raw) && $current_raw !== ''
			&& (string)$proof_marker === hash('sha256', $current_raw);
	}

	/** sha256 hex fingerprint of a raw key string. */
	public static function fingerprint($raw) {
		return hash('sha256', $raw);
	}

	/**
	 * Direct stg_settings read of the public key, falling back to the settings
	 * singleton (which also serves file-config values). Direct because the
	 * singleton memoizes non-blank values for the life of the process: a caller
	 * that saves a key and then asks what the key is — the setup panel, a CLI
	 * run, a test — must see what it just wrote.
	 */
	private static function read_public_key_setting() {
		try {
			$db = DbConnector::get_instance()->get_db_link();
			$q = $db->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
			$q->execute([self::PUBLIC_KEY_SETTING]);
			$v = $q->fetchColumn();
			if ($v !== false) {
				return (string)$v;
			}
		} catch (\Throwable $e) {
			error_log('BackupRecoveryKey: public key read failed: ' . $e->getMessage());
		}
		return (string)Globalvars::get_instance()->get_setting(self::PUBLIC_KEY_SETTING, true, true);
	}

	/** Direct stg_settings read — this internal marker is a managed setting, never hand-edited. */
	private static function read_proof_setting() {
		try {
			$db = DbConnector::get_instance()->get_db_link();
			$q = $db->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
			$q->execute([self::PROOF_SETTING]);
			$v = $q->fetchColumn();
			return ($v === false) ? '' : (string)$v;
		} catch (\Throwable $e) {
			return '';
		}
	}

	private static function write_proof_setting($value) {
		self::write_setting(self::PROOF_SETTING, $value);
	}

	/** Write a core backups setting row. */
	private static function write_setting($name, $value) {
		require_once(PathHelper::getIncludePath('data/settings_class.php'));
		Setting::put($name, $value);
	}
}
