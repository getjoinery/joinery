<?php
/** @joinery-test
 * name: email_provider_config
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Email sending-provider abstraction + configuration, read-only (no mail sent).
 *
 * Two layers:
 *  1. The provider registry — EmailSender auto-discovers provider classes and
 *     exposes them by key. This is code-driven and environment-independent, so
 *     it is asserted unconditionally: the registry lists mailgun and smtp, hands
 *     back labels and settings fields for known keys, and returns nothing for an
 *     unknown key. The configured active provider resolves to a real instance.
 *  2. Per-provider config well-formedness — asserted only for providers this
 *     install actually configured (a set primary credential); an unconfigured
 *     provider is a SKIP, never a failure, so the test is green on any install.
 *
 * Replaces the read-only ServiceTests config coverage of the retired
 * tests/email/suites framework. The real-send checks move to the live tier
 * (email_send_delivery_test); nothing here touches the network.
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/EmailServiceProvider.php'));
require_once(PathHelper::getIncludePath('includes/EmailSender.php'));

$settings = Globalvars::get_instance();

section('provider registry (code-driven, environment-independent)');
EmailSender::resetProviderCache();

$services = EmailSender::getAvailableServices();
check(is_array($services) && count($services) > 0, 'getAvailableServices returns a non-empty registry', 'count: ' . count($services));
check(isset($services['mailgun']), 'registry includes mailgun');
check(isset($services['smtp']), 'registry includes smtp');
check(isset($services['smtp2go']), 'registry includes smtp2go');
check(isset($services['mailgun']) && is_string($services['mailgun']) && $services['mailgun'] !== '', 'mailgun has a non-empty label');
check(isset($services['smtp']) && is_string($services['smtp']) && $services['smtp'] !== '', 'smtp has a non-empty label');

$discovered = EmailSender::getDiscoveredProviders();
$classes_exist = true;
foreach ($discovered as $key => $class) {
	if (!class_exists($class)) { $classes_exist = false; break; }
}
check($classes_exist, 'every discovered provider maps to a real class');

// A provider's fields come from its declared group in settings.json, not from
// a method on the provider class, so every path that renders or writes them
// reads the same rules.
$mailgun_fields = EmailSender::getProviderSettings('mailgun');
$smtp_fields    = EmailSender::getProviderSettings('smtp');
check(is_array($mailgun_fields) && count($mailgun_fields) > 0,
	'getProviderSettings returns fields for mailgun', 'count: ' . count($mailgun_fields));
check(is_array($smtp_fields) && count($smtp_fields) > 0,
	'getProviderSettings returns fields for smtp', 'count: ' . count($smtp_fields));
check(EmailSender::getProviderSettings('zz_nonexistent_provider') === [],
	'getProviderSettings for an unknown key returns an empty array (no fabricated fields)');

$named = array_column($mailgun_fields, 'name');
check(in_array('mailgun_api_key', $named, true),
	'the returned fields are declarations, carrying the setting name', implode(', ', $named));
$labelled = array_filter($mailgun_fields, function ($f) { return !empty($f['label']); });
check(count($labelled) === count($mailgun_fields),
	'every provider field carries a label, so a page never has to invent one');

section('the configured active provider resolves to a real instance');

// No provider chosen is a legitimate state, and the whole point of leaving the
// setting empty by default is that the platform says so instead of guessing.
// A guess would send a fresh install's first password reset at a provider
// nobody selected and report that provider's auth error as the reason.
$active_key = EmailSender::activeServiceKey();

if ($active_key === '') {
	$sender = new EmailSender();
	check(EmailSender::getActiveProvider() === null,
		'an unconfigured site resolves to no provider rather than a default one');
	check($sender->getServiceType() === 'none',
		'getServiceType reports none, not a provider that was never chosen');
	$validation = $sender->validateServiceConfiguration();
	check(isset($validation['valid']) && $validation['valid'] === false,
		'validateServiceConfiguration reports invalid when nothing is configured');
	check(!empty($validation['errors'])
		&& stripos(implode(' ', $validation['errors']), 'no email service') !== false,
		'and gives the real reason rather than a credential error',
		implode(' ', $validation['errors'] ?? []));
} else {
	$active = EmailSender::getActiveProvider();
	check($active instanceof EmailServiceProvider, 'getActiveProvider returns an EmailServiceProvider',
		'email_service=' . $active_key);
}
$fallback_key = $settings->get_setting('email_fallback_service');
if ($fallback_key) {
	check(isset($discovered[$fallback_key]), "the fallback provider ('$fallback_key') is a discovered provider");
} else {
	harness_skip('no email_fallback_service configured');
}

section('per-provider config well-formedness (unconfigured providers SKIP)');
// [key => [primary credential setting, [all required settings]]]
$provider_reqs = array(
	'mailgun'  => array('mailgun_api_key', array('mailgun_api_key', 'mailgun_domain')),
	'smtp'     => array('smtp_host',        array('smtp_host', 'smtp_port')),
	'sendgrid' => array('sendgrid_api_key', array('sendgrid_api_key')),
	'postmark' => array('postmark_api_key', array('postmark_api_key')),
	'ses'      => array('ses_access_key',   array('ses_access_key', 'ses_secret_key')),
	'smtp2go'  => array('smtp2go_api_key',  array('smtp2go_api_key')),
);
foreach ($provider_reqs as $key => $spec) {
	list($primary, $required) = $spec;
	if (trim((string)$settings->get_setting($primary)) === '') {
		harness_skip("$key not configured (no $primary)");
		continue;
	}
	$missing = array();
	foreach ($required as $field) {
		if (trim((string)$settings->get_setting($field)) === '') { $missing[] = $field; }
	}
	check(empty($missing), "$key is configured with every required field", 'missing: ' . implode(', ', $missing));
}

harness_finish();
