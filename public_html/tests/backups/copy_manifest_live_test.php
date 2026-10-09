<?php
/** @joinery-test
 * name: copy_manifest_live
 * tier: live
 * env: dev-only
 * needs: []
 * timeout: 300
 */
/**
 * A copy from backups reads the backup at its real provider
 * (specs/storage_targets.md F7). For each provider with a backup target here
 * holding a chain, it signs a link to the chain's newest manifest, as
 * SiteCopyRunner::key_request() does for copy_take_key, and runs the agent's
 * own read of it (TestReadStoredManifestLive in the agent source): the bytes
 * match the hash named, the manifest is the chain's and seals the key named,
 * and the provider answers with the date it stored it, from a host the agent
 * believes. Nothing is written to any bucket.
 *
 * Needs the agent source and Go on this box (JOINERY_AGENT_SOURCE, default
 * /home/user1/joinery-agent); without them it is a named skip.
 *
 * Run: php tests/backups/copy_manifest_live_test.php
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$agent_dir = getenv('JOINERY_AGENT_SOURCE') ?: '/home/user1/joinery-agent';
$go = trim((string)shell_exec('command -v go 2>/dev/null'));
section('The copy reads a chain\'s manifest at its provider');
if (!is_file($agent_dir . '/primitives/copy_stored_manifest_live_test.go') || $go === '') {
	harness_skip('reading a manifest at its provider', "no agent source with the live read at {$agent_dir}, or no go");
	harness_finish();
	return;
}

$cases = array();
foreach (new MultiBackupTarget(array('deleted' => false, 'purpose' => MultiBackupTarget::ANY_PURPOSE), array('bkt_backup_target_id' => 'ASC')) as $t) {
	$provider = (string)$t->get('bkt_provider');
	if (isset($cases[$provider]) || $t->is_file_store() || !$t->get('bkt_enabled')) {
		continue;
	}
	try {
		$creds = $t->get_credentials();
		$bucket = trim((string)$t->get('bkt_bucket'));
		$key = '';
		foreach (S3Signer::list($creds, $bucket, BackupTarget::normalise_prefix((string)$t->get('bkt_path_prefix')) . '/') as $o) {
			if (preg_match('#/chain-[0-9_]+/manifest-[0-9]+\.json$#', (string)$o['key'])) {
				$key = (string)$o['key'];
			}
		}
		if ($key === '') {
			continue;
		}
		$body = (string)S3Signer::get($creds, $bucket, '/' . $key)['body'];
	} catch (Exception $e) {
		continue;
	}
	$m = json_decode($body, true);
	$recovery = null;
	foreach ((array)($m['envelope']['recipients'] ?? array()) as $r) {
		if (($r['kind'] ?? '') === 'recovery') { $recovery = $r; }
	}
	if (!$recovery) {
		continue;
	}
	$cases[$provider] = array('provider' => $provider, 'url' => S3Signer::presign_get($creds, $bucket, $key, 900),
		'chain_id' => (string)$m['chain_id'], 'sha' => hash('sha256', $body),
		'sealed' => (string)$recovery['sealed'], 'fingerprint' => (string)$recovery['fingerprint']);
}
if (!$cases) {
	harness_skip('reading a manifest at its provider', 'no enabled backup target here holds a chain');
	harness_finish();
	return;
}

$list = harness_scratch_dir('copy_manifest_live') . '/manifests.json';
file_put_contents($list, json_encode(array_values($cases)));
$cmd = 'cd ' . escapeshellarg($agent_dir) . ' && JOINERY_LIVE_MANIFESTS=' . escapeshellarg($list)
	. ' nice ' . escapeshellarg($go) . ' test ./primitives/ -run TestReadStoredManifestLive -count=1 -v 2>&1';
exec($cmd, $out, $code);
@unlink($list);
$text = implode("\n", $out);
foreach ($cases as $provider => $c) {
	$line = '';
	foreach ($out as $l) {
		if (strpos($l, $provider . ': chain ') !== false) { $line = trim($l); }
	}
	check($line !== '' && strpos($line, ' stored ') !== false, "{$provider}: the copy reads the manifest and its provider's date", $line ?: $text);
}
check($code === 0, 'and every check of the read holds', $code === 0 ? '' : $text);

harness_finish();
