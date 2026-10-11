<?php
/**
 * mail_apps/mail_sync — reconcile one folder and answer its state: numbers,
 * flags, change numbers, dates and the sizes already told. All live messages,
 * or (with since_modseq) only those changed since then plus the numbers
 * expunged since (`vanished`). With status_only, just the folder's numbers and
 * counts (IMAP STATUS / SELECT).
 *
 * A check counts as activity for a borrowed unlock (decision 2).
 *
 * @version 1.0.0
 */

function mail_sync_logic(array $input): LogicResult {
	return MailAppAction::run($input, function (MailAppContext $ctx, array $input) {
		$folder = MailAppAction::folder($ctx, (string)$input['folder']);
		$state = MailAppFolders::reconcile($ctx, $folder);
		$ctx->countCheckAsActivity();
		$out = array(
			'folder'         => $folder['key'],
			'uidvalidity'    => $state['uidvalidity'],
			'uidnext'        => $state['uidnext'],
			'highest_modseq' => $state['highest_modseq'],
			'exists'         => $state['exists'],
			'unseen'         => $state['unseen'],
			'locked'         => $state['locked'],
			'waiting'        => $state['waiting'],
		);
		if (empty($input['status_only'])) {
			$since = isset($input['since_modseq']) && $input['since_modseq'] !== null && $input['since_modseq'] !== ''
				? intval($input['since_modseq']) : null;
			$rows = MailAppFolders::rows($ctx, $folder, $since);
			$out['messages'] = $rows['messages'];
			$out['vanished'] = $rows['vanished'];
		}
		return LogicResult::render($out);
	});
}

function mail_sync_logic_descriptor() {
	return MailAppAction::descriptor('Reconcile one mail-app folder and answer its numbers, flags and changes', array(
		'folder'       => array('type' => 'string', 'required' => true, 'label' => 'Folder key', 'max_length' => 64),
		'since_modseq' => array('type' => 'int', 'required' => false, 'label' => 'Changes since', 'min' => 0),
		'status_only'  => array('type' => 'bool', 'required' => false, 'label' => 'Counts only', 'default' => false),
	));
}
?>
