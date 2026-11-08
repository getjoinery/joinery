<?php
/** @joinery-test
 * name: status_recovery_key
 * tier: safe
 * env: any
 * needs: []
 * covers: [plugins/server_manager/includes/JobResultProcessor.php]
 */
/**
 * check_status's recovery_key (agent 1.116.0): root's record of the site's
 * recovery key beside the site's own, by fingerprint. JobResultProcessor keeps
 * it only in its own shape, which the node page's Recovery key box reads.
 *
 * Run: php plugins/server_manager/tests/status_recovery_key_test.php
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

section('The report is kept in its own shape');

$a = str_repeat('a', 64);
$b = str_repeat('b', 64);
$r = JobResultProcessor::status_recovery_key(array(
	'recorded' => true, 'fingerprint' => $a, 'how' => 'first seen', 'pinned_time' => '2026-11-08 20:00:00',
	'site_fingerprint' => $b, 'matches' => false, 'extra' => 'dropped',
));
check($r['recorded'] === true && $r['fingerprint'] === $a && $r['site_fingerprint'] === $b, 'the fingerprints are kept');
check($r['matches'] === false, 'a mismatch is kept as one');
check($r['how'] === 'first seen' && $r['pinned_time'] === '2026-11-08 20:00:00', 'how and when it was recorded are kept');
check(!array_key_exists('extra', $r), 'anything else is dropped');
check($r['unusable'] === false, 'a readable record is not unusable');

$bad = JobResultProcessor::status_recovery_key(array(
	'recorded' => 'yes', 'fingerprint' => '<script>', 'how' => 'set by the web tier', 'pinned_time' => 'today',
	'site_fingerprint' => 'AAAA', 'matches' => 'true',
));
check($bad['recorded'] === false, 'recorded must be true itself');
check($bad['fingerprint'] === null && $bad['site_fingerprint'] === null, 'a fingerprint that is not 64 hex is dropped');
check($bad['how'] === null && $bad['pinned_time'] === null, 'an unknown how and a malformed time are dropped');
check($bad['matches'] === null, 'matches must be a boolean');

$err = JobResultProcessor::status_recovery_key(array('recorded' => false, 'error' => "root's record is unusable"));
check($err['unusable'] === true, 'an unusable record says so');
check(JobResultProcessor::status_recovery_key('nope') === null, 'anything but an array is no report');

harness_finish();
