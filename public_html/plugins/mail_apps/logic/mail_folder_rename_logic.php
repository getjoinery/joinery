<?php
/**
 * mail_apps/mail_folder_rename — a mail program renames a folder (IMAP
 * RENAME). Joinery's labels cannot be renamed yet, so this always refuses,
 * saying so; the program keeps the folder as it was.
 *
 * @version 1.0.0
 */

function mail_folder_rename_logic(array $input): LogicResult {
	return MailAppAction::run($input, function (MailAppContext $ctx, array $input) {
		throw new MailAppRefusal('Folders cannot be renamed yet. Create a new one and move the mail into it.', 'CANNOT');
	});
}

function mail_folder_rename_logic_descriptor() {
	return MailAppAction::descriptor('Rename a folder from a mail program (refused: labels cannot be renamed yet)', array(
		'name'     => array('type' => 'string', 'required' => true, 'label' => 'Folder name', 'max_length' => 255),
		'new_name' => array('type' => 'string', 'required' => true, 'label' => 'New name', 'max_length' => 255),
	));
}
?>
