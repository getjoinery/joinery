<?php
/** @joinery-test
 * name: subscription_emails
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * What the store emails a subscriber about a subscription (SubscriptionEmails,
 * spec subscription_emails):
 *
 *   - a renewal's receipt: once per invoice, for a renewal only, with the
 *     invoice's amount and every line the subscription pays for;
 *   - the cancel and resume confirmations, and the ended notice, each skipped
 *     for a line whose product tells its buyer itself;
 *   - the renewal reminder: once per renewal, for the plans the setting names.
 *
 * Every send goes to the class's mailer seam; nothing is mailed and nothing
 * reaches Stripe.
 *
 * Run: php plugins/store/tests/subscription_emails_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$sent = array();
SubscriptionEmails::$mailer = function ($template, $to, $vars) use (&$sent) {
	$sent[] = array('template' => $template, 'to' => $to, 'vars' => $vars);
	return true;
};
SubscriptionEmails::$now = '2026-12-01 12:00:00';
harness_defer(function () { SubscriptionEmails::$mailer = null; SubscriptionEmails::$now = null; });

/** A provider that tells its buyer itself, as a hosted site's does. */
class SeTellingProvider implements FulfillmentProvider, FulfillmentTellsBuyer {
	public function key(): string { return 'se_telling'; }
	public function label(): string { return 'Tells its buyer'; }
	public function options(): array { return array(); }
	public function extraRequirements(Product $product, int $ref): array { return array(); }
	public function checkAvailability(Product $product, int $ref, int $quantity, array $data = []): ?string { return null; }
	public function fulfill(User $user, Product $product, OrderItem $order_item, Order $order, int $ref): array { return array(); }
	public function displayReference(int $ref): string { return ''; }
	public function tellsBuyerOfEnding(OrderItem $order_item): bool { return true; }
}
FulfillmentRegistry::register(new SeTellingProvider());
harness_defer(function () { FulfillmentRegistry::resetCache(); });

$run = substr(md5(uniqid('se', true)), 0, 8);
$buyer = make_user('SubEmails');

function se_product(string $name, string $run, string $provider = ''): Product {
	$p = new Product(NULL);
	$p->set('pro_name', $name);
	$p->set('pro_link', 'se-' . strtolower(preg_replace('/\W+/', '-', $name)) . '-' . $run);
	$p->set('pro_is_active', false);
	if ($provider !== '') { $p->set('pro_fulfillment_provider', $provider); }
	$p->save();
	$p->load();
	harness_register_row('pro_products', 'pro_product_id', $p->key);
	return $p;
}

function se_version(Product $p, string $period): ProductVersion {
	$v = new ProductVersion(NULL);
	$v->set('prv_pro_product_id', $p->key);
	$v->set('prv_version_name', 'SE ' . $period);
	$v->set('prv_version_price', '10.00');
	$v->set('prv_status', 1);
	$v->set('prv_price_type', $period);
	$v->save();
	$v->load();
	harness_register_row('prv_product_versions', 'prv_product_version_id', $v->key);
	return $v;
}

$order = new Order(NULL);
$order->set('ord_usr_user_id', $buyer->key);
$order->save();
$order->load();
harness_register_row('ord_orders', 'ord_order_id', $order->key);

function se_line(Order $order, $buyer, Product $p, array $fields = array()): OrderItem {
	$item = new OrderItem(NULL);
	$item->set('odi_ord_order_id', $order->key);
	$item->set('odi_pro_product_id', $p->key);
	$item->set('odi_usr_user_id', $buyer->key);
	$item->set('odi_price', '10.00');
	$item->set('odi_is_subscription', true);
	$item->set('odi_subscription_status', 'active');
	$item->set('odi_payment_source', 'stripe');
	foreach ($fields as $k => $v) { $item->set($k, $v); }
	$item->save();
	$item->load();
	harness_register_row('odi_order_items', 'odi_order_item_id', $item->key);
	return $item;
}

function se_invoice(string $id, string $reason, int $cents, array $more = array()) {
	return (object)array_merge(array('id' => $id, 'billing_reason' => $reason, 'amount_paid' => $cents,
		'status_transitions' => (object)array('paid_at' => strtotime('2026-12-01 09:00:00 UTC'))), $more);
}

