<?php
/** @joinery-test
 * name: package_signature
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * What root will put on the box (specs/package_signing.md WP2).
 *
 * Code reaches a node's tree only when it was built by us. The whole claim
 * rests on one class: PackageSignature::verify() says `signed` for exactly the
 * bytes a manifest our key signed describes, and something else — with a
 * sentence — for everything that is not that. Each verdict below is a way an
 * archive could be wrong that a wrong verifier would wave through.
 *
 * A throwaway keypair is minted here and never printed. The fixture tree is a
 * signed plugin, the same shape publish_upgrade.php ships (paths relative to
 * the site root, manifest at the artifact's root), signed with the real writer.
 *
 * Run: php tests/run.php --only=tests/security/package_signature_test.php
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

// The fixtures are signed by the real writer, so the reader is held to what
// the publisher actually ships. The writer lives in the server_manager plugin;
// a box where it is inactive has nothing to sign with.
if (!class_exists('TreeManifestPublisher')) {
	harness_skip('TreeManifestPublisher is not available here (server_manager inactive)');
	harness_finish();
}

$work = harness_scratch_dir('package_signature');
$rmtree = function ($p) use (&$rmtree) {
	foreach (glob(rtrim($p, '/') . '/{,.}*', GLOB_BRACE) ?: array() as $f) {
		if (basename($f) === '.' || basename($f) === '..') continue;
		is_dir($f) && !is_link($f) ? $rmtree($f) : @unlink($f);
	}
	@rmdir($p);
};
$rmtree($work);
@mkdir($work, 0770, true);

// A throwaway key. The secret half lives in this process and nowhere else.
$pair = sodium_crypto_sign_keypair();
$keys = array('secret' => sodium_crypto_sign_secretkey($pair), 'public' => sodium_crypto_sign_publickey($pair));
$keys_file = $work . '/release_verify_keys';
file_put_contents($keys_file, "# test keys\n\n" . base64_encode($keys['public']) . "\n");

/** A signed plugin tree under a throwaway site root. Returns [site_root, plugin_dir]. */
$signed_plugin = function (string $label, array $files) use ($work, $keys): array {
	$site = $work . '/' . $label;
	$plugin = $site . '/public_html/plugins/fixture';
	foreach ($files as $rel => $bytes) {
		@mkdir(dirname($plugin . '/' . $rel), 0770, true);
		file_put_contents($plugin . '/' . $rel, $bytes);
	}
	TreeManifestPublisher::write($plugin, $site, $keys);
	return array($site, $plugin);
};
$files = array(
	'plugin.json'            => json_encode(array('name' => 'fixture', 'version' => '1.0.0')),
	'includes/Fixture.php'   => "<?php class Fixture {}\n",
	'vendor/autoload.php'    => "<?php // composer\n",
	'config/defaults.php'    => "<?php return array();\n",
);

section('A package we built verifies');

list($site, $plugin) = $signed_plugin('good', $files);
$v = PackageSignature::verify($plugin, $keys_file);
check($v->signed(), 'a signed, untouched package is `signed`', $v->line());
check($v->root === 'public_html/plugins/fixture', 'and the verdict names the directory the manifest describes', $v->root);
check($v->files === 4, 'every file was checked, the plugin\'s vendor/ and config/ included', (string)$v->files);
check($v->key === base64_encode($keys['public']), 'and says which key it verified against');

section('Every way the bytes can be wrong is a distinct verdict');

// One byte. The whole point, stated as a test.
list($site, $plugin) = $signed_plugin('tampered', $files);
file_put_contents($plugin . '/includes/Fixture.php', "<?php class Fixture { /* x */ }\n");
$v = PackageSignature::verify($plugin, $keys_file);
check($v->verdict === PackageSignature::TAMPERED, 'a changed file is `tampered`', $v->line());
check($v->file === 'includes/Fixture.php', 'and the verdict names the file', (string)$v->file);

// A file beside the signed set. This is the one a hash-only check misses: every
// listed file is fine, and the archive still carries something nobody signed.
list($site, $plugin) = $signed_plugin('extra', $files);
file_put_contents($plugin . '/includes/Extra.php', "<?php\n");
$v = PackageSignature::verify($plugin, $keys_file);
check($v->verdict === PackageSignature::EXTRA_FILE, 'an unlisted file is `extra_file`', $v->line());
check($v->file === 'includes/Extra.php', 'and is named', (string)$v->file);

