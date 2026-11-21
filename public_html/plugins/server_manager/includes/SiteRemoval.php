<?php
/**
 * SiteRemoval: a buyer takes a site off their own Your Sites page.
 *
 * Two cases, both on the buyer's own server (a site we host ends by
 * cancelling its subscription, which runs it to the end of the paid period):
 *
 *   stop    a built site on the buyer's own server. We stop managing it: its
 *           agent is forgotten, updates, alerts, backups and uptime checks
 *           stop (InstanceTransferFinish::let_go_node), the email and backups
 *           bundle is cancelled to the end of its paid period, and the site
 *           leaves the page. The server is the buyer's and is not touched.
 *   remove  an order that never built (waiting for a connection, or failed)
 *           and paid for nothing: it leaves the page.
 *
 * An order that never built but was paid for (a licence, a domain, the
 * bundle) is not offered here: money has to go back first, which is a
 * person's step.
 *
 * @version 1.0 - walkthrough Q36
 */
class SiteRemoval {

	const KIND_STOP = 'stop';
	const KIND_REMOVE = 'remove';

	/** What this buyer may do with this site here: KIND_STOP, KIND_REMOVE, or '' for neither. */
	public static function offered(CustomerCloudProvision $provision): string {
		if ($provision->get('cvp_delete_time') || $provision->is_pre_payment() || $provision->is_operator_hosted()) {
			return '';
		}
		$status = (string)$provision->get('cvp_status');
		if (InstanceTransfers::node_of($provision) !== null && $status === 'done') {
			return self::KIND_STOP;
		}
		if (in_array($status, array('pending_connect', 'failed'), true)
				&& trim((string)$provision->get('cvp_instance_id')) === '' && self::paid($provision) <= 0) {
			return self::KIND_REMOVE;
		}
		return '';
	}

	/** What the lines bought for this site cost, less what was refunded. */
	public static function paid(CustomerCloudProvision $provision): float {
		$paid = 0.0;
		foreach (CustomerCloudFulfillment::site_lines($provision) as $line) {
			$paid += max(0.0, (float)$line->get('odi_price') - (float)$line->get('odi_refund_amount'));
		}
		return round($paid, 2);
	}

	/** The question the button asks, in the buyer's words. */
	public static function question(CustomerCloudProvision $provision): string {
		$domain = (string)$provision->get('cvp_domain');
		if (self::offered($provision) === self::KIND_STOP) {
			return 'Stop our management of ' . $domain . ' and remove it from this page? Your server keeps running and nothing on it '
				. 'is changed. We stop its updates, alerts and backups; backups already stored with us are deleted after '
				. ServiceTenant::RETENTION_DAYS . ' days.'
				. ((int)$provision->get('cvp_services_order_item_id') > 0
					? ' Its email and backups subscription is cancelled and runs to the end of the period you have paid for.' : '')
				. ' To have it managed again later, add it from this page.';
		}
		return 'Remove ' . $domain . ' from this page? Nothing was built and nothing was charged for it.';
	}

	/**
	 * Do it. Throws RuntimeException when the site is not one this can remove.
	 *
	 * @return array{kind: string, bundle_cancelled: bool}
	 */
	public static function remove(CustomerCloudProvision $provision): array {
		$kind = self::offered($provision);
		if ($kind === '') {
			throw new RuntimeException('This site cannot be removed from here.');
		}
		$bundle_cancelled = false;
		if ($kind === self::KIND_STOP) {
			$node = InstanceTransfers::node_of($provision);
			if ($node !== null && ($node->get('mgn_enabled') || $node->get('mgn_agent_public_key'))) {
				InstanceTransferFinish::let_go_node($node);
			}
			$bundle_id = (int)$provision->get('cvp_services_order_item_id');
			if ($bundle_id > 0 && class_exists('OrderItem')) {
				$line = new OrderItem($bundle_id, TRUE);
				if ($line->key && $line->get('odi_is_subscription') && !$line->get('odi_subscription_cancelled_time')
						&& !$line->get('odi_subscription_cancel_at_period_end')) {
					$line->cancel_subscription_by_system('period_end');
					$bundle_cancelled = true;
				}
			}
		}
		$provision->set('cvp_error', $kind === self::KIND_STOP
			? 'Removed by its buyer: management stopped on ' . gmdate('Y-m-d') . '.'
			: 'Removed by its buyer before anything was built.');
		$provision->save();
		$provision->soft_delete();
		return array('kind' => $kind, 'bundle_cancelled' => $bundle_cancelled);
	}
}
