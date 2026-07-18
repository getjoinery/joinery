<?php
/** @joinery-test
 * name: subscription_tiers
 * tier: live
 * env: dev-only
 * needs: [stripe-test-keys]
 * timeout: 600
 */

/**
 * Subscription tiers: model layer, change_tier_logic, and the real Stripe
 * test-mode subscription lifecycle.
 *
 * Three seams, cheapest first, so a failure localizes:
 *
 *   1. Model layer (no Stripe): tier assignment, the upgrade-only purchase
 *      rule, feature/display resolution, minimum-tier checks, change tracking.
 *   2. Price sync (one Stripe call): a product version with no stored price id
 *      gets one created and persisted.
 *   3. Lifecycle (real Stripe subscriptions): upgrade with proration, then
 *      downgrade and cancellation under BOTH timing settings, then reactivation.
 *
 * Every subscription is created against Stripe test mode with tok_visa and torn
 * down at finish — customers are deleted, not merely unsubscribed, so repeated
 * runs do not accumulate test-mode customers.
 *
 * Timing coverage note: change_tier_logic branches on subscription_downgrade_timing
 * and subscription_cancellation_timing. Both values of both settings are exercised
 * here. The end_of_period branches assert the entitlement SURVIVES the request
 * (the change is scheduled, not applied) — the complementary half of the
 * immediate branches, and the half most likely to regress into instant
 * revocation.
 *
 * Run: php plugins/store/tests/subscription_tiers_test.php
 *
 * @version 1.0.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode(); // all fixtures below land in the copied test database
set_time_limit(600);

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/settings_class.php'));
require_once(PathHelper::getIncludePath('data/change_tracking_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/includes/StripeHelper.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/product_versions_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/orders_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/order_items_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/logic/change_tier_logic.php'));

$settings = Globalvars::get_instance();

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Persist a setting to the (test) database and drop the Globalvars cache so the
 * next get_setting() re-reads it. change_tier_logic reads settings through the
 * singleton, so an in-memory-only override would not survive its internal reads.
 */
function st_apply_settings(array $values) {
	foreach ($values as $name => $value) {
		$setting = Setting::GetByColumn('stg_name', $name);
		if ($setting) {
			$setting->set('stg_value', $value);
			$setting->save();
		}
	}
	$ref = new ReflectionClass('Globalvars');
	$map = $ref->getProperty('instance_map');
	$map->setAccessible(true);
	$map->setValue(null, array());
}

/** Snapshot the named settings and defer their restoration (crash-safe, LIFO). */
function st_snapshot_settings(array $names) {
	$original = array();
	foreach ($names as $name) {
		$setting = Setting::GetByColumn('stg_name', $name);
		$original[$name] = $setting ? $setting->get('stg_value') : null;
	}
	harness_defer(function () use ($original) {
		foreach ($original as $name => $value) {
			if ($value === null) continue;
			$setting = Setting::GetByColumn('stg_name', $name);
			if ($setting) {
				$setting->set('stg_value', $value);
				$setting->save();
			}
		}
	});
	return $original;
}

/** Make change_tier_logic see this user as the logged-in caller. */
function st_act_as($user_id) {
	$_SESSION['usr_user_id'] = $user_id;
	$_SESSION['loggedin'] = true;
	SessionControl::get_instance();
}

/** The tier level currently entitled to $user_id, or null. */
function st_current_level($user_id) {
	$tier = SubscriptionTier::GetUserTier($user_id);
	return $tier ? (int)$tier->get('sbt_tier_level') : null;
}

/**
 * Create a real Stripe test-mode subscription for $user_id on $product_id and
 * the matching local Order/OrderItem, and entitle the user to the product tier.
 * Returns the subscription detail array, or null with the reason in $error.
 */
