<?php
/** @joinery-test
 * name: access_declarations
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * The permission declarations (spec declared_permissions 3.1, 3.6, 3.9).
 *
 * permissions.json holds the levels and the class table, and Access reads it:
 * a requirement is a level or class:verb, and a malformed one throws, so a typo
 * never reads as 0. AccessDeclarations reads every page header, model class,
 * column class and action level in the tree; this suite proves each rule on a
 * stand-in tree of hand-made files, then holds the real tree to every rule
 * that is on. A rule asking for declarations a later work package makes is
 * reported with its count until that package turns it on.
 *
 * Run: php tests/run.php --filter=access_declarations
 *
 * @version 1.2 - a level compared in code; every rule is on
 * @version 1.1 - a descriptor builder's level; a misplaced min_permission
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

// ---------------------------------------------------------------------------
section('permissions.json');

$json = json_decode((string)file_get_contents(PathHelper::getIncludePath('permissions.json')), true);
check(Access::schemaErrors($json) === array(), 'permissions.json passes its schema', json_encode(Access::schemaErrors($json)));
check(Access::levels() === array(0 => 'Regular User', 5 => 'Assistant', 6 => 'Editor', 7 => 'Manager',
	8 => 'Admin', 9 => 'Senior Admin', 10 => 'Master Admin'), 'the seven levels, with the owner\'s names (Q14)',
	json_encode(Access::levels()));
check(Access::classes() === array('content', 'money', 'personal', 'config', 'restricted', 'keys'),
	'the six classes', json_encode(Access::classes()));

$good = array('levels' => array('0' => 'User', '10' => 'Owner'),
	'classes' => array('c' => array('read' => 0, 'write' => 10, 'soft_delete' => 10, 'permanent_delete' => 10)));
check(Access::schemaErrors($good) === array(), 'a minimal table passes', json_encode(Access::schemaErrors($good)));
$cases = array(
	'not JSON'                  => array(null, 'not valid JSON'),
	'no level 10'               => array(array('levels' => array('0' => 'User')) + $good, 'level 10 is missing'),
	'a level out of range'      => array(array('levels' => array('0' => 'U', '10' => 'O', '11' => 'X')) + $good, "level '11' is not 0 to 10"),
	'a verb on no level'        => array(array('classes' => array('c' => array('read' => 5) + $good['classes']['c'])) + $good, 'c:read is not a declared level'),
	'a verb below the one before' => array(array('classes' => array('c' => array('read' => 10, 'write' => 0, 'soft_delete' => 10, 'permanent_delete' => 10))) + $good, 'c:write (0) is below the verb before it (10)'),
	'an unknown verb'           => array(array('classes' => array('c' => $good['classes']['c'] + array('delete' => 10))) + $good, "c has an unknown verb 'delete'"),
	'a missing verb'            => array(array('classes' => array('c' => array('read' => 0, 'write' => 10, 'soft_delete' => 10))) + $good, 'c:permanent_delete is not a declared level'),
);
foreach ($cases as $label => [$data, $want]) {
	$errors = Access::schemaErrors($data);
	check(in_array($want, $errors, true), "the schema refuses $label", json_encode($errors));
}

// ---------------------------------------------------------------------------
section('a requirement is a level or class:verb');

check(Access::level('personal:write') === 8 && Access::level('content:read') === 5
	&& Access::level('keys:read') === 10 && Access::level('money:soft_delete') === 7
	&& Access::level('config:permanent_delete') === 9, 'class:verb reads the table');
check(Access::level(0) === 0 && Access::level(8) === 8 && Access::level(10) === 10, 'a level is itself');
check(Access::allows('personal:write', 8) && !Access::allows('personal:write', 7), 'allows() is the level comparison');
check(Access::levelName(9) === 'Senior Admin' && Access::levelName(3) === '3', 'a level\'s name, or its number');
foreach (array("'personal:writ'" => 'personal:writ', "'person:write'" => 'person:write', "'8'" => '8', 'null' => null,
	'11' => 11, '-1' => -1, "'personal'" => 'personal', 'a float' => 8.0) as $label => $bad) {
	$threw = false;
	try {
		Access::level($bad);
	} catch (InvalidArgumentException $e) {
		$threw = true;
	}
	check($threw, "$label is refused, never read as a level");
}

// ---------------------------------------------------------------------------
section('decide() reads the session\'s level');

/** A session stand-in: decide() asks only these two. */
class AccessTestSession {
	public $permission; public $user_id;
	function __construct($permission, $user_id) { $this->permission = $permission; $this->user_id = $user_id; }
	function get_permission() { return $this->permission; }
	function get_user_id() { return $this->user_id; }
}
// Only outcomes that write no audit row; the rows are access_audit's.
check(Access::decide('content:read', new AccessTestSession(5, 0)) === true, 'a 5 may read content');
check(Access::decide('content:write', new AccessTestSession(5, 0)) === false, 'a 5 may not write it');
check(Access::decide(0, new AccessTestSession(0, 0)) === true, 'level 0 is anyone');
check(Access::decide(8, new AccessTestSession(0, 0)) === false, 'an anonymous session is refused');

