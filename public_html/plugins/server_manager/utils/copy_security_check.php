<?php
/**
 * copy_security_check.php — the security a site's machine promises, read on
 * this management node from what the machine reports (CopySecurityCheck;
 * specs/site_copy_final_test.md, the rig). Read-only, unless --refresh.
 *
 *   php copy_security_check.php NODE_ID [--quiet] [--baseline=FILE] [--save=FILE] [--refresh]
 *
 *   --quiet         the site is expected quiet (a dormant copy, or frozen)
 *   --save=FILE     keep these rows, as a baseline for the copy
 *   --baseline=FILE compare: a row that passed there and does not here fails
 *   --refresh       queue a host report (and a container's host's inventory),
 *                   then run again once they finish
 *
 * One line per row: PASS, FAIL, UNKNOWN or N/A, what, and its evidence.
 * Exit 0 when nothing failed (and nothing is weaker than the baseline), 1 when
 * something did.
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') {
	http_response_code(404);
	exit("This runs from the command line only.\n");
}

require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));

$node_id = 0;
$opts = array();
foreach (array_slice($argv, 1) as $arg) {
	if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $arg, $m)) {
		$opts[$m[1]] = $m[2] ?? true;
	} elseif (ctype_digit($arg)) {
		$node_id = (int)$arg;
	}
}
if ($node_id <= 0) {
	fwrite(STDERR, "usage: copy_security_check.php NODE_ID [--quiet] [--baseline=FILE] [--save=FILE] [--refresh]\n");
	exit(2);
}
$node = new ManagedNode($node_id, TRUE);
if (!$node->key || $node->get('mgn_delete_time')) {
	fwrite(STDERR, "node {$node_id} is not on record\n");
	exit(2);
}
if (!empty($opts['refresh'])) {
	$jobs = CopySecurityCheck::refresh($node);
	echo 'queued job(s) ' . implode(', ', $jobs) . "; run again once they finish\n";
	exit(0);
}
$rows = CopySecurityCheck::rows($node, !empty($opts['quiet']));
echo implode("\n", CopySecurityCheck::lines($rows)), "\n";
$failed = false;
foreach ($rows as $row) {
	$failed = $failed || ($row['applies'] && $row['pass'] === false);
}
if (is_string($opts['save'] ?? null)) {
	file_put_contents($opts['save'], json_encode($rows));
	echo "saved as the baseline: {$opts['save']}\n";
}
if (is_string($opts['baseline'] ?? null)) {
	$baseline = json_decode((string)@file_get_contents($opts['baseline']), true);
	if (!is_array($baseline)) {
		fwrite(STDERR, "the baseline {$opts['baseline']} cannot be read\n");
		exit(2);
	}
	$weaker = CopySecurityCheck::weaker($baseline, $rows);
	foreach ($weaker as $row) {
		echo 'WEAKER  ' . $row['what'] . ' — passed on the source, not here: ' . $row['evidence'] . "\n";
	}
	$failed = $failed || (bool)$weaker;
}
exit($failed ? 1 : 0);
