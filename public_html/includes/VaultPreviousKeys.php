<?php
/**
 * VaultPreviousKeys - an account vault's retired keys, each kept locked under
 * the vault's CURRENT key, so content still sealed to an older key opens
 * whenever the vault is open, with nothing for its owner to do.
 *
 * Why: a rotation re-seals everything it can find, but content can still
 * reach an old key afterwards: mail a relay sealed before it learned the new
 * key, a row written at the wrong moment. Retiring the old key's unlockers
 * leaves it wrapped only under the passkey's PRF output, which the server never
 * holds, so without this that content opens only through a passkey ceremony.
 *
 * Shape: uev_previous_keys is JSON, a list of
 *   {generation, wrapped, sealed_kek[, swept][, sweep_at]}
 * where `wrapped` is the retired secret wrapped (SealedBox::wrapKey) under a
 * random 32-byte KEK, AD "vault:user:previous:{generation}", and `sealed_kek`
 * is that KEK sealed to the current public key, exactly as a row's DEK is
 * (VaultCrypto::sealItemDek). Opening one is: the open window's key opens the
 * KEK, the KEK opens the old secret. A rotation re-seals every KEK to the new
 * key, so the chain follows the vault. It keeps every retired key: an entry is
 * a few hundred bytes, and dropping one would strand whatever still sits on
 * that generation.
 *
 * What it costs: whoever holds the current key can reach the previous ones. A
 * window on the current key reads whatever is still on an older generation
 * (a row a re-seal failed on, a stranded relay blob), so rotating after a
 * suspected compromise of the current window does not cut those leftovers off.
 * No retired unlocker comes back: a lost recovery code stays dead. A row read
 * through a previous key is re-sealed to the current one on the spot
 * (SystemBase::openWithPreviousKeys), so a leftover on an old X25519 key does
 * not stay exposed.
 *
 * Content left on a retired key does not wait to be read: VaultChainSweep moves
 * it to the current key in the owner's window and then marks the entry
 * `swept`, so later unlocks skip it. A rotation keeps the mark on the entries
 * it carries over; the generation it retires starts unswept. A sweep that
 * runs out of time records where it stopped (`sweep_at`) and the next window
 * goes on from there.
 *
 * @version 1.1 - entries carry `swept` and the sweep's place, `sweep_at` (VaultChainSweep); recordSweep()
 * @version 1.0
 */
class VaultPreviousKeys {

	/** The AD a retired secret is wrapped under. */
	public static function ad(int $generation): string {
		return 'vault:user:previous:' . $generation;
	}

	/** The stored entries, newest generation first. */
	public static function entries(UserEncryptionVault $vault): array {
		$list = json_decode((string)$vault->get('uev_previous_keys'), true);
		if (!is_array($list)) {
			return array();
		}
		$out = array();
		foreach ($list as $e) {
			if (is_array($e) && isset($e['generation'], $e['wrapped'], $e['sealed_kek'])) {
				$out[(int)$e['generation']] = array('generation' => (int)$e['generation'],
					'wrapped' => (string)$e['wrapped'], 'sealed_kek' => (string)$e['sealed_kek']);
				if (!empty($e['swept'])) {
					$out[(int)$e['generation']]['swept'] = true;
				} elseif (isset($e['sweep_at']['step']) && is_string($e['sweep_at']['step'])) {
					$out[(int)$e['generation']]['sweep_at'] = array('step' => $e['sweep_at']['step'],
						'after' => (int)($e['sweep_at']['after'] ?? 0));
				}
			}
		}
		krsort($out);
		return array_values($out);
	}

