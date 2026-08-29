<?php
// PathHelper, Globalvars, SessionControl, DbConnector, ThemeHelper,
// PluginHelper are always pre-loaded — never require them.

require_once(PathHelper::getIncludePath('adm/logic/admin_sealed_secrets_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

$page_vars = process_logic(admin_sealed_secrets_logic(array_merge($_GET, $_POST)));

$session    = $page_vars['session'];
$dead_items = $page_vars['dead_items'];
$flash      = $page_vars['flash'];
$flash_kind = $page_vars['flash_kind'];

$page = new AdminPage();
$page->admin_header(array(
	'menu-id'        => 'sealed-secrets',
	'page_title'     => 'Secrets Health',
	'readable_title' => 'Secrets Health',
	'breadcrumbs'    => array('Secrets Health' => ''),
	'session'        => $session,
));

if ($flash) {
	echo '<div class="jy-alert jy-alert-' . htmlspecialchars($flash_kind === 'danger' ? 'danger' : 'success')
		. '">' . htmlspecialchars($flash) . '</div>';
}

$page->begin_box(array('title' => 'Stored secrets'));

echo '<p>The platform seals credentials, tokens and signing keys at rest with this '
	. 'install&rsquo;s key. When a database is copied into another environment, or the key '
	. 'is rotated, those values can no longer be read here. This page lists any that are '
	. 'currently unreadable and how to restore each one.</p>';

echo AdminPage::action_button('Reconcile now', '/admin/admin_sealed_secrets', array(
	'hidden' => array('action' => 'reconcile'),
	'class'  => 'btn btn-secondary',
));

if (empty($dead_items)) {
	echo '<p class="jy-mt-3"><span class="badge badge-success">All clear</span> '
		. 'Every stored secret opens with this install&rsquo;s key.</p>';
	$page->end_box();
	$page->admin_footer();
	return;
}

echo '<table class="jy-table jy-mt-3"><thead><tr>'
	. '<th>Secret</th><th>Feature</th><th>Why it&rsquo;s here</th><th>What to do</th>'
	. '</tr></thead><tbody>';

foreach ($dead_items as $item) {
	echo '<tr>';
	echo '<td><strong>' . htmlspecialchars($item['label']) . '</strong></td>';
	echo '<td>' . htmlspecialchars($item['feature']) . '</td>';

	// Why it is unreadable / what class of fix.
	if ($item['orphan']) {
		echo '<td>Belongs to a plugin that has been removed. The stored value is left over.</td>';
	} elseif ($item['severity'] === 'needs_ack') {
		echo '<td>The machine can create a fresh one, but doing so cuts off anything pinned to the old value.</td>';
	} else {
		echo '<td>Only you hold this value — it cannot be regenerated.</td>';
	}

	// The action.
	echo '<td>';
	if ($item['orphan']) {
		echo '<span class="text-muted">Nothing to do — reactivate the plugin if you need it, then reconcile.</span>';
	} elseif ($item['can_remint']) {
		echo AdminPage::action_button('Re-mint&hellip;', '/admin/admin_sealed_secrets', array(
			'hidden'        => array('action' => 'ack_remint', 'locator' => $item['locator']),
			'confirm'       => 'Re-minting ' . $item['label'] . ' invalidates everything pinned to the old value '
				. '(paired devices, pinned peers). This cannot be undone. Continue?',
			'confirm_typed' => 'REMINT',
			'class'         => 'btn btn-danger btn-sm',
		));
	} elseif ($item['severity'] === 'needs_ack') {
		echo '<span class="text-muted">Re-mint this from its own feature page.</span>';
	} elseif ($item['is_singleton']) {
		echo '<a class="btn btn-primary btn-sm" href="/admin/admin_settings">Re-enter in settings</a>';
	} else {
		echo '<span class="text-muted">Re-enter this credential on its feature&rsquo;s page.</span>';
	}
	echo '</td>';
	echo '</tr>';
}

echo '</tbody></table>';

$page->end_box();
$page->admin_footer();
