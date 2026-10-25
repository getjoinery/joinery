<?php
/** @joinery-test
 * name: changed_selection
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * The --changed selection logic, fed fabricated maps and change lists.
 *
 * The rule under guard: a scoped run may only ever run LESS than the full
 * gate, and only for a reason the map can state — never skip a suite it
 * cannot account for. So: an unknown suite runs; a harness change runs
 * everything; a suite runs when a changed file is in its recorded reach, its
 * covers globs, its fixtures, or (for a dev-web suite) core; and a changed
 * code file nothing reaches is named, while prose is not. A PHP file whose
 * edit is only comments and layout runs the same code, so it selects nothing
 * through what loads it — but still where its text is the subject. An edit
 * inside function bodies selects, through what loads the file, only the
 * suites that ran one of those functions; any other kind of edit selects as
 * the whole file.
 *
 * Run: php tests/unit/changed_selection_test.php
 */
require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/../lib/coverage.php');
harness_boot();

$T = function ($path, $needs = array(), $covers = array()) {
	return array('path' => $path, 'meta' => array('needs' => $needs, 'covers' => $covers));
};
$mailbox = $T('public_html/plugins/mailbox/tests/drafts_test.php');
$vault   = $T('public_html/tests/vault/registry_test.php');
$web     = $T('public_html/tests/functional/api/member_screens_test.php', array('dev-web'));
$gate    = $T('public_html/tests/functional/sync/sync_sim_gate.sh', array('rust'), array('sync/**'));
$tests = array($mailbox, $vault, $web, $gate);
$map = array(
	'public_html/plugins/mailbox/tests/drafts_test.php' => array(
		'files' => array('public_html/plugins/mailbox/includes/Drafts.php'), 'covers' => array()),
	'public_html/tests/vault/registry_test.php' => array(
		'files' => array('public_html/includes/VaultCrypto.php'), 'covers' => array()),
	'public_html/tests/functional/api/member_screens_test.php' => array(
		'files' => array('public_html/tests/lib/http.php'), 'covers' => array()),
	'public_html/tests/functional/sync/sync_sim_gate.sh' => array('files' => array(), 'covers' => array()),
);

section('A change selects exactly what reaches it');

$r = coverage_select($tests, $map, array('public_html/plugins/mailbox/includes/Drafts.php'));
check(array_keys($r['selected']) === array('public_html/plugins/mailbox/tests/drafts_test.php'),
	'a mailbox file selects the suite that loads it, and nothing else', json_encode(array_keys($r['selected'])));
check($r['uncovered'] === array(), 'a reached file is not "uncovered"');

$r = coverage_select($tests, $map, array('public_html/includes/VaultCrypto.php'));
check(isset($r['selected']['public_html/tests/vault/registry_test.php']), 'a core file selects the suite that loads it');
check(isset($r['selected']['public_html/tests/functional/api/member_screens_test.php']),
	'and every dev-web suite — Apache-side reach is not in the include list');
check(!isset($r['selected']['public_html/plugins/mailbox/tests/drafts_test.php']),
	'but not a suite whose recorded reach misses it');

section('Unknown means run, never skip');

$r = coverage_select($tests, array(), array('public_html/docs/whatever.md'));
check(count($r['selected']) === count($tests),
	'with no map at all, everything runs — a missing map can only widen', count($r['selected']) . ' selected');

$r = coverage_select($tests, $map, array());
check($r['selected'] === array(), 'a clean tree selects nothing');

section('The overrides');

$r = coverage_select($tests, $map, array('public_html/tests/lib/harness.php'));
check($r['run_all'] !== '', 'a harness change runs the whole batch, with the reason stated', $r['run_all']);
foreach (array('public_html/tests/run.php', 'public_html/tests/lib/coverage.php', 'public_html/tests/lib/discovery.php', 'public_html/tests/lib/gate.php') as $f) {
	$r = coverage_select($tests, $map, array($f));
	check($r['run_all'] !== '', 'the runner, the selection and the discovery changing run the whole batch (' . $f . ')');
}

$r = coverage_select($tests, $map, array('public_html/tests/lib/http.php'));
check($r['run_all'] === '' && array_keys($r['selected']) === array('public_html/tests/functional/api/member_screens_test.php'),
	'a helper library under tests/lib selects only the suites that load it, never the whole batch', json_encode(array_keys($r['selected'])));

$r = coverage_select($tests, $map, array('public_html/tests/vault/registry_test.php'));
check(isset($r['selected']['public_html/tests/vault/registry_test.php']), "a suite's own file selects it");

$r = coverage_select($tests, $map, array('public_html/tests/vault/fixtures/sealed.bin'));
check(isset($r['selected']['public_html/tests/vault/registry_test.php']), 'an area fixture selects its area');
check(!isset($r['selected']['public_html/plugins/mailbox/tests/drafts_test.php']), 'and only its area');

