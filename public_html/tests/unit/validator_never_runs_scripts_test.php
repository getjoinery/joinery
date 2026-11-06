<?php
/** @joinery-test
 * name: validator_never_runs_scripts
 * tier: safe
 * env: any
 * needs: []
 * timeout: 240
 * covers: [maintenance_scripts/dev_tools/validate_php_file.php]
 */
/**
 * validate_php_file.php checks the calls a file makes. It includes the file
 * only when the file just declares, or is a class file in includes/ or data/
 * (which the class autoloader includes on every request anyway). A script is
 * read from its source: including utils/upgrade.php once ran a real upgrade.
 *
 *  - a script that writes a marker when run is not run, and the function it
 *    defines still counts as found
 *  - a script whose only code is a guarded class declaration is not run
 *  - a function from a file the script requires by a written-out path counts
 *    as found, that file read and not run
 *  - a file that only declares is included
 *  - a model class file with top-level code (data/users_class.php) is still
 *    included, so its own checks keep their reflection
 *
 * Offline, no DB writes. Run: php tests/unit/validator_never_runs_scripts_test.php
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$validator = dirname(__DIR__, 3) . '/maintenance_scripts/dev_tools/validate_php_file.php';
$dir = harness_scratch_dir('validator');

function vnr_run($validator, $file) {
	$out = array();
	exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($validator) . ' ' . escapeshellarg($file) . ' 2>&1', $out);
	return implode("\n", $out);
}

section('A script is read from its source, never run');
$marker = "$dir/marker";
file_put_contents("$dir/script.php", "<?php\n/** a script */\nfunction vnr_helper() { return 1; }\n"
	. "file_put_contents(" . var_export($marker, true) . ", 'ran');\nvnr_helper();\n");
$out = vnr_run($validator, "$dir/script.php");
check(!file_exists($marker), 'the script did not run', $out);
check(strpos($out, 'Not loaded: line 4') !== false, 'the validator names the line that would run', $out);
check(preg_match('/Missing: 0\b/', $out) === 1, 'the function the script defines counts as found', $out);

$marker2 = "$dir/marker2";
file_put_contents("$dir/guarded.php", "<?php\nif (!class_exists('VnrGuarded', false)) { class VnrGuarded {} }\n"
	. "file_put_contents(" . var_export($marker2, true) . ", 'ran');\n");
$out = vnr_run($validator, "$dir/guarded.php");
check(!file_exists($marker2), 'a guarded class declaration does not make the file loadable', $out);

file_put_contents("$dir/lib.php", "<?php\nfunction vnr_from_lib() { return 2; }\n");
$marker3 = "$dir/marker3";
file_put_contents("$dir/uses_lib.php", "<?php\nrequire_once(__DIR__ . '/lib.php');\n"
	. "file_put_contents(" . var_export($marker3, true) . ", 'ran');\nvnr_from_lib();\n");
$out = vnr_run($validator, "$dir/uses_lib.php");
check(!file_exists($marker3) && preg_match('/Missing: 0\b/', $out) === 1,
	'a function from a file the script requires counts as found, read and not run', $out);

section('Files that declare are included');
file_put_contents("$dir/decl.php", "<?php\nnamespace Vnr;\nuse ArrayObject;\nconst X = 1;\n"
	. "#[\\Attribute]\nfinal class Decl { public function a() { return 1; } }\nfunction vnr_decl() { return new Decl(); }\n");
$out = vnr_run($validator, "$dir/decl.php");
check(strpos($out, 'File loaded successfully') !== false, 'a file that only declares is included', $out);

$out = vnr_run($validator, PathHelper::getIncludePath('data/users_class.php'));
check(strpos($out, 'File loaded successfully') !== false, 'a model class file with top-level code is still included', $out);

harness_finish();
