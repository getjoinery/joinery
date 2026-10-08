<?php
/**
 * Cloud Storage Admin Logic
 *
 * Thin caller over the shared CloudStorageLifecycle. The page manages the file
 * store: a private bucket that holds private uploads, Drive files and inbound
 * mail, kept as a target row (bkt_purpose 'files') with its key sealed like
 * every target's. Save = the shared target form read onto the row + the
 * check + store; the check stores nothing on a fail. Pause, "Disable and Pull
 * Files Back to Local" and Remove act on the store. Switching to another
 * bucket saves a second store and makes it the one new offloads go to; the
 * older store keeps serving the files whose records name it until Move files
 * carries them across. Offload itself runs through one platform task
 * (CloudOffloadRun).
 *
 * @version 4.1 - an older store's key is replaced from its own row (edit_store): it still serves its files
 * @version 4.0 - the file store is a target row (specs/storage_targets.md WP6): save and edit go through
 *                BackupTargetForm and CloudStorageLifecycle::saveStore(); a key is replaced by editing the
 *                store; switch to another bucket, Move files, stop a move, delete an emptied older store
 * @version 3.2 - the secret key is read through FormWriterV2Base::process_secretinput(): a locked field keeps
 *                the stored key, Reset and blank fails as required
 * @changelog 3.1 - replace_key: a rotated key is proved and stored on its own, leaving the enabled latch
 *                and the draining flag untouched — the save path's activate-and-stop-draining is what
 *                a full Save means, not what replacing a key means
 * @version 3.0.1 - Retry clears the recorded reason with the count
 * @version 3.0 - one private store (specs/implemented/cloud_storage_private_only.md): one Save, one binding, one
 *                pull-back; the private-store fields and disable_and_pull_private are gone
 * @version 2.2
 */

