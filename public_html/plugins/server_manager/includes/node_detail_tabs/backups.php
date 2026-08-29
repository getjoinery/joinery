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
 * @version 1.6 - the backup-target line and recoverable box resolve the shelf via get_target(), the
 *                same fallback the job builder uses, so a node that names no target but backs up to the
 *                sole enabled shelf reads as cloud-backed instead of "Local only"
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

	// Where this node's backups actually go — resolved the SAME way the job
	// builder resolves it, so the tab never says "Local only" about a node that
	// is in fact uploading to the management node's sole enabled shelf. Reading the
	// raw mgn_bkt_backup_target_id here was how a working, cloud-backed node
	// showed as local-only whenever it named no target of its own.
	require_once(PathHelper::getIncludePath('data/backup_target_class.php'));
	$cloud_target  = JobCommandBuilder::get_target($node);
	$names_own     = (bool) $node->get('mgn_bkt_backup_target_id');
	$target_name   = 'Local only';
	$target_provider = 'local';
	if ($cloud_target) {
		$provider_labels = ['local' => 'Local', 'b2' => 'Backblaze B2', 's3' => 'Amazon S3', 'linode' => 'Linode Object Storage'];
		$target_provider = $cloud_target->get('bkt_provider');
		$target_name = htmlspecialchars($cloud_target->get('bkt_name')) . ' (' . ($provider_labels[$target_provider] ?? $target_provider) . ')';
		// Make the fallback legible rather than silent: this node named no shelf,
		// so it is using the only one this management node has.
		if (!$names_own) {
			$target_name .= ' &mdash; <span class="text-muted">the management node\'s only shelf (this node names none)</span>';
		}
	} elseif ($names_own) {
		// It named a shelf, but that shelf is gone or switched off — not the same
		// thing as choosing local-only, and worth saying so.
		$target_name = 'Local only (the shelf this node named is missing or switched off)';
	}

	echo '<div class="alert alert-light border mb-3">';
	echo '<strong>Backup target:</strong> ' . $target_name;
	echo ' <a href="' . $base_url . '&tab=overview&edit=1#connectionSettings" class="ms-2 small">Change</a>';
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
		echo '<a href="' . $base_url . '&tab=overview&edit=1#connectionSettings" class="alert-link">Choose one</a> '
		   . 'to start backing it up.';
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
		echo '<p class="mb-1">';
		if (!empty($policy['enabled'])) {
			echo '<strong>Scheduled:</strong> ' . htmlspecialchars($policy['frequency'])
			   . ' at ' . htmlspecialchars(FleetBackupPolicy::slot_time($policy, (string)$node->get('mgn_slug')))
			   . ', keeping ' . (int)$policy['keep'] . ' restore point' . ($policy['keep'] === 1 ? '' : 's')
			   . ($policy['mode'] === 'chain' ? ', as incremental chains' : ', a full backup every time');
		} else {
			echo '<strong>Not scheduled.</strong> This management node takes no backups of this node '
			   . 'except when someone runs one.';
		}
		echo '</p>';

		$health = NodeMonitorHealth::fleet_backup_health($node, $policy);
		echo '<p class="' . ($health['is_problem'] ? 'text-danger' : 'text-muted') . '">'
		   . '<strong>' . htmlspecialchars($health['label']) . ':</strong> '
		   . htmlspecialchars($health['detail']) . '</p>';

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
			. (!empty($fleet['enabled'])
				? $fleet['frequency'] . ' backups, keeping ' . (int)$fleet['keep']
				: 'no scheduled backups');

		echo '<hr>';
		$fw_pol = $page->getFormWriter('backup_policy_form');
		$fw_pol->begin_form();
		$fw_pol->hiddeninput('action', '', ['value' => 'save_backup_policy']);
		$fw_pol->hiddeninput(SmAdminCsrf::FIELD, '', ['value' => SmAdminCsrf::token()]);

		$custom_fields = ['policy_schedule', 'policy_window_start', 'policy_window_minutes',
			'policy_mode', 'policy_keep', 'policy_full_interval_days'];
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
			'options' => ['chain' => 'Incremental chains', 'full' => 'A full backup every time'],
			'value'   => $policy['mode'],
		]);
		$fw_pol->numberinput('policy_keep', 'Restore points kept', [
			'value'    => $policy['keep'],
			'min'      => 1,
			'helptext' => 'Chains are kept or deleted whole, never partly.',
		]);
		$fw_pol->numberinput('policy_full_interval_days', 'Days before a chain starts a fresh full', [
			'value' => $policy['full_interval_days'],
			'min'   => 0,
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

	$pageoptions = ['title' => 'Backup Files'];
	$page->begin_box($pageoptions);

	echo '<div class="d-flex justify-content-between align-items-center mb-2">';
	if ($last_scan) {
		echo '<small class="text-muted">Last scanned: ' . LibraryFunctions::convert_time($last_scan, 'UTC', $session->get_timezone(), 'M j, g:i A') . '</small>';
	} else {
		echo '<small class="text-muted">No scan performed yet</small>';
	}
	echo '<button type="button" class="btn btn-sm btn-outline-primary" id="refreshBackupsBtn" onclick="refreshBackupList()">Scan for Backups</button>';
	echo '</div>';

	echo '<div id="backupScanStatus" hidden class="mb-2"></div>';

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
				$ra = htmlspecialchars(json_encode($restore_type)) . ', '
				    . htmlspecialchars(json_encode($fn)) . ', '
				    . htmlspecialchars(json_encode($local_path)) . ', '
				    . htmlspecialchars(json_encode($cloud_path));
				echo '<button type="button" class="btn btn-outline-warning btn-sm me-1" onclick="openRestoreModal(' . $ra . ')">Restore</button>';
			}

			// A backup that exists only on the node is one disk failure from gone —
			// which is the state an upload interrupted by a transient provider error
			// leaves behind. Offer the push whenever there is somewhere to push to.
			if ($has_local && !$has_cloud && $cloud_target) {
				$ua = htmlspecialchars(json_encode($fn)) . ', '
				    . htmlspecialchars(json_encode($local_path)) . ', this';
				echo '<button type="button" class="btn btn-outline-primary btn-sm me-1" onclick="uploadBackup(' . $ua . ')">Upload to cloud</button>';
			}

			if ($target) {
				$args = htmlspecialchars(json_encode($target)) . ', '
				      . htmlspecialchars(json_encode($fn)) . ', '
				      . htmlspecialchars(json_encode($local_path)) . ', '
				      . htmlspecialchars(json_encode($cloud_path));
				echo '<button type="button" class="btn btn-outline-danger btn-sm" onclick="deleteBackup(' . $args . ')">Delete</button>';
			}

			echo '</td>';
			echo '</tr>';
		}
	} else {
		echo '<tr><td colspan="5" class="text-muted text-center">' . ($last_scan ? 'No backup files found' : 'Click "Scan for Backups" to see files') . '</td></tr>';
	}

	echo '</tbody></table>';

	$page->end_box();

	// ── Restore points held as incremental chains ──
	//
	// This is what the schedule actually produces. Each chain is ONE restore
	// point per run: the full, then every incremental up to the run chosen,
	// applied in order. It cannot be represented in the flat list above, which
	// is why the list above leaves chain artifacts out.
	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/BackupChainListHelper.php'));
	$chain_list = BackupChainListHelper::for_node($node);

	if ($chain_list['error'] || !empty($chain_list['chains'])) {
		$pageoptions = ['title' => 'Restore points (incremental chains)'];
		$page->begin_box($pageoptions);

		if ($chain_list['error']) {
			echo '<div class="alert alert-warning mb-0">Could not read the chain listing: '
			   . htmlspecialchars($chain_list['error']) . '</div>';
		} else {
			echo '<table class="table table-striped table-sm">';
			echo '<thead><tr><th>Chain</th><th>Taken by</th><th>Restore points</th><th>Newest</th><th>Size</th><th>Actions</th></tr></thead><tbody>';
			$profile_labels = ['manager' => 'This management node', 'site' => 'The site itself'];
			foreach ($chain_list['chains'] as $c) {
				$runs = $c['runs'];
				$last = end($runs);
				echo '<tr>';
				echo '<td><small><code>' . htmlspecialchars($c['chain_id']) . '</code></small></td>';
				echo '<td><small>' . htmlspecialchars($profile_labels[$c['profile']] ?? $c['profile']) . '</small></td>';
				echo '<td>' . count($runs) . '</td>';
				echo '<td><small>' . htmlspecialchars($last ? substr((string)$last['time'], 0, 16) . ' UTC' : '-') . '</small></td>';
				echo '<td>' . htmlspecialchars(BackupChainListHelper::format_size($c['bytes'])) . '</td>';
				echo '<td>';
				$ca = htmlspecialchars(json_encode($c['chain_id'])) . ', '
				    . htmlspecialchars(json_encode($c['profile'])) . ', '
				    . htmlspecialchars(json_encode(array_map(function ($r) {
						return ['seq' => $r['seq'], 'level' => $r['level'], 'time' => substr((string)$r['time'], 0, 16)];
					}, $runs)));
				echo '<button type="button" class="btn btn-outline-warning btn-sm" onclick="openChainRestoreModal(' . $ca . ')">Restore</button>';
				echo '</td></tr>';
			}
			echo '</tbody></table>';
			echo '<p class="text-muted small mb-0">A chain restores as at one of its runs: the full, then every '
			   . 'incremental up to that run, in order. Each artifact is checked against its recorded size and '
			   . 'hash before anything is written.</p>';
		}

		$page->end_box();
	}

	// The domain every restore form pre-fills with. It is the node's recorded
	// URL, offered rather than assumed: a rebuild keeps the site's own domain
	// while a rehearsal must not claim it, and the backup looks identical
	// either way — so the value is confirmed at the moment somebody knows it.
	$prefill_domain = parse_url((string)$node->get('mgn_site_url'), PHP_URL_HOST) ?: '';

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
			<button type="button" aria-label="Close" onclick="closeRestoreModal();" class="svm-modal-close">&times;</button>
		</div>
		<p class="text-muted small">
			A pre-restore snapshot of the current database and project files is written to
			<code>/backups/auto_pre_project_restore_*</code> before the restore runs.
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
			<button type="button" class="dialog-btn-cancel" onclick="closeRestoreModal();">Cancel</button>
			<button type="button" class="dialog-btn-confirm dialog-btn-danger" onclick="submitRestoreModal();">Restore</button>
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
			<button type="button" aria-label="Close" onclick="closeChainRestoreModal();" class="svm-modal-close">&times;</button>
		</div>
		<p class="text-muted small">
			Every artifact is checked against its recorded size and hash before anything is written, and a
			pre-restore snapshot of the database is taken first.
		</p>
		<?php
		// The run picker is filled in by openChainRestoreModal from the manifest —
		// restoring "as at" a run is the whole point of keeping a chain, so it is a
		// choice on the form rather than an assumption of "the newest".
		$fw_chain->dropinput('chain_seq', 'Restore as at', ['options' => [], 'id' => 'cm_seq']);
		$fw_chain->textinput('restore_domain', 'Domain the restored site will answer to', [
			'value'    => $prefill_domain,
			'id'       => 'cm_domain',
			'required' => true,
		]);
		$fw_chain->checkboxinput('restore_database', 'Database', ['checked' => true, 'id' => 'cm_database']);
		?>
		<div class="dialog-actions">
			<button type="button" class="dialog-btn-cancel" onclick="closeChainRestoreModal();">Cancel</button>
			<button type="button" class="dialog-btn-confirm dialog-btn-danger" onclick="submitChainRestoreModal();">Restore</button>
		</div>
		<?php $fw_chain->end_form(); ?>
	</dialog>

