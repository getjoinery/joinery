<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once (LibraryFunctions::get_logic_file_path('event_logic.php'));
	require_once(LibraryFunctions::get_theme_path().'/includes/PublicPageTW.php');
	require_once(LibraryFunctions::get_theme_path().'/includes/FormWriterPublicTW.php');
	
	$formwriter = new FormWriterPublicTW("form1", TRUE, TRUE);
	
	$page = new PublicPageTW(TRUE);
	$page_options = array(
		'is_valid_page' => $is_valid_page,
		'title' => $event->get('evt_name')
	);
	if($event->get('evt_short_description')){
		$page_options['meta_description'] = $event->get('evt_short_description');
	}
	if($event->get_picture_link('large')){
		$page_options['preview_image_url'] = $event->get_picture_link('large');
	}
	$page->public_header($page_options);
	
	echo PublicPageTW::BeginPage('&nbsp;', $pageoptions);
	


	

	?>
	<!--
	STYLE FOR ACCORDION
	-->
	        <!-- THE CSS -->
        <style>
                details summary::-webkit-details-marker {
                display: none;
            }

             
            details[open] summary {
                background: blue;
                color: white
            }

            details[open] summary::after {
                content: "-";
            }

            details[open] summary ~ * {
                animation: slideDown 0.3s ease-in-out;
            }

            details[open] summary p {
                opacity: 0;
                animation-name: showContent;
                animation-duration: 0.6s;
                animation-delay: 0.2s;
                animation-fill-mode: forwards;
                margin: 0;
            }

            @keyframes showContent {
                from {
                opacity: 0;
                height: 0;
                }
                to {
                opacity: 1;
                height: auto;
                }
            }
            @keyframes slideDown {
                from {
                opacity: 0;
                height: 0;
                padding: 0;
                }

                to {
                opacity: 1;
                height: auto;
                }
            }
        </style>
  <main class="-mt-24 pb-8">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:max-w-7xl lg:px-8">
      <h1 class="sr-only">Profile</h1>
      <!-- Main 3 column grid -->
      <div class="grid grid-cols-1 gap-4 items-start lg:grid-cols-3 lg:gap-8">
        <!-- Left column -->
        <div class="grid grid-cols-1 gap-4 lg:col-span-2">
          <!-- Welcome panel -->
          <section aria-labelledby="profile-overview-title">
            <div class="rounded-lg bg-white overflow-hidden shadow">
              <h2 class="sr-only" id="profile-overview-title">Profile Overview</h2>
              <div class="bg-white p-6">
                <div class="sm:flex sm:items-center sm:justify-between">
                  <div class="sm:flex sm:space-x-5">
                    <!--<div class="flex-shrink-0">
                      <img class="mx-auto h-20 w-20 rounded-full" src="https://images.unsplash.com/photo-1550525811-e5869dd03032?ixlib=rb-1.2.1&ixid=eyJhcHBfaWQiOjEyMDd9&auto=format&fit=facearea&facepad=2&w=256&h=256&q=80" alt="">
                    </div>-->
                    <div class="mt-4 sm:mt-0 sm:pt-1 ">
                      <p class="text-xl font-bold text-gray-900 sm:text-2xl"><?php echo htmlspecialchars($event->get('evt_name')); ?></p>
					  <?php 
					  if($time_string = $event->get_time_string()){
						  echo '<p class="text-xl font-medium text-gray-600">'.$time_string.'</p>';
					  }
					  ?>
					  
					  <?php
						if($event->get('evt_timezone') != $session->get_timezone()){
							echo '<p class="text-xl font-medium text-gray-600">'.$event->get_time_string($session->get_timezone()).'</p>';
						}	
					  ?>
					  <?php
					  	if($event->get('evt_location')){
							echo '<p class="text-l font-medium text-gray-600">'.$event->get('evt_location').'</p>';
						}
					  ?>
					  <?php
						if($event->get('evt_usr_user_id_leader')){
							$leader = new User($event->get('evt_usr_user_id_leader'), TRUE);
							echo '<p class="text-l font-medium text-gray-600">Led by: '.$leader->display_name().'</p>';
						}
					  ?>
                      
                    </div>
                  </div>
                  
                </div>
              </div>
			  <!--
              <div class="border-t border-gray-200 bg-gray-50 grid grid-cols-1 divide-y divide-gray-200 sm:grid-cols-3 sm:divide-y-0 sm:divide-x">
                <div class="px-6 py-5 text-sm font-medium text-center">
                  <span class="text-gray-900">12</span>
                  <span class="text-gray-600">Test
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

          <!-- Actions panel -->
          <section aria-labelledby="quick-links-title">
            <div class="rounded-lg bg-white overflow-hidden shadow p-6">
              
				
				<?php if($picture_link = $event->get_picture_link('medium')){ ?>
					<div class="mb-5">
					<img src="<?php echo $picture_link; ?>">
					</div>
				<?php } ?>
			<h2 class="text-base font-medium text-gray-900" id="description-title">Description</h2>
              <?php echo '<div class="prose">'.$event->get('evt_description').'</div>'; ?>


            </div>
          </section>
        </div>

        <!-- Right column -->
        <div class="grid grid-cols-1 gap-4">
		
		
		<!-- Register Info -->
          <section aria-labelledby="registration-title">
            <div class="rounded-lg bg-white overflow-hidden shadow">
              <div class="p-6">
                <h2 class="text-base font-medium text-gray-900" id="registration-title">Registration</h2>
                <div class="flow-root mt-6">
                  
                      <div class="relative focus-within:ring-2 focus-within:ring-cyan-500">
                       <!--
					   <h3 class="text-sm font-semibold text-gray-800">
                          <a href="#" class="hover:underline focus:outline-none">
                            
                            <span class="absolute inset-0" aria-hidden="true"></span>
                            Office closed on July 2nd
                          </a>
                        </h3>
						-->
                        <p class="mt-1 text-sm text-gray-600 line-clamp-2">
                          <?php
			
							if($registration_message){
								echo '<p>'.$registration_message.'</p>';
							}


							foreach($register_urls as $register_url){
								echo $formwriter->new_button($register_url['label'], $register_url['link'], 'primary', 'full');	
							}			
							

							
							if($if_registered_message){
								echo '<p>'.$if_registered_message.'</p>';
							}

							?>
                        </p>
                      </div>
                   
                </div>


				<!--				
                <div class="mt-6">
                  <a href="#" class="w-full flex justify-center items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    View all
                  </a>
                </div>
				-->
              </div>
            </div>
          </section>		
		
		
		
          <!-- Sessions -->
          <section aria-labelledby="announcements-title">
            <div class="rounded-lg bg-white overflow-hidden shadow">
              <div class="p-6">
                <h2 class="text-base font-medium text-gray-900" id="announcements-title">Sessions</h2>
                <div class="flow-root mt-6">
				
	

