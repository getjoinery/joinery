<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('includes/Activation.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/mailing_lists_class.php'));
require_once(PathHelper::getIncludePath('data/mailing_list_registrants_class.php'));

function admin_mailing_list_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$session->check_permission(8);

	$mailing_list = new MailingList($input['mlt_mailing_list_id'], TRUE);

	if($input['action'] == 'delete'){
		$mailing_list->assert_can_write($session);
		$mailing_list->soft_delete();

		return LogicResult::redirect("/admin/admin_mailing_lists");
	}
	else if($input['action'] == 'undelete'){
		$mailing_list->assert_can_write($session);
		$mailing_list->undelete();

		return LogicResult::redirect("/admin/admin_mailing_lists");
	}
	else if($input['action'] == 'removeregistrant'){
		$registrant = new MailingListRegistrant($input['mlr_mailing_list_registrant_id'], TRUE);
		$mailing_list->remove_registrant($registrant->get('mlr_usr_user_id'));
		return LogicResult::redirect("/admin/admin_mailing_list?mlt_mailing_list_id=".$mailing_list->key);
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
		$options['altlinks'] += array('Edit Mailing List' => '/admin/admin_mailing_list_edit?mlt_mailing_list_id='.$mailing_list->key);
	}

	if($_SESSION['permission'] >= 8){
		if($mailing_list->get('mlt_delete_time')) {
			$options['altlinks']['Undelete'] = array('post' => '/admin/admin_mailing_list', 'hidden' => array('action' => 'undelete', 'mlt_mailing_list_id' => $mailing_list->key));
		}
		else {
			$options['altlinks']['Soft Delete'] = array('post' => '/admin/admin_mailing_list', 'hidden' => array('action' => 'delete', 'mlt_mailing_list_id' => $mailing_list->key));
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

	$page_vars = array(
		'session' => $session,
		'mailing_list' => $mailing_list,
		'registrants' => $registrants,
		'numrecords' => $numrecords,
		'numperpage' => $numperpage,
		'dropdown_button' => $dropdown_button,
	);

	return LogicResult::render($page_vars);
}
