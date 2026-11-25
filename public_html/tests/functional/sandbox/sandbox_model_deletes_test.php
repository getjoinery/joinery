<?php
/** @joinery-test
 * name: sandbox_model_deletes
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 240
 */
/**
 * A sandboxed plugin's rows and a core record's delete (spec
 * sandboxed_plugin_model_files D5, sections 7 and 8).
 *
 * What is pinned here:
 *  - a prevent on a site table is a grant: until it is approved no rule is
 *    registered, and a user with the plugin's rows is deleted as before;
 *  - approved, it blocks, with the platform's words, counting deleted rows
 *    too; the dry run reports the blocker by table, column and record, and
 *    the refused delete links to the page of those rows;
 *  - a blocker on a record the delete would remove is carried up to the dry
 *    run of the record asked for;
 *  - the blocking rows page lists the plugin's links with their counts, and
 *    the rows for one record, at the model's permanent delete level; its
 *    Delete permanently button removes them with the plugin's own rules, and
 *    then the record deletes;
 *  - with the plugin's folder gone the links, the page and the rule stand;
 *  - the Plugins page names the page while there is something on it;
 *  - the approval page describes each model in one line.
 *
 * Run: php tests/run.php db --filter=sandbox_model_deletes
 *
 * @version 1.2 - a prevent between its own models that a user's delete reaches waits on an approval (reviewer1 R6)
 * @version 1.1 - only a blocking link in force (reviewer1 R3)
 * @version 1.0
 */

require_once(__DIR__ . '/../../lib/harness.php');
require_once(__DIR__ . '/../../lib/logic.php');
require_once(__DIR__ . '/../../lib/sandbox.php');
harness_boot();

$sign_in = function (User $user) {
	if (session_status() === PHP_SESSION_NONE) {
		@session_start();
	}
	$_SESSION['usr_user_id'] = (int)$user->key;
	$_SESSION['loggedin'] = true;
	$_SESSION['permission'] = (int)$user->get('usr_permission');
	$_SESSION['saved_messages'] = array();
};
$page_logic = 'adm/logic/admin_sandbox_blocks_logic.php';
// harness_call_logic() fills $_GET or $_POST from one array; the page reads both.
$call = function (array $get, array $post = array()) use ($page_logic) {
	require_once(PathHelper::getIncludePath($page_logic));
	$saved = array($_GET, $_POST, $_SERVER['REQUEST_METHOD'] ?? null, Access::$sapi);
	$_GET = $get;
	$_POST = $post;
	$_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
	Access::$sapi = 'fpm-fcgi';
	try {
		return admin_sandbox_blocks_logic(array_merge($get, $post));
	} catch (Throwable $e) {
		return $e;
	} finally {
		list($_GET, $_POST, $method, Access::$sapi) = $saved;
		$_SERVER['REQUEST_METHOD'] = $method;
	}
};

$files = array(
	// A hold blocks deleting its user.
	'data/holds_class.php' => sandbox_model_file('Hold', 'qmh', array('usr_user_id' => array('type' => 'int8'), 'what' => array('type' => 'text')),
		array('protected:foreign_key_actions' => array('qmh_usr_user_id' => array('action' => 'prevent')))),
	// A task goes with its user, and its own notes stop that.
	'data/tasks_class.php' => sandbox_model_file('Task', 'qmt', array('usr_user_id' => array('type' => 'int8')),
		array('protected:foreign_key_actions' => array('qmt_usr_user_id' => array('action' => 'permanent_delete')))),
	'data/tasknotes_class.php' => sandbox_model_file('TaskNote', 'qmn', array('qmt_task_id' => array('type' => 'int8')),
		array('protected:foreign_key_actions' => array('qmn_qmt_task_id' => array('action' => 'prevent')))),
);

