<?php
/**
 * DirectSpoolDrain - the deferred half of a sealed-tier delivery, run at the
 * recipient's next unlock.
 *
 * At Private and Fortress the receiver accepted before it could judge: the
 * contact list is sealed, so authorization had to wait while authentication —
 * the instance signature and every sealed-byte hash — ran at receive on a locked
 * box. This is where the waiting ends. For each held delivery it runs the kind's
 * deferred gate against the now-readable list and hands the outcome to the
 * kind's ingest.
 *
 * Two rules the drain never bends:
 *
 *   - **A deferred decline is a local disposition, not a drop.** The sender was
 *     already answered `accept` and is long gone by unlock, so a rejection here
 *     is a filing decision: mail lands exactly where SMTP would have put it and
 *     is simply denied the verified mark. Nothing is bounced, nothing is
 *     returned, and the sender is never told — which is a feature, because
 *     delivery feedback is how attackers enumerate addresses and probe filters,
 *     and returning mail to forged senders is backscatter.
 *
 *   - **A kind whose plugin went away is HELD, not errored.** The delivery stays
 *     sealed until the plugin is reactivated, and expires quietly under the
 *     spool's retention if it never is. Nothing is returned to the sender in
 *     either case.
 *
 * Registered as a core deferred-work consumer, so the backlog drains wherever
 * the recipient happens to be on the site with an open window — not only when
 * they open the surface the payload belongs to.
 *
 * Also this consumer's rotation re-seal (declared `reseals: true`): a held
 * delivery's sealed parts are sealed DIRECTLY to the recipient's vault keypair
 * (sealBulkDelivery — no per-item DEK indirection), so a key rotation must
 * re-seal the bytes themselves or retire the only keypair that can ever open
 * them — and a spooled delivery can wait for months.
 *
 * @version 1.2
 * @changelog 1.2 - DirectDeferIngest from a kind's ingest leaves the delivery
 *   held quietly for a future unlock instead of logging it as a drain failure.
 * @changelog 1.1 - onReseal callback: held deliveries' sealed parts re-seal to
 *   the new vault keypair on rotation instead of being silently orphaned.
 */

