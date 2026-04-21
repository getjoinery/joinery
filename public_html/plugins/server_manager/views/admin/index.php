<?php
/**
 * Server Manager Dashboard
 * URL: /admin/server_manager
 *
 * @version 1.3
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/agent_heartbeat_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobResultProcessor.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);
$session->set_return();

// Process any completed check_status jobs that haven't been processed yet.
// This catches cases where the user navigated away from job_detail before the
// AJAX poll could trigger the result processor.
$db = DbConnector::get_instance()->get_db_link();
// Skip jobs whose node has been soft-deleted — processing them would spawn
// chained check_status jobs against hosts that no longer exist, producing
// spurious failures (see VPS-A cleanup, 2026-04-21).
$q = $db->query(
	"SELECT j.mjb_id FROM mjb_management_jobs j " .
	"JOIN mgn_managed_nodes n ON n.mgn_id = j.mjb_mgn_node_id " .
	"WHERE j.mjb_status IN ('completed','failed') " .
	"  AND j.mjb_job_type IN ('check_status','install_node','apply_update','refresh_archives') " .
	"  AND j.mjb_result IS NULL " .
	"  AND j.mjb_delete_time IS NULL " .
	"  AND n.mgn_delete_time IS NULL"
);
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

$agent_online = $agent && $agent->is_online();
$agent_class  = $agent_online ? 'success' : 'danger';
$agent_label  = $agent_online ? 'Online'  : 'Offline';
?>

<style>
	.node-row { cursor: pointer; transition: background-color 0.12s ease; min-height: 58px; }
	.node-row:hover { background-color: #f8f9fa; }
</style>

<!-- Agent Status Bar -->
<div class="card mb-4">
	<div class="card-body d-flex justify-content-between align-items-center">
		<div>
			<strong>Agent Status:</strong>
			<span class="badge bg-<?php echo $agent_class; ?> ms-1"><?php echo $agent_label; ?></span>
			<?php if ($agent): ?>
				<?php if ($agent->get('ahb_agent_version')): ?>
					<span class="text-muted ms-2">v<?php echo htmlspecialchars($agent->get('ahb_agent_version')); ?></span>
				<?php endif; ?>
				<span class="text-muted ms-2">
					Last heartbeat: <?php echo LibraryFunctions::time_ago_or_time($agent->get('ahb_last_heartbeat'), 'UTC', $session->get_timezone(), 'M j, g:i:s A'); ?>
				</span>
			<?php else: ?>
				<span class="text-muted ms-2">No agent has connected yet</span>
			<?php endif; ?>
		</div>
		<a href="/admin/server_manager/publish_upgrade" class="btn btn-sm btn-primary">Publish New Upgrade</a>
	</div>
	<?php if (!$agent_online): ?>
		<div class="card-footer">
			<small class="text-muted">
				<?php if (!$agent): ?>
					The joinery-agent service runs on the control plane and services all connected sites.
					Install it here: <code>cd /home/user1/joinery-agent &amp;&amp; make release VERSION=1.0.0 &amp;&amp; sudo bash joinery-agent-installer.sh --verbose</code>
				<?php else: ?>
					The agent was last seen <?php echo LibraryFunctions::time_ago_or_time($agent->get('ahb_last_heartbeat'), 'UTC', $session->get_timezone(), 'M j, g:i:s A'); ?>.
					Check: <code>sudo systemctl status joinery-agent</code> &mdash; <code>journalctl -u joinery-agent -f</code>
				<?php endif; ?>
			</small>
		</div>
	<?php endif; ?>
</div>

<!-- Two-column layout: Nodes (left) | Recent Jobs (right) -->
<div class="row">
	<!-- LEFT: Sites -->
	<div class="col-md-6 mb-4">
		<?php
		$pageoptions = [
			'title' => 'Sites',
			'altlinks' => [
				'Connect Site'   => '/admin/server_manager/node_add',
				'Remote Install' => '/admin/server_manager/install_node_form',
			],
		];
		$page->begin_box($pageoptions);
		?>

		<?php if (count($nodes) == 0): ?>
			<div class="alert alert-info mb-0">
				<strong>No sites configured yet.</strong>
				<a href="/admin/server_manager/node_add" class="alert-link">Connect your first site</a> to get started.
			</div>
		<?php else: ?>
			<div class="list-group list-group-flush">
				<?php foreach ($nodes as $node): ?>
					<?php
					$status_data = $node->get('mgn_last_status_data');
					if (is_string($status_data)) $status_data = json_decode($status_data, true);
					$last_check = $node->get('mgn_last_status_check');

					// Most recent check_status job failed?
					$last_job_failed = false;
					$last_job_q = $db->prepare("SELECT mjb_status FROM mjb_management_jobs WHERE mjb_mgn_node_id = ? AND mjb_job_type = 'check_status' AND mjb_delete_time IS NULL ORDER BY mjb_id DESC LIMIT 1");
					$last_job_q->execute([$node->key]);
					$last_job_row = $last_job_q->fetch(PDO::FETCH_ASSOC);
					if ($last_job_row && $last_job_row['mjb_status'] === 'failed') {
						$last_job_failed = true;
					}

					$install_state = $node->get('mgn_install_state');
					if ($install_state === 'installing') {
						$status_color = 'info';
					} elseif ($install_state === 'install_failed') {
						$status_color = 'danger';
					} elseif ($last_job_failed) {
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

					// Version comparison
					$node_version = $node->get('mgn_joinery_version');
					$version_cmp = null;
					if ($node_version) {
						$cp_version = LibraryFunctions::get_joinery_version();
						if ($cp_version !== '' && preg_match('/^\d+\.\d+\.\d+$/', $node_version)) {
							$version_cmp = version_compare($node_version, $cp_version);
						}
					}
					?>
					<?php
					$api_refreshable = JobCommandBuilder::has_api_creds($node)
						&& !in_array($install_state, ['installing', 'install_failed'], true);
					?>
					<div class="list-group-item node-row d-flex justify-content-between align-items-center"
						data-href="/admin/server_manager/node_detail?mgn_id=<?php echo $node->key; ?>"
						data-node-id="<?php echo $node->key; ?>"
						data-api-refreshable="<?php echo $api_refreshable ? '1' : '0'; ?>"
						onclick="if(!event.target.closest('form,button,input,a')) window.location=this.dataset.href">
						<div class="d-flex align-items-center" style="min-width:0;flex:1">
							<span class="badge bg-<?php echo $status_color; ?> me-2 js-status-badge">&bull;</span>
							<div style="min-width:0">
								<strong><?php echo htmlspecialchars($node->get('mgn_name')); ?></strong>
								<?php if ($install_state === 'installing'): ?>
									<span class="badge bg-info ms-1">Installing…</span>
								<?php elseif ($install_state === 'install_failed'): ?>
									<span class="badge bg-danger ms-1">Install failed</span>
								<?php endif; ?>
								<span class="js-version-indicator">
									<?php if ($version_cmp === -1): ?>
										<span class="badge bg-warning ms-1" title="Control plane is at <?php echo htmlspecialchars($cp_version); ?>">upgrade available</span>
									<?php elseif ($version_cmp === 1): ?>
										<span class="badge bg-danger ms-1" title="Control plane is at <?php echo htmlspecialchars($cp_version); ?>">ahead of control plane</span>
									<?php endif; ?>
								</span>
								<small class="text-muted ms-1 js-last-check"><?php
									if ($last_check) {
										echo '(' . htmlspecialchars(LibraryFunctions::time_ago_or_time($last_check, 'UTC', $session->get_timezone(), 'M j, g:i A')) . ')';
									}
								?></small>
								<?php if ($node->get('mgn_site_url')): ?>
									<div><small class="text-muted"><?php echo htmlspecialchars($node->get('mgn_site_url')); ?></small></div>
								<?php endif; ?>
							</div>
						</div>
						<form method="post" action="/admin/server_manager/node_detail?mgn_id=<?php echo $node->key; ?>" style="flex-shrink:0">
							<input type="hidden" name="action" value="check_status">
							<button type="submit" class="btn btn-sm btn-outline-primary">Check Status</button>
						</form>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<?php $page->end_box(); ?>
	</div>

	<!-- RIGHT: Recent Jobs -->
	<div class="col-md-6 mb-4">
		<?php
		$pageoptions = ['title' => 'Recent Jobs', 'altlinks' => ['All Jobs' => '/admin/server_manager/jobs']];
		$page->begin_box($pageoptions);
		?>
		<table class="table table-striped table-sm mb-0">
			<thead>
				<tr>
					<th>ID</th>
					<th>Site</th>
					<th>Type</th>
					<th>Status</th>
					<th>Started</th>
					<th>Dur.</th>
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
						<td><?php echo $job->get('mjb_started_time') ? LibraryFunctions::time_ago_or_time($job->get('mjb_started_time'), 'UTC', $session->get_timezone(), 'M j, g:i A') : '-'; ?></td>
						<td><?php echo $duration; ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if (count($recent_jobs) == 0): ?>
					<tr><td colspan="6" class="text-muted text-center">No jobs yet</td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php $page->end_box(); ?>
	</div>
</div>

<script>
// Auto-refresh status for nodes that have API credentials. Fires once on page
// load, in parallel, bypassing the agent/job pipeline. Silent on failure — the
// pre-rendered badge (from last stored status) stays as the fallback.
(function() {
	var rows = document.querySelectorAll('.node-row[data-api-refreshable="1"]');
	if (!rows.length) return;

	var colorClasses = ['bg-secondary','bg-success','bg-warning','bg-danger','bg-info','bg-primary'];

	rows.forEach(function(row) {
		var nodeId = row.getAttribute('data-node-id');
		var badge = row.querySelector('.js-status-badge');
		var versionSpan = row.querySelector('.js-version-indicator');
		var lastCheckSpan = row.querySelector('.js-last-check');
		if (badge) badge.style.opacity = '0.4';

		fetch('/ajax/refresh_node_status?node_id=' + encodeURIComponent(nodeId), { credentials: 'same-origin' })
			.then(function(r) { return r.json(); })
			.then(function(j) {
				if (badge) badge.style.opacity = '';
				if (!j.ok) return;

				if (badge && j.status_color) {
					colorClasses.forEach(function(c) { badge.classList.remove(c); });
					badge.classList.add('bg-' + j.status_color);
				}

				if (versionSpan) {
					versionSpan.innerHTML = '';
					if (j.version_cmp === -1) {
						versionSpan.innerHTML = ' <span class="badge bg-warning ms-1" title="Control plane is at ' +
							(j.cp_version || '') + '">upgrade available</span>';
					} else if (j.version_cmp === 1) {
						versionSpan.innerHTML = ' <span class="badge bg-danger ms-1" title="Control plane is at ' +
							(j.cp_version || '') + '">ahead of control plane</span>';
					}
				}

				if (lastCheckSpan && j.last_check) {
					lastCheckSpan.textContent = '(' + j.last_check + ')';
				}
			})
			.catch(function() {
				if (badge) badge.style.opacity = '';
			});
	});
})();
</script>

<?php
$page->admin_footer();
?>
