<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once (LibraryFunctions::get_logic_file_path('events_logic.php'));
	require_once(LibraryFunctions::get_theme_includes_path().'/PublicPage.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/FormWriterPublic.php');

	$page = new PublicPage(TRUE);
	$page->public_header(array(
		'title' => 'Events'
	));
	echo PublicPage::BeginPage('Retreats and Events');
	
	echo '<a href="/events?past=1">Click here to see past events</a>';

	foreach ($events as $event){
		if($event->get_picture_link('small')){
			echo '<img style="float: left;" src="'.$event->get_picture_link('small'). '" alt="'.$event->get('evt_name').'" height="380">';
		}	
		else{
			echo '<img style="float: left;" src="https://source.unsplash.com/gMsnXqILjp4/640x380" alt="">';
		}

		echo '<div>';
		$now = LibraryFunctions::get_current_time_obj('UTC');
		$event_time = LibraryFunctions::get_time_obj($event->get('evt_start_time'), 'UTC');
		if($event->get('evt_start_time') && $event_time > $now){				
			echo $event->get_event_start_time($tz, 'M'); 
			echo $event->get_event_start_time($tz, 'd'); 				
		}
		else if($next_session = $event->get_next_session()){
			echo $next_session->get_start_time($tz, 'M'); 
			echo $next_session->get_start_time($tz, 'd'); 						
		}
		echo '</div>';
	  
		echo '<h3>'.$event->get('evt_name').'</h3>';

		if($event->get('evt_usr_user_id_leader')){
			$leader = new User($event->get('evt_usr_user_id_leader'), TRUE);
			echo '<p>By '. $leader->display_name().'</p>';
		}
		else{
			echo '<p>Various instructors</p>';
		}

		if($event->get('evt_short_description')){
			echo '<p>'. $event->get('evt_short_description').'</p>';
		}

		echo '<p><a href="'.$event->get_url().'">Read More</a></p><hr>'; 
	}	
  
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>