	/**
	 * The chain for a vault moving from $old_key to $new_public_key: every
	 * entry already held, its KEK re-sealed to the new key, plus each
	 * $retiring generation (generation => an unlocker that opens it), wrapped
	 * fresh. Returns the JSON to store in uev_previous_keys.
	 *
	 * @param array<int,array> $retiring generation => unlocker (VaultUnlock::openKey's first argument)
	 */
	public static function forNewKey(UserEncryptionVault $vault, VaultKey $old_key, array $retiring, string $new_public_key): string {
		$crypto = new VaultCrypto();
		$out = array();
		foreach (self::entries($vault) as $e) {
			// A KEK that will not open is not dropped: the rotation is inside its
			// transaction, so throwing rolls it back whole, and committing a
			// shorter chain would strand that generation for good.
			$kek = $crypto->openItemDek($e['sealed_kek'], $old_key);
			$out[$e['generation']] = array('generation' => $e['generation'], 'wrapped' => $e['wrapped'],
				'sealed_kek' => $crypto->sealItemDek($kek, $new_public_key));
			if (!empty($e['swept'])) {
				$out[$e['generation']]['swept'] = true;
			}
			sodium_memzero($kek);
		}
		foreach ($retiring as $generation => $unlocker) {
			$entry = self::wrap((int)$generation, $unlocker, $new_public_key);
			if ($entry !== null) {
				$out[(int)$generation] = $entry;
			}
		}
		krsort($out);
		return json_encode(array_values($out));
	}

	/**
	 * Add retired generations a passkey still opens to a vault's chain, sealed
	 * to its current key: content stranded before the chain existed, reached
	 * the first time its owner unlocks with that passkey. Writes the vault row
	 * when anything was added. Returns the generations added.
	 */
	public static function adoptRetired(UserEncryptionVault $vault, VaultKey $current_key, int $passkey_credential_id, string $kek): array {
		$current_generation = (int)$vault->get('uev_key_generation');
		$held = array();
		foreach (self::entries($vault) as $e) {
			$held[$e['generation']] = true;
		}
		// Every retired wrapping of this passkey per generation, newest first:
		// an orphan a crash left at a generation number the next rotation then
		// minted for real (VaultCeremonies' orphan cleanup) carries the same
		// number, so the one that opens is taken, the newest tried first.
		$candidates = array();
		foreach (new MultiUserEncryptionWrapping(array('vault_id' => $vault->key, 'deleted' => true,
				'unlocker_type' => UserEncryptionWrapping::TYPE_PASSKEY)) as $w) {
			$generation = (int)$w->get('uew_key_generation');
			if ($generation < $current_generation && !isset($held[$generation])
					&& (int)$w->get('uew_pkc_passkey_credential_id') === $passkey_credential_id) {
				$candidates[$generation][(int)$w->key] = $w;
			}
		}
		$retiring = array();
		foreach ($candidates as $generation => $rows) {
			krsort($rows);
			foreach ($rows as $w) {
				try {
					PoolVaultKey::open($w->unlocker($kek));
				} catch (Throwable $e) {
					continue;
				}
				$retiring[$generation] = $w->unlocker($kek);
				break;
			}
		}
		if (!$retiring) {
			return array();
		}
		return self::add($vault, $retiring);
	}

	/**
	 * Add retired generations (generation => an unlocker that opens it) to the
	 * chain of a vault ALREADY on its current key, sealed to that key, and
	 * save the row when anything was added. Returns the generations added.
	 */
	public static function add(UserEncryptionVault $vault, array $retiring): array {
		$added = array();
		$entries = array();
		foreach (self::entries($vault) as $e) {
			$entries[$e['generation']] = $e;
		}
		foreach ($retiring as $generation => $unlocker) {
			$entry = self::wrap((int)$generation, $unlocker, (string)$vault->get('uev_public_key'));
			if ($entry !== null) {
				$entries[(int)$generation] = $entry;
				$added[] = (int)$generation;
			}
		}
		if (!$added) {
			return array();
		}
		return self::store($vault, $entries) ? $added : array();
	}

