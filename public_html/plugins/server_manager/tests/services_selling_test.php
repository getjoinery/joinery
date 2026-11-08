<?php
/** @joinery-test
 * name: services_selling
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Selling getjoinery's services to a self-hosted site, and the nudge
 * (specs/services_phase3_selling_nudge.md §1, §2):
 *
 *  - **The line names the site.** JoineryServiceRequirement takes only a live
 *    row of the signed-in buyer's, on a key still active, and stores its id.
 *  - **Before the charge** a stale site, a product with no plan, and a site
 *    already renewing by subscription are refused with a sentence.
 *  - **A purchase writes the date.** Bought once: the plan's term from the
 *    later of today and the old date, on every row the plan grants (the
 *    missing one created), once per order item. By subscription: the line's
 *    period end, followed every pass while the line pays; a cancelled line
 *    stops, and the date is left to fall into the past.
 *  - **The site is told** what is on sale (an unpaid row's notice, the offer
 *    in the status answer), and whether its date renews.
 *  - **The connected sites page** offers Buy, Keep going, or nothing.
 *  - **The reminders** are owed at 80% and 100%, inside the notice window, on
 *    the date and at the stop; never twice, and never for a renewing date.
 *  - **The banner** carries the paid period as its own row with two doors.
 *
 * Run: php plugins/server_manager/tests/services_selling_test.php
 *
 * @version 1.1 - reviewer2 B1-B5: a released service stays released, past_due buys nothing, a paid idle site is
 *               reminded, the stopped row, a blank date is not read as renewing
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

require_once(PathHelper::getIncludePath('plugins/store/includes/FulfillmentRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/fulfillment_providers/JoineryServicesFulfillment.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/requirements/JoineryServiceRequirement.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/product_versions_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/orders_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/order_items_class.php'));
require_once(PathHelper::getIncludePath('tasks/ServicesStatusPoll.php'));

$db = DbConnector::get_instance()->get_db_link();
$run = substr(md5(uniqid('sss', true)), 0, 6);

harness_set_setting_mem('server_manager_hosted_send_allowance', '2000');
harness_set_setting_mem('server_manager_hosted_shelf_allowance_gb', '500');
harness_set_setting_mem('server_manager_services_grace_days', '14');
harness_set_setting_mem('server_manager_services_notice_days', '21');
harness_set_setting_mem('server_manager_smtp2go_referral_url', 'https://smtp2go.example/ref');

// ── Fixtures ────────────────────────────────────────────────────────────────

function sss_product(string $name, string $link, int $ref) {
	$product = new Product(NULL);
	$product->set('pro_name', $name);
	$product->set('pro_link', $link);
	$product->set('pro_is_active', true);
	$product->set('pro_fulfillment_provider', 'joinery_services');
	$product->set('pro_fulfillment_ref', $ref);
	$product->save();
	$product->load();
	harness_register_row('pro_products', 'pro_product_id', $product->key);
	return $product;
}
function sss_version($product, string $price, string $type) {
	$version = new ProductVersion(NULL);
	$version->set('prv_pro_product_id', $product->key);
	$version->set('prv_version_name', 'Services');
	$version->set('prv_version_price', $price);
	$version->set('prv_price_type', $type);
	$version->set('prv_status', 1);
	$version->save();
	$version->load();
	harness_register_row('prv_product_versions', 'prv_product_version_id', $version->key);
	return $version;
}
/** A paid line naming the row, as cart_charge stores it; a subscription line takes the period fields. */
function sss_line($buyer, $product, $version, int $row_id, array $sub = array()) {
	$order = new Order(NULL);
	$order->set('ord_usr_user_id', $buyer->key);
	$order->set('ord_status', Order::STATUS_PAID);
	$order->save();
	$order->load();
	harness_register_row('ord_orders', 'ord_order_id', $order->key);
	$item = new OrderItem(NULL);
	$item->set('odi_ord_order_id', $order->key);
	$item->set('odi_pro_product_id', $product->key);
	$item->set('odi_prv_product_version_id', $version->key);
	$item->set('odi_usr_user_id', $buyer->key);
	$item->set('odi_product_info', base64_encode(serialize(array(
		JoineryServiceRequirement::FIELD => array('question' => 'Site record', 'answer' => (string)$row_id, 'internal' => true),
	))));
	$item->set('odi_price', $version->get('prv_version_price'));
	$item->set('odi_status', OrderItem::STATUS_PAID);
	foreach ($sub as $k => $v) { $item->set($k, $v); }
	$item->save();
	$item->load();
	harness_register_row('odi_order_items', 'odi_order_item_id', $item->key);
	return array($order, $item);
}
function sss_reload(ServiceTenant $row): ServiceTenant {
	return new ServiceTenant((int)$row->key, TRUE);
}
$at = function (int $days) { return gmdate('Y-m-d H:i:s', time() + $days * 86400); };

