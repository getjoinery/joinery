<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/videos_class.php'));
	require_once(PathHelper::getIncludePath('data/groups_class.php'));
	require_once(PathHelper::getIncludePath('data/events_class.php'));

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
				require_once(__DIR__ . '/../includes/Exceptions/ValidationException.php');
				throw new ValidationException('We could not verify that the link you entered is from a video site we support.  Please check the link again and if you continue to have difficulty <a href="/contact">contact us</a> to help.');
			}

			if(!$vid_video_number = Video::extract_number_from_url($source, $_POST['vid_url'])) {
				require_once(__DIR__ . '/../includes/Exceptions/ValidationException.php');
				throw new ValidationException('We could not verify that the link you entered is valid.  Please check the link again and if you continue to have difficulty <a href="/contact">contact us</a> to help.');
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
			require_once(__DIR__ . '/../includes/Exceptions/SystemException.php');
			throw new SystemException($e->getMessage());
		}

		LibraryFunctions::redirect('/admin/admin_videos');
		return;

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

		$formwriter = $page->getFormWriter('form1', [
			'model' => $video,
			'edit_primary_key_value' => $video->key
		]);

		$formwriter->begin_form();

		$formwriter->textinput('vid_title', 'Video title', [
			'validation' => ['required' => true]
		]);
		if(!$video->get('vid_link') || $_SESSION['permission'] == 10){
			$formwriter->textinput('pag_link', 'Link (no spaces, optional)', [
				'prepend' => $settings->get_setting('webDir').'/video/'
			]);
		}
		$formwriter->textbox('vid_description', 'Video description', [
			'htmlmode' => 'no'
		]);

		if(!$video->key){
			$formwriter->textinput('vid_url', 'Video link', [
				'validation' => ['required' => true]
			]);
		}

	//echo $formwriter->checkboxinput("List video in index", "vid_is_listed", "checkbox", "left", $file->get('vid_is_listed'), 1, "");

	$optionvals = array(null => 'Public (anyone)', 0=>'Any logged in user (0)', 5=>'Assistant (5)', 8=>'Admin (8)', 10 => 'Master Admin (10)');
	$formwriter->dropinput("vid_min_permission", "Permission level can access", [
		'options' => $optionvals
	]);

	$groups = new MultiGroup(
		array('category'=>'user'),  //SEARCH
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$groups->load();

	$optionvals1['All'] = NULL;
	$optionvals2 = $groups->get_dropdown_array();
	$optionvals = array_merge($optionvals1, $optionvals2);
	$formwriter->dropinput("vid_grp_group_id", "Group can access", [
		'options' => $optionvals
	]);

	$events = new MultiEvent(
		array(),  //SEARCH
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$events->load();

	$optionvals['All'] = NULL;
	$optionvals2 = $events->get_dropdown_array();
	$optionvals = array_merge($optionvals1, $optionvals2);
	$formwriter->dropinput("vid_evt_event_id", "Event can access", [
		'options' => $optionvals
	]);

	$formwriter->submitbutton('btn_submit', 'Submit');
	$formwriter->end_form();

		$page->end_box();

		$page->admin_footer();
	}

?>
