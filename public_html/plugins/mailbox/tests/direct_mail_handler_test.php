<?php
/** @joinery-test
 * name: direct_mail_handler
 * tier: safe
 * env: any
 * needs: []
 */

/**
 * MailDirectHandler's header-part parsing, in the part that decides trust.
 *
 * A Direct message's subject, From display name and threading headers ride in a
 * sealed `message/rfc822-headers` part — free text the sender wrote. The one
 * field that must NOT come from there is the received time: it is the envelope
 * timestamp, which the instance signature covers and the freshness window bounds
 * to within minutes of receipt. Honouring the sender's Date header would let a
 * message pin itself to the top of the inbox with a future date or bury itself
 * with an ancient one — a sort-order forgery SMTP does not allow either, because
 * the receipt stamp is always the receiver's clock.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectEnvelope.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailDirectHandler.php'));

/** Call MailDirectHandler's private static parseHeaderPart. */
function parse_header(string $block, DirectEnvelope $envelope): array {
	$m = new ReflectionMethod('MailDirectHandler', 'parseHeaderPart');
	$m->setAccessible(true);
	return $m->invoke(null, $block, $envelope);
}

/** Call MailDirectHandler's private static assemble. */
function assemble_parts(DirectEnvelope $envelope, array $parts): array {
	$m = new ReflectionMethod('MailDirectHandler', 'assemble');
	$m->setAccessible(true);
	return $m->invoke(null, $envelope, $parts, null);
}

$envelope = DirectEnvelope::fromVerified(array(
	'kind' => 'mail', 'sender' => 'alice@example.com', 'sender_domain' => 'example.com',
	'recipient' => 'bob@receiver.test', 'recipient_user_id' => 7, 'recipient_alias_id' => 3,
	'nonce' => 'abcdef0123456789abcdef0123456789', 'timestamp' => '2026-08-12 10:00:00',
));

// ---------------------------------------------------------------------------
section('The sender\'s Date header cannot set the received time');
// ---------------------------------------------------------------------------

$out = parse_header(
	"Subject: Hello\r\nDate: Fri, 01 Jan 2038 00:00:00 +0000\r\nMessage-ID: <x@example.com>", $envelope);

check(($out['subject'] ?? '') === 'Hello', 'the subject is read from the header part');
check(($out['message_id'] ?? '') === '<x@example.com>', 'so is the Message-ID');
check(!array_key_exists('received_time', $out),
	'but a far-future Date header does NOT become the received time — the envelope timestamp stands');

$out_past = parse_header("Subject: Old\r\nDate: Tue, 01 Jan 1980 00:00:00 +0000", $envelope);
check(!array_key_exists('received_time', $out_past),
	'and neither does an ancient one — the field is never sender-controlled');

// ---------------------------------------------------------------------------
section('The From header is display-only; the address is the envelope\'s');
// ---------------------------------------------------------------------------

$spoof = parse_header("From: Totally Legit <president@whitehouse.gov>", $envelope);
check(($spoof['sender'] ?? '') === 'Totally Legit <alice@example.com>',
	'a From claiming another address keeps only its display name, bound to the verified envelope sender',
	$spoof['sender'] ?? '');

// ---------------------------------------------------------------------------
section('The outbound header block carries Reply-To and always a Message-ID');
// ---------------------------------------------------------------------------

$m = new EmailMessage();
$m->from('alice@example.com')->to('bob@x.test')->subject('Hi')->replyTo('desk@example.com');
$header_block = MailDirectHandler::buildParts($m)[0]['bytes'];

check(strpos($header_block, 'Reply-To: desk@example.com') !== false,
	'a Reply-To the sender set is carried, so a reply does not silently go to From');
check(preg_match('/Message-ID: <[0-9a-f]{32}@example\.com>/', $header_block) === 1,
	'a Message-ID is minted when the caller set none, as the SMTP path would', $header_block);

// A caller-pinned Message-ID is preserved, and never doubled.
$m2 = new EmailMessage();
$m2->from('alice@example.com')->to('bob@x.test')->messageId('<pinned@example.com>');
$hb2 = MailDirectHandler::buildParts($m2)[0]['bytes'];
check(strpos($hb2, 'Message-ID: <pinned@example.com>') !== false, 'a pinned Message-ID is kept verbatim');
check(substr_count($hb2, 'Message-ID:') === 1, 'and it appears exactly once');

// ---------------------------------------------------------------------------
section('A genuine message/rfc822-headers attachment is not swallowed as the headers');
// ---------------------------------------------------------------------------

// The header block is found by its ROLE now, not its content type — so a real
// attachment that happens to be message/rfc822-headers (a forwarded bounce
// report) survives as an attachment instead of being parsed as the mail's own
// headers and vanishing.
$parts = array(
	new DirectPart(array('role' => DirectProtocol::ROLE_HEADERS,
		'content_type' => MailDirectHandler::HEADERS_CONTENT_TYPE,
		'bytes' => "Subject: The real subject\r\nMessage-ID: <real@example.com>")),
	new DirectPart(array('role' => DirectProtocol::ROLE_BODY_TEXT,
		'content_type' => 'text/plain; charset=utf-8', 'bytes' => 'the body')),
	new DirectPart(array('role' => DirectProtocol::ROLE_ATTACHMENT,
		'content_type' => MailDirectHandler::HEADERS_CONTENT_TYPE, 'filename' => 'bounce.hdr',
		'bytes' => "Subject: DECOY do not use\r\nMessage-ID: <decoy@evil>")),
);
$assembled = assemble_parts($envelope, $parts);

check(($assembled['meta']['subject'] ?? '') === 'The real subject',
	'the subject comes from the header block');
check(($assembled['meta']['message_id'] ?? '') === '<real@example.com>',
	'and the Message-ID too — not the attachment that shares the header content type');
check(count($assembled['parts']['attachments']) === 1,
	'the genuine rfc822-headers attachment is kept, not swallowed');
check(($assembled['parts']['attachments'][0]['filename'] ?? '') === 'bounce.hdr',
	'with its own filename intact');

harness_finish();
