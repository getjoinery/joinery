<?php
/**
 * The file store becomes a target row (specs/storage_targets.md WP6).
 *
 * The six cloud_storage_* binding settings (provider, endpoint, region, bucket,
 * access key, secret key) are written as one backup target row with
 * bkt_purpose 'files', its key sealed like every target's, and
 * file_store_target_id names it. Every offloaded file blob is stamped with the
 * store and the full key its original was written under: {folder}/{stored
 * name}, where the folder is the one the driver derived from site_template.
 * Nothing in the bucket moves. The six settings rows go once the row holds
 * them, read back and compared.
 *
 * Offloaded mail is stamped by the mailbox plugin's own migration, which runs
 * after its columns exist.
 *
 * Idempotent: the test gate skips it once the six rows are gone, and a second
 * run finds file_store_target_id set and stamps only rows still unstamped.
 */
function file_store_target_row() {
	$db = DbConnector::get_instance()->get_db_link();
	$names = array('cloud_storage_provider', 'cloud_storage_endpoint', 'cloud_storage_region',
		'cloud_storage_bucket', 'cloud_storage_access_key', 'cloud_storage_secret_key');
	$read = function ($name) use ($db) {
		$q = $db->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
		$q->execute(array($name));
		return trim((string)$q->fetchColumn());
	};

	$target_id = (int)$read(BackupTarget::FILE_STORE_SETTING);
	$bucket = $read('cloud_storage_bucket');
	if ($target_id === 0 && $bucket !== '') {
		$endpoint = StorageProvider::normalise_endpoint($read('cloud_storage_endpoint'));
		$creds = array(
			'access_key' => $read('cloud_storage_access_key'),
			'secret_key' => $read('cloud_storage_secret_key'),
			'region'     => $read('cloud_storage_region'),
			'endpoint'   => $endpoint,
		);
		$name = 'File store';
		$taken = $db->prepare('SELECT 1 FROM bkt_backup_targets WHERE lower(bkt_name) = lower(?) AND bkt_delete_time IS NULL');
		for ($n = 2; ; $n++) {
			$taken->execute(array($name));
			if (!$taken->fetchColumn()) { break; }
			$name = 'File store ' . $n;
		}
		$target = new BackupTarget(NULL);
		$target->set('bkt_name', $name);
		$target->set('bkt_purpose', BackupTarget::PURPOSE_FILES);
		$target->set('bkt_provider', StorageProvider::effective($read('cloud_storage_provider'), $endpoint));
		$target->set('bkt_bucket', $bucket);
		// The folder the driver composed every key under: site_template, as it
		// read then. Every row is stamped with it below, so changing the
		// setting later moves nothing.
		$target->set('bkt_path_prefix', CloudFileStore::default_prefix());
		$target->set('bkt_credentials', $creds);
		$target->set('bkt_enabled', true);
		$target->save();

		$check = new BackupTarget($target->key, TRUE);
		$stored = $check->get_credentials();
		if ((string)($stored['access_key'] ?? '') !== $creds['access_key'] || (string)($stored['secret_key'] ?? '') !== $creds['secret_key']) {
			echo "  The file store target row did not read back the key it was given; the settings are left in place.\n";
			return false;
		}
		Setting::put(BackupTarget::FILE_STORE_SETTING, (string)(int)$target->key);
		$target_id = (int)$target->key;
		echo "  The file store is target row " . $target_id . ' "' . $name . '" (' . $bucket . '/' . $check->prefix() . ").\n";
	}

	if ($target_id > 0) {
		$target = new BackupTarget($target_id, TRUE);
		$prefix = $target->prefix();
		$q = $db->prepare("UPDATE fbb_file_blobs SET fbb_bkt_backup_target_id = ?, fbb_remote_key = ? || '/' || fbb_stored_name
			WHERE fbb_storage_driver = 'cloud' AND fbb_bkt_backup_target_id IS NULL");
		$q->execute(array($target_id, $prefix));
		echo "  " . $q->rowCount() . " offloaded file blob(s) record the file store and their key, " . $prefix . "/{stored name}.\n";
		echo "  A file offloaded under an earlier site name (site_template) is not at that key; the daily file-store check lists any such file as missing.\n";
	} else {
		$left = (int)$db->query("SELECT count(*) FROM fbb_file_blobs WHERE fbb_storage_driver = 'cloud' AND fbb_bkt_backup_target_id IS NULL")->fetchColumn();
		if ($left > 0) {
			echo "  WARNING: " . $left . " blob(s) are marked offloaded but no file store is configured, so nothing says where they are.\n";
			error_log('file_store_target_row: ' . $left . ' cloud blob(s) with no file store binding to stamp them with');
		}
	}

	$in = implode(',', array_fill(0, count($names), '?'));
	$del = $db->prepare("DELETE FROM stg_settings WHERE stg_name IN ($in)");
	$del->execute($names);
	echo "  The file store's binding settings are retired (" . $del->rowCount() . " row(s)).\n";
	return true;
}
