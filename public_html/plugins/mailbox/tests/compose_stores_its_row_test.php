<?php
/** @joinery-test
 * name: compose_stores_its_row
 * tier: safe
 * env: any
 * needs: []
 */

/**
 * A compose send always stores its own outbound row
 * (specs/mailbox_compose_always_stores_its_row.md).
 *
 * The catalog used to claim Gmail renames a message on the way out, so a Gmail
 * send stored nothing and waited for the Sent ingest to surface it. The claim was
 * false — every provider in the catalog preserves the Message-ID it is handed —
 * and acting on it meant a sent message could vanish entirely when the ingest
 * never came.
 *
 * This is a mechanical guard, not a send test: it fails if the per-provider claim
 * or the return that skipped the store comes back. What it cannot see is a live
 * send, so it is deliberately narrow about what it asserts.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));

// ---------------------------------------------------------------------------
section('The catalog makes no claim about rewritten Message-IDs');
// ---------------------------------------------------------------------------

$claimed = array();
foreach (InboundImapAccount::PRESETS as $key => $preset) {
	if (array_key_exists('smtp_rewrites_message_id', $preset)) {
		$claimed[] = $key;
	}
}
check(empty($claimed), 'no preset declares smtp_rewrites_message_id',
	$claimed ? 'declared by: ' . implode(', ', $claimed) : '');
check(!method_exists('InboundImapAccount', 'smtpRewritesMessageId'),
	'and nothing can ask a feed whether its provider rewrites one');

// The capability that survived, and does real work: whether the provider files
// the Sent copy itself or Joinery has to APPEND it.
check(method_exists('InboundImapAccount', 'smtpFilesSent'),
	'the Sent-filing capability is untouched');
$files_sent = array();
foreach (InboundImapAccount::PRESETS as $key => $preset) {
	check(array_key_exists('smtp_files_sent', $preset), $key . ' declares smtp_files_sent');
	if (!empty($preset['smtp_files_sent'])) { $files_sent[] = $key; }
}
check(in_array('imap_gmail', $files_sent, true) && !in_array('imap_generic', $files_sent, true),
	'a hosted provider files its own Sent copy; generic submission does not');

// ---------------------------------------------------------------------------
section('The send path reaches the store');
// ---------------------------------------------------------------------------
// The removed branch returned early, before storeOutboundRow(), whenever the
// claim above was true for the feed. Nothing may report a send as successful
// while having stored nothing.

$sender_src = file_get_contents(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxSender.php'));
check(strpos($sender_src, 'pending_sent_ingest') === false,
	'MailboxSender never reports a send whose row is still to come');

$logic_src = file_get_contents(PathHelper::getIncludePath('plugins/mailbox/logic/send_logic.php'));
check(strpos($logic_src, 'pending_sent_ingest') === false,
	'and the send action does not carry it on the wire');

$reader_src = file_get_contents(PathHelper::getIncludePath('plugins/mailbox/assets/mailbox_reader.js'));
check(strpos($reader_src, 'pending_sent_ingest') === false,
	'nor does the reader treat a rowless send as a sent message');

harness_finish();
