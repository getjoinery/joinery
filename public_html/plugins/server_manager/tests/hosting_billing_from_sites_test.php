<?php
/** @joinery-test
 * name: hosting_billing_from_sites
 * tier: db
 * env: any
 * needs: []
 * covers: [plugins/store/data/order_items_class.php, plugins/server_manager/includes/fulfillment_providers/CustomerCloudFulfillment.php, plugins/server_manager/logic/profile_sites_logic.php, plugins/store/views/profile/subscriptions.php]
 */
/**
 * Billing reached from Your Sites (specs/hosting_after_purchase.md WP3): a
 * hosting line's cancel question says what happens to the site; a cancel at
 * the end of the paid period can be taken back while it has not ended; each
 * paid site links its subscription, billing, domain and services; its
 * hosting row names the price.
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/server_manager/logic/profile_sites_logic.php'));

$run = getmypid() . '-' . random_int(1000, 9999);
$buyer = make_user('BillingFromSites');

$product = new Product(NULL);
$product->set('pro_name', 'Managed hosting ' . $run);
$product->set('pro_link', 'bfs-hosting-' . $run);
$product->set('pro_fulfillment_provider', 'customer_cloud');
$product->set('pro_fulfillment_ref', 1);
$product->save();
$product->load();
harness_register_row('pro_products', 'pro_product_id', $product->key);

$order = new Order(NULL);
$order->set('ord_usr_user_id', $buyer->key);
$order->save();
$order->load();
harness_register_row('ord_orders', 'ord_order_id', $order->key);
$item = new OrderItem(NULL);
$item->set('odi_ord_order_id', $order->key);
$item->set('odi_pro_product_id', $product->key);
$item->set('odi_usr_user_id', $buyer->key);
$item->set('odi_price', '12.99');
$item->set('odi_status', OrderItem::STATUS_PAID);
$item->set('odi_is_subscription', true);
$item->set('odi_subscription_status', 'active');
$item->set('odi_stripe_subscription_id', 'sub_test_' . $run);
$item->set('odi_subscription_period_end', gmdate('Y-m-d H:i:s', time() + 20 * 86400));
$item->save();
$item->load();
harness_register_row('odi_order_items', 'odi_order_item_id', $item->key);

$prov = new CustomerCloudProvision(NULL);
$prov->set('cvp_origin', 'buyer');
$prov->set('cvp_usr_user_id', $buyer->key);
$prov->set('cvp_domain', 'bfs-' . $run . '.example.com');
$prov->set('cvp_slug', 'bfs-' . $run);
$prov->set('cvp_status', 'done');
$prov->set('cvp_hosting_mode', 'operator');
$prov->set('cvp_domain_source', 'register');
$prov->set('cvp_external_order_item_id', $item->key);
$prov->save();
$prov->load();
harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $prov->key);

section('The cancel question says what happens to the site');
$notice = $item->cancel_notice();
check(strpos($notice, $prov->get('cvp_domain')) !== false
	&& strpos($notice, HostedTrialSignals::grace_days() . ' days after') !== false
	&& strpos($notice, 'shut down') !== false && strpos($notice, 'recovery key') !== false,
	'a hosting line names the site, the grace, the shutdown and the backups', $notice);

section('A cancel at the end of the paid period can be taken back while it lasts');
check(!$item->can_resume(), 'a live subscription has nothing to take back');
$item->set('odi_subscription_cancel_at_period_end', true);
$item->set('odi_subscription_cancelled_time', gmdate('Y-m-d H:i:s', time() + 20 * 86400));
$item->save();
check($item->can_resume(), 'a cancel waiting for the period end can be undone');
$item->set('odi_subscription_cancelled_time', gmdate('Y-m-d H:i:s', time() - 60));
check(!$item->can_resume(), 'one that has ended cannot');
$item->set('odi_subscription_cancelled_time', gmdate('Y-m-d H:i:s', time() + 20 * 86400));
$item->set('odi_stripe_subscription_id', null);
$item->set('odi_paypal_subscription_id', 'I-' . $run);
check(!$item->can_resume(), 'nor a PayPal one, which PayPal ended at once');
$item = new OrderItem($item->key, TRUE);

section('Each paid site links where it is managed, and its row names the price');
$links = profile_sites_links($prov);
check(isset($links['Subscription'], $links['Billing and card'], $links['Domain']) && !isset($links['Email and backups']),
	'subscription, billing and the registered domain; no services it does not rent', json_encode($links));
$trial = new HostedTrial(NULL);
$trial->set('htr_cvp_customer_cloud_provision_id', (int)$prov->key);
$trial->set('htr_state', HostedTrial::STATE_SUBSCRIBED);
$item->set('odi_subscription_cancelled_time', null);
$line = profile_sites_plan_line($trial, $item, SessionControl::get_instance());
check(strpos($line, 'Subscribed, ') === 0 && strpos($line, '12.99') !== false && strpos($line, 'renews ') !== false,
	'a subscribed site says its price and when it renews', $line);

harness_finish();
