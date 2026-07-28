<?php
/**
 * API action: mailbox/mail_import_discard — throw away a finished import's archive.
 *
 * A mail archive is routinely hundreds of megabytes, and once an import is done
 * with it there is no reason it should sit on the server until a retention sweep
 * eventually notices. This is the "I am finished with that, take it back" button.
 *
 * It does NOT touch the imported mail. The run keeps its record of what it did, so
 * the report survives; only the source bytes go.
 *
 * An archive picked from the caller's own Drive is released rather than deleted —
 * it is their file, it counts against their quota, and they may well want it. The
 * importer only reclaims what the importer created.
 *
 * @version 1.0
 */

function mail_import_discard_logic(array $input): LogicResult {
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

	// Refused while the run is live: the archive is the only copy of what is being
	// imported, and removing it mid-run strands the run with nothing to read.
	if (!$run->isFinished()) {
		return LogicResult::error('Wait for the import to finish before discarding its archive.');
	}

	$result = (new MailArchiveImporter($run))->discardArchive();
	if (empty($result['ok'])) {
		return LogicResult::error($result['message']);
	}

	$run->load();
	return LogicResult::render(array(
		'run'     => MailImportService::describe($run),
		'freed'   => intval($result['freed']),
		'message' => $result['message'],
	));
}

/**
 * No 'ai_agent' key, deliberately: this deletes a file the user uploaded, and the
 * decision that they are finished with it is theirs to make.
 */
function mail_import_discard_logic_descriptor(): array {
	return array(
		'description'      => 'Delete the source archive of a finished mail import, keeping the imported mail and the run record.',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => array(
			'run_id' => array('type' => 'int', 'required' => true, 'label' => 'The import run'),
		),
	);
}
?>
