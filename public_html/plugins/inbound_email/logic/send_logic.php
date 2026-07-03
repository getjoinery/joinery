<?php
/**
 * API action: inbound_email/send — reply / reply-all / forward AS the mailbox.
 *
 * POST /api/v1/action/inbound_email/send (session key). Params: mode
 * (reply|reply_all|forward), source_id, to, cc, subject, body. Same brain as
 * the web reader's send endpoint — MailboxSender resolves the sending
 * identity, quotes the original server-side, applies threading headers, and
 * stores the outbound copy. Per-alias scope is enforced inside MailboxSender
 * (the source message's mailbox must be in the viewer's grants). JSON-only
 * transport, so uploads don't ride along here; forwards still re-attach the
 * original's attachments server-side (specs/mobile_native_email.md).
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function send_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/MailboxViewer.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/MailboxSender.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}

	$viewer = MailboxViewer::fromSession($session);
	if (!$viewer->canCompose()) {
		return LogicResult::error('You do not have a mailbox to send from.');
	}

	$params = array(
		'mode'      => $input['mode'] ?? '',
		'source_id' => $input['source_id'] ?? 0,
		'to'        => $input['to'] ?? '',
		'cc'        => $input['cc'] ?? '',
		'subject'   => $input['subject'] ?? '',
		'body'      => $input['body'] ?? '',
	);

	$sender = new MailboxSender($viewer);

	try {
		$result = $sender->send($params);
	} catch (MailboxSenderException $e) {
		return LogicResult::error($e->getMessage());
	} catch (Throwable $e) {
		error_log('inbound_email/send: ' . $e->getMessage());
		return LogicResult::error('An unexpected error prevented sending.');
	}

	return LogicResult::render(array(
		'outbound_id' => intval($result['outbound_id']),
		'pending_sent_ingest' => !empty($result['pending_sent_ingest']),
	));
}

function send_logic_api() {
	return [
		'requires_session' => true,
		'description' => 'Send a reply, reply-all, or forward as the mailbox',
	];
}

?>
