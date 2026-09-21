<?php
/**
 * Cloud Storage Admin Logic
 *
 * Thin caller over the shared CloudStorageLifecycle. The page manages the
 * public-blob store (BlobStorageProfile) and, independently, the private
 * store's bucket configuration + privacy gate. Save = test + persist +
 * activate, per store present in the form; each store's Save is validated
 * independently (a private-bucket failure never blocks the public Save, and
 * vice versa). Pause and "Disable and Pull Files Back to Local" act on the
 * public store; the private store has its own "Disable and Pull Back" that
 * drains its cloud objects to local. Offload itself runs through one platform
 * task (CloudOffloadRun): enabling a store sets it to offload mode and ensures
 * that task is active; the tick drives every store of every visibility from the
 * registry, so the admin never names a profile or a per-store task.
 *
 * @version 2.5 - the page's shape (configured, locked, public_cloud, draining); a field the form did not
 *                post keeps its stored value, so Enable re-proves the stored settings and the locked
 *                form posts only what may change; the remove action forgets an empty store
 * @version 2.4 - objects_status (BackupObjectsStatus::compute()) for the waiting-for-backup count and
 *                size and the same-account line
 * @version 2.3 - the daily file-store check (inventory) and who brings a missing file back
 *                (objects_source) are handed to the page; the bring_back_objects action starts
 *                this site's own Bring them back in the background
 * @version 2.2
 */

