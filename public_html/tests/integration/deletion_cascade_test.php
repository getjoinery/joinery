<?php
/** @joinery-test
 * name: deletion_cascade
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Deletion-cascade EXECUTION — proves permanent_delete() actually consults
 * del_deletion_rules and applies each action, and that a registered but
 * unconsulted rule table would fail this test.
 *
 * The companion deletion_rule_registration_test.php proves rules are WRITTEN
 * correctly (autodetect, override, prune). Nothing exercised them at delete
 * time — a rule table that registers perfectly and is never read would pass
 * registration and silently drop cascades, orphaning or leaking rows. This
 * suite closes that gap.
 *
 *   Section A — each action, against self-contained scratch tables (zzdel_*):
 *     cascade deletes children; null nulls the FK; set_value writes the
 *     sentinel; prevent throws and rolls the WHOLE delete back (atomic), and
 *     removing the blocker lets it through.
 *   Section B — permanent_delete_dry_run() previews the same rules: lists every
 *     dependency, reports can_delete=false with a blocking reason while a
 *     prevent child exists, true once it is gone.
 *   Section C — the permanent_delete ACTION recurses multi-level through real,
 *     registered, discoverable models: usr_users → aic_conversations →
 *     aim_conversation_messages. Deleting a user removes its conversations AND
 *     their messages (a level the flat-cascade action would orphan), while a
 *     bystander's conversation and message survive. Skipped if that chain is
 *     not registered (joinery_ai inactive).
 *
 * Scratch tables and rule rows are created and dropped by the test. Run:
 *   php tests/integration/deletion_cascade_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/deletion_rule_class.php'));

$_SERVER['REQUEST_METHOD'] = 'POST'; // mutation context — permanent_delete refuses on GET

/** Minimal parent model: permanent_delete() only needs $tablename/$pkey/$this->key. */
class ZZDelParentModel extends SystemBase {
	public static $tablename = 'zzdel_parent';
	public static $prefix = 'zzdp';
	public static $pkey_column = 'zzdp_id';
	public static $field_specifications = array(
		'zzdp_id' => array('type' => 'int8', 'serial' => true),
	);
}

/**
 * Declares an action name the engine does not implement. Registration must
 * refuse it rather than writing a rule that would later no-op. 'source_table'
 * is explicit because the scratch tables don't follow the prefix convention.
 */
class ZZDelBogusActionModel extends SystemBase {
	public static $tablename = 'zzdel_bogus_child';
	public static $prefix = 'zzdb';
	public static $pkey_column = 'id';
	protected static $foreign_key_actions = array(
		'zzdb_parent_id' => array('action' => 'restrict', 'source_table' => 'zzdel_parent'),
	);
	public static $field_specifications = array(
		'id'             => array('type' => 'int8', 'serial' => true),
		'zzdb_parent_id' => array('type' => 'int8'),
	);
}

$db = DbConnector::get_instance()->get_db_link();

$SCRATCH = array('zzdel_cascade_child', 'zzdel_null_child', 'zzdel_setval_child', 'zzdel_prevent_child', 'zzdel_bogus_child', 'zzdel_parent');
$drop_all = function () use ($db, $SCRATCH) {
	foreach ($SCRATCH as $t) { $db->exec("DROP TABLE IF EXISTS {$t} CASCADE"); }
	$db->prepare("DELETE FROM del_deletion_rules WHERE del_source_table = 'zzdel_parent'")->execute();
};

// Clean slate in case a prior interrupted run left anything behind, and register
// teardown up front so a mid-test failure still drops the scratch schema.
$drop_all();
harness_defer($drop_all);

function rule_row($db, $target_table, $target_column, $action, $value = null, $message = null) {
	$db->prepare(
		"INSERT INTO del_deletion_rules (del_source_table, del_target_table, del_target_column, del_action, del_action_value, del_message)
		 VALUES ('zzdel_parent', ?, ?, ?, ?, ?)"
	)->execute(array($target_table, $target_column, $action, $value, $message));
}
function count_where($db, $table, $column, $value) {
	$q = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = ?");
	$q->execute(array($value));
	return (int)$q->fetchColumn();
}

