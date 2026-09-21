<?php
/**
 * ServiceTenantLadder - the grace-then-suspend ladder every rented service
 * walks its tenant rows down, and back up.
 *
 * (specs/services_phase2_platform.md §5, §14 E1). A tenant of a service the
 * operator runs (a relay slot, outbound mail, backup storage) holds a row
 * with a state and two timestamps. Each reconcile pass asks one question —
 * is this tenant still entitled? — and the ladder turns the answer into the
 * row's next state:
 *
 *   active, entitled        → the check time is stamped; a lapse in progress
 *                             is cleared (re-entitled inside the window)
 *   active, not entitled    → the lapse time is stamped on the first pass;
 *                             once the grace window has run out, the row goes
 *                             suspended and the service's own suspend act runs
 *   suspended, entitled     → the row goes active again in place, the lapse
 *                             time is cleared, and the reactivate act runs
 *
 * How entitlement is decided is the service's business (a tier feature, a
 * paid-through date); the ladder only takes the answer. What suspending or
 * reactivating means on the provider is the service's business too, handed
 * in as the two closures. The ladder writes the state and the timestamps and
 * saves the row before it calls either closure, so a closure that throws
 * leaves the row already on its new rung and the next pass retries the act.
 *
 * The state vocabulary is shared by every tenant row: provisioning (asked
 * for, not yet usable), active, suspended (entitlement lapsed past the
 * window), released (the tenant left). A service may add rungs of its own
 * beyond these — the mailbox fleet's `evicted`, backup storage's retention clock —
 * on its own side of the closures.
 *
 * @version 1.0 - lifted from MailboxRelayReconcile phase 5's entitlement re-check
 */
class ServiceTenantLadder {

	const STATE_PROVISIONING = 'provisioning';
	const STATE_ACTIVE       = 'active';
	const STATE_SUSPENDED    = 'suspended';
	const STATE_RELEASED     = 'released';

	/** Every state a tenant row may hold in the shared vocabulary. */
	const STATES = array(
		self::STATE_PROVISIONING, self::STATE_ACTIVE, self::STATE_SUSPENDED, self::STATE_RELEASED,
	);

	/** What one advance() did to the row. */
	const STEP_NONE        = 'none';        // not on a rung the ladder moves
	const STEP_CHECKED     = 'checked';     // active and entitled; check time stamped
	const STEP_LAPSED      = 'lapsed';      // first pass without entitlement; grace started
	const STEP_IN_GRACE    = 'in_grace';    // still inside the window
	const STEP_SUSPENDED   = 'suspended';   // window ran out; suspend act ran
	const STEP_REACTIVATED = 'reactivated'; // entitled again from suspended; reactivate act ran

	/**
	 * Walk one row one step.
	 *
	 * @param SystemBase $row       The tenant row (a model; the ladder calls set()/get()/save()).
	 * @param array      $columns   The row's column names for the three things the
	 *                              ladder writes: `state`, `lapse_time`, `check_time`.
	 *                              `check_time` may be omitted for a row that keeps none.
	 * @param bool       $entitled  The service's answer for this pass.
	 * @param int        $grace_days How long after the lapse the tenant keeps the service.
	 * @param callable   $suspend   function(SystemBase $row): void — the provider-side act
	 *                              once the row is saved suspended.
	 * @param callable   $reactivate function(SystemBase $row): void — the provider-side act
	 *                              once the row is saved active again.
	 * @param string|null $now      UTC 'Y-m-d H:i:s'; the clock, for tests.
	 * @return string One of the STEP_* constants.
	 */
	public static function advance(SystemBase $row, array $columns, bool $entitled, int $grace_days,
			callable $suspend, callable $reactivate, ?string $now = null): string {
		$state_col = (string)$columns['state'];
		$lapse_col = (string)$columns['lapse_time'];
		$check_col = isset($columns['check_time']) ? (string)$columns['check_time'] : '';
		$now = $now ?? gmdate('Y-m-d H:i:s');
		$state = (string)$row->get($state_col);

		if ($state === self::STATE_ACTIVE) {
			if ($entitled) {
				if ($check_col !== '') {
					$row->set($check_col, $now);
				}
				if ($row->get($lapse_col) !== null) {
					$row->set($lapse_col, null); // re-entitled inside the window
				}
				$row->save();
				return self::STEP_CHECKED;
			}
			if ($row->get($lapse_col) === null) {
				$row->set($lapse_col, $now);
				$row->save();
				return self::STEP_LAPSED;
			}
			$deadline = LibraryFunctions::time_shift(
				(string)$row->get($lapse_col), max(0, $grace_days) . ' days', 'Y-m-d H:i:s');
			if ($now <= $deadline) {
				return self::STEP_IN_GRACE;
			}
			$row->set($state_col, self::STATE_SUSPENDED);
			$row->save();
			$suspend($row);
			return self::STEP_SUSPENDED;
		}

		if ($state === self::STATE_SUSPENDED && $entitled) {
			$row->set($state_col, self::STATE_ACTIVE);
			$row->set($lapse_col, null);
			if ($check_col !== '') {
				$row->set($check_col, $now);
			}
			$row->save();
			$reactivate($row);
			return self::STEP_REACTIVATED;
		}

		return self::STEP_NONE;
	}

	/** The moment the grace window closes for a row, or null when no lapse is running. */
	public static function graceEnds(SystemBase $row, string $lapse_column, int $grace_days): ?string {
		$lapse = $row->get($lapse_column);
		if ($lapse === null || $lapse === '') {
			return null;
		}
		$ends = LibraryFunctions::time_shift((string)$lapse, max(0, $grace_days) . ' days', 'Y-m-d H:i:s');
		return $ends === false ? null : $ends;
	}
}
