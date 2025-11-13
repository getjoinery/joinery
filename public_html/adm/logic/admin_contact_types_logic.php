<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_contact_types_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/Pager.php'));
	require_once(PathHelper::getIncludePath('data/contact_types_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable_local($get_vars, 'offset', 0);
	$sort = LibraryFunctions::fetch_variable_local($get_vars, 'sort', 'contact_type_id');
	$sdirection = LibraryFunctions::fetch_variable_local($get_vars, 'sdirection', 'DESC');

	$search_criteria = array();
	if($session->get_permission() < 10){
		$search_criteria['deleted'] = false;
	}

	$contact_types = new MultiContactType($search_criteria, array($sort=>$sdirection), $numperpage, $offset);
	$numrecords = $contact_types->count_all();
	$contact_types->load();

	$page_vars = array();
	$page_vars['session'] = $session;
	$page_vars['contact_types'] = $contact_types;
	$page_vars['numrecords'] = $numrecords;
	$page_vars['numperpage'] = $numperpage;

	return LogicResult::render($page_vars);
}
?>
