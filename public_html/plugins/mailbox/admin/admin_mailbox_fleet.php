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
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
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
$oform->checkboxinput('mailbox_fleet_service_enabled', 'Run a hosted relay fleet other deployments can enroll in', array(
	'checked' => !empty($fleet_service_on),
));
$oform->textinput('mailbox_fleet_mx_zone', 'Fleet MX zone', array(
	'value'       => (string)($fleet_mx_zone ?? ''),
	'placeholder' => 'mx.example.com',
	'helptext'    => 'A DNS zone you control. Each tenant\'s MX hostname is <slug>.<zone> (slug format t<id>), published by you as an A record pointing at its shard.',
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
}

$page->admin_footer();
?>
