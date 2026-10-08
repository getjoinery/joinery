<?php
/**
 * Cloud Storage Admin Page
 *
 * Health status block at top. Then the file store: the setup form when none
 * is set up (the shared target form, BackupTargetForm, in its files mode);
 * otherwise what is stored, read-only, with Pause or Enable, Disable and Pull
 * Files Back to Local, and Remove as the state allows, and the form folded
 * away — the key and name once files are in it, everything otherwise. Every
 * save runs the bucket and key check, the privacy gate among its steps, and
 * stores only when it passes. Switch to another bucket saves a second store
 * new offloads go to; Older file stores lists the stores still serving files,
 * with Move files and, once empty, Delete.
 *
 * @version 3.1 - an older store that does not answer says so, and its key is replaced on its own row
 * @version 3.0 - the file store is a target row (specs/storage_targets.md WP6): the shared target form,
 *                Switch to another bucket, Older file stores with Move files, its progress and Stop
 * @version 2.2 - a stored secret key is a locked field with Reset
 * @changelog 2.1 - while files are in the bucket the key folds behind Replace key, which proves the key
 *                and stores it alone; it opens itself when the bucket stopped answering
 * @version 2.0.2 - records with no bytes on this server are listed apart from stuck files, without Retry;
 *                  the stuck table shows each file's last error
 * @version 2.0.1 - Pause and Disable and Pull Files Back are plain grey buttons
 * @version 2.0 - one private store (specs/implemented/cloud_storage_private_only.md): the intro says what moves;
 *                the forms draw provider, endpoint, region, bucket and the key; the private-store lines,
 *                the second pull-back, the egress banner and the pre-save confirm are gone
 * @version 1.7 - the provider picker heads the form; the endpoint and region fields show only for
 *                a provider that asks for them, and the script fills each field's example and help
 * @version 1.6 - the store's three shapes; locked fields shown, not edited; Enable and Remove; the
 *                Status box is one state sentence plus lines only for what needs attention
 * @version 1.5 - the Status box says what waits on this server for a backup before its local copy is
 *                released, and that the file store and backup storage share an account when they do
 * @version 1.4 - the file-store check in the Status box: when it last looked, "N offloaded files are
 *                missing from the file store; the backup holds M of them", and Bring them back
 * @version 1.3
 */

require_once(PathHelper::getIncludePath('adm/logic/admin_cloud_storage_logic.php'));

$page_vars = process_logic(admin_cloud_storage_logic(array_merge($_GET, $_POST)));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(array(
	'menu-id' => null,
	'page_title' => 'Cloud Storage',
	'readable_title' => 'Cloud Storage',
	'breadcrumbs' => array(
		'Settings' => '/admin/admin_settings',
		'Cloud Storage' => '',
	),
	'session' => $session,
));

// =====================================================
// STATUS
// =====================================================
// One sentence for the state, with every healthy figure folded into it, under
// the traffic light. A coloured box only for a problem or a warning. Nothing
// for an absence: a driver that answers, a task whose last run succeeded, say
// nothing here.
echo '<p style="max-width: 800px; margin-bottom: 16px;">If your Joinery is running out of disk space, you can add a storage bucket below and Joinery will intelligently offload files to the bucket. '
	. 'Those files will be accessible just like locally, but you\'ll pay for storage and transfer according to your bucket provider\'s policies. '
	. 'Only files people must be signed in to see move: private uploads, Drive files and inbound mail. Public images and downloads stay on this server, so nothing on a page is served from the bucket.</p>';

$page->begin_box(array('title' => 'Status'));

$dot = function($color) {
	return '<span style="display:inline-block; width:10px; height:10px; border-radius:50%; background:' . $color . '; margin-right:6px; vertical-align:middle;"></span>';
};
$when  = function ($utc) use ($session) { return htmlspecialchars(LibraryFunctions::convert_time($utc, 'UTC', $session->get_timezone())); };
$files = function ($count, $bytes = null) {
	return number_format((int)$count) . ' file' . ((int)$count === 1 ? '' : 's')
		. ($bytes !== null && (int)$count > 0 ? ' (' . BackupRunner::human((int)$bytes) . ')' : '');
};
// The state wears the traffic light; a problem or a warning is a coloured box;
// a plain fact is plain text.
$state   = function ($color, $html) use ($dot) { echo '<div style="margin-bottom: 8px;">' . $dot($color) . $html . '</div>'; };
$problem = function ($html) { echo '<div class="alert alert-danger" style="margin-bottom: 8px;">' . $html . '</div>'; };
$warn    = function ($html) { echo '<div class="alert alert-warning" style="margin-bottom: 8px;">' . $html . '</div>'; };
$info    = function ($html) { echo '<div style="margin-bottom: 8px;">' . $html . '</div>'; };