$r = coverage_select($tests, $map, array('public_html/tests/fixtures/shared.eml'));
check(count($r['selected']) === count($tests), 'the shared fixtures select everything');

section('covers: globs — reach without loading');

check(coverage_glob_match('sync/**', 'sync/jd-core/src/reconcile.rs'), '** crosses directories');
check(coverage_glob_match('public_html/plugins/*/plugin.json', 'public_html/plugins/mailbox/plugin.json'),
	'* matches one segment');
check(!coverage_glob_match('public_html/plugins/*/plugin.json', 'public_html/plugins/mailbox/sub/plugin.json'),
	'and only one segment');
$r = coverage_select($tests, $map, array('sync/jd-core/src/order.rs'));
check(array_keys($r['selected']) === array('public_html/tests/functional/sync/sync_sim_gate.sh'),
	'a Rust change selects the Rust gate through covers:, and nothing else', json_encode(array_keys($r['selected'])));

section('Uncovered names code, not prose');

$r = coverage_select($tests, $map, array(
	'public_html/assets/js/orphan.js', 'public_html/specs/some_spec.md', 'docs/notes.md',
));
check($r['uncovered'] === array('public_html/assets/js/orphan.js'),
	'an unreached code file is named; specs and markdown are not', json_encode($r['uncovered']));

section('A comment-only edit runs the same code');

$src = "<?php\n/** Doc. */\nfunction f(\$a) {\n\treturn \$a + 1; // one more\n}\n";
check(coverage_php_code_hash($src) === coverage_php_code_hash("<?php function f(\$a){return \$a+1;}"),
	'comments and layout do not change the code');
check(coverage_php_code_hash($src) !== coverage_php_code_hash("<?php function f(\$a){return \$a+2;}"),
	'a changed token does');
check(coverage_php_code_hash("<?php \$s = 'a  b';") !== coverage_php_code_hash("<?php \$s = 'a b';"),
	'whitespace inside a string is code');
check(coverage_php_code_hash("<?php \$s = <<<T\na  b\nT;\n") !== coverage_php_code_hash("<?php \$s = <<<T\na b\nT;\n"),
	'and so is whitespace inside a heredoc');
check(coverage_php_code_hash("<p>x</p>\n<?php echo 1; ?>\n<p>y</p>") !== coverage_php_code_hash("<p>x</p>\n<?php echo 1; ?>\n<p>y </p>"),
	'markup outside the tags is output');
check(coverage_php_code_hash('plain text') === null, 'a file that is not PHP has no code hash');
$cu = coverage_code_unchanged(array(
	'public_html/includes/A.php' => array("<?php // old\nx();", "<?php // new\nx();"),
	'public_html/includes/B.php' => array("<?php x();", "<?php y();"),
	'public_html/includes/C.php' => array(null, "<?php x();"),
	'public_html/assets/x.js'    => array("// old\nx();", "// new\nx();"),
));
check(array_keys($cu) === array('public_html/includes/A.php'),
	'only an existing PHP file whose change is prose counts; a new file or a non-PHP file never does', json_encode(array_keys($cu)));

$prose = array('public_html/includes/VaultCrypto.php' => true);
$r = coverage_select($tests, $map, array('public_html/includes/VaultCrypto.php'), $prose);
check($r['selected'] === array(), 'a comment-only core edit selects neither the suite that loads it nor a dev-web suite',
	json_encode(array_keys($r['selected'])));
check($r['uncovered'] === array(), 'and is not reported uncovered');
$r = coverage_select($tests, $map, array('public_html/tests/lib/harness.php'), array('public_html/tests/lib/harness.php' => true));
check($r['run_all'] === '', 'a comment-only harness edit does not run everything', $r['run_all']);
$own = 'public_html/tests/vault/registry_test.php';
$r = coverage_select($tests, $map, array($own), array($own => true));
check(isset($r['selected'][$own]), "a suite's own file still selects it: its header is a comment");
$fx = 'public_html/tests/vault/fixtures/sample.php';
$r = coverage_select($tests, $map, array($fx), array($fx => true));
check(isset($r['selected'][$own]), 'a fixture still selects its area: its text is the data');
$gate_php = $T('public_html/tests/functional/sync/php_reader_gate.sh', array(), array('public_html/includes/Reader.php'));
$r = coverage_select(array($gate_php), array($gate_php['path'] => array('files' => array(), 'covers' => array())), array('public_html/includes/Reader.php'), array('public_html/includes/Reader.php' => true));
check(count($r['selected']) === 1 && strpos(reset($r['selected']), 'covers') === 0,
	'a covers: glob still selects: the suite declares it reads the text', json_encode($r['selected']));

section('An edit inside function bodies reaches only who ran them');

