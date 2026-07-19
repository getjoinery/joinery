<?php
/** @joinery-test
 * name: system_base_lifecycle
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The Active Record contract every data model inherits.
 *
 * `models_crud` already drives CRUD across all 151 models generically. What it
 * does not do is pin the handful of rules a developer has to hold in their head
 * while writing one — the ones that produce no error when you get them wrong.
 * That is what this covers.
 *
 * Every rule here has cost real time in this codebase. Setting a field that
 * does not exist logs an exception nobody reads and then drops the value at
 * save, so the record saves "successfully" without it. A primary key comes back
 * from the database as a string, so `$model->key === 5` is false for row 5 —
 * the same type-strictness that produced seventy false failures in the Multi
 * suite. Defaults declared in `$field_specifications` do not exist on the
 * object until it has been saved and reloaded, so reading one on a fresh
 * instance gives NULL rather than the default. And a soft-deleted row is still
 * in the table, which is why "deleted" data keeps turning up in queries that
 * forgot to exclude it — but is invisible to the duplicate check, which is why
 * a soft-deleted row does not reserve its unique values.
 *
 * Run: php tests/unit/system_base_lifecycle_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('data/questions_class.php'));
require_once(PathHelper::getIncludePath('data/schedule_class.php'));

if (session_id() === '') { @session_start(); }

$db = DbConnector::get_instance()->get_db_link();
$RUN = bin2hex(random_bytes(3));

/** A saved, reloaded Question registered for teardown. */
function sbl_question($text) {
	$q = new Question(NULL);
	$q->set('qst_question', $text);
	$q->save();
	$q->load();
	harness_register_row('qst_questions', 'qst_question_id', $q->key);
	return $q;
}

function sbl_threw(callable $fn) {
	try { $fn(); return false; } catch (Throwable $e) { return true; }
}


section('Constructing a model');

// The constructor takes no default. `new Question()` is the single most common
// first mistake against this API, and it fails loudly rather than producing a
// half-built object.
check(sbl_threw(function () { $c = 'Question'; new $c(); }),
	'Constructing with no argument at all is an error');

$fresh = new Question(NULL);
check($fresh->key === NULL, 'A new record has no key until it is saved');
check($fresh->get('qst_question') === NULL, 'and no field values');

$saved = sbl_question('HarnessTest lifecycle ' . $RUN);
check($saved->key !== NULL, 'Saving assigns a key');

// Loading is explicit. The second constructor argument is what separates "I
// want the row" from "I want an object pointing at the row".
$deferred = new Question($saved->key);
check($deferred->get('qst_question') === NULL,
	'Constructing with an id alone does not read the row');
$deferred->load();
check($deferred->get('qst_question') === 'HarnessTest lifecycle ' . $RUN,
	'load() fetches it');

$immediate = new Question($saved->key, TRUE);
check($immediate->get('qst_question') === 'HarnessTest lifecycle ' . $RUN,
	'Passing TRUE loads on construction');


section('Identity');

// There is no getter for the primary key — the property is the API. Code that
// reaches for get_key() is guessing, and would fail at runtime rather than at
// review.
check(method_exists($saved, 'get_key') === false, 'There is no get_key() method');
check($saved->get('qst_question_id') == $saved->key,
	'The primary key column and ->key agree');

// The key comes back from PDO as a string. Pinned because comparing it strictly
// against an integer is silently false, and that is exactly the mistake that
// made the Multi suite report seventy correct queries as broken.
$reloaded = new Question($saved->key, TRUE);
check(is_string($reloaded->key), 'A loaded key is a string, not an int', gettype($reloaded->key));
check($reloaded->key == (int)$reloaded->key, 'It compares equal to its integer form loosely');
check(($reloaded->key === (int)$reloaded->key) === false,
	'and NOT equal strictly — compare keys loosely or cast');


section('Reading and writing fields');

// An unknown field reads as NULL rather than erroring, so a typo in a get()
// produces an empty value that flows onward looking like missing data.
check($saved->get('qst_not_a_real_field') === NULL, 'Reading an undeclared field gives NULL, not an error');