<script>
var backupNodeId = <?php echo $node->key; ?>;
var backupLastScanAge = <?php echo $last_scan ? (time() - strtotime($last_scan)) : 99999; ?>;
var BACKUP_STALE_SECONDS = 60;
// The node name as a proper JS string literal (json_encode, HTML/JS-safe). Used
// in confirm() dialogs so an apostrophe or markup in a discovery-supplied name
// cannot break out of an onclick attribute (S-3).
// smApiPost / smEsc come from the shared server_manager.js asset; smNodeName is
// defined once by the shell.
<?php
// Suppress auto-refresh if the most recent list_backups attempt (any status) failed.
// Without this guard, every page load triggers a new scan that also fails, creating
// an infinite loop of failed jobs whenever the remote endpoint is unavailable.
$bk_last = ManagementJob::latestForNode($node->key, 'list_backups');
$backup_last_attempt_failed = ($bk_last && $bk_last->get('mjb_status') === 'failed');
?>
var backupLastAttemptFailed = <?php echo $backup_last_attempt_failed ? 'true' : 'false'; ?>;
// Cap on how many times polling retries after a transport error before it gives
// up and shows a reload notice — without this the poll retried forever, and a
// {}-swallowed error looked like "complete" and reloaded, minting a new scan job.
var BACKUP_POLL_MAX_RETRIES = 5;

