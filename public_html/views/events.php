<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/PublicPage.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/FormWriterPublic.php');

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
	if($_REQUEST['past']){
		$searches['past'] = TRUE;
	}
	else{
		$searches['past'] = FALSE;
		$searches['status'] = 1;
		$swasdirection = 'DESC';
	}
	
	 

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
	'title' => 'Checkout'
	));
	echo PublicPage::BeginPage('Retreats and Events');
?>

		     <div style="clear:both;"><span style="float:right;"><a href="/events?past=1">Click here to see past events</a></span></div>
			  
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
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>

