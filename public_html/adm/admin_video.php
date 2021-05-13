<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/videos_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$video = new Video($_GET['v'], TRUE);
	$user = new User($video->get('vid_usr_user_id'), TRUE);
	
	if($_REQUEST['action'] == 'remove'){
		$video->authenticate_write($session);
		$video->permanent_delete();

		//$returnurl = $session->get_return();
		header("Location: /admin/admin_videos");
		exit();		
	}	


	if($_REQUEST['action'] == 'delete'){
		$video->authenticate_write($session);
		$video->soft_delete();

		header("Location: /admin/admin_videos");
		exit();				
	}
	else if($_REQUEST['action'] == 'undelete'){
		$video->authenticate_write($session);
		$video->undelete();

		header("Location: /admin/admin_videos");
		exit();				
	}

	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 3,
		'page_title' => 'Videos',
		'readable_title' => 'Videos',
		'breadcrumbs' => array(
			'Videos'=>'/admin/admin_videos', 
			'Video: ' . $video->get('vid_title') => '',
		),
		'session' => $session,
	)
	);
	
	$options['title'] = 'Video: ' . $video->get('vid_title');
	$options['altlinks'] = array('Edit Video'=>'/admin/admin_video_edit?vid_video_id='.$video->key);
	if($video->get('vid_delete_time')){
		$options['altlinks']['Undelete'] = '/admin/admin_video?action=undelete&vid_video_id='.$video->key;
	}
	else{
		$options['altlinks']['Delete'] = '/admin/admin_video?action=delete&vid_video_id='.$video->key;
	}
	if($session->get_user_id() == 1){
		$options['altlinks'] += array('Permanently Delete Video' => '/admin/admin_video?action=remove&v='.$video->key);
	}

	$page->begin_box($options);

	$formwriter = new FormWriterMaster("form1");
	
	
	
	echo '<strong>User:</strong> ('.$user->key.') <a href="/admin/admin_user?usr_user_id='.$user->key.'">'.$user->display_name() .'</a><br />';	
	echo '<strong>Created:</strong> '.LibraryFunctions::convert_time($video->get('vid_create_time'), 'UTC', $session->get_timezone()) .'<br />';
	if($video->get('vid_delete_time')){
		echo 'Status: Deleted at '.LibraryFunctions::convert_time($video->get('vid_delete_time'), 'UTC', $session->get_timezone()).'<br />';
	}
	echo '<br /><strong>Title:</strong> '.$video->get('vid_title') .'<br />';	
	echo '<strong>Description:</strong> '.$video->get('vid_description') .'<br />';
	echo '<strong>Original:</strong> <a href="'.$video->get('vid_video_text').'">'.$video->get('vid_video_text').'</a><br />';	
	
	echo '<br /><br />';			
	echo '<div class="padding10px">'.$video->get_embed().'</div>';
	echo '<div class="padding10px"><pre>'.htmlspecialchars($video->get_embed()).'</pre></div>';
	

		
	
	$page->admin_footer();
?>


