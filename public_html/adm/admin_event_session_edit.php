<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/FormWriterV2Base.php'));
	require_once(PathHelper::getIncludePath('data/events_class.php'));
	require_once(PathHelper::getIncludePath('data/videos_class.php'));
	require_once(PathHelper::getIncludePath('data/files_class.php'));
	require_once(PathHelper::getIncludePath('data/event_sessions_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	if (isset($_REQUEST['evs_event_session_id']) || isset($_POST['edit_primary_key_value'])) {
		$session_id = isset($_POST['edit_primary_key_value']) ? $_POST['edit_primary_key_value'] : $_REQUEST['evs_event_session_id'];
		$event_session = new EventSession($session_id, TRUE);
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
		LibraryFunctions::redirect('/admin/admin_event?evt_event_id='.$event->key);
		return;
	}
	else if($_REQUEST['action'] == 'addfile'){
		$event_session = new EventSession($_REQUEST['evs_event_session_id'], TRUE);
		$event_session->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));

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
		$event_session->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
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
			array('evs_start_time'=>'DESC', 'evs_session_number'=>'DESC')
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

			header("Location: /admin/admin_event?evt_event_id=".$event->key);
			exit();
		}

	}
	else if($_POST){

		//TODO FIX THIS FROM HAVING TO BE DONE

		if(!$_POST['evs_vid_video_id']){
			$_POST['evs_vid_video_id'] = NULL;
		}

		if (isset($_POST['evs_session_number'])){
			if(is_null($_POST['evs_session_number']) || $_POST['evs_session_number'] === ''){
				$event_session->set('evs_session_number', NULL);
			}
			else if($_POST['evs_session_number'] >= 0){
				$event_sessions = new MultiEventSessions(
					array('event_id'=>$event->key, 'session_number'=>$_POST['evs_session_number'],'deleted'=>false),
					NULL,
					10,
					0);
				$numsessions = $event_sessions->count_all();
				if($numsessions == 1){
					$event_sessions->load();
					$event_session = $event_sessions->get(0);

					if($_POST['evs_session_number'] == $event_session->get('evs_session_number')){
						$event_session->set('evs_session_number', (int)$_POST['evs_session_number']);
					}
					else{
						throw new SystemDisplayableError('Session '.$_POST['evs_session_number'].' already exists. Please try a different session number.');
					}
				}
				if($numsessions > 1){
					throw new SystemDisplayableError('Sessions with number '.$_POST['evs_session_number'].' already exist. Please try a different session number.');
				}
				else{
					$event_session->set('evs_session_number', (int)$_POST['evs_session_number']);
				}
			}
		}

		// For new sessions, ensure evs_evt_event_id is set from the event object
	if(!$event_session->get('evs_evt_event_id')){
		$event_session->set('evs_evt_event_id', $event->key);
	}

	// Handle start time using FormWriterV2Base helper
	$start_time = FormWriterV2Base::process_datetimeinput($_POST, 'evs_start_time', true);
	if($start_time !== NULL){
		$event_session->set('evs_start_time', $start_time);
	}

	// Handle end time using FormWriterV2Base helper
	$end_time = FormWriterV2Base::process_datetimeinput($_POST, 'evs_end_time', true);
	if($end_time !== NULL){
		$event_session->set('evs_end_time', $end_time);
	}

	$editable_fields = array('evs_content', 'evs_links', 'evs_picture_link', 'evs_is_public', 'evs_title', 'evs_vid_video_id');

	foreach($editable_fields as $field) {
		$event_session->set($field, $_POST[$field]);
	}

	$event_session->prepare();
	$event_session->save();

		LibraryFunctions::redirect('/admin/admin_event?evt_event_id='.$event_session->get('evs_evt_event_id'));
		return;
	}

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'events',
		'page_title' => 'Event session edit',
		'readable_title' => 'Event session edit',
		'breadcrumbs' => array('Events'=>'/admin/admin_events', $event->get('evt_name')=>'/admin/admin_event?evt_event_id='.$event->key,'Add Session'=> ''),
		'uploader' => TRUE,
		'session' => $session,
	)
	);

	$pageoptions['title'] = "New Session";
	$page->begin_box($pageoptions);

	// FormWriter V2 with model and edit_primary_key_value
	$formwriter = $page->getFormWriter('form1', 'v2', [
		'model' => $event_session,
		'edit_primary_key_value' => $event_session->key
	]);

	$formwriter->begin_form();

	// Pass event ID for new sessions (when there's no session ID yet)
	if(!$event_session->key){
		$formwriter->hiddeninput('evt_event_id', '', ['value' => $event->key]);
	}

	$formwriter->textinput('evs_title', 'Title', [
		'validation' => ['required' => true]
	]);

	$optionvals = $event->get_all_valid_session_numbers();
	//ADD IN THE CURRENT SESSION NUMBER
	if($event_session->get('evs_session_number')){
		$optionvals[$event_session->get('evs_session_number')] = $event_session->get('evs_session_number');
	}

	$formwriter->dropinput('evs_session_number', 'Session number (number, for ordering)', [
		'options' => $optionvals,
		'validation' => ['required' => true, 'digits' => true]
	]);

	$formwriter->datetimeinput('evs_start_time', 'Session start time ('. ($event->get('evt_timezone') ? $event->get('evt_timezone') : 'local') . ' timezone)');

	$formwriter->datetimeinput('evs_end_time', 'Session end time ('. ($event->get('evt_timezone') ? $event->get('evt_timezone') : 'local') . ' timezone)');

	$formwriter->textbox('evs_content', 'Session description', [
		'rows' => 5,
		'cols' => 80,
		'htmlmode' => 'yes'
	]);

	$videos = new MultiVideo(
		array('deleted'=>false),
		array('video_id' => 'DESC'),		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$videos->load();
	$optionvals = $videos->get_video_dropdown_array();
	$formwriter->dropinput('evs_vid_video_id', 'Video', [
		'options' => $optionvals,
		'empty_option' => '-- Select --'
	]);

	$formwriter->submitbutton('btn_submit', 'Submit');
	$formwriter->end_form();
	$page->end_box();

	if($event_session->key){
		echo '<h3 style="margin-top: 30px; margin-bottom: 20px;">Additional File Management</h3>';

		$pageoptions['title'] = "Files";
		$page->begin_box($pageoptions);

		// Editing an existing event
		$formwriter = $page->getFormWriter('form2', 'v2');

		$formwriter->begin_form('form2', 'POST', '/admin/admin_event_session_edit');

		$formwriter->hiddeninput('action', '', ['value' => 'addfile']);
		$formwriter->hiddeninput('evs_event_session_id', '', ['value' => $event_session->key]);
		$formwriter->hiddeninput('evs_evt_event_id', '', ['value' => $event_session->get('evs_evt_event_id')]);

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
		$formwriter->dropinput('fil_file_id', 'Add file', [
			'options' => $optionvals,
			'empty_option' => '-- Select --'
		]);

		$formwriter->submitbutton('btn_submit', 'Add file');
		$formwriter->end_form();

		//$page->begin_box();
		echo '<hr><div style="margin-left:20px"><h4>Bulk upload</h4>';
		$formwriter = $page->getFormWriter('fileupload', 'v2');

		echo $formwriter->file_upload_full(array('evs_event_session_id'=> $event_session->key));
		$formwriter->end_form();
		echo '</div>';
		//$page->end_box();

		$page->end_box();
	}
	$page->admin_footer();

?>
