<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_pages_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/pages_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '', $get_vars);
	$sort = LibraryFunctions::fetch_variable('sort', 'page_id', 0, '', $get_vars);
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '', $get_vars);
	$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '', $get_vars);

	$search_criteria = array();

	//ONLY SHOW DELETED TO SUPER ADMINS
	if($_SESSION['permission'] < 10){
		$search_criteria['deleted'] = false;
	}

	$pages = new MultiPage(
		$search_criteria,
		array($sort=>$sdirection),
		$numperpage,
		$offset);
	$numrecords = $pages->count_all();
	$pages->load();

	$page_vars = array(
		'session' => $session,
		'pages' => $pages,
		'numrecords' => $numrecords,
		'numperpage' => $numperpage
	);

	return LogicResult::render($page_vars);
}
?>
