<?php
/**
 * VaultChainSweep - moves content still sealed to one of an account vault's
 * retired keys onto its current key, in the owner's unlock window.
 *
 * A rotation re-seals what its consumers' callbacks find on the generation it
 * retires. Content can still be left on a retired key: a consumer whose
 * callback missed a model (recipe runs, queued AI actions and mail send
 * attempts were missed until joinery_ai 0.26.14 and mailbox 1.144.21), mail a
 * relay sealed to the old key after the rotation, a row written mid-rotation.
 * The vault's chain (VaultPreviousKeys) keeps every retired key under the
 * current one, so that content opens, but only through the chain. This sweep
 * moves it off, so a retired key holds nothing and can be dropped.
 *
 * How: for each chain generation not yet swept, it runs every registered
 * re-seal callback (VaultUnlock::resealCallbacks) as a rotation would, from
 * the retired key and its generation to the current public key and
 * generation. Model callbacks (VaultUnlock::modelReseal) go in batches of
 * BATCH rows against the slice's deadline, and a consumer's own callback runs
 * whole. Every callback selects by generation, so running one again moves
 * nothing twice; what a callback cannot select by generation (mailbox's DKIM
 * and Direct keys) it skips when the retired key does not open it, because
 * VaultUnlock::sweepingPreviousKey() is true. A generation is marked swept
 * when every step has run; a slice that runs out of time records its place
 * on the chain entry and the next one goes on from there.
 *
 * A generation's mark holds the pass that swept it. When a consumer's callback
 * learns to find more (mailbox 1.144.23: messages whose key outlived their
 * sealed flag), PASS goes up and every chain is swept once more.
 *
 * A row that does not move (its key does not open) is logged and passed; it
 * stays readable through the chain, and the v1 census reports it.
 *
 * It holds the member's drain lock (VaultCeremonies::withDrainLock), so it
 * never runs beside a rotation's re-seal.
 *
 * Deferred work (VaultDeferredWork), declared in vault_consumers.json; this
 * file is its bootstrap.
 *
 * @version 1.1 - PASS: a mark from an earlier pass is work again
 * @version 1.0
 */

class VaultChainSweep {

	const CONSUMER_ID = 'vault_chain_sweep';

	/**
	 * The sweep's pass. Raise it when a re-seal callback starts finding rows
	 * it passed by before, so chains already swept are swept again.
	 * 2: mailbox 1.144.23 moves messages whose key outlived iem_content_sealed.
	 */
	const PASS = 2;

	/** Rows a model step re-seals per query. */
	const BATCH = 200;

	/** Is any retired key in this member's account vault chain not yet swept? One vault read. */
	public static function hasWork(int $user_id): bool {
		$vault = UserEncryptionVault::loadForUser($user_id);
		if (!$vault) {
			return false;
		}
		$current = (int)$vault->get('uev_key_generation');
		foreach (VaultPreviousKeys::entries($vault) as $e) {
			if (($e['swept'] ?? 0) < self::PASS && $e['generation'] < $current) {
				return true;
			}
		}
		return false;
	}

	/**
	 * One slice. Returns the rows moved plus the consumer callbacks run, so the
	 * deferred-work loop sees progress; 0 when the drain lock is held elsewhere
	 * or the window's key is not the vault's current key.
	 */
	public static function drain(int $user_id, VaultKey $key, float $deadline): int {
		$done = 0;
		VaultCeremonies::withDrainLock($user_id, function () use ($user_id, $key, $deadline, &$done) {
			VaultUnlock::asPreviousKeySweep(function () use ($user_id, $key, $deadline, &$done) {
				self::sweep($user_id, $key, $deadline, $done);
			});
		});
		return $done;
	}

