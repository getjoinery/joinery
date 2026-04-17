<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function page_logic($get_vars, $post_vars, $page, $params){
	require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/page_contents_class.php'));
	require_once(PathHelper::getIncludePath('data/pages_class.php'));

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;


	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;
	if(!$settings->get_setting('page_contents_active')){
		return LogicResult::error('This feature is turned off');
	}
	
	$page_vars['page'] = $page;

	if(!$page){
		require_once(LibraryFunctions::display_404_page());	
	}

	if ($session->get_user_id() && $session->get_permission() > 4) {
		//SHOW IT EVEN IF UNPUBLISHED OR DELETED
	}
	else {
		if(!$page->get('pag_published_time') || $page->get('pag_delete_time')){
			require_once(LibraryFunctions::display_404_page());	
		}
	}	
	
	
	return LogicResult::render($page_vars);
}
?>

