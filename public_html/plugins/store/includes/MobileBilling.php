<?php
/**
 * MobileBilling — shared engine behind App Store / Google Play billing.
 *
 * Everything that changes billing state for mobile-store subscriptions
 * funnels through here: the claim actions (app posts its purchase for
 * server-side validation) and both store webhooks (renewals, cancellations,
 * refunds). Callers verify signatures first (AppStoreHelper /
 * GooglePlayHelper); this class only ever sees verified payloads.
 *
 * A store purchase becomes a normal Order + OrderItem (payment source
 * app_store/play_store), so subscription summary, admin orders, and tier
 * grant/revoke all work through the existing paths. Tier changes go through
 * TierBilling::handleProductPurchase / handleSubscriptionExpired only.
 *
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('plugins/store/data/orders_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/order_items_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/mobile_store_products_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/includes/TierBilling.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));

class MobileBillingException extends Exception {}

class MobileBilling {

	/**
	 * Deterministic, reversible account token for store-side user linkage.
	 * iOS passes it as appAccountToken on purchase; Android as
	 * obfuscatedAccountId. Webhook events that arrive before (or without) a
	 * claim can be traced back to the user through it.
	 */
	public static function appAccountTokenForUser($user_id) {
		return sprintf('00000000-0000-4000-8000-%012x', (int)$user_id);
	}

	public static function userIdFromAppAccountToken($token) {
		if (is_string($token) && preg_match('/^00000000-0000-4000-8000-([0-9a-f]{12})$/i', $token, $matches)) {
			return (int)hexdec($matches[1]);
		}
		return null;
	}

	// ------------------------------------------------------------------
	// App Store
	// ------------------------------------------------------------------

	/**
	 * Claim a verified StoreKit 2 transaction payload for $user_id: validate
	 * it entitles a mapped product, enforce source exclusivity, create or
	 * update the Order/OrderItem, and grant the tier. Returns a summary
	 * array. Throws MobileBillingException with a user-displayable message on
	 * any rejection.
	 */
	public static function claimAppStoreTransaction($user_id, array $transaction) {
		require_once(PathHelper::getIncludePath('plugins/store/includes/AppStoreHelper.php'));

		$bundle_id = $transaction['bundleId'] ?? '';
		$allowed_bundles = AppStoreHelper::allowedBundleIds();
		if (empty($allowed_bundles) || !in_array($bundle_id, $allowed_bundles)) {
			throw new MobileBillingException('This app is not enabled for App Store billing.');
		}

		$environment = $transaction['environment'] ?? 'Production';
		if (!AppStoreHelper::environmentAllowed($environment)) {
			throw new MobileBillingException('Sandbox purchases are not accepted here.');
		}

		if (!empty($transaction['type']) && $transaction['type'] !== 'Auto-Renewable Subscription') {
			throw new MobileBillingException('Only auto-renewable subscriptions can be claimed.');
		}

		if (!empty($transaction['revocationDate'])) {
			throw new MobileBillingException('This purchase has been refunded.');
		}

		$expires_ms = (int)($transaction['expiresDate'] ?? 0);
		if ($expires_ms <= 0 || ($expires_ms / 1000) <= time()) {
			throw new MobileBillingException('This subscription is not active.');
		}

		$product_id = $transaction['productId'] ?? '';
		$original_transaction_id = $transaction['originalTransactionId'] ?? '';
		if (!$product_id || !$original_transaction_id) {
			throw new MobileBillingException('The purchase record is incomplete.');
		}

		$mapping = MobileStoreProduct::GetByStoreProductId(MobileStoreProduct::STORE_APP_STORE, $product_id);
		if ($mapping === NULL) {
			throw new MobileBillingException('This App Store product is not configured for a plan.');
		}

		$period_end = gmdate('Y-m-d H:i:s', (int)($expires_ms / 1000));
		$price = null;
		if (isset($transaction['price'])) {
			// App Store Server API prices are in milliunits of the currency.
			$price = round(((float)$transaction['price']) / 1000, 2);
		}

		return self::claimStorePurchase($user_id, 'app_store', $mapping, array(
			'provider_reference_field' => 'odi_app_store_original_transaction_id',
			'provider_reference'       => $original_transaction_id,
			'period_end'               => $period_end,
			'price'                    => $price,
			'sandbox'                  => (strcasecmp((string)$environment, 'Sandbox') === 0),
			'product_info'             => array(
				'app_store_bundle_id'  => $bundle_id,
				'app_store_product_id' => $product_id,
			),
		));
	}

	/**
	 * Apply a verified App Store Server Notification V2 to billing state.
	 * $transaction is the decoded signedTransactionInfo; $renewal the decoded
	 * signedRenewalInfo (may be null). Returns ['processed' => bool,
	 * 'message' => string].
	 */
	public static function applyAppStoreEvent($notification_type, $subtype, array $transaction, $renewal = null) {
		$original_transaction_id = $transaction['originalTransactionId'] ?? '';
		if (!$original_transaction_id) {
			return array('processed' => false, 'message' => 'Notification carries no originalTransactionId');
		}

		$order_item = self::findOrderItem('odi_app_store_original_transaction_id', $original_transaction_id);

		$entitling = in_array($notification_type, array('SUBSCRIBED', 'DID_RENEW', 'OFFER_REDEEMED'));

		if ($order_item === null) {
			// No claim yet — link through the appAccountToken the kit sets at
			// purchase time.
			$user_id = self::userIdFromAppAccountToken($transaction['appAccountToken'] ?? null);
			if ($user_id && $entitling) {
				try {
					self::claimAppStoreTransaction($user_id, $transaction);
					return array('processed' => true, 'message' => 'Created subscription from notification');
				} catch (MobileBillingException $e) {
					return array('processed' => false, 'message' => 'Claim from notification failed: ' . $e->getMessage());
				}
			}
			return array('processed' => false, 'message' => 'No order item for transaction ' . $original_transaction_id);
		}

		switch ($notification_type) {
			case 'SUBSCRIBED':
			case 'DID_RENEW':
			case 'OFFER_REDEEMED':
				self::renewStoreItem($order_item, $transaction);
				return array('processed' => true, 'message' => 'Subscription renewed/active');

			case 'DID_CHANGE_RENEWAL_PREF':
				// Plan change: upgrades take effect immediately (the
				// transaction already carries the new productId), downgrades
				// at next renewal (handled by the eventual DID_RENEW).
				self::renewStoreItem($order_item, $transaction);
				return array('processed' => true, 'message' => 'Renewal preference changed');

			case 'DID_CHANGE_RENEWAL_STATUS':
				$disabled = ($subtype === 'AUTO_RENEW_DISABLED');
				$order_item->set('odi_subscription_cancel_at_period_end', $disabled);
				$order_item->save();
				return array('processed' => true, 'message' => $disabled ? 'Auto-renew disabled' : 'Auto-renew enabled');

			case 'DID_FAIL_TO_RENEW':
				$status = ($subtype === 'GRACE_PERIOD') ? 'grace_period' : 'past_due';
				$order_item->set('odi_subscription_status', $status);
				$order_item->save();
				self::dispatchSignal('subscription.payment_failed', $order_item, 'app_store', $original_transaction_id);
				return array('processed' => true, 'message' => 'Renewal failed (' . $status . ')');

			case 'GRACE_PERIOD_EXPIRED':
			case 'EXPIRED':
				self::expireStoreItem($order_item, 'app_store', $original_transaction_id);
				return array('processed' => true, 'message' => 'Subscription expired');

			case 'REFUND':
			case 'REVOKE':
				if (!empty($transaction['revocationDate'])) {
					$order_item->set('odi_refund_time', gmdate('Y-m-d H:i:s', (int)($transaction['revocationDate'] / 1000)));
				} else {
					$order_item->set('odi_refund_time', gmdate('Y-m-d H:i:s'));
				}
				if ($order_item->get('odi_price')) {
					$order_item->set('odi_refund_amount', $order_item->get('odi_price'));
				}
				$order_item->set('odi_refund_note', 'Refunded by the App Store');
				self::expireStoreItem($order_item, 'app_store', $original_transaction_id);
				return array('processed' => true, 'message' => 'Refunded and revoked');

			default:
				return array('processed' => true, 'message' => 'Ignored notification type ' . $notification_type);
		}
	}

	// ------------------------------------------------------------------
	// Google Play
	// ------------------------------------------------------------------

	/**
	 * Claim a verified Play subscription purchase (subscriptionsv2 resource)
	 * for $user_id. Mirrors claimAppStoreTransaction.
	 */
	public static function claimPlayPurchase($user_id, $package_name, $purchase_token, array $purchase) {
		require_once(PathHelper::getIncludePath('plugins/store/includes/GooglePlayHelper.php'));

		$allowed_packages = GooglePlayHelper::allowedPackageNames();
		if (empty($allowed_packages) || !in_array($package_name, $allowed_packages)) {
			throw new MobileBillingException('This app is not enabled for Google Play billing.');
		}

		if (isset($purchase['testPurchase']) && !GooglePlayHelper::testPurchaseAllowed()) {
			throw new MobileBillingException('Test purchases are not accepted here.');
		}

		$state = $purchase['subscriptionState'] ?? '';
		$expiry = GooglePlayHelper::purchaseExpiryTime($purchase);
		$entitled = in_array($state, array(
			'SUBSCRIPTION_STATE_ACTIVE',
			'SUBSCRIPTION_STATE_IN_GRACE_PERIOD',
			'SUBSCRIPTION_STATE_CANCELED',
		)) && $expiry !== null && $expiry > gmdate('Y-m-d H:i:s');
		if (!$entitled) {
			throw new MobileBillingException('This subscription is not active.');
		}

		$line_product_id = '';
		foreach (($purchase['lineItems'] ?? array()) as $line) {
			if (!empty($line['productId'])) {
				$line_product_id = $line['productId'];
			}
		}
		if (!$line_product_id || !$purchase_token) {
			throw new MobileBillingException('The purchase record is incomplete.');
		}

		$mapping = MobileStoreProduct::GetByStoreProductId(MobileStoreProduct::STORE_PLAY_STORE, $line_product_id);
		if ($mapping === NULL) {
			throw new MobileBillingException('This Google Play product is not configured for a plan.');
		}

		$result = self::claimStorePurchase($user_id, 'play_store', $mapping, array(
			'provider_reference_field' => 'odi_play_purchase_token',
			'provider_reference'       => $purchase_token,
			'period_end'               => $expiry,
			'price'                    => null,
			'sandbox'                  => isset($purchase['testPurchase']),
			'product_info'             => array(
				'play_package_name'    => $package_name,
				'play_subscription_id' => $line_product_id,
			),
			'cancel_at_period_end'     => ($state === 'SUBSCRIPTION_STATE_CANCELED'),
		));

		// Unacknowledged purchases are auto-refunded by Google after three
		// days. Best-effort: a failed acknowledge is logged, not fatal — the
		// next RTDN refresh retries it.
		if (($purchase['acknowledgementState'] ?? '') === 'ACKNOWLEDGEMENT_STATE_PENDING') {
			try {
				GooglePlayHelper::acknowledgeSubscription($package_name, $line_product_id, $purchase_token);
			} catch (Exception $e) {
				error_log('Play acknowledge failed for token ' . substr($purchase_token, 0, 16) . '…: ' . $e->getMessage());
			}
		}

		return $result;
	}

	/**
	 * Apply a verified RTDN subscription notification. $purchase is the
	 * current subscriptionsv2 resource (fetched by the webhook — the RTDN
	 * itself carries no state). Returns ['processed' => bool, 'message' => string].
	 */
	public static function applyPlayEvent($notification_type, $package_name, $purchase_token, array $purchase) {
		require_once(PathHelper::getIncludePath('plugins/store/includes/GooglePlayHelper.php'));

		$order_item = self::findOrderItem('odi_play_purchase_token', $purchase_token);

		if ($order_item === null) {
			$token = $purchase['externalAccountIdentifiers']['obfuscatedExternalAccountId'] ?? null;
			$user_id = self::userIdFromAppAccountToken($token);
			if ($user_id) {
				try {
					self::claimPlayPurchase($user_id, $package_name, $purchase_token, $purchase);
					return array('processed' => true, 'message' => 'Created subscription from notification');
				} catch (MobileBillingException $e) {
					return array('processed' => false, 'message' => 'Claim from notification failed: ' . $e->getMessage());
				}
			}
			return array('processed' => false, 'message' => 'No order item for purchase token');
		}

		// SUBSCRIPTION_REVOKED (12): refund + immediate revoke.
		if ((int)$notification_type === 12) {
			$order_item->set('odi_refund_time', gmdate('Y-m-d H:i:s'));
			if ($order_item->get('odi_price')) {
				$order_item->set('odi_refund_amount', $order_item->get('odi_price'));
			}
			$order_item->set('odi_refund_note', 'Revoked by Google Play');
			self::expireStoreItem($order_item, 'play_store', $purchase_token);
			return array('processed' => true, 'message' => 'Revoked and refunded');
		}

		$state = $purchase['subscriptionState'] ?? '';
		$status = GooglePlayHelper::mapSubscriptionState($state);

		if ($status === 'expired') {
			self::expireStoreItem($order_item, 'play_store', $purchase_token);
			return array('processed' => true, 'message' => 'Subscription expired');
		}

		$order_item->set('odi_subscription_status', $status);
		$expiry = GooglePlayHelper::purchaseExpiryTime($purchase);
		if ($expiry !== null) {
			$order_item->set('odi_subscription_period_end', $expiry);
		}
		$order_item->set('odi_subscription_cancel_at_period_end', $state === 'SUBSCRIPTION_STATE_CANCELED');
		$order_item->save();

		if ($status === 'past_due') {
			self::dispatchSignal('subscription.payment_failed', $order_item, 'play_store', $purchase_token);
		}

		// Renewals can carry a new productId (plan change at renewal).
		$line_product_id = '';
		foreach (($purchase['lineItems'] ?? array()) as $line) {
			if (!empty($line['productId'])) {
				$line_product_id = $line['productId'];
			}
		}
		if ($line_product_id && $status === 'active') {
			$mapping = MobileStoreProduct::GetByStoreProductId(MobileStoreProduct::STORE_PLAY_STORE, $line_product_id);
			if ($mapping !== NULL) {
				self::applyProductMapping($order_item, $mapping);
			}
		}

		if (($purchase['acknowledgementState'] ?? '') === 'ACKNOWLEDGEMENT_STATE_PENDING') {
			try {
				GooglePlayHelper::acknowledgeSubscription($package_name, $line_product_id, $purchase_token);
			} catch (Exception $e) {
				error_log('Play acknowledge failed for token ' . substr($purchase_token, 0, 16) . '…: ' . $e->getMessage());
			}
		}

		return array('processed' => true, 'message' => 'Subscription updated (' . $status . ')');
	}

	// ------------------------------------------------------------------
	// Shared internals
	// ------------------------------------------------------------------

	/**
	 * Create or update the Order/OrderItem for a verified store purchase and
	 * grant the mapped tier. Shared by both stores' claim paths.
	 */
	private static function claimStorePurchase($user_id, $source, MobileStoreProduct $mapping, array $details) {
		$user = new User($user_id, TRUE);
		if (!$user->key) {
			throw new MobileBillingException('Unknown user.');
		}

		$product = new Product($mapping->get('msp_pro_product_id'), TRUE);
		if (!$product->key) {
			throw new MobileBillingException('The mapped product no longer exists.');
		}

		$reference_field = $details['provider_reference_field'];
		$reference = $details['provider_reference'];

		$existing = self::findOrderItem($reference_field, $reference);
		if ($existing !== null) {
			if ((int)$existing->get('odi_usr_user_id') !== (int)$user_id) {
				throw new MobileBillingException('This purchase belongs to a different account.');
			}
			// Re-claim (restore purchases, app reinstall): refresh state and
			// re-grant — addUser() is upgrade-only, so this is idempotent.
			$existing->set('odi_subscription_status', 'active');
			$existing->set('odi_subscription_period_end', $details['period_end']);
			if (!empty($details['cancel_at_period_end'])) {
				$existing->set('odi_subscription_cancel_at_period_end', true);
			}
			self::applyProductMapping($existing, $mapping);
			$order = $existing->get_order();
			TierBilling::handleProductPurchase($user, self::orderItemProduct($existing), $existing, $order);
			return self::claimSummary($existing);
		}

		$conflict = TierBilling::sourceConflict($user_id, $source);
		if ($conflict !== null) {
			throw new MobileBillingException(
				'You already have an active subscription through ' . TierBilling::sourceLabel($conflict)
				. '. Cancel it there before subscribing here.'
			);
		}

		$price = $details['price'];
		if ($price === null) {
			if ($mapping->get('msp_prv_product_version_id')) {
				$version = new ProductVersion($mapping->get('msp_prv_product_version_id'), TRUE);
				$price = $version->key ? $version->get('prv_version_price') : $product->get('pro_price');
			} else {
				$price = $product->get('pro_price');
			}
		}

		$order = new Order(NULL);
		$order->set('ord_usr_user_id', $user_id);
		$order->set('ord_status', Order::STATUS_PAID);
		$order->set('ord_total_cost', $price);
		$order->set('ord_payment_method', $source);
		$order->set('ord_test_mode', !empty($details['sandbox']));
		$order->save();
		$order->load();

		$order_item = new OrderItem(NULL);
		$order_item->set('odi_ord_order_id', $order->key);
		$order_item->set('odi_pro_product_id', $product->key);
		if ($mapping->get('msp_prv_product_version_id')) {
			$order_item->set('odi_prv_product_version_id', $mapping->get('msp_prv_product_version_id'));
		}
		$order_item->set('odi_usr_user_id', $user_id);
		$order_item->set('odi_price', $price);
		$order_item->set('odi_status', OrderItem::STATUS_PAID);
		$order_item->set('odi_status_change_time', 'now()');
		$order_item->set('odi_is_subscription', true);
		$order_item->set('odi_payment_source', $source);
		$order_item->set($reference_field, $reference);
		$order_item->set('odi_store_environment', !empty($details['sandbox']) ? 'sandbox' : 'production');
		$order_item->set('odi_subscription_status', 'active');
		$order_item->set('odi_subscription_period_end', $details['period_end']);
		if (!empty($details['cancel_at_period_end'])) {
			$order_item->set('odi_subscription_cancel_at_period_end', true);
		}
		$order_item->set('odi_product_info', base64_encode(serialize($details['product_info'])));
		$order_item->save();
		$order_item->load();

		TierBilling::handleProductPurchase($user, $product, $order_item, $order);

		require_once(PathHelper::getIncludePath('includes/SignalBus.php'));
		SignalBus::dispatch('subscription.started', array(
			'order_item_id'            => $order_item->key,
			'user_id'                  => $user_id,
			'provider'                 => $source,
			'provider_subscription_id' => $reference,
		));

		return self::claimSummary($order_item);
	}

	/**
	 * Mark a store item active with a fresh period end, remapping the product
	 * if the store reports a plan change.
	 */
	private static function renewStoreItem($order_item, array $transaction) {
		$order_item->set('odi_subscription_status', 'active');
		if (!empty($transaction['expiresDate'])) {
			$order_item->set('odi_subscription_period_end', gmdate('Y-m-d H:i:s', (int)($transaction['expiresDate'] / 1000)));
		}
		// A resubscribe after cancellation clears the cancelled marker.
		if ($order_item->get('odi_subscription_cancelled_time')) {
			$order_item->set('odi_subscription_cancelled_time', NULL);
		}
		$order_item->save();

		if (!empty($transaction['productId'])) {
			$mapping = MobileStoreProduct::GetByStoreProductId(MobileStoreProduct::STORE_APP_STORE, $transaction['productId']);
			if ($mapping !== NULL) {
				self::applyProductMapping($order_item, $mapping);
			}
		}

		// Re-grant in case the tier was revoked while lapsed (upgrade-only,
		// so a no-op when the user already holds the tier).
		$user = new User($order_item->get('odi_usr_user_id'), TRUE);
		if ($user->key) {
			TierBilling::handleProductPurchase($user, self::orderItemProduct($order_item), $order_item, $order_item->get_order());
		}
	}

	/**
	 * Point an order item at the mapping's product/version (plan changes) and
	 * grant the new tier when it differs.
	 */
	private static function applyProductMapping($order_item, MobileStoreProduct $mapping) {
		$changed = false;
		if ((int)$order_item->get('odi_pro_product_id') !== (int)$mapping->get('msp_pro_product_id')) {
			$order_item->set('odi_pro_product_id', $mapping->get('msp_pro_product_id'));
			$order_item->set('odi_prv_product_version_id', $mapping->get('msp_prv_product_version_id') ?: NULL);
			$changed = true;
		}
		if ($changed) {
			$order_item->save();
			$user = new User($order_item->get('odi_usr_user_id'), TRUE);
			$product = new Product($mapping->get('msp_pro_product_id'), TRUE);
			if ($user->key && $product->key) {
				TierBilling::handleProductPurchase($user, $product, $order_item, $order_item->get_order());
			}
		}
	}

	/**
	 * Cancel a store item and revoke the tier — the shared expiry/refund
	 * path, mirroring the Stripe webhook's subscription.deleted handling.
	 */
	private static function expireStoreItem($order_item, $provider, $provider_reference) {
		$order_item->set('odi_subscription_status', 'expired');
		if (!$order_item->get('odi_subscription_cancelled_time')) {
			$order_item->set('odi_subscription_cancelled_time', gmdate('Y-m-d H:i:s'));
		}
		$order_item->save();

		self::dispatchSignal('subscription.cancelled', $order_item, $provider, $provider_reference);

		$user_id = $order_item->get('odi_usr_user_id');
		if ($user_id) {
			TierBilling::handleSubscriptionExpired($user_id);
		}
	}

	private static function dispatchSignal($signal, $order_item, $provider, $provider_reference) {
		require_once(PathHelper::getIncludePath('includes/SignalBus.php'));
		SignalBus::dispatch($signal, array(
			'order_item_id'            => $order_item->key,
			'user_id'                  => $order_item->get('odi_usr_user_id'),
			'provider'                 => $provider,
			'provider_subscription_id' => $provider_reference,
		));
	}

	private static function findOrderItem($column_option, $value) {
		$items = new MultiOrderItem(array($column_option => $value), array('order_item_id' => 'DESC'));
		$items->load();
		foreach ($items as $item) {
			return $item;
		}
		return null;
	}

	private static function orderItemProduct($order_item) {
		return new Product($order_item->get('odi_pro_product_id'), TRUE);
	}

	private static function claimSummary($order_item) {
		$product = self::orderItemProduct($order_item);
		$tier = null;
		if ($product->key && $product->get('pro_sbt_subscription_tier_id')) {
			require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
			$tier_obj = new SubscriptionTier($product->get('pro_sbt_subscription_tier_id'), TRUE);
			if ($tier_obj->key) {
				$tier = array(
					'tier_id' => (int)$tier_obj->key,
					'name'    => $tier_obj->get('sbt_display_name') ?: $tier_obj->get('sbt_name'),
					'level'   => (int)$tier_obj->get('sbt_tier_level'),
				);
			}
		}
		return array(
			'order_item_id'  => (int)$order_item->key,
			'product_name'   => $product->key ? $product->get('pro_name') : '',
			'tier'           => $tier,
			'status'         => $order_item->get('odi_subscription_status'),
			'period_end'     => $order_item->get('odi_subscription_period_end'),
			'payment_source' => $order_item->get_payment_source(),
		);
	}
}

?>
