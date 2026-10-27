<?php
/** @joinery-test
 * name: release_commit
 * tier: safe
 * env: any
 * needs: []
 * covers: [maintenance_scripts/install_tools/joinery_jail/go.mod, public_html/plugins/mailbox/provisioning/relay-sealer/go.mod, public_html/plugins/server_manager/includes/publish_upgrade.php, public_html/includes/*Publisher.php, public_html/plugins/**Publisher.php]
 */
/**
 * A release is a commit (spec release_transparency D1, D2, D3):
 *
 *  - which untracked files stop a publish: the ones that would ship, and only
 *    those (ReleaseCommit::joineryPathShips)
 *  - what git status becomes once it is read (ReleaseCommit::status,
 *    joineryBlockers, agentBlockers), against a throwaway repository built
 *    here, so no state of the real repository leaks into the result
 *  - what ships: a file git knows or one publish builds, never an ignored one
 *    (ReleaseCommit::knownFiles, joineryFileShips); the manifest built with
 *    that rule, and the archive cut from the manifest's listing
 *    (TreeManifestPublisher::archiveMembers) verifies as a fresh archive
 *  - what publish commits itself (ReleaseCommit::publishWrite, commitRelease):
 *    its own files only, never what else is staged, and pushed
 *  - the Go toolchain pin is read from go.mod and an unpinned tree is refused
 *    (GoBinaryPublisher::pinnedToolchain, officialGo)
 *  - the repository's key lists are read from release_keys/ and a signing
 *    key not listed there is refused (AgentDistPublisher)
 *
 * Runs offline, no DB. Run: php tests/unit/release_commit_test.php
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/AgentDistPublisher.php'));

$tmp = sys_get_temp_dir() . '/release_commit_test_' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp));
mkdir($tmp, 0755, true);

// Every repository this test runs git in is a throwaway one under $tmp: it
// commits, pushes and rewrites remotes there. Pointed anywhere else (a check
// that reused $repo for the real tree did, on 2026-10-14) it would do all of
// that to a real repository, so it stops instead.
function rc_git($repo, $args) {
	global $tmp;
	if ((string)$repo !== $tmp && strpos((string)$repo, $tmp . '/') !== 0) {
		fwrite(STDERR, "release_commit_test: refusing to run git in {$repo}, which is not under {$tmp}\n");
		exit(1);
	}
	return ReleaseCommit::git($repo, $args);
}

// ---------------------------------------------------------------------------
section('Which untracked paths would ship');

$ships = array(
	'public_html/includes/Foo.php'                                 => true,
	'public_html/gw.sh'                                            => true,
	'public_html/plugins/mailbox/includes/X.php'                   => true,
	'public_html/theme/getjoinery/views/x.php'                     => true,
	'maintenance_scripts/install_tools/joinery-install.sql.gz'     => true,
	'maintenance_scripts/sysadmin_tools/x.sh'                      => true,
	'LICENSE.md'                                                   => true,
	'public_html/specs/draft.md'                                   => false,
	'public_html/uploads/x.png'                                    => false,
	'public_html/cache/x'                                          => false,
	'public_html/logs/x.log'                                       => false,
	'public_html/backups/x'                                        => false,
	'public_html/.claude/x'                                        => false,
	'public_html/includes/cache/x.php'                             => false,
	'public_html/RELEASE_MANIFEST'                                 => false,
	'maintenance_scripts/dev_tools/x.php'                          => false,
	'strategy/notes.md'                                            => false,
	'incidents/x.md'                                               => false,
	'vendor/x.php'                                                 => false,
	'config/Globalvars_site.php'                                   => false,
);
foreach ($ships as $path => $expected) {
	check(ReleaseCommit::joineryPathShips($path) === $expected,
		($expected ? 'ships:      ' : 'never ships: ') . $path);
}

// ---------------------------------------------------------------------------
section('git status, read from a throwaway repository');

$repo = $tmp . '/repo';
mkdir($repo . '/public_html/includes', 0755, true);
mkdir($repo . '/public_html/specs', 0755, true);
$init = rc_git($repo, array('init', '-q', '-b', 'main'));
check($init['exit'] === 0, 'git init works here', implode(' | ', $init['out']));
rc_git($repo, array('config', 'user.email', 'test@example.com'));
rc_git($repo, array('config', 'user.name', 'test'));
file_put_contents($repo . '/public_html/includes/A.php', "<?php\n");
file_put_contents($repo . '/public_html/VERSION', "0.0.1\n");
rc_git($repo, array('add', '-A'));
rc_git($repo, array('commit', '-q', '-m', 'first'));

check(ReleaseCommit::joineryBlockers($repo) === array(), 'a committed tree has no blockers',
	json_encode(ReleaseCommit::joineryBlockers($repo)));
check(preg_match('/^[0-9a-f]{40}$/', (string)ReleaseCommit::head($repo)) === 1, 'head() is a full commit id');

file_put_contents($repo . '/public_html/VERSION', "0.0.2\n");                  // modified, tracked
file_put_contents($repo . '/public_html/includes/B.php', "<?php\n");          // untracked, ships
file_put_contents($repo . '/public_html/specs/draft.md', "# draft\n");        // untracked, never ships
$blockers = ReleaseCommit::joineryBlockers($repo);
check($blockers === array('public_html/VERSION', 'public_html/includes/B.php'),
	'a modified tracked file and an untracked shipping file block; an untracked spec does not',
	json_encode($blockers));

$status = ReleaseCommit::status($repo);
check(in_array('public_html/specs/draft.md', $status['untracked'], true), 'status() still reports the spec as untracked');

unlink($repo . '/public_html/includes/A.php');                                 // deleted, tracked
$blockers = ReleaseCommit::joineryBlockers($repo);
check(in_array('public_html/includes/A.php', $blockers, true), 'a deleted tracked file blocks', json_encode($blockers));

check(ReleaseCommit::agentBlockers($repo) === array(
		'public_html/VERSION', 'public_html/includes/A.php', 'public_html/includes/B.php', 'public_html/specs/draft.md'),
	'the agent rule blocks on every untracked file, specs included', json_encode(ReleaseCommit::agentBlockers($repo)));

check(ReleaseCommit::status($tmp . '/not-a-repo') === null, 'a directory that is not a repository reads as null, not as clean');
check(ReleaseCommit::joineryBlockers($tmp . '/not-a-repo') === null, 'joineryBlockers() passes the null through');

// ---------------------------------------------------------------------------
section('Only what git knows, or publish builds, ships');

// The release 0.8.466 core archive carried 196 working screenshots, one of
// them a user list, and a local email corpus; its themes carried images no
// commit held. An ignored file must never reach a node, whatever directory
// it sits in, and the manifest and the archive must agree on that.
$shipr = $tmp . '/shipr';
mkdir($shipr . '/public_html/theme/t/assets', 0755, true);
mkdir($shipr . '/public_html/plugins/mailbox/provisioning/bin', 0755, true);
mkdir($shipr . '/public_html/plugins/mailbox/provisioning/relay-sealer', 0755, true);
mkdir($shipr . '/public_html/agent_dist', 0755, true);
rc_git($shipr, array('init', '-q', '-b', 'main'));
rc_git($shipr, array('config', 'user.email', 'test@example.com'));
rc_git($shipr, array('config', 'user.name', 'test'));
file_put_contents($shipr . '/.gitignore', "/public_html/*.png\n/public_html/agent_dist/\n/public_html/plugins/mailbox/provisioning/bin/\n/public_html/plugins/mailbox/provisioning/relay-sealer/relay-sealer\n");
file_put_contents($shipr . '/public_html/serve.php', "<?php\n");
mkdir($shipr . '/maintenance_scripts/install_tools', 0755, true);
file_put_contents($shipr . '/maintenance_scripts/install_tools/default_Globalvars_site.php', "<?php // template\n");
file_put_contents($shipr . '/public_html/theme/t/assets/logo.png', 'logo');
file_put_contents($shipr . '/public_html/theme/t/assets/a b.css', 'x');
rc_git($shipr, array('add', '-A'));
rc_git($shipr, array('commit', '-q', '-m', 'first'));
file_put_contents($shipr . '/public_html/admin_users.png', 'screenshot');                       // ignored
file_put_contents($shipr . '/public_html/plugins/mailbox/provisioning/relay-sealer/relay-sealer', 'stray');  // ignored build output
file_put_contents($shipr . '/public_html/plugins/mailbox/provisioning/bin/relay-sealer-x86_64', 'built');    // ignored, built by publish
file_put_contents($shipr . '/public_html/agent_dist/manifest.json', '{}');                       // ignored, built by publish
file_put_contents($shipr . '/public_html/new.php', "<?php\n");                                   // untracked, not ignored

$known = ReleaseCommit::knownFiles($shipr);
check(is_array($known) && isset($known['public_html/serve.php'], $known['public_html/theme/t/assets/logo.png']),
	'knownFiles() holds the tracked files', json_encode($known));
check(isset($known['public_html/theme/t/assets/a b.css']), 'a path with a space is read whole');
check(isset($known['public_html/new.php']), 'an untracked file no rule ignores is known (and blocks a publish)');
check(!isset($known['public_html/admin_users.png']), 'an ignored file is not known');
check(ReleaseCommit::knownFiles($tmp . '/not-a-repo') === null, 'a directory that is not a repository reads as null');

$file_ships = array(
	'public_html/serve.php'                                              => true,
	'public_html/theme/t/assets/logo.png'                                => true,
	'public_html/admin_users.png'                                        => false,
	'public_html/plugins/mailbox/provisioning/relay-sealer/relay-sealer' => false,
	'public_html/plugins/mailbox/provisioning/bin/relay-sealer-x86_64'   => true,
	'public_html/plugins/mail_apps/provisioning/bin/mail-gateway-aarch64' => true,
	'public_html/plugins/mail_apps/provisioning/mail-gateway/mail-gateway' => false,
	'public_html/agent_dist/manifest.json'                               => true,
	'public_html/agent_dist.old/manifest.json'                           => false,
	'public_html/LICENSE.md'                                             => true,
	'public_html/RELEASE_STATEMENT'                                      => true,
	'public_html/theme/t/RELEASE_STATEMENT'                              => true,
);
foreach ($file_ships as $path => $expected) {
	check(ReleaseCommit::joineryFileShips($path, $known) === $expected,
		($expected ? 'ships:      ' : 'never ships: ') . $path);
}

$ships = function ($rel) use ($known) { return ReleaseCommit::joineryFileShips($rel, $known); };
$body = TreeManifestPublisher::build($shipr, $shipr, $ships);
check(strpos($body, 'public_html/admin_users.png') === false && strpos($body, 'relay-sealer/relay-sealer') === false,
	'the manifest built with the rule lists no ignored file');
check(strpos($body, 'public_html/theme/t/assets/logo.png') !== false && strpos($body, 'public_html/agent_dist/manifest.json') !== false,
	'and lists the committed and the built ones');
check(strpos(TreeManifestPublisher::build($shipr, $shipr), 'public_html/admin_users.png') !== false,
	'without the rule (a live tree checked in place) every file is listed');

// The archive is cut from the manifest, not the directory.
$pair = sodium_crypto_sign_keypair();
$keys = array('secret' => sodium_crypto_sign_secretkey($pair), 'public' => sodium_crypto_sign_publickey($pair));
$keys_file = $tmp . '/release_verify_keys';
file_put_contents($keys_file, base64_encode($keys['public']) . "\n");
TreeManifestPublisher::write($shipr, $shipr, $keys, $ships);
mkdir($shipr . '/config', 0755, true);
copy($shipr . '/maintenance_scripts/install_tools/default_Globalvars_site.php', $shipr . '/config/default_Globalvars_site.php');
$members = TreeManifestPublisher::archiveMembers($shipr, '.', '');
check(!in_array('./public_html/admin_users.png', $members, true), 'the core archive leaves the screenshot out');
foreach (array('./RELEASE_MANIFEST', './RELEASE_MANIFEST.sig', './config/default_Globalvars_site.php',
		'./public_html/theme', './public_html/plugins', './public_html/theme/t/assets', './public_html/theme/t/assets/a b.css') as $want) {
	check(in_array($want, $members, true), 'the core archive carries ' . $want);
}
$tarball = $tmp . '/core.tar.gz';
$list = $tmp . '/members';
file_put_contents($list, implode("\0", $members) . "\0");
exec(sprintf('tar -czf %s --null --no-recursion -C %s -T %s 2>&1', escapeshellarg($tarball), escapeshellarg($shipr), escapeshellarg($list)), $o, $x);
$unpacked = $tmp . '/unpacked';
mkdir($unpacked);
exec(sprintf('tar -xzf %s -C %s 2>&1', escapeshellarg($tarball), escapeshellarg($unpacked)), $o, $x2);
$verdict = PackageSignature::verify($unpacked, $keys_file, array('fresh' => true));
check($x === 0 && $x2 === 0 && !is_file($unpacked . '/public_html/admin_users.png'), 'a tar of that list unpacks without the screenshot');
check($verdict->signed(), 'and the unpacked archive verifies as a fresh signed archive', $verdict->verdict . ': ' . $verdict->detail);

mkdir($shipr . '/public_html/plugins/p', 0755, true);
file_put_contents($shipr . '/public_html/plugins/p/plugin.json', '{}');
file_put_contents($shipr . '/public_html/plugins/p/shot.png', 'ignored here');
TreeManifestPublisher::write($shipr . '/public_html/plugins/p', $shipr, $keys, function ($rel) { return substr($rel, -4) !== '.png'; });
$members = TreeManifestPublisher::archiveMembers($shipr . '/public_html/plugins', 'p', 'public_html/plugins/p');
check($members === array('p', 'p/RELEASE_MANIFEST', 'p/RELEASE_MANIFEST.sig', 'p/plugin.json'),
	'a plugin archive is its directory, its manifest and what that lists', json_encode($members));
check(TreeManifestPublisher::archiveMembers($shipr . '/public_html/plugins', 'p', 'public_html/plugins/q') === null,
	'a manifest describing another directory gives no member list');
check(TreeManifestPublisher::archiveMembers($tmp, 'nothing', 'public_html/plugins/nothing') === null,
	'no manifest, no member list');

// ---------------------------------------------------------------------------
section('A release is built from the commit; only a held path refuses');

// Several sessions share one working tree. A release is built from the
// commit, so one session's uncommitted edit is left out instead of stopping
// another's release - except where the release reads this box rather than
// the commit, which still refuses (spec parallel_sessions_release_flow I4).
$held = array(
	'public_html/data/users_class.php'                                  => true,  // schema the install SQL is dumped with
	'public_html/migrations/migrations.php'                             => true,
	'public_html/plugins/store/data/orders_class.php'                   => true,
	'public_html/plugins/boards/migrations/m1.php'                      => true,
	'public_html/plugins/store/plugin.json'                             => true,  // publish writes the version bump here
	'public_html/theme/default/theme.json'                              => true,
	'public_html/settings.json'                                         => true,
	'public_html/admin_menus.json'                                      => true,
	'public_html/install_bundles.json'                                  => true,
	'public_html/utils/create_install_sql.php'                          => true,
	'maintenance_scripts/install_tools/release_keys/release/k.pub'      => true,  // copied into the agent bundle
	'maintenance_scripts/install_tools/joinery_jail/src/main.rs'        => true,  // compiled on this box
	'maintenance_scripts/install_tools/site_engine/versions.env'        => true,  // the engine is built from it here
	'public_html/plugins/mailbox/provisioning/relay-sealer/src/main.rs' => true,
	// publish's own code runs from this disk and decides what the release holds (B70)
	'public_html/plugins/server_manager/includes/publish_upgrade.php'   => true,
	'public_html/includes/ReleaseCommit.php'                            => true,
	'public_html/plugins/server_manager/includes/SupportBundlePublisher.php' => true,
	'public_html/includes/ParserJailPublisher.php'                      => true,
	'public_html/plugins/mailbox/includes/RelaySealerPublisher.php'     => true,
	'public_html/plugins/server_manager/includes/JobCommandBuilder.php' => false,
	'public_html/plugins/server_manager/views/admin/publisher.php'      => false,
	'public_html/includes/DriveHelper.php'                              => false,
	'public_html/logic/drive_trash_logic.php'                           => false,
	'public_html/plugins/store/includes/Cart.php'                       => false,
	'public_html/plugins/store/data_export.php'                         => false,
	'public_html/theme/default/views/index.php'                         => false,
	'maintenance_scripts/install_tools/_plugin_installers_start.sh'     => false,
	'public_html/utils/upgrade.php'                                     => false,
);
foreach ($held as $path => $expected) {
	check(ReleaseCommit::heldPath($path) === $expected, ($expected ? 'held:      ' : 'left out:  ') . $path);
}
// Every publisher in the tree, the next one too, is held without a list to keep.
// Only read here: $repo below is the throwaway repository, never this one.
$publisher_root = dirname(PathHelper::getIncludePath(''));
$publishers = array_merge(glob($publisher_root . '/public_html/includes/*Publisher.php') ?: array(),
	glob($publisher_root . '/public_html/plugins/*/includes/*Publisher.php') ?: array());
$not_held = array();
foreach ($publishers as $p) {
	if (!ReleaseCommit::heldPath(substr($p, strlen($publisher_root) + 1))) {
		$not_held[] = substr($p, strlen($publisher_root) + 1);
	}
}
check(count($publishers) >= 10 && $not_held === array(), 'every *Publisher class in the tree is held (' . count($publishers) . ')', json_encode($not_held));
check(strpos(implode(' ', ReleaseCommit::JOINERY_HELD_DIRS), ParserJailPublisher::SOURCE_SUBDIR . '/') !== false,
	'the parser jail source held is the one its publisher compiles');
check(in_array(SandboxHostPublisher::SOURCE_SUBDIR . '/', ReleaseCommit::JOINERY_HELD_DIRS, true)
	&& ReleaseCommit::publishWrite(dirname(PathHelper::getIncludePath('includes')), SandboxHostPublisher::BIN_SUBDIR . '/sandbox-host-x86_64'),
	'the sandboxed plugin host source is held, and its binaries are publish\'s to commit');
$publish_source = (string)file_get_contents(PathHelper::getIncludePath('plugins/server_manager/includes/publish_upgrade.php'));
check(strpos($publish_source, 'SandboxHostPublisher::publish($full_site_dir') !== false
	&& strpos($publish_source, 'SandboxHostPublisher::STATUS_FAILED') !== false, 'and publish builds it, refusing a release whose host is stale');
check(in_array(SiteEnginePublisher::SOURCE_SUBDIR . '/', ReleaseCommit::JOINERY_HELD_DIRS, true),
	'the site container engine source held is the one its publisher builds');
$plugin_publishers = GoBinaryPublisher::pluginPublishers();
check(in_array('RelaySealerPublisher', $plugin_publishers, true) && in_array('MailGatewayPublisher', $plugin_publishers, true),
	'the relay sealer and the mail gateway are both plugin Go programs publish builds', json_encode($plugin_publishers));
foreach ($plugin_publishers as $publisher) {
	check(in_array($publisher::SOURCE_SUBDIR . '/', ReleaseCommit::JOINERY_HELD_DIRS, true),
		'  ' . $publisher::LABEL . ': the source held is the one its publisher compiles');
	check(in_array($publisher::BIN_SUBDIR . '/', ReleaseCommit::JOINERY_GENERATED_DIRS, true),
		'  ' . $publisher::LABEL . ': the bin/ it writes ships as a generated directory');
	check($publisher::artifactName('x86_64') === $publisher::BINARY . '/x86_64',
		'  ' . $publisher::LABEL . ': the statement names its binaries ' . $publisher::BINARY . '/<uname -m>');
}

$buildr = $tmp . '/buildr';
mkdir($buildr . '/public_html/includes', 0755, true);
mkdir($buildr . '/public_html/data', 0755, true);
mkdir($buildr . '/maintenance_scripts/install_tools', 0755, true);
mkdir($buildr . '/strategy', 0755, true);
rc_git($buildr, array('init', '-q', '-b', 'main'));
rc_git($buildr, array('config', 'user.email', 'test@example.com'));
rc_git($buildr, array('config', 'user.name', 'test'));
file_put_contents($buildr . '/.gitignore', "/public_html/agent_dist/\n");
file_put_contents($buildr . '/LICENSE.md', "license\n");
file_put_contents($buildr . '/public_html/includes/A.php', "<?php // committed\n");
file_put_contents($buildr . '/public_html/data/x_class.php', "<?php // committed\n");
file_put_contents($buildr . '/maintenance_scripts/install_tools/install.sh', "#!/bin/sh\n");
file_put_contents($buildr . '/strategy/plan.md', "not shipped\n");
rc_git($buildr, array('add', '-A'));
rc_git($buildr, array('commit', '-q', '-m', 'first'));
$commit = ReleaseCommit::head($buildr);

// Another session's work, uncommitted: an edit, a new file, a staged new file.
file_put_contents($buildr . '/public_html/includes/A.php', "<?php // half-finished edit\n");
file_put_contents($buildr . '/public_html/includes/B.php', "<?php // untracked\n");
file_put_contents($buildr . '/public_html/includes/C.php', "<?php // staged, not committed\n");
rc_git($buildr, array('add', '--', 'public_html/includes/C.php'));
// What publish builds and no commit holds.
mkdir($buildr . '/public_html/agent_dist', 0755, true);
file_put_contents($buildr . '/public_html/agent_dist/manifest.json', '{"built":1}');

$b = ReleaseCommit::ownerBlockers($buildr, null);
check($b['core'] === array(), 'nothing held is uncommitted, so nothing refuses', json_encode($b));
check($b['left_out'] === array('public_html/includes/A.php', 'public_html/includes/B.php', 'public_html/includes/C.php'),
	'the edit, the new file and the staged file are left out, and named', json_encode($b['left_out']));
file_put_contents($buildr . '/public_html/data/x_class.php', "<?php // schema change\n");
$b = ReleaseCommit::ownerBlockers($buildr, null);
check($b['core'] === array('public_html/data/x_class.php'), 'an uncommitted data class refuses', json_encode($b));

$files = ReleaseCommit::commitFiles($buildr, $commit);
check(is_array($files) && isset($files['public_html/includes/A.php']) && !isset($files['public_html/includes/C.php']),
	'commitFiles() is the commit, not the index: a staged file is not in it', json_encode($files));
check(ReleaseCommit::commitFiles($tmp . '/not-a-repo', $commit) === null, 'commitFiles() of a non-repository is null');

$out = $tmp . '/build';
mkdir($out, 0755);
$export = ReleaseCommit::exportTree($buildr, $commit, $out);
check($export['ok'], 'exportTree() builds the commit', $export['reason']);
check((string)@file_get_contents($out . '/public_html/includes/A.php') === "<?php // committed\n",
	'the release holds the committed bytes, not the edit beside them');
check((string)@file_get_contents($out . '/public_html/data/x_class.php') === "<?php // committed\n",
	'every file is the committed one');
check(!file_exists($out . '/public_html/includes/B.php') && !file_exists($out . '/public_html/includes/C.php'),
	'an untracked file and a staged one are not in it');
check(is_file($out . '/LICENSE.md') && is_file($out . '/maintenance_scripts/install_tools/install.sh'),
	'the root license and maintenance_scripts are in it');
check(!file_exists($out . '/strategy') && !file_exists($out . '/.gitignore'), 'nothing outside the shipping paths is');
exec('find ' . escapeshellarg($out) . ' \\( -perm -g+w -o -perm -o+w \\) -print', $writable);
check($writable === array(), 'nothing in the build is group- or other-writable: archives are cut from it',
	implode(', ', array_slice($writable, 0, 5)));
check((string)@file_get_contents($out . '/public_html/agent_dist/manifest.json') === '{"built":1}',
	'what publish built is copied in beside the commit');
file_put_contents($buildr . '/public_html/agent_dist/manifest.json', '{"built":2}');
$again = ReleaseCommit::overlayGenerated($buildr, $out);
check($again['ok'] && (string)@file_get_contents($out . '/public_html/agent_dist/manifest.json') === '{"built":2}',
	'overlayGenerated() refreshes it after a later build');
check(!ReleaseCommit::exportTree($buildr, $commit, $out)['ok'], 'exportTree() refuses a directory that is not empty');
$empty = $tmp . '/build2';
mkdir($empty, 0755);
check(!ReleaseCommit::exportTree($buildr, 'HEAD', $empty)['ok'], 'and anything but a full commit id');
check(!ReleaseCommit::exportTree($buildr, str_repeat('0', 40), $empty)['ok'], 'and a commit that does not exist');

// The agent source is built from its commit the same way, whole.
$whole = $tmp . '/whole';
mkdir($whole, 0755);
check(ReleaseCommit::exportCommit($buildr, $commit, $whole)['ok']
	&& (string)@file_get_contents($whole . '/public_html/includes/A.php') === "<?php // committed\n"
	&& is_file($whole . '/strategy/plan.md') && !file_exists($whole . '/public_html/includes/B.php'),
	'exportCommit() writes the whole commit, committed bytes only, nothing untracked');
check(!ReleaseCommit::exportCommit($buildr, $commit, $whole)['ok'], 'and refuses a directory that is not empty');

// ---------------------------------------------------------------------------
section('On the remote');

// From here on the test fetches, commits, pushes and rewrites remotes in
// $repo, some of it through ReleaseCommit directly rather than rc_git().
if (strpos((string)$repo, $tmp . '/') !== 0) {
	fwrite(STDERR, "release_commit_test: \$repo is {$repo}, not the throwaway repository under {$tmp}; stopping\n");
	exit(1);
}

$remote = ReleaseCommit::onRemote($repo, ReleaseCommit::head($repo));
check($remote['on_remote'] === false && strpos($remote['reason'], 'could not fetch') === 0,
	'a repository with no remote is refused with the fetch failure, never assumed public', $remote['reason']);
check(ReleaseCommit::publicUrl($repo) === null, 'no origin: no public URL');
rc_git($repo, array('remote', 'add', 'origin', 'git@github.com:getjoinery/joinery.git'));
check(ReleaseCommit::publicUrl($repo) === 'https://github.com/getjoinery/joinery.git', 'an SSH GitHub origin reads as its public https URL');
rc_git($repo, array('remote', 'set-url', 'origin', 'ssh://git@github.com/getjoinery/joinery-agent.git'));
check(ReleaseCommit::publicUrl($repo) === 'https://github.com/getjoinery/joinery-agent.git', 'the ssh:// form too');
rc_git($repo, array('remote', 'remove', 'origin'));

// A bare "remote" the throwaway repository pushes to, so the ancestor check
// runs for real without the network.
$bare = $tmp . '/origin.git';
rc_git($tmp, array('init', '-q', '--bare', $bare));
rc_git($repo, array('remote', 'add', 'origin', $bare));
rc_git($repo, array('add', '-A'));
rc_git($repo, array('commit', '-q', '-m', 'second'));
$unpushed = ReleaseCommit::onRemote($repo, ReleaseCommit::head($repo));
check($unpushed['on_remote'] === false, 'a commit not yet pushed is not on the remote', $unpushed['reason']);
rc_git($repo, array('push', '-q', 'origin', 'main'));
$pushed = ReleaseCommit::onRemote($repo, ReleaseCommit::head($repo));
check($pushed['on_remote'] === true, 'a pushed commit is on the remote', $pushed['reason']);

// ---------------------------------------------------------------------------
section('What publish commits itself');

file_put_contents($repo . '/public_html/VERSION', "0.8.470\n");
mkdir($repo . '/public_html/plugins/p', 0755, true);
file_put_contents($repo . '/public_html/plugins/p/plugin.json', "{\n  \"name\": \"p\",\n  \"version\": \"1.0.0\"\n}\n");
file_put_contents($repo . '/public_html/includes/Mine.php', "<?php\n");
rc_git($repo, array('add', '-A'));
rc_git($repo, array('commit', '-q', '-m', 'third'));
rc_git($repo, array('push', '-q', 'origin', 'main'));

file_put_contents($repo . '/public_html/VERSION', "0.8.471\n");
file_put_contents($repo . '/public_html/plugins/p/plugin.json', "{\n  \"name\": \"p\",\n  \"version\": \"1.0.1\"\n}\n");
check(ReleaseCommit::publishWrite($repo, 'public_html/VERSION'), 'VERSION is publish\'s to commit');
check(ReleaseCommit::publishWrite($repo, 'public_html/plugins/p/plugin.json'), 'a manifest whose only change is its version is publish\'s');
check(ReleaseCommit::publishWrite($repo, 'maintenance_scripts/install_tools/joinery_jail/bin/joinery-jail-x86_64'), 'a rebuilt parser jail binary is publish\'s');
check(ReleaseCommit::publishWrite($repo, 'maintenance_scripts/install_tools/site_engine/ENGINE'), 'the site container engine\'s ENGINE record is publish\'s');
check(!ReleaseCommit::publishWrite($repo, 'maintenance_scripts/install_tools/site_engine/versions.env'), '  its pins are the owner\'s');
file_put_contents($repo . '/public_html/includes/Mine.php', "<?php // the owner's\n");
check(!ReleaseCommit::publishWrite($repo, 'public_html/includes/Mine.php'), 'any other file is the owner\'s');
file_put_contents($repo . '/public_html/plugins/p/plugin.json', "{\n  \"name\": \"q\",\n  \"version\": \"1.0.1\"\n}\n");
check(!ReleaseCommit::publishWrite($repo, 'public_html/plugins/p/plugin.json'), 'a manifest with any other change is the owner\'s');
file_put_contents($repo . '/public_html/plugins/p/plugin.json', "{\n  \"name\": \"p\",\n  \"version\": \"1.0.1\"\n}\n");

// Another session's work sits staged in the shared index; it must not go
// into the release commit.
rc_git($repo, array('add', 'public_html/includes/Mine.php'));
// And one release file git has never seen: a first jail binary.
@mkdir($repo . '/maintenance_scripts/install_tools/joinery_jail/bin', 0755, true);
file_put_contents($repo . '/maintenance_scripts/install_tools/joinery_jail/bin/joinery-jail-x86_64', "\x7fELF new\n");
$made = ReleaseCommit::commitRelease($repo, array('public_html/VERSION', 'public_html/plugins/p/plugin.json',
	'maintenance_scripts/install_tools/joinery_jail/bin/joinery-jail-x86_64'), 'Release 0.8.471');
$files = rc_git($repo, array('show', '--name-only', '--format=%s', 'HEAD'))['out'];
check($made['ok'] && $made['commit'] === ReleaseCommit::head($repo), 'the release files are committed', $made['reason']);
check($files === array('Release 0.8.471', '', 'maintenance_scripts/install_tools/joinery_jail/bin/joinery-jail-x86_64',
	'public_html/VERSION', 'public_html/plugins/p/plugin.json'),
	'exactly those files, under the release\'s name, a file new to git among them', json_encode($files));
check(rc_git($repo, array('diff', '--cached', '--name-only'))['out'] === array('public_html/includes/Mine.php'),
	'what else was staged stays staged, uncommitted');
check(ReleaseCommit::onRemote($repo, $made['commit'])['on_remote'], 'and the commit is pushed');

// A commit pushed from elsewhere while the publish ran (B172): the release is
// refused before anything is committed, so the checkout still fast-forwards.
$other = $tmp . '/other';
rc_git($tmp, array('clone', '-q', '-b', 'main', $bare, $other));
rc_git($other, array('config', 'user.email', 'other@example.com'));
rc_git($other, array('config', 'user.name', 'Other'));
file_put_contents($other . '/elsewhere.txt', "pushed while publishing\n");
rc_git($other, array('add', 'elsewhere.txt'));
rc_git($other, array('commit', '-q', '-m', 'pushed elsewhere'));
rc_git($other, array('push', '-q', 'origin', 'HEAD:main'));
$before = ReleaseCommit::head($repo);
file_put_contents($repo . '/public_html/VERSION', "0.8.472\n");
$moved = ReleaseCommit::commitRelease($repo, array('public_html/VERSION'), 'Release 0.8.472');
check(!$moved['ok'] && $moved['commit'] === null && strpos($moved['reason'], 'moved to') !== false,
	'a remote that moved during the publish is refused, with nothing committed', $moved['reason']);
check(ReleaseCommit::head($repo) === $before, '  the checkout is still on the commit it was built from');
check(rc_git($repo, array('merge', '-q', '--ff-only', 'FETCH_HEAD'))['exit'] === 0,
	'  and still fast-forwards to the remote');

// A push refused anyway (no remote to reach): the release commit is undone.
rc_git($repo, array('remote', 'set-url', 'origin', $tmp . '/no-such-remote.git'));
$before = ReleaseCommit::head($repo);
file_put_contents($repo . '/public_html/VERSION', "0.8.472\n");
$new_bin = 'maintenance_scripts/install_tools/joinery_jail/bin/joinery-jail-aarch64';
file_put_contents($repo . '/' . $new_bin, "\x7fELF arm\n");
$failed = ReleaseCommit::commitRelease($repo, array('public_html/VERSION', $new_bin), 'Release 0.8.472');
check(!$failed['ok'] && $failed['commit'] !== null && strpos($failed['reason'], 'git push') !== false,
	'a push that fails is reported, naming the commit it made', $failed['reason']);
check(ReleaseCommit::head($repo) === $before && strpos($failed['reason'], 'undone') !== false,
	'  and that commit is undone, so the checkout is back where it was', $failed['reason']);
check(trim((string)file_get_contents($repo . '/public_html/VERSION')) === '0.8.471',
	'  with the release file back at the committed version');
check(!file_exists($repo . '/' . $new_bin) && rc_git($repo, array('ls-files', '--', $new_bin))['out'] === array(),
	'  and a release file the commit it was built from never had is gone');
check(rc_git($repo, array('diff', '--cached', '--name-only'))['out'] === array('public_html/includes/Mine.php'),
	'  and what else was staged still staged');

// A publish that refuses puts back what it wrote, so the checkout still
// fast-forwards (dev's follow_main.sh will not pull over local changes).
file_put_contents($repo . '/public_html/VERSION', "0.8.473\n");
$fresh_bin = 'maintenance_scripts/install_tools/joinery_jail/bin/joinery-jail-riscv64';
file_put_contents($repo . '/' . $fresh_bin, "\x7fELF riscv\n");
$restored = ReleaseCommit::restoreWrites($repo, array('public_html/VERSION', $fresh_bin));
check($restored['exit'] === 0, 'restoreWrites succeeds', implode(' | ', $restored['out']));
check(trim((string)file_get_contents($repo . '/public_html/VERSION')) === '0.8.471', '  a written file is back at HEAD');
check(!file_exists($repo . '/' . $fresh_bin), '  a file git never had, never staged, is removed');
check(rc_git($repo, array('diff', '--cached', '--name-only'))['out'] === array('public_html/includes/Mine.php'),
	'  and what else was staged is untouched');

// fastForward: refuses local changes, then catches up once they are gone.
rc_git($repo, array('remote', 'set-url', 'origin', $bare));
file_put_contents($other . '/later.txt', "another commit elsewhere\n");
rc_git($other, array('add', 'later.txt'));
rc_git($other, array('commit', '-q', '-m', 'later'));
rc_git($other, array('push', '-q', 'origin', 'HEAD:main'));
$dirty = ReleaseCommit::fastForward($repo);
check(!$dirty['ok'] && strpos($dirty['reason'], 'local changes') !== false, 'fastForward refuses a checkout with local changes', $dirty['reason']);
rc_git($repo, array('reset', '-q', '--hard', 'HEAD'));
rc_git($repo, array('clean', '-q', '-fd'));
$caught = ReleaseCommit::fastForward($repo);
check($caught['ok'] && $caught['head'] === trim(rc_git($other, array('rev-parse', 'HEAD'))['out'][0] ?? ''),
	'  and on a clean checkout catches up to the remote', $caught['reason']);

// ---------------------------------------------------------------------------
section('The Go toolchain pin');

$src = $tmp . '/gosrc';
mkdir($src, 0755, true);
file_put_contents($src . '/go.mod', "module x\n\ngo 1.22\n");
check(GoBinaryPublisher::pinnedToolchain($src) === null, 'no toolchain line: no pin');
$mod = $tmp . '/gomod';
$refused = null;
try { GoBinaryPublisher::officialGo('/usr/bin/true', $src, $mod, $tmp); } catch (Exception $e) { $refused = $e->getMessage(); }
check($refused !== null && strpos($refused, 'no toolchain line') !== false, 'an unpinned tree is refused', (string)$refused);

file_put_contents($src . '/go.mod', "module x\n\ngo 1.22\n\ntoolchain go1.27.2\n");
check(GoBinaryPublisher::pinnedToolchain($src) === 'go1.27.2', 'the pin is read from go.mod');

// A fake downloader: answers `go env GOROOT GOVERSION` as $script says, and
// records the GOTOOLCHAIN it was asked for.
$fake_go = $tmp . '/go';
$fake = function ($script) use ($fake_go, $tmp) {
	file_put_contents($fake_go, "#!/bin/sh\necho \"\$GOTOOLCHAIN \$GOMODCACHE \$GOSUMDB \$GOPROXY [\$GONOSUMDB\$GOINSECURE]\" > " . escapeshellarg($tmp . '/asked') . "\n" . $script);
	chmod($fake_go, 0755);
};
// A caller that turned the checksum database off does not turn it off here.
putenv('GOSUMDB=off'); putenv('GONOSUMDB=golang.org'); putenv('GOINSECURE=*');
$refused = null;
$fake("echo 'go: download go1.27.2: not found'; exit 1\n");
try { GoBinaryPublisher::officialGo($fake_go, $src, $mod, $tmp); } catch (Exception $e) { $refused = $e->getMessage(); }
check($refused !== null && strpos($refused, 'could not fetch the official go1.27.2') !== false, 'a fetch that fails is refused', (string)$refused);
check(trim((string)@file_get_contents($tmp . '/asked')) === 'go1.27.2 ' . $mod . ' sum.golang.org https://proxy.golang.org []',
	'the downloader is asked for the pinned version, into the module cache, through Go\'s proxy and checksum database',
	(string)@file_get_contents($tmp . '/asked'));

$refused = null;
$fake("echo /usr/lib/go-1.27; echo go1.27.2\n");
try { GoBinaryPublisher::officialGo($fake_go, $src, $mod, $tmp); } catch (Exception $e) { $refused = $e->getMessage(); }
check($refused !== null && strpos($refused, 'is itself go1.27.2') !== false,
	'a local Go that only reports the pinned version (a distribution build) is refused', (string)$refused);

$root = realpath($mod) . '/golang.org/toolchain@v0.0.1-go1.27.2.linux-amd64';
mkdir($root . '/bin', 0755, true);
file_put_contents($root . '/bin/go', "#!/bin/sh\n");
chmod($root . '/bin/go', 0755);
$fake('echo ' . escapeshellarg($root) . "; echo go1.27.2\n");
check(GoBinaryPublisher::officialGo($fake_go, $src, $mod, $tmp) === array('go' => $root . '/bin/go', 'toolchain' => 'go1.27.2'),
	'the official toolchain, unpacked by Go in the module cache, is the one built with');

$pins = array();
foreach (array(PathHelper::getSiteRoot() . '/maintenance_scripts/install_tools/joinery_jail',
		PathHelper::getIncludePath('plugins/mailbox/provisioning/relay-sealer')) as $tree) {
	$pins[] = GoBinaryPublisher::pinnedToolchain($tree);
}
check(count(array_unique($pins)) === 1 && $pins[0] !== null, 'every Go tree core ships pins the same toolchain', json_encode($pins));

// ---------------------------------------------------------------------------
section('The repository key lists');

$site = $tmp . '/site';
mkdir($site . '/maintenance_scripts/install_tools/release_keys/release', 0755, true);
mkdir($site . '/maintenance_scripts/install_tools/release_keys/log', 0755, true);
$pair = sodium_crypto_sign_keypair();
$pub = base64_encode(sodium_crypto_sign_publickey($pair));
file_put_contents($site . '/maintenance_scripts/install_tools/release_keys/release/one.pub', $pub . "\n");
file_put_contents($site . '/maintenance_scripts/install_tools/release_keys/release/junk.pub', "not-a-key\n");
$log_spki = base64_encode(TransparencyProof::ED25519_SPKI_PREFIX . random_bytes(32));
file_put_contents($site . '/maintenance_scripts/install_tools/release_keys/log/log2025-1.rekor.sigstore.dev.pub', $log_spki . "\n");
file_put_contents($site . '/maintenance_scripts/install_tools/release_keys/log/junk.example.pub', base64_encode(random_bytes(32)) . "\n");
$lists = AgentDistPublisher::repoKeyLists($site);
check($lists['release_keys'] === array($pub), 'release keys: the well-formed key, the junk file ignored', json_encode($lists['release_keys']));
check($lists['log_keys'] === array(array('origin' => 'log2025-1.rekor.sigstore.dev', 'key' => $log_spki)),
	'log keys carry the origin from the file name; a file that is not an Ed25519 key is not shipped', json_encode($lists['log_keys']));

$refused = null;
try { AgentDistPublisher::assertOwnKeyListed($site, base64_encode(random_bytes(32))); } catch (Exception $e) { $refused = $e->getMessage(); }
check($refused !== null && strpos($refused, 'not listed') !== false, 'a signing key the repository does not list is refused', (string)$refused);
check(AgentDistPublisher::assertOwnKeyListed($site, $pub)['release_keys'] === array($pub), 'a listed key passes and returns the lists');

// ---------------------------------------------------------------------------
section('What stops a publish, asked before it is queued (ownerBlockers)');

// Two scratch repositories: a core with a committed tracked file, and an agent.
$own_core  = $tmp . '/own_core';
$own_agent = $tmp . '/own_agent';
foreach (array($own_core, $own_agent) as $dir) {
	mkdir($dir . '/public_html', 0755, true);
	file_put_contents($dir . '/public_html/a.php', "<?php // a\n");
	exec('git -C ' . escapeshellarg($dir) . ' init -q && git -C ' . escapeshellarg($dir) . ' add -A && git -C ' . escapeshellarg($dir)
		. ' -c user.email=t@example.test -c user.name=t commit -qm init 2>&1');
}
$clean = ReleaseCommit::ownerBlockers($own_core, $own_agent);
check($clean['core'] === array() && $clean['agent'] === array() && $clean['unreadable'] === array(),
	'two clean repositories block nothing', json_encode($clean));

file_put_contents($own_core . '/public_html/a.php', "<?php // edited\n");
mkdir($own_core . '/public_html/data', 0755, true);
file_put_contents($own_core . '/public_html/data/x_class.php', "<?php // new\n"); // held: shapes the install SQL
file_put_contents($own_core . '/public_html/VERSION', "9.9.9\n");          // a file publish writes itself
file_put_contents($own_agent . '/main.go', "package main\n");              // untracked in the agent: always a blocker
$b = ReleaseCommit::ownerBlockers($own_core, $own_agent);
check($b['core'] === array('public_html/data/x_class.php'), 'a held change in the core blocks, the file publish writes itself does not', json_encode($b['core']));
check($b['left_out'] === array('public_html/a.php', 'agent: main.go'), 'and any other edit is left out of the release, not in the way', json_encode($b['left_out']));
check($b['agent'] === array() && in_array('agent: main.go', $b['left_out'], true),
	'a change in the agent source is left out of the release, not in the way: the agent is built from its commit',
	json_encode($b));

$nogit = $tmp . '/no_agent_here';
mkdir($nogit, 0755, true);
$u = ReleaseCommit::ownerBlockers($own_core, $nogit);
check(count($u['unreadable']) === 1 && strpos($u['unreadable'][0], 'not a git checkout') !== false,
	'an agent source that is not a git checkout is named, not skipped', json_encode($u['unreadable']));
check(count(ReleaseCommit::ownerBlockers($own_core, null)['unreadable']) === 1, 'and so is no agent source path at all');

// ---------------------------------------------------------------------------
section('What each refusing file is, and who holds it (blockerNotes)');

// A held data class with a new method and a version line; and a session that has
// already prepared it in its private index.
file_put_contents($own_core . '/public_html/data/x_class.php',
	"<?php\n/**\n * @version 1.2 - address_moved() moves the rows\n */\nclass X {\n\tpublic static function address_moved(int \$n): int { return 0; }\n}\n");
