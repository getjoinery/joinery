<?php
/** @joinery-test
 * name: collection_sort_injection
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * A collection sort names a COLUMN, and SQL has no bind placeholder for an
 * identifier — so the column is interpolated, and the only defense is proving it
 * is a column name before it reaches the query.
 *
 * This is a regression guard for a real, exploitable blind SQL injection: the
 * REST collection endpoint forwarded its `sort` query parameter into ORDER BY
 * with only the DIRECTION sanitized. `sort=usr_user_id, (SELECT CASE WHEN 1=1
 * THEN 1 ELSE 1/0 END)` returned 200 and the same expression with `1=0` returned
 * 500 — a boolean oracle any authenticated caller could use to read the database
 * one bit at a time, on every collection endpoint.
 *
 * The check lives in SystemBase::_get_resultsv2(), not in the API, so an
 * internal caller that forwards user input is covered too. These tests therefore
 * go through the model layer, which is where the guarantee actually is.
 *
 * Read-only: every query here is a SELECT that never runs, because the refusal
 * happens before execution.
 *
 * Run: php tests/api/collection_sort_injection_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/passkeys_class.php'));

/** Load a collection with $sort and report what happened. */
function sort_attempt($sort) {
	try {
		$m = new MultiUser(array(), $sort);
		$m->load();
		return array('ok' => true, 'error' => null);
	} catch (UnsortableColumnException $e) {
		return array('ok' => false, 'error' => $e->getMessage());
	} catch (\Throwable $e) {
		return array('ok' => false, 'error' => 'WRONG EXCEPTION ' . get_class($e) . ': ' . $e->getMessage());
	}
}

// ---------------------------------------------------------------------------
section('The injection is refused');

// The exact payload pair that proved the oracle. Both must be refused, and
// refused the SAME way — a difference between them is the oracle.
$true_branch  = sort_attempt(array('usr_user_id, (SELECT CASE WHEN 1=1 THEN 1 ELSE 1/0 END)' => 'ASC'));
$false_branch = sort_attempt(array('usr_user_id, (SELECT CASE WHEN 1=0 THEN 1 ELSE 1/0 END)' => 'ASC'));
check(!$true_branch['ok'] && !$false_branch['ok'],
	'both halves of the boolean oracle are refused',
	'true: ' . var_export($true_branch, true) . ' false: ' . var_export($false_branch, true));
check($true_branch['error'] === $false_branch['error'],
	'and refused identically — a difference between the two IS the oracle',
	'true: ' . $true_branch['error'] . ' / false: ' . $false_branch['error']);

$payloads = array(
	'subquery'          => 'usr_user_id, (SELECT 1)',
	'single quote'      => "usr_user_id'",
	'comment tail'      => 'usr_user_id--',
	'stacked statement' => 'usr_user_id; DROP TABLE usr_users',
	'function call'     => 'length(usr_email)',
	'whitespace'        => 'usr_user_id DESC, usr_email',
	'union attempt'     => 'usr_user_id UNION SELECT 1',
	'parenthesis'       => '(usr_user_id)',
);
foreach ($payloads as $label => $payload) {
	$r = sort_attempt(array($payload => 'ASC'));
	check(!$r['ok'] && strpos((string)$r['error'], 'plain column name') !== false,
		'refused: ' . $label,
		'got: ' . var_export($r, true));
}

// The direction was always sanitized; prove that has not regressed while the
// column gate was added around it.
$r = sort_attempt(array('usr_user_id' => 'ASC; DROP TABLE usr_users'));
check($r['ok'], 'a hostile direction is still neutralized rather than refused',
	'got: ' . var_export($r, true));

// ---------------------------------------------------------------------------
section('Legitimate sorts still work');

$r = sort_attempt(array('usr_user_id' => 'ASC'));
check($r['ok'], 'a declared column sorts', 'got: ' . var_export($r, true));

$r = sort_attempt(array('usr_user_id' => 'DESC'));
check($r['ok'], 'descending works', 'got: ' . var_export($r, true));

// Prefix inference is the reason the gate runs AFTER the prefix is added:
// callers may pass the bare name and the platform expands it.
$r = sort_attempt(array('user_id' => 'ASC'));
check($r['ok'], 'an unprefixed column name still resolves through prefix inference',
	'got: ' . var_export($r, true));

$r = sort_attempt(array('usr_email' => 'ASC', 'usr_user_id' => 'DESC'));
check($r['ok'], 'a multi-column sort works', 'got: ' . var_export($r, true));

$r = sort_attempt(array());
check($r['ok'], 'no sort at all is fine', 'got: ' . var_export($r, true));

// ---------------------------------------------------------------------------
section('An undeclared column is named, not 500');

// Gate two. Without it a bad column reaches Postgres and comes back a server
// error, which also leaks column existence by status code.
$r = sort_attempt(array('usr_no_such_column' => 'ASC'));
check(!$r['ok'] && strpos((string)$r['error'], 'not a sortable column') !== false,
	'an undeclared column is refused by name',
	'got: ' . var_export($r, true));

// The guard is per-model, not a global column list: one model's column is not
// sortable on another's collection.
try {
	$m = new MultiPasskey(array('user_id' => 1), array('usr_email' => 'ASC'));
	$m->load();
	$leaked = true;
	$msg = '';
} catch (UnsortableColumnException $e) {
	$leaked = false;
	$msg = $e->getMessage();
} catch (\Throwable $e) {
	$leaked = false;
	$msg = 'WRONG EXCEPTION ' . get_class($e);
}
check(!$leaked && strpos($msg, 'pkc_passkey_credentials') !== false,
	'the allowlist is the queried model\'s own columns, not any model\'s',
	'got: ' . $msg);

harness_finish();