$monthly = sss_product('getjoinery services [test]', 'sss-monthly-' . $run, 1);
$monthly_version = sss_version($monthly, '2.99', 'month');
$once = sss_product('getjoinery services, one term [test]', 'sss-once-' . $run, 1);
$once_version = sss_version($once, '3.49', 'single');
$planless = sss_product('getjoinery services, no plan [test]', 'sss-planless-' . $run, 9);
sss_version($planless, '1.00', 'single');

$buyer = make_user('SssBuyer' . $run);
$stranger = make_user('SssStranger' . $run);
$key = make_machine_key($buyer->key, 'Joinery services', 3);
$key_id = (int)$key['api_key']->key;
$host = 'harnesstest-sss-' . $run . '.example.com';

$svt_ids = array();
harness_defer(function () use (&$svt_ids, $db) {
	foreach ($svt_ids as $id) {
		$db->exec('DELETE FROM sps_storage_spaces WHERE sps_svt_service_tenant_id = ' . (int)$id);
		$db->exec('DELETE FROM svt_service_tenants WHERE svt_service_tenant_id = ' . (int)$id);
	}
});
$track = function (?ServiceTenant $row) use (&$svt_ids) {
	if ($row !== null && !in_array((int)$row->key, $svt_ids, true)) { $svt_ids[] = (int)$row->key; }
	return $row;
};

// The site asked once from its Email step: its mail row exists at unpaid.
$mail = $track(JoineryServices::tenant((int)$buyer->key, $key_id, 'mail', $host));

$_SESSION['loggedin'] = 1;
$_SESSION['usr_user_id'] = (int)$buyer->key;
$_SESSION['permission'] = 0;
$provider = FulfillmentRegistry::get('joinery_services');

// ---------------------------------------------------------------------------
section('the provider and its plan');
check($provider instanceof JoineryServicesFulfillment, 'joinery_services is registered');
check(isset($provider->options()[1]) && JoineryServicesFulfillment::plan(1)['services'] === array('mail', 'shelf'),
	'plan 1 grants mail and backup storage');
$req = $provider->extraRequirements($monthly, 1);
check(count($req) === 1 && $req[0] instanceof JoineryServiceRequirement, 'the line carries one requirement, the site');

// ---------------------------------------------------------------------------
section('the line names the site');
$r = new JoineryServiceRequirement();
check($r->validate(array(JoineryServiceRequirement::FIELD => (int)$mail->key), $monthly) === array(),
	'the buyer\'s own row is accepted');
list($data, $display) = $r->process(array(), $monthly, null, $buyer);
check(($data[JoineryServiceRequirement::FIELD]['answer'] ?? '') === (string)$mail->key
	&& !empty($data[JoineryServiceRequirement::FIELD]['internal']), 'the row id is stored, marked internal');
check(($display['Site'] ?? '') === $host, 'the line reads as the site\'s host');
$_SESSION['usr_user_id'] = (int)$stranger->key;
check(count($r->validate(array(JoineryServiceRequirement::FIELD => (int)$mail->key), $monthly)) === 1,
	'another account\'s row is refused');
$_SESSION['usr_user_id'] = (int)$buyer->key;
check(count($r->validate(array(JoineryServiceRequirement::FIELD => 0), $monthly)) === 1, 'no row is refused');

