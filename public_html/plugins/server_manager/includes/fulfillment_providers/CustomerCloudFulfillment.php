<?php
/**
 * CustomerCloudFulfillment — marks a hosting product as fulfilled onto a
 * server in the buyer's own cloud account (Linode), billed to the buyer by
 * the provider.
 *
 * Selecting this in the product-edit Purchase grants picker is the whole
 * product-side setup: it stamps pro_fulfillment_provider = customer_cloud
 * (the value Poll Hosting Orders forks on) and contributes the configured
 * domain Question as a checkout requirement automatically — no manual
 * question attachment.
 *
 * fulfill() creates the provision row at purchase time — the provider only
 * registers where server_manager is active, so the store IS the management
 * node and the row can be written directly. The buyer's Connect page shows
 * their order immediately instead of after the next poll tick, and the
 * after-purchase message (page + per-product email) carries the Connect
 * link. Poll Hosting Orders remains the safety net: it dedups on existing
 * provision rows and is the sole creation path when the store is a remote
 * site (where this provider is not registered).
 *
 * WHOSE ACCOUNT THE SERVER IS BORN ON IS THE PRODUCT'S DECISION, NOT THE
 * BUYER'S. The picker offers two references and the product stores the one it
 * was set to:
 *
 *   0  the buyer's own cloud account. They connect it, the provider bills
 *      them, and none of the operator's keys are involved. This is the
 *      bring-your-own product, unchanged.
 *   1  the operator's account. The plane's own token creates the instance,
 *      there is no Connect page and no grant to wait for, and the hosted legs
 *      — mail on our provider, the trial clock, the allowance banners — apply
 *      (specs/hosted_trial_provisioning.md §4.1).
 *
 * A buyer never chooses between them, because they are two products with two
 * prices and two arrangements, not one product with a switch.
 *
 * Registered from server_manager's serve.php when the store plugin is
 * present.
 *
 * @version 1.3 - a second option: the server is created on the OPERATOR's account (hosted)
 * @version 1.2
 */
require_once(PathHelper::getIncludePath('plugins/store/includes/FulfillmentRegistry.php'));

class CustomerCloudFulfillment implements FulfillmentProvider {

	public function key(): string {
		return 'customer_cloud';
	}

	public function label(): string {
		return 'Customer cloud server';
	}

	/** The reference a product stores for the operator-hosted mode. */
	const REF_CUSTOMER = 0;
	const REF_OPERATOR = 1;

	public function options(): array {
		return array(
			self::REF_CUSTOMER => 'Create the server in the buyer\'s own cloud account',
			self::REF_OPERATOR => 'Create the server on the operator\'s account (hosted)',
		);
	}

	/** Which hosting mode a stored reference means. */
	public static function mode_for_ref(int $ref): string {
		return ($ref === self::REF_OPERATOR) ? 'operator' : 'customer';
	}

	public function extraRequirements(Product $product, int $ref): array {
		$settings = Globalvars::get_instance();
		$question_id = (int)$settings->get_setting('server_manager_provisioning_domain_question_id');
		if ($question_id <= 0) {
			return array();
		}
		require_once(PathHelper::getIncludePath('plugins/store/includes/requirements/AbstractProductRequirement.php'));
		return array(AbstractProductRequirement::createInstance('QuestionRequirement', array(
			'question_id' => $question_id,
		)));
	}

	/**
	 * Always available: a cloud site is provisioned on demand rather than drawn
	 * from a finite pool, so there is no supply to run out before the charge.
	 */
	public function checkAvailability(Product $product, int $ref, int $quantity): ?string {
		return null;
	}

	public function fulfill(User $user, Product $product, OrderItem $order_item, Order $order, int $ref): array {
		// A failure here must never break the purchase — the poll task
		// re-derives everything from the order and creates the row itself.
		try {
			$provision = $this->create_provision($user, $order_item, self::mode_for_ref($ref));
		} catch (\Throwable $e) {
			error_log('CustomerCloudFulfillment: provision creation failed for order item #'
				. $order_item->key . ' (' . $e->getMessage() . ') — Poll Hosting Orders will pick it up.');
			$provision = null;
		}
		if ($provision === null) {
			return array('ref_id' => null, 'label' => 'Server provisioning queued', 'labels' => null);
		}
		return array('ref_id' => (int)$provision->key,
			'label' => 'Server for ' . $provision->get('cvp_domain'), 'labels' => null);
	}

	/**
	 * Create the provision row for a paid order item, reading the domain
	 * from the order's stored checkout answers. Returns null (defer to the
	 * poll task) when the domain is unreadable or a row already exists.
	 */
	private function create_provision(User $user, OrderItem $order_item, string $hosting_mode = 'customer'): ?CustomerCloudProvision {
		if (!$order_item->key || !$user->key) {
			return null;
		}
		$settings = Globalvars::get_instance();
		$question_id = (int)$settings->get_setting('server_manager_provisioning_domain_question_id');
		if ($question_id <= 0) {
			return null;
		}

		require_once(PathHelper::getIncludePath('plugins/store/data/order_item_requirements_class.php'));
		$answers = new MultiOrderItemRequirement(array('order_item_id' => (int)$order_item->key));
		$answers->load();
		$domain = '';
		foreach ($answers as $req) {
			if ((int)$req->get('oir_qst_question_id') === $question_id) {
				$domain = trim((string)$req->get('oir_answer'));
				break;
			}
		}
		if ($domain === '') {
			return null;
		}

		$slug = strtolower($domain);
		$slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
		$slug = preg_replace('/-+/', '-', $slug);
		$slug = trim($slug, '-');
		if ($slug === '') {
			return null;
		}

		require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_provision_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_account_class.php'));

		$existing = new MultiCustomerCloudProvision(array(
			'external_order_item_id' => (int)$order_item->key, 'deleted' => false));
		if ($existing->count_all() > 0) {
			return null;
		}

		$provision = new CustomerCloudProvision(NULL);
		$provision->set('cvp_external_order_item_id', (int)$order_item->key);
		$provision->set('cvp_usr_user_id', (int)$user->key);
		$provision->set('cvp_domain', $domain);
		$provision->set('cvp_slug', $slug);
		$provision->set('cvp_hosting_mode', $hosting_mode);
		$provision->set('cvp_buyer_email', (string)$user->get('usr_email'));
		$provision->set('cvp_buyer_name',
			trim((string)$user->get('usr_first_name') . ' ' . (string)$user->get('usr_last_name')));

		if ($hosting_mode === 'operator') {
			// Nothing to connect and nobody to ask: the instance is created on
			// the operator's own account with the operator's own token. The
			// buyer paid and the pipeline starts on the next tick.
			$provision->set('cvp_status', 'ready');
			// The hosted legs are owed from birth. Marking them pending here,
			// rather than when the site comes up, is what makes an operator
			// able to see a hosted provision whose mail never got set up.
			$provision->set('cvp_mail_state', 'pending');
		} else {
			// A buyer who already granted access skips the Connect wait entirely.
			$account = CustomerCloudAccount::get_for_user((int)$user->key, 'linode');
			if ($account !== null && $account->get('cca_status') === 'active') {
				$provision->set('cvp_cca_account_id', $account->key);
				$provision->set('cvp_status', 'ready');
			} else {
				$provision->set('cvp_status', 'pending_connect');
			}
		}
		$provision->save();
		$provision->load();
		return $provision;
	}

	public function displayReference(int $ref): string {
		return '';
	}
}
