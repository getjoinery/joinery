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

	// An upload over post_max_size is discarded by PHP BEFORE any of this runs:
	// $_POST and $_FILES both arrive empty while Content-Length says otherwise.
	// Without this check the request looks like a user who filled in nothing, and
	// they get told to choose a mailbox they did in fact choose.
	$posted_bytes = intval($_SERVER['CONTENT_LENGTH'] ?? 0);
	if ($posted_bytes > 0 && empty($_POST) && empty($_FILES)) {
		return LogicResult::error(mail_import_too_large_message($posted_bytes));
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
	//
	// The branch is entered whenever a file was ATTEMPTED, not only when one
	// arrived intact. A file rejected for size has an empty tmp_name and an error
	// code; guarding on tmp_name would skip straight past it and report the far
	// less useful "choose an archive".
	$file_id = intval($input['file_id'] ?? 0);
	$source_name = '';
	$attempted = isset($_FILES['archive'])
		&& intval($_FILES['archive']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

	if ($attempted) {
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
 * The largest archive a plain form upload can carry, in bytes.
 *
 * A file has to clear BOTH ini limits — upload_max_filesize for the file and
 * post_max_size for the whole request — so the real ceiling is the smaller of
 * them, and it is usually far below what a mail archive weighs.
 */
function mail_import_upload_ceiling(): int {
	$to_bytes = function (string $value): int {
		$value = trim($value);
		if ($value === '') { return 0; }
		$unit = strtolower(substr($value, -1));
		$n = (int)$value;
		if ($unit === 'g') { return $n * 1073741824; }
		if ($unit === 'm') { return $n * 1048576; }
		if ($unit === 'k') { return $n * 1024; }
		return $n;
	};
	$file = $to_bytes((string)ini_get('upload_max_filesize'));
	$post = $to_bytes((string)ini_get('post_max_size'));
	$limits = array_filter(array($file, $post));
	return $limits ? min($limits) : 0;
}

/** Bytes as something a person reads without counting digits. */
function mail_import_format_bytes(int $bytes): string {
	$units = array('B', 'KB', 'MB', 'GB', 'TB');
	$i = 0;
	$n = max(0, $bytes);
	while ($n >= 1024 && $i < count($units) - 1) { $n /= 1024; $i++; }
	return ($i === 0 ? (int)$n : round($n, 1)) . ' ' . $units[$i];
}

/**
 * Say what actually happened and what to do instead. The archive is not too big
 * for the importer — it is too big for a single web request, which is a different
 * problem with a different answer.
 */
function mail_import_too_large_message(int $posted_bytes): string {
	$ceiling = mail_import_upload_ceiling();
	$size = $posted_bytes > 0 ? 'That archive is ' . mail_import_format_bytes($posted_bytes) . ', and this'
		: 'That archive is too big to upload here: this';
	return $size . ' server accepts at most ' . mail_import_format_bytes($ceiling)
		. ' in a single upload. The importer itself has no size limit — add the file to your '
		. 'Drive first, which uploads in chunks, then come back and choose it under '
		. 'Use a file already in my files.';
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
