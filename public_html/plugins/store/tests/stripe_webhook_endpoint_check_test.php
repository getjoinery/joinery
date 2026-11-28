<?php
/** @joinery-test
 * name: stripe_webhook_endpoint_check
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * StripeHelper::assess_webhook_endpoints(), which Payment Settings' Run All
 * Validations uses to say whether the account has this site's endpoint with
 * the events and API version the webhook reads.
 *
 * Endpoint lists are written here; Stripe is never called.
 *
 * Run: php tests/run.php safe --filter=stripe_webhook_endpoint_check
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

if (!class_exists('StripeHelper')) {
	harness_skip('the store plugin is not active here', 'StripeHelper does not resolve');
}

$url = 'https://getjoinery.com/ajax/stripe_webhook';
$all = StripeHelper::WEBHOOK_EVENTS;
$no_checkout = array_values(array_diff($all, array('checkout.session.completed')));
$ep = function ($overrides = array()) use ($url, $all) {
	return array_merge(array(
		'url' => $url, 'status' => 'enabled', 'api_version' => '2020-08-27', 'enabled_events' => $all,
	), $overrides);
};
$has = function ($report, $needle) {
	foreach (array_merge($report['problems'], $report['notes']) as $line) {
		if (strpos($line, $needle) !== false) {
			return true;
		}
	}
	return false;
};

section('A matching endpoint passes');
$r = StripeHelper::assess_webhook_endpoints(array($ep()), $url, 'stripe_regular');
check($r['ok'] === true, 'all nine events, 2020-08-27, enabled: ok');
$r = StripeHelper::assess_webhook_endpoints(array($ep(array('url' => 'https://GetJoinery.com/ajax/stripe_webhook/'))), $url, 'stripe_regular');
check($r['ok'] === true, 'host case and a trailing slash do not matter');
$r = StripeHelper::assess_webhook_endpoints(array($ep(array('enabled_events' => array('*')))), $url, 'stripe_checkout');
check($r['ok'] === true, 'an endpoint on every event (*) passes');

section('No endpoint for this site');
$r = StripeHelper::assess_webhook_endpoints(array($ep(array('url' => 'https://dev.getjoinery.com/ajax/stripe_webhook'))), $url, 'stripe_regular');
check($r['ok'] === false, 'another site\'s endpoint does not count');
check($has($r, 'No endpoint for ' . $url), 'says which URL is missing');
check($has($r, 'dev.getjoinery.com'), 'names the endpoints the account does have');
$r = StripeHelper::assess_webhook_endpoints(array(), $url, 'stripe_regular');
check(!$r['ok'] && $has($r, 'no webhook endpoints'), 'an account with none says so');

section('Events follow the checkout style');
$r = StripeHelper::assess_webhook_endpoints(array($ep(array('enabled_events' => $no_checkout))), $url, 'stripe_regular');
check($r['ok'] === true, 'card fields on the site: checkout.session.completed not needed');
$r = StripeHelper::assess_webhook_endpoints(array($ep(array('enabled_events' => $no_checkout))), $url, 'stripe_checkout');
check(!$r['ok'] && $has($r, 'checkout.session.completed'), 'hosted checkout: its event is required');
$r = StripeHelper::assess_webhook_endpoints(array($ep(array('enabled_events' => array('invoice.paid')))), $url, 'stripe_regular');
check(!$r['ok'] && $has($r, 'charge.refunded') && !$has($r, 'invoice.paid,'), 'lists only the missing events');

section('Status and API version');
$r = StripeHelper::assess_webhook_endpoints(array($ep(array('status' => 'disabled'))), $url, 'stripe_regular');
check(!$r['ok'] && $has($r, 'disabled'), 'a disabled endpoint fails');
$r = StripeHelper::assess_webhook_endpoints(array($ep(array('status' => 'disabled')), $ep()), $url, 'stripe_regular');
check($r['ok'] === true, 'an enabled duplicate wins over a disabled one');
$r = StripeHelper::assess_webhook_endpoints(array($ep(array('api_version' => '2025-03-31.basil'))), $url, 'stripe_regular');
check(!$r['ok'] && $has($r, '2025-03-31.basil'), 'basil (2025-03-31) fails');
$r = StripeHelper::assess_webhook_endpoints(array($ep(array('api_version' => '2025-02-24.acacia'))), $url, 'stripe_regular');
check($r['ok'] === true, '2025-02-24 passes');
$r = StripeHelper::assess_webhook_endpoints(array($ep(array('api_version' => null))), $url, 'stripe_regular');
check($r['ok'] === true && $has($r, 'default API version'), 'the account default passes with a note');

harness_finish();
