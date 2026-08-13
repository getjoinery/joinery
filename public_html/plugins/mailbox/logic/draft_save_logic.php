<?php
/**
 * API action: mailbox/draft_save — create or update a compose draft.
 *
 * POST /api/v1/action/mailbox/draft_save (session credential). Params: alias_id (From),
 * draft_id (optional; present on update), mode, source_id, to, cc, bcc, subject,
 * body_html / body, plus an optional multipart `attachments[]` (new files persist onto
 * the draft immediately). Same multipart handling as mailbox/send. Scope is enforced in
 * MailboxDrafts (the alias_id must be a grant the viewer holds). Returns {draft_id}, or
 * {locked:true} when a sealed draft with attachments needs an unlock window it lacks —
 * the client prompts a one-tap unlock, then resaves.
 *
 * @version 1.1.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function draft_save_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxViewer.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxDrafts.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}

	$viewer = MailboxViewer::fromSession($session);
	if (!$viewer->canCompose()) {
		return LogicResult::error('You do not have a mailbox to draft from.');
	}

	$params = array(
		'alias_id'  => $input['alias_id'] ?? 0,
		'draft_id'  => $input['draft_id'] ?? 0,
		'mode'      => $input['mode'] ?? 'new',
		'source_id' => $input['source_id'] ?? 0,
		'to'        => $input['to'] ?? '',
		'cc'        => $input['cc'] ?? '',
		'bcc'       => $input['bcc'] ?? '',
		'subject'   => $input['subject'] ?? '',
		'body_html' => $input['body_html'] ?? '',
		'body'      => $input['body'] ?? '',
		// Local-id => filename map for pasted inline images (Fix 7): a matched upload
		// persists as an inline part carrying its local id as Content-ID.
		'inline_manifest' => $input['inline_manifest'] ?? '',
	);

	$drafts = new MailboxDrafts($viewer);
	$files = MailboxSender::collectUploads();

	try {
		$result = $drafts->saveDraft($params, $files);
	} catch (MailboxDraftsException $e) {
		return LogicResult::error($e->getMessage());
	} catch (Throwable $e) {
		error_log('mailbox/draft_save: ' . $e->getMessage());
		return LogicResult::error('The draft could not be saved.');
	}

	if (!empty($result['locked'])) {
		return LogicResult::render(array('locked' => true,
			'message' => 'Unlock your vault to save this draft with attachments.'));
	}
	// Echo the authoritative persisted attachment set (Fix 3) so the client can drop
	// the files it just sent from its resend queue, plus the saved inline parts (Fix 7).
	return LogicResult::render(array(
		'draft_id'    => intval($result['draft_id']),
		'attachments' => $result['attachments'] ?? array(),
		'inline'      => $result['inline'] ?? array(),
	));
}

function draft_save_logic_descriptor() {
	return array(
		'requires_session' => true,
		'description' => 'Create or update a compose draft (multipart attachments supported)',
	);
}
?>
