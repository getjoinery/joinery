<?php
/** @joinery-test
 * name: relay_outbound_inbound_only
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Relay inbound-only outbound fork (specs/mailbox_relay_inbound_only.md).
 *
 * The relay defaults to inbound-only: compose sends leave through the configured
 * provider's HTTP-API raw-message path (hiding the origin) instead of the relay
 * smarthost. This test covers the DB-free, injectable cores of that change:
 *
 *  - API-class self-declaration: Mailgun/SES are ApiSubmissionRelay (usable for
 *    the hidden-origin compose path); SmtpProvider is a RawMessageRelay but NOT
 *    ApiSubmissionRelay (excluded — SMTP submission would leak the box IP).
 *  - RawRelayComposeTransport: builds a fully-formed message and hands it to the
 *    injected provider's relayRawMessage() with all envelope recipients (to+cc+bcc)
 *    and the chosen envelope sender; returns true only when every destination
 *    succeeds; refuses (false) when the provider is not API-class; and the message
 *    it generates carries NO box IP or gethostname() (Message-ID derives from the
 *    mail hostname — the ⟨VERIFY⟩ header-generation obligation).
 *  - scanHeadersForOrigin: the origin-leak detector flags the box IP or hostname
 *    in the header block, ignores the (relay-pointing) mail hostname, is clean on
 *    a leak-free message, and matches on token boundaries (the IP never matches
 *    inside a longer IP; the hostname never matches inside a distinct token).
 *
 * Run: php plugins/mailbox/tests/relay_outbound_inbound_only_test.php
 *
 * @version 1.1
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/EmailServiceProvider.php'));
require_once(PathHelper::getIncludePath('includes/EmailMessage.php'));
require_once(PathHelper::getIncludePath('includes/RawRelayComposeTransport.php'));
require_once(PathHelper::getIncludePath('includes/email_providers/MailgunProvider.php'));
require_once(PathHelper::getIncludePath('includes/email_providers/SesProvider.php'));
require_once(PathHelper::getIncludePath('includes/email_providers/SmtpProvider.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailHealth.php'));

/**
 * A capturing stand-in for an API raw-message relay: records the raw MIME,
 * envelope sender, and destinations it is handed, and reports a caller-set result.
 */
class FakeApiRelay implements EmailServiceProvider, ApiSubmissionRelay {
	public $raw = null;
	public $envelope = null;
	public $destinations = null;
	public $result_ok = true;

	public static function getKey(): string { return 'fake-api'; }
	public static function getLabel(): string { return 'Fake API relay'; }
	public static function getSettingsFields(): array { return []; }
	public static function validateConfiguration(): array { return ['valid' => true, 'errors' => []]; }
	public function send(EmailMessage $m): bool { return true; }
	public function sendBatch(EmailMessage $m, array $r): array { return ['success' => true, 'failed_recipients' => []]; }

	public function relayRawMessage(string $raw_mime, string $envelope_sender, array $destinations): array {
		$this->raw = $raw_mime;
		$this->envelope = $envelope_sender;
		$this->destinations = $destinations;
		$out = [];
		foreach ($destinations as $d) { $out[$d] = $this->result_ok; }
		return $out;
	}
}

/** A RawMessageRelay that is NOT API-class (like SmtpProvider) — must be refused. */
class FakeSmtpRelay implements EmailServiceProvider, RawMessageRelay {
	public static function getKey(): string { return 'fake-smtp'; }
	public static function getLabel(): string { return 'Fake SMTP relay'; }
	public static function getSettingsFields(): array { return []; }
	public static function validateConfiguration(): array { return ['valid' => true, 'errors' => []]; }
	public function send(EmailMessage $m): bool { return true; }
	public function sendBatch(EmailMessage $m, array $r): array { return ['success' => true, 'failed_recipients' => []]; }
	public function relayRawMessage(string $raw_mime, string $envelope_sender, array $destinations): array {
		$out = []; foreach ($destinations as $d) { $out[$d] = true; } return $out;
	}
}

// ---------------------------------------------------------------------------
section('API-class self-declaration (ApiSubmissionRelay)');

$mg = new MailgunProvider();
$ses = new SesProvider();
$smtp = new SmtpProvider();

check($mg instanceof ApiSubmissionRelay, 'Mailgun is ApiSubmissionRelay (usable for hidden-origin compose)');
check($ses instanceof ApiSubmissionRelay, 'SES is ApiSubmissionRelay');
check($mg instanceof RawMessageRelay, 'Mailgun still satisfies RawMessageRelay (forwarding unaffected)');
check($smtp instanceof RawMessageRelay, 'SMTP is a RawMessageRelay (forwarding fallback)');
check(!($smtp instanceof ApiSubmissionRelay), 'SMTP is NOT ApiSubmissionRelay — excluded from the hidden-origin path');

// ---------------------------------------------------------------------------
section('RawRelayComposeTransport → provider relayRawMessage');

$fake = new FakeApiRelay();
$msg = (new EmailMessage())
	->from('sender@example.test', 'Sender')
	->to('a@dest.test', 'A')
	->cc('c@dest.test', 'C')
	->bcc('b@dest.test', 'B')
	->subject('hidden-origin compose')
	->text('body');

