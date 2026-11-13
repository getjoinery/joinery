<?php
/**
 * node_detail — Backups tab partial.
 *
 * Included by views/admin/node_detail.php in the shell's scope; the shell
 * owns node loading, the tab whitelist, and the permission gate. Lives under
 * includes/ (not views/) so it is not reachable as a standalone URL.
 *
 * In scope: $node, $page, $session, $base_url, $node_name, $page_regex,
 * $skip_joinery, $tab.
 *
 * @version 1.25 - no inline script or handlers: buttons name a tab function in data-sm-do, the code is
 *                 assets/js/node-backups.js with #node-backups-config
 * @version 1.24 - Backup Files is Other backup files, below the backups, saying what belongs in it; ledger
 *                 receipts and offloaded-file objects are never listed there (BackupListHelper)
 * @version 1.23 - Verify the newest backup beside the backed-up line at the top: it opens the newest run's own Verify
 * @version 1.22 - a site's own getjoinery backup storage target is never offered as where a node's backups move
 * @version 1.21 - Prepare stages its own row's run (it staged only the newest), and Restore is offered for a run
 *                 once it is prepared on the node, never queued for the node to refuse (B39, B40)
 * @version 1.20 - a scan that finishes while a dialog is open offers a reload instead of reloading under it, which
 *                 threw away a restore the operator was in the middle of confirming
 * @version 1.19 - each run shows its manifest and the sha256 recorded when it was written, and a backup set whose
 *                 newest manifest differs from the recorded one says so (specs/storage_targets.md WP5)
 * @version 1.18 - the node's storage spaces: where new backups go, older backups kept on a target it was moved
 *                away from, and Move; every cloud action names the space its backup is in; one unreadable
 *                space no longer hides the chains of the others (specs/storage_targets.md WP4)
 * @version 1.17 - the provider label is StorageProvider's; the target line is the one the node names (nothing
 *                 inferred), and a switched-off target is named as such
 * @version 1.16 - each run shows its newest verify (passed or failed, when, how deep), from the verify jobs sent
 * @version 1.15 - the Verify room is worked out from a listing that carries the manifest version and each
 *                artifact's level, so a version-2 chain is planned per kind as the node plans it
 * @version 1.14 - retention: the site's own window, at least policy_keep_days; the tab says which applies
 * @version 1.13 - Recent runs from here: the last runs this plane dispatched, each with its outcome and,
 *                 when the run said, its level and size (BACKUP_LEVEL / BACKUP_BYTES)
 * @version 1.12 - "Offloaded files in backup storage: N objects, X GB by <party>; last indexed at the run of …"
 *                 from the listing's object-store totals (BackupChainListHelper 1.3)
 * @version 1.11 - Bring them back: the node's offloaded files the file store has lost, brought home from the
 *                 newest run that carries an offloaded-files index, as the paged job loop the plane drives
 *                 (restore_objects through backup_actions); the poller reports the survey and points at the jobs
 * @version 1.10 - verified restorable: the last verify is stated with the three facts above the run
 *                 list, a Verify button per run offers "open and read" or "rehearse a restore" (with
 *                 the room the node needs), the schedule sentence says how often the newest backup is
 *                 verified, and the policy editor has the days-between-verifications field
 * @version 1.9 - the schedule summary and the fleet-default dropdown share one sentence that says
 *                what is kept: N full backups with their incrementals, and how many days that is
 * @version 1.8 - backup storage is listed as one row per backup run (when, full or incremental, who took
 *                it, size) with the last backup, last full backup and oldest backup held stated above it;
 *                restore is offered per run. Chains and restore points are how it is stored, not what
 *                an operator is looking for
 * @version 1.7 - a failing fleet backup links the failed job next to its reason
 * @version 1.6 - the backup-target line and recoverable box resolve backup storage via get_target(), the
 *                same fallback the job builder uses, so a node that names no target but backs up to the
 *                sole enabled backup storage reads as cloud-backed instead of "Local only"
 * @version 1.5 - recoverability is read from the NODE's own verified recovery key, not this management
 *                node's: backups seal to the key the node holds, so a node without a verified one
 *                is shown as unable to back up and the run button is not offered
 * @version 1.4 - incremental chains are listed and restorable (they are what the schedule actually
 *                produces); every restore asks which domain the site is to answer to; the Apache
 *                choice is gone, because the serving config is always regenerated for this machine
 * @version 1.3 - per-file "Upload to cloud" action for a backup sitting local-only, with a poller that
 *                reports the job's real verdict (a failed transfer must not read as done)
 * @version 1.2 - forced encryption is read from JobCommandBuilder::get_target(), the same source the
 *                job builder uses (a non-B2 cloud target had the form offering a choice the builder
 *                overruled, then the job was refused); Seal backup key now runs as a job
 * @version 1.1 - never offers a backup that will be refused: a cloud-target node with backup key
 *                recovery unfinished gets the explanation and a link to the walkthrough in place
 *                of the Run Backup forms, and a local-only node has encryption switched off and
 *                explained rather than failing at job creation
 * @version 1.0
 */

