<?php
/**
 * Server Manager - Add Node
 * URL: /admin/server_manager/node_add
 *
 * The manual add form for a node that already exists. A node this plane did
 * not build is enrolled from the node's own Admin → System → Management Node
 * page, where its agent asks to join; the record here is what that join is
 * approved against. There is no discovery scan: the plane never needs a shell
 * on someone else's machine (specs/ssh_single_bootstrap.md).
 * After save, redirects to node_detail.
 *
 * @version 1.5 - the auto-detect (SSH discovery) panel is gone; enrollment starts on the node
 * @version 1.4 - CSRF on the save handler; tcp_port check requires a port at save time
 * @version 1.3
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/SmAdminCsrf.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);
$session->set_return();

$node = new ManagedNode(NULL);

// Handle form save
$error = null;
if ($_POST && isset($_POST['mgn_name'])) {
	// A forged cross-origin POST must not be able to plant a node record
	// pointed at attacker infrastructure.
	if (!SmAdminCsrf::valid()) { header('Location: /admin/server_manager/node_add'); exit; }
	$editable_fields = [
		'mgn_name', 'mgn_slug', 'mgn_host', 'mgn_ssh_user', 'mgn_ssh_key_path',
		'mgn_ssh_port', 'mgn_container_name', 'mgn_container_user', 'mgn_web_root',
		'mgn_site_url', 'mgn_health_check_url', 'mgn_notes', 'mgn_enabled', 'mgn_skip_joinery_checks',
		'mgn_uptime_enabled', 'mgn_uptime_check_type', 'mgn_uptime_tcp_port',
	];

	foreach ($editable_fields as $field) {
		if (isset($_POST[$field])) {
			$value = trim($_POST[$field]);
			if ($field === 'mgn_enabled' || $field === 'mgn_skip_joinery_checks' || $field === 'mgn_uptime_enabled') {
				$value = isset($_POST[$field]) ? true : false;
			}
			if ($field === 'mgn_ssh_port' && $value === '') {
				$value = 22;
			}
			// TCP port is only filled for tcp_port checks; an empty value must fall
			// back to the column default (0), never NULL — the column is NOT NULL.
			if ($field === 'mgn_uptime_tcp_port' && $value === '') {
				$value = 0;
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
	if (!isset($_POST['mgn_uptime_enabled'])) {
		$node->set('mgn_uptime_enabled', false);
	}

	try {
		// A TCP check with no port would save port 0 and only surface later as a
		// dashboard "Monitoring misconfigured" row — reject it at the form.
		if ($node->get('mgn_uptime_enabled')
			&& $node->get('mgn_uptime_check_type') === 'tcp_port'
			&& (int)$node->get('mgn_uptime_tcp_port') < 1) {
			throw new Exception('A TCP port is required when the check type is TCP port.');
		}
		$node->prepare();
		$node->save();
		$node->load();

		// Link (or mint) the placement record. Every node names its machine by
		// mgn_mgh_host_id — sibling grouping (port allocation, upgrade-all,
		// host-scope routing) reads nothing else, so the FK is set the moment
		// the node exists rather than only when a host row happened to.
		ManagedHost::ensure_for_node($node);

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

// ── How a node gets here ──
echo '<div class="alert alert-info mb-4"><strong>Connecting a site this management node did not install:</strong> '
   . 'add its record below, then on the node open <em>Admin &rarr; System &rarr; Management Node</em> and enter '
   . '<code>' . htmlspecialchars(rtrim(LibraryFunctions::get_absolute_url(), '/')) . '</code>. '
   . 'The node\'s agent asks to join and the request appears on the node\'s API keys tab here for approval. '
   . 'No key is copied in either direction.</div>';

// ── Manual add form ──
$pageoptions = ['title' => 'Add New Node'];
$page->begin_box($pageoptions);

$formwriter = $page->getFormWriter('node_form', [
	'model' => $node,
]);

echo $formwriter->begin_form();
echo SmAdminCsrf::field();

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

$formwriter->textinput('mgn_ssh_key_path', 'SSH Key Path', [
	'helptext' => 'Only for a relay or DNS box this plane reaches by hand. A managed site is reached through its agent and needs none.',
	'validation' => ['maxlength' => 500],
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

$formwriter->textinput('mgn_health_check_url', 'Health check URL', [
	'placeholder' => 'e.g., https://dns.scrolldaddy.app/health',
	'helptext' => 'Optional. Absolute URL probed for uptime checks instead of the site root. Use for non-Joinery nodes or services that expose a dedicated health endpoint.',
	'validation' => ['maxlength' => 500],
]);

$formwriter->checkboxinput('mgn_enabled', 'Enabled', [
	'checked' => true,
]);

echo '<h6 class="text-muted mt-4 mb-3">Uptime Monitoring</h6>';

$formwriter->checkboxinput('mgn_uptime_enabled', 'Monitor uptime', [
	'checked' => true,
	'helptext' => 'When checked, the node is probed on its own interval. Down/recovered transitions trigger an email alert.',
]);

$formwriter->dropinput('mgn_uptime_check_type', 'Check type', [
	'options' => [
		'http_status' => 'HTTP status (plain GET, any 2xx/3xx is up)',
		'api'         => 'API probe (authenticated /api/v1/management/stats)',
		'tcp_port'    => 'TCP port (connection accepted means up)',
	],
	'value'    => 'http_status',
	'helptext' => 'HTTP status works with no setup — any Joinery site with a URL concludes up or down immediately. API probe gives richer info but requires API keys provisioned on the node; without them the check cannot conclude and the node is reported as misconfigured. TCP port suits services with no web endpoint, such as a mail relay. When "Skip Joinery-specific checks" is on, an API probe falls back to HTTP status; an explicitly chosen HTTP or TCP check is left alone.',
	'visibility_rules' => [
		'mgn_uptime_tcp_port' => ['tcp_port'],
	],
]);

$formwriter->numberinput('mgn_uptime_tcp_port', 'TCP port', [
	'min'      => 1,
	'max'      => 65535,
	'helptext' => 'Port to connect to on this node\'s host address. 25 for an inbound mail relay.',
]);

$formwriter->textbox('mgn_notes', 'Notes', ['rows' => 3]);

$formwriter->submitbutton('btn_submit', 'Connect Site');
echo $formwriter->end_form();

$page->end_box();
$page->admin_footer();
?>
