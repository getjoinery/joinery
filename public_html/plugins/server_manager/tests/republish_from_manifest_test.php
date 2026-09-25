<?php
/** @joinery-test
 * name: republish_from_manifest
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * A site that republishes what it received serves that release, file for file
 * (B18). getjoinery republished 0.8.426 by walking its own tree: the archive
 * carried an install SQL dumped from its own database under the hash dev
 * signed, and two scripts git dropped months ago. TreeManifestPublisher::
 * republish_artifact() stages an artifact from exactly the files its received
 * manifest lists, checking each copy against the listed hash.
 *
 * Pinned here, on throwaway trees signed with a throwaway key:
 *   - a tree with stray files stages exactly the listed files, byte-identical,
 *     and the staged artifact verifies as a node verifies it;
 *   - a plugin stages the same way under its own manifest;
 *   - a listed file whose bytes differ, or that is missing, refuses by name;
 *   - carry()'s own checks still apply to the manifest.
 *
 * Run: php plugins/server_manager/tests/republish_from_manifest_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('tests/lib/harness.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/TreeManifestPublisher.php'));

harness_boot();

$rfm_dirs = array();
function rfm_tree(array $files) {
	global $rfm_dirs;
	$root = sys_get_temp_dir() . '/rfm_' . bin2hex(random_bytes(6));
	$rfm_dirs[] = $root;
	foreach ($files as $rel => $contents) {
		$abs = $root . '/' . $rel;
		@mkdir(dirname($abs), 0755, true);
		file_put_contents($abs, $contents);
	}
	return $root;
}
function rfm_stage() {
	global $rfm_dirs;
	$dir = sys_get_temp_dir() . '/rfm_stage_' . bin2hex(random_bytes(6));
	$rfm_dirs[] = $dir;
	return $dir;
}
/** Every regular file under $dir, as [relative path => sha256]. */
function rfm_files($dir) {
	$out = array();
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $f) {
		if ($f->isFile()) {
			$out[substr($f->getPathname(), strlen($dir) + 1)] = hash_file('sha256', $f->getPathname());
		}
	}
	ksort($out);
	return $out;
}
function rfm_refusal(callable $fn) {
	try { $fn(); return null; } catch (Exception $e) { return $e->getMessage(); }
}

// The upstream release key signs; this site holds a key of its own that it may
// not sign with (the republishing posture).
$pair = sodium_crypto_sign_keypair();
$upstream = array('secret' => sodium_crypto_sign_secretkey($pair), 'public' => sodium_crypto_sign_publickey($pair));
$own_pub = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
$authority = array(
	'may_sign' => false, 'keys' => null,
	'own_public_b64' => base64_encode($own_pub),
	'bundle_key_b64' => base64_encode($upstream['public']),
	'reason' => 'test: this site carries what it received',
);
$keys_file = rfm_tree(array('release_verify_keys' => base64_encode($upstream['public']) . "\n")) . '/release_verify_keys';

section('A core release stages exactly its listed files, byte for byte');

// The release as upstream shipped it, manifest at the site root.
$site = rfm_tree(array(
	'public_html/VERSION' => "0.8.426\n",
	'public_html/utils/upgrade.php' => "<?php // upgrade\n",
	'maintenance_scripts/install_tools/install.sh' => "#!/bin/bash\necho install\n",
	'maintenance_scripts/install_tools/default_Globalvars_site.php' => "<?php // template\n",
	'maintenance_scripts/install_tools/joinery-install.sql.gz' => gzencode("-- 204 tables\n"),
));
chmod($site . '/maintenance_scripts/install_tools/install.sh', 0755);
TreeManifestPublisher::write($site, $site, $upstream);
$listed = PackageSignature::parse(file_get_contents($site . '/RELEASE_MANIFEST'));

// What this site then grew: files no release lists.
file_put_contents($site . '/maintenance_scripts/install_tools/deploy.sh', "#!/bin/bash\n# retired long ago\n");
file_put_contents($site . '/public_html/stray.txt', "local\n");
@mkdir($site . '/public_html/cache', 0755, true);
file_put_contents($site . '/public_html/cache/x', 'cached');

$stage = rfm_stage();
$r = TreeManifestPublisher::republish_artifact($site, $site, $stage, '', $authority);
$staged = rfm_files($stage);
$want = $listed;
$want['RELEASE_MANIFEST'] = hash_file('sha256', $site . '/RELEASE_MANIFEST');
$want['RELEASE_MANIFEST.sig'] = hash_file('sha256', $site . '/RELEASE_MANIFEST.sig');
ksort($want);
check($staged === $want, 'the staged core is exactly the listed files plus the manifest pair, each byte-identical',
	json_encode(array_keys($staged)));
check($r['files'] === count($listed) && $r['carried'] === true, 'and it reports the listed count, carried');
check(!isset($staged['maintenance_scripts/install_tools/deploy.sh']) && !isset($staged['public_html/stray.txt']),
	'a file no release lists does not ship');
