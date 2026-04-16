<?php
/**
 * Server Manager - Add Node
 * URL: /admin/server_manager/node_add
 *
 * Auto-detect panel and manual add form for new nodes.
 * After save, redirects to node_detail.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);
$session->set_return();

$node = new ManagedNode(NULL);

// Handle form save
$error = null;
if ($_POST && isset($_POST['mgn_name'])) {
	$editable_fields = [
		'mgn_name', 'mgn_slug', 'mgn_host', 'mgn_ssh_user', 'mgn_ssh_key_path',
		'mgn_ssh_port', 'mgn_container_name', 'mgn_container_user', 'mgn_web_root',
		'mgn_site_url', 'mgn_notes', 'mgn_enabled', 'mgn_skip_joinery_checks',
	];

	foreach ($editable_fields as $field) {
		if (isset($_POST[$field])) {
			$value = trim($_POST[$field]);
			if ($field === 'mgn_enabled' || $field === 'mgn_skip_joinery_checks') {
				$value = isset($_POST[$field]) ? true : false;
			}
			if ($field === 'mgn_ssh_port' && $value === '') {
				$value = 22;
			}
			$node->set($field, $value);
		}
	}

	if (!isset($_POST['mgn_enabled'])) {
		$node->set('mgn_enabled', false);
	}
	if (!isset($_POST['mgn_skip_joinery_checks'])) {
		$node->set('mgn_skip_joinery_checks', false);
	}

	try {
		$node->prepare();
		$node->save();
		$node->load();

		$page_regex = '/\/admin\/server_manager/';
		$session->save_message(new DisplayMessage(
			'Node added successfully.',
			'Success', $page_regex,
			DisplayMessage::MESSAGE_ANNOUNCEMENT,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
		header('Location: /admin/server_manager/node_detail?mgn_id=' . $node->key);
		exit;
	} catch (Exception $e) {
		$error = $e->getMessage();
	}
}

$default_ssh_key = '/home/user1/.ssh/id_ed25519_claude';

$page = new AdminPage();
$page->admin_header([
	'menu-id' => 'server-manager',
	'page_title' => 'Connect Site',
	'readable_title' => 'Connect Site',
	'breadcrumbs' => [
		'Server Manager' => '/admin/server_manager',
		'Connect Site' => '',
	],
	'session' => $session,
]);

if ($error) {
	echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
}

// ── Auto-detect section ──
?>

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
			setTimeout(function() { pollDiscoveryJob(jobId, host); }, 3000);
		});
}

function fillFromDetected(inst, data) {
	var form = document.createElement('form');
	form.method = 'POST';
	form.action = '/admin/server_manager/node_add';

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

<?php
// ── Manual add form ──
$pageoptions = ['title' => 'Add New Node'];
$page->begin_box($pageoptions);

$formwriter = $page->getFormWriter('node_form', [
	'model' => $node,
]);

echo $formwriter->begin_form();

echo '<h6 class="text-muted mt-2 mb-3">Connection Settings</h6>';

$formwriter->textinput('mgn_name', 'Display Name *', [
	'placeholder' => 'e.g., Empowered Health Production',
	'validation' => ['required' => true, 'maxlength' => 100],
]);

$formwriter->textinput('mgn_slug', 'Slug *', [
	'placeholder' => 'e.g., empoweredhealthtn',
	'helptext' => 'Unique short identifier (lowercase, hyphens OK). Often matches the Docker container name.',
	'validation' => ['required' => true, 'maxlength' => 50],
]);

$formwriter->textinput('mgn_host', 'SSH Host *', [
	'placeholder' => 'e.g., 23.239.11.53',
	'validation' => ['required' => true, 'maxlength' => 255],
]);

$formwriter->textinput('mgn_ssh_user', 'SSH User', [
	'placeholder' => 'root',
	'validation' => ['maxlength' => 50],
]);

$formwriter->textinput('mgn_ssh_key_path', 'SSH Key Path *', [
	'placeholder' => $default_ssh_key,
	'validation' => ['required' => true, 'maxlength' => 500],
]);

$formwriter->numberinput('mgn_ssh_port', 'SSH Port', [
	'placeholder' => '22',
	'min' => 1, 'max' => 65535,
]);

echo '<h6 class="text-muted mt-4 mb-3">Node Type</h6>';

$formwriter->checkboxinput('mgn_skip_joinery_checks', 'Skip Joinery-specific checks', [
	'helptext' => 'Check this for DNS, Redis, or other non-Joinery servers. When checked, status checks run only the generic disk/memory/load probes, and Backups/Database/Updates tabs are hidden.',
]);

echo '<h6 class="text-muted mt-4 mb-3">Docker Settings <small>(leave blank for bare-metal servers)</small></h6>';

$formwriter->textinput('mgn_container_name', 'Docker Container Name', [
	'placeholder' => 'e.g., empoweredhealthtn',
	'validation' => ['maxlength' => 100],
]);

$formwriter->textinput('mgn_container_user', 'Container User', [
	'placeholder' => 'e.g., www-data',
	'validation' => ['maxlength' => 50],
]);

echo '<h6 class="text-muted mt-4 mb-3">Joinery Paths <small>(leave blank for non-Joinery nodes)</small></h6>';

$formwriter->textinput('mgn_web_root', 'Web Root Path', [
	'placeholder' => '/var/www/html/site/public_html',
	'helptext' => 'Required for Joinery nodes. Leave blank if "Skip Joinery-specific checks" is checked.',
	'validation' => ['maxlength' => 500],
]);

$formwriter->textinput('mgn_site_url', 'Site URL', [
	'placeholder' => 'e.g., https://empoweredhealthtn.com',
	'validation' => ['maxlength' => 500],
]);

$formwriter->checkboxinput('mgn_enabled', 'Enabled', [
	'checked' => true,
]);

$formwriter->textbox('mgn_notes', 'Notes', ['rows' => 3]);

$formwriter->submitbutton('btn_submit', 'Connect Site');
echo $formwriter->end_form();

$page->end_box();
$page->admin_footer();
?>
