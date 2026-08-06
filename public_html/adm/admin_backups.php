<?php
// PathHelper, Globalvars, SessionControl, DbConnector, ThemeHelper,
// PluginHelper are always pre-loaded — never require them.

require_once(PathHelper::getIncludePath('adm/logic/admin_backups_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/BackupRunner.php'));

$page_vars = process_logic(admin_backups_logic(array_merge($_GET, $_POST)));

$session      = $page_vars['session'];
$settings     = $page_vars['settings'];
$targets      = $page_vars['targets'];
$history      = $page_vars['history'];
$manager_history = $page_vars['manager_history'];
$recovery     = $page_vars['recovery'];
$plan         = $page_vars['plan'];
$plan_problem = $page_vars['plan_problem'];
$default_slug = $page_vars['default_slug'];
$task         = $page_vars['task'];

$page = new AdminPage();
$page->admin_header(array(
	'menu-id'        => 'backups',
	'page_title'     => 'Backups',
	'readable_title' => 'Backups',
	'breadcrumbs'    => array('Backups' => ''),
	'session'        => $session,
));

$edit_id = (int)($_GET['edit'] ?? 0);
$tz = $session->get_timezone();

$when = function ($utc) use ($tz) {
	return $utc ? LibraryFunctions::convert_time($utc, 'UTC', $tz, 'M j, Y g:i A T') : '—';
};

// ── Status ──────────────────────────────────────────────────────────────────
// One box that answers "am I backed up?", because that is the only question
// this page exists to answer.
$page->begin_box(array('title' => 'Status'));

$active = !empty($task['sct_is_active']);
if ($plan_problem !== '') {
	echo '<div class="alert alert-warning mb-2">' . htmlspecialchars($plan_problem) . '</div>';
} elseif (!$active) {
	echo '<div class="alert alert-warning mb-2">Everything is configured, but the Backup task is switched off, '
	   . 'so nothing runs on a schedule. '
	   . '<a href="/admin/admin_scheduled_tasks" class="alert-link">Turn it on</a>.</div>';
} else {
	echo '<div class="alert alert-success mb-2">Backing up ' . htmlspecialchars($plan['project']) . ' '
	   . ($plan['type'] === 'database' ? '(database only)' : '(whole site)')
	   . ' to <strong>' . htmlspecialchars($plan['target']->get('bkt_name')) . '</strong>, '
	   . 'keeping the newest ' . (int)$plan['keep_cloud'] . '.</div>';
}

$last_ok = null;
foreach ($history as $h) {
	if ($h->get('bkh_outcome') === 'success' && $h->get('bkh_upload_time')) { $last_ok = $h; break; }
}
echo '<table class="table mb-0"><tbody>';
echo '<tr><th>Last backup stored offsite</th><td>'
   . ($last_ok ? htmlspecialchars($when($last_ok->get('bkh_upload_time')))
                 . ' (' . htmlspecialchars(BackupRunner::human($last_ok->get('bkh_bytes'))) . ')'
               : 'never') . '</td></tr>';
echo '<tr><th>Task last ran</th><td>' . htmlspecialchars($when($task['sct_last_run_time'] ?? null));
if (!empty($task['sct_last_run_message'])) {
	echo ' &mdash; ' . htmlspecialchars($task['sct_last_run_message']);
}
echo '</td></tr>';
echo '<tr><th>Recovery key</th><td>'
   . ($recovery['is_ready']
       ? 'verified (' . htmlspecialchars($recovery['fingerprint']) . '&hellip;)'
       : htmlspecialchars(BackupRecoveryKey::outstanding_summary($recovery)))
   . '</td></tr>';
echo '</tbody></table>';

// A backup on demand — before a risky change, or to prove the setup works
// without waiting for tonight. Only offered when a run could actually work.
if ($plan) {
	$fr = $page->getFormWriter('run_now_form');
	$fr->begin_form();
	$fr->hiddeninput('action', '', array('value' => 'run_backup'));
	$fr->submitbutton('btn_run_backup', 'Run a backup now', array('class' => 'btn btn-sm btn-primary mt-2'));
	$fr->end_form();
}
$page->end_box();

// ── Recovery key ────────────────────────────────────────────────────────────
echo '<a id="recovery-key"></a>';
$page->begin_box(array('title' => 'Recovery key'));
require_once(PathHelper::getIncludePath('includes/RecoveryKeySetupPanel.php'));
RecoveryKeySetupPanel::render($page, array('state' => $recovery));
$page->end_box();

