<?php
/**
 * API action: mailbox/draft_attachment_delete — remove one saved attachment from a draft.
 *
 * POST /api/v1/action/mailbox/draft_attachment_delete (session credential). Params:
 * draft_id, attachment_id. The saved-chip × on a reopened draft (compose maturity fix
 * pack Fix 3): hard-delete the backing File and the ima_ manifest row for one regular
 * (non-inline) attachment. Scope is enforced in MailboxDrafts — the draft is author-owned
 * and the attachment must belong to it. Returns {deleted:bool}.
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function draft_attachment_delete_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxViewer.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxDrafts.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}

	$draft_id = intval($input['draft_id'] ?? 0);
	$attachment_id = intval($input['attachment_id'] ?? 0);
	if ($draft_id <= 0 || $attachment_id <= 0) {
		return LogicResult::error('No attachment specified.');
	}

	$viewer = MailboxViewer::fromSession($session);
	if (!$viewer->canCompose()) {
		return LogicResult::error('You do not have a mailbox to draft from.');
	}

	$drafts = new MailboxDrafts($viewer);
	try {
		$deleted = $drafts->deleteDraftAttachment($draft_id, $attachment_id);
	} catch (Throwable $e) {
		error_log('mailbox/draft_attachment_delete: ' . $e->getMessage());
		return LogicResult::error('The attachment could not be removed.');
	}

	return LogicResult::render(array('deleted' => (bool)$deleted));
}

function draft_attachment_delete_logic_api() {
	return array(
		'requires_session' => true,
		'description' => 'Remove one saved attachment from a draft (file + manifest row)',
	);
}
?>
