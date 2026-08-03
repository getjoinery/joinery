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
 * @version 1.1 - key_report() and accept_proven_fingerprint(), so a control plane can see what a
 *                site holds and hand it a key it has already proven
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
		if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
			throw new BackupRecoveryKeyException(
				self::PUBLIC_KEY_SETTING . ' is not a valid base64 box public key.');
		}
		return $raw;
	}

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

	/**
	 * A fresh challenge: the expected proof string sealed to the configured
	 * public key. Only the holder of the matching private key can open it.
	 * (Sealed boxes are randomized, so the blob differs per call — the content
	 * is what matters.)
	 */
	public static function possession_challenge() {
		return base64_encode(sodium_crypto_box_seal(self::expected_proof_string(), self::parse_public_key()));
	}

	/** HKDF context for the browser challenge — never reused by another blob. */
	const BROWSER_INFO = 'joinery-backup-recovery-possession:';

	/**
	 * The same challenge in a form a browser can open with WebCrypto alone
	 * (X25519 + HKDF-SHA256 + AES-256-GCM), so the operator can prove possession
	 * by pasting the key straight out of their password manager — which is the
	 * copy that has to work in a disaster, not a file left on a server.
	 *
	 * Opening it needs exactly the same X25519 secret key as a sealed box does;
	 * only the packaging differs, because libsodium's sealed-box construction
	 * (XSalsa20-Poly1305 with a blake2b nonce) has no WebCrypto equivalent.
	 *
	 * Layout: base64( ephemeralPub[32] || iv[12] || ciphertext || tag[16] ).
	 */
	public static function browser_challenge(): string {
		$recipient = self::parse_public_key();

		$eph    = sodium_crypto_box_keypair();
		$eph_sk = sodium_crypto_box_secretkey($eph);
		$eph_pk = sodium_crypto_box_publickey($eph);

		$shared  = sodium_crypto_scalarmult($eph_sk, $recipient);
		$aes_key = hash_hkdf('sha256', $shared, 32, self::BROWSER_INFO . $eph_pk . $recipient, '');

		$iv  = random_bytes(12);
		$tag = '';
		$ct  = openssl_encrypt(self::expected_proof_string(), 'aes-256-gcm', $aes_key,
			OPENSSL_RAW_DATA, $iv, $tag);

		sodium_memzero($eph_sk);
		sodium_memzero($eph);
		sodium_memzero($shared);
		sodium_memzero($aes_key);

		if ($ct === false) {
			throw new BackupRecoveryKeyException('Could not build the verification challenge.');
		}
		return base64_encode($eph_pk . $iv . $ct . $tag);
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
		if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
			$out['state'] = 'invalid';
			$out['error'] = self::PUBLIC_KEY_SETTING . ' is not a valid base64 box public key.';
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
		if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
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
				. 'cannot be done by pasting a new value here. Follow the rotation procedure in docs/backups.md.');
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
	 * A control plane deciding whether a site needs a recovery key must be able
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
	 * What a control plane pushing its recovery key to this site should do:
	 * fill an empty slot, complete a matching one, and never overwrite.
	 *
	 * Pure — no settings, no database — because the case that matters is the one
	 * that must never regress. A site already holding a different key may have
	 * archives that open only with the private half of THAT key; writing over it
	 * would leave every one of them unopenable, and the mistake would surface at
	 * the only moment it cannot be undone. So the rule is exercised directly,
	 * without a test ever having to stand up a site to try it on.
	 *
	 * @param string $here_state    unconfigured | invalid | unproven | proven
	 * @param string $here_fpr      full sha256 of the key configured here, '' if none
	 * @param string $incoming_fpr  full sha256 of the key being pushed
	 * @param bool   $have_proof    whether the pusher also carries a proof marker
	 * @return string different | already | proof_write | written
	 */
	public static function push_decision(string $here_state, string $here_fpr,
	                                     string $incoming_fpr, bool $have_proof): string {
		$holds_a_key = ($here_state === 'proven' || $here_state === 'unproven');

		// Somebody else's key, whatever state it is in. Proven, backups may
		// already be sealed to it; unproven, someone may be part-way through
		// setting it up by hand. Neither is ours to discard.
		if ($holds_a_key && !hash_equals($here_fpr, $incoming_fpr)) {
			return 'different';
		}
		if ($here_state === 'proven') {
			return 'already';
		}
		// The same key, missing only its proof. Completing it is not an
		// overwrite: the key stays exactly what it was, and what changes is that
		// backups can run. With no proof to offer there is nothing to add.
		if ($here_state === 'unproven') {
			return $have_proof ? 'proof_write' : 'already';
		}
		// Empty, or holding a value that is not a key at all. An unparseable
		// value can never have sealed anything, so replacing it destroys nothing.
		return 'written';
	}

	/**
	 * Accept a possession proof that was established somewhere else, for the key
	 * this site already holds.
	 *
	 * The ceremony is mandatory for a key a human typed, and this does not weaken
	 * that. What the ceremony catches is a transcription mistake: a public key
	 * that was mistyped seals happily and produces archives nobody can open, and
	 * only a demonstration of the private half rules that out. A key copied
	 * machine-to-machine from a control plane that has already run the ceremony
	 * has no transcription step to go wrong, and re-running the ceremony on each
	 * site would establish nothing that is not already known.
	 *
	 * The fingerprint still has to match what is configured here, so this can
	 * only ever complete the key this site is actually holding — it cannot mark
	 * some other key proven, and it cannot make an unparseable value usable.
	 */
	public static function accept_proven_fingerprint($fpr): void {
		$expected = hash('sha256', self::parse_public_key());
		if (!is_string($fpr) || !hash_equals($expected, strtolower(trim($fpr)))) {
			throw new BackupRecoveryKeyException(
				'That fingerprint does not belong to the recovery key configured here, so it proves nothing '
				. 'about it. Nothing was changed.');
		}
		self::write_proof_setting($expected);
	}

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

	/** Upsert a core backups setting row. */
	private static function write_setting($name, $value) {
		$db = DbConnector::get_instance()->get_db_link();
		$up = $db->prepare(
			"INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name)
			 VALUES (?, ?, 1, NOW(), NOW(), ?)
			 ON CONFLICT (stg_name) DO UPDATE SET stg_value = EXCLUDED.stg_value, stg_update_time = NOW()");
		$up->execute([$name, $value, self::SETTING_GROUP]);
	}
}