require_once(PathHelper::getIncludePath('includes/VaultDeferredWork.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
require_once(PathHelper::getIncludePath('includes/SealedEgressGuard.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectSpoolService.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectKinds.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectProtocol.php'));
require_once(PathHelper::getIncludePath('data/direct_spool_class.php'));
require_once(PathHelper::getIncludePath('data/direct_spool_parts_class.php'));

class DirectSpoolDrain {

	/** Safety cap per pass, so a large backlog never blocks a page render. */
	const DEFAULT_MAX = 100;

	public static function hasWork(int $user_id): bool {
		return DirectSpool::hasWork($user_id);
	}

	/**
	 * Gate and ingest the deliveries held for one user. Returns how many were
	 * drained. A per-delivery failure is logged and the row left held, to be
	 * retried at the next unlock — one bad delivery never stalls the rest.
	 */
	public static function drainForUser(int $user_id, string $secret_key, int $max = self::DEFAULT_MAX,
			?float $deadline = null): int {
		if ($user_id <= 0 || $secret_key === '') {
			return 0;
		}
		$ids = DirectSpool::heldIdsForUser($user_id, $max);
		if (empty($ids)) {
			return 0;
		}

		$drained = 0;
		foreach ($ids as $id) {
			if ($deadline !== null && microtime(true) >= $deadline) {
				break;
			}
			try {
				$spool = new DirectSpool(intval($id), TRUE);
				// One delivery is one unit of work for the hot-turn rule, by the
				// same argument deferred mail ingest makes: opening this delivery's
				// parts must not leave every LATER delivery in the pass hot.
				$done = SealedEgressGuard::isolate(function () use ($spool, $secret_key) {
					return self::drainOne($spool, $secret_key);
				});
				if ($done) {
					$drained++;
				}
			} catch (\Throwable $e) {
				error_log('DirectSpoolDrain: failed to drain delivery ' . $id . ': ' . $e->getMessage());
			}
		}
		return $drained;
	}

	/** Gate, ingest, and retire one held delivery. */
	private static function drainOne(DirectSpool $spool, string $secret_key): bool {
		$kind = (string)$spool->get('jdp_kind');
		if (!DirectKinds::isServed($kind)) {
			// The plugin is gone for now. Held, silently — it may come back, and
			// if it does not the retention sweep takes it.
			return false;
		}

		// Gate and ingest both rebuild their envelope from this row, so the
		// recipient identity resolved at accept reaches the store unchanged. This
		// is the same gate an unencrypted mailbox runs at commit — here it runs at
		// unlock, against the now-readable sealed contact list.
		$accepted = DirectSpoolService::gateFor($spool);
		try {
			DirectSpoolService::ingest($spool, $accepted, $secret_key);
		} catch (DirectDeferIngest $e) {
			// Still not openable from THIS member's unlock — the store it
			// belongs in is sealed to someone else's window. Held for a future
			// unlock; retention reclaims it if that never comes.
			return false;
		}

		$spool->set('jdp_state', DirectSpool::STATE_DONE);
		$spool->set('jdp_drained_time', gmdate('Y-m-d H:i:s'));
		$spool->save();
		DirectSpoolService::dropParts($spool);
		return true;
	}

	/**
	 * Re-seal every HELD delivery's sealed parts from the draining keypair to
	 * the new one — this consumer's onReseal.
	 *
	 * Unlike the model consumers, there is no per-item DEK to re-wrap: the
	 * sender sealed the part bytes straight to the recipient's vault public key,
	 * so re-sealing means opening the bytes with the old secret and sealing them
	 * again with the new public key. The plaintext exists only in this process's
	 * memory, inside a ceremony the member is running with their vault open.
	 *
	 * Each delivery converges atomically: its part rewrites and its
	 * jdp_key_generation bump commit together, so a rotation re-run after a
	 * crash finds every delivery either fully on the old generation (selected
	 * and re-sealed again) or fully on the new one (not selected) — never
	 * half-moved, which would read as an unrecoverable failure.
	 *
	 * STAGING rows are left alone on purpose. A transfer in flight is sealing
	 * parts to the old key as they arrive; re-sealing a moving target cannot
	 * converge. The race window is a session TTL measured in minutes (against a
	 * ceremony a member runs rarely), and an undrainable staging row is exactly
	 * an abandoned transfer, which the retention sweep already reclaims.
	 *
	 * Fail-loud per the onReseal contract: every delivery is attempted, then any
	 * failure throws so the ceremony refuses to retire the old generation.
	 */
	public static function resealForUser(int $user_id, string $old_secret_key, int $old_key_generation,
			string $new_public_key, int $new_key_generation): void {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('SELECT jdp_direct_spool_id FROM jdp_direct_spool
			WHERE jdp_usr_user_id = ? AND jdp_state = ? AND jdp_key_generation = ?
			AND jdp_delete_time IS NULL');
		$stmt->execute(array($user_id, DirectSpool::STATE_HELD, $old_key_generation));
		$ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: array();

		$crypto = new VaultCrypto();
		$failed = 0;
		foreach ($ids as $id) {
			$owns_txn = !$db->inTransaction();
			try {
				$spool = new DirectSpool(intval($id), TRUE);
				if (!$spool->key) {
					continue;
				}
				if ($owns_txn) {
					$db->beginTransaction();
				}
				$replaced_file_ids = array();
				foreach (DirectSpoolPart::forSpool(intval($spool->key)) as $part) {
					if (!$part->get('jda_is_sealed')) {
						continue;
					}
					$sealed = $part->bytes();
					if ($sealed === '') {
						continue;
					}
					$plain = $crypto->openBulkDelivery($sealed, $old_secret_key);
					$old_file_id = self::replacePartBytes($part,
						$crypto->sealBulkDelivery($plain, $new_public_key), $user_id);
					unset($plain);
					if ($old_file_id > 0) {
						$replaced_file_ids[] = $old_file_id;
					}
				}
				$db->prepare('UPDATE jdp_direct_spool SET jdp_key_generation = ?
					WHERE jdp_direct_spool_id = ?')
					->execute(array($new_key_generation, intval($spool->key)));
				if ($owns_txn) {
					$db->commit();
				}
				// Only after the commit are the superseded file bytes dead weight —
				// deleted earlier, a later part's failure would roll the rows back
				// to pointing at bytes that no longer exist.
				foreach ($replaced_file_ids as $old_file_id) {
					try {
						$old = new File($old_file_id, TRUE);
						if ($old->key) {
							$old->permanent_delete();
						}
					} catch (\Throwable $e) {
						error_log('Direct spool reseal: superseded part file ' . $old_file_id
							. ' could not be removed: ' . $e->getMessage());
					}
				}
			} catch (\Throwable $e) {
				if ($owns_txn && $db->inTransaction()) {
					$db->rollBack();
				}
				$failed++;
				error_log('Direct spool reseal: failed for held delivery ' . $id . ': ' . $e->getMessage());
			}
		}

		if ($failed > 0) {
			throw new RuntimeException(
				'Direct spool reseal: ' . $failed . ' of ' . count($ids) . ' held deliveries could not '
				. 'be re-sealed; the old key generation must not be retired.');
		}
	}

	/**
	 * Put a part's re-sealed bytes back wherever the originals lived — on the
	 * row (base64) when inline, in the private file store when large. jda_hash
	 * is restated over the new ciphertext so the stored hash keeps describing
	 * the stored bytes; the sender-signature check it descends from already ran
	 * at receive, before the part row existed.
	 *
	 * @return int the SUPERSEDED file id (0 when the part rides inline). The
	 *   caller deletes it after its transaction commits — never here, where a
	 *   later rollback would leave rows pointing at bytes already gone.
	 */
	private static function replacePartBytes(DirectSpoolPart $part, string $sealed_bytes, int $owner_id): int {
		$db = DbConnector::get_instance()->get_db_link();
		$hash = DirectProtocol::hashBytes($sealed_bytes);
		$file_id = intval($part->get('jda_fil_file_id'));

		if ($file_id > 0) {
			require_once(PathHelper::getIncludePath('data/files_class.php'));
			require_once(PathHelper::getIncludePath('data/users_class.php'));
			$filename = (string)$part->get('jda_filename');
			$content_type = (string)$part->get('jda_content_type');
			$replacement = File::createFromBytes(
				$sealed_bytes,
				$filename !== '' ? $filename : 'direct-part',
				$content_type !== '' ? $content_type : 'application/octet-stream',
				$owner_id > 0 ? $owner_id : User::USER_SYSTEM,
				array('fil_private' => true)
			);
			$db->prepare('UPDATE jda_direct_spool_parts SET jda_fil_file_id = ?, jda_hash = ?, jda_bytes = ?
				WHERE jda_direct_spool_part_id = ?')
				->execute(array(intval($replacement->key), $hash, strlen($sealed_bytes), intval($part->key)));
			return $file_id;
		}

		$db->prepare('UPDATE jda_direct_spool_parts SET jda_content = ?, jda_hash = ?, jda_bytes = ?
			WHERE jda_direct_spool_part_id = ?')
			->execute(array(base64_encode($sealed_bytes), $hash, strlen($sealed_bytes), intval($part->key)));
		return 0;
	}
}

// Core consumer: it registers unconditionally and its own hook decides whether
// there is work, exactly as the other core sealed consumers do.
VaultDeferredWork::register(
	'joinery_direct_spool',
	function (int $user_id): bool {
		return DirectSpoolDrain::hasWork($user_id);
	},
	function (int $user_id, string $secret_key, float $deadline): int {
		return DirectSpoolDrain::drainForUser($user_id, $secret_key, DirectSpoolDrain::DEFAULT_MAX, $deadline);
	}
);

// --- Rotation re-seal callback (docs/sealed_vault.md § Key rotation) ---
// Held deliveries carry parts sealed straight to the vault keypair being
// rotated away; without this, retiring the old generation would orphan every
// one of them — silently, since the spool bounces nothing and expires quietly.
VaultUnlock::onReseal(function (int $user_id, string $old_secret_key, int $old_key_generation,
		string $new_public_key, int $new_key_generation) {
	DirectSpoolDrain::resealForUser($user_id, $old_secret_key, $old_key_generation,
		$new_public_key, $new_key_generation);
});
