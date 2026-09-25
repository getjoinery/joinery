<?php
/** @joinery-test
 * name: fortress_server_readers
 * tier: test-db
 * env: dev-only
 * needs: []
 */
/**
 * Every server-side reader of message content leaves a Fortress row alone
 * (specs/client_custody_mail.md § R7): it skips the row, or it throws
 * MailboxBrowserSealedException — none of them opens it, and none of them
 * fails on it the way a reader that tries would.
 *
 * Covered: the AI job selection (with a server window open, so the row-level
 * guard is what excludes it), the security and attachment digests, the AI
 * model query, the server search index, the filter backfill, the message
 * timeline's header block, the promoted-row repair, the attachment backfill,
 * the lowering unseal, the attachment opener, the original / print export,
 * the calendar tool's source line, and "not spam" on a Fortress row.
 *
 * Run: php tests/run.php test-db --filter=fortress_server_readers
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../../../tests/lib/vault_fixtures.php');
// The session starts before anything is printed (harness_test_mode() prints).
if (!extension_loaded('sodium')) {
	harness_skip('sodium extension unavailable');
	harness_finish();
}
if (!vault_apcu_usable() || !vault_ensure_session()) {
	harness_skip('APCu/session unavailable, so no unlock window can be held in CLI');
	harness_finish();
}
harness_test_mode();
require_once(__DIR__ . '/lib/fortress_fixture.php');
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/message_export.php'));

/** Runs $fn and answers whether it threw MailboxBrowserSealedException. */
function fsr_refuses(callable $fn): bool {
	try { $fn(); } catch (MailboxBrowserSealedException $e) { return true; }
	return false;
}

