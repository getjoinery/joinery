<?php
/**
 * Logic for the Inbound Email Mailbox tab.
 *
 * Handles soft-delete and purge-all actions; loads paged messages.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function admin_inbound_email_mailbox_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_message_class.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_domain_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$settings = Globalvars::get_instance();

	$redirect = '/plugins/inbound_email/admin/admin_inbound_email_mailbox';

	if (isset($input['action']) && $input['action'] === 'delete') {
		$id = intval($input['iem_inbound_email_message_id'] ?? 0);
		if ($id > 0) {
			$msg = new InboundEmailMessage($id, TRUE);
			if ($msg->key) {
				$msg->soft_delete();
				$session->save_message(new DisplayMessage(
					'Message deleted.',
					'Deleted',
					'/plugins/inbound_email/admin/',
					DisplayMessage::MESSAGE_ANNOUNCEMENT,
					DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
				));
			}
		}
		return LogicResult::redirect($redirect);
	}

	if (isset($input['action']) && $input['action'] === 'purge_all') {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare("UPDATE iem_inbound_email_messages SET iem_delete_time = NOW() WHERE iem_delete_time IS NULL");
		$stmt->execute();
		$session->save_message(new DisplayMessage(
			'All stored messages purged.',
			'Purged',
			'/plugins/inbound_email/admin/',
			DisplayMessage::MESSAGE_ANNOUNCEMENT,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
		return LogicResult::redirect($redirect);
	}

	// Load domains for filter dropdown
	$domains = new MultiInboundEmailDomain(array('deleted' => false), array('ied_domain' => 'ASC'));
	$domains->load();

	return LogicResult::render(array(
		'session' => $session,
		'settings' => $settings,
		'domains' => $domains,
	));
}
?>
