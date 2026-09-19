<?php
/**
 * CustomerCloudFulfillment — turns a paid hosting line into a running site,
 * on the cloud account the PRODUCT names.
 *
 * Selecting this in the product-edit Purchase grants picker is the whole
 * product-side setup: it stamps pro_fulfillment_provider = customer_cloud and
 * contributes the one requirement the line needs — the id of the site the
 * buyer configured beforehand on the Server Manager configure page
 * (ManagedSiteRequirement, the umbrella spec's contract C1). Nothing about
 * the site is asked in the cart; adding a question later is a column and a
 * form field on that page, never a change to the store.
 *
 * The buyer configures first, then pays; PAYMENT ACTIVATES. The configure
 * page leaves a draft provision at pending_payment, which holds nothing — no
 * instance, no domain, no slug. Three moments here:
 *
 *   checkAvailability()  before the charge: the line's draft must still be
 *                        pending_payment and the buyer's, or the charge is
 *                        refused with a sentence (the buyer edited the site
 *                        after adding it to the cart; the line is stale).
 *   fulfill()            after the charge: activate the draft — status ready,
 *                        the paid order item stamped, the hosting mode from
 *                        the product's reference, and, when we register the
 *                        name, the registration row from the draft's sealed
 *                        registrant and frozen quote. From here the pipeline
 *                        is the one that exists.
 *   a refusal at fulfill is logged AND alerted with the order item id: the
 *                        order is paid, so it is an operator's task, never
 *                        silent.
 *
 * WHOSE ACCOUNT THE SERVER IS BORN ON IS THE PRODUCT'S DECISION, NOT THE
 * BUYER'S. The picker offers two references and the product stores the one it
 * was set to:
 *
 *   0  the buyer's own cloud account. They connect it, the provider bills
 *      them, and none of the operator's keys are involved.
 *   1  the operator's account. The plane's own token creates the instance,
 *      there is no Connect page and no grant to wait for, and the hosted legs
 *      — mail on our provider, the trial clock, the allowance banners — apply
 *      (specs/hosted_trial_provisioning.md §4.1). This is Managed hosting.
 *
 * A buyer never chooses between them, because they are two products with two
 * prices and two arrangements, not one product with a switch.
 *
 * Registered from server_manager's serve.php when the store plugin is
 * present.
 *
 * @version 1.4 - configure first, pay once, payment activates (specs/managed_hosting_phase1_purchase.md):
 *                extraRequirements() contributes ManagedSiteRequirement, checkAvailability() refuses a
 *                stale draft before the charge, fulfill() activates the draft and files the domain row
 * @version 1.3 - a second option: the server is created on the OPERATOR's account (hosted)
 * @version 1.2
 */
require_once(PathHelper::getIncludePath('plugins/store/includes/FulfillmentRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/requirements/ManagedSiteRequirement.php'));

class CustomerCloudFulfillment implements FulfillmentProvider {

	public function key(): string {
		return 'customer_cloud';
	}

	public function label(): string {
		return 'Customer cloud server';
	}

	/** The reference a product stores for each hosting mode. */
	const REF_CUSTOMER = 0;
	const REF_OPERATOR = 1;

	/** What the buyer reads when the line's draft moved after it was added. */
	const STALE_DRAFT_SENTENCE = 'You changed your site setup after adding it to the cart. '
		. 'Remove it from the cart and continue to payment again.';

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

	/**
	 * The one requirement: the id of the draft the buyer configured. It is
	 * the whole coupling between the store and Server Manager.
	 */
	public function extraRequirements(Product $product, int $ref): array {
		return array(new ManagedSiteRequirement());
	}

	/**
	 * Before the charge: is the line's draft still the one the line was built
	 * from? Editing a frozen draft returns it to draft, and freezing it again
	 * gives it a new freeze time; a cart line built from the earlier freeze is
	 * stale either way — its summary, its quote and its domain line may all
	 * describe a site the buyer no longer wants. Refused with a sentence while
	 * declining is free.
	 */
	public function checkAvailability(Product $product, int $ref, int $quantity, array $data = array()): ?string {
		$session = SessionControl::get_instance();
		$draft = ManagedSiteRequirement::draft_for_line($data, (int)$session->get_user_id());
		if ($draft === null) {
			return self::STALE_DRAFT_SENTENCE;
		}
		return null;
	}