// Unsigned code inside vendor/ is the B3 case: with vendor listed, it is caught.
list($site, $plugin) = $signed_plugin('vendor_extra', $files);
file_put_contents($plugin . '/vendor/evil.php', "<?php\n");
$v = PackageSignature::verify($plugin, $keys_file);
check($v->verdict === PackageSignature::EXTRA_FILE, 'an unlisted file under the plugin\'s vendor/ is caught too', $v->line());

list($site, $plugin) = $signed_plugin('missing', $files);
unlink($plugin . '/vendor/autoload.php');
$v = PackageSignature::verify($plugin, $keys_file);
check($v->verdict === PackageSignature::MISSING_FILE, 'an absent listed file is `missing_file`', $v->line());

// A symlink is not a file we signed, whatever it points at.
list($site, $plugin) = $signed_plugin('link', $files);
symlink('/etc/hostname', $plugin . '/includes/link.php');
$v = PackageSignature::verify($plugin, $keys_file);
check($v->verdict === PackageSignature::EXTRA_FILE, 'a symlink is refused as an extra file', $v->line());

// Paths a manifest never lists are neither required nor counted against the
// package: a cache/ directory a plugin left behind, its own .gitignore.
list($site, $plugin) = $signed_plugin('excluded', $files);
@mkdir($plugin . '/cache', 0770, true);
file_put_contents($plugin . '/cache/x', 'x');
file_put_contents($plugin . '/.gitignore', "cache\n");
$v = PackageSignature::verify($plugin, $keys_file);
check($v->signed(), 'files the manifest rule never lists do not count as extra', $v->line());

section('Every way the signature can be wrong is a distinct verdict');

list($site, $plugin) = $signed_plugin('unsigned', $files);
unlink($plugin . '/RELEASE_MANIFEST.sig');
$v = PackageSignature::verify($plugin, $keys_file);
check($v->verdict === PackageSignature::UNSIGNED, 'no signature is `unsigned`', $v->line());
unlink($plugin . '/RELEASE_MANIFEST');
$v = PackageSignature::verify($plugin, $keys_file);
check($v->verdict === PackageSignature::UNSIGNED, 'no manifest is `unsigned`', $v->line());

// Signed, but by somebody else. A stranger's key is the marketplace-of-one
// case this whole spec is about.
$stranger = sodium_crypto_sign_keypair();
list($site, $plugin) = $signed_plugin('stranger', $files);
TreeManifestPublisher::write($plugin, $site, array(
	'secret' => sodium_crypto_sign_secretkey($stranger), 'public' => sodium_crypto_sign_publickey($stranger)));
$v = PackageSignature::verify($plugin, $keys_file);
check($v->verdict === PackageSignature::UNKNOWN_KEY, 'a signature by a key we do not hold is `unknown_key`', $v->line());

// The manifest's own bytes altered after signing: the signature no longer
// covers them, which reads as an unknown key — there is no key it verifies under.
list($site, $plugin) = $signed_plugin('edited_manifest', $files);
$body = file_get_contents($plugin . '/RELEASE_MANIFEST');
file_put_contents($plugin . '/RELEASE_MANIFEST', str_replace('includes/Fixture.php', 'includes/Other.php', $body));
$v = PackageSignature::verify($plugin, $keys_file);
check($v->verdict === PackageSignature::UNKNOWN_KEY, 'an edited manifest no longer verifies under any key', $v->line());

// No key file, or an empty one: nothing can be verified, and that is its own
// answer rather than a silent pass or a misleading "unknown key".
list($site, $plugin) = $signed_plugin('nokeys', $files);
$v = PackageSignature::verify($plugin, $work . '/no_such_file');
check($v->verdict === PackageSignature::NO_KEYS, 'no key file is `no_keys`', $v->line());
$empty = $work . '/empty_keys';
file_put_contents($empty, "# nothing here\n\n");
$v = PackageSignature::verify($plugin, $empty);
check($v->verdict === PackageSignature::NO_KEYS, 'an empty key file is `no_keys`', $v->line());
$garbage = $work . '/garbage_keys';
file_put_contents($garbage, "not-a-key\n");
$v = PackageSignature::verify($plugin, $garbage);
check($v->verdict === PackageSignature::NO_KEYS, 'a key file with no usable key is `no_keys`', $v->line());

// A manifest is only read AFTER its signature is good, so a malformed one that
// is signed reads as unreadable, and an unsigned malformed one as unsigned.
list($site, $plugin) = $signed_plugin('garbled', $files);
file_put_contents($plugin . '/RELEASE_MANIFEST.sig', "AAAA\n");
$v = PackageSignature::verify($plugin, $keys_file);
check($v->verdict === PackageSignature::UNREADABLE, 'a signature that is not a signature is `unreadable`', $v->line());

