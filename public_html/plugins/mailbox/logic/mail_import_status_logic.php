<?php
/**
 * API action: mailbox/mail_import_status — how an import is getting on.
 *
 * With no run id, the caller's recent runs; with one, that run plus — when it has
 * finished scanning — the per-folder counts the choose-what-to-bring screen is
 * built from.
 *
 * This is what the page polls, so it is deliberately cheap: counters live on the
 * run row and are advanced by the importer as it goes, and the folder breakdown
 * is one GROUP BY rather than half a million loaded models.
 *
 * @version 1.0
 */

function mail_import_status_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailImportService.php'));

	$session = SessionControl::get_instance();
	if (!intval($session->get_user_id())) {
		return LogicResult::error('Sign in required.');
	}

	$service = MailImportService::fromSession($session);
	$run_id = intval($input['run_id'] ?? 0);

	if ($run_id <= 0) {
		$alias_id = intval($input['alias_id'] ?? 0);
		return LogicResult::render(array(
			'runs' => $service->history($alias_id > 0 ? $alias_id : null),
		));
	}

	$run = $service->loadRun($run_id);
	if ($run === null) {
		return LogicResult::error('That import could not be found.');
	}

	$payload = array('run' => MailImportService::describe($run));

	// The choice is only meaningful once the scan has counted everything; before
	// that the numbers would be a running total presented as a total.
	if ((string)$run->get('mir_state') === MailImportRun::STATE_SCANNED) {
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/import/MailArchiveImporter.php'));
		$importer = new MailArchiveImporter($run);
		$payload['preview'] = $importer->preview();
	}

	return LogicResult::render($payload);
}

function mail_import_status_logic_descriptor(): array {
	return array(
		'description'      => 'Progress of a mail archive import, or the caller\'s recent imports.',
		'requires_session' => true,
		'mutates'          => false,
		'input'            => array(
			'run_id'   => array('type' => 'int', 'required' => false, 'label' => 'One import run'),
			'alias_id' => array('type' => 'int', 'required' => false, 'label' => 'Limit the history to one mailbox'),
		),
	);
}
?>
