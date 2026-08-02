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
$page->end_box();

// ── Recovery key ────────────────────────────────────────────────────────────
echo '<a id="recovery-key"></a>';
$page->begin_box(array('title' => 'Recovery key'));

if ($recovery['state'] === 'unconfigured' || $recovery['state'] === 'invalid') {
	if ($recovery['state'] === 'invalid') {
		echo '<div class="alert alert-danger">' . htmlspecialchars($recovery['error']) . '</div>';
	}
	echo '<p>Generate a keypair, keep the private half in your password manager, and paste the public half here. '
	   . 'Every backup this site makes seals its own key to it, so that one private key opens all of them.</p>';
	echo '<pre class="border rounded p-2 small">php '
	   . htmlspecialchars(PathHelper::getSiteRoot())
	   . '/maintenance_scripts/sysadmin_tools/escrow_keypair.php generate --private-out ~/recovery.key</pre>';
	$fw = $page->getFormWriter('recovery_key_form');
	$fw->begin_form();
	$fw->hiddeninput('action', '', array('value' => 'save_recovery_key'));
	$fw->textinput('backup_recovery_public_key', 'Recovery public key', array('required' => true));
	$fw->submitbutton('btn_save_recovery', 'Save public key');
	$fw->end_form();

} elseif ($recovery['state'] === 'unproven') {
	echo '<p>Key ' . htmlspecialchars($recovery['fingerprint']) . '… is saved but unverified. '
	   . 'Nothing is sealed to it yet: a mistyped key would seal happily and produce backups nobody could ever open, '
	   . 'so open this challenge with the private key you saved.</p>';

	echo '<label for="rk-privkey" class="form-label"><strong>Paste your recovery key</strong></label>';
	echo '<p class="text-muted small mb-1">It is used in your browser and never sent anywhere.</p>';
	echo '<input type="password" id="rk-privkey" class="form-control" autocomplete="off" spellcheck="false">';
	echo '<button type="button" id="rk-open" class="btn btn-primary btn-sm mt-2">Open the challenge</button>';
	echo '<div id="rk-status" class="small mt-2"></div>';

	$fw = $page->getFormWriter('recovery_proof_form');
	$fw->begin_form();
	$fw->hiddeninput('action', '', array('value' => 'verify_recovery_key'));
	$fw->textinput('recovery_proof', 'Result', array('autocomplete' => 'off', 'id' => 'rk-proof'));
	$fw->submitbutton('btn_verify_recovery', 'Verify');
	$fw->end_form();

	echo '<details class="mt-2"><summary class="small">Or open it at the command line</summary>';
	echo '<pre class="border rounded p-2 small">echo \'' . htmlspecialchars(BackupRecoveryKey::possession_challenge())
	   . '\' | php ' . htmlspecialchars(PathHelper::getSiteRoot())
	   . '/maintenance_scripts/sysadmin_tools/escrow_keypair.php unseal --private ~/recovery.key</pre></details>';

	echo '<script defer src="/assets/js/recovery-readiness.js?v='
	   . (@filemtime(PathHelper::getIncludePath('assets/js/recovery-readiness.js')) ?: '1') . '"></script>';
	echo '<script>window.rrCeremonyConfigs = ' . json_encode(array(array(
		'keyInputId' => 'rk-privkey',
		'buttonId'   => 'rk-open',
		'statusId'   => 'rk-status',
		'proofId'    => 'rk-proof',
		'challenge'  => BackupRecoveryKey::browser_challenge(),
		'publicKey'  => base64_encode(BackupRecoveryKey::parse_public_key()),
		'infoPrefix' => BackupRecoveryKey::BROWSER_INFO,
	)), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';

	$fw2 = $page->getFormWriter('recovery_clear_form');
	$fw2->begin_form();
	$fw2->hiddeninput('action', '', array('value' => 'clear_recovery_key'));
	$fw2->submitbutton('btn_clear_recovery', 'Use a different key', array('class' => 'btn btn-sm btn-outline-secondary mt-2'));
	$fw2->end_form();

} else {
	echo '<p class="mb-1"><strong>Verified.</strong> Key ' . htmlspecialchars($recovery['fingerprint'])
	   . '… opens every backup this site makes.</p>';
	echo '<p class="text-muted small mb-0">Re-check it any time from '
	   . '<a href="/admin/admin_recovery_readiness">Recovery Readiness</a>.</p>';
}
$page->end_box();

// ── Targets ─────────────────────────────────────────────────────────────────
$page->begin_box(array('title' => 'Where backups go'));

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
		echo '<a class="btn btn-sm btn-outline-secondary" href="/admin/admin_backups?edit=' . (int)$t->key . '">Edit</a>';
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
$page->end_box();

// ── Schedule and retention ──────────────────────────────────────────────────
$page->begin_box(array('title' => 'What to keep'));

$target_options = array('0' => '— none —');
foreach ($rows as $t) {
	if ($t->get('bkt_enabled')) { $target_options[(string)(int)$t->key] = $t->get('bkt_name'); }
}

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
));
$fw->submitbutton('btn_save_schedule', 'Save');
$fw->end_form();

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

$page->admin_footer();
