<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/PublicPage.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/FormWriterPublic.php');
	require_once(LibraryFunctions::get_logic_file_path('event_sessions_course_logic.php'));
	
	if($error_message){
		PublicPage::OutputGenericPublicPage('Not Registered', 'Not Registered', $error_message);
		exit();
	}	

	$page = new PublicPage();
	$hoptions = array(
		'title' => 'Sessions', 
	);
	$page->public_header($hoptions,NULL);
	$options['subtitle'] = '<a href="/profile/profile">Back to my profile</a>';
	echo PublicPage::BeginPage($event->get('evt_name'), $options);
	

	$session_name = 'Session ' . $event_session->get('evs_session_number') . ' - '.$event_session->get('evs_title');

	?>
	<div class="section padding-top-20">
		<div class="container">
			<div class="row col-spacing-50">
				<!-- Blog Posts -->
				<div class="col-12 col-lg-8"> 

	
					<div class="padding-40 border-all border-radius hover-shadow margin-bottom-20">
						<h4 class="font-weight-normal margin-0"><?php echo $session_name; ?></h4>
						<!--<div class="margin-bottom-10 margin-lg-bottom-20 text-black-03">
							<p><i class="fas fa-map-marker-alt margin-right-10"></i><span><?php echo $time_string; ?></span></p>
						</div>-->
						<?php 
						if($video->key && !$video->get('vid_delete_time')){
							echo $video->get_embed(784,441);
						}
						else if($event->get('evt_picture_link')){
							echo '<img width="800" height="532" src="'.$event_session->get('evs_picture_link'). '"  alt="" />';
						}
						?>
						<p><?php echo $event_session->get('evs_content'); ?></p>
						<?php
						$session_files = $event_session->get_files();
						$num_session_files = 0;
						foreach($session_files as $session_file){
							$num_session_files++;
						}
				
						$session_files = $event_session->get_files();
						if($session_files){
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

	
				$next_session = $session_number+1;
				//CHECK IF NEXT SESSION EXISTS
				$exists=0;
				foreach($event_sessions as $check_session){
					if($check_session->get('evs_session_number') == $next_session){
						$exists=1;
					}
				}
			
				if($exists){
					echo '<p style="float:right;"><a class="button button-lg button-dark" href="/profile/event_sessions_course?event_id='.$event->key.'&session_number='. $next_session .'">Next Session</a></p>';
				}
		
			?>
			</div>
			<?php
			
			if($numsessions > 0){
			?>
			<div class="col-12 col-lg-4 sidebar-wrapper">
				<!-- Sidebar box 1 - About me -->
				<div class="sidebar-box">
					<div class="text-center">
						<h6 class="font-small font-weight-normal uppercase">Sessions</h6>
						</div>
						<?php			
						foreach($event_sessions as $aevent_session){			
							echo '<a href="/profile/event_sessions_course?session_number='.$aevent_session->get('evs_session_number').'&event_id='. $event->key.'">Session ' . $aevent_session->get('evs_session_number') . ' - '.$aevent_session->get('evs_title').'</a><br />';

						}
						$page->endtable();	
						?>			
						<!--<img class="img-circle-md margin-bottom-20" src="../assets/images/img-circle-medium.jpg" alt="">
						<p>Aenean massa. Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.</p>-->
					
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
