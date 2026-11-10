<?php
/**
 * One mailing list: its subscribers, and the people waiting to confirm
 * (Resend, Mark confirmed, Delete). Every action is a POST.
 *
 * @version 1.1 - pending requests; actions are POST only
 */

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('includes/Activation.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));

function admin_mailing_list_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();

	$mailing_list = new MailingList($input['mlt_mailing_list_id'] ?? null, TRUE);
	$mailing_list->assert_found();

	$action = LibraryFunctions::isFormSubmission() ? ($input['action'] ?? null) : null;

	// People waiting to confirm (spec email_lists_plugin §10).
	if (in_array($action, array('pending_confirm', 'pending_resend', 'pending_delete'), true)) {
		Access::demand('personal:write', $session);
		$row = new MailingListPending($input['mlp_mailing_list_pending_id'] ?? null, TRUE);
		$row->assert_found();
		if ((int)$row->get('mlp_mlt_mailing_list_id') !== (int)$mailing_list->key) {
			return LogicResult::error('That request is not for this list.');
		}
		$email = (string)$row->get('mlp_email');
		if ($action === 'pending_confirm') {
			// Consent collected elsewhere (a paper form, an import), recorded as the admin's.
			$why_not = EmailListSubscriptions::admin_confirm($row, new User($session->get_user_id(), TRUE));
			$said = $why_not === null ? array($email . ' is confirmed.', true) : array('Not confirmed: ' . $why_not, false);
		} elseif ($action === 'pending_resend') {
			$said = EmailListSubscriptions::resend($email)
				? array('A new confirmation link went to ' . $email . '.', true)
				: array('Not sent: ' . $email . ' was sent a link in the last day, or today\'s limit is reached. Try again tomorrow.', false);
		} else {
			$row->permanent_delete();
			$said = array('The request from ' . $email . ' is deleted.', true);
		}
		$session->save_message(new DisplayMessage($said[0], $said[1] ? 'Done' : 'Not done',
			'/\/plugins\/email_lists\/admin\/admin_mailing_list/',
			$said[1] ? DisplayMessage::MESSAGE_ANNOUNCEMENT : DisplayMessage::MESSAGE_WARNING, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		return LogicResult::redirect("/plugins/email_lists/admin/admin_mailing_list?mlt_mailing_list_id=".$mailing_list->key);
	}

	if($action == 'delete'){
		$mailing_list->assert_can_write($session);
		$mailing_list->soft_delete();

		return LogicResult::redirect("/plugins/email_lists/admin/admin_mailing_lists");
	}
	else if($action == 'undelete'){
		$mailing_list->assert_can_write($session);
		$mailing_list->undelete();

		return LogicResult::redirect("/plugins/email_lists/admin/admin_mailing_lists");
	}
	else if($action == 'removeregistrant'){
		$registrant = new MailingListRegistrant($input['mlr_mailing_list_registrant_id'], TRUE);
		$registrant->assert_found();
		$mailing_list->remove_registrant($registrant->get('mlr_usr_user_id'));
		return LogicResult::redirect("/plugins/email_lists/admin/admin_mailing_list?mlt_mailing_list_id=".$mailing_list->key);
	}

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'mailing_list_registrant_id', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '');

	$search_criteria = array(
		'deleted' => false,
		'mailing_list_id' => $mailing_list->key);
	$registrants = new MultiMailingListRegistrant(
		$search_criteria,
		array($sort=>$sdirection),
		$numperpage,
		$offset);
	$registrants->load();
	$numrecords = $registrants->count_all();

	$session->set_return();

	// Build dropdown actions
	$options['altlinks'] = array();
	if(!$mailing_list->get('mlt_delete_time')) {
		$options['altlinks'] += array('Edit Mailing List' => '/plugins/email_lists/admin/admin_mailing_list_edit?mlt_mailing_list_id='.$mailing_list->key);
	}

	if($mailing_list->can_delete($session)){
		if($mailing_list->get('mlt_delete_time')) {
			$options['altlinks']['Undelete'] = array('post' => '/plugins/email_lists/admin/admin_mailing_list', 'hidden' => array('action' => 'undelete', 'mlt_mailing_list_id' => $mailing_list->key), 'model' => $mailing_list, 'verb' => 'soft_delete');
		}
		else {
			$options['altlinks']['Soft Delete'] = array('post' => '/plugins/email_lists/admin/admin_mailing_list', 'hidden' => array('action' => 'delete', 'mlt_mailing_list_id' => $mailing_list->key), 'model' => $mailing_list, 'verb' => 'soft_delete');
		}
	}

	// Build dropdown button from altlinks
	$dropdown_button = '';
	if (!empty($options['altlinks'])) {
		$dropdown_button = '<div class="dropdown">';
		$dropdown_button .= '<button class="btn btn-soft-default btn-sm dropdown-toggle" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Actions</button>';
		$dropdown_button .= '<div class="dropdown-menu dropdown-menu-end py-0">';
		foreach ($options['altlinks'] as $label => $entry) {
			$is_danger = strpos($label, 'Delete') !== false;
			$dropdown_button .= AdminPage::renderActionEntry($label, $entry, 'dropdown-item' . ($is_danger ? ' text-danger' : ''));
		}
		$dropdown_button .= '</div>';
		$dropdown_button .= '</div>';
	}

	$pending = new MultiMailingListPending(array('mlp_mlt_mailing_list_id' => $mailing_list->key, 'live' => true),
		array('mlp_mailing_list_pending_id' => 'DESC'), 50, 0);

	$page_vars = array(
		'pending' => $pending,
		'pending_count' => $pending->count_all(),
		'session' => $session,
		'mailing_list' => $mailing_list,
		'registrants' => $registrants,
		'numrecords' => $numrecords,
		'numperpage' => $numperpage,
		'dropdown_button' => $dropdown_button,
	);

	return LogicResult::render($page_vars);
}