$hosting = se_product('SE Hosting ' . $run, $run);
$services = se_product('SE Services ' . $run, $run);
$sub = 'sub_se_' . $run;
$a = se_line($order, $buyer, $hosting, array('odi_stripe_subscription_id' => $sub, 'odi_subscription_period_end' => '2027-01-01 09:00:00'));
$b = se_line($order, $buyer, $services, array('odi_stripe_subscription_id' => $sub, 'odi_subscription_period_end' => '2027-01-01 09:00:00'));

harness_set_setting_mem('store_renewal_receipts', '1');
harness_set_setting_mem('store_subscription_ended_emails', '1');
harness_set_setting_mem('store_renewal_reminders', 'year');

section('A renewal is receipted once, with what was charged');

$said = SubscriptionEmails::renewal_paid(se_invoice('in_se_first_' . $run, 'subscription_create', 2000), array($a, $b));
check(!$sent, 'the first payment of a subscription sends no renewal receipt', $said);

$said = SubscriptionEmails::renewal_paid(se_invoice('in_se_1_' . $run, 'subscription_cycle', 1500), array($b, $a));
check($said === 'sent' && count($sent) === 1, 'a renewal sends one receipt', $said);
$mail = $sent[0] ?? array('template' => '', 'to' => '', 'vars' => array());
check($mail['template'] === 'subscription_renewal_receipt' && $mail['to'] === $buyer->get('usr_email'),
	'the receipt template, to the subscriber', $mail['template'] . ' to ' . $mail['to']);
check(strpos((string)($mail['vars']['amount'] ?? ''), '15.00') !== false,
	'the amount is the invoice\'s, not the lines\' prices', (string)($mail['vars']['amount'] ?? ''));
check(strpos((string)($mail['vars']['plan_name'] ?? ''), 'SE Hosting') !== false && strpos((string)$mail['vars']['plan_name'], 'SE Services') !== false,
	'it names every line the subscription pays for', (string)($mail['vars']['plan_name'] ?? ''));
check(strpos((string)($mail['vars']['next_payment_date'] ?? ''), '2027') !== false, 'and the next payment date', (string)($mail['vars']['next_payment_date'] ?? ''));

$said = SubscriptionEmails::renewal_paid(se_invoice('in_se_1_' . $run, 'subscription_cycle', 1500), array($a, $b));
check(count($sent) === 1, 'the second event of the same invoice sends nothing', $said);

$said = SubscriptionEmails::renewal_paid(se_invoice('in_se_2_' . $run, 'subscription_cycle', 1500), array($a, $b));
check(count($sent) === 2, 'the next renewal\'s invoice sends its own receipt', $said);

$said = SubscriptionEmails::renewal_paid(se_invoice('in_se_3_' . $run, 'subscription_cycle', 0), array($a, $b));
check(count($sent) === 2, 'an invoice that charged nothing sends nothing', $said);

$said = SubscriptionEmails::renewal_paid(se_invoice('in_se_4_' . $run, 'subscription_cycle', 1500, array('paid_out_of_band' => true)), array($a, $b));
check(count($sent) === 2, 'nor does one marked paid by hand', $said);

harness_set_setting_mem('store_renewal_receipts', '0');
$said = SubscriptionEmails::renewal_paid(se_invoice('in_se_5_' . $run, 'subscription_cycle', 1500), array($a, $b));
check(count($sent) === 2, 'with receipts switched off nothing goes', $said);
harness_set_setting_mem('store_renewal_receipts', '1');

SubscriptionEmails::$mailer = function () { return false; };
$said = SubscriptionEmails::renewal_paid(se_invoice('in_se_6_' . $run, 'subscription_cycle', 1500), array($a, $b));
SubscriptionEmails::$mailer = function ($template, $to, $vars) use (&$sent) {
	$sent[] = array('template' => $template, 'to' => $to, 'vars' => $vars);
	return true;
};
$said2 = SubscriptionEmails::renewal_paid(se_invoice('in_se_6_' . $run, 'subscription_cycle', 1500), array($a, $b));
check($said !== 'sent' && $said2 === 'sent' && count($sent) === 3, 'a receipt that could not be sent is tried again by the invoice\'s other event', $said . ' / ' . $said2);

section('A failed payment names what the invoice bills');
$vars = SubscriptionEmails::payment_failed_vars(array($a, $b), (object)array('amount_due' => 1298, 'currency' => 'usd'));
check(strpos($vars['tier_name'], 'SE Hosting') !== false && strpos($vars['tier_name'], 'SE Services') !== false && $vars['billing_amount'] === '12.98',
	'both lines and the invoice amount', json_encode($vars));
