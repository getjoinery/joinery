<?php
/** @joinery-test
 * name: target_backups
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * TargetBackups + S3Signer listing — the control-plane view of a backup bucket.
 *
 * This is the path that keeps a decommissioned site's backups reachable: it lists
 * the bucket straight from the control plane and groups the objects by site, so a
 * node that no longer exists can still have its offsite backups found and deleted.
 * Two things have to hold. The listing must page through a truncated ListObjectsV2
 * response (a bucket with more than one page of keys must not silently show only
 * the first page). And the grouping must tag each site correctly — live,
 * decommissioned, or orphaned — because that tag is the operator's whole basis for
 * deciding a prefix is safe to wipe.
 *
 * The XML paging is driven through S3Signer's private parsers by reflection; the
 * grouping is a pure function fed synthetic objects and a synthetic node map, so no
 * network or live bucket is required.
 *
 * Run: php plugins/server_manager/tests/target_backups_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/S3Signer.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/TargetBackups.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/backup_target_class.php'));

/** Call a private static on S3Signer. */
function s3_call($method, array $args) {
	$m = new ReflectionMethod('S3Signer', $method);
	$m->setAccessible(true);
	return $m->invokeArgs(null, $args);
}

// ---------------------------------------------------------------------------
section('ListObjectsV2 XML parsing');

$page1 = '<?xml version="1.0" encoding="UTF-8"?>'
	. '<ListBucketResult><Name>b</Name><Prefix>joinery-backups/</Prefix>'
	. '<IsTruncated>true</IsTruncated><NextContinuationToken>TOK/EN+123=</NextContinuationToken>'
	. '<Contents><Key>joinery-backups/site-a/backup1.sql.gz.enc</Key><Size>1048576</Size><LastModified>2026-07-20T10:00:00.000Z</LastModified></Contents>'
	. '<Contents><Key>joinery-backups/site-a/a&amp;b.tar.gz</Key><Size>2048</Size><LastModified>2026-07-21T10:00:00.000Z</LastModified></Contents>'
	. '</ListBucketResult>';

$page2 = '<?xml version="1.0" encoding="UTF-8"?>'
	. '<ListBucketResult><Name>b</Name><IsTruncated>false</IsTruncated>'
	. '<Contents><Key>joinery-backups/site-b/backup9.tar.gz</Key><Size>512</Size><LastModified>2026-07-22T10:00:00.000Z</LastModified></Contents>'
	. '</ListBucketResult>';

$c1 = s3_call('parse_list_contents', array($page1));
check(count($c1) === 2, 'page 1 yields both Contents entries', count($c1));
check(($c1[0]['key'] ?? '') === 'joinery-backups/site-a/backup1.sql.gz.enc' && (int)$c1[0]['size'] === 1048576,
	'key and size are parsed', var_export($c1[0] ?? null, true));
check(($c1[1]['key'] ?? '') === 'joinery-backups/site-a/a&b.tar.gz',
	'XML entities in a key are decoded', $c1[1]['key'] ?? '?');

$tok = s3_call('parse_next_token', array($page1));
check($tok === 'TOK/EN+123=', 'a truncated page returns its continuation token verbatim', var_export($tok, true));
check(s3_call('parse_next_token', array($page2)) === null,
	'a non-truncated page returns no token (paging stops)');
check(s3_call('parse_list_contents', array('not xml at all')) === array(),
	'garbage input yields no objects rather than a warning');

// ---------------------------------------------------------------------------
section('Grouping and classification');

$objects = array(
	array('key' => 'joinery-backups/live-slug/f1.gz',  'size' => 100, 'last_modified' => 'x'),
	array('key' => 'joinery-backups/live-slug/f2.gz',  'size' => 50,  'last_modified' => 'x'),
	array('key' => 'joinery-backups/dead-slug/f3.gz',  'size' => 200, 'last_modified' => 'x'),
	array('key' => 'joinery-backups/ghost-slug/f4.gz', 'size' => 10,  'last_modified' => 'x'),
	array('key' => 'joinery-backups/',                 'size' => 0,   'last_modified' => 'x'), // folder marker
);
$node_map = array(
	'live-slug' => array('node_id' => 11, 'deleted' => false),
	'dead-slug' => array('node_id' => 22, 'deleted' => true),
);
$res = TargetBackups::group_objects($objects, 'joinery-backups/', $node_map);

check($res['total_objects'] === 4, 'the folder marker is not counted as an object', $res['total_objects']);
check(count($res['groups']) === 3, 'three sites are grouped', count($res['groups']));
check(($res['groups']['live-slug']['status'] ?? '') === 'live'
	&& $res['groups']['live-slug']['count'] === 2 && $res['groups']['live-slug']['bytes'] === 150,
	'a slug with a live node is tagged live, with correct count and bytes',
	var_export($res['groups']['live-slug'] ?? null, true));
check(($res['groups']['dead-slug']['status'] ?? '') === 'decommissioned',
	'a slug whose node is soft-deleted is tagged decommissioned');
check(($res['groups']['ghost-slug']['status'] ?? '') === 'orphaned'
	&& $res['groups']['ghost-slug']['node_id'] === null,
	'a slug with no node at all is tagged orphaned with no node id');

// ---------------------------------------------------------------------------
section('Prefix handling and the single-object delete guard');

$t = new BackupTarget(NULL);
$t->set('bkt_path_prefix', 'joinery-backups');
check(TargetBackups::base_prefix($t) === 'joinery-backups/',
	'base prefix is normalized to a single trailing slash');
$t->set('bkt_path_prefix', 'custom/prefix/');
check(TargetBackups::base_prefix($t) === 'custom/prefix/',
	'an already-slashed custom prefix is preserved');
$t->set('bkt_path_prefix', '');
check(TargetBackups::base_prefix($t) === 'joinery-backups/',
	'an empty prefix falls back to the default');

// delete_object refuses a key outside the target prefix BEFORE any bucket call.
$t->set('bkt_path_prefix', 'joinery-backups');
$guarded = false;
try {
	TargetBackups::delete_object($t, 'some-other-prefix/evil.gz');
} catch (TargetBackupsException $e) {
	$guarded = true;
}
check($guarded, 'delete_object refuses a key outside the target prefix (no arbitrary deletes)');

// ---------------------------------------------------------------------------
section('Backup-existence guard (blocks record deletion while backups exist)');

// An unsafe/empty slug can carry no prefixed backups — the guard returns a clean
// zero without touching any target (the network paths are exercised live).
$g = TargetBackups::slug_backup_count('bad slug!!');
check($g['count'] === 0 && $g['unchecked'] === array(),
	'an invalid slug yields count 0 with nothing unchecked (no prefix to probe)',
	var_export($g, true));
$g2 = TargetBackups::slug_backup_count('');
check($g2['count'] === 0, 'an empty slug yields count 0');

harness_finish();
