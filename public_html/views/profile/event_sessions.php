<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	$logic_path = LibraryFunctions::get_logic_file_path('event_sessions_logic.php');
	require_once ($logic_path);	
	
	$settings = Globalvars::get_instance();
	$site_template = $settings->get_setting('site_template');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/theme/'.$site_template.'/includes/PublicPage.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/theme/'.$site_template.'/includes/FormWriterPublic.php');	

	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/calendar-links/Link.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/calendar-links/Generator.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/calendar-links/Generators/Google.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/calendar-links/Generators/Ics.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/calendar-links/Generators/Yahoo.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/calendar-links/Generators/WebOutlook.php');
	use Spatie\CalendarLinks\Link;

	$page = new PublicPage();
	$hoptions = array(
		'title' => 'Sessions - My Profile', 
		'currentmain' => 'Account',
		'currentsub' => 'Dashboard',
	);
	$page->public_header($hoptions,NULL);
	echo '<a class="back-link" href="/profile/profile">My Profile</a> > '.$event->get('evt_name').' sessions<br />';
	echo PublicPage::BeginPage($event->get('evt_name'));
	
	if($error_message){
		echo $error_message;
	}
	else{
		echo '<span class="dashicons dashicons-calendar"></span><span class="ee-event-datetimes-li-daterange">'. $event->get_time_string() . '</span><br/>';

		if($event->get('evt_timezone') != $session->get_timezone()){
				echo '(<span class="dashicons dashicons-calendar"></span><span class="ee-event-datetimes-li-daterange">'. $event->get_time_string($session->get_timezone()) . '</span>)<br/>';
		}	
		
		//CALENDAR LINKS
		//FROM https://github.com/spatie/calendar-links	
		if($event->get('evt_start_time') && $event->get('evt_show_add_to_calendar_link')){
			$start_time_obj = LibraryFunctions::get_time_obj($event->get_event_start_time($session->get_timezone()), $session->get_timezone());	
			$end_time_obj = LibraryFunctions::get_time_obj($event->get_event_end_time($session->get_timezone()), $session->get_timezone());
			$settings = Globalvars::get_instance();
			$webDir = $settings->get_setting('webDir_SSL');	
			$cal_link = $webDir.'/profile/event_sessions?evt_event_id='.$event->key;
			$link = Link::create($event->get('evt_name'), $start_time_obj, $end_time_obj)
				->description($event->get('evt_short_description'))
				->address($cal_link);
				//->address('Kruikstraat 22, 2018 Antwerpen');
			echo 'Add to calendar: <a href="'.$link->google().'">google</a> | ';
			echo '<a href="'.$link->yahoo().'">yahoo</a> | ';
			echo '<a href="'.$link->webOutlook().'">outlook</a> | ';
			echo '<a href="'.$link->ics().'">ical</a> ';	
		}
		echo '<br /><br />';
		
		//DISPLAY REGISTER FINISH LINKS FOR ANY EVENTS
		if($event->get('evt_collect_extra_info')){
			$event_registrants = new MultiEventRegistrant(array('user_id' => $session->get_user_id(), 'event_id' => $event->key), NULL);
			$event_registrants->load();
			foreach($event_registrants as $event_registrant){
				if(!$event_registrant->get('evr_extra_info_completed')){
					$act_code = Activation::CheckForActiveCode($user->key, Activation::EMAIL_VERIFY);
					$line = 'Your registration for <strong>'.$event->get('evt_name').'</strong> needs some additional information. <a href="/profile/event_register_finish?act_code='.$act_code->act_code.'&userid='.$user->key.'&eventregistrantid='.$event_registrant->key.'">click here to add the information</a>';
					echo '<div class="status_warning">'.$line.'</div><br /><br />';
				}
			}
		}		

		?>
		<div uk-grid>
			<div class="uk-width-1-3@m"><div style="padding: 20px">
			<?php

			
		echo '<h2>Live Info</h2>';
		if($event->get('evt_private_info')){
			echo $event->get('evt_private_info');
		}
		
		if($event_detail){
			if($event_detail->get('evd_video_link')){
				echo '<a href="'.$event_detail->get('evd_video_link').'" />Video link</a>';
			}	
			else if($event_detail->get('evd_picture_link')){
				echo '<img style="float:left; width:500px; margin: 0px 30px 30px 0px;" src="'.$event_detail->get('evd_picture_link').'" />';
			}
			
			//echo $event_detail->get('evd_content');
			
			//echo '<div style="clear:both;">';
			
			//echo '<h3>Location</h3>';
			echo $event_detail->get('evd_location');
			echo $event_detail->get('evd_links');
			//echo '<h3>Schedule</h3>';
			//echo $event_detail->get('evd_schedule');
			
			//echo '</div>';
		}

		echo '	</div>
			</div>
			<div class="uk-width-2-3@m"><div style="padding: 20px">';

		if($next_session){
			echo '<h2>Next Session:</h2>';
			echo '<div>';

			if($next_session->get('evs_title')){
				echo $next_session->get('evs_title');
			}
			else{
				echo 'Session '.$session_number;
			}
			
			if($event->get('evt_timezone') == $session->get_timezone()){
				echo '</h4>: '.$next_session->get_time_string($event->get('evt_timezone')) . '<br />';				
			}
			else{
				echo '</h4>: '.$next_session->get_time_string($event->get('evt_timezone')) . ' (Your time: ' . $next_session->get_time_string($session->get_timezone()). ')<br />';
			}
			//CALENDAR LINKS
			//FROM https://github.com/spatie/calendar-links	
			if($next_session->get('evs_start_time')){
				$start_time_obj = LibraryFunctions::get_time_obj($next_session->get_start_time($session->get_timezone()), $session->get_timezone());	
				$end_time_obj = LibraryFunctions::get_time_obj($next_session->get_end_time($session->get_timezone()), $session->get_timezone());
				$settings = Globalvars::get_instance();
				$webDir = $settings->get_setting('webDir_SSL');	
				$cal_link = $webDir.'/profile/event_sessions?evt_event_id='.$event->key;
				$link = Link::create($event->get('evt_name'), $start_time_obj, $end_time_obj)
					->description($event->get('evt_short_description'))
					->address($cal_link);
					//->address('Kruikstraat 22, 2018 Antwerpen');
				echo 'Add to calendar: <a href="'.$link->google().'">google</a> | ';
				echo '<a href="'.$link->yahoo().'">yahoo</a> | ';
				echo '<a href="'.$link->webOutlook().'">outlook</a> | ';
				echo '<a href="'.$link->ics().'">ical</a> ';	
			}
			
			echo '<div>'.$next_session->get('evs_content').'</div>';	
			echo '</div>';		
		}

		if ($num_sessions){
			echo '<h2>Past Sessions</h2>';
		}
		else{
			echo '<h2>Past Sessions</h2><p>There are no sessions here.</p>';
		}

		$session_number = 1;
		foreach($event_sessions as $event_session){
			if($event_session->get('evs_vid_video_id')){
				$video = new Video($event_session->get('evs_vid_video_id'), TRUE);
			}
			else{
				$video = new Video(NULL);
			}		
			
			echo '<div><h4>';

			if($event_session->get('evs_title')){
				echo $event_session->get('evs_title');
			}
			else{
				echo 'Session '.$session_number;
			}
			echo '</h4>'.$event_session->get_start_time($event->get('evt_timezone')) . ' (' . $event_session->get_start_time($session->get_timezone()). ')<br />';


			echo $video->get_embed();
			echo '<div>'.$event_session->get('evs_content').'</div>';
			
			$session_files = $event_session->get_files();
			$rowcontent = '<ul>';
			foreach($session_files as $session_file){
				$rowcontent .= '<li><a href="'.$session_file->get_url().'">'.$session_file->get_name().'</a></li>';
			}
			$rowcontent .= '</ul>';
			echo $rowcontent;
				
			echo '</div><hr>';
			
			
			$session_number++;
		}
		if($num_sessions > 5){
			echo '<p><a class="et_pb_button" href="/profile/event_sessions?show_all=1&evt_event_id='.$event->key.'">See all '.$num_sessions.' sessions</a></p>';
		}
		

			?>
		</div>
		</div>
		<?php
	}


	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'show_survey'=>TRUE));
?>
