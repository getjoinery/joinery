<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/PublicPage.php');
	require_once(LibraryFunctions::get_theme_includes_path().'/FormWriterPublic.php');
	require_once(LibraryFunctions::get_logic_file_path('profile_logic.php'));

	
	$page = new PublicPage();
	$hoptions = array(
		'title' => 'My Profile', 
	);
	$page->public_header($hoptions,NULL);
	echo PublicPage::BeginPage('My Profile');
	

		$display_messages = $session->get_messages($_SERVER['REQUEST_URI']);
		foreach($display_messages AS $display_message) {
			if($display_message->identifier == 'userbox') {			
				echo '<div class="'.$display_message->get_message_class().'">'.$display_message->message.'</div>';
			}
		}	
		
		if($settings->get_setting('events_active')){
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

		<div class="section padding-top-20">
			<div class="container">
				<div class="row col-spacing-50">
					<!-- Blog Posts -->
					<div class="col-12 col-lg-8">


					<?php
					if($settings->get_setting('events_active')){
						
						?>
						<ul class="nav nav-tabs margin-bottom-20">
						  <li class="nav-item">
							<a class="nav-link <?php if(!$_REQUEST['tab']){ echo 'active'; } ?>" href="/profile/profile">Active Events</a>
						  </li>
						  <li class="nav-item">
							<a class="nav-link <?php if($_REQUEST['tab'] == 'past'){ echo 'active'; } ?>" href="/profile/profile?tab=past">Past Events</a>
						  </li>
						  <li class="nav-item">
							<a class="nav-link <?php if($_REQUEST['tab'] == 'orders'){ echo 'active'; } ?>" href="/profile/profile?tab=orders">Order History</a>
						  </li>
						</ul>
						<?php
						
						if(!$_REQUEST['tab'] || $_REQUEST['tab'] == 'past'){
							$headers = array('Event', 'Actions');
							$page->tableheader($headers, "table cart-table");
							
							foreach($event_registrants as $event_registrant){
								$event = new Event($event_registrant->get('evr_evt_event_id'), TRUE);
								$next_session = $event->get_next_session();
								
								if(!$_REQUEST['events']){
									if($event->get('evt_status') != 1){
										continue;
									}
								}
								else{
									if($event->get('evt_status') == 1){
										continue;
									}								
								}

								
								$time = NULL;
								$tz = $event->get('evt_timezone');
								if($next_session){
									$time = '<b>Next session: ';
									$time .= $next_session->get_time_string($tz);
									
									if($event->get('evt_timezone') != $session->get_timezone()){
										$time .= ' (Your local time: '. $next_session->get_time_string($session->get_timezone()). ')';
									}
									$time .= '</b>';
								}
								else if($event->get('evt_status') != 2 && $event->get('evt_status') != 3){

									$time = $event->get_time_string($tz);		
									if($event->get('evt_timezone') != $session->get_timezone()){
										$time .= ' (Your local time: '. $event->get_time_string($session->get_timezone()). ')';			
									}				
								}
								
								$calendar_text = '';
								if($event->get('evt_status') != 2 && $event->get('evt_status') != 3){
									$calendar_links = $event->get_add_to_calendar_links();
									if($calendar_links){
										if($time){
											$calendar_text .= '<br>';
										}
										$calendar_text .= 'Add to calendar: <a href="'.$calendar_links['google'].'">google</a> | ';
										$calendar_text .= '<a href="'.$calendar_links['yahoo'].'">yahoo</a> | ';
										$calendar_text .= '<a href="'.$calendar_links['outlook'].'">outlook</a> | ';
										$calendar_text .= '<a href="'.$calendar_links['ics'].'">ical</a> ';
									}
								}
								
								
								$rowvalues = array();
								if($event->get('evt_session_display_type')==2){
									array_push($rowvalues, '<h6><a href="/profile/event_sessions_course?event_id='.$event->key.'">'.$event->get('evt_name').'</a></h6>'. $time. $calendar_text);
								}
								else{
									array_push($rowvalues, '<h6><a href="/profile/event_sessions?evt_event_id='.$event->key.'">'.$event->get('evt_name').'</a></h6>'. $time. $calendar_text);
								}
								

								$actions = '';
								if(!$event_registrant->get('evr_extra_info_completed') && $event->get('evt_collect_extra_info') && $event->get('evt_status') == 1){
									$act_code = Activation::CheckForActiveCode($user->key, Activation::EMAIL_VERIFY);
									$actions .= '<a href="/profile/event_register_finish?act_code='.$act_code->act_code.'&userid='.$user->key.'&eventregistrantid='.$event_registrant->key.'">Additional information needed</a>';
								}

								if($event->get('evt_end_time') > date('Y-m-d H:i:s')){
									$actions .= '<a class="button-circle button-circle-md button-circle-grey" href="/profile/event_withdraw?evr_event_registrant_id='.$event_registrant->key.'" alt="Withdraw from course"><i class="ti-close"></i>';
								}
								array_push($rowvalues, $actions); 
								$page->disprow($rowvalues);
							}
							$page->endtable();	
						}
						else{
							if($settings->get_setting('products_active')){
								
								//ORDERS
								$numperpage = 60;
								$conoffset = LibraryFunctions::fetch_variable('conoffset', 0, 0, '');
								$consort = LibraryFunctions::fetch_variable('consort', 'ord_order_id', 0, '');	
								$consdirection = LibraryFunctions::fetch_variable('consdirection', 'DESC', 0, '');
								$search_criteria = NULL;
								
								$search_criteria = array();
								$search_criteria['user_id'] = $session->get_user_id();
								

								$orders = new MultiOrder(
									$search_criteria,
									array($consort=>$consdirection),
									$numperpage,
									$conoffset);
								$numrecords = $orders->count_all();
								$orders->load();


								$headers = array('Products', 'Order Total', 'Order Time' );
								$page->tableheader($headers, "table cart-table");

								
								foreach($orders as $order) {
									$rowvalues = array();
									if($order->get('ord_usr_user_id')){
										$order_user = new User($order->get('ord_usr_user_id'), TRUE);
									}
									else{
										$order_user = new User(NULL);
									}
									
									$min_status = NULL;

									$order_items = $order->get_order_items();
									$order_items_out = array();
									$product_versions_out = array();
									foreach($order_items as $order_item) {
										$product = new Product($order_item->get('odi_pro_product_id'), TRUE);
										

										if (array_key_exists($order_item->get('odi_pro_product_id'), $PRODUCT_ID_TO_NAME_CACHE)) {
											$title = $PRODUCT_ID_TO_NAME_CACHE[$order_item->get('odi_pro_product_id')];
										} else {
											//$product = new Product($order_item->get('odi_pro_product_id'), TRUE);
											$title = $product->get('pro_name');
											$PRODUCT_ID_TO_NAME_CACHE[$product->key] = $title;
										} 
													
										$product_version = $order_item->get_product_version($product->get('pro_product_id'));
										if($product_version){
											$product_versions_out[$order_item->key] = '('. $product_version->prv_version_name . ' - $' . $product_version->prv_version_price . ')<br>';
										}
										
										$extra_info_link = array();
										if($product->get('pro_evt_event_id')){
											//IT'S AN EVENT
											$event_registrants = new MultiEventRegistrant(array('event_id' => $product->get('pro_evt_event_id'), 'user_id' => $user->key), NULL);
											$found = $event_registrants->count_all();
											$event_registrants->load();

											if($found){	
												$event_registrant = $event_registrants->get(0);				
												if(!$event_registrant->get('evr_extra_info_completed') && $event->get('evt_collect_extra_info') && $event->get('evt_status') == 1){
													$extra_info_link[$order_item->key] = ' <a href="/profile/event_register_finish?eventregistrantid=' . $event_registrant->key . '">[info needed]</a>';
												}	
											}					
										}

										$order_items_out[$order_item->key] = $title;

									}
									
									$outtext = '';
									foreach ($order_items_out as $num => $item){
										$outtext .= $item. $product_versions_out[$num].$extra_info_link[$num];
									}
									

									//array_push($rowvalues, $order->key);
									array_push($rowvalues, $outtext);
									array_push($rowvalues, '$'.$order->get('ord_total_cost'));
									array_push($rowvalues,  LibraryFunctions::convert_time($order->get('ord_timestamp'), 'UTC', $session->get_timezone()));


									//array_push($rowvalues, '<a href="/profile/order_refund?stripe_pi=' . $order->get('ord_stripe_payment_intent_id'). '">refund this order</a>');
									$page->disprow($rowvalues);
								}
								$page->endtable();	
							}							
							
						}
					}	
					?>


					</div>
					<!-- end Blog Posts -->

					<!-- Blog Sidebar -->
					<div class="col-12 col-lg-4 sidebar-wrapper">
						<!-- Sidebar box 1 - About me -->
						<div class="sidebar-box">
							<div class="text-center">
								<h6 class="font-small font-weight-normal uppercase">My Info (<a href="/profile/account_edit" alt="edit account">edit</a>)</h6>
								
								
								<!--<img class="img-circle-md margin-bottom-20" src="../assets/images/img-circle-medium.jpg" alt="">
								<p>Aenean massa. Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.</p>-->
							</div>
							<ul class="list-category">
								<li><a href="/profile/account_edit">Name <span><?php echo htmlspecialchars($user->display_name()); ?></span></a></li>
								<li><a href="/profile/account_edit">Email <span><?php echo htmlspecialchars($user->get('usr_email')); ?></span></a></li>
								<li><a href="/profile/account_edit">Timezone <span><?php echo $user->get('usr_timezone'); ?></span></a></li>
								<li><a href="/profile/account_edit">Address <span><?php echo $address->get_address_string(', '); ?></span></a></li>
								<li><a href="/profile/account_edit">Phone <span><?php echo $phone_number->get_phone_string(); ?></span></a></li>
								<li><a href="/profile/contact_preferences">Newsletter <span><?php 
								if($user->get('usr_contact_preferences')) {
									echo 'Unsubscribe';
								}
								else{
									echo 'Subscribe';
								}
								?>
								</span></a></li>
							</ul>
						</div>
						
						<?php
						if($settings->get_setting('products_active')){
							$logic_path = LibraryFunctions::get_logic_file_path('get_subscriptions_logic.php', 'url');
							echo '
							<script>
							$(document).ready(function() {
								$("#subscriptions").load("'.$logic_path.'");
							});
							</script>
							<div style="margin-bottom: 20px;" id="subscriptions"></div>';
						}
						?>

						<?php
						if($settings->get_setting('products_active')){
							$logic_path = LibraryFunctions::get_logic_file_path('get_appointments_logic.php', 'url');
							echo '
							<script>
							$(document).ready(function() {
								$("#appointments").load("'.$logic_path.'");
							});
							</script>
							<div style="margin-bottom: 20px;" id="appointments"></div>';
						}
						?>

						
						<!-- Sidebar box 3 - Popular Posts -->
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
								<li><a href="/profile/messages"><strong><?php echo trim(str_replace('<br />', ' ', substr(strip_tags($message->get('msg_body')), 0, 50). '...')); ?></strong> <?php echo LibraryFunctions::convert_time($message->get('msg_sent_time'), 'UTC', $session->get_timezone()); ?></a></li>								
								<?php
							}
							?>	
										
						</div>

						
					</div>
					<!-- end Blog Sidebar -->
				</div><!-- end row -->
			</div><!-- end container -->
		</div>
		
		<?php
	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'show_survey'=>TRUE));
?>
