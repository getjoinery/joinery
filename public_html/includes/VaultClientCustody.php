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
 * @version 1.4 - holds VaultClientRotation, VaultCustodyChange and VaultClientResume too:
 *   one file for client custody, four classes
 * @version 1.3 - opensThroughRoot(), throughRootVaults(): a content vault that opens
 *   through the root rotates under the root (specs/client_custody_mail.md WP6)
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
	 * Whether $vault is a content vault that opens through the root: it holds a
	 * live `root` wrapping for the key in use. It has no unlocker of its own, so
	 * the new key of its rotation takes one `root` wrapping and nothing else.
	 */
	public static function opensThroughRoot(UserEncryptionVault $vault): bool {
		return (new MultiUserEncryptionWrapping(array('vault_id' => (int)$vault->key,
			'unlocker_type' => UserEncryptionWrapping::TYPE_ROOT,
			'key_generation' => (int)$vault->get('uev_key_generation'))))->count() > 0;
	}

	/**
	 * The user's content vaults that open through the root, for the Security
	 * page's rotate controls (they have no recovery card of their own).
	 *
	 * @return array<int,array{scope:string,label:string,pending:bool}>
	 */
	public static function throughRootVaults(int $user_id): array {
		require_once(PathHelper::getIncludePath('includes/VaultScopes.php'));
		$out = array();
		foreach (new MultiUserEncryptionVault(array('user_id' => $user_id)) as $vault) {
			$scope = (string)$vault->get('uev_scope');
			if ((string)$vault->get('uev_custody') !== 'client' || $scope === VaultScopes::ROOT_SCOPE
					|| !VaultScopes::isRegistered($scope) || !self::opensThroughRoot($vault)) {
				continue;
			}
			$out[] = array('scope' => $scope, 'label' => VaultScopes::labelFor($scope),
				'pending' => $vault->get('uev_pending_key_generation') !== null);
		}
		return $out;
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
			$through_root = $through_root || self::opensThroughRoot($vault);
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

// =====================================================================
// Rotating a client-custody key (VaultClientRotation)
// =====================================================================

/**
 * VaultClientRotation - rotating a client-custody vault's keypair
 * (docs/sealed_vault.md § Rotating a client-custody key).
 *
 * Only the browser holds a client-custody scope's secret, so only the browser
 * can rotate it. A vault with unlockers of its own pays for it honestly: new
 * recovery codes (it never held the old ones), the passphrase again if there is
 * one, and one passkey tap per enrolled passkey. A vault that opens through the
 * root costs nothing more than the root being open: the new key is wrapped
 * under the root, whose unlockers and codes are unchanged. This class is the
 * server's half, which is bookkeeping:
 *
 *   1. begin — the browser posts the NEW public key and the new key's
 *      wrappings. They are stored as generation N+1, PENDING: the key in use
 *      and its unlockers are untouched, so a rotation that stops half way
 *      leaves every existing unlocker working.
 *   2. the batch — every sealed DEK under the scope is re-sealed to the new
 *      key by the browser: rows of the models registered with
 *      VaultUnlock::clientReseal() through resealPage()/resealRows(), and keys
 *      kept elsewhere through each consumer's own browser hook. A moved row
 *      carries generation N+1, so the walk resumes where it stopped.
 *   3. commit — refused while any registered row still sits on generation N.
 *      Then generation N's wrappings retire, the pending key becomes the key,
 *      and every linked device that held the scope loses it (it holds the old
 *      secret and must re-link).
 *
 * Consumers registered with VaultUnlock::onClientRotation() hear begin and
 * commit once each is stored.
 *
 * A pending rotation is only ever finished, never discarded. Keys a consumer's
 * hook moved (Drive's file grants, the password store key) carry no generation
 * the server could count, so there is no telling "nothing moved" from "the hooks
 * moved everything"; and material sealed while the rotation is pending goes to
 * the pending key (UserEncryptionVault::sealingPublicKey()). Discarding the
 * pending key could therefore strand content, so there is no abandon: begin
 * refuses while one is pending, and the way out is to finish it — the new key
 * opens with the unlockers it was given.
 *
 * @version 1.5 - commit asks the scope's commit guards first (VaultUnlock::onClientRotationCommit)
 * @version 1.4 - begin and commit tell VaultUnlock::onClientRotation() listeners; a vault
 *   that opens through the root rotates with one `root` wrapping
 * @version 1.3 - a re-sealed key must name a client-custody scope (VaultCrypto::clientCustodyScope)
 * @version 1.2 - the root vault's key is refused (rotating it would orphan every content vault)
 * @version 1.1 - no abandon (it could not see what the hooks moved); assertCanBegin()
 *   for the browser to ask before collecting taps; one vault load per scope
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/VaultScopes.php'));

class VaultClientRotation {

	/** Rows per page of the re-seal walk. */
	const PAGE_MAX = 200;

	/**
	 * Refuse a rotation some consumer could not re-seal for: one that declares
	 * `client_reseals` for this scope and registered nothing, or whose plugin is
	 * switched off (its keys would be left on the retired key).
	 */
	public static function assertResealersPresent(string $scope): void {
		VaultUnlock::loadConsumerBootstraps();
		$unmet = VaultConsumers::unmetClientReseals($scope);
		if (!$unmet) {
			return;
		}
		error_log('Client vault rotation refused for scope ' . $scope . ': no resealer registered by '
			. implode(', ', array_keys($unmet)));
		$inactive = array_keys(array_filter($unmet));
		if ($inactive) {
			throw new VaultClientCustodyException('Rotating now would lock what a switched-off feature keeps in this vault ('
				. implode(', ', $inactive) . '). Nothing was changed. Switch it back on and rotate again.');
		}
		throw new VaultClientCustodyException('Part of this site keeps keys in this vault and cannot re-secure them ('
			. implode(', ', array_keys($unmet)) . '). Nothing was changed.');
	}

	/** The caller's vault for $scope with a rotation pending, or a refusal. */
	private static function pendingVault(int $user_id, string $scope): UserEncryptionVault {
		VaultClientCustody::assertClientScope($scope);
		$vault = VaultClientCustody::loadVault($user_id, $scope);
		if (!$vault) {
			throw new VaultClientCustodyException('Your vault is not set up.');
		}
		if ($vault->get('uev_pending_key_generation') === null) {
			throw new VaultClientCustodyException('No key rotation is under way for this vault.');
		}
		return $vault;
	}

	/**
	 * Whether a rotation of $scope can begin: the vault exists, none is pending,
	 * and every consumer that keeps keys under it can re-seal them. The browser
	 * asks this before collecting passkey taps and the passphrase.
	 */
	public static function assertCanBegin(int $user_id, string $scope): UserEncryptionVault {
		VaultClientCustody::assertClientScope($scope);
		// The root vault's key is not rotated here: every content vault's `root`
		// wrapping is under a key derived from the root's secret, not sealed to
		// its public key, so no reseal would move them and a committed rotation
		// would orphan them all (specs/one_vault_experience.md § As built).
		if ($scope === VaultScopes::ROOT_SCOPE) {
			throw new VaultClientCustodyException('Your vault\'s own key cannot be rotated here.');
		}
		$vault = VaultClientCustody::loadVault($user_id, $scope);
		if (!$vault) {
			throw new VaultClientCustodyException('Your vault is not set up.');
		}
		if ($vault->get('uev_pending_key_generation') !== null) {
			throw new VaultClientCustodyException('A rotation of this vault\'s key is already under way. Finish it first.');
		}
		self::assertResealersPresent($scope);
		return $vault;
	}

	/**
	 * Start a rotation: store the new public key and its wrappings as the
	 * pending generation. A vault with unlockers of its own needs a new one to
	 * open the new key (a passkey or a passphrase) and new recovery codes, as
	 * setup does; a vault that opens through the root takes one `root`
	 * wrapping, made with the root open (VaultClientCustody::opensThroughRoot()).
	 */
	public static function begin(int $user_id, string $scope, string $public_key, array $wrappings): array {
		$vault = self::assertCanBegin($user_id, $scope);

		$raw = base64_decode($public_key, true);
		if ($raw === false || strlen($raw) !== 32) {
			throw new VaultClientCustodyException('The new public key is malformed.');
		}
		if ($public_key === (string)$vault->get('uev_public_key')) {
			throw new VaultClientCustodyException('The new key is the key already in use.');
		}
		if (VaultClientCustody::opensThroughRoot($vault)) {
			// The root opens it, and the root's own passkeys, phrase and codes
			// are the way back in: the new key takes its `root` wrapping alone.
			if (count($wrappings) !== 1 || (string)($wrappings[0]['unlocker_type'] ?? '') !== UserEncryptionWrapping::TYPE_ROOT) {
				throw new VaultClientCustodyException('This vault opens through your vault: its new key takes one wrapping under your vault and nothing else.');
			}
		} else {
			$primary = 0;
			$recovery = 0;
			foreach ($wrappings as $w) {
				$t = (string)($w['unlocker_type'] ?? '');
				if ($t === UserEncryptionWrapping::TYPE_PASSKEY || $t === UserEncryptionWrapping::TYPE_PASSPHRASE) $primary++;
				if ($t === UserEncryptionWrapping::TYPE_RECOVERY) $recovery++;
			}
			if ($primary < 1) {
				throw new VaultClientCustodyException('The new key needs a passkey or a passphrase to unlock it.');
			}
			if ($recovery < 1) {
				throw new VaultClientCustodyException('The new key needs at least one recovery code.');
			}
		}

		$next = (int)$vault->get('uev_key_generation') + 1;
		$db = DbConnector::get_instance()->get_db_link();
		$db->beginTransaction();
		try {
			// Leftovers of an earlier attempt at this generation that never took go first.
			$db->prepare('DELETE FROM uew_user_encryption_wrappings WHERE uew_uev_user_encryption_vault_id = ? AND uew_key_generation = ?')
				->execute(array((int)$vault->key, $next));
			VaultClientCustody::persistWrappings($user_id, $vault, $wrappings, $next);
			$db->prepare('UPDATE uev_user_encryption_vaults SET uev_pending_public_key = ?, uev_pending_key_generation = ?, uev_update_time = now()
				WHERE uev_user_encryption_vault_id = ?')->execute(array($public_key, $next, (int)$vault->key));
			$db->commit();
		} catch (Throwable $e) {
			if ($db->inTransaction()) $db->rollBack();
			throw $e;
		}
		VaultUnlock::clientRotationChanged($user_id, $scope, 'begin');
		return array('pending_key_generation' => $next);
	}

	/**
	 * One page of the rows to re-seal, across the models registered for the
	 * scope, in registration order. $model/$after_id is the cursor the previous
	 * page returned; an empty $model starts at the first model.
	 *
	 * @return array{rows:array, next:?array{model:string,after_id:int}, remaining:int}
	 */
	public static function resealPage(int $user_id, string $scope, string $model, int $after_id, int $limit): array {
		$vault = self::pendingVault($user_id, $scope);
		$generation = (int)$vault->get('uev_key_generation');
		$classes = VaultUnlock::clientResealsFor($scope)['classes'];
		$limit = max(1, min(self::PAGE_MAX, $limit));

		$remaining = 0;
		foreach ($classes as $class) {
			$remaining += $class::browserSealedRowCount($user_id, $scope, $generation);
		}

		$start = ($model === '') ? 0 : array_search($model, $classes, true);
		if ($start === false) {
			throw new VaultClientCustodyException('Unknown model in the re-seal cursor.');
		}
		for ($i = $start; $i < count($classes); $i++) {
			$class = $classes[$i];
			$page = $class::browserResealPage($user_id, $scope, $generation, ($i === $start) ? $after_id : 0, $limit);
			if ($page['rows']) {
				$rows = array_map(function ($r) use ($class) {
					return array('model' => $class, 'id' => $r['id'], 'sealed_dek' => $r['sealed_dek']);
				}, $page['rows']);
				return array('rows' => $rows, 'next' => array('model' => $class, 'after_id' => $page['last_id']),
					'remaining' => $remaining);
			}
		}
		return array('rows' => array(), 'next' => null, 'remaining' => $remaining);
	}

	/**
	 * Store re-sealed DEKs, [{model, id, sealed_dek}]. Each model must be one
	 * registered for the blob's scope; each row must be the caller's.
	 */
	public static function resealRows(int $user_id, array $rows): int {
		$written = 0;
		$vaults = array();   // scope => its vault, loaded once per request
		foreach ($rows as $r) {
			$sealed = (string)($r['sealed_dek'] ?? '');
			$scope = VaultCrypto::clientCustodyScope($sealed);
			if ($scope === null) {
				throw new VaultClientCustodyException('A re-sealed key is not sealed to a client-custody vault.');
			}
			$vault = $vaults[$scope] ?? ($vaults[$scope] = self::pendingVault($user_id, $scope));
			$model = (string)($r['model'] ?? '');
			if (!in_array($model, VaultUnlock::clientResealsFor($scope)['classes'], true)) {
				throw new VaultClientCustodyException('Nothing named ' . $model . ' is re-sealed for this vault.');
			}
			try {
				$model::acceptBrowserReseal($user_id, (int)($r['id'] ?? 0), $sealed,
					(int)$vault->get('uev_key_generation'), (int)$vault->get('uev_pending_key_generation'));
			} catch (RuntimeException $e) {
				throw new VaultClientCustodyException($e->getMessage());
			}
			$written++;
		}
		return $written;
	}

	/**
	 * Make the pending key the key. Refused while any registered row still
	 * sits on the old generation.
	 */
	public static function commit(int $user_id, string $scope): array {
		$vault = self::pendingVault($user_id, $scope);
		$old = (int)$vault->get('uev_key_generation');
		$new = (int)$vault->get('uev_pending_key_generation');
		$left = 0;
		foreach (VaultUnlock::clientResealsFor($scope)['classes'] as $class) {
			$left += $class::browserSealedRowCount($user_id, $scope, $old);
		}
		if ($left > 0) {
			throw new VaultClientCustodyException($left . ' sealed item' . ($left === 1 ? ' is' : 's are')
				. ' still on the old key. Finish re-sealing before the old key retires.');
		}
		$refusal = VaultUnlock::clientRotationCommitRefusal($user_id, $scope);
		if ($refusal !== null) {
			throw new VaultClientCustodyException($refusal);
		}

		$db = DbConnector::get_instance()->get_db_link();
		$db->beginTransaction();
		try {
			$db->prepare('UPDATE uew_user_encryption_wrappings SET uew_delete_time = now()
				WHERE uew_uev_user_encryption_vault_id = ? AND uew_key_generation = ? AND uew_delete_time IS NULL')
				->execute(array((int)$vault->key, $old));
			$db->prepare('UPDATE uev_user_encryption_vaults SET uev_public_key = uev_pending_public_key,
				uev_key_generation = uev_pending_key_generation, uev_pending_public_key = NULL,
				uev_pending_key_generation = NULL, uev_update_time = now() WHERE uev_user_encryption_vault_id = ?')
				->execute(array((int)$vault->key));
			self::forgetScopeOnDevices($user_id, $scope);
			$db->commit();
		} catch (Throwable $e) {
			if ($db->inTransaction()) $db->rollBack();
			throw $e;
		}
		VaultUnlock::clientRotationChanged($user_id, $scope, 'commit');
		return array('key_generation' => $new);
	}

	/**
	 * A linked device held the retired secret: take the scope off every one of
	 * the user's devices. One left holding no vault drops its device key too, so
	 * it reads as holding none (SyncDevice::vault_scopes()).
	 */
	private static function forgetScopeOnDevices(int $user_id, string $scope): void {
		$devices = new MultiSyncDevice(array('user_id' => $user_id, 'deleted' => false));
		foreach ($devices as $device) {
			$held = $device->vault_scopes();
			if (!in_array($scope, $held, true)) {
				continue;
			}
			$left = array_values(array_diff($held, array($scope)));
			$device->set('sde_vault_scopes', $left ? implode(',', $left) : null);
			if (!$left) {
				$device->set('sde_device_pubkey', null);
			}
			$device->save();
		}
	}
}

// =====================================================================
// Moving rows between custodies (VaultCustodyChange)
// =====================================================================

/**
 * VaultCustodyChange - moving rows off a client-custody vault
 * (specs/client_custody_mail.md § R8, the lowering).
 *
 * A row sealed to a client-custody scope (Fortress mail) has a DEK only its
 * owner's browser opens, so only that browser can move it anywhere else. When
 * a row's hook stops naming the scope (a mailbox lowered from Fortress to
 * Private), the browser walks the rows the hook moved, opens each DEK with the
 * scope's session and re-seals it to the vault the hook now names; the server
 * checks and stores the result (SystemBase::acceptBrowserCustodyChange()).
 * Content stays as it is: the DEK does not change, only whose key wraps it.
 *
 * Which models take part is what VaultUnlock::clientReseal() registered for
 * the scope, the same list a rotation re-seals. The walk resumes wherever it
 * stopped, since a moved row is no longer listed.
 *
 * The raise (server custody to client) is the server's work, done row by row
 * in the owner's window: SystemBase::convertRowToClientCustody().
 *
 * @version 1.0
 */
class VaultCustodyChange {

	/** Rows per page of the walk, and per accept request. */
	const PAGE_MAX = 100;

	/**
	 * One page of the caller's rows to move off client-custody $scope, across
	 * the registered models in order. $model/$after_id is the cursor the
	 * previous page returned; an empty $model starts at the first model, and
	 * that first page also carries `remaining` when every model can count
	 * cheaply (backlog(); later pages carry null). A page may be empty with a
	 * `next`: keep walking.
	 * Each row names the vault it moves to and that vault's public key, in the
	 * standard base64 the browser seals to.
	 *
	 * @return array{rows:array, next:?array{model:string,after_id:int}, remaining:?int}
	 */
	public static function page(int $user_id, string $scope, string $model, int $after_id, int $limit): array {
		self::assertScopeVault($user_id, $scope);
		$classes = VaultUnlock::clientResealsFor($scope)['classes'];
		$limit = max(1, min(self::PAGE_MAX, $limit));
		$remaining = null;
		if ($model === '' && $after_id === 0) {
			$remaining = self::backlog($user_id, $scope);
		}

		$start = ($model === '') ? 0 : array_search($model, $classes, true);
		if ($start === false) {
			throw new VaultClientCustodyException('Unknown model in the custody cursor.');
		}
		$keys = array();   // target scope => its public key, loaded once per request
		for ($i = $start; $i < count($classes); $i++) {
			$class = $classes[$i];
			try {
				$page = $class::browserCustodyPage($user_id, $scope, ($i === $start) ? $after_id : 0, $limit);
			} catch (RuntimeException $e) {
				throw new VaultClientCustodyException($e->getMessage());
			}
			if (!$page['rows'] && $page['done']) {
				continue;
			}
			$rows = array();
			foreach ($page['rows'] as $r) {
				$target = $r['target_scope'];
				if (!array_key_exists($target, $keys)) {
					$keys[$target] = self::targetPublicKey($user_id, $target);
				}
				$rows[] = array('model' => $class, 'id' => $r['id'], 'sealed_dek' => $r['sealed_dek'],
					'target_scope' => $target, 'target_public_key' => $keys[$target]);
			}
			$next = (!$page['done'] || $i + 1 < count($classes))
				? ($page['done'] ? array('model' => $classes[$i + 1], 'after_id' => 0)
					: array('model' => $class, 'after_id' => $page['last_id']))
				: null;
			return array('rows' => $rows, 'next' => $next, 'remaining' => $remaining);
		}
		return array('rows' => array(), 'next' => null, 'remaining' => $remaining);
	}

	/**
	 * How many of the caller's rows under $scope are waiting to move, or null
	 * when a model cannot say without reading every row
	 * (SystemBase::browserCustodyBacklog()).
	 */
	public static function backlog(int $user_id, string $scope): ?int {
		VaultClientCustody::assertClientScope($scope);
		$count = 0;
		foreach (VaultUnlock::clientResealsFor($scope)['classes'] as $class) {
			$n = $class::browserCustodyBacklog($user_id, $scope);
			if ($n === null) {
				return null;
			}
			$count += $n;
		}
		return $count;
	}

	/**
	 * Store re-sealed DEKs, [{model, id, sealed_dek}], for rows leaving
	 * $scope. Each model must be registered for $scope; each row must be the
	 * caller's and its hook must name the vault the key is sealed to.
	 */
	public static function accept(int $user_id, string $scope, array $rows): int {
		VaultClientCustody::assertClientScope($scope);
		if (count($rows) > self::PAGE_MAX) {
			throw new VaultClientCustodyException('Too many rows in one request.');
		}
		$classes = VaultUnlock::clientResealsFor($scope)['classes'];
		$written = 0;
		foreach ($rows as $r) {
			$model = (string)($r['model'] ?? '');
			if (!in_array($model, $classes, true)) {
				throw new VaultClientCustodyException('Nothing named ' . $model . ' is kept in this vault.');
			}
			try {
				$model::acceptBrowserCustodyChange($user_id, (int)($r['id'] ?? 0), (string)($r['sealed_dek'] ?? ''));
			} catch (RuntimeException $e) {
				throw new VaultClientCustodyException($e->getMessage());
			}
			$written++;
		}
		return $written;
	}

	/** The caller must hold the scope's vault: its session opens the DEKs being moved. */
	private static function assertScopeVault(int $user_id, string $scope): void {
		if (!VaultClientCustody::loadVault($user_id, $scope)) {
			throw new VaultClientCustodyException('Your vault is not set up.');
		}
	}

	/**
	 * The public key a row moving to $scope is sealed to, standard base64. A
	 * client-custody vault's key is stored that way already (the pending one
	 * during a rotation); a server vault's is base64url.
	 */
	private static function targetPublicKey(int $user_id, string $scope): string {
		$vault = UserEncryptionVault::loadForUser($user_id, $scope);
		if (!$vault) {
			throw new VaultClientCustodyException('There is no "' . $scope . '" vault to move these to.');
		}
		if ((string)$vault->get('uev_custody') === 'client') {
			return $vault->sealingPublicKey();
		}
		return base64_encode(SealedBox::b64url_decode((string)$vault->get('uev_public_key')));
	}
}

// =====================================================================
// Resuming a browser session after a reload (VaultClientResume)
// =====================================================================

/**
 * VaultClientResume - the server's half of keeping a browser-held vault open
 * across a reload of the same tab (specs/client_custody_mail.md § R4a).
 *
 * A client-custody vault's secret lives in the page's memory, so a reload used
 * to drop it and ask for the passkey again. When a scope opens, the browser
 * wraps its secret under a key derived from two random halves: one it keeps in
 * the tab's sessionStorage beside the wrapped secret, one it hands here. A
 * reload asks for this half back, rebuilds the key, and unwraps.
 *
 * Neither half opens anything alone. This half lives in the PHP session, so it
 * is readable only with this session's cookie, and it dies when the session
 * does: sign-out, expiry, or an explicit drop when the vault is locked in the
 * browser. The tab's half dies when the tab closes. The server never sees the
 * tab's half or the wrapped secret, so holding this one gives it nothing it
 * can open.
 *
 * Each tab keeps its own half under a random id it makes (`tab`), so two tabs
 * of one session never overwrite each other; a session holds at most
 * MAX_PER_SCOPE halves per scope, oldest dropped first.
 *
 * Never log a share. The API logs no request bodies; keep it that way here.
 *
 * @version 1.1 - a half kept before the account's last recovery-code use is refused
 * @version 1.0
 */

class VaultClientResumeException extends Exception {}

class VaultClientResume {

	/** Where the halves live in $_SESSION, keyed by scope. */
	const SESSION_KEY = 'jy_vault_client_resume';

	/** A share is 32 random bytes, sent as standard base64. */
	const SHARE_BYTES = 32;

	/** Halves kept per scope in one session (one per open tab, in practice). */
	const MAX_PER_SCOPE = 8;

	/** A tab id: 16 to 64 lowercase hex characters the browser makes. */
	private static function assertTab(string $tab): void {
		if (!preg_match('/^[0-9a-f]{16,64}$/', $tab)) {
			throw new VaultClientResumeException('A resume needs the tab\'s id.');
		}
	}

	/**
	 * Keep $share_b64 for $scope in this session, replacing any earlier one.
	 * The scope must be client custody and the user must hold its vault.
	 *
	 * @throws VaultClientResumeException
	 */
	public static function put(int $user_id, string $scope, string $tab, string $share_b64): void {
		self::assertHeldScope($user_id, $scope);
		self::assertTab($tab);
		$raw = base64_decode($share_b64, true);
		if ($raw === false || strlen($raw) !== self::SHARE_BYTES) {
			throw new VaultClientResumeException('A resume share is 32 bytes.');
		}
		self::assertSession();
		$kept = $_SESSION[self::SESSION_KEY][$scope] ?? array();
		unset($kept[$tab]);
		$kept[$tab] = array(
			'user_id' => $user_id,
			'share'   => base64_encode($raw),
			'set'     => time(),
		);
		while (count($kept) > self::MAX_PER_SCOPE) {
			array_shift($kept);   // insertion order: the oldest first
		}
		$_SESSION[self::SESSION_KEY][$scope] = $kept;
	}

	/**
	 * This session's share for $scope, with the vault's public keys (current,
	 * and pending during a rotation) so the browser can tell a wrapped secret
	 * that a rotation has retired. Null when there is none, or it belongs to
	 * another user (a session that changed hands through login-as).
	 *
	 * @return array{share:string, public_key:string, pending_public_key:?string}|null
	 * @throws VaultClientResumeException
	 */
	public static function get(int $user_id, string $scope, string $tab): ?array {
		$vault = self::assertHeldScope($user_id, $scope);
		self::assertTab($tab);
		$entry = $_SESSION[self::SESSION_KEY][$scope][$tab] ?? null;
		if (!is_array($entry) || intval($entry['user_id'] ?? 0) !== $user_id || (string)($entry['share'] ?? '') === '') {
			self::drop($scope, $tab);
			return null;
		}
		// A recovery code used since this half was kept ends it, in every
		// session: a used code is a possible theft (specs/one_vault_experience.md
		// § R6, review B6). The stamp lives on the vault rows, so it reaches
		// sessions this request cannot see.
		$recovered = UserEncryptionVault::lastRecoveryTime($user_id);
		if ($recovered !== null && intval($entry['set'] ?? 0) < strtotime($recovered . ' UTC')) {
			self::drop($scope, $tab);
			return null;
		}
		$pending = $vault->get('uev_pending_key_generation') !== null ? (string)$vault->get('uev_pending_public_key') : '';
		return array(
			'share'              => (string)$entry['share'],
			'public_key'         => (string)$vault->get('uev_public_key'),
			'pending_public_key' => $pending !== '' ? $pending : null,
		);
	}

	/**
	 * Forget a tab's share for $scope; every tab's for the scope with a null
	 * tab; every scope's with a null scope.
	 */
	public static function drop(?string $scope = null, ?string $tab = null): void {
		if (session_status() !== PHP_SESSION_ACTIVE || !isset($_SESSION[self::SESSION_KEY])) {
			return;
		}
		if ($scope === null) {
			unset($_SESSION[self::SESSION_KEY]);
			return;
		}
		if ($tab === null) {
			unset($_SESSION[self::SESSION_KEY][$scope]);
			return;
		}
		unset($_SESSION[self::SESSION_KEY][$scope][$tab]);
	}

	/** The scope's vault row, refusing a scope that is not client custody or not held. */
	private static function assertHeldScope(int $user_id, string $scope): UserEncryptionVault {
		if ($user_id <= 0) {
			throw new VaultClientResumeException('Sign in first.');
		}
		try {
			$vault = VaultClientCustody::loadVault($user_id, $scope);
		} catch (VaultClientCustodyException $e) {
			throw new VaultClientResumeException($e->getMessage());
		}
		if ($vault === null) {
			throw new VaultClientResumeException('You have no ' . strtolower(VaultScopes::labelFor($scope)) . '.');
		}
		return $vault;
	}

	private static function assertSession(): void {
		if (session_status() !== PHP_SESSION_ACTIVE) {
			throw new VaultClientResumeException('No session to keep the share in.');
		}
	}
}
?>
