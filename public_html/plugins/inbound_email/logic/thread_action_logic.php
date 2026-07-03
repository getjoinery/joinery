<?php
/**
 * API action: inbound_email/thread_action — the reader's state mutations.
 *
 * POST /api/v1/action/inbound_email/thread_action (session key). Params:
 * action ∈ {mark_read, mark_unread, star, unstar, delete, archive,
 * unarchive, mark_spam, mark_not_spam, set_membership, create_folder},
 * targets as ids[] (message ids) OR thread_key (expanded server-side,
 * optionally narrowed by alias_id), plus folder_id/present for
 * set_membership and name for create_folder. Every mutation re-checks scope
 * in SQL — same guarantees as the web reader's action endpoint, same
 * MailboxService brain (specs/mobile_native_email.md).
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function thread_action_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/MailboxService.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}

	$viewer = MailboxViewer::fromSession($session);
	$service = new MailboxService($viewer);

	$action = isset($input['action']) ? (string)$input['action'] : '';
	$alias_id = MailboxService::parseAliasParam($input['alias_id'] ?? null);

	// Resolve target ids: explicit ids[] or a thread_key expanded server-side.
	$ids = array();
	if (isset($input['ids']) && is_array($input['ids'])) {
		foreach ($input['ids'] as $id) {
			$ids[] = intval($id);
		}
	} elseif (isset($input['thread_key']) && $input['thread_key'] !== '') {
		$ids = $service->messageIdsInThread($alias_id, (string)$input['thread_key']);
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

function thread_action_logic_api() {
	return [
		'requires_session' => true,
		'description' => 'Mutate mail state: read/star/archive/delete/spam, labels, create-folder',
	];
}

?>
