<?php
/**
 * HostedTrialSignals — the store's subscription facts, applied to hosting.
 *
 * A hosted site's compute, mail and backups are all real things that cost
 * money, and whether they keep running is decided by one fact the store owns:
 * is the subscription paying? The store broadcasts that fact on the signal bus
 * and this listens (specs/hosted_trial_provisioning.md E5).
 *
 * Three signals, and each does exactly one thing to the clock:
 *
 *   subscription.payment_failed     start the grace period, if one is not
 *                                   already running. Not restart it: a second
 *                                   failed retry inside the same grace must not
 *                                   push the deadline out, or a card that never
 *                                   works buys unlimited hosting.
 *   subscription.payment_recovered  clear it. The customer paid; nothing is
 *                                   owed and nothing is pending.
 *   subscription.cancelled          end hosting at the same deadline a failed
 *                                   payment would. A cancellation is a decision,
 *                                   not a fault, so it gets the same wind-down
 *                                   rather than an immediate shutdown.
 *
 * WHAT IT NEVER DOES IS ACT. It moves dates on a row. The shutdown, the
 * operator's deletion task and the shelf prune are HostedTrialWatch's, on its
 * own schedule — because a webhook arrives inside somebody's HTTP request, and
 * powering off a customer's machine from inside a webhook is how a retry, a
 * duplicate delivery or a provider outage becomes an outage of ours.
 *
 * @version 1.0
 */

class HostedTrialSignals {

	public static function handle_signal($signal, array $payload): void {
		$order_item_id = (int)($payload['order_item_id'] ?? 0);
		if (!$order_item_id) {
			return;
		}
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/hosted_trial_class.php'));
		$trial = HostedTrial::for_order_item($order_item_id);
		if ($trial === null) {
			// Not a hosted subscription. Every other subscription on the
			// platform passes through here and this is where they stop.
			return;
		}

		switch ($signal) {
			case 'subscription.payment_failed':  self::start_grace($trial, 'a payment failed'); break;
			case 'subscription.cancelled':       self::start_grace($trial, 'the subscription was cancelled'); break;
			case 'subscription.payment_recovered': self::clear_grace($trial); break;
		}
	}

	/**
	 * Begin the wind-down, once. A row already in grace, or already shut down,
	 * keeps the deadline it has.
	 */
	private static function start_grace($trial, string $because): void {
		if (in_array((string)$trial->get('htr_state'),
				array(HostedTrial::STATE_GRACE, HostedTrial::STATE_SHUTDOWN), true)) {
			return;
		}
		$now = gmdate('Y-m-d H:i:s');
		$trial->set('htr_state', HostedTrial::STATE_GRACE);
		$trial->set('htr_payment_failed_time', $now);
		$trial->set('htr_grace_ends_time', self::plus_days($now, self::grace_days()));
		// Counted from the failed payment, not from the shutdown: "your backups
		// are kept ninety days" is a promise a customer can check against the
		// day they stopped paying, and it should not move because a shutdown
		// ran late.
		$trial->set('htr_shelf_ends_time', self::plus_days($now, self::shelf_days()));
		$trial->set('htr_note', ucfirst($because) . ' on ' . $now . ' UTC.');
		$trial->save();
		error_log('HostedTrialSignals: hosting for provision #' . $trial->get('htr_cvp_provision_id')
			. ' entered its grace period (' . $because . '); it ends '
			. $trial->get('htr_grace_ends_time') . ' UTC.');
	}

	/** The charge went through. Everything pending is cancelled. */
	private static function clear_grace($trial): void {
		if ((string)$trial->get('htr_state') === HostedTrial::STATE_SHUTDOWN) {
			// Already off. Bringing it back is a deliberate act with a person
			// behind it — the machine may have been deleted at the provider by
			// now, and a signal cannot know that.
			$trial->set('htr_note', 'A payment arrived after this site was shut down; '
				. 'bringing it back is a manual step.');
			$trial->save();
			return;
		}
		$trial->set('htr_state', HostedTrial::STATE_SUBSCRIBED);
		$trial->set('htr_payment_failed_time', null);
		$trial->set('htr_grace_ends_time', null);
		$trial->set('htr_shelf_ends_time', null);
		$trial->set('htr_note', null);
		$trial->save();
	}

	private static function plus_days(string $from, int $days): string {
		return gmdate('Y-m-d H:i:s', strtotime($from . ' UTC') + ($days * 86400));
	}

	public static function grace_days(): int {
		$value = (int)Globalvars::get_instance()->get_setting('server_manager_hosted_grace_days', true, true);
		return $value > 0 ? $value : 30;
	}

	public static function shelf_days(): int {
		$value = (int)Globalvars::get_instance()->get_setting('server_manager_hosted_shelf_days', true, true);
		return $value > 0 ? $value : 90;
	}
}
