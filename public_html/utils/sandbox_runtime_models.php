<?php
/**
 * sandbox_runtime_models.php - the model layer inside a sandboxed plugin's
 * fence (specs/sandboxed_php_plugins.md design 5). Loaded by
 * SandboxRuntime::boot(), after the stand-ins; never by the site itself.
 *
 * Models read like native code:
 *
 *   $event = new Event(12, TRUE);                                     record.get
 *   $log = new SentLog(); $log->set('message', 'hi'); $log->save();   record.create
 *   foreach (new MultiSentLog(['signal' => 'x'], ['create_time' => 'DESC'], 50) as $row) { ... }   record.list
 *
 * SystemBase::$remote_store is set to SandboxRemoteStore, so a model's
 * load(), save(), soft_delete() and a collection's load() and count_all()
 * are asks to core over the pipe, carried out by the API with the plugin's
 * approved reach (SandboxRelay). Core checks every save as it does any API
 * caller's. The plugin's own rules run in the fence before the ask: a
 * prepare() its code declares, which may refuse the save, and a save() it
 * overrides. They bind only the plugin.
 *
 * A model's short name is the plugin's only when no core or native class has
 * it: the manifest check refuses such a name at install
 * (SandboxPluginManager::modelCollisions), and a class core gains later wins,
 * the plugin's model staying reachable by its Sbx_ name.
 *
 * The plugin's own models are declared here from the envelope's `models`
 * (the manifest's declarations, as core read them), under core's class
 * names (Sbx_{plugin}_{Model}) and their short names (SentLog,
 * MultiSentLog). A plugin may add methods in src/data with a class of the
 * short name that extends the core name (class SentLog extends
 * Sbx_myplugin_SentLog); it declares no fields there. Its collection then
 * yields its class.
 *
 * Refused, each with a message naming the alternative: raw SQL (the
 * database stand-in), a string condition ("IS NULL") or a split-parenthesis
 * OR (they are SQL text), a NULL filter (not carried by the API; use another
 * column), an OR collection, more than one sort column, an offset that is
 * not a whole number of pages, restoring or removing a row, and a
 * collection of the plugin's that writes its own getMultiResults().
 *
 * A collection with no limit is read a page (200 rows) at a time, each page
 * one ask, within the call's 200 asks.
 *
 * @version 1.1 - reviewer2 G1-G4: no short name a core class has; the plugin's prepare() runs; coreName() walks to
 *                a known model class; a 400 on a read throws
 * @version 1.0
 */

final class SandboxRemoteStore implements SystemBaseRemoteStore {

	/** Rows read at once by a collection with no limit (the API's page cap). */
	const PAGE = 200;

	/**
	 * The class core knows a model by: the first class up from $class that is
	 * core's, an approved native plugin's, or one of the plugin's declared
	 * models (Sbx_), wherever the plugin wrote the class below it.
	 */
	public static function coreName(string $class): string {
		while (!SandboxRuntime::isKnownModelClass($class) && ($parent = get_parent_class($class)) !== false) {
			$class = $parent;
		}
		return $class;
	}

	/**
	 * The plugin's own rules on a row it saves: prepare() when the plugin's
	 * code declares it (on its own model class or one it extends). They may
	 * refuse, as natively. A core model's rules are core's, run where the API
	 * saves the row; in the fence they could only reach the database stand-in.
	 */
	public function beforeWrite(SystemBase $row): void {
		$declaring = (new ReflectionMethod($row, 'prepare'))->getDeclaringClass()->getName();
		if (!SandboxRuntime::isKnownModelClass($declaring) && !in_array($declaring, array('SystemBase', 'SandboxRecord'), true)) {
			$row->prepare();
		}
	}

	public function fetch(string $class, int $id): ?array {
		try {
			$answer = SandboxPipe::ask('record.get', array('model' => self::coreName($class), 'id' => $id));
		} catch (SandboxPipeException $e) {
			// The API's answer for a row that is not there, or not this
			// plugin's to read: a model with no key, as natively. A malformed
			// ask (400) is a bug the author should see, and throws.
			if (in_array($e->getCode(), array(403, 404), true)) {
				return null;
			}
			throw $e;
		}
		$record = $answer['record'] ?? null;
		return is_array($record) && $record !== array() && ($record[$class::$pkey_column] ?? null) !== null ? $record : null;
	}

	public function write(string $class, ?int $id, array $fields): array {
		$model = self::coreName($class);
		$answer = $id === null
			? SandboxPipe::ask('record.create', array('model' => $model, 'fields' => (object)$fields))
			: SandboxPipe::ask('record.update', array('model' => $model, 'id' => $id, 'fields' => (object)$fields));
		return is_array($answer['record'] ?? null) ? $answer['record'] : array();
	}

	public function trash(string $class, int $id): array {
		$answer = SandboxPipe::ask('record.delete', array('model' => self::coreName($class), 'id' => $id));
		return is_array($answer['record'] ?? null) ? $answer['record'] : array();
	}