function st_create_subscription($user_id, $product_id, &$error = null) {
	$product = new Product($product_id, TRUE);
	$versions = $product->get_product_versions();
	$version = $versions->count() > 0 ? $versions->get(0) : null;
	if (!$version) { $error = "product $product_id has no version"; return null; }

	try {
		$helper = new StripeHelper();
		$client = $helper->get_stripe_client();
		$user = new User($user_id, TRUE);

		$customer_id = $helper->read_customer_id($user);
		if (!$customer_id) {
			$customer = $client->customers->create(array(
				'email'       => $user->get('usr_email'),
				'name'        => trim($user->get('usr_first_name') . ' ' . $user->get('usr_last_name')),
				'description' => 'subscription_tiers_test fixture',
			));
			$helper->write_customer_id($user, $customer->id);
			$customer_id = $customer->id;
		}

		$price = $helper->get_or_create_price($version);

		// tok_visa is Stripe's always-succeeds test card token.
		$payment_method = $client->paymentMethods->create(array(
			'type' => 'card',
			'card' => array('token' => 'tok_visa'),
		));
		$client->paymentMethods->attach($payment_method->id, array('customer' => $customer_id));
		$client->customers->update($customer_id, array(
			'invoice_settings' => array('default_payment_method' => $payment_method->id),
		));

		$subscription = $client->subscriptions->create(array(
			'customer'               => $customer_id,
			'items'                  => array(array('price' => $price->id)),
			'default_payment_method' => $payment_method->id,
			'expand'                 => array('latest_invoice.payment_intent'),
		));

		$order = new Order(NULL);
		$order->set('ord_usr_user_id', $user_id);
		$order->set('ord_status', Order::STATUS_PAID);
		$order->set('ord_payment_method', 'stripe');
		$order->set('ord_total_cost', $version->get('prv_version_price'));
		$order->save();
		harness_register_row('ord_orders', 'ord_order_id', $order->key);

		$order_item = new OrderItem(NULL);
		$order_item->set('odi_ord_order_id', $order->key);
		$order_item->set('odi_usr_user_id', $user_id);
		$order_item->set('odi_pro_product_id', $product->key);
		$order_item->set('odi_prv_product_version_id', $version->key);
		$order_item->set('odi_price', $version->get('prv_version_price'));
		$order_item->set('odi_status', OrderItem::STATUS_PAID); // required by the is_active_subscription filter
		$order_item->set('odi_is_subscription', true);
		$order_item->set('odi_stripe_subscription_id', $subscription->id);
		$order_item->set('odi_subscription_status', $subscription->status);
		$order_item->set('odi_subscription_period_end', gmdate('Y-m-d H:i:s', $subscription->current_period_end));
		$order_item->save();
		harness_register_row('odi_order_items', 'odi_order_item_id', $order_item->key);

		if ($product->get('pro_sbt_subscription_tier_id')) {
			$tier = new SubscriptionTier($product->get('pro_sbt_subscription_tier_id'), TRUE);
			$tier->addUser($user_id, 'purchase', 'order', $order->key);
		}

		return array(
			'subscription_id' => $subscription->id,
			'order_id'        => $order->key,
			'order_item_id'   => $order_item->key,
			'customer_id'     => $customer_id,
		);
	} catch (Exception $e) {
		$error = $e->getMessage();
		return null;
	}
}

/**
 * Return the user to a clean slate: cancel every Stripe subscription, drop the
 * Stripe customer, delete local order items, and strip all tier entitlements.
 * Each lifecycle section starts from this state so one section's residue cannot
 * make the next one pass (or fail) for the wrong reason.
 */