// ---------------------------------------------------------------------------
section('each rule, on a stand-in tree');

$root = harness_scratch_dir('access_declarations') . '/' . bin2hex(random_bytes(4));
$put = function ($rel, $text) use ($root) {
	@mkdir(dirname("$root/$rel"), 0770, true);
	file_put_contents("$root/$rel", $text);
};
$put('adm/admin_good.php', "<?php\n/** @joinery-admin class=personal */\n");
$put('adm/admin_level.php', "<?php\n/** @joinery-admin level=8 */\n");
$put('adm/admin_badhead.php', "<?php\n/** @joinery-admin personal */\n");
$put('adm/admin_unknown.php', "<?php\n/** @joinery-admin class=secret */\n");
$put('adm/admin_twice.php', "<?php\n/** @joinery-admin class=content */\n/** @joinery-admin class=money */\n");
$put('adm/admin_bare.php', "<?php\n// check_permission() in a comment is not a call\n");
$put('adm/admin_named.php', "<?php\n/** @joinery-admin class=content */\nrequire_once(PathHelper::getIncludePath('plugins/demo/logic/odd_name_logic.php'));\n");
$put('plugins/demo/logic/odd_name_logic.php', "<?php\n\$session->check_permission(5);\n");
$put('adm/logic/admin_good_logic.php', "<?php\n\$session->check_permission(8);\n");
$put('plugins/demo/admin/admin_things.php', "<?php\n/** @joinery-admin class=content */\n");
$put('plugins/demo/views/admin/sub/page.php', "<?php\n/** @joinery-admin level=11 */\n");
$put('data/things_class.php', <<<'PHP'
<?php
class Thing extends SystemBase {
	public static $tablename = 'thg_things';
	public static $access_class = 'personal';
	public static $owner_column = 'thg_usr_user_id';
	public static $delete_permission = 9;
	// 'thg_fake' => array('type'=>'text', 'access_class'=>'content'),   a comment, not a column
	public static $field_specifications = array(
		'thg_thing_id' => array('type'=>'int8', 'serial'=>true),
		'thg_usr_user_id' => array('type'=>'int4', 'validation' => array('required' => true)),
		'thg_secret' => array('type'=>'text', 'access_class'=>'config'),
		'thg_hash' => array('type'=>'text', 'access_class'=>'keys'),
		'thg_note' => array('type'=>'text', 'access_class'=>'content'),
	);
}
class Gadget extends SystemBase {
	public static $tablename = 'gdg_gadgets';
	public static $access_class = 'secret';
	public static $owner_column = 'gdg_owner';
	public static $write_permission = 12;
	public static $field_specifications = [
		'gdg_gadget_id' => ['type'=>'int8'],
		'gdg_flag' => ['type'=>'bool', 'access_class'=>'sensitive'],
	];
}
class Widget extends SystemBase {
	public static $tablename = 'wdg_widgets';
	public static $field_specifications = array(
		'wdg_widget_id' => array('type'=>'int8'),
		'wdg_label' => array('type'=>'text', 'access_class'=>'config'),
	);
}
PHP);
$put('logic/demo_logic.php', <<<'PHP'
<?php
function good_logic_descriptor() { return array('auth' => array('min_user_permission' => 'personal:write'), 'mutates' => true); }
function plain_logic_descriptor() { return array('auth' => array('min_user_permission' => 8)); }
function typo_logic_descriptor() { return array('auth' => array('min_user_permission' => 'personal:writ')); }
function constant_logic_descriptor() { return array('auth' => array('min_user_permission' => Foo::LEVEL)); }
function readmut_logic_descriptor() { return array('auth' => array('min_user_permission' => 'content:read'), 'mutates' => true); }
function dquote_logic_descriptor() { return array('auth' => array('min_user_permission' => "money:write"), 'mutates' => true); }
function readonly_logic_descriptor() { return array('auth' => array('min_user_permission' => 'content:read'), 'mutates' => false); }
function none_logic_descriptor() { return array('auth' => array()); }
function misplaced_logic_descriptor() { return array('auth' => array('min_user_permission' => 0), 'min_permission' => 8); }
function built_logic_descriptor() { return DemoAction::descriptor(array('mutates' => true)); }
PHP);
$put('logic/inline_logic.php', <<<'PHP'
<?php
function inline_logic($session) {
	if ($session->get_permission() >= 10) {}
	if ($_SESSION['permission'] == 10) {}
	if ((int)$user->get('usr_permission') < 5) {}
	if (10 <= $session->get_permission()) {}
	$identity = array('current_user_permission' => $session->get_permission());
	// a comment: get_permission() >= 10
	if (Access::permits(10, $session)) {}
}
PHP);
$put('plugins/demo/includes/DemoAction.php', <<<'PHP'
<?php
class DemoAction {
	public static function descriptor(array $d) { return array_merge(array('auth' => array('min_user_permission' => 'content:write')), $d); }
}
PHP);

