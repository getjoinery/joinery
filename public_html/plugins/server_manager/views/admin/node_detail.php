<?php
/**
 * Server Manager - Node Detail
 * URL: /admin/server_manager/node_detail?mgn_id=N&tab=overview
 *
 * Consolidated node management page with tabs:
 * Overview, Backups, Database, Updates, Jobs
 *
 * @version 1.1 - Forms converted to FormWriter
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/Pager.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobResultProcessor.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);
$session->set_return();

// Load node
$mgn_id = isset($_POST['edit_primary_key_value']) && $_POST['edit_primary_key_value']
	? intval($_POST['edit_primary_key_value'])
	: (isset($_GET['mgn_id']) ? intval($_GET['mgn_id']) : 0);

if (!$mgn_id) {
	header('Location: /admin/server_manager');
	exit;
}

try {
	$node = new ManagedNode($mgn_id, TRUE);
} catch (Exception $e) {
	header('Location: /admin/server_manager');
	exit;
}

$tab = isset($_GET['tab']) ? $_GET['tab'] : 'overview';
$skip_joinery = $node->get('mgn_skip_joinery_checks');
$valid_tabs = $skip_joinery
	? ['overview', 'jobs', 'api_keys']
	: ['overview', 'backups', 'database', 'updates', 'jobs', 'api_keys'];
if (!in_array($tab, $valid_tabs)) {
	$tab = 'overview';
}

$page_regex = '/\/admin\/server_manager/';
$base_url = '/admin/server_manager/node_detail?mgn_id=' . $node->key;

// ── POST action handlers ──

if ($_POST && isset($_POST['action'])) {
	$action = $_POST['action'];

	// Overview actions
	if ($action === 'check_status') {
		$steps = JobCommandBuilder::build_check_status($node);
		$job = ManagementJob::createJob($node->key, 'check_status', $steps, null, $session->get_user_id());
		header('Location: /admin/server_manager/job_detail?job_id=' . $job->key);
		exit;
	}

	// Backup actions
	if ($action === 'backup_database') {
		$params = ['encryption' => !empty($_POST['encryption'])];
		$steps = JobCommandBuilder::build_backup_database($node, $params);
		$job = ManagementJob::createJob($node->key, 'backup_database', $steps, $params, $session->get_user_id());
		header('Location: /admin/server_manager/job_detail?job_id=' . $job->key);
		exit;
	}

	if ($action === 'backup_project') {
		$params = ['encryption' => !empty($_POST['encryption'])];
		$steps = JobCommandBuilder::build_backup_project($node, $params);
		$job = ManagementJob::createJob($node->key, 'backup_project', $steps, $params, $session->get_user_id());
		header('Location: /admin/server_manager/job_detail?job_id=' . $job->key);
		exit;
	}

	// Database actions
	if ($action === 'copy_database') {
		$source_id = intval($_POST['source_node_id'] ?? 0);

		if ($source_id) {
			if ($source_id === $node->key) {
				$session->save_message(new DisplayMessage(
					'Source and target sites must be different.',
					'Error', $page_regex, DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
				));
			} else {
				try {
					$source_node = new ManagedNode($source_id, TRUE);
					$params = ['source_node_id' => $source_id, 'target_node_id' => $node->key];
					$steps = JobCommandBuilder::build_copy_database($source_node, $node, $params);
					$job = ManagementJob::createJob($node->key, 'copy_database', $steps, $params, $session->get_user_id());
					header('Location: /admin/server_manager/job_detail?job_id=' . $job->key);
					exit;
				} catch (Exception $e) {
					$session->save_message(new DisplayMessage(
						'Source site not found.', 'Error', $page_regex,
						DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
					));
				}
			}
		}
		header('Location: ' . $base_url . '&tab=database');
		exit;
	}

	if ($action === 'copy_database_local') {
		$source_db_name = trim($_POST['source_db_name'] ?? '');
		if ($source_db_name) {
			$params = ['source_db_name' => $source_db_name];
			$steps = JobCommandBuilder::build_copy_database_by_name($node, $params);
			$job = ManagementJob::createJob($node->key, 'copy_database_local', $steps, $params, $session->get_user_id());
			header('Location: /admin/server_manager/job_detail?job_id=' . $job->key);
			exit;
		}
		header('Location: ' . $base_url . '&tab=database');
		exit;
	}

	if ($action === 'restore_database') {
		$filename     = trim($_POST['backup_filename'] ?? '');
		$local_path   = trim($_POST['backup_local_path'] ?? '');
		$cloud_path   = trim($_POST['backup_cloud_path'] ?? '');

		if ($filename && ($local_path || $cloud_path)) {
			$params = [
				'filename'   => $filename,
				'local_path' => $local_path ?: null,
				'cloud_path' => $cloud_path ?: null,
			];
			$steps = JobCommandBuilder::build_restore_database($node, $params);
			$job = ManagementJob::createJob($node->key, 'restore_database', $steps, $params, $session->get_user_id());
			header('Location: /admin/server_manager/job_detail?job_id=' . $job->key);
			exit;
		}
		header('Location: ' . $base_url . '&tab=database');
		exit;
	}

	if ($action === 'restore_project') {
		$filename   = trim($_POST['backup_filename'] ?? '');
		$local_path = trim($_POST['backup_local_path'] ?? '');
		$cloud_path = trim($_POST['backup_cloud_path'] ?? '');

		if (!$filename || (!$local_path && !$cloud_path)) {
			header('Location: ' . $base_url . '&tab=backups');
			exit;
		}

		$params = [
			'filename'      => $filename,
			'local_path'    => $local_path ?: null,
			'cloud_path'    => $cloud_path ?: null,
			'skip_database' => empty($_POST['restore_database']),
			'skip_files'    => empty($_POST['restore_files']),
			'skip_apache'   => empty($_POST['restore_apache']),
		];
		try {
			$steps = JobCommandBuilder::build_restore_project($node, $params);
			$job = ManagementJob::createJob($node->key, 'restore_project', $steps, $params, $session->get_user_id());
			header('Location: /admin/server_manager/job_detail?job_id=' . $job->key);
			exit;
		} catch (Exception $e) {
			$session->save_message(new DisplayMessage(
				$e->getMessage(), 'Error', $page_regex,
				DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
			header('Location: ' . $base_url . '&tab=backups');
			exit;
		}
	}

	// Update actions
	if ($action === 'apply_update') {
		$steps = JobCommandBuilder::build_apply_update($node);
		$job = ManagementJob::createJob($node->key, 'apply_update', $steps, [], $session->get_user_id());
		header('Location: /admin/server_manager/job_detail?job_id=' . $job->key);
		exit;
	}

	if ($action === 'apply_update_all_on_host') {

		$siblings = new MultiManagedNode(
			['host' => $node->get('mgn_host'), 'enabled' => true, 'deleted' => false],
			['mgn_slug' => 'ASC']
		);
		$siblings->load();

		if ($siblings->count() === 0) {
			$session->save_message(new DisplayMessage(
				'No eligible sites found on this host.', 'Error', $page_regex,
				DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
			header('Location: ' . $base_url . '&tab=updates');
			exit;
		}

		$queued = 0;
		foreach ($siblings as $sibling) {
			try {
				$steps = JobCommandBuilder::build_apply_update($sibling);
				ManagementJob::createJob(
					$sibling->key, 'apply_update', $steps, [], $session->get_user_id()
				);
				$queued++;
			} catch (Exception $e) {
				error_log("apply_update_all_on_host: failed to queue node {$sibling->key}: " . $e->getMessage());
			}
		}

		if ($queued === 0) {
			$session->save_message(new DisplayMessage(
				'No jobs were queued.', 'Error', $page_regex,
				DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
			header('Location: ' . $base_url . '&tab=updates');
			exit;
		}

		$session->save_message(new DisplayMessage(
			"Queued {$queued} upgrade " . ($queued === 1 ? 'job' : 'jobs') . " for sites on this host.",
			'Success', $page_regex,
			DisplayMessage::MESSAGE_SUCCESS, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
		header('Location: /admin/server_manager?tab=jobs');
		exit;
	}

	if ($action === 'retry_install') {
		// Reuse params from the most recent install_node job for this node
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("SELECT mjb_id FROM mjb_management_jobs WHERE mjb_mgn_node_id = ? AND mjb_job_type = 'install_node' AND mjb_delete_time IS NULL ORDER BY mjb_id DESC LIMIT 1");
		$q->execute([$node->key]);
		$row = $q->fetch(PDO::FETCH_ASSOC);
		if (!$row) {
			$session->save_message(new DisplayMessage(
				'No prior install job found for this node.', 'Error', $page_regex,
				DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
			header('Location: ' . $base_url);
			exit;
		}
		$prev = new ManagementJob($row['mjb_id'], TRUE);
		$params = $prev->get('mjb_parameters');
		if (is_string($params)) $params = json_decode($params, true);
		try {
			$steps = JobCommandBuilder::build_install_node($node, $params ?: []);
			$node->set('mgn_install_state', 'installing');
			$node->save();
			$job = ManagementJob::createJob($node->key, 'install_node', $steps, $params, $session->get_user_id());
			header('Location: /admin/server_manager/job_detail?job_id=' . $job->key);
			exit;
		} catch (Exception $e) {
			$session->save_message(new DisplayMessage(
				'Failed to queue retry: ' . $e->getMessage(), 'Error', $page_regex,
				DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
			header('Location: ' . $base_url);
			exit;
		}
	}

	// Save/clear API credential (Overview tab panel)
	if ($action === 'save_api_credential') {
		$pub  = trim($_POST['mgn_api_public_key'] ?? '');
		$sec  = trim($_POST['mgn_api_secret_key'] ?? '');
		$tls_insecure = !empty($_POST['mgn_tls_insecure']);

		$node->set('mgn_api_public_key', $pub !== '' ? $pub : null);
		// Empty secret field on an existing-credentials form means "keep current secret".
		if ($sec !== '') {
			$node->set('mgn_api_secret_key', $sec);
		} elseif ($pub === '') {
			// Both cleared → wipe secret too.
			$node->set('mgn_api_secret_key', null);
		}
		$node->set('mgn_tls_insecure', $tls_insecure);

		try {
			$node->save();
			$node->load();
			$session->save_message(new DisplayMessage(
				'API credential saved.', 'Success', $page_regex,
				DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
		} catch (Exception $e) {
			$session->save_message(new DisplayMessage(
				$e->getMessage(), 'Error', $page_regex,
				DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
		}
		header('Location: ' . $base_url . '&tab=api_keys');
		exit;
	}

	if ($action === 'clear_api_credential') {
		$node->set('mgn_api_public_key', null);
		$node->set('mgn_api_secret_key', null);
		try {
			$node->save();
			$session->save_message(new DisplayMessage(
				'API credential cleared. Jobs will now route via SSH.', 'Success', $page_regex,
				DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
		} catch (Exception $e) {
			$session->save_message(new DisplayMessage(
				$e->getMessage(), 'Error', $page_regex,
				DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
		}
		header('Location: ' . $base_url . '&tab=api_keys');
		exit;
	}

	// Save node settings (overview tab form)
	if ($action === 'save_node') {
		$editable_fields = [
			'mgn_name', 'mgn_slug', 'mgn_host', 'mgn_ssh_user', 'mgn_ssh_key_path',
			'mgn_ssh_port', 'mgn_container_name', 'mgn_container_user', 'mgn_web_root',
			'mgn_site_url', 'mgn_bkt_backup_target_id', 'mgn_notes', 'mgn_enabled',
			'mgn_delete_local_after_upload',
		];
		$bool_fields = ['mgn_enabled', 'mgn_delete_local_after_upload'];
		foreach ($editable_fields as $field) {
			if (in_array($field, $bool_fields, true)) {
				$node->set($field, !empty($_POST[$field]));
				continue;
			}
			if (isset($_POST[$field])) {
				$value = trim($_POST[$field]);
				if ($field === 'mgn_ssh_port' && $value === '') {
					$value = 22;
				}
				if ($field === 'mgn_bkt_backup_target_id' && $value === '') {
					$value = null;
				}
				$node->set($field, $value);
			}
		}

		try {
			$node->prepare();
			$node->save();
			$node->load();
			$session->save_message(new DisplayMessage(
				'Site saved successfully.', 'Success', $page_regex,
				DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
			header('Location: ' . $base_url . '&tab=overview');
			exit;
		} catch (Exception $e) {
			$session->save_message(new DisplayMessage(
				$e->getMessage(), 'Error', $page_regex,
				DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
			header('Location: ' . $base_url . '&tab=overview');
			exit;
		}
	}
}

// Handle GET actions (delete)
if (isset($_GET['action']) && $_GET['action'] === 'delete' && $node->key) {
	$node->soft_delete();
	$session->save_message(new DisplayMessage(
		'Site deleted.', 'Success', $page_regex,
		DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
	));
	header('Location: /admin/server_manager');
	exit;
}

// ── Page rendering ──

$node_name = htmlspecialchars($node->get('mgn_name'));

$page = new AdminPage();
$page->admin_header([
	'menu-id' => 'server-manager',
	'page_title' => $node->get('mgn_name'),
	'readable_title' => $node->get('mgn_name'),
	'breadcrumbs' => [
		'Server Manager' => '/admin/server_manager',
		$node->get('mgn_name') => '',
	],
	'session' => $session,
]);

// Display messages
$display_messages = $session->get_messages('/admin/server_manager');
if (!empty($display_messages)) {
	foreach ($display_messages as $msg) {
		$alert_class = 'alert-info';
		if ($msg->display_type == DisplayMessage::MESSAGE_ERROR) {
			$alert_class = 'alert-danger';
		} elseif ($msg->display_type == DisplayMessage::MESSAGE_ANNOUNCEMENT) {
			$alert_class = 'alert-success';
		}
		echo '<div class="alert ' . $alert_class . ' alert-dismissible fade show" role="alert">';
		if ($msg->message_title) {
			echo '<strong>' . htmlspecialchars($msg->message_title) . ':</strong> ';
		}
		echo htmlspecialchars($msg->message);
		echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
	}
	$session->clear_clearable_messages();
}

// ── Tab navigation ──
?>
<ul class="nav nav-tabs mb-3">
	<li class="nav-item"><a class="nav-link <?php echo $tab === 'overview' ? 'active' : ''; ?>" href="<?php echo $base_url; ?>&tab=overview">Overview</a></li>
	<?php if (!$skip_joinery): ?>
		<li class="nav-item"><a class="nav-link <?php echo $tab === 'backups' ? 'active' : ''; ?>" href="<?php echo $base_url; ?>&tab=backups">Backups</a></li>
		<li class="nav-item"><a class="nav-link <?php echo $tab === 'database' ? 'active' : ''; ?>" href="<?php echo $base_url; ?>&tab=database">Database</a></li>
		<li class="nav-item"><a class="nav-link <?php echo $tab === 'updates' ? 'active' : ''; ?>" href="<?php echo $base_url; ?>&tab=updates">Updates</a></li>
	<?php endif; ?>
	<li class="nav-item"><a class="nav-link <?php echo $tab === 'jobs' ? 'active' : ''; ?>" href="<?php echo $base_url; ?>&tab=jobs">Jobs</a></li>
	<li class="nav-item"><a class="nav-link <?php echo $tab === 'api_keys' ? 'active' : ''; ?>" href="<?php echo $base_url; ?>&tab=api_keys">API Keys</a></li>
</ul>

<form id="nodeActionCheckStatus" method="post" action="<?php echo $base_url; ?>" style="display:none">
	<input type="hidden" name="action" value="check_status">
</form>

<?php
// ============================================================
// OVERVIEW TAB
// ============================================================
if ($tab === 'overview') {

	// Install state banner (takes precedence over regular status)
	$install_state = $node->get('mgn_install_state');
	if ($install_state === 'installing') {
		echo '<div class="alert alert-info"><strong>Install in progress.</strong> The install job is running against this node. ';
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("SELECT mjb_id FROM mjb_management_jobs WHERE mjb_mgn_node_id = ? AND mjb_job_type = 'install_node' AND mjb_delete_time IS NULL ORDER BY mjb_id DESC LIMIT 1");
		$q->execute([$node->key]);
		$row = $q->fetch(PDO::FETCH_ASSOC);
		if ($row) {
			echo '<a href="/admin/server_manager/job_detail?job_id=' . $row['mjb_id'] . '">View job #' . $row['mjb_id'] . '</a>';
		}
		echo '</div>';
	} elseif ($install_state === 'install_failed') {
		echo '<div class="alert alert-danger"><strong>Install failed.</strong> The last install attempt did not complete.';
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("SELECT mjb_id FROM mjb_management_jobs WHERE mjb_mgn_node_id = ? AND mjb_job_type = 'install_node' AND mjb_delete_time IS NULL ORDER BY mjb_id DESC LIMIT 1");
		$q->execute([$node->key]);
		$row = $q->fetch(PDO::FETCH_ASSOC);
		if ($row) {
			echo ' <a href="/admin/server_manager/job_detail?job_id=' . $row['mjb_id'] . '" class="alert-link">View job #' . $row['mjb_id'] . ' output</a>.';
		}
		echo '<div class="mt-2"><form method="post" style="display:inline" onsubmit="return confirm(\'Before retrying: SSH to the target and remove any partial install (e.g. rm -rf /var/www/html/SITENAME, drop the DB). install.sh will refuse if the site directory already exists. Continue?\');">';
		echo '<input type="hidden" name="action" value="retry_install">';
		echo '<button type="submit" class="btn btn-sm btn-warning">Retry Install</button></form></div>';
		echo '</div>';
	}

	// Status summary card
	$status_data = $node->get('mgn_last_status_data');
	if (is_string($status_data)) {
		$status_data = json_decode($status_data, true);
	}
	$last_check = $node->get('mgn_last_status_check');

	$db = DbConnector::get_instance()->get_db_link();
	$last_job_failed = false;
	$last_job_q = $db->prepare("SELECT mjb_status FROM mjb_management_jobs WHERE mjb_mgn_node_id = ? AND mjb_job_type = 'check_status' AND mjb_delete_time IS NULL ORDER BY mjb_id DESC LIMIT 1");
	$last_job_q->execute([$node->key]);
	$last_job_row = $last_job_q->fetch(PDO::FETCH_ASSOC);
	if ($last_job_row && $last_job_row['mjb_status'] === 'failed') {
		$last_job_failed = true;
	}

	if ($last_job_failed) {
		$status_color = 'danger';
	} elseif (!$last_check || !$status_data) {
		$status_color = 'secondary';
	} elseif (
		(isset($status_data['disk_usage_percent']) && $status_data['disk_usage_percent'] > 90) ||
		(isset($status_data['postgres_status']) && $status_data['postgres_status'] !== 'accepting connections')
	) {
		$status_color = 'danger';
	} elseif (
		(isset($status_data['disk_usage_percent']) && $status_data['disk_usage_percent'] > 80) ||
		(isset($status_data['load_1m']) && $status_data['load_1m'] > 5)
	) {
		$status_color = 'warning';
	} else {
		$status_color = 'success';
	}

	echo '<div class="mb-3">';
	echo '<div class="d-flex justify-content-between align-items-center py-2 px-3 mb-2">';
	echo '<div class="d-flex align-items-center">';
	echo '<span class="badge bg-' . $status_color . ' me-2">&bull;</span>';
	echo '<strong>' . $node_name . '</strong>';
	if ($node->get('mgn_site_url')) {
		echo '<a href="' . htmlspecialchars($node->get('mgn_site_url')) . '" target="_blank" class="ms-2 small">' . htmlspecialchars($node->get('mgn_site_url')) . '</a>';
	}
	echo '</div>';
	?>
	<div class="btn-group" style="position:relative">
		<button type="button" class="btn btn-sm btn-primary dropdown-toggle" onclick="var m=this.nextElementSibling;m.style.display=m.style.display==='block'?'none':'block'">Actions</button>
		<ul class="dropdown-menu dropdown-menu-end" style="display:none;position:absolute;right:0;top:100%">
			<li><a class="dropdown-item" href="javascript:void(0)" onclick="document.getElementById('nodeActionCheckStatus').submit()">Check Status</a></li>
			<li><a class="dropdown-item" href="<?php echo $base_url; ?>&tab=overview&edit=1#connectionSettings">Edit Connection Settings</a></li>
			<?php if (!$node->get('mgn_delete_time')): ?>
				<li><hr class="dropdown-divider"></li>
				<li><a class="dropdown-item text-danger" href="<?php echo $base_url; ?>&action=delete" onclick="return confirm('Delete this site?')">Delete Site</a></li>
			<?php endif; ?>
		</ul>
	</div>
	<?php
	echo '</div>';

	if ($last_check) {
		echo '<small class="text-muted">Last checked: ' . LibraryFunctions::convert_time($last_check, 'UTC', $session->get_timezone(), 'M j, g:i A') . '</small>';
	} elseif (!$status_data) {
		echo '<small class="text-muted">No status check has been run yet.</small>';
	}

	echo '</div>';

	// ── System Health panel ──
	if ($status_data) {
		$pageoptions = ['title' => 'System Health'];
		$page->begin_box($pageoptions);

		$cp_version = LibraryFunctions::get_joinery_version();
		$node_version = $node->get('mgn_joinery_version');
		$version_cmp = ($cp_version !== '' && preg_match('/^\d+\.\d+\.\d+$/', $node_version ?? ''))
			? version_compare($node_version, $cp_version) : null;

		// Stat tile grid — each tile: label on top, large value, optional subline/progress bar.
		echo '<div class="row g-3">';

		// Disk
		if (isset($status_data['disk_usage_percent'])) {
			$pct = intval($status_data['disk_usage_percent']);
			$bar = $pct > 90 ? 'bg-danger' : ($pct > 80 ? 'bg-warning' : 'bg-success');
			$sub = '';
			if (!empty($status_data['disk_total'])) {
				$sub = htmlspecialchars($status_data['disk_used'] . ' / ' . $status_data['disk_total'] . ' used · ' . ($status_data['disk_available'] ?? '?') . ' free');
			}
			echo '<div class="col-md-6 col-xl-4">';
			echo '<div class="border rounded p-3 h-100">';
			echo '<div class="text-muted small text-uppercase">Disk</div>';
			echo '<div class="fs-3 fw-semibold mt-1">' . $pct . '<span class="fs-5 text-muted">%</span></div>';
			echo '<div class="progress mt-2" style="height:4px"><div class="progress-bar ' . $bar . '" style="width:' . $pct . '%"></div></div>';
			if ($sub) echo '<div class="text-muted small mt-2">' . $sub . '</div>';
			echo '</div></div>';
		}

		// Memory
		if (isset($status_data['memory_used_mb'], $status_data['memory_total_mb']) && $status_data['memory_total_mb'] > 0) {
			$used = (int)$status_data['memory_used_mb'];
			$total = (int)$status_data['memory_total_mb'];
			$pct = (int)round($used * 100 / $total);
			$bar = $pct > 90 ? 'bg-danger' : ($pct > 80 ? 'bg-warning' : 'bg-success');
			echo '<div class="col-md-6 col-xl-4">';
			echo '<div class="border rounded p-3 h-100">';
			echo '<div class="text-muted small text-uppercase">Memory</div>';
			echo '<div class="fs-3 fw-semibold mt-1">' . $pct . '<span class="fs-5 text-muted">%</span></div>';
			echo '<div class="progress mt-2" style="height:4px"><div class="progress-bar ' . $bar . '" style="width:' . $pct . '%"></div></div>';
			echo '<div class="text-muted small mt-2">' . $used . ' / ' . $total . ' MB</div>';
			echo '</div></div>';
		}

		// Load average
		if (isset($status_data['load_1m'])) {
			$l1 = $status_data['load_1m'] ?? '-';
			$l5 = $status_data['load_5m'] ?? '-';
			$l15 = $status_data['load_15m'] ?? '-';
			echo '<div class="col-md-6 col-xl-4">';
			echo '<div class="border rounded p-3 h-100">';
			echo '<div class="text-muted small text-uppercase">Load Average</div>';
			echo '<div class="fs-3 fw-semibold mt-1">' . htmlspecialchars((string)$l1) . '</div>';
			echo '<div class="text-muted small mt-2">' . htmlspecialchars("{$l5} (5m) · {$l15} (15m)") . '</div>';
			echo '</div></div>';
		}

		// Uptime
		if (!empty($status_data['uptime'])) {
			echo '<div class="col-md-6 col-xl-4">';
			echo '<div class="border rounded p-3 h-100">';
			echo '<div class="text-muted small text-uppercase">Uptime</div>';
			echo '<div class="fs-5 fw-semibold mt-1">' . htmlspecialchars($status_data['uptime']) . '</div>';
			echo '</div></div>';
		}

		// PostgreSQL
		if (!empty($status_data['postgres_status'])) {
			$pg_class = $status_data['postgres_status'] === 'accepting connections' ? 'success' : 'danger';
			echo '<div class="col-md-6 col-xl-4">';
			echo '<div class="border rounded p-3 h-100">';
			echo '<div class="text-muted small text-uppercase">PostgreSQL</div>';
			echo '<div class="mt-1"><span class="badge bg-' . $pg_class . '">' . htmlspecialchars($status_data['postgres_status']) . '</span></div>';
			if (!empty($status_data['current_db'])) {
				echo '<div class="text-muted small mt-2">Current DB: <code>' . htmlspecialchars($status_data['current_db']) . '</code></div>';
			}
			echo '</div></div>';
		}

		// Joinery version
		if ($node_version) {
			$badge = '';
			$subline = '';
			if ($version_cmp === -1) {
				$badge = ' <span class="badge bg-warning ms-1">upgrade available</span>';
				$subline = 'Control plane: ' . htmlspecialchars($cp_version);
			} elseif ($version_cmp === 1) {
				$badge = ' <span class="badge bg-danger ms-1">ahead of control plane</span>';
				$subline = 'Control plane: ' . htmlspecialchars($cp_version);
			} elseif ($version_cmp === 0) {
				$badge = ' <span class="badge bg-success ms-1">up to date</span>';
			}
			echo '<div class="col-md-6 col-xl-4">';
			echo '<div class="border rounded p-3 h-100">';
			echo '<div class="text-muted small text-uppercase">Joinery Version</div>';
			echo '<div class="fs-5 fw-semibold mt-1">' . htmlspecialchars($node_version) . $badge . '</div>';
			if ($subline) echo '<div class="text-muted small mt-2">' . $subline . '</div>';
			echo '</div></div>';
		}

		echo '</div>'; // end .row

		// Secondary info that doesn't warrant its own tile.
		if (!empty($status_data['db_list']) && count($status_data['db_list']) > 1) {
			echo '<div class="text-muted small mt-3"><strong>All databases:</strong> ' . htmlspecialchars(implode(', ', $status_data['db_list'])) . '</div>';
		}

		$page->end_box();
	}

	// ── Connection Info panel (read-only summary) ──
	$pageoptions = ['title' => 'Connection Info'];
	$page->begin_box($pageoptions);
	echo '<table class="table table-sm mb-0 align-middle">';
	echo '<tbody>';

	$conn_row = function($label, $value) {
		echo '<tr>';
		echo '<th class="text-muted fw-normal" style="width:200px">' . $label . '</th>';
		echo '<td>' . $value . '</td>';
		echo '</tr>';
	};

	$conn_row('Host', '<code>' . htmlspecialchars($node->get('mgn_host')) . '</code>');
	$conn_row('SSH', '<code>' . htmlspecialchars($node->get('mgn_ssh_user')) . '@' . htmlspecialchars($node->get('mgn_host')) . ':' . intval($node->get('mgn_ssh_port') ?: 22) . '</code>');

	if ($node->get('mgn_container_name')) {
		$container_value = '<code>' . htmlspecialchars($node->get('mgn_container_name')) . '</code>';
		if ($node->get('mgn_container_user')) {
			$container_value .= ' <span class="text-muted">as ' . htmlspecialchars($node->get('mgn_container_user')) . '</span>';
		}
		$conn_row('Docker container', $container_value);
	}
	if ($node->get('mgn_web_root')) {
		$conn_row('Web root', '<code>' . htmlspecialchars($node->get('mgn_web_root')) . '</code>');
	}
	if ($node->get('mgn_site_url')) {
		$site_url = htmlspecialchars($node->get('mgn_site_url'));
		$conn_row('Site URL', '<a href="' . $site_url . '" target="_blank" rel="noopener">' . $site_url . ' ↗</a>');
	}

	$target_id = $node->get('mgn_bkt_backup_target_id');
	if ($target_id) {
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/backup_target_class.php'));
		try {
			$target = new BackupTarget($target_id, TRUE);
			$conn_row('Backup target',
				'<a href="/admin/server_manager/target_info?bkt_id=' . $target->key . '">' . htmlspecialchars($target->get('bkt_name')) . '</a> <span class="text-muted">(' . htmlspecialchars($target->get('bkt_provider')) . ')</span>'
			);
		} catch (Exception $e) {}
	}
	if ($node->get('mgn_notes')) {
		$conn_row('Notes', nl2br(htmlspecialchars($node->get('mgn_notes'))));
	}

	echo '</tbody></table>';
	$page->end_box();

	// Recent jobs for this node
	$overview_jobs = new MultiManagementJob(['deleted' => false, 'node_id' => $node->key], ['mjb_id' => 'DESC'], 10);
	$overview_jobs->load();

	$pageoptions = ['title' => 'Recent Jobs', 'altlinks' => ['All Jobs' => $base_url . '&tab=jobs']];
	$page->begin_box($pageoptions);

	echo '<table class="table table-striped table-sm">';
	echo '<thead><tr><th>ID</th><th>Type</th><th>Status</th><th>Created</th><th>Duration</th></tr></thead>';
	echo '<tbody>';
	$job_count = 0;
	foreach ($overview_jobs as $oj) {
		$job_count++;
		$oj_sc = match($oj->get('mjb_status')) {
			'completed' => 'success', 'failed' => 'danger', 'running' => 'primary',
			'cancelled' => 'secondary', default => 'warning',
		};
		$oj_dur = '';
		if ($oj->get('mjb_started_time') && $oj->get('mjb_completed_time')) {
			$d = strtotime($oj->get('mjb_completed_time')) - strtotime($oj->get('mjb_started_time'));
			$oj_dur = $d < 60 ? "{$d}s" : round($d / 60, 1) . 'm';
		} elseif ($oj->get('mjb_started_time')) {
			$d = time() - strtotime($oj->get('mjb_started_time'));
			$oj_dur = ($d < 60 ? "{$d}s" : round($d / 60, 1) . 'm') . '...';
		}
		echo '<tr>';
		echo '<td><a href="/admin/server_manager/job_detail?job_id=' . $oj->key . '">#' . $oj->key . '</a></td>';
		echo '<td>' . htmlspecialchars(str_replace('_', ' ', $oj->get('mjb_job_type'))) . '</td>';
		echo '<td><span class="badge bg-' . $oj_sc . '">' . htmlspecialchars($oj->get('mjb_status')) . '</span></td>';
		echo '<td>' . LibraryFunctions::convert_time($oj->get('mjb_create_time'), 'UTC', $session->get_timezone(), 'M j, g:i A') . '</td>';
		echo '<td>' . $oj_dur . '</td>';
		echo '</tr>';
	}
	if ($job_count === 0) {
		echo '<tr><td colspan="5" class="text-muted text-center">No jobs yet</td></tr>';
	}
	echo '</tbody></table>';

	$page->end_box();

	// Connection settings — open when arriving from the Actions menu (?edit=1), otherwise collapsed
	$edit_open = !empty($_GET['edit']);
	echo '<div id="connectionSettings" style="display:' . ($edit_open ? 'block' : 'none') . '">';

	$default_ssh_key = '/home/user1/.ssh/id_ed25519_claude';

	$pageoptions = ['title' => 'Connection Settings'];
	$page->begin_box($pageoptions);

	$formwriter = $page->getFormWriter('node_form', [
		'model' => $node,
		'edit_primary_key_value' => $node->key,
	]);

	echo $formwriter->begin_form();
	echo '<input type="hidden" name="action" value="save_node">';

	$formwriter->textinput('mgn_name', 'Display Name *', [
		'placeholder' => 'e.g., Empowered Health Production',
		'validation' => ['required' => true, 'maxlength' => 100],
	]);

	$formwriter->textinput('mgn_slug', 'Slug *', [
		'placeholder' => 'e.g., empoweredhealthtn',
		'helptext' => 'Unique short identifier (lowercase, hyphens OK)',
		'validation' => ['required' => true, 'maxlength' => 50],
	]);

	$formwriter->textinput('mgn_host', 'SSH Host *', [
		'placeholder' => 'e.g., 23.239.11.53',
		'validation' => ['required' => true, 'maxlength' => 255],
	]);

	$formwriter->textinput('mgn_ssh_user', 'SSH User', [
		'placeholder' => 'root',
		'validation' => ['maxlength' => 50],
	]);

	$formwriter->textinput('mgn_ssh_key_path', 'SSH Key Path *', [
		'placeholder' => $default_ssh_key,
		'validation' => ['required' => true, 'maxlength' => 500],
	]);

	$formwriter->numberinput('mgn_ssh_port', 'SSH Port', [
		'placeholder' => '22',
		'min' => 1, 'max' => 65535,
	]);

	echo '<h6 class="text-muted mt-4 mb-3">Docker Settings <small>(leave blank for bare-metal servers)</small></h6>';

	$formwriter->textinput('mgn_container_name', 'Docker Container Name', [
		'placeholder' => 'e.g., empoweredhealthtn',
		'validation' => ['maxlength' => 100],
	]);

	$formwriter->textinput('mgn_container_user', 'Container User', [
		'placeholder' => 'e.g., www-data',
		'validation' => ['maxlength' => 50],
	]);

	echo '<h6 class="text-muted mt-4 mb-3">Joinery Paths</h6>';

	$formwriter->textinput('mgn_web_root', 'Web Root Path *', [
		'placeholder' => '/var/www/html/site/public_html',
		'validation' => ['required' => true, 'maxlength' => 500],
	]);

	$formwriter->textinput('mgn_site_url', 'Site URL', [
		'placeholder' => 'e.g., https://empoweredhealthtn.com',
		'validation' => ['maxlength' => 500],
	]);

	echo '<h6 class="text-muted mt-4 mb-3">Backup Settings</h6>';

	// Target dropdown (manual since FormWriter doesn't have a model-aware FK dropdown)
	require_once(PathHelper::getIncludePath('plugins/server_manager/data/backup_target_class.php'));
	$all_targets = new MultiBackupTarget(['deleted' => false, 'enabled' => true], ['bkt_name' => 'ASC']);
	$all_targets->load();
	$current_target_id = $node->get('mgn_bkt_backup_target_id');

	echo '<div class="mb-3">';
	echo '<label class="form-label">Backup Target</label>';
	echo '<select name="mgn_bkt_backup_target_id" class="form-select">';
	echo '<option value="">Local only (no cloud upload)</option>';
	foreach ($all_targets as $d) {
		$sel = ($d->key == $current_target_id) ? ' selected' : '';
		echo '<option value="' . $d->key . '"' . $sel . '>' . htmlspecialchars($d->get('bkt_name')) . ' (' . $d->get('bkt_provider') . ')</option>';
	}
	echo '</select>';
	echo '<small class="text-muted">Where to upload backups after creation. <a href="/admin/server_manager/targets">Manage targets</a></small>';
	echo '</div>';

	$formwriter->checkboxinput('mgn_delete_local_after_upload', 'Delete local backup after upload', [
		'checked' => $node->get('mgn_delete_local_after_upload'),
		'helptext' => 'Removes the local copy on this node after a successful cloud upload. Saves disk but leaves only the cloud copy.',
	]);

	$formwriter->checkboxinput('mgn_enabled', 'Enabled', [
		'checked' => $node->get('mgn_enabled'),
	]);

	$formwriter->textbox('mgn_notes', 'Notes', ['rows' => 3]);

	$formwriter->submitbutton('btn_submit', 'Save Changes');
	echo $formwriter->end_form();

	$page->end_box();
	echo '</div>'; // end connectionSettings

// ============================================================
// BACKUPS TAB
// ============================================================
} elseif ($tab === 'backups') {

	// Load target info
	require_once(PathHelper::getIncludePath('plugins/server_manager/data/backup_target_class.php'));
	$target_id = $node->get('mgn_bkt_backup_target_id');
	$target_name = 'Local only';
	$target_provider = 'local';
	if ($target_id) {
		try {
			$target = new BackupTarget($target_id, TRUE);
			$provider_labels = ['local' => 'Local', 'b2' => 'Backblaze B2', 's3' => 'Amazon S3', 'linode' => 'Linode Object Storage'];
			$target_provider = $target->get('bkt_provider');
			$target_name = htmlspecialchars($target->get('bkt_name')) . ' (' . ($provider_labels[$target_provider] ?? $target_provider) . ')';
		} catch (Exception $e) {
			$target_name = 'Local only (configured target not found)';
		}
	}

	echo '<div class="alert alert-light border mb-3">';
	echo '<strong>Backup target:</strong> ' . $target_name;
	echo ' <a href="' . $base_url . '&tab=overview&edit=1#connectionSettings" class="ms-2 small">Change</a>';
	echo '</div>';

	$pageoptions = ['title' => 'Run Backup'];
	$page->begin_box($pageoptions);
	$require_encryption = ($target_provider === 'b2');
	?>
	<div class="row">
		<div class="col-md-6">
			<h6>Database Backup</h6>
			<?php
			$fw_db = $page->getFormWriter('backup_db_form');
			$fw_db->begin_form();
			$fw_db->hiddeninput('action', '', ['id' => 'db_backup_action', 'value' => 'backup_database']);
			if ($require_encryption) {
				$fw_db->hiddeninput('encryption', '', ['value' => '1']);
				echo '<p class="text-muted small">Encryption required for Backblaze B2 targets</p>';
			} else {
				$fw_db->checkboxinput('encryption', 'Encrypt backup', ['checked' => true, 'id' => 'db_encrypt']);
			}
			$fw_db->submitbutton('btn_db_backup', 'Run Database Backup', ['class' => 'btn btn-sm btn-primary']);
			$fw_db->end_form();
			?>
		</div>
		<div class="col-md-6">
			<h6>Full Project Backup</h6>
			<?php
			$fw_proj = $page->getFormWriter('backup_proj_form');
			$fw_proj->begin_form();
			$fw_proj->hiddeninput('action', '', ['id' => 'proj_backup_action', 'value' => 'backup_project']);
			if ($require_encryption) {
				$fw_proj->hiddeninput('encryption', '', ['value' => '1']);
				echo '<p class="text-muted small">Encryption required for Backblaze B2 targets</p>';
			} else {
				$fw_proj->checkboxinput('encryption', 'Encrypt backup', ['checked' => true, 'id' => 'proj_encrypt']);
			}
			$fw_proj->submitbutton('btn_proj_backup', 'Run Project Backup', ['class' => 'btn btn-sm btn-primary']);
			$fw_proj->end_form();
			?>
		</div>
	</div>
<?php
	$page->end_box();

	// ── Backup File Browser ──
	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/BackupListHelper.php'));
	$backup_list = BackupListHelper::get_for_node($node);
	$last_scan = $backup_list['last_scan'];
	$files = $backup_list['files'];
	$cloud_error = $backup_list['cloud_error'];
	if ($cloud_error) {
		echo '<div class="alert alert-warning">Cloud listing failed: ' . htmlspecialchars($cloud_error) . '</div>';
	}

	$pageoptions = ['title' => 'Backup Files'];
	$page->begin_box($pageoptions);

	echo '<div class="d-flex justify-content-between align-items-center mb-2">';
	if ($last_scan) {
		echo '<small class="text-muted">Last scanned: ' . LibraryFunctions::convert_time($last_scan, 'UTC', $session->get_timezone(), 'M j, g:i A') . '</small>';
	} else {
		echo '<small class="text-muted">No scan performed yet</small>';
	}
	echo '<button type="button" class="btn btn-sm btn-outline-primary" id="refreshBackupsBtn" onclick="refreshBackupList()">Scan for Backups</button>';
	echo '</div>';

	echo '<div id="backupScanStatus" style="display:none" class="mb-2"></div>';

	echo '<table class="table table-striped table-sm" id="backupFilesTable">';
	echo '<thead><tr><th>Filename</th><th>Size</th><th>Date</th><th>Location</th><th>Actions</th></tr></thead>';
	echo '<tbody>';

	if (!empty($files)) {
		$location_labels = ['local' => 'Local', 'cloud' => 'Cloud', 'both' => 'Local + Cloud'];
		foreach ($files as $f) {
			$fn = htmlspecialchars($f['filename']);
			$loc = $location_labels[$f['location'] ?? 'local'] ?? $f['location'];
			$loc_class = ($f['location'] === 'both') ? 'success' : (($f['location'] === 'cloud') ? 'info' : 'secondary');

			echo '<tr>';
			echo '<td><small>' . $fn . '</small></td>';
			echo '<td>' . htmlspecialchars($f['size'] ?? '-') . '</td>';
			echo '<td>' . htmlspecialchars($f['date'] ?? '-') . '</td>';
			echo '<td><span class="badge bg-' . $loc_class . '">' . $loc . '</span></td>';
			echo '<td>';

			$local_path = $f['local_path'] ?? '';
			$cloud_path = $f['cloud_path'] ?? '';
			$has_local = !empty($local_path);
			$has_cloud = !empty($cloud_path);

			if ($has_local && $has_cloud) {
				$target = 'both';
			} elseif ($has_local) {
				$target = 'local';
			} elseif ($has_cloud) {
				$target = 'cloud';
			} else {
				$target = '';
			}

			$restore_type = '';
			if (preg_match('/\.tar\.gz$/', $f['filename'])) {
				$restore_type = 'project';
			} elseif (preg_match('/\.sql\.gz(\.enc)?$/', $f['filename'])) {
				$restore_type = 'database';
			}

			if ($restore_type) {
				$ra = htmlspecialchars(json_encode($restore_type)) . ', '
				    . htmlspecialchars(json_encode($fn)) . ', '
				    . htmlspecialchars(json_encode($local_path)) . ', '
				    . htmlspecialchars(json_encode($cloud_path));
				echo '<button type="button" class="btn btn-outline-warning btn-sm me-1" onclick="openRestoreModal(' . $ra . ')">Restore</button>';
			}

			if ($target) {
				$args = htmlspecialchars(json_encode($target)) . ', '
				      . htmlspecialchars(json_encode($fn)) . ', '
				      . htmlspecialchars(json_encode($local_path)) . ', '
				      . htmlspecialchars(json_encode($cloud_path));
				echo '<button type="button" class="btn btn-outline-danger btn-sm" onclick="deleteBackup(' . $args . ')">Delete</button>';
			}

			echo '</td>';
			echo '</tr>';
		}
	} else {
		echo '<tr><td colspan="5" class="text-muted text-center">' . ($last_scan ? 'No backup files found' : 'Click "Scan for Backups" to see files') . '</td></tr>';
	}

	echo '</tbody></table>';

	$page->end_box();

	// ── Shared Restore modal (used by per-row Restore buttons above) ──
?>
	<div id="restoreModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; z-index:9999; background:rgba(0,0,0,0.5);" onclick="if(event.target===this) closeRestoreModal();">
		<div style="max-width:500px; margin:10vh auto; background:white; border-radius:.5rem; box-shadow:0 .5rem 1rem rgba(0,0,0,.2);">
			<?php
			$fw_restore = $page->getFormWriter('restoreForm');
			$fw_restore->begin_form();
			$fw_restore->hiddeninput('action', '', ['id' => 'rm_action', 'value' => '']);
			$fw_restore->hiddeninput('backup_filename', '', ['id' => 'rm_filename', 'value' => '']);
			$fw_restore->hiddeninput('backup_local_path', '', ['id' => 'rm_local_path', 'value' => '']);
			$fw_restore->hiddeninput('backup_cloud_path', '', ['id' => 'rm_cloud_path', 'value' => '']);
			?>
			<div style="padding:1rem 1.25rem; border-bottom:1px solid #dee2e6; display:flex; justify-content:space-between; align-items:center;">
				<h5 style="margin:0">Restore from <code id="rm_title"></code></h5>
				<button type="button" class="btn-close" aria-label="Close" onclick="closeRestoreModal();" style="background:none;border:none;font-size:1.5rem;cursor:pointer;line-height:1;">&times;</button>
			</div>
			<div style="padding:1.25rem;">
				<p class="text-muted small">
					A pre-restore snapshot of the current database and project files is written to
					<code>/backups/auto_pre_project_restore_*</code> before the restore runs.
				</p>
				<label class="form-label">What to restore</label>
				<?php
				echo '<div id="rm_files_wrap">';
				$fw_restore->checkboxinput('restore_files', 'Project files (<code>' . htmlspecialchars($node->get('mgn_web_root')) . '</code>)', ['checked' => true, 'id' => 'rm_files']);
				echo '</div>';
				$fw_restore->checkboxinput('restore_database', 'Database', ['checked' => true, 'id' => 'rm_database']);
				echo '<div id="rm_apache_wrap">';
				$fw_restore->checkboxinput('restore_apache', 'Apache config', ['checked' => true, 'id' => 'rm_apache']);
				echo '</div>';
				?>
				<div id="rm_component_error" class="text-danger small mt-2" style="display:none">Select at least one component.</div>
			</div>
			<div style="padding:.75rem 1.25rem; border-top:1px solid #dee2e6; text-align:right;">
				<button type="button" class="btn btn-secondary" onclick="closeRestoreModal();">Cancel</button>
				<button type="submit" class="btn btn-danger" onclick="return submitRestoreModal();">Restore</button>
			</div>
			<?php $fw_restore->end_form(); ?>
		</div>
	</div>

<script>
var backupNodeId = <?php echo $node->key; ?>;
var backupLastScanAge = <?php echo $last_scan ? (time() - strtotime($last_scan)) : 99999; ?>;
var BACKUP_STALE_SECONDS = 60;

function refreshBackupList() {
	var btn = document.getElementById('refreshBackupsBtn');
	var status = document.getElementById('backupScanStatus');
	btn.disabled = true;
	btn.textContent = 'Scanning...';
	status.style.display = 'block';
	status.innerHTML = '<span class="text-muted"><span class="spinner-border spinner-border-sm me-1"></span> Scanning backup files...</span>';

	fetch('/ajax/backup_actions?action=refresh_list&node_id=' + backupNodeId)
		.then(function(r) { return r.json(); })
		.then(function(data) {
			if (!data.success) {
				btn.disabled = false;
				btn.textContent = 'Scan for Backups';
				status.innerHTML = '<span class="text-danger">' + data.message + '</span>';
				return;
			}
			pollBackupList(data.job_id);
		})
		.catch(function(err) {
			btn.disabled = false;
			btn.textContent = 'Scan for Backups';
			status.innerHTML = '<span class="text-danger">Request failed</span>';
		});
}

// Auto-refresh local listing on page load if stale. Cloud listing is already live.
if (backupLastScanAge > BACKUP_STALE_SECONDS) {
	window.addEventListener('DOMContentLoaded', refreshBackupList);
}

function pollBackupList(jobId) {
	var btn = document.getElementById('refreshBackupsBtn');
	var status = document.getElementById('backupScanStatus');

	fetch('/ajax/backup_actions?action=list_status&node_id=' + backupNodeId + '&job_id=' + jobId)
		.then(function(r) { return r.json(); })
		.then(function(data) {
			if (data.status === 'pending' || data.status === 'running') {
				setTimeout(function() { pollBackupList(jobId); }, 2000);
				return;
			}
			btn.disabled = false;
			btn.textContent = 'Scan for Backups';
			status.innerHTML = '<span class="text-success">Scan complete</span>';
			setTimeout(function() { status.style.display = 'none'; }, 2000);
			// Reload page to show updated file list
			window.location.reload();
		})
		.catch(function() {
			setTimeout(function() { pollBackupList(jobId); }, 3000);
		});
}

function openRestoreModal(type, filename, localPath, cloudPath) {
	document.getElementById('rm_filename').value = filename;
	document.getElementById('rm_local_path').value = localPath || '';
	document.getElementById('rm_cloud_path').value = cloudPath || '';
	document.getElementById('rm_title').textContent = filename;
	document.getElementById('rm_component_error').style.display = 'none';

	var isProject = (type === 'project');
	document.getElementById('rm_action').value = isProject ? 'restore_project' : 'restore_database';

	// Show/hide components based on backup type
	document.getElementById('rm_files_wrap').style.display    = isProject ? '' : 'none';
	document.getElementById('rm_apache_wrap').style.display   = isProject ? '' : 'none';
	// Database is always available
	document.getElementById('rm_files').checked    = isProject;
	document.getElementById('rm_database').checked = true;
	document.getElementById('rm_apache').checked   = isProject;

	document.getElementById('restoreModal').style.display = 'block';
}

function closeRestoreModal() {
	document.getElementById('restoreModal').style.display = 'none';
}

function submitRestoreModal() {
	var action = document.getElementById('rm_action').value;
	var fn = document.getElementById('rm_filename').value || 'the selected backup';

	if (action === 'restore_project') {
		var boxes = document.querySelectorAll('#restoreForm input[type=checkbox]:checked');
		var err = document.getElementById('rm_component_error');
		if (boxes.length === 0) {
			err.style.display = 'block';
			return false;
		}
		err.style.display = 'none';
		var parts = [];
		if (document.getElementById('rm_files').checked)    parts.push('project files');
		if (document.getElementById('rm_database').checked) parts.push('database');
		if (document.getElementById('rm_apache').checked)   parts.push('Apache config');
		return confirm('Restore ' + parts.join(', ') + ' from ' + fn + '?\n\nThis will overwrite the current site. A pre-restore snapshot is written to /backups/ first.');
	}

	// restore_database
	return confirm('Restore database from ' + fn + '?\n\nThis will overwrite the current database. A pre-restore snapshot is written first.');
}

function deleteBackup(target, filename, localPath, cloudPath) {
	var locations;
	if (target === 'both')       locations = 'BOTH the local copy and the cloud copy';
	else if (target === 'local') locations = 'the local copy';
	else                         locations = 'the cloud copy';

	if (!confirm('Delete ' + filename + '?\n\nThis will remove ' + locations + '. This cannot be undone.')) return;

	var url = '/ajax/backup_actions?action=delete_file&node_id=' + backupNodeId
		+ '&target=' + encodeURIComponent(target)
		+ '&local_path=' + encodeURIComponent(localPath)
		+ '&cloud_path=' + encodeURIComponent(cloudPath);

	fetch(url)
		.then(function(r) { return r.json(); })
		.then(function(data) {
			if (!data.success) {
				alert('Delete failed: ' + data.message);
				return;
			}
			// Refresh the backup list after deletion
			refreshBackupList();
		})
		.catch(function() {
			alert('Delete request failed');
		});
}
</script>

<?php

// ============================================================
// DATABASE TAB
// ============================================================
} elseif ($tab === 'database') {

	// Load cached status data (for Internal Copy db_list)
	$status_data = $node->get('mgn_last_status_data');
	if (is_string($status_data)) $status_data = json_decode($status_data, true);

	// Load other nodes for the cross-node copy source dropdown
	$other_nodes = new MultiManagedNode(['deleted' => false, 'enabled' => true], ['mgn_name' => 'ASC']);
	$other_nodes->load();

	$pageoptions = ['title' => 'Copy Database to ' . $node_name];
	$page->begin_box($pageoptions);

	// Build source node options (exclude current node)
	$copy_source_options = ['' => 'Select source...'];
	foreach ($other_nodes as $n) {
		if ($n->key != $node->key) {
			$copy_source_options[$n->key] = $n->get('mgn_name') . ' (' . $n->get('mgn_slug') . ')';
		}
	}
	$fw_copy = $page->getFormWriter('copy_db_form');
	$fw_copy->begin_form();
	$fw_copy->hiddeninput('action', '', ['id' => 'copy_db_action', 'value' => 'copy_database']);
	$fw_copy->dropinput('source_node_id', 'Source Site', [
		'required'     => true,
		'options'      => $copy_source_options,
		'empty_option' => false,
		'helptext'     => 'Copies into: ' . htmlspecialchars($node_name) . ' (' . htmlspecialchars($node->get('mgn_slug')) . ')',
	]);
	$fw_copy->submitbutton('btn_copy_db', 'Copy Database', [
		'class'   => 'btn btn-danger',
		'onclick' => 'return confirm(\'Are you sure? This will overwrite the database on ' . addslashes($node_name) . '.\')',
	]);
	$fw_copy->end_form();
	$page->end_box();

	$pageoptions = ['title' => 'Internal Copy'];
	$page->begin_box($pageoptions);
	$db_list = $status_data['db_list'] ?? [];
	$current_db = $status_data['current_db'] ?? null;
	$skip_dbs = [$current_db, 'postgres'];
	$other_dbs = array_values(array_filter($db_list, fn($d) => !in_array($d, $skip_dbs, true)));
?>
	<?php if (empty($db_list)): ?>
		<p class="text-muted">Run <strong>Check Status</strong> from the Overview tab to discover databases on this server.</p>
	<?php elseif (empty($other_dbs)): ?>
		<p class="text-muted">No other databases found on this server<?php echo $current_db ? " (current: <strong>" . htmlspecialchars($current_db) . "</strong>)" : ''; ?>.</p>
	<?php else:
		$internal_db_options = ['' => 'Select source...'];
		foreach ($other_dbs as $db) {
			$internal_db_options[$db] = $db;
		}
		$fw_icopy = $page->getFormWriter('internal_copy_form');
		$fw_icopy->begin_form();
		$fw_icopy->hiddeninput('action', '', ['id' => 'icopy_db_action', 'value' => 'copy_database_local']);
		$fw_icopy->dropinput('source_db_name', 'Source Database', [
			'required'     => true,
			'options'      => $internal_db_options,
			'empty_option' => false,
			'helptext'     => 'Copies into: ' . htmlspecialchars($current_db ?: $node_name),
		]);
		$fw_icopy->submitbutton('btn_icopy_db', 'Copy Database', [
			'class'   => 'btn btn-danger',
			'onclick' => 'return confirm(\'Are you sure? This will overwrite the database on ' . addslashes($node_name) . '.\')',
		]);
		$fw_icopy->end_form();
	endif; ?>
<?php
	$page->end_box();

	// Recent database ops for this node
	$db_jobs = new MultiManagementJob(['deleted' => false, 'node_id' => $node->key], ['mjb_id' => 'DESC'], 20);
	$db_jobs->load();

	$pageoptions = ['title' => 'Recent Database Operations'];
	$page->begin_box($pageoptions);
?>
	<table class="table table-striped table-sm">
		<thead><tr><th>ID</th><th>Type</th><th>Status</th><th>Created</th><th>Duration</th></tr></thead>
		<tbody>
			<?php
			$count = 0;
			foreach ($db_jobs as $job):
				if (!in_array($job->get('mjb_job_type'), ['copy_database', 'copy_database_local', 'restore_database'])) continue;
				$count++;
				if ($count > 10) break;
				$sc = match($job->get('mjb_status')) {
					'completed' => 'success', 'failed' => 'danger', 'running' => 'primary', default => 'warning',
				};
				$dur = '';
				if ($job->get('mjb_started_time') && $job->get('mjb_completed_time')) {
					$d = strtotime($job->get('mjb_completed_time')) - strtotime($job->get('mjb_started_time'));
					$dur = $d < 60 ? "{$d}s" : round($d / 60, 1) . 'm';
				}
			?>
				<tr>
					<td><a href="/admin/server_manager/job_detail?job_id=<?php echo $job->key; ?>">#<?php echo $job->key; ?></a></td>
					<td><?php echo htmlspecialchars(str_replace('_', ' ', $job->get('mjb_job_type'))); ?></td>
					<td><span class="badge bg-<?php echo $sc; ?>"><?php echo htmlspecialchars($job->get('mjb_status')); ?></span></td>
					<td><?php echo LibraryFunctions::convert_time($job->get('mjb_create_time'), 'UTC', $session->get_timezone(), 'M j, g:i A'); ?></td>
					<td><?php echo $dur; ?></td>
				</tr>
			<?php endforeach; ?>
			<?php if ($count === 0): ?>
				<tr><td colspan="5" class="text-muted text-center">No database operations yet</td></tr>
			<?php endif; ?>
		</tbody>
	</table>
<?php
	$page->end_box();
?>

<?php
// ============================================================
// UPDATES TAB
// ============================================================
} elseif ($tab === 'updates') {

	// Get local version
	$settings = Globalvars::get_instance();
	$local_version = $settings->get_setting('system_version') ?: '-';

	$node_version = $node->get('mgn_joinery_version') ?: 'Unknown';
	$up_to_date = ($node_version === $local_version);

	$pageoptions = ['title' => 'Version Status'];
	$page->begin_box($pageoptions);
?>
	<div class="row mb-3">
		<div class="col-md-4">
			<strong>Current Version:</strong> <?php echo htmlspecialchars($node_version); ?>
		</div>
		<div class="col-md-4">
			<strong>Control Plane Version:</strong> <?php echo htmlspecialchars($local_version); ?>
		</div>
		<div class="col-md-4">
			<?php if ($up_to_date): ?>
				<span class="badge bg-success">Up to date</span>
			<?php else: ?>
				<span class="badge bg-warning">Update available</span>
			<?php endif; ?>
		</div>
	</div>
	<div class="mt-2">
		<form method="post" style="display:inline">
			<input type="hidden" name="action" value="apply_update">
			<button type="submit" class="btn btn-sm btn-outline-primary" onclick="return confirm('Apply update to <?php echo $node_name; ?>?')">Apply Update</button>
		</form>
	</div>
	<hr>
	<div class="mt-3">
		<form method="post" style="display:inline"
		      onsubmit="return confirm('Queue an upgrade job for every enabled site on host <?php echo htmlspecialchars($node->get('mgn_host')); ?>?');">
			<input type="hidden" name="action" value="apply_update_all_on_host">
			<button type="submit" class="btn btn-sm btn-warning">Upgrade All Sites on This Host</button>
		</form>
		<p class="text-muted small mt-2 mb-0">
			Queues one independent upgrade job per enabled, non-deleted site that shares this host
			(<code><?php echo htmlspecialchars($node->get('mgn_host')); ?></code>).
			Jobs run as the agent picks them up; one site failing does not affect the others.
			Disable a site first to skip it.
		</p>
	</div>
<?php
	$page->end_box();

// ============================================================
// JOBS TAB
// ============================================================
} elseif ($tab === 'jobs') {

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable_local($_GET, 'offset', 0);
	$sort = LibraryFunctions::fetch_variable_local($_GET, 'sort', 'mjb_id');
	$sdirection = LibraryFunctions::fetch_variable_local($_GET, 'sdirection', 'DESC');

	$search_criteria = ['deleted' => false, 'node_id' => $node->key];
	if (isset($_GET['status']) && $_GET['status']) {
		$search_criteria['status'] = $_GET['status'];
	}
	if (isset($_GET['job_type']) && $_GET['job_type']) {
		$search_criteria['job_type'] = $_GET['job_type'];
	}

	$jobs = new MultiManagementJob($search_criteria, [$sort => $sdirection], $numperpage, $offset);
	$numrecords = $jobs->count_all();
	$jobs->load();
?>
	<div class="card mb-3">
		<div class="card-body">
			<?php
			$status_options = ['' => 'All'];
			foreach (['pending', 'running', 'completed', 'failed', 'cancelled'] as $s) {
				$status_options[$s] = ucfirst($s);
			}
			$type_options = ['' => 'All'];
			foreach (['check_status', 'backup_database', 'backup_project', 'fetch_backup', 'copy_database', 'copy_database_local', 'restore_database', 'restore_project', 'apply_update'] as $t) {
				$type_options[$t] = str_replace('_', ' ', $t);
			}
			$fw_filter = $page->getFormWriter('jobs_filter_form', ['method' => 'GET']);
			$fw_filter->begin_form();
			$fw_filter->hiddeninput('mgn_id', '', ['value' => $node->key]);
			$fw_filter->hiddeninput('tab', '', ['value' => 'jobs']);
			$fw_filter->dropinput('status', 'Status', [
				'options'      => $status_options,
				'value'        => $_GET['status'] ?? '',
				'empty_option' => false,
			]);
			$fw_filter->dropinput('job_type', 'Type', [
				'options'      => $type_options,
				'value'        => $_GET['job_type'] ?? '',
				'empty_option' => false,
			]);
			$fw_filter->submitbutton('btn_filter', 'Filter', ['class' => 'btn btn-sm btn-primary']);
			$fw_filter->end_form();
			?>
			<a href="<?php echo $base_url; ?>&tab=jobs" class="btn btn-sm btn-outline-secondary">Clear</a>
			<a href="/admin/server_manager/jobs" class="btn btn-sm btn-outline-secondary ms-2">View All Jobs</a>
		</div>
	</div>
<?php
	$headers = ['ID', 'Type', 'Status', 'Progress', 'Created', 'Duration'];
	$pager = new Pager(['numrecords' => $numrecords, 'numperpage' => $numperpage]);
	$table_options = [
		'title' => 'Jobs',
		'sortoptions' => [
			'ID' => 'mjb_id',
			'Type' => 'mjb_job_type',
			'Status' => 'mjb_status',
		],
	];
	$page->tableheader($headers, $table_options, $pager);

	foreach ($jobs as $job) {
		$status_class = match($job->get('mjb_status')) {
			'completed' => 'success', 'failed' => 'danger', 'running' => 'primary',
			'cancelled' => 'secondary', default => 'warning',
		};
		$progress = $job->get('mjb_current_step') . '/' . $job->get('mjb_total_steps');
		$duration = '';
		if ($job->get('mjb_started_time') && $job->get('mjb_completed_time')) {
			$diff = strtotime($job->get('mjb_completed_time')) - strtotime($job->get('mjb_started_time'));
			$duration = $diff < 60 ? "{$diff}s" : round($diff / 60, 1) . 'm';
		} elseif ($job->get('mjb_started_time')) {
			$diff = time() - strtotime($job->get('mjb_started_time'));
			$duration = ($diff < 60 ? "{$diff}s" : round($diff / 60, 1) . 'm') . '...';
		}

		$rowvalues = [];
		$rowvalues[] = '<a href="/admin/server_manager/job_detail?job_id=' . $job->key . '">#' . $job->key . '</a>';
		$rowvalues[] = htmlspecialchars(str_replace('_', ' ', $job->get('mjb_job_type')));
		$rowvalues[] = '<span class="badge bg-' . $status_class . '">' . htmlspecialchars($job->get('mjb_status')) . '</span>';
		$rowvalues[] = $progress;
		$rowvalues[] = LibraryFunctions::convert_time($job->get('mjb_create_time'), 'UTC', $session->get_timezone(), 'M j, g:i A');
		$rowvalues[] = $duration;

		$page->disprow($rowvalues);
	}

	$page->endtable($pager);

// ============================================================
// API KEYS TAB
// ============================================================
} elseif ($tab === 'api_keys') {

	$has_api_pub = (bool)$node->get('mgn_api_public_key');
	$has_api_sec = (bool)$node->get('mgn_api_secret_key');
	$api_tls_insecure = (bool)$node->get('mgn_tls_insecure');

	$pageoptions = ['title' => 'API Credential'];
	$page->begin_box($pageoptions);
	echo '<p class="text-muted small mb-3">Pastable API credentials let the control plane use this node\'s HTTP management API instead of SSH for read-only operations (stats, version, backup listing, backup fetch). ';
	echo 'Create a key on the node: Admin → API Keys, owned by a superadmin user, with permission 1 (read-only). IP-restrict to this control plane\'s egress IP.</p>';

	if ($has_api_pub && $has_api_sec) {
		echo '<div class="mb-2"><span class="badge bg-success">Configured</span>';
		echo ' <span class="text-muted small ms-2">Public: <code>' . htmlspecialchars(substr($node->get('mgn_api_public_key'), 0, 12)) . '…</code></span>';
		echo ' <span id="apiProbeIndicator" class="ms-2 small text-muted" data-node-id="' . intval($node->key) . '">Probing…</span>';
		echo '</div>';
	} else {
		echo '<div class="mb-2"><span class="badge bg-secondary">Not configured</span> <span class="text-muted small ms-2">Jobs route via SSH.</span></div>';
	}

	$fw_api = $page->getFormWriter('api_keys_form', [
		'values' => [
			'mgn_api_public_key' => $node->get('mgn_api_public_key') ?? '',
		],
	]);
	$fw_api->begin_form();
	$fw_api->hiddeninput('action', '', ['id' => 'api_save_action', 'value' => 'save_api_credential']);
	$fw_api->textinput('mgn_api_public_key', 'Public key', [
		'placeholder' => 'paste public_key here',
	]);
	$fw_api->passwordinput('mgn_api_secret_key', 'Secret key', [
		'placeholder' => $has_api_sec ? '(leave blank to keep current secret)' : 'paste secret_key here',
	]);
	$fw_api->checkboxinput('mgn_tls_insecure', 'Skip TLS certificate verification (only for dev/local instances without a trusted CA cert)', [
		'checked' => (bool)$api_tls_insecure,
	]);
	if ($api_tls_insecure) {
		echo '<div class="alert alert-warning py-2 small"><strong>TLS verification disabled.</strong> Do not use for nodes reachable from the public internet.</div>';
	}
	$fw_api->submitbutton('btn_api_save', 'Save', ['class' => 'btn btn-sm btn-primary']);
	$fw_api->end_form();
	if ($has_api_pub) {
		echo ' <button type="submit" form="api_keys_clear_form" class="btn btn-sm btn-outline-danger" '
		   . 'onclick="return confirm(\'Clear API credentials? Jobs will fall back to SSH.\')">Clear</button>';
	}

	if ($has_api_pub) {
		$fw_api_clear = $page->getFormWriter('api_keys_clear_form');
		$fw_api_clear->begin_form();
		$fw_api_clear->hiddeninput('action', '', ['id' => 'api_clear_action', 'value' => 'clear_api_credential']);
		$fw_api_clear->end_form();
	}
	$page->end_box();

	// Async API probe — populates #apiProbeIndicator when credentials exist.
	if ($has_api_pub && $has_api_sec):
	?>
	<script>
	(function() {
		var el = document.getElementById('apiProbeIndicator');
		if (!el) return;
		var nodeId = el.getAttribute('data-node-id');
		fetch('/ajax/probe_api?node_id=' + encodeURIComponent(nodeId))
			.then(function(r) { return r.json(); })
			.then(function(j) {
				if (j.ok) {
					el.className = 'ms-2 small text-success';
					el.textContent = 'API healthy (' + j.elapsed_ms + 'ms)';
				} else {
					el.className = 'ms-2 small text-danger';
					var label = j.reason === 'auth' ? 'auth failed'
					          : j.reason === 'transport' ? 'unreachable'
					          : j.reason === 'status' ? 'bad response'
					          : 'failed';
					el.textContent = 'API ' + label + (j.message ? ': ' + j.message : '');
				}
			})
			.catch(function() {
				el.className = 'ms-2 small text-danger';
				el.textContent = 'API probe failed';
			});
	})();
	</script>
	<?php
	endif;

} // end tab switch

$page->admin_footer();
?>
