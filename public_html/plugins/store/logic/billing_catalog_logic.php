<?php
/**
 * API action: store/billing_catalog — the mobile billing catalog for one
 * store.
 *
 * POST /api/v1/action/store/billing_catalog (session key). The app asks for
 * its store's purchasable plans: each active store-product mapping with its
 * product and tier, plus the caller's active subscription source (so the app
 * shows the existing source instead of a purchase button — source
 * exclusivity) and the account token the kit attaches to purchases
 * (appAccountToken on iOS, obfuscatedAccountId on Android) for webhook-side
 * user linkage.
 *
 * @version 1.0.0
 */

function billing_catalog_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/mobile_store_products_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/includes/TierBilling.php'));
	require_once(PathHelper::getIncludePath('plugins/store/includes/MobileBilling.php'));
	require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('products_active') || !$settings->get_setting('subscriptions_active')) {
		return LogicResult::error('This feature is turned off');
	}

	$store = $input['store'] ?? '';
	if (!in_array($store, array(MobileStoreProduct::STORE_APP_STORE, MobileStoreProduct::STORE_PLAY_STORE))) {
		return LogicResult::error('store must be app_store or play_store');
	}

	$user_id = $session->get_user_id();

	$mappings = new MultiMobileStoreProduct(
		array('store' => $store, 'active' => true, 'deleted' => false),
		array('mobile_store_product_id' => 'ASC')
	);
	$mappings->load();

	$products_out = array();
	foreach ($mappings as $mapping) {
		$product = new Product($mapping->get('msp_pro_product_id'), TRUE);
		if (!$product->key || !$product->get('pro_is_active')) {
			continue;
		}

		$tier = null;
		if ($product->get('pro_sbt_subscription_tier_id')) {
			$tier_obj = new SubscriptionTier($product->get('pro_sbt_subscription_tier_id'), TRUE);
			if ($tier_obj->key) {
				$tier = array(
					'tier_id' => (int)$tier_obj->key,
					'name'    => $tier_obj->get('sbt_display_name') ?: $tier_obj->get('sbt_name'),
					'level'   => (int)$tier_obj->get('sbt_tier_level'),
				);
			}
		}

		$period = '';
		if ($mapping->get('msp_prv_product_version_id')) {
			$version = new ProductVersion($mapping->get('msp_prv_product_version_id'), TRUE);
			if ($version->key) {
				$period = $version->is_subscription() ?: '';
			}
		}

		$products_out[] = array(
			'store_product_id' => $mapping->get('msp_store_product_id'),
			'product_name'     => $product->get('pro_name'),
			'period'           => $period,
			'tier'             => $tier,
		);
	}

	$active_source = TierBilling::getActiveSubscriptionSource($user_id);

	return LogicResult::render(array(
		'store'             => $store,
		'products'          => $products_out,
		'active_source'     => $active_source,
		'can_purchase'      => ($active_source === null || $active_source === $store),
		'app_account_token' => MobileBilling::appAccountTokenForUser($user_id),
	));
}

function billing_catalog_logic_descriptor(): array {
	return [
		'description'      => 'Purchasable mobile subscription plans for one store, with the caller\'s active billing source.',
		'requires_session' => true,
		'mutates'          => false,
		'input'            => [
			'store' => ['type' => 'string', 'required' => true, 'enum' => ['app_store', 'play_store'], 'label' => 'Store'],
		],
	];
}

?>
