<?php
/**
 * API action: mailbox/mailboxes — the viewer's granted mailboxes.
 *
 * POST /api/v1/action/mailbox/mailboxes (session key). Returns the
 * accessible mailboxes with unread/total counts and folder rails, plus
 * can_compose — the switcher data the native mail screens boot from
 * (specs/implemented/mobile_native_email_server_api_and_ios.md). Same shape as the web reader's switcher
 * feed: MailboxService::listMailboxes() is the single source.
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function mailboxes_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxService.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}

	$viewer = MailboxViewer::fromSession($session);
	$service = new MailboxService($viewer);

	$payload = $service->listMailboxes();
	$payload['can_compose'] = $viewer->canCompose();

	return LogicResult::render($payload);
}

function mailboxes_logic_descriptor() {
	return [
		'requires_session' => true,
		'description' => 'List the granted mailboxes with unread counts, folder rails, and compose capability',
	];
}

?>
