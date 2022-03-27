<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/stripe-php/init.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/Pager.php');

	require_once($_SERVER['DOCUMENT_ROOT'].'/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/files_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/users_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'file_id', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '');

	$files = new MultiFile();
	$files->load();	
	
	foreach ($files as $file){
		$file->delete_resized('thumbnail');
		$file->resize('thumbnail');
		$file->resize('lthumbnail');
		echo $file->get('fil_name'). '<br>';
	}



	
	exit;

	
	$numperpage = 30;
	$filoffset = LibraryFunctions::fetch_variable('filoffset', 0, 0, '');
	$filsort = LibraryFunctions::fetch_variable('filsort', 'file_id', 0, '');
	$filsdirection = LibraryFunctions::fetch_variable('filsdirection', 'DESC', 0, '');
	$searchterm = LibraryFunctions::fetch_variable('filsearchterm', '', 0, '');

	$search_criteria = array();
	if($searchterm){
		$search_criteria['filename_like'] = $searchterm;
	}	
	$search_criteria['deleted'] = false;
	$files = new MultiFile(
		$search_criteria,
		array($filsort=>$filsdirection),
		$numperpage,
		$filoffset,
		'AND');
	$files->load();	
	$numrecords = $files->count_all();
	

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 9,
		'page_title' => 'Files',
		'readable_title' => 'Files',
		'breadcrumbs' => array(
			'Files'=>'', 
		),
		'session' => $session,
	)
	);
	

	$headers = array('Thumb','File', 'File Type', 'Uploaded', 'By');
	$altlinks = array('Upload file'=>'/admin/admin_file_upload');
	$title= 'Files';
	$table_options = array(
	'numrecords'=>$numrecords, 
	'numperpage'=> $numperpage, 
	'getvars'=>$_SERVER[REQUEST_URI],
	'prefix'=>'fil',
	//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
	'altlinks' => $altlinks,
	'title' => $title,
	'search_on' => TRUE
	);
	
	
	$pager = new Pager('fil', $table_options);
	print_r($pager->get_var('numperpage'));
	exit();
	
	for($x=1; $x<=4;$x++){
		echo '<a href="/test/test?'.$pager->get_url($x).'">'.$pager->get_url($x).'</a><br />';
	}
	
	echo '******************<br />';
	echo '<a href="/test/test?'.$pager->get_url('-5').'">'.$pager->get_url('-5').'</a><br />';
	
	
	$page->tableheader($headers, $table_options);
		


		
	$page->endtable($table_options);		
		
	$page->admin_footer();

	
	
	
	
	
	
	
	
	
	
	
	
	
	
	exit();
?>

		     <div style="clear:both;"><span style="float:right;"><a href="/past-events">Click here to see past events</a></span></div>
			  
	<div class="uk-section uk-margin-top">
  <div class="uk-container">		  
<div class="uk-child-width-1-2@s uk-child-width-1-3@m uk-grid-match uk-margin-medium-top" data-uk-grid>
	
			<?php
	foreach ($events as $event){
		

		?>
      <div>
        <div class="uk-card uk-card-small uk-card-default uk-card-hover uk-border-rounded-large uk-overflow-hidden">
          <div class="uk-card-media-top uk-inline uk-light">
            
			<?php
				if($event->get_picture_link('small')){
					echo '<img src="'.$event->get_picture_link('small'). '" alt="'.$event->get('evt_name').'" height="380">';
				}	
				else{
					echo '<img src="https://source.unsplash.com/gMsnXqILjp4/640x380" alt="Course Title">';
				}
			?>				
			
            <div class="uk-position-cover uk-overlay-xlight"></div>
            <div class="uk-position-small uk-position-top-left">
              <!--<span class="uk-label uk-text-bold uk-text-price">$27.00</span>-->
            </div>
			<!--
            <div class="uk-position-small uk-position-top-right">
              <a href="#" class="uk-icon-button uk-like uk-position-z-index uk-position-relative" data-uk-icon="heart"></a>
            </div>  
			-->			
          </div>
          <div class="uk-card-body">
            <div data-uk-grid>
              <div class="uk-width-auto uk-text-center">
                <span class="uk-display-block uk-text-small uk-text-bold uk-text-primary uk-text-uppercase">
				<?php 
				$now = LibraryFunctions::get_current_time_obj('UTC');
				$event_time = LibraryFunctions::get_time_obj($event->get('evt_start_time'), 'UTC');
				if($event->get('evt_start_time') && $event_time > $now){					
					echo $event->get_event_start_time($tz, 'M'); 
					echo '</span>
					<span class="uk-display-block uk-h4 uk-margin-xsmall-top" style="margin-top:10px;">';
					echo $event->get_event_start_time($tz, 'd'); 				
				}
				else if($next_session = $event->get_next_session()){
					echo $next_session->get_start_time($tz, 'M'); 
					echo '</span>
					<span class="uk-display-block uk-h4 uk-margin-xsmall-top" style="margin-top:10px;">';
					echo $next_session->get_start_time($tz, 'd'); 						
				}
				?>
				</span>
              </div>
              <div class="uk-width-expand">
                <h3 class="uk-card-title"><?php echo $event->get('evt_name'); ?></h3>
				<?php
				if($event->get('evt_usr_user_id_leader')){
					$leader = new User($event->get('evt_usr_user_id_leader'), TRUE);
					echo '<p class="uk-text-muted uk-text-small">By '. $leader->display_name().'</p>';
				}
				else{
					echo '<p class="uk-text-muted uk-text-small">Various instructors</p>';
				}
				?>
				
				<?php
				if($event->get('evt_short_description')){
					echo '<p class="uk-text-small">'. $event->get('evt_short_description').'</p>';
				}
				?>                
              </div>
            </div>
          </div>		  
          <!--
		  <div class="uk-card-body">
            <h3 class="uk-card-title uk-margin-small-bottom"><?php echo $event->get('evt_name'); ?></h3>
            <div class="uk-text-muted uk-text-small">Some Person</div>
            <div class="uk-text-muted uk-text-xxsmall uk-rating uk-margin-small-top">
              <span class="uk-rating-filled" data-uk-icon="icon: star; ratio: 0.75"></span>
              <span class="uk-rating-filled" data-uk-icon="icon: star; ratio: 0.75"></span>
              <span class="uk-rating-filled" data-uk-icon="icon: star; ratio: 0.75"></span>
              <span class="uk-rating-filled" data-uk-icon="icon: star; ratio: 0.75"></span>
              <span class="uk-rating-filled" data-uk-icon="icon: star; ratio: 0.75"></span>
              <span class="uk-margin-small-left uk-text-bold">5.0</span>
              <span>(324)</span>
            </div>
          </div>
		  -->
          <a href="<?php echo $event->get_url(); ?>" class="uk-position-cover"></a>
        </div>
      </div>		
	<?php } ?>			  
	</div>	  
			  
			  
</div>
</div>
			  
			  
			  
			  
			  
			  
			  
		<?php
		/*
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
	*/

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>

