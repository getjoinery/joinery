<?php
/**
 * API action: mailbox/message_source — the original RFC822 source of one message.
 *
 * POST /api/v1/action/mailbox/message_source (session credential). Param:
 * message_id. Returns the stored raw message as text, for the reader's
 * "Show original" view.
 *
 * Authorization is mailbox-grant scope, identical to reading the message body
 * (MailboxViewer): the source is exactly as private as the message it belongs
 * to, and a NULL-alias (catch-all / unmatched) message stays superadmin-only.
 *
 * Not every message has a stored original. InboundEmailMessage::getRawMessage()
 * resolves the storage descriptor (inline / local / cloud), and a
 * reference-backed IMAP row ('remote') keeps no platform copy at all — only its
 * individual parts are fetched on demand. Those cases return
 * {available:false, reason:...} rather than an error, so the modal can say what
 * happened instead of failing.
 *
 * On a protected mailbox the raw may itself be sealed; reading it needs an open
 * unlock window and a locked one returns {locked:true}, the same contract the
 * body and draft reads use.
 *
 * @version 1.0.0
 */

function message_source_logic(array $input): LogicResult {
	// Ceiling on the source handed to the browser. A message carrying
	// attachments is mostly base64, and the whole of one belongs in a download,
	// not in a modal — past this the view says it was cut short.
	$limit = 1048576;

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}

	$id = intval($input['message_id'] ?? 0);
	if ($id <= 0) {
		return LogicResult::error('No message specified.');
	}

	$message = new InboundEmailMessage($id, TRUE);
	if (!$message->key || $message->get('iem_delete_time')) {
		return LogicResult::error('That message no longer exists.');
	}

	// Grant check — the same rule the reader applies to the message itself.
	$viewer = MailboxViewer::fromSession($session);
	$alias_id = intval($message->get('iem_iea_inbound_email_alias_id'));
	$allowed = $alias_id > 0 ? $viewer->canAccess($alias_id) : $viewer->isAllAccess();
	if (!$allowed) {
		return LogicResult::error('You do not have access to this mailbox.');
	}

	try {
		$raw = $message->getRawMessage();
	} catch (VaultLockedException $e) {
		return LogicResult::render(array('locked' => true));
	} catch (Throwable $e) {
		error_log('mailbox/message_source: ' . $e->getMessage());
		return LogicResult::error('The original could not be read.');
	}

	if ($raw === null || $raw === '') {
		$driver = (string)$message->get('iem_raw_storage_driver') ?: 'inline';
		return LogicResult::render(array(
			'available' => false,
			'reason'    => $driver === 'remote'
				? 'This message is read directly from its source mailbox over IMAP, '
					. 'which keeps no copy of the original here.'
				: 'No original was stored for this message.',
		));
	}

	$size = strlen($raw);
	$truncated = $size > $limit;
	if ($truncated) {
		$raw = substr($raw, 0, $limit);
	}

	return LogicResult::render(array(
		'available'  => true,
		'source'     => $raw,
		'size_bytes' => $size,
		'truncated'  => $truncated,
	));
}

function message_source_logic_descriptor() {
	return array(
		'requires_session' => true,
		'description' => 'Return the original RFC822 source of one mail message',
	);
}
?>
