<?php
/**
 * Server Manager - Install New Node
 * URL: /admin/server_manager/install_node_form
 *
 * One-click node provisioning. Two targets:
 *   - Existing server: creates a ManagedNode, queues an install_node job,
 *     redirects to the job detail page.
 *   - New cloud instance: creates an admin-origin CustomerCloudProvision at
 *     'ready'; the Provision Customer Cloud task births the instance in the
 *     connected cloud account and dispatches the install from there.
 *
 * @version 1.5 - Bare install mode: cloud instance with no site (infrastructure nodes)
 * @version 1.4 - Cloud-instance target (admin-origin provisions)
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_account_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_provision_class.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
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

		$is_cloud_target = (($_POST['host_dropdown'] ?? '') === '__cloud__');
		$is_bare         = ($mode === 'bare');

		if (!in_array($mode, ['fresh', 'from_backup', 'bare'], true)) {
			$field_errors['install_mode'] = 'Choose an install type.';
		} elseif ($is_bare && !$is_cloud_target) {
			// A bare node with no site only makes sense for an instance this
			// form births; an existing server is added via the node form.
			$field_errors['install_mode'] = 'Bare instance is only available with the create-cloud-instance target.';
		}

		if (!$is_bare) {
			if (!$sitename) {
				$field_errors['sitename'] = 'Site name is required.';
			} elseif (!preg_match('/^[a-z0-9_]+$/', $sitename)) {
				$field_errors['sitename'] = 'Lowercase letters, numbers, and underscores only.';
			}
			if ($docker_mode !== 'docker' && $docker_mode !== 'bare-metal') {
				$field_errors['docker_mode'] = 'Choose Docker or Bare-metal.';
			}
		}

		$domain = rtrim(preg_replace('#^https?://#i', '', trim($_POST['domain'] ?? '')), '/');
		if (!$domain) {
			$field_errors['domain'] = 'Domain is required.';
		}

		$mgn_host = trim($_POST['mgn_host'] ?? '');
		if (!$is_cloud_target && !$mgn_host) {
			$field_errors['host_dropdown'] = 'Target host is required.';
		}

		$cloud_account = null;
		if ($is_cloud_target) {
			$cca_id = intval($_POST['cca_account_id'] ?? 0);
			$cloud_account = $cca_id ? new CustomerCloudAccount($cca_id, TRUE) : null;
			if (!$cloud_account || !$cloud_account->key
					|| $cloud_account->get('cca_status') !== 'active'
					|| $cloud_account->get('cca_delete_time')) {
				$field_errors['cca_account_id'] = 'Choose an active connected cloud account.';
			}
			if (trim($_POST['cloud_region'] ?? '') === '') {
				$field_errors['cloud_region'] = 'Region is required.';
			}
			if (trim($_POST['cloud_instance_type'] ?? '') === '') {
				$field_errors['cloud_instance_type'] = 'Instance type is required.';
			}
		}

		if ($mode !== 'from_backup') {
			$source_node_id = 0;
		} else {
			$source_node_id = intval($_POST['source_node_id'] ?? 0);
			if (!$source_node_id) {
				$field_errors['source_node_id'] = 'Source node is required for from-backup install.';
			}
			// A brand-new instance has no cached backups to point at — the
			// pipeline always captures a fresh source backup for cloud targets.
			if ($is_cloud_target && ($_POST['backup_source'] ?? 'new') === 'existing') {
				$field_errors['backup_source'] = 'Cloud instances install from a fresh backup — choose "Take fresh backup now".';
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

			if ($is_cloud_target) {
				// No server exists yet — record what to build and let the
				// Provision Customer Cloud task birth the instance and
				// dispatch the install from there. The provision belongs to
				// the grant owner: if the grant goes stale, they are the one
				// who can re-connect and resume it.
				$owner = new User($cloud_account->get('cca_usr_user_id'), TRUE);

				$provision = new CustomerCloudProvision(NULL);
				$provision->set('cvp_origin',         'admin');
				$provision->set('cvp_usr_user_id',    $cloud_account->get('cca_usr_user_id'));
				$provision->set('cvp_domain',         $domain);
				$provision->set('cvp_slug',           $slug);
				$provision->set('cvp_sitename',       $is_bare ? $slug : $sitename);
				$provision->set('cvp_buyer_email',    $owner->key ? $owner->get('usr_email') : '');
				$provision->set('cvp_buyer_name',     $owner->key ? trim($owner->get('usr_first_name') . ' ' . $owner->get('usr_last_name')) : '');
				$provision->set('cvp_status',         'ready');
				$provision->set('cvp_cca_account_id', $cloud_account->key);
				$provision->set('cvp_provider',       $cloud_account->get('cca_provider'));
				$provision->set('cvp_region',         trim($_POST['cloud_region']));
				$provision->set('cvp_instance_type',  trim($_POST['cloud_instance_type']));
				$provision->set('cvp_docker_mode',    $is_bare ? 'bare-metal' : $docker_mode);
				$provision->set('cvp_install_mode',   $mode);
				if ($mode === 'from_backup') {
					$provision->set('cvp_source_node_id', $source_node_id);
					$provision->set('cvp_backup_source',  'new');
				}
				$provision->prepare();
				$provision->save();

				header('Location: /admin/server_manager/install_node_form?provision_created=' . $provision->key);
				exit;
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

			// Link (or mint) the placement record. Every node names its machine
			// by mgn_mgh_host_id — sibling grouping (port allocation,
			// upgrade-all, host-scope routing) reads nothing else, so the FK is
			// set the moment the node exists rather than only when a host row
			// happened to.
			ManagedHost::ensure_for_node($node);

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

// Connected cloud accounts — target options for cloud-instance birth
$cloud_accounts = new MultiCustomerCloudAccount(['status' => 'active', 'deleted' => false]);
$cloud_accounts->load();
$cloud_account_options = ['' => '-- Select a connected account --'];
foreach ($cloud_accounts as $ca) {
	$ca_user = new User($ca->get('cca_usr_user_id'), TRUE);
	$who = $ca_user->key
		? trim($ca_user->get('usr_first_name') . ' ' . $ca_user->get('usr_last_name'))
		: ('user #' . $ca->get('cca_usr_user_id'));
	$cloud_account_options[$ca->key] = ucfirst($ca->get('cca_provider')) . ' — ' . $who;
}
$has_cloud_accounts = count($cloud_account_options) > 1;

// Build host dropdown options
$host_dropdown_options = ['' => '-- Select target --'];
foreach ($known_hosts as $kh) {
	$preview = implode(', ', array_slice($kh['slugs'], 0, 3));
	if (count($kh['slugs']) > 3) $preview .= ', +' . (count($kh['slugs']) - 3) . ' more';
	$host_dropdown_options[$kh['host']] = $kh['host'] . ' — ' . $preview;
}
$host_dropdown_options['__custom__'] = 'Other server (enter SSH details)';
$host_dropdown_options['__cloud__']  = 'Create a new cloud instance';

// Determine initial state for re-render after validation error
$post_host = $_POST['mgn_host'] ?? '';
$host_dropdown_value = '';
if (($_POST['host_dropdown'] ?? '') === '__cloud__') {
	$host_dropdown_value = '__cloud__';
} elseif ($post_host) {
	$host_dropdown_value = isset($known_hosts_map[$post_host]) ? $post_host : '__custom__';
}
$ssh_fields_hidden   = (!$host_dropdown_value || $host_dropdown_value !== '__custom__');
$cloud_fields_hidden = ($host_dropdown_value !== '__cloud__');

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
if (!empty($_GET['provision_created'])) {
	echo '<div class="alert alert-success">Cloud provision #' . intval($_GET['provision_created'])
		. ' created. The provisioning task creates the instance within a couple of minutes; the node and its install job then appear on the '
		. '<a href="/admin/server_manager" class="alert-link">Server Manager dashboard</a>. Failures alert the ops email.</div>';
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
		'cca_account_id' => $_POST['cca_account_id'] ?? '',
		'cloud_region'   => $_POST['cloud_region'] ?? (Globalvars::get_instance()->get_setting('server_manager_customer_cloud_region') ?: 'us-southeast'),
		'cloud_instance_type' => $_POST['cloud_instance_type'] ?? (Globalvars::get_instance()->get_setting('server_manager_customer_cloud_type') ?: 'g6-standard-1'),
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

$formwriter->dropinput('host_dropdown', 'Target Host', [
	'required' => true,
	'options'  => $host_dropdown_options,
]);

// SSH detail fields — hidden unless "Other server" is selected
echo '<div id="ssh_fields"' . ($ssh_fields_hidden ? ' hidden' : '') . '>';
$formwriter->textinput('mgn_host', 'SSH Host', [
	'placeholder' => '23.239.11.53 or server.example.com',
]);
$formwriter->textinput('mgn_ssh_user', 'SSH User');
$formwriter->textinput('mgn_ssh_key_path', 'SSH Key Path');
$formwriter->numberinput('mgn_ssh_port', 'SSH Port', ['min' => 1, 'max' => 65535]);
echo '</div>';

// Cloud-instance fields — shown only for the create-cloud-instance target
echo '<div id="cloud_fields"' . ($cloud_fields_hidden ? ' hidden' : '') . '>';
if ($has_cloud_accounts) {
	$formwriter->dropinput('cca_account_id', 'Connected Cloud Account', [
		'options'      => $cloud_account_options,
		'empty_option' => false,
		'helptext'     => 'The instance is created in — and billed to — this account. Linode grants expire after two hours: re-connect shortly before submitting if the connection is old.',
	]);
} else {
	echo '<div class="alert alert-info mb-3">No cloud account is connected yet. '
		. '<a href="/profile/server_manager/connect_cloud" class="alert-link">Connect a Linode account</a>, then return here.</div>';
}
$formwriter->textinput('cloud_region', 'Region', [
	'placeholder' => 'e.g., us-southeast',
]);
$formwriter->textinput('cloud_instance_type', 'Instance Type', [
	'placeholder' => 'e.g., g6-standard-1',
	'helptext'    => 'g6-nanode-1 = 1 GB, g6-standard-1 = 2 GB, g6-standard-2 = 4 GB.',
]);
echo '</div>';

// Site-defining fields — hidden for a bare instance (no site is installed)
echo '<div id="site_fields">';
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
echo '</div>';

$formwriter->radioinput('install_mode', 'Install Type', [
	'required' => true,
	'options'  => [
		'fresh'       => 'Fresh install — empty Joinery site with default schema and admin user',
		'from_backup' => 'Install from backup — clone an existing managed node via its backup',
		'bare'        => 'Bare instance — no site install (infrastructure node, e.g. mail relay shard); cloud target only',
	],
]);

$formwriter->textinput('domain', 'Domain', [
	'required'    => true,
	'placeholder' => 'e.g., orgs.getjoinery.com',
	'helptext'    => 'Domain only — no http:// or https://. SSL is configured separately after DNS cutover.',
	'pattern'     => '^(?!https?://).+',
]);

// Fresh install panel (empty placeholder — nothing extra needed)
echo '<div id="panel_fresh"></div>';

// From-backup panel
echo '<div id="panel_backup" hidden>';
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
echo '<div id="panel_existing_backup" hidden>';
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
	var fields      = document.getElementById("ssh_fields");
	var cloudFields = document.getElementById("cloud_fields");
	var dmDocker    = document.getElementById("docker_mode_docker");
	var dmBare      = document.getElementById("docker_mode_bare-metal");

	if (cloudFields) cloudFields.hidden = (val !== "__cloud__");

	if (!val || val === "__cloud__") {
		if (fields) fields.hidden = true;
		if (dmBare) { dmBare.disabled = false; dmBare.closest(".form-check").style.opacity = "1"; }
		return;
	}
	if (val === "__custom__") {
		if (fields) fields.hidden = false;
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
	var fresh  = document.querySelector("input[name=install_mode][value=fresh]");
	var backup = document.querySelector("input[name=install_mode][value=from_backup]");
	var isFresh  = fresh && fresh.checked;
	var isBackup = backup && backup.checked;
	document.getElementById("panel_fresh").hidden  = !isFresh;
	document.getElementById("panel_backup").hidden = !isBackup;
	var siteFields = document.getElementById("site_fields");
	if (siteFields) siteFields.hidden = !(isFresh || isBackup);
}

function syncBareAvailability() {
	// Bare instance only exists for the create-cloud-instance target: on any
	// other target, disable it and fall back to fresh if it was selected.
	var hostSel = document.getElementById("host_dropdown");
	var bare    = document.querySelector("input[name=install_mode][value=bare]");
	if (!hostSel || !bare) return;
	var isCloud = (hostSel.value === "__cloud__");
	bare.disabled = !isCloud;
	var wrap = bare.closest(".form-check");
	if (wrap) wrap.style.opacity = isCloud ? "1" : "0.4";
	if (!isCloud && bare.checked) {
		var fresh = document.querySelector("input[name=install_mode][value=fresh]");
		if (fresh) fresh.checked = true;
		toggleModePanel();
	}
}

function toggleBackupSourcePanel() {
	var existing = document.querySelector("input[name=backup_source][value=existing]");
	document.getElementById("panel_existing_backup").hidden = !(existing && existing.checked);
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
	hostDropdown.addEventListener("change", function() { applyHostPreset(this.value); syncBareAvailability(); });
}

// Initial state
toggleModePanel();
toggleBackupSourcePanel();
syncBareAvailability();
if (hostDropdown && hostDropdown.value && hostDropdown.value !== "__custom__") {
	applyHostPreset(hostDropdown.value);
}
');

$formwriter->end_form();

echo '<a href="/admin/server_manager" class="btn btn-link">Cancel</a>';

$page->end_box();
?>
<?php $page->admin_footer(); ?>
