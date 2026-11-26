<?php
/** @joinery-test
 * name: hosting_buyer_notices
 * tier: db
 * env: any
 * needs: []
 * covers: [plugins/server_manager/includes/provisioning/HostingBuyerNotices.php, includes/EmailSender.php, plugins/server_manager/data/customer_cloud_provisions_class.php, plugins/server_manager/logic/profile_sites_logic.php]
 */
/**
 * A paid site's buyer is told, once, each state they need to know
 * (specs/hosting_after_purchase.md WP2): waiting to connect, held, failed,
 * cancel scheduled, payment needed or hosting ended, shuts down soon, shut
 * down. A setup state left and reached again is told again; a site another
 * store sold is not told here. Also: Retry setup and Retry email setup, and
 * a row whose order was closed may be dismissed. Each notice is a send-once
 * email on the outbox (specs/email_outbox.md).
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/HostingBuyerNotices.php'));

$run = getmypid() . '-' . random_int(1000, 9999);
$buyer = make_user('BuyerNotices');
$sent = array();
$hbn_capture = function ($template, $to, $vars) use (&$sent) {
	$sent[] = array('template' => $template, 'to' => $to, 'vars' => $vars);
	return true;
};
EmailSender::$capture = $hbn_capture;
harness_defer(function () { EmailSender::$capture = null; });
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
check(EmailSender::onceKeys($site, 'setup:') === array(), 'leaving the state forgets it');
$site->fail('again');
$sent = array();
check(HostingBuyerNotices::tell(new CustomerCloudProvision($site->key, TRUE)) === 1, 'failing again is told again');

section('Waiting to connect and held are told');
$w_item = hbn_line($buyer);
$waiting = hbn_site($buyer, 'hbn-wait-' . $run, 'pending_connect', (int)$w_item->key);
$sent = array();
HostingBuyerNotices::tell($waiting);
check(hbn_templates($sent) === array('hosting_waiting_connect'), 'an own-Linode site waiting for its connection', json_encode(hbn_templates($sent)));
// Their own account would not make the server: the provider's words, once
// per refusal, in place of the plain wait.
$waiting->set('cvp_error', CustomerCloudProvision::PROVIDER_REFUSED . 'You have reached a limit <of services>.');
$waiting->save();
$waiting = new CustomerCloudProvision($waiting->key, TRUE);
check($waiting->provider_refusal() === 'You have reached a limit <of services>.', 'the refusal is read off the waiting site');
$sent = array();
HostingBuyerNotices::tell($waiting);
check(hbn_templates($sent) === array('hosting_provider_refused')
	&& strpos(json_encode($sent), 'limit &lt;of services&gt;') !== false,
	'a refusal by the buyer\'s own account is told with the provider\'s words, escaped', json_encode($sent));
$sent = array();
check(HostingBuyerNotices::tell(new CustomerCloudProvision($waiting->key, TRUE)) === 0, 'the same refusal is told once');
$waiting = new CustomerCloudProvision($waiting->key, TRUE);
$waiting->set('cvp_status', 'failed');
$waiting->save();
check((new CustomerCloudProvision($waiting->key, TRUE))->provider_refusal() === '', 'only a waiting site reads as refused');
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
EmailSender::$capture = function () { return false; };
$fail_item = hbn_line($buyer);
$fail_site = hbn_site($buyer, 'hbn-nosend-' . $run, 'failed', (int)$fail_item->key);
$hbn_db = DbConnector::get_instance()->get_db_link();
$hbn_row = function ($site) use ($hbn_db) {
	$q = $hbn_db->prepare("SELECT equ_queued_email_id, equ_status, equ_body IS NOT NULL AS has_body FROM equ_queued_emails
		WHERE equ_entity_type = 'cvp_customer_cloud_provisions' AND equ_entity_id = ? AND equ_once_key = 'setup:failed'");
	$q->execute(array((int)$site->key));
	return $q->fetch(PDO::FETCH_ASSOC) ?: array();
};
check(HostingBuyerNotices::tell($fail_site) === 1 && (int)($hbn_row($fail_site)['equ_status'] ?? 0) === QueuedEmail::ERROR_SENDING
	&& !empty($hbn_row($fail_site)['has_body']),
	'a send that did not go waits for the queue\'s retry, with what it needs (B20)');
EmailSender::$capture = $hbn_capture;
$sent = array();
check(HostingBuyerNotices::tell(new CustomerCloudProvision($fail_site->key, TRUE)) === 0 && $sent === array(),
	'while it waits for its retry, the next pass sends no second copy');
$hbn_db->prepare('UPDATE equ_queued_emails SET equ_status = ? WHERE equ_queued_email_id = ?')
	->execute(array(QueuedEmail::PERMANENT_FAILURE, (int)$hbn_row($fail_site)['equ_queued_email_id']));
check(HostingBuyerNotices::tell(new CustomerCloudProvision($fail_site->key, TRUE)) === 1 && hbn_templates($sent) === array('hosting_setup_failed')
	&& (int)$hbn_row($fail_site)['equ_status'] === QueuedEmail::SENT,
	'one that failed for good is sent again by the next pass, as the same row (Q6)');
$gone_id = (int)$hbn_row($fail_site)['equ_queued_email_id'];
$hbn_db->prepare('UPDATE equ_queued_emails SET equ_status = ?, equ_given_up_time = now() WHERE equ_queued_email_id = ?')
	->execute(array(QueuedEmail::PERMANENT_FAILURE, $gone_id));
$passed = new CustomerCloudProvision($fail_site->key, TRUE);
$passed->set('cvp_status', 'ready');
$passed->save();
$sent = array();
HostingBuyerNotices::tell(new CustomerCloudProvision($fail_site->key, TRUE));
$gone_row = $hbn_db->query('SELECT equ_status, equ_once_key, equ_given_up_time FROM equ_queued_emails WHERE equ_queued_email_id = '
	. $gone_id)->fetch(PDO::FETCH_ASSOC);
check($sent === array() && (int)$gone_row['equ_status'] === QueuedEmail::DELETED && $gone_row['equ_once_key'] === null
	&& $gone_row['equ_given_up_time'] === null,
	'one that failed for good whose state has passed is withdrawn, not sent and not held as a failure (reviewer2 N2)');
$sent = array();

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

// ---------------------------------------------------------------------------
section('A built site still waiting for its DNS records is reminded after a day and a week (Q41)');
$n_node = new ManagedNode(NULL);
$n_node->set('mgn_name', 'HarnessTest dns reminder ' . $run);
$n_node->set('mgn_slug', 'hbn-dnsr-' . $run);
$n_node->set('mgn_host', '203.0.113.' . random_int(2, 250));
$n_node->set('mgn_ssh_user', 'root');
$n_node->set('mgn_web_root', '/var/www/html/hbn-dnsr/public_html');
$n_node->set('mgn_enabled', true);
$n_node->set('mgn_ssl_state', 'pending');
$n_node->save();
$n_node->load();
harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $n_node->key);
$n_item = hbn_line($buyer);
$n_site = hbn_site($buyer, 'hbn-dnsr-' . $run, 'done', (int)$n_item->key);
$n_site->set('cvp_mgn_managed_node_id', (int)$n_node->key);
$listed = gmdate('Y-m-d H:i:s', strtotime(HostingBuyerNotices::$now . ' UTC') - 3600);
$n_site->set('cvp_site_dns_time', $listed);
$n_site->save();
$n_trial = new HostedTrial(NULL);
$n_trial->set('htr_cvp_customer_cloud_provision_id', (int)$n_site->key);
$n_trial->set('htr_external_order_item_id', (int)$n_item->key);
$n_trial->set('htr_state', HostedTrial::STATE_SUBSCRIBED);
$n_trial->save();
$n_trial->load();
harness_register_row('htr_hosted_trials', 'htr_hosted_trial_id', $n_trial->key);
$reload = function () use ($n_site) { return new CustomerCloudProvision((int)$n_site->key, TRUE); };
check(SmHosting::awaits_address($reload()), 'the site is built and waits for its address');
$sent = array();
check(HostingBuyerNotices::tell($reload()) === 0, 'an hour after the list was shown, nothing yet (the ready email told them)');
$set_listed = function (int $seconds_ago) use ($n_site) {
	$row = new CustomerCloudProvision((int)$n_site->key, TRUE);
	$row->set('cvp_site_dns_time', gmdate('Y-m-d H:i:s', strtotime(HostingBuyerNotices::$now . ' UTC') - $seconds_ago));
	$row->save();
};
$set_listed(86400 + 60);
$sent = array();
check(HostingBuyerNotices::tell($reload()) === 1 && hbn_templates($sent) === array('hosting_dns_reminder')
	&& $sent[0]['vars']['domain'] === $n_site->get('cvp_domain') && $sent[0]['vars']['listed_date'] !== ''
	&& $sent[0]['vars']['sites_url'] !== '',
	'a day on, the buyer is reminded, naming the site, the date and the sites page', json_encode($sent));
$sent = array();
check(HostingBuyerNotices::tell($reload()) === 0, 'once per stage');
// The list's date is in the key, so the stage moves with the same date.
$row = $reload();
$row->set('cvp_site_dns_time', gmdate('Y-m-d', strtotime($listed . ' UTC')) . ' 00:00:00');
$row->save();
HostingBuyerNotices::$now = gmdate('Y-m-d H:i:s', strtotime(gmdate('Y-m-d', strtotime($listed . ' UTC')) . ' 00:00:00 UTC') + 7 * 86400 + 60);
$sent = array();
check(HostingBuyerNotices::tell($reload()) === 1 && hbn_templates($sent) === array('hosting_dns_reminder'),
	'a week on, reminded again', json_encode(hbn_templates($sent)));
$sent = array();
check(HostingBuyerNotices::tell($reload()) === 0, 'and not a third time');
HostingBuyerNotices::$now = gmdate('Y-m-d H:i:s');

section('No reminder once the name reaches the site, while hosting ends, or before the list is shown');
$fresh = function (string $tag, array $site_cols, ?string $trial_state, int $listed_ago) use ($buyer, $run) {
	$node = new ManagedNode(NULL);
	$node->set('mgn_name', 'HarnessTest dns reminder ' . $tag . ' ' . $run);
	$node->set('mgn_slug', 'hbn-dn' . $tag . '-' . $run);
	$node->set('mgn_host', '203.0.113.' . random_int(2, 250));
	$node->set('mgn_ssh_user', 'root');
	$node->set('mgn_web_root', '/var/www/html/hbn-dn/public_html');
	$node->set('mgn_enabled', true);
	$node->set('mgn_ssl_state', $site_cols['ssl'] ?? 'pending');
	$node->save();
	$node->load();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $node->key);
	$item = hbn_line($buyer);
	if (!empty($site_cols['cancelled'])) {
		$item->set('odi_subscription_cancelled_time', gmdate('Y-m-d H:i:s', time() + 5 * 86400));
		$item->save();
	}
	$site = hbn_site($buyer, 'hbn-dn' . $tag . '-' . $run, 'done', (int)$item->key);
	$site->set('cvp_mgn_managed_node_id', (int)$node->key);
	$site->set('cvp_site_dns_time', $listed_ago > 0 ? gmdate('Y-m-d H:i:s', time() - $listed_ago) : null);
	$site->save();
	if ($trial_state !== null) {
		$trial = new HostedTrial(NULL);
		$trial->set('htr_cvp_customer_cloud_provision_id', (int)$site->key);
		$trial->set('htr_external_order_item_id', (int)$item->key);
		$trial->set('htr_state', $trial_state);
		if ($trial_state === HostedTrial::STATE_GRACE) {
			$trial->set('htr_grace_ends_time', gmdate('Y-m-d H:i:s', time() + 20 * 86400));
		}
		$trial->save();
		$trial->load();
		harness_register_row('htr_hosted_trials', 'htr_hosted_trial_id', $trial->key);
	}
	return new CustomerCloudProvision((int)$site->key, TRUE);
};
$sent = array();
HostingBuyerNotices::tell($fresh('ok', array('ssl' => 'active'), HostedTrial::STATE_SUBSCRIBED, 2 * 86400));
check(!in_array('hosting_dns_reminder', hbn_templates($sent), true), 'a site whose name reaches it is not reminded');
$sent = array();
HostingBuyerNotices::tell($fresh('nl', array(), HostedTrial::STATE_SUBSCRIBED, 0));
check($sent === array(), 'a site whose list was never shown is not reminded', json_encode(hbn_templates($sent)));
$sent = array();
HostingBuyerNotices::tell($fresh('gr', array(), HostedTrial::STATE_GRACE, 2 * 86400));
check(!in_array('hosting_dns_reminder', hbn_templates($sent), true) && in_array('hosting_grace_payment', hbn_templates($sent), true),
	'a site in grace is told its grace, not reminded', json_encode(hbn_templates($sent)));
$sent = array();
HostingBuyerNotices::tell($fresh('cx', array('cancelled' => true), HostedTrial::STATE_SUBSCRIBED, 2 * 86400));
check(!in_array('hosting_dns_reminder', hbn_templates($sent), true), 'a cancelled site is not reminded', json_encode(hbn_templates($sent)));
$sent = array();
HostingBuyerNotices::tell($fresh('lt', array(), HostedTrial::STATE_SUBSCRIBED, 30 * 86400));
check(hbn_templates($sent) === array('hosting_dns_reminder'), 'a site found a month late gets one email, not two', json_encode(hbn_templates($sent)));

section('The reminder template is installed');
$tpl = DbConnector::get_instance()->get_db_link()->prepare('SELECT emt_subject, emt_body FROM emt_email_templates WHERE emt_name = ?');
$tpl->execute(array('hosting_dns_reminder'));
$tpl_row = $tpl->fetch(PDO::FETCH_ASSOC);
check($tpl_row && strpos($tpl_row['emt_body'], '*sites_url*') !== false && strpos($tpl_row['emt_subject'], '*domain*') !== false,
	'hosting_dns_reminder exists and links the sites page (run update_database if not)');

HostingBuyerNotices::$now = null;
harness_finish();
