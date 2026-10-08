<?php
/** @joinery-test
 * name: cloud_storage_guards
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Guards / offload-mode test.
 *
 * The file store is a target row (specs/storage_targets.md WP6). Its location
 * is fixed once a file is stored in it (R1, BackupTarget::location_refusal());
 * its key and name may change; it is not deleted while files are in it; a
 * backup target's form never saves one; lists of backup targets leave it out.
 * The factory: current() is where new offloads go, only with the latch on;
 * forTarget() is the store a row names, latch or not.
 *
 * Offload mode dispatch: one CloudOffloadRun tick drives every profile by the
 * store's MODE, derived from the enabled latch + draining flag. The store has
 * exactly one mode per tick (offload / drain / idle), so a row can never
 * ping-pong — forward/reverse mutual-exclusion is structural.
 *
 * Settings are overridden only in the Globalvars in-memory cache (this
 * process; never persisted), so no live settings or scheduled tasks are touched.
 *
 * Run: php tests/integration/cloud_storage_guards_test.php
 *
 * @version 4.0 - the file store is a target row: R1 on it, the backup form refuses it, lists keep the
 *                purposes apart, the factory's latch and per-row store; the binding guard is gone
 * @version 3.1 - replacing a key: the map it writes carries no latch and no drain flag, and a key
 *                naming another endpoint or bucket is refused
 * @version 3.0 - one store: one binding, one latch, one drain flag; the cloud row is a private blob
 * @version 2.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$dblink = DbConnector::get_instance()->get_db_link();
$cloud_fixture_id = null;
$suffix = bin2hex(random_bytes(4));

/** A file store target row, cleaned up after the run. */
function guard_store($name, $bucket) {
	$t = new BackupTarget(NULL);
	$t->set('bkt_name', $name);
	$t->set('bkt_purpose', BackupTarget::PURPOSE_FILES);
	$t->set('bkt_provider', 'generic');
	$t->set('bkt_bucket', $bucket);
	$t->set('bkt_path_prefix', 'guard-site');
	$t->set('bkt_credentials', array('access_key' => 'AK1', 'secret_key' => 'SK1', 'region' => 'r1', 'endpoint' => 'https://ep1.example.com'));
	$t->save();
	harness_register_row('bkt_backup_targets', 'bkt_backup_target_id', $t->key);
	return new BackupTarget($t->key, TRUE);
}

