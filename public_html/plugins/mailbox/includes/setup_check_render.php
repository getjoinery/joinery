<?php
/**
 * Check-row rendering shared by the Setup tab (one mailbox or domain) and the
 * Server tab (the whole server).
 *
 * A row is one InboundEmailSetupCheck result: status, label, summary, and an
 * optional detail and fix. Both tabs draw rows the same way, so a check looks
 * the same wherever it appears. A fix that carries an action renders as a POST
 * form with no action attribute, so it posts back to the page it is on; both
 * tabs' logic hands those actions to mailbox_setup_fix_action()
 * (setup_fix_actions.php).
 *
 * @version 1.0
 */

if (!function_exists('mailbox_setup_status_badge')) {

function mailbox_setup_status_badge(string $status): string {
	switch ($status) {
		case InboundEmailSetupCheck::PASS:    return '<span class="badge bg-success">PASS</span>';
		case InboundEmailSetupCheck::FAIL:    return '<span class="badge bg-danger">FAIL</span>';
		case InboundEmailSetupCheck::WARN:    return '<span class="badge bg-warning text-dark">WARN</span>';
		// badge-info / badge-secondary, not bg-*: the admin theme colours badges
		// through .badge-{variant} and defines no .bg-info or .bg-secondary, so
		// those two rendered as unstyled text.
		case InboundEmailSetupCheck::INFO:    return '<span class="badge badge-info">INFO</span>';
		// Grey, because nothing is wrong: a capability nobody turned on.
		case InboundEmailSetupCheck::OPTIONAL: return '<span class="badge badge-secondary">OPTIONAL</span>';
		default:                              return '<span class="badge badge-secondary">UNKNOWN</span>';
	}
}

/**
 * Echo a check's fix: its text, command, DNS records, link and action button.
 *
 * @param array|null $fix
 * @param string     $address The focused mailbox, carried on an action form ('' on the Server tab).
 */
function mailbox_setup_render_fix($fix, string $address = ''): void {
	if (!$fix) { return; }
	if (!empty($fix['text'])) {
		echo '<p class="mb-2">' . htmlspecialchars($fix['text']) . '</p>';
	}
	if (!empty($fix['command'])) {
		echo '<pre class="bg-light p-2 mb-2"><code>' . htmlspecialchars($fix['command']) . '</code></pre>';
	}
	// One record or several — the same table either way. Columns appear when
	// any row carries the field: MX brings Priority; SRV brings Priority,
	// Weight and Port, with its target in the Value column — one input per
	// field the DNS provider's form asks for, never a crammed value string.
	$fix_records = !empty($fix['dns_records']) ? $fix['dns_records']
		: (!empty($fix['dns_record']) ? array($fix['dns_record']) : array());
	if (!empty($fix_records)) {
		$numeric_columns = array('priority' => 'Priority', 'weight' => 'Weight', 'port' => 'Port');
		$present = array();
		foreach ($numeric_columns as $key => $label) {
			foreach ($fix_records as $rec) {
				if (array_key_exists($key, $rec)) { $present[$key] = $label; break; }
			}
		}
		echo '<table class="table table-sm table-bordered mb-2 iem-table-760">';
		echo '<thead><tr><th>Type</th><th>Name</th>';
		foreach ($present as $label) {
			echo '<th>' . $label . '</th>';
		}
		echo '<th>Value</th></tr></thead><tbody>';
		foreach ($fix_records as $rec) {
			echo '<tr>';
			echo '<td>' . htmlspecialchars($rec['type']) . '</td>';
			echo '<td><code>' . htmlspecialchars($rec['name']) . '</code></td>';
			foreach (array_keys($present) as $key) {
				echo '<td>' . (array_key_exists($key, $rec)
					? '<code>' . htmlspecialchars((string)$rec[$key]) . '</code>' : '') . '</td>';
			}
			echo '<td>' . PublicPageBase::copy_field($rec['value']) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
	if (!empty($fix['link'])) {
		echo '<p class="mb-2"><a class="btn btn-sm btn-outline-secondary" href="'
			. htmlspecialchars($fix['link']['url']) . '">'
			. htmlspecialchars($fix['link']['label']) . '</a></p>';
	}
	if (!empty($fix['action'])) {
		$act = $fix['action'];
		echo '<form method="post" class="mb-1">';
		echo '<input type="hidden" name="action" value="' . htmlspecialchars($act['action']) . '">';
		if (!empty($act['domain'])) {
			echo '<input type="hidden" name="domain" value="' . htmlspecialchars($act['domain']) . '">';
		}
		echo '<input type="hidden" name="address" value="' . htmlspecialchars($address) . '">';
		$label = !empty($act['label']) ? $act['label']
			: ($act['action'] === 'enable_plugin' ? 'Enable inbound email'
			: ($act['action'] === 'add_domain' ? 'Add this domain' : 'Apply fix'));
		echo '<button type="submit" class="btn btn-sm btn-primary">' . htmlspecialchars($label) . '</button>';
		echo '</form>';
	}
}

/** Echo one check row as a card. */
function mailbox_setup_render_check(array $c, string $address = ''): void {
	$border = $c['status'] === InboundEmailSetupCheck::FAIL ? 'border-danger'
		: ($c['status'] === InboundEmailSetupCheck::WARN ? 'border-warning'
		: ($c['status'] === InboundEmailSetupCheck::PASS ? 'border-success'
		: ($c['status'] === InboundEmailSetupCheck::INFO ? 'border-info' : 'border-secondary')));
	echo '<div class="card mb-2 ' . $border . '"><div class="card-body py-2">';
	echo '<div>' . mailbox_setup_status_badge($c['status']) . ' <strong>' . htmlspecialchars($c['label']) . '</strong>';
	// "OPTIONAL recommended" contradicts itself — the grey badge already says
	// there is nothing to do here.
	if ($c['severity'] === InboundEmailSetupCheck::RECOMMENDED
			&& $c['status'] !== InboundEmailSetupCheck::OPTIONAL) {
		echo ' <span class="badge badge-subtle-secondary">recommended</span>';
	}
	echo '</div>';
	echo '<div class="mt-1">' . htmlspecialchars($c['summary']) . '</div>';
	if (!empty($c['detail']) || !empty($c['fix'])) {
		echo '<details class="mt-1"><summary class="fix-toggle small">Details &amp; how to fix</summary>';
		echo '<div class="mt-2">';
		if (!empty($c['detail'])) {
			echo '<p class="text-muted small mb-2">' . htmlspecialchars($c['detail']) . '</p>';
		}
		mailbox_setup_render_fix($c['fix'], $address);
		echo '</div></details>';
	}
	echo '</div></div>';
}

}
