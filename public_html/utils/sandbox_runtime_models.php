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
 *   foreach (new MultiSentLog(['id' => [4, 9, 12]]) as $row) { ... }                              record.list, any of them
 *
 * SystemBase::$remote_store is set to SandboxRemoteStore, so a model's
 * load(), save(), soft_delete() and a collection's load() and count_all()
 * are asks to core over the pipe, carried out by the API with the plugin's
 * approved reach (SandboxRelay). Core checks every save as it does any API
 * caller's. The plugin's own rules run in the fence before the ask: a
 * prepare() its code declares, which may refuse the save, and a save() it
 * overrides. They bind only the plugin.
 *
 * The plugin's own models are its model files in src/data, loaded as
 * written (class Note extends SystemBase, class MultiNote extends
 * SystemMultiBase): the same files the site read, without running, for its
 * own classes (spec sandboxed_plugin_model_files). The envelope's `models`
 * maps each to the name the site knows it by (Note => Sbx_notes_Note), which
 * every ask carries. A model's name is the plugin's only when no core or
 * native class has it: the install refuses such a name
 * (SandboxPluginManager::modelCollisions).

 * Refused, each with a message naming the alternative: raw SQL (the
 * database stand-in), a string condition ("IS NULL") or a split-parenthesis
 * OR (they are SQL text), a NULL filter (not carried by the API; use another
 * column), an OR collection, more than one sort column, an offset that is
 * not a whole number of pages, restoring or removing a row, and a
 * collection of the plugin's that writes its own getMultiResults().
 *
 * A filter's value is one value or a list of up to 200 (any of them), as a
 * column filter takes natively (SystemBase::column_option_filters()); the
 * whole list is one ask.
 *
 * A collection with no limit is read a page (200 rows) at a time, each page
 * one ask, within the call's 200 asks.
 *
 * @version 2.0 - the plugin's own model files load as written; the envelope maps each class to the site's name
 *                (SandboxFenceModels); its prepare() runs when its own class declares it (spec sandboxed_plugin_model_files)
 * @version 1.3 - a collection with no query of its own sends its default options, which are column filters
 *                (spec collection_default_query)
 * @version 1.2 - a filter takes a list of values, as natively; the [value, PDO::PARAM_*] form, which a column
 *                filter does not take natively, is a list too (spec sandboxed_plugin_ask_speed WP2)
 * @version 1.1 - reviewer2 G1-G4: no short name a core class has; the plugin's prepare() runs; coreName() walks to
 *                a known model class; a 400 on a read throws
 * @version 1.0
 */

final class SandboxRemoteStore implements SystemBaseRemoteStore {

	/** Rows read at once by a collection with no limit (the API's page cap). */
	const PAGE = 200;

	/**
	 * The class core knows a model by: the site's name of one of the plugin's
	 * own models, or the first class up from $class that is core's or an
	 * approved native plugin's, wherever the plugin wrote the class below it.
	 */
	public static function coreName(string $class): string {
		$at = $class;
		while ($at !== false) {
			$site = SandboxFenceModels::siteName($at);
			if ($site !== null) {
				return $site;
			}
			if (SandboxRuntime::isKnownModelClass($at)) {
				return $at;
			}
			$at = get_parent_class($at);
		}
		return $class;
	}

	/**
	 * The plugin's own rules on a row it saves: prepare() when the plugin's
	 * code declares it (on its own model or a class it extends a model with).
	 * They may refuse, as natively. A core model's rules are core's, run
	 * where the API saves the row; in the fence they could only reach the
	 * database stand-in.
	 */
	public function beforeWrite(SystemBase $row): void {
		$declaring = (new ReflectionMethod($row, 'prepare'))->getDeclaringClass()->getName();
		if (SandboxRuntime::isPluginClass($declaring)) {
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
	 * A collection's options as column filters: one value or a list of them
	 * each, or deleted.
	 * The class's own defaults it did not change are left out (they are the
	 * class's own option keys, which only its SQL knows), unless the
	 * collection has no query of its own (SystemMultiBase::is_columns_only()):
	 * its defaults are column filters like any other option.
	 */
	private static function filters(SystemMultiBase $multi): array {
		$defaults = (new ReflectionProperty($multi, 'default_options'))->getValue();
		$collection = get_class($multi);
		$out = array();
		foreach ((array)$multi->options as $key => $value) {
			$key = (string)$key;
			// A collection with no query of its own has no option keys of its
			// own either: its defaults are column filters, and go to core.
			if (!$multi::is_columns_only() && is_array($defaults) && array_key_exists($key, $defaults) && $defaults[$key] === $value) {
				continue;
			}
			if ($key !== '' && $key[0] === '(') {
				throw new SystemBaseException($collection . ': a split-parenthesis OR is SQL text; ask for each list and join them.');
			}
			if ($key === 'deleted') {
				$out['deleted'] = (bool)$value;
				continue;
			}
			if ($value === null) {
				throw new SystemBaseException($collection . ': a filter for an empty ' . $key . ' is not carried to core; filter on another column.');
			}
			if (is_array($value)) {
				// Any of these values, as natively; core holds the list to 200.
				if (!array_is_list($value)) {
					throw new SystemBaseException($collection . ': the filter on ' . $key . ' is one value or a list of values, not keyed entries.');
				}
				foreach ($value as $member) {
					if (!is_scalar($member)) {
						throw new SystemBaseException($collection . ': the filter on ' . $key . ' takes a list of numbers, text or true/false.');
					}
				}
				$out[$key] = $value;
				continue;
			}
			if (is_string($value) && preg_match('~^\s*IS\s+(NOT\s+)?NULL\s*$~i', $value)) {
				throw new SystemBaseException($collection . ': "' . trim($value) . '" is SQL text, not a value; for the trash use the deleted filter.');
			}
			$out[$key] = $value;
		}
		return $out;
	}
}

/** The plugin's own models, by the names the site knows them (the envelope's `models`). */
final class SandboxFenceModels {

	/** the plugin's class (lower case) => the site's class name */
	private static $site = array();

	/** @param array $models the plugin's class => the site's name ('Note' => 'Sbx_notes_Note') */
	public static function register(string $plugin, array $models): void {
		self::$site = array();
		if (!preg_match(SandboxManifest::NAME_PATTERN, $plugin)) {
			return;
		}
		foreach ($models as $class => $site) {
			if (is_string($class) && preg_match(SandboxManifest::MODEL_PATTERN, $class)
					&& $site === SandboxManifest::classFor($plugin, $class)) {
				self::$site[strtolower($class)] = $site;
			}
		}
	}

	/** The site's name of one of the plugin's own model classes, or null. */
	public static function siteName(string $class): ?string {
		$site = self::$site[strtolower(ltrim($class, '\\'))] ?? null;
		return $site !== null && SandboxRuntime::isPluginClass($class) ? $site : null;
	}
}