// ---------------------------------------------------------------------------
section('before the charge');
$line_data = array(JoineryServiceRequirement::FIELD => array('answer' => (string)$mail->key));
check($provider->checkAvailability($monthly, 1, 1, $line_data) === null, 'a connected site of the buyer\'s may be bought for');
check($provider->checkAvailability($planless, 9, 1, $line_data) !== null, 'a product naming no plan is refused');
$_SESSION['usr_user_id'] = (int)$stranger->key;
check($provider->checkAvailability($monthly, 1, 1, $line_data) === JoineryServicesFulfillment::STALE_SITE_SENTENCE,
	'a line whose site is not the buyer\'s is refused with the sentence');
$_SESSION['usr_user_id'] = (int)$buyer->key;
$key['api_key']->set('apk_is_active', false);
$key['api_key']->save();
check($provider->checkAvailability($monthly, 1, 1, $line_data) === JoineryServicesFulfillment::STALE_SITE_SENTENCE,
	'a disconnected site (key inactive) is refused');
$key['api_key']->set('apk_is_active', true);
$key['api_key']->save();

// ---------------------------------------------------------------------------
section('what the site is told before it pays');
$s = JoineryServices::statusOf($mail);
check(strpos($s['notice'], '$2.99 a month') !== false && strpos($s['notice'], 'connected sites') !== false,
	'an unpaid row\'s notice names the cheapest offer and where to buy it', $s['notice']);
check($s['renews'] === false, 'an unpaid row does not renew');
$offer = JoineryServices::offer();
check($offer !== null && $offer['price'] === '$2.99 a month' && substr($offer['url'], -32) === '/profile/server_manager/services',
	'the status answer\'s offer is the product\'s price and the connected sites page', json_encode($offer));
$site = ServicesConnect::sitesFor((int)$buyer->key)[0] ?? array();
check(($site['row_id'] ?? 0) === (int)$mail->key, 'the connected sites page knows the row a purchase names');
check(JoineryServicesFulfillment::card_state($site, gmdate('Y-m-d H:i:s'), 21) === 'buy', 'a site never paid for shows Buy');
$form = JoineryServicesFulfillment::buy_form(JoineryServicesFulfillment::offers()[0], (int)$mail->key, 'Buy');
check(strpos($form, 'name="' . JoineryServiceRequirement::FIELD . '" value="' . (int)$mail->key . '"') !== false
	&& strpos($form, 'name="product_version"') !== false, 'the Buy button posts the product, its version and the row');

// ---------------------------------------------------------------------------
section('bought once: the term, on every row the plan grants');
list(, $item1) = sss_line($buyer, $once, $once_version, (int)$mail->key);
$before = time();
$result = $provider->fulfill($buyer, $once, $item1, new Order((int)$item1->get('odi_ord_order_id'), TRUE), 1);
$mail = sss_reload($mail);
$shelf = $track(ServiceTenant::forKey($key_id, 'shelf'));
check($shelf !== null, 'the backup storage row the site had not asked for yet is created');
$expect = $before + 31 * 86400;
$got = strtotime($mail->get('svt_paid_until') . ' UTC');
check(abs($got - $expect) < 120, 'mail is paid through today plus the plan\'s 31 days', (string)$mail->get('svt_paid_until'));
check($shelf && (string)$shelf->get('svt_paid_until') === (string)$mail->get('svt_paid_until'), 'backup storage gets the same date');
check((int)$mail->get('svt_odi_order_item_id') === (int)$item1->key, 'the row names the line that paid');
check(($result['label'] ?? '') === 'getjoinery services for ' . $host && ($result['outcome'] ?? '') === 'plain',
	'the receipt names the site', json_encode($result));
$first_date = (string)$mail->get('svt_paid_until');
$provider->fulfill($buyer, $once, $item1, new Order((int)$item1->get('odi_ord_order_id'), TRUE), 1);
check((string)sss_reload($mail)->get('svt_paid_until') === $first_date, 'the same order item twice extends nothing');
list(, $item2) = sss_line($buyer, $once, $once_version, (int)$mail->key);
$provider->fulfill($buyer, $once, $item2, new Order((int)$item2->get('odi_ord_order_id'), TRUE), 1);
$mail = sss_reload($mail);
check(abs(strtotime($mail->get('svt_paid_until') . ' UTC') - (strtotime($first_date . ' UTC') + 31 * 86400)) < 5,
	'bought again early, the term runs from the old date, not today', (string)$mail->get('svt_paid_until'));
