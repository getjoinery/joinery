<?php
/** @joinery-test
 * name: sent_copy_raw_mime
 * tier: safe
 * env: any
 * needs: []
 */

/**
 * The Sent copy a compose send APPENDs to a source mailbox is real MIME.
 *
 * A connected feed whose SMTP does not file its own Sent copy (filesSent=false)
 * gets one APPENDed by MailboxSender::appendSentCopy(), best-effort. The raw it
 * appends must (a) actually assemble — the previous Horde_Mime_Mail::getRaw()
 * threw "No base part set" on every call, so no copy was ever filed and the
 * failure only ever reached the error log — and (b) be the message as sent:
 * pinned Message-ID (the Sent ingest dedups by it), threading headers, both
 * body alternatives, regular and inline attachments.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxSender.php'));
require_once(PathHelper::getComposerAutoloadPath());

$sender = (new ReflectionClass('MailboxSender'))->newInstanceWithoutConstructor();
$build  = new ReflectionMethod('MailboxSender', 'buildRawMime');
$raw_of = function (EmailMessage $m) use ($sender, $build) {
	return $build->invoke($sender, $m);
};

// ---------------------------------------------------------------------------
section('A full compose (HTML + text, attachment, inline image, threading) assembles');
// ---------------------------------------------------------------------------

$message_id = '<sent-copy-' . bin2hex(random_bytes(6)) . '@example.test>';
$bytes      = random_bytes(64);
$png        = "\x89PNG\r\n\x1a\n" . random_bytes(16);

$m = new EmailMessage();
$m->from('alice@example.test', 'Alice Example')
  ->to('bob@example.test', 'Bob')
  ->cc('carol@example.test')
  ->subject('Re: the quarterly numbers')
  ->html('<p>See attached and <img src="cid:chart1"></p>')
  ->text('See attached.')
  ->messageId($message_id)
  ->header('In-Reply-To', '<parent-123@example.test>')
  ->header('References', '<parent-123@example.test>')
  ->attachData($bytes, 'numbers.bin', 'application/octet-stream')
  ->attachInlineData($png, 'chart1', 'chart.png', 'image/png');

$raw = $raw_of($m);
check(is_string($raw) && $raw !== '', 'buildRawMime returns non-empty bytes');
check(strpos($raw, 'Message-ID: ' . $message_id) !== false, 'the pinned Message-ID is the one on the wire (Sent ingest dedups by it)');
check(strpos($raw, 'Subject: Re: the quarterly numbers') !== false, 'Subject carried');
check(strpos($raw, 'From: Alice Example <alice@example.test>') !== false, 'From with display name');
check(strpos($raw, 'To: Bob <bob@example.test>') !== false, 'To carried');
check(strpos($raw, 'Cc: carol@example.test') !== false, 'Cc carried');
check(strpos($raw, 'In-Reply-To: <parent-123@example.test>') !== false, 'threading header carried');
check(strpos($raw, 'References: <parent-123@example.test>') !== false, 'References carried');
check(stripos($raw, 'multipart/alternative') !== false, 'HTML + plain alternative');
check(strpos($raw, 'See attached.') !== false, 'plain alternative present');
check(strpos($raw, 'name=numbers.bin') !== false || strpos($raw, 'name="numbers.bin"') !== false, 'regular attachment named');
check(strpos($raw, 'Content-ID: <chart1>') !== false, 'inline image keeps its Content-ID');
$b64 = chunk_split(base64_encode($bytes), 76, "\r\n");
check(strpos(str_replace("\n", "\r\n", str_replace("\r\n", "\n", $raw)), rtrim($b64)) !== false, 'attachment bytes present base64-encoded');

// Round-trip through an independent parser: the bytes are a well-formed message.
$parsed = Horde_Mime_Part::parseMessage($raw);
check($parsed instanceof Horde_Mime_Part, 'raw parses as MIME');
check($parsed && strtolower($parsed->getType()) === 'multipart/mixed', 'top level is multipart/mixed (bodies + attachment)');
$names = array();
if ($parsed) {
	foreach ($parsed->contentTypeMap() as $id => $type) {
		$part = $parsed->getPart($id);
		if ($part && $part->getName()) $names[] = $part->getName();
	}
}
check(in_array('numbers.bin', $names, true), 'parser sees the attachment');
check(in_array('chart.png', $names, true), 'parser sees the inline image');

// ---------------------------------------------------------------------------
section('A text-only message assembles too');
// ---------------------------------------------------------------------------

$t = new EmailMessage();
$t->from('alice@example.test')->to('bob@example.test')->subject('plain')->text('just words');
$raw_t = $raw_of($t);
check(is_string($raw_t) && $raw_t !== '', 'text-only raw is non-empty');
check(stripos($raw_t, 'Content-Type: text/plain') !== false, 'text-only is text/plain');
check(strpos($raw_t, 'just words') !== false, 'body present');

// ---------------------------------------------------------------------------
section('The bug class cannot come back');
// ---------------------------------------------------------------------------

$src = file_get_contents(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxSender.php'));
check(strpos($src, 'new Horde_Mime_Mail') === false, 'MailboxSender never instantiates Horde_Mime_Mail (getRaw() needs a send() first)');
check(strpos($src, '->getRaw(') === false, 'MailboxSender never calls Horde getRaw()');
check(strpos($src, 'getSentMIMEMessage()') !== false, 'the Sent copy comes from the SmtpMailer assembly every SMTP send uses');

harness_finish();
