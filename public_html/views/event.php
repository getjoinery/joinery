<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once (LibraryFunctions::get_logic_file_path('event_logic.php'));
	require_once(LibraryFunctions::get_theme_includes_path().'/PublicPage.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/FormWriterPublic.php');
	
	$page = new PublicPage(TRUE);
	$page->public_header(array(
		'title' => $event->get('evt_name')
	));
	echo PublicPage::BeginPage($event->get('evt_name'));

	echo '<h2>'.$event->get('evt_name').'</h2>';
	
	if($time){
		echo '<span>'. $time . '</span><br>';
	}

	if($time_user){
		echo '(<span>'. $time_user . '</span>)<br>';
	}
				
	if($event->get('evt_location')){
		echo '<span>Location: '. $event->get('evt_location') . '</span><br />';
	}
		
	if($event->get('evt_usr_user_id_leader')){
		$leader = new User($event->get('evt_usr_user_id_leader'), TRUE);
		echo '<span>Led by '. $leader->display_name() . '</span>';
	}
			
	if($picture_link = $event->get_picture_link('medium')){
		echo '<div><img width="500" src="'.$picture_link. '"  alt="" /></div>';
	}				

	echo '<p>'.$event->get('evt_description').'</p>';
	echo '<a href="'.$event->get_url().'">Read More</a>';

	if($view_course_link){
		echo '<p><a class="et_pb_button" href="/profile/event_sessions_course?event_id='.$event->key.'">View Course</a></p>';		
	}
	
	if($registration_message){
		echo '<p>'.$registration_message.'</p>';
	}

	if($register_link){
		echo '<p><a class="et_pb_button" href="'.$register_link.'">Register Now</a></p>';
	}
	
	if($waiting_list_link){
		echo '<p><a href="'.$waiting_list_link.'">Get on the waiting list</a></p>';
	}
	
	if($if_registered_message){
		echo '<p>'.$if_registered_message.'</p>';
	}



	//CHECK FOR SESSIONS
	if($event->get('evt_session_display_type') == Event::DISPLAY_SEPARATE && $numsessions > 0){
		echo '<h2>Course Details</h2>';

		foreach($event_sessions as $event_session){	
			if($event_session->get('evs_session_number')){
				echo '<h3>Session ' . $event_session->get('evs_session_number') . ' -  ' . $event_session->get('evs_title'). '</h3>';
			}
			else{
				echo $event_session->get('evs_title');						
			}
			
			echo preg_replace('#<a.*?>(.*?)</a>#i', '\1', $event_session->get('evs_content'));	
		}
		$page->endtable();	
	}
	else{	
		if($future_numsessions > 0){
			echo '<h3>Upcoming Sessions</h3>';

			foreach($future_event_sessions as $event_session){			
				echo '<strong>' . $event_session->get_time_string($tz) . '</strong>  -  ' . $event_session->get('evs_title').'<br>';				
			}
			$page->endtable();	
		}


		if($past_numsessions > 0){
			echo '<h3>Past Sessions</h3>';

			foreach($past_event_sessions as $event_session){			
				echo '<strong>' . $event_session->get_time_string($tz) . '</strong>  -  ' . $event_session->get('evs_title');
				echo ' <a href="/profile/event_sessions?evt_event_id='. $event->key.'">View videos and materials</a><br>';					
			}
			$page->endtable();	
		}			
	}


	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>

