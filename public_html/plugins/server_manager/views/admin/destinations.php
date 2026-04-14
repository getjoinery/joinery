<?php
/**
 * Server Manager - Backup Destinations
 * URL: /admin/server_manager/destinations
 *
 * CRUD page for managing backup storage destinations (B2, S3, Linode, local).
 *
 * @version 1.1
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/backup_destination_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/DestinationTester.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);
$session->set_return();

// Load or create destination
$dest = null;
$is_edit = false;
if (isset($_GET['bkd_id']) && $_GET['bkd_id']) {
	$dest = new BackupDestination(intval($_GET['bkd_id']), TRUE);
	$is_edit = true;
} elseif (isset($_GET['action']) && $_GET['action'] === 'add') {
	$dest = new BackupDestination(NULL);
}

// Handle test (from list row)
if (isset($_GET['action']) && $_GET['action'] === 'test' && $is_edit) {
	$result = DestinationTester::test($dest);
	$page_regex = '/\/admin\/server_manager/';
	$session->save_message(new DisplayMessage(
		'Test "' . $dest->get('bkd_name') . '": ' . $result['message'],
		$result['success'] ? 'Success' : 'Error',
		$page_regex,
		$result['success'] ? DisplayMessage::MESSAGE_ANNOUNCEMENT : DisplayMessage::MESSAGE_ERROR,
		DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
	));
	header('Location: /admin/server_manager/destinations');
	exit;
}

// Handle delete
if (isset($_GET['action']) && $_GET['action'] === 'delete' && $is_edit) {
	$dest->soft_delete();
	$page_regex = '/\/admin\/server_manager/';
	$session->save_message(new DisplayMessage(
		'Destination deleted.', 'Success', $page_regex,
		DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
	));
	header('Location: /admin/server_manager/destinations');
	exit;
}

// Handle form save
$error = null;
if ($_POST && isset($_POST['bkd_name'])) {
	if (!$dest) {
		$dest = new BackupDestination(NULL);
	}

	$dest->set('bkd_name', trim($_POST['bkd_name'] ?? ''));
	$dest->set('bkd_provider', trim($_POST['bkd_provider'] ?? 'b2'));
	$dest->set('bkd_bucket', trim($_POST['bkd_bucket'] ?? ''));
	$dest->set('bkd_path_prefix', trim($_POST['bkd_path_prefix'] ?? 'joinery-backups'));
	$dest->set('bkd_delete_local', !empty($_POST['bkd_delete_local']));
	$dest->set('bkd_enabled', isset($_POST['bkd_enabled']) ? true : false);

	// Build credentials JSON from provider-specific fields
	$provider = $dest->get('bkd_provider');
	$creds = [];
	if ($provider === 'b2') {
		$creds = [
			'key_id' => trim($_POST['cred_key_id'] ?? ''),
			'app_key' => trim($_POST['cred_app_key'] ?? ''),
		];
	} elseif ($provider === 's3' || $provider === 'linode') {
		$creds = [
			'access_key' => trim($_POST['cred_access_key'] ?? ''),
			'secret_key' => trim($_POST['cred_secret_key'] ?? ''),
			'region' => trim($_POST['cred_region'] ?? 'us-east-1'),
		];
		if ($provider === 'linode') {
			$creds['endpoint'] = trim($_POST['cred_endpoint'] ?? '');
		}
	}
	$dest->set('bkd_credentials', json_encode($creds));

	if (!isset($_POST['bkd_enabled'])) {
		$dest->set('bkd_enabled', false);
	}

	try {
		$dest->prepare();
		$dest->save();
		$dest->load();

		$test_result = DestinationTester::test($dest);
		$page_regex = '/\/admin\/server_manager/';
		if ($test_result['success']) {
			$session->save_message(new DisplayMessage(
				'Destination saved. ' . $test_result['message'],
				'Success', $page_regex,
				DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
		} else {
			$session->save_message(new DisplayMessage(
				'Destination saved, but connection test failed: ' . $test_result['message'],
				'Warning', $page_regex,
				DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
		}
		header('Location: /admin/server_manager/destinations?bkd_id=' . $dest->key);
		exit;
	} catch (Exception $e) {
		$error = $e->getMessage();
		$is_edit = $dest->key ? true : false;
	}
}

// Load all destinations for listing
$all_destinations = new MultiBackupDestination(['deleted' => false], ['bkd_name' => 'ASC']);
$all_destinations->load();

$page = new AdminPage();
$page->admin_header([
	'menu-id' => 'server-manager',
	'page_title' => 'Backup Destinations',
	'readable_title' => 'Backup Destinations',
	'breadcrumbs' => [
		'Server Manager' => '/admin/server_manager',
		'Destinations' => '',
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

if ($error) {
	echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
}

// ── Destination List ──
$provider_labels = ['b2' => 'Backblaze B2', 's3' => 'Amazon S3', 'linode' => 'Linode Object Storage'];

$pageoptions = ['title' => 'Backup Destinations', 'altlinks' => ['Add Destination' => '/admin/server_manager/destinations?action=add']];
$page->begin_box($pageoptions);

echo '<table class="table table-striped table-sm">';
echo '<thead><tr><th>Name</th><th>Provider</th><th>Bucket</th><th>Path Prefix</th><th>Delete Local</th><th>Status</th><th>Actions</th></tr></thead>';
echo '<tbody>';

$dest_count = 0;
foreach ($all_destinations as $d) {
	$dest_count++;
	$prov = $d->get('bkd_provider');
	$prov_label = $provider_labels[$prov] ?? $prov;
	$enabled = $d->get('bkd_enabled');
	echo '<tr>';
	echo '<td><a href="/admin/server_manager/destinations?bkd_id=' . $d->key . '">' . htmlspecialchars($d->get('bkd_name')) . '</a></td>';
	echo '<td>' . htmlspecialchars($prov_label) . '</td>';
	echo '<td>' . htmlspecialchars($d->get('bkd_bucket') ?: '-') . '</td>';
	echo '<td>' . htmlspecialchars($d->get('bkd_path_prefix') ?: '-') . '</td>';
	echo '<td>' . ($d->get('bkd_delete_local') ? 'Yes' : 'No') . '</td>';
	echo '<td><span class="badge bg-' . ($enabled ? 'success' : 'secondary') . '">' . ($enabled ? 'Enabled' : 'Disabled') . '</span></td>';
	echo '<td><a href="/admin/server_manager/destinations?bkd_id=' . $d->key . '" class="btn btn-sm btn-outline-primary">Edit</a> ';
	echo '<a href="/admin/server_manager/destinations?bkd_id=' . $d->key . '&action=test" class="btn btn-sm btn-outline-secondary">Test</a></td>';
	echo '</tr>';
}

if ($dest_count === 0) {
	echo '<tr><td colspan="7" class="text-muted text-center">No backup destinations configured. Backups are stored locally on each node.</td></tr>';
}

echo '</tbody></table>';
$page->end_box();

// ── Add/Edit Form ──
if ($dest !== null) {
	$creds = $dest->key ? $dest->get_credentials() : [];
	$current_provider = $dest->get('bkd_provider') ?: 'b2';

	$form_title = $is_edit ? 'Edit Destination: ' . htmlspecialchars($dest->get('bkd_name')) : 'Add Destination';
	$pageoptions = ['title' => $form_title];
	$page->begin_box($pageoptions);
?>
	<form method="post">
		<?php if ($is_edit): ?>
			<input type="hidden" name="edit_primary_key_value" value="<?php echo $dest->key; ?>">
		<?php endif; ?>

		<div class="row mb-3">
			<div class="col-md-6">
				<label class="form-label">Name *</label>
				<input type="text" name="bkd_name" class="form-control" value="<?php echo htmlspecialchars($dest->get('bkd_name') ?: ''); ?>" required placeholder="e.g., Production B2">
			</div>
			<div class="col-md-6">
				<label class="form-label">Provider *</label>
				<select name="bkd_provider" id="providerSelect" class="form-select" onchange="toggleProviderFields()">
					<?php foreach ($provider_labels as $key => $label): ?>
						<option value="<?php echo $key; ?>" <?php echo $current_provider === $key ? 'selected' : ''; ?>><?php echo $label; ?></option>
					<?php endforeach; ?>
				</select>
			</div>
		</div>

		<div id="cloudFields">
			<div class="row mb-3">
				<div class="col-md-6">
					<label class="form-label">Bucket Name *</label>
					<input type="text" name="bkd_bucket" class="form-control" value="<?php echo htmlspecialchars($dest->get('bkd_bucket') ?: ''); ?>" placeholder="my-backup-bucket">
				</div>
				<div class="col-md-6">
					<label class="form-label">Path Prefix</label>
					<input type="text" name="bkd_path_prefix" class="form-control" value="<?php echo htmlspecialchars($dest->get('bkd_path_prefix') ?: 'joinery-backups'); ?>" placeholder="joinery-backups">
					<small class="text-muted">Files stored at: bucket/prefix/node-slug/filename</small>
				</div>
			</div>

			<!-- B2 Credentials -->
			<div id="b2Fields" style="<?php echo $current_provider === 'b2' ? '' : 'display:none'; ?>">
				<h6 class="text-muted mb-3">Backblaze B2 Credentials</h6>
				<div class="row mb-3">
					<div class="col-md-6">
						<label class="form-label">Application Key ID</label>
						<input type="text" name="cred_key_id" class="form-control" value="<?php echo htmlspecialchars($creds['key_id'] ?? ''); ?>">
					</div>
					<div class="col-md-6">
						<label class="form-label">Application Key</label>
						<input type="password" name="cred_app_key" class="form-control" value="<?php echo htmlspecialchars($creds['app_key'] ?? ''); ?>">
					</div>
				</div>
			</div>

			<!-- S3 Credentials -->
			<div id="s3Fields" style="<?php echo $current_provider === 's3' ? '' : 'display:none'; ?>">
				<h6 class="text-muted mb-3">Amazon S3 Credentials</h6>
				<div class="row mb-3">
					<div class="col-md-4">
						<label class="form-label">Access Key</label>
						<input type="text" name="cred_access_key" class="form-control" value="<?php echo htmlspecialchars($creds['access_key'] ?? ''); ?>">
					</div>
					<div class="col-md-4">
						<label class="form-label">Secret Key</label>
						<input type="password" name="cred_secret_key" class="form-control" value="<?php echo htmlspecialchars($creds['secret_key'] ?? ''); ?>">
					</div>
					<div class="col-md-4">
						<label class="form-label">Region</label>
						<input type="text" name="cred_region" class="form-control" value="<?php echo htmlspecialchars($creds['region'] ?? 'us-east-1'); ?>" placeholder="us-east-1">
					</div>
				</div>
			</div>

			<!-- Linode Credentials -->
			<div id="linodeFields" style="<?php echo $current_provider === 'linode' ? '' : 'display:none'; ?>">
				<h6 class="text-muted mb-3">Linode Object Storage Credentials</h6>
				<div class="row mb-3">
					<div class="col-md-3">
						<label class="form-label">Access Key</label>
						<input type="text" name="cred_access_key" class="form-control" value="<?php echo htmlspecialchars($creds['access_key'] ?? ''); ?>">
					</div>
					<div class="col-md-3">
						<label class="form-label">Secret Key</label>
						<input type="password" name="cred_secret_key" class="form-control" value="<?php echo htmlspecialchars($creds['secret_key'] ?? ''); ?>">
					</div>
					<div class="col-md-3">
						<label class="form-label">Region</label>
						<input type="text" name="cred_region" class="form-control" value="<?php echo htmlspecialchars($creds['region'] ?? ''); ?>" placeholder="us-east-1">
					</div>
					<div class="col-md-3">
						<label class="form-label">Endpoint URL</label>
						<input type="text" name="cred_endpoint" class="form-control" value="<?php echo htmlspecialchars($creds['endpoint'] ?? ''); ?>" placeholder="https://us-east-1.linodeobjects.com">
					</div>
				</div>
			</div>
		</div>

		<div class="mb-3 form-check">
			<input type="checkbox" name="bkd_delete_local" class="form-check-input" id="deleteLocal" value="1" <?php echo $dest->get('bkd_delete_local') ? 'checked' : ''; ?>>
			<label class="form-check-label" for="deleteLocal">Delete local backup file after successful upload</label>
		</div>

		<div class="mb-3 form-check">
			<input type="checkbox" name="bkd_enabled" class="form-check-input" id="destEnabled" value="1" <?php echo ($dest->key ? $dest->get('bkd_enabled') : true) ? 'checked' : ''; ?>>
			<label class="form-check-label" for="destEnabled">Enabled</label>
		</div>

		<button type="submit" class="btn btn-primary"><?php echo $is_edit ? 'Save Changes' : 'Add Destination'; ?></button>
		<a href="/admin/server_manager/destinations" class="btn btn-outline-secondary ms-2">Cancel</a>

		<?php if ($is_edit): ?>
			<a href="/admin/server_manager/destinations?bkd_id=<?php echo $dest->key; ?>&action=delete" class="btn btn-outline-danger ms-2" onclick="return confirm('Delete this destination?')">Delete</a>
		<?php endif; ?>
	</form>

	<script>
	function toggleProviderFields() {
		var provider = document.getElementById('providerSelect').value;
		document.getElementById('b2Fields').style.display = provider === 'b2' ? '' : 'none';
		document.getElementById('s3Fields').style.display = provider === 's3' ? '' : 'none';
		document.getElementById('linodeFields').style.display = provider === 'linode' ? '' : 'none';
	}
	</script>

<?php
	$page->end_box();
}

$page->admin_footer();
?>
