<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/PublicPage.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/FormWriterPublic.php');

	require_once($_SERVER['DOCUMENT_ROOT'].'/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/event_sessions_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/event_registrants_class.php');

	$session = SessionControl::get_instance();
	
	$event = Event::get_by_link($static_routes_path);
	if(!$event || !$event->get('evt_visibility')){
		require_once(LibraryFunctions::display_404_page());				
	}

	
	$page = new PublicPage(TRUE);
	$page->public_header(array(
	'title' => 'Checkout',
	'profilenav' => TRUE,
	));
	echo PublicPage::BeginPage($event->get('evt_name'));
?>

	
		
		<strong>
		<?php 

		$time = NULL;
		
		$tz = $event->get('evt_timezone');

		if($event->get_event_start_time($tz)){
			$time = $event->get_event_start_time($tz);
		}
		if($event->get_event_end_time($tz)){
			$time .= ' - ' . $event->get_event_end_time($tz);
		}
		
		if($time){
			echo '<span class="dashicons dashicons-calendar"></span><span class="ee-event-datetimes-li-daterange">'. $time . '</span><br/>';
		}
		

		if($event->get('evt_timezone') != $session->get_timezone()){
			$time_user = NULL;
			if($event->get_event_start_time($session->get_timezone())){
				$time_user = $event->get_event_start_time($session->get_timezone());
			}
			if($event->get_event_end_time($session->get_timezone())){
				$time_user .= ' - ' . $event->get_event_end_time($session->get_timezone());
			}
			if($time_user){
				echo '(<span class="dashicons dashicons-calendar"></span><span class="ee-event-datetimes-li-daterange">'. $time_user . '</span>)<br/>';
			}
		}			
		
		if($event->get('evt_location')){
			echo '<span>'. $event->get('evt_location') . '</span><br />';
		}
		
		if($event->get('evt_usr_user_id_leader')){
			$leader = new User($event->get('evt_usr_user_id_leader'), TRUE);
			echo '<span>Led by '. $leader->display_name() . '</span>';
		}
		
		
					?>	</strong>						
	
	
	<div class="archive-content">
		
				<?php
				if($picture_link = $event->get_picture_link('medium')){
					echo '<div >
				<img width="500" src="'.$picture_link. '" class="attachment-twentyseventeen-featured-image size-twentyseventeen-featured-image wp-post-image" alt="" /></div><!-- .post-thumbnail -->	';
				}				

				?>	
		

		<div class="entry-summary">
			<div class="archive-info"> 

	<div class="archive-excerpt"><?php echo $event->get('evt_description'); ?></div>
													<div class="readmore-button">
					<!--<a class="readmore" href="<?php echo $event->get_url(); ?>">Read More</a>-->
					<?php
					$registrants = new MultiEventRegistrant(
						array('event_id'=>$event->key)
					);
					$numregistrants = $registrants->count_all();
					
					$is_registered = 0;
					if($session->get_user_id()){
						$is_registered = EventRegistrant::check_if_registrant_exists($session->get_user_id(), $event->key);
					}
					
					if($is_registered){
						echo '<p><a class="et_pb_button" href="/profile/event_sessions_course?event_id='.$event->key.'">View Course</a></p>';
					}
					else{
						if($event->get('evt_status') == 2){
							echo '<p>This event is complete.</p>';
						}	
						else if($event->get('evt_status') == 3){
							echo '<p>This event has been cancelled.</p>';
						}						
						else if($event->get('evt_is_accepting_signups') && $event->get_register_url()){		
							if($event->get('evt_allow_waiting_list') && ($event->get('evt_max_signups') && $numregistrants >= $event->get('evt_max_signups'))){
								echo '<p>Registration is full, but you may add yourself to the waiting list.</p>';
								echo '<p><a class="et_pb_button" href="/event_waiting_list?event_id='.$event->key.'">Get on the waiting list</a></p>';
							}
							else if($event->get('evt_max_signups') && $numregistrants >= $event->get('evt_max_signups')){
								echo '<p>This event is full.</p>';
							}
							else{
								echo '<p><a class="et_pb_button" href="'.$event->get_register_url().'">Register Now</a></p>';		
							}
						}
						else if($event->get('evt_allow_waiting_list')){
								echo '<p>Registration is not open yet, but you may add yourself to the waiting list.</p>';
								echo '<p><a class="et_pb_button" href="/event_waiting_list?event_id='.$event->key.'">Get on the waiting list</a></p>';
						}
						else{
							echo '<p><strong>There is no registration for this event at this time.</strong></p>';
						}
					
						if($numregistrants){
							if($event->get('evt_session_display_type') == 2){
								echo '<p>If you are registered, you can access the course link, info, videos, and materials <a href="/profile/event_sessions_course?event_id='.$event->key.'">in the my profile section of the website</a>.</p>';
							}
							else{
								echo '<p>If you are registered, you can access the course link, info, videos, and materials <a href="/profile/event_sessions?evt_event_id='.$event->key.'">in the my profile section of the website</a>.</p>';							
							}
						}
					}

					?>
				</div>
	<?php
	//CHECK FOR SESSIONS
	

		if($event->get('evt_session_display_type')==2){
			$searches = array();
			$searches['event_id'] = $event->key;
			$event_sessions = new MultiEventSessions($searches,
				array('time_then_session_number'=>'ASC')); 
			$event_sessions->load();	
			$numsessions = $event_sessions->count_all();
			
			if($numsessions > 0){
				echo '<h2>Course Details</h2>';

				foreach($event_sessions as $event_session){	
					if($event_session->get('evs_session_number')){
						echo '<h3>Session ' . $event_session->get('evs_session_number') . ' -  ' . $event_session->get('evs_title'). '</h3>';
					}
					else{
						echo $event_session->get('evs_title');						
					}
					
					echo preg_replace('#<a.*?>(.*?)</a>#i', '\1', $event_session->get('evs_content'));	
					//echo ' <a href="/profile/event_sessions?evt_event_id='. $event->key.'">View videos and materials</a><br />';
				}
				$page->endtable();	
			}
		}
		else{
			$searches = array();
			$searches['event_id'] = $event->key;
			$searches['future'] = 'now()';
			$event_sessions = new MultiEventSessions($searches,
				array('time_then_session_number'=>'DESC')); 
			$event_sessions->load();	
			$numsessions = $event_sessions->count_all();
			
			if($numsessions > 0){
				echo '<h3>Upcoming Sessions</h3>';

				foreach($event_sessions as $event_session){			
					echo '<strong>' . $event_session->get_time_string($tz) . '</strong>  -  ' . $event_session->get('evs_title').'<br>';				
				}
				$page->endtable();	
			}

			$searches = array();
			$searches['event_id'] = $event->key;
			$searches['past'] = 'now()';
			$event_sessions = new MultiEventSessions($searches,
				array('time_then_session_number'=>'DESC'));
			$numsessions = $event_sessions->count_all();
			$event_sessions->load();	

			if($numsessions > 0){
				echo '<h3>Past Sessions</h3>';

				foreach($event_sessions as $event_session){			
					echo '<strong>' . $event_session->get_time_string($tz) . '</strong>  -  ' . $event_session->get('evs_title');
					echo ' <a href="/profile/event_sessions?evt_event_id='. $event->key.'">View videos and materials</a><br>';					
				}
				$page->endtable();	
			}			
		}
		?>

			</div>
		</div><!-- .entry-summary -->
	</div><!-- .archive-content -->

	
	<?php

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>