$site = ServicesConnect::sitesFor((int)$buyer->key)[0] ?? array();
check(JoineryServicesFulfillment::card_state($site, gmdate('Y-m-d H:i:s'), 21) === '', 'paid and not due: nothing to buy');
check(JoineryServicesFulfillment::card_state($site, $at(45), 21) === 'keep', 'inside the notice window: Keep going');
check(JoineryServicesFulfillment::card_state($site, $at(70), 21) === 'buy', 'past the date: Buy');

// ---------------------------------------------------------------------------
section('by subscription: the period end, followed while it pays');
foreach (array($mail, $shelf) as $row) {
	$row->set('svt_paid_until', null);
	$row->set('svt_odi_order_item_id', null);
	$row->save();
}
list(, $sub) = sss_line($buyer, $monthly, $monthly_version, (int)$mail->key, array(
	'odi_is_subscription' => true, 'odi_subscription_status' => 'active',
	'odi_subscription_period_end' => $at(30)));
$result = $provider->fulfill($buyer, $monthly, $sub, new Order((int)$sub->get('odi_ord_order_id'), TRUE), 1);
$mail = sss_reload($mail);
$shelf = sss_reload($shelf);
check(substr((string)$mail->get('svt_paid_until'), 0, 16) === substr($at(30), 0, 16), 'the date is the line\'s period end');
check(($result['outcome'] ?? '') === 'subscription', 'the receipt reads the line as a subscription');
check(JoineryServicesFulfillment::row_renews($mail) && JoineryServices::statusOf($mail)['renews'] === true,
	'the row renews, and the status answer says so');
check($provider->checkAvailability($monthly, 1, 1, $line_data) === JoineryServicesFulfillment::ALREADY_RENEWING_SENTENCE,
	'a second subscription for the same site is refused before the charge');
$site = ServicesConnect::sitesFor((int)$buyer->key)[0] ?? array();
check(JoineryServicesFulfillment::card_state($site, $at(25), 21) === 'renewing', 'the connected sites page shows it renewing');

$sub->set('odi_subscription_period_end', $at(61));
$sub->save();
check(JoineryServicesFulfillment::follow_line($mail) === 1 && JoineryServicesFulfillment::follow_line($shelf) === 1,
	'a renewal (the period end moved) moves both dates');
$mail = sss_reload($mail);
check(substr((string)$mail->get('svt_paid_until'), 0, 16) === substr($at(61), 0, 16), 'to the new period end');
check(JoineryServicesFulfillment::follow_line($mail) === 0, 'nothing moves when the line has not');

$sub->set('odi_subscription_status', 'past_due');
$sub->set('odi_subscription_period_end', $at(92));
$sub->save();
check(JoineryServicesFulfillment::follow_line($mail) === 0,
	'a failed renewal (past_due, the next period end already stored) buys nothing');
$sub->set('odi_subscription_status', 'active');
$sub->set('odi_subscription_period_end', $at(61));
$sub->save();

$shelf = sss_reload($shelf);
$shelf->set('svt_state', ServiceTenant::STATE_RELEASED);
$shelf->set('svt_revoked_time', gmdate('Y-m-d H:i:s'));
$shelf->save();
$sub->set('odi_subscription_period_end', $at(75));
$sub->save();
JoineryServicesFulfillment::follow_line($shelf);
$shelf = sss_reload($shelf);
check((string)$shelf->get('svt_state') === ServiceTenant::STATE_RELEASED && $shelf->get('svt_revoked_time') !== null,
	'a renewal leaves a released service released, its retention clock running');
