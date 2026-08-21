<?php
/** @joinery-test
 * name: shipped_recipes
 * tier: db
 * env: any
 * needs: []
 */
/**
 * Recipes that ship with new installs
 * (specs/implemented/joinery_ai_shipped_recipes.md).
 *
 * A declaration in plugins/joinery_ai/recipes.json becomes a recipe on every
 * install, once. Nearly everything here is about the boundaries of that "once":
 *
 *  - a declaration seeds one recipe, inert — disabled, unbound, no model, no
 *    tainted-writes acknowledgment;
 *  - a second sync creates nothing and overwrites nothing, including a prompt
 *    the operator edited;
 *  - a template the operator DELETED is never resurrected;
 *  - withdrawing a declaration leaves the existing recipe alone;
 *  - the system account is never a recipe owner, and an install with no
 *    administrator yet seeds nothing rather than creating an ownerless recipe;
 *  - a seeded recipe is invisible to the dispatcher while disabled, and cannot
 *    be enabled without accepting tainted writes.
 *
 * The manifest is hand-edited; the well-formedness section is what catches a
 * bad edit — an unknown field, a duplicate key, a non-travelling field.
 *
 * Run: php tests/run.php db --filter=shipped_recipes
 *
 * @version 1.1
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../../../tests/lib/logic.php');
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/PluginManager.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipes_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeSeeder.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/TaintGate.php'));

$db = DbConnector::get_instance()->get_db_link();

// Every recipe this test creates carries a key with this prefix, and the whole
// family is swept at teardown — including rows a failing assertion left behind.
$tag = substr(md5(uniqid('', true)), 0, 8);
$key_prefix = 'shiptest_' . $tag . '_';
harness_defer(function () use ($db, $key_prefix) {
	$db->prepare("DELETE FROM rcp_recipes WHERE rcp_declared_key LIKE ?")
	   ->execute(array($key_prefix . '%'));
});

/** One declaration, keyed into this run's namespace. */
$declare = function (string $suffix, array $overrides = array()) use ($key_prefix) {
	return array_merge(array(
		'key'                => $key_prefix . $suffix,
		'name'               => 'Shipped Test ' . $suffix,
		'pipeline_job'       => 'email_triage',
		'prompt'             => '',
		'schedule_frequency' => 'hourly',
		'max_iterations'     => 25,
		'max_tokens'         => 5000,
		'monthly_token_cap'  => 200000,
		'thinking_level'     => 'off',
	), $overrides);
};

/** The single live-or-deleted recipe for a declared key, or null. */
$fetch = function (string $key) use ($db) {
	$q = $db->prepare("SELECT * FROM rcp_recipes WHERE rcp_declared_key = ?");
	$q->execute(array($key));
	$row = $q->fetch(PDO::FETCH_ASSOC);
	return $row === false ? null : $row;
};

// -----------------------------------------------------------------------------
section('The shipped manifest is well-formed');

try {
	$shipped = RecipeSeeder::declarations();
	check(true, 'recipes.json parses and every entry validates');
} catch (Throwable $e) {
	$shipped = array();
	check(false, 'recipes.json parses and every entry validates', $e->getMessage());
}

check(count($shipped) > 0, 'the manifest declares at least one recipe',
	'declared: ' . count($shipped));

$keys = array_map(function ($d) { return $d['key']; }, $shipped);
check(count($keys) === count(array_unique($keys)), 'declared keys are unique');

$non_travelling = array_keys(RecipeSeeder::NON_TRAVELLING_FIELDS);
$leaked = array();
$pipeline_floors = array();
$with_prompt = array();
foreach ($shipped as $d) {
	foreach ($non_travelling as $field) {
		// Declarations use unprefixed names, so check both spellings — a
		// hand-edit is as likely to write "enabled" as "rcp_enabled".
		$bare = preg_replace('/^rcp_/', '', $field);
		if (array_key_exists($field, $d) || array_key_exists($bare, $d)) $leaked[] = $d['key'];
	}
	// Requirement keys are legal only on an AGENT-mode declaration (no job to
	// declare a floor). On a pipeline declaration they would be a second
	// answer beside the job's own, kept in step by hope.
	if (!empty($d['pipeline_job'])) {
		foreach (RecipeSeeder::REQUIREMENT_DECLARATION_KEYS as $rk) {
			if (array_key_exists($rk, $d)) $pipeline_floors[] = $d['key'] . '.' . $rk;
		}
	}
	if (trim((string)($d['prompt'] ?? '')) !== '') $with_prompt[] = $d['key'];
}
check(empty($leaked), 'no declaration carries a field that cannot travel',
	'offending: ' . implode(', ', $leaked));
check(empty($pipeline_floors),
	'no pipeline declaration carries a requirement floor — the job is the single source',
	'offending: ' . implode(', ', $pipeline_floors));
