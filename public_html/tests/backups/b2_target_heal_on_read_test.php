<?php
/** @joinery-test
 * name: b2_target_heal_on_read
 * tier: test-db
 * env: any
 * needs: []
 */
/**
 * A Backblaze target saved with only its keys and bucket (every B2 target
 * saved before save-time completion existed) carries an empty region and
 * endpoint, and BackupRunner signs with what the row has: "Missing required
 * credential field: region", every morning (specs/post_release_fleet_defects.md B3).
 *
 * Pinned here: such a row loads with both filled, the completed credential is
 * written back once, and a credential that is already complete is never asked
 * about again. Backblaze is stood in for by BackupTarget::$b2_locator.
 *
 * Run: php tests/backups/b2_target_heal_on_read_test.php
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();

$asked = 0;
BackupTarget::$b2_locator = function ($access_key, $secret_key) use (&$asked) {
	$asked++;
	return 'https://s3.us-west-004.backblazeb2.com';
};
harness_defer(function () { BackupTarget::$b2_locator = null; });

section('A B2 row with an empty region loads with both filled');

$target = new BackupTarget();
$target->set('bkt_name', 'Harness B2 heal ' . substr(md5((string)microtime(true)), 0, 6));
$target->set('bkt_provider', 'b2');
$target->set('bkt_bucket', 'harness-bucket');
// Straight into the column, the way a pre-completion save left it: keys and
// nothing else. (Saving through the form path would complete it today.)
$target->set('bkt_credentials', array('access_key' => 'k1', 'secret_key' => 's1', 'bucket' => 'harness-bucket'));
$target->save();
$id = (int)$target->key;
harness_defer(function () use ($id) { $t = new BackupTarget($id, TRUE); $t->permanent_delete(); });

$fresh = new BackupTarget($id, TRUE);
$creds = $fresh->get_credentials();
check($creds['region'] === 'us-west-004', 'the region is filled on read', json_encode($creds));
check($creds['endpoint'] === 'https://s3.us-west-004.backblazeb2.com', 'and the endpoint');
check(($creds['bucket'] ?? '') === 'harness-bucket' && $creds['access_key'] === 'k1', 'the keys the row carried are kept');
check($asked === 1, 'Backblaze was asked once', (string)$asked);

section('The completed credential is written back once');

$again = new BackupTarget($id, TRUE);
$creds2 = $again->get_credentials();
check($creds2['region'] === 'us-west-004' && $creds2['endpoint'] === $creds['endpoint'], 'a second load reads the completed credential');
check($asked === 1, 'without asking Backblaze again', (string)$asked);

section('A credential Backblaze cannot complete is left as it was');

BackupTarget::$b2_locator = function () use (&$asked) { $asked++; throw new RuntimeException('unauthorized'); };
$t2 = new BackupTarget();
$t2->set('bkt_name', 'Harness B2 heal fail ' . substr(md5((string)microtime(true)), 0, 6));
$t2->set('bkt_provider', 'b2');
$t2->set('bkt_bucket', 'harness-bucket');
$t2->set('bkt_credentials', array('access_key' => 'k2', 'secret_key' => 's2'));
$t2->save();
$id2 = (int)$t2->key;
harness_defer(function () use ($id2) { $t = new BackupTarget($id2, TRUE); $t->permanent_delete(); });
$c = (new BackupTarget($id2, TRUE))->get_credentials();
check(($c['region'] ?? '') === '' && ($c['endpoint'] ?? '') === '', 'nothing is invented when Backblaze does not answer', json_encode($c));
check($asked === 2, 'it was asked', (string)$asked);

section('Other providers are never asked');

$t3 = new BackupTarget();
$t3->set('bkt_name', 'Harness S3 ' . substr(md5((string)microtime(true)), 0, 6));
$t3->set('bkt_provider', 's3');
$t3->set('bkt_bucket', 'harness-bucket');
$t3->set('bkt_credentials', array('access_key' => 'k3', 'secret_key' => 's3'));
$t3->save();
$id3 = (int)$t3->key;
harness_defer(function () use ($id3) { $t = new BackupTarget($id3, TRUE); $t->permanent_delete(); });
(new BackupTarget($id3, TRUE))->get_credentials();
check($asked === 2, 'an S3 target does not consult Backblaze', (string)$asked);

harness_finish();