// A button that calls one of the tab's own functions (assets/js/node-backups.js),
// named in data-sm-do with its arguments as JSON; with $btn_last the button
// itself is passed last. No inline handler (strict script policy).
$sm_do = function (string $fn, array $args = array(), bool $btn_last = false): string {
	return ' data-sm-do="' . htmlspecialchars($fn, ENT_QUOTES) . '"'
		. ' data-sm-args="' . htmlspecialchars(json_encode(array_values($args)), ENT_QUOTES) . '"'
		. ($btn_last ? ' data-sm-this' : '');
};

	// Where this node's backups go: its active storage space, and nothing
	// else (specs/storage_targets.md R6). A switched-off target still holds
	// the backups already there, so it is named, with what that means. Any
	// space the node was moved away from is listed too: its backups stay
	// readable and restorable there until they age out.
	require_once(PathHelper::getIncludePath('data/backup_targets_class.php'));
	$node_spaces   = StorageSpace::of_owner(StorageSpace::OWNER_NODE, (int)$node->key);
	$cloud_target  = JobCommandBuilder::get_target($node);
	$target_name   = 'None: this management node takes no backups of it';
	if ($cloud_target) {
		$target_name = htmlspecialchars($cloud_target->get('bkt_name')) . ' (' . htmlspecialchars(StorageProvider::label($cloud_target->get('bkt_provider'))) . ')';
		if (!$cloud_target->get('bkt_enabled')) {
			$target_name .= ' &mdash; <span class="text-muted">switched off: the backups already there stay readable, and no new backup is taken until the node is moved or the target switched on</span>';
		}
	}

	echo '<div class="alert alert-light border mb-3">';
	echo '<strong>Backup target:</strong> ' . $target_name;
	foreach ($node_spaces as $sp) {
		if (!$sp->is_draining()) { continue; }
		$old = $sp->target();
		echo '<div class="small text-muted mt-1">Older backups are kept on '
			. htmlspecialchars($old ? $old->get('bkt_name') : 'a deleted target')
			. ' since ' . htmlspecialchars(substr((string)$sp->get('sps_draining_time'), 0, 10))
			. ': readable and restorable, kept whole until the new target holds a verified backup, then aged out.</div>';
	}
	// Move: new backups go to another target, which starts a full backup
	// there; nothing is copied (R3).
	$move_options = [];
	foreach (new MultiBackupTarget(['deleted' => false], ['bkt_name' => 'ASC']) as $t) {
		// Not this site's own getjoinery backup storage: it holds this site's backups, not its nodes'.
		if ($t->get('bkt_enabled') && !$t->is_service() && (!$cloud_target || (int)$t->key !== (int)$cloud_target->key)) {
			$move_options[(string)(int)$t->key] = $t->get('bkt_name') . ' (' . StorageProvider::label($t->get('bkt_provider')) . ')';
		}
	}
	if ($move_options) {
		$fw_move = $page->getFormWriter('move_backup_storage_form');
		$fw_move->begin_form();
		$fw_move->hiddeninput('action', '', ['value' => 'move_backup_storage']);
		$fw_move->hiddeninput(SmAdminCsrf::FIELD, '', ['value' => SmAdminCsrf::token()]);
		$fw_move->dropinput('bkt_backup_target_id', $cloud_target ? 'Move new backups to' : 'Back up to', [
			'options'  => $move_options,
			'helptext' => 'The next backup starts a full backup there. Backups already taken stay where they are until they age out.',
		]);
		$fw_move->submitbutton('btn_move_backup_storage', $cloud_target ? 'Move' : 'Start', ['class' => 'btn btn-sm btn-outline-primary']);
		$fw_move->end_form();
	}
	echo '</div>';

	// Whether backups of this node can be recovered at all — which is a question
	// about the NODE, not about this management node. Every backup seals to the
	// recovery key the node holds and has proven, read on the node; nothing is
	// supplied from here, so this management node's own key has no bearing on
	// whether this node can be backed up or by whom its archives can be opened.
	require_once(PathHelper::getIncludePath('includes/BackupRecoveryKey.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/RecoveryKeyFleet.php'));
	$rk_node        = RecoveryKeyFleet::node_state($node);
	$recovery_ready = ($rk_node['state'] === 'n/a') || RecoveryKeyFleet::has_own_key($rk_node);

	// Encryption is forced for exactly the nodes whose archives leave the box —
	// $cloud_target is the same value the job builder resolves (computed above),
	// so the form can never offer a choice the builder is going to overrule. (An
	// enabled target is what counts: a disabled one uploads nothing.)
	$require_encryption = (bool) $cloud_target;

	// When backups are paused outright (below), that box says everything this
	// status alert would — so it is not repeated here.
	if ($cloud_target && $recovery_ready && $rk_node['fingerprint'] !== '') {
		echo '<div class="alert alert-success border mb-3 py-2">';
		echo '<strong>Backups are recoverable.</strong> Every backup carries its own key sealed to this '
			. 'node\'s verified recovery key '
			. htmlspecialchars(RecoveryKeyFleet::short($rk_node['fingerprint'])) . '&hellip; &mdash; '
			. 'held by whoever administers the node, and the only key that opens these archives.';
		echo '</div>';
	}

	// One key, one custodian, both kinds of backup. The node's own scheduled
	// backups and the copies taken from here seal to the same thing: the key the
	// node holds. So this line is not a footnote about the node's private
	// arrangements — it is the coverage statement for everything on this tab.
	if ($rk_node['state'] !== 'n/a') {
		echo '<div class="alert alert-' . ($recovery_ready ? 'light' : 'warning') . ' border mb-3 py-2">';
		echo '<strong>This node\'s recovery key:</strong> ' . htmlspecialchars($rk_node['summary']);
		if ($rk_node['fingerprint'] !== '') {
			echo ' (' . htmlspecialchars(RecoveryKeyFleet::short($rk_node['fingerprint'])) . '&hellip;)';
		}
		if (!$recovery_ready) {
			echo '<div class="small mt-1">' . htmlspecialchars(RecoveryKeyFleet::blocker_summary($rk_node))
			   . '</div>';
		}
		echo '</div>';
	}

	// ── Run a backup now ──
	//
	// One button, running the same thing the schedule runs: same engine on the
	// node, same chain, same shelf, same retention. An on-demand backup that
	// landed anywhere else would be a restore point nobody's retention was
	// counting, and a chain nobody was extending.
	$pageoptions = ['title' => 'Backups taken from here'];
	$page->begin_box($pageoptions);

	if (!$cloud_target) {
		// No shelf, so there is nothing for this management node to take a copy
		// onto. Not a fault of the node's — say what would change it.
		echo '<div class="alert alert-light border mb-0">';
		echo '<strong>No backups are taken of this node from here.</strong> ';
		echo 'Backups this management node takes go to its own cloud storage, and this node has no '
		   . 'backup target set. ';
		echo 'Choose one above to start backing it up.';
		echo '</div>';
	} elseif (!$cloud_target->get('bkt_enabled')) {
		// A switched-off target keeps what it holds and takes nothing new, so
		// a run would be refused; say so instead of offering one.
		echo '<div class="alert alert-light border mb-0">';
		echo '<strong>No new backups are taken of this node.</strong> ';
		echo 'Its backup target, ' . htmlspecialchars($cloud_target->get('bkt_name')) . ', is switched off. '
		   . 'The backups already there stay readable and restorable. ';
		echo 'Move the node above, or switch the target on, to start again.';
		echo '</div>';
	} elseif (!$recovery_ready) {
		// A backup nobody can decrypt is not a backup, so the run is refused —
		// on the node, whatever this page believes, and again here when the job
		// is built. Don't offer a button that is going to be refused; say what
		// would change it, and say plainly that it cannot be changed from here.
		echo '<div class="alert alert-warning mb-0">';
		echo '<strong>No backups of this node can run.</strong> ';
		echo 'These backups leave the node, so they are encrypted &mdash; and an encrypted backup '
		   . 'nobody can open is not a backup. ';
		echo htmlspecialchars(RecoveryKeyFleet::blocker_summary($rk_node));
		echo '<div class="small mt-1">The node\'s own Backups page generates a key and runs the '
		   . 'verification challenge in one pass, at <code>' . htmlspecialchars(BackupRecoveryKey::SETUP_URL)
		   . '</code> on the node. Then run a status check here and this clears.</div>';
		echo '</div>';
	} else {
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/FleetBackupPolicy.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/NodeMonitorHealth.php'));
		$policy = FleetBackupPolicy::for_node($node);

		// The schedule and the last run, together. Either alone is misleading:
		// a schedule that has never fired reads as coverage, and a last run with
		// no schedule reads as ongoing.
		// One sentence for a policy, used for this node's summary and for the
		// fleet default in the dropdown below, so the two never describe the
		// same settings in two ways.
		$describe_policy = function (array $p) {
			$keep = (int)$p['keep_days'];
			$kept = ', keeping the site\'s own window of backups, at least ' . $keep . ' day' . ($keep === 1 ? '' : 's');
			// How often the newest backup is proven restorable, in the same
			// sentence as the schedule: a schedule nobody verifies is a hope.
			$every = (int)($p['verify_every_days'] ?? 0);
			$verify = ($every > 0)
				? ', verified every ' . $every . ' day' . ($every === 1 ? '' : 's') . ' by opening and reading the newest backup'
				: ', never verified';
			if ($p['mode'] === 'chain') {
				$days = (int)$p['full_interval_days'];
				return $p['frequency'] . ' backups: a full backup every ' . $days . ' day' . ($days === 1 ? '' : 's')
				   . ' and incrementals in between' . $kept . $verify;
			}
			return $p['frequency'] . ' full backups' . $kept . $verify;
		};
		echo '<p class="mb-1">';
		if (!empty($policy['enabled'])) {
			echo '<strong>Scheduled:</strong> ' . htmlspecialchars($describe_policy($policy))
			   . ', starting at ' . htmlspecialchars(FleetBackupPolicy::slot_time($policy, (string)$node->get('mgn_slug')));
			// What that comes to for this node, and whose number it is.
			$site_days = (int)$node->get('mgn_backup_keep_days');
			$kept_days = FleetBackupPolicy::retention_days($policy, $node);
			echo '. <strong>Keeping ' . $kept_days . ' days:</strong> '
			   . htmlspecialchars($site_days <= 0
			       ? 'the site has not reported its own setting yet, so its default of ' . BackupRunner::DEFAULT_KEEP_DAYS . ' days'
			       : ($site_days >= (int)$policy['keep_days']
			           ? 'the site\'s own setting'
			           : 'the site asks for ' . $site_days . ', raised to the minimum here'));
		} else {
			echo '<strong>Not scheduled.</strong> This management node takes no backups of this node '
			   . 'except when someone runs one.';
		}
		echo '</p>';

		$health = NodeMonitorHealth::fleet_backup_health($node, $policy);
		echo '<p class="' . ($health['is_problem'] ? 'text-danger' : 'text-muted') . '">'
		   . '<strong>' . htmlspecialchars($health['label']) . ':</strong> '
		   . htmlspecialchars($health['detail'])
		   . (!empty($health['job_id'])
		       ? ' <a href="/admin/server_manager/job_detail?job_id=' . (int)$health['job_id'] . '">See the failed job.</a>'
		       : '')
		   . ' <button type="button" class="btn btn-outline-primary btn-sm ms-1" id="verifyNewestTop" hidden'
		   . ' data-sm-do="verifyNewest">'
		   . 'Verify the newest backup</button>'
		   . '</p>';
		// Shown once the backup list below has a run to verify: it opens that
		// run's own Verify dialog, so there is one verify path, near the top.
		// (node-backups.js reveals it.)

		// The runs this plane dispatched, with the level and size each one
		// printed. The storage listing below says what is kept; this says what
		// each run did, including the ones that failed.
		$recent_runs = NodeMonitorHealth::backup_runs_from_here((int)$node->key, 7);
		if ($recent_runs) {
			echo '<details class="mb-3"><summary>Recent runs from here</summary>'
			   . '<table class="table table-sm mb-0"><thead><tr><th>When</th><th>Outcome</th><th>Level and size</th><th></th></tr></thead><tbody>';
			foreach ($recent_runs as $run) {
				$when = $run['time'] !== ''
					? LibraryFunctions::convert_time($run['time'], 'UTC', $session->get_timezone(), 'M j, Y g:i A T')
					: '';
				echo '<tr><td>' . htmlspecialchars($when) . '</td>'
				   . '<td>' . htmlspecialchars($run['outcome'] !== '' ? $run['outcome'] : 'not yet read') . '</td>'
				   . '<td>' . htmlspecialchars(NodeMonitorHealth::backup_run_figures($run)) . '</td>'
				   . '<td><a href="/admin/server_manager/job_detail?job_id=' . (int)$run['id'] . '">Job</a></td></tr>';
			}
			echo '</tbody></table></details>';
		}

		echo '<p class="text-muted">Encrypted on the node, sealed to the node\'s own verified recovery key '
		   . htmlspecialchars(RecoveryKeyFleet::short($rk_node['fingerprint'])) . '&hellip; and to the '
		   . 'node itself, then uploaded to ' . $target_name . '. No key is sent from here, so these '
		   . 'archives open only with the private half held by the node\'s administrator.</p>';

		$fw_run = $page->getFormWriter('backup_run_form');
		$fw_run->begin_form();
		$fw_run->hiddeninput('action', '', ['value' => 'backup_run']);
		$fw_run->hiddeninput(SmAdminCsrf::FIELD, '', ['value' => SmAdminCsrf::token()]);
		// Not 'backup_type': that is a declared core setting name, and a page may
		// not draw its own field for one. This is a job parameter for one run,
		// not a setting anybody is storing.
		$fw_run->dropinput('backup_scope', 'What to back up', [
			'options' => ['project' => 'The whole site (files and database)', 'database' => 'Database only'],
			'value'   => 'project',
		]);
		$fw_run->submitbutton('btn_backup_run', 'Run backup now', ['class' => 'btn btn-sm btn-primary']);
		$fw_run->end_form();

		// ── The schedule itself ──
		//
		// Three positions. "Fleet default" stores nothing, so the node follows
		// the fleet settings including future changes; "custom" freezes a full
		// field set of this node's own; "off" is a decision, stored as one —
		// which is what lets the dashboard treat a node without backups as
		// somebody's choice rather than a gap.
		$fleet = FleetBackupPolicy::fleet_defaults();
		$default_label = 'Fleet default — '
			. (!empty($fleet['enabled']) ? $describe_policy($fleet) : 'no scheduled backups');

		echo '<hr>';
		$fw_pol = $page->getFormWriter('backup_policy_form');
		$fw_pol->begin_form();
		$fw_pol->hiddeninput('action', '', ['value' => 'save_backup_policy']);
		$fw_pol->hiddeninput(SmAdminCsrf::FIELD, '', ['value' => SmAdminCsrf::token()]);

		$custom_fields = ['policy_schedule', 'policy_window_start', 'policy_window_minutes',
			'policy_mode', 'policy_keep_days', 'policy_full_interval_days', 'policy_verify_every_days'];
		$fw_pol->dropinput('backup_policy_source', 'Schedule for this node', [
			'options' => [
				'default' => $default_label,
				'custom'  => 'A schedule of this node\'s own',
				'off'     => 'Off — no scheduled backups of this node from here',
			],
			'value' => FleetBackupPolicy::stored_mode($node),
			'visibility_rules' => [
				'default' => ['hide' => $custom_fields],
				'custom'  => ['show' => $custom_fields],
				'off'     => ['hide' => $custom_fields],
			],
		]);

		// Prefilled from the effective policy, so choosing "custom" starts from
		// what the node is doing today rather than from blanks.
		$fw_pol->dropinput('policy_schedule', 'How often', [
			'options' => [
				'daily' => 'Every day',
				'0' => 'Weekly, on Sunday',    '1' => 'Weekly, on Monday',
				'2' => 'Weekly, on Tuesday',   '3' => 'Weekly, on Wednesday',
				'4' => 'Weekly, on Thursday',  '5' => 'Weekly, on Friday',
				'6' => 'Weekly, on Saturday',
			],
			'value' => ($policy['frequency'] === 'weekly') ? (string)$policy['day_of_week'] : 'daily',
		]);
		$fw_pol->textinput('policy_window_start', 'Window starts (UTC, HH:MM)', [
			'value' => $policy['window_start'],
		]);
		$fw_pol->numberinput('policy_window_minutes', 'Window length (minutes)', [
			'value'    => $policy['window_minutes'],
			'min'      => 1,
			'helptext' => 'The node gets its own start minute inside the window, so a fleet does not upload all at once.',
		]);
		$fw_pol->dropinput('policy_mode', 'How backups are taken', [
			'options' => ['chain' => 'A full backup, then incrementals', 'full' => 'A full backup every time'],
			'value'   => $policy['mode'],
		]);
		$fw_pol->numberinput('policy_keep_days', 'Fewest days of backups kept', [
			'value'    => $policy['keep_days'],
			'min'      => 1,
			'helptext' => 'The site chooses how many days of backups it keeps; this is the floor under its choice, so an intruder on the site cannot shorten the window to erase its history. A full backup and the incrementals after it are kept or deleted together, never partly.',
		]);
		$fw_pol->numberinput('policy_full_interval_days', 'Days between full backups', [
			'value' => $policy['full_interval_days'],
			'min'   => 0,
		]);
		$fw_pol->numberinput('policy_verify_every_days', 'Days between verifications (0 = never)', [
			'value'    => $policy['verify_every_days'],
			'min'      => 0,
			'helptext' => 'The newest backup is opened and read on the node to prove it restorable: every archive '
			            . 'it depends on is downloaded, decrypted with the node\'s own key and read to the end, then '
			            . 'removed. Nothing on the site is touched. Each verification downloads the whole set once.',
		]);

		$fw_pol->submitbutton('btn_save_backup_policy', 'Save schedule', ['class' => 'btn btn-sm btn-secondary']);
		$fw_pol->end_form();
	}

	$page->end_box();

	// ── Backup File Browser ──
	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/BackupListHelper.php'));
	require_once(PathHelper::getIncludePath('includes/BackupNaming.php'));
	$backup_list = BackupListHelper::get_for_node($node);
	$last_scan = $backup_list['last_scan'];
	$files = $backup_list['files'];
	$cloud_error = $backup_list['cloud_error'];
	if ($cloud_error) {
		echo '<div class="alert alert-warning">Cloud listing failed: ' . htmlspecialchars($cloud_error) . '</div>';
	}

	// Where Verify, Prepare and the scan report their progress: above the
	// backups, so it is in view of the buttons that start them.
	echo '<div id="backupScanStatus" hidden class="mb-2"></div>';

	// The standalone archives box goes last: rendered here, shown below the
	// backups, which are what a person comes to this tab for.
	ob_start();
	$pageoptions = ['title' => 'Other backup files'];
	$page->begin_box($pageoptions);
	echo '<p class="text-muted small">Single-file backups, in this node\'s backup storage or on the node, that are '
	   . 'not part of the backups above: a whole-site download, or a backup taken before backups were kept in sets. '
	   . 'Usually empty.</p>';

	echo '<div class="d-flex justify-content-between align-items-center mb-2">';
	if ($last_scan) {
		echo '<small class="text-muted">Last scanned: ' . LibraryFunctions::convert_time($last_scan, 'UTC', $session->get_timezone(), 'M j, g:i A') . '</small>';
	} else {
		echo '<small class="text-muted">No scan performed yet</small>';
	}
	echo '<button type="button" class="btn btn-sm btn-outline-primary" id="refreshBackupsBtn" data-sm-do="refreshBackupList">Scan for Backups</button>';
	echo '</div>';

	echo '<table class="table table-striped table-sm" id="backupFilesTable">';
	echo '<thead><tr><th>Filename</th><th>Size</th><th>Date</th><th>Location</th><th>Actions</th></tr></thead>';
	echo '<tbody>';

	if (!empty($files)) {
		$location_labels = ['local' => 'Local', 'cloud' => 'Cloud', 'both' => 'Local + Cloud'];
		foreach ($files as $f) {
			$fn = htmlspecialchars($f['filename']);
			$loc = $location_labels[$f['location'] ?? 'local'] ?? $f['location'];
			$loc_class = ($f['location'] === 'both') ? 'success' : (($f['location'] === 'cloud') ? 'info' : 'secondary');

			echo '<tr>';
			echo '<td><small>' . $fn . '</small></td>';
			echo '<td>' . htmlspecialchars($f['size'] ?? '-') . '</td>';
			echo '<td>' . htmlspecialchars($f['date'] ?? '-') . '</td>';
			echo '<td><span class="badge bg-' . $loc_class . '">' . $loc . '</span></td>';
			echo '<td>';

			$local_path = $f['local_path'] ?? '';
			$cloud_path = $f['cloud_path'] ?? '';
			$has_local = !empty($local_path);
			$has_cloud = !empty($cloud_path);

			if ($has_local && $has_cloud) {
				$target = 'both';
			} elseif ($has_local) {
				$target = 'local';
			} elseif ($has_cloud) {
				$target = 'cloud';
			} else {
				$target = '';
			}

			$restore_type = BackupNaming::restore_type($f['filename']);

			if ($restore_type) {
				echo '<button type="button" class="btn btn-outline-warning btn-sm me-1"' . $sm_do('openRestoreModal', array($restore_type, $fn, $local_path, $cloud_path)) . '>Restore</button>';
			}

			// A backup that exists ONLY in the cloud cannot be restored — the
			// node's restore takes a name it resolves in its own backup
			// directory, and every node in this fleet deletes its local copy
			// after upload, so this is the normal state rather than an unusual
			// one. Bringing it back is a separate, non-destructive step: the
			// object is signed here and the node checks what arrives against its
			// own upload ledger before it lands.
			if ($has_cloud && !$has_local) {
				echo '<button type="button" class="btn btn-outline-primary btn-sm me-1"' . $sm_do('downloadBackup', array($fn, $cloud_path), true) . '>Bring back to node</button>';
			}

			// A backup that exists only on the node is one disk failure from gone —
			// which is the state an upload interrupted by a transient provider error
			// leaves behind. Offer the push whenever there is somewhere to push to.
			if ($has_local && !$has_cloud && $cloud_target) {
				echo '<button type="button" class="btn btn-outline-primary btn-sm me-1"' . $sm_do('uploadBackup', array($fn, $local_path), true) . '>Upload to cloud</button>';
			}

			if ($target) {
				echo '<button type="button" class="btn btn-outline-danger btn-sm"' . $sm_do('deleteBackup', array($target, $fn, $local_path, $cloud_path)) . '>Delete</button>';
			}

			echo '</td>';
			echo '</tr>';
		}
	} else {
		echo '<tr><td colspan="5" class="text-muted text-center">' . ($last_scan ? 'None' : 'Click "Scan for Backups" to see files') . '</td></tr>';
	}

	echo '</tbody></table>';

	$page->end_box();
	$other_files_box = ob_get_clean();

	// ── Backups in backup storage ──
	//
	// One row per run, newest first, whichever chain it sits in. A chain (one
	// full plus the incrementals after it) is how backup storage is organised and how
	// a restore replays; what an operator asks is when the last backup was, when
	// the last full was, and how far back the oldest reaches — so those are
	// stated first, and every run is offered for restore on its own row. Chain
	// artifacts are deliberately absent from the flat file list above, which
	// would otherwise offer an incremental with no full under it.
	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/BackupChainListHelper.php'));
	$chain_list = BackupChainListHelper::for_node($node);

	if ($chain_list['error'] || !empty($chain_list['chains'])) {
		$pageoptions = ['title' => 'Backups'];
		$page->begin_box($pageoptions);

		if ($chain_list['error']) {
			echo '<div class="alert alert-warning">Could not read all of the backup listing: '
			   . htmlspecialchars($chain_list['error']) . '</div>';
		}
		if (!empty($chain_list['chains'])) {
			$profile_labels = ['manager' => 'This management node', 'site' => 'The site itself'];
			$shelf_runs = [];
			foreach ($chain_list['chains'] as $c) {
				foreach ($c['runs'] as $r) {
					$shelf_runs[] = ['chain' => $c, 'run' => $r];
				}
			}
			usort($shelf_runs, function ($x, $y) {
				return strcmp((string)$y['run']['time'], (string)$x['run']['time']);
			});
			$run_when = function ($r) { return $r['time'] !== '' ? substr((string)$r['time'], 0, 16) . ' UTC' : 'unknown time'; };
			$run_line = function ($entry) use ($run_when, $profile_labels) {
				if (!$entry) { return '<span class="text-muted">none</span>'; }
				return htmlspecialchars($run_when($entry['run']))
				   . ' <span class="text-muted small">&middot; ' . ($entry['run']['level'] === 0 ? 'full' : 'incremental')
				   . ' &middot; ' . htmlspecialchars(BackupChainListHelper::format_size($entry['run']['bytes']))
				   . ' &middot; by ' . htmlspecialchars(strtolower($profile_labels[$entry['chain']['profile']] ?? $entry['chain']['profile']))
				   . '</span>';
			};
			$newest_full = null;
			foreach ($shelf_runs as $entry) { if ($entry['run']['level'] === 0) { $newest_full = $entry; break; } }
			// The last verify, beside the three facts about backup storage. This is
			// the plane's copy of what the node proved (mgn_backup_verify_*),
			// refreshed from every verify job and every status report.
			require_once(PathHelper::getIncludePath('includes/BackupVerifier.php'));
			$verify_time    = trim((string)$node->get('mgn_backup_verify_time'));
			$verify_outcome = (string)$node->get('mgn_backup_verify_outcome');
			$verify_message = trim((string)$node->get('mgn_backup_verify_message'));
			$verify_level   = BackupVerifier::level_name((int)$node->get('mgn_backup_verify_level'));
			if ($verify_time === '') {
				$verify_line = '<span class="text-muted">never' . ($verify_message !== '' ? ' &mdash; ' . htmlspecialchars($verify_message) : '') . '</span>';
			} elseif ($verify_outcome === 'pass') {
				$verify_line = htmlspecialchars(substr($verify_time, 0, 16)) . ' UTC'
				   . ' <span class="text-muted small">&middot; ' . htmlspecialchars($verify_level ?: 'verified')
				   . ($verify_message !== '' ? ' &middot; ' . htmlspecialchars($verify_message) : '') . '</span>';
			} else {
				$verify_line = '<span class="text-danger">failed ' . htmlspecialchars(substr($verify_time, 0, 16)) . ' UTC'
				   . ($verify_message !== '' ? ' &mdash; ' . htmlspecialchars($verify_message) : '') . '</span>';
			}
			echo '<table class="table table-sm mb-3"><tbody>';
			echo '<tr><th>Last backup</th><td>' . $run_line($shelf_runs[0] ?? null) . '</td></tr>';
			echo '<tr><th>Last full backup</th><td>' . $run_line($newest_full) . '</td></tr>';
			echo '<tr><th>Oldest backup held</th><td>' . $run_line($shelf_runs ? end($shelf_runs) : null) . '</td></tr>';
			echo '<tr><th>Last verified restorable</th><td>' . $verify_line . '</td></tr>';
			// Offloaded files: the newest run that carries an offloaded-files
			// index is the one they come home from. The node checks its file
			// store daily and shows what is missing on its own Backups page;
			// bringing them back is this plane's job to run, so the button is here.
			$objects_run = null;
			foreach ($shelf_runs as $entry) {
				if (isset($entry['run']['artifacts']['objects'])) { $objects_run = $entry; break; }
			}
			$object_store = $chain_list['objects'] ?? [];
			if ($objects_run !== null || $object_store) {
				// "N objects, X GB" per party that keeps them, then the run that last indexed them.
				$store_words = [];
				foreach ($object_store as $profile => $o) {
					$store_words[] = number_format((int)$o['count']) . ' object' . ((int)$o['count'] === 1 ? '' : 's') . ', '
					    . BackupChainListHelper::format_size($o['bytes'])
					    . ' <span class="text-muted small">by ' . htmlspecialchars(strtolower($profile_labels[$profile] ?? $profile)) . '</span>';
				}
				echo '<tr><th>Offloaded files in backup storage</th><td>' . ($store_words ? implode('; ', $store_words) : 'none listed');
				echo $objects_run !== null
				   ? '; last indexed at the run of ' . htmlspecialchars($run_when($objects_run['run']))
				   : '; <span class="text-muted">not indexed by any run in backup storage</span>';
				if ($objects_run !== null) {
					echo ' <button type="button" class="btn btn-outline-primary btn-sm ms-2"' . $sm_do('bringBackObjects', array($objects_run['chain']['chain_id'],
						$objects_run['chain']['profile'], (int)$objects_run['run']['seq'], $run_when($objects_run['run'])), true) . '>Bring them back</button>';
				}
				echo ''
				   . '<div class="text-muted small mt-1">Files the site moved to its cloud file store are in no archive; each is in this backup storage once. '
				   . 'Bring them back asks the node which of them its file store can no longer serve and sends those home from backup storage, '
				   . 'a page of signed links per job. Nothing on the node is overwritten; nothing in any bucket is deleted.</div></td></tr>';
			}
			echo '</tbody></table>';

			// Each run's newest verify, from the verify jobs this plane sent:
			// their results name the run as "<chain>/<seq>".
			$verified_runs = array();
			foreach (new MultiManagementJob(['node_id' => $node->key, 'job_type' => 'verify_backup', 'deleted' => false],
					['mjb_management_job_id' => 'DESC'], 50) as $vjob) {
				$vres = json_decode((string)$vjob->get('mjb_result'), true);
				$vrun = is_array($vres) ? (string)($vres['run'] ?? '') : '';
				if ($vrun === '' || isset($verified_runs[$vrun]) || !in_array($vres['verify_status'] ?? '', array('pass', 'fail'), true)) {
					continue;
				}
				$verified_runs[$vrun] = array('pass' => $vres['verify_status'] === 'pass',
					'when' => substr((string)$vjob->get('mjb_completed_time'), 0, 16),
					'level' => BackupVerifier::level_name((int)($vres['level'] ?? 0)));
			}

			// Which runs a Prepare has put on the node: a restore is offered
			// for those, and the node would refuse any other.
			$staged_runs = BackupChainListHelper::staged_runs($node);
			echo '<table class="table table-striped table-sm">';
			echo '<thead><tr><th>When</th><th>Backup</th><th>Taken by</th><th>Size</th><th>Verified</th><th>Actions</th></tr></thead><tbody>';
			foreach ($shelf_runs as $entry) {
				$c = $entry['chain']; $r = $entry['run'];
				echo '<tr>';
				echo '<td>' . htmlspecialchars($run_when($r)) . '</td>';
				// The run's manifest and the hash recorded when it was written:
				// what a shell restore of this run checks its manifest against.
				echo '<td>' . ($r['level'] === 0 ? 'Full' : 'Incremental')
				   . (($c['space_state'] ?? '') === StorageSpace::STATE_DRAINING
						? ' <span class="text-muted small">on ' . htmlspecialchars($c['target_name']) . ', moved away from</span>' : '')
				   . (($r['manifest_sha256'] ?? '') !== ''
						? '<div class="text-muted small text-break">' . htmlspecialchars($r['manifest']) . ' sha256 <code class="user-select-all">'
							. htmlspecialchars($r['manifest_sha256']) . '</code></div>' : '')
				   . (!empty($c['manifest_mismatch'])
						? '<div class="text-danger small">The newest manifest of this backup set in backup storage is not the one its run wrote.</div>' : '')
				   . '</td>';
				echo '<td><small>' . htmlspecialchars($profile_labels[$c['profile']] ?? $c['profile']) . '</small></td>';
				echo '<td>' . htmlspecialchars(BackupChainListHelper::format_size($r['bytes'])) . '</td>';
				$v = $verified_runs[$c['chain_id'] . '/' . (int)$r['seq']] ?? null;
				echo '<td>' . ($v === null ? '<span class="text-muted small">not yet</span>'
					: '<span class="badge bg-' . ($v['pass'] ? 'success' : 'danger') . '">' . ($v['pass'] ? 'verified' : 'failed') . '</span>'
					  . ' <span class="text-muted small">' . htmlspecialchars($v['when']) . ' UTC'
					  . ($v['level'] !== '' ? ' &middot; ' . htmlspecialchars($v['level']) : '') . '</span>') . '</td>';
				echo '<td>';
				$ca = array($c['chain_id'], $c['profile'], array_map(function ($x) {
						return ['seq' => $x['seq'], 'level' => $x['level'], 'time' => substr((string)$x['time'], 0, 16)];
					}, $c['runs']), (int)$r['seq']);
				// Prepare, then restore. Two buttons rather than one, because
				// they are genuinely two decisions: preparing downloads this
				// backup and the runs it depends on and recovers their data key
				// from this machine's own key, and destroys nothing; the restore
				// replays them over live data and has to be approved on the node.
				$sa = array($c['chain_id'], $c['profile'], (int)$r['seq']);
				$is_staged = in_array((int)$r['seq'], $staged_runs[$c['chain_id']] ?? array(), true);
				$restore_id = 'restore_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$c['chain_id']) . '_' . (int)$r['seq'];
				// Verify: prove this run restorable on the node without restoring
				// it. The room a rehearsal needs is worked out here from the runs'
				// recorded sizes, the same arithmetic the node applies before it
				// downloads anything.
				$pseudo = ['chain_id' => $c['chain_id'], 'version' => (int)($c['version'] ?? 1), 'runs' => []];
				foreach ($c['runs'] as $x) {
					$arts = [];
					foreach (($x['artifacts'] ?? []) as $kind => $b) {
						$arts[$kind] = ['name' => $kind, 'bytes' => (int)$b];
						if (isset($x['levels'][$kind])) { $arts[$kind]['level'] = (int)$x['levels'][$kind]; }
					}
					$pseudo['runs'][] = ['seq' => $x['seq'], 'level' => $x['level'], 'artifacts' => $arts];
				}
				try { $needs = BackupVerifier::disk_needed($pseudo, (int)$r['seq'], BackupVerifier::LEVEL_REHEARSE); }
				catch (Throwable $e) { $needs = 0; }
				$va = array($c['chain_id'], $c['profile'], (int)$r['seq'], $run_when($r), BackupChainListHelper::format_size($needs));
				echo '<button type="button" class="btn btn-outline-primary btn-sm me-1"' . $sm_do('stageChain', $sa, true) . '>Prepare</button>';
				echo '<button type="button" class="btn btn-outline-secondary btn-sm me-1"'
				   . ($entry === $shelf_runs[0] ? ' id="verifyNewestBtn"' : '')
				   . $sm_do('openVerifyModal', $va, true) . '>Verify</button>';
				echo '<button type="button" class="btn btn-outline-warning btn-sm" id="' . $restore_id . '"'
				   . ($is_staged ? '' : ' disabled title="Prepare this backup first: it downloads what restoring this run needs onto the node."')
				   . $sm_do('openChainRestoreModal', $ca) . '>Restore</button>';
				echo '</td></tr>';
			}
			echo '</tbody></table>';
			echo '<p class="text-muted small mb-2"><strong>Verify</strong> proves a backup restorable without restoring '
			   . 'it: the node downloads what a restore of that run would need, opens every archive with its own key '
			   . 'and reads it to the end &mdash; or, for a rehearsal, replays it into a scratch directory and a '
			   . 'throwaway database and counts what came back. Nothing on the site is touched either way. The '
			   . 'result is shown above and on the node\'s card.</p>';
			echo '<p class="text-muted small mb-0">Restoring a backup rebuilds the site as it was at that run: the '
			   . 'last full backup before it, then every incremental up to it, in order. Each file is checked '
			   . 'against its recorded size and hash before anything is written. <strong>Prepare first</strong> '
			   . '&mdash; that downloads what restoring that run needs onto the node and recovers its data key there, '
			   . 'and Restore is offered for it once it is done; '
			   . 'the restore itself then has to be approved on the node\'s own Backups page with its backup '
			   . 'recovery key.</p>';
		}

		$page->end_box();
	}

	echo $other_files_box;

	// The domain every restore form pre-fills with. It is the node's recorded
	// URL, offered rather than assumed: a rebuild keeps the site's own domain
	// while a rehearsal must not claim it, and the backup looks identical
	// either way — so the value is confirmed at the moment somebody knows it.
	$prefill_domain = parse_url((string)$node->get('mgn_site_url'), PHP_URL_HOST) ?: '';

	// ── Verify dialog (used by per-row Verify buttons above) ──
	//
	// Two choices, both non-destructive and both jobs the node runs on itself.
	// Buttons rather than a form: nothing is posted to this page, the API
	// creates the job and the poller reports what the node said.
