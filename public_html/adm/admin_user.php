<?php

	PathHelper::requireOnce('/includes/Activation.php');
	// ErrorHandler.php no longer needed - using new ErrorManager system
	
	PathHelper::requireOnce('/includes/AdminPage.php');

	PathHelper::requireOnce('/data/users_class.php');
	PathHelper::requireOnce('/data/phone_number_class.php');
	PathHelper::requireOnce('/data/address_class.php');
	PathHelper::requireOnce('/data/log_form_errors_class.php');
	PathHelper::requireOnce('/data/emails_class.php');
	PathHelper::requireOnce('/data/email_recipients_class.php');
	PathHelper::requireOnce('/data/events_class.php');
	PathHelper::requireOnce('/data/event_logs_class.php');
	PathHelper::requireOnce('/data/event_sessions_class.php');
	PathHelper::requireOnce('/data/orders_class.php');
	PathHelper::requireOnce('/data/products_class.php');
	PathHelper::requireOnce('/data/product_details_class.php');
	
	PathHelper::requireOnce('/data/groups_class.php');
	PathHelper::requireOnce('/data/group_members_class.php');
	PathHelper::requireOnce('/data/mailing_lists_class.php');
	
	$settings = Globalvars::get_instance();
	$composer_dir = $settings->get_setting('composerAutoLoad');	
	require_once $composer_dir.'autoload.php';	

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$user = new User($_GET['usr_user_id'], TRUE);
	include(PathHelper::getAbsolutePath('/utils/registrant_maintenance.php')); 
	include(PathHelper::getAbsolutePath('/utils/order_maintenance.php'));

	if($_REQUEST['action'] == 'delete'){
		$user->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$user->soft_delete();

		header("Location: /admin/admin_users");
		exit();				
	}
	else if($_REQUEST['action'] == 'undelete'){
		$user->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$user->soft_delete();

		header("Location: /admin/admin_user?usr_user_id=".$user->key);
		exit();				
	}

	if($_POST){ 

		if($_POST['action'] == 'add_to_group'){
			//ADD THE USER TO A GROUP
			$group = new Group($_POST['grp_group_id'], TRUE);
			$groupmember = $group->add_member($user->key);
			header("Location: /admin/admin_user?usr_user_id=".$user->key);
			exit();			
		}
		else if($_POST['action'] == 'remove_from_group'){
			$groupmember = new GroupMember($_POST['grm_group_member_id'], TRUE);
			$groupmember->remove();
			header("Location: /admin/admin_user?usr_user_id=".$user->key);
			exit();				
		}
		else if($_POST['action'] == 'add_to_event'){
			//ADD THE USER TO AN EVENT
			$event = new Event($_POST['evt_event_id'], TRUE);
			$event->add_registrant($user->key);
			header("Location: /admin/admin_user?usr_user_id=".$user->key);
			exit();			
		}
		else if($_POST['action'] == 'remove_from_event'){
			$event = new Event($_POST['evt_event_id'], TRUE);
			$event->remove_registrant($user->key);
			header("Location: /admin/admin_user?usr_user_id=".$user->key);
			exit();					
		}	

	}

	$phone_numbers = new MultiPhoneNumber(
		array('user_id'=>$user->key),
		NULL,
		30,
		0);
	$phone_numbers->load();
	$numphonerecords = $phone_numbers->count_all();

	$addresses = new MultiAddress(
		array('user_id'=>$user->key),
		NULL,
		30,
		0);
	$numaddressrecords = $addresses->count_all();
	$addresses->load();

	/*
	$form_errors = new MultiFormError(
		array('user_id'=>$user->key),
		NULL,
		10,
		0);
	$form_errors->load();
	*/

	$search_criteria = array();
	$search_criteria['user_id'] = $user->key;
	//$search_criteria['deleted'] = FALSE;

	$orders = new MultiOrder(
		$search_criteria,
		array('ord_order_id'=>'DESC'),
		NULL,
		NULL);
	$orders->load();
	$numorders = $orders->count_all();	

	$searches['user_id'] = $user->key;
	$event_registrations = new MultiEventRegistrant(
		$searches,
		NULL, //array('event_id'=>'DESC'),
		NULL,
		NULL);
	$event_registrations->load();	
	$numeventsregistrations = $event_registrations->count_all();	
	
	//SUBSCRIPTIONS
	$active_subscriptions = new MultiOrderItem(
	array('user_id' => $user->key, 'is_active_subscription' => true), //SEARCH CRITERIA
	array('order_item_id' => 'DESC'),  // SORT, SORT DIRECTION
	15, //NUMBER PER PAGE
	NULL //OFFSET
	);
	$active_subscriptions->load();	

	//SUBSCRIPTIONS
	$cancelled_subscriptions = new MultiOrderItem(
	array('user_id' => $user->key, 'is_cancelled_subscription' => true), //SEARCH CRITERIA
	array('order_item_id' => 'DESC'),  // SORT, SORT DIRECTION
	15, //NUMBER PER PAGE
	NULL //OFFSET
	);
	$cancelled_subscriptions->load();	
	/*
	$search_criteria = NULL;
	$search_criteria['user_id'] = $user->key;

	$details = new MultiProductDetail(
		$search_criteria,
		array('product_detail_id'=>'DESC'),
		NULL,
		NULL);
	$numrecords = $details->count_all();
	$details->load();
	*/