check(empty($with_prompt),
	'no declaration pastes prompt text — the job class owns the wording so upgrades can improve it',
	'offending: ' . implode(', ', $with_prompt));

// -----------------------------------------------------------------------------
section('A declaration seeds one inert recipe');

$key_a = $key_prefix . 'a';
$messages = RecipeSeeder::seedDeclared(array($declare('a')));
check(count($messages) === 1 && strpos($messages[0], 'seeded recipe') === 0,
	'seeding reports what it created', implode(' | ', $messages));

$row = $fetch($key_a);
check($row !== null, 'the recipe exists');
check($row && $row['rcp_enabled'] !== 't', 'it arrives DISABLED');
check($row && $row['rcp_allow_tainted_writes'] !== 't', 'it arrives with tainted writes OFF');
check($row && ($row['rcp_model'] === null || $row['rcp_model'] === ''),
	'it carries no model — the destination resolves its own', var_export($row['rcp_model'] ?? null, true));
check($row && ($row['rcp_temperature'] === null && $row['rcp_top_p'] === null),
	'temperature and top_p are unset, so the site default applies');
check($row && $row['rcp_min_tier'] === null && $row['rcp_trust_floor'] === null
	&& $row['rcp_thinking_required'] === null && $row['rcp_min_context'] === null,
	'every requirement column is NULL — a seeded floor would freeze at install '
	. 'and never see a raise a later release ships',
	$row ? json_encode(array_intersect_key($row, array_flip(
		['rcp_min_tier', 'rcp_trust_floor', 'rcp_thinking_required', 'rcp_min_context']))) : 'no row');
$config = $row ? json_decode((string)$row['rcp_source_config'], true) : null;
check(empty($config), 'no mailbox is bound', var_export($row['rcp_source_config'] ?? null, true));
check($row && (string)$row['rcp_prompt'] === '',
	'the prompt is empty, so the job class default applies');
check($row && $row['rcp_mode'] === Recipe::MODE_PIPELINE && $row['rcp_pipeline_job'] === 'email_triage',
	'the job binding travels');
check($row && (int)$row['rcp_max_iterations'] === 25 && (int)$row['rcp_max_tokens'] === 5000
	&& (int)$row['rcp_monthly_token_cap'] === 200000 && $row['rcp_schedule_frequency'] === 'hourly',
	'the declared caps and schedule travel');
check($row && (int)$row['rcp_owner_user_id'] === (int)RecipeSeeder::resolveOwnerUserId(),
	'the owner is the resolved administrator');

// -----------------------------------------------------------------------------
section('Seeding is create-only');

$before = $row ? (int)$row['rcp_recipe_id'] : 0;
$db->prepare("UPDATE rcp_recipes SET rcp_prompt = ?, rcp_max_tokens = 999 WHERE rcp_declared_key = ?")
   ->execute(array('operator tuned this', $key_a));

$messages = RecipeSeeder::seedDeclared(array($declare('a')));
check(empty($messages), 'a second sync reports nothing', implode(' | ', $messages));

$q = $db->prepare("SELECT COUNT(*) FROM rcp_recipes WHERE rcp_declared_key = ?");
$q->execute(array($key_a));
check((int)$q->fetchColumn() === 1, 'and creates no duplicate');

$row = $fetch($key_a);
check($row && (int)$row['rcp_recipe_id'] === $before
	&& $row['rcp_prompt'] === 'operator tuned this' && (int)$row['rcp_max_tokens'] === 999,
	'the operator edits survive — an upgrade never overwrites a tuned recipe');

// Withdrawing the declaration leaves the recipe alone.
$messages = RecipeSeeder::seedDeclared(array($declare('b')));
$row = $fetch($key_a);
check($row !== null && $row['rcp_prompt'] === 'operator tuned this',
	'dropping a declaration does not delete or alter the recipe it created');
check($fetch($key_prefix . 'b') !== null, 'and a newly added declaration still seeds');

// -----------------------------------------------------------------------------
section('An omitted limit inherits the field-spec default');

$key_g = $key_prefix . 'g';
$decl_g = $declare('g');
unset($decl_g['max_iterations'], $decl_g['max_tokens'], $decl_g['monthly_token_cap']);
RecipeSeeder::seedDeclared(array($decl_g));
$row = $fetch($key_g);
$spec = Recipe::$field_specifications;
check($row
	&& (int)$row['rcp_max_iterations'] === (int)$spec['rcp_max_iterations']['default']
	&& (int)$row['rcp_max_tokens'] === (int)$spec['rcp_max_tokens']['default']
	&& (int)$row['rcp_monthly_token_cap'] === (int)$spec['rcp_monthly_token_cap']['default'],
	'limits left out of a declaration seed as the spec defaults — the single source',
	$row ? json_encode(array_intersect_key($row, array_flip(
		['rcp_max_iterations', 'rcp_max_tokens', 'rcp_monthly_token_cap']))) : 'no row');

