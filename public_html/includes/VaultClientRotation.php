<?php
/**
 * VaultClientRotation - rotating a client-custody vault's keypair
 * (docs/sealed_vault.md § Rotating a client-custody key).
 *
 * Only the browser holds a client-custody scope's secret, so only the browser
 * can rotate it, and it pays for it honestly: new recovery codes (it never held
 * the old ones), the passphrase again if there is one, and one passkey tap per
 * enrolled passkey. This class is the server's half, which is bookkeeping:
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
 * A pending rotation is only ever finished, never discarded. Keys a consumer's
 * hook moved (Drive's file grants, the password store key) carry no generation
 * the server could count, so there is no telling "nothing moved" from "the hooks
 * moved everything"; and material sealed while the rotation is pending goes to
 * the pending key (UserEncryptionVault::sealingPublicKey()). Discarding the
 * pending key could therefore strand content, so there is no abandon: begin
 * refuses while one is pending, and the way out is to finish it — the new key
 * opens with the unlockers it was given.
 *
 * @version 1.1 - no abandon (it could not see what the hooks moved); assertCanBegin()
 *   for the browser to ask before collecting taps; one vault load per scope
 * @version 1.0
 */
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
	 * pending generation. Needs a new unlocker to open it (a passkey or a
	 * passphrase) and new recovery codes, as setup does.
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
			$scope = VaultCrypto::parseEdgeScope($sealed);
			if ($scope === null) {
				throw new VaultClientCustodyException('A re-sealed key is not a browser-sealed key.');
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
?>