/*
	$phonereveals = new MultiEventLog(
		array('user_id'=>$user->key, 'event' => EventLog::SHOW_PHONE)
		);
	$numphonereveal = $phonereveals->count_all();

	$websiteclick = new MultiEventLog(
		array('user_id'=>$user->key, 'event' => EventLog::WEBSITE_CLICK)
		);
	$numwebsiteclick = $websiteclick->count_all();
*/

	$dbhelper = DbConnector::get_instance();
	$dblink = $dbhelper->get_db_link();
	// Get activation entries for user
	/*
	$sql_activations = "SELECT * FROM act_activation_codes WHERE (act_usr_email ILIKE :usr_email OR act_usr_user_id = :usr_user_id) AND (act_purpose = 2 OR  act_purpose = 3)";

	try
	{
		$q = $dblink->prepare($sql_activations);
		$q->bindParam(':usr_email', $user->get('usr_email'), PDO::PARAM_STR);
		$q->bindParam(':usr_user_id', $user->key, PDO::PARAM_INT);
		$count = $q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);
	}
	catch(PDOException $e){
		$dbhelper->handle_query_error($e);
	}

	$activations = $q->fetchAll();

	*/

	$sql = 'SELECT * FROM log_logins WHERE log_usr_user_id='.$user->key.' ORDER BY log_login_time DESC LIMIT 10';

	try{
		$q = $dblink->prepare($sql);
		$count = $q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);
	}
	catch(PDOException $e){
		$dbhelper->handle_query_error($e);
	}
	$logins = $q->fetchAll();

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'users-list',
		'page_title' => 'User',
		'readable_title' => 'User',
		'breadcrumbs' => array(
			'Users'=>'/admin/admin_users', 
			$user->display_name() => '',
		),
		'session' => $session,
	)
	);

	$settings = Globalvars::get_instance();
	$webDir = $settings->get_setting('webDir');

		$options['title'] = $user->display_name() . ' (' . $user->key . ')';
		
		if(!$user->get('usr_delete_time')) {
			if($_SESSION['permission'] > 7){
				$options['altlinks']['Edit User'] = '/admin/admin_users_edit?usr_user_id='.$user->key;
				if($settings->get_setting('checkout_type')){
					$options['altlinks']['Payment Methods'] = '/admin/admin_user_payment_methods?usr_user_id='.$user->key;
				}
				if(!$user->get('usr_email_is_verified')){
					$options['altlinks']['Resend activation email'] = '/admin/admin_email_verify?usr_user_id='.$user->key; 
				}
				$options['altlinks']['Send email to user'] = '/admin/admin_users_message?usr_user_id='.$user->key;

				$options['altlinks']['Change password'] = '/admin/admin_users_password_edit?usr_user_id='.$user->key;
				$options['altlinks']['Soft Delete'] = '/admin/admin_user?action=delete&usr_user_id='.$user->key;

				if(!$user->get('usr_is_activated')) {
					$options['altlinks']['Activate User'] = '/admin/admin_activate?usr_user_id='.$user->key;
				}
				if ($_SESSION['permission'] == 10) {
					$options['altlinks']['Log in as user'] = '/admin/admin_user_login_as?usr_user_id='.$user->key;
				}

			}
		} 
		else {
			$options['altlinks']['Undelete'] = '/admin/admin_users_undelete?usr_user_id='.$user->key;
		}	
		if ($_SESSION['permission'] == 10) {
			$options['altlinks']['Permanent Delete'] = '/admin/admin_users_permanent_delete?usr_user_id='.$user->key;
		}		

		$page->begin_box($options);
	?>

          <!-- Profile Image -->
              <!--<img class="profile-user-img rounded-circle img-fluid mx-auto d-block" src="../../../images/5.jpg" alt="User profile picture">-->
			<?php
			//if ($event->get('evt_picture_link')) {
				//echo '<img src="' .  $event->get('evt_picture_link') . '" alt="' . htmlspecialchars($event->get('evt_name'), ENT_QUOTES) . '" width="450" />';
			//}
			?>	
			
				<p class="text-center">
				<?php
				echo 'Email address:  <strong>'.$user->get('usr_email').'</strong> ';
				if($user->get('usr_email_is_verified')) {
					echo ' <b>Verified</b>';
				}
				else{
					echo ' <b>Unverified</b>';
				}	

				$user_subscribed_list = array();
				$search_criteria = array('deleted' => false, 'user_id' => $user->key);
				$user_lists = new MultiMailingListRegistrant(
					$search_criteria);	
				$user_lists->load();
				
				foreach ($user_lists as $user_list){
					$mailing_list = new MailingList($user_list->get('mlr_mlt_mailing_list_id'), TRUE);
					$user_subscribed_list[] = $mailing_list->get('mlt_name');
				}	

				echo '<br>';
				if(!empty($user_subscribed_list)){
					echo 'This user is subscribed to the following lists: '.implode(', ', $user_subscribed_list).'<br>';
				}

				/*
				if($user->get('usr_contact_preferences_last_changed')) {
					echo ', last change:  '. LibraryFunctions::convert_time($event_registration->get('usr_contact_preferences_last_changed'), 'UTC', $session->get_timezone());
				}	
				*/				
				?>
				</p>

              <p class="text-center"><?php echo 'Signed up: '.LibraryFunctions::convert_time($user->get('usr_signup_date'), 'UTC', $session->get_timezone(), 'M j, Y'); ?></p>
			  
			  <p class="text-center">
			  <?php
				if($user->get('usr_delete_time')){
					echo 'Status: Deleted at '.LibraryFunctions::convert_time($user->get('usr_delete_time'), 'UTC', $session->get_timezone()).'<br />';
				}
				if($user->get('usr_is_admin_disabled')) {
					echo 'Admin Disabled (' . $user->get('usr_admin_disabled_comment') .')';
				} else if($user->get('usr_is_disabled')) {
					echo 'Disabled';
				}
				else {	

					echo '<h4>Active Subscriptions</h4>';
					foreach($active_subscriptions as $subscription){
						$stripe_helper = new StripeHelper();						
						$stripe_helper->update_subscription_in_order_item($subscription);
						$status_words = 'active';
						if($subscription->get('odi_subscription_status')){
							$status_words = $subscription->get('odi_subscription_status');
						}
						
						$status = '<a href="/admin/admin_order?ord_order_id='.$subscription->get('odi_ord_order_id').'">Order '.$subscription->get('odi_ord_order_id').'</a> $'.$subscription->get('odi_price') .'/month, Status: '.$status_words;

						if($subscription->get('odi_subscription_period_end')){
							$status .= ' period ends on '.LibraryFunctions::convert_time($subscription->get('odi_subscription_period_end'), 'UTC', $session->get_timezone());
						}
						
						$status .= ' <a href="/profile/orders_recurring_action?order_item_id='. $subscription->key . '">cancel</a>';
						
						?><span><?php echo $status; ?></span><br />
						<?php
					}

					echo '<h4>Cancelled Subscriptions</h4>';
					foreach($cancelled_subscriptions as $subscription){	
							
						$status = '<a href="/admin/admin_order?ord_order_id='.$subscription->get('odi_ord_order_id').'">Order '.$subscription->get('odi_ord_order_id').'</a> $'.$subscription->get('odi_price') .'/month canceled on '. LibraryFunctions::convert_time($subscription->get('odi_subscription_cancelled_time'), 'UTC', $session->get_timezone());
						
						if($subscription->get('odi_subscription_period_end')){
							$status .= ' last day is '.LibraryFunctions::convert_time($subscription->get('odi_subscription_period_end'), 'UTC', $session->get_timezone());
						}
						?><span><?php echo $status; ?></span><br />
						<?php
					}
				
				}		
				?>
				</p>

						<p class="text-center">
						<?php
						if($numphonerecords){
							foreach($phone_numbers as $phone_number)	 {
								echo 'Phone: '.$phone_number->get_phone_string() . ' [<a class="sortlink" href="/admin/admin_phone_edit?phn_phone_number_id='. $phone_number->key. '&usr_user_id='. $user->key . '">edit</a>]<br />';
							}
						}
						else{
							echo ' [<a class="sortlink" href="/admin/admin_phone_edit?usr_user_id='. $user->key . '">Add Phone Number</a>]<br />';
						}
						?>
						</p> 

						<p class="text-center">
						<?php
						if($numaddressrecords){
							foreach($addresses as $address) {

								echo 'Address: '.$address->get_address_string(' ') . ' [<a class="sortlink" href="/admin/admin_address_edit?usa_address_id='. $address->key .'">edit</a>]<br />' ;

								$page->disprow($rowvalues);
							}
						}
						else{
							echo ' [<a class="sortlink" href="/admin/admin_address_edit?usr_user_id='. $user->key . '">Add address</a>]<br />';
						}
						
						echo '<br />Timezone: '.$user->get('usr_timezone'). ' [<a href="/admin/admin_users_edit?usr_user_id='.$user->key.'">edit</a>]';
						?>
						</p>

	<?php $page->end_box(); ?>
