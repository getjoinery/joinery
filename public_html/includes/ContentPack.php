<?php
/**
 * Content packs: a file that says "make sure these records look like this",
 * checked against the site and applied through the site's own models.
 *
 * A pack is data. It names models from a fixed list (the models that declare
 * $content_pack_keys), fields those models open to packs, declared settings,
 * and text files inside its own zip. Nothing in it is run, and nothing in it
 * is extracted.
 *
 *   $pack = ContentPack::read($zip_bytes);   // validates, or throws ContentPackRefusal
 *   $plan = $pack->plan();                   // reads only
 *   $result = $pack->apply($apply_record, $plan['hash'], $ticks, $session);
 *
 * See specs/content_pack_feature.md.
 *
 * @version 1.0
 */

/** A pack refused, with the reason a person can act on. Nothing was written. */
class ContentPackRefusal extends SystemBaseException implements DisplayableErrorMessage {}

class ContentPack {

	const MAX_ZIP_BYTES = 2097152;
	const MAX_MEMBER_BYTES = 2097152;

	const PACK_KEYS = array('pack', 'version', 'description', 'requires', 'notes', 'entries');
	const REQUIRES_KEYS = array('plugins');
	const RECORD_KEYS = array('id', 'model', 'match', 'match_also', 'set', 'on_create');
	const UNLISTED_KEYS = array('model', 'unlisted');
	const UNLISTED_INNER_KEYS = array('where', 'set');
	const SETTING_KEYS = array('setting', 'value');

	/** Field states a plan line can be in. */
	const STATE_NEW = 'new';
	const STATE_CHANGE = 'change';
	const STATE_SAME = 'same';
	const STATE_EDITED = 'edited';

	private $name;
	private $version;
	private $description;
	private $notes;
	/** Validated entries, file and ref values still as written. */
	private $entries = array();
	/** Record entry indexes, each after every record it refers to. */
	private $record_order = array();
	/** Record entry id => entry index. */
	private $ids = array();
	/** File name => contents. */
	private $files = array();
	/** Model name => class, the models a pack may name on this site. */
	private $models = array();

	private function __construct() {}

	// ---------------------------------------------------------------------
	// Reading
	// ---------------------------------------------------------------------

	/**
	 * Read and validate a pack from its zip bytes.
	 *
	 * @throws ContentPackRefusal naming what is wrong.
	 */
	public static function read(string $zip_bytes): ContentPack {
		if ($zip_bytes === '') {
			throw new ContentPackRefusal('The file is empty.');
		}
		if (strlen($zip_bytes) > self::MAX_ZIP_BYTES) {
			throw new ContentPackRefusal('A content pack is at most 2 MB; this file is ' . strlen($zip_bytes) . ' bytes.');
		}

		return self::from_members(self::zip_members($zip_bytes));
	}

	/**
	 * Read and validate a pack from a folder on this server: a plugin's own
	 * pack (plugins/{name}/content_packs/{pack}/). The folder holds pack.json
	 * and the text files it names, as a zip would.
	 *
	 * @throws ContentPackRefusal naming what is wrong.
	 */
	public static function read_dir(string $dir): ContentPack {
		return self::from_members(self::dir_members($dir));
	}

	/** Name => contents of each file in a pack folder, checked as a zip's members are. */
	public static function dir_members(string $dir): array {
		if (!is_dir($dir)) {
			throw new ContentPackRefusal('There is no pack folder at ' . $dir . '.');
		}
		$members = array();
		$total = 0;
		foreach (scandir($dir) as $name) {
			if ($name === '.' || $name === '..' || $name === '.DS_Store') {
				continue;
			}
			$path = $dir . '/' . $name;
			if (!is_file($path)) {
				throw new ContentPackRefusal('"' . $name . '" in ' . $dir . ' is not a file. A pack is pack.json and the text files it names, side by side, with no folders.');
			}
			$size = filesize($path);
			$total += $size;
			if ($size > self::MAX_MEMBER_BYTES || $total > self::MAX_ZIP_BYTES) {
				throw new ContentPackRefusal('The pack in ' . $dir . ' is larger than a pack may be (2 MB).');
			}
			$contents = (string)file_get_contents($path);
			if (!mb_check_encoding($contents, 'UTF-8')) {
				throw new ContentPackRefusal('"' . $name . '" is not UTF-8 text.');
			}
			$members[$name] = $contents;
		}
		ksort($members);
		return $members;
	}

	/** A pack from its files by name: pack.json and the text files it names. */
	private static function from_members(array $members): ContentPack {
		$pack = new ContentPack();
		if (!isset($members['pack.json'])) {
			throw new ContentPackRefusal('The pack holds no pack.json.');
		}
		$manifest = json_decode($members['pack.json'], true);
		if (!is_array($manifest) || array_is_list($manifest)) {
			throw new ContentPackRefusal('pack.json is not a JSON object: ' . json_last_error_msg() . '.');
		}
		unset($members['pack.json']);
		$pack->files = $members;
		$pack->models = self::pack_models();
		$pack->validate($manifest);
		return $pack;
	}

