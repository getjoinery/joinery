<?php
/**
 * SandboxModelDeclarations — what a sandboxed plugin's model files declare,
 * held to what the sandbox can honour (spec sandboxed_plugin_model_files).
 *
 * A sandboxed plugin writes its models as regular model files in src/data/
 * (D1). SandboxModelFile reads each one in the parser jail and answers JSON;
 * this class runs on the site over that answer, which is data. It holds every
 * value to an allow-list and builds the declaration the site uses for the
 * model: its class, table, columns, delete rules and levels. Anything the
 * sandbox cannot honour is refused, naming the file, the class and the thing
 * (D2); a model that fails is refused whole, and so is the plugin.
 *
 * read() reads a plugin's src/data/ and answers
 *
 *   array('models' => array(Class => declaration), 'files' => array(file => array(sha256, size, mtime)))
 *
 * A declaration (also what plg_metadata._sandbox_models keeps):
 *
 *   file, class, multi, site_class (Sbx_{plugin}_{Class}), prefix, tablename (the author's),
 *   table (sbx_{plugin}__{tablename}), pkey_column, field_specifications,
 *   foreign_key_actions (column => rule, its source_table resolved, a prevent's message the platform's),
 *   access_class, read_permission, write_permission, delete_permission, permanent_delete_permission,
 *   owner_column (null: the convention; false: none; or a column), api_readable, api_unreadable_fields,
 *   methods (the model's own method names)
 *
 * @version 1.1 - a null or set_value rule must fit its column, its kind and its range (reviewer1 R1, R1b)
 * @version 1.0
 */

class SandboxModelException extends Exception {

	/** @var string[] every problem found, each naming its file */
	public $problems = array();

	public function __construct(array $problems) {
		$this->problems = array_values($problems);
		parent::__construct("The plugin's model files cannot be used:\n- " . implode("\n- ", $this->problems));
	}
}

class SandboxModelDeclarations {

	/** The folder of a plugin's model files, under src/. */
	const DATA_DIR = 'data';

	/** A model file's name, as natively. */
	const FILE_PATTERN = '~^[a-z][a-z0-9_]{0,60}_class\.php$~D';

	/** A model's prefix: three lowercase letters. */
	const PREFIX_PATTERN = '~^[a-z]{3}$~D';

	const MAX_MODELS = 20;
	const MAX_COLUMNS = 40;

	/** The longest name the database keeps whole. */
	const MAX_NAME = 63;

	/** Static properties a model file may declare. */
	const MODEL_STATICS = array('prefix', 'tablename', 'pkey_column', 'field_specifications', 'foreign_key_actions',
		'access_class', 'read_permission', 'write_permission', 'delete_permission', 'permanent_delete_permission',
		'owner_column', 'api_readable', 'api_writable', 'api_unreadable_fields');

	/** Static properties a model file must declare. */
	const REQUIRED_STATICS = array('prefix', 'tablename', 'pkey_column', 'field_specifications', 'access_class');

	/** The per-verb levels, by static. */
	const LEVEL_STATICS = array('read_permission', 'write_permission', 'delete_permission', 'permanent_delete_permission');

	/** Keys a column's specification may hold. */
	const FIELD_KEYS = array('type', 'is_nullable', 'required', 'default', 'serial', 'is_primary_key', 'unique',
		'unique_with', 'index', 'index_with', 'allowed_values', 'zero_on_create', 'stamp_on_save', 'insert_only', 'access_class');

	/** Field keys that are true or false. */
	const FLAG_KEYS = array('is_nullable', 'required', 'serial', 'is_primary_key', 'unique', 'index',
		'zero_on_create', 'stamp_on_save', 'insert_only');

	/** Keys a delete rule may hold. */
	const RULE_KEYS = array('action', 'value', 'source_class', 'source_table');

	/** A column's type, matched whole: it reaches SQL as written. */
	const TYPE_PATTERNS = array(
		'~^varchar\(([1-9][0-9]{0,3})\)$~D',
		'~^(text|int2|int4|int8|integer|bigint|smallint|bool|boolean|timestamp\(6\)|timestamp|date|json|jsonb)$~D',
		'~^numeric\(([1-9][0-9]?),([0-9][0-9]?)\)$~D',
	);

	/** What User::USER_DELETED reads as until a set_value takes it. */
	const DELETED_USER = "\0User::USER_DELETED";

