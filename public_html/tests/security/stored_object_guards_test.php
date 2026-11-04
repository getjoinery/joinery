<?php
/** @joinery-test
 * name: stored_object_guards
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * A session file or an APCu entry never turns into an object of a class the
 * platform did not put there (specs/unseal_daemon.md C4).
 *
 * The web user writes both, and PHP rebuilds every object either names,
 * autoloading its class, before any code of ours sees the value. Each check
 * plants an object of a class that records being loaded, then reads it the
 * way a later request would:
 *
 *  - SessionGuard::classesIn() lists the classes a stored session names
 *    without building anything, and is not fooled by the same text inside a
 *    string;
 *  - a session naming a class outside SessionGuard::ALLOWED_CLASSES starts
 *    empty, and its class is never loaded; a session of flash messages reads
 *    back whole;
 *  - ApcuStore::fetch() refuses an entry holding any object, never loads a
 *    planted class, and deletes the entry; plain values read as before;
 *  - no production code calls session_start() or apcu_fetch() around them.
 *
 * Run: php tests/security/stored_object_guards_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$scratch = harness_scratch_dir('stored_object_guards');
$path_helper = PathHelper::getIncludePath('includes/PathHelper.php');

section('The session parser lists classes and builds nothing');
$enc = 'usr_user_id|i:5;saved_messages|a:1:{i:0;O:14:"DisplayMessage":2:{s:7:"message";s:2:"hi";s:5:"shown";b:0;}}'
	. 'note|s:21:"O:6:"Gadget":0:{}|x;y";n|N;f|d:1.5;inf|d:INF;r|R:2;!gone|';
check(SessionGuard::classesIn($enc, 'php') === array('DisplayMessage'), 'flash messages are the only class named; a lookalike inside a string is not one',
	json_encode(SessionGuard::classesIn($enc, 'php')));
foreach (array(
	'an object'            => 'x|O:6:"Gadget":0:{}',
	'a nested object'      => 'x|a:1:{i:0;a:1:{s:1:"k";O:6:"Gadget":0:{}}}',
	'a custom-serialized'  => 'x|C:6:"Gadget":2:{ab}',
	'an enum'              => 'x|E:8:"Gadget:A";',
	'an object property'   => 'x|O:14:"DisplayMessage":1:{s:1:"m";O:6:"Gadget":0:{}}',
) as $label => $planted) {
	$classes = SessionGuard::classesIn($planted, 'php');
	check(is_array($classes) && in_array('Gadget', $classes, true), "names the class in $label", json_encode($classes));
}
foreach (array('garbage', 'x|s:5:"abc";', 'x|O:6:"Gadget":1:{}', 'x|S:1:"a";', 'x|i:1', 'x|a:1:{i:0;}') as $bad) {
	check(SessionGuard::classesIn($bad, 'php') === null, 'refuses malformed ' . json_encode($bad));
}
check(SessionGuard::classesIn(serialize(array('a' => 1, 'm' => array())), 'php_serialize') === array(), 'reads the php_serialize format too');

// A child process per check: sessions and APCu are per process. The gadget
// class lives in a file an autoloader can find, and loading it leaves a mark.
$gadget = $scratch . '/PlantedGadget.php';
$mark = $scratch . '/gadget_loaded';
file_put_contents($gadget, '<?php file_put_contents(' . var_export($mark, true) . ', "loaded");'
	. ' class PlantedGadget { function __wakeup() { file_put_contents(' . var_export($mark, true) . ', "woke"); } }');
$boot = '<?php require_once(' . var_export($path_helper, true) . ');'
	. ' spl_autoload_register(function ($c) { if ($c === "PlantedGadget") require ' . var_export($gadget, true) . '; });';
function stored_guard_run(string $scratch, string $body, string $flags = ''): ?array {
	$file = $scratch . '/probe_' . md5($body) . '.php';
	file_put_contents($file, $body);
	$out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . $flags . ' ' . escapeshellarg($file) . ' 2>/dev/null');
	$got = json_decode($out, true);
	return is_array($got) ? $got : array('raw' => substr($out, 0, 300));
}

section('A planted session starts empty and its class is never loaded');
$sessions = $scratch . '/sessions';
@mkdir($sessions, 0700, true);
$sid = 'guardtest' . bin2hex(random_bytes(8));
file_put_contents($sessions . '/sess_' . $sid, 'usr_user_id|i:42;x|O:13:"PlantedGadget":0:{}');
$read = $boot . ' session_save_path(' . var_export($sessions, true) . '); session_id(' . var_export($sid, true) . ');'
	. ' SessionGuard::start(array("use_cookies" => 0)); echo json_encode(array("session" => $_SESSION));';
@unlink($mark);
$got = stored_guard_run($scratch, $read);
check(($got['session'] ?? null) === array(), 'the session reads as empty', json_encode($got));
check(!is_file($mark), 'PlantedGadget was never loaded or woken');

file_put_contents($sessions . '/sess_' . $sid, 'usr_user_id|i:42;saved_messages|a:1:{i:0;O:14:"DisplayMessage":1:{s:7:"message";s:5:"saved";}}');
$read2 = $boot . ' session_save_path(' . var_export($sessions, true) . '); session_id(' . var_export($sid, true) . ');'
	. ' SessionGuard::start(array("use_cookies" => 0)); $m = $_SESSION["saved_messages"][0] ?? null;'
	. ' echo json_encode(array("user" => $_SESSION["usr_user_id"] ?? null, "message" => $m instanceof DisplayMessage ? $m->message : null));';
$got = stored_guard_run($scratch, $read2);
check(($got['user'] ?? null) === 42 && ($got['message'] ?? null) === 'saved', 'a session of flash messages reads back whole', json_encode($got));

section('APCu: a planted object is refused, never loaded, and deleted');
$apcu = $boot . ' if (!function_exists("apcu_enabled") || !apcu_enabled()) { echo json_encode(array("apcu" => false)); exit; }'
	. ' $r = array("apcu" => true);'
	. ' apcu_store("guard:unloaded", array("map" => unserialize(\'O:13:"PlantedGadget":0:{}\', array("allowed_classes" => false))));'
	. ' $v = ApcuStore::fetch("guard:unloaded", $ok); $r["unloaded"] = array($v, $ok, apcu_exists("guard:unloaded"));'
	. ' apcu_store("guard:loaded", array(1, new ArrayObject(array())));'
	. ' $v = ApcuStore::fetch("guard:loaded", $ok); $r["loaded"] = array($v, $ok, apcu_exists("guard:loaded"));'
	. ' apcu_store("guard:plain", array("a" => array(1, "x"), "n" => null));'
	. ' $v = ApcuStore::fetch("guard:plain", $ok); $r["plain"] = array($v, $ok);'
	. ' $v = ApcuStore::fetch("guard:absent", $ok); $r["absent"] = array($v, $ok);'
	. ' $r["after"] = class_exists("ArrayObject") && !class_exists("PlantedGadget", false);'
	. ' echo json_encode($r);';
@unlink($mark);
$got = stored_guard_run($scratch, $apcu, '-d apc.enable_cli=1');
if (($got['apcu'] ?? null) === false) {
	check(true, 'APCu is not loaded here: nothing to plant into');
} else {
	check(($got['unloaded'] ?? null) === array(false, false, false), 'an object of a class not loaded reads as a miss and is deleted', json_encode($got['unloaded'] ?? $got));
	check(!is_file($mark), 'PlantedGadget was never loaded or woken');
	check(($got['loaded'] ?? null) === array(false, false, false), 'an object of a loaded class is refused too', json_encode($got['loaded'] ?? null));
	check(($got['plain'] ?? null) === array(array('a' => array(1, 'x'), 'n' => null), true), 'a plain value reads as before', json_encode($got['plain'] ?? null));
	check(($got['absent'] ?? null) === array(false, false), 'a missing key is a miss', json_encode($got['absent'] ?? null));
	check(($got['after'] ?? null) === true, 'autoloading works again after the read');
}

section('Nothing in production reads around the guards');
$around = array('session_start(' => array(), 'apcu_fetch(' => array());
$root = PathHelper::getRootDir();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
	$p = $f->getPathname();
	$rel = substr($p, strlen($root) + 1);
	if (substr($p, -4) !== '.php' || preg_match('#(^|/)(vendor|tests|specs)/#', $rel)) continue;
	$src = (string)@file_get_contents($p);
	// Code only: comments may name the functions.
	$code = '';
	foreach (@token_get_all($src) ?: array() as $t) {
		if (is_array($t) && in_array($t[0], array(T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING), true)) continue;
		$code .= is_array($t) ? $t[1] : $t;
	}
	foreach (array_keys($around) as $fn) {
		if (preg_match('/(?<![\w:>$])' . preg_quote($fn, '/') . '/', $code)) $around[$fn][] = $rel;
	}
}
check($around['session_start('] === array('includes/SessionGuard.php'), 'session_start() is called only by SessionGuard', implode(', ', $around['session_start(']));
check($around['apcu_fetch('] === array('includes/ApcuStore.php'), 'apcu_fetch() is called only by ApcuStore', implode(', ', $around['apcu_fetch(']));

harness_finish();