function refreshBackupList() {
	var btn = document.getElementById('refreshBackupsBtn');
	var status = document.getElementById('backupScanStatus');
	btn.disabled = true;
	btn.textContent = 'Scanning...';
	status.style.display = 'block';
	status.innerHTML = '<span class="text-muted"><span class="spinner-border spinner-border-sm me-1"></span> Scanning backup files...</span>';

	smApiPost('backup_actions', { action: 'refresh_list', node_id: backupNodeId })
		.then(function(data) {
			if (!data.success) {
				btn.disabled = false;
				btn.textContent = 'Scan for Backups';
				status.innerHTML = '<span class="text-danger">' + smEsc(data.message) + '</span>';
				return;
			}
			pollBackupList(data.job_id);
		})
		.catch(function(err) {
			btn.disabled = false;
			btn.textContent = 'Scan for Backups';
			status.innerHTML = '<span class="text-danger">Request failed</span>';
		});
}

// Auto-refresh local listing on page load if stale — but not if the last attempt
// failed, to avoid an infinite loop of failing jobs on every page load.
if (backupLastScanAge > BACKUP_STALE_SECONDS && !backupLastAttemptFailed) {
	window.addEventListener('DOMContentLoaded', refreshBackupList);
}

function pollBackupList(jobId, retries) {
	retries = retries || 0;
	var btn = document.getElementById('refreshBackupsBtn');
	var status = document.getElementById('backupScanStatus');

	smApiPost('backup_actions', { action: 'list_status', node_id: backupNodeId, job_id: jobId })
		.then(function(data) {
			if (data.status === 'pending' || data.status === 'running') {
				setTimeout(function() { pollBackupList(jobId, 0); }, 2000);
				return;
			}
			btn.disabled = false;
			btn.textContent = 'Scan for Backups';
			status.innerHTML = '<span class="text-success">Scan complete</span>';
			setTimeout(function() { status.style.display = 'none'; }, 2000);
			// Reload page to show updated file list
			window.location.reload();
		})
		.catch(function() {
			// Transport/HTTP error (smApiPost rejects — it never fakes success).
			// Retry a bounded number of times, then stop and tell the user to
			// reload rather than silently loop or fake a completed scan.
			if (retries >= BACKUP_POLL_MAX_RETRIES) {
				btn.disabled = false;
				btn.textContent = 'Scan for Backups';
				status.innerHTML = '<span class="text-danger">Polling stopped — the scan status could not be reached. '
					+ '<a href="" onclick="location.reload();return false;">Reload</a> to try again.</span>';
				return;
			}
			setTimeout(function() { pollBackupList(jobId, retries + 1); }, 3000);
		});
}

