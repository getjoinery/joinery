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

	// Load target info
	require_once(PathHelper::getIncludePath('data/backup_target_class.php'));
	$target_id = $node->get('mgn_bkt_backup_target_id');
	$target_name = 'Local only';
	$target_provider = 'local';
	if ($target_id) {
		try {
			$target = new BackupTarget($target_id, TRUE);
			$provider_labels = ['local' => 'Local', 'b2' => 'Backblaze B2', 's3' => 'Amazon S3', 'linode' => 'Linode Object Storage'];
			$target_provider = $target->get('bkt_provider');
			$target_name = htmlspecialchars($target->get('bkt_name')) . ' (' . ($provider_labels[$target_provider] ?? $target_provider) . ')';
		} catch (Exception $e) {
			$target_name = 'Local only (configured target not found)';
		}
	}

	echo '<div class="alert alert-light border mb-3">';
	echo '<strong>Backup target:</strong> ' . $target_name;
	echo ' <a href="' . $base_url . '&tab=overview&edit=1#connectionSettings" class="ms-2 small">Change</a>';
	echo '</div>';

	// Whether backups from this node can be recovered at all. Each backup seals
	// its own key to the recovery key, so there is nothing per-node to chase —
	// either recovery is set up, in which case every backup is openable, or it
	// is not, in which case encrypted backups refuse to run.
	require_once(PathHelper::getIncludePath('includes/BackupRecoveryKey.php'));
	$recovery_setup = BackupRecoveryKey::setup_state();
	$recovery_ready = $recovery_setup['is_ready'];

	// Encryption is forced for exactly the nodes whose archives leave the box —
	// asked of the same function the job builder asks, so the form can never
	// offer a choice the builder is going to overrule. (An enabled target is what
	// counts: a disabled one uploads nothing, so nothing leaves.)
	$cloud_target = JobCommandBuilder::get_target($node);
	$require_encryption = (bool) $cloud_target;

	// When backups are paused outright (below), that box says everything this
	// status alert would — so it is not repeated here.
	if ($target_id && $recovery_ready) {
		echo '<div class="alert alert-success border mb-3 py-2">';
		echo '<strong>Backups are recoverable.</strong> Every backup carries its own key sealed to recovery key '
			. htmlspecialchars($recovery_setup['fingerprint']) . '&hellip;';
		echo '</div>';
	}

	// That covers backups this control plane runs. A backup the node runs on its
	// own schedule reads the node's OWN recovery key, so it needs one of its own
	// — which the control plane gives it, filling an empty slot and never
	// overwriting one.
	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/RecoveryKeyFleet.php'));
	$rk_node = RecoveryKeyFleet::node_state($node);
	if ($rk_node['state'] !== 'n/a') {
		$rk_class = ($rk_node['state'] === 'has') ? 'alert-light' : 'alert-warning';
		echo '<div class="alert ' . $rk_class . ' border mb-3 py-2">';
		echo '<strong>Recovery key on this site:</strong> ' . htmlspecialchars($rk_node['summary']);
		if ($rk_node['fingerprint'] !== '') {
			echo ' (' . htmlspecialchars(RecoveryKeyFleet::short($rk_node['fingerprint'])) . '&hellip;)';
		}
		if (RecoveryKeyFleet::is_pushable($rk_node)) {
			$fw_rk = $page->getFormWriter('push_recovery_key_form');
			$fw_rk->begin_form();
			$fw_rk->hiddeninput('action', '', ['value' => 'push_recovery_key']);
			$fw_rk->hiddeninput(SmAdminCsrf::FIELD, '', ['value' => SmAdminCsrf::token()]);
			$fw_rk->submitbutton('btn_push_recovery_key', 'Send the recovery key',
				['class' => 'btn btn-sm btn-primary mt-2']);
			$fw_rk->end_form();
		}
		echo '</div>';
	}

	// A backup nobody can decrypt is not a backup, so an encrypting backup is
	// refused server-side until recovery is set up. Don't offer the button that
	// is going to be refused: a cloud-target node (encryption forced) gets the
	// explanation in place of the form, and a local-only node keeps its forms
	// with encryption switched off and explained.
	if ($require_encryption && !$recovery_ready) {
		$pageoptions = ['title' => 'Run Backup'];
		$page->begin_box($pageoptions);
		echo '<div class="alert alert-warning mb-0">';
		echo '<strong>Backups are paused until backup key recovery is set up.</strong> ';
		echo 'This node backs up to cloud storage, so its backups are encrypted &mdash; and an encrypted backup '
		   . 'nobody can open is not a backup. ';
		echo htmlspecialchars(BackupRecoveryKey::outstanding_summary($recovery_setup));
		echo ' <a href="' . BackupRecoveryKey::SETUP_URL . '" class="alert-link">Set up backup key recovery</a>.';
		echo '</div>';
		$page->end_box();
		$skip_backup_forms = true;
	}

	if (empty($skip_backup_forms)):
	$pageoptions = ['title' => 'Run Backup'];
	$page->begin_box($pageoptions);
	?>
	<div class="row">
		<div class="col-md-6">
			<h6>Database Backup</h6>
			<?php
			$fw_db = $page->getFormWriter('backup_db_form');
			$fw_db->begin_form();
			$fw_db->hiddeninput('action', '', ['id' => 'db_backup_action', 'value' => 'backup_database']);
			$fw_db->hiddeninput(SmAdminCsrf::FIELD, '', ['value' => SmAdminCsrf::token()]);
			if ($require_encryption) {
				$fw_db->hiddeninput('encryption', '', ['value' => '1']);
				echo '<p class="text-muted small">Encrypted because this backup is uploaded off the node</p>';
			} elseif (!$recovery_ready) {
				$fw_db->checkboxinput('encryption', 'Encrypt backup', ['checked' => false, 'disabled' => true, 'id' => 'db_encrypt']);
				echo '<p class="text-muted small">Encryption needs a recovery key so the backup can be opened again. '
				   . '<a href="' . BackupRecoveryKey::SETUP_URL . '">Set it up</a>.</p>';
			} else {
				$fw_db->checkboxinput('encryption', 'Encrypt backup', ['checked' => true, 'id' => 'db_encrypt']);
			}
			$fw_db->submitbutton('btn_db_backup', 'Run Database Backup', ['class' => 'btn btn-sm btn-primary']);
			$fw_db->end_form();
			?>
		</div>
		<div class="col-md-6">
			<h6>Full Project Backup</h6>
			<?php
			$fw_proj = $page->getFormWriter('backup_proj_form');
			$fw_proj->begin_form();
			$fw_proj->hiddeninput('action', '', ['id' => 'proj_backup_action', 'value' => 'backup_project']);
			$fw_proj->hiddeninput(SmAdminCsrf::FIELD, '', ['value' => SmAdminCsrf::token()]);
			if ($require_encryption) {
				$fw_proj->hiddeninput('encryption', '', ['value' => '1']);
				echo '<p class="text-muted small">Encrypted because this backup is uploaded off the node</p>';
			} elseif (!$recovery_ready) {
				$fw_proj->checkboxinput('encryption', 'Encrypt backup', ['checked' => false, 'disabled' => true, 'id' => 'proj_encrypt']);
				echo '<p class="text-muted small">Encryption needs a recovery key so the backup can be opened again. '
				   . '<a href="' . BackupRecoveryKey::SETUP_URL . '">Set it up</a>.</p>';
			} else {
				$fw_proj->checkboxinput('encryption', 'Encrypt backup', ['checked' => true, 'id' => 'proj_encrypt']);
			}
			$fw_proj->submitbutton('btn_proj_backup', 'Run Project Backup', ['class' => 'btn btn-sm btn-primary']);
			$fw_proj->end_form();
			?>
		</div>
	</div>
