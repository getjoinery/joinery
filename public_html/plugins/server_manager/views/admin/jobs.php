<?php
/**
 * Server Manager - Job History
 * URL: /admin/server_manager/jobs
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/Pager.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);
$session->set_return();

$numperpage = 30;
$offset = LibraryFunctions::fetch_variable_local($_GET, 'offset', 0);
$sort = LibraryFunctions::fetch_variable_local($_GET, 'sort', 'mjb_id');
$sdirection = LibraryFunctions::fetch_variable_local($_GET, 'sdirection', 'DESC');

$search_criteria = ['deleted' => false];

// Optional filters
if (isset($_GET['node_id']) && $_GET['node_id']) {
	$search_criteria['node_id'] = intval($_GET['node_id']);
}
if (isset($_GET['status']) && $_GET['status']) {
	$search_criteria['status'] = $_GET['status'];
}
if (isset($_GET['job_type']) && $_GET['job_type']) {
	$search_criteria['job_type'] = $_GET['job_type'];
}

$jobs = new MultiManagementJob($search_criteria, [$sort => $sdirection], $numperpage, $offset);
$numrecords = $jobs->count_all();
$jobs->load();

// Load all nodes for filter dropdown and name lookup
$all_nodes = new MultiManagedNode(['deleted' => false], ['mgn_name' => 'ASC']);
$all_nodes->load();
$node_map = [];
foreach ($all_nodes as $n) {
	$node_map[$n->key] = $n->get('mgn_name');
}

$page = new AdminPage();
$page->admin_header([
	'menu-id' => 'server-manager',
	'page_title' => 'Jobs',
	'readable_title' => 'Jobs',
	'breadcrumbs' => [
		'Server Manager' => '/admin/server_manager',
		'Jobs' => '',
	],
	'session' => $session,
]);

// Filter bar
?>
<div class="card mb-3">
	<div class="card-body">
		<form method="get" class="row g-2 align-items-end">
			<div class="col-auto">
				<label class="form-label">Node</label>
				<select name="node_id" class="form-select form-select-sm">
					<option value="">All Nodes</option>
					<?php foreach ($all_nodes as $n): ?>
						<option value="<?php echo $n->key; ?>" <?php echo (isset($_GET['node_id']) && $_GET['node_id'] == $n->key) ? 'selected' : ''; ?>><?php echo htmlspecialchars($n->get('mgn_name')); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
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
					<?php foreach (['check_status', 'backup_database', 'backup_project', 'fetch_backup', 'copy_database', 'copy_database_local', 'restore_database', 'apply_update', 'refresh_archives', 'publish_upgrade'] as $t): ?>
						<option value="<?php echo $t; ?>" <?php echo (isset($_GET['job_type']) && $_GET['job_type'] === $t) ? 'selected' : ''; ?>><?php echo str_replace('_', ' ', $t); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="col-auto">
				<button type="submit" class="btn btn-sm btn-primary">Filter</button>
				<a href="/admin/server_manager/jobs" class="btn btn-sm btn-outline-secondary">Clear</a>
			</div>
		</form>
	</div>
</div>
<?php

$headers = ['ID', 'Node', 'Type', 'Status', 'Progress', 'Created', 'Duration'];
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
		'completed' => 'success',
		'failed' => 'danger',
		'running' => 'primary',
		'cancelled' => 'secondary',
		default => 'warning',
	};

	$node_id = $job->get('mjb_mgn_node_id');
	$node_name = $node_id && isset($node_map[$node_id]) ? $node_map[$node_id] : ($node_id ? "#{$node_id}" : 'Local');

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
	array_push($rowvalues, '<a href="/admin/server_manager/job_detail?job_id=' . $job->key . '">#' . $job->key . '</a>');
	array_push($rowvalues, htmlspecialchars($node_name));
	array_push($rowvalues, htmlspecialchars(str_replace('_', ' ', $job->get('mjb_job_type'))));
	array_push($rowvalues, '<span class="badge bg-' . $status_class . '">' . htmlspecialchars($job->get('mjb_status')) . '</span>');
	array_push($rowvalues, $progress);
	array_push($rowvalues, LibraryFunctions::convert_time($job->get('mjb_create_time'), 'UTC', $session->get_timezone(), 'M j, g:i A'));
	array_push($rowvalues, $duration);

	$page->disprow($rowvalues);
}

$page->endtable($pager);
$page->admin_footer();
?>
