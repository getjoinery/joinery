<?php
/**
 * admin_backups_logic — the Backups page.
 *
 * Everything a site needs to back itself up lives here: where backups go, what
 * opens them, how many are kept, and what has actually happened. No fleet, no
 * agent — server_manager is a layer on top of this, not a prerequisite for it.
 *
 * @version 1.11 - save_target proves an enabled target before saving it (TargetTester 4.0) and refuses
 *                 one that fails, saying why
 * @version 1.10 - objects_status (BackupObjectsStatus::compute()): what each backup holds of the offloaded
 *                 files, what waits on this server for a backup, what is still to copy from the file
 *                 store, and whether the file store and the site's target share an account
 * @version 1.9 - offloaded files (specs/backup_offloaded_files.md § Verification): the page reads the daily
 *                file-store check (inventory) and who brings a missing file back (objects_source); the
 *                bring_back_objects action starts this site's own Bring them back in the background
 * @version 1.8 - milestones['verify_attempt']: a verify that proved nothing either way (skipped or
 *                refused) and is about a backup no older than the last proof, so the Status box can
 *                say what became of a verify a person started
 * @version 1.7 - verified restorable: milestones['verified'] (the newest run proven restorable) and
 *                ['verify_failed'] (a failure newer than that); 'ceremony' (when the recovery key was
 *                last proven by a person); the verify_backup action starts a level 2 or 3 verify of
 *                the site's own newest backup in the background
 * @version 1.6 - milestones: the newest backup, the newest full backup and the oldest backup still
 *                held, for the Status box
 * @version 1.5 - save_target completes a Backblaze credential through BackupTarget::complete_credentials
 * @version 1.4 - save_target fills a Backblaze target's region and endpoint from Backblaze's own authorize answer
 *                (both forms hide the fields for B2, and S3 signing needs them)
 * @version 1.3 - the history keeps cleaned-up runs visible (include_pruned) so Recent backups can show
 *                whether each backup is still present or has been pruned by retention
 * @version 1.2 - one backup history for all profiles (site + management-node runs), newest first, so the
 *                page shows a single Recent backups list labelled by who initiated each run
 * @version 1.1 - reports whether a management node runs this site's backups (is_managed / manager_url),
 *                so the page can defer targets, schedule and retention to the management node
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('includes/BackupRecoveryKey.php'));
require_once(PathHelper::getIncludePath('includes/BackupRunner.php'));
require_once(PathHelper::getIncludePath('includes/TargetTester.php'));
require_once(PathHelper::getIncludePath('data/backup_targets_class.php'));
require_once(PathHelper::getIncludePath('data/backup_history_class.php'));
require_once(PathHelper::getIncludePath('includes/BackupVerifyLauncher.php'));
require_once(PathHelper::getIncludePath('data/recovery_verifications_class.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStoreInventory.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStoreInventoryPanel.php'));
require_once(PathHelper::getIncludePath('includes/BackupObjectsStatus.php'));
require_once(PathHelper::getIncludePath('includes/BackupObjectRestoreLauncher.php'));

function admin_backups_logic($input = array()) {
	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$action = (string)($input['action'] ?? '');

	if ($action !== '') {
		$outcome = _admin_backups_handle($action, $input, $session);
		if ($outcome !== null) {
			return LogicResult::redirect($outcome);
		}
	}

	$targets = new MultiBackupTarget(array('deleted' => false), array('bkt_name' => 'ASC'));
	$targets->load();

	// Everything that has run, whoever initiated it. A site's own runs and a
	// management node's copies of it both seal to this site's recovery key, so they
	// are one history — each row labelled with who ran it. include_pruned keeps
	// backups retention has cleaned up in the list (shown as such) while still
	// excluding ones the admin hid; omitting the profile filter loads every
	// profile.
	$history = new MultiBackupHistory(array('include_pruned' => true),
		array('bkh_start_time' => 'DESC'), 30, 0);
	$history->load();

	// A plan that cannot be made is the most useful thing this page can say, so
	// the reason is surfaced rather than swallowed.
	$plan = null;
	$plan_problem = '';
	try {
		$plan = BackupRunner::plan();
	} catch (Exception $e) {
		$plan_problem = $e->getMessage();
	}

	// Whether a management node is responsible for this machine. When it is, the
	// backup targets, schedule and retention are configured on the management
	// node, not here — so this page stops offering to set them and stops warning
	// that they are unset.
	require_once(PathHelper::getIncludePath('includes/ManagementNodeStatus.php'));

	// A restore this machine's own agent is holding open, waiting for a person
	// here to authorize it. Read on every render rather than cached: the agent
	// stages and clears it underneath a running process, and a stale copy would
	// be an approval screen for a restore that is no longer running.
	require_once(PathHelper::getIncludePath('includes/RestoreApproval.php'));

	// A removal the HOST's agent is holding open — this site consenting to its
	// own destruction. Same read-fresh rule, its own settings rows.
	require_once(PathHelper::getIncludePath('includes/DecommissionApproval.php'));

	return LogicResult::render(array(
		'session'       => $session,
		'settings'      => Globalvars::get_instance(),
		'targets'       => $targets,
		'history'       => $history,
		'milestones'    => _admin_backups_milestones(),
		'ceremony'      => _admin_backups_ceremony_time(),
		'verify_every'  => _admin_backups_verify_every_days(),
		'recovery'      => BackupRecoveryKey::setup_state(),
		'plan'          => $plan,
		'plan_problem'  => $plan_problem,
		'default_slug'  => basename(PathHelper::getSiteRoot()),
		'task'          => _admin_backups_task_state(),
		'is_managed'    => ManagementNodeStatus::is_managed(),
		'manager_url'   => ManagementNodeStatus::manager_url(),
		'approval'      => RestoreApproval::pending(),
		'decommission_approval' => DecommissionApproval::pending(),
		// The daily file-store check: which offloaded files the file bucket
		// cannot serve, and who brings them back on this site.
		'inventory'      => CloudStoreInventory::current(),
		'objects_source' => CloudStoreInventoryPanel::source(ManagementNodeStatus::is_managed()),
		// Offloaded files and backup storage: held, waiting, still to copy, same account.
		'objects_status' => BackupObjectsStatus::compute(),
	));
}

/** Handle a POST. Returns a redirect URL, or null to fall through to render. */
function _admin_backups_handle($action, array $input, $session) {
	$url = '/admin/admin_backups';
	$page_regex = '/admin\/admin_backups/';

	// The setup wizard submits these same actions and bounces back to its own
	// step instead of this tab (specs/setup_wizard.md § New backend work 6).
	// Allow-listed: only /setup is ever accepted.
	if ((string)($input['return_to'] ?? '') === '/setup') {
		$url = '/setup?step=backups';
		$page_regex = '/\/setup/';
	}

	// DisplayMessage lives in the pre-loaded SessionControl.php — never required.
	$say = function ($message, $ok) use ($session, $page_regex) {
		$session->save_message(new DisplayMessage(
			$message,
			$ok ? 'Success' : 'Error',
			$page_regex,
			$ok ? DisplayMessage::MESSAGE_ANNOUNCEMENT : DisplayMessage::MESSAGE_ERROR,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
	};

	try {
		switch ($action) {

			case 'save_recovery_key':
				BackupRecoveryKey::set_public_key((string)($input['backup_recovery_public_key'] ?? ''));
				$say('Recovery key saved. Now prove you hold the matching private key.', true);
				return $url . '#recovery-key';

			case 'clear_recovery_key':
				BackupRecoveryKey::clear_public_key();
				$say('Recovery key cleared. Paste a different one to start again.', true);
				return $url . '#recovery-key';

			case 'verify_recovery_key':
				BackupRecoveryKey::record_possession_proof((string)($input['recovery_proof'] ?? ''));
				$say('Verified — the key you hold opens what this site seals.', true);
				return $url . '#recovery-key';

			// ── Approving a restore this machine's own agent is holding ──
			//
			// Nothing is decided here. The answer is a one-time secret that only
			// the recovery key could have produced, and the agent compares it in
			// constant time against what it sealed. This handler moves a string;
			// a compromised web tier gains nothing by reaching it, because it
			// still could not produce the string.
			//
			// BOTH ACTIONS VERIFY THEIR TOKEN, and DECLINE is the one that needs
			// it. Approving is self-protecting — an attacker cannot produce the
			// sealed answer — but declining takes nothing but a job id that is
			// printed on the page. This handler runs on any request carrying an
			// `action`, GET included, so without a token check a superadmin
			// lured to a crafted link would refuse a restore that was mid-flight
			// on a machine somebody is trying to recover. It fails safe (nothing
			// is destroyed) and it fails at the worst moment, which is why these
			// two are checked where the rest of this file's actions are not.
			// ── Approving this site's own permanent removal ──
			//
			// The same shape as the restore pair below, under decommission's own
			// names: the challenge was staged by the HOST's agent, the answer is
			// a one-time secret only this site's recovery key could produce, and
			// the host compares it in constant time. Token-checked for the same
			// reason: DECLINE takes nothing but a printed job id, and a lured
			// superadmin must not be able to refuse (or worse, be tricked into
			// answering) a removal via a crafted link.
			case 'approve_decommission':
			case 'decline_decommission': {
				require_once(PathHelper::getIncludePath('includes/DecommissionApproval.php'));
				$form = ($action === 'approve_decommission') ? 'decommission_approval_form' : 'decommission_decline_form';
				$fw = new FormWriterV2HTML5($form);
				if (!$fw->validateCSRF($input)) {
					$say('That request could not be verified — reload the Backups page and try again.', false);
					return $url;
				}
				$job_id = (int)($input['approval_job_id'] ?? 0);
				if ($action === 'approve_decommission') {
					DecommissionApproval::answer($job_id, (string)($input['approval_answer'] ?? ''));
					$say('Approved. The host is checking your answer and will remove this site permanently.', true);
				} else {
					DecommissionApproval::decline($job_id);
					$say('Declined. Nothing was deleted, and the removal is reported refused.', true);
				}
				return $url;
			}

			case 'approve_restore':
			case 'decline_restore': {
				require_once(PathHelper::getIncludePath('includes/RestoreApproval.php'));
				$form = ($action === 'approve_restore') ? 'restore_approval_form' : 'restore_decline_form';
				$fw = new FormWriterV2HTML5($form);
				if (!$fw->validateCSRF($input)) {
					$say('That request could not be verified — reload the Backups page and try again.', false);
					return $url;
				}
				$job_id = (int)($input['approval_job_id'] ?? 0);
				if ($action === 'approve_restore') {
					RestoreApproval::answer($job_id, (string)($input['approval_answer'] ?? ''));
					$say('Approved. This machine is checking your answer and will start the restore.', true);
				} else {
					RestoreApproval::decline($job_id);
					$say('Declined. Nothing was restored, and the job is reported refused.', true);
				}
				return $url;
			}

			case 'save_target': {
				$id = (int)($input['bkt_backup_target_id'] ?? 0);
				$target = $id ? new BackupTarget($id, TRUE) : new BackupTarget(NULL);
				$target->set('bkt_name', trim((string)($input['bkt_name'] ?? '')));
				$target->set('bkt_provider', (string)($input['bkt_provider'] ?? ''));
				$target->set('bkt_bucket', trim((string)($input['bkt_bucket'] ?? '')));
				$target->set('bkt_path_prefix', trim((string)($input['bkt_path_prefix'] ?? '')) ?: 'joinery-backups');

				// A blank secret on an edit means "leave it alone" — the stored
				// value is never rendered back into the form, so an operator
				// changing a bucket name must not have to re-enter the key.
				$access = trim((string)($input['access_key'] ?? ''));
				$secret = trim((string)($input['secret_key'] ?? ''));
				if ($access !== '' || $secret !== '' || !$id) {
					$existing = $id ? ($target->get_credentials() ?: array()) : array();
					$completed = BackupTarget::complete_credentials((string)$target->get('bkt_provider'), array(
						'access_key' => $access !== '' ? $access : (string)($existing['access_key'] ?? ''),
						'secret_key' => $secret !== '' ? $secret : (string)($existing['secret_key'] ?? ''),
						'region'     => (string)($input['region'] ?? ($existing['region'] ?? '')),
						'endpoint'   => (string)($input['endpoint'] ?? ($existing['endpoint'] ?? '')),
					));
					if ($completed['note'] !== '') {
						$b2_note = ' ' . $completed['note'];
					}
					$target->set('bkt_credentials', $completed['creds']);
				}
				$target->set('bkt_enabled', !empty($input['bkt_enabled']));
				// Proven before it is saved: an enabled target that cannot do
				// its job is not saved, and the message says why. A disabled
				// target is saved untested; enabling it is a save, and that
				// save tests it.
				if ($target->get('bkt_enabled')) {
					$test = TargetTester::test($target);
					if (!$test['success']) {
						$say('Not saved. ' . $test['message'] . ($b2_note ?? ''), false);
						return $url;
					}
					$target->save();
					$say('Target saved. ' . $test['message'], true);
					return $url;
				}
				$target->save();
				$say('Target saved. It is disabled, so it was not tested; enabling it tests it.' . ($b2_note ?? ''), true);
				return $url;
			}

			case 'test_target': {
				$target = new BackupTarget((int)($input['bkt_backup_target_id'] ?? 0), TRUE);
				$test = TargetTester::test($target);
				$say(($test['success'] ? 'Connection OK: ' : 'Connection failed: ') . $test['message'], $test['success']);
				return $url;
			}

			case 'delete_target': {
				$target = new BackupTarget((int)($input['bkt_backup_target_id'] ?? 0), TRUE);
				// Deleting the target a schedule points at would leave the task
				// skipping every night with a message nobody reads, so say so now.
				if ((int)Globalvars::get_instance()->get_setting('backup_target_id') === (int)$target->key) {
					$say('That target is the one scheduled backups use. Point the schedule somewhere else first.', false);
					return $url;
				}
				$target->soft_delete();
				$say('Target deleted. Backups already in its bucket are untouched.', true);
				return $url;
			}

			case 'save_schedule': {
				// Declared settings are written through SettingsWriter, not by
				// this page: it is what applies the declared validation, honours
				// the vault gate, and refuses names outside the scope it was
				// given. A page writing its own SQL bypasses all three.
				require_once(PathHelper::getIncludePath('includes/SettingsWriter.php'));
				require_once(PathHelper::getIncludePath('includes/SettingsFieldRenderer.php'));

				$slug = trim((string)($input['backup_path_slug'] ?? ''));
				if ($slug !== '' && !preg_match('/^[A-Za-z0-9_-]+$/', $slug)) {
					$say('The backup folder name may only contain letters, numbers, hyphens and underscores.', false);
					return $url;
				}

				$names = SettingsFieldRenderer::namesFor('backups', 'core');
				$names = array_values(array_diff($names,
					array('backup_recovery_public_key', 'backup_recovery_public_key_proven_fpr')));

				// A retention count of zero would mean "keep nothing", which is
				// not a thing anyone means. Floor it before it is stored.
				if (isset($input['backup_retention_count'])) {
					$input['backup_retention_count'] = (string)max(1, (int)$input['backup_retention_count']);
				}
				if (isset($input['backup_local_retention_days'])) {
					$input['backup_local_retention_days'] = (string)max(0, (int)$input['backup_local_retention_days']);
				}

				$write = SettingsWriter::write($input, array(
					'page'   => 'admin_backups',
					'source' => 'core',
					'names'  => $names,
				));
				if (!empty($write['errors'])) {
					$first = reset($write['errors']);
					$say(is_array($first) ? implode(' ', $first) : (string)$first, false);
					return $url;
				}
				$say('Backup settings saved.', true);
				return $url;
			}

			case 'run_backup': {
				// Refuse the obvious no-op loudly: with no viable plan, a run
				// would only record a skip nobody asked for. plan() throws with
				// the exact reason, which the catch below shows the operator.
				BackupRunner::plan();

				// The run outlives any sensible web request — a first full can
				// take hours — so it happens in its own process. Progress and
				// outcome land in Recent backups, where scheduled runs report.
				$runner = PathHelper::getIncludePath('utils/run_backup.php');
				exec('nohup php ' . escapeshellarg($runner) . ' > /dev/null 2>&1 &');
				$say('Backup started in the background. Watch it under Recent backups.', true);
				return $url;
			}

			case 'verify_backup': {
				// Prove the newest backup restorable without restoring it. Level
				// 2 opens and reads every archive it depends on; level 3 also
				// replays it into scratch and a throwaway database. Nothing on
				// the site is touched either way. Runs in its own process like a
				// backup does; the result lands on the run's row under Recent
				// backups and on the Status box.
				$level = (int)($input['level'] ?? 0);
				if (!BackupVerifier::is_runnable_level($level)) {
					throw new Exception('A verify is either "open and read" or "rehearse a restore".');
				}
				$run = BackupVerifyLauncher::newest_run();
				if ($run === null) {
					throw new Exception('This site has no backup of its own to verify yet.');
				}
				BackupVerifyLauncher::start($run, $level);
				$say(($level === BackupVerifier::LEVEL_REHEARSE ? 'Rehearsing a restore of' : 'Opening and reading')
					. ' the backup of ' . BackupVerifier::when_words((string)$run->get('bkh_start_time'))
					. ' in the background. The result appears here when it is done.', true);
				return $url;
			}

			case CloudStoreInventoryPanel::ACTION: {
				// Bring the offloaded files the file store has lost back from
				// this site's own newest backup, in the background. Only what
				// the file store cannot serve is touched; the result lands on
				// the inventory record the Offloaded files box reads.
				$say(BackupObjectRestoreLauncher::start_newest(BackupObjectRestore::MODE_MISSING), true);
				return $url;
			}

			case 'delete_history': {
				$row = new BackupHistory((int)($input['bkh_backup_history_id'] ?? 0), TRUE);
				$row->set('bkh_delete_time', gmdate('Y-m-d H:i:s'));
				$row->save();
				$say('Removed from the list. The stored backup itself was not deleted.', true);
				return $url;
			}
		}
	} catch (Exception $e) {
		$say($e->getMessage(), false);
		return $url;
	}

	return null;
}

/**
 * The three facts an operator asks of a backup history — the newest backup,
 * the newest FULL backup, and the oldest backup still held — each as the run's
 * history row, or null. Read from runs that are still present offsite: a run
 * retention cleaned up is soft-deleted, and a hidden one is too, so the
 * collection's defaults leave both out.
 */
function _admin_backups_milestones() {
	$base = array('outcome' => 'success', 'offsite' => true, 'deleted' => false);
	$one = function (array $extra, $direction) use ($base) {
		$rows = new MultiBackupHistory(array_merge($base, $extra), array('bkh_start_time' => $direction), 1, 0);
		foreach ($rows as $r) { return $r; }
		return null;
	};
	// A full backup is the first run of a chain or a standalone archive; the
	// newer of the two is the answer.
	$full = $one(array('bkh_chain_seq' => 0), 'DESC');
	$alone = $one(array('chained' => false), 'DESC');
	if ($alone && (!$full || (string)$alone->get('bkh_start_time') > (string)$full->get('bkh_start_time'))) {
		$full = $alone;
	}
	// Verified restorable: the newest run proven so, and a failure newer than
	// that proof (a failed verify of a later backup is worth more than an old
	// pass, and is shown beside it rather than hiding it). Either profile
	// counts — a management node's copy this machine proved is a proof.
	$verified = $one(array('bkh_verify_outcome' => 'pass'), 'DESC');
	$verify_failed = null;
	$failed = new MultiBackupHistory(
		array_merge($base, array('bkh_verify_outcome' => 'fail')), array('bkh_verify_time' => 'DESC'), 1, 0);
	foreach ($failed as $r) {
		if ($verified === null || (string)$r->get('bkh_verify_time') > (string)$verified->get('bkh_verify_time')) {
			$verify_failed = $r;
		}
	}
	// A verify that proved nothing either way — skipped for disk or a busy
	// machine, or refused before it read anything — leaves its reason as the
	// run's message with no outcome. Shown when it is about a backup newer
	// than the last proof, so a person who pressed the button sees what
	// became of it.
	$verify_attempt = null;
	$attempts = new MultiBackupHistory(
		array_merge($base, array('verify_attempted' => true)), array('bkh_start_time' => 'DESC'), 1, 0);
	foreach ($attempts as $r) {
		if (!BackupVerifier::is_attempt_message((string)$r->get('bkh_verify_message'))) { continue; }
		if ($verified === null || (string)$r->get('bkh_start_time') >= (string)$verified->get('bkh_start_time')) {
			$verify_attempt = $r;
		}
	}
	return array(
		'newest'         => $one(array(), 'DESC'),
		'full'           => $full,
		'oldest'         => $one(array(), 'ASC'),
		'verified'       => $verified,
		'verify_failed'  => $verify_failed,
		'verify_attempt' => $verify_attempt,
	);
}

/**
 * Days between scheduled verifications of the newest backup: the setting, or
 * its declared default (30) where the row has not been seeded yet.
 */
function _admin_backups_verify_every_days() {
	$v = Globalvars::get_instance()->get_setting('backup_verify_every_days', true, true);
	return ($v === null || $v === '') ? 30 : (int)$v;
}

/**
 * When a person last proved the recovery private key opens a challenge — the
 * ceremony on the Recovery Readiness page. Shown beside the last verify,
 * because the two together are the proof: the ceremony shows the key opens an
 * envelope, the verify shows the envelope's contents are sound. Null when
 * never. Whoever ran it; the key is the site's, not a user's.
 */
function _admin_backups_ceremony_time() {
	try {
		$latest = RecoveryVerification::latest_passed(array('backup_recovery_key'));
		return isset($latest['backup_recovery_key']) ? (string)$latest['backup_recovery_key'] : null;
	} catch (\Throwable $e) {
		return null;
	}
}

/**
 * Whether the scheduled task is switched on, and when it last ran. A page that
 * shows a schedule while the task is inactive is describing something that will
 * never happen.
 */
function _admin_backups_task_state() {
	try {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			'SELECT sct_is_active, sct_last_run_time, sct_last_run_status, sct_last_run_message,
			        sct_frequency, sct_schedule_time
			   FROM sct_scheduled_tasks
			  WHERE sct_task_class = ? AND sct_delete_time IS NULL LIMIT 1');
		$q->execute(array('BackupRun'));
		$row = $q->fetch(PDO::FETCH_ASSOC);
		return $row ?: array();
	} catch (\Throwable $e) {
		return array();
	}
}
