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
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/VaultDeferredWork.php'));
require_once(PathHelper::getIncludePath('includes/SealedEgressGuard.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectSpoolService.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectKinds.php'));
require_once(PathHelper::getIncludePath('data/direct_spool_class.php'));

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
		DirectSpoolService::ingest($spool, $accepted, $secret_key);

		$spool->set('jdp_state', DirectSpool::STATE_DONE);
		$spool->set('jdp_drained_time', gmdate('Y-m-d H:i:s'));
		$spool->save();
		DirectSpoolService::dropParts($spool);
		return true;
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
