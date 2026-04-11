<?php
/**
 * Server Manager - Backups
 * URL: /admin/server_manager/backups
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);
$session->set_return();

// Load nodes for dropdown
$all_nodes = new MultiManagedNode(['deleted' => false, 'enabled' => true], ['mgn_name' => 'ASC']);
$all_nodes->load();
$node_map = [];
foreach ($all_nodes as $n) {
	$node_map[$n->key] = $n;
}

// Handle backup actions
if ($_POST && isset($_POST['action'])) {
	$page_regex = '/\/admin\/server_manager/';
	$node_id = intval($_POST['node_id'] ?? 0);

	if ($node_id && isset($node_map[$node_id])) {
		$node = $node_map[$node_id];

		if ($_POST['action'] === 'backup_database') {
			$params = [
				'encryption' => !empty($_POST['encryption']),
			];
			$steps = JobCommandBuilder::build_backup_database($node, $params);
			$job = ManagementJob::createJob($node_id, 'backup_database', $steps, $params, $session->get_user_id());
			header('Location: /admin/server_manager/job_detail?job_id=' . $job->key);
			exit;
		}

		if ($_POST['action'] === 'backup_project') {
			$params = [
				'encryption' => !empty($_POST['encryption']),
			];
			$steps = JobCommandBuilder::build_backup_project($node, $params);
			$job = ManagementJob::createJob($node_id, 'backup_project', $steps, $params, $session->get_user_id());
			header('Location: /admin/server_manager/job_detail?job_id=' . $job->key);
			exit;
		}

		if ($_POST['action'] === 'fetch_backup') {
			$params = ['remote_path' => trim($_POST['remote_path'] ?? '')];
			if ($params['remote_path']) {
				$steps = JobCommandBuilder::build_fetch_backup($node, $params);
				$job = ManagementJob::createJob($node_id, 'fetch_backup', $steps, $params, $session->get_user_id());
				header('Location: /admin/server_manager/job_detail?job_id=' . $job->key);
				exit;
			}
		}
	}
}

// Load recent backup jobs
$backup_criteria = ['deleted' => false];
if (isset($_GET['node_id']) && $_GET['node_id']) {
	$backup_criteria['node_id'] = intval($_GET['node_id']);
}
$backup_jobs = new MultiManagementJob($backup_criteria, ['mjb_id' => 'DESC'], 30);
$backup_jobs->load();

// Filter to backup-type jobs only
$filtered_jobs = [];
foreach ($backup_jobs as $j) {
	if (in_array($j->get('mjb_job_type'), ['backup_database', 'backup_project', 'fetch_backup'])) {
		$filtered_jobs[] = $j;
	}
}

$page = new AdminPage();
$page->admin_header([
	'menu-id' => 'server-manager',
	'page_title' => 'Backups',
	'readable_title' => 'Backups',
	'breadcrumbs' => [
		'Server Manager' => '/admin/server_manager',
		'Backups' => '',
	],
	'session' => $session,
]);

// Display messages
$display_messages = $session->get_messages('/admin/server_manager');
if (!empty($display_messages)) {
	foreach ($display_messages as $msg) {
		$alert_class = $msg->display_type == DisplayMessage::MESSAGE_ERROR ? 'alert-danger' : 'alert-success';
		echo '<div class="alert ' . $alert_class . ' alert-dismissible fade show">';
		echo htmlspecialchars($msg->message);
		echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
	}
	$session->clear_clearable_messages();
}

if (count($all_nodes) == 0) {
	echo '<div class="alert alert-info">';
	echo '<strong>No managed nodes configured.</strong> ';
	echo 'You need to <a href="/admin/server_manager/nodes_edit" class="alert-link">add a node</a> before you can run backups. ';
	echo 'A node represents a remote Joinery server you want to back up.';
	echo '</div>';
	$page->admin_footer();
	return;
}

// Backup Database form
$pageoptions = ['title' => 'Run Backup'];
$page->begin_box($pageoptions);
?>
<div class="row">
	<div class="col-md-6">
		<h6>Database Backup</h6>
		<form method="post">
			<input type="hidden" name="action" value="backup_database">
			<div class="mb-2">
				<label class="form-label">Node</label>
				<select name="node_id" class="form-select form-select-sm" required>
					<option value="">Select node...</option>
					<?php foreach ($all_nodes as $n): ?>
						<option value="<?php echo $n->key; ?>"><?php echo htmlspecialchars($n->get('mgn_name')); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="mb-2 form-check">
				<input type="checkbox" name="encryption" class="form-check-input" id="db_encrypt">
				<label class="form-check-label" for="db_encrypt">Encrypt backup</label>
			</div>
			<button type="submit" class="btn btn-sm btn-primary">Run Database Backup</button>
		</form>
	</div>
	<div class="col-md-6">
		<h6>Full Project Backup</h6>
		<form method="post">
			<input type="hidden" name="action" value="backup_project">
			<div class="mb-2">
				<label class="form-label">Node</label>
				<select name="node_id" class="form-select form-select-sm" required>
					<option value="">Select node...</option>
					<?php foreach ($all_nodes as $n): ?>
						<option value="<?php echo $n->key; ?>"><?php echo htmlspecialchars($n->get('mgn_name')); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="mb-2 form-check">
				<input type="checkbox" name="encryption" class="form-check-input" id="proj_encrypt">
				<label class="form-check-label" for="proj_encrypt">Encrypt backup</label>
			</div>
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
				<label class="form-label">Node</label>
				<select name="node_id" class="form-select form-select-sm" required>
					<option value="">Select node...</option>
					<?php foreach ($all_nodes as $n): ?>
						<option value="<?php echo $n->key; ?>"><?php echo htmlspecialchars($n->get('mgn_name')); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
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

// Recent backup jobs table
$pageoptions = ['title' => 'Recent Backup Jobs'];
$page->begin_box($pageoptions);
?>
<table class="table table-striped table-sm">
	<thead>
		<tr><th>ID</th><th>Node</th><th>Type</th><th>Status</th><th>Created</th></tr>
	</thead>
	<tbody>
		<?php foreach ($filtered_jobs as $job): ?>
			<?php
			$sc = match($job->get('mjb_status')) {
				'completed' => 'success', 'failed' => 'danger', 'running' => 'primary', default => 'warning',
			};
			$nn = $job->get('mjb_mgn_node_id') && isset($node_map[$job->get('mjb_mgn_node_id')])
				? $node_map[$job->get('mjb_mgn_node_id')]->get('mgn_name') : '-';
			?>
			<tr>
				<td><a href="/admin/server_manager/job_detail?job_id=<?php echo $job->key; ?>">#<?php echo $job->key; ?></a></td>
				<td><?php echo htmlspecialchars($nn); ?></td>
				<td><?php echo htmlspecialchars(str_replace('_', ' ', $job->get('mjb_job_type'))); ?></td>
				<td><span class="badge bg-<?php echo $sc; ?>"><?php echo htmlspecialchars($job->get('mjb_status')); ?></span></td>
				<td><?php echo LibraryFunctions::convert_time($job->get('mjb_create_time'), 'UTC', $session->get_timezone(), 'M j, g:i A'); ?></td>
			</tr>
		<?php endforeach; ?>
		<?php if (empty($filtered_jobs)): ?>
			<tr><td colspan="5" class="text-muted text-center">No backup jobs yet</td></tr>
		<?php endif; ?>
	</tbody>
</table>

<?php
$page->end_box();
$page->admin_footer();
?>
