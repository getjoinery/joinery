<?php
/**
 * API action: inbound_email/send — reply / reply-all / forward AS the mailbox.
 *
 * POST /api/v1/action/inbound_email/send (session key). Params: mode
 * (reply|reply_all|forward|new), source_id (reply/reply_all/forward) or
 * alias_id (new), to, cc, subject, body, plus an optional multipart
 * `attachments[]`. Same brain as the web reader's send endpoint —
 * MailboxSender resolves the sending identity, quotes the original
 * server-side (reply/forward only), applies threading headers, attaches
 * uploads, and stores the outbound copy (with an attachment manifest so the
 * sent copy shows what was attached). Per-alias scope is enforced inside
 * MailboxSender: a reply/forward's source message must be in the viewer's
 * grants; a new message's alias_id must itself be a grant
 * (specs/implemented/inbound_email_new_message_compose.md). A multipart
 * POST leaves php://input empty, so the dispatcher falls back to $_POST and
 * PHP fills $_FILES natively — no ApiLogicEndpoint change needed
 * (joinery_ai/chat_send is the shipped precedent). Forwards still re-attach
 * the original's attachments server-side regardless of transport
 * (specs/implemented/inbound_email_compose_attachments.md).
 *
 * @version 1.2.0
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
		'alias_id'  => $input['alias_id'] ?? 0,
		'to'        => $input['to'] ?? '',
		'cc'        => $input['cc'] ?? '',
		'subject'   => $input['subject'] ?? '',
		'body'      => $input['body'] ?? '',
	);

	$sender = new MailboxSender($viewer);
	$files = MailboxSender::collectUploads();

	try {
		$result = $sender->send($params, $files);
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