	/**
	 * Read and check every model file of an installed or staged plugin.
	 * Each file is tokenized in the parser jail; nothing is run.
	 *
	 * @param string $title the plugin's title, for the message a prevent rule carries
	 * @throws SandboxModelException listing every problem
	 */
	public static function read(string $plugin, string $title): array {
		if (!preg_match(SandboxManifest::NAME_PATTERN, $plugin)) {
			throw new SandboxModelException(array("'" . substr($plugin, 0, 40) . "' is not a plugin name."));
		}
		return self::readDir($plugin, $title, SandboxPlugins::dirFor($plugin) . '/' . SandboxPlugins::SOURCE_DIR . '/' . self::DATA_DIR);
	}

	/**
	 * The same, for the model files in $dir: a plugin's src/data/, or a
	 * folder an author is checking before it is installed.
	 *
	 * @throws SandboxModelException listing every problem
	 */
	public static function readDir(string $plugin, string $title, string $dir): array {
		$files = self::files($dir);
		if (isset($files['problems'])) {
			throw new SandboxModelException($files['problems']);
		}
		if (!$files['bytes']) {
			return array('models' => array(), 'files' => array());
		}
		if (!DocumentText::jailAvailable() || !DocumentText::jailFenced()) {
			throw new SandboxModelException(array('This server has no parser jail, so it cannot read a sandboxed plugin\'s model files '
				. '(they are read in the jail, never in the site). Install the parser jail to use one.'));
		}
		$answers = array();
		foreach (DocumentText::parseWithMany('SandboxModelFile', $files['bytes']) as $file => $json) {
			$answers[$file] = is_string($json) ? json_decode($json, true) : null;
		}
		$checked = self::check($plugin, $title, $answers);
		if ($checked['problems']) {
			throw new SandboxModelException($checked['problems']);
		}
		return array('models' => $checked['models'], 'files' => $files['stats']);
	}

