<?php
/** @joinery-test
 * name: formwriter_unknown_option
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * PURPOSE: An input option FormWriter never reads must be refused, not dropped.
 *
 * THE DEFECT THIS PINS. Nothing happened when a field option was misspelled. The
 * field rendered, the page looked finished, and whatever the option asked for was
 * simply absent. `help_text` instead of `helptext` meant every explanation written
 * to stop a silent failure was itself silently dropped — in five files, including
 * the DNS publish box's "leave this unticked and the record is skipped, not
 * silently changed", which is the one sentence standing between an operator and a
 * publish they believe worked. A sweep found nineteen dead options across eight
 * names: help, hint, use_editor, showdefault, title, prefix and two jQuery
 * data-rule attributes on a platform that has no jQuery.
 *
 * What is pinned:
 *   - the known set is DERIVED from the writer's source and its parents', so
 *     implementing an option is declaring it and no hand-list can fall behind
 *   - an unknown option is refused and names the nearest real one: debug stops
 *     the page, production logs it and renders, and both carry the same message
 *   - every option the shipped writers actually read is accepted
 *   - no caller in the tree passes an option FormWriter does not read
 *
 * The last one is the regression guard that matters: the check is only worth
 * having while the tree is clean, and a page that throws on load is a worse
 * outcome than the silence it replaced.
 *
 * Runs offline — no network, no DB.
 *
 * Run:  php tests/unit/formwriter_unknown_option_test.php
 *
 * @version 1.1 - Reads the refusal from whichever channel the site uses instead
 *                of only the debug throw. Declared `env: any`, it asserted dev
 *                behaviour, so ten of its checks failed on any production-mode
 *                install with nothing wrong — found on a fresh 26.04 box. The
 *                production path (log and render) is now pinned too.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/FormWriterV2HTML5.php'));

if (session_status() === PHP_SESSION_NONE) {
	session_start();
}

/** The message of any Throwable $fn raises, or '' when it raises none. */
function fw_thrown(callable $fn): string {
	try { $fn(); return ''; }
	catch (Throwable $e) { return $e->getMessage(); }
}

/**
 * The refusal message for $fn, read from whichever channel this site uses.
 *
 * The contract is deliberately environment-dependent — debug throws, production
 * logs and renders, because a live site refusing a page over a misspelled help
 * string would be the worse failure. Reading only the throw made this test
 * assert dev behaviour while declaring `env: any`, so every check below it
 * failed on a production-mode install with nothing wrong. Both channels carry
 * the same message, so both are pinned here and the test means the same thing
 * wherever it runs.
 *
 * Returns '' when the option was accepted, and a message beginning
 * UNEXPECTED THROW when production raised where it should have logged.
 */
function fw_refusal(callable $fn): string {
	if (Globalvars::get_instance()->get_setting('debug', false, true)) {
		return fw_thrown($fn);
	}
	$log  = tempnam(sys_get_temp_dir(), 'fw_refusal_');
	$prev = ini_get('error_log');
	ini_set('error_log', $log);
	$threw = '';
	try { $fn(); }
	catch (Throwable $e) { $threw = 'UNEXPECTED THROW: ' . $e->getMessage(); }
	ini_set('error_log', $prev);
	$logged = trim((string)@file_get_contents($log));
	@unlink($log);
	return $threw !== '' ? $threw : $logged;
}

// ---------------------------------------------------------------------------
section('The vocabulary is derived from the code, never hand-maintained');
// ---------------------------------------------------------------------------

$known = FormWriterV2HTML5::knownOptionKeys();
check(!empty($known), 'the writer can state which options it reads', count($known) . ' keys');

// Options read by the base class and by the HTML5 subclass both count: a theme
// writer that adds options of its own gets them without registering anything.
check(isset($known['helptext']), 'a base-class option is known');
check(isset($known['visibility_rules']), 'and so is one only the renderer reads');
check(isset($known['options']) && isset($known['value']) && isset($known['required']),
	'the everyday options are all in the set');

// The exact misspelling that started this.
check(!isset($known['help_text']),
	'help_text is not an option — which is the whole point, since it looks like one');

// ---------------------------------------------------------------------------
section('An unknown option stops the page instead of being ignored');
// ---------------------------------------------------------------------------

$form = new FormWriterV2HTML5('fw_probe_form');

$msg = fw_refusal(function () use ($form) {
	ob_start();
	$form->textinput('probe_a', 'Probe', array('help_text' => 'dropped on the floor'));
	ob_end_clean();
});
check($msg !== '', 'a misspelled option raises rather than rendering a field without it');
check(strpos($msg, 'help_text') !== false, 'the message names the option that was wrong', $msg);
check(strpos($msg, 'probe_a') !== false, 'and the field it was passed to');
check(strpos($msg, "'helptext'") !== false,
	'and the nearest real option, so the fix does not need a source read', $msg);

// A word nothing is close to gets no suggestion. Naming the wrong option is
// worse than naming none.
$msg = fw_refusal(function () use ($form) {
	ob_start();
	$form->textinput('probe_b', 'Probe', array('zzqqxx' => 1));
	ob_end_clean();
});
check($msg !== '' && strpos($msg, 'Did you mean') === false,
	'an invented option is refused with no guess attached', $msg);