function openRestoreModal(type, filename, localPath, cloudPath) {
	document.getElementById('rm_filename').value = filename;
	document.getElementById('rm_local_path').value = localPath || '';
	document.getElementById('rm_cloud_path').value = cloudPath || '';
	document.getElementById('rm_title').textContent = filename;
	document.getElementById('rm_component_error').style.display = 'none';

	var isProject = (type === 'project');
	document.getElementById('rm_action').value = isProject ? 'restore_project' : 'restore_database';

	// Show/hide components based on backup type
	document.getElementById('rm_files_wrap').style.display  = isProject ? '' : 'none';
	// The domain is a property of the SITE, so it is only settled by a project
	// restore. A database-only restore leaves the machine's identity alone.
	document.getElementById('rm_domain_wrap').style.display = isProject ? '' : 'none';
	// Database is always available
	document.getElementById('rm_files').checked    = isProject;
	document.getElementById('rm_database').checked = true;

	document.getElementById('restoreModal').showModal();
}

function closeRestoreModal() {
	document.getElementById('restoreModal').close();
}

// Chain restore. The runs come from the chain's own manifest, so the picker can
// only ever offer restore points that exist.
function openChainRestoreModal(chainId, profile, runs) {
	document.getElementById('cm_chain_id').value = chainId;
	document.getElementById('cm_profile').value = profile;
	document.getElementById('cm_title').textContent = chainId;

	var sel = document.getElementById('cm_seq');
	sel.innerHTML = '';
	for (var i = runs.length - 1; i >= 0; i--) {
		var r = runs[i];
		var opt = document.createElement('option');
		opt.value = r.seq;
		opt.textContent = 'Run ' + r.seq + ' — ' + (r.time || 'unknown time') + ' UTC'
			+ (r.level === 0 ? ' (full)' : '')
			+ (i === runs.length - 1 ? ' — newest' : '');
		sel.appendChild(opt);
	}

	document.getElementById('chainRestoreModal').showModal();
}

