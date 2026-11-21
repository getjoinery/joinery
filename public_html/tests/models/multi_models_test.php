<?php
/** @joinery-test
 * name: multi_models_crud
 * tier: test-db         # runs against the copied test database
 * env: dev-only
 * needs: [test-db]
 * timeout: 900
 */

/**
 * Multi-collection query suite, contract-emitting.
 *
 * The companion to `models_test.php`. That one drives ModelTester over every
 * single-row model; this one drives MultiModelTester over every collection
 * class, which is the half that has never run from the gate — `models_test.php`
 * hard-sets SINGLE_TESTS_ONLY, so until now nothing checked that a Multi class
 * returns what its filters claim to.
 *
 * What the engine actually asserts, per model: that a loaded collection matches
 * the equivalent direct SQL, that each discovered filter narrows the result set
 * the way the same WHERE clause does, that ordering and pagination agree with
 * ORDER BY / LIMIT / OFFSET, and that combinations of the three compose. It
 * builds its own records to query against and removes them afterwards.
 *
 * This surface is worth gating because its failure mode is silent. A Multi
 * class that mis-reads an option returns a *plausible* collection — too many
 * rows, or the wrong owner's rows — and every caller downstream treats it as
 * authoritative. Nothing throws.
 *
 * Run: php tests/models/multi_models_test.php   (or php tests/run.php test-db)
 */

require_once(__DIR__ . '/../lib/harness.php');

// Both constants are read at class-load time by the tester, so they have to be
// defined before MultiModelTester.php is required, not after.
if (!defined('TEST_MULTI')) define('TEST_MULTI', true);
if (!defined('MULTI_TESTS_ONLY')) define('MULTI_TESTS_ONLY', true);

require_once(PathHelper::getIncludePath('tests/models/MultiModelTester.php'));
harness_boot();

set_time_limit(900);

$classes = LibraryFunctions::discover_model_classes(['include_plugins' => true]);

// Load every model class first: a Multi class is declared in the same file as
// its model, so class_exists('MultiFoo') is only meaningful once Foo's file has
// been read. Without this pass, models later in the list would report "no Multi
// class" purely because nothing had required them yet.
foreach ($classes as $class) { class_exists($class); }

$with_multi = array();
foreach ($classes as $class) {
	if (class_exists('Multi' . $class)) { $with_multi[] = $class; }
}

section('Multi-collection queries (' . count($with_multi) . ' of ' . count($classes) . ' models have a Multi class, test database)');

foreach ($with_multi as $class) {
	$before = ModelTester::get_test_stats();
	ob_start();
	$status = 'fail';
	$err = '';
	try {
		$tester = new MultiModelTester($class);
		$res = $tester->test(null, false, false);
		$status = ($res === 'SKIPPED') ? 'skip' : ($res ? 'pass' : 'fail');
	} catch (Throwable $e) {
		$status = 'fail';
		$err = $e->getMessage();
	}
	$output = ob_get_clean();
	$after = ModelTester::get_test_stats();
	$delta_pass = $after['passed'] - $before['passed'];
	$delta_fail = $after['failed'] - $before['failed'];
	$detail = "$delta_pass sub-checks";
	if ($status === 'fail') {
		$detail = ($delta_fail ? "$delta_fail sub-checks failed. " : '')
			. ($err !== '' ? $err : trim(strip_tags(str_replace('<br>', ' ', substr($output, -400)))));
	}

	if ($status === 'skip') {
		harness_skip('Multi' . $class, 'configuration required');
	} else {
		check($status === 'pass', 'Multi' . $class, $detail);
	}
}

// A model with no Multi class is a fact about the estate, not a failure — but
// it is worth stating, because "the Multi suite is green" should not be read as
// "every collection is covered" when a third of the models have no collection
// class at all.
section('Coverage');
check(count($with_multi) > 0, 'The estate has Multi classes to test', count($with_multi) . ' of ' . count($classes));

// ---------------------------------------------------------------------------
// The per-class checks above prove a DECLARED option filters correctly. This
// section pins the other half of the contract: an option a collection never
// declared must refuse the query, not silently return unfiltered rows.
section('Unknown option keys are refused, not silently dropped');

