<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once (LibraryFunctions::get_logic_file_path('events_logic.php'));
	require_once(LibraryFunctions::get_theme_path().'/includes/PublicPage.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/FormWriterPublic.php');

	$page = new PublicPage(TRUE);
	$page->public_header(array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Events'
	));
	echo PublicPage::BeginPage('Retreats and Events');

	?>
		<div class="section padding-top-20">
			<div class="container">
				<ul class="nav nav-tabs margin-bottom-20">
				<?php
				foreach($tab_menus as $id => $name){
					if($id == $_REQUEST['type']){
					  echo '<li class="nav-item">
						<a class="nav-link active" href="/events?type='.$id.'">'.$name.'</a>
					  </li>';					
					}
					else{
					  echo '<li class="nav-item">
						<a class="nav-link" href="/events?type='.$id.'">'.$name.'</a>
					  </li>';						
					}
				}
				?>
				</ul>



				<div class="">
					<!-- start -->
					<!--
					<div class="button-group filter-button-group filter-button-group2">
					  <button data-filter="*" class="active gallery-btn">all</button>
					  <button data-filter=".upcoming" class="gallery-btn">Upcoming Event</button>
					  <button data-filter=".recently" class="gallery-btn">Recently Event</button>
					  <button data-filter=".past" class="gallery-btn">Live Event</button>
					</div>
					<div class="vertical-space-40"></div>
					-->
					<div class="grid2">
						<?php
					foreach ($events as $event){
						$now = LibraryFunctions::get_current_time_obj('UTC');
						$event_time = LibraryFunctions::get_time_obj($event->get('evt_start_time'), 'UTC');
						?>
						<!-- Add Event -->
						<a href="<?php echo $event->get_url(); ?>">
						<div class="col-xs-12 col-sm-4 col-md-4 grid-item2 past">
							<div class="event-container">
								<div class="event-img-container">
									<div class="event-img-container-inner">
										<?php
										if($pic = $event->get_picture_link('small')){
											echo '<img class="border-radius box-shadow-with-hover" src="'.$pic.'" alt="">';
										}
										?>
										<div class="event-date-container">
											<?php
											if($event->get('evt_start_time') && $event_time > $now){				
												echo '<h3>'.$event->get_event_start_time($tz, 'M'). '</h3> <h4>' . $event->get_event_start_time($tz, 'd').'</h4>'; 				
											}
											else if($next_session = $event->get_next_session()){
												echo '<h3>'.$next_session->get_start_time($tz, 'M'). '</h3> <h4>' . $next_session->get_start_time($tz, 'd').'</h4>'; 
											
											}					
											?>
										</div>
									</div>
								</div>
								<div class="event-detail-container">
									<h2><?php echo $event->get('evt_name'); ?></h2>
									<p><?php echo $event->get('evt_short_description'); ?></p>
									<!--<h4>10:00 AM - 5:00 PM <span class="pull-right">Los Angles</span></h4>-->
								</div>
							</div>
							<div class="vertical-space-40"></div>
						</div>
						</a>
		<?php
	}	
	?>						
	
					</div>
					<!-- end -->
				</div>
		</div>
		<?php
  
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>