function st_reset_user($user_id) {
	try {
		$user = new User($user_id, TRUE);
		$helper = new StripeHelper();
		$customer_id = $helper->read_customer_id($user);
		if ($customer_id) {
			$client = $helper->get_stripe_client();
			$subs = $client->subscriptions->all(array('customer' => $customer_id, 'status' => 'all'));
			foreach ($subs->data as $sub) {
				if ($sub->status !== 'canceled') {
					try { $client->subscriptions->cancel($sub->id); } catch (Exception $e) { /* already gone */ }
				}
			}
			try { $client->customers->delete($customer_id); } catch (Exception $e) { /* already gone */ }
			$helper->write_customer_id($user, NULL);
		}
	} catch (Exception $e) {
		echo "  WARNING: Stripe reset for user $user_id failed: " . $e->getMessage() . "\n";
	}

	$items = new MultiOrderItem(array('user_id' => $user_id), array('order_item_id' => 'DESC'));
	$items->load();
	foreach ($items as $item) {
		try { $item->permanent_delete(); } catch (Exception $e) { /* teardown best-effort */ }
	}

	SubscriptionTier::removeUserFromAllTiers($user_id);
}

// ===========================================================================
section('Preconditions');
// ===========================================================================

// The Stripe sections make real test-mode calls. Without keys the SDK throws
// deep in the stack, so this is a loud check rather than a silent skip.
$has_stripe_keys = !empty($settings->get_setting('stripe_api_key_test'))
	&& !empty($settings->get_setting('stripe_api_pkey_test'));
check($has_stripe_keys, 'Stripe test keys configured',
	$has_stripe_keys ? '' : 'stripe_api_key_test / stripe_api_pkey_test are unset');

// change_tier_logic redirects to /profile when either flag is off, which would
// otherwise surface as a misleading "tier not updated" on every logic test.
$store_flags_on = (bool)$settings->get_setting('products_active')
	&& (bool)$settings->get_setting('subscriptions_active');
check($store_flags_on, 'store and subscriptions enabled',
	$store_flags_on ? '' : 'products_active / subscriptions_active must be on for change_tier_logic to act');

$multi_tiers = new MultiSubscriptionTier(
	array('sbt_is_active' => true, 'sbt_delete_time' => 'IS NULL'),
	array('sbt_tier_level' => 'ASC')
);
$multi_tiers->load();

$tiers = array();
foreach ($multi_tiers as $tier) {
	$level = (int)$tier->get('sbt_tier_level');
	if (isset($tiers[$level])) continue; // keep the first tier at each level
	$tiers[$level] = array(
		'id'           => $tier->key,
		'name'         => (string)$tier->get('sbt_name'),
		'display_name' => (string)$tier->get('sbt_display_name'),
		'level'        => $level,
	);
}
ksort($tiers);
check(count($tiers) >= 3, 'at least 3 active tiers', 'found ' . count($tiers));

// MultiProduct's recognized option keys are is_active / deleted — the raw column
// names pro_is_active / pro_delete_time are silently ignored, which would let an
// inactive or soft-deleted product be picked as a tier's product.
// A tier is only usable here if its product actually has a version to price and
// subscribe to. Taking the first active product regardless would let an
// unrelated half-built product (one created by a crashed run, say) decide the
// fixture set and fail the suite for a reason that has nothing to do with tiers.
$tier_products = array();
foreach ($tiers as $level => $tier) {
	$products = new MultiProduct(array(
		'pro_sbt_subscription_tier_id' => $tier['id'],
		'is_active'                    => true,
		'deleted'                      => false,
	));
	$products->load();
	foreach ($products as $candidate) {
		$versions = $candidate->get_product_versions();
		if ($versions->count() > 0) {
			$tier_products[$level] = $candidate->key;
			break;
		}
	}
}
check(count($tier_products) >= 3, 'at least 3 tiers have an active product',
	'found ' . count($tier_products) . ' of ' . count($tiers));

$levels = array_keys($tier_products);
sort($levels);

// Everything below depends on the fixtures above; stop here rather than emit a
// cascade of failures that all restate the same missing precondition.
if (!$has_stripe_keys || !$store_flags_on || count($tier_products) < 3) {
	harness_finish();
}

$low = $levels[0];
$mid = $levels[1];
$high = $levels[2];

// ===========================================================================
section('Fixtures');
// ===========================================================================

