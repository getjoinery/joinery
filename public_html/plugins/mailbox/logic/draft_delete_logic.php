<?php
/**
 * API action: mailbox/draft_delete — discard a saved draft.
 *
 * POST /api/v1/action/mailbox/draft_delete (session credential). Param: draft_id. Hard
 * delete: the row, its ima_ attachment manifest, and the backing Files (a discarded draft
 * never lingers in a trash tier — there is no draft trash). Scope is enforced in
 * MailboxDrafts. Returns {deleted:bool}.
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function draft_delete_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxViewer.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxDrafts.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}

	$draft_id = intval($input['draft_id'] ?? 0);
	if ($draft_id <= 0) {
		return LogicResult::error('No draft specified.');
	}

	$viewer = MailboxViewer::fromSession($session);
	$drafts = new MailboxDrafts($viewer);

	try {
		$deleted = $drafts->deleteDraft($draft_id);
	} catch (Throwable $e) {
		error_log('mailbox/draft_delete: ' . $e->getMessage());
		return LogicResult::error('The draft could not be deleted.');
	}

	return LogicResult::render(array('deleted' => (bool)$deleted));
}

function draft_delete_logic_descriptor() {
	return array(
		'requires_session' => true,
		'description' => 'Discard a saved draft (row + attachments + files)',
	);
}
?>