check(hash_file('sha256', $stage . '/maintenance_scripts/install_tools/joinery-install.sql.gz')
		=== $listed['maintenance_scripts/install_tools/joinery-install.sql.gz'],
	'the install SQL that ships is the one the release carried');
check((fileperms($stage . '/maintenance_scripts/install_tools/install.sh') & 0111) !== 0, 'an executable script stays executable');
$verdict = PackageSignature::verify($stage, $keys_file);
check($verdict->signed(), 'the staged core verifies exactly as a node verifies it', $verdict->line());

section('A plugin stages from its own manifest');

$psite = rfm_tree(array(
	'public_html/plugins/demo/plugin.json' => "{\"version\":\"1.2.3\"}\n",
	'public_html/plugins/demo/includes/Demo.php' => "<?php class Demo {}\n",
));
$pdir = $psite . '/public_html/plugins/demo';
TreeManifestPublisher::write($pdir, $psite, $upstream);
file_put_contents($pdir . '/leftover.php', "<?php // not shipped\n");
$pstage = rfm_stage();
TreeManifestPublisher::republish_artifact($pdir, $psite, $pstage . '/demo', 'public_html/plugins/demo', $authority);
check(array_keys(rfm_files($pstage . '/demo')) === array('RELEASE_MANIFEST', 'RELEASE_MANIFEST.sig', 'includes/Demo.php', 'plugin.json'),
	'the staged plugin is its listed files and its manifest pair', json_encode(array_keys(rfm_files($pstage . '/demo'))));
$pv = PackageSignature::verify($pstage . '/demo', $keys_file);
check($pv->signed() && $pv->root === 'public_html/plugins/demo', 'and it verifies as the plugin it names', $pv->line());

section('A listed file that is not what was signed refuses, by name');

// The install SQL regenerated from this site's own database: 0.8.426 exactly.
file_put_contents($site . '/maintenance_scripts/install_tools/joinery-install.sql.gz', gzencode("-- 157 tables\n"));
$why = rfm_refusal(function () use ($site, $authority) {
	TreeManifestPublisher::republish_artifact($site, $site, rfm_stage(), '', $authority);
});
check($why !== null && strpos($why, 'maintenance_scripts/install_tools/joinery-install.sql.gz') !== false,
	'a regenerated install SQL refuses and names the file', (string)$why);

unlink($site . '/maintenance_scripts/install_tools/joinery-install.sql.gz');
$why = rfm_refusal(function () use ($site, $authority) {
	TreeManifestPublisher::republish_artifact($site, $site, rfm_stage(), '', $authority);
});
check($why !== null && strpos($why, 'joinery-install.sql.gz is listed in the received manifest but is not a file') !== false,
	'a listed file that is missing refuses and names it', (string)$why);

section('The manifest itself is checked as any carried one is');

$why = rfm_refusal(function () use ($pdir, $psite, $authority) {
	TreeManifestPublisher::republish_artifact($pdir, $psite, rfm_stage() . '/demo', 'public_html/plugins/other', $authority);
});
check($why !== null && strpos($why, 'outside public_html/plugins/other') !== false,
	'a listing that is not the artifact\'s refuses', (string)$why);

$self_signed = $authority;
$self_signed['bundle_key_b64'] = base64_encode($own_pub);
$why = rfm_refusal(function () use ($pdir, $psite, $self_signed) {
	TreeManifestPublisher::republish_artifact($pdir, $psite, rfm_stage() . '/demo', 'public_html/plugins/demo', $self_signed);
});
check($why !== null && strpos($why, 'does not verify against the key in the agent') !== false,
	'a manifest the shipped agent would not trust refuses', (string)$why);

// A listing only the signer could have written, but not a plain path: refused
// before anything is copied (defence in depth; the signature already binds it).
$odd = rfm_tree(array('public_html/a.php' => "<?php\n"));
$odd_body = "# odd\n" . hash('sha256', "<?php\n") . "  public_html//a.php\n";
file_put_contents($odd . '/RELEASE_MANIFEST', $odd_body);
file_put_contents($odd . '/RELEASE_MANIFEST.sig', base64_encode(sodium_crypto_sign_detached($odd_body, $upstream['secret'])) . "\n");
$why = rfm_refusal(function () use ($odd, $authority) {
	TreeManifestPublisher::republish_artifact($odd, $odd, rfm_stage(), '', $authority);
});
check($why !== null && strpos($why, 'which is not a plain path') !== false, 'a listed path with an empty segment refuses', (string)$why);

$bare = rfm_tree(array('public_html/a.php' => "<?php\n"));
$why = rfm_refusal(function () use ($bare, $authority) {
	TreeManifestPublisher::republish_artifact($bare, $bare, rfm_stage(), '', $authority);
});
check($why !== null && strpos($why, 'holds no received manifest') !== false, 'no received manifest refuses', (string)$why);

foreach ($rfm_dirs as $d) {
	exec('rm -rf ' . escapeshellarg($d));
}

harness_finish();
