<?php
/**
 * Logic for the single-message detail view.
 *
 * Handles soft-delete and .eml download actions; loads the message for
 * display. Soft-deleted messages are not accessible.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function admin_inbound_email_message_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_message_class.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_domain_class.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_alias_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$settings = Globalvars::get_instance();

	$id = intval($input['iem_inbound_email_message_id'] ?? 0);
	if ($id <= 0) {
		return LogicResult::redirect('/plugins/inbound_email/admin/admin_inbound_email_mailbox');
	}

	$message = new InboundEmailMessage($id, TRUE);
	if (!$message->key || $message->get('iem_delete_time')) {
		return LogicResult::redirect('/plugins/inbound_email/admin/admin_inbound_email_mailbox');
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
		return LogicResult::redirect('/plugins/inbound_email/admin/admin_inbound_email_mailbox');
	}

	if (isset($input['action']) && $input['action'] === 'download_eml') {
		// Stream the raw message as message/rfc822
		$filename = 'inbound_message_' . intval($message->key) . '.eml';
		header('Content-Type: message/rfc822');
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		echo $message->get('iem_raw_message');
		exit();
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

	return LogicResult::render(array(
		'session' => $session,
		'settings' => $settings,
		'message' => $message,
		'domain_name' => $domain_name,
		'alias_name' => $alias_name,
	));
}
?>
