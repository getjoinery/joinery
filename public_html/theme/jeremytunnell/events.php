<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once('includes/PublicPage.php');
	require_once('includes/FormWriterPublic.php');

	require_once($_SERVER['DOCUMENT_ROOT'].'/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/address_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/users_class.php');

	$session = SessionControl::get_instance();

	$numperpage = 30;
	$swaoffset = 0;
	$swasort = 'start_time';
	$swasdirection = 'ASC';
	$searchterm = LibraryFunctions::fetch_variable('searchterm', NULL, 0, '');
	$user_id = LibraryFunctions::fetch_variable('u', NULL, 0, '');
	
	$searches = array();
	$searches['deleted'] = FALSE;
	$searches['visibility'] = 1;
	$searches['past'] = FALSE;
	$searches['status'] = 1;
	
	 

	$events = new MultiEvent(
		$searches,
		array($swasort=>$swasdirection),
		$numperpage,
		$swaoffset,
		'AND');
	$events->load();	
	$numeventsrecords = $events->count_all();	

	$page = new PublicPage(TRUE);
	$page->public_header(array(
	'title' => 'Checkout',
	'profilenav' => TRUE,
	));
	echo PublicPage::BeginPage('Retreats and Events');
?>

		      <span style="float:right;"><a href="/past-events">Click here to see past events</a></span>
		<?php
	foreach ($events as $event){
		

		?>


		
					<h3 class="entry-title"><a style="text-decoration: none; color: #0a466b;" href="<?php echo $event->get_url(); ?>" rel="bookmark"><?php echo $event->get('evt_name'); ?></a></h3>	<!-- .entry-header -->
					<div class="archive-content">
						<?php
						if($picture_link = $event->get_picture_link('medium')){
							echo '<div >
						<img width="300" src="'.$picture_link. '" class="attachment-twentyseventeen-featured-image size-twentyseventeen-featured-image wp-post-image" alt="" /></div><!-- .post-thumbnail -->	';
						}
						?>		
		

						<div class="entry-summary">
							<div class="archive-info"> 
								<div class="subtitle">
									<div class="ledby">Led by 
									<?php echo $event->get_leader(); ?>					
									</div>
								</div>
								<div class="event-datetimes">
		<strong><?php echo $event->get('evt_location'); ?></strong><br />
		<?php 
		$time = NULL;

		if($event->get_event_start_time($event->get('evt_timezone'))){
			$time = $event->get_event_start_time($event->get('evt_timezone'));
		}
		if($event->get_event_end_time($event->get('evt_timezone'))){
			$time .= ' - ' . $event->get_event_end_time($event->get('evt_timezone'));
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
		
		?>
		<br />
		<div class="archive-excerpt"><?php echo $event->get('evt_short_description'); ?></div>
		</div>
	<!-- .event-datetimes -->
												<div class="readmore-button">
					<a class="et_pb_button"  href="<?php echo $event->get_url(); ?>">Read More</a>
							</div>
						</div>
					</div><!-- .entry-summary -->
				</div><!-- .archive-content -->
			<hr>
	
	<?php	
	}

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>

