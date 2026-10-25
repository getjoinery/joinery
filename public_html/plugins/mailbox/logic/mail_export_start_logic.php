<?php
/**
 * API action: mailbox/mail_export_start — ask for a download of a mailbox, or
 * one folder of it, as a zip of mbox files.
 *
 * POST /api/v1/action/mailbox/mail_export_start (browser session, after a
 * step-up). Params: alias_id, folder (optional: a key from the export page's
 * folder list; omitted for the whole mailbox). Returns {run_id, level, state}.
 *
 * Years of mail in one file is the most a stolen session could walk off with,
 * so this asks for a fresh second factor first (requires_stepup), as Google
 * Takeout asks you to sign in again. What happens next follows the mailbox's
 * level (MailExportRun):
 *
 *   standard   queued for the RunMailExports task; the member is told when it
 *              is ready;
 *   private    built in the owner's unlocked window, sealed to their vault.
 *              Only the mailbox's owner can ask, since only their vault opens
 *              its mail;
 *   fortress   built by the owner's browser, which pages through the rows with
 *              mailbox/mail_export_fortress_page under this run.
 *
 * The caller exports only what they can read: a mailbox they hold a grant on,
 * or any Standard mailbox for an all-access reader. Asking again while a
 * build of the same mailbox and folder is under way returns that build.
 *
 * @version 1.0
 */

function mail_export_start_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$uid = intval($session->get_user_id());
	if (!$uid) {
		return LogicResult::error('Sign in required.');
	}

	$alias_id = intval($input['alias_id'] ?? 0);
	if ($alias_id <= 0) {
		return LogicResult::error('No mailbox specified.');
	}
	$viewer = MailboxViewer::fromSession($session);
	if (!$viewer->canAccess($alias_id)) {
		return LogicResult::error('Not authorized.');
	}
	$alias = new InboundEmailAlias($alias_id, TRUE);
	if (!$alias->key || $alias->get('iea_delete_time')) {
		return LogicResult::error('That mailbox no longer exists.');
	}
	$folder = trim((string)($input['folder'] ?? ''));
	if ($folder !== '' && !MailExport::validFolder($alias_id, $folder)) {
		return LogicResult::error('That folder is not in this mailbox.');
	}

	if ($session->step_up_outstanding(null, 300)) {
		return LogicResult::error('Confirm it is you before downloading a mailbox.', array('requires_stepup' => true));
	}

	$level = $alias->is_fortress() ? MailExportRun::LEVEL_FORTRESS
		: ($alias->seals_content() ? MailExportRun::LEVEL_PRIVATE : MailExportRun::LEVEL_STANDARD);
	if ($level !== MailExportRun::LEVEL_STANDARD
			&& !in_array($alias_id, array_map('intval', InboundEmailMailboxGrant::alias_ids_for_user($uid)), true)) {
		return LogicResult::error('This mailbox is protected, so only its owner can download it.');
	}

	// The same mailbox and folder already on its way: hand that one back.
	$db = DbConnector::get_instance()->get_db_link();
	$stmt = $db->prepare("SELECT mxr_mail_export_run_id FROM mxr_mail_export_runs
		WHERE mxr_usr_user_id = ? AND mxr_iea_inbound_email_alias_id = ? AND COALESCE(mxr_folder, '') = ?
		  AND mxr_state IN ('queued', 'building') ORDER BY mxr_mail_export_run_id DESC LIMIT 1");
	$stmt->execute(array($uid, $alias_id, $folder));
	$existing = $stmt->fetchColumn();
	if ($existing !== false) {
		$run = new MailExportRun(intval($existing), TRUE);
		return LogicResult::render(array('run_id' => intval($run->key), 'level' => (string)$run->get('mxr_level'),
			'state' => (string)$run->get('mxr_state')));
	}

	$vault = null;
	if ($level === MailExportRun::LEVEL_PRIVATE) {
		$vault = UserEncryptionVault::loadForUser($uid, UserEncryptionVault::SCOPE_USER);
		if ($vault === null) {
			return LogicResult::error('This mailbox is Private, and you have no vault to seal its download to.');
		}
	}
	if ($level !== MailExportRun::LEVEL_FORTRESS) {
		$keys = $folder !== '' ? array($folder) : array_keys(MailExport::FOLDERS);
		$short = DiskSpace::shortfallMessage(MailExport::storedBytes($alias_id, $keys), array(MailExportRun::rootDir() . '/'));
		if ($short !== '') {
			return LogicResult::error('This server does not have room to build that download right now. ' . $short);
		}
	}

	$run = new MailExportRun(NULL);
	$run->set('mxr_iea_inbound_email_alias_id', $alias_id);
	$run->set('mxr_usr_user_id', $uid);
	$run->set('mxr_folder', $folder !== '' ? $folder : null);
	$run->set('mxr_level', $level);
	$run->set('mxr_state', $level === MailExportRun::LEVEL_FORTRESS ? MailExportRun::STATE_BROWSER : MailExportRun::STATE_QUEUED);
	// Counted now, so the list says how many from the first moment.
	$run->set('mxr_total', MailExport::count($alias_id, $folder !== '' ? array($folder) : array_keys(MailExport::FOLDERS)));
	$run->save();
	if ($vault !== null) {
		MailExportRun::recordSealedKey(intval($run->key), $vault);
	}
	$run->load();

	return LogicResult::render(array('run_id' => intval($run->key), 'level' => $level,
		'state' => (string)$run->get('mxr_state')));
}

function mail_export_start_logic_descriptor(): array {
	return array(
		'description' => 'Ask for a download of a mailbox, or one folder, as a zip of mbox files',
		'mutates' => true,
		'requires_session' => true,
		'auth' => array('min_user_permission' => 0, 'requires_browser_session' => true),
		'input' => array(
			'alias_id' => array('type' => 'int', 'required' => true, 'label' => 'Mailbox alias ID'),
			'folder'   => array('type' => 'string', 'required' => false, 'label' => 'One folder (omit for the whole mailbox)'),
		),
	);
}
?>
