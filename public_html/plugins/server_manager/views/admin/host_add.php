<?php
/**
 * Server Manager - Add / Edit Host
 * URL: /admin/server_manager/host_add
 *      /admin/server_manager/host_add?mgh_id=N  (edit mode)
 *
 * @version 1.1 - host agent link (mgh_mgn_host_node_id) and a delete action; a host is
 *                deleted last, after its container sites and its own node identity
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_host_class.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);

$is_edit = isset($_GET['mgh_id']) && (int)$_GET['mgh_id'] > 0;
$host = $is_edit ? new ManagedHost((int)$_GET['mgh_id'], TRUE) : new ManagedHost(NULL);

$error = null;

// Delete action — handled before the generic save path, and returns from it.
// A host is deleted last: never while container sites still name it as their
// placement, and never while it still has a live agent node identity.
if ($_POST && ($_POST['action'] ?? '') === 'delete_host' && $is_edit) {
	try {
		if (!$host->key) {
			throw new ManagedHostException('Host not found.');
		}
		$site_count = $host->count_sites();
		if ($site_count > 0) {
			throw new ManagedHostException(
				"This host still has {$site_count} site" . ($site_count === 1 ? '' : 's') .
				" placed on it. Decommission or reassign them first."
			);
		}
		if ($host->host_node()) {
			throw new ManagedHostException(
				'This host still has a live agent node record linked. Delete the node record first.'
			);
		}
		$host->soft_delete();

		$page_regex = '/\/admin\/server_manager/';
		$session->save_message(new DisplayMessage(
			'Host deleted.',
			'Success', $page_regex,
			DisplayMessage::MESSAGE_ANNOUNCEMENT,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
		header('Location: /admin/server_manager');
		exit;
	} catch (Exception $e) {
		$error = $e->getMessage();
	}
} elseif ($_POST && isset($_POST['mgh_name'])) {
	$editable_fields = [
		'mgh_name', 'mgh_slug', 'mgh_host', 'mgh_ssh_user', 'mgh_ssh_key_path',
		'mgh_ssh_port', 'mgh_max_sites', 'mgh_provisioning_enabled', 'mgh_notes',
		'mgh_mgn_host_node_id',
	];

	foreach ($editable_fields as $field) {
		if (!isset($_POST[$field])) continue;
		$value = trim($_POST[$field]);
		if ($field === 'mgh_provisioning_enabled') {
			$value = true;
		} elseif ($field === 'mgh_ssh_port' && $value === '') {
			$value = 22;
		} elseif ($field === 'mgh_max_sites' && $value === '') {
			$value = 50;
		} elseif ($field === 'mgh_mgn_host_node_id') {
			$value = $value === '' ? null : (int)$value;
		}
		$host->set($field, $value);
	}

	if (!isset($_POST['mgh_provisioning_enabled'])) {
		$host->set('mgh_provisioning_enabled', false);
	}

	try {
		$host->prepare();
		$host->save();

		$page_regex = '/\/admin\/server_manager/';
		$session->save_message(new DisplayMessage(
			$is_edit ? 'Host updated.' : 'Host added.',
			'Success', $page_regex,
			DisplayMessage::MESSAGE_ANNOUNCEMENT,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
		header('Location: /admin/server_manager');
		exit;
	} catch (Exception $e) {
		$error = $e->getMessage();
	}
}

$page_title = $is_edit ? 'Edit Host' : 'Add Host';

$page = new AdminPage();
$page->admin_header([
	'menu-id' => 'server-manager',
	'page_title' => $page_title,
	'readable_title' => $page_title,
	'breadcrumbs' => [
		'Server Manager' => '/admin/server_manager',
		$page_title => '',
	],
	'session' => $session,
]);

if ($error) {
	echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
}

$pageoptions = ['title' => $page_title];
$page->begin_box($pageoptions);

$form_action = $is_edit ? '/admin/server_manager/host_add?mgh_id=' . $host->key : '';
$formwriter = $page->getFormWriter('host_form', ['model' => $host, 'action' => $form_action]);
echo $formwriter->begin_form();

echo '<h6 class="text-muted mt-2 mb-3">Identity</h6>';

$formwriter->textinput('mgh_name', 'Display Name *', [
	'placeholder' => 'e.g., docker-prod',
	'validation' => ['required' => true, 'maxlength' => 100],
]);

$formwriter->textinput('mgh_slug', 'Slug *', [
	'placeholder' => 'e.g., docker-prod',
	'helptext' => 'Unique short identifier (lowercase, hyphens OK).',
	'validation' => ['required' => true, 'maxlength' => 50],
]);

echo '<h6 class="text-muted mt-4 mb-3">Connection</h6>';

$formwriter->textinput('mgh_host', 'Host IP / Hostname *', [
	'placeholder' => 'e.g., 23.239.11.53',
	'helptext' => 'Must be a public IP when provisioning is enabled — sent to customers in DNS instructions.',
	'validation' => ['required' => true, 'maxlength' => 255],
]);

$formwriter->textinput('mgh_ssh_user', 'SSH User', [
	'placeholder' => 'root',
	'validation' => ['maxlength' => 50],
]);

$formwriter->textinput('mgh_ssh_key_path', 'SSH Key Path', [
	'placeholder' => '/home/user1/.ssh/id_ed25519_claude',
	'validation' => ['maxlength' => 500],
]);

$formwriter->numberinput('mgh_ssh_port', 'SSH Port', [
	'placeholder' => '22',
	'min' => 1, 'max' => 65535,
]);

echo '<h6 class="text-muted mt-4 mb-3">Provisioning</h6>';

$formwriter->numberinput('mgh_max_sites', 'Max Sites', [
	'placeholder' => '50',
	'helptext' => 'Hard cap on auto-provisioned sites for this host.',
	'min' => 1,
]);

$formwriter->checkboxinput('mgh_provisioning_enabled', 'Enable automated provisioning', [
	'helptext' => 'When checked, this host is eligible to receive new sites from the polling task. Backfilled hosts default to off.',
	'checked' => (bool)$host->get('mgh_provisioning_enabled'),
]);

echo '<h6 class="text-muted mt-4 mb-3">Host Agent</h6>';

$node_options = ['' => '— no agent node —'];
$candidate_nodes = new MultiManagedNode(['deleted' => false], ['mgn_name' => 'ASC']);
$candidate_nodes->load();
foreach ($candidate_nodes as $cn) {
	$label = $cn->get('mgn_name') . ' (' . $cn->get('mgn_slug') . ')';
	if ($cn->get('mgn_agent_paired_time')) {
		$label .= ' — paired';
	}
	$node_options[$cn->key] = $label;
}
$formwriter->dropinput('mgh_mgn_host_node_id', 'Host Agent Node', [
	'options' => $node_options,
	'helptext' => 'The paired node record that IS this machine — where host-scope work (removing a container site) is addressed. Enroll the host\'s agent first, then link its node here.',
]);

echo '<h6 class="text-muted mt-4 mb-3">Notes</h6>';

$formwriter->textbox('mgh_notes', 'Notes', ['rows' => 3]);

$formwriter->submitbutton('btn_submit', $is_edit ? 'Save Changes' : 'Add Host');
echo $formwriter->end_form();

$page->end_box();

if ($is_edit && $host->key) {
	$page->begin_box(['title' => 'Delete Host']);
	$site_count = $host->count_sites();
	$live_host_node = $host->host_node();
	if ($site_count > 0 || $live_host_node) {
		echo '<p class="text-muted mb-0">A host is deleted last. ';
		if ($site_count > 0) {
			echo 'This host still has ' . (int)$site_count . ' site' . ($site_count === 1 ? '' : 's') . ' placed on it. ';
		}
		if ($live_host_node) {
			echo 'Its agent node record (' . htmlspecialchars($live_host_node->get('mgn_slug')) . ') is still live. ';
		}
		echo 'Remove those first.</p>';
	} else {
		echo '<p class="text-muted">Removes this placement record from the dashboard. The machine itself is untouched.</p>';
		echo AdminPage::action_button('Delete Host', '/admin/server_manager/host_add?mgh_id=' . $host->key, [
			'hidden'  => ['action' => 'delete_host'],
			'confirm' => 'Delete this host record?',
			'class'   => 'btn btn-danger btn-sm',
		]);
	}
	$page->end_box();
}

$page->admin_footer();
?>
