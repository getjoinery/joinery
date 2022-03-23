<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/PublicPageTW.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/FormWriterPublicTW.php');
	require_once(LibraryFunctions::get_logic_file_path('event_sessions_course_logic.php'));
	
	if($error_message){
		PublicPageTW::OutputGenericPublicPage('Not Registered', 'Not Registered', $error_message);
		exit();
	}	

	$page = new PublicPageTW();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Sessions', 
		'breadcrumbs' => array(
			'My Profile' => '/profile/profile',
			'Event' => '',
		), 
	);
	$page->public_header($hoptions,NULL);

	echo PublicPageTW::BeginPage('&nbsp;', $hoptions);
	

	$session_name = 'Session ' . $event_session->get('evs_session_number') . ' - '.$event_session->get('evs_title');

	?>
	
 <main class="-mt-24 pb-8">

	
	
<header class="relative z-20 flex items-center justify-between pb-4 px-4 sm:px-6 lg:flex-none">
	<div class="mt-4 sm:mt-0 sm:pt-1 sm:text-left">
		<h1 class="text-xl font-bold text-gray-900 sm:text-2xl">
			<?php echo htmlspecialchars($event->get('evt_name')); ?>
		</h1>
		<p class="text-sm font-medium text-gray-600">
		  <?php 	
		  $time_string = $event->get_time_string();
			if($event->get('evt_timezone') != $session->get_timezone()){
				if($local_string = $event->get_time_string($session->get_timezone())){
					$time_string .= ' ('. $local_string . ')';
				}
			}	
			echo $time_string;
			?>
		</p>
		<?php
		$calendar_text = '';
		if($event->get('evt_status') != 2 && $event->get('evt_status') != 3){
			$calendar_links = $event->get_add_to_calendar_links();
			if($calendar_links){
				$calendar_text .= 'Add to calendar: <a href="'.$calendar_links['google'].'">google</a> | ';
				$calendar_text .= '<a href="'.$calendar_links['yahoo'].'">yahoo</a> | ';
				$calendar_text .= '<a href="'.$calendar_links['outlook'].'">outlook</a> | ';
				$calendar_text .= '<a href="'.$calendar_links['ics'].'">ical</a> ';
			}
		}
		echo '<div class="mt-4 text-sm font-medium text-gray-600 sm:mt-0 sm:pt-1 sm:text-left">'.$calendar_text.'</div>';
		?>
	</div>
	
	<?php 
	if($event->get('evt_end_time') > date('Y-m-d H:i:s')){
		$options = array('Withdraw from Course'=> '/profile/event_withdraw?evr_event_registrant_id='.$event_registrant->key);
	}
	else{
		$options = array();
	}
	?>
	</header>
	
	
	
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:max-w-7xl lg:px-8">	
      <h1 class="sr-only">Profile</h1>
      <!-- Main 3 column grid -->
      <div class="grid grid-cols-1 gap-4 items-start lg:grid-cols-3 lg:gap-8">
        <!-- Left column -->
        <div class="grid grid-cols-1 gap-4 lg:col-span-2">
          <!-- Welcome panel -->
          <section aria-labelledby="profile-overview-title">
            
			
			
			
			
			<div class="rounded-lg bg-white overflow-hidden shadow">
              <h2 class="sr-only" id="profile-overview-title">Sessions Overview</h2>
              


  <div class="border-t border-gray-200 px-4 py-5 sm:px-6">
    <?php echo $event->get('evt_short_description'); ?>
  </div>

<!--
  <div class="border-t border-gray-200 px-4 py-5 sm:px-6">
    <dl class="grid grid-cols-1 gap-4 gap-y-8 sm:grid-cols-2">
      <div class="sm:col-span-1">
        <dt class="text-sm font-medium text-gray-500">
          Email
        </dt>
        <dd class="mt-1 text-sm text-gray-900">
          <?php //echo htmlspecialchars($user->get('usr_email')); ?>
        </dd>
      </div>
      <div class="sm:col-span-1">
        <dt class="text-sm font-medium text-gray-500">
          Phone
        </dt>
        <dd class="mt-1 text-sm text-gray-900">
          <?php //echo $phone_number->get_phone_string(); ?>
        </dd>
      </div>
      <div class="sm:col-span-1">
        <dt class="text-sm font-medium text-gray-500">
          Address
        </dt>
        <dd class="mt-1 text-sm text-gray-900">
          <?php //echo $address->get_address_string(', '); ?>
        </dd>
      </div>
      <div class="sm:col-span-1">
        <dt class="text-sm font-medium text-gray-500">
          Timezone
        </dt>
        <dd class="mt-1 text-sm text-gray-900">
          <?php //echo $user->get('usr_timezone'); ?>
        </dd>
      </div>
    </dl>
  </div>
