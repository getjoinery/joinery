<?php
/**
 * API action: mailbox/draft_get — the decrypted compose state for a saved draft.
 *
 * POST /api/v1/action/mailbox/draft_get (session credential). Param: draft_id. Returns
 * the compose fields (alias_id, mode, source_id, to, cc, bcc, subject, body_html) plus the
 * attachment list, so the reader can repopulate the composer. On a protected mailbox this
 * needs an open unlock window; a locked draft returns {locked:true} (the reader shows the
 * one-tap unlock affordance, same as reading sealed mail). Scope is enforced in
 * MailboxDrafts — a draft outside the viewer's grants returns an empty result.
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function draft_get_logic(array $input): LogicResult {
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
		$data = $drafts->getDraft($draft_id);
	} catch (Throwable $e) {
		error_log('mailbox/draft_get: ' . $e->getMessage());
		return LogicResult::error('The draft could not be opened.');
	}

	if (empty($data)) {
		return LogicResult::error('That draft no longer exists.');
	}
	if (!empty($data['locked'])) {
		return LogicResult::render(array('locked' => true));
	}
	return LogicResult::render($data);
}

function draft_get_logic_descriptor() {
	return array(
		'requires_session' => true,
		'description' => 'Return the decrypted compose state + attachments for a saved draft',
	);
}
?>
