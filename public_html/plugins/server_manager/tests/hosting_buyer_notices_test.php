<?php
/** @joinery-test
 * name: hosting_buyer_notices
 * tier: db
 * env: any
 * needs: []
 * covers: [plugins/server_manager/includes/provisioning/HostingBuyerNotices.php, plugins/server_manager/data/customer_cloud_provisions_class.php, plugins/server_manager/logic/profile_sites_logic.php]
 */
/**
 * A paid site's buyer is told, once, each state they need to know
 * (specs/hosting_after_purchase.md WP2): waiting to connect, held, failed,
 * cancel scheduled, payment needed or hosting ended, shuts down soon, shut
 * down. A setup state left and reached again is told again; a site another
 * store sold is not told here. Also: Retry setup and Retry email setup, and
 * a row whose order was closed may be dismissed.
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/HostingBuyerNotices.php'));

$run = getmypid() . '-' . random_int(1000, 9999);
$buyer = make_user('BuyerNotices');
$sent = array();
HostingBuyerNotices::$mailer = function ($template, $to, $vars) use (&$sent) {
	$sent[] = array('template' => $template, 'to' => $to, 'vars' => $vars);
};
HostingBuyerNotices::$now = gmdate('Y-m-d H:i:s');

function hbn_line($buyer, bool $subscription = true) {
	$order = new Order(NULL);
	$order->set('ord_usr_user_id', $buyer->key);
	$order->save();
	$order->load();
	harness_register_row('ord_orders', 'ord_order_id', $order->key);
	$item = new OrderItem(NULL);
	$item->set('odi_ord_order_id', $order->key);
	$item->set('odi_pro_product_id', 999999002);
	$item->set('odi_usr_user_id', $buyer->key);
	$item->set('odi_price', '12.99');
	$item->set('odi_status', OrderItem::STATUS_PAID);
	$item->set('odi_is_subscription', $subscription);
	$item->set('odi_subscription_status', $subscription ? 'active' : null);
	$item->set('odi_subscription_period_end', gmdate('Y-m-d H:i:s', time() + 20 * 86400));
	$item->save();
	$item->load();
	harness_register_row('odi_order_items', 'odi_order_item_id', $item->key);
	return $item;
}

function hbn_site($buyer, string $name, string $status, int $item_id, string $origin = 'buyer') {
	$prov = new CustomerCloudProvision(NULL);
	$prov->set('cvp_origin', $origin);
	$prov->set('cvp_usr_user_id', $buyer->key);
	$prov->set('cvp_domain', $name . '.example.com');
	$prov->set('cvp_slug', $name);
	$prov->set('cvp_status', $status);
	$prov->set('cvp_hosting_mode', 'operator');
	$prov->set('cvp_buyer_email', 'hbn-' . $name . '@example.com');
	$prov->set('cvp_external_order_item_id', $item_id);
	$prov->save();
	$prov->load();
	harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $prov->key);
	return $prov;
}

function hbn_templates(array $sent): array {
	return array_column($sent, 'template');
}

// ---------------------------------------------------------------------------
section('A setup state is told once, and again when it is reached again');
$item = hbn_line($buyer);
$site = hbn_site($buyer, 'hbn-fail-' . $run, 'failed', (int)$item->key);
$sent = array();
check(HostingBuyerNotices::tell($site) === 1 && hbn_templates($sent) === array('hosting_setup_failed'),
	'a failed site\'s buyer is told it failed', json_encode(hbn_templates($sent)));
check($sent[0]['to'] === 'hbn-hbn-fail-' . $run . '@example.com' && $sent[0]['vars']['domain'] === $site->get('cvp_domain')
	&& $sent[0]['vars']['support_email'] !== '', 'to the buyer, naming the site and where to write');
$sent = array();
check(HostingBuyerNotices::tell(new CustomerCloudProvision($site->key, TRUE)) === 0, 'told once only');
$site = new CustomerCloudProvision($site->key, TRUE);
$site->set('cvp_status', 'ready');
$site->save();
HostingBuyerNotices::tell($site);
$site = new CustomerCloudProvision($site->key, TRUE);
check(HostingBuyerNotices::told($site) === array(), 'leaving the state forgets it');
$site->fail('again');
$sent = array();
check(HostingBuyerNotices::tell(new CustomerCloudProvision($site->key, TRUE)) === 1, 'failing again is told again');

section('Waiting to connect and held are told');
$w_item = hbn_line($buyer);
$waiting = hbn_site($buyer, 'hbn-wait-' . $run, 'pending_connect', (int)$w_item->key);
$sent = array();
HostingBuyerNotices::tell($waiting);
check(hbn_templates($sent) === array('hosting_waiting_connect'), 'an own-Linode site waiting for its connection', json_encode(hbn_templates($sent)));
$h_item = hbn_line($buyer);
$held = hbn_site($buyer, 'hbn-held-' . $run, 'held', (int)$h_item->key);
$sent = array();
HostingBuyerNotices::tell($held);
check(hbn_templates($sent) === array('hosting_held'), 'a held order', json_encode(hbn_templates($sent)));

section('A site another store sold is not told here');
harness_set_setting_mem('server_manager_getjoinery_api_url', 'https://store.elsewhere.example/api/v1');
$f_item = hbn_line($buyer);
$foreign = hbn_site($buyer, 'hbn-foreign-' . $run, 'failed', (int)$f_item->key, 'order');
$sent = array();
check(!CustomerCloudProvision::polls_own_store() && HostingBuyerNotices::tell($foreign) === 0 && $sent === array(),
	'an order polled from another store names no line here, so nothing is sent');
harness_set_setting_mem('server_manager_getjoinery_api_url', '');

// ---------------------------------------------------------------------------
section('The plan: cancel scheduled, grace, the last week, shut down');
$p_item = hbn_line($buyer);
$plan_site = hbn_site($buyer, 'hbn-plan-' . $run, 'done', (int)$p_item->key);
$trial = new HostedTrial(NULL);
$trial->set('htr_cvp_customer_cloud_provision_id', (int)$plan_site->key);
$trial->set('htr_external_order_item_id', (int)$p_item->key);
$trial->set('htr_state', HostedTrial::STATE_SUBSCRIBED);
$trial->save();
$trial->load();
harness_register_row('htr_hosted_trials', 'htr_hosted_trial_id', $trial->key);
$sent = array();
check(HostingBuyerNotices::tell($plan_site) === 0, 'a subscribed site with nothing pending is told nothing');

// A cancel at the period end records when it takes effect: the period end.
$p_item->set('odi_subscription_cancelled_time', (string)$p_item->get('odi_subscription_period_end'));
$p_item->save();
$sent = array();
HostingBuyerNotices::tell(new CustomerCloudProvision($plan_site->key, TRUE));
check(hbn_templates($sent) === array('hosting_cancel_scheduled') && $sent[0]['vars']['period_end'] !== '',
	'a cancel to the end of the paid period is told, with that date', json_encode(hbn_templates($sent)));
$p_item->set('odi_subscription_cancelled_time', null);
$p_item->save();
HostingBuyerNotices::tell(new CustomerCloudProvision($plan_site->key, TRUE));
$p_item->set('odi_subscription_cancelled_time', (string)$p_item->get('odi_subscription_period_end'));
$p_item->save();
$sent = array();
HostingBuyerNotices::tell(new CustomerCloudProvision($plan_site->key, TRUE));
check(hbn_templates($sent) === array('hosting_cancel_scheduled'), 'a cancel taken back, then made again, is told again (B22)');
$immediate = new OrderItem($p_item->key, TRUE);
$immediate->set('odi_subscription_cancelled_time', gmdate('Y-m-d H:i:s', time() - 60));
check(!isset(HostingBuyerNotices::due(new CustomerCloudProvision($plan_site->key, TRUE), $immediate)['plan:cancel:' . $immediate->get('odi_subscription_cancelled_time')]),
	'an immediate cancel is not told as running to the period end (B22)');
$sent = array();
HostingBuyerNotices::$mailer = function () { return false; };
$fail_item = hbn_line($buyer);
$fail_site = hbn_site($buyer, 'hbn-nosend-' . $run, 'failed', (int)$fail_item->key);
check(HostingBuyerNotices::tell($fail_site) === 0 && HostingBuyerNotices::told(new CustomerCloudProvision($fail_site->key, TRUE)) === array(),
	'a send that did not go is not recorded as told (B20)');
HostingBuyerNotices::$mailer = function ($template, $to, $vars) use (&$sent) {
	$sent[] = array('template' => $template, 'to' => $to, 'vars' => $vars);
};

$trial->set('htr_state', HostedTrial::STATE_GRACE);
$trial->set('htr_grace_ends_time', gmdate('Y-m-d H:i:s', time() + 20 * 86400));
$trial->set('htr_shelf_ends_time', gmdate('Y-m-d H:i:s', time() + 80 * 86400));
$trial->save();
$sent = array();
HostingBuyerNotices::tell(new CustomerCloudProvision($plan_site->key, TRUE));
check(hbn_templates($sent) === array('hosting_grace_ended'), 'the grace a cancel ends in reads as hosting ended', json_encode(hbn_templates($sent)));

HostingBuyerNotices::$now = gmdate('Y-m-d H:i:s', time() + 15 * 86400);
$sent = array();
HostingBuyerNotices::tell(new CustomerCloudProvision($plan_site->key, TRUE));
check(hbn_templates($sent) === array('hosting_shutdown_soon') && $sent[0]['vars']['days_left'] === '5',
	'five days before the end, the last warning', json_encode($sent ? $sent[0]['vars']['days_left'] ?? null : null));
$sent = array();
HostingBuyerNotices::tell(new CustomerCloudProvision($plan_site->key, TRUE));
check($sent === array(), 'and only once');
HostingBuyerNotices::$now = gmdate('Y-m-d H:i:s');

$trial->set('htr_state', HostedTrial::STATE_SHUTDOWN);
$trial->set('htr_shutdown_time', gmdate('Y-m-d H:i:s'));
$trial->save();
$sent = array();
HostingBuyerNotices::tell(new CustomerCloudProvision($plan_site->key, TRUE));
check(hbn_templates($sent) === array('hosting_shut_down'), 'a shutdown is told', json_encode(hbn_templates($sent)));

section('A failed payment\'s grace reads as payment needed');
$g_item = hbn_line($buyer);
$g_site = hbn_site($buyer, 'hbn-grace-' . $run, 'done', (int)$g_item->key);
$g_trial = new HostedTrial(NULL);
$g_trial->set('htr_cvp_customer_cloud_provision_id', (int)$g_site->key);
$g_trial->set('htr_state', HostedTrial::STATE_GRACE);
$g_trial->set('htr_grace_ends_time', gmdate('Y-m-d H:i:s', time() + 25 * 86400));
$g_trial->save();
$g_trial->load();
harness_register_row('htr_hosted_trials', 'htr_hosted_trial_id', $g_trial->key);
$sent = array();
HostingBuyerNotices::tell($g_site);
check(hbn_templates($sent) === array('hosting_grace_payment') && strpos($sent[0]['vars']['billing_url'], '/profile/billing') !== false,
	'with where to update the card', json_encode(hbn_templates($sent)));

section('Your Sites says the plan in one sentence');
require_once(PathHelper::getIncludePath('plugins/server_manager/logic/profile_sites_logic.php'));
$session = SessionControl::get_instance();
check(strpos(profile_sites_plan_line(new HostedTrial($g_trial->key, TRUE), $g_item, $session), 'Payment needed by ') === 0,
	'a failed payment: payment needed by the date');
$p_trial = new HostedTrial($trial->key, TRUE);
$p_trial->set('htr_state', HostedTrial::STATE_SUBSCRIBED);
check(strpos(profile_sites_plan_line($p_trial, new OrderItem($p_item->key, TRUE), $session), 'Cancelled: runs until ') === 0,
	'a cancel waiting for the period end says so');

// ---------------------------------------------------------------------------
section('Retry setup, Retry email setup, and closing an order');
$r_item = hbn_line($buyer);
$r_site = hbn_site($buyer, 'hbn-retry-' . $run, 'failed', (int)$r_item->key);
check($r_site->retry_setup_refusal() === '', 'a paid site failed before its node may be retried');
// No server yet: its activation runs again, against its own paid line. This
// line names no product that can be read, so it stops, and says so.
$refused = null;
try { $r_site->retry_setup(); } catch (CustomerCloudProvisionException $e) { $refused = $e->getMessage(); }
check($refused !== null && strpos($refused, 'activation') !== false, 'one with no server reruns its activation, which says why it stopped', (string)$refused);
// A server already made: it was activated, and goes back to be built.
$r_site = new CustomerCloudProvision($r_site->key, TRUE);
$r_site->set('cvp_instance_id', '77100');
$r_site->set('cvp_status', 'failed');
$r_site->set('cvp_external_order_item_id', (int)$r_item->key);
$r_site->save();
$r_site->retry_setup();
$r_site = new CustomerCloudProvision($r_site->key, TRUE);
check($r_site->get('cvp_status') === 'ready' && trim((string)$r_site->get('cvp_error')) === '', 'one with a server goes back to be built');
check($r_site->retry_setup_refusal() !== '', 'a site that has not failed is not');

$r_site->set('cvp_mail_state', 'failed');
$r_site->set('cvp_smtp2go_subaccount_id', 'sub-' . $run);
$r_site->set('cvp_mail_error', 'no records');
$r_site->save();
check($r_site->retry_mail() === 'subaccount_created', 'email setup restarts after the subaccount it already made');
$r_site->set('cvp_mail_state', 'failed');
$r_site->set('cvp_smtp2go_domain_id', 'dom-' . $run);
$r_site->set('cvp_smtp2go_user_id', 'usr-' . $run);
$r_site->save();
check($r_site->retry_mail() === 'smtp_user_created', 'and after the user, at the settings push');
check(trim((string)$r_site->get('cvp_mail_retry_time')) !== '', 'and the retry is stamped, so the push gets a fresh allowance (B19)');

$r_site->fail('for the dismiss check');
check(!$r_site->order_closed() && in_array('a paid order is waiting on it: refund it, or cancel its subscription, first', $r_site->dismiss_blockers(), true),
	'a failed site whose order still pays may not be dismissed');
$r_item->set('odi_subscription_cancelled_time', gmdate('Y-m-d H:i:s'));
$r_item->save();
check($r_site->order_closed(), 'once its subscription is cancelled the order is closed');

// ---------------------------------------------------------------------------
section('A domain whose site is gone is told by email at the notice\'s stages (D2)');
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/ManagedDomainWatch.php'));
$d_item = hbn_line($buyer);
$d_site = hbn_site($buyer, 'hbn-dom-' . $run, 'done', (int)$d_item->key);
$dom = new RegisteredDomain(NULL);
$dom->set('rdm_registrar', 'namecheap');
$dom->set('rdm_domain', 'hbn-dom-' . $run . '.com');
$dom->set('rdm_usr_user_id', $buyer->key);
$dom->set('rdm_buyer_email', 'hbn-dom-' . $run . '@example.com');
$dom->set('rdm_external_order_item_id', (int)$d_item->key);
$dom->set('rdm_status', RegisteredDomain::STATUS_ACTIVE);
$dom->set('rdm_graduation_state', RegisteredDomain::GRAD_OPERATOR);
$dom->set('rdm_registered_time', gmdate('Y-m-d H:i:s', time() - 300 * 86400));
$dom->set('rdm_expiry_time', gmdate('Y-m-d H:i:s', time() + 20 * 86400));
$dom->prepare();
$dom->save();
$dom->load();
harness_register_row('rdm_registered_domains', 'rdm_registered_domain_id', $dom->key);
check(!ManagedDomainWatch::has_live_box($dom), 'a domain with no node has no box to show its notice');
$sent = array();
check(HostingBuyerNotices::tell_domain_expiry($dom) === 1 && hbn_templates($sent) === array('hosting_domain_expiring')
	&& $sent[0]['to'] === 'hbn-dom-' . $run . '@example.com' && $sent[0]['vars']['days_left'] !== '',
	'its buyer is emailed at the month stage', json_encode(hbn_templates($sent)));
$sent = array();
check(HostingBuyerNotices::tell_domain_expiry($dom) === 0, 'once per stage');
$dom->set('rdm_expiry_time', gmdate('Y-m-d H:i:s', time() + 5 * 86400));
check(HostingBuyerNotices::tell_domain_expiry($dom) === 1, 'and again at the week stage');
$dom->set('rdm_graduation_state', RegisteredDomain::GRAD_SELF);
check(HostingBuyerNotices::tell_domain_expiry($dom) === 0, 'never for a domain already in the buyer\'s own account');

HostingBuyerNotices::$mailer = null;
HostingBuyerNotices::$now = null;
harness_finish();
