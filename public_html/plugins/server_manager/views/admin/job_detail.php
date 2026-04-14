<?php
/**
 * Server Manager - Job Detail
 * URL: /admin/server_manager/job_detail
 *
 * Shows job output with live polling for running jobs.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobResultProcessor.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);
$session->set_return();

$job_id = isset($_GET['job_id']) ? intval($_GET['job_id']) : 0;
if (!$job_id) {
	header('Location: /admin/server_manager/jobs');
	exit;
}

$job = new ManagementJob($job_id, TRUE);

// Handle cancel action
if (isset($_GET['action']) && $_GET['action'] === 'cancel' && $job->get('mjb_status') === 'pending') {
	$job->set('mjb_status', 'cancelled');
	$job->set('mjb_completed_time', gmdate('Y-m-d H:i:s'));
	$job->save();
	header('Location: /admin/server_manager/job_detail?job_id=' . $job_id);
	exit;
}

// Handle re-run action
if (isset($_GET['action']) && $_GET['action'] === 'rerun') {
	$new_job = ManagementJob::createJob(
		$job->get('mjb_mgn_node_id'),
		$job->get('mjb_job_type'),
		json_decode($job->get('mjb_commands'), true)['steps'] ?? [],
		$job->get('mjb_parameters') ? json_decode($job->get('mjb_parameters'), true) : null,
		$session->get_user_id()
	);
	header('Location: /admin/server_manager/job_detail?job_id=' . $new_job->key);
	exit;
}

// Process result if completed but not yet processed
if ($job->get('mjb_status') === 'completed' && !$job->get('mjb_result')) {
	JobResultProcessor::process($job);
	$job->load();
}

// Load node name
$node_name = 'Local';
$node_id = $job->get('mjb_mgn_node_id');
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
			$node_name => '/admin/server_manager/node_detail?mgn_id=' . $node_id,
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
		echo '<div class="alert ' . $alert_class . ' alert-dismissible fade show">';
		echo htmlspecialchars($msg->message);
		echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
	}
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
				<p><strong>Created:</strong> <?php echo LibraryFunctions::convert_time($job->get('mjb_create_time'), 'UTC', $session->get_timezone(), 'M j, Y g:i:s A'); ?></p>
				<?php if ($job->get('mjb_started_time')): ?>
					<p><strong>Started:</strong> <?php echo LibraryFunctions::convert_time($job->get('mjb_started_time'), 'UTC', $session->get_timezone(), 'M j, Y g:i:s A'); ?></p>
				<?php endif; ?>
				<?php if ($job->get('mjb_completed_time')): ?>
					<p><strong>Completed:</strong> <?php echo LibraryFunctions::convert_time($job->get('mjb_completed_time'), 'UTC', $session->get_timezone(), 'M j, Y g:i:s A'); ?></p>
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

		if ($job->get('mjb_status') === 'running') {
			$running_seconds = time() - strtotime($job->get('mjb_started_time'));
			if ($running_seconds > 3600): ?>
				<div class="alert alert-warning mt-2">
					<strong>This job has been running for <?php echo round($running_seconds / 3600, 1); ?> hours.</strong>
					It may be stuck. Check agent logs: <code>journalctl -u joinery-agent -f</code>
				</div>
			<?php endif;
		}
		?>

		<div class="mt-2">
			<?php if ($job->get('mjb_status') === 'pending'): ?>
				<a href="/admin/server_manager/job_detail?job_id=<?php echo $job->key; ?>&action=cancel" class="btn btn-sm btn-warning" onclick="return confirm('Cancel this job?')">Cancel</a>
			<?php endif; ?>
			<?php if (in_array($job->get('mjb_status'), ['completed', 'failed', 'cancelled'])): ?>
				<a href="/admin/server_manager/job_detail?job_id=<?php echo $job->key; ?>&action=rerun" class="btn btn-sm btn-outline-primary">Re-run</a>
			<?php endif; ?>
		</div>
	</div>
</div>

<!-- Output -->
<div class="card mb-3">
	<div class="card-header"><strong>Output</strong></div>
	<div class="card-body">
		<pre id="job-output" style="max-height: 600px; overflow-y: auto; background: #1e1e1e; color: #d4d4d4; padding: 1rem; border-radius: 4px; font-size: 13px; white-space: pre-wrap;"><?php echo htmlspecialchars($job->get('mjb_output') ?: 'Waiting for output...'); ?></pre>
	</div>
</div>

<?php
// Show structured result if available
$result = $job->get('mjb_result');
if ($result) {
	$result_data = is_string($result) ? json_decode($result, true) : $result;
	if ($result_data) {
		echo '<div class="card mb-3"><div class="card-header"><strong>Structured Result</strong></div><div class="card-body">';
		echo '<pre>' . htmlspecialchars(json_encode($result_data, JSON_PRETTY_PRINT)) . '</pre>';
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
	$icon_style = 'display:inline-block;width:1.25em;text-align:center;';
	foreach ($commands_data['steps'] as $i => $step) {
		if ($status === 'completed') {
			$icon = '<span class="text-success me-2" style="' . $icon_style . '">&#10003;</span>';
		} elseif ($i < $raw_current) {
			$icon = '<span class="text-success me-2" style="' . $icon_style . '">&#10003;</span>';
		} elseif ($i === $raw_current) {
			if ($status === 'failed') {
				$icon = '<span class="text-danger me-2" style="' . $icon_style . '">&#10007;</span>';
			} elseif ($status === 'running') {
				$icon = '<span class="text-primary me-2" style="' . $icon_style . '">&#9654;</span>';
			} elseif ($status === 'cancelled') {
				$icon = '<span class="text-secondary me-2" style="' . $icon_style . '">&#9633;</span>';
			} else {
				$icon = '<span class="text-muted me-2" style="' . $icon_style . '">&#9675;</span>';
			}
		} else {
			$icon = '<span class="text-muted me-2" style="' . $icon_style . '">&#9675;</span>';
		}
		echo '<li class="list-group-item">' . $icon . htmlspecialchars($step['label']) . ' <small class="text-muted">(' . $step['type'] . ')</small></li>';
	}
	echo '</ul></div>';
}
?>

<?php if (in_array($job->get('mjb_status'), ['pending', 'running'])): ?>
<script>
(function() {
	var outputEl = document.getElementById('job-output');
	var statusEl = document.getElementById('job-status');
	var progressEl = document.getElementById('job-progress');
	var offset = <?php echo strlen($job->get('mjb_output') ?: ''); ?>;
	var polling = true;

	function poll() {
		if (!polling) return;
		fetch('/ajax/job_status?job_id=<?php echo $job->key; ?>&output_offset=' + offset)
			.then(function(r) { return r.json(); })
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

				if (data.status !== 'pending' && data.status !== 'running') {
					polling = false;
					statusEl.textContent = data.status;
					statusEl.className = 'badge bg-' + (data.status === 'completed' ? 'success' : 'danger');
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