st_snapshot_settings(array(
	'subscription_downgrades_enabled',
	'subscription_downgrade_timing',
	'subscription_cancellation_enabled',
	'subscription_cancellation_timing',
	'subscription_reactivation_enabled',
	'subscription_cancellation_prorate',
));

$user = make_user('TierSub', 1); // registered for teardown by the harness
$user_id = $user->key;
check($user_id > 0, 'test user created', "user id $user_id");

// Runs before the user delete (LIFO), so Stripe state and order rows are gone
// before the row the orders reference is removed.
harness_defer(function () use ($user_id) { st_reset_user($user_id); });

// Every tier product needs a Stripe price before the lifecycle sections.
$price_errors = array();
foreach ($tier_products as $level => $product_id) {
	$product = new Product($product_id, TRUE);
	$versions = $product->get_product_versions();
	if ($versions->count() === 0) { $price_errors[] = "level $level has no product version"; continue; }
	$version = $versions->get(0);
	if (!$version->get('prv_stripe_price_id_test')) {
		try {
			$helper = new StripeHelper();
			$helper->get_or_create_price($version);
		} catch (Exception $e) {
			$price_errors[] = "level $level: " . $e->getMessage();
		}
	}
}
check(empty($price_errors), 'all tier products have a Stripe test price',
	implode('; ', $price_errors));

// ===========================================================================
section('Model layer: tier entitlement');
// ===========================================================================

// Assignment is verified through group membership rather than GetUserTier:
// GetUserTier strips the tier when there is no active subscription, so it would
// report "not assigned" for a manual grant that did in fact land.
$tier_obj = new SubscriptionTier($tiers[$low]['id'], TRUE);
$group_id = $tier_obj->get('sbt_grp_group_id');
$tier_obj->addUser($user_id, 'manual', 'test', null, 1);

// MultiGroupMember's option keys are group_id / foreign_key_id. The raw column
// names grm_grp_group_id / grm_foreign_key_id are silently ignored, which would
// make this an unfiltered count of every group member on the site — a count that
// is always greater than zero, so the assertion could never fail.
$membership = new MultiGroupMember(array(
	'group_id'       => $group_id,
	'foreign_key_id' => $user_id,
));
$membership->load();
check($membership->count() > 0, 'manual assignment adds the user to the tier group',
	'group ' . $group_id);

SubscriptionTier::removeUserFromAllTiers($user_id);

// A purchase may only move a user up. Buying a lower tier while holding a higher
// one must not demote them.
$high_tier = new SubscriptionTier($tiers[$high]['id'], TRUE);
$high_tier->addUser($user_id, 'manual', 'test', null, 1);
$low_tier = new SubscriptionTier($tiers[$low]['id'], TRUE);
$low_tier->addUser($user_id, 'purchase', 'test', null, 1);
$after_purchase = SubscriptionTier::GetUserTier($user_id);
check($after_purchase && $after_purchase->key == $high_tier->key,
	'purchasing a lower tier does not demote the user',
	'expected tier ' . $high_tier->key . ', got ' . ($after_purchase ? $after_purchase->key : 'none'));

SubscriptionTier::removeUserFromAllTiers($user_id);

// The tier display must reflect the assigned tier — an empty or unrelated
// display means entitlement resolution is broken even though assignment worked.
$tier_obj = new SubscriptionTier($tiers[$low]['id'], TRUE);
$tier_obj->addUser($user_id, 'manual', 'test', null, 1);
$display = (string)SubscriptionTier::getUserTierDisplay($user_id);
$reflects = ($tiers[$low]['name'] !== '' && stripos($display, $tiers[$low]['name']) !== false)
	|| ($tiers[$low]['display_name'] !== '' && stripos($display, $tiers[$low]['display_name']) !== false);
check($display !== '' && $reflects, 'tier display reflects the assigned tier',
	"display '$display' vs '" . $tiers[$low]['name'] . "' / '" . $tiers[$low]['display_name'] . "'");

SubscriptionTier::removeUserFromAllTiers($user_id);