try {
	$admin = make_user('SbxDelAdmin', 10);
	$sign_in($admin);
	$p = sandbox_fixture_plugin(array(), array('files' => $files));
	$name = $p['name'];
	$pm = PluginManager::getInstance();
	$pm->install($name, false);
	$hold = SandboxManifest::classFor($name, 'Hold');
	$task = SandboxManifest::classFor($name, 'Task');
	$tasknote = SandboxManifest::classFor($name, 'TaskNote');
	$multi_tasknote = 'Multi' . $tasknote;
	$db = DbConnector::get_instance()->get_db_link();
	$rule = function (string $class, string $column) use ($db) {
		$q = $db->prepare('SELECT del_action, del_message FROM del_deletion_rules WHERE del_target_table = ? AND del_target_column = ?');
		$q->execute(array($class::$tablename, $column));
		return $q->fetch(PDO::FETCH_ASSOC) ?: null;
	};
	$row_for = function (string $class, array $fields) {
		$r = new $class(NULL);
		foreach ($fields as $k => $v) {
			$r->set($k, $v);
		}
		$r->save();
		return $r;
	};

	section('What the approval page says');
	$approve_page = harness_call_logic('adm/logic/admin_sandbox_approve_logic.php', 'admin_sandbox_approve_logic', array('plugin' => $name), 'GET');
	$lines = (array)($approve_page->data['own_models'] ?? array());
	check(in_array('Hold records: read by staff (level 5 and above). Can stop its user being permanently deleted.', $lines, true)
		&& in_array('Task records: read by staff (level 5 and above). Removed with its user.', $lines, true), 'One line a model: who reads it and what it is linked to', json_encode($lines));
	$asked = (array)($approve_page->data['asked'] ?? array());
	$blocks = reset($asked) !== false ? ($asked["Stop the site's records being deleted while it holds rows for them (an admin can see and remove those rows)"] ?? array()) : array();
	check($blocks === array('A user cannot be permanently deleted while it holds hold records for them (qmh_usr_user_id)',
		'A user cannot be permanently deleted while its task records hold task note records (qmn_qmt_task_id)'),
		'Blocking a site record\'s delete is a grant it asks for, a prevent between its own models that a user\'s delete reaches included (reviewer1 R6)',
		json_encode($asked));

	section('Not approved, it blocks nothing');
	$victim = make_user('SbxDelVictim', 0);
	$row_for($hold, array('qmh_usr_user_id' => (int)$victim->key, 'qmh_what' => 'x'));
	DeletionRule::registerModelRules($hold);
	DeletionRule::registerModelRules($tasknote);
	check($rule($hold, 'qmh_usr_user_id') === null && $rule($tasknote, 'qmn_qmt_task_id') === null,
		'No rule is registered for an unapproved block, on a site table or between its own models');
	$early = make_user('SbxDelEarly', 0);
	$early_task = $row_for($task, array('qmt_usr_user_id' => (int)$early->key));
	$row_for($tasknote, array('qmn_qmt_task_id' => (int)$early_task->key));
	$early->permanent_delete();
	check(!(new User((int)$early->key, TRUE))->key, 'so a user whose task has a note deletes as before');
	$victim->permanent_delete();
	check(!(new User((int)$victim->key, TRUE))->key, 'and a user with its rows deletes as before');

	section('Approved, it blocks');
	sandbox_approve($name);
	$pm->activate($name);
	SandboxPlugins::reset();
	SandboxModels::reset();
	$r = $rule($hold, 'qmh_usr_user_id');
	check(($r['del_action'] ?? '') === 'prevent' && ($r['del_message'] ?? '') === 'The plugin HarnessTest ' . $name . ' holds hold records for this.',
		'The rule is registered, with the platform\'s words', json_encode($r));
	$user = make_user('SbxDelUser', 0);
	$held = $row_for($hold, array('qmh_usr_user_id' => (int)$user->key, 'qmh_what' => 'kept'));
	$gone = $row_for($hold, array('qmh_usr_user_id' => (int)$user->key, 'qmh_what' => 'trashed'));
	$gone->soft_delete();
	$dry = $user->permanent_delete_dry_run();
	check(!$dry['can_delete'] && in_array(array('table' => $hold::$tablename, 'column' => 'qmh_usr_user_id', 'key' => (int)$user->key, 'count' => 2),
		array_map(function ($b) { $b['key'] = (int)$b['key']; $b['count'] = (int)$b['count']; return $b; }, $dry['blockers']), true),
		'The dry run names the blocker by table, column and record, its deleted rows counted', json_encode($dry['blockers']));
	$urls = SandboxBlockingRows::urlsForDryRun($dry);
	check($urls === array(SandboxBlockingRows::url($name, 'Hold', 'qmh_usr_user_id', (int)$user->key)), 'and the refused delete links to the page of those rows', json_encode($urls));
	$threw = '';
	try {
		$user->permanent_delete();
	} catch (SystemDisplayableError $e) {
		$threw = $e->getMessage();
	}
	check(strpos($threw, 'holds hold records for this') !== false && (new User((int)$user->key, TRUE))->key, 'A permanent delete is refused, in the platform\'s words', $threw);
	$user_page = (string)file_get_contents(PathHelper::getIncludePath('adm/admin_users_permanent_delete.php'));
	check(strpos($user_page, 'SandboxBlockingRows::urlsForDryRun($dry_run)') !== false, 'The user\'s permanent delete page prints that link');

	section('A blocker on a record the delete would remove');
	$owner = make_user('SbxDelOwner', 0);
	$t = $row_for($task, array('qmt_usr_user_id' => (int)$owner->key));
	$row_for($tasknote, array('qmn_qmt_task_id' => (int)$t->key));
	$dry = $owner->permanent_delete_dry_run();
	$carried = array_values(array_filter($dry['blockers'], function ($b) use ($tasknote) { return $b['table'] === $tasknote::$tablename; }));
	check(!$dry['can_delete'] && count($carried) === 1 && (int)$carried[0]['key'] === (int)$t->key && $carried[0]['column'] === 'qmn_qmt_task_id',
		'is carried up, naming the task it holds', json_encode($dry['blockers']));
	check(SandboxBlockingRows::urlsForDryRun($dry) === array(SandboxBlockingRows::url($name, 'TaskNote', 'qmn_qmt_task_id', (int)$t->key)),
		'and links to its rows');

	section('The page of the rows that block');
	$links = $call(array('plugin' => $name));
	$by = array();
	foreach ((array)($links instanceof LogicResult ? $links->data['links'] ?? array() : array()) as $link) {
		$by[$link['model'] . '.' . $link['column']] = $link['count'];
	}
	// The hold and the task note made before the approval still name what is
	// gone: no rule acted on them then.
	check($by === array('Hold.qmh_usr_user_id' => 3, 'TaskNote.qmn_qmt_task_id' => 2), 'It lists the plugin\'s blocking links, each with the rows that hold a link now', json_encode($by));
	$staff = make_user('SbxDelStaff', 8);
	$sign_in($staff);
	$low = $call(array('plugin' => $name, 'model' => 'Hold', 'column' => 'qmh_usr_user_id', 'id' => (int)$user->key));
	check($low instanceof Throwable || ($low instanceof LogicResult && $low->error), 'Below the model\'s permanent delete level (9), the rows are refused');
	$sign_in($admin);
	$rows = $call(array('plugin' => $name, 'model' => 'Hold', 'column' => 'qmh_usr_user_id', 'id' => (int)$user->key));
	$keys = array_map(function ($r) { return (int)$r->key; }, $rows instanceof LogicResult ? (array)($rows->data['rows'] ?? array()) : array());
	sort($keys);
	check($keys === array((int)$held->key, (int)$gone->key), 'It lists the rows for one record, the deleted one too', json_encode($keys));

	section('With its folder gone');
	$away = $p['dir'] . '.away';
	rename($p['dir'], $away);
	harness_defer(function () use ($away, $p) {
		if (is_dir($away) && !is_dir($p['dir'])) {
			rename($away, $p['dir']);
		}
	});
	SandboxPlugins::reset();
	SandboxModels::reset();
	$rows = $call(array('plugin' => $name, 'model' => 'Hold', 'column' => 'qmh_usr_user_id', 'id' => (int)$user->key));
	check($rows instanceof LogicResult && !$rows->error && count($rows->data['rows'] ?? array()) === 2, 'the page still lists them');
	check(($rule($hold, 'qmh_usr_user_id')['del_action'] ?? '') === 'prevent' && count(SandboxBlockingRows::links($name)) === 2, 'and the rule and links stand');

	section('Deleting them');
	$r = $call(array('plugin' => $name, 'model' => 'Hold', 'column' => 'qmh_usr_user_id', 'id' => (int)$user->key),
		array('action' => 'delete', 'row' => (string)$held->key));
	check($r instanceof LogicResult && $r->redirect && !(new $hold((int)$held->key, TRUE))->key && (new $hold((int)$gone->key, TRUE))->key,
		'Delete permanently removes one row', is_object($r) && $r instanceof Throwable ? $r->getMessage() : '');
	$call(array('plugin' => $name, 'model' => 'Hold', 'column' => 'qmh_usr_user_id', 'id' => (int)$user->key), array('action' => 'delete', 'row' => 'all'));
	check(SandboxBlockingRows::count($hold, 'qmh_usr_user_id', (int)$user->key) === 0, 'and the button for all removes the rest');
	rename($away, $p['dir']);
	SandboxPlugins::reset();
	SandboxModels::reset();
	$user->permanent_delete();
	check(!(new User((int)$user->key, TRUE))->key, 'Then the user deletes');
	foreach (array(array('Task', 'qmt_usr_user_id'), array('Hold', 'qmh_what')) as list($m, $c)) {
		$r = $call(array('plugin' => $name, 'model' => $m, 'column' => $c), array('action' => 'delete', 'row' => 'all'));
		check($r instanceof LogicResult && $r->error && (new $task((int)$t->key, TRUE))->key,
			"The page refuses $m.$c, which is no link that stops a delete, and deletes nothing (reviewer1 R3)");
	}
	// Its note goes first, then the task can, then its user.
	foreach (new $multi_tasknote(array('qmt_task_id' => (int)$t->key)) as $n) {
		$n->permanent_delete();
	}
	$owner->permanent_delete();
	check(!(new $task((int)$t->key, TRUE))->key && !(new User((int)$owner->key, TRUE))->key, 'With its note gone, the user deletes and takes the task with them');

	section('The Plugins page');
	$plugins_page = (string)file_get_contents(PathHelper::getIncludePath('adm/admin_plugins.php'));
	check(strpos($plugins_page, "SandboxBlockingRows::links(\$plugin['name'])") !== false && SandboxBlockingRows::links($name) !== array(),
		'names the page while there is a link to show');
} catch (Throwable $e) {
	check(false, 'Unexpected ' . get_class($e), $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
