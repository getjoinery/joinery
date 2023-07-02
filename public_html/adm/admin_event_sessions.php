<?php

	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_registrants_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_sessions_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/files_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/videos_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();	

	if($_REQUEST['action'] == 'removefile'){
		$event_session = new EventSession($_REQUEST['evs_event_session_id'], TRUE);
		$event_session->authenticate_write($session);
		$event_session->remove_file($_REQUEST['fil_file_id']);

		//$returnurl = $session->get_return();
		header("Location: /admin/admin_event_sessions?evt_event_id=".$event_session->get('evs_evt_event_id'));
		exit();		
	}	
	
	$event_id = LibraryFunctions::fetch_variable('evt_event_id', '', TRUE);
	$event = new Event($event_id, TRUE);

	$user_id = LibraryFunctions::fetch_variable('u', NULL, 0, '');
	
	
	$searches = array();
	if($_REQUEST['showpast'] == 'all'){
		//nothing
	}
	else{
		$searches['past'] = FALSE;
	}
	
	$event = new Event($event_id, TRUE);

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'events',
		'page_title' => 'Event Sessions',
		'readable_title' => 'Event Sessions',
		'breadcrumbs' => array(
			'Events'=>'/admin/admin_events', 
			$event->get('evt_name') => '/admin/admin_event?evt_event_id='.$event->key,
			'Sessions'=>'',
		),
		'session' => $session,
	)
	);	
		
		$options['title'] = $event->get('evt_name');
			$options['altlinks'] = array();
			if(!$event->get('evt_delete_time')) {
				if($_SESSION['permission'] > 7){
					$options['altlinks'] += array('Edit Event' => '/admin/admin_event_edit?evt_event_id='.$event->key);
				}
			}
			else {
				//echo '<a class="dropdown-item" href="/admin/admin_events_undelete?evt_event_id='.$event->key.'">Undelete</a>';
			}

		$page->begin_box($options);
	?>
              <p class="text-muted text-center"><?php echo $event->get_event_start_time() . ' - ' . $event->get_event_end_time(); ?></p>
			  
			  <p class="text-center">
			  <?php
				if($event->get('evt_visibility') == 0) {
					echo '<b>Private</b><br />';
				} 
				else if($event->get('evt_visibility') == 1){
					echo '<b>Public</b> - <a href="' . $event->get_url() . '">Public link</a><br />';
				}
				else{
					echo '<b>Public but unlisted</b> - <a href="' . $event->get_url() . '">Public link</a><br />';
				}		
				echo '<a href="/profile/event_sessions_course?evt_event_id='. $event->key .'">Sessions link</a><br />';
				?>
				</p>
			  <p class="text-center">
			  <?php
				if($event->get('evt_is_accepting_signups')) {
					echo '<b>Registration open</b><br />';
				} 
				else{
					echo '<b>Registration closed</b><br />';
				}		
				?>
				</p>
	<?php

		$page->end_box();
	?>
	<nav class="uk-navbar-container" uk-navbar>
		<div class="uk-navbar-left">
			<ul class="uk-navbar-nav">
				<li class="uk-parent"><a href="/admin/admin_event?evt_event_id=<?php echo $event->key; ?>">Registrants</a></li>
				<li class="uk-active"><a href="#">Sessions</a></li>
				<li><a href=""></a></li>
			</ul>
		</div>
	</nav>
	<?php


	//WAITING LIST
	$wnumperpage = 10;
	$woffset = LibraryFunctions::fetch_variable('woffset', 0, 0, '');
	$wsort = LibraryFunctions::fetch_variable('wsort', 'time_then_session_number', 0, '');
	$wsdirection = LibraryFunctions::fetch_variable('wsdirection', 'DESC', 0, '');
	$wsearchterm = LibraryFunctions::fetch_variable('wsearchterm', '', 0, '');
	$wsearch_criteria = array();
	$wsearch_criteria['event_id'] = $event->key;
	$event_sessions = new MultiEventSessions(		
		$wsearch_criteria,
		array($wsort=>$wsdirection),
		$wnumperpage,
		$woffset);
	$numsessions = $event_sessions->count_all();
	$event_sessions->load();
	$wpager = new Pager(array('numrecords'=>$numsessions, 'numperpage'=> $wnumperpage), 'w');
	

	$headers = array("Title", "Time", "Links", "Picture/Video", "Edit");
	$altlinks = array('Add Session'=>'/admin/admin_event_session_edit?evt_event_id='.$event->key);
	$box_vars =	array(
		'altlinks' => $altlinks,
		'title' => "Sessions"
	);
	$page->tableheader($headers, $box_vars,$wpager);
	
	if($_SESSION['permission'] >= 8 && $numsessions){ 
		$delform = '<tr colspan="5"><form id="form2" class="form2" name="form2" method="POST" action="/admin/admin_event_session_edit?evt_event_id='. $event->key.'">';
		$delform .= '
		<label for="num_days" class="">Create new session </label><input name="num_days" value="7" type="text" /> days ahead  <label for="evs_title" class="">with title </label><input name="evs_title" type="text" />
		<input type="hidden" class="hidden" name="action" id="action" value="newsession-days" />
		<button type="submit">Submit</button>
		</form></tr>';
		echo $delform;
	}

	foreach($event_sessions as $event_session){
		if($event_session->get('evs_vid_video_id')){ 
			$video = new Video($event_session->get('evs_vid_video_id'), TRUE);
		}
		else{
			$video = new Video(NULL);
		}

		
		$rowvalues = array();		
		
		
		$session_label = '';
		if($event_session->get('evs_session_number')){
			$session_label = 'Session '.$event_session->get('evs_session_number'). ' - ';
		}
		array_push($rowvalues, $session_label.$event_session->get('evs_title'));
		array_push($rowvalues, $event_session->get_time_string());
		//array_push($rowvalues, $event_session->get('evs_location'));
		
		$session_files = $event_session->get_files();
		$rowcontent = '<ul>';
		foreach($session_files as $session_file){
			$rowcontent .= '<li><a href="/admin/admin_file?fil_file_id='.$session_file->key.'">'.$session_file->get_name().'</a> (<a href="/admin/admin_event_sessions?action=removefile&evs_event_session_id='.$event_session->key.'&fil_file_id='.$session_file->key.'">remove</a>)</li>';
		}
		$rowcontent .= '</ul>';
			
		array_push($rowvalues, $rowcontent);
		if($event_session->get('evs_picture_link')){
			array_push($rowvalues, $event_session->get('evs_picture_link'));
		}
		else{
			array_push($rowvalues, $video->get_embed(300, 168));
		}
		
		array_push($rowvalues, '<a href="/admin/admin_event_session_edit?evs_event_session_id='. $event_session->key .'">edit</a> |
		<a href="/admin/admin_event_session_edit?action=delete&evs_event_session_id='. $event_session->key .'">delete</a>');
		$page->disprow($rowvalues);
	}
	$page->endtable($wpager);

	$page->admin_footer();


?>
