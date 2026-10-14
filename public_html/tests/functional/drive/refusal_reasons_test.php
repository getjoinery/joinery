<?php
/** @joinery-test
 * name: drive_refusal_reasons
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * Every refusal a sync client can meet says why.
 *
 * The client decides what to do with a refusal (send it again, plan again from
 * the disk, hold it, or step aside from a taken name) by its machine-readable
 * `reason`, never by its prose. The feed tells it these actions always give
 * one (`refusals: reasoned`), and a client that has heard that stops guessing.
 * So a refusal added to one of these actions without a reason is read as a
 * server fault and withdrawn. This reads the logic files and fails on any
 * `LogicResult::error(` call without one (docs/drive.md, Refusal reasons).
 *
 * Run: php tests/functional/drive/refusal_reasons_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../lib/harness.php');
harness_boot();

// The actions a sync client calls.
$actions = array(
	'drive_changes', 'drive_index', 'drive_stat', 'drive_list',
	'drive_upload_init', 'drive_upload_complete', 'drive_folder_create',
	'drive_rename', 'drive_move', 'drive_trash', 'drive_public_keys',
);

/** Each `LogicResult::error(...)` call in $source, with its line. */
function rr_error_calls($source) {
	$calls = array();
	$offset = 0;
	while (($at = strpos($source, 'LogicResult::error(', $offset)) !== false) {
		$i = $at + strlen('LogicResult::error(');
		$depth = 1;
		$quote = null;
		$len = strlen($source);
		while ($depth > 0 && $i < $len) {
			$c = $source[$i];
			if ($quote !== null) {
				if ($c === '\\') { $i++; }
				elseif ($c === $quote) { $quote = null; }
			} elseif ($c === "'" || $c === '"') {
				$quote = $c;
			} elseif ($c === '(') {
				$depth++;
			} elseif ($c === ')') {
				$depth--;
			}
			$i++;
		}
		$calls[] = array(substr_count(substr($source, 0, $at), "\n") + 1, substr($source, $at, $i - $at));
		$offset = $i;
	}
	return $calls;
}

section('Every refusal in the sync-facing actions carries a reason');

$total = 0;
foreach ($actions as $action) {
	$path = PathHelper::getIncludePath('logic/' . $action . '_logic.php');
	$source = file_get_contents($path);
	check($source !== false, "$action: logic file read");
	if ($source === false) {
		continue;
	}
	$missing = array();
	foreach (rr_error_calls($source) as $call) {
		list($line, $text) = $call;
		$total++;
		if (strpos($text, "'reason'") === false) {
			$missing[] = "line $line";
		}
	}
	check(empty($missing), "$action: every refusal names its reason", implode(', ', $missing));
}
check($total > 80, 'the scan found the refusals it reads', "$total calls");

section('The feed says refusals are reasoned');

foreach (array('drive_changes', 'drive_index') as $action) {
	$source = file_get_contents(PathHelper::getIncludePath('logic/' . $action . '_logic.php'));
	$answers = substr_count($source, 'LogicResult::render(array(');
	$marked = substr_count($source, "'refusals'");
	check($answers > 0 && $marked === $answers, "$action: every answer carries refusals: reasoned", "$marked of $answers");
}

harness_finish();
