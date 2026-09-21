<?php
/**
 * admin_backups — the Backups page.
 *
 * @version 1.12 - the target form's bucket and key fields say what each must be; Save proves it
 * @version 1.11 - the Offloaded files box carries the figures: what each backup holds in backup storage and when
 *                 it was last indexed, what is still to copy from the file store, what waits on this
 *                 server for a backup before its local copy goes, and the same-account line when the
 *                 file store and this site's target share an access key
 * @version 1.10 - the Offloaded files box: what the daily file-store check found, "N offloaded files are
 *                 missing from the file store; the backup holds M of them", and Bring them back — run
 *                 here on a site with a backup of its own, named as the management node's job otherwise
 * @version 1.9 - the Keeping row says what a run leaves on this server (a chain run's metadata
 *                artifact, a standalone archive's envelope); archives and dumps stream to the
 *                bucket and there is no local copy to remove
 * @version 1.8 - a verify that proved nothing either way (skipped, or refused before it read
 *                anything) shows as "Last attempt" in the Status box and "not verified" / "since
 *                then" on the run's row, so a verify a person started never vanishes without a word
 * @version 1.7 - verified restorable: the Status box's fourth row (the last backup proven
 *                restorable, with the recovery-key ceremony beside it), the "Verify the newest
 *                backup" and "Rehearse a restore" buttons, and each run's Availability says whether
 *                it was verified restorable or failed verification
 * @version 1.6 - the Status box reads the three milestones; Recent backups is one row per run
 */
// PathHelper, Globalvars, SessionControl, DbConnector, ThemeHelper,
// PluginHelper are always pre-loaded — never require them.

require_once(PathHelper::getIncludePath('adm/logic/admin_backups_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/BackupRunner.php'));
require_once(PathHelper::getIncludePath('includes/BackupVerifier.php'));

$page_vars = process_logic(admin_backups_logic(array_merge($_GET, $_POST)));

$session      = $page_vars['session'];
$settings     = $page_vars['settings'];
$targets      = $page_vars['targets'];
$history      = $page_vars['history'];
$milestones   = $page_vars['milestones'];
$ceremony     = $page_vars['ceremony'];
$verify_every = $page_vars['verify_every'];
$recovery     = $page_vars['recovery'];
$plan         = $page_vars['plan'];
$plan_problem = $page_vars['plan_problem'];
$default_slug = $page_vars['default_slug'];
$task         = $page_vars['task'];
$is_managed   = $page_vars['is_managed'];
$manager_url  = $page_vars['manager_url'];
$approval     = $page_vars['approval'];
$decommission_approval = $page_vars['decommission_approval'];
$inventory      = $page_vars['inventory'];
$objects_source = $page_vars['objects_source'];
$objects_status = $page_vars['objects_status'];

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

// ── A restore waiting on a person ───────────────────────────────────────────
// FIRST, above everything, when there is one. This machine's own agent has
// claimed a job that will erase live data, has run nothing, and is holding it
// open until somebody here says yes with the recovery key. It is the most urgent
// thing this page can be showing, and it is showing it because the machine
// itself asked — not because a management node did.
// A removal outranks a restore, and the page renders exactly one approval
// ceremony at a time — recovery-readiness.js binds one window.rrApproval, and
// two key boxes on one screen is how a person answers the wrong one. In the
// unlikely case both are pending, the restore waits and says so.
if ($decommission_approval) {
	require_once(PathHelper::getIncludePath('includes/DecommissionApprovalPanel.php'));
	$page->begin_box(array('title' => 'Approve the permanent removal of this site'));
	DecommissionApprovalPanel::render($page, $decommission_approval);
	if ($approval) {
		echo '<p class="text-muted small mt-3">A restore approval is also waiting. It will be shown '
		   . 'here once this removal request is answered or expires.</p>';
	}
	$page->end_box();
} elseif ($approval) {
	require_once(PathHelper::getIncludePath('includes/RestoreApprovalPanel.php'));
	$page->begin_box(array('title' => 'Approve a restore'));
	RestoreApprovalPanel::render($page, $approval);
	$page->end_box();
}

