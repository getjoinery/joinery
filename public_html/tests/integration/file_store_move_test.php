<?php
/** @joinery-test
 * name: file_store_move
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Move files — an older file store's files carried to the current one
 * (specs/storage_targets.md §7, WP6).
 *
 * Two file store target rows over the loopback S3 fixture, one bucket each.
 * Offloaded blobs record the older store and their keys; a move:
 *   - copies every object of a row to the new store under its folder, checks
 *     each copy against the bytes read (size, and the MD5 the provider reports,
 *     or the bytes read back when the ETag is not a plain MD5), and only then
 *     re-points the row and deletes the old copies;
 *   - leaves a row it cannot read, or whose copy does not check, on its old
 *     store with its old copy, and takes it on a later pass (resumable);
 *   - leaves the older store holding nothing, so it may be deleted.
 * A read follows the row: before the move from the old store, after it from
 * the new one.
 *
 * Run: php tests/integration/file_store_move_test.php
 *
 * @version 1.1 - two stores in one place are refused at save and by the move; a 32-hex ETag that is not the
 *                MD5 is checked by reading back; each table's row locks are its own (reviewer2, 10-08)
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/../lib/s3_fixtures.php');
require_once(__DIR__ . '/../lib/cloud_fixtures.php'); // InMemoryBlobDriver
harness_boot();

$fx = s3fx_start();
if ($fx === null) {
	harness_skip('Move files over the fixture', 'no loopback S3 fixture could start');
	harness_finish();
}
harness_defer(function () use ($fx) { s3fx_stop($fx); });

$db = DbConnector::get_instance()->get_db_link();
$run = bin2hex(random_bytes(4));

/** A file store target row on the fixture, cleaned up after the run. */
function move_store($fx, $name, $bucket, $prefix) {
	$t = new BackupTarget(NULL);
	$t->set('bkt_name', $name);
	$t->set('bkt_purpose', BackupTarget::PURPOSE_FILES);
	$t->set('bkt_provider', 'generic');
	$t->set('bkt_bucket', $bucket);
	$t->set('bkt_path_prefix', $prefix);
	$t->set('bkt_credentials', s3fx_creds($fx));
	$t->save();
	harness_register_row('bkt_backup_targets', 'bkt_backup_target_id', $t->key);
	return new BackupTarget($t->key, TRUE);
}

$old = move_store($fx, 'HarnessTest Old files ' . $run, 'old-files', 'site-old');
$new = move_store($fx, 'HarnessTest New files ' . $run, 'new-files', 'site-new');
CloudStorageDriverFactory::reset();
$old_driver = CloudStorageDriverFactory::forTarget((int)$old->key);
$to = new CloudFileStore((int)$new->key, $new->prefix(), CloudStorageDriverFactory::forTarget((int)$new->key));
$profile = new BlobStorageProfile();

/** An offloaded private blob on the old store, its bytes in the old bucket; one carries a variant. */
$blobs = array();
$make = function ($tag, $bytes, $variant = null) use ($db, $old, $old_driver, $run, &$blobs) {
	$name = 'mv' . $run . '_' . $tag . '.bin';
	$b = new FileBlob(NULL);
	$b->set('fbb_stored_name', $name);
	$b->set('fbb_size_bytes', strlen($bytes));
	$b->set('fbb_sha256', hash('sha256', $bytes));
	$b->set('fbb_mime_type', 'application/octet-stream');
	$b->set('fbb_is_private', true);
	$b->set('fbb_reference_count', 1);
	$b->set('fbb_storage_driver', 'cloud');
	$b->set('fbb_bkt_backup_target_id', (int)$old->key);
	$b->set('fbb_remote_key', 'site-old/' . $name);
	if ($variant !== null) {
		$b->set('fbb_encrypted_variant_key', 'thumbnail');
	}
	$b->save();
	harness_register_row('fbb_file_blobs', 'fbb_file_blob_id', $b->key);
	$tmp = tempnam(sys_get_temp_dir(), 'mv');
	file_put_contents($tmp, $bytes);
	$old_driver->put($tmp, 'site-old/' . $name, 'application/octet-stream');
	if ($variant !== null) {
		file_put_contents($tmp, $variant);
		$old_driver->put($tmp, 'site-old/thumbnail/' . $name, 'application/octet-stream');
	}
	@unlink($tmp);
	$blobs[$tag] = (int)$b->key;
	return new FileBlob($b->key, TRUE);
};
$where = function ($tag) use ($db, &$blobs) {
	$q = $db->prepare('SELECT fbb_bkt_backup_target_id AS target, fbb_remote_key AS key FROM fbb_file_blobs WHERE fbb_file_blob_id = ?');
	$q->execute(array($blobs[$tag]));
	return $q->fetch(PDO::FETCH_ASSOC);
};

$a = $make('a', 'alpha ' . random_bytes(2000), 'thumb of alpha');
$b = $make('b', 'bravo ' . random_bytes(500));
$c = $make('c', 'charlie ' . random_bytes(100));
// c's object was lost from the old bucket: the move cannot read it yet.
$old_driver->delete('site-old/' . $c->get('fbb_stored_name'));

