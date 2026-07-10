<?php
// NO need to require PathHelper - admin pages are accessed through serve.php
// PathHelper, Globalvars, SessionControl, DbConnector, ThemeHelper, PluginHelper are ALWAYS available

// Include the logic file
require_once(PathHelper::getIncludePath('adm/logic/admin_user_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/Pager.php'));

// Process the logic and get page variables
$page_vars = process_logic(admin_user_logic(array_merge($_GET, $_POST)));

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

// Orders, subscriptions and event registrations are rendered by plugin-owned
// panels (AdminUserPanelRegistry) further down.
require_once(PathHelper::getIncludePath('includes/AdminUserPanelRegistry.php'));

// Create Pager objects for record count display
$groups_pager = new Pager(array('numrecords' => $num_groups, 'numperpage' => $list_limit ?: $num_groups));
$received_emails_pager = new Pager(array('numrecords' => $num_received_emails, 'numperpage' => $list_limit ?: $num_received_emails));
$sent_emails_pager = new Pager(array('numrecords' => $num_sent_emails, 'numperpage' => $list_limit ?: $num_sent_emails));
$logins_pager = new Pager(array('numrecords' => $num_logins, 'numperpage' => $list_limit ?: $num_logins));

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
				<table class="table table-borderless fw-medium mb-0">
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

		<!-- Mailing Lists Card -->
		<?php if(!empty($user_subscribed_list)): ?>
		<div class="card mt-3">
			<div class="card-header bg-body-tertiary">
				<h6 class="mb-0"><span class="fas fa-envelope-open me-2"></span>Mailing List Subscriptions</h6>
			</div>
			<div class="card-body">
				<p class="mb-0">This user is subscribed to: <strong><?php echo implode(', ', $user_subscribed_list); ?></strong></p>
			</div>
		</div>
		<?php endif; ?>

		<!-- Groups Card -->
		<div class="card mt-3">
			<div class="card-header bg-body-tertiary">
				<h6 class="mb-0"><span class="fas fa-users me-2"></span>Groups</h6>
			</div>
			<div class="card-body p-0">
				<div class="table-responsive">
					<table class="table mb-0">
						<thead>
							<tr>
								<th>Group</th>
								<th class="text-end">Action</th>
							</tr>
						</thead>
						<tbody>
							<?php
							require_once(PathHelper::getIncludePath('data/groups_class.php'));
							require_once(PathHelper::getIncludePath('data/group_members_class.php'));
							foreach($groups as $group): ?>
								<?php $groupmember = $group->is_member_in_group($user->key); ?>
								<tr>
									<td><?php echo htmlspecialchars($group->get('grp_name')); ?></td>
									<td class="text-end">
										<?php echo AdminPage::action_button('Remove', '/admin/admin_user', [
											'hidden'  => ['action' => 'remove_from_group', 'grm_group_member_id' => $groupmember->key, 'usr_user_id' => $user->key],
											'confirm' => 'Remove user from this group?',
										]); ?>
									</td>
								</tr>
							<?php endforeach; ?>
							<tr>
								<td colspan="2" class="pt-3">
									<?php
										$formwriter = $page->getFormWriter('form5', [
											'deferred_output' => true
										]);

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
										$formwriter->hiddeninput('action', '', ['value' => 'add_to_group']);
										$formwriter->hiddeninput('usr_user_id', '', ['value' => $user->key]);
										$formwriter->dropinput('grp_group_id', 'Add to group', [
											'options' => $optionvals,
											'validation' => ['required' => true]
										]);
										$formwriter->submitbutton('submit_button', 'Add');
										echo $formwriter->getFieldsHTML();
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
		<!-- Subscription Tier Card -->
		<div class="card">
			<div class="card-header bg-body-tertiary">
				<h6 class="mb-0"><span class="fas fa-star me-2"></span>Subscription Tier</h6>
			</div>
			<div class="card-body">
				<table class="table table-borderless fw-medium mb-0">
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
										} catch (Exception $e) { /* Tier may have been deleted */ }
									}
								?>
								<div class="mb-1">
									• <?php echo $change_time; ?>: <?php echo $old_value; ?> → <?php echo $new_value; ?>
									<?php if($reason): ?>
										(<?php echo htmlspecialchars($reason); ?>
										<?php if($reason === 'purchase' && $change->get('cht_reference_id') && PluginHelper::isPluginActive('store')): ?>
											- <a href="/plugins/store/admin/admin_order?ord_order_id=<?php echo $change->get('cht_reference_id'); ?>">Order #<?php echo $change->get('cht_reference_id'); ?></a>
										<?php elseif($reason === 'manual' && $change->get('cht_changed_by_usr_user_id')): ?>
											<?php
												try {
													require_once(PathHelper::getIncludePath('data/users_class.php'));
													$changed_by = new User($change->get('cht_changed_by_usr_user_id'), TRUE);
													echo ' by ' . htmlspecialchars($changed_by->display_name());
												} catch (Exception $e) { /* User may have been deleted */ }
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

	</div>
</div>

<?php
// ---- Plugin-contributed panels: Orders, Subscriptions (store), Events (event_manager) ----
// Each panel renders its own section and loads its own data; absent when the
// owning plugin is inactive.
foreach (AdminUserPanelRegistry::panels() as $panel) {
	echo $panel->render($user, $page, array(
		'show_all'     => $show_all,
		'list_limit'   => $list_limit,
		'show_all_url' => $page_vars['show_all_url'],
	));
}
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
					<table class="table mb-0">
						<thead>
							<tr>
								<th>Subject</th>
								<th class="text-center">Status</th>
								<th class="text-center">Sent Date</th>
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
									<td>
										<a href="/admin/admin_email_view?eml_email_id=<?php echo $email->key; ?>">
											<?php echo htmlspecialchars($email->get('eml_subject')); ?>
										</a>
									</td>
									<td class="text-center fs-11"><?php echo htmlspecialchars($email->get_status_text()); ?></td>
									<td class="text-center fs-11"><?php echo LibraryFunctions::convert_time($email->get('eml_sent_time'), "UTC", $session->get_timezone(), 'M j'); ?></td>
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
					<table class="table mb-0">
						<thead>
							<tr>
								<th>Subject</th>
								<th class="text-center">Status</th>
								<th class="text-center">Sent Date</th>
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
									<td><?php echo htmlspecialchars($email->get('eml_subject')); ?></td>
									<td class="text-center fs-11"><?php echo htmlspecialchars($email->get_status_text()); ?></td>
									<td class="text-center fs-11"><?php echo LibraryFunctions::convert_time($email->get('eml_sent_time'), "UTC", $session->get_timezone(), 'M j'); ?></td>
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

	<!-- Right Column: Logins -->
	<div class="col-lg-6">
		<!-- Recent Logins -->
		<div class="card">
			<div class="card-header bg-body-tertiary">
				<h6 class="mb-0"><span class="fas fa-sign-in-alt me-2"></span>Recent Logins</h6>
			</div>
			<div class="card-body p-0">
				<div class="table-responsive">
					<table class="table mb-0">
						<thead>
							<tr>
								<th>Time</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($logins as $login): ?>
								<tr>
									<td><?php echo LibraryFunctions::convert_time($login->log_login_time, "UTC", $session->get_timezone()); ?></td>
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
