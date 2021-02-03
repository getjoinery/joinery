<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	
	$settings = Globalvars::get_instance();
	$site_template = $settings->get_setting('site_template');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/theme/'.$site_template.'/includes/PublicPage.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/posts_class.php');


	$session = SessionControl::get_instance();
	$session->check_permission(5);

	$post = new Post($_GET['pst_post_id'], TRUE);

	
	PublicPage::OutputGenericPublicPage($post->get('pst_title'), $post->get('pst_title'), $post->get('pst_body'));
			

?>
