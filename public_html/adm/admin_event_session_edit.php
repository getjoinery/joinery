<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/videos_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/files_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_sessions_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	if (isset($_REQUEST['evs_event_session_id'])) {
		$event_session = new EventSession($_REQUEST['evs_event_session_id'], TRUE);
		$event = new Event($event_session->get('evs_evt_event_id'), TRUE);
	} 
	else if (isset($_REQUEST['evt_event_id'])) {
		$event_session = new EventSession(NULL);
		$event = new Event($_REQUEST['evt_event_id'], TRUE);
	}	
	else{
		echo 'Need an event or a session';
		exit();
	}
	
	if($_REQUEST['action'] == 'delete'){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$sql = 'DELETE FROM evs_event_sessions WHERE evs_event_session_id=:evs_event_session_id';

		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':evs_event_session_id', $event_session->key, PDO::PARAM_INT);
			$count = $q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}
		LibraryFunctions::redirect('/admin/admin_event_sessions?evt_event_id='.$event->key);
		exit;		
	}
	else if($_REQUEST['action'] == 'addfile'){
		$event_session = new EventSession($_REQUEST['evs_event_session_id'], TRUE);
		$event_session->authenticate_write($session);
		
		//IF SOMEONE JUST CLICKS THE BUTTON, FAIL SILENTLY
		if($_REQUEST['fil_file_id']){
			$event_session->add_file($_REQUEST['fil_file_id']);
		}
		
		//$returnurl = $session->get_return();
		header("Location: /admin/admin_event_session_edit?evs_event_session_id=".$event_session->key);
		exit();		
	}	
	else if($_REQUEST['action'] == 'removefile'){
		$event_session = new EventSession($_REQUEST['evs_event_session_id'], TRUE);
		$event_session->authenticate_write($session);
		$event_session->remove_file($_REQUEST['fil_file_id']);

		//$returnurl = $session->get_return();
		header("Location: /admin/admin_event_session_edit?evs_event_session_id=".$event_session->key);
		exit();		
	}	
	else if($_REQUEST['action'] == 'newsession-days'){	

		//PULL LATEST Session
		$searches = array();
		$searches['event_id'] = $event->key;
		$event_sessions = new MultiEventSessions(
			$searches,
			array('time_then_session_number'=>'DESC')
		); 
		$event_sessions->load();	
		$latest_session = $event_sessions->get(0);
		
		if(!$latest_session){
			throw new SystemDisplayableError('There is no previous session with a date and time.');
			exit();			
		}
		else if(!$latest_session->get('evs_start_time')){
			throw new SystemDisplayableError('The previous session has no date and time.  Cannot auto generate a new session.  Please enter the new session manually.');
			exit();					
		}
		else{

			$new_session_number = NULL;
			$event_session = new EventSession(NULL);
			$event_session->set('evs_evt_event_id', $event->key);		
			if($latest_session->get('evs_session_number')){
				$new_session_number = $latest_session->get('evs_session_number') + 1;
				$event_session->set('evs_session_number', $new_session_number);
			}
			
			if($_POST['evs_title']){
				$event_session->set('evs_title', $_POST['evs_title']);
			}
			else if($new_session_number){
				$event_session->set('evs_title', 'Session ' . $new_session_number);
			}
			else{
				$event_session->set('evs_title', 'New Session');
			}
			
			if($latest_session->get('evs_start_time')){
				$start_time = LibraryFunctions::time_shift($latest_session->get('evs_start_time'), $_POST['num_days'], 'c');
				$start_time_local = LibraryFunctions::time_shift($latest_session->get('evs_start_time_local'), $_POST['num_days'], 'c');
				$event_session->set('evs_start_time', $start_time);
				$event_session->set('evs_start_time_local', $start_time_local);
			}
			
			if($latest_session->get('evs_end_time')){
				$end_time = LibraryFunctions::time_shift($latest_session->get('evs_end_time'), $_POST['num_days'], 'c');
				$end_time_local = LibraryFunctions::time_shift($latest_session->get('evs_end_time_local'), $_POST['num_days'], 'c');
				$event_session->set('evs_end_time', $end_time);
				$event_session->set('evs_end_time_local', $end_time_local);
			}

			$event_session->save();
			
			header("Location: /admin/admin_event_sessions?evt_event_id=".$event->key);
			exit();	
		}
	
	}
	else if($_POST){
		
		//TODO FIX THIS FROM HAVING TO BE DONE
		if($_POST['evs_vid_video_id']){
			$event_session->set('evs_vid_video_id', $_POST['evs_vid_video_id']);
		}
		else{
			$event_session->set('evs_vid_video_id', NULL); 
		}
		
		if (isset($_POST['evs_session_number'])){
			if(is_null($_POST['evs_session_number']) || $_POST['evs_session_number'] === ''){
				$event_session->set('evs_session_number', NULL);
			}
			else if($_POST['evs_session_number'] >= 0){
				$event_session->set('evs_session_number', (int)$_POST['evs_session_number']);
			}
		}

		

	
		$editable_fields = array('evs_evt_event_id', 'evs_content', 'evs_links', 'evs_picture_link', 'evs_is_public', 'evs_title');

		foreach($editable_fields as $field) {
			$event_session->set($field, $_POST[$field]);
		}


		if($_POST['evs_start_time_date'] && $_POST['evs_start_time_time']){
			$time_combined = $_POST['evs_start_time_date'] . ' ' . LibraryFunctions::toDBTime($_POST['evs_start_time_time']);
			$utc_time = LibraryFunctions::convert_time($time_combined, $event->get('evt_timezone'),  'UTC', 'c');
			$event_session->set('evs_start_time', $utc_time);
			$event_session->set('evs_start_time_local', $time_combined);
		}
		
		if($_POST['evs_end_time_date'] && $_POST['evs_end_time_time']){
			$time_combined = $_POST['evs_end_time_date'] . ' ' . LibraryFunctions::toDBTime($_POST['evs_end_time_time']);
			$utc_time = LibraryFunctions::convert_time($time_combined, $event->get('evt_timezone'),  'UTC', 'c');
			$event_session->set('evs_end_time', $utc_time);	
			$event_session->set('evs_end_time_local', $time_combined);			
		}


		


		$event_session->prepare();
		$event_session->save();
		
		
		LibraryFunctions::redirect('/admin/admin_event_sessions?evt_event_id='.$event_session->get('evs_evt_event_id'));
		exit;
	}


	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 2,
		'page_title' => 'Event session edit',
		'readable_title' => 'Event session edit',
		'breadcrumbs' => array('Events'=>'/admin/admin_events', $event->get('evt_name')=>'/admin/admin_event_sessions?evt_event_id='.$event->key,'Add Session'=> ''),
		'uploader' => TRUE,
		'session' => $session,
	)
	);	
	
	$pageoptions['title'] = "New Session";
	$page->begin_box($pageoptions);

	// Editing an existing event
	$formwriter = new FormWriterMaster('form1');
	
	$validation_rules = array();
	//$validation_rules['evs_start_time_time']['required']['value'] = 'true';
	//$validation_rules['evs_start_time_date']['required']['value'] = 'true';
	$validation_rules['evs_title']['required']['value'] = 'true';
	$validation_rules['evs_session_number']['required']['value'] = 'true';
	echo $formwriter->set_validate($validation_rules);	
	
	echo $formwriter->begin_form('form1', 'POST', '/admin/admin_event_session_edit');
	
	if($event_session->key){
		echo $formwriter->hiddeninput('evs_event_session_id', $event_session->key);
		echo $formwriter->hiddeninput('evs_evt_event_id', $event_session->get('evs_evt_event_id'));
	}
	else if($event->key){
		echo $formwriter->hiddeninput('evt_event_id', $event->key);
		echo $formwriter->hiddeninput('evs_evt_event_id', $event->key);
	}	
	echo $formwriter->textinput('Title', 'evs_title', NULL, 100, @$event_session->get('evs_title'), '', 255, '');
	echo $formwriter->textinput('Session number (number, for ordering)', 'evs_session_number', NULL, 100, @$event_session->get('evs_session_number'), '', 255, '');
	echo $formwriter->datetimeinput('Session start time ('. ($event->get('evt_timezone') ? $event->get('evt_timezone') : 'local') . ' timezone)', 'evs_start_time', 'ctrlHolder', LibraryFunctions::convert_time(@$event_session->get('evs_start_time_local'), $event->get('evt_timezone'), $event->get('evt_timezone'), 'Y-m-d h:ia'), '', '', '');

	echo $formwriter->datetimeinput('Session end time ('. ($event->get('evt_timezone') ? $event->get('evt_timezone') : 'local') . ' timezone)', 'evs_end_time', 'ctrlHolder', LibraryFunctions::convert_time(@$event_session->get('evs_end_time_local'), $event->get('evt_timezone'), $event->get('evt_timezone'), 'Y-m-d h:ia'), '', '', '');
	
	 
	//$optionvals = array("Hidden"=>0, "Live"=>1);
	//echo $formwriter->dropinput("Published", "evs_is_public", "ctrlHolder", $optionvals, $event_session->get('evs_is_public'), '', FALSE);



	
	//echo $formwriter->textinput('Order', 'evs_order', NULL, 100, $event_session->get('evs_order'), '', 255, '');
	
	/*
	$events = new MultiEvent(
		array('deleted'=>false),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$events->load();
	$optionvals = $events->get_dropdown_array();
	echo $formwriter->dropinput("Event registration?", "evs_evt_event_id", "ctrlHolder", $optionvals, $product->get('pro_evt_event_id'), '', TRUE);		
	*/
	
	echo $formwriter->textbox('Session description', 'evs_content', 'ctrlHolder', 5, 80, @$event_session->get('evs_content'), '', 'yes');
	//echo $formwriter->textbox('Session location', 'evs_location', 'ctrlHolder', 5, 80, $event_session->get('evs_location'), '', 'no');
	
	
	//echo $formwriter->textbox('Event links', 'evs_links', 'ctrlHolder', 5, 80, $event_session->get('evs_links'), '', 'no');
	
	//echo $formwriter->textinput('Picture link', 'evs_picture_link', NULL, 100, $event_session->get('evs_picture_link'), '', 255, '');
	//echo $formwriter->textbox('Session video embed', 'evs_video_link', 'ctrlHolder', 5, 80, $event_session->get('evs_video_link'), '', 'no');
	
	
	$videos = new MultiVideo(
		array('deleted'=>false),
		array('video_id' => 'DESC'),		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$videos->load();
	$optionvals = $videos->get_video_dropdown_array();
	echo $formwriter->dropinput("Video", "evs_vid_video_id", "ctrlHolder", $optionvals, @$event_session->get('evs_vid_video_id'), '', TRUE);
	
	
	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();
	$page->end_box();

	if($event_session->key){
		$pageoptions['title'] = "Files";
		$page->begin_box($pageoptions);

		// Editing an existing event
		$formwriter = new FormWriterMaster('form2');
		
		echo $formwriter->begin_form('form2', 'POST', '/admin/admin_event_session_edit');
		
		echo $formwriter->hiddeninput('action', 'addfile');
		echo $formwriter->hiddeninput('evs_event_session_id', $event_session->key);
		echo $formwriter->hiddeninput('evs_evt_event_id', $event_session->get('evs_evt_event_id'));
		
		

		$session_files = $event_session->get_files();
		$rowcontent = '<ul>';
		foreach($session_files as $session_file){
			$rowcontent .= '<li><a href="/admin/admin_file?fil_file_id='.$session_file->key.'">'.$session_file->get_name().'</a> (<a href="/admin/admin_event_session_edit?action=removefile&evs_event_session_id='.$event_session->key.'&fil_file_id='.$session_file->key.'">remove</a>)</li>';
		}
		$rowcontent .= '</ul>';
		echo $rowcontent;

		
		$files = new MultiFile(
			array('deleted'=>false),
			array('file_id' => 'DESC'),		//SORT BY => DIRECTION
			NULL,  //NUM PER PAGE
			NULL);  //OFFSET
		$files->load();
		$optionvals = $files->get_file_dropdown_array();
		echo $formwriter->dropinput("Add file", "fil_file_id", "ctrlHolder", $optionvals, NULL, '', TRUE, TRUE, FALSE, TRUE);
		
		
		echo $formwriter->start_buttons();
		echo $formwriter->new_form_button('Add file');
		echo $formwriter->end_buttons();
		echo $formwriter->end_form();



		//$page->begin_box();
		echo '<hr><div style="margin-left:20px"><h4>Bulk upload</h4>';
		$formwriter = new FormWriterMaster("fileupload");
		FormWriterMaster::file_upload_full(array('evs_event_session_id'=> $event_session->key));
		$formwriter->end_form();
		echo '</div>';
		//$page->end_box();
		

		$page->end_box();	
	}
	$page->admin_footer();

?>