// -----------------------------------------------------------------------------
section('A deleted template stays deleted');

$key_c = $key_prefix . 'c';
RecipeSeeder::seedDeclared(array($declare('c')));
$seeded = $fetch($key_c);
check($seeded !== null, 'template seeded');

$recipe = new Recipe((int)$seeded['rcp_recipe_id'], true);
$recipe->soft_delete();
$row = $fetch($key_c);
check($row && $row['rcp_delete_time'] !== null, 'the operator deletes it');

check(RecipeSeeder::exists($key_c), 'exists() counts the soft-deleted row');
$messages = RecipeSeeder::seedDeclared(array($declare('c')));
check(empty($messages), 'the next sync says nothing about it', implode(' | ', $messages));

$q = $db->prepare("SELECT COUNT(*) FROM rcp_recipes WHERE rcp_declared_key = ?");
$q->execute(array($key_c));
check((int)$q->fetchColumn() === 1,
	'and does NOT resurrect it — deletion means what it says');

// -----------------------------------------------------------------------------
section('Ownership');

$owner_id = RecipeSeeder::resolveOwnerUserId();
check($owner_id !== null, 'this install resolves an owner');
check($owner_id !== User::USER_SYSTEM,
	'the owner is never the system account', 'resolved: ' . var_export($owner_id, true));

// The exclusion is load-bearing only because the system row would otherwise
// win: confirm it really is an active permission-10 row with a low id.
$q = $db->prepare("SELECT usr_permission, usr_delete_time FROM usr_users WHERE usr_user_id = ?");
$q->execute(array(User::USER_SYSTEM));
$system_row = $q->fetch(PDO::FETCH_ASSOC);
$system_qualifies = $system_row && (int)$system_row['usr_permission'] >= 10
	&& $system_row['usr_delete_time'] === null;
if ($system_qualifies) {
	check(true, 'the system account WOULD qualify without the exclusion (so the exclusion is doing work)');
} else {
	// An install whose system row is absent, demoted or deleted is a different
	// shape, not a broken one — the exclusion simply has nothing to exclude.
	harness_skip('the system account WOULD qualify without the exclusion',
		'this install has no active permission-10 system row');
}

$q = $db->prepare(
	"SELECT MIN(usr_user_id) FROM usr_users
	 WHERE usr_permission >= 10 AND usr_delete_time IS NULL AND usr_user_id NOT IN (?, ?)"
);
$q->execute(array(User::USER_SYSTEM, User::USER_DELETED));
check((int)$q->fetchColumn() === (int)$owner_id,
	'the owner is the lowest-numbered eligible administrator');

// An install still mid-setup: no human admin yet.
$key_d = $key_prefix . 'd';
$messages = RecipeSeeder::seedDeclared(array($declare('d')), function () { return null; });
check(count($messages) === 1 && stripos($messages[0], 'no administrator') !== false,
	'with no administrator, seeding says so', implode(' | ', $messages));
check($fetch($key_d) === null,
	'and creates nothing — no ownerless recipe, no throw, retried at the next sync');

// -----------------------------------------------------------------------------
section('requires_plugin holds a declaration back');

$key_e = $key_prefix . 'e';
$messages = RecipeSeeder::seedDeclared(array($declare('e', array(
	'requires_plugin' => 'no_such_plugin_' . $tag,
))));
check(empty($messages) && $fetch($key_e) === null,
	'a template for an inactive plugin does not arrive', implode(' | ', $messages));

$key_f = $key_prefix . 'f';
RecipeSeeder::seedDeclared(array($declare('f', array('requires_plugin' => 'joinery_ai'))));
check($fetch($key_f) !== null, 'one for an active plugin does');

// -----------------------------------------------------------------------------
section('A seeded recipe is inert until set up');

$disabled = new MultiRecipe(array('enabled' => true, 'deleted' => false));
$disabled->load();
$found_enabled = false;
foreach ($disabled as $r) {
	if ((string)$r->get('rcp_declared_key') === $key_prefix . 'b') $found_enabled = true;
}
check(!$found_enabled,
	'the dispatcher selection (enabled recipes) does not include it while disabled');

// Enabling it without acknowledging tainted writes is refused. All three email
// jobs declare untrustedDigest(), so pipeline mode always trips the gate.
$admin = make_user('ShipAdmin' . $tag, 10);
$_SESSION['loggedin'] = 1;
$_SESSION['usr_user_id'] = (int)$admin->key;
$_SESSION['permission'] = 10;

