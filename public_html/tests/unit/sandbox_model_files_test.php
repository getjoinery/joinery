<?php
/** @joinery-test
 * name: sandbox_model_files
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * A sandboxed plugin's model files (spec sandboxed_plugin_model_files): the
 * reader (SandboxModelFile), which tokenizes a file and runs nothing, and the
 * checker (SandboxModelDeclarations), which holds what it read to the
 * allow-lists.
 *
 * What is pinned here:
 *  - a model file written as a first-party one is read: its statics as
 *    plain values, typed (an int key stays an int), its methods and members;
 *  - everything that is not a plain declaration is refused, naming the line:
 *    a require, a namespace, a use, declare, a function, text after ?>, a
 *    trait, an attribute, a constant, another base class, an interface, a
 *    static that is a constant, a call, a joined or interpolated string, a
 *    heredoc, a spread, a number not in decimal;
 *  - the reader answers the same through the parser jail;
 *  - the checker builds the declaration the site uses (its class, its table
 *    sbx_{plugin}__{tablename}, each delete rule's source table, a prevent's
 *    message the platform's) and refuses, naming the file and the thing,
 *    every static, field key, type, default, delete rule and method the
 *    sandbox cannot honour;
 *  - the folder rule: model files sit directly in src/data, named *_class.php.
 *
 * @version 1.1 - reviewer1 R1, R4, R5: rules fit their column, braces in strings, large floats; B1's guard
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

/** A model file with $replace applied to the good one. */
function smf_file(array $replace = array(), string $extra = ''): string {
	$text = <<<'PHP'
<?php
/** A note. */
class Note extends SystemBase {
	public static $prefix = 'qzn';
	public static $tablename = 'qzn_notes';
	public static $pkey_column = 'qzn_note_id';
	public static $access_class = 'content';
	public static $field_specifications = array(
		'qzn_note_id'     => array('type' => 'int8', 'is_nullable' => false, 'serial' => true, 'is_primary_key' => true),
		'qzn_usr_user_id' => array('type' => 'int8', 'is_nullable' => true, 'index' => true),
		'qzn_text'        => array('type' => 'varchar(200)', 'required' => true),
		'qzn_pinned'      => array('type' => 'bool', 'default' => false),
		'qzn_amount'      => array('type' => 'numeric(10,2)', 'default' => -1.5),
		'qzn_kind'        => array('type' => 'varchar(20)', 'allowed_values' => array('a', 'b', 3)),
		'qzn_create_time' => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
		'qzn_delete_time' => array('type' => 'timestamp(6)', 'is_nullable' => true),
	);
	protected static $foreign_key_actions = array(
		'qzn_usr_user_id' => array('action' => 'cascade'),
	);
	private $cache = array(1, 2);
	public function line(): string { return "{$this->get('qzn_text')}"; }
	function prepare() {
		if (trim((string)$this->get('qzn_text')) === '') {
			throw new RuntimeException('A note needs some text.');
		}
	}
}

class MultiNote extends SystemMultiBase {
	protected static $model_class = 'Note';
}
PHP;
	foreach ($replace as $from => $to) {
		$text = str_replace($from, $to, $text);
	}
	return $text . $extra;
}

/** The checker's problems for one file, or its declaration of Note. */
function smf_check(string $text, string $file = 'notes_class.php'): array {
	return SandboxModelDeclarations::check('qzplug', 'Quiz plugin', array($file => SandboxModelFile::read($text)));
}

function smf_refused(string $text, string $needle): bool {
	foreach (smf_check($text)['problems'] as $p) {
		if (stripos($p, $needle) !== false) {
			return true;
		}
	}
	return false;
}

