<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_file_path('PublicPageTW.php', '/includes'));
	require_once(LibraryFunctions::get_logic_file_path('profile_logic.php'));

	$page_vars = profile_logic($_GET, $_POST);
	
	$page = new PublicPageTW();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'My Profile', 
		'breadcrumbs' => array (
			'My Profile' => '',
			),
	);
	$page->public_header($hoptions,NULL);

	echo PublicPageTW::BeginPage('My Profile', $hoptions);
	

	foreach($page_vars['display_messages'] AS $display_message) {
		if($display_message->identifier == 'profilebox') {	
			echo PublicPageTW::alert($display_message->message_title, $display_message->message, $display_message->get_message_class());
		}
	}	
		
	if($page_vars['settings']->get_setting('events_active')){
		//DISPLAY REGISTER FINISH LINKS
		foreach($event_registrants as $event_registrant){
			if(!$event_registrant->get('evr_extra_info_completed')){
				$event = new Event($event_registrant->get('evr_evt_event_id'), TRUE);
				if($event->get('evt_collect_extra_info') && $event->get('evt_status') == 1){
					$act_code = Activation::CheckForActiveCode($user->key, Activation::EMAIL_VERIFY);
					$line = 'Your registration for <strong>'.$event->get('evt_name').'</strong> needs some additional information. <a href="/profile/event_register_finish?act_code='.$act_code->act_code.'&userid='.$user->key.'&eventregistrantid='.$event_registrant->key.'">click here to add the information</a>';
					echo '<div class="status_warning">'.$line.'</div><br /><br />';
				}
			}
		}			
	} 
	?>





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
					<!--
                    <div class="flex-shrink-0">
                      <img class="mx-auto h-20 w-20 rounded-full" src="https://images.unsplash.com/photo-1550525811-e5869dd03032?ixlib=rb-1.2.1&ixid=eyJhcHBfaWQiOjEyMDd9&auto=format&fit=facearea&facepad=2&w=256&h=256&q=80" alt="">
                    </div>
					-->
	

	
					
                    <div class="mt-4 sm:mt-0 sm:pt-1 sm:text-left">
                      <p class="text-xl font-bold text-gray-900 sm:text-2xl"><?php echo htmlspecialchars($page_vars['user']->display_name()); ?></p>
                      <!--<p class="text-sm font-medium text-gray-600"><?php echo htmlspecialchars($page_vars['user']->get('usr_email')); ?></p>-->
					  

                    </div>
                  </div>
                  <div class="mt-5 flex justify-center sm:mt-0">
                    <a href="/profile/account_edit" class="flex justify-center items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                      Edit profile
                    </a>
                  </div>
                </div>
              </div>



  <div class="border-t border-gray-200 px-4 py-5 sm:px-6">
    <dl class="grid grid-cols-1 gap-4 gap-y-8 sm:grid-cols-2">
      <div class="sm:col-span-1">
        <dt class="text-sm font-medium text-gray-500">
          Email
        </dt>
        <dd class="mt-1 text-sm text-gray-900">
          <?php echo htmlspecialchars($page_vars['user']->get('usr_email')); ?>
        </dd>
      </div>
      <div class="sm:col-span-1">
        <dt class="text-sm font-medium text-gray-500">
          Phone
        </dt>
        <dd class="mt-1 text-sm text-gray-900">
          <?php echo $page_vars['phone_number']->get_phone_string(); ?>
        </dd>
      </div>
      <div class="sm:col-span-1">
        <dt class="text-sm font-medium text-gray-500">
          Address
        </dt>
        <dd class="mt-1 text-sm text-gray-900">
          <?php echo $page_vars['address']->get_address_string(', '); ?>
        </dd>
      </div>
      <div class="sm:col-span-1">
        <dt class="text-sm font-medium text-gray-500">
          Timezone
        </dt>
        <dd class="mt-1 text-sm text-gray-900">
          <?php echo $page_vars['user']->get('usr_timezone'); ?>
        </dd>
      </div>
	  
      <div class="sm:col-span-2">
        <dt class="text-sm font-medium text-gray-500">
          Mailing List Subscriptions
        </dt>
        <dd class="mt-1 text-sm text-gray-900">
          <?php 
			echo '<br>';
			if(empty($page_vars['user_subscribed_list'])){
				echo 'You are not subscribed to any mailing lists.<br>';
			}
			else{
				echo 'You are subscribed to the following lists: '.implode(', ', $page_vars['user_subscribed_list']).'<br>';
			}
			?>
        </dd>
      </div>
      
    </dl>
  </div>

 
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
              <h2 class="sr-only" id="quick-links-title">Events</h2>


                

