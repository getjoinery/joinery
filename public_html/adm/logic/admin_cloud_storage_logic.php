<?php
/**
 * Cloud Storage Admin Logic
 *
 * Thin caller over the shared CloudStorageLifecycle. The page manages the one
 * file store: a private bucket that holds private uploads, Drive files and
 * inbound mail. Save = check + persist + activate; the check stores nothing
 * on a fail. Pause, "Disable and Pull Files Back to Local" and Remove act on
 * the store. Offload itself runs through one platform task (CloudOffloadRun):
 * enabling the store sets it to offload mode and ensures that task is active;
 * the tick drives every profile from the registry, so the admin never names a
 * profile or a per-store task.
 *
 * @version 3.0.1 - Retry clears the recorded reason with the count
 * @version 3.0 - one private store (specs/cloud_storage_private_only.md): one Save, one binding, one
 *                pull-back; the private-store fields and disable_and_pull_private are gone
 * @version 2.6 - the provider picker: StorageProvider::complete() settles the endpoint and region a
 *                provider decides (Backblaze from the key) before the check runs; remove resets it
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
	$profile  = new BlobStorageProfile();   // the file-blob profile, whose figures the page shows

	$test_results = null;
	$errors = array();

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
			// The secret key is a password field, so it never carries its stored
			// value into the page. A blank submission therefore means "keep the
			// stored key" — the check below needs a real key to run.
			$secret_key = trim($input['cloud_storage_secret_key'] ?? '');
			if ($secret_key === '') {
				$secret_key = (string)$settings->get_setting('cloud_storage_secret_key');
			}
			$opts = array(
				'provider'   => $posted('cloud_storage_provider'),
				'endpoint'   => $posted('cloud_storage_endpoint'),
				'region'     => $posted('cloud_storage_region'),
				'bucket'     => $posted('cloud_storage_bucket'),
				'access_key' => $posted('cloud_storage_access_key'),
				'secret_key' => $secret_key,
			);
			$saved = false;
			foreach (['bucket', 'access_key', 'secret_key'] as $field) {
				if ($opts[$field] === '') {
					$errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
				}
			}
			// The provider decides the endpoint and region it did not ask for:
			// Amazon, Wasabi, DigitalOcean and Linode from the region, Cloudflare
			// R2 a fixed region, Backblaze both from the key.
			if (empty($errors)) {
				$settled = StorageProvider::complete($opts);
				$opts = $settled['opts'];
				if (!$settled['ok']) {
					$errors[] = $settled['message'];
				}
			}
			if (empty($errors)) {
				$mutable = CloudStorageLifecycle::assertBindingMutable($opts);
				if (!$mutable['ok']) {
					$errors[] = $mutable['message'];
				} else {
					$test_results = CloudStorageLifecycle::testConnection($opts);
					if ($test_results['ok']) {
						$persist = CloudStorageLifecycle::persistSettings($opts, $session);
						if ($persist['ok']) {
							CloudStorageLifecycle::stopDrain($session); // enabling cancels any in-progress drain
							CloudStorageLifecycle::ensureTickActive();
							$saved = true;
						} else {
							$errors[] = $persist['message'];
						}
					}
				}
			}

			// Redirect only when nothing needs inline diagnostics.
			if (empty($errors) && $saved) {
				$session->save_message(new DisplayMessage(
					'Cloud storage enabled. Private files start moving to the bucket on the next cron tick.',
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
			if (CloudStorageLifecycle::cloudRowCount() > 0 || $settings->get_setting('cloud_storage_draining')) {
				$session->save_message(new DisplayMessage(
					'Files are still in the bucket, or on their way back. Disable and pull them back first; remove once the count is zero.',
					'Not removed', '/\/admin\/admin_cloud_storage/',
					DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
				return LogicResult::redirect('/admin/admin_cloud_storage');
			}
			CloudStorageLifecycle::setEnabled(false, $session, array(
				'cloud_storage_provider' => StorageProvider::GENERIC,
				'cloud_storage_endpoint' => '', 'cloud_storage_region' => '', 'cloud_storage_bucket' => '',
				'cloud_storage_access_key' => '', 'cloud_storage_secret_key' => '',
			));
			CloudStorageLifecycle::stopDrain($session);
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
			CloudStorageLifecycle::setEnabled(false, $session);
			CloudStorageLifecycle::stopDrain($session);
			$session->save_message(new DisplayMessage(
				'Cloud storage paused. Files already in the bucket keep being served from it.',
				'Paused', '/\/admin\/admin_cloud_storage/',
				DisplayMessage::MESSAGE_ANNOUNCEMENT,
				DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
			return LogicResult::redirect('/admin/admin_cloud_storage');
		}
		elseif ($action === 'disable_and_pull') {
			// Disable the latch and set the draining flag; the offload tick pulls
			// every cloud file back to local until none remain, then clears the
			// flag itself.
			CloudStorageLifecycle::setEnabled(false, $session);
			CloudStorageLifecycle::startDrain($session);
			$session->save_message(new DisplayMessage(
				'Pull-back started. Bucket-stored files will be returned to local disk over the next several cron ticks.',
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
			$q = $dblink->prepare("UPDATE fbb_file_blobs SET fbb_sync_failed_count = 0, fbb_sync_last_error = NULL WHERE fbb_file_blob_id = ?");
			$q->execute([(int)$input['fbb_file_blob_id']]);
			return LogicResult::redirect('/admin/admin_cloud_storage');
		}
	}

	// On a failed save, repopulate from POST so the admin doesn't lose input.
	$pick = function($key) use ($input, $settings) {
		if (isset($input[$key])) return $input[$key];
		return $settings->get_setting($key);
	};

	$cloud_count = CloudStorageLifecycle::cloudRowCount();
	$page_data = array(
		'session'         => $session,
		'settings_values' => array(
			// A store saved before the picker existed shows as the provider its
			// endpoint belongs to.
			'provider'        => isset($input['cloud_storage_provider'])
				? StorageProvider::normalise($input['cloud_storage_provider'])
				: StorageProvider::effective($settings->get_setting('cloud_storage_provider'), $settings->get_setting('cloud_storage_endpoint')),
			'endpoint'        => $pick('cloud_storage_endpoint'),
			'region'          => $pick('cloud_storage_region'),
			'bucket'          => $pick('cloud_storage_bucket'),
			'access_key'      => $pick('cloud_storage_access_key'),
			'secret_key'      => $pick('cloud_storage_secret_key'),
		),
		'enabled'              => (bool)$settings->get_setting('cloud_storage_enabled'),
		// The page's shape: the store is configured once a bucket, endpoint and
		// key are stored; it is locked while files are in the bucket or on their
		// way back, when only the key may change.
		'configured'           => $settings->get_setting('cloud_storage_bucket') !== '' && $settings->get_setting('cloud_storage_endpoint') !== ''
		                          && $settings->get_setting('cloud_storage_access_key') !== '',
		'cloud_count'          => $cloud_count,
		'draining'             => (bool)$settings->get_setting('cloud_storage_draining'),
		'locked'               => $cloud_count > 0 || (bool)$settings->get_setting('cloud_storage_draining'),
		'errors'               => $errors,
		'test_results'         => $test_results,
		'health'               => CloudStorageLifecycle::health($profile),
		// The daily file-store check and who brings a missing file back.
		'inventory'            => CloudStoreInventory::current(),
		'objects_source'       => CloudStoreInventoryPanel::source(ManagementNodeStatus::is_managed()),
		'objects_status'       => BackupObjectsStatus::compute(),
		'manager_url'          => ManagementNodeStatus::manager_url(),
	);

	return LogicResult::render($page_data);
}