$models = AccessDeclarations::models($root);
check(array_keys($models) === array('Gadget', 'Thing', 'Widget'), 'every model in a file is read', json_encode(array_keys($models)));
check($models['Thing']['fields'] === array('thg_thing_id', 'thg_usr_user_id', 'thg_secret', 'thg_hash', 'thg_note'),
	'a model\'s columns are its top-level keys, never a nested one or a commented one', json_encode($models['Thing']['fields']));
check($models['Thing']['exceptions'] === array('soft_delete' => 9), '$delete_permission is the soft-delete exception',
	json_encode($models['Thing']['exceptions']));
check($models['Gadget']['columns'] === array('gdg_flag' => 'sensitive'), 'a [...] spec is read too', json_encode($models['Gadget']['columns']));
$pages = AccessDeclarations::pages($root);
check(isset($pages['/admin/admin_good'], $pages['/plugins/demo/admin/admin_things'], $pages['/admin/demo/sub/page']),
	'pages of all three URL families', json_encode(array_keys($pages)));
check($pages['/admin/admin_level']['header'] === array('level' => 8), 'a level header', json_encode($pages['/admin/admin_level']['header']));
check((AccessDeclarations::actions($root)['dquote']['level'] ?? null) === 'money:write', 'a double-quoted level reads as one',
	json_encode(AccessDeclarations::actions($root)['dquote'] ?? null));
check((AccessDeclarations::actions($root)['built']['level'] ?? null) === 'content:write', 'a builder\'s level is the descriptor\'s',
	json_encode(AccessDeclarations::actions($root)['built'] ?? null));

$found = array();
foreach (AccessDeclarations::problems($root, array('thg_things.thg_secret', 'thg_things.thg_hash')) as $p) {
	$found[$p['rule'] . ' ' . $p['where']] = $p['why'];
}
$want = array(
	'page_header_invalid /admin/admin_badhead',
	'page_header_invalid /admin/admin_unknown',
	'page_header_invalid /admin/admin_twice',
	'page_header_invalid /admin/demo/sub/page',
	'page_undeclared /admin/admin_bare',
	'admin_check_permission /admin/admin_good',
	'admin_check_permission /admin/admin_named',
	'model_class_unknown Gadget',
	'model_exception_invalid Gadget',
	'owner_column_missing Gadget',
	'column_class_unknown Gadget.gdg_flag',
	'column_looser_than_model Thing.thg_note',
	'sealed_column_below_keys Thing.thg_secret',
	'model_undeclared Widget',
	'column_looser_than_model Widget.wdg_label',
	'action_level_invalid typo',
	'action_level_invalid constant',
	'action_mutates_on_read readmut',
	'action_undeclared none',
	'action_level_misplaced misplaced',
	'level_compared_inline logic/inline_logic.php:3',
	'level_compared_inline logic/inline_logic.php:4',
	'level_compared_inline logic/inline_logic.php:5',
	'level_compared_inline logic/inline_logic.php:6',
);
foreach ($want as $key) {
	check(isset($found[$key]), "the rule fires: $key", $found[$key] ?? 'not reported');
}
$extra = array_diff(array_keys($found), $want);
check($extra === array(), 'and nothing else (the good page, column, sealed keys column and actions pass)', json_encode(array_values($extra)));
check(count(array_unique(array_map(function ($k) { return strtok($k, ' '); }, $want))) === count(AccessDeclarations::RULES),
	'every rule has a case here');

// ---------------------------------------------------------------------------
section('the tree');

$by_rule = array_fill_keys(array_keys(AccessDeclarations::RULES), array());
foreach (AccessDeclarations::problems() as $p) {
	$by_rule[$p['rule']][] = $p['where'] . ': ' . $p['why'];
}
foreach (AccessDeclarations::RULES as $rule => $on) {
	if ($on) {
		check($by_rule[$rule] === array(), "no $rule", implode("\n", array_slice($by_rule[$rule], 0, 20)));
	} else {
		harness_skip("$rule: " . count($by_rule[$rule]) . ' left', 'its work package turns the rule on');
	}
}
check(count(AccessDeclarations::pages()) > 200 && count(AccessDeclarations::models()) > 200 && count(AccessDeclarations::actions()) > 300,
	'the scan reaches the tree (over 200 pages, 200 models, 300 actions)');

harness_finish();