?>
	<dialog id="verifyModal">
		<div class="svm-modal-head">
			<h5 class="svm-m0">Verify the backup of <span id="vm_title"></span></h5>
			<button type="button" aria-label="Close" data-sm-do="closeVerifyModal" class="svm-modal-close">&times;</button>
		</div>
		<p class="text-muted small">Nothing on the site is touched. The node downloads what a restore of this
			backup would need, then removes it when it is done.</p>
		<div class="mb-2">
			<button type="button" class="btn btn-outline-primary btn-sm" data-sm-do="submitVerifyModal" data-sm-args="[2]">Open and read</button>
			<div class="text-muted small mt-1">Every archive is decrypted with the node's own key and read to the end.
				A minute or three; downloads the whole set once.</div>
		</div>
		<div class="mb-2">
			<button type="button" class="btn btn-outline-primary btn-sm" data-sm-do="submitVerifyModal" data-sm-args="[3]">Rehearse a restore
				(needs about <span id="vm_needs"></span> free on the node)</button>
			<div class="text-muted small mt-1">Opens and reads, then replays the files into a scratch directory and loads
				the database into a throwaway one on the node, counts what came back, and deletes both.</div>
		</div>
		<div class="dialog-actions">
			<button type="button" class="dialog-btn-cancel" data-sm-do="closeVerifyModal">Cancel</button>
		</div>
	</dialog>

	<?php
	// ── Shared Restore modal (used by per-row Restore buttons above) ──
