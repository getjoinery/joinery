<?php
/**
 * Logic for the member Mailbox page (/profile/mailbox/mailbox).
 *
 * Requires a signed-in session — any member, no staff permission. What the
 * member sees is decided entirely by MailboxViewer: the mailboxes they hold
 * grants for (superadmins are all-access). Issues the same persistent
 * per-session reader CSRF token the admin mount uses, so the shared AJAX
 * endpoints accept either mount.
 *
 * @version 1.4.0 - ?open=N hands the reader a message to open (open_message)
 * @version 1.3.0 - no device_ai_site_model: Email settings is where the site's model is offered
 * @version 1.2.0 - device_ai_site_model: the site's own model, offered with one click
 * @version 1.1.0 - device_ai_origin: the member's registered model origin
 * @version 1.0.0
 */

function profile_mailbox_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxService.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::redirect('/login?return=' . urlencode('/profile/mailbox/mailbox'));
	}
	$settings = Globalvars::get_instance();

	// Persistent CSRF token for the reader's state-changing endpoints
	// (validated, not consumed, because the reader fires many actions per
	// session). Same session key as the admin mount — one reader, one token.
	if (empty($_SESSION['mailbox_reader_csrf'])) {
		$_SESSION['mailbox_reader_csrf'] = bin2hex(random_bytes(32));
	}
	$csrf_token = $_SESSION['mailbox_reader_csrf'];

	$viewer = MailboxViewer::fromSession($session);
	$service = new MailboxService($viewer);
	$initial_mailboxes = $service->listMailboxes();

	// listMailboxes() returns {all_access, mailboxes, [all_mail, unmatched]}.
	// An all-access superadmin always has the merged views to look at; anyone
	// else needs at least one granted mailbox or they get the empty state.
	$has_mailboxes = !empty($initial_mailboxes['all_access'])
		|| count($initial_mailboxes['mailboxes'] ?? array()) > 0;

	// ?open=N opens that message's conversation, for a link from another feature (a Boards
	// card's "Open the original email"). Only a message this viewer may read: anything else
	// opens the mailbox as usual and says nothing about whether the message exists.
	$open_message = null;
	$open_id = intval($input['open'] ?? 0);
	if ($open_id > 0) {
		$probe = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT iem_iea_inbound_email_alias_id, iem_thread_key FROM iem_inbound_email_messages
			  WHERE iem_inbound_email_message_id = ? AND iem_delete_time IS NULL');
		$probe->execute(array($open_id));
		$row = $probe->fetch(PDO::FETCH_ASSOC);
		if ($row && intval($row['iem_iea_inbound_email_alias_id']) > 0 && $viewer->canAccess(intval($row['iem_iea_inbound_email_alias_id']))
				&& (string)$row['iem_thread_key'] !== '') {
			$open_message = array('alias_id' => intval($row['iem_iea_inbound_email_alias_id']), 'thread_key' => (string)$row['iem_thread_key']);
			// The subject heads the open conversation; a sealing mailbox's is not readable here.
			$open_alias = new InboundEmailAlias($open_message['alias_id'], TRUE);
			if ($open_alias->key && !$open_alias->seals_content()) {
				$open_message['subject'] = (string)(new InboundEmailMessage($open_id, TRUE))->get('iem_subject');
			}
		}
	}

	return LogicResult::render(array(
		'session'           => $session,
		'settings'          => $settings,
		'csrf_token'        => $csrf_token,
		'open_message'      => $open_message,
		'initial_mailboxes' => $initial_mailboxes,
		'has_mailboxes'     => $has_mailboxes,
		// Where the member's own AI model answers, if they registered one
		// (specs/fortress_mail_device_ai.md § R2): the page names it in its CSP.
		'device_ai_origin'  => MailboxDeviceAiHost::originForUser((int)$session->get_user_id()),
	));
}
?>