-->

	<!--	
              <div class="border-t border-gray-200 bg-gray-50 grid grid-cols-1 divide-y divide-gray-200 sm:grid-cols-3 sm:divide-y-0 sm:divide-x">
                <div class="px-6 py-5 text-sm font-medium text-center">
                  <span class="text-gray-900">12</span>
                  <span class="text-gray-600">Vacation days left</span>
                </div>

                <div class="px-6 py-5 text-sm font-medium text-center">
                  <span class="text-gray-900">4</span>
                  <span class="text-gray-600">Sick days left</span>
                </div>

                <div class="px-6 py-5 text-sm font-medium text-center">
                  <span class="text-gray-900">2</span>
                  <span class="text-gray-600">Personal days left</span>
                </div>
              </div>
			  -->
            </div>
          </section>

<?php /*
<div>
  <div class="sm:hidden">
    <label for="tabs" class="sr-only">Select a tab</label>
    <!-- Use an "onChange" listener to redirect the user to the selected tab URL. -->
    <select id="tabs" name="tabs" class="block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
      <option <?php if(!$_REQUEST['tab']){ echo 'selected'; } ?>>Active Events</option>

      <option <?php if($_REQUEST['tab'] == 'past'){ echo 'selected'; } ?>>Past Events</option>

    </select>
  </div>
  <div class="hidden sm:block">
    <div class="border-b border-gray-200">
      <nav class="-mb-px flex space-x-8" aria-label="Tabs">
        <!-- Current: "border-indigo-500 text-indigo-600", Default: "border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300" -->
		<?php
		$current_style = 'class="border-indigo-500 text-indigo-600 group inline-flex items-center py-4 px-1 border-b-2 font-medium text-sm" aria-current="page"';
		$standard_style = 'class="border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm"';
		?>
        <a href="/profile/profile"  <?php if(!$_REQUEST['tab']){ echo $current_style; } else{ echo $standard_style; } ?>>
          Active Events
        </a>

        <a href="/profile/profile?tab=past" <?php if($_REQUEST['tab'] == 'past'){ echo $current_style; } else{ echo $standard_style; } ?>>
          Past Events
        </a>
      </nav>
    </div>
  </div>
</div>
*/
?>

          <!-- Actions panel -->
          <section aria-labelledby="quick-links-title">
            <div class="rounded-lg bg-gray-200 overflow-hidden shadow divide-y divide-gray-200 sm:divide-y-0 sm:grid  sm:gap-px">
              <h2 class="sr-only" id="quick-links-title">Sessions</h2>


                

<div class="bg-white shadow overflow-hidden sm:rounded-md">
  <ul role="list" class="divide-y divide-gray-200">
    
		
									<li>
									  <!--<a href="<?php echo $course_link; ?>" class="block hover:bg-gray-50">-->
										<div class="px-4 py-4 sm:px-6">
										  <div class="flex items-center justify-between">
											<p class="text-sm font-medium text-indigo-600 truncate">
											  <?php echo $session_name; ?>
											</p>
											<div class="ml-2 flex-shrink-0 flex">
											  
												<?php
												/*
												if($event->get('evt_status') == Event::STATUS_ACTIVE){
													echo '<p class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Upcoming</p>';
												} 
												else if($event->get('evt_status') == Event::STATUS_CANCELED){
													echo '<p class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">Canceled</p>';
												}
												else if($event->get('evt_status') == Event::STATUS_COMPLETED){
													echo '<p class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">Completed</p>';
												}
												*/
												?>
											  
											</div>
										  </div>
										  <div class="mt-2 sm:flex sm:justify-between">
											<?php if($time_string){ ?>
											<div class="sm:flex mb-3">
											  <p class="flex items-center text-sm text-gray-500">
											  <!-- Heroicon name: solid/calendar -->
											  <svg class="flex-shrink-0 mr-1.5 h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
												<path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd" />
											  </svg>
												<?php echo $time_string; ?>
											  </p>
											  </div>
											<?php } ?>
											
											<div class="mt-2 flex items-center text-sm text-gray-500 sm:mt-0">
											
											  <!-- Heroicon name: solid/calendar -->
											  <!--
											  <svg class="flex-shrink-0 mr-1.5 h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
												<path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd" />
											  </svg>
											  -->
											  <p>
												<?php echo $actions; ?>
												<!--<time datetime="2020-01-07">January 7, 2020</time>-->
											  </p>
											</div>
										  </div>
										  
										  
									<?php 
						if($video->key && !$video->get('vid_delete_time')){
							echo $video->get_embed(784,441);
						}
						else if($event->get('evt_picture_link')){
							echo '<img width="800" height="532" src="'.$event_session->get('evs_picture_link'). '"  alt="" />';
						}
						?>
						<div class="prose mt-3"><?php echo $event_session->get('evs_content'); ?></div>
						<?php
						$session_files = $event_session->get_files();
						$num_session_files = 0;
						foreach($session_files as $session_file){
							$num_session_files++;
						}
				
						$session_files = $event_session->get_files();
						if($session_files){
						?>
						<div class="margin-top-20 prose">
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
										  
										  
										  
										  
										  
										  
										  
										  
										</div>
									  <!--</a>-->
									</li>			
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
										echo '<div><a class="block bg-gray-50 text-sm font-medium text-gray-500 text-center px-4 py-4 hover:text-gray-700 sm:rounded-b-lg" href="/profile/event_sessions_course?session_number='.$next_session.'&event_id='.$event->key.'">Next Session</a></div>';
									}								
								

						
								
								
							
	?>

  </ul>
