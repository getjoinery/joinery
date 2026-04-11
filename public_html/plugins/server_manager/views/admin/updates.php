<?php
/**
 * Server Manager - Updates
 * URL: /admin/server_manager/updates
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

// Get local version
$local_version = '-';
$version_file = PathHelper::getIncludePath('includes/version.php');
if (file_exists($version_file)) {
	$version_contents = file_get_contents($version_file);
	if (preg_match("/VERSION\s*=\s*['\"]?([^'\";\s]+)/", $version_contents, $m)) {
		$local_version = $m[1];
	}
}

// Handle actions
if ($_POST && isset($_POST['action'])) {
	$page_regex = '/\/admin\/server_manager/';

	if ($_POST['action'] === 'publish_upgrade') {
		$release_notes = trim($_POST['release_notes'] ?? '');
		if ($release_notes) {
			$params = ['release_notes' => $release_notes];
			$steps = JobCommandBuilder::build_publish_upgrade($params);
			$job = ManagementJob::createJob(null, 'publish_upgrade', $steps, $params, $session->get_user_id());
			header('Location: /admin/server_manager/job_detail?job_id=' . $job->key);
			exit;
		}
	}

	if ($_POST['action'] === 'apply_update') {
		$node_id = intval($_POST['node_id'] ?? 0);
		if ($node_id && isset($node_map[$node_id])) {
			$node = $node_map[$node_id];
			$params = ['dry_run' => !empty($_POST['dry_run'])];
			$steps = JobCommandBuilder::build_apply_update($node, $params);
			$job = ManagementJob::createJob($node_id, 'apply_update', $steps, $params, $session->get_user_id());
			header('Location: /admin/server_manager/job_detail?job_id=' . $job->key);
			exit;
		}
	}

	if ($_POST['action'] === 'refresh_archives') {
		$node_id = intval($_POST['node_id'] ?? 0);
		if ($node_id && isset($node_map[$node_id])) {
			$node = $node_map[$node_id];
			$steps = JobCommandBuilder::build_refresh_archives($node);
			$job = ManagementJob::createJob($node_id, 'refresh_archives', $steps, null, $session->get_user_id());
			header('Location: /admin/server_manager/job_detail?job_id=' . $job->key);
			exit;
		}
	}

	header('Location: /admin/server_manager/updates');
	exit;
}

$page = new AdminPage();
$page->admin_header([
	'menu-id' => 'server-manager',
	'page_title' => 'Updates',
	'readable_title' => 'Updates',
	'breadcrumbs' => [
		'Server Manager' => '/admin/server_manager',
		'Updates' => '',
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
	echo '<a href="/admin/server_manager/nodes_edit" class="alert-link">Add a node</a> to see version status and apply updates. ';
	echo 'You can still use "Publish New Upgrade" below to build upgrade archives from the control plane source.';
	echo '</div>';
}

// Version Comparison
$pageoptions = ['title' => 'Version Status'];
$page->begin_box($pageoptions);
?>
<?php if (count($all_nodes) > 0): ?>
<table class="table table-sm">
	<thead><tr><th>Node</th><th>Current Version</th><th>Control Plane Version</th><th>Status</th><th>Actions</th></tr></thead>
	<tbody>
		<?php foreach ($all_nodes as $node): ?>
			<?php
			$node_version = $node->get('mgn_joinery_version') ?: 'Unknown';
			$up_to_date = ($node_version === $local_version);
			?>
			<tr>
				<td><?php echo htmlspecialchars($node->get('mgn_name')); ?></td>
				<td><?php echo htmlspecialchars($node_version); ?></td>
				<td><?php echo htmlspecialchars($local_version); ?></td>
				<td>
					<?php if ($up_to_date): ?>
						<span class="badge bg-success">Up to date</span>
					<?php else: ?>
						<span class="badge bg-warning">Update available</span>
					<?php endif; ?>
				</td>
				<td>
					<form method="post" style="display:inline">
						<input type="hidden" name="action" value="apply_update">
						<input type="hidden" name="node_id" value="<?php echo $node->key; ?>">
						<button type="submit" class="btn btn-sm btn-outline-primary" onclick="return confirm('Apply update to <?php echo htmlspecialchars($node->get('mgn_name')); ?>?')">Apply Update</button>
					</form>
					<form method="post" style="display:inline">
						<input type="hidden" name="action" value="apply_update">
						<input type="hidden" name="node_id" value="<?php echo $node->key; ?>">
						<input type="hidden" name="dry_run" value="1">
						<button type="submit" class="btn btn-sm btn-outline-secondary">Dry Run</button>
					</form>
					<form method="post" style="display:inline">
						<input type="hidden" name="action" value="refresh_archives">
						<input type="hidden" name="node_id" value="<?php echo $node->key; ?>">
						<button type="submit" class="btn btn-sm btn-outline-secondary" onclick="return confirm('Refresh archives and apply to <?php echo htmlspecialchars($node->get('mgn_name')); ?>?')">Refresh &amp; Apply</button>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
<?php endif; ?>
<?php
$page->end_box();

// Publish New Upgrade
$pageoptions = ['title' => 'Publish New Upgrade'];
$page->begin_box($pageoptions);
?>
<p>Build upgrade archives from the current control plane source code.</p>
<form method="post">
	<input type="hidden" name="action" value="publish_upgrade">
	<div class="mb-3">
		<label class="form-label">Release Notes</label>
		<textarea name="release_notes" class="form-control" rows="3" placeholder="Describe what changed in this release..." required></textarea>
	</div>
	<button type="submit" class="btn btn-primary">Publish Upgrade</button>
</form>
<?php
$page->end_box();
$page->admin_footer();
?>
