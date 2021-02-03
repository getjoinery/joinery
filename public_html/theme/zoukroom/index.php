<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/Pager.php');
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
	$searches['public'] = TRUE;
	$searches['past'] = FALSE;
	
	

	$events = new MultiEvent(
		$searches,
		array($swasort=>$swasdirection),
		$numperpage,
		$swaoffset);
	$events->load();	
	$numeventsrecords = $events->count_all();	
	


	$page = new PublicPage();
	$hoptions = array(
		'title' => '',
		'description' => '',
		'banner' => '',
		'submenu' => '',
	);
	$page->public_header($hoptions); 
	
			
	?>
	<div class="uk-container uk-container-large">
		<div class="uk-position-relative uk-visible-toggle uk-light uk-box-shadow-small uk-overflow-hidden uk-border-rounded"
		 tabindex="-1" data-uk-slider="autoplay: true">
			<ul class="uk-slider-items uk-child-width-1-1">
				<li>
					<img src="/theme/zoukroom/img/DSC07869-5800x3301.jpg" alt="Slide">
					<div class="uk-position-cover uk-overlay-light uk-flex uk-flex-middle uk-padding-slide">
						<div class="uk-width-3-4@m">
							<h2 class="uk-heading-medium uk-letter-spacing-medium">Online Zouk Lessons</h2>
							<p>
								<a class="hvr-forward" href="/events" data-uk-icon="icon: arrow-right">See what's available</a>
							</p>
						</div>
					</div>
				</li>
				<li>
					<img src="/theme/zoukroom/img/DSC04428-5800x3300.jpg" alt="Slide">
					<div class="uk-position-cover uk-overlay-xlight uk-flex uk-flex-middle uk-padding-slide">
						<div class="uk-width-1-2@m">
							<h1 class="uk-heading-medium uk-letter-spacing-medium">From Your Living Room</h1>
							<p>
								<a class="hvr-forward" href="/events" data-uk-icon="icon: arrow-right">See what's available</a>
							</p>
						</div>
					</div>
				</li>
				<li>
					<img src="/theme/zoukroom/img/DSC08382-5800x3300.jpg" alt="Slide">
					<div class="uk-position-cover uk-overlay-xlight uk-flex uk-flex-middle uk-padding-slide">
						<div class="uk-width-1-2@m">
							<h1 class="uk-heading-medium uk-letter-spacing-medium">Support Our Artists</h1>
							<p>
								<a class="hvr-forward" href="/events" data-uk-icon="icon: arrow-right">See what's available</a>
							</p>
						</div>
					</div>
				</li>				
				
			</ul>
			<a class="uk-position-center-left uk-position-small uk-hidden-hover" href="#" data-uk-slidenav-previous
				data-uk-slider-item="previous"></a>
			<a class="uk-position-center-right uk-position-small uk-hidden-hover" href="#" data-uk-slidenav-next
				data-uk-slider-item="next"></a>
		</div>
	</div>
	<div class="uk-container">
		<div class="uk-background-primary-dark uk-light uk-border-rounded-large uk-position-relative uk-position-z-index uk-header-banner uk-header-banner-courses uk-box-shadow-small">
			<div class="uk-child-width-1-3@m uk-grid-small" data-uk-grid>
				<div class="uk-flex uk-flex-middle">
					<div class="uk-grid-small" data-uk-grid>
						<div class="uk-width-auto uk-flex uk-flex-middle">
							<span data-uk-icon="icon: album; ratio: 2"></span>
						</div>
						<div class="uk-width-expand">
							<h4 class="uk-margin-remove">All styles</h4>
							<p class="uk-margin-remove uk-text-muted uk-text-small">Lamba, Neo, Flow, and others</p>
						</div>
					</div>
				</div>
				<div class="uk-flex uk-flex-middle">
					<div class="uk-grid-small" data-uk-grid>
						<div class="uk-width-auto uk-flex uk-flex-middle">
							<span data-uk-icon="icon: users; ratio: 2"></span>
						</div>
						<div class="uk-width-expand">
							<h4 class="uk-margin-remove">Expert instructors</h4>
							<p class="uk-margin-remove uk-text-muted uk-text-small">Find the right instructor for you</p>
						</div>
					</div>
				</div>
				<div class="uk-flex uk-flex-middle">
					<div class="uk-grid-small" data-uk-grid>
						<div class="uk-width-auto uk-flex uk-flex-middle">
							<span data-uk-icon="icon: future; ratio: 2"></span>
						</div>
						<div class="uk-width-expand">
							<h4 class="uk-margin-remove">Live and self-paced</h4>
							<p class="uk-margin-remove uk-text-muted uk-text-small">Flexible online learning schedule</p>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</header>	
	
