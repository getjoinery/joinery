<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_posts_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/posts_class.php'));

	$session = SessionControl::get_instance();
	$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '', $input);
	$sort = LibraryFunctions::fetch_variable('sort', 'post_id', 0, '', $input);
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '', $input);
	$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '', $input);

	$search_criteria = array();

	//SHOW DELETED ONLY TO THOSE WHO MAY UNDELETE
	if(!Access::permits(Post::access_level('soft_delete'), $session)){
		$search_criteria['deleted'] = false;
	}

	$posts = new MultiPost(
		$search_criteria,
		array($sort=>$sdirection),
		$numperpage,
		$offset);
	$numrecords = $posts->count_all();
	$posts->load();

	$page_vars = array(
		'session' => $session,
		'posts' => $posts,
		'numrecords' => $numrecords,
		'numperpage' => $numperpage
	);

	return LogicResult::render($page_vars);
}
?>
