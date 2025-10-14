<?php

	require_once(PathHelper::getIncludePath('/includes/Activation.php'));

	require_once(PathHelper::getIncludePath('/includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('/data/users_class.php'));
	require_once(PathHelper::getIncludePath('/data/phone_number_class.php'));
	require_once(PathHelper::getIncludePath('/data/address_class.php'));
	require_once(PathHelper::getIncludePath('/data/log_form_errors_class.php'));
	require_once(PathHelper::getIncludePath('/data/emails_class.php'));
	require_once(PathHelper::getIncludePath('/data/email_recipients_class.php'));
	require_once(PathHelper::getIncludePath('/data/events_class.php'));
	require_once(PathHelper::getIncludePath('/data/event_logs_class.php'));
	require_once(PathHelper::getIncludePath('/data/event_sessions_class.php'));
	require_once(PathHelper::getIncludePath('/data/orders_class.php'));
	require_once(PathHelper::getIncludePath('/data/products_class.php'));
	require_once(PathHelper::getIncludePath('/data/product_details_class.php'));

	require_once(PathHelper::getIncludePath('/data/groups_class.php'));
	require_once(PathHelper::getIncludePath('/data/group_members_class.php'));
	require_once(PathHelper::getIncludePath('/data/mailing_lists_class.php'));

	$settings = Globalvars::get_instance();
	$composer_dir = $settings->get_setting('composerAutoLoad');
	require_once $composer_dir.'autoload.php';

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	// Check if "show all" is enabled
	$show_all = isset($_GET['show_all']) && $_GET['show_all'] == '1';
	$list_limit = $show_all ? NULL : 10;

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
		$list_limit,
		NULL);
	$numorders = $orders->count_all();
	$orders->load();

	$searches['user_id'] = $user->key;
	$event_registrations = new MultiEventRegistrant(
		$searches,
		NULL, //array('event_id'=>'DESC'),
		$list_limit,
		NULL);
	$numeventsregistrations = $event_registrations->count_all();
	$event_registrations->load();

	//SUBSCRIPTIONS
	$active_subscriptions = new MultiOrderItem(
	array('user_id' => $user->key, 'is_active_subscription' => true), //SEARCH CRITERIA
	array('order_item_id' => 'DESC'),  // SORT, SORT DIRECTION
	$list_limit, //NUMBER PER PAGE
	NULL //OFFSET
	);
	$num_active_subscriptions = $active_subscriptions->count_all();
	$active_subscriptions->load();

	//SUBSCRIPTIONS
	$cancelled_subscriptions = new MultiOrderItem(
	array('user_id' => $user->key, 'is_cancelled_subscription' => true), //SEARCH CRITERIA
	array('order_item_id' => 'DESC'),  // SORT, SORT DIRECTION
	$list_limit, //NUMBER PER PAGE
	NULL //OFFSET
	);
	$num_cancelled_subscriptions = $cancelled_subscriptions->count_all();
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

	$settings = Globalvars::get_instance();
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

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'users-list',
		'page_title' => 'User',
		'readable_title' => $user->display_name(),
		'breadcrumbs' => array(
			'Users'=>'/admin/admin_users',
			$user->display_name() => '',
		),
		'session' => $session,
		'no_page_card' => true,
		'header_action' => $dropdown_button,
	)
	);

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
	require_once(PathHelper::getIncludePath('/data/subscription_tiers_class.php'));
	require_once(PathHelper::getIncludePath('/data/change_tracking_class.php'));
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

	// Create Pager objects for record count display
	$events_pager = new Pager(array('numrecords' => $numeventsregistrations, 'numperpage' => $list_limit ?: $numeventsregistrations));
	$orders_pager = new Pager(array('numrecords' => $numorders, 'numperpage' => $list_limit ?: $numorders));
	$groups_pager = new Pager(array('numrecords' => $num_groups, 'numperpage' => $list_limit ?: $num_groups));
	$active_subscriptions_pager = new Pager(array('numrecords' => $num_active_subscriptions, 'numperpage' => $list_limit ?: $num_active_subscriptions));
	$cancelled_subscriptions_pager = new Pager(array('numrecords' => $num_cancelled_subscriptions, 'numperpage' => $list_limit ?: $num_cancelled_subscriptions));
	$received_emails_pager = new Pager(array('numrecords' => $num_received_emails, 'numperpage' => $list_limit ?: $num_received_emails));
	$sent_emails_pager = new Pager(array('numrecords' => $num_sent_emails, 'numperpage' => $list_limit ?: $num_sent_emails));
	$logins_pager = new Pager(array('numrecords' => $num_logins, 'numperpage' => $list_limit ?: $num_logins));
	$session_visits_pager = new Pager(array('numrecords' => $num_session_visits, 'numperpage' => $list_limit ?: $num_session_visits));
	?>

	<!-- Two Column Layout -->
	<div class="row g-3 mb-3">
		<!-- LEFT COLUMN: Account Information -->
		<div class="col-xxl-6">
			<div class="card">
				<div class="card-header bg-body-tertiary">
					<h6 class="mb-0"><span class="fas fa-user me-2"></span>Account Information</h6>
				</div>
				<div class="card-body">
					<table class="table table-borderless fs-9 fw-medium mb-0">
						<tbody>
							<tr>
								<td class="p-1" style="width: 35%;">Email:</td>
								<td class="p-1">
									<a class="text-600 text-decoration-none" href="mailto:<?php echo htmlspecialchars($user->get('usr_email')); ?>">
										<?php echo htmlspecialchars($user->get('usr_email')); ?>
									</a>
									<?php if($user->get('usr_email_is_verified')): ?>
										<span class="badge rounded-pill badge-subtle-success ms-2">
											<span>Verified</span><span class="fas fa-check ms-1" data-fa-transform="shrink-4"></span>
										</span>
									<?php else: ?>
										<span class="badge rounded-pill badge-subtle-warning ms-2">
											<span>Unverified</span><span class="fas fa-exclamation-triangle ms-1" data-fa-transform="shrink-4"></span>
										</span>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<td class="p-1" style="width: 35%;">Signed Up:</td>
								<td class="p-1 text-600"><?php echo LibraryFunctions::convert_time($user->get('usr_signup_date'), 'UTC', $session->get_timezone(), 'M j, Y'); ?></td>
							</tr>
							<?php if($user->get('usr_delete_time')): ?>
							<tr>
								<td class="p-1" style="width: 35%;">Status:</td>
								<td class="p-1">
									<span class="badge badge-danger">Deleted at <?php echo LibraryFunctions::convert_time($user->get('usr_delete_time'), 'UTC', $session->get_timezone()); ?></span>
								</td>
							</tr>
							<?php endif; ?>
							<?php if($user->get('usr_is_admin_disabled')): ?>
							<tr>
								<td class="p-1" style="width: 35%;">Admin Status:</td>
								<td class="p-1">
									<span class="badge badge-warning">Admin Disabled (<?php echo htmlspecialchars($user->get('usr_admin_disabled_comment')); ?>)</span>
								</td>
							</tr>
							<?php elseif($user->get('usr_is_disabled')): ?>
							<tr>
								<td class="p-1" style="width: 35%;">Status:</td>
								<td class="p-1"><span class="badge badge-warning">Disabled</span></td>
							</tr>
							<?php endif; ?>
							<tr>
								<td class="p-1" style="width: 35%;">Phone:</td>
								<td class="p-1 text-600">
									<?php if($numphonerecords): ?>
										<?php foreach($phone_numbers as $phone_number): ?>
											<a href="tel:<?php echo htmlspecialchars($phone_number->get_phone_string()); ?>" class="text-600 text-decoration-none">
												<?php echo htmlspecialchars($phone_number->get_phone_string()); ?>
											</a>
											<a href="/admin/admin_phone_edit?phn_phone_number_id=<?php echo $phone_number->key; ?>&usr_user_id=<?php echo $user->key; ?>" class="fs-11 ms-2">[edit]</a>
											<br>
										<?php endforeach; ?>
									<?php else: ?>
										<a href="/admin/admin_phone_edit?usr_user_id=<?php echo $user->key; ?>" class="fs-11">[Add Phone Number]</a>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<td class="p-1" style="width: 35%;">Address:</td>
								<td class="p-1 text-600">
									<?php if($numaddressrecords): ?>
										<?php foreach($addresses as $address): ?>
											<?php echo htmlspecialchars($address->get_address_string(' ')); ?>
											<a href="/admin/admin_address_edit?usa_address_id=<?php echo $address->key; ?>" class="fs-11 ms-2">[edit]</a>
											<br>
										<?php endforeach; ?>
									<?php else: ?>
										<a href="/admin/admin_address_edit?usr_user_id=<?php echo $user->key; ?>" class="fs-11">[Add Address]</a>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<td class="p-1" style="width: 35%;">Timezone:</td>
								<td class="p-1 text-600">
									<?php echo htmlspecialchars($user->get('usr_timezone')); ?>
									<a href="/admin/admin_users_edit?usr_user_id=<?php echo $user->key; ?>" class="fs-11 ms-2">[edit]</a>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>

			<!-- Subscription Tier Card -->
			<div class="card mt-3">
				<div class="card-header bg-body-tertiary">
					<h6 class="mb-0"><span class="fas fa-star me-2"></span>Subscription Tier</h6>
				</div>
				<div class="card-body">
					<table class="table table-borderless fs-9 fw-medium mb-0">
						<tbody>
							<tr>
								<td class="p-1" style="width: 35%;">Current Tier:</td>
								<td class="p-1">
									<?php if($user_tier): ?>
										<strong><?php echo htmlspecialchars($user_tier->get('sbt_display_name')); ?></strong>
										(Level <?php echo $user_tier->get('sbt_tier_level'); ?>)
									<?php else: ?>
										<strong>Free</strong> (No active tier)
									<?php endif; ?>
									<a href="/admin/admin_tier_edit?user_id=<?php echo $user->key; ?>" class="fs-11 ms-2">[change]</a>
								</td>
							</tr>
						</tbody>
					</table>

					<?php if($tier_changes->count_all() > 0): ?>
						<?php $tier_changes->load(); ?>
						<div class="border-top mt-3 pt-3">
							<h6 class="fs-10 mb-2">Tier Change History:</h6>
							<div class="fs-10 text-600">
								<?php foreach($tier_changes as $change): ?>
									<?php
										$change_time = LibraryFunctions::convert_time($change->get('cht_change_time'), 'UTC', $session->get_timezone());
										$old_value = $change->get('cht_old_value') ? 'Level ' . $change->get('cht_old_value') : 'Free';
										$new_value = $change->get('cht_new_value') ? 'Level ' . $change->get('cht_new_value') : 'Free';
										$reason = $change->get('cht_change_reason');

										if ($change->get('cht_entity_id')) {
											try {
												$tier = new SubscriptionTier($change->get('cht_entity_id'), TRUE);
												$new_value = htmlspecialchars($tier->get('sbt_display_name')) . ' (' . $new_value . ')';
											} catch (Exception $e) {}
										}
									?>
									<div class="mb-1">
										• <?php echo $change_time; ?>: <?php echo $old_value; ?> → <?php echo $new_value; ?>
										<?php if($reason): ?>
											(<?php echo htmlspecialchars($reason); ?>
											<?php if($reason === 'purchase' && $change->get('cht_reference_id')): ?>
												- <a href="/admin/admin_order?ord_order_id=<?php echo $change->get('cht_reference_id'); ?>">Order #<?php echo $change->get('cht_reference_id'); ?></a>
											<?php elseif($reason === 'manual' && $change->get('cht_changed_by_usr_user_id')): ?>
												<?php
													try {
														$changed_by = new User($change->get('cht_changed_by_usr_user_id'), TRUE);
														echo ' by ' . htmlspecialchars($changed_by->display_name());
													} catch (Exception $e) {}
												?>
											<?php endif; ?>)
										<?php endif; ?>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<!-- Mailing Lists Card -->
			<?php if(!empty($user_subscribed_list)): ?>
			<div class="card mt-3">
				<div class="card-header bg-body-tertiary">
					<h6 class="mb-0"><span class="fas fa-envelope-open me-2"></span>Mailing List Subscriptions</h6>
				</div>
				<div class="card-body">
					<p class="fs-9 mb-0">This user is subscribed to: <strong><?php echo implode(', ', $user_subscribed_list); ?></strong></p>
				</div>
			</div>
			<?php endif; ?>

			<!-- Groups Card -->
			<div class="card mt-3">
				<div class="card-header bg-body-tertiary">
					<h6 class="mb-0"><span class="fas fa-users me-2"></span>Groups</h6>
				</div>
				<div class="card-body">
					<div class="table-responsive">
						<table class="table table-sm fs-9 mb-0">
							<thead>
								<tr class="border-bottom">
									<th class="py-2">Group</th>
									<th class="py-2 text-end">Action</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach($groups as $group): ?>
									<?php $groupmember = $group->is_member_in_group($user->key); ?>
									<tr>
										<td class="py-2"><?php echo htmlspecialchars($group->get('grp_name')); ?></td>
										<td class="py-2 text-end">
											<form method="POST" action="/admin/admin_user?usr_user_id=<?php echo $user->key; ?>" style="display: inline;">
												<input type="hidden" name="action" value="remove_from_group" />
												<input type="hidden" name="grm_group_member_id" value="<?php echo $groupmember->key; ?>" />
												<button type="submit" class="btn btn-sm btn-falcon-default">Remove</button>
											</form>
										</td>
									</tr>
								<?php endforeach; ?>
								<tr>
									<td colspan="2" class="pt-3">
										<?php
											$formwriter = $page->getFormWriter('form5');
											$validation_rules = array();
											$validation_rules['grp_group_id']['required']['value'] = 'true';
											echo $formwriter->set_validate($validation_rules);
											echo $formwriter->begin_form('form5', 'POST', '/admin/admin_user?usr_user_id='. $user->key);

											$group_drops = new MultiGroup(
												array('category'=>'user'),
												NULL,
												NULL,
												NULL);
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
										?>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
					<?php echo $groups_pager->record_count_info(count($groups), array('show_all_url' => $show_all_url)); ?>
				</div>
			</div>
		</div>

		<!-- RIGHT COLUMN: Subscription Status -->
		<div class="col-xxl-6">
			<!-- Active Subscriptions -->
			<div class="card">
				<div class="card-header bg-body-tertiary">
					<h6 class="mb-0"><span class="fas fa-credit-card me-2"></span>Active Subscriptions</h6>
				</div>
				<div class="card-body">
					<?php if($active_subscriptions->count() > 0): ?>
						<?php foreach($active_subscriptions as $subscription): ?>
							<?php
								$stripe_helper = new StripeHelper();
								$stripe_helper->update_subscription_in_order_item($subscription);
								$status_words = $subscription->get('odi_subscription_status') ? $subscription->get('odi_subscription_status') : 'active';
							?>
							<div class="mb-3 p-2 bg-body-tertiary rounded">
								<div class="fs-9 fw-semi-bold">
									<a href="/admin/admin_order?ord_order_id=<?php echo $subscription->get('odi_ord_order_id'); ?>">
										Order <?php echo $subscription->get('odi_ord_order_id'); ?>
									</a> - $<?php echo number_format($subscription->get('odi_price'), 2); ?>/month
								</div>
								<div class="fs-11 text-600 mt-1">
									Status: <span class="text-success"><?php echo htmlspecialchars($status_words); ?></span><br>
									<?php if($subscription->get('odi_subscription_period_end')): ?>
										Period ends: <?php echo LibraryFunctions::convert_time($subscription->get('odi_subscription_period_end'), 'UTC', $session->get_timezone()); ?><br>
									<?php endif; ?>
									<a href="/profile/orders_recurring_action?order_item_id=<?php echo $subscription->key; ?>" class="text-danger">cancel</a>
								</div>
							</div>
						<?php endforeach; ?>
					<?php else: ?>
						<p class="text-600 fs-9 mb-0">No active subscriptions</p>
					<?php endif; ?>
				</div>
				<?php echo $active_subscriptions_pager->record_count_info($active_subscriptions->count(), array('show_all_url' => $show_all_url)); ?>
			</div>

			<!-- Cancelled Subscriptions -->
			<div class="card mt-3">
				<div class="card-header bg-body-tertiary">
					<h6 class="mb-0"><span class="fas fa-ban me-2"></span>Cancelled Subscriptions</h6>
				</div>
				<div class="card-body">
					<?php if($cancelled_subscriptions->count() > 0): ?>
						<?php foreach($cancelled_subscriptions as $subscription): ?>
							<div class="mb-2 p-2 bg-body-tertiary rounded">
								<div class="fs-9 fw-semi-bold">
									<a href="/admin/admin_order?ord_order_id=<?php echo $subscription->get('odi_ord_order_id'); ?>">
										Order <?php echo $subscription->get('odi_ord_order_id'); ?>
									</a> - $<?php echo number_format($subscription->get('odi_price'), 2); ?>/month
								</div>
								<div class="fs-11 text-600 mt-1">
									Canceled: <?php echo LibraryFunctions::convert_time($subscription->get('odi_subscription_cancelled_time'), 'UTC', $session->get_timezone()); ?>
									<?php if($subscription->get('odi_subscription_period_end')): ?>
										<br>Last day: <?php echo LibraryFunctions::convert_time($subscription->get('odi_subscription_period_end'), 'UTC', $session->get_timezone()); ?>
									<?php endif; ?>
								</div>
							</div>
						<?php endforeach; ?>
					<?php else: ?>
						<p class="text-600 fs-9 mb-0">No cancelled subscriptions</p>
					<?php endif; ?>
				</div>
				<?php echo $cancelled_subscriptions_pager->record_count_info($cancelled_subscriptions->count(), array('show_all_url' => $show_all_url)); ?>
			</div>
		</div>
	</div>

	<?php

	// Events Table
	$headers = array('Event', 'Added', 'Expires', 'Action');
	$table_options = array('title' => 'Events', 'card' => true);
	$page->tableheader($headers, $table_options, $events_pager);

	$event_ids_for_user = array();
	foreach ($event_registrations as $event_registration):
		$event = new Event($event_registration->get('evr_evt_event_id'), TRUE);
		$event_ids_for_user[] = $event->key;

		$event_cell = '<a href="/admin/admin_event?evt_event_id='.$event->key.'">'.
			LibraryFunctions::convert_time($event->get('evt_start_time'), "UTC", "UTC", 'M j, Y').' '.
			'<strong>'.htmlspecialchars($event->getString('evt_name', 50)).'</strong> '.
			htmlspecialchars($event->get('evt_location')).
			'</a>';

		$added_cell = LibraryFunctions::convert_time($event_registration->get('evr_create_time'), 'UTC', $session->get_timezone(), 'M j');
		$expires_cell = LibraryFunctions::convert_time($event_registration->get('evr_expires_time'), 'UTC', $session->get_timezone(), 'M j');

		$action_cell = '<form method="POST" action="/admin/admin_user?usr_user_id='.$user->key.'" style="display: inline;">'.
			'<input type="hidden" name="action" value="remove_from_event" />'.
			'<input type="hidden" name="evt_event_id" value="'.$event->key.'" />'.
			'<button type="submit" class="btn btn-sm btn-falcon-default">Remove</button>'.
			'</form>';

		$page->disprow(array($event_cell, $added_cell, $expires_cell, $action_cell));
	endforeach;

	// Add event form row
	$formwriter = $page->getFormWriter('form3');
	$validation_rules = array();
	$validation_rules['evt_event_id']['required']['value'] = 'true';
	$add_form = $formwriter->set_validate($validation_rules);
	$add_form .= $formwriter->begin_form('form2', 'POST', '/admin/admin_user?usr_user_id='. $user->key);

	$events = new MultiEvent(
		array('deleted'=>false),
		array('start_time'=>'DESC'),
		NULL,
		NULL);
	$events->load();

	foreach($event_ids_for_user as $event_id) {
		if($events->contains_key($event_id)){
			$events->remove_by_key($event_id);
		}
	}

	$optionvals = $events->get_dropdown_array();
	$add_form .= $formwriter->hiddeninput('action', 'add_to_event');
	$add_form .= $formwriter->hiddeninput('usr_user_id', $user->key);
	$add_form .= $formwriter->dropinput("Add to event", "evt_event_id", "ctrlHolder", $optionvals, NULL, '', TRUE);
	$add_form .= $formwriter->new_form_button('Add');
	$add_form .= $formwriter->end_form();

	echo '<tr><td colspan="4" class="pt-3">'.$add_form.'</td></tr>';

	$page->endtable($events_pager);

	// Orders Table
	$headers = array('Order ID', 'Order Time', 'Products', 'Total');
	$table_options = array('title' => 'Orders', 'card' => true);
	$page->tableheader($headers, $table_options, $orders_pager);

	$PRODUCT_ID_TO_NAME_CACHE = array();
	foreach($orders as $order):
		$order_items = $order->get_order_items();
		$order_items_out = array();
		foreach($order_items as $order_item):
			if (array_key_exists($order_item->get('odi_pro_product_id'), $PRODUCT_ID_TO_NAME_CACHE)) {
				$title = $PRODUCT_ID_TO_NAME_CACHE[$order_item->get('odi_pro_product_id')];
			} else {
				$product = new Product($order_item->get('odi_pro_product_id'), TRUE);
				$title = $product->get('pro_name');
				$PRODUCT_ID_TO_NAME_CACHE[$product->key] = $title;
			}

			$this_out = htmlspecialchars($title) . ' ($'. number_format($order_item->get('odi_price'), 2) .')';

			if($order_item->get('odi_subscription_cancelled_time')){
				$status_words = $order_item->get('odi_subscription_status') ? $order_item->get('odi_subscription_status') : 'canceled';
				$this_out .= '<br><span class="fs-11 text-600">'. htmlspecialchars($status_words). ' at '.LibraryFunctions::convert_time($order_item->get('odi_subscription_cancelled_time'), 'UTC', $session->get_timezone()).'</span>';
			}
			else if($order_item->get('odi_subscription_status')){
				$this_out .=  '<br><span class="fs-11 text-600">STATUS: '. htmlspecialchars($order_item->get('odi_subscription_status')).'</span>';
			}

			$order_items_out[] = $this_out;
		endforeach;

		$order_id_cell = '<a href="/admin/admin_order?ord_order_id='.$order->key.'">Order '.$order->key.'</a>';
		$order_time_cell = LibraryFunctions::convert_time($order->get('ord_timestamp'), "UTC", $session->get_timezone());
		$products_cell = implode('<br>', $order_items_out);
		$total_cell = '$'.number_format($order->get('ord_total_cost'), 2);

		$page->disprow(array($order_id_cell, $order_time_cell, $products_cell, $total_cell));
	endforeach;

	$page->endtable($orders_pager);
	?>

	<?php
	/*
	REMOVED OLD CODE - Addresses and Phone Numbers sections
	These are now integrated into the Account Information card above
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
	?>

	<!-- Email and Login Activity Side by Side -->
	<div class="row g-3 mb-3">
		<!-- Received Emails Column -->
		<div class="col-lg-6">
			<div class="card">
				<div class="card-header bg-body-tertiary">
					<h6 class="mb-0"><span class="fas fa-inbox me-2"></span>Received Emails</h6>
				</div>
				<div class="card-body p-0">
					<div class="table-responsive">
						<table class="table table-sm fs-9 mb-0">
							<thead class="bg-body-tertiary">
								<tr>
									<th class="py-2 ps-3">Subject</th>
									<th class="py-2 text-center">Status</th>
									<th class="py-2 text-center">Sent Date</th>
								</tr>
							</thead>
							<tbody>
								<?php
									$received_emails = new MultiEmailRecipient(
										array('user_id' => $user->key, 'sent' => TRUE),
										NULL,
										$list_limit,
										0);
									$received_emails->load();

									foreach ($received_emails as $received_email):
										$email = new Email($received_email->get('erc_eml_email_id'), TRUE);
								?>
									<tr>
										<td class="py-2 ps-3">
											<a href="/admin/admin_email_view?eml_email_id=<?php echo $email->key; ?>">
												<?php echo htmlspecialchars($email->get('eml_subject')); ?>
											</a>
										</td>
										<td class="py-2 text-center fs-11"><?php echo htmlspecialchars($email->get_status_text()); ?></td>
										<td class="py-2 text-center fs-11"><?php echo LibraryFunctions::convert_time($email->get('eml_sent_time'), "UTC", $session->get_timezone(), 'M j'); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<?php echo $received_emails_pager->record_count_info($received_emails->count(), array('show_all_url' => $show_all_url)); ?>
				</div>
			</div>

			<?php if($user->get('usr_permission') > 0): ?>
			<!-- Sent Emails -->
			<div class="card mt-3">
				<div class="card-header bg-body-tertiary">
					<h6 class="mb-0"><span class="fas fa-paper-plane me-2"></span>Sent Emails</h6>
				</div>
				<div class="card-body p-0">
					<div class="table-responsive">
						<table class="table table-sm fs-9 mb-0">
							<thead class="bg-body-tertiary">
								<tr>
									<th class="py-2 ps-3">Subject</th>
									<th class="py-2 text-center">Status</th>
									<th class="py-2 text-center">Sent Date</th>
								</tr>
							</thead>
							<tbody>
								<?php
									$emails = new MultiEmail(
										array('user_id' => $user->key),
										NULL,
										$list_limit,
										0);
									$emails->load();

									foreach ($emails as $email):
								?>
									<tr>
										<td class="py-2 ps-3"><?php echo htmlspecialchars($email->get('eml_subject')); ?></td>
										<td class="py-2 text-center fs-11"><?php echo htmlspecialchars($email->get_status_text()); ?></td>
										<td class="py-2 text-center fs-11"><?php echo LibraryFunctions::convert_time($email->get('eml_sent_time'), "UTC", $session->get_timezone(), 'M j'); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<?php echo $sent_emails_pager->record_count_info($emails->count(), array('show_all_url' => $show_all_url)); ?>
				</div>
			</div>
			<?php endif; ?>
		</div>

		<!-- Right Column: Session Visits and Logins -->
		<div class="col-lg-6">
			<!-- Session Visits -->
			<div class="card">
				<div class="card-header bg-body-tertiary">
					<h6 class="mb-0"><span class="fas fa-eye me-2"></span>Session Visits</h6>
				</div>
				<div class="card-body p-0">
					<div class="table-responsive">
						<table class="table table-sm fs-9 mb-0">
							<thead class="bg-body-tertiary">
								<tr>
									<th class="py-2 ps-3">Session</th>
									<th class="py-2 text-center">Last Viewed</th>
									<th class="py-2 text-center"># Views</th>
								</tr>
							</thead>
							<tbody>
								<?php
									foreach ($event_registrations as $event_registration):
										$event = new Event($event_registration->get('evr_evt_event_id'), TRUE);
										$searches = array();
										$searches['event_id'] = $event_registration->get('evr_evt_event_id');
										$event_sessions = new MultiEventSessions(
											$searches,
											array('evs_session_number' => 'DESC', 'evs_title' => 'DESC'));
										$event_sessions->load();

										foreach ($event_sessions as $event_session):
											if($visit_time = $event_session->get_last_visited_time_for_user($user->key)):
												$session_num = $event_session->get('evs_session_number') ? 'Session '.$event_session->get('evs_session_number'). ' - ' : '';
								?>
									<tr>
										<td class="py-2 ps-3"><?php echo htmlspecialchars($event->get('evt_name') . ' - '. $session_num . $event_session->get('evs_title')); ?></td>
										<td class="py-2 text-center"><?php echo LibraryFunctions::convert_time($visit_time, 'UTC', $session->get_timezone()); ?></td>
										<td class="py-2 text-center"><?php echo $event_session->get_number_visits_for_user($user->key); ?></td>
									</tr>
								<?php
											endif;
										endforeach;
									endforeach;
								?>
							</tbody>
						</table>
					</div>
					<?php echo $session_visits_pager->record_count_info($num_session_visits, array('show_all_url' => $show_all_url)); ?>
				</div>
			</div>

			<!-- Recent Logins -->
			<div class="card mt-3">
				<div class="card-header bg-body-tertiary">
					<h6 class="mb-0"><span class="fas fa-sign-in-alt me-2"></span>Recent Logins</h6>
				</div>
				<div class="card-body p-0">
					<div class="table-responsive">
						<table class="table table-sm fs-9 mb-0">
							<thead class="bg-body-tertiary">
								<tr>
									<th class="py-2 ps-3">Time</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($logins as $login): ?>
									<tr>
										<td class="py-2 ps-3"><?php echo LibraryFunctions::convert_time($login->log_login_time, "UTC", $session->get_timezone()); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<?php echo $logins_pager->record_count_info(count($logins), array('show_all_url' => $show_all_url)); ?>
				</div>
			</div>
		</div>
	</div>

	<?php
	//OLD CODE BELOW - REMOVED
	/*
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

			//REMOVED - SEE NEW EMAIL/LOGIN LAYOUT ABOVE
			*/

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

