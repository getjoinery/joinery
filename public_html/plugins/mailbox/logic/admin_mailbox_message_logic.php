<?php
/**
 * Logic for the single-message detail view.
 *
 * Handles soft-delete and loads the message + its attachment manifest for
 * display. Soft-deleted messages are not accessible. There is no raw/.eml
 * download (retired for every transport) — the user-facing surface is the body
 * plus the clickable per-attachment list. Inline images: cid: references in
 * the HTML body are rewritten to short-lived signed URLs by the shared
 * MailboxService::resolveInlineImages(), scoped to this message only.
 *
 * @version 1.4
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function admin_mailbox_message_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/VaultUnlock.php')); // declares VaultLockedException
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_message_attachment_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$settings = Globalvars::get_instance();

	$id = intval($input['iem_inbound_email_message_id'] ?? 0);
	if ($id <= 0) {
		return LogicResult::redirect('/plugins/mailbox/admin/admin_mailbox_reader');
	}

	$message = new InboundEmailMessage($id, TRUE);
	if (!$message->key || $message->get('iem_delete_time')) {
		return LogicResult::redirect('/plugins/mailbox/admin/admin_mailbox_reader');
	}

	if (isset($input['action']) && $input['action'] === 'delete') {
		$message->soft_delete();
		$session->save_message(new DisplayMessage(
			'Message deleted.',
			'Deleted',
			'/plugins/mailbox/admin/',
			DisplayMessage::MESSAGE_ANNOUNCEMENT,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
		return LogicResult::redirect('/plugins/mailbox/admin/admin_mailbox_reader');
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

	// Sealed content (specs/implemented/inbound_email_encryption_at_rest.md § 7):
	// gate on KEY POSSESSION, not permission — a permission-10 admin (including
	// via login-as) with no open window for this message's OWNER sees a locked
	// placeholder, same as anyone else. VaultUnlock is keyed to the owning
	// user's session, so an admin viewing someone else's sealed mailbox is
	// locked out by construction, not by a permission check.
	$locked = false;
	$sender = $recipient = $subject = $body_plain = '';
	$body_html = '';
	try {
		$sender = (string)$message->get('iem_sender');
		$recipient = (string)$message->get('iem_recipient');
		$subject = (string)$message->get('iem_subject');
		$body_plain = (string)$message->get('iem_body_plain');
		$body_html = (string)$message->get('iem_body_html');
	} catch (VaultLockedException $e) {
		$locked = true;
	}

	// Inline images: rewrite cid: references in the HTML body to short-lived
	// signed URLs (shared resolver — the permission-5 gate above is the
	// authorization statement for the mint).
	if ($body_html !== '' && !$locked) {
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxService.php'));
		$resolved = MailboxService::resolveInlineImages(array(
			array('id' => $message->key, 'body_html' => $body_html),
		));
		$body_html = $resolved[0]['body_html'];
	}

	return LogicResult::render(array(
		'session' => $session,
		'settings' => $settings,
		'message' => $message,
		'domain_name' => $domain_name,
		'alias_name' => $alias_name,
		'attachments' => $attachments,
		'locked' => $locked,
		'sender' => $sender,
		'recipient' => $recipient,
		'subject' => $subject,
		'body_plain' => $body_plain,
		'body_html' => $body_html,
	));
}
?>