// ── Targets ─────────────────────────────────────────────────────────────────
$adding = !empty($_GET['add']);

echo '<a id="targets"></a>';
$page->begin_box(array('title' => 'Where backups go',
	'altlinks' => array('Add a target' => '/admin/admin_backups?add=1#targets')));

$rows = array();
foreach ($targets as $t) { $rows[] = $t; }

if ($rows) {
	echo '<table class="table"><thead><tr>'
	   . '<th>Name</th><th>Provider</th><th>Bucket</th><th>Folder</th><th>Enabled</th><th></th>'
	   . '</tr></thead><tbody>';
	foreach ($rows as $t) {
		echo '<tr>';
		echo '<td>' . htmlspecialchars($t->get('bkt_name')) . '</td>';
		echo '<td>' . htmlspecialchars(strtoupper($t->get('bkt_provider'))) . '</td>';
		echo '<td>' . htmlspecialchars((string)$t->get('bkt_bucket')) . '</td>';
		echo '<td>' . htmlspecialchars((string)$t->get('bkt_path_prefix')) . '</td>';
		echo '<td>' . ($t->get('bkt_enabled') ? 'Yes' : 'No') . '</td>';
		echo '<td>';
		echo '<a class="btn btn-sm btn-outline-secondary" href="/admin/admin_backups?edit=' . (int)$t->key . '#targets">Edit</a>';
		$ft = $page->getFormWriter('test_' . (int)$t->key);
		$ft->begin_form();
		$ft->hiddeninput('action', '', array('value' => 'test_target'));
		$ft->hiddeninput('bkt_id', '', array('value' => (int)$t->key));
		$ft->submitbutton('btn_test_' . (int)$t->key, 'Test', array('class' => 'btn btn-sm btn-outline-secondary'));
		$ft->end_form();
		$fd = $page->getFormWriter('del_' . (int)$t->key);
		$fd->begin_form();
		$fd->hiddeninput('action', '', array('value' => 'delete_target'));
		$fd->hiddeninput('bkt_id', '', array('value' => (int)$t->key));
		$fd->submitbutton('btn_del_' . (int)$t->key, 'Delete', array('class' => 'btn btn-sm btn-outline-secondary'));
		$fd->end_form();
		echo '</td></tr>';
	}
	echo '</tbody></table>';
}

$editing = null;
if ($edit_id) {
	foreach ($rows as $t) { if ((int)$t->key === $edit_id) { $editing = $t; } }
}

if (!$rows && !$adding) {
	echo '<p class="text-muted mb-0">No target is set up yet, so backups have nowhere to go.</p>';
}

// The form only appears when asked for — the Add a target action on this box,
// or a row's Edit button. A page whose default state is a blank credential
// form reads as unfinished setup even on a fully configured site.
if ($editing || $adding) {
	echo '<h6 class="mt-3">' . ($editing ? 'Edit target' : 'Add a target') . '</h6>';
	$fw = $page->getFormWriter('target_form');
	$fw->begin_form();
	$fw->hiddeninput('action', '', array('value' => 'save_target'));
	$fw->hiddeninput('bkt_id', '', array('value' => $editing ? (int)$editing->key : ''));
	$fw->textinput('bkt_name', 'Name', array('required' => true, 'value' => $editing ? $editing->get('bkt_name') : ''));
	$fw->dropinput('bkt_provider', 'Provider', array(
		'options' => array('b2' => 'Backblaze B2', 's3' => 'Amazon S3', 'linode' => 'Linode Object Storage'),
		'value'   => $editing ? $editing->get('bkt_provider') : 'b2',
	));
	$fw->textinput('bkt_bucket', 'Bucket', array('value' => $editing ? (string)$editing->get('bkt_bucket') : ''));
	$fw->textinput('bkt_path_prefix', 'Folder inside the bucket',
		array('value' => $editing ? (string)$editing->get('bkt_path_prefix') : 'joinery-backups'));
	$fw->textinput('access_key', 'Access key ID',
		array('autocomplete' => 'off', 'helptext' => $editing ? 'Leave blank to keep the stored key.' : ''));
	$fw->passwordinput('secret_key', 'Secret key',
		array('autocomplete' => 'new-password', 'helptext' => $editing ? 'Leave blank to keep the stored key.' : ''));
	$fw->textinput('region', 'Region', array('value' => ''));
	$fw->textinput('endpoint', 'Endpoint hostname',
		array('value' => '', 'helptext' => 'Leave blank for Backblaze B2 — it is detected when the target is saved.'));
	$fw->checkboxinput('bkt_enabled', 'Enabled', array('checked' => $editing ? (bool)$editing->get('bkt_enabled') : true));
	$fw->submitbutton('btn_save_target', $editing ? 'Save target' : 'Add target');
	$fw->end_form();
	echo '<a class="btn btn-sm btn-outline-secondary" href="/admin/admin_backups">Cancel</a>';
}
$page->end_box();

