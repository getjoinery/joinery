<?php
/**
 * Logic for the single-message detail view.
 *
 * Handles soft-delete and loads the message + its attachment manifest for
 * display. Soft-deleted messages are not accessible. There is no raw/.eml
 * download (retired for every transport) — the user-facing surface is the body
 * plus the clickable per-attachment list.
 *
 * @version 1.2
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function admin_inbound_email_message_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_message_class.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_domain_class.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_alias_class.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_message_attachment_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$settings = Globalvars::get_instance();

	$id = intval($input['iem_inbound_email_message_id'] ?? 0);
	if ($id <= 0) {
		return LogicResult::redirect('/plugins/inbound_email/admin/admin_inbound_email_reader');
	}

	$message = new InboundEmailMessage($id, TRUE);
	if (!$message->key || $message->get('iem_delete_time')) {
		return LogicResult::redirect('/plugins/inbound_email/admin/admin_inbound_email_reader');
	}

	if (isset($input['action']) && $input['action'] === 'delete') {
		$message->soft_delete();
		$session->save_message(new DisplayMessage(
			'Message deleted.',
			'Deleted',
			'/plugins/inbound_email/admin/',
			DisplayMessage::MESSAGE_ANNOUNCEMENT,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
		return LogicResult::redirect('/plugins/inbound_email/admin/admin_inbound_email_reader');
	}

	// Load referenced domain / alias (best-effort)
	$domain_name = '';
	$dom_id = $message->get('iem_ied_inbound_email_domain_id');
	if ($dom_id) {
		$domain = new InboundEmailDomain($dom_id, TRUE);
		if ($domain->key) {
			$domain_name = $domain->get('ied_domain');
		}
	}

	$alias_name = '';
	$alias_id = $message->get('iem_iea_inbound_email_alias_id');
	if ($alias_id) {
		$alias = new InboundEmailAlias($alias_id, TRUE);
		if ($alias->key) {
			$alias_name = $alias->get('iea_alias');
		}
	}

	// Attachment manifest (real attachments only — inline cid: parts belong to the
	// HTML body and are excluded from the list).
	$attachments = new MultiInboundMessageAttachment(
		array('message_id' => $message->key, 'is_inline' => false),
		array('ima_inbound_message_attachment_id' => 'ASC')
	);
	$attachments->load();

	return LogicResult::render(array(
		'session' => $session,
		'settings' => $settings,
		'message' => $message,
		'domain_name' => $domain_name,
		'alias_name' => $alias_name,
		'attachments' => $attachments,
	));
}
?>
