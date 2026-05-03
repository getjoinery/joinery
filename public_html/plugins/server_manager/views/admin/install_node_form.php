<?php
/**
 * Server Manager - Install New Node
 * URL: /admin/server_manager/install_node_form
 *
 * One-click node provisioning: creates a ManagedNode, queues an install_node job,
 * redirects to the job detail page.
 *
 * @version 1.3 - Remove redundant section headings where label alone is sufficient
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);
$session->set_return();

$error = null;
$field_errors = [];
$default_ssh_key = '/home/user1/.ssh/id_ed25519_claude';

if ($_POST && isset($_POST['mgn_name'])) {
	try {
		$mode        = $_POST['install_mode'] ?? 'fresh';
		$sitename    = trim($_POST['sitename'] ?? '');
		$docker_mode = $_POST['docker_mode'] ?? '';

		if (!$sitename) {
			$field_errors['sitename'] = 'Site name is required.';
		} elseif (!preg_match('/^[a-z0-9_]+$/', $sitename)) {
			$field_errors['sitename'] = 'Lowercase letters, numbers, and underscores only.';
		}
		if ($docker_mode !== 'docker' && $docker_mode !== 'bare-metal') {
			$field_errors['docker_mode'] = 'Choose Docker or Bare-metal.';
		}

		$domain = trim($_POST['domain'] ?? '');
		if (!$domain) {
			$field_errors['domain'] = 'Domain is required.';
		}

		$mgn_host = trim($_POST['mgn_host'] ?? '');
		if (!$mgn_host) {
			$field_errors['host_dropdown'] = 'Target host is required.';
		}

		if ($mode === 'fresh') {
			$source_node_id = 0;
		} else {
			$source_node_id = intval($_POST['source_node_id'] ?? 0);
			if (!$source_node_id) {
				$field_errors['source_node_id'] = 'Source node is required for from-backup install.';
			}
		}

		if (empty($field_errors)) {
			// Generate slug from display name; append counter if collision
			$base_slug = strtolower(trim($_POST['mgn_name']));
			$base_slug = preg_replace('/[^a-z0-9]+/', '-', $base_slug);
			$base_slug = trim($base_slug, '-') ?: 'node';
			$slug      = $base_slug;
			$counter   = 2;
			$existing_check = new MultiManagedNode(['slug' => $slug, 'deleted' => false]);
			while ($existing_check->count_all() > 0) {
				$slug = $base_slug . '-' . $counter++;
				$existing_check = new MultiManagedNode(['slug' => $slug, 'deleted' => false]);
			}

			// Create the node record
			$node = new ManagedNode(NULL);
			$node->set('mgn_name', trim($_POST['mgn_name']));
			$node->set('mgn_slug', $slug);
			$node->set('mgn_host', $mgn_host);
			$node->set('mgn_ssh_user', trim($_POST['mgn_ssh_user']) ?: 'root');
			$node->set('mgn_ssh_key_path', trim($_POST['mgn_ssh_key_path']));
			$port = trim($_POST['mgn_ssh_port'] ?? '');
			$node->set('mgn_ssh_port', $port === '' ? 22 : intval($port));
			$node->set('mgn_web_root', "/var/www/html/{$sitename}/public_html");
			$node->set('mgn_site_url', "https://{$domain}");
			$node->set('mgn_enabled', true);
			$node->set('mgn_install_state', 'installing');
			$node->prepare();
			$node->save();
			$node->load();

			$params = [
				'mode'        => $mode,
				'sitename'    => $sitename,
				'domain'      => $domain,
				'docker_mode' => $docker_mode,
			];
			if ($mode === 'from_backup') {
				$params['source_node_id'] = $source_node_id;
				$params['backup_source']  = $_POST['backup_source'] ?? 'new';
				if ($params['backup_source'] === 'existing') {
					$params['db_backup_path']      = trim($_POST['db_backup_path'] ?? '');
					$params['project_backup_path'] = trim($_POST['project_backup_path'] ?? '');
				}
			}

			$steps = JobCommandBuilder::build_install_node($node, $params);
			$job   = ManagementJob::createJob($node->key, 'install_node', $steps, $params, $session->get_user_id());

			header('Location: /admin/server_manager/job_detail?job_id=' . $job->key);
			exit;
		}
	} catch (Exception $e) {
		$error = $e->getMessage();
		if (!empty($node) && $node->key) {
			$node->set('mgn_install_state', 'install_failed');
			$node->save();
		}
	}
}

// Existing nodes — source options for from-backup mode and host dropdown
$existing_nodes = new MultiManagedNode(['deleted' => false, 'enabled' => true], ['mgn_name' => 'ASC']);
$existing_nodes->load();

// Backup list data for JS, keyed by node id
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/BackupListHelper.php'));
$backup_lists = [];
foreach ($existing_nodes as $en) {
	$bl = BackupListHelper::get_for_node($en);
	$backup_lists[$en->key] = $bl['files'];
}

// Known hosts from existing nodes, grouped by SSH host IP
$known_hosts_map = [];
foreach ($existing_nodes as $en) {
	$host = $en->get('mgn_host');
	if (!$host) continue;
	if (!isset($known_hosts_map[$host])) {
		$known_hosts_map[$host] = [
			'host'         => $host,
			'ssh_user'     => $en->get('mgn_ssh_user') ?: 'root',
			'ssh_key_path' => $en->get('mgn_ssh_key_path') ?: $default_ssh_key,
			'ssh_port'     => intval($en->get('mgn_ssh_port') ?: 22),
			'slugs'        => [],
			'is_docker'    => false,
		];
	}
	$known_hosts_map[$host]['slugs'][] = $en->get('mgn_slug');
	if ($en->get('mgn_container_name')) {
		$known_hosts_map[$host]['is_docker'] = true;
	}
}
$known_hosts = array_values($known_hosts_map);

// Build source node dropdown options
$source_node_options = ['' => '-- Select source node --'];
foreach ($existing_nodes as $en) {
	$label = $en->get('mgn_name') . ' (' . (parse_url($en->get('mgn_site_url'), PHP_URL_HOST) ?: $en->get('mgn_host')) . ')';
	$source_node_options[$en->key] = $label;
}

// Build host dropdown options
$has_known_hosts = !empty($known_hosts);
$host_dropdown_options = [];
if ($has_known_hosts) {
	$host_dropdown_options[''] = '-- Select a known host --';
	foreach ($known_hosts as $kh) {
		$preview = implode(', ', array_slice($kh['slugs'], 0, 3));
		if (count($kh['slugs']) > 3) $preview .= ', +' . (count($kh['slugs']) - 3) . ' more';
		$host_dropdown_options[$kh['host']] = $kh['host'] . ' — ' . $preview;
	}
	$host_dropdown_options['__custom__'] = 'Other (enter manually)';
}

// Determine initial state for re-render after validation error
$post_host = $_POST['mgn_host'] ?? '';
$host_dropdown_value = '';
if ($post_host) {
	$host_dropdown_value = isset($known_hosts_map[$post_host]) ? $post_host : '__custom__';
}
$ssh_fields_hidden = $has_known_hosts && (!$host_dropdown_value || $host_dropdown_value !== '__custom__');

$page = new AdminPage();
$page->admin_header([
	'menu-id'        => 'server-manager',
	'page_title'     => 'Remote Install',
	'readable_title' => 'Remote Install',
	'breadcrumbs'    => [
		'Server Manager' => '/admin/server_manager',
		'Remote Install' => '',
	],
	'session' => $session,
]);

if ($error) {
	echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
}

$page->begin_box(['title' => 'New Node']);

$formwriter = $page->getFormWriter('install_form', [
	'values' => [
		'mgn_name'       => $_POST['mgn_name'] ?? '',
		'host_dropdown'  => $host_dropdown_value,
		'mgn_host'       => $_POST['mgn_host'] ?? '',
		'mgn_ssh_user'   => $_POST['mgn_ssh_user'] ?? 'root',
		'mgn_ssh_key_path' => $_POST['mgn_ssh_key_path'] ?? $default_ssh_key,
		'mgn_ssh_port'   => $_POST['mgn_ssh_port'] ?? 22,
		'sitename'       => $_POST['sitename'] ?? '',
		'docker_mode'    => $_POST['docker_mode'] ?? '',
		'install_mode'   => $_POST['install_mode'] ?? 'fresh',
		'domain'         => $_POST['domain'] ?? '',
		'source_node_id' => $_POST['source_node_id'] ?? '',
	],
]);

// Propagate server-side field errors to FormWriter
foreach ($field_errors as $field => $msg) {
	$formwriter->addError($field, $msg);
}

$formwriter->begin_form();

$formwriter->textinput('mgn_name', 'Display Name', [
	'required'    => true,
	'placeholder' => 'e.g., Getjoinery Orgs',
]);

if ($has_known_hosts) {
	$formwriter->dropinput('host_dropdown', 'Target Host', [
		'required' => true,
		'options'  => $host_dropdown_options,
	]);
}

// SSH detail fields — hidden when a known host is selected
echo '<div id="ssh_fields" style="' . ($ssh_fields_hidden ? 'display:none' : '') . '">';
$formwriter->textinput('mgn_host', 'SSH Host', [
	'placeholder' => '23.239.11.53 or server.example.com',
]);
$formwriter->textinput('mgn_ssh_user', 'SSH User');
$formwriter->textinput('mgn_ssh_key_path', 'SSH Key Path');
$formwriter->numberinput('mgn_ssh_port', 'SSH Port', ['min' => 1, 'max' => 65535]);
echo '</div>';

$formwriter->textinput('sitename', 'Site Name', [
	'required'    => true,
	'placeholder' => 'e.g., mysite',
	'helptext'    => 'Becomes the DB name and /var/www/html/SITENAME/. Lowercase letters, numbers, underscores.',
]);
$formwriter->radioinput('docker_mode', 'Deployment Mode', [
	'required' => true,
	'options'  => [
		'docker'     => 'Docker — each site in its own container',
		'bare-metal' => 'Bare-metal — Apache + PostgreSQL + PHP directly on host',
	],
]);

$formwriter->radioinput('install_mode', 'Install Type', [
	'required' => true,
	'options'  => [
		'fresh'       => 'Fresh install — empty Joinery site with default schema and admin user',
		'from_backup' => 'Install from backup — clone an existing managed node via its backup',
	],
]);

$formwriter->textinput('domain', 'Domain', [
	'required'    => true,
	'placeholder' => 'e.g., orgs.getjoinery.com',
	'helptext'    => 'SSL is not configured at install time — run certbot after DNS cutover.',
]);

// Fresh install panel (empty placeholder — nothing extra needed)
echo '<div id="panel_fresh"></div>';

// From-backup panel
echo '<div id="panel_backup" style="display:none">';
$formwriter->dropinput('source_node_id', 'Source Node', [
	'options'      => $source_node_options,
	'empty_option' => false,
]);
$formwriter->radioinput('backup_source', 'Backup to Use', [
	'options' => [
		'new'      => 'Take fresh backup now (adds a backup job as the first step)',
		'existing' => 'Use existing backup',
	],
]);
echo '<div id="panel_existing_backup" style="display:none">';
$formwriter->dropinput('db_backup_path', 'DB Backup File', ['options' => [], 'empty_option' => false]);
$formwriter->dropinput('project_backup_path', 'Project Backup File', ['options' => [], 'empty_option' => false]);
echo '<small class="text-muted d-block mb-3">Populated from the source node\'s cached backup list. If empty, run "List backups" on the source first, or choose "Take fresh backup now".</small>';
echo '</div>';
echo '</div>';

$formwriter->submitbutton('btn_submit', 'Install');
$formwriter->addReadyScript('
var BACKUP_LISTS = ' . json_encode($backup_lists) . ';
var KNOWN_HOSTS  = ' . json_encode(array_values($known_hosts_map)) . ';

function applyHostPreset(val) {
	var fields   = document.getElementById("ssh_fields");
	var dmDocker = document.getElementById("docker_mode_docker");
	var dmBare   = document.getElementById("docker_mode_bare-metal");

	if (!val) {
		if (fields) fields.style.display = "none";
		if (dmBare) { dmBare.disabled = false; dmBare.closest(".form-check").style.opacity = "1"; }
		return;
	}
	if (val === "__custom__") {
		if (fields) fields.style.display = "";
		if (dmBare) { dmBare.disabled = false; dmBare.closest(".form-check").style.opacity = "1"; }
		return;
	}
	var preset = KNOWN_HOSTS.find(function(h) { return h.host === val; });
	if (!preset) return;

	var hostEl = document.getElementById("mgn_host");
	var userEl = document.getElementById("mgn_ssh_user");
	var keyEl  = document.getElementById("mgn_ssh_key_path");
	var portEl = document.getElementById("mgn_ssh_port");
	if (hostEl) hostEl.value = preset.host;
	if (userEl) userEl.value = preset.ssh_user;
	if (keyEl)  keyEl.value  = preset.ssh_key_path;
	if (portEl) portEl.value = preset.ssh_port;

	if (fields) fields.style.display = "none";

	if (preset.is_docker) {
		if (dmDocker) dmDocker.checked = true;
		if (dmBare)   { dmBare.checked = false; dmBare.disabled = true; dmBare.closest(".form-check").style.opacity = "0.4"; }
	} else {
		if (dmBare) { dmBare.disabled = false; dmBare.closest(".form-check").style.opacity = "1"; }
	}
}

function toggleModePanel() {
	var fresh = document.querySelector("input[name=install_mode][value=fresh]");
	var isFresh = fresh && fresh.checked;
	document.getElementById("panel_fresh").style.display  = isFresh ? "" : "none";
	document.getElementById("panel_backup").style.display = isFresh ? "none" : "";
}

function toggleBackupSourcePanel() {
	var existing = document.querySelector("input[name=backup_source][value=existing]");
	document.getElementById("panel_existing_backup").style.display = (existing && existing.checked) ? "" : "none";
}

function updateBackupOptions() {
	var sourceId = document.querySelector("select[name=source_node_id]");
	sourceId = sourceId ? sourceId.value : "";
	var dbSel   = document.getElementById("db_backup_path");
	var projSel = document.getElementById("project_backup_path");
	if (!dbSel || !projSel) return;
	dbSel.innerHTML = "";
	projSel.innerHTML = "";
	if (!sourceId || !BACKUP_LISTS[sourceId]) {
		dbSel.innerHTML   = "<option value=\"\">No backups cached</option>";
		projSel.innerHTML = "<option value=\"\">No backups cached</option>";
		return;
	}
	var files = BACKUP_LISTS[sourceId];
	files.forEach(function(f) {
		if (!f.local_path) return;
		var opt = document.createElement("option");
		opt.value = f.local_path;
		opt.textContent = f.filename + " (" + f.size + ", " + f.date + ")";
		if (/\\.sql\\.gz(\\.enc)?$/.test(f.filename)) {
			dbSel.appendChild(opt);
		} else if (/\\.tar\\.gz$/.test(f.filename)) {
			projSel.appendChild(opt);
		}
	});
	if (!dbSel.children.length)   dbSel.innerHTML   = "<option value=\"\">No DB backups found</option>";
	if (!projSel.children.length) projSel.innerHTML = "<option value=\"\">No project backups found</option>";
}

// Wire up events
var installModeRadios = document.querySelectorAll("input[name=install_mode]");
installModeRadios.forEach(function(r) { r.addEventListener("change", toggleModePanel); });

var backupSourceRadios = document.querySelectorAll("input[name=backup_source]");
backupSourceRadios.forEach(function(r) { r.addEventListener("change", toggleBackupSourcePanel); });

var sourceNodeSel = document.querySelector("select[name=source_node_id]");
if (sourceNodeSel) sourceNodeSel.addEventListener("change", updateBackupOptions);

var hostDropdown = document.getElementById("host_dropdown");
if (hostDropdown) {
	hostDropdown.addEventListener("change", function() { applyHostPreset(this.value); });
}

// Initial state
toggleModePanel();
toggleBackupSourcePanel();
if (hostDropdown && hostDropdown.value && hostDropdown.value !== "__custom__") {
	applyHostPreset(hostDropdown.value);
}
');

$formwriter->end_form();

echo '<a href="/admin/server_manager" class="btn btn-link">Cancel</a>';

$page->end_box();
?>
<?php $page->admin_footer(); ?>
