<?php
/** @joinery-test
 * name: b2_s3_location
 * tier: safe
 * env: any
 * needs: []
 */

/**
 * A Backblaze target saved through the wizard or the admin page has no region
 * and no endpoint typed in — both forms hide those fields for B2 — yet S3
 * signing needs both. The save asks Backblaze for its S3 address and reads
 * the two values out of it with BackupTarget::b2_s3_location(). Found live on
 * keyless11 (2026-09-08): "Missing required credential field: region".
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

section('The region and endpoint come out of the S3 address Backblaze reports');
$loc = BackupTarget::b2_s3_location('https://s3.us-east-005.backblazeb2.com');
check($loc['region'] === 'us-east-005' && $loc['endpoint'] === 'https://s3.us-east-005.backblazeb2.com',
	'a Backblaze s3ApiUrl yields its region and a https endpoint', json_encode($loc));
$loc = BackupTarget::b2_s3_location('S3.EU-Central-003.BACKBLAZEB2.COM');
check($loc['region'] === 'eu-central-003' && $loc['endpoint'] === 'https://s3.eu-central-003.backblazeb2.com',
	'a bare hostname in any case is accepted and normalised', json_encode($loc));

section('Anything that is not a Backblaze S3 host yields nothing, so a typed value is never overwritten');
foreach (array('https://s3.amazonaws.com', 'https://api.backblazeb2.com', '', 'not a url') as $bad) {
	$loc = BackupTarget::b2_s3_location($bad);
	check($loc['region'] === '' && $loc['endpoint'] === '', 'no location for ' . var_export($bad, true), json_encode($loc));
}

harness_finish();
