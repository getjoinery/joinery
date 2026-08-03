<?php
/**
 * API action: mailbox/contacts_import — import contacts, or add one by hand.
 *
 * POST /api/v1/action/mailbox/contacts_import (session credential). Always takes alias_id
 * (contacts belong to a mailbox — an import lands in the one you are looking at), plus
 * either:
 *   - a multipart `file` upload (.vcf vCard or Google-contacts CSV) → bulk import, or
 *   - an `address` param ("Name <email>" or bare) → a single manual add.
 * Both upsert through the same contact-store path (source 'import' / 'manual'). A file
 * import returns {imported, skipped}; a manual add returns {added: bool}.
 *
 * @version 1.1.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function contacts_import_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxContacts.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxViewer.php'));

	$session = SessionControl::get_instance();
	$uid = $session->get_user_id();
	if (!$uid) {
		return LogicResult::error('Sign in required.');
	}

	// A contact has to land in a mailbox, so an add with none named is refused rather
	// than stored unscoped where no mailbox-scoped read would ever surface it.
	$alias_id = intval($input['alias_id'] ?? 0);
	if ($alias_id <= 0) {
		return LogicResult::error('No mailbox specified.');
	}
	$viewer = MailboxViewer::fromSession($session);
	if (!$viewer->canAccess($alias_id)) {
		return LogicResult::error('Not authorized.');
	}

	$contacts = new MailboxContacts();

	// Manual single add.
	$address = trim((string)($input['address'] ?? ''));
	if ($address !== '' && empty($_FILES['file'])) {
		$added = $contacts->manualAdd(intval($uid), $address, $alias_id);
		if (!$added) {
			return LogicResult::error('That is not a valid email address.');
		}
		return LogicResult::render(array('added' => true));
	}

	// File import (vCard / CSV).
	if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
		return LogicResult::error('Choose a .vcf or .csv file to import.');
	}
	$tmp = (string)($_FILES['file']['tmp_name'] ?? '');
	if ($tmp === '' || !is_uploaded_file($tmp)) {
		return LogicResult::error('The file could not be read.');
	}
	if (intval($_FILES['file']['size'] ?? 0) > 5 * 1024 * 1024) {
		return LogicResult::error('The contacts file is too large (5 MB max).');
	}
	$content = file_get_contents($tmp);
	if ($content === false) {
		return LogicResult::error('The file could not be read.');
	}

	try {
		$result = $contacts->import(intval($uid), $content, (string)($_FILES['file']['name'] ?? ''), $alias_id);
	} catch (Throwable $e) {
		error_log('mailbox/contacts_import: ' . $e->getMessage());
		return LogicResult::error('The contacts could not be imported.');
	}
	return LogicResult::render($result);
}

function contacts_import_logic_api() {
	return array(
		'requires_session' => true,
		'description' => 'Import contacts from a vCard/CSV file, or add one address by hand',
	);
}
?>
