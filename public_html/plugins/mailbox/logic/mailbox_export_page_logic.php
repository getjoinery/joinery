<?php
/**
 * Logic for the member "Download a mailbox" page (/profile/mailbox/export).
 *
 * Lists the mailboxes the member holds a grant on, plus the one named in
 * ?mailbox= when they may read it (an all-access reader arriving from the
 * admin mailbox list), with each one's level and folders, and the member's
 * downloads. Asking for one, and everything after, is the page's script
 * calling the mailbox/mail_export_* API actions.
 *
 * @version 1.0
 */

function mailbox_export_page_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('plugins/mailbox/logic/mail_export_list_logic.php'));

	$session = SessionControl::get_instance();
	$user_id = intval($session->get_user_id());
	if (!$user_id) {
		return LogicResult::redirect('/login?return=' . urlencode('/profile/mailbox/export'));
	}

	$ids = array_map('intval', InboundEmailMailboxGrant::alias_ids_for_user($user_id));
	$wanted = intval($input['mailbox'] ?? 0);
	if ($wanted > 0 && !in_array($wanted, $ids, true) && MailboxViewer::fromSession($session)->canAccess($wanted)) {
		$ids[] = $wanted;
	}

	$mailboxes = array();
	foreach (array_unique($ids) as $alias_id) {
		$alias = new InboundEmailAlias($alias_id, TRUE);
		if (!$alias->key || $alias->get('iea_delete_time')) {
			continue;
		}
		$mailboxes[] = array(
			'alias_id' => intval($alias->key),
			'address'  => (string)$alias->get_full_address(),
			'level'    => $alias->is_fortress() ? MailExportRun::LEVEL_FORTRESS
				: ($alias->seals_content() ? MailExportRun::LEVEL_PRIVATE : MailExportRun::LEVEL_STANDARD),
		);
	}
	usort($mailboxes, function ($a, $b) { return strcasecmp($a['address'], $b['address']); });

	$current = null;
	foreach ($mailboxes as $mb) {
		if ($mb['alias_id'] === $wanted) {
			$current = $mb;
		}
	}
	if ($current === null && $mailboxes) {
		$current = $mailboxes[0];
	}
	if ($current !== null) {
		$current['folders'] = MailExport::folderChoices($current['alias_id']);
		$current['count'] = MailExport::count($current['alias_id'], array_keys(MailExport::FOLDERS));
	}

	return LogicResult::render(array(
		'mailboxes'      => $mailboxes,
		'current'        => $current,
		'exports'        => mail_export_list_rows($user_id),
		'retention_days' => intval(Globalvars::get_instance()->get_setting('export_retention_days')),
	));
}
?>
