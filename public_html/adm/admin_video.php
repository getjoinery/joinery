<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	PathHelper::requireOnce('includes/ErrorHandler.php');
	
	PathHelper::requireOnce('includes/AdminPage.php');
	PathHelper::requireOnce('includes/SessionControl.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');

	PathHelper::requireOnce('data/users_class.php');
	PathHelper::requireOnce('data/videos_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$video = new Video($_GET['vid_video_id'], TRUE);
	$user = new User($video->get('vid_usr_user_id'), TRUE);
	
	if($_REQUEST['action'] == 'remove'){
		$video->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$video->permanent_delete();

		//$returnurl = $session->get_return();
		header("Location: /admin/admin_videos");
		exit();		
	}	


	if($_REQUEST['action'] == 'delete'){
		$video->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$video->soft_delete();

		header("Location: /admin/admin_videos");
		exit();				
	}
	else if($_REQUEST['action'] == 'undelete'){
		$video->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$video->undelete();

		header("Location: /admin/admin_videos");
		exit();				
	}

	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'videos',
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
		$options['altlinks'] += array('Permanently Delete Video' => '/admin/admin_video?action=remove&vid_video_id='.$video->key);
	}

	$page->begin_box($options);

	$formwriter = LibraryFunctions::get_formwriter_object('form1', 'admin');
	
	
	
	echo '<strong>User:</strong> ('.$user->key.') <a href="/admin/admin_user?usr_user_id='.$user->key.'">'.$user->display_name() .'</a><br />';	
	echo '<strong>Created:</strong> '.LibraryFunctions::convert_time($video->get('vid_create_time'), 'UTC', $session->get_timezone()) .'<br />';
	if($video->get('vid_delete_time')){
		echo 'Status: Deleted at '.LibraryFunctions::convert_time($video->get('vid_delete_time'), 'UTC', $session->get_timezone()).'<br />';
	}
	echo '<br /><strong>Title:</strong> '.$video->get('vid_title') .'<br />';
	echo '<strong>Video permissions:</strong> ';
	$group_or_event=false;
	if($video->get('vid_grp_group_id')){
		$group = new Group($video->get('vid_grp_group_id'), TRUE);
		echo 'ONLY logged in users in the "'.$group->get('grp_name').'" group ';
		$group_or_event=true;
	}
	if($video->get('vid_evt_event_id')){
		$event = new Event($video->get('vid_evt_event_id'), TRUE);
		echo 'ONLY logged in users registered for the "'.$event->get('evt_name').'" event ';
		$group_or_event=true;		
	}
	if($group_or_event){
		if($video->get('vid_min_permission') > 0){
			echo 'with minimum permission ('.$video->get('vid_min_permission').') ';
		}
	}
	else{
		if($video->get('vid_min_permission') === NULL){
			echo 'Anyone ';
		}
		else if($video->get('vid_min_permission') === 0){
			echo 'Anyone logged in';
		}
		else{
			echo 'Minimum permission ('.$video->get('vid_min_permission').') ';
		}		
	}
	
	echo '<br />';		
	echo '<strong>Description:</strong> '.$video->get('vid_description') .'<br />';
	echo '<strong>Link:</strong> <a href="'.$video->get_url().'">'.$video->get_url('short').'</a><br />';	
	echo '<strong>Original:</strong> <a href="'.$video->get('vid_video_text').'">'.$video->get('vid_video_text').'</a><br />';	
	
	echo '<br /><br />';			
	echo '<div class="padding10px">'.$video->get_embed().'</div>';
	echo '<div class="padding10px"><pre>'.htmlspecialchars($video->get_embed()).'</pre></div>';
	

		
	
	$page->admin_footer();
?>