// Minimum-tier checks are asserted in all three directions: a lone "has the low
// tier" assertion would also pass if the check returned true unconditionally.
$mid_tier = new SubscriptionTier($tiers[$mid]['id'], TRUE);
$mid_tier->addUser($user_id, 'manual', 'test', null, 1);
check(SubscriptionTier::UserHasMinimumTier($user_id, $low), 'mid tier satisfies a lower minimum');
check(SubscriptionTier::UserHasMinimumTier($user_id, $mid), 'mid tier satisfies its own minimum');
check(!SubscriptionTier::UserHasMinimumTier($user_id, $high), 'mid tier does not satisfy a higher minimum');

SubscriptionTier::removeUserFromAllTiers($user_id);

// Tier movement must leave an audit trail.
foreach ($levels as $level) {
	$t = new SubscriptionTier($tiers[$level]['id'], TRUE);
	$t->addUser($user_id, 'manual', 'test', null, 1);
}
$tier_changes = 0;
foreach (ChangeTracking::getUserHistory($user_id) as $change) {
	if ($change->get('cht_entity_type') === 'subscription_tier') $tier_changes++;
}
check($tier_changes > 0, 'tier changes are recorded in change tracking',
	"$tier_changes entries");

SubscriptionTier::removeUserFromAllTiers($user_id);

// ===========================================================================
section('Stripe: price id sync');
// ===========================================================================

// A version with no stored test price id must get one created AND persisted —
// the id is what every later subscription call is built on.
$sync_product = new Product($tier_products[$low], TRUE);
$sync_versions = $sync_product->get_product_versions();
$sync_version = $sync_versions->count() > 0 ? $sync_versions->get(0) : null;

if (!$sync_version) {
	check(false, 'tier product has a version to price', 'no product version');
} else {
	try {
		$helper = new StripeHelper();
		$stripe_price = $helper->get_or_create_price($sync_version);
		$reloaded = new ProductVersion($sync_version->key, TRUE);
		$stored = (string)$reloaded->get('prv_stripe_price_id_test');
		check($stored !== '' && $stored === $stripe_price->id,
			'Stripe price id is created and persisted on the version',
			"stored '$stored' vs API '" . $stripe_price->id . "'");
	} catch (Exception $e) {
		check(false, 'Stripe price id is created and persisted on the version', $e->getMessage());
	}
}

// ===========================================================================
section('Stripe: upgrade and proration');
// ===========================================================================

st_reset_user($user_id);
$sub_error = null;
$subscription = st_create_subscription($user_id, $tier_products[$low], $sub_error);
check($subscription !== null, 'subscription created at the low tier', (string)$sub_error);

if ($subscription) {
	st_act_as($user_id);

	$helper = new StripeHelper();
	$client = $helper->get_stripe_client();
	$before = $client->subscriptions->retrieve($subscription['subscription_id']);
	$amount_before = $before->items->data[0]->price->unit_amount;

	$result = change_tier_logic(array('action' => 'upgrade', 'product_id' => $tier_products[$mid]));
	check(empty($result->data['error_message']), 'upgrade request is accepted',
		(string)($result->data['error_message'] ?? ''));
	check(st_current_level($user_id) === (int)$mid, 'upgrade moves the user to the mid tier',
		'now level ' . var_export(st_current_level($user_id), true) . ", expected $mid");

	// Upgrading mid-cycle must not silently charge a full period: Stripe either
	// emits explicit proration line items or, failing that, the recurring amount
	// must at least have gone up.
	$after = $client->subscriptions->retrieve($subscription['subscription_id']);
	$amount_after = $after->items->data[0]->price->unit_amount;

	$has_proration = false;
	try {
		$invoice = $client->invoices->retrieve($after->latest_invoice);
		foreach ($invoice->lines->data as $line) {
			if (!empty($line->proration)) { $has_proration = true; break; }
		}
	} catch (Exception $e) {
		// Fall through to the price-increase assertion below.
	}
	check($has_proration || $amount_after > $amount_before,
		'mid-cycle upgrade prorates or raises the recurring amount',
		'proration=' . var_export($has_proration, true)
			. " amount $amount_before -> $amount_after");
}