require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_cloud_storage_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageLifecycle.php'));
	require_once(PathHelper::getIncludePath('includes/cloud_storage/BlobStorageProfile.php'));
	require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStoreInventory.php'));
	require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStoreInventoryPanel.php'));
	require_once(PathHelper::getIncludePath('includes/BackupObjectRestoreLauncher.php'));
	require_once(PathHelper::getIncludePath('includes/BackupObjectsStatus.php'));
	require_once(PathHelper::getIncludePath('includes/ManagementNodeStatus.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$settings = Globalvars::get_instance();
	$profile  = new BlobStorageProfile();   // the public store this page manages

	$test_results = null;          // public store
	$errors = array();
	$private_test_results = null;  // private store
	$private_errors = array();

	// A field the form did not post keeps its stored value. The page shows the
	// locked fields (endpoint, region, bucket) read-only while files are in the
	// bucket and posts only the ones that may change; Enable posts nothing and
	// re-proves the stored settings.
	$posted = function ($key) use ($input, $settings) {
		return array_key_exists($key, $input) ? trim((string)$input[$key]) : trim((string)$settings->get_setting($key));
	};

	if ($input && isset($input['action'])) {
		$action = $input['action'];
		if ($action === 'enable') {
			$action = 'save';
		}

		if ($action === 'save') {
			// ---- Public store -------------------------------------------------
			// The secret key is a password field, so it never carries its stored
			// value into the page. A blank submission therefore means "keep the
			// stored key" — the connection test below needs a real key to run.
			$secret_key = trim($input['cloud_storage_secret_key'] ?? '');
			if ($secret_key === '') {
				$secret_key = (string)$settings->get_setting('cloud_storage_secret_key');
			}
			$opts = array(
				'endpoint'        => $posted('cloud_storage_endpoint'),
				'region'          => $posted('cloud_storage_region'),
				'bucket'          => $posted('cloud_storage_bucket'),
				'access_key'      => $posted('cloud_storage_access_key'),
				'secret_key'      => $secret_key,
				'public_base_url' => $posted('cloud_storage_public_base_url'),
			);
			$public_ok = false;
			foreach (['endpoint', 'bucket', 'access_key', 'secret_key'] as $field) {
				if ($opts[$field] === '') {
					$errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
				}
			}
			if (empty($errors)) {
				$mutable = CloudStorageLifecycle::assertBindingMutable($opts, 'public');
				if (!$mutable['ok']) {
					$errors[] = $mutable['message'];
				} else {
					$test_results = CloudStorageLifecycle::testConnection($opts, 'public');
					if ($test_results['ok']) {
						$persist = CloudStorageLifecycle::persistSettings($opts, 'public', $session);
						if ($persist['ok']) {
							CloudStorageLifecycle::stopDrain('public', $session); // enabling cancels any in-progress drain
							CloudStorageLifecycle::ensureTickActive();
							$public_ok = true;
						} else {
							$errors[] = $persist['message'];
						}
					}
				}
			}

			// ---- Private store (independent) ----------------------------------
			$private_bucket = $posted('cloud_storage_private_bucket');
			$private_handled = false;
			$private_ok = true;
			if ($private_bucket !== '') {
				$private_handled = true;
				$private_ok = false;
				$private_opts = array(
					'endpoint'        => $opts['endpoint'],
					'region'          => $opts['region'],
					'bucket'          => $private_bucket,
					'access_key'      => $opts['access_key'],
					'secret_key'      => $opts['secret_key'],
					'public_base_url' => '',
				);
				$pmutable = CloudStorageLifecycle::assertBindingMutable($private_opts, 'private');
				if (!$pmutable['ok']) {
					$private_errors[] = $pmutable['message'];
				} else {
					$private_test_results = CloudStorageLifecycle::testConnection($private_opts, 'private');
					if ($private_test_results['ok']) {
						$ppersist = CloudStorageLifecycle::persistSettings($private_opts, 'private', $session);
						if ($ppersist['ok']) {
							// Gate passed + latch set: the single offload tick now
							// offloads every private-visibility store (private files,
							// inbound-mail raw) on its next run.
							CloudStorageLifecycle::stopDrain('private', $session); // enabling cancels any in-progress drain
							CloudStorageLifecycle::ensureTickActive();
							$private_ok = true;
						} else {
							$private_errors[] = $ppersist['message'];
						}
					}
				}
			} else {
				// Cleared private bucket: degrade cleanly (disable + blank) unless
				// it would strand private cloud rows.
				if ($settings->get_setting('cloud_storage_private_bucket') !== '' || $settings->get_setting('cloud_storage_private_enabled')) {
					$private_handled = true;
					$private_ok = false;
					$pmutable = CloudStorageLifecycle::assertBindingMutable(['endpoint' => $opts['endpoint'], 'bucket' => ''], 'private');
					if (!$pmutable['ok']) {
						$private_errors[] = $pmutable['message'];
					} else {
						CloudStorageLifecycle::setEnabled('private', false, $session, ['cloud_storage_private_bucket' => '']);
						CloudStorageLifecycle::stopDrain('private', $session); // guard 1 already ensured no cloud rows remain; tick self-deactivates when idle
						$private_ok = true;
					}
				}
			}

			// ---- Redirect only when nothing needs inline diagnostics ----------
			$public_clean  = empty($errors) && ($public_ok || (empty($opts['endpoint']) && empty($opts['bucket'])));
			$private_clean = empty($private_errors) && $private_ok;
			if ($public_clean && $private_clean) {
				$saved = array();
				if ($public_ok)                  $saved[] = 'Public files store enabled. Migration of existing public files will start on the next cron tick.';
				if ($private_handled && $private_bucket !== '') $saved[] = 'Private store verified non-public and enabled.';
				if ($private_handled && $private_bucket === '') $saved[] = 'Private store cleared.';
				$session->save_message(new DisplayMessage(
					$saved ? implode(' ', $saved) : 'No changes.',
					'Saved', '/\/admin\/admin_cloud_storage/',
					DisplayMessage::MESSAGE_ANNOUNCEMENT,
					DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
				));
				return LogicResult::redirect('/admin/admin_cloud_storage');
			}
			// otherwise fall through and render diagnostics inline
		}
		elseif ($action === 'remove') {
			// Forget the bucket and the key. Only when nothing is in the bucket
			// and nothing is on its way back: a binding that still names
			// offloaded files is what the pull-back reads.
			if (CloudStorageLifecycle::cloudRowCount('public') > 0 || CloudStorageLifecycle::cloudRowCount('private') > 0
					|| $settings->get_setting('cloud_storage_draining') || $settings->get_setting('cloud_storage_private_draining')) {
				$session->save_message(new DisplayMessage(
					'Files are still in the bucket, or on their way back. Disable and pull them back first; remove once the count is zero.',
					'Not removed', '/\/admin\/admin_cloud_storage/',
					DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
				return LogicResult::redirect('/admin/admin_cloud_storage');
			}
			CloudStorageLifecycle::setEnabled('public', false, $session, array(
				'cloud_storage_endpoint' => '', 'cloud_storage_region' => '', 'cloud_storage_bucket' => '',
				'cloud_storage_access_key' => '', 'cloud_storage_secret_key' => '', 'cloud_storage_public_base_url' => '',
			));
			CloudStorageLifecycle::setEnabled('private', false, $session, array('cloud_storage_private_bucket' => ''));
			CloudStorageLifecycle::stopDrain('public', $session);
			CloudStorageLifecycle::stopDrain('private', $session);
			$session->save_message(new DisplayMessage(
				'Cloud storage removed. Uploads stay on this server.',
				'Removed', '/\/admin\/admin_cloud_storage/',
				DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
			return LogicResult::redirect('/admin/admin_cloud_storage');
		}
		elseif ($action === 'pause') {
			// Pause: stop offloading new files; keep existing cloud files serving
			// (idle mode, not drain). The tick keeps running while those files
			// exist, for the daily file-store check.
			CloudStorageLifecycle::setEnabled('public', false, $session);
			CloudStorageLifecycle::stopDrain('public', $session);
			$session->save_message(new DisplayMessage(
				'Cloud storage paused. Existing cloud-stored files continue to serve from the bucket.',
				'Paused', '/\/admin\/admin_cloud_storage/',
				DisplayMessage::MESSAGE_ANNOUNCEMENT,
				DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
			return LogicResult::redirect('/admin/admin_cloud_storage');
		}
		elseif ($action === 'disable_and_pull') {
			// Disable the latch and set the draining flag; the offload tick pulls
			// public cloud files back to local until none remain, then clears the
			// flag itself.
			CloudStorageLifecycle::setEnabled('public', false, $session);
			CloudStorageLifecycle::startDrain('public', $session);
			$session->save_message(new DisplayMessage(
				'Pull-back started. Bucket-stored files will be returned to local disk over the next several cron ticks.',
				'Pull-back queued', '/\/admin\/admin_cloud_storage/',
				DisplayMessage::MESSAGE_ANNOUNCEMENT,
				DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
			return LogicResult::redirect('/admin/admin_cloud_storage');
		}
		elseif ($action === 'disable_and_pull_private') {
			// Disable the private store's latch (forVisibility('private') goes null)
			// but KEEP the bucket binding so the tick's drain can still read — it
			// resolves the driver with-fallback to the unlatched binding. The
			// bucket is cleared later by a Save with an empty field, once guard 1
			// sees zero cloud rows.
			CloudStorageLifecycle::setEnabled('private', false, $session);
			CloudStorageLifecycle::startDrain('private', $session);
			$session->save_message(new DisplayMessage(
				'Private-store pull-back started. Offloaded inbound-mail raw will return to local disk over the next several cron ticks; clear the private bucket field and Save once it reaches zero to fully remove it.',
				'Pull-back queued', '/\/admin\/admin_cloud_storage/',
				DisplayMessage::MESSAGE_ANNOUNCEMENT,
				DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
			return LogicResult::redirect('/admin/admin_cloud_storage');
		}
		elseif ($action === CloudStoreInventoryPanel::ACTION) {
			// Bring the offloaded files the file store has lost back from this
			// site's own newest backup, in the background. Only what the file
			// store cannot serve is touched.
			try {
				$message = BackupObjectRestoreLauncher::start_newest(BackupObjectRestore::MODE_MISSING);
				$session->save_message(new DisplayMessage($message, 'Started', '/\/admin\/admin_cloud_storage/',
					DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
			} catch (Exception $e) {
				$session->save_message(new DisplayMessage($e->getMessage(), 'Error', '/\/admin\/admin_cloud_storage/',
					DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
			}
			return LogicResult::redirect('/admin/admin_cloud_storage');
		}
		elseif ($action === 'retry_stuck' && isset($input['fbb_file_blob_id'])) {
			$dblink = DbConnector::get_instance()->get_db_link();
			$q = $dblink->prepare("UPDATE fbb_file_blobs SET fbb_sync_failed_count = 0 WHERE fbb_file_blob_id = ?");
			$q->execute([(int)$input['fbb_file_blob_id']]);
			return LogicResult::redirect('/admin/admin_cloud_storage');
		}
	}

	// On a failed save, repopulate from POST so the admin doesn't lose input.
	$pick = function($key) use ($input, $settings) {
		if (isset($input[$key])) return $input[$key];
		return $settings->get_setting($key);
	};

	$page_data = array(
		'session'         => $session,
		'settings_values' => array(
			'endpoint'        => $pick('cloud_storage_endpoint'),
			'region'          => $pick('cloud_storage_region'),
			'bucket'          => $pick('cloud_storage_bucket'),
			'access_key'      => $pick('cloud_storage_access_key'),
			'secret_key'      => $pick('cloud_storage_secret_key'),
			'public_base_url' => $pick('cloud_storage_public_base_url'),
			'private_bucket'  => $pick('cloud_storage_private_bucket'),
		),
		'enabled'              => (bool)$settings->get_setting('cloud_storage_enabled'),
		// The page's shape: a store is configured once a bucket, endpoint and key
		// are stored; it is locked while files are in either bucket or on their
		// way back, when only the key, the public URL and the private bucket may change.
		'configured'           => $settings->get_setting('cloud_storage_bucket') !== '' && $settings->get_setting('cloud_storage_endpoint') !== ''
		                          && $settings->get_setting('cloud_storage_access_key') !== '',
		'public_cloud'         => CloudStorageLifecycle::cloudRowCount('public'),
		'draining'             => (bool)$settings->get_setting('cloud_storage_draining') || (bool)$settings->get_setting('cloud_storage_private_draining'),
		'private_enabled'      => (bool)$settings->get_setting('cloud_storage_private_enabled'),
		'private_status'       => array(
			'configured' => trim((string)$settings->get_setting('cloud_storage_private_bucket')) !== '',
			'enabled'    => (bool)$settings->get_setting('cloud_storage_private_enabled'),
			'cloud_count'=> CloudStorageLifecycle::cloudRowCount('private'),
		),
		'locked'               => CloudStorageLifecycle::cloudRowCount('public') > 0 || CloudStorageLifecycle::cloudRowCount('private') > 0
		                          || (bool)$settings->get_setting('cloud_storage_draining') || (bool)$settings->get_setting('cloud_storage_private_draining'),
		'errors'               => $errors,
		'test_results'         => $test_results,
		'private_errors'       => $private_errors,
		'private_test_results' => $private_test_results,
		'health'               => CloudStorageLifecycle::health($profile),
		// The daily file-store check and who brings a missing file back.
		'inventory'            => CloudStoreInventory::current(),
		'objects_source'       => CloudStoreInventoryPanel::source(ManagementNodeStatus::is_managed()),
		'objects_status'       => BackupObjectsStatus::compute(),
		'manager_url'          => ManagementNodeStatus::manager_url(),
	);

	return LogicResult::render($page_data);
}