$base = <<<'PHP'
<?php
namespace App\Things;
use function App\helper;
interface Shape { public function area(): float; }
trait Named { public function label() { return 'n'; } }
class Box extends Base implements Shape {
	use Named;
	const SIDE = 2;
	protected $kind = 'box';
	public static function make($side = 2) { return new static(); }
	public function area(): float { $f = function ($x) { return $x * 2; }; return $f(self::SIDE); }
	public function size() { return 1; }
}
enum Suit: string { case Hearts = 'h'; public function color() { return 'red'; } }
function helper_two($a) { return $a + 1; }
$top = new class { public function run() { return 1; } };
PHP;
$edit = function ($from, $to) use ($base) {
	check(substr_count($base, $from) === 1, 'fixture edit applies: ' . $from);
	return coverage_function_change($base, str_replace($from, $to, $base));
};
check($edit('return 1; }' . "\n}", 'return 2; }' . "\n}") === array('box::size' => true), 'a method body edit names that method', json_encode($edit('return 1; }' . "\n}", 'return 2; }' . "\n}")));
check($edit('return $x * 2;', 'return $x * 3;') === array('box::area' => true), 'a closure inside a method belongs to the method');
check($edit("return 'n';", "return 'm';") === array('*::label' => true), "a trait's method matches by name (the trace reports it under the using class)");
check($edit("return 'red';", "return 'blue';") === array('suit::color' => true), 'an enum method body edit names it');
check($edit('return $a + 1;', 'return $a + 2;') === array('helper_two' => true), 'a global function body edit names it');
check($edit('return 1; } };', 'return 2; } };') === array('*::run' => true), 'an anonymous class method matches by name');
foreach (array(
	array('const SIDE = 2;', 'const SIDE = 3;', 'a constant'),
	array("protected \$kind = 'box';", "protected \$kind = 'crate';", 'a property default'),
	array('public static function make($side = 2)', 'public static function make($side = 3)', 'a default parameter'),
	array('public function size()', 'protected function size()', 'a visibility'),
	array('public function size() { return 1; }', 'public function size() { return 1; } public function extra() { return 0; }', 'an added method'),
	array("\tpublic function size() { return 1; }\n", '', 'a removed method'),
	array('function helper_two($a)', 'function helper_three($a)', 'a renamed global function'),
	array('use function App\\helper;', 'use function App\\other;', 'an imported name'),
	array('class Box extends Base', 'class Box extends Other', 'a class header'),
	array('public function area(): float; }', 'public function area(): int; }', 'an interface signature'),
	array("\$top = new class", "\$other = new class", 'top-level code'),
) as $c) {
	check($edit($c[0], $c[1]) === null, $c[2] . ' changing is a whole-file change');
}
check(coverage_function_change($base, null) === null && coverage_function_change(null, $base) === null, 'a file added or removed is a whole-file change');
check(coverage_function_change($base, $base . "\nfunction (") === null, 'a file that does not parse is a whole-file change');
check(coverage_function_change($base, str_replace('return 1; }' . "\n}", "return 1; /* why */ }\n}", $base)) === array(),
	'a comment inside a body changes nothing');

check(coverage_function_key('App\\Things\\Box::Area') === 'box::area' && coverage_function_key('App\\helper_Two') === 'helper_two',
	'a traced name drops its namespace and case');
check(coverage_ran_any(array('box::size' => true), array('box::size' => true, 'x::y' => true)) === 'box::size', 'a suite that ran the method is hit');
check(coverage_ran_any(array('box::size' => true), array('box::area' => true)) === '', 'a suite that ran only others is not');
check(coverage_ran_any(array('*::label' => true), array('crate::label' => true)) === '*::label', 'a by-name key matches the method in any class');

$fn_map = array(
	'public_html/tests/vault/registry_test.php' => array('files' => array('public_html/includes/VaultCrypto.php'), 'covers' => array(),
		'functions' => array('vaultcrypto::seal' => true)),
	'public_html/plugins/mailbox/tests/drafts_test.php' => array('files' => array('public_html/includes/VaultCrypto.php'), 'covers' => array(),
		'functions' => array('vaultcrypto::open' => true)),
	'public_html/tests/functional/api/member_screens_test.php' => array('files' => array('public_html/includes/VaultCrypto.php'), 'covers' => array()),
	'public_html/tests/functional/sync/sync_sim_gate.sh' => array('files' => array(), 'covers' => array()),
);
$vc = 'public_html/includes/VaultCrypto.php';
$r = coverage_select(array($vault, $mailbox), $fn_map, array($vc), array(), array($vc => array('vaultcrypto::seal' => true)));
check(array_keys($r['selected']) === array('public_html/tests/vault/registry_test.php')
	&& strpos($r['selected']['public_html/tests/vault/registry_test.php'], 'runs vaultcrypto::seal') === 0,
	'a body edit selects the suite that ran it, naming the function, and spares the one that did not', json_encode($r['selected']));
