<?php
/**
 * HostedHeldRemoval - what goes with a held Starter site once its host has
 * removed its container (specs/hosting_paid_launch.md B1).
 *
 * A sold Starter site whose payment failed is held stopped at the end of its
 * grace, its backups are pruned at the end of the keep-period, and it is then
 * marked ready to remove (htr_remove_ready_time). A person presses Permanently
 * Delete Site on its node page; its host checks its own hold
 * (decommission_held_site) and removes the container. When the host's answer
 * verifies the site gone, the job's result processing calls finish() before
 * the node is soft-deleted, while the site's records are still live:
 *
 *   - its hosting subscription, if no one has cancelled it yet (a failed
 *     payment leaves it past_due or unpaid, open until the processor gives
 *     up), is cancelled at once, on the order's own side (test or live), so
 *     a buyer who fixes their card later is not charged for a site that no
 *     longer exists;
 *   - its mail subaccount at SMTP2GO is closed (its SMTP user and webhook go
 *     with it);
 *   - an open suspension is closed, so Unsuspend is refused from then on.
 *
 * The node's soft delete then does the rest, as for any removal: the
 * provision and its hosted trial go, a domain bought for the site is parked
 * for its buyer (ManagedNode::release_site_records), the relay reconcile sees
 * the site gone and releases its slot (FleetService::siteGone), and the place
 * on its host comes back. A domain the buyer owns keeps its own records; a
 * Starter name under a fleet zone has none of its own (one wildcard per zone).
 *
 * Nothing here can stop the removal: the container is already gone. Each step
 * that fails says so in the job's notes and in the error log.
 *
 * @version 1.1 - cancels only a subscription this site's store sold (hosting_after_purchase B11)
 * @version 1.0
 */

class HostedHeldRemoval {

	/**
	 * @param ManagedNode $node the removed site's node, not yet soft-deleted
	 * @param ?callable $mail_client returns the SMTP2GO client (or null), for tests
	 * @param ?callable $cancel cancels an OrderItem's subscription at its processor, for tests
	 * @return string[] one plain sentence per step
	 */
	public static function finish($node, ?callable $mail_client = null, ?callable $cancel = null): array {
		$notes = array();
		$provision = CustomerCloudProvision::latest_for_node($node->key);
		if ($provision === null) {
			return $notes;
		}
		$notes = array_merge($notes, self::cancel_subscription($provision, $cancel));
		$notes = array_merge($notes, self::close_mail($provision, $mail_client));
		$row = HostedSuspension::open_for($provision->key);
		if ($row !== null) {
			$row->set('hsu_unsuspend_time', gmdate('Y-m-d H:i:s'));
			$row->add_steps(array('Closed on ' . gmdate('Y-m-d') . ': the site was removed from its host after its hosting ended.'));
			$row->save();
			$notes[] = 'Its suspension was closed with it.';
		}
		return $notes;
	}

	private static function cancel_subscription(CustomerCloudProvision $provision, ?callable $cancel): array {
		// Only a line this site's store sold: another store's line id names
		// somebody else's purchase here.
		$item = $provision->local_order_item();
		if ($item === null || !$item->get('odi_is_subscription') || $item->get('odi_subscription_cancelled_time')) {
			return array();
		}
		$order = (int)$item->get('odi_ord_order_id');
		$act = function () use ($item, $cancel) {
			if ($cancel !== null) {
				$cancel($item);
				return;
			}
			$sub_id = trim((string)$item->get('odi_stripe_subscription_id'));
			if ($item->get_payment_source() === 'stripe' && $sub_id !== ''
					&& (string)(new StripeHelper())->get_subscription($sub_id)->status === 'canceled') {
				return;
			}
			$item->cancel_subscription_by_system('immediate');
		};
		try {
			if ($provision->is_test_purchase()) {
				TestMode::on_test_side($act);
			} else {
				$act();
			}
		} catch (Throwable $e) {
			error_log('HostedHeldRemoval: subscription of order ' . $order . ' (' . $provision->get('cvp_domain') . ') not cancelled: ' . $e->getMessage());
			return array('Its hosting subscription (order ' . $order . ') was not cancelled (' . $e->getMessage()
				. '). Cancel it by hand on the buyer\'s user page, under Subscriptions, or the buyer may be charged again.');
		}
		$item = new OrderItem((int)$item->key, TRUE);
		if (!$item->get('odi_subscription_cancelled_time')) {
			$item->set('odi_subscription_cancelled_time', gmdate('Y-m-d H:i:s'));
			$item->set('odi_subscription_status', 'canceled');
			$item->save();
		}
		return array('Cancelled its hosting subscription (order ' . $order . ').');
	}

	private static function close_mail(CustomerCloudProvision $provision, ?callable $mail_client): array {
		$sub = trim((string)$provision->get('cvp_smtp2go_subaccount_id'));
		if ($sub === '') {
			return array();
		}
		$client = $mail_client ? $mail_client() : Smtp2GoClient::fromSettings();
		if ($client === null) {
			error_log('HostedHeldRemoval: mail subaccount ' . $sub . ' of ' . $provision->get('cvp_domain') . ' left open: no SMTP2GO key here.');
			return array('Its mail subaccount ' . $sub . ' was not closed: this site holds no SMTP2GO key. Close it at SMTP2GO.');
		}
		try {
			$state = $client->subaccountState($sub);
			if ($state !== '' && $state !== 'closed') {
				$client->closeSubaccount($sub);
			}
		} catch (Throwable $e) {
			error_log('HostedHeldRemoval: mail subaccount ' . $sub . ' of ' . $provision->get('cvp_domain') . ' not closed: ' . $e->getMessage());
			return array('Its mail subaccount ' . $sub . ' was not closed (' . $e->getMessage() . '). Close it at SMTP2GO.');
		}
		$provision->set('cvp_smtp2go_subaccount_id', null);
		$provision->set('cvp_smtp2go_domain_id', null);
		$provision->set('cvp_smtp2go_user_id', null);
		$provision->save();
		return array('Closed its mail subaccount ' . $sub . ' at SMTP2GO.');
	}
}
