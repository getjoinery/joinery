<?php
/**
 * HostedAiCreditFulfillment — a paid "AI credit" line becomes bought credit on
 * one site's AI account (specs/hosted_ai_credit.md §3.7).
 *
 * The product is one version priced "User chooses", from $5 to $500 (the
 * store's own range on the version). Picking this provider in the product
 * edit page's Purchase grants picker is the whole product-side setup; its
 * line carries one requirement, the site (HostedAiCreditRequirement).
 *
 * THE AMOUNT IS READ FROM THE ORDER LINE, NOT THE FORM: odi_price is what was
 * charged. The credit is that ÷ (1 + markup), with the markup in force now
 * recorded on the purchase row, so a later change to the markup never touches
 * credit already bought. A purchase applies once per order line however many
 * times fulfilment runs (HostedAiLedger's unique purchase line). A refund,
 * done by hand on the store's refund page, is found by the setup leg's watch.
 *
 * @version 1.1 - an account being removed is refused, like a removed one (B14)
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('plugins/store/includes/FulfillmentRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/requirements/HostedAiCreditRequirement.php'));

class HostedAiCreditFulfillment implements FulfillmentProvider {

	const KEY = 'hosted_ai_credit';

	/** The one thing this provider grants. */
	const REF_CREDIT = 1;

	/** What the product page and receipt say about the credit. */
	const TERMS_SENTENCE = 'Unspent credit is refundable on request, and ends when the site is removed.';

	public function key(): string {
		return self::KEY;
	}

	public function label(): string {
		return 'AI credit for one of the buyer\'s sites';
	}

	public function options(): array {
		return array(self::REF_CREDIT => 'AI credit (the amount the buyer pays)');
	}

	public function extraRequirements(Product $product, int $ref): array {
		return array(new HostedAiCreditRequirement());
	}

	/** Before the charge: the line's site is still the buyer's and still with us. */
	public function checkAvailability(Product $product, int $ref, int $quantity, array $data = array()): ?string {
		if ($ref !== self::REF_CREDIT) {
			return 'This product is not set up to grant AI credit yet.';
		}
		$user_id = (int)SessionControl::get_instance()->get_user_id();
		if (HostedAiCreditRequirement::account_for_user(HostedAiCreditRequirement::account_id_from($data), $user_id) === null) {
			return 'The site this AI credit is for is no longer one of yours with us. Remove it from the cart.';
		}
		return null;
	}

	public function fulfill(User $user, Product $product, OrderItem $order_item, Order $order, int $ref): array {
		$account_id = HostedAiCreditRequirement::account_id_from((array)$order_item->get_raw_data());
		$paid = round((float)$order_item->get('odi_price'), 2);
		// The order is paid: a failure here is written down and alerted, never
		// thrown into the purchase.
		try {
			$account = new HostedAiAccount($account_id, TRUE);
			if (!$account->key || in_array((string)$account->get('hai_state'),
					array(HostedAiAccount::STATE_REMOVED, HostedAiAccount::STATE_REMOVING), true)) {
				throw new RuntimeException('AI credit account #' . $account_id . ' is not a live site\'s.');
			}
			if ($paid <= 0) {
				throw new RuntimeException('The line was paid $' . number_format($paid, 2) . '.');
			}
			$row = HostedAiCredit::purchase($account, (int)$order_item->key, $paid);
		} catch (Throwable $e) {
			self::alert_problem($user, $order_item, $e->getMessage());
			return array('ref_id' => null, 'label' => 'AI credit needs attention', 'labels' => null, 'outcome' => 'plain');
		}
		$site = ProvisionHostedAi::label($account);
		return array(
			'ref_id' => null,
			'label'  => HostedAiCap::dollars((int)$row->get('hal_amount')) . ' of AI credit for ' . $site,
			'labels' => null,
			'outcome' => 'plain',
			'after_purchase_message' => '<p>' . htmlspecialchars(HostedAiCap::dollars((int)$row->get('hal_amount'))
				. ' of AI usage was added to ' . $site . '. It is ready to use now. ' . self::TERMS_SENTENCE) . '</p>',
		);
	}

	public function displayReference(int $ref): string {
		return $ref === self::REF_CREDIT ? 'AI credit' : '';
	}

	private static function alert_problem(User $user, OrderItem $order_item, string $reason): void {
		error_log('HostedAiCreditFulfillment: order item #' . $order_item->key . ': ' . $reason);
		ProvisionHostedAi::notify_ops('[hosted] AI credit paid but not added: order item #' . $order_item->key,
			"A paid AI credit order could not be added to a site.\n\n"
			. 'Order item: ' . $order_item->key . "\n"
			. 'Buyer: ' . $user->get('usr_email') . "\n"
			. 'Reason: ' . $reason . "\n\n"
			. "The buyer has paid. Add the credit with Add credit on the site's node page, or refund the line.\n");
	}
}