// ── Status ──────────────────────────────────────────────────────────────────
// One box that answers "am I backed up?", because that is the only question
// this page exists to answer.
$page->begin_box(array('title' => 'Status'));

$active = !empty($task['sct_is_active']);
if ($is_managed) {
	// A management node owns this site's backup arrangements. The local "no target
	// configured" warning is not this admin's problem to fix — there is nothing
	// to configure here — so it is replaced by a plain statement of who runs the
	// backups. The offsite/last-run facts below still come from what actually
	// happened, which is the honest confirmation that the arrangement is working.
	echo '<div class="alert alert-info mb-2">This site\'s backups are managed by '
	   . ($manager_url !== ''
	       ? '<code>' . htmlspecialchars($manager_url) . '</code>'
	       : 'a management node')
	   . '. Where they go, how often they run, and how many are kept are set there, not on this page.</div>';
} elseif ($plan_problem !== '') {
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

// The three facts this box exists to answer, each read from the runs still
// held offsite: when the last backup was, when the last FULL backup was, and
// how far back the oldest one held reaches. Whoever took the run, it opens with
// this site's recovery key, so the row says who rather than sorting by whom.
$describe = function ($h) use ($when) {
	if (!$h) { return '<span class="text-muted">none yet</span>'; }
	if ((string)$h->get('bkh_type') === 'database') {
		$kind = 'database only';
	} else {
		$kind = ((string)$h->get('bkh_chain_id') !== '' && (int)$h->get('bkh_chain_seq') > 0) ? 'incremental' : 'full';
	}
	$by = ($h->get('bkh_profile') === BackupProfile::MANAGER) ? 'the management node' : 'this site';
	return htmlspecialchars($when($h->get('bkh_start_time')))
	   . ' <span class="text-muted small">&middot; ' . $kind
	   . ' &middot; ' . htmlspecialchars(BackupRunner::human($h->get('bkh_bytes')))
	   . ' &middot; by ' . $by . '</span>';
};
$oldest_note = '';
if ($milestones['oldest'] && $milestones['oldest']->get('bkh_profile') === BackupProfile::MANAGER) {
	// This machine witnessed the upload; the management node cleans its own
	// shelf up on its own schedule and does not report that back here.
	$oldest_note = ' <span class="text-muted small">(as recorded here &mdash; the management node cleans up '
	             . 'old backups on its own schedule)</span>';
}
echo '<table class="table mb-0"><tbody>';
echo '<tr><th>Last backup</th><td>' . $describe($milestones['newest']) . '</td></tr>';
echo '<tr><th>Last full backup</th><td>' . $describe($milestones['full']) . '</td></tr>';
echo '<tr><th>Oldest backup still held</th><td>' . $describe($milestones['oldest']) . $oldest_note . '</td></tr>';

// The fourth fact: has any of this been PROVEN restorable, and when. A pass is
// dated and named by level; a failure newer than the last pass is shown in red
// beside it with the reason, because a backup that failed to open is the one
// to know about. The recovery-key ceremony sits beside it on purpose: the
// verify proves the archives are sound with the key this machine holds, the
// ceremony proves the private key a person holds opens an envelope — together
// they are the proof, and neither alone is.
$verified = $milestones['verified'];
$verify_failed = $milestones['verify_failed'];
echo '<tr><th>Last verified restorable</th><td>';
if ($verified) {
	echo htmlspecialchars($when($verified->get('bkh_verify_time')))
	   . ' <span class="text-muted small">&middot; ' . htmlspecialchars(BackupVerifier::level_name((int)$verified->get('bkh_verify_level')) ?: 'verified')
	   . ' &middot; the backup of ' . htmlspecialchars($when($verified->get('bkh_start_time')))
	   . ((string)$verified->get('bkh_verify_message') !== ''
	       ? ' &middot; ' . htmlspecialchars((string)$verified->get('bkh_verify_message')) : '')
	   . '</span>';
} else {
	echo '<span class="text-muted">never</span>';
	if ($verify_every > 0 && $milestones['newest']) {
		echo ' <span class="text-muted small">&middot; the newest backup is opened and read every '
		   . (int)$verify_every . ' days</span>';
	} elseif ($verify_every <= 0) {
		echo ' <span class="text-muted small">&middot; scheduled verification is switched off</span>';
	}
}
if ($verify_failed) {
	echo '<div class="text-danger small">Verification failed ' . htmlspecialchars($when($verify_failed->get('bkh_verify_time')))
	   . ': ' . htmlspecialchars((string)$verify_failed->get('bkh_verify_message') ?: 'no reason recorded') . '</div>';
}
// A verify that proved nothing either way (skipped, or refused before it
// read anything) is neither of the above, and is still what became of the
// button a person pressed.
if (!empty($milestones['verify_attempt'])) {
	echo '<div class="text-muted small">Last attempt: '
	   . htmlspecialchars((string)$milestones['verify_attempt']->get('bkh_verify_message')) . '</div>';
}
echo '<div class="text-muted small">Your recovery key was last proven '
   . ($ceremony ? htmlspecialchars($when($ceremony)) : 'never')
   . ' &mdash; <a href="/admin/admin_recovery_readiness">prove it</a>. The two together are the proof: the '
   . 'verification shows the archives open and read with the key this machine holds; the ceremony shows '
   . 'your own private key opens them.</div>';
echo '</td></tr>';

// When did a backup last run, and who ran it. A plane-backed node's local task
// never runs — its backups are dispatched by the management node, which writes no
// local task row — so falling back to the newest management-node run tells the
// truth instead of showing a dash under a task nobody scheduled here.
$local_task_ran = function () use ($when, $task) {
	echo htmlspecialchars($when($task['sct_last_run_time'] ?? null));
	if (!empty($task['sct_last_run_message'])) {
		echo ' &mdash; ' . htmlspecialchars($task['sct_last_run_message']);
	}
};
$last_mgr_run = null;
foreach ($history as $h) {
	if ($h->get('bkh_profile') === BackupProfile::MANAGER) { $last_mgr_run = $h; break; }
}

echo '<tr><th>Task last ran</th><td>';
if (!empty($task['sct_is_active'])) {
	// An active local task is what runs backups here; its own last run is the truth.
	$local_task_ran();
} elseif ($last_mgr_run) {
	// No active local task, but a management node is taking backups — report its
	// last run rather than a dash or a stale local time from before this node
	// was managed.
	echo '<span class="text-muted">A management node runs your backups</span> &mdash; last ran '
	   . htmlspecialchars($when($last_mgr_run->get('bkh_start_time')));
} elseif (!empty($task['sct_last_run_time'])) {
	// A local task that has run but is switched off — the run is real, the alert
	// above says it is paused.
	$local_task_ran();
} else {
	echo '&mdash;';
}
echo '</td></tr>';

// The recovery-key line carries its own actions once the key is verified, so the
// separate setup box below can disappear entirely — an operator returning to a
// solved problem should not have to scroll past its solution.
$rotating = !empty($_GET['rotate_recovery']);
echo '<tr><th>Recovery key</th><td>';
if ($recovery['is_ready']) {
	echo 'verified (' . htmlspecialchars($recovery['fingerprint']) . '&hellip;)';
	if (!$rotating) {
		echo ' <a href="/admin/admin_recovery_readiness" class="ms-2 small">Verify</a>';
		echo ' <a href="?rotate_recovery=1#recovery-key" class="ms-2 small">Rotate key&hellip;</a>';
	}
} else {
	echo htmlspecialchars(BackupRecoveryKey::outstanding_summary($recovery));
}
echo '</td></tr>';
echo '</tbody></table>';

// A backup on demand — before a risky change, or to prove the setup works
// without waiting for tonight. Only offered when a run could actually work.
if ($plan) {
	$fr = $page->getFormWriter('run_now_form');
	$fr->begin_form();
	$fr->hiddeninput('action', '', array('value' => 'run_backup'));
	$fr->submitbutton('btn_run_backup', 'Run a backup now', array('class' => 'btn btn-sm btn-primary mt-2'));
	$fr->end_form();

	// Prove the newest backup restorable, without restoring it. Two buttons
	// for two levels: opening and reading (what the schedule does), and a
	// rehearsal into scratch and a throwaway database (a person's choice; no
	// schedule runs one). Only offered once there is a backup of the site's
	// own to open.
	$newest_own = BackupVerifyLauncher::newest_run();
	if ($newest_own) {
		$fv = $page->getFormWriter('verify_form');
		$fv->begin_form();
		$fv->hiddeninput('action', '', array('value' => 'verify_backup'));
		$fv->hiddeninput('level', '', array('value' => BackupVerifier::LEVEL_READ));
		$fv->submitbutton('btn_verify', 'Verify the newest backup', array('class' => 'btn btn-sm btn-outline-primary mt-2'));
		$fv->end_form();
		$fw3 = $page->getFormWriter('rehearse_form');
		$fw3->begin_form();
		$fw3->hiddeninput('action', '', array('value' => 'verify_backup'));
		$fw3->hiddeninput('level', '', array('value' => BackupVerifier::LEVEL_REHEARSE));
		$fw3->submitbutton('btn_rehearse', 'Rehearse a restore', array('class' => 'btn btn-sm btn-outline-secondary mt-2'));
		$fw3->end_form();
		echo '<p class="text-muted small mt-2 mb-0"><strong>Verify</strong> downloads every archive the newest backup '
		   . 'depends on, opens each with this site\'s own key and reads it to the end, then removes them. '
		   . '<strong>Rehearse</strong> does that and then replays the files into a scratch directory and loads '
		   . 'the database into a throwaway one on this machine\'s PostgreSQL, counts what came back, and deletes '
		   . 'both &mdash; it needs free disk of roughly twice the site plus the database, and a PostgreSQL role '
		   . 'that can create a database; both are checked before anything is downloaded. Nothing on the live '
		   . 'site is touched either way. Each verification downloads the whole set once.</p>';
	}
}
$page->end_box();

// ── Recovery key ────────────────────────────────────────────────────────────
// Only while there is something to do: setting a key, proving possession, or a
// rotation in progress. A verified key needs no box — its line above holds the
// Verify and Rotate actions.
echo '<a id="recovery-key"></a>';
if (!$recovery['is_ready'] || $rotating) {
	$page->begin_box(array('title' => 'Recovery key'));
	require_once(PathHelper::getIncludePath('includes/RecoveryKeySetupPanel.php'));
	RecoveryKeySetupPanel::render($page, array('state' => $recovery));
	$page->end_box();
}

// ── Offloaded files ─────────────────────────────────────────────────────────
// Files whose bytes live in the file bucket are in no archive; each is on the
// backup storage once, and the daily check asks the bucket whether it still has
// every one. Shown once the check has run or a Bring them back has happened,
// so a site that offloads nothing never sees an empty box.
if (BackupObjectsStatus::has_content($objects_status) || CloudStoreInventoryPanel::has_content($inventory)) {
	$page->begin_box(array('title' => 'Offloaded files'));
	// What each backup holds, what is still to copy, what waits here.
	$total = $objects_status['total'];
	echo '<p class="mb-1">' . htmlspecialchars(BackupObjectsStatus::files_words($total['count'], $total['bytes']))
	   . ' live in the cloud file store.</p>';
	foreach (BackupObjectsStatus::shelf_sentences($objects_status) as $line) {
		echo '<p class="mb-1">' . htmlspecialchars($line) . '</p>';
	}
	if (!$objects_status['enabled'] && $total['count'] > 0) {
		echo '<p class="mb-1 text-muted">No backup of this site stores offloaded files yet: a backup target of this site\'s own '
		   . '(with a proven recovery key and a backup type that includes files), or a management node, copies each one to '
		   . 'its backup storage from its next run on.</p>';
	}
	$catchup = BackupObjectsStatus::catchup_sentence($objects_status);
	if ($catchup !== '') {
		echo '<p class="mb-1">' . htmlspecialchars($catchup) . ' <span class="text-muted">Each run copies more, one at a time '
		   . 'inside its budget, until this reaches zero.</span></p>';
	}
	$waiting = BackupObjectsStatus::waiting_sentence($objects_status);
	if ($waiting !== '') {
		echo '<p class="mb-1">' . htmlspecialchars($waiting) . '</p>';
	}
	$same = BackupObjectsStatus::same_account_line($objects_status);
	if ($same !== '') {
		echo '<div class="alert alert-warning mb-2">' . htmlspecialchars($same) . '</div>';
	}
	if (CloudStoreInventoryPanel::has_content($inventory)) {
		echo '<hr class="my-2">';
		echo CloudStoreInventoryPanel::render($inventory, $objects_source, '/admin/admin_backups', $manager_url);
	}
	echo '<p class="text-muted small mt-2 mb-0">Files moved to the cloud file store are served from there and are not '
	   . 'in the backup archives; each is copied to backup storage once instead, and its local copy stays on this server '
	   . 'until every backup that stores offloaded files holds it. Once a day every offloaded file is '
	   . 'checked in the file store. <a href="/admin/admin_cloud_storage">Cloud storage</a></p>';
	$page->end_box();
}

// ── Targets and schedule ────────────────────────────────────────────────────
// Configured here only when this site runs its own backups. On a managed node
// the target, schedule and retention live on the management node, so both boxes
// are hidden rather than offering this admin settings the management node would
// overrule. (The recovery key above stays: it is held on this machine and the
// management node cannot set it.)
if (!$is_managed):

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
		$ft->hiddeninput('bkt_backup_target_id', '', array('value' => (int)$t->key));
		$ft->submitbutton('btn_test_' . (int)$t->key, 'Test', array('class' => 'btn btn-sm btn-outline-secondary'));
		$ft->end_form();
		$fd = $page->getFormWriter('del_' . (int)$t->key);
		$fd->begin_form();
		$fd->hiddeninput('action', '', array('value' => 'delete_target'));
		$fd->hiddeninput('bkt_backup_target_id', '', array('value' => (int)$t->key));
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
	$fw->hiddeninput('bkt_backup_target_id', '', array('value' => $editing ? (int)$editing->key : ''));
	$fw->textinput('bkt_name', 'Name', array('required' => true, 'value' => $editing ? $editing->get('bkt_name') : ''));
	$fw->dropinput('bkt_provider', 'Provider', array(
		'options' => array('b2' => 'Backblaze B2', 's3' => 'Amazon S3', 'linode' => 'Linode Object Storage'),
		'value'   => $editing ? $editing->get('bkt_provider') : 'b2',
		// B2 needs neither: both are detected from the key at save time
		// (b2_authorize_account), so the fields only invite wrong values.
		'visibility_rules' => array(
			'b2'     => array('hide' => array('region', 'endpoint')),
			's3'     => array('show' => array('region', 'endpoint')),
			'linode' => array('show' => array('region', 'endpoint')),
		),
	));
	$fw->textinput('bkt_bucket', 'Bucket', array('value' => $editing ? (string)$editing->get('bkt_bucket') : '',
		'helptext' => 'A private bucket used for nothing else.'));
	$fw->textinput('bkt_path_prefix', 'Folder inside the bucket',
		array('value' => $editing ? (string)$editing->get('bkt_path_prefix') : 'joinery-backups'));
	$fw->textinput('access_key', 'Access key ID',
		array('autocomplete' => 'off', 'helptext' => ($editing ? 'Leave blank to keep the stored key. ' : '')
			. 'A key for this bucket only, with list, read, write and delete. Backblaze: listFiles, readFiles, writeFiles, deleteFiles. '
			. 'Amazon: s3:ListBucket, s3:GetObject, s3:PutObject, s3:DeleteObject.'));
	$fw->passwordinput('secret_key', 'Secret key',
		array('autocomplete' => 'new-password', 'helptext' => $editing ? 'Leave blank to keep the stored key.' : ''));
	$fw->textinput('region', 'Region', array('value' => ''));
	$fw->textinput('endpoint', 'Endpoint hostname',
		array('value' => '', 'helptext' => 'The provider\'s S3-compatible endpoint, e.g. s3.us-east-1.amazonaws.com.'));
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
	// Archives and dumps stream to the bucket and are never on this server;
	// what a run leaves behind is a chain run's metadata artifact or a
	// standalone archive's envelope file, and that is what these two govern.
	echo '<tr><th>Keeping</th><td>Newest ' . $keep . ' offsite; what a run leaves on this server '
	   . ($settings->get_setting('backup_delete_local_after_upload') === '1'
	       ? 'is removed once uploaded'
	       : ($local_days > 0 ? 'is kept ' . $local_days . ' days' : 'is kept'))
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

endif; // targets + schedule shown only when this site runs its own backups

// ── Recent backups ──────────────────────────────────────────────────────────
// One row per run, newest first, whoever ran it: a site's own runs and a
// management node's copies of it both seal to this site's recovery key, so they
// belong in one list — each row says what kind of backup it was and who ran it.
// Only this site's own runs carry a Hide action; a management node's records
// are read-only here.
$page->begin_box(array('title' => 'Recent backups'));

$hrows = array();
foreach ($history as $h) { $hrows[] = $h; }

$backup_kind = function ($h) {
	if ((string)$h->get('bkh_type') === 'database') { return 'Database only'; }
	if ((string)$h->get('bkh_chain_id') === '') { return 'Full'; }
	return ((int)$h->get('bkh_chain_seq') === 0) ? 'Full' : 'Incremental';
};

if (!$hrows) {
	echo '<p class="text-muted mb-0">No backups have run yet.</p>';
} else {
	if ($is_managed) {
		echo '<p class="text-muted small">'
		   . ($manager_url !== '' ? '<code>' . htmlspecialchars($manager_url) . '</code>' : 'A management node')
		   . ' runs this site\'s backups and stores them offsite; they open with this site\'s recovery key. '
		   . 'An incremental backup holds what changed since the run before it and restores together with '
		   . 'the last full backup before it. A restore is started from the management node and approved '
		   . 'on this page.</p>';
	} else {
		echo '<p class="text-muted small">Newest first. Runs this site made itself and runs a management node '
		   . 'made on its behalf both open with this site\'s recovery key. An incremental backup holds what '
		   . 'changed since the run before it and restores together with the last full backup before it.</p>';
	}
	echo '<table class="table"><thead><tr>'
	   . '<th>When</th><th>Backup</th><th>Run by</th><th>Result</th><th>Size</th><th>Availability</th><th></th>'
	   . '</tr></thead><tbody>';
	foreach ($hrows as $h) {
		$outcome    = (string)$h->get('bkh_outcome');
		$is_manager = ($h->get('bkh_profile') === BackupProfile::MANAGER);
		$message    = (string)$h->get('bkh_message');
		echo '<tr>';
		echo '<td>' . htmlspecialchars($when($h->get('bkh_start_time'))) . '</td>';
		echo '<td>' . htmlspecialchars($backup_kind($h)) . '</td>';
		echo '<td>' . ($is_manager
			? 'Management node' . ($manager_url !== ''
				? ' <span class="text-muted small">(' . htmlspecialchars($manager_url) . ')</span>'
				: '')
			: 'This site') . '</td>';
		echo '<td>' . ($outcome === 'failed'
			? '<strong>failed</strong>'
			: htmlspecialchars($outcome));
		// A successful run's message restates the row ("Incremental run 3 of
		// chain-..."); only a warning inside it is worth the reader's eye. A
		// failure's message is the reason, and always shown.
		if ($message !== '' && ($outcome !== 'success' || stripos($message, 'WARNING') !== false)) {
			echo '<div class="small text-muted">' . htmlspecialchars($message) . '</div>';
		}
		echo '</td>';
		echo '<td>' . htmlspecialchars(BackupRunner::human($h->get('bkh_bytes'))) . '</td>';
		// Whether the backup is still there, stated on every row so present and
		// cleaned-up read differently at a glance. Retention that cleaned a backup
		// up stamped bkh_pruned_time; its upload_time survives the prune, so pruned
		// is checked first or a gone backup would still read as present offsite.
		//
		// A management node's run is the one case this site cannot answer. It was
		// uploaded with a credential that can neither list nor delete, to a shelf
		// the management node prunes on its own schedule, and that prune is never
		// reported back here — bkh_pruned_time on these rows is stamped by nobody,
		// so they would read Present forever, including backups deleted weeks ago.
		// The row states what this machine actually witnessed, which is the upload,
		// and says who owns the copy from there on.
		$pruned = (bool)$h->get('bkh_pruned_time');
		// Verified restorable, or failed verification, from the stamp the
		// verify left on this run — shown with the availability, since "still
		// there" and "proven to open" are the two halves of one answer.
		// A skip or a refusal stamps the message only, so the last real
		// result stands and the attempt's reason rides beside it.
		$verify_note = '';
		$verify_message = (string)$h->get('bkh_verify_message');
		$attempt = BackupVerifier::is_attempt_message($verify_message);
		if ((string)$h->get('bkh_verify_time') !== '') {
			$verify_note = ((string)$h->get('bkh_verify_outcome') === 'pass')
				? '<div class="text-success small">verified restorable &middot; ' . htmlspecialchars($when($h->get('bkh_verify_time')))
				  . ' <span class="text-muted">(' . htmlspecialchars(BackupVerifier::level_name((int)$h->get('bkh_verify_level'))) . ')</span></div>'
				: '<div class="text-danger small">verification failed &middot; ' . htmlspecialchars($when($h->get('bkh_verify_time')))
				  . (($verify_message !== '' && !$attempt) ? ' &middot; ' . htmlspecialchars($verify_message) : '')
				  . '</div>';
			if ($attempt) {
				$verify_note .= '<div class="text-muted small">since then: ' . htmlspecialchars($verify_message) . '</div>';
			}
		} elseif ($attempt) {
			$verify_note = '<div class="text-muted small">not verified &middot; ' . htmlspecialchars($verify_message) . '</div>';
		}
		echo '<td>';
		if ($pruned) {
			echo '<span class="text-muted">Cleaned up &middot; ' . htmlspecialchars($when($h->get('bkh_pruned_time'))) . '</span>';
		} elseif ($outcome === 'failed') {
			echo '<span class="text-muted">not stored</span>';
		} elseif ($outcome === 'running') {
			echo '<span class="text-muted">in progress</span>';
		} elseif ($is_manager && $h->is_offsite()) {
			echo '<span class="text-muted">Uploaded</span> <span class="text-muted small">&middot; '
			   . htmlspecialchars((string)$h->get('bkh_target_name'))
			   . ', kept for as long as the management node keeps it</span>';
		} elseif ($h->is_offsite()) {
			echo '<span class="text-success">Present</span> <span class="text-muted small">&middot; '
			   . htmlspecialchars((string)$h->get('bkh_target_name')) . '</span>';
		} else {
			echo '<span class="text-success">Present</span> <span class="text-muted small">&middot; local only</span>';
		}
		echo $verify_note;
		echo '</td>';
		echo '<td>';
		// A management node owns its own records; this site hides only its own — and
		// hiding only removes the row from this list, never the stored backup. A
		// cleaned-up run is already gone, so it carries no Hide.
		if (!$is_manager && !$pruned) {
			$fh = $page->getFormWriter('delh_' . (int)$h->key);
			$fh->begin_form();
			$fh->hiddeninput('action', '', array('value' => 'delete_history'));
			$fh->hiddeninput('bkh_backup_history_id', '', array('value' => (int)$h->key));
			$fh->submitbutton('btn_delh_' . (int)$h->key, 'Hide', array('class' => 'btn btn-sm btn-outline-secondary'));
			$fh->end_form();
		}
		echo '</td></tr>';
	}
	echo '</tbody></table>';
}
$page->end_box();

$page->admin_footer();
