<?php
/**
 * Server Manager Dashboard
 * URL: /admin/server_manager
 *
 * @version 1.19 - a finished provision stays on the board while this plane still holds its install
 *                 password, and every row says where that password stands
 * @version 1.18 - a failing fleet backup links the failed job next to its reason
 * @version 1.17 - agent update state fetch_failed: the artifact could not be fetched and the agent is retrying
 * @version 1.16 - recovery-readiness attention line (never-verified/stale must-save secrets) linking to the readiness page
 * @version 1.16 - the backup alert is recovery-key setup only; per-node escrow rows are gone
 * @version 1.15 - escrow alert covers recovery-not-set-up as its own row and links to the guided
 *                 walkthrough; heading no longer assumes every row is a node
 * @version 1.14 - Show-all-sites toggle (?show_all=1) surfaces removed (soft-deleted) nodes with a Removed badge
 * @version 1.13 - management-node-level escrow problems (agent signing key) render without a node link
 * @version 1.12 - Sweep reconciles all JobResultProcessor-handled types (P-17), not a hardcoded 3
 * @version 1.11 - Shared server_manager.js asset (smApiPost/smEsc/smSafeUrl)
 *          1.10 - Agent self-update surfacing: pending/refused/rolled-back
 *                 alerts from the heartbeat row (specs/implemented/agent_release_channel.md)
 *          1.9 - Relay Fleet console link (mailbox plugin)
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_host_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/agent_heartbeat_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobResultProcessor.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/SmAssets.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);
$session->set_return();

// Process completed jobs that haven't had their results parsed yet.
// Skip nodes that are soft-deleted to avoid spawning chained jobs against
// hosts that no longer exist.
$db = DbConnector::get_instance()->get_db_link();
// Reconcile every unprocessed terminal job whose type JobResultProcessor can
// handle — the Go agent completes jobs by writing the DB directly, so without
// this an unwatched job is never reconciled. The type list comes from the
// processor itself, so relay/SSL/backup results aren't silently skipped (P-17).
$processable  = JobResultProcessor::processable_types();
$placeholders = implode(',', array_fill(0, count($processable), '?'));
$q = $db->prepare(
	"SELECT j.mjb_id FROM mjb_management_jobs j " .
	"JOIN mgn_managed_nodes n ON n.mgn_id = j.mjb_mgn_node_id " .
	"WHERE j.mjb_status IN ('completed','failed') " .
	"  AND j.mjb_job_type IN ($placeholders) " .
	"  AND j.mjb_result IS NULL " .
	"  AND j.mjb_delete_time IS NULL " .
	"  AND n.mgn_delete_time IS NULL"
);
$q->execute($processable);
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
	$unprocessed_job = new ManagementJob($row['mjb_id'], TRUE);
	JobResultProcessor::process($unprocessed_job);
}

// Load hosts (ordered by name)
$hosts = new MultiManagedHost(['deleted' => false], ['mgh_name' => 'ASC']);
$hosts->load();

// Load nodes and group by host_id. Removed (soft-deleted) sites are hidden by
// default; ?show_all=1 includes them, so a decommissioned site can be found
// again — its record, its history, and a link into its detail page.
$show_all = !empty($_GET['show_all']);
$node_opts = ['enabled' => true];
if (!$show_all) { $node_opts['deleted'] = false; }
$nodes = new MultiManagedNode($node_opts, ['mgn_name' => 'ASC']);
$nodes->load();

$nodes_by_host = [];
foreach ($nodes as $node) {
	$hid = $node->get('mgn_mgh_host_id');
	$key = $hid !== null ? (int)$hid : 0; // 0 = ungrouped
	$nodes_by_host[$key][] = $node;
}

// Load recent jobs
$recent_jobs = new MultiManagementJob(['deleted' => false], ['mjb_id' => 'DESC'], 20);
$recent_jobs->load();

// Agent heartbeat
$agent = AgentHeartbeat::getLatest();

// Cloud provisions still working toward a running site (or stuck), and finished
// ones whose install password this plane still holds (specs/keyless_provisioning.md)
require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_provision_class.php'));
$inflight_provisions = new MultiCustomerCloudProvision([
	'open'    => true,
	'deleted' => false,
], ['cvp_id' => 'DESC']);
$inflight_provisions->load();

// Nodes whose uptime monitoring cannot currently conclude up or down.
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/NodeMonitorHealth.php'));
$monitor_problems = NodeMonitorHealth::problems();
$recovery_problems = NodeMonitorHealth::backup_recovery_problems();

// Nodes whose backups THIS management node takes are failing or have stopped.
// Deliberately not "nodes without backups": whether a site backs itself up is
// that site's business under its own key, and a node with fleet backups
// switched off was switched off on purpose. What stops a node falling through
// unnoticed is that fleet backups default to on, not a detector for indecision.
$fleet_backup_problems = NodeMonitorHealth::fleet_backup_problems();

// Recovery readiness: must-save secrets never verified or verified too long
// ago. One line; the details live on the readiness page.
require_once(PathHelper::getIncludePath('includes/RecoveryReadiness.php'));
try {
	$readiness_attention = RecoveryReadiness::attention($session);
} catch (Throwable $e) {
	$readiness_attention = ['never' => 0, 'stale' => 0, 'warnings' => 0];
}

// Cron health: active if ran within 20 minutes
$settings        = Globalvars::get_instance();
$last_cron_run   = $settings->get_setting('scheduled_tasks_last_cron_run');
$cron_is_active  = $last_cron_run && (time() - strtotime($last_cron_run)) < 1200;

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

// Self-update surfacing: the agent reports what the shipped agent_dist offers
// (bundled version) and its update state. A lagging or refused update is a
// problem someone must see — the agent will never install an artifact that
// fails signature verification.
$agent_update_alert = '';
$agent_update_class = 'warning';
if ($agent_online) {
	$update_state = $agent->get('ahb_update_state');
	$bundled      = $agent->get('ahb_bundled_version');
	if ($update_state === 'verify_failed') {
		$agent_update_alert = "Agent update to v{$bundled} REFUSED: the shipped artifact failed checksum or signature verification. The agent will not retry until a corrected release is published.";
		$agent_update_class = 'danger';
	} elseif ($update_state === 'fetch_failed') {
		$agent_update_alert = "Agent update to v{$bundled} could not be fetched yet (the artifact was unreadable or the request timed out — common while a publish is still writing it). The agent retries on its next check.";
	} elseif ($update_state === 'version_rejected') {
		$agent_update_alert = "Agent v{$bundled} failed to start on this host and was rolled back; the agent is holding at v{$agent->get('ahb_agent_version')} until a newer release ships.";
		$agent_update_class = 'danger';
	} elseif ($update_state === 'unsigned_build') {
		$agent_update_alert = "Agent v{$bundled} is available, but the running agent was built without an update key and cannot self-update. Reinstall once from a published build (Run Plugin Installers on this management node).";
	} elseif ($bundled && $agent->get('ahb_agent_version') && $bundled !== $agent->get('ahb_agent_version')) {
		$agent_update_alert = "Agent update to v{$bundled} pending (running v{$agent->get('ahb_agent_version')}). The agent installs it automatically between jobs.";
	}
}
?>

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
		<div class="d-flex align-items-center gap-3">
			<div>
				<strong>Cron:</strong>
				<span class="badge bg-<?php echo $cron_is_active ? 'success' : 'danger'; ?> ms-1"><?php echo $cron_is_active ? 'Active' : 'Not detected'; ?></span>
				<?php if ($last_cron_run): ?>
					<span class="text-muted ms-2">Last run: <?php echo LibraryFunctions::time_ago_or_time($last_cron_run, 'UTC', $session->get_timezone(), 'M j, g:i:s A'); ?></span>
				<?php endif; ?>
			</div>
			<?php if (PluginHelper::isPluginActive('mailbox')): ?>
				<a href="/plugins/mailbox/admin/admin_mailbox_fleet" class="btn btn-sm btn-outline-secondary">Relay Fleet</a>
			<?php endif; ?>
			<a href="/admin/server_manager/publish_upgrade" class="btn btn-sm btn-primary">Publish New Upgrade</a>
		</div>
	</div>
	<?php if ($agent_update_alert): ?>
		<div class="card-footer text-<?php echo $agent_update_class; ?>">
			<small><?php echo htmlspecialchars($agent_update_alert); ?></small>
		</div>
	<?php endif; ?>
	<?php if (!$agent_online): ?>
		<div class="card-footer">
			<small class="text-muted">
				<?php if (!$agent): ?>
					The joinery-agent service runs on the management node and services all connected sites.
					Install it here: <code>cd /home/user1/joinery-agent &amp;&amp; make release VERSION=1.0.0 &amp;&amp; sudo bash joinery-agent-installer.sh --verbose</code>
				<?php else: ?>
					The agent was last seen <?php echo LibraryFunctions::time_ago_or_time($agent->get('ahb_last_heartbeat'), 'UTC', $session->get_timezone(), 'M j, g:i:s A'); ?>.
					Check: <code>sudo systemctl status joinery-agent</code> &mdash; <code>journalctl -u joinery-agent -f</code>
				<?php endif; ?>
			</small>
		</div>
	<?php endif; ?>
</div>

<?php // Nodes whose monitoring cannot report up or down. Surfaced here because a
      // broken check is silent everywhere else — the node simply never alerts,
      // which is indistinguishable from never having had a problem. ?>
<?php if (!empty($monitor_problems)): ?>
<div class="alert alert-warning" role="alert">
	<strong>Monitoring not reporting on <?php echo count($monitor_problems); ?> node<?php echo count($monitor_problems) === 1 ? '' : 's'; ?>.</strong>
	These nodes cannot raise a down alert until fixed.
	<ul class="mb-0 mt-2">
		<?php foreach ($monitor_problems as $p): ?>
			<li>
				<a href="/admin/server_manager/node_detail?mgn_id=<?php echo (int)$p['id']; ?>" class="alert-link"><?php echo htmlspecialchars($p['name'] ?: $p['slug']); ?></a>
				&mdash; <?php echo htmlspecialchars($p['health']['detail']); ?>
			</li>
		<?php endforeach; ?>
	</ul>
</div>
<?php endif; ?>

<?php // Backup recovery is not set up, so encrypted backups cannot run.
      // A backup you cannot restore is as silent as monitoring that cannot alert,
      // so it is surfaced the same way. ?>
<?php if (!empty($recovery_problems)): ?>
<div class="alert alert-warning" role="alert">
	<strong>Backups cannot be recovered yet.</strong>
	<ul class="mb-0 mt-2">
		<?php foreach ($recovery_problems as $p): ?>
			<li>
				<?php if ((int)$p['id'] > 0): ?>
					<a href="/admin/server_manager/node_detail?mgn_id=<?php echo (int)$p['id']; ?>&tab=backups" class="alert-link"><?php echo htmlspecialchars($p['name'] ?: $p['slug']); ?></a>
				<?php else: // management-node-level problem (recovery setup, agent signing key) ?>
					<strong><?php echo htmlspecialchars($p['name'] ?: $p['slug']); ?></strong>
				<?php endif; ?>
				&mdash; <?php echo htmlspecialchars($p['health']['detail']); ?>
				<?php if (!empty($p['link'])): ?>
					<a href="<?php echo htmlspecialchars($p['link']); ?>" class="alert-link">Set it up</a>.
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
</div>
<?php endif; ?>

<?php // Backups this management node takes are not happening. Its own runs, its
      // own shelf, its own responsibility — which is why this is an alarm.
      // Two shapes land here and both belong: runs that fail or stop landing,
      // and nodes that cannot be backed up at all because they hold no verified
      // recovery key. The second is not fixable from here, and the line says so
      // rather than reading as something an operator here forgot. ?>
<?php if (!empty($fleet_backup_problems)): ?>
<div class="alert alert-warning" role="alert">
	<strong>Backups taken from here are not happening.</strong>
	<ul class="mb-0 mt-2">
		<?php foreach ($fleet_backup_problems as $p): ?>
			<li>
				<a href="<?php echo htmlspecialchars($p['link']); ?>" class="alert-link"><?php echo htmlspecialchars($p['name'] ?: $p['slug']); ?></a>
				&mdash; <strong><?php echo htmlspecialchars($p['health']['label']); ?>.</strong>
				<?php echo htmlspecialchars($p['health']['detail']); ?>
				<?php if (!empty($p['health']['job_id'])): ?>
					<a href="/admin/server_manager/job_detail?job_id=<?php echo (int)$p['health']['job_id']; ?>" class="alert-link">See the failed job.</a>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
</div>
<?php endif; ?>

<?php if ($readiness_attention['never'] + $readiness_attention['stale'] + $readiness_attention['warnings'] > 0): ?>
<div class="alert alert-warning" role="alert">
	<strong>Recovery readiness needs attention.</strong>
	<?php
	$bits = [];
	if ($readiness_attention['never'])    { $bits[] = $readiness_attention['never'] . ' must-save ' . ($readiness_attention['never'] === 1 ? 'secret has' : 'secrets have') . ' never been verified'; }
	if ($readiness_attention['stale'])    { $bits[] = $readiness_attention['stale'] . ' ' . ($readiness_attention['stale'] === 1 ? 'was' : 'were') . ' last verified over ' . RecoveryReadiness::STALE_DAYS . ' days ago'; }
	if ($readiness_attention['warnings']) { $bits[] = $readiness_attention['warnings'] . ' ' . ($readiness_attention['warnings'] === 1 ? 'carries' : 'carry') . ' warnings (low recovery codes, missing passkey)'; }
	echo htmlspecialchars(implode('; ', $bits)) . '.';
	?>
	<a href="/admin/admin_recovery_readiness" class="alert-link">Review and verify</a>.
</div>
<?php endif; ?>

<?php if (count($inflight_provisions)): ?>
<!-- Cloud provisions in flight -->
<div class="card mb-4">
	<div class="card-body py-2">
		<strong>Cloud provisions:</strong>
		<table class="table table-sm mb-0 mt-2">
			<thead><tr><th>Domain</th><th>Origin</th><th>Status</th><th>Instance</th><th>Install password</th><th>Detail</th></tr></thead>
			<tbody>
			<?php foreach ($inflight_provisions as $prov):
				$pstatus = $prov->get('cvp_status');
				$badge = ($pstatus === 'failed') ? 'danger' : (($pstatus === 'pending_connect') ? 'warning' : (($pstatus === 'done') ? 'success' : 'info'));
				$pw_state = (string)$prov->get('cvp_install_password');
				$pw_class = ($pw_state === 'retired') ? 'text-success' : (($pw_state === 'retire_failed') ? 'text-danger' : 'text-muted');
			?>
				<tr>
					<td><?php echo htmlspecialchars($prov->get('cvp_domain')); ?></td>
					<td><?php echo htmlspecialchars($prov->get('cvp_origin') ?: 'order'); ?></td>
					<td><span class="badge bg-<?php echo $badge; ?>"><?php echo htmlspecialchars($pstatus); ?></span></td>
					<td><?php echo htmlspecialchars(trim(($prov->get('cvp_instance_type') ?: '') . ' ' . ($prov->get('cvp_region') ?: '')) ?: '—'); ?></td>
					<td class="<?php echo $pw_class; ?> small"><?php echo htmlspecialchars(ProvisionCustomerCloud::install_password_summary($prov)); ?></td>
					<td class="text-muted"><?php echo htmlspecialchars(mb_substr((string)$prov->get('cvp_error'), 0, 120) ?: '—'); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>
<?php endif; ?>

<!-- Two-column layout: Hosts & Sites (left) | Recent Jobs (right) -->
<div class="row">
	<!-- LEFT: Hosts & Sites accordion -->
	<div class="col-md-8 mb-4">
		<?php
		$pageoptions = [
			'title' => 'Hosts & Sites',
			'altlinks' => [
				'Add Host'       => '/admin/server_manager/host_add',
				'Connect Site'   => '/admin/server_manager/node_add',
				'Remote Install' => '/admin/server_manager/install_node_form',
			],
		];
		$page->begin_box($pageoptions);
		?>

		<?php if (count($hosts) === 0 && empty($nodes_by_host[0])): ?>
			<div class="alert alert-info mb-0">
				<strong>No hosts configured yet.</strong>
				<a href="/admin/server_manager/host_add" class="alert-link">Add your first host</a> or
				<a href="/admin/server_manager/node_add" class="alert-link">connect a site directly</a>.
			</div>
		<?php else: ?>
			<div class="accordion accordion-flush host-accordion" id="hostsAccordion">

				<?php foreach ($hosts as $host):
					$host_nodes = $nodes_by_host[$host->key] ?? [];
					$site_count = count($host_nodes);
					$max_sites  = (int)$host->get('mgh_max_sites') ?: 50;
					$capacity_pct = $max_sites > 0 ? min(100, round($site_count / $max_sites * 100)) : 0;
					$capacity_color = $capacity_pct >= 90 ? 'danger' : ($capacity_pct >= 70 ? 'warning' : 'secondary');
					$prov_enabled = (bool)$host->get('mgh_provisioning_enabled');
				?>
				<div class="accordion-item">
					<h2 class="accordion-header" id="hdr-<?php echo $host->key; ?>">
						<button class="accordion-button" type="button"
							data-bs-toggle="collapse"
							data-bs-target="#hc-<?php echo $host->key; ?>"
							aria-expanded="true"
							aria-controls="hc-<?php echo $host->key; ?>">
							<div class="d-flex justify-content-between align-items-center w-100 me-3">
								<div>
									<strong><?php echo htmlspecialchars($host->get('mgh_name')); ?></strong>
									<small class="text-muted ms-2"><?php echo htmlspecialchars($host->get('mgh_host')); ?></small>
								</div>
								<div class="d-flex align-items-center gap-2">
									<span class="badge bg-<?php echo $capacity_color; ?>"><?php echo $site_count; ?> / <?php echo $max_sites; ?> sites</span>
									<?php if ($prov_enabled): ?>
										<span class="badge bg-success">provisioning on</span>
									<?php else: ?>
										<span class="badge bg-light text-dark border">provisioning off</span>
									<?php endif; ?>
								</div>
							</div>
						</button>
					</h2>
					<div id="hc-<?php echo $host->key; ?>" class="accordion-collapse collapse show"
						aria-labelledby="hdr-<?php echo $host->key; ?>">
						<div class="accordion-body">
							<?php if (empty($host_nodes)): ?>
								<div class="text-muted small p-3">No sites on this host.</div>
							<?php else: ?>
								<div class="list-group list-group-flush">
									<?php foreach ($host_nodes as $node): ?>
										<?php echo render_node_row($node, $db, $session); ?>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
							<div class="p-2 border-top bg-light d-flex gap-2">
								<a href="/admin/server_manager/install_node_form" class="btn btn-sm btn-outline-primary">Install Site</a>
								<a href="/admin/server_manager/host_add?mgh_id=<?php echo $host->key; ?>" class="btn btn-sm btn-outline-secondary">Edit Host</a>
							</div>
						</div>
					</div>
				</div>
				<?php endforeach; ?>

				<?php if (!empty($nodes_by_host[0])): ?>
				<!-- Ungrouped nodes (no host assigned) -->
				<div class="accordion-item">
					<h2 class="accordion-header" id="hdr-ungrouped">
						<button class="accordion-button" type="button"
							data-bs-toggle="collapse"
							data-bs-target="#hc-ungrouped"
							aria-expanded="true"
							aria-controls="hc-ungrouped">
							<div class="d-flex justify-content-between align-items-center w-100 me-3">
								<div><strong>Ungrouped Sites</strong></div>
								<span class="badge bg-secondary"><?php echo count($nodes_by_host[0]); ?> sites</span>
							</div>
						</button>
					</h2>
					<div id="hc-ungrouped" class="accordion-collapse collapse show"
						aria-labelledby="hdr-ungrouped">
						<div class="accordion-body p-0">
							<div class="list-group list-group-flush">
								<?php foreach ($nodes_by_host[0] as $node): ?>
									<?php echo render_node_row($node, $db, $session); ?>
								<?php endforeach; ?>
							</div>
						</div>
					</div>
				</div>
				<?php endif; ?>

			</div>
		<?php endif; ?>
		<div class="p-2 border-top small">
			<?php if ($show_all): ?>
				<a href="/admin/server_manager">Hide removed sites</a>
				<span class="text-muted ms-2">Showing all sites, including removed ones.</span>
			<?php else: ?>
				<a href="/admin/server_manager?show_all=1">Show all sites (including removed)</a>
			<?php endif; ?>
		</div>
		<?php $page->end_box(); ?>
	</div>

	<!-- RIGHT: Recent Jobs -->
	<div class="col-md-4 mb-4">
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
						<td class="text-truncate svm-w90" title="<?php echo htmlspecialchars($node_name); ?>"><?php echo htmlspecialchars($node_name); ?></td>
						<td><?php echo htmlspecialchars(str_replace('_', ' ', $job->get('mjb_job_type'))); ?></td>
						<td><span class="badge bg-<?php echo $status_class; ?>"><?php echo htmlspecialchars($job->get('mjb_status')); ?></span></td>
						<td><?php echo $job->get('mjb_started_time') ? LibraryFunctions::time_ago_or_time($job->get('mjb_started_time'), 'UTC', $session->get_timezone(), 'M j, g:i A') : '-'; ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if (count($recent_jobs) === 0): ?>
					<tr><td colspan="5" class="text-muted text-center">No jobs yet</td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php $page->end_box(); ?>
	</div>
</div>

<?php
/**
 * Render a single node row (used in each host panel and the ungrouped section).
 */