<?php
	$page->end_box();
	endif; // $skip_backup_forms

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
		<label class="form-label">What to restore</label>
		<?php
		echo '<div id="rm_files_wrap">';
		$fw_restore->checkboxinput('restore_files', 'Project files (<code>' . htmlspecialchars($node->get('mgn_web_root')) . '</code>)', ['checked' => true, 'id' => 'rm_files']);
		echo '</div>';
		$fw_restore->checkboxinput('restore_database', 'Database', ['checked' => true, 'id' => 'rm_database']);
		echo '<div id="rm_apache_wrap">';
		$fw_restore->checkboxinput('restore_apache', 'Apache config', ['checked' => true, 'id' => 'rm_apache']);
		echo '</div>';
		?>
		<div id="rm_component_error" class="text-danger small mt-2" hidden>Select at least one component.</div>
		<div class="dialog-actions">
			<button type="button" class="dialog-btn-cancel" onclick="closeRestoreModal();">Cancel</button>
			<button type="button" class="dialog-btn-confirm dialog-btn-danger" onclick="submitRestoreModal();">Restore</button>
		</div>
		<?php $fw_restore->end_form(); ?>
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
	document.getElementById('rm_files_wrap').style.display    = isProject ? '' : 'none';
	document.getElementById('rm_apache_wrap').style.display   = isProject ? '' : 'none';
	// Database is always available
	document.getElementById('rm_files').checked    = isProject;
	document.getElementById('rm_database').checked = true;
	document.getElementById('rm_apache').checked   = isProject;

	document.getElementById('restoreModal').showModal();
}

function closeRestoreModal() {
	document.getElementById('restoreModal').close();
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
		var parts = [];
		if (document.getElementById('rm_files').checked)    parts.push('project files');
		if (document.getElementById('rm_database').checked) parts.push('database');
		if (document.getElementById('rm_apache').checked)   parts.push('Apache config');
		JoineryModal.confirm('Restore ' + parts.join(', ') + ' from ' + fn + '? This will overwrite the current site. A pre-restore snapshot is written to /backups/ first.', function() {
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

