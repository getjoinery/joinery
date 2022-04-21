<?php
	require_once( __DIR__ . '/../includes/Globalvars.php');
	require_once( __DIR__ . '/../includes/SessionControl.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPageTW.php', '/includes'));
	

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/posts_class.php');


	$session = SessionControl::get_instance();
	$session->check_permission(5);

	$post = new Post($_GET['pst_post_id'], TRUE);

	
	PublicPageTW::OutputGenericPublicPage($post->get('pst_title'), $post->get('pst_title'), $post->get('pst_body'));
			

?>
