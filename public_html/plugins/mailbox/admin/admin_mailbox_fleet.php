<?php
/**
 * Relay fleet console (operator side).
 *
 * Run a shared relay service other deployments enroll in: switch the service
 * on with its MX zone, register/provision shards on managed nodes, and
 * publish the DNS the fleet zone needs (live resolution verdicts per row).
 * Reached from the Server Manager dashboard; tenant relay surfaces live on
 * the mailbox Setup/Settings tabs.
 *
 * @version 1.2
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/SettingsFieldRenderer.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/admin_mailbox_fleet_logic.php'));

$page_vars = process_logic(admin_mailbox_fleet_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(array(
	'menu-id' => 'incoming',
	'breadcrumbs' => array(
		'Server Manager' => '/admin/server_manager',
		'Relay Fleet' => '',
	),
	'session' => $session,
));

// --- service switch + MX zone -----------------------------------------------
$page->begin_box(array('title' => 'Relay fleet service'));

$oform = $page->getFormWriter('fleet_service_config');
echo $oform->begin_form();
$oform->hiddeninput('action', '', array('value' => 'fleet_service_config'));
SettingsFieldRenderer::renderGroup($oform, 'fleet', array(
	'source' => 'mailbox',
	'skip'   => array_diff(
		SettingsFieldRenderer::namesFor('fleet', 'mailbox'),
		array('mailbox_fleet_service_enabled', 'mailbox_fleet_mx_zone')
	),
	'values' => array(
		'mailbox_fleet_service_enabled' => !empty($fleet_service_on) ? '1' : '0',
		'mailbox_fleet_mx_zone'         => (string)($fleet_mx_zone ?? ''),
	),
));
$oform->submitbutton('btn_fleet_service', 'Save');
echo $oform->end_form();

$page->end_box();

// --- shards -------------------------------------------------------------------
if (!empty($fleet_service_on)) {
	$page->begin_box(array('title' => 'Shards'));

	if (!empty($fleet_shards)) {
		echo '<table class="table"><thead><tr>'
			. '<th>Shard</th><th>Hostname</th><th>Public IP</th><th>Tenants</th><th>Active</th>'
			. '</tr></thead><tbody>';
		foreach ($fleet_shards as $row) {
			$shard = $row['model'];
			echo '<tr>';
			echo '<td>' . htmlspecialchars((string)$shard->get('mfs_name')) . '</td>';
			echo '<td>' . htmlspecialchars((string)$shard->get('mfs_hostname')) . '</td>';
			echo '<td>' . htmlspecialchars((string)$shard->get('mfs_public_ip')) . '</td>';
			echo '<td>' . intval($row['slots']) . ' / ' . intval($shard->get('mfs_capacity')) . '</td>';
			echo '<td>' . ((bool)$shard->get('mfs_is_active') ? 'Yes' : 'No') . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		// DNS to publish: the operator's half of the tenants' MX guidance —
		// every record the fleet zone needs, with a live resolution verdict.
		echo '<h5>DNS to publish</h5>';
		echo '<table class="table"><thead><tr>'
			. '<th></th><th>Type</th><th>Name</th><th>Value</th><th>Currently</th>'
			. '</tr></thead><tbody>';
		$dns_dots = array('ok' => '🟢', 'wrong' => '🔴', 'missing' => '🔴', 'unknown' => '⚪');
		foreach ($fleet_shards as $row) {
			foreach ($row['dns'] as $dns) {
				echo '<tr>';
				echo '<td>' . ($dns_dots[$dns['state']] ?? '⚪') . '</td>';
				echo '<td>' . htmlspecialchars($dns['kind']) . '</td>';
				echo '<td>' . PublicPageBase::copy_field($dns['name']) . '</td>';
				echo '<td>' . PublicPageBase::copy_field($dns['value']) . '</td>';
				echo '<td>' . ($dns['state'] === 'ok' ? 'published'
					: ($dns['state'] === 'missing' ? 'no record'
					: ($dns['state'] === 'unknown' ? 'lookup failed'
					: htmlspecialchars($dns['found'])))) . '</td>';
				echo '</tr>';
			}
		}
		echo '</tbody></table>';
		echo '<p class="text-muted small">PTR records are set where the shard\'s IP is hosted, not in the DNS zone.</p>';
	} else {
		echo '<p>No shards yet. Provision the first one below.</p>';
	}

	if (!empty($nodes)) {
		$sform = $page->getFormWriter('provision_shard');
		echo $sform->begin_form();
		$sform->hiddeninput('action', '', array('value' => 'provision_shard'));
		$shard_node_options = array();
		foreach ($nodes as $node) {
			$shard_node_options[(string)$node->key] = $node->get('mgn_name') . ' (' . $node->get('mgn_host') . ')';
		}
		$sform->dropinput('shard_node_id', 'Managed node', array('options' => $shard_node_options));
		$sform->textinput('shard_hostname', 'Shard mail hostname', array('placeholder' => 'shard1.mx.example.com'));
		$sform->textinput('shard_capacity', 'Capacity (tenants)', array('value' => '25'));
		$sform->submitbutton('btn_provision_shard', 'Provision shard');
		echo $sform->end_form();
	} else {
		echo '<p>No managed nodes are available. Add a node in Server Manager first.</p>';
	}

	$page->end_box();

	// --- Fortress hosting product (order-time auto-enrollment) ----------------
	$page->begin_box(array('title' => 'Fortress hosting product'));
	if (empty($store_active)) {
		echo '<p>Selling fleet slots needs the store plugin. Activate it to create the Fortress hosting product.</p>';
	} elseif (!empty($fleet_products)) {
		echo '<table class="table"><thead><tr>'
			. '<th>Product</th><th>Fulfillment</th><th>Active</th><th></th>'
			. '</tr></thead><tbody>';
		foreach ($fleet_products as $fp) {
			echo '<tr>';
			echo '<td>' . htmlspecialchars($fp['name']) . '</td>';
			echo '<td>' . htmlspecialchars($fp['fulfillment'] !== '' ? $fp['fulfillment'] : 'none (tier only)') . '</td>';
			echo '<td>' . ($fp['is_active'] ? 'Yes' : 'No — not for sale yet') . '</td>';
			echo '<td><a href="/plugins/store/admin/admin_product_edit?pro_product_id=' . intval($fp['id']) . '">Edit</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p class="text-muted small">A paid order for a customer-cloud product on a fleet-slot tier builds the buyer\'s server and pre-seeds its relay enrollment — the owner\'s Setup tab lands on one-click Enroll.</p>';
	} else {
		echo '<form method="post">';
		echo '<input type="hidden" name="action" value="fleet_create_product">';
		echo '<button type="submit" class="btn btn-primary">Create Fortress hosting product</button>';
		echo '</form>';
	}
	$page->end_box();
}

$page->admin_footer();
?>
