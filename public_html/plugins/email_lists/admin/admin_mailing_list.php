<?php
/** @joinery-admin class=personal */

	require_once(PathHelper::getIncludePath('includes/Activation.php'));

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('data/files_class.php'));

	require_once(PathHelper::getIncludePath('plugins/email_lists/admin/logic/admin_mailing_list_logic.php'));

	$page_vars = process_logic(admin_mailing_list_logic(array_merge($_GET, $_POST)));

	extract($page_vars);

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'mailing-lists',
		'page_title' => 'Mailing List',
		'readable_title' => $mailing_list->get('mlt_name'),
		'breadcrumbs' => array(
			'Mailing Lists'=>'/plugins/email_lists/admin/admin_mailing_lists',
			$mailing_list->get('mlt_name') => '',
		),
		'session' => $session,
		'no_page_card' => true,
		'header_action' => $dropdown_button,
	)
	);
	?>

	<!-- Two Column Layout -->
	<div class="row g-3 mb-3">
		<!-- Left Column -->
		<div class="col-xxl-6">
			<!-- Mailing List Information Card -->
			<div class="card mb-3">
				<div class="card-header bg-body-tertiary">
					<h5 class="mb-0">Mailing List Information</h5>
				</div>
				<div class="card-body">
					<table class="table table-borderless mb-0" style="font-size: 0.875rem;">
						<tbody>
							<tr>
								<td class="p-1 text-800 fw-semi-bold" style="width: 180px;">Name</td>
								<td class="p-1 text-600"><?php echo htmlspecialchars($mailing_list->get('mlt_name')); ?></td>
							</tr>
							<?php if($mailing_list->get('mlt_description')): ?>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Description</td>
								<td class="p-1 text-600"><?php echo htmlspecialchars($mailing_list->get('mlt_description')); ?></td>
							</tr>
							<?php endif; ?>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Status</td>
								<td class="p-1">
									<?php if($mailing_list->get('mlt_delete_time')): ?>
										<span class="badge badge-danger">Deleted at <?php echo $mailing_list->get_local('mlt_delete_time'); ?></span>
									<?php else: ?>
										<span class="badge badge-subtle-success">Active</span>
									<?php endif; ?>
								</td>
							</tr>
							<?php if(!$mailing_list->get('mlt_delete_time')): ?>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Visibility</td>
								<td class="p-1">
									<?php if($mailing_list->get('mlt_visibility') == MailingList::VISIBILITY_PUBLIC_UNLISTED): ?>
										<span class="badge badge-subtle-info">Public</span> <a href="<?php echo htmlspecialchars($mailing_list->get_url()); ?>" class="ms-2 fs-11"><?php echo htmlspecialchars(LibraryFunctions::get_absolute_url($mailing_list->get_url())); ?></a>
									<?php elseif($mailing_list->get('mlt_visibility') == MailingList::VISIBILITY_PUBLIC): ?>
										<span class="badge badge-subtle-info">Public</span> <a href="<?php echo htmlspecialchars($mailing_list->get_url()); ?>" class="ms-2 fs-11"><?php echo htmlspecialchars(LibraryFunctions::get_absolute_url($mailing_list->get_url())); ?></a>
									<?php else: ?>
										<span class="badge badge-subtle-secondary">Hidden</span>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Subscribed Users</td>
								<td class="p-1 text-600"><?php echo $mailing_list->count_subscribed_users(); ?></td>
							</tr>
							<?php endif; ?>
							<?php if($mailing_list->get('mlt_create_time')): ?>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Created</td>
								<td class="p-1 text-600"><?php echo $mailing_list->get_local('mlt_create_time', 'M j, Y g:i A T'); ?></td>
							</tr>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>

		<!-- Right Column -->
		<div class="col-xxl-6">
			<!-- Integration Settings Card -->
			<div class="card mb-3">
				<div class="card-header bg-body-tertiary">
					<h5 class="mb-0">Integration Settings</h5>
				</div>
				<div class="card-body">
					<table class="table table-borderless mb-0" style="font-size: 0.875rem;">
						<tbody>
							<?php if($mailing_list->get('mlt_emt_email_template_id')): ?>
							<tr>
								<td class="p-1 text-800 fw-semi-bold" style="width: 180px;">Welcome Email Template</td>
								<td class="p-1 text-600"><a href="/admin/admin_email_template?emt_email_template_id=<?php echo $mailing_list->get('mlt_emt_email_template_id'); ?>">View Template</a></td>
							</tr>
							<?php endif; ?>
							<?php if($mailing_list->get('mlt_fil_file_id')): ?>
								<?php $file = new File($mailing_list->get('mlt_fil_file_id'), true); ?>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Attached File</td>
								<td class="p-1 text-600"><a href="/admin/admin_file?fil_file_id=<?php echo $file->key; ?>"><?php echo htmlspecialchars($file->get('fil_name')); ?></a></td>
							</tr>
							<?php endif; ?>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Mailing List Integration</td>
								<td class="p-1">
									<?php if($mailing_list->get('mlt_provider_list_id')): ?>
										<span class="badge badge-subtle-success">Active</span>
									<?php else: ?>
										<span class="badge badge-subtle-secondary">Inactive</span>
									<?php endif; ?>
								</td>
							</tr>
							<?php if($mailing_list->get('mlt_provider_list_id')): ?>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Remote List ID</td>
								<td class="p-1 text-600"><code><?php echo htmlspecialchars($mailing_list->get('mlt_provider_list_id')); ?></code></td>
							</tr>
							<?php endif; ?>
							<?php if($mailing_list->get('mlt_ctt_contact_type_id')): ?>
							<tr>
								<td class="p-1 text-800 fw-semi-bold">Contact Type</td>
								<td class="p-1 text-600"><?php echo htmlspecialchars($mailing_list->get('mlt_ctt_contact_type_id')); ?></td>
							</tr>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
	</div>

	<?php
	$headers = array("Users",  "Action");

	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	$altlinks = array();
	$box_vars = array(
		'altlinks' => $altlinks,
		'title' => 'Subscribed Users',
		'card' => true
	);
	$page->tableheader($headers, $box_vars, $pager);

	foreach($registrants as $registrant){
		$user = new User($registrant->get('mlr_usr_user_id'), TRUE);
		$rowvalues=array();
		// A member deleted while the plugin was off leaves the row behind.
		if (!$user->key) {
			$page->disprow(array('<span class="text-muted">(deleted account)</span>', ''));
			continue;
		}

		$consent = $registrant->get('mlr_confirm_source') ? ' <small class="text-muted">(' . htmlspecialchars((string)$registrant->get('mlr_confirm_source'))
			. ($registrant->get('mlr_confirm_by_usr_user_id') ? ' by user ' . (int)$registrant->get('mlr_confirm_by_usr_user_id') : '') . ')</small>' : '';
		// Consent follows the address: changed without proof, nothing is sent
		// until the new address confirms.
		if (!$mailing_list->is_consented($user->key)) {
			$consent .= ' <small class="text-warning">address changed, waiting to confirm</small>';
		}
		array_push($rowvalues, htmlspecialchars($user->display_name()) . $consent);

		$delform = AdminPage::action_button('Delete', '/plugins/email_lists/admin/admin_mailing_list', [
			'hidden'  => ['action' => 'removeregistrant', 'mlr_mailing_list_registrant_id' => $registrant->key, 'mlt_mailing_list_id' => $mailing_list->key],
			'model'   => $registrant,
			'verb'    => 'soft_delete',
			'confirm' => 'Remove this subscriber?',
		]);
		array_push($rowvalues, $delform);

		$page->disprow($rowvalues);

	}

	$page->endtable($pager);

	// People who asked and have not confirmed (spec email_lists_plugin §10).
	$page->tableheader(array('Address', 'Asked', 'From', 'Action'), array(
		'title' => 'Waiting to confirm (' . (int)$page_vars['pending_count'] . ')',
		'card' => true,
	));
	foreach ($page_vars['pending'] as $row) {
		$hidden = array('mlt_mailing_list_id' => $mailing_list->key, 'mlp_mailing_list_pending_id' => $row->key);
		$actions = AdminPage::action_button('Resend', '/plugins/email_lists/admin/admin_mailing_list', array('hidden' => $hidden + array('action' => 'pending_resend')))
			. ' ' . AdminPage::action_button('Mark confirmed', '/plugins/email_lists/admin/admin_mailing_list', array(
				'hidden' => $hidden + array('action' => 'pending_confirm'),
				'confirm' => 'Mark this person confirmed? Do this only for consent they gave you another way (a signed form, say); it is recorded under your name.'))
			. ' ' . AdminPage::action_button('Delete', '/plugins/email_lists/admin/admin_mailing_list', array(
				'hidden' => $hidden + array('action' => 'pending_delete'), 'confirm' => 'Delete this request?'));
		$page->disprow(array(
			htmlspecialchars((string)$row->get('mlp_email')),
			htmlspecialchars(LibraryFunctions::convert_time($row->get('mlp_request_time'), 'UTC', $session->get_timezone())),
			htmlspecialchars((string)$row->get('mlp_source')),
			$actions,
		));
	}
	$page->endtable();

	$page->admin_footer();
?>
