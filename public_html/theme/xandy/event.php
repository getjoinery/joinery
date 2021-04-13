<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once (LibraryFunctions::get_logic_file_path('event_logic.php'));
	require_once(LibraryFunctions::get_theme_path().'/includes/PublicPage.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/FormWriterPublic.php');
	
	$page = new PublicPage(TRUE);
	$page->public_header(array(
		'title' => $event->get('evt_name')
	));
	
	
	if($time){
		$subtitle[] = $time;
	} 
	
	if($time_user){
		$subtitle[] = '( '.$time_user .')';
	}
				
	if($event->get('evt_location')){
		$subtitle[] = $event->get('evt_location');
	}	

	if($event->get('evt_usr_user_id_leader')){
		$leader = new User($event->get('evt_usr_user_id_leader'), TRUE);
		$subtitle[] = 'Led by '. $leader->display_name();
	}
	$pageoptions['subtitle'] = implode(' | ', $subtitle);
	echo PublicPage::BeginPage($event->get('evt_name'), $pageoptions);
		
		if($picture_link = $event->get_picture_link('medium')){
		?>
		<div class="section padding-top-20">
			<div class="container">
				<div class="row">
					<div class="col-12 text-center">
						<?php
							echo '<img src="'.$picture_link. '"  alt="" />';
						?>
					</div>
				</div><!-- end row -->
			</div><!-- end container -->
		</div>		
		<?php } ?>
		
		<div class="section">
			<div class="container">
				<div class="row">
					<div class="col-12">
						<h3>Description</h3>
						<?php echo $event->get('evt_description'); ?></p>					
					</div>
				</div><!-- end row -->
			</div><!-- end container -->
		</div>	
		
		<div class="margin-top-70 text-center">
			<?php
					
			if($view_course_link){
				echo '<a href="/profile/event_sessions_course?event_id='.$event->key.'"><div class="button-group filter-button-group filter-button-group2"><button class="gallery-btn">View Course</button></a>';		
			}
			
			if($registration_message){
				echo '<p>'.$registration_message.'</p>';
			}

			if($register_link){
				echo '<a href="'.$register_link.'"><div class="button-group filter-button-group filter-button-group2"><button class="gallery-btn">Register Now</button></div></a>';
			}
			
			if($waiting_list_link){
				echo '<ahref="'.$waiting_list_link.'"><div class="button-group filter-button-group filter-button-group2"><button class="gallery-btn">Get on the waiting list</button></div></a>';
			}
			
			if($if_registered_message){
				echo '<p>'.$if_registered_message.'</p>';
			}

			?>
			<!--<a class="button button-lg button-rounded button-reveal-right-dark" href="#"><span>Get In Touch</span><i class="ti-arrow-right"></i></a>-->
		</div>		
		
		
		<div class="section">
			<div class="container">
				<div class="row col-spacing-50">
					<div class="row">
						<ul class="accordion single-open">
							

<?php
	//CHECK FOR SESSIONS
	if($event->get('evt_session_display_type') == Event::DISPLAY_SEPARATE && $numsessions > 0){
		echo '<h2>Course Details</h2>';

		foreach($event_sessions as $event_session){	
			if($event_session->get('evs_session_number')){
				?>
							<li>
								<div class="accordion-title">
									<h6 class="font-small font-weight-normal uppercase"><?php echo 'Session ' . $event_session->get('evs_session_number') . ' -  ' . $event_session->get('evs_title'); ?></h6>
								</div>
								<div class="accordion-content">
									<p><?php echo preg_replace('#<a.*?>(.*?)</a>#i', '\1', $event_session->get('evs_content')); ?></p>
								</div>
							</li>
				<?php
			}
			else{
				?>
							<li>
								<div class="accordion-title">
									<h6 class="font-small font-weight-normal uppercase"><?php echo $event_session->get('evs_title'); ?></h6>
								</div>
								<div class="accordion-content">
									<p><?php echo preg_replace('#<a.*?>(.*?)</a>#i', '\1', $event_session->get('evs_content')); ?></p>
								</div>
							</li>
				<?php						
			}
			
			;	
		}
		$page->endtable();	
	}
	else{	
		if($future_numsessions > 0){
			echo '<h3>Upcoming Sessions</h3>';

			foreach($future_event_sessions as $event_session){	
				?>
							<li>
								<div class="accordion-title">
									<h6 class="font-small font-weight-normal uppercase"><?php echo $event_session->get('evs_title') . ' -  ' . $event_session->get_time_string($tz); ?></h6>
								</div>
								<div class="accordion-content">
									<p><?php echo preg_replace('#<a.*?>(.*?)</a>#i', '\1', $event_session->get('evs_content')); ?></p> 
								</div>
							</li>
				<?php							
			}	
		}


		if($past_numsessions > 0){
			echo '<h3>Past Sessions</h3>';

			foreach($past_event_sessions as $event_session){
				?>
							<li>
								<div class="accordion-title">
									<h6 class="font-small font-weight-normal uppercase"><?php echo $event_session->get('evs_title') . ' -  ' . $event_session->get_time_string($tz); ?></h6>
								</div>
								<div class="accordion-content">
									<p><?php echo preg_replace('#<a.*?>(.*?)</a>#i', '\1', $event_session->get('evs_content')); ?></p> 
									<?php
									echo '<a href="/profile/event_sessions?evt_event_id='. $event->key.'">View videos and materials</a>';
									?>
								</div>
							</li>
				<?php									
			}	
		}			
	}
	?>
						</ul>
					</div>
				</div><!-- end row -->
			</div><!-- end container -->
		</div>
		<?php


	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>