	private static function sweep(int $user_id, VaultKey $key, float $deadline, int &$done): void {
		$vault = UserEncryptionVault::loadForUser($user_id);
		if (!$vault) {
			return;
		}
		$current_generation = (int)$vault->get('uev_key_generation');
		$public_key = (string)$vault->get('uev_public_key');
		$retired = VaultPreviousKeys::open($vault, $key);
		$steps = self::steps();
		foreach (VaultPreviousKeys::entries($vault) as $e) {
			$generation = $e['generation'];
			if (($e['swept'] ?? 0) >= self::PASS || $generation >= $current_generation) {
				continue;
			}
			if (!$retired) {
				// The window's key opens no entry: it is not the vault's current
				// key (a rotation landed since it opened). The next window sweeps.
				return;
			}
			if (!isset($retired[$generation])) {
				// The current key opens the others but not this one: nothing can
				// ever be moved with it, so stop asking.
				error_log('Vault chain sweep: the retired key of generation ' . $generation . ' of user ' . $user_id
					. ' does not open; marked swept with nothing moved.');
				VaultPreviousKeys::recordSweep($vault, $generation, true);
				continue;
			}
			$at = $e['sweep_at'] ?? null;
			$state = self::sweepGeneration($user_id, $retired[$generation], $generation, $public_key,
				$current_generation, $steps, $at, $deadline, $done);
			VaultPreviousKeys::recordSweep($vault, $generation, $state);
			if ($state !== true) {
				return;   // out of time
			}
			error_log('Vault chain sweep: generation ' . $generation . ' of user ' . $user_id
				. ' swept onto generation ' . $current_generation . '.');
		}
	}

	/**
	 * The work for one retired generation, in a fixed order: ['model:Class'
	 * => [Class, null]] for each class a model callback covers, and
	 * ['callback:N' => [null, callable]] for each consumer's own callback.
	 */
	private static function steps(): array {
		$steps = array();
		foreach (VaultUnlock::resealCallbacks() as $i => $callback) {
			$classes = VaultUnlock::modelResealClasses($callback);
			if ($classes === null) {
				$steps['callback:' . $i] = array(null, $callback);
				continue;
			}
			foreach ($classes as $class) {
				$steps['model:' . $class] = array($class, null);
			}
		}
		return $steps;
	}

	/**
	 * Run the steps from $at (where an earlier slice stopped) on. Returns true
	 * when all ran, else where it stopped: ['step' => key, 'after' => row id].
	 */
	private static function sweepGeneration(int $user_id, VaultKey $retired_key, int $generation,
			string $public_key, int $current_generation, array $steps, ?array $at, float $deadline, int &$done) {
		$keys = array_keys($steps);
		$from = 0;
		$after = 0;
		if ($at !== null && ($i = array_search($at['step'], $keys, true)) !== false) {
			$from = $i;
			$after = (int)$at['after'];
		}
		for ($i = $from; $i < count($keys); $i++) {
			list($class, $callback) = $steps[$keys[$i]];
			if ($class !== null) {
				do {
					if (microtime(true) >= $deadline) {
						return array('step' => $keys[$i], 'after' => $after);
					}
					$r = $class::resealRows($user_id, $retired_key, $generation, $public_key,
						$current_generation, self::BATCH, $after);
					$done += max(0, $r['attempted'] - $r['failed'] - $r['skipped']);
					if ($r['failed'] > 0) {
						error_log('Vault chain sweep: ' . $r['failed'] . ' ' . $class . ' rows of user ' . $user_id
							. ' on generation ' . $generation . ' did not open with that key; left as they are.');
					}
					$after = $r['last_id'];
				} while ($after > 0);
			} else {
				if (microtime(true) >= $deadline) {
					return array('step' => $keys[$i], 'after' => 0);
				}
				try {
					call_user_func($callback, $user_id, $retired_key, $generation, $public_key, $current_generation);
				} catch (Throwable $e) {
					// The callback attempted everything before it threw; what it
					// could not move stays readable through the chain.
					error_log('Vault chain sweep: ' . $keys[$i] . ' left content of user ' . $user_id
						. ' on generation ' . $generation . ': ' . $e->getMessage());
				}
				$done++;
			}
			$after = 0;
		}
		return true;
	}
}

VaultDeferredWork::register(
	VaultChainSweep::CONSUMER_ID,
	function (int $user_id): bool {
		return VaultChainSweep::hasWork($user_id);
	},
	function (int $user_id, VaultKey $key, float $deadline): int {
		return VaultChainSweep::drain($user_id, $key, $deadline);
	}
);
