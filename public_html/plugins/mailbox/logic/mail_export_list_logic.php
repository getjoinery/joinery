<?php
/**
 * API action: mailbox/mail_export_list — the caller's mailbox downloads.
 *
 * POST /api/v1/action/mailbox/mail_export_list (session credential). No params.
 * Returns {exports: [...]}, newest first: each one's mailbox, folder, level,
 * state, progress, how many messages were written and left out, when it is
 * deleted, and its download link once it is ready. A Fortress request is not
 * listed: the browser that asked builds it, and nothing is kept here.
 *
 * @version 1.0
 */

function mail_export_list_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$uid = intval($session->get_user_id());
	if (!$uid) {
		return LogicResult::error('Sign in required.');
	}
	return LogicResult::render(array('exports' => mail_export_list_rows($uid)));
}

/** The rows the list and the export page show. */
function mail_export_list_rows(int $uid): array {
	$days = intval(Globalvars::get_instance()->get_setting('export_retention_days'));
	$runs = new MultiMailExportRun(array('mxr_usr_user_id' => $uid,
		'states' => array(MailExportRun::STATE_QUEUED, MailExportRun::STATE_BUILDING,
			MailExportRun::STATE_READY, MailExportRun::STATE_FAILED)),
		array('mxr_mail_export_run_id' => 'DESC'));
	$out = array();
	foreach ($runs as $run) {
		$alias_id = intval($run->get('mxr_iea_inbound_email_alias_id'));
		$alias = new InboundEmailAlias($alias_id, TRUE);
		$folder = (string)$run->get('mxr_folder');
		$ready = (string)$run->get('mxr_state') === MailExportRun::STATE_READY;
		$since = (string)($run->get('mxr_finish_time') ?: $run->get('mxr_create_time'));
		$out[] = array(
			'run_id'     => intval($run->key),
			'mailbox'    => $alias->key ? (string)$alias->get_full_address() : '',
			'folder'     => $folder !== '' ? MailExport::folderName($folder, $alias_id) : '',
			'level'      => (string)$run->get('mxr_level'),
			'state'      => (string)$run->get('mxr_state'),
			'percent'    => $run->percent(),
			'total'      => intval($run->get('mxr_total')),
			'written'    => intval($run->get('mxr_done')),
			'left_out'   => intval($run->get('mxr_left_out')),
			'bytes'      => intval($run->get('mxr_bytes')),
			'error'      => (string)$run->get('mxr_error'),
			'created'    => (string)$run->get('mxr_create_time'),
			'expires'    => $days > 0 ? gmdate('Y-m-d H:i:s', (strtotime($since . ' UTC') ?: time()) + $days * 86400) : null,
			'download'   => $ready ? '/profile/mailbox/export_download?run_id=' . intval($run->key) : null,
		);
	}
	return $out;
}

function mail_export_list_logic_descriptor(): array {
	return array(
		'auth' => array('min_user_permission' => 0),
		'description' => 'The caller\'s mailbox downloads: progress, and the link once each is ready',
		'requires_session' => true,
		'input' => array(),
	);
}
?>
