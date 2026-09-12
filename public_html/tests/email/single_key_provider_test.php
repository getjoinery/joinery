<?php
/** @joinery-test
 * name: single_key_provider
 * tier: safe
 * env: any
 * needs: []
 */

/**
 * A provider one API key configures declares the shape of its keys
 * (SingleKeyProvider::apiKeyPattern), and EmailSender::providersForApiKey()
 * turns a pasted key into the providers that could have issued it. What is
 * pinned: every declared pattern compiles; a key of each documented shape
 * resolves to exactly its own provider and no other; a key no shape matches
 * lists every single-key provider (for the live try-in-turn); providers that
 * need more than one credential never appear. No network, no database.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/EmailSender.php'));

$single = array();
foreach (EmailSender::getDiscoveredProviders() as $key => $class) {
	if (in_array('SingleKeyProvider', class_implements($class) ?: array(), true)) {
		$single[$key] = $class;
	}
}

section('Every single-key provider declares a usable pattern');
check(count($single) >= 2, 'at least SMTP2GO and Mailgun opt in', implode(', ', array_keys($single)));
foreach ($single as $key => $class) {
	$pattern = $class::apiKeyPattern();
	check(@preg_match($pattern, '') !== false, $key . ': the pattern compiles', $pattern);
	check(preg_match($pattern, '') !== 1 && preg_match($pattern, 'not a key') !== 1,
		$key . ': the pattern does not match an empty or arbitrary string');
}

section('Providers that need more than one credential are not single-key');
foreach (array('smtp', 'mailjet', 'ses') as $multi) {
	check(!isset($single[$multi]), $multi . ' does not opt in');
}

section('A key of each documented shape resolves to its own provider alone');
$samples = array(
	'smtp2go'  => 'api-ABCDEFGHIJKLMNOPQRSTUVWXYZ012345',
	'mailgun'  => '0123456789abcdef0123456789abcdef-01234567-89abcdef',
	'sendgrid' => 'SG.abcdefghijklmnopqrstuv.abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJK',
	'resend'   => 're_abcdefghijklmnopqrstuvwxyz',
	'brevo'    => 'xkeysib-' . str_repeat('a', 64) . '-abcdefghijklmnop',
	'postmark' => '12345678-1234-1234-1234-123456789abc',
);
$legacy_mailgun = 'key-0123456789abcdef0123456789abcdef';
foreach ($samples as $expect => $sample) {
	if (!isset($single[$expect])) {
		continue;
	}
	$got = array_keys(EmailSender::providersForApiKey($sample));
	check($got === array($expect), $expect . ' key resolves to ' . $expect . ' only', implode(',', $got));
}
if (isset($single['mailgun'])) {
	check(array_keys(EmailSender::providersForApiKey($legacy_mailgun)) === array('mailgun'), 'the older key- Mailgun form still resolves to mailgun');
}

section('A key no shape matches is tried against every single-key provider');
$all = array_keys(EmailSender::providersForApiKey('definitely-not-any-known-shape'));
sort($all);
$expected = array_keys($single);
sort($expected);
check($all === $expected, 'the unknown-shape list is exactly the single-key providers', implode(',', $all));
check(EmailSender::providersForApiKey('') !== array(), 'an empty key still lists them (the caller refuses empty keys itself)');

harness_finish();
