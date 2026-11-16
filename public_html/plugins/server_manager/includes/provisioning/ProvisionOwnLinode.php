<?php
/**
 * ProvisionOwnLinode — what an own-Linode site needs from us after it is built
 * (specs/own_linode_we_install.md W3, W5).
 *
 * An own-Linode site is built in its buyer's own Linode account with the grant
 * they gave in the configurator. Two things follow the build:
 *
 *  - The grant is forgotten. It was asked for the build only (one server,
 *    linodes:read_write), so once none of the buyer's own-Linode sites is
 *    still being built, the stored token is cleared. A later need (a site
 *    copy's address swap) asks again.
 *  - With the email and backups bundle (cvp_services_order_item_id), the site
 *    is carried into the end state a Managed site has after moving to its
 *    buyer's own account: node-linked mail and backup Services rows that
 *    follow the bundle's subscription, and the node's space on our backup
 *    storage. Its mail is built first by the hosted mail leg
 *    (ProvisionHostedMail), whose provider pieces the rows take over. Without
 *    the bundle nothing of ours is set up; the site's own setup steps offer it.
 *
 * Idempotent: a row the node already has is left alone, and the backup space
 * is opened only when the node has none.
 *
 * @version 1.1 - reviewer2: the grant is let go once per site, only the build-only one, and not while a failed
 *                build may retry (B1); a failed mail leg still links the backups row and is reported (B3); the
 *                rows' date asks whether the bundle line still pays (B4); the mail row is left to verify (B5)
 * @version 1.0 - specs/own_linode_we_install.md
 */
class ProvisionOwnLinode {

	/** Statuses in which a build still needs the buyer's grant: a failed one is retried. */
	const BUILDING = array('pending_connect', 'ready', 'booting', 'installing', 'failed');

	/** @var array Problems for the run summary. */
	private $errors = array();

	public function run(array $config): array {
		$this->errors = array();
		$rows = new MultiCustomerCloudProvision(array('plan' => 'own_linode', 'statuses' => array('done'), 'deleted' => false));
		$only = (int)($config['provision_id'] ?? 0);
		$steps = 0;
		$seen = 0;
		foreach ($rows as $provision) {
			if ($only > 0 && (int)$provision->key !== $only) {
				continue;
			}
			$seen++;
			try {
				$steps += $this->forget_grant($provision);
				$steps += $this->link_services($provision);
			} catch (Throwable $e) {
				$this->errors[] = $provision->get('cvp_domain') . ': ' . $e->getMessage();
				error_log('ProvisionOwnLinode: ' . $provision->get('cvp_domain') . ': ' . $e->getMessage());
			}
		}
		if ($seen === 0) {
			return array('status' => 'skipped', 'message' => 'No own-Linode sites.');
		}
		$message = 'Own Linode: ' . $steps . ' step(s) across ' . $seen . ' site(s).';
		if ($this->errors) {
			return array('status' => 'error', 'message' => $message . ' ' . implode('; ', array_slice($this->errors, 0, 3)));
		}
		return array('status' => 'success', 'message' => $message);
	}

	/**
	 * Let the buyer's build-only grant go, once per site, after the build:
	 * when none of the buyer's sites is still being built (a failed one is
	 * retried), and only a grant that is the build-only one. A wider grant the
	 * buyer gave on the Connect page for something else is theirs to keep, and
	 * a site already let go is never looked at again, so a later connection is
	 * never wiped. Returns 1 when this site's turn is done.
	 */
	public function forget_grant(CustomerCloudProvision $provision): int {
		if ($provision->get('cvp_grant_released_time')) {
			return 0;
		}
		$building = new MultiCustomerCloudProvision(array(
			'user_id'  => (int)$provision->get('cvp_usr_user_id'),
			'statuses' => self::BUILDING,
			'deleted'  => false,
		));
		if (count($building) > 0) {
			return 0;
		}
		$account_id = (int)$provision->get('cvp_cca_customer_cloud_account_id');
		$account = $account_id > 0 ? new CustomerCloudAccount($account_id, TRUE) : null;
		if ($account !== null && $account->key && trim((string)$account->get('cca_access_token')) !== ''
				&& $account->is_build_only_grant()) {
			$account->clear_grant();
		}
		$provision->set('cvp_grant_released_time', gmdate('Y-m-d H:i:s'));
		$provision->save();
		return 1;
	}

	/**
	 * With the bundle: the node-linked rows and the backup space, once the
	 * site is up and its mail is built. Returns the number of steps taken.
	 */
	public function link_services(CustomerCloudProvision $provision): int {
		$item_id = (int)$provision->get('cvp_services_order_item_id');
		if ($item_id <= 0) {
			return 0;
		}
		$node_id = (int)$provision->get('cvp_mgn_managed_node_id');
		if ($node_id <= 0) {
			return 0;
		}
		$node = new ManagedNode($node_id, TRUE);
		if (!$node->key || !ManagedNode::is_operational_from($node)) {
			return 0;
		}
		// Mail is carried over from what the hosted mail leg built: wait for it
		// to finish. A leg that failed still gets its backups row, so the
		// subscription governs them, and the mail failure is reported on every
		// pass until a person sees to it.
		$mail_state = (string)$provision->get('cvp_mail_state');
		if (!in_array($mail_state, array('done', 'failed'), true)) {
			return 0;
		}
		$link_mail = $mail_state === 'done';
		if (!$link_mail) {
			$this->errors[] = $provision->get('cvp_domain') . ': its email setup failed ('
				. trim((string)$provision->get('cvp_mail_error')) . '); its backups follow the subscription, its email needs a person.';
		}
		$steps = 0;
		if (($link_mail && ServiceTenant::forNode((int)$node->key, ServiceTenant::SERVICE_MAIL) === null)
				|| ServiceTenant::forNode((int)$node->key, ServiceTenant::SERVICE_SHELF) === null) {
			// Verification is left to the services reconcile: a domain the
			// leg gave up waiting on is asked again until it verifies.
			InstanceTransferFinish::link_node_services($provision, $node, self::paid_until($item_id),
				'Built on its own Linode with the email and backups bundle on ' . gmdate('Y-m-d') . '.', $item_id,
				false, $link_mail);
			$steps++;
		}
		if ($node->backup_space() === null && $node->open_default_backup_space() !== null) {
			$steps++;
		}
		return $steps;
	}

	/**
	 * What the bundle line pays the rows to: its period end while it pays, a
	 * term from now while the payment provider has not said the period yet,
	 * and now when it no longer pays (cancelled or refunded before the link),
	 * so the services ladder runs rather than a month given free.
	 */
	public static function paid_until(int $order_item_id): string {
		$now = gmdate('Y-m-d H:i:s');
		$item = ($order_item_id > 0 && class_exists('OrderItem')) ? new OrderItem($order_item_id, TRUE) : null;
		if ($item === null || !$item->key || !JoineryServicesFulfillment::line_paying($item)) {
			return $now;
		}
		$end = trim((string)$item->get('odi_subscription_period_end'));
		if ($end !== '' && $end > $now) {
			return $end;
		}
		return gmdate('Y-m-d H:i:s', time() + 31 * 86400);
	}
}