	/**
	 * The model files in a plugin's src/data/: their bytes and what the hash
	 * check compares, or the problems with the folder.
	 *
	 * @return array{bytes?:array<string,string>, stats?:array, problems?:string[]}
	 */
	public static function files(string $dir): array {
		if (!file_exists($dir)) {
			return array('bytes' => array(), 'stats' => array());
		}
		if (is_link($dir) || !is_dir($dir)) {
			return array('problems' => array('src/' . self::DATA_DIR . ' must be a folder, not a link.'));
		}
		$problems = array();
		$bytes = array();
		$stats = array();
		$entries = scandir($dir) ?: array();
		sort($entries);
		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			$path = $dir . '/' . $entry;
			if (is_link($path) || is_dir($path)) {
				$problems[] = 'src/' . self::DATA_DIR . '/' . self::show($entry) . ': model files sit directly in src/'
					. self::DATA_DIR . ' (no folders or links); helpers go in src/includes.';
				continue;
			}
			if (substr($entry, -4) !== '.php') {
				continue;
			}
			if (!preg_match(self::FILE_PATTERN, $entry)) {
				$problems[] = 'src/' . self::DATA_DIR . '/' . self::show($entry) . ': a model file is named for its table, '
					. 'notes_class.php; a helper class goes in src/includes.';
				continue;
			}
			$size = (int)filesize($path);
			if ($size > SandboxModelFile::MAX_BYTES) {
				$problems[] = $entry . ': larger than 256 KB.';
				continue;
			}
			$text = file_get_contents($path);
			if (!is_string($text)) {
				$problems[] = $entry . ': it cannot be read.';
				continue;
			}
			$bytes[$entry] = $text;
			$stats[$entry] = array('sha256' => hash('sha256', $text), 'size' => strlen($text), 'mtime' => (int)filemtime($path));
		}
		if (count($bytes) > self::MAX_MODELS) {
			$problems[] = 'A plugin may declare at most ' . self::MAX_MODELS . ' models.';
		}
		return $problems ? array('problems' => $problems) : array('bytes' => $bytes, 'stats' => $stats);
	}

	/**
	 * Hold the reader's answers to the allow-lists.
	 *
	 * @param array<string,?array> $answers file => SandboxModelFile's decoded answer (null: it could not be read)
	 * @return array{models:array, problems:string[]}
	 */
	public static function check(string $plugin, string $title, array $answers): array {
		$problems = array();
		$found = array();
		foreach ($answers as $file => $answer) {
			$file = (string)$file;
			if (!is_array($answer)) {
				$problems[] = "$file: the parser jail could not read it.";
				continue;
			}
			if (empty($answer['ok'])) {
				$problems[] = "$file " . (string)($answer['error'] ?? 'cannot be read.');
				continue;
			}
			$pair = self::pair($file, (array)($answer['classes'] ?? array()), $problems);
			if ($pair !== null) {
				if (isset($found[strtolower($pair[0]['name'])])) {
					$problems[] = "$file: the model {$pair[0]['name']} is declared in another file too.";
					continue;
				}
				$found[strtolower($pair[0]['name'])] = array($file, $pair[0], $pair[1]);
			}
		}

		// The plugin's own prefixes and tables first: a link between two of its
		// models resolves to its own table.
		$own_prefixes = array();
		$own_tables = array();
		$own_classes = array();
		foreach ($found as list($file, $model)) {
			$prefix = self::staticValue($model, 'prefix');
			$tablename = self::staticValue($model, 'tablename');
			if (is_string($prefix) && preg_match(self::PREFIX_PATTERN, $prefix)) {
				if (isset($own_prefixes[$prefix])) {
					$problems[] = "$file: {$model['name']}::\$prefix '$prefix' is another of this plugin's models' too; each model has its own.";
				}
				if (is_string($tablename)) {
					$own_prefixes[$prefix] = self::tableFor($plugin, $tablename);
				}
			}
			if (is_string($tablename)) {
				$own_tables[$tablename] = self::tableFor($plugin, $tablename);
				$own_classes[strtolower($model['name'])] = self::tableFor($plugin, $tablename);
			}
		}

		$models = array();
		foreach ($found as list($file, $model, $multi)) {
			$before = count($problems);
			$declaration = self::model($plugin, $title, $file, $model, $multi, $own_prefixes, $own_tables, $own_classes, $problems);
			if (count($problems) === $before) {
				$models[$model['name']] = $declaration;
			}
		}
		return array('models' => $problems ? array() : $models, 'problems' => $problems);
	}

	/** The model class and its Multi class of one file, or null when the pair is wrong. */
	private static function pair(string $file, array $classes, array &$problems): ?array {
		$model = null;
		$multi = array();
		foreach ($classes as $class) {
			if (($class['extends'] ?? '') === 'SystemBase') {
				if ($model !== null) {
					$problems[] = "$file line {$class['line']}: a second model class ({$class['name']}); one model a file.";
					return null;
				}
				$model = $class;
			} else {
				$multi[] = $class;
			}
		}
		if ($model === null) {
			$problems[] = "$file: it declares no class that extends SystemBase.";
			return null;
		}
		$name = (string)$model['name'];
		if (!preg_match(SandboxManifest::MODEL_PATTERN, $name)) {
			$problems[] = "$file line {$model['line']}: the model's name '" . self::show($name) . "' must be a capital letter, then letters and digits (at most 20).";
			return null;
		}
		if (count($multi) !== 1 || strcasecmp($multi[0]['name'], 'Multi' . $name) !== 0 || $multi[0]['name'] !== 'Multi' . $name) {
			$problems[] = "$file: the model $name needs its collection, written class Multi$name extends SystemMultiBase "
				. "{ protected static \$model_class = '$name'; }, and no other class.";
			return null;
		}
		return array($model, $multi[0]);
	}

	private static function model(string $plugin, string $title, string $file, array $model, array $multi,
			array $own_prefixes, array $own_tables, array $own_classes, array &$problems): array {
		$name = $model['name'];
		$where = function (?int $line) use ($file) {
			return $line ? "$file line $line" : $file;
		};
		$lineOf = function (string $static) use ($model) {
			return isset($model['statics'][$static]['line']) ? (int)$model['statics'][$static]['line'] : null;
		};

		// The statics: each allowed one, and every other refused by name.
		$values = array();
		foreach ((array)$model['statics'] as $static => $entry) {
			$value = self::decode($entry['value'] ?? array('t' => 'n'));
			$at = $where((int)($entry['line'] ?? 0));
			if ($static === 'api_writable') {
				if ($value !== false) {
					$problems[] = "$at: only the plugin's own code writes $name; set $name::\$api_writable to false or leave it out, "
						. 'and give outsiders an action of the plugin\'s instead.';
				}
				continue;
			}
			if ($static === 'api_unwritable_fields' || $static === 'api_create_only_fields') {
				$problems[] = "$at: $name::\$$static has nothing to act on: only the plugin's own code writes its models.";
				continue;
			}
			if (!in_array($static, self::MODEL_STATICS, true)) {
				$problems[] = "$at: $name::\$$static is not available to a sandboxed plugin.";
				continue;
			}
			$values[$static] = $value;
		}
		foreach (self::REQUIRED_STATICS as $static) {
			if (!array_key_exists($static, $values) && !isset($model['statics'][$static])) {
				$problems[] = "$file: $name needs \$$static.";
			}
		}
		if (self::holdsMark($values, array('foreign_key_actions'))) {
			$problems[] = "$file: User::USER_DELETED is read only as a delete rule's set_value.";
		}

		$prefix = $values['prefix'] ?? null;
		if (array_key_exists('prefix', $values) && (!is_string($prefix) || !preg_match(self::PREFIX_PATTERN, $prefix))) {
			$problems[] = $where($lineOf('prefix')) . ": $name::\$prefix is three lowercase letters.";
			$prefix = null;
		}
		if (is_string($prefix) && isset(DeletionRule::modelPrefixes()[$prefix])) {
			$problems[] = $where($lineOf('prefix')) . ": $name::\$prefix '$prefix' is taken by a model of the site's ("
				. implode(', ', DeletionRule::modelPrefixes()[$prefix]) . '); choose another.';
		}
		$tablename = $values['tablename'] ?? null;
		$table = null;
		if (array_key_exists('tablename', $values)) {
			if (!is_string($tablename) || $prefix === null || !preg_match('~^' . $prefix . '_[a-z0-9_]+$~D', $tablename) || strpos($tablename, '__') !== false) {
				$problems[] = $where($lineOf('tablename')) . ": $name::\$tablename starts with its prefix and an underscore, then a-z, 0-9 and single _ (nte_notes).";
			} else {
				$table = self::tableFor($plugin, $tablename);
			}
		}

		// Columns.
		$specs = array();
		$raw_specs = $values['field_specifications'] ?? null;
		if (array_key_exists('field_specifications', $values)) {
			if (!is_array($raw_specs) || !$raw_specs || array_is_list($raw_specs)) {
				$problems[] = $where($lineOf('field_specifications')) . ": $name::\$field_specifications maps each column to its specification.";
				$raw_specs = array();
			} elseif (count($raw_specs) > self::MAX_COLUMNS) {
				$problems[] = $where($lineOf('field_specifications')) . ": $name has more than " . self::MAX_COLUMNS . ' columns.';
			}
		} else {
			$raw_specs = array();
		}
		$pkey = $values['pkey_column'] ?? null;
		if (array_key_exists('pkey_column', $values) && (!is_string($pkey) || !isset($raw_specs[$pkey]))) {
			$problems[] = $where($lineOf('pkey_column')) . ": $name::\$pkey_column names one of its columns.";
			$pkey = null;
		}
		$at_specs = $where($lineOf('field_specifications'));
		foreach ($raw_specs as $column => $spec) {
			$column = (string)$column;
			$label = "$at_specs: $name column '" . self::show($column) . "'";
			if ($prefix !== null && (!preg_match('~^' . $prefix . '_[a-z0-9_]+$~D', $column) || strlen($column) > self::MAX_NAME)) {
				$problems[] = "$label: a column is named for its model's prefix, $prefix" . '_ then a-z, 0-9 and _, at most ' . self::MAX_NAME . ' characters.';
				continue;
			}
			if (!is_array($spec) || array_is_list($spec) && $spec !== array()) {
				$problems[] = "$label: its specification is an array of keys.";
				continue;
			}
			$specs[$column] = self::field($label, $column, $spec, $column === $pkey, array_keys($raw_specs), $problems);
		}
		if ($pkey !== null && isset($specs[$pkey])) {
			$key = $specs[$pkey];
			if (($key['type'] ?? '') !== 'int8' || empty($key['serial'])) {
				$problems[] = "$at_specs: $name's key column $pkey is declared 'type' => 'int8', 'serial' => true.";
			}
		}
		if ($table !== null && $pkey !== null) {
			foreach (array($table, $table . '_' . $pkey . '_seq') as $db_name) {
				if (strlen($db_name) > self::MAX_NAME) {
					$problems[] = "$file: $name's table needs the name $db_name, longer than the database keeps ("
						. self::MAX_NAME . ' characters); shorten the plugin\'s name or the table\'s.';
					break;
				}
			}
		}

		// Who may read and write.
		$access_class = $values['access_class'] ?? null;
		if (array_key_exists('access_class', $values) && (!is_string($access_class) || !in_array($access_class, Access::classes(), true))) {
			$problems[] = $where($lineOf('access_class')) . ": $name::\$access_class is one of " . implode(', ', Access::classes()) . '.';
		}
		$levels = array();
		foreach (self::LEVEL_STATICS as $static) {
			$level = $values[$static] ?? null;
			if ($level !== null && (!is_int($level) || $level < 0 || $level > 10)) {
				$problems[] = $where($lineOf($static)) . ": $name::\$$static is a whole number from 0 to 10.";
				$level = null;
			}
			$levels[$static] = $level;
		}
		$owner = array_key_exists('owner_column', $values) ? $values['owner_column'] : null;
		if ($owner !== null && $owner !== false && (!is_string($owner) || ($specs[$owner]['type'] ?? '') !== 'int8')) {
			$problems[] = $where($lineOf('owner_column')) . ": $name::\$owner_column is false or one of its int8 columns.";
			$owner = null;
		}
		$api_readable = $values['api_readable'] ?? false;
		if (!is_bool($api_readable)) {
			$problems[] = $where($lineOf('api_readable')) . ": $name::\$api_readable is true or false.";
			$api_readable = false;
		}
		$unreadable = $values['api_unreadable_fields'] ?? array();
		if (!is_array($unreadable) || !array_is_list($unreadable) || array_diff($unreadable, array_keys($specs))) {
			$problems[] = $where($lineOf('api_unreadable_fields')) . ": $name::\$api_unreadable_fields lists its own columns.";
			$unreadable = array();
		}

		$rules = self::rules($plugin, $title, $name, $where($lineOf('foreign_key_actions')), $values['foreign_key_actions'] ?? array(),
			$specs, $prefix, $pkey, $own_prefixes, $own_tables, $own_classes, $problems);

		// Methods: the author's own, run only in the fence. One that would
		// replace SystemBase's would be skipped by the site, which calls its own.
		$methods = array();
		foreach ((array)$model['methods'] as $method => $line) {
			$method = (string)$method;
			if (method_exists('SystemBase', $method) && !in_array(strtolower($method), array('prepare', 'save'), true)) {
				$problems[] = $where((int)$line) . ": $name::$method() would replace the site's own; a sandboxed model may write only prepare() and save() of those.";
				continue;
			}
			$methods[] = $method;
		}
		foreach ((array)$model['members'] as $member => $line) {
			if (property_exists('SystemBase', (string)$member)) {
				$problems[] = $where((int)$line) . ": $name::\$$member would replace the site's own; name it something else.";
			}
		}
		foreach ((array)$multi['statics'] as $static => $entry) {
			$at = $where((int)($entry['line'] ?? 0));
			if ($static === 'model_class') {
				if (self::decode($entry['value'] ?? array()) !== $name) {
					$problems[] = "$at: Multi$name::\$model_class is '$name'.";
				}
				continue;
			}
			$problems[] = "$at: Multi$name::\$$static is not available to a sandboxed plugin; its collection holds only \$model_class "
				. '(the site lists its rows with the built-in query, by column).';
		}
		if (!isset($multi['statics']['model_class'])) {
			$problems[] = "$file: Multi$name needs protected static \$model_class = '$name';";
		}
		foreach ((array)$multi['methods'] as $method => $line) {
			if (method_exists('SystemMultiBase', (string)$method)) {
				$problems[] = $where((int)$line) . ": Multi$name::$method() would replace the site's own collection method; "
					. 'a sandboxed collection lists by column with the built-in query.';
			}
		}

		return array(
			'file'                 => $file,
			'class'                => $name,
			'multi'                => 'Multi' . $name,
			'site_class'           => SandboxManifest::classFor($plugin, $name),
			'prefix'               => $prefix,
			'tablename'            => $tablename,
			'table'                => $table,
			'pkey_column'          => $pkey,
			'field_specifications' => $specs,
			'foreign_key_actions'  => $rules,
			'access_class'         => $access_class,
			'read_permission'      => $levels['read_permission'],
			'write_permission'     => $levels['write_permission'],
			'delete_permission'    => $levels['delete_permission'],
			'permanent_delete_permission' => $levels['permanent_delete_permission'],
			'owner_column'         => $owner,
			'api_readable'         => $api_readable,
			'api_unreadable_fields' => array_values($unreadable),
			'methods'              => $methods,
		);
	}

	/** One column's specification, checked key by key. */
	private static function field(string $label, string $column, array $spec, bool $is_key, array $columns, array &$problems): array {
		$out = array();
		foreach ($spec as $key => $value) {
			$key = (string)$key;
			if ($key === 'foreign_key') {
				$problems[] = "$label: 'foreign_key' makes a real database constraint, which a sandboxed plugin's table does not have; "
					. 'declare the link in $foreign_key_actions.';
				continue;
			}
			if (!in_array($key, self::FIELD_KEYS, true)) {
				$problems[] = "$label: '" . self::show($key) . "' is not available to a sandboxed plugin's column.";
				continue;
			}
			if (in_array($key, self::FLAG_KEYS, true) && !is_bool($value)) {
				$problems[] = "$label: '$key' is true or false.";
				continue;
			}
			if (($key === 'serial' || $key === 'is_primary_key') && $value && !$is_key) {
				$problems[] = "$label: '$key' belongs to the key column only.";
				continue;
			}
			$out[$key] = $value;
		}
		$type = $out['type'] ?? null;
		if (!is_string($type) || !self::typeAllowed($type)) {
			$problems[] = "$label: 'type' is one of varchar(1..2000), text, int2, int4, int8, integer, bigint, smallint, "
				. 'numeric(p,s) up to (30,10), bool, boolean, timestamp(6), timestamp, date, json, jsonb.';
			$type = '';
		}
		if (array_key_exists('default', $out)) {
			$default = $out['default'];
			if (is_string($default) && (preg_match('/^[A-Za-z_]+\(\)$/', $default) || strtoupper($default) === 'CURRENT_TIMESTAMP')) {
				// Written into SQL as it stands: only now(), on a time column.
				if ($default !== 'now()' || strpos($type, 'timestamp') !== 0) {
					$problems[] = "$label: a default of '" . self::show($default) . "' is SQL; the only one allowed is 'now()', on a timestamp column.";
				}
			} elseif (!is_string($default) && !is_int($default) && !is_float($default) && !is_bool($default)) {
				$problems[] = "$label: 'default' is a number, true or false, or a string.";
			} elseif (is_string($default) && mb_strlen($default) > 2000) {
				$problems[] = "$label: 'default' is at most 2000 characters.";
			}
		}
		foreach (array('unique_with', 'index_with') as $key) {
			if (!array_key_exists($key, $out)) {
				continue;
			}
			$list = $out[$key];
			$ok = is_array($list) && $list !== array() && array_is_list($list);
			foreach ($ok ? $list : array() as $member) {
				if (!is_string($member) || !in_array($member, $columns, true) || $member === $column) {
					$ok = false;
				}
			}
			if (!$ok) {
				$problems[] = "$label: '$key' is a list of the model's other columns.";
			}
		}
		if (array_key_exists('allowed_values', $out)) {
			$list = $out['allowed_values'];
			$ok = is_array($list) && $list !== array() && array_is_list($list) && count($list) <= 50;
			foreach ($ok ? $list : array() as $member) {
				if (!(is_int($member) || is_float($member) || (is_string($member) && mb_strlen($member) <= 100))) {
					$ok = false;
				}
			}
			if (!$ok) {
				$problems[] = "$label: 'allowed_values' is a list of at most 50 numbers or strings of at most 100 characters.";
			}
		}
		if (array_key_exists('access_class', $out) && (!is_string($out['access_class']) || !in_array($out['access_class'], Access::classes(), true))) {
			$problems[] = "$label: 'access_class' is one of " . implode(', ', Access::classes()) . '.';
		}
		return $out;
	}

	/** Whether a column type is one the sandbox allows, read whole. */
	public static function typeAllowed(string $type): bool {
		if (preg_match(self::TYPE_PATTERNS[0], $type, $m)) {
			return (int)$m[1] >= 1 && (int)$m[1] <= 2000;
		}
		if (preg_match(self::TYPE_PATTERNS[1], $type)) {
			return true;
		}
		if (preg_match(self::TYPE_PATTERNS[2], $type, $m)) {
			return (int)$m[1] <= 30 && (int)$m[2] <= 10 && (int)$m[2] <= (int)$m[1];
		}
		return false;
	}

	/**
	 * The model's delete rules, each with its source table resolved as the
	 * native registration would, the plugin's own tables first. A link-shaped
	 * column with no rule is refused, never registered as a guess.
	 */
	private static function rules(string $plugin, string $title, string $name, string $at, $declared, array $specs,
			?string $prefix, ?string $pkey, array $own_prefixes, array $own_tables, array $own_classes, array &$problems): array {
		if (!is_array($declared) || ($declared !== array() && array_is_list($declared))) {
			$problems[] = "$at: $name::\$foreign_key_actions maps each linking column to its rule.";
			return array();
		}
		$out = array();
		foreach ($declared as $column => $rule) {
			$column = (string)$column;
			$label = "$at: $name::\$foreign_key_actions['" . self::show($column) . "']";
			if (!isset($specs[$column]) || $column === $pkey) {
				$problems[] = "$label: a delete rule is for one of $name's linking columns.";
				continue;
			}
			if (!is_array($rule) || array_is_list($rule)) {
				$problems[] = "$label: a rule is an array with an 'action'.";
				continue;
			}
			foreach (array_keys($rule) as $key) {
				if ($key === 'message') {
					$problems[] = "$label: a rule's message is the platform's own, written for the admin who meets it; leave it out.";
				} elseif ($key === 'plugin') {
					$problems[] = "$label: 'plugin' is set by the platform; leave it out.";
				} elseif (!in_array($key, self::RULE_KEYS, true)) {
					$problems[] = "$label: '" . self::show($key) . "' is not part of a delete rule (action, value, source_class, source_table).";
				}
			}
			$action = $rule['action'] ?? null;
			if (!is_string($action) || !in_array($action, SystemBase::$valid_deletion_actions, true)) {
				$problems[] = "$label: 'action' is one of " . implode(', ', SystemBase::$valid_deletion_actions) . '.';
				continue;
			}
			$resolved = array('action' => $action);
			if ($action === 'set_value') {
				$value = $rule['value'] ?? null;
				if ($value === self::DELETED_USER) {
					$resolved['value'] = User::USER_DELETED;
				} elseif (is_int($value) || is_float($value) || (is_string($value) && mb_strlen($value) <= 2000)) {
					$resolved['value'] = $value;
				} else {
					$problems[] = "$label: a set_value rule's 'value' is a number, a string or User::USER_DELETED.";
					continue;
				}
			} elseif (array_key_exists('value', $rule)) {
				$problems[] = "$label: 'value' is for a set_value rule.";
				continue;
			}
			// A rule the database would refuse fails every delete of the
			// record it links to: a prevent in effect, unapproved.
			$fit = self::ruleFits($column, $specs, $resolved);
			if ($fit !== null) {
				$problems[] = "$label: $fit";
				continue;
			}
			$source = self::source($plugin, $column, $rule, $prefix, $own_prefixes, $own_tables, $own_classes, $why);
			if ($source === null) {
				$problems[] = "$label: $why";
				continue;
			}
			$resolved['source_table'] = $source;
			if ($action === 'prevent') {
				$resolved['message'] = 'The plugin ' . $title . ' holds ' . self::words($name) . ' records for this.';
			}
			$out[$column] = $resolved;
		}
		// A column that reads as a link by its name and has no rule.
		foreach (array_keys($specs) as $column) {
			if ($column === $pkey || isset($declared[$column]) || $prefix === null) {
				continue;
			}
			$source = self::conventionSource($column, $prefix, $own_prefixes);
			if ($source !== null) {
				$problems[] = "$at: $name's column $column reads as a link to $source; say in $name::\$foreign_key_actions "
					. "what happens to the row when that record is deleted (cascade, null, set_value or prevent), or name the column for what it holds.";
			}
		}
		return $out;
	}

	/**
	 * Why a null or set_value rule would fail on its column when the engine
	 * runs it (an UPDATE of every linked row), or null when it fits: null
	 * only on a nullable column; a set_value value of the column's kind and
	 * size, never on a unique column (every linked row would take it).
	 */
	private static function ruleFits(string $column, array $specs, array $rule): ?string {
		$spec = $specs[$column] ?? array();
		$type = strtolower((string)($spec['type'] ?? ''));
		if ($rule['action'] === 'null') {
			return ($spec['is_nullable'] ?? true) === false ? "a null rule needs a column that may be empty; $column is declared 'is_nullable' => false." : null;
		}
		if ($rule['action'] !== 'set_value') {
			return null;
		}
		$unique = !empty($spec['unique']) || isset($spec['unique_with']);
		foreach ($specs as $other => $other_spec) {
			if (in_array($column, (array)($other_spec['unique_with'] ?? array()), true)) {
				$unique = true;
			}
		}
		if ($unique) {
			return "a set_value rule cannot be on a unique column: every linked row would take the same value.";
		}
		$value = $rule['value'];
		// Of the column's range too: one outside it is refused by the
		// database when the delete runs (reviewer1 R1b).
		$ranges = array('int2' => 32767, 'smallint' => 32767, 'int4' => 2147483647, 'integer' => 2147483647, 'int8' => PHP_INT_MAX, 'bigint' => PHP_INT_MAX);
		if (isset($ranges[$type])) {
			$max = $ranges[$type];
			return is_int($value) && $value <= $max && $value >= -$max - 1 ? null
				: "a set_value on the integer column $column ($type) takes a whole number from " . (-$max - 1) . " to $max.";
		}
		if (preg_match('~^numeric\(([0-9]+),([0-9]+)\)$~', $type, $m)) {
			$before = (int)$m[1] - (int)$m[2];
			return (is_int($value) || is_float($value)) && abs(round((float)$value, (int)$m[2])) < pow(10, $before) ? null
				: "a set_value on the number column $column ($type) takes a number with at most $before digits before the point.";
		}
		if (preg_match('~^varchar\(([0-9]+)\)$~', $type, $m)) {
			return is_string($value) && mb_strlen($value) <= (int)$m[1] ? null : "a set_value on $column takes text of at most {$m[1]} characters.";
		}
		if ($type === 'text') {
			return is_string($value) ? null : "a set_value on the text column $column takes text.";
		}
		return "a set_value rule is for a number or text column, not $column ($type).";
	}

	/** The table a rule's column links to, or null with $why. */
	private static function source(string $plugin, string $column, array $rule, ?string $prefix, array $own_prefixes,
			array $own_tables, array $own_classes, ?string &$why): ?string {
		$why = null;
		$sandboxed = '~^sbx_~';
		if (isset($rule['source_table'])) {
			$table = $rule['source_table'];
			if (!is_string($table)) {
				$why = "'source_table' is a table's name.";
				return null;
			}
			if (isset($own_tables[$table])) {
				return $own_tables[$table];
			}
			if (preg_match($sandboxed, $table)) {
				$why = 'a sandboxed plugin links only to its own tables and the site\'s, not another plugin\'s.';
				return null;
			}
			if (!isset(DeletionRule::knownTables()[$table])) {
				$why = "no model of this site has the table '" . self::show($table) . "'.";
				return null;
			}
			return $table;
		}
		if (isset($rule['source_class'])) {
			$class = $rule['source_class'];
			if (!is_string($class) || !preg_match('~^[A-Za-z][A-Za-z0-9_]{0,79}$~D', $class)) {
				$why = "'source_class' is a model class's name.";
				return null;
			}
			if (isset($own_classes[strtolower($class)])) {
				return $own_classes[strtolower($class)];
			}
			if (!class_exists($class) || !is_subclass_of($class, 'SystemBase') || empty($class::$tablename)) {
				$why = "'" . $class . "' is not a model of this site.";
				return null;
			}
			if (is_subclass_of($class, 'SandboxRecord')) {
				$why = 'a sandboxed plugin links only to its own models and the site\'s, not another plugin\'s.';
				return null;
			}
			return (string)$class::$tablename;
		}
		if ($prefix === null) {
			$why = 'it cannot be resolved without the model\'s prefix.';
			return null;
		}
		$source = self::conventionSource($column, $prefix, $own_prefixes);
		if ($source === null) {
			$why = "its name does not say what it links to ({prefix}_{their prefix}_{entity}_id, as $prefix" . '_usr_user_id); name the table with \'source_table\'.';
		}
		return $source;
	}

	/** By the native naming convention, the plugin's own prefixes first, then the site's models'. */
	private static function conventionSource(string $column, string $prefix, array $own_prefixes): ?string {
		$rest = substr($column, strlen($prefix) + 1);
		if (strpos($rest, '_id') === false) {
			return null;
		}
		$first = strstr($rest, '_', true);
		if ($first !== false && isset($own_prefixes[$first])) {
			return $own_prefixes[$first];
		}
		return DeletionRule::getSourceTableFromColumn($column, $prefix);
	}

	/** The real table of a model: sbx_{plugin}__{its tablename}. */
	public static function tableFor(string $plugin, string $tablename): string {
		return 'sbx_' . $plugin . '__' . $tablename;
	}

	/** 'EventNote' => 'event note'. */
	public static function words(string $class): string {
		return strtolower(preg_replace('~(?<!^)[A-Z]~', ' $0', $class));
	}

	/** A reader value as PHP: typed, so an int key stays an int. */
	public static function decode($value) {
		if (!is_array($value)) {
			return null;
		}
		switch ($value['t'] ?? null) {
			case 's': return (string)$value['v'];
			case 'i': return (int)$value['v'];
			case 'f': return (float)$value['v'];
			case 'b': return (bool)$value['v'];
			case 'c': return ($value['v'] ?? '') === 'User::USER_DELETED' ? self::DELETED_USER : null;
			case 'a':
				$out = array();
				foreach ((array)($value['v'] ?? array()) as $pair) {
					$item = self::decode($pair[1] ?? null);
					if (($pair[0] ?? null) === null) {
						$out[] = $item;
					} else {
						$out[self::decode($pair[0])] = $item;
					}
				}
				return $out;
		}
		return null;
	}

	private static function staticValue(array $class, string $static) {
		return isset($class['statics'][$static]) ? self::decode($class['statics'][$static]['value'] ?? null) : null;
	}

	/** Whether User::USER_DELETED appears anywhere but the statics named. */
	private static function holdsMark(array $values, array $except): bool {
		foreach ($values as $static => $value) {
			if (in_array($static, $except, true)) {
				continue;
			}
			$found = false;
			$walk = function ($v) use (&$walk, &$found) {
				if ($v === self::DELETED_USER) {
					$found = true;
				} elseif (is_array($v)) {
					foreach ($v as $member) {
						$walk($member);
					}
				}
			};
			$walk($value);
			if ($found) {
				return true;
			}
		}
		return false;
	}

	/** A value for a message, cut short and stripped of control characters. */
	private static function show($v): string {
		$s = is_scalar($v) ? (string)$v : gettype($v);
		return mb_substr((string)preg_replace('~[\x00-\x1F\x7F]~u', '?', $s), 0, 60);
	}
}
