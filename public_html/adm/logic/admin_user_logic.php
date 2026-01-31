<?php
// IMPORTANT: Logic files MUST require PathHelper because they are not accessed
// through serve.php's front controller. They are directly included by view files,
// so they don't get automatic PathHelper loading.
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_user_logic($get_vars, $post_vars) {
	// Required includes (PathHelper is now available from the require above)
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/Pager.php'));
	require_once(PathHelper::getIncludePath('includes/Activation.php'));

	// Data class includes
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/phone_number_class.php'));
	require_once(PathHelper::getIncludePath('data/address_class.php'));
	require_once(PathHelper::getIncludePath('data/log_form_errors_class.php'));
	require_once(PathHelper::getIncludePath('data/emails_class.php'));
	require_once(PathHelper::getIncludePath('data/email_recipients_class.php'));
	require_once(PathHelper::getIncludePath('data/events_class.php'));
	require_once(PathHelper::getIncludePath('data/event_logs_class.php'));
	require_once(PathHelper::getIncludePath('data/event_sessions_class.php'));
	require_once(PathHelper::getIncludePath('data/orders_class.php'));
	require_once(PathHelper::getIncludePath('data/products_class.php'));
	require_once(PathHelper::getIncludePath('data/product_details_class.php'));
	require_once(PathHelper::getIncludePath('data/groups_class.php'));
	require_once(PathHelper::getIncludePath('data/group_members_class.php'));
	require_once(PathHelper::getIncludePath('data/mailing_lists_class.php'));
	require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
	require_once(PathHelper::getIncludePath('data/change_tracking_class.php'));

	// Get singletons (NO require needed - these are always pre-loaded)
	$settings = Globalvars::get_instance();
	require_once(PathHelper::getComposerAutoloadPath());

	$session = SessionControl::get_instance();

	// Permission check
	$session->check_permission(5);
	$session->set_return();

	// Initialize page variables
	$page_vars = array();
	$page_vars['settings'] = $settings;
	$page_vars['session'] = $session;

	// Check if "show all" is enabled
	$show_all = isset($get_vars['show_all']) && $get_vars['show_all'] == '1';
	$list_limit = $show_all ? NULL : 10;

	// Get user
	$user = new User($get_vars['usr_user_id'], TRUE);
	include(PathHelper::getAbsolutePath('/utils/registrant_maintenance.php'));
	include(PathHelper::getAbsolutePath('/utils/order_maintenance.php'));

	// Process actions
	$action = $get_vars['action'] ?? $post_vars['action'] ?? null;

	// Handle GET actions
	if(isset($get_vars['action']) && $get_vars['action'] == 'delete'){
		$user->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$user->soft_delete();
		return LogicResult::redirect('/admin/admin_users');
	}
	else if(isset($get_vars['action']) && $get_vars['action'] == 'undelete'){
		$user->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$user->undelete();
		return LogicResult::redirect('/admin/admin_user?usr_user_id='.$user->key);
	}

	// Handle POST actions
	if($post_vars){

		if($post_vars['action'] == 'add_to_group'){
			//ADD THE USER TO A GROUP
			$group = new Group($post_vars['grp_group_id'], TRUE);
			$groupmember = $group->add_member($user->key);
			return LogicResult::redirect('/admin/admin_user?usr_user_id='.$user->key);
		}
		else if($post_vars['action'] == 'remove_from_group'){
			$groupmember = new GroupMember($post_vars['grm_group_member_id'], TRUE);
			$groupmember->remove();
			return LogicResult::redirect('/admin/admin_user?usr_user_id='.$user->key);
		}
		else if($post_vars['action'] == 'add_to_event'){
			//ADD THE USER TO AN EVENT
			$event = new Event($post_vars['evt_event_id'], TRUE);
			$event->add_registrant($user->key);
			return LogicResult::redirect('/admin/admin_user?usr_user_id='.$user->key);
		}
		else if($post_vars['action'] == 'remove_from_event'){
			$event = new Event($post_vars['evt_event_id'], TRUE);
			$event->remove_registrant($user->key);
			return LogicResult::redirect('/admin/admin_user?usr_user_id='.$user->key);
		}

	}

	// Load phone numbers
	$phone_numbers = new MultiPhoneNumber(
		array('user_id'=>$user->key),
		NULL,
		30,
		0);
	$phone_numbers->load();
	$numphonerecords = $phone_numbers->count_all();

	// Load addresses
	$addresses = new MultiAddress(
		array('user_id'=>$user->key),
		NULL,
		30,
		0);
	$numaddressrecords = $addresses->count_all();
	$addresses->load();

	// Load orders
	$search_criteria = array();
	$search_criteria['user_id'] = $user->key;
	//$search_criteria['deleted'] = FALSE;

	$orders = new MultiOrder(
		$search_criteria,
		array('ord_order_id'=>'DESC'),
		$list_limit,
		NULL);
	$numorders = $orders->count_all();
	$orders->load();

	// Load event registrations
	$searches['user_id'] = $user->key;
	$event_registrations = new MultiEventRegistrant(
		$searches,
		NULL, //array('event_id'=>'DESC'),
		$list_limit,
		NULL);
	$numeventsregistrations = $event_registrations->count_all();
	$event_registrations->load();

	// Load active subscriptions
	$active_subscriptions = new MultiOrderItem(
	array('user_id' => $user->key, 'is_active_subscription' => true), //SEARCH CRITERIA
	array('order_item_id' => 'DESC'),  // SORT, SORT DIRECTION
	$list_limit, //NUMBER PER PAGE
	NULL //OFFSET
	);
	$num_active_subscriptions = $active_subscriptions->count_all();
	$active_subscriptions->load();

	// Load cancelled subscriptions
	$cancelled_subscriptions = new MultiOrderItem(
	array('user_id' => $user->key, 'is_cancelled_subscription' => true), //SEARCH CRITERIA
	array('order_item_id' => 'DESC'),  // SORT, SORT DIRECTION
	$list_limit, //NUMBER PER PAGE
	NULL //OFFSET
	);
	$num_cancelled_subscriptions = $cancelled_subscriptions->count_all();
	$cancelled_subscriptions->load();

	// Get database connection for custom queries
	$dbhelper = DbConnector::get_instance();
	$dblink = $dbhelper->get_db_link();

	// Get total count of logins
	$sql_count = 'SELECT COUNT(*) as count FROM log_logins WHERE log_usr_user_id='.$user->key;
	try{
		$q_count = $dblink->prepare($sql_count);
		$q_count->execute();
		$num_logins = $q_count->fetch(PDO::FETCH_OBJ)->count;
	}
	catch(PDOException $e){
		$dbhelper->handle_query_error($e);
	}

	// Get logins with limit
	$sql = 'SELECT * FROM log_logins WHERE log_usr_user_id='.$user->key.' ORDER BY log_login_time DESC';
	if (!$show_all) {
		$sql .= ' LIMIT 10';
	}

	try{
		$q = $dblink->prepare($sql);
		$count = $q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);
	}
	catch(PDOException $e){
		$dbhelper->handle_query_error($e);
	}
	$logins = $q->fetchAll();

	$webDir = $settings->get_setting('webDir');

	// Build altlinks array
	$options = array();
	$options['altlinks'] = array();

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

	// Build show all URL for card footers
	$show_all_url = !$show_all ? '/admin/admin_user?usr_user_id=' . $user->key . '&show_all=1' : null;

	// Build dropdown button from altlinks
	$dropdown_button = '';
	if (!empty($options['altlinks'])) {
		$dropdown_button = '<div class="dropdown">';
		$dropdown_button .= '<button class="btn btn-falcon-default btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Actions</button>';
		$dropdown_button .= '<div class="dropdown-menu dropdown-menu-end py-0">';
		foreach ($options['altlinks'] as $label => $url) {
			$dropdown_button .= '<a href="' . htmlspecialchars($url) . '" class="dropdown-item">' . htmlspecialchars($label) . '</a>';
		}
		$dropdown_button .= '</div>';
		$dropdown_button .= '</div>';
	}

	// Get mailing list subscriptions
	$user_subscribed_list = array();
	$search_criteria = array('deleted' => false, 'user_id' => $user->key);
	$user_lists = new MultiMailingListRegistrant($search_criteria);
	$user_lists->load();
	foreach ($user_lists as $user_list){
		$mailing_list = new MailingList($user_list->get('mlr_mlt_mailing_list_id'), TRUE);
		$user_subscribed_list[] = $mailing_list->get('mlt_name');
	}

	// Get tier info
	$user_tier = SubscriptionTier::GetUserTier($user->key);

	// Get tier change history
	$tier_changes = new MultiChangeTracking([
		'cht_entity_type' => 'subscription_tier',
		'cht_usr_user_id' => $user->key
	], ['cht_change_time' => 'DESC'], $list_limit);

	// Get groups count
	$groups = Group::get_groups_for_member($user->key, 'user', false, 'objects');
	$num_groups = count($groups);

	// Count received emails
	$received_emails_count = new MultiEmailRecipient(
		array('user_id' => $user->key, 'sent' => TRUE),
		NULL,
		$list_limit,
		0);
	$num_received_emails = $received_emails_count->count_all();

	// Count sent emails
	$sent_emails_count = new MultiEmail(
		array('user_id' => $user->key),
		NULL,
		$list_limit,
		0);
	$num_sent_emails = $sent_emails_count->count_all();

	// Count session visits for this user
	$num_session_visits = 0;
	foreach ($event_registrations as $event_registration) {
		$searches_count = array();
		$searches_count['event_id'] = $event_registration->get('evr_evt_event_id');
		$event_sessions_count = new MultiEventSessions(
			$searches_count,
			array('evs_session_number' => 'DESC', 'evs_title' => 'DESC'));
		$event_sessions_count->load();

		foreach ($event_sessions_count as $event_session_count) {
			if ($event_session_count->get_last_visited_time_for_user($user->key)) {
				$num_session_visits++;
			}
		}
	}

	// Prepare all data for view
	$page_vars['user'] = $user;
	$page_vars['show_all'] = $show_all;
	$page_vars['list_limit'] = $list_limit;
	$page_vars['phone_numbers'] = $phone_numbers;
	$page_vars['numphonerecords'] = $numphonerecords;
	$page_vars['addresses'] = $addresses;
	$page_vars['numaddressrecords'] = $numaddressrecords;
	$page_vars['orders'] = $orders;
	$page_vars['numorders'] = $numorders;
	$page_vars['event_registrations'] = $event_registrations;
	$page_vars['numeventsregistrations'] = $numeventsregistrations;
	$page_vars['active_subscriptions'] = $active_subscriptions;
	$page_vars['num_active_subscriptions'] = $num_active_subscriptions;
	$page_vars['cancelled_subscriptions'] = $cancelled_subscriptions;
	$page_vars['num_cancelled_subscriptions'] = $num_cancelled_subscriptions;
	$page_vars['logins'] = $logins;
	$page_vars['num_logins'] = $num_logins;
	$page_vars['dropdown_button'] = $dropdown_button;
	$page_vars['show_all_url'] = $show_all_url;
	$page_vars['user_subscribed_list'] = $user_subscribed_list;
	$page_vars['user_tier'] = $user_tier;
	$page_vars['tier_changes'] = $tier_changes;
	$page_vars['groups'] = $groups;
	$page_vars['num_groups'] = $num_groups;
	$page_vars['num_received_emails'] = $num_received_emails;
	$page_vars['num_sent_emails'] = $num_sent_emails;
	$page_vars['num_session_visits'] = $num_session_visits;

	// Return data for rendering
	return LogicResult::render($page_vars);
}
?>
