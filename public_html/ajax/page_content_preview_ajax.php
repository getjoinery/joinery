<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	
	$settings = Globalvars::get_instance();
	$site_template = $settings->get_setting('site_template');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/theme/'.$site_template.'/includes/PublicPageTW.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/page_contents_class.php');


	$session = SessionControl::get_instance();
	$session->check_permission(5);

	$page_content = new PageContent($_GET['pac_page_content_id'], TRUE);
	
	PublicPageTW::OutputGenericPublicPage($page_content->get('pac_title'), $page_content->get('pac_title'), $page_content->get_filled_content());
			

?>
