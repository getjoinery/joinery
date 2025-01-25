<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'].'/data/videos_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/groups_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/events_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);
		
	if($_REQUEST['vid_video_id']){
		$video = new Video($_REQUEST['vid_video_id'], TRUE);
	}
	else{
		$video = new Video(NULL);
	}
		
	
	if($_POST){

		if($_POST['vid_url']){
		
			//WHEN USER FEED IN TEXT CONTAINING VIDEO INFO
			if(!$source = Video::extract_source_from_url($_POST['vid_url'])) {
				$errortext = 'We could not verify that the link you entered is from a video site we support.  Please check the link again and if you continue to have difficulty <a href="/contact">contact us</a> to help.';
				$errorhandler = new ErrorHandler();
				$errorhandler->handle_general_error($errortext);
			}	

			if(!$vid_video_number = Video::extract_number_from_url($source, $_POST['vid_url'])) {
				$errortext = 'We could not verify that the link you entered is valid.  Please check the link again and if you continue to have difficulty <a href="/contact">contact us</a> to help.';
				$errorhandler = new ErrorHandler();
				$errorhandler->handle_general_error($errortext);		
			}

			
			$video->set('vid_usr_user_id', $session->get_user_id());
			$video->set('vid_source', $source);
			$video->set('vid_video_number', $vid_video_number);
			$video->set('vid_video_text', $_POST['vid_url']);
		}

		if($_POST['vid_min_permission'] === NULL || $_POST['vid_min_permission'] === ''){
			$video->set('vid_min_permission', NULL);
		} 
		else{
			$video->set('vid_min_permission', $_POST['vid_min_permission']);
		}

		if($_POST['vid_grp_group_id'] === NULL || $_POST['vid_grp_group_id'] === ''){
			$video->set('vid_grp_group_id', NULL);
		} 
		else{
			$video->set('vid_grp_group_id', $_POST['vid_grp_group_id']);
		}
		
		if($_POST['vid_evt_event_id'] === NULL || $_POST['vid_evt_event_id'] === ''){
			$video->set('vid_evt_event_id', NULL);
		} 
		else{
			$video->set('vid_evt_event_id', $_POST['vid_evt_event_id']);
		}
		
		$video->set('vid_title', $_POST['vid_title']);
		$video->set('vid_description', $_POST['vid_description']);
		
		try {
			$video->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
			$video->save(); 
			$video->load();

			if(!$video->get('vid_link') || $_SESSION['permission'] == 10){
				if($_POST['vid_link']){
					$video->set('vid_link', $video->create_url($_POST['vid_link']));
				}
				else{
					$video->set('vid_link', $video->create_url($video->get('vid_title')));
				}
			}
		} catch (TTClassException $e) {
			$errorhandler = new ErrorHandler();
			$errorhandler->handle_general_error($e->getMessage());
		}

		LibraryFunctions::redirect('/admin/admin_videos');						
		exit();

	}
	else{
		
		$breadcrumbs = array('Videos'=>'/admin/admin_videos');
		if ($video->key) {
			$breadcrumbs += array('Video '.$video->get('vid_title') => '/admin/admin_video?vid_video_id='.$video->key);
			$breadcrumbs += array('Video Edit'=>'');
		}
		else{
			$breadcrumbs += array('New Video' => '');
		}
				
		
		$page = new AdminPage();
		$page->admin_header(	
		array(
			'menu-id'=> 'videos',
			'page_title' => 'Videos',
			'readable_title' => 'Videos',
			'breadcrumbs' => array(
				'Videos'=>'/admin/admin_videos', 
				'Video '.$video->get('vid_title') => '/admin/admin_video?vid_video_id='.$video->key,
				'Video Edit'=>'',
			),
			'session' => $session,
		)
		);			
		
		$options['title'] = 'Edit Video';
		$page->begin_box($options);
		
		if(!$video->key){
			?><p>We currently support YouTube and Vimeo.</p><?php
		}
		
		$formwriter = LibraryFunctions::get_formwriter_object('form1', 'admin');
		
		$validation_rules = array();
		$validation_rules['vid_title']['required']['value'] = 'true'; 
		if(!$video->key){
			$validation_rules['vid_url']['required']['value'] = 'true';
		}
		echo $formwriter->set_validate($validation_rules);				
			
		echo $formwriter->begin_form('form1', 'POST', '/admin/admin_video_edit');

		if($video->key){
			echo $formwriter->hiddeninput('vid_video_id', $video->key);
			echo $formwriter->hiddeninput('action', 'edit');
		}
		
		echo $formwriter->textinput('Video title', 'vid_title', NULL, 100, $video->get('vid_title'), '', 255, '');
		if(!$video->get('vid_link') || $_SESSION['permission'] == 10){
			echo $formwriter->textinput('Link (no spaces, optional): '.$settings->get_setting('webDir').'/video/', 'pag_link', NULL, 100, $video->get('vid_link'), '', 255, '');	
		}
		echo $formwriter->textbox('Video description', 'vid_description', 'ctrlHolder', 5, 80, $video->get('vid_description'), '', 'no');		
		
		if(!$video->key){
			echo $formwriter->textinput('Video link', 'vid_url', 'ctrlHolder', 5, NULL, '', 'no'); 
		}
		
	//echo $formwriter->checkboxinput("List video in index", "vid_is_listed", "checkbox", "left", $file->get('vid_is_listed'), 1, "");

	$optionvals = array('Public (anyone)' => null, 'Any logged in user (0)'=>0, 'Assistant (5)'=>5, 'Admin (8)'=>8, 'Master Admin (10)' => 10);
	echo $formwriter->dropinput("Permission level can access", "vid_min_permission", "ctrlHolder", $optionvals, $video->get('vid_min_permission'), '', FALSE, TRUE);
	
	$groups = new MultiGroup(
		array('category'=>'user'),  //SEARCH 
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$groups->load();

	$optionvals1['All'] = NULL;	
	$optionvals2 = $groups->get_dropdown_array();
	$optionvals = array_merge($optionvals1, $optionvals2);
	echo $formwriter->dropinput("Group can access", "vid_grp_group_id", "ctrlHolder", $optionvals, $video->get('vid_grp_group_id'), '', FALSE, TRUE);

	$events = new MultiEvent(
		array(),  //SEARCH 
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$events->load();

	$optionvals['All'] = NULL;	
	$optionvals2 = $events->get_dropdown_array();
	$optionvals = array_merge($optionvals1, $optionvals2);
	echo $formwriter->dropinput("Event can access", "vid_evt_event_id", "ctrlHolder", $optionvals, $video->get('vid_evt_event_id'), '', FALSE, TRUE);
	
			
	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();

		$page->end_box();

		$page->admin_footer();
	}

?>
