<?php
/**
 * API action: mailbox/thread_list — paged threads for a mailbox view.
 *
 * POST /api/v1/action/mailbox/thread_list (session key). Params:
 * alias_id (omit/blank = all accessible), q, unread_only, starred_only,
 * spam, sent, inbox, folder_id, page. Returns {threads, has_more, page} — the
 * exact shapes the web reader's list endpoint serves; every row is scoped
 * by MailboxViewer (specs/implemented/mobile_native_email_server_api_and_ios.md).
 * The `drafts` param switches to the Drafts view (specs/mailbox_compose_maturity.md);
 * `trash` switches to the Trash view (specs/mailbox_trash_folder.md), whose rows
 * carry a purge_time; `sent` switches to the Sent view (conversations carrying
 * an outbound row).
 *
 * @version 1.3.0
 * @changelog 1.3.0 - sent param: the Sent pseudo-folder view
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
		// Sent view: conversations carrying an outbound row — a pseudo-folder
		// like spam, read from the direction column.
		'sent'         => !empty($input['sent']),
		'inbox'        => !empty($input['inbox']),
		// Drafts view (specs/mailbox_compose_maturity.md § Phase 2): the viewer's saved
		// drafts, singletons, excluded from every other view.
		'drafts'       => !empty($input['drafts']),
		// Trash view (specs/mailbox_trash_folder.md): the soft-deleted rows in scope,
		// the only view that shows them.
		'trash'        => !empty($input['trash']),
	);

	$page = isset($input['page']) ? intval($input['page']) : 1;
	$folder_id = isset($input['folder_id']) && $input['folder_id'] !== '' && $input['folder_id'] !== null
		? intval($input['folder_id']) : null;

	return LogicResult::render($service->listThreads($alias_id, $filters, $page, 50, $folder_id));
}

function thread_list_logic_descriptor() {
	return [
		'requires_session' => true,
		'description' => 'List mail threads for a mailbox view (inbox/all/sent/spam/trash, search, labels), paged',
	];
}

?>
