<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_locations_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/Pager.php'));
	require_once(PathHelper::getIncludePath('data/locations_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(7);
	$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable_local($get_vars, 'offset', 0);
	$sort = LibraryFunctions::fetch_variable_local($get_vars, 'sort', 'loc_location_id');
	$sdirection = LibraryFunctions::fetch_variable_local($get_vars, 'sdirection', 'DESC');

	$search_criteria = array();
	$locations = new MultiLocation($search_criteria, array($sort=>$sdirection), $numperpage, $offset);
	$numrecords = $locations->count_all();
	$locations->load();

	$page_vars = array();
	$page_vars['session'] = $session;
	$page_vars['locations'] = $locations;
	$page_vars['numrecords'] = $numrecords;
	$page_vars['numperpage'] = $numperpage;

	return LogicResult::render($page_vars);
}
?>
