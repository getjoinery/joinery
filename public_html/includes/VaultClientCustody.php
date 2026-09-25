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
 * The root vault and content scopes (specs/one_vault_experience.md): the
 * `root` scope is opened by the person's unlockers (passkeys, the passphrase
 * where one is allowed, recovery codes from the one set); a content scope
 * holds one `root` wrapping — its secret under a key the browser derives from
 * the root's secret — and nothing else. A passphrase wrapping is accepted only
 * on the root, and only for an account whose passkeys cannot hold a key (R8).
 *
 * @version 1.2 - root and content scopes; `root` wrappings; code set id/index on
 *   recovery wrappings; passphrase gated (R8); forgetDevices()
 * @version 1.1 - wrappings carry a key generation; the status lists the one in use and,
 *   apart, a pending rotation's; assertNoPendingRotation()
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_wrappings_class.php'));

class VaultClientCustodyException extends Exception {}

class VaultClientCustody {

	/** Validate and normalize a requested scope; only client-custody scopes are
	 *  reachable through these actions (the 'user' scope is server-custody and
	 *  has its own vault_* actions). Which scopes exist, and whose custody they
	 *  are, comes from VaultScopes — so a plugin adds one by declaring it. */
	public static function assertClientScope(string $scope): string {
		require_once(PathHelper::getIncludePath('includes/VaultScopes.php'));
		if (!VaultScopes::isClientCustody($scope)) {
			throw new VaultClientCustodyException('Unknown or non-client vault scope.');
		}
		return $scope;
	}