$c = $health['counts'];
$last_run = !empty($health['sync_task']['last_run']) ? '; last run ' . $when($health['sync_task']['last_run']) : '';

// The state.
if (!$configured) {
	$state('#999', '<strong>Not set up.</strong> ' . $files($c['pending'], $c['pending_bytes']) . ' on this server would move to a bucket once one is set up.');
} elseif ($draining) {
	$last = !empty($health['reverse_task']['last_run']) ? '; last run ' . $when($health['reverse_task']['last_run']) : '';
	$state('#0d6efd', '<strong>Pulling files back.</strong> ' . $files($c['cloud'], $c['cloud_bytes']) . ' still in a file store' . $last . '.'
		. (!empty($health['reverse_task']['last_message']) ? '<br><small class="text-muted">' . htmlspecialchars($health['reverse_task']['last_message']) . '</small>' : ''));
} elseif ($enabled) {
	$state('#28a745', '<strong>Active.</strong> ' . $files($c['cloud'], $c['cloud_bytes']) . ' offloaded, ' . $files($c['pending'], $c['pending_bytes']) . ' waiting to move, '
		. number_format((int)$c['migrated_this_week']) . ' moved this week' . $last_run . '.');
} else {
	$state('#999', '<strong>Off.</strong> ' . ((int)$c['cloud'] > 0
		? $files($c['cloud'], $c['cloud_bytes']) . ' offloaded keep serving from their file store; ' . $files($c['pending'], $c['pending_bytes']) . ' on this server would move once enabled.'
		: $files($c['pending'], $c['pending_bytes']) . ' on this server would move to the file store once enabled.'));
}

// What needs attention.
if (!$health['cron']['ok']) {
	$problem('<strong>Cron is not running;</strong> nothing moves until it does. Last tick: '
		. ($health['cron']['last'] ? $when($health['cron']['last']) : '<em>never</em>') . '.');
}
// The ping runs off the latch too, so a paused or draining store reports a key
// that stopped working — those stores still serve every offloaded file. A
// store that is off and holds nothing says nothing: there is no file to lose.
$driver_failed = !empty($health['driver']) && !$health['driver']['ok'];
if ($driver_failed && ($enabled || (int)$cloud_count > 0 || $draining)) {
	$problem('<strong>The file store did not answer:</strong> ' . htmlspecialchars((string)($health['driver']['message'] ?? 'unknown'))
		. ' If the key was revoked or has expired, replace it below; the files in it cannot be served or pulled back until one works.');
}
if (!empty($health['sync_task']) && $health['sync_task']['is_active'] && $health['sync_task']['last_status'] === 'error') {
	$problem('<strong>The last run failed:</strong> ' . htmlspecialchars((string)$health['sync_task']['last_message']));
}
if ((int)$c['stuck'] > 0) {
	$problem('<strong>' . $files($c['stuck']) . ' failed to move 5 or more times.</strong> Retry below.');
}
if ((int)$c['missing'] > 0) {
	// Nothing to retry: the bytes were gone before the bucket was set up.
	$names = array();
	foreach ($health['missing_rows'] as $row) {
		$names[] = htmlspecialchars((string)($row['fbb_stored_name'] ?? ('#' . (int)($row['id'] ?? 0))));
	}
	$problem('<strong>' . $files($c['missing']) . ' ' . ((int)$c['missing'] === 1 ? 'has' : 'have') . ' no bytes on this server,</strong> so there is nothing to move. '
		. 'Each was deleted or lost before the bucket was set up; permanently deleting the file releases its record.'
		. ($names ? '<br><small class="text-muted">' . implode(', ', $names) . ((int)$c['missing'] > count($names) ? ', …' : '') . '</small>' : ''));
}

