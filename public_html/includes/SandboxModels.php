<?php
/**
 * SandboxModels — a sandboxed plugin's models, as the site knows them.
 *
 * A sandboxed plugin declares its models in regular model files in src/data/
 * (spec sandboxed_plugin_model_files). The site never includes those files:
 * SandboxModelDeclarations reads them in the parser jail and checks what they
 * declare, and the checked declarations are kept on the plugin's row
 * (plg_metadata._sandbox_models, with a sha256 of each file). From that
 * stored copy alone this class declares, for each model, two classes from one
 * fixed template:
 *
 *   final class Sbx_{plugin}_{Class} extends SandboxRecord { …static properties… }
 *   final class MultiSbx_{plugin}_{Class} extends SandboxRecordCollection { … }
 *
 * and fills their static properties from the declaration. The only text that
 * reaches the template is the class name, built from the plugin and class
 * names already held to [a-z0-9_] and [A-Za-z0-9], and checked again against
 * CLASS_PATTERN immediately before declaring, so a model file cannot put code
 * there. This is the one place core declares a class at run time;
 * tests/unit/sandbox_models_test.php holds that, and that no core or native
 * class could ever be one of these names (none starts 'Sbx_').
 *
 * Names: plugin 'notes', model class 'Note' with $tablename 'nte_notes' →
 * class Sbx_notes_Note, table sbx_notes__nte_notes, columns as the file
 * declares them (nte_text).
 *
 * When the declarations are read (refresh()), and stored: at install, at
 * activation, at Sync with Filesystem and when an approval is given, each a
 * POST or a CLI run. Opening the approval page reads them and stores nothing.
 * After that the stored copy is the only source: the classes, model
 * discovery, the delete rules and the blocking rows page work with the
 * plugin's folder or manifest unreadable or gone, so a delete rule that
 * blocks a core record never lapses unchosen.
 *
 * What a model opens beyond the plugin (read through the API, a read or
 * delete level below its class's, each member's own rows) and every rule
 * that blocks deleting a site record apply only once approved
 * (SandboxConsent::effectiveModels()); until then the class is the narrower
 * one.
 *
 * Which classes resolve: an active sandboxed plugin's (ClassAutoloader asks
 * autoload() on a miss); one plugin's on request, whatever its status,
 * through classesFor() (install and uninstall); and, for model discovery over
 * many plugins, those installed here.
 *
 * @version 2.0 - models from the plugin's own model files, read never run, and stored on its row; classes built from
 *                the stored copy alone; reach and blocks within the approval; the hash check before a call
 *                (spec sandboxed_plugin_model_files)
 * @version 1.1 - declareExtendable() and declareFenceCollection() (specs/sandboxed_php_plugins.md WP4)
 * @version 1.0
 */

class SandboxModels {

	/** What a declared class name must look like, checked before eval. */
	const CLASS_PATTERN = '~^Sbx_[a-z][a-z0-9]*(_[a-z0-9]+)*_[A-Z][A-Za-z0-9]{1,19}$~D';

	/** The runtime key of plg_metadata that holds the checked declarations. */
	const METADATA_KEY = '_sandbox_models';

	/** The message of a call refused because the files changed since they were read. */
	const FILES_CHANGED = 'its model files changed since the site last read them; sync the plugin (Plugins page, Sync with Filesystem) to read them again';

	/** class name (lower case) => array(plugin, declaration), for the active plugins. */
	private static $active_map = null;

	/** class name (lower case) => true once its statics are filled for this state. */
	private static $declared = array();

	/** plugin => array(models, files) read in this process and not yet stored. */
	private static $pending = array();

	/** plugin => stored entry, read once per state. */
	private static $stored = array();

	/**
	 * Autoloader hook: declare $class when it is a model of an active
	 * sandboxed plugin. Returns quietly otherwise.
	 */
	public static function autoload(string $class): void {
		if (stripos($class, 'Sbx_') === false) {
			return;
		}
		$model_class = strncasecmp($class, 'MultiSbx_', 9) === 0 ? substr($class, 5) : $class;
		if (strncasecmp($model_class, 'Sbx_', 4) !== 0) {
			return;
		}
		$entry = self::activeMap()[strtolower($model_class)] ?? null;
		if ($entry === null) {
			return;
		}
		self::declare($entry[0], $entry[1]);
	}

