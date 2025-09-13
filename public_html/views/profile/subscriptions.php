<?php
	require_once(__DIR__ . '/../../includes/PathHelper.php');
	PathHelper::requireOnce('includes/Globalvars.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	PathHelper::requireOnce('includes/AdminPage.php');
	require_once(PathHelper::getThemeFilePath('subscriptions_logic.php', 'logic'));

	$page_vars = subscriptions_logic($_GET, $_POST);
	
	$page = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'My Profile', 
		'breadcrumbs' => array (
			'My Profile' => '',
			),
	);
	$page->public_header($hoptions,NULL);

	echo PublicPage::BeginPage('My Profile', $hoptions);
	

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
						foreach($page_vars['active_subscriptions'] as $subscription){	
								
							if($subscription->get('odi_subscription_cancelled_time')){
								$status = ' canceled on '. LibraryFunctions::convert_time($subscription->get('odi_subscription_cancelled_time'), 'UTC', $page_vars['session']->get_timezone());
								$action = '';
							}
							else{
								$status = 'active';
								if($subscription->get('odi_subscription_status')){
									$status = $subscription->get('odi_subscription_status');
								}
								$action = '<a href="/profile/subscription_edit?order_item='.$subscription->key. '">change</a><a class="inline-flex items-center shadow-sm px-2.5 py-0.5 border border-gray-300 text-sm leading-5 font-medium rounded-full text-gray-700 bg-white hover:bg-gray-50" href="/profile/orders_recurring_action?order_item_id='. $subscription->key . '">cancel</a>';
								
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
			// Note: This needs a URL path for AJAX loading, not a file include
			// TODO: Update this to use a proper AJAX endpoint
			$logic_path = '/logic/get_appointments_logic.php';
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
		

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'show_survey'=>TRUE));
?>