<div class="uk-section uk-margin-top">
  <div class="uk-container">
    <div class="uk-grid-small uk-flex uk-flex-middle" data-uk-grid>
      <div class="uk-width-expand@m">
        <h2>Popular Courses</h2>
      </div>
	  <!--
      <div class="uk-width-auto@m">
        <select class="uk-select uk-select-light">
          <option>Any Type</option>
          <option>Online</option>
          <option>School</option>
        </select>
      </div>
      <div class="uk-width-auto@m">
        <select class="uk-select uk-select-light">
          <option>Any Category</option>
          <option>Web Design</option>
          <option>Marketing</option>
          <option>Accounting</option>
          <option>Business</option>
          <option>Design</option>
        </select>
      </div>
	  -->
    </div>
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
                <span class="uk-display-block uk-text-small uk-text-bold uk-text-primary uk-text-uppercase"><?php echo $event->get_event_start_time($tz, 'M'); ?></span>
                <span class="uk-display-block uk-h4 uk-margin-xsmall-top"><?php echo $event->get_event_start_time($tz, 'd'); ?></span>
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
	
		<!--
      <div>
        <div class="uk-card uk-card-small uk-card-default uk-card-hover uk-border-rounded-large uk-overflow-hidden">
          <div class="uk-card-media-top uk-inline uk-light">
            <img src="https://source.unsplash.com/gMsnXqILjp4/640x380" alt="Course Title">
            <div class="uk-position-cover uk-overlay-xlight"></div>
            <div class="uk-position-small uk-position-top-left">
              <span class="uk-label uk-text-bold uk-text-price">$27.00</span>
            </div>
            <div class="uk-position-small uk-position-top-right">
              <a href="#" class="uk-icon-button uk-like uk-position-z-index uk-position-relative" data-uk-icon="heart"></a>
            </div>            
          </div>
          <div class="uk-card-body">
            <h3 class="uk-card-title uk-margin-small-bottom">Business Presentation Course</h3>
            <div class="uk-text-muted uk-text-small">Thomas Haller</div>
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
          <a href="course.html" class="uk-position-cover"></a>
        </div>
      </div>
      -->
    </div>
	<!--
    <div class="uk-text-center uk-margin-large-top">
      <div class="uk-text-primary"><span class="uk-margin-small-right"><img src="img/loader.svg" alt="Loader" width="22" height="22" data-uk-svg></span> Loading More</div>
    </div>   
-->	
  </div>
</div>
	
	

<?php /*
									<p class="date col-lg-12 col-md-12 col-6"><a href="#"><?php echo LibraryFunctions::convert_time($post->get('pst_published_time'), 'UTC', 'America/New_York', '%b %e, %i:%M %p'); ?></a> <span class="lnr lnr-calendar-full"></span></p>
										
							
							<?php
							if($numrecords > $numperpage){
								$total_pages = $numrecords / $numperpage;
								$current_page = $page_offset / $numperpage;
								if($currentpage > 1){
									$prevpage = $currentpage - 1;
								}
								else{
									$prevpage = NULL;
								}
								
								if($currentpage < $total_pages){
									$nextpage = $currentpage + 1;
								}
								else{
									$nextpage = NULL;
								}
							}
							?>
																					
		                    <nav class="blog-pagination justify-content-center d-flex">
		                        <ul class="pagination">
									<?php if($prevpage){ ?>
		                            <li class="page-item">
		                                <a href="/blog?offset=<?php echo $prevpage * $numperpage; ?>" class="page-link" aria-label="Previous">
		                                    <span aria-hidden="true">
		                                        <span class="lnr lnr-chevron-left"></span>
		                                    </span>
		                                </a>
		                            </li>
									<?php } ?>
									
									<?php
									for($x=0; $x<$total_pages; $x++){
										if($x == $current_page){
											echo '<li class="page-item active"><a href="/blog?offset=<?php echo $x * $numperpage; ?>" class="page-link">0'.$x.'</a></li>';
										}
										else{
											echo '<li class="page-item"><a href="/blog?offset=<?php echo $x * $numperpage; ?>" class="page-link">0'.$x.'</a></li>';
										}
										
									}
									?>
									
									<?php if($nextpage){ ?>
		                            <li class="page-item">
		                                <a href="/blog?offset=<?php echo $nextpage * $numperpage; ?>" class="page-link" aria-label="Next">
		                                    <span aria-hidden="true">
		                                        <span class="lnr lnr-chevron-right"></span>
		                                    </span>
		                                </a>
		                            </li>
									<?php } ?>
		                        </ul>
		                    </nav>
							
							
						</div>
						
	
*/








	$page->public_footer(array('track'=>TRUE));
?>