function render_node_row($node, $db, $session) {
	$status_data = $node->get('mgn_last_status_data');
	if (is_string($status_data)) $status_data = json_decode($status_data, true);
	$last_check = $node->get('mgn_last_status_check');

	$last_job_failed = false;
	$last_job_q = $db->prepare(
		"SELECT mjb_status FROM mjb_management_jobs " .
		"WHERE mjb_mgn_node_id = ? AND mjb_job_type = 'check_status' AND mjb_delete_time IS NULL " .
		"ORDER BY mjb_id DESC LIMIT 1"
	);
	$last_job_q->execute([$node->key]);
	$last_job_row = $last_job_q->fetch(PDO::FETCH_ASSOC);
	if ($last_job_row && $last_job_row['mjb_status'] === 'failed') {
		$last_job_failed = true;
	}

	$install_state = $node->get('mgn_install_state');
	$status_color = JobCommandBuilder::status_color_for_node($node, $status_data, $last_job_failed);

	$node_version = $node->get('mgn_joinery_version');
	$version_cmp  = null;
	if ($node_version) {
		$cp_version = LibraryFunctions::get_joinery_version();
		if ($cp_version !== '' && preg_match('/^\d+\.\d+\.\d+$/', $node_version)) {
			$version_cmp = version_compare($node_version, $cp_version);
		}
	}

	$api_refreshable = !empty($node->get('mgn_site_url'))
		&& !in_array($install_state, ['installing', 'install_failed'], true)
		&& !$node->get('mgn_delete_time'); // never poll a removed site

	$ssl_state = $node->get('mgn_ssl_state');

	ob_start();
	?>
	<div class="list-group-item node-row d-flex justify-content-between align-items-center"
		data-href="/admin/server_manager/node_detail?mgn_id=<?php echo $node->key; ?>"
		data-node-id="<?php echo $node->key; ?>"
		data-api-refreshable="<?php echo $api_refreshable ? '1' : '0'; ?>"
		onclick="if(!event.target.closest('form,button,input,a')) window.location=this.dataset.href">
		<div class="d-flex align-items-center svm-flex1">
			<span class="badge bg-<?php echo $status_color; ?> me-2 js-status-badge">&bull;</span>
			<div class="svm-minw0">
				<strong><?php echo htmlspecialchars($node->get('mgn_name')); ?></strong>
				<?php if ($node->get('mgn_delete_time')): ?>
					<span class="badge bg-secondary ms-1" title="Removed <?php echo htmlspecialchars($node->get_local('mgn_delete_time', 'M j, Y')); ?>">Removed</span>
				<?php endif; ?>
				<?php if ($install_state === 'installing'): ?>
					<span class="badge bg-info ms-1">Installing…</span>
				<?php elseif ($install_state === 'install_failed'): ?>
					<span class="badge bg-danger ms-1">Install failed</span>
				<?php endif; ?>
				<?php if ($ssl_state === 'pending'): ?>
					<span class="badge bg-warning ms-1">SSL pending</span>
				<?php elseif ($ssl_state === 'failed'): ?>
					<span class="badge bg-danger ms-1">SSL failed</span>
				<?php endif; ?>
				<span class="js-version-indicator">
					<?php if ($version_cmp === -1): ?>
						<span class="badge bg-warning ms-1" title="Management node is at <?php echo htmlspecialchars($cp_version ?? ''); ?>">upgrade available</span>
					<?php elseif ($version_cmp === 1): ?>
						<span class="badge bg-danger ms-1" title="Management node is at <?php echo htmlspecialchars($cp_version ?? ''); ?>">ahead of management node</span>
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
	</div>
	<?php
	return ob_get_clean();
}
?>