<div class="bg-white shadow overflow-hidden sm:rounded-md">
  <ul role="list" class="divide-y divide-gray-200">
    


	<?php

							if(!$page_vars['num_events']){
									echo '<p class="mt-6 px-4 py-5 ">You have no event registrations.</p>';						
							}
							else{
								foreach($page_vars['event_registrants'] as $event_registrant){
									$event = new Event($event_registrant->get('evr_evt_event_id'), TRUE);
									if(!$event || !$event->get('evt_visibility') || $event->get('evt_delete_time')){
										continue;
									}
									$next_session = $event->get_next_session();

									
									$time = '';
									$tz = $event->get('evt_timezone');
									if($next_session){
										$time = '<b>Next session: ';
										
										if($event->get('evt_timezone') != $page_vars['session']->get_timezone()){
											$time .= $next_session->get_time_string($page_vars['session']->get_timezone());
										}
										else{
											$time .= $next_session->get_time_string($tz);
										}
										$time .= '</b>';
									}
									else if($event->get('evt_status') != 2 && $event->get('evt_status') != 3){
		
										if($event->get('evt_timezone') != $page_vars['session']->get_timezone()){
											$time .= $event->get_time_string($page_vars['session']->get_timezone());			
										}
										else{
											$time .= $event->get_time_string($tz);
										}
									}
									
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
									
									if($event->get('evt_session_display_type')==2){
										$course_link = '/profile/event_sessions_course?evt_event_id='.$event->key;
									}
									else{
										$course_link = '/profile/event_sessions?evt_event_id='.$event->key;
									}
									
									$actions = '';
									if(!$event_registrant->get('evr_extra_info_completed') && $event->get('evt_collect_extra_info') && $event->get('evt_status') == 1){
										$act_code = Activation::CheckForActiveCode($user->key, Activation::EMAIL_VERIFY);
										$actions .= '<a href="/profile/event_register_finish?act_code='.$act_code->act_code.'&userid='.$user->key.'&eventregistrantid='.$event_registrant->key.'">Additional information needed</a> ';
									}

									
									?>			
									<li>
									  <a href="<?php echo $course_link; ?>" class="block hover:bg-gray-50">
										<div class="px-4 py-4 sm:px-6">
										  <div class="flex items-center justify-between">
											<p class="text-sm font-medium text-indigo-600 truncate">
											  <?php echo $event->get('evt_name'); ?>
											</p>
											<div class="ml-2 flex-shrink-0 flex">
											  
												<?php
												if($event_registrant->get('evr_expires_time') && $event_registrant->get('evr_expires_time') < date("Y-m-d H:i:s")){
													echo '<p class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">Expired</p>';
												} 
												else{
													if($event->get('evt_status') == Event::STATUS_ACTIVE){
														if($event_registrant->get('evr_expires_time')){
															echo '<p class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Expires '.LibraryFunctions::convert_time($event_registrant->get('evr_expires_time'), 'UTC', $page_vars['session']->get_timezone()).'</p>';
														}
														else{
															echo '<p class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Active</p>';
														}
													} 
													else if($event->get('evt_status') == Event::STATUS_CANCELED){
														echo '<p class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">Canceled</p>';
													}
													else if($event->get('evt_status') == Event::STATUS_COMPLETED){
														echo '<p class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">Completed</p>';
													}
												}
												?>
											  
											</div>
										  </div>
										  <div class="mt-2 sm:flex sm:justify-between">
											<div class="sm:flex">
												<?php if($time){ ?>
											  <p class="flex items-center text-sm text-gray-500">
											  <!-- Heroicon name: solid/calendar -->
											  <svg class="flex-shrink-0 mr-1.5 h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
												<path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd" />
											  </svg>
												<?php echo $time; ?>
											  </p>
												<?php } ?>
											  </div>


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
										</div>
									  </a>
									</li>			
									<?php
									
								}
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

          <!-- Subscriptions -->
		  <?php
			if($page_vars['settings']->get_setting('products_active') && $page_vars['settings']->get_setting('subscriptions_active')){
			?>
          <section aria-labelledby="recent-hires-title">
            <div class="rounded-lg bg-white overflow-hidden shadow">
              <div class="p-6">
                <h2 class="text-base font-medium text-gray-900" id="recent-hires-title">Your Subscriptions</h2>
                <div class="flow-root mt-6">
                  <ul role="list" class="-my-5 divide-y divide-gray-200">
				  
					<?php
						foreach($page_vars['subscriptions'] as $subscription){	
								
							if($subscription->get('odi_subscription_cancelled_time')){
								$status = ' canceled on '. LibraryFunctions::convert_time($subscription->get('odi_subscription_cancelled_time'), 'UTC', $page_vars['session']->get_timezone());
								$action = '';
							}
							else{
								$status = 'active';
								$action = '<a class="inline-flex items-center shadow-sm px-2.5 py-0.5 border border-gray-300 text-sm leading-5 font-medium rounded-full text-gray-700 bg-white hover:bg-gray-50" href="/profile/orders_recurring_action?order_item_id='. $subscription->key . '">cancel</a>';
								
							}
							?>
							<li class="py-4">
							  <div class="flex items-center space-x-4">
							  <!--
								<div class="flex-shrink-0">
								  <img class="h-8 w-8 rounded-full" src="https://images.unsplash.com/photo-1519345182560-3f2917c472ef?ixlib=rb-1.2.1&ixid=eyJhcHBfaWQiOjEyMDd9&auto=format&fit=facearea&facepad=2&w=256&h=256&q=80" alt="">
								</div>
								-->
								<div class="flex-1 min-w-0">
								  <p class="text-sm font-medium text-gray-900 truncate">
									<?php echo '$'.$subscription->get('odi_price') .'/month'; ?>
								  </p>
								  
								  <p class="text-sm text-gray-500 truncate">
									<?php echo $status; ?>
								  </p>
								  
								</div>
								<?php
								if($action){
								?>
								<div>
								  <?php echo $action; ?>
								</div>
								<?php
								}
								?>
							  </div>
							</li>

							<?php
								
						}

						?>				  

				  
                  </ul>
                </div>
                <div class="mt-6">
					<?php
						if(!$active){
							echo '<a href="/product/recurring-donation" class="w-full flex justify-center items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
							Start a new subscription
						  </a>';
							
						}
					?>
                  
                </div>
              </div>
            </div>
          </section>
		  <?php
			}
			?>
			
			
			
          <!-- Order History -->
		  <?php
			if($page_vars['settings']->get_setting('products_active')){
				?>
				<section aria-labelledby="recent-hires-title">
					<div class="rounded-lg bg-white overflow-hidden shadow">
					  <div class="p-6">
						<h2 class="text-base font-medium text-gray-900" id="recent-hires-title">Your Orders</h2>
						<div class="flow-root mt-6">
						  <ul role="list" class="-my-5 divide-y divide-gray-200">
						  <?php
				
				
				foreach($page_vars['orders'] as $order) {
					?>
					<li class="py-4">
					  <div class="flex items-center space-x-4">
					  <!--
						<div class="flex-shrink-0">
						  <img class="h-8 w-8 rounded-full" src="https://images.unsplash.com/photo-1519345182560-3f2917c472ef?ixlib=rb-1.2.1&ixid=eyJhcHBfaWQiOjEyMDd9&auto=format&fit=facearea&facepad=2&w=256&h=256&q=80" alt="">
						</div>
						-->
						<div class="flex-1 min-w-0">
						  <p class="text-sm font-medium text-gray-900 truncate">
							Order <?php echo $order->key. ' ($'.$order->get('ord_total_cost').')'; ?>
						  </p>
						  
						  <p class="text-sm text-gray-500 truncate">
							<?php echo  LibraryFunctions::convert_time($order->get('ord_timestamp'), 'UTC', $page_vars['session']->get_timezone(), 'M d, Y'); ?>
						  </p>
						  
						</div>
						<?php
						/*
						if($action){
						?>
						<div>
						  <?php echo $action; ?>
						</div>
						<?php
						}
						*/
						?>
					  </div>
					</li>
				<?php
				}
				?>
				  
                  </ul>
                </div>
                <div class="mt-6">
				<!--
					<a href="/product/recurring-donation" class="w-full flex justify-center items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
						See All Orders
					  </a>
					  -->
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





		<?php

		/*
			?>
				<h6 class="font-small font-weight-normal uppercase">Your Appointments</h6>

				<?php							
			$logic_path = LibraryFunctions::get_logic_file_path('get_appointments_logic.php', 'url');
			echo '
			<script>
			$(document).ready(function() {
				$("#appointments").load("'.$logic_path.'");
			});
			</script>
			<div style="margin-bottom: 20px;" id="appointments"></div>';
			*/

		/*
		if($page_vars['settings']->get_setting('messages_active')){
		?>
		<div class="sidebar-box">
			<h6 class="font-small font-weight-normal uppercase">Your Messages</h6>
										
			<?php
			$num_messages = 0;
			foreach($messages as $message){
				$num_messages++; 
				if($num_messages == 5){
					break;
				}
				?>
				<li><a href="/profile/messages"><strong><?php echo trim(str_replace('<br />', ' ', substr(strip_tags($message->get('msg_body')), 0, 50). '...')); ?></strong> <?php echo LibraryFunctions::convert_time($message->get('msg_sent_time'), 'UTC', $page_vars['session']->get_timezone()); ?></a></li>								
				<?php
			}
			?>	
						
		</div>
		<?php
		}
		*/
		

	echo PublicPageTW::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'show_survey'=>TRUE));
?>