// What is worth knowing.
if (CloudStoreInventoryPanel::has_content($inventory)) {
	$panel = CloudStoreInventoryPanel::render($inventory, $objects_source, '/admin/admin_cloud_storage', $manager_url);
	if ((int)$inventory['missing_count'] > 0) { $problem($panel); } else { $info($panel); }
}
$waiting_line = BackupObjectsStatus::waiting_sentence($objects_status);
if ($waiting_line !== '') {
	$info(htmlspecialchars($waiting_line) . ' <a href="/admin/admin_backups">Backups</a>');
}
$same_account = $configured ? BackupObjectsStatus::same_account_line($objects_status) : '';
if ($same_account !== '') {
	$warn(htmlspecialchars($same_account));
}
// Stuck files, with their Retry.
if (!empty($health['stuck_rows'])) {
	echo '<div style="margin-top: 8px;">';
	echo '<table class="table table-sm" style="margin-top: 6px;"><thead><tr>';
	echo '<th>File</th><th>Last attempt</th><th>Failures</th><th>Last error</th><th></th>';
	echo '</tr></thead><tbody>';
	foreach ($health['stuck_rows'] as $row) {
		echo '<tr>';
		echo '<td>' . htmlspecialchars($row['fbb_stored_name']) . ' <small class="text-muted">(#' . (int)$row['fbb_file_blob_id'] . ')</small></td>';
		echo '<td>' . ($row['fbb_sync_last_attempt'] ? $when($row['fbb_sync_last_attempt']) : '—') . '</td>';
		echo '<td>' . (int)$row['fbb_sync_failed_count'] . '</td>';
		echo '<td><small>' . htmlspecialchars((string)($row['fbb_sync_last_error'] ?? '')) . '</small></td>';
		echo '<td>';
		echo '<form method="post" action="/admin/admin_cloud_storage" style="display:inline;">';
		echo '<input type="hidden" name="action" value="retry_stuck">';
		echo '<input type="hidden" name="fbb_file_blob_id" value="' . (int)$row['fbb_file_blob_id'] . '">';
		echo '<button type="submit" class="btn btn-sm btn-outline-primary">Retry</button>';
		echo '</form>';
		echo '</td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
	echo '</div>';
}

$page->end_box();

// =====================================================
// VALIDATION ERRORS (form-level)
// =====================================================
if (!empty($errors)) {
	echo '<div class="alert alert-danger">';
	echo '<strong>Settings not saved:</strong><ul style="margin-bottom:0;">';
	foreach ($errors as $e) echo '<li>' . htmlspecialchars($e) . '</li>';
	echo '</ul></div>';
}

// =====================================================
// TEST CONNECTION RESULTS (rendered inline after a failed save)
// =====================================================
if (!empty($test_results)) {
	$pageoptions = array('title' => 'Test Connection results');
	$page->begin_box($pageoptions);
	if (!$test_results['ok']) {
		echo '<div class="alert alert-danger">Settings were NOT saved. Fix the failed step below and Save again.</div>';
	}
	echo '<table class="table table-sm" style="max-width: 800px;"><tbody>';
	foreach ($test_results['steps'] as $step) {
		$icon_color = '#999';
		$icon = '—';
		if ($step['status'] === 'pass') { $icon = '✓'; $icon_color = '#28a745'; }
		elseif ($step['status'] === 'fail') { $icon = '✗'; $icon_color = '#dc3545'; }
		elseif ($step['status'] === 'warn') { $icon = '!'; $icon_color = '#ffc107'; }
		echo '<tr>';
		echo '<td style="width:30px; color:' . $icon_color . '; font-weight:bold; font-size: 1.2em;">' . $icon . '</td>';
		echo '<td><strong>' . htmlspecialchars($step['label']) . ':</strong> ' . htmlspecialchars($step['message']);
		if (!empty($step['raw'])) {
			echo '<br><small class="text-muted">Raw: <code>' . htmlspecialchars($step['raw']) . '</code></small>';
		}
		echo '</td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
	$page->end_box();
}

// =====================================================
// THE FILE STORE
// =====================================================
// Nothing set up: the setup form. Set up: what is stored, read-only, with the
// actions that fit its state, then the form folded away — the whole store
// while it holds nothing, the key and name once files are in it (their
// records point at objects there). Switching to another bucket is a second
// store; the older one keeps serving its files until Move files carries them.
$draw_form = function (?BackupTarget $target, bool $is_new, string $submit) use ($page) {
	$fw = $page->getFormWriter('file_store_form', ['action' => '/admin/admin_cloud_storage', 'method' => 'post', 'id' => 'file_store_form']);
	$fw->begin_form();
	$fw->hiddeninput('action', '', array('value' => 'save_store'));
	$fw->hiddeninput('bkt_backup_target_id', '', array('value' => (!$is_new && $target && $target->key) ? (int)$target->key : ''));
	BackupTargetForm::render($fw, $target, array('files' => true));
	echo '<div style="margin-top: 12px;">';
	$fw->submitbutton('btn_save_store', $submit, array('class' => 'btn btn-primary'));
	echo '</div>';
	echo $fw->end_form();
};
$save_failed = !empty($errors) || (!empty($test_results) && !$test_results['ok']);
$show_new = $form_new && ($save_failed || !empty($_GET['new_store']));

if (!$configured) {
	$page->begin_box(array('title' => 'Set up cloud storage'));
	echo '<p style="color:#666;">The bucket must be private: Save refuses one anyone can read.</p>';
	$draw_form($form_target, true, 'Save');
	$page->end_box();
} else {
	// The state is said once, in the Status box above; this box is what is stored.
	$page->begin_box(array('title' => 'File store'));

	try {
		$creds = $current->get_credentials() ?: array();
	} catch (BackupTargetException $e) {
		$creds = array();
		echo '<div class="alert alert-danger">' . htmlspecialchars($e->getMessage()) . '</div>';
	}
	$show = function ($label, $value, $muted = '') {
		echo '<tr><th style="width: 180px; font-weight: 600;">' . htmlspecialchars($label) . '</th><td>'
			. ($value !== '' ? htmlspecialchars($value) : '<span class="text-muted">' . htmlspecialchars($muted) . '</span>') . '</td></tr>';
	};
	echo '<table class="table table-sm" style="max-width: 800px;"><tbody>';
	$show('Name', (string)$current->get('bkt_name'));
	$show('Provider', StorageProvider::label((string)$current->get('bkt_provider')));
	$show('Endpoint', (string)($creds['endpoint'] ?? ''));
	$show('Region', (string)($creds['region'] ?? ''), 'none');
	$show('Bucket', (string)$current->get('bkt_bucket'));
	$show('Folder', $current->prefix());
	$show('Access key', (string)($creds['access_key'] ?? ''));
	$show('Secret key', '', (string)($creds['secret_key'] ?? '') !== '' ? 'stored' : 'none');
	$show('Files in it', $files($current_count, $current_count === (int)$c['cloud'] ? $health['counts']['cloud_bytes'] : null));
	echo '</tbody></table>';

	// The actions that fit the state.
	echo '<div style="margin-top: 6px; display: flex; gap: 8px; flex-wrap: wrap;">';
	if ($enabled) {
		echo AdminPage::action_button('Pause', '/admin/admin_cloud_storage', array(
			'hidden'  => array('action' => 'pause'),
			'confirm' => 'Pause cloud storage? Files already offloaded keep serving from their file store; new uploads stay on this server. Enable again at any time.',
			'class'   => 'btn btn-secondary',
		));
	} else {
		echo AdminPage::action_button('Enable', '/admin/admin_cloud_storage', array(
			'hidden'  => array('action' => 'enable'),
			'class'   => 'btn btn-primary',
		));
	}
	if (($enabled || (int)$cloud_count > 0) && !$draining) {
		$disk_free = function_exists('disk_free_space') ? @disk_free_space('/') : null;
		$free_label = $disk_free !== null ? round($disk_free / 1024 / 1024 / 1024, 1) . ' GB free' : 'unknown free space';
		echo AdminPage::action_button('Disable and Pull Files Back to Local', '/admin/admin_cloud_storage', array(
			'hidden'  => array('action' => 'disable_and_pull'),
			'confirm' => 'Disable cloud storage and pull all ' . (int)$cloud_count . ' offloaded files back to this server? Local disk: ' . $free_label . '. Ensure several GB of free space before continuing.',
			'class'   => 'btn btn-secondary',
		));
	}
	if ((int)$cloud_count === 0 && !$draining && !$enabled) {
		echo AdminPage::action_button('Remove', '/admin/admin_cloud_storage', array(
			'hidden'  => array('action' => 'remove'),
			'confirm' => 'Forget this file store and its key? Nothing is in it, so no file is affected. Uploads stay on this server.',
			'class'   => 'btn btn-outline-danger',
		));
	}
	echo '</div>';

	if ($edit_store !== null) {
		echo '<h6 style="margin-top: 18px;">Replace key for ' . htmlspecialchars((string)$edit_store->get('bkt_name')) . '</h6>';
		echo '<p class="text-muted small">This older store still serves the files whose records name it until they are moved, so its key has to work. '
			. 'The new key is proved against its bucket before it is stored; nothing else about it changes.</p>';
		$draw_form($form_target ?: $edit_store, false, 'Save');
		echo '<a class="btn btn-sm btn-outline-secondary" href="/admin/admin_cloud_storage">Cancel</a>';
	} elseif ($show_new) {
		echo '<h6 style="margin-top: 18px;">Switch to another bucket</h6>';
		echo '<p class="text-muted small">Once it is saved, new offloaded files go to this new store. Files already offloaded keep being served from '
			. htmlspecialchars((string)$current->get('bkt_name')) . ' until you move them with Move files, below.</p>';
		$draw_form($form_target, true, 'Save and switch');
		echo '<a class="btn btn-sm btn-outline-secondary" href="/admin/admin_cloud_storage">Cancel</a>';
	} else {
		// What may change is the form's to say: the whole store while it holds
		// nothing, the key and the name once files are in it. It opens on its
		// own when a save just failed or the bucket stopped answering — a
		// revoked key is the one fault only this form can fix.
		$holds = $current_count > 0 || $draining;
		echo '<details style="margin-top: 14px;"' . ($save_failed || $driver_failed ? ' open' : '') . '>';
		echo '<summary style="cursor: pointer; font-weight: 600;">' . ($holds ? 'Replace key' : 'Change settings') . '</summary>';
		echo '<p class="text-muted small" style="margin-top: 8px;">' . ($holds
			? 'Paste the replacement key — after rotating it at your provider, or after revoking one that leaked. It is proved against this same bucket before it is stored, and storing it changes nothing else: a paused store stays paused, and a pull-back carries on with the new key.'
			: 'Nothing is in this file store, so any of these may change. Save proves the bucket and the key before anything is stored.') . '</p>';
		$draw_form($form_target ?: $current, false, 'Save');
		echo '</details>';
		echo '<p style="margin-top: 12px;"><a href="/admin/admin_cloud_storage?new_store=1">Switch to another bucket</a></p>';
	}
	$page->end_box();

	// Older stores: files whose records still name them are served from there
	// until Move files carries them to the current store.
	$older = array_filter($stores, function ($s) { return !$s['current']; });
	if ($older) {
		$page->begin_box(array('title' => 'Older file stores'));
		echo '<p class="text-muted small">Files offloaded before the switch are still served from these. Move files carries them to '
			. htmlspecialchars((string)$current->get('bkt_name')) . ' a batch at a time, checking each copy before its record moves and the old one is deleted. '
			. 'A store that holds nothing can be deleted; nothing in its bucket is touched.</p>';
		echo '<table class="table table-sm" style="max-width: 900px;"><thead><tr><th>Name</th><th>Bucket</th><th>Files in it</th><th></th></tr></thead><tbody>';
		foreach ($older as $s) {
			$t = $s['target'];
			echo '<tr><td>' . htmlspecialchars((string)$t->get('bkt_name')) . '</td>';
			echo '<td>' . htmlspecialchars((string)$t->get('bkt_bucket') . ' / ' . $t->prefix()) . '</td>';
			echo '<td>' . $files($s['files']);
			if ($s['answers'] !== null && empty($s['answers']['ok'])) {
				echo '<div class="text-danger small"><strong>Did not answer:</strong> ' . htmlspecialchars((string)($s['answers']['message'] ?? 'unknown'))
					. ' Its files cannot be served or moved until it does.</div>';
			}
			echo '</td><td>';
			if ($move !== null && (int)$move['from'] === (int)$t->key) {
				echo '<div><strong>Moving:</strong> ' . $files($s['files']) . ' of ' . number_format((int)$move['total']) . ' left'
					. ($move['last'] !== '' ? '<br><small class="text-muted">' . htmlspecialchars((string)$move['last']) . '</small>' : '') . '</div>';
				echo '<a class="btn btn-sm btn-outline-secondary" href="/admin/admin_cloud_storage?edit_store=' . (int)$t->key . '">Replace key</a> ';
				echo AdminPage::action_button('Stop', '/admin/admin_cloud_storage', array(
					'hidden' => array('action' => 'stop_move'), 'class' => 'btn btn-sm btn-outline-secondary'));
			} elseif ($s['files'] > 0) {
				echo '<a class="btn btn-sm btn-outline-secondary" href="/admin/admin_cloud_storage?edit_store=' . (int)$t->key . '">Replace key</a> ';
				if ($move === null && !$draining) {
					echo AdminPage::action_button('Move files', '/admin/admin_cloud_storage', array(
						'hidden'  => array('action' => 'move_files', 'bkt_backup_target_id' => (int)$t->key),
						'confirm' => 'Move ' . $files($s['files']) . ' to ' . $current->get('bkt_name') . '? Each is copied and checked before its record moves; the old copy is then deleted.',
						'class'   => 'btn btn-sm btn-primary'));
				}
			} else {
				echo AdminPage::action_button('Delete', '/admin/admin_cloud_storage', array(
					'hidden'  => array('action' => 'delete_store', 'bkt_backup_target_id' => (int)$t->key),
					'confirm' => 'Delete the file store ' . $t->get('bkt_name') . '? It holds no file. Nothing in its bucket is touched.',
					'class'   => 'btn btn-sm btn-outline-danger'));
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		$page->end_box();
	}
}

$page->admin_footer();