check(substr((string)$shelf->get('svt_paid_until'), 0, 16) === substr($at(75), 0, 16), 'while still taking the date');
list(, $again) = sss_line($buyer, $once, $once_version, (int)$mail->key);
JoineryServicesFulfillment::grant_line($buyer, $again, 1);
check((string)sss_reload($shelf)->get('svt_state') === ServiceTenant::STATE_RELEASED, 'buying again does not bring it back either');
$shelf->set('svt_state', ServiceTenant::STATE_ACTIVE);
$shelf->set('svt_revoked_time', null);
$shelf->set('svt_odi_order_item_id', (int)$sub->key);
$shelf->save();
$mail = sss_reload($mail);
$mail->set('svt_odi_order_item_id', (int)$sub->key);
$mail->set('svt_paid_until', $at(61));   // the purchase above extended it
$mail->save();
$sub->set('odi_subscription_period_end', $at(61));
$sub->save();

$sub->set('odi_subscription_cancel_at_period_end', true);
$sub->save();
check(!JoineryServicesFulfillment::row_renews($mail), 'set to cancel at period end: no longer renewing (reminders are owed)');
$sub->set('odi_subscription_cancelled_time', gmdate('Y-m-d H:i:s'));
$sub->set('odi_subscription_status', 'canceled');
$sub->set('odi_subscription_period_end', $at(90));
$sub->save();
check(JoineryServicesFulfillment::follow_line($mail) === 0, 'a cancelled line is not followed');
check(substr((string)sss_reload($mail)->get('svt_paid_until'), 0, 16) === substr($at(61), 0, 16),
	'the date stays where it was and will fall into the past on its own');
check($provider->checkAvailability($monthly, 1, 1, $line_data) === null, 'once cancelled, the site may be bought for again');

// ---------------------------------------------------------------------------
section('the reminders');
$mail = sss_reload($mail);
$shelf = sss_reload($shelf);
foreach (array($mail, $shelf) as $row) {
	$row->set('svt_state', ServiceTenant::STATE_ACTIVE);
	$row->set('svt_nudged', null);
	$row->save();
}
$mail->set('svt_figure', 1700);
$mail->set('svt_figure_time', gmdate('Y-m-d H:i:s'));
$mail->save();
$now = gmdate('Y-m-d H:i:s');
$keys = function (array $events) { return array_map(function ($e) { return $e['key']; }, $events); };
$owed = ServicesNudge::owed(array($mail, $shelf), $now);
check($keys($owed) === array('warn:mail:' . substr($now, 0, 7)), 'mail at 85%: one 80% reminder, no date one yet (61 days out)',
	json_encode($keys($owed)));
check(strpos($owed[0]['doors'], 'https://smtp2go.example/ref') !== false && strpos($owed[0]['keeps'], 'keeps working') !== false,
	'it names the own-account door and that the site keeps working');
$mail->set('svt_nudged', json_encode(array($owed[0]['key'] => $now)));
$mail->save();
check(ServicesNudge::owed(array($mail, $shelf), $now) === array(), 'sent once, it is not owed again');
$mail->set('svt_figure', 2000);
$mail->save();
check($keys(ServicesNudge::owed(array($mail, $shelf), $now)) === array('full:mail:' . substr($now, 0, 7)), 'at 100%, the paused one');
$mail->set('svt_figure', 10);
$mail->save();

foreach (array($mail, $shelf) as $row) { $row->set('svt_paid_until', $at(10)); $row->save(); }
$owed = ServicesNudge::owed(array($mail, $shelf), $now);
check($keys($owed) === array('due:' . substr($at(10), 0, 10)), 'ten days out, not renewing: the due reminder', json_encode($keys($owed)));
check(strpos($owed[0]['doors'], 'https://' . $host . '/setup') !== false && strpos($owed[0]['doors'], '$2.99 a month') !== false,
	'with both doors: the site\'s own setup first, then the offer');