// ── Schedule and retention ──────────────────────────────────────────────────
// A summary once configured, the form only on first setup or behind Edit. The
// configured marker is a chosen target: it is the one setting with no default,
// and the thing that makes every other value here mean something.
$schedule_configured = (int)$settings->get_setting('backup_target_id') > 0;
$editing_schedule = !empty($_GET['edit_schedule']) || !$schedule_configured;

echo '<a id="keep"></a>';
$keep_box = array('title' => 'What to keep');
if (!$editing_schedule) {
	$keep_box['altlinks'] = array('Edit' => '/admin/admin_backups?edit_schedule=1#keep');
}
$page->begin_box($keep_box);

if ($editing_schedule) {
	// These are declared settings, so the page must not draw its own fields for
	// them — the declarations in settings.json are the single source of the label,
	// type and help, and a hand-drawn duplicate is exactly how those drift apart.
	// The recovery key lives in its own box above, so it is skipped here.
	require_once(PathHelper::getIncludePath('includes/SettingsFieldRenderer.php'));

	$fw = $page->getFormWriter('schedule_form');
	$fw->begin_form();
	$fw->hiddeninput('action', '', array('value' => 'save_schedule'));
	SettingsFieldRenderer::renderGroup($fw, 'backups', array(
		'source' => 'core',
		'skip'   => array('backup_recovery_public_key', 'backup_recovery_public_key_proven_fpr'),
		// Blank means "follow the project directory name" — show what that
		// resolves to on THIS machine, since the declaration cannot know it.
		'field_options' => array(
			'backup_path_slug' => array('placeholder' => $default_slug),
		),
	));
	$fw->submitbutton('btn_save_schedule', 'Save');
	$fw->end_form();
	if ($schedule_configured) {
		echo '<a class="btn btn-sm btn-outline-secondary" href="/admin/admin_backups">Cancel</a>';
	}
} else {
	$target_name = '';
	$target_id = (int)$settings->get_setting('backup_target_id');
	foreach ($rows as $t) {
		if ((int)$t->key === $target_id) { $target_name = (string)$t->get('bkt_name'); }
	}

	$is_db_only = $settings->get_setting('backup_type') === 'database';
	$is_full    = $is_db_only || $settings->get_setting('backup_mode') === 'full';
	$keep       = max(1, (int)$settings->get_setting('backup_retention_count'));
	$local_days = (int)$settings->get_setting('backup_local_retention_days');
	$slug       = trim((string)$settings->get_setting('backup_path_slug')) ?: $default_slug;
	$excludes   = trim((string)$settings->get_setting('backup_exclude'));

	echo '<table class="table mb-2"><tbody>';
	echo '<tr><th>Backing up</th><td>'
	   . ($is_db_only ? 'Database only' : 'Whole site (files, database, web server config)') . '</td></tr>';
	echo '<tr><th>How</th><td>'
	   . ($is_full ? 'Full every time'
	               : 'Incremental — a fresh full every ' . (int)$settings->get_setting('backup_full_interval_days') . ' days')
	   . '</td></tr>';
	echo '<tr><th>Uploads to</th><td>'
	   . ($target_name !== '' ? htmlspecialchars($target_name) : '<span class="text-muted">missing target</span>')
	   . ', filed under ' . htmlspecialchars($slug) . '</td></tr>';
	echo '<tr><th>Keeping</th><td>Newest ' . $keep . ' offsite; local copies '
	   . ($local_days > 0 ? $local_days . ' days' : 'forever')
	   . ($settings->get_setting('backup_delete_local_after_upload') === '1' ? '; local copy removed once uploaded' : '')
	   . '</td></tr>';
	if ($excludes !== '') {
		echo '<tr><th>Leaving out</th><td>' . htmlspecialchars($excludes) . '</td></tr>';
	}
	echo '</tbody></table>';
}

