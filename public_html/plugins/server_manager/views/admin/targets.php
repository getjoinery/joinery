<?php
/**
 * Server Manager - Backup Targets
 * URL: /admin/server_manager/targets
 *
 * CRUD page for managing backup storage targets (B2, S3, Linode).
 *
 * @version 2.6 - node credential (write-only): a target can hold a second key handed to nodes
 *                during a backup run, so the delete-capable key never leaves the management node.
 *                B2 and S3 only; Linode cannot express write-without-delete and says so.
 * @version 2.5 - recovery key setup is drawn once, on the core Backups page; this page keeps the
 *                standing state line and links there. The three POST handlers that served the
 *                walkthrough here are gone with the form that posted to them.
 * @version 2.4 - guided backup key recovery walkthrough (detects the outstanding step and walks
 *                it) replaces the bare verify card; public-key save/clear and bulk node escrow
 *                actions added
 * @version 2.3 - Stored Backups panel: list + delete offsite objects from the management node
 *                (node-independent), grouped by site with live/decommissioned/orphaned tags
 * @version 2.2 - possession check for the recovery key; CSRF on the save handler; undecryptable stored credentials surfaced instead of silently merged
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/backup_target_class.php'));
require_once(PathHelper::getIncludePath('includes/TargetTester.php'));
require_once(PathHelper::getIncludePath('includes/TargetBackups.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/FleetBackups.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/SmAdminCsrf.php'));
// Read by the recovery-key panel below on every render, not only by the POST
// handlers that also require it — a plain GET has to have it too.
require_once(PathHelper::getIncludePath('includes/BackupRecoveryKey.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/RecoveryKeyFleet.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));

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

// Test and delete are POST actions (a GET link is CSRF-triggerable), CSRF-validated.
$post_action = ($_POST['action'] ?? '');

if ($post_action === 'test_target' && $is_edit) {
	if (!SmAdminCsrf::valid()) { header('Location: /admin/server_manager/targets'); exit; }
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

if ($post_action === 'delete_target' && $is_edit) {
	if (!SmAdminCsrf::valid()) { header('Location: /admin/server_manager/targets'); exit; }
	$target->soft_delete();
	$page_regex = '/\/admin\/server_manager/';
	$session->save_message(new DisplayMessage(
		'Target deleted.', 'Success', $page_regex,
		DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
	));
	header('Location: /admin/server_manager/targets');
	exit;
}

// Recovery key setup lives in one place — the core Backups page, which draws
// RecoveryKeySetupPanel. This page shows the standing state and links there.

// Delete every offsite backup object for one site (whole slug prefix). Run from the
// management node against the bucket — no live node needed, so a decommissioned site's
// backups are still reachable. Type-to-confirm on the client, slug-validated on the server.
if ($post_action === 'delete_backup_prefix' && $is_edit) {
	if (!SmAdminCsrf::valid()) { header('Location: /admin/server_manager/targets'); exit; }
	$page_regex = '/\/admin\/server_manager/';
	$slug = trim($_POST['slug'] ?? '');
	try {
		$n = TargetBackups::delete_prefix($target, $slug);
		$session->save_message(new DisplayMessage(
			'Deleted ' . $n . ' backup object' . ($n === 1 ? '' : 's') . ' for "' . $slug . '".',
			'Success', $page_regex, DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
	} catch (Exception $e) {
		$session->save_message(new DisplayMessage(
			$e->getMessage(), 'Error', $page_regex, DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
	}
	header('Location: /admin/server_manager/targets?bkt_id=' . $target->key);
	exit;
}

// Delete a single offsite backup object. The key is validated to sit under this
// target's prefix before the delete is issued.
if ($post_action === 'delete_backup_object' && $is_edit) {
	if (!SmAdminCsrf::valid()) { header('Location: /admin/server_manager/targets'); exit; }
	$page_regex = '/\/admin\/server_manager/';
	try {
		TargetBackups::delete_object($target, $_POST['key'] ?? '');
		$session->save_message(new DisplayMessage(
			'Backup object deleted.', 'Success', $page_regex,
			DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
	} catch (Exception $e) {
		$session->save_message(new DisplayMessage(
			$e->getMessage(), 'Error', $page_regex, DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
	}
	header('Location: /admin/server_manager/targets?bkt_id=' . $target->key);
	exit;
}

// Handle form save
$error = null;
if ($_POST && isset($_POST['bkt_name'])) {
	// Same CSRF gate as every other mutation on this page: this handler writes
	// storage credentials, the highest-value forgery target here.
	if (!SmAdminCsrf::valid()) { header('Location: /admin/server_manager/targets'); exit; }
	if (!$target) {
		$target = new BackupTarget(NULL);
	}

	$target->set('bkt_name', trim($_POST['bkt_name'] ?? ''));
	$target->set('bkt_provider', trim($_POST['bkt_provider'] ?? 'b2'));
	$target->set('bkt_bucket', trim($_POST['bkt_bucket'] ?? ''));
	$target->set('bkt_path_prefix', trim($_POST['bkt_path_prefix'] ?? 'joinery-backups'));
	$target->set('bkt_enabled', isset($_POST['bkt_enabled']) ? true : false);

	// Leave-blank-to-keep: secret fields are never prefilled into the form, so a
	// blank secret on an edit means "keep the stored one" rather than wipe it (S-5).
	// Undecryptable stored credentials mean there is nothing to keep — surface
	// that instead of silently merging with nothing.
	try {
		$existing_creds = ($target->key ? $target->get_credentials() : []);
	} catch (BackupTargetException $e) {
		$existing_creds = [];
		$error = $e->getMessage() . ' Re-enter BOTH the access key and the secret to replace them.';
	}

	// Build credentials JSON — canonical shape for all providers:
	// {access_key, secret_key, region, endpoint}
	$provider = $target->get('bkt_provider');
	$creds = [];
	if ($provider === 'b2') {
		// User enters B2 applicationKeyId + applicationKey. Detect the S3-compat
		// endpoint automatically via b2_authorize_account; store unified shape.
		$key_id = trim($_POST['cred_key_id'] ?? '');
		$app_key = trim($_POST['cred_app_key'] ?? '');
		if ($app_key === '' && !empty($existing_creds['secret_key'])) {
			// Leave-blank-to-keep: preserve stored B2 credentials (and the detected
			// region/endpoint) verbatim; do not re-authorize. A changed key ID with
			// a blank secret still keeps the stored secret.
			$creds = $existing_creds;
			if ($key_id !== '') { $creds['access_key'] = $key_id; }
		} else {
			$b2_region = '';
			$b2_endpoint = '';
			if ($key_id !== '' && $app_key !== '') {
				$ch = curl_init('https://api.backblazeb2.com/b2api/v3/b2_authorize_account');
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Basic ' . base64_encode($key_id . ':' . $app_key)]);
				curl_setopt($ch, CURLOPT_TIMEOUT, 10);
				$body = curl_exec($ch);
				$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
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
		}
	} elseif ($provider === 's3') {
		$region = trim($_POST['cred_s3_region'] ?? 'us-east-1');
		$secret = trim($_POST['cred_s3_secret_key'] ?? '');
		if ($secret === '' && !empty($existing_creds['secret_key'])) { $secret = $existing_creds['secret_key']; }
		$creds = [
			'access_key' => trim($_POST['cred_s3_access_key'] ?? ''),
			'secret_key' => $secret,
			'region' => $region,
			'endpoint' => 'https://s3.' . $region . '.amazonaws.com',
		];
	} elseif ($provider === 'linode') {
		$linode_secret = trim($_POST['cred_linode_secret_key'] ?? '');
		if ($linode_secret === '' && !empty($existing_creds['secret_key'])) { $linode_secret = $existing_creds['secret_key']; }
		$creds = [
			'access_key' => trim($_POST['cred_linode_access_key'] ?? ''),
			'secret_key' => $linode_secret,
			'region' => trim($_POST['cred_linode_region'] ?? ''),
			'endpoint' => trim($_POST['cred_linode_endpoint'] ?? ''),
		];
	}
	$target->set('bkt_credentials', json_encode($creds));

	// Node (write-only) credential — an optional second key handed to nodes
	// during a backup run in place of the delete-capable one above. Same
	// leave-blank-to-keep semantics; empty means "not configured" and nodes
	// receive the main credential.
	try {
		$existing_node = ($target->key ? $target->get_node_credentials() : []);
	} catch (BackupTargetException $e) {
		$existing_node = [];
	}
	if (isset($_POST['node_creds_remove'])) {
		$target->set('bkt_node_credentials', null);
	} elseif ($provider === 'b2') {
		$nk_id  = trim($_POST['node_cred_key_id'] ?? '');
		$nk_key = trim($_POST['node_cred_app_key'] ?? '');
		if ($nk_key === '' && !empty($existing_node['secret_key'])) {
			$node_creds = $existing_node;
			if ($nk_id !== '') { $node_creds['access_key'] = $nk_id; }
			$target->set('bkt_node_credentials', json_encode($node_creds));
		} elseif ($nk_id !== '' && $nk_key !== '') {
			// Same bucket, so the endpoint detection matches the main key's.
			$nb_region = '';
			$nb_endpoint = '';
			$ch = curl_init('https://api.backblazeb2.com/b2api/v3/b2_authorize_account');
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Basic ' . base64_encode($nk_id . ':' . $nk_key)]);
			curl_setopt($ch, CURLOPT_TIMEOUT, 10);
			$body = curl_exec($ch);
			$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
			if ($status === 200 && ($data = json_decode($body, true))) {
				$s3_url = $data['apiInfo']['storageApi']['s3ApiUrl'] ?? '';
				if (preg_match('#^https?://s3\.([^.]+)\.backblazeb2\.com#', $s3_url, $m)) {
					$nb_region = $m[1];
					$nb_endpoint = $s3_url;
				}
			}
			$target->set('bkt_node_credentials', json_encode([
				'access_key' => $nk_id,
				'secret_key' => $nk_key,
				'region'     => $nb_region,
				'endpoint'   => $nb_endpoint,
			]));
		}
	} elseif ($provider === 's3') {
		$ns_access = trim($_POST['node_cred_s3_access_key'] ?? '');
		$ns_secret = trim($_POST['node_cred_s3_secret_key'] ?? '');
		if ($ns_secret === '' && !empty($existing_node['secret_key'])) {
			$node_creds = $existing_node;
			if ($ns_access !== '') { $node_creds['access_key'] = $ns_access; }
			$target->set('bkt_node_credentials', json_encode($node_creds));
		} elseif ($ns_access !== '' && $ns_secret !== '') {
			$ns_region = trim($_POST['cred_s3_region'] ?? 'us-east-1');
			$target->set('bkt_node_credentials', json_encode([
				'access_key' => $ns_access,
				'secret_key' => $ns_secret,
				'region'     => $ns_region,
				'endpoint'   => 'https://s3.' . $ns_region . '.amazonaws.com',
			]));
		}
	}
	// Linode: no node credential — its keys cannot express write-without-delete.

	if (!isset($_POST['bkt_enabled'])) {
		$target->set('bkt_enabled', false);
	}

	try {
		if ($error !== null) {
			throw new Exception($error); // undecryptable stored creds — do not save a silent merge-with-nothing
		}
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
		echo '<div class="alert ' . $alert_class . '">';
		echo htmlspecialchars($msg->message);
		echo '<button type="button" class="alert-close" aria-label="Close">&times;</button></div>';
	}
	// Rendered above, so these are spent; the footer drops them.
	$session->mark_shown($display_messages);
	$session->clear_clearable_messages();
}

if ($error) {
	echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
}

// ── Backup key recovery ──
// The guided walkthrough: it detects how far setup has got and renders the one
// outstanding step. Once every targeted node is escrowed it collapses to a
// standing summary of what recovery would look like.
// Recovery key setup is core, not fleet: a standalone site needs it just as
// much. This page links there rather than carrying a second copy of the panel.
$rk_state = BackupRecoveryKey::setup_state();
echo '<div class="alert ' . ($rk_state['is_ready'] ? 'alert-success' : 'alert-warning') . ' border" id="backup-key-setup">';
echo '<strong>Backup key recovery.</strong> '
   . htmlspecialchars(BackupRecoveryKey::outstanding_summary($rk_state));
if ($rk_state['is_ready']) {
	echo ' Key ' . htmlspecialchars($rk_state['fingerprint']) . '&hellip;';
}
echo ' <a href="' . BackupRecoveryKey::SETUP_URL . '" class="alert-link">Open backup settings</a>.';
echo '</div>';

// ── Which nodes can be backed up at all ──
// Every backup of a node seals to the recovery key that NODE holds and has
// proven, read there — the copies taken from here as much as the copies it takes
// for itself. Nothing supplies a key from this management node, because a key sent
// from here would let this management node decide who can open a node's database
// and mail, with nothing anywhere looking wrong. So a node with no verified key
// is not a node with a preference; it is a node nobody is backing up, and this
// table is the fleet's coverage list.
$rk_nodes = new MultiManagedNode(['deleted' => false, 'enabled' => true], ['mgn_name' => 'ASC']);
$rk_nodes->load();

$rk_rows = [];
foreach ($rk_nodes as $rk_node) {
	$rk_state = RecoveryKeyFleet::node_state($rk_node);
	if ($rk_state['state'] === 'n/a') continue;   // not applicable, not a gap
	$rk_rows[] = ['node' => $rk_node, 'rk' => $rk_state];
}

if ($rk_rows) {
	$page->begin_box(['title' => 'Which nodes can be backed up']);
	echo '<p class="text-muted">A backup is encrypted to the recovery key the node itself holds and '
	   . 'has verified, and that key is set up by whoever administers the node, on the node\'s own '
	   . 'Backups page. This management node deliberately cannot supply one &mdash; a key sent from here '
	   . 'would be a key this machine could open every node\'s backups with. A node without a verified '
	   . 'key takes no backups, including the ones scheduled from here.</p>';
	echo '<table class="table table-sm"><thead><tr>'
	   . '<th>Node</th><th>Recovery key on the node</th>'
	   . '</tr></thead><tbody>';
	$rk_badges = ['proven' => 'success', 'missing' => 'warning', 'unproven' => 'warning', 'unknown' => 'secondary'];
	$rk_labels = ['proven' => 'can be backed up', 'missing' => 'no key — not backed up',
		'unproven' => 'not verified — not backed up', 'unknown' => 'not checked yet'];
	foreach ($rk_rows as $row) {
		$n  = $row['node'];
		$rk = $row['rk'];
		echo '<tr>';
		echo '<td><a href="/admin/server_manager/node_detail?mgn_id=' . (int)$n->key . '&tab=backups">'
		   . htmlspecialchars($n->get('mgn_name')) . '</a></td>';
		echo '<td><span class="badge bg-' . ($rk_badges[$rk['state']] ?? 'secondary') . '">'
		   . htmlspecialchars($rk_labels[$rk['state']] ?? $rk['state']) . '</span> ';
		echo '<span class="small text-muted">' . htmlspecialchars($rk['summary']);
		if ($rk['fingerprint'] !== '') {
			echo ' (' . htmlspecialchars(RecoveryKeyFleet::short($rk['fingerprint'])) . '&hellip;)';
		}
		echo '</span></td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
	$page->end_box();
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
	// Test is a POST action (it hits the provider; a GET link is CSRF-triggerable).
	echo '<form method="post" action="/admin/server_manager/targets?bkt_id=' . $t->key . '" style="display:inline;">';
	echo '<input type="hidden" name="action" value="test_target">';
	echo SmAdminCsrf::field();
	echo '<button type="submit" class="btn btn-sm btn-outline-secondary">Test</button>';
	echo '</form></td>';
	echo '</tr>';
}

if ($target_count === 0) {
	echo '<tr><td colspan="6" class="text-muted text-center">No backup targets configured. Backups are stored locally on each node.</td></tr>';
}

echo '</tbody></table>';
$page->end_box();

// ── Add/Edit Form ──
if ($target !== null) {
	try {
		$creds = $target->key ? $target->get_credentials() : [];
	} catch (BackupTargetException $e) {
		$creds = [];
		echo '<div class="alert alert-danger">' . htmlspecialchars($e->getMessage()) . ' Re-enter both credential fields to replace them.</div>';
	}
	$current_provider = $target->get('bkt_provider') ?: 'b2';

	$has_node_creds = $is_edit && $target->has_node_credentials();
	try {
		$node_creds = $has_node_creds ? $target->get_node_credentials() : [];
	} catch (BackupTargetException $e) {
		$node_creds = [];
	}

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
			// Secret fields are NEVER prefilled — leave blank to keep the stored key (S-5).
			'cred_app_key'           => '',
			'cred_s3_access_key'     => $current_provider === 's3' ? ($creds['access_key'] ?? '') : '',
			'cred_s3_region'         => $current_provider === 's3' ? ($creds['region'] ?? 'us-east-1') : 'us-east-1',
			'cred_linode_access_key' => $current_provider === 'linode' ? ($creds['access_key'] ?? '') : '',
			'cred_linode_region'     => $current_provider === 'linode' ? ($creds['region'] ?? '') : '',
			'cred_linode_endpoint'   => $current_provider === 'linode' ? ($creds['endpoint'] ?? '') : '',
			'node_cred_key_id'        => $current_provider === 'b2' ? ($node_creds['access_key'] ?? '') : '',
			// Node secret fields are never prefilled either — leave blank to keep.
			'node_cred_app_key'       => '',
			'node_cred_s3_access_key' => $current_provider === 's3' ? ($node_creds['access_key'] ?? '') : '',
		],
	]);

	$formwriter->begin_form();
	echo SmAdminCsrf::field();
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
			document.getElementById('b2Fields').hidden       = p !== 'b2';
			document.getElementById('s3Fields').hidden       = p !== 's3';
			document.getElementById('linodeFields').hidden   = p !== 'linode';
			document.getElementById('nodeCredB2').hidden     = p !== 'b2';
			document.getElementById('nodeCredS3').hidden     = p !== 's3';
			document.getElementById('nodeCredLinode').hidden = p !== 'linode';
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
	echo '<div id="b2Fields"' . ($current_provider === 'b2' ? '' : ' hidden') . '>';
	echo '<p class="fw-semibold text-muted mt-2 mb-1">Backblaze B2 Credentials</p>';
	$formwriter->textinput('cred_key_id', 'Application Key ID', [
		'helptext' => 'Create via Backblaze → Account → Application Keys. Must be a scoped key — the master account key will not work with the S3-compatible API.',
	]);
	$formwriter->passwordinput('cred_app_key', 'Application Key', [
		'helptext' => $is_edit ? 'Leave blank to keep the current key. Region is auto-detected on save.' : 'Region is auto-detected on save.',
	]);
	echo '</div>';

	// ── S3 Credentials ──
	echo '<div id="s3Fields"' . ($current_provider === 's3' ? '' : ' hidden') . '>';
	echo '<p class="fw-semibold text-muted mt-2 mb-1">Amazon S3 Credentials</p>';
	$formwriter->textinput('cred_s3_access_key', 'Access Key');
	$formwriter->passwordinput('cred_s3_secret_key', 'Secret Key', $is_edit ? ['helptext' => 'Leave blank to keep the current key.'] : []);
	$formwriter->textinput('cred_s3_region', 'Region', ['placeholder' => 'us-east-1']);
	echo '</div>';

	// ── Linode Credentials ──
	echo '<div id="linodeFields"' . ($current_provider === 'linode' ? '' : ' hidden') . '>';
	echo '<p class="fw-semibold text-muted mt-2 mb-1">Linode Object Storage Credentials</p>';
	$formwriter->textinput('cred_linode_access_key', 'Access Key');
	$formwriter->passwordinput('cred_linode_secret_key', 'Secret Key', $is_edit ? ['helptext' => 'Leave blank to keep the current key.'] : []);
	$formwriter->textinput('cred_linode_region', 'Region', ['placeholder' => 'us-east-1']);
	$formwriter->textinput('cred_linode_endpoint', 'Endpoint URL', ['placeholder' => 'https://us-east-1.linodeobjects.com']);
	echo '</div>';

	// ── Node credential (write-only) ──
	echo '<div id="nodeCredB2"' . ($current_provider === 'b2' ? '' : ' hidden') . '>';
	echo '<p class="fw-semibold text-muted mt-2 mb-1">Node Credential (write-only)'
		. ($has_node_creds ? ' <span class="badge bg-success">configured</span>' : '') . '</p>';
	$formwriter->textinput('node_cred_key_id', 'Node Application Key ID', [
		'helptext' => 'Optional second B2 key, created with write but not delete capability on this bucket. Nodes are handed this key during a backup run, so a compromised node cannot erase the shelf; the main key above never leaves this management node.',
	]);
	$formwriter->passwordinput('node_cred_app_key', 'Node Application Key', [
		'helptext' => $has_node_creds ? 'Leave blank to keep the current key.' : 'Leave empty to keep handing nodes the main key.',
	]);
	echo '</div>';

	echo '<div id="nodeCredS3"' . ($current_provider === 's3' ? '' : ' hidden') . '>';
	echo '<p class="fw-semibold text-muted mt-2 mb-1">Node Credential (write-only)'
		. ($has_node_creds ? ' <span class="badge bg-success">configured</span>' : '') . '</p>';
	$formwriter->textinput('node_cred_s3_access_key', 'Node Access Key', [
		'helptext' => 'Optional second key from an IAM user allowed s3:PutObject but not s3:DeleteObject on this bucket. Nodes are handed this key during a backup run; the main key above never leaves this management node.',
	]);
	$formwriter->passwordinput('node_cred_s3_secret_key', 'Node Secret Key', [
		'helptext' => $has_node_creds ? 'Leave blank to keep the current key.' : 'Leave empty to keep handing nodes the main key.',
	]);
	echo '</div>';

	echo '<div id="nodeCredLinode"' . ($current_provider === 'linode' ? '' : ' hidden') . '>';
	echo '<p class="text-muted mt-2 mb-1">Linode Object Storage keys are read-only or read-write per bucket — write-without-delete is not expressible, so nodes are handed the main key during a run. B2 and S3 targets can hold a separate write-only node credential.</p>';
	echo '</div>';

	if ($has_node_creds) {
		$formwriter->checkboxinput('node_creds_remove', 'Remove node credential', [
			'helptext' => 'Nodes go back to being handed the main key during a run.',
		]);
	}

	$formwriter->checkboxinput('bkt_enabled', 'Enabled', [
		'checked' => (bool)($target->key ? $target->get('bkt_enabled') : true),
	]);
	$formwriter->submitbutton('btn_submit', $is_edit ? 'Save Changes' : 'Add Target');
	$formwriter->end_form();

	echo '<a href="/admin/server_manager/targets" class="btn btn-outline-secondary ms-2">Cancel</a>';
	if ($is_edit) {
		echo '<form method="post" action="/admin/server_manager/targets?bkt_id=' . $target->key . '" id="delete_target_form" style="display:inline;">';
		echo '<input type="hidden" name="action" value="delete_target">';
		echo SmAdminCsrf::field();
		echo '<button type="button" class="btn btn-outline-danger ms-2" onclick="JoineryModal.confirm(\'Delete this target?\', function(){ document.getElementById(\'delete_target_form\').submit(); })">Delete</button>';
		echo '</form>';
	}

	$page->end_box();

	// ── Stored Backups (management-node view of the bucket) ──
	if ($is_edit) {
		$fmt_bytes = function ($b) {
			if ($b >= 1073741824) return round($b / 1073741824, 1) . ' GB';
			if ($b >= 1048576)    return round($b / 1048576, 1) . ' MB';
			if ($b >= 1024)       return round($b / 1024, 1) . ' KB';
			return $b . ' B';
		};
		$badge_for = ['live' => 'success', 'decommissioned' => 'warning', 'orphaned' => 'secondary'];

		$page->begin_box(['title' => 'Stored Backups']);
		try {
			$listing = FleetBackups::list_grouped($target);
			if ($listing['total_objects'] === 0) {
				echo '<p class="text-muted">No backup objects found under '
					. htmlspecialchars(TargetBackups::base_prefix($target)) . '</p>';
			} else {
				echo '<p class="text-muted">' . $listing['total_objects'] . ' object'
					. ($listing['total_objects'] === 1 ? '' : 's') . ', ' . $fmt_bytes($listing['total_bytes'])
					. ' total, grouped by site. A decommissioned site keeps its backups here until you delete them.</p>';

				foreach ($listing['groups'] as $slug => $g) {
					$badge = $badge_for[$g['status']] ?? 'secondary';
					echo '<div class="card mb-2"><div class="card-body">';
					echo '<div class="d-flex justify-content-between align-items-start">';

					echo '<div><strong>' . htmlspecialchars($slug) . '</strong> ';
					echo '<span class="badge bg-' . $badge . '">' . htmlspecialchars($g['status']) . '</span>';
					if ($g['status'] === 'live' && $g['node_id']) {
						echo ' <a class="small ms-1" href="/admin/server_manager/node_detail?mgn_id='
							. (int)$g['node_id'] . '&tab=backups">manage on node</a>';
					}
					echo '<div class="text-muted small">' . $g['count'] . ' object'
						. ($g['count'] === 1 ? '' : 's') . ', ' . $fmt_bytes($g['bytes']) . '</div>';

					// Per-object detail with individual delete.
					echo '<details class="mt-1"><summary class="small">Show files</summary>';
					echo '<table class="table table-sm mt-1 mb-0"><tbody>';
					foreach ($g['objects'] as $obj) {
						$fname = basename($obj['key']);
						$oid = 'delobj_' . md5($obj['key']);
						echo '<tr>';
						echo '<td class="small">' . htmlspecialchars($fname) . '</td>';
						echo '<td class="small text-muted">' . $fmt_bytes((int)$obj['size']) . '</td>';
						echo '<td class="small text-muted">' . htmlspecialchars($obj['last_modified']) . '</td>';
						echo '<td class="text-end">';
						echo '<form method="post" action="/admin/server_manager/targets?bkt_id=' . $target->key . '" id="' . $oid . '" style="margin:0;">';
						echo '<input type="hidden" name="action" value="delete_backup_object">';
						echo '<input type="hidden" name="key" value="' . htmlspecialchars($obj['key']) . '">';
						echo SmAdminCsrf::field();
						$obj_msg = 'Delete backup file ' . $fname . '? This cannot be undone.';
						echo '<button type="button" class="btn btn-sm btn-outline-danger" onclick="JoineryModal.confirm('
							. json_encode($obj_msg) . ', function(){ document.getElementById(' . json_encode($oid) . ').submit(); })">Delete</button>';
						echo '</form>';
						echo '</td></tr>';
					}
					echo '</tbody></table></details>';
					echo '</div>'; // left column

					// Delete-all-for-this-site (whole prefix), type-to-confirm the slug.
					$pid = 'delpfx_' . md5($slug);
					echo '<form method="post" action="/admin/server_manager/targets?bkt_id=' . $target->key . '" id="' . $pid . '" style="margin:0;">';
					echo '<input type="hidden" name="action" value="delete_backup_prefix">';
					echo '<input type="hidden" name="slug" value="' . htmlspecialchars($slug) . '">';
					echo SmAdminCsrf::field();
					$pfx_msg = 'Delete all ' . $g['count'] . ' backup object' . ($g['count'] === 1 ? '' : 's')
						. ' for this site? This cannot be undone.';
					echo '<button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="JoineryModal.confirmTyped('
						. json_encode($pfx_msg) . ', ' . json_encode($slug) . ', function(){ document.getElementById(' . json_encode($pid) . ').submit(); })">Delete all</button>';
					echo '</form>';

					echo '</div>'; // d-flex
					echo '</div></div>'; // card-body, card
				}
			}
		} catch (Exception $e) {
			echo '<div class="alert alert-warning">Could not list backups: ' . htmlspecialchars($e->getMessage()) . '</div>';
		}
		$page->end_box();
	}
}

$page->admin_footer();
?>
