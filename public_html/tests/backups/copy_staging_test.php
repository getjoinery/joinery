<?php
/** @joinery-test
 * name: copy_staging
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 * covers: [includes/CopyStaging.php, utils/copy_stage.php]
 */
/**
 * A dormant copy downloading its source's chain (specs/site_copy.md WP4, G7):
 *
 *   - the manifest is kept only when its hash is one the source vouched for,
 *     and nothing else is fetched when it is not;
 *   - every artifact the run needs is fetched and checked against the
 *     manifest's size and hash; a damaged one is refused and removed;
 *   - an artifact already staged and matching is kept, not fetched again;
 *   - no key is written;
 *   - the request is refused when the agent's workspace and the chain id
 *     disagree, when no run is vouched, or when it carries an unknown key.
 *
 * Run: php tests/backups/copy_staging_test.php
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/CopyStaging.php'));

$base = sys_get_temp_dir() . '/copy_staging_test_' . getmypid();
harness_defer(function() use ($base) { exec('rm -rf ' . escapeshellarg($base)); });
@mkdir($base, 0700, true);

$chain_id = 'chain-20260930_010203';
$work = $base . '/restore_' . $chain_id;

// The source's bucket, in memory: object name => bytes.
$db0   = str_repeat('database dump ', 50);
$data0 = str_repeat('data tree ', 80);
$data1 = str_repeat('data incremental ', 20);
$manifest = BackupChain::start($chain_id, 'source', array('recipients' => array()), '', 1);
$manifest = BackupChain::add_run($manifest, 0, 0, array(
	'files' => array('name' => 'files-0000.tar.gz.enc', 'bytes' => strlen($data0), 'sha256' => hash('sha256', $data0)),
	'db'    => array('name' => 'db-0000.sql.gz.enc', 'bytes' => strlen($db0), 'sha256' => hash('sha256', $db0)),
));
$db1 = str_repeat('database dump two ', 40);
$manifest = BackupChain::add_run($manifest, 1, 1, array(
	'files' => array('name' => 'files-0001.tar.gz.enc', 'bytes' => strlen($data1), 'sha256' => hash('sha256', $data1)),
	'db'    => array('name' => 'db-0001.sql.gz.enc', 'bytes' => strlen($db1), 'sha256' => hash('sha256', $db1)),
));
$manifest_json = BackupChain::encode($manifest);
$bucket = array(
	'manifest.json'         => $manifest_json,
	'files-0000.tar.gz.enc' => $data0,
	'files-0001.tar.gz.enc' => $data1,
	'db-0000.sql.gz.enc'    => $db0,
	'db-0001.sql.gz.enc'    => $db1,
);

$fetched = array();
BackupStaging::$fetch_for_tests = function ($url, $sink, $max) use (&$bucket, &$fetched) {
	$name = basename(parse_url($url, PHP_URL_PATH));
	$fetched[] = $name;
	if (!isset($bucket[$name])) { return array('ok' => false, 'error' => 'gone'); }
	if ($max > 0 && strlen($bucket[$name]) > $max) { return array('ok' => false, 'error' => 'too large'); }
	file_put_contents($sink, $bucket[$name]);
	return array('ok' => true, 'error' => '', 'bytes' => strlen($bucket[$name]));
};
harness_defer(function() { BackupStaging::$fetch_for_tests = null; });

$link = function ($name) { return 'https://bucket.invalid/source/' . $name . '?X-Amz-Signature=x'; };
$config = array(
	'chain_id'      => $chain_id,
	'manifest_url'  => $link('manifest.json'),
	'artifact_urls' => array(),
);
foreach (array_keys($bucket) as $name) {
	if ($name !== 'manifest.json') { $config['artifact_urls'][$name] = $link($name); }
}
$vouched = hash('sha256', $manifest_json);
$args = array('--workspace', $work, '--vouched', $vouched);

section('A vouched chain is staged, every artifact checked');

$req = CopyStaging::parse_request($args, $config);
$out = CopyStaging::stage($req);
check($out['seq'] === 1 && $out['fetched'] === 3, 'the newest run needs both file archives and its own dump, and all three arrived',
	json_encode($out));
check(file_get_contents($work . '/manifest.json') === $manifest_json, 'the manifest is kept under its own name');
check(!file_exists($work . '/manifest.json.incoming'), 'and nothing is left beside it');
check(!file_exists($work . '/' . BackupStaging::KEY_NAME), 'no key is written: copy_import writes it');

$fetched = array();
$out = CopyStaging::stage(CopyStaging::parse_request($args, $config));
check($fetched === array('manifest.json') && $out['fetched'] === 3,
	'a second staging keeps what already matches and fetches only the manifest', json_encode($fetched));

$fetched = array();
$c = $config; $c['seq'] = 0;
$out = CopyStaging::stage(CopyStaging::parse_request($args, $c));
check($out['seq'] === 0 && $out['fetched'] === 2, 'a run number stages that run');

section('A manifest the source did not vouch for is refused before anything else moves');

exec('rm -rf ' . escapeshellarg($work));
$fetched = array();
$bucket['manifest.json'] = $manifest_json . ' ';
$threw = null;
try { CopyStaging::stage(CopyStaging::parse_request($args, $config)); } catch (BackupStagingException $e) { $threw = $e; }
check($threw !== null && strpos($threw->getMessage(), 'not one the source vouched for') !== false,
	'a changed manifest is refused, naming the vouch', $threw ? $threw->getMessage() : 'no refusal');
check($fetched === array('manifest.json'), 'and no artifact was fetched for it', json_encode($fetched));
check(!file_exists($work . '/manifest.json') && !file_exists($work . '/manifest.json.incoming'),
	'and it is not kept');
$bucket['manifest.json'] = $manifest_json;

section('An artifact that does not match the manifest is refused and removed');

$bucket['db-0001.sql.gz.enc'] = strrev($db1);
$threw = null;
try { CopyStaging::stage(CopyStaging::parse_request($args, $config)); } catch (BackupStagingException $e) { $threw = $e; }
check($threw !== null && strpos($threw->getMessage(), 'recorded hash') !== false, 'a replaced artifact is refused',
	$threw ? $threw->getMessage() : 'no refusal');
check(!file_exists($work . '/db-0001.sql.gz.enc'), 'and removed');
$bucket['db-0001.sql.gz.enc'] = $db1;

unset($config['artifact_urls']['files-0001.tar.gz.enc']);
@unlink($work . '/files-0001.tar.gz.enc');
$threw = null;
try { CopyStaging::stage(CopyStaging::parse_request($args, $config)); } catch (BackupStagingException $e) { $threw = $e; }
check($threw !== null && strpos($threw->getMessage(), 'gone: files-0001') !== false,
	'an artifact with no link fails as gone, by name', $threw ? $threw->getMessage() : 'no refusal');
$config['artifact_urls']['files-0001.tar.gz.enc'] = $link('files-0001.tar.gz.enc');

section('A request the agent and the management node disagree on is refused');

$malformed = function ($args, $config) {
	try { CopyStaging::parse_request($args, $config); } catch (BackupStagingException $e) {
		return $e->getCode() === BackupStagingException::MALFORMED;
	}
	return false;
};
check($malformed(array('--workspace', $work), $config), 'no vouched run');
check($malformed(array('--workspace', $work, '--vouched', 'abc'), $config), 'a vouch that is not a hash');
check($malformed(array('--workspace', $base . '/restore_chain-20200101_000000', '--vouched', $vouched), $config),
	'a workspace for another chain');
check($malformed(array('--workspace', 'relative/restore_' . $chain_id, '--vouched', $vouched), $config),
	'a workspace that is not an absolute path');
check($malformed($args, $config + array('key_file' => '/x')), 'a key it does not know');
check($malformed(array_merge($args, array('--key', 'x')), $config), 'an argument it does not know');
$c = $config; $c['manifest_url'] = 'http://bucket.invalid/manifest.json';
check($malformed($args, $c), 'a manifest link that is not https');

harness_finish();
