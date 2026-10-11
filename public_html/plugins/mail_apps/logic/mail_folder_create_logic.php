<?php
/**
 * mail_apps/mail_folder_create — a mail program creates a folder (IMAP
 * CREATE): a label, through the reader's own createFolder(). Labels are one
 * set for the whole site, so the label is the same one the web reader lists.
 * A name of one of the fixed folders is refused.
 *
 * @version 1.0.0
 */

function mail_folder_create_logic(array $input): LogicResult {
	return MailAppAction::run($input, function (MailAppContext $ctx, array $input) {
		$name = trim((string)$input['name'], " /");
		if (strpos($name, MailAppFolders::LABEL_PARENT . '/') === 0) {
			$name = substr($name, strlen(MailAppFolders::LABEL_PARENT) + 1);
		}
		foreach (MailAppFolders::FIXED as $fixed) {
			if (strcasecmp($name, $fixed[0]) === 0) {
				throw new MailAppRefusal('That folder already exists.', 'ALREADYEXISTS');
			}
		}
		if ($name === '') {
			throw new MailAppRefusal('Give the folder a name.', 'CANNOT');
		}
		$created = $ctx->service->createFolder($ctx->alias_id, $name);
		if ($created === null) {
			throw new MailAppRefusal('That folder could not be created.', 'CANNOT');
		}
		$folder = MailAppFolders::byKey($ctx, 'label:' . intval($created['id']));
		return LogicResult::render(array('key' => $folder['key'], 'name' => $folder['name']));
	});
}

function mail_folder_create_logic_descriptor() {
	return MailAppAction::descriptor('Create a folder (a label) from a mail program', array(
		'name' => array('type' => 'string', 'required' => true, 'label' => 'Folder name', 'max_length' => 255),
	));
}
?>
