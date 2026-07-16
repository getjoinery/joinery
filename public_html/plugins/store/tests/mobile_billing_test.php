<?php
/** @joinery-test
 * name: mobile_billing
 * tier: db
 * env: dev-only
 * needs: []
 */

/**
 * Mobile app billing: JWS/OIDC signature verification, claim flow, tier
 * grant/revoke on purchase/renewal/refund events, and source exclusivity.
 *
 * Crypto sections build their own certificate chains and keys, pointing the
 * verifiers at test roots via the helpers' override seams — no Apple/Google
 * traffic. Billing sections drive MobileBilling with verified-payload arrays,
 * the exact shape the webhooks/claim actions hand it.
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/store/includes/AppStoreHelper.php'));
require_once(PathHelper::getIncludePath('plugins/store/includes/GooglePlayHelper.php'));
require_once(PathHelper::getIncludePath('plugins/store/includes/MobileBilling.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/mobile_store_products_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/order_items_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/orders_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));

// ---------------------------------------------------------------------------
// Local crypto fixtures
// ---------------------------------------------------------------------------

function mbt_make_ec_chain() {
	$config = array('digest_alg' => 'sha256', 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1');

	$root_key = openssl_pkey_new($config);
	$root_csr = openssl_csr_new(array('CN' => 'MBT Test Root'), $root_key, $config);
	$root_cert = openssl_csr_sign($root_csr, null, $root_key, 30, $config, 1);

	$leaf_key = openssl_pkey_new($config);
	$leaf_csr = openssl_csr_new(array('CN' => 'MBT Test Leaf'), $leaf_key, $config);
	$leaf_cert = openssl_csr_sign($leaf_csr, $root_cert, $root_key, 30, $config, 2);

	openssl_x509_export($root_cert, $root_pem);
	openssl_x509_export($leaf_cert, $leaf_pem);

	return array(
		'root_pem'  => $root_pem,
		'leaf_key'  => $leaf_key,
		'x5c'       => array(mbt_pem_to_b64der($leaf_pem), mbt_pem_to_b64der($root_pem)),
	);
}

function mbt_pem_to_b64der($pem) {
	return preg_replace('/-----[^-]+-----|\s+/', '', $pem);
}

function mbt_sign_jws(array $payload, $chain) {
	$header = array('alg' => 'ES256', 'x5c' => $chain['x5c']);
	$input = AppStoreHelper::b64url_encode(json_encode($header)) . '.' . AppStoreHelper::b64url_encode(json_encode($payload));
	openssl_sign($input, $der_sig, $chain['leaf_key'], OPENSSL_ALGO_SHA256);
	return $input . '.' . AppStoreHelper::b64url_encode(AppStoreHelper::es256DerToRaw($der_sig));
}

// ---------------------------------------------------------------------------
section('App Store JWS verification');
// ---------------------------------------------------------------------------

$chain = mbt_make_ec_chain();
AppStoreHelper::$root_ca_pem_override = $chain['root_pem'];
harness_defer(function () { AppStoreHelper::$root_ca_pem_override = null; });

$payload = array('notificationType' => 'TEST', 'notificationUUID' => 'mbt-uuid-1');
$jws = mbt_sign_jws($payload, $chain);

$decoded = AppStoreHelper::verifySignedPayload($jws);
check($decoded['notificationUUID'] === 'mbt-uuid-1', 'valid JWS verifies and decodes');

$tampered = explode('.', $jws);
$tampered[1] = AppStoreHelper::b64url_encode(json_encode(array('notificationType' => 'EVIL')));
$threw = false;
try { AppStoreHelper::verifySignedPayload(implode('.', $tampered)); } catch (AppStoreHelperException $e) { $threw = true; }
check($threw, 'tampered payload is rejected');

$other_chain = mbt_make_ec_chain(); // different root — not the pinned one
$foreign_jws = mbt_sign_jws($payload, $other_chain);
$threw = false;
try { AppStoreHelper::verifySignedPayload($foreign_jws); } catch (AppStoreHelperException $e) { $threw = true; }
check($threw, 'JWS anchored at an untrusted root is rejected');

$threw = false;
try { AppStoreHelper::verifySignedPayload('not-a-jws'); } catch (AppStoreHelperException $e) { $threw = true; }
check($threw, 'malformed JWS is rejected');

// ---------------------------------------------------------------------------
section('Play RTDN bearer verification');
// ---------------------------------------------------------------------------

$rsa_key = openssl_pkey_new(array('digest_alg' => 'sha256', 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048));
$rsa_details = openssl_pkey_get_details($rsa_key);
GooglePlayHelper::$jwks_override = array('keys' => array(array(
	'kty' => 'RSA', 'alg' => 'RS256', 'kid' => 'mbt-kid',
	'n' => GooglePlayHelper::b64url_encode($rsa_details['rsa']['n']),
	'e' => GooglePlayHelper::b64url_encode($rsa_details['rsa']['e']),
)));
harness_defer(function () { GooglePlayHelper::$jwks_override = null; });

harness_set_setting_mem('store_play_rtdn_audience', 'https://example.test/ajax/play_rtdn_webhook');

function mbt_sign_oidc(array $claims, $key) {
	$header = array('alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'mbt-kid');
	$input = GooglePlayHelper::b64url_encode(json_encode($header)) . '.' . GooglePlayHelper::b64url_encode(json_encode($claims));
	openssl_sign($input, $sig, $key, OPENSSL_ALGO_SHA256);
	return $input . '.' . GooglePlayHelper::b64url_encode($sig);
}

$good_claims = array(
	'iss' => 'https://accounts.google.com',
	'aud' => 'https://example.test/ajax/play_rtdn_webhook',
	'exp' => time() + 300,
	'email' => 'pubsub@mbt.iam.gserviceaccount.com',
);
$verified = GooglePlayHelper::verifyRtdnBearer(mbt_sign_oidc($good_claims, $rsa_key));
check($verified['email'] === 'pubsub@mbt.iam.gserviceaccount.com', 'valid OIDC bearer verifies');

$threw = false;
try { GooglePlayHelper::verifyRtdnBearer(mbt_sign_oidc(array_merge($good_claims, array('aud' => 'https://other/aud')), $rsa_key)); }
catch (GooglePlayHelperException $e) { $threw = true; }
check($threw, 'audience mismatch is rejected');

$threw = false;
try { GooglePlayHelper::verifyRtdnBearer(mbt_sign_oidc(array_merge($good_claims, array('exp' => time() - 10)), $rsa_key)); }
catch (GooglePlayHelperException $e) { $threw = true; }
check($threw, 'expired bearer is rejected');

$forged = mbt_sign_oidc($good_claims, $rsa_key);
$parts = explode('.', $forged);
$parts[1] = GooglePlayHelper::b64url_encode(json_encode(array_merge($good_claims, array('email' => 'evil@evil'))));
$threw = false;
try { GooglePlayHelper::verifyRtdnBearer(implode('.', $parts)); } catch (GooglePlayHelperException $e) { $threw = true; }
check($threw, 'forged bearer payload is rejected');

// ---------------------------------------------------------------------------
section('Fixtures');
// ---------------------------------------------------------------------------

$tier = new SubscriptionTier(NULL);
$tier->set('sbt_name', 'mbt_tier_' . time());
$tier->set('sbt_display_name', 'MBT Premium');
$tier->set('sbt_tier_level', 9100);
$tier->set('sbt_is_active', true);
$tier->save();
$tier->load();
harness_register_row('sbt_subscription_tiers', 'sbt_subscription_tier_id', $tier->key);
if ($tier->get('sbt_grp_group_id')) {
	harness_register_row('grp_groups', 'grp_group_id', $tier->get('sbt_grp_group_id'));
}
check($tier->key > 0, 'tier fixture created');

$product = new Product(NULL);
$product->set('pro_name', 'MBT Premium Plan');
$product->set('pro_link', 'mbt-premium-plan-' . time());
$product->set('pro_price', '9.99');
$product->set('pro_is_active', true);
$product->set('pro_sbt_subscription_tier_id', $tier->key);
$product->save();
$product->load();
harness_register_row('pro_products', 'pro_product_id', $product->key);
check($product->key > 0, 'product fixture created');

$apple_mapping = new MobileStoreProduct(NULL);
$apple_mapping->set('msp_store', 'app_store');
$apple_mapping->set('msp_store_product_id', 'test.mbt.premium.monthly');
$apple_mapping->set('msp_pro_product_id', $product->key);
$apple_mapping->set('msp_is_active', true);
$apple_mapping->save();
$apple_mapping->load();
harness_register_row('msp_mobile_store_products', 'msp_mobile_store_product_id', $apple_mapping->key);

$play_mapping = new MobileStoreProduct(NULL);
$play_mapping->set('msp_store', 'play_store');
$play_mapping->set('msp_store_product_id', 'mbt_premium_monthly');
$play_mapping->set('msp_pro_product_id', $product->key);
$play_mapping->set('msp_is_active', true);
$play_mapping->save();
$play_mapping->load();
harness_register_row('msp_mobile_store_products', 'msp_mobile_store_product_id', $play_mapping->key);

$found = MobileStoreProduct::GetByStoreProductId('app_store', 'test.mbt.premium.monthly');
check($found !== NULL && (int)$found->key === (int)$apple_mapping->key, 'mapping lookup by store product ID works');

$user1 = make_user('MbtApple');
$user2 = make_user('MbtToken');
$user3 = make_user('MbtPlay');
harness_defer(function () use ($user1, $user2, $user3) {
	SubscriptionTier::removeUserFromAllTiers($user1->key);
	SubscriptionTier::removeUserFromAllTiers($user2->key);
	SubscriptionTier::removeUserFromAllTiers($user3->key);
});

harness_set_setting_mem('store_app_store_bundle_ids', 'com.test.mbt');
harness_set_setting_mem('store_play_package_names', 'com.test.mbt.android');

$mbt_register_order_rows = function ($order_item_id) {
	$item = new OrderItem($order_item_id, TRUE);
	harness_register_row('odi_order_items', 'odi_order_item_id', $item->key);
	harness_register_row('ord_orders', 'ord_order_id', $item->get('odi_ord_order_id'));
	return $item;
};

// ---------------------------------------------------------------------------
section('App Store claim');
// ---------------------------------------------------------------------------

$apple_txn = array(
	'bundleId'              => 'com.test.mbt',
	'productId'             => 'test.mbt.premium.monthly',
	'originalTransactionId' => 'mbt-original-1',
	'transactionId'         => 'mbt-txn-1',
	'environment'           => 'Production',
	'type'                  => 'Auto-Renewable Subscription',
	'expiresDate'           => (time() + 30 * 86400) * 1000,
	'purchaseDate'          => time() * 1000,
	'price'                 => 9990, // milliunits
	'currency'              => 'USD',
	'appAccountToken'       => MobileBilling::appAccountTokenForUser($user1->key),
);

$summary = MobileBilling::claimAppStoreTransaction($user1->key, $apple_txn);
$apple_item = $mbt_register_order_rows($summary['order_item_id']);

check($summary['payment_source'] === 'app_store', 'claim records app_store payment source');
check($summary['tier'] !== null && (int)$summary['tier']['tier_id'] === (int)$tier->key, 'claim reports the mapped tier');
$user1_tier = SubscriptionTier::GetUserTier($user1->key);
check($user1_tier !== null && (int)$user1_tier->key === (int)$tier->key, 'tier granted through TierBilling on claim');
check($apple_item->get('odi_app_store_original_transaction_id') === 'mbt-original-1', 'order item stores the original transaction ID');
check((float)$apple_item->get('odi_price') === 9.99, 'store-reported price recorded (milliunits converted)');

$again = MobileBilling::claimAppStoreTransaction($user1->key, $apple_txn);
check((int)$again['order_item_id'] === (int)$summary['order_item_id'], 're-claim is idempotent (same order item)');

$threw = false;
try { MobileBilling::claimAppStoreTransaction($user3->key, $apple_txn); } catch (MobileBillingException $e) { $threw = true; }
check($threw, 'claiming another user\'s transaction is rejected');

$threw = false;
try {
	MobileBilling::claimAppStoreTransaction($user1->key, array_merge($apple_txn, array('originalTransactionId' => 'mbt-x', 'bundleId' => 'com.evil.app')));
} catch (MobileBillingException $e) { $threw = true; }
check($threw, 'unknown bundle ID is rejected');

$threw = false;
try {
	MobileBilling::claimAppStoreTransaction($user1->key, array_merge($apple_txn, array('originalTransactionId' => 'mbt-y', 'expiresDate' => (time() - 60) * 1000)));
} catch (MobileBillingException $e) { $threw = true; }
check($threw, 'expired transaction cannot be claimed');

harness_set_setting_mem('debug', '0');
$threw = false;
try {
	MobileBilling::claimAppStoreTransaction($user1->key, array_merge($apple_txn, array('environment' => 'Sandbox')));
} catch (MobileBillingException $e) { $threw = true; }
check($threw, 'sandbox purchase rejected when debug is off');
harness_set_setting_mem('debug', '1');

// ---------------------------------------------------------------------------
section('Source exclusivity');
// ---------------------------------------------------------------------------

check(TierBilling::getActiveSubscriptionSource($user1->key) === 'app_store', 'active source reads app_store');
check(TierBilling::sourceConflict($user1->key, 'stripe') === 'app_store', 'stripe purchase conflicts with active app_store source');
check(TierBilling::sourceConflict($user1->key, 'app_store') === null, 'same-source purchase does not conflict');

$play_purchase_u1 = array(
	'subscriptionState'    => 'SUBSCRIPTION_STATE_ACTIVE',
	'acknowledgementState' => 'ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED',
	'lineItems'            => array(array('productId' => 'mbt_premium_monthly', 'expiryTime' => gmdate('Y-m-d\TH:i:s\Z', time() + 30 * 86400))),
);
$threw = false; $conflict_message = '';
try { MobileBilling::claimPlayPurchase($user1->key, 'com.test.mbt.android', 'mbt-token-conflict', $play_purchase_u1); }
catch (MobileBillingException $e) { $threw = true; $conflict_message = $e->getMessage(); }
check($threw && strpos($conflict_message, 'App Store') !== false, 'cross-store claim is blocked by exclusivity', $conflict_message);

// ---------------------------------------------------------------------------
section('App Store webhook events');
// ---------------------------------------------------------------------------

$renew_txn = array_merge($apple_txn, array('expiresDate' => (time() + 60 * 86400) * 1000));
$result = MobileBilling::applyAppStoreEvent('DID_RENEW', null, $renew_txn, null);
$apple_item->load();
check($result['processed'] && $apple_item->get('odi_subscription_period_end') > gmdate('Y-m-d H:i:s', time() + 55 * 86400), 'DID_RENEW extends the period end');

$result = MobileBilling::applyAppStoreEvent('DID_CHANGE_RENEWAL_STATUS', 'AUTO_RENEW_DISABLED', $apple_txn, null);
$apple_item->load();
check($result['processed'] && $apple_item->get('odi_subscription_cancel_at_period_end'), 'auto-renew off sets cancel-at-period-end');

$result = MobileBilling::applyAppStoreEvent('DID_FAIL_TO_RENEW', 'GRACE_PERIOD', $apple_txn, null);
$apple_item->load();
check($result['processed'] && $apple_item->get('odi_subscription_status') === 'grace_period', 'billing failure enters grace period');
check($apple_item->check_subscription_status(), 'grace period keeps the subscription entitled');

$result = MobileBilling::applyAppStoreEvent('EXPIRED', 'VOLUNTARY', $apple_txn, null);
$apple_item->load();
check($result['processed'] && $apple_item->get('odi_subscription_cancelled_time') !== null, 'EXPIRED cancels the order item');
check(SubscriptionTier::GetUserTier($user1->key) === null, 'EXPIRED revokes the tier via handleSubscriptionExpired');

// Token linkage: a notification for a never-claimed transaction reaches the
// user through the appAccountToken and creates the subscription.
check(MobileBilling::userIdFromAppAccountToken(MobileBilling::appAccountTokenForUser($user2->key)) === (int)$user2->key, 'app account token round-trips the user id');

$token_txn = array_merge($apple_txn, array(
	'originalTransactionId' => 'mbt-original-2',
	'appAccountToken'       => MobileBilling::appAccountTokenForUser($user2->key),
	'expiresDate'           => (time() + 30 * 86400) * 1000,
));
$result = MobileBilling::applyAppStoreEvent('SUBSCRIBED', 'INITIAL_BUY', $token_txn, null);
check($result['processed'], 'SUBSCRIBED with no prior claim creates the subscription via token linkage');
$user2_tier = SubscriptionTier::GetUserTier($user2->key);
check($user2_tier !== null && (int)$user2_tier->key === (int)$tier->key, 'token-linked subscription granted the tier');
$user2_items = new MultiOrderItem(array('odi_app_store_original_transaction_id' => 'mbt-original-2'));
$user2_items->load();
foreach ($user2_items as $item) { $mbt_register_order_rows($item->key); }

// Refund revokes
$result = MobileBilling::applyAppStoreEvent('REFUND', null, array_merge($token_txn, array('revocationDate' => time() * 1000)), null);
check($result['processed'], 'REFUND event processes');
check(SubscriptionTier::GetUserTier($user2->key) === null, 'refund revokes tier benefits (net-new refund-driven revocation)');
$user2_items = new MultiOrderItem(array('odi_app_store_original_transaction_id' => 'mbt-original-2'));
$user2_items->load();
foreach ($user2_items as $item) {
	check($item->get('odi_refund_time') !== null, 'refund timestamp recorded on the order item');
}

// ---------------------------------------------------------------------------
section('Google Play claim and events');
// ---------------------------------------------------------------------------

GooglePlayHelper::$api_response_override = array('ok' => true); // short-circuits acknowledge
harness_defer(function () { GooglePlayHelper::$api_response_override = null; });

$play_purchase = array(
	'subscriptionState'          => 'SUBSCRIPTION_STATE_ACTIVE',
	'acknowledgementState'       => 'ACKNOWLEDGEMENT_STATE_PENDING',
	'lineItems'                  => array(array('productId' => 'mbt_premium_monthly', 'expiryTime' => gmdate('Y-m-d\TH:i:s\Z', time() + 30 * 86400))),
	'externalAccountIdentifiers' => array('obfuscatedExternalAccountId' => MobileBilling::appAccountTokenForUser($user3->key)),
);

$summary = MobileBilling::claimPlayPurchase($user3->key, 'com.test.mbt.android', 'mbt-play-token-1', $play_purchase);
$play_item = $mbt_register_order_rows($summary['order_item_id']);

check($summary['payment_source'] === 'play_store', 'Play claim records play_store payment source');
$user3_tier = SubscriptionTier::GetUserTier($user3->key);
check($user3_tier !== null && (int)$user3_tier->key === (int)$tier->key, 'Play claim grants the tier');
check($play_item->get('odi_play_purchase_token') === 'mbt-play-token-1', 'order item stores the purchase token');

$threw = false;
try { MobileBilling::claimPlayPurchase($user3->key, 'com.evil.android', 'mbt-play-x', $play_purchase); }
catch (MobileBillingException $e) { $threw = true; }
check($threw, 'unknown package name is rejected');

harness_set_setting_mem('debug', '0');
$threw = false;
try { MobileBilling::claimPlayPurchase($user3->key, 'com.test.mbt.android', 'mbt-play-y', array_merge($play_purchase, array('testPurchase' => new stdClass()))); }
catch (MobileBillingException $e) { $threw = true; }
check($threw, 'license-tester purchase rejected when debug is off');
harness_set_setting_mem('debug', '1');

// Cancellation (auto-renew off, still entitled)
$cancelled_purchase = array_merge($play_purchase, array('subscriptionState' => 'SUBSCRIPTION_STATE_CANCELED', 'acknowledgementState' => 'ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED'));
$result = MobileBilling::applyPlayEvent(3, 'com.test.mbt.android', 'mbt-play-token-1', $cancelled_purchase);
$play_item->load();
check($result['processed'] && $play_item->get('odi_subscription_cancel_at_period_end'), 'RTDN CANCELED sets cancel-at-period-end, still entitled');
check($play_item->check_subscription_status(), 'cancelled-but-not-expired Play subscription stays entitled');

// Expiry revokes
$expired_purchase = array_merge($play_purchase, array('subscriptionState' => 'SUBSCRIPTION_STATE_EXPIRED', 'acknowledgementState' => 'ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED'));
$result = MobileBilling::applyPlayEvent(13, 'com.test.mbt.android', 'mbt-play-token-1', $expired_purchase);
$play_item->load();
check($result['processed'] && $play_item->get('odi_subscription_cancelled_time') !== null, 'RTDN EXPIRED cancels the order item');
check(SubscriptionTier::GetUserTier($user3->key) === null, 'RTDN EXPIRED revokes the tier');

// ---------------------------------------------------------------------------
section('Payment source model');
// ---------------------------------------------------------------------------

$derived = new OrderItem(NULL);
$derived->set('odi_stripe_subscription_id', 'sub_123');
check($derived->get_payment_source() === 'stripe', 'legacy rows derive stripe from the provider column');
$derived2 = new OrderItem(NULL);
check($derived2->get_payment_source() === 'none', 'rows with no provider read none');
$apple_item->load();
check($apple_item->get_payment_source() === 'app_store', 'stored payment source wins');

harness_finish();
?>
