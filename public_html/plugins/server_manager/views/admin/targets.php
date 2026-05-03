<?php
/**
 * Server Manager - Backup Targets
 * URL: /admin/server_manager/targets
 *
 * CRUD page for managing backup storage targets (B2, S3, Linode).
 *
 * @version 2.1
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
		$region = trim($_POST['cred_s3_region'] ?? 'us-east-1');
		$creds = [
			'access_key' => trim($_POST['cred_s3_access_key'] ?? ''),
			'secret_key' => trim($_POST['cred_s3_secret_key'] ?? ''),
			'region' => $region,
			'endpoint' => 'https://s3.' . $region . '.amazonaws.com',
		];
	} elseif ($provider === 'linode') {
		$creds = [
			'access_key' => trim($_POST['cred_linode_access_key'] ?? ''),
			'secret_key' => trim($_POST['cred_linode_secret_key'] ?? ''),
			'region' => trim($_POST['cred_linode_region'] ?? ''),
			'endpoint' => trim($_POST['cred_linode_endpoint'] ?? ''),
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

	$formwriter = $page->getFormWriter('target_form', [
		'values' => [
			'bkt_name'               => $target->get('bkt_name') ?: '',
			'bkt_provider'           => $current_provider,
			'bkt_bucket'             => $target->get('bkt_bucket') ?: '',
			'bkt_path_prefix'        => $target->get('bkt_path_prefix') ?: 'joinery-backups',
			'cred_key_id'            => $creds['access_key'] ?? '',
			'cred_app_key'           => $creds['secret_key'] ?? '',
			'cred_s3_access_key'     => $current_provider === 's3' ? ($creds['access_key'] ?? '') : '',
			'cred_s3_region'         => $current_provider === 's3' ? ($creds['region'] ?? 'us-east-1') : 'us-east-1',
			'cred_linode_access_key' => $current_provider === 'linode' ? ($creds['access_key'] ?? '') : '',
			'cred_linode_region'     => $current_provider === 'linode' ? ($creds['region'] ?? '') : '',
			'cred_linode_endpoint'   => $current_provider === 'linode' ? ($creds['endpoint'] ?? '') : '',
		],
	]);

	$formwriter->begin_form();
	if ($is_edit) {
		$formwriter->hiddeninput('edit_primary_key_value', '', ['value' => $target->key]);
	}

	$formwriter->textinput('bkt_name', 'Name', [
		'required'    => true,
		'placeholder' => 'e.g., Production B2',
	]);
	$formwriter->dropinput('bkt_provider', 'Provider', [
		'options'       => $provider_labels,
		'custom_script' => "
			var p = this.value;
			document.getElementById('b2Fields').style.display     = p === 'b2'     ? '' : 'none';
			document.getElementById('s3Fields').style.display     = p === 's3'     ? '' : 'none';
			document.getElementById('linodeFields').style.display = p === 'linode' ? '' : 'none';
		",
	]);
	$formwriter->textinput('bkt_bucket', 'Bucket Name', [
		'placeholder' => 'my-backup-bucket',
	]);
	$formwriter->textinput('bkt_path_prefix', 'Path Prefix', [
		'placeholder' => 'joinery-backups',
		'helptext'    => 'Files stored at: bucket/prefix/node-slug/filename',
	]);

	// ── B2 Credentials ──
	echo '<div id="b2Fields" style="' . ($current_provider === 'b2' ? '' : 'display:none') . '">';
	echo '<p class="fw-semibold text-muted mt-2 mb-1">Backblaze B2 Credentials</p>';
	$formwriter->textinput('cred_key_id', 'Application Key ID', [
		'helptext' => 'Create via Backblaze → Account → Application Keys. Must be a scoped key — the master account key will not work with the S3-compatible API.',
	]);
	$formwriter->passwordinput('cred_app_key', 'Application Key', [
		'helptext' => 'Region is auto-detected on save.',
	]);
	echo '</div>';

	// ── S3 Credentials ──
	echo '<div id="s3Fields" style="' . ($current_provider === 's3' ? '' : 'display:none') . '">';
	echo '<p class="fw-semibold text-muted mt-2 mb-1">Amazon S3 Credentials</p>';
	$formwriter->textinput('cred_s3_access_key', 'Access Key');
	$formwriter->passwordinput('cred_s3_secret_key', 'Secret Key');
	$formwriter->textinput('cred_s3_region', 'Region', ['placeholder' => 'us-east-1']);
	echo '</div>';

	// ── Linode Credentials ──
	echo '<div id="linodeFields" style="' . ($current_provider === 'linode' ? '' : 'display:none') . '">';
	echo '<p class="fw-semibold text-muted mt-2 mb-1">Linode Object Storage Credentials</p>';
	$formwriter->textinput('cred_linode_access_key', 'Access Key');
	$formwriter->passwordinput('cred_linode_secret_key', 'Secret Key');
	$formwriter->textinput('cred_linode_region', 'Region', ['placeholder' => 'us-east-1']);
	$formwriter->textinput('cred_linode_endpoint', 'Endpoint URL', ['placeholder' => 'https://us-east-1.linodeobjects.com']);
	echo '</div>';

	$formwriter->checkboxinput('bkt_enabled', 'Enabled', [
		'checked' => (bool)($target->key ? $target->get('bkt_enabled') : true),
	]);
	$formwriter->submitbutton('btn_submit', $is_edit ? 'Save Changes' : 'Add Target');
	$formwriter->end_form();

	echo '<a href="/admin/server_manager/targets" class="btn btn-outline-secondary ms-2">Cancel</a>';
	if ($is_edit) {
		echo '<a href="/admin/server_manager/targets?bkt_id=' . $target->key . '&action=delete" class="btn btn-outline-danger ms-2" onclick="return confirm(\'Delete this target?\')">Delete</a>';
	}

	$page->end_box();
}

$page->admin_footer();
?>