section('More than one key, and the key file\'s own format');

// The stable channel will ship a second key; the file is one key per line,
// and a package signed by any of them verifies.
$two = $work . '/two_keys';
file_put_contents($two, base64_encode(sodium_crypto_sign_publickey($stranger)) . "\n" . base64_encode($keys['public']) . "\n");
list($site, $plugin) = $signed_plugin('two', $files);
$v = PackageSignature::verify($plugin, $two);
check($v->signed(), 'a package signed by the second key in the file verifies', $v->line());
check(count(PackageSignature::readKeys($two)) === 2, 'both keys are read');
$dup = $work . '/dup_keys';
file_put_contents($dup, base64_encode($keys['public']) . "\n" . base64_encode($keys['public']) . "\n  \nbad line\n");
check(count(PackageSignature::readKeys($dup)) === 1, 'a repeated key counts once and a bad line is skipped');

section('The node\'s own key file is trusted only when root could have written it');

// A key file the web user can write is a key file the web user can add a key
// to, after which every `signed` answer is theirs. The same guard the host
// converger puts on the scripts it runs: root's or the tree owner's, and no
// write bit for anyone else.
$me = (int)posix_geteuid();
chmod($keys_file, 0644);
check(PackageSignature::keyFileRefusal($keys_file, $me) === '', 'the tree owner\'s 0644 file is trusted');
chmod($keys_file, 0664);
check(PackageSignature::keyFileRefusal($keys_file, $me) !== '', 'a group-writable key file is refused');
chmod($keys_file, 0646);
check(PackageSignature::keyFileRefusal($keys_file, $me) !== '', 'an other-writable key file is refused');
chmod($keys_file, 0644);
if ($me !== 0) {
	check(PackageSignature::keyFileRefusal($keys_file, $me + 1) !== '',
		'a key file owned by neither root nor the tree owner is refused');
}
check(PackageSignature::keyFileRefusal($work . '/no_such_file', $me) !== '', 'a file that cannot be stat\'d is refused');

section('The manifest names its own directory');

// A manifest that describes some other directory is not this package, even
// when every hash in it is right for the bytes on disk.
list($site, $plugin) = $signed_plugin('renamed', $files);
rename($plugin, $site . '/public_html/plugins/other');
$v = PackageSignature::verify($site . '/public_html/plugins/other', $keys_file);
check($v->verdict === PackageSignature::UNREADABLE && strpos($v->detail, 'public_html/plugins/fixture') !== false,
	'a package whose manifest describes another directory is refused, naming both', $v->line());

// A site-root archive (the core) spans public_html/ and maintenance_scripts/,
// so its root is '' and its config/ template is outside the promise.
$core = $work . '/core';
@mkdir($core . '/public_html/utils', 0770, true);
@mkdir($core . '/maintenance_scripts/install_tools', 0770, true);
@mkdir($core . '/config', 0770, true);
file_put_contents($core . '/public_html/utils/upgrade.php', "<?php\n");
file_put_contents($core . '/maintenance_scripts/install_tools/install.sh', "#!/bin/bash\n");
file_put_contents($core . '/config/default_Globalvars_site.php', "<?php\n");
TreeManifestPublisher::write($core, $core, $keys);
$v = PackageSignature::verify($core, $keys_file);
check($v->signed() && $v->root === '', 'a core archive verifies with an empty root', $v->line() . ' root=' . $v->root);

section('The parser refuses a listing that reaches outside itself');

check(PackageSignature::parse("abc\n") === null, 'a line that is not two fields is refused');
check(PackageSignature::parse(str_repeat('a', 64) . "  ../etc/passwd\n") === null, 'a path with .. is refused');
check(PackageSignature::parse(str_repeat('a', 64) . "  /etc/passwd\n") === null, 'an absolute path is refused');
check(PackageSignature::parse("# comment\n\n" . str_repeat('a', 64) . "  public_html/x.php\n") === array('public_html/x.php' => str_repeat('a', 64)),
	'comments and blank lines are skipped, and the entry is path => hash');
check(PackageSignature::commonRoot(array('public_html/a', 'maintenance_scripts/b')) === '', 'a core listing has no common root');
check(PackageSignature::commonRoot(array('public_html/plugins/x/plugin.json')) === 'public_html/plugins/x',
	'a one-file listing\'s root is its directory');

$rmtree($work);

harness_finish();