const SENTINEL = 424242;

try {
	// --- Scratch schema: a parent and one child table per SQL-direct action ---
	$db->exec("CREATE TABLE zzdel_parent (zzdp_id bigserial PRIMARY KEY)");
	$db->exec("CREATE TABLE zzdel_cascade_child (id bigserial PRIMARY KEY, zzdc_parent_id bigint)");
	$db->exec("CREATE TABLE zzdel_null_child   (id bigserial PRIMARY KEY, zzdn_parent_id bigint)");
	$db->exec("CREATE TABLE zzdel_setval_child (id bigserial PRIMARY KEY, zzdv_parent_id bigint)");
	$db->exec("CREATE TABLE zzdel_prevent_child(id bigserial PRIMARY KEY, zzdr_parent_id bigint)");
	$db->exec("CREATE TABLE zzdel_bogus_child  (id bigserial PRIMARY KEY, zzdb_parent_id bigint)");

	rule_row($db, 'zzdel_cascade_child', 'zzdc_parent_id', 'cascade');
	rule_row($db, 'zzdel_null_child',    'zzdn_parent_id', 'null');
	rule_row($db, 'zzdel_setval_child',  'zzdv_parent_id', 'set_value', (string)SENTINEL);
	rule_row($db, 'zzdel_prevent_child', 'zzdr_parent_id', 'prevent', null, 'referenced by a prevent child');

	$db->exec("INSERT INTO zzdel_parent DEFAULT VALUES");
	$parent_id = (int)$db->lastInsertId('zzdel_parent_zzdp_id_seq');

	$seed_children = function () use ($db, $parent_id) {
		foreach (array(
			array('zzdel_cascade_child', 'zzdc_parent_id'),
			array('zzdel_null_child',    'zzdn_parent_id'),
			array('zzdel_setval_child',  'zzdv_parent_id'),
		) as $c) {
			$db->prepare("INSERT INTO {$c[0]} ({$c[1]}) VALUES (?), (?)")->execute(array($parent_id, $parent_id));
		}
	};
	$seed_children();
	$db->prepare("INSERT INTO zzdel_prevent_child (zzdr_parent_id) VALUES (?)")->execute(array($parent_id));

	$parent = new ZZDelParentModel(NULL);
	$parent->key = $parent_id;

	// -------------------------------------------------------------------------
	section('Dry run previews the registered rules');

	$dry = $parent->permanent_delete_dry_run();
	$dep_tables = array();
	foreach ($dry['dependencies'] as $d) { $dep_tables[$d['table']] = $d['action']; }
	check(count($dry['dependencies']) === 4, 'dry run lists all four dependencies', 'got ' . count($dry['dependencies']));
	check(($dep_tables['zzdel_cascade_child'] ?? '') === 'cascade'
		&& ($dep_tables['zzdel_null_child'] ?? '') === 'null'
		&& ($dep_tables['zzdel_setval_child'] ?? '') === 'set_value'
		&& ($dep_tables['zzdel_prevent_child'] ?? '') === 'prevent',
		'dry run reports the correct action per dependency');
	check($dry['can_delete'] === false && !empty($dry['blocking_reasons']),
		'dry run blocks while a prevent child exists', json_encode($dry['blocking_reasons']));

	// -------------------------------------------------------------------------
	section('prevent blocks the whole delete atomically');

	$threw = false;
	try {
		$parent->permanent_delete();
	} catch (\Throwable $e) {
		$threw = true;
	}
	check($threw, 'permanent_delete throws while a prevent child exists');
	check(count_where($db, 'zzdel_parent', 'zzdp_id', $parent_id) === 1, 'parent row survives the blocked delete');
	check(count_where($db, 'zzdel_cascade_child', 'zzdc_parent_id', $parent_id) === 2,
		'cascade children untouched by the rolled-back delete (atomic)');
	check(count_where($db, 'zzdel_setval_child', 'zzdv_parent_id', $parent_id) === 2,
		'set_value children untouched by the rolled-back delete (atomic)');
	// $parent->key is cleared only on a successful delete; restore for the retry.
	$parent->key = $parent_id;

	// -------------------------------------------------------------------------
	section('cascade / null / set_value applied once the blocker is gone');

	$db->prepare("DELETE FROM zzdel_prevent_child WHERE zzdr_parent_id = ?")->execute(array($parent_id));

	$dry2 = $parent->permanent_delete_dry_run();
	check($dry2['can_delete'] === true, 'dry run clears once the prevent child is gone');

	$parent->permanent_delete();

	check(count_where($db, 'zzdel_parent', 'zzdp_id', $parent_id) === 0, 'parent row deleted');
	check(count_where($db, 'zzdel_cascade_child', 'zzdc_parent_id', $parent_id) === 0, 'cascade children deleted');
	check(count_where($db, 'zzdel_setval_child', 'zzdv_parent_id', $parent_id) === 0,
		'set_value children no longer point at the parent id');
	check(count_where($db, 'zzdel_setval_child', 'zzdv_parent_id', SENTINEL) === 2,
		'set_value children rewritten to the sentinel value');
	$null_rows = $db->prepare("SELECT COUNT(*) FROM zzdel_null_child WHERE zzdn_parent_id IS NULL");
	$null_rows->execute();
	check((int)$null_rows->fetchColumn() === 2, 'null children had their foreign key set to NULL');

	// -------------------------------------------------------------------------
	section('an unrecognised action is refused, never silently skipped');

	// A typo'd action used to fall through the switch: the dependents were left
	// alone and the parent was deleted anyway, so a misspelled 'prevent' would
	// permit the very deletion it was written to block.
	$db->exec("INSERT INTO zzdel_parent DEFAULT VALUES");
	$bogus_parent_id = (int)$db->lastInsertId('zzdel_parent_zzdp_id_seq');
	$db->prepare("INSERT INTO zzdel_bogus_child (zzdb_parent_id) VALUES (?), (?)")
		->execute(array($bogus_parent_id, $bogus_parent_id));
	rule_row($db, 'zzdel_bogus_child', 'zzdb_parent_id', 'restrict'); // not a valid action

	$bogus_parent = new ZZDelParentModel(NULL);
	$bogus_parent->key = $bogus_parent_id;

	$dry3 = $bogus_parent->permanent_delete_dry_run();
	check($dry3['can_delete'] === false, 'dry run refuses to clear a rule with an unknown action');
	check(count(array_filter($dry3['blocking_reasons'], function ($r) { return stripos($r, 'unknown deletion action') !== false; })) > 0,
		'dry run names the unknown action in its blocking reason', json_encode($dry3['blocking_reasons']));

	$bogus_threw = false;
	try {
		$bogus_parent->permanent_delete();
	} catch (\Throwable $e) {
		$bogus_threw = stripos($e->getMessage(), 'unknown deletion action') !== false;
	}
	check($bogus_threw, 'permanent_delete throws on an unknown action rather than no-opping');
	check(count_where($db, 'zzdel_parent', 'zzdp_id', $bogus_parent_id) === 1,
		'parent survives — the bad rule did not let the delete through');
	check(count_where($db, 'zzdel_bogus_child', 'zzdb_parent_id', $bogus_parent_id) === 2,
		'dependents of the bad rule are untouched');

	// Registration refuses the same name, so it can never reach the rules table.
	$reg_warnings = DeletionRule::registerModelRules('ZZDelBogusActionModel');
	check(count(array_filter($reg_warnings, function ($w) { return stripos($w, 'unknown action') !== false; })) > 0,
		'registerModelRules warns instead of registering an unknown action', json_encode($reg_warnings));
	$registered_bogus = (int)$db->query(
		"SELECT COUNT(*) FROM del_deletion_rules WHERE del_target_table='zzdel_bogus_child'"
	)->fetchColumn();
	check($registered_bogus === 0, 'no rule row was written for the unknown action', "found $registered_bogus");

	// -------------------------------------------------------------------------
	section('permanent_delete action recurses multi-level through real models');

	$has_chain = (int)$db->query(
		"SELECT COUNT(*) FROM del_deletion_rules
		 WHERE del_source_table='aic_conversations' AND del_target_table='aim_conversation_messages'
		 AND del_action='permanent_delete'"
	)->fetchColumn() > 0
	&& (int)$db->query(
		"SELECT COUNT(*) FROM del_deletion_rules
		 WHERE del_source_table='usr_users' AND del_target_table='aic_conversations'
		 AND del_action='permanent_delete'"
	)->fetchColumn() > 0;

	if (!$has_chain) {
		harness_skip('multi-level recursion', 'usr->aic_conversations->aim_conversation_messages chain not registered');
	} else {
		require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversations_class.php'));
		require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/ai_conversation_messages_class.php'));

		$mk_conversation = function ($owner_id, $n_messages) {
			$c = new AiConversation(NULL);
			$c->set('aic_owner_user_id', (int)$owner_id);
			$c->set('aic_title', 'deletion_cascade fixture');
			$c->save();
			$c->load();
			for ($i = 0; $i < $n_messages; $i++) {
				$m = new AiConversationMessage(NULL);
				$m->set('aim_aic_conversation_id', (int)$c->key);
				$m->set('aim_role', $i % 2 === 0 ? 'user' : 'assistant');
				$m->set('aim_content', 'fixture message ' . $i);
				$m->save();
			}
			return (int)$c->key;
		};

		// Victim owns a conversation with messages; a bystander owns another. The
		// victim is created outside make_user so this test owns its deletion; a
		// guarded defer cleans it up only if the delete under test never ran.
		$victim = new User(NULL);
		$victim->set('usr_first_name', 'DelTest');
		$victim->set('usr_last_name', 'Victim');
		$victim->set('usr_email', 'deltest_victim_' . substr(md5(uniqid('', true)), 0, 8) . '@getjoinery.com');
		$victim->set('usr_password', User::GeneratePassword('x' . uniqid()));
		$victim->set('usr_terms_accepted_time', gmdate('Y-m-d H:i:s'));
		$victim->save();
		$victim->load();
		$victim_id = (int)$victim->key;
		harness_defer(function () use ($victim_id) {
			$u = new User($victim_id, true);
			if ($u->key) { $u->permanent_delete(); }
		});

		$bystander = make_user('DelBystander' . substr(md5(uniqid('', true)), 0, 6));

		$victim_conv    = $mk_conversation($victim_id, 2);
		$bystander_conv = $mk_conversation($bystander->key, 1);

		$conv_count = function ($conv_id) use ($db) {
			$q = $db->prepare("SELECT COUNT(*) FROM aic_conversations WHERE aic_conversation_id = ?");
			$q->execute(array($conv_id));
			return (int)$q->fetchColumn();
		};
		$msg_count = function ($conv_id) use ($db) {
			$q = $db->prepare("SELECT COUNT(*) FROM aim_conversation_messages WHERE aim_aic_conversation_id = ?");
			$q->execute(array($conv_id));
			return (int)$q->fetchColumn();
		};

		check($conv_count($victim_conv) === 1 && $msg_count($victim_conv) === 2, 'fixture: victim conversation + 2 messages exist');
		check($conv_count($bystander_conv) === 1 && $msg_count($bystander_conv) === 1, 'fixture: bystander conversation + message exist');

		$victim->permanent_delete();

		check($conv_count($victim_conv) === 0, 'deleting the user cascaded to its conversation (level 2)');
		check($msg_count($victim_conv) === 0, 'the conversation cascade recursed into its messages (level 3)');
		check($conv_count($bystander_conv) === 1 && $msg_count($bystander_conv) === 1,
			'a bystander user conversation and message are untouched');
	}

} finally {
	harness_teardown_data();
}

harness_finish();