<?php

	$headers = array("Event", "Added", "Expires", "Action");
	$altlinks = array();
	$box_vars =	array(
		'altlinks' => $altlinks,
		'title' => "Events"
	);
	$page->tableheader($headers, $box_vars);

	$event_ids_for_user = array();
	foreach ($event_registrations as $event_registration){
		$event = new Event($event_registration->get('evr_evt_event_id'), TRUE);	 
		$event_ids_for_user[] = $event->key;
		$rowvalues = array();

		array_push($rowvalues, '<a href="/admin/admin_event?evt_event_id='.$event->key.'">'.LibraryFunctions::convert_time($event->get('evt_start_time'), "UTC", "UTC", 'M j, Y') . ' <strong>'.$event->getString('evt_name', 50). '</strong> '. $event->get('evt_location').'</a>');

		array_push($rowvalues, LibraryFunctions::convert_time($event_registration->get('evr_create_time'), 'UTC', $session->get_timezone()));
		array_push($rowvalues, LibraryFunctions::convert_time($event_registration->get('evr_expires_time'), 'UTC', $session->get_timezone()));
		$delform = '<form id="form2" class="form2" name="form2" method="POST" action="/admin/admin_user?usr_user_id='.$user->key.'">
		<input type="hidden" class="hidden" name="action" id="action" value="remove_from_event" />
		<input type="hidden" class="hidden" name="evt_event_id" id="evt_event_id" value="'.$event->key.'" />
		<button type="submit">Remove</button>
		</form>';
		array_push($rowvalues, $delform);			
		
		$page->disprow($rowvalues);
	}
	
	echo '<tr><td colspan="4">';
	$formwriter = LibraryFunctions::get_formwriter_object('form3', 'admin');
	$validation_rules = array();
	$validation_rules['evt_event_id']['required']['value'] = 'true';
	echo $formwriter->set_validate($validation_rules);
	echo $formwriter->begin_form('form2', 'POST', '/admin/admin_user?usr_user_id='. $user->key);
	
	$events = new MultiEvent(
		array('deleted'=>false),
		array('start_time'=>'DESC'),		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$events->load();
	
	foreach($event_ids_for_user as $event_id) {
		if($events->contains_key($event_id)){
			$events->remove_by_key($event_id);
		}
	}
	
	$optionvals = $events->get_dropdown_array();
	echo $formwriter->hiddeninput('action', 'add_to_event');
	echo $formwriter->hiddeninput('usr_user_id', $user->key);
	echo $formwriter->dropinput("Add to event", "evt_event_id", "ctrlHolder", $optionvals, NULL, '', TRUE);
	echo $formwriter->new_form_button('Add');
	echo $formwriter->end_form();	
	echo '</td></tr>';		
	
	$page->endtable(); 

	$groups = Group::get_groups_for_member($user->key, 'user', false, 'objects');

	$headers = array("Group", "Action");
	$altlinks = array();
	$box_vars =	array(
		'altlinks' => $altlinks,
		'title' => "Groups"
	);
	$page->tableheader($headers, $box_vars);

    foreach($groups as $group) {
		$groupmember = $group->is_member_in_group($user->key);
		
		$rowvalues = array();
		array_push($rowvalues, $group->get('grp_name'));
		$delform = '<form id="form4" class="form4" name="form4" method="POST" action="/admin/admin_user?usr_user_id='. $user->key.'">
		<input type="hidden" class="hidden" name="action" id="action" value="remove_from_group" />
		<input type="hidden" class="hidden" name="grm_group_member_id" id="grm_group_member_id" value="'.$groupmember->key.'" />
		<button type="submit">Remove</button>
		</form>';
		array_push($rowvalues, $delform);	
		
		$page->disprow($rowvalues);
	}
	
	echo '<tr><td colspan="2">';
	$formwriter = LibraryFunctions::get_formwriter_object('form5', 'admin');

	$validation_rules = array();
	$validation_rules['grp_group_id']['required']['value'] = 'true';
	echo $formwriter->set_validate($validation_rules);	
	echo $formwriter->begin_form('form5', 'POST', '/admin/admin_user?usr_user_id='. $user->key);
	
	$group_drops = new MultiGroup(
		array('category'=>'user'),  //SEARCH 
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$group_drops->load();

	foreach($groups as $group) {
		if($group_drops->contains_key($group->key)){
			$group_drops->remove_by_key($group->key);
		}
	}
	
	$optionvals = $group_drops->get_dropdown_array();
	echo $formwriter->hiddeninput('action', 'add_to_group');
	echo $formwriter->hiddeninput('usr_user_id', $user->key);
	echo $formwriter->dropinput("Add to group", "grp_group_id", "ctrlHolder", $optionvals, NULL, '', TRUE);
	echo $formwriter->new_form_button('Add');
	echo $formwriter->end_form();	
	echo '</td></tr>';	

	$page->endtable();

	//VIEW STATS

	$headers = array("Session", "Last Viewed", "# Views");
	$altlinks = array();
	$box_vars =	array(
		'altlinks' => $altlinks,
		'title' => "Session Visits"
	);
	$page->tableheader($headers, $box_vars);

	foreach ($event_registrations as $event_registration){
		$event = new Event($event_registration->get('evr_evt_event_id'), TRUE);	
		$searches = array();
		$searches['event_id'] = $event_registration->get('evr_evt_event_id');
		$event_sessions = new MultiEventSessions(
			$searches,
			array('evs_session_number' => 'DESC', 'evs_title' => 'DESC'));
		$event_sessions->load();
		
		foreach ($event_sessions as $event_session){
			$rowvalues = array();
			
			if($visit_time = $event_session->get_last_visited_time_for_user($user->key)){
				if($event_session->get('evs_session_number')){
					$session_num = 'Session '.$event_session->get('evs_session_number'). ' - ';
				}
				else{
					$session_num = '';
				}
				array_push($rowvalues, $event->get('evt_name') . ' - '. $session_num . $event_session->get('evs_title'));
				array_push($rowvalues, LibraryFunctions::convert_time($visit_time, 'UTC', $session->get_timezone()));
				array_push($rowvalues, $event_session->get_number_visits_for_user($user->key));
			
			}
			$page->disprow($rowvalues);
		}
	}

	$page->endtable(); 

	$PRODUCT_ID_TO_NAME_CACHE = array();

	$headers = array('Order ID', 'Order Time', 'Products', 'Total');
	$altlinks = array();
	$box_vars =	array(
		'altlinks' => $altlinks,
		'title' => "Orders"
	);
	$page->tableheader($headers, $box_vars);

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
		foreach($order_items as $order_item) {

			if (array_key_exists($order_item->get('odi_pro_product_id'), $PRODUCT_ID_TO_NAME_CACHE)) {
				$title = $PRODUCT_ID_TO_NAME_CACHE[$order_item->get('odi_pro_product_id')];
			} else {
				$product = new Product($order_item->get('odi_pro_product_id'), TRUE);
				$title = $product->get('pro_name');
				$PRODUCT_ID_TO_NAME_CACHE[$product->key] = $title;
			}

			$this_out = $title . ' ($'. $order_item->get('odi_price') .')';

			if($order_item->get('odi_subscription_cancelled_time')){
				$status_words = 'canceled';
				if($order_item->get('odi_subscription_status')){
					$status_words = $order_item->get('odi_subscription_status');
				}
				$this_out .= ' '. $status_words. ' at '.LibraryFunctions::convert_time($order_item->get('odi_subscription_cancelled_time'), 'UTC', $session->get_timezone());	
			}
			else if($order_item->get('odi_subscription_status')){
				$this_out .=  ' STATUS: '. $order_item->get('odi_subscription_status');
			}	
			
			$order_items_out[] = $this_out;

		}

		array_push($rowvalues, '<a href="/admin/admin_order?ord_order_id='.$order->key.'">Order '.$order->key.'</a>');

		array_push($rowvalues,  LibraryFunctions::convert_time($order->get('ord_timestamp'), "UTC", $session->get_timezone()));
		array_push($rowvalues, implode('<br>', $order_items_out));
		array_push($rowvalues, '$'.$order->get('ord_total_cost'));
		
		//array_push($rowvalues, $status_to_html[$min_status ?: 1]);
		$page->disprow($rowvalues);
	}
	$page->endtable();			

	/*
	?>	

     <h2>Addresses</h2>
	<?php
	$address_headers = array("Address");
	$page->tableheader($address_headers, "admin_table");

    foreach($addresses as $address) {
		$rowvalues = array();

        if($address->get('usa_is_default')){
            $setdefault = '';
        }
        else{
            $setdefault = '(<a class="sortlink" href="/profile/users_addrs_setdefault?a=' . LibraryFunctions::encode($address->key) . '&u=' . LibraryFunctions::encode($user->key) . '">Set Default</a>)';
        }

		array_push($rowvalues, '('.$address->key.') '.$address->get_address_string(' '));

		$page->disprow($rowvalues);
	}
	$page->endtable();
	*/
	/*
	?>

     <h2>Phone Numbers</h2>
	<?php

	$phone_headers = array("Phone");
	$page->tableheader($phone_headers, "admin_table");

	foreach($phone_numbers as $phone_number)	 {
		$rowvalues=array();
		
		array_push($rowvalues, $phone_number->get_phone_string() . '[<a class="sortlink" href="phone_numbers_edit?phn_phone_number_id='. $phone_number->key. '&usr_user_id='. $user->key . '">edit</a>]');
		
        $page->disprow($rowvalues);
	}

	$page->endtable();
	*/

	$received_emails = new MultiEmailRecipient(
		array('user_id' => $user->key, 'sent' => TRUE),
		NULL,
		20,
		0);
	$received_emails->load();

	$headers = array("Subject", "Status", "Sent Date", "Recipients");
	$altlinks = array();
	$box_vars =	array(
		'altlinks' => $altlinks,
		'title' => "Received Emails"
	);
	$page->tableheader($headers, $box_vars);		

	foreach ($received_emails as $received_email) {
		$email = new Email($received_email->get('erc_eml_email_id'), TRUE);
		$rowvalues = array();

		array_push($rowvalues, '('.$email->key.') <a href="/admin/admin_email_view?eml_email_id='.$email->key.'">'.$email->get('eml_subject').'</a>');
		array_push($rowvalues, $email->get_status_text());
		array_push($rowvalues, LibraryFunctions::convert_time( $email->get('eml_sent_time'), "UTC", $session->get_timezone()));

		$emails = new MultiEmailRecipient(
			array('email_id' => $email->key, 'sent' => TRUE)
			);
		$numemails = $emails->count_all();

		array_push($rowvalues, $numemails);
		$page->disprow($rowvalues);
	}
	$page->endtable();	

	if($user->get('usr_permission') > 0){

		$emails = new MultiEmail(
			array('user_id' => $user->key),
			NULL,
			20,
			0);
		$emails->load();

		$headers = array("Subject", "Status", "Sent Date", "Recipients");
		$altlinks = array();
		$box_vars =	array(
			'altlinks' => $altlinks,
			'title' => "Sent Emails"
		);
		$page->tableheader($headers, $box_vars);
		
		foreach ($emails as $email) {
			$rowvalues = array();

			array_push($rowvalues, '('.$email->key.') '.$email->get('eml_subject'));
			array_push($rowvalues, $email->get_status_text());
			array_push($rowvalues, LibraryFunctions::convert_time( $email->get('eml_sent_time'), "UTC", $session->get_timezone()));

			$emails = new MultiEmailRecipient(
				array('email_id' => $email->key, 'sent' => TRUE)
				);
			$numemails = $emails->count_all();

			array_push($rowvalues, $numemails);
			$page->disprow($rowvalues);
		}
		$page->endtable();	
		
	}

/*
?>
		<h2>Recurring Emails Sent</h2>

<?php

	$page->tableheader(array('Send Time', 'Email Address', 'Template'), 'recurring_mail_table');

	foreach (RecurringMailer::GetSentEmails($user->key) as $email) {
		$page->disprow(
			array(
				$email['ers_send_time'],
				$email['ers_usr_email'],
				$email['ers_template_name'])
			);
	}

	$page->endtable();
*/

	$headers = array("Time");
	$altlinks = array();
	$box_vars =	array(
		'altlinks' => $altlinks,
		'title' => "Logins"
	);
	$page->tableheader($headers, $box_vars);

	foreach ($logins as $login)
	{
		$rowvalues = array();
		array_push($rowvalues, LibraryFunctions::convert_time($login->log_login_time, "UTC", $session->get_timezone()));
		$page->disprow($rowvalues);
	}

  	$page->endtable();

	if($_SESSION['permission'] == 10){
		/*
		?>
		<h2>Errors</h2>
		<?php
		$page->tableheader(
			array(
				"Error",
				),
			"admin_table");

		foreach ($form_errors as $form_error) {
			$rowvalues = array();

			array_push($rowvalues, '(' .$form_error->key.')<a href="/admin/admin_form_error?lfe_log_form_error_id=' . $form_error->key . '"> '. $form_error->display_time($session). '</a> (' . $form_error->get('lfe_page') . ')');
			$page->disprow($rowvalues);
		}
		$page->endtable();
		*/
	}

	$page->admin_footer();

?>