section('A read follows the row');
ok('before the move, a blob reads from the old store', $a->read_bytes('original') === s3fx_object($fx, 'old-files', 'site-old/' . $a->get('fbb_stored_name')));
ok('the old store holds the three files whose records name it', CloudStorageLifecycle::cloudRowCount((int)$old->key) === 3);
ok('so its location is fixed and it is not deleted', $old->location_refusal() !== '' && $old->delete_refusal() !== '');

section('Two stores in one place are refused');
// A store in the old store's bucket and folder, or a folder inside it, holds
// the same objects; moving between them would delete each file it copied.
$twin = new BackupTarget(NULL);
$twin->set('bkt_name', 'HarnessTest Twin files ' . $run);
$twin->set('bkt_purpose', BackupTarget::PURPOSE_FILES);
$twin->set('bkt_provider', 'generic');
$twin->set('bkt_bucket', 'old-files');
$twin->set('bkt_path_prefix', 'site-old/inner');
$twin->set('bkt_credentials', s3fx_creds($fx));
ok('a folder inside another store\'s folder, in its bucket, is that store\'s place', $twin->overlaps($old) && strpos($twin->file_store_overlap(), $old->get('bkt_name')) !== false, $twin->file_store_overlap());
$twin->set('bkt_path_prefix', 'site-old');
ok('so is the same folder', $twin->overlaps($old));
$twin->set('bkt_path_prefix', 'site-old-2');
ok('a folder beside it is not', !$twin->overlaps($old));
$twin->set('bkt_bucket', 'new-files');
$twin->set('bkt_path_prefix', 'site-old');
ok('nor the same folder in another bucket', !$twin->overlaps($old));
$saved = CloudStorageLifecycle::saveStore(new BackupTarget(NULL), array('bkt_name' => 'HarnessTest Twin ' . $run, 'bkt_provider' => 'generic',
	'bkt_bucket' => 'old-files', 'bkt_path_prefix' => 'site-old', 'access_key' => 'TESTKEY', 'secret_key' => 'TESTSECRET',
	'region' => 'us-east-005', 'endpoint' => s3fx_creds($fx)['endpoint']), false);
ok('the page\'s save refuses a store in another store\'s place, before any check', $saved['ok'] === false && $saved['test_results'] === null
	&& strpos($saved['message'], 'already uses the bucket') !== false, $saved['message']);
$twin->set('bkt_bucket', 'old-files');
$twin->save();
harness_register_row('bkt_backup_targets', 'bkt_backup_target_id', $twin->key);
$r = CloudOffloadEngine::moveBatch($profile, (int)$old->key, new CloudFileStore((int)$twin->key, 'site-old', $old_driver));
ok('a move between two stores in one place moves and deletes nothing', $r['status'] === 'error' && $r['moved'] === 0
	&& s3fx_object($fx, 'old-files', 'site-old/' . $a->get('fbb_stored_name')) !== null, json_encode($r));

section('Move files: copy, check, re-point, then delete the old copy');
$r = CloudOffloadEngine::moveBatch($profile, (int)$old->key, $to);
ok('two rows moved, the unreadable one failed and is left for a later pass', $r['moved'] === 2 && $r['failed'] === 1 && $r['remaining'] === 1, json_encode($r));
$wa = $where('a');
ok('a moved row names the new store and the key under its folder', (int)$wa['target'] === (int)$new->key && $wa['key'] === 'site-new/' . $a->get('fbb_stored_name'), json_encode($wa));
ok('its bytes are in the new bucket, its variant beside them',
	s3fx_object($fx, 'new-files', 'site-new/' . $a->get('fbb_stored_name')) !== null
	&& s3fx_object($fx, 'new-files', 'site-new/thumbnail/' . $a->get('fbb_stored_name')) === 'thumb of alpha');
ok('and the old copies, variant included, are gone from the old bucket',
	s3fx_object($fx, 'old-files', 'site-old/' . $a->get('fbb_stored_name')) === null
	&& s3fx_object($fx, 'old-files', 'site-old/thumbnail/' . $a->get('fbb_stored_name')) === null);
$a2 = new FileBlob($blobs['a'], TRUE);
ok('after the move, the blob reads from the new store, the same bytes', $a2->read_bytes('original') !== null
	&& hash('sha256', $a2->read_bytes('original')) === $a2->get('fbb_sha256'));
ok('the row that could not be read still names the old store', (int)$where('c')['target'] === (int)$old->key);