function closeChainRestoreModal() {
	document.getElementById('chainRestoreModal').close();
}

function submitChainRestoreModal() {
	var chainId = document.getElementById('cm_chain_id').value;
	var seq     = document.getElementById('cm_seq').value;
	var domain  = (document.getElementById('cm_domain').value || '').trim();
	if (!domain) {
		alert('Enter the domain the restored site is to answer to.');
		return;
	}
	JoineryModal.confirm('Restore ' + chainId + ' as at run ' + seq + ', serving ' + domain
		+ '? Files deleted since the full backup are deleted here too. A pre-restore snapshot is written first.',
		function() {
			document.getElementById('chainRestoreForm').submit();
		});
}

function submitRestoreModal() {
	var action = document.getElementById('rm_action').value;
	var fn = document.getElementById('rm_filename').value || 'the selected backup';

	if (action === 'restore_project') {
		var boxes = document.querySelectorAll('#restoreForm input[type=checkbox]:checked');
		var err = document.getElementById('rm_component_error');
		if (boxes.length === 0) {
			err.style.display = 'block';
			return;
		}
		err.style.display = 'none';
		var domain = (document.getElementById('rm_domain').value || '').trim();
		if (!domain) {
			alert('Enter the domain the restored site is to answer to.');
			return;
		}
		var parts = [];
		if (document.getElementById('rm_files').checked)    parts.push('project files');
		if (document.getElementById('rm_database').checked) parts.push('database');
		JoineryModal.confirm('Restore ' + parts.join(', ') + ' from ' + fn + ', serving ' + domain
			+ '? This will overwrite the current site. A pre-restore snapshot is written to /backups/ first.', function() {
			document.getElementById('restoreForm').submit();
		});
		return;
	}

	// restore_database
	JoineryModal.confirm('Restore database from ' + fn + '? This will overwrite the current database. A pre-restore snapshot is written first.', function() {
		document.getElementById('restoreForm').submit();
	});
}

