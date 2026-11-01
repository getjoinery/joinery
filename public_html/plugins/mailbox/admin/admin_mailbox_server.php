<?php
/** @joinery-admin class=restricted */
/**
 * Inbound Email - Server
 *
 * The whole mail server, with no mailbox to choose first: the relay, this
 * server's mail identity (hostname and public IP), and the full health run,
 * server checks first and then one group per hosted domain. Setup for one
 * mailbox or domain is the Setup tab; the choices (which stack receives mail,
 * spam filtering, limits) are the Settings tab.
 *
 * Every form posts back to this page, and its logic redirects here afterwards.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/admin_tabs.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailSetupCheck.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/setup_check_render.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/admin_mailbox_server_logic.php'));

$page_vars = process_logic(admin_mailbox_server_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(array(
	'menu-id' => 'incoming',
	'breadcrumbs' => array(
		'Inbound Email' => '/plugins/mailbox/admin/admin_mailbox_accounts',
		'Server' => '',
	),
	'session' => $session,
));

echo AdminPage::tab_menu(mailbox_admin_tabs(), 'Server');

// --- Relay ---
// Whether a relay takes this server's mail in, its health, its update, and
// where one is set up, enabled or removed.
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/relay_section.php'));
mailbox_relay_section_render($page, $relay_section);

// --- This server's mail identity ---
// Two values that are set once: shown as values, with an Edit link to the
// form. The form opens by itself when auto-detection found a private address,
// which is the one case that needs a value typed in. A site container has no
// mail server, so it has no mail identity to show or set: its mail arrives
// through a relay (multi_tenant_docker_hosts WP9).
if ($has_mail_server) {
	$identity_edit = $edit_identity || $public_ip_private || $mail_hostname === '';
	echo '<div id="mail-identity">';
	$page->begin_box(array('title' => "This server's mail identity"));
	if (!$identity_edit) {
		echo '<table class="table" style="max-width:640px;"><tbody>'
			. '<tr><th style="width:40%;">Mail server hostname</th><td>' . htmlspecialchars($mail_hostname) . '</td></tr>'
			. '<tr><th>Public IP</th><td>' . htmlspecialchars($configured_public_ip !== '' ? $configured_public_ip
				: ($public_ip !== '' ? $public_ip . ' (detected)' : 'detected automatically')) . '</td></tr>'
			. '</tbody></table>'
			. '<a href="' . htmlspecialchars($base . '?edit_identity=1#mail-identity') . '">Edit</a>';
	} else {
		$formwriter = $page->getFormWriter('identity_form', array('action' => $base));
		echo $formwriter->begin_form();
		$formwriter->textinput('mail_hostname', 'Mail server hostname', array(
			'value'       => $mail_hostname,
			'placeholder' => 'mail.example.com',
			'helptext'    => 'The fully-qualified name of THIS mail server — the target of your MX records, its HELO name, and its reverse-DNS name.',
		));
		if ($public_ip_private) {
			$formwriter->addError('public_ip',
				'Auto-detection found ' . $public_ip . ', a private address. Enter this server\'s public IP here.');
		}
		$formwriter->textinput('public_ip', 'Mail server public IP', array(
			'value'       => $configured_public_ip,
			'placeholder' => $public_ip !== '' ? 'auto-detected: ' . $public_ip : 'auto-detected',
			'helptext'    => 'Leave blank to auto-detect. Set this only if the server is behind NAT and auto-detection finds a private address.',
		));
		$formwriter->submitbutton('btn_save', 'Save & Run Checks');
		echo $formwriter->end_form();
	}
	$page->end_box();
	echo '</div>';
}

// --- Health run ---
// Server checks first (software, identity, plugin configuration, end-to-end),
// then Domain DNS with one heading per domain: the same eight or so checks
// listed flat for six domains read as one list full of duplicates. A domain
// with nothing to act on folds to one line; one with a failure or warning
// stays open.
$page->begin_box(array('title' => 'Health checks'));
if (empty($results)) {
	echo '<p class="text-muted mb-0">No checks returned.</p>';
} else {
	echo '<p class="text-muted small mb-3">DNS takes a while to travel. If you just published records, wait a '
		. 'few minutes and reload. To work on one mailbox or domain, use the '
		. '<a href="/plugins/mailbox/admin/admin_mailbox_setup">Setup</a> tab.</p>';
	$by_layer = array();
	foreach ($results as $r) { $by_layer[$r['layer']][] = $r; }
	// Server layers first, any layer without a title under its own name, and
	// the per-domain groups last.
	$layer_titles = array(
		'host'     => 'Mail server software',
		'mailhost' => "This server's mail identity",
		'plugin'   => 'Plugin configuration',
		'e2e'      => 'End-to-end',
		'address'  => 'Delivery target',
	);
	$titled = $layer_titles;
	foreach (array_keys($by_layer) as $layer) {
		if (!isset($titled[$layer]) && $layer !== 'domain') { $titled[$layer] = $layer; }
	}
	$titled['domain'] = 'Domain DNS';
	foreach ($titled as $layer => $title) {
		if (empty($by_layer[$layer])) { continue; }
		echo '<h6 class="text-muted mt-3">' . htmlspecialchars($title) . '</h6>';
		if ($layer !== 'domain') {
			foreach ($by_layer[$layer] as $r) { mailbox_setup_render_check($r); }
			continue;
		}
		$groups = array();
		foreach ($by_layer[$layer] as $r) { $groups[$r['for_domain'] ?? $r['scope'] ?? ''][] = $r; }
		foreach ($groups as $group_domain => $group_rows) {
			$counts = array();
			foreach ($group_rows as $r) { $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1; }
			$needs_action = count($group_rows) > (($counts[InboundEmailSetupCheck::PASS] ?? 0)
				+ ($counts[InboundEmailSetupCheck::INFO] ?? 0) + ($counts[InboundEmailSetupCheck::OPTIONAL] ?? 0));
			$tally = array();
			foreach (array(InboundEmailSetupCheck::FAIL => 'failing', InboundEmailSetupCheck::WARN => 'warning',
					InboundEmailSetupCheck::UNKNOWN => 'could not be checked', InboundEmailSetupCheck::PASS => 'passing',
					InboundEmailSetupCheck::INFO => 'info', InboundEmailSetupCheck::OPTIONAL => 'optional') as $status => $word) {
				if (!empty($counts[$status])) { $tally[] = $counts[$status] . ' ' . $word; }
			}
			echo '<details class="mb-2"' . ($needs_action ? ' open' : '') . '><summary>'
				. '<strong>' . htmlspecialchars($group_domain) . '</strong>'
				. ' <span class="text-muted small">&mdash; ' . htmlspecialchars(implode(', ', $tally)) . '</span>'
				. '</summary><div class="mt-2">';
			foreach ($group_rows as $r) { mailbox_setup_render_check($r); }
			echo '</div></details>';
		}
	}
}
$page->end_box();

$page->admin_footer();
?>
