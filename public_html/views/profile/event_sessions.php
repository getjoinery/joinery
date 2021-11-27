<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/PublicPage.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/FormWriterPublic.php');
	require_once(LibraryFunctions::get_logic_file_path('event_sessions_logic.php'));	


	if($error_message){
		PublicPage::OutputGenericPublicPage('Not Registered', 'Not Registered', $error_message);
		exit();
	}	

	$page = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Sessions', 
	);
	$page->public_header($hoptions,NULL);
	

	$time_string = $event->get_time_string();
	if($event->get('evt_timezone') != $session->get_timezone()){
		$time_string .= '('. $event->get_time_string($session->get_timezone()) . ')';
	}		
	$time_string .= '<br>';
	
	$options=array();
	$options['subtitle'] = $time_string .'<a href="/profile/profile">Back to my profile</a>';
	echo PublicPage::BeginPage($event->get('evt_name'), $options);	
	

	?>
	<div class="section padding-top-20">
		<div class="container">
			<div class="row col-spacing-50">
				<!-- Blog Posts -->
				<div class="col-12 col-lg-8"> 
				<?php
				

	

	
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




	if($next_session){
		if($next_session->get('evs_title')){
			$session_name = $next_session->get('evs_title');
		}
		else{
			$session_name = 'Session '.$next_session->get('evs_session_number');
		}
		
		if($event->get('evt_timezone') == $session->get_timezone()){
			$time_string = $next_session->get_time_string($event->get('evt_timezone'));				
		}
		else{
			$time_string = $next_session->get_time_string($event->get('evt_timezone')) . ' (Your time: ' . $next_session->get_time_string($session->get_timezone()). ')';
		}
		
		$calendar_text = '';
		$calendar_links = $next_session->get_add_to_calendar_links();
		if($calendar_links){
			$calendar_text .= 'Add to calendar: <a href="'.$calendar_links['google'].'">google</a> | ';
			$calendar_text .= '<a href="'.$calendar_links['yahoo'].'">yahoo</a> | ';
			$calendar_text .= '<a href="'.$calendar_links['outlook'].'">outlook</a> | ';
			$calendar_text .= '<a href="'.$calendar_links['ics'].'">ical</a> ';
		}
		?>
		<div class="padding-40 border-all border-radius hover-shadow">
			<h4 class="font-weight-normal margin-0">Next Session: <?php echo $session_name; ?></h4>
			<div class="margin-bottom-10 margin-lg-bottom-20 text-black-03">
				<p><i class="fas fa-map-marker-alt margin-right-10"></i><span><?php echo $time_string; ?></span></p>
				<p><i class="fas fa-map-marker-alt margin-right-10"></i><span><?php echo $calendar_text; ?></span></p>
			</div>
			<p><?php echo $next_session->get('evs_content'); ?></p>
			<!--<div class="text-right margin-top-10 margin-lg-top-20">
				<a class="button-text-1" href="#">Apply Now</a>
			</div>-->
		</div>
		<?php
	}

	if ($num_sessions){
		echo '<h2>Past Sessions</h2>';
	}
	else{
		echo '<h2>Past Sessions</h2><p>There are no sessions here.</p>';
	}

	foreach($event_sessions as $event_session){
		if($event_session->get('evs_vid_video_id')){
			$video = new Video($event_session->get('evs_vid_video_id'), TRUE);
		}
		else{
			$video = new Video(NULL);
		}	

		if($event_session->get('evs_title')){
			$session_name = $event_session->get('evs_title');
		}
		else{
			$session_name = 'Session '.$event_session->get('evs_session_number');
		}
		
		if($event->get('evt_timezone') == $session->get_timezone()){
			$time_string = $event_session->get_time_string($event->get('evt_timezone'));				
		}
		else{
			$time_string = $event_session->get_time_string($event->get('evt_timezone')) . ' (Your time: ' . $event_session->get_time_string($session->get_timezone()). ')';
		}			
		
		?>
		<div class="padding-40 border-all border-radius hover-shadow margin-bottom-20">
			<h4 class="font-weight-normal margin-0"><?php echo $session_name; ?></h4>
			<div class="margin-bottom-10 margin-lg-bottom-20 text-black-03">
				<p><i class="fas fa-map-marker-alt margin-right-10"></i><span><?php echo $time_string; ?></span></p>
			</div>
			<?php echo $video->get_embed(); ?>
			<p><?php echo $event_session->get('evs_content'); ?></p>
			<?php
			$session_files = $event_session->get_files();
			$num_session_files = 0;
			foreach($session_files as $session_file){
				$num_session_files++;
			}
			if($num_session_files){
			?>
			<div class="margin-top-20">
				<h6 class="font-family-tertiary font-small font-weight-medium uppercase">Materials:</h6>
				<ul class="list-dash">
					<?php
					foreach($session_files as $session_file){
						echo '<li><a href="'.$session_file->get_url().'">'.$session_file->get_name().'</a></li>';
					}		
					?>						
				</ul>
			</div>
			<?php
			}
			?>
			<!--<div class="text-right margin-top-10 margin-lg-top-20">
				<a class="button-text-1" href="#">Apply Now</a>
			</div>-->
		</div>
		<?php			
		
	}
	
	if($num_sessions > 5){
		echo '<p><a class="button button-lg button-dark" href="/profile/event_sessions?show_all=1&evt_event_id='.$event->key.'">See all '.$num_sessions.' sessions</a></p>';
	}



	?>
	</div>
	
	<?php
	if($event->get('evt_private_info')){
	?>
	<div class="col-12 col-lg-4 sidebar-wrapper">
		<!-- Sidebar box 1 - About me -->
		<div class="sidebar-box">
			<div class="text-center">
				<h6 class="font-small font-weight-normal uppercase">Live Info</h6>
				
				
				<!--<img class="img-circle-md margin-bottom-20" src="../assets/images/img-circle-medium.jpg" alt="">
				<p>Aenean massa. Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.</p>-->
			</div>
			<p><?php echo $event->get('evt_private_info'); ?></p>
		</div>
		<?php
	}
	?>
				</div>
				<!-- end Blog Sidebar -->
			</div><!-- end row -->
		</div><!-- end container -->
	</div>
	<?php
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'show_survey'=>TRUE));
?>
