<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	
	
	require_once(LibraryFunctions::get_theme_path().'/includes/PublicPage.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/FormWriterPublic.php');

	require_once($_SERVER['DOCUMENT_ROOT'].'/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/event_sessions_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/event_registrants_class.php');

	require_once (LibraryFunctions::get_logic_file_path('event_logic.php'));


	$page_vars = event_logic($_GET, $_POST, $event, NULL);
	$event = $page_vars['event'];
	
	$session = SessionControl::get_instance();


	
	$page = new PublicPage(TRUE);
	$page->public_header(array(
	'title' => 'Checkout',
	'profilenav' => TRUE,
	));
	//echo PublicPage::BeginPage($event->get('evt_name'));
?>

<div class="uk-container uk-container-large">
		<?php
			if($event->get_picture_link('large')){
				echo '		<div class="uk-box-shadow-small uk-overflow-hidden uk-border-rounded uk-inline uk-background-center-center 
		uk-background-cover uk-background-norepeat uk-background-blend-overlay uk-overlay-blend" 
		style="background-image: url('.$event->get_picture_link('large'). ');">';
			}	
			else{
				echo '<div class="uk-box-shadow-small uk-overflow-hidden uk-border-rounded uk-inline uk-background-center-center 
		uk-background-cover uk-background-norepeat uk-background-blend-overlay uk-overlay-blend">';
			}
		?>

			<div class="uk-padding-large">
				<div data-uk-grid>
					<div class="uk-width-expand@m uk-flex uk-flex-middle uk-light">
						<div>
						
							<a class="uk-text-demi-bold hvr-back" href="/events"><span class="uk-margin-small-right" 
								data-uk-icon="icon: arrow-left; ratio: 1.4"></span>Back</a>
							<h1 class="uk-heading-small uk-letter-spacing-medium"><?php echo $event->get('evt_name'); ?></h1>
							<p class="uk-margin-small-bottom">				
							<?php
							if($event->get('evt_short_description')){
								echo '<p class="uk-text-small">'. $event->get('evt_short_description').'</p>';
							}
							?>  
							</p>
							<!--
							<div class="uk-rating">
								<span class="uk-rating-filled" data-uk-icon="icon: star"></span>
								<span class="uk-rating-filled" data-uk-icon="icon: star"></span>
								<span class="uk-rating-filled" data-uk-icon="icon: star"></span>
								<span class="uk-rating-filled" data-uk-icon="icon: star"></span>
								<span data-uk-icon="icon: star"></span>
								<span class="uk-margin-small-left">4.0</span>
								<span>(6,650)</span>
								<span class="uk-margin-left">65,306 students enrolled</span>
							</div>
							-->
							<p class="uk-margin-xsmall-top">
									<?php
									if($event->get('evt_usr_user_id_leader')){
										$leader = new User($event->get('evt_usr_user_id_leader'), TRUE);
										echo '<p class="uk-text-muted uk-text-small">By '. $leader->display_name().'</p>';
									}
									else{
										echo '<p class="uk-text-muted uk-text-small">Various instructors</p>';
									}
									?>
									<span class="uk-margin-left">Last updated</span>
								<time datetime="2020-07-07">July 7 2019</time></p>
						</div>
					</div>
					<div class="uk-width-1-5@m"></div>
					<div class="uk-width-1-3@m uk-flex uk-flex-middle">
						
						<div class="uk-inline uk-light uk-border-rounded-large uk-box-shadow-small uk-overflow-hidden">
							<img src="/img/transparent-640x400.png">
							<!--
							<div class="uk-position-center">
								<a href="#course-video" class="uk-icon-link" data-uk-icon="icon: play-circle; ratio: 3" data-uk-toggle></a>
							</div>
							-->
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</header>
<!--
<div id="course-video" class="uk-flex-top" data-uk-modal>
	<div class="uk-modal-dialog uk-width-auto uk-margin-auto-vertical">
		<button class="uk-modal-close-outside" type="button" data-uk-close></button>
		<iframe src="https://player.vimeo.com/video/126241629" width="1000" height="562" data-uk-video></iframe>
	</div>
</div>
-->

