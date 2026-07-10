<?php
// IMPORTANT: Logic files MUST require PathHelper because they are not accessed
// through serve.php's front controller. They are directly included by view files,
// so they don't get automatic PathHelper loading.
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_user_logic(array $input): LogicResult {
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
	// Orders, subscriptions and event registrations are rendered by plugin-owned
	// panels (AdminUserPanelRegistry) that load their own data — this page needs
	// no store/event class here and renders on a store-less, event-less install.
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
	$show_all = isset($input['show_all']) && $input['show_all'] == '1';
	$list_limit = $show_all ? NULL : 10;

	// Get user
	$user = new User($input['usr_user_id'], TRUE);

	// Process actions
	$action = $input['action'] ?? $input['action'] ?? null;

	// Handle GET actions.
	// Intentional GET-action mutations — opt in to the GET-is-read-only tripwire.
	if(isset($input['action']) && $input['action'] == 'delete'){
		$user->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		SystemBase::$allow_get_mutation = true;
		try { $user->soft_delete(); }
		finally { SystemBase::$allow_get_mutation = false; }
		return LogicResult::redirect('/admin/admin_users');
	}
	else if(isset($input['action']) && $input['action'] == 'undelete'){
		$user->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		SystemBase::$allow_get_mutation = true;
		try { $user->undelete(); }
		finally { SystemBase::$allow_get_mutation = false; }
		return LogicResult::redirect('/admin/admin_user?usr_user_id='.$user->key);
	}

	// Handle POST actions
	if(LibraryFunctions::isFormSubmission()){

		if($input['action'] == 'add_to_group'){
			//ADD THE USER TO A GROUP
			$group = new Group($input['grp_group_id'], TRUE);
			$groupmember = $group->add_member($user->key);
			return LogicResult::redirect('/admin/admin_user?usr_user_id='.$user->key);
		}
		else if($input['action'] == 'remove_from_group'){
			$groupmember = new GroupMember($input['grm_group_member_id'], TRUE);
			$groupmember->remove();
			return LogicResult::redirect('/admin/admin_user?usr_user_id='.$user->key);
		}

		// Plugin-contributed panels (orders, subscriptions, events) own their POST
		// actions — dispatch through the registry before falling through.
		require_once(PathHelper::getIncludePath('includes/AdminUserPanelRegistry.php'));
		$panel_result = AdminUserPanelRegistry::handlePost($user, $input);
		if ($panel_result) {
			return $panel_result;
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

	// Get database connection for custom queries
	$dbhelper = DbConnector::get_instance();
	$dblink = $dbhelper->get_db_link();

	// Get total count of logins
	$sql_count = 'SELECT COUNT(*) as count FROM log_logins WHERE log_usr_user_id = ?';
	try{
		$q_count = $dblink->prepare($sql_count);
		$q_count->execute([$user->key]);
		$num_logins = $q_count->fetch(PDO::FETCH_OBJ)->count;
	}
	catch(PDOException $e){
		$dbhelper->handle_query_error($e);
	}

	// Get logins with limit
	$sql = 'SELECT * FROM log_logins WHERE log_usr_user_id = ? ORDER BY log_login_time DESC';
	if (!$show_all) {
		$sql .= ' LIMIT 10';
	}

	try{
		$q = $dblink->prepare($sql);
		$count = $q->execute([$user->key]);
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
			if(PluginHelper::isPluginActive('store') && $settings->get_setting('checkout_type')){
				$options['altlinks']['Payment Methods'] = '/plugins/store/admin/admin_user_payment_methods?usr_user_id='.$user->key;
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
		$dropdown_button .= '<button class="btn btn-soft-default btn-sm dropdown-toggle" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Actions</button>';
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

	// Prepare all data for view
	$page_vars['user'] = $user;
	$page_vars['show_all'] = $show_all;
	$page_vars['list_limit'] = $list_limit;
	$page_vars['phone_numbers'] = $phone_numbers;
	$page_vars['numphonerecords'] = $numphonerecords;
	$page_vars['addresses'] = $addresses;
	$page_vars['numaddressrecords'] = $numaddressrecords;
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

	// Return data for rendering
	return LogicResult::render($page_vars);
}
?>