// ===========================================================================
section('Stripe: downgrade (immediate)');
// ===========================================================================

st_reset_user($user_id);
$sub_error = null;
$subscription = st_create_subscription($user_id, $tier_products[$mid], $sub_error);
check($subscription !== null, 'subscription created at the mid tier', (string)$sub_error);

if ($subscription) {
	st_act_as($user_id);
	st_apply_settings(array(
		'subscription_downgrades_enabled' => '1',
		'subscription_downgrade_timing'   => 'immediate',
	));

	$result = change_tier_logic(array('action' => 'downgrade', 'product_id' => $tier_products[$low]));
	check(empty($result->data['error_message']), 'immediate downgrade request is accepted',
		(string)($result->data['error_message'] ?? ''));
	check(st_current_level($user_id) === (int)$low, 'immediate downgrade applies at once',
		'now level ' . var_export(st_current_level($user_id), true) . ", expected $low");
}

// ===========================================================================
section('Stripe: downgrade (end of period)');
// ===========================================================================

// The complementary branch: the request is accepted but the user keeps the
// higher entitlement until the period ends. A regression that applies the
// downgrade immediately would strip paid-for access early.
st_reset_user($user_id);
$sub_error = null;
$subscription = st_create_subscription($user_id, $tier_products[$mid], $sub_error);
check($subscription !== null, 'subscription created at the mid tier for scheduled downgrade',
	(string)$sub_error);

if ($subscription) {
	st_act_as($user_id);
	st_apply_settings(array(
		'subscription_downgrades_enabled' => '1',
		'subscription_downgrade_timing'   => 'end_of_period',
	));

	$result = change_tier_logic(array('action' => 'downgrade', 'product_id' => $tier_products[$low]));
	check(empty($result->data['error_message']), 'scheduled downgrade request is accepted',
		(string)($result->data['error_message'] ?? ''));
	check(st_current_level($user_id) === (int)$mid,
		'scheduled downgrade leaves the current tier in place until period end',
		'now level ' . var_export(st_current_level($user_id), true) . ", expected $mid");

	// "Nothing changed locally" is only correct if the change is genuinely
	// pending at Stripe — otherwise this section would also pass if the
	// downgrade had been silently dropped.
	$helper = new StripeHelper();
	$live = $helper->get_stripe_client()->subscriptions->retrieve($subscription['subscription_id']);
	check(!empty($live->schedule), 'the downgrade is pending as a Stripe subscription schedule',
		'schedule = ' . var_export($live->schedule, true));

	$low_price = null;
	$low_versions = (new Product($tier_products[$low], TRUE))->get_product_versions();
	if ($low_versions->count() > 0) {
		$low_price = $low_versions->get(0)->get('prv_stripe_price_id_test');
	}
	$scheduled_price = null;
	if (!empty($live->schedule)) {
		$schedule = $helper->get_stripe_client()->subscriptionSchedules->retrieve($live->schedule);
		$phases = $schedule->phases;
		$last = end($phases);
		$scheduled_price = ($last && !empty($last->items)) ? $last->items[0]->price : null;
	}
	check($scheduled_price !== null && $scheduled_price === $low_price,
		'the scheduled phase moves to the lower tier price',
		"scheduled '" . var_export($scheduled_price, true) . "' vs low-tier '" . var_export($low_price, true) . "'");
}

// ===========================================================================
section('Stripe: cancellation (immediate)');
// ===========================================================================

st_reset_user($user_id);
$sub_error = null;
$subscription = st_create_subscription($user_id, $tier_products[$low], $sub_error);
check($subscription !== null, 'subscription created for immediate cancellation', (string)$sub_error);

