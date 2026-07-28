<?php
/**
 * API action: mailbox/mail_import_select — bring these in, leave those out.
 *
 * The run has finished scanning and is holding at `scanned`. This records what
 * the user ticked and releases it to import.
 *
 * Spam and Trash are separate flags rather than folders in the list because a
 * message can sit in a folder the user wants AND be spam, and because they are
 * the two buckets that arrive unticked — an archive's spam folder is usually the
 * largest thing in it and almost never what anyone wanted to keep.
 *
 * Everything left out is marked skipped rather than deleted, so the run's final
 * reconciliation can still account for every message the scan found.
 *
 * @version 1.0
 */

function mail_import_select_logic(array $input): LogicResult {
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
	if ((string)$run->get('mir_state') !== MailImportRun::STATE_SCANNED) {
		return LogicResult::error('That import is not waiting for a choice.');
	}

	// One folder per line — the shape the action schema takes, since it coerces
	// scalars and a folder name never contains a newline. An in-process caller may
	// hand in a real array instead. "*" means everything.
	$raw = $input['folders'] ?? array();
	if (!is_array($raw)) {
		$raw = array_filter(array_map('trim', preg_split('/[\r\n]+/', (string)$raw)), 'strlen');
	}
	$folders = array_values(array_map('strval', $raw));

	$include_spam  = mail_import_truthy($input['include_spam'] ?? false);
	$include_trash = mail_import_truthy($input['include_trash'] ?? false);

	if (!$folders && !$include_spam && !$include_trash) {
		return LogicResult::error('Nothing is selected, so there would be nothing to import.');
	}

	$importer = new MailArchiveImporter($run);
	$skipped = $importer->applySelection($folders, $include_spam, $include_trash);

	$run->load();
	return LogicResult::render(array(
		'run'     => MailImportService::describe($run),
		'skipped' => $skipped,
		'message' => 'Importing ' . max(0, intval($run->get('mir_total_entries')) - $skipped)
			. ' message(s) in the background. You can leave this page.',
	));
}

/** Form checkboxes, JSON booleans and query strings all mean the same thing. */
function mail_import_truthy($value): bool {
	if (is_bool($value)) { return $value; }
	return in_array(strtolower(trim((string)$value)), array('1', 'true', 'yes', 'on'), true);
}

/**
 * No 'ai_agent' key, deliberately: choosing what to bring in is the one decision
 * the whole flow exists to put in front of a person.
 */
function mail_import_select_logic_descriptor(): array {
	return array(
		'description'      => 'Choose which folders of a scanned mail archive to import, then start importing.',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => array(
			'run_id'        => array('type' => 'int',    'required' => true,  'label' => 'The import run'),
			'folders'       => array('type' => 'string', 'required' => false, 'label' => 'Folders to bring in, one per line (* for all)'),
			'include_spam'  => array('type' => 'string', 'required' => false, 'label' => 'Include spam'),
			'include_trash' => array('type' => 'string', 'required' => false, 'label' => 'Include trash'),
		),
	);
}
?>
