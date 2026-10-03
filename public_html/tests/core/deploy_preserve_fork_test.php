<?php
/** @joinery-test
 * name: deploy_preserve_fork
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * What a deploy keeps (specs/package_replace_on_upload.md WP3).
 *
 * DeploymentHelper::copyPreservedToStaging() decides, extension by
 * extension, whether the live directory is carried into staging over the
 * incoming copy. The fork model's whole promise rests on one of its three
 * reasons: a LIVE manifest saying receives_upgrades: false is the operator's
 * copy, and the archive cannot override it. Each case below is a live tree
 * and a staging tree built by hand, and the check is which bytes are in
 * staging afterwards.
 *
 * Run: php tests/run.php --only=tests/core/deploy_preserve_fork_test.php
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$work = harness_scratch_dir('deploy_preserve_fork') . '/run-' . getmypid();
$rmtree = function ($p) use (&$rmtree) {
	foreach (glob(rtrim($p, '/') . '/{,.}*', GLOB_BRACE) ?: array() as $f) {
		if (basename($f) === '.' || basename($f) === '..') continue;
		is_dir($f) && !is_link($f) ? $rmtree($f) : @unlink($f);
	}
	@rmdir($p);
};
$rmtree($work);
@mkdir($work, 0770, true);
harness_defer(function () use ($work, $rmtree) { $rmtree($work); });

/**
 * One extension in one tree: a manifest (or none, or garbage) and a marker
 * file whose content says which copy this is.
 */
$put = function (string $tree, string $sub, string $name, $manifest, string $marker) {
	$dir = "$tree/$sub/$name";
	@mkdir($dir, 0770, true);
	$file = $sub === 'theme' ? 'theme.json' : 'plugin.json';
	if ($manifest === 'garbage') {
		file_put_contents("$dir/$file", '{not json');
	} elseif ($manifest !== null) {
		file_put_contents("$dir/$file", json_encode($manifest));
	}
	file_put_contents("$dir/MARKER", $marker);
};
$marker = function (string $tree, string $sub, string $name): string {
	return (string)@file_get_contents("$tree/$sub/$name/MARKER");
};

section('preserveReason(): the three reasons, in order, and nothing else');

$live = "$work/reason/live";
$stage = "$work/reason/stage";
$put($live, 'plugins', 'absent', array('receives_upgrades' => true), 'live');
check(DeploymentHelper::preserveReason("$live/plugins/absent", "$stage/plugins/absent", 'plugin.json') === 'not in staging',
	'an extension the archive does not carry is preserved: not in staging');

$put($live, 'plugins', 'fork', array('receives_upgrades' => false), 'live');
$put($stage, 'plugins', 'fork', array('receives_upgrades' => true), 'incoming');
$reason = DeploymentHelper::preserveReason("$live/plugins/fork", "$stage/plugins/fork", 'plugin.json');
check(strpos($reason, 'local fork') === 0, 'a LIVE false is a local fork, whatever the incoming manifest says', $reason);

$put($live, 'plugins', 'shipped_preserved', array('receives_upgrades' => true), 'live');
$put($stage, 'plugins', 'shipped_preserved', array('receives_upgrades' => false), 'incoming');
$reason = DeploymentHelper::preserveReason("$live/plugins/shipped_preserved", "$stage/plugins/shipped_preserved", 'plugin.json');
check(strpos($reason, 'published as preserved') === 0, 'an incoming false is a package published as preserved', $reason);

$put($live, 'plugins', 'plain', array('receives_upgrades' => true), 'live');
$put($stage, 'plugins', 'plain', array('receives_upgrades' => true), 'incoming');
check(DeploymentHelper::preserveReason("$live/plugins/plain", "$stage/plugins/plain", 'plugin.json') === '',
	'true on both sides: the incoming copy stands');

$put($live, 'plugins', 'silent', array('name' => 'silent'), 'live');
$put($stage, 'plugins', 'silent', array('name' => 'silent'), 'incoming');
check(DeploymentHelper::preserveReason("$live/plugins/silent", "$stage/plugins/silent", 'plugin.json') === '',
	'a manifest that does not mention the flag says nothing, and the incoming copy stands');

$put($live, 'plugins', 'garbled', 'garbage', 'live');
$put($stage, 'plugins', 'garbled', 'garbage', 'incoming');
check(DeploymentHelper::preserveReason("$live/plugins/garbled", "$stage/plugins/garbled", 'plugin.json') === '',
	'a manifest that is not JSON says nothing');

