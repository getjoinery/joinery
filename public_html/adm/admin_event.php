<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('adm/logic/admin_event_logic.php'));

	$page_vars = process_logic(admin_event_logic($_GET, $_POST));
	extract($page_vars);

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'events',
		'page_title' => 'Event',
		'readable_title' => $event->get('evt_name'),
		'breadcrumbs' => array(
			'Events'=>'/admin/admin_events',
			$event->get('evt_name') => '',
		),
		'session' => $session,
		'no_page_card' => true,
		'header_action' => $dropdown_button,
	)
	);
	?>

	<!-- Two Column Layout for Event Information -->
	<div class="row g-3 mb-3">
		<!-- LEFT COLUMN: Event Information -->
		<div class="col-xxl-6">
			<!-- Event Details Card -->
			<div class="card">
				<div class="card-header bg-body-tertiary">
					<h6 class="mb-0"><span class="fas fa-calendar-alt me-2"></span>Event Details</h6>
				</div>
				<div class="card-body">
					<table class="table table-borderless fs-9 fw-medium mb-0">
						<tbody>
							<tr>
								<td class="p-1" style="width: 35%;">Event Name:</td>
								<td class="p-1 text-600">
									<strong><?php echo htmlspecialchars($event->get('evt_name')); ?></strong>
									<?php if(!$event->get('evt_delete_time') && $_SESSION['permission'] > 7): ?>
										<a href="/admin/admin_event_edit?evt_event_id=<?php echo $event->key; ?>" class="fs-11 ms-2">[edit]</a>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<td class="p-1" style="width: 35%;">Start Date:</td>
								<td class="p-1 text-600"><?php echo $event->get('evt_start_time') ? LibraryFunctions::convert_time($event->get('evt_start_time'), 'UTC', $session->get_timezone(), 'M j, Y g:i A T') : '—'; ?></td>
							</tr>
							<tr>
								<td class="p-1" style="width: 35%;">End Date:</td>
								<td class="p-1 text-600"><?php echo $event->get('evt_end_time') ? LibraryFunctions::convert_time($event->get('evt_end_time'), 'UTC', $session->get_timezone(), 'M j, Y g:i A T') : '—'; ?></td>
							</tr>
							<tr>
								<td class="p-1" style="width: 35%;">Timezone:</td>
								<td class="p-1 text-600"><?php echo htmlspecialchars($event->get('evt_timezone')); ?></td>
							</tr>
							<?php if($event_location): ?>
							<tr>
								<td class="p-1" style="width: 35%;">Location:</td>
								<td class="p-1 text-600">
									<a href="/admin/admin_location?loc_location_id=<?php echo $event_location->key; ?>">
										<?php echo htmlspecialchars($event_location->get('loc_name')); ?>
									</a>
								</td>
							</tr>
							<?php elseif($event->get('evt_location')): ?>
							<tr>
								<td class="p-1" style="width: 35%;">Location:</td>
								<td class="p-1 text-600"><?php echo htmlspecialchars($event->get('evt_location')); ?></td>
							</tr>
							<?php endif; ?>
							<?php if($event_leader): ?>
							<tr>
								<td class="p-1" style="width: 35%;">Event Leader:</td>
								<td class="p-1 text-600">
									<a href="/admin/admin_user?usr_user_id=<?php echo $event_leader->key; ?>">
										<?php echo htmlspecialchars($event_leader->display_name()); ?>
									</a>
								</td>
							</tr>
							<?php endif; ?>
							<?php if($event_type): ?>
							<tr>
								<td class="p-1" style="width: 35%;">Event Type:</td>
								<td class="p-1 text-600"><?php echo htmlspecialchars($event_type->get('ety_name')); ?></td>
							</tr>
							<?php endif; ?>
							<?php if($event_group): ?>
							<tr>
								<td class="p-1" style="width: 35%;">Category:</td>
								<td class="p-1 text-600">
									<a href="/admin/admin_group?grp_group_id=<?php echo $event_group->key; ?>">
										<?php echo htmlspecialchars($event_group->get('grp_name')); ?>
									</a>
								</td>
							</tr>
							<?php endif; ?>
							<tr>
								<td class="p-1" style="width: 35%;">Created:</td>
								<td class="p-1 text-600"><?php echo LibraryFunctions::convert_time($event->get('evt_create_time'), 'UTC', $session->get_timezone(), 'M j, Y'); ?></td>
							</tr>
							<tr>
								<td class="p-1" style="width: 35%;">Status:</td>
								<td class="p-1">
									<?php if($event->get('evt_delete_time')): ?>
										<span class="badge rounded-pill badge-subtle-danger">
											<span>Deleted</span><span class="fas fa-trash ms-1" data-fa-transform="shrink-4"></span>
										</span>
									<?php else: ?>
										<span class="badge rounded-pill badge-subtle-success">
											<span>Active</span><span class="fas fa-check ms-1" data-fa-transform="shrink-4"></span>
										</span>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<td class="p-1" style="width: 35%;">Visibility:</td>
								<td class="p-1">
									<?php
									$visibility = $event->get('evt_visibility');
									if($visibility == 0):
										echo '<span class="badge rounded-pill badge-subtle-warning"><span>Private</span><span class="fas fa-lock ms-1" data-fa-transform="shrink-4"></span></span>';
									elseif($visibility == 1):
										echo '<span class="badge rounded-pill badge-subtle-info"><span>Public</span><span class="fas fa-globe ms-1" data-fa-transform="shrink-4"></span></span>';
									else:
										echo '<span class="badge rounded-pill badge-subtle-secondary"><span>Unlisted</span><span class="fas fa-eye-slash ms-1" data-fa-transform="shrink-4"></span></span>';
									endif;
									?>
								</td>
							</tr>
							<tr>
								<td class="p-1" style="width: 35%;">Event URL:</td>
								<td class="p-1 text-600">
									<a href="<?php echo $event->get_url(); ?>" target="_blank"><?php echo $event->get_url('short'); ?></a>
									<span class="fas fa-external-link-alt ms-1 fs-11"></span>
								</td>
							</tr>
							<tr>
								<td class="p-1" style="width: 35%;">Capacity:</td>
								<td class="p-1 text-600">
									<?php
									$max_signups = $event->get('evt_max_signups');
									if($max_signups):
										$remaining = $max_signups - $numregistrants;
										echo "<strong>$numregistrants / $max_signups</strong> registered ";
										if($remaining > 0):
											echo '<span class="badge badge-subtle-success ms-2">' . $remaining . ' spots remaining</span>';
										else:
											echo '<span class="badge badge-subtle-danger ms-2">Full</span>';
										endif;
									else:
										echo "<strong>$numregistrants</strong> registered (unlimited capacity)";
									endif;
									?>
								</td>
							</tr>
							<?php if($numwaitinglist > 0): ?>
							<tr>
								<td class="p-1" style="width: 35%;">Waiting List:</td>
								<td class="p-1 text-600"><?php echo $numwaitinglist; ?> people</td>
							</tr>
							<?php endif; ?>
							<?php if($numsessions > 0): ?>
							<tr>
								<td class="p-1" style="width: 35%;">Sessions:</td>
								<td class="p-1 text-600">
									<?php echo $numsessions; ?> session<?php echo $numsessions != 1 ? 's' : ''; ?>
								</td>
							</tr>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>

			<!-- Registration Settings Card -->
			<div class="card mt-3">
				<div class="card-header bg-body-tertiary">
					<h6 class="mb-0"><span class="fas fa-clipboard-check me-2"></span>Registration Settings</h6>
				</div>
				<div class="card-body">
					<table class="table table-borderless fs-9 fw-medium mb-0">
						<tbody>
							<tr>
								<td class="p-1" style="width: 35%;">Registration:</td>
								<td class="p-1">
									<?php if($event->get('evt_is_accepting_signups')): ?>
										<span class="badge rounded-pill badge-subtle-success">
											<span>Open</span><span class="fas fa-door-open ms-1" data-fa-transform="shrink-4"></span>
										</span>
									<?php else: ?>
										<span class="badge rounded-pill badge-subtle-danger">
											<span>Closed</span><span class="fas fa-door-closed ms-1" data-fa-transform="shrink-4"></span>
										</span>
									<?php endif; ?>
								</td>
							</tr>
							<?php if($event->get('evt_max_signups')): ?>
							<tr>
								<td class="p-1" style="width: 35%;">Max Signups:</td>
								<td class="p-1 text-600"><?php echo $event->get('evt_max_signups'); ?> people</td>
							</tr>
							<?php endif; ?>
							<tr>
								<td class="p-1" style="width: 35%;">Waiting List:</td>
								<td class="p-1">
									<?php if($event->get('evt_allow_waiting_list')): ?>
										<span class="badge rounded-pill badge-subtle-success"><span>Enabled</span></span>
									<?php else: ?>
										<span class="badge rounded-pill badge-subtle-secondary"><span>Disabled</span></span>
									<?php endif; ?>
								</td>
							</tr>
							<?php if($event->get('evt_external_register_link')): ?>
							<tr>
								<td class="p-1" style="width: 35%;">External Link:</td>
								<td class="p-1 text-600">
									<a href="<?php echo htmlspecialchars($event->get('evt_external_register_link')); ?>" target="_blank">
										<?php echo htmlspecialchars($event->get('evt_external_register_link')); ?>
									</a>
									<span class="fas fa-external-link-alt ms-1 fs-11"></span>
								</td>
							</tr>
							<?php endif; ?>
							<?php if($event_survey): ?>
							<tr>
								<td class="p-1" style="width: 35%;">Survey:</td>
								<td class="p-1 text-600">
									<a href="/admin/admin_survey?svy_survey_id=<?php echo $event_survey->key; ?>">
										<?php echo htmlspecialchars($event_survey->get('svy_title')); ?>
									</a>
									<?php if($event->get('evt_survey_required')): ?>
										<span class="badge badge-subtle-warning ms-2">Required</span>
									<?php endif; ?>
								</td>
							</tr>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>

		</div>

		<!-- RIGHT COLUMN: Media & Settings -->
		<div class="col-xxl-6">
			<!-- Display Settings Card -->
			<div class="card">
				<div class="card-header bg-body-tertiary">
					<h6 class="mb-0"><span class="fas fa-cog me-2"></span>Display Settings</h6>
				</div>
				<div class="card-body">
					<table class="table table-borderless fs-9 fw-medium mb-0">
						<tbody>
							<?php if($event->get('evt_session_display_type') !== null): ?>
							<tr>
								<td class="p-1" style="width: 35%;">Session Display:</td>
								<td class="p-1 text-600">
									<?php
									switch($event->get('evt_session_display_type')){
										case 0: echo 'Standard View'; break;
										case 1: echo 'Condensed View'; break;
										default: echo 'Type ' . $event->get('evt_session_display_type');
									}
									?>
								</td>
							</tr>
							<?php endif; ?>
							<tr>
								<td class="p-1" style="width: 35%;">Show Calendar Link:</td>
								<td class="p-1">
									<?php if($event->get('evt_show_add_to_calendar_link')): ?>
										<span class="badge rounded-pill badge-subtle-success"><span>Enabled</span></span>
									<?php else: ?>
										<span class="badge rounded-pill badge-subtle-secondary"><span>Disabled</span></span>
									<?php endif; ?>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>

			<!-- Event Photos Card -->
			<?php
			require_once(PathHelper::getIncludePath('includes/PhotoHelper.php'));
			$photo_editable = !$event->get('evt_delete_time') && $_SESSION['permission'] > 7;
			PhotoHelper::render_photo_card('grid', 'event', $event->key, $event_photos, [
				'set_primary_url' => '/admin/admin_event?evt_event_id=' . $event->key,
				'card_title' => 'Event Photos',
				'editable' => $photo_editable,
				'primary_file_id' => $event->get('evt_fil_file_id'),
			]);
			?>
			<?php if(count($event_photos) == 0 && $event->get('evt_picture_link')): ?>
			<!-- Legacy external image fallback -->
			<div class="card mt-3">
				<div class="card-body text-center">
					<img src="<?php echo htmlspecialchars($event->get('evt_picture_link')); ?>" alt="Event Image" class="img-fluid rounded" style="max-height: 300px;">
					<div class="mt-2 text-muted fs-10">External image link</div>
				</div>
			</div>
			<?php endif; ?>

			<!-- Event Description Card -->
			<?php if($event->get('evt_description') || $event->get('evt_short_description')): ?>
			<div class="card mt-3">
				<div class="card-header bg-body-tertiary">
					<h6 class="mb-0"><span class="fas fa-align-left me-2"></span>Description</h6>
				</div>
				<div class="card-body">
					<div class="fs-9 text-600">
						<?php if($event->get('evt_short_description')): ?>
							<p><strong>Short Description:</strong><br>
							<?php echo nl2br(htmlspecialchars($event->get('evt_short_description'))); ?></p>
						<?php endif; ?>

						<?php if($event->get('evt_description')): ?>
							<p class="<?php echo $event->get('evt_short_description') ? 'mt-3' : ''; ?>"><strong>Full Description:</strong></p>
							<div><?php echo $event->get('evt_description'); ?></div>
						<?php endif; ?>
					</div>
				</div>
			</div>
			<?php endif; ?>

		</div>
	</div>
	<?php

	// ===== Series Occurrences Card (only for recurring parents) =====
	if ($event->is_recurring_parent()):
		$recurrence_desc = $event->get_recurrence_description();
		$occurrence_dates = $event->compute_occurrence_dates(date('Y-m-d'), 20);
		$materialized_instances = $event->get_materialized_instances();
		$materialized_by_date = [];
		if (is_array($materialized_instances)) {
			foreach ($materialized_instances as $mi) {
				$materialized_by_date[$mi->get('evt_materialized_instance_date')] = $mi;
			}
		} else {
			foreach ($materialized_instances as $mi) {
				$materialized_by_date[$mi->get('evt_materialized_instance_date')] = $mi;
			}
		}
	?>
	<div class="card mb-3">
		<div class="card-header bg-body-tertiary d-flex justify-content-between align-items-center">
			<h6 class="mb-0"><span class="fas fa-sync me-2"></span>Series Occurrences</h6>
			<div>
				<a href="/admin/admin_event_edit?evt_event_id=<?php echo $event->key; ?>" class="btn btn-sm btn-soft-default me-2">Edit Series</a>
				<?php echo AdminPage::action_button('End Series', '/admin/admin_event', [
					'hidden'  => ['action' => 'end_series', 'evt_event_id' => $event->key],
					'confirm' => 'End this recurring series? Future virtual instances will stop appearing.',
					'class'   => 'btn btn-sm btn-danger',
				]); ?>
			</div>
		</div>
		<div class="card-body">
			<p class="mb-3 text-600"><strong>Pattern:</strong> <?php echo htmlspecialchars($recurrence_desc); ?></p>

			<?php if (!empty($occurrence_dates)): ?>
			<div class="table-responsive">
				<table class="table table-sm table-hover fs-9 mb-0">
					<thead>
						<tr>
							<th>Date</th>
							<th>Status</th>
							<th>Registrants</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($occurrence_dates as $occ_date):
							$is_materialized = isset($materialized_by_date[$occ_date]);
							$mat_instance = $is_materialized ? $materialized_by_date[$occ_date] : null;
							$is_cancelled = $mat_instance && $mat_instance->get('evt_status') == Event::STATUS_CANCELED;
						?>
						<tr>
							<td><?php echo date('M j, Y (D)', strtotime($occ_date)); ?></td>
							<td>
								<?php if ($is_cancelled): ?>
									<span class="badge badge-subtle-danger">Cancelled</span>
								<?php elseif ($is_materialized): ?>
									<span class="badge badge-subtle-success">Customized</span>
								<?php else: ?>
									<span class="badge badge-subtle-secondary">Scheduled</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ($is_materialized && !$is_cancelled):
									$inst_regs = new MultiEventRegistrant(['event_id' => $mat_instance->key, 'expired' => false]);
									$inst_reg_count = $inst_regs->count_all();
									$max = $mat_instance->get('evt_max_signups');
									echo $max ? $inst_reg_count . '/' . $max : $inst_reg_count;
								else:
									echo '—';
								endif; ?>
							</td>
							<td>
								<?php if ($is_cancelled): ?>
									—
								<?php elseif ($is_materialized): ?>
									<a href="/admin/admin_event?evt_event_id=<?php echo $mat_instance->key; ?>" class="btn btn-sm btn-soft-default py-0 px-2">View</a>
									<a href="/admin/admin_event_edit?evt_event_id=<?php echo $mat_instance->key; ?>" class="btn btn-sm btn-soft-default py-0 px-2">Edit</a>
								<?php else: ?>
									<a href="/admin/admin_event_edit?parent_event_id=<?php echo $event->key; ?>&instance_date=<?php echo $occ_date; ?>" class="btn btn-sm btn-soft-primary py-0 px-2">Edit</a>
									<?php echo AdminPage::action_button('Cancel', '/admin/admin_event', [
										'hidden'  => ['action' => 'cancel_instance', 'instance_date' => $occ_date, 'evt_event_id' => $event->key],
										'confirm' => 'Cancel this occurrence?',
										'class'   => 'btn btn-sm btn-danger py-0 px-2',
									]); ?>
								<?php endif; ?>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php else: ?>
				<p class="text-muted mb-0">No upcoming occurrences.</p>
			<?php endif; ?>
		</div>
	</div>
	<?php endif; ?>

	<?php
	// ===== Instance Parent Link (only for materialized instances) =====
	if ($event->is_instance()):
		$parent_event = $event->get_parent_event();
		if ($parent_event):
	?>
	<div class="card mb-3">
		<div class="card-body">
			<span class="fas fa-sync me-2 text-muted"></span>
			This is a customized occurrence (<?php echo date('M j, Y', strtotime($event->get('evt_materialized_instance_date'))); ?>) of the recurring series
			<a href="/admin/admin_event?evt_event_id=<?php echo $parent_event->key; ?>"><strong><?php echo htmlspecialchars($parent_event->get('evt_name')); ?></strong></a>.
		</div>
	</div>
	<?php
		endif;
	endif;
	?>

	<?php
	$headers = array("Registrant", "Registered on", "Order", "Email Verified",  "Expires", "Action");
	$altlinks = array();
	if(!$event->get('evt_delete_time')) {
		if($_SESSION['permission'] >= 8){
			$altlinks +=  array('Email registrants' => '/admin/admin_users_message?evt_event_id='.$event->key);
		}
	}
	$box_vars =	array(
		'altlinks' => $altlinks,
		'title' => "Registrants",
		'card' => true
	);
	$page->tableheader($headers, $box_vars, $rpager);

	// Initialize FormWriter for form buttons
	$formwriter = $page->getFormWriter('form2');

	$registrant_emails = '';
	foreach($event_registrants as $event_registrant){

		$registrant = new User($event_registrant->get('evr_usr_user_id'), TRUE);

		$registrant_emails .= $registrant->display_name() . ' &lt;'.$registrant->get('usr_email'). '&gt;, ';

		$rowvalues=array();
		array_push($rowvalues, '<a href="/admin/admin_user?usr_user_id='. $registrant->key. '">'.$registrant->display_name() . '</a>');
		array_push($rowvalues, LibraryFunctions::convert_time($event_registrant->get('evr_create_time'), 'UTC', $session->get_timezone()));
		//array_push($rowvalues, LibraryFunctions::bool_to_english($registrant->get('evr_is_default'), "Default", " dsasd"));
		//array_push($rowvalues, LibraryFunctions::bool_to_english($registrant->get('evr_is_verified'), "Verified", 'Not Verified [<a class="sortlink" href="/admin/admin_phone_verify?evr_registrant_id='. $registrant->key. '">Verify</a>]'));

		if($event_registrant->get('evr_ord_order_id')){
			$order = new Order($event_registrant->get('evr_ord_order_id'), TRUE);
		}

		$order_items = new MultiOrderItem(array('registrant_id' => $event_registrant->key));
		$order_items->load();

		$row = '';
		$total_paid = 0;
		foreach ($order_items as $order_item){
			$row .= '<a href="/admin/admin_order?ord_order_id=' . $order_item->get('odi_ord_order_id') . '">Order# '.$order_item->get('odi_ord_order_id').'</a> ($'. $order_item->get('odi_price'). ')';
			//ADD AN ASTERISK IF THE ORDER HAS A REFUND
			$order = $order_item->get_order();
			if($order->get('ord_refund_amount')){
				$row .= '*';
			}
			$row .= '<br>';
		}
		array_push($rowvalues, $row);

		$evr_verified = LibraryFunctions::bool_to_english($registrant->get('usr_email_is_verified'),"Verified", "Unverified");
		array_push($rowvalues, $evr_verified);

		if($event_registrant->get('evr_expires_time') && $event_registrant->get('evr_expires_time') < date("Y-m-d H:i:s")){
			array_push($rowvalues, 'Expired: '.LibraryFunctions::convert_time($event_registrant->get('evr_expires_time'), 'UTC', $session->get_timezone()));
		}
		else{
			array_push($rowvalues, LibraryFunctions::convert_time($event_registrant->get('evr_expires_time'), 'UTC', $session->get_timezone()));
		}
		$delform = AdminPage::action_button('Remove', '/admin/admin_event', [
			'hidden'  => ['action' => 'remove_from_event', 'evr_event_registrant_id' => $event_registrant->key, 'evt_event_id' => $event->key],
			'confirm' => 'Remove this registrant from the event?',
		]);
		array_push($rowvalues, $delform);

        $page->disprow($rowvalues);
	}

	$page->endtable($rpager);

	if($numwaitinglist){

		$headers = array("User", "Registered on", "Action");
		$altlinks = array();
		if(!$event->get('evt_delete_time')) {
			if($_SESSION['permission'] >= 8){
				$altlinks +=  array('Email waiting list' => '/admin/admin_users_message?waiting_list=1&evt_event_id='.$event->key);
			}
		}
		$box_vars =	array(
			'altlinks' => $altlinks,
			'title' => "Waiting List",
			'card' => true
		);
		$page->tableheader($headers, $box_vars, $wpager);

		foreach($waiting_lists as $waiting_list){

			$registrant = new User($waiting_list->get('ewl_usr_user_id'), TRUE);

			$rowvalues=array();
			array_push($rowvalues, '<a href="/admin/admin_user?usr_user_id='. $registrant->key. '">'.$registrant->display_name() . '</a>');
			array_push($rowvalues, LibraryFunctions::convert_time($waiting_list->get('ewl_create_time'), 'UTC', $session->get_timezone()));

			$delform = AdminPage::action_button('Remove', '/admin/admin_event', [
				'hidden'  => ['action' => 'remove_from_waiting_list', 'ewl_waiting_list_id' => $waiting_list->key, 'evt_event_id' => $event->key],
				'confirm' => 'Remove from waiting list?',
			]);
			array_push($rowvalues, $delform);

			$page->disprow($rowvalues);
		}

		$page->endtable($wpager);
	}

	//SESSIONS TABLE
	$headers = array("Session #", "Title", "Start Time", "Duration", "Action");
	$altlinks = array();
	if(!$event->get('evt_delete_time')) {
		if($_SESSION['permission'] >= 8){
			$altlinks +=  array('Create Session' => '/admin/admin_event_session_edit?evt_event_id='.$event->key);
		}
	}
	$box_vars =	array(
		'altlinks' => $altlinks,
		'title' => "Sessions",
		'card' => true
	);

	$page->tableheader($headers, $box_vars, $spager);

	foreach($event_sessions_paged as $event_session){
		$rowvalues=array();

		// Session number
		array_push($rowvalues, $event_session->get('evs_session_number') ? $event_session->get('evs_session_number') : '—');

		// Title with link
		$title_link = '<a href="/admin/admin_event_session_edit?evs_event_session_id='.$event_session->key.'">'
			. htmlspecialchars($event_session->get('evs_title'))
			. '</a>';
		array_push($rowvalues, $title_link);

		// Start time
		if($event_session->get('evs_start_time')) {
			array_push($rowvalues, LibraryFunctions::convert_time($event_session->get('evs_start_time'), 'UTC', $session->get_timezone(), 'M j, Y g:i A'));
		} else {
			array_push($rowvalues, '—');
		}

		// Duration
		if($event_session->get('evs_start_time') && $event_session->get('evs_end_time')) {
			$start = new DateTime($event_session->get('evs_start_time'));
			$end = new DateTime($event_session->get('evs_end_time'));
			$diff = $start->diff($end);

			if($diff->h > 0) {
				$duration = $diff->h . ' hour' . ($diff->h != 1 ? 's' : '');
				if($diff->i > 0) {
					$duration .= ', ' . $diff->i . ' min';
				}
			} else {
				$duration = $diff->i . ' minutes';
			}
			array_push($rowvalues, $duration);
		} else {
			array_push($rowvalues, '—');
		}

		// Action
		$action_link = '<a href="/admin/admin_event_session_edit?evs_event_session_id='.$event_session->key.'" class="btn btn-sm btn-soft-default">Edit</a> ';
		$action_link .= '<a href="/admin/admin_event_session_edit?action=delete&evs_event_session_id='.$event_session->key.'" class="btn btn-sm btn-soft-danger" onclick="return confirm(\'Are you sure you want to delete this session?\')">Delete</a>';
		array_push($rowvalues, $action_link);

		$page->disprow($rowvalues);
	}

	$page->endtable($spager);

	//MESSAGES
	$headers = array("Sender", "Message", "Time");
	$altlinks = array();
	if(!$event->get('evt_delete_time')) {
		if($_SESSION['permission'] >= 8){
			$altlinks +=  array('Send message' => '/admin/admin_users_message?evt_event_id='.$event->key);
		}
	}
	$box_vars =	array(
		'altlinks' => $altlinks,
		'title' => "Messages to Registrants",
		'card' => true
	);

	$page->tableheader($headers, $box_vars, $mpager);

	foreach($messages as $message){
		$user = new User($message->get('msg_usr_user_id_sender'), TRUE);

		$rowvalues=array();
		array_push($rowvalues, $user->display_name());
		array_push($rowvalues, '<a href="/admin/admin_message?msg_message_id='.$message->key.'">'.$message->display_title(). '...</a>');
		array_push($rowvalues, LibraryFunctions::convert_time($message->get('msg_sent_time'), 'UTC', $session->get_timezone()));
        $page->disprow($rowvalues);
	}

	$page->endtable($mpager);

	?>
	<?php if($photo_editable): ?>
	<?php PhotoHelper::render_photo_scripts('grid', 'event', $event->key, [
		'set_primary_url' => '/admin/admin_event?evt_event_id=' . $event->key,
		'confirm_delete_msg' => 'Remove this photo from the event?',
	]); ?>
	<?php endif; ?>
	<?php

	$page->admin_footer();

?>
