<?php
/** @joinery-test
 * name: payment_failure_email_once_per_subscription
 * tier: db
 * env: any
 * needs: []
 * covers: [data/webhook_logs_class.php, plugins/store/ajax/stripe_webhook.php, plugins/store/ajax/paypal_subscription_webhook.php]
 */
/**
 * A failed renewal emails its buyer once a day per subscription, however often
 * the provider retries the charge. Other buyers' failures never hold one back
 * (hosting_after_purchase B1).
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$run = bin2hex(random_bytes(4));
$log = function (string $provider, string $type, $payload) use ($run) {
	static $n = 0;
	$n++;
	$id = 'evt_test_' . $run . '_' . $n;
	WebhookLog::logEvent($provider, $type, $id, $payload, true, null);
	$row = WebhookLog::GetByColumn('wbh_event_id', $id);
	harness_register_row('wbh_webhook_logs', 'wbh_webhook_log_id', $row->key);
};

section('Stripe');
$mine = 'sub_mine_' . $run;
$log('stripe', 'invoice.payment_failed', array('subscription' => 'sub_other1_' . $run));
$log('stripe', 'invoice.payment_failed', array('subscription' => 'sub_other2_' . $run));
check(!WebhookLog::hasRecentPaymentFailure('stripe', 'invoice.payment_failed', $mine),
	'two other buyers\' failures do not hold back this buyer\'s email');
$log('stripe', 'invoice.payment_failed', array('subscription' => $mine));
check(WebhookLog::hasRecentPaymentFailure('stripe', 'invoice.payment_failed', $mine),
	'this subscription\'s earlier failure today does');
check(!WebhookLog::hasRecentPaymentFailure('stripe', 'invoice.payment_failed', ''),
	'a line with no subscription id is never held back');

section('PayPal');
$pp = 'I-MINE' . strtoupper($run);
$log('paypal', 'BILLING.SUBSCRIPTION.PAYMENT.FAILED', array('resource' => array('id' => 'I-OTHER' . $run)));
check(!WebhookLog::hasRecentPaymentFailure('paypal', 'BILLING.SUBSCRIPTION.PAYMENT.FAILED', $pp),
	'another PayPal subscription\'s failure does not count');
$log('paypal', 'BILLING.SUBSCRIPTION.PAYMENT.FAILED', array('resource' => array('id' => $pp)));
check(WebhookLog::hasRecentPaymentFailure('paypal', 'BILLING.SUBSCRIPTION.PAYMENT.FAILED', $pp),
	'its own earlier failure does');

harness_finish();
