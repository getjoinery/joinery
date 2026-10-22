<?php
/** @joinery-test
 * name: outbound_mail_check
 * tier: db
 * env: dev-only
 * needs: []
 * covers: [includes/PluginProvisioning.php]
 */
/**
 * A site that cannot send mail fails its node's health
 * (specs/fleet_security_patching.md WP10): core's own entry in the fleet
 * report says whether a mail service is set and whether its provider's
 * settings are complete, the fallback service counting as the sender counts
 * it. On 2026-10-21 xandyliberato.com had users and no mail service, and
 * nothing said so.
 *
 * Run: php tests/unit/outbound_mail_check_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

section('Can this site send mail');

$none = PluginProvisioning::outboundMailCheck('', '');
check($none['plugin'] === 'core' && $none['key'] === 'outbound_mail' && $none['state'] === 'error'
		&& strpos($none['reason'], 'No mail service is set') === 0,
	'no mail service set: an error, saying what goes unsent', json_encode($none));

$unknown = PluginProvisioning::outboundMailCheck('carrier-pigeon', '');
check($unknown['state'] === 'error' && strpos($unknown['reason'], "'carrier-pigeon' is not one this release carries") !== false,
	'a service this release does not carry: an error naming it', json_encode($unknown));

// A provider whose settings this site does not hold: one dev does not use.
$active = EmailSender::activeServiceKey();
$unset = null;
foreach (array_keys(EmailSender::getAvailableServices()) as $key) {
	$class = get_class(EmailSender::providerFor($key));
	$v = $class::validateConfiguration();
	if ($key !== $active && empty($v['valid'])) { $unset = $key; break; }
}
if ($unset !== null) {
	$r = PluginProvisioning::outboundMailCheck($unset, '');
	check($r['state'] === 'error' && strpos($r['reason'], 'is not set up:') !== false,
		'a provider whose settings are incomplete: an error with its own words (' . $unset . ')', json_encode($r));
	$r = PluginProvisioning::outboundMailCheck($unset, 'carrier-pigeon');
	check($r['state'] === 'error' && strpos($r['reason'], 'is not set up:') !== false
			&& strpos($r['reason'], "its fallback, the mail service 'carrier-pigeon'") !== false,
		'neither the service nor its fallback works: an error naming both', json_encode($r));
}

section('The fallback counts, as the sender counts it');

$working = function (string $key): bool {
	if ($key === '' || !EmailSender::providerFor($key)) { return false; }
	$class = get_class(EmailSender::providerFor($key));
	return !empty($class::validateConfiguration()['valid']);
};
$good = null;
foreach (array_keys(EmailSender::getAvailableServices()) as $key) {
	if ($working($key)) { $good = $key; break; }
}
if ($good !== null) {
	$r = PluginProvisioning::outboundMailCheck('', $good);
	check($r['state'] === 'verified', 'no service named, a working fallback: the sender uses the fallback, so mail leaves (' . $good . ')', json_encode($r));
	if ($unset !== null) {
		$r = PluginProvisioning::outboundMailCheck($unset, $good);
		check($r['state'] === 'verified', 'a service not set up and a working fallback: the sender tries the fallback, so mail leaves', json_encode($r));
	}
} else {
	check(false, 'this site holds one working mail service to test the fallback with');
}

$fallback_setting = trim((string)Globalvars::get_instance()->get_setting('email_fallback_service'));
$r = PluginProvisioning::outboundMailCheck();
check($r['state'] === ($working($active) || $working($fallback_setting) ? 'verified' : 'error'),
	'the site\'s own settings read when none is named, judged by their providers', json_encode($r));

$report = PluginProvisioning::runFleetReport();
$core = array_values(array_filter($report['checks'], function ($c) { return $c['plugin'] === 'core' && $c['key'] === 'outbound_mail'; }));
check(count($core) === 1, 'the fleet report carries it, once, beside the plugins\' checks');

harness_finish();