function admin_cloud_storage_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$profile = new BlobStorageProfile();   // the file-blob profile, whose figures the page shows

	$test_results = null;
	$errors = array();
	// The form drawn again after a refused save, with what was entered: the
	// store being edited, or a new one.
	$form_target = null;
	$form_new = !empty($input['new_store']);
	// An older store whose key is being replaced: it still serves the files
	// whose records name it until they are moved, so its key must stay good.
	$edit_store = null;
	if (!empty($input['edit_store'])) {
		$candidate = new BackupTarget((int)$input['edit_store'], TRUE);
		if ($candidate->key && !$candidate->get('bkt_delete_time') && $candidate->is_file_store()) {
			$edit_store = $candidate;
		}
	}

	$say = function ($message, $title, $ok = true) use ($session) {
		$session->save_message(new DisplayMessage($message, $title, '/\/admin\/admin_cloud_storage/',
			$ok ? DisplayMessage::MESSAGE_ANNOUNCEMENT : DisplayMessage::MESSAGE_ERROR,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		return LogicResult::redirect('/admin/admin_cloud_storage');
	};
	$store_of = function () use ($input) {
		$target = new BackupTarget((int)($input['bkt_backup_target_id'] ?? 0), TRUE);
		return ($target->key && !$target->get('bkt_delete_time') && $target->is_file_store()) ? $target : null;
	};

	if ($input && isset($input['action'])) {
		$action = $input['action'];

		if ($action === 'save_store') {
			// A new store, or an edit of one. A new one becomes where new
			// offloads go. An edit of a store that holds files may change its
			// key and name only (the form draws the rest read-only and apply()
			// refuses a change to it).
			$editing = $store_of();
			$target = $editing ?: new BackupTarget(NULL);
			$saved = CloudStorageLifecycle::saveStore($target, $input, $editing === null);
			$test_results = $saved['test_results'];
			if ($saved['ok']) {
				$note = $saved['message'] !== '' ? ' ' . $saved['message'] : '';
				return $say(($editing
					? 'File store saved. It keeps doing what it was doing.'
					: 'File store saved. Private files start moving to it on the next cron tick.') . $note, 'Saved');
			}
			if ($saved['message'] !== '') {
				$errors[] = $saved['message'];
			}
			$form_target = $target;
			$form_new = $editing === null;
			$current_id = (int)Globalvars::get_instance()->get_setting(BackupTarget::FILE_STORE_SETTING, false, true);
			if ($editing !== null && (int)$editing->key !== $current_id) {
				$edit_store = $editing;
			}
		}
		elseif ($action === 'enable') {
			// Re-prove the stored store before offloading to it again.
			$target = CloudStorageDriverFactory::currentTarget();
			if ($target === null) {
				return $say('There is no file store to enable.', 'Not enabled', false);
			}
			$test_results = CloudStorageLifecycle::testConnection(
				CloudStorageDriverFactory::options($target) + array('prefix' => $target->prefix()));
			if ($test_results['ok']) {
				CloudStorageLifecycle::setEnabled(true);
				CloudStorageLifecycle::stopDrain();
				CloudStorageLifecycle::ensureTickActive();
				return $say('Cloud storage enabled. Private files start moving to the file store on the next cron tick.', 'Enabled');
			}
		}
		elseif ($action === 'remove') {
			$why = CloudStorageLifecycle::removeStore();
			return $why === ''
				? $say('Cloud storage removed. Uploads stay on this server.', 'Removed')
				: $say($why, 'Not removed', false);
		}
		elseif ($action === 'pause') {
			// Stop offloading new files; files already offloaded keep serving
			// from their store (idle mode, not drain). The tick keeps running
			// while those files exist, for the daily file-store check.
			CloudStorageLifecycle::setEnabled(false);
			CloudStorageLifecycle::stopDrain();
			return $say('Cloud storage paused. Files already in the file store keep being served from it.', 'Paused');
		}
		elseif ($action === 'disable_and_pull') {
			// The offload tick pulls every offloaded file back to local, each
			// from the store its record names, then clears the flag itself.
			CloudStorageLifecycle::setEnabled(false);
			CloudStorageLifecycle::startDrain();
			return $say('Pull-back started. Offloaded files will be returned to local disk over the next several cron ticks.', 'Pull-back queued');
		}
		elseif ($action === 'move_files') {
			$from = $store_of();
			$why = $from ? CloudStorageLifecycle::startMove((int)$from->key) : 'That file store is gone.';
			return $why === ''
				? $say('Moving the files to the current file store, a batch on each cron tick.', 'Move started')
				: $say($why, 'Not started', false);
		}
		elseif ($action === 'stop_move') {
			CloudStorageLifecycle::stopMove();
			return $say('Move stopped. Files already moved stay moved; the rest are served from where they are.', 'Stopped');
		}
		elseif ($action === 'delete_store') {
			$store = $store_of();
			if ($store === null) {
				return $say('That file store is gone.', 'Not deleted', false);
			}
			$why = $store->delete_refusal();
			if ($why !== '') {
				return $say($why, 'Not deleted', false);
			}
			$store->soft_delete();
			CloudStorageDriverFactory::reset();
			return $say('File store "' . $store->get('bkt_name') . '" deleted. Nothing in its bucket was touched.', 'Deleted');
		}
		elseif ($action === CloudStoreInventoryPanel::ACTION) {
			// Bring the offloaded files the file store has lost back from this
			// site's own newest backup, in the background. Only what the file
			// store cannot serve is touched.
			try {
				return $say(BackupObjectRestoreLauncher::start_newest(BackupObjectRestore::MODE_MISSING), 'Started');
			} catch (Exception $e) {
				return $say($e->getMessage(), 'Error', false);
			}
		}
		elseif ($action === 'retry_stuck' && isset($input['fbb_file_blob_id'])) {
			$dblink = DbConnector::get_instance()->get_db_link();
			$q = $dblink->prepare("UPDATE fbb_file_blobs SET fbb_sync_failed_count = 0, fbb_sync_last_error = NULL WHERE fbb_file_blob_id = ?");
			$q->execute([(int)$input['fbb_file_blob_id']]);
			return LogicResult::redirect('/admin/admin_cloud_storage');
		}
	}

	$settings = Globalvars::get_instance();
	$current = CloudStorageDriverFactory::currentTarget();
	$cloud_count = CloudStorageLifecycle::cloudRowCount();
	$draining = (bool)$settings->get_setting('cloud_storage_draining');
	$page_data = array(
		'session'       => $session,
		// The store new offloads go to, and every store with what it holds.
		'current'       => $current,
		'current_count' => $current ? CloudStorageLifecycle::cloudRowCount((int)$current->key) : 0,
		'stores'        => CloudStorageLifecycle::stores(),
		'move'          => CloudStorageLifecycle::moveState(),
		'enabled'       => (bool)$settings->get_setting('cloud_storage_enabled'),
		'configured'    => $current !== null,
		'cloud_count'   => $cloud_count,
		'draining'      => $draining,
		// The form drawn again after a refused save, and whether it is a new store's.
		'form_target'   => $form_target,
		'form_new'      => $form_new,
		'edit_store'    => $edit_store,
		'errors'        => $errors,
		'test_results'  => $test_results,
		'health'        => CloudStorageLifecycle::health($profile),
		// The daily file-store check and who brings a missing file back.
		'inventory'      => CloudStoreInventory::current(),
		'objects_source' => CloudStoreInventoryPanel::source(ManagementNodeStatus::is_managed()),
		'objects_status' => BackupObjectsStatus::compute(),
		'manager_url'    => ManagementNodeStatus::manager_url(),
	);

	return LogicResult::render($page_data);
}