<div class="uk-section">
  <div class="uk-container">
    <div class="uk-grid-large" data-uk-grid>
      <div class="uk-width-expand@m">
        <div class="uk-article">
          <h3>Description</h3>
          <?php echo $event->get('evt_description'); ?>

		<?php
			$searches = array();
			$searches['event_id'] = $event->key;
			$event_sessions = new MultiEventSessions($searches,
				array('evs_start_time'=>'ASC', 'evs_session_number'=>'ASC')); 
			$event_sessions->load();	
			$numsessions = $event_sessions->count_all();
			
			if($numsessions > 0){
				echo '<h3>Course content</h3>';
				echo '<ul class="uk-margin-top" data-uk-accordion="multiple: true">';
				foreach($event_sessions as $event_session){	
					if($event_session->get('evs_session_number')){
						$session_number_text = $event_session->get('evs_session_number') . ' - ';
					}	
					echo'<li>
						  <a class="uk-accordion-title" href="#">'. $session_number_text . $event_session->get('evs_title'). '<!--<span class="uk-align-right uk-margin-remove-bottom">28:56</span>--></a>
						';
					if($event_session->get('evs_content')){
						?>
					  <div class="uk-accordion-content">
						<table class="uk-table uk-table-justify uk-table-middle uk-table-divider">
						  <tbody>
							<tr class="uk-text-muted">
							  <td class="uk-table-expand"><span class="uk-margin-small-right" data-uk-icon="info"></span><?php echo preg_replace('#<a.*?>(.*?)</a>#i', '\1', $event_session->get('evs_content')); ?></td>
							  <!--<td><span data-uk-icon="lock"></span></td>
							  <td class="uk-table-shrink">04:24</td>-->
							</tr>                   
						  </tbody>
						</table>
					  </div>
					  <?php
					}

					echo '</li>';
					
				}
				echo '</ul>';
			}
			?>
          	<!--
          <ul class="uk-margin-top" data-uk-accordion="multiple: true">
            <li>
              <a class="uk-accordion-title" href="#">What is User Experience Design?<span class="uk-align-right uk-margin-remove-bottom">28:56</span></a>
            </li>

            <li>
              <a class="uk-accordion-title" href="#">Understanding the Elements of User Experience<span class="uk-align-right uk-margin-remove-bottom">19:26</span></a>
              <div class="uk-accordion-content">
                <table class="uk-table uk-table-justify uk-table-middle uk-table-divider">
                  <tbody>
                    <tr class="uk-text-muted">
                      <td class="uk-table-expand"><span class="uk-margin-small-right" data-uk-icon="play-circle"></span>The Elements of User Experience</td>
                      <td><span data-uk-icon="lock"></span></td>
                      <td class="uk-table-shrink">04:24</td>
                    </tr>
                    <tr class="uk-text-muted">
                      <td><span class="uk-margin-small-right" data-uk-icon="play-circle"></span>Exploring the Elements of User Experience</td>
                      <td><span data-uk-icon="lock"></span></td>
                      <td>04:24</td>
                    </tr>
                    <tr class="uk-text-muted">
                      <td><span class="uk-margin-small-right" data-uk-icon="play-circle"></span>How the Elements Work Together</td>
                      <td><span data-uk-icon="lock"></span></td>
                      <td>04:24</td>
                    </tr>
                    <tr class="uk-text-muted">
                      <td><span class="uk-margin-small-right" data-uk-icon="play-circle"></span>Identifying Business Goals</td>
                      <td><span data-uk-icon="lock"></span></td>
                      <td>04:24</td>
                    </tr>                    
                  </tbody>
                </table>
              </div>
            </li>
            <li>
              <a class="uk-accordion-title" href="#">Using the Design Elements Structure<span class="uk-align-right uk-margin-remove-bottom">35:30</span></a>
              <div class="uk-accordion-content">
                <table class="uk-table uk-table-justify uk-table-middle uk-table-divider">
                  <tbody>
                    <tr class="uk-text-muted">
                      <td class="uk-table-expand"><span class="uk-margin-small-right" data-uk-icon="play-circle"></span>Generating Effective Requirements</td>
                      <td><span data-uk-icon="lock"></span></td>
                      <td class="uk-table-shrink">04:24</td>
                    </tr>
                    <tr class="uk-text-muted">
                      <td><span class="uk-margin-small-right" data-uk-icon="play-circle"></span>Exploring Information Architecture</td>
                      <td><span data-uk-icon="lock"></span></td>
                      <td>04:24</td>
                    </tr>
                    <tr class="uk-text-muted">
                      <td><span class="uk-margin-small-right" data-uk-icon="play-circle"></span>Convention and Metaphor</td>
                      <td><span data-uk-icon="lock"></span></td>
                      <td>04:24</td>
                    </tr>
                    <tr class="uk-text-muted">
                      <td><span class="uk-margin-small-right" data-uk-icon="play-circle"></span>Contrast and Uniformity</td>
                      <td><span data-uk-icon="lock"></span></td>
                      <td>04:24</td>
                    </tr>                    
                  </tbody>
                </table>
              </div>
            </li>

          </ul>
			-->
		<!--
          <h3>Requirements</h3>
          <p>Entered have break himself cheek, and with activity that, for bit of text, never just 
            as our have they of begin to cannot in of ran middle at behind seal that their accustomed.</p>

          <div class="uk-margin-large-top">
            <h3>Tom Solender</h3>
            <div data-uk-grid>
              <div class="uk-width-auto uk-flex uk-flex-middle">
                <img class="uk-border-circle" src="https://source.unsplash.com/CZ9AjMGKIFI/80x80" alt="Tom Solender">
              </div>
              <div class="uk-width-expand">
                <div data-uk-grid data-uk-margin>
                  <div class="uk-width-1-1">
                    <p>29-year UX + Design Veteran; Consultant, Author & Speaker</p>
                  </div>
                  <div class="uk-width-1-2">
                    <span class="uk-margin-small-right" data-uk-icon="star"></span>4.4 Instructor Rating
                  </div>
                  <div class="uk-width-1-2">
                    <span class="uk-margin-small-right" data-uk-icon="comments"></span>21,201 Reviews
                  </div>
                  <div class="uk-width-1-2">
                    <span class="uk-margin-small-right" data-uk-icon="users"></span>130,451 Students
                  </div>
                  <div class="uk-width-1-2">
                    <span class="uk-margin-small-right" data-uk-icon="file-text"></span>5 Courses
                  </div>
                </div>
              </div>              
            </div>
            <p>Activity that and the scarfs, for bit of text, never just 
              as our have they of begin to cannot in of ran middle at behind seal that their accustomed. For 
              devotion their to though one rationally small sight. In so her has I solider, touched 
              the we the past, time, he posterity.</p>          
          </div>  
		  -->
		
			<!--
          <h3>Student feedback</h3>
          <ul class="uk-comment-list">
            <li>
              <article class="uk-comment uk-visible-toggle" tabindex="-1">
                <header class="uk-comment-header uk-position-relative">
                  <div class="uk-grid-medium uk-flex-middle" data-uk-grid>
                    <div class="uk-width-auto">
                      <img class="uk-comment-avatar uk-border-circle" src="https://source.unsplash.com/CZ9AjMGKIFI/100x100" width="80" height="80" alt="Alice Thomson">
                    </div>
                    <div class="uk-width-expand">
                      <h4 class="uk-comment-title uk-margin-remove"><a class="uk-link-reset" href="#">Alice Thomson</a></h4>
                      <p class="uk-comment-meta uk-margin-remove"><a class="uk-link-reset" href="#">12 days ago</a></p>
                      <div class="uk-rating">
                        <span class="uk-rating-filled" data-uk-icon="icon: star; ratio: 0.8"></span>
                        <span class="uk-rating-filled" data-uk-icon="icon: star; ratio: 0.8"></span>
                        <span class="uk-rating-filled" data-uk-icon="icon: star; ratio: 0.8"></span>
                        <span class="uk-rating-filled" data-uk-icon="icon: star; ratio: 0.8"></span>
                        <span data-uk-icon="icon: star; ratio: 0.8"></span>
                      </div>
                    </div>
                  </div>
                  <div class="uk-position-top-right uk-position-small uk-hidden-hover"><a class="uk-link-muted" href="#">Reply</a>
                  </div>
                </header>
                <div class="uk-comment-body">
                  <p>Lorem ipsum dolor sit amet, consetetur sadipscing elitr, sed diam nonumy eirmod tempor invidunt ut labore et
                    dolore magna aliquyam erat, sed diam voluptua. At vero eos et accusam et justo duo dolores et ea rebum. Stet
                    clita kasd gubergren, no sea takimata sanctus est Lorem ipsum dolor sit amet.</p>
                </div>
              </article>
              <ul>
                <li>
                  <article class="uk-comment uk-comment-primary uk-visible-toggle" tabindex="-1">
                    <header class="uk-comment-header uk-position-relative">
                      <div class="uk-grid-medium uk-flex-middle" data-uk-grid>
                        <div class="uk-width-auto">
                          <img class="uk-comment-avatar uk-border-circle" src="https://source.unsplash.com/CZ9AjMGKIFI/100x100" width="80" height="80" alt="Tom Solender">
                        </div>
                        <div class="uk-width-expand">
                          <h4 class="uk-comment-title uk-margin-remove"><a class="uk-link-reset" href="#">Tom Solender</a></h4>
                          <p class="uk-comment-meta uk-margin-remove-top"><a class="uk-link-reset" href="#">12 days ago</a></p>
                        </div>
                      </div>
                      <div class="uk-position-top-right uk-position-small uk-hidden-hover"><a class="uk-link-muted"
                          href="#">Reply</a></div>
                    </header>
                    <div class="uk-comment-body">
                      <p>Lorem ipsum dolor sit amet, consetetur sadipscing elitr, sed diam nonumy eirmod tempor invidunt ut labore
                        et dolore magna aliquyam erat, sed diam voluptua. At vero eos et accusam et justo duo dolores et ea rebum.
                        Stet clita kasd gubergren, no sea takimata sanctus est Lorem ipsum dolor sit amet.</p>
                    </div>
                  </article>
                </li>
                <li>
                  <article class="uk-comment uk-visible-toggle" tabindex="-1">
                    <header class="uk-comment-header uk-position-relative">
                      <div class="uk-grid-medium uk-flex-middle" data-uk-grid>
                        <div class="uk-width-auto">
                          <img class="uk-comment-avatar uk-border-circle" src="https://source.unsplash.com/CZ9AjMGKIFI/100x100" width="80" height="80" alt="Alice Thomson">
                        </div>
                        <div class="uk-width-expand">
                          <h4 class="uk-comment-title uk-margin-remove"><a class="uk-link-reset" href="#">Alice Thomson</a></h4>
                          <p class="uk-comment-meta uk-margin-remove"><a class="uk-link-reset" href="#">12 days ago</a></p>
                          <div class="uk-rating">
                            <span class="uk-rating-filled" data-uk-icon="icon: star; ratio: 0.8"></span>
                            <span class="uk-rating-filled" data-uk-icon="icon: star; ratio: 0.8"></span>
                            <span class="uk-rating-filled" data-uk-icon="icon: star; ratio: 0.8"></span>
                            <span class="uk-rating-filled" data-uk-icon="icon: star; ratio: 0.8"></span>
                            <span data-uk-icon="icon: star; ratio: 0.8"></span>
                          </div>
                        </div>
                      </div>
                      <div class="uk-position-top-right uk-position-small uk-hidden-hover"><a class="uk-link-muted"
                          href="#">Reply</a></div>
                    </header>
                    <div class="uk-comment-body">
                      <p>Lorem ipsum dolor sit amet, consetetur sadipscing elitr, sed diam nonumy eirmod tempor invidunt ut labore
                        et dolore magna aliquyam erat, sed diam voluptua. At vero eos et accusam et justo duo dolores et ea rebum.
                        Stet clita kasd gubergren, no sea takimata sanctus est Lorem ipsum dolor sit amet.</p>
                    </div>
                  </article>
                </li>
              </ul>
            </li>
          </ul>
		  -->

			<!--
          <h3 id="contact">How do I contact you with my questions?</h3>
          <p>Activity that and the scarfs, for bit of text, never just 
            as our have they of begin to cannot in of ran middle at behind seal that their accustomed. For 
            devotion their to though one small sight escape, little. In so her has I solider, touched 
            the we the past, time, he posterity.</p>          
			-->
        </div>
      </div>
      <div class="uk-width-1-3@m">
        <div data-uk-sticky="offset: 100; bottom: true; media: @m">
          <div class="uk-card uk-card-default uk-card-body uk-width-1-1 uk-border-rounded-large">
            <!--<h3>$19.99<del class="uk-text-muted uk-margin-left uk-text-small">$99.99</del></h3>-->
            <a href="<?php echo $event->get_register_url(); ?>" class="uk-button uk-button-primary-preserve uk-button-large uk-width-1-1">Go to Course</a>
            <p class="uk-text-center uk-text-muted uk-text-small">&nbsp;</p>
			
			<p class="uk-margin-remove">Course dates and times:</p>
            <ul class="uk-list uk-margin-small-top">
				<?php 

				$time = NULL;
				
				if($session->get_timezone()){
					$tz = $session->get_timezone();
				}
				else{
					$tz = $event->get('evt_timezone');
				}
				
				if($event->get_event_start_time($tz)){
					echo '<li><span class="uk-margin-small-right" data-uk-icon="clock"></span>Starts: '.$event->get_event_start_time($tz).'</li>';
				}
				
				if($event->get_event_end_time($tz)){
					echo '<li><span class="uk-margin-small-right" data-uk-icon="clock"></span>Ends: '.$event->get_event_end_time($tz).'</li>';
				}
				?>
            </ul>			
			<!--
            <p class="uk-margin-remove">This course includes:</p>
            <ul class="uk-list uk-margin-small-top">
              <li><span class="uk-margin-small-right" data-uk-icon="clock"></span>10 hours on-demand video</li>
              <li><span class="uk-margin-small-right" data-uk-icon="unlock"></span>Full lifetime access</li>
              <li><span class="uk-margin-small-right" data-uk-icon="tablet"></span>Access on mobile</li>
              <li><span class="uk-margin-small-right" data-uk-icon="file-text"></span>Certificate of completion</li>
            </ul>
            <p class="uk-text-center"><a href="#contact" class="uk-link-muted" data-uk-scroll="offset: 100">Have a question, contact us</a></p>
			-->
          </div>	
