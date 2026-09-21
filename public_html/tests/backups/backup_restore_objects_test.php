<?php
/** @joinery-test
 * name: backup_restore_objects
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 300
 */
/**
 * Bringing a backup's offloaded files home (specs/backup_offloaded_files.md
 * § Restore), against a real run on the local-provider fixture:
 *
 *   - the survey: missing mode wants only what the file bucket cannot serve,
 *     all mode wants every cloud row, the list is capped and says so, and the
 *     epochs it would draw from are counted
 *   - a downloaded tree brings the wanted files home: each checked against
 *     the index, decrypted with the epoch key, checked against its row, put
 *     in place, and its row set to local; a second run finds nothing to do
 *   - a file already on disk is adopted when it matches its row and refused
 *     by name when it does not — nothing is overwritten
 *   - a page of links does the same one object at a time, with the
 *     ciphertext gone after each; a flipped byte is refused before decryption
 *     and a row that disagrees with the plaintext is refused by name
 *   - an epoch with no envelope needs a recovered key file; with one it opens
 *   - the contract round-trips and reads in words; the script refuses what it
 *     cannot understand and reports a dry run
 *
 * Run: php tests/backups/backup_restore_objects_test.php
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
require_once(PathHelper::getIncludePath('includes/BackupObjectRestore.php'));
require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));

$fx = s3fx_start();
if ($fx === null) {
	harness_skip('restore objects', 'could not start a local PHP HTTP server on 127.0.0.1');
	harness_finish();
}
harness_defer(function() use ($fx) { s3fx_stop($fx); });

// ── A throwaway site tree, a scratch backup dir, a home for restored files ──
$work = sys_get_temp_dir() . '/jy_restore_objects_' . getmypid();
$tree = $work . '/site';
$up   = $tree . '/static_files/uploads';
$out  = $work . '/backups';
$home = $work . '/home';
@mkdir($tree . '/public_html', 0755, true);
@mkdir($up, 0755, true);
@mkdir($out, 0700, true);
@mkdir($home, 0755, true);
file_put_contents($tree . '/public_html/index.php', '<?php echo "hello";');
@mkdir($tree . '/config', 0755, true);
file_put_contents($tree . '/config/Globalvars_site.php', "<?php\n\$this->settings['deployment_environment'] = 'docker';\n");
harness_defer(function() use ($work) {
	exec('chmod -R u+rwX ' . escapeshellarg($work) . ' 2>/dev/null; rm -rf ' . escapeshellarg($work));
});

// Three offloaded blobs with real rows on this database: one row records a
// hash, one only a size, one both. Their names are unique to this run.
$pdo = DbConnector::get_instance()->get_db_link();
$tag = 'jyro' . getmypid() . '_';
$plain = array($tag . 'big.jpg' => random_bytes(60000), $tag . 'mid.bin' => random_bytes(9000), $tag . 'tiny.pdf' => 'tiny ' . random_bytes(300));
$hashed = array($tag . 'big.jpg' => true, $tag . 'mid.bin' => false, $tag . 'tiny.pdf' => true);
$ids = array();
$blobs = array();
foreach ($plain as $name => $bytes) {
	file_put_contents($up . '/' . $name, $bytes);
	$blob = new FileBlob();
	$blob->set('fbb_stored_name', $name);
	$blob->set('fbb_size_bytes', strlen($bytes));
	$blob->set('fbb_sha256', $hashed[$name] ? hash('sha256', $bytes) : null);
	$blob->set('fbb_mime_type', 'application/octet-stream');
	$blob->set('fbb_is_private', false);
	$blob->set('fbb_storage_driver', 'cloud');
	$blob->save();
	$ids[$name] = (int)$blob->key;
	$blobs[$name] = array('id' => (int)$blob->key, 'name' => $name, 'original' => $up . '/' . $name, 'paths' => array($up . '/' . $name),
		'remote_key' => $name, 'content_type' => 'application/octet-stream', 'visibility' => 'public');
}
harness_defer(function () use ($pdo, $ids) {
	$q = $pdo->prepare('DELETE FROM fbb_file_blobs WHERE fbb_file_blob_id = ?');
	foreach ($ids as $id) { $q->execute(array($id)); }
});
$row = function ($name) use ($pdo, $ids) {
	$q = $pdo->prepare('SELECT fbb_storage_driver FROM fbb_file_blobs WHERE fbb_file_blob_id = ?');
	$q->execute(array($ids[$name]));
	return (string)$q->fetchColumn();
};
$set_cloud = function ($name) use ($pdo, $ids) {
	$q = $pdo->prepare("UPDATE fbb_file_blobs SET fbb_storage_driver = 'cloud' WHERE fbb_file_blob_id = ?");
	$q->execute(array($ids[$name]));
};

// The file bucket, as the survey sees it: it still serves big.jpg and nothing else.
$store = new InMemoryBlobDriver();
$store->objects[$tag . 'big.jpg'] = $plain[$tag . 'big.jpg'];
BackupObjectRestore::$test_hooks = array(
	'store'     => function ($visibility) use ($store) { return $store; },
	'placement' => function (FileBlob $b) use ($home) { return $home . '/' . $b->get('fbb_stored_name'); },
);
BackupObjects::$test_hooks = array('enumerator' => function () use (&$blobs) { return array_values($blobs); });
BackupProfile::$enabled_for_tests = array();
harness_defer(function () { BackupObjects::$test_hooks = array(); BackupObjectRestore::$test_hooks = array();
	BackupProfile::$enabled_for_tests = null; BackupStaging::$fetch_for_tests = null; });

$slug = 'restore-objects-' . getmypid();
$plan = BackupRunner::plan(array('profile' => 'manager', 'manager' => array(
	'bucket' => 'bkt', 'credentials' => s3fx_creds($fx), 'slug' => $slug,
	'type' => 'project', 'mode' => 'chain', 'delete_local_after_upload' => 0, 'keep_local_days' => 7,
	'target_name' => 'local fixture',
)));
$plan['base_dir'] = $out; $plan['output_dir'] = $out . '/manager';
$plan['project'] = 'site'; $plan['project_dir'] = $tree;
$plan['objects'] = true; $plan['objects_source'] = 'listing';
$base = 'joinery-backups/' . $slug . '/manager/';

$execute_chain = new ReflectionMethod('BackupRunner', 'execute_chain');
$execute_chain->setAccessible(true);
$history = new BackupHistory(NULL);
$history->set('bkh_type', 'project'); $history->set('bkh_outcome', 'running');
$history->set('bkh_slug', $plan['slug']); $history->set('bkh_profile', 'manager');
$history->set('bkh_recovery_fpr', $plan['recovery_fpr']); $history->set('bkh_encrypted', true);
$history->set('bkh_target_name', 'local fixture');
$history->save();
harness_register_row('bkh_backup_history', 'bkh_backup_history_id', $history->key);
$shelf = function ($key) use ($fx) { return s3fx_object($fx, 'bkt', '/' . $key); };
$shelf_file = function ($key) use ($fx) { return s3fx_object_file($fx['dir'], 'bkt', '/' . $key); };

// ─────────────────────────────────────────────────────────────────────────────
section('A run stores the three objects; the survey says what would come home');

try { $result = $execute_chain->invoke(null, $plan, $history); $error = null; } catch (\Throwable $e) { $result = null; $error = $e->getMessage(); }
check($error === null && ($result['status'] ?? '') === 'success', 'the run succeeds', (string)$error . ' ' . json_encode($result));
$dirs = glob($plan['output_dir'] . '/' . BackupChain::DIR_PREFIX . '*', GLOB_ONLYDIR);
$chain_id = basename($dirs[0]);
$index_path = $work . '/objects-0000.json.gz';
file_put_contents($index_path, $shelf($base . $chain_id . '/objects-0000.json.gz'));
$index = BackupObjects::read_index_file($index_path);
$epoch = $index['epochs'][0] ?? '';
$entries = BackupObjects::index_entries($index);
check(count($entries) === 3 && preg_match('/^epoch-\d{8}_\d{6}$/', $epoch), 'the index marks the three stored under one epoch', json_encode($index['epochs']));
foreach ($plain as $name => $bytes) {
	check(!is_file($up . '/' . $name), $name . ' has left the disk (no profile holds it back)');
}

$s = BackupObjectRestore::survey($index, 'missing');
check($s['want'] === array($tag . 'mid.bin', $tag . 'tiny.pdf') && $s['served'] === 1 && $s['wanted'] === 2 && $s['indexed'] === 3 && !$s['more'],
	'missing mode wants the two the file bucket cannot serve', json_encode($s));
check($s['epochs'] === array($epoch => 2), 'and counts them by epoch', json_encode($s['epochs']));
$s = BackupObjectRestore::survey($index, 'all');
check(count($s['want']) === 3 && $s['served'] === 0 && $s['wanted'] === 3, 'all mode wants every cloud row, served or not', json_encode($s));
$s = BackupObjectRestore::survey($index, 'all', 1);
check($s['want'] === array($tag . 'big.jpg') && $s['more'] === true && $s['wanted'] === 3, 'a capped survey names the first and says there is more', json_encode($s));
$s = BackupObjectRestore::survey($index, 'all', 1000, strlen($tag . 'big.jpg') + 2);
check($s['want'] === array($tag . 'big.jpg') && $s['more'] === true && $s['wanted'] === 3,
	'the list is capped by bytes too: a name that would not fit the line stops it, and there is more', json_encode($s['want']));
check(BackupObjectRestore::SURVEY_MAX_BYTES <= 65536 / 2, 'the byte cap is at most half the agent\'s 64 KiB of output, leaving room for the rest of the answer');
$store->objects[$tag . 'big.jpg'] = 'short';
$s = BackupObjectRestore::survey($index, 'missing');
check(count($s['want']) === 3, 'an object the file bucket holds at the wrong size is not served', json_encode($s['want']));
$store->objects[$tag . 'big.jpg'] = $plain[$tag . 'big.jpg'];
$index_with_gap = $index;
$index_with_gap['objects'][] = array('name' => $tag . 'never.bin', 'epoch' => '', 'object_bytes' => 0, 'object_sha256' => '', 'stored' => false);
$s = BackupObjectRestore::survey($index_with_gap, 'missing');
check($s['not_stored'] === 1 && $s['indexed'] === 3, 'an entry the index says never reached backup storage is counted, not wanted', json_encode($s));
$s = BackupObjectRestore::survey(array('objects' => array(array('name' => 'nobody_' . $tag, 'epoch' => $epoch, 'object_bytes' => 1, 'object_sha256' => 'x', 'stored' => true))), 'all');
check($s['no_row'] === 1 && $s['wanted'] === 0, 'a name the restored database has no row for is skipped', json_encode($s));

// ─────────────────────────────────────────────────────────────────────────────
section('A downloaded tree brings the wanted files home');

// The tree an operator downloads with the site's own credential: DIR/{epoch}/…
$tree_dir = $work . '/downloaded';
@mkdir($tree_dir . '/' . $epoch, 0700, true);
copy($shelf_file($base . 'objects/' . $epoch . '/envelope.json'), $tree_dir . '/' . $epoch . '/envelope.json');
foreach ($entries as $name => $e) {
	copy($shelf_file($base . 'objects/' . $epoch . '/' . $name . '.enc'), $tree_dir . '/' . $epoch . '/' . $name . '.enc');
}
$env_path = function ($ep) use ($tree_dir) { return $tree_dir . '/' . $ep . '/envelope.json'; };

$keys = BackupObjectRestore::epoch_keys($index, $env_path);
check(array_keys($keys) === array($epoch) && strlen($keys[$epoch]) > 20, 'the epoch envelope opens with the site key');
$log = array();
$progress = function ($what, $name, $detail = '') use (&$log) { $log[] = $what . ' ' . $name; };
$survey = BackupObjectRestore::survey($index, 'missing');
$r = BackupObjectRestore::restore($index, $survey['want'], 'missing', $keys, BackupObjectRestore::tree_source($tree_dir), $progress);
check($r['restored'] === 2 && $r['bytes'] === 9000 + 305 && $r['kept'] === 0 && $r['skipped'] === 0, 'two files brought home, their plaintext bytes counted', json_encode($r));
check(is_file($home . '/' . $tag . 'mid.bin') && file_get_contents($home . '/' . $tag . 'mid.bin') === $plain[$tag . 'mid.bin']
	&& file_get_contents($home . '/' . $tag . 'tiny.pdf') === $plain[$tag . 'tiny.pdf'], 'each landed at its placement with the original bytes');
check(!is_file($home . '/' . $tag . 'big.jpg'), 'the one the file bucket serves was not fetched');
check($row($tag . 'mid.bin') === 'local' && $row($tag . 'tiny.pdf') === 'local' && $row($tag . 'big.jpg') === 'cloud', 'their rows say local; the served one still says cloud');
check(count(glob($home . '/.restore-*')) === 0, 'no part file is left beside them');
check($log === array('restored ' . $tag . 'mid.bin', 'restored ' . $tag . 'tiny.pdf'), 'progress named each', json_encode($log));

$s = BackupObjectRestore::survey($index, 'missing');
check($s['wanted'] === 0 && $s['local'] === 2 && $s['served'] === 1, 'a second survey finds nothing to do', json_encode($s));
$r = BackupObjectRestore::restore($index, array_keys($entries), 'all', $keys, BackupObjectRestore::tree_source($tree_dir));
check($r['restored'] === 1 && $r['skipped'] === 2 && $row($tag . 'big.jpg') === 'local', 'all mode brings the served one home too and skips the two already local', json_encode($r));

// ─────────────────────────────────────────────────────────────────────────────
section('A file already on disk is adopted when it matches, refused when it does not');

$set_cloud($tag . 'tiny.pdf');
$s = BackupObjectRestore::survey($index, 'missing');
check($s['want'] === array($tag . 'tiny.pdf'), 'a cloud row whose file is on disk is wanted (nothing to fetch, a record to fix)', json_encode($s['want']));
$log = array();
$r = BackupObjectRestore::restore($index, array($tag . 'tiny.pdf'), 'missing', $keys, function () { throw new Exception('fetched'); }, $progress);
check($r['kept'] === 1 && $r['restored'] === 0 && $row($tag . 'tiny.pdf') === 'local' && $log === array('kept ' . $tag . 'tiny.pdf'), 'it is adopted without a fetch', json_encode($r));

$set_cloud($tag . 'mid.bin');
file_put_contents($home . '/' . $tag . 'mid.bin', 'not the file');
$threw = '';
try { BackupObjectRestore::restore($index, array($tag . 'mid.bin'), 'missing', $keys, BackupObjectRestore::tree_source($tree_dir)); }
catch (BackupObjectRestoreException $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'offloaded file ' . $tag . 'mid.bin') === 0 && strpos($threw, 'nothing is overwritten') !== false
	&& file_get_contents($home . '/' . $tag . 'mid.bin') === 'not the file' && $row($tag . 'mid.bin') === 'cloud',
	'a file on disk that is not this file is refused by name and left alone', $threw);
unlink($home . '/' . $tag . 'mid.bin');
$threw = '';
try { BackupObjectRestore::restore($index, array($tag . 'mid.bin', 'stranger.bin'), 'missing', $keys, BackupObjectRestore::tree_source($tree_dir)); }
catch (BackupObjectRestoreException $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'stranger.bin') !== false && $e->getCode() === BackupObjectRestoreException::MALFORMED && $row($tag . 'mid.bin') === 'cloud',
	'a page naming what the index does not mark stored is malformed, and nothing is done first', $threw);

// A served file with a copy on disk: in missing mode it is left as it is
// (the copy is the waiting state the offload tick handles); in all mode the
// copy is adopted.
$set_cloud($tag . 'big.jpg');
check(is_file($home . '/' . $tag . 'big.jpg') && $store->head($tag . 'big.jpg') !== null, 'big.jpg is on disk and the file bucket serves it');
$s = BackupObjectRestore::survey($index, 'missing');
check(!in_array($tag . 'big.jpg', $s['want'], true) && $s['served'] === 1 && $row($tag . 'big.jpg') === 'cloud',
	'missing mode leaves a served row alone even with a copy on disk', json_encode($s));
$r = BackupObjectRestore::restore($index, array($tag . 'big.jpg'), 'missing', $keys, function () { throw new Exception('fetched'); });
check($r['skipped'] === 1 && $r['kept'] === 0 && $row($tag . 'big.jpg') === 'cloud', 'and a page naming it skips it without a fetch', json_encode($r));
$r = BackupObjectRestore::restore($index, array($tag . 'big.jpg'), 'all', $keys, function () { throw new Exception('fetched'); });
check($r['kept'] === 1 && $row($tag . 'big.jpg') === 'local', 'all mode adopts the copy', json_encode($r));

// ─────────────────────────────────────────────────────────────────────────────
section('A page of links brings files home one at a time');

foreach (array_keys($plain) as $name) { $set_cloud($name); @unlink($home . '/' . $name); }
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
$links = array($tag . 'mid.bin' => $sign('objects/' . $epoch . '/' . $tag . 'mid.bin.enc'), $tag . 'tiny.pdf' => $sign('objects/' . $epoch . '/' . $tag . 'tiny.pdf.enc'));
$page_work = $work . '/page';
BackupStaging::prepare_workspace($page_work);

$page = BackupObjectRestore::subset($index, array_keys($links));
check(count($page['objects']) === 2, 'the page is the index narrowed to its names');
$envs = BackupStaging::fetch_envelopes($page_work, $page, array($epoch => $sign('objects/' . $epoch . '/envelope.json')));
$keys_l = BackupObjectRestore::epoch_keys($page, function ($ep) use ($envs) { return $envs['envelopes'][$ep] ?? ''; });
check($keys_l === $keys, 'the fetched envelope yields the same key');
$log = array();
$r = BackupObjectRestore::restore($index, array_keys($links), 'missing', $keys_l, BackupObjectRestore::link_source($page_work, $links, $progress), $progress);
check($r['restored'] === 2 && $row($tag . 'mid.bin') === 'local' && file_get_contents($home . '/' . $tag . 'tiny.pdf') === $plain[$tag . 'tiny.pdf'],
	'both came home by link', json_encode($r));
check(count(glob($page_work . '/objects/' . $epoch . '/*.enc')) === 0, 'no ciphertext is left in the working directory');
check($log === array('fetching objects/' . $epoch . '/' . $tag . 'mid.bin.enc', 'restored ' . $tag . 'mid.bin',
	'fetching objects/' . $epoch . '/' . $tag . 'tiny.pdf.enc', 'restored ' . $tag . 'tiny.pdf'), 'fetch and place alternate: one object on disk at a time', json_encode($log));
$threw = '';
try { BackupObjectRestore::restore($index, array($tag . 'big.jpg'), 'all', $keys_l, BackupObjectRestore::link_source($page_work, $links)); }
catch (BackupObjectRestoreException $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'no download link') !== false && strpos($threw, $tag . 'big.jpg') !== false, 'a wanted name with no link fails by name', $threw);

// A flipped byte in backup storage is refused before decryption.
$set_cloud($tag . 'mid.bin'); unlink($home . '/' . $tag . 'mid.bin');
$obj_file = $shelf_file($base . 'objects/' . $epoch . '/' . $tag . 'mid.bin.enc');
$good = file_get_contents($obj_file);
$bad = $good; $bad[100] = chr(ord($bad[100]) ^ 1); file_put_contents($obj_file, $bad);
$threw = '';
try { BackupObjectRestore::restore($index, array($tag . 'mid.bin'), 'missing', $keys_l, BackupObjectRestore::link_source($page_work, $links)); }
catch (BackupObjectRestoreException $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'offloaded file ' . $tag . 'mid.bin') === 0 && strpos($threw, 'recorded hash') !== false,
	'a flipped byte fails on the index\'s hash, naming the file', $threw);
check(!is_file($home . '/' . $tag . 'mid.bin') && $row($tag . 'mid.bin') === 'cloud' && count(glob($page_work . '/objects/' . $epoch . '/*.enc')) === 0,
	'nothing was placed, the row is untouched, the ciphertext is gone');
file_put_contents($obj_file, $good);

// A row that disagrees with the plaintext.
$pdo->prepare('UPDATE fbb_file_blobs SET fbb_size_bytes = 1 WHERE fbb_file_blob_id = ?')->execute(array($ids[$tag . 'mid.bin']));
$threw = '';
try { BackupObjectRestore::restore($index, array($tag . 'mid.bin'), 'missing', $keys_l, BackupObjectRestore::link_source($page_work, $links)); }
catch (BackupObjectRestoreException $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'decrypts to 9000 bytes where its record says 1') !== false && !is_file($home . '/' . $tag . 'mid.bin') && count(glob($home . '/.restore-*')) === 0,
	'a plaintext that disagrees with its row is refused by name and nothing is placed', $threw);
$pdo->prepare('UPDATE fbb_file_blobs SET fbb_size_bytes = 9000, fbb_sha256 = ? WHERE fbb_file_blob_id = ?')->execute(array(str_repeat('0', 64), $ids[$tag . 'mid.bin']));
$threw = '';
try { BackupObjectRestore::restore($index, array($tag . 'mid.bin'), 'missing', $keys_l, BackupObjectRestore::link_source($page_work, $links)); }
catch (BackupObjectRestoreException $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'hash is not the one its record holds') !== false && $row($tag . 'mid.bin') === 'cloud', 'and so is one whose hash disagrees', $threw);
$pdo->prepare('UPDATE fbb_file_blobs SET fbb_sha256 = NULL WHERE fbb_file_blob_id = ?')->execute(array($ids[$tag . 'mid.bin']));

// ─────────────────────────────────────────────────────────────────────────────
section('Epoch keys: an envelope that is not there needs a recovered key file');

$threw = '';
try { BackupObjectRestore::epoch_keys($index, function ($ep) { return '/nonexistent/envelope.json'; }); }
catch (BackupObjectRestoreException $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'gone: the envelope of ' . $epoch) === 0 && strpos($threw, '--epoch-key') !== false, 'a missing envelope is gone, by epoch, and says how to hand a key over', $threw);
$key_file = $work . '/epoch.key';
file_put_contents($key_file, $keys[$epoch] . "\n");
$k = BackupObjectRestore::epoch_keys($index, function ($ep) { return '/nonexistent/envelope.json'; }, array($epoch => $key_file));
check($k === $keys, 'a key file for the epoch stands in for its envelope');
$wrong = $tree_dir . '/wrong.json';
$env = BackupEnvelope::read_sidecar($env_path($epoch)); $env['artifact'] = 'epoch-20000101_000000';
BackupEnvelope::write_sidecar($wrong, $env);
$threw = '';
try { BackupObjectRestore::open_envelope($epoch, $wrong); } catch (BackupObjectRestoreException $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'was minted for epoch-20000101_000000') !== false, 'an envelope minted for another epoch is refused', $threw);
$foreign = BackupEnvelope::build(random_bytes(32), $epoch, array(array('kind' => 'recovery', 'pub' => sodium_crypto_box_publickey(sodium_crypto_box_keypair()))));
BackupEnvelope::write_sidecar($wrong, $foreign);
$threw = '';
try { BackupObjectRestore::open_envelope($epoch, $wrong, 2); } catch (BackupObjectRestoreException $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'does not open with this machine\'s own backup key for the 2 offloaded files') !== false, 'one sealed to another site says so, with what it holds', $threw);

// ─────────────────────────────────────────────────────────────────────────────
section('The contract, the words, the script');

$survey_r = array('result' => 'ok', 'mode' => 'missing', 'run' => $chain_id . '/0', 'indexed' => 3, 'not_stored' => 1, 'wanted' => 2,
	'epochs' => array($epoch => 2), 'want' => array('a.jpg', 'b.bin'), 'more' => true, 'served' => 1, 'duration' => 4);
$text = BackupObjectRestore::format_contract($survey_r);
check(preg_match('/^RESTORE_OBJECTS_WANT=a\.jpg,b\.bin$/m', $text) === 1 && preg_match('/^RESTORE_OBJECTS_MORE=1$/m', $text) === 1
	&& preg_match('/^RESTORE_OBJECTS_EPOCHS=' . $epoch . ':2$/m', $text) === 1 && strpos($text, 'RESTORE_OBJECTS_RESTORED') === false,
	'a survey prints its names, its epochs and that there is more; no page lines', $text);
$p = BackupObjectRestore::parse_contract($text);
check($p['result'] === 'ok' && $p['want'] === array('a.jpg', 'b.bin') && $p['more'] === true && $p['epochs'] === array($epoch => 2)
	&& $p['wanted'] === 2 && $p['not_stored'] === 1 && $p['skipped'] === 1, 'and parses back', json_encode($p));
$p = BackupObjectRestore::parse_contract("RESTORE_OBJECTS_RESULT=ok\nRESTORE_OBJECTS_WANT=ok.jpg,../evil,a b,fine_1.bin\n");
check($p['want'] === array('ok.jpg', 'fine_1.bin'), 'a name that is not a bare name is dropped on the way in', json_encode($p['want']));
$page_r = array('result' => 'ok', 'mode' => 'all', 'run' => 'r', 'indexed' => 3, 'not_stored' => 0, 'restored' => 36, 'bytes' => 432013312, 'kept' => 2, 'skipped' => 0, 'duration' => 1);
$text = BackupObjectRestore::format_contract($page_r);
check(preg_match('/^RESTORE_OBJECTS_RESTORED=36$/m', $text) === 1 && strpos($text, 'RESTORE_OBJECTS_WANT') === false, 'a page prints what it placed and no survey lines');
check(BackupObjectRestore::describe($page_r) === 'Brought 36 offloaded files home (412 MB) (2 already on disk)', 'in words', BackupObjectRestore::describe($page_r));
check(BackupObjectRestore::describe($survey_r) === '2 offloaded files to bring home (1 offloaded file never reached backup storage; 1 need nothing: served by the file store, or already here; the first 2 named)',
	'a survey in words', BackupObjectRestore::describe($survey_r));
check(BackupObjectRestore::parse_contract('nothing')['result'] === 'fail' && strpos(BackupObjectRestore::describe(array('result' => 'fail', 'reason' => 'x')), 'Could not bring') === 0,
	'no result is a failure, worded');

// The script: a dry run over the real index and this database's rows; refusals.
$script = PathHelper::getIncludePath('utils/restore_objects.php');
$run_script = function (array $args, $stdin = null) use ($script) {
	$desc = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
	$cmd = 'php ' . escapeshellarg($script);
	foreach ($args as $a) { $cmd .= ' ' . escapeshellarg($a); }
	$proc = proc_open($cmd, $desc, $pipes);
	if ($stdin !== null) { fwrite($pipes[0], $stdin); }
	fclose($pipes[0]);
	$out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
	fclose($pipes[1]); fclose($pipes[2]);
	return array(proc_close($proc), $out, $err);
};
foreach (array_keys($plain) as $name) { $set_cloud($name); @unlink($home . '/' . $name); }
list($code, $out_t, $err_t) = $run_script(array('--index', $index_path, '--objects', $tree_dir, '--mode', 'all', '--dry-run'));
$p = BackupObjectRestore::parse_contract($out_t);
check($code === 0 && $p['result'] === 'ok' && $p['wanted'] === 3 && $p['mode'] === 'all' && ($p['epochs'][$epoch] ?? 0) === 3 && $p['run'] === $chain_id . '/0',
	'a dry run reports how many would come home and from which epoch, and changes nothing', $out_t . $err_t);
check($row($tag . 'mid.bin') === 'cloud' && !is_file($home . '/' . $tag . 'mid.bin') && !is_file(FileBlob::$tablename), 'nothing moved');
list($code, $out_t, $err_t) = $run_script(array('--index', $index_path, '--objects', '/nonexistent'));
check($code === 2 && strpos($err_t, 'objects_dir') !== false, 'a tree that is not there is a malformed request (exit 2)', $err_t);
list($code, $out_t, $err_t) = $run_script(array('--index', $index_path, '--objects', $tree_dir, '--mode', 'some'));
check($code === 2 && strpos($err_t, "'mode' must be missing or all") !== false, 'an unknown mode is refused', $err_t);
list($code, $out_t, $err_t) = $run_script(array('--index', $index_path, '--objects', $tree_dir, '--epoch-key', 'nope'));
check($code === 2 && strpos($err_t, 'EPOCH=FILE') !== false, '--epoch-key without EPOCH=FILE is refused', $err_t);
list($code, $out_t, $err_t) = $run_script(array(), json_encode(array('chain_id' => 'chain-1', 'profile' => 'manager', 'seq' => 0, 'mode' => 'missing',
	'index_url' => 'https://x/i', 'object_urls' => array('a.bin' => 'https://x/a'), 'recovery_key' => 'k')));
check($code === 2 && strpos($err_t, 'unrecognised key(s): recovery_key') !== false, 'a link request carrying a key is refused before anything is fetched', $err_t);
list($code, $out_t, $err_t) = $run_script(array(), json_encode(array('chain_id' => 'chain-1', 'profile' => 'manager', 'mode' => 'missing', 'index_url' => 'https://x/i')));
check($code === 2 && strpos($err_t, "'seq' must name the run") !== false, 'a link request must name the run', $err_t);
list($code, $out_t, $err_t) = $run_script(array(), json_encode(array('chain_id' => 'chain-1', 'profile' => 'manager', 'seq' => 0, 'mode' => 'missing',
	'index_url' => 'https://x/i', 'object_urls' => array('../a' => 'https://x/a'))));
check($code === 2 && strpos($err_t, 'not a name') !== false, 'an object link keyed by a path is refused', $err_t);

harness_finish();
