<?php
/** @joinery-test
 * name: provider_dkim
 * tier: safe
 * env: dev-only
 * needs: []
 */
/**
 * Provider-aware DKIM (specs/mailbox_provider_dkim.md), core half:
 *
 *  - MailgunProvider::pickApiDomain — the pure choice of which Mailgun sending
 *    domain a raw relay submits through. The API path domain is Mailgun's DKIM
 *    signing identity, so an active account domain matching the envelope
 *    sender wins (DMARC alignment) and everything else falls back to the
 *    configured mailgun_domain.
 *  - DkimRecordSource capability wiring: the API providers that sign outbound
 *    mail report their records; local-submission providers never claim to.
 *
 * No network, no DB writes — pure statics and interface reflection.
 *
 * Run: php tests/run.php safe --filter=provider_dkim
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/EmailServiceProvider.php'));
require_once(PathHelper::getIncludePath('includes/email_providers/MailgunProvider.php'));
require_once(PathHelper::getIncludePath('includes/email_providers/SesProvider.php'));
require_once(PathHelper::getIncludePath('includes/email_providers/SmtpProvider.php'));
require_once(PathHelper::getIncludePath('includes/email_providers/PostfixProvider.php'));

try {

	// -----------------------------------------------------------------------
	section('Mailgun submission-domain pick (DKIM alignment)');

	$fallback = 'mg.site.example';
	check(MailgunProvider::pickApiDomain('customer.example', 'active', $fallback) === 'customer.example',
		'an active account domain wins — Mailgun signs aligned with the From domain');
	check(MailgunProvider::pickApiDomain('customer.example', 'unverified', $fallback) === $fallback,
		'a not-yet-active account domain falls back (submitting through it would fail the send)');
	check(MailgunProvider::pickApiDomain('customer.example', '', $fallback) === $fallback,
		'a domain missing from the account (or a failed lookup) falls back');
	check(MailgunProvider::pickApiDomain('', 'active', $fallback) === $fallback,
		'an envelope with no domain falls back');

	// -----------------------------------------------------------------------
	section('DkimRecordSource capability wiring');

	check(in_array('DkimRecordSource', class_implements('MailgunProvider') ?: array(), true),
		'Mailgun reports its DKIM records');
	check(in_array('DkimRecordSource', class_implements('SesProvider') ?: array(), true),
		'SES reports its DKIM records');
	check(!in_array('DkimRecordSource', class_implements('SmtpProvider') ?: array(), true),
		'SMTP does not claim provider DKIM (local submission — opendkim signs)');
	check(!in_array('DkimRecordSource', class_implements('PostfixProvider') ?: array(), true),
		'Postfix does not claim provider DKIM (local submission — opendkim signs)');

	// The status contract every DkimRecordSource caller relies on.
	$ref = new ReflectionMethod('MailgunProvider', 'getDkimStatus');
	check($ref->isStatic() && $ref->isPublic(), 'getDkimStatus is a public static (setup check calls it by class name)');

} catch (\Throwable $e) {
	check(false, 'uncaught ' . get_class($e), $e->getMessage());
}

harness_finish();