<!--		  
          <h3>Tags</h3>
          <div data-uk-margin>
            <a class="uk-display-inline-block" href="#"><span class="uk-label uk-label-light">UX</span></a>
            <a class="uk-display-inline-block" href="#"><span class="uk-label uk-label-light">Design</span></a>
            <a class="uk-display-inline-block" href="#"><span class="uk-label uk-label-light">UI</span></a>
            <a class="uk-display-inline-block" href="#"><span class="uk-label uk-label-light">Experience</span></a>          
          </div>
		  -->
		  <!--
          <h3>Share Course</h3>
          <div class="uk-margin">
            <div data-uk-grid class="uk-child-width-auto uk-grid-small">
              <div>
                <a href="#" data-uk-icon="icon: facebook" class="uk-icon-button facebook" target="_blank"></a>
              </div>
              <div>
                <a href="#" data-uk-icon="icon: linkedin" class="uk-icon-button linkedin" target="_blank"></a>
              </div>
              <div>
                <a href="#" data-uk-icon="icon: twitter" class="uk-icon-button twitter" target="_blank"></a>
              </div>
            </div>
          </div>
		  -->
        </div>
      </div>
    </div>
  </div>
</div>

<div id="lesson" class="uk-flex-top uk-modal-container" data-uk-modal>
	<div class="uk-modal-dialog uk-modal-body uk-margin-auto-vertical uk-light uk-background-secondary 
    uk-border-rounded-large">
		<button class="uk-modal-close-outside" type="button" data-uk-close></button>
    <h2 class="uk-modal-title">Introduction: a UXD Parable</h2>
      <iframe src="https://player.vimeo.com/video/126241629" width="1000" height="562" data-uk-responsive data-uk-video></iframe>
    <div class="uk-margin-top uk-article uk-text-muted">
      <p>Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et 
        dolore magna aliqua. Ut enim ad minim reprehenderit in voluptate velit esse cillum dolore eu 
        fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia 
        deserunt mollit anim id est laborum.</p>
    </div>    
  </div>