// Writing one is worse: it logs an exception that no caller sees, keeps the
// value in memory so a read-back appears to confirm it worked, and then drops
// it at save because there is no column. The record saves without complaint.
$ghost = sbl_question('HarnessTest ghost ' . $RUN);
// The platform's error handler prints the logged exception; captured so the
// suite's own report stays readable.
ob_start();
$wrote = sbl_threw(function () use ($ghost) { $ghost->set('qst_not_a_real_field', 'value'); });
ob_end_clean();
check($wrote === false, 'Setting an undeclared field does not raise to the caller');
check($ghost->get('qst_not_a_real_field') === 'value',
	'The value is readable back, which is why the mistake looks like it worked');
$ghost->save();
$ghost_reloaded = new Question($ghost->key, TRUE);
check($ghost_reloaded->get('qst_not_a_real_field') === NULL,
	'but it was never persisted — the intent is dropped at save');

// Declared defaults are a database-side fact, not an object-side one.
$unsaved = new Question(NULL);
check($unsaved->get('qst_is_published') === NULL,
	'A declared default is absent on a fresh object');
$defaulted = sbl_question('HarnessTest defaults ' . $RUN);
check($defaulted->get('qst_is_published') === true,
	'and materialises only once the row has been saved and read back',
	var_export($defaulted->get('qst_is_published'), true));
check($defaulted->get('qst_create_time') !== NULL,
	'A now() default is filled in on insert');


section('Soft delete leaves the row behind');

$doomed = sbl_question('HarnessTest doomed ' . $RUN);
$doomed_key = $doomed->key;
$doomed->soft_delete();

$after = new Question($doomed_key, TRUE);
check($after->key !== NULL, 'A soft-deleted row is still loadable by id');
check($after->get('qst_delete_time') !== NULL, 'and carries a delete time');
check($after->get('qst_question') === 'HarnessTest doomed ' . $RUN,
	'with its data intact');

// The row is genuinely still in the table. Every query that wants live records
// has to say so — which is what the `deleted` option on a Multi class is for,
// and why a collection missing that filter quietly returns deleted rows.
$count = $db->prepare('SELECT count(*) FROM qst_questions WHERE qst_question_id = ?');
$count->execute(array($doomed_key));
check((int)$count->fetchColumn() === 1, 'The row is still physically present');


section('Uniqueness is enforced without calling prepare()');

// Schedule declares sch_subject_type unique_with sch_subject_id, which
// materialises both a database constraint and an application-level pre-check.
// The pre-check runs inside save() itself — prepare() is a separate entry point
// that most call sites never invoke, so anything that must happen on every
// write belongs in save(), not prepare().
$subject_id = 900000 + random_int(1000, 9999);

$s1 = new Schedule(NULL);
$s1->set('sch_subject_type', 'harnesstest_' . $RUN);
$s1->set('sch_subject_id', $subject_id);
$s1->set('sch_timezone', 'UTC');
$s1->save();
$s1->load();
harness_register_row('sch_schedules', 'sch_schedule_id', $s1->key);
check($s1->key !== NULL, 'The first row of a unique pair saves');

$s2 = new Schedule(NULL);
$s2->set('sch_subject_type', 'harnesstest_' . $RUN);
$s2->set('sch_subject_id', $subject_id);
$s2->set('sch_timezone', 'UTC');
$dup_error = null;
try {
	$s2->save();
} catch (Throwable $e) {
	$dup_error = $e;
}
check($dup_error !== null,
	'A duplicate pair is refused by save() alone, with no prepare() call');

// Which exception arrives says which layer caught it, and that distinction is
// the whole point: the in-PHP pre-check produces a DisplayableUserException a
// form can render, while the database constraint produces a DatabaseException
// that reaches the user as an error page. Asserting only "it was refused"
// would pass with the pre-check deleted, since the constraint refuses it too.
check($dup_error instanceof DisplayableUserException,
	'and refused in PHP, so the caller gets a message rather than a database error',
	$dup_error ? get_class($dup_error) : '');
check($s2->key === NULL, 'The refused row was not written');

// A different pair is fine — proving the refusal is about the constraint and
// not about the second write.
$s3 = new Schedule(NULL);
$s3->set('sch_subject_type', 'harnesstest_' . $RUN);
$s3->set('sch_subject_id', $subject_id + 1);
$s3->set('sch_timezone', 'UTC');
$s3->save();
$s3->load();
harness_register_row('sch_schedules', 'sch_schedule_id', $s3->key);
check($s3->key !== NULL, 'A different pair saves normally');

