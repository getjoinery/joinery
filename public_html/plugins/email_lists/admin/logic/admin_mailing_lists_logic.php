<?php
/**
 * The mailing lists admin page, and its Sync now button (POST).
 *
 * @version 1.1 - Sync now runs MailingListSync
 */
function admin_mailing_lists_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/Pager.php'));

	$session = SessionControl::get_instance();
	$session->set_return();

	// Sync now: the same reconcile the MailingListSyncTask task runs daily.
	if (LibraryFunctions::isFormSubmission() && ($input['action'] ?? '') === 'sync_now') {
		Access::demand('personal:write', $session);
		set_time_limit(0);
		$result = MailingListSync::run(false);
		$session->save_message(new DisplayMessage($result['message'], $result['ok'] ? 'Synced' : 'Not synced',
			'/\/plugins\/email_lists\/admin\/admin_mailing_lists/',
			$result['ok'] ? DisplayMessage::MESSAGE_ANNOUNCEMENT : DisplayMessage::MESSAGE_ERROR,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		return LogicResult::redirect('/plugins/email_lists/admin/admin_mailing_lists');
	}

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable_local($input, 'offset', 0);
	$sort = LibraryFunctions::fetch_variable_local($input, 'sort', 'mailing_list_id');
	$sdirection = LibraryFunctions::fetch_variable_local($input, 'sdirection', 'DESC');

	$search_criteria = array();
	if(!Access::permits(MailingList::access_level('soft_delete'), $session)){
		$search_criteria['deleted'] = false;
	}

	$mailing_lists = new MultiMailingList($search_criteria, array($sort=>$sdirection), $numperpage, $offset);
	$numrecords = $mailing_lists->count_all();
	$mailing_lists->load();

	$page_vars = array();
	$page_vars['session'] = $session;
	$page_vars['mailing_lists'] = $mailing_lists;
	$page_vars['numrecords'] = $numrecords;
	$page_vars['numperpage'] = $numperpage;
	$page_vars['has_provider'] = MailingListService::getProvider() !== null;

	return LogicResult::render($page_vars);
}
?>
