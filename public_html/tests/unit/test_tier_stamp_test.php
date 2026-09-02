<?php
/** @joinery-test
 * name: test_tier_stamp
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The PASS stamp names one exact tree, and a publish accepts only that tree.
 *
 * The publisher is root on the local job queue and cannot run the development
 * tiers itself, so it trusts the runner's stamp — which means the stamp has to
 * be impossible to satisfy with a tree other than the one that was tested. This
 * builds a throwaway git repository shaped like a site (public_html + cache),
 * stamps it, and checks that every kind of change — edit, new file, delete,
 * commit — is seen, named, and refused, while ignored paths are not.
 *
 * Run: php tests/run.php safe --filter=test_tier_stamp
 *
 * @version 1.1 - identity is content only: a commit of the same bytes keeps the stamp
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$site = sys_get_temp_dir() . '/tier_stamp_' . getmypid();
$ph = $site . '/public_html';
@mkdir($ph, 0700, true);
@mkdir($site . '/cache', 0700, true);
$git = function ($args) use ($site) {
	$out = array(); $rc = 1;
	exec('git -C ' . escapeshellarg($site) . ' ' . $args . ' 2>&1', $out, $rc);
	return array($rc, implode("\n", $out));
};

try {
	section('A repository shaped like a site');
	list($rc, $o) = $git('init -q');
	check($rc === 0, 'git init', $o);
	$git('config user.email t@example.invalid'); $git('config user.name t'); $git('config commit.gpgsign false');
	file_put_contents($site . '/.gitignore', "/cache\n/logs\n");
	file_put_contents($ph . '/a.php', "<?php // a\n");
	$git('add -A'); list($rc, $o) = $git('commit -q -m one');
	check($rc === 0, 'first commit', $o);

	$tree = TestTierStamp::treeId($ph);
	check(is_array($tree) && preg_match('/^[0-9a-f]{64}$/', $tree['id'])
		&& array_keys($tree['files']) === array('.gitignore', 'public_html/a.php') && strlen($tree['files']['public_html/a.php']) === 40,
		'a tree is every file with its git blob hash', json_encode($tree));

	section('Record and verify');
	check(TestTierStamp::verify($ph, 'safe')['ok'] === false, 'no stamp yet → not ok');
	check(TestTierStamp::record($ph, array('safe', 'db'), $tree, array('tests' => 3)) === true, 'record writes');
	check(is_file($site . '/cache/test_tier_stamp.json'), 'the stamp lives in {site}/cache');
	$v = TestTierStamp::verify($ph, 'safe');
	check($v['ok'] === true && $v['stamp']['tree_id'] === $tree['id'], 'the same tree verifies for safe');
	check(TestTierStamp::verify($ph, 'db')['ok'] === true, 'and for db, which the batch also covered');
	check(TestTierStamp::verify($ph, 'test-db')['ok'] === false, 'not for a tier the batch did not cover');

	section('Every kind of change is seen and named');
	file_put_contents($ph . '/a.php', "<?php // a edited\n");
	$v = TestTierStamp::verify($ph, 'safe');
	check($v['ok'] === false && in_array('public_html/a.php', $v['changed'], true), 'an edit to a tracked file refuses and names the path', json_encode($v['changed']));

	$git('checkout -q -- public_html/a.php');
	check(TestTierStamp::verify($ph, 'safe')['ok'] === true, 'reverting the edit restores the match');

	file_put_contents($ph . '/new.php', "<?php // new\n");
	$v = TestTierStamp::verify($ph, 'safe');
	check($v['ok'] === false && in_array('public_html/new.php', $v['changed'], true), 'an untracked file refuses and is named');
	unlink($ph . '/new.php');

	unlink($ph . '/a.php');
	$v = TestTierStamp::verify($ph, 'safe');
	check($v['ok'] === false && in_array('public_html/a.php', $v['changed'], true), 'a deleted file refuses and is named');
	$git('checkout -q -- public_html/a.php');

	file_put_contents($site . '/cache/junk.json', '{}');
	@mkdir($site . '/logs'); file_put_contents($site . '/logs/x.log', 'x');
	check(TestTierStamp::verify($ph, 'safe')['ok'] === true, 'ignored paths (cache/, logs/) are not part of the tree');

	section('Git state is not content: committing the same bytes keeps the stamp');
	file_put_contents($ph . '/a.php', "<?php // a edited\n");
	TestTierStamp::record($ph, array('safe'), TestTierStamp::treeId($ph), array('tests' => 1));
	$git('add -A'); $git('commit -q -m two');
	check(TestTierStamp::verify($ph, 'safe')['ok'] === true, 'a commit of the stamped content still verifies');
	$git('commit -q --amend --allow-empty -m two-amended');
	check(TestTierStamp::verify($ph, 'safe')['ok'] === true, 'an amend still verifies');
	$git('add -A'); check(TestTierStamp::verify($ph, 'safe')['ok'] === true, 'staging changes nothing');

	file_put_contents($ph . '/b.php', "<?php // b\n");
	$git('add -A'); $git('commit -q -m three');
	$v = TestTierStamp::verify($ph, 'safe');
	check($v['ok'] === false && in_array('public_html/b.php', $v['changed'], true),
		'a commit that ADDS content refuses and names the file', json_encode($v['changed']));

	section('A stamp taken on a dirty tree is for that dirty tree');
	file_put_contents($ph . '/b.php', "<?php // b dirty\n");
	$dirty = TestTierStamp::treeId($ph);
	check(isset($dirty['files']['public_html/b.php']) && strlen($dirty['files']['public_html/b.php']) === 40, 'the dirty path is hashed into the identity');
	TestTierStamp::record($ph, array('safe'), $dirty, array('tests' => 1));
	check(TestTierStamp::verify($ph, 'safe')['ok'] === true, 'the same dirty content verifies');
	file_put_contents($ph . '/b.php', "<?php // b dirtier\n");
	check(TestTierStamp::verify($ph, 'safe')['ok'] === false, 'a further edit to the same dirty file does not');

	section('Clearing');
	TestTierStamp::record($ph, array('safe'), TestTierStamp::treeId($ph), array());
	check(TestTierStamp::verify($ph, 'safe')['ok'] === true, 're-stamped');
	TestTierStamp::clear($ph, array('safe'));
	check(TestTierStamp::verify($ph, 'safe')['ok'] === false && TestTierStamp::verify($ph, 'safe')['reason'] === 'no PASS stamp for the safe tier',
		'a failing full run forgets the stamp');
	check(TestTierStamp::verify($ph, 'db')['ok'] === false && strpos(TestTierStamp::verify($ph, 'db')['reason'], 'different tree') !== false,
		'other tiers keep theirs, and stay honest about the tree');

	section('No repository, no identity');
	$bare = sys_get_temp_dir() . '/tier_stamp_bare_' . getmypid() . '/public_html';
	@mkdir($bare, 0700, true);
	check(TestTierStamp::treeId($bare) === null, 'a tree with no .git has no identity');
	check(TestTierStamp::verify($bare, 'safe')['ok'] === false, 'and can never verify');

	section('The publish gate reads the stamp');
	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/PublishTestGate.php'));
	$git('checkout -q -- public_html/b.php');
	TestTierStamp::record($ph, array('safe'), TestTierStamp::treeId($ph), array('tests' => 5, 'skipped_needs' => array('sync_engine')));
	$lines = array();
	$r = PublishTestGate::verifyStamp($ph, 'safe', function ($l) use (&$lines) { $lines[] = $l; });
	check($r['ok'] === true && $r['started'] === true && $r['exit_code'] === null, 'a matching stamp is accepted', json_encode($lines));
	check(count(array_filter($lines, function ($l) { return strpos($l, 'sync_engine') !== false; })) === 1, 'what that run skipped is said out loud');
	file_put_contents($ph . '/a.php', "<?php // a again\n");
	$lines = array();
	$r = PublishTestGate::verifyStamp($ph, 'safe', function ($l) use (&$lines) { $lines[] = $l; });
	check($r['ok'] === false, 'a changed tree is refused');
	check(count(array_filter($lines, function ($l) { return strpos($l, 'public_html/a.php') !== false; })) === 1, 'the refusal names the path', json_encode($lines));
	check(count(array_filter($lines, function ($l) { return strpos($l, 'php tests/run.php safe') !== false; })) === 1, 'and says what to run');

} finally {
	exec('rm -rf ' . escapeshellarg($site) . ' ' . escapeshellarg(dirname($bare ?? ($site . '/x'))));
}

harness_finish();
