<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function location_logic($get_vars, $post_vars, $location, $params){
	PathHelper::requireOnce('includes/SessionControl.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');

	PathHelper::requireOnce('data/page_contents_class.php');
	PathHelper::requireOnce('data/pages_class.php');

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;


	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;
	if(!$settings->get_setting('page_contents_active')){
		header("HTTP/1.0 404 Not Found");
		echo 'This feature is turned off';
		exit();
	}
	
	$page_vars['location'] = $location;

	if($params[0] != 'location' || !$location){
		require_once(LibraryFunctions::display_404_page());	
	}
	
	if ($session->get_user_id() && $session->get_permission() > 4) {
		//SHOW IT EVEN IF UNPUBLISHED OR DELETED
	}
	else {
		if(!$location->get('loc_is_published') || $location->get('loc_delete_time')){
			require_once(LibraryFunctions::display_404_page());	
		}
	}	
	
	
	//TODO:  WORK IN PROGRESS...SCRIPT FILENAMES
	/*
	if($page->get('pac_script_filename')){
		//THIS IS A STANDALONE FILE
		include($page->get('pac_script_filename'));
		exit();
	}	
	*/
	return $page_vars;
}
?>

