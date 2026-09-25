<?php
/** @joinery-test
 * name: fortress_reader_api
 * tier: test-db
 * env: dev-only
 * needs: []
 */
/**
 * What the reader's endpoints hand over for a Fortress message
 * (specs/client_custody_mail.md § R4, WP2).
 *
 *  - the thread list: the newest message's subject, sender and snippet are
 *    empty and travel under `sealed` as ciphertext that the owner's key opens;
 *    the response says `fortress`; no plaintext of the message is in it;
 *  - a thread: every content column under `sealed`, clear fields empty,
 *    `fortress: true`, attachments (inline included) by id and MIME part with
 *    no name, and no signed URL once the native transport pass has run;
 *  - the attachment download hands over the stored ciphertext, flagged, and
 *    the server-side opener refuses the row; text preview is not offered;
 *  - the inline-image rewrite leaves a Fortress body's cid: references alone.
 *
 * Run: php tests/run.php test-db --filter=fortress_reader_api
 *
 * @version 1.1 - reply and forward refuse a Fortress source
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();
require_once(__DIR__ . '/../../../tests/lib/vault_fixtures.php');
require_once(__DIR__ . '/lib/fortress_fixture.php');
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/attachment_retrieval.php'));

if (!extension_loaded('sodium')) {
	harness_skip('sodium extension unavailable');
	harness_finish();
}

try {
	$fx = fortress_fixture('Api');
	$mid = fortress_ingest($fx, 'Fortress quarterly numbers', 'zebracorn');
	check($mid > 0, 'a message arrived at the Fortress mailbox');
	$alias_id = intval($fx['alias']->key);
	$service = new MailboxService(MailboxViewer::forUser($fx['owner_id'], 0));

	/** No plaintext of the message anywhere in a response. */
	$clean = function ($payload): array {
		$json = json_encode($payload);
		$found = array();
		foreach (array('Fortress quarterly', 'zebracorn', 'Secret Sender', 'quarterly-report.pdf', 'figures.csv', 'logo.png') as $needle) {
			if (stripos($json, $needle) !== false) { $found[] = $needle; }
		}
		return $found;
	};

	// ---------------------------------------------------------------- the list
	section('thread_list: the newest message travels sealed');

	$list = $service->listThreads($alias_id, array('inbox' => true));
	check(!empty($list['fortress']), 'the response says fortress');
	$thread = null;
	foreach ($list['threads'] as $t) { if (intval($t['latest_id']) === $mid) { $thread = $t; } }
	check($thread !== null, 'the thread is listed');
	check($thread['subject'] === '' && $thread['sender'] === '' && $thread['snippet'] === '' && $thread['senders'] === '',
		'its clear subject, sender, senders and snippet are empty');
	check(!array_key_exists('ai_summary', $thread), 'it carries no AI summary');
	$sealed = $thread['sealed'] ?? array();
	check(($sealed['sealed_scope'] ?? '') === 'mail' && intval($sealed['key'] ?? 0) === $mid
		&& ($sealed['sealed_ad_prefix'] ?? '') === 'mail:', 'sealed names the row, the mail scope and the AD prefix');
	$found = $clean($list);
	check(empty($found), 'no plaintext of the message is in the response', implode(', ', $found));

	$dek = fortress_open_dek($fx, (string)$sealed['sealed_dek']);
	check(fortress_open_field($fx, $sealed['iem_subject'], $dek, 'mail:' . $mid . ':iem_subject') === 'Fortress quarterly numbers',
		'the owner\'s key opens the subject with the AD the browser builds');
	check(strpos(fortress_open_field($fx, $sealed['iem_snippet'], $dek, 'mail:' . $mid . ':iem_snippet'), 'zebracorn') !== false,
		'and the snippet');

	// ---------------------------------------------------------------- a thread
	section('thread: every content column sealed, parts unnamed');

	$messages = $service->withSignedTransport($service->getThread($alias_id, (string)$thread['thread_key']));
	check(count($messages) === 1, 'one message');
	$m = $messages[0];
	check(!empty($m['fortress']), 'marked fortress');
	check($m['subject'] === '' && $m['sender'] === '' && $m['body_plain'] === '' && $m['body_html'] === '',
		'its clear content fields are empty');
	check($m['recipient'] === strtolower($fx['address']) || $m['recipient'] === $fx['address'],
		'the routing recipient stays in the clear', (string)$m['recipient']);
	foreach (array('iem_sender', 'iem_subject', 'iem_body_plain', 'iem_body_html', 'iem_attachment_manifest') as $col) {
		check(strncmp((string)($m['sealed'][$col] ?? ''), 'v1.edge.', 8) === 0, $col . ' travels as v1.edge.');
	}
	check(strpos(fortress_open_field($fx, $m['sealed']['iem_body_plain'], $dek, 'mail:' . $mid . ':iem_body_plain'), 'zebracorn') !== false,
		'and the body opens under the owner\'s key');
	$found = $clean($messages);
	check(empty($found), 'no plaintext of the message is in the thread payload', implode(', ', $found));

	check(count($m['attachments']) === 3, 'three parts listed, the inline image included');
	$inline = array_values(array_filter($m['attachments'], function ($a) { return !empty($a['inline']); }));
	check(count($inline) === 1, 'one of them inline');
	$unnamed = true; $unsigned = true;
	foreach ($m['attachments'] as $a) {
		if (array_key_exists('filename', $a) || array_key_exists('content_type', $a)) { $unnamed = false; }
		if ($a['url'] !== null) { $unsigned = false; }
	}
	check($unnamed, 'no part carries a name or a type');
	check($unsigned, 'and none gets a signed URL: a sessionless client has no key to open it');
	check($m['original_source'] === 'none', 'no original is offered');

	// ---------------------------------------------------------------- attachments
	section('the attachment endpoint hands over ciphertext');

	$att = new InboundMessageAttachment(intval($m['attachments'][0]['id']), TRUE);
	$msg = new InboundEmailMessage($mid, TRUE);
	$got = mailbox_retrieve_attachment_bytes($att, $msg);
	check($got['ok'] && !empty($got['browser_sealed']), 'retrieval answers with the stored bytes, flagged browser-sealed');
	check(strncmp((string)$got['content'], 'v1.edge.', 8) === 0, 'which are v1.edge. ciphertext');
	$refused = null;
	try { InboundEmailMessage::openSealedAttachment($msg, $att, (string)$got['content']); }
	catch (MailboxBrowserSealedException $e) { $refused = $e->getMessage(); }
	check($refused !== null, 'the server-side opener refuses a Fortress attachment');

	$logic_src = (string)file_get_contents(PathHelper::getIncludePath('plugins/mailbox/includes/attachment_retrieval.php'));
	check(strpos($logic_src, "\$browser_sealed ? 'Content-Disposition: attachment'") !== false,
		'the stream names no file for a browser-sealed part');

	// ---------------------------------------------------------------- reply
	section('reply and forward refuse a Fortress source until the browser writes them');

	$sender = new MailboxSender(MailboxViewer::forUser($fx['owner_id'], 0));
	$load = new ReflectionMethod('MailboxSender', 'loadSourceInScope');
	$refused = null;
	try { $load->invoke($sender, $mid); } catch (MailboxSenderException $e) { $refused = $e->getMessage(); }
	check($refused !== null && stripos($refused, 'end-to-end') !== false,
		'the sender refuses the source with a clear message', (string)$refused);
	$reader_src = (string)file_get_contents(PathHelper::getIncludePath('plugins/mailbox/assets/mailbox_reader.js'));
	check(strpos($reader_src, "latest.alias_id != null && !latest.sealed") !== false,
		'and the reader offers no reply chips on one');

	// ---------------------------------------------------------------- inline images
	section('the inline-image rewrite leaves a Fortress body alone');

	$html = '<p>x</p><img src="cid:logo123@elsewhere.example">';
	$out = MailboxService::resolveInlineImages(array(array('id' => $mid, 'body_html' => $html, 'fortress' => true)));
	check($out[0]['body_html'] === $html, 'a message marked fortress is untouched');
	$out = MailboxService::resolveInlineImages(array(array('id' => $mid, 'body_html' => $html)));
	check($out[0]['body_html'] === $html, 'and even unmarked, a Fortress row has no Content-ID in the clear to map');

} catch (\Throwable $e) {
	check(false, 'EXCEPTION', get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
