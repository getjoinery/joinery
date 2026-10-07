<?php
/** @joinery-test
 * name: release_commit
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * A release is a commit (specs/release_transparency.md D1, D2, D3):
 *
 *  - which untracked files stop a publish: the ones that would ship, and only
 *    those (ReleaseCommit::joineryPathShips)
 *  - what git status becomes once it is read (ReleaseCommit::status,
 *    joineryBlockers, agentBlockers), against a throwaway repository built
 *    here, so no state of the real repository leaks into the result
 *  - the command the owner is shown quotes every path and never runs here
 *  - the Go toolchain pin is read from go.mod and an unpinned tree is refused
 *    (GoBinaryPublisher::pinnedToolchain, assertToolchain)
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
section('The command the owner is shown');

$cmd = ReleaseCommit::commitCommand('/srv/x', array('public_html/VERSION', 'a b.php'), "Release 0.8.470");
check(strpos($cmd, "git add -A -- 'public_html/VERSION' 'a b.php'") !== false, 'every path is quoted', $cmd);
check(strpos($cmd, "git commit -m 'Release 0.8.470'") !== false, 'the message is quoted', $cmd);
check(strpos($cmd, 'git push origin main') !== false, 'it ends with the push', $cmd);

// ---------------------------------------------------------------------------
section('The Go toolchain pin');

$src = $tmp . '/gosrc';
mkdir($src, 0755, true);
file_put_contents($src . '/go.mod', "module x\n\ngo 1.22\n");
check(GoBinaryPublisher::pinnedToolchain($src) === null, 'no toolchain line: no pin');
$refused = null;
try { GoBinaryPublisher::assertToolchain('/usr/bin/true', $src); } catch (Exception $e) { $refused = $e->getMessage(); }
check($refused !== null && strpos($refused, 'no toolchain line') !== false, 'an unpinned tree is refused', (string)$refused);

file_put_contents($src . '/go.mod', "module x\n\ngo 1.22\n\ntoolchain go1.22.2\n");
check(GoBinaryPublisher::pinnedToolchain($src) === 'go1.22.2', 'the pin is read from go.mod');

$fake_go = $tmp . '/go';
file_put_contents($fake_go, "#!/bin/sh\necho 'go version go1.23.0 linux/amd64'\n");
chmod($fake_go, 0755);
$refused = null;
try { GoBinaryPublisher::assertToolchain($fake_go, $src); } catch (Exception $e) { $refused = $e->getMessage(); }
check($refused !== null && strpos($refused, 'go.mod pins go1.22.2 but') !== false,
	'a newer compiler is refused too, not only an older one', (string)$refused);

file_put_contents($fake_go, "#!/bin/sh\necho 'go version go1.22.2 linux/amd64'\n");
check(GoBinaryPublisher::assertToolchain($fake_go, $src) === 'go1.22.2', 'the pinned compiler passes');

// ---------------------------------------------------------------------------
section('The repository key lists');

$site = $tmp . '/site';
mkdir($site . '/maintenance_scripts/install_tools/release_keys/release', 0755, true);
mkdir($site . '/maintenance_scripts/install_tools/release_keys/log', 0755, true);
$pair = sodium_crypto_sign_keypair();
$pub = base64_encode(sodium_crypto_sign_publickey($pair));
file_put_contents($site . '/maintenance_scripts/install_tools/release_keys/release/one.pub', $pub . "\n");
file_put_contents($site . '/maintenance_scripts/install_tools/release_keys/release/junk.pub', "not-a-key\n");
file_put_contents($site . '/maintenance_scripts/install_tools/release_keys/log/log2025-1.rekor.sigstore.dev.pub', base64_encode(random_bytes(32)) . "\n");
$lists = AgentDistPublisher::repoKeyLists($site);
check($lists['release_keys'] === array($pub), 'release keys: the well-formed key, the junk file ignored', json_encode($lists['release_keys']));
check(count($lists['log_keys']) === 1 && $lists['log_keys'][0]['origin'] === 'log2025-1.rekor.sigstore.dev',
	'log keys carry the origin from the file name', json_encode($lists['log_keys']));

$refused = null;
try { AgentDistPublisher::assertOwnKeyListed($site, base64_encode(random_bytes(32))); } catch (Exception $e) { $refused = $e->getMessage(); }
check($refused !== null && strpos($refused, 'not listed') !== false, 'a signing key the repository does not list is refused', (string)$refused);
check(AgentDistPublisher::assertOwnKeyListed($site, $pub)['release_keys'] === array($pub), 'a listed key passes and returns the lists');

exec('rm -rf ' . escapeshellarg($tmp));
harness_finish();
