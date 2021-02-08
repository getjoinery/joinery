<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/PublicPage.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/FormWriterPublic.php');
	require_once(LibraryFunctions::get_logic_file_path('event_sessions_course_logic.php'));
	


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
		?>	
		<div uk-grid>
		<div class="uk-width-2-3@m"><div style="padding: 20px">
					<?php
					if($video->key){
						echo '<div style="margin: 20px">'.$video->get_embed(784,441).'</div>';
					}
					else if($event->get('evt_picture_link')){
						echo '<div class="post-thumbnail">
					<img width="800" height="532" src="'.$event_session->get('evs_picture_link'). '" class="attachment-twentyseventeen-featured-image size-twentyseventeen-featured-image wp-post-image" alt="" /></div><!-- .post-thumbnail -->	';
					} 
					?>

		<div><?php echo $event_session->get('evs_content'); ?></div>
														
		<?php

			$session_files = $event_session->get_files();
			if($session_files){
				echo '<h3>Files and Homework</h3>';
				
				$rowcontent = '<ul>';
				foreach($session_files as $session_file){
					$rowcontent .= '<li><a href="'.$session_file->get_url().'">'.$session_file->get_name().'</a></li>';
				}
				$rowcontent .= '</ul>';
				echo $rowcontent;
			}
			
			$next_session = $session_number+1;
			//CHECK IF SESSION EXISTS
			$exists=0;
			foreach($event_sessions as $check_session){
				if($check_session->get('evs_session_number') == $next_session){
					$exists=1;
				}
			}
		
			if($exists){
				echo '<p style="float:right;"><a class="et_pb_button" href="/profile/event_sessions_course?event_id='.$event->key.'&session_number='. $next_session .'">Next Session</a></p>';
			}
		
		

			?>
					
		</div>
		</div>
		<div class="uk-width-1-3@m"><div style="padding: 20px">
		<?php
		//CHECK FOR SESSIONS
		
			$searches = array();
			$searches['event_id'] = $event->key;
			
			$event_sessions = new MultiEventSessions($searches,
				array('session_number'=>'ASC'));
			$event_sessions->load();	
			$numsessions = $event_sessions->count_all();
			
			if($numsessions > 0){
				echo '<h3>Sessions</h3>';

				foreach($event_sessions as $aevent_session){			
					echo 'Session ' . $aevent_session->get('evs_session_number') . ' - <a href="/profile/event_sessions_course?session_number='.$aevent_session->get('evs_session_number').'&event_id='. $event->key.'">'.$aevent_session->get('evs_title').'</a><br />';

				}
				$page->endtable();	

			}	
		?>
		</div>
		</div>
		</div>	
			<?php
	}

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'show_survey'=>TRUE));
?>
