<?php
/**
 * mail_apps/mail_folder_delete — a mail program deletes a folder (IMAP
 * DELETE): a label folder deletes the label, through the reader's own
 * deleteLabel() — the label comes off every message that carries it, and the
 * messages themselves stay. The fixed folders cannot be deleted.
 *
 * @version 1.0.0
 */

function mail_folder_delete_logic(array $input): LogicResult {
	return MailAppAction::run($input, function (MailAppContext $ctx, array $input) {
		$folder = MailAppFolders::byName($ctx, (string)$input['name']);
		if ($folder === null) {
			throw new MailAppRefusal('That folder does not exist.', 'NONEXISTENT');
		}
		if ($folder['view'] !== 'label') {
			throw new MailAppRefusal($folder['name'] . ' cannot be deleted.', 'CANNOT');
		}
		$deleted = $ctx->service->deleteLabel($ctx->alias_id, intval($folder['label_id']));
		if ($deleted === null) {
			throw new MailAppRefusal('That folder could not be deleted.', 'CANNOT');
		}
		return LogicResult::render(array('deleted' => $folder['key']));
	});
}

function mail_folder_delete_logic_descriptor() {
	return MailAppAction::descriptor('Delete a folder (a label) from a mail program', array(
		'name' => array('type' => 'string', 'required' => true, 'label' => 'Folder name', 'max_length' => 255),
	));
}
?>