?>
	<dialog id="restoreModal">
		<?php
		$fw_restore = $page->getFormWriter('restoreForm');
		$fw_restore->begin_form();
		$fw_restore->hiddeninput('action', '', ['id' => 'rm_action', 'value' => '']);
		$fw_restore->hiddeninput(SmAdminCsrf::FIELD, '', ['value' => SmAdminCsrf::token()]);
		$fw_restore->hiddeninput('backup_filename', '', ['id' => 'rm_filename', 'value' => '']);
		$fw_restore->hiddeninput('backup_local_path', '', ['id' => 'rm_local_path', 'value' => '']);
		$fw_restore->hiddeninput('backup_cloud_path', '', ['id' => 'rm_cloud_path', 'value' => '']);
		?>
		<div class="svm-modal-head">
			<h5 class="svm-m0">Restore from <code id="rm_title"></code></h5>
			<button type="button" aria-label="Close" data-sm-do="closeRestoreModal" class="svm-modal-close">&times;</button>
		</div>
		<p class="text-muted small">
			<strong>Nothing is saved first.</strong> The current site files and database are
			replaced, and what is there now is not kept anywhere.
		</p>
		<?php
		// Asked, never assumed. This is the one thing a restore cannot work out
		// for itself, and the answer decides whether the rebuilt site claims the
		// live domain or stays out of its way.
		echo '<div id="rm_domain_wrap">';
		$fw_restore->textinput('restore_domain', 'Domain the restored site will answer to', [
			'value'    => $prefill_domain,
			'id'       => 'rm_domain',
			// Deliberately not `required`: this field is hidden for a
			// database-only restore, and a hidden required control blocks
			// submission from anything that runs constraint validation.
			// The check is in submitRestoreModal(), and the job builder
			// refuses an empty domain server-side regardless.
			'helptext' => 'The site is set to this name, its serving config is regenerated for it, and its '
			            . 'certificate is issued once DNS points here. Change it for a rehearsal that must not '
			            . 'claim the live domain.',
		]);
		echo '</div>';
		?>
		<label class="form-label">What to restore</label>
		<?php
		echo '<div id="rm_files_wrap">';
		$fw_restore->checkboxinput('restore_files', 'Project files (<code>' . htmlspecialchars($node->get('mgn_web_root')) . '</code>)', ['checked' => true, 'id' => 'rm_files']);
		echo '</div>';
		$fw_restore->checkboxinput('restore_database', 'Database', ['checked' => true, 'id' => 'rm_database']);
		?>
		<p class="text-muted small mt-2">
			The serving config is always regenerated for this machine from the platform's own templates —
			the virtualhost inside the backup is never installed. If it differs, it is kept beside the live
			one as <code>.conf.from-backup</code> and named in the job output.
		</p>
		<div id="rm_component_error" class="text-danger small mt-2" hidden>Select at least one component.</div>
		<div class="dialog-actions">
			<button type="button" class="dialog-btn-cancel" data-sm-do="closeRestoreModal">Cancel</button>
			<button type="button" class="dialog-btn-confirm dialog-btn-danger" data-sm-do="submitRestoreModal">Restore</button>
		</div>
		<?php $fw_restore->end_form(); ?>
	</dialog>

	<dialog id="chainRestoreModal">
		<?php
		$fw_chain = $page->getFormWriter('chainRestoreForm');
		$fw_chain->begin_form();
		$fw_chain->hiddeninput('action', '', ['value' => 'restore_chain']);
		$fw_chain->hiddeninput(SmAdminCsrf::FIELD, '', ['value' => SmAdminCsrf::token()]);
		$fw_chain->hiddeninput('chain_id', '', ['id' => 'cm_chain_id', 'value' => '']);
		$fw_chain->hiddeninput('chain_profile', '', ['id' => 'cm_profile', 'value' => '']);
		?>
		<div class="svm-modal-head">
			<h5 class="svm-m0">Restore from <code id="cm_title"></code></h5>
			<button type="button" aria-label="Close" data-sm-do="closeChainRestoreModal" class="svm-modal-close">&times;</button>
		</div>
		<p class="text-muted small">
			Every artifact is checked against its recorded size and hash before anything is written.
			<strong>Nothing is saved first</strong> — what is on this node now is not kept.
		</p>
		<?php
		// The run picker is filled in by openChainRestoreModal from the manifest —
		// restoring "as at" a run is the whole point of keeping a chain, so it is a
		// choice on the form rather than an assumption of "the newest".
		$fw_chain->dropinput('chain_seq', 'Backup to restore', ['options' => [], 'id' => 'cm_seq']);
		$fw_chain->textinput('restore_domain', 'Domain the restored site will answer to', [
			'value'    => $prefill_domain,
			'id'       => 'cm_domain',
			'required' => true,
		]);
		$fw_chain->checkboxinput('restore_database', 'Database', ['checked' => true, 'id' => 'cm_database']);
		?>
		<div class="dialog-actions">
			<button type="button" class="dialog-btn-cancel" data-sm-do="closeChainRestoreModal">Cancel</button>
			<button type="button" class="dialog-btn-confirm dialog-btn-danger" data-sm-do="submitChainRestoreModal">Restore</button>
		</div>
		<?php $fw_chain->end_form(); ?>
	</dialog>

