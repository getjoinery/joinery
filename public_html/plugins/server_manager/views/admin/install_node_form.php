<?php
/**
 * Server Manager - Install New Node
 * URL: /admin/server_manager/install_node_form
 *
 * One-click node provisioning: creates a ManagedNode, queues an install_node job,
 * redirects to the job detail page.
 *
 * @version 1.1 - Host dropdown pre-fills SSH fields from existing nodes; raw fields moved to Advanced
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);
$session->set_return();

$error = null;
$default_ssh_key = '/home/user1/.ssh/id_ed25519_claude';

if ($_POST && isset($_POST['mgn_name'])) {
	try {
		$mode = $_POST['install_mode'] ?? 'fresh';
		$sitename = trim($_POST['sitename'] ?? '');
		$docker_mode = $_POST['docker_mode'] ?? '';

		if (!$sitename) {
			throw new Exception('Site name is required.');
		}
		if (!preg_match('/^[a-z0-9_]+$/', $sitename)) {
			throw new Exception('Site name must be lowercase letters, numbers, or underscores.');
		}
		if ($docker_mode !== 'docker' && $docker_mode !== 'bare-metal') {
			throw new Exception('Choose Docker or Bare-metal.');
		}

		// Determine domain — always required regardless of mode
		$domain = trim($_POST['domain'] ?? '');
		if (!$domain) {
			throw new Exception('Domain is required.');
		}

		if ($mode === 'fresh') {
			$source_node_id = 0;
		} else {
			$source_node_id = intval($_POST['source_node_id'] ?? 0);
			if (!$source_node_id) {
				throw new Exception('Source node is required for from-backup install.');
			}
		}

		// Generate slug from display name; append counter if collision
		$base_slug = strtolower(trim($_POST['mgn_name']));
		$base_slug = preg_replace('/[^a-z0-9]+/', '-', $base_slug);
		$base_slug = trim($base_slug, '-') ?: 'node';
		$slug = $base_slug;
		$counter = 2;
		$existing_check = new MultiManagedNode(['slug' => $slug, 'deleted' => false]);
		while ($existing_check->count_all() > 0) {
			$slug = $base_slug . '-' . $counter++;
			$existing_check = new MultiManagedNode(['slug' => $slug, 'deleted' => false]);
		}

		// Create the node record
		$node = new ManagedNode(NULL);
		$node->set('mgn_name', trim($_POST['mgn_name']));
		$node->set('mgn_slug', $slug);
		$node->set('mgn_host', trim($_POST['mgn_host']));
		$node->set('mgn_ssh_user', trim($_POST['mgn_ssh_user']) ?: 'root');
		$node->set('mgn_ssh_key_path', trim($_POST['mgn_ssh_key_path']));
		$port = trim($_POST['mgn_ssh_port'] ?? '');
		$node->set('mgn_ssh_port', $port === '' ? 22 : intval($port));
		// Leave mgn_container_name blank during install — install.sh runs on host.
		// Admin can populate it post-install if running in Docker mode.
		$node->set('mgn_web_root', "/var/www/html/{$sitename}/public_html");
		$node->set('mgn_site_url', "https://{$domain}");
		$node->set('mgn_enabled', true);
		$node->set('mgn_install_state', 'installing');
		$node->prepare();
		$node->save();
		$node->load();

		// Build job params
		$params = [
			'mode' => $mode,
			'sitename' => $sitename,
			'domain' => $domain,
			'docker_mode' => $docker_mode,
		];
		if ($mode === 'from_backup') {
			$params['source_node_id'] = $source_node_id;
			$params['backup_source'] = $_POST['backup_source'] ?? 'new';
			if ($params['backup_source'] === 'existing') {
				$params['db_backup_path'] = trim($_POST['db_backup_path'] ?? '');
				$params['project_backup_path'] = trim($_POST['project_backup_path'] ?? '');
			}
		}

		$steps = JobCommandBuilder::build_install_node($node, $params);
		$job = ManagementJob::createJob($node->key, 'install_node', $steps, $params, $session->get_user_id());

		header('Location: /admin/server_manager/job_detail?job_id=' . $job->key);
		exit;
	} catch (Exception $e) {
		$error = $e->getMessage();
		// If we created a node but failed to queue the job, mark it install_failed
		if (!empty($node) && $node->key) {
			$node->set('mgn_install_state', 'install_failed');
			$node->save();
		}
	}
}

// Existing nodes — used as source options for from-backup mode and host dropdown
$existing_nodes = new MultiManagedNode(['deleted' => false, 'enabled' => true], ['mgn_name' => 'ASC']);
$existing_nodes->load();

// Build backup list data for JS, keyed by node id
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/BackupListHelper.php'));
$backup_lists = [];
foreach ($existing_nodes as $en) {
	$bl = BackupListHelper::get_for_node($en);
	$backup_lists[$en->key] = $bl['files'];
}

// Build known hosts from existing nodes, grouped by SSH host IP
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

$page = new AdminPage();
$page->admin_header([
	'menu-id' => 'server-manager',
	'page_title' => 'Remote Install',
	'readable_title' => 'Remote Install',
	'breadcrumbs' => [
		'Server Manager' => '/admin/server_manager',
		'Remote Install' => '',
	],
	'session' => $session,
]);

if ($error) {
	echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
}
?>

<form method="post" class="p-3">

	<label class="form-label fw-semibold d-block">New Site Info</label>

	<div class="row g-3 mb-3">
		<div class="col-md-6">
			<label class="form-label">Display Name *</label>
			<input type="text" name="mgn_name" class="form-control" required maxlength="100"
				value="<?php echo htmlspecialchars($_POST['mgn_name'] ?? ''); ?>"
				placeholder="e.g., Getjoinery Orgs">
		</div>
	</div>

	<label class="form-label fw-semibold d-block mt-4">Target Host</label>

	<?php if (!empty($known_hosts)): ?>
	<div class="mb-3">
		<select id="host_dropdown" class="form-select" onchange="applyHostPreset()">
			<option value="">-- Select a known host --</option>
			<?php foreach ($known_hosts as $kh):
				$preview = implode(', ', array_slice($kh['slugs'], 0, 3));
				if (count($kh['slugs']) > 3) $preview .= ', +' . (count($kh['slugs']) - 3) . ' more';
				$selected = (($_POST['mgn_host'] ?? '') === $kh['host']) ? 'selected' : '';
			?>
				<option value="<?php echo htmlspecialchars($kh['host']); ?>" <?php echo $selected; ?>>
					<?php echo htmlspecialchars($kh['host']); ?> — <?php echo htmlspecialchars($preview); ?>
				</option>
			<?php endforeach; ?>
			<option value="__custom__" <?php echo (!empty($_POST['mgn_host']) && !isset($known_hosts_map[$_POST['mgn_host']])) ? 'selected' : ''; ?>>Other (enter manually)</option>
		</select>
	</div>
	<?php endif; ?>

	<div id="ssh_fields" style="display:<?php echo (!empty($known_hosts) && empty($_POST)) ? 'none' : 'block'; ?>">
		<div class="row g-3 mb-3">
				<div class="col-md-5">
					<label class="form-label">SSH Host *</label>
					<input type="text" id="mgn_host" name="mgn_host" class="form-control" required
						value="<?php echo htmlspecialchars($_POST['mgn_host'] ?? ''); ?>"
						placeholder="23.239.11.53 or server.example.com">
				</div>
				<div class="col-md-3">
					<label class="form-label">SSH User</label>
					<input type="text" id="mgn_ssh_user" name="mgn_ssh_user" class="form-control"
						value="<?php echo htmlspecialchars($_POST['mgn_ssh_user'] ?? 'root'); ?>">
				</div>
				<div class="col-md-3">
					<label class="form-label">SSH Key Path *</label>
					<input type="text" id="mgn_ssh_key_path" name="mgn_ssh_key_path" class="form-control" required
						value="<?php echo htmlspecialchars($_POST['mgn_ssh_key_path'] ?? $default_ssh_key); ?>">
				</div>
				<div class="col-md-1">
					<label class="form-label">Port</label>
					<input type="number" id="mgn_ssh_port" name="mgn_ssh_port" class="form-control"
						value="<?php echo intval($_POST['mgn_ssh_port'] ?? 22); ?>" min="1" max="65535">
				</div>
		</div>
	</div>

	<div class="row g-3 mt-4 mb-3">
		<div class="col-md-6">
			<label class="form-label">Site Name *</label>
			<input type="text" name="sitename" class="form-control" required pattern="[a-z0-9_]+" placeholder="e.g., mysite">
			<small class="text-muted">Becomes DB name and <code>/var/www/html/SITENAME/</code>. Lowercase letters, numbers, underscores.</small>
		</div>
		<div class="col-md-6">
			<label class="form-label">Deployment Mode *</label>
			<div class="form-check">
				<input class="form-check-input" type="radio" name="docker_mode" id="dm_docker" value="docker" required>
				<label class="form-check-label" for="dm_docker"><strong>Docker</strong> — each site in its own container (<code>install.sh docker</code> bootstraps Docker if missing)</label>
			</div>
			<div class="form-check">
				<input class="form-check-input" type="radio" name="docker_mode" id="dm_bare" value="bare-metal">
				<label class="form-check-label" for="dm_bare"><strong>Bare-metal</strong> — Apache + PostgreSQL + PHP directly on host (<code>install.sh server</code> installs prereqs if missing)</label>
			</div>
		</div>
	</div>


<label class="form-label fw-semibold d-block mt-4">Install Type</label>

	<div class="mb-3">
		<div class="form-check">
			<input class="form-check-input" type="radio" name="install_mode" id="mode_fresh" value="fresh" checked onchange="toggleModePanel()">
			<label class="form-check-label" for="mode_fresh"><strong>Fresh install</strong> — empty Joinery site with default schema and admin user</label>
		</div>
		<div class="form-check">
			<input class="form-check-input" type="radio" name="install_mode" id="mode_backup" value="from_backup" onchange="toggleModePanel()">
			<label class="form-check-label" for="mode_backup"><strong>Install from backup</strong> — clone an existing managed node via its backup</label>
		</div>
	</div>

	<div class="mb-3">
		<label class="form-label">Domain *</label>
		<input type="text" name="domain" class="form-control" required
			value="<?php echo htmlspecialchars($_POST['domain'] ?? ''); ?>"
			placeholder="e.g., orgs.getjoinery.com">
		<small class="text-muted">SSL is not configured at install time — run certbot after DNS cutover.</small>
	</div>

	<div id="panel_fresh" class="mb-3" style="display:none"></div>

	<div id="panel_backup" class="mb-3" style="display:none">
		<label class="form-label">Source Node *</label>
		<select name="source_node_id" class="form-select mb-2" required onchange="updateBackupOptions()">
			<option value="">-- Select source node --</option>
			<?php foreach ($existing_nodes as $en): ?>
				<option value="<?php echo $en->key; ?>"><?php echo htmlspecialchars($en->get('mgn_name')); ?> (<?php echo htmlspecialchars(parse_url($en->get('mgn_site_url'), PHP_URL_HOST) ?: $en->get('mgn_host')); ?>)</option>
			<?php endforeach; ?>
		</select>

		<div class="form-check">
			<input class="form-check-input" type="radio" name="backup_source" id="bkp_new" value="new" checked onchange="toggleBackupSourcePanel()">
			<label class="form-check-label" for="bkp_new">Take fresh backup now (adds a backup job as the first step)</label>
		</div>
		<div class="form-check mb-2">
			<input class="form-check-input" type="radio" name="backup_source" id="bkp_existing" value="existing" onchange="toggleBackupSourcePanel()">
			<label class="form-check-label" for="bkp_existing">Use existing backup</label>
		</div>

		<div id="panel_existing_backup" style="display:none">
			<div class="row g-2">
				<div class="col-md-6">
					<label class="form-label">DB backup file</label>
					<select name="db_backup_path" id="db_backup_select" class="form-select"></select>
				</div>
				<div class="col-md-6">
					<label class="form-label">Project backup file</label>
					<select name="project_backup_path" id="project_backup_select" class="form-select"></select>
				</div>
			</div>
			<small class="text-muted">Populated from the source node's cached backup list. If empty, run "List backups" on the source first, or choose "Take fresh backup now".</small>
		</div>
	</div>

	<button type="submit" class="btn btn-primary">Install</button>
	<a href="/admin/server_manager" class="btn btn-link">Cancel</a>
</form>

<script>
var BACKUP_LISTS = <?php echo json_encode($backup_lists); ?>;
var KNOWN_HOSTS  = <?php echo json_encode(array_values($known_hosts_map)); ?>;

function applyHostPreset() {
	var sel    = document.getElementById('host_dropdown');
	var val    = sel ? sel.value : '';
	var fields = document.getElementById('ssh_fields');
	var dmDocker = document.getElementById('dm_docker');
	var dmBare   = document.getElementById('dm_bare');

	if (!val || val === '__custom__') {
		if (fields) fields.style.display = 'block';
		dmBare.disabled = false;
		dmBare.closest('.form-check').style.opacity = '1';
		return;
	}

	var preset = KNOWN_HOSTS.find(function(h) { return h.host === val; });
	if (!preset) return;

	document.getElementById('mgn_host').value         = preset.host;
	document.getElementById('mgn_ssh_user').value     = preset.ssh_user;
	document.getElementById('mgn_ssh_key_path').value = preset.ssh_key_path;
	document.getElementById('mgn_ssh_port').value     = preset.ssh_port;

	if (fields) fields.style.display = 'none';

	if (preset.is_docker) {
		dmDocker.checked = true;
		dmBare.checked   = false;
		dmBare.disabled  = true;
		dmBare.closest('.form-check').style.opacity = '0.4';
	} else {
		dmBare.disabled = false;
		dmBare.closest('.form-check').style.opacity = '1';
	}
}

document.addEventListener('DOMContentLoaded', function() {
	var sel = document.getElementById('host_dropdown');
	if (sel && sel.value && sel.value !== '__custom__') applyHostPreset();
});

function toggleModePanel() {
	var fresh = document.getElementById('mode_fresh').checked;
	document.getElementById('panel_fresh').style.display  = fresh ? 'block' : 'none';
	document.getElementById('panel_backup').style.display = fresh ? 'none'  : 'block';
	var srcSelect = document.querySelector('select[name=source_node_id]');
	if (srcSelect) srcSelect.required = !fresh;
}

function toggleBackupSourcePanel() {
	var existing = document.getElementById('bkp_existing').checked;
	document.getElementById('panel_existing_backup').style.display = existing ? 'block' : 'none';
}

function updateBackupOptions() {
	var sourceId = document.querySelector('select[name=source_node_id]').value;
	var db_sel = document.getElementById('db_backup_select');
	var proj_sel = document.getElementById('project_backup_select');
	db_sel.innerHTML = '';
	proj_sel.innerHTML = '';
	if (!sourceId || !BACKUP_LISTS[sourceId]) {
		db_sel.innerHTML = '<option value="">No backups cached</option>';
		proj_sel.innerHTML = '<option value="">No backups cached</option>';
		return;
	}
	var files = BACKUP_LISTS[sourceId];
	files.forEach(function(f) {
		if (!f.local_path) return;
		var opt = document.createElement('option');
		opt.value = f.local_path;
		opt.textContent = f.filename + ' (' + f.size + ', ' + f.date + ')';
		if (/\.sql\.gz(\.enc)?$/.test(f.filename)) {
			db_sel.appendChild(opt);
		} else if (/\.tar\.gz$/.test(f.filename)) {
			proj_sel.appendChild(opt);
		}
	});
	if (!db_sel.children.length)   db_sel.innerHTML   = '<option value="">No DB backups found</option>';
	if (!proj_sel.children.length) proj_sel.innerHTML = '<option value="">No project backups found</option>';
}
</script>

<?php $page->admin_footer(); ?>
