<?php
/**
 * Server Manager - Job Detail
 * URL: /admin/server_manager/job_detail
 *
 * Shows job output with live polling for running jobs.
 *
 * @version 1.27 - net_health and web_workers results (agent 1.93.0): a verdict line, then the counts
 * @version 1.26 - starting a deploy-all rollout on a page view is the server's own write (server_initiated_write)
 * @version 1.25 - a site_log_count result renders as a marker table under a line saying whether the read covered the window
 * @version 1.24 - a deploy-all publish not yet done describes its rollout's two stages (StagedRolloutRunner::deploy_all_description)
 * @version 1.23 - that publish reads 'published' once its job completes, and warns when it failed
 * @version 1.22 - an apply that Apply and publish follows with a publish says where that publish stands
 * @version 1.21 - an install_security_updates result says what it reloaded, what still serves old code, and a
 *                half-done package run finished first (install_security_updates.sh 1.2; fleet_security_patching WP3)
 * @version 1.20 - install_security_updates results (agent 1.87.0): what was installed, with old and new versions,
 *                 and any reboot or service restart still owed
 * @version 1.19 - the site state card reads the processed result (JobResultProcessor 1.96); a reading it does not
 *                 know is not called live
 * @version 1.18 - site_state_report results (agent 1.86.0): each site state directory's records
 * @version 1.17 - listening_sockets, container_inventory, spam_learning_status and reboot_machine results (agent 1.76.0)
 * @version 1.16 - Mark as failed on a running agent job: fails it as lost (ManagementJob::markLost) and frees the
 *                 node's queue; a result that arrives later still replaces it. No running job was ever stuck
 *                 past what a page could clear again (getjoinery job 536, 10-10). The hour-long-run warning
 *                 points at the button, not at a shell on the node.
 * @version 1.15 - a publish that asked to deploy to all managed nodes says what became of that: the nodes it
 *                 will reach, the rollout it started (loading the page starts it), or why it did not
 * @version 1.14 - a disk_usage result shows the data root's figures and biggest directories beside the site tree
 * @version 1.13 - a move_to_plane result renders as a card: which management node was asked, the name and the fingerprint
 * @version 1.12 - a page_probe that could not run shows the node's reason instead of "no report"
 * @version 1.11 - an apply_update job renders its structured apply result (versions, migrations, schema
 *                changes, plugins, deploy tier, rollback); restart_unit/_container, run_installer,
 *                file_head and schema_probe results render as cards
 * @version 1.10 - a finished job's result is folded through JobResultProcessor::process_if_due, so a failed
 *                 job is folded here as it is everywhere else
 * @version 1.9 - a reset_failed_unit result renders the unit's state before and after the reset
 * @version 1.8 - a unit_journal result renders the unit's verdict above its journal lines, and a
 *                disk_usage result renders two size tables — the site tree's biggest directories
 *                and the machine's usual ones
 * @version 1.7 - a log job whose result records read=false says so instead of dumping the flag
 * @version 1.6 - a site_log result renders its text as a log box and a log_table_tail result as a table,
 *                both through the redactor a second time (specs/agent_log_access.md §4); a pruned
 *                excerpt says so
 * @version 1.5 - the output box and the poll offset read $job->transcript(), so a script primitive's
 *                transcript is shown as text rather than as the envelope it travels in
 * @version 1.4 - re-run goes through ManagementJob::rerun(), so a primitive job re-runs its primitive
 * @version 1.3 - structured result display redacted too
 * @version 1.2
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_jobs_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_nodes_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobResultProcessor.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/SmAdminCsrf.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/SmSecretRedactor.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/SmAssets.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);
$session->set_return();

$job_id = isset($_GET['job_id']) ? intval($_GET['job_id']) : 0;
if (!$job_id) {
	header('Location: /admin/server_manager/jobs');
	exit;
}

try {
	$job = new ManagementJob($job_id, TRUE);
} catch (Exception $e) {
	header('Location: /admin/server_manager/jobs');
	exit;
}

$post_action = ($_POST['action'] ?? '');

// Cancel and re-run are POST actions (a GET link is CSRF-triggerable), CSRF-validated.
if ($post_action === 'cancel_job' && in_array($job->get('mjb_status'), ['pending', 'queued'], true)) {
	if (!SmAdminCsrf::valid()) { header('Location: /admin/server_manager/job_detail?job_id=' . $job_id); exit; }
	$job->set('mjb_status', 'cancelled');
	$job->set('mjb_completed_time', gmdate('Y-m-d H:i:s'));
	$job->save();
	header('Location: /admin/server_manager/job_detail?job_id=' . $job_id);
	exit;
}

// A running agent job the node will not report: its agent restarted without
// saying it is idle (an agent before 1.72.0), or something nobody foresaw. Failed
// as lost, the same verdict the claim endpoint and the budget sweep give, so a
// result that arrives later still lands on it.
if ($post_action === 'mark_job_lost' && $job->get('mjb_status') === 'running' && $job->isPrimitiveJob()) {
	if (!SmAdminCsrf::valid()) { header('Location: /admin/server_manager/job_detail?job_id=' . $job_id); exit; }
	ManagementJob::markLost((int)$job->get('mjb_mgn_managed_node_id'),
		'Marked failed from the job page while it showed as running. What it did on the node is unknown: '
		. 'check the node before running it again. A result the node sends later replaces this.',
		(int)$job->key);
	header('Location: /admin/server_manager/job_detail?job_id=' . $job_id);
	exit;
}

// Handle re-run action
if ($post_action === 'rerun_job') {
	if (!SmAdminCsrf::valid()) { header('Location: /admin/server_manager/job_detail?job_id=' . $job_id); exit; }
	// The job model copies its own work — a primitive envelope or a step list —
	// so the page never has to know which shape it is looking at.
	try {
		$new_job = $job->rerun($session->get_user_id());
	} catch (ManagementJobException $e) {
		$session->save_message(new DisplayMessage(
			$e->getMessage(), 'Error', '#^/admin/server_manager#',
			DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
		header('Location: /admin/server_manager/job_detail?job_id=' . $job_id);
		exit;
	}
	header('Location: /admin/server_manager/job_detail?job_id=' . $new_job->key);
	exit;
}

// Fold the result if it has not been yet — the same rule the agent channel
// and the dashboard sweep apply, so a failed job is folded here too.
if (JobResultProcessor::process_if_due($job)) {
	$job->load();
}

// A publish that asked to deploy to all managed nodes: loading the page is a
// tick, so the rollout starts here as soon as the publish has completed. The
// server's own write: it starts only what the publish's POST asked for, as
// the AdvanceStagedRollouts task would.
$deploy_all = null;
if ($job->get('mjb_job_type') === 'publish_upgrade') {
	$deploy_all = StagedRolloutRunner::job_params($job);
	if (empty($deploy_all['deploy_all'])) {
		$deploy_all = null;
	} elseif (!isset($deploy_all['deploy_all_outcome']) && $job->get('mjb_status') === 'completed'
		&& (int)$job->get('mjb_mgn_managed_node_id') === (int)(ManagedNode::self_node()?->key)) {
		SystemBase::server_initiated_write(function () use ($job) { StagedRolloutRunner::deploy_all_for_publish($job); });
		$deploy_all = StagedRolloutRunner::job_params($job);
	}
}

// An apply that Apply and publish is to follow with a publish. The task
// starts the publish; this page only says where it stands.
$then_publish = ApplyAndPublish::followUp($job);

// Load node name
$node_name = 'Local';
$node_id = $job->get('mjb_mgn_managed_node_id');
if ($node_id) {
	try {
		$node = new ManagedNode($node_id, TRUE);
		$node_name = $node->get('mgn_name');
	} catch (Exception $e) {
		$node_name = "Node #{$node_id}";
	}
}

// Get display messages
$display_messages = $session->get_messages('/admin/server_manager');

$page = new AdminPage();
$page->admin_header([
	'menu-id' => 'server-manager',
	'page_title' => 'Job #' . $job->key,
	'readable_title' => 'Job Detail',
	'breadcrumbs' => $node_id
		? [
			'Server Manager' => '/admin/server_manager',
			$node_name => '/admin/server_manager/node_detail?mgn_managed_node_id=' . $node_id,
			'Job #' . $job->key => '',
		]
		: [
			'Server Manager' => '/admin/server_manager',
			'Jobs' => '/admin/server_manager/jobs',
			'Job #' . $job->key => '',
		],
	'session' => $session,
]);

// Display messages
if (!empty($display_messages)) {
	foreach ($display_messages as $msg) {
		$alert_class = $msg->display_type == DisplayMessage::MESSAGE_ERROR ? 'alert-danger' : 'alert-success';
		echo '<div class="alert ' . $alert_class . '">';
		echo htmlspecialchars($msg->message);
		echo ' ' . $msg->report_link_html();
		echo '<button type="button" class="alert-close" aria-label="Close">&times;</button></div>';
	}
	// Rendered above, so these are spent; the footer drops them.
	$session->mark_shown($display_messages);
	$session->clear_clearable_messages();
}

$status_class = match($job->get('mjb_status')) {
	'completed' => 'success',
	'failed' => 'danger',
	'running' => 'primary',
	'cancelled' => 'secondary',
	default => 'warning',
};
?>

<div class="card mb-3">
	<div class="card-body">
		<div class="row">
			<div class="col-md-6">
				<p><strong>Type:</strong> <?php echo htmlspecialchars(str_replace('_', ' ', $job->get('mjb_job_type'))); ?></p>
				<p><strong>Node:</strong> <?php echo htmlspecialchars($node_name); ?></p>
				<p><strong>Status:</strong> <span id="job-status" class="badge bg-<?php echo $status_class; ?>"><?php echo htmlspecialchars($job->get('mjb_status')); ?></span></p>
				<?php
				// mjb_current_step is the 0-based index of the step being executed;
				// translate to a user-facing "N of total" completed count.
				$total_steps = intval($job->get('mjb_total_steps'));
				$raw_current = intval($job->get('mjb_current_step'));
				$job_status = $job->get('mjb_status');
				if ($job_status === 'completed') {
					$done_steps = $total_steps;
				} elseif (in_array($job_status, ['failed', 'cancelled'])) {
					$done_steps = $raw_current; // steps before the failing one completed
				} else {
					$done_steps = $raw_current; // running: steps completed so far
				}
				?>
				<p><strong>Progress:</strong> <span id="job-progress"><?php echo $done_steps; ?></span> / <?php echo $total_steps; ?> steps</p>
			</div>
			<div class="col-md-6">
				<p><strong>Created:</strong> <?php echo $job->get_local('mjb_create_time', 'M j, Y g:i:s A'); ?></p>
				<?php if ($job->get('mjb_started_time')): ?>
					<p><strong>Started:</strong> <?php echo $job->get_local('mjb_started_time', 'M j, Y g:i:s A'); ?></p>
				<?php endif; ?>
				<?php if ($job->get('mjb_completed_time')): ?>
					<p><strong>Completed:</strong> <?php echo $job->get_local('mjb_completed_time', 'M j, Y g:i:s A'); ?></p>
				<?php endif; ?>
				<?php if ($job->get('mjb_started_time') && $job->get('mjb_completed_time')): ?>
					<?php $dur = strtotime($job->get('mjb_completed_time')) - strtotime($job->get('mjb_started_time')); ?>
					<p><strong>Duration:</strong> <?php echo $dur < 60 ? "{$dur}s" : round($dur / 60, 1) . ' min'; ?></p>
				<?php endif; ?>
			</div>
		</div>

		<?php if ($job->get('mjb_error_message')): ?>
			<div class="alert alert-danger mt-2">
				<strong>Error:</strong> <?php echo nl2br(htmlspecialchars($job->get('mjb_error_message'))); ?>
			</div>
		<?php endif; ?>

		<?php if ($then_publish !== null): ?>
			<?php
			$tp_status  = (string)$job->get('mjb_status');
			$tp_outcome = (string)($then_publish['outcome'] ?? '');
			$tp_publish = (string)($then_publish['publish_status'] ?? '');
			?>
			<div class="jy-callout <?php echo $tp_outcome === 'refused' || in_array($tp_status, ['failed', 'cancelled'], true) || in_array($tp_publish, ['failed', 'cancelled'], true) ? 'jy-callout-warning' : 'jy-callout-info'; ?> mt-2">
				<strong>Then publish:</strong>
				<?php if ($tp_outcome === 'started'): ?>
					<?php
					$tp_job = (int)($then_publish['publish_job_id'] ?? 0);
					$tp_version = htmlspecialchars((string)($then_publish['version'] ?? ''));
					if ($tp_publish === 'completed') {
						echo 'published ' . $tp_version;
					} elseif (in_array($tp_publish, ['failed', 'cancelled'], true)) {
						echo 'the publish of ' . $tp_version . ' ' . ($tp_publish === 'failed' ? 'failed' : 'was cancelled');
					} else {
						echo 'publishing ' . $tp_version;
					}
					?>
					(<a href="/admin/server_manager/job_detail?job_id=<?php echo $tp_job; ?>">job #<?php echo $tp_job; ?></a>).
				<?php elseif ($tp_outcome === 'refused'): ?>
					not published — <?php echo htmlspecialchars((string)($then_publish['reason'] ?? '')); ?>.
				<?php elseif (in_array($tp_status, ['failed', 'cancelled'], true)): ?>
					nothing will be published, because this apply did not complete.
				<?php elseif ($tp_status === 'completed'): ?>
					the apply has finished; its publish starts within a minute if the apply succeeded. This page reloads until it does.
					<script>setTimeout(function () { window.location.reload(); }, 15000);</script>
				<?php else: ?>
					once this apply brings the node to <?php echo htmlspecialchars((string)($then_publish['target'] ?? '')); ?>
					or later and succeeds, that version is published with these notes<?php echo !empty($then_publish['deploy_all']) ? ' and deployed to all managed nodes' : ''; ?>.
				<?php endif; ?>
				<br><small><?php echo nl2br(htmlspecialchars((string)($then_publish['release_notes'] ?? ''))); ?></small>
			</div>
		<?php endif; ?>

		<?php if ($deploy_all !== null): ?>
			<?php
			$da_status  = (string)$job->get('mjb_status');
			$da_outcome = (string)($deploy_all['deploy_all_outcome'] ?? '');
			$da_left    = (array)($deploy_all['deploy_all_left_out'] ?? []);
			?>
			<div class="jy-callout <?php echo $da_outcome === 'refused' || in_array($da_status, ['failed', 'cancelled'], true) ? 'jy-callout-warning' : 'jy-callout-info'; ?> mt-2">
				<strong>Deploy to all managed nodes:</strong>
				<?php if ($da_outcome === 'started'): ?>
					rollout started. <a href="/admin/server_manager/rollouts">Follow it on the Staged rollout page</a>.
				<?php elseif ($da_outcome === 'refused'): ?>
					not started — <?php echo htmlspecialchars((string)($deploy_all['deploy_all_reason'] ?? '')); ?>.
				<?php elseif (in_array($da_status, ['failed', 'cancelled'], true)): ?>
					nothing was deployed, because this publish did not complete.
				<?php elseif ($da_status === 'completed'): ?>
					waiting for the rollout already running to finish; this one starts after it.
					<a href="/admin/server_manager/rollouts">Staged rollout</a>
				<?php else: ?>
					<?php
					$da_plan  = StagedRolloutRunner::deploy_all_plan();
					$da_left  = $da_plan['left_out'];
					echo htmlspecialchars(StagedRolloutRunner::deploy_all_description($da_plan, false));
					?>
				<?php endif; ?>
				<?php foreach ($da_left as $lo): ?>
					<br><small>Left out: <?php echo htmlspecialchars((string)($lo['name'] ?? '')); ?> — <?php echo htmlspecialchars((string)($lo['reason'] ?? '')); ?></small>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php
		// Detect stuck jobs
		if ($job->get('mjb_status') === 'pending') {
			$pending_seconds = time() - strtotime($job->get('mjb_create_time'));
			if ($pending_seconds > 120): ?>
				<div class="alert alert-warning mt-2">
					<strong>This job has been pending for <?php echo round($pending_seconds / 60); ?> minutes.</strong>
					<?php
					$agent = AgentHeartbeat::getLatest();
					$agent_online = $agent && $agent->is_online();
					if (!$agent_online): ?>
						The agent appears to be offline. Check that it is running:
						<code>sudo systemctl status joinery-agent</code>
					<?php else: ?>
						The agent is online but has not picked up this job. Another job may be running on the same node.
						<a href="/admin/server_manager/jobs?status=running">Check running jobs</a>.
					<?php endif; ?>
				</div>
			<?php endif;
		}

		// A queued install runs from this management node (the plane-side
		// install executor), not from a node agent: the Run Install Jobs task
		// spawns the worker on its next tick, so a wait of one cron interval is
		// normal and an agent-offline hint would point at the wrong machine.
		if ($job->get('mjb_status') === 'queued') {
			$queued_seconds = time() - strtotime($job->get('mjb_create_time'));
			if ($queued_seconds > 1200): ?>
				<div class="alert alert-warning mt-2">
					<strong>This install has been queued for <?php echo round($queued_seconds / 60); ?> minutes.</strong>
					It is run by this management node's install executor, which the Run Install Jobs scheduled task starts on its next tick.
					If the task is running and this persists, check <code>logs/install_executor.log</code> on this management node.
				</div>
			<?php endif;
		}

		if ($job->get('mjb_status') === 'running') {
			$running_seconds = time() - strtotime($job->get('mjb_started_time'));
			if ($running_seconds > 3600): ?>
				<div class="alert alert-warning mt-2">
					<strong>This job has been running for <?php echo round($running_seconds / 3600, 1); ?> hours.</strong>
					It may be stuck. If the node will not report it, <strong>Mark as failed</strong> frees the node for its next job.
				</div>
			<?php endif;
		}
		?>

		<div class="mt-2">
			<?php if (in_array($job->get('mjb_status'), ['pending', 'queued'], true)): ?>
				<form method="post" action="/admin/server_manager/job_detail?job_id=<?php echo $job->key; ?>" id="cancel_job_form" style="display:inline;">
					<input type="hidden" name="action" value="cancel_job">
					<?php echo SmAdminCsrf::field(); ?>
					<button type="button" class="btn btn-sm btn-warning" onclick="JoineryModal.confirm('Cancel this job?', function(){ document.getElementById('cancel_job_form').submit(); })">Cancel</button>
				</form>
			<?php endif; ?>
			<?php if ($job->get('mjb_status') === 'running' && $job->isPrimitiveJob()): ?>
				<form method="post" action="/admin/server_manager/job_detail?job_id=<?php echo $job->key; ?>" id="mark_job_lost_form" style="display:inline;">
					<input type="hidden" name="action" value="mark_job_lost">
					<?php echo SmAdminCsrf::field(); ?>
					<button type="button" class="btn btn-sm btn-outline-danger" onclick="JoineryModal.confirm('Mark this job as failed? Do this only when the node is not going to report it: its agent or machine restarted, or it is long past its time. It is never run again by itself, and the node is free for its next job. If the node does report it later, its result replaces this.', function(){ document.getElementById('mark_job_lost_form').submit(); })">Mark as failed</button>
				</form>
			<?php endif; ?>
			<?php if (in_array($job->get('mjb_status'), ['completed', 'failed', 'cancelled'])): ?>
				<form method="post" action="/admin/server_manager/job_detail?job_id=<?php echo $job->key; ?>" style="display:inline;">
					<input type="hidden" name="action" value="rerun_job">
					<?php echo SmAdminCsrf::field(); ?>
					<button type="submit" class="btn btn-sm btn-outline-primary">Re-run</button>
				</form>
			<?php endif; ?>
		</div>
	</div>
</div>

<!-- Output -->
<div class="card mb-3">
	<div class="card-header"><strong>Output</strong></div>
	<div class="card-body">
		<pre id="job-output" class="svm-logbox"><?php echo htmlspecialchars(SmSecretRedactor::redact($job->transcript() ?: 'Waiting for output...')); ?></pre>
	</div>
</div>

<?php
// Show structured result if available
$result = $job->get('mjb_result');
if ($result) {
	$result_data = is_string($result) ? json_decode($result, true) : $result;
	$job_type    = (string)$job->get('mjb_job_type');
	if (is_array($result_data) && in_array($job_type, ManagementJob::LOG_EXCERPT_TYPES, true)
		&& array_key_exists('read', $result_data) && !$result_data['read']) {
		// The node completed the job but what came back was not a log envelope
		// (JobResultProcessor::process_site_log / process_log_table_tail).
		echo '<div class="card mb-3"><div class="card-body text-muted">The node completed this job but returned '
			. 'no readable log excerpt; the transcript above is everything it said.</div></div>';
		$result_data = null;
	} elseif (is_array($result_data) && !empty($result_data['pruned'])) {
		// The retention sweep kept the job and dropped the excerpt
		// (ManagementJob::purgeLogExcerpts).
		echo '<div class="card mb-3"><div class="card-body text-muted">The log excerpt this job returned has been '
			. 'removed by the retention sweep; the job itself is kept as the record that it ran.</div></div>';
		$result_data = null;
	} elseif (is_array($result_data) && $job_type === 'apply_update' && is_array($result_data['apply'] ?? null)) {
		// The node's own structured account of the apply (APPLY_RESULT), then
		// the plane's probe of the version it now serves. The transcript above
		// stays for forensics.
		$a = $result_data['apply'];
		$h = function ($v) { return htmlspecialchars((string)$v); };
		$rb = $a['rolled_back'];
		$ok = $a['outcome'] === 'completed' && empty($rb['rolled_back']);
		echo '<div class="card mb-3"><div class="card-header"><strong>Apply result</strong> <small class="'
			. ($ok ? 'text-success' : 'text-danger') . '">— '
			. $h($a['version_before'] ?? '?') . ' → ' . $h($a['version_after'] ?? '?') . ', '
			. ($ok ? 'completed' : (!empty($rb['rolled_back']) ? 'rolled back at ' . $h($rb['step'] ?? 'an unnamed step') : 'did not complete'))
			. (!empty($a['self_updated']) ? '; the pipeline updated itself and re-ran' : '')
			. ', ' . (int)$a['duration_seconds'] . 's</small></div><div class="card-body">';
		if (!empty($rb['rolled_back']) && !empty($rb['schema_ahead_of_code'])) {
			echo '<div class="alert alert-warning small">The code was rolled back and the schema was not: migrations had already run. Upgrade forward rather than leaving the node here.</div>';
		}
		$tier = $a['deploy_tier'];
		echo '<div>Deploy tier: <strong>' . $h(str_replace('_', ' ', $tier['verdict'])) . '</strong>'
			. ($tier['failed_tests'] ? ' — failed: ' . $h(implode(', ', $tier['failed_tests'])) : '') . '</div>';
		if (isset($result_data['version'])) {
			echo '<div>The site now serves version ' . $h($result_data['version'] ?? 'unknown') . '.</div>';
		}
		if (!empty($a['truncated'])) {
			echo '<div class="small text-muted">The lists below were cut to fit the node\'s report; the counts are the full ones. The transcript above has every line.</div>';
		}
		echo '<div class="mt-2"><strong>Migrations</strong> (' . (int)($a['migrations_total'] ?? count($a['migrations'])) . ')</div>';
		if ($a['migrations']) {
			echo '<ul class="small mb-1">';
			foreach ($a['migrations'] as $m) {
				echo '<li class="' . ($m['outcome'] === 'failed' ? 'text-danger' : '') . '">' . $h($m['version']) . ': ' . $h($m['outcome'])
					. ($m['rows'] !== null ? ', ' . (int)$m['rows'] . ' row(s)' : '') . '</li>';
			}
			echo '</ul>';
		}
		echo '<div class="mt-2"><strong>Schema changes</strong> (' . (int)($a['schema_changes_total'] ?? count($a['schema_changes'])) . ')</div>';
		if ($a['schema_changes']) {
			echo '<ul class="small mb-1">';
			foreach ($a['schema_changes'] as $c) { echo '<li>' . $h($c) . '</li>'; }
			echo '</ul>';
		}
		echo '<div class="mt-2"><strong>Plugins synced</strong> (' . (int)($a['plugins_total'] ?? count($a['plugins'])) . ')</div>';
		if ($a['plugins']) {
			echo '<ul class="small mb-0">';
			foreach ($a['plugins'] as $p) {
				echo '<li>' . $h($p['name']) . ': ' . $h($p['before'] !== '' ? $p['before'] : 'new') . ' → ' . $h($p['after']) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div></div>';
		$result_data = null;
	} elseif (is_array($result_data) && $job_type === 'site_log' && array_key_exists('text', $result_data)) {
		// The node redacted this before it left; the plane's redactor is the
		// second pass, as it is for every transcript shown here.
		$file = (string)($result_data['file'] ?? '');
		echo '<div class="card mb-3"><div class="card-header"><strong>'
			. htmlspecialchars(JobCommandBuilder::SITE_LOG_FILES[$file] ?? $file)
			. (!empty($result_data['previous']) ? ' (previous rotation)' : '')
			. '</strong> <small class="text-muted">— '
			. (empty($result_data['present'])
				? 'not present on the node'
				: (int)($result_data['lines_returned'] ?? 0) . ' line(s)'
					. (!empty($result_data['truncated']) ? ', more in the file' : '')
					. ', ' . number_format((int)($result_data['size_bytes'] ?? 0)) . ' bytes on disk'
					. ', modified ' . htmlspecialchars((string)($result_data['modified_time'] ?? '')))
			. '</small></div>';
		echo '<pre class="svm-logbox">' . htmlspecialchars(SmSecretRedactor::redact((string)$result_data['text'])) . '</pre></div>';
		$result_data = null;
	} elseif (is_array($result_data) && $job_type === 'site_log_count' && is_array($result_data['markers'] ?? null)) {
		// Counts first, then whether they cover the whole window: a count from a
		// read that stopped short of 'since' is a floor, not an answer.
		$covers = !empty($result_data['covers_since']);
		echo '<div class="card mb-3"><div class="card-header"><strong>Error log problem lines since '
			. htmlspecialchars((string)($result_data['since'] ?? '')) . '</strong> <small class="text-muted">— '
			. (empty($result_data['present']) ? 'no error log on the node'
				: (int)($result_data['lines_in_window'] ?? 0) . ' line(s) in the window, read from '
					. htmlspecialchars(implode(', ', (array)($result_data['files_read'] ?? []))))
			. '</small></div><div class="card-body">';
		if ($covers) {
			echo '<div class="alert alert-success small py-1">The read reached back to '
				. htmlspecialchars((string)$result_data['covered_from']) . ': these counts cover the whole window.</div>';
		} else {
			echo '<div class="alert alert-warning small py-1">The read did not reach back to the start of the window'
				. ($result_data['covered_from'] !== '' ? ' (oldest line read: ' . htmlspecialchars((string)$result_data['covered_from']) . ')' : '')
				. ': ' . htmlspecialchars((string)($result_data['not_covered'] ?? '')) . '. The counts are a floor.</div>';
		}
		echo '<table class="table table-sm small mb-0"><thead><tr><th>Marker</th><th>Count</th><th>First</th><th>Last</th><th>Sample</th></tr></thead><tbody>';
		foreach (JobCommandBuilder::SITE_LOG_COUNT_MARKERS as $name => $label) {
			$m = $result_data['markers'][$name] ?? ['count' => 0];
			$count = (int)($m['count'] ?? 0);
			$sample = '';
			foreach ((array)($m['samples'] ?? []) as $line) {
				$sample .= '<div class="text-break">' . htmlspecialchars(SmSecretRedactor::redact((string)$line)) . '</div>';
			}
			echo '<tr' . ($count ? ' class="table-warning"' : '') . '><td>' . htmlspecialchars($label) . '</td><td>' . $count
				. '</td><td>' . htmlspecialchars((string)($m['first'] ?? '')) . '</td><td>' . htmlspecialchars((string)($m['last'] ?? ''))
				. '</td><td><small>' . $sample . '</small></td></tr>';
		}
		echo '</tbody></table></div></div>';
		$result_data = null;
	} elseif (is_array($result_data) && $job_type === 'log_table_tail' && isset($result_data['rows']) && is_array($result_data['rows'])) {
		$table   = (string)($result_data['table'] ?? '');
		$columns = is_array($result_data['columns'] ?? null) ? $result_data['columns'] : array();
		echo '<div class="card mb-3"><div class="card-header"><strong>'
			. htmlspecialchars(JobCommandBuilder::LOG_TABLES[$table] ?? $table)
			. '</strong> <small class="text-muted">— ' . count($result_data['rows']) . ' newest row(s)'
			. (!empty($result_data['truncated']) ? ', cut to the size cap' : '') . '</small></div>';
		echo '<div class="table-responsive"><table class="table table-sm table-striped mb-0"><thead><tr>';
		foreach ($columns as $c) { echo '<th>' . htmlspecialchars((string)$c) . '</th>'; }
		echo '</tr></thead><tbody>';
		foreach ($result_data['rows'] as $row) {
			if (!is_array($row)) { continue; }
			echo '<tr>';
			foreach ($columns as $c) {
				$v = $row[$c] ?? null;
				if (is_bool($v)) { $v = $v ? 'true' : 'false'; }
				elseif ($v === null) { $v = ''; }
				elseif (!is_scalar($v)) { $v = json_encode($v); }
				echo '<td>' . htmlspecialchars(SmSecretRedactor::redact((string)$v)) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div></div>';
		$result_data = null;
	} elseif (is_array($result_data) && $job_type === 'unit_journal' && !empty($result_data['read'])) {
		// The verdict first, because it is usually the whole answer: Result
		// names the kind of failure and exit_status is the number the unit
		// left behind. The journal is underneath for when it is not.
		$unit = (string)($result_data['unit'] ?? '');
		echo '<div class="card mb-3"><div class="card-header"><strong>' . htmlspecialchars($unit) . '</strong>'
			. ' <small class="text-muted">— ' . htmlspecialchars((string)($result_data['active_state'] ?? 'unknown'))
			. ' (' . htmlspecialchars((string)($result_data['sub_state'] ?? 'unknown')) . ')'
			. ', result ' . htmlspecialchars((string)($result_data['result'] ?? 'unknown'))
			. ', exit status ' . htmlspecialchars((string)($result_data['exit_status'] ?? 'unknown'));
		if (is_int($result_data['last_run_unix'] ?? null) && $result_data['last_run_unix'] > 0) {
			echo ', last ran ' . htmlspecialchars(LibraryFunctions::convert_time(
				gmdate('Y-m-d H:i:s', (int)$result_data['last_run_unix']), 'UTC',
				SessionControl::get_instance()->get_timezone(), 'M j, Y g:i A'));
		}
		echo '</small></div>';
		$lines = is_array($result_data['journal'] ?? null) ? $result_data['journal'] : array();
		if (count($lines) === 0) {
			echo '<div class="card-body text-muted">The node read no journal lines for this unit.</div>';
		} else {
			echo '<pre class="svm-logbox">' . htmlspecialchars(SmSecretRedactor::redact(implode("\n", $lines))) . '</pre>';
		}
		echo '</div>';
		$result_data = null;
	} elseif (is_array($result_data) && $job_type === 'reset_failed_unit' && !empty($result_data['read'])) {
		$state = function ($s) {
			$s = is_array($s) ? $s : array();
			return htmlspecialchars((string)($s['active_state'] ?? 'unknown')) . ' ('
				. htmlspecialchars((string)($s['sub_state'] ?? 'unknown')) . '), result '
				. htmlspecialchars((string)($s['result'] ?? 'unknown'));
		};
		echo '<div class="card mb-3"><div class="card-header"><strong>'
			. htmlspecialchars((string)($result_data['unit'] ?? '')) . '</strong> <small class="text-muted">— '
			. (!empty($result_data['reset']) ? 'failed record cleared' : 'systemd did not accept the reset')
			. '</small></div><div class="card-body">'
			. '<div>Before: ' . $state($result_data['before'] ?? null) . '</div>'
			. '<div>After: ' . $state($result_data['after'] ?? null) . '</div>'
			. '<small class="text-muted">Clearing changes nothing that runs: a unit that is still broken fails again the next time it starts, and the next host report names it again.</small>'
			. '</div></div>';
		$result_data = null;
	} elseif (is_array($result_data) && $job_type === 'install_security_updates' && !empty($result_data['read'])) {
		// The nightly security updates, run now (agent 1.87.0): the outcome in
		// plain words, then each package that changed.
		$su_outcomes = array(
			'installed'          => 'security updates installed',
			'nothing_to_install' => 'nothing to install: the machine already had every update its policy allows',
			'failed'             => 'the updater reported an error',
			'busy'               => 'another package run held the lock; try again in a few minutes',
			'unavailable'        => 'this machine cannot run automatic updates',
		);
		$su_outcome = (string)($result_data['outcome'] ?? 'unknown');
		$su_count = (int)($result_data['upgraded_count'] ?? 0);
		echo '<div class="card mb-3"><div class="card-header"><strong>Security updates</strong> <small class="text-muted">— '
			. htmlspecialchars($su_outcomes[$su_outcome] ?? 'outcome unknown')
			. ($su_count > 0 ? ' (' . $su_count . ' package' . ($su_count === 1 ? '' : 's') . ')' : '')
			. '</small></div><div class="card-body">';
		if ((string)($result_data['reason'] ?? '') !== '') {
			echo '<div class="mb-2">' . htmlspecialchars((string)$result_data['reason']) . '</div>';
		}
		if (($result_data['apt_update'] ?? '') === 'failed') {
			echo '<div class="mb-2 text-warning">The package lists could not be refreshed, so only updates the machine already knew about were considered.</div>';
		}
		if (!empty($result_data['reboot_required'])) {
			echo '<div class="mb-2 text-warning">An update asks for a reboot. It was not rebooted; the Reboot button on the node page does that.</div>';
		}
		if (!empty($result_data['repaired'])) {
			echo '<div class="mb-2">A package run left half done was finished first.</div>';
		}
		// The php-fpm binaries reloaded after the run, and any still on old code
		// (install_security_updates.sh 1.2): what decides whether the fix is live.
		$su_reloaded = $result_data['reloaded'] ?? null;
		if (is_array($su_reloaded) && $su_reloaded) {
			echo '<div class="mb-2">Reloaded so the new code serves: ' . htmlspecialchars(implode(', ', array_map('strval', $su_reloaded))) . '</div>';
		}
		$su_old = $result_data['still_on_old_code'] ?? null;
		if (is_array($su_old) && $su_old) {
			echo '<div class="mb-2 text-danger">Still serving the old code: ' . htmlspecialchars(implode(', ', array_map('strval', $su_old)))
				. '. It did not reload; restart the site, or its container, to put the fix in place.</div>';
		}
		$su_services = $result_data['services_needing_restart'] ?? null;
		if (is_array($su_services) && $su_services) {
			echo '<div class="mb-2 text-warning">Still running replaced code until restarted: '
				. htmlspecialchars(implode(', ', array_map('strval', $su_services))) . '</div>';
		}
		$su_rows = is_array($result_data['upgraded'] ?? null) ? $result_data['upgraded'] : array();
		if ($su_rows) {
			echo '<table class="table table-sm mb-0"><thead><tr><th>Package</th><th>Was</th><th>Now</th></tr></thead><tbody>';
			foreach ($su_rows as $row) {
				echo '<tr><td>' . htmlspecialchars((string)($row['package'] ?? '')) . '</td><td>'
					. htmlspecialchars((string)($row['from'] ?? '') !== '' ? (string)$row['from'] : 'new') . '</td><td>'
					. htmlspecialchars((string)($row['to'] ?? '')) . '</td></tr>';
			}
			echo '</tbody></table>';
			if (!empty($result_data['truncated'])) {
				echo '<small class="text-muted">The list stops at ' . count($su_rows) . '; ' . $su_count . ' packages changed in all.</small>';
			}
		}
		echo '</div></div>';
		$result_data = null;
	} elseif (is_array($result_data) && $job_type === 'site_state_report' && !empty($result_data['read'])) {
		// What the machine's site state directories hold (agent 1.86.0): a
		// table per site, the plain records' values, every other record named
		// and sized only.
		$when = function ($t) use ($session) {
			return $t ? LibraryFunctions::convert_time(str_replace(array('T', 'Z'), array(' ', ''), (string)$t), 'UTC', $session->get_timezone(), 'M j, Y g:i:s A') : '';
		};
		echo '<div class="card mb-3"><div class="card-header"><strong>Site state records</strong> <small class="text-muted">— '
			. htmlspecialchars((string)($result_data['sites_dir'] ?? '')) . ' on this machine</small></div><div class="card-body">';
		$reading = $result_data['reading'] ?? null;
		if (is_array($reading)) {
			$reason = (string)($reading['reason'] ?? '');
			$what = $reason === 'copy' ? 'a dormant copy' . (!empty($reading['copy_of']) ? ' of node ' . (int)$reading['copy_of'] : '')
				: ($reason === 'switchover' ? 'frozen for a switch-over' : ($reason === '' ? 'live' : 'in a state this page does not know'));
			echo '<div class="mb-2">The agent reads its site, <strong>' . htmlspecialchars((string)($reading['site'] ?? '')) . '</strong>, as ' . $what . '.'
				. (!empty($reading['error']) ? ' <span class="text-danger">' . htmlspecialchars((string)$reading['error']) . '</span>' : '') . '</div>';
		}
		$ss_sites = is_array($result_data['sites']) ? $result_data['sites'] : array();
		if (empty($result_data['exists'])) {
			echo '<div class="text-muted">There is no site state directory: no site here has been quiet or a copy.</div>';
		} elseif (!$ss_sites) {
			echo '<div class="text-muted">The directory holds no site\'s records.</div>';
		}
		foreach ($ss_sites as $ss) {
			$entries = is_array($ss['entries'] ?? null) ? $ss['entries'] : array();
			echo '<div class="mt-2"><strong>' . htmlspecialchars((string)($ss['site'] ?? '')) . '</strong>';
			if (!$entries) {
				echo ' <span class="text-muted">— an empty directory</span></div>';
				continue;
			}
			echo '</div><table class="table table-sm mb-2"><thead><tr><th>Record</th><th>Kind</th><th>Size</th><th>Mode</th><th>Root\'s</th><th>Changed</th><th>Value</th></tr></thead><tbody>';
			foreach ($entries as $e) {
				if (!empty($e['error'])) {
					echo '<tr><td><code>' . htmlspecialchars((string)($e['name'] ?? '')) . '</code></td><td colspan="6" class="text-danger">' . htmlspecialchars((string)$e['error']) . '</td></tr>';
					continue;
				}
				echo '<tr><td><code>' . htmlspecialchars((string)($e['name'] ?? '')) . '</code></td>'
					. '<td>' . htmlspecialchars((string)($e['kind'] ?? '')) . '</td>'
					. '<td>' . (isset($e['bytes']) ? (int)$e['bytes'] . ' B' : '') . '</td>'
					. '<td>' . htmlspecialchars((string)($e['mode'] ?? '')) . '</td>'
					. '<td>' . (array_key_exists('root_owned', $e) ? ($e['root_owned'] ? 'yes' : '<span class="text-danger">no</span>') : '') . '</td>'
					. '<td>' . htmlspecialchars($when($e['modified'] ?? '')) . '</td>'
					. '<td>' . (isset($e['value']) ? '<code>' . htmlspecialchars((string)$e['value']) . '</code>' : '') . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		if (!empty($result_data['truncated'])) {
			echo '<div class="small text-muted">The node cut this short: it lists at most 100 sites and 64 records each.</div>';
		}
		echo '<small class="text-muted">Values are shown for the plain records only (the state line, copy_of, forward, and a host\'s quiet record and id block). The look secret, run spec, source key, vouched runs and history are named and sized, never read.</small>';
		echo '</div></div>';
		$result_data = null;
	} elseif (is_array($result_data) && $job_type === 'net_health' && !empty($result_data['read'])) {
		// Whether the machine drops packets or connections (agent 1.93.0): the
		// verdict, then each counter's change over the interval and its total.
		$secs = (int)($result_data['interval_seconds'] ?? 10);
		echo '<div class="card mb-3"><div class="card-header"><strong>Network health</strong> <small class="text-muted">— counts only, read twice ' . $secs . ' s apart</small></div>';
		echo '<div class="card-body"><p class="mb-2"><strong>' . htmlspecialchars((string)($result_data['verdict'] ?? '')) . '</strong></p>';
		$ctr = $result_data['counters'] ?? 'unknown';
		if (is_array($ctr) && $ctr) {
			echo '<table class="table table-sm mb-2"><thead><tr><th>Counter</th><th class="text-end">In the last ' . $secs . ' s</th><th class="text-end">Since boot</th></tr></thead><tbody>';
			foreach (JobResultProcessor::NET_HEALTH_COUNTERS as $k => $label) {
				if (!isset($ctr[$k])) { continue; }
				$drop = in_array($k, JobResultProcessor::NET_HEALTH_DROP_COUNTERS, true) && $ctr[$k]['per_interval'] > 0;
				echo '<tr' . ($drop ? ' class="text-danger"' : '') . '><td>' . htmlspecialchars(ucfirst($label)) . '</td><td class="text-end">'
					. (int)$ctr[$k]['per_interval'] . '</td><td class="text-end">' . number_format((int)$ctr[$k]['total']) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		$ct = $result_data['conntrack'] ?? 'absent';
		echo '<div class="small">Connection table: ' . (is_array($ct)
			? number_format((int)$ct['count']) . ' of ' . number_format((int)$ct['max']) . ' (' . (int)$ct['percent'] . '%)'
			: htmlspecialchars($ct === 'absent' ? 'not in use on this machine' : 'not read')) . '</div>';
		$web = $result_data['web_connections'] ?? 'unknown';
		if (is_array($web)) {
			$bits = array();
			foreach ($web as $state => $n) { $bits[] = htmlspecialchars($state) . ' ' . (int)$n; }
			echo '<div class="small">Connections on ports 80 and 443: ' . ($bits ? implode(', ', $bits) : 'none') . '</div>';
		}
		$qs = $result_data['listen_queues'] ?? 'unknown';
		if (is_array($qs)) {
			foreach ($qs as $q) {
				echo '<div class="small">Listener <code>' . htmlspecialchars((string)$q['address']) . ':' . (int)$q['port'] . '</code>: '
					. (int)$q['queued'] . ' waiting to be accepted, room for ' . (int)$q['limit'] . '</div>';
			}
		}
		echo '</div></div>';
		$result_data = null;
	} elseif (is_array($result_data) && $job_type === 'web_workers' && !empty($result_data['read'])) {
		// Whether the web tier is out of workers (agent 1.93.0).
		echo '<div class="card mb-3"><div class="card-header"><strong>Web workers</strong> <small class="text-muted">— '
			. htmlspecialchars((string)($result_data['site'] ?? '') ?: 'no site') . ', read over loopback; counts only</small></div>';
		echo '<div class="card-body"><p class="mb-2"><strong>' . htmlspecialchars((string)($result_data['verdict'] ?? '')) . '</strong></p>';
		$fpm = $result_data['fpm'] ?? 'unknown';
		if (is_array($fpm)) {
			$cell = function ($v) { return $v === null ? '—' : (string)(int)$v; };
			echo '<table class="table table-sm mb-2"><tbody>'
				. '<tr><th>PHP pool</th><td>' . htmlspecialchars($fpm['pool'] ?: '—') . ' (' . htmlspecialchars($fpm['process_manager'] ?: '—') . ')</td></tr>'
				. '<tr><th>Busy / idle / ceiling</th><td>' . $cell($fpm['active']) . ' / ' . $cell($fpm['idle']) . ' / ' . $cell($fpm['max_children']) . '</td></tr>'
				. '<tr><th>Waiting for a worker now / most ever</th><td>' . $cell($fpm['listen_queue']) . ' / ' . $cell($fpm['max_listen_queue']) . '</td></tr>'
				. '<tr><th>Ceiling reached since PHP started</th><td>' . $cell($fpm['max_children_reached']) . ' time(s)</td></tr>'
				. '<tr><th>Most busy at once</th><td>' . $cell($fpm['max_active']) . '</td></tr>'
				. '</tbody></table>';
		} else {
			echo '<div class="small text-muted">PHP-FPM: ' . htmlspecialchars($fpm === 'not_reported' ? 'no site on this machine' : 'its status page did not answer') . '</div>';
		}
		$ap = $result_data['apache'] ?? 'unknown';
		if (is_array($ap)) {
			$sbits = array();
			foreach ($ap['scoreboard'] as $k => $n) { if ($n > 0 && $k !== 'open_slot') { $sbits[] = str_replace('_', ' ', $k) . ' ' . (int)$n; } }
			echo '<div class="small">Apache: ' . (int)$ap['busy'] . ' busy, ' . (int)$ap['idle'] . ' idle of ' . (int)$ap['slots'] . ' slots'
				. ($sbits ? ' (' . htmlspecialchars(implode(', ', $sbits)) . ')' : '') . '</div>';
		} else {
			echo '<div class="small text-muted">Apache: ' . htmlspecialchars($ap === 'not_reported' ? 'no site on this machine' : 'its status page did not answer') . '</div>';
		}
		$ev = $result_data['fpm_max_children_events_24h'] ?? 'unknown';
		echo '<div class="small">PHP hit its ceiling in the last day: ' . (is_int($ev) ? (int)$ev . ' time(s)' : 'not read') . '</div>';
		echo '</div></div>';
		$result_data = null;
	} elseif (is_array($result_data) && $job_type === 'listening_sockets' && !empty($result_data['read'])) {
		// What the machine listens on (agent 1.76.0): a row per listener.
		$socks = $result_data['sockets'] ?? 'unknown';
		echo '<div class="card mb-3"><div class="card-header"><strong>Listening</strong> <small class="text-muted">— TCP sockets that listen and UDP sockets that are bound; no connections</small></div>';
		if (!is_array($socks)) {
			echo '<div class="card-body text-muted">The node could not read its sockets (ss is missing or did not answer).</div>';
		} elseif (!$socks) {
			echo '<div class="card-body text-muted">Nothing listens.</div>';
		} else {
			echo '<div class="card-body p-0"><table class="table table-sm mb-0"><thead><tr><th>Protocol</th><th>Address</th><th>Port</th><th>Program</th><th>Unit</th></tr></thead><tbody>';
			foreach ($socks as $r) {
				$fam = array('ipv4' => 'IPv4', 'ipv6' => 'IPv6', 'any' => 'IPv4 and IPv6')[$r['family'] ?? ''] ?? 'unknown';
				echo '<tr><td>' . htmlspecialchars(strtoupper((string)$r['proto'])) . '</td><td><code>' . htmlspecialchars((string)$r['address'])
					. '</code> <small class="text-muted">' . $fam . '</small></td><td>' . (int)$r['port'] . '</td><td>'
					. htmlspecialchars((string)$r['program'] ?: '—') . '</td><td>' . htmlspecialchars((string)$r['unit'] ?: '—') . '</td></tr>';
			}
			echo '</tbody></table></div>';
			if (!empty($result_data['truncated'])) {
				echo '<div class="card-body small text-muted">The first ' . count($socks) . ' are shown; the machine listens on more.</div>';
			}
		}
		echo '</div>';
		$result_data = null;
	} elseif (is_array($result_data) && $job_type === 'container_inventory' && !empty($result_data['read'])) {
		// Every container on the host (agent 1.76.0), install.sh's and not.
		$list = $result_data['containers'] ?? 'unknown';
		$engine = array('docker' => 'Docker', 'podman' => 'the site container engine (Podman)')[$result_data['engine'] ?? ''] ?? 'its container engine';
		echo '<div class="card mb-3"><div class="card-header"><strong>Containers on this machine</strong> <small class="text-muted">— as ' . htmlspecialchars($engine) . ' lists them</small></div>';
		if ($list === 'none') {
			echo '<div class="card-body text-muted">This machine has no ' . htmlspecialchars($engine) . '.</div>';
		} elseif (!is_array($list)) {
			echo '<div class="card-body text-muted">' . htmlspecialchars(ucfirst($engine)) . ' did not answer, so the containers are not known.</div>';
		} elseif (!$list) {
			echo '<div class="card-body text-muted">No containers.</div>';
		} else {
			echo '<div class="card-body small text-muted">A site container is one install.sh made: its name is a site name and its SITENAME is its name. Removing a site, holding it stopped and restarting it act only on those; anything else here is someone else\'s and nothing on this plane touches it.</div>';
			echo '<div class="card-body p-0"><table class="table table-sm mb-0"><thead><tr><th>Container</th><th>Made by install.sh</th><th>Image</th><th>State</th><th>Mounts</th><th>Run spec</th></tr></thead><tbody>';
			foreach ($list as $c) {
				$mounts = $c['mounts'] ?? 'unknown';
				if (is_array($mounts)) {
					$parts = array();
					foreach ($mounts as $m) {
						$parts[] = htmlspecialchars((string)$m['type']) . ($m['name'] !== '' ? ' <code>' . htmlspecialchars((string)$m['name']) . '</code>' : '');
					}
					$mounts = $parts ? implode(', ', $parts) : 'none';
				} else {
					$mounts = 'unknown';
				}
				echo '<tr' . (empty($c['install_sh']) ? ' class="table-warning"' : '') . '><td><code>' . htmlspecialchars((string)$c['name']) . '</code></td><td>'
					. (!empty($c['install_sh']) ? 'yes' : '<strong>no</strong>') . '</td><td><code>' . htmlspecialchars((string)$c['image']) . '</code></td><td>'
					. htmlspecialchars((string)$c['state']) . '</td><td class="small">' . $mounts . '</td><td>' . (!empty($c['run_spec']) ? 'yes' : 'no') . '</td></tr>';
			}
			echo '</tbody></table></div>';
			if (!empty($result_data['truncated'])) {
				echo '<div class="card-body small text-muted">The first ' . count($list) . ' are shown; the machine has more.</div>';
			}
		}
		echo '</div>';
		$result_data = null;
	} elseif (is_array($result_data) && $job_type === 'spam_learning_status' && !empty($result_data['read'])) {
		// Whether members' spam corrections reach the filter (agent 1.76.0): counts only.
		echo '<div class="card mb-3"><div class="card-header"><strong>Spam learning</strong></div><div class="card-body">';
		if (($result_data['mailbox'] ?? '') !== 'active') {
			echo '<div class="text-muted">The Mailbox plugin is not active on this site.</div>';
		} else {
			$n = function ($v) { return is_int($v) ? number_format($v) : 'unknown'; };
			$c = $result_data['corrections'] ?? array();
			$k = $result_data['corpus'] ?? array();
			$p = $result_data['policy'] ?? array();
			echo '<div>Filing suspected spam: <strong>' . (!empty($p['filtering_enabled']) ? 'on' : 'off') . '</strong>; learning from what members mark: <strong>'
				. (!empty($p['learning_enabled']) ? 'on' : 'off') . '</strong></div>';
			echo '<div class="mt-2">Marked spam or not spam in the last ' . $n($result_data['window_days'] ?? null) . ' days: <strong>' . $n($c['recorded'] ?? null) . '</strong></div>'
				. '<ul class="mb-2"><li>taught: ' . $n($c['taught'] ?? null) . '</li>'
				. '<li>waiting for the next pass: ' . $n($c['pending_clear'] ?? null) . '</li>'
				. '<li>waiting for their owner to unlock their mail: ' . $n($c['pending_sealed'] ?? null) . '</li>'
				. '<li>never taught, because they were fetched from another mail server over IMAP: ' . $n($c['skipped_imap'] ?? null) . '</li></ul>';
			echo '<div>The spam filter has learned from ' . $n($k['spam_messages'] ?? null) . ' spam and ' . $n($k['ham_messages'] ?? null) . ' good messages ('
				. $n($k['tokens'] ?? null) . ' words), ' . (!empty($k['trained']) ? 'enough for its answer to count' : 'not yet enough for its answer to count')
				. (!empty($k['newest_token']) ? '; it last learned something on ' . htmlspecialchars(LibraryFunctions::convert_time(str_replace(array('T', 'Z'), array(' ', ''), $k['newest_token']), 'UTC', $session->get_timezone(), 'M j, Y g:i A')) : '')
				. '.</div>';
		}
		echo '</div></div>';
		$result_data = null;
	} elseif (is_array($result_data) && $job_type === 'reboot_machine' && !empty($result_data['read'])) {
		// The reboot (agent 1.76.0): scheduled, and whether it was seen to happen.
		echo '<div class="card mb-3"><div class="card-header"><strong>Reboot</strong></div><div class="card-body">';
		if (empty($result_data['scheduled'])) {
			echo '<div>Not rebooted: ' . htmlspecialchars((string)($result_data['reason'] ?? '')) . '</div>';
		} else {
			echo '<div>' . (!empty($result_data['already_scheduled']) ? 'A reboot was already scheduled; no second one was added.' : 'Scheduled ' . (int)($result_data['in_seconds'] ?? 0) . ' seconds after this job reported.') . '</div>';
			if (!empty($result_data['came_back_at'])) {
				echo '<div class="text-success mt-1">It came back: a host report at ' . htmlspecialchars(LibraryFunctions::convert_time((string)$result_data['came_back_at'], 'UTC', $session->get_timezone(), 'M j, g:i A'))
					. ' carried a new boot id.</div>';
			} else {
				echo '<div class="text-muted mt-1">Not yet seen to come back. This management node asks the machine for a host report every two minutes until one carries a new boot id.</div>';
			}
		}
		echo '</div></div>';
		$result_data = null;
	} elseif (is_array($result_data) && $job_type === 'move_to_plane' && !empty($result_data['fingerprint'])) {
		$fp = (string)$result_data['fingerprint'];
		echo '<div class="card mb-3"><div class="card-header"><strong>Asked '
			. htmlspecialchars((string)($result_data['management_node'] ?? '')) . ' to adopt this machine</strong>'
			. ' <small class="text-muted">— as ' . htmlspecialchars((string)($result_data['claimed_name'] ?? '')) . '</small></div>'
			. '<div class="card-body">'
			. '<div>Key fingerprint: <code>' . htmlspecialchars(trim(chunk_split($fp, 4, ' '))) . '</code></div>'
			. '<small class="text-muted">Approve the request on that management node only if it shows exactly this fingerprint. '
			. 'Until then the machine stays here; once it is approved it says goodbye here and restarts onto the new one.</small>'
			. '</div></div>';
		$result_data = null;
	} elseif (is_array($result_data) && in_array($job_type, array('restart_unit', 'restart_container'), true) && !empty($result_data['read'])) {
		$state = function ($s) {
			$s = is_array($s) ? $s : array();
			$bits = array();
			foreach ($s as $k => $v) { $bits[] = str_replace('_', ' ', $k) . ' ' . htmlspecialchars((string)$v); }
			return $bits ? implode(', ', $bits) : 'unknown';
		};
		echo '<div class="card mb-3"><div class="card-header"><strong>'
			. htmlspecialchars((string)($result_data['target'] ?? '')) . '</strong> <small class="text-muted">— '
			. (!empty($result_data['absent']) ? 'not on this machine; nothing was restarted'
				: (!empty($result_data['restarted']) ? 'restarted' : 'the restart was not accepted'))
			. '</small></div><div class="card-body">'
			. '<div>Before: ' . $state($result_data['before'] ?? null) . '</div>'
			. '<div>After: ' . $state($result_data['after'] ?? null) . '</div>'
			. '</div></div>';
		$result_data = null;
	} elseif (is_array($result_data) && in_array($job_type, array('run_installer', 'reclaim_managed_file'), true) && isset($result_data['ran'])) {
		echo '<div class="card mb-3"><div class="card-header"><strong>'
			. htmlspecialchars($job_type === 'reclaim_managed_file'
				? (JobCommandBuilder::FILE_HEAD_FILES[$result_data['file'] ?? ''] ?? (string)($result_data['file'] ?? '')) . ' — reset by ' . (string)($result_data['name'] ?? '')
				: (string)($result_data['name'] ?? ''))
			. '</strong> <small class="text-muted">— ' . (!empty($result_data['ran']) ? 'ran to its ok line' : 'did not complete') . '</small></div>';
		if (!empty($result_data['reclaim'])) {
			echo '<ul class="list-group list-group-flush">';
			foreach ((array)$result_data['reclaim'] as $line) { echo '<li class="list-group-item small">' . htmlspecialchars((string)$line) . '</li>'; }
			echo '</ul>';
		}
		$failures = is_array($result_data['failures'] ?? null) ? $result_data['failures'] : array();
		if ($failures) {
			echo '<ul class="list-group list-group-flush">';
			foreach ($failures as $f) { echo '<li class="list-group-item text-danger small">' . htmlspecialchars((string)$f) . '</li>'; }
			echo '</ul>';
		}
		echo '</div>';
		// The transcript below stays: the card says what the runner concluded.
	} elseif (is_array($result_data) && $job_type === 'file_head' && array_key_exists('text', $result_data)) {
		$file = (string)($result_data['file'] ?? '');
		echo '<div class="card mb-3"><div class="card-header"><strong>'
			. htmlspecialchars(JobCommandBuilder::FILE_HEAD_FILES[$file] ?? $file)
			. '</strong> <small class="text-muted">— ' . htmlspecialchars((string)($result_data['path'] ?? '')) . ', '
			. (empty($result_data['present'])
				? 'not present on the node'
				: (int)($result_data['lines_returned'] ?? 0) . ' line(s)'
					. (!empty($result_data['truncated']) ? ', more in the file' : '')
					. ', ' . number_format((int)($result_data['size_bytes'] ?? 0)) . ' bytes on disk'
					. ', modified ' . htmlspecialchars((string)($result_data['modified_time'] ?? '')))
			. '</small></div>';
		echo '<pre class="svm-logbox">' . htmlspecialchars(SmSecretRedactor::redact((string)$result_data['text'])) . '</pre>'
			. '<div class="card-footer small text-muted">A line whose setting names a credential is shown as the setting alone; the node never sent its value.</div></div>';
		$result_data = null;
	} elseif (is_array($result_data) && $job_type === 'schema_probe' && !empty($result_data['read'])) {
		$table = htmlspecialchars((string)($result_data['table'] ?? ''));
		echo '<div class="card mb-3"><div class="card-header"><strong>' . $table . '</strong> <small class="text-muted">— ';
		if (empty($result_data['exists'])) {
			echo 'does not exist in the site\'s schema</small></div></div>';
		} else {
			$n = (int)($result_data['row_count'] ?? -1);
			echo ($n < 0 ? 'row count unknown' : number_format($n) . ' row(s)' . (empty($result_data['row_count_exact']) ? ' (planner estimate; too large to count in time)' : ''))
				. '</small></div>';
			echo '<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Column</th><th>Type</th><th>Nullable</th><th>Default</th></tr></thead><tbody>';
			foreach ((array)($result_data['columns'] ?? array()) as $c) {
				echo '<tr><td>' . htmlspecialchars((string)($c['name'] ?? '')) . '</td><td>' . htmlspecialchars((string)($c['type'] ?? ''))
					. (isset($c['max_length']) ? '(' . (int)$c['max_length'] . ')' : '') . '</td><td>'
					. (!empty($c['nullable']) ? 'yes' : '<strong>NOT NULL</strong>') . '</td><td><small>'
					. htmlspecialchars((string)($c['default'] ?? '')) . '</small></td></tr>';
			}
			echo '</tbody></table></div>';
			$idx = (array)($result_data['indexes'] ?? array());
			if ($idx) {
				echo '<ul class="list-group list-group-flush small">';
				foreach ($idx as $i) {
					echo '<li class="list-group-item"><strong>' . htmlspecialchars((string)($i['name'] ?? '')) . '</strong> '
						. htmlspecialchars((string)($i['definition'] ?? '')) . '</li>';
				}
				echo '</ul>';
			}
			echo '</div>';
		}
		$result_data = null;
	} elseif (is_array($result_data) && $job_type === 'page_probe' && !empty($result_data['read'])) {
		$h = function ($v) { return htmlspecialchars((string)$v); };
		$st = (int)$result_data['status'];
		$lm = $result_data['landmarks'];
		echo '<div class="card mb-3"><div class="card-header"><strong>' . $h($result_data['page']) . '</strong> <small class="'
			. ($st >= 200 && $st < 400 ? 'text-muted' : 'text-danger') . '">— as ' . $h($result_data['viewer']) . ', HTTP ' . $st
			. ', ' . number_format((int)$result_data['bytes']) . ' bytes'
			. ($result_data['render_ms'] !== null ? ', ' . (int)$result_data['render_ms'] . ' ms' : '')
			. ($result_data['statements'] !== null ? ', ' . (int)$result_data['statements'] . ' queries' : '')
			. ($result_data['peak_memory'] !== null ? ', ' . $h(JobResultProcessor::format_size((int)$result_data['peak_memory'])) . ' peak memory' : '')
			. '</small></div><div class="card-body">';
		echo '<div>Landmarks: header ' . ($lm['header'] ? 'yes' : '<strong>no</strong>') . ', main ' . ($lm['main'] ? 'yes' : '<strong>no</strong>')
			. ', footer ' . ($lm['footer'] ? 'yes' : '<strong>no</strong>') . '; ' . (int)$lm['forms'] . ' form(s)</div>';
		echo '<div class="small text-muted">Structure hash ' . $h(substr($result_data['structure_hash'], 0, 16)) . ' — the same page renders to the same hash while its structure is unchanged.</div>';
		if (!empty($result_data['error'])) {
			echo '<div class="text-danger small">The page was never rendered — ' . $h($result_data['error']) . '.</div>';
		} elseif (!$result_data['reported']) {
			echo '<div class="text-warning small">The render left no report (it may have died before its end); timing, queries and warnings are unknown.</div>';
		}
		if ($result_data['warnings']) {
			echo '<div class="mt-2"><strong>PHP warnings and errors</strong></div><ul class="small mb-1">';
			foreach ($result_data['warnings'] as $w) { echo '<li>' . $h($w['type']) . ' at ' . $h($w['at']) . '</li>'; }
			echo '</ul>';
		}
		if ($result_data['failed_assets']) {
			echo '<div class="mt-2"><strong>Assets that failed to load</strong></div><ul class="small mb-1">';
			foreach ($result_data['failed_assets'] as $a) { echo '<li>' . $h($a['path']) . ' — HTTP ' . (int)$a['status'] . '</li>'; }
			echo '</ul>';
		}
		if (!empty($result_data['failed_assets_unnamed'])) {
			echo '<div class="small text-muted">' . (int)$result_data['failed_assets_unnamed']
				. ' more asset path(s) failed that the release does not ship; they may come from what members wrote, so they are counted, not named.</div>';
		}
		if (!empty($result_data['truncated'])) {
			echo '<div class="small text-muted">The page was larger than 4 MiB; its structure was read from the first 4 MiB.</div>';
		}
		foreach ($result_data['warnings'] as $w) {
			if ($w['type'] === 'refused_write') {
				echo '<div class="small text-danger">The render tried to write (at ' . $h($w['at']) . '); the probe session is view-only and refused it.</div>';
				break;
			}
		}
		if ($result_data['cleanup'] !== 'done') {
			echo '<div class="text-danger small">Cleanup: ' . $h($result_data['cleanup']) . '</div>';
		}
		echo '</div></div>';
		$result_data = null;
	} elseif (is_array($result_data) && $job_type === 'disk_usage' && !empty($result_data['read'])) {
		$fmt = function ($v) {
			if ($v === 'absent') { return 'not present'; }
			return is_int($v) ? JobResultProcessor::format_size($v) : 'unknown';
		};
		$tree = is_array($result_data['tree'] ?? null) ? $result_data['tree'] : array();
		$fs   = is_array($result_data['filesystem'] ?? null) ? $result_data['filesystem'] : array();
		echo '<div class="card mb-3"><div class="card-header"><strong>Disk usage</strong> <small class="text-muted">— '
			. htmlspecialchars($fmt($fs['used_bytes'] ?? null)) . ' used of '
			. htmlspecialchars($fmt($fs['total_bytes'] ?? null)) . ', '
			. htmlspecialchars($fmt($fs['avail_bytes'] ?? null)) . ' free'
			. (!empty($tree['partial']) ? ' — the walk could not read everything, so the tree figures are a floor' : '')
			. '</small></div><div class="card-body">';
		echo '<p class="mb-1"><strong>' . htmlspecialchars((string)($tree['path'] ?? '')) . '</strong>: '
			. htmlspecialchars($fmt($tree['total_bytes'] ?? null)) . '</p>';
		$rows = function ($entries) use ($fmt) {
			echo '<table class="table table-sm mb-3"><tbody>';
			foreach ((is_array($entries) ? $entries : array()) as $e) {
				echo '<tr><td>' . htmlspecialchars((string)($e['path'] ?? '')) . '</td>'
					. '<td class="text-end">' . htmlspecialchars($fmt($e['bytes'] ?? null)) . '</td></tr>';
			}
			echo '</tbody></table>';
		};
		// Depth two, so a parent and a child both appear: the pair is the
		// answer to "which part of it", not a double count.
		$rows($tree['entries'] ?? array());
		$dr = is_array($result_data['data_root'] ?? null) ? $result_data['data_root'] : null;
		if ($dr) {
			$dr_fs = is_array($dr['filesystem'] ?? null) ? $dr['filesystem'] : array();
			$dr_tree = is_array($dr['tree'] ?? null) ? $dr['tree'] : array();
			echo '<p class="mb-1"><strong>' . htmlspecialchars((string)($dr_fs['path'] ?? '')) . '</strong> (the data root): '
				. htmlspecialchars($fmt($dr_fs['used_bytes'] ?? null)) . ' used of '
				. htmlspecialchars($fmt($dr_fs['total_bytes'] ?? null)) . ', '
				. htmlspecialchars($fmt($dr_fs['avail_bytes'] ?? null)) . ' free'
				. (!empty($dr_tree['partial']) ? ' <small class="text-muted">(the walk could not read everything, so these are a floor)</small>' : '')
				. '</p>';
			$rows($dr_tree['entries'] ?? array());
		}
		echo '<p class="mb-1 text-muted small">Machine directories</p>';
		$rows($result_data['machine'] ?? array());
		echo '</div></div>';
		$result_data = null;
	}
	if ($result_data) {
		echo '<div class="card mb-3"><div class="card-header"><strong>Structured Result</strong></div><div class="card-body">';
		echo '<pre>' . htmlspecialchars(SmSecretRedactor::redact(json_encode($result_data, JSON_PRETTY_PRINT))) . '</pre>';
		echo '</div></div>';
	}
}

// Show steps
$commands = $job->get('mjb_commands');
$commands_data = is_string($commands) ? json_decode($commands, true) : $commands;
if ($commands_data && isset($commands_data['steps'])) {
	echo '<div class="card mb-3"><div class="card-header"><strong>Steps</strong> <small class="text-muted">— progress indicator for job execution</small></div>';
	echo '<ul class="list-group list-group-flush">';
	// mjb_current_step is the 0-based index of the running (or last-run) step.
	$raw_current = intval($job->get('mjb_current_step'));
	$status = $job->get('mjb_status');
	// (status-icon styling moved to .svm-status-icon)
	foreach ($commands_data['steps'] as $i => $step) {
		if ($status === 'completed') {
			$icon = '<span class="text-success me-2 svm-status-icon">&#10003;</span>';
		} elseif ($i < $raw_current) {
			$icon = '<span class="text-success me-2 svm-status-icon">&#10003;</span>';
		} elseif ($i === $raw_current) {
			if ($status === 'failed') {
				$icon = '<span class="text-danger me-2 svm-status-icon">&#10007;</span>';
			} elseif ($status === 'running') {
				$icon = '<span class="text-primary me-2 svm-status-icon">&#9654;</span>';
			} elseif ($status === 'cancelled') {
				$icon = '<span class="text-secondary me-2 svm-status-icon">&#9633;</span>';
			} else {
				$icon = '<span class="text-muted me-2 svm-status-icon">&#9675;</span>';
			}
		} else {
			$icon = '<span class="text-muted me-2 svm-status-icon">&#9675;</span>';
		}
		echo '<li class="list-group-item">' . $icon . htmlspecialchars($step['label']) . ' <small class="text-muted">(' . htmlspecialchars($step['type']) . ')</small></li>';
	}
	echo '</ul></div>';
}
?>

<?php if (in_array($job->get('mjb_status'), ['queued', 'pending', 'running'])): ?>
<?php echo SmAssets::script_tag(); ?>
<script>
(function() {
	var outputEl = document.getElementById('job-output');
	var statusEl = document.getElementById('job-status');
	var progressEl = document.getElementById('job-progress');
	var offset = <?php echo strlen(SmSecretRedactor::redact($job->transcript() ?: '')); ?>;
	var polling = true;

	function poll() {
		if (!polling) return;
		smApiPost('job_status', { job_id: <?php echo (int)$job->key; ?>, output_offset: offset })
			.then(function(data) {
				if (!data.success) return;

				if (data.new_output) {
					if (offset === 0 && outputEl.textContent === 'Waiting for output...') {
						outputEl.textContent = '';
					}
					outputEl.textContent += data.new_output;
					offset = data.output_offset;
					outputEl.scrollTop = outputEl.scrollHeight;
				}

				var totalSteps = <?php echo $total_steps; ?>;
				// Translate 0-based raw index to user-facing completed count.
				var doneSteps;
				if (data.status === 'completed') {
					doneSteps = totalSteps;
				} else {
					doneSteps = data.current_step;
				}
				progressEl.textContent = doneSteps;

				if (data.status !== 'queued' && data.status !== 'pending' && data.status !== 'running') {
					polling = false;
					statusEl.textContent = data.status;
					statusEl.className = 'badge bg-' + (data.status === 'completed' ? 'success' : 'danger');
					<?php if ($deploy_all !== null): ?>
					// The page says what became of the deploy, and loading it starts the rollout.
					if (data.status === 'completed') { window.location.reload(); return; }
					<?php endif; ?>
					<?php if ($then_publish !== null): ?>
					// The page says what became of the publish that follows.
					window.location.reload(); return;
					<?php endif; ?>
					if (data.error_message) {
						var errDiv = document.createElement('div');
						errDiv.className = 'alert alert-danger mt-2';
						errDiv.textContent = 'Error: ' + data.error_message;
						statusEl.parentNode.appendChild(errDiv);
					}
					return;
				}

				setTimeout(poll, 2000);
			})
			.catch(function() {
				setTimeout(poll, 5000);
			});
	}

	setTimeout(poll, 2000);
})();
</script>
<?php endif; ?>

<?php
$page->admin_footer();
?>
