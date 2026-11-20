<?php
/** @joinery-admin class=config */
/**
 * Relay fleet console (operator side).
 *
 * Run a shared relay service other deployments enroll in: switch the service
 * on with its MX zone, register/provision shards on managed nodes, and
 * publish the DNS the fleet zone needs (live resolution verdicts per row).
 * Reached from the Server Manager dashboard; tenant relay surfaces live on
 * the mailbox Setup/Settings tabs.
 *
 * @version 1.9 - Move tenants to another shard, and the moves in progress; the page shows what its last action did
 * @version 1.8 - each shard's capacity is editable in its row
 * @version 1.7 - the Relay section link opens the Server tab
 * @version 1.6 - per-shard Retire: the shard and its managed node leave together
 * @version 1.5 - the shard region defaults to us-east
 * @version 1.4 - the fleet product box is Relay Hosting
 * @version 1.3 - shard relay version column + per-shard Rebuild
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

// What the last action here did or refused, once.
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/relay_section.php'));
echo mailbox_relay_notice_html();

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
		// Version is the operator's half of the promise the tenant surface makes:
		// a hosted slot tells its tenant the relay is the operator's to keep
		// current, which needs somewhere the operator can see where it stands.
		// A shard holds every tenant's undrained mail, so nothing here re-images
		// one; a new shard is born and tenants move to it.
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayVersion.php'));
		$shipped = RelayVersion::shipped();
		echo '<table class="table"><thead><tr>'
			. '<th>Shard</th><th>Hostname</th><th>Public IP</th><th>Identity pin</th><th>Tenants</th><th>Active</th>'
			. '<th>Relay version</th><th></th>'
			. '</tr></thead><tbody>';
		foreach ($fleet_shards as $row) {
			$shard = $row['model'];
			$running  = trim((string)$shard->get('mfs_provisioned_version'));
			$standing = RelayVersion::compare($running, $shipped);
			echo '<tr>';
			echo '<td>' . htmlspecialchars((string)$shard->get('mfs_name')) . '</td>';
			echo '<td>' . htmlspecialchars((string)$shard->get('mfs_hostname')) . '</td>';
			echo '<td>' . htmlspecialchars((string)$shard->get('mfs_public_ip')) . '</td>';
			$pin = (string)$shard->get('mfs_identity_fingerprint');
			echo '<td><code>' . htmlspecialchars(substr($pin, 0, 16)) . ($pin !== '' ? '…' : '') . '</code></td>';
			// Tenants held, and the number it takes: editable, since a full
			// fleet refuses every new site.
			echo '<td>' . intval($row['slots']) . ' of ';
			$cform = $page->getFormWriter('shard_capacity_' . intval($shard->key), array('class' => 'd-inline'));
			echo $cform->begin_form();
			$cform->hiddeninput('action', '', array('value' => 'shard_capacity'));
			$cform->hiddeninput('mfs_mailbox_fleet_shard_id', '', array('value' => intval($shard->key)));
			$cform->textinput('shard_capacity', '', array('value' => (string)intval($shard->get('mfs_capacity')), 'maxlength' => 4));
			$cform->submitbutton('btn_shard_capacity_' . intval($shard->key), 'Set', array('class' => 'btn btn-sm btn-outline-secondary'));
			echo $cform->end_form();
			if (!empty($row['leaving'])) {
				echo '<div class="text-muted small">' . intval($row['leaving']) . ' moved off, still collecting from here</div>';
			}
			echo '</td>';
			echo '<td>' . ((bool)$shard->get('mfs_is_active') ? 'Yes' : 'No') . '</td>';
			switch ($standing) {
				case RelayVersion::CURRENT:
					$version_cell = htmlspecialchars($running); break;
				case RelayVersion::BEHIND:
					$version_cell = '<span class="text-danger">' . htmlspecialchars($running)
						. '</span> <span class="text-muted">(ships ' . htmlspecialchars($shipped) . ')</span>'; break;
				case RelayVersion::AHEAD:
					$version_cell = htmlspecialchars($running)
						. ' <span class="text-muted">(newer than this site)</span>'; break;
				default:
					// A shard that has not reported in. Unknown must never read
					// as up to date — that is the whole point of showing this.
					$version_cell = '<span class="text-muted">Unknown</span>';
			}
			echo '<td>' . $version_cell . '</td>';
			// Retiring is refused while tenants hold slots here; the button
			// stays, and the refusal says what to do first.
			echo '<td>' . AdminPage::action_button('Retire', '/plugins/mailbox/admin/admin_mailbox_fleet', array(
				'hidden'        => array('action' => 'retire_shard', 'mfs_mailbox_fleet_shard_id' => intval($shard->key)),
				'confirm'       => 'Retire shard ' . (string)$shard->get('mfs_name') . '? It leaves this list and its node is hidden '
					. 'from the dashboard, so nothing monitors it any more. Its server is not touched: destroy it in the cloud account yourself.',
				'confirm_label' => 'Retire',
				'confirm_style' => 'danger',
				'class'         => 'btn btn-sm btn-outline-danger',
			)) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		// Renewing a shard: its tenants move to another, then it retires.
		$move_sources = array();
		$move_targets = array();
		foreach ($fleet_shards as $row) {
			if (intval($row['slots']) > 0 && !empty($row['targets'])) {
				$move_sources[intval($row['model']->key)] = (string)$row['model']->get('mfs_name') . ' (' . intval($row['slots']) . ')';
				$move_targets += $row['targets'];
			}
		}
		if ($move_sources) {
			echo '<h5>Move tenants to another shard</h5>';
			echo '<p class="text-muted small">How a shard is renewed: create the new shard, move the tenants to it, then retire the old one. '
				. 'No mail is lost: the old shard keeps each tenant until its site has collected what arrived there.</p>';
			$mform = $page->getFormWriter('shard_move');
			echo $mform->begin_form();
			$mform->hiddeninput('action', '', array('value' => 'move_shard_tenants'));
			$mform->dropinput('mfs_mailbox_fleet_shard_id', 'From (tenants)', array('options' => $move_sources));
			$mform->dropinput('move_to_shard_id', 'To', array('options' => $move_targets));
			$mform->submitbutton('btn_shard_move', 'Move tenants');
			echo $mform->end_form();
		}

		// Tenants part-way to another shard, and what each is waiting for.
		if (!empty($fleet_moves)) {
			echo '<h5>Moves in progress</h5>';
			echo '<p class="text-muted small">A moved tenant\'s site collects from both shards until the old one is empty. '
				. 'Nothing here needs doing except pointing each mail name at its new shard when its row says so.</p>';
			echo '<table class="table"><thead><tr><th>Tenant</th><th>Mail name</th><th>From</th><th>To</th><th>Standing</th></tr></thead><tbody>';
			foreach ($fleet_moves as $move) {
				echo '<tr><td>' . htmlspecialchars($move['slug']) . '</td><td>' . htmlspecialchars($move['name']) . '</td><td>'
					. htmlspecialchars($move['from']) . '</td><td>' . htmlspecialchars($move['to']) . '</td><td>'
					. htmlspecialchars($move['step']) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

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

	if (!empty($shard_run) && $shard_run->isLive()) {
		echo '<p>⏳ A shard is being born (run #' . intval($shard_run->key) . ', ' . htmlspecialchars((string)$shard_run->get('rcl_status'))
			. '). Approve or watch it in the <a href="/plugins/mailbox/admin/admin_mailbox_server#relay-section">Relay section</a>.</p>';
	} else {
		// A shard is born like any relay: a skeleton-only run in the operator's
		// own cloud account, the operator identity's public key in its registry.
		$sform = $page->getFormWriter('provision_shard');
		echo $sform->begin_form();
		$sform->hiddeninput('action', '', array('value' => 'provision_shard'));
		$sform->textinput('shard_hostname', 'Shard mail hostname', array('placeholder' => 'shard1.mx.example.com'));
		$sform->dropinput('shard_region', 'Region', array(
			'value'   => 'us-east',
			'options' => array(
				'us-southeast' => 'Atlanta, GA (US)', 'us-east' => 'Newark, NJ (US)', 'us-central' => 'Dallas, TX (US)',
				'us-west' => 'Fremont, CA (US)', 'us-sea' => 'Seattle, WA (US)', 'us-mia' => 'Miami, FL (US)',
				'ca-central' => 'Toronto (Canada)', 'eu-west' => 'London (UK)', 'eu-central' => 'Frankfurt (Germany)',
				'nl-ams' => 'Amsterdam (Netherlands)', 'fr-par' => 'Paris (France)', 'ap-south' => 'Singapore',
				'ap-northeast' => 'Tokyo (Japan)', 'ap-southeast' => 'Sydney (Australia)', 'br-gru' => 'São Paulo (Brazil)',
			),
		));
		$sform->textinput('shard_capacity', 'Capacity (tenants)', array('value' => '25'));
		$sform->submitbutton('btn_provision_shard', 'Create shard in my Linode account');
		echo $sform->end_form();
		echo '<p class="text-muted small">Creates one small instance in your Linode account, billed to you; it builds itself and reports in.</p>';
	}

	$page->end_box();

	// --- Relay Hosting product (order-time auto-enrollment) -------------------
	$page->begin_box(array('title' => 'Relay Hosting product'));
	if (empty($store_active)) {
		echo '<p>Selling fleet slots needs the store plugin. Activate it to create the Relay Hosting product.</p>';
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
		echo '<p class="text-muted small">A paid order for a customer-cloud product on a fleet-slot tier builds the buyer\'s server and pre-seeds its relay enrollment — the owner\'s mailbox Server tab lands on one-click Enroll.</p>';
	} else {
		echo '<form method="post">';
		echo '<input type="hidden" name="action" value="fleet_create_product">';
		echo '<button type="submit" class="btn btn-primary">Create Relay Hosting product</button>';
		echo '</form>';
	}
	$page->end_box();
}

$page->admin_footer();
?>
