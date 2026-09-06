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
 * @version 1.1
 * @changelog 1.1 - SMTP2GO: the domain-answer readers every consumer shares
 *   (entryFor / recordsOf / stateOf) and the deliberate absence of an SPF
 *   mechanism.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/EmailServiceProvider.php'));
require_once(PathHelper::getIncludePath('includes/email_providers/MailgunProvider.php'));
require_once(PathHelper::getIncludePath('includes/email_providers/SesProvider.php'));
require_once(PathHelper::getIncludePath('includes/email_providers/SmtpProvider.php'));
require_once(PathHelper::getIncludePath('includes/email_providers/PostfixProvider.php'));
require_once(PathHelper::getIncludePath('includes/email_providers/Smtp2GoProvider.php'));

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

	check(in_array('DkimRecordSource', class_implements('Smtp2GoProvider') ?: array(), true),
		'SMTP2GO reports its DKIM records');
	check(in_array('SendingDomainRegistrar', class_implements('Smtp2GoProvider') ?: array(), true),
		'SMTP2GO registers a sending domain — the wizard\'s DNS stage only runs for a registrar');

	// The status contract every DkimRecordSource caller relies on.
	$ref = new ReflectionMethod('MailgunProvider', 'getDkimStatus');
	check($ref->isStatic() && $ref->isPublic(), 'getDkimStatus is a public static (setup check calls it by class name)');

	// -----------------------------------------------------------------------
	section('SMTP2GO domain answers (the records it actually asks for)');

	// One shape answers /domain/add, /domain/view and /domain/verify: the
	// domain object nested under domains[].domain, trackers beside it. EVERY
	// RECORD IS A CNAME. dkim_value is the TARGET of a CNAME at the provider's
	// selector — not a DKIM key to publish as TXT — and the return-path host is
	// built from rpath_selector, a bare label rather than a hostname. Read
	// wrongly, a sending domain is registered, records that resolve to nothing
	// are published, the domain never verifies, and mail goes out unsigned
	// while every dashboard reads green.
	$answer = array('domains' => array(array(
		'domain' => array(
			'fulldomain'     => 'mail.example.com',
			'dkim_selector'  => 's1234567',
			'dkim_verified'  => true,
			'dkim_value'     => 'dkim.smtp2go.net',
			'rpath_selector' => 'em1234',
			'rpath_verified' => true,
			'rpath_value'    => 'return.smtp2go.net',
		),
		'trackers' => array(array(
			'fulldomain'  => 'link.mail.example.com',
			'cname_value' => 'track.smtp2go.net',
			'enabled'     => true,
		)),
	)));

	$entry = Smtp2GoProvider::entryFor($answer, 'mail.example.com');
	check($entry !== null, 'the domain entry is found under domains[].domain');

	$records = Smtp2GoProvider::recordsOf($entry);
	$by_name = array();
	foreach ($records as $record) { $by_name[$record['name']] = $record; }
	check(count($records) === 3, 'all three records are read', json_encode(array_keys($by_name)));

	$dkim = $by_name['s1234567._domainkey.mail.example.com'] ?? array();
	check(($dkim['type'] ?? '') === 'CNAME' && ($dkim['value'] ?? '') === 'dkim.smtp2go.net',
		'DKIM is a CNAME at selector._domainkey.<domain>, pointing at the provider',
		json_encode($dkim));

	$rpath = $by_name['em1234.mail.example.com'] ?? array();
	check(($rpath['type'] ?? '') === 'CNAME' && ($rpath['value'] ?? '') === 'return.smtp2go.net',
		'the return path is a CNAME at <rpath_selector>.<domain>', json_encode($rpath));
	check(($rpath['purpose'] ?? '') === 'Return-Path',
		'and says what it is for, so the plan does not label every record DKIM');

	check(($by_name['link.mail.example.com']['value'] ?? '') === 'track.smtp2go.net',
		'the tracking CNAME points where the provider said');

	// Tracking is optional and off by default: prescribing a record nobody
	// asked for, or holding a good sending domain at unverified until a
	// tracking CNAME appears, would both be wrong.
	$off = $answer;
	$off['domains'][0]['trackers'][0]['enabled'] = false;
	$off_entry = Smtp2GoProvider::entryFor($off, 'mail.example.com');
	check(count(Smtp2GoProvider::recordsOf($off_entry)) === 2,
		'a tracker that is switched off is not prescribed');
	check(Smtp2GoProvider::stateOf($off_entry) === 'active',
		'and does not hold the domain back from active');

	check(Smtp2GoProvider::stateOf($entry) === 'active',
		'a domain whose DKIM and return path both resolve is active');
	$pending = $answer;
	$pending['domains'][0]['domain']['rpath_verified'] = false;
	check(Smtp2GoProvider::stateOf(Smtp2GoProvider::entryFor($pending, 'mail.example.com')) === 'unverified',
		'an outstanding return path is unverified — a wait, not a failure');

	$many = array('domains' => array(
		array('domain' => array('fulldomain' => 'other.example.com',
			'dkim_selector' => 'sX', 'dkim_value' => 'dkim.smtp2go.net')),
		$answer['domains'][0],
	));
	check((Smtp2GoProvider::entryFor($many, 'mail.example.com')['domain']['fulldomain'] ?? '')
		=== 'mail.example.com', 'the domain asked about wins over the first one listed');

	check(Smtp2GoProvider::entryFor(array('domains' => array()), 'mail.example.com') === null,
		'a domain the account does not hold reads as absent');
	check(Smtp2GoProvider::stateOf(null) === 'not_registered',
		'and an absent domain is not_registered, never a silent pass');
	check(Smtp2GoProvider::recordsOf(null) === array(), 'with no records to publish');

	// A half-formed record is worse than a missing one: it looks published.
	$half = array('domains' => array(array('domain' => array(
		'fulldomain' => 'mail.example.com', 'dkim_selector' => 's1', 'dkim_value' => '',
		'rpath_selector' => '', 'rpath_value' => 'return.smtp2go.net'))));
	check(Smtp2GoProvider::recordsOf(Smtp2GoProvider::entryFor($half, 'mail.example.com')) === array(),
		'a record missing either half is dropped rather than half-published');

	// -----------------------------------------------------------------------
	section('SMTP2GO prescribes no SPF mechanism');

	// Deliberate, and not for want of looking it up: SMTP2GO sends with a
	// return path on the sender's own em###### subdomain, so SPF is evaluated
	// against a record it maintains behind that CNAME. An include: on the From
	// domain would authorize nothing and spend one of SPF's ten lookups.
	check(Smtp2GoProvider::getSpfMechanism('mail.example.com') === '',
		'no include: is prescribed for the From domain');

} catch (\Throwable $e) {
	check(false, 'uncaught ' . get_class($e), $e->getMessage());
}

harness_finish();