	/** The draft id a cart line or an order item's stored answers carry. */
	public static function draft_id_from(array $data): int {
		$answer = $data[ManagedSiteRequirement::FIELD] ?? 0;
		if (is_array($answer)) {
			$answer = $answer['answer'] ?? 0;
		}
		return (int)$answer;
	}

	public function fulfill(User $user, Product $product, OrderItem $order_item, Order $order, int $ref): array {
		// A failure here must never break the purchase: the order is paid.
		// Anything that stops the activation is written down and alerted with
		// the order item id, so a person can finish it.
		try {
			$provision = $this->activate_draft($user, $order_item, self::mode_for_ref($ref));
		} catch (\Throwable $e) {
			$this->alert_activation_problem($user, $order_item, $e->getMessage());
			$provision = null;
		}
		if ($provision === null) {
			return array('ref_id' => null, 'label' => 'Server provisioning needs attention', 'labels' => null);
		}
		return array('ref_id' => (int)$provision->key,
			'label' => 'Server for ' . $provision->get('cvp_domain'), 'labels' => null);
	}

	/**
	 * pending_payment -> ready, with the paid order item stamped.
	 *
	 * Idempotent on the order item: a second call for the same item returns
	 * the row the first one activated. Anything else that is not exactly a
	 * pending_payment draft owned by this buyer throws, and the caller alerts.
	 */
	private function activate_draft(User $user, OrderItem $order_item, string $hosting_mode): ?CustomerCloudProvision {
		if (!$order_item->key || !$user->key) {
			throw new RuntimeException('The order item or the buyer could not be loaded.');
		}
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_provisions_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_accounts_class.php'));

		$existing = new MultiCustomerCloudProvision(array(
			'external_order_item_id' => (int)$order_item->key, 'deleted' => false));
		foreach ($existing as $row) {
			return $row;   // already activated — fulfill() can run more than once
		}

		$draft_id = self::draft_id_from((array)$order_item->get_raw_data());
		if ($draft_id <= 0) {
			throw new RuntimeException('The paid line carries no site draft id.');
		}
		$draft = new CustomerCloudProvision($draft_id, TRUE);
		if (!$draft->key || $draft->get('cvp_delete_time')) {
			throw new RuntimeException('Site draft #' . $draft_id . ' no longer exists.');
		}
		if ((int)$draft->get('cvp_usr_user_id') !== (int)$user->key) {
			throw new RuntimeException('Site draft #' . $draft_id . ' belongs to user #'
				. (int)$draft->get('cvp_usr_user_id') . ', not the buyer (#' . (int)$user->key . ').');
		}
		if ((string)$draft->get('cvp_status') !== 'pending_payment') {
			throw new RuntimeException('Site draft #' . $draft_id . " is at '" . $draft->get('cvp_status')
				. "', not pending_payment; the buyer edited it after adding it to the cart.");
		}

		// A slug or domain a live provision already holds fails the activation
		// closed: the row keeps the paid order item, so it is on the operator's
		// board, and the alert names it. The Continue check makes this rare; it
		// cannot make it impossible.
		$held = CustomerCloudProvision::held_by_live((string)$draft->get('cvp_domain'),
			(string)$draft->get('cvp_slug'), (int)$draft->key);
		if ($held !== null) {
			$draft->set('cvp_external_order_item_id', (int)$order_item->key);
			$draft->fail("Domain '" . $draft->get('cvp_domain') . "' (slug " . $draft->get('cvp_slug')
				. ') is already held by provision #' . $held->key . ' — manual resolution required.');
			throw new RuntimeException('Site draft #' . $draft_id . ' failed at activation: '
				. $draft->get('cvp_error'));
		}

		$draft->set('cvp_external_order_item_id', (int)$order_item->key);
		$draft->set('cvp_hosting_mode', $hosting_mode);
		$draft->set('cvp_buyer_name',
			trim((string)$user->get('usr_first_name') . ' ' . (string)$user->get('usr_last_name')));
		if (trim((string)$draft->get('cvp_buyer_email')) === '') {
			$draft->set('cvp_buyer_email', (string)$user->get('usr_email'));
		}
		if ($hosting_mode === 'operator') {
			// Nothing to connect and nobody to ask: the instance is created on
			// the operator's own account with the operator's own token.
			$draft->set('cvp_status', 'ready');
			// The hosted legs are owed from birth. Marking them pending here,
			// rather than when the site comes up, is what makes an operator
			// able to see a hosted provision whose mail never got set up.
			$draft->set('cvp_mail_state', 'pending');
		} else {
			// A buyer who already granted access skips the Connect wait entirely.
			$account = CustomerCloudAccount::get_for_user((int)$user->key, 'linode');
			if ($account !== null && $account->get('cca_status') === 'active') {
				$draft->set('cvp_cca_customer_cloud_account_id', $account->key);
				$draft->set('cvp_status', 'ready');
			} else {
				$draft->set('cvp_status', 'pending_connect');
			}
		}
		$draft->set('cvp_error', null);
		$draft->save();
		$draft->load();

		if ((string)$draft->get('cvp_domain_source') === 'register') {
			$this->file_domain_row($draft, $user, $order_item);
		}
		return $draft;
	}

