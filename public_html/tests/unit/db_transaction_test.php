<?php
/** @joinery-test
 * name: db_transaction
 * tier: test-db
 * env: dev-only
 * needs: []
 */
/**
 * A transaction the code opens is closed on every way out.
 *
 * DbConnector::transaction() commits when its work returns and rolls back on
 * anything thrown, a PHP Error as well as an Exception. A transaction left
 * open keeps its row locks, and whatever the request writes afterwards joins
 * it and is lost when the connection closes, so a TypeError caught somewhere
 * higher up used to throw away writes nobody saw fail.
 *
 * The second half reads every production file: a transaction opened by hand
 * is guarded by a try that catches Throwable or closes it in a finally.
 *
 * Run: php tests/unit/db_transaction_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();

$db = DbConnector::get_instance()->get_db_link();
$db->exec('CREATE TEMP TABLE b15_tx_probe (v text)');
$rows = function () use ($db) {
	return $db->query('SELECT v FROM b15_tx_probe ORDER BY v')->fetchAll(PDO::FETCH_COLUMN);
};
$write = function ($v) use ($db) {
	$db->prepare('INSERT INTO b15_tx_probe (v) VALUES (?)')->execute(array($v));
};
$threw = function (callable $fn, $class) {
	try { $fn(); return false; } catch (Throwable $e) { return $e instanceof $class; }
};

// ---------------------------------------------------------------- own transaction

section('Its own transaction');

$got = DbConnector::transaction(function ($link) use ($write) { $write('a'); return 'done'; });
check($got === 'done', 'returns what the work returns');
check($rows() === array('a'), 'commits when the work returns', implode(',', $rows()));
check(!$db->inTransaction(), 'and leaves no transaction open');

check($threw(function () use ($write) {
	DbConnector::transaction(function () use ($write) { $write('b'); throw new RuntimeException('refused'); });
}, 'RuntimeException'), 'an Exception is rethrown');
check($rows() === array('a'), 'and its write is rolled back', implode(',', $rows()));
check(!$db->inTransaction(), 'and no transaction is left open');

// The case the helper exists for: a PHP Error, not an Exception.
check($threw(function () use ($write) {
	DbConnector::transaction(function () use ($write) { $write('c'); strlen(array()); });
}, 'TypeError'), 'a TypeError is rethrown');
check($rows() === array('a'), 'and its write is rolled back', implode(',', $rows()));
check(!$db->inTransaction(), 'and no transaction is left open behind it');

$write('d');
check($rows() === array('a', 'd'), 'so a later write in the same request is kept', implode(',', $rows()));

// ---------------------------------------------------------------- caller's transaction

section('Inside a caller\'s transaction');

$db->beginTransaction();
DbConnector::transaction(function () use ($write) { $write('e'); });
check($db->inTransaction(), 'the work joins it and does not commit it');
$db->rollBack();
check($rows() === array('a', 'd'), 'the caller\'s rollback takes the work back', implode(',', $rows()));

$db->beginTransaction();
check($threw(function () use ($write) {
	DbConnector::transaction(function () use ($write) { $write('f'); strlen(array()); });
}, 'TypeError'), 'an Error inside reaches the caller');
check($db->inTransaction(), 'and the caller\'s transaction is still the caller\'s to close');
$db->rollBack();

// A claim that must be durable before its side effect refuses to join.
$db->beginTransaction();
check($threw(function () { (new QueuedEmail(NULL))->send(false); }, 'QueuedEmailException'),
	'a queued email refuses to send inside a caller\'s transaction');
check($db->inTransaction(), 'and leaves the caller\'s transaction alone');
$db->rollBack();

// ---------------------------------------------------------------- every hand-opened transaction

section('Every transaction opened by hand handles an Error');

/**
 * The beginTransaction() calls in one file that no try closes on a PHP Error.
 *
 * The try that guards a call is the innermost one around it, else the first
 * one after it in the same function (begin, then try). That try must catch
 * Throwable or have a finally. A function that catches Throwable somewhere
 * else does not count: save() did, in a later try, while an Error between its
 * INSERT and that try left the seal transaction open.
 */
