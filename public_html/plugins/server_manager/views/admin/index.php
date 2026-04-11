<?php
/**
 * Server Manager Dashboard
 * URL: /admin/server_manager
 *
 * @version 1.1
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/agent_heartbeat_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobResultProcessor.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);
$session->set_return();

// Process any completed check_status jobs that haven't been processed yet.
// This catches cases where the user navigated away from job_detail before the
// AJAX poll could trigger the result processor.
$db = DbConnector::get_instance()->get_db_link();
$q = $db->query("SELECT mjb_id FROM mjb_management_jobs WHERE mjb_status = 'completed' AND mjb_job_type = 'check_status' AND mjb_result IS NULL AND mjb_delete_time IS NULL");
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
	$unprocessed_job = new ManagementJob($row['mjb_id'], TRUE);
	JobResultProcessor::process($unprocessed_job);
}

// Load nodes
$nodes = new MultiManagedNode(['deleted' => false, 'enabled' => true], ['mgn_name' => 'ASC']);
$nodes->load();

// Load recent jobs
$recent_jobs = new MultiManagementJob(['deleted' => false], ['mjb_id' => 'DESC'], 20);
$recent_jobs->load();

// Agent heartbeat
$agent = AgentHeartbeat::getLatest();

$page = new AdminPage();
$page->admin_header([
	'menu-id' => 'server-manager',
	'page_title' => 'Server Manager',
	'readable_title' => 'Server Manager',
	'breadcrumbs' => ['Server Manager' => ''],
	'session' => $session,
]);

// Agent Status
$agent_online = $agent && $agent->is_online();
$agent_class = $agent_online ? 'success' : 'danger';
$agent_label = $agent_online ? 'Online' : 'Offline';
?>

<div class="row mb-4">
	<div class="col-md-12">
		<div class="card">
			<div class="card-body">
				<h5 class="card-title">Agent Status</h5>
				<span class="badge bg-<?php echo $agent_class; ?>"><?php echo $agent_label; ?></span>
				<?php if ($agent): ?>
					<?php if ($agent->get('ahb_agent_version')): ?>
						<span class="text-muted ms-2">v<?php echo htmlspecialchars($agent->get('ahb_agent_version')); ?></span>
					<?php endif; ?>
					<span class="text-muted ms-2">
						Last heartbeat: <?php echo LibraryFunctions::convert_time($agent->get('ahb_last_heartbeat'), 'UTC', $session->get_timezone(), 'M j, g:i:s A'); ?>
					</span>
				<?php else: ?>
					<span class="text-muted ms-2">No agent has connected yet</span>
				<?php endif; ?>
				<?php if (!$agent_online): ?>
					<div class="mt-2">
						<small class="text-muted">
							<?php if (!$agent): ?>
								The joinery-agent service needs to be installed and started on this server.
								<ol class="mt-1 mb-0">
									<li><code>cd /home/user1/joinery-agent && make release VERSION=1.0.0</code></li>
									<li><code>sudo bash joinery-agent-installer.sh --verbose</code></li>
									<li>Edit <code>/etc/joinery-agent/joinery-agent.env</code> with your database credentials</li>
									<li><code>sudo systemctl start joinery-agent</code></li>
								</ol>
							<?php else: ?>
								The agent was last seen at <?php echo LibraryFunctions::convert_time($agent->get('ahb_last_heartbeat'), 'UTC', $session->get_timezone(), 'M j, g:i:s A'); ?>.
								Check if the service is running: <code>sudo systemctl status joinery-agent</code>
								&mdash; View logs: <code>journalctl -u joinery-agent -f</code>
							<?php endif; ?>
						</small>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>

<!-- Node Cards -->
<div class="row mb-4">
	<?php foreach ($nodes as $node): ?>
		<?php
		$status_data = $node->get('mgn_last_status_data');
		if (is_string($status_data)) {
			$status_data = json_decode($status_data, true);
		}
		$last_check = $node->get('mgn_last_status_check');

		// Check if the most recent check_status job for this node failed
		$last_job_failed = false;
		$last_job_q = $db->prepare("SELECT mjb_status FROM mjb_management_jobs WHERE mjb_mgn_node_id = ? AND mjb_job_type = 'check_status' AND mjb_delete_time IS NULL ORDER BY mjb_id DESC LIMIT 1");
		$last_job_q->execute([$node->key]);
		$last_job_row = $last_job_q->fetch(PDO::FETCH_ASSOC);
		if ($last_job_row && $last_job_row['mjb_status'] === 'failed') {
			$last_job_failed = true;
		}

		// Dot color reflects actual health, not recency
		// Red: real problem (failed check, disk > 90%, postgres down)
		// Yellow: warning threshold (disk > 80%, high load)
		// Green: healthy
		// Gray: no data yet
		if (!$last_check || !$status_data) {
			$status_color = 'secondary';
		} elseif ($last_job_failed) {
			$status_color = 'danger';
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
		?>
		<div class="col-md-6 col-lg-4 mb-3">
			<div class="card">
				<div class="card-header d-flex justify-content-between align-items-center">
					<strong><?php echo htmlspecialchars($node->get('mgn_name')); ?></strong>
					<span class="badge bg-<?php echo $status_color; ?>">&bull;</span>
				</div>
				<div class="card-body">
					<?php if ($node->get('mgn_site_url')): ?>
						<p class="mb-1"><small><a href="<?php echo htmlspecialchars($node->get('mgn_site_url')); ?>" target="_blank"><?php echo htmlspecialchars($node->get('mgn_site_url')); ?></a></small></p>
					<?php endif; ?>
					<?php if ($node->get('mgn_joinery_version')): ?>
						<p class="mb-1"><small>Version: <?php echo htmlspecialchars($node->get('mgn_joinery_version')); ?></small></p>
					<?php endif; ?>
					<?php if ($status_data): ?>
						<?php if (isset($status_data['disk_usage_percent'])): ?>
							<p class="mb-1"><small>Disk: <?php echo $status_data['disk_usage_percent']; ?>% used</small></p>
						<?php endif; ?>
						<?php if (isset($status_data['memory_used_mb']) && isset($status_data['memory_total_mb'])): ?>
							<p class="mb-1"><small>Memory: <?php echo $status_data['memory_used_mb']; ?> / <?php echo $status_data['memory_total_mb']; ?> MB</small></p>
						<?php endif; ?>
						<?php if (isset($status_data['load_1m'])): ?>
							<p class="mb-1"><small>Load: <?php echo $status_data['load_1m']; ?></small></p>
						<?php endif; ?>
					<?php endif; ?>
					<?php if ($last_check): ?>
						<p class="mb-0 text-muted"><small>Checked: <?php echo LibraryFunctions::convert_time($last_check, 'UTC', $session->get_timezone(), 'M j, g:i A'); ?></small></p>
					<?php endif; ?>
				</div>
				<div class="card-footer">
					<form method="post" action="/admin/server_manager/nodes_edit?mgn_id=<?php echo $node->key; ?>&action=check_status" style="display:inline">
						<button type="submit" class="btn btn-sm btn-outline-primary">Check Status</button>
					</form>
					<a href="/admin/server_manager/jobs?node_id=<?php echo $node->key; ?>" class="btn btn-sm btn-outline-secondary">Jobs</a>
					<a href="/admin/server_manager/nodes_edit?mgn_id=<?php echo $node->key; ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
				</div>
			</div>
		</div>
	<?php endforeach; ?>
</div>

<?php if (count($nodes) == 0): ?>
	<div class="alert alert-info">
		<strong>No managed nodes configured yet.</strong>
		Nodes represent the remote Joinery servers you want to manage (backups, updates, status checks).
		<a href="/admin/server_manager/nodes_edit" class="alert-link">Add your first node</a> to get started.
		You will need: the server's SSH host/IP, an SSH key path, and (for Docker setups) the container name.
	</div>
<?php endif; ?>

<!-- Recent Jobs -->
<?php
$pageoptions = ['title' => 'Recent Jobs', 'altlinks' => ['All Jobs' => '/admin/server_manager/jobs']];
$page->begin_box($pageoptions);
?>

<table class="table table-striped table-sm">
	<thead>
		<tr>
			<th>ID</th>
			<th>Node</th>
			<th>Type</th>
			<th>Status</th>
			<th>Started</th>
			<th>Duration</th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ($recent_jobs as $job): ?>
			<?php
			$status_class = match($job->get('mjb_status')) {
				'completed' => 'success',
				'failed' => 'danger',
				'running' => 'primary',
				'cancelled' => 'secondary',
				default => 'warning',
			};
			$duration = '';
			if ($job->get('mjb_started_time') && $job->get('mjb_completed_time')) {
				$diff = strtotime($job->get('mjb_completed_time')) - strtotime($job->get('mjb_started_time'));
				$duration = $diff < 60 ? "{$diff}s" : round($diff / 60, 1) . 'm';
			} elseif ($job->get('mjb_started_time')) {
				$diff = time() - strtotime($job->get('mjb_started_time'));
				$duration = ($diff < 60 ? "{$diff}s" : round($diff / 60, 1) . 'm') . '...';
			}

			// Look up node name
			$node_name = '-';
			$node_id = $job->get('mjb_mgn_node_id');
			if ($node_id) {
				try {
					$job_node = new ManagedNode($node_id, TRUE);
					$node_name = $job_node->get('mgn_name');
				} catch (Exception $e) {
					$node_name = "Node #{$node_id}";
				}
			}
			?>
			<tr>
				<td><a href="/admin/server_manager/job_detail?job_id=<?php echo $job->key; ?>">#<?php echo $job->key; ?></a></td>
				<td><?php echo htmlspecialchars($node_name); ?></td>
				<td><?php echo htmlspecialchars(str_replace('_', ' ', $job->get('mjb_job_type'))); ?></td>
				<td><span class="badge bg-<?php echo $status_class; ?>"><?php echo htmlspecialchars($job->get('mjb_status')); ?></span></td>
				<td><?php echo $job->get('mjb_started_time') ? LibraryFunctions::convert_time($job->get('mjb_started_time'), 'UTC', $session->get_timezone(), 'M j, g:i A') : '-'; ?></td>
				<td><?php echo $duration; ?></td>
			</tr>
		<?php endforeach; ?>
		<?php if (count($recent_jobs) == 0): ?>
			<tr><td colspan="6" class="text-muted text-center">No jobs yet</td></tr>
		<?php endif; ?>
	</tbody>
</table>

<?php
$page->end_box();
$page->admin_footer();
?>