// Push a local-only backup to the node's cloud target. Non-destructive, so no
// confirm — but it can be a multi-hundred-MB transfer, so it reports progress and
// waits for the job's real verdict rather than assuming it worked.
function uploadBackup(filename, localPath, btn) {
	var status = document.getElementById('backupScanStatus');
	if (btn) { btn.disabled = true; btn.textContent = 'Uploading...'; }
	status.style.display = 'block';
	status.innerHTML = '<span class="text-muted"><span class="spinner-border spinner-border-sm me-1"></span> Uploading '
		+ smEsc(filename) + ' to cloud storage...</span>';

	smApiPost('backup_actions', { action: 'upload_file', node_id: backupNodeId, local_path: localPath })
		.then(function(data) {
			if (!data.success) {
				uploadBackupFailed(btn, smEsc(data.message));
				return;
			}
			pollUploadJob(data.job_id, btn, 0);
		})
		.catch(function() {
			uploadBackupFailed(btn, 'Upload request failed');
		});
}

function uploadBackupFailed(btn, html) {
	if (btn) { btn.disabled = false; btn.textContent = 'Upload to cloud'; }
	document.getElementById('backupScanStatus').innerHTML = '<span class="text-danger">' + html + '</span>';
}

// Deliberately not pollBackupList: that one treats any terminal job as success.
// An upload can genuinely fail, and reporting a failed transfer as done is the
// exact failure mode this button exists to fix.
function pollUploadJob(jobId, btn, retries) {
	retries = retries || 0;
	var status = document.getElementById('backupScanStatus');

	smApiPost('backup_actions', { action: 'list_status', node_id: backupNodeId, job_id: jobId })
		.then(function(data) {
			if (data.status === 'pending' || data.status === 'running') {
				setTimeout(function() { pollUploadJob(jobId, btn, 0); }, 3000);
				return;
			}
			if (data.job_status !== 'completed') {
				uploadBackupFailed(btn, 'Upload failed. <a href="/admin/server_manager/job_detail?job_id='
					+ encodeURIComponent(jobId) + '">See the job output</a> for the provider error.');
				return;
			}
			status.innerHTML = '<span class="text-success">Uploaded to cloud storage</span>';
			window.location.reload();
		})
		.catch(function() {
			if (retries >= BACKUP_POLL_MAX_RETRIES) {
				uploadBackupFailed(btn, 'Lost contact while the upload was running. '
					+ '<a href="/admin/server_manager/job_detail?job_id=' + encodeURIComponent(jobId)
					+ '">Check the job</a> before retrying.');
				return;
			}
			setTimeout(function() { pollUploadJob(jobId, btn, retries + 1); }, 3000);
		});
}

function deleteBackup(target, filename, localPath, cloudPath) {
	var locations;
	if (target === 'both')       locations = 'BOTH the local copy and the cloud copy';
	else if (target === 'local') locations = 'the local copy';
	else                         locations = 'the cloud copy';

	JoineryModal.confirm('Delete ' + filename + '? This will remove ' + locations + '. This cannot be undone.', function() {
		smApiPost('backup_actions', {
			action: 'delete_file', node_id: backupNodeId, target: target,
			local_path: localPath, cloud_path: cloudPath
		})
			.then(function(data) {
				if (!data.success) {
					alert('Delete failed: ' + data.message);
					return;
				}
				// Refresh the backup list after deletion
				refreshBackupList();
			})
			.catch(function() {
				alert('Delete request failed');
			});
	});
}
</script>

<?php

