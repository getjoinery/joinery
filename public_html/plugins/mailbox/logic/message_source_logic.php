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
 * The original resolves through mailbox_resolve_original()
 * (specs/mailbox_show_original_coverage.md): the stored raw where one exists, a
 * live IMAP fetch for a reference-backed row, or — for a lean record that
 * retained only its header block — a reconstruction (headers + decoded plain
 * body), flagged `reconstructed: true` so the modal labels it. A message with
 * none of those returns {available:false, reason:...} rather than an error, so
 * the modal can say what happened instead of failing.
 *
 * On a protected mailbox the stored raw / headers may themselves be sealed;
 * reading those needs an open unlock window and a locked one returns
 * {locked:true}, the same contract the body and draft reads use.
 *
 * @version 1.1.0
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

	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/message_export.php'));
	try {
		$resolved = mailbox_resolve_original($message);
	} catch (Throwable $e) {
		error_log('mailbox/message_source: ' . $e->getMessage());
		return LogicResult::error('The original could not be read.');
	}
	if ($resolved['locked']) {
		return LogicResult::render(array('locked' => true));
	}
	if (!$resolved['ok']) {
		return LogicResult::render(array(
			'available' => false,
			'reason'    => $resolved['reason'],
		));
	}
	$raw = $resolved['raw'];

	$size = strlen($raw);
	$truncated = $size > $limit;
	if ($truncated) {
		$raw = substr($raw, 0, $limit);
	}

	return LogicResult::render(array(
		'available'     => true,
		'source'        => $raw,
		'size_bytes'    => $size,
		'truncated'     => $truncated,
		// A lean record's answer is headers + decoded plain body, not wire
		// bytes — the modal labels it so nobody mistakes it for the original.
		'reconstructed' => ($resolved['kind'] === 'reconstructed'),
	));
}

function message_source_logic_descriptor() {
	return array(
		'requires_session' => true,
		'description' => 'Return the original RFC822 source of one mail message',
	);
}
?>