	/** The PRF context a scope's passkey unlock derives its KEK under. */
	public static function contextForScope(string $scope): string {
		self::assertClientScope($scope);
		require_once(PathHelper::getIncludePath('includes/VaultScopes.php'));
		return VaultScopes::prfContext($scope);
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
		require_once(PathHelper::getIncludePath('data/passkey_credentials_class.php'));
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
	public static function persistWrappings(int $user_id, UserEncryptionVault $vault, array $wrappings, ?int $key_generation = null): void {
		require_once(PathHelper::getIncludePath('includes/VaultScopes.php'));
		$is_root = ((string)$vault->get('uev_scope') === VaultScopes::ROOT_SCOPE);
		// A content vault that opens through the root (it holds, or is being
		// given, a `root` wrapping) takes no unlocker of its own. One made before
		// the root keeps its own until it is given one (§ As built, D3): its
		// rotation and enrolments still write them.
		$through_root = false;
		if (!$is_root) {
			foreach ($wrappings as $w) {
				if (($w['unlocker_type'] ?? '') === UserEncryptionWrapping::TYPE_ROOT) {
					$through_root = true;
				}
			}
			$through_root = $through_root || (new MultiUserEncryptionWrapping([
				'vault_id' => $vault->key, 'unlocker_type' => UserEncryptionWrapping::TYPE_ROOT]))->count() > 0;
		}
		foreach ($wrappings as $w) {
			$type = isset($w['unlocker_type']) ? (string)$w['unlocker_type'] : '';
			$blob = isset($w['wrapped_secret_key']) ? (string)$w['wrapped_secret_key'] : '';
			if ($blob === '') {
				throw new VaultClientCustodyException('A wrapping was missing its wrapped key.');
			}
			$credential_internal_id = null;
			$salt = null;
			$label = isset($w['label']) ? (string)$w['label'] : null;
			$code_set = null;
			$code_index = null;

			// The root opens through the person's own unlockers, never another
			// vault; a content vault that opens through the root, through it alone.
			if ($is_root && $type === UserEncryptionWrapping::TYPE_ROOT) {
				throw new VaultClientCustodyException('Your vault cannot be opened by another vault.');
			}
			if ($through_root && $type !== UserEncryptionWrapping::TYPE_ROOT) {
				throw new VaultClientCustodyException('This vault opens through your vault; it takes no unlocker of its own.');
			}

			if ($type === UserEncryptionWrapping::TYPE_ROOT) {
				// no salt, no credential: the key comes from the root's secret
			} elseif ($type === UserEncryptionWrapping::TYPE_PASSKEY) {
				$cred_b64 = isset($w['credential_id']) ? (string)$w['credential_id'] : '';
				if ($cred_b64 === '') {
					throw new VaultClientCustodyException('A passkey wrapping was missing its credential id.');
				}
				$credential_internal_id = self::resolveOwnedPrfPasskeyId($user_id, $cred_b64);
			} elseif ($type === UserEncryptionWrapping::TYPE_RECOVERY || $type === UserEncryptionWrapping::TYPE_PASSPHRASE) {
				if ($type === UserEncryptionWrapping::TYPE_PASSPHRASE && !self::passphraseAllowed($user_id)) {
					throw new VaultClientCustodyException('Your passkey can hold your key, so a passphrase is not offered.');
				}
				$salt = isset($w['salt']) ? (string)$w['salt'] : (string)$vault->get('uev_salt');
				if ($type === UserEncryptionWrapping::TYPE_RECOVERY && isset($w['code_set'])) {
					$code_set = strtolower((string)$w['code_set']);
					$code_index = (int)($w['code_index'] ?? -1);
					if (!preg_match('/^[0-9a-f]{32}$/', $code_set) || $code_index < 0 || $code_index > 19) {
						throw new VaultClientCustodyException('A recovery code was not sent in the expected form.');
					}
				}
			} else {
				throw new VaultClientCustodyException('Unknown unlocker type in a wrapping.');
			}

			$row = self::insertOpaqueWrapping((int)$vault->key, $type, $blob, $credential_internal_id, $label, $salt, $key_generation);
			if ($code_set !== null) {
				$row->set('uew_code_set', $code_set);
				$row->set('uew_code_index', $code_index);
				$row->save();
			}
		}
	}

	/**
	 * Create a client-custody vault from what the browser made: the public key,
	 * the salt and KDF params, and the opaque wrappings. Runs inside the
	 * caller's transaction when one is open (the account setup and the code
	 * set adoption create the root beside the account vault), else in its own.
	 *
	 * The floor at birth: the root takes at least one everyday unlocker (a
	 * passkey, or the passphrase where allowed) and the codes of one set; a
	 * content scope takes one `root` wrapping and nothing else
	 * (specs/one_vault_experience.md § R2, R4). The root is made only in the
	 * request that gives the account vault the same codes — $paired_code_set,
	 * VaultCeremonies::codeSet()'s shape, required for it — so one set opens
	 * both; its codes must be that set, index for index.
	 *
	 * @throws VaultClientCustodyException with the message to show
	 */
	public static function createVault(int $user_id, string $scope, array $input, ?array $paired_code_set = null): UserEncryptionVault {
		require_once(PathHelper::getIncludePath('includes/VaultScopes.php'));
		self::assertClientScope($scope);
		$public_key = isset($input['public_key']) ? (string)$input['public_key'] : '';
		$salt       = isset($input['salt']) ? (string)$input['salt'] : '';
		$wrappings  = isset($input['wrappings']) && is_array($input['wrappings']) ? $input['wrappings'] : array();
		if ($public_key === '' || $salt === '') {
			throw new VaultClientCustodyException('Missing vault key material.');
		}

		$primary = 0; $root = 0; $indices = array(); $sets = array();
		foreach ($wrappings as $w) {
			$t = isset($w['unlocker_type']) ? $w['unlocker_type'] : '';
			if ($t === UserEncryptionWrapping::TYPE_PASSKEY || $t === UserEncryptionWrapping::TYPE_PASSPHRASE) $primary++;
			if ($t === UserEncryptionWrapping::TYPE_RECOVERY) {
				$sets[strtolower((string)($w['code_set'] ?? ''))] = true;
				$indices[] = (int)($w['code_index'] ?? -1);
			}
			if ($t === UserEncryptionWrapping::TYPE_ROOT) $root++;
		}
		if ($scope === VaultScopes::ROOT_SCOPE) {
			if ($primary < 1) {
				throw new VaultClientCustodyException('Your vault needs a passkey or a passphrase to unlock it.');
			}
			if (!$indices || count($sets) !== 1 || isset($sets[''])) {
				throw new VaultClientCustodyException('Your vault needs its recovery codes.');
			}
			if ($paired_code_set === null) {
				// The root is made only beside the account vault, in the request
				// that gives both the same codes (setup, code set adoption). Made
				// on its own it would carry a second set of codes, or, before the
				// account vault exists, block that vault's setup for good.
				throw new VaultClientCustodyException(UserEncryptionVault::loadForUser($user_id)
					? 'Unlock your vault: that finishes setting it up.'
					: 'Set up your vault on your security page.');
			}
			$want = array_map(function ($e) { return (int)$e[0]; }, $paired_code_set['entries']);
			sort($want);
			sort($indices);
			if (!isset($sets[$paired_code_set['id']]) || $want !== $indices) {
				throw new VaultClientCustodyException('Your recovery codes did not arrive together. Reload the page and try again.');
			}
		} elseif ($root < 1 || $root !== count($wrappings)) {
			throw new VaultClientCustodyException('This vault opens through your vault. Open your vault first.');
		}

		if (self::loadVault($user_id, $scope)) {
			throw new VaultClientCustodyException('Your vault is already set up.');
		}

		$db = DbConnector::get_instance()->get_db_link();
		$owns_tx = !$db->inTransaction();
		if ($owns_tx) {
			$db->beginTransaction();
		}
		try {
			$vault = new UserEncryptionVault(NULL);
			$vault->set('uev_usr_user_id', $user_id);
			$vault->set('uev_scope', $scope);
			$vault->set('uev_custody', UserEncryptionVault::CUSTODY_CLIENT);
			$vault->set('uev_public_key', $public_key);
			$vault->set('uev_salt', $salt);
			$vault->set('uev_kdf_params', self::encodeKdfParams($input['kdf_params'] ?? null));
			$vault->set('uev_key_generation', 1);
			$vault->save();

			self::persistWrappings($user_id, $vault, $wrappings);

			if ($owns_tx) {
				$db->commit();
			}
			return $vault;
		} catch (Throwable $e) {
			if ($owns_tx && $db->inTransaction()) {
				$db->rollBack();
			}
			throw $e;
		}
	}

	/**
	 * Replace the root vault's passphrase wrapping with the browser's (none
	 * removes it), inside the caller's transaction, beside the account vault's
	 * own phrase change (specs/one_vault_experience.md § R7). The phrase is one:
	 * both halves change together or neither does.
	 *
	 * @throws VaultClientCustodyException
	 */
	public static function replaceRootPassphrase(int $user_id, array $wrappings): void {
		require_once(PathHelper::getIncludePath('includes/VaultScopes.php'));
		$root = self::loadVault($user_id, VaultScopes::ROOT_SCOPE);
		if (!$root) {
			if (!$wrappings) {
				return;
			}
			throw new VaultClientCustodyException('Your vault is not set up.');
		}
		self::assertNoPendingRotation($root);
		if (count($wrappings) > 1) {
			throw new VaultClientCustodyException('Your vault takes one passphrase.');
		}
		foreach ($wrappings as $w) {
			if (($w['unlocker_type'] ?? '') !== UserEncryptionWrapping::TYPE_PASSPHRASE) {
				throw new VaultClientCustodyException('A wrapping for your vault was not the kind expected.');
			}
		}
		$old = new MultiUserEncryptionWrapping(['vault_id' => $root->key, 'unlocker_type' => UserEncryptionWrapping::TYPE_PASSPHRASE]);
		foreach ($old as $row) {
			$row->soft_delete();
		}
		self::persistWrappings($user_id, $root, $wrappings);
	}

	/**
	 * A passphrase is for an account whose passkeys cannot hold a key, and for
	 * no other (specs/one_vault_experience.md § R8): at least one passkey, and
	 * every one provably incapable.
	 */
	public static function passphraseAllowed(int $user_id): bool {
		require_once(PathHelper::getIncludePath('data/passkey_credentials_class.php'));
		return Passkey::userNeedsPassphraseFallback($user_id);
	}

	/**
	 * Replace the root vault's recovery wrappings with the root halves of a
	 * browser-made set whose account halves the caller is storing in the same
	 * transaction (specs/one_vault_experience.md § R6: one set opens both).
	 * Every wrapping must be a recovery wrapping of that set, one per index
	 * the account side holds. No transaction of its own: the caller's.
	 */
	public static function replaceRootRecovery(int $user_id, array $code_set, array $wrappings): void {
		require_once(PathHelper::getIncludePath('includes/VaultScopes.php'));
		$root = self::loadVault($user_id, VaultScopes::ROOT_SCOPE);
		if (!$root) {
			throw new VaultClientCustodyException('Your vault is not set up.');
		}
		self::assertNoPendingRotation($root);
		$want = array();
		foreach ($code_set['entries'] as $entry) {
			$want[(int)$entry[0]] = true;
		}
		$got = array();
		foreach ($wrappings as $w) {
			if (($w['unlocker_type'] ?? '') !== UserEncryptionWrapping::TYPE_RECOVERY
					|| strtolower((string)($w['code_set'] ?? '')) !== $code_set['id']) {
				throw new VaultClientCustodyException('Your new recovery codes did not match. Nothing was changed; try again.');
			}
			$got[(int)($w['code_index'] ?? -1)] = true;
		}
		ksort($want);
		ksort($got);
		if (array_keys($want) !== array_keys($got) || count($got) !== count($wrappings)) {
			throw new VaultClientCustodyException('Your new recovery codes did not match. Nothing was changed; try again.');
		}
		$old = new MultiUserEncryptionWrapping(['vault_id' => $root->key, 'unlocker_type' => UserEncryptionWrapping::TYPE_RECOVERY]);
		foreach ($old as $row) {
			$row->soft_delete();
		}
		self::persistWrappings($user_id, $root, $wrappings);
	}

	/**
	 * Forget every vault key handed to this user's linked devices. A recovery
	 * code in use is a possible theft, so each device re-confirms by linking
	 * again (specs/one_vault_experience.md § R6, review B6).
	 */
	public static function forgetDevices(int $user_id): void {
		if (!class_exists('MultiSyncDevice')) {
			return;
		}
		$devices = new MultiSyncDevice(array('user_id' => $user_id, 'deleted' => false));
		foreach ($devices as $device) {
			if (!$device->vault_scopes()) {
				continue;
			}
			$device->set('sde_vault_scopes', null);
			$device->set('sde_device_pubkey', null);
			$device->save();
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
	private static function insertOpaqueWrapping(int $vault_id, string $type, string $blob, ?int $credential_id, ?string $label, ?string $salt, ?int $key_generation = null): UserEncryptionWrapping {
		$wrapping = new UserEncryptionWrapping(NULL);
		$wrapping->set('uew_uev_user_encryption_vault_id', $vault_id);
		$wrapping->set('uew_unlocker_type', $type);
		if ($credential_id !== null) {
			$wrapping->set('uew_pkc_passkey_credential_id', $credential_id);
		}
		if ($label !== null && $label !== '') {
			$wrapping->set('uew_label', $label);
		}
		if ($salt !== null && $salt !== '') {
			$wrapping->set('uew_salt', $salt);
		}
		$wrapping->set('uew_key_generation', $key_generation
			?? (int)(new UserEncryptionVault($vault_id, TRUE))->get('uev_key_generation'));
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
			return ['set_up' => false, 'scope' => $scope, 'prf_context' => self::contextForScope($scope),
				'passphrase_allowed' => self::passphraseAllowed($user_id)];
		}

		$wrappings = new MultiUserEncryptionWrapping(['vault_id' => $vault->key]);
		$wrappings->load();
		$list = [];
		$pending_list = [];
		$passkey_count = 0;
		$unused_recovery = 0;
		$has_passphrase = false;
		$root_wrapped = false;
		$code_set = null;
		$generation = (int)$vault->get('uev_key_generation');
		$pending_generation = $vault->get('uev_pending_key_generation') !== null ? (int)$vault->get('uev_pending_key_generation') : null;
		foreach ($wrappings as $w) {
			$type = $w->get('uew_unlocker_type');
			// The key in use unlocks with its own generation's wrappings only. A
			// pending rotation's wrappings open the NEW key; they are listed
			// apart, for the browser finishing that rotation.
			if ((int)$w->get('uew_key_generation') !== $generation) {
				if ($pending_generation !== null && (int)$w->get('uew_key_generation') === $pending_generation) {
					$pending_list[] = self::wrappingView($w);
				}
				continue;
			}
			if ($type === UserEncryptionWrapping::TYPE_PASSKEY) {
				$passkey_count++;
			}
			if ($type === UserEncryptionWrapping::TYPE_RECOVERY && !$w->get('uew_is_used')) {
				$unused_recovery++;
			}
			if ($type === UserEncryptionWrapping::TYPE_PASSPHRASE) {
				$has_passphrase = true;
			}
			if ($type === UserEncryptionWrapping::TYPE_ROOT) {
				$root_wrapped = true;
			}
			if ($type === UserEncryptionWrapping::TYPE_RECOVERY && (string)$w->get('uew_code_set') !== '') {
				$code_set = (string)$w->get('uew_code_set');
			}
			$list[] = self::wrappingView($w);
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
			// Opens through the root vault (a content scope made after the root).
			'root_wrapped'               => $root_wrapped,
			// The code set the root's recovery wrappings belong to, or null for
			// codes made before sets existed.
			'code_set'                   => $code_set,
			'passphrase_allowed'         => self::passphraseAllowed($user_id),
			'pending_key_generation'     => $pending_generation,
			'pending_public_key'         => $pending_generation !== null ? $vault->get('uev_pending_public_key') : null,
			'pending_wrappings'          => $pending_list,
		];
	}

	/** One wrapping as the browser sees it. */
	private static function wrappingView(UserEncryptionWrapping $w): array {
		return [
			'id'                 => (int)$w->key,
			'unlocker_type'      => $w->get('uew_unlocker_type'),
			'credential_id'      => self::credentialB64ForWrapping($w),
			'wrapped_secret_key' => $w->get('uew_wrapped_secret_key'),
			'salt'               => $w->get('uew_salt'),
			'label'              => $w->get('uew_label'),
			'is_used'            => (bool)$w->get('uew_is_used'),
			'code_set'           => $w->get('uew_code_set'),
			'code_index'         => $w->get('uew_code_index') !== null ? (int)$w->get('uew_code_index') : null,
		];
	}

	/**
	 * Refuse a change to a vault's unlockers while its key is being rotated:
	 * they wrap the key the commit retires, so whatever was added would be lost
	 * and whatever was removed would no longer matter.
	 */
	public static function assertNoPendingRotation(UserEncryptionVault $vault): void {
		if ($vault->get('uev_pending_key_generation') !== null) {
			throw new VaultClientCustodyException('This vault\'s key is being rotated. Finish the rotation on your security page first.');
		}
	}

	/** Map a passkey wrapping's internal pkc id back to the WebAuthn b64url the
	 *  browser needs to match a PRF assertion to its wrapping. */
	private static function credentialB64ForWrapping(UserEncryptionWrapping $w): ?string {
		if ($w->get('uew_unlocker_type') !== UserEncryptionWrapping::TYPE_PASSKEY) {
			return null;
		}
		$internal_id = (int)$w->get('uew_pkc_passkey_credential_id');
		if (!$internal_id) {
			return null;
		}
		require_once(PathHelper::getIncludePath('data/passkey_credentials_class.php'));
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