check($r['uncovered'] === array(), 'a file reached but sparing every suite is still not "uncovered"');
$r = coverage_select(array($vault, $mailbox), $fn_map, array($vc), array(), array($vc => null));
check(count($r['selected']) === 2, 'a whole-file change selects every suite that loaded it');
$r = coverage_select(array($vault, $mailbox), $fn_map, array($vc));
check(count($r['selected']) === 2, 'no function answer for the file: file level');
$r = coverage_select(array($web), $fn_map, array($vc), array(), array($vc => array('vaultcrypto::seal' => true)));
check(isset($r['selected']['public_html/tests/functional/api/member_screens_test.php']),
	'a suite with no function set is file level (and a dev-web suite keeps its core rule)');
$r = coverage_select(array($vault), $fn_map, array('public_html/tests/vault/registry_test.php'), array(),
	array('public_html/tests/vault/registry_test.php' => array('x' => true)));
check(isset($r['selected']['public_html/tests/vault/registry_test.php']), "a suite's own file still selects it whatever changed inside");

section('Function sets');
$fs_root = sys_get_temp_dir() . '/fnset_test_' . getmypid() . '/public_html';
@mkdir(dirname($fs_root) . '/cache', 0777, true);
harness_defer(function () use ($fs_root) { shell_exec('rm -rf ' . escapeshellarg(dirname($fs_root))); });
$id1 = coverage_fnset_put($fs_root, array('App\\Box::Size', 'helper'));
$id2 = coverage_fnset_put($fs_root, array('helper', 'Box::size'));
check($id1 !== '' && $id1 === $id2, 'a set is stored by content: the same functions in any order and namespace give one id');
check(coverage_fnset_get($fs_root, $id1) === array('box::size' => true, 'helper' => true), 'and reads back as normalized keys');
check(coverage_fnset_get($fs_root, str_repeat('a', 40)) === null && coverage_fnset_get($fs_root, '../x') === null,
	'a missing or malformed id reads as nothing (file level), never as an empty set');
touch(coverage_fnset_dir($fs_root) . '/' . $id1 . '.json', time() - 100);
coverage_fnsets_prune($fs_root, 50);
check(!is_file(coverage_fnset_dir($fs_root) . '/' . $id1 . '.json'), 'an old set the map does not name is pruned');
$gone = coverage_fnset_put($fs_root, array('never_read'));
@unlink(coverage_fnset_dir($fs_root) . '/' . $gone . '.json');
$m = array('public_html/tests/x_test.php' => array('files' => array('public_html/includes/A.php'), 'functions_set' => $gone));
check(!isset(coverage_map_with_functions($m, array('public_html/includes/A.php' => array('a::b' => true)), $fs_root)['public_html/tests/x_test.php']['functions']),
	'a map entry whose set is gone stays file level');

section('The contract records reach');

// The map is only as honest as the contract underneath it: a suite's emitted
// JSON must carry the repo files its process loaded.
$out = (string)shell_exec(escapeshellarg(PHP_BINARY)
	. ' ' . escapeshellarg(dirname(__DIR__) . '/unit/box_variants_test.php') . ' --json 2>/dev/null');
$pos = strrpos($out, JOINERY_RESULT_SENTINEL);
$contract = $pos === false ? null : json_decode(trim(substr($out, $pos + strlen(JOINERY_RESULT_SENTINEL))), true);
check(is_array($contract) && !empty($contract['files']), 'a suite contract carries its loaded files');
check(is_array($contract) && !in_array(true, array_map(function ($f) {
	return strpos($f, 'vendor/') === 0 || strpos($f, '/') === 0;
}, $contract['files'] ?? array()), true), 'repo-relative, vendor excluded');
if (extension_loaded('xhprof')) {
	// With the trace prepended, as the runner does, the contract names the functions run.
	$out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d auto_prepend_file='
		. escapeshellarg(dirname(__DIR__) . '/lib/function_trace.php') . ' '
		. escapeshellarg(dirname(__DIR__) . '/unit/box_variants_test.php') . ' --json 2>/dev/null');
	$pos = strrpos($out, JOINERY_RESULT_SENTINEL);
	$traced = $pos === false ? null : json_decode(trim(substr($out, $pos + strlen(JOINERY_RESULT_SENTINEL))), true);
	check(is_array($traced) && in_array('harness_boot', $traced['functions'] ?? array(), true),
		'a traced suite contract carries the functions it ran', is_array($traced) ? count($traced['functions'] ?? array()) . ' functions' : 'no contract');
	check(is_array($contract) && !isset($contract['functions']), 'an untraced one carries none');
} else {
	harness_skip('xhprof is not loaded here: no function trace to check');
}

harness_finish();
