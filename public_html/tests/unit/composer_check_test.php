<?php
/** @joinery-test
 * name: composer_check
 * tier: db
 * env: any
 * needs: []
 * covers: [includes/PluginProvisioning.php, includes/ComposerValidator.php, includes/PathHelper.php]
 */
/**
 * A node whose Composer libraries do not load as its settings say fails its
 * node's health: core's own `composer` entry in the fleet report says whether
 * the composerAutoLoad setting is one PathHelper accepts and whether the
 * vendor tree the site loads is whole (ComposerValidator). Older nodes may
 * still carry a path from before per-site vendor trees (migration 68 never
 * ran), and until this check nothing a management node could read said so.
 *
 * Run: php tests/unit/composer_check_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$refused = "composerAutoLoad must be ../vendor/ or vendor/; ignoring '/home/user1/vendor/' and loading Composer from ../vendor/";

section('The shape every fleet check has');

$live = PluginProvisioning::composerCheck();
check($live['plugin'] === 'core' && $live['key'] === 'composer' && $live['label'] !== ''
		&& in_array($live['state'], array('verified', 'unmet', 'error'), true) && is_string($live['reason']),
	'plugin core, key composer, a label, a state and a reason', json_encode($live));

$expect_problem = PathHelper::composerSettingProblem();
$expect_valid = (new ComposerValidator())->validate();
$expected = !$expect_valid ? 'error' : ($expect_problem !== null ? 'unmet' : 'verified');
check($live['state'] === $expected,
	'this site\'s own setting and vendor tree, judged as PathHelper and the validator judge them (' . $expected . ')', json_encode($live));
check($live['state'] !== 'verified' || $live['reason'] === '', 'a verified check carries no reason', json_encode($live));

section('A setting PathHelper refuses, over a whole vendor tree');

$whole = new ComposerValidator();
$r = PluginProvisioning::composerCheck($refused, $whole);
check($r['state'] === ($whole->validate() ? 'unmet' : 'error'),
	'a refused setting alone is unmet: the site runs, the setting is wrong', json_encode($r));
check(strpos($r['reason'], "'/home/user1/vendor/'") !== false && strpos($r['reason'], '../vendor/') !== false,
	'the reason names the value refused and the path that loads instead', $r['reason']);

$r = PluginProvisioning::composerCheck(null, $whole);
check($r['state'] === ($whole->validate() ? 'verified' : 'error'),
	'no setting problem and a whole tree: verified', json_encode($r));

section('A vendor tree that is not whole');

// A vendor directory with an autoload.php and nothing else: the shape of a
// tree composer never built (composer_validator_test's bare case).
$scratch = rtrim(sys_get_temp_dir(), '/') . '/cck_' . substr(md5(uniqid('', true)), 0, 8);
mkdir($scratch . '/composer', 0777, true);
file_put_contents($scratch . '/autoload.php', "<?php\n");
harness_defer(function () use ($scratch) {
	@unlink($scratch . '/autoload.php');
	@rmdir($scratch . '/composer');
	@rmdir($scratch);
});

$bare = new ComposerValidator($scratch . '/');
$r = PluginProvisioning::composerCheck(null, $bare);
check($r['state'] === 'error' && strpos($r['reason'], 'Composer libraries: ') === 0
		&& strpos($r['reason'], 'Missing required packages') !== false,
	'a tree without installed.json is an error, in the validator\'s words', json_encode($r));

$r = PluginProvisioning::composerCheck($refused, new ComposerValidator($scratch . '/'));
check($r['state'] === 'error' && strpos($r['reason'], $refused) === 0 && strpos($r['reason'], 'Missing required packages') !== false,
	'a refused setting and a broken tree: an error that names both, the setting first', json_encode($r));

$long = new ComposerValidator($scratch . '/');
$r = PluginProvisioning::composerCheck(str_repeat('x', 600), $long);
check(mb_strlen($r['reason']) <= PluginProvisioning::FLEET_REASON_MAX,
	'the reason is capped at the fleet report\'s bound', (string)mb_strlen($r['reason']));

section('It rides the fleet report');

$report = PluginProvisioning::runFleetReport();
$keys = array();
foreach ($report['checks'] as $c) { $keys[] = $c['plugin'] . '/' . $c['key']; }
check(in_array('core/composer', $keys, true) && array_search('core/composer', $keys, true) < 2,
	'runFleetReport lists core/composer among core\'s own checks, ahead of the plugins', implode(', ', $keys));

harness_finish();
