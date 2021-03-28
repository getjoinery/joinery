<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/posts_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/comments_class.php');

	$settings = Globalvars::get_instance();
	if(!$settings->get_setting('blog_active')){
		//TURNED OFF
		header("HTTP/1.0 404 Not Found");
		echo 'This setting is turned off';
		exit();			
	}


	if(!$post || !$post->get('pst_is_published') || $post->get('pst_delete_time')){
		require_once(LibraryFunctions::display_404_page());		
	}

	$session = SessionControl::get_instance();
	$session->set_return();
	
	if($_POST){
		
		Comment::add_comment($post->key, $session, $_POST);

		header('Location: '.$_SERVER['REQUEST_URI']);
		exit();
	}

?>