<?php
// Suppress auto-refresh if the most recent list_backups attempt (any status) failed.
// Without this guard, every page load triggers a new scan that also fails, creating
// an infinite loop of failed jobs whenever the remote endpoint is unavailable.
$bk_last = ManagementJob::latestForNode($node->key, 'list_backups');
$backup_last_attempt_failed = ($bk_last && $bk_last->get('mjb_status') === 'failed');
$space_of_path = [];
foreach (($files ?? []) as $f) {
	if (!empty($f['cloud_path']) && !empty($f['space_id'])) { $space_of_path[(string)$f['cloud_path']] = (int)$f['space_id']; }
}
$space_of_chain = [];
foreach ((($chain_list ?? [])['chains'] ?? []) as $c) {
	$space_of_chain[(string)$c['chain_id']] = (int)($c['space_id'] ?? 0);
}
echo PageScript::json('node-backups-config', array(
	'node_id'             => (int)$node->key,
	'last_scan_age'       => $last_scan ? (time() - strtotime($last_scan)) : 99999,
	'last_attempt_failed' => (bool)$backup_last_attempt_failed,
	'space_of_path'       => (object)$space_of_path,
	'space_of_chain'      => (object)$space_of_chain,
	'staged_runs'         => (object)($staged_runs ?? array()),
)), PageScript::src('plugins/server_manager/assets/js/node-backups.js');
?>

<?php

