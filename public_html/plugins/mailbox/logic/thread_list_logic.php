<?php
/**
 * API action: mailbox/thread_list — paged threads for a mailbox view.
 *
 * POST /api/v1/action/mailbox/thread_list (session key). Params:
 * alias_id (omit/blank = all accessible), q, unread_only, starred_only,
 * spam, inbox, folder_id, page. Returns {threads, has_more, page} — the
 * exact shapes the web reader's list endpoint serves; every row is scoped
 * by MailboxViewer (specs/implemented/mobile_native_email_server_api_and_ios.md).
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function thread_list_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxService.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}

	$viewer = MailboxViewer::fromSession($session);
	$service = new MailboxService($viewer);

	$alias_id = MailboxService::parseAliasParam($input['alias_id'] ?? null);

	$filters = array(
		'q'            => isset($input['q']) ? trim((string)$input['q']) : '',
		'unread_only'  => !empty($input['unread_only']),
		'starred_only' => !empty($input['starred_only']),
		'spam'         => !empty($input['spam']),
		'inbox'        => !empty($input['inbox']),
	);

	$page = isset($input['page']) ? intval($input['page']) : 1;
	$folder_id = isset($input['folder_id']) && $input['folder_id'] !== '' && $input['folder_id'] !== null
		? intval($input['folder_id']) : null;

	return LogicResult::render($service->listThreads($alias_id, $filters, $page, 50, $folder_id));
}

function thread_list_logic_api() {
	return [
		'requires_session' => true,
		'description' => 'List mail threads for a mailbox view (inbox/all/spam, search, labels), paged',
	];
}

?>