foreach (array($mail, $shelf) as $row) { $row->set('svt_paid_until', $at(-1)); $row->set('svt_lapse_time', $at(-1)); $row->save(); }
check($keys(ServicesNudge::owed(array($mail, $shelf), $now)) === array('ended:' . substr($at(-1), 0, 10)), 'the date passed: the ended reminder');
$shelf->set('svt_state', ServiceTenant::STATE_SUSPENDED);
$shelf->set('svt_revoked_time', $now);
$shelf->save();
$owed = ServicesNudge::owed(array($mail, $shelf), $now);
check($keys($owed) === array('stopped:' . substr($now, 0, 10)), 'grace ran out: the stopped reminder', json_encode($keys($owed)));
$shelf->set('svt_state', ServiceTenant::STATE_ACTIVE);
$shelf->set('svt_revoked_time', null);
$shelf->save();

list(, $sub2) = sss_line($buyer, $monthly, $monthly_version, (int)$mail->key, array(
	'odi_is_subscription' => true, 'odi_subscription_status' => 'active', 'odi_subscription_period_end' => $at(10)));
foreach (array($mail, $shelf) as $row) {
	$row->set('svt_odi_order_item_id', (int)$sub2->key);
	$row->set('svt_paid_until', $at(10));
	$row->set('svt_lapse_time', null);
	$row->save();
}
check(ServicesNudge::owed(array($mail, $shelf), $now) === array(), 'a date that renews on its own is owed no reminder');
$idle = clone $mail;
$idle->set('svt_state', ServiceTenant::STATE_UNPAID);
$idle->set('svt_odi_order_item_id', null);
$idle->set('svt_paid_until', $at(5));
$idle->set('svt_nudged', null);
check(array_map(function ($e) { return $e['key']; }, ServicesNudge::owed(array($idle), $now)) === array('due:' . substr($at(5), 0, 10)),
	'a site paid for and never switched on is still owed the date reminder');

// ---------------------------------------------------------------------------
section('the banner: the paid period as its own row');
$offer = array('price' => '$2.99 a month', 'url' => 'https://plane.example/profile/server_manager/services');
check(ServicesStatusPoll::periodRow($at(30), '', 21, $offer) === null, 'outside the window: no row');
$row = ServicesStatusPoll::periodRow($at(10), '', 21, $offer);
check($row !== null && $row['due'] && !$row['urgent'] && strpos($row['used'], 'paid through') === 0,
	'inside the window: a due row, paid through the date', json_encode($row));
check($row['second_label'] === 'keep going, $2.99 a month' && $row['second_url'] === $offer['url'], 'the second door is the offer');
$late = ServicesStatusPoll::periodRow($at(-2), $at(12), 21, $offer);
check($late !== null && $late['urgent'] && strpos($late['used'], 'ended on') === 0 && strpos($late['used'], 'stop on') !== false,
	'past the date: urgent, with the day they stop', json_encode($late));
$stopped = ServicesStatusPoll::periodRow($at(-20), $at(-6), 21, $offer);
check($stopped !== null && strpos($stopped['used'], 'stopped on') === 0, 'past the grace end: they stopped', json_encode($stopped));

$row['action_url'] = 'https://' . $host . '/setup';
check(HostedPlanNotice::level('services', null, array($row)) === 'soon', 'a due row makes the notice soon');
check(HostedPlanNotice::level('services', null, array(array('urgent' => true, 'percent' => 0))) === 'urgent', 'an urgent row makes it urgent');
harness_set_setting_mem('hosted_plan_state', 'services');
harness_set_setting_mem('hosted_plan_until_time', $at(10));
harness_set_setting_mem('hosted_plan_notice', '');
harness_set_setting_mem('hosted_plan_manage_url', '');
harness_set_setting_mem('hosted_plan_allowances', json_encode(array($row)));
$html = HostedPlanNotice::render();
check(strpos($html, 'href="https://' . $host . '/setup"') !== false && strpos($html, 'href="' . $offer['url'] . '"') !== false
	&& strpos($html, '>or<') !== false, 'the banner renders both doors, own accounts first');
harness_set_setting_mem('hosted_plan_until_time', '');
harness_set_setting_mem('hosted_plan_allowances', '[]');
check(strpos(HostedPlanNotice::render(), 'renew on their own') === false, 'a missing date alone is not read as renewing');
harness_set_setting_mem('hosted_plan_state', '');

harness_finish();
