<?php
/**
 * API action: mailbox/mail_export_delete — delete one of the caller's mailbox
 * downloads, with everything built for it, before its time is up.
 *
 * POST /api/v1/action/mailbox/mail_export_delete (session credential). Params:
 * run_id. A build under way stops: its row and pieces go together.
 *
 * @version 1.0
 */

function mail_export_delete_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$uid = intval($session->get_user_id());
	if (!$uid) {
		return LogicResult::error('Sign in required.');
	}
	$run = new MailExportRun(intval($input['run_id'] ?? 0), TRUE);
	if (!$run->key || intval($run->get('mxr_usr_user_id')) !== $uid) {
		return LogicResult::error('That download is not yours, or is already gone.');
	}
	$run->permanent_delete();
	return LogicResult::render(array('deleted' => true));
}

function mail_export_delete_logic_descriptor(): array {
	return array(
		'description' => 'Delete one of the caller\'s mailbox downloads',
		'mutates' => true,
		'requires_session' => true,
		'input' => array(
			'run_id' => array('type' => 'int', 'required' => true, 'label' => 'Export run ID'),
		),
	);
}
?>
