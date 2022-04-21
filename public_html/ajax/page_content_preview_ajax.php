<?php
	require_once( __DIR__ . '/../includes/Globalvars.php');
	require_once( __DIR__ . '/../includes/DbConnector.php');
	require_once( __DIR__ . '/../data/page_contents_class.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPageTW.php', '/includes'));




	$session = SessionControl::get_instance();
	$session->check_permission(5);

	$page_content = new PageContent($_GET['pac_page_content_id'], TRUE);
	
	PublicPageTW::OutputGenericPublicPage($page_content->get('pac_title'), $page_content->get('pac_title'), $page_content->get_filled_content());
			

?>
