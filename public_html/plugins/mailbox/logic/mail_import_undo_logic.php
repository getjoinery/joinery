<?php
/**
 * API action: mailbox/mail_import_undo — reverse a finished import.
 *
 * Permanently deletes every message the run created, and only those. Mail that
 * deduped against something already in the mailbox was never tagged with the run,
 * so an undo cannot remove mail the import did not bring in — and neither can it
 * touch anything that arrived afterwards.
 *
 * The run itself survives as `undone` and keeps its entries, so the report of
 * what happened outlives the reversal.
 *
 * This is destructive and irreversible, which is exactly why it exists: an import
 * that went to the wrong mailbox or brought in the wrong decade should be one
 * action to put right, not a mailbox to weed by hand.
 *
 * @version 1.0
 */

function mail_import_undo_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailImportService.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/import/MailArchiveImporter.php'));

	$session = SessionControl::get_instance();
	if (!intval($session->get_user_id())) {
		return LogicResult::error('Sign in required.');
	}

	$service = MailImportService::fromSession($session);
	$run = $service->loadRun(intval($input['run_id'] ?? 0));
	if ($run === null) {
		return LogicResult::error('That import could not be found.');
	}

	$state = (string)$run->get('mir_state');
	if ($state === MailImportRun::STATE_UNDONE) {
		return LogicResult::error('That import has already been reversed.');
	}
	// Reversing mid-flight would race the task, which is still storing messages
	// and would keep going after the delete. Cancel it first, then reverse.
	if (!in_array($state, array(MailImportRun::STATE_DONE, MailImportRun::STATE_FAILED), true)) {
		return LogicResult::error('Wait for the import to finish before reversing it.');
	}

	$importer = new MailArchiveImporter($run);
	$result = $importer->undo();

	$run->load();
	return LogicResult::render(array(
		'run'     => MailImportService::describe($run),
		'removed' => $result['removed'],
		'failed'  => $result['failed'],
		'message' => 'Removed ' . $result['removed'] . ' imported message(s)'
			. ($result['labels_removed'] > 0 ? ' and ' . $result['labels_removed'] . ' now-empty label(s)' : '')
			. ($result['failed'] > 0 ? '; ' . $result['failed'] . ' could not be removed.' : '.'),
	));
}

/**
 * No 'ai_agent' key, deliberately — and more firmly than for starting an import:
 * this permanently deletes mail. Only a signed-in human reaches it.
 */
function mail_import_undo_logic_descriptor(): array {
	return array(
		'description'      => 'Reverse a finished mail archive import, permanently deleting the messages it created.',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => array(
			'run_id' => array('type' => 'int', 'required' => true, 'label' => 'The import run to reverse'),
		),
	);
}
?>
