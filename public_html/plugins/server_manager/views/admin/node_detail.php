<?php
/**
 * Server Manager - Node Detail
 * URL: /admin/server_manager/node_detail?mgn_id=N&tab=overview
 *
 * Consolidated node management page with tabs:
 * Overview, Backups, Database, Updates, Jobs
 *
 * @version 1.0
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
$valid_tabs = ['overview', 'backups', 'database', 'updates', 'jobs'];
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

	if ($action === 'fetch_backup') {
		$params = ['remote_path' => trim($_POST['remote_path'] ?? '')];
		if ($params['remote_path']) {
			$steps = JobCommandBuilder::build_fetch_backup($node, $params);
			$job = ManagementJob::createJob($node->key, 'fetch_backup', $steps, $params, $session->get_user_id());
			header('Location: /admin/server_manager/job_detail?job_id=' . $job->key);
			exit;
		}
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

	// Update actions
	if ($action === 'apply_update') {
		$params = ['dry_run' => !empty($_POST['dry_run'])];
		$steps = JobCommandBuilder::build_apply_update($node, $params);
		$job = ManagementJob::createJob($node->key, 'apply_update', $steps, $params, $session->get_user_id());
		header('Location: /admin/server_manager/job_detail?job_id=' . $job->key);
		exit;
	}

	if ($action === 'refresh_archives') {
		$steps = JobCommandBuilder::build_refresh_archives($node);
		$job = ManagementJob::createJob($node->key, 'refresh_archives', $steps, null, $session->get_user_id());
		header('Location: /admin/server_manager/job_detail?job_id=' . $job->key);
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

	// Save node settings (overview tab form)
	if ($action === 'save_node') {
		$editable_fields = [
			'mgn_name', 'mgn_slug', 'mgn_host', 'mgn_ssh_user', 'mgn_ssh_key_path',
			'mgn_ssh_port', 'mgn_container_name', 'mgn_container_user', 'mgn_web_root',
			'mgn_site_url', 'mgn_bkt_backup_target_id', 'mgn_notes', 'mgn_enabled',
		];
		foreach ($editable_fields as $field) {
			if (isset($_POST[$field])) {
				$value = trim($_POST[$field]);
				if ($field === 'mgn_enabled') {
					$value = isset($_POST[$field]) ? true : false;
				}
				if ($field === 'mgn_ssh_port' && $value === '') {
					$value = 22;
				}
				if ($field === 'mgn_bkt_backup_target_id' && $value === '') {
					$value = null;
				}
				$node->set($field, $value);
			}
		}
		if (!isset($_POST['mgn_enabled'])) {
			$node->set('mgn_enabled', false);
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
	<li class="nav-item"><a class="nav-link <?php echo $tab === 'backups' ? 'active' : ''; ?>" href="<?php echo $base_url; ?>&tab=backups">Backups</a></li>
	<li class="nav-item"><a class="nav-link <?php echo $tab === 'database' ? 'active' : ''; ?>" href="<?php echo $base_url; ?>&tab=database">Database</a></li>
	<li class="nav-item"><a class="nav-link <?php echo $tab === 'updates' ? 'active' : ''; ?>" href="<?php echo $base_url; ?>&tab=updates">Updates</a></li>
	<li class="nav-item"><a class="nav-link <?php echo $tab === 'jobs' ? 'active' : ''; ?>" href="<?php echo $base_url; ?>&tab=jobs">Jobs</a></li>
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
			<form method="post">
				<input type="hidden" name="action" value="backup_database">
				<?php if ($require_encryption): ?>
					<input type="hidden" name="encryption" value="1">
					<div class="mb-2"><small class="text-muted">Encryption required for Backblaze B2 targets</small></div>
				<?php else: ?>
					<div class="mb-2 form-check">
						<input type="checkbox" name="encryption" class="form-check-input" id="db_encrypt" checked>
						<label class="form-check-label" for="db_encrypt">Encrypt backup</label>
					</div>
				<?php endif; ?>
				<button type="submit" class="btn btn-sm btn-primary">Run Database Backup</button>
			</form>
		</div>
		<div class="col-md-6">
			<h6>Full Project Backup</h6>
			<form method="post">
				<input type="hidden" name="action" value="backup_project">
				<?php if ($require_encryption): ?>
					<input type="hidden" name="encryption" value="1">
					<div class="mb-2"><small class="text-muted">Encryption required for Backblaze B2 targets</small></div>
				<?php else: ?>
					<div class="mb-2 form-check">
						<input type="checkbox" name="encryption" class="form-check-input" id="proj_encrypt" checked>
						<label class="form-check-label" for="proj_encrypt">Encrypt backup</label>
					</div>
				<?php endif; ?>
				<button type="submit" class="btn btn-sm btn-primary">Run Project Backup</button>
			</form>
		</div>
	</div>
	<hr>
	<div class="row">
		<div class="col-md-6">
			<h6>Fetch Backup File</h6>
			<form method="post">
				<input type="hidden" name="action" value="fetch_backup">
				<div class="mb-2">
					<label class="form-label">Remote path</label>
					<input type="text" name="remote_path" class="form-control form-control-sm" placeholder="/backups/backup_20260410.sql.gz" required>
				</div>
				<button type="submit" class="btn btn-sm btn-primary">Fetch to Control Plane</button>
			</form>
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
?>

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

	// Load cached status data (for Internal Copy db_list) and backup list (for Restore dropdown)
	$status_data = $node->get('mgn_last_status_data');
	if (is_string($status_data)) $status_data = json_decode($status_data, true);

	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/BackupListHelper.php'));
	$_bl = BackupListHelper::get_for_node($node);
	$backup_list_raw = ['files' => $_bl['files']];
	$backup_list_time = $_bl['last_scan'];

	// Load other nodes for the cross-node copy source dropdown
	$other_nodes = new MultiManagedNode(['deleted' => false, 'enabled' => true], ['mgn_name' => 'ASC']);
	$other_nodes->load();

	$pageoptions = ['title' => 'Copy Database to ' . $node_name];
	$page->begin_box($pageoptions);
?>
	<form method="post">
		<input type="hidden" name="action" value="copy_database">
		<div class="row mb-3">
			<div class="col-md-5">
				<label class="form-label"><strong>Source Site</strong></label>
				<select name="source_node_id" class="form-select" required>
					<option value="">Select source...</option>
					<?php foreach ($other_nodes as $n): ?>
						<?php if ($n->key != $node->key): ?>
							<option value="<?php echo $n->key; ?>"><?php echo htmlspecialchars($n->get('mgn_name')); ?> (<?php echo htmlspecialchars($n->get('mgn_slug')); ?>)</option>
						<?php endif; ?>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="col-md-2 text-center align-self-end">
				<p class="mb-2"><strong>&rarr;</strong></p>
			</div>
			<div class="col-md-5">
				<label class="form-label"><strong>Target</strong> (this node)</label>
				<input type="text" class="form-control" value="<?php echo $node_name; ?> (<?php echo htmlspecialchars($node->get('mgn_slug')); ?>)" disabled>
			</div>
		</div>
		<button type="submit" class="btn btn-danger" onclick="return confirm('Are you sure? This will overwrite the database on <?php echo $node_name; ?>.')">Copy Database</button>
	</form>
<?php
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
	<?php else: ?>
		<form method="post">
			<input type="hidden" name="action" value="copy_database_local">
			<div class="row mb-3">
				<div class="col-md-5">
					<label class="form-label"><strong>Source Database</strong></label>
					<select name="source_db_name" class="form-select" required>
						<option value="">Select source...</option>
						<?php foreach ($other_dbs as $db): ?>
							<option value="<?php echo htmlspecialchars($db); ?>"><?php echo htmlspecialchars($db); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="col-md-2 text-center align-self-end">
					<p class="mb-2"><strong>&rarr;</strong></p>
				</div>
				<div class="col-md-5">
					<label class="form-label"><strong>Target</strong> (this node)</label>
					<input type="text" class="form-control" value="<?php echo $current_db ? htmlspecialchars($current_db) : $node_name; ?>" disabled>
				</div>
			</div>
			<button type="submit" class="btn btn-danger" onclick="return confirm('Are you sure? This will overwrite the database on <?php echo $node_name; ?>.')">Copy Database</button>
		</form>
	<?php endif; ?>
<?php
	$page->end_box();

	// Filter backup list to database backups only (.sql.gz / .sql.gz.enc)
	$restore_files = [];
	if (!empty($backup_list_raw['files'])) {
		foreach ($backup_list_raw['files'] as $f) {
			$fn = $f['filename'] ?? '';
			if (preg_match('/\.sql\.gz(\.enc)?$/', $fn)) {
				$restore_files[] = $f;
			}
		}
	}

	$pageoptions = ['title' => 'Restore Database from Backup'];
	$page->begin_box($pageoptions);
?>
	<?php if (empty($restore_files)): ?>
		<p class="text-muted" id="dbTabRefreshEmpty">
			No database backups found.
			<?php if ($backup_list_time): ?>
				Last refreshed <?php echo LibraryFunctions::convert_time($backup_list_time, 'UTC', $session->get_timezone(), 'M j, g:i A'); ?>.
			<?php endif; ?>
		</p>
	<?php else: ?>
		<form method="post">
			<input type="hidden" name="action" value="restore_database">
			<div class="mb-3">
				<label class="form-label">Backup file</label>
				<select name="backup_filename" id="restore_backup_select" class="form-select" required
					onchange="
						var opts = this.options[this.selectedIndex].dataset;
						document.getElementById('restore_local_path').value = opts.localPath || '';
						document.getElementById('restore_cloud_path').value = opts.cloudPath || '';
					">
					<option value="">Select backup...</option>
					<?php foreach ($restore_files as $f): ?>
						<?php
						$loc_badge = match($f['location'] ?? '') {
							'both'  => ' [local + cloud]',
							'cloud' => ' [cloud only]',
							default => ' [local]',
						};
						$label = $f['filename'] . ' — ' . ($f['size'] ?? '') . ' — ' . ($f['date'] ?? '') . $loc_badge;
						?>
						<option value="<?php echo htmlspecialchars($f['filename']); ?>"
							data-local-path="<?php echo htmlspecialchars($f['local_path'] ?? ''); ?>"
							data-cloud-path="<?php echo htmlspecialchars($f['cloud_path'] ?? ''); ?>">
							<?php echo htmlspecialchars($label); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<small class="text-muted" id="dbTabRefreshStatus">
					<?php if ($backup_list_time): ?>
						List refreshed <?php echo LibraryFunctions::convert_time($backup_list_time, 'UTC', $session->get_timezone(), 'M j, g:i A'); ?>.
					<?php endif; ?>
				</small>
			</div>
			<input type="hidden" name="backup_local_path" id="restore_local_path" value="">
			<input type="hidden" name="backup_cloud_path" id="restore_cloud_path" value="">
			<button type="submit" class="btn btn-danger" onclick="return confirm('Are you sure? This will overwrite the database.')">Restore Database</button>
		</form>
	<?php endif; ?>
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

<script>
// Auto-refresh local backup listing on page load if stale. Matches the Backups tab behavior.
(function() {
	var nodeId = <?php echo $node->key; ?>;
	var lastScanAge = <?php echo $backup_list_time ? (time() - strtotime($backup_list_time)) : 99999; ?>;
	var STALE_SECONDS = 60;

	if (lastScanAge <= STALE_SECONDS) return;

	function showRefreshing() {
		var spinner = '<span class="spinner-border spinner-border-sm me-1"></span>Refreshing backup list...';
		var el1 = document.getElementById('dbTabRefreshStatus');
		if (el1) el1.innerHTML = spinner;
		var el2 = document.getElementById('dbTabRefreshEmpty');
		if (el2) el2.innerHTML = spinner;
	}

	function poll(jobId) {
		fetch('/ajax/backup_actions?action=list_status&node_id=' + nodeId + '&job_id=' + jobId)
			.then(function(r) { return r.json(); })
			.then(function(data) {
				if (data.status === 'pending' || data.status === 'running') {
					setTimeout(function() { poll(jobId); }, 2000);
					return;
				}
				window.location.reload();
			})
			.catch(function() { setTimeout(function() { poll(jobId); }, 3000); });
	}

	window.addEventListener('DOMContentLoaded', function() {
		showRefreshing();
		fetch('/ajax/backup_actions?action=refresh_list&node_id=' + nodeId)
			.then(function(r) { return r.json(); })
			.then(function(data) {
				if (data.success && data.job_id) poll(data.job_id);
			})
			.catch(function() {});
	});
})();
</script>

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
		<form method="post" style="display:inline">
			<input type="hidden" name="action" value="apply_update">
			<input type="hidden" name="dry_run" value="1">
			<button type="submit" class="btn btn-sm btn-outline-secondary">Dry Run</button>
		</form>
		<form method="post" style="display:inline">
			<input type="hidden" name="action" value="refresh_archives">
			<button type="submit" class="btn btn-sm btn-outline-secondary" onclick="return confirm('Refresh archives and apply to <?php echo $node_name; ?>?')">Refresh &amp; Apply</button>
		</form>
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
			<form method="get" class="row g-2 align-items-end">
				<input type="hidden" name="mgn_id" value="<?php echo $node->key; ?>">
				<input type="hidden" name="tab" value="jobs">
				<div class="col-auto">
					<label class="form-label">Status</label>
					<select name="status" class="form-select form-select-sm">
						<option value="">All</option>
						<?php foreach (['pending', 'running', 'completed', 'failed', 'cancelled'] as $s): ?>
							<option value="<?php echo $s; ?>" <?php echo (isset($_GET['status']) && $_GET['status'] === $s) ? 'selected' : ''; ?>><?php echo ucfirst($s); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="col-auto">
					<label class="form-label">Type</label>
					<select name="job_type" class="form-select form-select-sm">
						<option value="">All</option>
						<?php foreach (['check_status', 'backup_database', 'backup_project', 'fetch_backup', 'copy_database', 'copy_database_local', 'restore_database', 'apply_update', 'refresh_archives'] as $t): ?>
							<option value="<?php echo $t; ?>" <?php echo (isset($_GET['job_type']) && $_GET['job_type'] === $t) ? 'selected' : ''; ?>><?php echo str_replace('_', ' ', $t); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="col-auto">
					<button type="submit" class="btn btn-sm btn-primary">Filter</button>
					<a href="<?php echo $base_url; ?>&tab=jobs" class="btn btn-sm btn-outline-secondary">Clear</a>
				</div>
				<div class="col-auto ms-auto">
					<a href="/admin/server_manager/jobs" class="btn btn-sm btn-outline-secondary">View All Jobs</a>
				</div>
			</form>
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

} // end tab switch

$page->admin_footer();
?>
