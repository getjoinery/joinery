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
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/passkeys_class.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_wrappings_class.php'));

/** A live PRF-capable passkey credential row (the floor checks liveness). */
function vault_fixture_passkey(int $user_id, string $label = 'Vault Test Passkey'): Passkey {
	$p = new Passkey(NULL);
	$p->set('pkc_usr_user_id', $user_id);
	$p->set('pkc_credential_id', 'vault-test-' . bin2hex(random_bytes(8)));
	$p->set('pkc_source_json', '{}');
	$p->set('pkc_prf_capable', true);
	$p->set('pkc_label', $label);
	$p->save();
	harness_register_row('pkc_passkey_credentials', 'pkc_passkey_credential_id', (int)$p->key);
	return $p;
}

/**
 * A complete vault via the real setup ceremony (window closed). Returns
 * ['user','passkey','kek','vault','recovery_codes','key_file'].
 */
function vault_fixture_vault(string $suffix, string $passphrase = '', int $code_count = 10): array {
	$user = make_user('Vault' . $suffix);
	$passkey = vault_fixture_passkey((int)$user->key);
	$kek = random_bytes(32);
	$ceremonies = new VaultCeremonies();
	$result = $ceremonies->setup($user, (int)$passkey->key, (string)$passkey->get('pkc_label'), $kek, $passphrase, $code_count, false);
	harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', (int)$result['vault']->key);
	// uew rows cascade with the vault row (FK ON DELETE CASCADE)
	return [
		'user'           => $user,
		'passkey'        => $passkey,
		'kek'            => $kek,
		'vault'          => $result['vault'],
		'recovery_codes' => $result['recovery_codes'],
		'key_file'       => $result['key_file'],
	];
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
