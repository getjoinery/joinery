<?php
/**
 * Shared fixtures for the Sealed Vault test estate (specs/vault_testing.md).
 * Not a test file (no @joinery-test header, not *_test.php) — required by the
 * vault suites after harness_boot().
 *
 * A synthetic KEK (random_bytes(32)) stands in for a passkey PRF output —
 * cryptographically equivalent; WebAuthn cannot run in CLI.
 */

require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('includes/VaultCeremonies.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlockerKdf.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/passkey_credentials_class.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_wrappings_class.php'));

/** A live PRF-capable passkey credential row (the floor checks liveness). */
function vault_fixture_passkey(int $user_id, string $label = 'Vault Test Passkey'): Passkey {
	$p = new Passkey(NULL);
	$p->set('pkc_usr_user_id', $user_id);
	// Recognizable as a fixture (referential_integrity_test sweeps
	// 'vault-test-%') AND decodable: every ceremony base64url-decodes this back
	// to raw bytes, so the whole string must be valid base64url on a 4-character
	// boundary — a 12-character prefix plus 20 characters of encoded randomness.
	$p->set('pkc_credential_id', 'vault-test-A' . rtrim(strtr(base64_encode(random_bytes(15)), '+/', '-_'), '='));
	$p->set('pkc_source_json', '{}');
	$p->set('pkc_prf_capable', true);
	$p->set('pkc_label', $label);
	$p->save();
	harness_register_row('pkc_passkey_credentials', 'pkc_passkey_credential_id', (int)$p->key);
	return $p;
}

// ── What the browser makes (specs/one_vault_experience.md) ────────────────
// The server never sees a code or a phrase; these build exactly what a
// browser posts, with VaultUnlockerKdf's derivations, so a suite can drive the
// real actions and still prove a code opens both halves.

/** A root vault salt, as the browser makes it (standard base64, 16 bytes). */
function vault_fixture_root_salt(): string {
	return base64_encode(random_bytes(16));
}

/**
 * Wrap raw secret bytes under a raw 32-byte KEK exactly as
 * VaultCrypto.wrapSecretKey does: base64(IV[12] || ciphertext || tag[16]).
 */
function vault_fixture_wrap(string $secret, string $kek, string $ad): string {
	$iv = random_bytes(12);
	$tag = '';
	$ct = openssl_encrypt($secret, 'aes-256-gcm', $kek, OPENSSL_RAW_DATA, $iv, $tag, $ad, 16);
	return base64_encode($iv . $ct . $tag);
}

/** VaultCrypto.unwrapSecretKey in PHP; null when the KEK or AD is wrong. */
function vault_fixture_unwrap(string $blob, string $kek, string $ad): ?string {
	$raw = base64_decode($blob, true);
	if ($raw === false || strlen($raw) < 28) {
		return null;
	}
	$pt = openssl_decrypt(substr($raw, 12, -16), 'aes-256-gcm', $kek, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, -16), $ad);
	return $pt === false ? null : $pt;
}

/**
 * A browser-made code set of $count codes keyed to $root_salt. Returns
 * ['codes' => [index => code], 'post' => the code_set a page posts,
 *  'set' => VaultCeremonies::codeSet() of it, 'root_keks' => [index => raw]].
 */
function vault_fixture_code_set(string $root_salt, int $count = 10): array {
	$box = new SealedBox();
	$id = bin2hex(random_bytes(16));
	$codes = array();
	$entries = array();
	$root_keks = array();
	for ($i = 0; $i < $count; $i++) {
		$code = $box->generateRecoveryCode();
		$codes[$i] = $code;
		$entries[] = array('index' => $i, 'kek' => SealedBox::b64url(VaultUnlockerKdf::codeKekAccount($code, $root_salt)));
		$root_keks[$i] = VaultUnlockerKdf::codeKekRoot($code, $root_salt);
	}
	$post = array('id' => $id, 'entries' => $entries);
	return array('codes' => $codes, 'post' => $post, 'set' => VaultCeremonies::codeSet($post), 'root_keks' => $root_keks);
}

/** The account half of one code's KEK, as a page posts it (code_kek). */
function vault_fixture_code_kek(string $code, string $root_salt): string {
	return SealedBox::b64url(VaultUnlockerKdf::codeKekAccount($code, $root_salt));
}

/**
 * A passphrase's two halves. The browser runs Argon2id; PHP's sodium cannot
 * reproduce its parallelism, and the server never runs it, so a SHA-256
 * stands in for the slow step — the split is the real one.
 * Returns [account KEK raw, root KEK raw].
 */