try {
	section('A file store is a target row; its location is fixed once a file is in it');

	$store = guard_store('HarnessTest Files ' . $suffix, 'guard-files-' . $suffix);
	ok('it is a file store', $store->is_file_store() && $store->prefix() === 'guard-site');
	ok('its key is sealed like every target\'s', strpos((string)$dblink->query('SELECT bkt_credentials::text FROM bkt_backup_targets WHERE bkt_backup_target_id = ' . (int)$store->key)->fetchColumn(), 'SK1') === false);
	ok('holding nothing, its location may change', $store->location_refusal() === '');
	ok('holding nothing, and not the file store, it may be deleted', $store->delete_refusal() === '');

	ok('a list of targets is the backup targets: the file store is not in it',
		!in_array((int)$store->key, array_map(function ($t) { return (int)$t->key; }, iterator_to_array(new MultiBackupTarget(array('deleted' => false)))), true));
	$named = array_values(array_filter(BucketCheck::file_store_buckets(), function ($b) use ($store) { return $b['bucket'] === $store->get('bkt_bucket'); }));
	ok('the bucket check names its bucket as a file store, by name', count($named) === 1 && $named[0]['label'] === 'the file store "' . $store->get('bkt_name') . '"', json_encode($named));
	ok('and not as a backup target\'s', !in_array($store->get('bkt_bucket'), array_column(BucketCheck::backup_target_buckets(), 'bucket'), true));
	ok('asked for file stores, it is',
		in_array((int)$store->key, array_map(function ($t) { return (int)$t->key; }, iterator_to_array(new MultiBackupTarget(array('deleted' => false, 'purpose' => BackupTarget::PURPOSE_FILES)))), true));
	$saved = BackupTargetForm::save(new BackupTarget($store->key, TRUE), array('bkt_name' => 'x', 'bkt_provider' => 'generic', 'bkt_bucket' => 'other'));
	ok('a backup target\'s form refuses to save a file store', $saved['ok'] === false && strpos($saved['message'], 'Cloud Storage page') !== false, $saved['message']);

	// A cloud row in it: a private blob, the only kind that reaches the bucket.
	$b = new FileBlob(NULL);
	$b->set('fbb_stored_name', '_guardtest_' . bin2hex(random_bytes(5)) . '.bin');
	$b->set('fbb_size_bytes', 16);
	$b->set('fbb_mime_type', 'application/octet-stream');
	$b->set('fbb_is_private', true);
	$b->set('fbb_reference_count', 1);
	$b->set('fbb_storage_driver', 'cloud');
	$b->set('fbb_bkt_backup_target_id', (int)$store->key);
	$b->set('fbb_remote_key', 'guard-site/' . $b->get('fbb_stored_name'));
	$b->save();
	$cloud_fixture_id = $b->key;

	ok('the store counts the file recorded in it', CloudStorageLifecycle::cloudRowCount((int)$store->key) === 1);
	$store = new BackupTarget($store->key, TRUE);
	ok('with a file in it, its location is fixed, and the refusal says why', strpos($store->location_refusal(), '1 offloaded file is stored in it') !== false, $store->location_refusal());
	ok('and it is not deleted', strpos($store->delete_refusal(), 'still stored in it') !== false, $store->delete_refusal());

	$moved = new BackupTarget($store->key, TRUE);
	$applied = BackupTargetForm::apply($moved, array('bkt_provider' => 'generic', 'bkt_bucket' => 'somewhere-else', 'bkt_enabled' => '1',
		'access_key' => 'AK1', 'endpoint' => 'https://ep1.example.com', 'region' => 'r1'), array('files' => true));
	ok('a save that moves it to another bucket is refused', $applied['ok'] === false && strpos($applied['message'], 'cannot change') !== false, $applied['message']);
	$rotated = new BackupTarget($store->key, TRUE);
	$applied = BackupTargetForm::apply($rotated, array('bkt_provider' => 'generic', 'bkt_bucket' => $store->get('bkt_bucket'), 'bkt_enabled' => '1',
		'bkt_path_prefix' => 'guard-site', 'access_key' => 'AK2', 'secret_key' => 'SK2', 'endpoint' => 'https://ep1.example.com', 'region' => 'r1'), array('files' => true));
	ok('a new key for the same place may be stored', $applied['ok'] === true, $applied['message']);

	section('Which store a write goes to, and which a read follows');

	harness_set_setting_mem(BackupTarget::FILE_STORE_SETTING, (string)(int)$store->key);
	harness_set_setting_mem('cloud_storage_enabled', '0');
	CloudStorageDriverFactory::reset();
	ok('latch off ⇒ no store for new offloads', CloudStorageDriverFactory::current() === null);
	ok('latch off ⇒ the named store still answers, for its page', CloudStorageDriverFactory::currentUnlatched() !== null
		&& CloudStorageDriverFactory::currentUnlatched()->target_id === (int)$store->key);
	ok('latch off ⇒ a row in the store is still read from it', (new FileBlob($cloud_fixture_id, TRUE))->cloud_driver() !== null);
	harness_set_setting_mem('cloud_storage_enabled', '1');
	$cur = CloudStorageDriverFactory::current();
	ok('latch on ⇒ new offloads go to the named store, under its folder', $cur !== null && $cur->key('a.bin') === 'guard-site/a.bin');
	ok('a backup target is never a file store\'s driver', CloudStorageDriverFactory::forTarget(0) === null);

	section('Offload mode dispatch (mode)');

	// Enabled latch on ⇒ offload (takes precedence over any draining flag).
	harness_set_setting_mem('cloud_storage_enabled', '1');
	harness_set_setting_mem('cloud_storage_draining', '1');
	ok('enabled ⇒ offload (precedence over draining)', CloudStorageLifecycle::mode() === 'offload');
	harness_set_setting_mem(BackupTarget::FILE_STORE_SETTING, '0');
	ok('enabled with no file store named ⇒ not offload', CloudStorageLifecycle::mode() !== 'offload');
	harness_set_setting_mem(BackupTarget::FILE_STORE_SETTING, (string)(int)$store->key);

	// Disabled + draining ⇒ drain.
	harness_set_setting_mem('cloud_storage_enabled', '0');
	harness_set_setting_mem('cloud_storage_draining', '1');
	ok('disabled + draining ⇒ drain', CloudStorageLifecycle::mode() === 'drain');

	// Disabled + not draining ⇒ idle (paused: keep serving, do nothing).
	harness_set_setting_mem('cloud_storage_enabled', '0');
	harness_set_setting_mem('cloud_storage_draining', '0');
	ok('disabled + not draining ⇒ idle', CloudStorageLifecycle::mode() === 'idle');

	// With the store idle but a file still offloaded (a paused store), the
	// tick moves nothing, gives the daily file-store check its slice, and stays
	// active: a paused store serves the same files as an active one. No driver
	// answers here, so the check counts the row as one it cannot check.
	harness_set_setting_mem('cloud_storage_enabled', '0');
	harness_set_setting_mem('cloud_storage_draining', '0');
	CloudStoreInventory::$test_hooks['driver'] = function () { return null; };
	CloudStoreInventory::$test_hooks['record'] = array();
	$tick = CloudStorageLifecycle::runOffloadTick();
	ok('runOffloadTick: the store idle, a file offloaded ⇒ no deactivate signal', empty($tick['deactivate']));
	ok('runOffloadTick: status success when idle', ($tick['status'] ?? '') === 'success');
	ok('runOffloadTick: the daily check took its slice', strpos((string)$tick['message'], 'not checked (no driver for their file store') !== false
		|| strpos((string)$tick['message'], 'under the daily check') !== false);
	unset(CloudStoreInventory::$test_hooks['driver']);
	CloudStoreInventory::$test_hooks['record'] = array();

	// With the offloaded file gone as well, there is nothing to move and
	// nothing to check, and the tick asks to be switched off.
	$d = $dblink->prepare("DELETE FROM fbb_file_blobs WHERE fbb_file_blob_id = ?");
	$d->execute([$cloud_fixture_id]);
	$cloud_fixture_id = null;
	$tick = CloudStorageLifecycle::runOffloadTick();
	if (CloudStorageLifecycle::cloudRowCount() === 0) {
		ok('runOffloadTick: the store idle, nothing offloaded ⇒ deactivate signal', !empty($tick['deactivate']));
	} else {
		ok('runOffloadTick: this site has offloaded files of its own, so the tick stays active', empty($tick['deactivate']));
	}

} finally {
	if ($cloud_fixture_id) {
		$d = $dblink->prepare("DELETE FROM fbb_file_blobs WHERE fbb_file_blob_id = ?");
		$d->execute([$cloud_fixture_id]);
	}
}

harness_finish();
