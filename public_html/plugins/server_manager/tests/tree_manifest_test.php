<?php
/** @joinery-test
 * name: tree_manifest
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The signed per-file release manifest (component G, spec §3.2).
 *
 * What this guards is a root-exec boundary: the site tree is writable by the web
 * user while the agent runs as root, so a primitive that invokes a script has to
 * know the script is the one the publisher shipped. Every property below is one
 * a wrong manifest would break silently — a manifest that verifies the wrong
 * thing is worse than none, because none refuses and a wrong one permits.
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('tests/lib/harness.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/TreeManifestPublisher.php'));

harness_boot();

/** A throwaway site tree. Returns [site_root, artifact_dir]. */
function tm_tree(array $files) {
	$root = sys_get_temp_dir() . '/tm_' . bin2hex(random_bytes(6));
	foreach ($files as $rel => $contents) {
		$abs = $root . '/' . $rel;
		@mkdir(dirname($abs), 0777, true);
		file_put_contents($abs, $contents);
	}
	return $root;
}

function tm_keys() {
	$pair = sodium_crypto_sign_keypair();
	return array(
		'secret' => sodium_crypto_sign_secretkey($pair),
		'public' => sodium_crypto_sign_publickey($pair),
	);
}

section('The manifest names files by their path from the site root');

// The scripts the agent most needs to verify live in BOTH trees, so a
// public_html-relative root could not name half of them. One root across every
// manifest also means the agent resolves a path the same way whichever artifact
// owns it.
$root = tm_tree(array(
	'public_html/utils/upgrade.php'                     => "<?php // upgrade\n",
	'maintenance_scripts/install_tools/install_agent.sh' => "#!/bin/bash\n",
));
$body = TreeManifestPublisher::build($root, $root);

check(strpos($body, '  public_html/utils/upgrade.php') !== false,
	'a public_html script is listed from the site root', $body);
check(strpos($body, '  maintenance_scripts/install_tools/install_agent.sh') !== false,
	'so is a maintenance_scripts one — the tree the agent runs most', $body);

section('The format is the one the agent parses');

// Fixed by primitives/manifest.go: two fields, 64 lowercase hex first. A format
// drift here is only discovered when a fleet of agents refuses to run anything.
foreach (explode("\n", trim($body)) as $line) {
	if ($line === '' || $line[0] === '#') continue;
	$fields = preg_split('/\s+/', trim($line));
	check(count($fields) === 2, 'each line is exactly two fields', $line);
	check(preg_match('/^[0-9a-f]{64}$/', $fields[0]) === 1,
		'the hash is 64 lowercase hex characters', $line);
}

section('Hashes are of the real bytes on disk');

$expected = hash_file('sha256', $root . '/public_html/utils/upgrade.php');
check(strpos($body, $expected . '  public_html/utils/upgrade.php') !== false,
	'the recorded hash is the file\'s actual sha256',
	'anything else and verification passes on a file nobody shipped');

section('The same tree always produces the same manifest');

// Byte-identical output is what lets a publish that changed nothing leave an
// artifact alone — and an artifact that churns every publish bumps its version
// every publish.
check(TreeManifestPublisher::build($root, $root) === $body,
	'building twice over an unchanged tree is byte-identical');

section('What is deliberately left out');

// Site-local mutable state is excluded because it is not shipped and differs on
// every node; listing it would make every manifest wrong the moment a site ran.
foreach (array(
	'config/Globalvars_site.php' => 'site config, which holds secrets and is per-node',
	'logs/error.log'             => 'logs',
	'cache/static_pages/x.html'  => 'generated cache',
	'uploads/photo.jpg'          => 'uploaded content',
	'public_html/.git/HEAD'      => 'git metadata at any depth',
	'specs/thing.md'             => 'specs, which do not ship',
) as $rel => $why) {
	check(TreeManifestPublisher::excluded($rel), 'excluded: ' . $why, $rel);
}

// A file cannot contain its own hash.
check(TreeManifestPublisher::excluded('RELEASE_MANIFEST'), 'the manifest excludes itself');
check(TreeManifestPublisher::excluded('RELEASE_MANIFEST.sig'), 'and its signature');

// ...but a plugin may legitimately ship a directory called config, and its
// scripts must still be verifiable. Only the site root's own config/ is site-local.
check(!TreeManifestPublisher::excluded('public_html/plugins/mailbox/config/defaults.php'),
	'a plugin\'s own config directory is still covered',
	'excluding it by name anywhere would silently unverify plugin files');
check(!TreeManifestPublisher::excluded('public_html/utils/upgrade.php'),
	'ordinary shipped files are covered');

section('The signature verifies, and is checked before it ships');

$keys = tm_keys();
$result = TreeManifestPublisher::write($root, $root, $keys);
check(is_file($root . '/RELEASE_MANIFEST'), 'the manifest is written into the artifact');
check(is_file($root . '/RELEASE_MANIFEST.sig'), 'with a detached signature beside it');

$written_body = file_get_contents($root . '/RELEASE_MANIFEST');
$signature = base64_decode(trim(file_get_contents($root . '/RELEASE_MANIFEST.sig')));
check(sodium_crypto_sign_verify_detached($signature, $written_body, $keys['public']),
	'the shipped signature verifies against the release public key');

