<?php
/**
 * API action: mailbox/send — reply / reply-all / forward AS the mailbox.
 *
 * POST /api/v1/action/mailbox/send (session key). Params: mode
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
 * Compose maturity (specs/mailbox_compose_maturity.md): also accepts optional `bcc`,
 * `body_html` (rich composer; server-sanitized), `inline_manifest` (inline-image
 * local-id => filename), and `draft_id` (morph a saved draft into the Sent row). All
 * optional, so the mobile `mailbox/send` contract stays backward-compatible.
 *
 * A success always carries `sent: true`. When the carrier took the message but
 * its Sent copy could not be stored, it also carries `warning` (and
 * outbound_id 0): the message has left, so this is not an error, and a client
 * that showed one would invite a second send. An unexpected failure is
 * reported as not sent — everything after the hand-off comes back as a
 * result — with a reference that finds its line in the error log.
 *
 * @version 1.4 - `sent` and `warning` on success; an unexpected failure says the message was
 *   not sent and names a log reference
 * @version 1.3.2 - comment wording: Private plus the relay-sealing and sending-lock add-ons
 * @version 1.3.1
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function send_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxViewer.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxSender.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}

	$viewer = MailboxViewer::fromSession($session);
	if (!$viewer->canCompose()) {
		return LogicResult::error('You do not have a mailbox to send from.');
	}

	$params = array(
		'mode'            => $input['mode'] ?? '',
		'source_id'       => $input['source_id'] ?? 0,
		'alias_id'        => $input['alias_id'] ?? 0,
		'to'              => $input['to'] ?? '',
		'cc'              => $input['cc'] ?? '',
		'bcc'             => $input['bcc'] ?? '',
		'subject'         => $input['subject'] ?? '',
		'body'            => $input['body'] ?? '',
		'body_html'       => $input['body_html'] ?? '',
		'inline_manifest' => $input['inline_manifest'] ?? '',
		'draft_id'        => $input['draft_id'] ?? 0,
	);

	$sender = new MailboxSender($viewer);
	$files = MailboxSender::collectUploads();

	try {
		$result = $sender->send($params, $files);
	} catch (MailboxLockedException $e) {
		// sending-lock compose while locked (specs/mailbox_security_levels.md § 4.2):
		// return locked instead of erroring so the native client runs the unlock
		// ceremony and resumes the send, rather than showing a failure.
		return LogicResult::render(array('locked' => true, 'message' => $e->getMessage()));
	} catch (MailboxSenderException $e) {
		return LogicResult::error($e->getMessage());
	} catch (Throwable $e) {
		$ref = MailboxSender::errorReference();
		error_log('mailbox/send [' . $ref . ']: ' . get_class($e) . ': ' . $e->getMessage());
		return LogicResult::error('Your message was not sent: the server hit an unexpected error. It is safe to '
			. 'try again. If it keeps happening, give your administrator this reference: ' . $ref . '.');
	}

	$out = array(
		'sent'        => true,
		'outbound_id' => intval($result['outbound_id']),
	);
	if (!empty($result['warning'])) {
		$out['warning'] = (string)$result['warning'];
	}
	return LogicResult::render($out);
}

function send_logic_descriptor() {
	return [
		'requires_session' => true,
		'description' => 'Send a reply, reply-all, or forward as the mailbox',
		'input' => [
			'alias_id' => ['type' => 'int', 'required' => false, 'label' => 'Mailbox alias ID'],
			'draft_id' => ['type' => 'int', 'required' => false, 'label' => 'Draft ID'],
			'mode' => ['type' => 'string', 'required' => false, 'label' => 'new, reply, reply_all or forward'],
			'source_id' => ['type' => 'int', 'required' => false, 'label' => 'Message being replied to or forwarded'],
			'to' => ['type' => 'string', 'required' => false, 'label' => 'To addresses'],
			'cc' => ['type' => 'string', 'required' => false, 'label' => 'Cc addresses'],
			'bcc' => ['type' => 'string', 'required' => false, 'label' => 'Bcc addresses'],
			'subject' => ['type' => 'string', 'required' => false, 'label' => 'Subject'],
			'body' => ['type' => 'text', 'required' => false, 'label' => 'Plain-text body'],
			'body_html' => ['type' => 'text', 'required' => false, 'label' => 'HTML body'],
			'inline_manifest' => ['type' => 'string', 'required' => false, 'label' => 'Inline image manifest (local-id => filename)'],
		],
	];
}

?>
