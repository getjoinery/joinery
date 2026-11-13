<?php
/** @joinery-admin class=keys */
// PathHelper, Globalvars, SessionControl, DbConnector, ThemeHelper,
// PluginHelper are always pre-loaded — never require them.

require_once(PathHelper::getIncludePath('adm/logic/admin_sealed_secrets_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

$page_vars = process_logic(admin_sealed_secrets_logic(array_merge($_GET, $_POST)));

$session    = $page_vars['session'];
$dead_items = $page_vars['dead_items'];
$flash      = $page_vars['flash'];
$flash_kind = $page_vars['flash_kind'];
$replacement_list  = $page_vars['replacement_list'];
$replacement_items = $page_vars['replacement_items'];

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
}
if (!empty($dead_items)) {

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
}

$page->end_box();

// ── Replace after a break-in (SecretReplacementList) ─────────────────────
$page->begin_box(array('title' => 'Replace after a break-in'));

echo '<p>Anything that ran on this server as the website could read every stored secret: the site '
	. 'has to be able to use them, so it holds the key. After a break-in, treat each one as known '
	. 'to the intruder and replace it. This list names every kind stored here and what replacing it takes.</p>';

if ($replacement_list === null) {
	echo AdminPage::action_button('Open a replacement list', '/admin/admin_sealed_secrets', array(
		'hidden'  => array('action' => 'list_start'),
		'confirm' => 'Open a list of every stored secret to replace? It stays open, on this page, until each one is marked done.',
		'class'   => 'btn btn-primary',
	));
} else {
	$progress = SecretReplacementList::progress($replacement_list, $replacement_items);
	$who = function ($user_id) {
		$u = new User((int)$user_id, TRUE);
		return $u->key ? $u->display_name() : 'user ' . (int)$user_id;
	};
	echo '<p><strong>Open since ' . htmlspecialchars((string)$replacement_list['started']) . ' UTC</strong>, opened by '
		. htmlspecialchars($who($replacement_list['started_by'] ?? 0)) . '. '
		. (int)$progress['done'] . ' of ' . (int)$progress['total'] . ' done.</p>';

	$headings = array(
		SecretReplacementList::GROUP_REMINT  => array('Make new ones here', 'The site can make a new one. Anything pinned to the old value (a paired device, a peer, a signed link) has to reconnect.'),
		SecretReplacementList::GROUP_REENTER => array('Get new ones from the provider', 'A person entered these. Make a new one in the provider\'s console, revoke the old one there, and enter the new one on its page here.'),
		SecretReplacementList::GROUP_NOTHING => array('Nothing to do', 'Per-run values that are thrown away after use.'),
	);
	foreach ($headings as $group => $heading) {
		$rows = array_values(array_filter($replacement_items, function ($i) use ($group) { return $i['group'] === $group; }));
		if (!$rows) {
			continue;
		}
		echo '<h3 class="jy-mt-3">' . htmlspecialchars($heading[0]) . '</h3><p class="text-muted">' . htmlspecialchars($heading[1]) . '</p>';
		echo '<table class="jy-table"><thead><tr><th>Secret</th><th>Feature</th><th>Stored</th><th>Not yet replaced</th>'
			. ($group !== SecretReplacementList::GROUP_NOTHING ? '<th>Done</th>' : '') . '</tr></thead><tbody>';
		foreach ($rows as $item) {
			$done = $replacement_list['done'][$item['locator']] ?? null;
			echo '<tr><td><strong>' . htmlspecialchars($item['label']) . '</strong>';
			if ($item['breaks_things']) {
				echo '<div class="text-muted small">Replacing it cuts off whatever is pinned to the old one.</div>';
			}
			if ($item['per_member']) {
				echo '<div class="text-muted small">One per member: replacing them means each member sets theirs up again.</div>';
			}
			if ($item['plugin_off']) {
				echo '<div class="text-muted small">Its plugin is switched off: turn it on to replace these, or delete them.</div>';
			}
			echo '</td><td>' . htmlspecialchars($item['feature']) . '</td><td>' . (int)$item['count'] . '</td>'
				. '<td>' . (int)$item['unchanged'] . '</td>';
			if ($group !== SecretReplacementList::GROUP_NOTHING) {
				echo '<td>';
				if ($done !== null) {
					if (isset($done['reason'])) {
						echo '<span class="badge badge-warning">Closed without replacing</span> <span class="text-muted small">'
							. htmlspecialchars((string)$done['at']) . ' UTC, ' . htmlspecialchars($who($done['by'] ?? 0))
							. ': ' . htmlspecialchars((string)$done['reason']) . '</span> ';
					} else {
						echo '<span class="badge badge-success">Done</span> <span class="text-muted small">'
							. htmlspecialchars((string)$done['at']) . ' UTC, ' . htmlspecialchars($who($done['by'] ?? 0)) . '</span> ';
					}
					echo AdminPage::action_button('Undo', '/admin/admin_sealed_secrets', array(
						'hidden' => array('action' => 'list_mark', 'locator' => $item['locator'], 'done' => ''),
						'class'  => 'btn btn-link btn-sm',
					));
				} else {
					if ($item['can_remint']) {
						echo AdminPage::action_button('Make a new one', '/admin/admin_sealed_secrets', array(
							'hidden'  => array('action' => 'list_remint', 'locator' => $item['locator']),
							'confirm' => 'Replace ' . $item['label'] . ' with a new one? Anything pinned to the old value has to reconnect.',
							'class'   => 'btn btn-secondary btn-sm',
						)) . ' ';
					}
					echo AdminPage::action_button('Mark done', '/admin/admin_sealed_secrets', array(
						'hidden' => array('action' => 'list_mark', 'locator' => $item['locator'], 'done' => '1'),
						'class'  => 'btn btn-outline-secondary btn-sm',
					));
					if ($item['unchanged'] > 0) {
						// Some kinds are not this admin's to replace (a registrant's
						// details, a customer server's password): close it with a
						// reason, which stays on the row.
						$form_id = 'srl_close_' . substr(md5($item['locator']), 0, 10);
						echo ' <form id="' . $form_id . '" method="POST" action="/admin/admin_sealed_secrets" style="display:inline;">'
							. '<input type="hidden" name="action" value="list_mark">'
							. '<input type="hidden" name="locator" value="' . htmlspecialchars($item['locator']) . '">'
							. '<input type="hidden" name="done" value="1">'
							. '<input type="hidden" name="reason" value="">'
							. '<button type="button" class="btn btn-link btn-sm" data-jy-prompt="'
							. htmlspecialchars('Close ' . $item['label'] . ' without replacing ' . (int)$item['unchanged']
								. ' value' . ($item['unchanged'] === 1 ? '' : 's') . '? Say why; it stays on the list.', ENT_QUOTES)
							. '" data-jy-prompt-form="' . $form_id . '" data-jy-prompt-field="reason">Close without replacing</button>'
							. '</form>';
					}
				}
				echo '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	echo '<h3 class="jy-mt-3">The rest of a recovery</h3>'
		. '<p class="text-muted">Not on this list, because they are not stored secrets, but part of the same recovery:</p><ol class="text-muted">'
		. '<li>Change the database password, in the server\'s config folder, on the server.</li>'
		. '<li>The key that seals these secrets, and the backup key, are in the same folder and cannot be replaced from '
		. 'here yet. A value replaced on this list is sealed under the same key, so an intruder who copied that key can '
		. 'read it from any later copy of the database. Keep backups and database copies away from them.</li>'
		. '<li>Redeploy the code from a release, in case files were changed.</li>'
		. '<li>Sign everyone out, and have admins change their passwords: password hashes could be copied for guessing offline.</li>'
		. '<li>Review what could have been added: admin accounts and permission changes, API keys, passkeys, app passwords, '
		. 'second-factor enrolments, mail forwarding, webhooks and scheduled tasks.</li>'
		. '<li>Members whose vault was open during the break-in rotate their vault key. A locked vault could not be read.</li>'
		. '</ol>';

	if ($progress['done'] >= $progress['total']) {
		echo AdminPage::action_button('Close the list', '/admin/admin_sealed_secrets', array(
			'hidden' => array('action' => 'list_finish'),
			'class'  => 'btn btn-primary',
		));
	}
}

$page->end_box();
$page->admin_footer();
