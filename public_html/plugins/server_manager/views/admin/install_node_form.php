<?php
/**
 * Server Manager - Install New Node
 * URL: /admin/server_manager/install_node_form
 *
 * One-click node provisioning of a NEW cloud instance: creates an admin-origin
 * CustomerCloudProvision at 'ready'; the Provision Customer Cloud task births
 * the instance in the connected cloud account and dispatches the one-session
 * bootstrap from there (specs/ssh_single_bootstrap.md). An existing server is
 * not installed from here: the plane never opens SSH to a machine it did not
 * create. It is enrolled from its own Admin → System → Management Node page
 * and added on the Connect Site page.
 *
 * @version 1.8 - a bare instance is encoded as docker_mode 'docker' (it is a Docker host with no site); the
 *                builder refused the bare-metal encoding this form used, so every bare provision failed
 * @version 1.7 - cloud instances only: the existing-server target (an SSH key on a machine the plane
 *                did not create) is gone with the SSH surface it needed
 * @version 1.6 - From-backup is a clone over HTTPS from the source site (specs/ssh_single_bootstrap.md):
 *                no backup-file choice, and the domain given is the new site's own
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

if ($_POST && isset($_POST['mgn_name'])) {
	try {
		$mode        = $_POST['install_mode'] ?? 'fresh';
		$sitename    = trim($_POST['sitename'] ?? '');
		$docker_mode = $_POST['docker_mode'] ?? '';

		$is_cloud_target = true; // the only target this form has
		$is_bare         = ($mode === 'bare');

		if (!in_array($mode, ['fresh', 'from_backup', 'bare'], true)) {
			$field_errors['install_mode'] = 'Choose an install type.';
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

		$cloud_account = null;
		if ($is_cloud_target) {
			$cca_id = intval($_POST['cca_account_id'] ?? 0);
			$cloud_account = $cca_id ? new CustomerCloudAccount($cca_id, TRUE) : null;
			if (!$cloud_account || !$cloud_account->key
					|| $cloud_account->get('cca_status') !== 'active'
					|| $cloud_account->get('cca_delete_time')) {
				$field_errors['cca_account_id'] = 'Choose an active connected cloud account.';
			} elseif (CustomerCloudAccount::grant_expired($cloud_account)) {
				// A Linode grant lasts two hours and cannot be refreshed. Saying
				// so here beats a provision that parks for re-connect on its
				// first tick and emails the buyer about it.
				$field_errors['cca_account_id'] = 'That account\'s cloud grant has expired. Re-connect it at '
					. '/profile/server_manager/connect_cloud (signed in as its owner), then return here.';
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
				$field_errors['source_node_id'] = 'Source node is required for a clone.';
			} else {
				// The clone pulls from the source's web address, so the source
				// must have one, and its agent must be able to arm the export.
				$source_node = new ManagedNode($source_node_id, TRUE);
				if (!$source_node->key || !preg_match('#^https://#', (string)$source_node->get('mgn_site_url'))) {
					$field_errors['source_node_id'] = 'The source node needs an https site URL; the clone pulls from it.';
				} elseif (!JobCommandBuilder::has_primitive($source_node, 'clone_export_arm')) {
					$field_errors['source_node_id'] = 'The source node\'s agent cannot arm a clone export (needs agent 1.17.0 or later, paired). Update it first.';
				}
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
				$provision->set('cvp_docker_mode',    $is_bare ? 'docker' : $docker_mode); // a bare instance IS a Docker host; the builder refuses any other shape for it
				$provision->set('cvp_install_mode',   $mode);
				if ($mode === 'from_backup') {
					$provision->set('cvp_source_node_id', $source_node_id);
				}
				$provision->prepare();
				$provision->save();

				header('Location: /admin/server_manager/install_node_form?provision_created=' . $provision->key);
				exit;
			}

		}
	} catch (Exception $e) {
		$error = $e->getMessage();
	}
}

// Existing nodes — source options for from-backup mode and host dropdown
$existing_nodes = new MultiManagedNode(['deleted' => false, 'enabled' => true], ['mgn_name' => 'ASC']);
$existing_nodes->load();

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
	$cloud_account_options[$ca->key] = ucfirst($ca->get('cca_provider')) . ' — ' . $who
		. (CustomerCloudAccount::grant_expired($ca) ? ' (grant expired — re-connect first)' : '');
}
$has_cloud_accounts = count($cloud_account_options) > 1;

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

echo '<p class="text-muted small mb-3">A new instance is created in a connected cloud account and installed over one SSH session with a root password this '
	. 'management node seals for the length of the install; from then on the machine is reached only through its agent. '
	. 'To manage a server that already exists, add it on <a href="/admin/server_manager/node_add">Connect Site</a> and have it ask to join from its own Management Node page.</p>';

// Cloud-instance fields
echo '<div id="cloud_fields">';
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
		'from_backup' => 'Clone — pull an existing managed node\'s database, uploads, themes and plugins over HTTPS',
		'bare'        => 'Bare instance — no site install (infrastructure node, e.g. mail relay shard); cloud target only',
	],
]);

$formwriter->textinput('domain', 'Domain', [
	'required'    => true,
	'placeholder' => 'e.g., orgs.getjoinery.com',
	'helptext'    => 'Domain only — no http:// or https://. The new site\'s own domain, for a clone too. A certificate is issued during the install when DNS already points here, otherwise on its own once it does.',
	'pattern'     => '^(?!https?://).+',
]);

// Fresh install panel (empty placeholder — nothing extra needed)
echo '<div id="panel_fresh"></div>';

// Clone panel
echo '<div id="panel_backup" hidden>';
$formwriter->dropinput('source_node_id', 'Source Node', [
	'options'      => $source_node_options,
	'empty_option' => false,
	'helptext'     => 'The new machine pulls the source over HTTPS from the source site\'s own address; the source\'s agent is armed with a one-time export key first. Nothing reaches the source\'s shell.',
]);
echo '</div>';

$formwriter->submitbutton('btn_submit', 'Install');
$formwriter->addReadyScript('

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

// Wire up events
var installModeRadios = document.querySelectorAll("input[name=install_mode]");
installModeRadios.forEach(function(r) { r.addEventListener("change", toggleModePanel); });

// Initial state
toggleModePanel();
');

$formwriter->end_form();

echo '<a href="/admin/server_manager" class="btn btn-link">Cancel</a>';

$page->end_box();
?>
<?php $page->admin_footer(); ?>