$seeded_b = $fetch($key_prefix . 'b');
$enable_input = array(
	'rcp_recipe_id'            => (int)$seeded_b['rcp_recipe_id'],
	'rcp_name'                 => $seeded_b['rcp_name'],
	'rcp_mode'                 => Recipe::MODE_PIPELINE,
	'rcp_pipeline_job'         => 'email_triage',
	'rcp_enabled'              => '1',
	'rcp_allow_tainted_writes' => '',
);
$r = harness_call_logic('plugins/joinery_ai/logic/admin_edit_logic.php',
	'admin_joinery_ai_edit_logic', $enable_input);
check($r->error && strpos((string)$r->error, 'Standing approval required') === 0,
	'enabling without the acknowledgment is refused', var_export($r->error, true));
check($r->error && stripos((string)$r->error, 'one verdict for one item') !== false
	&& stripos((string)$r->error, 'fixed field') !== false,
	'and the refusal explains the narrow thing actually being accepted',
	var_export($r->error, true));

// -----------------------------------------------------------------------------
section('A saved recipe cannot change its shape');

// The editor renders mode and job as static text once a recipe is saved, but
// that is presentation. Editing the hidden input must be refused server-side,
// because flipping the job is not cosmetic: the mail jobs take the same
// mailbox_aliases config so validation passes, and aip_recipe_item_log is keyed
// per job — repoint triage at the security scan and every already-triaged
// message reads as already scanned, so the scan silently does nothing.
$shape_input = array(
	'rcp_recipe_id'            => (int)$seeded_b['rcp_recipe_id'],
	'rcp_name'                 => $seeded_b['rcp_name'],
	'rcp_mode'                 => Recipe::MODE_PIPELINE,
	'rcp_pipeline_job'         => 'email_security_scan',   // was email_triage
	'rcp_allow_tainted_writes' => '1',
);
$r = harness_call_logic('plugins/joinery_ai/logic/admin_edit_logic.php',
	'admin_joinery_ai_edit_logic', $shape_input);
check($r->error && stripos((string)$r->error, 'cannot change its job') !== false,
	'posting a different pipeline job is refused', var_export($r->error, true));

$after_job = $fetch($key_prefix . 'b');
check((string)$after_job['rcp_pipeline_job'] === 'email_triage',
	'and the stored job is untouched', (string)$after_job['rcp_pipeline_job']);

$mode_input = $shape_input;
$mode_input['rcp_pipeline_job'] = 'email_triage';
$mode_input['rcp_mode'] = Recipe::MODE_AGENT;
$r = harness_call_logic('plugins/joinery_ai/logic/admin_edit_logic.php',
	'admin_joinery_ai_edit_logic', $mode_input);
check($r->error && stripos((string)$r->error, 'cannot change its mode') !== false,
	'and so is flipping pipeline to agent, which would also exit the sealed-source cloud gate',
	var_export($r->error, true));

// Re-posting the SAME shape is not a change, so the lock must not fire — the
// editor posts mode and job on every ordinary save. An unbound template is a
// legal state (an empty mailbox list covers nothing), so the save completes.
$same_input = $shape_input;
$same_input['rcp_pipeline_job'] = 'email_triage';
$r = harness_call_logic('plugins/joinery_ai/logic/admin_edit_logic.php',
	'admin_joinery_ai_edit_logic', $same_input);
check($r->error === null || stripos((string)$r->error, 'cannot change its') === false,
	're-posting the unchanged shape is not treated as a change',
	var_export($r->error, true));
check($r->error === null,
	'and the unbound save completes — an empty mailbox list is legal',
	var_export($r->error, true));

// -----------------------------------------------------------------------------
section('The sync hook is wired up');

$hook_file = PathHelper::getIncludePath('plugins/joinery_ai/sync.php');
check(file_exists($hook_file), 'plugins/joinery_ai/sync.php exists');
require_once($hook_file);
check(function_exists('joinery_ai_sync'), 'it defines joinery_ai_sync()');

// Run it for real, then clean up only what it created — the shipped
// declarations land on this install at the next genuine sync, not here.
$pre_existing = array();
foreach ($shipped as $d) {
	if (RecipeSeeder::exists($d['key'])) $pre_existing[$d['key']] = true;
}
$manager = new PluginManager();
$hook_messages = $manager->runSyncHook('joinery_ai');
foreach ($shipped as $d) {
	if (isset($pre_existing[$d['key']])) continue;
	$db->prepare("DELETE FROM rcp_recipes WHERE rcp_declared_key = ?")->execute(array($d['key']));
}
check(is_array($hook_messages),
	'PluginManager::runSyncHook() runs it and returns its messages',
	implode(' | ', (array)$hook_messages));

check($manager->runSyncHook('no_such_plugin_' . $tag) === array(),
	'a plugin with no sync.php is a no-op');

harness_finish();
