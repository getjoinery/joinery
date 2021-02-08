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
	echo 'My Profile<br />';
	echo PublicPage::BeginPage('My Profile');
	
	?>
	<p><a class="et_pb_button" href="/profile/account_edit">Edit Account Info</a> 
	<a class="et_pb_button" href="/profile/password_edit">Change Password</a> 
	<a class="et_pb_button" href="/profile/contact_preferences">Set Contact Preferences</a></p>
	<?php	
		$display_messages = $session->get_messages($_SERVER['REQUEST_URI']);
		foreach($display_messages AS $display_message) {
			if($display_message->identifier == 'userbox') {			
				echo '<div class="'.$display_message->get_message_class().'">'.$display_message->message.'</div>';
			}
		}	
		
		$settings = Globalvars::get_instance();
		if($settings->get_setting('events_active')){
			//DISPLAY REGISTER FINISH LINKS FOR ANY EVENTS
			$event_registrants = new MultiEventRegistrant(array('user_id' => $user->key), NULL);
			$event_registrants->load();
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

                
            	<p>
                	Name: <b><?php echo htmlspecialchars($user->display_name()); ?></b><br />
                    Email: <b><?php echo htmlspecialchars($user->get('usr_email')); ?></b><br />
                    Time Zone: <b><?php echo $user->get('usr_timezone'); ?></b><br />

					<?php
					$count = 0;					
					foreach($addresses as $address){
						$count++;
						if($count == 1){
							echo 'Address: <b>';
						}
						echo '' . $address->get_address_string(', ') . '&nbsp;';
						/*
						if($address->get_privacy_status() == 'Private' || $address->get_privacy_status() == 'Integral Zen Only') {
							echo '(Private)';
						}
						*/
						/*
						if($address->get('usa_is_default')) {
							echo '<span>Default</span>';
						}
						*/
						/*
						if(!$address->get('usa_is_verified')) {
							echo '<a href="/profile/improvements?box=background">Verify</a>';
						}
						*/
						echo '</b><br />';												
					}
					
					$count = 0;					
					foreach($phone_numbers as $phone_number){
						$count++;
						if($count == 1) {
							echo 'Phone: <b>';
						}
						echo '' . $phone_number->get_phone_string() . '&nbsp;';
						/*
						if($phone_number->get('phn_is_private')) {
							echo '(Private)';
						}
						*/
						/*
						if($phone_number->get('phn_is_default')) {
							echo '<span>Default</span>';
						}
						*/
						/*
						if(!$phone_number->get('phn_is_verified')) {
							echo '<a href="/profile/account_edit">Verify</a>';
						}
						*/
						echo '</b><br />';						
					}
					?>
                </p>

            	
    
	<script>
	//<![CDATA[

	$(document).ready(function() {
		
		
			$('.clickshow').click(function() {
				var thisid = $(this).attr('id');
				var newid = '#messagebox' + thisid.replace('clickshow','');
				$(newid).toggle(500);
				return false;
			});

	});
	//]]>
		</script>

	<?php

	echo '<h2>Your messages</h2>';
	$headers = array("Message", "Time");
	$page->tableheader($headers, "admin_table");

	$num_messages = 0;
	foreach($messages as $message){
		$num_messages++; 
		if($num_messages == 5){
			break;
		}
		$rowvalues=array();
		$introtext = trim(str_replace('<br />', ' ', substr(strip_tags($message->get('msg_body')), 0, 100). '...'));
		$fulltext = $message->get('msg_body');
		
		$text_container = '<a class="clickshow" id="clickshow'.$message->key.'" href="#">'.$introtext.'</a><div id="messagebox'.$message->key.'" style="display:none; margin: 20px;"><br />'.$fulltext.'</div>';
		
		array_push($rowvalues, $text_container);
		array_push($rowvalues, LibraryFunctions::convert_time($message->get('msg_sent_time'), 'UTC', $session->get_timezone()));
        $page->disprow($rowvalues);
	}

	$page->endtable();		


	$logic_path = LibraryFunctions::get_logic_file_path('get_appointments_logic.php', 'url');
	?>
	<script>
	$(document).ready(function() {

		$("#appointments").load("<?php echo $logic_path; ?>");

	});
    </script>
	
	<div id="appointments"></div>	
	<?php
	
	
	if($settings->get_setting('events_active')){
		$event_registrants = new MultiEventRegistrant(array('user_id' => $user->key), array('event_id'=> 'DESC'));
		$event_registrants->load();

		echo '<h2>Event registrations</h2>';
		$headers = array('Event', 'Status', 'Actions');
		$page->tableheader($headers, "admin_table");

		
		foreach($event_registrants as $event_registrant){
			$event = new Event($event_registrant->get('evr_evt_event_id'), TRUE);
			$next_session = $event->get_next_session();

			
			$time = NULL;
			
			$tz = $event->get('evt_timezone');


			if($next_session){
				$time = '<b>Next session: ';
				$time .= $next_session->get_time_string($tz);
				
				if($event->get('evt_timezone') != $session->get_timezone()){
					$time .= ' (Your local time: '. $next_session->get_time_string($session->get_timezone()). ')';
				}
				echo '</b>';
			}
			else if($event->get('evt_status') != 2 && $event->get('evt_status') != 3){

				$time = $event->get_time_string($tz);		
				if($event->get('evt_timezone') != $session->get_timezone()){
					$time .= ' (Your local time: '. $event->get_time_string($session->get_timezone()). ')';			
				}				
			}
			
			
			$rowvalues = array();
			if($event->get('evt_session_display_type')==2){
				array_push($rowvalues, '<a href="/profile/event_sessions_course?event_id='.$event->key.'">'.$event->get('evt_name').'</a><br />'. $time);
			}
			else{
				array_push($rowvalues, '<a href="/profile/event_sessions?evt_event_id='.$event->key.'">'.$event->get('evt_name').'</a><br />'. $time);
			}
			
		
			
			if($event->get('evt_status') == 1){
				array_push($rowvalues,  'Upcoming/In process'); 
			}
			else if($event->get('evt_status') == 2){
				array_push($rowvalues,  'Completed'); 
			}
			else{
				array_push($rowvalues,  'Cancelled'); 
			}
			
			
			$actions = '';
			if(!$event_registrant->get('evr_extra_info_completed') && $event->get('evt_collect_extra_info') && $event->get('evt_status') == 1){
				$act_code = Activation::CheckForActiveCode($user->key, Activation::EMAIL_VERIFY);
				$actions .= '<a href="/profile/event_register_finish?act_code='.$act_code->act_code.'&userid='.$user->key.'&eventregistrantid='.$event_registrant->key.'">Additional information needed</a>';
			}

			if($event->get('evt_end_time') > date('Y-m-d H:i:s')){
				$actions .= '<a class="sortlink" href="/profile/event_withdraw?evr_event_registrant_id='.$event_registrant->key.'">Withdraw from course</a>';
			}
			array_push($rowvalues, $actions); 
			$page->disprow($rowvalues);
		}
		$page->endtable();	
	}		
	
	if($settings->get_setting('products_active')){
		$logic_path = LibraryFunctions::get_logic_file_path('get_subscriptions_logic.php', 'url');
		?>
		<script>
		$(document).ready(function() {

			$("#subscriptions").load("<?php echo $logic_path; ?>");

		});
		</script>
		<div id="subscriptions"></div>

		
		<p><a class="et_pb_button" href="/product?product_id=3">Start a new recurring donation</a></p>
		
		<?php
		
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

		echo '<h2>Orders</h2>';

		$headers = array('Products', 'Order Total', 'Order Time' );
		$page->tableheader($headers, "admin_table");

		
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

	echo PublicPage::EndPage();
	$page->public_footer($foptions=array('track'=>TRUE, 'show_survey'=>TRUE));
?>