// Soft-deleting a row frees its unique values, and BOTH enforcement layers
// agree: the application pre-check excludes deleted rows, and on a
// soft-deletable table update_database materializes unique/unique_with as a
// PARTIAL unique index (unique among live rows) rather than a full
// constraint — same exclusion, one answer. Delete-then-recreate with the
// same values therefore succeeds.
$s1->soft_delete();

$probe = new Schedule(NULL);
$probe->set('sch_subject_type', 'harnesstest_' . $RUN);
$probe->set('sch_subject_id', $subject_id);
// Despite the name, this returns how many matching rows there are rather than
// a yes/no — so a caller writing `=== true` never fires and one writing
// `=== false` never fires either. Callers must test the count.
$dupes = $probe->check_for_duplicate(array('sch_subject_type', 'sch_subject_id'));
check(!is_bool($dupes), 'check_for_duplicate returns a count, not a boolean', var_export($dupes, true));
check((int)$dupes === 0,
	'The application duplicate check treats a soft-deleted row as gone', var_export($dupes, true));

// Asserted as a pair, because either half alone is satisfiable by a broken
// filter: a check that always returns 0 passes the line above, and one that
// never filters passes the line below. Only both together establish that the
// soft-delete exclusion is doing real work, and that the row it excludes is
// genuinely still there to be found.
$dupes_incl = $probe->check_for_duplicate(array('sch_subject_type', 'sch_subject_id'), true);
check((int)$dupes_incl === 1,
	'and finds it again when asked to search deleted rows', var_export($dupes_incl, true));

$s4 = new Schedule(NULL);
$s4->set('sch_subject_type', 'harnesstest_' . $RUN);
$s4->set('sch_subject_id', $subject_id);
$s4->set('sch_timezone', 'UTC');
$refusal = null;
try {
	$s4->save();
} catch (Throwable $e) {
	$refusal = $e;
}
check($refusal === null,
	'and the database agrees: re-creating a soft-deleted pair saves cleanly',
	$refusal ? get_class($refusal) . ': ' . substr($refusal->getMessage(), 0, 90) : '');
check($s4->key !== NULL, 'the replacement row was written');
if ($s4->key) {
	$s4->load();
	harness_register_row('sch_schedules', 'sch_schedule_id', $s4->key);
}

// The partial index still polices live rows: a duplicate of the LIVE
// replacement is refused at the database even when the pre-check is bypassed
// (raw insert), so scoping uniqueness to live rows weakened nothing.
$raw_refused = false;
try {
	$raw = DbConnector::get_instance()->get_db_link()->prepare(
		'INSERT INTO sch_schedules (sch_subject_type, sch_subject_id, sch_timezone) VALUES (?, ?, ?)');
	$raw->execute(array('harnesstest_' . $RUN, $subject_id, 'UTC'));
} catch (Throwable $e) {
	$raw_refused = true;
}
check($raw_refused, 'a raw duplicate of the live pair is still refused by the database');


section('Collections are iterated, never read as ->results');

// The documented foot-gun: SystemMultiBase keeps its rows in a private array
// and exposes them through IteratorAggregate. Reading ->results returns null,
// so a foreach over it runs zero times — no error, no warning, just a loop body
// that never executes. It has caused at least five dead-code bugs that survived
// two rounds of review.
$multi = new MultiQuestion(array('deleted' => false), array(), 3);
$multi->load();

$iterated = 0;
foreach ($multi as $row) { $iterated++; }
check($iterated > 0, 'Iterating the collection yields rows', $iterated . ' rows');
check(count($multi) === $iterated, 'count() agrees with what iteration produces');

check(property_exists($multi, 'results') === false, 'There is no public results property');

$phantom = 0;
foreach ((array)($multi->results ?? array()) as $row) { $phantom++; }
check($phantom === 0,
	'Reading ->results yields nothing, so a loop over it silently never runs');

check($multi->get(0) !== NULL, 'get(0) reaches the first row by position');

harness_finish();
