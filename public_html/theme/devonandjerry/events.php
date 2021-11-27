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
	echo PublicPage::BeginPage('Courses');


	
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
			<?php



	foreach ($events as $event){
		$now = LibraryFunctions::get_current_time_obj('UTC');
		$event_time = LibraryFunctions::get_time_obj($event->get('evt_start_time'), 'UTC');
		?>
		<div class="row align-items-center col-spacing-50">
			<div class="col-12 col-md-6">
				<?php
				if($pic = $event->get_picture_link('small')){
					echo '<img src="'.$pic.'" alt="">';
				}
				?>
			</div>
			<div class="col-12 col-md-6">
				<h4 class=" font-weight-normal "><?php echo $event->get('evt_name'); ?></h4>
				<h6 class="font-family-tertiary font-small font-weight-normal uppercase">
				<?php
				if($event->get('evt_usr_user_id_leader')){
					$leader = new User($event->get('evt_usr_user_id_leader'), TRUE);
					echo '<p>By '. $leader->display_name().'</p>';
				}
				else{
					echo '<p>Various instructors</p>';
				}

				if($event->get('evt_start_time') && $event_time > $now){				
					echo $event->get_event_start_time($tz, 'M'). ' ' . $event->get_event_start_time($tz, 'd'); 				
				}
				else if($next_session = $event->get_next_session()){
					echo $next_session->get_start_time($tz, 'M'). ' ' . $next_session->get_start_time($tz, 'd'); 
				
				}						
				?>
				</h6>
				<p><?php echo $event->get('evt_short_description'); ?></p>
				<a class="button button-xs button-dark button-rounded margin-top-20 margin-bottom-30" href="<?php echo $event->get_url(); ?>">Read More</a>
				<br><br>
			</div>
		</div>
		<?php
	}	
	?>
	
			</div><!-- end container -->
		</div>
		<?php
  
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>

