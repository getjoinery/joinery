<?php
/**
 * Server Manager - Database Operations
 * URL: /admin/server_manager/database
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

// Load nodes
$all_nodes = new MultiManagedNode(['deleted' => false, 'enabled' => true], ['mgn_name' => 'ASC']);
$all_nodes->load();
$node_map = [];
foreach ($all_nodes as $n) {
	$node_map[$n->key] = $n;
}

// Handle actions
if ($_POST && isset($_POST['action'])) {
	$page_regex = '/\/admin\/server_manager/';

	if ($_POST['action'] === 'copy_database') {
		$source_id = intval($_POST['source_node_id'] ?? 0);
		$target_id = intval($_POST['target_node_id'] ?? 0);
		$confirm = !empty($_POST['confirm_overwrite']);

		if ($source_id && $target_id && $confirm && isset($node_map[$source_id]) && isset($node_map[$target_id])) {
			if ($source_id === $target_id) {
				$session->save_message(new DisplayMessage(
					'Source and target nodes must be different.',
					'Error', $page_regex,
					DisplayMessage::MESSAGE_ERROR,
					DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
				));
			} else {
				$source_node = $node_map[$source_id];
				$target_node = $node_map[$target_id];
				$params = ['source_node_id' => $source_id, 'target_node_id' => $target_id, 'confirm_overwrite' => true];
				$steps = JobCommandBuilder::build_copy_database($source_node, $target_node, $params);
				$job = ManagementJob::createJob($target_id, 'copy_database', $steps, $params, $session->get_user_id());
				header('Location: /admin/server_manager/job_detail?job_id=' . $job->key);
				exit;
			}
		} elseif (!$confirm) {
			$session->save_message(new DisplayMessage(
				'You must confirm the database overwrite.',
				'Error', $page_regex,
				DisplayMessage::MESSAGE_ERROR,
				DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
		}
	}

	if ($_POST['action'] === 'restore_database') {
		$node_id = intval($_POST['node_id'] ?? 0);
		$backup_path = trim($_POST['backup_path'] ?? '');
		$confirm = !empty($_POST['confirm_overwrite']);

		if ($node_id && $backup_path && $confirm && isset($node_map[$node_id])) {
			$node = $node_map[$node_id];
			$params = ['backup_path' => $backup_path, 'confirm_overwrite' => true];
			$steps = JobCommandBuilder::build_restore_database($node, $params);
			$job = ManagementJob::createJob($node_id, 'restore_database', $steps, $params, $session->get_user_id());
			header('Location: /admin/server_manager/job_detail?job_id=' . $job->key);
			exit;
		} elseif (!$confirm) {
			$session->save_message(new DisplayMessage(
				'You must confirm the database overwrite.',
				'Error', $page_regex,
				DisplayMessage::MESSAGE_ERROR,
				DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
		}
	}

	header('Location: /admin/server_manager/database');
	exit;
}

$page = new AdminPage();
$page->admin_header([
	'menu-id' => 'server-manager',
	'page_title' => 'Database Operations',
	'readable_title' => 'Database Operations',
	'breadcrumbs' => [
		'Server Manager' => '/admin/server_manager',
		'Database' => '',
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
	echo 'You need to <a href="/admin/server_manager/nodes_edit" class="alert-link">add at least one node</a> before you can run database operations. ';
	echo 'Database copy requires two nodes (source and target).';
	echo '</div>';
	$page->admin_footer();
	return;
}

// Copy Database
$pageoptions = ['title' => 'Copy Database Between Nodes'];
$page->begin_box($pageoptions);
?>
<div class="alert alert-warning">
	<strong>Destructive Operation:</strong> Copying a database will overwrite the target database. An automatic backup of the target is taken before overwrite.
</div>
<form method="post">
	<input type="hidden" name="action" value="copy_database">
	<div class="row mb-3">
		<div class="col-md-5">
			<label class="form-label"><strong>Source Node</strong></label>
			<select name="source_node_id" class="form-select" required>
				<option value="">Select source...</option>
				<?php foreach ($all_nodes as $n): ?>
					<option value="<?php echo $n->key; ?>"><?php echo htmlspecialchars($n->get('mgn_name')); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div class="col-md-2 text-center align-self-end">
			<p class="mb-2"><strong>&rarr;</strong></p>
		</div>
		<div class="col-md-5">
			<label class="form-label"><strong>Target Node</strong> (will be overwritten)</label>
			<select name="target_node_id" class="form-select" required>
				<option value="">Select target...</option>
				<?php foreach ($all_nodes as $n): ?>
					<option value="<?php echo $n->key; ?>"><?php echo htmlspecialchars($n->get('mgn_name')); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
	</div>
	<div class="mb-3 form-check">
		<input type="checkbox" name="confirm_overwrite" class="form-check-input" id="copy_confirm" value="1">
		<label class="form-check-label" for="copy_confirm"><strong>I confirm I want to overwrite the target database</strong></label>
	</div>
	<button type="submit" class="btn btn-danger" onclick="return confirm('Are you sure? This will overwrite the target database.')">Copy Database</button>
</form>
<?php
$page->end_box();

// Restore Database
$pageoptions = ['title' => 'Restore Database from Backup'];
$page->begin_box($pageoptions);
?>
<div class="alert alert-warning">
	<strong>Destructive Operation:</strong> Restoring will overwrite the current database. An automatic backup is taken before restore.
</div>
<form method="post">
	<input type="hidden" name="action" value="restore_database">
	<div class="row mb-3">
		<div class="col-md-6">
			<label class="form-label">Node</label>
			<select name="node_id" class="form-select" required>
				<option value="">Select node...</option>
				<?php foreach ($all_nodes as $n): ?>
					<option value="<?php echo $n->key; ?>"><?php echo htmlspecialchars($n->get('mgn_name')); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div class="col-md-6">
			<label class="form-label">Backup file path (on the server)</label>
			<input type="text" name="backup_path" class="form-control" placeholder="/backups/backup_20260410.sql.gz" required>
		</div>
	</div>
	<div class="mb-3 form-check">
		<input type="checkbox" name="confirm_overwrite" class="form-check-input" id="restore_confirm" value="1">
		<label class="form-check-label" for="restore_confirm"><strong>I confirm I want to overwrite the database</strong></label>
	</div>
	<button type="submit" class="btn btn-danger" onclick="return confirm('Are you sure? This will overwrite the database.')">Restore Database</button>
</form>
<?php
$page->end_box();

// Recent database operation jobs
$db_jobs = new MultiManagementJob(['deleted' => false], ['mjb_id' => 'DESC'], 20);
$db_jobs->load();

$pageoptions = ['title' => 'Recent Database Operations'];
$page->begin_box($pageoptions);
?>
<table class="table table-striped table-sm">
	<thead><tr><th>ID</th><th>Node</th><th>Type</th><th>Status</th><th>Created</th></tr></thead>
	<tbody>
		<?php
		$count = 0;
		foreach ($db_jobs as $job):
			if (!in_array($job->get('mjb_job_type'), ['copy_database', 'restore_database'])) continue;
			$count++;
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
		<?php if ($count === 0): ?>
			<tr><td colspan="5" class="text-muted text-center">No database operations yet</td></tr>
		<?php endif; ?>
	</tbody>
</table>
<?php
$page->end_box();
$page->admin_footer();
?>