<?php
	//CHECK FOR SESSIONS
	if($event->get('evt_session_display_type') == Event::DISPLAY_SEPARATE && $numsessions > 0){

		foreach($event_sessions as $event_session){	
			if($event_session->get('evs_session_number')){
				?>
 
				<details class="w-full bg-white border border-blue-500 cursor-pointer mb-3">
					<summary class="w-full bg-white text-dark flex justify-between px-4 py-3  after:content-['+']"><?php echo 'Session ' . $event_session->get('evs_session_number') . ' -  ' . $event_session->get('evs_title'); ?></summary>
					<div class="px-4 py-3">
					<?php echo preg_replace('#<a.*?>(.*?)</a>#i', '\1', $event_session->get('evs_content')); ?>
					</div>
				</details>
							
				<?php
			}
			else{
				?>
				<details class="w-full bg-white border border-blue-500 cursor-pointer mb-3">
					<summary class="w-full bg-white text-dark flex justify-between px-4 py-3  after:content-['+']"><?php echo $event_session->get('evs_title'); ?></summary>
					<div class="px-4 py-3">
					<?php echo preg_replace('#<a.*?>(.*?)</a>#i', '\1', $event_session->get('evs_content')); ?>
					</div>
				</details>
						
				<?php						
			}
			
			;	
		}
		$page->endtable();	
	}
	else{	
		if($future_numsessions > 0){

			foreach($future_event_sessions as $event_session){	
				if($time_string = $event_session->get_time_string($tz)){
					$time_string = ' -  ' . $time_string;
				}
				?>
				<details class="w-full bg-white border border-blue-500 cursor-pointer mb-3">
					<summary class="w-full bg-white text-dark flex justify-between px-4 py-3  after:content-['+']"><?php echo $event_session->get('evs_title'); ?></summary>
					<div class="px-4 py-3">
					<?php echo preg_replace('#<a.*?>(.*?)</a>#i', '\1', $event_session->get('evs_content')); ?>
					</div>
				</details>
				<?php							
			}	
		}


		if($past_numsessions > 0){
			echo '<h3>Past Sessions</h3>';

			foreach($past_event_sessions as $event_session){
				if($time_string = $event_session->get_time_string($tz)){
					$time_string = ' -  ' . $time_string;
				}
				?>
				<details class="w-full bg-white border border-blue-500 cursor-pointer mb-3">
					<summary class="w-full bg-white text-dark flex justify-between px-4 py-3  after:content-['+']"><?php echo $event_session->get('evs_title') . $time_string; ?></summary>
					<div class="px-4 py-3">
					<?php echo preg_replace('#<a.*?>(.*?)</a>#i', '\1', $event_session->get('evs_content')); ?>
					echo '<p><a href="/profile/event_sessions?evt_event_id='. $event->key.'">View videos and materials</a></p>';
					</div>
				</details>
							
				<?php									
			}	
		}			
	}
	?>


                </div>
				<!--
                <div class="mt-6">
                  <a href="#" class="w-full flex justify-center items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    View all
                  </a>
                </div>
				-->
              </div>
            </div>
          </section>

          <!-- Recent Hires -->
		  <!--
          <section aria-labelledby="recent-hires-title">
            <div class="rounded-lg bg-white overflow-hidden shadow">
              <div class="p-6">
                <h2 class="text-base font-medium text-gray-900" id="recent-hires-title">Recent Hires</h2>
                <div class="flow-root mt-6">
                  <ul role="list" class="-my-5 divide-y divide-gray-200">
                    <li class="py-4">
                      <div class="flex items-center space-x-4">
                        <div class="flex-shrink-0">
                          <img class="h-8 w-8 rounded-full" src="https://images.unsplash.com/photo-1519345182560-3f2917c472ef?ixlib=rb-1.2.1&ixid=eyJhcHBfaWQiOjEyMDd9&auto=format&fit=facearea&facepad=2&w=256&h=256&q=80" alt="">
                        </div>
                        <div class="flex-1 min-w-0">
                          <p class="text-sm font-medium text-gray-900 truncate">
                            Leonard Krasner
                          </p>
                          <p class="text-sm text-gray-500 truncate">
                            @leonardkrasner
                          </p>
                        </div>
                        <div>
                          <a href="#" class="inline-flex items-center shadow-sm px-2.5 py-0.5 border border-gray-300 text-sm leading-5 font-medium rounded-full text-gray-700 bg-white hover:bg-gray-50">
                            View
                          </a>
                        </div>
                      </div>
                    </li>

                    <li class="py-4">
                      <div class="flex items-center space-x-4">
                        <div class="flex-shrink-0">
                          <img class="h-8 w-8 rounded-full" src="https://images.unsplash.com/photo-1463453091185-61582044d556?ixlib=rb-1.2.1&ixid=eyJhcHBfaWQiOjEyMDd9&auto=format&fit=facearea&facepad=2&w=256&h=256&q=80" alt="">
                        </div>
                        <div class="flex-1 min-w-0">
                          <p class="text-sm font-medium text-gray-900 truncate">
                            Floyd Miles
                          </p>
                          <p class="text-sm text-gray-500 truncate">
                            @floydmiles
                          </p>
                        </div>
                        <div>
                          <a href="#" class="inline-flex items-center shadow-sm px-2.5 py-0.5 border border-gray-300 text-sm leading-5 font-medium rounded-full text-gray-700 bg-white hover:bg-gray-50">
                            View
                          </a>
                        </div>
                      </div>
                    </li>

                    <li class="py-4">
                      <div class="flex items-center space-x-4">
                        <div class="flex-shrink-0">
                          <img class="h-8 w-8 rounded-full" src="https://images.unsplash.com/photo-1502685104226-ee32379fefbe?ixlib=rb-1.2.1&ixid=eyJhcHBfaWQiOjEyMDd9&auto=format&fit=facearea&facepad=2&w=256&h=256&q=80" alt="">
                        </div>
                        <div class="flex-1 min-w-0">
                          <p class="text-sm font-medium text-gray-900 truncate">
                            Emily Selman
                          </p>
                          <p class="text-sm text-gray-500 truncate">
                            @emilyselman
                          </p>
                        </div>
                        <div>
                          <a href="#" class="inline-flex items-center shadow-sm px-2.5 py-0.5 border border-gray-300 text-sm leading-5 font-medium rounded-full text-gray-700 bg-white hover:bg-gray-50">
                            View
                          </a>
                        </div>
                      </div>
                    </li>

                    <li class="py-4">
                      <div class="flex items-center space-x-4">
                        <div class="flex-shrink-0">
                          <img class="h-8 w-8 rounded-full" src="https://images.unsplash.com/photo-1500917293891-ef795e70e1f6?ixlib=rb-1.2.1&ixid=eyJhcHBfaWQiOjEyMDd9&auto=format&fit=facearea&facepad=2&w=256&h=256&q=80" alt="">
                        </div>
                        <div class="flex-1 min-w-0">
                          <p class="text-sm font-medium text-gray-900 truncate">
                            Kristin Watson
                          </p>
                          <p class="text-sm text-gray-500 truncate">
                            @kristinwatson
                          </p>
                        </div>
                        <div>
                          <a href="#" class="inline-flex items-center shadow-sm px-2.5 py-0.5 border border-gray-300 text-sm leading-5 font-medium rounded-full text-gray-700 bg-white hover:bg-gray-50">
                            View
                          </a>
                        </div>
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
		  -->
        </div>
      </div>
    </div>
  </main>	
	
	
		

		<?php


	echo PublicPageTW::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE));
?>

