<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_mailing_lists_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/Pager.php'));
	require_once(PathHelper::getIncludePath('data/mailing_lists_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable_local($get_vars, 'offset', 0);
	$sort = LibraryFunctions::fetch_variable_local($get_vars, 'sort', 'mailing_list_id');
	$sdirection = LibraryFunctions::fetch_variable_local($get_vars, 'sdirection', 'DESC');

	$search_criteria = array();
	if($session->get_permission() < 10){
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

	return LogicResult::render($page_vars);
}
?>
