<?php
/**
 * VaultClientCustody - the server side of a client-custody Sealed Vault scope
 * (docs/sealed_vault.md, specs/implemented/password_vault.md).
 *
 * ZERO-KNOWLEDGE, restated as a hard rule for anyone touching this file: the
 * server stores and returns OPAQUE BLOBS and nothing else. It never receives,
 * derives, logs, or validates a KEK, a secret key, a DEK, or a plaintext. The
 * browser does every bit of the crypto; these actions are custody-agnostic
 * storage for the wrapped-key rows the browser produces. If you find yourself
 * decrypting, json_decoding a ciphertext, or inspecting the contents of a
 * `uew_wrapped_secret_key`, you have broken the model - stop.
 *
 * Scope-parameterized on purpose: the password manager passes scope
 * 'passwords', Drive will pass 'drive'. Nothing here hardcodes a consumer.
 * Each client-custody scope is its own X25519 keypair with its own per-scope
 * PRF context, so a KEK derived for one scope can never open another's key.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_wrappings_class.php'));

class VaultClientCustodyException extends Exception {}

class VaultClientCustody {

	/** The client-custody scopes and their per-scope WebAuthn PRF context.
	 *  The context is what keeps one scope's unlock from opening another. */
	const SCOPE_CONTEXTS = array(
		UserEncryptionVault::SCOPE_PASSWORDS => 'vault-passwords-kek',
		UserEncryptionVault::SCOPE_DRIVE     => 'vault-drive-kek',
	);

	/** Validate and normalize a requested scope; only client-custody scopes are
	 *  reachable through these actions (the 'user' scope is server-custody and
	 *  has its own vault_* actions). */
	public static function assertClientScope(string $scope): string {
		if (!isset(self::SCOPE_CONTEXTS[$scope])) {
			throw new VaultClientCustodyException('Unknown or non-client vault scope.');
		}
		return $scope;
	}

	/** The PRF context a scope's passkey unlock derives its KEK under. */
	public static function contextForScope(string $scope): string {
		self::assertClientScope($scope);
		return self::SCOPE_CONTEXTS[$scope];
	}

	/** The one client-custody vault row for (user, scope), or null. */
	public static function loadVault(int $user_id, string $scope): ?UserEncryptionVault {
		return UserEncryptionVault::loadForUser($user_id, self::assertClientScope($scope));
	}

	/**
	 * Resolve a WebAuthn credential id (base64url, as the browser reports it)
	 * to the internal pkc row id, verifying the credential is the caller's own
	 * and still live. Storing the internal id (not the b64url) is what lets the
	 * unlocker floor and post-revoke cleanup key on it. PRF-capability is
	 * required: a passkey wrapping is only ever derivable from a PRF-capable
	 * credential.
	 */
	public static function resolveOwnedPrfPasskeyId(int $user_id, string $credential_b64url): int {
		require_once(PathHelper::getIncludePath('data/passkeys_class.php'));
		$creds = new MultiPasskey(['user_id' => $user_id]);
		$creds->load();
		foreach ($creds as $passkey) {
			if ((string)$passkey->get('pkc_credential_id') === $credential_b64url) {
				if (!$passkey->get('pkc_prf_capable')) {
					throw new VaultClientCustodyException('That passkey cannot derive an encryption key (no PRF support).');
				}
				return (int)$passkey->key;
			}
		}
		throw new VaultClientCustodyException('That passkey is not enrolled on your account.');
	}

	/**
	 * Persist a validated set of wrapping rows produced by the browser. Each
	 * item: ['unlocker_type', 'wrapped_secret_key', optional 'credential_id'
	 * (b64url, passkey only), 'salt' (recovery/passphrase), 'label']. The
	 * secret key inside every blob is opaque here - the browser already wrapped
	 * it. Client-custody wrappings tag their own generation (always the current
	 * one at enrollment) via createWrapped()'s null default.
	 */
	public static function persistWrappings(int $user_id, UserEncryptionVault $vault, array $wrappings): void {
		foreach ($wrappings as $w) {
			$type = isset($w['unlocker_type']) ? (string)$w['unlocker_type'] : '';
			$blob = isset($w['wrapped_secret_key']) ? (string)$w['wrapped_secret_key'] : '';
			if ($blob === '') {
				throw new VaultClientCustodyException('A wrapping was missing its wrapped key.');
			}
			$credential_internal_id = null;
			$salt = null;
			$label = isset($w['label']) ? (string)$w['label'] : null;

			if ($type === UserEncryptionWrapping::TYPE_PASSKEY) {
				$cred_b64 = isset($w['credential_id']) ? (string)$w['credential_id'] : '';
				if ($cred_b64 === '') {
					throw new VaultClientCustodyException('A passkey wrapping was missing its credential id.');
				}
				$credential_internal_id = self::resolveOwnedPrfPasskeyId($user_id, $cred_b64);
			} elseif ($type === UserEncryptionWrapping::TYPE_RECOVERY || $type === UserEncryptionWrapping::TYPE_PASSPHRASE) {
				$salt = isset($w['salt']) ? (string)$w['salt'] : (string)$vault->get('uev_salt');
			} else {
				throw new VaultClientCustodyException('Unknown unlocker type in a wrapping.');
			}

			self::insertOpaqueWrapping((int)$vault->key, $type, $blob, $credential_internal_id, $label, $salt);
		}
	}

	/**
	 * Insert one wrapping whose ciphertext the BROWSER produced. Unlike the
	 * server-custody UserEncryptionWrapping::createWrapped(), this never calls
	 * SealedBox::wrapKey() - it stores the browser's blob verbatim. The
	 * two-phase insert is unnecessary because a client-custody blob's AD is a
	 * stable string the browser reconstructs from scope + unlocker (it does not
	 * depend on the row id).
	 */
	private static function insertOpaqueWrapping(int $vault_id, string $type, string $blob, ?int $credential_id, ?string $label, ?string $salt): UserEncryptionWrapping {
		$wrapping = new UserEncryptionWrapping(NULL);
		$wrapping->set('uew_uev_user_encryption_vault_id', $vault_id);
		$wrapping->set('uew_unlocker_type', $type);
		if ($credential_id !== null) {
			$wrapping->set('uew_pkc_credential_id', $credential_id);
		}
		if ($label !== null && $label !== '') {
			$wrapping->set('uew_label', $label);
		}
		if ($salt !== null && $salt !== '') {
			$wrapping->set('uew_salt', $salt);
		}
		$wrapping->set('uew_key_generation', (int)(new UserEncryptionVault($vault_id, TRUE))->get('uev_key_generation'));
		$wrapping->set('uew_wrapped_secret_key', $blob);
		$wrapping->save();
		return $wrapping;
	}

	/**
	 * The keyring view the browser needs to unlock: the public key, the KDF
	 * salt/params for the passphrase and recovery derivations, and every live
	 * wrapping's opaque blob (useless without a KEK the server never has). This
	 * is the client-custody analog of vault_status.
	 */
	public static function statusPayload(int $user_id, string $scope): array {
		$vault = self::loadVault($user_id, $scope);
		if (!$vault) {
			return ['set_up' => false, 'scope' => $scope, 'prf_context' => self::contextForScope($scope)];
		}

		$wrappings = new MultiUserEncryptionWrapping(['vault_id' => $vault->key]);
		$wrappings->load();
		$list = [];
		$passkey_count = 0;
		$unused_recovery = 0;
		$has_passphrase = false;
		foreach ($wrappings as $w) {
			$type = $w->get('uew_unlocker_type');
			if ($type === UserEncryptionWrapping::TYPE_PASSKEY) {
				$passkey_count++;
			}
			if ($type === UserEncryptionWrapping::TYPE_RECOVERY && !$w->get('uew_is_used')) {
				$unused_recovery++;
			}
			if ($type === UserEncryptionWrapping::TYPE_PASSPHRASE) {
				$has_passphrase = true;
			}
			$list[] = [
				'id'                 => (int)$w->key,
				'unlocker_type'      => $type,
				'credential_id'      => self::credentialB64ForWrapping($w),
				'wrapped_secret_key' => $w->get('uew_wrapped_secret_key'),
				'salt'               => $w->get('uew_salt'),
				'label'              => $w->get('uew_label'),
				'is_used'            => (bool)$w->get('uew_is_used'),
			];
		}

		return [
			'set_up'                     => true,
			'scope'                      => $scope,
			'prf_context'                => self::contextForScope($scope),
			'public_key'                 => $vault->get('uev_public_key'),
			'salt'                       => $vault->get('uev_salt'),
			'kdf_params'                 => self::decodeKdfParams($vault->get('uev_kdf_params')),
			'key_generation'             => (int)$vault->get('uev_key_generation'),
			'passkey_wrapping_count'     => $passkey_count,
			'unused_recovery_code_count' => $unused_recovery,
			'has_passphrase'             => $has_passphrase,
			'regenerate_recommended'     => $unused_recovery < 3,
			'wrappings'                  => $list,
		];
	}

	/** Map a passkey wrapping's internal pkc id back to the WebAuthn b64url the
	 *  browser needs to match a PRF assertion to its wrapping. */
	private static function credentialB64ForWrapping(UserEncryptionWrapping $w): ?string {
		if ($w->get('uew_unlocker_type') !== UserEncryptionWrapping::TYPE_PASSKEY) {
			return null;
		}
		$internal_id = (int)$w->get('uew_pkc_credential_id');
		if (!$internal_id) {
			return null;
		}
		require_once(PathHelper::getIncludePath('data/passkeys_class.php'));
		$passkey = new Passkey($internal_id, TRUE);
		return $passkey->key ? (string)$passkey->get('pkc_credential_id') : null;
	}

	/** kdf_params is opaque JSON the browser round-trips; decode only so the API
	 *  hands the browser a JSON object rather than a string (never inspected). */
	private static function decodeKdfParams($raw) {
		if ($raw === null || $raw === '') {
			return null;
		}
		$decoded = json_decode((string)$raw, true);
		return $decoded === null ? null : $decoded;
	}

	/** Store kdf_params exactly as the browser sent it (opaque JSON text). */
	public static function encodeKdfParams($params): ?string {
		if ($params === null) {
			return null;
		}
		if (is_string($params)) {
			return $params;
		}
		return json_encode($params);
	}
}
?>