$put($live, 'plugins', 'nomanifest', null, 'live');
$put($stage, 'plugins', 'nomanifest', null, 'incoming');
check(DeploymentHelper::preserveReason("$live/plugins/nomanifest", "$stage/plugins/nomanifest", 'plugin.json') === '',
	'no manifest at all says nothing');

// The string 'false' and 0 are not false: only the JSON boolean is the mark.
$put($live, 'plugins', 'stringy', array('receives_upgrades' => 'false'), 'live');
$put($stage, 'plugins', 'stringy', array('receives_upgrades' => true), 'incoming');
check(DeploymentHelper::preserveReason("$live/plugins/stringy", "$stage/plugins/stringy", 'plugin.json') === '',
	'only the JSON boolean false is the fork mark; a string is not');

section('copyPreservedToStaging(): the bytes that end up in staging');

$live = "$work/copy/live";
$stage = "$work/copy/stage";
// themes: one of each reason, and one that is simply upgraded
$put($live, 'theme', 'uploaded_only', array('receives_upgrades' => true), 'live');
$put($live, 'theme', 'forked', array('receives_upgrades' => false, 'version' => '1.0.0'), 'live-fork');
$put($stage, 'theme', 'forked', array('receives_upgrades' => true, 'version' => '1.4.0'), 'incoming');
$put($live, 'theme', 'preserved_by_publisher', array('receives_upgrades' => true), 'live');
$put($stage, 'theme', 'preserved_by_publisher', array('receives_upgrades' => false), 'incoming');
$put($live, 'theme', 'upgraded', array('receives_upgrades' => true), 'live');
$put($stage, 'theme', 'upgraded', array('receives_upgrades' => true), 'incoming');
// plugins: a fork and an upgrade, so the plugin half is held to the same rule
$put($live, 'plugins', 'forkplug', array('receives_upgrades' => false), 'live-fork');
$put($stage, 'plugins', 'forkplug', array('receives_upgrades' => true), 'incoming');
$put($live, 'plugins', 'plainplug', array('receives_upgrades' => true), 'live');
$put($stage, 'plugins', 'plainplug', array('receives_upgrades' => true), 'incoming');
// a plain file under theme/ is not an extension and is skipped
file_put_contents("$live/theme/README", 'x');

$result = DeploymentHelper::copyPreservedToStaging($live, $stage, false);
check($result['success'] === true && $result['errors'] === array(), 'the copy reports success', json_encode($result));
check($result['themes_copied'] === 3 && $result['themes_skipped'] === 1, 'three themes preserved, one left to upgrade',
	$result['themes_copied'] . '/' . $result['themes_skipped']);
check($result['plugins_copied'] === 1 && $result['plugins_skipped'] === 1, 'one plugin preserved, one left to upgrade',
	$result['plugins_copied'] . '/' . $result['plugins_skipped']);

check($marker($stage, 'theme', 'uploaded_only') === 'live', 'a theme the archive does not carry is now in staging');
check($marker($stage, 'theme', 'forked') === 'live-fork', 'the fork\'s live bytes replaced the incoming copy in staging');
$staged_fork = json_decode((string)file_get_contents("$stage/theme/forked/theme.json"), true);
check(is_array($staged_fork) && $staged_fork['receives_upgrades'] === false && $staged_fork['version'] === '1.0.0',
	'and the fork\'s own manifest travelled with it, so the next deploy sees the mark again');
check($marker($stage, 'theme', 'preserved_by_publisher') === 'live', 'a package published as preserved keeps the live copy');
check($marker($stage, 'theme', 'upgraded') === 'incoming', 'a theme true on both sides is the incoming copy');
check($marker($stage, 'plugins', 'forkplug') === 'live-fork', 'a forked plugin keeps its live bytes');
check($marker($stage, 'plugins', 'plainplug') === 'incoming', 'a plain plugin is the incoming copy');
check(!file_exists("$stage/theme/README"), 'a plain file under theme/ is not carried');

section('Allow upgrade: a live true lets the next deploy replace the fork');

// The operator pressed Allow upgrade: set_receives_upgrades wrote true into
// the live manifest. Same trees, next deploy.
$manifest = json_decode((string)file_get_contents("$live/theme/forked/theme.json"), true);
$manifest['receives_upgrades'] = true;
file_put_contents("$live/theme/forked/theme.json", json_encode($manifest));
$put($stage, 'theme', 'forked', array('receives_upgrades' => true, 'version' => '1.4.0'), 'incoming');
$result = DeploymentHelper::copyPreservedToStaging($live, $stage, false);
check($marker($stage, 'theme', 'forked') === 'incoming', 'after Allow upgrade the incoming copy stands');

$rmtree($work);
harness_finish();