	// ------------------------------------------------------------------
	// The declarations: read, stored, and which copy is used
	// ------------------------------------------------------------------

	/**
	 * Read and check the plugin's model files now, for this process: what
	 * classesFor() and the consent checks use until stored. Stores nothing.
	 *
	 * @return array<string,array> class => declaration
	 * @throws SandboxModelException listing every problem
	 */
	public static function refresh(string $plugin): array {
		$manifest = SandboxPlugins::manifest($plugin);
		$read = SandboxModelDeclarations::read($plugin, $manifest->title());
		self::$pending[$plugin] = $read;
		self::forgetClasses();
		return $read['models'];
	}

	/** What refresh() read in this process and has not stored, or null. */
	public static function pending(string $plugin): ?array {
		return self::$pending[$plugin] ?? null;
	}

	/**
	 * Keep what refresh() read on the plugin's row. Nothing to do before the
	 * row exists (a first install writes it with its metadata) or when
	 * nothing was read in this process.
	 */
	public static function storePending(string $plugin): void {
		$read = self::$pending[$plugin] ?? null;
		if ($read === null) {
			return;
		}
		$row = Plugin::get_by_plugin_name($plugin);
		if (!$row) {
			return;
		}
		$metadata = json_decode((string)$row->get('plg_metadata'), true);
		if (!is_array($metadata)) {
			$metadata = array();
		}
		if (($metadata[self::METADATA_KEY] ?? null) !== $read) {
			$metadata[self::METADATA_KEY] = $read;
			// Never a row's metadata replaced by nothing: what cannot be
			// written as JSON is not stored.
			$json = json_encode($metadata);
			if ($json === false) {
				throw new SandboxModelException(array("The plugin's model declarations cannot be stored (" . json_last_error_msg() . ').'));
			}
			Plugin::updateColumns((int)$row->key, array('plg_metadata' => $json));
		}
		unset(self::$pending[$plugin]);
		self::$stored[$plugin] = $read;
	}

	/** The entry kept on the plugin's row: array(models, files), or null. */
	public static function stored(string $plugin): ?array {
		if (!array_key_exists($plugin, self::$stored)) {
			$entry = null;
			try {
				$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT plg_metadata FROM plg_plugins WHERE plg_name = ?');
				$q->execute(array($plugin));
				$metadata = json_decode((string)$q->fetchColumn(), true);
				$entry = is_array($metadata[self::METADATA_KEY] ?? null) ? $metadata[self::METADATA_KEY] : null;
			} catch (Throwable $e) {
				$entry = null;
			}
			self::$stored[$plugin] = $entry;
		}
		return self::$stored[$plugin];
	}

	/**
	 * The declarations the site uses for a plugin: what this process read and
	 * has not stored yet, else the stored copy. Never the files themselves.
	 *
	 * @return array<string,array> class => declaration
	 */
	public static function declarations(string $plugin): array {
		$entry = self::$pending[$plugin] ?? self::stored($plugin);
		return is_array($entry['models'] ?? null) ? $entry['models'] : array();
	}

	/**
	 * Whether the plugin's model files differ from what the site read: a
	 * fenced call would run new files against the old tables. Size and time
	 * first; a file is hashed only when one of them differs.
	 */
	public static function filesChanged(string $plugin): bool {
		$entry = self::stored($plugin);
		$known = is_array($entry['files'] ?? null) ? $entry['files'] : array();
		$dir = SandboxPlugins::dirFor($plugin) . '/' . SandboxPlugins::SOURCE_DIR . '/' . SandboxModelDeclarations::DATA_DIR;
		$now = array();
		foreach (is_dir($dir) ? (scandir($dir) ?: array()) : array() as $file) {
			if ($file !== '.' && $file !== '..' && (substr($file, -4) === '.php' || is_dir($dir . '/' . $file))) {
				$now[] = $file;
			}
		}
		sort($now);
		$names = array_keys($known);
		sort($names);
		if ($now !== $names) {
			return true;
		}
		foreach ($known as $file => $stat) {
			$path = $dir . '/' . $file;
			if (is_link($path) || !is_file($path)) {
				return true;
			}
			clearstatcache(true, $path);
			if ((int)filesize($path) === (int)($stat['size'] ?? -1) && (int)filemtime($path) === (int)($stat['mtime'] ?? -1)) {
				continue;
			}
			if (!hash_equals((string)($stat['sha256'] ?? ''), (string)hash_file('sha256', $path))) {
				return true;
			}
		}
		return false;
	}