$bogus = new MultiUser(array('no_such_option_key' => 1));
$threw = false;
try {
	$bogus->count_all();
} catch (UnknownMultiOptionException $e) {
	$threw = true;
	check(strpos($e->getMessage(), 'no_such_option_key') !== false,
		'the refusal names the unknown key', $e->getMessage());
}
check($threw, 'an undeclared option key throws UnknownMultiOptionException');

// A declared key still works — enforcement must not flag the legitimate set.
$fine = new MultiUser(array('deleted' => false));
$threw = false;
try {
	$fine->count_all();
} catch (UnknownMultiOptionException $e) {
	$threw = true;
}
check(!$threw, 'a declared option key passes enforcement');

// A pass-through collection (iterates its options generically, every key maps
// to a column) is exempt — it has no fixed vocabulary and a bogus key already
// fails loudly in SQL. No collection in the estate is one, so the test brings
// its own.
class MultiPassThroughPlugin extends SystemMultiBase {
	protected static $model_class = 'Plugin';
	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		foreach ($this->options as $field => $value) {
			$filters[$field] = array($value, PDO::PARAM_STR);
		}
		return $this->_get_resultsv2('plg_plugins', $filters, $this->order_by, $only_count, $debug);
	}
}
$passthrough = new MultiPassThroughPlugin(array('plg_name' => 'no_such_plugin_zz'));
$threw = false;
try {
	$passthrough->count_all();
} catch (UnknownMultiOptionException $e) {
	$threw = true;
}
check(!$threw, 'a pass-through collection is exempt from enforcement');

// A filter on a declared column binds with the column's type. A bool false
// bound as text is '' and Postgres refuses it as a boolean (the Updates page
// asks MultiPlugin for plg_receives_upgrades = false).
section('A column filter binds with the column\'s declared type');
$expected = (int)DbConnector::get_instance()->get_db_link()
	->query('SELECT count(*) FROM plg_plugins WHERE plg_receives_upgrades = false')->fetchColumn();
try {
	$got = count(new MultiPlugin(array('plg_receives_upgrades' => false)));
	check($got === $expected, 'MultiPlugin filters a bool column on false', "$got rows, SQL says $expected");
} catch (Throwable $e) {
	check(false, 'MultiPlugin filters a bool column on false', $e->getMessage());
}