if ($subscription) {
	st_act_as($user_id);
	st_apply_settings(array(
		'subscription_cancellation_enabled' => '1',
		'subscription_cancellation_timing'  => 'immediate',
		'subscription_cancellation_prorate' => '0',
	));

	$result = change_tier_logic(array('action' => 'cancel'));
	check(empty($result->data['error_message']), 'immediate cancellation request is accepted',
		(string)($result->data['error_message'] ?? ''));
	check(st_current_level($user_id) === null, 'immediate cancellation revokes the tier at once',
		'still level ' . var_export(st_current_level($user_id), true));
}

// ===========================================================================
section('Stripe: cancellation (end of period)');
// ===========================================================================

st_reset_user($user_id);
$sub_error = null;
$subscription = st_create_subscription($user_id, $tier_products[$low], $sub_error);
check($subscription !== null, 'subscription created for scheduled cancellation', (string)$sub_error);

if ($subscription) {
	st_act_as($user_id);
	st_apply_settings(array(
		'subscription_cancellation_enabled' => '1',
		'subscription_cancellation_timing'  => 'end_of_period',
		'subscription_cancellation_prorate' => '0',
	));

	$result = change_tier_logic(array('action' => 'cancel'));
	check(empty($result->data['error_message']), 'scheduled cancellation request is accepted',
		(string)($result->data['error_message'] ?? ''));

	// Entitlement must survive: the user paid through the end of the period.
	check(st_current_level($user_id) === (int)$low,
		'scheduled cancellation keeps the tier until period end',
		'now level ' . var_export(st_current_level($user_id), true) . ", expected $low");

	// The subscription stays *current* (entitled) while no longer being "active,
	// never cancelled" — so it is the current-subscription filter that must
	// still find it.
	$current = new MultiOrderItem(
		array('user_id' => $user_id, 'is_current_subscription' => true),
		array('order_item_id' => 'DESC')
	);
	$current->load();
	check($current->count() > 0, 'a cancelled-at-period-end subscription is still current',
		$current->count() . ' current subscription(s)');

	$item = $current->count() > 0 ? $current->get(0) : null;
	check($item && $item->get('odi_subscription_cancel_at_period_end'),
		'the order item is flagged cancel-at-period-end');
}

// ===========================================================================
section('Stripe: reactivation');
// ===========================================================================

// Reactivation runs against the scheduled-cancellation state left above: the
// subscription is cancel-at-period-end and must return to a plain active state
// with the entitlement intact.
if ($subscription) {
	st_apply_settings(array('subscription_reactivation_enabled' => '1'));

	$result = change_tier_logic(array('action' => 'reactivate'));
	check(empty($result->data['error_message']), 'reactivation request is accepted',
		(string)($result->data['error_message'] ?? ''));
	check(st_current_level($user_id) === (int)$low, 'reactivation preserves the tier',
		'now level ' . var_export(st_current_level($user_id), true) . ", expected $low");

	// Read the row directly rather than through the active-subscription filter,
	// so each field assertion reports on its own instead of cascading from one
	// filter miss.
	$item = new OrderItem($subscription['order_item_id'], TRUE);
	check(!$item->get('odi_subscription_cancel_at_period_end'),
		'the cancel-at-period-end flag is cleared');
	check($item->get('odi_subscription_cancelled_time') === null,
		'the scheduled cancellation time is cleared',
		'cancelled_time = ' . var_export($item->get('odi_subscription_cancelled_time'), true));

	// The whole point of reactivating: the subscription counts as active again.
	// is_active_subscription requires odi_subscription_cancelled_time IS NULL, so
	// this fails whenever the reset above is incomplete.
	$reactivated = new MultiOrderItem(
		array('user_id' => $user_id, 'is_active_subscription' => true),
		array('order_item_id' => 'DESC')
	);
	$reactivated->load();
	check($reactivated->count() > 0, 'reactivation restores the subscription to active',
		$reactivated->count() . ' active subscription(s)');
}

harness_finish();
