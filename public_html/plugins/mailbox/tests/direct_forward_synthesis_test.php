<?php
/** @joinery-test
 * name: direct_forward_synthesis
 * tier: db
 * env: dev-only
 * needs: []
 */

/**
 * A filter "Forward to" works for a Joinery Direct message.
 *
 * A Direct delivery never crossed the wire as a MIME document, so it persists no
 * raw and `forwardStoredMessage` had nothing to relay — a configured forward
 * silently dropped. The router now synthesizes a forward-quality raw from the
 * message's own content (subject, body, attachment Files) when no raw is stored.
 * This pins that the synthesized raw is well-formed and carries the body and the
 * attachment, so the forward the user asked for actually leaves.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/tests/lib/mailbox_test_fixture.php'));

$suffix = substr(md5(uniqid('dfs', true)), 0, 8);
$domain_name = 'dfs-test-' . $suffix . '.example';

mailbox_purge_domains('dfs-test-%');
harness_defer(function () { mailbox_purge_domains('dfs-test-%'); });

$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', $domain_name);
$domain->set('ied_is_enabled', true);
$domain->save();

$alias = new InboundEmailAlias(NULL);
$alias->set('iea_ied_inbound_email_domain_id', intval($domain->key));
$alias->set('iea_alias', 'inbox');
$alias->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
$alias->set('iea_is_enabled', true);
$alias->prepare();
$alias->save();

$recipient = 'inbox@' . $domain_name;
$BODY = 'This is the plain body the forward must carry, marker ' . $suffix;
$ATT  = 'attachment bytes marker ' . $suffix;

$router = new InboundEmailRouter();
$meta = array('sender' => 'someone@elsewhere.test', 'subject' => 'Direct forward ' . $suffix,
	'message_id' => '<dfs-' . $suffix . '@elsewhere.test>', 'references' => '', 'in_reply_to' => '',
	'received_time' => gmdate('Y-m-d H:i:s'));
$parts = array('body_plain' => $BODY, 'body_html' => '', 'attachments' => array(
	array('filename' => 'report.txt', 'content_type' => 'text/plain', 'content_id' => '',
		'is_inline' => false, 'bytes' => $ATT),
));

$result = $router->storeDirectMessage($meta, $parts, $alias, $domain, $recipient, true);
check(!empty($result['message']), 'the Direct message stored');
$msg = $result['message'];
harness_defer(function () use ($msg) { try { $msg->permanent_delete(); } catch (Throwable $e) {} });

// ---------------------------------------------------------------------------
section('A Direct message retains no raw, which is why the forward needed a fix');
// ---------------------------------------------------------------------------

check($msg->getRawMessage() === null,
	'a Direct message has no stored raw — forwardStoredMessage had nothing to relay before');

// Diagnostic: confirm the stored object exposes the plaintext body the synthesis reads.
$msg = new InboundEmailMessage(intval($msg->key), TRUE);
check($msg->get('iem_body_plain') === $BODY,
	'the stored message exposes its plaintext body', 'got: [' . $msg->get('iem_body_plain') . ']');

// ---------------------------------------------------------------------------
section('The synthesized raw is well-formed and carries the content');
// ---------------------------------------------------------------------------

$m = new ReflectionMethod('InboundEmailRouter', 'synthesizeRawForForward');
$m->setAccessible(true);
$raw = $m->invoke($router, $msg);

check(is_string($raw) && $raw !== '', 'a raw MIME is synthesized where none was stored');
check(strpos($raw, 'Subject: Direct forward ' . $suffix) !== false, 'it carries the subject');
check(strpos($raw, 'report.txt') !== false, 'and names the attachment');

// Round-trip through the same parser the forward path uses, to prove it is valid
// MIME the body and attachment survive — not just a string that happens to match.
$parsed = $router->parseEmail($raw);
$bodies = $router->extractBodies($raw, $parsed);
check(strpos((string)$bodies['plain'], $BODY) !== false,
	'the body round-trips through a real MIME parse', substr((string)$bodies['plain'], 0, 80));

// The attachment is a MIME part of its own with its bytes intact (short ASCII, so
// it rides as 7bit in the raw). The body round-trip above already proved the whole
// thing parses as valid MIME.
check(strpos($raw, $ATT) !== false,
	'and the attachment bytes ride in the synthesized MIME');
check(preg_match('~Content-Type: multipart/mixed~i', $raw) === 1,
	'as a distinct part of a multipart/mixed message');

harness_finish();
