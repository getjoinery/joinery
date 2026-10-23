<?php
/** @joinery-test
 * name: upgrade_retention_kept
 * tier: db
 * env: any
 * needs: []
 */

/**
 * upgrade_retention_kept (B55, from the final copy test's C8): a management
 * node keeps the signed core archive of each release it upgrades to, as
 * received, where it keeps the ones it publishes, so a copy of one of its
 * sites onto a host can install that release (ReleaseManifestSource reads it).
 * The kept archives are pruned by the rules published ones follow: the newest
 * N stay, and so does any release this node or one of its nodes runs. Driven
 * against an archive directory of the test's own.
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$tag = bin2hex(random_bytes(3));
$dir = harness_scratch_dir('upgrade_retention_kept') . '/' . $tag;
mkdir($dir, 0770);
UpgradeRetention::$archive_dir = $dir;
harness_defer(function () use ($dir) {
	UpgradeRetention::$archive_dir = null;
	array_map('unlink', glob($dir . '/*') ?: array());
	@rmdir($dir);
});

$download = function (string $body) use ($dir) {
	$f = $dir . '/download-' . bin2hex(random_bytes(4)) . '.tar.gz';
	file_put_contents($f, $body);
	return $f;
};

section('A management node keeps the archive it applied, as received');

$got = $download('SIGNED ARCHIVE 7.0.3');
$kept = UpgradeRetention::keep_applied_archive($got, '7.0.3');
check($kept === $dir . '/joinery-core-7.0.3.tar.gz' && is_file($kept), 'the archive is kept under the name a published one has', $kept);
check(file_get_contents($kept) === 'SIGNED ARCHIVE 7.0.3' && file_exists($got),
	'byte for byte as received, and the download is left for upgrade.php to purge');
check((fileperms($kept) & 0777) === 0644 && !file_exists($kept . '.part'), 'readable, and no partial file left');

file_put_contents($dir . '/joinery-core-7.0.2.tar.gz', 'PUBLISHED 7.0.2');
check(UpgradeRetention::keep_applied_archive($download('ANOTHER 7.0.2'), '7.0.2') === ''
	&& file_get_contents($dir . '/joinery-core-7.0.2.tar.gz') === 'PUBLISHED 7.0.2',
	'an archive this node already holds (a publisher\'s own) is never replaced');
check(UpgradeRetention::keep_applied_archive($download('X'), '7.0') === ''
	&& UpgradeRetention::keep_applied_archive($dir . '/none.tar.gz', '7.0.4') === '',
	'nothing is kept for a malformed version or a missing download');

section('Kept archives are pruned by the rules published ones follow');

foreach (array('7.0.1', '7.0.4') as $v) {
	UpgradeRetention::keep_applied_archive($download('SIGNED ARCHIVE ' . $v), $v);
}
$own = LibraryFunctions::get_joinery_version();
if ($own !== '' && !file_exists($dir . '/joinery-core-' . $own . '.tar.gz')) {
	file_put_contents($dir . '/joinery-core-' . $own . '.tar.gz', 'OWN');
}
// A node of this management node still runs 7.0.1: its copy would need it.
$n = new ManagedNode(NULL);
$n->set('mgn_name', 'HarnessTest URK ' . $tag);
$n->set('mgn_slug', 'harnessurk-' . $tag);
$n->set('mgn_uptime_enabled', false);
$n->set('mgn_host', '192.0.2.77');
$n->set('mgn_joinery_version', '7.0.1');
$n->save();
$n->load();
harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $n->key);

check(UpgradeRetention::prune_kept(0)['removed'] === array(), 'retention set to keep all removes nothing');
$report = UpgradeRetention::prune_kept(1);
$left = array_map(function ($f) { return basename($f); }, glob($dir . '/joinery-core-*.tar.gz'));
sort($left);
check(in_array('joinery-core-7.0.4.tar.gz', $left, true), 'the newest kept archive stays', implode(' ', $left));
check(in_array('joinery-core-7.0.1.tar.gz', $left, true), 'so does one a node of this management node runs', implode(' ', $left));
check($own === '' || in_array('joinery-core-' . $own . '.tar.gz', $left, true), 'and the one this management node runs');
sort($report['removed']);
check($report['removed'] === array('7.0.2', '7.0.3') && !in_array('joinery-core-7.0.3.tar.gz', $left, true),
	'the rest go', json_encode($report));

section('upgrade.php keeps it before it empties its download folder, on a management node only');

$up = (string)file_get_contents(PathHelper::getIncludePath('utils/upgrade.php'));
$keep_at = strpos($up, 'UpgradeRetention::keep_applied_archive($file_download_location');
$purge_at = strpos($up, '// Empty the archive directory.');
check($keep_at !== false && $purge_at !== false && $keep_at < $purge_at, 'the applied archive is kept before the download folder is emptied');
// The re-run a self-update re-execs skips the download, so the path is set
// before that branch: the archive its parent downloaded is the one it keeps.
$path_at = strpos($up, '$file_download_location = $archive_location . basename(');
$branch_at = strpos($up, 'if (!$resuming_after_self_update) {');
check($path_at !== false && $branch_at !== false && $path_at < $branch_at,
	'the re-run after a self-update knows where the archive was downloaded, so it keeps it too');
check(strpos($up, "if (isset(\$file_download_location) && class_exists('UpgradeRetention')) {") !== false,
	'only where server_manager\'s classes resolve: a site that is not a management node keeps nothing');

harness_finish();