<?php echo SmAssets::script_tag(); ?>
<script>
// Auto-refresh status for nodes with API credentials. Fires once on page load
// in parallel, bypassing the agent/job pipeline. Silent on failure — the
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

		smApiPost('refresh_node_status', { node_id: nodeId })
			.then(function(j) {
				if (badge) badge.style.opacity = '';
				if (!j.ok) return;

				if (badge && j.status_color) {
					colorClasses.forEach(function(c) { badge.classList.remove(c); });
					badge.classList.add('bg-' + j.status_color);
				}

				// Only update the version badge when the response includes definitive
				// version data. HTTP-only checks omit version_cmp entirely; API checks
				// without a joinery_version return null. In both cases, preserve the
				// server-rendered badge rather than clearing it.
				if (versionSpan && 'version_cmp' in j && j.version_cmp !== null) {
					versionSpan.innerHTML = '';
					if (j.version_cmp === -1) {
						versionSpan.innerHTML = ' <span class="badge bg-warning ms-1" title="Management node is at ' +
							(j.cp_version || '') + '">upgrade available</span>';
					} else if (j.version_cmp === 1) {
						versionSpan.innerHTML = ' <span class="badge bg-danger ms-1" title="Management node is at ' +
							(j.cp_version || '') + '">ahead of management node</span>';
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