function vault_fixture_passphrase(string $phrase, string $root_salt): array {
	return VaultUnlockerKdf::passphraseSplit(hash('sha256', base64_decode($root_salt) . $phrase, true));
}

/**
 * The root vault a browser makes beside the account vault: a random secret
 * wrapped under each code's root half, the passkey's second output (when
 * given) and the phrase's root half (when given). Returns
 * ['payload' => what the page posts as `root`, 'secret' => raw root secret,
 *  'recovery' => the recovery wrappings alone (for a regenerate)].
 */
function vault_fixture_root(string $root_salt, array $code_set, ?Passkey $passkey = null, ?string $passkey_root_kek = null,
		?string $phrase_root_kek = null, ?string $secret = null): array {
	$secret = $secret ?? random_bytes(48);
	$wrappings = array();
	if ($passkey !== null) {
		$wrappings[] = array('unlocker_type' => 'passkey', 'credential_id' => (string)$passkey->get('pkc_credential_id'),
			'wrapped_secret_key' => vault_fixture_wrap($secret, $passkey_root_kek ?? random_bytes(32),
				'vault:root:passkey:' . $passkey->get('pkc_credential_id')));
	}
	$recovery = vault_fixture_root_recovery($root_salt, $code_set, $secret);
	$wrappings = array_merge($wrappings, $recovery);
	if ($phrase_root_kek !== null) {
		$wrappings[] = array('unlocker_type' => 'passphrase', 'salt' => $root_salt,
			'wrapped_secret_key' => vault_fixture_wrap($secret, $phrase_root_kek, 'vault:root:passphrase'));
	}
	$public = base64_encode(random_bytes(32));
	return array(
		'payload'  => array('public_key' => $public, 'salt' => $root_salt, 'kdf_params' => array('alg' => 'argon2id'),
			'wrappings' => $wrappings),
		'secret'   => $secret,
		'recovery' => $recovery,
	);
}

/** The root's recovery wrappings of a code set (the twins). */
function vault_fixture_root_recovery(string $root_salt, array $code_set, string $secret): array {
	$out = array();
	foreach ($code_set['root_keks'] as $i => $kek) {
		$out[] = array('unlocker_type' => 'recovery', 'salt' => $root_salt, 'code_set' => $code_set['post']['id'],
			'code_index' => $i, 'wrapped_secret_key' => vault_fixture_wrap($secret, $kek, 'vault:root:recovery'));
	}
	return $out;
}

/**
 * A complete vault via the real setup ceremony (window closed), with its root
 * vault and one code set. A $passphrase makes the passkeyless fallback vault:
 * the fixture passkey is then marked as having failed a real derivation, the
 * only way an account may hold a phrase (§ R8). Returns ['user', 'passkey',
 * 'kek', 'vault', 'recovery_codes', 'key_file', 'root_salt', 'root_secret',
 * 'code_set', 'passphrase_kek'].
 */
function vault_fixture_vault(string $suffix, string $passphrase = '', int $code_count = 10): array {
	$user = make_user('Vault' . $suffix);
	$passkey = vault_fixture_passkey((int)$user->key);
	$kek = random_bytes(32);
	$root_salt = vault_fixture_root_salt();
	$codes = vault_fixture_code_set($root_salt, $code_count);
	$phrase_kek = '';
	$phrase_root = null;
	if ($passphrase !== '') {
		$passkey->set('pkc_prf_capable', false);
		$passkey->set('pkc_prf_failed_time', gmdate('Y-m-d H:i:s'));
		$passkey->save();
		[$phrase_kek, $phrase_root] = vault_fixture_passphrase($passphrase, $root_salt);
	}
	$root = vault_fixture_root($root_salt, $codes, $passphrase === '' ? $passkey : null, null, $phrase_root);
	$ceremonies = new VaultCeremonies();
	$result = $passphrase === ''
		? $ceremonies->setup($user, (int)$passkey->key, (string)$passkey->get('pkc_label'), $kek, '', $codes['set'], $root['payload'], false)
		: $ceremonies->setup($user, 0, null, '', $phrase_kek, $codes['set'], $root['payload'], false);
	vault_fixture_register_vaults((int)$user->key);
	return [
		'user'           => $user,
		'passkey'        => $passkey,
		'kek'            => $passphrase === '' ? $kek : null,
		'vault'          => $result['vault'],
		'recovery_codes' => array_values($codes['codes']),
		'key_file'       => $result['key_file'],
		'root_salt'      => $root_salt,
		'root_secret'    => $root['secret'],
		'code_set'       => $codes,
		'passphrase_kek' => $phrase_kek,
	];
}

