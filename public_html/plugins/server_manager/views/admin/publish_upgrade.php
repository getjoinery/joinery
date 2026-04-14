<?php
/**
 * Server Manager — Upgrades
 * URL: /admin/server_manager/publish_upgrade
 *
 * Lists published upgrade archives (with delete), and provides the
 * Publish New Upgrade form with optional version override.
 *
 * @version 1.2
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/upgrades_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);
$session->set_return();

$page_regex = '/\/admin\/server_manager/';
$static_dir = dirname(PathHelper::getIncludePath('')) . '/static_files';
// getIncludePath('') returns public_html/; its dirname is the site root
$archive_dir = rtrim($static_dir, '/');

// ── Delete upgrade ──
if ($_POST && ($_POST['action'] ?? '') === 'delete_upgrade') {
	$delete_id = intval($_POST['upgrade_id'] ?? 0);
	if ($delete_id) {
		try {
			$u = new Upgrade($delete_id, TRUE);
			if ($u->key) {
				$archive_filename = $u->get('upg_name');
				$archive_path = $archive_dir . '/' . $archive_filename;
				if (file_exists($archive_path)) {
					@unlink($archive_path);
				}
				$version_string = $u->get('upg_major_version') . '.' . $u->get('upg_minor_version') . '.' . $u->get('upg_patch_version');
				$u->permanent_delete();
				$session->save_message(new DisplayMessage(
					"Upgrade $version_string deleted.", 'Success', $page_regex,
					DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
				));
			}
		} catch (Exception $e) {
			$session->save_message(new DisplayMessage(
				'Delete failed: ' . $e->getMessage(), 'Error', $page_regex,
				DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
		}
	}
	header('Location: /admin/server_manager/publish_upgrade');
	exit;
}

// ── Publish upgrade ──
if ($_POST && ($_POST['action'] ?? '') === 'publish_upgrade') {
	$release_notes = trim($_POST['release_notes'] ?? '');
	$params = ['release_notes' => $release_notes];
	if (isset($_POST['version_major'], $_POST['version_minor'], $_POST['version_patch'])
		&& $_POST['version_major'] !== '' && $_POST['version_minor'] !== '' && $_POST['version_patch'] !== '') {
		$params['major'] = intval($_POST['version_major']);
		$params['minor'] = intval($_POST['version_minor']);
		$params['patch'] = intval($_POST['version_patch']);
	}
	if ($release_notes) {
		$steps = JobCommandBuilder::build_publish_upgrade($params);
		$job = ManagementJob::createJob(null, 'publish_upgrade', $steps, $params, $session->get_user_id());
		header('Location: /admin/server_manager/job_detail?job_id=' . $job->key);
		exit;
	}
}

// ── Load upgrade history ──
$upgrades = new MultiUpgrade([], ['upgrade_id' => 'DESC'], 50);
$upgrades->load();

// Auto-detect next version
$current = LibraryFunctions::get_joinery_version();
if ($current !== '' && preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $current, $m)) {
	$next_major = (int)$m[1];
	$next_minor = (int)$m[2];
	$next_patch = (int)$m[3] + 1;
} elseif ($upgrades->count() > 0) {
	$last = $upgrades->get(0);
	$next_major = (int)$last->get('upg_major_version');
	$next_minor = (int)$last->get('upg_minor_version');
	$next_patch = (int)$last->get('upg_patch_version') + 1;
} else {
	$next_major = 0;
	$next_minor = 8;
	$next_patch = 1;
}

$page = new AdminPage();
$page->admin_header([
	'menu-id' => 'server-manager',
	'page_title' => 'Upgrades',
	'readable_title' => 'Upgrades',
	'breadcrumbs' => [
		'Server Manager' => '/admin/server_manager',
		'Upgrades' => '',
	],
	'session' => $session,
]);

// Flash messages
$display_messages = $session->get_messages('/admin/server_manager');
foreach ($display_messages as $msg) {
	$alert_class = 'alert-info';
	if ($msg->display_type == DisplayMessage::MESSAGE_ERROR) $alert_class = 'alert-danger';
	elseif ($msg->display_type == DisplayMessage::MESSAGE_ANNOUNCEMENT) $alert_class = 'alert-success';
	echo '<div class="alert ' . $alert_class . ' alert-dismissible fade show" role="alert">';
	if ($msg->message_title) echo '<strong>' . htmlspecialchars($msg->message_title) . ':</strong> ';
	echo htmlspecialchars($msg->message);
	echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
}
$session->clear_clearable_messages();

// ── Upgrade History ──
$pageoptions = ['title' => 'Upgrade History'];
$page->begin_box($pageoptions);
?>
<?php if ($upgrades->count() === 0): ?>
	<p class="text-muted mb-0">No upgrades published yet.</p>
<?php else: ?>
	<table class="table table-sm mb-0">
		<thead>
			<tr>
				<th>Version</th>
				<th>Published</th>
				<th>Archive</th>
				<th>Release Notes</th>
				<th></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($upgrades as $u): ?>
				<?php
				$version = $u->get('upg_major_version') . '.' . $u->get('upg_minor_version') . '.' . $u->get('upg_patch_version');
				$archive_filename = $u->get('upg_name');
				$archive_path = $archive_dir . '/' . $archive_filename;
				$archive_exists = file_exists($archive_path);
				$archive_size = '';
				if ($archive_exists) {
					$bytes = filesize($archive_path);
					if ($bytes >= 1073741824) $archive_size = round($bytes / 1073741824, 1) . 'G';
					elseif ($bytes >= 1048576) $archive_size = round($bytes / 1048576, 1) . 'M';
					elseif ($bytes >= 1024) $archive_size = round($bytes / 1024, 1) . 'K';
					else $archive_size = $bytes . 'B';
				}
				?>
				<tr>
					<td><strong><?php echo htmlspecialchars($version); ?></strong></td>
					<td><small><?php echo LibraryFunctions::convert_time($u->get('upg_create_time'), 'UTC', $session->get_timezone(), 'M j, Y g:i A'); ?></small></td>
					<td>
						<?php if ($archive_exists): ?>
							<small class="text-muted"><?php echo htmlspecialchars($archive_filename); ?><?php echo $archive_size ? ' (' . htmlspecialchars($archive_size) . ')' : ''; ?></small>
						<?php else: ?>
							<span class="badge bg-danger">missing</span>
						<?php endif; ?>
					</td>
					<td><small><?php echo nl2br(htmlspecialchars($u->get('upg_release_notes') ?? '')); ?></small></td>
					<td class="text-end">
						<form method="post" style="display:inline" onsubmit="return confirm('Delete upgrade <?php echo htmlspecialchars($version); ?>? This removes both the archive file and the database record.');">
							<input type="hidden" name="action" value="delete_upgrade">
							<input type="hidden" name="upgrade_id" value="<?php echo $u->key; ?>">
							<button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>
<?php
$page->end_box();

// ── Publish Form ──
$pageoptions = ['title' => 'Publish New Upgrade'];
$page->begin_box($pageoptions);
?>
<p class="text-muted">Build upgrade archives from the current control plane source code. The version numbers default to the auto-detected next patch; override if you need a specific version.</p>
<form method="post">
	<input type="hidden" name="action" value="publish_upgrade">
	<div class="row">
		<div class="col-auto">
			<label class="form-label">Major</label>
			<input type="number" name="version_major" class="form-control" value="<?php echo $next_major; ?>" min="0" required>
		</div>
		<div class="col-auto">
			<label class="form-label">Minor</label>
			<input type="number" name="version_minor" class="form-control" value="<?php echo $next_minor; ?>" min="0" required>
		</div>
		<div class="col-auto">
			<label class="form-label">Patch</label>
			<input type="number" name="version_patch" class="form-control" value="<?php echo $next_patch; ?>" min="0" required>
		</div>
	</div>
	<div class="mb-3 mt-3">
		<label class="form-label">Release notes</label>
		<textarea name="release_notes" class="form-control" rows="4" placeholder="Describe what changed in this release..." required></textarea>
	</div>
	<button type="submit" class="btn btn-primary">Publish Upgrade</button>
	<a href="/admin/server_manager" class="btn btn-link">Cancel</a>
</form>
<?php
$page->end_box();
$page->admin_footer();
?>
