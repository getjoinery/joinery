<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_groups_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/Pager.php'));
	require_once(PathHelper::getIncludePath('data/groups_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable_local($input, 'offset', 0);
	$sort = LibraryFunctions::fetch_variable_local($input, 'sort', 'grp_update_time');
	$sdirection = LibraryFunctions::fetch_variable_local($input, 'sdirection', 'DESC');

	$search_criteria = array();
	if($session->get_permission() < 10){
		$search_criteria['deleted'] = false;
	}
	$search_criteria['category'] = 'user';

	$groups = new MultiGroup($search_criteria, array($sort=>$sdirection), $numperpage, $offset, 'OR');
	$numrecords = $groups->count_all();
	$groups->load();

	$page_vars = array();
	$page_vars['session'] = $session;
	$page_vars['groups'] = $groups;
	$page_vars['numrecords'] = $numrecords;
	$page_vars['numperpage'] = $numperpage;

	return LogicResult::render($page_vars);
}
?>
