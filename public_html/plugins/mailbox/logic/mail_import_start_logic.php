<?php
/**
 * API action: mailbox/mail_import_start — begin importing a mail archive.
 *
 * Takes a mailbox, an archive, and the addresses the user says were theirs, and
 * queues a run. It stores no mail and reads nothing beyond the file's first bytes
 * — enough to work out the format, so an unreadable file is refused here, with a
 * reason, rather than queueing a run that would fail on the next cron pass.
 *
 * The archive arrives one of two ways, both resolving to a file the server can
 * read: uploaded now (multipart, field `archive`), or picked from the caller's
 * existing files by id. There is no server-path option — pointing at a folder on
 * the machine is not a thing a member can do.
 *
 * The addresses matter more than they look. An archive carries no envelope, so
 * without them nothing can tell sent mail from received, or say which of several
 * addresses a message actually reached.
 *
 * @version 1.0
 */

function mail_import_start_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailImportService.php'));

	$session = SessionControl::get_instance();
	if (!intval($session->get_user_id())) {
		return LogicResult::error('Sign in required.');
	}

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('mailbox_import_enabled')) {
		return LogicResult::error('Mail archive import is switched off on this site.');
	}

	$alias_id = intval($input['alias_id'] ?? 0);
	if ($alias_id <= 0) {
		return LogicResult::error('Choose the mailbox the mail should go into.');
	}

	$service = MailImportService::fromSession($session);
	if (!$service->canTarget($alias_id)) {
		return LogicResult::error('You do not have access to that mailbox.');
	}

	$addresses = MailImportRun::parseAddressList((string)($input['own_addresses'] ?? ''));
	if (!$addresses) {
		return LogicResult::error('Add at least one address that was yours at the old provider, '
			. 'so sent mail can be told apart from received.');
	}

	// Uploaded now, or picked from files already here. Upload wins when both are
	// present — the user just chose a file, so that is what they meant.
	$file_id = intval($input['file_id'] ?? 0);
	$source_name = '';
	if (!empty($_FILES['archive']['tmp_name']) && is_uploaded_file($_FILES['archive']['tmp_name'])) {
		try {
			$stored = $service->storeUpload($_FILES['archive']);
		} catch (Throwable $e) {
			return LogicResult::error($e->getMessage());
		}
		$file_id = intval($stored->key);
		$source_name = (string)$_FILES['archive']['name'];
	}

	if ($file_id <= 0) {
		return LogicResult::error('Choose an archive to import — upload one, or pick a file you already have here.');
	}

	try {
		$run = $service->startRun($alias_id, $file_id, $addresses, $source_name);
	} catch (Throwable $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render(array(
		'run'     => MailImportService::describe($run),
		'message' => 'Reading the archive. This runs in the background — you can leave this page, '
			. 'and you will be asked what to bring in once it knows what is there.',
	));
}

/**
 * No 'ai_agent' key, deliberately: starting an import moves somebody's entire mail
 * history into a mailbox, and that is a decision a person makes about their own
 * mail. The action stays reachable only by a signed-in human.
 */
function mail_import_start_logic_descriptor(): array {
	return array(
		'description'      => 'Queue a mail archive (mbox, .eml folder, zip, tar) for import into a mailbox.',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => array(
			'alias_id'      => array('type' => 'int',    'required' => true,  'label' => 'Mailbox to import into'),
			'file_id'       => array('type' => 'int',    'required' => false, 'label' => 'An archive already in your files'),
			'own_addresses' => array('type' => 'string', 'required' => true,  'label' => 'Addresses that were yours'),
		),
	);
}
?>