$transport = new RawRelayComposeTransport('envelope@fwd.example.test', $fake);
$ok = $transport->send($msg);

check($ok === true, 'send() returns true when every destination succeeds');
check(is_string($fake->raw) && $fake->raw !== '', 'provider received a non-empty raw MIME');
check($fake->envelope === 'envelope@fwd.example.test', 'the chosen envelope sender is passed through as MAIL FROM');
$dests = $fake->destinations ?: [];
sort($dests);
check($dests === ['a@dest.test', 'b@dest.test', 'c@dest.test'],
	'all envelope recipients (to + cc + bcc) become destinations', json_encode($dests));

// The generated message carries the From and Subject, and no origin leak.
check(strpos((string)$fake->raw, 'sender@example.test') !== false, 'raw carries the From address');
check(stripos((string)$fake->raw, 'hidden-origin compose') !== false, 'raw carries the Subject');

// ⟨VERIFY⟩ header generation: no box IP, no gethostname() in the generated headers.
$origin_ip = trim((string)Globalvars::get_instance()->get_setting('mailbox_public_ip'));
$header_leaks = InboundEmailHealth::scanHeadersForOrigin((string)$fake->raw, $origin_ip, (string)gethostname());
check(empty($header_leaks), 'generated headers leak neither the box IP nor gethostname()', json_encode($header_leaks));

// ---------------------------------------------------------------------------
section('RawRelayComposeTransport failure + refusal paths');

$fake2 = new FakeApiRelay();
$fake2->result_ok = false;
$msg2 = (new EmailMessage())->from('s@example.test')->to('x@dest.test')->subject('s')->text('b');
check((new RawRelayComposeTransport('', $fake2))->send($msg2) === false,
	'send() returns false when a destination fails at the provider');

$smtp_like = new FakeSmtpRelay();
$msg3 = (new EmailMessage())->from('s@example.test')->to('x@dest.test')->subject('s')->text('b');
check((new RawRelayComposeTransport('', $smtp_like))->send($msg3) === false,
	'send() refuses a non-API (SMTP-class) provider — the origin would leak');

// ---------------------------------------------------------------------------
section('Origin-leak detector (scanHeadersForOrigin)');

$leak_raw = "Received: from mx by devmail.getjoinery.com\n"
	. "X-Origin-Host: leaked at 203.0.113.7 today\n"
	. "Message-ID: <abc@devmail.getjoinery.com>\n\nbody";
$ip_leaks = InboundEmailHealth::scanHeadersForOrigin($leak_raw, '203.0.113.7', 'box-internal.example');
check(count($ip_leaks) === 1 && strpos($ip_leaks[0], '203.0.113.7') !== false,
	'flags the box IP in a header', json_encode($ip_leaks));

$host_leak_raw = "Received: from box-internal.example (10.0.0.5)\n"
	. "Message-ID: <abc@devmail.getjoinery.com>\n\nbody";
$host_leaks = InboundEmailHealth::scanHeadersForOrigin($host_leak_raw, '', 'box-internal.example');
check(!empty($host_leaks), 'flags the internal hostname in a header', json_encode($host_leaks));

$clean_raw = "From: a@example.test\nMessage-ID: <abc@devmail.getjoinery.com>\n"
	. "Received: from mail by devmail.getjoinery.com\n\nbody";
$clean = InboundEmailHealth::scanHeadersForOrigin($clean_raw, '203.0.113.7', 'box-internal.example');
check(empty($clean), 'clean message (mail hostname only) is not flagged', json_encode($clean));

$short = InboundEmailHealth::scanHeadersForOrigin("X: mx\n\nbody", '', 'mx');
check(empty($short), 'a too-short hostname needle is ignored (no false positive)');

// Only the header block is scanned; a leak in the BODY is out of scope.
$body_only = "From: a@example.test\n\nbody mentions 203.0.113.7 here";
check(empty(InboundEmailHealth::scanHeadersForOrigin($body_only, '203.0.113.7', 'box-internal.example')),
	'a match in the body (not headers) is not flagged');

// Token boundaries: the IP needle never matches inside a LONGER IP, and the
// hostname needle never matches inside a distinct token.
$longer_ip = "X-Trace: via 203.0.113.78 and 1203.0.113.7\n\nbody";
check(empty(InboundEmailHealth::scanHeadersForOrigin($longer_ip, '203.0.113.7', '')),
	'the box IP is not flagged inside a longer IP (203.0.113.78 / 1203.0.113.7)');
$embedded_host = "Received: from notbox-internal.example (10.0.0.9)\n\nbody";
check(empty(InboundEmailHealth::scanHeadersForOrigin($embedded_host, '', 'box-internal.example')),
	'the hostname is not flagged inside a distinct token (notbox-internal.example)');
// A longer FQDN BEGINNING with the box hostname still flags — that shape is a
// derived leak, and over-reporting is the safe direction.
$derived_fqdn = "Received: from box-internal.example.lan\n\nbody";
check(!empty(InboundEmailHealth::scanHeadersForOrigin($derived_fqdn, '', 'box-internal.example')),
	'a longer FQDN beginning with the hostname is still flagged (derived leak)');

harness_finish();