// A column name and a string condition go into the query as they are, so the
// query refuses any that is not a quoted string in the collection's own
// source. These collections do what a careless filter would.
section('A condition or column put together while running is refused');
class MultiJoinedConditionPlugin extends SystemMultiBase {
	protected static $model_class = 'Plugin';
	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['joined'])) {
			$joined = "= '" . $this->options['joined'] . "'";
			$filters['plg_name'] = $joined;
		}
		if (isset($this->options['bound_text'])) {
			$filters['plg_name'] = SqlCondition::bind('= ' . $this->options['bound_text'], 1);
		}
		if (isset($this->options['column'])) {
			$filters[$this->options['column']] = array('x', PDO::PARAM_STR);
		}
		if (isset($this->options['written'])) {
			$filters['plg_name'] = 'IS NULL';
		}
		if (isset($this->options['like'])) {
			$filters['plg_name'] = SqlCondition::bind('ILIKE ?', $this->options['like']);
		}
		if (isset($this->options['names'])) {
			$filters['(plg_name'] = SqlCondition::bind('IN (?) OR plg_name = ?)', $this->options['names'], 'no_such_plugin_zz');
		}
		return $this->_get_resultsv2('plg_plugins', $filters, $this->order_by, $only_count, $debug);
	}
}
$refused = function (array $options) {
	try {
		(new MultiJoinedConditionPlugin($options))->count_all();
	} catch (UnwrittenConditionException $e) {
		return true;
	} catch (Throwable $e) {
		return get_class($e) . ': ' . $e->getMessage();
	}
	return false;
};
$injection = "x' OR (SELECT count(*) FROM usr_users) > 0 OR plg_name = 'x";
$total = (int)DbConnector::get_instance()->get_db_link()->query('SELECT count(*) FROM plg_plugins')->fetchColumn();
check($total > 0, 'the plugin table has rows to leak', $total . ' rows');
check($refused(array('joined' => $injection)) === true, 'a value joined into a condition never reaches the database');
check($refused(array('joined' => 'harmless')) === true, 'and is refused whatever the value: the form is the fault');
check($refused(array('bound_text' => '? OR 1 = 1')) === true, 'SqlCondition text put together while running is refused');
// Joined here, not written whole: a string this file writes whole is, by the
// rule under test, one the collection in this file may use.
check($refused(array('column' => 'plg_name = ' . "'x'" . ' OR 1 = 1 OR ' . 'plg_name')) === true, 'a column that is neither written nor declared is refused');
check($refused(array('column' => 'plg_name')) === false, 'a declared column taken from a variable passes');
check($refused(array('written' => true)) === false, 'a written condition passes');
try {
	$got = (new MultiJoinedConditionPlugin(array('like' => $injection)))->count_all();
	check((int)$got === 0, 'a bound value is matched as text, not run', $got . ' rows');
	$some = DbConnector::get_instance()->get_db_link()->query('SELECT plg_name FROM plg_plugins ORDER BY plg_name LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
	$got = (new MultiJoinedConditionPlugin(array('names' => $some)))->count_all();
	check((int)$got === count($some), 'a bound list finds exactly its members', $got . ' of ' . count($some));
	$got = (new MultiJoinedConditionPlugin(array('names' => array())))->count_all();
	check((int)$got === 0, 'an empty bound list finds nothing');
} catch (Throwable $e) {
	check(false, 'bound conditions run', get_class($e) . ': ' . $e->getMessage());
}

// A query string carries text, and the text "false" is true to PHP. An option
// the collection reads as yes or no takes the words; an option that carries a
// value is never touched.
section('The words true and false are read as yes and no');
class MultiYesNoPlugin extends SystemMultiBase {
	protected static $model_class = 'Plugin';
	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['named'])) {
			$filters['plg_name'] = $this->options['named'] ? 'IS NOT NULL' : 'IS NULL';
		}
		if (isset($this->options['only_unnamed'])) {
			$filters['plg_name'] = 'IS NULL';
		}
		if (!empty($this->options['unnamed'])) {
			$filters['plg_name'] = 'IS NULL';
		}
		if (isset($this->options['called'])) {
			$filters['plg_name'] = array($this->options['called'], PDO::PARAM_STR);
		}
		return $this->_get_resultsv2('plg_plugins', $filters, $this->order_by, $only_count, $debug);
	}
}
$count = function (array $options) {
	return (int)(new MultiYesNoPlugin($options))->count_all();
};
check($count(array('named' => 'false')) === 0 && $count(array('named' => false)) === 0, 'the word false on a yes/no option means no');
check($count(array('named' => 'FALSE')) === 0 && $count(array('named' => '0')) === 0, 'so do FALSE and 0');
check($count(array('named' => 'true')) === $total && $count(array('named' => '1')) === $total, 'true and 1 mean yes');
check($count(array('unnamed' => 'false')) === $total, 'a tested option given false is not applied');
check($count(array('unnamed' => 'true')) === 0, 'and given true it is');
check($count(array('only_unnamed' => 'false')) === $total, 'an option that only has to be passed is not passed by saying no');
check($count(array('only_unnamed' => 'true')) === 0 && $count(array('only_unnamed' => 1)) === 0, 'and is by saying yes');
check($count(array('called' => 'false')) === 0 && $count(array('called' => 'true')) === 0, 'an option that carries a value keeps the word as text');
$keys = SystemMultiBase::yes_no_option_keys_in('<?php
	if (isset($this->options["a"])) { $f["x"] = $this->options["a"] ? "1" : "2"; }
	if (!empty($this->options["b"])) {}
	if (isset($this->options["c"])) { $f["x"] = array($this->options["c"], 1); }
	if (isset($this->options["d"])) {}
	if (isset($this->options["e"]) && $this->options["e"]) { $f["x"] = "%" . $this->options["e"]; }
	$g = $this->options["g"] ?? null;
	if ($this->options["h"] === true) {}
	if (array_key_exists("i", $this->options)) { $f["x"] = $this->options["i"] === null ? "IS NULL" : array($this->options["i"], 1); }');
check(array_keys($keys['flag']) === array('b', 'h', 'a'), 'tested options are yes/no', json_encode(array_keys($keys['flag'])));
check(array_keys($keys['presence']) === array('d'), 'an option only checked for being passed is a presence option', json_encode(array_keys($keys['presence'])));

harness_finish();