</div>

<!--
<div class="uk-section uk-section-muted">
  <div class="uk-container">
    <h3>Other Courses You May Like</h3>
    <div class="uk-child-width-1-2@s uk-child-width-1-3@m uk-margin-medium-top" data-uk-grid>
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
      <div>
        <div class="uk-card uk-card-small uk-card-default uk-card-hover uk-border-rounded-large uk-overflow-hidden">
          <div class="uk-card-media-top uk-inline uk-light">
            <img src="https://source.unsplash.com/JtV6zyOZSrA/640x380" alt="Course Title">
            <div class="uk-position-cover uk-overlay-xlight"></div>
            <div class="uk-position-small uk-position-top-left">
              <span class="uk-label uk-text-bold uk-text-price">$39.00</span>
            </div>
            <div class="uk-position-small uk-position-top-right">
              <a href="#" class="uk-icon-button uk-like uk-position-z-index uk-position-relative" data-uk-icon="heart"></a>
            </div>            
          </div>
          <div class="uk-card-body">
            <h3 class="uk-card-title uk-margin-small-bottom">Outdoor Portrait Photography</h3>
            <div class="uk-text-muted uk-text-small">Jannet Wilkins</div>
            <div class="uk-text-muted uk-text-xxsmall uk-rating uk-margin-small-top">
              <span class="uk-rating-filled" data-uk-icon="icon: star; ratio: 0.75"></span>
              <span class="uk-rating-filled" data-uk-icon="icon: star; ratio: 0.75"></span>
              <span class="uk-rating-filled" data-uk-icon="icon: star; ratio: 0.75"></span>
              <span class="uk-rating-filled" data-uk-icon="icon: star; ratio: 0.75"></span>
              <span data-uk-icon="icon: star; ratio: 0.75"></span>
              <span class="uk-margin-small-left uk-text-bold">4.0</span>
              <span>(6,650)</span>
            </div>
          </div>
          <a href="course.html" class="uk-position-cover"></a>
        </div>
      </div>
      <div>
        <div class="uk-card uk-card-small uk-card-default uk-card-hover uk-border-rounded-large uk-overflow-hidden">
          <div class="uk-card-media-top uk-inline uk-light">
            <img src="https://source.unsplash.com/442btOpRp9Q/640x380" alt="Course Title">
            <div class="uk-position-cover uk-overlay-xlight"></div>
            <div class="uk-position-small uk-position-top-left">
              <span class="uk-label uk-text-bold uk-text-price">$19.00</span>
            </div>
            <div class="uk-position-small uk-position-top-right">
              <a href="#" class="uk-icon-button uk-like uk-position-z-index uk-position-relative" data-uk-icon="heart"></a>
            </div>            
          </div>
          <div class="uk-card-body">
            <h3 class="uk-card-title uk-margin-small-bottom">Creating Digital Artwork on Tablet</h3>
            <div class="uk-text-muted uk-text-small">Chris Jenkins</div>
            <div class="uk-text-muted uk-text-xxsmall uk-rating uk-margin-small-top">
              <span class="uk-rating-filled" data-uk-icon="icon: star; ratio: 0.75"></span>
              <span class="uk-rating-filled" data-uk-icon="icon: star; ratio: 0.75"></span>
              <span class="uk-rating-filled" data-uk-icon="icon: star; ratio: 0.75"></span>
              <span class="uk-rating-filled" data-uk-icon="icon: star; ratio: 0.75"></span>
              <span data-uk-icon="icon: star; ratio: 0.75"></span>
              <span class="uk-margin-small-left uk-text-bold">4.0</span>
              <span>(6,650)</span>
            </div>
          </div>
          <a href="course.html" class="uk-position-cover"></a>
        </div>
      </div>
      <div>
        <div class="uk-card uk-card-small uk-card-default uk-card-hover uk-border-rounded-large uk-overflow-hidden">
          <div class="uk-card-media-top uk-inline uk-light">
            <img src="https://source.unsplash.com/hiAdjnXZxl8/640x380" alt="Course Title">
            <div class="uk-position-cover uk-overlay-xlight"></div>
            <div class="uk-position-small uk-position-top-left">
              <span class="uk-label uk-text-bold uk-text-price">$34.00</span>
            </div>
            <div class="uk-position-small uk-position-top-right">
              <a href="#" class="uk-icon-button uk-like uk-position-z-index uk-position-relative" data-uk-icon="heart"></a>
            </div>            
          </div>
          <div class="uk-card-body">
            <h3 class="uk-card-title uk-margin-small-bottom">Advanced Portrait Photography</h3>
            <div class="uk-text-muted uk-text-small">Linsey Tomkins</div>
            <div class="uk-text-muted uk-text-xxsmall uk-rating uk-margin-small-top">
              <span class="uk-rating-filled" data-uk-icon="icon: star; ratio: 0.75"></span>
              <span class="uk-rating-filled" data-uk-icon="icon: star; ratio: 0.75"></span>
              <span class="uk-rating-filled" data-uk-icon="icon: star; ratio: 0.75"></span>
              <span class="uk-rating-filled" data-uk-icon="icon: star; ratio: 0.75"></span>
              <span class="uk-rating-filled" data-uk-icon="icon: star; ratio: 0.75"></span>
              <span class="uk-margin-small-left uk-text-bold">4.95</span>
              <span>(2,350)</span>
            </div>
          </div>
          <a href="course.html" class="uk-position-cover"></a>
        </div>
      </div>
      <div>
        <div class="uk-card uk-card-small uk-card-default uk-card-hover uk-border-rounded-large uk-overflow-hidden">
          <div class="uk-card-media-top uk-inline uk-light">
            <img src="https://source.unsplash.com/AmEeEB1g3XQ/640x380" alt="Course Title">
            <div class="uk-position-cover uk-overlay-xlight"></div>
            <div class="uk-position-small uk-position-top-left">
              <span class="uk-label uk-text-bold uk-text-price">$45.00</span>
            </div>
            <div class="uk-position-small uk-position-top-right">
              <a href="#" class="uk-icon-button uk-like uk-position-z-index uk-position-relative" data-uk-icon="heart"></a>
            </div>            
          </div>
          <div class="uk-card-body">
            <h3 class="uk-card-title uk-margin-small-bottom">React Development Environment</h3>
            <div class="uk-text-muted uk-text-small">Ben Allen</div>
            <div class="uk-text-muted uk-text-xxsmall uk-rating uk-margin-small-top">
              <span class="uk-rating-filled" data-uk-icon="icon: star; ratio: 0.75"></span>
              <span class="uk-rating-filled" data-uk-icon="icon: star; ratio: 0.75"></span>
              <span class="uk-rating-filled" data-uk-icon="icon: star; ratio: 0.75"></span>
              <span class="uk-rating-filled" data-uk-icon="icon: star; ratio: 0.75"></span>
              <span data-uk-icon="icon: star; ratio: 0.75"></span>
              <span class="uk-margin-small-left uk-text-bold">4.0</span>
              <span>(6,650)</span>
            </div>
          </div>
          <a href="course.html" class="uk-position-cover"></a>
        </div>
      </div>
      <div>
        <div class="uk-card uk-card-small uk-card-default uk-card-hover uk-border-rounded-large uk-overflow-hidden">
          <div class="uk-card-media-top uk-inline uk-light">
            <img src="https://source.unsplash.com//jQqn81J-PhQ/640x380" alt="Course Title">
            <div class="uk-position-cover uk-overlay-xlight"></div>
            <div class="uk-position-small uk-position-top-left">
              <span class="uk-label uk-text-bold uk-text-price">$29.00</span>
            </div>
            <div class="uk-position-small uk-position-top-right">
              <a href="#" class="uk-icon-button uk-like uk-position-z-index uk-position-relative" data-uk-icon="heart"></a>
            </div>            
          </div>
          <div class="uk-card-body">
            <h3 class="uk-card-title uk-margin-small-bottom">Hatha Yoga for Complete Beginners</h3>
            <div class="uk-text-muted uk-text-small">Alex Jensen</div>
            <div class="uk-text-muted uk-text-xxsmall uk-rating uk-margin-small-top">
              <span class="uk-rating-filled" data-uk-icon="icon: star; ratio: 0.75"></span>
              <span class="uk-rating-filled" data-uk-icon="icon: star; ratio: 0.75"></span>
              <span class="uk-rating-filled" data-uk-icon="icon: star; ratio: 0.75"></span>
              <span data-uk-icon="icon: star; ratio: 0.75"></span>
              <span data-uk-icon="icon: star; ratio: 0.75"></span>
              <span class="uk-margin-small-left uk-text-bold">3.1</span>
              <span>(1,834)</span>
            </div>
          </div>
          <a href="course.html" class="uk-position-cover"></a>
        </div>
      </div>
    </div>
  </div>
</div>
-->
	
	<?php

	//echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>

