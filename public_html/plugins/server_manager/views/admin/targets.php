<?php
/**
 * Server Manager - Backup Targets
 * URL: /admin/server_manager/targets
 *
 * CRUD page for managing backup storage targets (B2, S3, Linode).
 *
 * @version 2.0
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/backup_target_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/TargetTester.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);
$session->set_return();

// Load or create target
$target = null;
$is_edit = false;
if (isset($_GET['bkt_id']) && $_GET['bkt_id']) {
	$target = new BackupTarget(intval($_GET['bkt_id']), TRUE);
	$is_edit = true;
} elseif (isset($_GET['action']) && $_GET['action'] === 'add') {
	$target = new BackupTarget(NULL);
}

// Handle test (from list row)
if (isset($_GET['action']) && $_GET['action'] === 'test' && $is_edit) {
	$result = TargetTester::test($target);
	$page_regex = '/\/admin\/server_manager/';
	$session->save_message(new DisplayMessage(
		'Test "' . $target->get('bkt_name') . '": ' . $result['message'],
		$result['success'] ? 'Success' : 'Error',
		$page_regex,
		$result['success'] ? DisplayMessage::MESSAGE_ANNOUNCEMENT : DisplayMessage::MESSAGE_ERROR,
		DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
	));
	header('Location: /admin/server_manager/targets');
	exit;
}

// Handle delete
if (isset($_GET['action']) && $_GET['action'] === 'delete' && $is_edit) {
	$target->soft_delete();
	$page_regex = '/\/admin\/server_manager/';
	$session->save_message(new DisplayMessage(
		'Target deleted.', 'Success', $page_regex,
		DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
	));
	header('Location: /admin/server_manager/targets');
	exit;
}

// Handle form save
$error = null;
if ($_POST && isset($_POST['bkt_name'])) {
	if (!$target) {
		$target = new BackupTarget(NULL);
	}

	$target->set('bkt_name', trim($_POST['bkt_name'] ?? ''));
	$target->set('bkt_provider', trim($_POST['bkt_provider'] ?? 'b2'));
	$target->set('bkt_bucket', trim($_POST['bkt_bucket'] ?? ''));
	$target->set('bkt_path_prefix', trim($_POST['bkt_path_prefix'] ?? 'joinery-backups'));
	$target->set('bkt_enabled', isset($_POST['bkt_enabled']) ? true : false);

	// Build credentials JSON — canonical shape for all providers:
	// {access_key, secret_key, region, endpoint}
	$provider = $target->get('bkt_provider');
	$creds = [];
	if ($provider === 'b2') {
		// User enters B2 applicationKeyId + applicationKey. Detect the S3-compat
		// endpoint automatically via b2_authorize_account; store unified shape.
		$key_id = trim($_POST['cred_key_id'] ?? '');
		$app_key = trim($_POST['cred_app_key'] ?? '');
		$b2_region = '';
		$b2_endpoint = '';
		if ($key_id !== '' && $app_key !== '') {
			$ch = curl_init('https://api.backblazeb2.com/b2api/v3/b2_authorize_account');
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Basic ' . base64_encode($key_id . ':' . $app_key)]);
			curl_setopt($ch, CURLOPT_TIMEOUT, 10);
			$body = curl_exec($ch);
			$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);
			if ($status === 200 && ($data = json_decode($body, true))) {
				$s3_url = $data['apiInfo']['storageApi']['s3ApiUrl'] ?? '';
				if (preg_match('#^https?://s3\.([^.]+)\.backblazeb2\.com#', $s3_url, $m)) {
					$b2_region = $m[1];
					$b2_endpoint = $s3_url;
				}
			}
		}
		$creds = [
			'access_key' => $key_id,
			'secret_key' => $app_key,
			'region' => $b2_region,
			'endpoint' => $b2_endpoint,
		];
	} elseif ($provider === 's3') {
		$region = trim($_POST['cred_region'] ?? 'us-east-1');
		$creds = [
			'access_key' => trim($_POST['cred_access_key'] ?? ''),
			'secret_key' => trim($_POST['cred_secret_key'] ?? ''),
			'region' => $region,
			'endpoint' => 'https://s3.' . $region . '.amazonaws.com',
		];
	} elseif ($provider === 'linode') {
		$creds = [
			'access_key' => trim($_POST['cred_access_key'] ?? ''),
			'secret_key' => trim($_POST['cred_secret_key'] ?? ''),
			'region' => trim($_POST['cred_region'] ?? 'us-east-1'),
			'endpoint' => trim($_POST['cred_endpoint'] ?? ''),
		];
	}
	$target->set('bkt_credentials', json_encode($creds));

	if (!isset($_POST['bkt_enabled'])) {
		$target->set('bkt_enabled', false);
	}

	try {
		$target->prepare();
		$target->save();
		$target->load();

		$test_result = TargetTester::test($target);
		$page_regex = '/\/admin\/server_manager/';
		if ($test_result['success']) {
			$session->save_message(new DisplayMessage(
				'Target saved. ' . $test_result['message'],
				'Success', $page_regex,
				DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
		} else {
			$session->save_message(new DisplayMessage(
				'Target saved, but connection test failed: ' . $test_result['message'],
				'Warning', $page_regex,
				DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
		}
		header('Location: /admin/server_manager/targets?bkt_id=' . $target->key);
		exit;
	} catch (Exception $e) {
		$error = $e->getMessage();
		$is_edit = $target->key ? true : false;
	}
}

// Load all targets for listing
$all_targets = new MultiBackupTarget(['deleted' => false], ['bkt_name' => 'ASC']);
$all_targets->load();

$page = new AdminPage();
$page->admin_header([
	'menu-id' => 'server-manager',
	'page_title' => 'Backup Targets',
	'readable_title' => 'Backup Targets',
	'breadcrumbs' => [
		'Server Manager' => '/admin/server_manager',
		'Targets' => '',
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

// ── Target List ──
$provider_labels = ['b2' => 'Backblaze B2', 's3' => 'Amazon S3', 'linode' => 'Linode Object Storage'];

$pageoptions = ['title' => 'Backup Targets', 'altlinks' => ['Add Target' => '/admin/server_manager/targets?action=add']];
$page->begin_box($pageoptions);

echo '<table class="table table-striped table-sm">';
echo '<thead><tr><th>Name</th><th>Provider</th><th>Bucket</th><th>Path Prefix</th><th>Status</th><th>Actions</th></tr></thead>';
echo '<tbody>';

$target_count = 0;
foreach ($all_targets as $t) {
	$target_count++;
	$prov = $t->get('bkt_provider');
	$prov_label = $provider_labels[$prov] ?? $prov;
	$enabled = $t->get('bkt_enabled');
	echo '<tr>';
	echo '<td><a href="/admin/server_manager/target_info?bkt_id=' . $t->key . '">' . htmlspecialchars($t->get('bkt_name')) . '</a></td>';
	echo '<td>' . htmlspecialchars($prov_label) . '</td>';
	echo '<td>' . htmlspecialchars($t->get('bkt_bucket') ?: '-') . '</td>';
	echo '<td>' . htmlspecialchars($t->get('bkt_path_prefix') ?: '-') . '</td>';
	echo '<td><span class="badge bg-' . ($enabled ? 'success' : 'secondary') . '">' . ($enabled ? 'Enabled' : 'Disabled') . '</span></td>';
	echo '<td><a href="/admin/server_manager/targets?bkt_id=' . $t->key . '" class="btn btn-sm btn-outline-primary">Edit</a> ';
	echo '<a href="/admin/server_manager/targets?bkt_id=' . $t->key . '&action=test" class="btn btn-sm btn-outline-secondary">Test</a></td>';
	echo '</tr>';
}

if ($target_count === 0) {
	echo '<tr><td colspan="6" class="text-muted text-center">No backup targets configured. Backups are stored locally on each node.</td></tr>';
}

echo '</tbody></table>';
$page->end_box();

// ── Add/Edit Form ──
if ($target !== null) {
	$creds = $target->key ? $target->get_credentials() : [];
	$current_provider = $target->get('bkt_provider') ?: 'b2';

	$form_title = $is_edit ? 'Edit Target: ' . htmlspecialchars($target->get('bkt_name')) : 'Add Target';
	$pageoptions = ['title' => $form_title];
	$page->begin_box($pageoptions);
?>
	<form method="post">
		<?php if ($is_edit): ?>
			<input type="hidden" name="edit_primary_key_value" value="<?php echo $target->key; ?>">
		<?php endif; ?>

		<div class="row mb-3">
			<div class="col-md-6">
				<label class="form-label">Name *</label>
				<input type="text" name="bkt_name" class="form-control" value="<?php echo htmlspecialchars($target->get('bkt_name') ?: ''); ?>" required placeholder="e.g., Production B2">
			</div>
			<div class="col-md-6">
				<label class="form-label">Provider *</label>
				<select name="bkt_provider" id="providerSelect" class="form-select" onchange="toggleProviderFields()">
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
					<input type="text" name="bkt_bucket" class="form-control" value="<?php echo htmlspecialchars($target->get('bkt_bucket') ?: ''); ?>" placeholder="my-backup-bucket">
				</div>
				<div class="col-md-6">
					<label class="form-label">Path Prefix</label>
					<input type="text" name="bkt_path_prefix" class="form-control" value="<?php echo htmlspecialchars($target->get('bkt_path_prefix') ?: 'joinery-backups'); ?>" placeholder="joinery-backups">
					<small class="text-muted">Files stored at: bucket/prefix/node-slug/filename</small>
				</div>
			</div>

			<!-- B2 Credentials -->
			<div id="b2Fields" style="<?php echo $current_provider === 'b2' ? '' : 'display:none'; ?>">
				<h6 class="text-muted mb-3">Backblaze B2 Credentials</h6>
				<div class="row mb-3">
					<div class="col-md-6">
						<label class="form-label">Application Key ID</label>
						<input type="text" name="cred_key_id" class="form-control" value="<?php echo htmlspecialchars($creds['access_key'] ?? ''); ?>">
						<small class="text-muted">Create via Backblaze → Account → Application Keys. Must be a scoped key — the master account key will not work with the S3-compatible API.</small>
					</div>
					<div class="col-md-6">
						<label class="form-label">Application Key</label>
						<input type="password" name="cred_app_key" class="form-control" value="<?php echo htmlspecialchars($creds['secret_key'] ?? ''); ?>">
						<small class="text-muted">Region is auto-detected on save.</small>
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
			<input type="checkbox" name="bkt_enabled" class="form-check-input" id="targetEnabled" value="1" <?php echo ($target->key ? $target->get('bkt_enabled') : true) ? 'checked' : ''; ?>>
			<label class="form-check-label" for="targetEnabled">Enabled</label>
		</div>

		<button type="submit" class="btn btn-primary"><?php echo $is_edit ? 'Save Changes' : 'Add Target'; ?></button>
		<a href="/admin/server_manager/targets" class="btn btn-outline-secondary ms-2">Cancel</a>

		<?php if ($is_edit): ?>
			<a href="/admin/server_manager/targets?bkt_id=<?php echo $target->key; ?>&action=delete" class="btn btn-outline-danger ms-2" onclick="return confirm('Delete this target?')">Delete</a>
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
