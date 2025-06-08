<?php

function page_logic($get_vars, $post_vars, $page, $params){
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'].'/data/page_contents_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/pages_class.php');

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;


	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;
	if(!$settings->get_setting('page_contents_active')){
		header("HTTP/1.0 404 Not Found");
		echo 'This feature is turned off';
		exit();
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