// Real options still render, which is the check that keeps this from being a
// rule that only says no.
$ok = fw_refusal(function () use ($form) {
	ob_start();
	$form->textinput('probe_c', 'Probe', array(
		'helptext' => 'shown', 'maxlength' => 20, 'required' => true,
		'placeholder' => 'x', 'autocomplete' => 'off',
	));
	ob_end_clean();
});
check($ok === '', 'a field of entirely real options renders untouched', $ok);

// The value FormWriter itself adds during registration must never trip it.
$ok = fw_refusal(function () {
	$bound = new FormWriterV2HTML5('fw_bound_form');
	$bound->set_values(array('probe_d' => 'from the model'));
	ob_start();
	$bound->textinput('probe_d', 'Probe', array('maxlength' => 10));
	ob_end_clean();
});
check($ok === '', 'an option FormWriter fills in for itself is not treated as a caller mistake', $ok);

// Every field type funnels through registerField(), so the check reaches all of
// them rather than the one it was written against.
foreach (array(
	'dropinput'      => array('options' => array('a' => 'A'), 'help_text' => 'x'),
	'checkboxinput'  => array('help_text' => 'x'),
	'textbox'        => array('rows' => 3, 'help_text' => 'x'),
	'dateinput'      => array('help_text' => 'x'),
	'passwordinput'  => array('help_text' => 'x'),
) as $method => $options) {
	$msg = fw_refusal(function () use ($form, $method, $options) {
		ob_start();
		$form->$method('probe_' . $method, 'Probe', $options);
		ob_end_clean();
	});
	check($msg !== '', $method . '() refuses an unknown option too');
}

// ---------------------------------------------------------------------------
section('No caller in the tree passes an option FormWriter does not read');
// ---------------------------------------------------------------------------
//
// Without this the check is a trap: every dead option in the tree becomes a page
// that throws on a dev box. The sweep is static — it reads the option keys out of
// the argument arrays at each call site — so it costs nothing and needs no page
// to be loaded.

$field_methods = array('textinput', 'numberinput', 'passwordinput', 'dropinput',
	'checkboxinput', 'radioinput', 'checkboxList', 'dateinput', 'timeinput',
	'datetimeinput', 'fileinput', 'hiddeninput', 'textbox', 'imageinput',
	'imageselector', 'colorpicker', 'textarea', 'submitbutton', 'repeater');
$field_methods = array_flip(array_map('strtolower', $field_methods));

$offenders = array();
$root = rtrim(PathHelper::getIncludePath(''), '/');
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,
	FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
	if (!$file->isFile() || substr($file->getFilename(), -4) !== '.php') { continue; }
	$path = $file->getPathname();
	if (strpos($path, '/vendor/') !== false) { continue; }
	// This file passes bad options on purpose, a few sections up.
	if ($path === __FILE__) { continue; }
	$src = (string)file_get_contents($path);
	if (strpos($src, 'ormWriter') === false && strpos($src, '$form') === false) { continue; }

	$tokens = @token_get_all($src);
	$n = count($tokens);
	for ($i = 0; $i < $n; $i++) {
		if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_OBJECT_OPERATOR) { continue; }
		$j = $i + 1;
		while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
		if ($j >= $n || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) { continue; }
		if (!isset($field_methods[strtolower($tokens[$j][1])])) { continue; }
		$line = $tokens[$j][2];
		$k = $j + 1;
		while ($k < $n && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) { $k++; }
		if ($k >= $n || $tokens[$k] !== '(') { continue; }

		// Depth 2 is a key sitting directly inside an argument array — the option
		// map itself, not a nested option list or validation rule set.
		$depth = 0;
		for ($p = $k; $p < $n; $p++) {
			$text = is_array($tokens[$p]) ? $tokens[$p][1] : $tokens[$p];
			if ($text === '(' || $text === '[') { $depth++; continue; }
			if ($text === ')' || $text === ']') {
				$depth--;
				if ($depth === 0) { break; }
				continue;
			}
			if ($depth !== 2 || !is_array($tokens[$p])
					|| $tokens[$p][0] !== T_CONSTANT_ENCAPSED_STRING) { continue; }
			$q = $p + 1;
			while ($q < $n && is_array($tokens[$q]) && $tokens[$q][0] === T_WHITESPACE) { $q++; }
			if ($q >= $n || !is_array($tokens[$q]) || $tokens[$q][0] !== T_DOUBLE_ARROW) { continue; }
			$key = trim($tokens[$p][1], "'\"");
			if ($key !== '' && !isset($known[$key])) {
				$offenders[] = str_replace($root . '/', '', $path) . ':' . $line . " passes '" . $key . "'";
			}
		}
	}
}

check(empty($offenders),
	'every option key handed to a field method is one the writer reads',
	empty($offenders) ? 'clean' : implode(' | ', array_slice(array_unique($offenders), 0, 8)));

harness_finish();
