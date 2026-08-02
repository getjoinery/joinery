<?php
/**
 * Server Manager - Node Detail (shell)
 * URL: /admin/server_manager/node_detail?mgn_id=N&tab=overview
 *
 * Thin shell: loads the node, applies the permission gate, dispatches the POST
 * action (NodeDetailActions), renders the header + tab nav, and includes the
 * one tab partial. Each tab lives in includes/node_detail_tabs/{tab}.php — under
 * includes/, not views/, so a partial is never reachable as a standalone URL
 * that would bypass this file's node loading and check_permission(10).
 *
 * @version 2.1
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/Pager.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobResultProcessor.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/NodeMonitorHealth.php'));
require_once(PathHelper::getIncludePath('includes/BackupRecoveryKey.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/SmAdminCsrf.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/SmAssets.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/logic/node_detail_actions_logic.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);
$session->set_return();

// Load node
$mgn_id = isset($_POST['edit_primary_key_value']) && $_POST['edit_primary_key_value']
	? intval($_POST['edit_primary_key_value'])
	: (isset($_GET['mgn_id']) ? intval($_GET['mgn_id']) : 0);

if (!$mgn_id) {
	header('Location: /admin/server_manager');
	exit;
}

try {
	$node = new ManagedNode($mgn_id, TRUE);
} catch (Exception $e) {
	header('Location: /admin/server_manager');
	exit;
}

$tab = isset($_GET['tab']) ? $_GET['tab'] : 'overview';
$skip_joinery = $node->get('mgn_skip_joinery_checks');
$valid_tabs = $skip_joinery
	? ['overview', 'jobs', 'api_keys']
	: ['overview', 'backups', 'database', 'updates', 'jobs', 'api_keys'];
if (!in_array($tab, $valid_tabs)) {
	$tab = 'overview';
}

$page_regex = '/\/admin\/server_manager/';
$base_url = '/admin/server_manager/node_detail?mgn_id=' . $node->key;

// ── POST action dispatch ──
// Validates CSRF once, runs the handler with uniform error handling, and
// returns a redirect URL — or null when there is no known action to handle.
$redirect = NodeDetailActions::dispatch($node, $session, $base_url, $page_regex);
if ($redirect !== null) {
	header('Location: ' . $redirect);
	exit;
}

// ── DNS publish actions (specs/dns_record_management.md) ──
// The node's site A record — the one certificate issuance waits on — is
// publishable through the shared box instead of hand-typed into a dashboard.
require_once(PathHelper::getIncludePath('includes/dns/DnsPublishBox.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/NodeDnsPlan.php'));
$dns_return_url = $base_url . '&tab=overview';
$dns_result = DnsPublishBox::handle(array_merge($_GET, $_POST), function () use ($node) {
	return NodeDnsPlan::forNode($node);
}, $dns_return_url);
if ($dns_result !== null && $dns_result->redirect !== null) {
	header('Location: ' . $dns_result->redirect);
	exit;
}
$dns_box = DnsPublishBox::build(NodeDnsPlan::forNode($node), array_merge($_GET, $_POST), $dns_return_url);

// ── Page rendering ──

$node_name = htmlspecialchars($node->get('mgn_name'));

$page = new AdminPage();
$page->admin_header([
	'menu-id' => 'server-manager',
	'page_title' => $node->get('mgn_name'),
	'readable_title' => $node->get('mgn_name'),
	'breadcrumbs' => [
		'Server Manager' => '/admin/server_manager',
		$node->get('mgn_name') => '',
	],
	'session' => $session,
]);

echo SmAssets::script_tag();
// The node name as a JS string literal (HTML/JS-safe), shared by every tab's
// confirm() dialogs so a quote or markup in the name cannot break out of an
// onclick attribute (S-3). Defined once here rather than per tab.
echo '<script>var smNodeName = '
	. json_encode($node->get('mgn_name'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)
	. ';</script>' . "\n";

// Display messages
$display_messages = $session->get_messages('/admin/server_manager');
if (!empty($display_messages)) {
	foreach ($display_messages as $msg) {
		$alert_class = 'alert-info';
		if ($msg->display_type == DisplayMessage::MESSAGE_ERROR) {
			$alert_class = 'alert-danger';
		} elseif ($msg->display_type == DisplayMessage::MESSAGE_ANNOUNCEMENT) {
			$alert_class = 'alert-success';
		}
		echo '<div class="alert ' . $alert_class . '" role="alert">';
		if ($msg->message_title) {
			echo '<strong>' . htmlspecialchars($msg->message_title) . ':</strong> ';
		}
		echo htmlspecialchars($msg->message);
		echo '<button type="button" class="alert-close" aria-label="Close">&times;</button></div>';
	}
	$session->clear_clearable_messages();
}

// ── Tab navigation ──
?>
<ul class="nav nav-tabs mb-3">
	<li class="nav-item"><a class="nav-link <?php echo $tab === 'overview' ? 'active' : ''; ?>" href="<?php echo $base_url; ?>&tab=overview">Overview</a></li>
	<?php if (!$skip_joinery): ?>
		<li class="nav-item"><a class="nav-link <?php echo $tab === 'backups' ? 'active' : ''; ?>" href="<?php echo $base_url; ?>&tab=backups">Backups</a></li>
		<li class="nav-item"><a class="nav-link <?php echo $tab === 'database' ? 'active' : ''; ?>" href="<?php echo $base_url; ?>&tab=database">Database</a></li>
		<li class="nav-item"><a class="nav-link <?php echo $tab === 'updates' ? 'active' : ''; ?>" href="<?php echo $base_url; ?>&tab=updates">Updates</a></li>
	<?php endif; ?>
	<li class="nav-item"><a class="nav-link <?php echo $tab === 'jobs' ? 'active' : ''; ?>" href="<?php echo $base_url; ?>&tab=jobs">Jobs</a></li>
	<li class="nav-item"><a class="nav-link <?php echo $tab === 'api_keys' ? 'active' : ''; ?>" href="<?php echo $base_url; ?>&tab=api_keys">API Keys</a></li>
</ul>

<?php
// ── Tab body ──
require(PathHelper::getIncludePath('plugins/server_manager/includes/node_detail_tabs/' . $tab . '.php'));

$page->admin_footer();
?>
