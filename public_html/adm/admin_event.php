<?php

	require_once(PathHelper::getIncludePath('includes/Activation.php'));

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('data/events_class.php'));
	require_once(PathHelper::getIncludePath('data/event_registrants_class.php'));
	require_once(PathHelper::getIncludePath('data/address_class.php'));
	require_once(PathHelper::getIncludePath('data/log_form_errors_class.php'));
	require_once(PathHelper::getIncludePath('data/emails_class.php'));
	require_once(PathHelper::getIncludePath('data/email_recipients_class.php'));
	require_once(PathHelper::getIncludePath('data/event_logs_class.php'));
	require_once(PathHelper::getIncludePath('data/orders_class.php'));
	require_once(PathHelper::getIncludePath('data/messages_class.php'));
	require_once(PathHelper::getIncludePath('data/event_waiting_lists_class.php'));
	require_once(PathHelper::getIncludePath('data/locations_class.php'));
	require_once(PathHelper::getIncludePath('data/event_types_class.php'));
	require_once(PathHelper::getIncludePath('data/groups_class.php'));
	require_once(PathHelper::getIncludePath('data/surveys_class.php'));
	require_once(PathHelper::getIncludePath('data/event_sessions_class.php'));
	require_once(PathHelper::getIncludePath('data/files_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$event = new Event($_REQUEST['evt_event_id'], TRUE);

	if($_REQUEST['action'] == 'delete'){
		$event->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$event->soft_delete();

		header("Location: /admin/admin_events");
		exit();
	}
	else if($_REQUEST['action'] == 'undelete'){
		$event->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$event->undelete();

		header("Location: /admin/admin_events");
		exit();
	}

	if($_POST['action'] == 'remove_from_event'){

		$eventregistrant = new EventRegistrant($_POST['evr_event_registrant_id'], TRUE);
		$eventregistrant->remove();

		$returnurl = $session->get_return();
		header("Location: $returnurl");
		exit();
	}

	if($_POST['action'] == 'remove_from_waiting_list'){

		$waiting_list = new WaitingList($_POST['ewl_waiting_list_id'], TRUE);
		$waiting_list->remove();

		$returnurl = $session->get_return();
		header("Location: $returnurl");
		exit();
	}
/*
	$form_errors = new MultiFormError(
		array('event_id'=>$event->key),
		NULL,
		10,
		0);
	$form_errors->load();

	$phonereveals = new MultiEventLog(
		array('event_id'=>$event->key, 'event' => EventLog::SHOW_PHONE)
		);
	$numphonereveal = $phonereveals->count_all();

	$websiteclick = new MultiEventLog(
		array('event_id'=>$event->key, 'event' => EventLog::WEBSITE_CLICK)
		);
	$numwebsiteclick = $websiteclick->count_all();
	*/

	//REGISTRANTS
	$rnumperpage = 50;
	$roffset = LibraryFunctions::fetch_variable('roffset', 0, 0, '');
	$rsort = LibraryFunctions::fetch_variable('rsort', 'event_registrant_id', 0, '');
	$rsdirection = LibraryFunctions::fetch_variable('rsdirection', 'DESC', 0, '');
	$rsearchterm = LibraryFunctions::fetch_variable('rsearchterm', '', 0, '');
	$rsearch_criteria = array();
	$rsearch_criteria['event_id'] = $event->key;

	$event_registrants = new MultiEventRegistrant(
		$rsearch_criteria,
		array($rsort=>$rsdirection),
		$rnumperpage,
		$roffset
		);
	$numregistrants = $event_registrants->count_all();
	$event_registrants->load();

	$rpager = new Pager(array('numrecords'=>$numregistrants, 'numperpage'=> $rnumperpage), 'r');

	//SESSIONS
	$event_sessions = new MultiEventSessions(
		array('event_id' => $event->key),
		array('evs_session_number' => 'ASC')
	);
	$numsessions = $event_sessions->count_all();

	//WAITING LIST
	$wnumperpage = 20;
	$woffset = LibraryFunctions::fetch_variable('woffset', 0, 0, '');
	$wsort = LibraryFunctions::fetch_variable('wsort', 'ewl_waiting_list_id', 0, '');
	$wsdirection = LibraryFunctions::fetch_variable('wsdirection', 'DESC', 0, '');
	$wsearchterm = LibraryFunctions::fetch_variable('wsearchterm', '', 0, '');
	$wsearch_criteria = array();
	$wsearch_criteria['event_id'] = $event->key;
	$waiting_lists = new MultiWaitingList(
		$wsearch_criteria,
		array($wsort=>$wsdirection),
		$wnumperpage,
		$woffset);
	$numwaitinglist = $waiting_lists->count_all();
	$waiting_lists->load();
	$wpager = new Pager(array('numrecords'=>$numwaitinglist, 'numperpage'=> $wnumperpage), 'w');

	$settings = Globalvars::get_instance();
	$webDir = $settings->get_setting('webDir');

	// Build altlinks array
	$options = array();
	$options['altlinks'] = array();

	if(!$event->get('evt_delete_time')) {
		if($_SESSION['permission'] > 7){
			$options['altlinks']['Edit Event'] = '/admin/admin_event_edit?evt_event_id='.$event->key;
		}
		if($_SESSION['permission'] >= 8) {
			$options['altlinks']['Email Registrants'] = '/admin/admin_event_emails?evt_event_id='.$event->key;
			$options['altlinks']['Soft Delete'] = '/admin/admin_event?action=delete&evt_event_id='.$event->key;
		}
	}
	else {
		if($_SESSION['permission'] >= 8) {
			$options['altlinks']['Undelete'] = '/admin/admin_event?action=undelete&evt_event_id='.$event->key;
		}
	}

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

	// Load related objects for display
	$event_leader = $event->get('evt_usr_user_id_leader') ? new User($event->get('evt_usr_user_id_leader'), TRUE) : null;
	$event_location = $event->get('evt_loc_location_id') ? new Location($event->get('evt_loc_location_id'), TRUE) : null;
	$event_type = $event->get('evt_ety_event_type_id') ? new EventType($event->get('evt_ety_event_type_id'), TRUE) : null;
	$event_group = $event->get('evt_grp_group_id') ? new Group($event->get('evt_grp_group_id'), TRUE) : null;
	$event_survey = $event->get('evt_svy_survey_id') ? new Survey($event->get('evt_svy_survey_id'), TRUE) : null;
	$event_image = $event->get('evt_fil_file_id') ? new File($event->get('evt_fil_file_id'), TRUE) : null;

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
								<td class="p-1 text-600"><?php echo $event->get_timezone_corrected_time('evt_start_time', $session, 'M j, Y g:i A T'); ?></td>
							</tr>
							<tr>
								<td class="p-1" style="width: 35%;">End Date:</td>
								<td class="p-1 text-600"><?php echo $event->get_timezone_corrected_time('evt_end_time', $session, 'M j, Y g:i A T'); ?></td>
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
									<a href="/admin/admin_event_sessions?evt_event_id=<?php echo $event->key; ?>">
										<?php echo $numsessions; ?> session<?php echo $numsessions != 1 ? 's' : ''; ?>
									</a>
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
							<tr>
								<td class="p-1" style="width: 35%;">Collect Extra Info:</td>
								<td class="p-1 text-600"><?php echo $event->get('evt_collect_extra_info') ? 'Yes' : 'No'; ?></td>
							</tr>
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
			<!-- Event Image Card -->
			<?php if($event_image): ?>
			<div class="card">
				<div class="card-header bg-body-tertiary">
					<h6 class="mb-0"><span class="fas fa-image me-2"></span>Event Image</h6>
				</div>
				<div class="card-body text-center">
					<img src="<?php echo htmlspecialchars($event_image->get_url()); ?>" alt="Event Image" class="img-fluid rounded" style="max-height: 300px;">
					<?php if(!$event->get('evt_delete_time') && $_SESSION['permission'] > 7): ?>
					<div class="mt-2">
						<a href="/admin/admin_event_edit?evt_event_id=<?php echo $event->key; ?>" class="btn btn-sm btn-falcon-default">Change Image</a>
					</div>
					<?php endif; ?>
				</div>
			</div>
			<?php elseif($event->get('evt_picture_link')): ?>
			<div class="card">
				<div class="card-header bg-body-tertiary">
					<h6 class="mb-0"><span class="fas fa-image me-2"></span>Event Image</h6>
				</div>
				<div class="card-body text-center">
					<img src="<?php echo htmlspecialchars($event->get('evt_picture_link')); ?>" alt="Event Image" class="img-fluid rounded" style="max-height: 300px;">
					<?php if(!$event->get('evt_delete_time') && $_SESSION['permission'] > 7): ?>
					<div class="mt-2">
						<a href="/admin/admin_event_edit?evt_event_id=<?php echo $event->key; ?>" class="btn btn-sm btn-falcon-default">Change Image</a>
					</div>
					<?php endif; ?>
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
							<p class="<?php echo $event->get('evt_short_description') ? 'mt-3' : ''; ?>"><strong>Full Description:</strong><br>
							<?php echo nl2br(htmlspecialchars($event->get('evt_description'))); ?></p>
						<?php endif; ?>
					</div>
				</div>
			</div>
			<?php endif; ?>

			<!-- Display Settings Card -->
			<div class="card mt-3">
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
		</div>
	</div>
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
/*
		$reginfo = '';
		if($event_registrant->get('evr_recording_consent')){
			$reginfo .= 'Recording consent: '.LibraryFunctions::bool_to_english($event_registrant->get('evr_recording_consent'),"Yes", "No"). '<br />';
		}
		if(!is_null($event_registrant->get('evr_first_event'))){
			$reginfo .= '<br>First Event: '. LibraryFunctions::bool_to_english($event_registrant->get('evr_first_event'),"Yes", "No") . '<br />';
		}

		if($event_registrant->get('evr_other_events')){
			$reginfo .= '<br>Other events attended: '. $event_registrant->get('evr_other_events'). '<br />';
		}
		if($event_registrant->get('evr_health_notes')){
			$reginfo .= '<br>Health notes: '. $event_registrant->get('evr_health_notes');
		}

		if($event_registrant->get('evr_extra_info_completed') || !$event->get('evt_collect_extra_info')){
			array_push($rowvalues, $reginfo);
		}
		else{
			$act_code = Activation::CheckForActiveCode($registrant->key, Activation::EMAIL_VERIFY);
			if($act_code){
				$line = 'Not Answered <a href="/profile/event_register_finish?act_code='.$act_code->act_code.'&userid='.$registrant->key.'&eventregistrantid='.$event_registrant->key.'">link</a>';
			}
			else{
				$line = 'Not Answered';
			}
			array_push($rowvalues, $line);
		}
	*/

		$delform = '<form id="form2" class="form2" name="form2" method="POST" action="/admin/admin_event?evt_event_id='. $event->key.'">
		<input type="hidden" class="hidden" name="action" id="action" value="remove_from_event" />
		<input type="hidden" class="hidden" name="evr_event_registrant_id" id="evr_event_registrant_id" value="'.$event_registrant->key.'" />
		'.$formwriter->new_form_button('Remove', 'secondary').'
		</form>';
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

			$delform = '<form id="form2" class="form2" name="form2" method="POST" action="/admin/admin_event?evt_event_id='. $event->key.'">
			<input type="hidden" class="hidden" name="action" id="action" value="remove_from_waiting_list" />
			<input type="hidden" class="hidden" name="ewl_waiting_list_id" id="ewl_waiting_list_id" value="'.$waiting_list->key.'" />
			'.$formwriter->new_form_button('Remove', 'secondary').'
			</form>';
			array_push($rowvalues, $delform);

			$page->disprow($rowvalues);
		}

		$page->endtable($wpager);
	}

	//SESSIONS TABLE
	if($numsessions > 0) {
		$snumperpage = 20;
		$soffset = LibraryFunctions::fetch_variable('soffset', 0, 0, '');
		$ssort = LibraryFunctions::fetch_variable('ssort', 'evs_session_number', 0, '');
		$ssdirection = LibraryFunctions::fetch_variable('ssdirection', 'ASC', 0, '');

		$event_sessions_paged = new MultiEventSessions(
			array('event_id' => $event->key),
			array($ssort => $ssdirection),
			$snumperpage,
			$soffset
		);
		$event_sessions_paged->load();
		$spager = new Pager(array('numrecords'=>$numsessions, 'numperpage'=> $snumperpage), 's');

		$headers = array("Session #", "Title", "Start Time", "Duration", "Action");
		$altlinks = array();
		if(!$event->get('evt_delete_time')) {
			if($_SESSION['permission'] >= 8){
				$altlinks +=  array('Manage Sessions' => '/admin/admin_event_sessions?evt_event_id='.$event->key);
			}
		}
		$box_vars =	array(
			'altlinks' => $altlinks,
			'title' => "Sessions",
			'card' => true
		);

		$page->tableheader($headers, $box_vars, $spager);

		foreach($event_sessions_paged as $session){
			$rowvalues=array();

			// Session number
			array_push($rowvalues, $session->get('evs_session_number') ? $session->get('evs_session_number') : '—');

			// Title with link
			$title_link = '<a href="/admin/admin_event_session_edit?evs_event_session_id='.$session->key.'">'
				. htmlspecialchars($session->get('evs_title'))
				. '</a>';
			array_push($rowvalues, $title_link);

			// Start time
			if($session->get('evs_start_time')) {
				array_push($rowvalues, LibraryFunctions::convert_time($session->get('evs_start_time'), 'UTC', $session->get_timezone(), 'M j, Y g:i A'));
			} else {
				array_push($rowvalues, '—');
			}

			// Duration
			if($session->get('evs_start_time') && $session->get('evs_end_time')) {
				$start = new DateTime($session->get('evs_start_time'));
				$end = new DateTime($session->get('evs_end_time'));
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
			$action_link = '<a href="/admin/admin_event_session_edit?evs_event_session_id='.$session->key.'" class="btn btn-sm btn-falcon-default">Edit</a>';
			array_push($rowvalues, $action_link);

			$page->disprow($rowvalues);
		}

		$page->endtable($spager);
	}

	//MESSAGES
	$mnumperpage = 20;
	$moffset = LibraryFunctions::fetch_variable('moffset', 0, 0, '');
	$msort = LibraryFunctions::fetch_variable('msort', 'message_id', 0, '');
	$msdirection = LibraryFunctions::fetch_variable('msdirection', 'DESC', 0, '');
	$msearchterm = LibraryFunctions::fetch_variable('msearchterm', '', 0, '');
	$msearch_criteria = array();
	$msearch_criteria['event_id_only'] = $event->key;
	$messages = new MultiMessage(
		$msearch_criteria,
		array($msort=>$msdirection),
		$mnumperpage,
		$moffset);
	$nummessages = $messages->count_all();
	$messages->load();
	$mpager = new Pager(array('numrecords'=>$nummessages, 'numperpage'=> $mnumperpage), 'w');

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

	/*
	$pageoptions['title'] = "Emails of all registrants";
	$page->begin_box($pageoptions);
	echo '<p>'.$registrant_emails. '';
	$page->end_box();
	*/
/*
	?>

		<h2>Recurring Emails Sent</h2>

<?php

	$page->tableheader(array('Send Time', 'Email Address', 'Template'), 'recurring_mail_table');

	foreach (RecurringMailer::GetSentEmails($event->key) as $email) {
		$page->disprow(
			array(
				LibraryFunctions::FormatTimestampForEvent(new DateTime($email['ers_send_time']), $session),
				$email['ers_evt_email'],
				$email['ers_template_name'])
			);
	}

	$page->endtable();
*/
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

	?>

	<h2>Contact Emails</h2>
	<?php
	$emails = new MultiEmail(
		array('event_id' => $event->key)
		);
	$emails->load();

	$page->tableheader(
		array(
			"Subject", "Status", "Sent Date", "Recipients"
			),
		"admin_table");

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

*/

	$page->admin_footer();

?>
