<?php
/**
 * Server Manager - Add/Edit Node
 * URL: /admin/server_manager/nodes_edit
 *
 * @version 1.1
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);
$session->set_return();

// Load or create node
if (isset($_POST['edit_primary_key_value']) && $_POST['edit_primary_key_value']) {
	$node = new ManagedNode($_POST['edit_primary_key_value'], TRUE);
} elseif (isset($_GET['mgn_id'])) {
	$node = new ManagedNode($_GET['mgn_id'], TRUE);
} else {
	$node = new ManagedNode(NULL);
}

// Handle actions (check_status, test_connection, delete)
if (isset($_GET['action']) || isset($_POST['action'])) {
	$action = isset($_POST['action']) ? $_POST['action'] : $_GET['action'];
	$page_regex = '/\/admin\/server_manager/';

	if ($action === 'check_status' && $node->key) {
		$steps = JobCommandBuilder::build_check_status($node);
		$job = ManagementJob::createJob($node->key, 'check_status', $steps, null, $session->get_user_id());
		$session->save_message(new DisplayMessage(
			'Status check queued (Job #' . $job->key . ')',
			'Success', $page_regex,
			DisplayMessage::MESSAGE_ANNOUNCEMENT,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
		header('Location: /admin/server_manager/job_detail?job_id=' . $job->key);
		exit;
	}

	if ($action === 'test_connection' && $node->key) {
		$steps = JobCommandBuilder::build_test_connection($node);
		$job = ManagementJob::createJob($node->key, 'test_connection', $steps, null, $session->get_user_id());
		$session->save_message(new DisplayMessage(
			'Connection test queued (Job #' . $job->key . ')',
			'Success', $page_regex,
			DisplayMessage::MESSAGE_ANNOUNCEMENT,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
		header('Location: /admin/server_manager/job_detail?job_id=' . $job->key);
		exit;
	}

	if ($action === 'delete' && $node->key) {
		$node->soft_delete();
		$session->save_message(new DisplayMessage(
			'Node deleted.', 'Success', $page_regex,
			DisplayMessage::MESSAGE_ANNOUNCEMENT,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
		header('Location: /admin/server_manager/nodes');
		exit;
	}
}

// Handle form save
$error = null;
if ($_POST && isset($_POST['mgn_name'])) {
	$editable_fields = [
		'mgn_name', 'mgn_slug', 'mgn_host', 'mgn_ssh_user', 'mgn_ssh_key_path',
		'mgn_ssh_port', 'mgn_container_name', 'mgn_container_user', 'mgn_web_root',
		'mgn_site_url', 'mgn_notes', 'mgn_enabled',
	];

	foreach ($editable_fields as $field) {
		if (isset($_POST[$field])) {
			$value = trim($_POST[$field]);
			if ($field === 'mgn_enabled') {
				$value = isset($_POST[$field]) ? true : false;
			}
			if ($field === 'mgn_ssh_port' && $value === '') {
				$value = 22;
			}
			$node->set($field, $value);
		}
	}

	// Handle checkbox - unchecked checkboxes aren't submitted
	if (!isset($_POST['mgn_enabled'])) {
		$node->set('mgn_enabled', false);
	}

	try {
		$node->prepare();
		$node->save();
		$node->load();

		$page_regex = '/\/admin\/server_manager/';
		$session->save_message(new DisplayMessage(
			'Node saved successfully.',
			'Success', $page_regex,
			DisplayMessage::MESSAGE_ANNOUNCEMENT,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
		header('Location: /admin/server_manager/nodes_edit?mgn_id=' . $node->key);
		exit;
	} catch (Exception $e) {
		$error = $e->getMessage();
	}
}

// Default SSH key path — the agent runs as user1 and reads the key, not PHP
$default_ssh_key = '/home/user1/.ssh/id_ed25519_claude';

// Get display messages
$display_messages = $session->get_messages('/admin/server_manager');

$page = new AdminPage();
$is_edit = $node->key ? true : false;
$page->admin_header([
	'menu-id' => 'server-manager',
	'page_title' => $is_edit ? 'Edit Node' : 'Add Node',
	'readable_title' => $is_edit ? 'Edit Node' : 'Add Node',
	'breadcrumbs' => [
		'Server Manager' => '/admin/server_manager',
		'Nodes' => '/admin/server_manager/nodes',
		($is_edit ? 'Edit' : 'Add') => '',
	],
	'session' => $session,
]);

// Display messages
if (!empty($display_messages)) {
	foreach ($display_messages as $msg) {
		$alert_class = 'alert-info';
		if ($msg->display_type == DisplayMessage::MESSAGE_ERROR) {
			$alert_class = 'alert-danger';
		} elseif ($msg->display_type == DisplayMessage::MESSAGE_ANNOUNCEMENT) {
			$alert_class = 'alert-success';
		}
		echo '<div class="alert ' . $alert_class . ' alert-dismissible fade show" role="alert">';
		if ($msg->message_title) {
			echo '<strong>' . htmlspecialchars($msg->message_title) . ':</strong> ';
		}
		echo htmlspecialchars($msg->message);
		echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
	}
	$session->clear_clearable_messages();
}

if ($error) {
	echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
}

// ── Auto-detect section (only for new nodes) ──
if (!$is_edit): ?>

<div class="card mb-4" id="detect-panel">
	<div class="card-header"><strong>Auto-Detect Joinery Servers</strong></div>
	<div class="card-body">
		<p class="text-muted">Enter an SSH host and key to scan for Joinery instances. Docker containers and bare-metal installs are detected automatically.</p>
		<div class="row g-3 align-items-end">
			<div class="col-md-4">
				<label class="form-label">SSH Host *</label>
				<input type="text" id="detect_host" class="form-control" placeholder="e.g., 23.239.11.53">
			</div>
			<div class="col-md-3">
				<label class="form-label">SSH Key Path *</label>
				<input type="text" id="detect_key" class="form-control" value="<?php echo htmlspecialchars($default_ssh_key); ?>">
			</div>
			<div class="col-md-2">
				<label class="form-label">SSH User</label>
				<input type="text" id="detect_user" class="form-control" value="root">
			</div>
			<div class="col-md-1">
				<label class="form-label">Port</label>
				<input type="number" id="detect_port" class="form-control" value="22">
			</div>
			<div class="col-md-2">
				<button type="button" id="detect_btn" class="btn btn-primary w-100" onclick="detectServers()">Detect</button>
			</div>
		</div>
		<div id="detect_status" class="mt-3" style="display:none"></div>
		<div id="detect_results" class="mt-3"></div>
	</div>
</div>

<script>
function detectServers() {
	var host = document.getElementById('detect_host').value.trim();
	var key = document.getElementById('detect_key').value;
	var user = document.getElementById('detect_user').value.trim() || 'root';
	var port = document.getElementById('detect_port').value || '22';
	var btn = document.getElementById('detect_btn');
	var status = document.getElementById('detect_status');
	var results = document.getElementById('detect_results');

	if (!host) { alert('Enter an SSH host'); return; }
	if (!key) { alert('Select an SSH key'); return; }

	btn.disabled = true;
	btn.textContent = 'Scanning...';
	status.style.display = 'block';
	status.innerHTML = '<div class="text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Creating discovery job... The agent will SSH to ' + host + ' and scan for Joinery instances.</div>';
	results.innerHTML = '';

	// POST to create the discovery job
	var formData = new FormData();
	formData.append('host', host);
	formData.append('ssh_user', user);
	formData.append('ssh_key_path', key);
	formData.append('ssh_port', port);

	fetch('/ajax/discover_nodes', { method: 'POST', body: formData })
		.then(function(r) { return r.json(); })
		.then(function(data) {
			if (!data.success) {
				btn.disabled = false;
				btn.textContent = 'Detect';
				status.innerHTML = '<div class="alert alert-danger">' + data.message + '</div>';
				return;
			}
			// Poll for job completion
			status.innerHTML = '<div class="text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Agent is scanning ' + host + '... <a href="/admin/server_manager/job_detail?job_id=' + data.job_id + '" target="_blank" class="ms-2">View job #' + data.job_id + '</a></div>';
			pollDiscoveryJob(data.job_id, host);
		})
		.catch(function(err) {
			btn.disabled = false;
			btn.textContent = 'Detect';
			status.innerHTML = '<div class="alert alert-danger">Request failed: ' + err.message + '</div>';
		});
}

function pollDiscoveryJob(jobId, host) {
	var btn = document.getElementById('detect_btn');
	var status = document.getElementById('detect_status');
	var results = document.getElementById('detect_results');

	fetch('/ajax/discover_nodes?job_id=' + jobId)
		.then(function(r) { return r.json(); })
		.then(function(data) {
			if (!data.success) {
				btn.disabled = false;
				btn.textContent = 'Detect';
				status.innerHTML = '<div class="alert alert-danger">' + (data.message || 'Poll failed') + '</div>';
				return;
			}

			if (data.status === 'pending' || data.status === 'running') {
				// Still running — poll again
				setTimeout(function() { pollDiscoveryJob(jobId, host); }, 2000);
				return;
			}

			btn.disabled = false;
			btn.textContent = 'Detect';

			if (data.status === 'failed') {
				var msg = data.error_message || 'Discovery job failed';
				status.innerHTML = '<div class="alert alert-danger"><strong>Detection failed:</strong> ' + msg + '</div>';
				return;
			}

			// Completed — show results
			if (!data.result || !data.result.instances || data.result.instances.length === 0) {
				var hostname = (data.result && data.result.hostname) ? data.result.hostname : host;
				var hasDocker = data.result && data.result.has_docker;
				status.innerHTML = '<div class="alert alert-warning">Connected to <strong>' + hostname + '</strong> but no Joinery instances were found'
					+ (hasDocker ? ' in any Docker container' : '') + '. You can still add a node manually using the form below.</div>';
				return;
			}

			var r = data.result;
			status.innerHTML = '<div class="alert alert-success">Found <strong>' + r.instances.length + '</strong> Joinery instance(s) on <strong>' + (r.hostname || host) + '</strong>' + (r.has_docker ? ' (Docker)' : '') + ':</div>';

			var html = '<div class="row">';
			r.instances.forEach(function(inst) {
				var disabled = inst.already_added ? ' disabled' : '';
				var badge = inst.already_added ? '<span class="badge bg-secondary ms-2">Already added</span>' : '';
				html += '<div class="col-md-6 col-lg-4 mb-3">'
					+ '<div class="card">'
					+ '<div class="card-body">'
					+ '<h6 class="card-title">' + inst.name + badge + '</h6>'
					+ (inst.container_name ? '<p class="mb-1"><small class="text-muted">Container: ' + inst.container_name + '</small></p>' : '')
					+ (inst.site_url ? '<p class="mb-1"><small><a href="' + inst.site_url + '" target="_blank">' + inst.site_url + '</a></small></p>' : '')
					+ (inst.version ? '<p class="mb-1"><small>Version: ' + inst.version + '</small></p>' : '')
					+ '<p class="mb-1"><small class="text-muted">' + inst.web_root + '</small></p>'
					+ '<button type="button" class="btn btn-sm btn-primary mt-2' + disabled + '"'
					+ disabled
					+ ' onclick=\'fillFromDetected(' + JSON.stringify(inst) + ', ' + JSON.stringify(r) + ')\'>'
					+ (inst.already_added ? 'Already Added' : 'Add This Node')
					+ '</button>'
					+ '</div></div></div>';
			});
			html += '</div>';
			results.innerHTML = html;
		})
		.catch(function(err) {
			// Retry on network error
			setTimeout(function() { pollDiscoveryJob(jobId, host); }, 3000);
		});
}

function fillFromDetected(inst, data) {
	// Submit a hidden form directly — one-click add
	var form = document.createElement('form');
	form.method = 'POST';
	form.action = '/admin/server_manager/nodes_edit';

	var fields = {
		'mgn_name': inst.name,
		'mgn_slug': inst.slug,
		'mgn_host': data.host,
		'mgn_ssh_user': data.ssh_user,
		'mgn_ssh_key_path': data.ssh_key_path,
		'mgn_ssh_port': data.ssh_port || 22,
		'mgn_container_name': inst.container_name || '',
		'mgn_web_root': inst.web_root,
		'mgn_site_url': inst.site_url || '',
		'mgn_enabled': '1',
	};

	for (var name in fields) {
		var input = document.createElement('input');
		input.type = 'hidden';
		input.name = name;
		input.value = fields[name];
		form.appendChild(input);
	}

	document.body.appendChild(form);
	form.submit();
}
</script>

<?php endif; // end !$is_edit ?>

<?php
$pageoptions = ['title' => $is_edit ? 'Edit Node: ' . htmlspecialchars($node->get('mgn_name')) : 'Add New Node'];
if ($is_edit) {
	$pageoptions['altlinks'] = [
		'Test Connection' => '/admin/server_manager/nodes_edit?mgn_id=' . $node->key . '&action=test_connection',
		'Check Status' => '/admin/server_manager/nodes_edit?mgn_id=' . $node->key . '&action=check_status',
	];
}
echo '<div id="node_form_box">';
$page->begin_box($pageoptions);

$formwriter = $page->getFormWriter('node_form', [
	'model' => $node,
	'edit_primary_key_value' => $node->key,
]);

echo $formwriter->begin_form();

echo '<h6 class="text-muted mt-2 mb-3">Connection Settings</h6>';

$formwriter->textinput('mgn_name', 'Display Name *', [
	'placeholder' => 'e.g., Empowered Health Production',
	'helptext' => 'A friendly name shown throughout the admin interface',
	'validation' => ['required' => true, 'maxlength' => 100],
]);

$formwriter->textinput('mgn_slug', 'Slug *', [
	'placeholder' => 'e.g., empoweredhealthtn',
	'helptext' => 'Unique short identifier (lowercase, hyphens OK). Often matches the Docker container name.',
	'validation' => ['required' => true, 'maxlength' => 50],
]);

$formwriter->textinput('mgn_host', 'SSH Host *', [
	'placeholder' => 'e.g., 23.239.11.53',
	'helptext' => 'IP address or hostname of the server the agent will SSH into',
	'validation' => ['required' => true, 'maxlength' => 255],
]);

$formwriter->textinput('mgn_ssh_user', 'SSH User', [
	'placeholder' => 'root',
	'helptext' => 'Defaults to root if left blank',
	'validation' => ['maxlength' => 50],
]);

$formwriter->textinput('mgn_ssh_key_path', 'SSH Key Path *', [
	'placeholder' => $default_ssh_key,
	'helptext' => 'Full path to the private key on this server (the control plane). The Go agent reads this key — PHP never touches it. Must not have a passphrase.',
	'validation' => ['required' => true, 'maxlength' => 500],
]);

$formwriter->numberinput('mgn_ssh_port', 'SSH Port', [
	'placeholder' => '22',
	'helptext' => 'Defaults to 22',
	'min' => 1, 'max' => 65535,
]);

echo '<h6 class="text-muted mt-4 mb-3">Docker Settings <small>(leave blank for bare-metal servers)</small></h6>';

$formwriter->textinput('mgn_container_name', 'Docker Container Name', [
	'placeholder' => 'e.g., empoweredhealthtn',
	'helptext' => 'If this server runs inside a Docker container, enter the container name. Commands will be wrapped in "docker exec". Leave blank if Joinery runs directly on the host.',
	'validation' => ['maxlength' => 100],
]);

$formwriter->textinput('mgn_container_user', 'Container User', [
	'placeholder' => 'e.g., www-data',
	'helptext' => 'Run commands as this user inside the container (passed to docker exec -u). Leave blank for default.',
	'validation' => ['maxlength' => 50],
]);

echo '<h6 class="text-muted mt-4 mb-3">Joinery Paths</h6>';

$formwriter->textinput('mgn_web_root', 'Web Root Path *', [
	'placeholder' => '/var/www/html/site/public_html',
	'helptext' => 'Path to public_html inside the server/container. Used to find version.php, config files, and run utilities.',
	'validation' => ['required' => true, 'maxlength' => 500],
]);

$formwriter->textinput('mgn_site_url', 'Site URL', [
	'placeholder' => 'e.g., https://empoweredhealthtn.com',
	'validation' => ['maxlength' => 500],
]);

$formwriter->checkboxinput('mgn_enabled', 'Enabled', [
	'checked' => $node->key ? $node->get('mgn_enabled') : true,
]);

$formwriter->textbox('mgn_notes', 'Notes', [
	'rows' => 3,
]);

$formwriter->submitbutton('btn_submit', $is_edit ? 'Save Changes' : 'Add Node');
echo $formwriter->end_form();

$page->end_box();
echo '</div>'; // close node_form_box

// If editing, show delete option
if ($is_edit && !$node->get('mgn_delete_time')) {
	echo '<div class="mt-3">';
	echo '<a href="/admin/server_manager/nodes_edit?mgn_id=' . $node->key . '&action=delete" class="btn btn-outline-danger" onclick="return confirm(\'Delete this node?\')">Delete Node</a>';
	echo '</div>';
}

$page->admin_footer();
?>