	public function select(SystemMultiBase $multi, bool $count_only): array {
		$collection = get_class($multi);
		$declaring = (new ReflectionMethod($multi, 'getMultiResults'))->getDeclaringClass()->getName();
		if (SandboxRuntime::isPluginClass($declaring)) {
			throw new SystemBaseException($collection . ' writes its own getMultiResults(), which is SQL: a sandboxed plugin\'s collection filters by column only.');
		}
		$model = self::coreName($multi::remote_model_class());
		if (strtoupper((string)$multi->operation) !== 'AND') {
			throw new SystemBaseException($collection . ': an OR collection is SQL text; ask for each list and join them.');
		}
		$ask = array('model' => $model, 'filters' => (object)self::filters($multi));
		$order = (array)$multi->order_by;
		if (count($order) > 1) {
			throw new SystemBaseException($collection . ': a list is sorted on one column.');
		}
		foreach ($order as $column => $direction) {
			$ask['sort'] = (string)$column;
			$ask['direction'] = strtolower((string)$direction) === 'desc' ? 'desc' : 'asc';
		}

		if ($count_only) {
			$answer = SandboxPipe::ask('record.list', $ask + array('limit' => 1, 'offset' => 0));
			return array('rows' => array(), 'total' => (int)($answer['total'] ?? 0));
		}
		$limit = (int)$multi->limit;
		$offset = (int)$multi->offset;
		if ($limit > 0) {
			if ($limit > self::PAGE) {
				throw new SystemBaseException($collection . ': at most ' . self::PAGE . ' rows at a time; read the rest by offset.');
			}
			if ($offset % $limit !== 0) {
				throw new SystemBaseException($collection . ': the offset must be a whole number of pages (a multiple of the limit).');
			}
			$answer = SandboxPipe::ask('record.list', $ask + array('limit' => $limit, 'offset' => $offset));
			return array('rows' => (array)($answer['records'] ?? array()), 'total' => (int)($answer['total'] ?? 0));
		}
		if ($offset % self::PAGE !== 0) {
			throw new SystemBaseException($collection . ': with no limit, the offset must be a multiple of ' . self::PAGE . '.');
		}
		// No limit: every row, a page at a time.
		$rows = array();
		$total = 0;
		do {
			$answer = SandboxPipe::ask('record.list', $ask + array('limit' => self::PAGE, 'offset' => $offset));
			$page = (array)($answer['records'] ?? array());
			$total = (int)($answer['total'] ?? 0);
			$rows = array_merge($rows, $page);
			$offset += self::PAGE;
		} while (count($page) === self::PAGE && $offset < $total);
		return array('rows' => $rows, 'total' => $total);
	}

	/**
	 * A collection's options as column filters: one value each, or deleted.
	 * The class's own defaults it did not change are left out (they are the
	 * class's own option keys, which only its SQL knows).
	 */
	private static function filters(SystemMultiBase $multi): array {
		$defaults = (new ReflectionProperty($multi, 'default_options'))->getValue();
		$collection = get_class($multi);
		$out = array();
		foreach ((array)$multi->options as $key => $value) {
			$key = (string)$key;
			if (is_array($defaults) && array_key_exists($key, $defaults) && $defaults[$key] === $value) {
				continue;
			}
			if ($key !== '' && $key[0] === '(') {
				throw new SystemBaseException($collection . ': a split-parenthesis OR is SQL text; ask for each list and join them.');
			}
			if (is_array($value)) {
				// The parameterized form [value, PDO::PARAM_*].
				if (count($value) !== 2 || !array_key_exists(0, $value) || !is_int($value[1] ?? null)) {
					throw new SystemBaseException($collection . ': the filter on ' . $key . ' takes one value.');
				}
				$value = $value[0];
			}
			if ($key === 'deleted') {
				$out['deleted'] = (bool)$value;
				continue;
			}
			if ($value === null) {
				throw new SystemBaseException($collection . ': a filter for an empty ' . $key . ' is not carried to core; filter on another column.');
			}
			if (is_string($value) && preg_match('~^\s*IS\s+(NOT\s+)?NULL\s*$~i', $value)) {
				throw new SystemBaseException($collection . ': "' . trim($value) . '" is SQL text, not a value; for the trash use the deleted filter.');
			}
			$out[$key] = $value;
		}
		return $out;
	}
}

/** The plugin's own models, declared in the fence from the envelope. */
final class SandboxFenceModels {

	/** short name => core class name */
	private static $short = array();

	/** core class name (lower case) => model declaration */
	private static $declarations = array();

	public static function register(string $plugin, array $models): void {
		if (!preg_match(SandboxManifest::NAME_PATTERN, $plugin)) {
			return;
		}
		foreach ($models as $model) {
			if (!is_array($model) || !is_string($model['name'] ?? null) || !preg_match(SandboxManifest::MODEL_PATTERN, $model['name'])) {
				continue;
			}
			$class = SandboxManifest::classFor($plugin, $model['name']);
			self::$short[$model['name']] = $class;
			self::$declarations[strtolower($class)] = array($plugin, $model);
		}
	}

	/** The autoloader for the plugin's models: core names, short names, their collections. */
	public static function load(string $class): void {
		$lower = strtolower($class);
		$multi = strncmp($lower, 'multi', 5) === 0;
		$base = $multi ? substr($class, 5) : $class;
		if (isset(self::$declarations[strtolower($base)])) {
			self::declare(strtolower($base));
			return;
		}
		$core = self::$short[$base] ?? null;
		if ($core === null) {
			return;
		}
		self::declare(strtolower($core));
		if (!$multi) {
			class_alias($core, $base);
			return;
		}
		if (SandboxRuntime::isPluginClass($base)) {
			// The plugin's own model class: its collection yields it.
			SandboxModels::declareFenceCollection($base, $core);
		} else {
			class_alias('Multi' . $core, 'Multi' . $base);
		}
	}

	private static function declare(string $lower): void {
		list($plugin, $model) = self::$declarations[$lower];
		SandboxModels::declareExtendable($plugin, $model);
	}
}
