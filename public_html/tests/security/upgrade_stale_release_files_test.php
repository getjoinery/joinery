<?php
/** @joinery-test
 * name: upgrade_stale_release_files
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * An upgrade removes the maintenance_scripts/ files a release stopped shipping,
 * and only those (B18). upgrade.php rsyncs maintenance_scripts/ without
 * --delete, because a node may carry scripts of its own, so a script a release
 * dropped stayed on every node forever.
 *
 * The rule: a file the previous signed release listed under maintenance_scripts/
 * and the new one does not. A file neither listed is local and stays. Pinned
 * here on manifests signed with a throwaway key, plus where upgrade.php applies
 * it: worked out before the new manifest replaces the previous one, applied
 * only after every rollback exit.
 *
 * Run: php tests/run.php --only=tests/security/upgrade_stale_release_files_test.php
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$usr_dirs = array();
function usr_signed_manifest(array $listing, array $keys) {
	global $usr_dirs;
	$dir = sys_get_temp_dir() . '/usr_' . bin2hex(random_bytes(6));
	$usr_dirs[] = $dir;
	mkdir($dir, 0755, true);
	$body = "# test manifest\n";
	foreach ($listing as $rel => $hash) {
		$body .= $hash . '  ' . $rel . "\n";
	}
	file_put_contents($dir . '/' . PackageSignature::MANIFEST_NAME, $body);
	file_put_contents($dir . '/' . PackageSignature::SIGNATURE_NAME,
		base64_encode(sodium_crypto_sign_detached($body, $keys['secret'])) . "\n");
	return $dir;
}

$pair = sodium_crypto_sign_keypair();
$keys = array('secret' => sodium_crypto_sign_secretkey($pair), 'public' => sodium_crypto_sign_publickey($pair));
$h = str_repeat('a', 64);
$keys_dir = sys_get_temp_dir() . '/usr_keys_' . bin2hex(random_bytes(6));
$usr_dirs[] = $keys_dir;
mkdir($keys_dir, 0755, true);
file_put_contents($keys_dir . '/release_verify_keys', base64_encode($keys['public']) . "\n");
$keys_file = $keys_dir . '/release_verify_keys';

section('The files a release stopped shipping');

$previous = usr_signed_manifest(array(
	'public_html/index.php' => $h,
	'public_html/old_page.php' => $h,
	'maintenance_scripts/install_tools/install.sh' => $h,
	'maintenance_scripts/install_tools/dropped.sh' => $h,
	'maintenance_scripts/sysadmin_tools/also_dropped.php' => $h,
), $keys);
$new = usr_signed_manifest(array(
	'public_html/index.php' => $h,
	'maintenance_scripts/install_tools/install.sh' => $h,
	'maintenance_scripts/install_tools/added.sh' => $h,
), $keys);
$prev_listing = PackageSignature::trustedListing($previous, $keys_file);
$new_listing = PackageSignature::trustedListing($new, $keys_file);
check(is_array($prev_listing) && count($prev_listing) === 5 && is_array($new_listing) && count($new_listing) === 3,
	'a manifest a trusted key signed is read as its listing');

$dropped = PackageSignature::droppedPaths($prev_listing, $new_listing, 'maintenance_scripts');
check($dropped === array('maintenance_scripts/install_tools/dropped.sh', 'maintenance_scripts/sysadmin_tools/also_dropped.php'),
	'the maintenance_scripts files the previous release listed and the new one does not', json_encode($dropped));
check(!in_array('public_html/old_page.php', $dropped, true), 'nothing outside maintenance_scripts/ is named (public_html is swapped whole)');
check(!in_array('maintenance_scripts/install_tools/local_tool.sh', $dropped, true)
	&& PackageSignature::droppedPaths(array(), $new_listing, 'maintenance_scripts') === array(),
	'a file no release listed is local, and no previous listing names nothing');

section('Only a signed listing counts');

$stranger = sodium_crypto_sign_keypair();
$forged = usr_signed_manifest(array('maintenance_scripts/install_tools/install.sh' => $h),
	array('secret' => sodium_crypto_sign_secretkey($stranger)));
check(PackageSignature::trustedListing($forged, $keys_file) === null,
	'a manifest signed by a key this machine does not trust is no listing');
file_put_contents($previous . '/' . PackageSignature::MANIFEST_NAME, "\n" . $h . '  maintenance_scripts/install_tools/install.sh' . "\n", FILE_APPEND);
check(PackageSignature::trustedListing($previous, $keys_file) === null, 'nor is one edited after signing');
check(PackageSignature::trustedListing($keys_dir . '/nothing_here', $keys_file) === null, 'nor an absent one');

section('Where upgrade.php applies it');

$src = file_get_contents(PathHelper::getIncludePath('utils/upgrade.php'));
$worked_out = strpos($src, 'PackageSignature::droppedPaths($previous_listing, $new_listing');
$manifest_replaced = strpos($src, "foreach (array('RELEASE_MANIFEST', 'RELEASE_MANIFEST.sig') as \$mf)");
$removed = strpos($src, 'foreach (($stale_release_files ?? array()) as $stale_rel)');
$last_rollback = strrpos($src, 'DeploymentHelper::performRollback(');
check($worked_out !== false && $manifest_replaced !== false && $worked_out < $manifest_replaced,
	'the dropped files are worked out before the new manifest replaces the previous one');
check($removed !== false && $last_rollback !== false && $removed > $last_rollback,
	'and removed only after the last rollback exit, so a rolled-back upgrade removes nothing');

foreach ($usr_dirs as $d) {
	exec('rm -rf ' . escapeshellarg($d));
}

harness_finish();