</div>


             
           
          </section>
        </div>

        <!-- Right column -->
        <div class="grid grid-cols-1 gap-4">
		
		<?php /* ?>
          <!-- Announcements -->
          <section aria-labelledby="announcements-title">
            <div class="rounded-lg bg-white overflow-hidden shadow">
              <div class="p-6">
                <h2 class="text-base font-medium text-gray-900" id="announcements-title">Announcements</h2>
                <div class="flow-root mt-6">
                  <ul role="list" class="-my-5 divide-y divide-gray-200">
                    <li class="py-5">
                      <div class="relative focus-within:ring-2 focus-within:ring-cyan-500">
                        <h3 class="text-sm font-semibold text-gray-800">
                          <a href="#" class="hover:underline focus:outline-none">
                            <!-- Extend touch target to entire panel -->
                            <span class="absolute inset-0" aria-hidden="true"></span>
                            Office closed on July 2nd
                          </a>
                        </h3>
                        <p class="mt-1 text-sm text-gray-600 line-clamp-2">
                          Cum qui rem deleniti. Suscipit in dolor veritatis sequi aut. Vero ut earum quis deleniti. Ut a sunt eum cum ut repudiandae possimus. Nihil ex tempora neque cum consectetur dolores.
                        </p>
                      </div>
                    </li>

                    <li class="py-5">
                      <div class="relative focus-within:ring-2 focus-within:ring-cyan-500">
                        <h3 class="text-sm font-semibold text-gray-800">
                          <a href="#" class="hover:underline focus:outline-none">
                            <!-- Extend touch target to entire panel -->
                            <span class="absolute inset-0" aria-hidden="true"></span>
                            New password policy
                          </a>
                        </h3>
                        <p class="mt-1 text-sm text-gray-600 line-clamp-2">
                          Alias inventore ut autem optio voluptas et repellendus. Facere totam quaerat quam quo laudantium cumque eaque excepturi vel. Accusamus maxime ipsam reprehenderit rerum id repellendus rerum. Culpa cum vel natus. Est sit autem mollitia.
                        </p>
                      </div>
                    </li>

                    <li class="py-5">
                      <div class="relative focus-within:ring-2 focus-within:ring-cyan-500">
                        <h3 class="text-sm font-semibold text-gray-800">
                          <a href="#" class="hover:underline focus:outline-none">
                            <!-- Extend touch target to entire panel -->
                            <span class="absolute inset-0" aria-hidden="true"></span>
                            Office closed on July 2nd
                          </a>
                        </h3>
                        <p class="mt-1 text-sm text-gray-600 line-clamp-2">
                          Tenetur libero voluptatem rerum occaecati qui est molestiae exercitationem. Voluptate quisquam iure assumenda consequatur ex et recusandae. Alias consectetur voluptatibus. Accusamus a ab dicta et. Consequatur quis dignissimos voluptatem nisi.
                        </p>
                      </div>
                    </li>
                  </ul>
                </div>
                <div class="mt-6">
                  <a href="#" class="w-full flex justify-center items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    View all
                  </a>
                </div>
              </div>
            </div>
          </section>
		  
		  <?php */ ?>



          <!-- Sessions List -->

				<section aria-labelledby="recent-hires-title">
					<div class="rounded-lg bg-white overflow-hidden shadow">
					  <div class="p-6">
						<h2 class="text-base font-medium text-gray-900" id="recent-hires-title">Sessions</h2>
						<div class="flow-root mt-6 prose">
						 <?php 
						 foreach($event_sessions as $aevent_session){			
							echo '<a href="/profile/event_sessions_course?session_number='.$aevent_session->get('evs_session_number').'&event_id='. $event->key.'">Session ' . $aevent_session->get('evs_session_number') . ' - '.$aevent_session->get('evs_title').'</a><br />';

						}
						?>
                </div>
                
              </div>
            </div>
          </section>
				
			
          <!-- Private Info -->
		  <?php
			if($event->get('evt_private_info')){
				?>
				<section aria-labelledby="recent-hires-title">
					<div class="rounded-lg bg-white overflow-hidden shadow">
					  <div class="p-6">
						<h2 class="text-base font-medium text-gray-900" id="recent-hires-title">Registrant Info</h2>
						<div class="flow-root mt-6">
						 <?php echo $event->get('evt_private_info'); ?>
                </div>
                
              </div>
            </div>
          </section>
		  <?php
			}
			?>			
			
        </div>
      </div>
    </div>
</main>		
	
<?php
	echo PublicPageTW::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'show_survey'=>TRUE));
?>
