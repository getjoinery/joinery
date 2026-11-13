/*
 * A node's Backups tab (includes/node_detail_tabs/backups.php): scanning,
 * restoring, verifying, bringing back and uploading backups. The page's
 * #node-backups-config carries the node id, how old the last scan is,
 * whether it failed, the storage space of each listed backup and chain, and
 * the runs prepared on the node.
 *
 * @version 1.0
 */
var BK = (window.joineryPageData && joineryPageData('node-backups-config')) || {};
var backupNodeId = BK.node_id | 0;
var backupLastScanAge = BK.last_scan_age | 0;
var BACKUP_STALE_SECONDS = 60;
// smApiPost / smEsc come from the shared server_manager.js asset.
var backupLastAttemptFailed = !!BK.last_attempt_failed;
// The storage space each listed backup is in, so every action reaches the
// bucket that holds it (a node moved to another target keeps its older
// backups where they were).
var backupSpaceOfPath = BK.space_of_path || {};
var backupSpaceOfChain = BK.space_of_chain || {};
// The runs prepared on the node, by chain: only those are offered for restore.
var backupStagedRuns = BK.staged_runs || {};
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

	smApiPost('backup_list', { action: 'refresh_list', node_id: backupNodeId })
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

	smApiPost('backup_list', { action: 'list_status', node_id: backupNodeId, job_id: jobId })
		.then(function(data) {
			if (data.status === 'pending' || data.status === 'running') {
				setTimeout(function() { pollBackupList(jobId, 0); }, 2000);
				return;
			}
			btn.disabled = false;
			btn.textContent = 'Scan for Backups';
			// The scan often starts on its own at page load, so the operator may
			// already be in a restore or verify dialog: reloading under it would
			// throw that away. Offer the reload instead.
			if (document.querySelector('dialog[open]')) {
				status.innerHTML = '<span class="text-success">Scan complete.</span> '
					+ '<a href="">Reload</a> to see the new list.';
				return;
			}
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
					+ '<a href="">Reload</a> to try again.</span>';
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

// Restore of a run held in a chain. The runs come from the chain's own
// manifest, so the picker can only ever offer backups that exist; the row's
// own run is preselected.
function openChainRestoreModal(chainId, profile, runs, chosenSeq) {
	document.getElementById('cm_chain_id').value = chainId;
	document.getElementById('cm_profile').value = profile;

	var sel = document.getElementById('cm_seq');
	sel.innerHTML = '';
	var chosenTime = '';
	var staged = backupStagedRuns[chainId] || [];
	for (var i = runs.length - 1; i >= 0; i--) {
		var r = runs[i];
		// Only a run prepared on the node: the node refuses any other.
		if (staged.indexOf(r.seq) === -1) { continue; }
		var opt = document.createElement('option');
		opt.value = r.seq;
		opt.textContent = (r.time || 'unknown time') + ' UTC'
			+ (r.level === 0 ? ' — full' : ' — incremental')
			+ (i === runs.length - 1 ? ' — newest' : '');
		if (chosenSeq !== undefined && r.seq === chosenSeq) { opt.selected = true; chosenTime = r.time || ''; }
		sel.appendChild(opt);
	}
	document.getElementById('cm_title').textContent = chosenTime ? 'Backup of ' + chosenTime + ' UTC' : chainId;

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
	var chosen = document.getElementById('cm_seq');
	var label  = chosen.options[chosen.selectedIndex] ? chosen.options[chosen.selectedIndex].textContent : ('run ' + seq);
	JoineryModal.confirm('Restore the backup of ' + label + ', serving ' + domain
		+ '? Files deleted since the full backup are deleted here too, and nothing is saved first.',
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
			+ '? This will overwrite the current site, and nothing is saved first.', function() {
			document.getElementById('restoreForm').submit();
		});
		return;
	}

	// restore_database
	JoineryModal.confirm('Restore database from ' + fn + '? This will overwrite the current database, and nothing is saved first.', function() {
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

// Bring one cloud-only backup back onto the node, so a restore has something to
// restore from. Deliberately shares pollUploadJob's shape rather than
// pollBackupList's: a transfer can genuinely fail, and a failed transfer
// reported as done is the exact thing that would send someone into a restore
// with nothing there.
function downloadBackup(filename, cloudPath, btn) {
	var status = document.getElementById('backupScanStatus');
	if (btn) { btn.disabled = true; btn.textContent = 'Fetching...'; }
	status.style.display = 'block';
	status.innerHTML = '<span class="text-muted"><span class="spinner-border spinner-border-sm me-1"></span> Bringing '
		+ smEsc(filename) + ' back onto the node...</span>';

	smApiPost('backup_actions', { action: 'download_file', node_id: backupNodeId,
			filename: filename, cloud_path: cloudPath, space_id: backupSpaceOfPath[cloudPath] || 0 })
		.then(function(data) {
			if (!data.success) {
				downloadBackupFailed(btn, smEsc(data.message));
				return;
			}
			// Re-scan when it lands. Without it the archive is on the node and
			// the row still says cloud-only, so Restore stays hidden and the
			// operator has to guess that a manual scan is what unblocks them.
			pollTransferJob(data.job_id, btn, 'Bring back to node',
				'On the node now — rescanning so Restore becomes available...', refreshBackupList);
		})
		.catch(function() {
			downloadBackupFailed(btn, 'Request failed');
		});
}

function downloadBackupFailed(btn, html) {
	if (btn) { btn.disabled = false; btn.textContent = 'Bring back to node'; }
	document.getElementById('backupScanStatus').innerHTML = '<span class="text-danger">' + html + '</span>';
}

// Download a whole chain onto the node and recover its data key there. Nothing
// is destroyed by this, which is why it is a separate button from Restore.
function stageChain(chainId, profile, seq, btn) {
	var status = document.getElementById('backupScanStatus');
	if (btn) { btn.disabled = true; btn.textContent = 'Preparing...'; }
	status.style.display = 'block';
	status.innerHTML = '<span class="text-muted"><span class="spinner-border spinner-border-sm me-1"></span> Downloading '
		+ smEsc(chainId) + ' onto the node and recovering its key there...</span>';

	smApiPost('backup_actions', { action: 'stage_chain', node_id: backupNodeId,
			chain_id: chainId, profile: profile, seq: seq, space_id: backupSpaceOfChain[chainId] || 0 })
		.then(function(data) {
			if (!data.success) {
				stageChainFailed(btn, smEsc(data.message));
				return;
			}
			pollTransferJob(data.job_id, btn, 'Prepare',
				'Ready on the node. Restore now, and approve it on the node\'s own Backups page.', function() {
					(backupStagedRuns[chainId] = backupStagedRuns[chainId] || []).push(seq);
					var restoreBtn = document.getElementById('restore_' + chainId.replace(/[^A-Za-z0-9_-]/g, '') + '_' + seq);
					if (restoreBtn) { restoreBtn.disabled = false; restoreBtn.removeAttribute('title'); }
				});
		})
		.catch(function() {
			stageChainFailed(btn, 'Request failed');
		});
}

function stageChainFailed(btn, html) {
	if (btn) { btn.disabled = false; btn.textContent = 'Prepare'; }
	document.getElementById('backupScanStatus').innerHTML = '<span class="text-danger">' + html + '</span>';
}

// Prove a backup restorable on the node without restoring it. Two levels, both
// non-destructive: the dialog asks which, then the job runs on the node and
// the poller reports what it said.
var verifyPending = null;
function openVerifyModal(chainId, profile, seq, when, needs, btn) {
	verifyPending = { chainId: chainId, profile: profile, seq: seq, btn: btn };
	document.getElementById('vm_title').textContent = when;
	document.getElementById('vm_needs').textContent = needs;
	document.getElementById('verifyModal').showModal();
}
function closeVerifyModal() {
	document.getElementById('verifyModal').close();
}
function submitVerifyModal(level) {
	var v = verifyPending;
	closeVerifyModal();
	if (!v) { return; }
	var status = document.getElementById('backupScanStatus');
	var btn = v.btn;
	if (btn) { btn.disabled = true; btn.textContent = 'Verifying...'; }
	status.style.display = 'block';
	status.innerHTML = '<span class="text-muted"><span class="spinner-border spinner-border-sm me-1"></span> '
		+ (level === 3 ? 'Rehearsing a restore of ' : 'Opening and reading ') + 'the backup on the node...</span>';

	smApiPost('backup_actions', { action: 'verify_backup', node_id: backupNodeId,
			chain_id: v.chainId, profile: v.profile, seq: v.seq, level: level, space_id: backupSpaceOfChain[v.chainId] || 0 })
		.then(function(data) {
			if (!data.success) {
				verifyBackupFailed(btn, smEsc(data.message));
				return;
			}
			pollTransferJob(data.job_id, btn, 'Verify',
				(level === 3 ? 'Rehearsed on the node.' : 'Opened and read on the node.')
				+ ' Reload this page to see the result above and on the node\'s card.');
		})
		.catch(function() {
			verifyBackupFailed(btn, 'Request failed');
		});
}
// Bring the node's offloaded files the file store has lost home from the
// shelf. The first job is a survey: the node names what its file store cannot
// serve, and this plane signs a page of links per answer (FleetObjectRestore).
// The poller follows the survey; the pages are their own jobs in the list.
function bringBackObjects(chainId, profile, seq, when, btn) {
	JoineryModal.confirm('Bring back the offloaded files the node\'s file store can no longer serve, from the backup of '
		+ when + '? Only files the file store cannot serve are touched; nothing on the node is overwritten and nothing in any bucket is deleted.', function() {
		var status = document.getElementById('backupScanStatus');
		if (btn) { btn.disabled = true; btn.textContent = 'Asking the node...'; }
		status.style.display = 'block';
		status.innerHTML = '<span class="text-muted"><span class="spinner-border spinner-border-sm me-1"></span> '
			+ 'Asking the node which offloaded files its file store cannot serve...</span>';
		smApiPost('backup_actions', { action: 'restore_objects', node_id: backupNodeId,
				chain_id: chainId, profile: profile, seq: seq, mode: 'missing', space_id: backupSpaceOfChain[chainId] || 0 })
			.then(function(data) {
				if (!data.success) {
					if (btn) { btn.disabled = false; btn.textContent = 'Bring them back'; }
					status.innerHTML = '<span class="text-danger">' + smEsc(data.message) + '</span>';
					return;
				}
				pollTransferJob(data.job_id, btn, 'Bring them back',
					'The node has answered. Each page of files comes home as its own job; '
					+ 'the job list shows them, and the node\'s Backups page shows what is still missing.');
			})
			.catch(function() {
				if (btn) { btn.disabled = false; btn.textContent = 'Bring them back'; }
				status.innerHTML = '<span class="text-danger">Request failed</span>';
			});
	});
}
function verifyBackupFailed(btn, html) {
	if (btn) { btn.disabled = false; btn.textContent = 'Verify'; }
	document.getElementById('backupScanStatus').innerHTML = '<span class="text-danger">' + html + '</span>';
}

// One poller for both directions of transfer. Like pollUploadJob it distinguishes
// "finished" from "succeeded"; unlike it, it does not reload the page — a
// prepared chain has nothing new to show in the list, and a reload would throw
// away the message that says what to do next.
function pollTransferJob(jobId, btn, label, doneMessage, onDone) {
	var status = document.getElementById('backupScanStatus');

	smApiPost('backup_list', { action: 'list_status', node_id: backupNodeId, job_id: jobId })
		.then(function(data) {
			if (data.status === 'pending' || data.status === 'running') {
				setTimeout(function() { pollTransferJob(jobId, btn, label, doneMessage, onDone); }, 3000);
				return;
			}
			if (btn) { btn.disabled = false; btn.textContent = label; }
			if (data.job_status !== 'completed') {
				status.innerHTML = '<span class="text-danger">Did not finish. '
					+ '<a href="/admin/server_manager/job_detail?job_id=' + encodeURIComponent(jobId)
					+ '">See the job output</a> for what the node said.</span>';
				return;
			}
			status.innerHTML = '<span class="text-success">' + smEsc(doneMessage) + '</span>';
			if (typeof onDone === 'function') { onDone(); }
		})
		.catch(function() {
			if (btn) { btn.disabled = false; btn.textContent = label; }
			status.innerHTML = '<span class="text-danger">Lost track of the job. '
				+ '<a href="/admin/server_manager/job_detail?job_id=' + encodeURIComponent(jobId)
				+ '">Check it here</a>.</span>';
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

	smApiPost('backup_list', { action: 'list_status', node_id: backupNodeId, job_id: jobId })
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
			local_path: localPath, cloud_path: cloudPath, space_id: backupSpaceOfPath[cloudPath] || 0
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

// ── Buttons ──────────────────────────────────────────────────────────────
// A button names one of this tab's functions in data-sm-do, with its
// arguments as a JSON list in data-sm-args (data-sm-this adds the button).
// Only the functions listed here can be named.
var SM_DO = {
	refreshBackupList: refreshBackupList,
	openRestoreModal: openRestoreModal, closeRestoreModal: closeRestoreModal, submitRestoreModal: submitRestoreModal,
	openChainRestoreModal: openChainRestoreModal, closeChainRestoreModal: closeChainRestoreModal, submitChainRestoreModal: submitChainRestoreModal,
	openVerifyModal: openVerifyModal, closeVerifyModal: closeVerifyModal, submitVerifyModal: submitVerifyModal,
	downloadBackup: downloadBackup, uploadBackup: uploadBackup, deleteBackup: deleteBackup,
	stageChain: stageChain, bringBackObjects: bringBackObjects,
	verifyNewest: function () {
		var b = document.getElementById('verifyNewestBtn');
		if (b) { b.scrollIntoView({ block: 'center' }); b.click(); }
	}
};
document.addEventListener('click', function (e) {
	var btn = e.target.closest ? e.target.closest('[data-sm-do]') : null;
	if (!btn) return;
	var fn = SM_DO[btn.getAttribute('data-sm-do')];
	if (typeof fn !== 'function' || !Object.prototype.hasOwnProperty.call(SM_DO, btn.getAttribute('data-sm-do'))) return;
	e.preventDefault();
	var args = [];
	try { args = JSON.parse(btn.getAttribute('data-sm-args') || '[]'); } catch (x) { return; }
	if (!Array.isArray(args)) return;
	if (btn.hasAttribute('data-sm-this')) args.push(btn);
	fn.apply(null, args);
});
// "Verify the newest backup" at the top shows once the list has a run to verify.
(function () {
	var top = document.getElementById('verifyNewestTop');
	if (top && document.getElementById('verifyNewestBtn')) top.hidden = false;
})();
