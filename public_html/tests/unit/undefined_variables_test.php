<?php
/** @joinery-test
 * name: undefined_variables
 * tier: safe
 * env: dev-only
 * needs: [psalm]
 * timeout: 300
 * covers: [public_html/**.php, maintenance_scripts/**.php]
 */
/**
 * No edited PHP file reads a variable on a path where it may never have been
 * set.
 *
 * Checks the PHP files git status lists as edited or new (what --changed runs
 * on), with Psalm, through tests/lib/undefined_variables.php: see there for
 * what is reported and why. Every finding fails. A run with nothing edited
 * checks nothing and passes.
 *
 * The covers glob is every PHP file, so any PHP edit runs this suite and no
 * earlier pass of it is reused for a tree whose PHP differs.
 *
 * Run: php tests/run.php --changed (or --filter=undefined_variables)
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/../lib/coverage.php');
require_once(__DIR__ . '/../lib/undefined_variables.php');
harness_boot();

$repo_root = dirname(PathHelper::getSiteRoot() . '/public_html', 1);

section('The detector itself');

// A read of a variable set in only one branch, and an include the detector
// must not let hide it. Checked in memory, so a broken stripper fails here
// rather than passing every file.
$stripped = undefvar_strip_dynamic_includes("<?php\nrequire_once(PathHelper::getIncludePath(\n'x.php'));\nrequire_once('/abs/y.php');\n\$a = 1;\n");
check(substr_count($stripped, "\n") === 5, 'a stripped include keeps every line where it was', json_encode($stripped));
check(strpos($stripped, 'PathHelper') === false && strpos($stripped, "require_once('/abs/y.php')") !== false,
	'only an include Psalm cannot resolve is stripped', json_encode($stripped));
check(undefvar_files_to_check(array('public_html/tests/unit/x_test.php', 'public_html/vendor/a/b.php', 'README.md',
		'public_html/specs/a.php', 'public_html/no_such_file.php'), $repo_root) === array(),
	'test files, vendored code, specs, non-PHP and deleted files are not checked');


// Psalm's cache lives with whoever runs it: it touches its own cache files,
// which a shared directory under the site's cache/ (the web user's) refuses.
// Psalm drops its cache when its config changes, so the config is written to
// the same place every run, which one run at a time holds.
$cache = undefvar_cache_dir();
$work = $cache . '/work';
$work_lock = fopen($cache . '/work.lock', 'c');
if ($work_lock === false || !flock($work_lock, LOCK_EX)) {
	check(false, 'the work directory can be held', $cache);
	harness_finish();
}
harness_defer(function () use ($work_lock) { flock($work_lock, LOCK_UN); fclose($work_lock); });

// The probe: a function that reads a variable one branch never sets, checked
// in the same Psalm run as the edited files. It must be found, or a clean
// result for them means nothing.
@mkdir($work, 0770, true);
$probe_label = 'probe (a planted read of a variable one branch never sets)';
file_put_contents($work . '/probe.php', "<?php\nfunction undefvar_probe(\$x) {\n"
	. "\trequire_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));\n"
	. "\tif (\$x) { \$a = 1; } else { \$b = 2; }\n\treturn \$a;\n}\n");

$changed = coverage_changed_files($repo_root);
$files = $changed === null ? array() : undefvar_files_to_check($changed, $repo_root);
$sources = array($probe_label => $work . '/probe.php');
foreach ($files as $rel) { $sources[$rel] = $repo_root . '/' . $rel; }

$findings = null;
try {
	$findings = undefvar_check($sources, $repo_root, $work, $cache);
} catch (Throwable $e) {
	check(false, 'Psalm runs', $e->getMessage());
}

if ($findings !== null) {
	$by_file = array();
	foreach ($findings as $f) { $by_file[$f['file']][] = $f; }
	$probe = $by_file[$probe_label] ?? array();
	check(count($probe) === 1 && $probe[0]['line'] === 5 && $probe[0]['type'] === 'PossiblyUndefinedVariable',
		'a variable set in one branch only is found, past an include Psalm cannot resolve', json_encode($probe));

	section('The edited PHP files');
	if ($changed === null) {
		check(false, 'git status answers here');
	} elseif (!$files) {
		check(true, 'no PHP file is edited, so there is nothing to check');
	} else {
		foreach ($files as $rel) {
			foreach ($by_file[$rel] ?? array() as $f) {
				check(false, $f['file'] . ':' . $f['line'] . ' ' . $f['message'], $f['type']);
			}
		}
		check(true, count($files) . ' edited PHP file(s) checked');
	}
}

harness_finish();
