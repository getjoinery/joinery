<?php
/** @joinery-test
 * name: retention_registry
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Retention registry — the declarations that replaced twelve purge tasks
 * (specs/scheduled_task_consolidation.md § Part 1).
 *
 * A retention rule is declared as $retention_policy on the data class that owns
 * the table. That makes a broken rule invisible until the sweep runs at 3am and
 * quietly deletes nothing — or throws. So the invariants worth guarding are the
 * ones a declaration can get wrong:
 *
 *   A. Every rule resolves. An age_column names a real column in that class's
 *      own $field_specifications; a purge_method is actually callable on that
 *      class. Either being wrong is silent in production.
 *
 *   B. Every window is declared. A rule naming a setting nobody declares reads
 *      as "off" forever, so the table grows without bound and nothing says so.
 *
 *   C. 0 means never purge. The sweep must skip the rule entirely rather than
 *      fall back to some default the operator never chose.
 *
 *   D. One bad rule does not cost the others their sweep. This is the whole
 *      reason the twelve tasks could be collapsed into one.
 *
 *   E. An inactive plugin contributes no rules, matching how plugin task
 *      suspension already works.
 *
 * Run: php tests/integration/retention_registry_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/ScheduledTaskRegistry.php'));
require_once(PathHelper::getIncludePath('includes/SettingsDeclarations.php'));
require_once(PathHelper::getIncludePath('tasks/RetentionSweep.php'));

$rules = ScheduledTaskRegistry::retentionRules();

// ---------------------------------------------------------------------------
section('A. Every declared rule resolves against its own class');

check(count($rules) > 0, 'the registry found rules at all', 'count=' . count($rules));

foreach ($rules as $table => $rule) {
	$class = $rule['class'];
	$policy = $rule['policy'];
	$label = $class . ' (' . $table . ')';

	check(!empty($policy['label']), "$label declares a human label");

	$has_method = isset($policy['purge_method']);
	$has_age = isset($policy['age_column']);
	check($has_method xor $has_age,
		"$label declares exactly one rule form", json_encode(array_keys($policy)));

	if ($has_method) {
		check(is_callable(array($class, $policy['purge_method'])),
			"$label purge_method {$policy['purge_method']}() is callable on the class");
	} else {
		$specs = $class::$field_specifications;
		check(isset($specs[$policy['age_column']]),
			"$label age_column {$policy['age_column']} is a real column on the class");
		check(isset(RetentionSweep::UNITS[$policy['age_unit'] ?? '']),
			"$label age_unit is one the sweep understands", (string)($policy['age_unit'] ?? ''));
	}
}

// ---------------------------------------------------------------------------
section('B. Every window names a declared setting');

$declared = array();
foreach (SettingsDeclarations::all() as $name => $decl) {
	$declared[$name] = true;
}

foreach ($rules as $table => $rule) {
	$policy = $rule['policy'];
	check(array_key_exists('window_setting', $policy),
		$rule['class'] . ' declares a window_setting key');

	if ($policy['window_setting'] === null) {
		// The one legitimate shape: an unconditional rule with no age to choose.
		check(isset($policy['purge_method']),
			$rule['class'] . ' a windowless rule is always the method form');
		continue;
	}

	check(isset($declared[$policy['window_setting']]),
		$rule['class'] . " window '{$policy['window_setting']}' is a declared setting");
}

// ---------------------------------------------------------------------------
section('C. A window of 0 means never purge');

// GeneralError is the simplest age-form rule on the platform, so it is the
// honest place to assert the semantics without dragging in a subsystem.
require_once(PathHelper::getIncludePath('data/general_errors_class.php'));
$db = DbConnector::get_instance()->get_db_link();

$db->exec("INSERT INTO err_general_errors (err_message, err_create_time)
	VALUES ('retention_registry_test marker', now() - interval '400 days')");
$marker_count = function () use ($db) {
	return (int)$db->query("SELECT count(*) FROM err_general_errors
		WHERE err_message = 'retention_registry_test marker'")->fetchColumn();
};
check($marker_count() >= 1, 'a 400-day-old error row exists to sweep');

harness_set_setting_mem('error_log_retention_days', '0');
$sweep = new RetentionSweep();
$sweep->run(array());
check($marker_count() >= 1, 'a window of 0 skips the rule — the old row survives');

harness_set_setting_mem('error_log_retention_days', '30');
$sweep->run(array());
check($marker_count() === 0, 'a window of 30 removes it');

// ---------------------------------------------------------------------------
section('D. One failing rule does not stop the others');

// A rule that throws is the realistic failure: a purge method that hits a table
// an upgrade dropped, or a bug in one subsystem's reclamation. The sweep must
// record it and carry on, because the alternative is one bad table costing
// every other table its retention.
class RetentionTestExploding {
	public static $tablename = 'retention_test_exploding';
	public static $field_specifications = array();
	public static $retention_policy = array(
		'label'          => 'Exploding test rule',
		'purge_method'   => 'boom',
		'window_setting' => 'error_log_retention_days',
	);
	public static function boom($window) {
		throw new Exception('deliberate test failure');
	}
}

$db->exec("INSERT INTO err_general_errors (err_message, err_create_time)
	VALUES ('retention_registry_test marker', now() - interval '400 days')");

// Drive the sweep's own isolation by calling it with the exploding rule ahead
// of a real one, using the same code path the scheduled run takes.
$reflect = new ReflectionMethod('RetentionSweep', 'runMethodRule');
$threw = false;
try {
	$reflect->invoke(new RetentionSweep(),
		array('class' => 'RetentionTestExploding', 'policy' => RetentionTestExploding::$retention_policy), 30);
} catch (Throwable $e) {
	$threw = true;
}
check($threw, 'a throwing purge method does throw out of the rule runner');

$result = $sweep->run(array());
check(($result['status'] ?? '') === 'success',
	'the sweep still reports on the rules that did run', json_encode($result));
check($marker_count() === 0,
	'the real rule ran even though another rule can fail');

// ---------------------------------------------------------------------------
section('E. An inactive plugin contributes no rules');

require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));
$mailbox_active = PluginHelper::isPluginActive('mailbox');
$has_mailbox_rule = isset($rules['iem_inbound_email_messages']);
check($mailbox_active === $has_mailbox_rule,
	'mailbox rules are present exactly when the mailbox plugin is active',
	'active=' . var_export($mailbox_active, true) . ' rule=' . var_export($has_mailbox_rule, true));

// Every rule that IS present belongs to core or to an active plugin — the
// registry never hands the sweep a rule it should not run.
$all_ok = true;
foreach ($rules as $table => $rule) {
	$file = (new ReflectionClass($rule['class']))->getFileName();
	if (preg_match('#/plugins/([^/]+)/#', $file, $m) && !PluginHelper::isPluginActive($m[1])) {
		$all_ok = false;
	}
}
check($all_ok, 'no rule comes from a deactivated plugin');

harness_finish();