function b15_unhandled_sites($file) {
	$t = token_get_all(file_get_contents($file));
	$n = count($t);
	$skip = function ($i) use ($t, $n) {
		while ($i < $n && is_array($t[$i]) && in_array($t[$i][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) { $i++; }
		return $i;
	};
	$match = array(); $open = array();
	for ($i = 0; $i < $n; $i++) {
		$tok = $t[$i];
		if ($tok === '{' || (is_array($tok) && in_array($tok[0], array(T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES), true))) { $open[] = $i; }
		if ($tok === '}') { $match[array_pop($open)] = $i; }
	}
	$tries = array();
	for ($i = 0; $i < $n; $i++) {
		if (!is_array($t[$i]) || $t[$i][0] !== T_TRY) { continue; }
		$bs = $skip($i + 1);
		if ($t[$bs] !== '{') { continue; }
		$be = $match[$bs];
		$closes = false;
		$k = $skip($be + 1);
		while ($k < $n && is_array($t[$k]) && in_array($t[$k][0], array(T_CATCH, T_FINALLY), true)) {
			if ($t[$k][0] === T_FINALLY) { $closes = true; }
			$m = $k + 1;
			while ($t[$m] !== '{') {
				if (is_array($t[$m]) && in_array($t[$m][0], array(T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED), true)
					&& ltrim($t[$m][1], '\\') === 'Throwable') { $closes = true; }
				$m++;
			}
			$k = $skip($match[$m] + 1);
		}
		$tries[] = array('start' => $i, 'bs' => $bs, 'be' => $be, 'closes' => $closes);
	}
	$funcs = array();
	for ($i = 0; $i < $n; $i++) {
		if (!is_array($t[$i]) || $t[$i][0] !== T_FUNCTION) { continue; }
		$m = $i + 1;
		while ($m < $n && $t[$m] !== '{' && $t[$m] !== ';') { $m++; }
		if ($m < $n && $t[$m] === '{') { $funcs[] = array($m, $match[$m]); }
	}
	$bad = array();
	for ($i = 0; $i < $n; $i++) {
		if (!is_array($t[$i]) || $t[$i][0] !== T_STRING || strcasecmp($t[$i][1], 'beginTransaction') !== 0) { continue; }
		$prev = $i - 1;
		while ($prev >= 0 && is_array($t[$prev]) && $t[$prev][0] === T_WHITESPACE) { $prev--; }
		if ($prev >= 0 && is_array($t[$prev]) && $t[$prev][0] === T_FUNCTION) { continue; }
		$lo = 0; $hi = $n - 1;
		foreach ($funcs as $f) {
			if ($f[0] <= $i && $i <= $f[1] && $f[0] >= $lo) { $lo = $f[0]; $hi = $f[1]; }
		}
		$around = null; $after = null;
		foreach ($tries as $tr) {
			if ($tr['bs'] <= $i && $i <= $tr['be'] && $tr['bs'] >= $lo && (!$around || $tr['bs'] > $around['bs'])) { $around = $tr; }
			if ($tr['start'] > $i && $tr['start'] <= $hi && (!$after || $tr['start'] < $after['start'])) { $after = $tr; }
		}
		if (!($around && $around['closes']) && !($after && $after['closes'])) { $bad[] = $t[$i][2]; }
	}
	return $bad;
}

$root = realpath(__DIR__ . '/../..');
$skip = '#/(vendor|node_modules|tests)/#';
$files = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
	$path = $f->getPathname();
	if (substr($path, -4) !== '.php' || preg_match($skip, substr($path, strlen($root)))) { continue; }
	if (stripos(file_get_contents($path), 'beginTransaction') === false) { continue; }
	$files[] = $path;
}
check(count($files) > 30, 'the scan finds the files that open transactions', count($files) . ' files');

$unhandled = array();
foreach ($files as $path) {
	if ($path === $root . '/includes/DbConnector.php') { continue; }
	foreach (b15_unhandled_sites($path) as $line) {
		$unhandled[] = substr($path, strlen($root) + 1) . ':' . $line;
	}
}
check(!$unhandled,
	'every transaction opened by hand is guarded by a try that catches Throwable or has a finally; move it onto DbConnector::transaction()',
	$unhandled ? implode(', ', $unhandled) : 'none');

harness_finish();
