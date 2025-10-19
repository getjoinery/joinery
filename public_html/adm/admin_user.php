<?php
// NO need to require PathHelper - admin pages are accessed through serve.php
// PathHelper, Globalvars, SessionControl, DbConnector, ThemeHelper, PluginHelper are ALWAYS available

// Include the logic file
require_once(PathHelper::getIncludePath('adm/logic/admin_user_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/Pager.php'));

// Process the logic and get page variables
$page_vars = process_logic(admin_user_logic($_GET, $_POST));

// Extract commonly used variables for convenience
$session = $page_vars['session'];
$settings = $page_vars['settings'];
$user = $page_vars['user'];
$show_all = $page_vars['show_all'];
$list_limit = $page_vars['list_limit'];
$phone_numbers = $page_vars['phone_numbers'];
$numphonerecords = $page_vars['numphonerecords'];
$addresses = $page_vars['addresses'];
$numaddressrecords = $page_vars['numaddressrecords'];
$orders = $page_vars['orders'];
$numorders = $page_vars['numorders'];
$event_registrations = $page_vars['event_registrations'];
$numeventsregistrations = $page_vars['numeventsregistrations'];
$active_subscriptions = $page_vars['active_subscriptions'];
$num_active_subscriptions = $page_vars['num_active_subscriptions'];
$cancelled_subscriptions = $page_vars['cancelled_subscriptions'];
$num_cancelled_subscriptions = $page_vars['num_cancelled_subscriptions'];
$logins = $page_vars['logins'];
$num_logins = $page_vars['num_logins'];
$dropdown_button = $page_vars['dropdown_button'];
$show_all_url = $page_vars['show_all_url'];
$user_subscribed_list = $page_vars['user_subscribed_list'];
$user_tier = $page_vars['user_tier'];
$tier_changes = $page_vars['tier_changes'];
$groups = $page_vars['groups'];
$num_groups = $page_vars['num_groups'];
$num_received_emails = $page_vars['num_received_emails'];
$num_sent_emails = $page_vars['num_sent_emails'];
$num_session_visits = $page_vars['num_session_visits'];

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

// AdminPage setup (display only)
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
							<?php
							require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
							foreach($tier_changes as $change): ?>
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
													require_once(PathHelper::getIncludePath('data/users_class.php'));
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
							<?php
							require_once(PathHelper::getIncludePath('data/groups_class.php'));
							require_once(PathHelper::getIncludePath('data/group_members_class.php'));
							foreach($groups as $group): ?>
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
					<?php
					require_once(PathHelper::getIncludePath('includes/StripeHelper.php'));
					foreach($active_subscriptions as $subscription): ?>
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
require_once(PathHelper::getIncludePath('data/events_class.php'));
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
require_once(PathHelper::getIncludePath('data/orders_class.php'));
require_once(PathHelper::getIncludePath('data/products_class.php'));
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
								require_once(PathHelper::getIncludePath('data/emails_class.php'));
								require_once(PathHelper::getIncludePath('data/email_recipients_class.php'));
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
								require_once(PathHelper::getIncludePath('data/event_sessions_class.php'));
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
$page->admin_footer();
?>