$vars = SubscriptionEmails::payment_failed_vars(array($a), (object)array('amount_due' => 500, 'currency' => 'jpy'));
check($vars['billing_amount'] === '500', 'a currency with no cents is not divided', json_encode($vars));

section('Cancel, resume and ended');

$sent = array();
$a->set('odi_subscription_cancelled_time', '2027-01-01 09:00:00');
$said = SubscriptionEmails::cancel_confirmed($a);
check($said === 'sent' && ($sent[0]['template'] ?? '') === 'subscription_cancelled', 'a cancel is confirmed', $said);
check(strpos((string)($sent[0]['vars']['access_end_date'] ?? ''), '2027') !== false,
	'with the day it ends', (string)($sent[0]['vars']['access_end_date'] ?? ''));

check(strpos((string)($sent[0]['vars']['tier_name'] ?? ''), 'SE Hosting') !== false && strpos((string)$sent[0]['vars']['tier_name'], 'SE Services') !== false,
	'naming every line the cancelled subscription pays for', (string)($sent[0]['vars']['tier_name'] ?? ''));

$a->set('odi_subscription_cancelled_time', '2026-12-01 12:00:00');
SubscriptionEmails::cancel_confirmed($a);
check(($sent[1]['vars']['access_end_date'] ?? '') === 'Immediately', 'a cancel that ends at once says so', (string)($sent[1]['vars']['access_end_date'] ?? ''));
$a->set('odi_subscription_cancelled_time', null);

$said = SubscriptionEmails::resumed($a);
check($said === 'sent' && ($sent[2]['template'] ?? '') === 'subscription_reactivated', 'a cancel taken back is confirmed', $said);

$said = SubscriptionEmails::ended($a);
check($said === 'sent' && ($sent[3]['template'] ?? '') === 'subscription_expired', 'a subscription that ended is told', $said);

$told_itself = se_line($order, $buyer, se_product('SE Site ' . $run, $run, 'se_telling'), array('odi_stripe_subscription_id' => 'sub_se_t_' . $run));
$before = count($sent);
$c = SubscriptionEmails::cancel_confirmed($told_itself);
$e = SubscriptionEmails::ended($told_itself);
check(count($sent) === $before, 'a line whose product tells its buyer gets no cancel or ended email from the store', $c . ' / ' . $e);

// The site's own line and a services line on one subscription: a cancel pressed on either tells of the services line only.
$shared = 'sub_se_s_' . $run;
$site_line = se_line($order, $buyer, se_product('SE Shared Site ' . $run, $run, 'se_telling'), array('odi_stripe_subscription_id' => $shared));
$svc_line = se_line($order, $buyer, se_product('SE Shared Services ' . $run, $run), array('odi_stripe_subscription_id' => $shared));
$said = SubscriptionEmails::cancel_confirmed($site_line);
$last = end($sent);
check($said === 'sent' && count($sent) === $before + 1 && strpos((string)$last['vars']['tier_name'], 'SE Shared Services') !== false
	&& strpos((string)$last['vars']['tier_name'], 'SE Shared Site') === false,
	'a cancel pressed on a site\'s line still tells of the services line its subscription also pays for', $said . ': ' . (string)$last['vars']['tier_name']);

// A failed payment: the same split, and nothing at all for a site alone.
$ours = SubscriptionEmails::told_by_store(array($site_line, $svc_line));
check(count($ours) === 1 && (int)$ours[0]->key === (int)$svc_line->key,
	'a failed payment\'s email leaves out the line whose product tells its buyer', (string)count($ours));
check(SubscriptionEmails::told_by_store(array($told_itself)) === array(),
	'and a subscription paying only for such a line gets none from the store');
$before = count($sent);

$a->set('odi_subscription_status', 'incomplete_expired');
$said = SubscriptionEmails::ended($a);
check(count($sent) === $before, 'a first payment never made is not an ending to tell', $said);
$a->set('odi_subscription_status', 'active');

harness_set_setting_mem('store_subscription_ended_emails', '0');
$said = SubscriptionEmails::ended($a);
check(count($sent) === $before, 'with ended notices switched off nothing goes', $said);
harness_set_setting_mem('store_subscription_ended_emails', '1');

section('Renewal reminders');

