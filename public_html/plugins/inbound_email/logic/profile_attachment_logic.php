<?php
/**
 * Logic for the member per-attachment download endpoint
 * (/profile/inbound_email/attachment).
 *
 * Authorization is mailbox-grant scope for BOTH attachment backings: the
 * viewer may access the alias of the attachment's message (MailboxViewer;
 * NULL-alias messages stay superadmin-only). An attachment is exactly as
 * private as its message — unlike the admin endpoint, file-backed rows are
 * not additionally opened to owner-or-admin here; the grant is the rule.
 *
 * After the gate, retrieval + streaming are the shared helpers
 * (includes/attachment_retrieval.php) — File::read_bytes() for file-backed
 * rows (it deliberately does not authorize; this gate is the authorization),
 * raw MIME extraction or IMAP single-part fetch otherwise.
 *
 * On success this streams and exit()s. On any failure it returns a
 * LogicResult so the view can render an honest message.
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function profile_attachment_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_message_attachment_class.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_message_class.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/MailboxViewer.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/attachment_retrieval.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::redirect('/login?return=' . urlencode('/profile/inbound_email/mailbox'));
	}
	$settings = Globalvars::get_instance();

	$reader_url = '/profile/inbound_email/mailbox';

	$id = intval($input['ima_inbound_message_attachment_id'] ?? 0);
	if ($id <= 0) {
		return _profile_attachment_error($session, $settings, 'No attachment specified.', $reader_url);
	}

	$att = new InboundMessageAttachment($id, TRUE);
	if (!$att->key) {
		return _profile_attachment_error($session, $settings, 'Attachment not found.', $reader_url);
	}

	$message = new InboundEmailMessage(intval($att->get('ima_iem_inbound_email_message_id')), TRUE);
	if (!$message->key || $message->get('iem_delete_time')) {
		return _profile_attachment_error($session, $settings, 'The message for this attachment no longer exists.', $reader_url);
	}

	// Grant check — identical to the reader (an attachment is as private as
	// its message). A NULL-alias (catch-all/unmatched) message is superadmin-only.
	$viewer = MailboxViewer::fromSession($session);
	$alias_id = intval($message->get('iem_iea_inbound_email_alias_id'));
	$allowed = $alias_id > 0 ? $viewer->canAccess($alias_id) : $viewer->isAllAccess();
	if (!$allowed) {
		return _profile_attachment_error($session, $settings, 'You do not have access to this mailbox.', $reader_url);
	}

	$result = inbound_email_retrieve_attachment_bytes($att, $message);
	if (!$result['ok']) {
		return _profile_attachment_error($session, $settings, $result['error'], $reader_url);
	}

	inbound_email_stream_attachment($att, $result['content']);
}

function _profile_attachment_error($session, $settings, string $message, string $reader_url): LogicResult {
	return LogicResult::render(array(
		'session' => $session,
		'settings' => $settings,
		'error' => $message,
		'reader_url' => $reader_url,
	));
}
?>