// The manifest excludes itself, so writing it must not have changed what it says.
check($written_body === $body,
	'writing the manifest does not invalidate the manifest',
	'if the manifest listed itself, its own hash would change as it was written');

section('A tampered file stops verifying');

// The whole point, stated as a test: change a byte, and the recorded hash no
// longer matches what is on disk.
file_put_contents($root . '/public_html/utils/upgrade.php', "<?php // tampered\n");
$after = hash_file('sha256', $root . '/public_html/utils/upgrade.php');
check(strpos($written_body, $after) === false,
	'a modified file no longer matches its signed hash');

exec('rm -rf ' . escapeshellarg($root));

section('Only the site that built the shipped agent may sign');

// The agent verifies against the key compiled into its binary. A site that
// received its agent from upstream holds upstream's key in that binary and its
// own key in config/; a manifest it signs is one its own agent refuses.
$own = 'OWN_KEY_B64'; $upstream = 'UPSTREAM_KEY_B64';
check(TreeManifestPublisher::maySign($own, $own, false)['may_sign'],
	'a bundle built with this site\'s key: sign');
check(!TreeManifestPublisher::maySign($upstream, $own, true)['may_sign'],
	'a bundle built with another site\'s key: never sign, even with the source on this box');
check(TreeManifestPublisher::maySign(null, $own, true)['may_sign'],
	'a bundle that predates the key record, on the box that holds the source: it was built here, sign');
check(!TreeManifestPublisher::maySign(null, $own, false)['may_sign'],
	'a bundle that predates the key record, on a box without the source: it was received, do not sign');

section('A site that may not sign carries the received manifest forward');

$upstream_keys = tm_keys();
$own_keys      = tm_keys();
$authority = array(
	'may_sign'       => false,
	'keys'           => null,
	'own_public_b64' => base64_encode($own_keys['public']),
	'bundle_key_b64' => base64_encode($upstream_keys['public']),
	'reason'         => 'test: cannot sign',
);

// The live tree, exactly as upstream delivered it: upstream's signed manifest at the root.
$site = tm_tree(array('public_html/utils/upgrade.php' => "<?php // upgrade\n"));
TreeManifestPublisher::write($site, $site, $upstream_keys);
$received = file_get_contents($site . '/RELEASE_MANIFEST');
// The staged core tree that will ship.
$staged = tm_tree(array('public_html/utils/upgrade.php' => "<?php // upgrade\n"));

$r = TreeManifestPublisher::publish_artifact($staged, $staged, $authority, $site);
check($r['carried'] === true, 'the result says the manifest was carried, not signed');
check(file_get_contents($staged . '/RELEASE_MANIFEST') === $received,
	'the staged archive ships upstream\'s manifest byte for byte');
check(sodium_crypto_sign_verify_detached(
		base64_decode(trim(file_get_contents($staged . '/RELEASE_MANIFEST.sig'))),
		$received, $upstream_keys['public']),
	'and upstream\'s signature, which the shipped agent verifies');
check(file_get_contents($site . '/RELEASE_MANIFEST') === $received,
	'the live tree is left exactly as delivered');

// The state that broke getjoinery: a manifest this site signed itself, sitting
// in its live tree. Carrying it forward would republish the breakage.
TreeManifestPublisher::write($site, $site, $own_keys);
$threw = '';
try { TreeManifestPublisher::publish_artifact($staged, $staged, $authority, $site); }
catch (Exception $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'own key') !== false,
	'a manifest signed with this site\'s own key is refused, naming the cause', $threw);

// A manifest signed by neither key is not upstream's either.
TreeManifestPublisher::write($site, $site, tm_keys());
$threw = '';
try { TreeManifestPublisher::publish_artifact($staged, $staged, $authority, $site); }
catch (Exception $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'does not verify') !== false,
	'a manifest the shipped agent would not verify is refused', $threw);

// Nothing received at all: there is nothing honest to ship.
unlink($site . '/RELEASE_MANIFEST'); unlink($site . '/RELEASE_MANIFEST.sig');
$threw = '';
try { TreeManifestPublisher::publish_artifact($staged, $staged, $authority, $site); }
catch (Exception $e) { $threw = $e->getMessage(); }
check(strpos($threw, 'no received manifest') !== false,
	'with no received manifest the publish refuses rather than shipping unsigned', $threw);

// A site that may sign still signs, with its own key.
$r = TreeManifestPublisher::publish_artifact($staged, $staged, array(
	'may_sign' => true, 'keys' => $own_keys, 'own_public_b64' => base64_encode($own_keys['public']),
	'bundle_key_b64' => base64_encode($own_keys['public']), 'reason' => 'test: may sign'));
check($r['carried'] === false && sodium_crypto_sign_verify_detached(
		base64_decode(trim(file_get_contents($staged . '/RELEASE_MANIFEST.sig'))),
		file_get_contents($staged . '/RELEASE_MANIFEST'), $own_keys['public']),
	'a site that may sign writes a fresh manifest under its own key');

exec('rm -rf ' . escapeshellarg($site) . ' ' . escapeshellarg($staged));

harness_finish();
