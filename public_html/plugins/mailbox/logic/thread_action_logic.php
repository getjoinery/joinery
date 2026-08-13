<?php
/**
 * API action: mailbox/thread_action — the reader's state mutations.
 *
 * POST /api/v1/action/mailbox/thread_action (session key). Params:
 * action ∈ {mark_read, mark_unread, star, unstar, delete, archive,
 * unarchive, mark_spam, mark_not_spam, restore, purge, set_membership,
 * create_folder},
 * targets as ids[] (message ids) OR thread_key OR thread_keys[] (each expanded
 * server-side, optionally narrowed by alias_id), plus folder_id/present for
 * set_membership and name for create_folder. thread_keys[] is what the reader's
 * multi-select sends: one request for a whole selection, so a bulk archive is
 * one scope check and one write rather than N of each. Every mutation re-checks scope
 * in SQL — same guarantees as the web reader's action endpoint, same
 * MailboxService brain (specs/implemented/mobile_native_email_server_api_and_ios.md).
 *
 * restore and purge are the two actions that act on a TRASHED message
 * (specs/mailbox_trash_folder.md), so they expand a thread_key under the Trash
 * scope; every other action refuses a discarded row by scope.
 *
 * @version 1.2.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function thread_action_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxService.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}

	$viewer = MailboxViewer::fromSession($session);
	$service = new MailboxService($viewer);

	$action = isset($input['action']) ? (string)$input['action'] : '';
	$alias_id = MailboxService::parseAliasParam($input['alias_id'] ?? null);

	// Resolve target ids: explicit ids[], one thread_key, or a whole selection as
	// thread_keys[] — each expanded server-side and unioned, so a caller can never
	// reach a message by naming a conversation it cannot see.
	// The two trash actions expand under the Trash scope — the read scope cannot
	// see a discarded conversation, so it would resolve to nothing.
	$trashed = ($action === 'restore' || $action === 'purge');
	$ids = array();
	if (isset($input['ids']) && is_array($input['ids'])) {
		foreach ($input['ids'] as $id) {
			$ids[] = intval($id);
		}
	} elseif (isset($input['thread_keys']) && is_array($input['thread_keys'])) {
		$seen = array();
		foreach ($input['thread_keys'] as $key) {
			$key = (string)$key;
			if ($key === '') {
				continue;
			}
			foreach ($service->messageIdsInThread($alias_id, $key, $trashed) as $id) {
				$seen[$id] = true;
			}
		}
		$ids = array_keys($seen);
	} elseif (isset($input['thread_key']) && $input['thread_key'] !== '') {
		$ids = $service->messageIdsInThread($alias_id, (string)$input['thread_key'], $trashed);
	}

	if (!count($ids)) {
		return LogicResult::render(array('count' => 0));
	}

	switch ($action) {
		case 'mark_read':
			$count = $service->markRead($ids, true);
			break;
		case 'mark_unread':
			$count = $service->markRead($ids, false);
			break;
		case 'star':
			$count = $service->setStarred($ids, true);
			break;
		case 'unstar':
			$count = $service->setStarred($ids, false);
			break;
		case 'delete':
			$count = $service->softDelete($ids);
			break;
		case 'archive':
			$count = $service->setArchived($ids, true);
			break;
		case 'unarchive':
			$count = $service->setArchived($ids, false);
			break;
		case 'mark_spam':
			$count = $service->setSpamVerdict($ids, InboundEmailMessage::SPAM_VERDICT_SPAM);
			break;
		case 'mark_not_spam':
			$count = $service->setSpamVerdict($ids, InboundEmailMessage::SPAM_VERDICT_HAM);
			break;
		case 'restore':
			$count = $service->restoreFromTrash($ids);
			break;
		case 'purge':
			$count = $service->purgeFromTrash($ids);
			break;
		case 'set_membership':
			$folder_id = intval($input['folder_id'] ?? 0);
			$present = !empty($input['present']) && $input['present'] !== '0';
			$count = $service->setMembership($ids, $folder_id, $present);
			break;
		case 'create_folder':
			$folder = $service->createFolder(intval($alias_id ?? 0), (string)($input['name'] ?? ''));
			if ($folder === null) {
				return LogicResult::error('The folder could not be created.');
			}
			$count = $service->setMembership($ids, intval($folder['id']), true);
			return LogicResult::render(array('folder' => $folder, 'count' => $count));
		default:
			return LogicResult::error('Unknown thread action.');
	}

	return LogicResult::render(array('count' => $count));
}

function thread_action_logic_descriptor() {
	return [
		'requires_session' => true,
		'description' => 'Mutate mail state: read/star/archive/delete/spam, restore/purge from trash, labels, create-folder',
	];
}

?>