	// ------------------------------------------------------------------
	// The classes
	// ------------------------------------------------------------------

	/**
	 * Declare and return the model classes of one sandboxed plugin, active or
	 * not, from its declarations (never its files).
	 *
	 * @return string[]
	 */
	public static function classesFor(string $plugin): array {
		if (!preg_match(SandboxManifest::NAME_PATTERN, $plugin)) {
			return array();
		}
		$classes = array();
		foreach (self::declarations($plugin) as $declaration) {
			$class = self::declare($plugin, $declaration);
			if ($class !== null) {
				$classes[] = $class;
			}
		}
		return $classes;
	}

	/**
	 * The model classes of the installed sandboxed plugins among $plugins
	 * (names), for model discovery. A plugin is sandboxed here by its stored
	 * declarations, so a folder gone or gone bad still declares its tables.
	 *
	 * @return string[]
	 */
	public static function classesForPlugins(array $plugins): array {
		$classes = array();
		$installed = SandboxPlugins::installed();
		foreach ($plugins as $plugin) {
			if (isset($installed[(string)$plugin])) {
				$classes = array_merge($classes, self::classesFor((string)$plugin));
			}
		}
		return $classes;
	}

	/**
	 * The installed sandboxed plugins whose declarations are stored, by name:
	 * model discovery's list, read from the rows, never the folders.
	 *
	 * @return string[]
	 */
	public static function installedWithModels(): array {
		$names = array();
		try {
			$q = DbConnector::get_instance()->get_db_link()->query(
				"SELECT plg_name FROM plg_plugins WHERE plg_status IS DISTINCT FROM 'uninstalled' AND plg_metadata LIKE '%" . self::METADATA_KEY . "%' ORDER BY plg_name");
			foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $name) {
				if (preg_match(SandboxManifest::NAME_PATTERN, (string)$name) && self::declarations((string)$name)) {
					$names[] = (string)$name;
				}
			}
		} catch (Throwable $e) {
			return array();
		}
		return $names;
	}

	/** The declaration of the model a site class names, or null. */
	public static function declarationOf(string $class): ?array {
		if (!class_exists($class) || !is_subclass_of($class, 'SandboxRecord')) {
			return null;
		}
		return self::declarations($class::sandboxPlugin())[$class::sandboxModel()] ?? null;
	}

	/**
	 * Declare the two classes for one model, once per process, and fill their
	 * statics from the declaration within what was approved (again after
	 * reset()). Returns the model class, or null when the name is taken by a
	 * class that is not this model's (the install refuses that; on a site
	 * where it happens anyway, the model is left out rather than shadowing).
	 */
	private static function declare(string $plugin, array $declaration): ?string {
		$name = (string)($declaration['class'] ?? '');
		$class = SandboxManifest::classFor($plugin, $name);
		$lower = strtolower($class);
		if (!preg_match(self::CLASS_PATTERN, $class) || !preg_match(SandboxManifest::NAME_PATTERN, $plugin)
				|| !preg_match(SandboxManifest::MODEL_PATTERN, $name)) {
			error_log('[SandboxModels] refused to declare ' . substr($class, 0, 80) . ': not a plain name');
			return null;
		}
		if (!class_exists($class, false)) {
			if (class_exists('Multi' . $class, false)) {
				error_log('[SandboxModels] ' . $plugin . '/' . $name . ': the class Multi' . $class . ' already exists; the model is left out');
				return null;
			}
			// The fixed template. $class has just been held to CLASS_PATTERN.
			eval('final class ' . $class . ' extends SandboxRecord {'
				. ' public static $prefix; public static $tablename; public static $pkey_column;'
				. ' public static $field_specifications = array();'
				. ' public static $access_class; public static $read_permission; public static $write_permission;'
				. ' public static $delete_permission; public static $permanent_delete_permission; public static $owner_column;'
				. ' public static $api_readable = false; public static $api_writable = false;'
				. ' public static $api_unreadable_fields = array();'
				. ' protected static $foreign_key_actions = array();'
				. ' public static $sandbox_plugin; public static $sandbox_model;'
				. ' }'
				. ' final class Multi' . $class . ' extends SandboxRecordCollection {'
				. ' protected static $model_class = \'' . $class . '\';'
				. ' }');
		} elseif (!is_subclass_of($class, 'SandboxRecord') || $class::$sandbox_plugin !== null && $class::$sandbox_plugin !== $plugin) {
			error_log('[SandboxModels] ' . $plugin . '/' . $name . ': the class ' . $class . ' already exists; the model is left out');
			return null;
		}
		if (!isset(self::$declared[$lower])) {
			// Marked first: filling reads the approval, and nothing it reads
			// may come back here to fill the class again.
			self::$declared[$lower] = true;
			$class::sandboxFill($plugin, $declaration, SandboxConsent::effectiveModels($plugin));
		}
		return $class;
	}

	/** @return array<string,array{0:string,1:array}> */
	private static function activeMap(): array {
		if (self::$active_map !== null) {
			return self::$active_map;
		}
		$map = array();
		try {
			$q = DbConnector::get_instance()->get_db_link()->query(
				"SELECT plg_name FROM plg_plugins WHERE plg_active = 1 AND plg_metadata LIKE '%" . self::METADATA_KEY . "%'");
			foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $plugin) {
				$plugin = (string)$plugin;
				if (!preg_match(SandboxManifest::NAME_PATTERN, $plugin)) {
					continue;
				}
				foreach (self::declarations($plugin) as $declaration) {
					$map[strtolower(SandboxManifest::classFor($plugin, (string)$declaration['class']))] = array($plugin, $declaration);
				}
			}
		} catch (Throwable $e) {
			// No database (a CLI tool before it is configured): nothing is active.
			$map = array();
		}
		self::$active_map = $map;
		return $map;
	}

	/** Forget the active set, the stored copies and the filled statics; after an install, approval or removal. */
	public static function reset(): void {
		// What refresh() read and has not stored is kept: it is the newest
		// read of the files, which an install writes with the plugin's row.
		self::$active_map = null;
		self::$stored = array();
		self::forgetClasses();
	}

	/** Refill every declared class's statics when next asked for. */
	private static function forgetClasses(): void {
		self::$declared = array();
		self::$active_map = null;
		if (class_exists('DeletionRule', false)) {
			DeletionRule::resetModelRegistry();
		}
	}

	/**
	 * Drop a plugin's model tables and their id sequences, for uninstall: the
	 * rows are the plugin's own and nothing outside it holds a database
	 * constraint on them (a model file cannot declare one). The tables are
	 * the ones its declarations name now and the ones recorded when they were
	 * made (SandboxPluginManager::recordTables()), each held to the plugin's
	 * own sbx_{plugin}__ pattern.
	 *
	 * @return string[] the tables dropped
	 */
	public static function dropTables(string $plugin, array $recorded = array()): array {
		$tables = array();
		foreach (self::classesFor($plugin) as $class) {
			$tables[$class::$tablename] = $class::$pkey_column;
		}
		foreach ($recorded as $table => $pkey) {
			$tables[(string)$table] = (string)$pkey;
		}
		$dropped = array();
		$dblink = DbConnector::get_instance()->get_db_link();
		$owned = '~^sbx_' . preg_quote($plugin, '~') . '__[a-z0-9_]+$~D';
		foreach ($tables as $table => $pkey) {
			if (!preg_match($owned, $table) || !preg_match('~^[a-z0-9_]+$~D', $pkey)) {
				continue;
			}
			$dblink->exec('DROP TABLE IF EXISTS ' . $table . ' CASCADE');
			// The id sequence is made without OWNED BY, so CASCADE leaves it.
			$dblink->exec('DROP SEQUENCE IF EXISTS ' . $table . '_' . $pkey . '_seq');
			$dropped[] = $table;
		}
		return $dropped;
	}
}
