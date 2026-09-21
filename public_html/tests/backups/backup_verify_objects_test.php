<?php
/** @joinery-test
 * name: backup_verify_objects
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 300
 */
/**
 * Verifying a backup's offloaded files (specs/backup_offloaded_files.md
 * § Verification), against a real run on the local-provider fixture:
 *
 *   - level 2 stages the epoch envelope the run's index names and opens it
 *     with the site key; the stored objects it covers are counted in
 *     VERIFY_OBJECTS / VERIFY_OBJECT_BYTES; no object is fetched
 *   - a missing envelope link, an envelope backup storage no longer holds, and an
 *     envelope minted for another epoch each fail by name
 *   - level 3 brings back the sample the launcher picks from the same index,
 *     checks each against the index's hash, decrypts it with the epoch key
 *     and compares it to the rehearsed database's row — fbb_sha256 where the
 *     row records one, fbb_size_bytes otherwise — and counts them in
 *     VERIFY_OBJECTS_SAMPLED
 *   - a sampled object whose bytes disagree with the index is refused before
 *     decryption; a row that disagrees with the plaintext fails by name
 *   - the operator's shell entry (no site key) proves the archives and
 *     reports the offloaded files unproven
 *
 * Run: php tests/backups/backup_verify_objects_test.php
 *
 * @version 1.1 - one file store: an object's visibility reads private
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../lib/s3_fixtures.php');
require_once(__DIR__ . '/../lib/cloud_fixtures.php');

require_once(PathHelper::getIncludePath('includes/BackupRunner.php'));
require_once(PathHelper::getIncludePath('includes/BackupObjects.php'));
require_once(PathHelper::getIncludePath('includes/BackupEnvelope.php'));
require_once(PathHelper::getIncludePath('includes/BackupChain.php'));
require_once(PathHelper::getIncludePath('includes/BackupStaging.php'));
require_once(PathHelper::getIncludePath('includes/BackupVerifier.php'));
require_once(PathHelper::getIncludePath('includes/BackupVerifyLauncher.php'));

$fx = s3fx_start();
if ($fx === null) {
	harness_skip('verify objects', 'could not start a local PHP HTTP server on 127.0.0.1');
	harness_finish();
}
harness_defer(function() use ($fx) { s3fx_stop($fx); });

// ── A throwaway site, its blob table, a scratch backup dir ──────────────────
$work = sys_get_temp_dir() . '/jy_verify_objects_' . getmypid();
$tree = $work . '/site';
$up   = $tree . '/static_files/uploads';
$out  = $work . '/backups';
@mkdir($tree . '/public_html', 0755, true);
@mkdir($up, 0755, true);
@mkdir($out, 0700, true);
file_put_contents($tree . '/public_html/index.php', '<?php echo "hello";');
file_put_contents($tree . '/public_html/kept.txt', 'a plain file that stays in the archive');
@mkdir($tree . '/config', 0755, true);
$dbname = 'jy_vobj_' . getmypid();
file_put_contents($tree . '/config/Globalvars_site.php', "<?php\n\$this->settings['deployment_environment'] = 'docker';\n"
	. "\$this->settings['dbusername'] = 'postgres';\n\$this->settings['dbname'] = '" . $dbname . "';\n");
harness_defer(function() use ($work) {
	exec('chmod -R u+rwX ' . escapeshellarg($work) . ' 2>/dev/null; rm -rf ' . escapeshellarg($work));
});

$password = (string)Globalvars::get_instance()->get_setting('dbpassword', true, true);
$pdo = DbConnector::get_instance()->get_db_link();
$pdo->exec('CREATE DATABASE "' . $dbname . '" TEMPLATE template0');
harness_defer(function() use ($pdo, $dbname) {
	$GLOBALS['scratch'] = null;   // the fixture's own connection, whatever path got here
	$pdo->exec('DROP DATABASE IF EXISTS "' . $dbname . '" WITH (FORCE)');
});
putenv('PGPASSWORD=' . $password);
$db = array('user' => 'postgres', 'password' => $password,
	'connect_db' => (string)Globalvars::get_instance()->get_setting('dbname', true, true), 'host' => 'localhost', 'port' => 5432);
$scratch = new PDO('pgsql:host=localhost dbname=' . $dbname, 'postgres', $password);
$scratch->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$scratch->exec('CREATE TABLE fbb_file_blobs (fbb_stored_name varchar(255) primary key, fbb_sha256 varchar(64), fbb_size_bytes int8 not null)');

// Three offloaded blobs with local bytes: one row records a hash, one only
// a size, one both. The enumerator describes them the way the storage
// profile would.
$blobs = array();
$plain = array('big.jpg' => random_bytes(60000), 'mid.bin' => random_bytes(9000), 'tiny.pdf' => 'tiny ' . random_bytes(300));
$rows  = array('big.jpg' => true, 'mid.bin' => false, 'tiny.pdf' => true);   // whether the row records a hash
$id = -9101;
foreach ($plain as $name => $bytes) {
	file_put_contents($up . '/' . $name, $bytes);
	$blobs[$name] = array('id' => $id--, 'name' => $name, 'original' => $up . '/' . $name, 'paths' => array($up . '/' . $name),
		'remote_key' => $name, 'content_type' => 'application/octet-stream', 'visibility' => 'private');
	$ins = $scratch->prepare('INSERT INTO fbb_file_blobs VALUES (?, ?, ?)');
	$ins->execute(array($name, $rows[$name] ? hash('sha256', $bytes) : null, strlen($bytes)));
	unset($ins);   // a statement left alive holds the connection, and a held connection blocks the DROP at the end
}
BackupObjects::$test_hooks = array('enumerator' => function () use (&$blobs) { return array_values($blobs); });
BackupProfile::$enabled_for_tests = array();
harness_defer(function () { BackupObjects::$test_hooks = array(); BackupProfile::$enabled_for_tests = null; BackupStaging::$fetch_for_tests = null; });

$slug = 'verify-objects-' . getmypid();
$plan = BackupRunner::plan(array('profile' => 'manager', 'manager' => array(
	'bucket' => 'bkt', 'credentials' => s3fx_creds($fx), 'slug' => $slug,
	'type' => 'project', 'mode' => 'chain', 'delete_local_after_upload' => 0, 'keep_local_days' => 7,
	'target_name' => 'local fixture',
)));
$plan['base_dir'] = $out; $plan['output_dir'] = $out . '/manager';
$plan['project'] = 'site'; $plan['project_dir'] = $tree; $plan['database'] = $dbname;
$plan['objects'] = true; $plan['objects_source'] = 'listing';
$base = 'joinery-backups/' . $slug . '/manager/';

$execute_chain = new ReflectionMethod('BackupRunner', 'execute_chain');
$execute_chain->setAccessible(true);
$run = function () use ($execute_chain, $plan) {
	$history = new BackupHistory(NULL);
	$history->set('bkh_type', 'project'); $history->set('bkh_outcome', 'running');
	$history->set('bkh_slug', $plan['slug']); $history->set('bkh_profile', 'manager');
	$history->set('bkh_recovery_fpr', $plan['recovery_fpr']); $history->set('bkh_encrypted', true);
	$history->set('bkh_target_name', 'local fixture');
	$history->save();
	harness_register_row('bkh_backup_history', 'bkh_backup_history_id', $history->key);
	try {
		return array($execute_chain->invoke(null, $plan, $history), null);
	} catch (\Throwable $e) {
		return array(null, $e->getMessage());
	}
};
$shelf = function ($key) use ($fx) { return s3fx_object($fx, 'bkt', '/' . $key); };

// The fetch the staging code makes, answered by the fixture shelf: a link is
// https://shelf.invalid/<key>, and what is not in backup storage is a 404.
$fetched = array();
BackupStaging::$fetch_for_tests = function ($url, $sink, $max) use ($shelf, &$fetched) {
	$key = substr((string)parse_url($url, PHP_URL_PATH), 1);
	$fetched[] = $key;
	$bytes = $shelf($key);
	if ($bytes === null) { return array('ok' => false, 'error' => 'HTTP 404 from storage (the response body is not repeated here)'); }
	if ($max > 0 && strlen($bytes) > $max) { return array('ok' => false, 'error' => 'refusing this download: larger than recorded'); }
	file_put_contents($sink, $bytes);
	return array('ok' => true, 'error' => '');
};
$sign = function ($relname) use ($base) { return 'https://shelf.invalid/' . $base . $relname . '?X-Amz-Signature=test'; };

// Stage run N of the chain into a fresh working directory the way the script
// does: manifest, the artifacts a restore needs, the chain key — then the
// objects material from the request's link maps.
$stage = function ($chain_id, $manifest, $seq, array $links) use ($work, $base, $shelf) {
	$dir = $work . '/verify-' . $seq . '-' . bin2hex(random_bytes(3));
	BackupStaging::prepare_workspace($dir);
	file_put_contents($dir . '/manifest.json', $shelf($base . $chain_id . '/manifest.json'));
	$plan_r = BackupStaging::plan($manifest, $seq);
	foreach (BackupStaging::wanted($plan_r) as $name) {
		file_put_contents($dir . '/' . $name, $shelf($base . $chain_id . '/' . $name));
	}
	$key_file = BackupStaging::write_chain_key($manifest, $dir);
	$index = BackupObjects::read_index_file($dir . '/' . $plan_r['objects']['name']);
	$objects = BackupStaging::fetch_objects($dir, $index, $links['epoch_envelope_urls'] ?? array(), $links['object_urls'] ?? array());
	return array($dir, $key_file, $objects, $index, $plan_r);
};

// ─────────────────────────────────────────────────────────────────────────────
section('A run stores the three objects; the launcher reads its index and signs the links');

list($result, $error) = $run();
check($error === null && ($result['status'] ?? '') === 'success', 'the run succeeds', (string)$error . ' ' . json_encode($result));
$dirs = glob($plan['output_dir'] . '/' . BackupChain::DIR_PREFIX . '*', GLOB_ONLYDIR);
$chain_id = basename($dirs[0]);
$manifest = BackupChain::decode($shelf($base . $chain_id . '/manifest.json'));
$index_bytes = $shelf($base . $chain_id . '/objects-0000.json.gz');
$index = BackupObjects::decode_index($index_bytes);
$epoch = $index['epochs'][0] ?? '';
$entries = BackupObjects::index_entries($index);
check(count($entries) === 3 && preg_match('/^epoch-\d{8}_\d{6}$/', $epoch), 'the index marks the three stored under one epoch', json_encode($index['epochs']));
$shelf_bytes = 0; foreach ($entries as $e) { $shelf_bytes += $e['object_bytes']; }

$links2 = BackupVerifyLauncher::object_links($index, 2, $sign);
check(array_keys($links2) === array('epoch_envelope_urls') && array_keys($links2['epoch_envelope_urls']) === array($epoch),
	'a level-2 request carries one envelope link per epoch the index names, and no objects', json_encode($links2));
check($links2['epoch_envelope_urls'][$epoch] === $sign('objects/' . $epoch . '/envelope.json'), 'the envelope link names objects/{epoch}/envelope.json');
$links3 = BackupVerifyLauncher::object_links($index, 3, $sign);
check(isset($links3['object_urls']) && count($links3['object_urls']) === 3 && array_keys($links3['object_urls'])[0] === 'big.jpg',
	'a level-3 request also carries the sample — every object of a small store, largest first', json_encode(array_keys($links3['object_urls'] ?? array())));
check($links3['object_urls']['mid.bin'] === $sign('objects/' . $epoch . '/mid.bin.enc'), 'each object link names objects/{epoch}/{name}.enc');

// ─────────────────────────────────────────────────────────────────────────────
section('Level 2: the epoch envelope opens with the site key; nothing per object is fetched');

$fetched = array();
list($dir, $key_file, $objects, $idx, $plan_r) = $stage($chain_id, $manifest, 0, $links2);
check($fetched === array($base . 'objects/' . $epoch . '/envelope.json'), 'staging fetched the envelope and nothing else', json_encode($fetched));
check(is_file($dir . '/objects/' . $epoch . '/envelope.json') && $objects['objects'] === array(), 'it landed under objects/{epoch}/ in the working directory');
$r = BackupVerifier::read_all($dir, $manifest, 0, $key_file, $objects);
check($r['result'] === 'pass', 'the run opens and reads', json_encode($r));
check($r['objects'] === 3 && $r['object_bytes'] === $shelf_bytes, 'VERIFY_OBJECTS counts the stored objects the opened envelope covers, with their shelf bytes',
	json_encode(array($r['objects'], $r['object_bytes'], $shelf_bytes)));
check(strpos(BackupVerifier::format_contract($r), 'VERIFY_OBJECTS=3') !== false && strpos(BackupVerifier::format_contract($r), 'VERIFY_OBJECT_BYTES=' . $shelf_bytes) !== false,
	'and the contract prints them');
check(strpos(BackupVerifier::describe($r), '3 offloaded files') !== false, 'the words say so', BackupVerifier::describe($r));

$r0 = BackupVerifier::read_all($dir, $manifest, 0, $key_file, null);
check($r0['result'] === 'pass' && $r0['objects'] === 0, 'with nothing staged (the shell entry) the archives pass and the offloaded files count as unproven');

// No link for the epoch: the request does not match the run.
$threw = '';
try { $stage($chain_id, $manifest, 0, array()); } catch (BackupStagingException $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'no download link was supplied for the envelope of ' . $epoch) !== false, 'a request with no envelope link for a named epoch is refused by name', $threw);

// The envelope is not in backup storage.
$gone_link = array('epoch_envelope_urls' => array($epoch => 'https://shelf.invalid/' . $base . 'objects/' . $epoch . '/nothing.json?X-Amz-Signature=test'));
$threw = '';
try { $stage($chain_id, $manifest, 0, $gone_link); } catch (BackupStagingException $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'could not bring back the envelope of ' . $epoch) !== false && strpos($threw, 'HTTP 404') !== false,
	'an envelope backup storage no longer holds fails as a 404, which the script words as gone', $threw);

// An envelope minted for something else, staged under this epoch's name.
list($dir_x, $key_x, $objects_x) = $stage($chain_id, $manifest, 0, $links2);
BackupEnvelope::write_sidecar($objects_x['envelopes'][$epoch], $manifest['envelope']);
$r = BackupVerifier::read_all($dir_x, $manifest, 0, $key_x, $objects_x);
check($r['result'] === 'fail' && strpos($r['reason'], 'was minted for') !== false && strpos($r['reason'], $epoch) !== false,
	'an envelope that is not the epoch\'s is refused, naming the epoch', $r['reason']);
// An envelope this machine's key does not open.
$foreign = BackupEnvelope::build(random_bytes(32), $epoch, array(array('kind' => 'recovery', 'pub' => sodium_crypto_box_publickey(sodium_crypto_box_keypair()))));
BackupEnvelope::write_sidecar($objects_x['envelopes'][$epoch], $foreign);
$r = BackupVerifier::read_all($dir_x, $manifest, 0, $key_x, $objects_x);
check($r['result'] === 'fail' && strpos($r['reason'], 'does not open with this machine') !== false,
	'an envelope sealed to another site fails: its objects cannot be recovered here', $r['reason']);
// A staged envelope removed from under the verify.
@unlink($objects_x['envelopes'][$epoch]);
$r = BackupVerifier::read_all($dir_x, $manifest, 0, $key_x, $objects_x);
check($r['result'] === 'fail' && strpos($r['reason'], 'gone: the envelope of ' . $epoch) === 0, 'a missing envelope is gone, by epoch', $r['reason']);

// ─────────────────────────────────────────────────────────────────────────────
section('Level 3: the sample is hashed, decrypted and compared to the rehearsed database');

$fetched = array();
list($dir, $key_file, $objects, $idx, $plan_r) = $stage($chain_id, $manifest, 0, $links3);
check(count($objects['objects']) === 3 && count($fetched) === 4, 'staging fetched the envelope and the three sampled objects', json_encode($fetched));
foreach ($objects['objects'] as $name => $path) {
	check(is_file($path) && strlen(file_get_contents($path)) === $entries[$name]['object_bytes'], 'the staged ' . $name . ' is the ciphertext the index records');
}
$before = (int)$pdo->query("SELECT count(*) FROM pg_database WHERE datname LIKE 'verify\\_%'")->fetchColumn();
$r = BackupVerifier::rehearse($dir, $manifest, 0, $key_file, $db, 'site', $objects);
check($r['result'] === 'pass', 'the rehearsal passes', json_encode($r));
check($r['objects'] === 3 && $r['objects_sampled'] === 3, 'three offloaded files proven, three of them opened and compared', json_encode(array($r['objects'], $r['objects_sampled'] ?? null)));
check(($r['tables'] ?? 0) >= 1 && isset($r['rows']['fbb_file_blobs']) && $r['rows']['fbb_file_blobs'] === 3, 'the rehearsed database came back with the blob rows', json_encode($r['rows'] ?? null));
check(!is_file($dir . '/' . BackupVerifier::SAMPLE_PLAIN) && !is_dir($dir . '/scratch'), 'no plaintext and no scratch tree are left behind');
check((int)$pdo->query("SELECT count(*) FROM pg_database WHERE datname LIKE 'verify\\_%'")->fetchColumn() === $before, 'and the throwaway database is gone');
$text = BackupVerifier::format_contract($r);
check(preg_match('/^VERIFY_OBJECTS_SAMPLED=3$/m', $text) === 1, 'VERIFY_OBJECTS_SAMPLED says how many were opened');
check(strpos(BackupVerifier::describe($r), '3 offloaded files') !== false && strpos(BackupVerifier::describe($r), '3 of them opened') !== false, 'in words too', BackupVerifier::describe($r));

// A sampled object whose bytes disagree with the index is refused before decryption.
list($dir_b, $key_b, $objects_b) = $stage($chain_id, $manifest, 0, $links3);
$bad = $objects_b['objects']['mid.bin'];
$bytes = file_get_contents($bad); $bytes[100] = chr(ord($bytes[100]) ^ 1); file_put_contents($bad, $bytes);
$r = BackupVerifier::rehearse($dir_b, $manifest, 0, $key_b, $db, 'site', $objects_b);
check($r['result'] === 'fail' && strpos($r['reason'], 'offloaded file mid.bin') === 0 && strpos($r['reason'], 'recorded hash') !== false,
	'a flipped byte in a sampled object fails on the index\'s hash, naming the file', $r['reason']);
check(!is_file($dir_b . '/' . BackupVerifier::SAMPLE_PLAIN), 'nothing decrypted was left');
// Staging refuses the same before it lands.
$threw = '';
try {
	$fx_dir = $work . '/tamper'; @mkdir($fx_dir, 0700, true);
	$tampered = $objects_b;
	$stage_bad = BackupStaging::$fetch_for_tests;
	BackupStaging::$fetch_for_tests = function ($url, $sink, $max) use ($stage_bad) {
		$r = $stage_bad($url, $sink, $max);
		if ($r['ok'] && substr($sink, -8) === '.bin.enc') { file_put_contents($sink, 'x', FILE_APPEND); }
		return $r;
	};
	BackupStaging::fetch_objects($fx_dir, $idx, $links3['epoch_envelope_urls'], array('mid.bin' => $links3['object_urls']['mid.bin']));
} catch (BackupStagingException $e) { $threw = $e->getMessage(); }
BackupStaging::$fetch_for_tests = $stage_bad;
check(strpos($threw, 'offloaded file mid.bin') === 0 && strpos($threw, 'bytes') !== false && !is_file($fx_dir . '/objects/' . $epoch . '/mid.bin.enc'),
	'staging refuses an object whose size disagrees with the index and keeps nothing', $threw);
// A sample naming something the index does not mark stored.
$threw = '';
try { BackupStaging::fetch_objects($fx_dir, $idx, $links3['epoch_envelope_urls'], array('other.bin' => $sign('objects/' . $epoch . '/other.bin.enc'))); }
catch (BackupStagingException $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'other.bin') !== false && $e->getCode() === BackupStagingException::MALFORMED, 'a sample naming what the index lacks is a malformed request', $threw);

// ─────────────────────────────────────────────────────────────────────────────
section('A row that disagrees with the plaintext fails by name');

// The database now says mid.bin (no hash on its row) is one byte; the next
// run's dump carries that, and the rehearsal of that run catches it.
$scratch->exec("UPDATE fbb_file_blobs SET fbb_size_bytes = 1 WHERE fbb_stored_name = 'mid.bin'");
list($result, $error) = $run();
check($error === null && ($result['status'] ?? '') === 'success', 'a second run succeeds', (string)$error);
$manifest1 = BackupChain::decode($shelf($base . $chain_id . '/manifest.json'));
check(count($manifest1['runs']) === 2, 'as run 1 of the same chain');
$index1 = BackupObjects::decode_index($shelf($base . $chain_id . '/objects-0001.json.gz'));
list($dir1, $key1, $objects1) = $stage($chain_id, $manifest1, 1, BackupVerifyLauncher::object_links($index1, 3, $sign));
$r = BackupVerifier::rehearse($dir1, $manifest1, 1, $key1, $db, 'site', $objects1);
check($r['result'] === 'fail' && strpos($r['reason'], 'offloaded file mid.bin decrypts to 9000 bytes where the rehearsed database records 1') === 0,
	'the size the row records is compared where it records no hash', $r['reason']);
check(!is_file($dir1 . '/' . BackupVerifier::SAMPLE_PLAIN), 'no plaintext is left after the failure');
$scratch->exec("UPDATE fbb_file_blobs SET fbb_size_bytes = 9000, fbb_sha256 = repeat('0', 64) WHERE fbb_stored_name = 'mid.bin'");
list($result, $error) = $run();
$manifest2 = BackupChain::decode($shelf($base . $chain_id . '/manifest.json'));
$index2 = BackupObjects::decode_index($shelf($base . $chain_id . '/objects-0002.json.gz'));
list($dir2, $key2, $objects2) = $stage($chain_id, $manifest2, 2, BackupVerifyLauncher::object_links($index2, 3, $sign));
$r = BackupVerifier::rehearse($dir2, $manifest2, 2, $key2, $db, 'site', $objects2);
check($r['result'] === 'fail' && strpos($r['reason'], 'offloaded file mid.bin decrypts to bytes whose hash is not the one the rehearsed database records') === 0,
	'and the hash where it records one', $r['reason']);
$scratch = null;

harness_finish();