exec('git -C ' . escapeshellarg($own_core) . ' add -N public_html/data/x_class.php 2>&1');
$prep = $own_core . '/.git/work-commits/some-session';
mkdir($prep, 0755, true);
file_put_contents($prep . '/paths', "public_html/data/x_class.php\0");
file_put_contents($prep . '/message', "Move the escrow addresses with an IP swap\n\n- bullet\n");
$notes = ReleaseCommit::blockerNotes($own_core, array('public_html/data/x_class.php', 'public_html/a.php'));
$held_text = implode(' | ', $notes['public_html/data/x_class.php'] ?? array());
check(strpos($held_text, 'last written') !== false, 'a note says when the file was last written', $held_text);
check(strpos($held_text, 'adds: @version 1.2') !== false,
	'it says what the change adds (a version line or function)', $held_text);
check(strpos($held_text, "session 'some-session'") !== false && strpos($held_text, 'work some-session --commit') !== false,
	'and names the session whose prepared commit already holds the file, with the command that commits it', $held_text);
$other = implode(' | ', $notes['public_html/a.php'] ?? array());
check(strpos($other, 'no session has prepared this file') !== false, 'a file nobody prepared says so', $other);
check(strpos($other, '+1 -1 lines') !== false, 'and gives the size of the change', $other);
check(ReleaseCommit::blockerNotes($tmp . '/no_agent_here', array('x.php'))['x.php'] !== array(),
	'a path in a repository git cannot read still gets an answer, not a failure');

exec('rm -rf ' . escapeshellarg($tmp));
harness_finish();
