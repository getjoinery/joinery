<?php
/** @joinery-test
 * name: release_commit
 * tier: safe
 * env: any
 * needs: []
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

function rc_git($repo, $args) {
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
check($verdict->signed(), 'and the unpacked archive verifies as a fresh signed archive', $verdict->verdict . ': ' . $verdict->reason);

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
section('On the remote');

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

rc_git($repo, array('remote', 'set-url', 'origin', $tmp . '/no-such-remote.git'));
file_put_contents($repo . '/public_html/VERSION', "0.8.472\n");
$failed = ReleaseCommit::commitRelease($repo, array('public_html/VERSION'), 'Release 0.8.472');
check(!$failed['ok'] && $failed['commit'] !== null && strpos($failed['reason'], 'git push') !== false,
	'a push that fails is reported, naming the commit it made', $failed['reason']);

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

exec('rm -rf ' . escapeshellarg($tmp));
harness_finish();