	/**
	 * Name => contents of each file in the zip. A zip of one folder is read as
	 * that folder's contents, which is what zipping a pack's folder makes.
	 */
	private static function zip_members(string $zip_bytes): array {
		$path = tempnam(sys_get_temp_dir(), 'cpk');
		try {
			file_put_contents($path, $zip_bytes);
			$zip = new ZipArchive();
			if ($zip->open($path, ZipArchive::RDONLY) !== true) {
				throw new ContentPackRefusal('The file is not a zip.');
			}
			$stats = array();
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$stat = $zip->statIndex($i);
				if ($stat === false) {
					throw new ContentPackRefusal('The zip cannot be read.');
				}
				if (substr($stat['name'], -1) === '/') {
					continue;   // a folder entry
				}
				// What a Mac's own zipping adds beside the files: never pack content.
				if (strpos($stat['name'], '__MACOSX/') === 0 || basename($stat['name']) === '.DS_Store') {
					continue;
				}
				$stats[] = $stat;
			}

			$names = array_map(function ($s) { return $s['name']; }, $stats);
			$prefix = '';
			if (!in_array('pack.json', $names, true)) {
				$tops = array_unique(array_map(function ($n) { return explode('/', $n, 2)[0]; }, $names));
				if (count($tops) === 1 && in_array(reset($tops) . '/pack.json', $names, true)) {
					$prefix = reset($tops) . '/';
				}
			}

			$members = array();
			foreach ($stats as $stat) {
				$name = $stat['name'];
				if ($prefix !== '') {
					$name = substr($name, strlen($prefix));
				}
				if ($name === '' || strpos($name, '/') !== false || strpos($name, '\\') !== false || strpos($name, '..') !== false) {
					throw new ContentPackRefusal('The zip holds "' . $stat['name'] . '". A pack is pack.json and the text files it names, side by side, with no folders.');
				}
				if ($stat['size'] > self::MAX_MEMBER_BYTES) {
					throw new ContentPackRefusal('"' . $name . '" is ' . $stat['size'] . ' bytes unpacked; each file in a pack is at most 2 MB.');
				}
				$contents = $zip->getFromIndex($stat['index']);
				if ($contents === false || strlen($contents) > self::MAX_MEMBER_BYTES) {
					throw new ContentPackRefusal('"' . $name . '" cannot be read.');
				}
				if (!mb_check_encoding($contents, 'UTF-8')) {
					throw new ContentPackRefusal('"' . $name . '" is not UTF-8 text.');
				}
				$members[$name] = $contents;
			}
			$zip->close();
			return $members;
		} finally {
			@unlink($path);
		}
	}

	/** Model name => class for every active model that opens itself to packs. */
	public static function pack_models(): array {
		$models = array();
		$classes = LibraryFunctions::discover_model_classes(array('include_plugins' => true, 'plugin_status' => 'active'));
		foreach ($classes as $class) {
			if (class_exists($class) && property_exists($class, 'content_pack_keys') && !empty($class::$content_pack_keys)) {
				$models[$class] = $class;
			}
		}
		return $models;
	}

	private function validate(array $manifest): void {
		self::refuse_unknown_keys($manifest, self::PACK_KEYS, 'pack.json');

		$name = $manifest['pack'] ?? null;
		if (!is_string($name) || trim($name) === '' || strlen($name) > 100) {
			throw new ContentPackRefusal('pack.json needs "pack": the pack\'s name, at most 100 characters.');
		}
		$this->name = trim($name);
		foreach (array('version' => 50, 'description' => 1000, 'notes' => 5000) as $key => $max) {
			if (isset($manifest[$key]) && (!is_string($manifest[$key]) || strlen($manifest[$key]) > $max)) {
				throw new ContentPackRefusal('"' . $key . '" must be text of at most ' . $max . ' characters.');
			}
		}
		$this->version = (string)($manifest['version'] ?? '');
		$this->description = (string)($manifest['description'] ?? '');
		$this->notes = (string)($manifest['notes'] ?? '');

		if (isset($manifest['requires'])) {
			if (!is_array($manifest['requires']) || array_is_list($manifest['requires'])) {
				throw new ContentPackRefusal('"requires" must be an object.');
			}
			self::refuse_unknown_keys($manifest['requires'], self::REQUIRES_KEYS, 'requires');
			$plugins = $manifest['requires']['plugins'] ?? array();
			if (!is_array($plugins) || !array_is_list($plugins)) {
				throw new ContentPackRefusal('"requires.plugins" must be a list of plugin names.');
			}
			foreach ($plugins as $plugin) {
				if (!is_string($plugin) || !PluginHelper::isPluginActive($plugin)) {
					throw new ContentPackRefusal('This pack needs the ' . json_encode($plugin) . ' plugin, which is not active on this site.');
				}
			}
		}

		$entries = $manifest['entries'] ?? null;
		if (!is_array($entries) || !array_is_list($entries) || !$entries) {
			throw new ContentPackRefusal('pack.json needs "entries": a list of what the pack makes true.');
		}

		// Ids first, so an entry may refer to one that appears later.
		foreach ($entries as $i => $entry) {
			if (!is_array($entry) || array_is_list($entry)) {
				throw new ContentPackRefusal('Entry ' . ($i + 1) . ' is not an object.');
			}
			if (isset($entry['id'])) {
				if (!is_string($entry['id']) || $entry['id'] === '' || !isset($entry['match'])) {
					throw new ContentPackRefusal('Entry ' . ($i + 1) . ': "id" names a record entry, as text.');
				}
				if (isset($this->ids[$entry['id']])) {
					throw new ContentPackRefusal('Two entries are called "' . $entry['id'] . '".');
				}
				$this->ids[$entry['id']] = $i;
			}
		}

		$used_files = array();
		foreach ($entries as $i => $entry) {
			$where = 'Entry ' . ($i + 1);
			if (array_key_exists('setting', $entry)) {
				$this->entries[$i] = $this->validate_setting($entry, $where, $used_files);
			} elseif (array_key_exists('unlisted', $entry)) {
				$this->entries[$i] = $this->validate_unlisted($entry, $where, $used_files);
			} elseif (array_key_exists('match', $entry)) {
				$this->entries[$i] = $this->validate_record($entry, $where, $used_files);
			} else {
				throw new ContentPackRefusal($where . ' is none of a record ("match"), an unlisted ("unlisted") or a setting ("setting") entry.');
			}
		}

		foreach (array_keys($this->files) as $file) {
			if (!isset($used_files[$file])) {
				throw new ContentPackRefusal('"' . $file . '" is in the zip but no entry uses it.');
			}
		}

		$this->record_order = $this->order_records();
	}

	private function validate_record(array $entry, string $where, array &$used_files): array {
		self::refuse_unknown_keys($entry, self::RECORD_KEYS, $where);
		$class = $this->model_class($entry['model'] ?? null, $where);
		$specs = $class::$field_specifications;
		$label = $where . ' (' . $class . ')';

		$match = $this->object_of($entry['match'], $label . ' "match"');
		$key_sets = array_map(function ($set) { sort($set); return $set; }, $class::$content_pack_keys);
		$match_fields = array_keys($match);
		sort($match_fields);
		if (!in_array($match_fields, $key_sets, true)) {
			throw new ContentPackRefusal($label . ': "match" must name exactly one of the key sets ' . $class . ' declares: '
				. implode('; ', array_map(function ($s) { return implode(' + ', $s); }, $key_sets)) . '.');
		}

		$match_also = array();
		if (isset($entry['match_also'])) {
			if (!is_array($entry['match_also']) || !array_is_list($entry['match_also'])) {
				throw new ContentPackRefusal($label . ': "match_also" must be a list of objects.');
			}
			foreach ($entry['match_also'] as $n => $alt) {
				$alt = $this->object_of($alt, $label . ' "match_also" ' . ($n + 1));
				foreach ($alt as $field => $value) {
					$this->refuse_undeclared_column($class, $field, $label . ' "match_also"');
					$alt[$field] = $this->value_of($value, $label . ' "match_also" ' . $field, $used_files, false);
				}
				$match_also[] = $alt;
			}
		}

		$set = isset($entry['set']) ? $this->object_of($entry['set'], $label . ' "set"') : array();
		$on_create = isset($entry['on_create']) ? $this->object_of($entry['on_create'], $label . ' "on_create"') : array();

		foreach ($match as $field => $value) {
			$this->refuse_unwritable($class, $field, $label, true);
			$match[$field] = $this->value_of($value, $label . ' ' . $field, $used_files, false);
		}
		foreach (array('set' => &$set, 'on_create' => &$on_create) as $part => &$fields) {
			foreach ($fields as $field => $value) {
				if (array_key_exists($field, $match)) {
					throw new ContentPackRefusal($label . ': ' . $field . ' is in "match"; a match value is kept on every apply already.');
				}
				$this->refuse_unwritable($class, $field, $label, false);
				$fields[$field] = $this->value_of($value, $label . ' ' . $field, $used_files, true);
			}
		}
		unset($fields);
		foreach ($on_create as $field => $value) {
			if (array_key_exists($field, $set)) {
				throw new ContentPackRefusal($label . ': ' . $field . ' is in both "set" and "on_create".');
			}
		}

		return array('kind' => 'record', 'id' => $entry['id'] ?? null, 'model' => $class,
			'match' => $match, 'match_also' => $match_also, 'set' => $set, 'on_create' => $on_create);
	}

	private function validate_unlisted(array $entry, string $where, array &$used_files): array {
		self::refuse_unknown_keys($entry, self::UNLISTED_KEYS, $where);
		$class = $this->model_class($entry['model'] ?? null, $where);
		$label = $where . ' (' . $class . ' unlisted)';
		$unlisted = $this->object_of($entry['unlisted'], $label);
		self::refuse_unknown_keys($unlisted, self::UNLISTED_INNER_KEYS, $label);

		$filter = $this->object_of($unlisted['where'] ?? null, $label . ' "where"');
		if (!$filter) {
			throw new ContentPackRefusal($label . ': "where" must name at least one field.');
		}
		foreach ($filter as $field => $value) {
			$this->refuse_undeclared_column($class, $field, $label . ' "where"');
			$filter[$field] = $this->value_of($value, $label . ' "where" ' . $field, $used_files, false);
		}
		$set = $this->object_of($unlisted['set'] ?? null, $label . ' "set"');
		if (!$set) {
			throw new ContentPackRefusal($label . ': "set" must name at least one field.');
		}
		foreach ($set as $field => $value) {
			$this->refuse_unwritable($class, $field, $label, false);
			$set[$field] = $this->value_of($value, $label . ' ' . $field, $used_files, false);
		}
		return array('kind' => 'unlisted', 'model' => $class, 'where' => $filter, 'set' => $set);
	}

	private function validate_setting(array $entry, string $where, array &$used_files): array {
		self::refuse_unknown_keys($entry, self::SETTING_KEYS, $where);
		$name = $entry['setting'];
		if (!is_string($name) || $name === '') {
			throw new ContentPackRefusal($where . ': "setting" must name a setting.');
		}
		if (!SettingsDeclarations::isDeclared($name)) {
			throw new ContentPackRefusal($where . ': "' . $name . '" is not a declared setting on this site.');
		}
		if (SettingsDeclarations::isSecret($name) || preg_match(SystemBase::CREDENTIAL_FIELD_PATTERN, $name)) {
			throw new ContentPackRefusal($where . ': "' . $name . '" is a credential. A pack never carries one.');
		}
		if (SettingsDeclarations::isManaged($name)) {
			throw new ContentPackRefusal($where . ': "' . $name . '" is written by the site itself, never by hand or by a pack.');
		}
		if (!array_key_exists('value', $entry)) {
			throw new ContentPackRefusal($where . ': a setting entry needs "value".');
		}
		$value = $this->value_of($entry['value'], $where . ' ' . $name, $used_files, true);
		return array('kind' => 'setting', 'name' => $name, 'value' => $value);
	}

	private function model_class($name, string $where): string {
		if (!is_string($name) || !isset($this->models[$name])) {
			throw new ContentPackRefusal($where . ': ' . json_encode($name) . ' is not a model a pack may write on this site. '
				. ($this->models ? 'Those are: ' . implode(', ', array_keys($this->models)) . '.' : 'None are active.'));
		}
		return $this->models[$name];
	}

	private function refuse_undeclared_column(string $class, $field, string $where): void {
		if (!is_string($field) || !isset($class::$field_specifications[$field])) {
			throw new ContentPackRefusal($where . ': ' . json_encode($field) . ' is not a column of ' . $class . '.');
		}
	}

	/** A field a pack may write: a declared key field ($is_key), or one on the model's pack list. */
	private function refuse_unwritable(string $class, $field, string $where, bool $is_key): void {
		$this->refuse_undeclared_column($class, $field, $where);
		$keys = array_merge(...array_values($class::$content_pack_keys));
		$fields = property_exists($class, 'content_pack_fields') ? $class::$content_pack_fields : array();
		$open = $is_key ? in_array($field, $keys, true) : in_array($field, $fields, true);
		if (!$open || $class::is_unwritable_field($field)) {
			throw new ContentPackRefusal($where . ': ' . $class . ' does not let a pack write ' . $field . '.');
		}
	}

	private function object_of($value, string $where): array {
		if (!is_array($value) || ($value && array_is_list($value))) {
			throw new ContentPackRefusal($where . ' must be an object.');
		}
		return $value;
	}

	/**
	 * A value as the pack wrote it: a scalar or null, {"ref": id} or, where
	 * $files_ok, {"file": name}. Files are read here; refs stay refs.
	 */
	private function value_of($value, string $where, array &$used_files, bool $files_ok) {
		if ($value === null || is_scalar($value)) {
			return $value;
		}
		if (is_array($value) && count($value) === 1 && array_key_exists('ref', $value)) {
			if (!is_string($value['ref']) || !isset($this->ids[$value['ref']])) {
				throw new ContentPackRefusal($where . ': no record entry is called ' . json_encode($value['ref']) . '.');
			}
			return array('ref' => $value['ref']);
		}
		if ($files_ok && is_array($value) && count($value) === 1 && array_key_exists('file', $value)) {
			$file = $value['file'];
			if (!is_string($file) || $file === '' || strpbrk($file, '/\\') !== false || strpos($file, '..') !== false) {
				throw new ContentPackRefusal($where . ': ' . json_encode($file) . ' is not a file name in the pack.');
			}
			if (!isset($this->files[$file])) {
				throw new ContentPackRefusal($where . ': the pack holds no file "' . $file . '".');
			}
			$used_files[$file] = true;
			return $this->files[$file];
		}
		throw new ContentPackRefusal($where . ': a value is text, a number, true, false, null, {"ref": ...}'
			. ($files_ok ? ' or {"file": ...}' : '') . '.');
	}

	private static function refuse_unknown_keys(array $object, array $known, string $where): void {
		foreach (array_keys($object) as $key) {
			if (!in_array($key, $known, true)) {
				throw new ContentPackRefusal($where . ': unknown key ' . json_encode($key) . '. Known: ' . implode(', ', $known) . '.');
			}
		}
	}

	/** The ids a record entry refers to, in match, set and on_create. */
	private static function refs_of(array $entry): array {
		$refs = array();
		foreach (array('match', 'set', 'on_create', 'where') as $part) {
			foreach ($entry[$part] ?? array() as $value) {
				if (is_array($value) && isset($value['ref'])) {
					$refs[] = $value['ref'];
				}
			}
		}
		return array_values(array_unique($refs));
	}

	/** Record entries, each after the records it refers to. A cycle refuses the pack. */
	private function order_records(): array {
		$order = array();
		$state = array();   // index => 1 visiting, 2 done
		$visit = function ($i, $path) use (&$visit, &$order, &$state) {
			if (($state[$i] ?? 0) === 2) {
				return;
			}
			if (($state[$i] ?? 0) === 1) {
				throw new ContentPackRefusal('These entries refer to each other in a loop: ' . implode(' -> ', array_merge($path, array($this->entries[$i]['id']))) . '.');
			}
			$state[$i] = 1;
			foreach (self::refs_of($this->entries[$i]) as $ref) {
				$visit($this->ids[$ref], array_merge($path, array($this->entries[$i]['id'] ?? ('entry ' . ($i + 1)))));
			}
			$state[$i] = 2;
			$order[] = $i;
		};
		foreach ($this->entries as $i => $entry) {
			if ($entry['kind'] === 'record') {
				$visit($i, array());
			}
		}
		return $order;
	}

	public function name(): string { return $this->name; }
	public function version(): string { return $this->version; }

	// ---------------------------------------------------------------------
	// The plan
	// ---------------------------------------------------------------------

	/**
	 * What applying this pack would do, read from the site as it is now. Writes
	 * nothing.
	 *
	 * Returns ['pack', 'version', 'description', 'notes', 'items', 'hash'].
	 * Each item is a record, unlisted or setting block; each line in it carries
	 * a state (new, change, same, edited), the site's value, the pack's value,
	 * and a 'tick' id with a 'default' when the owner may choose it.
	 *
	 * @throws ContentPackRefusal when the site's rows make the pack ambiguous.
	 */
	public function plan(): array {
		$keys = array();   // record id => existing key, or null while new
		$items = array();

		foreach ($this->record_order as $i) {
			$items[$i] = $this->plan_record($i, $keys);
			if ($this->entries[$i]['id'] !== null) {
				$keys[$this->entries[$i]['id']] = $items[$i]['key'];
			}
		}
		foreach ($this->entries as $i => $entry) {
			if ($entry['kind'] === 'unlisted') {
				$items[$i] = $this->plan_unlisted($i, $keys, $items);
			} elseif ($entry['kind'] === 'setting') {
				$items[$i] = $this->plan_setting($i, $keys, $items);
			}
		}
		ksort($items);
		$items = array_values($items);

		return array(
			'pack' => $this->name,
			'version' => $this->version,
			'description' => $this->description,
			'notes' => $this->notes,
			'items' => $items,
			// A stored value that is not UTF-8 is substituted, never dropped:
			// json_encode() would otherwise return false, and every such plan
			// would hash alike, defeating the stale check.
			'hash' => hash('sha256', json_encode(self::without_defaults($items), JSON_INVALID_UTF8_SUBSTITUTE)),
		);
	}

	private static function without_defaults(array $items): array {
		foreach ($items as &$item) {
			unset($item['default']);
			foreach ($item['lines'] ?? array() as $n => $line) {
				unset($item['lines'][$n]['default']);
			}
		}
		return $items;
	}

	private function plan_record(int $i, array $keys): array {
		$entry = $this->entries[$i];
		$class = $entry['model'];
		$label = $class . ' ' . self::describe($entry['match'], $keys);

		$found = null;
		$via = null;
		$match = $this->resolve_values($entry['match'], $keys);
		if ($match !== null) {
			$hits = self::find_keys($class, $match);
			if (count($hits) > 1) {
				throw new ContentPackRefusal($label . ' matches more than one row (#' . implode(', #', $hits) . '). Make the pack\'s match name one, or tidy the site first.');
			}
			if ($hits) {
				$found = $hits[0];
				$via = 'match';
			}
			foreach ($entry['match_also'] as $alt) {
				$alt_values = $this->resolve_values($alt, $keys);
				if ($alt_values === null) {
					continue;
				}
				$alt_hits = self::find_keys($class, $alt_values);
				if (count($alt_hits) > 1) {
					throw new ContentPackRefusal($label . ': the earlier identity ' . self::describe($alt, $keys) . ' matches more than one row (#' . implode(', #', $alt_hits) . ').');
				}
				if ($alt_hits && $found !== null && $alt_hits[0] !== $found) {
					throw new ContentPackRefusal($label . ' is row #' . $found . ', but its earlier identity ' . self::describe($alt, $keys) . ' is row #' . $alt_hits[0] . '. Retire one of them first.');
				}
				if ($alt_hits && $found === null) {
					$found = $alt_hits[0];
					$via = 'match_also';
				}
			}
		}

		$lines = array();
		$specs = $class::$field_specifications;
		if ($found === null) {
			foreach (array('match' => $entry['match'], 'set' => $entry['set'], 'on_create' => $entry['on_create']) as $part => $fields) {
				foreach ($fields as $field => $value) {
					$lines[] = array('field' => $field, 'part' => $part, 'state' => self::STATE_NEW,
						'current' => null, 'new' => $this->display_value($value, $keys), 'tick' => null);
				}
			}
			$tick = 'r' . $i;
			return array('kind' => 'record', 'index' => $i, 'model' => $class, 'label' => $label, 'key' => null,
				'via' => null, 'state' => self::STATE_NEW, 'tick' => $tick, 'default' => true,
				'needs' => $this->new_refs($entry, $keys), 'lines' => $lines);
		}

		$object = new $class($found, TRUE);
		$target = $class . ':' . $found;
		foreach (array('match' => $entry['match'], 'set' => $entry['set']) as $part => $fields) {
			foreach ($fields as $field => $value) {
				$current = $object->get($field);
				$new = $this->resolve_value($value, $keys);
				$type = $specs[$field]['type'] ?? 'text';
				$state = $this->field_state($target, $field, $current, $new, $type);
				$line = array('field' => $field, 'part' => $part, 'state' => $state,
					'current' => self::display_scalar($current), 'new' => $this->display_value($value, $keys), 'tick' => null);
				if ($state === self::STATE_CHANGE || $state === self::STATE_EDITED) {
					$line['tick'] = 'r' . $i . '.' . $field;
					$line['default'] = ($state === self::STATE_CHANGE);
				}
				$lines[] = $line;
			}
		}
		return array('kind' => 'record', 'index' => $i, 'model' => $class, 'label' => $label . ' (#' . $found . ')',
			'key' => $found, 'via' => $via, 'state' => null, 'tick' => null, 'needs' => array(), 'lines' => $lines);
	}

	private function plan_unlisted(int $i, array $keys, array $items): array {
		$entry = $this->entries[$i];
		$class = $entry['model'];
		$where = $this->resolve_values($entry['where'], $keys);
		$label = $class . ' rows where ' . self::describe($entry['where'], $keys) . ', not named by this pack';

		// The record entries under the same "where": the new ones must be
		// created for these lines to apply, so unticking the new version never
		// leaves a product with none.
		$needs = array();
		$named = array();
		foreach ($this->record_order as $r) {
			$record = $this->entries[$r];
			if ($record['model'] !== $class) {
				continue;
			}
			if ($items[$r]['key'] !== null) {
				$named[] = $items[$r]['key'];
			}
			$shared = array_intersect_key($entry['where'], $record['match']);
			if ($shared && $shared == array_intersect_key($record['match'], $shared) && $items[$r]['key'] === null) {
				$needs[] = 'r' . $r;
			}
		}

		$records = array();
		if ($where !== null) {
			$specs = $class::$field_specifications;
			foreach (self::find_keys($class, $where) as $key) {
				if (in_array($key, $named, true)) {
					continue;
				}
				$object = new $class($key, TRUE);
				$lines = array();
				foreach ($entry['set'] as $field => $value) {
					$new = $this->resolve_value($value, $keys);
					$type = $specs[$field]['type'] ?? 'text';
					if (self::normalize($object->get($field), $type) === self::normalize($new, $type)) {
						continue;
					}
					$lines[] = array('field' => $field, 'current' => self::display_scalar($object->get($field)),
						'new' => $this->display_value($value, $keys));
				}
				if ($lines) {
					$records[] = array('key' => $key, 'label' => $class . ' #' . $key . self::row_name($object),
						'tick' => 'u' . $i . '.' . $key, 'default' => true, 'lines' => $lines);
				}
			}
		}
		return array('kind' => 'unlisted', 'index' => $i, 'model' => $class, 'label' => $label,
			'needs' => array_values(array_unique(array_merge($needs, $this->new_refs($entry, $keys)))), 'records' => $records);
	}

	private function plan_setting(int $i, array $keys, array $items): array {
		$entry = $this->entries[$i];
		$current = self::stored_setting($entry['name']);
		$new = $this->resolve_value($entry['value'], $keys);
		$needs = $this->new_refs(array('set' => array($entry['value'])), $keys);
		// Pointing at a record this apply creates, its value is not known yet,
		// so it cannot be the same as what the site holds.
		$state = $this->field_state('setting', $entry['name'], $current, $needs ? null : $new, 'text', (bool)$needs);
		$item = array('kind' => 'setting', 'index' => $i, 'label' => 'Setting ' . $entry['name'], 'name' => $entry['name'],
			'state' => $state, 'current' => self::display_scalar($current), 'new' => $this->display_value($entry['value'], $keys),
			'needs' => $needs, 'tick' => null);
		if ($state === self::STATE_CHANGE || $state === self::STATE_EDITED) {
			$item['tick'] = 's' . $i;
			$item['default'] = ($state === self::STATE_CHANGE);
		}
		return $item;
	}

	/**
	 * same / change / edited for a field on an existing row. An empty field
	 * holds no edit to protect, so filling it is a change.
	 */
	private function field_state(string $target, string $field, $current, $new, string $type, bool $new_unknown = false): string {
		$current_n = self::normalize($current, $type);
		if (!$new_unknown && $current_n === self::normalize($new, $type)) {
			return self::STATE_SAME;
		}
		if ($current_n === null || $current_n === '') {
			return self::STATE_CHANGE;
		}
		$last = self::last_established($target, $field);
		if ($last !== false && self::normalize($last, $type) === $current_n) {
			return self::STATE_CHANGE;
		}
		return self::STATE_EDITED;
	}

	/** The value a pack last established here, or false when none has. */
	private static function last_established(string $target, string $field) {
		$rows = new MultiContentPackValue(array('cpv_target' => $target, 'cpv_field' => $field));
		foreach ($rows as $row) {
			return $row->get('cpv_value');
		}
		return false;
	}

	/** The tick ids of new records an entry's refs point at. */
	private function new_refs(array $entry, array $keys): array {
		$needs = array();
		foreach (self::refs_of($entry) as $ref) {
			if (array_key_exists($ref, $keys) && $keys[$ref] === null) {
				$needs[] = 'r' . $this->ids[$ref];
			}
		}
		return $needs;
	}

	/** Pack values with refs replaced by keys; null when a ref's record is still new. */
	private function resolve_values(array $values, array $keys): ?array {
		$resolved = array();
		foreach ($values as $field => $value) {
			if (is_array($value) && isset($value['ref'])) {
				if (!isset($keys[$value['ref']])) {
					return null;
				}
				$value = (int)$keys[$value['ref']];
			}
			$resolved[$field] = $value;
		}
		return $resolved;
	}

	private function resolve_value($value, array $keys) {
		if (is_array($value) && isset($value['ref'])) {
			return isset($keys[$value['ref']]) ? (int)$keys[$value['ref']] : null;
		}
		return $value;
	}

	/** Rows of $class whose columns hold $values, live rows only. Text compares trimmed. */
	private static function find_keys(string $class, array $values): array {
		$specs = $class::$field_specifications;
		$table = $class::$tablename;
		$pkey = $class::$pkey_column;
		$clauses = array();
		$params = array();
		foreach ($values as $field => $value) {
			if (!isset($specs[$field])) {
				throw new ContentPackRefusal($field . ' is not a column of ' . $class . '.');
			}
			$type = strtolower($specs[$field]['type'] ?? '');
			if ($value === null) {
				$clauses[] = $field . ' IS NULL';
			} elseif (self::is_text_type($type)) {
				$clauses[] = 'BTRIM(' . $field . ') = ?';
				$params[] = trim((string)$value);
			} elseif (self::is_bool_type($type)) {
				$clauses[] = $field . ' = ?';
				$params[] = self::normalize($value, $type) === '1' ? 'true' : 'false';
			} else {
				$clauses[] = $field . ' = ?';
				$params[] = (string)$value;
			}
		}
		$delete_column = $class::$prefix . '_delete_time';
		if (isset($specs[$delete_column])) {
			$clauses[] = $delete_column . ' IS NULL';
		}
		$sql = 'SELECT ' . $pkey . ' FROM ' . $table . ' WHERE ' . implode(' AND ', $clauses) . ' ORDER BY ' . $pkey;
		$stmt = DbConnector::get_instance()->get_db_link()->prepare($sql);
		try {
			$stmt->execute($params);
		} catch (PDOException $e) {
			// A value the column cannot hold ("abc" for an integer) fails the
			// cast in the database; say which, as a refusal.
			throw new ContentPackRefusal($class . ': ' . self::describe($values, array())
				. ' cannot be looked up; a value does not fit its column.');
		}
		return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
	}

	private static function stored_setting(string $name) {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
		$stmt->execute(array($name));
		$value = $stmt->fetchColumn();
		return $value === false ? null : $value;
	}

	private static function is_text_type(string $type): bool {
		return strpos($type, 'char') !== false || $type === 'text';
	}

	private static function is_bool_type(string $type): bool {
		return $type === 'bool' || $type === 'boolean';
	}

	/**
	 * A value in the form two values are compared in: line endings LF, text
	 * trimmed (null and '' alike), booleans and numbers by value.
	 */
	public static function normalize($value, string $type): ?string {
		$type = strtolower($type);
		if (self::is_bool_type($type)) {
			if ($value === null || $value === '') {
				return null;
			}
			return in_array(is_string($value) ? strtolower($value) : $value, array(true, 1, '1', 't', 'true', 'yes'), true) ? '1' : '0';
		}
		if (preg_match('/^(int|smallint|bigint|integer|serial)/', $type)) {
			if ($value === null || $value === '') {
				return null;
			}
			return is_numeric($value) ? (string)(int)$value : (string)$value;
		}
		if (preg_match('/^(numeric|decimal|float|real|double)/', $type)) {
			if ($value === null || $value === '') {
				return null;
			}
			return is_numeric($value) ? (string)(0 + $value) : (string)$value;
		}
		if ($value === true || $value === false) {
			return $value ? '1' : '0';
		}
		return trim(str_replace("\r\n", "\n", (string)$value));
	}

	/** A plan line's display of a pack value: refs named by the record they point at. */
	private function display_value($value, array $keys) {
		if (is_array($value) && isset($value['ref'])) {
			$ref = $value['ref'];
			if (isset($keys[$ref])) {
				return (string)$keys[$ref];
			}
			return '(the new ' . $this->entries[$this->ids[$ref]]['model'] . ' "' . $ref . '")';
		}
		return self::display_scalar($value);
	}

	private static function display_scalar($value): ?string {
		if ($value === null) {
			return null;
		}
		if ($value === true || $value === false) {
			return $value ? 'true' : 'false';
		}
		return (string)$value;
	}

	private static function describe(array $values, array $keys): string {
		$parts = array();
		foreach ($values as $field => $value) {
			if (is_array($value) && isset($value['ref'])) {
				$value = isset($keys[$value['ref']]) ? '#' . $keys[$value['ref']] : '"' . $value['ref'] . '"';
			} else {
				$value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			}
			$parts[] = $field . ' = ' . $value;
		}
		return implode(', ', $parts);
	}

	/**
	 * A line diff of two texts for the check page: a list of [op, line] where
	 * op is ' ' (both), '-' (only the site's) or '+' (only the pack's). Line
	 * endings are compared as LF.
	 */
	public static function line_diff(?string $old, ?string $new): array {
		$a = explode("\n", str_replace("\r\n", "\n", (string)$old));
		$b = explode("\n", str_replace("\r\n", "\n", (string)$new));
		$n = count($a);
		$m = count($b);
		if ($n * $m > 250000) {
			return array_merge(array_map(function ($l) { return array('-', $l); }, $a),
				array_map(function ($l) { return array('+', $l); }, $b));
		}
		$lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
		for ($i = $n - 1; $i >= 0; $i--) {
			for ($j = $m - 1; $j >= 0; $j--) {
				$lcs[$i][$j] = ($a[$i] === $b[$j]) ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
			}
		}
		$diff = array();
		$i = 0;
		$j = 0;
		while ($i < $n && $j < $m) {
			if ($a[$i] === $b[$j]) {
				$diff[] = array(' ', $a[$i]);
				$i++;
				$j++;
			} elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
				$diff[] = array('-', $a[$i++]);
			} else {
				$diff[] = array('+', $b[$j++]);
			}
		}
		while ($i < $n) {
			$diff[] = array('-', $a[$i++]);
		}
		while ($j < $m) {
			$diff[] = array('+', $b[$j++]);
		}
		return $diff;
	}

	/** ' "name"' from the row's first name-like column, for a line a person reads. */
	private static function row_name($object): string {
		$prefix = $object::$prefix;
		foreach (array($prefix . '_name', $prefix . '_version_name', $prefix . '_title') as $column) {
			if (isset($object::$field_specifications[$column]) && (string)$object->get($column) !== '') {
				return ' "' . $object->get($column) . '"';
			}
		}
		return '';
	}

	// ---------------------------------------------------------------------
	// Applying
	// ---------------------------------------------------------------------

	/**
	 * Apply the ticked lines, in one transaction, through the models.
	 *
	 * The plan is built again inside the transaction and must hash to
	 * $shown_hash, so what is written is what the owner saw. Every write is
	 * recorded on $apply; on any failure nothing is written but the record of
	 * the failure.
	 *
	 * @param ContentPackApply $apply The checked upload this pack was read from.
	 * @param string $shown_hash The plan hash the owner confirmed.
	 * @param array $ticks Tick ids the owner left ticked.
	 * @return array What was written: one entry per row or setting, with before and after values.
	 * @throws ContentPackRefusal when the plan changed, or a tick names no line.
	 */
	public function apply(ContentPackApply $apply, string $shown_hash, array $ticks, $session): array {
		if (!Access::decide(10, $session)) {
			throw new ContentPackRefusal('Only a superadmin applies a content pack.');
		}
		return $this->run_apply($apply, $shown_hash, $ticks);
	}

	/**
	 * Apply the plan's default ticks with nobody to read it first: new rows,
	 * and changes to fields that still hold what a pack established, while a
	 * field edited on the site is left as it is. This is how a plugin's own
	 * packs land when it is installed. The writes are the platform's (the
	 * plugin's files carry the pack), so they are not checked against whoever
	 * started the activation.
	 *
	 * @return array What was written, as apply() returns it.
	 */
	public function apply_defaults(ContentPackApply $apply): array {
		return SystemBase::server_initiated_write(function () use ($apply) {
			return $this->run_apply($apply, null, null);
		});
	}

	/**
	 * The apply itself. $shown_hash null takes the plan built here, and $ticks
	 * null takes that plan's default ticks.
	 */
	private function run_apply(ContentPackApply $apply, ?string $shown_hash, ?array $ticks): array {
		$ticks = $ticks === null ? null : array_values(array_unique(array_map('strval', $ticks)));
		$dblink = DbConnector::get_instance()->get_db_link();
		if ($dblink->inTransaction()) {
			throw new ContentPackRefusal('A content pack applies in a transaction of its own.');
		}

		$dblink->beginTransaction();
		$committed = false;
		try {
			// One apply at a time on this site, so two that both plan a row as
			// new cannot both create it.
			$dblink->query("SELECT pg_advisory_xact_lock(hashtext('content_pack_apply'))");
			$plan = $this->plan();
			if ($ticks === null) {
				$ticks = self::default_ticks($plan['items']);
			}
			if ($shown_hash !== null && !hash_equals($plan['hash'], $shown_hash)) {
				throw new ContentPackRefusal('The site changed since this pack was checked. Check it again and read the new plan before applying.');
			}
			$tickable = self::tickable($plan['items']);
			foreach ($ticks as $tick) {
				if (!isset($tickable[$tick])) {
					throw new ContentPackRefusal('"' . $tick . '" is not a line this plan offers.');
				}
			}
			$ticked = array_flip($ticks);

			$written = array();
			$keys = array();       // record id => key, once it exists
			$created = array();    // tick id of each record created
			$items = array();
			foreach ($plan['items'] as $item) {
				$items[$item['index']] = $item;
			}

			foreach ($this->record_order as $i) {
				$entry = $this->entries[$i];
				$item = $items[$i];
				$key = $this->apply_record($entry, $item, $ticked, $keys, $created, $apply, $written);
				if ($entry['id'] !== null && $key !== null) {
					$keys[$entry['id']] = $key;
				}
			}
			foreach ($this->entries as $i => $entry) {
				$item = $items[$i];
				$needs_met = !array_diff($item['needs'] ?? array(), array_keys($created));
				if ($entry['kind'] === 'unlisted' && $needs_met) {
					$this->apply_unlisted($entry, $item, $ticked, $keys, $written);
				} elseif ($entry['kind'] === 'setting' && $needs_met) {
					$this->apply_setting($entry, $item, $ticked, $keys, $apply, $written);
				}
			}

			$apply->set('cpa_status', ContentPackApply::STATUS_APPLIED);
			$apply->set('cpa_plan', json_encode($plan, JSON_INVALID_UTF8_SUBSTITUTE));
			$apply->set('cpa_ticks', json_encode($ticks));
			$apply->set('cpa_result', json_encode($written));
			$apply->set('cpa_applied_time', gmdate('Y-m-d H:i:s'));
			$apply->set('cpa_error', NULL);
			$apply->save();

			$dblink->commit();
			$committed = true;
			return $written;
		} finally {
			if (!$committed && $dblink->inTransaction()) {
				$dblink->rollBack();
			}
		}
	}

	/** The lines a plan ticks before anyone changes it. */
	public static function default_ticks(array $items): array {
		$ticks = array();
		foreach ($items as $item) {
			if (!empty($item['tick']) && !empty($item['default'])) {
				$ticks[] = $item['tick'];
			}
			foreach ($item['lines'] ?? array() as $line) {
				if (!empty($line['tick']) && !empty($line['default'])) {
					$ticks[] = $line['tick'];
				}
			}
			foreach ($item['records'] ?? array() as $record) {
				if (!empty($record['default'])) {
					$ticks[] = $record['tick'];
				}
			}
		}
		return $ticks;
	}

	/** Tick id => true for every line the plan lets the owner choose. */
	private static function tickable(array $items): array {
		$ids = array();
		foreach ($items as $item) {
			if (!empty($item['tick'])) {
				$ids[$item['tick']] = true;
			}
			foreach ($item['lines'] ?? array() as $line) {
				if (!empty($line['tick'])) {
					$ids[$line['tick']] = true;
				}
			}
			foreach ($item['records'] ?? array() as $record) {
				$ids[$record['tick']] = true;
			}
		}
		return $ids;
	}

	private function apply_record(array $entry, array $item, array $ticked, array $keys, array &$created, ContentPackApply $apply, array &$written): ?int {
		$class = $entry['model'];
		if ($item['key'] === null) {
			if (!isset($ticked[$item['tick']]) || array_diff($item['needs'], array_keys($created))) {
				return null;
			}
			$object = new $class(NULL);
			$fields = array_merge($entry['match'], $entry['set'], $entry['on_create']);
			$after = array();
			foreach ($fields as $field => $value) {
				$value = $this->write_value($this->resolve_value($value, $keys), $class, $field);
				$object->set($field, $value);
				$after[$field] = self::display_scalar($value);
			}
			$object->prepare();
			$object->save();
			$object->load();
			$created[$item['tick']] = true;
			$this->record_established($class . ':' . $object->key, array_merge($entry['match'], $entry['set']), $keys, $apply);
			$written[] = array('target' => $class . ':' . $object->key, 'label' => $item['label'], 'created' => true,
				'before' => array(), 'after' => $after);
			return (int)$object->key;
		}

		$object = new $class($item['key'], TRUE);
		$before = array();
		$after = array();
		$established = array();
		foreach ($item['lines'] as $line) {
			$field = $line['field'];
			$value = array_key_exists($field, $entry['match']) ? $entry['match'][$field] : $entry['set'][$field];
			if ($line['state'] === self::STATE_SAME) {
				$established[$field] = $value;
				continue;
			}
			if (!isset($ticked[$line['tick']])) {
				continue;
			}
			$new = $this->write_value($this->resolve_value($value, $keys), $class, $field);
			$before[$field] = self::display_scalar($object->get($field));
			$object->set($field, $new);
			$after[$field] = self::display_scalar($new);
			$established[$field] = $value;
		}
		if ($after) {
			$object->prepare();
			$object->save();
			$written[] = array('target' => $class . ':' . $object->key, 'label' => $item['label'], 'created' => false,
				'before' => $before, 'after' => $after);
		}
		$this->record_established($class . ':' . $object->key, $established, $keys, $apply);
		return (int)$object->key;
	}

	private function apply_unlisted(array $entry, array $item, array $ticked, array $keys, array &$written): void {
		$class = $entry['model'];
		foreach ($item['records'] as $record) {
			if (!isset($ticked[$record['tick']])) {
				continue;
			}
			$object = new $class($record['key'], TRUE);
			$before = array();
			$after = array();
			foreach ($entry['set'] as $field => $value) {
				$new = $this->write_value($this->resolve_value($value, $keys), $class, $field);
				$before[$field] = self::display_scalar($object->get($field));
				$object->set($field, $new);
				$after[$field] = self::display_scalar($new);
			}
			$object->prepare();
			$object->save();
			$written[] = array('target' => $class . ':' . $object->key, 'label' => $record['label'], 'created' => false,
				'before' => $before, 'after' => $after);
		}
	}

	private function apply_setting(array $entry, array $item, array $ticked, array $keys, ContentPackApply $apply, array &$written): void {
		$value = $this->resolve_value($entry['value'], $keys);
		if ($item['state'] !== self::STATE_SAME) {
			if (!isset($ticked[$item['tick']])) {
				return;
			}
			$before = self::stored_setting($entry['name']);
			Setting::put($entry['name'], $value);
			$written[] = array('target' => 'setting', 'label' => $item['label'], 'created' => false,
				'before' => array($entry['name'] => self::display_scalar($before)),
				'after' => array($entry['name'] => self::display_scalar($value)));
		}
		$this->record_established('setting', array($entry['name'] => $value), $keys, $apply);
	}

	/** A value as the model stores it: booleans as booleans, the rest as written. */
	private function write_value($value, string $class, string $field) {
		$type = strtolower($class::$field_specifications[$field]['type'] ?? '');
		if (self::is_bool_type($type) && $value !== null) {
			return self::normalize($value, $type) === '1';
		}
		return $value;
	}

	/** Record that a pack established these values on $target (§4.2). */
	private function record_established(string $target, array $values, array $keys, ContentPackApply $apply): void {
		foreach ($values as $field => $value) {
			$value = $this->resolve_value($value, $keys);
			$row = null;
			foreach (new MultiContentPackValue(array('cpv_target' => $target, 'cpv_field' => $field)) as $existing) {
				$row = $existing;
			}
			if ($row === null) {
				$row = new ContentPackValue(NULL);
				$row->set('cpv_target', $target);
				$row->set('cpv_field', $field);
			}
			$row->set('cpv_value', $value === null ? null : self::display_scalar($value));
			$row->set('cpv_cpa_content_pack_apply_id', $apply->key);
			$row->set('cpv_update_time', gmdate('Y-m-d H:i:s'));
			$row->save();
		}
	}
}