$sent = array();
$yearly = se_product('SE Yearly ' . $run, $run);
$monthly = se_product('SE Monthly ' . $run, $run);
$y = se_line($order, $buyer, $yearly, array('odi_stripe_subscription_id' => 'sub_se_y_' . $run,
	'odi_prv_product_version_id' => se_version($yearly, 'year')->key, 'odi_subscription_period_end' => '2026-12-05 12:00:00'));
$m = se_line($order, $buyer, $monthly, array('odi_stripe_subscription_id' => 'sub_se_m_' . $run,
	'odi_prv_product_version_id' => se_version($monthly, 'month')->key, 'odi_subscription_period_end' => '2026-12-05 12:00:00'));
$far = se_line($order, $buyer, $yearly, array('odi_stripe_subscription_id' => 'sub_se_f_' . $run,
	'odi_prv_product_version_id' => $y->get('odi_prv_product_version_id'), 'odi_subscription_period_end' => '2027-06-01 12:00:00'));
$leaving = se_line($order, $buyer, $yearly, array('odi_stripe_subscription_id' => 'sub_se_l_' . $run,
	'odi_prv_product_version_id' => $y->get('odi_prv_product_version_id'), 'odi_subscription_period_end' => '2026-12-05 12:00:00',
	'odi_subscription_cancel_at_period_end' => true, 'odi_subscription_cancelled_time' => '2026-12-05 12:00:00'));

/** The reminders sent for this run's lines. */
function se_reminders(array $sent, string $run): array {
	return array_values(array_filter($sent, function ($mail) use ($run) {
		return $mail['template'] === 'subscription_renewal_reminder' && strpos((string)$mail['vars']['plan_name'], $run) !== false;
	}));
}

$amp = se_line($order, $buyer, se_product('SE Bed & Board ' . $run, $run), array('odi_stripe_subscription_id' => 'sub_se_amp_' . $run,
	'odi_prv_product_version_id' => $y->get('odi_prv_product_version_id'), 'odi_subscription_period_end' => '2026-12-20 12:00:00'));
$preview = SubscriptionEmails::remind_due(30, true, (int)$order->key);
check(count(preg_grep('/SE Bed & Board ' . $run . '/', $preview['due'])) === 1, 'the dry run\'s list is plain text, for its reader to escape once',
	implode(' | ', preg_grep('/Bed/', $preview['due'])));
$preview = SubscriptionEmails::remind_due(7, true, (int)$order->key);
check(!se_reminders($sent, $run) && count(preg_grep('/SE Yearly ' . $run . '/', $preview['due'])) === 1,
	'a dry run names who would be told and sends nothing', implode(' | ', preg_grep('/' . $run . '/', $preview['due'])));

SubscriptionEmails::remind_due(7, false, (int)$order->key);
$mine = se_reminders($sent, $run);
check(count($mine) === 1 && strpos((string)$mine[0]['vars']['plan_name'], 'SE Yearly') !== false,
	'yearly plans only: the yearly renewal within the week is reminded, the monthly, the far and the cancelled are not',
	count($mine) . ' sent');
check(strpos((string)($mine[0]['vars']['renewal_date'] ?? ''), 'December 5, 2026') !== false, 'with its renewal date', (string)($mine[0]['vars']['renewal_date'] ?? ''));

SubscriptionEmails::remind_due(7, false, (int)$order->key);
check(count(se_reminders($sent, $run)) === 1, 'a second run does not remind the same renewal again');

harness_set_setting_mem('store_renewal_reminders', 'all');
SubscriptionEmails::remind_due(7, false, (int)$order->key);
$mine = se_reminders($sent, $run);
check(count($mine) === 2 && strpos((string)$mine[1]['vars']['plan_name'], 'SE Monthly') !== false, 'every plan: the monthly one is reminded too', count($mine) . ' sent');

$y->load();
$y->set('odi_subscription_period_end', '2026-12-06 12:00:00');
$y->save();
SubscriptionEmails::remind_due(7, false, (int)$order->key);
check(count(se_reminders($sent, $run)) === 3, 'the next renewal of the same line is reminded in its turn');

harness_set_setting_mem('store_renewal_reminders', '0');
$m->load();
$m->set('odi_subscription_period_end', '2026-12-07 12:00:00');
$m->save();
$off = SubscriptionEmails::remind_due(7, false, (int)$order->key);
check(count(se_reminders($sent, $run)) === 3 && !$off['due'], 'switched off, nothing is due');

harness_finish();