try {
	$db = DbConnector::get_instance()->get_db_link();
	$fx = fortress_fixture('Readers');
	$owner_id = $fx['owner_id'];
	$alias_id = intval($fx['alias']->key);
	$mid = fortress_ingest($fx);
	check($mid > 0, 'a Fortress message is stored');
	$msg = new InboundEmailMessage($mid, TRUE);
	$row = fortress_row($mid);
	check(InboundEmailMessage::isBrowserSealed($row) && InboundEmailMessage::isBrowserSealed($msg),
		'isBrowserSealed() answers for a raw row and a loaded message');

	// A server window open for the owner: the readers below would open a Private
	// row now, so whatever keeps them off this one is the Fortress guard.
	$keys = sodium_crypto_box_keypair();
	$uv = new UserEncryptionVault(NULL);
	$uv->set('uev_usr_user_id', $owner_id);
	$uv->set('uev_public_key', SealedBox::b64url(sodium_crypto_box_publickey($keys)));
	$uv->set('uev_salt', SealedBox::b64url(random_bytes(16)));
	$uv->save();
	harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', intval($uv->key));
	vault_fixture_open_window($owner_id, SealedBox::b64url(sodium_crypto_box_secretkey($keys)));
	harness_defer(function () use ($owner_id) { try { VaultUnlock::lock($owner_id); } catch (\Throwable $e) {} });

	// ---------------------------------------------------------------- AI
	section('server-side AI never reads it');

	if (class_exists('EmailJobCandidates')) {
		check(EmailJobCandidates::nextId(array($alias_id), 0, $owner_id, 0) === null,
			'the email-job selection passes over it, window open or not');
	} else {
		harness_skip('joinery_ai inactive: the job selection is not here to test');
	}
	check(fsr_refuses(function () use ($msg) { EmailSecurityDigest::build($msg); }),
		'the security digest refuses it');
	check(fsr_refuses(function () use ($msg) { EmailAttachmentDigest::build($msg); }),
		'the attachment digest refuses it');
	if (class_exists('ModelQueryExecutor')) {
		$decrypt = new ReflectionMethod('ModelQueryExecutor', 'decryptSealedFields');
		$out = $decrypt->invoke(null, array($row), 'InboundEmailMessage', true);
		check($out === array(), 'the AI model query leaves the row out rather than failing');
	}
	if (is_file(PathHelper::getIncludePath('plugins/joinery_ai/recipe_tools/CreateCalendarEntryTool.php'))) {
		require_once(PathHelper::getIncludePath('plugins/joinery_ai/recipe_tools/CreateCalendarEntryTool.php'));
		$line = new ReflectionMethod('CreateCalendarEntryTool', 'sourceLine');
		$said = (string)$line->invoke(null, array('source_ref' => (string)$mid), $owner_id);
		check(strpos($said, 'end-to-end encrypted') !== false && stripos($said, 'Fortress quarterly') === false,
			'the calendar tool names the source email without reading it', $said);
	}

	// ---------------------------------------------------------------- mailbox
	section('the mailbox\'s own server readers skip it');

	$load = new ReflectionMethod('MailboxIndex', 'loadForIndex');
	check($load->invoke(new MailboxIndex(), $mid) === null, 'the server search index skips it');

	$filter = new InboundEmailFilter(NULL);
	$filter->set('ief_ied_inbound_email_domain_id', intval($fx['domain']->key));
	$filter->set('ief_match_has_words', 'zebracorn');
	check($filter->matches($msg) === false, 'a rule applied to stored mail never matches it');
	check($filter->matches($msg, array(), array('sender' => '', 'subject' => '', 'body_plain' => 'zebracorn',
		'body_html' => '')) === true, 'while at arrival, handed the plaintext, the same rule matches');

	$timeline = new MailboxMessageTimeline($msg, false);
	$header = new ReflectionMethod('MailboxMessageTimeline', 'headerBlock');
	check($header->invoke($timeline) === null, 'the timeline reads no header block');

	check(fsr_refuses(function () use ($msg) { InboundEmailMessage::unsealAndPersistContent($msg); }),
		'the lowering unseal refuses it');
	check(fsr_refuses(function () use ($mid) {
		(new InboundEmailRouter())->resealBackfillAttachments($mid, "Subject: x\r\n\r\nbody", random_bytes(32));
	}), 'the attachment backfill refuses it');
	$atts = new MultiInboundMessageAttachment(array('message_id' => $mid));
	foreach ($atts as $att) {
		check(fsr_refuses(function () use ($msg, $att) { InboundEmailMessage::openSealedAttachment($msg, $att, 'v1.edge.x'); }),
			'the attachment opener refuses it');
		break;
	}
	check(InboundEmailMessage::unwrapDekInWindow($owner_id, (string)$row['iem_sealed_key']) === null,
		'a serve grant never unwraps its key');

	$original = mailbox_resolve_original($msg);
	check(!$original['ok'] && stripos((string)$original['reason'], 'end-to-end') !== false,
		'there is no original to export', (string)$original['reason']);
	check(fsr_refuses(function () use ($msg) { mailbox_print_message($msg); }), 'and no server print sheet');

	// "Not spam" still moves it; no rule can be written for a sender nobody here can read.
	$db->prepare("UPDATE iem_inbound_email_messages SET iem_spam_verdict = 'spam' WHERE iem_inbound_email_message_id = ?")
		->execute(array($mid));
	$service = new MailboxService(MailboxViewer::forUser($owner_id, 0));
	$allowed = $service->allowSender(array($mid));
	check($allowed['count'] === 1 && $allowed['addresses'] === array(), '"not spam" clears it and writes no rule');

	// ---------------------------------------------------------------- promoted-row repair
	section('the promoted-row repair does not take a Fortress Sent copy for debt');

	$sender = new MailboxSender(MailboxViewer::forUser($owner_id, 10));
	$store = new ReflectionMethod('MailboxSender', 'storeOutboundRow');
	$email = new EmailMessage();
	$email->text('outbound body');
	$email->html('<p>outbound body</p>');
	$stored = $store->invoke($sender, null, $fx['alias'], MailboxSender::MODE_NEW, $fx['address'],
		array(array('email' => 'pat@elsewhere.example', 'name' => 'Pat')), array(), array(), 'Sent subject', $email,
		'<fsr-' . bin2hex(random_bytes(5)) . '@' . $fx['domain']->get('ied_domain') . '>', null, null);
	$out = fortress_row(intval($stored['id']));
	check(strncmp((string)$out['iem_recipient'], 'v1.edge.', 8) === 0, 'the Sent copy\'s recipient is v1.edge.');
	check(PromotedRowRepair::hasWork($owner_id) === false, 'and the repair finds no debt in it');

} catch (\Throwable $e) {
	check(false, 'EXCEPTION', get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