section('A copy that does not check moves nothing');
// The old bucket has c again; the new store mangles what it is given.
$tmp = tempnam(sys_get_temp_dir(), 'mv');
file_put_contents($tmp, 'charlie restored');
$old_driver->put($tmp, 'site-old/' . $c->get('fbb_stored_name'), 'application/octet-stream');
@unlink($tmp);
$mangler = new class extends InMemoryBlobDriver {
	public function put(string $local_path, string $remote_key, string $content_type): void {
		$this->objects[$remote_key] = strrev((string)file_get_contents($local_path));
	}
};
$r = CloudOffloadEngine::moveBatch($profile, (int)$old->key, new CloudFileStore((int)$new->key, 'site-new', $mangler));
ok('a copy whose MD5 is not the original\'s is refused', $r['moved'] === 0 && $r['failed'] === 1 && strpos($r['message'], 'does not hash to the original') !== false, json_encode($r));
ok('the row stays on the old store, its old copy kept', (int)$where('c')['target'] === (int)$old->key
	&& s3fx_object($fx, 'old-files', 'site-old/' . $c->get('fbb_stored_name')) === 'charlie restored');
ok('and the bad copy is not left in the new store', $mangler->objects === array());

// A provider whose ETag is not a plain MD5 (a multipart upload, an encrypted
// bucket): the copy is read back and compared.
$multipart = new class extends InMemoryBlobDriver {
	public function head(string $remote_key): ?array {
		$h = parent::head($remote_key);
		return $h === null ? null : array('size' => $h['size'], 'etag' => 'abc123-2');
	}
};
$same_size_wrong = new class extends InMemoryBlobDriver {
	public function put(string $local_path, string $remote_key, string $content_type): void {
		$this->objects[$remote_key] = strrev((string)file_get_contents($local_path));
	}
	public function head(string $remote_key): ?array {
		$h = parent::head($remote_key);
		return $h === null ? null : array('size' => $h['size'], 'etag' => 'abc123-2');
	}
};
$r = CloudOffloadEngine::moveBatch($profile, (int)$old->key, new CloudFileStore((int)$new->key, 'site-new', $same_size_wrong));
ok('with no MD5 to compare, a copy of the right size but other bytes is read back and refused', $r['failed'] === 1 && (int)$where('c')['target'] === (int)$old->key, json_encode($r));
// A bucket encrypted with its own keys reports a 32-hex ETag that is not
// the MD5: a true copy is read back and accepted, not refused forever.
$kms = new class extends InMemoryBlobDriver {
	public function head(string $remote_key): ?array {
		$h = parent::head($remote_key);
		return $h === null ? null : array('size' => $h['size'], 'etag' => str_repeat('ab', 16));
	}
};
$check = new ReflectionMethod('CloudOffloadEngine', '_check_copy');
$probe = tempnam(sys_get_temp_dir(), 'mv');
file_put_contents($probe, 'kms bytes');
$kms->put($probe, 'k/1', 'application/octet-stream');
$threw = '';
try { $check->invoke(null, $kms, 'k/1', $probe, $probe . '.rb'); } catch (RuntimeException $e) { $threw = $e->getMessage(); }
ok('an ETag shaped like an MD5 that is not the bytes\' MD5 is checked by reading back, and a true copy passes', $threw === '', $threw);
@unlink($probe);
$r = CloudOffloadEngine::moveBatch($profile, (int)$old->key, new CloudFileStore((int)$new->key, 'site-new', $multipart));
ok('with no MD5 to compare, a true copy is read back and accepted', $r['moved'] === 1 && $r['remaining'] === 0, json_encode($r));
ok('the row names the new store', (int)$where('c')['target'] === (int)$new->key && $multipart->objects['site-new/' . $c->get('fbb_stored_name')] === 'charlie restored');

section('Resumable to the end; the emptied store may go');
ok('the old store holds nothing now', CloudStorageLifecycle::cloudRowCount((int)$old->key) === 0);
$old = new BackupTarget($old->key, TRUE);
ok('its location is free and, not being the file store, it may be deleted', $old->location_refusal() === '' && $old->delete_refusal() === '');
$r = CloudOffloadEngine::moveBatch($profile, (int)$old->key, $to);
ok('another pass finds nothing to move', $r['moved'] === 0 && $r['failed'] === 0 && $r['remaining'] === 0, json_encode($r));
$r = CloudOffloadEngine::moveBatch($profile, (int)$new->key, $to);
ok('a store is never moved onto itself', $r['status'] === 'skipped');

section('Each table\'s rows lock in a space of their own');
ok('blob 7 and message 7 are two locks', CloudOffloadEngine::lockSpace('fbb_file_blobs') === CloudOffloadEngine::ADVISORY_LOCK_NAMESPACE
	&& CloudOffloadEngine::lockSpace('iem_inbound_email_messages') !== CloudOffloadEngine::ADVISORY_LOCK_NAMESPACE
	&& CloudOffloadEngine::lockSpace('iem_inbound_email_messages') === CloudOffloadEngine::lockSpace('iem_inbound_email_messages'));

section('Starting a move is refused when there is nowhere to move to');
harness_set_setting_mem(BackupTarget::FILE_STORE_SETTING, (string)(int)$new->key);
ok('the current store cannot be moved onto itself', CloudStorageLifecycle::startMove((int)$new->key) !== '');
ok('a store holding nothing has nothing to move', strpos(CloudStorageLifecycle::startMove((int)$old->key), 'holds no files') !== false);

CloudStorageDriverFactory::reset();
harness_finish();
