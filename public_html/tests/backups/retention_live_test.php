<?php
/** @joinery-test
 * name: retention_live
 * tier: live
 * env: dev-only
 * needs: []
 * timeout: 300
 */
/**
 * Retention deletion, proven against real buckets (specs/storage_targets.md
 * F5). For each provider with an enabled backup target on this site — B2 and
 * Linode — it:
 *
 *   - writes four small chains under a scratch folder, with backdated history
 *     rows: 40, 30, 20 and 10 days old, the 30-day one verified;
 *   - runs the site's chain retention twice, 21 hours apart on the test clock,
 *     with a one-day window;
 *   - asserts the first pass deleted nothing, and that afterwards exactly the
 *     surplus chain (40 days) is gone from the bucket, while the newest
 *     verified chain, everything newer and the newest chain are all there.
 *
 * Bucket writes are scratch objects under a unique folder, removed at the
 * end; history rows are fixture rows. Credentials are read from the target
 * rows and never printed. A provider with no target here is a named skip.
 *
 * Run: php tests/backups/retention_live_test.php
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$D = 86400;
$H = 3600;

$targets = array();
foreach (new MultiBackupTarget(array('deleted' => false, 'enabled' => true), array('bkt_backup_target_id' => 'ASC')) as $t) {
	$p = (string)$t->get('bkt_provider');
	if (in_array($p, array('b2', 'linode'), true) && !isset($targets[$p])) {
		$targets[$p] = $t;
	}
}

foreach (array('b2' => 'Backblaze B2', 'linode' => 'Linode') as $provider => $label) {
	section('Retention against a real ' . $label . ' bucket');
	if (!isset($targets[$provider])) {
		harness_skip($label . ' retention', 'no enabled ' . $label . ' backup target on this site');
		continue;
	}
	$target = $targets[$provider];
	$creds  = $target->get_credentials();
	$bucket = trim((string)$target->get('bkt_bucket'));
	$slug   = 'zz-retention-live-' . getmypid() . '-' . bin2hex(random_bytes(3));
	$base   = BackupTarget::normalise_prefix((string)$target->get('bkt_path_prefix')) . '/' . $slug . '/site/';
	$work   = harness_scratch_dir('retention_live_' . $provider);
	$real_now = time();

	harness_defer(function () use ($creds, $bucket, $base) {
		foreach (S3Signer::list($creds, $bucket, $base) as $o) {
			S3Signer::delete($creds, $bucket, '/' . ltrim((string)$o['key'], '/'));
		}
	});

	$chains = array();
	foreach (array(40 => false, 30 => true, 20 => false, 10 => false) as $days => $verified) {
		$cid = 'chain-' . gmdate('Ymd_His', $real_now - $days * $D);
		$keys = array();
		foreach (array('files-0000.tar.gz.enc', 'manifest.json') as $name) {
			$tmp = $work . '/' . $name;
			file_put_contents($tmp, $cid . ' ' . $name);
			$resp = S3Signer::put_file($creds, $bucket, '/' . $base . $cid . '/' . $name, $tmp);
			if ((int)$resp['status'] < 200 || (int)$resp['status'] >= 300) {
				check(false, 'the scratch chain is written to the bucket', 'HTTP ' . (int)$resp['status']);
				continue 3;
			}
			$keys[] = $base . $cid . '/' . $name;
		}
		$h = new BackupHistory(NULL);
		$h->set('bkh_type', 'project');
		$h->set('bkh_outcome', 'success');
		$h->set('bkh_slug', $slug);
		$h->set('bkh_profile', 'site');
		$h->set('bkh_destination', 'target');
		$h->set('bkh_bkt_backup_target_id', $target->key);
		$h->set('bkh_target_name', $target->get('bkt_name'));
		$h->set('bkh_chain_id', $cid);
		$h->set('bkh_chain_seq', 0);
		$h->set('bkh_start_time', gmdate('Y-m-d H:i:s', $real_now - $days * $D));
		$h->set('bkh_upload_time', gmdate('Y-m-d H:i:s', $real_now - $days * $D + 600));
		$h->set_artifacts(array(
			array('name' => 'files-0000.tar.gz.enc', 'kind' => 'files', 'key' => $keys[0]),
			array('name' => 'manifest.json', 'kind' => 'manifest', 'key' => $keys[1]),
		));
		if ($verified) {
			$h->set('bkh_verify_outcome', 'pass');
			$h->set('bkh_verify_level', 2);
			$h->set('bkh_verify_time', gmdate('Y-m-d H:i:s', $real_now - $days * $D + 3600));
		}
		$h->save();
		harness_register_row('bkh_backup_history', 'bkh_backup_history_id', $h->key);
		$chains[$days] = $cid;
	}

	$present = function () use ($creds, $bucket, $base) {
		$out = array();
		foreach (S3Signer::list($creds, $bucket, $base) as $o) {
			$rel = substr((string)$o['key'], strlen($base));
			$out[strtok($rel, '/')] = true;
		}
		ksort($out);
		return array_keys($out);
	};
	$all = array_values($chains);
	sort($all);
	check($present() === $all, 'four chains are in the bucket', json_encode($present()));

	$plan = array('slug' => $slug, 'profile' => 'site', 'keep_days' => 1, 'prunes_cloud' => true,
		'destination' => 'target', 'target' => $target, 'output_dir' => $work . '/site', 'objects' => false);
	$idx = null;
	$n = BackupRunner::enforce_chain_retention($plan, $idx, $real_now);
	check($n === 0 && $present() === $all, 'the first pass deletes nothing', $n . ' ' . json_encode($present()));
	$n = BackupRunner::enforce_chain_retention($plan, $idx, $real_now + 21 * $H);
	$left = $present();
	check($n === 1 && !in_array($chains[40], $left, true), 'the second pass deletes the surplus chain from the bucket', $n . ' ' . json_encode($left));
	check(in_array($chains[30], $left, true) && in_array($chains[20], $left, true),
		'the newest verified chain and everything newer survive', json_encode($left));
	check(in_array($chains[10], $left, true), 'the newest chain survives', json_encode($left));
	check(count($left) === 3, 'and nothing else is gone', json_encode($left));
}

harness_finish();