	/**
	 * Record the chain sweep's state for one generation (VaultChainSweep): true
	 * when nothing found on it is left to move, or where the sweep stopped,
	 * ['step' => string, 'after' => int]. False when the chain changed
	 * meanwhile; the sweep then starts that generation over.
	 */
	public static function recordSweep(UserEncryptionVault $vault, int $generation, $state): bool {
		$entries = array();
		foreach (self::entries($vault) as $e) {
			$entries[$e['generation']] = $e;
		}
		if (!isset($entries[$generation])) {
			return false;
		}
		unset($entries[$generation]['swept'], $entries[$generation]['sweep_at']);
		if ($state === true) {
			$entries[$generation]['swept'] = true;
		} else {
			$entries[$generation]['sweep_at'] = array('step' => (string)$state['step'], 'after' => (int)$state['after']);
		}
		return self::store($vault, $entries);
	}

	/** Write the chain $entries (generation => entry) over the one $vault was read with. */
	private static function store(UserEncryptionVault $vault, array $entries): bool {
		krsort($entries);
		// A compare-and-swap, not a save: an unlock that loaded the vault before
		// another device's rotation committed would otherwise write back a chain
		// sealed to the old key over the rotation's. It lands only while the row
		// still has the key these entries were sealed to and the chain this read;
		// otherwise nothing is written, and the next unlock tries again.
		$json = json_encode(array_values($entries));
		$read = $vault->get('uev_previous_keys');
		$stmt = DbConnector::get_instance()->get_db_link()->prepare('UPDATE uev_user_encryption_vaults
			SET uev_previous_keys = ? WHERE uev_user_encryption_vault_id = ? AND uev_public_key = ?
			AND uev_previous_keys IS NOT DISTINCT FROM ?');
		SealedEgressGuard::contentFree('vault_keys', function () use ($stmt, $json, $vault, $read) {
			$stmt->execute(array($json, (int)$vault->key, (string)$vault->get('uev_public_key'),
				($read === null || $read === '') ? null : (string)$read));
		});
		if ($stmt->rowCount() !== 1) {
			return false;
		}
		$vault->set('uev_previous_keys', $json);
		return true;
	}

	/** Per-request cache, keyed on the vault, the current key and the chain as stored: generation => VaultKey. */
	private static $opened = array();

	/**
	 * Every previous key of $vault this open $current_key reaches, newest
	 * first: generation => VaultKey. Empty when the chain is empty or the
	 * current key is not the vault's.
	 */
	public static function open(UserEncryptionVault $vault, VaultKey $current_key): array {
		$cache = (int)$vault->key . ':' . $current_key->id() . ':' . hash('sha256', (string)$vault->get('uev_previous_keys'));
		if (isset(self::$opened[$cache])) {
			return self::$opened[$cache];
		}
		$crypto = new VaultCrypto();
		$keys = array();
		foreach (self::entries($vault) as $e) {
			try {
				$kek = $crypto->openItemDek($e['sealed_kek'], $current_key);
				$keys[$e['generation']] = PoolVaultKey::open(array('wrapped' => $e['wrapped'], 'kek' => $kek,
					'ad' => self::ad($e['generation'])))['key'];
				sodium_memzero($kek);
			} catch (Throwable $ex) {
				continue;
			}
		}
		return self::$opened[$cache] = $keys;
	}

	/** One retired generation, wrapped under a fresh KEK sealed to $public_key; null when $unlocker does not open it. */
	private static function wrap(int $generation, array $unlocker, string $public_key): ?array {
		$kek = random_bytes(32);
		try {
			$opened = PoolVaultKey::open($unlocker, array(array('kek' => $kek, 'ad' => self::ad($generation))));
		} catch (Throwable $e) {
			return null;
		}
		$entry = array('generation' => $generation, 'wrapped' => $opened['wrappings'][0],
			'sealed_kek' => (new VaultCrypto())->sealItemDek($kek, $public_key));
		sodium_memzero($kek);
		return $entry;
	}
}