/**
 * Register every vault a user holds for teardown. uew rows are removed by the
 * DB-level fk_uew_uev_user_encryption_vault_id ON DELETE CASCADE (declared in
 * user_encryption_wrappings_class.php and materialized by update_database; the
 * referential-integrity gate test verifies it exists).
 */
function vault_fixture_register_vaults(int $user_id): void {
	foreach (new MultiUserEncryptionVault(array('user_id' => $user_id)) as $vault) {
		harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', (int)$vault->key);
	}
}

/**
 * A client-custody vault row (a caller-supplied public key, no server-held
 * private key) so consumers like drive_public_keys can resolve the user's key.
 * This is the raw model for E2E-encrypted scopes where the server never holds
 * the secret; the setup ceremony in vault_fixture_vault() is server-custody and
 * does not fit. Inserts one uev row (client custody, no wrappings), registers it
 * for teardown, and returns its id.
 *
 * @param int    $user_id
 * @param string $public_key  base64 public key the caller minted
 * @param string $scope       vault scope (e.g. 'drive', 'passwords')
 * @return int   the new uev_user_encryption_vault_id
 */
function vault_fixture_client_vault(int $user_id, string $public_key, string $scope = 'drive'): int {
	$dblink = DbConnector::get_instance()->get_db_link();
	$q = $dblink->prepare(
		"INSERT INTO uev_user_encryption_vaults (uev_usr_user_id, uev_scope, uev_custody, uev_public_key, uev_salt, uev_key_generation)
		 VALUES (?, ?, 'client', ?, ?, 1) RETURNING uev_user_encryption_vault_id");
	$q->execute(array($user_id, $scope, $public_key, base64_encode(random_bytes(16))));
	$id = (int)$q->fetchColumn();
	harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', $id);
	return $id;
}

/**
 * A VaultKey for a keypair the test minted itself (SealedBox::generateKeypair()):
 * the secret is wrapped under a throwaway KEK and opened exactly the way the
 * platform opens one — VaultUnlock::openKey() — so the test never needs a
 * constructor for the bytes and exercises the real seam.
 */
function vault_fixture_key(string $secret_b64): VaultKey {
	$box = new SealedBox();
	$kek = random_bytes(32);
	$ad = 'vault-test:' . bin2hex(random_bytes(4));
	$unlocker = ['wrapped' => $box->wrapKey($secret_b64, $kek, $ad), 'kek' => $kek, 'ad' => $ad];
	return VaultUnlock::openKey(0, $unlocker, [], UserEncryptionVault::SCOPE_USER)['key'];
}

/**
 * Arm the current session's window with a test keypair's secret — the fixture
 * form of VaultUnlock::open() for suites that seal content to a keypair they
 * generated. Returns the key so the suite can compare ids or open with it.
 */
function vault_fixture_open_window(int $user_id, string $secret_b64, string $scope = UserEncryptionVault::SCOPE_USER,
		?array $caps = null, string $via = VaultAudit::VIA_UNKNOWN): VaultKey {
	$key = vault_fixture_key($secret_b64);
	VaultUnlock::arm($user_id, $key, $scope, $caps, $via);
	return $key;
}

/** A VaultKey for a keypair nobody sealed anything to — for a consumer that
 *  takes a key it will not use (a fold over plaintext-only rows). */
function vault_fixture_dummy_key(): VaultKey {
	return vault_fixture_key((new SealedBox())->generateKeypair()['secret']);
}

/** True when APCu actually works in this process (CLI needs apc.enable_cli=1). */
function vault_apcu_usable(): bool {
	if (!function_exists('apcu_store')) {
		return false;
	}
	$probe = 'vault_test_probe_' . getmypid();
	apcu_store($probe, 1, 30);
	$ok = apcu_fetch($probe) === 1;
	apcu_delete($probe);
	return $ok;
}

/** Ensure a session id exists so VaultUnlock window calls work in CLI. */
function vault_ensure_session(): bool {
	if (session_id() !== '') {
		return true;
	}
	return @session_start();
}

/** The live (not soft-deleted) wrappings of a vault as an array. */
function vault_live_wrappings(int $vault_id): array {
	$multi = new MultiUserEncryptionWrapping(['vault_id' => $vault_id]);
	$multi->load();
	$out = [];
	foreach ($multi as $w) {
		$out[] = $w;
	}
	return $out;
}