echo '<p class="text-muted small mb-0">When backups run is set on '
   . '<a href="/admin/admin_scheduled_tasks">Scheduled Tasks</a>'
   . (!empty($task['sct_frequency'])
       ? ' — currently ' . htmlspecialchars($task['sct_frequency'])
         . ' at ' . htmlspecialchars((string)($task['sct_schedule_time'] ?? ''))
       : '') . '.</p>';
$page->end_box();

// ── History ─────────────────────────────────────────────────────────────────
$page->begin_box(array('title' => 'Recent backups'));

$hrows = array();
foreach ($history as $h) { $hrows[] = $h; }

if (!$hrows) {
	echo '<p class="text-muted mb-0">No backups have run yet.</p>';
} else {
	echo '<table class="table"><thead><tr>'
	   . '<th>When</th><th>What</th><th>Result</th><th>Size</th><th>Stored</th><th></th>'
	   . '</tr></thead><tbody>';
	foreach ($hrows as $h) {
		$outcome = (string)$h->get('bkh_outcome');
		echo '<tr>';
		echo '<td>' . htmlspecialchars($when($h->get('bkh_start_time'))) . '</td>';
		echo '<td>' . htmlspecialchars($h->get('bkh_type')) . '</td>';
		echo '<td>' . ($outcome === 'failed'
			? '<strong>failed</strong>'
			: htmlspecialchars($outcome));
		if ($h->get('bkh_message')) {
			echo '<div class="small text-muted">' . htmlspecialchars($h->get('bkh_message')) . '</div>';
		}
		echo '</td>';
		echo '<td>' . htmlspecialchars(BackupRunner::human($h->get('bkh_bytes'))) . '</td>';
		echo '<td>' . ($h->is_offsite()
			? htmlspecialchars((string)$h->get('bkh_target_name'))
			: '<span class="text-muted">local only</span>') . '</td>';
		echo '<td>';
		$fh = $page->getFormWriter('delh_' . (int)$h->key);
		$fh->begin_form();
		$fh->hiddeninput('action', '', array('value' => 'delete_history'));
		$fh->hiddeninput('bkh_id', '', array('value' => (int)$h->key));
		$fh->submitbutton('btn_delh_' . (int)$h->key, 'Hide', array('class' => 'btn btn-sm btn-outline-secondary'));
		$fh->end_form();
		echo '</td></tr>';
	}
	echo '</tbody></table>';
}
$page->end_box();

// ── Backups somebody else takes of this site ────────────────────────────────
//
// Shown whenever they exist, and read-only. This site cannot schedule, run or
// delete them — they belong to whoever runs them, on their storage, under their
// key. Saying so plainly is the point: the alternative is a site admin finding
// unexplained archives in a directory listing, or assuming a copy they cannot
// open is a copy they can restore from.
$mrows = array();
foreach ($manager_history as $m) { $mrows[] = $m; }

if ($mrows) {
	$page->begin_box(array('title' => 'Backups taken by a control plane'));
	echo '<p class="text-muted">Another party manages this site and takes its own backups of it. They '
	   . 'are encrypted to that party\'s recovery key, stored on their storage, and kept to their '
	   . 'schedule &mdash; this page cannot change or delete them. They do not replace this site\'s own '
	   . 'backups above, which are the ones you hold the key to.</p>';
	echo '<table class="table"><thead><tr>'
	   . '<th>When</th><th>What</th><th>Result</th><th>Size</th><th>Opens with</th>'
	   . '</tr></thead><tbody>';
	foreach ($mrows as $m) {
		$outcome = (string)$m->get('bkh_outcome');
		echo '<tr>';
		echo '<td>' . htmlspecialchars($when($m->get('bkh_start_time'))) . '</td>';
		echo '<td>' . htmlspecialchars($m->get('bkh_type')) . '</td>';
		echo '<td>' . ($outcome === 'failed' ? '<strong>failed</strong>' : htmlspecialchars($outcome)) . '</td>';
		echo '<td>' . htmlspecialchars(BackupRunner::human($m->get('bkh_bytes'))) . '</td>';
		// The site key is a recipient too, so this machine can restore itself from
		// one of these unattended. Worth stating: it is the difference between a
		// copy that is merely present and one this site can actually use.
		echo '<td><span class="small text-muted">their recovery key '
		   . htmlspecialchars(substr((string)$m->get('bkh_recovery_fpr'), 0, 16)) . '&hellip;, '
		   . 'and this site\'s own key</span></td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
	$page->end_box();
}

$page->admin_footer();