try {
	section('The reader: a first-party model file');
	$read = SandboxModelFile::read(smf_file());
	check($read['ok'] === true && count($read['classes']) === 2, 'The file reads as two classes', json_encode($read['error'] ?? ''));
	$note = $read['classes'][0];
	check($note['name'] === 'Note' && $note['extends'] === 'SystemBase' && $read['classes'][1]['name'] === 'MultiNote', 'Note extends SystemBase; MultiNote is its collection');
	check($note['statics']['prefix']['value'] === array('t' => 's', 'v' => 'qzn') && $note['statics']['prefix']['line'] === 4, 'A static is read as a typed value, with its line');
	check(array_keys($note['methods']) === array('line', 'prepare') && array_keys($note['members']) === array('cache'), 'Its methods and members are listed, not read');
	$specs = SandboxModelDeclarations::decode($note['statics']['field_specifications']['value']);
	check($specs['qzn_amount']['default'] === -1.5 && $specs['qzn_pinned']['default'] === false && $specs['qzn_kind']['allowed_values'] === array('a', 'b', 3),
		'Numbers, booleans and lists keep their kinds', json_encode($specs['qzn_kind']));
	$keyed = SandboxModelFile::read("<?php\nclass A extends SystemBase { public static \$a = array(3 => 'x', '3a' => 'y'); }");
	check(SandboxModelDeclarations::decode($keyed['classes'][0]['statics']['a']['value']) === array(3 => 'x', '3a' => 'y'), 'An integer key stays an integer');

	section('The reader: anything else is refused, naming the line');
	$refusals = array(
		"<?php\nrequire 'x.php';\nclass A extends SystemBase {}"                       => 'line 2: a model file includes no other file',
		"<?php\nnamespace X;\nclass A extends SystemBase {}"                            => 'line 2: a model file has no namespace',
		"<?php\nuse Foo\\Bar;\nclass A extends SystemBase {}"                           => 'imports nothing',
		"<?php\ndeclare(strict_types=1);\nclass A extends SystemBase {}"                => 'leave out declare(strict_types=1);',
		"<?php\nfunction f() {}\nclass A extends SystemBase {}"                         => 'no function outside its classes',
		"<?php\necho 'x';\nclass A extends SystemBase {}"                               => 'is outside them',
		"<?php\nclass A extends SystemBase {}\n?>\nhello"                               => 'nothing may follow the closing ?>',
		"hello<?php\nclass A extends SystemBase {}"                                     => 'starts with <?php',
		"<?php\nclass A extends SystemBase { use T; }"                                  => 'uses a trait',
		"<?php\nclass A extends SystemBase { #[Attr] public static \$a = 1; }"          => 'has an attribute',
		"<?php\nclass A extends SystemBase { const X = 1; }"                            => 'class constant',
		"<?php\nclass A extends Foo {}"                                                 => 'extends Foo',
		"<?php\nclass A extends \\SystemBase {}"                                        => 'written as such',
		"<?php\nclass A extends SystemBase implements I {}"                             => 'implements an interface',
		"<?php\ninterface I {}"                                                         => 'no interface',
		"<?php\ntrait T {}"                                                             => 'no trait',
		"<?php\nabstract class A extends SystemBase {}"                                 => 'with no abstract',
		"<?php\nclass A extends SystemBase { public static \$a = FOO; }"                => 'A::$a is not a plain value ("FOO")',
		"<?php\nclass A extends SystemBase { public static \$a = self::X; }"            => 'not a plain value',
		"<?php\nclass A extends SystemBase { public static \$a = foo(); }"              => 'not a plain value',
		"<?php\nclass A extends SystemBase { public static \$a = 'a' . 'b'; }"          => 'nothing joined or worked out',
		"<?php\nclass A extends SystemBase { public static \$a = 1 + 2; }"              => 'nothing joined or worked out',
		"<?php\nclass A extends SystemBase { public static \$a = \"x\$y\"; }"           => 'something joined into it',
		"<?php\nclass A extends SystemBase { public static \$a = \"x\\n\"; }"           => 'write it in single quotes',
		"<?php\nclass A extends SystemBase { public static \$a = <<<X\nhi\nX; }"        => 'heredoc',
		"<?php\nclass A extends SystemBase { public static \$a = [...\$b]; }"           => 'spreads',
		"<?php\nclass A extends SystemBase { public static \$a = 0x1F; }"               => 'in decimal',
		"<?php\nclass A extends SystemBase { public static \$a = array(foo() => 1); }"  => 'not a plain value',
		"<?php\nclass A extends SystemBase { public static \$a = new B(); }"            => 'not a plain value',
		"<?php\nclass A extends SystemBase { public static \$a = User::OTHER; }"        => 'not a plain value',
		"<?php\nclass A extends SystemBase { public static \$a = 1; public static \$a = 2; }" => 'declared twice',
		"<?php\nclass A extends SystemBase { public static \$a = ; }"                   => 'PHP cannot read this file',
	);
	foreach ($refusals as $code => $needle) {
		$r = SandboxModelFile::read($code);
		check($r['ok'] === false && stripos((string)$r['error'], $needle) !== false, 'Refused: ' . $needle, json_encode($r));
	}
	$r = SandboxModelFile::read("<?php\nclass A extends SystemBase {}\n\n\nclass B extends SystemBase { use T; }");
	check(strpos((string)($r['error'] ?? ''), 'line 5:') === 0, 'The message names the line', (string)($r['error'] ?? ''));
	$r = SandboxModelFile::read(str_repeat(' ', SandboxModelFile::MAX_BYTES + 1));
	check($r['ok'] === false && strpos($r['error'], '256 KB') !== false, 'A file over 256 KB is refused');
	$r = SandboxModelFile::read(smf_file(array(), "\n?>\n"));
	check($r['ok'] === true, 'A closing ?> with nothing after it is allowed');

	$r = SandboxModelFile::read("<?php\nclass A extends SystemBase {\n\tfunction label() { return \"\$a{\"; }\n\tfunction permanent_delete(\$debug = false) { return \"\$debug}\"; }\n}\n");
	check($r['ok'] === true && array_keys($r['classes'][0]['methods']) === array('label', 'permanent_delete'),
		'A brace inside an interpolated string hides no method (reviewer1 R4)', json_encode($r));
	$r = SandboxModelFile::read("<?php\nclass A extends SystemBase {\n\tfunction f() { return \"{\$x}\"; }\n\tuse T;\n}\n");
	check($r['ok'] === false && strpos((string)$r['error'], 'uses a trait') !== false, 'nor a trait use after it', json_encode($r));
	$r = SandboxModelFile::read("<?php\nclass A extends SystemBase { public static \$a = " . str_repeat('9', 400) . ".0; }");
	check($r['ok'] === false && strpos((string)$r['error'], 'at most 15 digits') !== false, 'A float too large to hold is refused (reviewer1 R5)', json_encode($r));
	$r = SandboxModelFile::read("<?php\nclass A extends SystemBase { public static \$a = 1.25; }");
	check($r['ok'] === true && SandboxModelDeclarations::decode($r['classes'][0]['statics']['a']['value']) === 1.25, 'and an ordinary one is read');

	section('The reader runs in the parser jail');
	if (DocumentText::jailAvailable() && DocumentText::jailFenced()) {
		$jailed = DocumentText::parseWithMany('SandboxModelFile', array('notes_class.php' => smf_file()));
		check(is_string($jailed['notes_class.php']) && json_decode($jailed['notes_class.php'], true) === json_decode(json_encode($read), true),
			'Through the jail, the answer is the same');
	} else {
		check(false, 'This box has the parser jail installed', 'the suite runs on a box with the jail');
	}

	section('The checker: the declaration the site uses');
	$checked = smf_check(smf_file());
	check($checked['problems'] === array(), 'The good file passes', json_encode($checked['problems']));
	$d = $checked['models']['Note'] ?? array();
	check(($d['site_class'] ?? '') === 'Sbx_qzplug_Note' && $d['table'] === 'sbx_qzplug__qzn_notes' && $d['tablename'] === 'qzn_notes',
		'Its site class is Sbx_{plugin}_{Class}, its table sbx_{plugin}__{tablename}');
	check($d['foreign_key_actions'] === array('qzn_usr_user_id' => array('action' => 'cascade', 'source_table' => 'usr_users')),
		'A delete rule\'s source table is resolved by the native convention', json_encode($d['foreign_key_actions']));
	check($d['methods'] === array('line', 'prepare') && $d['owner_column'] === null && $d['api_readable'] === false, 'Methods, owner and API flag are kept');
	$prevent = smf_check(smf_file(array("array('action' => 'cascade')" => "array('action' => 'prevent')")))['models']['Note'];
	check($prevent['foreign_key_actions']['qzn_usr_user_id']['message'] === 'The plugin Quiz plugin holds note records for this.',
		'A prevent carries the platform\'s message', json_encode($prevent['foreign_key_actions']));
	$deleted = smf_check(smf_file(array("array('action' => 'cascade')" => "array('action' => 'set_value', 'value' => User::USER_DELETED)")))['models']['Note'];
	check(($deleted['foreign_key_actions']['qzn_usr_user_id']['value'] ?? null) === User::USER_DELETED, 'set_value takes User::USER_DELETED as its value');
	$two = SandboxModelDeclarations::check('qzplug', 'Quiz', array(
		'notes_class.php' => SandboxModelFile::read(smf_file()),
		'comments_class.php' => SandboxModelFile::read(sandbox_model_text_comment()),
	));
	check($two['problems'] === array() && ($two['models']['Comment']['foreign_key_actions']['qzc_qzn_note_id']['source_table'] ?? '') === 'sbx_qzplug__qzn_notes',
		'A link between two of its models resolves to its own table', json_encode($two['problems']));

	section('The checker: refused, naming the file and the thing');
	$cases = array(
		'api_writable true'           => array(array(), "\tpublic static \$api_writable = true;\n", 'only the plugin\'s own code writes Note'),
		'api_unwritable_fields'       => array(array(), "\tpublic static \$api_unwritable_fields = array();\n", 'has nothing to act on'),
		'a SystemBase static'         => array(array(), "\tpublic static \$sealed_fields = array();\n", 'Note::$sealed_fields is not available to a sandboxed plugin'),
		'public read'                 => array(array(), "\tpublic static \$api_public_read = true;\n", 'Note::$api_public_read is not available'),
		'a retention policy'          => array(array(), "\tpublic static \$retention_policy = array();\n", 'Note::$retention_policy is not available'),
		'a static of its own'         => array(array(), "\tpublic static \$colour = 'red';\n", 'Note::$colour is not available'),
		'a prefix of four letters'    => array(array("\$prefix = 'qzn'" => "\$prefix = 'qznx'"), '', 'three lowercase letters'),
		'a prefix the site has'       => array(array("'qzn" => "'usr", "\$prefix = 'qzn'" => "\$prefix = 'usr'"), '', 'is taken by a model of the site'),
		'a table not of its prefix'   => array(array("'qzn_notes'" => "'notes'"), '', 'starts with its prefix'),
		'a column not of its prefix'  => array(array("'qzn_kind'" => "'kind'"), '', 'a column is named for its model\'s prefix'),
		'a real database constraint'  => array(array("'index' => true)," => "'foreign_key' => 'usr_users.usr_user_id'),"), '', 'declare the link in $foreign_key_actions'),
		'an unknown field key'        => array(array("'required' => true)," => "'required' => true, 'encrypted' => true),"), '', "'encrypted' is not available"),
		'a type of its own'           => array(array("'varchar(200)'" => "'varchar(200) PRIMARY KEY'"), '', "'type' is one of"),
		'a varchar too long'          => array(array("'varchar(200)'" => "'varchar(9999)'"), '', "'type' is one of"),
		'a default that is SQL'       => array(array("'default' => false" => "'default' => 'nextval()'"), '', 'the only one allowed is'),
		'CURRENT_TIMESTAMP'           => array(array("'default' => 'now()'" => "'default' => 'CURRENT_TIMESTAMP'"), '', 'the only one allowed is'),
		'now() on a text column'      => array(array("'required' => true)," => "'required' => true, 'default' => 'now()'),"), '', 'on a timestamp column'),
		'unique_with another table'   => array(array("'index' => true)," => "'unique_with' => array('usr_email')),"), '', "'unique_with' is a list of the model's other columns"),
		'index_with nested'           => array(array("'index' => true)," => "'index_with' => array(array('qzn_text'))),"), '', "'index_with' is a list"),
		'a flag that is not a flag'   => array(array("'index' => true)," => "'index' => 'yes'),"), '', "'index' is true or false"),
		'serial off the key'          => array(array("'index' => true)," => "'serial' => true),"), '', 'belongs to the key column only'),
		'too many allowed values'     => array(array("array('a', 'b', 3)" => 'array(' . implode(', ', range(1, 51)) . ')'), '', "'allowed_values' is a list of at most 50"),
		'a column class unknown'      => array(array("'required' => true)," => "'required' => true, 'access_class' => 'secret'),"), '', "'access_class' is one of"),
		'a class unknown'             => array(array("\$access_class = 'content'" => "\$access_class = 'everyone'"), '', 'Note::$access_class is one of'),
		'no class at all'             => array(array("	public static \$access_class = 'content';\n" => ''), '', 'Note needs $access_class'),
		'a level past 10'             => array(array(), "\tpublic static \$read_permission = 11;\n", 'is a whole number from 0 to 10'),
		'an owner that is text'       => array(array(), "\tpublic static \$owner_column = 'qzn_text';\n", 'is false or one of its int8 columns'),
		'unreadable not its own'      => array(array(), "\tpublic static \$api_unreadable_fields = array('usr_password');\n", 'lists its own columns'),
		'a rule with a message'       => array(array("array('action' => 'cascade')" => "array('action' => 'prevent', 'message' => 'Mine')"), '', "a rule's message is the platform's own"),
		'a rule naming a plugin'      => array(array("array('action' => 'cascade')" => "array('action' => 'cascade', 'plugin' => 'x')"), '', "'plugin' is set by the platform"),
		'an unknown action'           => array(array("array('action' => 'cascade')" => "array('action' => 'cascad')"), '', "'action' is one of"),
		'a set_value with no value'   => array(array("array('action' => 'cascade')" => "array('action' => 'set_value')"), '', "a set_value rule's 'value'"),
		'another plugin\'s table'     => array(array("array('action' => 'cascade')" => "array('action' => 'cascade', 'source_table' => 'sbx_other__oth_things')"), '', 'not another plugin\'s'),
		'a table the site lacks'      => array(array("array('action' => 'cascade')" => "array('action' => 'cascade', 'source_table' => 'zz_nothing')"), '', 'no model of this site has the table'),
		'a class the site lacks'      => array(array("array('action' => 'cascade')" => "array('action' => 'cascade', 'source_class' => 'NoSuchModelHere')"), '', 'is not a model of this site'),
		'a link with no rule'         => array(array("protected static \$foreign_key_actions = array(\n\t\t'qzn_usr_user_id' => array('action' => 'cascade'),\n\t);" => ''), '', 'reads as a link to usr_users'),
		'a rule off its columns'      => array(array("'qzn_usr_user_id' => array('action' => 'cascade')," => "'qzn_usr_user_id' => array('action' => 'cascade'), 'qzn_evt_event_id' => array('action' => 'null'),"), '', "a delete rule is for one of Note's linking columns"),
		'USER_DELETED as a default'   => array(array("'default' => false" => "'default' => User::USER_DELETED"), '', 'only as a delete rule\'s set_value'),
		'a method of SystemBase'      => array(array("function prepare() {" => "function load() {"), '', 'Note::load() would replace the site\'s own'),
		'a member of SystemBase'      => array(array("private \$cache" => "private \$data"), '', 'Note::$data would replace the site\'s own'),
		'a key not int8 serial'       => array(array("'qzn_note_id'     => array('type' => 'int8', 'is_nullable' => false, 'serial' => true, 'is_primary_key' => true)" => "'qzn_note_id' => array('type' => 'int4', 'is_nullable' => false)"), '', "is declared 'type' => 'int8', 'serial' => true"),
		'collection defaults'         => array(array("protected static \$model_class = 'Note';" => "protected static \$model_class = 'Note';\n\tprotected static \$default_options = array('deleted' => false);"), '', 'MultiNote::$default_options is not available'),
		'a collection query'          => array(array("protected static \$model_class = 'Note';" => "protected static \$model_class = 'Note';\n\tprotected function getMultiResults(\$a = false, \$b = false) { return 1; }"), '', 'MultiNote::getMultiResults() would replace'),
		'a collection of another'     => array(array("protected static \$model_class = 'Note';" => "protected static \$model_class = 'User';"), '', "MultiNote::\$model_class is 'Note'"),
		'no collection'               => array(array("class MultiNote extends SystemMultiBase {\n\tprotected static \$model_class = 'Note';\n}" => ''), '', 'needs its collection'),
		'null on a NOT NULL column'   => array(array("'qzn_usr_user_id' => array('type' => 'int8', 'is_nullable' => true" => "'qzn_usr_user_id' => array('type' => 'int8', 'is_nullable' => false", "array('action' => 'cascade')" => "array('action' => 'null')"), '', 'a null rule needs a column that may be empty'),
		'set_value text on int8'      => array(array("array('action' => 'cascade')" => "array('action' => 'set_value', 'value' => 'abc')"), '', 'takes a whole number'),
		'set_value on a unique column' => array(array("'qzn_usr_user_id' => array('type' => 'int8', 'is_nullable' => true, 'index' => true)" => "'qzn_usr_user_id' => array('type' => 'int8', 'is_nullable' => true, 'unique' => true)", "array('action' => 'cascade')" => "array('action' => 'set_value', 'value' => 3)"), '', 'cannot be on a unique column'),
		'set_value past int8'         => array(array("'qzn_usr_user_id' => array('type' => 'int8'" => "'qzn_usr_user_id' => array('type' => 'int2'", "array('action' => 'cascade')" => "array('action' => 'set_value', 'value' => 70000)"), '', 'takes a whole number from -32768 to 32767'),
		'set_value past int4'         => array(array("'qzn_usr_user_id' => array('type' => 'int8'" => "'qzn_usr_user_id' => array('type' => 'int4'", "array('action' => 'cascade')" => "array('action' => 'set_value', 'value' => 3000000000)"), '', 'from -2147483648 to 2147483647'),
		'set_value past numeric'      => array(array("'qzn_usr_user_id' => array('type' => 'int8'" => "'qzn_usr_user_id' => array('type' => 'numeric(5,2)'", "array('action' => 'cascade')" => "array('action' => 'set_value', 'value' => 100000)"), '', 'at most 3 digits before the point'),
		'a second model'              => array(array('class MultiNote extends' => "class Other extends SystemBase {}\nclass MultiNote extends"), '', 'a second model class'),
		'a lower-case model'          => array(array('class Note ' => 'class note ', 'MultiNote' => 'Multinote'), '', 'must be a capital letter'),
	);
	foreach ($cases as $label => list($replace, $statics, $needle)) {
		$text = smf_file($replace);
		if ($statics !== '') {
			$text = preg_replace('~(class Note extends SystemBase \{\n)~', '$1' . str_replace('$', '\\$', $statics), $text, 1);
		}
		$problems = smf_check($text)['problems'];
		$found = false;
		foreach ($problems as $p) {
			$found = $found || (stripos($p, $needle) !== false && strpos($p, 'notes_class.php') === 0);
		}
		check($found, "Refused: $label", implode(' | ', $problems));
	}
	check(smf_check(smf_file(array("function prepare() {" => "function save(\$debug = false) { return parent::save(\$debug); }\n\tfunction prepare() {")))['problems'] === array(),
		'prepare() and save() may be its own');
	check(smf_check(smf_file(), 'Notes.php')['problems'] === array(), 'The checker takes any file name; the folder rule is files()');

	check(smf_check(smf_file(array("array('action' => 'cascade')" => "array('action' => 'null')")))['problems'] === array(),
		'A null rule on a nullable column is taken');

	section('The API\'s delete asks the delete rule, which asks the write rule first');
	// api/apiv1.php's DELETE asks authenticate_delete(), which drops the
	// return of authenticate_write(): no model may refuse a write by returning
	// false instead of throwing (reviewer1, B1).
	$base = PathHelper::getBasePath();
	$found = 0;
	$falsy = array();
	foreach (array_merge(glob($base . '/data/*.php') ?: array(), glob($base . '/plugins/*/data/*.php') ?: array(), glob($base . '/includes/*.php') ?: array()) as $path) {
		$tokens = PhpToken::tokenize((string)file_get_contents($path));
		$n = count($tokens);
		for ($i = 0; $i < $n; $i++) {
			if ($tokens[$i]->id !== T_FUNCTION) {
				continue;
			}
			$j = $i + 1;
			while ($j < $n && $tokens[$j]->id === T_WHITESPACE) {
				$j++;
			}
			if ($j >= $n || $tokens[$j]->text !== 'authenticate_write') {
				continue;
			}
			$found++;
			$depth = 0;
			for ($k = $j; $k < $n; $k++) {
				if ($tokens[$k]->id === ord('{')) {
					$depth++;
				} elseif ($tokens[$k]->id === ord('}')) {
					if (--$depth === 0) {
						break;
					}
				} elseif ($depth > 0 && $tokens[$k]->id === T_RETURN) {
					$m = $k + 1;
					while ($m < $n && $tokens[$m]->id === T_WHITESPACE) {
						$m++;
					}
					if ($m < $n && strtolower($tokens[$m]->text) === 'false') {
						$falsy[] = substr($path, strlen($base) + 1) . ':' . $tokens[$k]->line;
					}
				}
			}
		}
	}
	check($found > 20 && $falsy === array(), "No authenticate_write() of the $found refuses by returning false", implode(', ', $falsy));

	section('The folder: model files directly in src/data, named for their table');
	$dir = harness_scratch_dir('sbx_model_files');
	file_put_contents($dir . '/notes_class.php', smf_file());
	file_put_contents($dir . '/README.txt', 'not a model');
	$files = SandboxModelDeclarations::files($dir);
	check(array_keys($files['bytes'] ?? array()) === array('notes_class.php') && strlen($files['stats']['notes_class.php']['sha256']) === 64,
		'A *_class.php file is read and hashed; a file that is not PHP is left alone');
	file_put_contents($dir . '/Helper.php', "<?php\n");
	check(stripos(implode(' ', SandboxModelDeclarations::files($dir)['problems'] ?? array()), 'a helper class goes in src/includes') !== false,
		'A PHP file not named for a table is refused');
	unlink($dir . '/Helper.php');
	mkdir($dir . '/more');
	check(stripos(implode(' ', SandboxModelDeclarations::files($dir)['problems'] ?? array()), 'no folders or links') !== false,
		'A folder in src/data is refused');
} catch (Throwable $e) {
	check(false, 'Unexpected ' . get_class($e), $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
}

/** A second model, linked to Note by the naming convention. */
function sandbox_model_text_comment(): string {
	return <<<'PHP'
<?php
class Comment extends SystemBase {
	public static $prefix = 'qzc';
	public static $tablename = 'qzc_comments';
	public static $pkey_column = 'qzc_comment_id';
	public static $access_class = 'content';
	public static $field_specifications = array(
		'qzc_comment_id'  => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'qzc_qzn_note_id' => array('type' => 'int8'),
	);
	protected static $foreign_key_actions = array(
		'qzc_qzn_note_id' => array('action' => 'cascade'),
	);
}
class MultiComment extends SystemMultiBase {
	protected static $model_class = 'Comment';
}
PHP;
}

harness_finish();