	/**
	 * The registration row the domain pipeline works from, created from the
	 * draft's sealed registrant and frozen quote — exactly the row the
	 * checkout-time intake used to file. Idempotent on the order item.
	 *
	 * A failure here does not undo the activation: the site is still owed.
	 * It is alerted, because the commonest cause is two buyers racing for
	 * the same name — the loser hits the unique constraint after paying.
	 */
	private function file_domain_row(CustomerCloudProvision $draft, User $user, OrderItem $order_item): void {
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/registered_domains_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/ManagedDomainIntake.php'));
		try {
			$existing = new MultiRegisteredDomain(array(
				'external_order_item_id' => (int)$order_item->key, 'deleted' => false));
			foreach ($existing as $row) {
				return;
			}
			$registrant = $draft->open_registrant();
			if (!is_array($registrant) || trim((string)($registrant['email'] ?? '')) === '') {
				throw new RuntimeException('the sealed registrant block on the draft cannot be read');
			}
			$registrar = ManagedDomainIntake::registrar();
			$row = new RegisteredDomain(NULL);
			$row->set('rdm_registrar', $registrar ? $registrar::getKey() : 'namecheap');
			$row->set('rdm_domain', (string)$draft->get('cvp_domain'));
			$row->set('rdm_usr_user_id', (int)$user->key);
			$row->set('rdm_external_order_item_id', (int)$order_item->key);
			$row->set('rdm_buyer_email', (string)$user->get('usr_email'));
			$row->set('rdm_price_paid', number_format((float)$draft->get('cvp_domain_quote'), 2, '.', ''));
			$row->set('rdm_status', RegisteredDomain::STATUS_PENDING);
			$row->set('rdm_graduation_state', RegisteredDomain::GRAD_OPERATOR);
			$row->seal_registrant($registrant);
			$row->prepare();
			$row->save();
			// One copy of a home address, on the row that registers with it.
			$draft->set('cvp_registrant_sealed', null);
			$draft->save();
		} catch (\Throwable $e) {
			error_log('CustomerCloudFulfillment: could not file the domain row for order item #'
				. $order_item->key . ': ' . $e->getMessage());
			$this->alert_activation_problem($user, $order_item,
				'The site was activated but its domain ' . $draft->get('cvp_domain')
				. ' could not be queued for registration: ' . $e->getMessage()
				. "\nNothing is registered and nothing will retry on its own. If the name was taken by "
				. 'another order in the same moment, the buyer needs a refund or an alternate name.');
		}
	}

	/** A paid order that could not be activated is a person's task, never silent. Protected: the mail edge a test double intercepts. */
	protected function alert_activation_problem(User $user, OrderItem $order_item, string $reason): void {
		$line = 'CustomerCloudFulfillment: order item #' . $order_item->key . ' (buyer '
			. $user->get('usr_email') . '): ' . $reason;
		error_log($line);
		try {
			require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
			require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/ProvisionManagedDomains.php'));
			$to = ProvisionManagedDomains::resolve_alert_recipient();
			if ($to === '') {
				return;
			}
			$body = "A paid Managed hosting order could not be activated.\n\n"
				. 'Order item: ' . $order_item->key . "\n"
				. 'Buyer: ' . $user->get('usr_email') . "\n"
				. 'Reason: ' . $reason . "\n\n"
				. "The buyer has paid. Look at the provision on /admin/server_manager and resolve it with them.\n";
			EmailSender::quickSend($to, '[managed-hosting] Paid but not activated: order item #' . $order_item->key, $body);
		} catch (\Throwable $e) {
			error_log('CustomerCloudFulfillment: activation alert also failed: ' . $e->getMessage());
		}
	}

	public function displayReference(int $ref): string {
		return '';
	}
}
