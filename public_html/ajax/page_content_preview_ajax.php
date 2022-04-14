<?php
	require_once( __DIR__ . '/../includes/Globalvars.php');
	require_once( __DIR__ . '/../includes/DbConnector.php');
	require_once( __DIR__ . '/../includes/FormattingFunctions.php');
	require_once( __DIR__ . '/../data/page_contents_class.php');
	
	$settings = Globalvars::get_instance();
	$site_template = $settings->get_setting('site_template');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/theme/'.$site_template.'/includes/PublicPageTW.php');



	$session = SessionControl::get_instance();
	$session->check_permission(5);

	$page_content = new PageContent($_GET['pac_page_content_id'], TRUE);
	
	PublicPageTW::OutputGenericPublicPage($page_content->get('pac_title'), $page_content->get('pac_title'), $page_content->get_filled_content());
			

?>
